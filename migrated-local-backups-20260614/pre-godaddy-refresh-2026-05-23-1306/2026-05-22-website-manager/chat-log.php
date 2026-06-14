<?php
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode([
    'success' => false,
    'message' => 'Method not allowed.'
  ]);
  exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput ?: '', true);

if (!is_array($data) || !$data) {
  $data = $_POST;
}

$question = trim($data['question'] ?? '');
if ($question === '') {
  http_response_code(400);
  echo json_encode([
    'success' => false,
    'message' => 'Missing question.'
  ]);
  exit;
}

$logDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'ogm-chat';
$logFile = $logDirectory . DIRECTORY_SEPARATOR . 'chatbot-unanswered.log';

if (!is_dir($logDirectory) && !@mkdir($logDirectory, 0755, true) && !is_dir($logDirectory)) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'message' => 'Could not create log directory.'
  ]);
  exit;
}

$entry = [
  'timestamp' => gmdate('c'),
  'question' => $question,
  'reason' => trim($data['reason'] ?? 'low_confidence'),
  'confidence' => trim($data['confidence'] ?? 'low'),
  'intent' => trim($data['intent'] ?? ''),
  'service_key' => trim($data['service_key'] ?? ''),
  'customer_type' => trim($data['customer_type'] ?? ''),
  'space_type' => trim($data['space_type'] ?? ''),
  'material_priority' => trim($data['material_priority'] ?? ''),
  'material_keys' => array_values(array_filter((array)($data['material_keys'] ?? []))),
  'previous_question' => trim($data['previous_question'] ?? ''),
  'previous_intent' => trim($data['previous_intent'] ?? ''),
  'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
  'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
];

$written = @file_put_contents(
  $logFile,
  json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL,
  FILE_APPEND | LOCK_EX
);

if ($written === false) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'message' => 'Could not write log entry.'
  ]);
  exit;
}

echo json_encode([
  'success' => true
]);
