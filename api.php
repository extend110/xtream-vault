<?php
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
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

// ─── Nicht konfiguriert → Fehler außer bei Config-Aktionen ───────────────────
$config_actions = ['get_config', 'save_config',
    'external_create_user', 'external_list_users', 'external_suspend_user',
    'external_delete_user', 'external_update_user'];
if (!is_configured() && !in_array($action, $config_actions) && !in_array($action, $public_actions)) {
    http_response_code(503);
    echo json_encode(['error' => 'not_configured']);
    exit;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function xtream(string $action, array $extra = []): mixed {
    $params = array_merge(['username' => USERNAME, 'password' => PASSWORD, 'action' => $action], $extra);
    $url    = 'http://' . SERVER_IP . ':' . PORT . '/player_api.php?' . http_build_query($params);
    $ctx    = stream_context_create(['http' => [
        'timeout' => 30,
        'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n"
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        http_response_code(502);
        echo json_encode(['error' => 'Could not reach Xtream server']);
        exit;
    }
    return json_decode($raw, true);
}

function load_db(): array {
    if (file_exists(DOWNLOAD_DB))
        return json_decode(file_get_contents(DOWNLOAD_DB), true) ?? ['movies' => [], 'episodes' => []];
    return ['movies' => [], 'episodes' => []];
}
function save_db(array $db): void {
    @mkdir(DATA_PATH, 0755, true);
    file_put_contents(DOWNLOAD_DB, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function load_queue(): array {
    if (file_exists(QUEUE_FILE))
        return json_decode(file_get_contents(QUEUE_FILE), true) ?? [];
    return [];
}
function save_queue(array $q): void {
    @mkdir(DATA_PATH, 0755, true);
    file_put_contents(QUEUE_FILE, json_encode($q, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function sanitize(string $n): string {
    $n = str_replace(['/', '\\'], '-', $n);
    $n = preg_replace('/[<>:"|?*]/u', '', $n);
    // Unicode-safe: erlaubt Buchstaben (inkl. Umlaute), Zahlen etc.
    $n = preg_replace('/[^\p{L}\p{N}\s\-_\.]/u', '', $n);
    return trim(preg_replace('/\s+/u', ' ', $n)) ?: 'Uncategorized';
}
function stream_url(string $type, $id, string $ext): string {
    $p = $type === 'movie' ? 'movie' : 'series';
    return 'http://' . SERVER_IP . ':' . PORT . "/{$p}/" . USERNAME . '/' . PASSWORD . "/{$id}.{$ext}";
}

// ─── Router ───────────────────────────────────────────────────────────────────
switch ($action) {

    // ── Health Check ──────────────────────────────────────────────────────────
    case 'get_new_releases':
        require_permission('browse');
        $file = DATA_DIR . '/new_releases.json';
        if (!file_exists($file)) {
            echo json_encode(['movies' => [], 'series' => [], 'generated_at' => null]);
            break;
        }
        $data = json_decode(file_get_contents($file), true) ?? [];
        $db   = load_db();
        // Downloaded-Status anreichern
        foreach (($data['movies'] ?? []) as &$m) {
            $m['downloaded'] = in_array((string)$m['id'], $db['movies']);
            $m['stream_id']  = $m['id'];
            $m['clean_title'] = display_title($m['title'] ?? '');
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
        $d   = $_GET + (json_decode(file_get_contents('php://input'), true) ?? []);
        $sid = (string)($d['stream_id'] ?? '');
        $type = $d['type'] ?? 'movie';
        $ext  = $d['ext']  ?? 'mp4';
        if ($sid === '') { echo json_encode(['error' => 'Missing stream_id']); break; }
        if (!is_configured()) { echo json_encode(['error' => 'Nicht konfiguriert']); break; }

        // ffprobe verfügbar?
        exec('ffprobe -version 2>&1', $verOut, $verRet);
        if ($verRet !== 0) { echo json_encode(['error' => 'ffprobe nicht installiert']); break; }

        $url = stream_url($type === 'episode' ? 'series' : 'movie', $sid, $ext);

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
        $d     = $_GET + (json_decode(file_get_contents('php://input'), true) ?? []);
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
        $d      = json_decode(file_get_contents('php://input'), true) ?? [];
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
        $d      = json_decode(file_get_contents('php://input'), true) ?? [];
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
        $history = file_exists(DOWNLOAD_HISTORY_FILE)
            ? (json_decode(file_get_contents(DOWNLOAD_HISTORY_FILE), true) ?? [])
            : [];
        $filtered = array_values(array_filter($history, fn($h) => ($h['added_by'] ?? '') === $username));
        echo json_encode([
            'username' => $username,
            'count'    => count($filtered),
            'total_bytes' => array_sum(array_column($filtered, 'bytes')),
            'items'    => array_slice($filtered, 0, 100), // max 100
        ]);
        break;

    case 'update_user':
        require_permission('users');
        $d  = json_decode(file_get_contents('php://input'), true) ?? [];
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
        $d     = json_decode(file_get_contents('php://input'), true) ?? [];
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
        $d         = json_decode(file_get_contents('php://input'), true) ?? [];
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
        $id = json_decode(file_get_contents('php://input'), true)['id'] ?? '';
        if ($id === $current_user['id']) {
            http_response_code(400); echo json_encode(['error' => 'Du kannst dich nicht selbst löschen']); break;
        }
        $target = find_user_by_id($id);
        $r = delete_user($id);
        if (is_string($r)) { http_response_code(400); echo json_encode(['error' => $r]); break; }
        log_activity($current_user['id'], $current_user['username'], 'delete_user', ['target' => $target['username'] ?? $id]);
        echo json_encode(['ok' => true]);
        break;

    case 'change_own_password':
        $d   = json_decode(file_get_contents('php://input'), true) ?? [];
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
            'server_ip'              => $c['server_ip']              ?? '',
            'port'                   => $c['port']                   ?? '80',
            'username'               => $c['username']               ?? '',
            'password'               => isset($c['password']) && $c['password'] !== '' ? '••••••••' : '',
            'dest_path'              => $c['dest_path']              ?? '',
            'configured'             => is_configured(),
            'server_id'              => SERVER_ID,
            'rclone_enabled'         => (bool)($c['rclone_enabled']  ?? false),
            'rclone_remote'          => $c['rclone_remote']          ?? '',
            'rclone_path'            => $c['rclone_path']            ?? '',
            'rclone_bin'             => $c['rclone_bin']             ?? 'rclone',
            'editor_movies_enabled'  => (bool)($c['editor_movies_enabled']  ?? true),
            'editor_series_enabled'  => (bool)($c['editor_series_enabled']  ?? true),
            'tmdb_api_key'           => isset($c['tmdb_api_key']) && $c['tmdb_api_key'] !== '' ? '••••••••' : '',
            'telegram_bot_token'     => isset($c['telegram_bot_token']) && $c['telegram_bot_token'] !== '' ? '••••••••' : '',
            'telegram_chat_id'       => $c['telegram_chat_id'] ?? '',
        ]);
        break;

    case 'save_config':
        require_permission('settings');
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        $current = load_config();

        $new = [
            'server_ip'             => trim($d['server_ip']      ?? $current['server_ip']     ?? ''),
            'port'                  => trim($d['port']            ?? $current['port']          ?? '80'),
            'username'              => trim($d['username']        ?? $current['username']      ?? ''),
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
        ];
        if (!empty($d['password']) && $d['password'] !== '••••••••') {
            $new['password'] = $d['password'];
        } else {
            $new['password'] = $current['password'] ?? '';
        }

        if ($new['server_ip'] === '' || $new['username'] === '' || $new['password'] === '') {
            echo json_encode(['error' => 'Server IP, Username und Passwort sind Pflichtfelder']);
            break;
        }

        // Prüfen ob der Server gewechselt wurde
        $oldServerId = SERVER_ID;
        $newServerId = substr(md5($new['server_ip'] . ':' . $new['port'] . ':' . $new['username']), 0, 8);
        $serverChanged = $oldServerId !== $newServerId && is_configured();

        if (!empty($d['test_connection'])) {
            $params = http_build_query(['username' => $new['username'], 'password' => $new['password'], 'action' => 'get_vod_categories']);
            $testUrl = 'http://' . $new['server_ip'] . ':' . $new['port'] . '/player_api.php?' . $params;
            $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
            $raw = @file_get_contents($testUrl, false, $ctx);
            if ($raw === false) { echo json_encode(['error' => 'Verbindung fehlgeschlagen – Server nicht erreichbar']); break; }
            $json = json_decode($raw, true);
            if (!is_array($json)) { echo json_encode(['error' => 'Ungültige Antwort vom Server (falsche Zugangsdaten?)']); break; }
            echo json_encode(['ok' => true, 'tested' => true, 'categories' => count($json)]);
            if (empty($d['save'])) break;
        }

        if (save_config($new)) {
            $response = ['ok' => true];
            if ($serverChanged) {
                $response['server_changed'] = true;
                $response['new_server_id']  = $newServerId;
                $response['info'] = 'Server gewechselt — neue Datenbasis für Downloads, Queue und Cache wird verwendet.';
            }
            // Server automatisch in servers.json speichern/aktualisieren
            $servers = file_exists(SERVERS_FILE)
                ? (json_decode(file_get_contents(SERVERS_FILE), true) ?? [])
                : [];
            $found = false;
            foreach ($servers as &$s) {
                if ($s['id'] === $newServerId) {
                    $s['server_ip'] = $new['server_ip'];
                    $s['port']      = $new['port'];
                    $s['username']  = $new['username'];
                    $s['password']  = $new['password'];
                    $found = true; break;
                }
            }
            unset($s);
            if (!$found) {
                $servers[] = [
                    'id'        => $newServerId,
                    'name'      => $new['server_ip'] . ':' . $new['port'],
                    'server_ip' => $new['server_ip'],
                    'port'      => $new['port'],
                    'username'  => $new['username'],
                    'password'  => $new['password'],
                    'saved_at'  => date('Y-m-d H:i:s'),
                ];
            }
            file_put_contents(SERVERS_FILE, json_encode(array_values($servers), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo json_encode($response);
        } else {
            echo json_encode(['error' => 'Konnte config.json nicht schreiben – Berechtigungen prüfen']);
        }
        break;

    // ── Server-Verwaltung ──────────────────────────────────────────────────────
    // ── Einladungslinks ───────────────────────────────────────────────────────
    case 'create_invite':
        require_permission('users');
        $d       = json_decode(file_get_contents('php://input'), true) ?? [];
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
        $d     = json_decode(file_get_contents('php://input'), true) ?? [];
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
        // Aktiven Server markieren
        foreach ($servers as &$s) {
            $s['active'] = ($s['id'] === SERVER_ID);
        }
        unset($s);
        echo json_encode(array_values($servers));
        break;

    case 'save_server':
        require_permission('settings');
        $d       = json_decode(file_get_contents('php://input'), true) ?? [];
        $sid     = trim($d['server_id'] ?? '');
        $name    = trim($d['name']      ?? '');
        if ($sid === '' || $name === '') {
            echo json_encode(['error' => 'server_id und name sind Pflichtfelder']); break;
        }
        $servers = file_exists(SERVERS_FILE)
            ? (json_decode(file_get_contents(SERVERS_FILE), true) ?? [])
            : [];
        // Existierenden Eintrag updaten oder neu anlegen
        $found = false;
        foreach ($servers as &$s) {
            if ($s['id'] === $sid) { $s['name'] = $name; $found = true; break; }
        }
        unset($s);
        if (!$found) {
            $servers[] = [
                'id'         => $sid,
                'name'       => $name,
                'server_ip'  => $d['server_ip']  ?? '',
                'port'       => $d['port']        ?? '80',
                'username'   => $d['username']    ?? '',
                'saved_at'   => date('Y-m-d H:i:s'),
            ];
        }
        file_put_contents(SERVERS_FILE, json_encode(array_values($servers), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]);
        break;

    case 'delete_server':
        require_permission('settings');
        $d   = json_decode(file_get_contents('php://input'), true) ?? [];
        $sid = trim($d['server_id'] ?? '');
        if ($sid === '') { echo json_encode(['error' => 'server_id fehlt']); break; }
        if ($sid === SERVER_ID) { echo json_encode(['error' => 'Aktiver Server kann nicht gelöscht werden']); break; }
        $servers = file_exists(SERVERS_FILE)
            ? (json_decode(file_get_contents(SERVERS_FILE), true) ?? [])
            : [];
        $servers = array_values(array_filter($servers, fn($s) => $s['id'] !== $sid));
        file_put_contents(SERVERS_FILE, json_encode($servers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]);
        break;

    case 'switch_server':
        require_permission('settings');
        $d   = json_decode(file_get_contents('php://input'), true) ?? [];
        $sid = trim($d['server_id'] ?? '');
        $servers = file_exists(SERVERS_FILE)
            ? (json_decode(file_get_contents(SERVERS_FILE), true) ?? [])
            : [];
        $target = null;
        foreach ($servers as $s) {
            if ($s['id'] === $sid) { $target = $s; break; }
        }
        if (!$target) { echo json_encode(['error' => 'Server nicht gefunden']); break; }
        // Config mit den gespeicherten Zugangsdaten des Ziel-Servers laden
        $current = load_config();
        $new = array_merge($current, [
            'server_ip' => $target['server_ip'],
            'port'      => $target['port'],
            'username'  => $target['username'],
            'password'  => $target['password'] ?? $current['password'],
        ]);
        if (save_config($new)) {
            echo json_encode(['ok' => true, 'server_id' => $sid, 'name' => $target['name']]);
        } else {
            echo json_encode(['error' => 'Konnte config.json nicht schreiben']);
        }
        break;

    case 'webhook_test':
        require_permission('settings');
        require_once __DIR__ . '/notify.php';
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        // Temporär mit den gesendeten Werten testen (noch nicht gespeichert)
        $testUrl  = trim($d['webhook_url']  ?? WEBHOOK_URL);
        $testType = trim($d['webhook_type'] ?? WEBHOOK_TYPE);
        if ($testUrl === '') { echo json_encode(['error' => 'Webhook-URL fehlt']); break; }

        // Payload bauen und direkt senden (ohne Constants zu überschreiben)
        $message = "🔔 *Xtream Vault Test*\nWebhook-Verbindung erfolgreich!";
        if ($testType === 'telegram') {
            $payload = json_encode(['text' => $message, 'parse_mode' => 'Markdown']);
        } elseif ($testType === 'discord') {
            $payload = json_encode(['embeds' => [['description' => 'Xtream Vault Test — Verbindung erfolgreich!', 'color' => 0x64d2ff, 'footer' => ['text' => 'Xtream Vault']]]]);
        } else {
            $payload = json_encode(['event' => 'test', 'message' => 'Xtream Vault Webhook Test']);
        }
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nUser-Agent: XtreamVault/1.0\r\n", 'content' => $payload, 'timeout' => 8, 'ignore_errors' => true]]);
        $result = @file_get_contents($testUrl, false, $ctx);
        $code = 0;
        if (isset($http_response_header)) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $m);
            $code = (int)($m[1] ?? 0);
        }
        if ($result === false || ($code >= 400)) {
            echo json_encode(['error' => 'Webhook nicht erreichbar (HTTP ' . $code . ')']);
        } else {
            echo json_encode(['ok' => true, 'http_code' => $code]);
        }
        break;

    case 'telegram_test':
        require_permission('settings');
        $d     = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = trim($d['bot_token'] ?? TELEGRAM_BOT_TOKEN);
        $chatId = trim($d['chat_id'] ?? TELEGRAM_CHAT_ID);
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
        $d      = json_decode(file_get_contents('php://input'), true) ?? [];
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
    case 'get_movie_categories':
        echo json_encode(xtream('get_vod_categories'));
        break;

    case 'get_series_categories':
        echo json_encode(xtream('get_series_categories'));
        break;

    // ── Streams ───────────────────────────────────────────────────────────────
    case 'get_movies':
        $movies   = xtream('get_vod_streams', ['category_id' => $_GET['category_id'] ?? '']);
        $db       = load_db();
        $queue    = load_queue();
        $qids     = array_map('strval', array_column($queue, 'stream_id'));
        $is_admin = can('settings');
        foreach ($movies as &$m) {
            $m['downloaded']  = in_array((string)$m['stream_id'], $db['movies']);
            $m['queued']      = in_array((string)$m['stream_id'], $qids);
            $m['clean_title'] = display_title($m['name'] ?? '');
            if ($is_admin) {
                $m['stream_url'] = stream_url('movie', $m['stream_id'], $m['container_extension'] ?? 'mp4');
            } else {
                unset($m['stream_url']);
            }
        }
        echo json_encode($movies);
        break;

    case 'get_series':
        $list = xtream('get_series', ['category_id' => $_GET['category_id'] ?? '']);
        foreach ($list as &$s) {
            $s['clean_title'] = display_title($s['name'] ?? '');
            if (!can('settings')) unset($s['stream_url']);
        }
        echo json_encode($list);
        break;

    case 'get_series_info':
        $data     = xtream('get_series_info', ['series_id' => $_GET['series_id'] ?? '']);
        $db       = load_db();
        $queue    = load_queue();
        $qids     = array_map('strval', array_column($queue, 'stream_id'));
        $is_admin = can('settings');
        if (isset($data['episodes'])) {
            foreach ($data['episodes'] as $season => &$eps)
                foreach ($eps as &$ep) {
                    $ep['downloaded']  = in_array((string)$ep['id'], $db['episodes']);
                    $ep['queued']      = in_array((string)$ep['id'], $qids);
                    $ep['clean_title'] = display_title($ep['title'] ?? '');
                    if ($is_admin) {
                        $ep['stream_url'] = stream_url('series', $ep['id'], $ep['container_extension'] ?? 'mp4');
                    } else {
                        unset($ep['stream_url']);
                    }
                }
        }
        echo json_encode($data);
        break;

    case 'search_movies':
        $q        = strtolower(trim($_GET['q'] ?? ''));
        if ($q === '') { echo json_encode([]); break; }
        $db       = load_db();
        $queue    = load_queue();
        $qids     = array_map('strval', array_column($queue, 'stream_id'));
        $is_admin = can('settings');
        $results  = [];

        // ── Primär: Library-Cache durchsuchen ─────────────────────────────────
        $cacheFile = LIBRARY_CACHE_FILE;
        $cacheAge  = file_exists($cacheFile) ? (time() - filemtime($cacheFile)) : PHP_INT_MAX;
        $useCache  = file_exists($cacheFile) && $cacheAge < 86400 * 1; // max 1 Tag alt

        if ($useCache) {
            $cache = json_decode(file_get_contents($cacheFile), true) ?? [];
            foreach ($cache as $sid => $m) {
                $title = strtolower($m['title'] ?? $m['clean_title'] ?? $m['name'] ?? '');
                if (!str_contains($title, $q)) continue;
                $sidStr = (string)($m['stream_id'] ?? $sid);
                $results[] = [
                    'stream_id'           => $sidStr,
                    'clean_title'         => $m['title'] ?? $m['clean_title'] ?? '',
                    'stream_icon'         => $m['cover'] ?? '',
                    'category'            => $m['category'] ?? '',
                    'container_extension' => $m['ext'] ?? 'mp4',
                    'downloaded'          => in_array($sidStr, $db['movies']),
                    'queued'              => in_array($sidStr, $qids),
                ];
            }
            echo json_encode(['results' => $results, 'source' => 'cache', 'cache_age' => $cacheAge]);
            break;
        }

        // ── Fallback: Xtream-Server (Cache leer oder zu alt) ──────────────────
        foreach (xtream('get_vod_categories') as $cat) {
            foreach (xtream('get_vod_streams', ['category_id' => $cat['category_id']]) as $m) {
                $title = display_title($m['name'] ?? '');
                if (!str_contains(strtolower($title), $q)) continue;
                $m['clean_title'] = $title;
                $m['category']    = $cat['category_name'];
                $m['downloaded']  = in_array((string)$m['stream_id'], $db['movies']);
                $m['queued']      = in_array((string)$m['stream_id'], $qids);
                if ($is_admin) $m['stream_url'] = stream_url('movie', $m['stream_id'], $m['container_extension'] ?? 'mp4');
                else unset($m['stream_url']);
                $results[] = $m;
            }
        }
        echo json_encode(['results' => $results, 'source' => 'xtream']);
        break;

    case 'search_series':
        $q = strtolower(trim($_GET['q'] ?? ''));
        if ($q === '') { echo json_encode([]); break; }
        $results = [];

        // ── Primär: Series-Cache ───────────────────────────────────────────────
        $seriesCacheFile = DATA_DIR . '/series_cache.json';
        $seriesCacheAge  = file_exists($seriesCacheFile) ? (time() - filemtime($seriesCacheFile)) : PHP_INT_MAX;
        $useSeriesCache  = file_exists($seriesCacheFile) && $seriesCacheAge < 86400 * 1;

        if ($useSeriesCache) {
            $cache = json_decode(file_get_contents($seriesCacheFile), true) ?? [];
            foreach ($cache as $s) {
                $title = strtolower($s['clean_title'] ?? $s['name'] ?? '');
                if (str_contains($title, $q)) $results[] = $s;
            }
            echo json_encode(['results' => $results, 'source' => 'cache', 'cache_age' => $seriesCacheAge]);
            break;
        }

        // ── Fallback: Xtream-Server ────────────────────────────────────────────
        foreach (xtream('get_series_categories') as $cat) {
            foreach (xtream('get_series', ['category_id' => $cat['category_id']]) as $s) {
                $title = display_title($s['name'] ?? '');
                if (!str_contains(strtolower($title), $q)) continue;
                $s['clean_title'] = $title;
                $s['category']    = $cat['category_name'];
                $results[]        = $s;
            }
        }
        echo json_encode(['results' => $results, 'source' => 'xtream']);
        break;

    case 'queue_add_bulk':
        // Mehrere Items auf einmal zur Queue hinzufügen
        require_permission('queue_add');
        $items = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!is_array($items) || empty($items)) { echo json_encode(['error' => 'No items']); break; }

        $queue      = load_queue();
        $qids       = array_map('strval', array_column($queue, 'stream_id'));
        $added      = 0;
        $skipped    = 0;
        $limited    = false;

        foreach ($items as $d) {
            $limitCheck = check_queue_rate_limit($current_user);
            if (!$limitCheck['allowed']) { $limited = true; break; }

            $sid  = (string)($d['stream_id'] ?? '');
            $type = $d['type'] ?? 'movie';
            $ext  = $d['container_extension'] ?? 'mp4';
            if ($sid === '' || in_array($sid, $qids)) { $skipped++; continue; }

            $queue[] = [
                'stream_id'           => $sid,
                'type'                => $type,
                'title'               => sanitize($d['title'] ?? 'Unknown'),
                'container_extension' => $ext,
                'category'            => $d['category'] ?? '',
                'season'              => isset($d['season']) ? (int)$d['season'] : null,
                'priority'            => 2,
                'stream_url'          => stream_url($type === 'episode' ? 'series' : 'movie', $sid, $ext),
                'cover'               => $d['cover'] ?? '',
                'dest_subfolder'      => $d['dest_subfolder'] ?? ($type === 'episode' ? 'TV Shows' : 'Movies'),
                'added_at'            => date('Y-m-d H:i:s'),
                'added_by'            => $current_user['username'],
                'status'              => 'pending',
                'error'               => null,
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


        $type = $_POST['type'] ?? '';
        $id   = (string)($_POST['id'] ?? '');
        if (!in_array($type, ['movies','episodes']) || $id === '') { echo json_encode(['error'=>'Invalid']); break; }
        $db = load_db();
        if (!in_array($id, $db[$type])) { $db[$type][] = $id; save_db($db); }
        echo json_encode(['ok' => true]);
        break;

    // ─── Queue ────────────────────────────────────────────────────────────────
    case 'get_queue':
        require_permission('queue_view');
        $queue = load_queue();
        if (!can('settings')) {
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
        $d    = json_decode(file_get_contents('php://input'), true) ?? [];
        $sid  = (string)($d['stream_id'] ?? '');
        $type = $d['type'] ?? 'movie';
        $ext  = $d['container_extension'] ?? 'mp4';
        if ($sid === '') { echo json_encode(['error'=>'Missing stream_id']); break; }
        $queue = load_queue();
        foreach ($queue as $qi) if ((string)$qi['stream_id'] === $sid) { echo json_encode(['ok'=>true,'already'=>true]); break 2; }

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
        $server_stream_url = stream_url($type === 'episode' ? 'series' : 'movie', $sid, $ext);

        $queue[] = [
            'stream_id'           => $sid,
            'type'                => $type,
            'title'               => sanitize($d['title'] ?? 'Unknown'),
            'container_extension' => $ext,
            'category'            => $d['category'] ?? '',
            'season'              => isset($d['season']) ? (int)$d['season'] : null,
            'priority'            => 2, // Standard: normal
            'stream_url'          => $server_stream_url,
            'cover'               => $d['cover'] ?? '',
            'dest_subfolder'      => $d['dest_subfolder'] ?? '',
            'added_at'            => date('Y-m-d H:i:s'),
            'added_by'            => $current_user['username'],
            'status'              => 'pending',
            'error'               => null,
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
        $d    = json_decode(file_get_contents('php://input'), true) ?? [];
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
                    'cover'     => $d['cover']    ?? '',
                    'category'  => $d['category'] ?? '',
                    'ext'       => $d['ext']       ?? 'mp4',
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
        $d   = json_decode(file_get_contents('php://input'), true) ?? [];
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
        $sid   = (string)(json_decode(file_get_contents('php://input'), true)['stream_id'] ?? '');
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
        $sid   = (string)(json_decode(file_get_contents('php://input'), true)['stream_id'] ?? '');
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
        $d    = json_decode(file_get_contents('php://input'), true) ?? [];
        $sid  = (string)($d['stream_id'] ?? '');
        $type = $d['type'] ?? 'movie'; // 'movie' oder 'episode'
        if ($sid === '') { http_response_code(400); echo json_encode(['error' => 'Missing stream_id']); break; }

        // Aus downloaded.json entfernen
        $db     = load_db();
        $dbKey  = $type === 'movie' ? 'movies' : 'episodes';
        $before = count($db[$dbKey]);
        $db[$dbKey] = array_values(array_filter($db[$dbKey], fn($id) => (string)$id !== $sid));
        save_db($db);

        // Aus downloaded_index.json entfernen
        if (file_exists(DOWNLOADED_INDEX_FILE)) {
            $index = json_decode(file_get_contents(DOWNLOADED_INDEX_FILE), true) ?? [];
            unset($index[$sid]);
            file_put_contents(DOWNLOADED_INDEX_FILE, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
        save_queue($queue);
        echo json_encode(['ok' => true]);
        break;

    case 'queue_clear_all':
        require_permission('queue_clear');
        save_queue([]);
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

    case 'get_progress':
        require_permission('queue_view');
        if (!file_exists(PROGRESS_FILE)) {
            echo json_encode(['active' => false]);
            break;
        }
        $p = json_decode(file_get_contents(PROGRESS_FILE), true);
        if (!empty($p['updated_at'])) {
            $age = time() - strtotime($p['updated_at']);
            if ($age > 10) { $p['active'] = false; $p['stale'] = true; }
        }
        echo json_encode($p ?? ['active' => false]);
        break;

    case 'get_library':
        require_permission('browse');
        $db = load_db();
        $downloadedMovieIds   = $db['movies']   ?? [];
        $downloadedEpisodeIds = $db['episodes'] ?? [];

        // Nichts heruntergeladen → sofort antworten
        if (empty($downloadedMovieIds) && empty($downloadedEpisodeIds)) {
            echo json_encode([
                'cache_ready'   => file_exists(LIBRARY_CACHE_FILE) || file_exists(DOWNLOADED_INDEX_FILE),
                'cache_age_min' => null,
                'movies'        => [],
                'episodes'      => [],
                'total'         => 0,
                'categories'    => [],
            ]);
            break;
        }

        // Downloaded-Index lesen (nur heruntergeladene Items — klein und schnell)
        $index = [];
        if (file_exists(DOWNLOADED_INDEX_FILE)) {
            $raw = @file_get_contents(DOWNLOADED_INDEX_FILE);
            if ($raw !== false) $index = json_decode($raw, true) ?? [];
        }

        // Fehlende Filme aus library_cache.json nachschlagen (Filme-Cache ist okay groß)
        $missingMovies = array_filter($downloadedMovieIds, fn($id) => !isset($index[(string)$id]));
        if (!empty($missingMovies) && file_exists(LIBRARY_CACHE_FILE)) {
            $prevLimit = ini_get('memory_limit');
            ini_set('memory_limit', '256M');
            $raw = @file_get_contents(LIBRARY_CACHE_FILE);
            ini_set('memory_limit', $prevLimit);
            if ($raw !== false) {
                $data = json_decode($raw, true) ?? [];
                unset($raw);
                foreach ($missingMovies as $id)
                    if (isset($data[(string)$id])) $index[(string)$id] = $data[(string)$id];
                unset($data);
                @file_put_contents(DOWNLOADED_INDEX_FILE, json_encode($index, JSON_UNESCAPED_UNICODE));
            }
        }

        // Kein Index und kein Cache → noch nicht aufgebaut
        if (empty($index) && !file_exists(LIBRARY_CACHE_FILE)) {
            echo json_encode(['cache_ready' => false]);
            break;
        }

        $filterQ   = strtolower(trim($_GET['q']       ?? ''));
        $filterCat = trim($_GET['category'] ?? '');

        $movies = [];
        foreach ($downloadedMovieIds as $id) {
            $item = $index[(string)$id]
                ?? ['id' => (string)$id, 'title' => 'Film #'.$id, 'cover' => '', 'category' => '', 'ext' => 'mp4', 'type' => 'movie'];
            if ($filterQ   && !str_contains(strtolower($item['title']), $filterQ))  continue;
            if ($filterCat && $item['category'] !== $filterCat) continue;
            $movies[] = $item;
        }

        $episodes = [];
        foreach ($downloadedEpisodeIds as $id) {
            $item = $index[(string)$id]
                ?? ['id' => (string)$id, 'title' => 'Episode #'.$id, 'cover' => '', 'category' => '', 'ext' => 'mp4', 'type' => 'episode'];
            if ($filterQ   && !str_contains(strtolower($item['title']), $filterQ))  continue;
            if ($filterCat && $item['category'] !== $filterCat) continue;
            $episodes[] = $item;
        }

        $allCatMovies   = array_map(fn($id) => $index[(string)$id] ?? ['category'=>''], $downloadedMovieIds);
        $allCatEpisodes = array_map(fn($id) => $index[(string)$id] ?? ['category'=>''], $downloadedEpisodeIds);
        $categories = array_values(array_unique(array_filter(array_merge(
            array_column($allCatMovies,   'category'),
            array_column($allCatEpisodes, 'category')
        ))));
        sort($categories);

        $cacheAge = file_exists(DOWNLOADED_INDEX_FILE)
            ? round((time() - filemtime(DOWNLOADED_INDEX_FILE)) / 60)
            : null;

        echo json_encode([
            'cache_ready'   => true,
            'cache_age_min' => $cacheAge,
            'movies'        => array_reverse($movies),
            'episodes'      => array_reverse($episodes),
            'total'         => count($movies) + count($episodes),
            'categories'    => $categories,
        ]);
        break;

    case 'queue_start':
        require_permission('settings');
        $lockFile     = __DIR__ . '/data/cron.lock';
        $startingLock = __DIR__ . '/data/cron_starting.lock';

        // Prüfen ob cron.php läuft — gleiche Lock-Datei wie cron.php intern
        $lf = @fopen($lockFile, 'c');
        if ($lf) {
            $free = flock($lf, LOCK_EX | LOCK_NB);
            if ($free) flock($lf, LOCK_UN);
            fclose($lf);
        } else {
            $free = true;
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
        $name = basename(json_decode(file_get_contents('php://input'), true)['name'] ?? '');
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
        $name = basename(json_decode(file_get_contents('php://input'), true)['name'] ?? '');
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
        $movieReady  = file_exists(LIBRARY_CACHE_FILE);
        $indexReady  = file_exists(DOWNLOADED_INDEX_FILE);
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
            // Letzte relevante Zeile ohne PHP-Fehlermeldungen
            $filtered = array_filter($lines, fn($l) => !str_contains($l, 'PHP') && !str_contains($l, 'thrown'));
            $lastLine = end($filtered) ?: end($lines) ?: '';
        }

        echo json_encode([
            'movie_cache_ready'  => $movieReady,
            'index_ready'        => $indexReady,
            // series_cache_ready nicht mehr relevant — Serien werden via cron.php indexiert
            'series_cache_ready' => true,
            'cache_age_min'      => $movieReady ? round((time() - filemtime(LIBRARY_CACHE_FILE)) / 60) : null,
            'building'           => $building,
            'last_message'       => $lastLine,
        ]);
        break;


    case 'stats_data':
        require_permission('settings');
        $history = file_exists(DOWNLOAD_HISTORY_FILE)
            ? (json_decode(file_get_contents(DOWNLOAD_HISTORY_FILE), true) ?? [])
            : [];

        // ── GB pro Monat ──────────────────────────────────────────────────────
        $byMonth = [];
        foreach ($history as $h) {
            if (empty($h['done_at'])) continue;
            $month = substr($h['done_at'], 0, 7); // "YYYY-MM"
            if (!isset($byMonth[$month])) $byMonth[$month] = ['count' => 0, 'bytes' => 0];
            $byMonth[$month]['count']++;
            $byMonth[$month]['bytes'] += (int)($h['bytes'] ?? 0);
        }
        // Lücken füllen: letzte 12 Monate immer anzeigen
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = date('Y-m', strtotime("-{$i} months"));
            $months[$m] = $byMonth[$m] ?? ['count' => 0, 'bytes' => 0];
        }
        // Ältere Monate davor einfügen falls vorhanden
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

        // ── Gesamt ────────────────────────────────────────────────────────────
        $totalBytes = array_sum(array_column($history, 'bytes'));
        $totalCount = count($history);

        echo json_encode([
            'by_month'    => $months,
            'top_users'   => $topUsers,
            'total_bytes' => $totalBytes,
            'total_count' => $totalCount,
        ]);
        break;

    case 'stats':
        $db    = load_db();
        $queued = can('queue_view')
            ? count(array_filter(load_queue(), fn($q) => $q['status'] === 'pending'))
            : null;
        echo json_encode([
            'movies'           => count($db['movies']),
            'episodes'         => count($db['episodes']),
            'queued'           => $queued,
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
        $history = [];
        if (file_exists(DOWNLOAD_HISTORY_FILE)) {
            $raw = @file_get_contents(DOWNLOAD_HISTORY_FILE);
            if ($raw !== false) $history = json_decode($raw, true) ?? [];
        }
        $recentDownloads = array_slice($history, 0, 10);

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

        // ── System-Status ─────────────────────────────────────────────────────
        $memUsed  = memory_get_usage(true);
        $memPeak  = memory_get_peak_usage(true);
        $memLimit = ini_get('memory_limit');

        // Uptime via /proc/uptime (Linux)
        $uptime = null;
        if (file_exists('/proc/uptime')) {
            $up = (float)explode(' ', file_get_contents('/proc/uptime'))[0];
            $d  = floor($up / 86400);
            $h  = floor(($up % 86400) / 3600);
            $m  = floor(($up % 3600) / 60);
            $uptime = ($d > 0 ? "{$d}d " : '') . "{$h}h {$m}m";
        }

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
            'system'           => [
                'php_version' => PHP_VERSION,
                'mem_used'    => $memUsed,
                'mem_peak'    => $memPeak,
                'mem_limit'   => $memLimit,
                'uptime'      => $uptime,
                'os'          => php_uname('s') . ' ' . php_uname('r'),
            ],
            'total_downloaded' => count($db['movies']) + count($db['episodes']),
        ]);
        break;

    // ── Externer Endpoint: Benutzer anlegen via API-Key ───────────────────────
    case 'external_create_user':
        // Nur via API-Key erreichbar (geprüft oben)
        // Parameter aus POST-Body (JSON) oder GET-Query-String
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
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
        $d         = json_decode(file_get_contents('php://input'), true) ?? [];
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
        $d      = json_decode(file_get_contents('php://input'), true) ?? [];
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
        $d      = json_decode(file_get_contents('php://input'), true) ?? [];
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
    case 'get_server_info':
        require_permission('settings');
        $info = xtream('get_user_info');
        // Xtream-Antwort enthält user_info und server_info
        $user_info   = $info['user_info']   ?? [];
        $server_info = $info['server_info'] ?? [];
        echo json_encode([
            'user' => [
                'username'        => $user_info['username']       ?? '',
                'status'          => $user_info['status']         ?? '',
                'exp_date'        => $user_info['exp_date']        ?? null,
                'is_trial'        => $user_info['is_trial']        ?? '0',
                'active_cons'     => $user_info['active_cons']     ?? '0',
                'max_connections' => $user_info['max_connections'] ?? '0',
                'allowed_output_formats' => $user_info['allowed_output_formats'] ?? [],
            ],
            'server' => [
                'url'             => $server_info['url']          ?? '',
                'port'            => $server_info['port']         ?? '',
                'https_port'      => $server_info['https_port']   ?? '',
                'server_protocol' => $server_info['server_protocol'] ?? '',
                'rtmp_port'       => $server_info['rtmp_port']    ?? '',
                'timezone'        => $server_info['timezone']     ?? '',
                'timestamp_now'   => $server_info['timestamp_now'] ?? 0,
                'time_now'        => $server_info['time_now']     ?? '',
            ],
        ]);
        break;

    // ── API-Key-Verwaltung (Admin only) ───────────────────────────────────────
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
        $d    = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim($d['name'] ?? 'API Key');
        $key  = create_api_key($name, $current_user['username']);
        // Einzige Gelegenheit den vollen Key zu sehen
        echo json_encode(['ok' => true, 'key' => $key]);
        break;

    case 'revoke_api_key':
        require_permission('settings');
        $id = json_decode(file_get_contents('php://input'), true)['id'] ?? '';
        echo json_encode(['ok' => revoke_api_key($id)]);
        break;

    case 'reveal_api_key':
        require_permission('settings');
        $d        = json_decode(file_get_contents('php://input'), true) ?? [];
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
        $id = json_decode(file_get_contents('php://input'), true)['id'] ?? '';
        echo json_encode(['ok' => delete_api_key($id)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
