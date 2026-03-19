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
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg:        #0a0a0f;
  --bg2:       #111118;
  --bg3:       #18181f;
  --border:    rgba(255,255,255,.07);
  --accent:    #e8ff47;
  --accent2:   #47d4ff;
  --orange:    #ff9f43;
  --red:       #ff4757;
  --green:     #2ed573;
  --text:      #e8e8f0;
  --muted:     #5a5a70;
  --sidebar-w: 280px;
}
html { font-size: 15.5px; overflow-x: hidden; }
body { background: var(--bg); color: var(--text); font-family: 'DM Sans', sans-serif; min-height: 100vh; overflow-x: hidden; }
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
::-webkit-scrollbar-thumb:hover { background: var(--muted); }
body::before {
  content: '';
  position: fixed; inset: 0;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");
  pointer-events: none; z-index: 9999; opacity: .4;
}
.app { display: flex; min-height: 100vh; }

/* ── Sidebar ── */
.sidebar {
  width: var(--sidebar-w); min-height: 100vh;
  background: var(--bg2); border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  position: fixed; left: 0; top: 0; z-index: 100;
  transition: transform .3s cubic-bezier(.4,0,.2,1);
}
.sidebar-logo { padding: 28px 24px 20px; border-bottom: 1px solid var(--border); }
.logo-text { font-family: 'Bebas Neue', sans-serif; font-size: 2rem; letter-spacing: .12em; color: var(--accent); line-height: 1; }
.logo-sub { font-family: 'DM Mono', monospace; font-size: .65rem; color: var(--muted); letter-spacing: .2em; margin-top: 2px; }
.sidebar-stats { display: flex; gap: 8px; padding: 16px 24px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.stat-box { flex: 1; min-width: 60px; background: var(--bg3); border: 1px solid var(--border); border-radius: 6px; padding: 10px; text-align: center; }
.stat-box.queue-stat { border-color: rgba(255,159,67,.3); }
.stat-num { font-family: 'Bebas Neue', sans-serif; font-size: 1.4rem; color: var(--accent); line-height: 1; }
.stat-box.queue-stat .stat-num { color: var(--orange); }
.stat-label { font-family: 'DM Mono', monospace; font-size: .6rem; color: var(--muted); letter-spacing: .1em; margin-top: 2px; text-transform: uppercase; }
.nav { flex: 1; padding: 16px 0; overflow-y: auto; }
.nav-section-title { font-family: 'DM Mono', monospace; font-size: .6rem; color: var(--muted); letter-spacing: .2em; text-transform: uppercase; padding: 8px 24px 4px; }
.nav-item {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 24px; cursor: pointer;
  transition: background .15s, color .15s;
  border-left: 2px solid transparent;
  font-size: .875rem; color: var(--muted); font-weight: 400;
}
.nav-item:hover { background: rgba(255,255,255,.04); color: var(--text); }
.nav-item.active { background: rgba(232,255,71,.06); color: var(--accent); border-left-color: var(--accent); }
.nav-item.queue-nav.active { background: rgba(255,159,67,.06); color: var(--orange); border-left-color: var(--orange); }
.nav-icon { font-size: 1rem; width: 20px; text-align: center; }
.nav-badge {
  margin-left: auto;
  background: var(--orange); color: #000;
  font-family: 'DM Mono', monospace; font-size: .6rem; font-weight: 700;
  padding: 2px 7px; border-radius: 10px;
  display: none;
}
.nav-badge.show { display: inline-block; }
.category-list { max-height: 220px; overflow-y: auto; display: none; }
.category-list.open { display: block; }
.cat-item {
  padding: 8px 24px 8px 56px; cursor: pointer;
  color: var(--muted); font-size: .8rem;
  transition: color .15s, background .15s;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cat-item:hover { color: var(--text); background: rgba(255,255,255,.03); }
.cat-item.active { color: var(--accent2); }

/* ── Main ── */
.main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }
.topbar {
  height: 60px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 16px;
  padding: 0 28px; position: sticky; top: 0;
  background: rgba(10,10,15,.9); backdrop-filter: blur(12px); z-index: 50;
}
.page-title { font-family: 'Bebas Neue', sans-serif; font-size: 1.5rem; letter-spacing: .08em; color: var(--text); flex: 0 0 auto; }
.search-wrap { flex: 1; max-width: 420px; position: relative; }
.search-wrap input {
  width: 100%; background: var(--bg3); border: 1px solid var(--border);
  border-radius: 6px; padding: 8px 36px 8px 14px;
  color: var(--text); font-family: 'DM Sans', sans-serif; font-size: .875rem;
  outline: none; transition: border-color .2s;
}
.search-wrap input:focus { border-color: var(--accent); }
.search-wrap input::placeholder { color: var(--muted); }
.search-icon { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: .85rem; pointer-events: none; }
.filter-bar { display: flex; gap: 8px; margin-left: auto; }
.filter-btn {
  background: var(--bg3); border: 1px solid var(--border); border-radius: 5px;
  padding: 6px 14px; color: var(--muted);
  font-family: 'DM Mono', monospace; font-size: .7rem; letter-spacing: .1em;
  cursor: pointer; transition: all .15s;
}
.filter-btn:hover { border-color: var(--accent); color: var(--accent); }
.filter-btn.active { background: rgba(232,255,71,.12); border-color: var(--accent); color: var(--accent); }
.content { padding: 28px; flex: 1; overflow-x: hidden; max-width: 100%; }

/* ── Grid & Cards ── */
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
.card {
  background: var(--bg2); border: 1px solid var(--border); border-radius: 8px;
  overflow: hidden; transition: transform .2s, border-color .2s, box-shadow .2s; position: relative;
}
.card:hover { transform: translateY(-3px); border-color: rgba(232,255,71,.3); box-shadow: 0 8px 32px rgba(0,0,0,.4); }
.card.downloaded { border-color: rgba(71,212,255,.2); }
.card.queued     { border-color: rgba(255,159,67,.35); }
.btn-fav {
  position: absolute; top: 8px; right: 8px;
  background: rgba(0,0,0,.6); border: none; border-radius: 50%;
  width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: .85rem; transition: transform .15s; color: var(--muted); z-index: 2;
}
.btn-fav:hover { transform: scale(1.15); background: rgba(0,0,0,.85); }
.btn-fav.active { color: #ff4f6d; }
.card-thumb { width: 100%; aspect-ratio: 2/3; background: var(--bg3); overflow: hidden; position: relative; }
.card-thumb img { width: 100%; height: 100%; object-fit: cover; transition: opacity .3s; opacity: 0; }
.card-thumb img.loaded { opacity: 1; }
.card-thumb-placeholder { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: var(--muted); }
.card-badge {
  position: absolute; top: 8px; right: 8px;
  font-family: 'DM Mono', monospace; font-size: .55rem; font-weight: 500;
  letter-spacing: .1em; padding: 3px 7px; border-radius: 3px; text-transform: uppercase;
}
.badge-done  { background: var(--accent2); color: #000; }
.badge-queue { background: var(--orange);  color: #000; }
.card-body { padding: 12px; }
.card-title { font-size: .82rem; font-weight: 500; line-height: 1.3; color: var(--text); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.card-meta { font-family: 'DM Mono', monospace; font-size: .65rem; color: var(--muted); margin-top: 6px; letter-spacing: .05em; }
.card-actions { display: flex; gap: 6px; padding: 0 12px 12px; }
.btn-q {
  flex: 1; border: none; border-radius: 5px; padding: 7px;
  font-family: 'DM Mono', monospace; font-size: .65rem; font-weight: 500;
  letter-spacing: .1em; text-transform: uppercase; cursor: pointer;
  transition: opacity .15s, transform .1s; text-align: center;
}
.btn-q:hover { opacity: .85; transform: scale(.98); }
.btn-q.add    { background: var(--accent); color: #000; }
.btn-q.remove { background: rgba(255,159,67,.15); color: var(--orange); border: 1px solid rgba(255,159,67,.4); }
.btn-q.done   { background: var(--bg3); color: var(--muted); cursor: default; }
.btn-info {
  background: var(--bg3); border: 1px solid var(--border); border-radius: 5px;
  padding: 7px 10px; color: var(--muted); font-size: .75rem; cursor: pointer;
  transition: color .15s, border-color .15s;
}
.btn-info:hover { color: var(--accent2); border-color: var(--accent2); }

/* ── Queue Panel ── */
.queue-panel { }
.queue-toolbar {
  display: flex; align-items: center; gap: 12px;
  margin-bottom: 20px; flex-wrap: wrap;
}
.queue-toolbar-title { font-family: 'Bebas Neue', sans-serif; font-size: 1.1rem; letter-spacing: .08em; flex: 1; }
.btn-sm {
  background: var(--bg3); border: 1px solid var(--border); border-radius: 5px;
  padding: 6px 14px; color: var(--muted);
  font-family: 'DM Mono', monospace; font-size: .65rem; letter-spacing: .1em; text-transform: uppercase;
  cursor: pointer; transition: all .15s;
}
.btn-sm:hover { border-color: var(--accent); color: var(--accent); }
.btn-sm.danger:hover { border-color: var(--red); color: var(--red); }
.queue-list { display: flex; flex-direction: column; gap: 8px; }
.queue-item {
  background: var(--bg2); border: 1px solid var(--border); border-radius: 7px;
  display: flex; align-items: center; gap: 12px; padding: 12px 16px;
  transition: border-color .2s;
}
.queue-item.status-done    { border-left: 3px solid var(--green);  opacity: .7; }
.queue-item.status-error   { border-left: 3px solid var(--red); }
.queue-item.status-pending { border-left: 3px solid var(--orange); }
.queue-item.status-downloading { border-left: 3px solid var(--accent2); animation: pulse-border 1.5s ease-in-out infinite; }
@keyframes pulse-border { 0%,100%{border-left-color:var(--accent2)} 50%{border-left-color:#ffffff44} }
.qi-thumb { width: 36px; height: 54px; object-fit: cover; border-radius: 3px; background: var(--bg3); flex-shrink: 0; }
.qi-info { flex: 1; min-width: 0; }
.qi-title { font-size: .875rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.qi-meta  { font-family: 'DM Mono', monospace; font-size: .65rem; color: var(--muted); margin-top: 3px; }
.qi-status {
  font-family: 'DM Mono', monospace; font-size: .65rem; letter-spacing: .1em; text-transform: uppercase;
  padding: 3px 8px; border-radius: 4px; flex-shrink: 0;
}
.qi-status.pending     { background: rgba(255,159,67,.1); color: var(--orange); }
.qi-status.downloading { background: rgba(71,212,255,.1); color: var(--accent2); }
.qi-status.done        { background: rgba(46,213,115,.1); color: var(--green); }
.qi-status.error       { background: rgba(255,71,87,.1);  color: var(--red); }
.qi-del {
  background: none; border: 1px solid transparent; border-radius: 4px;
  color: var(--muted); font-size: .8rem; padding: 4px 8px; cursor: pointer;
  transition: all .15s; flex-shrink: 0;
}
.qi-del:hover { border-color: var(--red); color: var(--red); }

/* ── Live Progress Card ── */
.progress-card {
  background: var(--bg2); border: 1px solid rgba(71,212,255,.25);
  border-radius: 8px; padding: 20px 24px; margin-bottom: 20px;
  display: none;
}
.progress-card.active { display: block; animation: fadeIn .3s ease; }
@keyframes fadeIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:none; } }
.pc-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.pc-dot {
  width: 8px; height: 8px; border-radius: 50%; background: var(--accent2); flex-shrink: 0;
  animation: blink 1.2s ease-in-out infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.2} }
.pc-title { font-weight: 500; font-size: .92rem; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pc-pos { font-family: 'DM Mono', monospace; font-size: .65rem; color: var(--muted); flex-shrink: 0; }
.pc-bar-wrap { background: var(--bg3); border-radius: 4px; height: 6px; overflow: hidden; margin-bottom: 10px; }
.pc-bar { height: 100%; background: var(--accent2); border-radius: 4px; transition: width .8s ease; width: 0%; }
.pc-stats { display: flex; gap: 20px; flex-wrap: wrap; }
.pc-stat { font-family: 'DM Mono', monospace; font-size: .7rem; }
.pc-stat .val { color: var(--text); }
.pc-stat .lbl { color: var(--muted); margin-left: 4px; }

/* ── Log Panel ── */
.log-wrap {
  background: #08080d; border: 1px solid var(--border); border-radius: 8px;
  padding: 16px; font-family: 'DM Mono', monospace; font-size: .72rem;
  color: #aaa; max-height: 500px; overflow-y: auto;
  white-space: pre-wrap; word-break: break-all;
  line-height: 1.6;
}
.log-wrap .log-ok    { color: var(--green); }
.log-wrap .log-err   { color: var(--red); }
.log-wrap .log-skip  { color: var(--muted); }
.log-wrap .log-start { color: var(--accent2); }
.log-wrap .log-prog  { color: #888; }
.log-wrap .log-head  { color: var(--accent); }

/* ── Modal ── */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.7); backdrop-filter: blur(4px); z-index: 1000; display: none; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal {
  background: var(--bg2); border: 1px solid var(--border); border-radius: 10px;
  width: 90vw; max-width: 760px; max-height: 85vh;
  display: flex; flex-direction: column; overflow: hidden;
  animation: slideUp .25s cubic-bezier(.4,0,.2,1);
}
@keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: none; opacity: 1; } }
.modal-header { display: flex; align-items: center; gap: 16px; padding: 20px 24px; border-bottom: 1px solid var(--border); }
.modal-thumb { width: 60px; height: 90px; object-fit: cover; border-radius: 5px; background: var(--bg3); flex-shrink: 0; }
.modal-title { font-family: 'Bebas Neue', sans-serif; font-size: 1.6rem; letter-spacing: .06em; color: var(--text); line-height: 1.1; }
.modal-meta  { font-family: 'DM Mono', monospace; font-size: .7rem; color: var(--muted); margin-top: 4px; }
.modal-close { margin-left: auto; background: none; border: none; color: var(--muted); font-size: 1.3rem; cursor: pointer; padding: 4px; transition: color .15s; }
.modal-close:hover { color: var(--text); }
.modal-body { flex: 1; overflow-y: auto; padding: 20px 24px; }
.season-header { font-family: 'DM Mono', monospace; font-size: .7rem; letter-spacing: .2em; text-transform: uppercase; color: var(--muted); padding: 12px 0 8px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.season-queue-all { font-size: .65rem; color: var(--orange); cursor: pointer; }
.episode-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,.03); }
.ep-num   { font-family: 'DM Mono', monospace; font-size: .7rem; color: var(--muted); width: 28px; flex-shrink: 0; text-align: right; }
.ep-title { flex: 1; font-size: .85rem; }
.ep-ext   { font-family: 'DM Mono', monospace; font-size: .65rem; color: var(--muted); text-transform: uppercase; }
.ep-btn {
  background: none; border: 1px solid var(--border); border-radius: 4px;
  padding: 5px 12px; font-family: 'DM Mono', monospace; font-size: .6rem; letter-spacing: .1em;
  text-transform: uppercase; cursor: pointer; transition: background .15s, border-color .15s, color .15s;
}
.ep-btn.add    { color: var(--accent); border-color: rgba(232,255,71,.3); }
.ep-btn.add:hover { background: rgba(232,255,71,.1); border-color: var(--accent); }
.ep-btn.remove { color: var(--orange); border-color: rgba(255,159,67,.3); }
.ep-btn.remove:hover { background: rgba(255,159,67,.1); }
.ep-btn.done   { color: var(--muted); cursor: default; }

/* ── States ── */
.state-box { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 80px 24px; color: var(--muted); text-align: center; }
.state-box .icon { font-size: 2.5rem; opacity: .4; }
.state-box p { font-size: .85rem; max-width: 280px; }
.spinner { width: 32px; height: 32px; border: 2px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.toast { position: fixed; bottom: 24px; right: 24px; background: var(--bg3); border: 1px solid var(--border); border-radius: 7px; padding: 12px 18px; font-size: .82rem; z-index: 9000; opacity: 0; transform: translateY(8px); transition: opacity .25s, transform .25s; pointer-events: none; max-width: 340px; }
.toast.show { opacity: 1; transform: none; }
.toast.success { border-left: 3px solid var(--accent); }
.toast.error   { border-left: 3px solid var(--red); }
.toast.info    { border-left: 3px solid var(--orange); }
.hamburger { display: none; flex-direction: column; gap: 5px; cursor: pointer; padding: 4px; }
.hamburger span { width: 22px; height: 2px; background: var(--text); border-radius: 1px; }
.sidebar-overlay {
  display: none;
  position: fixed; inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 99;
}
@media (max-width: 768px) {
  :root { --sidebar-w: 0px; }

  /* Prevent ANY element from causing horizontal scroll */
  *, *::before, *::after { box-sizing: border-box; max-width: 100%; }

  /* Layout */
  .sidebar { transform: translateX(-100%); width: 260px; }
  .sidebar.open { transform: translateX(0); }
  .main { margin-left: 0; }
  .hamburger { display: flex !important; }

  /* Topbar */
  .topbar { padding: 0 12px; gap: 10px; }
  .page-title { font-size: 1.1rem; }
  .search-wrap { max-width: none; }
  .filter-bar { gap: 6px; }
  .filter-btn { padding: 5px 10px; font-size: .65rem; }
  .queue-pill { font-size: .7rem; padding: 4px 8px; }
  #limit-indicator { display: none !important; }

  /* Content */
  .content { padding: 16px 14px; }

  /* Grids — force single column on anything with a large minmax */
  .grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
  .settings-grid { grid-template-columns: 1fr !important; max-width: 100%; }
  #dash-stat-grid   { grid-template-columns: 1fr 1fr !important; gap: 8px !important; }
  #dash-server-info { grid-template-columns: 1fr 1fr !important; gap: 8px !important; }
  /* Queue+Disk+System row — stack all to 2 cols */
  #dash-stat-grid ~ div[style*="repeat(4,1fr)"] { grid-template-columns: 1fr 1fr !important; }
  .dkpi-n { font-size: 1.2rem; }
  .dkpi { padding: 10px 12px; }

  /* Override inline style grids (dashboard, rclone fields, etc.) */
  [style*="minmax(260px"] { grid-template-columns: 1fr 1fr !important; gap: 10px !important; }
  [style*="minmax(200px"] { grid-template-columns: 1fr 1fr !important; gap: 10px !important; }
  [style*="minmax(320px"] { grid-template-columns: 1fr !important; }
  [style*="grid-template-columns:1fr 1fr"] { grid-template-columns: 1fr !important; }
  [style*="grid-template-columns: 1fr 1fr"] { grid-template-columns: 1fr !important; }

  /* Cards */
  .card-body { padding: 8px 10px; }
  .card-title { font-size: .78rem; }
  .card-meta  { font-size: .65rem; }
  .btn-q { font-size: .65rem; padding: 5px 8px; }

  /* Settings */
  .settings-card { padding: 16px; }
  .settings-actions { flex-wrap: wrap; gap: 8px; }

  /* Queue */
  .queue-toolbar { flex-wrap: wrap; gap: 8px; padding: 12px 14px; }
  .queue-list    { padding: 0 14px; }
  .queue-item    { gap: 10px; padding: 10px 0; }
  .qi-thumb      { width: 44px; height: 44px; flex-shrink: 0; }
  .qi-title      { font-size: .8rem; }
  .qi-meta       { font-size: .65rem; }
  .qi-del        { padding: 4px 8px; font-size: .75rem; }

  /* Progress card */
  .pc-stats { gap: 10px; flex-wrap: wrap; }
  .pc-stat   { min-width: calc(50% - 10px); }

  /* User table — make scrollable rather than overflow */
  .user-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .user-table { font-size: .75rem; min-width: 0; width: 100%; }
  .user-table th, .user-table td { padding: 8px 6px; }
  .user-actions { flex-direction: column; gap: 4px; }
  .btn-icon { font-size: .7rem; padding: 4px 8px; }
  /* Hide less critical user columns */
  .user-table th:nth-child(4),
  .user-table td:nth-child(4) { display: none; }

  /* API key table */
  .apikey-table th, .apikey-table td { padding: 8px 6px; font-size: .72rem; }
  .apikey-table th:nth-child(4),
  .apikey-table td:nth-child(4),
  .apikey-table th:nth-child(5),
  .apikey-table td:nth-child(5) { display: none; }

  /* Modals */
  .umodal-box { padding: 20px; margin: 12px; width: calc(100% - 24px); max-width: none; }
  .modal-box  { margin: 0; height: 100%; width: 100%; border-radius: 0; max-height: 100dvh; }
  .modal-header { padding: 14px 16px; }
  .modal-body   { padding: 14px 16px; }

  /* Activity log */
  .actlog-item { gap: 8px; }

  /* Search — tab row and toolbar */
  #view-search > div:first-child { flex-wrap: wrap; row-gap: 8px; }
  #multiselect-toolbar { margin-left: 0 !important; width: 100%; }

  /* Library filter bar — stack vertically */
  #view-dashboard [style*="display:flex"][style*="gap:10px"] { flex-wrap: wrap; }
  #lib-search { min-width: 0; flex: 1 1 100%; }
  #lib-cat-filter { width: 100%; min-width: 0; }

  /* Dashboard dash-info */
  .dash-info-card { padding: 10px 12px; }
  .dic-val { font-size: .8rem; }
  #dash-bottom-grid { grid-template-columns: 1fr !important; }
}
/* ── Settings ── */
.settings-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; max-width: 900px; }
.settings-card { background: var(--bg2); border: 1px solid var(--border); border-radius: 8px; padding: 24px; }
.settings-card h3 { font-family: 'DM Mono', monospace; font-size: .65rem; color: var(--muted); letter-spacing: .2em; text-transform: uppercase; margin-bottom: 16px; }
.settings-card.warning { border-color: rgba(255,71,87,.25); }
.field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.field:last-child { margin-bottom: 0; }
.field label { font-family: 'DM Mono', monospace; font-size: .65rem; color: var(--muted); letter-spacing: .1em; text-transform: uppercase; }
.field input {
  background: var(--bg3); border: 1px solid var(--border); border-radius: 6px;
  padding: 9px 12px; color: var(--text);
  font-family: 'DM Mono', monospace; font-size: .82rem;
  outline: none; transition: border-color .2s;
}
.field input:focus { border-color: var(--accent); }
.field input.error { border-color: var(--red); }
.field .hint { font-size: .7rem; color: var(--muted); }
.settings-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; }
.btn-primary {
  background: var(--accent); color: #000; border: none; border-radius: 6px;
  padding: 9px 20px; font-family: 'DM Mono', monospace; font-size: .7rem;
  font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  cursor: pointer; transition: opacity .15s;
}
.btn-primary:hover { opacity: .85; }
.btn-primary:disabled { opacity: .4; cursor: default; }
.btn-secondary {
  background: var(--bg3); color: var(--text); border: 1px solid var(--border); border-radius: 6px;
  padding: 9px 20px; font-family: 'DM Mono', monospace; font-size: .7rem;
  letter-spacing: .1em; text-transform: uppercase; cursor: pointer; transition: all .15s;
}
.btn-secondary:hover { border-color: var(--accent2); color: var(--accent2); }
.settings-msg { margin-top: 12px; font-size: .8rem; padding: 10px 14px; border-radius: 6px; display: none; }
.settings-msg.ok  { background: rgba(46,213,115,.1); color: var(--green); border: 1px solid rgba(46,213,115,.2); display: block; }
.settings-msg.err { background: rgba(255,71,87,.1);  color: var(--red);   border: 1px solid rgba(255,71,87,.2);  display: block; }
.settings-msg.info { background: rgba(71,212,255,.1); color: var(--accent2); border: 1px solid rgba(71,212,255,.2); display: block; }
.unconfigured-banner {
  background: rgba(255,71,87,.08); border: 1px solid rgba(255,71,87,.25);
  border-radius: 8px; padding: 16px 20px; margin-bottom: 24px;
  display: flex; align-items: center; gap: 12px; font-size: .875rem;
  cursor: pointer; transition: background .15s;
}
.unconfigured-banner:hover { background: rgba(255,71,87,.13); }
.unconfigured-banner .ub-icon { font-size: 1.4rem; }
.unconfigured-banner strong { color: var(--red); }

/* ── User Management ── */
.user-table { width: 100%; border-collapse: collapse; }
.user-table th {
  font-family: 'DM Mono', monospace; font-size: .6rem; color: var(--muted);
  letter-spacing: .15em; text-transform: uppercase; text-align: left;
  padding: 8px 12px; border-bottom: 1px solid var(--border);
}
.user-table td { padding: 12px; border-bottom: 1px solid rgba(255,255,255,.03); font-size: .875rem; vertical-align: middle; }
.user-table tr:last-child td { border-bottom: none; }
.user-table tr:hover td { background: rgba(255,255,255,.02); }
.role-badge {
  font-family: 'DM Mono', monospace; font-size: .6rem; letter-spacing: .1em;
  text-transform: uppercase; padding: 3px 8px; border-radius: 4px;
}
.role-badge.admin  { background: rgba(232,255,71,.12); color: var(--accent); }
.role-badge.editor { background: rgba(71,212,255,.12); color: var(--accent2); }
.role-badge.viewer { background: rgba(255,255,255,.06); color: var(--muted); }
.user-actions { display: flex; gap: 6px; }
.btn-icon {
  background: none; border: 1px solid var(--border); border-radius: 4px;
  padding: 4px 10px; font-size: .75rem; color: var(--muted); cursor: pointer;
  transition: all .15s; font-family: 'DM Mono', monospace;
}
.btn-icon:hover { border-color: var(--accent2); color: var(--accent2); }
.btn-icon.danger:hover { border-color: var(--red); color: var(--red); }

/* ── User Modal ── */
.umodal {
  position: fixed; inset: 0; background: rgba(0,0,0,.7); backdrop-filter: blur(4px);
  z-index: 2000; display: none; align-items: center; justify-content: center;
}
.umodal.open { display: flex; }
.umodal-box {
  background: var(--bg2); border: 1px solid var(--border); border-radius: 10px;
  width: 90vw; max-width: 440px; padding: 28px 28px 24px;
  animation: slideUp .2s cubic-bezier(.4,0,.2,1);
}
.umodal-title { font-family: 'Bebas Neue', sans-serif; font-size: 1.3rem; letter-spacing: .08em; margin-bottom: 20px; }
.umodal-actions { display: flex; gap: 10px; margin-top: 20px; justify-content: flex-end; }

/* ── Topbar user chip ── */
.user-chip {
  display: flex; align-items: center; gap: 8px; margin-left: auto;
  background: var(--bg3); border: 1px solid var(--border); border-radius: 20px;
  padding: 4px 14px 4px 10px; cursor: pointer; transition: border-color .15s;
  position: relative;
}
.user-chip:hover { border-color: var(--accent); }
.user-chip-avatar {
  width: 22px; height: 22px; border-radius: 50%; background: var(--accent);
  color: #000; font-weight: 700; font-size: .7rem;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.user-chip-name { font-family: 'DM Mono', monospace; font-size: .7rem; }
.user-chip-role { font-size: .65rem; color: var(--muted); }
.user-dropdown {
  position: absolute; top: calc(100% + 8px); right: 0;
  background: var(--bg2); border: 1px solid var(--border); border-radius: 8px;
  min-width: 180px; overflow: hidden; z-index: 200;
  display: none; box-shadow: 0 8px 24px rgba(0,0,0,.4);
}
.user-chip.open .user-dropdown { display: block; }
.user-dropdown a, .user-dropdown button {
  display: block; width: 100%; text-align: left;
  padding: 10px 16px; font-size: .82rem; color: var(--text);
  background: none; border: none; cursor: pointer; text-decoration: none;
  transition: background .12s;
}
.user-dropdown a:hover, .user-dropdown button:hover { background: rgba(255,255,255,.05); }
.user-dropdown .sep { height: 1px; background: var(--border); margin: 4px 0; }
.user-dropdown .danger { color: var(--red); } */
.queue-pill {
  background: var(--orange); color: #000;
  font-family: 'DM Mono', monospace; font-size: .65rem; font-weight: 700;
  padding: 3px 10px; border-radius: 20px; cursor: pointer;
  display: none; transition: transform .15s;
}
.queue-pill:hover { transform: scale(1.05); }
.queue-pill.show { display: inline-block; }
.qi-prio {
  background: var(--bg3); border: 1px solid var(--border); border-radius: 4px;
  color: var(--text); font-size: .65rem; padding: 3px 6px; cursor: pointer; outline: none;
}
.qi-prio-badge { font-size: .65rem; color: var(--muted); }
.prio-1 { color: var(--red); }
.prio-2 { color: var(--orange); }
.prio-3 { color: var(--accent2); }

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50%       { opacity: .4; }
}
/* ── Multi-select ── */
.card.selected {
  border-color: var(--accent) !important;
  box-shadow: 0 0 0 2px rgba(232,255,71,.25);
}
.card .select-check {
  position: absolute; top: 8px; left: 8px;
  width: 20px; height: 20px; border-radius: 50%;
  background: var(--bg3); border: 2px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  font-size: .7rem; cursor: pointer; z-index: 2;
  transition: background .15s, border-color .15s;
}
.card.selected .select-check { background: var(--accent); border-color: var(--accent); color: #000; }

/* ── Activity Log ── */
.actlog-item {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,.03);
}
.actlog-item:last-child { border-bottom: none; }
.actlog-icon { font-size: 1rem; width: 28px; text-align: center; flex-shrink: 0; margin-top: 2px; }
.actlog-info { flex: 1; }
.actlog-action { font-size: .875rem; font-weight: 500; }
.actlog-meta { font-family: 'DM Mono', monospace; font-size: .65rem; color: var(--muted); margin-top: 3px; }
.dash-info-card {
  background: var(--bg2); border: 1px solid var(--border); border-radius: 7px;
  padding: 14px 16px;
}
.dkpi { background: var(--bg2); border: 1px solid var(--border); border-radius: 8px; padding: 12px 14px; }
.dkpi-l { font-family: 'DM Mono', monospace; font-size: .6rem; color: var(--muted); letter-spacing: .12em; text-transform: uppercase; margin-bottom: 5px; }
.dkpi-v { font-size: .875rem; color: var(--text); }
.dkpi-n { font-size: 1.5rem; font-weight: 500; line-height: 1.1; margin-top: 2px; }
.btn-secondary.danger { border-color: rgba(255,71,87,.3); color: var(--red); }
.btn-secondary.danger:hover { background: rgba(255,71,87,.1); border-color: var(--red); }
.dic-label {
  font-family: 'DM Mono', monospace; font-size: .6rem; color: var(--muted);
  letter-spacing: .12em; text-transform: uppercase; margin-bottom: 5px;
}
.dic-val { font-size: .875rem; color: var(--text); word-break: break-all; }
.dic-val.ok    { color: var(--green); font-weight: 500; }
.dic-val.warn  { color: var(--orange); font-weight: 500; }
.dic-val.error { color: var(--red); font-weight: 500; }

/* ── API Key Table ── */
.apikey-table { width: 100%; border-collapse: collapse; }
.apikey-table th {
  font-family: 'DM Mono', monospace; font-size: .6rem; color: var(--muted);
  letter-spacing: .15em; text-transform: uppercase; text-align: left;
  padding: 8px 12px; border-bottom: 1px solid var(--border);
}
.apikey-table td { padding: 11px 12px; border-bottom: 1px solid rgba(255,255,255,.03); font-size: .82rem; vertical-align: middle; }
.apikey-table tr:last-child td { border-bottom: none; }
.apikey-table tr:hover td { background: rgba(255,255,255,.02); }
.key-preview {
  font-family: 'DM Mono', monospace; font-size: .72rem;
  background: var(--bg3); border: 1px solid var(--border);
  border-radius: 4px; padding: 4px 8px; color: var(--accent2);
  letter-spacing: .05em;
}
.key-new-reveal {
  font-family: 'DM Mono', monospace; font-size: .75rem;
  background: rgba(46,213,115,.08); border: 1px solid rgba(46,213,115,.25);
  border-radius: 6px; padding: 12px 16px; color: var(--green);
  word-break: break-all; line-height: 1.7; margin: 12px 0;
}
.key-new-reveal strong { display: block; font-size: .65rem; color: var(--muted); letter-spacing: .15em; text-transform: uppercase; margin-bottom: 6px; }
.badge-active   { background: rgba(46,213,115,.12); color: var(--green); }
.badge-inactive { background: rgba(255,71,87,.1);   color: var(--red); }
</style>
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
    <?php if ($role === 'editor'): ?>
    <span id="limit-indicator" style="display:none;font-family:'DM Mono',monospace;font-size:.65rem;padding:4px 10px;border-radius:4px;background:var(--bg3);border:1px solid var(--border)"></span>
    <?php endif; ?>
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
      <div id="dash-stat-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:10px">
        <div class="dkpi"><div class="dkpi-l">Server</div><div class="dkpi-v" id="dash-server">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Account</div><div class="dkpi-v" id="dash-user">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Destination</div><div class="dkpi-v" style="word-break:break-all;font-size:.8rem" id="dash-dest">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Downloads gesamt</div><div class="dkpi-n" style="color:var(--green)" id="dash-total-dl">–</div></div>
      </div>

      <!-- Zeile 2: Queue-Zahlen + Disk + System in einer Zeile -->
      <div style="display:grid;grid-template-columns:repeat(4,1fr) 1.6fr 1.6fr;gap:10px;margin-bottom:10px">
        <div class="dkpi"><div class="dkpi-l">Ausstehend</div><div class="dkpi-n" style="color:var(--accent)" id="dqs-pending">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Lädt</div><div class="dkpi-n" style="color:var(--orange)" id="dqs-downloading">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Fertig</div><div class="dkpi-n" style="color:var(--green)" id="dqs-done">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Fehler</div><div class="dkpi-n" style="color:var(--red)" id="dqs-error">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Speicher</div><div id="dash-disk"><div style="color:var(--muted);font-size:.75rem">Lade…</div></div></div>
        <div class="dkpi"><div class="dkpi-l">System</div><div id="dash-system" style="font-size:.78rem;line-height:1.75"><div style="color:var(--muted)">Lade…</div></div></div>
      </div>

      <!-- Zeile 3: Xtream Server Info (6 kompakte Kacheln) -->
      <div id="dash-server-info" style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:10px">
        <div class="dkpi"><div class="dkpi-l">Status</div><div class="dkpi-v" id="si-status">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Läuft ab</div><div class="dkpi-v" id="si-exp">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Verbindungen</div><div class="dkpi-n" style="font-size:1.2rem" id="si-cons">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Trial</div><div class="dkpi-v" id="si-trial">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Formate</div><div class="dkpi-v" id="si-formats">–</div></div>
        <div class="dkpi"><div class="dkpi-l">Serverzeit</div><div class="dkpi-v" style="font-family:'DM Mono',monospace;font-size:.72rem" id="si-time">–</div></div>
      </div>

      <!-- Schnellzugriff -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
        <button class="btn-secondary" onclick="dashStartQueue()">▶ Queue starten</button>
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
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px" id="dash-bottom-grid">
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
      <!-- Viewer/Editor Dashboard: Mediathek -->
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div>
          <div style="font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:.08em">Meine Mediathek</div>
          <div style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);margin-top:2px">
            <span id="lib-count-movies">–</span> Filme · <span id="lib-count-episodes">–</span> Episoden
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="filter-btn active" id="lib-tab-movies"  onclick="switchLibTab('movies',this)">🎬 Filme</button>
          <button class="filter-btn"        id="lib-tab-episodes" onclick="switchLibTab('episodes',this)">📺 Episoden</button>
        </div>
      </div>
      <!-- Filter bar -->
      <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
        <div class="search-wrap" style="max-width:280px;flex:1">
          <input type="text" id="lib-search" placeholder="Titel suchen…" oninput="filterLibrary()">
          <span class="search-icon">🔍</span>
        </div>
        <select id="lib-cat-filter" onchange="filterLibrary()" style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:8px 12px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.82rem;outline:none">
          <option value="">Alle Kategorien</option>
        </select>
        <button class="btn-sm" onclick="filterLibrary(true)">↺ Zurücksetzen</button>
      </div>
      <!-- Loading state -->
      <div id="lib-loading" class="state-box"><div class="spinner"></div><p>Lade Mediathek…</p></div>
      <!-- Movies grid -->
      <div id="lib-movies" class="grid" style="display:none"></div>
      <!-- Episodes grid -->
      <div id="lib-episodes" class="grid" style="display:none"></div>
      <!-- Empty state -->
      <div id="lib-empty" class="state-box" style="display:none">
        <div class="icon">📭</div>
        <p>Noch keine VODs heruntergeladen.</p>
      </div>
      <?php endif; ?>
    </div>

    <!-- Movies -->
    <?php if ($show_movies): ?><div id="view-movies" style="display:none"><div class="grid" id="movie-grid"></div></div><?php endif; ?>
    <?php if ($show_series): ?><div id="view-series" style="display:none"><div class="grid" id="series-grid"></div></div><?php endif; ?>
    <!-- Search -->
    <div id="view-search" style="display:none">
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
        <button class="btn-sm" onclick="startQueue()">▶ Starten</button>
        <?php endif; ?>
        <?php if ($can_queue_clear): ?>
        <button class="btn-sm" onclick="clearDone()">✕ Done entfernen</button>
        <button class="btn-sm danger" onclick="clearAll()">🗑 Alles löschen</button>
        <?php endif; ?>
      </div>
      <div class="queue-list" id="queue-list"></div>
    </div>
    <?php endif; ?>

    <?php if ($can_cron_log): ?>
    <!-- Log -->
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

    <div id="view-log" style="display:none">
      <div class="queue-toolbar">
        <div class="queue-toolbar-title">Cron Log</div>
        <button class="btn-sm" onclick="loadLog()">↻ Aktualisieren</button>
      </div>
      <div class="log-wrap" id="log-wrap">Lade Log…</div>
    </div>
    <?php endif; ?>

    <!-- Settings -->
    <div id="view-settings" style="display:none">
      <?php if ($can_settings): ?>
      <div class="settings-grid">

        <div class="settings-card" style="grid-column:1/-1">
          <h3>Xtream Server</h3>
          <div class="field">
            <label>Server IP / Domain</label>
            <input type="text" id="cfg-server-ip" placeholder="z.B. line.example.com">
          </div>
          <div class="field">
            <label>Port</label>
            <input type="text" id="cfg-port" placeholder="80" value="80">
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
          <div class="field" style="margin-bottom:10px">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
              <input type="checkbox" id="cfg-editor-movies" style="width:16px;height:16px;accent-color:var(--accent)">
              🎬 Movies anzeigen
            </label>
          </div>
          <div class="field">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
              <input type="checkbox" id="cfg-editor-series" style="width:16px;height:16px;accent-color:var(--accent)">
              📺 Series anzeigen
            </label>
          </div>
        </div>

        <div class="settings-card" style="grid-column:1/-1">
          <h3>☁️ rclone — Cloud-Speicher</h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
            Wenn aktiviert, werden VODs direkt in den Cloud-Speicher gestreamt — ohne lokale Zwischenspeicherung.
          </div>
          <div class="field" style="margin-bottom:12px">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
              <input type="checkbox" id="cfg-rclone-enabled" onchange="toggleRcloneFields(this.checked)" style="width:16px;height:16px;accent-color:var(--accent)">
              rclone aktivieren
            </label>
          </div>
          <div id="rclone-fields" style="display:none">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px">
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
            </div>
            <div class="field" style="margin-bottom:12px">
              <label>rclone Binary-Pfad</label>
              <input type="text" id="cfg-rclone-bin" placeholder="rclone">
              <span class="hint">Vollständiger Pfad wenn nicht im PATH: z.B. /usr/bin/rclone</span>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <button class="btn-secondary" onclick="testRclone()">🔌 rclone testen</button>
              <div class="settings-msg" id="rclone-test-msg" style="margin:0"></div>
            </div>
          </div>
        </div>

        <div class="settings-card">
          <h3>Cronjob</h3>
          <div style="font-family:'DM Mono',monospace;font-size:.72rem;color:var(--orange);background:var(--bg3);border:1px solid var(--border);border-radius:5px;padding:12px;line-height:1.7;word-break:break-all">
            # Alle 30 Minuten ausführen:<br>
            */30 * * * * flock -n /tmp/xtream.lock \<br>
            &nbsp;&nbsp;php <?= __DIR__ ?>/cron.php
          </div>
          <div style="font-size:.75rem;color:var(--muted);margin-top:10px">Einrichten mit: <code style="color:var(--accent2)">sudo crontab -e -u www-data</code></div>
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

        <div class="settings-card" style="grid-column:1/-1">
          <h3>API-Keys (Externe Benutzerverwaltung)</h3>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
            API-Keys ermöglichen externe Systeme, Benutzer anzulegen.<br>
            <strong>POST:</strong> <code style="color:var(--accent2);font-family:'DM Mono',monospace">POST api.php?action=external_create_user</code>
            mit Header <code style="color:var(--accent2);font-family:'DM Mono',monospace">X-API-Key: &lt;key&gt;</code>
            und Body <code style="color:var(--accent2);font-family:'DM Mono',monospace">{"username":"…","password":"…","role":"viewer|editor|admin"}</code><br>
            <strong>GET:</strong> <code style="color:var(--accent2);font-family:'DM Mono',monospace">api.php?action=external_create_user&amp;api_key=xv_...&amp;username=…&amp;password=…&amp;role=viewer</code>
          </div>
          <div id="apikey-new-reveal" style="display:none" class="key-new-reveal">
            <strong>⚠️ Einmalig sichtbar — jetzt kopieren!</strong>
            <span id="apikey-new-value"></span>
          </div>
          <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
            <input type="text" id="apikey-name-input" placeholder="Name des Keys (z.B. Webshop)" style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:8px 12px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.82rem;outline:none;flex:1">
            <button class="btn-primary" onclick="createApiKey()">+ API-Key erstellen</button>
          </div>
          <div style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;overflow:hidden">
            <table class="apikey-table" id="apikey-table">
              <thead><tr><th>Name</th><th>Key</th><th>Status</th><th>Erstellt</th><th>Zuletzt benutzt</th><th>Aufrufe</th><th></th></tr></thead>
              <tbody id="apikey-tbody"><tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)">Lade…</td></tr></tbody>
            </table>
          </div>
        </div>

      </div>

      <div class="settings-actions">
        <button class="btn-secondary" onclick="testConnection()">🔌 Verbindung testen</button>
        <button class="btn-primary" id="btn-save-cfg" onclick="saveConfig()">💾 Speichern</button>
      </div>
      <div class="settings-msg" id="settings-msg"></div>

      <!-- Wartungsmodus -->
      <div class="settings-card" style="margin-top:24px;border-color:rgba(255,71,87,.2)">
        <h3>🔧 Wartungsmodus</h3>
        <div style="font-size:.82rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
          Wenn aktiv, können sich nur Admins einloggen. Alle anderen sehen eine Wartungsseite.
        </div>
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
          <div id="maintenance-status" style="font-family:'DM Mono',monospace;font-size:.75rem;padding:5px 12px;border-radius:5px;background:var(--bg3);border:1px solid var(--border)">
            Lade…
          </div>
          <button class="btn-secondary" id="btn-maintenance-toggle" onclick="toggleMaintenance()">Lade…</button>
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
        <div class="queue-toolbar-title">Benutzerverwaltung</div>
        <button class="btn-sm" onclick="showView('activity-log')">📋 Aktivitätslog</button>
        <button class="btn-sm" onclick="openCreateUser()">+ Benutzer anlegen</button>
      </div>
      <div class="user-table-wrap" style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;overflow:hidden">
        <table class="user-table" id="users-table">
          <thead>
            <tr>
              <th>Benutzername</th>
              <th>Rolle</th>
              <th>Status</th>
              <th>Erstellt</th>
              <th>Letzter Login</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="users-tbody">
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted)">Lade…</td></tr>
          </tbody>
        </table>
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

<div class="toast" id="toast"></div>

<script>
const API = 'api.php';
const canQueueAdd       = <?= $can_queue_add        ? 'true' : 'false' ?>;
const canQueueRemove    = <?= $can_queue_remove     ? 'true' : 'false' ?>;
const canQueueRemoveOwn = <?= $can_queue_remove_own ? 'true' : 'false' ?>;
const canSeeAddedBy     = <?= $can_settings         ? 'true' : 'false' ?>;
const currentUsername   = <?= json_encode($user['username']) ?>;
let currentView   = 'dashboard';
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
  <?php if (!$can_settings): ?>loadLibrary();<?php endif; ?>
  <?php if ($can_settings): ?>startDashboardPolling();<?php endif; ?>
  <?php if ($role === 'editor'): ?>loadLimitStatus();<?php endif; ?>
})();

// ── Stats ─────────────────────────────────────────────────────
async function loadStats() {
  const d = await api('stats');
  document.getElementById('stat-movies').textContent   = d.movies   ?? '–';
  document.getElementById('stat-episodes').textContent = d.episodes ?? '–';
  document.getElementById('stat-queued').textContent   = d.queued   ?? 0;
  updateQueuePill(d.queued ?? 0);
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
  const d = await api('queue_stats');
  updateQueuePill(d.pending ?? 0);
  <?php endif; ?>
}

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
async function loadMovies(catId, catName, el) {
  setActiveCat(el);
  showView('movies');
  document.getElementById('page-title').textContent = catName;
  document.getElementById('movie-grid').innerHTML = loadingHTML();
  allMovies = await api('get_movies', {category_id: catId});
  // Kategoriename in jeden Eintrag schreiben (fehlt im API-Response beim Browse)
  allMovies = allMovies.map(m => ({...m, category: m.category || catName}));
  renderMovies();
}

function renderMovies() {
  const grid = document.getElementById('movie-grid');
  let movies = allMovies;
  if (currentFilter === 'done')   movies = movies.filter(m => m.downloaded);
  if (currentFilter === 'new')    movies = movies.filter(m => !m.downloaded && !m.queued);
  if (currentFilter === 'queued') movies = movies.filter(m => m.queued);
  if (!movies.length) { grid.innerHTML = emptyHTML('Keine Filme'); return; }
  grid.innerHTML = movies.map(movieCard).join('');
  lazyLoadImages();
}

function movieCard(m) {
  const thumb = m.stream_icon ? `<img data-src="${m.stream_icon}" alt="">` : '';
  const badge = m.downloaded
    ? `<span class="card-badge badge-done">✓ Done</span>`
    : m.queued ? `<span class="card-badge badge-queue">⏳ Queue</span>` : '';

  const btn = m.downloaded
    ? `<button class="btn-q done" disabled>✓ Done</button>`
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

  return `
  <div class="card ${m.downloaded?'downloaded':m.queued?'queued':''}" id="card-m-${m.stream_id}">
    <div class="card-thumb">
      <div class="card-thumb-placeholder">🎬</div>
      ${thumb}${badge}${selectBox}${favBtn}
    </div>
    <div class="card-body">
      <div class="card-title">${m.clean_title}</div>
      <div class="card-meta">${(m.container_extension??'').toUpperCase()}</div>
    </div>
    <div class="card-actions">${btn}</div>
  </div>`;
}

// ── Series ────────────────────────────────────────────────────
async function loadSeriesCat(catId, catName, el) {
  setActiveCat(el);
  showView('series');
  document.getElementById('page-title').textContent = catName;
  const grid = document.getElementById('series-grid');
  grid.innerHTML = loadingHTML();
  const list = await api('get_series', {category_id: catId});
  if (!list.length) { grid.innerHTML = emptyHTML('Keine Serien'); return; }
  grid.innerHTML = list.map(s => seriesCard({...s, category: s.category || catName})).join('');
  lazyLoadImages();
}

function seriesCard(s) {
  const thumb = s.cover ? `<img data-src="${s.cover}" alt="">` : '';
  const isFav = favourites.has('series:' + s.series_id);
  const favBtn = `<button class="btn-fav${isFav?' active':''}" onclick="event.stopPropagation();toggleFav('series','${s.series_id}','${esc(s.clean_title)}','${esc(s.cover||'')}','${esc(s.category||'')}','',this)" title="${isFav?'Aus Favoriten entfernen':'Zu Favoriten hinzufügen'}">♥</button>`;
  return `
  <div class="card" onclick="openSeriesModal(${s.series_id},'${esc(s.clean_title)}','${esc(s.cover||'')}')">
    <div class="card-thumb"><div class="card-thumb-placeholder">📺</div>${thumb}${favBtn}</div>
    <div class="card-body"><div class="card-title">${s.clean_title}</div><div class="card-meta">${s.genre??''}</div></div>
    <div class="card-actions"><button class="btn-q add" onclick="event.stopPropagation();openSeriesModal(${s.series_id},'${esc(s.clean_title)}','${esc(s.cover||'')}')">📋 Episodes</button></div>
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
        ? `<button class="ep-btn done" disabled>✓</button>`
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
  if (d.already) { showToast('Bereits in der Queue', 'info'); return null; }
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
      if (statusEl && statusEl.textContent !== statusLabel) {
        statusEl.textContent = statusLabel;
        statusEl.className   = `qi-status ${item.status}`;
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

  return `
  <div class="queue-item status-${item.status}" id="qi-${item.stream_id}">
    ${thumb}
    <div class="qi-info">
      <div class="qi-title">${item.title}</div>
      <div class="qi-meta">${item.type} · ${item.container_extension?.toUpperCase()} · ${item.added_at ?? ''} ${addedBy}</div>
      ${item.error ? `<div style="font-size:.7rem;color:var(--red);margin-top:3px">${item.error}</div>` : ''}
    </div>
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0">
      <span class="qi-status ${item.status}">${statusLabel}${retryBtn}</span>
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
    if (f.type === 'movie' && canQueueAdd) {
      const movieObj = JSON.stringify({
        stream_id: f.stream_id, type: 'movie', title: f.title,
        container_extension: ext, cover: f.cover, category: f.category,
        clean_title: f.title,
      }).replace(/"/g, '&quot;');
      actionBtn = `<button class="btn-q add" onclick="addMovieToQueue(${movieObj},this.closest('.card'))">+ Queue</button>`;
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
async function loadLog() {
  const wrap = document.getElementById('log-wrap');
  wrap.textContent = 'Lade…';
  const d = await api('cron_log');
  if (!d.lines?.length) { wrap.textContent = 'Kein Log vorhanden.'; return; }
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
  wrap.scrollTop = wrap.scrollHeight;
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
    searchDebounce = setTimeout(() => doSearch(input.value.trim()), 400);
  });
}

async function doSearch(q) {
  const movGrid = document.getElementById('search-movies-grid');
  const serGrid = document.getElementById('search-series-grid');
  if (!q) {
    if (movGrid) movGrid.innerHTML = emptyHTML('Suchbegriff eingeben');
    if (serGrid) serGrid.innerHTML = '';
    clearSelection();
    return;
  }
  if (searchTab === 'movies') {
    if (movGrid) movGrid.innerHTML = loadingHTML();
    const results = await api('search_movies', {q});
    allMovies = results;
    if (movGrid) {
      movGrid.innerHTML = results.length ? results.map(movieCard).join('') : emptyHTML('Keine Treffer');
      lazyLoadImages();
    }
  } else {
    if (serGrid) serGrid.innerHTML = loadingHTML();
    const results = await api('search_series', {q});
    if (serGrid) {
      serGrid.innerHTML = results.length ? results.map(seriesCard).join('') : emptyHTML('Keine Treffer');
      lazyLoadImages();
    }
  }
  clearSelection();
}

// ── View management ───────────────────────────────────────────
function showView(v) {
  // Auf mobilen Geräten Sidebar schließen wenn eine View gewählt wird
  if (window.innerWidth <= 768) closeSidebar();
  ['dashboard','movies','series','search','queue','log','settings','users','activity-log','profile','favourites'].forEach(name => {
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
  if (v === 'search')       { document.getElementById('page-title').textContent = 'Suche'; initSearch(); document.getElementById('search-input').focus(); }
  if (v === 'dashboard')    { document.getElementById('page-title').textContent = 'Dashboard'; <?php if (!$can_settings): ?>loadLibrary();<?php endif; ?> <?php if ($can_settings): ?>startDashboardPolling();<?php endif; ?> }
  if (v === 'queue')        { document.getElementById('page-title').textContent = 'Download Queue'; refreshQueue(); startProgressPolling(); }
  if (v === 'log')          { document.getElementById('page-title').textContent = 'Cron Log'; loadLog(); stopProgressPolling(); }
  if (v === 'settings')     { document.getElementById('page-title').textContent = 'Einstellungen'; <?php if ($can_settings): ?>loadConfig(); loadCacheStatus(); loadApiKeys(); loadMaintenance();<?php endif; ?> }
  if (v === 'users')        { document.getElementById('page-title').textContent = 'Benutzer'; loadUsers(); }
  if (v === 'activity-log') { document.getElementById('page-title').textContent = 'Aktivitätslog'; loadActivityLog(); }
  if (v === 'profile')      { document.getElementById('page-title').textContent = 'Mein Profil'; document.getElementById('profile-msg').className = 'settings-msg'; }
  if (v === 'favourites')   { document.getElementById('page-title').textContent = 'Favoriten'; renderFavourites(); }
  clearInterval(queueRefreshInterval);
  if (v === 'queue') {
    // Progress- und Queue-Polling starten (unified — kein separates Queue-Interval nötig)
    // queueRefreshInterval bleibt leer, refreshQueue läuft über startProgressPolling
  }
  if (v !== 'queue') stopProgressPolling();
  <?php if ($can_settings): ?>if (v !== 'dashboard') stopDashboardPolling();<?php endif; ?>
  // Clear multi-select when leaving search
  if (v !== 'search') clearSelection();
}

// ── Settings ──────────────────────────────────────────────────
<?php if ($can_settings): ?>
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
  setSettingsMsg('', '');
}

function toggleRcloneFields(enabled) {
  const fields = document.getElementById('rclone-fields');
  if (fields) fields.style.display = enabled ? '' : 'none';
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
  };
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
    setSettingsMsg('✅ Einstellungen gespeichert', 'ok');
    loadStats();
    refreshDashboard();
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
        return `<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.03)">
          ${item.cover ? `<img src="${esc(item.cover)}" style="width:36px;height:36px;object-fit:cover;border-radius:4px;flex-shrink:0" onerror="this.style.display='none'">` : `<div style="width:36px;height:36px;background:var(--bg3);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0">${icon}</div>`}
          <div style="flex:1;min-width:0">
            <div style="font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(item.title)}</div>
            <div style="font-size:.65rem;color:var(--muted)">${esc(item.added_by)} · ${esc(item.added_at?.slice(0,10) ?? '')}</div>
          </div>
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

async function startQueue() {
  const d = await apiPost('queue_start', {});
  if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
  showToast(`▶ Download-Worker gestartet (${d.pending} ausstehend)`, 'success');
}
async function dashStartQueue() {
  await startQueue();
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
        <div style="display:flex;gap:6px">
          ${k.active ? `<button class="btn-icon" onclick="revokeApiKey('${k.id}')">⛔ Widerrufen</button>` : ''}
          <button class="btn-icon danger" onclick="deleteApiKey('${k.id}')">✕ Löschen</button>
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
<?php endif; ?>

// ── Live Progress Polling ─────────────────────────────────────
let progressInterval = null;

function startProgressPolling() {
  // Sofort ausführen
  pollProgress();
  refreshQueue();
  // Einziger Interval für beides — verhindert Race Condition
  if (!progressInterval) progressInterval = setInterval(async () => {
    await pollProgress();
    // Queue nur alle 10s aktualisieren (5 × 2s)
    if (!progressInterval._tick) progressInterval._tick = 0;
    progressInterval._tick++;
    if (progressInterval._tick % 5 === 0) refreshQueue();
  }, 2000);
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
  // Beide sofort ausführen, dann sequenziell alle 5s — kein Race Condition
  pollProgress().then(() => refreshDashboardQueue());
  if (!dashboardInterval) dashboardInterval = setInterval(async () => {
    await pollProgress();
    await refreshDashboardQueue();
  }, 5000);
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
let libraryData  = null;
let libActiveTab = 'movies';

async function loadLibrary(forceReload = false) {
  if (libraryData && !forceReload) { renderLibrary(); return; }
  libraryData = null;
  const loading = document.getElementById('lib-loading');
  const empty   = document.getElementById('lib-empty');
  if (loading) { loading.innerHTML = '<div class="spinner"></div><p>Lade Mediathek...</p>'; loading.style.display = ''; }
  if (empty)   empty.style.display = 'none';
  document.getElementById('lib-movies').style.display   = 'none';
  document.getElementById('lib-episodes').style.display = 'none';
  const data = await api('get_library');
  if (!data.cache_ready) {
    if (loading) loading.innerHTML = `
      <div class="icon" style="font-size:2rem;opacity:.5">📦</div>
      <p style="max-width:320px">Der Medien-Cache wurde noch nicht aufgebaut.<br>
      Bitte wende dich an deinen Administrator.</p>`;
    return;
  }
  libraryData = data;
  // Kategorien-Dropdown befüllen
  const catSel = document.getElementById('lib-cat-filter');
  if (catSel && data.categories?.length) {
    const cur = catSel.value;
    catSel.innerHTML = '<option value="">Alle Kategorien</option>' +
      data.categories.map(c => `<option value="${esc(c)}"${c===cur?' selected':''}>${esc(c)}</option>`).join('');
  }
  renderLibrary();
}

async function filterLibrary(reset = false) {
  if (reset) {
    const s = document.getElementById('lib-search');
    const c = document.getElementById('lib-cat-filter');
    if (s) s.value = '';
    if (c) c.value = '';
  }
  const q   = (document.getElementById('lib-search')?.value ?? '').trim().toLowerCase();
  const cat = document.getElementById('lib-cat-filter')?.value ?? '';
  // Wenn Filter gesetzt → frisch vom Server laden (serverseitig gefiltert)
  const loading = document.getElementById('lib-loading');
  const empty   = document.getElementById('lib-empty');
  if (loading) { loading.innerHTML = '<div class="spinner"></div><p>Filtere…</p>'; loading.style.display = ''; }
  document.getElementById('lib-movies').style.display   = 'none';
  document.getElementById('lib-episodes').style.display = 'none';
  const params = {};
  if (q)   params.q        = q;
  if (cat) params.category = cat;
  const data = await api('get_library', params);
  if (!data.cache_ready) {
    if (loading) loading.innerHTML = `<div class="icon" style="font-size:2rem;opacity:.5">📦</div><p>Kein Cache vorhanden.</p>`;
    return;
  }
  libraryData = data;
  renderLibrary();
}

function renderLibrary() {
  const loading = document.getElementById('lib-loading');
  const empty   = document.getElementById('lib-empty');
  const movGrid = document.getElementById('lib-movies');
  const epGrid  = document.getElementById('lib-episodes');
  const cntMov  = document.getElementById('lib-count-movies');
  const cntEp   = document.getElementById('lib-count-episodes');
  if (loading) loading.style.display = 'none';
  if (cntMov)  cntMov.textContent = libraryData.movies.length;
  if (cntEp)   cntEp.textContent  = libraryData.episodes.length;
  if (!libraryData.total) { if (empty) empty.style.display = ''; return; }
  if (movGrid) {
    movGrid.innerHTML = libraryData.movies.length
      ? libraryData.movies.map(libCard).join('')
      : `<div class="state-box" style="grid-column:1/-1"><div class="icon">📭</div><p>Keine Filme gefunden</p></div>`;
    movGrid.style.display = libActiveTab === 'movies' ? '' : 'none';
    lazyLoadImages();
  }
  if (epGrid) {
    epGrid.innerHTML = libraryData.episodes.length
      ? libraryData.episodes.map(libCard).join('')
      : `<div class="state-box" style="grid-column:1/-1"><div class="icon">📭</div><p>Keine Episoden gefunden</p></div>`;
    epGrid.style.display = libActiveTab === 'episodes' ? '' : 'none';
    lazyLoadImages();
  }
}

function libCard(item) {
  const thumb = item.cover ? `<img data-src="${item.cover}" alt="">` : '';
  const icon  = item.type === 'episode' ? '📺' : '🎬';
  return `
  <div class="card downloaded">
    <div class="card-thumb">
      <div class="card-thumb-placeholder">${icon}</div>
      ${thumb}
      <span class="card-badge badge-done">✓</span>
    </div>
    <div class="card-body">
      <div class="card-title">${esc(item.title)}</div>
      <div class="card-meta">${esc(item.category)}</div>
    </div>
  </div>`;
}

function switchLibTab(tab, btn) {
  libActiveTab = tab;
  document.querySelectorAll('#lib-tab-movies, #lib-tab-episodes').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  const movGrid = document.getElementById('lib-movies');
  const epGrid  = document.getElementById('lib-episodes');
  if (movGrid) movGrid.style.display = tab === 'movies'   ? '' : 'none';
  if (epGrid)  epGrid.style.display  = tab === 'episodes' ? '' : 'none';
}
<?php endif; ?>

// ── Auth JS ─────────────────────────────────────────────────────
async function doLogout() {
  await api('logout');
  window.location.href = 'login.php';
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
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted)">Keine Benutzer gefunden</td></tr>';
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
    return `
    <tr style="${isSuspended ? 'opacity:.6' : ''}">
      <td>
        <strong>${esc(u.username)}</strong>
        <div style="font-size:.65rem;color:var(--muted);font-family:'DM Mono',monospace;cursor:pointer" onclick="viewUserActivity('${esc(u.id)}')">📋 Log</div>
      </td>
      <td><span class="role-badge ${u.role}">${u.role}</span></td>
      <td>${statusBadge}</td>
      <td style="color:var(--muted);font-family:'DM Mono',monospace;font-size:.72rem">${u.created_at ?? '–'}</td>
      <td style="color:var(--muted);font-family:'DM Mono',monospace;font-size:.72rem">${u.last_login ?? 'Noch nie'}</td>
      <td>
        <div class="user-actions">
          <button class="btn-icon" onclick="openEditUser('${esc(u.id)}','${esc(u.username)}','${u.role}')">✏️ Bearbeiten</button>
          ${suspendBtn}
          <button class="btn-icon danger" onclick="deleteUser('${esc(u.id)}','${esc(u.username)}')">✕ Löschen</button>
        </div>
      </td>
    </tr>`}).join('');
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
