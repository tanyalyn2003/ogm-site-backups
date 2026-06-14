<?php

/**
 * One-off phone dedupe against customer JSON + quote_summaries on disk.
 * Run on the server from SSH / hosting terminal (no HTTP auth):
 *
 *   cd /path/to/quoter-tool && php run-customer-phone-dedupe-cli.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    exit(1);
}

$customersDir = __DIR__ . DIRECTORY_SEPARATOR . 'customers';
$summariesDir = __DIR__ . DIRECTORY_SEPARATOR . 'quote_summaries';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'customer-phone-dedupe-lib.php';

if (!is_dir($customersDir)) {
    fwrite(STDERR, "Missing directory: {$customersDir}\n");
    exit(1);
}
if (!is_dir($summariesDir)) {
    fwrite(STDERR, "Missing directory: {$summariesDir}\n");
    exit(1);
}

$result = customersApiExecutePhoneDedupe($customersDir, $summariesDir);
echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
