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

$returnQuery = trim((string) ($_POST['return_query'] ?? ''));
$redirectBase = 'index.php';
if ($returnQuery !== '') {
  $redirectBase .= '?' . ltrim($returnQuery, '?&');
}

$bulkAction = trim((string) ($_POST['bulk_action'] ?? ''));
$selectedKeys = array_values(array_unique(array_filter(array_map(static function ($value) {
  return trim((string) $value);
}, (array) ($_POST['lead_keys'] ?? [])))));

$allowedActions = ['assign_owner', 'clear_owner', 'change_status', 'trash', 'restore', 'delete_permanently'];
if (!$selectedKeys || !in_array($bulkAction, $allowedActions, true)) {
  $separator = strpos($redirectBase, '?') === false ? '?' : '&';
  header('Location: ' . $redirectBase . $separator . 'bulk_error=1');
  exit;
}

$leadMap = [];
foreach (adminBuildLeads() as $lead) {
  $leadKey = trim((string) ($lead['lead_key'] ?? ''));
  if ($leadKey !== '') {
    $leadMap[$leadKey] = $lead;
  }
}

$selectedKeys = array_values(array_filter($selectedKeys, static function ($leadKey) use ($leadMap) {
  return isset($leadMap[$leadKey]);
}));

if (!$selectedKeys) {
  $separator = strpos($redirectBase, '?') === false ? '?' : '&';
  header('Location: ' . $redirectBase . $separator . 'bulk_error=1');
  exit;
}

if ($bulkAction === 'delete_permanently') {
  $selectedKeys = array_values(array_filter($selectedKeys, static function ($leadKey) use ($leadMap) {
    return !empty($leadMap[$leadKey]['is_trashed']);
  }));

  if (!$selectedKeys) {
    $separator = strpos($redirectBase, '?') === false ? '?' : '&';
    header('Location: ' . $redirectBase . $separator . 'bulk_error=1');
    exit;
  }

  $deletedCount = adminPermanentlyDeleteLeadKeys($selectedKeys);
  if ($deletedCount === false) {
    http_response_code(500);
    echo 'Could not permanently delete selected leads.';
    exit;
  }

  $separator = strpos($redirectBase, '?') === false ? '?' : '&';
  header('Location: ' . $redirectBase . $separator . 'deleted=1&deleted_count=' . rawurlencode((string) $deletedCount));
  exit;
}

$state = adminReadDashboardState();
$currentUser = adminCurrentUser();
$updatedBy = trim((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? ''));
$timestamp = gmdate('c');
$updates = [
  'updated_at' => $timestamp,
  'updated_by' => $updatedBy,
];

if ($bulkAction === 'assign_owner') {
  $owner = trim((string) ($_POST['bulk_owner'] ?? ''));
  $validOwners = adminOwnerOptions();
  if ($owner === '' || !in_array($owner, $validOwners, true)) {
    $separator = strpos($redirectBase, '?') === false ? '?' : '&';
    header('Location: ' . $redirectBase . $separator . 'bulk_error=1');
    exit;
  }
  $updates['owner'] = $owner;
} elseif ($bulkAction === 'clear_owner') {
  $updates['owner'] = '';
} elseif ($bulkAction === 'change_status') {
  $status = trim((string) ($_POST['bulk_status'] ?? ''));
  $statusOptions = adminStatusOptions();
  if (!isset($statusOptions[$status])) {
    $separator = strpos($redirectBase, '?') === false ? '?' : '&';
    header('Location: ' . $redirectBase . $separator . 'bulk_error=1');
    exit;
  }
  $updates['status'] = $status;
} elseif ($bulkAction === 'trash') {
  $updates['trashed'] = true;
  $updates['trashed_at'] = $timestamp;
  $updates['trashed_by'] = $updatedBy;
} elseif ($bulkAction === 'restore') {
  $updates['trashed'] = false;
  $updates['trashed_at'] = '';
  $updates['trashed_by'] = '';
}

foreach ($selectedKeys as $leadKey) {
  $state = adminWriteLeadState($state, $leadKey, $updates);
}

adminSaveDashboardState($state);

if ($bulkAction === 'restore') {
  $returnQuery = preg_replace('/(^|&)view=trash(?=&|$)/', '$1view=active', $returnQuery);
  $returnQuery = trim((string) $returnQuery, '&');
  $redirectBase = 'index.php';
  if ($returnQuery !== '') {
    $redirectBase .= '?' . ltrim($returnQuery, '?&');
  }
}

$separator = strpos($redirectBase, '?') === false ? '?' : '&';
header('Location: ' . $redirectBase . $separator . 'bulk_saved=1&bulk_count=' . rawurlencode((string) count($selectedKeys)));
exit;
