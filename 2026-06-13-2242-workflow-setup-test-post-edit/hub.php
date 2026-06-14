<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

if (!qtIsLoggedIn()) {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $next = qtSafeNextPath($uri);
    if ($next === '') {
        $bp = qtBasePath();
        $trimmed = rtrim($bp, '/');
        $next = ($trimmed === '' ? '' : $trimmed) . '/hub.php';
    }
    header('Location: index.php?next=' . rawurlencode($next), true, 303);
    exit;
}

$path = __DIR__ . DIRECTORY_SEPARATOR . 'OGM_Hub.html';
if (!is_file($path)) {
    http_response_code(404);
    echo 'Hub file not found.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
$html = file_get_contents($path);
if ($html === false) {
    readfile($path);
    exit;
}

$currentUserPayload = [
    'username' => (string) ($_SESSION['qt_username'] ?? ''),
    'displayName' => qtCurrentUser(),
    'role' => (string) ($_SESSION['qt_role'] ?? ''),
];
$currentUserScript = '<script>window.OGM_CURRENT_USER='
    . json_encode($currentUserPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    . ';</script>';

if (stripos($html, '</head>') !== false) {
    $html = preg_replace('/<\/head>/i', $currentUserScript . "\n</head>", $html, 1);
} else {
    $html = $currentUserScript . "\n" . $html;
}
echo $html;
