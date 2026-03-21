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
define('DOWNLOAD_DB',        DATA_DIR . '/downloaded.json');
define('QUEUE_FILE',         DATA_DIR . '/queue.json');
define('CRON_LOG',           DATA_DIR . '/cron.log');
define('PROGRESS_FILE',      DATA_DIR . '/progress.json');
define('LIBRARY_CACHE_FILE',   DATA_DIR . '/library_cache.json');
define('SERIES_CACHE_FILE',    DATA_DIR . '/series_cache.json');
define('DOWNLOADED_INDEX_FILE', DATA_DIR . '/downloaded_index.json');
define('DOWNLOAD_HISTORY_FILE', DATA_DIR . '/download_history.json');
define('CANCEL_FILE',        DATA_DIR . '/cancel.lock');
define('MAINTENANCE_FILE',   DATA_DIR . '/maintenance.lock');
define('TMDB_API_KEY',       $_cfg['tmdb_api_key'] ?? '');
define('API_KEYS_FILE',      DATA_DIR . '/api_keys.json');
define('ACTIVITY_LOG_FILE',  DATA_DIR . '/activity.json');

// ── Prüfen ob Grundkonfiguration vorhanden ────────────────────────────────────
function is_configured(): bool {
    return SERVER_IP !== '' && USERNAME !== '' && PASSWORD !== '';
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
