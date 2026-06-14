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

$returnTo = trim((string) ($_POST['return_to'] ?? 'index.php'));
if (!in_array($returnTo, ['index.php', 'analytics.php'], true)) {
  $returnTo = 'index.php';
}

$existingNames = array_values((array) ($_POST['existing_names'] ?? []));
$repNames = array_values((array) ($_POST['rep_names'] ?? []));
$deleteNames = array_values((array) ($_POST['delete_names'] ?? []));
$deleteLookup = [];

foreach ($deleteNames as $name) {
  $name = strtolower(trim((string) $name));
  if ($name !== '') {
    $deleteLookup[$name] = true;
  }
}

$renameMap = [];
$deletedOwners = [];
$finalOwners = [];
$rowCount = max(count($existingNames), count($repNames));

for ($index = 0; $index < $rowCount; $index += 1) {
  $original = trim((string) ($existingNames[$index] ?? ''));
  $updated = trim((string) ($repNames[$index] ?? ''));

  if ($original === '' && $updated === '') {
    continue;
  }

  $isDeleted = $original !== '' && isset($deleteLookup[strtolower($original)]);
  if ($isDeleted || $updated === '') {
    if ($original !== '') {
      $deletedOwners[] = $original;
    }
    continue;
  }

  if ($original !== '' && strcasecmp($original, $updated) !== 0) {
    $renameMap[strtolower($original)] = $updated;
  }

  $finalOwners[] = $updated;
}

$newRepNames = preg_split('/\R+/', (string) ($_POST['new_rep_names'] ?? '')) ?: [];
foreach ($newRepNames as $name) {
  $finalOwners[] = $name;
}

$finalOwners = adminNormalizeOwnerList($finalOwners);
if (!adminSaveRepList($finalOwners)) {
  http_response_code(500);
  echo 'Could not save sales reps.';
  exit;
}

$currentUser = adminCurrentUser();
$updatedBy = trim((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? ''));
$state = adminReadDashboardState();
$state = adminApplyOwnerMigrations($state, $renameMap, $deletedOwners, $updatedBy);

if (!adminSaveDashboardState($state)) {
  http_response_code(500);
  echo 'Could not update lead assignments.';
  exit;
}

header('Location: ' . $returnTo . '?team_saved=1');
exit;
