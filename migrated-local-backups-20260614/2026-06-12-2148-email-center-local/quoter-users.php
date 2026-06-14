<?php

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lead-storage.php';

function qtUsersStorageDir() {
	return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'ogm-quoter';
}

function qtUsersFile() {
	return qtUsersStorageDir() . DIRECTORY_SEPARATOR . 'users.json';
}

function qtUsersSeedFile() {
	return qtUsersStorageDir() . DIRECTORY_SEPARATOR . 'users.seed.json';
}

function qtUserAuditFile() {
	return qtUsersStorageDir() . DIRECTORY_SEPARATOR . 'user-audit.log';
}

function qtAllRoles() {
	return ['general_manager', 'division_manager', 'sales', 'associate'];
}

function qtRoleLabels() {
	return [
		'general_manager' => 'General Manager',
		'division_manager' => 'Division Manager',
		'sales' => 'Sales',
		'associate' => 'Associate',
	];
}

function qtPermissionLabels() {
	return [
		'timecards' => 'Timecards',
		'pto' => 'PTO',
		'employee_board' => 'Employee board / message board',
		'sop_board' => 'SOP board',
		'employee_card' => 'Employee card / profile',
		'marketplace_community' => 'Marketplace / community',
		'report_editing' => 'Report editing',
		'user_admin' => 'User/admin management',
	];
}

function qtAllPermissions() {
	return array_keys(qtPermissionLabels());
}

function qtNormalizePermissionList($permissions) {
	$allowed = array_fill_keys(qtAllPermissions(), true);
	$out = [];
	foreach ((array) $permissions as $permission) {
		$permission = strtolower(trim((string) $permission));
		if ($permission !== '' && isset($allowed[$permission])) {
			$out[$permission] = true;
		}
	}
	return array_keys($out);
}

function qtDefaultPermissionsForRole($role) {
	$role = qtNormalizeRole($role);
	if ($role === 'general_manager') {
		return qtAllPermissions();
	}
	if ($role === 'division_manager') {
		return ['timecards', 'pto', 'employee_board', 'sop_board', 'employee_card', 'marketplace_community', 'report_editing', 'user_admin'];
	}
	if ($role === 'sales') {
		return ['timecards', 'employee_card', 'marketplace_community'];
	}
	return ['timecards', 'employee_card'];
}

function qtCapabilityPermissionMap() {
	return [
		'timecards' => 'timecards',
		'pto' => 'pto',
		'employee_board' => 'employee_board',
		'messages' => 'employee_board',
		'messages_limited' => 'employee_board',
		'sop_read' => 'sop_board',
		'sop_edit' => 'sop_board',
		'employee_card' => 'employee_card',
		'marketplace' => 'marketplace_community',
		'community' => 'marketplace_community',
		'marketplace_community' => 'marketplace_community',
		'reports' => 'report_editing',
		'report_editing' => 'report_editing',
		'user_admin' => 'user_admin',
	];
}

function qtDefaultUserProfile($username) {
	$key = strtolower(qtNormalizeUsername($username));
	$profiles = [
		'sed' => [
			'email' => 'sed@oliveglassandmarble.com',
			'title' => 'Owner / General Manager',
			'department' => 'Management',
			'division' => 'Company-wide',
			'manager_notes' => 'Owner/general manager; broad company operations.',
		],
		'meo' => [
			'email' => 'win@oliveglassandmarble.com',
			'title' => 'Owner / General Manager',
			'department' => 'Management / HR / Finance',
			'division' => 'HR and finance',
			'manager_notes' => 'Mary-Erwin is HR, finance, owner, and general manager.',
		],
		'brennan' => [
			'email' => 'brennan@oliveglassandmarble.com',
			'title' => 'Divisional Manager',
			'department' => 'Glass / Showers',
			'division' => 'Glass and showers',
			'manager_notes' => 'Divisional manager over glass and showers.',
		],
		'tanya' => [
			'email' => 'Tanya@OliveGlassandMarble.com',
			'title' => 'Divisional Manager',
			'department' => 'IT / Creative / Marketing / Sales / Finance',
			'division' => 'IT, graphic design, marketing, photography, art direction, sales support, finance support',
			'manager_notes' => 'Divisional manager over IT, graphic design, marketing, photography, art direction; also sales and finance support.',
		],
	];
	return $profiles[$key] ?? [];
}

/** Usernames are case-sensitive (Sed ≠ sed). Only trim and strip unsafe characters. */
function qtNormalizeUsername($username) {
	$username = trim((string) $username);
	$username = preg_replace('/[^a-zA-Z0-9._-]/', '', $username);
	return substr((string) $username, 0, 48);
}

function qtCanonicalUsernameMap() {
	return [
		'sed' => 'Sed',
		'meo' => 'Meo',
		'general_manager' => 'General_Manager',
		'brennan' => 'Brennan',
		'tanya' => 'Tanya',
		'division_manager' => 'Division_Manager',
		'sales' => 'Sales',
		'associate' => 'Associate',
	];
}

function qtMigrateUserKeyCasing($users) {
	if (!is_array($users)) {
		return [];
	}
	$map = qtCanonicalUsernameMap();
	$out = [];
	foreach ($users as $key => $user) {
		if (!is_array($user)) {
			continue;
		}
		$keyStr = (string) $key;
		$canonical = $map[strtolower($keyStr)] ?? $keyStr;
		$canonical = qtNormalizeUsername($canonical);
		if ($canonical === '') {
			continue;
		}
		if (!isset($out[$canonical])) {
			$out[$canonical] = $user;
		}
	}
	return $out;
}

function qtLookupUser($users, $login) {
	$login = trim((string) $login);
	if ($login === '' || !is_array($users)) {
		return null;
	}
	if (!isset($users[$login]) || !is_array($users[$login])) {
		return null;
	}
	return [
		'username' => $login,
		'user' => $users[$login],
	];
}

function qtNormalizeRole($role) {
	$role = strtolower(trim((string) $role));
	$role = str_replace(['-', '/', '&'], ' ', $role);
	$role = preg_replace('/\s+/', ' ', $role);
	if (in_array($role, ['owner', 'gm', 'general manager', 'owner general manager', 'general manager owner'], true)) {
		return 'general_manager';
	}
	if (in_array($role, ['division manager', 'divisional manager', 'department manager'], true)) {
		return 'division_manager';
	}
	return in_array($role, qtAllRoles(), true) ? $role : '';
}

function qtRoleLabel($role) {
	$labels = qtRoleLabels();
	$role = qtNormalizeRole($role);
	return $labels[$role] ?? $role;
}

function qtHashPassword($password) {
	return ['password_hash' => password_hash((string) $password, PASSWORD_DEFAULT)];
}

function qtVerifyPassword($password, $user) {
	$hash = trim((string) ($user['password_hash'] ?? ''));
	if ($hash === '') {
		return false;
	}
	return password_verify((string) $password, $hash);
}

function qtNormalizeUserRecord($username, $user) {
	$username = qtNormalizeUsername($username);
	if ($username === '' || !is_array($user)) {
		return null;
	}

	$role = qtNormalizeRole($user['role'] ?? '');
	if ($role === '') {
		return null;
	}

	$displayName = trim((string) ($user['display_name'] ?? ''));
	if ($displayName === '') {
		$displayName = ucfirst($username);
	}

	$hash = trim((string) ($user['password_hash'] ?? ''));
	if ($hash === '') {
		return null;
	}

	$active = !isset($user['active']) || !empty($user['active']);
	$messageAlerts = !empty($user['message_alerts']);
	$permissions = qtNormalizePermissionList($user['permissions'] ?? qtDefaultPermissionsForRole($role));
	$employeeId = trim((string) ($user['employee_id'] ?? ''));
	if ($employeeId === '') {
		$employeeId = $username;
	}
	$profileDefaults = qtDefaultUserProfile($username);

	return [
		'display_name' => $displayName,
		'role' => $role,
		'email' => trim((string) ($user['email'] ?? ($profileDefaults['email'] ?? ''))),
		'title' => trim((string) ($user['title'] ?? ($profileDefaults['title'] ?? ''))),
		'department' => trim((string) ($user['department'] ?? ($profileDefaults['department'] ?? ''))),
		'division' => trim((string) ($user['division'] ?? ($profileDefaults['division'] ?? ''))),
		'manager_notes' => trim((string) ($user['manager_notes'] ?? ($profileDefaults['manager_notes'] ?? ''))),
		'employee_id' => $employeeId,
		'active' => $active,
		'message_alerts' => $messageAlerts,
		'permissions' => $permissions,
		'password_hash' => $hash,
		'created_at' => trim((string) ($user['created_at'] ?? gmdate('c'))),
		'updated_at' => trim((string) ($user['updated_at'] ?? gmdate('c'))),
	];
}

function qtNormalizeUserRecords($users) {
	$normalized = [];
	foreach ((array) $users as $key => $user) {
		$username = is_string($key) ? $key : ($user['username'] ?? '');
		$row = qtNormalizeUserRecord($username, is_array($user) ? $user : []);
		if ($row !== null) {
			$normalized[qtNormalizeUsername($username)] = $row;
		}
	}
	return $normalized;
}

function qtDefaultSeedUsers() {
	$now = gmdate('c');
	$rows = [
		['Meo', 'G Hunter Olive (HO)', 'general_manager', 'MEO2026'],
		['Sed', 'G Sedberry Olive (SO)', 'general_manager', '714$$'],
		['General_Manager', 'General Manager', 'general_manager', 'MEO@HUNTER1975!!!'],
		['Brennan', 'Brennan Binkley (BB)', 'division_manager', '#Pepper1'],
		['Tanya', 'Tanya Wadkins (TW)', 'division_manager', 'OGM714!!'],
		['Division_Manager', 'Division Manager', 'division_manager', 'Ansel14AdamsOGM'],
		['Sales', 'Sales', 'sales', 'OGM@2026!!!'],
		['Associate', 'Associate', 'associate', 'OGM28305!!!'],
	];
	$users = [];
	foreach ($rows as $row) {
		$users[$row[0]] = array_merge([
			'display_name' => $row[1],
			'role' => $row[2],
			'employee_id' => $row[0],
			'permissions' => qtDefaultPermissionsForRole($row[2]),
			'active' => true,
			'created_at' => $now,
			'updated_at' => $now,
		], qtHashPassword($row[3]));
	}
	return $users;
}

function qtLoadSeedUsers() {
	$seedPath = qtUsersSeedFile();
	if (is_file($seedPath)) {
		$stored = ogmReadJsonFile($seedPath, []);
		$list = isset($stored['users']) && is_array($stored['users']) ? $stored['users'] : $stored;
		$built = [];
		$now = gmdate('c');
		foreach ((array) $list as $key => $user) {
			if (!is_array($user)) {
				continue;
			}
			$username = is_string($key) ? $key : ($user['username'] ?? '');
			$username = qtNormalizeUsername($username);
			if ($username === '') {
				continue;
			}
			$plain = trim((string) ($user['password'] ?? ''));
			if ($plain !== '') {
				$user = array_merge($user, qtHashPassword($plain));
			}
			$user['created_at'] = $user['created_at'] ?? $now;
			$user['updated_at'] = $user['updated_at'] ?? $now;
			$user['active'] = $user['active'] ?? true;
			$row = qtNormalizeUserRecord($username, $user);
			if ($row !== null) {
				$built[$username] = $row;
			}
		}
		if ($built) {
			return $built;
		}
	}
	return qtDefaultSeedUsers();
}

function qtBootstrapUsersIfNeeded() {
	if (is_file(qtUsersFile())) {
		$stored = ogmReadJsonFile(qtUsersFile(), []);
		$list = isset($stored['users']) && is_array($stored['users']) ? $stored['users'] : [];
		if (qtNormalizeUserRecords($list)) {
			return true;
		}
	}
	return qtSaveUsers(qtLoadSeedUsers());
}

function qtReadUsers() {
	qtBootstrapUsersIfNeeded();
	$stored = ogmReadJsonFile(qtUsersFile(), []);
	$list = isset($stored['users']) && is_array($stored['users']) ? $stored['users'] : [];
	$list = qtMigrateUserKeyCasing($list);
	$users = qtNormalizeUserRecords($list);
	$schemaVersion = (int) ($stored['schema_version'] ?? 1);
	if (!isset($users['Sed']) || !isset($users['Meo'])) {
		$users = qtNormalizeUserRecords(qtDefaultSeedUsers());
	}
	if ($schemaVersion < 3) {
		qtSaveUsers($users);
	}
	return $users;
}

function qtSaveUsers($users) {
	$users = qtNormalizeUserRecords($users);
	if (!$users) {
		return false;
	}
	$now = gmdate('c');
	foreach ($users as $username => $user) {
		$users[$username]['updated_at'] = $now;
	}
	return ogmWriteJsonFile(qtUsersFile(), [
		'schema_version' => 3,
		'users' => $users,
		'updated_at' => $now,
	]);
}

function qtHasUsers() {
	return (bool) qtReadUsers();
}

function qtProtectedUsernames() {
	return ['General_Manager', 'Division_Manager', 'Sales', 'Associate'];
}

function qtCountByRole($users, $role) {
	$count = 0;
	foreach ((array) $users as $user) {
		if (is_array($user) && ($user['role'] ?? '') === $role) {
			$count += 1;
		}
	}
	return $count;
}

function qtAuthenticateUser($username, $password) {
	$login = trim((string) $username);
	$password = (string) $password;
	if ($login === '' || $password === '') {
		return false;
	}

	$found = qtLookupUser(qtReadUsers(), $login);
	if ($found === null) {
		return false;
	}

	$user = $found['user'];
	if (empty($user['active']) || !qtVerifyPassword($password, $user)) {
		return false;
	}

	$canonical = $found['username'];

	return [
		'username' => $canonical,
		'display_name' => trim((string) ($user['display_name'] ?? $canonical)),
		'role' => (string) ($user['role'] ?? ''),
	];
}

function qtAppendUserAudit($action, $targetUsername, $details = '') {
	qtStartSession();
	$actor = trim((string) ($_SESSION['qt_username'] ?? ''));
	$line = gmdate('c') . "\t" . $action . "\t" . $actor . "\t" . $targetUsername . "\t" . $details . PHP_EOL;
	@file_put_contents(qtUserAuditFile(), $line, FILE_APPEND | LOCK_EX);
}

function qtRoleCapabilities($role) {
	$role = qtNormalizeRole($role);
	$map = [
		'associate' => [
			'sign_in', 'quoter', 'customers', 'jobs', 'calendar', 'sop_read', 'messages_limited',
		],
		'sales' => [
			'sign_in', 'quoter', 'customers', 'jobs', 'calendar', 'sop_read',
			'messages', 'invoices', 'reports', 'leads',
		],
		'division_manager' => [
			'sign_in', 'quoter', 'customers', 'jobs', 'calendar', 'sop_read', 'sop_edit',
			'messages', 'invoices', 'reports', 'leads', 'user_admin',
			'catalog_write', 'website_write', 'sensitive_admin',
			'timecards', 'pto', 'employee_board', 'employee_card', 'marketplace_community', 'report_editing',
		],
		'general_manager' => ['all'],
	];
	return $map[$role] ?? [];
}

function qtCan($capability) {
	$capability = trim((string) $capability);
	if ($capability === '') {
		return false;
	}
	if (!function_exists('qtIsLoggedIn') || !qtIsLoggedIn()) {
		return false;
	}

	$role = qtNormalizeRole($_SESSION['qt_role'] ?? '');
	if ($role === 'general_manager') {
		return true;
	}

	$caps = qtRoleCapabilities($role);
	if (in_array($capability, $caps, true)) {
		return true;
	}

	$username = qtNormalizeUsername((string) ($_SESSION['qt_username'] ?? ''));
	if ($username === '') {
		return false;
	}
	$users = qtReadUsers();
	if ((!isset($users[$username]) || empty($users[$username]['active'])) && !empty($_SESSION['qt_display_name'])) {
		$displayKey = strtolower(trim((string) $_SESSION['qt_display_name']));
		if (str_contains($displayKey, 'sedberry') || str_contains($displayKey, ' sed ') || $displayKey === 'sed') {
			$username = 'Sed';
		} elseif (str_contains($displayKey, 'hunter') || str_contains($displayKey, ' meo ') || $displayKey === 'meo') {
			$username = 'Meo';
		}
		if (isset($users[$username]) && !empty($users[$username]['active'])) {
			$_SESSION['qt_username'] = $username;
		}
	}
	if (!isset($users[$username]) || empty($users[$username]['active'])) {
		return false;
	}
	$userRole = qtNormalizeRole($users[$username]['role'] ?? '');
	if ($userRole !== '' && $userRole !== $role) {
		$_SESSION['qt_role'] = $userRole;
		$role = $userRole;
		if ($role === 'general_manager') {
			return true;
		}
		$caps = qtRoleCapabilities($role);
		if (in_array($capability, $caps, true)) {
			return true;
		}
	}
	$permission = qtCapabilityPermissionMap()[$capability] ?? $capability;
	$permissions = qtNormalizePermissionList($users[$username]['permissions'] ?? []);
	return in_array($permission, $permissions, true);
}

function qtRequireCapability($capability) {
	if (qtCan($capability)) {
		return;
	}
	http_response_code(403);
	header('Content-Type: text/plain; charset=UTF-8');
	echo 'Access denied.';
	exit;
}

function qtRequireUserAdmin() {
	qtRequireCapability('user_admin');
}

function qtCurrentRole() {
	if (!function_exists('qtStartSession')) {
		return '';
	}
	qtStartSession();
	return qtNormalizeRole($_SESSION['qt_role'] ?? '');
}

function qtCsrfToken() {
	qtStartSession();
	if (empty($_SESSION['qt_csrf'])) {
		$_SESSION['qt_csrf'] = bin2hex(random_bytes(24));
	}
	return (string) $_SESSION['qt_csrf'];
}

function qtVerifyCsrfToken($token) {
	qtStartSession();
	$expected = (string) ($_SESSION['qt_csrf'] ?? '');
	$token = trim((string) $token);
	return $expected !== '' && $token !== '' && hash_equals($expected, $token);
}
