<?php

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lead-storage.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'pushover.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'meta-messaging.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'auth.php';

function adminConfig() {
  static $config = null;

  if ($config === null) {
    $loaded = require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    $config = is_array($loaded) ? $loaded : [];
  }

  return $config;
}

function adminIsHttpsRequest() {
  if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
    return true;
  }

  return ((int) ($_SERVER['SERVER_PORT'] ?? 0)) === 443;
}

function adminSendNoIndexHeaders() {
  header('X-Robots-Tag: noindex, nofollow', true);
  header('Cache-Control: private, no-store, max-age=0', true);
}

function adminTimezone() {
  static $timezone = null;

  if ($timezone === null) {
    $config = adminConfig();
    $timezoneName = trim((string) ($config['timezone'] ?? 'America/New_York')) ?: 'America/New_York';
    $timezone = new DateTimeZone($timezoneName);
  }

  return $timezone;
}

function adminNow() {
  return new DateTimeImmutable('now', adminTimezone());
}

function adminWebBase() {
  static $base = null;

  if ($base !== null) {
    return $base;
  }

  $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
  if (preg_match('#/website-leads\.php$#', $script)) {
    $base = rtrim(dirname($script), '/') . '/website-leads/';
  } else {
    $base = rtrim(dirname($script), '/') . '/';
  }

  if ($base !== '/' && !str_ends_with($base, '/')) {
    $base .= '/';
  }

  return $base;
}

function adminUrl($file = 'index.php') {
  $file = ltrim((string) $file, '/');
  return adminWebBase() . $file;
}

function adminQuoterLoginUrl($next = '') {
  $base = rtrim(str_replace('\\', '/', dirname(adminWebBase())), '/');
  $login = ($base === '' ? '' : $base) . '/index.php';
  $next = trim((string) $next);
  if ($next === '') {
    return $login;
  }

  return $login . '?next=' . rawurlencode($next);
}

function adminStartSession() {
  qtStartSession();
}

function adminBootstrapFromQuoter() {
  qtStartSession();

  if (!qtIsLoggedIn()) {
    return false;
  }

  if (!empty($_SESSION['ogm_admin_user']) && is_array($_SESSION['ogm_admin_user'])) {
    return true;
  }

  $displayName = qtUsername();
  if ($displayName === '') {
    $displayName = 'Staff';
  }

  $username = adminNormalizeUsername($displayName);
  if ($username === '') {
    $username = 'staff';
  }

  $_SESSION['ogm_admin_user'] = [
    'username' => $username,
    'display_name' => $displayName,
  ];

  if (empty($_SESSION['ogm_admin_csrf'])) {
    $_SESSION['ogm_admin_csrf'] = bin2hex(random_bytes(24));
  }

  return true;
}

function adminCurrentUser() {
  adminStartSession();

  if (empty($_SESSION['ogm_admin_user']) || !is_array($_SESSION['ogm_admin_user'])) {
    return null;
  }

  return $_SESSION['ogm_admin_user'];
}

function adminIsLoggedIn() {
  if (adminCurrentUser() !== null) {
    return true;
  }

  return adminBootstrapFromQuoter();
}

function adminVerifyPassword($password, $saltHex, $iterations, $expectedHex) {
  if ($password === '' || $saltHex === '' || $expectedHex === '') {
    return false;
  }

  $salt = @hex2bin((string) $saltHex);
  if ($salt === false) {
    return false;
  }

  $computed = hash_pbkdf2('sha256', $password, $salt, max(1, (int) $iterations), 32, true);
  return hash_equals(strtolower((string) $expectedHex), strtolower(bin2hex($computed)));
}

function adminAttemptLogin($username, $password) {
  $username = adminNormalizeUsername($username);
  $users = adminReadUsers();
  $user = is_array($users[$username] ?? null) ? $users[$username] : null;

  if (!$user) {
    return false;
  }

  $valid = adminVerifyPassword(
    (string) $password,
    (string) ($user['salt_hex'] ?? ''),
    (int) ($user['iterations'] ?? 0),
    (string) ($user['hash_hex'] ?? '')
  );

  if (!$valid) {
    return false;
  }

  adminStartSession();
  session_regenerate_id(true);
  $_SESSION['ogm_admin_user'] = [
    'username' => $username,
    'display_name' => trim((string) ($user['display_name'] ?? $username)),
  ];

  if (empty($_SESSION['ogm_admin_csrf'])) {
    $_SESSION['ogm_admin_csrf'] = bin2hex(random_bytes(24));
  }

  return true;
}

function adminLogout() {
  adminStartSession();
  unset($_SESSION['ogm_admin_user'], $_SESSION['ogm_admin_csrf']);
}

function adminRequireLogin() {
  adminSendNoIndexHeaders();
  adminStartSession();

  if (adminIsLoggedIn()) {
    return;
  }

  $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
  $next = qtSafeNextPath($uri);
  if ($next === '') {
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#/website-leads\.php$#', $script)) {
      $next = $script;
    } else {
      $next = adminUrl('index.php');
    }
  }

  header('Location: ' . adminQuoterLoginUrl($next), true, 303);
  exit;
}

function adminCsrfToken() {
  adminStartSession();

  if (empty($_SESSION['ogm_admin_csrf'])) {
    $_SESSION['ogm_admin_csrf'] = bin2hex(random_bytes(24));
  }

  return (string) $_SESSION['ogm_admin_csrf'];
}

function adminVerifyCsrfToken($token) {
  adminStartSession();

  if (empty($_SESSION['ogm_admin_csrf']) || !is_string($_SESSION['ogm_admin_csrf'])) {
    return false;
  }

  return hash_equals($_SESSION['ogm_admin_csrf'], (string) $token);
}

function adminStatusOptions() {
  return [
    'partial' => 'Partial',
    'new' => 'New',
    'contacted' => 'Contacted',
    'quoted' => 'Quoted',
    'won' => 'Won',
    'closed' => 'Closed',
  ];
}

function adminStatusRank($status) {
  $order = [
    'new' => 1,
    'partial' => 2,
    'contacted' => 3,
    'quoted' => 4,
    'won' => 5,
    'closed' => 6,
  ];

  return (int) ($order[$status] ?? 99);
}

function adminUsersFile() {
  return ogmLeadStorageDir() . DIRECTORY_SEPARATOR . 'admin-users.json';
}

function adminNormalizeUsername($username) {
  $username = strtolower(trim((string) $username));
  $username = preg_replace('/[^a-z0-9._-]/', '', $username);
  return substr((string) $username, 0, 48);
}

function adminNormalizeUserRecords($users) {
  $normalized = [];

  foreach ((array) $users as $key => $user) {
    if (!is_array($user)) {
      continue;
    }

    $username = is_string($key) ? $key : ($user['username'] ?? '');
    $username = adminNormalizeUsername($username);
    if ($username === '') {
      continue;
    }

    $saltHex = strtolower(trim((string) ($user['salt_hex'] ?? '')));
    $hashHex = strtolower(trim((string) ($user['hash_hex'] ?? '')));
    if ($saltHex === '' || $hashHex === '') {
      continue;
    }

    $displayName = trim((string) ($user['display_name'] ?? ''));
    if ($displayName === '') {
      $displayName = $username;
    }

    $normalized[$username] = [
      'display_name' => $displayName,
      'salt_hex' => $saltHex,
      'iterations' => max(1, (int) ($user['iterations'] ?? 120000)),
      'hash_hex' => $hashHex,
    ];
  }

  return $normalized;
}

function adminDefaultUsers() {
  $config = adminConfig();
  return adminNormalizeUserRecords((array) ($config['users'] ?? []));
}

function adminProtectedUsernames() {
  $config = adminConfig();
  $protected = [];

  foreach ((array) ($config['protected_users'] ?? ['sales']) as $username) {
    $username = adminNormalizeUsername($username);
    if ($username !== '') {
      $protected[$username] = true;
    }
  }

  if (!$protected) {
    $protected['sales'] = true;
  }

  return array_keys($protected);
}

function adminReadUsers() {
  $stored = ogmReadJsonFile(adminUsersFile(), []);
  if (is_array($stored) && isset($stored['users']) && is_array($stored['users'])) {
    $stored = $stored['users'];
  }

  $users = adminNormalizeUserRecords($stored);
  if (!$users) {
    $users = adminDefaultUsers();
  }

  return $users;
}

function adminSaveUsers($users) {
  return ogmWriteJsonFile(adminUsersFile(), [
    'users' => adminNormalizeUserRecords($users),
    'updated_at' => gmdate('c'),
  ]);
}

function adminHashPassword($password) {
  $password = (string) $password;
  $salt = random_bytes(16);
  $iterations = 120000;
  $hash = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);

  return [
    'salt_hex' => bin2hex($salt),
    'iterations' => $iterations,
    'hash_hex' => bin2hex($hash),
  ];
}

function adminRepListFile() {
  return ogmLeadStorageDir() . DIRECTORY_SEPARATOR . 'sales-reps.json';
}

function adminNormalizeOwnerList($owners) {
  $normalized = [];
  $seen = [];

  foreach ((array) $owners as $owner) {
    $owner = trim((string) $owner);
    if ($owner === '') {
      continue;
    }

    $key = strtolower($owner);
    if (isset($seen[$key])) {
      continue;
    }

    $seen[$key] = true;
    $normalized[] = $owner;
  }

  return $normalized;
}

function adminDefaultOwnerOptions() {
  $config = adminConfig();
  return adminNormalizeOwnerList((array) ($config['owners'] ?? []));
}

function adminReadRepList() {
  $repListFile = adminRepListFile();
  $hasStoredFile = is_file($repListFile);
  $stored = ogmReadJsonFile($repListFile, []);

  if (is_array($stored) && isset($stored['owners']) && is_array($stored['owners'])) {
    $stored = $stored['owners'];
  }

  $owners = adminNormalizeOwnerList($stored);
  if (!$owners && !$hasStoredFile) {
    $owners = adminDefaultOwnerOptions();
  }

  return $owners;
}

function adminSaveRepList($owners) {
  return ogmWriteJsonFile(adminRepListFile(), [
    'owners' => adminNormalizeOwnerList($owners),
    'updated_at' => gmdate('c'),
  ]);
}

function adminOwnerOptions($currentOwner = '') {
  $owners = adminReadRepList();

  $currentOwner = trim((string) $currentOwner);
  if ($currentOwner !== '') {
    $owners[] = $currentOwner;
  }

  return adminNormalizeOwnerList($owners);
}

function adminReadDashboardState() {
  $state = ogmReadJsonFile(ogmLeadDashboardStateFile(), []);
  return is_array($state) ? $state : [];
}

function adminSaveDashboardState($state) {
  return ogmWriteJsonFile(ogmLeadDashboardStateFile(), is_array($state) ? $state : []);
}

function adminReportLogFile() {
  return ogmLeadStorageDir() . DIRECTORY_SEPARATOR . 'daily-report-log.json';
}

function adminGetLeadState($state, $leadKey) {
  $state = is_array($state) ? $state : [];
  $leadKey = trim((string) $leadKey);
  $leadState = $leadKey !== '' && isset($state[$leadKey]) && is_array($state[$leadKey])
    ? $state[$leadKey]
    : [];

  return is_array($leadState) ? $leadState : [];
}

function adminWriteLeadState($state, $leadKey, $updates) {
  $state = is_array($state) ? $state : [];
  $leadKey = trim((string) $leadKey);
  if ($leadKey === '') {
    return $state;
  }

  $existing = adminGetLeadState($state, $leadKey);
  $state[$leadKey] = array_merge($existing, is_array($updates) ? $updates : []);
  return $state;
}

function adminLeadIsTrashedFromState($leadState) {
  return !empty($leadState['trashed']);
}

function adminNormalizeLeadKeys($leadKeys) {
  $normalized = [];
  $seen = [];

  foreach ((array) $leadKeys as $leadKey) {
    $leadKey = trim((string) $leadKey);
    if ($leadKey === '' || isset($seen[$leadKey])) {
      continue;
    }

    $seen[$leadKey] = true;
    $normalized[] = $leadKey;
  }

  return $normalized;
}

function adminDeleteLeadEventsInFile($path, $leadKeys) {
  $leadKeys = adminNormalizeLeadKeys($leadKeys);
  if (!$leadKeys) {
    return true;
  }

  $deleteLookup = array_fill_keys($leadKeys, true);
  $remainingEntries = [];

  foreach (ogmReadNdjson($path) as $entry) {
    $entryLeadKey = trim((string) ($entry['lead_key'] ?? ''));
    if ($entryLeadKey === '') {
      $entryLeadKey = ogmBuildLeadKey($entry);
    }

    if ($entryLeadKey !== '' && isset($deleteLookup[$entryLeadKey])) {
      continue;
    }

    $remainingEntries[] = $entry;
  }

  return ogmWriteNdjson($path, $remainingEntries);
}

function adminPermanentlyDeleteLeadKeys($leadKeys) {
  $leadKeys = adminNormalizeLeadKeys($leadKeys);
  if (!$leadKeys) {
    return 0;
  }

  if (!adminDeleteLeadEventsInFile(ogmPartialLeadLogFile(), $leadKeys)) {
    return false;
  }

  if (!adminDeleteLeadEventsInFile(ogmFullLeadLogFile(), $leadKeys)) {
    return false;
  }

  if (!adminDeleteLeadEventsInFile(ogmSocialMessageLogFile(), $leadKeys)) {
    return false;
  }

  $state = adminReadDashboardState();
  foreach ($leadKeys as $leadKey) {
    unset($state[$leadKey]);
  }

  if (!adminSaveDashboardState($state)) {
    return false;
  }

  return count($leadKeys);
}

function adminReadReportLog() {
  $log = ogmReadJsonFile(adminReportLogFile(), []);
  return is_array($log) ? $log : [];
}

function adminSaveReportLog($log) {
  return ogmWriteJsonFile(adminReportLogFile(), is_array($log) ? $log : []);
}

function adminApplyOwnerMigrations($state, $renameMap, $deletedOwners, $updatedBy = '') {
  $state = is_array($state) ? $state : [];
  $renameMap = is_array($renameMap) ? $renameMap : [];
  $deletedLookup = [];
  foreach ((array) $deletedOwners as $owner) {
    $owner = strtolower(trim((string) $owner));
    if ($owner !== '') {
      $deletedLookup[$owner] = true;
    }
  }

  $updatedBy = trim((string) $updatedBy);
  $timestamp = gmdate('c');

  foreach ($state as $leadKey => &$leadState) {
    if (!is_array($leadState)) {
      continue;
    }

    $currentOwner = trim((string) ($leadState['owner'] ?? ''));
    if ($currentOwner === '') {
      continue;
    }

    $ownerKey = strtolower($currentOwner);
    $newOwner = $currentOwner;

    if (isset($renameMap[$ownerKey])) {
      $newOwner = trim((string) $renameMap[$ownerKey]);
    } elseif (isset($deletedLookup[$ownerKey])) {
      $newOwner = '';
    }

    if ($newOwner === $currentOwner) {
      continue;
    }

    $leadState['owner'] = $newOwner;
    $leadState['updated_at'] = $timestamp;
    if ($updatedBy !== '') {
      $leadState['updated_by'] = $updatedBy;
    }
  }
  unset($leadState);

  return $state;
}

function adminNormalizeAttachmentNames($attachmentNames) {
  $normalized = [];
  foreach ((array) $attachmentNames as $name) {
    $name = trim((string) $name);
    if ($name !== '') {
      $normalized[] = $name;
    }
  }

  return $normalized;
}

function adminNormalizeLeadEvent($entry, $type) {
  $timestamp = trim((string) ($entry['timestamp'] ?? ''));

  return [
    'entry_type' => $type,
    'lead_key' => trim((string) ($entry['lead_key'] ?? '')) ?: ogmBuildLeadKey($entry),
    'timestamp' => $timestamp,
    'timestamp_unix' => ($timestamp !== '' && strtotime($timestamp) !== false) ? strtotime($timestamp) : 0,
    'source' => trim((string) ($entry['source'] ?? '')),
    'channel' => trim((string) ($entry['channel'] ?? '')),
    'customer_id' => trim((string) ($entry['customer_id'] ?? '')),
    'message_id' => trim((string) ($entry['message_id'] ?? '')),
    'name' => trim((string) ($entry['name'] ?? '')),
    'email' => trim((string) ($entry['email'] ?? '')),
    'phone' => trim((string) ($entry['phone'] ?? '')),
    'project_type' => trim((string) ($entry['project_type'] ?? '')),
    'city' => trim((string) ($entry['city'] ?? '')),
    'space_type' => trim((string) ($entry['space_type'] ?? '')),
    'material_interest' => trim((string) ($entry['material_interest'] ?? '')),
    'build_type' => trim((string) ($entry['build_type'] ?? '')),
    'timeline' => trim((string) ($entry['timeline'] ?? '')),
    'chat_summary' => trim((string) ($entry['chat_summary'] ?? '')),
    'chat_transcript' => trim((string) ($entry['chat_transcript'] ?? '')),
    'customer_type' => trim((string) ($entry['customer_type'] ?? '')),
    'measurements' => trim((string) ($entry['measurements'] ?? '')),
    'tile_complete' => trim((string) ($entry['tile_complete'] ?? '')),
    'project_scope' => trim((string) ($entry['project_scope'] ?? '')),
    'plans_ready' => trim((string) ($entry['plans_ready'] ?? '')),
    'pricing_or_scheduling' => trim((string) ($entry['pricing_or_scheduling'] ?? '')),
    'home_or_commercial' => trim((string) ($entry['home_or_commercial'] ?? '')),
    'question' => trim((string) ($entry['question'] ?? '')),
    'page_url' => trim((string) ($entry['page_url'] ?? '')),
    'message' => trim((string) ($entry['message'] ?? '')),
    'mail_status' => trim((string) ($entry['mail_status'] ?? '')),
    'attachment_names' => adminNormalizeAttachmentNames($entry['attachment_names'] ?? []),
  ];
}

function adminReadLeadEvents() {
  $events = [];

  foreach (ogmReadNdjson(ogmPartialLeadLogFile()) as $entry) {
    $events[] = adminNormalizeLeadEvent($entry, 'partial');
  }

  foreach (ogmReadNdjson(ogmFullLeadLogFile()) as $entry) {
    $events[] = adminNormalizeLeadEvent($entry, 'full');
  }

  foreach (ogmReadNdjson(ogmSocialMessageLogFile()) as $entry) {
    $events[] = adminNormalizeLeadEvent($entry, 'social');
  }

  usort($events, function ($left, $right) {
    if ($left['timestamp_unix'] === $right['timestamp_unix']) {
      return strcmp($left['lead_key'], $right['lead_key']);
    }

    return $left['timestamp_unix'] <=> $right['timestamp_unix'];
  });

  return $events;
}

function adminMergeLeadField($currentValue, $incomingValue) {
  $incomingValue = trim((string) $incomingValue);
  return $incomingValue !== '' ? $incomingValue : $currentValue;
}

function adminMergeTranscriptField($currentValue, $incomingValue) {
  $currentValue = trim((string) $currentValue);
  $incomingValue = trim((string) $incomingValue);

  if ($incomingValue === '') {
    return $currentValue;
  }

  if ($currentValue === '' || strlen($incomingValue) >= strlen($currentValue)) {
    return $incomingValue;
  }

  return $currentValue;
}

function adminBuildLeads() {
  $events = adminReadLeadEvents();
  $state = adminReadDashboardState();
  $leads = [];

  foreach ($events as $event) {
    $leadKey = $event['lead_key'];
    if ($leadKey === '') {
      continue;
    }

    if (!isset($leads[$leadKey])) {
      $leads[$leadKey] = [
        'lead_key' => $leadKey,
        'channel' => '',
        'customer_id' => '',
        'message_id' => '',
        'name' => '',
        'email' => '',
        'phone' => '',
        'project_type' => '',
        'city' => '',
        'space_type' => '',
        'material_interest' => '',
        'build_type' => '',
        'timeline' => '',
        'customer_type' => '',
        'measurements' => '',
        'tile_complete' => '',
        'project_scope' => '',
        'plans_ready' => '',
        'pricing_or_scheduling' => '',
        'home_or_commercial' => '',
        'question' => '',
        'chat_summary' => '',
        'chat_transcript' => '',
        'message' => '',
        'source' => '',
        'page_url' => '',
        'mail_status' => '',
        'attachment_names' => [],
        'is_social_message' => false,
        'has_full' => false,
        'has_partial' => false,
        'full_count' => 0,
        'partial_count' => 0,
        'first_seen' => $event['timestamp'],
        'last_seen' => $event['timestamp'],
        'first_seen_unix' => $event['timestamp_unix'],
        'last_seen_unix' => $event['timestamp_unix'],
        'entries' => [],
      ];
    }

    $lead = &$leads[$leadKey];
    $lead['entries'][] = $event;
    $lead['name'] = adminMergeLeadField($lead['name'], $event['name']);
    $lead['channel'] = adminMergeLeadField($lead['channel'], $event['channel']);
    $lead['customer_id'] = adminMergeLeadField($lead['customer_id'], $event['customer_id']);
    $lead['message_id'] = adminMergeLeadField($lead['message_id'], $event['message_id']);
    $lead['email'] = adminMergeLeadField($lead['email'], $event['email']);
    $lead['phone'] = adminMergeLeadField($lead['phone'], $event['phone']);
    $lead['project_type'] = adminMergeLeadField($lead['project_type'], $event['project_type']);
    $lead['city'] = adminMergeLeadField($lead['city'], $event['city']);
    $lead['space_type'] = adminMergeLeadField($lead['space_type'], $event['space_type']);
    $lead['material_interest'] = adminMergeLeadField($lead['material_interest'], $event['material_interest']);
    $lead['build_type'] = adminMergeLeadField($lead['build_type'], $event['build_type']);
    $lead['timeline'] = adminMergeLeadField($lead['timeline'], $event['timeline']);
    $lead['customer_type'] = adminMergeLeadField($lead['customer_type'], $event['customer_type']);
    $lead['measurements'] = adminMergeLeadField($lead['measurements'], $event['measurements']);
    $lead['tile_complete'] = adminMergeLeadField($lead['tile_complete'], $event['tile_complete']);
    $lead['project_scope'] = adminMergeLeadField($lead['project_scope'], $event['project_scope']);
    $lead['plans_ready'] = adminMergeLeadField($lead['plans_ready'], $event['plans_ready']);
    $lead['pricing_or_scheduling'] = adminMergeLeadField($lead['pricing_or_scheduling'], $event['pricing_or_scheduling']);
    $lead['home_or_commercial'] = adminMergeLeadField($lead['home_or_commercial'], $event['home_or_commercial']);
    $lead['question'] = adminMergeLeadField($lead['question'], $event['question']);
    $lead['chat_summary'] = adminMergeLeadField($lead['chat_summary'], $event['chat_summary']);
    $lead['chat_transcript'] = adminMergeTranscriptField($lead['chat_transcript'], $event['chat_transcript']);
    $lead['message'] = adminMergeLeadField($lead['message'], $event['message']);
    $lead['source'] = adminMergeLeadField($lead['source'], $event['source']);
    $lead['page_url'] = adminMergeLeadField($lead['page_url'], $event['page_url']);
    $lead['mail_status'] = adminMergeLeadField($lead['mail_status'], $event['mail_status']);

    if (!empty($event['attachment_names'])) {
      $lead['attachment_names'] = array_values(array_unique(array_merge($lead['attachment_names'], $event['attachment_names'])));
    }

    if ($event['entry_type'] === 'full') {
      $lead['has_full'] = true;
      $lead['full_count'] += 1;
    } elseif ($event['entry_type'] === 'partial') {
      $lead['has_partial'] = true;
      $lead['partial_count'] += 1;
    } else {
      $lead['is_social_message'] = true;
    }

    if ($event['timestamp_unix'] > $lead['last_seen_unix']) {
      $lead['last_seen'] = $event['timestamp'];
      $lead['last_seen_unix'] = $event['timestamp_unix'];
    }

    if (($lead['first_seen_unix'] === 0 && $event['timestamp_unix'] > 0) || ($event['timestamp_unix'] > 0 && $event['timestamp_unix'] < $lead['first_seen_unix'])) {
      $lead['first_seen'] = $event['timestamp'];
      $lead['first_seen_unix'] = $event['timestamp_unix'];
    }

    unset($lead);
  }

  $statusOptions = adminStatusOptions();
  foreach ($leads as $leadKey => &$lead) {
    usort($lead['entries'], function ($left, $right) {
      return $right['timestamp_unix'] <=> $left['timestamp_unix'];
    });

    $savedState = is_array($state[$leadKey] ?? null) ? $state[$leadKey] : [];
    $defaultStatus = $lead['has_full'] ? 'new' : ($lead['is_social_message'] ? 'new' : 'partial');
    $status = trim((string) ($savedState['status'] ?? ''));
    if (!isset($statusOptions[$status])) {
      $status = $defaultStatus;
    }

    $lead['status'] = $status;
    $lead['owner'] = trim((string) ($savedState['owner'] ?? ''));
    $lead['notes'] = trim((string) ($savedState['notes'] ?? ''));
    $lead['updated_at'] = trim((string) ($savedState['updated_at'] ?? ''));
    $lead['updated_by'] = trim((string) ($savedState['updated_by'] ?? ''));
    $lead['is_trashed'] = adminLeadIsTrashedFromState($savedState);
    $lead['trashed_at'] = trim((string) ($savedState['trashed_at'] ?? ''));
    $lead['trashed_by'] = trim((string) ($savedState['trashed_by'] ?? ''));
    if ($lead['is_social_message']) {
      if ($lead['name'] === '') {
        $lead['name'] = ogmMetaDisplayName((string) ($lead['channel'] ?? 'facebook'), (string) ($lead['customer_id'] ?? ''));
      }

      $lead['primary_contact'] = ucfirst((string) ($lead['channel'] ?: 'social')) . ' DM';
      $lead['summary'] = $lead['message'] !== '' ? $lead['message'] : 'Incoming message';

      $transcriptLines = [];
      $chronologicalEntries = $lead['entries'];
      usort($chronologicalEntries, function ($left, $right) {
        return ($left['timestamp_unix'] ?? 0) <=> ($right['timestamp_unix'] ?? 0);
      });
      foreach ($chronologicalEntries as $entry) {
        if (($entry['entry_type'] ?? '') !== 'social') {
          continue;
        }

        $lineText = trim((string) ($entry['message'] ?? ''));
        if ($lineText === '' && !empty($entry['attachment_names'])) {
          $lineText = implode(', ', (array) ($entry['attachment_names'] ?? []));
        }
        if ($lineText === '') {
          continue;
        }

        $transcriptLines[] = '[' . adminFormatTimestamp((string) ($entry['timestamp'] ?? '')) . '] ' . $lineText;
      }

      if ($transcriptLines) {
        $lead['chat_transcript'] = implode("\n\n", $transcriptLines);
      }

      if ($lead['page_url'] === '') {
        $lead['page_url'] = 'https://business.facebook.com/latest/inbox';
      }
    } else {
      $lead['primary_contact'] = $lead['phone'] !== '' ? $lead['phone'] : ($lead['email'] !== '' ? $lead['email'] : 'No contact');
      $lead['summary'] = $lead['message'] !== '' ? $lead['message'] : ($lead['chat_summary'] !== '' ? $lead['chat_summary'] : $lead['question']);
    }

    $lead['search_blob'] = strtolower(implode(' ', [
      $lead['name'],
      $lead['email'],
      $lead['phone'],
      $lead['channel'],
      $lead['customer_id'],
      $lead['project_type'],
      $lead['city'],
      $lead['space_type'],
      $lead['material_interest'],
      $lead['summary'],
      $lead['owner'],
      $lead['notes'],
      $lead['primary_contact'],
      $lead['status'],
    ]));
  }
  unset($lead);

  $leadList = array_values($leads);
  usort($leadList, function ($left, $right) {
    $statusDiff = adminStatusRank($left['status']) <=> adminStatusRank($right['status']);
    if ($statusDiff !== 0) {
      return $statusDiff;
    }

    return $right['last_seen_unix'] <=> $left['last_seen_unix'];
  });

  return $leadList;
}

function adminFilterLeads($leads, $filters) {
  $status = trim((string) ($filters['status'] ?? ''));
  $owner = trim((string) ($filters['owner'] ?? ''));
  $query = strtolower(trim((string) ($filters['q'] ?? '')));
  $view = trim((string) ($filters['view'] ?? 'active'));

  return array_values(array_filter((array) $leads, function ($lead) use ($status, $owner, $query, $view) {
    $isTrashed = !empty($lead['is_trashed']);
    if ($view === 'trash') {
      if (!$isTrashed) {
        return false;
      }
    } elseif ($isTrashed) {
      return false;
    }

    if ($status !== '' && ($lead['status'] ?? '') !== $status) {
      return false;
    }

    if ($owner !== '' && ($lead['owner'] ?? '') !== $owner) {
      return false;
    }

    if ($query !== '' && strpos((string) ($lead['search_blob'] ?? ''), $query) === false) {
      return false;
    }

    return true;
  }));
}

function adminBuildLeadSummary($leads) {
  $summary = [
    'total' => 0,
    'full' => 0,
    'partial_only' => 0,
    'statuses' => [],
    'assigned' => 0,
    'unassigned' => 0,
    'contacted' => 0,
    'quoted' => 0,
    'won' => 0,
  ];

  foreach (array_keys(adminStatusOptions()) as $status) {
    $summary['statuses'][$status] = 0;
  }

  foreach ((array) $leads as $lead) {
    if (!empty($lead['is_social_message'])) {
      continue;
    }

    $summary['total'] += 1;

    if (!empty($lead['has_full'])) {
      $summary['full'] += 1;
    } else {
      $summary['partial_only'] += 1;
    }

    if (trim((string) ($lead['owner'] ?? '')) === '') {
      $summary['unassigned'] += 1;
    } else {
      $summary['assigned'] += 1;
    }

    $status = (string) ($lead['status'] ?? '');
    if (isset($summary['statuses'][$status])) {
      $summary['statuses'][$status] += 1;
    }

    if ($status === 'contacted') {
      $summary['contacted'] += 1;
    } elseif ($status === 'quoted') {
      $summary['quoted'] += 1;
    } elseif ($status === 'won') {
      $summary['won'] += 1;
    }
  }

  return $summary;
}

function adminReadLeadHistoryRecords() {
  $history = ogmReadLeadHistory();
  $records = [];

  foreach ((array) $history as $leadKey => $record) {
    if (!is_array($record)) {
      continue;
    }

    $firstSeen = trim((string) ($record['first_seen'] ?? ''));
    $firstSeenUnix = ($firstSeen !== '' && strtotime($firstSeen) !== false) ? strtotime($firstSeen) : 0;
    if ($firstSeenUnix <= 0) {
      continue;
    }

    $normalizedLeadKey = trim((string) ($record['lead_key'] ?? $leadKey));
    if ($normalizedLeadKey === '') {
      $normalizedLeadKey = trim((string) $leadKey);
    }

    $records[] = [
      'lead_key' => $normalizedLeadKey,
      'first_seen' => $firstSeen,
      'first_seen_unix' => $firstSeenUnix,
      'last_seen' => trim((string) ($record['last_seen'] ?? '')),
      'has_full' => !empty($record['has_full']),
      'has_partial' => !empty($record['has_partial']),
      'full_events' => (int) ($record['full_events'] ?? 0),
      'partial_events' => (int) ($record['partial_events'] ?? 0),
    ];
  }

  usort($records, function ($left, $right) {
    return ($right['first_seen_unix'] ?? 0) <=> ($left['first_seen_unix'] ?? 0);
  });

  return $records;
}

function adminBuildLeadHistoryCounts($records, DateTimeImmutable $now = null) {
  $now = $now instanceof DateTimeImmutable ? $now->setTimezone(adminTimezone()) : adminNow();
  $todayStart = $now->setTime(0, 0, 0);
  $weekStart = $now->modify('monday this week')->setTime(0, 0, 0);
  $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
  $yearStart = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0);

  $counts = [
    'all_time' => 0,
    'today' => 0,
    'week' => 0,
    'month' => 0,
    'year' => 0,
    'full' => 0,
    'partial_only' => 0,
  ];

  foreach ((array) $records as $record) {
    $firstSeenUnix = (int) ($record['first_seen_unix'] ?? 0);
    if ($firstSeenUnix <= 0) {
      continue;
    }

    $counts['all_time'] += 1;
    if (!empty($record['has_full'])) {
      $counts['full'] += 1;
    } else {
      $counts['partial_only'] += 1;
    }

    if ($firstSeenUnix >= $todayStart->getTimestamp()) {
      $counts['today'] += 1;
    }

    if ($firstSeenUnix >= $weekStart->getTimestamp()) {
      $counts['week'] += 1;
    }

    if ($firstSeenUnix >= $monthStart->getTimestamp()) {
      $counts['month'] += 1;
    }

    if ($firstSeenUnix >= $yearStart->getTimestamp()) {
      $counts['year'] += 1;
    }
  }

  return $counts;
}

function adminNormalizeTrafficEvent($entry) {
  $timestamp = trim((string) ($entry['timestamp'] ?? ''));
  $path = trim((string) ($entry['path'] ?? ''));
  $title = trim((string) ($entry['title'] ?? ''));

  if ($path === '') {
    $parsedUrl = parse_url((string) ($entry['url'] ?? ''));
    if ($parsedUrl !== false) {
      $path = trim((string) ($parsedUrl['path'] ?? ''));
      $query = trim((string) ($parsedUrl['query'] ?? ''));
      if ($query !== '') {
        $path .= '?' . $query;
      }
    }
  }

  if ($path === '') {
    $path = '/';
  }

  if ($path[0] !== '/') {
    $path = '/' . ltrim($path, '/');
  }

  return [
    'timestamp' => $timestamp,
    'timestamp_unix' => ($timestamp !== '' && strtotime($timestamp) !== false) ? strtotime($timestamp) : 0,
    'event_type' => trim((string) ($entry['event_type'] ?? '')) ?: 'pageview',
    'event_name' => trim((string) ($entry['event_name'] ?? '')),
    'page_id' => trim((string) ($entry['page_id'] ?? '')),
    'path' => $path,
    'title' => $title,
    'visitor_key' => trim((string) ($entry['visitor_key'] ?? '')),
    'referrer_host' => strtolower(trim((string) ($entry['referrer_host'] ?? ''))),
    'referrer_path' => trim((string) ($entry['referrer_path'] ?? '')),
    'engaged_ms' => max(0, (int) ($entry['engaged_ms'] ?? 0)),
    'target_host' => strtolower(trim((string) ($entry['target_host'] ?? ''))),
    'target_path' => trim((string) ($entry['target_path'] ?? '')),
    'target_label' => trim((string) ($entry['target_label'] ?? '')),
  ];
}

function adminReadTrafficEvents() {
  $events = [];

  foreach (ogmReadNdjson(ogmTrafficLogFile()) as $entry) {
    $event = adminNormalizeTrafficEvent($entry);
    if (($event['timestamp_unix'] ?? 0) > 0) {
      $events[] = $event;
    }
  }

  usort($events, function ($left, $right) {
    return ($right['timestamp_unix'] ?? 0) <=> ($left['timestamp_unix'] ?? 0);
  });

  return $events;
}

function adminTrafficPathLabel($path, $title = '') {
  $path = trim((string) $path);
  $title = trim((string) $title);

  if ($path === '/' || $path === '/index.html') {
    return 'Home';
  }

  if ($title !== '') {
    return $title;
  }

  return ltrim($path, '/');
}

function adminTrafficSourceLabel($event) {
  $host = strtolower(trim((string) ($event['referrer_host'] ?? '')));

  if ($host === '') {
    return 'Direct';
  }

  if ($host === 'oliveglassandmarble.com' || $host === 'www.oliveglassandmarble.com') {
    return 'Internal';
  }

  return $host;
}

function adminTrafficActionLabel($event) {
  $label = trim((string) ($event['target_label'] ?? ''));
  if ($label !== '') {
    return $label;
  }

  $eventName = trim((string) ($event['event_name'] ?? ''));
  $targetPath = trim((string) ($event['target_path'] ?? ''));

  $eventLabels = [
    'phone_click' => 'Phone Click',
    'email_click' => 'Email Click',
    'chat_open' => 'Chat Open',
    'chat_lead_submit' => 'Chat Lead Submit',
    'contact_form_submit' => 'Contact Form Submit',
    'contact_page_click' => 'Contact Page Click',
    'download' => 'Download',
    'outbound_click' => 'Outbound Click',
    'social_click' => 'Social Click',
  ];

  if ($eventName !== '' && isset($eventLabels[$eventName])) {
    return $eventLabels[$eventName];
  }

  if ($targetPath !== '') {
    return $targetPath;
  }

  if ($eventName !== '') {
    return ucwords(str_replace('_', ' ', $eventName));
  }

  return 'Interaction';
}

function adminBuildTrafficRangeSummary($events, DateTimeImmutable $start, DateTimeImmutable $end) {
  $summary = [
    'views' => 0,
    'visitors' => 0,
    'top_pages' => [],
    'top_sources' => [],
    'top_engagement' => [],
    'top_actions' => [],
    'avg_engaged_seconds' => 0,
    'interactions' => 0,
    'contact_actions' => 0,
  ];

  $startUnix = $start->getTimestamp();
  $endUnix = $end->getTimestamp();
  $visitors = [];
  $pageCounts = [];
  $sourceCounts = [];
  $engagementCounts = [];
  $actionCounts = [];
  $totalEngagedMs = 0;

  foreach ((array) $events as $event) {
    $eventUnix = (int) ($event['timestamp_unix'] ?? 0);
    if ($eventUnix < $startUnix || $eventUnix > $endUnix) {
      continue;
    }

    $eventType = trim((string) ($event['event_type'] ?? 'pageview')) ?: 'pageview';
    $pageKey = trim((string) ($event['path'] ?? '/')) ?: '/';

    if ($eventType === 'pageview') {
      $summary['views'] += 1;

      $visitorKey = trim((string) ($event['visitor_key'] ?? ''));
      if ($visitorKey !== '') {
        $visitors[$visitorKey] = true;
      }

      if (!isset($pageCounts[$pageKey])) {
        $pageCounts[$pageKey] = [
          'path' => $pageKey,
          'label' => adminTrafficPathLabel($pageKey, (string) ($event['title'] ?? '')),
          'count' => 0,
        ];
      }
      $pageCounts[$pageKey]['count'] += 1;

      $sourceLabel = adminTrafficSourceLabel($event);
      if ($sourceLabel !== 'Internal') {
        if (!isset($sourceCounts[$sourceLabel])) {
          $sourceCounts[$sourceLabel] = [
            'label' => $sourceLabel,
            'count' => 0,
          ];
        }
        $sourceCounts[$sourceLabel]['count'] += 1;
      }
    } elseif ($eventType === 'engagement') {
      $engagedMs = max(0, (int) ($event['engaged_ms'] ?? 0));
      if ($engagedMs <= 0) {
        continue;
      }

      $totalEngagedMs += $engagedMs;
      if (!isset($engagementCounts[$pageKey])) {
        $engagementCounts[$pageKey] = [
          'path' => $pageKey,
          'label' => adminTrafficPathLabel($pageKey, (string) ($event['title'] ?? '')),
          'total_ms' => 0,
          'samples' => 0,
        ];
      }

      $engagementCounts[$pageKey]['total_ms'] += $engagedMs;
      $engagementCounts[$pageKey]['samples'] += 1;
    } elseif ($eventType === 'link_click' || $eventType === 'contact_action') {
      $summary['interactions'] += 1;

      if ($eventType === 'contact_action') {
        $summary['contact_actions'] += 1;
      }

      $actionLabel = adminTrafficActionLabel($event);
      if (!isset($actionCounts[$actionLabel])) {
        $actionCounts[$actionLabel] = [
          'label' => $actionLabel,
          'count' => 0,
        ];
      }

      $actionCounts[$actionLabel]['count'] += 1;
    }
  }

  $summary['visitors'] = count($visitors);
  $summary['avg_engaged_seconds'] = $summary['views'] > 0 ? (int) round($totalEngagedMs / max(1, $summary['views']) / 1000) : 0;

  uasort($pageCounts, function ($left, $right) {
    $countDiff = ($right['count'] ?? 0) <=> ($left['count'] ?? 0);
    if ($countDiff !== 0) {
      return $countDiff;
    }

    return strcmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? ''));
  });

  uasort($sourceCounts, function ($left, $right) {
    $countDiff = ($right['count'] ?? 0) <=> ($left['count'] ?? 0);
    if ($countDiff !== 0) {
      return $countDiff;
    }

    return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
  });

  uasort($engagementCounts, function ($left, $right) {
    $leftAvg = ($left['samples'] ?? 0) > 0 ? ($left['total_ms'] ?? 0) / max(1, (int) ($left['samples'] ?? 0)) : 0;
    $rightAvg = ($right['samples'] ?? 0) > 0 ? ($right['total_ms'] ?? 0) / max(1, (int) ($right['samples'] ?? 0)) : 0;
    $avgDiff = $rightAvg <=> $leftAvg;
    if ($avgDiff !== 0) {
      return $avgDiff;
    }

    return strcmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? ''));
  });

  uasort($actionCounts, function ($left, $right) {
    $countDiff = ($right['count'] ?? 0) <=> ($left['count'] ?? 0);
    if ($countDiff !== 0) {
      return $countDiff;
    }

    return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
  });

  foreach ($engagementCounts as &$engagement) {
    $samples = max(1, (int) ($engagement['samples'] ?? 0));
    $engagement['avg_seconds'] = (int) round(((int) ($engagement['total_ms'] ?? 0)) / $samples / 1000);
    $engagement['total_minutes'] = round(((int) ($engagement['total_ms'] ?? 0)) / 60000, 1);
  }
  unset($engagement);

  $summary['top_pages'] = array_slice(array_values($pageCounts), 0, 5);
  $summary['top_sources'] = array_slice(array_values($sourceCounts), 0, 5);
  $summary['top_engagement'] = array_slice(array_values($engagementCounts), 0, 5);
  $summary['top_actions'] = array_slice(array_values($actionCounts), 0, 5);

  return $summary;
}

function adminBuildTrafficSnapshot($now = null) {
  $now = $now instanceof DateTimeImmutable ? $now->setTimezone(adminTimezone()) : adminNow();
  $events = adminReadTrafficEvents();
  $todayStart = $now->setTime(0, 0, 0);
  $sevenDayStart = $now->modify('-6 days')->setTime(0, 0, 0);
  $thirtyDayStart = $now->modify('-29 days')->setTime(0, 0, 0);
  $oldestEvent = $events ? $events[count($events) - 1] : null;
  $recentViews = [];
  $totalViews = 0;

  foreach ($events as $event) {
    $eventType = trim((string) ($event['event_type'] ?? 'pageview')) ?: 'pageview';
    if ($eventType !== 'pageview') {
      continue;
    }

    $totalViews += 1;

    if ((int) ($event['timestamp_unix'] ?? 0) >= $sevenDayStart->getTimestamp()) {
      $recentViews[] = $event;
    }

    if (count($recentViews) >= 8) {
      break;
    }
  }

  return [
    'total_views' => $totalViews,
    'started_at' => $oldestEvent['timestamp'] ?? '',
    'today' => adminBuildTrafficRangeSummary($events, $todayStart, $now),
    'seven_days' => adminBuildTrafficRangeSummary($events, $sevenDayStart, $now),
    'thirty_days' => adminBuildTrafficRangeSummary($events, $thirtyDayStart, $now),
    'recent_views' => $recentViews,
  ];
}

function adminDateKeyFromUnix($timestampUnix) {
  return (new DateTimeImmutable('@' . max(0, (int) $timestampUnix)))
    ->setTimezone(adminTimezone())
    ->format('Y-m-d');
}

function adminShortNumber($value) {
  $value = (float) $value;
  $abs = abs($value);

  if ($abs >= 1000000) {
    return rtrim(rtrim(number_format($value / 1000000, 1, '.', ''), '0'), '.') . 'M';
  }

  if ($abs >= 1000) {
    return rtrim(rtrim(number_format($value / 1000, 1, '.', ''), '0'), '.') . 'K';
  }

  if (abs($value - round($value)) > 0.001) {
    return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
  }

  return number_format((float) round($value), 0, '.', '');
}

function adminFormatCompactMinutes($minutes) {
  $minutes = max(0, (float) $minutes);

  if ($minutes >= 60) {
    return rtrim(rtrim(number_format($minutes / 60, 1, '.', ''), '0'), '.') . 'h';
  }

  return adminShortNumber(round($minutes)) . 'm';
}

function adminFormatChartTick($value, $format = 'count') {
  $value = max(0, (float) $value);

  if ($format === 'minutes') {
    return adminFormatCompactMinutes($value);
  }

  return adminShortNumber($value);
}

function adminBuildChangeSummary($current, $previous) {
  $current = (float) $current;
  $previous = (float) $previous;

  if ($previous <= 0) {
    if ($current <= 0) {
      return [
        'direction' => 'flat',
        'symbol' => '→',
        'label' => 'No change',
      ];
    }

    return [
      'direction' => 'up',
      'symbol' => '↑',
      'label' => 'New',
    ];
  }

  $delta = (($current - $previous) / $previous) * 100;
  if (abs($delta) < 0.05) {
    return [
      'direction' => 'flat',
      'symbol' => '→',
      'label' => '0%',
    ];
  }

  return [
    'direction' => $delta > 0 ? 'up' : 'down',
    'symbol' => $delta > 0 ? '↑' : '↓',
    'label' => adminShortNumber(abs($delta)) . '%',
  ];
}

function adminBuildTrendAxisLabels(DateTimeImmutable $start, $days) {
  $days = max(1, (int) $days);
  $indexes = [0, (int) floor(($days - 1) / 3), (int) floor((($days - 1) * 2) / 3), $days - 1];
  $labels = [];
  $seen = [];

  foreach ($indexes as $index) {
    if (isset($seen[$index])) {
      continue;
    }

    $seen[$index] = true;
    $date = $start->modify('+' . $index . ' days');
    $labels[] = [
      'index' => $index,
      'label' => $date->format('M j'),
    ];
  }

  return $labels;
}

function adminRenderTrendChartSvg($series, $axisLabels = [], $options = []) {
  $series = array_values(array_map('floatval', (array) $series));
  if (!$series) {
    $series = [0];
  }

  $width = 520;
  $height = 210;
  $paddingLeft = 12;
  $paddingRight = 12;
  $paddingTop = 10;
  $paddingBottom = 34;
  $plotWidth = $width - $paddingLeft - $paddingRight;
  $plotHeight = $height - $paddingTop - $paddingBottom;
  $lineColor = trim((string) ($options['line_color'] ?? '#7fd2ff')) ?: '#7fd2ff';
  $fillColor = trim((string) ($options['fill_color'] ?? 'rgba(127, 210, 255, 0.16)')) ?: 'rgba(127, 210, 255, 0.16)';
  $tickFormat = trim((string) ($options['tick_format'] ?? 'count')) ?: 'count';
  $maxValue = max($series);
  if ($maxValue <= 0) {
    $maxValue = 1;
  }

  $count = count($series);
  $stepX = $count > 1 ? ($plotWidth / ($count - 1)) : 0;
  $points = [];

  foreach ($series as $index => $value) {
    $x = $paddingLeft + ($stepX * $index);
    $y = $paddingTop + $plotHeight - (($value / $maxValue) * $plotHeight);
    $points[] = [$x, $y];
  }

  $linePathParts = [];
  foreach ($points as $index => $point) {
    $linePathParts[] = ($index === 0 ? 'M ' : 'L ') . round($point[0], 2) . ' ' . round($point[1], 2);
  }
  $linePath = implode(' ', $linePathParts);

  $areaPath = $linePath;
  if ($points) {
    $firstPoint = $points[0];
    $lastPoint = $points[count($points) - 1];
    $bottom = $paddingTop + $plotHeight;
    $areaPath .= ' L ' . round($lastPoint[0], 2) . ' ' . round($bottom, 2);
    $areaPath .= ' L ' . round($firstPoint[0], 2) . ' ' . round($bottom, 2) . ' Z';
  }

  $gridLines = [0, 0.5, 1];
  $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-hidden="true" preserveAspectRatio="none">';

  foreach ($gridLines as $fraction) {
    $y = $paddingTop + ($plotHeight * (1 - $fraction));
    $svg .= '<line x1="' . $paddingLeft . '" y1="' . round($y, 2) . '" x2="' . ($paddingLeft + $plotWidth) . '" y2="' . round($y, 2) . '" stroke="rgba(47, 40, 32, 0.14)" stroke-width="1" />';
    if ($fraction > 0) {
      $tickValue = $maxValue * $fraction;
      $svg .= '<text x="' . ($paddingLeft + 2) . '" y="' . max(10, round($y - 4, 2)) . '" fill="rgba(110, 94, 79, 0.9)" font-size="11">' . adminEscape(adminFormatChartTick($tickValue, $tickFormat)) . '</text>';
    }
  }

  $svg .= '<path d="' . $areaPath . '" fill="' . adminEscape($fillColor) . '" />';
  $svg .= '<path d="' . $linePath . '" fill="none" stroke="' . adminEscape($lineColor) . '" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />';

  foreach ((array) $axisLabels as $axisLabel) {
    $index = max(0, min($count - 1, (int) ($axisLabel['index'] ?? 0)));
    $x = $paddingLeft + ($stepX * $index);
    $svg .= '<text x="' . round($x, 2) . '" y="' . ($height - 8) . '" fill="rgba(110, 94, 79, 0.9)" font-size="11" text-anchor="' . ($index === 0 ? 'start' : ($index === $count - 1 ? 'end' : 'middle')) . '">' . adminEscape((string) ($axisLabel['label'] ?? '')) . '</text>';
  }

  $svg .= '</svg>';
  return $svg;
}

function adminBuildTrafficPerformanceOverview($leadHistoryRecords, $now = null) {
  $now = $now instanceof DateTimeImmutable ? $now->setTimezone(adminTimezone()) : adminNow();
  $days = 30;
  $currentStart = $now->modify('-' . ($days - 1) . ' days')->setTime(0, 0, 0);
  $previousStart = $currentStart->modify('-' . $days . ' days');
  $previousEnd = $currentStart->modify('-1 second');
  $axisLabels = adminBuildTrendAxisLabels($currentStart, $days);
  $keyIndexes = [];
  $dailyVisitorSets = [];

  for ($index = 0; $index < $days; $index += 1) {
    $dateKey = $currentStart->modify('+' . $index . ' days')->format('Y-m-d');
    $keyIndexes[$dateKey] = $index;
    $dailyVisitorSets[$dateKey] = [];
  }

  $metricMeta = [
    'views' => [
      'title' => 'Views',
      'format' => 'count',
      'subtitle' => 'Page views in the last 30 days',
      'legend' => 'Views',
    ],
    'visitors' => [
      'title' => 'Viewers',
      'format' => 'count',
      'subtitle' => 'Unique visitors in the last 30 days',
      'legend' => 'Viewers',
    ],
    'engaged_minutes' => [
      'title' => 'Engaged Minutes',
      'format' => 'minutes',
      'subtitle' => 'Total active time in the last 30 days',
      'legend' => 'Engaged minutes',
    ],
    'interactions' => [
      'title' => 'Content Interactions',
      'format' => 'count',
      'subtitle' => 'Tracked clicks and contact actions',
      'legend' => 'Interactions',
    ],
    'contact_actions' => [
      'title' => 'Contact Actions',
      'format' => 'count',
      'subtitle' => 'Phone, email, chat, and form actions',
      'legend' => 'Contact actions',
    ],
    'leads' => [
      'title' => 'Leads',
      'format' => 'count',
      'subtitle' => 'First-time lead captures in the last 30 days',
      'legend' => 'Leads',
    ],
  ];

  $currentTotals = [];
  $previousTotals = [];
  $series = [];

  foreach ($metricMeta as $metricKey => $meta) {
    $currentTotals[$metricKey] = 0;
    $previousTotals[$metricKey] = 0;
    $series[$metricKey] = array_fill(0, $days, 0);
  }

  $currentVisitors = [];
  $previousVisitors = [];

  foreach (adminReadTrafficEvents() as $event) {
    $eventUnix = (int) ($event['timestamp_unix'] ?? 0);
    if ($eventUnix <= 0 || $eventUnix < $previousStart->getTimestamp() || $eventUnix > $now->getTimestamp()) {
      continue;
    }

    $dateKey = adminDateKeyFromUnix($eventUnix);
    $eventType = trim((string) ($event['event_type'] ?? 'pageview')) ?: 'pageview';
    $isCurrent = $eventUnix >= $currentStart->getTimestamp();
    $isPrevious = !$isCurrent && $eventUnix >= $previousStart->getTimestamp() && $eventUnix <= $previousEnd->getTimestamp();

    if (!$isCurrent && !$isPrevious) {
      continue;
    }

    if ($eventType === 'pageview') {
      $visitorKey = trim((string) ($event['visitor_key'] ?? ''));

      if ($isCurrent) {
        $currentTotals['views'] += 1;
        if (isset($keyIndexes[$dateKey])) {
          $series['views'][$keyIndexes[$dateKey]] += 1;
          if ($visitorKey !== '') {
            $dailyVisitorSets[$dateKey][$visitorKey] = true;
          }
        }
        if ($visitorKey !== '') {
          $currentVisitors[$visitorKey] = true;
        }
      } else {
        $previousTotals['views'] += 1;
        if ($visitorKey !== '') {
          $previousVisitors[$visitorKey] = true;
        }
      }
    } elseif ($eventType === 'engagement') {
      $engagedMinutes = max(0, (int) round(((int) ($event['engaged_ms'] ?? 0)) / 60000));
      if ($engagedMinutes <= 0) {
        continue;
      }

      if ($isCurrent) {
        $currentTotals['engaged_minutes'] += $engagedMinutes;
        if (isset($keyIndexes[$dateKey])) {
          $series['engaged_minutes'][$keyIndexes[$dateKey]] += $engagedMinutes;
        }
      } else {
        $previousTotals['engaged_minutes'] += $engagedMinutes;
      }
    } elseif ($eventType === 'link_click' || $eventType === 'contact_action') {
      if ($isCurrent) {
        $currentTotals['interactions'] += 1;
        if (isset($keyIndexes[$dateKey])) {
          $series['interactions'][$keyIndexes[$dateKey]] += 1;
        }
      } else {
        $previousTotals['interactions'] += 1;
      }

      if ($eventType === 'contact_action') {
        if ($isCurrent) {
          $currentTotals['contact_actions'] += 1;
          if (isset($keyIndexes[$dateKey])) {
            $series['contact_actions'][$keyIndexes[$dateKey]] += 1;
          }
        } else {
          $previousTotals['contact_actions'] += 1;
        }
      }
    }
  }

  foreach ($dailyVisitorSets as $dateKey => $visitorSet) {
    if (isset($keyIndexes[$dateKey])) {
      $series['visitors'][$keyIndexes[$dateKey]] = count($visitorSet);
    }
  }

  $currentTotals['visitors'] = count($currentVisitors);
  $previousTotals['visitors'] = count($previousVisitors);

  foreach ((array) $leadHistoryRecords as $record) {
    $firstSeenUnix = (int) ($record['first_seen_unix'] ?? 0);
    if ($firstSeenUnix <= 0 || $firstSeenUnix < $previousStart->getTimestamp() || $firstSeenUnix > $now->getTimestamp()) {
      continue;
    }

    $dateKey = adminDateKeyFromUnix($firstSeenUnix);
    if ($firstSeenUnix >= $currentStart->getTimestamp()) {
      $currentTotals['leads'] += 1;
      if (isset($keyIndexes[$dateKey])) {
        $series['leads'][$keyIndexes[$dateKey]] += 1;
      }
    } elseif ($firstSeenUnix <= $previousEnd->getTimestamp()) {
      $previousTotals['leads'] += 1;
    }
  }

  $cards = [];
  foreach ($metricMeta as $metricKey => $meta) {
    $currentValue = $currentTotals[$metricKey] ?? 0;
    $change = adminBuildChangeSummary($currentValue, $previousTotals[$metricKey] ?? 0);
    $cards[$metricKey] = [
      'title' => $meta['title'],
      'format' => $meta['format'],
      'subtitle' => $meta['subtitle'],
      'legend' => $meta['legend'],
      'value' => $currentValue,
      'display_value' => $meta['format'] === 'minutes' ? adminFormatCompactMinutes($currentValue) : adminShortNumber($currentValue),
      'change' => $change,
      'series' => $series[$metricKey],
      'axis_labels' => $axisLabels,
    ];
  }

  return [
    'period_label' => $currentStart->format('M j, Y') . ' - ' . $now->format('M j, Y'),
    'cards' => $cards,
  ];
}

function adminFormatTimestamp($timestamp) {
  $timestamp = trim((string) $timestamp);
  if ($timestamp === '') {
    return 'Unknown';
  }

  try {
    $date = new DateTimeImmutable($timestamp);
    $date = $date->setTimezone(adminTimezone());
  } catch (Exception $exception) {
    return $timestamp;
  }

  return $date->format('M j, Y g:i a');
}

function adminFormatDurationSeconds($seconds) {
  $seconds = max(0, (int) $seconds);

  if ($seconds >= 3600) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return $hours . 'h ' . $minutes . 'm';
  }

  if ($seconds >= 60) {
    $minutes = floor($seconds / 60);
    $remainder = $seconds % 60;
    return $minutes . 'm ' . $remainder . 's';
  }

  return $seconds . 's';
}

function adminEscape($value) {
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function adminLeadBadgeText($lead) {
  if (!empty($lead['is_social_message'])) {
    $channel = strtolower(trim((string) ($lead['channel'] ?? '')));
    return $channel === 'instagram' ? 'Instagram Msg' : 'Facebook Msg';
  }

  if (!empty($lead['has_full']) && !empty($lead['has_partial'])) {
    return 'Full + Partial';
  }

  if (!empty($lead['has_full'])) {
    return 'Full Lead';
  }

  return 'Partial Lead';
}

function adminStatusLabel($status) {
  $options = adminStatusOptions();
  return $options[$status] ?? ucfirst((string) $status);
}

function adminReportRecipientEmail() {
  $config = adminConfig();
  return trim((string) ($config['report_email'] ?? ''));
}

function adminReportToken() {
  $config = adminConfig();
  return trim((string) ($config['report_token'] ?? ''));
}

function adminBuildReportKey(DateTimeImmutable $start, DateTimeImmutable $end) {
  return $start->format('Ymd') . '_' . $end->format('Ymd');
}

function adminBuildReportLabel(DateTimeImmutable $start, DateTimeImmutable $end) {
  if ($start->format('Ymd') === $end->format('Ymd')) {
    return $start->format('l, F j, Y');
  }

  return $start->format('l, F j, Y') . ' to ' . $end->format('l, F j, Y');
}

function adminScheduledReportWindow($now = null) {
  $now = $now instanceof DateTimeImmutable ? $now->setTimezone(adminTimezone()) : adminNow();
  $weekday = (int) $now->format('N');

  if ($weekday === 6 || $weekday === 7) {
    return null;
  }

  if ($weekday === 1) {
    $start = $now->modify('last friday')->setTime(0, 0, 0);
    $end = $now->modify('last sunday')->setTime(23, 59, 59);
  } else {
    $start = $now->modify('yesterday')->setTime(0, 0, 0);
    $end = $now->modify('yesterday')->setTime(23, 59, 59);
  }

  return [
    'start' => $start,
    'end' => $end,
    'key' => adminBuildReportKey($start, $end),
    'label' => adminBuildReportLabel($start, $end),
  ];
}

function adminCustomReportWindow($startInput, $endInput) {
  $startInput = trim((string) $startInput);
  $endInput = trim((string) $endInput);

  if ($startInput === '' || $endInput === '') {
    return null;
  }

  try {
    $start = new DateTimeImmutable($startInput . ' 00:00:00', adminTimezone());
    $end = new DateTimeImmutable($endInput . ' 23:59:59', adminTimezone());
  } catch (Exception $exception) {
    return null;
  }

  if ($end < $start) {
    return null;
  }

  return [
    'start' => $start,
    'end' => $end,
    'key' => adminBuildReportKey($start, $end),
    'label' => adminBuildReportLabel($start, $end),
  ];
}

function adminLeadEntriesInRange($lead, DateTimeImmutable $start, DateTimeImmutable $end) {
  $rangeEntries = [];
  $startUnix = $start->getTimestamp();
  $endUnix = $end->getTimestamp();

  foreach ((array) ($lead['entries'] ?? []) as $entry) {
    $entryUnix = (int) ($entry['timestamp_unix'] ?? 0);
    if ($entryUnix >= $startUnix && $entryUnix <= $endUnix) {
      $rangeEntries[] = $entry;
    }
  }

  usort($rangeEntries, function ($left, $right) {
    return ($right['timestamp_unix'] ?? 0) <=> ($left['timestamp_unix'] ?? 0);
  });

  return $rangeEntries;
}

function adminBuildLeadReportRows($allLeads, DateTimeImmutable $start, DateTimeImmutable $end) {
  $rows = [];

  foreach ((array) $allLeads as $lead) {
    if (!empty($lead['is_social_message'])) {
      continue;
    }

    $rangeEntries = adminLeadEntriesInRange($lead, $start, $end);
    if (!$rangeEntries) {
      continue;
    }

    $reportLead = $lead;
    $reportLead['report_entries'] = $rangeEntries;
    $reportLead['report_entry_count'] = count($rangeEntries);
    $reportLead['report_latest_activity'] = $rangeEntries[0]['timestamp'] ?? $lead['last_seen'];
    $rows[] = $reportLead;
  }

  usort($rows, function ($left, $right) {
    $leftUnix = strtotime((string) ($left['report_latest_activity'] ?? '')) ?: 0;
    $rightUnix = strtotime((string) ($right['report_latest_activity'] ?? '')) ?: 0;
    return $rightUnix <=> $leftUnix;
  });

  return $rows;
}

function adminBuildReportData($allLeads, DateTimeImmutable $start, DateTimeImmutable $end, $label = '') {
  $rows = adminBuildLeadReportRows($allLeads, $start, $end);
  $label = trim((string) $label) !== '' ? trim((string) $label) : adminBuildReportLabel($start, $end);

  return [
    'key' => adminBuildReportKey($start, $end),
    'label' => $label,
    'start' => $start,
    'end' => $end,
    'generated_at' => adminNow(),
    'leads' => $rows,
    'lead_count' => count($rows),
  ];
}

function adminRenderReportHtmlBody($report, $forEmail = false) {
  $title = $forEmail ? 'Daily Lead Report' : 'Printable Lead Report';
  $body = '<h1>' . adminEscape($title) . '</h1>';
  $body .= '<p><strong>Period:</strong> ' . adminEscape((string) ($report['label'] ?? '')) . '<br>';
  $body .= '<strong>Generated:</strong> ' . adminEscape(adminFormatTimestamp((string) (($report['generated_at'] ?? adminNow()) instanceof DateTimeImmutable ? $report['generated_at']->format(DateTimeInterface::ATOM) : $report['generated_at']))) . '</p>';
  $body .= '<p><strong>Total leads:</strong> ' . adminEscape((string) ($report['lead_count'] ?? 0)) . '</p>';

  if (empty($report['leads'])) {
    $body .= '<p>No leads were captured in this report window.</p>';
    return $body;
  }

  foreach ((array) $report['leads'] as $lead) {
    $body .= '<section style="margin:0 0 18px; padding:14px 16px; border:1px solid #d6c4aa; border-radius:14px;">';
    $body .= '<h2 style="margin:0 0 8px; font-size:18px;">' . adminEscape((string) ($lead['name'] !== '' ? $lead['name'] : 'Unnamed lead')) . '</h2>';
    $body .= '<p style="margin:0 0 8px;"><strong>Contact:</strong> ' . adminEscape((string) ($lead['primary_contact'] ?? 'No contact')) . '</p>';
    if (($lead['email'] ?? '') !== '' && ($lead['phone'] ?? '') !== '' && $lead['email'] !== $lead['phone']) {
      $body .= '<p style="margin:0 0 8px;"><strong>Email:</strong> ' . adminEscape((string) $lead['email']) . '<br><strong>Phone:</strong> ' . adminEscape((string) $lead['phone']) . '</p>';
    }
    $body .= '<p style="margin:0 0 8px;"><strong>Status:</strong> ' . adminEscape(adminStatusLabel((string) ($lead['status'] ?? ''))) . ' | <strong>Owner:</strong> ' . adminEscape((string) (($lead['owner'] ?? '') !== '' ? $lead['owner'] : 'Unassigned')) . '</p>';
    if (($lead['project_type'] ?? '') !== '' || ($lead['city'] ?? '') !== '' || ($lead['material_interest'] ?? '') !== '') {
      $body .= '<p style="margin:0 0 8px;"><strong>Project:</strong> ' . adminEscape((string) ($lead['project_type'] ?? 'Not provided'));
      $body .= ' | <strong>City:</strong> ' . adminEscape((string) (($lead['city'] ?? '') !== '' ? $lead['city'] : 'Not provided'));
      $body .= ' | <strong>Material:</strong> ' . adminEscape((string) (($lead['material_interest'] ?? '') !== '' ? $lead['material_interest'] : 'Not provided')) . '</p>';
    }
    if (($lead['summary'] ?? '') !== '') {
      $body .= '<p style="margin:0 0 8px; white-space:pre-wrap;"><strong>Summary:</strong> ' . adminEscape((string) $lead['summary']) . '</p>';
    }
    if (($lead['chat_transcript'] ?? '') !== '') {
      $body .= '<p style="margin:0 0 8px; white-space:pre-wrap;"><strong>Transcript:</strong><br>' . nl2br(adminEscape((string) $lead['chat_transcript'])) . '</p>';
    }
    $body .= '<p style="margin:0;"><strong>Lead events in period:</strong> ';
    $eventLabels = [];
    foreach ((array) ($lead['report_entries'] ?? []) as $entry) {
      $eventLabels[] = (($entry['entry_type'] ?? '') === 'full' ? 'Full submission' : 'Partial capture') . ' at ' . adminFormatTimestamp((string) ($entry['timestamp'] ?? ''));
    }
    $body .= adminEscape(implode(' | ', $eventLabels)) . '</p>';
    $body .= '</section>';
  }

  return $body;
}

function adminRenderReportPlainText($report) {
  $lines = [];
  $lines[] = 'Olive Glass & Marble Daily Lead Report';
  $lines[] = 'Period: ' . ((string) ($report['label'] ?? ''));
  $generatedAt = ($report['generated_at'] ?? adminNow());
  if ($generatedAt instanceof DateTimeImmutable) {
    $lines[] = 'Generated: ' . $generatedAt->format('M j, Y g:i a');
  }
  $lines[] = 'Total leads: ' . ((string) ($report['lead_count'] ?? 0));
  $lines[] = '';

  if (empty($report['leads'])) {
    $lines[] = 'No leads were captured in this report window.';
    return implode("\n", $lines);
  }

  foreach ((array) $report['leads'] as $lead) {
    $lines[] = str_repeat('=', 40);
    $lines[] = 'Name: ' . ($lead['name'] !== '' ? $lead['name'] : 'Unnamed lead');
    $lines[] = 'Contact: ' . ($lead['primary_contact'] ?? 'No contact');
    if (($lead['email'] ?? '') !== '') {
      $lines[] = 'Email: ' . $lead['email'];
    }
    if (($lead['phone'] ?? '') !== '') {
      $lines[] = 'Phone: ' . $lead['phone'];
    }
    $lines[] = 'Status: ' . (($lead['status'] ?? '') !== '' ? adminStatusLabel((string) $lead['status']) : 'Unknown');
    $lines[] = 'Owner: ' . (($lead['owner'] ?? '') !== '' ? $lead['owner'] : 'Unassigned');
    if (($lead['project_type'] ?? '') !== '') {
      $lines[] = 'Project: ' . $lead['project_type'];
    }
    if (($lead['city'] ?? '') !== '') {
      $lines[] = 'City: ' . $lead['city'];
    }
    if (($lead['material_interest'] ?? '') !== '') {
      $lines[] = 'Material: ' . $lead['material_interest'];
    }
    if (($lead['summary'] ?? '') !== '') {
      $lines[] = 'Summary: ' . $lead['summary'];
    }
    if (($lead['chat_transcript'] ?? '') !== '') {
      $lines[] = 'Transcript:';
      $lines[] = $lead['chat_transcript'];
    }
    $lines[] = 'Lead events in period:';
    foreach ((array) ($lead['report_entries'] ?? []) as $entry) {
      $lines[] = '- ' . (($entry['entry_type'] ?? '') === 'full' ? 'Full submission' : 'Partial capture') . ' at ' . adminFormatTimestamp((string) ($entry['timestamp'] ?? ''));
    }
    $lines[] = '';
  }

  return implode("\n", $lines);
}

function adminSendReportEmail($report, $recipient) {
  $recipient = trim((string) $recipient);
  if ($recipient === '' || !function_exists('mail')) {
    return false;
  }

  $subject = 'Olive Glass & Marble Lead Report: ' . ((string) ($report['label'] ?? ''));
  $plainText = adminRenderReportPlainText($report);
  $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif; background:#f7f2ea; color:#2f2820; padding:24px;">' .
    adminRenderReportHtmlBody($report, true) .
    '</body></html>';
  $boundary = 'ogm-report-' . md5(uniqid((string) mt_rand(), true));
  $headers = [];
  $headers[] = 'From: Olive Glass & Marble <website@oliveglassandmarble.com>';
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

  $parts = [];
  $parts[] = '--' . $boundary;
  $parts[] = 'Content-Type: text/plain; charset=UTF-8';
  $parts[] = 'Content-Transfer-Encoding: 8bit';
  $parts[] = '';
  $parts[] = $plainText;
  $parts[] = '--' . $boundary;
  $parts[] = 'Content-Type: text/html; charset=UTF-8';
  $parts[] = 'Content-Transfer-Encoding: 8bit';
  $parts[] = '';
  $parts[] = $html;
  $parts[] = '--' . $boundary . '--';
  $parts[] = '';

  return mail($recipient, $subject, implode("\r\n", $parts), implode("\r\n", $headers));
}

function adminReportAlreadySent($reportKey) {
  $log = adminReadReportLog();
  return isset($log[trim((string) $reportKey)]);
}

function adminMarkReportSent($reportKey, $extra = []) {
  $reportKey = trim((string) $reportKey);
  if ($reportKey === '') {
    return false;
  }

  $log = adminReadReportLog();
  $log[$reportKey] = array_merge([
    'sent_at' => gmdate('c'),
  ], is_array($extra) ? $extra : []);

  return adminSaveReportLog($log);
}
