<?php
/**
 * auth.php – Xtream Vault Authentication & User Management
 *
 * Rollen:
 *   admin  – alles
 *   editor – browsen + Queue hinzufügen (kein löschen, keine Settings)
 *   viewer – nur browsen (kein Queue)
 */

require_once __DIR__ . '/config.php';

define('USERS_FILE',    DATA_DIR . '/users.json');
define('RATELIMIT_FILE', DATA_DIR . '/rate_limits.json');

// ── Rollen-Berechtigungen ─────────────────────────────────────────────────────
const ROLE_PERMISSIONS = [
    'admin'  => ['browse', 'queue_view', 'queue_add', 'queue_remove', 'queue_remove_own', 'queue_clear', 'cron_log', 'settings', 'users'],
    'editor' => ['browse', 'queue_view', 'queue_add'],
    'viewer' => ['browse'],
];

// ── Queue-Add Limit pro Rolle und Stunde (null = unbegrenzt) ──────────────────
const QUEUE_ADD_HOURLY_LIMIT = [
    'admin'  => null,   // unbegrenzt
    'editor' => 3,
    'viewer' => 0,
];

// ── Rate Limiting ─────────────────────────────────────────────────────────────
function load_rate_limits(): array {
    if (file_exists(RATELIMIT_FILE))
        return json_decode(file_get_contents(RATELIMIT_FILE), true) ?? [];
    return [];
}

function save_rate_limits(array $data): void {
    @mkdir(dirname(RATELIMIT_FILE), 0755, true);
    file_put_contents(RATELIMIT_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

/**
 * Prüft ob ein User das stündliche Queue-Add-Limit noch nicht erreicht hat.
 * Gibt ein Array zurück:
 *   ['allowed' => true]
 *   ['allowed' => false, 'limit' => 3, 'resets_in' => 1800, 'used' => 3]
 */
function check_queue_rate_limit(array $user): array {
    $role  = $user['role'];
    $limit = QUEUE_ADD_HOURLY_LIMIT[$role] ?? null;

    // Keine Beschränkung für diese Rolle
    if ($limit === null) return ['allowed' => true];

    $now    = time();
    $window = 3600; // 1 Stunde in Sekunden
    $uid    = $user['id'];
    $data   = load_rate_limits();

    // Einträge für diesen User holen und veraltete (älter als 1h) entfernen
    $entries = array_filter($data[$uid] ?? [], fn($ts) => ($now - $ts) < $window);
    $used    = count($entries);

    if ($used >= $limit) {
        // Ältesten Eintrag finden → wann läuft das Fenster ab?
        $oldest    = min($entries);
        $resetsIn  = ($oldest + $window) - $now;
        return [
            'allowed'   => false,
            'limit'     => $limit,
            'used'      => $used,
            'resets_in' => max(0, $resetsIn),
        ];
    }

    return ['allowed' => true, 'limit' => $limit, 'used' => $used, 'remaining' => $limit - $used];
}

/**
 * Verbucht einen Queue-Add für den User (nach erfolgreicher Prüfung aufrufen).
 */
function record_queue_add(array $user): void {
    $limit = QUEUE_ADD_HOURLY_LIMIT[$user['role']] ?? null;
    if ($limit === null) return; // Admins nicht tracken

    $now  = time();
    $uid  = $user['id'];
    $data = load_rate_limits();

    // Veraltete Einträge bereinigen
    $data[$uid] = array_values(array_filter($data[$uid] ?? [], fn($ts) => ($now - $ts) < 3600));
    $data[$uid][] = $now;
    save_rate_limits($data);
}

/**
 * Gibt den aktuellen Limit-Status für den eingeloggten User zurück.
 */
function get_queue_limit_status(array $user): array {
    $role  = $user['role'];
    $limit = QUEUE_ADD_HOURLY_LIMIT[$role] ?? null;
    if ($limit === null) return ['limited' => false];

    $check = check_queue_rate_limit($user);
    $used  = $check['used'] ?? ($check['limit'] ?? $limit) - ($check['remaining'] ?? 0);
    return [
        'limited'   => true,
        'limit'     => $limit,
        'used'      => $used,
        'remaining' => max(0, $limit - $used),
        'allowed'   => $check['allowed'],
        'resets_in' => $check['resets_in'] ?? null,
    ];
}

// ── User DB ───────────────────────────────────────────────────────────────────
function load_users(): array {
    if (file_exists(USERS_FILE))
        return json_decode(file_get_contents(USERS_FILE), true) ?? [];
    return [];
}

function save_users(array $users): void {
    @mkdir(dirname(USERS_FILE), 0755, true);
    file_put_contents(USERS_FILE, json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function users_exist(): bool {
    $u = load_users();
    return !empty($u);
}

function find_user(string $username): ?array {
    foreach (load_users() as $u)
        if (strtolower($u['username']) === strtolower($username)) return $u;
    return null;
}

function find_user_by_id(string $id): ?array {
    foreach (load_users() as $u)
        if ($u['id'] === $id) return $u;
    return null;
}

function create_user(string $username, string $password, string $role): array|string {
    if (!in_array($role, ['admin', 'editor', 'viewer']))
        return 'Ungültige Rolle';
    if (strlen($username) < 3)
        return 'Benutzername muss mindestens 3 Zeichen haben';
    if (strlen($password) < 6)
        return 'Passwort muss mindestens 6 Zeichen haben';
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username))
        return 'Benutzername darf nur Buchstaben, Zahlen, _, - und . enthalten';

    $users = load_users();
    foreach ($users as $u)
        if (strtolower($u['username']) === strtolower($username))
            return 'Benutzername bereits vergeben';

    $user = [
        'id'         => bin2hex(random_bytes(8)),
        'username'   => $username,
        'password'   => password_hash($password, PASSWORD_BCRYPT),
        'role'       => $role,
        'suspended'  => false,
        'created_at' => date('Y-m-d H:i:s'),
        'last_login' => null,
    ];
    $users[] = $user;
    save_users($users);
    return $user;
}

function admin_reset_password(string $id, string $newPassword): bool|string {
    if (strlen($newPassword) < 6) return 'Passwort muss mindestens 6 Zeichen haben';
    $users = load_users();
    foreach ($users as &$u) {
        if ($u['id'] === $id) {
            $u['password'] = password_hash($newPassword, PASSWORD_BCRYPT);
            save_users($users);
            return true;
        }
    }
    return 'Benutzer nicht gefunden';
}

function set_user_suspended(string $id, bool $suspended): bool|string {
    $users = load_users();
    // Letzten aktiven Admin schützen
    if ($suspended) {
        $activeAdmins = array_filter($users, fn($u) => $u['role'] === 'admin' && !($u['suspended'] ?? false) && $u['id'] !== $id);
        foreach ($users as $u)
            if ($u['id'] === $id && $u['role'] === 'admin' && empty($activeAdmins))
                return 'Kann den letzten aktiven Admin nicht sperren';
    }
    foreach ($users as &$u) {
        if ($u['id'] === $id) {
            $u['suspended'] = $suspended;
            save_users($users);
            return true;
        }
    }
    return 'Benutzer nicht gefunden';
}

function update_user_role(string $id, string $role): bool|string {
    if (!in_array($role, ['admin', 'editor', 'viewer'])) return 'Ungültige Rolle';
    $users = load_users();
    // Sicherstellen dass immer mindestens ein Admin bleibt
    $admins = array_filter($users, fn($u) => $u['role'] === 'admin' && $u['id'] !== $id);
    $target = null;
    foreach ($users as $u) if ($u['id'] === $id) { $target = $u; break; }
    if ($target && $target['role'] === 'admin' && empty($admins))
        return 'Kann den letzten Admin nicht degradieren';
    foreach ($users as &$u) {
        if ($u['id'] === $id) { $u['role'] = $role; save_users($users); return true; }
    }
    return 'Benutzer nicht gefunden';
}

function delete_user(string $id): bool|string {
    $users = load_users();
    // Letzten Admin schützen
    $admins = array_filter($users, fn($u) => $u['role'] === 'admin');
    foreach ($users as $u)
        if ($u['id'] === $id && $u['role'] === 'admin' && count($admins) === 1)
            return 'Kann den letzten Admin nicht löschen';
    $new = array_values(array_filter($users, fn($u) => $u['id'] !== $id));
    if (count($new) === count($users)) return 'Benutzer nicht gefunden';
    save_users($new);
    return true;
}

// ── Session ───────────────────────────────────────────────────────────────────
function session_start_safe(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function attempt_login(string $username, string $password): array|false|string {
    $user = find_user($username);
    if (!$user) return false;
    if (!password_verify($password, $user['password'])) return false;
    if (!empty($user['suspended'])) return 'suspended';

    // last_login aktualisieren
    $users = load_users();
    foreach ($users as &$u)
        if ($u['id'] === $user['id']) { $u['last_login'] = date('Y-m-d H:i:s'); break; }
    save_users($users);

    session_start_safe();
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['logged_in'] = true;
    return $user;
}

// ── Activity Log ──────────────────────────────────────────────────────────────
function log_activity(string $user_id, string $username, string $action, array $meta = []): void {
    $entry = [
        'ts'       => date('Y-m-d H:i:s'),
        'user_id'  => $user_id,
        'username' => $username,
        'action'   => $action,
        'meta'     => $meta,
    ];
    $log = [];
    if (file_exists(ACTIVITY_LOG_FILE)) {
        $log = json_decode(file_get_contents(ACTIVITY_LOG_FILE), true) ?? [];
    }
    array_unshift($log, $entry);          // neueste zuerst
    if (count($log) > 500) $log = array_slice($log, 0, 500); // max 500 Einträge
    @mkdir(DATA_DIR, 0755, true);
    file_put_contents(ACTIVITY_LOG_FILE, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function get_activity_log(?string $user_id = null, int $limit = 100): array {
    if (!file_exists(ACTIVITY_LOG_FILE)) return [];
    $log = json_decode(file_get_contents(ACTIVITY_LOG_FILE), true) ?? [];
    if ($user_id !== null) {
        $log = array_values(array_filter($log, fn($e) => $e['user_id'] === $user_id));
    }
    return array_slice($log, 0, $limit);
}

function logout(): void {
    session_start_safe();
    $_SESSION = [];
    session_destroy();
}

function current_user(): ?array {
    session_start_safe();
    if (empty($_SESSION['logged_in'])) return null;
    // Session-Daten mit aktuellen DB-Daten abgleichen (falls Rolle geändert/gelöscht)
    $user = find_user_by_id($_SESSION['user_id'] ?? '');
    if (!$user) { logout(); return null; }
    // Session aktuell halten
    $_SESSION['role'] = $user['role'];
    return $user;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        // Wartungsmodus: Login-Seite zeigt eigene Meldung
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'unauthenticated']);
            exit;
        }
        header('Location: login.php');
        exit;
    }
    // Wartungsmodus aktiv → nur Admins dürfen rein
    if (file_exists(MAINTENANCE_FILE) && $user['role'] !== 'admin') {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json');
            http_response_code(503);
            echo json_encode(['error' => 'maintenance']);
            exit;
        }
        include __DIR__ . '/maintenance.php';
        exit;
    }
    return $user;
}

function can(string $permission): bool {
    $user = current_user();
    if (!$user) return false;
    return in_array($permission, ROLE_PERMISSIONS[$user['role']] ?? []);
}

function require_permission(string $permission): void {
    require_login();
    if (!can($permission)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'forbidden', 'required' => $permission]);
        exit;
    }
}

// ── API Key Management ────────────────────────────────────────────────────────

function load_api_keys(): array {
    if (file_exists(API_KEYS_FILE))
        return json_decode(file_get_contents(API_KEYS_FILE), true) ?? [];
    return [];
}

function save_api_keys(array $keys): void {
    @mkdir(dirname(API_KEYS_FILE), 0755, true);
    file_put_contents(API_KEYS_FILE, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function generate_api_key(): string {
    return 'xv_' . bin2hex(random_bytes(24)); // 51 Zeichen, Präfix xv_
}

function find_api_key(string $key): ?array {
    foreach (load_api_keys() as $k)
        if ($k['key'] === $key && ($k['active'] ?? true)) return $k;
    return null;
}

function create_api_key(string $name, string $created_by): array {
    $keys = load_api_keys();
    $entry = [
        'id'         => bin2hex(random_bytes(8)),
        'key'        => generate_api_key(),
        'name'       => trim($name) ?: 'API Key',
        'active'     => true,
        'created_by' => $created_by,
        'created_at' => date('Y-m-d H:i:s'),
        'last_used'  => null,
        'use_count'  => 0,
    ];
    $keys[] = $entry;
    save_api_keys($keys);
    return $entry;
}

function revoke_api_key(string $id): bool {
    $keys = load_api_keys();
    foreach ($keys as &$k) {
        if ($k['id'] === $id) { $k['active'] = false; save_api_keys($keys); return true; }
    }
    return false;
}

function delete_api_key(string $id): bool {
    $keys = load_api_keys();
    $new  = array_values(array_filter($keys, fn($k) => $k['id'] !== $id));
    if (count($new) === count($keys)) return false;
    save_api_keys($new);
    return true;
}

function record_api_key_use(string $key_value): void {
    $keys = load_api_keys();
    foreach ($keys as &$k) {
        if ($k['key'] === $key_value) {
            $k['last_used'] = date('Y-m-d H:i:s');
            $k['use_count'] = ($k['use_count'] ?? 0) + 1;
            break;
        }
    }
    save_api_keys($keys);
}
