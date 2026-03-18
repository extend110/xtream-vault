<?php
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

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
    // API-Key-Aufrufe dürfen nur den external_create_user-Endpoint nutzen
    if ($action !== 'external_create_user') {
        http_response_code(403);
        echo json_encode(['error' => 'This endpoint is not available via API key']);
        exit;
    }
}

// ─── Auth-freie Endpoints ─────────────────────────────────────────────────────
$public_actions = ['login', 'logout', 'setup_status'];
if (!$api_key_auth && !in_array($action, $public_actions)) {
    $current_user = require_login();
}

// ─── Nicht konfiguriert → Fehler außer bei Config-Aktionen ───────────────────
$config_actions = ['get_config', 'save_config'];
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
    $n = preg_replace('/[<>:"|?*]/', '', $n);
    $n = preg_replace('/[^\x20-\x7E]/', '', $n);
    return trim(preg_replace('/\s+/', ' ', $n)) ?: 'Uncategorized';
}
function stream_url(string $type, $id, string $ext): string {
    $p = $type === 'movie' ? 'movie' : 'series';
    return 'http://' . SERVER_IP . ':' . PORT . "/{$p}/" . USERNAME . '/' . PASSWORD . "/{$id}.{$ext}";
}

// ─── Router ───────────────────────────────────────────────────────────────────
switch ($action) {

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
            'id'         => $u['id'],
            'username'   => $u['username'],
            'role'       => $u['role'],
            'suspended'  => $u['suspended'] ?? false,
            'created_at' => $u['created_at'],
            'last_login' => $u['last_login'],
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
            'rclone_enabled'         => (bool)($c['rclone_enabled']  ?? false),
            'rclone_remote'          => $c['rclone_remote']          ?? '',
            'rclone_path'            => $c['rclone_path']            ?? '',
            'rclone_bin'             => $c['rclone_bin']             ?? 'rclone',
            'editor_movies_enabled'  => (bool)($c['editor_movies_enabled']  ?? true),
            'editor_series_enabled'  => (bool)($c['editor_series_enabled']  ?? true),
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
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => 'Konnte config.json nicht schreiben – Berechtigungen prüfen']);
        }
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
        $db       = load_db();
        $queue    = load_queue();
        $qids     = array_map('strval', array_column($queue, 'stream_id'));
        $is_admin = can('settings');
        $results  = [];
        foreach (xtream('get_vod_categories') as $cat) {
            foreach (xtream('get_vod_streams', ['category_id' => $cat['category_id']]) as $m) {
                $title = display_title($m['name'] ?? '');
                if ($q === '' || str_contains(strtolower($title), $q)) {
                    $m['clean_title'] = $title;
                    $m['category']    = $cat['category_name'];
                    $m['downloaded']  = in_array((string)$m['stream_id'], $db['movies']);
                    $m['queued']      = in_array((string)$m['stream_id'], $qids);
                    if ($is_admin) {
                        $m['stream_url'] = stream_url('movie', $m['stream_id'], $m['container_extension'] ?? 'mp4');
                    } else {
                        unset($m['stream_url']);
                    }
                    $results[] = $m;
                }
            }
        }
        echo json_encode($results);
        break;

    case 'search_series':
        $q        = strtolower(trim($_GET['q'] ?? ''));
        if ($q === '') { echo json_encode([]); break; }
        $results  = [];
        foreach (xtream('get_series_categories') as $cat) {
            foreach (xtream('get_series', ['category_id' => $cat['category_id']]) as $s) {
                $title = display_title($s['name'] ?? '');
                if (str_contains(strtolower($title), $q)) {
                    $s['clean_title'] = $title;
                    $s['category']    = $cat['category_name'];
                    $results[]        = $s;
                }
            }
        }
        echo json_encode($results);
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
                    'title'     => sanitize($d['title']  ?? ''),
                    'cover'     => $d['cover']  ?? '',
                    'category'  => $d['category'] ?? '',
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


    case 'stats':
        $db    = load_db();
        $queued = can('queue_view')
            ? count(array_filter(load_queue(), fn($q) => $q['status'] === 'pending'))
            : null;
        echo json_encode([
            'movies'      => count($db['movies']),
            'episodes'    => count($db['episodes']),
            'queued'      => $queued,
            'configured'  => is_configured(),
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
                'title'    => $q['title'],
                'type'     => $q['type']    ?? 'movie',
                'added_at' => $q['done_at'] ?? $q['added_at'] ?? '',
                'added_by' => $q['added_by'] ?? '',
                'cover'    => $q['cover']    ?? '',
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

    case 'delete_api_key':
        require_permission('settings');
        $id = json_decode(file_get_contents('php://input'), true)['id'] ?? '';
        echo json_encode(['ok' => delete_api_key($id)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
