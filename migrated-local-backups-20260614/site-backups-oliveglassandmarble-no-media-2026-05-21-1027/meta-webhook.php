<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'meta-messaging.php';

header('X-Robots-Tag: noindex, nofollow', true);

$settings = ogmReadMetaSettings();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
  $mode = trim((string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? ''));
  $token = trim((string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? ''));
  $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');

  if ($mode === 'subscribe' && hash_equals((string) ($settings['verify_token'] ?? ''), $token)) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo $challenge;
    exit;
  }

  http_response_code(403);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode([
    'ok' => false,
    'message' => 'Verification failed.',
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

if ($method !== 'POST') {
  http_response_code(405);
  header('Allow: GET, POST');
  exit;
}

if (!ogmMetaIsEnabled($settings)) {
  http_response_code(503);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode([
    'ok' => false,
    'message' => 'Meta messaging is disabled.',
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

$rawBody = (string) file_get_contents('php://input');
if ($rawBody === '' || !ogmMetaValidateSignature($rawBody, $settings)) {
  http_response_code(403);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode([
    'ok' => false,
    'message' => 'Invalid webhook signature.',
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
  http_response_code(400);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode([
    'ok' => false,
    'message' => 'Invalid JSON payload.',
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

$result = ogmHandleMetaIncomingPayload($payload, $settings);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
  'ok' => true,
  'parsed' => (int) ($result['parsed_count'] ?? 0),
  'stored' => (int) ($result['stored_count'] ?? 0),
  'duplicates' => (int) ($result['duplicate_count'] ?? 0),
], JSON_UNESCAPED_SLASHES);
