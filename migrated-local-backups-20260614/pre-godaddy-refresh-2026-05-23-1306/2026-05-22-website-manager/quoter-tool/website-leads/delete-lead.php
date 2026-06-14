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
$returnQuery = trim((string) ($_POST['return_query'] ?? ''));
$redirect = 'index.php';
if ($returnQuery !== '') {
  $redirect .= '?' . ltrim($returnQuery, '?&');
}

if ($leadKey === '') {
  header('Location: ' . $redirect);
  exit;
}

$leadMap = [];
foreach (adminBuildLeads() as $lead) {
  $currentKey = trim((string) ($lead['lead_key'] ?? ''));
  if ($currentKey !== '') {
    $leadMap[$currentKey] = $lead;
  }
}

if (empty($leadMap[$leadKey]['is_trashed'])) {
  header('Location: ' . $redirect);
  exit;
}

$deletedCount = adminPermanentlyDeleteLeadKeys([$leadKey]);
if ($deletedCount === false) {
  http_response_code(500);
  echo 'Could not permanently delete the lead.';
  exit;
}

$separator = strpos($redirect, '?') === false ? '?' : '&';
header('Location: ' . $redirect . $separator . 'deleted=1&deleted_count=' . rawurlencode((string) $deletedCount));
exit;
