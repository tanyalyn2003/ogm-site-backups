<?php
// Simple form handler for contact.html
// Customize the recipient and from address as needed.

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lead-storage.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'pushover.php';

$toEmail = 'info@oliveglassandmarble.com';
$fromEmail = 'website@oliveglassandmarble.com'; // Use a domain-based address for better deliverability

function wantsJsonResponse() {
  $requestedWith = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
  $acceptHeader = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
  $responseFormat = strtolower($_POST['response_format'] ?? '');

  return $requestedWith === 'xmlhttprequest'
    || strpos($acceptHeader, 'application/json') !== false
    || $responseFormat === 'json';
}

function normalizeUploadedFiles($fieldName) {
  if (!isset($_FILES[$fieldName])) {
    return [];
  }

  $files = $_FILES[$fieldName];
  if (!is_array($files['name'] ?? null)) {
    return [$files];
  }

  $normalized = [];
  $count = count($files['name']);
  for ($index = 0; $index < $count; $index += 1) {
    $normalized[] = [
      'name' => $files['name'][$index] ?? '',
      'type' => $files['type'][$index] ?? '',
      'tmp_name' => $files['tmp_name'][$index] ?? '',
      'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
      'size' => $files['size'][$index] ?? 0,
    ];
  }

  return $normalized;
}

function getUploadErrorMessage($errorCode) {
  switch ((int) $errorCode) {
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
      return 'One of the uploaded files is too large.';
    case UPLOAD_ERR_PARTIAL:
      return 'One of the uploaded files did not finish uploading.';
    case UPLOAD_ERR_NO_TMP_DIR:
    case UPLOAD_ERR_CANT_WRITE:
    case UPLOAD_ERR_EXTENSION:
      return 'We could not process one of the uploaded files.';
    default:
      return 'There was a problem with one of the uploaded files.';
  }
}

function detectUploadedMimeType($tmpPath) {
  if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
      $mimeType = finfo_file($finfo, $tmpPath) ?: '';
      finfo_close($finfo);
      if ($mimeType !== '') {
        return strtolower($mimeType);
      }
    }
  }

  if (function_exists('mime_content_type')) {
    $mimeType = mime_content_type($tmpPath);
    if ($mimeType) {
      return strtolower($mimeType);
    }
  }

  return '';
}

function sanitizeAttachmentName($fileName, $index) {
  $baseName = basename((string) $fileName);
  $cleanName = preg_replace('/[^A-Za-z0-9._-]/', '_', $baseName);

  if ($cleanName === '' || $cleanName === '.' || $cleanName === '..') {
    return 'upload-' . $index;
  }

  return $cleanName;
}

// Basic response template
function respond($title, $message, $isError = false) {
  if (wantsJsonResponse()) {
    http_response_code($isError ? 400 : 200);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
      'success' => !$isError,
      'title' => $title,
      'message' => $message,
    ]);
    exit;
  }

  $statusColor = $isError ? '#c0392b' : '#2c7a47';
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Submission</title>
    <style>
      body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f9f7f3; color: #1a1a1a; padding: 2rem; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
      .card { background: #fff; max-width: 520px; width: 100%; padding: 2rem; border-radius: 12px; box-shadow: 0 16px 40px rgba(0,0,0,0.1); }
      h1 { margin: 0 0 1rem 0; font-size: 1.5rem; color: <?php echo $statusColor; ?>; }
      p { margin: 0 0 1rem 0; line-height: 1.6; }
      a.button { display: inline-block; margin-top: 1rem; background: #c9a87c; color: #fff; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; font-weight: 600; }
      a.button:hover { background: #b89466; }
    </style>
  </head>
  <body>
    <div class="card">
      <h1><?php echo htmlspecialchars($title); ?></h1>
      <p><?php echo htmlspecialchars($message); ?></p>
      <a class="button" href="contact.html">Back to Contact Page</a>
    </div>
  </body>
  </html>
  <?php
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond('Invalid Request', 'Please submit the form from the contact page.', true);
}

// Honeypot to reduce spam
$honeypot = trim($_POST['website'] ?? '');
if ($honeypot !== '') {
  respond('Thank You', 'Thank you for your message.'); // Silently accept bots
}

// Collect fields
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$projectType = trim($_POST['project-type'] ?? '');
$city = trim($_POST['city'] ?? '');
$spaceType = trim($_POST['space-type'] ?? '');
$materialInterest = trim($_POST['material-interest'] ?? '');
$buildType = trim($_POST['build-type'] ?? '');
$timeline = trim($_POST['timeline'] ?? '');
$chatSummary = trim($_POST['chat-summary'] ?? '');
$chatTranscript = trim($_POST['chat-transcript'] ?? '');
$customerType = trim($_POST['customer-type'] ?? '');
$measurements = trim($_POST['measurements'] ?? '');
$tileComplete = trim($_POST['tile-complete'] ?? '');
$projectScope = trim($_POST['project-scope'] ?? '');
$plansReady = trim($_POST['plans-ready'] ?? '');
$pricingOrScheduling = trim($_POST['pricing-or-scheduling'] ?? '');
$homeOrCommercial = trim($_POST['home-or-commercial'] ?? '');
$source = trim($_POST['source'] ?? 'Website Contact Form');
$message = trim($_POST['message'] ?? '');
$uploadedFiles = array_values(array_filter(normalizeUploadedFiles('project-files'), function ($file) {
  return ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}));
$hasStructuredDetails = $projectType !== ''
  || $city !== ''
  || $spaceType !== ''
  || $materialInterest !== ''
  || $buildType !== ''
  || $timeline !== ''
  || $chatSummary !== ''
  || $chatTranscript !== ''
  || $customerType !== ''
  || $measurements !== ''
  || $tileComplete !== ''
  || $projectScope !== ''
  || $plansReady !== ''
  || $pricingOrScheduling !== ''
  || $homeOrCommercial !== ''
  || !empty($uploadedFiles);

// Validate required fields
if ($name === '' || ($message === '' && !$hasStructuredDetails) || ($email === '' && $phone === '')) {
  respond('Missing Information', 'Please fill in the required fields (name, either a phone number or email address, and project details in the form or chat) and try again.', true);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  respond('Invalid Email', 'Please provide a valid email address.', true);
}

$allowedAttachmentExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'];
$allowedAttachmentMimeTypes = [
  'application/pdf',
  'image/jpeg',
  'image/png',
  'image/webp',
  'image/gif',
];
$attachmentMimeMap = [
  'pdf' => 'application/pdf',
  'jpg' => 'image/jpeg',
  'jpeg' => 'image/jpeg',
  'png' => 'image/png',
  'webp' => 'image/webp',
  'gif' => 'image/gif',
];
$maxAttachmentCount = 4;
$maxAttachmentSize = 5 * 1024 * 1024;
$maxAttachmentTotalSize = 12 * 1024 * 1024;
$attachmentNames = [];
$attachments = [];
$totalAttachmentSize = 0;

if (count($uploadedFiles) > $maxAttachmentCount) {
  respond('Too Many Files', 'Please upload no more than 4 files.', true);
}

foreach ($uploadedFiles as $index => $file) {
  $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($uploadError !== UPLOAD_ERR_OK) {
    respond('Upload Problem', getUploadErrorMessage($uploadError), true);
  }

  $tmpName = (string) ($file['tmp_name'] ?? '');
  if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    respond('Upload Problem', 'One of the uploaded files could not be verified.', true);
  }

  $fileSize = (int) ($file['size'] ?? 0);
  if ($fileSize <= 0) {
    respond('Upload Problem', 'One of the uploaded files appears to be empty.', true);
  }

  if ($fileSize > $maxAttachmentSize) {
    respond('File Too Large', 'Each upload must be 5MB or smaller.', true);
  }

  $totalAttachmentSize += $fileSize;
  if ($totalAttachmentSize > $maxAttachmentTotalSize) {
    respond('Uploads Too Large', 'Combined uploads must stay under 12MB.', true);
  }

  $safeName = sanitizeAttachmentName($file['name'] ?? '', $index + 1);
  $extension = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
  if (!in_array($extension, $allowedAttachmentExtensions, true)) {
    respond('Unsupported File Type', 'Please upload PDF, JPG, PNG, WEBP, or GIF files only.', true);
  }

  $detectedMimeType = detectUploadedMimeType($tmpName);
  if ($detectedMimeType === '') {
    $detectedMimeType = $attachmentMimeMap[$extension] ?? 'application/octet-stream';
  }

  if (!in_array($detectedMimeType, $allowedAttachmentMimeTypes, true)) {
    respond('Unsupported File Type', 'Please upload PDF or image files only.', true);
  }

  $content = file_get_contents($tmpName);
  if ($content === false) {
    respond('Upload Problem', 'We could not read one of the uploaded files.', true);
  }

  $attachmentNames[] = $safeName;
  $attachments[] = [
    'name' => $safeName,
    'mime' => $detectedMimeType,
    'content' => $content,
  ];
}

$subjectPrefix = stripos($source, 'chat') !== false ? 'New Chatbot Inquiry' : 'New Project Inquiry';
$subject = "{$subjectPrefix} from {$name}";
$bodyLines = [];
$detailMap = [
  'Source' => $source,
  'Name' => $name,
  'Email' => $email,
  'Phone' => $phone,
  'Project Type' => $projectType,
  'City' => $city,
  'Space / Area' => $spaceType,
  'Material Interest' => $materialInterest,
  'New Construction / Remodel' => $buildType,
  'Timeline' => $timeline,
  'Chat Summary' => $chatSummary,
  'Customer Type' => $customerType,
  'Measurements / Plans' => $measurements,
  'Tile Complete' => $tileComplete,
  'Project Scope' => $projectScope,
  'Plans Ready' => $plansReady,
  'Pricing / Scheduling' => $pricingOrScheduling,
  'Home or Commercial' => $homeOrCommercial,
];

foreach ($detailMap as $label => $value) {
  if ($value !== '') {
    $bodyLines[] = "{$label}: {$value}";
  }
}

if (!empty($attachmentNames)) {
  $bodyLines[] = 'Attachments: ' . implode(', ', $attachmentNames);
}

$bodyLines[] = '';
$bodyLines[] = 'Message:';
$bodyLines[] = $message;

if ($chatTranscript !== '') {
  $bodyLines[] = '';
  $bodyLines[] = 'Chat Transcript:';
  $bodyLines[] = $chatTranscript;
}

$body = implode("\n", $bodyLines);

$headers = [];
$headers[] = "From: Olive Glass & Marble <{$fromEmail}>";
if ($email !== '') {
  $headers[] = "Reply-To: {$email}";
}
$headers[] = 'MIME-Version: 1.0';
$emailBody = $body;

if (!empty($attachments)) {
  $boundary = 'ogm-chat-' . md5(uniqid((string) mt_rand(), true));
  $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

  $parts = [];
  $parts[] = "--{$boundary}";
  $parts[] = 'Content-Type: text/plain; charset=UTF-8';
  $parts[] = 'Content-Transfer-Encoding: 8bit';
  $parts[] = '';
  $parts[] = $body;

  foreach ($attachments as $attachment) {
    $parts[] = "--{$boundary}";
    $parts[] = "Content-Type: {$attachment['mime']}; name=\"{$attachment['name']}\"";
    $parts[] = 'Content-Transfer-Encoding: base64';
    $parts[] = "Content-Disposition: attachment; filename=\"{$attachment['name']}\"";
    $parts[] = '';
    $parts[] = chunk_split(base64_encode($attachment['content']));
  }

  $parts[] = "--{$boundary}--";
  $parts[] = '';
  $emailBody = implode("\r\n", $parts);
} else {
  $headers[] = 'Content-Type: text/plain; charset=UTF-8';
}

$headersString = implode("\r\n", $headers);

$sent = mail($toEmail, $subject, $emailBody, $headersString);
$pushResult = ogmSendFullLeadPushover([
  'source' => $source,
  'name' => $name,
  'email' => $email,
  'phone' => $phone,
  'project_type' => $projectType,
  'city' => $city,
  'timeline' => $timeline,
  'chat_summary' => $chatSummary,
  'message' => $message,
]);

ogmStoreFullLead([
  'source' => $source,
  'name' => $name,
  'email' => $email,
  'phone' => $phone,
  'project_type' => $projectType,
  'city' => $city,
  'space_type' => $spaceType,
  'material_interest' => $materialInterest,
  'build_type' => $buildType,
  'timeline' => $timeline,
  'chat_summary' => $chatSummary,
  'chat_transcript' => $chatTranscript,
  'customer_type' => $customerType,
  'measurements' => $measurements,
  'tile_complete' => $tileComplete,
  'project_scope' => $projectScope,
  'plans_ready' => $plansReady,
  'pricing_or_scheduling' => $pricingOrScheduling,
  'home_or_commercial' => $homeOrCommercial,
  'message' => $message,
  'attachment_names' => $attachmentNames,
  'mail_status' => $sent ? 'sent' : 'failed',
  'push_status' => trim((string) ($pushResult['status'] ?? 'unknown')),
  'push_note' => trim((string) ($pushResult['message'] ?? '')),
  'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
  'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
]);

if ($sent) {
  respond('Message Sent', 'Thank you for reaching out. We will get back to you within 24 hours.');
} else {
  respond('Delivery Problem', 'We could not send your message right now. Please call us at (910) 484-5277 or email info@oliveglassandmarble.com.', true);
}
