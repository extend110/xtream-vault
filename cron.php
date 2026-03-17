#!/usr/bin/env php
<?php
/**
 * cron.php – Xtream Vault Queue Worker
 * ─────────────────────────────────────
 * Läuft als Cronjob (oder manuell via CLI) und lädt alle
 * Einträge mit status=pending aus der queue.json herunter.
 *
 * Cronjob-Beispiel (alle 30 Min):
 *   * /2 * * * * /usr/bin/php /var/www/html/xtream/cron.php >> /dev/null 2>&1
 *
 * Linux (mit flock, empfohlen – verhindert parallele Runs):
 *   * /2 * * * * flock -n /tmp/xtream_cron.lock /usr/bin/php /var/www/html/xtream/cron.php
 */

// ─── Config aus config.json laden ────────────────────────────────────────────
require_once __DIR__ . '/config.php';

if (!is_configured()) {
    clog("ERROR: Xtream Vault ist noch nicht konfiguriert. Bitte zuerst die Settings im Frontend ausfüllen.");
    exit(1);
}

// ── Ziel prüfen ───────────────────────────────────────────────────────────────
if (RCLONE_ENABLED) {
    // rclone-Modus: Binary prüfen
    $rcloneBin = RCLONE_BIN;
    exec(escapeshellcmd($rcloneBin) . ' version 2>&1', $out, $ret);
    if ($ret !== 0) {
        clog("ERROR: rclone nicht gefunden oder nicht ausführbar: $rcloneBin");
        clog("  Installieren mit: curl https://rclone.org/install.sh | sudo bash");
        exit(1);
    }
    if (empty(RCLONE_REMOTE)) {
        clog("ERROR: rclone Remote nicht konfiguriert. Bitte in den Einstellungen setzen.");
        exit(1);
    }
    // Remote-Verbindung testen
    exec(escapeshellcmd($rcloneBin) . ' lsd ' . escapeshellarg(RCLONE_REMOTE . ':') . ' 2>&1', $out2, $ret2);
    if ($ret2 !== 0) {
        clog("ERROR: rclone Remote '" . RCLONE_REMOTE . "' nicht erreichbar: " . implode(' ', $out2));
        exit(1);
    }
    clog("INFO: rclone Modus aktiv → Remote: " . RCLONE_REMOTE . ':' . RCLONE_PATH);
} else {
    // Lokaler Modus: DEST_PATH prüfen
    if (!is_dir(DEST_PATH)) {
        if (!mkdir(DEST_PATH, 0755, true)) {
            clog("ERROR: Download-Verzeichnis existiert nicht und konnte nicht erstellt werden: " . DEST_PATH);
            clog("  Bitte manuell anlegen: sudo mkdir -p " . DEST_PATH . " && sudo chown www-data:www-data " . DEST_PATH);
            exit(1);
        }
        clog("INFO: Download-Verzeichnis erstellt: " . DEST_PATH);
    }
    if (!is_writable(DEST_PATH)) {
        clog("ERROR: Download-Verzeichnis ist nicht schreibbar: " . DEST_PATH);
        clog("  Bitte Rechte setzen: sudo chown -R www-data:www-data " . DEST_PATH);
        exit(1);
    }
}

// ─── Tuning ────────────────────────────────────────────────────────────────────
define('CHUNK_SIZE',      1024 * 256);  // 256 KB read-chunks
define('CONNECT_TIMEOUT', 30);          // seconds to wait for connection
define('MAX_ERRORS',      3);           // retry-Limit pro Item bevor status=error

// ─── Lock-Datei (verhindert parallele Runs ohne flock) ─────────────────────────
$lockFile = sys_get_temp_dir() . '/xtream_cron.lock';
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    clog("Another cron instance is already running. Exiting.");
    exit(0);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function clog(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    @mkdir(DEST_PATH, 0777, true);
    file_put_contents(CRON_LOG, $line . PHP_EOL, FILE_APPEND);
    // Logfile auf max. 5000 Zeilen kürzen
    $lines = file(CRON_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($lines) > 5000) {
        file_put_contents(CRON_LOG, implode(PHP_EOL, array_slice($lines, -4000)) . PHP_EOL);
    }
}

function load_queue(): array {
    if (!file_exists(QUEUE_FILE)) return [];
    return json_decode(file_get_contents(QUEUE_FILE), true) ?? [];
}

function save_queue(array $q): void {
    @mkdir(DEST_PATH, 0777, true);
    file_put_contents(QUEUE_FILE, json_encode($q, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function load_db(): array {
    if (!file_exists(DOWNLOAD_DB)) return ['movies' => [], 'episodes' => []];
    return json_decode(file_get_contents(DOWNLOAD_DB), true) ?? ['movies' => [], 'episodes' => []];
}

function save_db(array $db): void {
    @mkdir(DEST_PATH, 0777, true);
    file_put_contents(DOWNLOAD_DB, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function safe_filename(string $name): string {
    // Dateisystem-Sonderzeichen entfernen
    $name = preg_replace('/[<>:"|?*\/\\\\]/', '', $name);
    // Steuerzeichen entfernen
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
    // Unicode-Symbole, Box-Drawing, Pfeile, Sonderzeichen entfernen
    // aber Buchstaben (inkl. Umlaute, Akzente), Zahlen, Leerzeichen und - _ . behalten
    $name = preg_replace('/[^\p{L}\p{N}\s\-_\.]/u', '', $name);
    return trim(preg_replace('/\s+/', ' ', $name)) ?: 'file';
}

/** Schreibt den aktuellen Download-Fortschritt in progress.json */
function write_progress(array $data): void {
    @mkdir(DATA_PATH, 0755, true);
    file_put_contents(PROGRESS_FILE, json_encode($data, JSON_UNESCAPED_UNICODE));
}

/** Löscht progress.json wenn kein Download mehr läuft */
function clear_progress(): void {
    if (file_exists(PROGRESS_FILE)) unlink(PROGRESS_FILE);
}

/**
 * Datei herunterladen mit Live-Fortschritt in progress.json.
 * Gibt true bei Erfolg zurück, false bei Fehler.
 */
function download_file(string $url, string $destPath, string $title, int $queuePos, int $queueTotal): bool {
    $dir = dirname($destPath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            clog("  ERROR: Verzeichnis konnte nicht erstellt werden: $dir");
            clog("  Bitte prüfen: sudo mkdir -p " . escapeshellarg($dir) . " && sudo chown www-data:www-data " . escapeshellarg(dirname($dir)));
            return false;
        }
        clog("  Verzeichnis erstellt: $dir");
    }

    // Partial-Download-Unterstützung
    $resumeFrom = 0;
    $tmpPath    = $destPath . '.part';
    if (file_exists($tmpPath)) {
        $resumeFrom = filesize($tmpPath);
        clog("  Resuming from " . number_format($resumeFrom / 1024 / 1024, 1) . " MB");
    }

    $headers = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'];
    if ($resumeFrom > 0) $headers[] = "Range: bytes={$resumeFrom}-";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => false,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_CONNECTTIMEOUT  => CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT         => 0,
        CURLOPT_HTTPHEADER      => $headers,
        CURLOPT_NOPROGRESS      => false,
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME  => 60,
        CURLOPT_BUFFERSIZE      => CHUNK_SIZE,
    ]);

    $fh = fopen($tmpPath, $resumeFrom > 0 ? 'ab' : 'wb');
    if (!$fh) { clog("  ERROR: Cannot open $tmpPath for writing"); return false; }
    curl_setopt($ch, CURLOPT_FILE, $fh);

    $startTime = time();
    $lastWrite = 0;
    $startSize = $resumeFrom;
    $lastBytes = $resumeFrom;
    $lastSpeedTime = time();

    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($ch, $dlTotal, $dlNow)
        use (&$lastWrite, &$lastBytes, &$lastSpeedTime, $startSize, $startTime, $title, $queuePos, $queueTotal, $tmpPath)
    {
        $now     = time();
        $total   = $startSize + $dlTotal;
        $current = $startSize + $dlNow;
        $pct     = ($total > 0) ? min(100, round($current / $total * 100, 1)) : 0;

        // Geschwindigkeit (Bytes/s) über gleitendes 2s-Fenster
        $elapsed = max(1, $now - $lastSpeedTime);
        $speed   = 0;
        if ($elapsed >= 2) {
            $speed         = ($current - $lastBytes) / $elapsed;
            $lastBytes     = $current;
            $lastSpeedTime = $now;
        }

        // ETA in Sekunden
        $remaining = ($total > $current && $speed > 0) ? round(($total - $current) / $speed) : null;

        // progress.json max. 1× pro Sekunde schreiben
        if ($now - $lastWrite >= 1) {
            write_progress([
                'active'        => true,
                'title'         => $title,
                'queue_pos'     => $queuePos,
                'queue_total'   => $queueTotal,
                'bytes_done'    => $current,
                'bytes_total'   => $total,
                'percent'       => $pct,
                'speed_bps'     => round($speed),
                'eta_seconds'   => $remaining,
                'started_at'    => date('Y-m-d H:i:s', $startTime),
                'updated_at'    => date('Y-m-d H:i:s', $now),
            ]);
            $lastWrite = $now;
        }

        // Log alle 30s
        if ($dlTotal > 0 && $now % 30 === 0 && $now !== $lastWrite) {
            clog(sprintf("  Progress: %.1f / %.1f MB (%d%%) @ %s/s",
                $current / 1048576, $total / 1048576, $pct,
                format_bytes(round($speed))
            ));
        }
    });

    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($result === false || !empty($error)) {
        clog("  cURL Error: $error (HTTP $httpCode)");
        return false;
    }
    if ($httpCode >= 400 && $httpCode !== 206) {
        clog("  HTTP Error: $httpCode");
        return false;
    }
    if (file_exists($tmpPath) && filesize($tmpPath) > 0) {
        rename($tmpPath, $destPath);
        return true;
    }
    clog("  ERROR: Downloaded file is empty");
    return false;
}

function format_bytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1)    . ' KB';
    return $bytes . ' B';
}

/**
 * Datei via rclone rcat direkt in Cloud-Speicher streamen.
 * Kein lokaler Zwischenspeicher — Stream geht direkt URL → rclone → Cloud.
 * Gibt true bei Erfolg zurück, false bei Fehler.
 */
function rclone_stream(string $url, string $remotePath, string $title, int $queuePos, int $queueTotal): bool {
    $rclone  = escapeshellarg(RCLONE_BIN);
    $dest    = escapeshellarg(RCLONE_REMOTE . ':' . $remotePath);

    // curl streamt in rclone rcat, das direkt in die Cloud schreibt
    // --no-buffer: sofort flushen | --silent: keine curl-Fortschrittsbalken
    $cmd = "curl --silent --location --max-redirs 5 " .
           "--user-agent 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' " .
           escapeshellarg($url) .
           " | " . $rclone . " rcat " . $dest . " 2>&1";

    clog("  rclone stream: $dest");

    // Initiales Progress-Objekt (kein Byte-Fortschritt bei rcat-Streaming)
    write_progress([
        'active'      => true,
        'title'       => $title,
        'queue_pos'   => $queuePos,
        'queue_total' => $queueTotal,
        'bytes_done'  => 0,
        'bytes_total' => 0,
        'percent'     => 0,
        'speed_bps'   => 0,
        'eta_seconds' => null,
        'mode'        => 'rclone',
        'started_at'  => date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);

    // proc_open für Echtzeit-Logging
    $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) { clog("  ERROR: proc_open fehlgeschlagen"); return false; }

    fclose($pipes[0]);
    $startTime = time();
    $lastLog   = time();

    // Ausgabe lesen (rclone gibt nur bei Fehler etwas aus)
    $output = '';
    while (!feof($pipes[1])) {
        $line = fgets($pipes[1], 4096);
        if ($line !== false) $output .= $line;
        $now = time();
        // Alle 30s Lebenszeichen loggen
        if ($now - $lastLog >= 30) {
            clog(sprintf("  Streaming läuft… (%ds)", $now - $startTime));
            write_progress([
                'active'      => true,
                'title'       => $title,
                'queue_pos'   => $queuePos,
                'queue_total' => $queueTotal,
                'bytes_done'  => 0,
                'bytes_total' => 0,
                'percent'     => 0,
                'speed_bps'   => 0,
                'eta_seconds' => null,
                'mode'        => 'rclone',
                'started_at'  => date('Y-m-d H:i:s', $startTime),
                'updated_at'  => date('Y-m-d H:i:s', $now),
            ]);
            $lastLog = $now;
        }
    }
    $errOutput = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    if ($exitCode !== 0) {
        clog("  rclone ERROR (exit $exitCode): " . trim($errOutput ?: $output));
        return false;
    }
    return true;
}

// ─── Main ─────────────────────────────────────────────────────────────────────
clog("=== Xtream Vault Cron Worker started ===");

$queue   = load_queue();
$db      = load_db();
$pending = array_filter($queue, fn($item) => $item['status'] === 'pending');

if (empty($pending)) {
    clog("Queue is empty – nothing to do.");
    clear_progress();
    flock($lock, LOCK_UN);
    exit(0);
}

$totalPending = count($pending);
clog(sprintf("Found %d pending item(s)", $totalPending));

$processed = 0;
$errors    = 0;
$position  = 0;

foreach ($queue as &$item) {
    if ($item['status'] !== 'pending') continue;
    $position++;

    $title      = $item['title'];
    $sid        = $item['stream_id'];
    $ext        = $item['container_extension'] ?? 'mp4';
    $type       = $item['type'] ?? 'movie';
    $url        = $item['stream_url'];
    $sub        = $item['dest_subfolder'] ?? ($type === 'movie' ? 'Movies' : 'TV Shows');
    $cat        = !empty($item['category']) ? $item['category'] : 'Uncategorized';

    $fileTitle = clean_title($title);
    $safeTitle = safe_filename($fileTitle) ?: safe_filename($title) ?: 'film_' . $sid;

    $safeTitle = str_starts_with($safeTitle, "DE") ? substr($safeTitle, 2) : $safeTitle; // Optional: "DE" Präfix entfernen
    $safeTitle = trim($safeTitle, " -_"); // Optional: führende/folg. Bindestriche und Unterstriche entfernen

    $safeCat   = safe_filename($cat) ?: 'Uncategorized';

    if (RCLONE_ENABLED) {
        // rclone-Modus: Pfad im Cloud-Speicher
        $remoteBase = rtrim(RCLONE_PATH, '/');
        $remotePath = ($remoteBase ? $remoteBase . '/' : '') . $sub . '/' . $safeCat . '/' . $safeTitle . '.' . $ext;
        $destDisplay = RCLONE_REMOTE . ':' . $remotePath;
        $destFile    = null; // kein lokaler Pfad
    } else {
        // Lokaler Modus
        $destDir     = DEST_PATH . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR . $safeCat;
        $destFile    = $destDir . DIRECTORY_SEPARATOR . $safeTitle . '.' . $ext;
        $destDisplay = $destFile;
        $remotePath  = null;
    }

    $dbKey = $type === 'movie' ? 'movies' : 'episodes';
    if (in_array((string)$sid, $db[$dbKey])) {
        clog("SKIP (already downloaded): $title");
        $item['status'] = 'done';
        save_queue($queue);
        continue;
    }
    if ($destFile && file_exists($destFile)) {
        clog("SKIP (file exists): $destFile");
        $item['status'] = 'done';
        if (!in_array((string)$sid, $db[$dbKey])) { $db[$dbKey][] = (string)$sid; save_db($db); }
        save_queue($queue);
        continue;
    }

    clog("START: $title  →  $destDisplay");
    $item['status'] = 'downloading';
    save_queue($queue);

    write_progress([
        'active'      => true,
        'title'       => $title,
        'queue_pos'   => $position,
        'queue_total' => $totalPending,
        'bytes_done'  => 0,
        'bytes_total' => 0,
        'percent'     => 0,
        'speed_bps'   => 0,
        'eta_seconds' => null,
        'mode'        => RCLONE_ENABLED ? 'rclone' : 'local',
        'started_at'  => date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);

    $ok = RCLONE_ENABLED
        ? rclone_stream($url, $remotePath, $title, $position, $totalPending)
        : download_file($url, $destFile, $title, $position, $totalPending);

    if ($ok) {
        $item['status'] = 'done';
        if (!in_array((string)$sid, $db[$dbKey])) { $db[$dbKey][] = (string)$sid; save_db($db); }

        // Metadaten sofort in downloaded_index.json schreiben
        // (ersetzt den nie fertig werdenden series_cache.json Aufbau)
        $existingIndex = [];
        if (file_exists(DOWNLOADED_INDEX_FILE)) {
            $raw = @file_get_contents(DOWNLOADED_INDEX_FILE);
            if ($raw !== false) $existingIndex = json_decode($raw, true) ?? [];
        }
        $existingIndex[(string)$sid] = [
            'id'       => (string)$sid,
            'title'    => $title,   // Originaltitel (mit Länderkürzel, vor clean_title)
            'cover'    => $item['cover'] ?? '',
            'category' => $item['category'] ?? '',
            'ext'      => $ext,
            'type'     => $type === 'movie' ? 'movie' : 'episode',
        ];
        @file_put_contents(DOWNLOADED_INDEX_FILE, json_encode($existingIndex, JSON_UNESCAPED_UNICODE));
        unset($existingIndex);

        clog("DONE:  $title");
        $processed++;
    } else {
        $item['status'] = 'error';
        $item['error']  = 'Download failed at ' . date('Y-m-d H:i:s');
        clog("ERROR: $title");
        $errors++;
    }
    save_queue($queue);
}

clear_progress();
clog(sprintf("=== Done: %d downloaded, %d errors ===", $processed, $errors));

// Cache neu aufbauen wenn neue Dateien heruntergeladen wurden
if ($processed > 0) {
    clog("Starte Library-Cache Rebuild im Hintergrund…");
    $script = escapeshellarg(__DIR__ . '/cache_builder.php');
    $log    = escapeshellarg(DATA_PATH . '/cache_build.log');
    shell_exec("php {$script} > {$log} 2>&1 &");
}

flock($lock, LOCK_UN);

// ── Migrations-Hilfsfunktion: Index aus done-Queue-Einträgen aufbauen ─────────
// Einmalig ausführen mit: php cron.php --migrate-index
if (in_array('--migrate-index', $argv ?? [])) {
    clog("=== Migriere Downloaded-Index aus Queue-Historie ===");
    $queue = load_queue();
    $done  = array_filter($queue, fn($q) => $q['status'] === 'done');
    $existingIndex = [];
    if (file_exists(DOWNLOADED_INDEX_FILE)) {
        $raw = @file_get_contents(DOWNLOADED_INDEX_FILE);
        if ($raw !== false) $existingIndex = json_decode($raw, true) ?? [];
    }
    $added = 0;
    foreach ($done as $item) {
        $sid = (string)$item['stream_id'];
        if (!isset($existingIndex[$sid])) {
            $existingIndex[$sid] = [
                'id'       => $sid,
                'title'    => $item['title'] ?? 'Unbekannt',
                'cover'    => $item['cover'] ?? '',
                'category' => $item['category'] ?? '',
                'ext'      => $item['container_extension'] ?? 'mp4',
                'type'     => ($item['type'] === 'episode') ? 'episode' : 'movie',
            ];
            $added++;
        }
    }
    @file_put_contents(DOWNLOADED_INDEX_FILE, json_encode($existingIndex, JSON_UNESCAPED_UNICODE));
    clog("Migration abgeschlossen: $added neue Einträge hinzugefügt.");
    exit(0);
}
