<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtLogout();

header('Location: ' . qtBasePath() . 'index.php');
exit;
