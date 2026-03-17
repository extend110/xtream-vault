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
define('API_KEYS_FILE',      DATA_DIR . '/api_keys.json');
define('ACTIVITY_LOG_FILE',  DATA_DIR . '/activity.json');

// ── Prüfen ob Grundkonfiguration vorhanden ────────────────────────────────────
function is_configured(): bool {
    return SERVER_IP !== '' && USERNAME !== '' && PASSWORD !== '';
}

// ── Titel-Bereinigung ─────────────────────────────────────────────────────────

/**
 * Entfernt führende Länderkürzel: 'IT ', 'DE ', 'FR |', 'EN: ', 'DACH- ' …
 * Nur beim Download/Dateinamen verwenden.
 */
function remove_country_prefix(string $name): string {
    return trim(preg_replace('/^[A-Z]{2,4}[\s\|:\-]+/', '', $name));
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
 * Kein Entfernen von Länderkürzel oder Jahreszahl.
 */
function display_title(string $name): string {
    return trim($name);
}

/**
 * Für DATEINAMEN beim Download: entfernt Länderkürzel und Jahreszahl.
 */
function clean_title(string $name): string {
    $name = remove_country_prefix($name);
    $name = remove_year_suffix($name);
    return trim($name);
}
