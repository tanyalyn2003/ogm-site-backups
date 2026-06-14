<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

adminSendNoIndexHeaders();
adminRequireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: index.php');
  exit;
}

if (!adminVerifyCsrfToken($_POST['csrf_token'] ?? '')) {
  http_response_code(400);
  echo 'Invalid request.';
  exit;
}

$leadKey = trim((string) ($_POST['lead_key'] ?? ''));
$status = trim((string) ($_POST['status'] ?? ''));
$owner = trim((string) ($_POST['owner'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));

$statusOptions = adminStatusOptions();
if ($leadKey === '' || !isset($statusOptions[$status])) {
  header('Location: index.php');
  exit;
}

$state = adminReadDashboardState();

if ($owner === 'Unassigned') {
  $owner = '';
}

$currentUser = adminCurrentUser();
$state = adminWriteLeadState($state, $leadKey, [
  'status' => $status,
  'owner' => substr($owner, 0, 80),
  'notes' => substr($notes, 0, 4000),
  'updated_at' => gmdate('c'),
  'updated_by' => trim((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? '')),
]);

adminSaveDashboardState($state);

$returnQuery = trim((string) ($_POST['return_query'] ?? ''));
$redirect = 'index.php?saved=1';
if ($returnQuery !== '') {
  $redirect .= '&' . ltrim($returnQuery, '?&');
}

$redirect .= '&open=' . rawurlencode($leadKey);
$redirect .= '#lead-' . rawurlencode($leadKey);

header('Location: ' . $redirect);
exit;
