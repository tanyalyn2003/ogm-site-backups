<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

adminSendNoIndexHeaders();
adminRequireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: index.php');
  exit;
}

if (!adminVerifyCsrfToken($_POST['csrf_token'] ?? '')) {
  header('Location: index.php?meta_error=' . rawurlencode('Your session expired. Please try again.'));
  exit;
}

$returnTo = trim((string) ($_POST['return_to'] ?? 'index.php'));
if (!in_array($returnTo, ['index.php', 'analytics.php'], true)) {
  $returnTo = 'index.php';
}

$settings = ogmNormalizeMetaSettings([
  'enabled' => !empty($_POST['meta_enabled']),
  'verify_token' => trim((string) ($_POST['meta_verify_token'] ?? '')),
  'app_secret' => trim((string) ($_POST['meta_app_secret'] ?? '')),
  'facebook_page_id' => trim((string) ($_POST['meta_facebook_page_id'] ?? '')),
  'instagram_account_id' => trim((string) ($_POST['meta_instagram_account_id'] ?? '')),
  'social_push_enabled' => !empty($_POST['meta_social_push_enabled']),
]);

if (!empty($settings['enabled']) && ($settings['verify_token'] === '' || $settings['app_secret'] === '')) {
  header('Location: ' . $returnTo . '?meta_error=' . rawurlencode('Enter both the verify token and app secret before enabling Meta messages.'));
  exit;
}

if (!ogmSaveMetaSettings($settings)) {
  header('Location: ' . $returnTo . '?meta_error=' . rawurlencode('Could not save the Meta settings.'));
  exit;
}

header('Location: ' . $returnTo . '?meta_saved=1');
exit;
