<?php
/**
 * invite.php – Einladungslink-Registrierung
 * Öffentlich zugänglich, kein Login erforderlich.
 * URL: /invite.php?token=<token>
 */
require_once __DIR__ . '/config.php';

$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = false;
$invite  = null;

function load_invites(): array {
    if (!file_exists(INVITES_FILE)) return [];
    return json_decode(file_get_contents(INVITES_FILE), true) ?? [];
}

function save_invites(array $invites): void {
    file_put_contents(INVITES_FILE, json_encode($invites, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Token prüfen
if ($token === '') {
    $error = 'Kein Einladungstoken angegeben.';
} else {
    $invites = load_invites();
    if (!isset($invites[$token])) {
        $error = 'Dieser Einladungslink ist ungültig oder wurde bereits verwendet.';
    } elseif ($invites[$token]['used']) {
        $error = 'Dieser Einladungslink wurde bereits verwendet.';
    } elseif ($invites[$token]['expires_at'] < date('Y-m-d H:i:s')) {
        $error = 'Dieser Einladungslink ist abgelaufen.';
    } else {
        $invite = $invites[$token];
    }
}

// Formular verarbeiten
if ($invite && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/auth.php';

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($username === '' || strlen($username) < 3) {
        $error = 'Benutzername muss mindestens 3 Zeichen haben.';
    } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username)) {
        $error = 'Benutzername darf nur Buchstaben, Zahlen, _ - . enthalten.';
    } elseif (strlen($password) < 6) {
        $error = 'Passwort muss mindestens 6 Zeichen haben.';
    } elseif ($password !== $password2) {
        $error = 'Passwörter stimmen nicht überein.';
    } elseif (find_user($username)) {
        $error = 'Dieser Benutzername ist bereits vergeben.';
    } else {
        // Benutzer anlegen
        $result = create_user($username, $password, $invite['role']);
        if (is_string($result)) {
            $error = $result;
        } else {
            // Einladung als verwendet markieren
            $invites = load_invites();
            if (isset($invites[$token])) {
                $invites[$token]['used']    = true;
                $invites[$token]['used_by'] = $username;
                $invites[$token]['used_at'] = date('Y-m-d H:i:s');
                save_invites($invites);
            }
            $success = true;
            $invite  = null;
        }
    }
}

$roleLabels = ['viewer' => 'Viewer', 'editor' => 'Editor', 'admin' => 'Admin'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Xtream Vault — Registrierung</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0f0f0f; --bg2: #161616; --bg3: #1e1e1e;
    --border: rgba(255,255,255,.1); --text: #f0f0f0; --muted: #888;
    --accent: #e8ff47; --accent2: #64d2ff; --red: #ff4757; --green: #2ed573;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    background: var(--bg); color: var(--text);
    font-family: 'DM Sans', sans-serif;
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
    padding: 20px;
  }
  .box {
    background: var(--bg2); border: 1px solid var(--border); border-radius: 12px;
    padding: 40px; width: 100%; max-width: 420px;
  }
  .logo { font-family: 'Bebas Neue', sans-serif; font-size: 2rem; letter-spacing: .12em; color: var(--accent); margin-bottom: 4px; }
  .subtitle { font-family: 'DM Mono', monospace; font-size: .65rem; color: var(--muted); letter-spacing: .2em; margin-bottom: 32px; }
  h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 6px; }
  .invite-meta {
    background: var(--bg3); border: 1px solid var(--border); border-radius: 8px;
    padding: 12px 16px; margin-bottom: 24px; font-size: .82rem;
  }
  .invite-role {
    display: inline-block; background: rgba(232,255,71,.12); color: var(--accent);
    border: 1px solid rgba(232,255,71,.3); border-radius: 20px;
    padding: 2px 10px; font-family: 'DM Mono', monospace; font-size: .68rem;
    font-weight: 500; margin-top: 4px;
  }
  .field { margin-bottom: 16px; }
  .field label { display: block; font-family: 'DM Mono', monospace; font-size: .65rem; color: var(--muted); letter-spacing: .1em; text-transform: uppercase; margin-bottom: 6px; }
  .field input {
    width: 100%; background: var(--bg3); border: 1px solid var(--border);
    border-radius: 6px; padding: 10px 14px; color: var(--text);
    font-family: 'DM Sans', sans-serif; font-size: .9rem; outline: none;
    transition: border-color .15s;
  }
  .field input:focus { border-color: var(--accent); }
  .btn {
    width: 100%; padding: 12px; background: var(--accent); color: #000;
    border: none; border-radius: 6px; font-weight: 700; font-size: .9rem;
    cursor: pointer; transition: opacity .15s; margin-top: 8px;
  }
  .btn:hover { opacity: .88; }
  .error {
    background: rgba(255,71,87,.1); border: 1px solid rgba(255,71,87,.3);
    border-radius: 6px; padding: 12px 16px; color: var(--red);
    font-size: .85rem; margin-bottom: 20px;
  }
  .success {
    background: rgba(46,213,115,.1); border: 1px solid rgba(46,213,115,.3);
    border-radius: 6px; padding: 20px; text-align: center;
  }
  .success .icon { font-size: 2.5rem; margin-bottom: 12px; }
  .success h3 { color: var(--green); margin-bottom: 8px; }
  .success p { color: var(--muted); font-size: .85rem; line-height: 1.5; }
  .login-link {
    display: block; text-align: center; margin-top: 20px;
    color: var(--accent2); font-size: .85rem; text-decoration: none;
  }
  .login-link:hover { text-decoration: underline; }
  .expires { font-size: .75rem; color: var(--muted); margin-top: 4px; }
</style>
</head>
<body>
<div class="box">
  <div class="logo">XTREAM VAULT</div>
  <div class="subtitle">EINLADUNG ZUR REGISTRIERUNG</div>

  <?php if ($success): ?>
  <div class="success">
    <div class="icon">✅</div>
    <h3>Konto erstellt!</h3>
    <p>Dein Konto wurde erfolgreich angelegt. Du kannst dich jetzt einloggen.</p>
  </div>
  <a href="index.php" class="login-link">→ Zum Login</a>

  <?php elseif ($error && !$invite): ?>
  <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
  <p style="color:var(--muted);font-size:.85rem;text-align:center">
    Bitte wende dich an einen Administrator für einen neuen Link.
  </p>

  <?php else: ?>
  <h2>Konto anlegen</h2>
  <div class="invite-meta">
    <div style="color:var(--muted);font-size:.75rem;margin-bottom:4px">Eingeladen von <strong style="color:var(--text)"><?= htmlspecialchars($invite['created_by']) ?></strong></div>
    <span class="invite-role"><?= htmlspecialchars($roleLabels[$invite['role']] ?? $invite['role']) ?></span>
    <?php if (!empty($invite['note'])): ?>
    <div style="margin-top:8px;font-size:.8rem;color:var(--muted)"><?= htmlspecialchars($invite['note']) ?></div>
    <?php endif; ?>
    <div class="expires">Gültig bis <?= htmlspecialchars($invite['expires_at']) ?></div>
  </div>

  <?php if ($error): ?>
  <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" action="invite.php?token=<?= htmlspecialchars($token) ?>">
    <div class="field">
      <label>Benutzername</label>
      <input type="text" name="username" autocomplete="username" required
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
             placeholder="Mindestens 3 Zeichen">
    </div>
    <div class="field">
      <label>Passwort</label>
      <input type="password" name="password" autocomplete="new-password" required placeholder="Mindestens 6 Zeichen">
    </div>
    <div class="field">
      <label>Passwort bestätigen</label>
      <input type="password" name="password2" autocomplete="new-password" required placeholder="Passwort wiederholen">
    </div>
    <button type="submit" class="btn">Konto erstellen</button>
  </form>
  <?php endif; ?>
</div>
</body>
</html>
