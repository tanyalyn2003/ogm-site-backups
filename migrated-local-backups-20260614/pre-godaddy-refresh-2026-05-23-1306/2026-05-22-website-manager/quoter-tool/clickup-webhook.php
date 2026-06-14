<?php

/**
 * ClickUp webhook endpoint — auto-links / auto-creates CustomerDB records when a
 * task is created in the Countertop intake list (901710745952).
 *
 * Flow on `taskCreated`:
 *   1. Verify HMAC-SHA256 signature against `.data/clickup-webhook-secret.json`.
 *   2. Fetch the full task from ClickUp.
 *   3. If the task already has a Customer ID custom-field value, no-op.
 *   4. Extract phone (custom field → fallback custom field → description regex).
 *   5. Match an existing customer by digits-only fuzzy phone.
 *   6. If no match, create a new CustomerDB record (`customers/<id>.json`) using
 *      the same shape OGM_CustomerDB.html / customers-api.php produce, plus
 *      `source: 'clickup-intake'` and a `clickupJobs` entry for this task.
 *   7. Write the resolved customerId back to the task's Customer ID field.
 *
 * Public endpoint — no PHP session. ClickUp posts here without cookies.
 * Always returns 200 on recoverable problems so ClickUp does not retry forever;
 * 503 only when the webhook secret has not been provisioned yet.
 *
 * NOTE: Helper logic (sanitize, match, customer-path) is intentionally inlined
 * here rather than `require`-ing customers-api.php so a malformed customer
 * payload cannot turn the webhook into a 401/HTML response. Source of truth for
 * the customer schema and search-by-phone algorithm is customers-api.php.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store, max-age=0');

const CLICKUP_BASE      = 'https://api.clickup.com/api/v2';
const INTAKE_LIST_ID    = '901710745952';

const FIELD_CUSTOMER_ID = '0f55db49-72c6-4591-a46d-f46a5b9bcb07';
const FIELD_NAME        = '3f29549e-7b5c-4541-a4ec-41c962c21206';
const FIELD_EMAIL       = '99066e11-0d80-4987-a9f4-09ef3b0e39dd';
const FIELD_ADDRESS     = 'd8be0cba-f158-4335-b524-dbd33674eb8e';
const FIELD_PHONE_PRI   = '277fe073-5980-4ba1-bc84-bcdc4a6a9a30'; // phone type
const FIELD_PHONE_FALL  = 'ebe09d1f-2c02-4431-afcd-7f59c137c7b7'; // short_text

$DATA_DIR        = __DIR__ . DIRECTORY_SEPARATOR . '.data';
$API_KEY_FILE    = $DATA_DIR . DIRECTORY_SEPARATOR . 'clickup-api-key.json';
$SECRET_FILE     = $DATA_DIR . DIRECTORY_SEPARATOR . 'clickup-webhook-secret.json';
$LOG_FILE        = $DATA_DIR . DIRECTORY_SEPARATOR . 'clickup-webhook.log';
$CUSTOMERS_DIR   = __DIR__ . DIRECTORY_SEPARATOR . 'customers';

/* ─── Logging ─────────────────────────────────────────────────────────────── */
function cuwhLog(array $entry, string $logFile): void {
    $entry['ts'] = gmdate('c');
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_file($logFile) && @filesize($logFile) > 5 * 1024 * 1024) {
        @rename($logFile, $logFile . '.1');
    }
    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
}

/* ─── Reply helpers ───────────────────────────────────────────────────────── */
function cuwhReply(int $status, array $payload, string $logFile, array $logExtra = []): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!empty($logExtra)) {
        cuwhLog(array_merge(['status' => $status], $logExtra), $logFile);
    }
    exit;
}

/* ─── ID generation (mirrors OGM_CustomerDB.html genId) ───────────────────── */
function cuwhBase36(int $num): string {
    if ($num <= 0) {
        return '0';
    }
    $alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
    $out = '';
    while ($num > 0) {
        $out = $alphabet[$num % 36] . $out;
        $num = intdiv($num, 36);
    }
    return $out;
}
function cuwhRandBase36(int $len): string {
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, 35)];
    }
    return $out;
}
function cuwhGenCustomerId(): string {
    $ms = (int) round(microtime(true) * 1000);
    return 'C' . strtoupper(cuwhBase36($ms)) . cuwhRandBase36(3);
}

/* ─── Filename safety (mirrors customersApiSanitizeId) ────────────────────── */
function cuwhSanitizeId(string $raw): ?string {
    $s = preg_replace('/[^A-Za-z0-9._-]/', '', $raw) ?? '';
    $s = substr($s, 0, 80);
    return $s === '' ? null : $s;
}

/* ─── Field extraction ────────────────────────────────────────────────────── */
function cuwhFindField(array $task, string $fieldId): ?array {
    $fields = isset($task['custom_fields']) && is_array($task['custom_fields'])
        ? $task['custom_fields']
        : [];
    foreach ($fields as $f) {
        if (is_array($f) && isset($f['id']) && (string) $f['id'] === $fieldId) {
            return $f;
        }
    }
    return null;
}
function cuwhFieldValue(array $task, string $fieldId) {
    $f = cuwhFindField($task, $fieldId);
    return $f === null ? null : ($f['value'] ?? null);
}

/* Mirrors extractAddressValue() in OGM_JobTracking.html — ClickUp address
 * fields can be a string or an object with formatted_address / location / address. */
function cuwhExtractAddress($value): string {
    if ($value === null || $value === '') {
        return '';
    }
    if (is_string($value)) {
        return trim($value);
    }
    if (is_array($value)) {
        foreach (['formatted_address', 'location', 'address'] as $k) {
            if (isset($value[$k]) && is_string($value[$k]) && trim($value[$k]) !== '') {
                return trim($value[$k]);
            }
        }
    }
    return '';
}

/* ClickUp phone-type fields can be returned as a plain string or as an
 * object/array — defensively flatten both shapes to the displayable string. */
function cuwhExtractPhone($value): string {
    if ($value === null || $value === '') {
        return '';
    }
    if (is_string($value)) {
        return trim($value);
    }
    if (is_array($value)) {
        foreach (['formatted', 'phone', 'value', 'number'] as $k) {
            if (isset($value[$k]) && is_string($value[$k]) && trim($value[$k]) !== '') {
                return trim($value[$k]);
            }
        }
    }
    return '';
}

function cuwhDigitsOnly(string $phone): string {
    return preg_replace('/[^0-9]/', '', $phone) ?? '';
}

function cuwhPhoneFromDescription(string $description): string {
    if ($description === '') {
        return '';
    }
    if (preg_match('/Phone:\s*(.+)/i', $description, $m)) {
        return trim(preg_split('/[\r\n]/', $m[1])[0] ?? '');
    }
    return '';
}

function cuwhSplitName(string $full): array {
    $name = trim($full);
    if ($name === '') {
        return ['', ''];
    }
    $parts = preg_split('/\s+/', $name, 2);
    return [$parts[0], $parts[1] ?? ''];
}

/* ─── ClickUp HTTP ────────────────────────────────────────────────────────── */
function cuwhClickUp(string $method, string $endpoint, string $apiKey, $body = null): array {
    $ch = curl_init(CLICKUP_BASE . $endpoint);
    $headers = ['Authorization: ' . $apiKey, 'Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES));
    }
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return ['ok' => false, 'status' => 0, 'error' => $err, 'body' => null];
    }
    $decoded = json_decode((string) $resp, true);
    return [
        'ok'     => $code >= 200 && $code < 300,
        'status' => $code,
        'error'  => $code >= 400 ? (is_array($decoded) ? ($decoded['err'] ?? $decoded['error'] ?? '') : (string) $resp) : '',
        'body'   => is_array($decoded) ? $decoded : null,
        'raw'    => is_array($decoded) ? null : $resp,
    ];
}

/* ─── Customer match (mirrors customers-api.php?action=search-by-phone) ───── */
function cuwhFindCustomerByPhone(string $customersDir, string $taskDigits): ?array {
    if (strlen($taskDigits) < 7) {
        return null;
    }
    $files = glob($customersDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    foreach ($files as $file) {
        $base = basename($file);
        if ($base === '' || $base[0] === '_') {
            continue;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['id'])) {
            continue;
        }
        $custDigits = cuwhDigitsOnly((string) ($data['phone'] ?? ''));
        if ($custDigits === '') {
            continue;
        }
        if (str_contains($custDigits, $taskDigits) || str_contains($taskDigits, $custDigits)) {
            return $data;
        }
    }
    return null;
}

/* Append a clickupJobs entry to an existing customer record (idempotent on taskId). */
function cuwhAttachJobToCustomer(array $customer, string $customersDir, string $taskId, string $listId): array {
    if (!isset($customer['clickupJobs']) || !is_array($customer['clickupJobs'])) {
        $customer['clickupJobs'] = [];
    }
    $alreadyLinked = false;
    foreach ($customer['clickupJobs'] as $entry) {
        if (is_array($entry) && isset($entry['taskId']) && (string) $entry['taskId'] === $taskId) {
            $alreadyLinked = true;
            break;
        }
    }
    if (!$alreadyLinked) {
        $customer['clickupJobs'][] = [
            'taskId'   => $taskId,
            'listId'   => $listId,
            'linkedAt' => (int) round(microtime(true) * 1000),
            'source'   => 'clickup-intake-webhook',
        ];
        $customer['updatedAt'] = date('F j, Y');
        $id = cuwhSanitizeId((string) ($customer['id'] ?? ''));
        if ($id !== null) {
            $path = $customersDir . DIRECTORY_SEPARATOR . $id . '.json';
            @file_put_contents($path, json_encode($customer, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
        }
    }
    return $customer;
}

/* ─── Routing ─────────────────────────────────────────────────────────────── */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if (isset($_GET['ping'])) {
        echo json_encode([
            'ok'             => true,
            'service'        => 'clickup-webhook',
            'intakeListId'   => INTAKE_LIST_ID,
            'apiKeyPresent'  => is_file($API_KEY_FILE),
            'secretPresent'  => is_file($SECRET_FILE),
        ]);
        exit;
    }
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST (or GET ?ping=1).']);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

/* Load API key + webhook secret. Both must be present. */
if (!is_file($SECRET_FILE)) {
    cuwhReply(503, ['ok' => false, 'error' => 'Webhook not configured (missing secret).'], $LOG_FILE,
        ['stage' => 'config', 'reason' => 'missing-secret']);
}
$secretJson = json_decode((string) @file_get_contents($SECRET_FILE), true);
$secret = is_array($secretJson) && isset($secretJson['secret']) && is_string($secretJson['secret'])
    ? trim($secretJson['secret'])
    : '';
if ($secret === '') {
    cuwhReply(503, ['ok' => false, 'error' => 'Webhook not configured (empty secret).'], $LOG_FILE,
        ['stage' => 'config', 'reason' => 'empty-secret']);
}

if (!is_file($API_KEY_FILE)) {
    cuwhReply(503, ['ok' => false, 'error' => 'ClickUp API key not configured.'], $LOG_FILE,
        ['stage' => 'config', 'reason' => 'missing-api-key']);
}
$apiKeyJson = json_decode((string) @file_get_contents($API_KEY_FILE), true);
$apiKey = is_array($apiKeyJson) && isset($apiKeyJson['apiKey']) && is_string($apiKeyJson['apiKey'])
    ? trim($apiKeyJson['apiKey'])
    : '';
if ($apiKey === '') {
    cuwhReply(503, ['ok' => false, 'error' => 'ClickUp API key empty.'], $LOG_FILE,
        ['stage' => 'config', 'reason' => 'empty-api-key']);
}

/* ─── Signature verification ──────────────────────────────────────────────── */
$rawBody = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? '');
$expected  = hash_hmac('sha256', $rawBody, $secret);
if ($signature === '' || !hash_equals($expected, $signature)) {
    cuwhReply(401, ['ok' => false, 'error' => 'Invalid signature.'], $LOG_FILE,
        ['stage' => 'signature', 'reason' => $signature === '' ? 'missing-header' : 'mismatch']);
}

/* ─── Payload parsing ─────────────────────────────────────────────────────── */
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    cuwhReply(200, ['ok' => false, 'error' => 'Invalid JSON payload.'], $LOG_FILE,
        ['stage' => 'parse', 'reason' => 'invalid-json']);
}

$event = (string) ($payload['event'] ?? '');

/* Local Test button support — short-circuit cleanly after the signature
 * has been verified. The setup page sends event=webhook.test. */
if ($event === 'webhook.test') {
    cuwhReply(200, [
        'ok'     => true,
        'mode'   => 'test',
        'event'  => $event,
        'note'   => 'Signature verified. Real taskCreated events will run end-to-end.',
    ], $LOG_FILE, ['stage' => 'test', 'event' => $event]);
}

if ($event !== 'taskCreated') {
    cuwhReply(200, ['ok' => true, 'skipped' => true, 'reason' => 'event-not-tracked', 'event' => $event],
        $LOG_FILE, ['stage' => 'filter', 'event' => $event]);
}

$taskId = (string) ($payload['task_id'] ?? '');
if ($taskId === '') {
    cuwhReply(200, ['ok' => false, 'error' => 'Missing task_id.'], $LOG_FILE,
        ['stage' => 'parse', 'reason' => 'missing-task-id']);
}

/* ─── Fetch the full task ─────────────────────────────────────────────────── */
$taskRes = cuwhClickUp('GET', '/task/' . rawurlencode($taskId), $apiKey);
if (!$taskRes['ok'] || !is_array($taskRes['body'])) {
    cuwhReply(200, [
        'ok'    => false,
        'error' => 'Could not fetch task from ClickUp.',
        'taskId' => $taskId,
        'status' => $taskRes['status'],
    ], $LOG_FILE, ['stage' => 'fetch-task', 'taskId' => $taskId, 'apiStatus' => $taskRes['status']]);
}
$task = $taskRes['body'];

/* Filter to the intake list. ClickUp can deliver events that the webhook
 * subscribed to via list scope, but a multi-list task could still appear here.
 * We trust the task's primary `list.id`. */
$taskListId = (string) ($task['list']['id'] ?? '');
if ($taskListId !== INTAKE_LIST_ID) {
    cuwhReply(200, [
        'ok'      => true,
        'skipped' => true,
        'reason'  => 'list-not-tracked',
        'taskId'  => $taskId,
        'listId'  => $taskListId,
    ], $LOG_FILE, ['stage' => 'filter', 'taskId' => $taskId, 'listId' => $taskListId]);
}

/* ─── Idempotency: bail if Customer ID is already populated ───────────────── */
$existingCustomerId = trim((string) (cuwhFieldValue($task, FIELD_CUSTOMER_ID) ?? ''));
if ($existingCustomerId !== '') {
    cuwhReply(200, [
        'ok'         => true,
        'skipped'    => true,
        'reason'     => 'already-linked',
        'taskId'     => $taskId,
        'customerId' => $existingCustomerId,
    ], $LOG_FILE, ['stage' => 'idempotent', 'taskId' => $taskId, 'customerId' => $existingCustomerId]);
}

/* ─── Extract intake fields ───────────────────────────────────────────────── */
$nameRaw    = trim((string) (cuwhFieldValue($task, FIELD_NAME) ?? ''));
if ($nameRaw === '') {
    $nameRaw = trim((string) ($task['name'] ?? ''));
}
[$firstName, $lastName] = cuwhSplitName($nameRaw);

$emailRaw   = trim((string) (cuwhFieldValue($task, FIELD_EMAIL) ?? ''));
$addressRaw = cuwhExtractAddress(cuwhFieldValue($task, FIELD_ADDRESS));

$phoneRaw   = cuwhExtractPhone(cuwhFieldValue($task, FIELD_PHONE_PRI));
$phoneSrc   = $phoneRaw !== '' ? 'field-phone' : '';
if ($phoneRaw === '') {
    $phoneRaw = cuwhExtractPhone(cuwhFieldValue($task, FIELD_PHONE_FALL));
    if ($phoneRaw !== '') {
        $phoneSrc = 'field-shorttext';
    }
}
if ($phoneRaw === '') {
    $phoneRaw = cuwhPhoneFromDescription((string) ($task['description'] ?? ''));
    if ($phoneRaw !== '') {
        $phoneSrc = 'description';
    }
}
$phoneDigits = cuwhDigitsOnly($phoneRaw);

/* ─── Match or create customer ────────────────────────────────────────────── */
if (!is_dir($CUSTOMERS_DIR)) {
    if (!@mkdir($CUSTOMERS_DIR, 0755, true) && !is_dir($CUSTOMERS_DIR)) {
        cuwhReply(200, ['ok' => false, 'error' => 'Customers directory not writable.'], $LOG_FILE,
            ['stage' => 'fs', 'reason' => 'customers-dir', 'taskId' => $taskId]);
    }
}

$matched = null;
if ($phoneDigits !== '' && strlen($phoneDigits) >= 7) {
    $matched = cuwhFindCustomerByPhone($CUSTOMERS_DIR, $phoneDigits);
}

$customerId = '';
$createdNew = false;
if (is_array($matched) && !empty($matched['id'])) {
    $customerId = (string) $matched['id'];
    /* Append this task to the matched customer's clickupJobs so the linkage
     * is bidirectional. Idempotent — won't double-add. */
    cuwhAttachJobToCustomer($matched, $CUSTOMERS_DIR, $taskId, INTAKE_LIST_ID);
} else {
    $customerId = cuwhGenCustomerId();
    $today      = date('F j, Y');
    /* Match the schema written by OGM_CustomerDB.html saveCustomer() so the
     * record renders correctly in the existing list. */
    $newCustomer = [
        'id'          => $customerId,
        'firstName'   => $firstName,
        'lastName'    => $lastName,
        'phone'       => $phoneRaw,
        'phone2'      => '',
        'email'       => $emailRaw,
        'email2'      => '',
        'svcStreet'   => $addressRaw,
        'svcCity'     => '',
        'sameAddr'    => true,
        'billStreet'  => $addressRaw,
        'billCity'    => '',
        'jobName'     => trim((string) ($task['name'] ?? '')),
        'status'      => 'new',
        'rep'         => '',
        'referral'    => '',
        'source'      => 'clickup-intake',
        'notes'       => '',
        'createdAt'   => $today,
        'updatedAt'   => $today,
        'quotes'      => [],
        'notesLog'    => [],
        'clickupJobs' => [
            [
                'taskId'   => $taskId,
                'listId'   => INTAKE_LIST_ID,
                'linkedAt' => (int) round(microtime(true) * 1000),
                'source'   => 'clickup-intake-webhook',
            ],
        ],
    ];
    $safeId = cuwhSanitizeId($customerId);
    if ($safeId === null) {
        cuwhReply(200, ['ok' => false, 'error' => 'Generated customer id failed sanitization.'], $LOG_FILE,
            ['stage' => 'create-customer', 'taskId' => $taskId, 'customerId' => $customerId]);
    }
    $custPath = $CUSTOMERS_DIR . DIRECTORY_SEPARATOR . $safeId . '.json';
    $written  = @file_put_contents($custPath, json_encode($newCustomer, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    if ($written === false) {
        cuwhReply(200, ['ok' => false, 'error' => 'Could not write customer record.'], $LOG_FILE,
            ['stage' => 'create-customer', 'taskId' => $taskId, 'customerId' => $customerId]);
    }
    $createdNew = true;
}

/* ─── Write the customer ID back into the task field ──────────────────────── */
$writeRes = cuwhClickUp(
    'POST',
    '/task/' . rawurlencode($taskId) . '/field/' . rawurlencode(FIELD_CUSTOMER_ID),
    $apiKey,
    ['value' => $customerId]
);

$result = [
    'ok'          => $writeRes['ok'],
    'taskId'      => $taskId,
    'listId'      => $taskListId,
    'customerId'  => $customerId,
    'createdNew'  => $createdNew,
    'phoneSource' => $phoneSrc,
    'fieldWriteStatus' => $writeRes['status'],
];
if (!$writeRes['ok']) {
    $result['error'] = 'Could not write Customer ID field';
    $result['apiError'] = $writeRes['error'] ?? '';
}

cuwhLog([
    'stage'       => 'done',
    'taskId'      => $taskId,
    'customerId'  => $customerId,
    'createdNew'  => $createdNew,
    'phoneSource' => $phoneSrc,
    'fieldWriteOk' => $writeRes['ok'],
    'fieldWriteStatus' => $writeRes['status'],
], $LOG_FILE);

http_response_code(200);
echo json_encode($result, JSON_UNESCAPED_SLASHES);
