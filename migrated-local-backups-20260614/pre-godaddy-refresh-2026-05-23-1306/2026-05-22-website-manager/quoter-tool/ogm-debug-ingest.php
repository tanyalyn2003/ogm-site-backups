<?php
/**
 * Same-origin debug NDJSON sink for browser instrumentation (writes under .cursor/).
 * Requires an active quoter session; not for public/unauthenticated use.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
	header('Access-Control-Allow-Methods: POST, OPTIONS', true);
	header('Access-Control-Allow-Headers: Content-Type, X-Debug-Session-Id', true);
	http_response_code(204);
	exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	http_response_code(405);
	echo json_encode(['ok' => false, 'err' => 'method']);
	exit;
}

if (!qtIsLoggedIn()) {
	http_response_code(403);
	echo json_encode(['ok' => false, 'err' => 'auth']);
	exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 65536) {
	http_response_code(413);
	echo json_encode(['ok' => false, 'err' => 'size']);
	exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
	http_response_code(400);
	echo json_encode(['ok' => false, 'err' => 'json']);
	exit;
}

$sid = isset($payload['sessionId']) && is_string($payload['sessionId'])
	? $payload['sessionId']
	: '';
if (!preg_match('/^[a-zA-Z0-9_-]{4,48}$/', $sid)) {
	$sid = 'default';
}

$logDir = __DIR__ . DIRECTORY_SEPARATOR . '.cursor';
if (!is_dir($logDir)) {
	if (!@mkdir($logDir, 0755, true)) {
		http_response_code(500);
		echo json_encode(['ok' => false, 'err' => 'mkdir']);
		exit;
	}
}

$logFile = $logDir . DIRECTORY_SEPARATOR . 'debug-' . $sid . '.log';
$line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
if (@file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX) === false) {
	http_response_code(500);
	echo json_encode(['ok' => false, 'err' => 'write']);
	exit;
}

echo json_encode(['ok' => true]);
