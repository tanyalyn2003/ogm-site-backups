<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

adminSendNoIndexHeaders();
adminRequireLogin();

function adminRedirectUserSettings($returnTo, $params = []) {
  $query = http_build_query($params);
  header('Location: ' . $returnTo . ($query !== '' ? '?' . $query : ''));
  exit;
}

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

$users = adminReadUsers();
$protectedLookup = array_fill_keys(adminProtectedUsernames(), true);
$currentUser = adminCurrentUser();
$currentUsername = adminNormalizeUsername((string) ($currentUser['username'] ?? ''));

$submittedDisplayNames = is_array($_POST['display_names'] ?? null) ? $_POST['display_names'] : [];
$submittedPasswords = is_array($_POST['reset_passwords'] ?? null) ? $_POST['reset_passwords'] : [];
$deleteUsernames = is_array($_POST['delete_usernames'] ?? null) ? $_POST['delete_usernames'] : [];
$deleteLookup = [];

foreach ($deleteUsernames as $username) {
  $username = adminNormalizeUsername($username);
  if ($username !== '') {
    $deleteLookup[$username] = true;
  }
}

foreach ($users as $username => $user) {
  $displayName = trim((string) ($submittedDisplayNames[$username] ?? ($user['display_name'] ?? '')));
  if ($displayName === '') {
    $displayName = trim((string) ($user['display_name'] ?? $username)) ?: $username;
  }

  if (isset($deleteLookup[$username])) {
    if (isset($protectedLookup[$username])) {
      continue;
    }

    if ($username === $currentUsername) {
      adminRedirectUserSettings($returnTo, [
        'users_error' => 'You cannot delete the login you are currently using.',
      ]);
    }

    unset($users[$username]);
    continue;
  }

  $users[$username]['display_name'] = $displayName;

  $newPassword = trim((string) ($submittedPasswords[$username] ?? ''));
  if ($newPassword !== '') {
    $users[$username] = array_merge($users[$username], adminHashPassword($newPassword));
  }
}

$newDisplayName = trim((string) ($_POST['new_display_name'] ?? ''));
$newUsername = adminNormalizeUsername((string) ($_POST['new_username'] ?? ''));
$newPassword = trim((string) ($_POST['new_password'] ?? ''));

$newFieldsStarted = $newDisplayName !== '' || $newUsername !== '' || $newPassword !== '';
if ($newFieldsStarted) {
  if ($newDisplayName === '' || $newUsername === '' || $newPassword === '') {
    adminRedirectUserSettings($returnTo, [
      'users_error' => 'New logins need a display name, username, and password.',
    ]);
  }

  if (isset($users[$newUsername])) {
    adminRedirectUserSettings($returnTo, [
      'users_error' => 'That username already exists.',
    ]);
  }

  $users[$newUsername] = array_merge([
    'display_name' => $newDisplayName,
  ], adminHashPassword($newPassword));
}

if (!$users) {
  adminRedirectUserSettings($returnTo, [
    'users_error' => 'At least one login account must remain.',
  ]);
}

if (!adminSaveUsers($users)) {
  adminRedirectUserSettings($returnTo, [
    'users_error' => 'Could not save login accounts.',
  ]);
}

if ($currentUsername !== '' && isset($users[$currentUsername])) {
  adminStartSession();
  $_SESSION['ogm_admin_user'] = [
    'username' => $currentUsername,
    'display_name' => trim((string) ($users[$currentUsername]['display_name'] ?? $currentUsername)),
  ];
}

adminRedirectUserSettings($returnTo, [
  'users_saved' => '1',
]);
