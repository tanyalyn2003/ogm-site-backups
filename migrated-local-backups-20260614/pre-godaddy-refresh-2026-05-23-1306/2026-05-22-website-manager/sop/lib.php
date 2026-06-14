<?php

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lead-storage.php';

function sopSendNoIndexHeaders() {
  header('X-Robots-Tag: noindex, nofollow', true);
  header('Cache-Control: private, no-store, max-age=0', true);
}

function sopIsHttpsRequest() {
  if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
    return true;
  }

  return ((int) ($_SERVER['SERVER_PORT'] ?? 0)) === 443;
}

function sopStartSession() {
  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }

  session_name('ogm_sop_portal');
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => sopIsHttpsRequest(),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

function sopEscape($value) {
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function sopStorageDir() {
  return dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'ogm-sop';
}

function sopUsersFile() {
  return sopStorageDir() . DIRECTORY_SEPARATOR . 'users.json';
}

function sopDataFile() {
  return sopStorageDir() . DIRECTORY_SEPARATOR . 'portal.json';
}

function sopNormalizeUsername($username) {
  $username = strtolower(trim((string) $username));
  $username = preg_replace('/[^a-z0-9._-]/', '', $username);
  return substr((string) $username, 0, 48);
}

function sopNormalizeRole($role) {
  return strtolower(trim((string) $role)) === 'manager' ? 'manager' : 'employee';
}

function sopHashPassword($password) {
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

function sopVerifyPassword($password, $user) {
  $password = (string) $password;
  $saltHex = trim((string) ($user['salt_hex'] ?? ''));
  $hashHex = trim((string) ($user['hash_hex'] ?? ''));
  $iterations = max(1, (int) ($user['iterations'] ?? 120000));

  if ($password === '' || $saltHex === '' || $hashHex === '') {
    return false;
  }

  $salt = @hex2bin($saltHex);
  if ($salt === false) {
    return false;
  }

  $computed = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
  return hash_equals(strtolower($hashHex), strtolower(bin2hex($computed)));
}

function sopDefaultUsers() {
  return [
    'sed' => [
      'display_name' => 'Sed',
      'role' => 'manager',
      'salt_hex' => '06ba9e54eb3e725ee9f367ce07ca9b55',
      'iterations' => 120000,
      'hash_hex' => 'b6e3d3c968ba52e16bd7b33a53eb2861fb14f51dfcb623d1f5bb3e8000f7eaf0',
    ],
    'employee' => [
      'display_name' => 'Employee',
      'role' => 'employee',
      'salt_hex' => '11590ae9cd9f515ead354f4cbda82072',
      'iterations' => 120000,
      'hash_hex' => '3e5f486c8499a4f4e67643aa645cb0065b68577617a7ffa378a123079c280fd7',
    ],
  ];
}

function sopNormalizeUserRecords($users) {
  $normalized = [];

  foreach ((array) $users as $key => $user) {
    if (!is_array($user)) {
      continue;
    }

    $username = is_string($key) ? $key : ($user['username'] ?? '');
    $username = sopNormalizeUsername($username);
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
      $displayName = ucfirst($username);
    }

    $normalized[$username] = [
      'display_name' => $displayName,
      'role' => sopNormalizeRole($user['role'] ?? 'employee'),
      'salt_hex' => $saltHex,
      'iterations' => max(1, (int) ($user['iterations'] ?? 120000)),
      'hash_hex' => $hashHex,
    ];
  }

  return $normalized;
}

function sopReadUsers() {
  $stored = ogmReadJsonFile(sopUsersFile(), []);
  if (isset($stored['users']) && is_array($stored['users'])) {
    $stored = $stored['users'];
  }

  $users = sopNormalizeUserRecords($stored);
  if (!$users) {
    $users = sopDefaultUsers();
  }

  return $users;
}

function sopManagerCount($users) {
  $count = 0;

  foreach ((array) $users as $user) {
    if (is_array($user) && sopNormalizeRole($user['role'] ?? 'employee') === 'manager') {
      $count += 1;
    }
  }

  return $count;
}

function sopSaveUsers($users) {
  return ogmWriteJsonFile(sopUsersFile(), [
    'users' => sopNormalizeUserRecords($users),
    'updated_at' => gmdate('c'),
  ]);
}

function sopAttemptLogin($username, $password) {
  $username = sopNormalizeUsername($username);
  $user = sopReadUsers()[$username] ?? null;
  if (!is_array($user) || !sopVerifyPassword($password, $user)) {
    return false;
  }

  sopStartSession();
  session_regenerate_id(true);
  $_SESSION['ogm_sop_user'] = [
    'username' => $username,
    'display_name' => trim((string) ($user['display_name'] ?? $username)),
    'role' => sopNormalizeRole($user['role'] ?? 'employee'),
  ];

  if (empty($_SESSION['ogm_sop_csrf'])) {
    $_SESSION['ogm_sop_csrf'] = bin2hex(random_bytes(24));
  }

  return true;
}

function sopCurrentUser() {
  sopStartSession();

  if (empty($_SESSION['ogm_sop_user']) || !is_array($_SESSION['ogm_sop_user'])) {
    return null;
  }

  $username = sopNormalizeUsername($_SESSION['ogm_sop_user']['username'] ?? '');
  if ($username === '') {
    sopLogout();
    return null;
  }

  $user = sopReadUsers()[$username] ?? null;
  if (!is_array($user)) {
    sopLogout();
    return null;
  }

  $_SESSION['ogm_sop_user'] = [
    'username' => $username,
    'display_name' => trim((string) ($user['display_name'] ?? $username)),
    'role' => sopNormalizeRole($user['role'] ?? 'employee'),
  ];

  return $_SESSION['ogm_sop_user'];
}

function sopIsLoggedIn() {
  return sopCurrentUser() !== null;
}

function sopIsManager() {
  $user = sopCurrentUser();
  return is_array($user) && (($user['role'] ?? '') === 'manager');
}

function sopRequireLogin() {
  if (sopIsLoggedIn()) {
    return;
  }

  header('Location: login.php');
  exit;
}

function sopRequireManager() {
  sopRequireLogin();

  if (sopIsManager()) {
    return;
  }

  http_response_code(403);
  exit('Manager access required.');
}

function sopLogout() {
  sopStartSession();
  $_SESSION = [];

  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', !empty($params['secure']), !empty($params['httponly']));
  }

  session_destroy();
}

function sopCsrfToken() {
  sopStartSession();

  if (empty($_SESSION['ogm_sop_csrf'])) {
    $_SESSION['ogm_sop_csrf'] = bin2hex(random_bytes(24));
  }

  return (string) $_SESSION['ogm_sop_csrf'];
}

function sopVerifyCsrfToken($token) {
  sopStartSession();

  if (empty($_SESSION['ogm_sop_csrf']) || !is_string($_SESSION['ogm_sop_csrf'])) {
    return false;
  }

  return hash_equals($_SESSION['ogm_sop_csrf'], (string) $token);
}

function sopSetFlash($type, $message) {
  sopStartSession();
  $_SESSION['ogm_sop_flash'] = [
    'type' => ($type === 'success' ? 'success' : 'error'),
    'message' => trim((string) $message),
  ];
}

function sopConsumeFlash() {
  sopStartSession();
  $flash = $_SESSION['ogm_sop_flash'] ?? null;
  unset($_SESSION['ogm_sop_flash']);
  return is_array($flash) ? $flash : null;
}

function sopHistoryLimit() {
  return 40;
}

function sopHistoryState() {
  sopStartSession();

  if (empty($_SESSION['ogm_sop_history']) || !is_array($_SESSION['ogm_sop_history'])) {
    $_SESSION['ogm_sop_history'] = [
      'undo' => [],
      'redo' => [],
    ];
  }

  $_SESSION['ogm_sop_history']['undo'] = array_values(is_array($_SESSION['ogm_sop_history']['undo'] ?? null) ? $_SESSION['ogm_sop_history']['undo'] : []);
  $_SESSION['ogm_sop_history']['redo'] = array_values(is_array($_SESSION['ogm_sop_history']['redo'] ?? null) ? $_SESSION['ogm_sop_history']['redo'] : []);

  return $_SESSION['ogm_sop_history'];
}

function sopHistorySelection($portalData, $departmentId = '', $pageId = '') {
  [$department] = sopResolveDepartment($portalData, $departmentId);
  $departmentId = (string) ($department['id'] ?? '');
  [$page] = sopResolvePage($department ?? [], $pageId);

  return [
    'department_id' => $departmentId,
    'page_id' => (string) ($page['id'] ?? ''),
  ];
}

function sopHistorySnapshot($departmentId = '', $pageId = '') {
  $portalData = sopReadData();

  return [
    'portal' => $portalData,
    'users' => sopReadUsers(),
    'selection' => sopHistorySelection($portalData, $departmentId, $pageId),
    'saved_at' => gmdate('c'),
  ];
}

function sopHistoryPushUndo($snapshot) {
  sopStartSession();
  $history = sopHistoryState();
  $undo = $history['undo'];
  $undo[] = $snapshot;
  if (count($undo) > sopHistoryLimit()) {
    $undo = array_slice($undo, -1 * sopHistoryLimit());
  }

  $_SESSION['ogm_sop_history']['undo'] = array_values($undo);
  $_SESSION['ogm_sop_history']['redo'] = [];
}

function sopHistoryCanUndo() {
  $history = sopHistoryState();
  return !empty($history['undo']);
}

function sopHistoryCanRedo() {
  $history = sopHistoryState();
  return !empty($history['redo']);
}

function sopHistoryRestoreSnapshot($snapshot) {
  $portal = is_array($snapshot['portal'] ?? null) ? $snapshot['portal'] : null;
  $users = is_array($snapshot['users'] ?? null) ? $snapshot['users'] : null;

  if ($portal === null || $users === null) {
    throw new RuntimeException('History could not be restored.');
  }

  if (!sopSaveData($portal) || !sopSaveUsers($users)) {
    throw new RuntimeException('History could not be restored.');
  }

  $selection = is_array($snapshot['selection'] ?? null) ? $snapshot['selection'] : [];
  $currentData = sopReadData();

  return sopHistorySelection(
    $currentData,
    (string) ($selection['department_id'] ?? ''),
    (string) ($selection['page_id'] ?? '')
  );
}

function sopUndoHistory($departmentId = '', $pageId = '') {
  sopStartSession();
  $history = sopHistoryState();
  $undo = $history['undo'];
  $redo = $history['redo'];

  if (!$undo) {
    throw new RuntimeException('Nothing to undo yet.');
  }

  $currentSnapshot = sopHistorySnapshot($departmentId, $pageId);
  $snapshot = array_pop($undo);
  $redo[] = $currentSnapshot;
  if (count($redo) > sopHistoryLimit()) {
    $redo = array_slice($redo, -1 * sopHistoryLimit());
  }

  $_SESSION['ogm_sop_history']['undo'] = array_values($undo);
  $_SESSION['ogm_sop_history']['redo'] = array_values($redo);

  return sopHistoryRestoreSnapshot($snapshot);
}

function sopRedoHistory($departmentId = '', $pageId = '') {
  sopStartSession();
  $history = sopHistoryState();
  $undo = $history['undo'];
  $redo = $history['redo'];

  if (!$redo) {
    throw new RuntimeException('Nothing to redo yet.');
  }

  $currentSnapshot = sopHistorySnapshot($departmentId, $pageId);
  $snapshot = array_pop($redo);
  $undo[] = $currentSnapshot;
  if (count($undo) > sopHistoryLimit()) {
    $undo = array_slice($undo, -1 * sopHistoryLimit());
  }

  $_SESSION['ogm_sop_history']['undo'] = array_values($undo);
  $_SESSION['ogm_sop_history']['redo'] = array_values($redo);

  return sopHistoryRestoreSnapshot($snapshot);
}

function sopPalette() {
  return [
    '#f1b43b',
    '#eb7540',
    '#bed547',
    '#56b8ed',
    '#d856a4',
    '#5d4da6',
  ];
}

function sopColorAt($index) {
  $palette = sopPalette();
  return $palette[((int) $index) % count($palette)];
}

function sopDepartmentBlueprints() {
  return [
    [
      'title' => 'Sales & Marketing',
      'summary' => 'Lead intake, messaging, quoting, campaign workflow, and handoff standards.',
    ],
    [
      'title' => 'Operations',
      'summary' => 'Scheduling, coordination, logistics, communication, and internal follow-through.',
    ],
    [
      'title' => 'Prefabrication',
      'summary' => 'Template prep, cut planning, readiness checks, and production preparation.',
    ],
    [
      'title' => 'Fabrication',
      'summary' => 'Shop standards, fabrication sequencing, machine workflow, and quality review.',
    ],
    [
      'title' => 'Instalation',
      'summary' => 'Field readiness, install sequencing, site standards, and completion checklists.',
    ],
    [
      'title' => 'Accounting & Finance',
      'summary' => 'Billing, payment handling, reporting, approvals, and document retention.',
    ],
  ];
}

function sopPageBlueprints($departmentTitle) {
  $departmentTitle = trim((string) $departmentTitle) ?: 'this department';

  return [
    [
      'title' => 'Overview',
      'summary' => 'Scope, ownership, and key expectations for ' . $departmentTitle . '.',
    ],
    [
      'title' => 'Daily Workflow',
      'summary' => 'Step-by-step daily process for ' . $departmentTitle . '.',
    ],
    [
      'title' => 'Forms & Templates',
      'summary' => 'Reusable forms, checklists, and templates for ' . $departmentTitle . '.',
    ],
    [
      'title' => 'Quality Checks',
      'summary' => 'Review checkpoints and finish standards for ' . $departmentTitle . '.',
    ],
    [
      'title' => 'Troubleshooting',
      'summary' => 'Escalation paths, exceptions, and common issue handling.',
    ],
    [
      'title' => 'Training Notes',
      'summary' => 'Onboarding notes, coaching updates, and process changes.',
    ],
  ];
}

function sopSlug($value, $fallback = 'item') {
  $value = strtolower(trim((string) $value));
  $value = preg_replace('/[^a-z0-9]+/', '-', $value);
  $value = trim((string) $value, '-');
  return $value !== '' ? $value : $fallback;
}

function sopUniqueId($preferred, $taken, $fallback = 'item') {
  $base = sopSlug($preferred, $fallback);
  $candidate = $base;
  $suffix = 2;

  while (isset($taken[$candidate])) {
    $candidate = $base . '-' . $suffix;
    $suffix += 1;
  }

  return $candidate;
}

function sopUniqueLabel($preferred, $taken) {
  $preferred = trim((string) $preferred) ?: 'Untitled';
  $candidate = $preferred;
  $suffix = 2;

  while (isset($taken[strtolower($candidate)])) {
    $candidate = $preferred . ' ' . $suffix;
    $suffix += 1;
  }

  return $candidate;
}

function sopDefaultDepartmentSummary($title) {
  $title = trim((string) $title) ?: 'this department';
  return 'Live SOP pages and linked Google Docs for ' . $title . '.';
}

function sopDraftDepartmentTitle($departments) {
  $taken = [];

  foreach ((array) $departments as $department) {
    if (!is_array($department)) {
      continue;
    }

    $title = trim((string) ($department['title'] ?? ''));
    if ($title !== '') {
      $taken[strtolower($title)] = true;
    }
  }

  return sopUniqueLabel('New Department', $taken);
}

function sopDraftPageTitle($pages) {
  $taken = [];

  foreach ((array) $pages as $page) {
    if (!is_array($page)) {
      continue;
    }

    $title = trim((string) ($page['title'] ?? ''));
    if ($title !== '') {
      $taken[strtolower($title)] = true;
    }
  }

  return sopUniqueLabel('New Side Tab', $taken);
}

function sopBuildSeedPages($departmentTitle) {
  $pages = [];
  $taken = [];

  foreach (sopPageBlueprints($departmentTitle) as $index => $blueprint) {
    $title = trim((string) ($blueprint['title'] ?? ''));
    $id = sopUniqueId($title, $taken, 'page');
    $taken[$id] = true;
    $pages[] = [
      'id' => $id,
      'title' => $title,
      'summary' => trim((string) ($blueprint['summary'] ?? '')),
      'color' => sopColorAt($index),
      'doc_url' => '',
      'sheet_url' => '',
      'updated_at' => gmdate('c'),
    ];
  }

  return $pages;
}

function sopDefaultData() {
  $departments = [];
  $taken = [];

  foreach (sopDepartmentBlueprints() as $index => $blueprint) {
    $title = trim((string) ($blueprint['title'] ?? ''));
    $id = sopUniqueId($title, $taken, 'department');
    $taken[$id] = true;
    $departments[] = [
      'id' => $id,
      'title' => $title,
      'summary' => trim((string) ($blueprint['summary'] ?? '')),
      'color' => sopColorAt($index),
      'pages' => sopBuildSeedPages($title),
      'updated_at' => gmdate('c'),
    ];
  }

  return [
    'portal_title' => 'OGM SOP & Document Handler',
    'departments' => $departments,
    'updated_at' => gmdate('c'),
  ];
}

function sopNormalizePageRecords($pages, $departmentTitle) {
  $normalized = [];
  $taken = [];

  foreach ((array) $pages as $index => $page) {
    if (!is_array($page)) {
      continue;
    }

    $title = trim((string) ($page['title'] ?? ''));
    if ($title === '') {
      $title = 'Step ' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
    }

    $preferredId = trim((string) ($page['id'] ?? ''));
    if ($preferredId === '') {
      $preferredId = $title;
    }

    $id = sopUniqueId($preferredId, $taken, 'page');
    $taken[$id] = true;
    $normalized[] = [
      'id' => $id,
      'title' => $title,
      'summary' => trim((string) ($page['summary'] ?? '')),
      'color' => trim((string) ($page['color'] ?? '')) ?: sopColorAt($index),
      'doc_url' => trim((string) ($page['doc_url'] ?? '')),
      'sheet_url' => trim((string) ($page['sheet_url'] ?? '')),
      'updated_at' => trim((string) ($page['updated_at'] ?? '')) ?: gmdate('c'),
    ];
  }

  return $normalized ?: sopBuildSeedPages($departmentTitle);
}

function sopNormalizeDepartmentRecords($departments) {
  $normalized = [];
  $taken = [];

  foreach ((array) $departments as $index => $department) {
    if (!is_array($department)) {
      continue;
    }

    $title = trim((string) ($department['title'] ?? ''));
    if ($title === '') {
      $title = 'Department ' . ($index + 1);
    }

    $preferredId = trim((string) ($department['id'] ?? ''));
    if ($preferredId === '') {
      $preferredId = $title;
    }

    $id = sopUniqueId($preferredId, $taken, 'department');
    $taken[$id] = true;
    $normalized[] = [
      'id' => $id,
      'title' => $title,
      'summary' => trim((string) ($department['summary'] ?? '')) ?: sopDefaultDepartmentSummary($title),
      'color' => trim((string) ($department['color'] ?? '')) ?: sopColorAt($index),
      'pages' => sopNormalizePageRecords($department['pages'] ?? [], $title),
      'updated_at' => trim((string) ($department['updated_at'] ?? '')) ?: gmdate('c'),
    ];
  }

  return $normalized;
}

function sopReadData() {
  $stored = ogmReadJsonFile(sopDataFile(), []);
  $defaults = sopDefaultData();
  $departments = sopNormalizeDepartmentRecords($stored['departments'] ?? []);

  return [
    'portal_title' => trim((string) ($stored['portal_title'] ?? '')) ?: $defaults['portal_title'],
    'departments' => $departments ?: $defaults['departments'],
    'updated_at' => trim((string) ($stored['updated_at'] ?? '')) ?: $defaults['updated_at'],
  ];
}

function sopSaveData($data) {
  return ogmWriteJsonFile(sopDataFile(), [
    'portal_title' => trim((string) ($data['portal_title'] ?? '')) ?: 'OGM SOP & Document Handler',
    'departments' => sopNormalizeDepartmentRecords($data['departments'] ?? []),
    'updated_at' => gmdate('c'),
  ]);
}

function sopResolveDepartment($data, $departmentId) {
  $departments = is_array($data['departments'] ?? null) ? $data['departments'] : [];
  if (!$departments) {
    return [null, -1];
  }

  $departmentId = trim((string) $departmentId);
  foreach ($departments as $index => $department) {
    if (($department['id'] ?? '') === $departmentId) {
      return [$department, $index];
    }
  }

  return [$departments[0], 0];
}

function sopResolvePage($department, $pageId) {
  $pages = is_array($department['pages'] ?? null) ? $department['pages'] : [];
  if (!$pages) {
    return [null, -1];
  }

  $pageId = trim((string) $pageId);
  foreach ($pages as $index => $page) {
    if (($page['id'] ?? '') === $pageId) {
      return [$page, $index];
    }
  }

  return [$pages[0], 0];
}

function sopBuildPortalUrl($departmentId = '', $pageId = '', $editTarget = '') {
  $query = [];
  if ($departmentId !== '') {
    $query['department'] = $departmentId;
  }
  if ($pageId !== '') {
    $query['page'] = $pageId;
  }
  $editTarget = trim((string) $editTarget);
  if ($editTarget !== '') {
    $query['edit'] = $editTarget;
  }

  return 'index.php' . ($query ? ('?' . http_build_query($query)) : '');
}

function sopExtractReferenceUrl($value) {
  $value = trim((string) $value);
  if ($value === '') {
    return '';
  }

  if (preg_match('~<iframe[^>]+src=["\']([^"\']+)["\']~i', $value, $matches)) {
    return html_entity_decode(trim((string) $matches[1]), ENT_QUOTES, 'UTF-8');
  }

  if (preg_match('~https?://[^\s<>"\']+~', $value, $matches)) {
    return html_entity_decode(trim((string) $matches[0]), ENT_QUOTES, 'UTF-8');
  }

  return $value;
}

function sopNormalizeDocReference($value) {
  $value = sopExtractReferenceUrl($value);
  if ($value === '') {
    return '';
  }

  $docId = sopGoogleDocId($value);
  if ($docId === '') {
    throw new RuntimeException('Paste a Google Docs document link or Google Docs document ID.');
  }

  return 'https://docs.google.com/document/d/' . $docId . '/edit';
}

function sopGoogleDocId($value) {
  $value = trim((string) $value);
  if ($value === '') {
    return '';
  }

  if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $value)) {
    return $value;
  }

  if (preg_match('~/document/d/([a-zA-Z0-9_-]+)~', $value, $matches)) {
    return $matches[1];
  }

  return '';
}

function sopGooglePreviewUrl($value) {
  $docId = sopGoogleDocId($value);
  if ($docId === '') {
    return '';
  }

  return 'https://docs.google.com/document/d/' . $docId . '/preview';
}

function sopGoogleEditUrl($value) {
  $docId = sopGoogleDocId($value);
  if ($docId === '') {
    return '';
  }

  return 'https://docs.google.com/document/d/' . $docId . '/edit';
}

function sopNormalizeSheetReference($value) {
  $value = sopExtractReferenceUrl($value);
  if ($value === '') {
    return '';
  }

  if (!preg_match('~^https?://~i', $value)) {
    throw new RuntimeException('Paste an Excel embed link, workbook link, or iframe embed code.');
  }

  return $value;
}

function sopExcelEmbedUrl($value) {
  $value = sopExtractReferenceUrl($value);
  if ($value === '' || !preg_match('~^https?://~i', $value)) {
    return '';
  }

  $parts = @parse_url($value);
  if (!is_array($parts)) {
    return '';
  }

  $host = strtolower((string) ($parts['host'] ?? ''));
  $path = strtolower((string) ($parts['path'] ?? ''));

  if (
    strpos($host, 'officeapps.live.com') !== false ||
    strpos($host, 'excel.officeapps.live.com') !== false ||
    strpos($path, 'xlembed.aspx') !== false
  ) {
    return $value;
  }

  if (strpos($host, 'onedrive.live.com') !== false && strpos($path, '/embed') !== false) {
    return $value;
  }

  if (preg_match('/\.xlsx($|[?#])/i', $value)) {
    return 'https://view.officeapps.live.com/op/embed.aspx?src=' . rawurlencode($value);
  }

  return '';
}

function sopExcelOpenUrl($value) {
  $value = sopExtractReferenceUrl($value);
  if ($value === '' || !preg_match('~^https?://~i', $value)) {
    return '';
  }

  $parts = @parse_url($value);
  if (!is_array($parts)) {
    return $value;
  }

  $query = [];
  parse_str((string) ($parts['query'] ?? ''), $query);

  foreach (['WOPISrc', 'src'] as $key) {
    $candidate = trim((string) ($query[$key] ?? ''));
    if ($candidate !== '') {
      return html_entity_decode($candidate, ENT_QUOTES, 'UTF-8');
    }
  }

  return $value;
}

function sopFormatTimestamp($value) {
  $value = trim((string) $value);
  if ($value === '') {
    return 'Not saved yet';
  }

  try {
    $date = new DateTimeImmutable($value);
  } catch (Exception $exception) {
    return 'Not saved yet';
  }

  return $date->setTimezone(new DateTimeZone('America/New_York'))->format('M j, Y g:i A');
}

function sopAddDepartment($title, $summary) {
  $title = trim((string) $title);
  $summary = trim((string) $summary);

  if ($title === '') {
    throw new RuntimeException('Department title is required.');
  }

  $data = sopReadData();
  $departments = is_array($data['departments'] ?? null) ? $data['departments'] : [];
  $taken = [];
  foreach ($departments as $department) {
    if (!empty($department['id'])) {
      $taken[$department['id']] = true;
    }
  }

  $departmentId = sopUniqueId($title, $taken, 'department');
  $departments[] = [
    'id' => $departmentId,
    'title' => $title,
    'summary' => $summary !== '' ? $summary : sopDefaultDepartmentSummary($title),
    'color' => sopColorAt(count($departments)),
    'pages' => sopBuildSeedPages($title),
    'updated_at' => gmdate('c'),
  ];

  $data['departments'] = $departments;
  if (!sopSaveData($data)) {
    throw new RuntimeException('Unable to save the new department.');
  }

  return $departmentId;
}

function sopUpdateDepartment($departmentId, $title, $summary) {
  $departmentId = trim((string) $departmentId);
  $title = trim((string) $title);
  $summary = trim((string) $summary);

  if ($departmentId === '') {
    throw new RuntimeException('The selected department was not found.');
  }

  if ($title === '') {
    throw new RuntimeException('Department title is required.');
  }

  $data = sopReadData();
  foreach ($data['departments'] as $departmentIndex => $department) {
    if (($department['id'] ?? '') !== $departmentId) {
      continue;
    }

    $existingTitle = trim((string) ($department['title'] ?? ''));
    $existingSummary = trim((string) ($department['summary'] ?? ''));
    $summary = trim((string) $summary);
    if (
      $summary === '' ||
      $summary === sopDefaultDepartmentSummary($existingTitle) ||
      $summary === sopDefaultDepartmentSummary($title)
    ) {
      $summary = sopDefaultDepartmentSummary($title);
    }

    $data['departments'][$departmentIndex]['title'] = $title;
    $data['departments'][$departmentIndex]['summary'] = $summary;
    $data['departments'][$departmentIndex]['updated_at'] = gmdate('c');

    if (!sopSaveData($data)) {
      throw new RuntimeException('Unable to save the department changes.');
    }

    return true;
  }

  throw new RuntimeException('The selected department was not found.');
}

function sopCreateDepartmentDraft() {
  $data = sopReadData();
  $departments = is_array($data['departments'] ?? null) ? $data['departments'] : [];
  return sopAddDepartment(sopDraftDepartmentTitle($departments), '');
}

function sopDeleteDepartment($departmentId) {
  $departmentId = trim((string) $departmentId);

  if ($departmentId === '') {
    throw new RuntimeException('The selected department was not found.');
  }

  $data = sopReadData();
  $departments = array_values(is_array($data['departments'] ?? null) ? $data['departments'] : []);
  if (count($departments) <= 1) {
    throw new RuntimeException('Add another top tab before deleting this one.');
  }

  foreach ($departments as $departmentIndex => $department) {
    if (($department['id'] ?? '') !== $departmentId) {
      continue;
    }

    array_splice($departments, $departmentIndex, 1);
    $data['departments'] = array_values($departments);

    if (!sopSaveData($data)) {
      throw new RuntimeException('Unable to delete the top tab.');
    }

    $nextIndex = min($departmentIndex, count($departments) - 1);
    $nextDepartment = $departments[$nextIndex] ?? $departments[0] ?? null;
    [$nextPage] = sopResolvePage($nextDepartment ?? [], '');

    return [
      'department_id' => (string) ($nextDepartment['id'] ?? ''),
      'page_id' => (string) ($nextPage['id'] ?? ''),
    ];
  }

  throw new RuntimeException('The selected department was not found.');
}

function sopAddPage($departmentId, $title, $summary, $docUrl, $sheetUrl = '') {
  $title = trim((string) $title);
  $summary = trim((string) $summary);
  $docUrl = sopNormalizeDocReference($docUrl);
  $sheetUrl = sopNormalizeSheetReference($sheetUrl);

  if ($title === '') {
    throw new RuntimeException('Side tab title is required.');
  }

  $data = sopReadData();
  foreach ($data['departments'] as $departmentIndex => $department) {
    if (($department['id'] ?? '') !== $departmentId) {
      continue;
    }

    $pages = is_array($department['pages'] ?? null) ? $department['pages'] : [];
    $taken = [];
    foreach ($pages as $page) {
      if (!empty($page['id'])) {
        $taken[$page['id']] = true;
      }
    }

    $pageId = sopUniqueId($title, $taken, 'page');
    $pages[] = [
      'id' => $pageId,
      'title' => $title,
      'summary' => $summary,
      'color' => sopColorAt(count($pages)),
      'doc_url' => $docUrl,
      'sheet_url' => $sheetUrl,
      'updated_at' => gmdate('c'),
    ];

    $data['departments'][$departmentIndex]['pages'] = $pages;
    $data['departments'][$departmentIndex]['updated_at'] = gmdate('c');
    if (!sopSaveData($data)) {
      throw new RuntimeException('Unable to save the new side tab.');
    }

    return $pageId;
  }

  throw new RuntimeException('The selected department was not found.');
}

function sopCreatePageDraft($departmentId) {
  $departmentId = trim((string) $departmentId);

  if ($departmentId === '') {
    throw new RuntimeException('Select a department before adding a side tab.');
  }

  $data = sopReadData();
  foreach ($data['departments'] as $department) {
    if (($department['id'] ?? '') !== $departmentId) {
      continue;
    }

    $pages = is_array($department['pages'] ?? null) ? $department['pages'] : [];
    return sopAddPage($departmentId, sopDraftPageTitle($pages), '', '');
  }

  throw new RuntimeException('The selected department was not found.');
}

function sopDeletePage($departmentId, $pageId) {
  $departmentId = trim((string) $departmentId);
  $pageId = trim((string) $pageId);

  if ($departmentId === '' || $pageId === '') {
    throw new RuntimeException('The selected page was not found.');
  }

  $data = sopReadData();
  foreach ($data['departments'] as $departmentIndex => $department) {
    if (($department['id'] ?? '') !== $departmentId) {
      continue;
    }

    $pages = array_values(is_array($department['pages'] ?? null) ? $department['pages'] : []);
    if (count($pages) <= 1) {
      throw new RuntimeException('Add another side tab before deleting this one.');
    }

    foreach ($pages as $pageIndex => $page) {
      if (($page['id'] ?? '') !== $pageId) {
        continue;
      }

      array_splice($pages, $pageIndex, 1);
      $data['departments'][$departmentIndex]['pages'] = array_values($pages);
      $data['departments'][$departmentIndex]['updated_at'] = gmdate('c');

      if (!sopSaveData($data)) {
        throw new RuntimeException('Unable to delete the side tab.');
      }

      $nextIndex = min($pageIndex, count($pages) - 1);
      $nextPage = $pages[$nextIndex] ?? $pages[0] ?? null;

      return [
        'department_id' => $departmentId,
        'page_id' => (string) ($nextPage['id'] ?? ''),
      ];
    }
  }

  throw new RuntimeException('The selected page was not found.');
}

function sopUpdatePage($departmentId, $pageId, $title, $summary, $docUrl) {
  $title = trim((string) $title);
  $summary = trim((string) $summary);
  $docUrl = sopNormalizeDocReference($docUrl);

  if ($title === '') {
    throw new RuntimeException('Page title is required.');
  }

  $data = sopReadData();
  foreach ($data['departments'] as $departmentIndex => $department) {
    if (($department['id'] ?? '') !== $departmentId) {
      continue;
    }

    foreach ($department['pages'] as $pageIndex => $page) {
      if (($page['id'] ?? '') !== $pageId) {
        continue;
      }

      $data['departments'][$departmentIndex]['pages'][$pageIndex]['title'] = $title;
      $data['departments'][$departmentIndex]['pages'][$pageIndex]['summary'] = $summary;
      $data['departments'][$departmentIndex]['pages'][$pageIndex]['doc_url'] = $docUrl;
      $data['departments'][$departmentIndex]['pages'][$pageIndex]['updated_at'] = gmdate('c');
      $data['departments'][$departmentIndex]['updated_at'] = gmdate('c');

      if (!sopSaveData($data)) {
        throw new RuntimeException('Unable to save the page changes.');
      }

      return true;
    }
  }

  throw new RuntimeException('The selected page was not found.');
}

function sopUpdatePageDocUrl($departmentId, $pageId, $docUrl) {
  $departmentId = trim((string) $departmentId);
  $pageId = trim((string) $pageId);
  $docUrl = sopNormalizeDocReference($docUrl);

  if ($departmentId === '' || $pageId === '') {
    throw new RuntimeException('Select a page before saving a Google Docs link.');
  }

  $data = sopReadData();
  foreach ($data['departments'] as $departmentIndex => $department) {
    if (($department['id'] ?? '') !== $departmentId) {
      continue;
    }

    foreach ($department['pages'] as $pageIndex => $page) {
      if (($page['id'] ?? '') !== $pageId) {
        continue;
      }

      $data['departments'][$departmentIndex]['pages'][$pageIndex]['doc_url'] = $docUrl;
      $data['departments'][$departmentIndex]['pages'][$pageIndex]['updated_at'] = gmdate('c');
      $data['departments'][$departmentIndex]['updated_at'] = gmdate('c');

      if (!sopSaveData($data)) {
        throw new RuntimeException('Unable to save the Google Docs link.');
      }

      return true;
    }
  }

  throw new RuntimeException('The selected page was not found.');
}

function sopUpdatePageLinks($departmentId, $pageId, $docUrl, $sheetUrl) {
  $departmentId = trim((string) $departmentId);
  $pageId = trim((string) $pageId);
  $docUrl = sopNormalizeDocReference($docUrl);
  $sheetUrl = sopNormalizeSheetReference($sheetUrl);

  if ($departmentId === '' || $pageId === '') {
    throw new RuntimeException('Select a page before saving the live links.');
  }

  $data = sopReadData();
  foreach ($data['departments'] as $departmentIndex => $department) {
    if (($department['id'] ?? '') !== $departmentId) {
      continue;
    }

    foreach ($department['pages'] as $pageIndex => $page) {
      if (($page['id'] ?? '') !== $pageId) {
        continue;
      }

      $data['departments'][$departmentIndex]['pages'][$pageIndex]['doc_url'] = $docUrl;
      $data['departments'][$departmentIndex]['pages'][$pageIndex]['sheet_url'] = $sheetUrl;
      $data['departments'][$departmentIndex]['pages'][$pageIndex]['updated_at'] = gmdate('c');
      $data['departments'][$departmentIndex]['updated_at'] = gmdate('c');

      if (!sopSaveData($data)) {
        throw new RuntimeException('Unable to save the page links.');
      }

      return true;
    }
  }

  throw new RuntimeException('The selected page was not found.');
}

function sopUpsertUser($username, $displayName, $role, $password) {
  $username = sopNormalizeUsername($username);
  $displayName = trim((string) $displayName);
  $password = (string) $password;
  $role = sopNormalizeRole($role);

  if ($username === '') {
    throw new RuntimeException('User ID is required.');
  }

  if ($displayName === '') {
    $displayName = ucfirst($username);
  }

  $users = sopReadUsers();
  $existing = is_array($users[$username] ?? null) ? $users[$username] : null;

  if ($existing === null && trim($password) === '') {
    throw new RuntimeException('A password is required when creating a new user.');
  }

  $existingRole = sopNormalizeRole($existing['role'] ?? 'employee');
  if ($existing !== null && $existingRole === 'manager' && $role !== 'manager' && sopManagerCount($users) <= 1) {
    throw new RuntimeException('Another manager user needs to be added first.');
  }

  $users[$username] = [
    'display_name' => $displayName,
    'role' => $role,
    'salt_hex' => (string) ($existing['salt_hex'] ?? ''),
    'iterations' => (int) ($existing['iterations'] ?? 120000),
    'hash_hex' => (string) ($existing['hash_hex'] ?? ''),
  ];

  if (trim($password) !== '') {
    $users[$username] = array_merge($users[$username], sopHashPassword($password));
  }

  if (!sopSaveUsers($users)) {
    throw new RuntimeException('Unable to save the user list.');
  }

  return $username;
}

function sopDeleteUser($username) {
  $username = sopNormalizeUsername($username);
  if ($username === '') {
    throw new RuntimeException('User ID is required.');
  }

  $users = sopReadUsers();
  $existing = is_array($users[$username] ?? null) ? $users[$username] : null;
  if ($existing === null) {
    throw new RuntimeException('The selected user was not found.');
  }

  if (sopNormalizeRole($existing['role'] ?? 'employee') === 'manager' && sopManagerCount($users) <= 1) {
    throw new RuntimeException('Another manager user needs to be added first.');
  }

  unset($users[$username]);

  if (!sopSaveUsers($users)) {
    throw new RuntimeException('Unable to delete the selected user.');
  }

  return true;
}
