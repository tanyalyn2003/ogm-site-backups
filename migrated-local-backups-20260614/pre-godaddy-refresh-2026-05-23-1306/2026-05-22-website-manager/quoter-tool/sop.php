<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

if (!qtIsLoggedIn()) {
    $bp = qtBasePath();
    $trimmed = rtrim($bp, '/');
    $next = ($trimmed === '' ? '' : $trimmed) . '/sop/';
    header('Location: index.php?next=' . rawurlencode($next), true, 303);
    exit;
}

header('Location: sop/', true, 303);
exit;
