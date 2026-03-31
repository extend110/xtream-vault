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

// ─── Lock ────────────────────────────────────────────────────────────────────
// Koordinator: cron.lock — verhindert parallele Coordinator-Starts
// Worker:      cron_worker_{id}.lock — verhindert doppelte Worker pro Server

// Server-Filter aus CLI-Args lesen (wird auch für Lock-Entscheidung gebraucht)
$serverFilter = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--server=')) {
        $serverFilter = substr($arg, 9);
    }
}

$lockFile = $serverFilter !== null
    ? __DIR__ . '/data/cron_worker_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $serverFilter) . '.lock'
    : __DIR__ . '/data/cron.lock';

$GLOBALS['_cron_lock_fh'] = fopen($lockFile, 'c');
if (!$GLOBALS['_cron_lock_fh'] || !flock($GLOBALS['_cron_lock_fh'], LOCK_EX | LOCK_NB)) {
    if ($GLOBALS['_cron_lock_fh']) fclose($GLOBALS['_cron_lock_fh']);
    $label   = $serverFilter ? "Worker ($serverFilter)" : 'Coordinator';
    $logLine = '[' . date('Y-m-d H:i:s') . "] $label already running. Exiting." . PHP_EOL;
    @file_put_contents(__DIR__ . '/data/cron.log', $logLine, FILE_APPEND);
    exit(0);
}
ftruncate($GLOBALS['_cron_lock_fh'], 0);
fwrite($GLOBALS['_cron_lock_fh'], (string)getmypid());
fflush($GLOBALS['_cron_lock_fh']);
if (!$serverFilter) @unlink(__DIR__ . '/data/cron_starting.lock');

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

/**
 * Telegram-Nachricht via live-geladener Config senden.
 * Liest config.json neu — so sind Änderungen während des Runs wirksam.
 */
function tg_notify(string $msg, string $type = 'success'): void {
    $cfg      = load_config();
    $enabled  = (bool)($cfg['telegram_enabled']    ?? false);
    $token    = $cfg['telegram_bot_token'] ?? '';
    $chatId   = $cfg['telegram_chat_id']   ?? '';
    if (!$enabled || $token === '' || $chatId === '') return;

    // Typ-spezifische Einstellung prüfen
    $typeKey = [
        'success'    => 'tg_notify_success',
        'error'      => 'tg_notify_error',
        'queue_done' => 'tg_notify_queue_done',
        'disk_low'   => 'tg_notify_disk_low',
    ][$type] ?? null;
    if ($typeKey && !((bool)($cfg[$typeKey] ?? true))) return;

    $url  = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $body = json_encode(['chat_id' => $chatId, 'text' => $msg, 'parse_mode' => 'HTML']);
    $ctx  = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nUser-Agent: XtreamVault/1.0\r\n",
        'content' => $body,
        'timeout' => 8,
    ]]);
    $raw  = @file_get_contents($url, false, $ctx);
    if ($raw === false) { clog("WARN: Telegram nicht erreichbar"); return; }
    $resp = json_decode($raw, true);
    if (!($resp['ok'] ?? false)) clog("WARN: Telegram-Fehler — " . ($resp['description'] ?? 'unbekannt'));
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

/**
 * Lädt alle aktiven Server aus servers.json (Fallback auf config.json).
 * Deaktivierte Server werden ausgeschlossen.
 */
function cron_load_all_servers(): array {
    $servers = file_exists(SERVERS_FILE)
        ? (json_decode(file_get_contents(SERVERS_FILE), true) ?? [])
        : [];
    // Deaktivierte Server überspringen
    $servers = array_values(array_filter($servers, fn($s) => ($s['enabled'] ?? true) !== false));
    if (empty($servers) && SERVER_IP !== '' && USERNAME !== '') {
        $servers = [[
            'id'        => SERVER_ID,
            'name'      => SERVER_IP . ':' . PORT,
            'server_ip' => SERVER_IP,
            'port'      => PORT,
            'username'  => USERNAME,
            'password'  => PASSWORD,
        ]];
    }
    return $servers;
}

/**
 * Lädt alle Queue-Items über alle Server zusammen.
 * Jedes Item bekommt '_queue_file' damit save_queue_item_status
 * die richtige Datei beschreiben kann.
 */
function load_queue(): array {
    $servers = cron_load_all_servers();
    $all = [];
    foreach ($servers as $srv) {
        $file = DATA_DIR . '/queue_' . $srv['id'] . '.json';
        if (!file_exists($file)) continue;
        $items = json_decode(file_get_contents($file), true) ?? [];
        foreach ($items as &$item) {
            $item['_queue_file']  = $file;
            $item['_server_id']   = $srv['id'];
        }
        unset($item);
        $all = array_merge($all, $items);
    }
    // Fallback: alte QUEUE_FILE ohne Server-ID
    if (empty($all) && file_exists(QUEUE_FILE)) {
        $items = json_decode(file_get_contents(QUEUE_FILE), true) ?? [];
        foreach ($items as &$item) {
            $item['_queue_file'] = QUEUE_FILE;
            $item['_server_id']  = SERVER_ID;
        }
        unset($item);
        $all = $items;
    }
    return $all;
}

function save_queue(array $q): void {
    // Gruppiert nach _queue_file speichern
    $byFile = [];
    foreach ($q as $item) {
        $file = $item['_queue_file'] ?? QUEUE_FILE;
        unset($item['_queue_file'], $item['_server_id']);
        $byFile[$file][] = $item;
    }
    foreach ($byFile as $file => $items) {
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

/** Gibt den server-spezifischen Pfad zur downloaded_*.json zurück */
function get_db_file(): string {
    global $serverFilter;
    return DATA_DIR . '/downloaded_' . ($serverFilter ?? SERVER_ID) . '.json';
}
/** Gibt den server-spezifischen Pfad zur downloaded_index_*.json zurück */
function get_index_file(): string {
    global $serverFilter;
    return DATA_DIR . '/downloaded_index_' . ($serverFilter ?? SERVER_ID) . '.json';
}
/** Gibt den server-spezifischen Pfad zur download_history_*.json zurück */
function get_history_file(): string {
    global $serverFilter;
    return DATA_DIR . '/download_history_' . ($serverFilter ?? SERVER_ID) . '.json';
}

function load_db(): array {
    $file = get_db_file();
    if (!file_exists($file)) return ['movies' => [], 'episodes' => []];
    return json_decode(file_get_contents($file), true) ?? ['movies' => [], 'episodes' => []];
}

function save_db(array $db): void {
    @mkdir(DEST_PATH, 0777, true);
    file_put_contents(get_db_file(), json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/** Schreibt den aktuellen Download-Fortschritt in progress.json */
function get_progress_file(): string {
    global $serverFilter;
    if ($serverFilter !== null) {
        return DATA_DIR . '/progress_' . $serverFilter . '.json';
    }
    return PROGRESS_FILE;
}

function write_progress(array $data): void {
    @mkdir(DATA_PATH, 0755, true);
    file_put_contents(get_progress_file(), json_encode($data, JSON_UNESCAPED_UNICODE));
}

/** Löscht progress.json wenn kein Download mehr läuft */
function clear_progress(): void {
    $file = get_progress_file();
    if (file_exists($file)) unlink($file);
}

/**
 * Datei herunterladen mit Live-Fortschritt in progress.json.
 * Gibt true bei Erfolg zurück, false bei Fehler.
 */
function download_file(string $url, string $destPath, string $title, int $queuePos, int $queueTotal, string $streamId = ''): bool {
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

        // VPN-Prüfung alle 30s — nur wenn VPN beim Start aktiv war
        if (VPN_ENABLED && ($GLOBALS['vpnActiveAtStart'] ?? false) && $now % 30 === 0 && $now !== $lastWrite) {
            if (!vpn_is_up()) {
                clog("  VPN-ABBRUCH: Interface " . VPN_INTERFACE . " nicht mehr aktiv");
                return 1; // cURL abbrechen
            }
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
function rclone_stream(string $url, string $remotePath, string $title, int $queuePos, int $queueTotal, string $streamId = ''): bool {
    $rcloneBin  = RCLONE_BIN;
    $remoteDest = RCLONE_REMOTE . ':' . $remotePath;

    // rclone copyurl lädt direkt von URL in Cloud — kennt Dateigröße und gibt Stats aus
    // copyurl <url> <remote:path/file.ext> streamt direkt ans Ziel
    $cmd = escapeshellarg($rcloneBin)
        . ' copyurl ' . escapeshellarg($url)
        . ' ' . escapeshellarg($remoteDest)
        . ' --no-traverse'
        . ' --http-no-head'
        . ' --stats 2s'
        . ' --stats-one-line'
        . ' --use-mmap'
        . ' -v'
        . ' 2>&1';

    clog("  rclone copyurl → $remoteDest");

    $startTime = time();

    write_progress([
        'active'      => true,
        'stream_id'   => $streamId,
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
            if (preg_match('/([\d.]+)\s*([\w]+)\s*\/\s*([\d.]+)\s*([\w]+),\s*(\d+)%,\s*([\d.]+)\s*([\w]+\/s),\s*ETA\s*(\S+)/i', $clean, $m)) {
                $parsedDone  = parse_rclone_bytes((float)$m[1], $m[2]);
                $parsedTotal = parse_rclone_bytes((float)$m[3], $m[4]);
                $parsedSpeed = parse_rclone_bytes((float)$m[6], str_replace('/s', '', $m[7]));
                $parsedEta   = parse_rclone_eta($m[8]);

                // Ignoriere falsche 100%-Meldungen: wenn done==total aber total < bisheriges Maximum
                // (rclone meldet manchmal nur den aktuellen Chunk als 100%)
                $isFakeHundred = ((int)$m[5] === 100 && $parsedDone === $parsedTotal && $parsedTotal < $bytesTotal * 0.9);
                if (!$isFakeHundred) {
                    $bytesDone = $parsedDone;
                    // Gesamtgröße nur nach oben anpassen (nie kleiner werden)
                    if ($parsedTotal > $bytesTotal) $bytesTotal = $parsedTotal;
                    $speedBps = $parsedSpeed;
                    $etaSecs  = $parsedEta;
                    // Prozent selbst berechnen wenn Gesamtgröße bekannt
                    $percent = $bytesTotal > 0 ? min(99, (int)round($bytesDone / $bytesTotal * 100)) : (int)$m[5];
                }
            }

            // Nur letzten Teil behalten um Buffer nicht endlos wachsen zu lassen
            if (strlen($output) > 4096) $output = substr($output, -2048);
        }

        $now = time();
        if ($now - $lastWrite >= 2) {
            write_progress([
                'active'      => true,
        'stream_id'   => $streamId,
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

            // VPN-Prüfung: nur wenn VPN beim Start aktiv war
            if (VPN_ENABLED && $GLOBALS['vpnActiveAtStart'] && !vpn_is_up()) {
                clog("  VPN-ABBRUCH: Interface " . VPN_INTERFACE . " nicht mehr aktiv — beende rclone…");
                proc_terminate($proc, 9);
                @unlink(CANCEL_FILE);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return 'vpn_lost';
            }
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

// ── Parallel-Modus: pro Server ein Worker-Prozess ─────────────────────────────
// Wenn --server=<id> übergeben: nur Items dieses Servers verarbeiten (Worker-Modus)
// Ohne Argument: einen Worker pro Server mit pending Items starten

if ($serverFilter === null) {
    // Koordinator-Modus: prüfe welche Server pending Items haben
    clog("=== Xtream Vault Cron Coordinator started ===");
    $allQueue    = load_queue();
    $pendingAll  = array_filter($allQueue, fn($i) => $i['status'] === 'pending');

    if (empty($pendingAll)) {
        clog("Queue is empty – nothing to do.");
        clear_progress();
        exit(0);
    }

    // Pending Items pro Server gruppieren
    $serverIds = array_unique(array_column(array_values($pendingAll), '_server_id'));

    // Parallel-Einstellungen aus config.json lesen
    $cfg             = load_config();
    $parallelEnabled = (bool)($cfg['parallel_enabled'] ?? true);
    $parallelMax     = max(1, (int)($cfg['parallel_max'] ?? 4));

    if (!$parallelEnabled) {
        // Parallel deaktiviert → alle Server sequenziell nacheinander
        if (count($serverIds) === 1) {
            $serverFilter = $serverIds[0];
            clog(sprintf("Found %d pending item(s) — running inline for: %s", count($pendingAll), $serverFilter));
            // VPN verbinden vor inline-Run
            if (VPN_ENABLED) {
                if (vpn_is_up()) { clog("VPN: " . VPN_INTERFACE . " bereits aktiv"); $vpnActiveAtStart = true; }
                else { $r = vpn_up(); if ($r === true) { $vpnStartedByUs = true; $vpnActiveAtStart = true; clog("VPN: verbunden"); } else clog("VPN ERROR: $r"); }
            }
            goto single_server_run;
        }
        clog(sprintf("Found %d pending item(s) across %d server(s) — parallel disabled, running sequentially",
            count($pendingAll), count($serverIds)));
        $php    = PHP_BINARY ?: '/usr/bin/php';
        $script = __FILE__;

        // VPN verbinden vor sequenziellem Run
        if (VPN_ENABLED) {
            if (vpn_is_up()) {
                clog("VPN: " . VPN_INTERFACE . " bereits aktiv");
                $vpnActiveAtStart = true;
            } else {
                clog("VPN: verbinde " . VPN_INTERFACE . " …");
                $vpnResult = vpn_up();
                if ($vpnResult !== true) {
                    clog("VPN ERROR: " . $vpnResult);
                } else {
                    $vpnStartedByUs = true; $vpnActiveAtStart = true;
                    clog("VPN: " . VPN_INTERFACE . " verbunden");
                }
            }
        }

        foreach ($serverIds as $srvId) {
            clog("Starte sequenziellen Worker für Server: $srvId");
            $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --server=' . escapeshellarg($srvId);
            passthru($cmd);
            clog("Worker $srvId abgeschlossen");
        }
        // Nach sequenziellem Run: VPN, Cleanup, Cache
        if (VPN_ENABLED && $vpnStartedByUs) {
            $vpnDown = vpn_down();
            clog($vpnDown === true ? "VPN: getrennt" : "VPN WARN: " . $vpnDown);
        }
        $queue  = load_queue();
        $cutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
        $queue  = array_values(array_filter($queue, fn($i) => $i['status'] !== 'done' || ($i['done_at'] ?? $i['added_at'] ?? '9999') > $cutoff));
        save_queue($queue);
        $queueAfter = load_queue();
        $doneRecent = array_filter($queueAfter, fn($i) => $i['status'] === 'done' && isset($i['done_at']) && $i['done_at'] >= date('Y-m-d H:i:s', time() - 3600));
        if (!empty($doneRecent)) {
            clog("Starte Library-Cache Rebuild im Hintergrund…");
            shell_exec(escapeshellarg(PHP_BINARY ?: 'php') . ' ' . escapeshellarg(__DIR__ . '/cache_builder.php') . ' > ' . escapeshellarg(DATA_DIR . '/cache_build.log') . ' 2>&1 &');
        }
        clog("=== Coordinator done (sequential) ===");
        exit(0);
    }

    // Auf parallel_max begrenzen
    $serverIds = array_slice($serverIds, 0, $parallelMax);

    // Nach Begrenzung: wenn nur ein Server übrig → inline ausführen
    if (count($serverIds) === 1) {
        $serverFilter = $serverIds[0];
        clog(sprintf("Found %d pending item(s) — only 1 server after limit, running inline: %s",
            count($pendingAll), $serverFilter));
        // VPN verbinden vor inline-Run
        if (VPN_ENABLED) {
            if (vpn_is_up()) { clog("VPN: " . VPN_INTERFACE . " bereits aktiv"); $vpnActiveAtStart = true; }
            else { $r = vpn_up(); if ($r === true) { $vpnStartedByUs = true; $vpnActiveAtStart = true; clog("VPN: verbunden"); } else clog("VPN ERROR: $r"); }
        }
        goto single_server_run;
    }

    clog(sprintf("Found %d pending item(s) across %d server(s) (max %d parallel): %s",
        count($pendingAll), count($serverIds), $parallelMax, implode(', ', $serverIds)));

    // VPN verbinden (vor allen Downloads)
    $vpnStartedByUs  = false;
    $vpnActiveAtStart = false;
    if (VPN_ENABLED) {
        if (vpn_is_up()) {
            clog("VPN: " . VPN_INTERFACE . " bereits aktiv");
            $vpnActiveAtStart = true;
        } else {
            clog("VPN: verbinde " . VPN_INTERFACE . " …");
            $vpnResult = vpn_up();
            if ($vpnResult !== true) {
                clog("VPN ERROR: " . $vpnResult . " — Downloads werden trotzdem gestartet");
            } else {
                $vpnStartedByUs   = true;
                $vpnActiveAtStart = true;
                clog("VPN: " . VPN_INTERFACE . " verbunden");
            }
        }
    }

    // Mehrere Server → Worker-Prozesse starten
    $php     = PHP_BINARY ?: '/usr/bin/php';
    $script  = __FILE__;
    $procs   = [];
    foreach ($serverIds as $srvId) {
        $cmd    = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --server=' . escapeshellarg($srvId);
        $proc   = proc_open($cmd, [0 => ['pipe','r'], 1 => STDOUT, 2 => STDERR], $pipes);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $procs[$srvId] = $proc;
            clog("Worker gestartet für Server: $srvId (PID " . proc_get_status($proc)['pid'] . ")");
        } else {
            clog("WARN: Konnte Worker für Server $srvId nicht starten");
        }
    }

    // Warten bis alle Worker fertig sind
    foreach ($procs as $srvId => $proc) {
        $status = proc_close($proc);
        clog("Worker $srvId beendet (exit $status)");
    }

    // VPN trennen wenn wir ihn gestartet haben
    if (VPN_ENABLED && $vpnStartedByUs) {
        clog("VPN: trenne " . VPN_INTERFACE . " …");
        $vpnDown = vpn_down();
        clog($vpnDown === true ? "VPN: getrennt" : "VPN WARN: " . $vpnDown);
    }

    // Cleanup nach allen Workern
    $queue  = load_queue();
    $cutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
    $before = count($queue);
    $queue  = array_values(array_filter($queue, function($item) use ($cutoff) {
        if (($item['status'] ?? '') !== 'done') return true;
        return ($item['done_at'] ?? $item['added_at'] ?? '9999') > $cutoff;
    }));
    $removed = $before - count($queue);
    if ($removed > 0) { save_queue($queue); clog("CLEANUP: $removed alte done-Einträge entfernt"); }

    $tgPending = count(array_filter(load_queue(), fn($i) => $i['status'] === 'pending'));
    if ($tgPending === 0) {
        tg_notify("🏁 Alle Downloads abgeschlossen", 'queue_done');
    }

    // Cache neu aufbauen wenn in den letzten 60 Minuten neue done-Einträge entstanden sind
    // (new_releases wird dabei ebenfalls aktualisiert)
    $queueAfter = load_queue();
    $doneRecent = array_filter($queueAfter, function($i) {
        return $i['status'] === 'done'
            && isset($i['done_at'])
            && $i['done_at'] >= date('Y-m-d H:i:s', time() - 3600);
    });
    if (!empty($doneRecent)) {
        clog("Starte Library-Cache Rebuild im Hintergrund…");
        $script = escapeshellarg(__DIR__ . '/cache_builder.php');
        $log    = escapeshellarg(DATA_DIR . '/cache_build.log');
        shell_exec("php {$script} > {$log} 2>&1 &");
    }

    // Telegram: Speicherplatz-Warnung
    if (!RCLONE_ENABLED && DEST_PATH !== '') {
        $cfgDisk = load_config();
        $lowGb   = (float)($cfgDisk['tg_disk_low_gb'] ?? 10);
        $path    = is_dir(DEST_PATH) ? DEST_PATH : dirname(DEST_PATH);
        if (is_dir($path)) {
            $freeBytes = disk_free_space($path);
            if ($freeBytes !== false && ($freeBytes / 1073741824) < $lowGb) {
                $freeGb = round($freeBytes / 1073741824, 1);
                tg_notify(
                    "⚠️ <b>Speicherplatz niedrig</b>\nNoch <b>{$freeGb} GB</b> frei auf " . htmlspecialchars(DEST_PATH, ENT_XML1),
                    'disk_low'
                );
            }
        }
    }

    clog("=== Coordinator done ===");
    exit(0);
}

// ── Worker-Modus: ab hier nur Items des angegebenen Servers ───────────────────
single_server_run:
clog("=== Xtream Vault Worker started (server: $serverFilter) ===");

$queue = load_queue();
$db    = load_db();
$now   = time();

// Im Worker-Modus: nur Items des zugewiesenen Servers
if ($serverFilter !== null) {
    $queue = array_values(array_filter($queue, fn($i) => ($i['_server_id'] ?? '') === $serverFilter));
}

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

// ── VPN: nur im Single-Server-Modus (Worker: Coordinator übernimmt) ───────────
$vpnStartedByUs   = $vpnStartedByUs  ?? false;
$vpnActiveAtStart = $vpnActiveAtStart ?? false;

if (VPN_ENABLED && $totalPending > 0 && $serverFilter === null) {
    if (vpn_is_up()) {
        clog("VPN: " . VPN_INTERFACE . " bereits aktiv");
        $vpnActiveAtStart = true;
    } else {
        clog("VPN: verbinde " . VPN_INTERFACE . " …");
        $vpnResult = vpn_up();
        if ($vpnResult !== true) {
            clog("VPN ERROR: " . $vpnResult . " — Downloads werden trotzdem gestartet");
        } else {
            $vpnStartedByUs   = true;
            $vpnActiveAtStart = true;
            clog("VPN: " . VPN_INTERFACE . " verbunden");
        }
    }
}

$processed = 0;
$errors    = 0;
$position  = 0;

// ── rclone-Cache vor dem Run neu aufbauen ─────────────────────────────────────
if (RCLONE_ENABLED && $totalPending > 0) {
    clog("INFO: Baue rclone-Cache neu auf…");
    $rcloneCacheFile = DATA_DIR . '/rclone_cache.json';
    $rcloneFileCache = list_rclone_files(RCLONE_REMOTE, RCLONE_PATH);
    file_put_contents($rcloneCacheFile, json_encode($rcloneFileCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    clog("INFO: rclone-Cache aktualisiert — " . count($rcloneFileCache) . " Dateien bekannt");
}

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

    // Vor dem Download: prüfen ob Item noch in der Queue (könnte manuell entfernt worden sein)
    $liveQueue = load_queue();
    $liveItem  = null;
    foreach ($liveQueue as $q) {
        if ((string)$q['stream_id'] === (string)$sid) { $liveItem = $q; break; }
    }
    if ($liveItem === null) {
        clog("SKIP (removed from queue): $title");
        continue;
    }

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
        $item['status']  = 'done';
        $item['done_at'] = $item['done_at'] ?? date('Y-m-d H:i:s');
        save_queue_item_status($sid, $item);
        continue;
    }
    if ($destFile && file_exists($destFile)) {
        clog("SKIP (file exists): $destFile");
        $item['status']  = 'done';
        $item['done_at'] = $item['done_at'] ?? date('Y-m-d H:i:s');
        if (!in_array((string)$sid, $db[$dbKey])) { $db[$dbKey][] = (string)$sid; save_db($db); }
        save_queue_item_status($sid, $item);
        continue;
    }

    // rclone-Cache: prüfen ob Datei bereits auf dem Remote vorhanden ist
    if (RCLONE_ENABLED) {
        $destFilename = basename($relPath);
        if (in_array($destFilename, $rcloneFileCache)) {
            clog("SKIP (already on remote): $destDisplay");
            $item['status']  = 'done';
            $item['done_at'] = $item['done_at'] ?? date('Y-m-d H:i:s');
            if (!in_array((string)$sid, $db[$dbKey])) { $db[$dbKey][] = (string)$sid; save_db($db); }
            save_queue_item_status($sid, $item);
            continue;
        }
    }

    clog("START: [$position/$totalPending] $title  →  $destDisplay");
    clog(sprintf("  Typ: %s | Ext: %s | Server: %s", strtoupper($type), strtoupper($ext), $item['_server_id'] ?? 'default'));

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
        'stream_id'   => (string)$sid,
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

    $downloadStartTime = time();
    $ok = RCLONE_ENABLED
        ? rclone_stream($url, $remotePath, $title, $position, $totalPending, (string)$sid)
        : download_file($url, $destFile, $title, $position, $totalPending, (string)$sid);

    if ($ok === 'cancelled') {
        clog("CANCELLED: $title — zurück auf pending");
        $item['status'] = 'pending';
        $item['error']  = null;
        save_queue_item_status($sid, $item);
        clear_progress();
        break; // Gesamten cron-Loop beenden — nächster Cron-Run macht weiter
    } elseif ($ok === 'vpn_lost') {
        clog("VPN-ABBRUCH: $title — VPN-Verbindung verloren, zurück auf pending");
        $item['status'] = 'pending';
        $item['error']  = 'VPN-Verbindung während des Downloads verloren';
        save_queue_item_status($sid, $item);
        clear_progress();
        $errors++;
        break; // Cron-Loop beenden — kein VPN, keine weiteren Downloads
    } elseif ($ok) {
        $item['status']  = 'done';
        $item['done_at'] = date('Y-m-d H:i:s');
        if (!in_array((string)$sid, $db[$dbKey])) { $db[$dbKey][] = (string)$sid; save_db($db); }

        // Metadaten sofort in downloaded_index.json schreiben
        // (ersetzt den nie fertig werdenden series_cache.json Aufbau)
        $existingIndex = [];
        if (file_exists(get_index_file())) {
            $raw = @file_get_contents(get_index_file());
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
        @file_put_contents(get_index_file(), json_encode($existingIndex, JSON_UNESCAPED_UNICODE));
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
            'stream_id' => (string)($item['stream_id'] ?? ''),
            'title'    => $title,
            'type'     => $type === 'movie' ? 'movie' : 'episode',
            'cover'    => $item['cover']    ?? '',
            'category' => $item['category'] ?? '',
            'added_by' => $item['added_by'] ?? '',
            'done_at'  => date('Y-m-d H:i:s'),
            'bytes'    => $downloadedBytes,
        ];
        $history = [];
        if (file_exists(get_history_file())) {
            $raw = @file_get_contents(get_history_file());
            if ($raw !== false) $history = json_decode($raw, true) ?? [];
        }
        array_unshift($history, $historyEntry);          // neueste zuerst
        if (count($history) > 2000) $history = array_slice($history, 0, 2000); // max 2000
        @file_put_contents(get_history_file(), json_encode($history, JSON_UNESCAPED_UNICODE));
        unset($history);

        $durationSec = time() - $downloadStartTime;
        // Dateigröße aus lokalem File oder progress.json lesen
        $downloadedBytes = 0;
        if ($destFile && file_exists($destFile)) {
            $downloadedBytes = filesize($destFile) ?: 0;
        } elseif (file_exists(get_progress_file())) {
            $prog = json_decode(@file_get_contents(get_progress_file()), true) ?? [];
            $downloadedBytes = (int)($prog['bytes_done'] ?? 0);
        }
        $sizeStr  = $downloadedBytes > 0 ? sprintf('%.1f MB', $downloadedBytes / 1048576) : '';
        $speedStr = ($durationSec > 0 && $downloadedBytes > 0)
            ? sprintf(' @ %.1f MB/s', $downloadedBytes / 1048576 / $durationSec) : '';
        clog(sprintf("DONE:  %s  [%s%s%s]",
            $title,
            $sizeStr,
            $speedStr,
            $durationSec > 0 ? ' in ' . gmdate('H:i:s', $durationSec) : ''
        ));
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

        // Telegram: Download abgeschlossen
        $typeLabel = $type === 'movie' ? '🎬 Film' : '📺 Episode';
        $sizeStr   = $downloadedBytes > 0 ? "\n📦 " . round($downloadedBytes / 1048576) . ' MB' : '';
        tg_notify("✅ <b>Download abgeschlossen</b>\n{$typeLabel}: <b>" . htmlspecialchars($title, ENT_XML1) . "</b>{$sizeStr}", 'success');
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
        clog(sprintf("ERROR: %s — fehlgeschlagen nach %ds", $title, time() - $downloadStartTime));
        $errors++;
        // Telegram: Fehler-Benachrichtigung
        $typeLabel = $type === 'movie' ? '🎬 Film' : '📺 Episode';
        tg_notify("❌ <b>Download fehlgeschlagen</b>\n{$typeLabel}: <b>" . htmlspecialchars($title, ENT_XML1) . "</b>", 'error');
    }
    save_queue_item_status($sid, $item);
}

/**
 * Aktualisiert nur den Status eines einzelnen Queue-Items in der Datei.
 * Im Worker-Modus wird nur die server-spezifische Queue-Datei geschrieben
 * um Race-Conditions zwischen parallelen Workern zu vermeiden.
 */
function save_queue_item_status(string $sid, array $updatedItem): void {
    global $serverFilter;

    // Im Worker-Modus: direkt die server-spezifische Datei schreiben
    if ($serverFilter !== null) {
        $file = DATA_DIR . '/queue_' . $serverFilter . '.json';
        if (!file_exists($file)) $file = QUEUE_FILE;
        $items = json_decode(@file_get_contents($file), true) ?? [];
        $found = false;
        foreach ($items as &$qi) {
            if ((string)$qi['stream_id'] === $sid) {
                $qi['status']  = $updatedItem['status'];
                $qi['error']   = $updatedItem['error']   ?? null;
                $qi['done_at'] = $updatedItem['done_at'] ?? $qi['done_at'] ?? null;
                $found = true;
                break;
            }
        }
        unset($qi);
        if ($found) file_put_contents($file, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return;
    }

    // Single-Server-Modus: über load/save_queue (korrekt für Fallback-Dateien)
    $current = load_queue();
    $found   = false;
    foreach ($current as &$qi) {
        if ((string)$qi['stream_id'] === $sid) {
            $qi['status']  = $updatedItem['status'];
            $qi['error']   = $updatedItem['error']   ?? null;
            $qi['done_at'] = $updatedItem['done_at'] ?? $qi['done_at'] ?? null;
            $found = true;
            break;
        }
    }
    unset($qi);
    if ($found) save_queue($current);
}

clear_progress();
clog(sprintf("=== Worker done (server: %s): %d downloaded, %d errors ===", $serverFilter ?? 'all', $processed, $errors));

// Im Worker-Modus (hat --server= Argument): Cleanup übernimmt der Coordinator
$isWorker = ($serverFilter !== null);
if ($isWorker) exit(0);

// ── Automatische Bereinigung: done-Einträge älter als 7 Tage entfernen ────────
$queue = load_queue();
$cutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
$before = count($queue);
$queue  = array_values(array_filter($queue, function($item) use ($cutoff) {
    if (($item['status'] ?? '') !== 'done') return true;
    return ($item['done_at'] ?? $item['added_at'] ?? '9999') > $cutoff;
}));
$removed = $before - count($queue);
if ($removed > 0) {
    save_queue($queue);
    clog("CLEANUP: $removed alte done-Einträge entfernt");
}

// ── VPN: nach Downloads trennen ───────────────────────────────────────────────
// Nur trennen wenn cron ihn selbst gestartet hat.
// War VPN bereits vorher aktiv, bleibt er nach den Downloads aktiv.
if (VPN_ENABLED && $vpnStartedByUs) {
    if (!vpn_is_up()) {
        clog("VPN: " . VPN_INTERFACE . " bereits getrennt (extern)");
    } else {
        clog("VPN: trenne " . VPN_INTERFACE . " …");
        $vpnDown = vpn_down();
        if ($vpnDown !== true) clog("VPN WARN: " . $vpnDown);
        else clog("VPN: " . VPN_INTERFACE . " getrennt");
    }
}

// Benachrichtigung: alle Downloads abgeschlossen
if ($processed > 0) {
    $totalBytes = 0;
    if (file_exists(get_history_file())) {
        $hist = json_decode(@file_get_contents(get_history_file()), true) ?? [];
        $totalBytes = array_sum(array_column(array_slice($hist, 0, $processed), 'bytes'));
    }
    // Telegram: Queue abgeschlossen
    $sizeStr  = $totalBytes > 0 ? "\n📦 " . round($totalBytes / 1073741824, 2) . ' GB' : '';
    $errStr   = $errors > 0 ? "\n⚠️ {$errors} Fehler" : '';
    $queueNow = load_queue();
    $pending  = count(array_filter($queueNow, fn($q) => in_array($q['status'] ?? '', ['pending'])));
    $moreStr  = $pending > 0 ? "\n⏳ {$pending} weitere in Queue" : '';
    tg_notify(
        "🏁 <b>Queue-Run abgeschlossen</b>\n✅ {$processed} heruntergeladen{$sizeStr}{$errStr}{$moreStr}",
        'queue_done'
    );
}

// Telegram: Speicherplatz-Warnung
if (!RCLONE_ENABLED && DEST_PATH !== '') {
    $cfg = load_config();
    $lowGb = (float)($cfg['tg_disk_low_gb'] ?? 10);
    $path  = is_dir(DEST_PATH) ? DEST_PATH : dirname(DEST_PATH);
    if (is_dir($path)) {
        $freeBytes = disk_free_space($path);
        if ($freeBytes !== false && ($freeBytes / 1073741824) < $lowGb) {
            $freeGb = round($freeBytes / 1073741824, 1);
            tg_notify(
                "⚠️ <b>Speicherplatz niedrig</b>\nNoch <b>{$freeGb} GB</b> frei auf " . htmlspecialchars(DEST_PATH, ENT_XML1),
                'disk_low'
            );
        }
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
    if (file_exists(get_index_file())) {
        $raw = @file_get_contents(get_index_file());
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
    @file_put_contents(get_index_file(), json_encode($existingIndex, JSON_UNESCAPED_UNICODE));
    clog("Migration abgeschlossen: $added neue Einträge hinzugefügt.");
    exit(0);
}
