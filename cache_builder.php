#!/usr/bin/env php
<?php
/**
 * cache_builder.php – Xtream Vault Library Cache Builder
 * ────────────────────────────────────────────────────────
 * Baut library_cache.json und series_cache.json auf.
 * Läuft im Hintergrund – niemals direkt via HTTP aufrufbar.
 *
 * Wird aufgerufen von:
 *   1. cron.php  – automatisch nach jedem Download-Run
 *   2. api.php (rebuild_library_cache) – manuell durch Admin
 *
 * Cronjob-Beispiel (täglich um 4 Uhr morgens für frischen Cache):
 *   0 4 * * * php /var/www/html/xtream/cache_builder.php
 */

// HTTP-Zugriff blockieren
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
    // Wenn über Web aufgerufen trotzdem blockieren
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';

if (!is_configured()) {
    echo "[CACHE] Nicht konfiguriert – abgebrochen.\n";
    exit(1);
}

// ── Lock: PID-basiert, überlebt keine Abstürze ───────────────────────────────
$lockFile = sys_get_temp_dir() . '/xtream_cache.lock';

function is_process_running(int $pid): bool {
    if ($pid <= 0) return false;
    // posix_kill mit Signal 0 prüft nur ob Prozess existiert, sendet kein Signal
    if (function_exists('posix_kill')) return posix_kill($pid, 0);
    // Fallback: /proc-Verzeichnis prüfen (Linux)
    return file_exists('/proc/' . $pid);
}

$existingPid = file_exists($lockFile) ? (int)file_get_contents($lockFile) : 0;
if ($existingPid > 0 && is_process_running($existingPid)) {
    echo "[CACHE] Cache-Builder läuft bereits (PID {$existingPid}) – abgebrochen.\n";
    exit(0);
}

// Eigene PID speichern
file_put_contents($lockFile, getmypid());

// Lock-File beim Beenden (auch bei Fehlern) aufräumen
register_shutdown_function(function() use ($lockFile) {
    @unlink($lockFile);
});

// ── Logging ───────────────────────────────────────────────────────────────────
function blog(string $msg): void {
    echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
}

// ── Xtream-Request ────────────────────────────────────────────────────────────
function xtream_req(string $action, array $extra = []): array {
    $params = array_merge([
        'username' => USERNAME,
        'password' => PASSWORD,
        'action'   => $action,
    ], $extra);
    $url = 'http://' . SERVER_IP . ':' . PORT . '/player_api.php?' . http_build_query($params);
    $ctx = stream_context_create(['http' => [
        'timeout' => 30,
        'header'  => "User-Agent: Mozilla/5.0\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return [];
    return json_decode($raw, true) ?? [];
}

@mkdir(DATA_PATH, 0755, true);

// ── Schritt 1: Movie-Cache aufbauen ───────────────────────────────────────────
blog('=== Starte Movie-Cache Aufbau ===');
$movieCache = [];
$categories = xtream_req('get_vod_categories');
blog(sprintf('  %d Movie-Kategorien gefunden', count($categories)));

foreach ($categories as $cat) {
    $streams = xtream_req('get_vod_streams', ['category_id' => $cat['category_id']]);
    foreach ($streams as $m) {
        $movieCache[(string)$m['stream_id']] = [
            'id'       => (string)$m['stream_id'],
            'title'    => display_title($m['name'] ?? ''),
            'cover'    => $m['stream_icon'] ?? '',
            'category' => $cat['category_name'],
            'ext'      => $m['container_extension'] ?? 'mp4',
            'type'     => 'movie',
        ];
    }
    blog(sprintf('  Kategorie "%s": %d Streams', $cat['category_name'], count($streams)));
}

file_put_contents(LIBRARY_CACHE_FILE, json_encode($movieCache, JSON_UNESCAPED_UNICODE));
blog(sprintf('Movie-Cache gespeichert: %d Einträge → %s', count($movieCache), LIBRARY_CACHE_FILE));

// ── Schritt 2: Serien-Cache ───────────────────────────────────────────────────
// Der vollständige Serien-Cache (get_series_info für jede Serie) ist zu aufwendig
// und wird NICHT mehr vom cache_builder aufgebaut. Episoden-Metadaten werden
// stattdessen direkt beim Download durch cron.php in downloaded_index.json gespeichert.
blog('Serien-Cache: wird durch cron.php beim Download befüllt (kein vollständiger Aufbau).');

// ── Schritt 3: Downloaded-Index aufbauen ──────────────────────────────────────
blog('=== Baue Downloaded-Index auf ===');

function load_db_local(): array {
    if (!file_exists(DOWNLOAD_DB)) return ['movies' => [], 'episodes' => []];
    return json_decode(file_get_contents(DOWNLOAD_DB), true) ?? ['movies' => [], 'episodes' => []];
}

$db         = load_db_local();
$dlMovies   = $db['movies']   ?? [];
$dlEpisodes = $db['episodes'] ?? [];

// Bestehenden Index laden um Episoden-Einträge (von cron.php) zu erhalten
$existingIndex = [];
if (file_exists(DOWNLOADED_INDEX_FILE)) {
    $raw = @file_get_contents(DOWNLOADED_INDEX_FILE);
    if ($raw !== false) $existingIndex = json_decode($raw, true) ?? [];
}

$index = [];
// Filme aus frischem Movie-Cache
foreach ($dlMovies as $id)
    $index[(string)$id] = $movieCache[(string)$id]
        ?? $existingIndex[(string)$id]
        ?? ['id'=>(string)$id,'title'=>'Film #'.$id,'cover'=>'','category'=>'','ext'=>'mp4','type'=>'movie'];

// Episoden aus bestehendem Index (von cron.php befüllt)
foreach ($dlEpisodes as $id)
    $index[(string)$id] = $existingIndex[(string)$id]
        ?? ['id'=>(string)$id,'title'=>'Episode #'.$id,'cover'=>'','category'=>'','ext'=>'mp4','type'=>'episode'];

file_put_contents(DOWNLOADED_INDEX_FILE, json_encode($index, JSON_UNESCAPED_UNICODE));
blog(sprintf('Downloaded-Index gespeichert: %d Einträge → %s', count($index), DOWNLOADED_INDEX_FILE));

blog('=== Done ===');
// Lock-File wird automatisch durch register_shutdown_function entfernt
