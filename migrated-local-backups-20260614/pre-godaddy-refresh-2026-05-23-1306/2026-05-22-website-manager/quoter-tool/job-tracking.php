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
        $next = ($trimmed === '' ? '' : $trimmed) . '/job-tracking.php';
    }
    header('Location: index.php?next=' . rawurlencode($next), true, 303);
    exit;
}

$path = __DIR__ . DIRECTORY_SEPARATOR . 'OGM_JobTracking.html';
if (!is_file($path)) {
    $bp = qtBasePath();
    $trimmed = rtrim($bp, '/');
    $next = ($trimmed === '' ? '' : $trimmed) . '/job-tracking.php';
    qtRenderMissingPage(
        'Job Tracking unavailable',
        'The Job Tracking page is temporarily unavailable. Sign in again to continue.',
        $next
    );
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
readfile($path);
