<?php

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lead-storage.php';

function tsStorageDir() {
	return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'ogm-quoter' . DIRECTORY_SEPARATOR . 'timesheet';
}

function tsRosterFile() {
	return tsStorageDir() . DIRECTORY_SEPARATOR . 'roster.json';
}

function tsPunchesFile() {
	return tsStorageDir() . DIRECTORY_SEPARATOR . 'punches.json';
}

function tsAdjustmentsFile() {
	return tsStorageDir() . DIRECTORY_SEPARATOR . 'week-adjustments.json';
}

function tsTimezone() {
	return new DateTimeZone('America/New_York');
}

function tsNormalizeId($name) {
	$name = trim((string) $name);
	$name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
	$name = trim($name, '_');
	return substr($name, 0, 48);
}

function tsNormalizeEmploymentType($type) {
	$type = strtolower(trim((string) $type));
	$type = str_replace(['-', ' '], '_', $type);
	return $type === 'part_time' ? 'part_time' : 'full_time';
}

function tsDefaultShopNames() {
	return [
		['id' => 'Elvin', 'display_name' => 'Elvin', 'pto_hours' => 40],
		['id' => 'JR', 'display_name' => 'JR', 'pto_hours' => 40],
		['id' => 'Reggie', 'display_name' => 'Reggie', 'pto_hours' => 40],
		['id' => 'Dante', 'display_name' => 'Dante', 'pto_hours' => 40],
		['id' => 'Carlie', 'display_name' => 'Carlie', 'pto_hours' => 40],
		['id' => 'Garry', 'display_name' => 'Garry', 'pto_hours' => 40],
		['id' => 'Austen', 'display_name' => 'Austen', 'pto_hours' => 40],
		['id' => 'Tanya', 'display_name' => 'Tanya Wadkins (TW)', 'pto_hours' => 48, 'quoter_username' => 'Tanya'],
	];
}

/** Quoter login accounts that must not appear on the shop TV clock. */
function tsClockExcludedQuoterUsernames() {
	return [
		'General_Manager',
		'Division_Manager',
		'Sales',
		'Associate',
		'Meo',
	];
}

/** Roster employee ids hidden from the TV clock (reports/timesheets may still include them). */
function tsClockExcludedEmployeeIds() {
	return array_merge(
		['Grant', 'General_Manager', 'Division_Manager', 'Sales', 'Associate', 'Meo'],
		array_keys(tsEmployeeIdAliases())
	);
}

/** When the same display name exists twice, keep this id. */
function tsCanonicalIdForDisplayName($displayName) {
	$key = strtolower(trim((string) $displayName));
	$map = [
		'g sedberry olive (so)' => 'Sed',
		'tanya wadkins (tw)' => 'Tanya',
		'brennan binkley (bb)' => 'Brennan',
		'g hunter olive (ho)' => 'Meo',
		'general manager' => 'General_Manager',
		'division manager' => 'Division_Manager',
	];
	return $map[$key] ?? null;
}

function tsEmployeeShowOnClock($emp) {
	if (!is_array($emp) || empty($emp['active'])) {
		return false;
	}
	$id = tsNormalizeId($emp['id'] ?? '');
	$display = trim((string) ($emp['display_name'] ?? ''));
	$username = trim((string) ($emp['quoter_username'] ?? ''));

	if ($id === '' || in_array($id, tsClockExcludedEmployeeIds(), true)) {
		return false;
	}
	if ($username !== '' && in_array($username, tsClockExcludedQuoterUsernames(), true)) {
		return false;
	}

	$roleLabels = ['General Manager', 'Division Manager', 'Sales', 'Associate'];
	if (in_array($display, $roleLabels, true)) {
		return false;
	}
	if (stripos($display, 'Hunter Olive') !== false || preg_match('/^G_Hunter/i', $id)) {
		return false;
	}

	$canonical = tsCanonicalIdForDisplayName($display);
	if ($canonical !== null && $id !== $canonical) {
		return false;
	}

	return true;
}

function tsPickPreferredEmployeeId(array $ids, $employees) {
	$prefer = ['Tanya', 'Brennan', 'Sed', 'Elvin', 'JR', 'Reggie', 'Dante', 'Carlie', 'Garry', 'Austen'];
	foreach ($prefer as $pid) {
		if (in_array($pid, $ids, true)) {
			return $pid;
		}
	}
	usort($ids, static function ($a, $b) use ($employees) {
		$qa = !empty($employees[$a]['quoter_username']) ? 0 : 1;
		$qb = !empty($employees[$b]['quoter_username']) ? 0 : 1;
		if ($qa !== $qb) {
			return $qa - $qb;
		}
		return strlen($a) - strlen($b);
	});
	return $ids[0] ?? '';
}

function tsRemapEmployeeIdInData($fromId, $toId) {
	if ($fromId === '' || $toId === '' || $fromId === $toId) {
		return;
	}
	$punches = tsReadPunches();
	$changed = false;
	foreach ($punches as $i => $p) {
		if (($p['employee_id'] ?? '') === $fromId) {
			$punches[$i]['employee_id'] = $toId;
			$changed = true;
		}
	}
	if ($changed) {
		tsSavePunches($punches);
	}

	$entries = tsReadAdjustments();
	$adjChanged = false;
	$newEntries = [];
	foreach ($entries as $key => $row) {
		$parts = explode('|', $key, 2);
		$eid = $parts[0] ?? '';
		$date = $parts[1] ?? ($row['date'] ?? '');
		if ($eid === $fromId) {
			$eid = $toId;
			$row['employee_id'] = $toId;
			$adjChanged = true;
		}
		$newEntries[$eid . '|' . $date] = $row;
	}
	if ($adjChanged) {
		tsSaveAdjustments(array_values($newEntries));
	}
}

function tsDedupeRosterEmployees(array &$employees) {
	$groups = [];
	foreach ($employees as $id => $emp) {
		$dn = strtolower(trim((string) ($emp['display_name'] ?? $id)));
		if ($dn === '') {
			continue;
		}
		$groups[$dn][] = $id;
	}
	$changed = false;
	foreach ($groups as $ids) {
		if (count($ids) < 2) {
			continue;
		}
		$keep = tsPickPreferredEmployeeId($ids, $employees);
		foreach ($ids as $id) {
			if ($id === $keep) {
				continue;
			}
			tsRemapEmployeeIdInData($id, $keep);
			unset($employees[$id]);
			$changed = true;
		}
	}
	return $changed;
}

/** Remove shop-clock-only junk (role logins, Grant, Hunter dupes) from stored roster. */
function tsRemoveJunkRosterEntries(array &$employees) {
	$junkIds = ['Grant', 'General_Manager', 'Division_Manager', 'Sales', 'Associate', 'Meo'];
	$toRemove = [];
	foreach ($employees as $id => $emp) {
		if (in_array($id, $junkIds, true)) {
			$toRemove[] = $id;
			continue;
		}
		$display = trim((string) ($emp['display_name'] ?? ''));
		if (in_array($display, ['General Manager', 'Division Manager', 'Sales', 'Associate'], true)) {
			$toRemove[] = $id;
			continue;
		}
		if (stripos($display, 'Hunter Olive') !== false || preg_match('/^G_Hunter/i', (string) $id)) {
			$toRemove[] = $id;
		}
	}
	$changed = false;
	foreach (array_unique($toRemove) as $id) {
		if (isset($employees[$id])) {
			unset($employees[$id]);
			$changed = true;
		}
	}
	return $changed;
}

function tsMigrateEmploymentTypeDefaults() {
	$stored = ogmReadJsonFile(tsRosterFile(), []);
	$list = isset($stored['employees']) && is_array($stored['employees']) ? $stored['employees'] : [];
	$needsMigration = false;
	foreach ($list as $row) {
		if (!is_array($row) || empty($row['employment_type'])) {
			$needsMigration = true;
			break;
		}
	}
	if (!$needsMigration) {
		return;
	}
	$employees = tsLoadRosterFromFile();
	if (!$employees) {
		return;
	}
	foreach ($employees as $id => $emp) {
		$employees[$id]['employment_type'] = tsNormalizeEmploymentType($emp['employment_type'] ?? 'full_time');
	}
	tsSaveRoster($employees);
}

function tsMigrateClockRosterCleanup() {
	$employees = tsLoadRosterFromFile();
	if (!$employees) {
		return;
	}
	$changed = tsDedupeRosterEmployees($employees);
	if (tsRemoveJunkRosterEntries($employees)) {
		$changed = true;
	}
	if ($changed) {
		tsSaveRoster($employees);
	}
	tsMigrateEmploymentTypeDefaults();
}

/** Legacy roster ids to rename (e.g. old Taanya entry). */
function tsEmployeeIdAliases() {
	return [
		'Taanya' => 'Tanya',
		'taanya' => 'Tanya',
	];
}

function tsResolveEmployeeId($id) {
	$id = tsNormalizeId($id);
	$aliases = tsEmployeeIdAliases();
	return $aliases[$id] ?? $id;
}

function tsLoadRosterFromFile() {
	if (!is_file(tsRosterFile())) {
		return [];
	}
	$stored = ogmReadJsonFile(tsRosterFile(), []);
	$list = isset($stored['employees']) && is_array($stored['employees']) ? $stored['employees'] : [];
	$employees = [];
	foreach ($list as $key => $row) {
		$emp = tsNormalizeEmployee(is_string($key) ? $key : ($row['id'] ?? ''), $row);
		if ($emp) {
			$employees[$emp['id']] = $emp;
		}
	}
	return $employees;
}

function tsMigrateLegacyEmployeeIds() {
	$aliases = tsEmployeeIdAliases();
	$roster = tsLoadRosterFromFile();
	$rosterChanged = false;
	foreach ($aliases as $from => $to) {
		if (!isset($roster[$from])) {
			continue;
		}
		if (!isset($roster[$to])) {
			$roster[$to] = $roster[$from];
			$roster[$to]['id'] = $to;
		} else {
			$roster[$to]['pto_hours'] = max(
				(float) ($roster[$to]['pto_hours'] ?? 0),
				(float) ($roster[$from]['pto_hours'] ?? 0)
			);
		}
		unset($roster[$from]);
		$rosterChanged = true;
	}
	if ($rosterChanged) {
		tsSaveRoster($roster);
	}

	$punches = tsReadPunches();
	$punchChanged = false;
	foreach ($punches as $i => $p) {
		$resolved = tsResolveEmployeeId($p['employee_id'] ?? '');
		if ($resolved !== ($p['employee_id'] ?? '')) {
			$punches[$i]['employee_id'] = $resolved;
			$punchChanged = true;
		}
	}
	if ($punchChanged) {
		tsSavePunches($punches);
	}

	$entries = tsReadAdjustments();
	$adjChanged = false;
	$newEntries = [];
	foreach ($entries as $key => $row) {
		$parts = explode('|', $key, 2);
		$eid = tsResolveEmployeeId($parts[0] ?? '');
		$date = $parts[1] ?? ($row['date'] ?? '');
		$newKey = $eid . '|' . $date;
		$row['employee_id'] = $eid;
		$newEntries[$newKey] = $row;
		if ($newKey !== $key) {
			$adjChanged = true;
		}
	}
	if ($adjChanged) {
		tsSaveAdjustments(array_values($newEntries));
	}
}

function tsNormalizeEmployee($id, $row) {
	if (!is_array($row)) {
		return null;
	}
	$id = tsNormalizeId($id !== '' ? $id : ($row['id'] ?? ''));
	if ($id === '') {
		return null;
	}
	$display = trim((string) ($row['display_name'] ?? $id));
	if ($display === '') {
		$display = $id;
	}
	return [
		'id' => $id,
		'display_name' => $display,
		'employment_type' => tsNormalizeEmploymentType($row['employment_type'] ?? 'full_time'),
		'pto_hours' => round(max(0, (float) ($row['pto_hours'] ?? 0)), 2),
		'active' => !isset($row['active']) || !empty($row['active']),
		'removed_at' => trim((string) ($row['removed_at'] ?? '')),
		'quoter_username' => trim((string) ($row['quoter_username'] ?? '')),
	];
}

/** Active roster members only (settings, TV clock). */
function tsRosterAdminList() {
	$list = [];
	foreach (tsReadRoster() as $emp) {
		if (tsIsJunkTimesheetEmployee($emp) || empty($emp['active'])) {
			continue;
		}
		$list[] = $emp;
	}
	usort($list, static function ($a, $b) {
		return strcasecmp((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? ''));
	});
	return $list;
}

function tsDateInWeekRange($ymd, array $range) {
	$ymd = trim((string) $ymd);
	return $ymd !== ''
		&& $ymd >= ($range['start_ymd'] ?? '')
		&& $ymd <= ($range['end_ymd'] ?? '');
}

/** True if employee has punches or adjustments in this Mon–Fri week. */
function tsEmployeeHasWeekActivity($employeeId, array $range) {
	$employeeId = tsNormalizeId($employeeId);
	if ($employeeId === '') {
		return false;
	}
	foreach (tsReadPunches() as $p) {
		if (($p['employee_id'] ?? '') !== $employeeId) {
			continue;
		}
		if (tsDateInWeekRange(tsDateKeyFromIso($p['at'] ?? ''), $range)) {
			return true;
		}
	}
	foreach (tsReadAdjustments() as $adj) {
		if (($adj['employee_id'] ?? '') !== $employeeId) {
			continue;
		}
		if (tsDateInWeekRange($adj['date'] ?? '', $range)) {
			return true;
		}
	}
	return false;
}

/** Show on weekly reports: active roster, or removed but has history this week. */
function tsEmployeeIncludedInWeekReport($emp, array $range) {
	if (tsIsJunkTimesheetEmployee($emp)) {
		return false;
	}
	if (!empty($emp['active'])) {
		return true;
	}
	return tsEmployeeHasWeekActivity($emp['id'] ?? '', $range);
}

/**
 * Remove from active roster (soft delete). Punches and adjustments are kept for history.
 */
function tsDeactivateEmployee($id) {
	$id = tsNormalizeId($id);
	if ($id === '') {
		return [false, 'Employee id required.'];
	}
	$roster = tsReadRoster();
	if (!isset($roster[$id])) {
		return [false, 'Employee not found.'];
	}
	$roster[$id]['active'] = false;
	$roster[$id]['removed_at'] = gmdate('c');
	$roster[$id] = tsNormalizeEmployee($id, $roster[$id]);
	if (!tsSaveRoster($roster)) {
		return [false, 'Could not save roster.'];
	}
	return [true, $roster[$id]];
}

function tsMergeQuoterRoster($employees) {
	if (!function_exists('qtReadUsers')) {
		require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';
	}
	$users = qtReadUsers();
	// Link named managers for timesheet reports only — do not import every quoter login.
	$map = [
		'Brennan' => 'Brennan Binkley (BB)',
		'Sed' => 'G Sedberry Olive (SO)',
		'Meo' => 'G Hunter Olive (HO)',
		'Tanya' => 'Tanya Wadkins (TW)',
	];
	foreach ($map as $id => $label) {
		if (isset($employees[$id])) {
			$employees[$id]['display_name'] = $label;
			continue;
		}
		foreach ($users as $uname => $u) {
			if (!is_array($u) || empty($u['active'])) {
				continue;
			}
			if (stripos((string) ($u['display_name'] ?? ''), $id) !== false || strcasecmp($uname, $id) === 0) {
				$employees[$id] = [
					'id' => $id,
					'display_name' => $label,
					'pto_hours' => 40,
					'active' => true,
					'quoter_username' => $uname,
				];
				break;
			}
		}
	}
	tsDedupeRosterEmployees($employees);
	return $employees;
}

function tsBootstrapRoster() {
	$employees = [];
	foreach (tsDefaultShopNames() as $row) {
		$emp = tsNormalizeEmployee($row['id'], $row);
		if ($emp) {
			$employees[$emp['id']] = $emp;
		}
	}
	$employees = tsMergeQuoterRoster($employees);
	uasort($employees, static function ($a, $b) {
		return strcasecmp((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? ''));
	});
	return $employees;
}

function tsReadRoster() {
	if (!is_file(tsRosterFile())) {
		$boot = tsBootstrapRoster();
		tsSaveRoster($boot);
		return $boot;
	}
	static $migrated = false;
	if (!$migrated) {
		$migrated = true;
		tsMigrateLegacyEmployeeIds();
		tsMigrateClockRosterCleanup();
	}
	$employees = tsLoadRosterFromFile();
	if (!$employees) {
		$employees = tsBootstrapRoster();
		tsSaveRoster($employees);
	}
	return $employees;
}

function tsSaveRoster($employees) {
	$normalized = [];
	foreach ((array) $employees as $id => $row) {
		$emp = tsNormalizeEmployee($id, $row);
		if ($emp) {
			$normalized[$emp['id']] = $emp;
		}
	}
	if (!$normalized) {
		return false;
	}
	return ogmWriteJsonFile(tsRosterFile(), [
		'employees' => array_values($normalized),
		'by_id' => $normalized,
		'updated_at' => gmdate('c'),
	]);
}

function tsReadPunches() {
	$stored = ogmReadJsonFile(tsPunchesFile(), []);
	$rows = isset($stored['punches']) && is_array($stored['punches']) ? $stored['punches'] : [];
	$out = [];
	foreach ($rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		$eid = tsNormalizeId($row['employee_id'] ?? '');
		$type = strtolower(trim((string) ($row['type'] ?? '')));
		$at = trim((string) ($row['at'] ?? ''));
		if ($eid === '' || !in_array($type, ['in', 'out'], true) || $at === '') {
			continue;
		}
		$out[] = [
			'id' => trim((string) ($row['id'] ?? bin2hex(random_bytes(8)))),
			'employee_id' => $eid,
			'type' => $type,
			'at' => $at,
			'source' => trim((string) ($row['source'] ?? 'tv')) ?: 'tv',
		];
	}
	return $out;
}

function tsSavePunches($punches) {
	return ogmWriteJsonFile(tsPunchesFile(), [
		'punches' => array_values($punches),
		'updated_at' => gmdate('c'),
	]);
}

function tsAppendPunch($employeeId, $type, $source = 'tv') {
	$employeeId = tsResolveEmployeeId($employeeId);
	$type = strtolower(trim((string) $type));
	if ($employeeId === '' || !in_array($type, ['in', 'out'], true)) {
		return [false, 'Invalid punch.'];
	}
	$roster = tsReadRoster();
	if (!isset($roster[$employeeId])) {
		return [false, 'Employee not found.'];
	}
	if (empty($roster[$employeeId]['active'])) {
		return [false, 'Employee is no longer on the roster.'];
	}
	if ($source === 'tv' && !tsEmployeeShowOnClock($roster[$employeeId])) {
		return [false, 'That name is not on the shop clock list.'];
	}
	$status = tsEmployeeStatus($employeeId);
	if ($type === 'in' && !empty($status['clocked_in'])) {
		return [false, 'Already clocked in. Clock out first.'];
	}
	if ($type === 'out' && empty($status['clocked_in'])) {
		return [false, 'Not clocked in. Clock in first.'];
	}
	$now = (new DateTime('now', tsTimezone()))->format(DateTime::ATOM);
	$punches = tsReadPunches();
	$punches[] = [
		'id' => bin2hex(random_bytes(8)),
		'employee_id' => $employeeId,
		'type' => $type,
		'at' => $now,
		'source' => $source,
	];
	if (!tsSavePunches($punches)) {
		return [false, 'Could not save punch.'];
	}
	return [true, [
		'employee_id' => $employeeId,
		'display_name' => (string) ($roster[$employeeId]['display_name'] ?? $employeeId),
		'type' => $type,
		'at' => $now,
		'clocked_in' => $type === 'in',
	]];
}

function tsEmployeeStatus($employeeId) {
	$employeeId = tsNormalizeId($employeeId);
	$punches = tsReadPunches();
	$last = null;
	foreach ($punches as $p) {
		if ($p['employee_id'] !== $employeeId) {
			continue;
		}
		if ($last === null || strcmp($p['at'], $last['at']) > 0) {
			$last = $p;
		}
	}
	$clockedIn = is_array($last) && ($last['type'] ?? '') === 'in';
	return [
		'employee_id' => $employeeId,
		'clocked_in' => $clockedIn,
		'last_punch' => $last,
		'next_action' => $clockedIn ? 'out' : 'in',
	];
}

function tsRosterList() {
	$roster = tsReadRoster();
	$list = [];
	$seenDisplay = [];
	foreach ($roster as $emp) {
		if (!tsEmployeeShowOnClock($emp)) {
			continue;
		}
		$dnKey = strtolower(trim((string) ($emp['display_name'] ?? '')));
		if ($dnKey !== '' && isset($seenDisplay[$dnKey])) {
			continue;
		}
		if ($dnKey !== '') {
			$seenDisplay[$dnKey] = true;
		}
		$list[] = [
			'id' => $emp['id'],
			'display_name' => $emp['display_name'],
		];
	}
	usort($list, static function ($a, $b) {
		return strcasecmp($a['display_name'], $b['display_name']);
	});
	return $list;
}

function tsParseDateYmd($ymd) {
	$ymd = trim((string) $ymd);
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
		return null;
	}
	try {
		return new DateTime($ymd . ' 00:00:00', tsTimezone());
	} catch (Exception $e) {
		return null;
	}
}

function tsWeekRange($weekStartYmd = null) {
	$tz = tsTimezone();
	if ($weekStartYmd) {
		$start = tsParseDateYmd($weekStartYmd);
		if (!$start) {
			$start = new DateTime('monday this week', $tz);
		}
	} else {
		$start = new DateTime('monday this week', $tz);
	}
	$weekday = (int) $start->format('N'); // 1 = Monday, 7 = Sunday.
	if ($weekday > 1) {
		$start->modify('-' . ($weekday - 1) . ' days');
	}
	$start->setTime(0, 0, 0);
	$end = clone $start;
	$end->modify('+4 days');
	$end->setTime(23, 59, 59);
	return [
		'start' => $start,
		'end' => $end,
		'start_ymd' => $start->format('Y-m-d'),
		'end_ymd' => $end->format('Y-m-d'),
		'week_key' => $start->format('Y-m-d'),
	];
}

function tsDateKeyFromIso($iso) {
	try {
		$dt = new DateTime($iso);
		$dt->setTimezone(tsTimezone());
		return $dt->format('Y-m-d');
	} catch (Exception $e) {
		return '';
	}
}

function tsTimeLabelFromIso($iso) {
	try {
		$dt = new DateTime($iso);
		$dt->setTimezone(tsTimezone());
		return $dt->format('g:i A');
	} catch (Exception $e) {
		return '';
	}
}

function tsHoursBetween($inIso, $outIso) {
	try {
		$a = new DateTime($inIso);
		$b = new DateTime($outIso);
		$sec = $b->getTimestamp() - $a->getTimestamp();
		if ($sec <= 0) {
			return 0;
		}
		return round($sec / 3600, 2);
	} catch (Exception $e) {
		return 0;
	}
}

function tsEmployeeGetsFridayPaidHour($emp) {
	return tsNormalizeEmploymentType(is_array($emp) ? ($emp['employment_type'] ?? 'full_time') : 'full_time') === 'full_time';
}

function tsIsFridayYmd($dateYmd) {
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dateYmd)) {
		return false;
	}
	$day = DateTime::createFromFormat('!Y-m-d', $dateYmd, tsTimezone());
	return $day instanceof DateTime && $day->format('N') === '5';
}

function tsHoursBetweenForTimesheet($dateYmd, $inIso, $outIso, $emp = null) {
	unset($dateYmd, $emp);
	try {
		$a = new DateTime($inIso);
		$b = new DateTime($outIso);
		$sec = $b->getTimestamp() - $a->getTimestamp();
		if ($sec <= 0) {
			return 0;
		}
		return round($sec / 3600, 2);
	} catch (Exception $e) {
		return 0;
	}
}

function tsApplyFridayPaidHour($dateYmd, $hrsWorked, $emp = null) {
	$hrsWorked = round(max(0, (float) $hrsWorked), 2);
	if ($hrsWorked > 0 && tsEmployeeGetsFridayPaidHour($emp) && tsIsFridayYmd($dateYmd)) {
		return round($hrsWorked + 1, 2);
	}
	return $hrsWorked;
}

function tsDefaultAdjustmentRow($employeeId, $date) {
	return [
		'employee_id' => $employeeId,
		'date' => $date,
		'log_in' => '',
		'log_out' => '',
		'hrs_worked' => null,
		'absence_code' => '',
		'hrs_pto' => 0,
		'hrs_ot' => 0,
		'note' => '',
		'pto_summary' => [],
	];
}

/** Role / placeholder roster rows that should not appear on timesheets. */
function tsIsJunkTimesheetEmployee($emp) {
	if (!is_array($emp)) {
		return true;
	}
	$id = tsNormalizeId($emp['id'] ?? '');
	$junkIds = ['Grant', 'General_Manager', 'Division_Manager', 'Sales', 'Associate'];
	if (in_array($id, $junkIds, true)) {
		return true;
	}
	$display = trim((string) ($emp['display_name'] ?? ''));
	return in_array($display, ['General Manager', 'Division Manager', 'Sales', 'Associate'], true);
}

function tsParseTimeOnDate($timeLabel, $dateYmd) {
	$timeLabel = trim((string) $timeLabel);
	if ($timeLabel === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
		return null;
	}
	$tz = tsTimezone();
	$candidates = [
		$dateYmd . ' ' . $timeLabel,
		$dateYmd . ' ' . preg_replace('/\s*(AM|PM)\s*/i', ' $1', $timeLabel),
	];
	foreach ($candidates as $raw) {
		foreach (['g:i A', 'G:i', 'g:i', 'H:i', 'h:i A'] as $fmt) {
			$dt = DateTime::createFromFormat('Y-m-d ' . $fmt, $raw, $tz);
			if ($dt instanceof DateTime) {
				return $dt;
			}
		}
	}
	$ts = strtotime($dateYmd . ' ' . $timeLabel);
	if ($ts !== false) {
		$dt = new DateTime('@' . $ts);
		$dt->setTimezone($tz);
		return $dt;
	}
	return null;
}

function tsHoursFromTimeLabels($dateYmd, $logIn, $logOut, $emp = null) {
	unset($emp);
	$in = tsParseTimeOnDate($logIn, $dateYmd);
	$out = tsParseTimeOnDate($logOut, $dateYmd);
	if (!$in || !$out) {
		return null;
	}
	$sec = $out->getTimestamp() - $in->getTimestamp();
	if ($sec <= 0) {
		return null;
	}
	return round($sec / 3600, 2);
}

function tsApplyManualTimeOverrides($dateYmd, $logIn, $logOut, $hrsWorked, array $adj, $emp = null) {
	$outLogIn = $logIn;
	$outLogOut = $logOut;
	$outHrs = $hrsWorked;

	$adjIn = trim((string) ($adj['log_in'] ?? ''));
	$adjOut = trim((string) ($adj['log_out'] ?? ''));
	$hasHrsOverride = array_key_exists('hrs_worked', $adj)
		&& $adj['hrs_worked'] !== null
		&& $adj['hrs_worked'] !== '';

	if ($adjIn !== '') {
		$outLogIn = $adjIn;
	}
	if ($adjOut !== '') {
		$outLogOut = $adjOut;
	}
	if ($hasHrsOverride) {
		$outHrs = round(max(0, (float) $adj['hrs_worked']), 2);
	} elseif (($adjIn !== '' || $adjOut !== '') && $outLogIn !== '' && $outLogOut !== '') {
		$computed = tsHoursFromTimeLabels($dateYmd, $outLogIn, $outLogOut, $emp);
		if ($computed !== null) {
			$outHrs = $computed;
		}
	}

	return [$outLogIn, $outLogOut, $outHrs, $hasHrsOverride];
}

function tsReadAdjustments() {
	$stored = ogmReadJsonFile(tsAdjustmentsFile(), []);
	$rows = isset($stored['entries']) && is_array($stored['entries']) ? $stored['entries'] : [];
	$out = [];
	foreach ($rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		$eid = tsNormalizeId($row['employee_id'] ?? '');
		$date = trim((string) ($row['date'] ?? ''));
		if ($eid === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			continue;
		}
		$key = $eid . '|' . $date;
		$hrsWorked = null;
		if (array_key_exists('hrs_worked', $row) && $row['hrs_worked'] !== '' && $row['hrs_worked'] !== null) {
			$hrsWorked = round(max(0, (float) $row['hrs_worked']), 2);
		}
		$out[$key] = [
			'employee_id' => $eid,
			'date' => $date,
			'log_in' => trim((string) ($row['log_in'] ?? '')),
			'log_out' => trim((string) ($row['log_out'] ?? '')),
			'hrs_worked' => $hrsWorked,
			'absence_code' => trim((string) ($row['absence_code'] ?? '')),
			'hrs_pto' => round(max(0, (float) ($row['hrs_pto'] ?? 0)), 2),
			'hrs_ot' => round(max(0, (float) ($row['hrs_ot'] ?? 0)), 2),
			'note' => trim((string) ($row['note'] ?? '')),
			'pto_summary' => tsNormalizePtoSummaryOverrides($row['pto_summary'] ?? []),
		];
	}
	return $out;
}

function tsSaveAdjustments($entries) {
	$list = [];
	foreach ((array) $entries as $row) {
		if (!is_array($row)) {
			continue;
		}
		$eid = tsNormalizeId($row['employee_id'] ?? '');
		$date = trim((string) ($row['date'] ?? ''));
		if ($eid === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			continue;
		}
		$entry = [
			'employee_id' => $eid,
			'date' => $date,
			'log_in' => trim((string) ($row['log_in'] ?? '')),
			'log_out' => trim((string) ($row['log_out'] ?? '')),
			'absence_code' => trim((string) ($row['absence_code'] ?? '')),
			'hrs_pto' => round(max(0, (float) ($row['hrs_pto'] ?? 0)), 2),
			'hrs_ot' => round(max(0, (float) ($row['hrs_ot'] ?? 0)), 2),
			'note' => trim((string) ($row['note'] ?? '')),
		];
		$ptoSummary = tsNormalizePtoSummaryOverrides($row['pto_summary'] ?? []);
		if ($ptoSummary) {
			$entry['pto_summary'] = $ptoSummary;
		}
		if (array_key_exists('hrs_worked', $row) && $row['hrs_worked'] !== null && $row['hrs_worked'] !== '') {
			$entry['hrs_worked'] = round(max(0, (float) $row['hrs_worked']), 2);
		}
		$list[] = $entry;
	}
	return ogmWriteJsonFile(tsAdjustmentsFile(), [
		'entries' => $list,
		'updated_at' => gmdate('c'),
	]);
}

function tsPtoSummaryOverrideFields() {
	return [
		'hrs_worked', 'absence_code', 'hrs_pto', 'hrs_ot', 'total',
		'pto_beg', 'pto_used', 'pto_end', 'check',
	];
}

function tsNormalizePtoSummaryOverrides($fields) {
	if (!is_array($fields)) {
		return [];
	}
	$out = [];
	foreach (tsPtoSummaryOverrideFields() as $field) {
		if (!array_key_exists($field, $fields)) {
			continue;
		}
		if ($field === 'absence_code') {
			$out[$field] = trim((string) $fields[$field]);
			continue;
		}
		$value = $fields[$field];
		if ($value === '' || $value === null) {
			$out[$field] = 0;
			continue;
		}
		$out[$field] = round((float) $value, 2);
	}
	return $out;
}

function tsAbsenceConsumesPto($code) {
	$code = strtolower(trim((string) $code));
	if ($code === '') {
		return false;
	}
	return strpos($code, 'pto') !== false
		|| strpos($code, 'vacation') !== false
		|| strpos($code, 'personal') !== false
		|| strpos($code, 'sick') !== false;
}

function tsWeekdayDates($range) {
	$dates = [];
	$cur = clone $range['start'];
	for ($i = 0; $i < 5; $i++) {
		$dates[] = $cur->format('Y-m-d');
		$cur->modify('+1 day');
	}
	return $dates;
}

function tsBuildEmployeeWeek($employeeId, $range) {
	$employeeId = tsNormalizeId($employeeId);
	$roster = tsReadRoster();
	$emp = $roster[$employeeId] ?? null;
	if (!$emp) {
		return null;
	}
	$punches = tsReadPunches();
	$adjustments = tsReadAdjustments();
	$dates = tsWeekdayDates($range);
	$days = [];
	$totalWorked = 0;
	$totalPto = 0;
	$totalOt = 0;

	foreach ($dates as $date) {
		$dayPunches = [];
		foreach ($punches as $p) {
			if ($p['employee_id'] !== $employeeId) {
				continue;
			}
			if (tsDateKeyFromIso($p['at']) === $date) {
				$dayPunches[] = $p;
			}
		}
		usort($dayPunches, static function ($a, $b) {
			return strcmp($a['at'], $b['at']);
		});
		$logIn = '';
		$logOut = '';
		$hrsWorked = 0;
		$pendingIn = null;
		foreach ($dayPunches as $p) {
			if ($p['type'] === 'in') {
				if ($pendingIn === null) {
					$pendingIn = $p['at'];
					$logIn = $logIn === '' ? tsTimeLabelFromIso($p['at']) : $logIn;
				}
			} elseif ($p['type'] === 'out' && $pendingIn !== null) {
				$logOut = tsTimeLabelFromIso($p['at']);
				$hrsWorked += tsHoursBetweenForTimesheet($date, $pendingIn, $p['at'], $emp);
				$pendingIn = null;
			}
		}
		if ($pendingIn !== null && $logIn === '') {
			$logIn = tsTimeLabelFromIso($pendingIn);
		}

		$adjKey = $employeeId . '|' . $date;
		$adj = $adjustments[$adjKey] ?? tsDefaultAdjustmentRow($employeeId, $date);
		list($logIn, $logOut, $hrsWorked, $hasManualHoursOverride) = tsApplyManualTimeOverrides($date, $logIn, $logOut, $hrsWorked, $adj, $emp);
		if (!$hasManualHoursOverride) {
			$hrsWorked = tsApplyFridayPaidHour($date, $hrsWorked, $emp);
		}

		$absence = $adj['absence_code'];
		$hrsPto = (float) $adj['hrs_pto'];
		$hrsOt = (float) $adj['hrs_ot'];
		if ($hrsPto <= 0 && $absence !== '' && tsAbsenceConsumesPto($absence) && $hrsWorked <= 0) {
			$hrsPto = 8;
		}

		$totalWorked += $hrsWorked;
		$totalPto += $hrsPto;
		$totalOt += $hrsOt;

		$days[] = [
			'date' => $date,
			'day_label' => (new DateTime($date, tsTimezone()))->format('l'),
			'log_in' => $logIn,
			'log_out' => $logOut,
			'hrs_worked' => round($hrsWorked, 2),
			'hrs_worked_override' => $hasManualHoursOverride,
			'absence_code' => $absence,
			'hrs_pto' => $hrsPto,
			'hrs_ot' => $hrsOt,
		];
	}

	$ptoBeg = (float) ($emp['pto_hours'] ?? 0);
	$ptoUsed = round($totalPto, 2);
	$ptoEnd = round($ptoBeg - $ptoUsed, 2);

	return [
		'employee_id' => $employeeId,
		'display_name' => $emp['display_name'],
		'employment_type' => $emp['employment_type'] ?? 'full_time',
		'roster_active' => !empty($emp['active']),
		'pto_beg' => $ptoBeg,
		'pto_end' => $ptoEnd,
		'pto_used_week' => $ptoUsed,
		'pto_warning' => $ptoUsed > $ptoBeg,
		'days' => $days,
		'totals' => [
			'hrs_worked' => round($totalWorked, 2),
			'hrs_pto' => round($totalPto, 2),
			'hrs_ot' => round($totalOt, 2),
			'total' => round($totalWorked + $totalPto, 2),
		],
	];
}

function tsBuildWeekTimesheet($weekStartYmd = null) {
	$range = tsWeekRange($weekStartYmd);
	$roster = tsReadRoster();
	$employees = [];
	foreach ($roster as $emp) {
		if (!tsEmployeeIncludedInWeekReport($emp, $range)) {
			continue;
		}
		$built = tsBuildEmployeeWeek($emp['id'], $range);
		if ($built) {
			$employees[] = $built;
		}
	}
	usort($employees, static function ($a, $b) {
		return strcasecmp($a['display_name'], $b['display_name']);
	});
	return [
		'week' => $range,
		'employees' => $employees,
	];
}

function tsBuildPtoSummary($weekStartYmd = null) {
	$sheet = tsBuildWeekTimesheet($weekStartYmd);
	$weekStart = (string) ($sheet['week']['start_ymd'] ?? '');
	$adjustments = tsReadAdjustments();
	$rows = [];
	$sumWorked = 0;
	$sumPto = 0;
	$sumOt = 0;
	$sumTotal = 0;
	$sumPtoBeg = 0;
	$sumPtoUsed = 0;
	$sumPtoEnd = 0;

	foreach ($sheet['employees'] as $emp) {
		$t = $emp['totals'];
		$row = [
			'employee_id' => $emp['employee_id'],
			'summary' => $emp['display_name'],
			'hrs_worked' => $t['hrs_worked'],
			'absence_code' => '',
			'hrs_pto' => $t['hrs_pto'],
			'hrs_ot' => $t['hrs_ot'],
			'total' => $t['total'],
			'pto_beg' => $emp['pto_beg'],
			'pto_used' => $emp['pto_used_week'],
			'pto_end' => $emp['pto_end'],
			'check' => $emp['pto_beg'],
			'pto_warning' => $emp['pto_warning'],
		];
		$override = $adjustments[$emp['employee_id'] . '|' . $weekStart]['pto_summary'] ?? [];
		if (is_array($override) && $override) {
			foreach (tsPtoSummaryOverrideFields() as $field) {
				if (array_key_exists($field, $override)) {
					$row[$field] = $override[$field];
				}
			}
			$row['summary_overridden'] = true;
		}
		$sumWorked += (float) $row['hrs_worked'];
		$sumPto += (float) $row['hrs_pto'];
		$sumOt += (float) $row['hrs_ot'];
		$sumTotal += (float) $row['total'];
		$sumPtoBeg += (float) $row['pto_beg'];
		$sumPtoUsed += (float) $row['pto_used'];
		$sumPtoEnd += (float) $row['pto_end'];
		$rows[] = $row;
	}

	return [
		'week' => $sheet['week'],
		'rows' => $rows,
		'totals' => [
			'hrs_worked' => round($sumWorked, 2),
			'hrs_pto' => round($sumPto, 2),
			'hrs_ot' => round($sumOt, 2),
			'total' => round($sumTotal, 2),
			'pto_beg' => round($sumPtoBeg, 2),
			'pto_used' => round($sumPtoUsed, 2),
			'pto_end' => round($sumPtoEnd, 2),
			'check' => round($sumPtoBeg, 2),
		],
	];
}

function tsUpsertAdjustment($employeeId, $date, $fields) {
	$employeeId = tsNormalizeId($employeeId);
	$date = trim((string) $date);
	if ($employeeId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
		return false;
	}
	$entries = tsReadAdjustments();
	$key = $employeeId . '|' . $date;
	$prev = $entries[$key] ?? tsDefaultAdjustmentRow($employeeId, $date);
	foreach (['log_in', 'log_out', 'absence_code', 'hrs_pto', 'hrs_ot', 'note'] as $f) {
		if (array_key_exists($f, $fields)) {
			$prev[$f] = trim((string) $fields[$f]);
		}
	}
	if (array_key_exists('hrs_worked', $fields)) {
		$v = $fields['hrs_worked'];
		if ($v === '' || $v === null) {
			$prev['hrs_worked'] = null;
		} else {
			$prev['hrs_worked'] = round(max(0, (float) $v), 2);
		}
	}
	$entries[$key] = $prev;
	return tsSaveAdjustments(array_values($entries));
}

function tsUpsertPtoSummaryOverride($employeeId, $weekStartYmd, $fields) {
	$employeeId = tsNormalizeId($employeeId);
	$date = trim((string) $weekStartYmd);
	if ($employeeId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
		return false;
	}
	$entries = tsReadAdjustments();
	$key = $employeeId . '|' . $date;
	$prev = $entries[$key] ?? tsDefaultAdjustmentRow($employeeId, $date);
	$prev['pto_summary'] = tsNormalizePtoSummaryOverrides($fields);
	$entries[$key] = $prev;
	return tsSaveAdjustments(array_values($entries));
}

function tsUpsertEmployee($id, $fields) {
	$roster = tsReadRoster();
	$id = tsNormalizeId($id !== '' ? $id : ($fields['id'] ?? ''));
	if ($id === '') {
		return [false, 'Employee id required.'];
	}
	$prev = $roster[$id] ?? [
		'id' => $id,
		'display_name' => $id,
		'employment_type' => 'full_time',
		'pto_hours' => 40,
		'active' => true,
		'quoter_username' => '',
	];
	if (isset($fields['display_name'])) {
		$prev['display_name'] = trim((string) $fields['display_name']);
	}
	if (isset($fields['employment_type'])) {
		$prev['employment_type'] = tsNormalizeEmploymentType($fields['employment_type']);
	}
	if (isset($fields['pto_hours'])) {
		$prev['pto_hours'] = (float) $fields['pto_hours'];
	}
	if (isset($fields['active'])) {
		$prev['active'] = !empty($fields['active']);
		if (!empty($prev['active'])) {
			$prev['removed_at'] = '';
		}
	}
	if (isset($fields['quoter_username'])) {
		$prev['quoter_username'] = trim((string) $fields['quoter_username']);
	}
	$roster[$id] = tsNormalizeEmployee($id, $prev);
	if (!tsSaveRoster($roster)) {
		return [false, 'Could not save roster.'];
	}
	return [true, $roster[$id]];
}
