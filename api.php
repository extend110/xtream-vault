<?php
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

// ── Request-Body einmalig lesen und cachen (php://input nur einmal lesbar) ────
$_RAW_BODY  = file_get_contents('php://input');
$_JSON_BODY = json_decode($_RAW_BODY ?: '{}', true) ?? [];

require_once __DIR__ . '/auth.php';

// ── Einmalige Migration: alte Dateinamen → server-spezifische Namen ───────────
if (is_configured() && SERVER_ID !== 'default') {
    $migrations = [
        DATA_DIR . '/downloaded.json'       => DOWNLOAD_DB,
        DATA_DIR . '/queue.json'            => QUEUE_FILE,
        DATA_DIR . '/downloaded_index.json' => DOWNLOADED_INDEX_FILE,
        DATA_DIR . '/download_history.json' => DOWNLOAD_HISTORY_FILE,
        DATA_DIR . '/library_cache.json'    => LIBRARY_CACHE_FILE,
        DATA_DIR . '/series_cache.json'     => SERIES_CACHE_FILE,
    ];
    foreach ($migrations as $old => $new) {
        if (file_exists($old) && !file_exists($new)) {
            @rename($old, $new);
        }
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ─── API-Key-Authentifizierung (für externe Aufrufe) ──────────────────────────
// Header: X-API-Key: xv_...  oder Query: ?api_key=xv_...
$api_key_value = $_SERVER['HTTP_X_API_KEY']
    ?? $_SERVER['HTTP_X_API_KEY']
    ?? ($_GET['api_key'] ?? '');

$api_key_auth  = false;
$current_user  = null;

if ($api_key_value !== '') {
    $key_record = find_api_key($api_key_value);
    if (!$key_record) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or revoked API key']);
        exit;
    }

    // IP-Whitelist prüfen
    $allowedIps = array_filter(array_map('trim', explode(',', load_config()['api_allowed_ips'] ?? '')));
    if (!empty($allowedIps)) {
        $remoteIp = $_SERVER['HTTP_X_FORWARDED_FOR']
            ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
            : ($_SERVER['REMOTE_ADDR'] ?? '');
        $allowed = false;
        foreach ($allowedIps as $cidr) {
            if (ip_in_cidr($remoteIp, $cidr)) { $allowed = true; break; }
        }
        if (!$allowed) {
            http_response_code(403);
            echo json_encode(['error' => 'IP address not allowed: ' . $remoteIp]);
            exit;
        }
    }

    record_api_key_use($api_key_value);
    $api_key_auth = true;
    // Erlaubte externe Endpoints
    $external_allowed = [
        'external_create_user',
        'external_list_users',
        'external_suspend_user',
        'external_delete_user',
        'external_update_user',
    ];
    if (!in_array($action, $external_allowed)) {
        http_response_code(403);
        echo json_encode(['error' => 'This endpoint is not available via API key']);
        exit;
    }
}

// ─── Auth-freie Endpoints ─────────────────────────────────────────────────────
$public_actions = ['login', 'logout', 'setup_status', 'health'];
if (!$api_key_auth && !in_array($action, $public_actions)) {
    $current_user = require_login();
}

// ─── CSRF-Schutz für alle POST-Requests (außer API-Key-Auth und öffentliche Endpoints) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$api_key_auth && !in_array($action, $public_actions)) {
    session_start_safe();
    if (!csrf_verify($_JSON_BODY)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token invalid or missing']);
        exit;
    }
}

// ─── Nicht konfiguriert → Fehler außer bei Config-Aktionen ───────────────────
$config_actions = [
    // Server-Verwaltung
    'get_config', 'save_config',
    'save_server', 'list_servers', 'delete_server', 'test_server', 'toggle_server',
    // Benutzer & Rollen (funktionieren ohne Server)
    'list_users', 'create_user', 'update_user', 'delete_user',
    'change_own_password', 'set_language', 'get_activity_log',
    'create_invite', 'list_invites', 'delete_invite',
    'create_api_key', 'list_api_keys', 'delete_api_key',
    // Dashboard & UI (zeigen leere Daten statt Fehler)
    'dashboard_data', 'stats', 'get_queue',
    'backup_list', 'get_maintenance', 'maintenance_enable', 'maintenance_disable',
    'get_cache_status',
    // Externe API
    'external_create_user', 'external_list_users', 'external_suspend_user',
    'external_delete_user', 'external_update_user',
];
if (!is_configured() && !in_array($action, $config_actions) && !in_array($action, $public_actions)) {
    http_response_code(503);
    echo json_encode(['error' => 'not_configured']);
    exit;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function clean_title_for_dedup(string $t): string {
    $t = mb_strtolower($t);
    $t = preg_replace('/\b(19|20)\d{2}\b/', '', $t); // Jahr entfernen
    $t = preg_replace('/[^a-z0-9\s]/', '', $t);       // Sonderzeichen
    $t = preg_replace('/\s+/', ' ', trim($t));
    return $t;
}

/** Prüft ob eine IP-Adresse in einem CIDR-Block liegt (IPv4, z.B. 192.168.1.0/24 oder 1.2.3.4) */
function ip_in_cidr(string $ip, string $cidr): bool {
    if (!str_contains($cidr, '/')) return $ip === $cidr;
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;
    if ($bits < 0 || $bits > 32) return false;
    $ipLong     = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === false || $subnetLong === false) return false;
    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
    return ($ipLong & $mask) === ($subnetLong & $mask);
}

function xtream_for_server(array $server, string $action, array $extra = []): array {
    $params = array_merge([
        'username' => $server['username'],
        'password' => $server['password'] ?? '',
        'action'   => $action,
    ], $extra);
    $url = 'http://' . $server['server_ip'] . ':' . $server['port'] . '/player_api.php?' . http_build_query($params);
    $ctx = stream_context_create(['http' => [
        'timeout' => 20,
        'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n"
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? (json_decode($raw, true) ?? []) : [];
}

/**
 * Lädt alle **aktiven** Server aus servers.json.
 * Deaktivierte Server (enabled === false) werden ausgeschlossen.
 * Fällt auf config.json zurück wenn servers.json leer ist.
 */
function load_all_servers(): array {
    $servers = file_exists(SERVERS_FILE)
        ? (json_decode(file_get_contents(SERVERS_FILE), true) ?? [])
        : [];
    // Nur aktivierte Server zurückgeben (enabled fehlt → gilt als aktiv)
    $servers = array_values(array_filter($servers, fn($s) => ($s['enabled'] ?? true) !== false));
    // Fallback: aktiver Server aus config.json wenn servers.json leer
    if (empty($servers) && SERVER_IP !== '' && USERNAME !== '') {
        $servers = [[
            'id'        => SERVER_ID,
            'name'      => SERVER_IP . ':' . PORT,
            'server_ip' => SERVER_IP,
            'port'      => PORT,
            'username'  => USERNAME,
            'password'  => PASSWORD,
            'enabled'   => true,
        ]];
    }
    return $servers;
}

function load_db(?string $serverId = null): array {
    if ($serverId !== null) {
        $file = DATA_DIR . '/downloaded_' . $serverId . '.json';
        if (file_exists($file)) return json_decode(file_get_contents($file), true) ?? ['movies' => [], 'episodes' => []];
        return ['movies' => [], 'episodes' => []];
    }
    // Alle Server-DBs zusammenführen
    $servers = load_all_servers();
    $merged  = ['movies' => [], 'episodes' => []];
    foreach ($servers as $srv) {
        $file = DATA_DIR . '/downloaded_' . $srv['id'] . '.json';
        if (!file_exists($file)) continue;
        $db = json_decode(file_get_contents($file), true) ?? [];
        $merged['movies']   = array_unique(array_merge($merged['movies'],   array_map('strval', $db['movies']   ?? [])));
        $merged['episodes'] = array_unique(array_merge($merged['episodes'], array_map('strval', $db['episodes'] ?? [])));
    }
    // Fallback: alte DOWNLOAD_DB — immer zusammenführen (nicht nur wenn leer)
    if (file_exists(DOWNLOAD_DB)) {
        $legacy = json_decode(file_get_contents(DOWNLOAD_DB), true) ?? [];
        $merged['movies']   = array_values(array_unique(array_merge($merged['movies'],   array_map('strval', $legacy['movies']   ?? []))));
        $merged['episodes'] = array_values(array_unique(array_merge($merged['episodes'], array_map('strval', $legacy['episodes'] ?? []))));
    }
    return $merged;
}
function save_db(array $db, ?string $serverId = null): void {
    @mkdir(DATA_PATH, 0755, true);
    $file = $serverId ? DATA_DIR . '/downloaded_' . $serverId . '.json' : DOWNLOAD_DB;
    file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function load_queue(): array {
    $servers = load_all_servers();
    $all = [];
    foreach ($servers as $srv) {
        $file = DATA_DIR . '/queue_' . $srv['id'] . '.json';
        if (!file_exists($file)) continue;
        $items = json_decode(file_get_contents($file), true) ?? [];
        foreach ($items as &$item) {
            $item['_queue_file'] = $file;
            $item['_server_id']  = $srv['id'];
        }
        unset($item);
        $all = array_merge($all, $items);
    }
    // Fallback: alte QUEUE_FILE
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
/** Führt alle downloaded_index_*.json zusammen → assoziativ [stream_id => entry] */
function load_all_index(): array {
    $merged = [];
    foreach (glob(DATA_DIR . '/downloaded_index_*.json') ?: [] as $f) {
        $idx = json_decode(@file_get_contents($f), true) ?? [];
        $merged = array_merge($merged, $idx);
    }
    // Fallback: alte DOWNLOADED_INDEX_FILE
    if (empty($merged) && file_exists(DOWNLOADED_INDEX_FILE)) {
        $merged = json_decode(file_get_contents(DOWNLOADED_INDEX_FILE), true) ?? [];
    }
    return $merged;
}

function load_all_history(): array {
    $all = [];
    // Alle server-spezifischen History-Dateien
    foreach (glob(DATA_DIR . '/download_history_*.json') ?: [] as $f) {
        $entries = json_decode(@file_get_contents($f), true) ?? [];
        $all = array_merge($all, $entries);
    }
    // Fallback: alte DOWNLOAD_HISTORY_FILE
    if (empty($all) && file_exists(DOWNLOAD_HISTORY_FILE)) {
        $all = json_decode(file_get_contents(DOWNLOAD_HISTORY_FILE), true) ?? [];
    }
    // Nach done_at absteigend sortieren
    usort($all, fn($a, $b) => strcmp($b['done_at'] ?? '', $a['done_at'] ?? ''));
    return $all;
}
function save_queue(array $q): void {
    @mkdir(DATA_PATH, 0755, true);
    $byFile = [];
    foreach ($q as $item) {
        $file = $item['_queue_file'] ?? QUEUE_FILE;
        unset($item['_queue_file'], $item['_server_id']);
        $byFile[$file][] = $item;
    }
    // Alle bekannten Queue-Dateien schreiben — auch leere (verhindert dass
    // entfernte Items in nicht mehr geschriebenen Dateien verbleiben)
    $servers = load_all_servers();
    foreach ($servers as $srv) {
        $f = DATA_DIR . '/queue_' . $srv['id'] . '.json';
        if (!isset($byFile[$f]) && file_exists($f)) {
            $byFile[$f] = []; // Explizit leeren
        }
    }
    if (!isset($byFile[QUEUE_FILE]) && file_exists(QUEUE_FILE)) {
        $byFile[QUEUE_FILE] = [];
    }
    foreach ($byFile as $file => $items) {
        file_put_contents($file, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
function sanitize(string $n): string {
    $n = str_replace(['/', '\\'], '-', $n);
    $n = preg_replace('/[<>:"|?*]/u', '', $n);
    // Unicode-safe: erlaubt Buchstaben (inkl. Umlaute), Zahlen etc.
    $n = preg_replace('/[^\p{L}\p{N}\s\-_\.]/u', '', $n);
    return trim(preg_replace('/\s+/u', ' ', $n)) ?: 'Uncategorized';
}
function stream_url_for_server(array $server, string $type, $id, string $ext): string {
    $p = $type === 'movie' ? 'movie' : 'series';
    return 'http://' . $server['server_ip'] . ':' . $server['port'] . "/{$p}/" . $server['username'] . '/' . ($server['password'] ?? '') . "/{$id}.{$ext}";
}

// ─── Router ───────────────────────────────────────────────────────────────────
switch ($action) {

    // ── Health Check ──────────────────────────────────────────────────────────
    case 'get_recent_downloads':
        require_permission('browse');
        $history = load_all_history();
        // Editoren/Viewer sehen nur eigene Downloads
        if (!in_array('settings', ROLE_PERMISSIONS[$current_user['role'] ?? 'viewer'] ?? [])) {
            $history = array_values(array_filter($history, fn($h) => ($h['added_by'] ?? '') === $current_user['username']));
        }
        $items = array_slice($history, 0, 20);
        // Cover aus downloaded_index als Fallback wenn leer
        $index = load_all_index();
        if (!empty($index)) {
            foreach ($items as &$h) {
                if (empty($h['cover'])) {
                    foreach ($index as $entry) {
                        if (($entry['title'] ?? '') === ($h['title'] ?? '') && !empty($entry['cover'])) {
                            $h['cover'] = $entry['cover'];
                            break;
                        }
                    }
                }
            }
            unset($h);
        }
        echo json_encode(['items' => $items]);
        break;

    case 'dismiss_all_new_releases':
        require_permission('settings');
        $nr = file_exists(NEW_RELEASES_FILE)
            ? (json_decode(file_get_contents(NEW_RELEASES_FILE), true) ?? [])
            : [];
        $nr['movies'] = [];
        $nr['series'] = [];
        file_put_contents(NEW_RELEASES_FILE, json_encode($nr, JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]);
        break;

    case 'dismiss_new_release':
        require_permission('settings');
        $d    = json_decode($_RAW_BODY, true) ?? [];
        $id   = (string)($d['id']   ?? '');
        $type = $d['type'] ?? 'movie'; // 'movie' or 'series'
        if ($id === '') { echo json_encode(['error' => 'Missing id']); break; }
        $nr = file_exists(NEW_RELEASES_FILE)
            ? (json_decode(file_get_contents(NEW_RELEASES_FILE), true) ?? [])
            : [];
        if ($type === 'series') {
            $nr['series'] = array_values(array_filter($nr['series'] ?? [], fn($s) =>
                (string)($s['series_id'] ?? $s['id'] ?? '') !== $id
            ));
        } else {
            $nr['movies'] = array_values(array_filter($nr['movies'] ?? [], fn($m) =>
                (string)($m['stream_id'] ?? $m['id'] ?? '') !== $id
            ));
        }
        file_put_contents(NEW_RELEASES_FILE, json_encode($nr, JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]);
        break;

    case 'get_new_releases':
        require_permission('browse');
        $file = DATA_DIR . '/new_releases.json';
        if (!file_exists($file)) {
            echo json_encode(['movies' => [], 'series' => [], 'generated_at' => null]);
            break;
        }
        $data = json_decode(file_get_contents($file), true) ?? [];
        $db   = load_db();
        foreach (($data['movies'] ?? []) as &$m) {
            $m['downloaded']  = in_array((string)($m['stream_id'] ?? $m['id'] ?? ''), $db['movies']);
            $m['stream_id']   = $m['stream_id'] ?? $m['id'] ?? '';
            $m['clean_title'] = display_title($m['title'] ?? '');
            // _server_id aus Cache übernehmen falls vorhanden
        }
        unset($m);
        echo json_encode([
            'movies'       => $data['movies']       ?? [],
            'series'       => $data['series']       ?? [],
            'generated_at' => $data['generated_at'] ?? null,
        ]);
        break;

    case 'stream_info':
        require_permission('browse');
        $d   = $_GET + (json_decode($_RAW_BODY, true) ?? []);
        $sid = (string)($d['stream_id'] ?? '');
        $type = $d['type'] ?? 'movie';
        $ext  = $d['ext']  ?? 'mp4';
        $srvIdInfo = $d['server_id'] ?? '';
        if ($sid === '') { echo json_encode(['error' => 'Missing stream_id']); break; }
        if (!is_configured()) { echo json_encode(['error' => 'Nicht konfiguriert']); break; }

        // ffprobe verfügbar?
        exec('ffprobe -version 2>&1', $verOut, $verRet);
        if ($verRet !== 0) { echo json_encode(['error' => 'ffprobe nicht installiert']); break; }

        $allSrvInfo = load_all_servers();
        $srvInfo = null;
        foreach ($allSrvInfo as $s) { if ($s['id'] === $srvIdInfo) { $srvInfo = $s; break; } }
        if (!$srvInfo) $srvInfo = $allSrvInfo[0] ?? null;
        $url = $srvInfo ? stream_url_for_server($srvInfo, $type === 'episode' ? 'series' : 'movie', $sid, $ext) : '';

        // ffprobe: nur Header lesen (keine Daten herunterladen), Timeout 10s
        $cmd = 'ffprobe -v quiet -print_format json -show_streams -show_format'
             . ' -analyzeduration 3000000 -probesize 1000000'
             . ' ' . escapeshellarg($url) . ' 2>&1';
        exec($cmd, $ffOut, $ffRet);
        $raw = implode('', $ffOut);
        $probe = json_decode($raw, true);

        if (!$probe || empty($probe['streams'])) {
            echo json_encode(['error' => 'Stream nicht erreichbar oder kein Video gefunden']);
            break;
        }

        $video = null;
        $audio = null;
        foreach ($probe['streams'] as $s) {
            if ($s['codec_type'] === 'video' && !$video) $video = $s;
            if ($s['codec_type'] === 'audio' && !$audio) $audio = $s;
        }

        $result = [];

        if ($video) {
            $w = (int)($video['width']  ?? 0);
            $h = (int)($video['height'] ?? 0);
            // Auflösungs-Label
            $label = '';
            if    ($h >= 2160) $label = '4K UHD';
            elseif ($h >= 1440) $label = '1440p';
            elseif ($h >= 1080) $label = '1080p';
            elseif ($h >= 720)  $label = '720p';
            elseif ($h >= 480)  $label = '480p';
            elseif ($h >  0)    $label = $h . 'p';

            // Bitrate
            $bitrate = 0;
            if (!empty($video['bit_rate'])) {
                $bitrate = (int)$video['bit_rate'];
            } elseif (!empty($probe['format']['bit_rate'])) {
                $bitrate = (int)$probe['format']['bit_rate'];
            }

            // Framerate
            $fps = '';
            if (!empty($video['r_frame_rate'])) {
                $parts = explode('/', $video['r_frame_rate']);
                if (count($parts) === 2 && (int)$parts[1] > 0) {
                    $fps = round((int)$parts[0] / (int)$parts[1], 1) . ' fps';
                }
            }

            // Codec-Profil
            $codecName = strtoupper($video['codec_name'] ?? '');
            $profile   = $video['profile'] ?? '';
            $codecStr  = $codecName;
            if ($profile && $profile !== 'unknown') {
                // Kürzen: "High" → "H", "Main" → "M" etc.
                $codecStr .= ' ' . $profile;
            }
            // HDR-Erkennung
            $colorTransfer = $video['color_transfer'] ?? '';
            $hdr = '';
            if (str_contains($colorTransfer, 'smpte2084') || str_contains($colorTransfer, 'arib-std-b67')) {
                $hdr = 'HDR';
            }

            $result['video'] = [
                'codec'      => $codecStr,
                'width'      => $w,
                'height'     => $h,
                'resolution' => $label,
                'fps'        => $fps,
                'bitrate_kbps' => $bitrate > 0 ? round($bitrate / 1000) : null,
                'hdr'        => $hdr,
            ];
        }

        if ($audio) {
            $audioCodec    = strtoupper($audio['codec_name'] ?? '');
            $channels      = (int)($audio['channels'] ?? 0);
            $channelLayout = $audio['channel_layout'] ?? '';
            $sampleRate    = !empty($audio['sample_rate']) ? ((int)$audio['sample_rate'] / 1000) . ' kHz' : '';

            // Kanal-Label
            $chLabel = '';
            if ($channelLayout) {
                $chLabel = $channelLayout;
            } elseif ($channels === 8) {
                $chLabel = '7.1';
            } elseif ($channels === 6) {
                $chLabel = '5.1';
            } elseif ($channels === 2) {
                $chLabel = 'Stereo';
            } elseif ($channels === 1) {
                $chLabel = 'Mono';
            } elseif ($channels > 0) {
                $chLabel = $channels . 'ch';
            }

            $result['audio'] = [
                'codec'       => $audioCodec,
                'channels'    => $channels,
                'layout'      => $chLabel,
                'sample_rate' => $sampleRate,
            ];
        }

        // Gesamtdauer
        if (!empty($probe['format']['duration'])) {
            $dur = (int)$probe['format']['duration'];
            $result['duration'] = sprintf('%d:%02d:%02d', $dur / 3600, ($dur % 3600) / 60, $dur % 60);
        }

        echo json_encode(['ok' => true] + $result);
        break;

    case 'tmdb_info':
        require_permission('browse');
        $d     = $_GET + (json_decode($_RAW_BODY, true) ?? []);
        $title = trim($d['title'] ?? '');
        $type  = $d['type'] ?? 'movie';
        $year  = trim($d['year']  ?? '');
        if ($title === '') { echo json_encode(['error' => 'Missing title']); break; }
        if (TMDB_API_KEY === '') { echo json_encode(['error' => 'TMDB API-Key nicht konfiguriert']); break; }

        // ── Titel bereinigen ──────────────────────────────────────────────────
        // 1. Alle führenden Unicode-Sonderzeichen entfernen (z.B. ┃ U+2503)
        $title = preg_replace('/^[^\p{L}\p{N}]+/u', '', $title);
        // 2. Länderkürzel + Trennzeichen am Anfang entfernen (DE, US, DACH, ┃DE┃ etc.)
        $title = preg_replace('/^[A-Z]{2,4}[^\p{L}\p{N}]*/u', '', $title);
        // 3. Nochmal führende Sonderzeichen bereinigen
        $title = preg_replace('/^[^\p{L}\p{N}]+/u', '', $title);
        // 4. Jahr extrahieren — aus "(1966)", "- 2026" oder " 2026" am Ende
        if ($year === '') {
            if (preg_match('/\(((19|20)\d{2})\)\s*$/', $title, $ym)) {
                $year = $ym[1];
            } elseif (preg_match('/\b((19|20)\d{2})\b/', $title, $ym)) {
                $year = $ym[1];
            }
        }
        // 5. Jahr aus dem Titel entfernen — "(1966)", "- 2026", " 2026" am Ende
        $title = preg_replace('/\s*\((?:19|20)\d{2}\)\s*$/', '', $title);
        $title = preg_replace('/\s*[-–]\s*(?:19|20)\d{2}\s*$/', '', $title);
        $title = preg_replace('/\s+(?:19|20)\d{2}\s*$/', '', $title);
        // 6. Trailing Sonderzeichen/Leerzeichen bereinigen
        $title = trim($title, " -–·|");
        $title = trim($title);

        if ($title === '') { echo json_encode(['error' => 'Titel nach Bereinigung leer']); break; }

        $apiKey   = TMDB_API_KEY;
        $endpoint = $type === 'series' ? 'tv' : 'movie';
        $query    = urlencode($title);
        $yearParam = $year ? ($type === 'series' ? "&first_air_date_year={$year}" : "&year={$year}") : '';
        $searchUrl = "https://api.themoviedb.org/3/search/{$endpoint}?api_key={$apiKey}&query={$query}&language=de-DE{$yearParam}";

        $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: XtreamVault/1.0\r\n"]]);
        $raw = @file_get_contents($searchUrl, false, $ctx);

        // Fallback: ohne Jahr suchen falls keine Ergebnisse
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (empty($data['results']) && $yearParam !== '') {
                $raw = @file_get_contents(
                    "https://api.themoviedb.org/3/search/{$endpoint}?api_key={$apiKey}&query={$query}&language=de-DE",
                    false, $ctx
                );
                $data = $raw ? (json_decode($raw, true) ?? []) : [];
            }
        }

        if ($raw === false) { echo json_encode(['error' => 'TMDB nicht erreichbar']); break; }
        $data = $data ?? json_decode($raw, true);
        if (empty($data['results'])) { echo json_encode(['found' => false]); break; }

        $r      = $data['results'][0];
        $tmdbId = $r['id'];

        // Details abrufen
        $detailUrl = "https://api.themoviedb.org/3/{$endpoint}/{$tmdbId}?api_key={$apiKey}&language=de-DE";
        $detailRaw = @file_get_contents($detailUrl, false, $ctx);
        $detail    = $detailRaw ? (json_decode($detailRaw, true) ?? $r) : $r;

        $poster = !empty($detail['poster_path'])
            ? 'https://image.tmdb.org/t/p/w500' . $detail['poster_path']
            : (!empty($r['poster_path']) ? 'https://image.tmdb.org/t/p/w500' . $r['poster_path'] : null);

        $backdrop = !empty($detail['backdrop_path'])
            ? 'https://image.tmdb.org/t/p/w780' . $detail['backdrop_path']
            : null;

        echo json_encode([
            'found'       => true,
            'title'       => $detail['title'] ?? $detail['name'] ?? $r['title'] ?? $r['name'] ?? $title,
            'overview'    => $detail['overview'] ?? $r['overview'] ?? '',
            'rating'      => round((float)($detail['vote_average'] ?? $r['vote_average'] ?? 0), 1),
            'vote_count'  => (int)($detail['vote_count'] ?? $r['vote_count'] ?? 0),
            'release'     => $detail['release_date'] ?? $detail['first_air_date'] ?? $r['release_date'] ?? $r['first_air_date'] ?? '',
            'runtime'     => $detail['runtime'] ?? ($detail['episode_run_time'][0] ?? null),
            'genres'      => array_column($detail['genres'] ?? [], 'name'),
            'poster'      => $poster,
            'backdrop'    => $backdrop,
            'tmdb_id'     => $tmdbId,
            'tmdb_url'    => "https://www.themoviedb.org/{$endpoint}/{$tmdbId}",
        ]);
        break;

    case 'get_my_ip':
        require_permission('settings');
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
            : ($_SERVER['REMOTE_ADDR'] ?? '');
        echo json_encode(['ip' => $ip]);
        break;

    case 'health':
        $configured = is_configured();
        $queue      = $configured ? load_queue() : [];
        $pending    = count(array_filter($queue, fn($q) => $q['status'] === 'pending'));
        $downloading = count(array_filter($queue, fn($q) => $q['status'] === 'downloading'));
        $errors     = count(array_filter($queue, fn($q) => $q['status'] === 'error'));

        // Cron-Worker Status
        $lockDir    = sys_get_temp_dir() . '/xtream_cron.lockdir';
        $cronPid    = file_exists($lockDir . '/pid') ? (int)file_get_contents($lockDir . '/pid') : 0;
        $cronAlive  = $cronPid > 0 && (function_exists('posix_kill') ? posix_kill($cronPid, 0) : file_exists('/proc/' . $cronPid));

        // Speicherplatz
        $disk = null;
        if (!RCLONE_ENABLED && DEST_PATH !== '' && is_dir(DEST_PATH)) {
            $free  = disk_free_space(DEST_PATH);
            $total = disk_total_space(DEST_PATH);
            if ($free !== false) $disk = ['free_bytes' => $free, 'total_bytes' => $total, 'free_pct' => round($free / $total * 100, 1)];
        }

        // Wartungsmodus
        $maintenance = file_exists(MAINTENANCE_FILE);

        // Letztes Backup
        $backupDir  = DATA_DIR . '/backups';
        $lastBackup = null;
        if (is_dir($backupDir)) {
            $files = glob($backupDir . '/backup_*.zip');
            if ($files) {
                usort($files, fn($a,$b) => filemtime($b) - filemtime($a));
                $lastBackup = date('Y-m-d H:i:s', filemtime($files[0]));
            }
        }

        $status = $configured ? 'ok' : 'unconfigured';
        if ($maintenance) $status = 'maintenance';
        http_response_code($configured && !$maintenance ? 200 : 503);

        echo json_encode([
            'status'      => $status,
            'version'     => '1.0',
            'timestamp'   => date('Y-m-d H:i:s'),
            'configured'  => $configured,
            'maintenance' => $maintenance,
            'queue'       => ['pending' => $pending, 'downloading' => $downloading, 'errors' => $errors],
            'cron'        => ['running' => $cronAlive, 'pid' => $cronPid ?: null],
            'disk'        => $disk,
            'last_backup' => $lastBackup,
        ]);
        break;

    // ── Auth ──────────────────────────────────────────────────────────────────
    case 'setup_status':
        echo json_encode(['needs_setup' => !users_exist()]);
        break;

    case 'login':
        $d      = json_decode($_RAW_BODY, true) ?? [];
        $result = attempt_login(trim($d['username'] ?? ''), $d['password'] ?? '');
        if ($result === 'suspended') {
            http_response_code(403);
            echo json_encode(['error' => 'Konto gesperrt. Bitte Administrator kontaktieren.']);
        } elseif ($result) {
            echo json_encode(['ok' => true, 'username' => $result['username'], 'role' => $result['role']]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Ungültiger Benutzername oder Passwort']);
        }
        break;

    case 'logout':
        logout();
        echo json_encode(['ok' => true]);
        break;

    case 'me':
        echo json_encode([
            'username' => $current_user['username'],
            'role'     => $current_user['role'],
            'id'       => $current_user['id'],
        ]);
        break;

    // ── User Management (admin only) ──────────────────────────────────────────
    case 'list_users':
        require_permission('users');
        $users = array_map(fn($u) => [
            'id'          => $u['id'],
            'username'    => $u['username'],
            'role'        => $u['role'],
            'suspended'   => $u['suspended'] ?? false,
            'created_at'  => $u['created_at'],
            'last_login'  => $u['last_login'],
            'queue_limit' => $u['queue_limit'] ?? '',
        ], load_users());
        echo json_encode($users);
        break;

    case 'create_user':
        require_permission('users');
        $d      = json_decode($_RAW_BODY, true) ?? [];
        $result = create_user(trim($d['username'] ?? ''), $d['password'] ?? '', $d['role'] ?? 'viewer');
        if (is_string($result)) {
            http_response_code(400);
            echo json_encode(['error' => $result]);
        } else {
            log_activity($current_user['id'], $current_user['username'], 'create_user', ['target' => $result['username'], 'role' => $result['role']]);
            echo json_encode(['ok' => true, 'id' => $result['id']]);
        }
        break;

    case 'user_download_history':
        require_permission('users');
        $username = trim($_GET['username'] ?? '');
        if ($username === '') { echo json_encode(['error' => 'Missing username']); break; }
        $history = load_all_history();
        echo json_encode([
            'username' => $username,
            'count'    => count($filtered),
            'total_bytes' => array_sum(array_column($filtered, 'bytes')),
            'items'    => array_slice($filtered, 0, 100), // max 100
        ]);
        break;

    case 'update_user':
        require_permission('users');
        $d  = json_decode($_RAW_BODY, true) ?? [];
        $id = $d['id'] ?? '';
        if (!empty($d['password'])) {
            $r = admin_reset_password($id, $d['password']);
            if (is_string($r)) { http_response_code(400); echo json_encode(['error' => $r]); break; }
            $target = find_user_by_id($id);
            log_activity($current_user['id'], $current_user['username'], 'reset_password', ['target' => $target['username'] ?? $id]);
        }
        if (!empty($d['role'])) {
            $r = update_user_role($id, $d['role']);
            if (is_string($r)) { http_response_code(400); echo json_encode(['error' => $r]); break; }
            $target = find_user_by_id($id);
            log_activity($current_user['id'], $current_user['username'], 'change_role', ['target' => $target['username'] ?? $id, 'role' => $d['role']]);
        }
        echo json_encode(['ok' => true]);
        break;

    case 'set_user_limit':
        require_permission('users');
        $d     = json_decode($_RAW_BODY, true) ?? [];
        $id    = $d['id'] ?? '';
        $limit = $d['queue_limit'] ?? ''; // '' = Rollen-Standard, '0' = gesperrt, '5' = 5/h
        $users = load_users();
        $found = false;
        foreach ($users as &$u) {
            if ($u['id'] !== $id) continue;
            if ($limit === '' || $limit === null) unset($u['queue_limit']);
            else $u['queue_limit'] = max(0, (int)$limit);
            $found = true; break;
        }
        unset($u);
        if (!$found) { http_response_code(404); echo json_encode(['error' => 'User nicht gefunden']); break; }
        save_users($users);
        echo json_encode(['ok' => true]);
        break;

    case 'suspend_user':
        require_permission('users');
        $d         = json_decode($_RAW_BODY, true) ?? [];
        $id        = $d['id'] ?? '';
        $suspended = (bool)($d['suspended'] ?? true);
        if ($id === $current_user['id']) {
            http_response_code(400); echo json_encode(['error' => 'Du kannst dich nicht selbst sperren']); break;
        }
        $r = set_user_suspended($id, $suspended);
        if (is_string($r)) { http_response_code(400); echo json_encode(['error' => $r]); break; }
        $target = find_user_by_id($id);
        log_activity($current_user['id'], $current_user['username'], $suspended ? 'suspend_user' : 'unsuspend_user', ['target' => $target['username'] ?? $id]);
        echo json_encode(['ok' => true]);
        break;

    case 'delete_user':
        require_permission('users');
        $id = json_decode($_RAW_BODY, true)['id'] ?? '';
        if ($id === $current_user['id']) {
            http_response_code(400); echo json_encode(['error' => 'Du kannst dich nicht selbst löschen']); break;
        }
        $target = find_user_by_id($id);
        $r = delete_user($id);
        if (is_string($r)) { http_response_code(400); echo json_encode(['error' => $r]); break; }
        log_activity($current_user['id'], $current_user['username'], 'delete_user', ['target' => $target['username'] ?? $id]);
        echo json_encode(['ok' => true]);
        break;

    case 'set_language':
        require_permission('browse');
        $lang = preg_replace('/[^a-z]/', '', trim($_JSON_BODY['lang'] ?? 'de'));
        $allowed = ['de', 'en'];
        if (!in_array($lang, $allowed)) { echo json_encode(['error' => 'Invalid language']); break; }
        $_SESSION['lang'] = $lang;
        // In users.json speichern
        $users = load_users();
        foreach ($users as &$u) {
            if ($u['id'] === $current_user['id']) { $u['lang'] = $lang; break; }
        }
        save_users($users);
        echo json_encode(['ok' => true, 'lang' => $lang]);
        break;

    case 'change_own_password':
        $d   = json_decode($_RAW_BODY, true) ?? [];
        $old = $d['old_password'] ?? '';
        $new = $d['new_password'] ?? '';
        if (!password_verify($old, $current_user['password'])) {
            http_response_code(400); echo json_encode(['error' => 'Aktuelles Passwort falsch']); break;
        }
        $r = admin_reset_password($current_user['id'], $new);
        if (is_string($r)) { http_response_code(400); echo json_encode(['error' => $r]); break; }
        log_activity($current_user['id'], $current_user['username'], 'change_own_password', []);
        echo json_encode(['ok' => true]);
        break;

    case 'get_activity_log':
        require_permission('users');
        $uid   = $_GET['user_id'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 100), 500);
        echo json_encode(get_activity_log($uid, $limit));
        break;

    case 'get_config':
        require_permission('settings');
        $c = load_config();
        echo json_encode([
            'dest_path'              => $c['dest_path']              ?? '',
            'configured'             => is_configured(),
            'rclone_enabled'         => (bool)($c['rclone_enabled']  ?? false),
            'rclone_remote'          => $c['rclone_remote']          ?? '',
            'rclone_path'            => $c['rclone_path']            ?? '',
            'rclone_bin'             => $c['rclone_bin']             ?? 'rclone',
            'editor_movies_enabled'  => (bool)($c['editor_movies_enabled']  ?? true),
            'editor_series_enabled'  => (bool)($c['editor_series_enabled']  ?? true),
            'tmdb_api_key'           => isset($c['tmdb_api_key']) && $c['tmdb_api_key'] !== '' ? '••••••••' : '',
            'telegram_bot_token'     => isset($c['telegram_bot_token']) && $c['telegram_bot_token'] !== '' ? '••••••••' : '',
            'telegram_chat_id'       => $c['telegram_chat_id']       ?? '',
            'telegram_enabled'       => (bool)($c['telegram_enabled'] ?? false),
            'tg_notify_success'      => (bool)($c['tg_notify_success']    ?? true),
            'tg_notify_error'        => (bool)($c['tg_notify_error']      ?? true),
            'tg_notify_queue_done'   => (bool)($c['tg_notify_queue_done'] ?? false),
            'tg_notify_disk_low'     => (bool)($c['tg_notify_disk_low']   ?? false),
            'tg_disk_low_gb'         => (float)($c['tg_disk_low_gb']      ?? 10),
            'vpn_enabled'            => (bool)($c['vpn_enabled']    ?? false),
            'vpn_interface'          => $c['vpn_interface']          ?? 'wg0',
            'parallel_enabled'       => (bool)($c['parallel_enabled'] ?? true),
            'parallel_max'           => (int)($c['parallel_max']      ?? 4),
            'api_allowed_ips'        => $c['api_allowed_ips']         ?? '',
        ]);
        break;

    case 'test_server':
        require_permission('settings');
        $srvId = trim($_JSON_BODY['server_id'] ?? '');
        $allSrv = load_all_servers();
        $srv = null;
        foreach ($allSrv as $s) { if ($s['id'] === $srvId) { $srv = $s; break; } }
        if (!$srv && !empty($allSrv)) $srv = $allSrv[0];
        if (!$srv) { echo json_encode(['error' => 'Server nicht gefunden']); break; }
        $testIp   = $srv['server_ip']; $testPort = $srv['port'];
        $testUser = $srv['username'];  $testPass = $srv['password'] ?? '';
        $params  = http_build_query(['username' => $testUser, 'password' => $testPass, 'action' => 'get_vod_categories']);
        $testUrl = 'http://' . $testIp . ':' . $testPort . '/player_api.php?' . $params;
        $ctx     = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
        $raw     = @file_get_contents($testUrl, false, $ctx);
        if ($raw === false) { echo json_encode(['ok' => false, 'error' => 'Nicht erreichbar']); break; }
        $json = json_decode($raw, true);
        if (!is_array($json)) { echo json_encode(['ok' => false, 'error' => 'Falsche Zugangsdaten']); break; }
        echo json_encode(['ok' => true, 'categories' => count($json)]);
        break;

    case 'save_config':
        require_permission('settings');
        $d = json_decode($_RAW_BODY, true) ?? [];
        $current = load_config();

        $new = [
            'dest_path'             => trim($d['dest_path']       ?? $current['dest_path']     ?? ''),
            'rclone_enabled'        => isset($d['rclone_enabled'])       ? (bool)$d['rclone_enabled']       : (bool)($current['rclone_enabled']       ?? false),
            'rclone_remote'         => trim($d['rclone_remote']  ?? $current['rclone_remote']  ?? ''),
            'rclone_path'           => trim($d['rclone_path']    ?? $current['rclone_path']    ?? ''),
            'rclone_bin'            => trim($d['rclone_bin']     ?? $current['rclone_bin']     ?? 'rclone'),
            'editor_movies_enabled' => isset($d['editor_movies_enabled']) ? (bool)$d['editor_movies_enabled'] : (bool)($current['editor_movies_enabled'] ?? true),
            'editor_series_enabled' => isset($d['editor_series_enabled']) ? (bool)$d['editor_series_enabled'] : (bool)($current['editor_series_enabled'] ?? true),
            'tmdb_api_key'          => trim($d['tmdb_api_key'] ?? '') !== '' ? trim($d['tmdb_api_key']) : ($current['tmdb_api_key'] ?? ''),
            'telegram_bot_token'    => trim($d['telegram_bot_token'] ?? '') !== '' ? trim($d['telegram_bot_token']) : ($current['telegram_bot_token'] ?? ''),
            'telegram_chat_id'      => trim($d['telegram_chat_id'] ?? $current['telegram_chat_id'] ?? ''),
            'telegram_enabled'      => (bool)($d['telegram_enabled'] ?? $current['telegram_enabled'] ?? false),
            'tg_notify_success'     => (bool)($d['tg_notify_success']    ?? $current['tg_notify_success']    ?? true),
            'tg_notify_error'       => (bool)($d['tg_notify_error']      ?? $current['tg_notify_error']      ?? true),
            'tg_notify_queue_done'  => (bool)($d['tg_notify_queue_done'] ?? $current['tg_notify_queue_done'] ?? false),
            'tg_notify_disk_low'    => (bool)($d['tg_notify_disk_low']   ?? $current['tg_notify_disk_low']   ?? false),
            'tg_disk_low_gb'        => (float)($d['tg_disk_low_gb']      ?? $current['tg_disk_low_gb']       ?? 10),
            'vpn_enabled'           => (bool)($d['vpn_enabled']   ?? $current['vpn_enabled']   ?? false),
            'vpn_interface'         => preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($d['vpn_interface'] ?? $current['vpn_interface'] ?? 'wg0')),
            'parallel_enabled'      => isset($d['parallel_enabled']) ? (bool)$d['parallel_enabled'] : (bool)($current['parallel_enabled'] ?? true),
            'parallel_max'          => max(1, min(10, (int)($d['parallel_max'] ?? $current['parallel_max'] ?? 4))),
            'api_allowed_ips'       => trim($d['api_allowed_ips'] ?? $current['api_allowed_ips'] ?? ''),
            // Alte Felder für Rückwärtskompatibilität beibehalten falls vorhanden
            'server_ip'             => $current['server_ip'] ?? '',
            'port'                  => $current['port']      ?? '80',
            'username'              => $current['username']  ?? '',
            'password'              => $current['password']  ?? '',
        ];

        // Validierung: mindestens ein Server in servers.json
        $srvList = file_exists(SERVERS_FILE) ? (json_decode(file_get_contents(SERVERS_FILE), true) ?? []) : [];
        if (empty($srvList) && ($new['server_ip'] ?? '') === '') {
            echo json_encode(['error' => 'Bitte zuerst einen Server hinzufügen']);
            break;
        }

        if (save_config($new)) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => 'Konnte config.json nicht schreiben – Berechtigungen prüfen']);
        }
        break;

    // ── Server-Verwaltung ──────────────────────────────────────────────────────
    // ── Einladungslinks ───────────────────────────────────────────────────────
    case 'create_invite':
        require_permission('users');
        $d       = json_decode($_RAW_BODY, true) ?? [];
        $role    = in_array($d['role'] ?? '', ['viewer','editor','admin']) ? $d['role'] : 'viewer';
        $hours   = max(1, min(168, (int)($d['expires_hours'] ?? 24))); // 1h–7d, default 24h
        $note    = trim($d['note'] ?? '');
        $token   = bin2hex(random_bytes(24)); // 48-Zeichen Token
        $invites = file_exists(INVITES_FILE)
            ? (json_decode(file_get_contents(INVITES_FILE), true) ?? [])
            : [];
        $invites[$token] = [
            'token'      => $token,
            'role'       => $role,
            'note'       => $note,
            'created_by' => $current_user['username'],
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + $hours * 3600),
            'used'       => false,
            'used_by'    => null,
        ];
        file_put_contents(INVITES_FILE, json_encode($invites, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        log_activity($current_user['id'], $current_user['username'], 'create_invite', ['role' => $role, 'expires_hours' => $hours]);
        echo json_encode(['ok' => true, 'token' => $token]);
        break;

    case 'list_invites':
        require_permission('users');
        $invites = file_exists(INVITES_FILE)
            ? (json_decode(file_get_contents(INVITES_FILE), true) ?? [])
            : [];
        // Abgelaufene markieren
        $now = date('Y-m-d H:i:s');
        foreach ($invites as &$inv) {
            $inv['expired'] = $inv['expires_at'] < $now;
        }
        unset($inv);
        echo json_encode(array_values($invites));
        break;

    case 'delete_invite':
        require_permission('users');
        $d     = json_decode($_RAW_BODY, true) ?? [];
        $token = $d['token'] ?? '';
        $invites = file_exists(INVITES_FILE)
            ? (json_decode(file_get_contents(INVITES_FILE), true) ?? [])
            : [];
        if (!isset($invites[$token])) { echo json_encode(['error' => 'Nicht gefunden']); break; }
        unset($invites[$token]);
        file_put_contents(INVITES_FILE, json_encode($invites, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]);
        break;

    case 'list_servers':
        require_permission('settings');
        $servers = file_exists(SERVERS_FILE)
            ? (json_decode(file_get_contents(SERVERS_FILE), true) ?? [])
            : [];
        foreach ($servers as &$s) {
            $s['has_cache'] = file_exists(DATA_DIR . '/library_cache_' . $s['id'] . '.json');
            $s['enabled']   = ($s['enabled'] ?? true) !== false;
            unset($s['password'], $s['active']);
        }
        unset($s);
        echo json_encode(array_values($servers));
        break;

    case 'save_server':
        require_permission('settings');
        $d    = json_decode($_RAW_BODY, true) ?? [];
        $name = trim($d['name'] ?? '');
        if ($name === '') {
            echo json_encode(['error' => 'Name ist ein Pflichtfeld']); break;
        }
        // Server-ID: aus gesendeter ID oder automatisch aus IP+Port+Username berechnen
        $sid = trim($d['server_id'] ?? '');
        if ($sid === '') {
            $ip   = trim($d['server_ip'] ?? '');
            $port = trim($d['port']      ?? '80');
            $user = trim($d['username']  ?? '');
            $sid  = substr(md5($ip . ':' . $port . ':' . $user), 0, 8);
        }
        if ($sid === '') {
            echo json_encode(['error' => 'server_id konnte nicht ermittelt werden']); break;
        }
        $servers = file_exists(SERVERS_FILE)
            ? (json_decode(file_get_contents(SERVERS_FILE), true) ?? [])
            : [];
        // Existierenden Eintrag updaten oder neu anlegen
        $found = false;
        foreach ($servers as &$s) {
            if ($s['id'] === $sid) {
                $s['name']       = $name;
                $s['server_ip']  = $d['server_ip'] ?? $s['server_ip'];
                $s['port']       = $d['port']       ?? $s['port'];
                $s['username']   = $d['username']   ?? $s['username'];
                if (!empty($d['password'])) $s['password'] = $d['password'];
                $found = true; break;
            }
        }
        unset($s);
        if (!$found) {
            $servers[] = [
                'id'         => $sid,
                'name'       => $name,
                'server_ip'  => $d['server_ip']  ?? '',
                'port'       => $d['port']        ?? '80',
                'username'   => $d['username']    ?? '',
                'password'   => $d['password']    ?? '',
                'saved_at'   => date('Y-m-d H:i:s'),
            ];
        }
        file_put_contents(SERVERS_FILE, json_encode(array_values($servers), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]);
        break;

    case 'toggle_server':
        require_permission('settings');
        $d   = json_decode($_RAW_BODY, true) ?? [];
        $sid = trim($d['server_id'] ?? '');
        if ($sid === '') { echo json_encode(['error' => 'server_id fehlt']); break; }
        $servers = file_exists(SERVERS_FILE)
            ? (json_decode(file_get_contents(SERVERS_FILE), true) ?? [])
            : [];
        $found = false;
        foreach ($servers as &$s) {
            if ($s['id'] === $sid) {
                $s['enabled'] = !($s['enabled'] ?? true);
                $found = true; break;
            }
        }
        unset($s);
        if (!$found) { echo json_encode(['error' => 'Server nicht gefunden']); break; }
        file_put_contents(SERVERS_FILE, json_encode(array_values($servers), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true, 'enabled' => $servers[array_search($sid, array_column($servers, 'id'))]['enabled'] ?? true]);
        break;

    case 'delete_server':
        require_permission('settings');
        $d   = json_decode($_RAW_BODY, true) ?? [];
        $sid = trim($d['server_id'] ?? '');
        if ($sid === '') { echo json_encode(['error' => 'server_id fehlt']); break; }

        // Prüfen ob ein Download auf diesem Server gerade läuft
        $srvQueueFile = DATA_DIR . '/queue_' . $sid . '.json';
        if (file_exists($srvQueueFile)) {
            $srvQueue = json_decode(file_get_contents($srvQueueFile), true) ?? [];
            foreach ($srvQueue as $qi) {
                if (($qi['status'] ?? '') === 'downloading') {
                    echo json_encode(['error' => 'Ein Download auf diesem Server läuft gerade noch. Bitte warte bis er abgeschlossen ist.']);
                    break 2;
                }
            }
        }

        $servers = file_exists(SERVERS_FILE)
            ? (json_decode(file_get_contents(SERVERS_FILE), true) ?? [])
            : [];
        $servers = array_values(array_filter($servers, fn($s) => $s['id'] !== $sid));
        file_put_contents(SERVERS_FILE, json_encode($servers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Server-spezifische Dateien löschen
        $filesToDelete = [
            DATA_DIR . '/queue_'            . $sid . '.json',
            DATA_DIR . '/downloaded_'       . $sid . '.json',
            DATA_DIR . '/downloaded_index_' . $sid . '.json',
            DATA_DIR . '/download_history_' . $sid . '.json',
            DATA_DIR . '/library_cache_'    . $sid . '.json',
            DATA_DIR . '/series_cache_'     . $sid . '.json',
        ];
        $deleted = [];
        foreach ($filesToDelete as $f) {
            if (file_exists($f)) { @unlink($f); $deleted[] = basename($f); }
        }

        echo json_encode(['ok' => true, 'deleted_files' => $deleted]);
        break;

    // ── VPN ───────────────────────────────────────────────────────────────────
    // ── Updates ───────────────────────────────────────────────────────────────
    case 'check_update':
        require_permission('settings');
        $versionFile = __DIR__ . '/version.json';
        $local       = file_exists($versionFile)
            ? (json_decode(file_get_contents($versionFile), true) ?? [])
            : [];
        $localCommit = $local['commit'] ?? 'unknown';

        // GitHub API: neuester Commit auf main
        $apiUrl = 'https://api.github.com/repos/extend110/xtream-vault/commits/main';
        $ctx    = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: XtreamVault/1.0\r\nAccept: application/vnd.github.v3+json\r\n",
            'timeout' => 8,
        ]]);
        $raw = @file_get_contents($apiUrl, false, $ctx);
        if ($raw === false) {
            echo json_encode(['error' => 'GitHub nicht erreichbar']); break;
        }
        $remote      = json_decode($raw, true);
        $remoteHash  = $remote['sha'] ?? '';
        $remoteMsg   = $remote['commit']['message'] ?? '';
        $remoteDate  = $remote['commit']['author']['date'] ?? '';
        $remoteShort = substr($remoteHash, 0, 7);
        $localShort  = substr($localCommit, 0, 7);
        $upToDate    = $localCommit !== 'unknown' && str_starts_with($remoteHash, $localShort);

        echo json_encode([
            'local_commit'   => $localShort ?: 'unbekannt',
            'remote_commit'  => $remoteShort,
            'up_to_date'     => $upToDate,
            'remote_message' => trim(explode("\n", $remoteMsg)[0]),
            'remote_date'    => $remoteDate ? date('d.m.Y H:i', strtotime($remoteDate)) : '',
            'git_available'  => true, // nicht mehr relevant, Feld bleibt für Kompatibilität
        ]);
        break;

    case 'run_update':
        require_permission('settings');
        $dir    = __DIR__;
        $tmpDir = sys_get_temp_dir() . '/xtream_update_' . time();

        // ── Backup von data/ (ohne backups/ selbst) ───────────────────────────
        $backupDir = $dir . '/data/backups';
        @mkdir($backupDir, 0775, true);

        exec('which zip 2>/dev/null', $zw, $zc);
        if ($zc === 0) {
            $backupFile = $backupDir . '/pre_update_' . date('Y-m-d_H-i-s') . '.zip';
            // -x um backups/ auszuschließen
            exec('zip -r ' . escapeshellarg($backupFile)
                . ' ' . escapeshellarg($dir . '/data')
                . ' -x "' . $dir . '/data/backups/*"'
                . ' 2>&1', $bkpOut, $bkpRet);
        } else {
            $backupFile = $backupDir . '/pre_update_' . date('Y-m-d_H-i-s') . '.tar.gz';
            exec('tar -czf ' . escapeshellarg($backupFile)
                . ' -C ' . escapeshellarg($dir)
                . ' --exclude=data/backups data 2>&1', $bkpOut, $bkpRet);
        }
        if ($bkpRet !== 0) {
            echo json_encode(['error' => 'Backup fehlgeschlagen: ' . implode(' ', $bkpOut)]); break;
        }

        // Alte pre_update-Backups aufräumen — max. 3 behalten
        $oldBackups = glob($backupDir . '/pre_update_*');
        if ($oldBackups && count($oldBackups) > 3) {
            usort($oldBackups, fn($a, $b) => filemtime($a) - filemtime($b)); // älteste zuerst
            $toDelete = array_slice($oldBackups, 0, count($oldBackups) - 3);
            foreach ($toDelete as $f) { @unlink($f); }
        }

        // ── ZIP von GitHub herunterladen ──────────────────────────────────────
        $zipUrl  = 'https://github.com/extend110/xtream-vault/archive/refs/heads/main.zip';
        $zipFile = $tmpDir . '.zip';
        $ctx     = stream_context_create(['http' => [
            'method'          => 'GET',
            'header'          => "User-Agent: XtreamVault/1.0\r\n",
            'timeout'         => 60,
            'follow_location' => true,
        ]]);
        $zipData = @file_get_contents($zipUrl, false, $ctx);
        if ($zipData === false || strlen($zipData) < 1000) {
            echo json_encode(['error' => 'ZIP-Download fehlgeschlagen — GitHub nicht erreichbar']); break;
        }
        file_put_contents($zipFile, $zipData);

        // ── ZIP entpacken ─────────────────────────────────────────────────────
        @mkdir($tmpDir, 0755, true);
        exec('unzip -q ' . escapeshellarg($zipFile) . ' -d ' . escapeshellarg($tmpDir) . ' 2>&1', $unzipOut, $unzipRet);
        @unlink($zipFile);
        if ($unzipRet !== 0) {
            exec('rm -rf ' . escapeshellarg($tmpDir));
            echo json_encode(['error' => 'Entpacken fehlgeschlagen: ' . implode(' ', $unzipOut)]); break;
        }

        // GitHub entpackt in einen Unterordner "xtream-vault-main/"
        $extractedDir = $tmpDir . '/xtream-vault-main';
        if (!is_dir($extractedDir)) {
            // Fallback: ersten Unterordner nehmen
            $dirs = glob($tmpDir . '/*/');
            $extractedDir = $dirs[0] ?? '';
        }
        if (!is_dir($extractedDir)) {
            exec('rm -rf ' . escapeshellarg($tmpDir));
            echo json_encode(['error' => 'Entpackter Ordner nicht gefunden']); break;
        }

        // ── Dateien kopieren (data/ und version.json auslassen) ───────────────
        $skipList = ['data', 'version.json', 'install.sh', 'README.md', '.gitignore'];
        $copied   = 0;
        $log      = [];
        foreach (scandir($extractedDir) as $file) {
            if ($file === '.' || $file === '..') continue;
            if (in_array($file, $skipList)) continue;
            $src  = $extractedDir . '/' . $file;
            $dest = $dir . '/' . $file;
            if (is_dir($src)) {
                exec('cp -r ' . escapeshellarg($src) . ' ' . escapeshellarg($dest) . ' 2>&1');
            } else {
                copy($src, $dest);
            }
            $log[] = $file;
            $copied++;
        }

        // ── version.json mit Remote-Commit aktualisieren ──────────────────────
        $apiUrl  = 'https://api.github.com/repos/extend110/xtream-vault/commits/main';
        $apiCtx  = stream_context_create(['http' => ['method' => 'GET', 'header' => "User-Agent: XtreamVault/1.0\r\n", 'timeout' => 8]]);
        $apiRaw  = @file_get_contents($apiUrl, false, $apiCtx);
        $newHash = 'unknown';
        if ($apiRaw) {
            $apiData = json_decode($apiRaw, true);
            $newHash = $apiData['sha'] ?? 'unknown';
        }
        file_put_contents($dir . '/version.json', json_encode([
            'commit'     => $newHash,
            'updated_at' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT));

        // Berechtigungen setzen (nach version.json damit chown es erfasst)
        exec('chmod -R 755 ' . escapeshellarg($dir));
        exec('chmod -R 775 ' . escapeshellarg($dir . '/data'));
        exec('chown -R www-data:www-data ' . escapeshellarg($dir));

        // Temporäre Dateien aufräumen
        exec('rm -rf ' . escapeshellarg($tmpDir));

        log_activity($current_user['id'], $current_user['username'], 'run_update', ['commit' => substr($newHash, 0, 7)]);
        echo json_encode([
            'ok'     => true,
            'commit' => substr($newHash, 0, 7),
            'log'    => implode("\n", $log),
            'backup' => basename($backupFile),
        ]);
        break;

    case 'vpn_status':
        require_permission('settings');
        $wgInstalled = vpn_wg_installed();
        $up          = $wgInstalled ? vpn_is_up() : false;
        $includeIp   = ($_GET['include_ip'] ?? '1') !== '0';

        // Öffentliche IP — nur wenn gewünscht
        $pubIp = '';
        if ($includeIp) {
            $raw = @file_get_contents('https://api.ipify.org?format=text',
                false, stream_context_create(['http' => ['timeout' => 5]]));
            if ($raw) $pubIp = trim($raw);
        }

        // Verbunden seit: Zeitstempel aus Datei (gesetzt beim vpn_up)
        $connectedSince = null;
        if ($up) {
            $tsFile = DATA_DIR . '/vpn_connected_at.txt';
            if (file_exists($tsFile)) {
                $ts = (int)trim(file_get_contents($tsFile));
                if ($ts > 0) $connectedSince = $ts;
            }
        }

        echo json_encode([
            'interface'      => VPN_INTERFACE,
            'up'             => $up,
            'public_ip'      => $pubIp,
            'wg_installed'   => $wgInstalled,
            'connected_since'=> $connectedSince,
        ]);
        break;

    case 'vpn_connect':
        require_permission('settings');
        $result = vpn_up();
        if ($result !== true) { echo json_encode(['error' => $result]); break; }
        log_activity($current_user['id'], $current_user['username'], 'vpn_connect', ['interface' => VPN_INTERFACE]);
        echo json_encode(['ok' => true, 'up' => true]);
        break;

    case 'vpn_disconnect':
        require_permission('settings');
        $result = vpn_down();
        if ($result !== true) { echo json_encode(['error' => $result]); break; }
        log_activity($current_user['id'], $current_user['username'], 'vpn_disconnect', ['interface' => VPN_INTERFACE]);
        echo json_encode(['ok' => true, 'up' => false]);
        break;

    case 'telegram_test':
        require_permission('settings');
        $d         = json_decode($_RAW_BODY, true) ?? [];
        $sentToken = trim($d['bot_token'] ?? '');
        $sentChat  = trim($d['chat_id']  ?? '');
        // Leerer oder maskierter Token → direkt aus config.json lesen
        $cfgRaw    = file_exists(CONFIG_FILE) ? (json_decode(file_get_contents(CONFIG_FILE), true) ?? []) : [];
        $token     = ($sentToken === '' || $sentToken === '••••••••') ? ($cfgRaw['telegram_bot_token'] ?? '') : $sentToken;
        $chatId    = $sentChat !== '' ? $sentChat : ($cfgRaw['telegram_chat_id'] ?? '');
        if ($token === '' || $chatId === '') {
            echo json_encode(['error' => 'Bot Token und Chat ID sind Pflichtfelder']); break;
        }
        // Temporär mit den übergebenen Werten testen
        $url  = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $body = json_encode(['chat_id' => $chatId, 'text' => "✅ <b>Xtream Vault</b>\n\nTest-Nachricht — Benachrichtigungen funktionieren!", 'parse_mode' => 'HTML']);
        $ctx  = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => 8]]);
        $raw  = @file_get_contents($url, false, $ctx);
        if ($raw === false) { echo json_encode(['error' => 'Telegram nicht erreichbar']); break; }
        $resp = json_decode($raw, true);
        if (!($resp['ok'] ?? false)) {
            echo json_encode(['error' => $resp['description'] ?? 'Fehler']); break;
        }
        echo json_encode(['ok' => true]);
        break;

    case 'rclone_test':
        require_permission('settings');
        $d      = json_decode($_RAW_BODY, true) ?? [];
        $bin    = trim($d['rclone_bin']    ?? 'rclone');
        $remote = trim($d['rclone_remote'] ?? '');
        if ($remote === '') { echo json_encode(['error' => 'Remote-Name fehlt']); break; }

        // Binary-Version prüfen
        exec(escapeshellcmd($bin) . ' version 2>&1', $verOut, $verRet);
        if ($verRet !== 0) {
            echo json_encode(['error' => 'rclone nicht gefunden: ' . implode(' ', $verOut)]);
            break;
        }
        $version = $verOut[0] ?? 'unbekannt';

        // Remote erreichbar?
        exec(escapeshellcmd($bin) . ' lsd ' . escapeshellarg($remote . ':') . ' 2>&1', $lsdOut, $lsdRet);
        if ($lsdRet !== 0) {
            echo json_encode(['error' => "Remote '$remote' nicht erreichbar: " . implode(' ', $lsdOut)]);
            break;
        }
        echo json_encode(['ok' => true, 'version' => $version, 'remote' => $remote]);
        break;

    // ── Categories ────────────────────────────────────────────────────────────
    case 'get_all_movie_categories':
    case 'get_all_series_categories': {
        $isMovies   = ($action === 'get_all_movie_categories');
        $catAction  = $isMovies ? 'get_vod_categories' : 'get_series_categories';
        $servers    = load_all_servers();
        $result     = [];
        foreach ($servers as $srv) {
            $srvId   = $srv['id'];
            $srvName = $srv['name'] ?? ($srv['server_ip'] . ':' . $srv['port']);
            // Cache nutzen wenn vorhanden (schnell, kein Server-Request)
            $cacheFile = $isMovies
                ? DATA_DIR . '/library_cache_' . $srvId . '.json'
                : DATA_DIR . '/series_cache_'  . $srvId . '.json';
            if (file_exists($cacheFile)) {
                $cache = json_decode(file_get_contents($cacheFile), true) ?? [];
                $seen  = [];
                $cats  = [];
                foreach ($cache as $item) {
                    $catId   = $item['category_id'] ?? '';
                    $catName = $item['category']    ?? '';
                    if ($catName === '' || $catId === '') continue;
                    if (!isset($seen[$catId])) {
                        $seen[$catId] = true;
                        $cats[] = ['category_id' => $catId, 'category_name' => $catName];
                    }
                }
                usort($cats, fn($a, $b) => strcmp($a['category_name'], $b['category_name']));
                if (!empty($cats)) {
                    $result[] = ['server_id' => $srvId, 'server_name' => $srvName, 'categories' => $cats];
                    continue;
                }
                // Kein category_id im Cache (z.B. Serien-Cache) → Fallback auf Server
            }
            // Fallback: direkt vom Server
            $cats = xtream_for_server($srv, $catAction);
            if (!is_array($cats)) continue;
            $result[] = ['server_id' => $srvId, 'server_name' => $srvName, 'categories' => $cats];
        }
        echo json_encode($result);
        break;
    }

    // ── Streams ───────────────────────────────────────────────────────────────
    case 'get_movies': {
        $catId   = $_GET['category_id'] ?? '';
        $srvId   = $_GET['server_id']   ?? '';
        $servers = load_all_servers();
        $srv = null;
        foreach ($servers as $s) { if ($s['id'] === $srvId) { $srv = $s; break; } }
        if (!$srv) $srv = $servers[0] ?? null;
        $resolvedSrvId = $srv['id'] ?? $srvId;

        $db    = load_db();
        $queue = load_queue();
        $qids  = array_map('strval', array_column($queue, 'stream_id'));

        // Cache nutzen wenn vorhanden
        $cacheFile = DATA_DIR . '/library_cache_' . $resolvedSrvId . '.json';
        $useCache  = file_exists($cacheFile);

        if ($useCache) {
            $cache = json_decode(file_get_contents($cacheFile), true) ?? [];
            // Cache muss category_id enthalten — sonst Fallback auf Server
            $cacheHasCatId = !empty($cache) && isset(reset($cache)['category_id']);
            if ($cacheHasCatId) {
                $movies = [];
                foreach ($cache as $sid => $m) {
                    if ($catId !== '' && ($m['category_id'] ?? '') !== (string)$catId) continue;
                    $sidStr = (string)($m['stream_id'] ?? $sid);
                    $movies[] = [
                        'stream_id'           => $sidStr,
                        'clean_title'         => $m['title'] ?? $m['clean_title'] ?? '',
                        'name'                => $m['title'] ?? $m['clean_title'] ?? '',
                        'stream_icon'         => $m['cover'] ?? '',
                        'category'            => $m['category'] ?? '',
                        'category_id'         => $m['category_id'] ?? '',
                        'container_extension' => $m['ext'] ?? 'mp4',
                        'year'                => $m['year']          ?? '',
                        'rating_5based'       => $m['rating_5based'] ?? '',
                        'genre'               => $m['genre']         ?? '',
                        'downloaded'          => in_array($sidStr, $db['movies']),
                        'queued'              => in_array($sidStr, $qids),
                        '_server_id'          => $resolvedSrvId,
                    ];
                }
                echo json_encode($movies);
                break;
            }
            // Cache hat kein category_id → Fallback auf Server
        }

        // Fallback: direkt vom Server laden
        $movies = $srv ? xtream_for_server($srv, 'get_vod_streams', ['category_id' => $catId]) : [];
        foreach ($movies as &$m) {
            $m['downloaded']    = in_array((string)$m['stream_id'], $db['movies']);
            $m['queued']        = in_array((string)$m['stream_id'], $qids);
            $m['clean_title']   = display_title($m['name'] ?? '');
            $m['_server_id']    = $resolvedSrvId;
            $m['year']          = $m['year']          ?? '';
            $m['rating_5based'] = $m['rating_5based']  ?? '';
            $m['genre']         = $m['genre']          ?? '';
        }
        echo json_encode($movies);
        break;
    }

    case 'get_series': {
        $catId = $_GET['category_id'] ?? '';
        $srvId = $_GET['server_id']   ?? '';
        $servers = load_all_servers();
        $srv = null;
        foreach ($servers as $s) { if ($s['id'] === $srvId) { $srv = $s; break; } }
        if (!$srv) $srv = $servers[0] ?? null;
        $list = $srv ? xtream_for_server($srv, 'get_series', ['category_id' => $catId]) : [];
        foreach ($list as &$s) {
            $s['clean_title'] = display_title($s['name'] ?? '');
            $s['_server_id']  = $srvId ?: ($srv['id'] ?? '');
        }
        echo json_encode($list);
        break;
    }

    case 'get_series_info': {
        $srvId   = $_GET['server_id'] ?? '';
        $servers = load_all_servers();
        $srv = null;
        foreach ($servers as $s) { if ($s['id'] === $srvId) { $srv = $s; break; } }
        if (!$srv) $srv = $servers[0] ?? null;
        $data     = $srv ? xtream_for_server($srv, 'get_series_info', ['series_id' => $_GET['series_id'] ?? '']) : [];
        $db       = load_db();
        $queue    = load_queue();
        $qids     = array_map('strval', array_column($queue, 'stream_id'));
        if (isset($data['episodes'])) {
            foreach ($data['episodes'] as $season => &$eps)
                foreach ($eps as &$ep) {
                    $ep['downloaded']  = in_array((string)$ep['id'], $db['episodes']);
                    $ep['queued']      = in_array((string)$ep['id'], $qids);
                    $ep['clean_title'] = display_title($ep['title'] ?? '');
                }
        }
        echo json_encode($data);
        break;
    }

    case 'search_all_servers':
        $q    = strtolower(trim($_GET['q'] ?? ''));
        $type = $_GET['type'] ?? 'movies';
        if ($q === '') { echo json_encode(['results' => [], 'source' => 'multi']); break; }

        $servers = load_all_servers();

        $allResults = [];
        $db    = load_db();
        $queue = load_queue();
        $qids  = array_map('strval', array_column($queue, 'stream_id'));

        foreach ($servers as $srv) {
            $srvId   = $srv['id'];
            $srvName = $srv['name'] ?? ($srv['server_ip'] . ':' . $srv['port']);

            // Cache für diesen Server prüfen
            $cacheFile = DATA_DIR . '/library_cache_' . $srvId . '.json';
            if ($type === 'series') $cacheFile = DATA_DIR . '/series_cache_' . $srvId . '.json';
            $useCache = file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400;

            $srvResults = [];
            if ($useCache) {
                $cache = json_decode(file_get_contents($cacheFile), true) ?? [];
                foreach ($cache as $sid => $m) {
                    $title = strtolower($m['title'] ?? $m['clean_title'] ?? $m['name'] ?? '');
                    if (!str_contains($title, $q)) continue;
                    if ($type === 'series') {
                        $srvResults[] = [
                            'series_id'   => (string)($m['series_id'] ?? $sid),
                            'stream_id'   => (string)($m['series_id'] ?? $sid),
                            'clean_title' => $m['clean_title'] ?? $m['title'] ?? '',
                            'cover'       => $m['cover'] ?? '',
                            'category'    => $m['category'] ?? '',
                            'genre'       => $m['genre'] ?? '',
                            'type'        => 'series',
                            '_server_id'  => $srvId,
                            '_server_name'=> $srvName,
                        ];
                    } else {
                        $sidStr = (string)($m['stream_id'] ?? $sid);
                        $srvResults[] = [
                            'stream_id'           => $sidStr,
                            'clean_title'         => $m['title'] ?? $m['clean_title'] ?? $m['name'] ?? '',
                            'stream_icon'         => $m['cover'] ?? $m['stream_icon'] ?? '',
                            'category'            => $m['category'] ?? '',
                            'container_extension' => $m['ext'] ?? $m['container_extension'] ?? 'mp4',
                            'downloaded'          => in_array($sidStr, $db['movies'] ?? []),
                            'queued'              => in_array($sidStr, $qids),
                            'year'                => $m['year']          ?? '',
                            'rating_5based'       => $m['rating_5based'] ?? '',
                            'genre'               => $m['genre']         ?? '',
                            '_server_id'          => $srvId,
                            '_server_name'        => $srvName,
                        ];
                    }
                }
            } else {
                // Kein Cache — direkt beim Server anfragen
                if ($type === 'series') {
                    foreach (xtream_for_server($srv, 'get_series_categories') as $cat) {
                        foreach (xtream_for_server($srv, 'get_series', ['category_id' => $cat['category_id']]) as $s) {
                            $title = display_title($s['name'] ?? '');
                            if (!str_contains(strtolower($title), $q)) continue;
                            $s['clean_title']  = $title;
                            $s['category']     = $cat['category_name'];
                            $s['_server_id']   = $srvId;
                            $s['_server_name'] = $srvName;
                            $srvResults[]      = $s;
                        }
                    }
                } else {
                    foreach (xtream_for_server($srv, 'get_vod_categories') as $cat) {
                        foreach (xtream_for_server($srv, 'get_vod_streams', ['category_id' => $cat['category_id']]) as $m) {
                            $title = display_title($m['name'] ?? '');
                            if (!str_contains(strtolower($title), $q)) continue;
                            $m['clean_title']  = $title;
                            $m['category']     = $cat['category_name'];
                            $m['downloaded']   = in_array((string)$m['stream_id'], $db['movies'] ?? []);
                            $m['queued']       = in_array((string)$m['stream_id'], $qids);
                            $m['_server_id']   = $srvId;
                            $m['_server_name'] = $srvName;
                            $srvResults[]      = $m;
                        }
                    }
                }
            }
            $allResults = array_merge($allResults, $srvResults);
        }

        echo json_encode(['results' => $allResults, 'source' => 'multi', 'server_count' => count($servers)]);
        break;

    case 'queue_add_bulk':
        // Mehrere Items auf einmal zur Queue hinzufügen
        require_permission('queue_add');
        $body  = json_decode($_RAW_BODY, true) ?? [];
        $items = $body['items'] ?? $body; // Fallback: alter Format war direkt ein Array
        if (!is_array($items) || empty($items)) { echo json_encode(['error' => 'No items']); break; }

        $queue      = load_queue();
        $qids       = array_map('strval', array_column($queue, 'stream_id'));
        $added      = 0;
        $skipped    = 0;
        $limited    = false;

        foreach ($items as $d) {
            $limitCheck = check_queue_rate_limit($current_user);
            if (!$limitCheck['allowed']) { $limited = true; break; }

            $sid      = (string)($d['stream_id'] ?? '');
            $type     = $d['type'] ?? 'movie';
            $ext      = $d['container_extension'] ?? 'mp4';
            $bulkSrvId = $d['_server_id'] ?? '';
            if ($sid === '' || in_array($sid, $qids)) { $skipped++; continue; }

            $bulkSrvList = load_all_servers();
            $bulkSrv = null;
            foreach ($bulkSrvList as $s) { if ($s['id'] === $bulkSrvId) { $bulkSrv = $s; break; } }
            if (!$bulkSrv) $bulkSrv = $bulkSrvList[0] ?? null;
            $bulkUrl       = $bulkSrv ? stream_url_for_server($bulkSrv, $type === 'episode' ? 'series' : 'movie', $sid, $ext) : '';
            $bulkQueueFile = $bulkSrv ? DATA_DIR . '/queue_' . $bulkSrv['id'] . '.json' : QUEUE_FILE;
            $bulkServerId  = $bulkSrv['id'] ?? SERVER_ID;

            $queue[] = [
                'stream_id'           => $sid,
                'type'                => $type,
                'title'               => sanitize($d['title'] ?? 'Unknown'),
                'container_extension' => $ext,
                'category'            => $d['category'] ?? '',
                'season'              => isset($d['season']) ? (int)$d['season'] : null,
                'priority'            => 2,
                'stream_url'          => $bulkUrl,
                'cover'               => $d['cover'] ?? '',
                'dest_subfolder'      => $d['dest_subfolder'] ?? ($type === 'episode' ? 'TV Shows' : 'Movies'),
                'added_at'            => date('Y-m-d H:i:s'),
                'added_by'            => $current_user['username'],
                'status'              => 'pending',
                'error'               => null,
                '_queue_file'         => $bulkQueueFile,
                '_server_id'          => $bulkServerId,
            ];
            $qids[] = $sid;
            record_queue_add($current_user);
            $added++;
        }

        if ($added > 0) {
            save_queue($queue);
            log_activity($current_user['id'], $current_user['username'], 'queue_add_bulk', ['count' => $added]);
        }

        $status = get_queue_limit_status($current_user);
        echo json_encode([
            'ok'        => true,
            'added'     => $added,
            'skipped'   => $skipped,
            'limited'   => $limited,
            'remaining' => $status['remaining'] ?? null,
        ]);
        break;


    // ─── Queue ────────────────────────────────────────────────────────────────
    case 'get_queue':
        require_permission('queue_view');
        $queue = load_queue();
        if (!can('settings')) {
            // Nicht-Admins sehen nur ihre eigenen Queue-Einträge
            $queue = array_values(array_filter($queue, fn($item) =>
                ($item['added_by'] ?? '') === $current_user['username']
            ));
            // Stream-URLs ausblenden
            $queue = array_map(function($item) {
                unset($item['stream_url']);
                return $item;
            }, $queue);
        }
        echo json_encode($queue);
        break;

    case 'queue_add':
        require_permission('queue_add');
        // Rate-Limit prüfen
        $limitCheck = check_queue_rate_limit($current_user);
        if (!$limitCheck['allowed']) {
            $mins = ceil($limitCheck['resets_in'] / 60);
            http_response_code(429);
            echo json_encode([
                'error'      => "Stundenlimit erreicht ({$limitCheck['limit']} VODs/h). Noch {$mins} Min. warten.",
                'rate_limit' => true,
                'limit'      => $limitCheck['limit'],
                'resets_in'  => $limitCheck['resets_in'],
            ]);
            break;
        }
        $d    = json_decode($_RAW_BODY, true) ?? [];
        $sid  = (string)($d['stream_id'] ?? '');
        $type = $d['type'] ?? 'movie';
        $ext  = $d['container_extension'] ?? 'mp4';
        if ($sid === '') { echo json_encode(['error'=>'Missing stream_id']); break; }
        $queue = load_queue();
        foreach ($queue as $qi) {
            if ((string)$qi['stream_id'] === $sid
                && in_array($qi['status'] ?? 'pending', ['pending', 'downloading'])) {
                echo json_encode(['ok' => true, 'already' => true, 'reason' => 'in_queue']); break 2;
            }
            // Gleicher Titel (anderer Stream-ID) — nur bei pending/downloading prüfen
            if (in_array($qi['status'] ?? 'pending', ['pending', 'downloading'])) {
                $qTitle = mb_strtolower(trim($qi['title'] ?? ''));
                $dTitle = mb_strtolower(trim($d['title'] ?? ''));
                if ($qTitle !== '' && $dTitle !== '' && $qTitle === $dTitle) {
                    echo json_encode(['ok' => true, 'already' => true, 'reason' => 'duplicate_title', 'title' => $qi['title']]); break 2;
                }
            }
        }

        // Bereits heruntergeladen?
        $db    = load_db();
        $dbKey = $type === 'episode' ? 'episodes' : 'movies';
        if (in_array($sid, $db[$dbKey] ?? [])) {
            echo json_encode(['ok' => true, 'already' => true, 'reason' => 'downloaded']);
            break;
        }

        // rclone-Cache prüfen: Dateiname schon auf Remote?
        if (RCLONE_ENABLED) {
            $rcloneCacheFile = DATA_DIR . '/rclone_cache.json';
            if (file_exists($rcloneCacheFile)) {
                $rcloneFiles = json_decode(file_get_contents($rcloneCacheFile), true) ?? [];
                $destInfo    = build_dest_path(array_merge($d, ['stream_id' => $sid, 'type' => $type]));
                if (in_array($destInfo['filename'], $rcloneFiles)) {
                    echo json_encode([
                        'ok'       => true,
                        'already'  => true,
                        'reason'   => 'on_remote',
                        'filename' => $destInfo['filename'],
                    ]);
                    break;
                }
            }
        }

        // stream_url immer serverseitig berechnen – Client-Angabe wird ignoriert
        // Stream-URL vom richtigen Server bauen
        $qServerSid = trim($_JSON_BODY['_server_id'] ?? '');
        $allServers = load_all_servers();
        $qSrv = null;
        foreach ($allServers as $s) {
            if ($s['id'] === $qServerSid) { $qSrv = $s; break; }
        }
        if (!$qSrv) $qSrv = $allServers[0] ?? null;
        $server_stream_url = $qSrv
            ? stream_url_for_server($qSrv, $type === 'episode' ? 'series' : 'movie', $sid, $ext)
            : '';

        // ── Dubletten-Erkennung (nur Movies, nur wenn nicht force_add) ─────────
        if ($type === 'movie' && empty($_JSON_BODY['force_add'])) {
            $incomingTitle = clean_title_for_dedup($d['title'] ?? '');

            if ($incomingTitle !== '') {
                $index = load_all_index();

                $dupFound = null;
                foreach ($index as $entry) {
                    $indexTitle = clean_title_for_dedup($entry['title'] ?? '');
                    if ($indexTitle === '') continue;

                    // Exakter Treffer nach Bereinigung
                    if ($indexTitle === $incomingTitle) {
                        $dupFound = $entry['title'];
                        break;
                    }

                    // Fuzzy: Levenshtein ≤ 2 (nur bei Titeln ≥ 5 Zeichen)
                    if (mb_strlen($incomingTitle) >= 5 && mb_strlen($indexTitle) >= 5) {
                        $lev = levenshtein(
                            mb_substr($incomingTitle, 0, 255),
                            mb_substr($indexTitle, 0, 255)
                        );
                        if ($lev <= 2) {
                            $dupFound = $entry['title'];
                            break;
                        }
                    }
                }

                if ($dupFound !== null) {
                    echo json_encode([
                        'ok'          => true,
                        'already'     => true,
                        'reason'      => 'duplicate',
                        'match_title' => $dupFound,
                    ]);
                    break;
                }
            }
        }

        $queueFile = $qSrv ? DATA_DIR . '/queue_' . $qSrv['id'] . '.json' : QUEUE_FILE;
        $qServerId = $qSrv['id'] ?? SERVER_ID;

        $queue[] = [
            'stream_id'           => $sid,
            'type'                => $type,
            'title'               => sanitize($d['title'] ?? 'Unknown'),
            'container_extension' => $ext,
            'category'            => $d['category'] ?? '',
            'category_original'   => $d['category_original'] ?? '',
            'season'              => isset($d['season']) ? (int)$d['season'] : null,
            'priority'            => 2,
            'stream_url'          => $server_stream_url,
            'cover'               => $d['cover'] ?? '',
            'dest_subfolder'      => $d['dest_subfolder'] ?? '',
            'added_at'            => date('Y-m-d H:i:s'),
            'added_by'            => $current_user['username'],
            'status'              => 'pending',
            'error'               => null,
            '_queue_file'         => $queueFile,
            '_server_id'          => $qServerId,
        ];
        save_queue($queue);
        record_queue_add($current_user);
        log_activity($current_user['id'], $current_user['username'], 'queue_add', ['title' => sanitize($d['title'] ?? ''), 'type' => $type]);
        $status = get_queue_limit_status($current_user);
        echo json_encode([
            'ok'        => true,
            'count'     => count($queue),
            'remaining' => $status['remaining'] ?? null,
            'limit'     => $status['limit']     ?? null,
        ]);
        break;

    case 'maintenance_enable':
        require_permission('settings');
        file_put_contents(MAINTENANCE_FILE, date('Y-m-d H:i:s'));
        log_activity($current_user['id'], $current_user['username'], 'maintenance_enable', []);
        echo json_encode(['ok' => true]);
        break;

    case 'maintenance_disable':
        require_permission('settings');
        @unlink(MAINTENANCE_FILE);
        log_activity($current_user['id'], $current_user['username'], 'maintenance_disable', []);
        echo json_encode(['ok' => true]);
        break;

    case 'maintenance_status':
        require_permission('settings');
        echo json_encode(['active' => file_exists(MAINTENANCE_FILE)]);
        break;

    case 'queue_cancel':
        require_permission('queue_remove');
        // Schreibt cancel.lock — cron.php prüft diese Datei und bricht ab
        file_put_contents(CANCEL_FILE, date('Y-m-d H:i:s'));
        log_activity($current_user['id'], $current_user['username'], 'queue_cancel', []);
        echo json_encode(['ok' => true]);
        break;

    case 'queue_limit_status':
        echo json_encode(get_queue_limit_status($current_user));
        break;

    case 'favourite_toggle':
        // Favorit hinzufügen oder entfernen
        $d    = json_decode($_RAW_BODY, true) ?? [];
        $sid  = (string)($d['stream_id'] ?? '');
        $type = $d['type'] ?? 'movie'; // 'movie' oder 'series'
        if ($sid === '') { echo json_encode(['error' => 'Missing stream_id']); break; }

        $users = load_users();
        $uid   = $current_user['id'];
        foreach ($users as &$u) {
            if ($u['id'] !== $uid) continue;
            $favs = $u['favourites'] ?? [];
            $key  = $type . ':' . $sid;
            if (isset($favs[$key])) {
                unset($favs[$key]);
                $action = 'removed';
            } else {
                $favs[$key] = [
                    'stream_id' => $sid,
                    'type'      => $type,
                    'title'     => sanitize($d['title']    ?? ''),
                    'cover'     => $d['cover']     ?? '',
                    'category'  => $d['category']  ?? '',
                    'ext'       => $d['ext']        ?? 'mp4',
                    'server_id' => $d['server_id']  ?? '',
                    'added_at'  => date('Y-m-d H:i:s'),
                ];
                $action = 'added';
            }
            $u['favourites'] = $favs;
            break;
        }
        unset($u);
        save_users($users);
        echo json_encode(['ok' => true, 'action' => $action]);
        break;

    case 'get_favourites':
        $users = load_users();
        $uid   = $current_user['id'];
        $favs  = [];
        foreach ($users as $u) {
            if ($u['id'] === $uid) { $favs = $u['favourites'] ?? []; break; }
        }
        echo json_encode(['favourites' => array_values($favs)]);
        break;



    case 'set_priority':
        // Admin-only: Priorität eines Queue-Eintrags ändern
        require_permission('queue_remove');
        $d   = json_decode($_RAW_BODY, true) ?? [];
        $sid = (string)($d['stream_id'] ?? '');
        $prio = (int)($d['priority'] ?? 2);
        if (!in_array($prio, [1, 2, 3])) { echo json_encode(['error' => 'Ungültige Priorität (1/2/3)']); break; }
        $queue = load_queue();
        $found = false;
        foreach ($queue as &$qi) {
            if ((string)$qi['stream_id'] === $sid) {
                $qi['priority'] = $prio;
                $found = true;
                break;
            }
        }
        unset($qi);
        if (!$found) { echo json_encode(['error' => 'Item nicht gefunden']); break; }
        save_queue($queue);
        echo json_encode(['ok' => true]);
        break;

    case 'queue_retry':
        // Admin-only: fehlgeschlagenes Item sofort neu einreihen
        require_permission('queue_remove');
        $sid   = (string)(json_decode($_RAW_BODY, true)['stream_id'] ?? '');
        $queue = load_queue();
        $found = false;
        foreach ($queue as &$qi) {
            if ((string)$qi['stream_id'] === $sid && $qi['status'] === 'error') {
                $qi['status']        = 'pending';
                $qi['error']         = null;
                $found = true;
                break;
            }
        }
        unset($qi);
        if (!$found) { echo json_encode(['error' => 'Item nicht gefunden oder nicht im Fehlerstatus']); break; }
        save_queue($queue);
        echo json_encode(['ok' => true]);
        break;


    case 'queue_remove':
        // Admins: dürfen alles entfernen (queue_remove)
        // Editor: darf nur eigene pending-Einträge entfernen (queue_remove_own)
        if (!can('queue_remove') && !can('queue_remove_own')) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            break;
        }
        $sid   = (string)(json_decode($_RAW_BODY, true)['stream_id'] ?? '');
        $queue = load_queue();
        $found = null;
        foreach ($queue as $qi) {
            if ((string)$qi['stream_id'] === $sid) { $found = $qi; break; }
        }
        if (!$found) { echo json_encode(['error' => 'Not found']); break; }

        // Eigentümer-Prüfung für queue_remove_own
        if (!can('queue_remove')) {
            if (($found['added_by'] ?? '') !== $current_user['username']) {
                http_response_code(403);
                echo json_encode(['error' => 'Du kannst nur deine eigenen Einträge entfernen']);
                break;
            }
            // Nur pending-Einträge dürfen entfernt werden (nicht aktive Downloads)
            if (($found['status'] ?? '') === 'downloading') {
                http_response_code(403);
                echo json_encode(['error' => 'Laufende Downloads können nicht entfernt werden']);
                break;
            }
        }

        $queue = array_values(array_filter($queue, fn($q) => (string)$q['stream_id'] !== $sid));
        save_queue($queue);
        echo json_encode(['ok' => true, 'count' => count($queue)]);
        break;

    case 'reset_download':
        // Entfernt ein Item aus downloaded.json und downloaded_index.json
        // damit es erneut zur Queue hinzugefügt werden kann
        require_permission('settings');
        $d    = json_decode($_RAW_BODY, true) ?? [];
        $sid  = (string)($d['stream_id'] ?? '');
        $type = $d['type'] ?? 'movie'; // 'movie' oder 'episode'
        if ($sid === '') { http_response_code(400); echo json_encode(['error' => 'Missing stream_id']); break; }

        // Aus allen server-spezifischen downloaded_*.json entfernen
        $dbKey   = $type === 'movie' ? 'movies' : 'episodes';
        $before  = 0;
        $removed = 0;
        foreach (load_all_servers() as $srv) {
            $db = load_db($srv['id']);
            $before += count($db[$dbKey]);
            $db[$dbKey] = array_values(array_filter($db[$dbKey], fn($id) => (string)$id !== $sid));
            $removed += $before - count($db[$dbKey]);
            save_db($db, $srv['id']);
            $before = 0;
        }
        // Fallback: alte DOWNLOAD_DB
        if (file_exists(DOWNLOAD_DB)) {
            $db = json_decode(file_get_contents(DOWNLOAD_DB), true) ?? ['movies'=>[],'episodes'=>[]];
            $db[$dbKey] = array_values(array_filter($db[$dbKey], fn($id) => (string)$id !== $sid));
            file_put_contents(DOWNLOAD_DB, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // Aus downloaded_index_*.json entfernen (alle Server)
        foreach (array_merge(
            glob(DATA_DIR . '/downloaded_index_*.json') ?: [],
            file_exists(DOWNLOADED_INDEX_FILE) ? [DOWNLOADED_INDEX_FILE] : []
        ) as $indexFile) {
            $index = json_decode(@file_get_contents($indexFile), true) ?? [];
            if (isset($index[$sid])) { unset($index[$sid]); file_put_contents($indexFile, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); }
        }

        // Aus Queue entfernen falls noch als 'done' vorhanden
        $queue = load_queue();
        $queue = array_values(array_filter($queue, fn($qi) => !((string)$qi['stream_id'] === $sid && $qi['status'] === 'done')));
        save_queue($queue);

        $removed = $before - count($db[$dbKey]);
        log_activity($current_user['id'], $current_user['username'], 'reset_download', ['stream_id' => $sid, 'type' => $type]);
        echo json_encode(['ok' => true, 'removed' => $removed > 0]);
        break;

    case 'queue_clear_done':
        require_permission('queue_clear');
        $queue = array_values(array_filter(load_queue(), fn($q) => $q['status'] !== 'done'));
        // Alle bekannten Queue-Dateien leeren und dann befüllen
        foreach (load_all_servers() as $srv) {
            $f = DATA_DIR . '/queue_' . $srv['id'] . '.json';
            if (file_exists($f)) file_put_contents($f, '[]');
        }
        if (file_exists(QUEUE_FILE)) file_put_contents(QUEUE_FILE, '[]');
        save_queue($queue); // schreibt verbleibende Items in richtige Dateien
        echo json_encode(['ok' => true]);
        break;

    case 'queue_clear_all':
        require_permission('queue_clear');
        // Alle server-spezifischen Queue-Dateien leeren
        foreach (load_all_servers() as $srv) {
            $f = DATA_DIR . '/queue_' . $srv['id'] . '.json';
            if (file_exists($f)) file_put_contents($f, '[]');
        }
        if (file_exists(QUEUE_FILE)) file_put_contents(QUEUE_FILE, '[]');
        echo json_encode(['ok' => true]);
        break;

    case 'queue_stats':
        require_permission('queue_view');
        $queue = load_queue();
        echo json_encode([
            'total'   => count($queue),
            'pending' => count(array_filter($queue, fn($q) => $q['status'] === 'pending')),
            'done'    => count(array_filter($queue, fn($q) => $q['status'] === 'done')),
            'error'   => count(array_filter($queue, fn($q) => $q['status'] === 'error')),
        ]);
        break;

    case 'cron_log':
        require_permission('cron_log');
        if (!file_exists(CRON_LOG)) { echo json_encode(['lines' => []]); break; }
        $lines = file(CRON_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        echo json_encode(['lines' => array_slice($lines, -150)]);
        break;

    case 'php_error_log':
        require_permission('settings');
        // PHP-Fehlerlog-Pfad aus ini ermitteln
        $phpLogPath = ini_get('error_log');
        if (!$phpLogPath || !file_exists($phpLogPath)) {
            // Fallback: Apache-Fehlerlog
            foreach (['/var/log/apache2/error.log', '/var/log/apache2/xtream_error.log', '/var/log/php_errors.log'] as $fallback) {
                if (file_exists($fallback) && is_readable($fallback)) { $phpLogPath = $fallback; break; }
            }
        }
        if (!$phpLogPath || !file_exists($phpLogPath)) {
            echo json_encode(['lines' => [], 'path' => null, 'error' => 'Keine Fehlerlog-Datei gefunden']); break;
        }
        if (!is_readable($phpLogPath)) {
            echo json_encode(['lines' => [], 'path' => $phpLogPath, 'error' => 'Keine Leseberechtigung']); break;
        }
        $lines = file($phpLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        // Nur Einträge die /xtream/ im Pfad enthalten (projektbezogen filtern)
        $filtered = array_values(array_filter($lines, fn($l) => stripos($l, 'xtream') !== false || stripos($l, 'PHP') !== false));
        echo json_encode(['lines' => array_slice($filtered, -100), 'path' => $phpLogPath]);
        break;

    case 'get_progress':
        require_permission('queue_view');
        // Alle progress-Dateien lesen (main + pro Server)
        $progressFiles = array_merge(
            [PROGRESS_FILE],
            glob(DATA_DIR . '/progress_*.json') ?: []
        );
        $active = [];
        foreach ($progressFiles as $pf) {
            if (!file_exists($pf)) continue;
            $p = json_decode(@file_get_contents($pf), true);
            if (!is_array($p) || empty($p)) continue;
            if (!empty($p['updated_at'])) {
                $age = time() - strtotime($p['updated_at']);
                if ($age > 10) { $p['active'] = false; $p['stale'] = true; }
            }
            if ($p['active'] ?? false) $active[] = $p;
        }
        if (empty($active)) {
            echo json_encode(['active' => false]);
        } elseif (count($active) === 1) {
            echo json_encode($active[0]);
        } else {
            // Mehrere parallele Downloads — aggregiert zurückgeben
            $totalDone  = array_sum(array_column($active, 'bytes_done'));
            $totalBytes = array_sum(array_column($active, 'bytes_total'));
            $totalSpeed = array_sum(array_column($active, 'speed_bps'));
            echo json_encode([
                'active'       => true,
                'parallel'     => count($active),
                'downloads'    => $active,
                'bytes_done'   => $totalDone,
                'bytes_total'  => $totalBytes,
                'speed_bps'    => $totalSpeed,
                'percent'      => $totalBytes > 0 ? round($totalDone / $totalBytes * 100) : 0,
                'title'        => count($active) . ' Downloads parallel',
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        }
        break;

    case 'queue_start':
        require_permission('settings');
        $lockFile     = __DIR__ . '/data/cron.lock';
        $startingLock = __DIR__ . '/data/cron_starting.lock';

        // Prüfen ob cron.php läuft — Koordinator-Lock oder Worker-Locks
        $lf = @fopen($lockFile, 'c');
        if ($lf) {
            $free = flock($lf, LOCK_EX | LOCK_NB);
            if ($free) flock($lf, LOCK_UN);
            fclose($lf);
        } else {
            $free = true;
        }
        // Auch Worker-Locks prüfen (laufen Workers noch ohne Koordinator?)
        if ($free) {
            foreach (glob(DATA_DIR . '/cron_worker_*.lock') ?: [] as $wl) {
                $wf = @fopen($wl, 'c');
                if ($wf) {
                    $wFree = flock($wf, LOCK_EX | LOCK_NB);
                    if ($wFree) flock($wf, LOCK_UN);
                    fclose($wf);
                    if (!$wFree) { $free = false; break; }
                }
            }
        }
        if (!$free) {
            echo json_encode(['error' => 'Download-Worker läuft bereits']);
            break;
        }

        // Semaphore gegen Doppelstart innerhalb 10s
        if (file_exists($startingLock) && (time() - filemtime($startingLock)) < 10) {
            echo json_encode(['error' => 'Download-Worker wird gerade gestartet']);
            break;
        }
        file_put_contents($startingLock, date('Y-m-d H:i:s'));

        $q = load_queue();
        $pending = array_filter($q, fn($qi) => $qi['status'] === 'pending');
        if (empty($pending)) {
            @unlink($startingLock);
            echo json_encode(['error' => 'Keine ausstehenden Downloads in der Queue']);
            break;
        }
        $script  = escapeshellarg(__DIR__ . '/cron.php');
        $phpBin  = escapeshellarg(PHP_BINARY ?: 'php');
        shell_exec("{$phpBin} {$script} > /dev/null 2>&1 &");
        log_activity($current_user['id'], $current_user['username'], 'queue_start', ['pending' => count($pending)]);
        echo json_encode(['ok' => true, 'pending' => count($pending)]);
        break;

    case 'backup_run':
        require_permission('settings');
        $script = escapeshellarg(__DIR__ . '/backup.php');
        $phpBin = escapeshellarg(PHP_BINARY ?: 'php');
        shell_exec("{$phpBin} {$script} > /dev/null 2>&1 &");
        log_activity($current_user['id'], $current_user['username'], 'backup_run', []);
        echo json_encode(['ok' => true]);
        break;

    case 'backup_list':
        require_permission('settings');
        $backupDir = DATA_DIR . '/backups';
        $files = is_dir($backupDir) ? glob($backupDir . '/backup_*.zip') : [];
        if ($files) usort($files, fn($a,$b) => filemtime($b) - filemtime($a));
        echo json_encode(['backups' => array_map(fn($f) => [
            'name'       => basename($f),
            'size'       => filesize($f),
            'created_at' => date('Y-m-d H:i:s', filemtime($f)),
        ], $files ?: [])]);
        break;

    case 'backup_restore':
        require_permission('settings');
        $name = basename(json_decode($_RAW_BODY, true)['name'] ?? '');
        if (!preg_match('/^backup_[\d_-]+\.zip$/', $name)) { echo json_encode(['error' => 'Ungültiger Dateiname']); break; }
        $path = DATA_DIR . '/backups/' . $name;
        if (!file_exists($path)) { echo json_encode(['error' => 'Backup nicht gefunden']); break; }
        if (!class_exists('ZipArchive')) { echo json_encode(['error' => "PHP-Extension 'zip' nicht verfügbar"]); break; }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) { echo json_encode(['error' => 'ZIP-Datei konnte nicht geöffnet werden']); break; }

        $restored = 0;
        $errors   = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            // Nur data/-Dateien wiederherstellen, backup_info.json überspringen
            if (!str_starts_with($entry, 'data/') || substr($entry, -1) === '/') continue;
            $filename = basename($entry);
            // Nur bekannte, sichere Dateien wiederherstellen
            $allowed = ['config.json','users.json','queue.json','downloaded.json',
                        'downloaded_index.json','download_history.json','library_cache.json',
                        'activity.json','rate_limits.json','api_keys.json'];
            if (!in_array($filename, $allowed)) continue;
            $content = $zip->getFromIndex($i);
            if ($content === false) { $errors[] = $filename; continue; }
            // Validieren dass es gültiges JSON ist
            if (json_decode($content) === null) { $errors[] = $filename . ' (ungültiges JSON)'; continue; }
            file_put_contents(DATA_DIR . '/' . $filename, $content);
            $restored++;
        }
        $zip->close();

        if (!empty($errors)) {
            echo json_encode(['ok' => false, 'restored' => $restored, 'errors' => $errors]);
        } else {
            log_activity($current_user['id'], $current_user['username'], 'backup_restore', ['name' => $name, 'files' => $restored]);
            echo json_encode(['ok' => true, 'restored' => $restored]);
        }
        break;

    case 'backup_delete':
        require_permission('settings');
        $name = basename(json_decode($_RAW_BODY, true)['name'] ?? '');
        if (!preg_match('/^backup_[\d_-]+\.zip$/', $name)) { echo json_encode(['error' => 'Ungültiger Dateiname']); break; }
        $path = DATA_DIR . '/backups/' . $name;
        if (!file_exists($path)) { echo json_encode(['error' => 'Datei nicht gefunden']); break; }
        @unlink($path);
        echo json_encode(['ok' => true]);
        break;

    case 'rclone_cache_refresh':
        require_permission('settings');
        if (!RCLONE_ENABLED) { echo json_encode(['error' => 'rclone nicht aktiviert']); break; }
        $cacheFile = DATA_DIR . '/rclone_cache.json';
        // Cache löschen → nächster cron.php-Lauf baut ihn neu auf
        // Oder hier direkt neu aufbauen via rclone lsf
        $cmd = escapeshellcmd(RCLONE_BIN) . ' lsf -R ' . escapeshellarg(RCLONE_REMOTE . ':' . RCLONE_PATH) . ' 2>&1';
        $out = []; $ret = 0;
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            echo json_encode(['error' => 'rclone lsf fehlgeschlagen: ' . implode(' ', array_slice($out, 0, 3))]); break;
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
        file_put_contents($cacheFile, json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo json_encode(['ok' => true, 'count' => count($files), 'cached_at' => date('Y-m-d H:i:s')]);
        break;

    case 'rclone_cache_status':
        require_permission('settings');
        $cacheFile = DATA_DIR . '/rclone_cache.json';
        if (!file_exists($cacheFile)) {
            echo json_encode(['exists' => false]); break;
        }
        $files = json_decode(file_get_contents($cacheFile), true) ?? [];
        echo json_encode([
            'exists'     => true,
            'count'      => count($files),
            'cached_at'  => date('Y-m-d H:i:s', filemtime($cacheFile)),
        ]);
        break;

    case 'rebuild_library_cache':
        require_permission('settings');
        // Altes hängendes Lock-File entfernen falls vorhanden
        $lockFile = sys_get_temp_dir() . '/xtream_cache.lock';
        if (file_exists($lockFile)) {
            $pid = (int)file_get_contents($lockFile);
            $running = $pid > 0 && (function_exists('posix_kill') ? posix_kill($pid, 0) : file_exists('/proc/' . $pid));
            if ($running) {
                echo json_encode(['error' => "Cache-Builder läuft bereits (PID {$pid})"]);
                break;
            }
            @unlink($lockFile);
        }
        $script = escapeshellarg(__DIR__ . '/cache_builder.php');
        $log    = escapeshellarg(DATA_PATH . '/cache_build.log');
        shell_exec("php {$script} > {$log} 2>&1 &");
        echo json_encode(['ok' => true, 'message' => 'Cache-Rebuild gestartet']);
        break;

    case 'cache_status':
        // Prüfen ob mindestens eine server-spezifische Cache-Datei existiert
        $serverCacheFiles = glob(DATA_DIR . '/library_cache_*.json') ?: [];
        // Fallback: alter fester Pfad für Rückwärtskompatibilität
        $movieReady  = !empty($serverCacheFiles) || file_exists(LIBRARY_CACHE_FILE);
        $newestCache = $movieReady
            ? max(array_map('filemtime', !empty($serverCacheFiles) ? $serverCacheFiles : [LIBRARY_CACHE_FILE]))
            : null;
        $indexReady  = !empty(glob(DATA_DIR . '/downloaded_index_*.json') ?: []) || file_exists(DOWNLOADED_INDEX_FILE);
        $buildLog    = DATA_PATH . '/cache_build.log';
        $lockFile    = sys_get_temp_dir() . '/xtream_cache.lock';
        $lastLine    = '';

        // Prüfen ob der Prozess wirklich noch läuft (PID-basiert)
        $building = false;
        if (file_exists($lockFile)) {
            $pid = (int)file_get_contents($lockFile);
            if ($pid > 0) {
                if (function_exists('posix_kill')) {
                    $building = posix_kill($pid, 0);
                } else {
                    $building = file_exists('/proc/' . $pid);
                }
            }
            if (!$building) @unlink($lockFile);
        }

        if (file_exists($buildLog)) {
            $lines    = file($buildLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $filtered = array_filter($lines, fn($l) => !str_contains($l, 'PHP') && !str_contains($l, 'thrown'));
            $lastLine = end($filtered) ?: end($lines) ?: '';
        }

        echo json_encode([
            'movie_cache_ready'  => $movieReady,
            'index_ready'        => $indexReady,
            'series_cache_ready' => true,
            'cache_age_min'      => $newestCache ? round((time() - $newestCache) / 60) : null,
            'building'           => $building,
            'last_message'       => $lastLine,
        ]);
        break;


    case 'stats_data':
        require_permission('settings');
        $history = load_all_history();

        // ── Echte Gesamtzahlen aus downloaded.json ────────────────────────────
        $db           = load_db();
        $totalMovies  = count($db['movies']   ?? []);
        $totalEpisodes= count($db['episodes'] ?? []);
        $totalCount   = $totalMovies + $totalEpisodes;

        // ── GB/Downloads pro Monat (aus History) ──────────────────────────────
        $byMonth = [];
        $byWeekday = array_fill(0, 7, ['count' => 0, 'bytes' => 0]); // 0=So, 1=Mo...
        foreach ($history as $h) {
            $dateStr = $h['done_at'] ?? $h['added_at'] ?? '';
            if (empty($dateStr)) continue;
            $month = substr($dateStr, 0, 7);
            if (!isset($byMonth[$month])) $byMonth[$month] = ['count' => 0, 'bytes' => 0];
            $byMonth[$month]['count']++;
            $byMonth[$month]['bytes'] += (int)($h['bytes'] ?? 0);
            // Wochentag (0=So bis 6=Sa)
            $wd = (int)date('w', strtotime($dateStr));
            $byWeekday[$wd]['count']++;
            $byWeekday[$wd]['bytes'] += (int)($h['bytes'] ?? 0);
        }
        // Letzte 12 Monate immer anzeigen
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = date('Y-m', strtotime("-{$i} months"));
            $months[$m] = $byMonth[$m] ?? ['count' => 0, 'bytes' => 0];
        }
        ksort($byMonth);
        foreach ($byMonth as $m => $v) {
            if (!isset($months[$m])) $months[$m] = $v;
        }
        ksort($months);

        // ── Top User ──────────────────────────────────────────────────────────
        $userCounts = [];
        foreach ($history as $h) {
            $u = $h['added_by'] ?? 'unknown';
            if (!isset($userCounts[$u])) $userCounts[$u] = ['count' => 0, 'bytes' => 0];
            $userCounts[$u]['count']++;
            $userCounts[$u]['bytes'] += (int)($h['bytes'] ?? 0);
        }
        arsort($userCounts);
        $topUsers = array_slice($userCounts, 0, 10, true);

        // ── Top Kategorien ────────────────────────────────────────────────────
        $catCounts = [];
        foreach ($history as $h) {
            $cat = trim($h['category'] ?? 'Unbekannt');
            if ($cat === '') $cat = 'Unbekannt';
            if (!isset($catCounts[$cat])) $catCounts[$cat] = ['count' => 0, 'bytes' => 0];
            $catCounts[$cat]['count']++;
            $catCounts[$cat]['bytes'] += (int)($h['bytes'] ?? 0);
        }
        arsort($catCounts);
        $topCategories = array_slice($catCounts, 0, 15, true);

        // ── Gesamt-Bytes aus History ──────────────────────────────────────────
        $totalBytes = array_sum(array_column($history, 'bytes'));

        // ── Aktueller Monat ───────────────────────────────────────────────────
        $currentMonth = date('Y-m');
        $thisMonth = $byMonth[$currentMonth] ?? ['count' => 0, 'bytes' => 0];

        echo json_encode([
            'by_month'       => $months,
            'by_weekday'     => $byWeekday,
            'top_users'      => $topUsers,
            'top_categories' => $topCategories,
            'total_bytes'    => $totalBytes,
            'total_count'    => $totalCount,
            'total_movies'   => $totalMovies,
            'total_episodes' => $totalEpisodes,
            'this_month'     => $thisMonth,
        ]);
        break;

    case 'stats':
        $db    = load_db();
        if (can('queue_view')) {
            $queue = load_queue();
            if (!can('settings')) {
                // Editoren/Viewer: nur eigene Einträge zählen
                $queue = array_filter($queue, fn($q) => ($q['added_by'] ?? '') === $current_user['username']);
            }
            $qStats = ['pending' => 0, 'downloading' => 0, 'done' => 0, 'error' => 0];
            foreach ($queue as $qi) { $s = $qi['status'] ?? 'pending'; if (isset($qStats[$s])) $qStats[$s]++; }
        } else {
            $qStats = null;
        }
        echo json_encode([
            'movies'           => count($db['movies']),
            'episodes'         => count($db['episodes']),
            'total_downloaded' => count($db['movies']) + count($db['episodes']),
            'queue_stats'      => $qStats,
            'downloaded_ids'   => array_map('strval', array_merge($db['movies'] ?? [], $db['episodes'] ?? [])),
            'configured'       => is_configured(),
            'can' => [
                'queue_view'   => can('queue_view'),
                'queue_add'    => can('queue_add'),
                'queue_remove' => can('queue_remove'),
                'queue_clear'  => can('queue_clear'),
                'cron_log'     => can('cron_log'),
                'settings'     => can('settings'),
                'users'        => can('users'),
            ],
        ]);
        break;

    case 'dashboard_data':
        require_permission('settings');
        $queue = load_queue();
        $db    = load_db();

        // ── Queue-Statistiken ─────────────────────────────────────────────────
        $qStats = ['pending' => 0, 'downloading' => 0, 'done' => 0, 'error' => 0];
        foreach ($queue as $qi) {
            $s = $qi['status'] ?? 'pending';
            if (isset($qStats[$s])) $qStats[$s]++;
        }

        // ── Letzte 10 Downloads ───────────────────────────────────────────────
        $recentDownloads = array_slice(load_all_history(), 0, 10);

        // ── Speicherplatz ─────────────────────────────────────────────────────
        $diskInfo = null;
        if (!RCLONE_ENABLED && DEST_PATH !== '') {
            $path = is_dir(DEST_PATH) ? DEST_PATH : dirname(DEST_PATH);
            if (is_dir($path)) {
                $free  = disk_free_space($path);
                $total = disk_total_space($path);
                if ($free !== false && $total !== false) {
                    $diskInfo = [
                        'free'       => $free,
                        'total'      => $total,
                        'used'       => $total - $free,
                        'percent'    => round(($total - $free) / $total * 100, 1),
                        'path'       => DEST_PATH,
                    ];
                }
            }
        } elseif (RCLONE_ENABLED) {
            $diskInfo = ['rclone' => true, 'remote' => RCLONE_REMOTE . ':' . RCLONE_PATH];
        }

        // ── Server-Liste ──────────────────────────────────────────────────────
        $srvList = load_all_servers();
        $queueBySrv = [];
        foreach ($queue as $qi) {
            $srvId = $qi['_server_id'] ?? 'default';
            if (!isset($queueBySrv[$srvId])) $queueBySrv[$srvId] = ['pending' => 0, 'downloading' => 0, 'error' => 0];
            $st = $qi['status'] ?? 'pending';
            if (isset($queueBySrv[$srvId][$st])) $queueBySrv[$srvId][$st]++;
        }
        $serverStatus = array_map(function($s) use ($queueBySrv) {
            $cacheFile    = DATA_DIR . '/library_cache_' . $s['id'] . '.json';
            $serCacheFile = DATA_DIR . '/series_cache_' . $s['id'] . '.json';
            $hasCache     = file_exists($cacheFile);
            $cacheAgeMin  = $hasCache ? round((time() - filemtime($cacheFile)) / 60) : null;
            $movieCount   = $hasCache ? count(json_decode(file_get_contents($cacheFile), true) ?? []) : null;
            $seriesCount  = file_exists($serCacheFile) ? count(json_decode(file_get_contents($serCacheFile), true) ?? []) : null;
            $q            = $queueBySrv[$s['id']] ?? ['pending' => 0, 'downloading' => 0, 'error' => 0];
            return [
                'id'           => $s['id'],
                'name'         => $s['name'] ?? ($s['server_ip'] . ':' . $s['port']),
                'server_ip'    => $s['server_ip'],
                'port'         => $s['port'],
                'username'     => $s['username'],
                'has_cache'    => $hasCache,
                'cache_age_min'=> $cacheAgeMin,
                'movie_count'  => $movieCount,
                'series_count' => $seriesCount,
                'queue'        => $q,
            ];
        }, $srvList);

        // ── System-Info ───────────────────────────────────────────────────────
        $memTotal = 0; $memFree = 0;
        if (file_exists('/proc/meminfo')) {
            foreach (file('/proc/meminfo') as $line) {
                if (str_starts_with($line, 'MemTotal:'))     $memTotal = (int)preg_replace('/\D/', '', $line) * 1024;
                if (str_starts_with($line, 'MemAvailable:')) $memFree  = (int)preg_replace('/\D/', '', $line) * 1024;
            }
        }
        $uptime = '';
        if (file_exists('/proc/uptime')) {
            $secs = (int)explode(' ', file_get_contents('/proc/uptime'))[0];
            $d_up = floor($secs / 86400);
            $h_up = floor(($secs % 86400) / 3600);
            $uptime = $d_up > 0 ? "{$d_up}d {$h_up}h" : "{$h_up}h " . floor(($secs % 3600) / 60) . "m";
        }
        $sysInfo = [
            'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'mem_used'    => $memTotal > 0 ? $memTotal - $memFree : 0,
            'mem_total'   => $memTotal,
            'uptime'      => $uptime,
        ];

        echo json_encode([
            'queue_stats'      => $qStats,
            'recent_downloads' => array_map(fn($q) => [
                'stream_id' => (string)($q['stream_id'] ?? $q['id'] ?? ''),
                'title'     => $q['title'],
                'type'      => $q['type']    ?? 'movie',
                'added_at'  => $q['done_at'] ?? $q['added_at'] ?? '',
                'added_by'  => $q['added_by'] ?? '',
                'cover'     => $q['cover']    ?? '',
            ], $recentDownloads),
            'disk'             => $diskInfo,
            'system'           => $sysInfo,
            'servers'          => $serverStatus,
            'total_downloaded' => count($db['movies']) + count($db['episodes']),
        ]);
        break;

    // ── Externer Endpoint: Benutzer anlegen via API-Key ───────────────────────
    case 'external_create_user':
        // Nur via API-Key erreichbar (geprüft oben)
        // Parameter aus POST-Body (JSON) oder GET-Query-String
        $d = json_decode($_RAW_BODY, true) ?? [];
        $username = trim($d['username'] ?? $_GET['username'] ?? '');
        $password = $d['password']      ?? $_GET['password'] ?? '';
        $role     = $d['role']          ?? $_GET['role']     ?? 'viewer';
        $result = create_user($username, $password, $role);
        if (is_string($result)) {
            http_response_code(400);
            echo json_encode(['error' => $result]);
        } else {
            echo json_encode([
                'ok'       => true,
                'id'       => $result['id'],
                'username' => $result['username'],
                'role'     => $result['role'],
            ]);
        }
        break;

    case 'external_list_users':
        // Gibt alle User zurück (ohne Passwort-Hashes)
        $users = array_map(fn($u) => [
            'id'         => $u['id'],
            'username'   => $u['username'],
            'role'       => $u['role'],
            'suspended'  => (bool)($u['suspended'] ?? false),
            'created_at' => $u['created_at'] ?? null,
        ], load_users());
        echo json_encode(['ok' => true, 'users' => $users, 'count' => count($users)]);
        break;

    case 'external_suspend_user':
        $d         = json_decode($_RAW_BODY, true) ?? [];
        $target    = trim($d['username'] ?? $_GET['username'] ?? '');
        $suspended = isset($d['suspended']) ? (bool)$d['suspended']
                   : (isset($_GET['suspended']) ? filter_var($_GET['suspended'], FILTER_VALIDATE_BOOLEAN) : true);
        if ($target === '') { http_response_code(400); echo json_encode(['error' => 'Missing username']); break; }
        $users  = load_users();
        $found  = false;
        foreach ($users as &$u) {
            if ($u['username'] === $target) {
                if ($u['role'] === 'admin') {
                    http_response_code(403);
                    echo json_encode(['error' => 'Admin-Accounts können nicht gesperrt werden']);
                    $found = true; break;
                }
                $u['suspended'] = $suspended;
                $found = true;
                break;
            }
        }
        unset($u);
        if (!$found) { http_response_code(404); echo json_encode(['error' => 'User nicht gefunden']); break; }
        save_users($users);
        echo json_encode(['ok' => true, 'username' => $target, 'suspended' => $suspended]);
        break;

    case 'external_delete_user':
        $d      = json_decode($_RAW_BODY, true) ?? [];
        $target = trim($d['username'] ?? $_GET['username'] ?? '');
        if ($target === '') { http_response_code(400); echo json_encode(['error' => 'Missing username']); break; }
        $users  = load_users();
        $before = count($users);
        $users  = array_values(array_filter($users, fn($u) => $u['username'] !== $target || $u['role'] === 'admin'));
        if (count($users) === $before) { http_response_code(404); echo json_encode(['error' => 'User nicht gefunden oder Admin']); break; }
        save_users($users);
        echo json_encode(['ok' => true, 'username' => $target, 'deleted' => true]);
        break;

    case 'external_update_user':
        // Passwort oder Rolle eines Users ändern
        $d      = json_decode($_RAW_BODY, true) ?? [];
        $target = trim($d['username'] ?? $_GET['username'] ?? '');
        if ($target === '') { http_response_code(400); echo json_encode(['error' => 'Missing username']); break; }
        $users = load_users();
        $found = false;
        foreach ($users as &$u) {
            if ($u['username'] !== $target) continue;
            if (!empty($d['password'])) $u['password'] = password_hash($d['password'], PASSWORD_BCRYPT);
            if (!empty($d['role']) && in_array($d['role'], ['admin','editor','viewer'])) $u['role'] = $d['role'];
            $found = true;
            break;
        }
        unset($u);
        if (!$found) { http_response_code(404); echo json_encode(['error' => 'User nicht gefunden']); break; }
        save_users($users);
        echo json_encode(['ok' => true, 'username' => $target, 'updated' => true]);
        break;

    // ── Server-Infos (Admin only) ─────────────────────────────────────────────
    case 'list_api_keys':
        require_permission('settings');
        $keys = array_map(function($k) {
            // Den eigentlichen Key nur einmal vollständig zeigen (bei Erstellung)
            // Danach nur noch die letzten 8 Zeichen
            $k['key_preview'] = '...' . substr($k['key'], -8);
            unset($k['key']); // Key nie nochmal vollständig ausgeben
            return $k;
        }, load_api_keys());
        echo json_encode($keys);
        break;

    case 'create_api_key':
        require_permission('settings');
        $d    = json_decode($_RAW_BODY, true) ?? [];
        $name = trim($d['name'] ?? 'API Key');
        $key  = create_api_key($name, $current_user['username']);
        // Einzige Gelegenheit den vollen Key zu sehen
        echo json_encode(['ok' => true, 'key' => $key]);
        break;

    case 'revoke_api_key':
        require_permission('settings');
        $id = json_decode($_RAW_BODY, true)['id'] ?? '';
        echo json_encode(['ok' => revoke_api_key($id)]);
        break;

    case 'reveal_api_key':
        require_permission('settings');
        $d        = json_decode($_RAW_BODY, true) ?? [];
        $id       = trim($d['id']       ?? '');
        $password = $d['password'] ?? '';
        if ($id === '' || $password === '') { echo json_encode(['error' => 'Fehlende Parameter']); break; }

        // Passwort des aktuell eingeloggten Admins prüfen
        $users = load_users();
        $me    = null;
        foreach ($users as $u) { if ($u['id'] === $current_user['id']) { $me = $u; break; } }
        if (!$me || !password_verify($password, $me['password'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Falsches Passwort']);
            break;
        }

        // Key aus der Liste holen
        $keys = load_api_keys();
        $found = null;
        foreach ($keys as $k) { if ($k['id'] === $id) { $found = $k; break; } }
        if (!$found || ($found['revoked'] ?? false)) {
            echo json_encode(['error' => 'API-Key nicht gefunden oder widerrufen']);
            break;
        }

        log_activity($current_user['id'], $current_user['username'], 'reveal_api_key', ['key_id' => $id]);
        echo json_encode(['ok' => true, 'key' => $found['key'], 'name' => $found['name']]);
        break;

    case 'delete_api_key':
        require_permission('settings');
        $id = json_decode($_RAW_BODY, true)['id'] ?? '';
        echo json_encode(['ok' => delete_api_key($id)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
