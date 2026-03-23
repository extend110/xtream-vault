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

// ─── Lock: flock auf data/ (kein tmpfs) ──────────────────────────────────────
// /tmp ist oft tmpfs wo flock(LOCK_NB) unzuverlässig ist.
// data/ liegt auf dem echten Dateisystem → flock garantiert atomar.
// Filehandle bleibt offen für gesamte Laufzeit → Lock automatisch freigegeben.
$GLOBALS['_cron_lock_fh'] = fopen(__DIR__ . '/data/cron.lock', 'c');
if (!$GLOBALS['_cron_lock_fh'] || !flock($GLOBALS['_cron_lock_fh'], LOCK_EX | LOCK_NB)) {
    if ($GLOBALS['_cron_lock_fh']) fclose($GLOBALS['_cron_lock_fh']);
    $logLine = '[' . date('Y-m-d H:i:s') . '] Another instance of cron.php is already running. Exiting.' . PHP_EOL;
    @file_put_contents(__DIR__ . '/data/cron.log', $logLine, FILE_APPEND);
    exit(0);
}
ftruncate($GLOBALS['_cron_lock_fh'], 0);
fwrite($GLOBALS['_cron_lock_fh'], (string)getmypid());
fflush($GLOBALS['_cron_lock_fh']);
@unlink(__DIR__ . '/data/cron_starting.lock');

// ─── Tuning ───────────────────────────────────────────────────────────────────
define('CHUNK_SIZE',      1024 * 256);
define('CONNECT_TIMEOUT', 30);
define('MAX_ERRORS',      3);

if (!is_configured()) {
    clog("ERROR: Xtream Vault ist noch nicht konfiguriert.");
    exit(1);
}

// ── Ziel prüfen ───────────────────────────────────────────────────────────────
if (RCLONE_ENABLED) {
    $rcloneBin = RCLONE_BIN;
    exec(escapeshellcmd($rcloneBin) . ' version 2>&1', $out, $ret);
    if ($ret !== 0) {
        clog("ERROR: rclone nicht gefunden oder nicht ausführbar: $rcloneBin");
        exit(1);
    }
    if (empty(RCLONE_REMOTE)) {
        clog("ERROR: rclone Remote nicht konfiguriert.");
        exit(1);
    }
    exec(escapeshellcmd($rcloneBin) . ' lsd ' . escapeshellarg(RCLONE_REMOTE . ':') . ' 2>&1', $out2, $ret2);
    if ($ret2 !== 0) {
        clog("ERROR: rclone Remote '" . RCLONE_REMOTE . "' nicht erreichbar: " . implode(' ', $out2));
        exit(1);
    }
    clog("INFO: rclone Modus aktiv → Remote: " . RCLONE_REMOTE . ':' . RCLONE_PATH);
} else {
    if (!is_dir(DEST_PATH)) {
        if (!mkdir(DEST_PATH, 0755, true)) {
            clog("ERROR: Download-Verzeichnis konnte nicht erstellt werden: " . DEST_PATH);
            exit(1);
        }
    }
    if (!is_writable(DEST_PATH)) {
        clog("ERROR: Download-Verzeichnis nicht schreibbar: " . DEST_PATH);
        exit(1);
    }
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

function list_rclone_files(string $remote, string $path): array {
    $cmd = escapeshellcmd(RCLONE_BIN) . ' lsf -R ' . escapeshellarg($remote . ':' . $path) . ' 2>&1';
    exec($cmd, $out, $ret);
    if ($ret !== 0) {
        clog("ERROR: rclone lsf fehlgeschlagen für {$remote}:{$path} — " . implode(' ', $out));
        return [];
    }
    $files = [];
    foreach ($out as $line) {
        $line = trim($line);
        if ($line === '' || substr($line, -1) === '/') continue;
        $files[] = basename($line);
    }
    $files = array_values(array_unique(array_filter(
        $files,
        fn($f) => preg_match('/\.(mp4|mkv|avi|mov|mp3|flac|srt|sub)$/i', $f)
    )));
    return $files;
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

        // Abbruch-Signal prüfen — gibt 1 zurück um cURL zu stoppen
        if (file_exists(CANCEL_FILE)) {
            return 1;
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

    // Abbruch prüfen
    if (file_exists(CANCEL_FILE)) {
        clog("  CANCEL: Abbruch-Signal empfangen — Download gestoppt");
        @unlink(CANCEL_FILE);
        @unlink($tmpPath); // Teil-Download entfernen
        return 'cancelled';
    }

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
    $rcloneBin  = RCLONE_BIN;
    $remoteDest = RCLONE_REMOTE . ':' . $remotePath;

    // rclone copyurl lädt direkt von URL in Cloud — kennt Dateigröße und gibt Stats aus
    // copyurl <url> <remote:path/file.ext> streamt direkt ans Ziel
    $cmd = escapeshellarg($rcloneBin)
        . ' copyurl ' . escapeshellarg($url)
        . ' ' . escapeshellarg($remoteDest)
        . ' --no-traverse'
        . ' --stats 2s'
        . ' --stats-one-line'
        . ' --use-mmap'
        . ' -v'
        . ' 2>&1';

    clog("  rclone copyurl → $remoteDest");

    $startTime = time();

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
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);

    $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) { clog("  ERROR: proc_open fehlgeschlagen"); return false; }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output    = '';
    $lastWrite = time();
    $lastLog   = $startTime;

    // Aktuell bekannte Werte (werden aus rclone-Stats geparst)
    $bytesDone  = 0;
    $bytesTotal = 0;
    $speedBps   = 0;
    $etaSecs    = null;
    $percent    = 0;

    while (true) {
        $status = proc_get_status($proc);

        // Ausgabe aus stdout+stderr lesen (durch 2>&1 alles auf stdout)
        $chunk = fread($pipes[1], 8192);
        if ($chunk !== false && $chunk !== '') {
            $output .= $chunk;

            // rclone actual output format (with --stats-one-line -v):
            // "2026/03/18 19:52:52 INFO  :     1.018 MiB / 2.260 GiB, 0%, 0 B/s, ETA -"
            // Strip ANSI escape codes first
            $clean = preg_replace('/\x1b\[[0-9;]*m/', '', $output);

            // Match: "1.018 MiB / 2.260 GiB, 0%, 0 B/s, ETA -"
            // Speed unit is e.g. "B/s", "KiB/s", "MiB/s", "GiB/s"
            if (preg_match('/([\d.]+)\s*([\w]+)\s*\/\s*([\d.]+)\s*([\w]+),\s*(\d+)%,\s*([\d.]+)\s*([\w]+\/s),\s*ETA\s*(\S+)/i', $clean, $m)) {
                $bytesDone  = parse_rclone_bytes((float)$m[1], $m[2]);
                $bytesTotal = parse_rclone_bytes((float)$m[3], $m[4]);
                $percent    = (int)$m[5];
                $speedBps   = parse_rclone_bytes((float)$m[6], str_replace('/s', '', $m[7]));
                $etaSecs    = parse_rclone_eta($m[8]);
            }

            // Nur letzten Teil behalten um Buffer nicht endlos wachsen zu lassen
            if (strlen($output) > 4096) $output = substr($output, -2048);
        }

        $now = time();
        if ($now - $lastWrite >= 2) {
            write_progress([
                'active'      => true,
                'title'       => $title,
                'queue_pos'   => $queuePos,
                'queue_total' => $queueTotal,
                'bytes_done'  => $bytesDone,
                'bytes_total' => $bytesTotal,
                'percent'     => $percent,
                'speed_bps'   => $speedBps,
                'eta_seconds' => $etaSecs,
                'mode'        => 'rclone',
                'started_at'  => date('Y-m-d H:i:s', $startTime),
                'updated_at'  => date('Y-m-d H:i:s', $now),
            ]);
            $lastWrite = $now;
            // Buffer nach dem Parsen leeren damit Regex auf frischen Daten arbeitet
            $output = substr($output, -2048);
        }

        if ($now - $lastLog >= 30) {
            clog(sprintf("  Streaming läuft… %s / %s (%d%%) @ %s/s (%ds)",
                format_bytes($bytesDone), format_bytes($bytesTotal),
                $percent, format_bytes($speedBps), $now - $startTime));
            $lastLog = $now;
        }

        // Abbruch-Signal prüfen
        if (file_exists(CANCEL_FILE)) {
            clog("  CANCEL: Abbruch-Signal empfangen — beende rclone…");
            proc_terminate($proc, 9);
            @unlink(CANCEL_FILE);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return 'cancelled';
        }

        if (!$status['running']) break;
        usleep(500000);
    }

    // Restlichen Output lesen
    $rest = stream_get_contents($pipes[1]);
    if ($rest) $output .= $rest;
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    if ($exitCode !== 0) {
        // Fehlermeldung aus Output extrahieren
        $errLine = '';
        foreach (array_reverse(explode("\n", $output)) as $line) {
            $line = trim($line);
            if ($line !== '') { $errLine = $line; break; }
        }
        clog("  rclone ERROR (exit $exitCode): $errLine");
        return false;
    }
    return true;
}

/**
 * Konvertiert rclone-Byte-Einheiten zu Bytes.
 * rclone gibt aus: B, KiB, MiB, GiB, TiB oder kB, MB, GB
 */
function parse_rclone_bytes(float $value, string $unit): int {
    $unit = strtolower(trim($unit));
    return (int) match(true) {
        str_starts_with($unit, 'ti') => $value * 1024 ** 4,
        str_starts_with($unit, 'gi') => $value * 1024 ** 3,
        str_starts_with($unit, 'mi') => $value * 1024 ** 2,
        str_starts_with($unit, 'ki') => $value * 1024,
        str_starts_with($unit, 'g')  => $value * 1000 ** 3,
        str_starts_with($unit, 'm')  => $value * 1000 ** 2,
        str_starts_with($unit, 'k')  => $value * 1000,
        default                      => $value,
    };
}

/**
 * Parst rclone ETA-Strings: "1m30s", "2h5m", "45s" → Sekunden
 */
function parse_rclone_eta(string $eta): ?int {
    if ($eta === '-' || $eta === '') return null;
    $secs = 0;
    if (preg_match('/(\d+)h/', $eta, $m)) $secs += (int)$m[1] * 3600;
    if (preg_match('/(\d+)m/', $eta, $m)) $secs += (int)$m[1] * 60;
    if (preg_match('/(\d+)s/', $eta, $m)) $secs += (int)$m[1];
    return $secs > 0 ? $secs : null;
}

// ─── Main ─────────────────────────────────────────────────────────────────────
clog("=== Xtream Vault Cron Worker started ===");

$queue = load_queue();
$db    = load_db();
$now   = time();

$pending = array_filter($queue, fn($item) => $item['status'] === 'pending');

if (empty($pending)) {
    clog("Queue is empty – nothing to do.");
    clear_progress();
    exit(0);
}

// Nach Priorität sortieren (1=hoch, 2=normal, 3=niedrig), dann nach Einreihungszeit
usort($queue, function($a, $b) {
    $pa = (int)($a['priority'] ?? 2);
    $pb = (int)($b['priority'] ?? 2);
    if ($pa !== $pb) return $pa - $pb;
    return strcmp($a['added_at'] ?? '', $b['added_at'] ?? '');
});
save_queue($queue);

$totalPending = count($pending);
clog(sprintf("Found %d pending item(s)", $totalPending));

// ── VPN: vor Downloads verbinden ─────────────────────────────────────────────
$vpnStartedByUs = false;
if (VPN_ENABLED && $totalPending > 0) {
    if (vpn_is_up()) {
        clog("VPN: " . VPN_INTERFACE . " bereits aktiv");
    } else {
        clog("VPN: verbinde " . VPN_INTERFACE . " …");
        $vpnResult = vpn_up();
        if ($vpnResult !== true) {
            clog("VPN ERROR: " . $vpnResult . " — Downloads werden trotzdem gestartet");
        } else {
            $vpnStartedByUs = true;
            clog("VPN: " . VPN_INTERFACE . " verbunden");
        }
    }
}

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
    $season     = isset($item['season']) && $item['season'] !== null ? (int)$item['season'] : null;

    // Zielpfad berechnen (gemeinsame Logik mit api.php via config.php)
    $destInfo  = build_dest_path($item);
    $relPath   = $destInfo['rel_path'];
    $safeTitle = $destInfo['safe_title'];

    if (RCLONE_ENABLED) {
        $remoteBase  = rtrim(RCLONE_PATH, '/');
        $remotePath  = ($remoteBase ? $remoteBase . '/' : '') . str_replace(DIRECTORY_SEPARATOR, '/', $relPath);
        $destDisplay = RCLONE_REMOTE . ':' . $remotePath;
        $destFile    = null;
    } else {
        $destFile    = DEST_PATH . DIRECTORY_SEPARATOR . $relPath;
        $destDisplay = $destFile;
        $remotePath  = null;
    }

    $dbKey = $type === 'movie' ? 'movies' : 'episodes';
    if (in_array((string)$sid, $db[$dbKey])) {
        clog("SKIP (already downloaded): $title");
        $item['status'] = 'done';
        save_queue_item_status($sid, $item);
        continue;
    }
    if ($destFile && file_exists($destFile)) {
        clog("SKIP (file exists): $destFile");
        $item['status'] = 'done';
        if (!in_array((string)$sid, $db[$dbKey])) { $db[$dbKey][] = (string)$sid; save_db($db); }
        save_queue_item_status($sid, $item);
        continue;
    }

    // rclone-Cache: prüfen ob Datei bereits auf dem Remote vorhanden ist
    if (RCLONE_ENABLED) {
        $rcloneCacheFile = DATA_DIR . '/rclone_cache.json';
        if (!isset($rcloneFileCache)) {
            if (file_exists($rcloneCacheFile)) {
                $rcloneFileCache = json_decode(file_get_contents($rcloneCacheFile), true) ?? [];
                clog("INFO: rclone-Cache geladen — " . count($rcloneFileCache) . " Dateien bekannt");
            } else {
                clog("INFO: rclone-Cache nicht gefunden, lade Dateiliste vom Remote…");
                $rcloneFileCache = list_rclone_files(RCLONE_REMOTE, RCLONE_PATH);
                file_put_contents($rcloneCacheFile, json_encode($rcloneFileCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                clog("INFO: rclone-Cache erstellt — " . count($rcloneFileCache) . " Dateien gefunden");
            }
        }
        $destFilename = basename($relPath);
        if (in_array($destFilename, $rcloneFileCache)) {
            clog("SKIP (already on remote): $destDisplay");
            $item['status'] = 'done';
            if (!in_array((string)$sid, $db[$dbKey])) { $db[$dbKey][] = (string)$sid; save_db($db); }
            save_queue_item_status($sid, $item);
            continue;
        }
    }

    clog("START: $title  →  $destDisplay");

    // Speicherplatz-Prüfung (nur im lokalen Modus)
    $fileSize = 0;
    if (!RCLONE_ENABLED) {
        $checkDir = is_dir(DEST_PATH) ? DEST_PATH : dirname(DEST_PATH);
        $freeDisk = @disk_free_space($checkDir);
        if ($freeDisk !== false) {
            // Dateigröße per HEAD-Request ermitteln
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'],
                CURLOPT_RETURNTRANSFER => true,
            ]);
            curl_exec($ch);
            $contentLength = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            curl_close($ch);
            if ($contentLength > 0) $fileSize = $contentLength;

            $minFree = 512 * 1024 * 1024;
            $needed  = $fileSize + $minFree;
            if ($fileSize > 0 && $freeDisk < $needed) {
                $msg = sprintf(
                    'Nicht genug Speicherplatz: %.1f GB frei, %.1f GB benötigt (Datei: %.1f GB + 512 MB Puffer)',
                    $freeDisk / 1073741824,
                    $needed   / 1073741824,
                    $fileSize / 1073741824
                );
                clog("SKIP (disk full): $title — $msg");
                $item['status'] = 'error';
                $item['error']  = $msg;
                save_queue_item_status($sid, $item);
                break;
            }
            if ($fileSize > 0) {
                clog(sprintf("  Speicher: %.1f GB frei, Datei: %.1f GB — OK",
                    $freeDisk / 1073741824, $fileSize / 1073741824));
            }
        }
    }

    $item['status'] = 'downloading';
    save_queue_item_status($sid, $item);

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

    if ($ok === 'cancelled') {
        clog("CANCELLED: $title — zurück auf pending");
        $item['status'] = 'pending';
        $item['error']  = null;
        save_queue_item_status($sid, $item);
        clear_progress();
        break; // Gesamten cron-Loop beenden — nächster Cron-Run macht weiter
    } elseif ($ok) {
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

        // Dateigröße ermitteln (für Statistiken)
        $downloadedBytes = 0;
        if (!RCLONE_ENABLED && $destFile && file_exists($destFile)) {
            $downloadedBytes = (int)@filesize($destFile);
        } else {
            // rclone: bytes_done aus progress.json lesen (direkt nach dem Transfer noch vorhanden)
            $prog = file_exists(PROGRESS_FILE) ? (json_decode(@file_get_contents(PROGRESS_FILE), true) ?? []) : [];
            $downloadedBytes = (int)($prog['bytes_done'] ?? 0);
        }

        // Download-Verlauf schreiben (unabhängig von Queue — überlebt queue_clear)
        $historyEntry = [
            'title'    => $title,
            'type'     => $type === 'movie' ? 'movie' : 'episode',
            'cover'    => $item['cover']    ?? '',
            'category' => $item['category'] ?? '',
            'added_by' => $item['added_by'] ?? '',
            'done_at'  => date('Y-m-d H:i:s'),
            'bytes'    => $downloadedBytes,
        ];
        $history = [];
        if (file_exists(DOWNLOAD_HISTORY_FILE)) {
            $raw = @file_get_contents(DOWNLOAD_HISTORY_FILE);
            if ($raw !== false) $history = json_decode($raw, true) ?? [];
        }
        array_unshift($history, $historyEntry);          // neueste zuerst
        if (count($history) > 200) $history = array_slice($history, 0, 200); // max 200
        @file_put_contents(DOWNLOAD_HISTORY_FILE, json_encode($history, JSON_UNESCAPED_UNICODE));
        unset($history);

        clog("DONE:  $title");
        $processed++;

        // Aus new_releases.json entfernen (damit es nicht mehr als "neu" erscheint)
        if (file_exists(DATA_DIR . '/new_releases.json')) {
            $nr = json_decode(@file_get_contents(DATA_DIR . '/new_releases.json'), true) ?? [];
            if ($type === 'movie') {
                $nr['movies'] = array_values(array_filter($nr['movies'] ?? [],
                    fn($m) => (string)($m['stream_id'] ?? $m['id'] ?? '') !== (string)$sid));
            } else {
                $nr['series'] = array_values(array_filter($nr['series'] ?? [],
                    fn($s) => (string)($s['series_id'] ?? $s['id'] ?? '') !== (string)$sid));
            }
            @file_put_contents(DATA_DIR . '/new_releases.json', json_encode($nr, JSON_UNESCAPED_UNICODE));
        }

        // Telegram-Benachrichtigung — live aus config lesen (Einstellungen können sich während des Runs ändern)
        $liveConfig  = load_config();
        $tgEnabled   = (bool)($liveConfig['telegram_enabled']   ?? false);
        $tgToken     = $liveConfig['telegram_bot_token'] ?? '';
        $tgChatId    = $liveConfig['telegram_chat_id']   ?? '';
        if ($tgEnabled && $tgToken !== '' && $tgChatId !== '') {
            // Dateigröße: tatsächlich übertragene Bytes
            $reportSize = $downloadedBytes;
            $typeLabel  = $type === 'movie' ? '🎬 Film' : '📺 Episode';
            $sizeStr    = $reportSize > 0 ? "\n📦 " . round($reportSize / 1048576) . ' MB' : '';
            $msg = "✅ <b>Download abgeschlossen</b>\n{$typeLabel}: <b>" . htmlspecialchars($title, ENT_XML1) . "</b>{$sizeStr}";
            // Temporär define-Werte mit Live-Werten überschreiben geht nicht —
            // send_telegram() direkt aufrufen mit lokalen Variablen
            $tgUrl  = 'https://api.telegram.org/bot' . $tgToken . '/sendMessage';
            $tgBody = json_encode(['chat_id' => $tgChatId, 'text' => $msg, 'parse_mode' => 'HTML']);
            $tgCtx  = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $tgBody, 'timeout' => 8]]);
            $tgRaw  = @file_get_contents($tgUrl, false, $tgCtx);
            if ($tgRaw === false) {
                clog("WARN: Telegram nicht erreichbar");
            } else {
                $tgResp = json_decode($tgRaw, true);
                if (!($tgResp['ok'] ?? false)) clog("WARN: Telegram-Fehler — " . ($tgResp['description'] ?? 'unbekannt'));
            }
        }
        // rclone-Cache aktualisieren: neu hochgeladene Datei eintragen
        if (RCLONE_ENABLED && isset($rcloneFileCache, $rcloneCacheFile)) {
            $uploadedFilename = basename($relPath);
            if (!in_array($uploadedFilename, $rcloneFileCache)) {
                $rcloneFileCache[] = $uploadedFilename;
                @file_put_contents($rcloneCacheFile, json_encode($rcloneFileCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
    } else {
        $item['status'] = 'error';
        $item['error']  = 'Download fehlgeschlagen (' . date('Y-m-d H:i:s') . ')';
        clog("ERROR: $title");
        $errors++;
    }
    save_queue_item_status($sid, $item);
}

/**
 * Aktualisiert nur den Status eines einzelnen Queue-Items in der Datei.
 * Liest die aktuelle Queue neu ein — so gehen parallel hinzugefügte Items nicht verloren.
 */
function save_queue_item_status(string $sid, array $updatedItem): void {
    $current = load_queue();
    $found   = false;
    foreach ($current as &$qi) {
        if ((string)$qi['stream_id'] === $sid) {
            $qi['status'] = $updatedItem['status'];
            $qi['error']  = $updatedItem['error'] ?? null;
            $found = true;
            break;
        }
    }
    unset($qi);
    // Falls Item nicht mehr vorhanden (z.B. manuell entfernt) — nicht neu hinzufügen
    if ($found) save_queue($current);
}

clear_progress();
clog(sprintf("=== Done: %d downloaded, %d errors ===", $processed, $errors));

// ── VPN: nach Downloads trennen (nur wenn wir ihn gestartet haben) ────────────
if (VPN_ENABLED && $vpnStartedByUs) {
    clog("VPN: trenne " . VPN_INTERFACE . " …");
    $vpnDown = vpn_down();
    if ($vpnDown !== true) clog("VPN WARN: " . $vpnDown);
    else clog("VPN: " . VPN_INTERFACE . " getrennt");
}

// Benachrichtigung: alle Downloads abgeschlossen
if ($processed > 0) {
    $totalBytes = 0;
    if (file_exists(DOWNLOAD_HISTORY_FILE)) {
        $hist = json_decode(@file_get_contents(DOWNLOAD_HISTORY_FILE), true) ?? [];
        // Bytes der letzten $processed Einträge summieren
        $totalBytes = array_sum(array_column(array_slice($hist, 0, $processed), 'bytes'));
    }
}

// Cache neu aufbauen wenn neue Dateien heruntergeladen wurden
if ($processed > 0) {
    clog("Starte Library-Cache Rebuild im Hintergrund…");
    $script = escapeshellarg(__DIR__ . '/cache_builder.php');
    $log    = escapeshellarg(DATA_PATH . '/cache_build.log');
    shell_exec("php {$script} > {$log} 2>&1 &");
}



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
