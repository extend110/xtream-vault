<?php
/**
 * config.php – Zentrale Konfiguration für Xtream Vault
 * Wird von api.php UND cron.php eingebunden.
 * Liest config.json; falls nicht vorhanden → leere Defaults.
 */

define('CONFIG_FILE', __DIR__ . '/data/config.json');
define('DATA_DIR',    __DIR__ . '/data');

function load_config(): array {
    if (file_exists(CONFIG_FILE)) {
        $c = json_decode(file_get_contents(CONFIG_FILE), true);
        if (is_array($c)) return $c;
    }
    return [];
}

function save_config(array $c): bool {
    @mkdir(DATA_DIR, 0755, true);
    return file_put_contents(CONFIG_FILE, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function cfg(string $key, string $default = ''): string {
    static $config = null;
    if ($config === null) $config = load_config();
    return (string)($config[$key] ?? $default);
}

// ── Konstanten aus config.json (oder Leerstring wenn noch nicht konfiguriert) ──
$_cfg = load_config();

define('SERVER_IP',   $_cfg['server_ip']   ?? '');
define('PORT',        $_cfg['port']        ?? '80');
define('USERNAME',    $_cfg['username']    ?? '');
define('PASSWORD',    $_cfg['password']    ?? '');
define('DEST_PATH',   $_cfg['dest_path']   ?? __DIR__ . '/downloads');

// rclone-Einstellungen
define('RCLONE_ENABLED', (bool)($_cfg['rclone_enabled'] ?? false));
define('RCLONE_REMOTE',  $_cfg['rclone_remote']  ?? '');   // z.B. "gdrive"
define('RCLONE_PATH',    $_cfg['rclone_path']    ?? '');   // z.B. "Media/VOD"
define('RCLONE_BIN',     $_cfg['rclone_bin']     ?? 'rclone'); // Pfad zum rclone-Binary

define('DATA_PATH',          DATA_DIR);

// ── Server-ID: Hash aus IP + Port + Username (identifiziert den Xtream-Server) ─
$_server_id = (SERVER_IP !== '' && USERNAME !== '')
    ? substr(md5(SERVER_IP . ':' . PORT . ':' . USERNAME), 0, 8)
    : 'default';
define('SERVER_ID', $_server_id);

// ── Per-Server-Dateipfade ──────────────────────────────────────────────────────
define('DOWNLOAD_DB',           DATA_DIR . '/downloaded_' . SERVER_ID . '.json');
define('QUEUE_FILE',            DATA_DIR . '/queue_'      . SERVER_ID . '.json');
define('DOWNLOADED_INDEX_FILE', DATA_DIR . '/downloaded_index_' . SERVER_ID . '.json');
define('DOWNLOAD_HISTORY_FILE', DATA_DIR . '/download_history_' . SERVER_ID . '.json');
define('LIBRARY_CACHE_FILE',    DATA_DIR . '/library_cache_'    . SERVER_ID . '.json');
define('SERIES_CACHE_FILE',     DATA_DIR . '/series_cache_'     . SERVER_ID . '.json');

// ── Globale Dateipfade (server-unabhängig) ────────────────────────────────────
define('CRON_LOG',           DATA_DIR . '/cron.log');
define('PROGRESS_FILE',      DATA_DIR . '/progress.json');
define('CANCEL_FILE',        DATA_DIR . '/cancel.lock');
define('MAINTENANCE_FILE',   DATA_DIR . '/maintenance.lock');
define('API_KEYS_FILE',      DATA_DIR . '/api_keys.json');
define('ACTIVITY_LOG_FILE',  DATA_DIR . '/activity.json');
define('TMDB_API_KEY',       $_cfg['tmdb_api_key']    ?? '');
define('SERVERS_FILE',       DATA_DIR . '/servers.json');
define('INVITES_FILE',       DATA_DIR . '/invites.json');
define('NEW_RELEASES_FILE',  DATA_DIR . '/new_releases.json');

// ── VPN (WireGuard) ───────────────────────────────────────────────────────────
define('VPN_INTERFACE',  preg_replace('/[^a-zA-Z0-9_\-]/', '', $_cfg['vpn_interface'] ?? 'wg0'));
define('VPN_ENABLED',    VPN_INTERFACE !== ''); // immer aktiv wenn Interface konfiguriert
define('VPN_RT_TABLE',   51820);

/**
 * Prüft ob WireGuard (wg-quick) installiert ist.
 */
function vpn_wg_installed(): bool {
    exec('which wg-quick 2>/dev/null', $out, $ret);
    return $ret === 0 && !empty($out);
}

/**
 * Prüft ob das WireGuard-Interface aktiv ist.
 */
function vpn_is_up(): bool {
    if (!vpn_wg_installed()) return false;
    if (VPN_INTERFACE === '') return false;
    exec('/usr/sbin/ip link show ' . escapeshellarg(VPN_INTERFACE) . ' 2>/dev/null', $out, $ret);
    return $ret === 0 && !empty(array_filter($out, fn($l) => str_contains($l, 'UP')));
}

/**
 * Startet WireGuard via wg-quick, entfernt dann die system-weiten Routing-Regeln
 * die wg-quick automatisch setzt, und ersetzt sie durch eine UID-spezifische Regel
 * die nur www-data durch den Tunnel schickt.
 */
function vpn_up(): bool|string {
    if (!vpn_wg_installed()) return 'WireGuard nicht installiert — bitte "apt install wireguard" ausführen';
    if (VPN_INTERFACE === '') return 'Kein Interface konfiguriert';

    $iface = VPN_INTERFACE;
    $table = VPN_RT_TABLE;
    $uid   = trim(shell_exec('id -u www-data 2>/dev/null') ?: '');
    if ($uid === '') return 'www-data UID nicht ermittelbar';

    ob_start(); // Unerwartete Ausgabe von wg-quick/sudo abfangen

    // Interface hochfahren
    if (!vpn_is_up()) {
        exec('sudo /usr/bin/wg-quick up ' . escapeshellarg($iface) . ' 2>&1', $out, $ret);
        if ($ret !== 0) { ob_end_clean(); return 'wg-quick up: ' . (implode(' ', $out) ?: 'Fehler'); }
        sleep(1);
        @file_put_contents(DATA_DIR . '/vpn_connected_at.txt', (string)time());
    }

    // wg-quick setzt automatisch system-weite ip rule Einträge mit fwmark 51820.
    // Diese leiten Traffic ALLER User durch den Tunnel — das müssen wir rückgängig machen.
    exec("sudo /usr/sbin/ip rule show 2>/dev/null", $rules);
    foreach ($rules as $rule) {
        if (preg_match('/lookup\s+51820/', $rule) && !str_contains($rule, "uidrange {$uid}")) {
            if (preg_match('/^(\d+):/', trim($rule), $m)) {
                exec("sudo /usr/sbin/ip rule del priority {$m[1]} 2>/dev/null");
            }
        }
    }
    exec("sudo /usr/sbin/ip -6 rule show 2>/dev/null", $rules6);
    foreach ($rules6 as $rule) {
        if (preg_match('/lookup\s+51820/', $rule) && !str_contains($rule, "uidrange {$uid}")) {
            if (preg_match('/^(\d+):/', trim($rule), $m)) {
                exec("sudo /usr/sbin/ip -6 rule del priority {$m[1]} 2>/dev/null");
            }
        }
    }

    exec("sudo /usr/sbin/ip rule add uidrange {$uid}-{$uid} lookup {$table} priority 100 2>/dev/null");

    ob_end_clean();
    return true;
}

/**
 * Stoppt WireGuard und entfernt die Routing-Regeln.
 */
function vpn_down(): bool|string {
    if (!vpn_wg_installed()) return 'WireGuard nicht installiert';
    if (VPN_INTERFACE === '') return 'Kein Interface konfiguriert';

    $uid   = trim(shell_exec('id -u www-data 2>/dev/null') ?: '');
    $table = VPN_RT_TABLE;
    $iface = VPN_INTERFACE;

    ob_start(); // Unerwartete Ausgabe von wg-quick/sudo abfangen

    if ($uid !== '') {
        exec("sudo /usr/sbin/ip rule del uidrange {$uid}-{$uid} lookup {$table} priority 100 2>/dev/null");
        exec("sudo /usr/sbin/ip -6 rule del uidrange {$uid}-{$uid} lookup {$table} priority 100 2>/dev/null");
    }

    if (vpn_is_up()) {
        exec('sudo /usr/bin/wg-quick down ' . escapeshellarg($iface) . ' 2>&1', $out, $ret);
        if ($ret !== 0) { ob_end_clean(); return 'wg-quick down: ' . (implode(' ', $out) ?: 'Fehler'); }
    }
    @unlink(DATA_DIR . '/vpn_connected_at.txt');

    ob_end_clean();
    return true;
}
/** Gibt true zurück wenn VPN manuell über die UI verbunden wurde (nicht durch den Cron). */
function vpn_is_manual(): bool {
    return file_exists(DATA_DIR . '/vpn_manual.flag');
}

// ── Prüfen ob Grundkonfiguration vorhanden ────────────────────────────────────
function is_configured(): bool {
    // Primär: config.json hat Server-Zugangsdaten (Rückwärtskompatibilität)
    if (SERVER_IP !== '' && USERNAME !== '' && PASSWORD !== '') return true;
    // Alternativ: mindestens ein Server in servers.json
    if (defined('SERVERS_FILE') && file_exists(SERVERS_FILE)) {
        $servers = json_decode(file_get_contents(SERVERS_FILE), true) ?? [];
        return count($servers) > 0;
    }
    return false;
}

// ── Titel-Bereinigung ─────────────────────────────────────────────────────────

// Bekannte Länderkürzel — nur diese werden ohne Trennzeichen erkannt
define('COUNTRY_CODES', ['DE', 'AT', 'CH', 'US', 'UK', 'GB', 'FR', 'IT', 'ES', 'PT',
                         'NL', 'BE', 'PL', 'RU', 'TR', 'AR', 'MX', 'BR', 'JP', 'CN',
                         'KR', 'IN', 'AU', 'CA', 'SE', 'NO', 'DK', 'FI', 'CZ', 'HU',
                         'RO', 'GR', 'IL', 'ZA', 'AE', 'SA', 'DACH', 'LATAM', 'MULTI']);

/**
 * Extrahiert das führende Länderkürzel aus einem Titel.
 * Erkennt sowohl mit Trennzeichen ('DE | Film', 'US: Movie')
 * als auch ohne ('DEFilm', 'USMovie') – aber nur bekannte Kürzel.
 * Gibt das Kürzel zurück (z.B. 'DE') oder '' wenn keins gefunden.
 */
function extract_country_prefix(string $name): string {
    $name = preg_replace('/\xc2\xa0|\s+/', ' ', $name);
    $name = trim($name);
    // Mit Standard-Trennzeichen
    if (preg_match('/^([A-Z]{2,4})[\s\|:\-]+/', $name, $m)) return $m[1];
    // Mit Unicode-Sonderzeichen als Trennzeichen (z.B. ┃)
    if (preg_match('/^([A-Z]{2,4})[^\p{L}\p{N}\s]+/u', $name, $m)) return $m[1];
    // Ohne Trennzeichen: nur bekannte Kürzel
    foreach (COUNTRY_CODES as $code) {
        $len = strlen($code);
        if (strncmp($name, $code, $len) === 0) {
            $rest = substr($name, $len);
            if ($rest !== '' && (ctype_upper($rest[0]) || $rest[0] === ' ')) return $code;
        }
    }
    return '';
}

function remove_country_prefix(string $name): string {
    // Normalisieren: non-breaking spaces und alle sonstigen Whitespace-Varianten → normales Leerzeichen
    $name = preg_replace('/\xc2\xa0|\s+/', ' ', $name);
    $name = trim($name);
    // Mit Standard-Trennzeichen (Leerzeichen, |, :, -)
    $stripped = preg_replace('/^[A-Z]{2,4}[\s\|:\-]+/', '', $name);
    if ($stripped !== $name) return trim($stripped);
    // Mit Unicode-Sonderzeichen als Trennzeichen (z.B. ┃ U+2503, │ U+2502, ‖ etc.)
    $stripped = preg_replace('/^[A-Z]{2,4}[^\p{L}\p{N}\s]+/u', '', $name);
    if ($stripped !== $name) return trim($stripped);
    // Ohne Trennzeichen: bekannte Kürzel
    foreach (COUNTRY_CODES as $code) {
        $len = strlen($code);
        if (strncmp($name, $code, $len) === 0) {
            $rest = substr($name, $len);
            if ($rest !== '' && (ctype_upper($rest[0]) || $rest[0] === ' ')) {
                return trim($rest);
            }
        }
    }
    return $name;
}

/**
 * Extrahiert eine Jahreszahl aus dem Titel (erste vierstellige 19xx/20xx Zahl).
 * Gibt das Jahr als String zurück (z.B. '2010') oder '' wenn keins gefunden.
 */
function extract_year(string $name): string {
    if (preg_match('/\b((?:19|20)\d{2})\b/', $name, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Entfernt angehängte Jahreszahlen: 'Film (2010)', 'Film - 2010', 'Film 2010'
 * Nur beim Download/Dateinamen verwenden.
 */
function remove_year_suffix(string $name): string {
    return trim(preg_replace('/[\s\-_]+\(?\d{4}\)?$/', '', $name));
}

/**
 * Für die ANZEIGE im Browser: gibt den Originaltitel unverändert zurück.
 */
function display_title(string $name): string {
    return trim($name);
}

/**
 * Bereinigt einen String für die Verwendung als Dateiname.
 */
function safe_filename(string $name): string {
    $name = str_replace(':', '-', $name);
    $name = preg_replace('/[<>"|?*\/\\\\]/', '', $name);
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
    $name = preg_replace('/[^\p{L}\p{N}\s\-_\.]/u', '', $name);
    return trim(preg_replace('/\s+/', ' ', $name)) ?: 'file';
}

/**
 * Berechnet den relativen Zielpfad für einen Download.
 * Wird von cron.php (Download) und api.php (Queue-Add-Prüfung) verwendet.
 *
 * @param array $item  Queue-Item mit title, type, category, container_extension, season, episode_num
 * @return array       ['rel_path' => string, 'filename' => string, 'safe_title' => string]
 */
function build_dest_path(array $item): array {
    $title  = $item['title']  ?? '';
    $ext    = $item['container_extension'] ?? 'mp4';
    $type   = $item['type']   ?? 'movie';
    $cat    = !empty($item['category']) ? $item['category'] : 'Uncategorized';
    $season = isset($item['season']) && $item['season'] !== null ? (int)$item['season'] : null;
    $sub    = $item['dest_subfolder'] ?? ($type === 'movie' ? 'Movies' : 'TV Shows');

    // Länderkürzel — zuerst aus Originalkategorie, dann aus Kategorie, dann aus Titel
    $catForPrefix = !empty($item['category_original']) ? $item['category_original'] : $cat;
    $countryPrefix = extract_country_prefix($catForPrefix);
    if ($countryPrefix === '') $countryPrefix = extract_country_prefix($cat);
    if ($countryPrefix === '') $countryPrefix = extract_country_prefix($title);

    // Kategorie bereinigen
    $catTrimmed = preg_replace('/\xc2\xa0/', ' ', trim($cat));
    $cleanCat   = remove_country_prefix($catTrimmed);
    if ($cleanCat === $catTrimmed) {
        $cleanCat = preg_replace('/^[A-Z]{2,4}\s+/u', '', $catTrimmed);
    }
    $cleanCat = trim($cleanCat) ?: $catTrimmed;
    $safeCat  = safe_filename($cleanCat) ?: 'Uncategorized';
    $safeCat  = trim($safeCat, ' -_.') ?: 'Uncategorized';

    $safePrefix = $countryPrefix !== '' ? $countryPrefix : '';

    if ($type === 'episode') {
        if ($safePrefix !== '' && str_starts_with($safeCat, $safePrefix)) {
            $safeCat = trim(substr($safeCat, strlen($safePrefix)), ' -_.');
            $safeCat = $safeCat ?: 'Uncategorized';
        }
        if ($safePrefix === '') {
            $safePrefix = extract_country_prefix($safeCat);
            $safeCat    = remove_country_prefix($safeCat);
            $safeCat    = trim($safeCat, ' -_.') ?: 'Uncategorized';
        }
    }

    // Dateiname
    $fileTitle = clean_title($title);
    if ($type !== 'movie') {
        $seriesBase = $safeCat ?: 'Unknown';
        $episode = '';
        if (preg_match('/[Ss](\d{1,2})[Ee](\d{1,2})/u', $title, $m)) {
            $episode = sprintf('S%02dE%02d', (int)$m[1], (int)$m[2]);
        }
        if ($episode === '' && isset($item['season'], $item['episode_num'])) {
            $episode = sprintf('S%02dE%02d', (int)$item['season'], (int)$item['episode_num']);
        }
        $fileTitle = $episode !== '' ? $seriesBase . '.' . $episode : $seriesBase;
    }
    $safeTitle = safe_filename($fileTitle) ?: safe_filename($title) ?: 'item_' . ($item['stream_id'] ?? '0');
    $safeTitle = trim($safeTitle, ' -_.') ?: 'item_' . ($item['stream_id'] ?? '0');

    // Pfad zusammenbauen
    $sep = DIRECTORY_SEPARATOR;
    if ($type === 'episode') {
        $staffel = $season !== null ? ('Staffel ' . $season) : '';
        $relPath = $sub
            . ($safePrefix !== '' ? $sep . $safePrefix : '')
            . $sep . $safeCat
            . ($staffel !== '' ? $sep . $staffel : '')
            . $sep . $safeTitle . '.' . $ext;
    } else {
        $relPath = $sub
            . ($safePrefix !== '' ? $sep . $safePrefix : '')
            . $sep . $safeCat
            . $sep . $safeTitle . '.' . $ext;
    }

    return [
        'rel_path'   => $relPath,
        'filename'   => $safeTitle . '.' . $ext,
        'safe_title' => $safeTitle,
    ];
}

/**
 * Für DATEINAMEN beim Download:
 * - Entfernt Länderkürzel
 * - Behält Jahreszahl (wird als .YYYY ans Ende gestellt, Plex/Jellyfin-kompatibel)
 * Beispiel: 'DE Der Pate - 1972' → 'Der Pate.1972'
 */
function clean_title(string $name): string {
    $year  = extract_year($name);
    $name  = remove_country_prefix($name);
    $name  = remove_year_suffix($name);
    $name  = trim($name);
    if ($year !== '') {
        $name .= '.' . $year;
    }
    return $name;
}

// ── i18n ─────────────────────────────────────────────────────────────────────
function get_user_lang(): string {
    // Aus Session (gesetzt nach Login)
    if (!empty($_SESSION['lang'])) return $_SESSION['lang'];
    return 'de'; // Standard: Deutsch
}

function load_lang(string $lang = ''): array {
    if ($lang === '') $lang = get_user_lang();
    $file = __DIR__ . '/lang/' . preg_replace('/[^a-z]/', '', $lang) . '.php';
    if (!file_exists($file)) $file = __DIR__ . '/lang/de.php';
    return file_exists($file) ? (require $file) : [];
}

function t(string $key, array $vars = []): string {
    static $strings = null;
    if ($strings === null) $strings = load_lang();
    $str = $strings[$key] ?? $key;
    foreach ($vars as $k => $v) {
        $str = str_replace('{{' . $k . '}}', $v, $str);
    }
    return $str;
}
