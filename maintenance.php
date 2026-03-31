<?php
/**
 * maintenance.php – Wartungsseite
 * Wird von require_login() eingebunden wenn maintenance.lock aktiv ist
 * und der User kein Admin ist.
 */
http_response_code(503);
header('Retry-After: 3600');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Wartung – Xtream Vault</title>
<link rel="icon" type="image/svg+xml" href="logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Mono:wght@400;500&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg:     #0a0a0f;
  --bg2:    #111118;
  --border: rgba(255,255,255,.07);
  --accent: #e8ff47;
  --muted:  #5a5a70;
  --text:   #e8e8f0;
}
body {
  background: var(--bg); color: var(--text);
  font-family: 'DM Sans', sans-serif;
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  padding: 24px; text-align: center;
}
.card {
  background: var(--bg2); border: 1px solid var(--border); border-radius: 12px;
  padding: 48px 40px; max-width: 420px; width: 100%;
}
.icon { font-size: 3rem; margin-bottom: 20px; }
.logo { font-family: 'Bebas Neue', sans-serif; font-size: 2rem; letter-spacing: .12em; color: var(--accent); margin-bottom: 4px; }
.logo-sub { font-family: 'DM Mono', monospace; font-size: .6rem; color: var(--muted); letter-spacing: .2em; margin-bottom: 28px; }
h1 { font-size: 1.1rem; font-weight: 500; margin-bottom: 10px; }
p { color: var(--muted); font-size: .88rem; line-height: 1.6; }
</style>
</head>
<body>
<div class="card">
  <div class="icon">🔧</div>
  <div class="logo"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 160" style="width:26px;height:21px;vertical-align:middle;margin-right:10px;color:var(--accent)" fill="none" stroke="currentColor"><rect x="2.5" y="2.5" width="195" height="155" rx="30" stroke-width="10"/><line x1="100" y1="25" x2="100" y2="92" stroke-width="18" stroke-linecap="round"/><path d="M52 68 L100 116 L148 68" stroke-width="18" stroke-linecap="round" stroke-linejoin="round"/><line x1="48" y1="135" x2="152" y2="135" stroke-width="18" stroke-linecap="round"/></svg>Xtream Vault</div>
  <div class="logo-sub">VOD Downloader</div>
  <h1>Wartungsmodus aktiv</h1>
  <p>Die Seite wird gerade gewartet und ist vorübergehend nicht verfügbar.<br>Bitte versuche es später erneut.</p>
  <form method="post" action="login.php" style="margin-top:24px">
    <input type="hidden" name="action" value="logout">
    <button type="submit" style="background:transparent;border:1px solid rgba(255,255,255,.12);border-radius:6px;padding:8px 20px;color:var(--muted);font-family:'DM Mono',monospace;font-size:.65rem;letter-spacing:.1em;cursor:pointer">Abmelden</button>
  </form>
</div>
</body>
</html>
