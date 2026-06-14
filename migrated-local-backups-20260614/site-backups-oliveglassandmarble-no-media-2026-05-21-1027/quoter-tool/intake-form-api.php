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

// ── Load API key ─────────────────────────────────────────────────────
$keyFile = __DIR__ . DIRECTORY_SEPARATOR . '.data'
         . DIRECTORY_SEPARATOR . 'clickup-api-key.json';
$apiKey  = '';
if (is_file($keyFile)) {
    $raw = @file_get_contents($keyFile);
    if ($raw) {
        $d = json_decode($raw, true);
        if (is_array($d) && !empty($d['apiKey'])) $apiKey = trim((string)$d['apiKey']);
    }
}
if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'API key not configured']);
    exit;
}

$action = trim((string)($_GET['action'] ?? ''));

/** @return array{ok:bool,status:int,data:?array,raw:string,error:string} */
function intakeCuRequest(string $method, string $url, string $apiKey, ?string $jsonBody = null, ?string $multipartBody = null, ?string $contentType = null): array {
    if (!function_exists('curl_init')) {
        $headers = "Authorization: {$apiKey}\r\n";
        if ($jsonBody !== null) {
            $headers .= "Content-Type: application/json\r\n";
        } elseif ($contentType) {
            $headers .= "Content-Type: {$contentType}\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => $headers,
            'content'       => $jsonBody ?? $multipartBody ?? '',
            'timeout'       => 30,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        $data = $raw ? json_decode($raw, true) : null;
        $err = '';
        if (is_array($data) && isset($data['err'])) {
            $err = (string) $data['err'];
        } elseif ($status >= 400) {
            $err = 'HTTP ' . $status;
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => is_array($data) ? $data : null, 'raw' => (string) $raw, 'error' => $err];
    }

    $ch = curl_init($url);
    $headers = ['Authorization: ' . $apiKey];
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    } elseif ($contentType) {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($jsonBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    } elseif ($multipartBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipartBody);
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = $raw !== '' ? json_decode($raw, true) : null;
    $err = '';
    if (is_array($data) && isset($data['err'])) {
        $err = (string) $data['err'];
    } elseif ($status >= 400) {
        $err = 'HTTP ' . $status;
    }
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => is_array($data) ? $data : null, 'raw' => $raw, 'error' => $err];
}

/** Material fields on the intake list are ClickUp "labels" — value must be an array of label IDs. */
function intakeNormalizeTaskBody(array $body): array {
    unset($body['status']);

    $labelMatFieldIds = [
        '973aa939-e346-4f89-ada9-7f4aad1c36d3',
        '4442a6df-515c-4df1-97a2-55124b430057',
        '0aac6ce8-7da4-4d41-98c1-87bcbb941055',
        '0a05df60-0be8-4643-9bb9-9634f80476fd',
        '91c82a7a-a912-46f0-90e6-420fb4e903ef',
        '76f7d45e-a6b5-4812-b6ef-a5a72582edd0',
    ];
    $dateFieldIds = ['def6ff89-3a11-4e2f-90b3-210e61c4f4a1'];

    if (!isset($body['custom_fields']) || !is_array($body['custom_fields'])) {
        return $body;
    }

    $out = [];
    foreach ($body['custom_fields'] as $cf) {
        if (!is_array($cf) || empty($cf['id'])) {
            continue;
        }
        $id = (string) $cf['id'];
        $val = $cf['value'] ?? null;
        if ($val === null || $val === '' || $val === []) {
            continue;
        }

        if (in_array($id, $dateFieldIds, true) && is_string($val) && preg_match('/^\d{4}-\d{2}-\d{2}/', $val)) {
            $ts = strtotime($val . ' 12:00:00');
            if ($ts !== false) {
                $cf['value'] = (int) $ts * 1000;
            }
        }

        if (in_array($id, $labelMatFieldIds, true)) {
            if (is_string($val)) {
                $cf['value'] = [$val];
            } elseif (!is_array($val)) {
                continue;
            }
        }

        $out[] = $cf;
    }
    $body['custom_fields'] = $out;
    return $body;
}

// ── ACTION: create-task ──────────────────────────────────────────────
if ($action === 'create-task' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode((string)$raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
        exit;
    }

    $body = intakeNormalizeTaskBody($body);
    $LIST_ID = '901710745952';
    $url     = 'https://api.clickup.com/api/v2/list/' . $LIST_ID . '/task';
    $json    = json_encode($body);

    $result = intakeCuRequest('POST', $url, $apiKey, $json);
    $data   = $result['data'];

    /* If custom fields rejected the payload, still create the task with description
       so photos can attach — same pattern as Job Tracking description fallback. */
    if (!$result['ok'] || !$data || !isset($data['id'])) {
        $minimal = [
            'name'        => (string) ($body['name'] ?? 'Field intake'),
            'description' => (string) ($body['description'] ?? 'OGM Field Intake Form'),
        ];
        $retry   = intakeCuRequest('POST', $url, $apiKey, json_encode($minimal));
        $data    = $retry['data'];
        if ($retry['ok'] && $data && isset($data['id'])) {
            echo json_encode([
                'ok'      => true,
                'taskId'  => $data['id'],
                'taskUrl' => $data['url'] ?? '',
                'warning' => 'Task created with basic info only — some custom fields could not be set.',
            ]);
            exit;
        }
        http_response_code(500);
        echo json_encode([
            'ok'             => false,
            'error'          => 'ClickUp task creation failed',
            'clickupMessage' => $result['error'] ?: ($data['err'] ?? $retry['error'] ?? ''),
            'detail'         => substr($result['raw'] ?: $retry['raw'], 0, 500),
        ]);
        exit;
    }
    echo json_encode(['ok' => true, 'taskId' => $data['id'],
                      'taskUrl' => $data['url'] ?? '']);
    exit;
}

// ── ACTION: upload-photo ─────────────────────────────────────────────
if ($action === 'upload-photo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $taskId = trim((string)($_POST['task_id'] ?? ''));
    if ($taskId === '' || empty($_FILES['photo'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing task_id or photo']);
        exit;
    }

    $file     = $_FILES['photo'];
    $tmpPath  = $file['tmp_name'];
    $filename = basename($file['name']);
    $mime     = $file['type'] ?: 'image/jpeg';

    if (!is_uploaded_file($tmpPath) || $file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Upload error: ' . $file['error']]);
        exit;
    }

    // Max 20MB
    if ($file['size'] > 20 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'File too large (max 20MB)']);
        exit;
    }

    $boundary = '----OGMBoundary' . uniqid();
    $fileData = file_get_contents($tmpPath);
    $body     = "--{$boundary}\r\n"
              . "Content-Disposition: form-data; name=\"attachment\"; filename=\"{$filename}\"\r\n"
              . "Content-Type: {$mime}\r\n\r\n"
              . $fileData . "\r\n"
              . "--{$boundary}--\r\n";

    $url = 'https://api.clickup.com/api/v2/task/' . $taskId . '/attachment';
    $result = intakeCuRequest(
        'POST',
        $url,
        $apiKey,
        null,
        $body,
        "multipart/form-data; boundary={$boundary}"
    );
    $data = $result['data'];

    if (!$result['ok'] || !$data || !isset($data['id'])) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Photo upload failed',
                          'clickupMessage' => $result['error'],
                          'detail' => substr($result['raw'], 0, 500)]);
        exit;
    }
    echo json_encode(['ok' => true, 'attachmentId' => $data['id']]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
