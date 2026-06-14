<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

adminSendNoIndexHeaders();

$next = qtSafeNextPath((string) ($_GET['next'] ?? ''));
if ($next === '') {
    $next = adminUrl('index.php');
}

header('Location: ' . adminQuoterLoginUrl($next), true, 303);
exit;
