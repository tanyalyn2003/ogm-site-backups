<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lead-storage.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'pushover.php';

$toEmail = 'info@oliveglassandmarble.com';
$fromEmail = 'website@oliveglassandmarble.com';

function slabReservationWantsJson() {
  $requestedWith = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
  $acceptHeader = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
  $responseFormat = strtolower($_POST['response_format'] ?? '');

  return $requestedWith === 'xmlhttprequest'
    || strpos($acceptHeader, 'application/json') !== false
    || $responseFormat === 'json';
}

function slabReservationRespond($title, $message, $isError = false) {
  if (slabReservationWantsJson()) {
    http_response_code($isError ? 400 : 200);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
      'success' => !$isError,
      'title' => $title,
      'message' => $message,
    ]);
    exit;
  }

  $statusColor = $isError ? '#9f2d2d' : '#1c7447';
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Slab Reservation</title>
    <style>
      body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f6f1e8; color: #231d18; padding: 2rem; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
      .card { background: #fffdfa; max-width: 560px; width: 100%; padding: 2rem; border-radius: 16px; box-shadow: 0 18px 40px rgba(0,0,0,0.08); }
      h1 { margin: 0 0 1rem; font-size: 1.5rem; color: <?php echo $statusColor; ?>; }
      p { margin: 0 0 1rem; line-height: 1.6; }
      a { display: inline-block; margin-top: 1rem; background: #c9a87c; color: #fff; padding: 0.8rem 1.3rem; border-radius: 999px; text-decoration: none; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
    </style>
  </head>
  <body>
    <div class="card">
      <h1><?php echo htmlspecialchars($title); ?></h1>
      <p><?php echo htmlspecialchars($message); ?></p>
      <a href="OGM-inventory.html">Back to Inventory</a>
    </div>
  </body>
  </html>
  <?php
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  slabReservationRespond('Invalid Request', 'Please submit the reservation form from the inventory page.', true);
}

$honeypot = trim($_POST['website'] ?? '');
if ($honeypot !== '') {
  slabReservationRespond('Request Received', 'Thank you. We will review your slab request shortly.');
}

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$preferredContact = trim($_POST['preferred_contact'] ?? 'Call');
$message = trim($_POST['message'] ?? '');

$slabName = trim($_POST['slab_name'] ?? '');
$slabNumber = trim($_POST['slab_number'] ?? '');
$slabMaterial = trim($_POST['slab_material'] ?? '');
$slabSize = trim($_POST['slab_size'] ?? '');
$slabArea = trim($_POST['slab_area'] ?? '');
$slabThickness = trim($_POST['slab_thickness'] ?? '');
$slabImageUrl = trim($_POST['slab_image_url'] ?? '');

if ($name === '' || ($email === '' && $phone === '')) {
  slabReservationRespond('Missing Information', 'Please provide your name and either a phone number or an email address.', true);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  slabReservationRespond('Invalid Email', 'Please provide a valid email address.', true);
}

if ($slabName === '' && $slabNumber === '') {
  slabReservationRespond('Missing Slab Information', 'We could not identify the slab tied to this request. Please reopen the slab details and try again.', true);
}

$source = 'OGM Inventory Reservation';
$slabLabel = trim($slabName . ($slabNumber !== '' ? " (#{$slabNumber})" : ''));
$subject = "New Slab Reservation from {$name}";
$bodyLines = [
  "Source: {$source}",
  "Name: {$name}",
];

if ($email !== '') {
  $bodyLines[] = "Email: {$email}";
}
if ($phone !== '') {
  $bodyLines[] = "Phone: {$phone}";
}
$bodyLines[] = "Preferred Contact: {$preferredContact}";
$bodyLines[] = "Slab: {$slabLabel}";
if ($slabMaterial !== '') {
  $bodyLines[] = "Material: {$slabMaterial}";
}
if ($slabThickness !== '') {
  $bodyLines[] = "Thickness: {$slabThickness}";
}
if ($slabSize !== '') {
  $bodyLines[] = "Slab Size: {$slabSize}";
}
if ($slabArea !== '') {
  $bodyLines[] = "Area: {$slabArea}";
}
if ($slabImageUrl !== '') {
  $bodyLines[] = "Image URL: {$slabImageUrl}";
}
$bodyLines[] = '';
$bodyLines[] = 'Reservation Notes:';
$bodyLines[] = $message !== '' ? $message : 'No additional notes provided.';

$headers = [];
$headers[] = "From: Olive Glass & Marble <{$fromEmail}>";
if ($email !== '') {
  $headers[] = "Reply-To: {$email}";
}
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headersString = implode("\r\n", $headers);
$emailBody = implode("\n", $bodyLines);

$sent = mail($toEmail, $subject, $emailBody, $headersString);
$pushResult = ogmSendFullLeadPushover([
  'source' => $source,
  'name' => $name,
  'email' => $email,
  'phone' => $phone,
  'project_type' => 'Slab Reservation',
  'timeline' => $preferredContact,
  'chat_summary' => $slabLabel,
  'message' => $message !== '' ? $message : "Requested slab: {$slabLabel}",
]);

ogmStoreFullLead([
  'source' => $source,
  'name' => $name,
  'email' => $email,
  'phone' => $phone,
  'project_type' => 'Slab Reservation',
  'material_interest' => $slabMaterial,
  'timeline' => $preferredContact,
  'measurements' => trim(implode(' · ', array_filter([$slabSize, $slabArea, $slabThickness]))),
  'project_scope' => $slabLabel,
  'message' => trim($message . ($slabImageUrl !== '' ? "\n\nImage URL: {$slabImageUrl}" : '')),
  'mail_status' => $sent ? 'sent' : 'failed',
  'push_status' => trim((string) ($pushResult['status'] ?? 'unknown')),
  'push_note' => trim((string) ($pushResult['message'] ?? '')),
  'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
  'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
]);

if ($sent) {
  slabReservationRespond('Reservation Request Sent', 'Thank you. Our team will review this slab request and contact you shortly.');
}

slabReservationRespond('Delivery Problem', 'We could not send your request right now. Please call us at (910) 484-5277 or email info@oliveglassandmarble.com.', true);
