<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!qtIsLoggedIn()) {
    http_response_code(401);
    if ($action === 'download') {
        echo 'Sign in to the Quoter Tool to download this document.';
    } else {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    }
    exit;
}

if ($action !== 'download') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

$dataRoot = __DIR__ . DIRECTORY_SEPARATOR . '.data';
$docRoot = $dataRoot . DIRECTORY_SEPARATOR . 'quote-documents';
$recordsDir = $docRoot . DIRECTORY_SEPARATOR . 'records';
$filesDir = $docRoot . DIRECTORY_SEPARATOR . 'files';

function ogmQdJson($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function ogmQdEnsureDir($dir) {
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0755, true);
}

function ogmQdProtectDataRoot($dir) {
    if (!ogmQdEnsureDir($dir)) return false;
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    return true;
}

function ogmQdSafeId($raw, $max = 120) {
    $s = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $raw);
    if ($s === null) return '';
    return substr($s, 0, $max);
}

function ogmQdSafeName($raw) {
    $name = trim((string) $raw);
    $name = preg_replace('/[^\w.\- ()]+/u', '_', $name);
    if ($name === null || trim($name) === '') $name = 'imported-file';
    return substr($name, 0, 180);
}

function ogmQdReadJson($path, $fallback = null) {
    if (!is_file($path)) return $fallback;
    $raw = @file_get_contents($path);
    $data = $raw ? json_decode((string) $raw, true) : null;
    return is_array($data) ? $data : $fallback;
}

function ogmQdWriteJson($path, array $data) {
    return @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
}

function ogmQdReadBody() {
    $raw = file_get_contents('php://input');
    $body = json_decode((string) $raw, true);
    return is_array($body) ? $body : null;
}

function ogmQdBaseUrl() {
    $base = rtrim(qtBasePath(), '/');
    $path = $base . '/quote-documents-api.php';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return $path;
    $scheme = qtIsHttpsRequest() ? 'https' : 'http';
    return $scheme . '://' . $host . $path;
}

function ogmQdDownloadUrl($id) {
    return ogmQdBaseUrl() . '?action=download&id=' . rawurlencode($id);
}

function ogmQdRecordPath($recordsDir, $id) {
    return $recordsDir . DIRECTORY_SEPARATOR . ogmQdSafeId($id) . '.json';
}

function ogmQdPublicRecord(array $doc) {
    $id = (string) ($doc['id'] ?? '');
    $doc['url'] = $id !== '' ? ogmQdDownloadUrl($id) : '';
    unset($doc['storagePath']);
    return $doc;
}

function ogmQdListRecords($recordsDir, $quoteNumber = '', $taskId = '') {
    $quoteNumber = trim((string) $quoteNumber);
    $taskId = trim((string) $taskId);
    $out = [];
    foreach ((glob($recordsDir . DIRECTORY_SEPARATOR . '*.json') ?: []) as $path) {
        $doc = ogmQdReadJson($path, null);
        if (!is_array($doc)) continue;
        if ($quoteNumber !== '' && strcasecmp((string) ($doc['quoteNumber'] ?? ''), $quoteNumber) !== 0) continue;
        if ($taskId !== '' && (string) ($doc['taskId'] ?? '') !== $taskId) continue;
        $out[] = ogmQdPublicRecord($doc);
    }
    usort($out, static function ($a, $b) {
        return strcmp((string) ($b['uploadedAt'] ?? ''), (string) ($a['uploadedAt'] ?? ''));
    });
    return $out;
}

if (!ogmQdProtectDataRoot($dataRoot) || !ogmQdEnsureDir($recordsDir) || !ogmQdEnsureDir($filesDir)) {
    ogmQdJson(['ok' => false, 'error' => 'Could not create quote document storage.'], 500);
}

if ($action === 'upload') {
    if ($method !== 'POST') ogmQdJson(['ok' => false, 'error' => 'Method not allowed.'], 405);
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        ogmQdJson(['ok' => false, 'error' => 'Missing uploaded file.'], 400);
    }
    $file = $_FILES['file'];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        ogmQdJson(['ok' => false, 'error' => 'Upload failed.'], 400);
    }
    $quoteNumber = ogmQdSafeId($_POST['quoteNumber'] ?? '', 120);
    if ($quoteNumber === '') ogmQdJson(['ok' => false, 'error' => 'quoteNumber is required.'], 400);
    $originalName = ogmQdSafeName($file['name'] ?? 'imported-file');
    $id = 'qd-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $docFileDir = $filesDir . DIRECTORY_SEPARATOR . $id;
    if (!ogmQdEnsureDir($docFileDir)) ogmQdJson(['ok' => false, 'error' => 'Could not create file directory.'], 500);
    $storagePath = $docFileDir . DIRECTORY_SEPARATOR . $originalName;
    if (!@move_uploaded_file((string) ($file['tmp_name'] ?? ''), $storagePath)) {
        ogmQdJson(['ok' => false, 'error' => 'Could not store uploaded file.'], 500);
    }
    $now = gmdate('c');
    $doc = [
        'id' => $id,
        'quoteNumber' => $quoteNumber,
        'taskId' => '',
        'fileName' => $originalName,
        'kind' => substr(trim((string) ($_POST['kind'] ?? 'source')), 0, 60),
        'source' => substr(trim((string) ($_POST['source'] ?? 'planner_import')), 0, 80),
        'plannerImportId' => ogmQdSafeId($_POST['plannerImportId'] ?? '', 120),
        'pageCount' => max(0, (int) ($_POST['pageCount'] ?? 0)),
        'size' => (int) ($file['size'] ?? 0),
        'mime' => substr(trim((string) ($file['type'] ?? 'application/octet-stream')), 0, 120),
        'uploadedAt' => $now,
        'uploadedBy' => qtCurrentUser(),
        'storagePath' => $storagePath,
    ];
    if (!ogmQdWriteJson(ogmQdRecordPath($recordsDir, $id), $doc)) {
        ogmQdJson(['ok' => false, 'error' => 'Could not save document record.'], 500);
    }
    ogmQdJson(['ok' => true, 'document' => ogmQdPublicRecord($doc)]);
}

if ($action === 'list') {
    $quoteNumber = ogmQdSafeId($_GET['quoteNumber'] ?? '', 120);
    $taskId = ogmQdSafeId($_GET['taskId'] ?? '', 120);
    if ($quoteNumber === '' && $taskId === '') {
        ogmQdJson(['ok' => false, 'error' => 'quoteNumber or taskId is required.'], 400);
    }
    ogmQdJson(['ok' => true, 'documents' => ogmQdListRecords($recordsDir, $quoteNumber, $taskId)]);
}

if ($action === 'link-task') {
    if ($method !== 'POST') ogmQdJson(['ok' => false, 'error' => 'Method not allowed.'], 405);
    $body = ogmQdReadBody();
    if (!is_array($body)) ogmQdJson(['ok' => false, 'error' => 'Invalid JSON.'], 400);
    $quoteNumber = ogmQdSafeId($body['quoteNumber'] ?? '', 120);
    $taskId = ogmQdSafeId($body['taskId'] ?? '', 120);
    if ($quoteNumber === '' || $taskId === '') {
        ogmQdJson(['ok' => false, 'error' => 'quoteNumber and taskId are required.'], 400);
    }
    $updated = 0;
    $docs = [];
    foreach ((glob($recordsDir . DIRECTORY_SEPARATOR . '*.json') ?: []) as $path) {
        $doc = ogmQdReadJson($path, null);
        if (!is_array($doc)) continue;
        if (strcasecmp((string) ($doc['quoteNumber'] ?? ''), $quoteNumber) !== 0) continue;
        if ((string) ($doc['taskId'] ?? '') !== $taskId) {
            $doc['taskId'] = $taskId;
            $doc['linkedAt'] = gmdate('c');
            $doc['linkedBy'] = qtCurrentUser();
            ogmQdWriteJson($path, $doc);
            $updated++;
        }
        $docs[] = ogmQdPublicRecord($doc);
    }
    ogmQdJson(['ok' => true, 'updated' => $updated, 'documents' => $docs]);
}

if ($action === 'download') {
    $id = ogmQdSafeId($_GET['id'] ?? '', 120);
    $doc = $id !== '' ? ogmQdReadJson(ogmQdRecordPath($recordsDir, $id), null) : null;
    if (!is_array($doc) || empty($doc['storagePath']) || !is_file((string) $doc['storagePath'])) {
        http_response_code(404);
        echo 'Document not found.';
        exit;
    }
    $name = ogmQdSafeName($doc['fileName'] ?? 'document');
    $mime = trim((string) ($doc['mime'] ?? ''));
    if ($mime === '') $mime = 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize((string) $doc['storagePath']));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile((string) $doc['storagePath']);
    exit;
}

ogmQdJson(['ok' => false, 'error' => 'Unknown action.'], 404);
