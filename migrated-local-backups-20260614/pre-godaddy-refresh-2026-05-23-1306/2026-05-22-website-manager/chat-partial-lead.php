<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lead-storage.php';

$toEmail = 'info@oliveglassandmarble.com';
$fromEmail = 'website@oliveglassandmarble.com';

function jsonResponse($success, $message = '', $statusCode = 200, $extra = []) {
  http_response_code($statusCode);
  echo json_encode(array_merge([
    'success' => $success,
    'message' => $message,
  ], $extra));
  exit;
}

function ensureDirectory($directory) {
  return is_dir($directory) || (@mkdir($directory, 0755, true) && is_dir($directory));
}

function normalizePhoneForCompare($phone) {
  return preg_replace('/\D+/', '', (string) $phone);
}

function buildPartialLeadSignature($entry) {
  return strtolower(implode('|', [
    trim($entry['name'] ?? ''),
    trim($entry['email'] ?? ''),
    normalizePhoneForCompare($entry['phone'] ?? ''),
    trim($entry['project_type'] ?? ''),
    trim($entry['city'] ?? ''),
    trim($entry['space_type'] ?? ''),
    trim($entry['material_interest'] ?? ''),
    trim($entry['build_type'] ?? ''),
    trim($entry['timeline'] ?? ''),
    trim($entry['chat_summary'] ?? ''),
  ]));
}

function buildPartialLeadScore($entry) {
  $fields = [
    'name',
    'email',
    'phone',
    'project_type',
    'city',
    'space_type',
    'material_interest',
    'build_type',
    'timeline',
    'chat_summary',
    'customer_type',
    'measurements',
    'tile_complete',
    'project_scope',
    'plans_ready',
    'pricing_or_scheduling',
    'home_or_commercial',
    'question',
  ];

  $score = 0;
  foreach ($fields as $field) {
    if (trim((string) ($entry[$field] ?? '')) !== '') {
      $score += 1;
    }
  }

  return $score;
}

function buildPartialLeadStateKey($entry) {
  $baseKey = trim((string) ($entry['session_id'] ?? ''));
  if ($baseKey === '') {
    $baseKey = strtolower(trim((string) ($entry['email'] ?? '')) . '|' . normalizePhoneForCompare($entry['phone'] ?? ''));
  }

  if ($baseKey === '') {
    $baseKey = strtolower(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
  }

  return sha1($baseKey);
}

function shouldEmailPartialLead($entry, $previousState) {
  if (!is_array($previousState) || !$previousState) {
    return true;
  }

  $signature = buildPartialLeadSignature($entry);
  $previousSignature = strtolower(trim((string) ($previousState['signature'] ?? '')));

  if ($signature === '' || $signature === $previousSignature) {
    return false;
  }

  $nameChanged = strtolower(trim((string) ($entry['name'] ?? ''))) !== strtolower(trim((string) ($previousState['name'] ?? '')));
  $emailChanged = strtolower(trim((string) ($entry['email'] ?? ''))) !== strtolower(trim((string) ($previousState['email'] ?? '')));
  $phoneChanged = normalizePhoneForCompare($entry['phone'] ?? '') !== normalizePhoneForCompare($previousState['phone'] ?? '');

  if ($nameChanged || $emailChanged || $phoneChanged) {
    return true;
  }

  $score = buildPartialLeadScore($entry);
  $previousScore = (int) ($previousState['score'] ?? 0);
  $captureSource = strtolower(trim((string) ($entry['capture_source'] ?? '')));
  $flushSources = ['lead_form_close', 'pagehide_partial_lead', 'lead_flow_flush'];

  if ($score >= $previousScore + 2) {
    return true;
  }

  if (in_array($captureSource, $flushSources, true) && $score > $previousScore) {
    return true;
  }

  return false;
}

function buildPartialLeadEmailSubject($entry, $isUpdate) {
  $prefix = $isUpdate ? 'Updated Partial Chat Lead' : 'New Partial Chat Lead';
  $name = trim((string) ($entry['name'] ?? ''));

  if ($name !== '') {
    return "{$prefix} from {$name}";
  }

  $contact = trim((string) ($entry['email'] ?? '')) ?: trim((string) ($entry['phone'] ?? ''));
  if ($contact !== '') {
    return "{$prefix} ({$contact})";
  }

  return $prefix;
}

function buildPartialLeadEmailBody($entry) {
  $lines = [
    'This is a partial chatbot lead captured before the full form was submitted.',
    '',
  ];

  $detailMap = [
    'Source' => $entry['source'] ?? '',
    'Capture Source' => $entry['capture_source'] ?? '',
    'Captured At' => $entry['timestamp'] ?? '',
    'Name' => $entry['name'] ?? '',
    'Email' => $entry['email'] ?? '',
    'Phone' => $entry['phone'] ?? '',
    'Project Type' => $entry['project_type'] ?? '',
    'City' => $entry['city'] ?? '',
    'Space / Area' => $entry['space_type'] ?? '',
    'Material Interest' => $entry['material_interest'] ?? '',
    'New Construction / Remodel' => $entry['build_type'] ?? '',
    'Timeline' => $entry['timeline'] ?? '',
    'Customer Type' => $entry['customer_type'] ?? '',
    'Measurements / Plans' => $entry['measurements'] ?? '',
    'Tile Complete' => $entry['tile_complete'] ?? '',
    'Project Scope' => $entry['project_scope'] ?? '',
    'Plans Ready' => $entry['plans_ready'] ?? '',
    'Pricing / Scheduling' => $entry['pricing_or_scheduling'] ?? '',
    'Home or Commercial' => $entry['home_or_commercial'] ?? '',
    'Question' => $entry['question'] ?? '',
    'Chat Summary' => $entry['chat_summary'] ?? '',
    'Page URL' => $entry['page_url'] ?? '',
  ];

  foreach ($detailMap as $label => $value) {
    $value = trim((string) $value);
    if ($value !== '') {
      $lines[] = "{$label}: {$value}";
    }
  }

  $chatTranscript = trim((string) ($entry['chat_transcript'] ?? ''));
  if ($chatTranscript !== '') {
    $lines[] = '';
    $lines[] = 'Chat Transcript:';
    $lines[] = $chatTranscript;
  }

  return implode("\n", $lines);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  jsonResponse(false, 'Method not allowed.', 405);
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput ?: '', true);

if (!is_array($data) || !$data) {
  $data = $_POST;
}

$phone = trim($data['phone'] ?? '');
$email = trim($data['email'] ?? '');

if ($phone === '' && $email === '') {
  jsonResponse(false, 'Missing phone or email.', 400);
}

$logDirectory = ogmLeadStorageDir();
$logFile = $logDirectory . DIRECTORY_SEPARATOR . 'chatbot-partial-leads.log';
$stateDirectory = $logDirectory . DIRECTORY_SEPARATOR . 'chatbot-partial-email-state';

if (!ensureDirectory($logDirectory) || !ensureDirectory($stateDirectory)) {
  jsonResponse(false, 'Could not create log directory.', 500);
}

$entry = [
  'timestamp' => gmdate('c'),
  'session_id' => trim($data['session_id'] ?? ''),
  'capture_source' => trim($data['capture_source'] ?? 'chatbot_partial'),
  'source' => trim($data['source'] ?? 'Homepage Chatbot Partial Lead'),
  'question' => trim($data['question'] ?? ''),
  'name' => trim($data['name'] ?? ''),
  'email' => $email,
  'phone' => $phone,
  'project_type' => trim($data['project_type'] ?? ''),
  'city' => trim($data['city'] ?? ''),
  'space_type' => trim($data['space_type'] ?? ''),
  'material_interest' => trim($data['material_interest'] ?? ''),
  'build_type' => trim($data['build_type'] ?? ''),
  'timeline' => trim($data['timeline'] ?? ''),
  'chat_summary' => trim($data['chat_summary'] ?? ''),
  'chat_transcript' => trim($data['chat_transcript'] ?? ''),
  'customer_type' => trim($data['customer_type'] ?? ''),
  'measurements' => trim($data['measurements'] ?? ''),
  'tile_complete' => trim($data['tile_complete'] ?? ''),
  'project_scope' => trim($data['project_scope'] ?? ''),
  'plans_ready' => trim($data['plans_ready'] ?? ''),
  'pricing_or_scheduling' => trim($data['pricing_or_scheduling'] ?? ''),
  'home_or_commercial' => trim($data['home_or_commercial'] ?? ''),
  'page_url' => trim($data['page_url'] ?? ''),
  'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
  'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
];

$entry['lead_key'] = ogmBuildLeadKey($entry);

$written = @file_put_contents(
  $logFile,
  json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL,
  FILE_APPEND | LOCK_EX
);

if ($written === false) {
  jsonResponse(false, 'Could not write partial lead.', 500);
}

ogmRecordLeadHistory($entry);

$stateFile = $stateDirectory . DIRECTORY_SEPARATOR . buildPartialLeadStateKey($entry) . '.json';
$previousState = null;

if (is_file($stateFile)) {
  $previousState = json_decode((string) file_get_contents($stateFile), true);
  if (!is_array($previousState)) {
    $previousState = null;
  }
}

$emailed = false;
$emailStatus = 'skipped';

if (shouldEmailPartialLead($entry, $previousState) && function_exists('mail')) {
  $headers = [];
  $headers[] = "From: Olive Glass & Marble <{$fromEmail}>";
  if ($email !== '') {
    $headers[] = "Reply-To: {$email}";
  }
  $headers[] = 'Content-Type: text/plain; charset=UTF-8';

  $subject = buildPartialLeadEmailSubject($entry, is_array($previousState) && !empty($previousState));
  $body = buildPartialLeadEmailBody($entry);
  $sent = @mail($toEmail, $subject, $body, implode("\r\n", $headers));

  if ($sent) {
    $emailed = true;
    $emailStatus = 'sent';
    @file_put_contents($stateFile, json_encode([
      'emailed_at' => gmdate('c'),
      'signature' => buildPartialLeadSignature($entry),
      'score' => buildPartialLeadScore($entry),
      'name' => $entry['name'],
      'email' => $entry['email'],
      'phone' => $entry['phone'],
      'capture_source' => $entry['capture_source'],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
  } else {
    $emailStatus = 'failed';
  }
}

jsonResponse(true, '', 200, [
  'emailed' => $emailed,
  'email_status' => $emailStatus,
]);
