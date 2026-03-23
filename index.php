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
<title>XTREAM VAULT</title>
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
    <div class="logo-text">Xtream Vault</div>
    <div class="logo-sub">VOD Downloader</div>
  </div>
  <div class="sidebar-stats">
    <div class="stat-box"><div class="stat-num" id="stat-movies">–</div><div class="stat-label">Movies</div></div>
    <div class="stat-box"><div class="stat-num" id="stat-episodes">–</div><div class="stat-label">Episodes</div></div>
    <?php if ($can_queue_view): ?>
    <div class="stat-box queue-stat"><div class="stat-num" id="stat-queued">0</div><div class="stat-label">Queued</div></div>
    <?php endif; ?>
  </div>
  <nav class="nav">
    <div class="nav-section-title">Navigate</div>
    <div class="nav-item active" data-view="dashboard" onclick="showView('dashboard')"><span class="nav-icon">⬛</span> Dashboard</div>
    <?php if ($show_movies): ?>
    <div class="nav-item" data-view="movies" onclick="toggleCats('movies')"><span class="nav-icon">🎬</span> Movies</div>
    <div class="category-list" id="cats-movies"></div>
    <?php endif; ?>
    <?php if ($show_series): ?>
    <div class="nav-item" data-view="series" onclick="toggleCats('series')"><span class="nav-icon">📺</span> Series</div>
    <div class="category-list" id="cats-series"></div>
    <?php endif; ?>
    <div class="nav-section-title" style="margin-top:8px">Tools</div>
    <div class="nav-item" onclick="showView('favourites')"><span class="nav-icon">♥</span> Favoriten <span class="nav-badge" id="fav-badge" style="display:none">0</span></div>
    <div class="nav-item" onclick="showView('new-releases')"><span class="nav-icon">🆕</span> Neu <span class="nav-badge" id="new-releases-badge" style="display:none">0</span></div>
    <div class="nav-item" onclick="showView('search')"><span class="nav-icon">🔍</span> Suche</div>
    <?php if ($can_queue_view): ?>
    <div class="nav-item queue-nav" onclick="showView('queue')">
      <span class="nav-icon">📋</span> Download Queue
      <span class="nav-badge" id="nav-badge">0</span>
    </div>
    <?php endif; ?>
    <?php if ($can_cron_log): ?>
    <div class="nav-item" onclick="showView('log')"><span class="nav-icon">🖥</span> Cron Log</div>
    <?php endif; ?>
    <?php if ($can_settings): ?>
    <div class="nav-item" onclick="showView('api-docs')"><span class="nav-icon">📖</span> API-Dokumentation</div>
    <?php endif; ?>
    <?php if ($can_settings): ?>
    <div class="nav-item" onclick="showView('stats')"><span class="nav-icon">📊</span> Statistiken</div>
    <?php endif; ?>
    <?php if ($can_users): ?>
    <div class="nav-item" onclick="showView('users')"><span class="nav-icon">👥</span> Benutzer</div>
    <?php endif; ?>
    <?php if ($can_settings): ?>
    <div class="nav-item" onclick="showView('settings')" style="margin-top:auto;border-top:1px solid var(--border)"><span class="nav-icon">⚙️</span> Einstellungen</div>
    <?php endif; ?>
  </nav>
</aside>

<!-- ── Main ─────────────────────────────────────────────────── -->
<div class="main">
  <header class="topbar">
    <div class="hamburger" id="hamburger" onclick="toggleSidebar()"><span></span><span></span><span></span></div>
    <div class="page-title" id="page-title">Dashboard</div>
    <div class="search-wrap" id="search-bar" style="display:none">
      <input type="text" id="search-input" placeholder="Film suchen…">
      <span class="search-icon">🔍</span>
    </div>
    <div class="filter-bar" id="filter-bar" style="display:none">
      <button class="filter-btn active" onclick="setFilter('all',this)">All</button>
      <button class="filter-btn" onclick="setFilter('new',this)">New</button>
      <button class="filter-btn" onclick="setFilter('queued',this)">Queued</button>
      <button class="filter-btn" onclick="setFilter('done',this)">Downloaded</button>
    </div>
    <?php if ($can_queue_view): ?>
    <span class="queue-pill" id="queue-pill" onclick="showView('queue')">📋 <span id="pill-count">0</span> in Queue</span>
    <?php endif; ?>
    <?php if ($can_settings): ?>
    <span id="vpn-badge" title="VPN-Status — klicken für Einstellungen" onclick="showView('settings')"
      style="display:none;font-family:'DM Mono',monospace;font-size:.65rem;font-weight:600;
             padding:3px 10px;border-radius:20px;cursor:pointer;letter-spacing:.04em;
             border:1px solid transparent;transition:background .3s,color .3s,border-color .3s"></span>
    <?php endif; ?>
    <!-- Desktop: direkte Buttons -->
    <button id="theme-toggle" class="topbar-icon-btn topbar-desktop-only" onclick="showView('profile')" title="Theme wechseln" aria-label="Theme wechseln">🎨</button>
    <?php if ($role === 'editor'): ?>
    <span id="limit-indicator" class="topbar-desktop-only" style="display:none;font-family:'DM Mono',monospace;font-size:.65rem;padding:4px 10px;border-radius:4px;background:var(--bg3);border:1px solid var(--border)"></span>
    <?php endif; ?>
    <!-- Mobile: ⋯ Overflow-Menü -->
    <div class="topbar-overflow" id="topbar-overflow">
      <button class="topbar-icon-btn" onclick="toggleTopbarMenu()" aria-label="Menü">⋯</button>
      <div class="topbar-menu" id="topbar-menu">
        <button onclick="showView('profile');closeTopbarMenu()">🎨 Theme wechseln</button>
        <?php if ($can_settings): ?>
        <button onclick="showView('settings');closeTopbarMenu()">⚙️ Einstellungen</button>
        <?php endif; ?>
        <?php if ($can_queue_view): ?>
        <button onclick="showView('queue');closeTopbarMenu()">📋 Queue</button>
        <?php endif; ?>
        <?php if ($role === 'editor'): ?>
        <div id="limit-indicator-mobile" style="display:none;padding:8px 16px;font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted)"></div>
        <?php endif; ?>
      </div>
    </div>
    <!-- User Chip -->
    <div class="user-chip" id="user-chip" onclick="toggleUserDropdown()">
      <div class="user-chip-avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
      <div>
        <div class="user-chip-name"><?= htmlspecialchars($user['username']) ?></div>
        <div class="user-chip-role"><?= $role ?></div>
      </div>
      <div class="user-dropdown" id="user-dropdown">
        <button onclick="event.stopPropagation();showView('profile');toggleUserDropdown()">👤 Mein Profil</button>
        <?php if ($can_users): ?>
        <button onclick="event.stopPropagation();showView('users');toggleUserDropdown()">👥 Benutzer</button>
        <?php endif; ?>
        <div class="sep"></div>
        <button class="danger" onclick="doLogout()">⏻ Abmelden</button>
      </div>
    </div>
  </header>

  <div class="content" id="content">
    <!-- Dashboard -->
    <div id="view-dashboard">
      <?php if ($can_settings): ?>
      <!-- Admin Dashboard -->
      <div id="unconfigured-banner" class="unconfigured-banner" style="display:none" onclick="showView('settings')">
        <span class="ub-icon">⚠️</span>
        <div><strong>Nicht konfiguriert</strong> — Bitte zuerst Server-Zugangsdaten in den Einstellungen hinterlegen.</div>
      </div>

      <!-- Zeile 1: Verbindung + Downloads gesamt -->
      <div id="dash-stat-grid" class="dash-kpi-grid">
        <div class="dkpi"><div class="dkpi-l">Server</div><div class="dkpi-v" id="dash-server">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Account</div><div class="dkpi-v" id="dash-user">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Destination</div><div class="dkpi-v" style="word-break:break-all;font-size:.8rem" id="dash-dest">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Downloads gesamt</div><div class="dkpi-n" style="color:var(--green)" id="dash-total-dl">–</div></div>
      </div>

      <!-- Zeile 2: Queue-Zahlen + Disk + System in einer Zeile -->
      <div class="dash-disk-grid">
        <div class="dkpi"><div class="dkpi-l">Ausstehend</div><div class="dkpi-n" style="color:var(--accent)" id="dqs-pending">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Lädt</div><div class="dkpi-n" style="color:var(--orange)" id="dqs-downloading">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Fertig</div><div class="dkpi-n" style="color:var(--green)" id="dqs-done">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Fehler</div><div class="dkpi-n" style="color:var(--red)" id="dqs-error">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Speicher</div><div id="dash-disk"><div style="color:var(--muted);font-size:.75rem">Lade…</div></div></div>
        <div class="dkpi"><div class="dkpi-l">System</div><div id="dash-system" style="font-size:.78rem;line-height:1.75"><div style="color:var(--muted)">Lade…</div></div></div>
      </div>

      <!-- Zeile 3: Xtream Server Info (6 kompakte Kacheln) -->
      <div id="dash-server-info" class="dash-server-grid">
        <div class="dkpi"><div class="dkpi-l">Status</div><div class="dkpi-v" id="si-status">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Läuft ab</div><div class="dkpi-v" id="si-exp">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Verbindungen</div><div class="dkpi-n" style="font-size:1.2rem" id="si-cons">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Trial</div><div class="dkpi-v" id="si-trial">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Formate</div><div class="dkpi-v" id="si-formats">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Serverzeit</div><div class="dkpi-v" style="font-family:'DM Mono',monospace;font-size:.72rem" id="si-time">–</div></div>
      </div>

      <!-- Schnellzugriff -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
        <button class="btn-secondary" data-label="▶ Queue starten" onclick="startQueue(this)">▶ Queue starten</button>
        <button class="btn-secondary" onclick="dashRebuildCache()">↻ Cache aufbauen</button>
        <button class="btn-secondary" onclick="dashClearDone()">Erledigte leeren</button>
        <button class="btn-secondary danger" onclick="dashClearAll()">Queue leeren</button>
        <button class="btn-secondary" onclick="showView('settings')">Einstellungen</button>
        <button class="btn-secondary" onclick="showView('log')">Cron Log</button>
      </div>

      <!-- Live Progress Card (nur sichtbar wenn aktiver Download) -->
      <div class="progress-card" id="dash-progress-card" style="margin-bottom:12px">
        <div class="pc-header">
          <div class="pc-dot"></div>
          <div class="pc-title" id="dash-pc-title">–</div>
          <div class="pc-pos"   id="dash-pc-pos"></div>
          <?php if ($can_queue_remove): ?>
          <button class="btn-sm" style="margin-left:auto;color:var(--red);border-color:rgba(255,71,87,.3)" onclick="cancelDownload()">✕ Abbrechen</button>
          <?php endif; ?>
        </div>
        <div class="pc-bar-wrap"><div class="pc-bar" id="dash-pc-bar"></div></div>
        <div class="pc-stats">
          <div class="pc-stat"><span class="val" id="dash-pc-pct">0%</span><span class="lbl">Fortschritt</span></div>
          <div class="pc-stat"><span class="val" id="dash-pc-done">–</span><span class="lbl">heruntergeladen</span></div>
          <div class="pc-stat"><span class="val" id="dash-pc-total">–</span><span class="lbl">gesamt</span></div>
          <div class="pc-stat"><span class="val" id="dash-pc-speed">–</span><span class="lbl">Geschwindigkeit</span></div>
          <div class="pc-stat"><span class="val" id="dash-pc-eta">–</span><span class="lbl">verbleibend</span></div>
        </div>
      </div>

      <!-- Letzte Downloads + Queue nebeneinander -->
      <div class="dash-bottom-grid" id="dash-bottom-grid">
        <div>
          <div class="dkpi-l" style="margin-bottom:8px">Letzte Downloads</div>
          <div id="dash-recent" style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;overflow:hidden">
            <div style="padding:24px;text-align:center"><div class="spinner" style="margin:auto"></div></div>
          </div>
        </div>
        <div>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
            <div class="dkpi-l">Queue</div>
            <button class="btn-sm" onclick="showView('queue')">Alle →</button>
          </div>
          <div class="queue-list" id="dash-queue-list">
            <div style="padding:32px;text-align:center"><div class="spinner" style="margin:auto"></div></div>
          </div>
        </div>
      </div>

      <?php else: ?>
      <!-- Viewer/Editor Dashboard -->

      <!-- KPI-Zeile -->
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px">
        <div class="dkpi" style="flex:1;min-width:140px">
          <div class="dkpi-l">Downloads gesamt</div>
          <div class="dkpi-n" style="color:var(--green)" id="ue-total-dl">–</div>
        </div>
        <?php if ($can_queue_view): ?>
        <div class="dkpi" style="flex:1;min-width:140px">
          <div class="dkpi-l">Ausstehend</div>
          <div class="dkpi-n" style="color:var(--accent)" id="ue-pending">–</div>
        </div>
        <div class="dkpi" style="flex:1;min-width:140px">
          <div class="dkpi-l">Lädt gerade</div>
          <div class="dkpi-n" style="color:var(--orange)" id="ue-downloading">–</div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Neue Releases -->
      <div style="margin-bottom:24px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
          <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase">🆕 Neue Releases</div>
          <button class="btn-sm" onclick="showView('new-releases')">Alle →</button>
        </div>
        <div id="ue-new-releases" style="display:flex;gap:10px;overflow-x:auto;padding-bottom:8px">
          <div style="color:var(--muted);font-size:.8rem;padding:8px">Lade…</div>
        </div>
      </div>

      <!-- Letzte Downloads -->
      <div>
        <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin-bottom:10px">📥 Zuletzt heruntergeladen</div>
        <div id="ue-recent" class="grid">
          <div style="color:var(--muted);font-size:.8rem;padding:8px">Lade…</div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Movies -->
    <?php if ($show_movies): ?>
    <div id="view-movies" style="display:none">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
        <div style="flex:1"></div>
        <div style="display:flex;align-items:center;gap:6px">
          <span style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted)">Sortierung:</span>
          <select id="sort-movies" class="sort-select" onchange="setSortOrder(this.value,'movies')">
            <option value="default">Standard</option><option value="az">A → Z</option><option value="za">Z → A</option>
          </select>
        </div>
      </div>
      <div class="grid" id="movie-grid"></div>
      <div id="movie-pagination" style="margin-top:16px;text-align:center"></div>
    </div>
    <?php endif; ?>
    <?php if ($show_series): ?>
    <div id="view-series" style="display:none">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
        <div style="flex:1"></div>
        <div style="display:flex;align-items:center;gap:6px">
          <span style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted)">Sortierung:</span>
          <select id="sort-series" class="sort-select" onchange="setSortOrder(this.value,'series')">
            <option value="default">Standard</option><option value="az">A → Z</option><option value="za">Z → A</option>
          </select>
        </div>
      </div>
      <div class="grid" id="series-grid"></div>
      <div id="series-pagination" style="margin-top:16px;text-align:center"></div>
    </div>
    <?php endif; ?>
    <!-- Search -->
    <div id="view-search" style="display:none">
      <div id="search-history-box" style="margin-bottom:16px"></div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap">
        <button class="filter-btn active" id="search-tab-movies"  onclick="switchSearchTab('movies',this)">🎬 Filme</button>
        <button class="filter-btn"        id="search-tab-series"  onclick="switchSearchTab('series',this)">📺 Serien</button>
        <?php if ($can_queue_add): ?>
        <div id="multiselect-toolbar" style="display:none;margin-left:auto;display:flex;gap:8px;align-items:center">
          <span id="multiselect-count" style="font-family:'DM Mono',monospace;font-size:.7rem;color:var(--muted)">0 ausgewählt</span>
          <button class="btn-sm" onclick="addSelectionToQueue()">+ Alle zur Queue</button>
          <button class="btn-sm" onclick="clearSelection()">✕ Auswahl aufheben</button>
        </div>
        <?php endif; ?>
      </div>
      <div id="search-movies-grid" class="grid"></div>
      <div id="search-series-grid" class="grid" style="display:none"></div>
    </div>

    <?php if ($can_queue_view): ?>
    <!-- Queue -->
    <div id="view-queue" style="display:none">
      <!-- Live Progress Card -->
      <div class="progress-card" id="progress-card">
        <div class="pc-header">
          <div class="pc-dot"></div>
          <div class="pc-title" id="pc-title">–</div>
          <div class="pc-pos" id="pc-pos"></div>
          <?php if ($can_queue_remove): ?>
          <button class="btn-sm" style="margin-left:auto;color:var(--red);border-color:rgba(255,71,87,.3)" onclick="cancelDownload()">✕ Abbrechen</button>
          <?php endif; ?>
        </div>
        <div class="pc-bar-wrap"><div class="pc-bar" id="pc-bar"></div></div>
        <div class="pc-stats">
          <div class="pc-stat"><span class="val" id="pc-pct">0%</span><span class="lbl">Fortschritt</span></div>
          <div class="pc-stat"><span class="val" id="pc-done">–</span><span class="lbl">heruntergeladen</span></div>
          <div class="pc-stat"><span class="val" id="pc-total">–</span><span class="lbl">gesamt</span></div>
          <div class="pc-stat"><span class="val" id="pc-speed">–</span><span class="lbl">Geschwindigkeit</span></div>
          <div class="pc-stat"><span class="val" id="pc-eta">–</span><span class="lbl">verbleibend</span></div>
        </div>
      </div>
      <div class="queue-toolbar">
        <div class="queue-toolbar-title">Download Queue</div>
        <button class="btn-sm" onclick="refreshQueue()">↻ Refresh</button>
        <?php if ($can_settings): ?>
        <button class="btn-sm" data-label="▶ Starten" onclick="startQueue(this)">▶ Starten</button>
        <?php endif; ?>
        <?php if ($can_queue_clear): ?>
        <button class="btn-sm" onclick="clearDone()">✕ Done entfernen</button>
        <button class="btn-sm danger" onclick="clearAll()">🗑 Alles löschen</button>
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
        <div style="display:flex;gap:8px">
          <button class="filter-btn active" id="fav-tab-all"    onclick="switchFavTab('all',this)">Alle</button>
          <button class="filter-btn"        id="fav-tab-movies" onclick="switchFavTab('movie',this)">🎬 Filme</button>
          <button class="filter-btn"        id="fav-tab-series" onclick="switchFavTab('series',this)">📺 Serien</button>
        </div>
      </div>
      <div style="margin-bottom:14px">
        <div class="search-wrap" style="max-width:300px">
          <input type="text" id="fav-search" placeholder="Favoriten durchsuchen…" oninput="renderFavourites()">
          <span class="search-icon">🔍</span>
        </div>
      </div>
      <div class="grid" id="fav-grid"></div>
    </div>

    <!-- Neue Releases -->
    <div id="view-new-releases" style="display:none">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div>
          <div style="font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:.08em">Neue Releases</div>
          <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);margin-top:2px" id="new-releases-meta">–</div>
        </div>
        <div style="display:flex;gap:8px">
          <button class="filter-btn active" id="nr-tab-all"    onclick="switchNrTab('all',this)">Alle</button>
          <button class="filter-btn"        id="nr-tab-movies" onclick="switchNrTab('movie',this)">🎬 Filme</button>
          <button class="filter-btn"        id="nr-tab-series" onclick="switchNrTab('series',this)">📺 Serien</button>
        </div>
      </div>
      <div class="grid" id="new-releases-grid"></div>
    </div>

    <!-- Statistiken (admin only) -->
    <?php if ($can_settings): ?>
    <div id="view-stats" style="display:none">
      <div style="margin-bottom:20px">
        <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin-bottom:4px">Gesamtstatistik</div>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
          <div class="dkpi"><div class="dkpi-l">Downloads gesamt</div><div class="dkpi-n" id="stats-total-count">–</div></div>
          <div class="dkpi"><div class="dkpi-l">Datenvolumen gesamt</div><div class="dkpi-n" id="stats-total-gb">–</div></div>
        </div>
      </div>

      <!-- Charts nebeneinander (2 Spalten auf Desktop) -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
        <!-- GB pro Monat -->
        <div class="settings-card">
          <h3>📦 Datenvolumen pro Monat</h3>
          <div style="position:relative;height:220px">
            <canvas id="stats-chart-gb"></canvas>
          </div>
        </div>

        <!-- Downloads pro Monat -->
        <div class="settings-card">
          <h3>📥 Downloads pro Monat</h3>
          <div style="position:relative;height:220px">
            <canvas id="stats-chart-count"></canvas>
          </div>
        </div>
      </div>

      <!-- Top Kategorien -->
      <div class="settings-card" style="margin-bottom:16px">
        <h3>🏷️ Top Kategorien</h3>
        <div style="position:relative;height:320px">
          <canvas id="stats-chart-cats"></canvas>
        </div>
      </div>

      <!-- Top User -->
      <div class="settings-card">
        <h3>👤 Top User</h3>
        <div id="stats-top-users"><div style="color:var(--muted);font-size:.8rem">Lade…</div></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- API Docs -->
    <?php if ($can_settings): ?>
    <div id="view-api-docs" style="display:none">
      <div>

        <!-- Intro -->
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:20px 24px;margin-bottom:20px">
          <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.2em;text-transform:uppercase;margin-bottom:10px">Authentifizierung</div>
          <p style="font-size:.85rem;color:var(--muted);line-height:1.7;margin-bottom:12px">
            Alle externen Endpoints erfordern einen API-Key. Dieser kann als HTTP-Header oder Query-Parameter übergeben werden.
          </p>
          <div class="api-code">X-API-Key: xv_xxxxxxxxxxxx</div>
          <div style="font-size:.78rem;color:var(--muted);margin-top:8px">oder als Query-Parameter: <code style="color:var(--accent2)">?api_key=xv_xxxxxxxxxxxx</code></div>
          <div style="font-size:.78rem;color:var(--muted);margin-top:6px">API-Keys verwalten: <span style="color:var(--accent2);cursor:pointer" onclick="showView('settings')">Einstellungen → API-Keys</span></div>
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
          <div class="api-desc">Legt einen neuen Benutzer an.</div>
          <div class="api-section-label">Parameter</div>
          <table class="api-table">
            <tr><th>Name</th><th>Typ</th><th>Pflicht</th><th>Beschreibung</th></tr>
            <tr><td>username</td><td>string</td><td>✅</td><td>Benutzername (eindeutig)</td></tr>
            <tr><td>password</td><td>string</td><td>✅</td><td>Passwort (min. 6 Zeichen)</td></tr>
            <tr><td>role</td><td>string</td><td>–</td><td><code>viewer</code> (Standard), <code>editor</code>, <code>admin</code></td></tr>
          </table>
          <div class="api-section-label">Antwort</div>
          <div class="api-code">{ "ok": true, "id": "abc123", "username": "max", "role": "viewer" }</div>
        </div>

        <!-- list_users -->
        <div class="api-card">
          <div class="api-card-header">
            <span class="api-method get">GET</span>
            <code class="api-endpoint"><?= htmlspecialchars($base) ?>?action=external_list_users</code>
          </div>
          <div class="api-desc">Gibt alle Benutzer zurück (ohne Passwörter).</div>
          <div class="api-section-label">Antwort</div>
          <div class="api-code">{ "ok": true, "count": 3, "users": [<br>&nbsp;&nbsp;{ "id": "abc123", "username": "max", "role": "viewer", "suspended": false, "created_at": "2026-01-01 12:00:00" }<br>] }</div>
        </div>

        <!-- suspend_user -->
        <div class="api-card">
          <div class="api-card-header">
            <span class="api-method post">POST</span>
            <span class="api-method get">GET</span>
            <code class="api-endpoint"><?= htmlspecialchars($base) ?>?action=external_suspend_user</code>
          </div>
          <div class="api-desc">Sperrt oder entsperrt einen Benutzer. Admins können nicht gesperrt werden.</div>
          <div class="api-section-label">Parameter</div>
          <table class="api-table">
            <tr><th>Name</th><th>Typ</th><th>Pflicht</th><th>Beschreibung</th></tr>
            <tr><td>username</td><td>string</td><td>✅</td><td>Benutzername</td></tr>
            <tr><td>suspended</td><td>bool</td><td>–</td><td><code>true</code> = sperren (Standard), <code>false</code> = entsperren</td></tr>
          </table>
          <div class="api-section-label">Antwort</div>
          <div class="api-code">{ "ok": true, "username": "max", "suspended": true }</div>
        </div>

        <!-- update_user -->
        <div class="api-card">
          <div class="api-card-header">
            <span class="api-method post">POST</span>
            <code class="api-endpoint"><?= htmlspecialchars($base) ?>?action=external_update_user</code>
          </div>
          <div class="api-desc">Ändert Passwort oder Rolle eines Benutzers.</div>
          <div class="api-section-label">Parameter</div>
          <table class="api-table">
            <tr><th>Name</th><th>Typ</th><th>Pflicht</th><th>Beschreibung</th></tr>
            <tr><td>username</td><td>string</td><td>✅</td><td>Benutzername</td></tr>
            <tr><td>password</td><td>string</td><td>–</td><td>Neues Passwort</td></tr>
            <tr><td>role</td><td>string</td><td>–</td><td><code>viewer</code>, <code>editor</code>, <code>admin</code></td></tr>
          </table>
          <div class="api-section-label">Antwort</div>
          <div class="api-code">{ "ok": true, "username": "max", "updated": true }</div>
        </div>

        <!-- delete_user -->
        <div class="api-card">
          <div class="api-card-header">
            <span class="api-method post">POST</span>
            <span class="api-method get">GET</span>
            <code class="api-endpoint"><?= htmlspecialchars($base) ?>?action=external_delete_user</code>
          </div>
          <div class="api-desc">Löscht einen Benutzer permanent. Admins können nicht gelöscht werden.</div>
          <div class="api-section-label">Parameter</div>
          <table class="api-table">
            <tr><th>Name</th><th>Typ</th><th>Pflicht</th><th>Beschreibung</th></tr>
            <tr><td>username</td><td>string</td><td>✅</td><td>Benutzername</td></tr>
          </table>
          <div class="api-section-label">Antwort</div>
          <div class="api-code">{ "ok": true, "username": "max", "deleted": true }</div>
        </div>

        <!-- Fehler -->
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:20px 24px;margin-top:8px">
          <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.2em;text-transform:uppercase;margin-bottom:10px">Fehlercodes</div>
          <table class="api-table">
            <tr><th>HTTP</th><th>Bedeutung</th></tr>
            <tr><td>400</td><td>Fehlende oder ungültige Parameter</td></tr>
            <tr><td>401</td><td>API-Key ungültig oder widerrufen</td></tr>
            <tr><td>403</td><td>Aktion nicht erlaubt (z.B. Admin sperren)</td></tr>
            <tr><td>404</td><td>Benutzer nicht gefunden</td></tr>
          </table>
          <div style="margin-top:12px;font-size:.78rem;color:var(--muted)">Fehlermeldungen werden als <code style="color:var(--accent2)">{ "error": "..." }</code> zurückgegeben.</div>
        </div>

      </div>
    </div>
    <?php endif; ?>

    <?php if ($can_cron_log): ?>
    <div id="view-log" style="display:none">      <div class="queue-toolbar">
        <div class="queue-toolbar-title">Cron Log</div>
        <button class="btn-sm" onclick="loadLog()">↻ Aktualisieren</button>
      </div>
      <div class="log-wrap" id="log-wrap">Lade Log…</div>
    </div>
    <?php endif; ?>

    <!-- Settings -->
    <div id="view-settings" style="display:none">
      <?php if ($can_settings): ?>
      <div>

        <div class="settings-card">
          <h3>Xtream Server</h3>
          <div style="font-size:.75rem;color:var(--muted);margin-bottom:14px;font-family:'DM Mono',monospace">
            Server-ID: <span id="cfg-server-id-display" style="color:var(--accent2)">–</span>
            <span style="margin-left:8px;font-size:.65rem;opacity:.6">Downloads und Queue sind pro Server getrennt</span>
          </div>

          <!-- Gespeicherte Server -->
          <div id="saved-servers-box" style="margin-bottom:18px">
            <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin-bottom:8px">Gespeicherte Server</div>
            <div id="saved-servers-list" style="display:flex;flex-direction:column;gap:6px">
              <div style="color:var(--muted);font-size:.8rem">Lade…</div>
            </div>
          </div>
          <div class="field">
            <label>Server IP / Domain</label>
            <input type="text" id="cfg-server-ip" placeholder="z.B. line.example.com">
          </div>
          <div class="field">
            <label>Port</label>
            <input type="text" id="cfg-port" placeholder="80" value="80" style="max-width:120px">
          </div>
          <div class="field">
            <label>Username</label>
            <input type="text" id="cfg-username" placeholder="Xtream Username" autocomplete="off">
          </div>
          <div class="field">
            <label>Passwort</label>
            <input type="password" id="cfg-password" placeholder="Xtream Passwort" autocomplete="new-password">
            <span class="hint">Leer lassen um das bestehende Passwort zu behalten</span>
          </div>
        </div>

        <div class="settings-card">
          <h3>Download-Zielordner</h3>
          <div id="rclone-disabled-fields">
            <div class="field">
              <label>Absoluter Pfad auf dem Server</label>
              <input type="text" id="cfg-dest-path" placeholder="/var/www/html/xtream/downloads">
              <span class="hint">Wird ignoriert wenn rclone aktiviert ist</span>
            </div>
          </div>
        </div>

        <div class="settings-card">
          <h3>Editor / Viewer — Sichtbarkeit</h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
            Steuert welche Bereiche für editor- und viewer-Accounts sichtbar sind.<br>
            Admins sehen immer alles.
          </div>
          <label class="settings-toggle">
            <input type="checkbox" id="cfg-editor-movies">
            <span>🎬 Movies anzeigen</span>
          </label>
          <label class="settings-toggle">
            <input type="checkbox" id="cfg-editor-series">
            <span>📺 Series anzeigen</span>
          </label>
        </div>

        <div class="settings-card">
          <h3>☁️ rclone — Cloud-Speicher</h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
            Wenn aktiviert, werden VODs direkt in den Cloud-Speicher gestreamt — ohne lokale Zwischenspeicherung.
          </div>
          <label class="settings-toggle" style="margin-bottom:14px">
            <input type="checkbox" id="cfg-rclone-enabled" onchange="toggleRcloneFields(this.checked)">
            <span>rclone aktivieren</span>
          </label>
          <div id="rclone-fields" style="display:none">
            <div class="field">
              <label>Remote-Name</label>
              <input type="text" id="cfg-rclone-remote" placeholder="gdrive">
              <span class="hint">Name des konfigurierten rclone-Remotes (z.B. gdrive, onedrive, dropbox)</span>
            </div>
            <div class="field">
              <label>Ziel-Pfad im Remote</label>
              <input type="text" id="cfg-rclone-path" placeholder="Media/VOD">
              <span class="hint">Ordner im Cloud-Speicher (ohne Remote-Name)</span>
            </div>
            <div class="field">
              <label>rclone Binary-Pfad</label>
              <input type="text" id="cfg-rclone-bin" placeholder="rclone">
              <span class="hint">Vollständiger Pfad wenn nicht im PATH: z.B. /usr/bin/rclone</span>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <button class="btn-secondary" onclick="testRclone()">🔌 rclone testen</button>
              <button class="btn-secondary" id="btn-rclone-cache" onclick="refreshRcloneCache(this)">🗂 Remote-Cache aktualisieren</button>
              <div class="settings-msg" id="rclone-test-msg" style="margin:0"></div>
            </div>
            <div id="rclone-cache-status" style="font-family:'DM Mono',monospace;font-size:.68rem;color:var(--muted);margin-top:10px"></div>
          </div>
        </div>

        <div class="settings-card">
          <h3>Medien-Cache</h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.6">
            Der Cache speichert Titel und Cover aller VODs lokal.<br>
            Er wird automatisch nach jedem Download-Run aktualisiert.
          </div>
          <div id="cache-status-box" style="font-family:'DM Mono',monospace;font-size:.72rem;color:var(--muted);margin-bottom:14px">Lade Status…</div>
          <button class="btn-secondary" id="btn-rebuild-cache" onclick="rebuildCache(this)">🔄 Cache jetzt aufbauen</button>
          <div class="settings-msg" id="cache-msg"></div>
        </div>

        <div class="settings-card">
          <h3>🎬 TMDB Integration</h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.6">
            The Movie Database — zeigt Plot und Bewertung beim Klick auf eine Karte an.<br>
            API-Key unter <a href="https://www.themoviedb.org/settings/api" target="_blank" style="color:var(--accent2)">themoviedb.org/settings/api</a> erstellen.
          </div>
          <div class="field">
            <label>TMDB API-Key (v3 auth)</label>
            <input type="password" id="cfg-tmdb-api-key" placeholder="Leer lassen um TMDB zu deaktivieren" autocomplete="off">
          </div>
        </div>

        <div class="settings-card">
          <h3>🔄 Updates</h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.6">
            Lädt die neueste Version als ZIP von
            <a href="https://github.com/extend110/xtream-vault" target="_blank" style="color:var(--accent2)">GitHub</a>
            herunter und installiert sie. Vor dem Update wird automatisch ein Backup von <code>data/</code> erstellt.
          </div>
          <div id="update-status" style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:12px 14px;margin-bottom:14px;font-family:'DM Mono',monospace;font-size:.72rem;line-height:1.8">
            <div style="color:var(--muted)">– noch nicht geprüft –</div>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="btn-secondary" onclick="checkUpdate(this)">🔍 Auf Updates prüfen</button>
            <button class="btn-primary"   id="btn-run-update" onclick="runUpdate(this)" style="display:none">⬆️ Update installieren</button>
            <div class="settings-msg" id="update-msg" style="margin:0"></div>
          </div>
          <div id="update-log" style="display:none;margin-top:12px;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:10px 14px;font-family:'DM Mono',monospace;font-size:.68rem;color:var(--muted);white-space:pre-wrap;max-height:160px;overflow-y:auto"></div>
        </div>

        <div class="settings-card">
          <h3>🔒 VPN (WireGuard)</h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.6">
            Downloads über WireGuard-VPN leiten. VPN wird vor dem ersten Download gestartet und danach automatisch getrennt.<br>
            Die Konfigurationsdatei muss unter <code style="color:var(--accent2)">/etc/wireguard/&lt;interface&gt;.conf</code> liegen.
          </div>
          <label class="settings-toggle" style="margin-bottom:14px">
            <input type="checkbox" id="cfg-vpn-enabled">
            <span>VPN für Downloads aktivieren</span>
          </label>
          <div class="field">
            <label>Interface-Name</label>
            <input type="text" id="cfg-vpn-interface" placeholder="wg0" style="max-width:160px">
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="btn-secondary" onclick="checkVpnStatus()">🔍 Status prüfen</button>
            <button class="btn-secondary" onclick="vpnConnect()">▶ Verbinden</button>
            <button class="btn-secondary" onclick="vpnDisconnect()">■ Trennen</button>
            <div class="settings-msg" id="vpn-status-msg" style="margin:0"></div>
          </div>
          <!-- VPN Live-Statistiken (nur sichtbar wenn aktiv) -->
          <div id="vpn-stats-card" style="display:none;margin-top:14px;background:var(--bg3);border:1px solid rgba(46,213,115,.2);border-radius:8px;padding:14px 16px">
            <div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--green);letter-spacing:.1em;text-transform:uppercase;margin-bottom:10px">🔒 VPN aktiv</div>
            <div style="display:flex;gap:16px;flex-wrap:wrap">
              <div><div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em">Öffentliche IP</div><div id="vpn-stat-ip" style="font-family:'DM Mono',monospace;font-size:.82rem;margin-top:3px">–</div></div>
              <div><div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em">Verbunden seit</div><div id="vpn-stat-since" style="font-family:'DM Mono',monospace;font-size:.82rem;margin-top:3px">–</div></div>
              <div><div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em">Interface</div><div id="vpn-stat-iface" style="font-family:'DM Mono',monospace;font-size:.82rem;margin-top:3px">–</div></div>
            </div>
          </div>
        </div>

        <div class="settings-card">
          <h3>📨 Telegram-Benachrichtigungen</h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.6">
            Benachrichtigung bei abgeschlossenem Download via Telegram Bot.<br>
            Bot erstellen: <a href="https://t.me/BotFather" target="_blank" style="color:var(--accent2)">@BotFather</a> →
            Chat-ID ermitteln: <a href="https://t.me/userinfobot" target="_blank" style="color:var(--accent2)">@userinfobot</a>
          </div>
          <div class="field">
            <label>Bot Token</label>
            <input type="password" id="cfg-telegram-bot-token" placeholder="Leer lassen um Telegram zu deaktivieren" autocomplete="off">
          </div>
          <div class="field">
            <label>Chat ID</label>
            <input type="text" id="cfg-telegram-chat-id" placeholder="z.B. 123456789 oder -100123456789 (Gruppe)">
          </div>
          <label class="settings-toggle" style="margin-bottom:14px">
            <input type="checkbox" id="cfg-telegram-enabled">
            <span>Benachrichtigungen aktivieren</span>
          </label>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="btn-secondary" onclick="testTelegram()">📨 Testnachricht senden</button>
            <div class="settings-msg" id="telegram-test-msg" style="margin:0"></div>
          </div>
        </div>

        <div class="settings-card">
          <h3>API-Keys (Externe Benutzerverwaltung)</h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
            API-Keys ermöglichen externe Systeme, Benutzer anzulegen.<br>
            Authentifizierung via Header <code style="color:var(--accent2);font-family:'DM Mono',monospace">X-API-Key: xv_...</code>
          </div>
          <div id="apikey-new-reveal" style="display:none" class="key-new-reveal">
            <strong>⚠️ Einmalig sichtbar — jetzt kopieren!</strong>
            <span id="apikey-new-value"></span>
          </div>
          <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
            <input type="text" id="apikey-name-input" placeholder="Name des Keys (z.B. Webshop)" style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:8px 12px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.82rem;outline:none;flex:1">
            <button class="btn-primary" onclick="createApiKey()">+ API-Key erstellen</button>
          </div>
          <div class="apikey-table-wrap">
            <table class="apikey-table" id="apikey-table">
              <thead><tr><th>Name</th><th>Key</th><th>Status</th><th>Erstellt</th><th>Zuletzt benutzt</th><th>Aufrufe</th><th></th></tr></thead>
              <tbody id="apikey-tbody"><tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)">Lade…</td></tr></tbody>
            </table>
          </div>
        </div>

        <div class="settings-card">
          <h3>💾 Datensicherung</h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
            Sichert alle Dateien im <code>data/</code>-Verzeichnis als ZIP. Automatisch täglich um 3 Uhr, maximal 7 Backups.
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px">
            <button class="btn-secondary" onclick="runBackup(this)">▶ Jetzt sichern</button>
            <span id="backup-run-msg" style="font-size:.78rem;color:var(--muted)"></span>
          </div>
          <div id="backup-list" style="overflow-x:auto"><div style="color:var(--muted);font-size:.8rem">Lade…</div></div>
        </div>

        <div class="settings-card" style="border-color:rgba(255,71,87,.2)">
          <h3>🔧 Wartungsmodus</h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
            Wenn aktiv, können sich nur Admins einloggen. Alle anderen sehen eine Wartungsseite.
          </div>
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <div id="maintenance-status" style="font-family:'DM Mono',monospace;font-size:.75rem;padding:5px 12px;border-radius:5px;background:var(--bg3);border:1px solid var(--border)">
              Lade…
            </div>
            <button class="btn-secondary" id="btn-maintenance-toggle" onclick="toggleMaintenance()">Lade…</button>
          </div>
        </div>

        <div class="settings-actions">
          <button class="btn-secondary" onclick="testConnection()">🔌 Verbindung testen</button>
          <button class="btn-primary" id="btn-save-cfg" onclick="saveConfig()">💾 Speichern</button>
        </div>
        <div class="settings-msg" id="settings-msg"></div>

      </div>
      <?php else: ?>
      <div class="state-box"><div class="icon">🔒</div><p>Keine Berechtigung — nur Admins können die Einstellungen ändern.</p></div>
      <?php endif; ?>
    </div>

    <!-- Users (admin only) -->
    <div id="view-users" style="display:none">
      <?php if ($can_users): ?>
      <div class="queue-toolbar">
        <div class="queue-toolbar-title">Benutzerverwaltung</div>
        <button class="btn-sm" onclick="showView('activity-log')">📋 Aktivitätslog</button>
        <button class="btn-sm" onclick="openInviteModal()">🔗 Einladung erstellen</button>
        <button class="btn-sm" onclick="openCreateUser()">+ Benutzer anlegen</button>
      </div>
      <div class="user-table-wrap" style="background:var(--bg2);border:1px solid var(--border);border-radius:8px">
        <table class="user-table" id="users-table">
          <thead>
            <tr>
              <th>Benutzername</th>
              <th>Rolle</th>
              <th>Status</th>
              <th>Erstellt</th>
              <th>Queue-Limit</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="users-tbody">
            <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted)">Lade…</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Aktive Einladungslinks -->
      <div style="margin-top:24px">
        <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin-bottom:10px">Einladungslinks</div>
        <div id="invite-list"><div style="color:var(--muted);font-size:.8rem">Lade…</div></div>
      </div>
      <?php else: ?>
      <div class="state-box"><div class="icon">🔒</div><p>Keine Berechtigung</p></div>
      <?php endif; ?>
    </div>

    <!-- Activity Log -->
    <div id="view-activity-log" style="display:none">
      <?php if ($can_users): ?>
      <div class="queue-toolbar">
        <div class="queue-toolbar-title">Aktivitätslog</div>
        <select id="actlog-user-filter" onchange="loadActivityLog()" style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:6px 10px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.82rem;outline:none">
          <option value="">Alle Benutzer</option>
        </select>
        <button class="btn-sm" onclick="showView('users')">← Zurück</button>
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
            Wähle ein Design für deine Ansicht. Die Auswahl wird lokal gespeichert.
          </div>
          <div class="theme-picker" id="theme-picker"></div>
        </div>
        <div class="settings-card">
          <h3>Passwort ändern</h3>
          <div class="field">
            <label>Aktuelles Passwort</label>
            <input type="password" id="prof-old-pw" autocomplete="current-password">
          </div>
          <div class="field">
            <label>Neues Passwort</label>
            <input type="password" id="prof-new-pw" autocomplete="new-password">
          </div>
          <div class="field">
            <label>Neues Passwort bestätigen</label>
            <input type="password" id="prof-new-pw2" autocomplete="new-password">
          </div>
          <div class="settings-actions" style="margin-top:16px">
            <button class="btn-primary" onclick="changeOwnPassword()">💾 Passwort ändern</button>
          </div>
          <div class="settings-msg" id="profile-msg"></div>
        </div>
        <div class="settings-card">
          <h3>Konto</h3>
          <div style="font-size:.875rem;line-height:2">
            <div style="color:var(--muted);font-family:'DM Mono',monospace;font-size:.65rem;letter-spacing:.1em;text-transform:uppercase">Benutzername</div>
            <div><?= htmlspecialchars($user['username']) ?></div>
            <div style="color:var(--muted);font-family:'DM Mono',monospace;font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;margin-top:12px">Rolle</div>
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
    <div class="umodal-title" id="umodal-title">Benutzer anlegen</div>
    <input type="hidden" id="umodal-id">
    <div class="field" id="umodal-username-wrap">
      <label>Benutzername</label>
      <input type="text" id="umodal-username" autocomplete="off">
    </div>
    <div class="field">
      <label>Passwort <span id="umodal-pw-hint" style="color:var(--muted);font-weight:400;text-transform:none;letter-spacing:0"></span></label>
      <input type="password" id="umodal-password" autocomplete="new-password">
    </div>
    <div class="field">
      <label>Rolle</label>
      <select id="umodal-role" style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:9px 12px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.875rem;outline:none;width:100%">
        <option value="viewer">viewer – Nur browsen</option>
        <option value="editor">editor – Browsen + Queue</option>
        <option value="admin">admin – Vollzugriff</option>
      </select>
    </div>
    <div class="settings-msg" id="umodal-msg"></div>
    <div class="umodal-actions">
      <button class="btn-secondary" onclick="closeUModal()">Abbrechen</button>
      <button class="btn-primary" id="umodal-submit" onclick="submitUModal()">Anlegen</button>
    </div>
  </div>
</div>

<!-- ── Series Modal ───────────────────────────────────────────── -->
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
      <button class="btn-primary" onclick="submitPwReset()" style="flex:1">💾 Speichern</button>
      <button class="btn-secondary" onclick="closePwResetModal()">Abbrechen</button>
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
      <div style="color:var(--muted);text-align:center;padding:24px">⏳ Lade…</div>
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
      <label>Rolle</label>
      <select id="invite-role" style="width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:10px 14px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.9rem;outline:none">
        <option value="viewer">Viewer</option>
        <option value="editor">Editor</option>
        <option value="admin">Admin</option>
      </select>
    </div>
    <div class="field">
      <label>Gültigkeitsdauer</label>
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
      <button class="btn-primary" id="btn-create-invite" onclick="createInvite()" style="flex:1">Link generieren</button>
      <button class="btn-secondary" onclick="closeInviteModal()">Schließen</button>
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
      <div id="tmdb-loading" style="text-align:center;padding:24px;color:var(--muted);font-size:.85rem">⏳ Lade Informationen…</div>
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
          <label>Admin-Passwort</label>
          <input type="password" id="reveal-password-input" placeholder="Dein Passwort"
            onkeydown="if(event.key==='Enter')submitReveal()">
        </div>
        <div id="reveal-error" style="font-size:.78rem;color:var(--red);margin-bottom:10px;display:none"></div>
        <div style="display:flex;gap:8px">
          <button class="btn-primary" onclick="submitReveal()">Bestätigen</button>
          <button class="btn-secondary" onclick="closeRevealModal()">Abbrechen</button>
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
          <button class="btn-primary" onclick="copyRevealKey()">📋 Kopieren</button>
          <button class="btn-secondary" onclick="closeRevealModal()">Schließen</button>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const API = 'api.php';
const canQueueAdd       = <?= $can_queue_add        ? 'true' : 'false' ?>;
const canQueueRemove    = <?= $can_queue_remove     ? 'true' : 'false' ?>;
const canQueueRemoveOwn = <?= $can_queue_remove_own ? 'true' : 'false' ?>;
const canSeeAddedBy     = <?= $can_settings         ? 'true' : 'false' ?>;
const currentUsername   = <?= json_encode($user['username']) ?>;
let currentView   = 'dashboard';
let _queuedIds    = new Set(); // stream_ids aktuell in der Queue (non-done)
let _downloadedIds = new Set(); // stream_ids bereits heruntergeladen (done)
let currentFilter = 'all';
let allMovies     = [];
let searchDebounce;
let queueRefreshInterval;

// ── Init ──────────────────────────────────────────────────────
(async () => {
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
  startBadgePolling();
  <?php if ($can_settings && VPN_ENABLED): ?>startVpnPolling();<?php endif; ?>
  // Neue-Releases-Badge beim Start laden
  api('get_new_releases').then(d => {
    const total = (d.movies?.length ?? 0) + (d.series?.length ?? 0);
    const badge = document.getElementById('new-releases-badge');
    if (badge && total > 0) { badge.textContent = total; badge.style.display = ''; }
  });
})();

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
  const catHist = getCatHistory();
  if (!history.length && !catHist.length) { box.innerHTML = ''; return; }
  let html = '';
  if (history.length) {
    html += `<div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);letter-spacing:.12em;text-transform:uppercase;margin-bottom:8px">Zuletzt gesucht</div>`;
    html += history.map(q => `<div class="history-chip" onclick="doSearchFromHistory(${JSON.stringify(q)})">${esc(q)}</div>`).join('');
  }
  if (catHist.length) {
    html += `<div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);letter-spacing:.12em;text-transform:uppercase;margin:12px 0 8px">Zuletzt angesehen</div>`;
    html += catHist.map(c => `<div class="history-chip" onclick="loadCatFromHistory('${esc(c.type)}','${esc(c.catId)}','${esc(c.catName)}')">${c.type === 'series' ? '📺' : '🎬'} ${esc(c.catName)}</div>`).join('');
  }
  box.innerHTML = html;
}
function doSearchFromHistory(query) {
  document.getElementById('search-input').value = query;
  doSearch();
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
  updateQueuePill(d.queued ?? 0);
  // _downloadedIds aus stats befüllen (vollständig — inkl. aus Queue entfernter Items)
  if (Array.isArray(d.downloaded_ids)) {
    _downloadedIds = new Set(d.downloaded_ids);
  }
  // Favoriten neu rendern falls aktiv, damit Status sofort korrekt ist
  if (currentView === 'favourites') renderFavourites();
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

async function updateQueueBadge() {
  <?php if ($can_queue_view): ?>
  const items = await api('get_queue');
  if (Array.isArray(items)) {
    // _queuedIds: alle nicht-done Items
    _queuedIds    = new Set(items.filter(i => i.status !== 'done').map(i => String(i.stream_id)));
    // _downloadedIds aus Queue-done Items ergänzen (nicht überschreiben — stats ist maßgeblich)
    items.filter(i => i.status === 'done').forEach(i => _downloadedIds.add(String(i.stream_id)));
    updateQueuePill(items.filter(i => i.status === 'pending').length);
    // Favoriten neu rendern falls aktiv
    if (currentView === 'favourites') renderFavourites();
  }
  <?php endif; ?>
}

// ── Globales Badge-Polling (alle 10s, unabhängig von der aktuellen View) ──────
let _badgeInterval = null;
function startBadgePolling() {
  if (_badgeInterval) return;
  _badgeInterval = setInterval(updateQueueBadge, 10000);
}
startBadgePolling();

// ── Categories ────────────────────────────────────────────────
async function loadMovieCats() {
  const cats = await api('get_movie_categories');
  document.getElementById('cats-movies').innerHTML = cats.map(c =>
    `<div class="cat-item" onclick="loadMovies('${c.category_id}','${esc(c.category_name)}',this)">${c.category_name}</div>`
  ).join('');
}
async function loadSeriesCats() {
  const cats = await api('get_series_categories');
  document.getElementById('cats-series').innerHTML = cats.map(c =>
    `<div class="cat-item" onclick="loadSeriesCat('${c.category_id}','${esc(c.category_name)}',this)">${c.category_name}</div>`
  ).join('');
}
function toggleCats(type) {
  document.getElementById('cats-' + type).classList.toggle('open');
}

// ── Movies ────────────────────────────────────────────────────
const PAGE_SIZE = 50;
let _moviePage = 1;
let _seriesPage = 1;
let _lastMovies = [];
let _lastSeries = [];
let _movieSort  = 'default';
let _seriesSort = 'default';

async function loadMovies(catId, catName, el) {
  setActiveCat(el);
  showView('movies');
  document.getElementById('page-title').textContent = catName;
  document.getElementById('movie-grid').innerHTML = loadingHTML();
  addCatHistory('movies', catId, catName);
  _lastMovies = await api('get_movies', {category_id: catId});
  _lastMovies = _lastMovies.map(m => ({...m, category: m.category || catName}));
  _moviePage = 1;
  renderMovies();
}

function setSortOrder(order, type) {
  if (type === 'movies') { _movieSort = order; _moviePage = 1; renderMovies(); }
  else                   { _seriesSort = order; _seriesPage = 1; renderSeriesGrid(_lastSeries); }
}

function renderMovies() {
  const grid = document.getElementById('movie-grid');
  let movies = _lastMovies;
  if (currentFilter === 'done')   movies = movies.filter(m => m.downloaded);
  if (currentFilter === 'new')    movies = movies.filter(m => !m.downloaded && !m.queued);
  if (currentFilter === 'queued') movies = movies.filter(m => m.queued);
  if (_movieSort === 'az') movies = [...movies].sort((a,b) => (a.clean_title||'').localeCompare(b.clean_title||'', 'de'));
  if (_movieSort === 'za') movies = [...movies].sort((a,b) => (b.clean_title||'').localeCompare(a.clean_title||'', 'de'));
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

function movieCard(m) {
  const thumb = m.stream_icon ? `<img data-src="${m.stream_icon}" alt="">` : '';
  const badge = m.downloaded
    ? `<span class="card-badge badge-done">✓ Done</span>`
    : m.queued ? `<span class="card-badge badge-queue">⏳ Queue</span>` : '';

  const btn = m.downloaded
    ? canQueueRemove
      ? `<button class="btn-q done" onclick="resetDownload('${m.stream_id}','movie',null)" title="Zurücksetzen">↺ Reset</button>`
      : `<button class="btn-q done" disabled>✓ Done</button>`
    : m.queued && (canQueueRemove || canQueueRemoveOwn)
      ? `<button class="btn-q remove" onclick="removeFromQueue('${m.stream_id}',this.closest('.card'))">✕ Remove</button>`
      : m.queued
        ? `<button class="btn-q done" disabled>⏳ Queued</button>`
        : canQueueAdd
          ? `<button class="btn-q add" onclick="addMovieToQueue(${JSON.stringify(m).replace(/"/g,'&quot;')},this.closest('.card'))">+ Queue</button>`
          : '';

  // Multi-select checkbox (only in search view, only if can add to queue)
  const selectBox = (currentView === 'search' && canQueueAdd && !m.downloaded && !m.queued)
    ? `<div class="select-check" onclick="event.stopPropagation();toggleSelectItem('${m.stream_id}',${JSON.stringify(m).replace(/"/g,'&quot;')},this.closest('.card'))"></div>`
    : '';

  const isFav = favourites.has('movie:' + m.stream_id);
  const favBtn = `<button class="btn-fav${isFav?' active':''}" onclick="event.stopPropagation();toggleFav('movie','${m.stream_id}','${esc(m.clean_title)}','${esc(m.stream_icon||'')}','${esc(m.category||'')}','${esc(m.container_extension||'mp4')}',this)" title="${isFav?'Aus Favoriten entfernen':'Zu Favoriten hinzufügen'}">♥</button>`;

  const year = m.year ?? '';

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
      <div class="card-meta">${(m.container_extension??'').toUpperCase()}</div>
    </div>
    <div class="card-actions" onclick="event.stopPropagation()">${btn}</div>
  </div>`;
}

// ── Series ────────────────────────────────────────────────────
async function loadSeriesCat(catId, catName, el) {
  setActiveCat(el);
  showView('series');
  document.getElementById('page-title').textContent = catName;
  const grid = document.getElementById('series-grid');
  grid.innerHTML = loadingHTML();
  addCatHistory('series', catId, catName);
  _lastSeries = await api('get_series', {category_id: catId});
  _lastSeries = _lastSeries.map(s => ({...s, category: s.category || catName}));
  _seriesPage = 1;
  renderSeriesGrid(_lastSeries);
}

function renderSeriesGrid(list) {
  const grid = document.getElementById('series-grid');
  if (!list?.length) { grid.innerHTML = emptyHTML('Keine Serien'); document.getElementById('series-pagination').innerHTML = ''; return; }
  let sorted = list;
  if (_seriesSort === 'az') sorted = [...list].sort((a,b) => (a.clean_title||'').localeCompare(b.clean_title||'', 'de'));
  if (_seriesSort === 'za') sorted = [...list].sort((a,b) => (b.clean_title||'').localeCompare(a.clean_title||'', 'de'));
  const pages = Math.ceil(sorted.length / PAGE_SIZE);
  const page  = Math.min(_seriesPage, pages);
  const slice = sorted.slice((page-1)*PAGE_SIZE, page*PAGE_SIZE);
  grid.innerHTML = slice.map(s => seriesCard({...s})).join('');
  lazyLoadImages();
  renderPagination('series-pagination', page, pages, p => { _seriesPage = p; renderSeriesGrid(_lastSeries); });
}

function seriesCard(s) {
  const thumb = s.cover ? `<img data-src="${s.cover}" alt="">` : '';
  const isFav = favourites.has('series:' + s.series_id);
  const favBtn = `<button class="btn-fav${isFav?' active':''}" onclick="event.stopPropagation();toggleFav('series','${s.series_id}','${esc(s.clean_title)}','${esc(s.cover||'')}','${esc(s.category||'')}','',this)" title="${isFav?'Aus Favoriten entfernen':'Zu Favoriten hinzufügen'}">♥</button>`;
  const queueData = {series_id: s.series_id, clean_title: s.clean_title, cover: s.cover || ''};
  return `
  <div class="card"
    data-tmdb-title="${esc(s.clean_title)}" data-tmdb-type="series" data-tmdb-year=""
    data-tmdb-queue="${esc(JSON.stringify(queueData))}"
    onclick="handleCardClick(event,this)" style="cursor:pointer">
    <div class="card-thumb"><div class="card-thumb-placeholder">📺</div>${thumb}${favBtn}</div>
    <div class="card-body"><div class="card-title">${s.clean_title}</div><div class="card-meta">${s.genre??''}</div></div>
    <div class="card-actions" onclick="event.stopPropagation()"><button class="btn-q add" onclick="openSeriesModal(${s.series_id},'${esc(s.clean_title)}','${esc(s.cover||'')}')">📋 Episodes</button></div>
  </div>`;
}

// ── Series Modal ──────────────────────────────────────────────
async function openSeriesModal(id, title, cover) {
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-meta').textContent  = 'Loading…';
  document.getElementById('modal-img').src           = cover || '';
  document.getElementById('modal-body').innerHTML    = `<div class="state-box"><div class="spinner"></div></div>`;
  document.getElementById('series-modal').classList.add('open');
  const data    = await api('get_series_info', {series_id: id});
  const episodes = data.episodes ?? {};
  const seasons  = Object.keys(episodes).sort();
  document.getElementById('modal-meta').textContent = `${seasons.length} Season(s)`;
  if (!seasons.length) { document.getElementById('modal-body').innerHTML = emptyHTML('Keine Episoden'); return; }
  let html = '';
  for (const season of seasons) {
    const eps = episodes[season];
    const seasonNum = parseInt(season, 10) || 1;
    html += `<div class="season-header">Season ${season}
      <span class="season-queue-all" onclick="queueAllSeason(${htmlJson(eps)},${seasonNum},'${esc(title)}')">⏳ All queuen</span>
    </div>`;
    for (const ep of eps) {
      const epBtn = ep.downloaded
        ? canQueueRemove
          ? `<button class="ep-btn done" onclick="resetEpisode('${ep.id}',${htmlJson(ep)},${seasonNum},'${esc(title)}')" title="Zurücksetzen">↺</button>`
          : `<button class="ep-btn done" disabled>✓</button>`
        : ep.queued && (canQueueRemove || canQueueRemoveOwn)
          ? `<button class="ep-btn remove" id="epbtn-${ep.id}" onclick="removeEpFromQueue('${ep.id}',this)">✕</button>`
          : ep.queued
            ? `<button class="ep-btn done" disabled>⏳</button>`
            : canQueueAdd
              ? `<button class="ep-btn add" id="epbtn-${ep.id}" onclick="queueEpisode(${htmlJson(ep)},${seasonNum},'${esc(title)}',this)">+ Q</button>`
              : '';
      html += `
      <div class="episode-row" id="ep-${ep.id}">
        <span class="ep-num">E${ep.episode_num??'?'}</span>
        <span class="ep-title">${ep.clean_title||ep.title}</span>
        <span class="ep-ext">${(ep.container_extension??'').toUpperCase()}</span>
        ${epBtn}
      </div>`;
    }
  }
  document.getElementById('modal-body').innerHTML = html;
}
function closeModal() { document.getElementById('series-modal').classList.remove('open'); }

async function queueEpisode(ep, season, seriesTitle, btn) {
  await queueItem({
    stream_id:           ep.id,
    type:                'episode',
    title:               ep.clean_title || ep.title,
    container_extension: ep.container_extension ?? 'mp4',
    cover:               '',
    dest_subfolder:      'TV Shows',
    category:            seriesTitle,
    season:              season,
  });
  if (btn) { btn.textContent = '✕'; btn.className = 'ep-btn remove'; btn.onclick = () => removeEpFromQueue(ep.id, btn); }
}
async function removeEpFromQueue(id, btn) {
  await apiPost('queue_remove', {stream_id: id});
  if (btn) { btn.textContent = '+ Q'; btn.className = 'ep-btn add'; btn.onclick = null; }
  updateQueueBadge(); loadStats();
}
async function queueAllSeason(eps, season, seriesTitle) {
  let count = 0;
  for (const ep of eps) {
    if (!ep.downloaded && !ep.queued) {
      const result = await queueItem({
        stream_id: ep.id, type: 'episode',
        title: ep.clean_title || ep.title,
        container_extension: ep.container_extension ?? 'mp4',
        cover: '', dest_subfolder: 'TV Shows', category: seriesTitle,
        season: season,
      });
      if (!result) break;
      count++;
      const btn = document.getElementById('epbtn-' + ep.id);
      if (btn) { btn.textContent = '✕'; btn.className = 'ep-btn remove'; }
    }
  }
  if (count > 0) showToast(`${count} Episode(n) zur Queue hinzugefügt`, 'info');
}

// ── Queue Add/Remove ──────────────────────────────────────────
async function addMovieToQueue(m, card) {
  await queueItem({
    stream_id:           m.stream_id,
    type:                'movie',
    title:               m.clean_title,
    container_extension: m.container_extension ?? 'mp4',
    cover:               m.stream_icon ?? '',
    dest_subfolder:      'Movies',
    category:            m.category ?? m._category ?? '',
  });
  if (card) {
    card.classList.add('queued');
    const badge = card.querySelector('.card-badge');
    if (badge) { badge.className = 'card-badge badge-queue'; badge.textContent = '⏳ Queue'; }
    else {
      const b = document.createElement('span');
      b.className = 'card-badge badge-queue'; b.textContent = '⏳ Queue';
      card.querySelector('.card-thumb').appendChild(b);
    }
    const btn = card.querySelector('.btn-q');
    if (btn) {
      btn.textContent = '✕ Remove';
      btn.className = 'btn-q remove';
      btn.onclick = () => removeFromQueue(m.stream_id, card);
    }
  }
  // Update in allMovies
  const idx = allMovies.findIndex(x => String(x.stream_id) === String(m.stream_id));
  if (idx >= 0) allMovies[idx].queued = true;
}

async function removeFromQueue(sid, card) {
  await apiPost('queue_remove', {stream_id: sid});
  if (card) {
    card.classList.remove('queued');
    const badge = card.querySelector('.card-badge');
    if (badge) badge.remove();
    const btn = card.querySelector('.btn-q');
    if (btn) {
      const m = allMovies.find(x => String(x.stream_id) === String(sid));
      btn.textContent = '+ Queue'; btn.className = 'btn-q add';
      if (m) btn.onclick = () => addMovieToQueue(m, card);
    }
  }
  const idx = allMovies.findIndex(x => String(x.stream_id) === String(sid));
  if (idx >= 0) allMovies[idx].queued = false;
  updateQueueBadge(); loadStats();
  showToast('Aus Queue entfernt', 'info');
}

async function queueItem(item) {
  const r = await fetch(API + '?action=queue_add', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(item)
  });
  const d = await r.json();
  if (d.already) {
    if (d.reason === 'on_remote') {
      showToast(`☁️ Bereits auf Remote vorhanden: ${d.filename}`, 'info');
    } else if (d.reason === 'downloaded') {
      showToast('✓ Bereits heruntergeladen', 'info');
    } else {
      showToast('Bereits in der Queue', 'info');
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
  updateQueueBadge(); loadStats();
  return d;
}

// ── Rate Limit Indicator ──────────────────────────────────────
function updateLimitIndicator(remaining) {
  const el = document.getElementById('limit-indicator');
  if (!el) return;
  if (remaining === null) { el.style.display = 'none'; return; }
  el.style.display = '';
  el.textContent   = `${remaining} Queue-Slot${remaining !== 1 ? 's' : ''} verbleibend`;
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
async function refreshQueue() {
  const list = document.getElementById('queue-list');
  if (!list) return;

  const items = await api('get_queue');

  // Global Set für Queue-IDs aktualisieren (für Favoriten und andere Views)
  _queuedIds = new Set(items.filter(i => i.status !== 'done').map(i => String(i.stream_id)));

  if (!items.length) {
    list.innerHTML = `<div class="state-box"><div class="icon">📭</div><p>Queue ist leer</p></div>`;
    return;
  }

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
      const statusLabel = {pending:'Ausstehend', downloading:'Lädt…', done:'Fertig', error:'Fehler'}[item.status] ?? item.status;
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
  const statusLabel = {pending:'Ausstehend', downloading:'Lädt…', done:'Fertig', error:'Fehler'}[item.status] ?? item.status;
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
  const prioLabels = {1:'🔴 Hoch', 2:'🟡 Normal', 3:'🔵 Niedrig'};
  const prio = item.priority ?? 2;
  const prioBtn = canQueueRemove && item.status === 'pending'
    ? `<select class="qi-prio" onchange="setPriority('${item.stream_id}',this.value)" title="Priorität">
        <option value="1"${prio===1?' selected':''}>🔴 Hoch</option>
        <option value="2"${prio===2?' selected':''}>🟡 Normal</option>
        <option value="3"${prio===3?' selected':''}>🔵 Niedrig</option>
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

async function toggleFav(type, sid, title, cover, category, ext, btn) {
  const key = type + ':' + sid;
  const d = await apiPost('favourite_toggle', {stream_id: sid, type, title, cover, category, ext});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  if (d.action === 'added') {
    favourites.add(key);
    favouriteData.push({stream_id: sid, type, title, cover, category, ext, added_at: new Date().toISOString().slice(0,10)});
    btn.classList.add('active');
    showToast('Zu Favoriten hinzugefügt', 'success');
  } else {
    favourites.delete(key);
    favouriteData = favouriteData.filter(f => !(f.type === type && f.stream_id === sid));
    btn.classList.remove('active');
    showToast('Aus Favoriten entfernt', 'info');
  }
  updateFavBadge();
  if (currentView === 'favourites') renderFavourites();
}

let favTab = 'all';
function switchFavTab(tab, el) {
  favTab = tab;
  document.querySelectorAll('#view-favourites .filter-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  renderFavourites();
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
    const sid          = String(f.stream_id);
    const isDownloaded = _downloadedIds.has(sid);
    const isQueued     = !isDownloaded && _queuedIds.has(sid);

    if (f.type === 'movie') {
      if (isDownloaded) {
        actionBtn = canQueueRemove
          ? `<button class="btn-q done" onclick="resetDownload('${sid}','movie',this.closest('.card'))" title="Zurücksetzen">↺ Reset</button>`
          : `<button class="btn-q done" disabled>✓ Done</button>`;
      } else if (isQueued) {
        actionBtn = canQueueRemove || canQueueRemoveOwn
          ? `<button class="btn-q remove" onclick="removeFromQueue('${sid}',this.closest('.card'))">✕ Remove</button>`
          : `<button class="btn-q done" disabled>⏳ Queued</button>`;
      } else if (canQueueAdd) {
        const movieObj = JSON.stringify({
          stream_id: f.stream_id, type: 'movie', title: f.title,
          container_extension: ext, cover: f.cover, category: f.category,
          clean_title: f.title,
        }).replace(/"/g, '&quot;');
        actionBtn = `<button class="btn-q add" onclick="addMovieToQueue(${movieObj},this.closest('.card'))">+ Queue</button>`;
      }
    } else if (f.type === 'series') {
      actionBtn = `<button class="btn-q add" onclick="openSeriesModal('${f.stream_id}','${esc(f.title)}','${esc(f.cover||'')}')">📋 Episodes</button>`;
    }

    return `
    <div class="card">
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
  if (grid) grid.innerHTML = `<div class="state-box" style="grid-column:1/-1"><div class="spinner"></div><p>Lade…</p></div>`;
  _nrData = await api('get_new_releases');
  const meta = document.getElementById('new-releases-meta');
  if (_nrData.generated_at) {
    const total = (_nrData.movies?.length ?? 0) + (_nrData.series?.length ?? 0);
    if (meta) meta.textContent = `${total} neue Titel seit ${_nrData.generated_at}`;
    const badge = document.getElementById('new-releases-badge');
    if (badge) { badge.textContent = total; badge.style.display = total > 0 ? '' : 'none'; }
  } else {
    if (meta) meta.textContent = 'Noch kein Cache-Run durchgeführt';
  }
  renderNewReleases();
}

function renderNewReleases() {
  const grid = document.getElementById('new-releases-grid');
  if (!grid || !_nrData) return;
  let movies = _nrData.movies ?? [];
  let series = _nrData.series ?? [];
  let items  = [];
  if (_nrTab === 'all')    items = [...movies, ...series];
  if (_nrTab === 'movie')  items = movies;
  if (_nrTab === 'series') items = series;
  if (!items.length) { grid.innerHTML = emptyHTML('Keine neuen Titel'); return; }

  grid.innerHTML = items.map(item => {
    const isSeries = item.type === 'series';
    const itemId   = String(isSeries ? (item.series_id ?? item.id) : (item.stream_id ?? item.id));
    const dismissBtn = `<button class="btn-icon" title="Entfernen"
      onclick="dismissNewRelease('${itemId}','${isSeries?'series':'movie'}',this)"
      style="position:absolute;top:4px;left:4px;z-index:10;background:rgba(0,0,0,.7);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:.65rem;padding:0;border:none;cursor:pointer;color:#fff">✕</button>`;

    if (isSeries) {
      const card = seriesCard({
        series_id:   item.series_id,
        clean_title: item.clean_title ?? item.title ?? '',
        cover:       item.cover ?? '',
        category:    item.category ?? '',
        genre:       item.genre ?? '',
      });
      // Inject dismiss button into card-thumb
      return card.replace('<div class="card-thumb">', `<div class="card-thumb">${dismissBtn}`);
    }
    // Movie
    const sid = String(item.stream_id ?? item.id);
    const card = movieCard({
      stream_id:           sid,
      clean_title:         item.clean_title ?? item.title ?? '',
      stream_icon:         item.cover ?? '',
      container_extension: item.ext ?? 'mp4',
      category:            item.category ?? '',
      downloaded:          _downloadedIds.has(sid),
      queued:              _queuedIds.has(sid),
      year:                item.year ?? '',
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
  // Remove from local data and re-render
  if (_nrData) {
    if (type === 'series') {
      _nrData.series = (_nrData.series ?? []).filter(s => String(s.series_id ?? s.id) !== id);
    } else {
      _nrData.movies = (_nrData.movies ?? []).filter(m => String(m.stream_id ?? m.id) !== id);
    }
    // Update badge
    const total = (_nrData.movies?.length ?? 0) + (_nrData.series?.length ?? 0);
    const badge = document.getElementById('new-releases-badge');
    if (badge) { badge.textContent = total; badge.style.display = total > 0 ? '' : 'none'; }
    const meta = document.getElementById('new-releases-meta');
    if (meta && _nrData.generated_at) meta.textContent = `${total} neue Titel seit ${_nrData.generated_at}`;
  }
  if (card) card.remove();
}

async function removeQueueItem(sid, el) {
  const r = await fetch(`${API}?action=queue_remove`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({stream_id: sid})
  });
  const d = await r.json();
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  if (el) el.remove();
  updateQueueBadge(); loadStats();
  showToast('Entfernt', 'info');
}

async function setPriority(sid, priority) {
  const r = await fetch(`${API}?action=set_priority`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({stream_id: sid, priority: parseInt(priority)})
  });
  const d = await r.json();
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast('Priorität geändert', 'info');
  refreshQueue();
}

async function resetEpisode(sid, ep, season, seriesTitle) {
  if (!confirm('Episode zurücksetzen?\n\nSie kann danach neu zur Queue hinzugefügt werden.')) return;
  const d = await apiPost('reset_download', {stream_id: sid, type: 'episode'});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast('↺ Zurückgesetzt', 'success');
  // Button in Modal auf + Q umschalten
  const btn = document.getElementById('epbtn-' + sid);
  const epRow = document.getElementById('ep-' + sid);
  if (epRow) {
    const newBtn = document.createElement('button');
    newBtn.className = 'ep-btn add';
    newBtn.id = 'epbtn-' + sid;
    newBtn.textContent = '+ Q';
    newBtn.onclick = () => queueEpisode(ep, season, seriesTitle, newBtn);
    epRow.querySelector('button')?.replaceWith(newBtn);
  }
  updateQueueBadge();
}

async function resetDownload(sid, type, rowEl) {
  if (!confirm('Download zurücksetzen?\n\nDas Item wird aus der "Heruntergeladen"-Liste entfernt und kann neu zur Queue hinzugefügt werden.')) return;
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

async function retryQueueItem(sid) {
  const r = await fetch(`${API}?action=queue_retry`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({stream_id: sid})
  });
  const d = await r.json();
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast('Wird erneut versucht', 'success');
  refreshQueue();
}
async function clearDone() {
  await api('queue_clear_done');
  refreshQueue(); updateQueueBadge(); loadStats();
  showToast('Erledigte Einträge entfernt', 'success');
}
async function clearAll() {
  if (!confirm('Wirklich die gesamte Queue löschen?')) return;
  await api('queue_clear_all');
  refreshQueue(); updateQueueBadge(); loadStats();
  showToast('Queue geleert', 'success');
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
  const q = document.getElementById('search-input').value.trim();
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
    const cacheKey = 'movies:' + q;
    let results, source;
    if (_searchCache.has(cacheKey)) {
      ({results, source} = _searchCache.get(cacheKey));
    } else {
      const d = await api('search_movies', {q});
      // Neues Format: {results, source} — Fallback auf altes Array-Format
      results = d.results ?? d;
      source  = d.source ?? 'xtream';
      _searchCache.set(cacheKey, {results, source});
    }
    _lastMovies = results;
    if (movGrid) {
      const sourceHint = source === 'cache'
        ? `<div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);margin-bottom:10px">📦 Aus lokalem Cache · <span style="cursor:pointer;color:var(--accent2)" onclick="clearSearchCache();doSearch('${esc(q)}')">Aktualisieren</span></div>`
        : '';
      if (!results.length) {
        movGrid.innerHTML = emptyHTML('Keine Treffer');
      } else {
        movGrid.innerHTML = sourceHint + results.map(movieCard).join('');
        lazyLoadImages();
      }
    }
  } else {
    if (serGrid) serGrid.innerHTML = loadingHTML();
    const cacheKey = 'series:' + q;
    let results, source;
    if (_searchCache.has(cacheKey)) {
      ({results, source} = _searchCache.get(cacheKey));
    } else {
      const d = await api('search_series', {q});
      results = d.results ?? d;
      source  = d.source ?? 'xtream';
      _searchCache.set(cacheKey, {results, source});
    }
    if (serGrid) {
      const sourceHint = source === 'cache'
        ? `<div style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--muted);margin-bottom:10px">📦 Aus lokalem Cache · <span style="cursor:pointer;color:var(--accent2)" onclick="clearSearchCache();doSearch('${esc(q)}')">Aktualisieren</span></div>`
        : '';
      if (!results.length) {
        serGrid.innerHTML = emptyHTML('Keine Treffer');
      } else {
        serGrid.innerHTML = sourceHint + results.map(seriesCard).join('');
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
  const sb = document.getElementById('search-bar');
  const fb = document.getElementById('filter-bar');
  sb.style.display = v === 'search'  ? '' : 'none';
  fb.style.display = v === 'movies'  ? '' : 'none';
  if (v === 'search')       { document.getElementById('page-title').textContent = 'Suche'; initSearch(); document.getElementById('search-input').focus(); renderSearchHistory(); }
  if (v === 'dashboard')    { document.getElementById('page-title').textContent = 'Dashboard'; <?php if (!$can_settings): ?>loadUserDashboard();<?php endif; ?> <?php if ($can_settings): ?>startDashboardPolling();<?php endif; ?> }
  if (v === 'queue')        { document.getElementById('page-title').textContent = 'Download Queue'; refreshQueue(); startProgressPolling(); }
  if (v === 'log')          { document.getElementById('page-title').textContent = 'Cron Log'; startLogPolling(); stopProgressPolling(); }
  if (v === 'settings')     { document.getElementById('page-title').textContent = 'Einstellungen'; <?php if ($can_settings): ?>loadConfig(); loadCacheStatus(); loadApiKeys(); loadMaintenance(); loadBackups(); loadServers();<?php endif; ?> }
  if (v === 'users')        { document.getElementById('page-title').textContent = 'Benutzer'; loadUsers(); <?php if ($can_users): ?>loadInvites();<?php endif; ?> }
  if (v === 'activity-log') { document.getElementById('page-title').textContent = 'Aktivitätslog'; loadActivityLog(); }
  if (v === 'profile')      { document.getElementById('page-title').textContent = 'Mein Profil'; document.getElementById('profile-msg').className = 'settings-msg'; const tp = document.getElementById('theme-picker'); if (tp) tp.innerHTML = renderThemePicker(); }
  if (v === 'favourites')    { document.getElementById('page-title').textContent = 'Favoriten'; renderFavourites(); loadStats(); updateQueueBadge(); }
  if (v === 'new-releases')  { document.getElementById('page-title').textContent = 'Neue Releases'; loadNewReleases(); }
  if (v === 'api-docs')     { document.getElementById('page-title').textContent = 'API-Dokumentation'; }
  if (v === 'stats')        { document.getElementById('page-title').textContent = 'Statistiken'; loadStatsView(); }
  clearInterval(queueRefreshInterval);
  if (v === 'queue') {
    // Progress- und Queue-Polling starten (unified — kein separates Queue-Interval nötig)
    // queueRefreshInterval bleibt leer, refreshQueue läuft über startProgressPolling
  }
  if (v !== 'queue' && v !== 'dashboard') stopProgressPolling();
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
    el.innerHTML = `<div style="color:var(--muted)">Noch keine Backups vorhanden.</div>`;
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
  if (!confirm(`Backup "${name}" wiederherstellen?\n\nDie aktuellen Daten (Users, Queue, Config etc.) werden überschrieben. Diese Aktion kann nicht rückgängig gemacht werden.`)) return;
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
  if (!confirm(`Backup "${name}" löschen?`)) return;
  const d = await apiPost('backup_delete', {name});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  row?.remove();
  showToast('Backup gelöscht', 'info');
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
    status.textContent = '🔴 Wartungsmodus AKTIV';
    status.style.color = 'var(--red)';
    status.style.borderColor = 'rgba(255,71,87,.3)';
    btn.textContent = 'Wartungsmodus deaktivieren';
    btn.className = 'btn-secondary danger';
  } else {
    status.textContent = '🟢 Normal — Seite erreichbar';
    status.style.color = 'var(--green)';
    status.style.borderColor = 'rgba(46,213,115,.2)';
    btn.textContent = 'Wartungsmodus aktivieren';
    btn.className = 'btn-secondary';
  }
}

async function toggleMaintenance() {
  const current = document.getElementById('maintenance-status')?.textContent?.includes('AKTIV');
  if (!current && !confirm('Wartungsmodus aktivieren? Alle nicht-Admin-User werden ausgesperrt.')) return;
  const action = current ? 'maintenance_disable' : 'maintenance_enable';
  const d = await fetch(`${API}?action=${action}`, {method:'POST'});
  const r = await d.json();
  if (r.error) { showToast('❌ ' + r.error, 'error'); return; }
  applyMaintenanceStatus(!current);
  showToast(current ? 'Wartungsmodus deaktiviert' : 'Wartungsmodus aktiviert', current ? 'success' : 'info');
}

// ── Server-Verwaltung ─────────────────────────────────────────
async function loadServers() {
  const list = document.getElementById('saved-servers-list');
  if (!list) return;
  const servers = await api('list_servers');
  if (!servers?.length) {
    list.innerHTML = `<div style="color:var(--muted);font-size:.8rem">Noch keine gespeicherten Server</div>`;
    return;
  }
  list.innerHTML = servers.map(s => `
    <div style="display:flex;align-items:center;gap:8px;background:var(--bg3);border:1px solid ${s.active ? 'var(--accent)' : 'var(--border)'};border-radius:6px;padding:8px 12px">
      <div style="flex:1;min-width:0">
        <div style="font-size:.85rem;font-weight:500;${s.active ? 'color:var(--accent)' : ''}">${esc(s.name)}${s.active ? ' <span style="font-size:.6rem;font-family:\'DM Mono\',monospace;opacity:.7">(aktiv)</span>' : ''}</div>
        <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted)">${esc(s.server_ip)}:${esc(s.port)} · ${esc(s.username)} · ID: ${esc(s.id)}</div>
      </div>
      <div style="display:flex;gap:4px;flex-shrink:0">
        ${!s.active ? `<button class="btn-icon" onclick="switchServer('${esc(s.id)}','${esc(s.name)}')">↗ Wechseln</button>` : ''}
        <button class="btn-icon" onclick="renameServer('${esc(s.id)}','${esc(s.name)}')">✏️</button>
        ${!s.active ? `<button class="btn-icon danger" onclick="deleteServer('${esc(s.id)}','${esc(s.name)}')">✕</button>` : ''}
      </div>
    </div>`).join('');
}

async function switchServer(serverId, name) {
  if (!confirm(`Zu Server "${name}" wechseln?`)) return;
  const d = await apiPost('switch_server', {server_id: serverId});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast(`✅ Gewechselt zu "${name}" — Seite wird neu geladen`, 'success');
  setTimeout(() => location.reload(), 1200);
}

async function renameServer(serverId, currentName) {
  const name = prompt('Neuer Name für diesen Server:', currentName);
  if (!name || name.trim() === '') return;
  const d = await apiPost('save_server', {server_id: serverId, name: name.trim()});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast('✅ Umbenannt', 'success');
  loadServers();
}

async function deleteServer(serverId, name) {
  if (!confirm(`Server "${name}" wirklich löschen?\n\nDownloads und Queue dieses Servers bleiben erhalten.`)) return;
  const d = await apiPost('delete_server', {server_id: serverId});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast('🗑 Server entfernt', 'info');
  loadServers();
}

async function loadConfig() {
  const c = await api('get_config');
  document.getElementById('cfg-server-ip').value    = c.server_ip     ?? '';
  document.getElementById('cfg-port').value          = c.port          ?? '80';
  document.getElementById('cfg-username').value      = c.username      ?? '';
  document.getElementById('cfg-password').value      = '';
  document.getElementById('cfg-dest-path').value     = c.dest_path     ?? '';
  document.getElementById('cfg-password').placeholder = c.password ? '(gesetzt – leer lassen zum Beibehalten)' : 'Xtream Passwort';
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
  document.getElementById('cfg-tmdb-api-key').placeholder = c.tmdb_api_key === '••••••••' ? 'Gespeichert — leer lassen um zu behalten' : 'Leer lassen um TMDB zu deaktivieren';
  const sidEl = document.getElementById('cfg-server-id-display');
  if (sidEl && c.server_id) sidEl.textContent = c.server_id;
  const tgToken = document.getElementById('cfg-telegram-bot-token');
  const tgChat  = document.getElementById('cfg-telegram-chat-id');
  if (tgToken) { tgToken.value = c.telegram_bot_token ?? ''; tgToken.placeholder = c.telegram_bot_token === '••••••••' ? 'Gespeichert — leer lassen um zu behalten' : 'Leer lassen um Telegram zu deaktivieren'; }
  if (tgChat)  tgChat.value = c.telegram_chat_id ?? '';
  const tgEnabled = document.getElementById('cfg-telegram-enabled');
  if (tgEnabled) tgEnabled.checked = c.telegram_enabled ?? false;
  const vpnEnabled = document.getElementById('cfg-vpn-enabled');
  const vpnIface   = document.getElementById('cfg-vpn-interface');
  if (vpnEnabled) vpnEnabled.checked  = c.vpn_enabled    ?? false;
  if (vpnIface)   vpnIface.value      = c.vpn_interface  ?? 'wg0';
  setSettingsMsg('', '');
}

function toggleRcloneFields(enabled) {
  const fields = document.getElementById('rclone-fields');
  if (fields) fields.style.display = enabled ? '' : 'none';
  if (enabled) loadRcloneCacheStatus();
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
  btn.textContent = '⏳ Lade…';
  if (statusEl) statusEl.textContent = '⏳ Lade Dateiliste vom Remote… (kann je nach Größe etwas dauern)';
  const d = await api('rclone_cache_refresh');
  btn.disabled = false;
  btn.textContent = '🗂 Remote-Cache aktualisieren';
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
    server_ip:      document.getElementById('cfg-server-ip').value.trim(),
    port:           document.getElementById('cfg-port').value.trim() || '80',
    username:       document.getElementById('cfg-username').value.trim(),
    password:       document.getElementById('cfg-password').value,
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
    vpn_enabled:           document.getElementById('cfg-vpn-enabled')?.checked  ?? false,
    vpn_interface:         document.getElementById('cfg-vpn-interface')?.value.trim() ?? 'wg0',
  };
}

// ── Updates ───────────────────────────────────────────────────
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
      <div style="color:var(--muted);margin-top:4px">Lokaler Commit: <span style="color:var(--text)">${esc(d.local_commit)}</span></div>
      <div style="color:var(--muted)">Remote Commit: <span style="color:var(--text)">${esc(d.remote_commit)}</span>${dateStr}</div>`;
  } else {
    statusEl.innerHTML = `
      <div style="color:var(--orange)">🆕 Update verfügbar</div>
      <div style="color:var(--muted);margin-top:4px">Lokaler Commit: <span style="color:var(--text)">${esc(d.local_commit)}</span></div>
      <div style="color:var(--muted)">Remote Commit: <span style="color:var(--accent2)">${esc(d.remote_commit)}</span>${dateStr}</div>
      ${d.remote_message ? `<div style="color:var(--muted);margin-top:4px">💬 ${esc(d.remote_message)}</div>` : ''}`;
    updateBtn.style.display = '';
  }
}

async function runUpdate(btn) {
  const msgEl  = document.getElementById('update-msg');
  const logEl  = document.getElementById('update-log');
  const status = document.getElementById('update-status');

  if (!confirm('Update installieren?\n\nEin Backup von data/ wird automatisch erstellt.\nDie Seite wird danach neu geladen.')) return;

  btn.disabled = true;
  msgEl.textContent = '⏳ Update läuft…'; msgEl.className = 'settings-msg info';
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
let _vpnDurationTimer = null;
let _vpnConnectedSince = null;

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
  badge.style.display = '';

  if (!status.wg_installed) {
    badge.textContent = '⚠️ VPN';
    badge.style.background   = 'rgba(255,71,87,.15)';
    badge.style.color        = 'var(--red)';
    badge.style.borderColor  = 'rgba(255,71,87,.3)';
    badge.title = 'WireGuard nicht installiert';
    if (statsCard) statsCard.style.display = 'none';
    return;
  }

  if (status.up) {
    badge.textContent = '🔒 VPN';
    badge.style.background  = 'rgba(46,213,115,.15)';
    badge.style.color       = 'var(--green)';
    badge.style.borderColor = 'rgba(46,213,115,.3)';
    badge.title = `VPN aktiv (${status.interface})${status.public_ip ? ' · ' + status.public_ip : ''}`;

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
      } else if (sinceEl) {
        sinceEl.textContent = '–';
      }
    }
  } else {
    badge.textContent = '🔓 VPN';
    badge.style.background  = 'rgba(255,159,67,.12)';
    badge.style.color       = 'var(--orange)';
    badge.style.borderColor = 'rgba(255,159,67,.25)';
    badge.title = `VPN inaktiv (${status.interface})`;
    if (statsCard) statsCard.style.display = 'none';
    if (_vpnDurationTimer) { clearInterval(_vpnDurationTimer); _vpnDurationTimer = null; }
  }
}

async function pollVpnStatus() {
  // Nur abfragen wenn VPN aktiviert ist
  const d = await api('vpn_status').catch(() => null);
  if (d && !d.error) updateVpnBadge(d);
}

function startVpnPolling() {
  pollVpnStatus();
  if (!_vpnPollInterval) _vpnPollInterval = setInterval(pollVpnStatus, 30000);
}

async function checkVpnStatus() {
  const msg = document.getElementById('vpn-status-msg');
  msg.textContent = '⏳ Prüfe…'; msg.className = 'settings-msg info';
  const d = await api('vpn_status');
  if (d.error) { msg.textContent = '❌ ' + d.error; msg.className = 'settings-msg err'; return; }
  updateVpnBadge(d);
  if (!d.wg_installed) {
    msg.textContent = '⚠️ WireGuard nicht installiert — sudo apt install wireguard';
    msg.className = 'settings-msg err'; return;
  }
  const upText = d.up ? '🟢 Aktiv' : '🔴 Inaktiv';
  const ipText = d.public_ip ? ` · IP: ${d.public_ip}` : '';
  msg.textContent = `${upText} (${d.interface})${ipText}`;
  msg.className = d.up ? 'settings-msg ok' : 'settings-msg err';
}
async function vpnConnect() {
  const msg = document.getElementById('vpn-status-msg');
  msg.textContent = '⏳ Verbinde…'; msg.className = 'settings-msg info';
  const d = await apiPost('vpn_connect', {});
  if (d.error) { msg.textContent = '❌ ' + d.error; msg.className = 'settings-msg err'; return; }
  msg.textContent = '🟢 Verbunden'; msg.className = 'settings-msg ok';
  pollVpnStatus();
}
async function vpnDisconnect() {
  const msg = document.getElementById('vpn-status-msg');
  msg.textContent = '⏳ Trenne…'; msg.className = 'settings-msg info';
  const d = await apiPost('vpn_disconnect', {});
  if (d.error) { msg.textContent = '❌ ' + d.error; msg.className = 'settings-msg err'; return; }
  msg.textContent = '🔴 Getrennt'; msg.className = 'settings-msg ok';
  pollVpnStatus();
}

async function testTelegram() {
  const msgEl = document.getElementById('telegram-test-msg');
  msgEl.textContent = '⏳ Sende…'; msgEl.className = 'settings-msg info';
  const cfg = collectConfig();
  const d = await apiPost('telegram_test', {
    bot_token: cfg.telegram_bot_token,
    chat_id:   cfg.telegram_chat_id,
  });
  if (d.error) {
    msgEl.textContent = '❌ ' + d.error; msgEl.className = 'settings-msg err';
  } else {
    msgEl.textContent = '✅ Testnachricht gesendet'; msgEl.className = 'settings-msg ok';
  }
}

async function testRclone() {
  const msgEl = document.getElementById('rclone-test-msg');
  msgEl.textContent = '⏳ Teste…'; msgEl.className = 'settings-msg info';
  const cfg = collectConfig();
  const r = await fetch(`${API}?action=rclone_test`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({rclone_bin: cfg.rclone_bin, rclone_remote: cfg.rclone_remote})
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
    body: JSON.stringify({...cfg, test_connection: true})
  });
  const d = await r.json();
  if (d.error) {
    setSettingsMsg('❌ ' + d.error, 'err');
  } else {
    setSettingsMsg(`✅ Verbindung erfolgreich — ${d.categories} Kategorien gefunden`, 'ok');
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
    body: JSON.stringify({...cfg, save: true})
  });
  const d = await r.json();
  btn.disabled = false;
  if (d.error) {
    setSettingsMsg('❌ ' + d.error, 'err');
  } else {
    if (d.server_changed) {
      setSettingsMsg('✅ Gespeichert — Server gewechselt, neue Datenbasis aktiv', 'ok');
      showToast('🔄 Server gewechselt — Downloads, Queue und Cache sind jetzt server-spezifisch', 'info');
    } else {
      setSettingsMsg('✅ Einstellungen gespeichert', 'ok');
    }
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
  const ds = document.getElementById('dash-server');
  const du = document.getElementById('dash-user');
  const dd = document.getElementById('dash-dest');
  if (ds) ds.textContent = c.server_ip ? `${c.server_ip}:${c.port}` : '–';
  if (du) du.textContent = c.username  ?? '–';
  if (dd) dd.textContent = c.dest_path || (c.rclone_enabled ? c.rclone_remote + ':' + c.rclone_path : '–');
  if (ub) ub.style.display = c.configured ? 'none' : '';
  if (c.configured) {
    loadServerInfo();
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
      actionHtml = `<button class="btn-primary" onclick="closeTmdbModal();openSeriesModal('${queueData.series_id}','${esc(queueData.clean_title)}','${esc(queueData.cover||'')}')">📋 Episodes</button>`;
    } else if (_downloadedIds.has(sid) && canQueueRemove) {
      actionHtml = `<button class="btn-secondary" onclick="closeTmdbModal();resetDownload('${sid}','movie',null)">↺ Reset</button>`;
    } else if (_downloadedIds.has(sid)) {
      actionHtml = `<button class="btn-secondary" disabled>✓ Heruntergeladen</button>`;
    } else if (_queuedIds.has(sid) && (canQueueRemove || canQueueRemoveOwn)) {
      actionHtml = `<button class="btn-secondary" onclick="closeTmdbModal();removeFromQueue('${sid}',null)">✕ Aus Queue entfernen</button>`;
    } else if (_queuedIds.has(sid)) {
      actionHtml = `<button class="btn-secondary" disabled>⏳ In Queue</button>`;
    } else if (canQueueAdd) {
      const qd = JSON.stringify(queueData).replace(/"/g,'&quot;');
      actionHtml = `<button class="btn-primary" onclick="closeTmdbModal();addMovieToQueue(${qd},null)">+ Queue</button>`;
    }
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
    const sid = String(queueData.stream_id ?? '');
    const ext = queueData.container_extension ?? queueData.ext ?? 'mp4';
    if (sid) loadStreamInfo(sid, 'movie', ext);
  }
}

async function loadStreamInfo(sid, type, ext) {
  const infoEl   = document.getElementById('tmdb-stream-info');
  const badgesEl = document.getElementById('tmdb-stream-badges');
  if (!infoEl || !badgesEl) return;

  // Lade-Indikator
  infoEl.style.display = '';
  badgesEl.innerHTML = `<span style="font-family:'DM Mono',monospace;font-size:.68rem;color:var(--muted)">⏳ Analysiere Stream…</span>`;

  const d = await api('stream_info', {stream_id: sid, type, ext});

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
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)">Noch keine API-Keys angelegt</td></tr>';
    return;
  }
  tbody.innerHTML = keys.map(k => `
    <tr>
      <td><strong>${esc(k.name)}</strong></td>
      <td><span class="key-preview">${esc(k.key_preview)}</span></td>
      <td><span class="role-badge ${k.active ? 'badge-active' : 'badge-inactive'}">${k.active ? 'Aktiv' : 'Widerrufen'}</span></td>
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
    body: JSON.stringify({name})
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
  if (!confirm('API-Key widerrufen? Er kann danach nicht mehr verwendet werden.')) return;
  await fetch(`${API}?action=revoke_api_key`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id})
  });
  showToast('API-Key widerrufen', 'info');
  loadApiKeys();
}

async function deleteApiKey(id) {
  if (!confirm('API-Key endgültig löschen?')) return;
  await fetch(`${API}?action=delete_api_key`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id})
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
      disk.innerHTML = `<div style="font-size:.85rem">☁️ rclone</div><div style="color:var(--muted);font-size:.75rem;margin-top:4px">${esc(d.disk.remote)}</div>`;
    } else {
      const pct  = d.disk.percent ?? 0;
      const col  = pct > 90 ? 'var(--red)' : pct > 75 ? 'var(--orange)' : 'var(--green)';
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

  // System-Status
  const sys = document.getElementById('dash-system');
  if (sys && d.system) {
    const s = d.system;
    sys.innerHTML = `
      <div style="display:flex;justify-content:space-between"><span style="color:var(--muted)">PHP</span><span>${esc(s.php_version)}</span></div>
      <div style="display:flex;justify-content:space-between"><span style="color:var(--muted)">RAM</span><span>${fmtBytes(s.mem_used)}</span></div>
      ${s.uptime ? `<div style="display:flex;justify-content:space-between"><span style="color:var(--muted)">Uptime</span><span>${esc(s.uptime)}</span></div>` : ''}
      <div style="display:flex;justify-content:space-between"><span style="color:var(--muted)">Downloads</span><span style="color:var(--green)">${d.total_downloaded ?? 0}</span></div>`;
  }

  // Letzte Downloads
  const recent = document.getElementById('dash-recent');
  if (recent) {
    if (!d.recent_downloads?.length) {
      recent.innerHTML = `<div style="padding:20px;text-align:center;color:var(--muted);font-size:.8rem">Noch keine Downloads</div>`;
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

// ── Dashboard Schnellzugriff ──────────────────────────────────
async function cancelDownload() {
  if (!confirm('Laufenden Download wirklich abbrechen?')) return;
  const d = await fetch(`${API}?action=queue_cancel`, {method:'POST'});
  const r = await d.json();
  if (r.error) { showToast('❌ ' + r.error, 'error'); return; }
  showToast('Abbruch-Signal gesendet — Download wird gestoppt…', 'info');
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
  loadDashboardData();
}
async function dashClearAll() {
  if (!confirm('Wirklich die gesamte Queue löschen?')) return;
  const r = await fetch(`${API}?action=queue_clear_all`, {method:'POST'});
  showToast('Queue geleert', 'info');
  loadDashboardData();
}
// ── Xtream Server Info ────────────────────────────────────────
async function loadServerInfo() {
  const d = await api('get_server_info');
  if (d.error) return;
  const u = d.user   ?? {};
  const s = d.server ?? {};

  const set = (id, val, cls = '') => {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = val;
    if (cls) el.className = 'dic-val ' + cls;
  };

  const statusCls = u.status === 'Active' ? 'ok' : u.status === 'Banned' ? 'error' : 'warn';
  set('si-status',  u.status  || '–', statusCls);

  // Ablaufdatum: Unix-Timestamp → lesbares Datum
  if (u.exp_date && u.exp_date !== '0') {
    const d = new Date(parseInt(u.exp_date) * 1000);
    const now = Date.now();
    const daysLeft = Math.ceil((d - now) / 86400000);
    const dateStr = d.toLocaleDateString('de-DE');
    const cls = daysLeft < 7 ? 'error' : daysLeft < 30 ? 'warn' : 'ok';
    set('si-exp', `${dateStr} (${daysLeft}d)`, cls);
  } else {
    set('si-exp', 'Unbegrenzt', 'ok');
  }

  set('si-cons',    `${u.active_cons ?? '–'} / ${u.max_connections ?? '–'}`);
  set('si-trial',   u.is_trial === '1' ? 'Ja' : 'Nein');
  set('si-formats', Array.isArray(u.allowed_output_formats) ? u.allowed_output_formats.join(', ') : '–');
  set('si-tz',      s.timezone || '–');
  set('si-time',    s.time_now || '–');
  set('si-proto',   s.server_protocol || '–');
}

// ── Statistiken ───────────────────────────────────────────────
<?php if ($can_settings): ?>
let _statsChart     = null;
let _statsChartCnt  = null;
let _statsChartCats = null;

async function loadStatsView() {
  const d = await api('stats_data');
  if (d.error) return;

  // Gesamt-KPIs
  document.getElementById('stats-total-count').textContent = (d.total_count ?? 0).toLocaleString();
  document.getElementById('stats-total-gb').textContent    = fmtBytes(d.total_bytes ?? 0);

  const months  = Object.keys(d.by_month ?? {});
  const gbVals  = months.map(m => +((d.by_month[m].bytes / 1073741824).toFixed(2)));
  const cntVals = months.map(m => d.by_month[m].count);
  const labels  = months.map(m => {
    const [y, mo] = m.split('-');
    return new Date(y, mo - 1).toLocaleDateString('de-DE', {month: 'short', year: '2-digit'});
  });

  const monoFont = { family: 'DM Mono', size: 10 };
  const gridColor = 'rgba(255,255,255,.05)';

  // ── GB pro Monat ──────────────────────────────────────────────
  const ctxGb = document.getElementById('stats-chart-gb')?.getContext('2d');
  if (ctxGb) {
    if (_statsChart) _statsChart.destroy();
    _statsChart = new Chart(ctxGb, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'GB',
          data: gbVals,
          backgroundColor: 'rgba(100,210,255,.25)',
          borderColor:     'rgba(100,210,255,.8)',
          borderWidth: 1, borderRadius: 4,
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: c => `${c.parsed.y.toFixed(2)} GB` } }
        },
        scales: {
          x: { ticks: { color: 'var(--muted)', font: monoFont }, grid: { color: gridColor } },
          y: { ticks: { color: 'rgba(100,210,255,.8)', font: monoFont, callback: v => v + ' GB' }, grid: { color: gridColor } },
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
      data: {
        labels,
        datasets: [{
          label: 'Downloads',
          data: cntVals,
          backgroundColor: 'rgba(255,159,67,.25)',
          borderColor:     'rgba(255,159,67,.8)',
          borderWidth: 1, borderRadius: 4,
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: c => `${c.parsed.y} Downloads` } }
        },
        scales: {
          x: { ticks: { color: 'var(--muted)', font: monoFont }, grid: { color: gridColor } },
          y: { ticks: { color: 'rgba(255,159,67,.8)', font: monoFont, stepSize: 1 }, grid: { color: gridColor } },
        }
      }
    });
  }

  // ── Top Kategorien ────────────────────────────────────────────
  const ctxCats = document.getElementById('stats-chart-cats')?.getContext('2d');
  if (ctxCats && d.top_categories) {
    const catEntries = Object.entries(d.top_categories);
    const catLabels  = catEntries.map(([k]) => k);
    const catCounts  = catEntries.map(([, v]) => v.count);
    const catBytes   = catEntries.map(([, v]) => +((v.bytes / 1073741824).toFixed(2)));

    if (_statsChartCats) _statsChartCats.destroy();
    _statsChartCats = new Chart(ctxCats, {
      type: 'bar',
      data: {
        labels: catLabels,
        datasets: [{
          label: 'Downloads',
          data: catCounts,
          backgroundColor: catCounts.map((_, i) =>
            `hsla(${200 + i * 12}, 70%, 60%, 0.3)`),
          borderColor: catCounts.map((_, i) =>
            `hsla(${200 + i * 12}, 70%, 60%, 0.9)`),
          borderWidth: 1, borderRadius: 4,
          yAxisID: 'y',
        }, {
          label: 'GB',
          data: catBytes,
          type: 'line',
          borderColor:     'rgba(232,255,71,.7)',
          backgroundColor: 'rgba(232,255,71,.1)',
          borderWidth: 2, pointRadius: 3, tension: 0.3,
          yAxisID: 'y2', fill: false,
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { labels: { color: 'var(--text)', font: { family: 'DM Mono', size: 11 } } },
          tooltip: {
            callbacks: {
              label: c => c.dataset.label === 'GB'
                ? `${c.parsed.x.toFixed(2)} GB`
                : `${c.parsed.x} Downloads`
            }
          }
        },
        scales: {
          y:  { ticks: { color: 'var(--text)', font: { family: 'DM Mono', size: 10 } }, grid: { color: gridColor } },
          x:  { position: 'bottom', ticks: { color: 'var(--muted)', font: monoFont }, grid: { color: gridColor } },
          y2: { position: 'right',  ticks: { color: 'rgba(232,255,71,.8)', font: monoFont, callback: v => v + ' GB' }, grid: { drawOnChartArea: false } },
        }
      }
    });
  }

  // Top User Tabelle
  const topEl = document.getElementById('stats-top-users');
  if (topEl && d.top_users) {
    const entries = Object.entries(d.top_users);
    if (!entries.length) {
      topEl.innerHTML = `<div style="color:var(--muted);font-size:.8rem">Noch keine Daten vorhanden</div>`;
    } else {
      const maxCount = entries[0]?.[1]?.count ?? 1;
      topEl.innerHTML = entries.map(([user, data], i) => {
        const pct = Math.round((data.count / maxCount) * 100);
        const medal = i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `#${i+1}`;
        return `
        <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border)">
          <span style="font-size:.95rem;width:28px;text-align:center;flex-shrink:0">${medal}</span>
          <div style="flex:1;min-width:0">
            <div style="font-weight:500;font-size:.85rem">${esc(user)}</div>
            <div style="height:4px;background:var(--bg3);border-radius:2px;margin-top:5px;overflow:hidden">
              <div style="height:100%;width:${pct}%;background:var(--accent);border-radius:2px;transition:width .4s"></div>
            </div>
          </div>
          <div style="text-align:right;flex-shrink:0">
            <div style="font-family:'DM Mono',monospace;font-size:.8rem">${data.count.toLocaleString()} Downloads</div>
            ${data.bytes ? `<div style="font-family:'DM Mono',monospace;font-size:.68rem;color:var(--muted)">${fmtBytes(data.bytes)}</div>` : ''}
          </div>
        </div>`;
      }).join('');
    }
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
  applyProgress(p, 'pc-', 'progress-card');        // Queue-view card
  applyProgress(p, 'dash-pc-', 'dash-progress-card'); // Dashboard card
}

function applyProgress(p, prefix, cardId) {
  const card = document.getElementById(cardId);
  if (!card) return;
  if (!p.active) { card.classList.remove('active'); return; }
  card.classList.add('active');
  const set = (id, val) => { const el = document.getElementById(prefix + id); if (el) el.textContent = val; };
  const setW = (id, val) => { const el = document.getElementById(prefix + id); if (el) el.style.width = val; };
  const setA = (id, val) => { const el = document.getElementById(prefix + id); if (el) el.style.animation = val; };
  set('title', p.title ?? '–');
  set('pos',   p.queue_total > 1 ? `${p.queue_pos} / ${p.queue_total}` : '');
  if (p.mode === 'rclone' && (p.percent ?? 0) === 0 && (p.bytes_done ?? 0) === 0) {
    // Noch keine Stats vom rclone — pulsierender Balken
    setW('bar', '100%');
    setA('bar', 'pulse 1.5s ease-in-out infinite');
    set('pct',   '☁️ Verbinde…');
    set('done',  '–');
    set('total', '–');
    set('speed', '–');
    set('eta',   '–');
  } else {
    // Lokaler Download oder rclone mit echten Stats
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
      const statusLabel = {pending:'Ausstehend', downloading:'Lädt…', done:'Fertig', error:'Fehler'}[item.status] ?? item.status;
      el.className = `queue-item status-${item.status}`;
      const statusEl = el.querySelector('.qi-status');
      if (statusEl) { statusEl.textContent = statusLabel; statusEl.className = `qi-status ${item.status}`; }
    });
  }
}

function dashQueueItemHTML(item) {
  const statusLabel = {pending:'Ausstehend', downloading:'Lädt…', done:'Fertig', error:'Fehler'}[item.status] ?? item.status;
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
    body: JSON.stringify(body)
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
    img.src = img.dataset.src; img.removeAttribute('data-src');
    img.onload = () => img.classList.add('loaded');
  });
}
let toastTimer;
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
    const items = [...(nr?.movies ?? []), ...(nr?.series ?? [])].slice(0, 12);
    if (!items.length) {
      nrEl.innerHTML = `<div style="color:var(--muted);font-size:.8rem;padding:8px">Keine neuen Releases</div>`;
    } else {
      nrEl.innerHTML = items.map(item => `
        <div style="flex-shrink:0;width:100px;cursor:pointer" onclick="openTmdbModal('${esc(item.name??item.title??'')}','${item.stream_id?'movie':'series'}','',null)">
          <div style="width:100px;height:148px;border-radius:6px;overflow:hidden;background:var(--bg3);border:1px solid var(--border);position:relative">
            ${item.stream_icon||item.cover ? `<img src="${esc(item.stream_icon||item.cover)}" alt="" style="width:100%;height:100%;object-fit:cover">` : `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:1.8rem">${item.stream_id?'🎬':'📺'}</div>`}
            ${item.downloaded ? `<span class="card-badge badge-done" style="top:4px;right:4px;font-size:.55rem">✓</span>` : ''}
          </div>
          <div style="font-size:.72rem;margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text)">${esc(item.name??item.title??'')}</div>
        </div>`).join('');
    }
  }

  // Letzte Downloads (aus download_history — eigene Downloads wenn editor, alle wenn admin)
  const h = await api('get_recent_downloads');
  const recentEl = document.getElementById('ue-recent');
  if (recentEl) {
    if (!h?.items?.length) {
      recentEl.innerHTML = `<div class="state-box" style="grid-column:1/-1"><div class="icon">📭</div><p>Noch keine Downloads</p></div>`;
    } else {
      recentEl.innerHTML = h.items.map(item => `
        <div class="card downloaded">
          <div class="card-thumb">
            <div class="card-thumb-placeholder">${item.type==='episode'?'📺':'🎬'}</div>
            ${item.cover ? `<img src="${esc(item.cover)}" alt="" style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0">` : ''}
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
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted)">Keine Benutzer gefunden</td></tr>';
    return;
  }
  tbody.innerHTML = users.map(u => {
    const isSuspended = u.suspended ?? false;
    const statusBadge = isSuspended
      ? `<span class="role-badge badge-inactive">Gesperrt</span>`
      : `<span class="role-badge badge-active">Aktiv</span>`;
    const suspendBtn = isSuspended
      ? `<button class="btn-icon" onclick="toggleSuspend('${esc(u.id)}',false,'${esc(u.username)}')">✅ Entsperren</button>`
      : `<button class="btn-icon danger" onclick="toggleSuspend('${esc(u.id)}',true,'${esc(u.username)}')">🚫 Sperren</button>`;
    const limitVal = u.queue_limit !== undefined ? u.queue_limit : '';
    const limitDisplay = limitVal === '' ? '<span style="color:var(--muted)">Standard</span>'
      : limitVal == 0 ? '<span style="color:var(--red)">Gesperrt</span>'
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
          <button class="btn-icon" onclick="openEditUser('${esc(u.id)}','${esc(u.username)}','${u.role}')">✏️ Bearbeiten</button>
          <button class="btn-icon" onclick="openPwResetModal('${esc(u.id)}','${esc(u.username)}')">🔑 Passwort</button>
          ${suspendBtn}
          <button class="btn-icon danger" onclick="deleteUser('${esc(u.id)}','${esc(u.username)}')">✕ Löschen</button>
        </div>
      </td>
    </tr>`}).join('');
}

async function setUserLimit(id, username, current) {
  const input = prompt(
    `Queue-Limit für "${username}" (Anfragen/Stunde):\n` +
    `• Leer lassen = Rollen-Standard\n• 0 = Keine Queue-Zugriffe\n• Zahl = Individuelle Grenze`,
    current
  );
  if (input === null) return; // Abgebrochen
  const d = await apiPost('set_user_limit', {id, queue_limit: input.trim()});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast('Limit aktualisiert', 'success');
  loadUsers();
}

async function toggleSuspend(id, suspend, username) {
  const action = suspend ? `"${username}" wirklich sperren?` : `"${username}" entsperren?`;
  if (!confirm(action)) return;
  const r = await fetch(`${API}?action=suspend_user`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id, suspended: suspend})
  });
  const d = await r.json();
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast(suspend ? `"${username}" gesperrt` : `"${username}" entsperrt`, 'success');
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
  };
  const labels = {
    queue_add: 'Film zur Queue', queue_add_bulk: 'Mehrere zur Queue', queue_remove: 'Aus Queue entfernt',
    create_user: 'Benutzer angelegt', delete_user: 'Benutzer gelöscht',
    suspend_user: 'Benutzer gesperrt', unsuspend_user: 'Benutzer entsperrt',
    reset_password: 'Passwort zurückgesetzt', change_role: 'Rolle geändert', change_own_password: 'Eigenes Passwort geändert',
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
    body: JSON.stringify(items)
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
  document.getElementById('umodal-title').textContent    = 'Benutzer anlegen';
  document.getElementById('umodal-id').value             = '';
  document.getElementById('umodal-username').value       = '';
  document.getElementById('umodal-password').value       = '';
  document.getElementById('umodal-role').value           = 'viewer';
  document.getElementById('umodal-username-wrap').style.display = '';
  document.getElementById('umodal-pw-hint').textContent  = '';
  document.getElementById('umodal-submit').textContent   = 'Anlegen';
  document.getElementById('umodal-msg').className        = 'settings-msg';
  document.getElementById('umodal').classList.add('open');
  setTimeout(() => document.getElementById('umodal-username').focus(), 50);
}

function openEditUser(id, username, role) {
  umodalMode = 'edit';
  document.getElementById('umodal-title').textContent    = `Benutzer bearbeiten: ${username}`;
  document.getElementById('umodal-id').value             = id;
  document.getElementById('umodal-username').value       = username;
  document.getElementById('umodal-password').value       = '';
  document.getElementById('umodal-role').value           = role;
  document.getElementById('umodal-username-wrap').style.display = 'none';
  document.getElementById('umodal-pw-hint').textContent  = '(leer = unverändert)';
  document.getElementById('umodal-submit').textContent   = 'Speichern';
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
      body: JSON.stringify({
        username: document.getElementById('umodal-username').value.trim(),
        password: document.getElementById('umodal-password').value,
        role:     document.getElementById('umodal-role').value,
      })
    });
    const d = await r.json();
    btn.disabled = false;
    if (d.error) { msg.textContent = '❌ ' + d.error; msg.className = 'settings-msg err'; return; }
    showToast('Benutzer angelegt', 'success');
    closeUModal(); loadUsers();
  } else {
    const id = document.getElementById('umodal-id').value;
    const r = await fetch(`${API}?action=update_user`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        id,
        password: document.getElementById('umodal-password').value || undefined,
        role:     document.getElementById('umodal-role').value,
      })
    });
    const d = await r.json();
    btn.disabled = false;
    if (d.error) { msg.textContent = '❌ ' + d.error; msg.className = 'settings-msg err'; return; }
    showToast('Gespeichert', 'success');
    closeUModal(); loadUsers();
  }
}

async function deleteUser(id, username) {
  if (!confirm(`Benutzer "${username}" wirklich löschen?`)) return;
  const r = await fetch(`${API}?action=delete_user`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id})
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
    `<div style="color:var(--muted);text-align:center;padding:24px">⏳ Lade…</div>`;

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
      `<div style="color:var(--muted);text-align:center;padding:24px">Noch keine Downloads</div>`;
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
    el.innerHTML = `<div style="color:var(--muted);font-size:.8rem">Keine aktiven Einladungslinks</div>`;
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
  showToast('Einladung gelöscht', 'info');
  loadInvites();
}
<?php endif; ?>

// ── Profile / Change own password ─────────────────────────────
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
    body: JSON.stringify({old_password: oldPw, new_password: newPw})
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
