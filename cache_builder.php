#!/usr/bin/env php
<?php
/**
 * cache_builder.php – Xtream Vault Library Cache Builder
 * Baut library_cache_{id}.json, series_cache_{id}.json und
 * new_releases.json für alle konfigurierten Server auf.
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
    http_response_code(403); exit('Forbidden');
}

require_once __DIR__ . '/config.php';

if (!is_configured()) {
    echo "[CACHE] Nicht konfiguriert – abgebrochen.\n"; exit(1);
}

$lockFile = sys_get_temp_dir() . '/xtream_cache.lock';

function is_process_running(int $pid): bool {
    if ($pid <= 0) return false;
    if (function_exists('posix_kill')) return posix_kill($pid, 0);
    return file_exists('/proc/' . $pid);
}

$existingPid = file_exists($lockFile) ? (int)file_get_contents($lockFile) : 0;
if ($existingPid > 0 && is_process_running($existingPid)) {
    echo "[CACHE] Cache-Builder läuft bereits (PID {$existingPid}) – abgebrochen.\n"; exit(0);
}
file_put_contents($lockFile, getmypid());
register_shutdown_function(function() use ($lockFile) { @unlink($lockFile); });

function blog(string $msg): void {
    echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
}

function xtream_req_srv(array $server, string $action, array $extra = []): array {
    $params = array_merge([
        'username' => $server['username'],
        'password' => $server['password'] ?? '',
        'action'   => $action,
    ], $extra);
    $url = 'http://' . $server['server_ip'] . ':' . $server['port'] . '/player_api.php?' . http_build_query($params);
    $ctx = stream_context_create(['http' => ['timeout' => 30, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return [];
    return json_decode($raw, true) ?? [];
}

function load_db_local(): array {
    if (!file_exists(DOWNLOAD_DB)) return ['movies' => [], 'episodes' => []];
    return json_decode(file_get_contents(DOWNLOAD_DB), true) ?? ['movies' => [], 'episodes' => []];
}

@mkdir(DATA_DIR, 0755, true);

// ── Alle Server laden ─────────────────────────────────────────────────────────
$servers = file_exists(SERVERS_FILE)
    ? (json_decode(file_get_contents(SERVERS_FILE), true) ?? [])
    : [];

// Fallback auf config.json wenn servers.json leer (Rückwärtskompatibilität)
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

blog(sprintf('=== Cache für %d Server ===', count($servers)));

// ── Pro Server: Movie-Cache + Serien-Cache aufbauen ──────────────────────────
$allMovieCaches = []; // server_id => movieCache array

foreach ($servers as $srv) {
    $srvId   = $srv['id'];
    $srvName = $srv['name'] ?? ($srv['server_ip'] . ':' . $srv['port']);
    blog("─── Server: {$srvName} ({$srvId}) ───");

    $libCacheFile = DATA_DIR . '/library_cache_' . $srvId . '.json';
    $serCacheFile = DATA_DIR . '/series_cache_'  . $srvId . '.json';

    // Movie-Cache
    blog('  Starte Movie-Cache…');
    $movieCache = [];
    $categories = xtream_req_srv($srv, 'get_vod_categories');
    blog(sprintf('  %d Movie-Kategorien', count($categories)));
    foreach ($categories as $cat) {
        $streams = xtream_req_srv($srv, 'get_vod_streams', ['category_id' => $cat['category_id']]);
        foreach ($streams as $m) {
            $movieCache[(string)$m['stream_id']] = [
                'id'            => (string)$m['stream_id'],
                'title'         => display_title($m['name'] ?? ''),
                'cover'         => $m['stream_icon'] ?? '',
                'category'      => $cat['category_name'],
                'category_id'   => (string)$cat['category_id'],
                'ext'           => $m['container_extension'] ?? 'mp4',
                'type'          => 'movie',
                'year'          => $m['year']          ?? '',
                'rating_5based' => $m['rating_5based'] ?? '',
                'genre'         => $m['genre']         ?? '',
            ];
        }
    }
    file_put_contents($libCacheFile, json_encode($movieCache, JSON_UNESCAPED_UNICODE));
    blog(sprintf('  Movie-Cache: %d Einträge → %s', count($movieCache), basename($libCacheFile)));
    $allMovieCaches[$srvId] = $movieCache;

    // Serien-Cache
    blog('  Starte Serien-Cache…');
    $seriesCache = [];
    $seriesCats  = xtream_req_srv($srv, 'get_series_categories');
    blog(sprintf('  %d Serien-Kategorien', count($seriesCats)));
    foreach ($seriesCats as $cat) {
        $series = xtream_req_srv($srv, 'get_series', ['category_id' => $cat['category_id']]);
        foreach ($series as $s) {
            $seriesCache[(string)$s['series_id']] = [
                'series_id'   => (string)$s['series_id'],
                'clean_title' => display_title($s['name'] ?? ''),
                'cover'       => $s['cover'] ?? '',
                'category'    => $cat['category_name'],
                'genre'       => $s['genre'] ?? '',
                'type'        => 'series',
                '_server_id'  => $srvId,
            ];
        }
    }
    file_put_contents($serCacheFile, json_encode(array_values($seriesCache), JSON_UNESCAPED_UNICODE));
    blog(sprintf('  Serien-Cache: %d Einträge → %s', count($seriesCache), basename($serCacheFile)));

    // Auch aktive Server cache-Dateien aktuell halten (für Kompatibilität)
    if ($srvId === SERVER_ID) {
        file_put_contents(LIBRARY_CACHE_FILE, json_encode($movieCache, JSON_UNESCAPED_UNICODE));
        file_put_contents(DATA_DIR . '/series_cache.json', json_encode(array_values($seriesCache), JSON_UNESCAPED_UNICODE));
    }
}

// ── Neue Releases: über alle Server zusammenführen ────────────────────────────
blog('=== Neue Releases (alle Server) ===');

$newReleasesFile = DATA_DIR . '/new_releases.json';
$prev = file_exists($newReleasesFile)
    ? (json_decode(@file_get_contents($newReleasesFile), true) ?? [])
    : [];

$db_nr      = load_db_local();
$dlMovieIds = array_flip(array_map('strval', $db_nr['movies'] ?? []));

// Alle aktuellen IDs über alle Server sammeln
$allCurrentIds = [];
foreach ($allMovieCaches as $srvId => $cache) {
    foreach ($cache as $id => $m) {
        $allCurrentIds[(string)$id] = $srvId;
    }
}

// previousMovieIds: IDs aus dem letzten Run (global)
$hasAllIds        = isset($prev['all_ids']) && is_array($prev['all_ids']) && count($prev['all_ids']) > 0;
$isFirstRun       = empty($prev) || !$hasAllIds;
$previousMovieIds = $isFirstRun
    ? array_flip(array_map('strval', array_keys($allCurrentIds)))
    : array_flip(array_map('strval', $prev['all_ids'] ?? []));

// Neu hinzugefügte Server erkennen: Server deren IDs komplett fehlen in previousMovieIds
// → deren Filme direkt als bekannt markieren (kein False-Positive)
$knownServerIds = array_flip($prev['known_server_ids'] ?? []);
foreach ($allMovieCaches as $srvId => $cache) {
    if (!isset($knownServerIds[$srvId]) && !$isFirstRun) {
        // Neuer Server — alle seine IDs als bekannt markieren
        blog("  Neuer Server erkannt: {$srvId} — IDs als bekannt markiert");
        foreach ($cache as $id => $m) {
            $previousMovieIds[(string)$id] = true;
        }
    }
}

// Akkumulierte Liste aus vorherigem Run (ohne heruntergeladene)
$accMovies = [];
foreach ($prev['movies'] ?? [] as $m) {
    $id = (string)($m['stream_id'] ?? $m['id'] ?? '');
    if ($id !== '' && !isset($dlMovieIds[$id])) $accMovies[$id] = $m;
}

// Neue Filme aus allen Servern hinzufügen
if (!$isFirstRun) {
    foreach ($allCurrentIds as $id => $srvId) {
        $sid = (string)$id;
        if (!isset($previousMovieIds[$sid]) && !isset($dlMovieIds[$sid])) {
            $m = $allMovieCaches[$srvId][$sid] ?? null;
            if ($m) {
                $m['stream_id'] = $sid;
                $m['_server_id'] = $srvId;
                $accMovies[$sid] = $m;
            }
        }
    }
}

$newReleasesData = [
    'generated_at'     => date('Y-m-d H:i:s'),
    'all_ids'          => array_map('strval', array_keys($allCurrentIds)),
    'known_server_ids' => array_keys($allMovieCaches), // alle bekannten Server-IDs
    'movies'           => array_values($accMovies),
    'series'           => [],
];
file_put_contents($newReleasesFile, json_encode($newReleasesData, JSON_UNESCAPED_UNICODE));
blog(sprintf('Neue Releases: %d Filme → %s', count($accMovies), $newReleasesFile));

// ── Downloaded-Index aufbauen ─────────────────────────────────────────────────
blog('=== Baue Downloaded-Index auf ===');
$db         = load_db_local();
$dlMovies   = $db['movies']   ?? [];
$dlEpisodes = $db['episodes'] ?? [];

$existingIndex = [];
if (file_exists(DOWNLOADED_INDEX_FILE)) {
    $raw = @file_get_contents(DOWNLOADED_INDEX_FILE);
    if ($raw !== false) $existingIndex = json_decode($raw, true) ?? [];
}

// Alle Movie-Caches durchsuchen für Downloaded-Einträge
$combinedMovieCache = [];
foreach ($allMovieCaches as $cache) {
    foreach ($cache as $id => $m) {
        $combinedMovieCache[(string)$id] = $m;
    }
}

$index = [];
foreach ($dlMovies as $id) {
    $index[(string)$id] = $combinedMovieCache[(string)$id]
        ?? $existingIndex[(string)$id]
        ?? ['id'=>(string)$id,'title'=>'Film #'.$id,'cover'=>'','category'=>'','ext'=>'mp4','type'=>'movie'];
}
foreach ($dlEpisodes as $id) {
    $index[(string)$id] = $existingIndex[(string)$id]
        ?? ['id'=>(string)$id,'title'=>'Episode #'.$id,'cover'=>'','category'=>'','ext'=>'mp4','type'=>'episode'];
}

file_put_contents(DOWNLOADED_INDEX_FILE, json_encode($index, JSON_UNESCAPED_UNICODE));
blog(sprintf('Downloaded-Index: %d Einträge → %s', count($index), basename(DOWNLOADED_INDEX_FILE)));

blog('=== Done ===');
