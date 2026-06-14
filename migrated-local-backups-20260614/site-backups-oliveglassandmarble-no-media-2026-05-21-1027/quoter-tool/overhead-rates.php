<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';
qtSendNoIndexHeaders();
qtStartSession();

if (!qtIsLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$storageDir  = __DIR__ . DIRECTORY_SEPARATOR . '.data';
$storageFile = $storageDir . DIRECTORY_SEPARATOR . 'overhead-rates.json';

$DEFAULTS = [
    'stone_install_labor'    => 144.51,
    'stone_install_overhead' => 60.63,
    'stone_prod_labor'       => 102.39,
    'stone_prod_overhead'    => 42.95,
    'stone_daily_target'     => 3282.20,
    'glass_install_labor'    => 49.05,
    'glass_install_overhead' => 21.00,
    'glass_prod_labor'       => 49.05,
    'glass_prod_overhead'    => 21.00,
    'glass_daily_target'     => 1120.80,
    'effective_date'         => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rates = $DEFAULTS;
    if (is_file($storageFile)) {
        $raw = @file_get_contents($storageFile);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $rates = array_merge($DEFAULTS, $decoded);
            }
        }
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true, 'rates' => $rates]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw     = file_get_contents('php://input');
    $payload = json_decode((string) $raw, true);
    if (!is_array($payload) || !isset($payload['rates']) || !is_array($payload['rates'])) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
        exit;
    }

    $rates = [];
    foreach ($DEFAULTS as $k => $def) {
        $v = $payload['rates'][$k] ?? $def;
        $rates[$k] = ($k === 'effective_date') ? trim((string) $v) : (float) $v;
    }

    if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Failed to prepare storage directory']);
        exit;
    }

    $ok = @file_put_contents(
        $storageFile,
        json_encode($rates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    if ($ok === false) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Failed to save rates']);
        exit;
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
header('Allow: GET, POST');
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
