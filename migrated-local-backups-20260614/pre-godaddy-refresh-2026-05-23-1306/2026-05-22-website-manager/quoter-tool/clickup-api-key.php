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
$storageFile = $storageDir . DIRECTORY_SEPARATOR . 'clickup-api-key.json';

function qtNormalizeClickUpKey(string $key): string
{
    $key = trim($key);
    if (stripos($key, 'bearer ') === 0) {
        $key = trim(substr($key, 7));
    }
    return trim($key, " \t\n\r\0\x0B\"'");
}

function qtValidateClickUpKey(string $apiKey): array
{
    $ch = curl_init('https://api.clickup.com/api/v2/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: ' . $apiKey, 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return ['valid' => false, 'error' => 'Could not reach ClickUp: ' . $err];
    }
    $decoded = json_decode((string) $resp, true);
    if ($code >= 200 && $code < 300 && is_array($decoded)) {
        $user = $decoded['user'] ?? $decoded;
        return [
            'valid' => true,
            'user'  => is_array($user) ? ($user['username'] ?? $user['email'] ?? '') : '',
        ];
    }
    $msg = is_array($decoded)
        ? (string) ($decoded['err'] ?? $decoded['error'] ?? 'Invalid token')
        : (string) $resp;
    return ['valid' => false, 'error' => 'ClickUp rejected this key (' . $code . '): ' . $msg];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $apiKey = '';
    if (is_file($storageFile)) {
        $raw = @file_get_contents($storageFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['apiKey']) && is_string($decoded['apiKey'])) {
                $apiKey = qtNormalizeClickUpKey($decoded['apiKey']);
            }
        }
    }
    $out = ['ok' => true, 'apiKey' => $apiKey];
    if (isset($_GET['validate']) && $_GET['validate'] === '1') {
        if ($apiKey === '') {
            $out['valid'] = false;
            $out['validationError'] = 'No API key saved on server';
        } else {
            $check = qtValidateClickUpKey($apiKey);
            $out['valid'] = $check['valid'];
            if (!$check['valid']) {
                $out['validationError'] = $check['error'];
            } elseif (!empty($check['user'])) {
                $out['clickupUser'] = $check['user'];
            }
        }
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode((string) $raw, true);
    $apiKey = is_array($payload) && isset($payload['apiKey']) && is_string($payload['apiKey'])
        ? qtNormalizeClickUpKey($payload['apiKey'])
        : '';

    // ClickUp personal / workspace tokens are long opaque strings (often prefixed pk_)
    if ($apiKey === '' || strlen($apiKey) < 24) {
        http_response_code(422);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid API key']);
        exit;
    }

    $check = qtValidateClickUpKey($apiKey);
    if (!$check['valid']) {
        http_response_code(422);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => $check['error']]);
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
        json_encode(['apiKey' => $apiKey], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );

    if ($written === false) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Failed to save API key']);
        exit;
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok'         => true,
        'clickupUser' => $check['user'] ?? '',
    ]);
    exit;
}

http_response_code(405);
header('Allow: GET, POST');
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
