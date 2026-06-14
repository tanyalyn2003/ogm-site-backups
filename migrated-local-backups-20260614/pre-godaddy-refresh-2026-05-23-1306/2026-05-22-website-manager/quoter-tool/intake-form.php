<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';
qtSendNoIndexHeaders();
qtStartSession();
if (!qtIsLoggedIn()) {
    $uri  = (string)($_SERVER['REQUEST_URI'] ?? '');
    $next = qtSafeNextPath($uri) ?: (qtBasePath() . 'intake-form.php');
    header('Location: index.php?next=' . rawurlencode($next), true, 303);
    exit;
}
$path = __DIR__ . DIRECTORY_SEPARATOR . 'OGM_IntakeForm.html';
if (!is_file($path)) { http_response_code(404); echo 'Not found.'; exit; }
header('Content-Type: text/html; charset=UTF-8');
readfile($path);
