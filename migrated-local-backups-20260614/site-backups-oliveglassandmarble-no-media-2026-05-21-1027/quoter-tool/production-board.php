<?php
// Production Board — NO login required (read-only TV display)
$path = __DIR__ . DIRECTORY_SEPARATOR . 'OGM_ProductionBoard.html';
if (!is_file($path)) { http_response_code(404); echo 'Not found.'; exit; }
header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
readfile($path);
