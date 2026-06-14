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

$serviceAccountPath = __DIR__ . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . 'calendar-service-account.json';
$tokenCachePath = __DIR__ . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . 'calendar-token-cache.json';
$calendarBase = 'https://www.googleapis.com/calendar/v3';
$scope = 'https://www.googleapis.com/auth/calendar';
$defaultCalendarId = 'ogm.tanya@gmail.com';

function calApiJsonResponse($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function calApiBase64Url($value) {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function calApiReadServiceAccount($path) {
    if (!is_file($path)) {
        return [null, 'Missing service account key at .data/calendar-service-account.json'];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [null, 'Could not read service account key'];
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [null, 'Invalid service account JSON'];
    }
    foreach (['client_email', 'private_key', 'token_uri'] as $key) {
        if (empty($json[$key]) || !is_string($json[$key])) {
            return [null, 'Service account JSON is missing ' . $key];
        }
    }

    return [$json, null];
}

function calApiGetAccessToken($serviceAccount, $scope, $cachePath) {
    $now = time();
    if (is_file($cachePath)) {
        $cached = json_decode((string) @file_get_contents($cachePath), true);
        if (is_array($cached) && !empty($cached['access_token']) && (int) ($cached['expires_at'] ?? 0) > ($now + 120)) {
            return [(string) $cached['access_token'], null];
        }
    }

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claim = [
        'iss' => $serviceAccount['client_email'],
        'scope' => $scope,
        'aud' => $serviceAccount['token_uri'],
        'exp' => $now + 3600,
        'iat' => $now,
    ];
    $unsigned = calApiBase64Url(json_encode($header, JSON_UNESCAPED_SLASHES)) . '.' . calApiBase64Url(json_encode($claim, JSON_UNESCAPED_SLASHES));
    $signature = '';
    $ok = openssl_sign($unsigned, $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);
    if (!$ok) {
        return [null, 'Could not sign Google service account JWT'];
    }
    $assertion = $unsigned . '.' . calApiBase64Url($signature);

    $ch = curl_init((string) $serviceAccount['token_uri']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]),
        CURLOPT_TIMEOUT => 25,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return [null, 'Google token request failed: ' . $err];
    }
    $json = json_decode((string) $body, true);
    if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['access_token'])) {
        $message = is_array($json) ? (string) ($json['error_description'] ?? $json['error'] ?? 'Token request failed') : 'Token request failed';
        return [null, $message];
    }

    $token = (string) $json['access_token'];
    $expiresIn = max(60, (int) ($json['expires_in'] ?? 3600));
    $cacheDir = dirname($cachePath);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    @file_put_contents($cachePath, json_encode([
        'access_token' => $token,
        'expires_at' => $now + $expiresIn,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);

    return [$token, null];
}

function calApiRequest($method, $path, $query = [], $payload = null) {
    global $serviceAccountPath, $tokenCachePath, $calendarBase, $scope;

    [$serviceAccount, $serviceError] = calApiReadServiceAccount($serviceAccountPath);
    if ($serviceError !== null) {
        calApiJsonResponse(['ok' => false, 'configured' => false, 'error' => $serviceError], 503);
    }
    [$token, $tokenError] = calApiGetAccessToken($serviceAccount, $scope, $tokenCachePath);
    if ($tokenError !== null) {
        calApiJsonResponse(['ok' => false, 'configured' => true, 'error' => $tokenError], 502);
    }

    $url = $calendarBase . $path;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];
    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json; charset=UTF-8';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 35,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) {
        calApiJsonResponse(['ok' => false, 'error' => 'Google Calendar request failed: ' . $err], 502);
    }
    $json = $raw === '' ? [] : json_decode((string) $raw, true);
    if ($json === null && $raw !== '') {
        $json = ['raw' => (string) $raw];
    }
    if ($status < 200 || $status >= 300) {
        $message = is_array($json) ? ($json['error']['message'] ?? $json['error'] ?? 'Google Calendar API error') : 'Google Calendar API error';
        calApiJsonResponse(['ok' => false, 'error' => $message, 'googleStatus' => $status, 'google' => $json], $status);
    }

    return is_array($json) ? $json : [];
}

function calApiRequired($name) {
    $value = trim((string) ($_GET[$name] ?? ''));
    if ($value === '') {
        calApiJsonResponse(['ok' => false, 'error' => 'Missing ' . $name], 422);
    }

    return $value;
}

$action = strtolower(trim((string) ($_GET['action'] ?? 'status')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($action === 'status') {
    [$serviceAccount, $error] = calApiReadServiceAccount($serviceAccountPath);
    if ($error !== null) {
        calApiJsonResponse(['ok' => true, 'configured' => false, 'error' => $error]);
    }
    calApiJsonResponse([
        'ok' => true,
        'configured' => true,
        'clientEmail' => $serviceAccount['client_email'],
    ]);
}

if ($action === 'calendars') {
    if ($method !== 'GET') {
        calApiJsonResponse(['ok' => false, 'error' => 'Method not allowed'], 405);
    }
    $data = calApiRequest('GET', '/users/me/calendarList', ['minAccessRole' => 'writer']);
    $items = $data['items'] ?? [];
    if (!$items && $defaultCalendarId !== '') {
        $calendar = calApiRequest('GET', '/calendars/' . rawurlencode($defaultCalendarId));
        $items = [[
            'id' => $calendar['id'] ?? $defaultCalendarId,
            'summary' => $calendar['summary'] ?? 'OGM Calendar',
            'backgroundColor' => $calendar['backgroundColor'] ?? '#9e7c3a',
            'foregroundColor' => $calendar['foregroundColor'] ?? '#ffffff',
            'accessRole' => 'writer',
            'primary' => true,
        ]];
    }
    calApiJsonResponse(['ok' => true, 'items' => $items]);
}

if ($action === 'events') {
    $calendarId = calApiRequired('calendarId');
    if ($method === 'GET') {
        $query = [
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => min(2500, max(1, (int) ($_GET['maxResults'] ?? 500))),
        ];
        foreach (['timeMin', 'timeMax'] as $key) {
            if (isset($_GET[$key]) && trim((string) $_GET[$key]) !== '') {
                $query[$key] = trim((string) $_GET[$key]);
            }
        }
        $data = calApiRequest('GET', '/calendars/' . rawurlencode($calendarId) . '/events', $query);
        calApiJsonResponse(['ok' => true, 'items' => $data['items'] ?? []]);
    }
    if ($method === 'POST') {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            calApiJsonResponse(['ok' => false, 'error' => 'Invalid JSON body'], 422);
        }
        $data = calApiRequest('POST', '/calendars/' . rawurlencode($calendarId) . '/events', [], $payload);
        calApiJsonResponse(['ok' => true, 'event' => $data]);
    }
    calApiJsonResponse(['ok' => false, 'error' => 'Method not allowed'], 405);
}

if ($action === 'event') {
    $calendarId = calApiRequired('calendarId');
    $eventId = calApiRequired('eventId');
    if ($method === 'PUT' || $method === 'PATCH') {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            calApiJsonResponse(['ok' => false, 'error' => 'Invalid JSON body'], 422);
        }
        $data = calApiRequest($method, '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId), [], $payload);
        calApiJsonResponse(['ok' => true, 'event' => $data]);
    }
    if ($method === 'DELETE') {
        calApiRequest('DELETE', '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId));
        calApiJsonResponse(['ok' => true]);
    }
    calApiJsonResponse(['ok' => false, 'error' => 'Method not allowed'], 405);
}

calApiJsonResponse(['ok' => false, 'error' => 'Unknown action'], 400);
