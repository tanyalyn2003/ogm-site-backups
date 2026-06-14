<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

adminSendNoIndexHeaders();
adminRequireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: index.php?view=trash');
  exit;
}

if (!adminVerifyCsrfToken($_POST['csrf_token'] ?? '')) {
  http_response_code(400);
  echo 'Invalid request.';
  exit;
}

$returnQuery = trim((string) ($_POST['return_query'] ?? 'view=trash'));
$redirect = 'index.php';
if ($returnQuery !== '') {
  $redirect .= '?' . ltrim($returnQuery, '?&');
}

$trashedKeys = [];
foreach (adminBuildLeads() as $lead) {
  if (!empty($lead['is_trashed']) && !empty($lead['lead_key'])) {
    $trashedKeys[] = (string) $lead['lead_key'];
  }
}

$trashedKeys = adminNormalizeLeadKeys($trashedKeys);
if (!$trashedKeys) {
  $separator = strpos($redirect, '?') === false ? '?' : '&';
  header('Location: ' . $redirect . $separator . 'trash_empty=1');
  exit;
}

$deletedCount = adminPermanentlyDeleteLeadKeys($trashedKeys);
if ($deletedCount === false) {
  http_response_code(500);
  echo 'Could not empty trash.';
  exit;
}

$separator = strpos($redirect, '?') === false ? '?' : '&';
header('Location: ' . $redirect . $separator . 'deleted=1&deleted_count=' . rawurlencode((string) $deletedCount));
exit;
