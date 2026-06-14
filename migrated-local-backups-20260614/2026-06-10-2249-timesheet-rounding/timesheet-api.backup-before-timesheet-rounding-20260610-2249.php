<?php

header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, max-age=0');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'timesheet-lib.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));

function tsJson($payload, $code = 200) {
	http_response_code($code);
	echo json_encode($payload, JSON_UNESCAPED_SLASHES);
	exit;
}

function tsRequireAuth() {
	require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';
	qtSendNoIndexHeaders();
	qtStartSession();
	if (!qtIsLoggedIn()) {
		tsJson(['ok' => false, 'error' => 'Not authenticated'], 401);
	}
}

function tsReadJsonBody() {
	$raw = (string) file_get_contents('php://input');
	$decoded = json_decode($raw, true);
	return is_array($decoded) ? $decoded : [];
}

try {
	if ($action === 'roster' && $method === 'GET') {
		tsJson(['ok' => true, 'employees' => tsRosterList()]);
	}

	if ($action === 'status' && $method === 'GET') {
		$eid = tsNormalizeId($_GET['employee_id'] ?? '');
		if ($eid === '') {
			tsJson(['ok' => false, 'error' => 'employee_id required'], 400);
		}
		$status = tsEmployeeStatus($eid);
		$roster = tsReadRoster();
		$display = (string) ($roster[$eid]['display_name'] ?? $eid);
		tsJson([
			'ok' => true,
			'employee_id' => $eid,
			'display_name' => $display,
			'clocked_in' => !empty($status['clocked_in']),
			'next_action' => $status['next_action'],
			'last_punch' => $status['last_punch'],
		]);
	}

	if ($action === 'punch' && $method === 'POST') {
		$body = tsReadJsonBody();
		$eid = tsNormalizeId($body['employee_id'] ?? '');
		$type = strtolower(trim((string) ($body['type'] ?? '')));
		if ($type === '' && !empty($body['toggle'])) {
			$st = tsEmployeeStatus($eid);
			$type = $st['next_action'] ?? 'in';
		}
		list($ok, $result) = tsAppendPunch($eid, $type, 'tv');
		if (!$ok) {
			tsJson(['ok' => false, 'error' => (string) $result], 400);
		}
		tsJson(['ok' => true, 'punch' => $result]);
	}

	// Authenticated actions below
	tsRequireAuth();

	if ($action === 'week_timesheet' && $method === 'GET') {
		$week = trim((string) ($_GET['week_start'] ?? ''));
		tsJson(['ok' => true, 'data' => tsBuildWeekTimesheet($week !== '' ? $week : null)]);
	}

	if ($action === 'pto_summary' && $method === 'GET') {
		$week = trim((string) ($_GET['week_start'] ?? ''));
		tsJson(['ok' => true, 'data' => tsBuildPtoSummary($week !== '' ? $week : null)]);
	}

	if ($action === 'save_adjustment' && $method === 'POST') {
		if (!qtCan('report_editing')) {
			tsJson(['ok' => false, 'error' => 'Access denied'], 403);
		}
		$body = tsReadJsonBody();
		$eid = tsNormalizeId($body['employee_id'] ?? '');
		$date = trim((string) ($body['date'] ?? ''));
		if ($eid === '' || $date === '') {
			tsJson(['ok' => false, 'error' => 'employee_id and date required'], 400);
		}
		$fields = [];
		foreach (['log_in', 'log_out', 'absence_code', 'hrs_pto', 'hrs_ot', 'note', 'hrs_worked'] as $f) {
			if (array_key_exists($f, $body)) {
				$fields[$f] = $body[$f];
			}
		}
		if (!tsUpsertAdjustment($eid, $date, $fields)) {
			tsJson(['ok' => false, 'error' => 'Could not save'], 500);
		}
		tsJson(['ok' => true]);
	}

	if ($action === 'save_pto_summary_row' && $method === 'POST') {
		if (!qtCan('report_editing')) {
			tsJson(['ok' => false, 'error' => 'Access denied'], 403);
		}
		$body = tsReadJsonBody();
		$eid = tsNormalizeId($body['employee_id'] ?? '');
		$weekStart = trim((string) ($body['week_start'] ?? $body['date'] ?? ''));
		$fields = is_array($body['fields'] ?? null) ? $body['fields'] : [];
		$allowed = array_fill_keys(tsPtoSummaryOverrideFields(), true);
		$clean = [];
		foreach ($fields as $field => $value) {
			$field = trim((string) $field);
			if (isset($allowed[$field])) {
				$clean[$field] = $value;
			}
		}
		if ($eid === '' || $weekStart === '') {
			tsJson(['ok' => false, 'error' => 'employee_id and week_start required'], 400);
		}
		if (!tsUpsertPtoSummaryOverride($eid, $weekStart, $clean)) {
			tsJson(['ok' => false, 'error' => 'Could not save PTO summary row'], 500);
		}
		tsJson(['ok' => true]);
	}

	if ($action === 'save_roster' && $method === 'POST') {
		qtRequireUserAdmin();
		$body = tsReadJsonBody();
		$id = tsNormalizeId($body['id'] ?? ($body['employee_id'] ?? ''));
		list($ok, $result) = tsUpsertEmployee($id, $body);
		if (!$ok) {
			tsJson(['ok' => false, 'error' => (string) $result], 400);
		}
		tsJson(['ok' => true, 'employee' => $result]);
	}

	if ($action === 'remove_employee' && $method === 'POST') {
		qtRequireUserAdmin();
		$body = tsReadJsonBody();
		$id = tsNormalizeId($body['id'] ?? ($body['employee_id'] ?? ''));
		list($ok, $result) = tsDeactivateEmployee($id);
		if (!$ok) {
			tsJson(['ok' => false, 'error' => (string) $result], 400);
		}
		tsJson(['ok' => true, 'employee' => $result, 'message' => 'Removed from roster. Past timesheet records were kept.']);
	}

	if ($action === 'roster_admin' && $method === 'GET') {
		tsJson(['ok' => true, 'employees' => tsRosterAdminList()]);
	}

	if ($action === 'capabilities' && $method === 'GET') {
		tsJson([
			'ok' => true,
			'can_admin' => qtCan('report_editing'),
			'can_user_admin' => qtCan('user_admin'),
		]);
	}

	tsJson(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
	tsJson(['ok' => false, 'error' => $e->getMessage()], 500);
}
