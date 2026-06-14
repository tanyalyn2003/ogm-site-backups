<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

adminSendNoIndexHeaders();

$authorized = false;
if (adminIsLoggedIn()) {
  $authorized = true;
}

$token = trim((string) ($_GET['token'] ?? ''));
if (!$authorized && $token !== '' && hash_equals(adminReportToken(), $token)) {
  $authorized = true;
}

if (!$authorized) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

$window = adminScheduledReportWindow();
if ($window === null) {
  echo 'No scheduled report today.';
  exit;
}

$force = isset($_GET['force']) && $_GET['force'] === '1';
$reportKey = $window['key'];

if (!$force && adminReportAlreadySent($reportKey)) {
  echo 'Report already sent for ' . $window['label'] . '.';
  exit;
}

$recipient = adminReportRecipientEmail();
if ($recipient === '') {
  http_response_code(500);
  echo 'Missing report recipient.';
  exit;
}

$allLeads = adminBuildLeads();
$report = adminBuildReportData($allLeads, $window['start'], $window['end'], $window['label']);
$sent = adminSendReportEmail($report, $recipient);

if (!$sent) {
  http_response_code(500);
  echo 'Could not send report.';
  exit;
}

adminMarkReportSent($reportKey, [
  'recipient' => $recipient,
  'label' => $window['label'],
  'lead_count' => $report['lead_count'],
]);

echo 'Report sent to ' . $recipient . ' for ' . $window['label'] . '.';
