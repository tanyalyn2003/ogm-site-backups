<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

header('Content-Type: application/json; charset=UTF-8');

if (!qtIsLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

function cupNormalizeKey(string $key): string
{
    $key = trim($key);
    if (stripos($key, 'bearer ') === 0) {
        $key = trim(substr($key, 7));
    }
    return trim($key, " \t\n\r\0\x0B\"'");
}

function cupLoadApiKey(): string
{
    $storageFile = __DIR__ . DIRECTORY_SEPARATOR . '.data'
        . DIRECTORY_SEPARATOR . 'clickup-api-key.json';
    if (!is_file($storageFile)) {
        return '';
    }
    $raw = @file_get_contents($storageFile);
    if ($raw === false) {
        return '';
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['apiKey']) || !is_string($decoded['apiKey'])) {
        return '';
    }
    return cupNormalizeKey($decoded['apiKey']);
}

function cupAllowedEndpoint(string $endpoint): bool
{
    if ($endpoint === '' || $endpoint[0] !== '/') {
        return false;
    }
    if (strpos($endpoint, '..') !== false) {
        return false;
    }
    return (bool) preg_match('#^/(list|task|space|view|team|workspaces)/#', $endpoint);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$endpoint = isset($payload['endpoint']) && is_string($payload['endpoint'])
    ? $payload['endpoint']
    : '';
$method = isset($payload['method']) && is_string($payload['method'])
    ? strtoupper(trim($payload['method']))
    : 'GET';
$apiVersion = isset($payload['apiVersion']) && $payload['apiVersion'] === 'v3' ? 'v3' : 'v2';
$body = $payload['body'] ?? null;
$bypassCache = !empty($payload['cacheBust']);

if (!cupAllowedEndpoint($endpoint)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Endpoint not allowed']);
    exit;
}

if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$apiKey = cupLoadApiKey();
if ($apiKey === '' || strlen($apiKey) < 24) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'ClickUp API key not configured on server']);
    exit;
}

$base = $apiVersion === 'v3'
    ? 'https://api.clickup.com/api/v3'
    : 'https://api.clickup.com/api/v2';
$url = $base . $endpoint;

$cacheDir = __DIR__ . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . 'clickup-cache';
$cacheTtlSeconds = 45;
$canCache = $method === 'GET' && !$bypassCache;
$cachePath = '';
if ($canCache) {
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cachePath = $cacheDir . DIRECTORY_SEPARATOR . sha1($apiVersion . "\n" . $endpoint) . '.json';
    if (is_file($cachePath) && (time() - filemtime($cachePath)) <= $cacheTtlSeconds) {
        $cached = @file_get_contents($cachePath);
        if ($cached !== false && $cached !== '') {
            echo $cached;
            exit;
        }
    }
} elseif ($method !== 'GET' && is_dir($cacheDir)) {
    foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        @unlink($file);
    }
}

$headers = ['Authorization: ' . $apiKey, 'Accept: application/json'];
$curlBody = null;
if ($body !== null && in_array($method, ['POST', 'PUT'], true)) {
    $headers[] = 'Content-Type: application/json';
    $curlBody = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
if ($curlBody !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $curlBody);
}

$resp = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'ClickUp request failed: ' . $curlErr]);
    exit;
}

$decoded = json_decode((string) $resp, true);
if ($code >= 200 && $code < 300) {
    $out = json_encode(['ok' => true, 'status' => $code, 'data' => $decoded]);
    if ($canCache && $cachePath !== '' && $out !== false) {
        @file_put_contents($cachePath, $out);
    }
    echo $out;
    exit;
}

$errMsg = is_array($decoded)
    ? (string) ($decoded['err'] ?? $decoded['error'] ?? json_encode($decoded))
    : (string) $resp;
http_response_code($code > 0 ? $code : 502);
echo json_encode([
    'ok'     => false,
    'status' => $code,
    'error'  => 'ClickUp API ' . $code . ': ' . $errMsg,
    'data'   => is_array($decoded) ? $decoded : null,
]);
