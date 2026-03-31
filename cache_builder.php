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
    $line = '[' . date('H:i:s') . '] [CACHE] ' . $msg;
    echo $line . PHP_EOL;
    if (defined('CRON_LOG')) {
        @file_put_contents(CRON_LOG, $line . PHP_EOL, FILE_APPEND);
    }
}

function cache_tg_notify(string $msg): void {
    $cfg    = load_config();
    if (!($cfg['telegram_enabled'] ?? false)) return;
    $token  = $cfg['telegram_bot_token'] ?? '';
    $chatId = $cfg['telegram_chat_id']   ?? '';
    if ($token === '' || $chatId === '') return;
    $url  = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $body = json_encode(['chat_id' => $chatId, 'text' => $msg, 'parse_mode' => 'HTML']);
    $ctx  = stream_context_create(['http' => ['method' => 'POST',
        'header' => "Content-Type: application/json\r\nUser-Agent: XtreamVault/1.0\r\n",
        'content' => $body, 'timeout' => 8]]);
    @file_get_contents($url, false, $ctx);
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
$_cbStartTime = microtime(true);

// ── Alle Server laden ─────────────────────────────────────────────────────────
$servers = file_exists(SERVERS_FILE)
    ? (json_decode(file_get_contents(SERVERS_FILE), true) ?? [])
    : [];

// Deaktivierte Server überspringen
$servers = array_values(array_filter($servers, fn($s) => ($s['enabled'] ?? true) !== false));

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
    $t0 = microtime(true);
    blog('  Starte Movie-Cache…');
    $movieCache = [];
    $categories = xtream_req_srv($srv, 'get_vod_categories');
    if (empty($categories)) {
        blog('  WARN: Keine Movie-Kategorien empfangen — Server erreichbar?');
    } else {
        blog(sprintf('  %d Movie-Kategorien gefunden', count($categories)));
        foreach ($categories as $cat) {
            $streams = xtream_req_srv($srv, 'get_vod_streams', ['category_id' => $cat['category_id']]);
            if (empty($streams)) continue;
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
    }
    file_put_contents($libCacheFile, json_encode($movieCache, JSON_UNESCAPED_UNICODE));
    blog(sprintf('  Movie-Cache: %d Einträge → %s (%.1fs)',
        count($movieCache), basename($libCacheFile), microtime(true) - $t0));
    $allMovieCaches[$srvId] = $movieCache;

    // Serien-Cache
    $t1 = microtime(true);
    blog('  Starte Serien-Cache…');
    $seriesCache = [];
    $seriesCats  = xtream_req_srv($srv, 'get_series_categories');
    if (empty($seriesCats)) {
        blog('  WARN: Keine Serien-Kategorien empfangen');
    } else {
        blog(sprintf('  %d Serien-Kategorien gefunden', count($seriesCats)));
        foreach ($seriesCats as $cat) {
            $series = xtream_req_srv($srv, 'get_series', ['category_id' => $cat['category_id']]);
            if (empty($series)) continue;
            foreach ($series as $s) {
                $seriesCache[(string)$s['series_id']] = [
                    'series_id'   => (string)$s['series_id'],
                    'clean_title' => display_title($s['name'] ?? ''),
                    'cover'       => $s['cover'] ?? '',
                    'category'    => $cat['category_name'],
                    'category_id' => (string)$cat['category_id'],
                    'genre'       => $s['genre'] ?? '',
                    'type'        => 'series',
                    '_server_id'  => $srvId,
                ];
            }
        }
    }
    file_put_contents($serCacheFile, json_encode(array_values($seriesCache), JSON_UNESCAPED_UNICODE));
    blog(sprintf('  Serien-Cache: %d Einträge → %s (%.1fs)',
        count($seriesCache), basename($serCacheFile), microtime(true) - $t1));

    // Fallback-Kopie für LIBRARY_CACHE_FILE (Rückwärtskompatibilität)
    if ($srvId === SERVER_ID) {
        file_put_contents(LIBRARY_CACHE_FILE, json_encode($movieCache, JSON_UNESCAPED_UNICODE));
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
// Wenn known_server_ids fehlt aber all_ids vorhanden ist → alle aktuellen Server als bekannt behandeln
$knownServerIds = array_flip($prev['known_server_ids'] ?? []);
$allIdsKnownServerFallback = !empty($prev['all_ids']) && empty($prev['known_server_ids']);
foreach ($allMovieCaches as $srvId => $cache) {
    if ((!isset($knownServerIds[$srvId]) || $allIdsKnownServerFallback) && !$isFirstRun) {
        // Neuer Server oder Migration ohne known_server_ids — alle seine IDs als bekannt markieren
        blog("  Server als bekannt markiert: {$srvId}");
        foreach ($cache as $id => $m) {
            $previousMovieIds[(string)$id] = true;
        }
    }
}

// Akkumulierte Liste aus vorherigem Run (ohne heruntergeladene und ohne dismissed)
$dismissedIds = array_flip(array_map('strval', $prev['dismissed_ids'] ?? []));
$accMovies = [];
foreach ($prev['movies'] ?? [] as $m) {
    $id = (string)($m['stream_id'] ?? $m['id'] ?? '');
    if ($id !== '' && !isset($dlMovieIds[$id]) && !isset($dismissedIds[$id])) $accMovies[$id] = $m;
}

// Neue Filme aus allen Servern hinzufügen (dismissed ebenfalls ausschließen)
if (!$isFirstRun) {
    foreach ($allCurrentIds as $id => $srvId) {
        $sid = (string)$id;
        if (!isset($previousMovieIds[$sid]) && !isset($dlMovieIds[$sid]) && !isset($dismissedIds[$sid])) {
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
    'known_server_ids' => array_keys($allMovieCaches),
    'dismissed_ids'    => array_values($prev['dismissed_ids'] ?? []), // beibehalten
    'movies'           => array_values($accMovies),
    'series'           => [],
];
file_put_contents($newReleasesFile, json_encode($newReleasesData, JSON_UNESCAPED_UNICODE));
blog(sprintf('Neue Releases: %d Filme → %s', count($accMovies), $newReleasesFile));

// Telegram-Benachrichtigung wenn neue Releases gefunden
$newCount = count($accMovies);
$prevCount = count(array_filter($prev['movies'] ?? [], fn($m) => !isset($dismissedIds[(string)($m['stream_id'] ?? $m['id'] ?? '')])));
$actuallyNew = $newCount - $prevCount;
if (!$isFirstRun && $actuallyNew > 0) {
    $cfg2 = load_config();
    if (!($cfg2['tg_notify_new_releases'] ?? false)) goto skip_tg_new_releases;
    $appTitle = cfg('app_title', 'Xtream Vault');
    $titles = array_slice(array_values($accMovies), max(0, $newCount - $actuallyNew), min(5, $actuallyNew));
    $titleList = implode("\n", array_map(
        fn($m) => '• ' . htmlspecialchars($m['clean_title'] ?? $m['title'] ?? '', ENT_XML1),
        $titles
    ));
    $more = $actuallyNew > 5 ? "\n<i>+ " . ($actuallyNew - 5) . " weitere</i>" : '';
    cache_tg_notify("🆕 <b>{$appTitle}</b> — {$actuallyNew} neue Filme\n\n{$titleList}{$more}");
    blog("Telegram: {$actuallyNew} neue Releases gemeldet");
}
skip_tg_new_releases:

// ── Auto-Queue neue Releases ──────────────────────────────────────────────────
$cfgAq = load_config();
if (!$isFirstRun && ($cfgAq['autoqueue_enabled'] ?? false) && $actuallyNew > 0) {
    $aqMax = max(1, (int)($cfgAq['autoqueue_max'] ?? 10));

    // Hilfsfunktionen (analog zu api.php)
    $aq_sanitize = fn(string $n): string => trim(preg_replace('/\s+/u', ' ',
        preg_replace('/[^\p{L}\p{N}\s\-_\.]/u', '',
        preg_replace('/[<>:"|?*]/u', '',
        str_replace(['/', '\\'], '-', $n))))) ?: 'Unknown';

    $aq_stream_url = fn(array $srv, string $sid, string $ext): string =>
        'http://' . $srv['server_ip'] . ':' . $srv['port']
        . '/movie/' . $srv['username'] . '/' . ($srv['password'] ?? '') . "/{$sid}.{$ext}";

    // Server-Map für stream_url Aufbau
    $allSrvMap = [];
    foreach (file_exists(SERVERS_FILE) ? (json_decode(@file_get_contents(SERVERS_FILE), true) ?? []) : [] as $s) {
        $allSrvMap[$s['id']] = $s;
    }

    // Neue Filme (noch nicht heruntergeladen)
    $dbAq       = load_db_local();
    $dlMovieIds = array_flip(array_map('strval', $dbAq['movies'] ?? []));
    $newMovies  = array_slice(
        array_filter(array_values($accMovies), fn($m) =>
            !isset($dlMovieIds[(string)($m['stream_id'] ?? $m['id'] ?? '')])),
        0, $aqMax
    );

    if (!empty($newMovies)) {
        // Queue-Dateien pro Server einmalig laden
        $queues    = [];
        $queuedIds = [];
        foreach ($newMovies as $m) {
            $srvId = $m['_server_id'] ?? '';
            if ($srvId === '' || isset($queues[$srvId])) continue;
            $qf = DATA_DIR . '/queue_' . $srvId . '.json';
            $queues[$srvId]    = file_exists($qf) ? (json_decode(@file_get_contents($qf), true) ?? []) : [];
            $queuedIds[$srvId] = array_flip(array_map('strval', array_column($queues[$srvId], 'stream_id')));
        }

        $added = 0;
        foreach ($newMovies as $m) {
            $sid   = (string)($m['stream_id'] ?? $m['id'] ?? '');
            $srvId = $m['_server_id'] ?? '';
            if ($sid === '' || $srvId === '' || isset($queuedIds[$srvId][$sid])) continue;
            $qf    = DATA_DIR . '/queue_' . $srvId . '.json';
            $ext   = $m['ext'] ?? 'mp4';
            $srv   = $allSrvMap[$srvId] ?? null;
            $title = $aq_sanitize($m['clean_title'] ?? $m['title'] ?? 'Unknown');
            $queues[$srvId][] = [
                'stream_id'           => $sid,
                'type'                => 'movie',
                'title'               => $title,
                'container_extension' => $ext,
                'cover'               => $m['cover'] ?? '',
                'dest_subfolder'      => 'Movies',
                'category'            => $m['category'] ?? '',
                'category_original'   => $m['category'] ?? '',
                'priority'            => 2,
                'stream_url'          => $srv ? $aq_stream_url($srv, $sid, $ext) : '',
                'added_at'            => date('Y-m-d H:i:s'),
                'added_by'            => 'auto-queue',
                'status'              => 'pending',
                'error'               => null,
                '_queue_file'         => $qf,
                '_server_id'          => $srvId,
            ];
            $queuedIds[$srvId][$sid] = true;
            $added++;
        }

        foreach ($queues as $srvId => $q) {
            @file_put_contents(DATA_DIR . '/queue_' . $srvId . '.json', json_encode($q, JSON_UNESCAPED_UNICODE));
        }

        if ($added > 0) blog("Auto-Queue: {$added} neue Filme zur Queue hinzugefügt");
    }
}

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

$totalTime = microtime(true) - $_cbStartTime;
blog(sprintf('=== Done (%.1fs gesamt) ===', $totalTime));
