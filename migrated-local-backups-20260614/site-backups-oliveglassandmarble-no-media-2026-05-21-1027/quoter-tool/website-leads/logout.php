<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

adminSendNoIndexHeaders();
adminLogout();

header('Location: ../logout.php', true, 303);
exit;
