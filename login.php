<?php
require_once __DIR__ . '/auth.php';

session_start_safe();

// Logout immer zuerst verarbeiten — auch wenn Wartungsmodus aktiv
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    logout();
    header('Location: login.php');
    exit;
}

// Bereits eingeloggt → weiterleiten (außer im Wartungsmodus für Nicht-Admins)
$currentUser = current_user();
if ($currentUser) {
    if (file_exists(MAINTENANCE_FILE) && $currentUser['role'] !== 'admin') {
        // Im Wartungsmodus nicht weiterleiten — Login-Seite zeigen mit Meldung
    } else {
        header('Location: index.php');
        exit;
    }
}

$mode  = users_exist() ? 'login' : 'setup';
$error = '';

// ── POST-Handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // CSRF-Prüfung für alle Formular-Posts außer logout
    if ($action !== 'logout' && !csrf_verify()) {
        $error = 'Ungültige Anfrage — bitte Seite neu laden.';
        $action = ''; // Kein weiteres Processing
    }

    if ($action === 'login') {
        $result = attempt_login(trim($_POST['username'] ?? ''), $_POST['password'] ?? '');
        // Wartungsmodus: erfolgreicher Login nur für Admins
        if ($result === true && file_exists(DATA_DIR . '/maintenance.lock')) {
            $loggedIn = current_user();
            if ($loggedIn && $loggedIn['role'] !== 'admin') {
                // Session wieder abmelden
                session_unset();
                $error = 'Die Seite befindet sich im Wartungsmodus. Bitte versuche es später erneut.';
                $result = false;
            }
        }
        if ($result === 'suspended') {
            $error = 'Dein Konto wurde gesperrt. Bitte kontaktiere einen Administrator.';
        } elseif ($result) {
            header('Location: index.php');
            exit;
        } else {
            $error = 'Ungültiger Benutzername oder Passwort';
        }
    }

    if ($action === 'setup' && !users_exist()) {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password']         ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';
        if ($password !== $confirm) {
            $error = 'Passwörter stimmen nicht überein';
        } else {
            $result = create_user($username, $password, 'admin');
            if (is_string($result)) {
                $error = $result;
            } else {
                // ── Cronjobs automatisch einrichten ──────────────────────────
                $cronScript    = __DIR__ . '/cron.php';
                $cacheScript   = __DIR__ . '/cache_builder.php';
                $backupScript  = __DIR__ . '/backup.php';
                $phpBin        = PHP_BINARY ?: '/usr/bin/php';

                $newJobs = [
                    "*/30 * * * * {$phpBin} {$cronScript} >> /dev/null 2>&1",
                    "0 4 * * * {$phpBin} {$cacheScript} >> /dev/null 2>&1",
                    "0 3 * * * {$phpBin} {$backupScript} >> /dev/null 2>&1",
                ];

                // Bestehende Crontab lesen (Fehler ignorieren falls noch leer)
                exec('crontab -l 2>/dev/null', $existing);
                $existing = array_filter($existing, fn($l) => trim($l) !== '');
                $currentCrontab = implode("\n", $existing);

                // Nur hinzufügen wenn noch nicht vorhanden (idempotent)
                if (!str_contains($currentCrontab, $cronScript)) {
                    $existing[] = $newJobs[0];
                }
                if (!str_contains($currentCrontab, $cacheScript)) {
                    $existing[] = $newJobs[1];
                }
                if (!str_contains($currentCrontab, $backupScript)) {
                    $existing[] = $newJobs[2];
                }

                $newCrontab = implode("\n", $existing) . "\n";
                $tmp = tempnam(sys_get_temp_dir(), 'crontab_');
                file_put_contents($tmp, $newCrontab);
                exec("crontab $tmp");
                @unlink($tmp);
                // ─────────────────────────────────────────────────────────────

                attempt_login($username, $password);
                header('Location: index.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $mode === 'setup' ? 'Setup – ' . cfg('app_title', 'Xtream Vault') : 'Login – ' . cfg('app_title', 'Xtream Vault') ?></title>
<link rel="icon" type="image/svg+xml" href="logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Mono:wght@400;500&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg:      #0a0a0f;
  --bg2:     #111118;
  --bg3:     #18181f;
  --border:  rgba(255,255,255,.07);
  --accent:  #e8ff47;
  --red:     #ff4757;
  --text:    #e8e8f0;
  --muted:   #5a5a70;
}
[data-theme="amoled"]   { --bg:#000; --bg2:#080808; --bg3:#111; --border:rgba(255,255,255,.08); --text:#f0f0f0; --muted:#505050; --accent:#e8ff47; }
[data-theme="midnight"] { --bg:#0a0e1a; --bg2:#111828; --bg3:#1a2235; --border:rgba(100,160,255,.1); --text:#d0e0ff; --muted:#5070a0; --accent:#64a0ff; }
[data-theme="light"]    { --bg:#f0f0f5; --bg2:#fff; --bg3:#e8e8ef; --border:rgba(0,0,0,.1); --text:#1a1a2e; --muted:#7a7a90; --accent:#6060e0; --red:#cc2020; }
[data-theme="nord"]     { --bg:#2e3440; --bg2:#3b4252; --bg3:#434c5e; --border:rgba(216,222,233,.12); --text:#eceff4; --muted:#7b88a1; --accent:#88c0d0; }
[data-theme="tokyo"]    { --bg:#1a1b2e; --bg2:#16213e; --bg3:#0f3460; --border:rgba(122,162,247,.12); --text:#c0caf5; --muted:#565f89; --accent:#7aa2f7; }
[data-theme="rosepine"] { --bg:#191724; --bg2:#1f1d2e; --bg3:#26233a; --border:rgba(196,167,231,.1); --text:#e0def4; --muted:#6e6a86; --accent:#ebbcba; }
</style>
<script>
// Theme vor dem Rendern setzen um Flash zu vermeiden
(function() {
  // Versuche user-spezifischen Key, dann generischen Fallback
  var theme = null;
  try {
    // Alle xv_theme_* Keys durchsuchen
    for (var i = 0; i < localStorage.length; i++) {
      var key = localStorage.key(i);
      if (key && key.startsWith('xv_theme_')) {
        theme = localStorage.getItem(key);
        break;
      }
    }
    if (!theme) theme = localStorage.getItem('xv_theme');
  } catch(e) {}
  if (theme && theme !== 'dark') {
    document.documentElement.setAttribute('data-theme', theme);
  }
})();
</script>
<style>
body {
  background: var(--bg); color: var(--text);
  font-family: 'DM Sans', sans-serif;
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  padding: 24px;
}
body::before {
  content: '';
  position: fixed; inset: 0;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");
  pointer-events: none; z-index: 0; opacity: .5;
}
.card {
  background: var(--bg2); border: 1px solid var(--border); border-radius: 12px;
  width: 100%; max-width: 380px; padding: 40px 36px;
  position: relative; z-index: 1;
  animation: rise .3s cubic-bezier(.4,0,.2,1);
}
@keyframes rise { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:none; } }
.logo { font-family: 'Bebas Neue', sans-serif; font-size: 2.2rem; letter-spacing: .12em; color: var(--accent); line-height: 1; }
.logo-sub { font-family: 'DM Mono', monospace; font-size: .6rem; color: var(--muted); letter-spacing: .2em; margin-top: 2px; }
.divider { height: 1px; background: var(--border); margin: 28px 0; }
.mode-label {
  font-family: 'DM Mono', monospace; font-size: .65rem; color: var(--muted);
  letter-spacing: .2em; text-transform: uppercase; margin-bottom: 20px;
}
<?php if ($mode === 'setup'): ?>
.setup-hint {
  background: rgba(232,255,71,.06); border: 1px solid rgba(232,255,71,.15);
  border-radius: 6px; padding: 12px 14px;
  font-size: .8rem; color: var(--accent); margin-bottom: 20px; line-height: 1.5;
}
<?php endif; ?>
.field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.field label { font-family: 'DM Mono', monospace; font-size: .65rem; color: var(--muted); letter-spacing: .1em; text-transform: uppercase; }
.field input {
  background: var(--bg3); border: 1px solid var(--border); border-radius: 6px;
  padding: 10px 12px; color: var(--text);
  font-family: 'DM Sans', sans-serif; font-size: .9rem;
  outline: none; transition: border-color .2s; width: 100%;
}
.field input:focus { border-color: var(--accent); }
.error-msg {
  background: rgba(255,71,87,.08); border: 1px solid rgba(255,71,87,.2);
  border-radius: 6px; padding: 10px 14px;
  font-size: .8rem; color: var(--red); margin-bottom: 16px;
  display: <?= $error ? 'block' : 'none' ?>;
}
.btn-submit {
  width: 100%; background: var(--accent); color: #000; border: none;
  border-radius: 7px; padding: 11px;
  font-family: 'DM Mono', monospace; font-size: .75rem; font-weight: 700;
  letter-spacing: .12em; text-transform: uppercase;
  cursor: pointer; transition: opacity .15s; margin-top: 6px;
}
.btn-submit:hover { opacity: .88; }
.btn-submit:active { opacity: .75; }
</style>
</head>
<body>
<div class="card">
  <div class="logo"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 160" style="width:26px;height:21px;vertical-align:middle;margin-right:10px;color:var(--accent)" fill="none" stroke="currentColor"><rect x="2.5" y="2.5" width="195" height="155" rx="30" stroke-width="10"/><line x1="100" y1="25" x2="100" y2="92" stroke-width="18" stroke-linecap="round"/><path d="M52 68 L100 116 L148 68" stroke-width="18" stroke-linecap="round" stroke-linejoin="round"/><line x1="48" y1="135" x2="152" y2="135" stroke-width="18" stroke-linecap="round"/></svg><?= htmlspecialchars(cfg('app_title', 'Xtream Vault')) ?></div>
  <div class="logo-sub">VOD Downloader</div>
  <div class="divider"></div>

  <?php if (file_exists(MAINTENANCE_FILE)): ?>
    <div style="background:rgba(255,71,87,.07);border:1px solid rgba(255,71,87,.2);border-radius:6px;padding:10px 14px;font-size:.8rem;color:#ff6b7a;margin-bottom:16px;line-height:1.5">
      🔧 Wartungsmodus aktiv — nur Admins können sich anmelden.
      <?php if ($currentUser): ?>
        <form method="post" style="display:inline;margin-left:6px">
          <input type="hidden" name="action" value="logout">
          <button type="submit" style="background:transparent;border:none;color:#ff6b7a;text-decoration:underline;cursor:pointer;font-size:.8rem;padding:0">Abmelden</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($mode === 'setup'): ?>
    <div class="mode-label">🔧 Ersteinrichtung</div>
    <div class="setup-hint">
      Willkommen! Lege jetzt deinen Administrator-Account an.<br>
      Weitere Benutzer können danach im Admin-Bereich hinzugefügt werden.
    </div>
    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <form method="post">
      <input type="hidden" name="action" value="setup">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <div class="field">
        <label>Benutzername</label>
        <input type="text" name="username" autocomplete="username" autofocus required
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Passwort</label>
        <input type="password" name="password" autocomplete="new-password" required minlength="6">
      </div>
      <div class="field">
        <label>Passwort bestätigen</label>
        <input type="password" name="password_confirm" autocomplete="new-password" required minlength="6">
      </div>
      <button type="submit" class="btn-submit">Admin-Account anlegen</button>
    </form>

  <?php else: ?>
    <div class="mode-label">Anmelden</div>
    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <form method="post">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <div class="field">
        <label>Benutzername</label>
        <input type="text" name="username" autocomplete="username" autofocus required
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Passwort</label>
        <input type="password" name="password" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn-submit">Anmelden</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
