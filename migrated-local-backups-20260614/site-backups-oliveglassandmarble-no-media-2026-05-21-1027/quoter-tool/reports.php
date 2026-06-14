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
        $next = ($trimmed === '' ? '' : $trimmed) . '/sales-reports.php';
    }
    header('Location: index.php?next=' . rawurlencode($next), true, 303);
    exit;
}

$path = __DIR__ . DIRECTORY_SEPARATOR . 'OGM_SalesReports.html';
if (!is_file($path)) {
    $bp = qtBasePath();
    $trimmed = rtrim($bp, '/');
    $next = ($trimmed === '' ? '' : $trimmed) . '/sales-reports.php';
    qtRenderMissingPage(
        'Sales Reports unavailable',
        'The Sales Reports page is temporarily unavailable. Sign in again to continue.',
        $next
    );
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
$build = (string) max(
    (int) filemtime($path),
    is_file(__DIR__ . DIRECTORY_SEPARATOR . 'ogm-server-sync.js')
        ? (int) filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'ogm-server-sync.js')
        : 0
);
$html = file_get_contents($path);
if ($html !== false && $html !== '') {
    $marker = '<body data-ogm-reports-build="' . htmlspecialchars($build, ENT_QUOTES, 'UTF-8') . '">';
    if (stripos($html, '<body') !== false && stripos($html, 'data-ogm-reports-build') === false) {
        $html = preg_replace('/<body([^>]*)>/i', $marker, $html, 1);
    }
    $html = preg_replace(
        '/ogm-server-sync\.js\?v=\d+/',
        'ogm-server-sync.js?v=' . $build,
        $html,
        1
    );
    echo $html;
} else {
    readfile($path);
}
