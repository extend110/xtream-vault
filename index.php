<?php
require_once __DIR__ . '/auth.php';
$user = require_login();
$role = $user['role'];
$can_queue_view   = in_array('queue_view',   ROLE_PERMISSIONS[$role]);
$can_queue_add    = in_array('queue_add',    ROLE_PERMISSIONS[$role]);
$can_queue_remove     = in_array('queue_remove',     ROLE_PERMISSIONS[$role]);
$can_queue_remove_own = in_array('queue_remove_own', ROLE_PERMISSIONS[$role]);
$can_queue_clear  = in_array('queue_clear',  ROLE_PERMISSIONS[$role]);
$can_cron_log     = in_array('cron_log',     ROLE_PERMISSIONS[$role]);
$can_settings     = in_array('settings',     ROLE_PERMISSIONS[$role]);
$can_users        = in_array('users',        ROLE_PERMISSIONS[$role]);

// Feature-Flags für editor/viewer (Admins sehen immer alles)
$_cfg = load_config();
$show_movies = $can_settings || (bool)($_cfg['editor_movies_enabled'] ?? true);
$show_series = $can_settings || (bool)($_cfg['editor_series_enabled'] ?? true);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(cfg('app_title', 'Xtream Vault')) ?></title>
<link rel="icon" type="image/svg+xml" href="logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Mono:wght@300;400;500&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__.'/style.css') ?>">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>
<body>
<div class="app">

<!-- ── Sidebar ──────────────────────────────────────────────── -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-text"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 160" style="width:22px;height:18px;vertical-align:middle;margin-right:8px;color:var(--accent)" fill="none" stroke="currentColor"><rect x="2.5" y="2.5" width="195" height="155" rx="30" stroke-width="10"/><line x1="100" y1="25" x2="100" y2="92" stroke-width="18" stroke-linecap="round"/><path d="M52 68 L100 116 L148 68" stroke-width="18" stroke-linecap="round" stroke-linejoin="round"/><line x1="48" y1="135" x2="152" y2="135" stroke-width="18" stroke-linecap="round"/></svg><?= htmlspecialchars(cfg('app_title', 'Xtream Vault')) ?></div>
    <div class="logo-sub">VOD Downloader</div>
  </div>
  <div class="sidebar-stats">
    <div class="stat-box"><div class="stat-num" id="stat-movies">–</div><div class="stat-label"><?= t('nav.movies') ?></div></div>
    <div class="stat-box"><div class="stat-num" id="stat-episodes">–</div><div class="stat-label"><?= t('nav.series') ?></div></div>
    <?php if ($can_queue_view): ?>
    <div class="stat-box queue-stat"><div class="stat-num" id="stat-queued">0</div><div class="stat-label"><?= t('filter.queued') ?></div></div>
    <?php endif; ?>
  </div>
  <nav class="nav">
    <div class="nav-section-title"><?= t('nav.navigate') ?></div>
    <div class="nav-item active" data-view="dashboard" onclick="showView('dashboard')"><span class="nav-icon">⬛</span><?= t('nav.dashboard') ?></div>
    <?php if ($show_movies): ?>
    <div class="nav-item" data-view="movies" onclick="toggleCats('movies')"><span class="nav-icon">🎬</span><?= t('nav.movies') ?></div>
    <div class="category-list" id="cats-movies"></div>
    <?php endif; ?>
    <?php if ($show_series): ?>
    <div class="nav-item" data-view="series" onclick="toggleCats('series')"><span class="nav-icon">📺</span><?= t('nav.series') ?></div>
    <div class="category-list" id="cats-series"></div>
    <?php endif; ?>
    <div class="nav-section-title" style="margin-top:8px">Tools</div>
    <div class="nav-item" onclick="showView('favourites')"><span class="nav-icon">♥</span><?= t('nav.favourites') ?> <span class="nav-badge" id="fav-badge" style="display:none">0</span></div>
    <div class="nav-item" onclick="showView('new-releases')"><span class="nav-icon">🆕</span><?= t('nav.new') ?> <span class="nav-badge" id="new-releases-badge" style="display:none">0</span></div>
    <div class="nav-item" onclick="showView('search')"><span class="nav-icon">🔍</span><?= t('nav.search') ?></div>
    <?php if ($can_queue_view): ?>
    <div class="nav-item queue-nav" onclick="showView('queue')">
      <span class="nav-icon">📋</span><?= t('nav.queue') ?>
      <span class="nav-badge" id="nav-badge">0</span>
    </div>
    <?php endif; ?>
    <?php if ($can_cron_log): ?>
    <div class="nav-item" onclick="showView('log')"><span class="nav-icon">🖥</span><?= t('nav.log') ?></div>
    <?php endif; ?>
    <?php if ($can_settings): ?>
    <div class="nav-item" onclick="showView('api-docs')"><span class="nav-icon">📖</span> <?= t('nav.api_docs') ?></div>
    <?php endif; ?>
    <?php if ($can_settings): ?>
    <div class="nav-item" onclick="showView('stats')"><span class="nav-icon">📊</span><?= t('nav.stats') ?></div>
    <?php endif; ?>
    <?php if ($can_users): ?>
    <div class="nav-item" onclick="showView('users')"><span class="nav-icon">👥</span><?= t('nav.users') ?></div>
    <?php endif; ?>
    <?php if ($can_settings): ?>
    <div class="nav-item" onclick="showView('settings')" style="margin-top:auto;border-top:1px solid var(--border)"><span class="nav-icon">⚙️</span><?= t('nav.settings') ?></div>
    <?php endif; ?>
  </nav>
</aside>

<!-- ── Main ─────────────────────────────────────────────────── -->
<div class="main">
  <header class="topbar">
    <div class="topbar-inner">
    <!-- Logo-Block -->
    <div class="topbar-logo-block">
      <div class="hamburger" id="hamburger" onclick="toggleSidebar()"><span></span><span></span><span></span></div>
      <div class="logo-text"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 160" style="width:18px;height:14px;vertical-align:middle;margin-right:7px" fill="none" stroke="currentColor"><rect x="2.5" y="2.5" width="195" height="155" rx="30" stroke-width="10"/><line x1="100" y1="25" x2="100" y2="92" stroke-width="18" stroke-linecap="round"/><path d="M52 68 L100 116 L148 68" stroke-width="18" stroke-linecap="round" stroke-linejoin="round"/><line x1="48" y1="135" x2="152" y2="135" stroke-width="18" stroke-linecap="round"/></svg><?= htmlspecialchars(cfg('app_title', 'Xtream Vault')) ?></div>
    </div>

    <!-- Mitte: Page-Title + Search + Filter -->
    <div class="topbar-center">
      <div class="page-title" id="page-title">Dashboard</div>
      <div class="search-wrap" id="search-bar" style="display:none"></div>
      <div class="filter-bar" id="filter-bar" style="display:none">
        <button class="filter-btn active" onclick="setFilter('all',this)">All</button>
        <button class="filter-btn" onclick="setFilter('new',this)"><?= t('filter.new') ?></button>
        <button class="filter-btn" onclick="setFilter('queued',this)"><?= t('filter.queued') ?></button>
        <button class="filter-btn" onclick="setFilter('done',this)"><?= t('status.done') ?></button>
      </div>
    </div>

    <!-- Rechte Chips -->
    <div class="topbar-right">
      <?php if ($can_settings): ?>
      <div id="topbar-dl" onclick="showView('queue')" title="Zum Download-Log"
        style="display:none;align-items:center;gap:6px;cursor:pointer;font-family:'DM Mono',monospace;font-size:.6rem;max-width:180px;overflow:hidden;padding:3px 8px;border-radius:4px;transition:background .15s"
        onmouseover="this.style.background='rgba(255,255,255,.06)'" onmouseout="this.style.background=''">
        <span class="tc-dot" style="background:var(--accent2);width:5px;height:5px;border-radius:50%;flex-shrink:0"></span>
        <div style="flex:1;min-width:0;overflow:hidden">
          <div id="topbar-dl-title" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--accent2)"></div>
          <div style="height:2px;background:rgba(255,255,255,.1);border-radius:1px;margin-top:2px;overflow:hidden">
            <div id="topbar-dl-bar" style="height:100%;width:0%;background:var(--accent2);border-radius:1px;transition:width .5s"></div>
          </div>
        </div>
        <span id="topbar-dl-pct" style="color:var(--accent2);flex-shrink:0"></span>
      </div>
      <?php endif; ?>

      <?php if ($can_queue_view): ?>
      <span class="queue-pill" id="queue-pill" onclick="showView('queue')"><span id="pill-count">0</span> <?= t('queue.in_queue') ?></span>
      <?php endif; ?>

      <?php if ($can_settings): ?>
      <div class="topbar-sep"></div>
      <span id="vpn-badge" class="topbar-chip" title="VPN — klicken zum Verbinden/Trennen" onclick="vpnBadgeToggle()" style="display:none"></span>
      <span id="update-badge" class="topbar-chip" onclick="showView('settings')" title="Update verfügbar"
        style="display:none;color:var(--orange)">⬆ Update</span>
      <?php endif; ?>

      <?php if ($role === 'editor'): ?>
      <span id="limit-indicator" class="topbar-chip topbar-desktop-only" style="display:none"></span>
      <?php endif; ?>

      <button id="theme-toggle" class="topbar-chip topbar-desktop-only" onclick="showView('profile')" title="Theme wechseln">🎨</button>

      <!-- Mobile Overflow -->
      <div class="topbar-overflow" id="topbar-overflow">
        <button class="topbar-icon-btn" onclick="toggleTopbarMenu()" aria-label="Menü">⋯</button>
        <div class="topbar-menu" id="topbar-menu">
          <button onclick="showView('profile');closeTopbarMenu()">🎨 <?= t('nav.theme') ?></button>
          <?php if ($can_settings): ?>
          <button onclick="showView('settings');closeTopbarMenu()">⚙️ <?= t('nav.settings') ?></button>
          <?php endif; ?>
          <?php if ($can_queue_view): ?>
          <button onclick="showView('queue');closeTopbarMenu()">📋 <?= t('nav.queue') ?></button>
          <?php endif; ?>
          <?php if ($role === 'editor'): ?>
          <div id="limit-indicator-mobile" style="display:none;padding:8px 16px;font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted)"></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="topbar-sep"></div>

      <!-- User Chip -->
      <div class="user-chip" id="user-chip" onclick="toggleUserDropdown()">
        <div class="user-chip-avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
        <div>
          <div class="user-chip-name"><?= htmlspecialchars($user['username']) ?></div>
          <div class="user-chip-role"><?= $role ?></div>
        </div>
        <div class="user-dropdown" id="user-dropdown">
          <button onclick="event.stopPropagation();showView('profile');toggleUserDropdown()">👤 <?= t('nav.profile') ?></button>
          <?php if ($can_users): ?>
          <button onclick="event.stopPropagation();showView('users');toggleUserDropdown()">👥 <?= t('nav.users') ?></button>
          <?php endif; ?>
          <div class="sep"></div>
          <button class="danger" onclick="doLogout()">⏻ <?= t('nav.logout') ?></button>
        </div>
      </div>
    </div>
    </div><!-- /topbar-inner -->
  </header>

  <div class="content" id="content">
    <!-- Dashboard -->
    <div id="view-dashboard">
      <?php if ($can_settings): ?>
      <!-- Admin Dashboard -->
      <div id="unconfigured-banner" class="unconfigured-banner" style="display:none" onclick="showView('settings')">
        <span class="ub-icon">⚠️</span>
        <div><strong><?= t('cfg.not_configured') ?></strong></div>
      </div>

      <!-- 1. Schnellzugriff-Buttons -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
        <button class="btn-secondary" data-label="<?= t('queue.start') ?>" onclick="startQueue(this)"><?= t('queue.start') ?></button>
        <button class="btn-secondary" onclick="dashRebuildCache()"><?= t('cfg.cache_build') ?></button>
        <button class="btn-secondary" onclick="dashClearDone()"><?= t('queue.clear_done') ?></button>
        <button class="btn-secondary danger" onclick="dashClearAll()"><?= t('queue.clear') ?></button>
        <button class="btn-secondary" onclick="showView('settings')">⚙️ <?= t('nav.settings') ?></button>
        <button class="btn-secondary" onclick="showView('log')">🖥 <?= t('nav.log') ?></button>
      </div>

      <!-- 2. Server-Status -->
      <div id="dash-servers" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px"></div>

      <!-- 3. KPI-Kacheln: Queue-Status + Speicher + System -->
      <div class="dash-kpi-grid" id="dash-stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(120px,1fr));margin-bottom:14px">
        <div class="dkpi">
          <div class="dkpi-l"><?= t('status.pending') ?></div>
          <div class="dkpi-n" style="color:var(--accent)" id="dqs-pending">–</div>
        </div>
        <div class="dkpi">
          <div class="dkpi-l"><?= t('status.downloading') ?></div>
          <div class="dkpi-n" style="color:var(--orange)" id="dqs-downloading">–</div>
        </div>
        <div class="dkpi">
          <div class="dkpi-l"><?= t('status.done') ?></div>
          <div class="dkpi-n" style="color:var(--green)" id="dqs-done">–</div>
        </div>
        <div class="dkpi">
          <div class="dkpi-l"><?= t('status.error') ?></div>
          <div class="dkpi-n" style="color:var(--red)" id="dqs-error">–</div>
        </div>
        <div class="dkpi">
          <div class="dkpi-l"><?= t('cfg.dest') ?></div>
          <div id="dash-disk" style="margin-top:4px"><div style="color:var(--muted);font-size:.75rem"><?= t('status.loading') ?></div></div>
        </div>
        <div class="dkpi">
          <div class="dkpi-l"><?= t('dash.system') ?></div>
          <div id="dash-system" style="font-size:.78rem;line-height:1.75;margin-top:4px"><div style="color:var(--muted)"><?= t('status.loading') ?></div></div>
        </div>
      </div>

      <!-- 4. Laufender Download -->
      <div class="progress-card" id="dash-progress-card" style="margin-bottom:14px">
        <div class="pc-header">
          <div class="pc-dot"></div>
          <div class="pc-title" id="dash-pc-title">–</div>
          <div class="pc-pos" id="dash-pc-pos"></div>
          <?php if ($can_queue_remove): ?>
          <button class="btn-sm" style="margin-left:auto;flex-shrink:0;color:var(--red);border-color:rgba(255,71,87,.3)" onclick="cancelDownload()"><?= t('queue.abort') ?></button>
          <?php endif; ?>
        </div>
        <div class="pc-bar-wrap"><div class="pc-bar" id="dash-pc-bar"></div></div>
        <div class="pc-stats">
          <div class="pc-stat"><span class="val" id="dash-pc-pct">0%</span><span class="lbl">%</span></div>
          <div class="pc-stat"><span class="val" id="dash-pc-done">–</span><span class="lbl"><?= t('dash.downloaded') ?></span></div>
          <div class="pc-stat"><span class="val" id="dash-pc-total">–</span><span class="lbl"><?= t('dash.total') ?></span></div>
          <div class="pc-stat"><span class="val" id="dash-pc-speed">–</span><span class="lbl"><?= t('dash.speed') ?></span></div>
          <div class="pc-stat"><span class="val" id="dash-pc-eta">–</span><span class="lbl"><?= t('dash.eta') ?></span></div>
        </div>
      </div>

      <!-- 5. Queue-Vorschau + Letzte Downloads (untereinander) -->
      <div style="display:flex;flex-direction:column;gap:14px">

        <!-- Queue-Vorschau -->
        <div class="settings-card" style="padding:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
            <div class="dkpi-l">📋 <?= t('nav.queue') ?></div>
            <button class="btn-sm" onclick="showView('queue')"><?= t('dash.all') ?></button>
          </div>
          <div class="queue-list" id="dash-queue-list">
            <div style="padding:24px;text-align:center"><div class="spinner" style="margin:auto"></div></div>
          </div>
        </div>

        <!-- Letzte Downloads -->
        <div class="settings-card" style="padding:14px">
          <div class="dkpi-l" style="margin-bottom:10px">📥 <?= t('dash.recent') ?></div>
          <div id="dash-recent">
            <div style="padding:24px;text-align:center"><div class="spinner" style="margin:auto"></div></div>
          </div>
        </div>

      </div>

      <?php else: ?>
      <!-- Viewer/Editor Dashboard -->

      <!-- KPI-Zeile -->
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px">
        <div class="dkpi" style="flex:1;min-width:140px">
          <div class="dkpi-l"><?= t('stats.total') ?></div>
          <div class="dkpi-n" style="color:var(--green)" id="ue-total-dl">–</div>
        </div>
        <?php if ($can_queue_view): ?>
        <div class="dkpi" style="flex:1;min-width:140px">
          <div class="dkpi-l"><?= t('status.pending') ?></div>
          <div class="dkpi-n" style="color:var(--accent)" id="ue-pending">–</div>
        </div>
        <div class="dkpi" style="flex:1;min-width:140px">
          <div class="dkpi-l"><?= t('status.downloading') ?></div>
          <div class="dkpi-n" style="color:var(--orange)" id="ue-downloading">–</div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Neue Releases -->
      <div style="margin-bottom:24px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
          <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase"><?= t('dash.new_releases') ?></div>
          <button class="btn-sm" onclick="showView('new-releases')"><?= t('dash.all') ?></button>
        </div>
        <div id="ue-new-releases" style="display:flex;gap:10px;overflow-x:auto;padding-bottom:8px">
          <div style="color:var(--muted);font-size:.8rem;padding:8px"><?= t('status.loading') ?></div>
        </div>
      </div>

      <!-- Letzte Downloads -->
      <div>
        <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin-bottom:10px"><?= t('dash.recent') ?></div>
        <div id="ue-recent" class="grid">
          <div style="color:var(--muted);font-size:.8rem;padding:8px"><?= t('status.loading') ?></div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Movies -->
    <?php if ($show_movies): ?>
    <div id="view-movies" style="display:none">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap">
        <div style="flex:1"></div>
        <button class="btn-sm" id="movie-filter-toggle" onclick="toggleMovieFilters()" style="font-family:'DM Mono',monospace;font-size:.65rem">⚡ Filter</button>
        <button class="view-mode-btn" id="view-mode-btn-movies" onclick="toggleViewMode()" title="Ansicht wechseln">☰</button>
        <div style="display:flex;align-items:center;gap:6px">
          <span style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted)"><?= t('lbl.sortierung') ?></span>
          <select id="sort-movies" class="sort-select" onchange="setSortOrder(this.value,'movies')">
            <option value="default"><?= t('sort.default') ?></option>
            <option value="az"><?= t('sort.az') ?></option>
            <option value="za"><?= t('sort.za') ?></option>
            <option value="rating_desc">⭐ <?= t('sort.rating_desc') ?></option>
            <option value="rating_asc">⭐ <?= t('sort.rating_asc') ?></option>
            <option value="recent">🕐 <?= t('sort.recent') ?></option>
          </select>
        </div>
      </div>

      <!-- Filter-Panel -->
      <div id="movie-filter-panel" style="display:none;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:12px 16px;margin-bottom:14px">
        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
          <div>
            <div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);margin-bottom:4px">FORMAT</div>
            <div style="display:flex;gap:4px">
              <button class="filter-btn active" id="fmt-all"  onclick="setFormatFilter('',this)">Alle</button>
              <button class="filter-btn"        id="fmt-mkv"  onclick="setFormatFilter('mkv',this)">MKV</button>
              <button class="filter-btn"        id="fmt-mp4"  onclick="setFormatFilter('mp4',this)">MP4</button>
            </div>
          </div>
          <div>
            <div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);margin-bottom:4px">MINDESTBEWERTUNG</div>
            <div style="display:flex;align-items:center;gap:6px">
              <input type="range" id="filter-rating" min="0" max="10" step="0.5" value="0" style="width:140px;accent-color:var(--accent)" oninput="document.getElementById('filter-rating-val').textContent=this.value>0?'★ '+this.value+'+':'Alle';applyMovieFilters()">
              <span id="filter-rating-val" style="font-family:'DM Mono',monospace;font-size:.7rem;color:var(--accent);min-width:48px">Alle</span>
            </div>
          </div>
          <div style="flex:1;min-width:160px">
            <div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);margin-bottom:4px">KATEGORIE</div>
            <select id="filter-genre" style="width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:4px;padding:5px 8px;color:var(--text);font-size:.8rem;outline:none" onchange="applyMovieFilters()">
              <option value="">Alle Kategorien</option>
            </select>
          </div>
          <button class="btn-sm" onclick="resetMovieFilters()" style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted)">✕ Zurücksetzen</button>
        </div>
        <div id="movie-filter-count" style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);margin-top:8px"></div>
      </div>

      <div class="grid" id="movie-grid"></div>
      <div id="movie-pagination" style="margin-top:16px;text-align:center"></div>
    </div>
    <?php endif; ?>
    <?php if ($show_series): ?>
    <div id="view-series" style="display:none">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
        <div style="flex:1"></div>
        <button class="view-mode-btn" id="view-mode-btn-series" onclick="toggleViewMode()" title="Ansicht wechseln">☰</button>
        <div style="display:flex;align-items:center;gap:6px">
          <span style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted)"><?= t('lbl.sortierung') ?></span>
          <select id="sort-series" class="sort-select" onchange="setSortOrder(this.value,'series')">
            <option value="default"><?= t('sort.default') ?></option>
            <option value="az"><?= t('sort.az') ?></option>
            <option value="za"><?= t('sort.za') ?></option>
            <option value="rating_desc">⭐ <?= t('sort.rating_desc') ?></option>
            <option value="rating_asc">⭐ <?= t('sort.rating_asc') ?></option>
            <option value="recent">🕐 <?= t('sort.recent') ?></option>
          </select>
        </div>
      </div>
      <div class="grid" id="series-grid"></div>
      <div id="series-pagination" style="margin-top:16px;text-align:center"></div>
    </div>
    <?php endif; ?>
    <!-- Search -->
    <div id="view-search" style="display:none">
      <!-- Suchfeld -->
      <div class="search-wrap" style="margin-bottom:16px;display:flex">
        <input type="text" id="search-input" placeholder="<?= t('search.placeholder') ?>" style="flex:1">
        <span class="search-icon">🔍</span>
      </div>
      <div id="search-history-box" style="margin-bottom:16px"></div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap">
        <button class="filter-btn active" id="search-tab-movies"  onclick="switchSearchTab('movies',this)"><?= t('search.movies_tab') ?></button>
        <button class="filter-btn"        id="search-tab-series"  onclick="switchSearchTab('series',this)"><?= t('search.series_tab') ?></button>
        <?php if ($can_queue_add): ?>
        <div id="multiselect-toolbar" style="display:none;margin-left:auto;display:flex;gap:8px;align-items:center">
          <span id="multiselect-count" style="font-family:'DM Mono',monospace;font-size:.7rem;color:var(--muted)">0 ausgewählt</span>
          <button class="btn-sm" onclick="addSelectionToQueue()">+ Alle zur Queue</button>
          <button class="btn-sm" onclick="clearSelection()">✕ Auswahl aufheben</button>
        </div>
        <?php endif; ?>
      </div>
      <div id="search-cache-hint" style="display:none;font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);margin-bottom:10px"></div>
      <div id="search-movies-grid" class="grid"></div>
      <div id="search-series-grid" class="grid" style="display:none"></div>
    </div>

    <?php if ($can_queue_view): ?>
    <!-- Queue -->
    <div id="view-queue" style="display:none">
      <!-- Live Progress Card (nur für Admins) -->
      <?php if ($can_settings): ?>
      <div class="progress-card" id="progress-card">
        <div class="pc-header">
          <div class="pc-dot"></div>
          <div class="pc-title" id="pc-title">–</div>
          <div class="pc-pos" id="pc-pos"></div>
          <?php if ($can_queue_remove): ?>
          <button class="btn-sm" style="margin-left:auto;flex-shrink:0;color:var(--red);border-color:rgba(255,71,87,.3)" onclick="cancelDownload()"><?= t('queue.abort') ?></button>
          <?php endif; ?>
        </div>
        <div class="pc-bar-wrap"><div class="pc-bar" id="pc-bar"></div></div>
        <div class="pc-stats">
          <div class="pc-stat"><span class="val" id="pc-pct">0%</span><span class="lbl">Fortschritt</span></div>
          <div class="pc-stat"><span class="val" id="pc-done">–</span><span class="lbl">heruntergeladen</span></div>
          <div class="pc-stat"><span class="val" id="pc-total">–</span><span class="lbl">gesamt</span></div>
          <div class="pc-stat"><span class="val" id="pc-speed">–</span><span class="lbl">Geschwindigkeit</span></div>
          <div class="pc-stat"><span class="val" id="pc-eta">–</span><span class="lbl"><?= t('dash.eta') ?></span></div>
        </div>
      </div>
      <?php endif; ?>
      <div class="queue-toolbar">
        <div class="queue-toolbar-title">Download Queue</div>
        <button class="btn-sm" onclick="refreshQueue()">↻ Refresh</button>
        <?php if ($can_settings): ?>
        <button class="btn-sm" data-label="▶ Starten" onclick="startQueue(this)"><?= t('queue.start') ?></button>
        <?php endif; ?>
        <?php if ($can_queue_clear): ?>
        <button class="btn-sm" onclick="clearDone()"><?= t('queue.clear_done') ?></button>
        <button class="btn-sm danger" onclick="clearAll()"><?= t('queue.clear') ?></button>
        <?php endif; ?>
      </div>
      <div class="queue-list" id="queue-list"></div>
    </div>
    <?php endif; ?>

    <!-- Favoriten -->
    <div id="view-favourites" style="display:none">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div>
          <div style="font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:.08em">Favoriten</div>
          <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);margin-top:2px">
            <span id="fav-count">–</span> gespeichert
          </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <button class="view-mode-btn" id="view-mode-btn-favourites" onclick="toggleViewMode()" title="Ansicht wechseln">☰</button>
          <button class="filter-btn active" id="fav-tab-all"    onclick="switchFavTab('all',this)"><?= t('fav.all') ?></button>
          <button class="filter-btn"        id="fav-tab-movies" onclick="switchFavTab('movie',this)"><?= t('search.movies_tab') ?></button>
          <button class="filter-btn"        id="fav-tab-series" onclick="switchFavTab('series',this)"><?= t('search.series_tab') ?></button>
        </div>
      </div>
      <div style="margin-bottom:14px">
        <div class="search-wrap" style="max-width:300px">
          <input type="text" id="fav-search" placeholder="<?= t('fav.search') ?>" oninput="renderFavourites()">
          <span class="search-icon">🔍</span>
        </div>
      </div>
      <div class="grid" id="fav-grid"></div>
    </div>

    <!-- Neue Releases -->
    <div id="view-new-releases" style="display:none">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div>
          <div style="font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:.08em"><?= t('new.title') ?></div>
          <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);margin-top:2px" id="new-releases-meta">–</div>
        </div>
        <div style="display:flex;gap:8px">
          <?php if ($can_settings): ?>
          <button class="btn-sm" onclick="dismissAllNewReleases(this)" style="margin-left:4px"><?= t('new.all_seen') ?></button>
          <?php endif; ?>
        </div>
      </div>
      <div class="grid" id="new-releases-grid"></div>
    </div>

    <!-- Statistiken (admin only) -->
    <?php if ($can_settings): ?>
    <div id="view-stats" style="display:none">

      <!-- KPI Cards -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px">
        <div class="dkpi"><div class="dkpi-l"><?= t('stats.total') ?></div><div class="dkpi-n" id="stats-total-count">–</div></div>
        <div class="dkpi"><div class="dkpi-l"><?= t('search.movies_tab') ?></div><div class="dkpi-n" id="stats-total-movies">–</div></div>
        <div class="dkpi"><div class="dkpi-l">📺 Episoden</div><div class="dkpi-n" id="stats-total-episodes">–</div></div>
        <div class="dkpi"><div class="dkpi-l"><?= t('stats.volume') ?></div><div class="dkpi-n" id="stats-total-gb">–</div></div>
        <div class="dkpi"><div class="dkpi-l"><?= t('stats.this_month') ?></div><div class="dkpi-n" id="stats-this-month">–</div></div>
        <div class="dkpi"><div class="dkpi-l"><?= t('stats.this_month_gb') ?></div><div class="dkpi-n" id="stats-this-month-gb">–</div></div>
      </div>

      <!-- Charts: 2 Spalten -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
        <div class="settings-card">
          <h3>📦 <?= t('stats.by_month_gb') ?></h3>
          <div style="position:relative;height:200px"><canvas id="stats-chart-gb"></canvas></div>
        </div>
        <div class="settings-card">
          <h3>📥 <?= t('stats.by_month_dl') ?></h3>
          <div style="position:relative;height:200px"><canvas id="stats-chart-count"></canvas></div>
        </div>
      </div>

      <!-- Wochentag-Verteilung + Top User nebeneinander -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
        <div class="settings-card">
          <h3>📅 <?= t('stats.weekday') ?></h3>
          <div style="position:relative;height:200px"><canvas id="stats-chart-weekday"></canvas></div>
        </div>
        <div class="settings-card">
          <h3>👤 <?= t('stats.top_users') ?></h3>
          <div id="stats-top-users" style="font-size:.82rem"></div>
        </div>
      </div>

      <!-- Top Kategorien als Tabelle -->
      <div class="settings-card">
        <h3>🏷️ <?= t('stats.top_cats') ?></h3>
        <div id="stats-top-cats" style="font-size:.82rem"></div>
      </div>

      <!-- Statistiken pro Server -->
      <div class="settings-card" style="margin-top:16px">
        <h3>🌐 <?= t('stats.by_server') ?></h3>
        <div id="stats-by-server" style="font-size:.82rem"></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- API Docs -->
    <?php if ($can_settings): ?>
    <div id="view-api-docs" style="display:none">
      <div>

        <!-- Intro -->
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:20px 24px;margin-bottom:20px">
          <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.2em;text-transform:uppercase;margin-bottom:10px"><?= t('api.auth') ?></div>
          <p style="font-size:.85rem;color:var(--muted);line-height:1.7;margin-bottom:12px"><?= t('api.auth_desc') ?></p>
          <div class="api-code">X-API-Key: xv_xxxxxxxxxxxx</div>
          <div style="font-size:.78rem;color:var(--muted);margin-top:8px"><?= t('api.auth_query') ?> <code style="color:var(--accent2)">?api_key=xv_xxxxxxxxxxxx</code></div>
          <div style="font-size:.78rem;color:var(--muted);margin-top:6px"><?= t('api.auth_manage') ?> <span style="color:var(--accent2);cursor:pointer" onclick="showView('settings')"><?= t('api.auth_manage_link') ?></span></div>
        </div>

        <!-- Endpoints -->
        <?php
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
              . '://' . $_SERVER['HTTP_HOST']
              . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/api.php';
        ?>

        <!-- create_user -->
        <div class="api-card">
          <div class="api-card-header">
            <span class="api-method post">POST</span>
            <span class="api-method get">GET</span>
            <code class="api-endpoint"><?= htmlspecialchars($base) ?>?action=external_create_user</code>
          </div>
          <div class="api-desc"><?= t('api.create_user_desc') ?></div>
          <div class="api-section-label"><?= t('api.parameters') ?></div>
          <table class="api-table">
            <tr><th><?= t('api.param_name') ?></th><th><?= t('api.param_type') ?></th><th><?= t('api.param_required') ?></th><th><?= t('api.param_desc') ?></th></tr>
            <tr><td>username</td><td>string</td><td>✅</td><td><?= t('api.param_username') ?></td></tr>
            <tr><td>password</td><td>string</td><td>✅</td><td><?= t('api.param_password') ?></td></tr>
            <tr><td>role</td><td>string</td><td>–</td><td><code>viewer</code> (<?= t('api.default') ?>), <code>editor</code>, <code>admin</code></td></tr>
          </table>
          <div class="api-section-label"><?= t('api.response') ?></div>
          <div class="api-code">{ "ok": true, "id": "abc123", "username": "max", "role": "viewer" }</div>
        </div>

        <!-- list_users -->
        <div class="api-card">
          <div class="api-card-header">
            <span class="api-method get">GET</span>
            <code class="api-endpoint"><?= htmlspecialchars($base) ?>?action=external_list_users</code>
          </div>
          <div class="api-desc"><?= t('api.list_users_desc') ?></div>
          <div class="api-section-label"><?= t('api.response') ?></div>
          <div class="api-code">{ "ok": true, "count": 3, "users": [<br>&nbsp;&nbsp;{ "id": "abc123", "username": "max", "role": "viewer", "suspended": false, "created_at": "2026-01-01 12:00:00" }<br>] }</div>
        </div>

        <!-- suspend_user -->
        <div class="api-card">
          <div class="api-card-header">
            <span class="api-method post">POST</span>
            <span class="api-method get">GET</span>
            <code class="api-endpoint"><?= htmlspecialchars($base) ?>?action=external_suspend_user</code>
          </div>
          <div class="api-desc"><?= t('api.suspend_user_desc') ?></div>
          <div class="api-section-label"><?= t('api.parameters') ?></div>
          <table class="api-table">
            <tr><th><?= t('api.param_name') ?></th><th><?= t('api.param_type') ?></th><th><?= t('api.param_required') ?></th><th><?= t('api.param_desc') ?></th></tr>
            <tr><td>username</td><td>string</td><td>✅</td><td><?= t('api.param_username') ?></td></tr>
            <tr><td>suspended</td><td>bool</td><td>–</td><td><code>true</code> = <?= t('api.suspend_true') ?>, <code>false</code> = <?= t('api.suspend_false') ?></td></tr>
          </table>
          <div class="api-section-label"><?= t('api.response') ?></div>
          <div class="api-code">{ "ok": true, "username": "max", "suspended": true }</div>
        </div>

        <!-- update_user -->
        <div class="api-card">
          <div class="api-card-header">
            <span class="api-method post">POST</span>
            <code class="api-endpoint"><?= htmlspecialchars($base) ?>?action=external_update_user</code>
          </div>
          <div class="api-desc"><?= t('api.update_user_desc') ?></div>
          <div class="api-section-label"><?= t('api.parameters') ?></div>
          <table class="api-table">
            <tr><th><?= t('api.param_name') ?></th><th><?= t('api.param_type') ?></th><th><?= t('api.param_required') ?></th><th><?= t('api.param_desc') ?></th></tr>
            <tr><td>username</td><td>string</td><td>✅</td><td><?= t('api.param_username') ?></td></tr>
            <tr><td>password</td><td>string</td><td>–</td><td><?= t('api.param_new_password') ?></td></tr>
            <tr><td>role</td><td>string</td><td>–</td><td><code>viewer</code>, <code>editor</code>, <code>admin</code></td></tr>
          </table>
          <div class="api-section-label"><?= t('api.response') ?></div>
          <div class="api-code">{ "ok": true, "username": "max", "updated": true }</div>
        </div>

        <!-- delete_user -->
        <div class="api-card">
          <div class="api-card-header">
            <span class="api-method post">POST</span>
            <span class="api-method get">GET</span>
            <code class="api-endpoint"><?= htmlspecialchars($base) ?>?action=external_delete_user</code>
          </div>
          <div class="api-desc"><?= t('api.delete_user_desc') ?></div>
          <div class="api-section-label"><?= t('api.parameters') ?></div>
          <table class="api-table">
            <tr><th><?= t('api.param_name') ?></th><th><?= t('api.param_type') ?></th><th><?= t('api.param_required') ?></th><th><?= t('api.param_desc') ?></th></tr>
            <tr><td>username</td><td>string</td><td>✅</td><td><?= t('api.param_username') ?></td></tr>
          </table>
          <div class="api-section-label"><?= t('api.response') ?></div>
          <div class="api-code">{ "ok": true, "username": "max", "deleted": true }</div>
        </div>

        <!-- Fehlercodes -->
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:20px 24px;margin-top:8px">
          <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.2em;text-transform:uppercase;margin-bottom:10px"><?= t('api.errors') ?></div>
          <table class="api-table">
            <tr><th>HTTP</th><th><?= t('api.error_meaning') ?></th></tr>
            <tr><td>400</td><td><?= t('api.error_400') ?></td></tr>
            <tr><td>401</td><td><?= t('api.error_401') ?></td></tr>
            <tr><td>403</td><td><?= t('api.error_403') ?></td></tr>
            <tr><td>404</td><td><?= t('api.error_404') ?></td></tr>
          </table>
          <div style="margin-top:12px;font-size:.78rem;color:var(--muted)"><?= t('api.error_format') ?> <code style="color:var(--accent2)">{ "error": "..." }</code></div>
        </div>

      </div>
    </div>
    <?php endif; ?>

    <?php if ($can_cron_log): ?>
    <div id="view-log" style="display:none">      <div class="queue-toolbar">
        <div class="queue-toolbar-title">Cron Log</div>
        <button class="btn-sm" onclick="loadLog()"><?= t('btn.refresh') ?></button>
        <?php if ($can_settings): ?>
        <button class="btn-sm danger" onclick="clearCronLog()"><?= t('btn.clear_log') ?></button>
        <?php endif; ?>
      </div>
      <div class="log-wrap" id="log-wrap">Lade Log…</div>
    </div>
    <?php endif; ?>

    <!-- Settings -->
    <div id="view-settings" style="display:none">
      <?php if ($can_settings): ?>
      <div>

        <div class="settings-card">
          <h3>🏷️ <?= t('cfg.app_title') ?></h3>
          <div class="field">
            <label><?= t('cfg.app_title_label') ?></label>
            <input type="text" id="cfg-app-title" value="<?= htmlspecialchars(cfg('app_title', 'Xtream Vault')) ?>" placeholder="Xtream Vault" maxlength="64">
          </div>
          <div style="display:flex;gap:8px;margin-top:10px">
            <button class="btn-primary" onclick="saveAppTitle()"><?= t('btn.save') ?></button>
          </div>
          <div class="settings-msg" id="app-title-msg"></div>
        </div>

        <div class="settings-card">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <h3 style="margin:0"><?= t('cfg.servers') ?></h3>
            <div style="display:flex;gap:6px">
              <button class="btn-sm" onclick="testAllServers()" id="btn-test-all-servers">🔌 Alle testen</button>
              <button class="btn-sm" onclick="openAddServerForm()" id="btn-add-server">+ <?= t('cfg.server_add') ?></button>
            </div>
          </div>

          <!-- Inline-Formular: Server hinzufügen -->
          <div id="add-server-form" style="display:none;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:14px">
            <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin-bottom:12px"><?= t('cfg.server_new') ?></div>
            <div class="field"><label><?= t('cfg.server') ?> Name</label><input type="text" id="add-srv-name" placeholder="z.B. Mein Server"></div>
            <div class="field"><label><?= t('cfg.server_ip') ?></label><input type="text" id="add-srv-ip" placeholder="line.example.com" autocomplete="off"></div>
            <div style="display:flex;gap:10px">
              <div class="field" style="flex:1"><label><?= t('cfg.port') ?></label><input type="text" id="add-srv-port" placeholder="80" value="80"></div>
            </div>
            <div class="field"><label><?= t('cfg.username') ?></label><input type="text" id="add-srv-username" autocomplete="off"></div>
            <div class="field"><label><?= t('cfg.password') ?></label><input type="password" id="add-srv-password" autocomplete="new-password"></div>
            <div class="settings-msg" id="add-srv-msg"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
              <button class="btn-secondary" onclick="closeAddServerForm()"><?= t('btn.cancel') ?></button>
              <button class="btn-primary" onclick="addServer()"><?= t('cfg.server_add_btn') ?></button>
            </div>
          </div>

          <!-- Server-Liste -->
          <div id="saved-servers-list" style="display:flex;flex-direction:column;gap:8px">
            <div style="color:var(--muted);font-size:.8rem"><?= t('status.loading') ?></div>
          </div>

          <!-- Aktuelle Konfiguration (versteckt, für Kompatibilität) -->
          <input type="hidden" id="cfg-server-ip">
          <input type="hidden" id="cfg-port">
          <input type="hidden" id="cfg-username">
          <input type="hidden" id="cfg-password">
          <span style="display:none" id="cfg-server-id-display"></span>
        </div>

        <div class="settings-card">
          <h3><?= t('cfg.dest') ?></h3>
          <div id="rclone-disabled-fields">
            <div class="field">
              <label><?= t('cfg.dest_path') ?></label>
              <input type="text" id="cfg-dest-path" placeholder="/var/www/html/xtream/downloads">
              <span class="hint"><?= t('cfg.dest_rclone_hint') ?></span>
            </div>
          </div>
        </div>

        <div class="settings-card">
          <h3><?= t('cfg.editor_visibility_title') ?></h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
            <?= t('cfg.editor_desc') ?>
          </div>
          <label class="settings-toggle">
            <input type="checkbox" id="cfg-editor-movies">
            <span>🎬 <?= t('cfg.editor_movies_label') ?></span>
          </label>
          <label class="settings-toggle">
            <input type="checkbox" id="cfg-editor-series">
            <span>📺 <?= t('cfg.editor_series_label') ?></span>
          </label>
        </div>

        <div class="settings-card">
          <h3>☁️ rclone — Cloud-Speicher</h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
            Wenn aktiviert, werden VODs direkt in den Cloud-Speicher gestreamt — ohne lokale Zwischenspeicherung.
          </div>
          <label class="settings-toggle" style="margin-bottom:14px">
            <input type="checkbox" id="cfg-rclone-enabled" onchange="toggleRcloneFields(this.checked)">
            <span><?= t('cfg.rclone_enable') ?></span>
          </label>
          <div id="rclone-fields" style="display:none">
            <div class="field">
              <label><?= t('cfg.rclone_remote') ?></label>
              <input type="text" id="cfg-rclone-remote" placeholder="gdrive">
              <span class="hint"><?= t('cfg.rclone_remote_hint') ?></span>
            </div>
            <div class="field">
              <label><?= t('cfg.rclone_path') ?></label>
              <input type="text" id="cfg-rclone-path" placeholder="Media/VOD">
              <span class="hint"><?= t('cfg.rclone_path_hint') ?></span>
            </div>
            <div class="field">
              <label><?= t('cfg.rclone_bin') ?></label>
              <input type="text" id="cfg-rclone-bin" placeholder="rclone">
              <span class="hint"><?= t('cfg.rclone_bin_hint') ?></span>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <button class="btn-secondary" onclick="testRclone()"><?= t('cfg.rclone_test') ?></button>
              <button class="btn-secondary" id="btn-rclone-cache" onclick="refreshRcloneCache(this)"><?= t('cfg.rclone_cache') ?></button>
              <div class="settings-msg" id="rclone-test-msg" style="margin:0"></div>
            </div>
            <div id="rclone-cache-status" style="font-family:'DM Mono',monospace;font-size:.68rem;color:var(--muted);margin-top:10px"></div>
          </div>
        </div>

        <div class="settings-card">
          <h3>⚡ <?= t('cfg.parallel_title') ?></h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
            <?= t('cfg.parallel_desc') ?>
          </div>
          <label class="settings-toggle" style="margin-bottom:14px">
            <input type="checkbox" id="cfg-parallel-enabled" onchange="document.getElementById('parallel-fields').style.display=this.checked?'':'none'">
            <span><?= t('cfg.parallel_enable') ?></span>
          </label>
          <div id="parallel-fields" style="display:none">
            <div class="field">
              <label><?= t('cfg.parallel_max') ?></label>
              <input type="number" id="cfg-parallel-max" min="1" max="10" step="1" style="max-width:100px">
              <span class="hint"><?= t('cfg.parallel_max_hint') ?></span>
            </div>
          </div>
        </div>

        <div class="settings-card">
          <h3>🆕 <?= t('cfg.autoqueue_title') ?></h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
            <?= t('cfg.autoqueue_desc') ?>
          </div>
          <label class="settings-toggle" style="margin-bottom:14px">
            <input type="checkbox" id="cfg-autoqueue-enabled" onchange="document.getElementById('autoqueue-fields').style.display=this.checked?'':'none'">
            <span><?= t('cfg.autoqueue_enable') ?></span>
          </label>
          <div id="autoqueue-fields" style="display:none">
            <div class="field">
              <label><?= t('cfg.autoqueue_max') ?></label>
              <input type="number" id="cfg-autoqueue-max" min="1" max="100" step="1" value="10" style="max-width:100px">
              <span class="hint"><?= t('cfg.autoqueue_max_hint') ?></span>
            </div>

          </div>
        </div>

        <div class="settings-card">
          <h3><?= t('cfg.cache_status') ?></h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.6">
            <?= t('cfg.cache_desc') ?>
          </div>
          <div id="cache-status-box" style="font-family:'DM Mono',monospace;font-size:.72rem;color:var(--muted);margin-bottom:14px"><?= t('status.loading') ?></div>
          <button class="btn-secondary" id="btn-rebuild-cache" onclick="rebuildCache(this)"><?= t('cfg.cache_build') ?></button>
          <div class="settings-msg" id="cache-msg"></div>
        </div>

        <div class="settings-card">
          <h3><?= t('cfg.tmdb') ?></h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.6">
            The Movie Database — zeigt Plot und Bewertung beim Klick auf eine Karte an.<br>
            API-Key unter <a href="https://www.themoviedb.org/settings/api" target="_blank" style="color:var(--accent2)">themoviedb.org/settings/api</a> erstellen.
          </div>
          <div class="field">
            <label><?= t('cfg.tmdb_key') ?></label>
            <input type="password" id="cfg-tmdb-api-key" placeholder="<?= t('cfg.tmdb_placeholder') ?>" autocomplete="off">
          </div>
        </div>

        <div class="settings-card">
          <h3>🔄 <?= t('nav.updates') ?></h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.6">
            <?= t('cfg.update_desc') ?>
            <a href="https://github.com/extend110/xtream-vault" target="_blank" style="color:var(--accent2)">GitHub</a>
            herunter und installiert sie. Vor dem Update wird automatisch ein Backup von <code>data/</code> erstellt.
          </div>
          <div id="update-status" style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:12px 14px;margin-bottom:14px;font-family:'DM Mono',monospace;font-size:.72rem;line-height:1.8">
            <div style="color:var(--muted)"><?= t('cfg.update_not_checked') ?></div>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="btn-secondary" onclick="checkUpdate(this)"><?= t('cfg.update_check') ?></button>
            <button class="btn-primary"   id="btn-run-update" onclick="runUpdate(this)" style="display:none"><?= t('cfg.update_install') ?></button>
            <div class="settings-msg" id="update-msg" style="margin:0"></div>
          </div>
          <div id="update-log" style="display:none;margin-top:12px;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:10px 14px;font-family:'DM Mono',monospace;font-size:.68rem;color:var(--muted);white-space:pre-wrap;max-height:160px;overflow-y:auto"></div>
        </div>

        <div class="settings-card">
          <h3>🪲 <?= t('cfg.phplog_title') ?></h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px">
            <?= t('cfg.phplog_desc') ?>
          </div>
          <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px">
            <button class="btn-secondary" onclick="loadPhpErrorLog(this)"><?= t('cfg.phplog_load') ?></button>
            <span id="php-log-path" style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted)"></span>
          </div>
          <div id="php-error-log" style="display:none;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:10px 14px;font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);white-space:pre-wrap;max-height:300px;overflow-y:auto;line-height:1.6"></div>
        </div>

        <div class="settings-card">
          <h3>🔒 <?= t('cfg.vpn_title') ?></h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.6">
            <?= t('cfg.vpn_desc') ?>
          </div>
          <div class="field">
            <label><?= t('cfg.vpn_iface_label') ?></label>
            <input type="text" id="cfg-vpn-interface" placeholder="wg0" style="max-width:160px">
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="btn-secondary" id="vpn-toggle-btn" onclick="vpnToggle(this)"><?= t('status.loading') ?></button>
            <div class="settings-msg" id="vpn-status-msg" style="margin:0"></div>
          </div>
          <!-- VPN Live-Statistiken (nur sichtbar wenn aktiv) -->
          <div id="vpn-stats-card" style="display:none;margin-top:14px;background:var(--bg3);border:1px solid rgba(46,213,115,.2);border-radius:8px;padding:14px 16px">
            <div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--green);letter-spacing:.1em;text-transform:uppercase;margin-bottom:10px">🔒 VPN aktiv</div>
            <div style="display:flex;gap:16px;flex-wrap:wrap">
              <div><div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em"><?= t('cfg.vpn_pub_ip') ?></div><div id="vpn-stat-ip" style="font-family:'DM Mono',monospace;font-size:.82rem;margin-top:3px">–</div></div>
              <div><div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em"><?= t('cfg.vpn_since') ?></div><div id="vpn-stat-since" style="font-family:'DM Mono',monospace;font-size:.82rem;margin-top:3px">–</div></div>
              <div><div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em"><?= t('cfg.vpn_iface') ?></div><div id="vpn-stat-iface" style="font-family:'DM Mono',monospace;font-size:.82rem;margin-top:3px">–</div></div>
            </div>
          </div>
        </div>

        <div class="settings-card">
          <h3>📨 <?= t('cfg.tg_title') ?></h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.6">
            <?= t('cfg.tg_desc') ?>
            Bot erstellen: <a href="https://t.me/BotFather" target="_blank" style="color:var(--accent2)">@BotFather</a> ·
            Chat-ID: <a href="https://t.me/userinfobot" target="_blank" style="color:var(--accent2)">@userinfobot</a>
          </div>
          <div class="field">
            <label>Bot Token</label>
            <input type="password" id="cfg-telegram-bot-token" placeholder="<?= t('cfg.telegram_placeholder') ?>" autocomplete="off">
          </div>
          <div class="field">
            <label>Chat ID</label>
            <input type="text" id="cfg-telegram-chat-id" placeholder="z.B. 123456789 oder -100123456789 (Gruppe)">
          </div>
          <label class="settings-toggle" style="margin-bottom:10px">
            <input type="checkbox" id="cfg-telegram-enabled" onchange="toggleTelegramOptions(this.checked)">
            <span><?= t('cfg.tg_enable') ?></span>
          </label>

          <!-- Benachrichtigungstypen -->
          <div id="telegram-notify-options" style="display:none;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:14px">
            <div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin-bottom:10px"><?= t('cfg.tg_notify_when') ?></div>
            <label class="settings-toggle" style="margin-bottom:6px">
              <input type="checkbox" id="cfg-tg-notify-success">
              <span><?= t('cfg.tg_on_success') ?></span>
            </label>
            <label class="settings-toggle" style="margin-bottom:6px">
              <input type="checkbox" id="cfg-tg-notify-error">
              <span><?= t('cfg.tg_on_error') ?></span>
            </label>
            <label class="settings-toggle" style="margin-bottom:6px">
              <input type="checkbox" id="cfg-tg-notify-queue-done">
              <span><?= t('cfg.tg_on_queue_done') ?></span>
            </label>
            <label class="settings-toggle" style="margin-bottom:6px">
              <input type="checkbox" id="cfg-tg-notify-new-releases">
              <span><?= t('cfg.tg_on_new_releases') ?></span>
            </label>
            <label class="settings-toggle" style="margin-bottom:10px">
              <input type="checkbox" id="cfg-tg-notify-disk-low" onchange="toggleDiskLowField(this.checked)">
              <span><?= t('cfg.tg_on_disk_low') ?></span>
            </label>
            <div class="field" id="tg-disk-low-field" style="display:none">
              <label><?= t('cfg.tg_disk_threshold') ?></label>
              <input type="number" id="cfg-tg-disk-low-gb" min="1" max="500" value="10" style="max-width:120px">
              <span class="hint"><?= t('cfg.tg_disk_threshold_hint') ?></span>
            </div>
          </div>

          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="btn-secondary" onclick="testTelegram()"><?= t('cfg.tg_test') ?></button>
            <div class="settings-msg" id="telegram-test-msg" style="margin:0"></div>
          </div>
        </div>

        <div class="settings-card">
          <h3><?= t('cfg.api_keys_title') ?></h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
            <?= t('cfg.api_keys_desc') ?>
          </div>
          <div id="apikey-new-reveal" style="display:none" class="key-new-reveal">
            <strong><?= t('cfg.api_key_once') ?></strong>
            <span id="apikey-new-value"></span>
          </div>
          <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
            <input type="text" id="apikey-name-input" placeholder="Name des Keys (z.B. Webshop)" style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:8px 12px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.82rem;outline:none;flex:1">
            <button class="btn-primary" onclick="createApiKey()"><?= t('cfg.api_key_create') ?></button>
          </div>
          <div class="apikey-table-wrap">
            <table class="apikey-table" id="apikey-table">
              <thead><tr><th><?= t('api.param_name') ?></th><th>Key</th><th><?= t('users.col_status') ?></th><th><?= t('users.col_created') ?></th><th><?= t('cfg.api_key_last_used') ?></th><th><?= t('cfg.api_key_calls') ?></th><th></th></tr></thead>
              <tbody id="apikey-tbody"><tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)">Lade…</td></tr></tbody>
            </table>
          </div>
        </div>

        <div class="settings-card">
          <h3>🚫 <?= t('cfg.blacklist_title') ?></h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.6">
            <?= t('cfg.blacklist_desc') ?>
          </div>
          <div class="field">
            <label><?= t('cfg.blacklist_label') ?></label>
            <textarea id="cfg-category-blacklist" rows="4"
              style="width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:8px 12px;color:var(--text);font-family:'DM Mono',monospace;font-size:.75rem;resize:vertical;outline:none"
              placeholder="<?= t('cfg.blacklist_placeholder') ?>"></textarea>
            <span class="hint"><?= t('cfg.blacklist_hint') ?></span>
          </div>
        </div>

        <div class="settings-card">
          <h3>🛡 <?= t('cfg.ip_whitelist_title') ?></h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.6">
            <?= t('cfg.ip_whitelist_desc') ?>
          </div>
          <div class="field">
            <label><?= t('cfg.ip_whitelist_label') ?></label>
            <textarea id="cfg-api-allowed-ips" rows="4"
              style="width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:8px 12px;color:var(--text);font-family:'DM Mono',monospace;font-size:.75rem;resize:vertical;outline:none"
              placeholder="<?= t('cfg.ip_whitelist_placeholder') ?>"></textarea>
            <span class="hint"><?= t('cfg.ip_whitelist_hint') ?></span>
          </div>
          <div id="ip-whitelist-test" style="margin-top:8px;font-family:'DM Mono',monospace;font-size:.72rem;color:var(--muted)">
            <?= t('cfg.ip_whitelist_your_ip') ?>: <strong id="your-ip">–</strong>
          </div>
        </div>

        <div class="settings-card">
          <h3>💾 <?= t('cfg.backup_title') ?></h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
            <?= t('cfg.backup_desc') ?>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px">
            <button class="btn-secondary" onclick="runBackup(this)"><?= t('cfg.backup_now') ?></button>
            <span id="backup-run-msg" style="font-size:.78rem;color:var(--muted)"></span>
          </div>
          <div id="backup-list" style="overflow-x:auto"><div style="color:var(--muted);font-size:.8rem"><?= t('status.loading') ?></div></div>
        </div>

        <div class="settings-card" style="border-color:rgba(255,71,87,.2)">
          <h3>🔧 <?= t('cfg.maintenance') ?></h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
            <?= t('cfg.maintenance_desc') ?>
          </div>
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <div id="maintenance-status" style="font-family:'DM Mono',monospace;font-size:.75rem;padding:5px 12px;border-radius:5px;background:var(--bg3);border:1px solid var(--border)">
              Lade…
            </div>
            <button class="btn-secondary" id="btn-maintenance-toggle" onclick="toggleMaintenance()">Lade…</button>
          </div>
        </div>

        <div class="settings-actions">
          <button class="btn-primary" id="btn-save-cfg" onclick="saveConfig()"><?= t('cfg.save') ?></button>
        </div>
        <div class="settings-msg" id="settings-msg"></div>

        <!-- Version -->
        <?php
        $versionInfo = file_exists(__DIR__ . '/version.json')
            ? (json_decode(file_get_contents(__DIR__ . '/version.json'), true) ?? [])
            : [];
        $commit = $versionInfo['commit'] ?? 'unknown';
        $commitShort = strlen($commit) > 7 ? substr($commit, 0, 7) : $commit;
        $updatedAt = $versionInfo['updated_at'] ?? '';
        ?>
        <div style="margin-top:24px;text-align:center;font-family:'DM Mono',monospace;font-size:.62rem;color:var(--muted);letter-spacing:.06em">
          <?= htmlspecialchars(cfg('app_title', 'Xtream Vault')) ?>
          <?php if ($commitShort && $commitShort !== 'unknown'): ?>
          · <span title="<?= htmlspecialchars($updatedAt) ?>">commit <?= htmlspecialchars($commitShort) ?></span>
          <?php endif; ?>
        </div>

      </div>
      <?php else: ?>
      <div class="state-box"><div class="icon">🔒</div><p>Keine Berechtigung — nur Admins können die Einstellungen ändern.</p></div>
      <?php endif; ?>
    </div>

    <!-- Users (admin only) -->
    <div id="view-users" style="display:none">
      <?php if ($can_users): ?>
      <div class="queue-toolbar">
        <div class="queue-toolbar-title"><?= t('users.title') ?></div>
        <button class="btn-sm" onclick="showView('activity-log')">📋 <?= t('nav.activity_log') ?></button>
        <button class="btn-sm" onclick="openInviteModal()"><?= t('invite.create') ?></button>
        <button class="btn-sm" onclick="openCreateUser()">+ <?= t('users.create') ?></button>
      </div>
      <div class="user-table-wrap" style="background:var(--bg2);border:1px solid var(--border);border-radius:8px">
        <table class="user-table" id="users-table">
          <thead>
            <tr>
              <th><?= t('users.col_username') ?></th>
              <th><?= t('users.col_role') ?></th>
              <th><?= t('users.col_status') ?></th>
              <th><?= t('users.col_created') ?></th>
              <th><?= t('users.col_limit') ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody id="users-tbody">
            <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted)"><?= t('status.loading') ?></td></tr>
          </tbody>
        </table>
      </div>

      <!-- Aktive Einladungslinks -->
      <div style="margin-top:24px">
        <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin-bottom:10px"><?= t('invite.links') ?></div>
        <div id="invite-list"><div style="color:var(--muted);font-size:.8rem"><?= t('status.loading') ?></div></div>
      </div>
      <?php else: ?>
      <div class="state-box"><div class="icon">🔒</div><p><?= t('users.no_permission') ?></p></div>
      <?php endif; ?>
    </div>

    <!-- Activity Log -->
    <div id="view-activity-log" style="display:none">
      <?php if ($can_users): ?>
      <div class="queue-toolbar">
        <div class="queue-toolbar-title"><?= t('nav.activity_log') ?></div>
        <select id="actlog-user-filter" onchange="loadActivityLog()" style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:6px 10px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.82rem;outline:none">
          <option value=""><?= t('users.all_users') ?></option>
        </select>
        <button class="btn-sm" onclick="showView('users')">← <?= t('btn.back') ?></button>
      </div>
      <div id="activity-log-list" class="queue-list"></div>
      <?php endif; ?>
    </div>

    <!-- Profile -->
    <div id="view-profile" style="display:none">
      <div class="settings-grid">
        <div class="settings-card">
          <h3>🎨 Theme</h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.5">
            <?= t('profile.theme_desc_local') ?>
          </div>
          <div class="theme-picker" id="theme-picker"></div>
        </div>
        <div class="settings-card">
          <h3>🌐 Sprache / Language</h3>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px">
            <button class="btn-secondary lang-btn" data-lang="de" onclick="setLanguage('de',this)"
              style="<?= get_user_lang() === 'de' ? 'border-color:var(--accent);color:var(--accent)' : '' ?>">
              🇩🇪 Deutsch
            </button>
            <button class="btn-secondary lang-btn" data-lang="en" onclick="setLanguage('en',this)"
              style="<?= get_user_lang() === 'en' ? 'border-color:var(--accent);color:var(--accent)' : '' ?>">
              🇬🇧 English
            </button>
            <button class="btn-secondary lang-btn" data-lang="fr" onclick="setLanguage('fr',this)"
              style="<?= get_user_lang() === 'fr' ? 'border-color:var(--accent);color:var(--accent)' : '' ?>">
              🇫🇷 Français
            </button>
            <button class="btn-secondary lang-btn" data-lang="es" onclick="setLanguage('es',this)"
              style="<?= get_user_lang() === 'es' ? 'border-color:var(--accent);color:var(--accent)' : '' ?>">
              🇪🇸 Español
            </button>
            <button class="btn-secondary lang-btn" data-lang="it" onclick="setLanguage('it',this)"
              style="<?= get_user_lang() === 'it' ? 'border-color:var(--accent);color:var(--accent)' : '' ?>">
              🇮🇹 Italiano
            </button>
          </div>
          <div class="settings-msg" id="lang-msg" style="margin-top:10px"></div>
        </div>
        <div class="settings-card">
          <h3><?= t('profile.change_pw') ?></h3>
          <div class="field">
            <label><?= t('profile.old_pw') ?></label>
            <input type="password" id="prof-old-pw" autocomplete="current-password">
          </div>
          <div class="field">
            <label><?= t('profile.new_pw') ?></label>
            <input type="password" id="prof-new-pw" autocomplete="new-password">
          </div>
          <div class="field">
            <label><?= t('profile.new_pw2') ?></label>
            <input type="password" id="prof-new-pw2" autocomplete="new-password">
          </div>
          <div class="settings-actions" style="margin-top:16px">
            <button class="btn-primary" onclick="changeOwnPassword()"><?= t('profile.change_pw') ?></button>
          </div>
          <div class="settings-msg" id="profile-msg"></div>
        </div>
        <div class="settings-card">
          <h3><?= t('profile.account') ?></h3>
          <div style="font-size:.875rem;line-height:2">
            <div style="color:var(--muted);font-family:'DM Mono',monospace;font-size:.65rem;letter-spacing:.1em;text-transform:uppercase"><?= t('users.col_username') ?></div>
            <div><?= htmlspecialchars($user['username']) ?></div>
            <div style="color:var(--muted);font-family:'DM Mono',monospace;font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;margin-top:12px"><?= t('users.col_role') ?></div>
            <div><span class="role-badge <?= $role ?>"><?= $role ?></span></div>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>
</div>

<!-- ── User Create/Edit Modal ─────────────────────────────────── -->
<div class="umodal" id="umodal" onclick="if(event.target===this)closeUModal()">
  <div class="umodal-box">
    <div class="umodal-title" id="umodal-title"><?= t('users.create') ?></div>
    <input type="hidden" id="umodal-id">
    <div class="field" id="umodal-username-wrap">
      <label><?= t('users.col_username') ?></label>
      <input type="text" id="umodal-username" autocomplete="off">
    </div>
    <div class="field">
      <label><?= t('cfg.password') ?> <span id="umodal-pw-hint" style="color:var(--muted);font-weight:400;text-transform:none;letter-spacing:0"></span></label>
      <input type="password" id="umodal-password" autocomplete="new-password">
    </div>
    <div class="field">
      <label><?= t('users.role') ?></label>
      <select id="umodal-role" style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:9px 12px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.875rem;outline:none;width:100%">
        <option value="viewer">viewer – Nur browsen</option>
        <option value="editor">editor – Browsen + Queue</option>
        <option value="admin">admin – Vollzugriff</option>
      </select>
    </div>
    <div class="settings-msg" id="umodal-msg"></div>
    <div class="umodal-actions">
      <button class="btn-secondary" onclick="closeUModal()"><?= t('btn.cancel') ?></button>
      <button class="btn-primary" id="umodal-submit" onclick="submitUModal()"><?= t('users.create') ?></button>
    </div>
  </div>
</div>

<!-- ── Series Modal ───────────────────────────────────────────── -->
<div class="modal-overlay" id="edit-server-modal" onclick="if(event.target===this)closeEditServerModal()" style="display:none;z-index:1045">
  <div class="modal-box" style="max-width:420px;width:100%">
    <div class="modal-header">
      <div class="modal-title">✏️ <?= t('cfg.servers') ?></div>
      <button class="modal-close" onclick="closeEditServerModal()">✕</button>
    </div>
    <div style="padding:16px;display:flex;flex-direction:column;gap:10px">
      <div class="field"><label><?= t('cfg.server') ?> Name</label><input type="text" id="esrv-name" placeholder="Mein Server"></div>
      <div class="field"><label><?= t('cfg.server_ip') ?></label><input type="text" id="esrv-ip" placeholder="line.example.com" autocomplete="off"></div>
      <div class="field"><label><?= t('cfg.port') ?></label><input type="text" id="esrv-port" placeholder="80" style="max-width:120px"></div>
      <div class="field"><label><?= t('cfg.username') ?></label><input type="text" id="esrv-username" autocomplete="off"></div>
      <div class="field"><label><?= t('cfg.password') ?></label><input type="password" id="esrv-password" autocomplete="new-password"></div>
      <div class="settings-msg" id="esrv-msg"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
        <button class="btn-secondary" onclick="closeEditServerModal()"><?= t('btn.cancel') ?></button>
        <button class="btn-primary"   onclick="saveEditServer()"><?= t('btn.save') ?></button>
      </div>
    </div>
  </div>
</div>

<!-- Generic Confirm Modal -->
<div class="modal-overlay" id="confirm-modal" style="display:none;z-index:1060" onclick="if(event.target===this)_confirmResolve(false)">
  <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:28px 32px;max-width:400px;width:90%;text-align:center">
    <div id="confirm-modal-icon" style="font-size:2rem;margin-bottom:12px"></div>
    <div id="confirm-modal-title" style="font-family:'Bebas Neue',sans-serif;font-size:1.3rem;letter-spacing:.06em;margin-bottom:10px"></div>
    <div id="confirm-modal-msg" style="font-size:.84rem;color:var(--muted);line-height:1.6;margin-bottom:24px"></div>
    <div style="display:flex;gap:10px;justify-content:center">
      <button class="btn-secondary" onclick="_confirmResolve(false)"><?= t('btn.cancel') ?></button>
      <button id="confirm-modal-ok" class="btn-primary" onclick="_confirmResolve(true)"></button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="series-modal" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <div class="modal-header">
      <img class="modal-thumb" id="modal-img" src="" alt="">
      <div><div class="modal-title" id="modal-title"></div><div class="modal-meta" id="modal-meta"></div></div>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body" id="modal-body"></div>
  </div>
</div>

<!-- Server Info Modal -->
<div class="modal-overlay" id="srv-info-modal" onclick="if(event.target===this)closeSrvInfoModal()" style="display:none;z-index:1045">
  <div class="modal-box" style="max-width:360px;width:100%">
    <div class="modal-header">
      <div class="modal-title" id="srv-info-title"></div>
      <button class="modal-close" onclick="closeSrvInfoModal()">✕</button>
    </div>
    <div style="padding:0 16px 16px">
      <div id="srv-info-body"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
        <button class="btn-secondary" onclick="closeSrvInfoModal();showView('settings')"><?= t('nav.settings') ?></button>
        <button class="btn-secondary" onclick="closeSrvInfoModal()"><?= t('btn.cancel') ?></button>
      </div>
    </div>
  </div>
</div>

<!-- Invite Modal -->
<?php if ($can_users): ?>

<!-- Passwort-Reset Modal -->
<div class="modal-overlay" id="pw-reset-modal" onclick="if(event.target===this)closePwResetModal()" style="display:none;z-index:1040">
  <div class="umodal-box" style="max-width:380px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <h3 style="font-size:1rem;font-weight:600">🔑 Passwort zurücksetzen</h3>
      <button class="modal-close" onclick="closePwResetModal()">✕</button>
    </div>
    <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px">
      Passwort für <strong id="pw-reset-username" style="color:var(--text)"></strong> zurücksetzen.
    </div>
    <div class="field">
      <label>Neues Passwort</label>
      <input type="password" id="pw-reset-new" placeholder="Mindestens 6 Zeichen" autocomplete="new-password">
    </div>
    <div class="field">
      <label>Passwort bestätigen</label>
      <input type="password" id="pw-reset-confirm" placeholder="Passwort wiederholen" autocomplete="new-password">
    </div>
    <div style="display:flex;gap:8px;margin-top:4px">
      <button class="btn-primary" onclick="submitPwReset()" style="flex:1"><?= t('cfg.save') ?></button>
      <button class="btn-secondary" onclick="closePwResetModal()"><?= t('btn.cancel') ?></button>
    </div>
    <div class="settings-msg" id="pw-reset-msg" style="margin-top:8px"></div>
  </div>
</div>

<!-- User-Verlauf Modal -->
<div class="modal-overlay" id="user-history-modal" onclick="if(event.target===this)closeUserHistoryModal()" style="display:none;z-index:1040">
  <div class="umodal-box" style="max-width:560px;max-height:80vh;display:flex;flex-direction:column">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-shrink:0">
      <div>
        <h3 style="font-size:1rem;font-weight:600">📋 Download-Verlauf</h3>
        <div id="user-history-meta" style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);margin-top:2px"></div>
      </div>
      <button class="modal-close" onclick="closeUserHistoryModal()">✕</button>
    </div>
    <div id="user-history-list" style="overflow-y:auto;flex:1">
      <div style="color:var(--muted);text-align:center;padding:24px"><?= t('status.loading') ?></div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($can_users): ?>
<div class="modal-overlay" id="invite-modal" onclick="if(event.target===this)closeInviteModal()" style="display:none;z-index:1040">
  <div class="umodal-box" style="max-width:420px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <h3 style="font-size:1rem;font-weight:600">🔗 Einladungslink erstellen</h3>
      <button class="modal-close" onclick="closeInviteModal()">✕</button>
    </div>
    <div class="field">
      <label><?= t('users.role') ?></label>
      <select id="invite-role" style="width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:10px 14px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.9rem;outline:none">
        <option value="viewer">Viewer</option>
        <option value="editor">Editor</option>
        <option value="admin">Admin</option>
      </select>
    </div>
    <div class="field">
      <label><?= t('cfg.invite_expires') ?></label>
      <select id="invite-hours" style="width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:10px 14px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.9rem;outline:none">
        <option value="6">6 Stunden</option>
        <option value="24" selected>24 Stunden</option>
        <option value="72">3 Tage</option>
        <option value="168">7 Tage</option>
      </select>
    </div>
    <div class="field">
      <label>Notiz (optional)</label>
      <input type="text" id="invite-note" placeholder="z.B. Für Familie Müller" style="width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:10px 14px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.9rem;outline:none">
    </div>
    <!-- Ergebnis -->
    <div id="invite-result" style="display:none;margin-top:4px">
      <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin-bottom:6px">Link kopieren</div>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="text" id="invite-link-output" readonly
          style="flex:1;background:var(--bg3);border:1px solid var(--accent);border-radius:6px;padding:8px 12px;color:var(--accent2);font-family:'DM Mono',monospace;font-size:.72rem;outline:none">
        <button class="btn-secondary" onclick="copyInviteLink()">📋</button>
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:16px">
      <button class="btn-primary" id="btn-create-invite" onclick="createInvite()" style="flex:1"><?= t('invite.create') ?></button>
      <button class="btn-secondary" onclick="closeInviteModal()"><?= t('btn.close') ?></button>
    </div>
    <div class="settings-msg" id="invite-msg" style="margin-top:8px"></div>
  </div>
</div>
<?php endif; ?>

<!-- TMDB Info Modal -->
<div class="modal-overlay" id="tmdb-modal" onclick="if(event.target===this)closeTmdbModal()" style="display:none;z-index:1050">
  <div class="modal" style="max-width:520px;overflow:hidden;padding:0">
    <div id="tmdb-backdrop" style="width:100%;height:160px;background:var(--bg3);background-size:cover;background-position:center top;position:relative;flex-shrink:0">
      <button class="modal-close" onclick="closeTmdbModal()" style="position:absolute;top:10px;right:10px;background:rgba(0,0,0,.6);z-index:10">✕</button>
    </div>
    <div style="display:flex;gap:16px;padding:20px 20px 0;align-items:flex-start">
      <img id="tmdb-poster" src="" alt="" style="width:80px;min-width:80px;border-radius:6px;border:2px solid var(--border);margin-top:-40px;box-shadow:0 4px 16px rgba(0,0,0,.4);display:none">
      <div style="min-width:0;flex:1;padding-top:4px">
        <div id="tmdb-title" style="font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:.06em;line-height:1.1;margin-bottom:4px"></div>
        <div id="tmdb-meta" style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.05em"></div>
      </div>
    </div>
    <div style="padding:16px 20px 20px">
      <div id="tmdb-rating" style="display:flex;align-items:center;gap:8px;margin-bottom:14px"></div>
      <div id="tmdb-overview" style="font-size:.875rem;line-height:1.6;color:var(--muted)"></div>
      <div id="tmdb-genres" style="margin-top:12px;display:flex;flex-wrap:wrap;gap:6px"></div>
      <div id="tmdb-stream-info" style="margin-top:14px;display:none">
        <div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin-bottom:8px">Stream-Info</div>
        <div id="tmdb-stream-badges" style="display:flex;flex-wrap:wrap;gap:6px"></div>
      </div>
      <div id="tmdb-actions" style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap"></div>
      <div id="tmdb-loading" style="text-align:center;padding:24px;color:var(--muted);font-size:.85rem"><?= t('status.loading') ?></div>
      <div id="tmdb-error" style="display:none;text-align:center;padding:16px;color:var(--muted);font-size:.82rem"></div>
    </div>
  </div>
</div>
<div class="modal-overlay" id="reveal-modal" onclick="if(event.target===this)closeRevealModal()" style="display:none;z-index:1100">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <div class="modal-title" id="reveal-modal-title">API-Key anzeigen</div>
      <button class="modal-close" onclick="closeRevealModal()">✕</button>
    </div>
    <div style="padding:0 24px 24px">
      <div id="reveal-password-section">
        <p style="font-size:.85rem;color:var(--muted);margin-bottom:16px;line-height:1.5">
          Gib dein Admin-Passwort ein um den API-Key anzuzeigen.
        </p>
        <div class="field" style="margin-bottom:12px">
          <label><?= t('cfg.password') ?></label>
          <input type="password" id="reveal-password-input" placeholder="<?= t('cfg.password') ?>"
            onkeydown="if(event.key==='Enter')submitReveal()">
        </div>
        <div id="reveal-error" style="font-size:.78rem;color:var(--red);margin-bottom:10px;display:none"></div>
        <div style="display:flex;gap:8px">
          <button class="btn-primary" onclick="submitReveal()"><?= t('btn.save') ?></button>
          <button class="btn-secondary" onclick="closeRevealModal()"><?= t('btn.cancel') ?></button>
        </div>
      </div>
      <div id="reveal-key-section" style="display:none">
        <p style="font-size:.82rem;color:var(--muted);margin-bottom:12px">
          Kopiere den Key — er wird nach dem Schließen nicht mehr angezeigt.
        </p>
        <div style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:12px 14px;
                    font-family:'DM Mono',monospace;font-size:.78rem;word-break:break-all;
                    color:var(--accent);margin-bottom:12px" id="reveal-key-value"></div>
        <div style="display:flex;gap:8px">
          <button class="btn-primary" onclick="copyRevealKey()"><?= t('cfg.api_key_copy') ?> Kopieren</button>
          <button class="btn-secondary" onclick="closeRevealModal()"><?= t('btn.close') ?></button>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>
<div id="dup-toast" style="display:none;position:fixed;bottom:24px;left:50%;transform:translateX(-50%);
     background:var(--bg2);border:1px solid var(--orange);border-radius:10px;
     padding:14px 18px;z-index:9999;box-shadow:0 8px 32px rgba(0,0,0,.5);
     max-width:420px;width:calc(100% - 48px);font-size:.82rem">
  <div style="color:var(--orange);font-weight:600;margin-bottom:6px"><?= t('dup.title') ?></div>
  <div style="color:var(--muted);margin-bottom:12px;line-height:1.5">
    <?= t('dup.body') ?><br>
    <span id="dup-match-title" style="color:var(--text);font-style:italic"></span>
  </div>
  <div style="display:flex;gap:8px;justify-content:flex-end">
    <button class="btn-secondary" onclick="closeDupToast()" style="font-size:.75rem;padding:5px 12px"><?= t('btn.cancel') ?></button>
    <button class="btn-primary"   onclick="forceQueueAdd()" style="font-size:.75rem;padding:5px 12px"><?= t('dup.force') ?></button>
  </div>
</div>

<script>
const API = 'api.php';
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const LANG = <?= json_encode(load_lang()) ?>;
const CURRENT_LANG = <?= json_encode(get_user_lang()) ?>;
function t(key, vars = {}) {
  let s = LANG[key] ?? key;
  for (const [k, v] of Object.entries(vars)) s = s.replaceAll('{{' + k + '}}', v);
  return s;
}
const canQueueAdd       = <?= $can_queue_add        ? 'true' : 'false' ?>;
const canQueueRemove    = <?= $can_queue_remove     ? 'true' : 'false' ?>;
const canQueueRemoveOwn = <?= $can_queue_remove_own ? 'true' : 'false' ?>;
const canSeeAddedBy     = <?= $can_settings         ? 'true' : 'false' ?>;
const isEditor          = <?= ($role === 'editor')  ? 'true' : 'false' ?>;
const currentUsername   = <?= json_encode($user['username']) ?>;
const ACTIVE_SERVER_IDS = new Set(<?= json_encode(array_column(array_filter(
    file_exists(__DIR__ . '/data/servers.json')
        ? (json_decode(file_get_contents(__DIR__ . '/data/servers.json'), true) ?? [])
        : [],
    fn($s) => ($s['enabled'] ?? true) !== false
), 'id')) ?>);
let currentView   = 'dashboard';
let _queuedIds    = new Set(); // stream_ids aktuell in der Queue (non-done)
let _downloadedIds = new Set(); // stream_ids bereits heruntergeladen (done)
let _downloadingIds = new Set(); // stream_ids gerade im Download
let _seenIds      = new Set(); // stream_ids die der User angeschaut hat (TMDB-Modal geöffnet)
try { _seenIds = new Set(Object.keys(JSON.parse(localStorage.getItem('xv_seen_<?= $user['id'] ?>') || '{}'))) } catch(e) {}
let _recentIds    = new Set(); // stream_ids in den letzten 24h heruntergeladen
let currentFilter = 'all';
let allMovies     = [];
let searchDebounce;
let queueRefreshInterval;

// ── Init ──────────────────────────────────────────────────────
setTimeout(async () => {
  const stats = await api('stats');
  if (stats.configured === false) {
    <?php if ($can_settings): ?>
    showView('settings');
    <?php else: ?>
    showView('dashboard');
    <?php endif; ?>
    document.getElementById('unconfigured-banner') && (document.getElementById('unconfigured-banner').style.display = '');
    <?php if ($can_settings): ?>showToast('Bitte zuerst die Zugangsdaten hinterlegen', 'error');<?php endif; ?>
    return;
  }
  document.getElementById('stat-movies').textContent   = stats.movies   ?? '–';
  document.getElementById('stat-episodes').textContent = stats.episodes ?? '–';
  if (stats.queued != null) updateQueuePill(stats.queued);
  refreshDashboard();
  const catLoaders = [];
  <?php if ($show_movies): ?>catLoaders.push(loadMovieCats());<?php endif; ?>
  <?php if ($show_series): ?>catLoaders.push(loadSeriesCats());<?php endif; ?>
  catLoaders.push(loadFavourites());
  await Promise.all(catLoaders);
  <?php if ($can_queue_view): ?>updateQueueBadge();<?php endif; ?>
  <?php if (!$can_settings): ?>loadUserDashboard();<?php endif; ?>
  <?php if ($can_settings): ?>startDashboardPolling();<?php endif; ?>
  <?php if ($role === 'editor'): ?>loadLimitStatus();<?php endif; ?>
  initTheme();
  <?php if ($can_settings): ?>startVpnPolling();<?php endif; ?>
  <?php if ($can_queue_view && $can_settings): ?>startProgressPolling();<?php endif; ?>
  // Neue-Releases-Badge beim Start laden
  api('get_new_releases').then(d => {
    const total = d.movies?.length ?? 0;
    const badge = document.getElementById('new-releases-badge');
    if (badge && total > 0) { badge.textContent = total; badge.style.display = ''; }
  });
  <?php if ($can_settings): ?>
  // Update-Check im Hintergrund (still, ohne UI-Feedback)
  api('check_update').then(d => {
    if (d && !d.error && !d.up_to_date) {
      const badge = document.getElementById('update-badge');
      if (badge) badge.style.display = '';
    }
  });
  <?php endif; ?>
}, 0);

// ── Theme Toggle ──────────────────────────────────────────────
const THEMES = {
  dark:     { label: 'Dark',       bg: '#0a0a0f', bg2: '#111118', accent: '#e8ff47' },
  amoled:   { label: 'AMOLED',     bg: '#000000', bg2: '#080808', accent: '#e8ff47' },
  midnight: { label: 'Midnight',   bg: '#0a0e1a', bg2: '#111828', accent: '#64a0ff' },
  nord:     { label: 'Nord',       bg: '#2e3440', bg2: '#3b4252', accent: '#88c0d0' },
  tokyo:    { label: 'Tokyo Night',bg: '#1a1b2e', bg2: '#16213e', accent: '#7aa2f7' },
  rosepine: { label: 'Rosé Pine',  bg: '#191724', bg2: '#1f1d2e', accent: '#ebbcba' },
  light:    { label: 'Light',      bg: '#f0f0f5', bg2: '#ffffff', accent: '#6060e0' },
};
const THEME_KEY = 'xv_theme_<?= $user['id'] ?>';

function initTheme() {
  const saved = localStorage.getItem(THEME_KEY) || 'dark';
  applyTheme(saved, false);
}
function applyTheme(theme, save = true) {
  const t = THEMES[theme] ? theme : 'dark';
  document.documentElement.setAttribute('data-theme', t === 'dark' ? '' : t);
  if (save) localStorage.setItem(THEME_KEY, t);
  // Aktiven Swatch markieren
  document.querySelectorAll('.theme-swatch').forEach(el => {
    el.classList.toggle('active', el.dataset.theme === t);
  });
}
function renderThemePicker() {
  const cur = localStorage.getItem(THEME_KEY) || 'dark';
  return Object.entries(THEMES).map(([key, t]) => `
    <div class="theme-swatch${cur === key ? ' active' : ''}" data-theme="${key}" onclick="applyTheme('${key}')" title="${t.label}">
      <div class="theme-swatch-preview">
        <div class="theme-swatch-sidebar" style="background:${t.bg2}"></div>
        <div class="theme-swatch-content" style="background:${t.bg};display:flex;align-items:flex-end;padding:4px">
          <div style="height:6px;width:60%;background:${t.accent};border-radius:2px"></div>
        </div>
      </div>
      <div class="theme-swatch-label" style="background:${t.bg2};color:${t.accent}">${t.label}</div>
    </div>`).join('');
}

// ── View Mode (Karten / Liste) ────────────────────────────────
const VIEW_MODE_KEY = 'xv_view_mode_<?= $user['id'] ?>';
let _viewMode = localStorage.getItem(VIEW_MODE_KEY) || 'grid';

function applyViewMode(mode) {
  _viewMode = mode;
  localStorage.setItem(VIEW_MODE_KEY, mode);
  const isListMode = mode === 'list';
  // Alle betroffenen Grids aktualisieren
  ['movie-grid', 'series-grid', 'fav-grid'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('list-mode', isListMode);
  });
  // Toggle-Buttons aktualisieren
  document.querySelectorAll('.view-mode-btn').forEach(btn => {
    btn.textContent  = isListMode ? '⊞' : '☰';
    btn.title        = isListMode ? 'Kartenansicht' : 'Listenansicht';
    btn.classList.toggle('active', isListMode);
  });
}

function toggleViewMode() {
  applyViewMode(_viewMode === 'grid' ? 'list' : 'grid');
}

// View-Mode beim Start anwenden
applyViewMode(_viewMode);

// ── Search History ────────────────────────────────────────────
const SEARCH_HISTORY_KEY = 'xv_search_history_<?= $user['id'] ?>';
const CAT_HISTORY_KEY    = 'xv_cat_history_<?= $user['id'] ?>';
const MAX_HISTORY = 10;

function addSearchHistory(query) {
  if (!query || query.length < 2) return;
  let h = getSearchHistory();
  h = [query, ...h.filter(q => q !== query)].slice(0, MAX_HISTORY);
  localStorage.setItem(SEARCH_HISTORY_KEY, JSON.stringify(h));
}
function getSearchHistory() {
  try { return JSON.parse(localStorage.getItem(SEARCH_HISTORY_KEY) || '[]'); } catch { return []; }
}
function addCatHistory(type, catId, catName) {
  let h = getCatHistory();
  const entry = {type, catId, catName, ts: Date.now()};
  h = [entry, ...h.filter(c => !(c.type === type && c.catId === catId))].slice(0, MAX_HISTORY);
  localStorage.setItem(CAT_HISTORY_KEY, JSON.stringify(h));
}
function getCatHistory() {
  try { return JSON.parse(localStorage.getItem(CAT_HISTORY_KEY) || '[]'); } catch { return []; }
}
function renderSearchHistory() {
  const box = document.getElementById('search-history-box');
  if (!box) return;
  const history = getSearchHistory();
  if (!history.length) { box.innerHTML = ''; return; }
  let html = `<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
    <div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);letter-spacing:.12em;text-transform:uppercase">${t('search.history')}</div>
    <button onclick="clearSearchHistory()" style="background:none;border:none;font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);cursor:pointer;padding:0;letter-spacing:.08em;text-transform:uppercase;transition:color .15s" onmouseover="this.style.color='var(--red)'" onmouseout="this.style.color='var(--muted)'">${t('search.clear_history')}</button>
  </div>`;
  html += `<div style="display:flex;flex-wrap:wrap;gap:6px">`;
  html += history.map(q => {
    const escaped = esc(q).replace(/'/g, '&#39;');
    return `<button class="history-chip" onclick="doSearchFromHistory('${escaped}')" style="background:var(--bg3);border:1px solid var(--border);border-radius:20px;padding:4px 12px;font-size:.78rem;cursor:pointer;color:var(--text);font-family:'DM Sans',sans-serif;transition:border-color .15s,color .15s">🔍 ${esc(q)}</button>`;
  }).join('');
  html += `</div>`;
  box.innerHTML = html;
}
function clearSearchHistory() {
  localStorage.removeItem(SEARCH_HISTORY_KEY);
  renderSearchHistory();
}
function doSearchFromHistory(query) {
  document.getElementById('search-input').value = query;
  doSearch(query);
}
function loadCatFromHistory(type, catId, catName) {
  if (type === 'series') {
    loadSeriesCat(catId, catName, null);
    showView('series');
  } else {
    loadMovies(catId, catName, null);
    showView('movies');
  }
}

// ── Sorting ───────────────────────────────────────────────────
let currentSort = 'default';

// ── Stats ─────────────────────────────────────────────────────
async function loadStats() {
  const d = await api('stats');
  document.getElementById('stat-movies').textContent   = d.movies   ?? '–';
  document.getElementById('stat-episodes').textContent = d.episodes ?? '–';
  document.getElementById('stat-queued').textContent   = d.queued   ?? 0;
  // Optimistischen Delta berücksichtigen
  const serverQueued = d.queued ?? 0;
  if (_optimisticDelta === 0) {
    updateQueuePill(serverQueued);
  } else {
    // Server kennt neuen Stand noch nicht — Delta beibehalten
    updateQueuePill(Math.max(0, serverQueued + _optimisticDelta));
  }
  if (Array.isArray(d.downloaded_ids)) {
    _downloadedIds = new Set(d.downloaded_ids);
    if (Array.isArray(d.recently_downloaded_ids)) {
      _recentIds = new Set(d.recently_downloaded_ids);
    }
  }
  if (currentView === 'favourites') updateFavouriteButtons();
}

function updateQueuePill(n) {
  const pill  = document.getElementById('queue-pill');
  const badge = document.getElementById('nav-badge');
  const stat  = document.getElementById('stat-queued');
  const cnt   = document.getElementById('pill-count');
  if (cnt)   cnt.textContent = n;
  if (pill)  pill.classList.toggle('show', n > 0);
  if (badge) { badge.textContent = n; badge.classList.toggle('show', n > 0); }
  if (stat)  stat.textContent = n;
}

// Optimistisch Badge anpassen ohne API-Call
let _optimisticDelta = 0;
function adjustQueueBadge(delta) {
  _optimisticDelta += delta;
  const cnt = document.getElementById('pill-count');
  const current = parseInt(cnt?.textContent || '0');
  updateQueuePill(Math.max(0, current + delta));
  // Dashboard-KPI sofort anpassen
  const dqsPending = document.getElementById('dqs-pending');
  if (dqsPending) {
    const cur = parseInt(dqsPending.textContent || '0');
    dqsPending.textContent = Math.max(0, cur + delta);
  }
}

async function updateQueueBadge() {
  <?php if ($can_queue_view): ?>
  const items = await api('get_queue');
  if (Array.isArray(items)) {
    _queuedIds = new Set(items.filter(i => i.status !== 'done').map(i => String(i.stream_id)));
    _downloadingIds = new Set(items.filter(i => i.status === 'downloading').map(i => String(i.stream_id)));
    items.filter(i => i.status === 'done').forEach(i => _downloadedIds.add(String(i.stream_id)));
    const serverPending = items.filter(i => i.status === 'pending').length;
    _optimisticDelta = 0;
    updateQueuePill(serverPending);
    if (currentView === 'favourites') updateFavouriteButtons();
  }
  <?php endif; ?>
}

// ── Globales Badge-Polling (alle 10s, unabhängig von der aktuellen View) ──────
let _badgeInterval = null;
function startBadgePolling() {
  if (_badgeInterval) return;
  _badgeInterval = setInterval(updateQueueBadge, 3000);
}
startBadgePolling();

// ── URL-Parameter und Hash beim Start lesen ───────────────────
// setTimeout(0) stellt sicher dass alle let-Deklarationen geparst sind
setTimeout(function applyUrlParams() {
  const validViews = ['dashboard','movies','series','search','queue','log','settings','users','activity-log','profile','favourites','new-releases','api-docs','stats'];
  const params = new URLSearchParams(window.location.search);
  const hash   = window.location.hash.replace('#', '');
  const view   = params.get('view') || (validViews.includes(hash) ? hash : null);
  if (view && validViews.includes(view)) {
    if (view === 'movies') {
      showView('movies');
      const cat = params.get('cat') || '';
      if (cat) {
        const tryClick = (n = 0) => {
          const match = Array.from(document.querySelectorAll('#cats-movies .cat-item'))
            .find(el => el.textContent.trim() === cat);
          if (match) match.click();
          else if (n < 20) setTimeout(() => tryClick(n + 1), 300);
        };
        tryClick();
      }
    } else if (view === 'series') {
      showView('series');
      const cat = params.get('cat') || '';
      if (cat) {
        const tryClick = (n = 0) => {
          const match = Array.from(document.querySelectorAll('#cats-series .cat-item'))
            .find(el => el.textContent.trim() === cat);
          if (match) match.click();
          else if (n < 20) setTimeout(() => tryClick(n + 1), 300);
        };
        tryClick();
      }
    } else if (view === 'search') {
      showView('search');
      const q = params.get('q') || '';
      if (q) { const inp = document.getElementById('search-input'); if (inp) { inp.value = q; doSearch(q); } }
    } else {
      showView(view);
    }
  }
  // Hash-Navigation (Rückwärtskompatibilität)
  window.addEventListener('popstate', () => {
    const h = window.location.hash.replace('#', '');
    if (h && validViews.includes(h)) showView(h);
  });
}, 0);

// ── Categories ────────────────────────────────────────────────
async function loadMovieCats() {
  const el = document.getElementById('cats-movies');
  if (!el) return;
  const groups = await api('get_all_movie_categories');
  if (!Array.isArray(groups)) return;
  const multiServer = groups.length > 1;
  el.innerHTML = groups.map(g => {
    if (!multiServer) {
      return (g.categories ?? []).map(c =>
        `<div class="cat-item" onclick="loadMovies('${c.category_id}','${esc(c.category_name)}',this,'${esc(g.server_id)}')">${esc(c.category_name)}</div>`
      ).join('');
    }
    const gid = 'srvgrp-m-' + esc(g.server_id);
    const items = (g.categories ?? []).map(c =>
      `<div class="cat-item" onclick="loadMovies('${c.category_id}','${esc(c.category_name)}',this,'${esc(g.server_id)}')">${esc(c.category_name)}</div>`
    ).join('');
    return `<div class="cat-server-label" onclick="toggleServerGroup('${gid}',this)"><span class="srv-arrow">▾</span> 🌐 ${esc(g.server_name)}</div>
<div class="cat-server-group" id="${gid}">${items}</div>`;
  }).join('');
}

async function loadSeriesCats() {
  const el = document.getElementById('cats-series');
  if (!el) return;
  const groups = await api('get_all_series_categories');
  if (!Array.isArray(groups)) return;
  const multiServer = groups.length > 1;
  el.innerHTML = groups.map(g => {
    if (!multiServer) {
      return (g.categories ?? []).map(c =>
        `<div class="cat-item" onclick="loadSeriesCat('${c.category_id}','${esc(c.category_name)}',this,'${esc(g.server_id)}')">${esc(c.category_name)}</div>`
      ).join('');
    }
    const gid = 'srvgrp-s-' + esc(g.server_id);
    const items = (g.categories ?? []).map(c =>
      `<div class="cat-item" onclick="loadSeriesCat('${c.category_id}','${esc(c.category_name)}',this,'${esc(g.server_id)}')">${esc(c.category_name)}</div>`
    ).join('');
    return `<div class="cat-server-label" onclick="toggleServerGroup('${gid}',this)"><span class="srv-arrow">▾</span> 🌐 ${esc(g.server_name)}</div>
<div class="cat-server-group" id="${gid}">${items}</div>`;
  }).join('');
}
function toggleCats(type) {
  document.getElementById('cats-' + type).classList.toggle('open');
}
function toggleServerGroup(id, label) {
  const group = document.getElementById(id);
  if (!group) return;
  const collapsed = group.classList.toggle('collapsed');
  const arrow = label?.querySelector('.srv-arrow');
  if (arrow) arrow.textContent = collapsed ? '▸' : '▾';
}

// ── Movies ────────────────────────────────────────────────────
const PAGE_SIZE = 50;
let _moviePage = 1;
let _seriesPage = 1;
let _lastMovies = [];
let _lastSeries = [];
let _movieSort  = 'default';
let _seriesSort = 'default';

async function loadMovies(catId, catName, el, serverId = '') {
  setActiveCat(el);
  showView('movies');
  document.getElementById('page-title').textContent = catName;
  document.getElementById('movie-grid').innerHTML = loadingHTML();
  addCatHistory('movies', catId, catName);
  const params = {category_id: catId};
  if (serverId) params.server_id = serverId;
  _lastMovies = await api('get_movies', params);
  _lastMovies = _lastMovies.map(m => ({...m, category: m.category || catName, _server_id: serverId || m._server_id || ''}));
  _moviePage = 1;
  // Filter zurücksetzen bei neuer Kategorie
  resetMovieFilters();
  // Genre-Dropdown neu befüllen
  buildGenreOptions();
}

function setSortOrder(order, type) {
  if (type === 'movies') { _movieSort = order; _moviePage = 1; renderMovies(); }
  else                   { _seriesSort = order; _seriesPage = 1; renderSeriesGrid(_lastSeries); }
}

let _movieFilters = { rating: 0, genre: '', format: '' };

function setFormatFilter(fmt, btn) {
  _movieFilters.format = fmt;
  document.querySelectorAll('#fmt-all,#fmt-mkv,#fmt-mp4').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  _moviePage = 1;
  renderMovies();
}

function toggleMovieFilters() {
  const panel = document.getElementById('movie-filter-panel');
  const btn   = document.getElementById('movie-filter-toggle');
  const open  = panel.style.display === 'none';
  panel.style.display = open ? '' : 'none';
  btn.textContent = open ? '⚡ Filter ✓' : '⚡ Filter';
  if (open) buildGenreOptions();
}

function buildGenreOptions() {
  const sel = document.getElementById('filter-genre');
  if (!sel) return;
  const cats = new Set();
  _lastMovies.forEach(m => {
    const cat = (m.category || '').trim();
    if (cat) cats.add(cat);
  });
  const current = sel.value;
  sel.style.color = '';
  sel.innerHTML = '<option value="">Alle Kategorien</option>' +
    [...cats].sort().map(c => `<option value="${c}"${c===current?' selected':''}>${c}</option>`).join('');
}

function applyMovieFilters() {
  _movieFilters.rating   = parseFloat(document.getElementById('filter-rating')?.value)  || 0;
  _movieFilters.genre    = document.getElementById('filter-genre')?.value || '';
  _moviePage = 1;
  renderMovies();
}

function resetMovieFilters() {
  _movieFilters = { rating: 0, genre: '', format: '' };
  const rt = document.getElementById('filter-rating');    if (rt) rt.value = 0;
  const rv = document.getElementById('filter-rating-val');if (rv) rv.textContent = 'Alle';
  const gn = document.getElementById('filter-genre');     if (gn) gn.value = '';
  document.querySelectorAll('#fmt-all,#fmt-mkv,#fmt-mp4').forEach(b => b.classList.remove('active'));
  const fmtAll = document.getElementById('fmt-all'); if (fmtAll) fmtAll.classList.add('active');
  _moviePage = 1;
  renderMovies();
}

function renderMovies() {
  const grid = document.getElementById('movie-grid');
  let movies = _lastMovies;
  // Status-Filter (Topbar)
  if (currentFilter === 'done')   movies = movies.filter(m => m.downloaded);
  if (currentFilter === 'new')    movies = movies.filter(m => !m.downloaded && !m.queued);
  if (currentFilter === 'queued') movies = movies.filter(m => m.queued);
  // Erweiterte Filter
  if (_movieFilters.rating) {
    movies = movies.filter(m => {
      // rating_5based ist 0-5 Skala → auf 10 normalisieren
      const r5 = parseFloat(m.rating_5based || 0);
      const r10 = r5 > 0 ? r5 * 2 : parseFloat(m.rating || 0);
      return r10 >= _movieFilters.rating;
    });
  }
  if (_movieFilters.genre)  movies = movies.filter(m => (m.category || '').toLowerCase().includes(_movieFilters.genre.toLowerCase()));
  if (_movieFilters.format) movies = movies.filter(m => (m.container_extension || 'mkv').toLowerCase() === _movieFilters.format);
  // Sortierung
  if (_movieSort === 'az')          movies = [...movies].sort((a,b) => (a.clean_title||'').localeCompare(b.clean_title||'', 'de'));
  if (_movieSort === 'za')          movies = [...movies].sort((a,b) => (b.clean_title||'').localeCompare(a.clean_title||'', 'de'));
  if (_movieSort === 'rating_desc') movies = [...movies].sort((a,b) => (parseFloat(b.rating_5based??b.rating??0)) - (parseFloat(a.rating_5based??a.rating??0)));
  if (_movieSort === 'rating_asc')  movies = [...movies].sort((a,b) => (parseFloat(a.rating_5based??a.rating??0)) - (parseFloat(b.rating_5based??b.rating??0)));
  if (_movieSort === 'recent')      movies = [...movies].sort((a,b) => (b.added??b.stream_id??0) - (a.added??a.stream_id??0));
  // Filter-Zähler
  const countEl = document.getElementById('movie-filter-count');
  if (countEl) {
    const hasFilter = _movieFilters.rating || _movieFilters.genre || _movieFilters.format;
    countEl.textContent = hasFilter ? `${movies.length} von ${_lastMovies.length} Filmen` : '';
  }
  if (!movies.length) { grid.innerHTML = emptyHTML('Keine Filme'); document.getElementById('movie-pagination').innerHTML = ''; return; }
  const total = movies.length;
  const pages = Math.ceil(total / PAGE_SIZE);
  const page  = Math.min(_moviePage, pages);
  const slice = movies.slice((page-1)*PAGE_SIZE, page*PAGE_SIZE);
  grid.innerHTML = slice.map(movieCard).join('');
  lazyLoadImages();
  renderPagination('movie-pagination', page, pages, p => { _moviePage = p; renderMovies(); });
}

function renderPagination(containerId, current, total, onPage) {
  const el = document.getElementById(containerId);
  if (!el) return;
  if (total <= 1) { el.innerHTML = ''; return; }
  let html = '';
  if (current > 1) html += `<button class="pagination-btn" onclick="(${onPage})(${current-1})">← Zurück</button>`;
  // Show max 7 page buttons
  const start = Math.max(1, current - 3);
  const end   = Math.min(total, start + 6);
  for (let p = start; p <= end; p++) {
    html += `<button class="pagination-btn${p===current?' active':''}" onclick="(${onPage})(${p})">${p}</button>`;
  }
  if (current < total) html += `<button class="pagination-btn" onclick="(${onPage})(${current+1})">Weiter →</button>`;
  html += `<div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);margin-top:8px">Seite ${current} von ${total}</div>`;
  el.innerHTML = html;
}

function movieCard(m, showServer = false) {
  registerFavItem('movie', m.stream_id, m);
  const thumb = m.stream_icon ? `<img data-src="${m.stream_icon}" alt="">` : '';
  const badge = m.downloaded
    ? _recentIds.has(String(m.stream_id))
      ? `<span class="card-badge badge-recent">✓ Neu</span>`
      : `<span class="card-badge badge-done">✓ Done</span>`
    : m.queued ? `<span class="card-badge badge-queue">⏳ Queue</span>`
    : _seenIds.has(String(m.stream_id)) ? `<span class="card-badge badge-seen">👁</span>` : '';

  const isDownloading = _downloadingIds.has(String(m.stream_id));
  const btn = m.downloaded
    ? canQueueRemove
      ? `<button class="btn-q done" onclick="resetDownload('${m.stream_id}','movie',null)" title="Zurücksetzen">↺ Reset</button>`
      : `<button class="btn-q done" disabled>✓ Done</button>`
    : isDownloading
      ? `<button class="btn-q done" disabled>⬇ ${t('status.downloading')}</button>`
      : m.queued && canQueueRemove
        ? `<button class="btn-q remove" onclick="removeFromQueue('${m.stream_id}',this.closest('.card'))">✕ Remove</button>`
        : m.queued
          ? `<button class="btn-q done" disabled>⏳ Queued</button>`
          : canQueueAdd
            ? `<button class="btn-q add" onclick="addMovieToQueue(${JSON.stringify(m).replace(/"/g,'&quot;')},this.closest('.card'))">+ Queue</button>`
            : '';

  // "Als heruntergeladen markieren"-Button (nur Admins, nur wenn nicht already done)
  const markBtn = (<?= $can_settings ? 'true' : 'false' ?> && !m.downloaded && !isDownloading)
    ? `<button class="btn-q add" style="font-size:.65rem;padding:4px 6px;opacity:.6" title="${t('btn.mark_downloaded')}"
        onclick="event.stopPropagation();markDownloaded('${m.stream_id}','movie',${JSON.stringify(m.clean_title).replace(/"/g,'&quot;')},'${esc(m.stream_icon||'')}','${esc(m.category||m._category||'')}','${esc(m.container_extension||'mp4')}','${esc(m._server_id||'')}',this.closest('.card'))">✓</button>`
    : '';

  // Multi-select checkbox (only in search view, only if can add to queue)
  const selectBox = (currentView === 'search' && canQueueAdd && !m.downloaded && !m.queued)
    ? `<div class="select-check" onclick="event.stopPropagation();toggleSelectItem('${m.stream_id}',${JSON.stringify(m).replace(/"/g,'&quot;')},this.closest('.card'))"></div>`
    : '';

  const isFav = favourites.has('movie:' + m.stream_id);
  const favBtn = `<button class="btn-fav${isFav?' active':''}" onclick="event.stopPropagation();toggleFavById('movie','${m.stream_id}',this)" title="${isFav?'Aus Favoriten entfernen':'Zu Favoriten hinzufügen'}">♥</button>`;

  const year = m.year ?? '';
  const rating = m.rating_5based ? (parseFloat(m.rating_5based) * 2).toFixed(1) : '';
  const ext = (m.container_extension || 'mkv').toUpperCase();
  const metaParts = [ext];
  if (year) metaParts.push(year);
  if (rating && parseFloat(rating) > 0) metaParts.push(`★ ${rating}`);
  const serverBadge = (showServer && m._server_name)
    ? `<div style="font-family:'DM Mono',monospace;font-size:.55rem;color:var(--accent2);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(m._server_name)}">🌐 ${esc(m._server_name)}</div>`
    : '';

  return `
  <div class="card ${m.downloaded?'downloaded':m.queued?'queued':''}" id="card-m-${m.stream_id}"
    data-tmdb-title="${esc(m.clean_title)}" data-tmdb-type="movie" data-tmdb-year="${esc(year)}"
    data-tmdb-queue="${esc(JSON.stringify(m))}"
    onclick="handleCardClick(event,this)" style="cursor:pointer">
    <div class="card-thumb">
      <div class="card-thumb-placeholder">🎬</div>
      ${thumb}${badge}${selectBox}${favBtn}
    </div>
    <div class="card-body">
      <div class="card-title">${m.clean_title}</div>
      <div class="card-meta">${metaParts.join(' · ')}</div>
      ${serverBadge}
    </div>
    <div class="card-actions" onclick="event.stopPropagation()">${btn}${markBtn}</div>
  </div>`;
}

// ── Series ────────────────────────────────────────────────────
async function loadSeriesCat(catId, catName, el, serverId = '') {
  setActiveCat(el);
  showView('series');
  document.getElementById('page-title').textContent = catName;
  const grid = document.getElementById('series-grid');
  grid.innerHTML = loadingHTML();
  addCatHistory('series', catId, catName);
  const params = {category_id: catId};
  if (serverId) params.server_id = serverId;
  _lastSeries = await api('get_series', params);
  _lastSeries = _lastSeries.map(s => ({...s, category: s.category || catName, _server_id: serverId || s._server_id || ''}));
  _seriesPage = 1;
  renderSeriesGrid(_lastSeries);
}

function renderSeriesGrid(list) {
  const grid = document.getElementById('series-grid');
  if (!list?.length) { grid.innerHTML = emptyHTML('Keine Serien'); document.getElementById('series-pagination').innerHTML = ''; return; }
  let sorted = list;
  if (_seriesSort === 'az')          sorted = [...list].sort((a,b) => (a.clean_title||'').localeCompare(b.clean_title||'', 'de'));
  if (_seriesSort === 'za')          sorted = [...list].sort((a,b) => (b.clean_title||'').localeCompare(a.clean_title||'', 'de'));
  if (_seriesSort === 'rating_desc') sorted = [...list].sort((a,b) => (parseFloat(b.rating??0)) - (parseFloat(a.rating??0)));
  if (_seriesSort === 'rating_asc')  sorted = [...list].sort((a,b) => (parseFloat(a.rating??0)) - (parseFloat(b.rating??0)));
  if (_seriesSort === 'recent')      sorted = [...list].sort((a,b) => (b.series_id??0) - (a.series_id??0));
  const pages = Math.ceil(sorted.length / PAGE_SIZE);
  const page  = Math.min(_seriesPage, pages);
  const slice = sorted.slice((page-1)*PAGE_SIZE, page*PAGE_SIZE);
  grid.innerHTML = slice.map(s => seriesCard({...s})).join('');
  lazyLoadImages();
  renderPagination('series-pagination', page, pages, p => { _seriesPage = p; renderSeriesGrid(_lastSeries); });
}

function seriesCard(s, showServer = false) {
  registerFavItem('series', s.series_id, s);
  const thumb = s.cover ? `<img data-src="${s.cover}" alt="">` : '';
  const isFav = favourites.has('series:' + s.series_id);
  const favBtn = `<button class="btn-fav${isFav?' active':''}" onclick="event.stopPropagation();toggleFavById('series','${s.series_id}',this)" title="${isFav?'Aus Favoriten entfernen':'Zu Favoriten hinzufügen'}">♥</button>`;
  const queueData = {series_id: s.series_id, clean_title: s.clean_title, cover: s.cover || '', category: s.category || '', _server_id: s._server_id || ''};
  const serverBadge = (showServer && s._server_name)
    ? `<div style="font-family:'DM Mono',monospace;font-size:.55rem;color:var(--accent2);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(s._server_name)}">🌐 ${esc(s._server_name)}</div>`
    : '';
  return `
  <div class="card"
    data-tmdb-title="${esc(s.clean_title)}" data-tmdb-type="series" data-tmdb-year=""
    data-tmdb-queue="${esc(JSON.stringify(queueData))}"
    onclick="handleCardClick(event,this)" style="cursor:pointer">
    <div class="card-thumb"><div class="card-thumb-placeholder">📺</div>${thumb}${favBtn}</div>
    <div class="card-body"><div class="card-title">${s.clean_title}</div><div class="card-meta">${s.genre??''}</div>${serverBadge}</div>
    <div class="card-actions" onclick="event.stopPropagation()"><button class="btn-q add" onclick="openSeriesModal(${s.series_id},'${esc(s.clean_title)}','${esc(s.cover||'')}','${esc(s.category||'')}','${esc(s._server_id||'')}')">📋 Episodes</button></div>
  </div>`;
}

// ── Series Modal ──────────────────────────────────────────────
async function openSeriesModal(id, title, cover, category, serverId) {
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-meta').textContent  = t('modal.loading');
  document.getElementById('modal-img').src           = cover || '';
  document.getElementById('modal-body').innerHTML    = `<div class="state-box"><div class="spinner"></div></div>`;
  document.getElementById('series-modal').classList.add('open');
  const data    = await api('get_series_info', {series_id: id, server_id: serverId || ''});
  const episodes = data.episodes ?? {};
  const seasons  = Object.keys(episodes).sort();
  document.getElementById('modal-meta').textContent = `${seasons.length} Season(s)`;
  if (!seasons.length) { document.getElementById('modal-body').innerHTML = emptyHTML(t('modal.no_episodes')); return; }

  // Episode-Objekte in Map speichern — kein JSON in onclick nötig
  window._epMap = {};
  for (const season of seasons) {
    for (const ep of episodes[season]) {
      window._epMap[ep.id] = ep;
    }
  }

  let html = '';
  for (const season of seasons) {
    const eps = episodes[season];
    const seasonNum = parseInt(season, 10) || 1;
    const epIds = eps.map(e => e.id).join(',');
    const pendingCount = eps.filter(e => !e.downloaded && !e.queued && !_downloadingIds.has(String(e.id))).length;
    const queueAllBtn = canQueueAdd && pendingCount > 0
      ? `<span class="season-queue-all" onclick="queueAllSeasonById('${epIds}',${seasonNum},'${esc(title)}','${esc(category||'')}','${esc(serverId||'')}')">⏳ ${t('modal.queue_all')}</span>`
      : '';
    const markAllBtn = <?= $can_settings ? 'true' : 'false' ?>
      ? `<span class="season-queue-all" style="color:var(--accent2);margin-left:4px" onclick="markAllSeasonDownloaded('${epIds}',${seasonNum},'${esc(title)}','${esc(category||'')}','${esc(serverId||'')}')">✓ ${t('btn.mark_all_downloaded')}</span>`
      : '';
    html += `<div class="season-header">${t('modal.season')} ${season} ${queueAllBtn}${markAllBtn}</div>`;

    for (const ep of eps) {
      let epBtn = '';
      if (ep.downloaded) {
        epBtn = canQueueRemove
          ? `<button class="ep-btn done" onclick="resetEpisode('${ep.id}','${ep.id}',${seasonNum},'${esc(title)}','${esc(category||'')}','${esc(serverId||'')}')" title="Zurücksetzen">↺</button>`
          : `<button class="ep-btn done" disabled>✓</button>`;
      } else if (_downloadingIds.has(String(ep.id))) {
        epBtn = `<button class="ep-btn done" disabled>⬇</button>`;
      } else if (ep.queued && canQueueRemove) {
        epBtn = `<button class="ep-btn remove" id="epbtn-${ep.id}" onclick="removeEpFromQueue('${ep.id}',this)">✕</button>`;
      } else if (ep.queued) {
        epBtn = `<button class="ep-btn done" disabled>⏳</button>`;
      } else if (!isEditor && canQueueAdd) {
        // Admins/Viewer sehen Einzel-Buttons; Editoren nur den Staffel-Button
        epBtn = `<button class="ep-btn add" id="epbtn-${ep.id}" onclick="queueEpisodeById('${ep.id}',${seasonNum},'${esc(title)}','${esc(category||'')}','${esc(serverId||'')}',this)">+ Q</button>`;
      }
      // "Als heruntergeladen markieren" — nur Admins, nur wenn nicht already done/downloading
      const markEpBtn = (<?= $can_settings ? 'true' : 'false' ?> && !ep.downloaded && !_downloadingIds.has(String(ep.id)))
        ? `<button class="ep-btn done" style="opacity:.5;margin-left:2px" title="${t('btn.mark_downloaded')}"
            onclick="markEpisodeDownloaded('${ep.id}','${esc(ep.clean_title||ep.title)}','${esc(category||'')}','${esc(ep.container_extension||'mp4')}','${esc(serverId||'')}',this)">✓</button>`
        : '';
      html += `
      <div class="episode-row" id="ep-${ep.id}">
        <span class="ep-num">E${ep.episode_num??'?'}</span>
        <span class="ep-title">${ep.clean_title||ep.title}</span>
        <span class="ep-ext">${(ep.container_extension??'').toUpperCase()}</span>
        ${epBtn}${markEpBtn}
      </div>`;
    }
  }
  document.getElementById('modal-body').innerHTML = html;
}
function closeModal() { document.getElementById('series-modal').classList.remove('open'); }

async function markEpisodeDownloaded(epId, epTitle, category, ext, serverId, btn) {
  const d = await apiPost('mark_downloaded', {
    stream_id: epId, type: 'episode',
    title: epTitle, cover: '', category, ext: ext || 'mp4', server_id: serverId || '',
  });
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  _downloadedIds.add(String(epId));
  // Button auf ✓ setzen und deaktivieren
  const row = document.getElementById('ep-' + epId);
  if (row) {
    const btns = row.querySelectorAll('.ep-btn');
    btns.forEach(b => b.remove());
    const doneBtn = document.createElement('button');
    doneBtn.className = 'ep-btn done'; doneBtn.disabled = true; doneBtn.textContent = '✓';
    row.appendChild(doneBtn);
  }
  showToast(`✓ ${epTitle} ${t('btn.mark_downloaded').toLowerCase()}`, 'success');
}

async function markAllSeasonDownloaded(epIdsCsv, season, seriesTitle, category, serverId) {
  const eps = (epIdsCsv || '').split(',').map(id => window._epMap?.[id]).filter(Boolean);
  const pending = eps.filter(ep => !ep.downloaded && !_downloadingIds.has(String(ep.id)));
  if (!pending.length) { showToast('Keine ausstehenden Episoden', 'info'); return; }
  if (!await showConfirm(`${pending.length} Episoden von Staffel ${season} als heruntergeladen markieren?`, {title:'Staffel markieren?', icon:'✓', okLabel:'Markieren'})) return;
  let count = 0;
  for (const ep of pending) {
    const d = await apiPost('mark_downloaded', {
      stream_id: ep.id, type: 'episode',
      title: ep.clean_title || ep.title, cover: '', category: seriesTitle,
      ext: ep.container_extension || 'mp4', server_id: serverId || '',
    });
    if (d.ok) {
      count++;
      _downloadedIds.add(String(ep.id));
      const row = document.getElementById('ep-' + ep.id);
      if (row) {
        const btns = row.querySelectorAll('.ep-btn');
        btns.forEach(b => b.remove());
        const doneBtn = document.createElement('button');
        doneBtn.className = 'ep-btn done'; doneBtn.disabled = true; doneBtn.textContent = '✓';
        row.appendChild(doneBtn);
      }
    }
  }
  showToast(`✓ ${count} Episode(n) als heruntergeladen markiert`, 'success');
  loadStats();
}

async function queueEpisodeById(epId, season, seriesTitle, category, serverId, btn) {
  const ep = window._epMap?.[epId];
  if (!ep) { showToast('❌ Episode nicht gefunden', 'error'); return; }
  await queueEpisode(ep, season, seriesTitle, category, serverId, btn);
}
async function queueAllSeasonById(epIdsCsv, season, seriesTitle, category, serverId) {
  const eps = (epIdsCsv || '').split(',').map(id => window._epMap?.[id]).filter(Boolean);
  await queueAllSeason(eps, season, seriesTitle, category, serverId);
}
async function queueEpisode(ep, season, seriesTitle, category, serverId, btn) {
  const result = await queueItem({
    stream_id:           ep.id,
    type:                'episode',
    title:               ep.clean_title || ep.title,
    container_extension: ep.container_extension ?? 'mp4',
    cover:               '',
    dest_subfolder:      'TV Shows',
    category:            seriesTitle,
    category_original:   category || '',
    season:              season,
    _server_id:          serverId || '',
    _series_id:          seriesTitle || '', // für Rate-Limit: pro Serie zählen
  });
  if (!result) return;
  if (btn) { btn.textContent = '✕'; btn.className = 'ep-btn remove'; btn.onclick = () => removeEpFromQueue(ep.id, btn); }
}
async function removeEpFromQueue(id, btn) {
  await apiPost('queue_remove', {stream_id: id});
  if (btn) { btn.textContent = '+ Q'; btn.className = 'ep-btn add'; btn.onclick = null; }
  adjustQueueBadge(-1); loadStats();
}
async function queueAllSeason(eps, season, seriesTitle, category, serverId) {
  let count = 0;
  for (const ep of eps) {
    if (!ep.downloaded && !ep.queued && !_downloadingIds.has(String(ep.id))) {
      const result = await queueItem({
        stream_id: ep.id, type: 'episode',
        title: ep.clean_title || ep.title,
        container_extension: ep.container_extension ?? 'mp4',
        cover: '', dest_subfolder: 'TV Shows',
        category:          seriesTitle,
        category_original: category || '',
        season:            season,
        _server_id:        serverId || '',
        _series_id:        seriesTitle || '',
      });
      if (!result) continue; // Duplikat/Fehler → überspringen, nicht abbrechen
      count++;
      const btn = document.getElementById('epbtn-' + ep.id);
      if (btn) { btn.textContent = '✕'; btn.className = 'ep-btn remove'; }
    }
  }
  if (count > 0) showToast(`${count} Episode(n) zur Queue hinzugefügt`, 'info');
}

// ── Queue Add/Remove ──────────────────────────────────────────
async function addMovieToQueue(m, card) {
  const result = await queueItem({
    stream_id:           m.stream_id,
    type:                'movie',
    title:               m.clean_title,
    container_extension: m.container_extension ?? 'mp4',
    cover:               m.stream_icon ?? '',
    dest_subfolder:      'Movies',
    category:            m.category ?? m._category ?? '',
    _server_id:          m._server_id ?? '',
  }, card || document.getElementById('card-m-' + m.stream_id));
  if (!result) return;
  // Karte per ID suchen falls nicht direkt übergeben (z.B. aus TMDB-Modal)
  if (!card) card = document.getElementById('card-m-' + m.stream_id);
  if (card) {
    card.classList.add('queued');
    const badge = card.querySelector('.card-badge');
    if (badge) { badge.className = 'card-badge badge-queue'; badge.textContent = '⏳ Queue'; }
    else {
      const b = document.createElement('span');
      b.className = 'card-badge badge-queue'; b.textContent = '⏳ Queue';
      card.querySelector('.card-thumb')?.appendChild(b);
    }
    const btn = card.querySelector('.btn-q');
    if (btn) {
      if (canQueueRemove) {
        btn.textContent = t('btn.remove_queue');
        btn.className   = 'btn-q remove';
        btn.disabled    = false;
        btn.removeAttribute('onclick');
        btn.onclick = () => removeFromQueue(m.stream_id, card);
      } else {
        btn.textContent = t('btn.queued');
        btn.className   = 'btn-q done';
        btn.disabled    = true;
        btn.removeAttribute('onclick');
        btn.onclick = null;
      }
    }
  }
  // Update in allMovies und _lastMovies
  const idx = allMovies.findIndex(x => String(x.stream_id) === String(m.stream_id));
  if (idx >= 0) allMovies[idx].queued = true;
  const idx2 = _lastMovies.findIndex(x => String(x.stream_id) === String(m.stream_id));
  if (idx2 >= 0) _lastMovies[idx2].queued = true;
  // Filter neu anwenden wenn aktiv (z.B. "Queued"-Filter oder Format-Filter)
  if (currentFilter !== 'all' || _movieFilters.rating || _movieFilters.genre || _movieFilters.format) renderMovies();
}

async function removeFromQueue(sid, card) {
  await apiPost('queue_remove', {stream_id: sid});
  if (card) {
    card.classList.remove('queued');
    const badge = card.querySelector('.card-badge');
    if (badge) badge.remove();
    const btn = card.querySelector('.btn-q');
    if (btn) {
      // Film-Objekt aus allMovies oder _lastMovies holen
      const m = allMovies.find(x => String(x.stream_id) === String(sid))
             || _lastMovies.find(x => String(x.stream_id) === String(sid));
      btn.textContent = t('btn.add_queue');
      btn.className   = 'btn-q add';
      btn.disabled    = false;
      btn.removeAttribute('onclick');
      btn.onclick = m ? () => addMovieToQueue(m, card) : null;
    }
  }
  const idx = allMovies.findIndex(x => String(x.stream_id) === String(sid));
  if (idx >= 0) allMovies[idx].queued = false;
  const idx2 = _lastMovies.findIndex(x => String(x.stream_id) === String(sid));
  if (idx2 >= 0) _lastMovies[idx2].queued = false;
  adjustQueueBadge(-1);
  loadStats();
  // Filter neu anwenden wenn aktiv
  if (currentFilter !== 'all' || _movieFilters.rating || _movieFilters.genre || _movieFilters.format) renderMovies();
  showToast(t('queue.removed'), 'info');
}

async function queueItem(item, card = null) {
  const r = await fetch(API + '?action=queue_add', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...item, _csrf: CSRF_TOKEN})
  });
  const d = await r.json();
  if (d.already) {
    if (d.reason === 'on_remote') {
      showToast(`☁️ Bereits auf Remote vorhanden: ${d.filename}`, 'info');
    } else if (d.reason === 'downloaded') {
      showToast(t('queue.already_dl'), 'info');
    } else if (d.reason === 'duplicate_title') {
      showToast(`⚠️ ${t('queue.duplicate', {title: d.title})}`, 'info');
    } else if (d.reason === 'duplicate') {
      showDuplicateToast(d.match_title, item, card);
    } else {
      showToast(t('queue.already_queue'), 'info');
    }
    return null;
  }
  if (d.rate_limit) {
    const mins = Math.ceil((d.resets_in ?? 3600) / 60);
    showToast(`⛔ Stundenlimit erreicht (${d.limit}/h). Noch ${mins} Min. warten.`, 'error');
    updateLimitIndicator(0);
    return null;
  }
  if (d.error) { showToast('❌ ' + d.error, 'error'); return null; }
  const remaining = d.remaining ?? null;
  const suffix = remaining !== null ? ` (noch ${remaining}/${d.limit} diese Stunde)` : '';
  showToast(`"${item.title}" zur Queue hinzugefügt${suffix}`, 'success');
  updateLimitIndicator(remaining);
  adjustQueueBadge(+1);
  loadStats();
  return d;
}

// ── Rate Limit Indicator ──────────────────────────────────────
function updateLimitIndicator(remaining) {
  const el = document.getElementById('limit-indicator');
  if (!el) return;
  if (remaining === null) { el.style.display = 'none'; return; }
  el.style.display = '';
  el.textContent   = t('lbl.queue_slots', {n: remaining});
  el.style.color   = remaining === 0 ? 'var(--red)' : remaining === 1 ? 'var(--orange)' : 'var(--muted)';
}

async function loadLimitStatus() {
  const el = document.getElementById('limit-indicator');
  if (!el) return;
  const s = await api('queue_limit_status');
  if (!s.limited) { el.style.display = 'none'; return; }
  updateLimitIndicator(s.remaining);
}

// ── Queue View ────────────────────────────────────────────────
let _queueRefreshController = null;

async function refreshQueue() {
  const list = document.getElementById('queue-list');
  if (!list) return;

  // Laufenden Request abbrechen
  if (_queueRefreshController) _queueRefreshController.abort();
  _queueRefreshController = new AbortController();

  const items = await api('get_queue');

  // Global Set für Queue-IDs aktualisieren
  _queuedIds = new Set(items.filter(i => i.status !== 'done').map(i => String(i.stream_id)));
    _downloadingIds = new Set(items.filter(i => i.status === 'downloading').map(i => String(i.stream_id)));

  if (!items.length) {
    // Nur Leer-Meldung zeigen wenn aktuell keine Queue-Items im DOM sind
    // (verhindert kurzes Aufblitzen wenn vorher Items da waren)
    if (!list.querySelector('.queue-item')) {
      list.innerHTML = `<div class="state-box"><div class="icon">📭</div><p>Queue ist leer</p></div>`;
    }
    return;
  }

  // Leer-Meldung entfernen falls vorhanden
  list.querySelector('.state-box')?.remove();

  // Diff-Update: nur neue/geänderte Items rendern, bestehende erhalten
  const existingIds = new Set([...list.querySelectorAll('.queue-item')].map(el => el.id));
  const newIds      = new Set(items.map(item => `qi-${item.stream_id}`));

  // Entfernte Items löschen
  for (const id of existingIds) {
    if (!newIds.has(id)) document.getElementById(id)?.remove();
  }

  // Items einfügen oder aktualisieren
  items.forEach((item, idx) => {
    const id  = `qi-${item.stream_id}`;
    const html = queueItemHTML(item);
    const existing = document.getElementById(id);

    if (!existing) {
      // Neu: an der richtigen Position einfügen
      const tmp = document.createElement('div');
      tmp.innerHTML = html;
      const newEl = tmp.firstElementChild;
      const sibling = list.querySelectorAll('.queue-item')[idx];
      if (sibling) list.insertBefore(newEl, sibling);
      else list.appendChild(newEl);
    } else {
      // Vorhanden: nur Status und Meta aktualisieren, nicht den ganzen Node ersetzen
      const newCls = `queue-item status-${item.status}`;
      if (existing.className !== newCls) existing.className = newCls;

      const statusEl = existing.querySelector('.qi-status');
      const statusLabel = {pending:t('status.pending'), downloading:t('status.downloading'), done:t('status.done'), error:t('status.error')}[item.status] ?? item.status;
      if (statusEl) {
        statusEl.className = `qi-status ${item.status}`;
        const retryBtn = item.status === 'error' && canQueueRemove
          ? `<button class="btn-icon" style="font-size:.65rem;padding:3px 8px;margin-left:6px" onclick="retryQueueItem('${item.stream_id}')">↻ Retry</button>`
          : '';
        const resetBtn = item.status === 'done' && canQueueRemove
          ? `<button class="btn-icon" style="font-size:.65rem;padding:3px 8px;margin-left:6px" onclick="resetDownload('${item.stream_id}','${item.type ?? 'movie'}',this.closest('.queue-item'))" title="Zurücksetzen">↺ Reset</button>`
          : '';
        statusEl.innerHTML = statusLabel + retryBtn + resetBtn;
      }
    }
  });
}

function queueItemHTML(item) {
  const statusLabel = {pending:t('status.pending'), downloading:t('status.downloading'), done:t('status.done'), error:t('status.error')}[item.status] ?? item.status;
  const thumb = item.cover ? `<img class="qi-thumb" src="${item.cover}" alt="">` : `<div class="qi-thumb" style="display:flex;align-items:center;justify-content:center;font-size:1.2rem">🎬</div>`;
  const isOwn = item.added_by === currentUsername;
  const canDel = item.status !== 'downloading' && (
    canQueueRemove ||
    (canQueueRemoveOwn && isOwn && item.status === 'pending')
  );
  const delBtn = canDel
    ? `<button class="qi-del" onclick="removeQueueItem('${item.stream_id}',this.closest('.queue-item'))">✕</button>`
    : '';
  const addedBy = canSeeAddedBy && item.added_by ? `· ${item.added_by}` : '';

  // Priorität
  const prioLabels = {1:t('queue.prio_high'), 2:t('queue.prio_normal'), 3:t('queue.prio_low')};
  const prio = item.priority ?? 2;
  const prioBtn = canQueueRemove && item.status === 'pending'
    ? `<select class="qi-prio" onchange="setPriority('${item.stream_id}',this.value)" title="Priorität">
        <option value="1"${prio===1?' selected':''}><?= t('queue.prio_high') ?></option>
        <option value="2"${prio===2?' selected':''}><?= t('queue.prio_normal') ?></option>
        <option value="3"${prio===3?' selected':''}><?= t('queue.prio_low') ?></option>
      </select>`
    : `<span class="qi-prio-badge prio-${prio}">${prioLabels[prio] ?? ''}</span>`;

  // Retry-Button für Fehler (Admin)
  const retryBtn = item.status === 'error' && canQueueRemove
    ? `<button class="btn-icon" style="font-size:.65rem;padding:3px 8px;margin-left:6px" onclick="retryQueueItem('${item.stream_id}')">↻ Retry</button>`
    : '';

  // Reset-Button für Done-Items (Admin)
  const resetBtn = item.status === 'done' && canQueueRemove
    ? `<button class="btn-icon" style="font-size:.65rem;padding:3px 8px;margin-left:6px" onclick="resetDownload('${item.stream_id}','${item.type ?? 'movie'}',this.closest('.queue-item'))" title="Zurücksetzen damit neu heruntergeladen werden kann">↺ Reset</button>`
    : '';

  return `
  <div class="queue-item status-${item.status}" id="qi-${item.stream_id}">
    ${thumb}
    <div class="qi-info">
      <div class="qi-title">${item.title}</div>
      <div class="qi-meta">${item.type} · ${item.container_extension?.toUpperCase()} · ${item.added_at ?? ''} ${addedBy}</div>
      ${item.error ? `<div style="font-size:.7rem;color:var(--red);margin-top:3px">${item.error}</div>` : ''}
      <div class="qi-progress" id="qip-${item.stream_id}" style="display:none">
        <div class="qi-progress-bar-wrap"><div class="qi-progress-bar" style="width:0%"></div></div>
        <div class="qi-progress-stats">
          <span class="qip-pct">0%</span><span class="qip-done">–</span><span class="qip-speed">–</span><span class="qip-eta">–</span>
        </div>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0">
      <span class="qi-status ${item.status}">${statusLabel}${retryBtn}${resetBtn}</span>
      ${prioBtn}
    </div>
    ${delBtn}
  </div>`;
}

// ── Favourites ────────────────────────────────────────────────
let favourites = new Set();    // 'movie:123' | 'series:456'
let favouriteData = [];        // full objects for rendering

async function loadFavourites() {
  const d = await api('get_favourites');
  favouriteData = d.favourites ?? [];
  favourites = new Set(favouriteData.map(f => f.type + ':' + f.stream_id));
  updateFavBadge();
}

function updateFavBadge() {
  const badge = document.getElementById('fav-badge');
  if (!badge) return;
  const n = favourites.size;
  badge.textContent = n;
  badge.style.display = n > 0 ? '' : 'none';
}

// Map zum schnellen Nachschlagen von Fav-Daten ohne inline-onclick-Argumente
const _favItemMap = new Map();

function registerFavItem(type, id, item) {
  _favItemMap.set(type + ':' + id, item);
}

async function toggleFavById(type, id, btn) {
  const item = _favItemMap.get(type + ':' + id);
  if (!item) return;
  if (type === 'movie') {
    await toggleFav('movie', id, item.clean_title || item.title, item.stream_icon || item.cover || '', item.category || '', item.container_extension || 'mp4', btn, item._server_id || '');
  } else {
    await toggleFav('series', id, item.clean_title || item.name, item.cover || '', item.category || '', '', btn, item._server_id || '');
  }
}

async function toggleFav(type, sid, title, cover, category, ext, btn, serverId = '') {
  const key = type + ':' + sid;
  const d = await apiPost('favourite_toggle', {stream_id: sid, type, title, cover, category, ext, server_id: serverId});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  if (d.action === 'added') {
    favourites.add(key);
    favouriteData.push({stream_id: sid, type, title, cover, category, ext, server_id: serverId, added_at: new Date().toISOString().slice(0,10)});
    btn.classList.add('active');
    showToast('Zu Favoriten hinzugefügt', 'success');
  } else {
    favourites.delete(key);
    favouriteData = favouriteData.filter(f => !(f.type === type && f.stream_id === sid));
    btn.classList.remove('active');
    showToast(t('fav.removed'), 'info');
  }
  updateFavBadge();
  if (currentView === 'favourites') updateFavouriteButtons();
}

let favTab = 'all';
function switchFavTab(tab, el) {
  favTab = tab;
  document.querySelectorAll('#view-favourites .filter-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  renderFavourites();
}

/** Aktualisiert nur die Action-Buttons in bestehenden Favoriten-Karten — ohne Cover neu zu laden */
function updateFavouriteButtons() {
  const grid = document.getElementById('fav-grid');
  if (!grid) return;
  favouriteData.forEach(f => {
    if (f.type !== 'movie') return; // Serien haben fixe Episode-Buttons
    const sid           = String(f.stream_id);
    const isDownloaded  = _downloadedIds.has(sid);
    const isDownloading = _downloadingIds.has(sid);
    const isQueued      = !isDownloaded && _queuedIds.has(sid);
    const serverAvail   = !f.server_id || ACTIVE_SERVER_IDS.has(f.server_id);
    const ext = f.ext || 'mp4';

    const card = grid.querySelector(`.card[data-sid="${sid}"]`);
    if (!card) return;
    const actions = card.querySelector('.card-actions');
    if (!actions) return;

    let newBtn = '';
    if (isDownloaded) {
      newBtn = canQueueRemove
        ? `<button class="btn-q done" onclick="resetDownload('${sid}','movie',this.closest('.card'))" title="Zurücksetzen">↺ Reset</button>`
        : `<button class="btn-q done" disabled>✓ Done</button>`;
    } else if (isDownloading) {
      newBtn = `<button class="btn-q done" disabled>⬇ ${t('status.downloading')}</button>`;
    } else if (isQueued) {
      newBtn = (canQueueRemove || canQueueRemoveOwn)
        ? `<button class="btn-q remove" onclick="removeFromQueue('${sid}',this.closest('.card'))">✕ Remove</button>`
        : `<button class="btn-q done" disabled>⏳ Queued</button>`;
    } else if (!serverAvail) {
      newBtn = `<button class="btn-q done" disabled title="${t('fav.server_unavailable')}">⚠ ${t('fav.unavailable')}</button>`;
    } else if (canQueueAdd) {
      const movieObj = JSON.stringify({
        stream_id: f.stream_id, type: 'movie', title: f.title,
        container_extension: ext, cover: f.cover, category: f.category,
        clean_title: f.title, _server_id: f.server_id ?? '',
      }).replace(/"/g, '&quot;');
      newBtn = `<button class="btn-q add" onclick="addMovieToQueue(${movieObj},this.closest('.card'))">+ Queue</button>`;
    }
    actions.innerHTML = newBtn;
  });
}

function renderFavourites() {
  const query = (document.getElementById('fav-search')?.value ?? '').toLowerCase();
  let items = favouriteData;
  if (favTab !== 'all') items = items.filter(f => f.type === favTab);
  if (query) items = items.filter(f => f.title.toLowerCase().includes(query));

  const count = document.getElementById('fav-count');
  if (count) count.textContent = items.length;

  const grid = document.getElementById('fav-grid');
  if (!grid) return;
  if (!items.length) { grid.innerHTML = emptyHTML('Keine Favoriten gefunden'); return; }

  grid.innerHTML = items.map(f => {
    const icon = f.type === 'series' ? '📺' : '🎬';
    const thumb = f.cover
      ? `<img src="${esc(f.cover)}" alt="" class="loaded" style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0">`
      : '';
    const ext = f.ext || 'mp4';

    // Queue-Button: Filme direkt queuen, Serien → Modal öffnen
    let actionBtn = '';
    const sid           = String(f.stream_id);
    const isDownloaded  = _downloadedIds.has(sid);
    const isDownloading = _downloadingIds.has(sid);
    const isQueued      = !isDownloaded && _queuedIds.has(sid);
    const serverAvail   = !f.server_id || ACTIVE_SERVER_IDS.has(f.server_id);

    if (f.type === 'movie') {
      if (isDownloaded) {
        actionBtn = canQueueRemove
          ? `<button class="btn-q done" onclick="resetDownload('${sid}','movie',this.closest('.card'))" title="Zurücksetzen">↺ Reset</button>`
          : `<button class="btn-q done" disabled>✓ Done</button>`;
      } else if (isDownloading) {
        actionBtn = `<button class="btn-q done" disabled>⬇ ${t('status.downloading')}</button>`;
      } else if (isQueued) {
        actionBtn = canQueueRemove || canQueueRemoveOwn
          ? `<button class="btn-q remove" onclick="removeFromQueue('${sid}',this.closest('.card'))">✕ Remove</button>`
          : `<button class="btn-q done" disabled>⏳ Queued</button>`;
      } else if (!serverAvail) {
        actionBtn = `<button class="btn-q done" disabled title="${t('fav.server_unavailable')}">⚠ ${t('fav.unavailable')}</button>`;
      } else if (canQueueAdd) {
        const movieObj = JSON.stringify({
          stream_id: f.stream_id, type: 'movie', title: f.title,
          container_extension: ext, cover: f.cover, category: f.category,
          clean_title: f.title, _server_id: f.server_id ?? '',
        }).replace(/"/g, '&quot;');
        actionBtn = `<button class="btn-q add" onclick="addMovieToQueue(${movieObj},this.closest('.card'))">+ Queue</button>`;
      }
    } else if (f.type === 'series') {
      if (!serverAvail) {
        actionBtn = `<button class="btn-q done" disabled title="${t('fav.server_unavailable')}">⚠ ${t('fav.unavailable')}</button>`;
      } else {
        actionBtn = `<button class="btn-q add" onclick="openSeriesModal('${f.stream_id}','${esc(f.title)}','${esc(f.cover||'')}','${esc(f.category||'')}','${esc(f.server_id||'')}')">📋 Episodes</button>`;
      }
    }

    return `
    <div class="card" data-sid="${f.stream_id}">
      <div class="card-thumb">
        <div class="card-thumb-placeholder">${icon}</div>
        ${thumb}
        <button class="btn-fav active" onclick="event.stopPropagation();toggleFav('${f.type}','${f.stream_id}','${esc(f.title)}','${esc(f.cover||'')}','${esc(f.category||'')}','${esc(ext)}',this)">♥</button>
      </div>
      <div class="card-body">
        <div class="card-title">${esc(f.title)}</div>
        <div class="card-meta">${f.type === 'series' ? 'Serie' : 'Film'} · ${esc(f.category||'')}</div>
      </div>
      ${actionBtn ? `<div class="card-actions">${actionBtn}</div>` : ''}
    </div>`;
  }).join('');
  lazyLoadImages();
}

// ── Neue Releases ─────────────────────────────────────────────
let _nrData  = null;
let _nrTab   = 'all';

function switchNrTab(tab, el) {
  _nrTab = tab;
  document.querySelectorAll('#nr-tab-all,#nr-tab-movies,#nr-tab-series').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  renderNewReleases();
}

async function loadNewReleases() {
  const grid = document.getElementById('new-releases-grid');
  if (grid) grid.innerHTML = `<div class="state-box" style="grid-column:1/-1"><div class="spinner"></div><p>${t('status.loading')}</p></div>`;
  _nrData = await api('get_new_releases');
  const meta = document.getElementById('new-releases-meta');
  if (_nrData.generated_at) {
    const total = _nrData.movies?.length ?? 0;
    if (meta) meta.textContent = t('new.meta', {n: total, date: _nrData.generated_at});
    const badge = document.getElementById('new-releases-badge');
    if (badge) { badge.textContent = total; badge.style.display = total > 0 ? '' : 'none'; }
  } else {
    if (meta) meta.textContent = t('new.no_cache');
  }
  renderNewReleases();
}

function renderNewReleases() {
  const grid = document.getElementById('new-releases-grid');
  if (!grid || !_nrData) return;
  const items = _nrData.movies ?? [];
  if (!items.length) { grid.innerHTML = emptyHTML(t('new.empty')); return; }

  grid.innerHTML = items.map(item => {
    const itemId     = String(item.stream_id ?? item.id);
    const dismissBtn = <?= $can_settings ? 'true' : 'false' ?> ? `<button class="btn-icon" title="<?= t('btn.delete') ?>"
      onclick="dismissNewRelease('${itemId}','movie',this)"
      style="position:absolute;top:4px;left:4px;z-index:10;background:rgba(0,0,0,.7);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:.65rem;padding:0;border:none;cursor:pointer;color:#fff">✕</button>` : '';
    const sid  = String(item.stream_id ?? item.id);
    const card = movieCard({
      stream_id:           sid,
      clean_title:         item.clean_title ?? item.title ?? '',
      stream_icon:         item.cover ?? '',
      container_extension: item.ext ?? 'mp4',
      category:            item.category ?? '',
      downloaded:          _downloadedIds.has(sid),
      queued:              _queuedIds.has(sid),
      year:                item.year ?? '',
      _server_id:          item._server_id ?? '',
    });
    return card.replace('<div class="card-thumb">', `<div class="card-thumb">${dismissBtn}`);
  }).join('');
  lazyLoadImages();
}

async function dismissNewRelease(id, type, btn) {
  const card = btn?.closest('.card');
  if (card) card.style.opacity = '.4';
  const d = await apiPost('dismiss_new_release', {id, type});
  if (d.error) { showToast('❌ ' + d.error, 'error'); if (card) card.style.opacity = ''; return; }
  if (_nrData) {
    if (type === 'series') {
      _nrData.series = (_nrData.series ?? []).filter(s => String(s.series_id ?? s.id) !== id);
    } else {
      _nrData.movies = (_nrData.movies ?? []).filter(m => String(m.stream_id ?? m.id) !== id);
    }
    const total = (_nrData.movies?.length ?? 0) + (_nrData.series?.length ?? 0);
    const badge = document.getElementById('new-releases-badge');
    if (badge) { badge.textContent = total; badge.style.display = total > 0 ? '' : 'none'; }
    const meta = document.getElementById('new-releases-meta');
    if (meta && _nrData.generated_at) meta.textContent = t('new.meta', {n: total, date: _nrData.generated_at});
  }
  if (card) card.remove();
}

async function dismissAllNewReleases(btn) {
  if (!await showConfirm(t('new.all_seen_confirm'), {title:'Alle als gesehen?', icon:'👁', okLabel:'Bestätigen'})) return;
  btn.disabled = true;
  const d = await apiPost('dismiss_all_new_releases', {});
  btn.disabled = false;
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  if (_nrData) { _nrData.movies = []; _nrData.series = []; }
  const badge = document.getElementById('new-releases-badge');
  if (badge) { badge.textContent = '0'; badge.style.display = 'none'; }
  const meta = document.getElementById('new-releases-meta');
  if (meta) meta.textContent = t('new.empty');
  renderNewReleases();
  showToast(t('new.all_seen_done'), 'success');
}

async function removeQueueItem(sid, el) {
  const r = await fetch(`${API}?action=queue_remove`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...({stream_id: sid}), _csrf: CSRF_TOKEN})
  });
  const d = await r.json();
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  if (el) el.remove();
  adjustQueueBadge(-1); loadStats();
  showToast('Entfernt', 'info');
}

async function setPriority(sid, priority) {
  const r = await fetch(`${API}?action=set_priority`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...({stream_id: sid, priority: parseInt(priority)}), _csrf: CSRF_TOKEN})
  });
  const d = await r.json();
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast(t('queue.priority_changed'), 'info');
  refreshQueue();
}

async function resetEpisode(sid, epId, season, seriesTitle, category, serverId) {
  if (!await showConfirm('Die Episode wird aus der Heruntergeladen-Liste entfernt und kann neu zur Queue hinzugefügt werden.', {title:'Episode zurücksetzen?', icon:'↺', okLabel:'Zurücksetzen', danger:true})) return;
  const d = await apiPost('reset_download', {stream_id: sid, type: 'episode'});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast(t('reset.done'), 'success');
  const ep = window._epMap?.[epId] ?? {id: epId};
  const epRow = document.getElementById('ep-' + sid);
  if (epRow) {
    const newBtn = document.createElement('button');
    newBtn.className = 'ep-btn add';
    newBtn.id = 'epbtn-' + sid;
    newBtn.textContent = '+ Q';
    newBtn.onclick = () => queueEpisode(ep, season, seriesTitle, category, serverId || '', newBtn);
    epRow.querySelector('button')?.replaceWith(newBtn);
  }
  updateQueueBadge();
}

async function resetDownload(sid, type, rowEl) {
  if (!await showConfirm('Das Item wird aus der Heruntergeladen-Liste entfernt und kann neu zur Queue hinzugefügt werden.', {title:'Download zurücksetzen?', icon:'↺', okLabel:'Zurücksetzen', danger:true})) return;
  const d = await apiPost('reset_download', {stream_id: sid, type});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast('↺ Zurückgesetzt — kann neu gequeued werden', 'success');
  rowEl?.remove();

  // _lastMovies aktualisieren damit re-render korrekt ist
  const idx = _lastMovies.findIndex(x => String(x.stream_id) === String(sid));
  if (idx >= 0) {
    _lastMovies[idx].downloaded = false;
    _lastMovies[idx].queued     = false;
    // Karte neu rendern
    const card = document.getElementById('card-m-' + sid);
    if (card) card.outerHTML = movieCard(_lastMovies[idx]);
  }

  refreshQueue(); updateQueueBadge(); loadStats();
}

async function markDownloaded(sid, type, title, cover, category, ext, serverId, cardEl) {
  if (!await showConfirm(`"${title}" wird als heruntergeladen markiert und erscheint dann als ✓ Done.`, {title:'Als heruntergeladen markieren?', icon:'✓', okLabel:'Markieren'})) return;
  const d = await apiPost('mark_downloaded', {
    stream_id: sid, type, title, cover, category: category || '',
    ext: ext || 'mp4', server_id: serverId || '',
  });
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast(`✓ "${title}" als heruntergeladen markiert`, 'success');
  _downloadedIds.add(String(sid));
  _queuedIds.delete(String(sid));

  // Karte aktualisieren
  const card = cardEl || document.getElementById('card-m-' + sid);
  if (card) {
    card.classList.add('downloaded');
    card.classList.remove('queued');
    const badge = card.querySelector('.card-badge');
    if (badge) { badge.className = 'card-badge badge-done'; badge.textContent = '✓ Done'; }
    const btn = card.querySelector('.btn-q');
    if (btn && canQueueRemove) {
      btn.textContent = '↺ Reset';
      btn.className   = 'btn-q done';
      btn.disabled    = false;
      btn.onclick     = () => resetDownload(sid, type, null);
    }
  }
  // _lastMovies aktualisieren
  const idx = _lastMovies.findIndex(x => String(x.stream_id) === String(sid));
  if (idx >= 0) _lastMovies[idx].downloaded = true;
  const idx2 = allMovies.findIndex(x => String(x.stream_id) === String(sid));
  if (idx2 >= 0) allMovies[idx2].downloaded = true;

  loadStats(); updateQueueBadge();
  // TMDB-Modal schließen falls offen
  const tmdbModal = document.getElementById('tmdb-modal');
  if (tmdbModal?.style.display !== 'none') closeTmdbModal();
}

async function retryQueueItem(sid) {
  const r = await fetch(`${API}?action=queue_retry`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...({stream_id: sid}), _csrf: CSRF_TOKEN})
  });
  const d = await r.json();
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast('Wird erneut versucht', 'success');
  refreshQueue();
}
async function clearDone() {
  await api('queue_clear_done');
  refreshQueue(); refreshDashboardQueue(); updateQueueBadge(); loadStats(); loadDashboardData();
  showToast('Erledigte Einträge entfernt', 'success');
}
async function clearAll() {
  if (!await showConfirm('Alle Einträge werden unwiderruflich aus der Queue entfernt.', {title:'Queue löschen?', icon:'🗑', okLabel:'Löschen', danger:true})) return;
  await api('queue_clear_all');
  refreshQueue(); refreshDashboardQueue(); updateQueueBadge(); loadStats(); loadDashboardData();
  showToast(t('queue.cleared'), 'success');
}

// ── Log ───────────────────────────────────────────────────────
let _logInterval = null;

async function loadLog(autoRefresh = false) {
  const wrap = document.getElementById('log-wrap');
  if (!wrap) return;
  // Bei manuellem Refresh: Lade-Indikator zeigen
  if (!autoRefresh) wrap.textContent = 'Lade…';
  const d = await api('cron_log');
  if (!d.lines?.length) { wrap.textContent = 'Kein Log vorhanden.'; return; }
  // Scroll-Position merken — nur nach unten scrollen wenn bereits am Ende
  const atBottom = wrap.scrollTop + wrap.clientHeight >= wrap.scrollHeight - 20;
  wrap.innerHTML = d.lines.map(line => {
    const l = line.replace(/&/g,'&amp;').replace(/</g,'&lt;');
    if (l.includes('DONE:'))       return `<span class="log-ok">${l}</span>`;
    if (l.includes('ERROR:'))      return `<span class="log-err">${l}</span>`;
    if (l.includes('SKIP'))        return `<span class="log-skip">${l}</span>`;
    if (l.includes('START:'))      return `<span class="log-start">${l}</span>`;
    if (l.includes('Progress:'))   return `<span class="log-prog">${l}</span>`;
    if (l.includes('==='))         return `<span class="log-head">${l}</span>`;
    return l;
  }).join('\n');
  if (atBottom || !autoRefresh) wrap.scrollTop = wrap.scrollHeight;
}

async function clearCronLog() {
  if (!await showConfirm('Das Cron-Log wird vollständig geleert.', {title:'Log leeren?', icon:'🧹', okLabel:'Leeren', danger:true})) return;
  const d = await apiPost('clear_cron_log', {});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  document.getElementById('log-wrap').textContent = 'Log geleert.';
  showToast('✓ Log geleert', 'success');
}

function startLogPolling() {
  loadLog();
  if (!_logInterval) _logInterval = setInterval(() => loadLog(true), 5000);
}

function stopLogPolling() {
  if (_logInterval) { clearInterval(_logInterval); _logInterval = null; }
}

// ── Search (Movies + Series tabs) ────────────────────────────
let searchTab = 'movies';
let searchInitialized = false;

function switchSearchTab(tab, btn) {
  searchTab = tab;
  document.querySelectorAll('#search-tab-movies, #search-tab-series').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('search-movies-grid').style.display = tab === 'movies' ? '' : 'none';
  document.getElementById('search-series-grid').style.display = tab === 'series' ? '' : 'none';
  const input = document.getElementById('search-input');
  if (input) input.placeholder = tab === 'series' ? t('search.placeholder_s') : t('search.placeholder');
  const q = input?.value.trim();
  if (q) doSearch(q);
}

function initSearch() {
  if (searchInitialized) return;
  searchInitialized = true;
  const input = document.getElementById('search-input');
  input.addEventListener('input', () => {
    clearTimeout(searchDebounce);
    const q = input.value.trim();
    if (!q) { doSearch(''); return; }
    searchDebounce = setTimeout(() => doSearch(q), 350);
  });
}

// Session-Cache für Suchergebnisse (lebt bis Seitenreload)
const _searchCache = new Map();

async function doSearch(q) {
  const movGrid = document.getElementById('search-movies-grid');
  const serGrid = document.getElementById('search-series-grid');
  if (!q) {
    if (movGrid) movGrid.innerHTML = emptyHTML('Suchbegriff eingeben');
    if (serGrid) serGrid.innerHTML = '';
    clearSelection();
    renderSearchHistory();
    return;
  }
  addSearchHistory(q);
  document.getElementById('search-history-box').innerHTML = '';

  if (searchTab === 'movies') {
    if (movGrid) movGrid.innerHTML = loadingHTML();
    const hintEl = document.getElementById('search-cache-hint');
    if (hintEl) { hintEl.style.display = 'none'; hintEl.innerHTML = ''; }
    const cacheKey = 'multi:' + q;
    let results, source;
    if (_searchCache.has(cacheKey)) {
      ({results, source} = _searchCache.get(cacheKey));
    } else {
      const d = await api('search_all_servers', {q, type: 'movies'});
      results = d.results ?? d;
      source  = d.source ?? 'multi';
      _searchCache.set(cacheKey, {results, source});
    }
    _lastMovies = results;
    if (movGrid) {
      const hint = document.getElementById('search-cache-hint');
      if (hint) { hint.style.display = 'none'; hint.innerHTML = ''; }
      if (!results.length) {
        movGrid.innerHTML = emptyHTML('Keine Treffer');
      } else {
        movGrid.innerHTML = results.map(m => movieCard(m, true)).join('');
        lazyLoadImages();
      }
    }
  } else {
    if (serGrid) serGrid.innerHTML = loadingHTML();
    const cacheKey = 'multi-s:' + q;
    let results, source;
    if (_searchCache.has(cacheKey)) {
      ({results, source} = _searchCache.get(cacheKey));
    } else {
      const d = await api('search_all_servers', {q, type: 'series'});
      results = d.results ?? d;
      source  = d.source ?? 'multi';
      _searchCache.set(cacheKey, {results, source});
    }
    if (serGrid) {
      if (!results.length) {
        serGrid.innerHTML = emptyHTML('Keine Treffer');
      } else {
        serGrid.innerHTML = results.map(s => seriesCard(s, true)).join('');
        lazyLoadImages();
      }
    }
  }
  clearSelection();
}

function clearSearchCache() {
  _searchCache.clear();
  showToast('Suchcache geleert', 'info');
}

// ── View management ───────────────────────────────────────────
function showView(v) {
  // URL-Hash aktualisieren
  if (history.replaceState) history.replaceState(null, '', '#' + v);
  // Auf mobilen Geräten Sidebar schließen wenn eine View gewählt wird
  if (window.innerWidth <= 768) closeSidebar();
  ['dashboard','movies','series','search','queue','log','settings','users','activity-log','profile','favourites','new-releases','api-docs','stats'].forEach(name => {
    const el = document.getElementById('view-' + name);
    if (el) el.style.display = name === v ? '' : 'none';
  });
  document.querySelectorAll('.nav-item[data-view]').forEach(el =>
    el.classList.toggle('active', el.dataset.view === v)
  );
  currentView = v;
  // Sofort aktualisieren beim View-Wechsel
  pollProgress();
  <?php if ($can_queue_view): ?>if (v === 'queue') refreshQueue();<?php endif; ?>
  <?php if ($can_settings && VPN_ENABLED): ?>pollVpnStatus(false);<?php endif; ?>
  // Topbar-Download-Indicator: ausblenden in Dashboard/Queue, sonst vom pollProgress gesteuert
  const topbarDl = document.getElementById('topbar-dl');
  if (topbarDl && (v === 'dashboard' || v === 'queue')) topbarDl.style.display = 'none';
  const sb = document.getElementById('search-bar');
  const fb = document.getElementById('filter-bar');
  if (sb) sb.style.display = 'none'; // Topbar-Suchfeld nicht mehr verwendet
  fb.style.display = v === 'movies'  ? '' : 'none';
  if (v === 'search')       { document.getElementById('page-title').textContent = t('nav.search'); initSearch(); document.getElementById('search-input').focus(); renderSearchHistory(); }
  if (v === 'dashboard')    { document.getElementById('page-title').textContent = t('nav.dashboard'); <?php if (!$can_settings): ?>loadUserDashboard();<?php endif; ?> <?php if ($can_settings): ?>startDashboardPolling();<?php endif; ?> }
  if (v === 'queue')        { document.getElementById('page-title').textContent = t('nav.queue'); refreshQueue(); <?php if ($can_settings): ?>startProgressPolling();<?php endif; ?> }
  if (v === 'log')          { document.getElementById('page-title').textContent = t('nav.log'); startLogPolling(); }
  if (v === 'settings')     { document.getElementById('page-title').textContent = t('nav.settings'); <?php if ($can_settings): ?>loadConfig(); loadCacheStatus(); loadApiKeys(); loadMaintenance(); loadBackups(); loadServers(); checkVpnStatus();<?php endif; ?> }
  if (v === 'users')        { document.getElementById('page-title').textContent = t('nav.users'); loadUsers(); <?php if ($can_users): ?>loadInvites();<?php endif; ?> }
  if (v === 'activity-log') { document.getElementById('page-title').textContent = t('nav.activity_log'); loadActivityLog(); }
  if (v === 'profile')      { document.getElementById('page-title').textContent = t('profile.title'); document.getElementById('profile-msg').className = 'settings-msg'; const tp = document.getElementById('theme-picker'); if (tp) tp.innerHTML = renderThemePicker(); }
  if (v === 'favourites')    { document.getElementById('page-title').textContent = t('nav.favourites'); renderFavourites(); loadStats(); updateQueueBadge(); }
  if (v === 'new-releases')  { document.getElementById('page-title').textContent = t('new.title'); loadNewReleases(); }
  if (v === 'api-docs')     { document.getElementById('page-title').textContent = t('nav.api_docs'); }
  if (v === 'stats')        { document.getElementById('page-title').textContent = t('nav.stats'); loadStatsView(); }
  clearInterval(queueRefreshInterval);
  if (v === 'queue') {
    // Progress- und Queue-Polling starten (unified — kein separates Queue-Interval nötig)
    // queueRefreshInterval bleibt leer, refreshQueue läuft über startProgressPolling
  }
  if (v !== 'log') stopLogPolling();
  <?php if ($can_settings): ?>if (v !== 'dashboard') stopDashboardPolling();<?php endif; ?>
  // Clear multi-select when leaving search
  if (v !== 'search') clearSelection();
}

// ── Settings ──────────────────────────────────────────────────
<?php if ($can_settings): ?>

async function loadBackups() {
  const d = await api('backup_list');
  const el = document.getElementById('backup-list');
  if (!el) return;
  if (!d.backups?.length) {
    el.innerHTML = `<div style="color:var(--muted)">${t('cfg.backup_none')}</div>`;
    return;
  }
  el.innerHTML = `<table style="width:100%;border-collapse:collapse">
    <tr style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase">
      <th style="text-align:left;padding:6px 0;border-bottom:1px solid var(--border)">Datei</th>
      <th style="text-align:right;padding:6px 0;border-bottom:1px solid var(--border)">Größe</th>
      <th style="text-align:right;padding:6px 0;border-bottom:1px solid var(--border)">Erstellt</th>
      <th style="padding:6px 0;border-bottom:1px solid var(--border)"></th>
    </tr>
    ${d.backups.map(b => `
    <tr style="border-bottom:1px solid rgba(255,255,255,.04)">
      <td style="padding:8px 0;font-family:'DM Mono',monospace;font-size:.72rem">${esc(b.name)}</td>
      <td style="padding:8px 0;text-align:right;color:var(--muted);font-size:.78rem">${fmtBytes(b.size)}</td>
      <td style="padding:8px 0;text-align:right;color:var(--muted);font-size:.78rem">${esc(b.created_at)}</td>
      <td style="padding:8px 4px;text-align:right;display:flex;gap:4px;justify-content:flex-end">
        <button class="btn-sm" onclick="restoreBackup('${esc(b.name)}')">↩ Restore</button>
        <button class="btn-sm danger" onclick="deleteBackup('${esc(b.name)}',this.closest('tr'))">✕</button>
      </td>
    </tr>`).join('')}
  </table>`;
}

async function runBackup(btn) {
  if (btn) { btn.disabled = true; btn.textContent = '▶ Läuft…'; }
  const msg = document.getElementById('backup-run-msg');
  if (msg) msg.textContent = 'Backup wird erstellt…';
  await apiPost('backup_run', {});
  showToast('Backup gestartet — läuft im Hintergrund', 'success');
  setTimeout(async () => {
    await loadBackups();
    if (btn) { btn.disabled = false; btn.textContent = '▶ Backup jetzt erstellen'; }
    if (msg) msg.textContent = '';
  }, 3000);
}

async function restoreBackup(name) {
  if (!await showConfirm(`Backup "${name}" wird eingespielt. Die aktuellen Daten (Users, Queue, Config etc.) werden überschrieben. Diese Aktion kann nicht rückgängig gemacht werden.`, {title:'Backup wiederherstellen?', icon:'↩', okLabel:'Wiederherstellen', danger:true})) return;
  showToast('Wiederherstellung läuft…', 'info');
  const d = await apiPost('backup_restore', {name});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  if (!d.ok) {
    showToast(`⚠️ Teilweise wiederhergestellt (${d.restored} Dateien). Fehler: ${d.errors?.join(', ')}`, 'error');
    return;
  }
  showToast(`✅ ${d.restored} Dateien wiederhergestellt — Seite wird neu geladen…`, 'success');
  setTimeout(() => location.reload(), 2000);
}

async function deleteBackup(name, row) {
  if (!await showConfirm(`Backup "${name}" wird endgültig gelöscht.`, {title:'Backup löschen?', icon:'🗑', okLabel:'Löschen', danger:true})) return;
  const d = await apiPost('backup_delete', {name});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  row?.remove();
  showToast(t('backup.deleted'), 'info');
}

async function loadMaintenance() {
  const d = await api('maintenance_status');
  applyMaintenanceStatus(d.active ?? false);
}

function applyMaintenanceStatus(active) {
  const status = document.getElementById('maintenance-status');
  const btn    = document.getElementById('btn-maintenance-toggle');
  if (!status || !btn) return;
  if (active) {
    status.textContent = t('maintenance.active');
    status.style.color = 'var(--red)';
    status.style.borderColor = 'rgba(255,71,87,.3)';
    btn.textContent = t('maintenance.btn_disable');
    btn.className = 'btn-secondary danger';
  } else {
    status.textContent = '🟢 Normal — Seite erreichbar';
    status.style.color = 'var(--green)';
    status.style.borderColor = 'rgba(46,213,115,.2)';
    btn.textContent = t('maintenance.btn_enable');
    btn.className = 'btn-secondary';
  }
}

async function toggleMaintenance() {
  const current = document.getElementById('maintenance-status')?.textContent?.includes('AKTIV');
  if (!current && !await showConfirm(t('maintenance.enable_confirm'), {title:'Wartungsmodus?', icon:'🔒', okLabel:'Aktivieren', danger:true})) return;
  const action = current ? 'maintenance_disable' : 'maintenance_enable';
  const r = await apiPost(action, {});
  if (r.error) { showToast('❌ ' + r.error, 'error'); return; }
  applyMaintenanceStatus(!current);
  showToast(current ? t('maintenance.disabled_toast') : t('maintenance.enabled_toast'), current ? 'success' : 'info');
}

// ── Server-Verwaltung ─────────────────────────────────────────
function openAddServerForm() {
  document.getElementById('add-server-form').style.display = '';
  document.getElementById('btn-add-server').style.display = 'none';
  document.getElementById('add-srv-name').focus();
}
function closeAddServerForm() {
  document.getElementById('add-server-form').style.display = 'none';
  document.getElementById('btn-add-server').style.display = '';
  ['add-srv-name','add-srv-ip','add-srv-port','add-srv-username','add-srv-password'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = id === 'add-srv-port' ? '80' : '';
  });
  const msg = document.getElementById('add-srv-msg');
  if (msg) { msg.textContent = ''; msg.className = 'settings-msg'; }
}
async function addServer() {
  const msg      = document.getElementById('add-srv-msg');
  const name     = document.getElementById('add-srv-name').value.trim();
  const ip       = document.getElementById('add-srv-ip').value.trim();
  const port     = document.getElementById('add-srv-port').value.trim() || '80';
  const username = document.getElementById('add-srv-username').value.trim();
  const password = document.getElementById('add-srv-password').value;
  if (!name || !ip || !username || !password) {
    msg.textContent = '❌ Alle Felder außer Port sind Pflichtfelder';
    msg.className = 'settings-msg err'; return;
  }
  // Server-ID wird serverseitig aus IP+Port+Username berechnet
  const d = await apiPost('save_server', {name, server_ip: ip, port, username, password});
  if (d.error) { msg.textContent = '❌ ' + d.error; msg.className = 'settings-msg err'; return; }
  closeAddServerForm();
  loadServers();
  showToast('✓ Server hinzugefügt', 'success');
}
async function testServer(serverId, name, btn) {
  const statusEl = document.getElementById('srv-test-' + serverId);
  const card = document.getElementById('srv-card-' + serverId);
  if (btn) { btn.disabled = true; btn.textContent = '⏳'; }
  if (statusEl) statusEl.innerHTML = `<span style="color:var(--muted)">⏳ Teste…</span>`;
  const d = await apiPost('test_server', {server_id: serverId});
  if (btn) { btn.disabled = false; btn.textContent = '🔌'; }
  if (d.ok) {
    if (statusEl) statusEl.innerHTML = `<span style="color:var(--green)">✓ OK (${d.categories} Kategorien)</span>`;
    if (card) card.style.borderColor = 'var(--green)';
    setTimeout(() => { if (card) card.style.borderColor = ''; if (statusEl) statusEl.innerHTML = ''; }, 4000);
  } else {
    if (statusEl) statusEl.innerHTML = `<span style="color:var(--red)">✕ ${esc(d.error ?? 'Fehler')}</span>`;
    if (card) card.style.borderColor = 'var(--red)';
    setTimeout(() => { if (card) card.style.borderColor = ''; }, 6000);
  }
}
async function testAllServers() {
  const btn = document.getElementById('btn-test-all-servers');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Teste…'; }
  const servers = await api('list_servers');
  if (servers?.length) {
    await Promise.all(servers.map(s => testServer(s.id, s.name, null)));
  }
  if (btn) { btn.disabled = false; btn.textContent = '🔌 Alle testen'; }
}

async function loadServers() {
  const list = document.getElementById('saved-servers-list');
  if (!list) return;
  const servers = await api('list_servers');
  if (!servers?.length) {
    list.innerHTML = `<div style="color:var(--muted);font-size:.8rem">${t('cfg.server_none')}</div>`;
    return;
  }
  list.innerHTML = servers.map(s => {
    const enabled = s.enabled !== false;
    return `
    <div style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:12px 14px;opacity:${enabled?'1':'.5'}" id="srv-card-${esc(s.id)}">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
        <span style="font-size:.9rem;font-weight:600;flex:1">${esc(s.name)}${!enabled?' <span style="font-family:\'DM Mono\',monospace;font-size:.6rem;color:var(--muted)">(deaktiviert)</span>':''}</span>
        <button class="btn-icon" title="Verbindung testen" ${!enabled?'disabled':''} onclick="testServer('${esc(s.id)}','${esc(s.name)}',this)">🔌</button>
        <button class="btn-icon" title="${enabled?'Deaktivieren':'Aktivieren'}" onclick="toggleServer('${esc(s.id)}',this)">${enabled?'⏸':'▶'}</button>
        <button class="btn-icon" title="Bearbeiten" onclick="openEditServerModal(${JSON.stringify(s).replace(/"/g,'&quot;')})">✏️</button>
        <button class="btn-icon danger" title="Löschen" onclick="deleteServer('${esc(s.id)}','${esc(s.name)}')">✕</button>
      </div>
      <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);display:flex;flex-wrap:wrap;gap:10px">
        <span>🌐 ${esc(s.server_ip)}:${esc(s.port)}</span>
        <span>👤 ${esc(s.username)}</span>
        <span id="srv-cache-${esc(s.id)}" style="color:${s.has_cache !== false ? 'var(--green)' : 'var(--orange)'}">
          ${s.has_cache !== false ? '✓ Cache' : '⚠ Kein Cache'}
        </span>
        <span id="srv-test-${esc(s.id)}"></span>
      </div>
    </div>`;
  }).join('');
}

let _editServerId = '';
function openEditServerModal(s) {
  _editServerId = s.id;
  document.getElementById('esrv-name').value      = s.name      ?? '';
  document.getElementById('esrv-ip').value        = s.server_ip ?? '';
  document.getElementById('esrv-port').value      = s.port      ?? '80';
  document.getElementById('esrv-username').value  = s.username  ?? '';
  document.getElementById('esrv-password').value  = '';
  document.getElementById('esrv-password').placeholder = s.password ? t('cfg.pw_set') : t('cfg.password');
  document.getElementById('esrv-msg').textContent = '';
  document.getElementById('edit-server-modal').style.display = 'flex';
}
function closeEditServerModal() {
  document.getElementById('edit-server-modal').style.display = 'none';
}
async function saveEditServer() {
  const msg  = document.getElementById('esrv-msg');
  const name = document.getElementById('esrv-name').value.trim();
  const ip   = document.getElementById('esrv-ip').value.trim();
  const port = document.getElementById('esrv-port').value.trim();
  const user = document.getElementById('esrv-username').value.trim();
  const pw   = document.getElementById('esrv-password').value;
  if (!name || !ip || !user) {
    msg.textContent = '❌ Name, IP und Benutzername sind Pflichtfelder';
    msg.className = 'settings-msg err'; return;
  }
  const payload = {server_id: _editServerId, name, server_ip: ip, port: port || '80', username: user};
  if (pw) payload.password = pw;
  const d = await apiPost('save_server', payload);
  if (d.error) { msg.textContent = '❌ ' + d.error; msg.className = 'settings-msg err'; return; }
  msg.textContent = '✓ Gespeichert'; msg.className = 'settings-msg ok';
  setTimeout(() => closeEditServerModal(), 600);
  loadServers();
}

async function showServerInfo(s) {
  const cacheAge = s.cache_age_min != null
    ? (s.cache_age_min < 60 ? `${s.cache_age_min} Min.` : `${Math.floor(s.cache_age_min/60)}h ${s.cache_age_min%60}m`)
    : '–';

  // Modal sofort mit lokalen Daten öffnen
  const modal = document.getElementById('srv-info-modal');
  document.getElementById('srv-info-title').textContent = s.name;
  document.getElementById('srv-info-body').innerHTML = `
    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)"><span style="color:var(--muted);font-size:.78rem">🌐 Host</span><span style="font-family:'DM Mono',monospace;font-size:.78rem">${esc(s.server_ip)}:${esc(s.port)}</span></div>
    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)"><span style="color:var(--muted);font-size:.78rem">👤 User</span><span style="font-family:'DM Mono',monospace;font-size:.78rem">${esc(s.username)}</span></div>
    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)"><span style="color:var(--muted);font-size:.78rem">🎬 Filme</span><span style="font-family:'DM Mono',monospace;font-size:.78rem">${s.movie_count != null ? s.movie_count.toLocaleString() : '–'}</span></div>
    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)"><span style="color:var(--muted);font-size:.78rem">📺 Serien</span><span style="font-family:'DM Mono',monospace;font-size:.78rem">${s.series_count != null ? s.series_count.toLocaleString() : '–'}</span></div>
    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)"><span style="color:var(--muted);font-size:.78rem">🗂 Cache</span><span style="font-family:'DM Mono',monospace;font-size:.78rem">${s.has_cache ? `✓ vor ${cacheAge}` : '⚠ Kein Cache'}</span></div>
    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)"><span style="color:var(--muted);font-size:.78rem">⏳ Pending</span><span style="font-family:'DM Mono',monospace;font-size:.78rem">${s.queue?.pending ?? 0}</span></div>
    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)"><span style="color:var(--muted);font-size:.78rem">⬇ Lädt</span><span style="font-family:'DM Mono',monospace;font-size:.78rem">${s.queue?.downloading ?? 0}</span></div>
    <div style="display:flex;justify-content:space-between;padding:6px 0"><span style="color:var(--muted);font-size:.78rem">❌ Fehler</span><span style="font-family:'DM Mono',monospace;font-size:.78rem">${s.queue?.error ?? 0}</span></div>
    <div id="srv-xinfo" style="margin-top:10px;border-top:1px solid var(--border);padding-top:10px">
      <div style="color:var(--muted);font-size:.72rem;font-family:'DM Mono',monospace">⏳ Xtream-Serverdaten werden geladen…</div>
    </div>`;
  modal.style.display = 'flex';

  // Live-Daten vom Xtream-Server nachladen
  const xi = await apiPost('get_server_xinfo', {server_id: s.id});
  const xEl = document.getElementById('srv-xinfo');
  if (!xEl) return;
  if (xi.error) {
    xEl.innerHTML = `<div style="color:var(--red);font-size:.72rem;font-family:'DM Mono',monospace">⚠ ${esc(xi.error)}</div>`;
    return;
  }
  const xRows = [
    ['📋 Status',       xi.status],
    ['📅 Läuft bis',    xi.exp_date],
    ['🔗 Verbindungen', `${xi.active_cons} / ${xi.max_connections}`],
    ['🧪 Trial',        xi.is_trial ? 'Ja' : 'Nein'],
    ['🕐 Zeitzone',     xi.timezone],
    ['🖥 Version',      xi.server_version],
  ];
  xEl.innerHTML = xRows.map(([k, v]) =>
    `<div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border)">
      <span style="color:var(--muted);font-size:.78rem">${k}</span>
      <span style="font-family:'DM Mono',monospace;font-size:.78rem">${esc(String(v ?? '–'))}</span>
    </div>`).join('');
}
function closeSrvInfoModal() {
  document.getElementById('srv-info-modal').style.display = 'none';
}

async function toggleServer(serverId, btn) {
  const d = await apiPost('toggle_server', {server_id: serverId});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast(d.enabled ? '✓ Server aktiviert' : '⏸ Server deaktiviert', 'info');
  loadServers();
}

async function deleteServer(serverId, name) {
  if (!await showConfirm(`Server "${name}" wird gelöscht. Queue, Cache und Download-Verlauf dieses Servers werden ebenfalls gelöscht.`, {title:'Server löschen?', icon:'🗑', okLabel:'Löschen', danger:true})) return;
  const d = await apiPost('delete_server', {server_id: serverId});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast('🗑 Server entfernt', 'info');
  loadServers();
}

async function loadConfig() {
  const c = await api('get_config');
  document.getElementById('cfg-dest-path').value = c.dest_path ?? '';
  // rclone
  const rcloneEnabled = c.rclone_enabled ?? false;
  document.getElementById('cfg-rclone-enabled').checked = rcloneEnabled;
  document.getElementById('cfg-rclone-remote').value    = c.rclone_remote ?? '';
  document.getElementById('cfg-rclone-path').value      = c.rclone_path   ?? '';
  document.getElementById('cfg-rclone-bin').value       = c.rclone_bin    ?? 'rclone';
  toggleRcloneFields(rcloneEnabled);
  // Editor feature flags
  document.getElementById('cfg-editor-movies').checked = c.editor_movies_enabled ?? true;
  document.getElementById('cfg-editor-series').checked = c.editor_series_enabled ?? true;
  document.getElementById('cfg-tmdb-api-key').value    = c.tmdb_api_key ?? '';
  document.getElementById('cfg-tmdb-api-key').placeholder = c.tmdb_api_key === '••••••••' ? t('cfg.pw_saved') : t('cfg.tmdb_placeholder');
  const tgToken = document.getElementById('cfg-telegram-bot-token');
  const tgChat  = document.getElementById('cfg-telegram-chat-id');
  if (tgToken) { tgToken.value = c.telegram_bot_token ?? ''; tgToken.placeholder = c.telegram_bot_token === '••••••••' ? t('cfg.pw_saved') : t('cfg.telegram_placeholder'); }
  if (tgChat)  tgChat.value = c.telegram_chat_id ?? '';
  const tgEnabled = document.getElementById('cfg-telegram-enabled');
  if (tgEnabled) {
    tgEnabled.checked = c.telegram_enabled ?? false;
    toggleTelegramOptions(tgEnabled.checked);
  }
  const setChk = (id, val, def = false) => { const el = document.getElementById(id); if (el) el.checked = val ?? def; };
  setChk('cfg-tg-notify-success',    c.tg_notify_success,    true);
  setChk('cfg-tg-notify-error',      c.tg_notify_error,      true);
  setChk('cfg-tg-notify-queue-done',    c.tg_notify_queue_done,    false);
  setChk('cfg-tg-notify-new-releases', c.tg_notify_new_releases, false);
  setChk('cfg-tg-notify-disk-low',   c.tg_notify_disk_low,   false);
  const diskGbEl = document.getElementById('cfg-tg-disk-low-gb');
  if (diskGbEl) diskGbEl.value = c.tg_disk_low_gb ?? 10;
  toggleDiskLowField(c.tg_notify_disk_low ?? false);
  const vpnIface   = document.getElementById('cfg-vpn-interface');
  if (vpnIface)   vpnIface.value      = c.vpn_interface  ?? 'wg0';
  // Parallel Downloads
  const parallelEnabled = c.parallel_enabled ?? true;
  const parallelMaxEl   = document.getElementById('cfg-parallel-max');
  const parallelChk     = document.getElementById('cfg-parallel-enabled');
  if (parallelChk) { parallelChk.checked = parallelEnabled; }
  if (parallelMaxEl) parallelMaxEl.value = c.parallel_max ?? 4;
  const pFields = document.getElementById('parallel-fields');
  if (pFields) pFields.style.display = parallelEnabled ? '' : 'none';
  // IP-Whitelist
  const ipEl = document.getElementById('cfg-api-allowed-ips');
  if (ipEl) ipEl.value = (c.api_allowed_ips ?? '').split(',').map(s=>s.trim()).filter(Boolean).join('\n');
  // App-Titel
  const appTitleEl = document.getElementById('cfg-app-title');
  if (appTitleEl) appTitleEl.value = c.app_title ?? 'Xtream Vault';
  const blEl = document.getElementById('cfg-category-blacklist');
  if (blEl) blEl.value = (c.category_blacklist ?? '').split(',').map(s=>s.trim()).filter(Boolean).join('\n');
  const aqChk   = document.getElementById('cfg-autoqueue-enabled');
  const aqMax   = document.getElementById('cfg-autoqueue-max');
  const aqFields = document.getElementById('autoqueue-fields');
  if (aqChk) { aqChk.checked = c.autoqueue_enabled ?? false; if (aqFields) aqFields.style.display = aqChk.checked ? '' : 'none'; }
  if (aqMax) aqMax.value = c.autoqueue_max ?? 10;
  // Aktuelle IP anzeigen
  const yourIpEl = document.getElementById('your-ip');
  if (yourIpEl) api('get_my_ip').then(d => { if (d.ip) yourIpEl.textContent = d.ip; });
  setSettingsMsg('', '');
}

function toggleRcloneFields(enabled) {
  const fields = document.getElementById('rclone-fields');
  if (fields) fields.style.display = enabled ? '' : 'none';
  if (enabled) loadRcloneCacheStatus();
}

function toggleTelegramOptions(enabled) {
  const opts = document.getElementById('telegram-notify-options');
  if (opts) opts.style.display = enabled ? '' : 'none';
}

function toggleDiskLowField(enabled) {
  const field = document.getElementById('tg-disk-low-field');
  if (field) field.style.display = enabled ? '' : 'none';
}

async function loadRcloneCacheStatus() {
  const el = document.getElementById('rclone-cache-status');
  if (!el) return;
  const d = await api('rclone_cache_status');
  if (!d.exists) {
    el.textContent = '🗂 Kein Remote-Cache vorhanden — wird beim nächsten Download-Run automatisch aufgebaut';
  } else {
    el.textContent = `🗂 Remote-Cache: ${d.count.toLocaleString()} Dateien bekannt · Stand: ${d.cached_at}`;
  }
}

async function refreshRcloneCache(btn) {
  const statusEl = document.getElementById('rclone-cache-status');
  const msgEl    = document.getElementById('rclone-test-msg');
  btn.disabled = true;
  btn.textContent = t('phplog.loading');
  if (statusEl) statusEl.textContent = '⏳ Lade Dateiliste vom Remote… (kann je nach Größe etwas dauern)';
  const d = await api('rclone_cache_refresh');
  btn.disabled = false;
  btn.textContent = t('cfg.rclone_cache');
  if (d.error) {
    if (msgEl) { msgEl.textContent = '❌ ' + d.error; msgEl.className = 'settings-msg err'; }
    if (statusEl) statusEl.textContent = '❌ Fehler beim Laden';
  } else {
    if (msgEl) { msgEl.textContent = `✅ ${d.count.toLocaleString()} Dateien gecacht`; msgEl.className = 'settings-msg ok'; }
    if (statusEl) statusEl.textContent = `🗂 Remote-Cache: ${d.count.toLocaleString()} Dateien bekannt · Stand: ${d.cached_at}`;
  }
}

function collectConfig() {
  return {
    dest_path:      document.getElementById('cfg-dest-path').value.trim(),
    rclone_enabled: document.getElementById('cfg-rclone-enabled').checked,
    rclone_remote:  document.getElementById('cfg-rclone-remote').value.trim(),
    rclone_path:    document.getElementById('cfg-rclone-path').value.trim(),
    rclone_bin:     document.getElementById('cfg-rclone-bin').value.trim() || 'rclone',
    editor_movies_enabled: document.getElementById('cfg-editor-movies').checked,
    editor_series_enabled: document.getElementById('cfg-editor-series').checked,
    tmdb_api_key:          (function() { const v = document.getElementById('cfg-tmdb-api-key').value.trim(); return (v === '••••••••' || v === '') ? '' : v; })(),
    telegram_bot_token:    (function() { const el = document.getElementById('cfg-telegram-bot-token'); const v = el?.value.trim() ?? ''; return (v === '••••••••' || v === '') ? '' : v; })(),
    telegram_chat_id:      document.getElementById('cfg-telegram-chat-id')?.value.trim() ?? '',
    telegram_enabled:      document.getElementById('cfg-telegram-enabled')?.checked ?? false,
    tg_notify_success:     document.getElementById('cfg-tg-notify-success')?.checked  ?? true,
    tg_notify_error:       document.getElementById('cfg-tg-notify-error')?.checked    ?? true,
    tg_notify_queue_done:  document.getElementById('cfg-tg-notify-queue-done')?.checked ?? false,
    tg_notify_new_releases: document.getElementById('cfg-tg-notify-new-releases')?.checked ?? false,
    tg_notify_disk_low:    document.getElementById('cfg-tg-notify-disk-low')?.checked  ?? false,
    tg_disk_low_gb:        parseFloat(document.getElementById('cfg-tg-disk-low-gb')?.value ?? '10'),
    vpn_interface:         document.getElementById('cfg-vpn-interface')?.value.trim() ?? 'wg0',
    parallel_enabled:      document.getElementById('cfg-parallel-enabled')?.checked ?? true,
    parallel_max:          parseInt(document.getElementById('cfg-parallel-max')?.value ?? '4') || 4,
    api_allowed_ips:       (document.getElementById('cfg-api-allowed-ips')?.value ?? '').split('\n').map(s=>s.trim()).filter(Boolean).join(','),
    app_title:             document.getElementById('cfg-app-title')?.value.trim() || 'Xtream Vault',
    category_blacklist:    (document.getElementById('cfg-category-blacklist')?.value ?? '').split('\n').map(s=>s.trim()).filter(Boolean).join(','),
    autoqueue_enabled:     document.getElementById('cfg-autoqueue-enabled')?.checked ?? false,
    autoqueue_max:         parseInt(document.getElementById('cfg-autoqueue-max')?.value ?? '10') || 10,
  };
}

// ── Updates ───────────────────────────────────────────────────
async function loadPhpErrorLog(btn) {
  const logEl  = document.getElementById('php-error-log');
  const pathEl = document.getElementById('php-log-path');
  if (btn) btn.disabled = true;
  if (pathEl) pathEl.textContent = t('phplog.loading');
  if (logEl)  { logEl.style.display = ''; logEl.textContent = t('phplog.loading'); }

  const d = await api('php_error_log');
  if (btn) btn.disabled = false;

  if (d.error) {
    if (pathEl) pathEl.textContent = '⚠️ ' + d.error;
    if (logEl)  logEl.textContent = d.error;
    return;
  }
  if (pathEl) pathEl.textContent = d.path ?? '';
  if (!d.lines?.length) {
    if (logEl) logEl.textContent = t('phplog.empty');
    return;
  }
  if (logEl) {
    logEl.innerHTML = d.lines.map(line => {
      const l = line.replace(/&/g,'&amp;').replace(/</g,'&lt;');
      if (l.includes('PHP Fatal')   || l.includes('PHP Parse'))  return `<span style="color:var(--red)">${l}</span>`;
      if (l.includes('PHP Warning') || l.includes('PHP Deprecated')) return `<span style="color:var(--orange)">${l}</span>`;
      if (l.includes('PHP Notice')) return `<span style="color:var(--muted)">${l}</span>`;
      return l;
    }).join('\n');
    logEl.scrollTop = logEl.scrollHeight;
  }
}

async function checkUpdate(btn) {
  const statusEl = document.getElementById('update-status');
  const msgEl    = document.getElementById('update-msg');
  const updateBtn= document.getElementById('btn-run-update');
  if (btn) btn.disabled = true;
  statusEl.innerHTML = `<div style="color:var(--muted)">⏳ Prüfe GitHub…</div>`;
  msgEl.textContent = '';
  updateBtn.style.display = 'none';

  const d = await api('check_update');
  if (btn) btn.disabled = false;

  if (d.error) {
    statusEl.innerHTML = `<div style="color:var(--red)">❌ ${esc(d.error)}</div>`;
    return;
  }

  const dateStr = d.remote_date ? ` · ${d.remote_date}` : '';
  if (d.up_to_date) {
    statusEl.innerHTML = `
      <div style="color:var(--green)">✅ Aktuell</div>
      <div style="color:var(--muted);margin-top:4px">${t('update.local')}: <span style="color:var(--text)">${esc(d.local_commit)}</span></div>
      <div style="color:var(--muted)">${t('update.remote')}: <span style="color:var(--text)">${esc(d.remote_commit)}</span>${dateStr}</div>`;
    const badge = document.getElementById('update-badge');
    if (badge) badge.style.display = 'none';
  } else {
    statusEl.innerHTML = `
      <div style="color:var(--orange)">🆕 Update verfügbar</div>
      <div style="color:var(--muted);margin-top:4px">${t('update.local')}: <span style="color:var(--text)">${esc(d.local_commit)}</span></div>
      <div style="color:var(--muted)">${t('update.remote')}: <span style="color:var(--accent2)">${esc(d.remote_commit)}</span>${dateStr}</div>
      ${d.remote_message ? `<div style="color:var(--muted);margin-top:4px">💬 ${esc(d.remote_message)}</div>` : ''}`;
    updateBtn.style.display = '';
    const badge = document.getElementById('update-badge');
    if (badge) badge.style.display = '';
  }
}

async function runUpdate(btn) {
  const msgEl  = document.getElementById('update-msg');
  const logEl  = document.getElementById('update-log');
  const status = document.getElementById('update-status');

  if (!await showConfirm(t('cfg.update_confirm'), {title:'Update installieren?', icon:'⬆️', okLabel:'Update installieren'})) return;

  btn.disabled = true;
  msgEl.textContent = t('update.installing'); msgEl.className = 'settings-msg info';
  logEl.style.display = 'none';

  const d = await apiPost('run_update', {});
  btn.disabled = false;

  if (d.error) {
    msgEl.textContent = '❌ ' + d.error; msgEl.className = 'settings-msg err';
    if (d.log) { logEl.textContent = d.log; logEl.style.display = ''; }
    return;
  }

  msgEl.textContent = `✅ Update abgeschlossen — Commit ${d.commit}`; msgEl.className = 'settings-msg ok';
  if (d.log) { logEl.textContent = d.log; logEl.style.display = ''; }
  if (d.backup) {
    status.innerHTML += `<div style="color:var(--muted);margin-top:4px">💾 Backup: <span style="color:var(--text)">${esc(d.backup)}</span></div>`;
  }
  btn.style.display = 'none';
  // Seite nach 3s neu laden damit neue PHP-Dateien wirksam werden
  setTimeout(() => location.reload(), 3000);
}

// ── VPN ───────────────────────────────────────────────────────
let _vpnPollInterval  = null;
let _vpnSlowInterval  = null;
let _vpnDurationTimer = null;
let _vpnConnectedSince = null;

async function pollVpnStatus(includeIp = false) {
  const d = await api('vpn_status', includeIp ? {} : {include_ip: '0'}).catch(() => null);
  if (!d || d.error) return;
  if (!includeIp && _vpnLastStatus?.public_ip) d.public_ip = _vpnLastStatus.public_ip;
  _vpnLastStatus = d;
  updateVpnBadge(d);
  _vpnUpdateToggleBtn(d.up);
}

let _vpnLastStatus = null;

function startVpnPolling() {
  pollVpnStatus(true); // Erster Aufruf mit IP
  if (!_vpnPollInterval) _vpnPollInterval = setInterval(() => pollVpnStatus(false), 5000);
  if (!_vpnSlowInterval) _vpnSlowInterval = setInterval(() => pollVpnStatus(true), 30000);
}

function fmtDurationVpn(ts) {
  if (!ts) return '–';
  const secs = Math.floor(Date.now() / 1000) - ts;
  if (secs < 60)   return secs + 's';
  if (secs < 3600) return Math.floor(secs / 60) + 'm ' + (secs % 60) + 's';
  const h = Math.floor(secs / 3600);
  const m = Math.floor((secs % 3600) / 60);
  return h + 'h ' + m + 'm';
}

function updateVpnBadge(status) {
  const badge = document.getElementById('vpn-badge');
  const statsCard = document.getElementById('vpn-stats-card');

  if (!badge) return;
  if (!status) { badge.style.display = 'none'; if (statsCard) statsCard.style.display = 'none'; return; }

  badge.classList.remove('vpn-on');
  badge.style.removeProperty('background');
  badge.style.removeProperty('color');
  badge.style.removeProperty('border-color');

  if (!status.wg_installed) {
    // WireGuard nicht installiert — Badge ausblenden
    badge.style.display = 'none';
    if (statsCard) statsCard.style.display = 'none';
    return;
  }

  // Badge immer anzeigen wenn wg installiert
  badge.style.display = '';

  if (status.up) {
    badge.innerHTML = '<span class="tc-dot"></span> VPN';
    badge.classList.add('vpn-on');
    badge.title = `VPN aktiv — klicken zum Trennen${status.public_ip ? ' · ' + status.public_ip : ''}`;

    // Stats-Card befüllen und anzeigen
    if (statsCard) {
      statsCard.style.display = '';
      const ipEl    = document.getElementById('vpn-stat-ip');
      const ifaceEl = document.getElementById('vpn-stat-iface');
      const sinceEl = document.getElementById('vpn-stat-since');
      if (ipEl)    ipEl.textContent    = status.public_ip || '–';
      if (ifaceEl) ifaceEl.textContent = status.interface || '–';

      // Dauer-Timer starten
      _vpnConnectedSince = status.connected_since || null;
      if (_vpnDurationTimer) clearInterval(_vpnDurationTimer);
      if (sinceEl && _vpnConnectedSince) {
        const updateDur = () => sinceEl.textContent = fmtDurationVpn(_vpnConnectedSince);
        updateDur();
        _vpnDurationTimer = setInterval(updateDur, 1000);
      }
    }
  } else {
    badge.innerHTML = '<span class="tc-dot" style="background:var(--orange)"></span> VPN';
    badge.style.color = 'var(--orange)';
    badge.title = `VPN inaktiv — klicken zum Verbinden (${status.interface})`;
    if (statsCard) statsCard.style.display = 'none';
    if (_vpnDurationTimer) { clearInterval(_vpnDurationTimer); _vpnDurationTimer = null; }
  }
}

let _confirmCb = null;

function _confirmResolve(val) {
  document.getElementById('confirm-modal').style.display = 'none';
  if (_confirmCb) { _confirmCb(val); _confirmCb = null; }
}

// showConfirm(msg, {title, icon, okLabel, danger}) → Promise<bool>
function showConfirm(msg, opts = {}) {
  return new Promise(resolve => {
    _confirmCb = resolve;
    const modal = document.getElementById('confirm-modal');
    document.getElementById('confirm-modal-icon').textContent  = opts.icon  ?? '❓';
    document.getElementById('confirm-modal-title').textContent = opts.title ?? 'Bestätigen';
    document.getElementById('confirm-modal-msg').textContent   = msg;
    const okBtn = document.getElementById('confirm-modal-ok');
    okBtn.textContent = opts.okLabel ?? 'OK';
    okBtn.style.background   = opts.danger ? 'var(--red)'  : '';
    okBtn.style.color        = opts.danger ? '#fff'        : '';
    okBtn.style.borderColor  = opts.danger ? 'var(--red)'  : '';
    modal.style.display = 'flex';
  });
}

function vpnConfirmDisconnect() {
  return showConfirm(
    'Die VPN-Verbindung wird getrennt. Laufende Downloads werden abgebrochen falls sie über den VPN-Tunnel laufen.',
    { title: 'VPN trennen?', icon: '🔓', okLabel: 'Trennen', danger: true }
  );
}

async function vpnBadgeToggle() {
  const badge = document.getElementById('vpn-badge');
  const isConnected = badge?.classList.contains('vpn-on');
  if (isConnected && !await vpnConfirmDisconnect()) return;
  if (badge) { badge.style.opacity = '.5'; badge.style.pointerEvents = 'none'; }
  const d = await apiPost(isConnected ? 'vpn_disconnect' : 'vpn_connect', {});
  if (d.error) {
    if (badge) { badge.style.opacity = ''; badge.style.pointerEvents = ''; }
    showToast('❌ VPN: ' + d.error, 'error');
    return;
  }
  showToast(isConnected ? '🔓 VPN getrennt' : '🔒 VPN verbunden', 'success');
  _vpnUpdateToggleBtn(!isConnected);
  // WireGuard braucht kurz zum Hoch-/Runterfahren → 1.5s warten dann Status holen
  await new Promise(r => setTimeout(r, 1500));
  if (badge) { badge.style.opacity = ''; badge.style.pointerEvents = ''; }
  await pollVpnStatus(true);
}

function _vpnUpdateToggleBtn(up) {
  const btn = document.getElementById('vpn-toggle-btn');
  if (!btn) return;
  if (up) {
    btn.textContent = '■ Trennen';
    btn.style.borderColor = 'rgba(255,71,87,.4)';
    btn.style.color = 'var(--red)';
  } else {
    btn.textContent = '▶ Verbinden';
    btn.style.borderColor = '';
    btn.style.color = '';
  }
}

async function checkVpnStatus() {
  const msg = document.getElementById('vpn-status-msg');
  const btn = document.getElementById('vpn-toggle-btn');
  if (btn) { btn.disabled = true; btn.textContent = t('vpn.checking'); }
  msg.textContent = ''; msg.className = 'settings-msg';
  const d = await api('vpn_status');
  if (btn) btn.disabled = false;
  if (d.error) { msg.textContent = '❌ ' + d.error; msg.className = 'settings-msg err'; _vpnUpdateToggleBtn(false); return; }
  updateVpnBadge(d);
  if (!d.wg_installed) {
    msg.textContent = t('cfg.vpn_not_installed_hint');
    msg.className = 'settings-msg err'; _vpnUpdateToggleBtn(false); return;
  }
  const ipText = d.public_ip ? ` · IP: ${d.public_ip}` : '';
  msg.textContent = `${d.up ? t('vpn.active') : t('vpn.inactive')} (${d.interface})${ipText}`;
  msg.className = d.up ? 'settings-msg ok' : 'settings-msg err';
  _vpnUpdateToggleBtn(d.up);
}

async function vpnToggle(btn) {
  const isConnected = btn.textContent.includes('Trennen');
  if (isConnected && !await vpnConfirmDisconnect()) return;
  const msg = document.getElementById('vpn-status-msg');
  btn.disabled = true;
  btn.textContent = isConnected ? t('vpn.disconnecting') : t('vpn.connecting');
  msg.textContent = ''; msg.className = 'settings-msg info';
  const d = await apiPost(isConnected ? 'vpn_disconnect' : 'vpn_connect', {});
  btn.disabled = false;
  if (d.error) {
    msg.textContent = '❌ ' + d.error; msg.className = 'settings-msg err';
    _vpnUpdateToggleBtn(isConnected); // zurücksetzen
    return;
  }
  msg.textContent = isConnected ? t('vpn.disconnected') : t('vpn.connected');
  msg.className = isConnected ? 'settings-msg err' : 'settings-msg ok';
  _vpnUpdateToggleBtn(!isConnected);
  await new Promise(r => setTimeout(r, 1500));
  pollVpnStatus(true);
}

async function vpnConnect() { /* legacy — nicht mehr verwendet */ }
async function vpnDisconnect() { /* legacy — nicht mehr verwendet */ }

async function testTelegram() {
  const msgEl = document.getElementById('telegram-test-msg');
  msgEl.textContent = t('telegram.sending'); msgEl.className = 'settings-msg info';
  const cfg = collectConfig();
  const d = await apiPost('telegram_test', {
    bot_token: cfg.telegram_bot_token,
    chat_id:   cfg.telegram_chat_id,
  });
  if (d.error) {
    msgEl.textContent = '❌ ' + d.error; msgEl.className = 'settings-msg err';
  } else {
    msgEl.textContent = t('telegram.ok'); msgEl.className = 'settings-msg ok';
  }
}

async function testRclone() {
  const msgEl = document.getElementById('rclone-test-msg');
  msgEl.textContent = '⏳ Teste…'; msgEl.className = 'settings-msg info';
  const cfg = collectConfig();
  const r = await fetch(`${API}?action=rclone_test`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...({rclone_bin: cfg.rclone_bin, rclone_remote: cfg.rclone_remote}), _csrf: CSRF_TOKEN})
  });
  const d = await r.json();
  if (d.error) {
    msgEl.textContent = '❌ ' + d.error; msgEl.className = 'settings-msg err';
  } else {
    msgEl.textContent = `✅ Verbunden — ${d.version}`; msgEl.className = 'settings-msg ok';
  }
}

async function testConnection() {
  setSettingsMsg('Verbindung wird getestet…', 'info');
  const cfg = collectConfig();
  const r = await fetch(`${API}?action=save_config`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...({...cfg, test_connection: true}), _csrf: CSRF_TOKEN})
  });
  const d = await r.json();
  if (d.error) {
    setSettingsMsg('❌ ' + d.error, 'err');
  } else {
    setSettingsMsg(`✅ Verbindung erfolgreich — ${d.categories} Kategorien gefunden`, 'ok');
  }
}

async function saveAppTitle() {
  const val = document.getElementById('cfg-app-title').value.trim();
  const msg = document.getElementById('app-title-msg');
  const d   = await apiPost('save_app_title', {app_title: val || 'Xtream Vault'});
  if (d.ok) {
    document.title = (val || 'Xtream Vault').toUpperCase();
    document.querySelector('.logo-text').textContent = val || 'Xtream Vault';
    showSettingsMsg(msg, t('settings.save_ok'), 'success');
  } else {
    showSettingsMsg(msg, t('settings.save_err'), 'error');
  }
}

async function saveConfig() {
  const btn = document.getElementById('btn-save-cfg');
  btn.disabled = true;
  setSettingsMsg('Speichern…', 'info');
  const cfg = collectConfig();
  const r = await fetch(`${API}?action=save_config`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...({...cfg, save: true}), _csrf: CSRF_TOKEN})
  });
  const d = await r.json();
  btn.disabled = false;
  if (d.error) {
    setSettingsMsg('❌ ' + d.error, 'err');
  } else {
    setSettingsMsg('✅ Einstellungen gespeichert', 'ok');
    loadStats();
    refreshDashboard();
    loadServers();
    const ub = document.getElementById('unconfigured-banner');
    if (ub) ub.style.display = 'none';
  }
}

function setSettingsMsg(msg, type) {
  const el = document.getElementById('settings-msg');
  if (!el) return;
  el.textContent = msg;
  el.className = 'settings-msg' + (type ? ' ' + type : '');
}

async function loadCacheStatus() {
  const box = document.getElementById('cache-status-box');
  const btn = document.getElementById('btn-rebuild-cache');
  if (!box) return;
  const s = await api('cache_status');
  if (s.building) {
    box.innerHTML = `<span style="color:var(--accent2)">⏳ Aufbau läuft…</span> ${s.last_message ? '— ' + s.last_message : ''}`;
    if (btn) btn.style.display = 'none';
    setTimeout(loadCacheStatus, 4000);
  } else if (s.movie_cache_ready) {
    box.innerHTML = `<span style="color:var(--green)">✓ Cache vorhanden</span> — vor ${s.cache_age_min ?? '?'} Min. aktualisiert`;
    if (btn) btn.style.display = '';
  } else {
    box.innerHTML = `<span style="color:var(--orange)">⚠ Kein Cache</span> — noch nicht aufgebaut`;
    if (btn) btn.style.display = '';
  }
}

async function rebuildCache(btn) {
  btn.style.display = 'none';
  const msgEl = document.getElementById('cache-msg');
  msgEl.textContent = '⏳ Cache-Rebuild gestartet…';
  msgEl.className = 'settings-msg info';
  const d = await api('rebuild_library_cache');
  if (d.error) {
    msgEl.textContent = '❌ ' + d.error;
    msgEl.className = 'settings-msg err';
    btn.style.display = '';
  } else {
    msgEl.textContent = '✅ Läuft im Hintergrund — dauert je nach Servergröße einige Minuten';
    msgEl.className = 'settings-msg ok';
    setTimeout(loadCacheStatus, 3000);
  }
}
<?php endif; ?>

async function refreshDashboard() {
  const ub = document.getElementById('unconfigured-banner');
  <?php if ($can_settings): ?>
  const c = await api('get_config');
  if (ub) ub.style.display = c.configured ? 'none' : '';
  if (c.configured) {
    loadDashboardData();
  }
  <?php else: ?>
  if (ub) ub.style.display = 'none';
  <?php endif; ?>
}

// ── TMDB Info Modal ───────────────────────────────────────────
function handleCardClick(event, card) {
  // Klicks auf Buttons/Links innerhalb der Karte ignorieren
  if (event.target.closest('button, a, .select-check, .btn-fav')) return;
  const title     = card.dataset.tmdbTitle;
  const type      = card.dataset.tmdbType;
  const year      = card.dataset.tmdbYear || '';
  const queueData = card.dataset.tmdbQueue ? JSON.parse(card.dataset.tmdbQueue) : null;
  openTmdbModal(title, type, year, queueData);
}
const _tmdbCache = new Map();

async function openTmdbModal(title, type, year, queueData) {
  const modal = document.getElementById('tmdb-modal');
  modal.style.display = 'flex';

  // Zuletzt gesehen tracken
  if (queueData?.stream_id) {
    try {
      const key = 'xv_seen_<?= $user['id'] ?>';
      const seen = JSON.parse(localStorage.getItem(key) || '{}');
      seen[String(queueData.stream_id)] = Date.now();
      // Max 500 Einträge — älteste entfernen
      const entries = Object.entries(seen).sort((a,b) => b[1]-a[1]).slice(0, 500);
      localStorage.setItem(key, JSON.stringify(Object.fromEntries(entries)));
      _seenIds.add(String(queueData.stream_id));
      // Badge auf Karte aktualisieren
      const card = document.getElementById('card-m-' + queueData.stream_id);
      if (card && !card.classList.contains('downloaded') && !card.classList.contains('queued')) {
        const existing = card.querySelector('.badge-seen');
        if (!existing) {
          const b = document.createElement('span');
          b.className = 'card-badge badge-seen'; b.textContent = '👁';
          card.querySelector('.card-thumb')?.appendChild(b);
        }
      }
    } catch(e) {}
  }
  // Reset state
  document.getElementById('tmdb-loading').style.display = '';
  document.getElementById('tmdb-error').style.display = 'none';
  document.getElementById('tmdb-poster').style.display = 'none';
  document.getElementById('tmdb-backdrop').style.backgroundImage = '';
  document.getElementById('tmdb-title').textContent = title;
  document.getElementById('tmdb-meta').textContent = '';
  document.getElementById('tmdb-rating').innerHTML = '';
  document.getElementById('tmdb-overview').textContent = '';
  document.getElementById('tmdb-genres').innerHTML = '';
  document.getElementById('tmdb-actions').innerHTML = '';
  document.getElementById('tmdb-stream-info').style.display = 'none';
  document.getElementById('tmdb-stream-badges').innerHTML = '';

  // Queue-Button vorbelegen — Status aus _queuedIds / _downloadedIds prüfen
  if (queueData) {
    const sid = String(queueData.stream_id ?? queueData.series_id ?? '');
    let actionHtml = '';
    if (type === 'series') {
      actionHtml = `<button class="btn-primary" onclick="closeTmdbModal();openSeriesModal('${queueData.series_id}','${esc(queueData.clean_title)}','${esc(queueData.cover||'')}','${esc(queueData.category||'')}','${esc(queueData._server_id||'')}')">📋 Episodes</button>`;
    } else if (_downloadedIds.has(sid) && canQueueRemove) {
      actionHtml = `<button class="btn-secondary" onclick="closeTmdbModal();resetDownload('${sid}','movie',null)">↺ Reset</button>`;
    } else if (_downloadedIds.has(sid)) {
      actionHtml = `<button class="btn-secondary" disabled>${t('btn.done')}</button>`;
    } else if (_queuedIds.has(sid) && (canQueueRemove || canQueueRemoveOwn)) {
      actionHtml = `<button class="btn-secondary" onclick="closeTmdbModal();removeFromQueue('${sid}',null)">${t('btn.remove_queue')}</button>`;
    } else if (_queuedIds.has(sid)) {
      actionHtml = `<button class="btn-secondary" disabled>${t('btn.queued')}</button>`;
    } else if (canQueueAdd) {
      const qd = JSON.stringify(queueData).replace(/"/g,'&quot;');
      actionHtml = `<button class="btn-primary" onclick="closeTmdbModal();addMovieToQueue(${qd},null)">+ Queue</button>`;
    }
    // "Als heruntergeladen markieren" — nur Admins, nur wenn nicht already done
    <?php if ($can_settings): ?>
    if (!_downloadedIds.has(sid) && type !== 'series') {
      const qt = esc(queueData.clean_title || queueData.title || '');
      const qc = esc(queueData.cover || '');
      const qcat = esc(queueData.category || '');
      const qext = esc(queueData.container_extension || 'mp4');
      const qsrv = esc(queueData._server_id || '');
      actionHtml += ` <button class="btn-secondary" style="opacity:.7;font-size:.78rem"
        onclick="markDownloaded('${sid}','movie','${qt}','${qc}','${qcat}','${qext}','${qsrv}',null)">✓ ${t('btn.mark_downloaded')}</button>`;
    }
    <?php endif; ?>
    document.getElementById('tmdb-actions').innerHTML = actionHtml;
  }

  const cacheKey = `${type}:${title}:${year||''}`;
  let d;
  if (_tmdbCache.has(cacheKey)) {
    d = _tmdbCache.get(cacheKey);
  } else {
    d = await api('tmdb_info', {title, type, year: year || ''});
    if (d && !d.error) _tmdbCache.set(cacheKey, d);
  }

  document.getElementById('tmdb-loading').style.display = 'none';

  if (!d || d.error) {
    document.getElementById('tmdb-error').style.display = '';
    document.getElementById('tmdb-error').textContent = d?.error === 'TMDB API-Key nicht konfiguriert'
      ? '🔑 TMDB API-Key nicht konfiguriert (Einstellungen → TMDB)'
      : '⚠️ Keine TMDB-Informationen gefunden';
    return;
  }
  if (!d.found) {
    document.getElementById('tmdb-error').style.display = '';
    document.getElementById('tmdb-error').textContent = '⚠️ Kein Treffer auf TMDB gefunden';
    return;
  }

  // Backdrop
  if (d.backdrop) {
    document.getElementById('tmdb-backdrop').style.backgroundImage = `url(${d.backdrop})`;
  }
  // Poster
  if (d.poster) {
    const img = document.getElementById('tmdb-poster');
    img.src = d.poster; img.style.display = '';
  }
  // Titel + Meta
  document.getElementById('tmdb-title').textContent = d.title || title;
  const parts = [];
  if (d.release) parts.push(d.release.slice(0, 4));
  if (d.runtime) parts.push(d.runtime + ' Min.');
  document.getElementById('tmdb-meta').textContent = parts.join(' · ');

  // Rating
  if (d.rating) {
    const stars = Math.round(d.rating / 2);
    const starHtml = Array.from({length: 5}, (_, i) =>
      `<span style="color:${i < stars ? '#f5c518' : 'var(--border)'};font-size:1rem">★</span>`
    ).join('');
    document.getElementById('tmdb-rating').innerHTML =
      `${starHtml}<span style="font-family:'DM Mono',monospace;font-size:.75rem;color:var(--muted);margin-left:6px">${d.rating}/10 (${d.vote_count?.toLocaleString()} Bewertungen)</span>`;
  }
  // Overview
  document.getElementById('tmdb-overview').textContent = d.overview || 'Keine Beschreibung verfügbar.';
  // Genres
  if (d.genres?.length) {
    document.getElementById('tmdb-genres').innerHTML = d.genres.map(g =>
      `<span style="background:var(--bg3);border:1px solid var(--border);border-radius:20px;padding:3px 10px;font-size:.72rem;font-family:'DM Mono',monospace">${esc(g)}</span>`
    ).join('');
  }
  // TMDB-Link
  if (d.tmdb_url) {
    document.getElementById('tmdb-actions').innerHTML +=
      `<a href="${esc(d.tmdb_url)}" target="_blank" class="btn-secondary" style="text-decoration:none;font-size:.75rem">🔗 TMDB</a>`;
  }

  // Stream-Info asynchron laden (nur für Filme, nicht Serien)
  if (queueData && type === 'movie') {
    const sid      = String(queueData.stream_id ?? '');
    const ext      = queueData.container_extension ?? queueData.ext ?? 'mp4';
    const serverId = queueData._server_id ?? '';
    if (sid) loadStreamInfo(sid, 'movie', ext, serverId);
  }
}

async function loadStreamInfo(sid, type, ext, serverId = '') {
  const infoEl   = document.getElementById('tmdb-stream-info');
  const badgesEl = document.getElementById('tmdb-stream-badges');
  if (!infoEl || !badgesEl) return;

  // Lade-Indikator
  infoEl.style.display = '';
  badgesEl.innerHTML = `<span style="font-family:'DM Mono',monospace;font-size:.68rem;color:var(--muted)">⏳ Analysiere Stream…</span>`;

  const d = await api('stream_info', {stream_id: sid, type, ext, server_id: serverId});

  if (!d || d.error) {
    infoEl.style.display = 'none';
    return;
  }

  const badges = [];
  const badge = (text, color) =>
    `<span style="background:var(--bg3);border:1px solid ${color ?? 'var(--border)'};border-radius:4px;padding:3px 9px;font-size:.68rem;font-family:'DM Mono',monospace;color:${color ?? 'var(--text)'}">${text}</span>`;

  if (d.video) {
    const v = d.video;
    if (v.hdr)        badges.push(badge(v.hdr, 'var(--orange)'));
    if (v.resolution) badges.push(badge(v.resolution, 'var(--accent)'));
    if (v.codec)      badges.push(badge(v.codec));
    if (v.fps)        badges.push(badge(v.fps));
    if (v.bitrate_kbps) {
      const mbps = (v.bitrate_kbps / 1000).toFixed(1);
      badges.push(badge(mbps + ' Mbps'));
    }
  }
  if (d.audio) {
    const a = d.audio;
    const audioStr = [a.codec, a.layout].filter(Boolean).join(' ');
    if (audioStr) badges.push(badge(audioStr));
    if (a.sample_rate) badges.push(badge(a.sample_rate));
  }
  if (d.duration) badges.push(badge('⏱ ' + d.duration));

  if (!badges.length) {
    infoEl.style.display = 'none';
    return;
  }

  badgesEl.innerHTML = badges.join('');
}

function closeTmdbModal() {
  document.getElementById('tmdb-modal').style.display = 'none';
}

// ── Reveal API Key Modal ───────────────────────────────────────────────────────
let _revealKeyId = null;

function showRevealModal(id, name) {
  _revealKeyId = id;
  document.getElementById('reveal-modal-title').textContent = `API-Key anzeigen — ${name}`;
  document.getElementById('reveal-password-input').value = '';
  document.getElementById('reveal-error').style.display = 'none';
  document.getElementById('reveal-password-section').style.display = '';
  document.getElementById('reveal-key-section').style.display = 'none';
  document.getElementById('reveal-modal').style.display = 'flex';
  setTimeout(() => document.getElementById('reveal-password-input').focus(), 50);
}

function closeRevealModal() {
  document.getElementById('reveal-modal').style.display = 'none';
  document.getElementById('reveal-key-value').textContent = '';
  _revealKeyId = null;
}

async function submitReveal() {
  const pw  = document.getElementById('reveal-password-input').value;
  const err = document.getElementById('reveal-error');
  if (!pw) { err.textContent = 'Bitte Passwort eingeben'; err.style.display = ''; return; }

  const d = await apiPost('reveal_api_key', {id: _revealKeyId, password: pw});
  if (d.error) {
    err.textContent = d.error;
    err.style.display = '';
    document.getElementById('reveal-password-input').value = '';
    document.getElementById('reveal-password-input').focus();
    return;
  }
  document.getElementById('reveal-password-section').style.display = 'none';
  document.getElementById('reveal-key-value').textContent = d.key;
  document.getElementById('reveal-key-section').style.display = '';
}

async function copyRevealKey() {
  const key = document.getElementById('reveal-key-value').textContent;
  try {
    await navigator.clipboard.writeText(key);
    showToast('API-Key kopiert', 'success');
  } catch {
    showToast('Kopieren fehlgeschlagen — bitte manuell kopieren', 'error');
  }
}

// ── API Key Management ────────────────────────────────────────
async function loadApiKeys() {
  const tbody = document.getElementById('apikey-tbody');
  if (!tbody) return;
  const keys = await api('list_api_keys');
  if (!keys.length) {
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)">${t('cfg.api_key_none')}</td></tr>`;
    return;
  }
  tbody.innerHTML = keys.map(k => `
    <tr>
      <td><strong>${esc(k.name)}</strong></td>
      <td><span class="key-preview">${esc(k.key_preview)}</span></td>
      <td><span class="role-badge ${k.active ? 'badge-active' : 'badge-inactive'}">${k.active ? t('users.status_active') : t('cfg.api_key_revoked')}</span></td>
      <td style="font-family:'DM Mono',monospace;font-size:.72rem;color:var(--muted)">${k.created_at ?? '–'}</td>
      <td style="font-family:'DM Mono',monospace;font-size:.72rem;color:var(--muted)">${k.last_used ?? 'Nie'}</td>
      <td style="font-family:'DM Mono',monospace;font-size:.72rem;color:var(--muted)">${k.use_count ?? 0}</td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap;flex-direction:column">
          ${k.active ? `<button class="btn-icon" style="width:100%" onclick="showRevealModal('${k.id}','${esc(k.name)}')">👁 Anzeigen</button>` : ''}
          ${k.active ? `<button class="btn-icon" style="width:100%" onclick="revokeApiKey('${k.id}')">⛔ Widerrufen</button>` : ''}
          <button class="btn-icon danger" style="width:100%" onclick="deleteApiKey('${k.id}')">✕ Löschen</button>
        </div>
      </td>
    </tr>
  `).join('');
}

async function createApiKey() {
  const name = document.getElementById('apikey-name-input').value.trim() || 'API Key';
  const r = await fetch(`${API}?action=create_api_key`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...({name}), _csrf: CSRF_TOKEN})
  });
  const d = await r.json();
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  // Key einmalig anzeigen
  document.getElementById('apikey-new-value').textContent = d.key.key;
  document.getElementById('apikey-new-reveal').style.display = '';
  document.getElementById('apikey-name-input').value = '';
  showToast('API-Key erstellt — jetzt kopieren!', 'success');
  loadApiKeys();
}

async function revokeApiKey(id) {
  if (!await showConfirm('Der API-Key wird widerrufen und kann danach nicht mehr verwendet werden.', {title:'API-Key widerrufen?', icon:'🔑', okLabel:'Widerrufen', danger:true})) return;
  await fetch(`${API}?action=revoke_api_key`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...({id}), _csrf: CSRF_TOKEN})
  });
  showToast(t('apikey.revoked'), 'info');
  loadApiKeys();
}

async function deleteApiKey(id) {
  if (!await showConfirm('Der API-Key wird endgültig gelöscht.', {title:'API-Key löschen?', icon:'🗑', okLabel:'Löschen', danger:true})) return;
  await fetch(`${API}?action=delete_api_key`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...({id}), _csrf: CSRF_TOKEN})
  });
  showToast('API-Key gelöscht', 'success');
  loadApiKeys();
}

<?php if ($can_settings): ?>
async function loadDashboardData() {
  const d = await api('dashboard_data');
  if (!d || d.error) return;

  // Queue-Statistiken
  const qs = d.queue_stats ?? {};
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
  set('dqs-pending',     qs.pending     ?? 0);
  set('dqs-downloading', qs.downloading ?? 0);
  set('dqs-done',        qs.done        ?? 0);
  set('dqs-error',       qs.error       ?? 0);
  set('dash-total-dl',   d.total_downloaded ?? 0);

  // Speicherplatz
  const disk = document.getElementById('dash-disk');
  if (disk && d.disk) {
    if (d.disk.rclone) {
      disk.innerHTML = `<div style="font-size:.85rem">☁️ ${esc(d.disk.remote)}</div>`;
    } else {
      const pct = d.disk.percent ?? 0;
      const col = pct > 90 ? 'var(--red)' : pct > 75 ? 'var(--orange)' : 'var(--green)';
      disk.innerHTML = `
        <div style="display:flex;justify-content:space-between;margin-bottom:6px">
          <span style="font-size:.85rem">${fmtBytes(d.disk.used)} / ${fmtBytes(d.disk.total)}</span>
          <span style="color:${col};font-size:.85rem">${pct}%</span>
        </div>
        <div style="background:var(--bg3);border-radius:3px;height:6px;overflow:hidden">
          <div style="background:${col};width:${pct}%;height:100%;border-radius:3px;transition:width .4s"></div>
        </div>
        <div style="color:var(--muted);font-size:.7rem;margin-top:6px">${fmtBytes(d.disk.free)} frei</div>`;
    }
  } else if (disk) {
    disk.innerHTML = `<div style="color:var(--muted);font-size:.8rem">–</div>`;
  }

  // System-Info
  const sys = document.getElementById('dash-system');
  if (sys && d.system) {
    const s = d.system;
    const memUsed  = fmtBytes(s.mem_used  ?? 0);
    const memTotal = fmtBytes(s.mem_total ?? 0);
    sys.innerHTML = `
      <div style="display:flex;justify-content:space-between"><span style="color:var(--muted)">PHP</span><span>${esc(String(s.php_version ?? '–'))}</span></div>
      <div style="display:flex;justify-content:space-between"><span style="color:var(--muted)">RAM</span><span>${memUsed} / ${memTotal}</span></div>
      ${s.uptime ? `<div style="display:flex;justify-content:space-between"><span style="color:var(--muted)">Uptime</span><span>${esc(s.uptime)}</span></div>` : ''}
    `;
  } else if (sys) {
    sys.innerHTML = `<div style="color:var(--muted);font-size:.8rem">–</div>`;
  }

  // Server-Status
  const srvEl = document.getElementById('dash-servers');
  if (srvEl) {
    if (d.servers?.length) {
      srvEl.style.display = 'flex';
      srvEl.innerHTML = d.servers.map(s => {
        const isDownloading = (s.queue?.downloading ?? 0) > 0;
        const borderColor   = isDownloading ? 'var(--accent)' : 'var(--border)';
        const pulse         = isDownloading ? 'animation:pulse-border-full 1.5s ease-in-out infinite' : '';
        return `
        <div onclick="showServerInfo(${JSON.stringify(s).replace(/"/g,'&quot;')})"
          style="display:inline-flex;align-items:center;gap:6px;background:var(--bg2);border:2px solid ${borderColor};border-radius:8px;padding:6px 12px;font-size:.78rem;cursor:pointer;transition:border-color .2s;${pulse}"
          onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='${borderColor}'">
          <span style="color:${s.has_cache ? 'var(--green)' : 'var(--orange)'}">●</span>
          <span style="font-weight:500">${esc(s.name)}</span>
          ${isDownloading ? `<span style="font-family:'DM Mono',monospace;font-size:.55rem;color:var(--accent)">⬇ ${s.queue.downloading}</span>` : ''}
          ${s.queue?.pending > 0 ? `<span style="font-family:'DM Mono',monospace;font-size:.55rem;color:var(--muted)">${s.queue.pending} pending</span>` : ''}
          ${!s.has_cache ? `<span style="font-family:'DM Mono',monospace;font-size:.55rem;color:var(--orange)">NO CACHE</span>` : ''}
        </div>`;
      }).join('');
    } else {
      srvEl.style.display = 'none';
    }
  }

  // Letzte Downloads
  const recent = document.getElementById('dash-recent');
  if (recent) {
    const newIds = (d.recent_downloads ?? []).map(i => i.stream_id + ':' + i.type).join(',');
    if (recent.dataset.ids !== newIds) {
      recent.dataset.ids = newIds;
      if (!d.recent_downloads?.length) {
        recent.innerHTML = `<div style="padding:20px;text-align:center;color:var(--muted);font-size:.8rem">${t('dash.no_recent')}</div>`;
      } else {
        recent.innerHTML = d.recent_downloads.map(item => {
          const icon = item.type === 'episode' ? '📺' : '🎬';
          const sid  = item.stream_id ?? '';
          const resetBtn = (canQueueRemove && sid)
            ? `<button class="btn-icon" style="font-size:.62rem;padding:3px 8px;flex-shrink:0" onclick="resetDownload('${sid}','${item.type ?? 'movie'}',this.closest('.dl-row'))" title="Zurücksetzen">↺</button>`
            : '';
          return `<div class="dl-row" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.03)">
            ${item.cover ? `<img src="${esc(item.cover)}" style="width:36px;height:36px;object-fit:cover;border-radius:4px;flex-shrink:0" onerror="this.style.display='none'">` : `<div style="width:36px;height:36px;background:var(--bg3);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0">${icon}</div>`}
            <div style="flex:1;min-width:0">
              <div style="font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(item.title)}</div>
              <div style="font-size:.65rem;color:var(--muted)">${esc(item.added_by)} · ${esc(item.added_at?.slice(0,10) ?? '')}</div>
            </div>
            ${resetBtn}
          </div>`;
        }).join('');
      }
    }
  }
}

// ── Dashboard Schnellzugriff ──────────────────────────────────
async function cancelDownload() {
  if (!await showConfirm(t('queue.cancel_confirm'), {title:'Download abbrechen?', icon:'✕', okLabel:'Abbrechen', danger:true})) return;
  const d = await fetch(`${API}?action=queue_cancel`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({_csrf: CSRF_TOKEN}),
  });
  const r = await d.json();
  if (r.error) { showToast('❌ ' + r.error, 'error'); return; }
  showToast(t('queue.cancel_sent'), 'info');
}

async function startQueue(btn) {
  if (btn) { btn.disabled = true; btn.textContent = '▶ Startet…'; }
  try {
    const d = await apiPost('queue_start', {});
    if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
    showToast(`▶ Download-Worker gestartet (${d.pending} ausstehend)`, 'success');
  } finally {
    if (btn) setTimeout(() => { btn.disabled = false; btn.textContent = btn.dataset.label || '▶ Starten'; }, 3000);
  }
}

async function dashRebuildCache() {
  const d = await api('rebuild_library_cache');
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast('Cache-Rebuild gestartet', 'success');
}
async function dashClearDone() {
  await api('queue_clear_done');
  showToast('Erledigte Einträge entfernt', 'info');
  refreshDashboardQueue(); loadDashboardData(); refreshQueue(); updateQueueBadge(); loadStats();
}
async function dashClearAll() {
  if (!await showConfirm('Alle Einträge werden unwiderruflich aus der Queue entfernt.', {title:'Queue löschen?', icon:'🗑', okLabel:'Löschen', danger:true})) return;
  await apiPost('queue_clear_all', {});
  showToast(t('queue.cleared'), 'info');
  refreshDashboardQueue(); loadDashboardData(); refreshQueue(); updateQueueBadge(); loadStats();
}
// ── Xtream Server Info ────────────────────────────────────────
// loadServerInfo entfernt — Serverinfos werden nicht mehr im Dashboard angezeigt


// ── Statistiken ───────────────────────────────────────────────
<?php if ($can_settings): ?>
let _statsChart     = null;
let _statsChartCnt  = null;
let _statsChartCats = null;
let _statsChartWd   = null;

async function loadStatsView() {
  const d = await api('stats_data');
  if (d.error) return;

  const monoFont = { family: 'DM Mono', size: 10 };
  const gridColor = 'rgba(255,255,255,.05)';

  // ── KPI Cards ─────────────────────────────────────────────────
  document.getElementById('stats-total-count').textContent    = (d.total_count    ?? 0).toLocaleString();
  document.getElementById('stats-total-movies').textContent   = (d.total_movies   ?? 0).toLocaleString();
  document.getElementById('stats-total-episodes').textContent = (d.total_episodes ?? 0).toLocaleString();
  document.getElementById('stats-total-gb').textContent       = fmtBytes(d.total_bytes ?? 0);
  document.getElementById('stats-this-month').textContent     = (d.this_month?.count ?? 0).toLocaleString() + ' DL';
  document.getElementById('stats-this-month-gb').textContent  = fmtBytes(d.this_month?.bytes ?? 0);

  const months  = Object.keys(d.by_month ?? {});
  const gbVals  = months.map(m => +((d.by_month[m].bytes / 1073741824).toFixed(2)));
  const cntVals = months.map(m => d.by_month[m].count);
  const labels  = months.map(m => {
    const [y, mo] = m.split('-');
    return new Date(y, mo - 1).toLocaleDateString('de-DE', {month: 'short', year: '2-digit'});
  });
  // Aktuellen Monat hervorheben
  const currentMonthIdx = months.indexOf(new Date().toISOString().slice(0,7));
  const mkColors = (base, highlight) => months.map((_, i) =>
    i === currentMonthIdx ? highlight : base);

  // ── GB pro Monat ──────────────────────────────────────────────
  const ctxGb = document.getElementById('stats-chart-gb')?.getContext('2d');
  if (ctxGb) {
    if (_statsChart) _statsChart.destroy();
    _statsChart = new Chart(ctxGb, {
      type: 'bar',
      data: { labels, datasets: [{
        label: 'GB', data: gbVals, borderWidth: 1, borderRadius: 4,
        backgroundColor: mkColors('rgba(100,210,255,.2)', 'rgba(100,210,255,.5)'),
        borderColor:     mkColors('rgba(100,210,255,.6)', 'rgba(100,210,255,1)'),
      }]},
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.parsed.y.toFixed(2)} GB` }}},
        scales: {
          x: { ticks: { color: 'var(--muted)', font: monoFont }, grid: { color: gridColor }},
          y: { ticks: { color: 'rgba(100,210,255,.8)', font: monoFont, callback: v => v + ' GB' }, grid: { color: gridColor }},
        }
      }
    });
  }

  // ── Downloads pro Monat ───────────────────────────────────────
  const ctxCnt = document.getElementById('stats-chart-count')?.getContext('2d');
  if (ctxCnt) {
    if (_statsChartCnt) _statsChartCnt.destroy();
    _statsChartCnt = new Chart(ctxCnt, {
      type: 'bar',
      data: { labels, datasets: [{
        label: t('stats.downloads'), data: cntVals, borderWidth: 1, borderRadius: 4,
        backgroundColor: mkColors('rgba(255,159,67,.2)', 'rgba(255,159,67,.5)'),
        borderColor:     mkColors('rgba(255,159,67,.6)', 'rgba(255,159,67,1)'),
      }]},
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.parsed.y} Downloads` }}},
        scales: {
          x: { ticks: { color: 'var(--muted)', font: monoFont }, grid: { color: gridColor }},
          y: { ticks: { color: 'rgba(255,159,67,.8)', font: monoFont, stepSize: 1 }, grid: { color: gridColor }},
        }
      }
    });
  }

  // ── Wochentag-Verteilung ──────────────────────────────────────
  const ctxWd = document.getElementById('stats-chart-weekday')?.getContext('2d');
  if (ctxWd && d.by_weekday) {
    const wdLabels = t('stats.weekdays').split(',');
    const wdCounts = d.by_weekday.map(w => w.count);
    const maxWd = Math.max(...wdCounts, 1);
    if (_statsChartWd) _statsChartWd.destroy();
    _statsChartWd = new Chart(ctxWd, {
      type: 'bar',
      data: { labels: wdLabels, datasets: [{
        label: t('stats.downloads'), data: wdCounts, borderWidth: 1, borderRadius: 6,
        backgroundColor: wdCounts.map(v => `rgba(155,100,255,${0.15 + 0.55 * v / maxWd})`),
        borderColor:     wdCounts.map(v => `rgba(155,100,255,${0.4 + 0.6 * v / maxWd})`),
      }]},
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.parsed.y} Downloads` }}},
        scales: {
          x: { ticks: { color: 'var(--text)', font: monoFont }, grid: { color: gridColor }},
          y: { ticks: { color: 'rgba(155,100,255,.8)', font: monoFont, stepSize: 1 }, grid: { color: gridColor }},
        }
      }
    });
  }

  // ── Top User ──────────────────────────────────────────────────
  const topEl = document.getElementById('stats-top-users');
  if (topEl && d.top_users) {
    const entries = Object.entries(d.top_users);
    if (!entries.length) {
      topEl.innerHTML = `<div style="color:var(--muted);font-size:.8rem">${t('stats.no_data')}</div>`;
    } else {
      const maxCount = entries[0]?.[1]?.count ?? 1;
      topEl.innerHTML = entries.map(([user, data], i) => {
        const pct   = Math.round((data.count / maxCount) * 100);
        const medal = i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `#${i+1}`;
        return `<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
          <span style="width:26px;text-align:center;flex-shrink:0;font-size:.9rem">${medal}</span>
          <div style="flex:1;min-width:0">
            <div style="font-size:.82rem;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(user)}</div>
            <div style="height:3px;background:var(--bg3);border-radius:2px;margin-top:4px;overflow:hidden">
              <div style="height:100%;width:${pct}%;background:var(--accent);border-radius:2px"></div>
            </div>
          </div>
          <div style="text-align:right;flex-shrink:0;font-family:'DM Mono',monospace">
            <div style="font-size:.78rem">${data.count} DL</div>
            ${data.bytes ? `<div style="font-size:.65rem;color:var(--muted)">${fmtBytes(data.bytes)}</div>` : ''}
          </div>
        </div>`;
      }).join('');
    }
  }

  // ── Top Kategorien als Tabelle ────────────────────────────────
  const catsEl = document.getElementById('stats-top-cats');
  if (catsEl && d.top_categories) {
    const entries = Object.entries(d.top_categories);
    if (!entries.length) {
      catsEl.innerHTML = `<div style="color:var(--muted)">${t('stats.no_data')}</div>`;
    } else {
      const maxCnt = entries[0]?.[1]?.count ?? 1;
      catsEl.innerHTML = `
        <div style="display:grid;grid-template-columns:1fr auto auto;gap:0;font-family:'DM Mono',monospace;font-size:.72rem">
          <div style="color:var(--muted);padding:6px 0;border-bottom:1px solid var(--border)">${t('stats.category')}</div>
          <div style="color:var(--muted);padding:6px 8px;border-bottom:1px solid var(--border);text-align:right">${t('stats.downloads')}</div>
          <div style="color:var(--muted);padding:6px 0 6px 8px;border-bottom:1px solid var(--border);text-align:right">${t('stats.volume_col')}</div>
          ${entries.map(([cat, data]) => {
            const pct = Math.round((data.count / maxCnt) * 100);
            return `
            <div style="padding:7px 0;border-bottom:1px solid var(--border);overflow:hidden">
              <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(cat)}</div>
              <div style="height:2px;background:var(--bg3);border-radius:1px;margin-top:3px;overflow:hidden">
                <div style="height:100%;width:${pct}%;background:var(--accent2);border-radius:1px"></div>
              </div>
            </div>
            <div style="padding:7px 8px;border-bottom:1px solid var(--border);text-align:right;color:var(--text)">${data.count}</div>
            <div style="padding:7px 0 7px 8px;border-bottom:1px solid var(--border);text-align:right;color:var(--muted)">${fmtBytes(data.bytes)}</div>`;
          }).join('')}
        </div>`;
    }
  }

  // ── Statistiken pro Server ────────────────────────────────────
  const srvStatsEl = document.getElementById('stats-by-server');
  if (srvStatsEl && d.by_server?.length) {
    const maxSrv = d.by_server[0]?.count ?? 1;
    srvStatsEl.innerHTML = `
      <div style="display:grid;grid-template-columns:1fr auto auto auto;gap:0;font-family:'DM Mono',monospace;font-size:.72rem">
        <div style="color:var(--muted);padding:6px 0;border-bottom:1px solid var(--border)">${t('cfg.server')}</div>
        <div style="color:var(--muted);padding:6px 8px;border-bottom:1px solid var(--border);text-align:right">${t('stats.downloads')}</div>
        <div style="color:var(--muted);padding:6px 8px;border-bottom:1px solid var(--border);text-align:right">🎬 / 📺</div>
        <div style="color:var(--muted);padding:6px 0 6px 8px;border-bottom:1px solid var(--border);text-align:right">${t('stats.volume_col')}</div>
        ${d.by_server.map(s => {
          const pct = Math.round((s.count / maxSrv) * 100);
          const nameColor = s.enabled ? 'var(--text)' : 'var(--muted)';
          const nameLabel = s.enabled ? esc(s.name) : `${esc(s.name)} <span style="opacity:.5">(inaktiv)</span>`;
          return `
          <div style="padding:7px 0;border-bottom:1px solid var(--border);overflow:hidden">
            <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:${nameColor}">${nameLabel}</div>
            <div style="height:2px;background:var(--bg3);border-radius:1px;margin-top:3px;overflow:hidden">
              <div style="height:100%;width:${pct}%;background:var(--accent);border-radius:1px"></div>
            </div>
          </div>
          <div style="padding:7px 8px;border-bottom:1px solid var(--border);text-align:right;color:var(--text)">${s.count}</div>
          <div style="padding:7px 8px;border-bottom:1px solid var(--border);text-align:right;color:var(--muted)">${s.movies} / ${s.episodes}</div>
          <div style="padding:7px 0 7px 8px;border-bottom:1px solid var(--border);text-align:right;color:var(--muted)">${fmtBytes(s.bytes)}</div>`;
        }).join('')}
      </div>`;
  } else if (srvStatsEl) {
    srvStatsEl.innerHTML = `<div style="color:var(--muted)">${t('stats.no_data')}</div>`;
  }
}
<?php endif; ?>

<?php endif; ?>

// ── Live Progress Polling ─────────────────────────────────────
let progressInterval = null;

function startProgressPolling() {
  pollProgress();
  refreshQueue();
  if (!progressInterval) progressInterval = setInterval(async () => {
    await pollProgress();
    if (currentView === 'queue') refreshQueue();
  }, 5000);
}
function stopProgressPolling() {
  clearInterval(progressInterval);
  progressInterval = null;
}

async function pollProgress() {
  const p = await api('get_progress');
  applyProgress(p, 'pc-', 'progress-card');
  applyProgress(p, 'dash-pc-', 'dash-progress-card');
  applyTopbarDl(p);
  applyQueueItemProgress(p);
  applyQueueStartButtons(p);
}

function applyQueueStartButtons(p) {
  const running = p.active === true;
  document.querySelectorAll('[onclick*="startQueue"]').forEach(btn => {
    btn.disabled = running;
    if (running) {
      btn.textContent = '⏳ Läuft…';
      btn.title = 'Download läuft bereits';
    } else {
      btn.textContent = btn.dataset.label || '<?= t('queue.start') ?>';
      btn.title = '';
    }
  });
}

function applyQueueItemProgress(p) {
  // Alle qi-progress Divs erst ausblenden
  document.querySelectorAll('.qi-progress').forEach(el => el.style.display = 'none');

  if (!p.active) return;

  // Einzelne oder parallele Downloads
  const downloads = p.parallel > 1 ? (p.downloads ?? []) : [p];

  for (const dl of downloads) {
    if (!dl.stream_id) continue;
    const el = document.getElementById('qip-' + dl.stream_id);
    if (!el) continue;

    el.style.display = '';
    const pct   = dl.percent ?? 0;
    const bar   = el.querySelector('.qi-progress-bar');
    const pctEl = el.querySelector('.qip-pct');
    const doneEl  = el.querySelector('.qip-done');
    const speedEl = el.querySelector('.qip-speed');
    const etaEl   = el.querySelector('.qip-eta');

    if (bar)   bar.style.width   = pct + '%';
    if (pctEl) pctEl.textContent = pct + '%';

    if (dl.mode === 'rclone' && pct === 0 && (dl.bytes_done ?? 0) === 0) {
      if (bar) { bar.style.width = '100%'; bar.style.animation = 'pulse-bar 1.5s ease-in-out infinite'; }
      if (pctEl)  pctEl.textContent  = '☁️…';
      if (doneEl) doneEl.textContent = '';
      if (speedEl) speedEl.textContent = '';
      if (etaEl)  etaEl.textContent  = '';
    } else {
      if (bar) bar.style.animation = '';
      if (doneEl)  doneEl.textContent  = dl.bytes_total > 0 ? fmtBytes(dl.bytes_done ?? 0) + ' / ' + fmtBytes(dl.bytes_total) : fmtBytes(dl.bytes_done ?? 0);
      if (speedEl) speedEl.textContent = dl.speed_bps > 0 ? fmtBytes(dl.speed_bps) + '/s' : '';
      if (etaEl)   etaEl.textContent   = dl.eta_seconds != null ? fmtDuration(dl.eta_seconds) : '';
    }
  }
}

function applyTopbarDl(p) {
  const el    = document.getElementById('topbar-dl');
  if (!el) return;
  const inDashOrQueue = currentView === 'dashboard' || currentView === 'queue';
  if (!p.active || inDashOrQueue) { el.style.display = 'none'; return; }
  el.style.display = 'flex';
  const titleEl = document.getElementById('topbar-dl-title');
  const barEl   = document.getElementById('topbar-dl-bar');
  const pctEl   = document.getElementById('topbar-dl-pct');
  const pct     = p.percent ?? 0;
  if (titleEl) titleEl.textContent = p.title ?? '–';
  if (barEl)   barEl.style.width   = pct + '%';
  if (pctEl)   pctEl.textContent   = pct + '%';
}

function applyProgress(p, prefix, cardId) {
  const card = document.getElementById(cardId);
  if (!card) return;
  if (!p.active) { card.classList.remove('active'); return; }
  card.classList.add('active');
  const set = (id, val) => { const el = document.getElementById(prefix + id); if (el) el.textContent = val; };
  const setW = (id, val) => { const el = document.getElementById(prefix + id); if (el) el.style.width = val; };
  const setA = (id, val) => { const el = document.getElementById(prefix + id); if (el) el.style.animation = val; };

  if (p.parallel > 1) {
    // Mehrere parallele Downloads — aggregierte Anzeige
    set('title', `${p.parallel}× ${t('status.downloading')}`);
    set('pos',   p.downloads?.map(d => d.title ?? '').filter(Boolean).join(' · ').substring(0, 60) || '');
    setA('bar', '');
    setW('bar',  (p.percent ?? 0) + '%');
    set('pct',   (p.percent ?? 0) + '%');
    set('done',  fmtBytes(p.bytes_done  ?? 0));
    set('total', p.bytes_total > 0 ? fmtBytes(p.bytes_total) : '?');
    set('speed', p.speed_bps > 0 ? fmtBytes(p.speed_bps) + '/s' : '–');
    set('eta',   '–');
    return;
  }

  set('title', p.title ?? '–');
  set('pos',   p.queue_total > 1 ? `${p.queue_pos} / ${p.queue_total}` : '');
  if (p.mode === 'rclone' && (p.percent ?? 0) === 0 && (p.bytes_done ?? 0) === 0) {
    setW('bar', '100%');
    setA('bar', 'pulse 1.5s ease-in-out infinite');
    set('pct',   '☁️ Verbinde…');
    set('done',  '–');
    set('total', '–');
    set('speed', '–');
    set('eta',   '–');
  } else {
    setA('bar', '');
    setW('bar',  (p.percent ?? 0) + '%');
    set('pct',   (p.percent ?? 0) + '%');
    set('done',  fmtBytes(p.bytes_done  ?? 0));
    set('total', p.bytes_total > 0 ? fmtBytes(p.bytes_total) : '?');
    set('speed', p.speed_bps > 0 ? fmtBytes(p.speed_bps) + '/s' : '–');
    set('eta',   p.eta_seconds != null ? fmtDuration(p.eta_seconds) : '–');
  }
}

function fmtBytes(b) {
  if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
  if (b >= 1048576)    return (b / 1048576).toFixed(1)    + ' MB';
  if (b >= 1024)       return (b / 1024).toFixed(1)       + ' KB';
  return b + ' B';
}
function fmtDuration(s) {
  if (s < 60)   return s + 's';
  if (s < 3600) return Math.floor(s/60) + 'm ' + (s%60) + 's';
  return Math.floor(s/3600) + 'h ' + Math.floor((s%3600)/60) + 'm';
}

<?php if ($can_settings): ?>
// ── Admin Dashboard Polling ───────────────────────────────────
let dashboardInterval = null;

function startDashboardPolling() {
  pollProgress().then(() => refreshDashboardQueue()).then(() => loadDashboardData());
  if (!dashboardInterval) dashboardInterval = setInterval(async () => {
    await pollProgress();
    await refreshDashboardQueue();
    await loadDashboardData();
  }, 10000);
}
function stopDashboardPolling() {
  clearInterval(dashboardInterval);
  dashboardInterval = null;
}

async function refreshDashboardQueue() {
  const list = document.getElementById('dash-queue-list');
  if (!list) return;
  const items = await api('get_queue');
  if (!items.length) {
    list.innerHTML = `<div class="state-box" style="padding:32px"><div class="icon">📭</div><p>Queue ist leer</p></div>`;
    return;
  }
  const sorted = [...items].sort((a, b) => {
    const order = {downloading:0, pending:1, error:2, done:3};
    return (order[a.status] ?? 9) - (order[b.status] ?? 9);
  }).slice(0, 10);
  // Kein innerHTML-Reset — nur Status-Labels aktualisieren wenn Items schon vorhanden
  const existingIds = new Set([...list.querySelectorAll('.queue-item[id]')].map(el => el.id));
  const needsFull   = sorted.some(item => !existingIds.has(`dqi-${item.stream_id}`));
  if (needsFull || list.querySelector('.state-box')) {
    list.innerHTML = sorted.map(dashQueueItemHTML).join('');
    if (items.length > 10) {
      list.innerHTML += `<div style="text-align:center;padding:12px;font-family:'DM Mono',monospace;font-size:.7rem;color:var(--muted)">
        + ${items.length - 10} weitere — <span style="color:var(--accent);cursor:pointer" onclick="showView('queue')">Alle anzeigen</span>
      </div>`;
    }
  } else {
    // Nur Status aktualisieren
    sorted.forEach(item => {
      const el = document.getElementById(`dqi-${item.stream_id}`);
      if (!el) return;
      const statusLabel = {pending:t('status.pending'), downloading:t('status.downloading'), done:t('status.done'), error:t('status.error')}[item.status] ?? item.status;
      el.className = `queue-item status-${item.status}`;
      const statusEl = el.querySelector('.qi-status');
      if (statusEl) { statusEl.textContent = statusLabel; statusEl.className = `qi-status ${item.status}`; }
    });
  }
}

function dashQueueItemHTML(item) {
  const statusLabel = {pending:t('status.pending'), downloading:t('status.downloading'), done:t('status.done'), error:t('status.error')}[item.status] ?? item.status;
  const thumb = item.cover
    ? `<img class="qi-thumb" src="${item.cover}" alt="" onerror="this.style.display='none'">`
    : `<div class="qi-thumb" style="display:flex;align-items:center;justify-content:center;font-size:1.2rem">🎬</div>`;
  const addedBy = canSeeAddedBy && item.added_by ? `· von ${item.added_by}` : '';
  return `
  <div class="queue-item status-${item.status}" id="dqi-${item.stream_id}">
    ${thumb}
    <div class="qi-info">
      <div class="qi-title">${item.title}</div>
      <div class="qi-meta">${item.type} · ${(item.container_extension ?? '').toUpperCase()} ${addedBy}</div>
      ${item.error ? `<div style="font-size:.7rem;color:var(--red);margin-top:3px">${item.error}</div>` : ''}
    </div>
    <span class="qi-status ${item.status}">${statusLabel}</span>
  </div>`;
}
<?php endif; ?>


function setFilter(f, btn) {
  currentFilter = f;
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderMovies();
}
function setActiveCat(el) {
  document.querySelectorAll('.cat-item').forEach(c => c.classList.remove('active'));
  if (el) el.classList.add('active');
}
function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  if (sidebar.classList.contains('open')) {
    closeSidebar();
  } else {
    sidebar.classList.add('open');
    const overlay = document.getElementById('sidebar-overlay');
    if (overlay) overlay.style.display = 'block';
  }
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  const overlay = document.getElementById('sidebar-overlay');
  if (overlay) overlay.style.display = 'none';
}

// ── API ───────────────────────────────────────────────────────
async function api(action, params = {}) {
  const qs = new URLSearchParams({action, ...params});
  const r  = await fetch(`${API}?${qs}`);
  return r.json();
}
async function apiPost(action, body) {
  const r = await fetch(`${API}?action=${action}`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...body, _csrf: CSRF_TOKEN})
  });
  return r.json();
}

// ── Utils ─────────────────────────────────────────────────────
function loadingHTML() { return `<div class="state-box" style="grid-column:1/-1"><div class="spinner"></div><p>Loading…</p></div>`; }
function emptyHTML(m)  { return `<div class="state-box" style="grid-column:1/-1"><div class="icon">📭</div><p>${m}</p></div>`; }
function esc(s)        { return String(s).replace(/'/g,"&#39;").replace(/"/g,'&quot;'); }
function htmlJson(o)   { return JSON.stringify(o).replace(/"/g,'&quot;'); }
function lazyLoadImages() {
  document.querySelectorAll('[data-src]').forEach(img => {
    img.onload  = () => img.classList.add('loaded');
    img.onerror = () => img.classList.add('loaded');
    img.src = img.dataset.src;
    img.removeAttribute('data-src');
    if (img.complete) img.classList.add('loaded');
  });
  document.querySelectorAll('.card-thumb img:not([data-src]):not(.loaded)').forEach(img => {
    if (img.complete) { img.classList.add('loaded'); return; }
    img.onload  = () => img.classList.add('loaded');
    img.onerror = () => img.classList.add('loaded');
  });
  // View-Mode auf neu gerenderte Grids anwenden
  if (typeof _viewMode !== 'undefined') applyViewMode(_viewMode);
}
let toastTimer;
let _dupPendingItem = null;
function showDuplicateToast(matchTitle, item, card) {
  _dupPendingItem = {item, card: card || null};
  document.getElementById('dup-match-title').textContent = matchTitle;
  document.getElementById('dup-toast').style.display = '';
}
function closeDupToast() {
  document.getElementById('dup-toast').style.display = 'none';
  _dupPendingItem = null;
}
async function forceQueueAdd() {
  const pending = _dupPendingItem;
  closeDupToast();
  if (!pending) return;
  const {item, card} = pending;
  const result = await queueItem({...item, force_add: true});
  if (!result) return;
  // Karte aktualisieren — gleicher Code wie in addMovieToQueue
  const c = card || document.getElementById('card-m-' + item.stream_id);
  if (c) {
    c.classList.add('queued');
    const badge = c.querySelector('.card-badge');
    if (badge) { badge.className = 'card-badge badge-queue'; badge.textContent = '⏳ Queue'; }
    const btn = c.querySelector('.btn-q');
    if (btn) {
      if (canQueueRemove) {
        btn.textContent = t('btn.remove_queue');
        btn.className   = 'btn-q remove';
        btn.disabled    = false;
        btn.removeAttribute('onclick');
        btn.onclick = () => removeFromQueue(item.stream_id, c);
      } else {
        btn.textContent = t('btn.queued');
        btn.className   = 'btn-q done';
        btn.disabled    = true;
        btn.onclick     = null;
      }
    }
  }
  const idx = allMovies.findIndex(x => String(x.stream_id) === String(item.stream_id));
  if (idx >= 0) allMovies[idx].queued = true;
  const idx2 = _lastMovies.findIndex(x => String(x.stream_id) === String(item.stream_id));
  if (idx2 >= 0) _lastMovies[idx2].queued = true;
}

function showToast(msg, type = '') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = `toast ${type} show`;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 3500);
}

// ── Library (viewer/editor dashboard) ────────────────────────
<?php if (!$can_settings): ?>
// ── User Dashboard (Editor/Viewer) ──────────────────────────

async function loadUserDashboard() {
  // KPI: Stats
  const s = await api('stats');
  if (s && !s.error) {
    const uel = document.getElementById('ue-total-dl');
    if (uel) uel.textContent = (s.total_downloaded ?? 0).toLocaleString();
    const ueP = document.getElementById('ue-pending');
    const ueD = document.getElementById('ue-downloading');
    if (ueP) ueP.textContent = s.queue_stats?.pending     ?? 0;
    if (ueD) ueD.textContent = s.queue_stats?.downloading ?? 0;
  }

  // Neue Releases (max 12 horizontal)
  const nr = await api('get_new_releases');
  const nrEl = document.getElementById('ue-new-releases');
  if (nrEl) {
    const movies = (nr?.movies ?? []).map(m => ({...m, _type: 'movie'}));
    const series = (nr?.series ?? []).map(s => ({...s, _type: 'series'}));
    const items  = [...movies, ...series].slice(0, 12);
    if (!items.length) {
      nrEl.innerHTML = `<div style="color:var(--muted);font-size:.8rem;padding:8px">${t('dash.no_new')}</div>`;
    } else {
      nrEl.innerHTML = items.map(item => {
        const title  = esc(item.clean_title ?? item.name ?? item.title ?? '');
        const cover  = esc(item.stream_icon ?? item.cover ?? '');
        const isSeries = item._type === 'series';
        const sid    = String(item.series_id ?? item.stream_id ?? '');
        const ext    = item.container_extension ?? 'mp4';
        const srvId  = item._server_id ?? '';
        const cat    = esc(item.category ?? '');
        // queueData für TMDB-Modal
        const qd = !isSeries ? JSON.stringify({
          stream_id: sid, type: 'movie', clean_title: item.clean_title ?? item.title ?? '',
          cover: item.stream_icon ?? item.cover ?? '',
          category: item.category ?? '', container_extension: ext,
          _server_id: srvId,
        }).replace(/"/g, '&quot;') : 'null';
        const onClick = isSeries
          ? `openSeriesModal('${sid}','${title}','${cover}','${cat}','${esc(srvId)}')`
          : `openTmdbModal('${title}','movie','',${qd})`;
        return `
        <div style="flex-shrink:0;width:100px;cursor:pointer" onclick="${onClick}">
          <div style="width:100px;height:148px;border-radius:6px;overflow:hidden;background:var(--bg3);border:1px solid var(--border);position:relative">
            ${cover ? `<img src="${cover}" alt="" style="width:100%;height:100%;object-fit:cover">` : `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:1.8rem">${isSeries?'📺':'🎬'}</div>`}
            ${item.downloaded ? `<span class="card-badge badge-done" style="top:4px;right:4px;font-size:.55rem">✓</span>` : ''}
          </div>
          <div style="font-size:.72rem;margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text)">${title}</div>
        </div>`;
      }).join('');
    }
  }

  // Letzte Downloads (aus download_history — eigene Downloads wenn editor, alle wenn admin)
  const h = await api('get_recent_downloads');
  const recentEl = document.getElementById('ue-recent');
  if (recentEl) {
    if (!h?.items?.length) {
      recentEl.innerHTML = `<div class="state-box" style="grid-column:1/-1"><div class="icon">📭</div><p>${t('dash.no_recent')}</p></div>`;
    } else {
      recentEl.innerHTML = h.items.map(item => `
        <div class="card downloaded">
          <div class="card-thumb">
            <div class="card-thumb-placeholder">${item.type==='episode'?'📺':'🎬'}</div>
            ${item.cover ? `<img src="${esc(item.cover)}" alt="" class="loaded" style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0">` : ''}
            <span class="card-badge badge-done">✓</span>
          </div>
          <div class="card-body">
            <div class="card-title">${esc(item.title)}</div>
            <div class="card-meta">${esc(item.category??'')}${item.done_at ? ' · ' + item.done_at.slice(0,10) : ''}</div>
          </div>
        </div>`).join('');
    }
  }
}
<?php endif; ?>

// ── Auth JS ─────────────────────────────────────────────────────
async function doLogout() {
  // Theme in generischen Key kopieren damit die Loginseite ihn lesen kann
  try {
    const t = localStorage.getItem(THEME_KEY);
    if (t) localStorage.setItem('xv_theme', t);
  } catch(e) {}
  await api('logout');
  window.location.href = 'login.php';
}

function toggleTopbarMenu() {
  const menu = document.getElementById('topbar-menu');
  if (!menu) return;
  const open = menu.classList.toggle('open');
  if (open) {
    // Schließen wenn außerhalb geklickt
    setTimeout(() => document.addEventListener('click', closeTopbarMenuOutside), 0);
  }
}
function closeTopbarMenu() {
  document.getElementById('topbar-menu')?.classList.remove('open');
  document.removeEventListener('click', closeTopbarMenuOutside);
}
function closeTopbarMenuOutside(e) {
  if (!document.getElementById('topbar-overflow')?.contains(e.target)) closeTopbarMenu();
}

function toggleUserDropdown() {
  const chip = document.getElementById('user-chip');
  chip.classList.toggle('open');
}
// Close dropdown when clicking outside
document.addEventListener('click', e => {
  const chip = document.getElementById('user-chip');
  if (chip && !chip.contains(e.target)) chip.classList.remove('open');
});

// ── User Management ───────────────────────────────────────────
async function loadUsers() {
  const tbody = document.getElementById('users-tbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted)"><div class="spinner" style="margin:auto"></div></td></tr>';
  const users = await api('list_users');

  // Populate activity log user filter
  const sel = document.getElementById('actlog-user-filter');
  if (sel) {
    sel.innerHTML = '<option value="">Alle Benutzer</option>' +
      users.map(u => `<option value="${esc(u.id)}">${esc(u.username)}</option>`).join('');
  }

  if (!users.length) {
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted)">${t('users.none_found')}</td></tr>`;
    return;
  }
  tbody.innerHTML = users.map(u => {
    const isSuspended = u.suspended ?? false;
    const statusBadge = isSuspended
      ? `<span class="role-badge badge-inactive">${t('users.status_suspended')}</span>`
      : `<span class="role-badge badge-active">${t('users.status_active')}</span>`;
    const suspendBtn = isSuspended
      ? `<button class="btn-icon" onclick="toggleSuspend('${esc(u.id)}',false,'${esc(u.username)}')">✅ ${t('users.unsuspend')}</button>`
      : `<button class="btn-icon danger" onclick="toggleSuspend('${esc(u.id)}',true,'${esc(u.username)}')">🚫 ${t('users.suspend')}</button>`;
    const limitVal = u.queue_limit !== undefined ? u.queue_limit : '';
    const limitDisplay = limitVal === '' ? `<span style="color:var(--muted)">${t('sort.default')}</span>`
      : limitVal == 0 ? `<span style="color:var(--red)">${t('users.status_suspended')}</span>`
      : `<span style="color:var(--orange)">${limitVal}/h</span>`;
    return `
    <tr style="${isSuspended ? 'opacity:.6' : ''}">
      <td>
        <strong style="cursor:pointer" onclick="openUserHistoryModal('${esc(u.username)}')" title="Download-Verlauf anzeigen">${esc(u.username)}</strong>
        <div style="font-size:.65rem;color:var(--muted);font-family:'DM Mono',monospace;cursor:pointer" onclick="viewUserActivity('${esc(u.id)}')">📋 Log</div>
      </td>
      <td><span class="role-badge ${u.role}">${u.role}</span></td>
      <td>${statusBadge}</td>
      <td style="color:var(--muted);font-family:'DM Mono',monospace;font-size:.72rem">${u.created_at ?? '–'}</td>
      <td>
        <div style="display:flex;align-items:center;gap:6px">
          ${limitDisplay}
          <button class="btn-icon" onclick="setUserLimit('${esc(u.id)}','${esc(u.username)}','${limitVal}')" title="Limit ändern">✏️</button>
        </div>
      </td>
      <td>
        <div class="user-actions">
          <button class="btn-icon" onclick="openEditUser('${esc(u.id)}','${esc(u.username)}','${u.role}')">✏️ ${t('btn.edit')}</button>
          <button class="btn-icon" onclick="openPwResetModal('${esc(u.id)}','${esc(u.username)}')">🔑 ${t('cfg.password')}</button>
          ${suspendBtn}
          <button class="btn-icon danger" onclick="deleteUser('${esc(u.id)}','${esc(u.username)}')">✕ ${t('btn.delete')}</button>
        </div>
      </td>
    </tr>`}).join('');
}

async function setUserLimit(id, username, current) {
  const input = prompt(
    `${t('users.limit_prompt')} "${username}":\n${t('users.limit_hint')}`,
    current
  );
  if (input === null) return;
  const d = await apiPost('set_user_limit', {id, queue_limit: input.trim()});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast(t('users.limit_updated'), 'success');
  loadUsers();
}

async function toggleSuspend(id, suspend, username) {
  const action = suspend ? t('users.suspend_confirm', {name: username}) : t('users.unsuspend_confirm', {name: username});
  if (!await showConfirm(action, {title:'Bestätigen?', icon:'🎭', okLabel:'Bestätigen', danger:true})) return;
  const r = await fetch(`${API}?action=suspend_user`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...({id, suspended: suspend}), _csrf: CSRF_TOKEN})
  });
  const d = await r.json();
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast(suspend ? t('users.suspended') : t('users.unsuspended'), 'success');
  loadUsers();
}

function viewUserActivity(userId) {
  const sel = document.getElementById('actlog-user-filter');
  if (sel) sel.value = userId;
  showView('activity-log');
}

async function loadActivityLog() {
  const list   = document.getElementById('activity-log-list');
  const userId = document.getElementById('actlog-user-filter')?.value ?? '';
  if (!list) return;
  list.innerHTML = `<div class="state-box"><div class="spinner"></div></div>`;
  const params = userId ? {user_id: userId, limit: 100} : {limit: 100};
  const entries = await api('get_activity_log', params);
  if (!entries.length) { list.innerHTML = `<div class="state-box"><div class="icon">📋</div><p>Keine Einträge</p></div>`; return; }

  const icons = {
    queue_add: '➕', queue_add_bulk: '➕', queue_remove: '➖',
    create_user: '👤', delete_user: '🗑', suspend_user: '🚫', unsuspend_user: '✅',
    reset_password: '🔑', change_role: '🎭', change_own_password: '🔑',
    save_config: '⚙️', clear_cron_log: '🧹', rebuild_cache: '🔄',
    mark_downloaded: '✓', reset_download: '↺',
    vpn_connect: '🔒', vpn_disconnect: '🔓',
    queue_start: '▶', queue_cancel: '✕',
    maintenance_enable: '🔒', maintenance_disable: '🔓',
    run_update: '⬆️', backup_run: '💾', backup_restore: '↩',
    create_invite: '🔗', reveal_api_key: '🔑',
  };
  const labels = {
    queue_add: t('actlog.queue_add'), queue_add_bulk: t('actlog.queue_add_bulk'), queue_remove: t('queue.removed'),
    create_user: t('users.created'), delete_user: t('actlog.delete_user'),
    suspend_user: t('users.suspended'), unsuspend_user: t('users.unsuspended'),
    reset_password: t('actlog.reset_password'), change_role: t('actlog.change_role'), change_own_password: t('actlog.change_own_password'),
    save_config: t('actlog.save_config'), clear_cron_log: t('actlog.clear_cron_log'), rebuild_cache: t('actlog.rebuild_cache'),
    mark_downloaded: t('actlog.mark_downloaded'), reset_download: t('actlog.reset_download'),
    vpn_connect: t('actlog.vpn_connect'), vpn_disconnect: t('actlog.vpn_disconnect'),
    queue_start: t('actlog.queue_start'), queue_cancel: t('actlog.queue_cancel'),
    maintenance_enable: t('actlog.maintenance_enable'), maintenance_disable: t('actlog.maintenance_disable'),
    run_update: t('update.done'), backup_run: t('backup.ok'), backup_restore: t('backup.restored'),
    create_invite: t('invite.created'), reveal_api_key: t('apikey.copied'),
  };

  list.innerHTML = `<div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:16px 20px">` +
    entries.map(e => {
      const icon  = icons[e.action]  ?? '📌';
      const label = labels[e.action] ?? e.action;
      const meta  = Object.entries(e.meta ?? {}).map(([k,v]) => `${k}: ${v}`).join(' · ');
      return `
      <div class="actlog-item">
        <div class="actlog-icon">${icon}</div>
        <div class="actlog-info">
          <div class="actlog-action"><strong>${esc(e.username)}</strong> – ${label}</div>
          <div class="actlog-meta">${e.ts}${meta ? ' · ' + esc(meta) : ''}</div>
        </div>
      </div>`;
    }).join('') + `</div>`;
}

// ── Multi-Select for Queue ────────────────────────────────────
<?php if ($can_queue_add): ?>
let selectedItems = new Map(); // stream_id → item data

function toggleSelectItem(streamId, itemData, cardEl) {
  if (selectedItems.has(streamId)) {
    selectedItems.delete(streamId);
    cardEl.classList.remove('selected');
  } else {
    selectedItems.set(streamId, itemData);
    cardEl.classList.add('selected');
  }
  updateMultiselectToolbar();
}

function updateMultiselectToolbar() {
  const toolbar = document.getElementById('multiselect-toolbar');
  const countEl = document.getElementById('multiselect-count');
  if (!toolbar) return;
  const n = selectedItems.size;
  toolbar.style.display = n > 0 ? 'flex' : 'none';
  if (countEl) countEl.textContent = `${n} ausgewählt`;
}

function clearSelection() {
  selectedItems.clear();
  document.querySelectorAll('.card.selected').forEach(c => c.classList.remove('selected'));
  updateMultiselectToolbar();
}

async function addSelectionToQueue() {
  if (!selectedItems.size) return;
  const items = Array.from(selectedItems.values()).map(m => ({
    stream_id:           m.stream_id,
    type:                'movie',
    title:               m.clean_title,
    container_extension: m.container_extension ?? 'mp4',
    cover:               m.stream_icon ?? '',
    dest_subfolder:      'Movies',
    category:            m.category ?? '',
  }));

  const r = await fetch(`${API}?action=queue_add_bulk`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({items, _csrf: CSRF_TOKEN})
  });
  const d = await r.json();
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  const msg = `${d.added} zur Queue hinzugefügt${d.skipped ? ', ' + d.skipped + ' bereits vorhanden' : ''}${d.limited ? ' (Limit erreicht)' : ''}`;
  showToast(msg, d.limited ? 'info' : 'success');
  if (d.remaining !== null) updateLimitIndicator(d.remaining);
  clearSelection();
  updateQueueBadge(); loadStats();
  // Mark queued in allMovies
  for (const [sid] of selectedItems) {
    const idx = allMovies.findIndex(x => String(x.stream_id) === String(sid));
    if (idx >= 0) allMovies[idx].queued = true;
  }
}
<?php endif; ?>

let umodalMode = 'create';

function openCreateUser() {
  umodalMode = 'create';
  document.getElementById('umodal-title').textContent    = t('users.create');
  document.getElementById('umodal-id').value             = '';
  document.getElementById('umodal-username').value       = '';
  document.getElementById('umodal-password').value       = '';
  document.getElementById('umodal-role').value           = 'viewer';
  document.getElementById('umodal-username-wrap').style.display = '';
  document.getElementById('umodal-pw-hint').textContent  = '';
  document.getElementById('umodal-submit').textContent   = t('users.create');
  document.getElementById('umodal-msg').className        = 'settings-msg';
  document.getElementById('umodal').classList.add('open');
  setTimeout(() => document.getElementById('umodal-username').focus(), 50);
}

function openEditUser(id, username, role) {
  umodalMode = 'edit';
  document.getElementById('umodal-title').textContent    = `${t('users.edit')}: ${username}`;
  document.getElementById('umodal-id').value             = id;
  document.getElementById('umodal-username').value       = username;
  document.getElementById('umodal-password').value       = '';
  document.getElementById('umodal-role').value           = role;
  document.getElementById('umodal-username-wrap').style.display = 'none';
  document.getElementById('umodal-pw-hint').textContent  = t('users.pw_unchanged');
  document.getElementById('umodal-submit').textContent   = t('btn.save');
  document.getElementById('umodal-msg').className        = 'settings-msg';
  document.getElementById('umodal').classList.add('open');
}

function closeUModal() {
  document.getElementById('umodal').classList.remove('open');
}

async function submitUModal() {
  const btn  = document.getElementById('umodal-submit');
  const msg  = document.getElementById('umodal-msg');
  btn.disabled = true;
  msg.className = 'settings-msg';

  if (umodalMode === 'create') {
    const r = await fetch(`${API}?action=create_user`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({...({
        username: document.getElementById('umodal-username').value.trim(),
        password: document.getElementById('umodal-password').value,
        role:     document.getElementById('umodal-role').value,
      }), _csrf: CSRF_TOKEN})
    });
    const d = await r.json();
    btn.disabled = false;
    if (d.error) { msg.textContent = '❌ ' + d.error; msg.className = 'settings-msg err'; return; }
    showToast(t('users.created'), 'success');
    closeUModal(); loadUsers();
  } else {
    const id = document.getElementById('umodal-id').value;
    const r = await fetch(`${API}?action=update_user`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({...({
        id,
        password: document.getElementById('umodal-password').value || undefined,
        role:     document.getElementById('umodal-role').value,
      }), _csrf: CSRF_TOKEN})
    });
    const d = await r.json();
    btn.disabled = false;
    if (d.error) { msg.textContent = '❌ ' + d.error; msg.className = 'settings-msg err'; return; }
    showToast(t('users.saved'), 'success');
    closeUModal(); loadUsers();
  }
}

async function deleteUser(id, username) {
  if (!await showConfirm(`Benutzer "${username}" wird endgültig gelöscht.`, {title:'Benutzer löschen?', icon:'🗑', okLabel:'Löschen', danger:true})) return;
  const r = await fetch(`${API}?action=delete_user`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...({id}), _csrf: CSRF_TOKEN})
  });
  const d = await r.json();
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast(`"${username}" gelöscht`, 'success');
  loadUsers();
}

// ── Passwort-Reset durch Admin ────────────────────────────────
<?php if ($can_users): ?>
let _pwResetUserId = null;

function openPwResetModal(id, username) {
  _pwResetUserId = id;
  document.getElementById('pw-reset-username').textContent = username;
  document.getElementById('pw-reset-new').value     = '';
  document.getElementById('pw-reset-confirm').value = '';
  document.getElementById('pw-reset-msg').textContent = '';
  document.getElementById('pw-reset-modal').style.display = 'flex';
  setTimeout(() => document.getElementById('pw-reset-new').focus(), 50);
}

function closePwResetModal() {
  document.getElementById('pw-reset-modal').style.display = 'none';
  _pwResetUserId = null;
}

async function submitPwReset() {
  const pw  = document.getElementById('pw-reset-new').value;
  const pw2 = document.getElementById('pw-reset-confirm').value;
  const msg = document.getElementById('pw-reset-msg');
  if (pw.length < 6) { msg.textContent = '❌ Mindestens 6 Zeichen'; msg.className = 'settings-msg err'; return; }
  if (pw !== pw2)    { msg.textContent = '❌ Passwörter stimmen nicht überein'; msg.className = 'settings-msg err'; return; }
  const d = await apiPost('update_user', {id: _pwResetUserId, password: pw});
  if (d.error) { msg.textContent = '❌ ' + d.error; msg.className = 'settings-msg err'; return; }
  showToast('✅ Passwort zurückgesetzt', 'success');
  closePwResetModal();
}

// ── Per-User Download-Verlauf ─────────────────────────────────
async function openUserHistoryModal(username) {
  document.getElementById('user-history-modal').style.display = 'flex';
  document.getElementById('user-history-meta').textContent = username;
  document.getElementById('user-history-list').innerHTML =
    `<div style="color:var(--muted);text-align:center;padding:24px">${t('status.loading')}</div>`;

  const d = await api('user_download_history', {username});
  if (d.error) {
    document.getElementById('user-history-list').innerHTML =
      `<div style="color:var(--red);text-align:center;padding:16px">${esc(d.error)}</div>`;
    return;
  }

  document.getElementById('user-history-meta').textContent =
    `${username} · ${d.count} Downloads · ${fmtBytes(d.total_bytes ?? 0)}`;

  if (!d.items?.length) {
    document.getElementById('user-history-list').innerHTML =
      `<div style="color:var(--muted);text-align:center;padding:24px">${t('dash.no_recent')}</div>`;
    return;
  }

  document.getElementById('user-history-list').innerHTML = d.items.map(h => `
    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border)">
      ${h.cover ? `<img src="${esc(h.cover)}" alt="" style="width:36px;height:52px;object-fit:cover;border-radius:4px;flex-shrink:0">` :
        `<div style="width:36px;height:52px;background:var(--bg3);border-radius:4px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1rem">${h.type==='series'?'📺':'🎬'}</div>`}
      <div style="flex:1;min-width:0">
        <div style="font-size:.85rem;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(h.title)}</div>
        <div style="font-family:'DM Mono',monospace;font-size:.63rem;color:var(--muted);margin-top:2px">
          ${esc(h.category ?? '')} · ${h.done_at ?? ''}${h.bytes ? ' · ' + fmtBytes(h.bytes) : ''}
        </div>
      </div>
      <span style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);flex-shrink:0">${h.type === 'episode' ? '📺' : '🎬'}</span>
    </div>`).join('');
}

function closeUserHistoryModal() {
  document.getElementById('user-history-modal').style.display = 'none';
}
<?php endif; ?>
<?php if ($can_users): ?>
function openInviteModal() {
  document.getElementById('invite-modal').style.display = 'flex';
  document.getElementById('invite-result').style.display = 'none';
  document.getElementById('invite-msg').textContent = '';
  document.getElementById('invite-note').value = '';
}
function closeInviteModal() {
  document.getElementById('invite-modal').style.display = 'none';
}

async function createInvite() {
  const btn  = document.getElementById('btn-create-invite');
  const msg  = document.getElementById('invite-msg');
  btn.disabled = true;
  const d = await apiPost('create_invite', {
    role:         document.getElementById('invite-role').value,
    expires_hours: parseInt(document.getElementById('invite-hours').value),
    note:         document.getElementById('invite-note').value.trim(),
  });
  btn.disabled = false;
  if (d.error) { msg.textContent = '❌ ' + d.error; msg.className = 'settings-msg err'; return; }
  const link = `${location.origin}${location.pathname.replace('index.php','').replace(/\/$/, '')}/invite.php?token=${d.token}`;
  document.getElementById('invite-link-output').value = link;
  document.getElementById('invite-result').style.display = '';
  msg.textContent = '✅ Link erstellt — jetzt kopieren!';
  msg.className = 'settings-msg ok';
  loadInvites();
}

function copyInviteLink() {
  const input = document.getElementById('invite-link-output');
  input.select();
  navigator.clipboard?.writeText(input.value);
  showToast('📋 Link kopiert', 'success');
}

async function loadInvites() {
  const el = document.getElementById('invite-list');
  if (!el) return;
  const invites = await api('list_invites');
  if (!invites?.length) {
    el.innerHTML = `<div style="color:var(--muted);font-size:.8rem">${t('cfg.invite_none')}</div>`;
    return;
  }
  const roleColors = {viewer:'var(--muted)', editor:'var(--accent2)', admin:'var(--red)'};
  el.innerHTML = invites.map(inv => `
    <div style="display:flex;align-items:center;gap:10px;background:var(--bg2);border:1px solid ${inv.expired||inv.used ? 'var(--border)' : 'rgba(100,210,255,.2)'};border-radius:6px;padding:10px 14px;margin-bottom:6px;opacity:${inv.expired||inv.used ? '.5' : '1'}">
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span style="font-family:'DM Mono',monospace;font-size:.68rem;color:${roleColors[inv.role]??'var(--muted)'}">${esc(inv.role.toUpperCase())}</span>
          ${inv.used ? `<span style="font-size:.68rem;color:var(--green)">✓ Verwendet von ${esc(inv.used_by)}</span>` : inv.expired ? `<span style="font-size:.68rem;color:var(--red)">Abgelaufen</span>` : `<span style="font-size:.68rem;color:var(--accent)">Aktiv</span>`}
          ${inv.note ? `<span style="font-size:.72rem;color:var(--muted)">${esc(inv.note)}</span>` : ''}
        </div>
        <div style="font-family:'DM Mono',monospace;font-size:.63rem;color:var(--muted);margin-top:3px">
          Von ${esc(inv.created_by)} · ${inv.used ? 'Verwendet ' + esc(inv.used_at ?? '') : 'Läuft ab ' + esc(inv.expires_at)}
        </div>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0">
        ${!inv.used && !inv.expired ? `<button class="btn-icon" onclick="copyTokenLink('${esc(inv.token)}')">📋</button>` : ''}
        <button class="btn-icon danger" onclick="deleteInvite('${esc(inv.token)}',this)">✕</button>
      </div>
    </div>`).join('');
}

function copyTokenLink(token) {
  const link = `${location.origin}${location.pathname.replace('index.php','').replace(/\/$/, '')}/invite.php?token=${token}`;
  navigator.clipboard?.writeText(link);
  showToast('📋 Link kopiert', 'success');
}

async function deleteInvite(token, btn) {
  btn.disabled = true;
  const d = await apiPost('delete_invite', {token});
  if (d.error) { showToast('❌ ' + d.error, 'error'); btn.disabled = false; return; }
  showToast(t('invite.deleted'), 'info');
  loadInvites();
}
<?php endif; ?>

// ── Profile / Change own password ─────────────────────────────
async function setLanguage(lang, btn) {
  const msg = document.getElementById('lang-msg');
  const d = await apiPost('set_language', {lang});
  if (d.error) { if (msg) { msg.textContent = '❌ ' + d.error; msg.className = 'settings-msg err'; } return; }
  // Buttons aktualisieren
  document.querySelectorAll('.lang-btn').forEach(b => {
    b.style.borderColor = b.dataset.lang === lang ? 'var(--accent)' : '';
    b.style.color = b.dataset.lang === lang ? 'var(--accent)' : '';
  });
  if (msg) { msg.textContent = '✓'; msg.className = 'settings-msg ok'; }
  // Seite neu laden damit neue Sprache wirksam wird
  setTimeout(() => location.reload(), 600);
}

async function changeOwnPassword() {
  const oldPw  = document.getElementById('prof-old-pw').value;
  const newPw  = document.getElementById('prof-new-pw').value;
  const newPw2 = document.getElementById('prof-new-pw2').value;
  const msg    = document.getElementById('profile-msg');

  if (newPw !== newPw2) {
    msg.textContent = '❌ Passwörter stimmen nicht überein';
    msg.className   = 'settings-msg err';
    return;
  }
  const r = await fetch(`${API}?action=change_own_password`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({...({old_password: oldPw, new_password: newPw}), _csrf: CSRF_TOKEN})
  });
  const d = await r.json();
  if (d.error) {
    msg.textContent = '❌ ' + d.error;
    msg.className   = 'settings-msg err';
  } else {
    msg.textContent = '✅ Passwort geändert';
    msg.className   = 'settings-msg ok';
    document.getElementById('prof-old-pw').value  = '';
    document.getElementById('prof-new-pw').value  = '';
    document.getElementById('prof-new-pw2').value = '';
  }
}
</script>
</body>
</html>
