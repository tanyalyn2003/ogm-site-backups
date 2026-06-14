<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

sopSendNoIndexHeaders();
sopStartSession();

if (sopIsLoggedIn()) {
  header('Location: index.php');
  exit;
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim((string) ($_POST['username'] ?? ''));
  $password = (string) ($_POST['password'] ?? '');

  if (sopAttemptLogin($username, $password)) {
    header('Location: index.php');
    exit;
  }

  $errorMessage = 'Login failed. Check the user ID and password.';
}

$styleVersion = (string) (@filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'styles.css') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>OGM SOP Login</title>
  <link rel="stylesheet" href="styles.css?v=<?php echo sopEscape($styleVersion); ?>">
</head>
<body class="login-shell">
  <div class="screen-wash"></div>
  <main class="login-card">
    <section class="login-visual">
      <p class="eyebrow">OGM Internal Knowledge Base</p>
      <h1>SOP &amp; Document Handler</h1>
      <p>Managers can update tabs, user access, and Google Doc links. Employees get clean read-only access to the handbook.</p>

      <div class="login-visual__stack">
        <div class="mini-tab" style="--mini-color: #f1b43b;">Sales &amp; Marketing</div>
        <div class="mini-tab" style="--mini-color: #eb7540;">Operations</div>
        <div class="mini-tab" style="--mini-color: #bed547;">Prefabrication</div>
        <div class="mini-tab" style="--mini-color: #56b8ed;">Fabrication</div>
        <div class="mini-tab" style="--mini-color: #d856a4;">Instalation</div>
        <div class="mini-tab" style="--mini-color: #5d4da6;">Accounting &amp; Finance</div>
      </div>
    </section>

    <section class="login-form">
      <div class="login-form__header">
        <p class="eyebrow">Secure Login</p>
        <h2>Sign in to the SOP portal</h2>
      </div>

      <?php if ($errorMessage !== ''): ?>
        <div class="flash flash--error">
          <?php echo sopEscape($errorMessage); ?>
        </div>
      <?php endif; ?>

      <form method="post" action="login.php">
        <label>
          <span>User ID</span>
          <input name="username" type="text" autocomplete="username" required>
        </label>

        <label>
          <span>Password</span>
          <input name="password" type="password" autocomplete="current-password" required>
        </label>

        <button class="button" type="submit">Enter Portal</button>
      </form>
    </section>
  </main>
</body>
</html>
