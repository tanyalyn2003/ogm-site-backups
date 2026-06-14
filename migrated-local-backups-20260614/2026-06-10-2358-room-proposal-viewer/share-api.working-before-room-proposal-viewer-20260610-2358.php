<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

header('Content-Type: application/json; charset=UTF-8');

if (!qtIsLoggedIn()) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
  exit;
}

$raw = file_get_contents('php://input');
if ($raw === false) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing request body.']);
  exit;
}

// Basic size limit (snapshots should stay small).
if (strlen($raw) > 2_000_000) {
  http_response_code(413);
  echo json_encode(['ok' => false, 'error' => 'Snapshot too large.']);
  exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid JSON.']);
  exit;
}

// Minimal validation.
$version = (int)($data['version'] ?? 0);
if ($version <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing snapshot version.']);
  exit;
}

$sharedDir = __DIR__ . DIRECTORY_SEPARATOR . 'shared';
if (!is_dir($sharedDir)) {
  if (!mkdir($sharedDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not create share directory.']);
    exit;
  }
}

$token = bin2hex(random_bytes(16));
$payload = [
  'savedAt' => gmdate('c'),
  'snapshot' => $data,
];

$path = $sharedDir . DIRECTORY_SEPARATOR . $token . '.json';
$ok = file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES));
if ($ok === false) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Could not save snapshot.']);
  exit;
}

$base = qtBasePath();
$path = $base . 'viewer.php?token=' . urlencode($token);
// Prefer an absolute URL so recipients can open the link from mail/apps without
// losing the tool’s directory prefix (relative URLs break when pasted elsewhere).
$host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
if ($host !== '') {
  $scheme = qtIsHttpsRequest() ? 'https' : 'http';
  $url = $scheme . '://' . $host . $path;
} else {
  $url = $path;
}

echo json_encode(['ok' => true, 'token' => $token, 'url' => $url]);
