<?php

function qtEscape($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function qtIsHttpsRequest() {
	// Behind nginx, Cloudflare, cPanel, etc. the app often sees HTTP while the browser uses HTTPS.
	// If we miss that, session cookies get Secure=false when they need Secure=true (or the reverse),
	// and the browser drops the session — login appears to succeed then loops back to this page.
	$xfProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
	if ($xfProto !== '') {
		$xfProto = trim(explode(',', $xfProto)[0]);
	}
	if ($xfProto === 'https') {
		return true;
	}
	if ($xfProto === 'http') {
		return false;
	}

	$xfSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
	if ($xfSsl === 'on') {
		return true;
	}

	if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
		return true;
	}
	return ((int) ($_SERVER['SERVER_PORT'] ?? 0)) === 443;
}

function qtSendNoIndexHeaders() {
	header('X-Robots-Tag: noindex, nofollow', true);
	header('Cache-Control: private, no-store, max-age=0', true);
}

function qtBasePath() {
	$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
	$scriptName = str_replace('\\', '/', $scriptName);
	$dir = rtrim(dirname($scriptName), '/');
	if ($dir === '' || $dir === '.') {
		$dir = '/';
	}
	if ($dir !== '/' && !str_ends_with($dir, '/')) {
		$dir .= '/';
	}
	return $dir;
}

function qtSessionCookiePath() {
	$bp = qtBasePath();
	if ($bp === '/' || $bp === '') {
		return '/';
	}
	return rtrim(str_replace('\\', '/', $bp), '/') . '/';
}

function qtStartSession() {
	if (session_status() === PHP_SESSION_ACTIVE) {
		return;
	}

	session_name('ogm_quoter_tool');
	session_set_cookie_params([
		'lifetime' => 0,
		'path' => qtSessionCookiePath(),
		'domain' => '',
		'secure' => qtIsHttpsRequest(),
		'httponly' => true,
		'samesite' => 'Lax',
	]);
	session_start();
}

function qtConfig() {
	static $config = null;
	if ($config !== null) {
		return $config;
	}

	$path = __DIR__ . DIRECTORY_SEPARATOR . 'auth-config.php';
	$loaded = is_file($path) ? require $path : [];
	$config = is_array($loaded) ? $loaded : [];
	return $config;
}

function qtPasswordHash() {
	$config = qtConfig();
	return trim((string) ($config['password_hash'] ?? ''));
}

function qtPasswordPlain() {
	$config = qtConfig();
	return $config['password_plain'] ?? '';
}

function qtUsername() {
	$config = qtConfig();
	return trim((string) ($config['username'] ?? ''));
}

function qtPasswordsPlainList() {
	$plain = qtPasswordPlain();
	if (is_array($plain)) {
		$out = [];
		foreach ($plain as $item) {
			$item = (string) $item;
			if ($item !== '') {
				$out[] = $item;
			}
		}
		return $out;
	}
	$plain = (string) $plain;
	return $plain === '' ? [] : [$plain];
}

function qtIsLoggedIn() {
	qtStartSession();
	return !empty($_SESSION['qt_logged_in']);
}

/** Display name for message logs (session or config username). */
function qtCurrentUser() {
	qtStartSession();
	if (!empty($_SESSION['qt_display_name'])) {
		return trim((string) $_SESSION['qt_display_name']);
	}
	$u = trim((string) ($_SESSION['qt_username'] ?? ''));
	if ($u !== '') {
		return $u;
	}
	$configUser = qtUsername();
	return $configUser !== '' ? $configUser : 'Staff';
}

function qtStaffRoster() {
	return [
		'Tanya Wadkins (TW)',
		'Austen Parlett (AP)',
		'Brennan Binkley (BB)',
		'G Sedberry Olive (SO)',
		'G Hunter Olive (HO)',
	];
}

function qtSafeNextPath($next) {
	$next = trim((string) $next);
	if ($next === '') {
		return '';
	}

	// Only allow same-site relative paths.
	if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $next)) {
		return '';
	}
	if (str_starts_with($next, '//')) {
		return '';
	}

	$parsed = parse_url($next);
	if (!is_array($parsed)) {
		return '';
	}
	if (!empty($parsed['host']) || !empty($parsed['scheme'])) {
		return '';
	}

	$path = (string) ($parsed['path'] ?? '');
	if ($path === '' || !str_starts_with($path, '/')) {
		return '';
	}

	$query = isset($parsed['query']) && $parsed['query'] !== '' ? ('?' . $parsed['query']) : '';
	return $path . $query;
}


function qtAttemptLogin($username, $password) {
	$username = trim((string) $username);
	$password = (string) $password;
	$hash = qtPasswordHash();
	$passwords = qtPasswordsPlainList();
	$expectedUsername = qtUsername();
	if ($hash === '' && !$passwords) {
		return [false, 'Login is not configured yet.'];
	}
	if ($expectedUsername !== '') {
		if ($username === '') {
			return [false, 'Enter the username.'];
		}
		// Case-sensitive on purpose.
		if (!hash_equals($expectedUsername, $username)) {
			return [false, 'Wrong username.'];
		}
	}
	if ($password === '') {
		return [false, 'Enter the password.'];
	}

	if ($hash !== '') {
		if (!password_verify($password, $hash)) {
			return [false, 'Wrong password.'];
		}
	} else {
		$ok = false;
		foreach ($passwords as $expected) {
			if (hash_equals($expected, $password)) {
				$ok = true;
				break;
			}
		}
		if (!$ok) {
			return [false, 'Wrong password.'];
		}
	}

	qtStartSession();
	session_regenerate_id(true);
	$_SESSION['qt_logged_in'] = true;
	$_SESSION['qt_username'] = $username !== '' ? $username : qtUsername();
	$_SESSION['qt_display_name'] = $_SESSION['qt_username'];

	return [true, ''];
}

/**
 * Friendly “page not found / session expired” response for served HTML files.
 * Always offers a sign-in link instead of a bare 404 string.
 */
function qtRenderMissingPage($title, $bodyMessage, $nextPath = '') {
	http_response_code(404);
	header('Content-Type: text/html; charset=UTF-8');
	$bp = rtrim(qtBasePath(), '/');
	$loginHref = ($bp === '' ? '' : $bp) . '/index.php';
	$nextSafe = qtSafeNextPath($nextPath);
	if ($nextSafe !== '') {
		$loginHref .= '?next=' . rawurlencode($nextSafe);
	}
	$titleHtml = qtEscape($title);
	$bodyHtml = qtEscape($bodyMessage);
	$loginHtml = qtEscape($loginHref);
	echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
	echo '<title>' . $titleHtml . '</title><style>';
	echo 'body{margin:0;min-height:100vh;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f6f6f6;display:grid;place-items:center;padding:24px;color:#1c1917}';
	echo '.card{width:min(100%,440px);background:#fff;border:1px solid #ddd;border-radius:14px;padding:22px;text-align:center}';
	echo 'h1{margin:0 0 12px;font-size:20px}p{margin:0 0 16px;color:#444;line-height:1.5}';
	echo 'a.btn{display:inline-block;padding:12px 22px;border-radius:999px;background:#111;color:#fff;font-weight:700;text-decoration:none}';
	echo 'a.btn:hover{background:#333}';
	echo '</style></head><body><main class="card"><h1>' . $titleHtml . '</h1>';
	echo '<p>' . $bodyHtml . '</p>';
	echo '<a class="btn" href="' . $loginHtml . '">Sign in</a>';
	echo '</main></body></html>';
}

function qtLogout() {
	qtStartSession();
	$_SESSION = [];

	if (ini_get('session.use_cookies')) {
		$params = session_get_cookie_params();
		$del = [
			'expires' => time() - 42000,
			'path' => (string) ($params['path'] ?? '/'),
			'domain' => (string) ($params['domain'] ?? ''),
			'secure' => !empty($params['secure']),
			'httponly' => !empty($params['httponly']),
			'samesite' => isset($params['samesite']) && $params['samesite'] !== ''
				? (string) $params['samesite']
				: 'Lax',
		];
		setcookie(session_name(), '', $del);
	}

	session_destroy();
}
