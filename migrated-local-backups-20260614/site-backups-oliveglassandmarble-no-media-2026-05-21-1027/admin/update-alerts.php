<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

adminSendNoIndexHeaders();
adminRequireLogin();

function adminRedirectAlertSettings($returnTo, $params = []) {
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

$settings = ogmNormalizePushoverSettings([
  'enabled' => !empty($_POST['pushover_enabled']),
  'token' => trim((string) ($_POST['pushover_token'] ?? '')),
  'user' => trim((string) ($_POST['pushover_user'] ?? '')),
  'device' => trim((string) ($_POST['pushover_device'] ?? '')),
  'priority' => trim((string) ($_POST['pushover_priority'] ?? '0')),
  'sound' => trim((string) ($_POST['pushover_sound'] ?? '')),
]);

$hasAnyCredentialField = $settings['token'] !== '' || $settings['user'] !== '' || $settings['device'] !== '' || $settings['sound'] !== '';

if ($settings['enabled'] && !ogmPushoverHasCredentials($settings)) {
  adminRedirectAlertSettings($returnTo, [
    'alerts_error' => 'Enter both the Pushover app token and the user or group key before enabling alerts.',
  ]);
}

if ($hasAnyCredentialField && !ogmPushoverHasCredentials($settings)) {
  adminRedirectAlertSettings($returnTo, [
    'alerts_error' => 'Save both the app token and the user/group key together, or clear both fields.',
  ]);
}

$validation = ogmValidatePushoverSettings($settings);
if (!$validation['success']) {
  adminRedirectAlertSettings($returnTo, [
    'alerts_error' => trim((string) ($validation['message'] ?? 'Pushover validation failed.')),
  ]);
}

if (!ogmSavePushoverSettings($settings)) {
  adminRedirectAlertSettings($returnTo, [
    'alerts_error' => 'Could not save the Pushover settings.',
  ]);
}

if (!empty($_POST['send_test'])) {
  $currentUser = adminCurrentUser();
  $requestedBy = trim((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? ''));
  $testResult = ogmSendPushoverTest($settings, $requestedBy);
  if (!$testResult['sent']) {
    adminRedirectAlertSettings($returnTo, [
      'alerts_error' => trim((string) ($testResult['message'] ?? 'Could not send the test push.')),
    ]);
  }

  adminRedirectAlertSettings($returnTo, [
    'alerts_saved' => '1',
    'alerts_test' => '1',
  ]);
}

adminRedirectAlertSettings($returnTo, [
  'alerts_saved' => '1',
]);
