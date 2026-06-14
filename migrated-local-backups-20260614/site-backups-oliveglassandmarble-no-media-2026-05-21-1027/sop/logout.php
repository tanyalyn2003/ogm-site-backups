<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

sopSendNoIndexHeaders();
sopLogout();

header('Location: login.php');
exit;
