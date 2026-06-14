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
$action = trim((string) ($_POST['action'] ?? ''));

if ($leadKey === '' || !in_array($action, ['trash', 'restore'], true)) {
  header('Location: index.php');
  exit;
}

$currentUser = adminCurrentUser();
$updatedBy = trim((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? ''));
$timestamp = gmdate('c');

$updates = [
  'updated_at' => $timestamp,
  'updated_by' => $updatedBy,
];

if ($action === 'trash') {
  $updates['trashed'] = true;
  $updates['trashed_at'] = $timestamp;
  $updates['trashed_by'] = $updatedBy;
} else {
  $updates['trashed'] = false;
  $updates['trashed_at'] = '';
  $updates['trashed_by'] = '';
}

$state = adminReadDashboardState();
$state = adminWriteLeadState($state, $leadKey, $updates);
adminSaveDashboardState($state);

$returnQuery = trim((string) ($_POST['return_query'] ?? ''));
$returnQuery = preg_replace('/(^|&)view=trash(?=&|$)/', '$1view=active', $returnQuery);
$returnQuery = trim((string) $returnQuery, '&');
$redirect = 'index.php?trash_saved=1';
if ($returnQuery !== '') {
  $redirect .= '&' . ltrim($returnQuery, '?&');
}

if ($action === 'restore') {
  $redirect .= '&open=' . rawurlencode($leadKey);
}

$redirect .= '#lead-' . rawurlencode($leadKey);

header('Location: ' . $redirect);
exit;
