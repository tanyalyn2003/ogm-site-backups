<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

adminSendNoIndexHeaders();
adminStartSession();

function adminSafeNextPath($next) {
  $next = trim((string) $next);
  if ($next === '') {
    return 'index.php';
  }

  // Only allow same-site relative redirects.
  if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $next)) {
    return 'index.php';
  }
  if (str_starts_with($next, '//')) {
    return 'index.php';
  }

  $parsed = parse_url($next);
  if (!is_array($parsed)) {
    return 'index.php';
  }
  if (!empty($parsed['host']) || !empty($parsed['scheme'])) {
    return 'index.php';
  }

  $path = (string) ($parsed['path'] ?? '');
  if ($path === '') {
    $path = 'index.php';
  }
  if (!str_starts_with($path, '/')) {
    // Make it relative to the admin folder.
    $path = 'index.php';
  }

  $query = isset($parsed['query']) && $parsed['query'] !== '' ? ('?' . $parsed['query']) : '';
  return $path . $query;
}

$nextTarget = adminSafeNextPath($_GET['next'] ?? '');

if (adminIsLoggedIn()) {
  header('Location: ' . $nextTarget);
  exit;
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim((string) ($_POST['username'] ?? ''));
  $password = (string) ($_POST['password'] ?? '');

  if (adminAttemptLogin($username, $password)) {
    header('Location: ' . $nextTarget);
    exit;
  }

  $errorMessage = 'Login failed. Check the username and password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales Login | Olive Glass & Marble</title>
  <style>
    :root {
      color-scheme: light;
      --bg: #f6f1e8;
      --panel: #fffaf2;
      --line: #d9c6ac;
      --text: #2b241d;
      --muted: #6b5a49;
      --accent: #1f5b4f;
      --accent-strong: #133f36;
      --error: #9e2f2f;
      --shadow: 0 28px 60px rgba(43, 36, 29, 0.12);
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: "Avenir Next", "Segoe UI", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(201, 168, 124, 0.28), transparent 34%),
        linear-gradient(160deg, #fbf7f1 0%, #f1e7d7 100%);
      display: grid;
      place-items: center;
      padding: 24px;
    }

    .card {
      width: min(100%, 420px);
      background: rgba(255, 250, 242, 0.96);
      border: 1px solid rgba(217, 198, 172, 0.9);
      border-radius: 24px;
      box-shadow: var(--shadow);
      padding: 32px;
      backdrop-filter: blur(10px);
    }

    h1 {
      margin: 0 0 10px;
      font-size: 2rem;
      line-height: 1.1;
    }

    p {
      margin: 0 0 24px;
      color: var(--muted);
      line-height: 1.6;
    }

    label {
      display: block;
      font-size: 0.92rem;
      font-weight: 700;
      margin-bottom: 8px;
    }

    .field {
      margin-bottom: 18px;
    }

    input {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 14px 16px;
      font: inherit;
      background: #fff;
      color: var(--text);
    }

    input:focus {
      outline: 2px solid rgba(31, 91, 79, 0.2);
      border-color: var(--accent);
    }

    button {
      width: 100%;
      border: 0;
      border-radius: 999px;
      padding: 14px 18px;
      font: inherit;
      font-weight: 700;
      color: #fff;
      background: linear-gradient(135deg, var(--accent) 0%, var(--accent-strong) 100%);
      cursor: pointer;
    }

    .error {
      margin: 0 0 18px;
      padding: 12px 14px;
      border-radius: 14px;
      background: rgba(158, 47, 47, 0.08);
      color: var(--error);
      font-weight: 600;
    }
  </style>
</head>
<body>
  <main class="card">
    <h1>Sales Dashboard</h1>
    <p>Private lead review area for Olive Glass &amp; Marble sales reps.</p>
    <?php if ($errorMessage !== ''): ?>
      <div class="error"><?php echo adminEscape($errorMessage); ?></div>
    <?php endif; ?>
    <form method="post" action="login.php">
      <div class="field">
        <label for="username">Username</label>
        <input id="username" name="username" type="text" autocomplete="username" required>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
      </div>
      <button type="submit">Sign in</button>
    </form>
  </main>
</body>
</html>
