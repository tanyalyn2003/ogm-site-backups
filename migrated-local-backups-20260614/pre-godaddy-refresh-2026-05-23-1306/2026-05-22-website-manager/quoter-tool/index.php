<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

$authConfigPath = __DIR__ . DIRECTORY_SEPARATOR . 'auth-config.php';
$authConfigMissing = !is_file($authConfigPath);

$errorMessage = '';

if (!qtIsLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST') {
  list($ok, $err) = qtAttemptLogin($_POST['username'] ?? '', $_POST['password'] ?? '');
  if ($ok) {
    $next = qtSafeNextPath($_GET['next'] ?? '') ?: (qtBasePath() . 'hub.php');
    // Persist session before redirect (some hosts defer write until shutdown and drop it on 302).
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }
    // Absolute URL avoids ambiguous relative Location after POST (some proxies / subpaths break it).
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '' && $next !== '' && str_starts_with($next, '/')) {
      $scheme = qtIsHttpsRequest() ? 'https' : 'http';
      $next = $scheme . '://' . $host . $next;
    }
    header('Location: ' . $next, true, 303);
    exit;
  }
  $errorMessage = $err;
}

if (!qtIsLoggedIn()) {
  $next = qtSafeNextPath($_GET['next'] ?? '') ?: '';
  $action = 'index.php' . ($next !== '' ? ('?next=' . urlencode($next)) : '');
  $hasPassword = (qtPasswordHash() !== '') || (count(qtPasswordsPlainList()) > 0);
  $needsConfig = !$hasPassword;
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quoter Tool Login</title>
    <style>
      * { box-sizing: border-box; }
      body {
        margin: 0;
        min-height: 100vh;
        font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
        background: #f6f6f6;
        display: grid;
        place-items: center;
        padding: 24px;
      }
      .card {
        width: min(100%, 440px);
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 14px;
        padding: 22px;
      }
      h1 { margin: 0 0 12px; font-size: 20px; }
      p { margin: 0 0 16px; color: #444; line-height: 1.4; }
      label { display: block; font-weight: 700; margin: 0 0 8px; }
      input {
        width: 100%;
        padding: 12px;
        border-radius: 10px;
        border: 1px solid #ccc;
        font: inherit;
      }
      button {
        margin-top: 14px;
        width: 100%;
        padding: 12px;
        border: 0;
        border-radius: 999px;
        background: #111;
        color: #fff;
        font-weight: 700;
        cursor: pointer;
      }
      .error {
        margin: 0 0 12px;
        padding: 10px 12px;
        border-radius: 10px;
        background: #fff0f0;
        border: 1px solid #f1bcbc;
        color: #8a1f1f;
        font-weight: 600;
      }
      .note {
        margin: 0 0 12px;
        padding: 10px 12px;
        border-radius: 10px;
        background: #fff8e6;
        border: 1px solid #f0d6a2;
        color: #5a3b00;
      }
      .critical {
        margin: 0 0 12px;
        padding: 10px 12px;
        border-radius: 10px;
        background: #fff0f0;
        border: 1px solid #e11d48;
        color: #881337;
        font-size: 14px;
        line-height: 1.45;
      }
      .critical code { font-size: 12px; }
    </style>
    <script>try{if(localStorage.getItem('ogm-theme')==='dark')document.documentElement.setAttribute('data-ogm-theme','dark');}catch(e){}</script>
    <link rel="stylesheet" href="ogm-theme-toggle.css?v=20260516p">
    <script src="ogm-theme-toggle.js?v=20260516o" defer></script>
  </head>
  <body>
    <main class="card">
      <h1>Quoter Tool</h1>
      <p>Sign in to access the internal quoter.</p>
      <?php if ($authConfigMissing): ?>
        <div class="critical">
          The file <code>auth-config.php</code> is missing in this folder on the server.
          Sign-in cannot work until you upload it (restore from your hosting backup or copy from the machine where login last worked).
          You can start from <code>auth-config.example.php</code>: copy it to <code>auth-config.php</code>, then set <code>password_hash</code> or <code>password_plain</code> per the comments inside.
        </div>
      <?php elseif ($needsConfig): ?>
        <div class="note">Login isn’t configured yet. Set a password hash (or plain passwords for testing only) in <code>auth-config.php</code>.</div>
      <?php endif; ?>
      <?php if ($errorMessage !== ''): ?>
        <div class="error"><?php echo qtEscape($errorMessage); ?></div>
      <?php endif; ?>
      <form method="post" action="<?php echo qtEscape($action); ?>">
        <label for="username">Username</label>
        <input id="username" name="username" type="text" autocomplete="username" required>
        <div style="height: 12px"></div>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
      </form>
    </main>
  </body>
  </html>
  <?php
  exit;
}

// Already logged in: forward to the requested destination if one was provided
// (covers stale tabs hitting index.php?next=… long after the original redirect).
$nextLogged = qtSafeNextPath($_GET['next'] ?? '');
if ($nextLogged !== '') {
  $bp = rtrim(qtBasePath(), '/');
  $defaultHome = ($bp === '' ? '' : $bp) . '/index.php';
  if ($nextLogged !== $defaultHome) {
    header('Location: ' . $nextLogged, true, 303);
    exit;
  }
}

// Serve the internal quoter UI. The raw HTML file is blocked from direct access via .htaccess.
$path = __DIR__ . DIRECTORY_SEPARATOR . 'ogm-quoter-internal.html';
if (!is_file($path)) {
  qtRenderMissingPage(
    'Quoter unavailable',
    'The quoter page is temporarily unavailable. Sign in again to continue.',
    $_GET['next'] ?? ''
  );
  exit;
}

header('Content-Type: text/html; charset=UTF-8');
readfile($path);
