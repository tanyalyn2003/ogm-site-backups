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

$storageDir = __DIR__ . DIRECTORY_SEPARATOR . '.data';
$storageFile = $storageDir . DIRECTORY_SEPARATOR . 'calendar-client-id.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $clientId = '';
    if (is_file($storageFile)) {
        $raw = @file_get_contents($storageFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['clientId']) && is_string($decoded['clientId'])) {
                $clientId = trim($decoded['clientId']);
            }
        }
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true, 'clientId' => $clientId]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode((string) $raw, true);
    $clientId = is_array($payload) && isset($payload['clientId']) && is_string($payload['clientId'])
        ? trim($payload['clientId'])
        : '';

    if ($clientId === '' || strpos($clientId, '.apps.googleusercontent.com') === false) {
        http_response_code(422);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid client ID']);
        exit;
    }

    if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Failed to prepare storage directory']);
        exit;
    }

    $written = @file_put_contents(
        $storageFile,
        json_encode(['clientId' => $clientId], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );

    if ($written === false) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Failed to save client ID']);
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
