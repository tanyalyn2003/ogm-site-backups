<?php

/**
 * Server-side CRM data: customers + lightweight quote summaries for Hub / Customer DB.
 * Same auth session as quotes-api.php (sign in via index.php first).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

header('Content-Type: application/json; charset=UTF-8');

if (!qtIsLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    exit;
}

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$customersDir = __DIR__ . DIRECTORY_SEPARATOR . 'customers';
$summariesDir = __DIR__ . DIRECTORY_SEPARATOR . 'quote_summaries';
$quotesDir = __DIR__ . DIRECTORY_SEPARATOR . 'quotes';

if (!is_dir($customersDir)) {
    if (!@mkdir($customersDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not create customers directory.']);
        exit;
    }
}
if (!is_dir($summariesDir)) {
    if (!@mkdir($summariesDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not create quote_summaries directory.']);
        exit;
    }
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'customer-phone-dedupe-lib.php';

/** Same rules as quotesApiSanitizeId — safe filename segment. */
function customersApiSanitizeId($raw) {
    $s = (string) $raw;
    $s = preg_replace('/[^A-Za-z0-9._-]/', '', $s);
    if ($s === null) {
        return null;
    }
    $s = substr($s, 0, 80);
    return $s === '' ? null : $s;
}

function customersApiCustomerPath($dir, $id) {
    return $dir . DIRECTORY_SEPARATOR . $id . '.json';
}

function customersApiSummaryPath($dir, $quoteNumber) {
    return $dir . DIRECTORY_SEPARATOR . $quoteNumber . '.json';
}

function customersApiQuotePath($dir, $quoteNumber) {
    return $dir . DIRECTORY_SEPARATOR . $quoteNumber . '.json';
}

function customersApiDisplayName(array $data) {
    $displayName = trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''));
    if ($displayName === '') {
        $displayName = (string) ($data['name'] ?? '');
    }

    return $displayName;
}

function customersApiCustomerSearchBlob(array $data) {
    $parts = [
        customersApiDisplayName($data),
        $data['name'] ?? '',
        $data['phone'] ?? '',
        $data['phone2'] ?? '',
        $data['email'] ?? '',
        $data['svcStreet'] ?? '',
        $data['svcCity'] ?? '',
        $data['billStreet'] ?? '',
        $data['billCity'] ?? '',
        $data['jobName'] ?? '',
        $data['id'] ?? '',
    ];

    return strtolower(implode(' ', array_filter(array_map('strval', $parts), static function ($s) {
        return trim($s) !== '';
    })));
}

function customersApiMatchesSearchQuery(array $data, string $q) {
    $q = strtolower(trim($q));
    if ($q === '') {
        return true;
    }
    $blob = customersApiCustomerSearchBlob($data);
    if ($blob !== '' && str_contains($blob, $q)) {
        return true;
    }
    $tokens = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$tokens) {
        return false;
    }
    foreach ($tokens as $token) {
        if (!str_contains($blob, $token)) {
            return false;
        }
    }

    return true;
}

function customersApiNormPhoneDigits($raw) {
    return preg_replace('/[^0-9]/', '', (string) $raw);
}

function customersApiNormEmail($raw) {
    $e = strtolower(trim((string) $raw));

    return $e;
}

function customersApiSplitDisplayName($full) {
    $s = trim((string) $full);
    if ($s === '') {
        return ['firstName' => '', 'lastName' => ''];
    }
    $parts = preg_split('/\s+/', $s) ?: [];
    if (count($parts) === 1) {
        return ['firstName' => $parts[0], 'lastName' => ''];
    }

    return ['firstName' => $parts[0], 'lastName' => implode(' ', array_slice($parts, 1))];
}

function customersApiCanCreateFromQuoteFields(array $fields) {
    $phone = customersApiNormPhoneDigits($fields['phone'] ?? '');
    $email = customersApiNormEmail($fields['email'] ?? '');
    $name = trim((string) ($fields['name'] ?? ''));
    $job = trim((string) ($fields['jobName'] ?? ($fields['job'] ?? '')));
    if (strlen($phone) >= 7) {
        return true;
    }
    if ($email !== '' && str_contains($email, '@')) {
        return true;
    }
    if ($name !== '') {
        return true;
    }

    return $job !== '';
}

/** Exact phone match (7+ digits), then exact primary email. */
function customersApiFindCustomerIdByContact($customersDir, $phoneDigits, $emailLow) {
    $wantPh = strlen($phoneDigits) >= 7;
    $wantEm = $emailLow !== '' && str_contains($emailLow, '@');
    if (!$wantPh && !$wantEm) {
        return null;
    }

    $glob = glob($customersDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    foreach ($glob as $file) {
        $raw = @file_get_contents($file);
        if (!$raw) {
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['id'])) {
            continue;
        }
        if ($wantPh) {
            $p = customersApiNormPhoneDigits($data['phone'] ?? '');
            if ($p !== '' && strlen($p) >= 7 && $p === $phoneDigits) {
                return (string) $data['id'];
            }
        }
        if ($wantEm) {
            $em = customersApiNormEmail($data['email'] ?? '');
            if ($em !== '' && $em === $emailLow) {
                return (string) $data['id'];
            }
        }
    }

    return null;
}

function customersApiMergeCustomerFromQuoteFields(array &$cust, array $fields) {
    $fill = static function ($key, $val) use (&$cust) {
        $t = trim((string) $val);
        if ($t === '') {
            return;
        }
        if (!isset($cust[$key]) || trim((string) $cust[$key]) === '') {
            $cust[$key] = $t;
        }
    };

    $rawName = trim((string) ($fields['name'] ?? ''));
    $nameForSplit = $rawName !== '' ? $rawName : trim((string) ($fields['jobName'] ?? ($fields['job'] ?? '')));
    $parts = customersApiSplitDisplayName($nameForSplit);
    if (trim((string) ($cust['firstName'] ?? '')) === '' && $parts['firstName'] !== '') {
        $cust['firstName'] = $parts['firstName'];
    }
    if (trim((string) ($cust['lastName'] ?? '')) === '' && $parts['lastName'] !== '') {
        $cust['lastName'] = $parts['lastName'];
    }

    $fill('phone', $fields['phone'] ?? '');
    $fill('email', $fields['email'] ?? '');

    $svcStr = trim((string) ($fields['installAddr'] ?? ($fields['svcStreet'] ?? ($fields['addr'] ?? ''))));
    $svcCit = trim((string) ($fields['installCity'] ?? ($fields['svcCity'] ?? ($fields['city'] ?? ''))));
    $fill('svcStreet', $svcStr);
    $fill('svcCity', $svcCit);
    $fill('jobName', $fields['jobName'] ?? ($fields['job'] ?? ''));
    $fill('rep', $fields['rep'] ?? ($fields['salesperson'] ?? ''));

    if (($cust['sameAddr'] ?? true) !== false) {
        if (trim((string) ($cust['billStreet'] ?? '')) === '' && trim((string) ($cust['svcStreet'] ?? '')) !== '') {
            $cust['billStreet'] = $cust['svcStreet'];
        }
        if (trim((string) ($cust['billCity'] ?? '')) === '' && trim((string) ($cust['svcCity'] ?? '')) !== '') {
            $cust['billCity'] = $cust['svcCity'];
        }
    }
}

function customersApiApplyQuoteToCustomer(array &$cust, array $quote) {
    if (!isset($cust['quotes']) || !is_array($cust['quotes'])) {
        $cust['quotes'] = [];
    }
    $invNum = (string) ($quote['invoiceNum'] ?? ($quote['quoteNumber'] ?? ''));
    if ($invNum === '') {
        return;
    }
    $entry = [
        'invoiceNum'  => $invNum,
        'quoteNumber' => (string) ($quote['quoteNumber'] ?? $invNum),
        'jobName'     => (string) ($quote['jobName'] ?? ''),
        'stone'       => (string) ($quote['stone'] ?? ''),
        'date'        => (string) ($quote['date'] ?? ''),
        'total'       => $quote['total'] ?? null,
        'profit'      => $quote['profit'] ?? null,
        'status'      => (string) ($quote['status'] ?? 'quoted'),
        'productLine' => (string) ($quote['productLine'] ?? ''),
    ];
    $idx = -1;
    foreach ($cust['quotes'] as $i => $q) {
        if (!is_array($q)) {
            continue;
        }
        $qn = (string) ($q['quoteNumber'] ?? ($q['invoiceNum'] ?? ''));
        if ($qn !== '' && $qn === $invNum) {
            $idx = $i;
            break;
        }
    }
    if ($idx >= 0) {
        $cust['quotes'][$idx] = array_merge($cust['quotes'][$idx], $entry);
    } else {
        $cust['quotes'][] = $entry;
    }
    if (($cust['status'] ?? '') === 'prospect') {
        $cust['status'] = 'quoted';
    }
    $cust['updatedAt'] = gmdate('Y-m-d');
}

function customersApiGenCustomerId() {
    return 'C' . strtoupper(base_convert((string) (int) (microtime(true) * 1000), 10, 36))
        . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
}

function customersApiComparableValue($value) {
    if ($value === null) {
        return '';
    }
    if (is_float($value) || is_int($value)) {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    return trim((string) $value);
}

function customersApiAppendSummaryChangeLog($oldSummary, array &$summary) {
    if (!is_array($oldSummary)) {
        return;
    }
    $fields = [
        'name' => 'Customer name',
        'job' => 'Job description',
        'salesperson' => 'Salesperson',
        'invoiceNumber' => 'Invoice #',
        'invoiceDate' => 'Invoice date',
        'invoiceTotal' => 'Invoice amount',
        'installDate' => 'Install date',
        'hrsEst' => 'Estimated hours',
        'hrsAct' => 'Actual hours / time on site',
        'jobCode' => 'Job code',
        'jobType' => 'Job type',
        'jtNotes' => 'Job tracking notes',
        'cosTotal' => 'Material/COS',
        'cosTax' => 'COS tax',
        'cosLabor' => 'Labor line',
        'taxMode' => 'Tax mode',
        'taxBackoutAmount' => 'Backed-out tax',
        'taxBase' => 'Tax base',
        'taxExemptReason' => 'Tax exempt reason',
        'sqft' => 'Square feet',
        'totalSF' => 'Square feet',
    ];
    $changes = [];
    foreach ($fields as $key => $label) {
        $old = customersApiComparableValue($oldSummary[$key] ?? '');
        $new = customersApiComparableValue($summary[$key] ?? '');
        if ($old !== $new) {
            $changes[] = [
                'field' => $label,
                'from' => $old,
                'to' => $new,
            ];
        }
    }
    if (!$changes) {
        return;
    }
    $log = isset($oldSummary['_changeLog']) && is_array($oldSummary['_changeLog']) ? $oldSummary['_changeLog'] : [];
    $log[] = [
        'at' => gmdate('c'),
        'by' => qtUsername() ?: 'Signed-in user',
        'changes' => $changes,
    ];
    if (count($log) > 100) {
        $log = array_slice($log, -100);
    }
    $summary['_changeLog'] = $log;
}

function customersApiPatchFullQuoteFromStoneWork($quotesDir, $quoteNumber, array $summary) {
    $path = customersApiQuotePath($quotesDir, $quoteNumber);
    if (!is_file($path)) {
        return false;
    }
    $raw = @file_get_contents($path);
    $full = $raw ? json_decode($raw, true) : null;
    if (!is_array($full)) {
        return false;
    }
    $old = $full;
    $full['name'] = (string) ($summary['name'] ?? ($full['name'] ?? ''));
    $full['job'] = (string) ($summary['job'] ?? ($full['job'] ?? ''));
    $full['sp'] = (string) ($summary['salesperson'] ?? ($summary['rep'] ?? ($full['sp'] ?? '')));
    $full['salesperson'] = $full['sp'];
    $full['invoiceNumber'] = (string) ($summary['invoiceNumber'] ?? ($full['invoiceNumber'] ?? ''));
    $full['invoiceTotal'] = $summary['invoiceTotal'] ?? ($full['invoiceTotal'] ?? 0);
    $full['installDate'] = (string) ($summary['installDate'] ?? ($full['installDate'] ?? ''));
    $full['hrsAct'] = $summary['hrsAct'] ?? ($full['hrsAct'] ?? 0);
    $full['hrsEst'] = $summary['hrsEst'] ?? ($full['hrsEst'] ?? 0);
    $full['cosTotal'] = $summary['cosTotal'] ?? ($full['cosTotal'] ?? 0);
    if (array_key_exists('cosTax', $summary)) {
        $full['cosTax'] = $summary['cosTax'];
    }
    if (array_key_exists('taxMode', $summary)) {
        $full['taxMode'] = (string) $summary['taxMode'];
    }
    if (array_key_exists('taxBackoutAmount', $summary)) {
        $full['taxBackoutAmount'] = $summary['taxBackoutAmount'];
    }
    if (array_key_exists('taxBase', $summary)) {
        $full['taxBase'] = $summary['taxBase'];
    }
    if (array_key_exists('useNcRpSplit', $summary)) {
        $full['useNcRpSplit'] = $summary['useNcRpSplit'] !== false;
    }
    $full['jobCode'] = (string) ($summary['jobCode'] ?? ($full['jobCode'] ?? ''));
    $full['jobType'] = (string) ($summary['jobType'] ?? ($full['jobType'] ?? ''));
    if (isset($full['state']) && is_array($full['state'])) {
        if (!isset($full['state']['customer']) || !is_array($full['state']['customer'])) {
            $full['state']['customer'] = [];
        }
        $full['state']['customer']['name'] = $full['name'];
        $full['state']['customer']['job'] = $full['job'];
        $full['state']['customer']['salesperson'] = $full['sp'];
    }
    customersApiAppendSummaryChangeLog($old, $full);
    return @file_put_contents($path, json_encode($full, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
}

if ($action === 'list-customers') {
    $customers = [];
    $glob = glob($customersDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    foreach ($glob as $file) {
        $name = basename($file);
        if ($name === '' || $name[0] === '_') {
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
        $customers[] = $data;
    }
    echo json_encode(['ok' => true, 'customers' => array_values($customers)], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'get-customer') {
    $id = customersApiSanitizeId($_GET['id'] ?? '');
    if ($id === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing or invalid id.']);
        exit;
    }
    $path = customersApiCustomerPath($customersDir, $id);
    if (!is_file($path)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Customer not found.']);
        exit;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not read customer.']);
        exit;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Invalid customer JSON.']);
        exit;
    }
    echo json_encode(['ok' => true, 'customer' => $data], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'save-customer') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing request body.']);
        exit;
    }
    if (strlen($raw) > 2_000_000) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'Payload too large.']);
        exit;
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON.']);
        exit;
    }
    $customer = isset($body['customer']) && is_array($body['customer']) ? $body['customer'] : $body;
    $id = customersApiSanitizeId($customer['id'] ?? '');
    if ($id === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Customer id missing or invalid.']);
        exit;
    }
    $customer['id'] = $id;
    $path = customersApiCustomerPath($customersDir, $id);
    $ok = @file_put_contents($path, json_encode($customer, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    if ($ok === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not save customer.']);
        exit;
    }
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

if ($action === 'delete-customer') {
    $id = customersApiSanitizeId($_GET['id'] ?? '');
    if ($id === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing or invalid id.']);
        exit;
    }
    $path = customersApiCustomerPath($customersDir, $id);
    if (!is_file($path)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Customer not found.']);
        exit;
    }
    if (!@unlink($path)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not delete customer.']);
        exit;
    }
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

if ($action === 'list-quote-summaries') {
    $summaries = [];
    $glob = glob($summariesDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    foreach ($glob as $file) {
        $name = basename($file);
        if ($name === '' || $name[0] === '_') {
            continue;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }
        $summaries[] = $data;
    }
    echo json_encode(['ok' => true, 'summaries' => array_values($summaries)], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'get-quote-summary') {
    $qn = customersApiSanitizeId($_GET['quoteNumber'] ?? '');
    if ($qn === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing or invalid quoteNumber.']);
        exit;
    }
    $path = customersApiSummaryPath($summariesDir, $qn);
    if (!is_file($path)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Summary not found.']);
        exit;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not read summary.']);
        exit;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Invalid summary JSON.']);
        exit;
    }
    echo json_encode(['ok' => true, 'summary' => $data], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'save-quote-summary') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing request body.']);
        exit;
    }
    if (strlen($raw) > 500_000) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'Payload too large.']);
        exit;
    }
    $body = json_decode($raw, true);
    if (!is_array($body) || !isset($body['summary']) || !is_array($body['summary'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Expected { "summary": { ... } }.']);
        exit;
    }
    $summary = $body['summary'];
    $qn = customersApiSanitizeId($summary['quoteNumber'] ?? '');
    if ($qn === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'summary.quoteNumber missing or invalid.']);
        exit;
    }
    $summary['quoteNumber'] = $qn;
    $path = customersApiSummaryPath($summariesDir, $qn);
    if (is_file($path)) {
        $oldRaw = @file_get_contents($path);
        $oldSummary = $oldRaw ? json_decode($oldRaw, true) : null;
        if (is_array($oldSummary)) {
            customersApiAppendSummaryChangeLog($oldSummary, $summary);
        }
    }
    $ok = @file_put_contents($path, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    if ($ok === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not save quote summary.']);
        exit;
    }
    echo json_encode(['ok' => true, 'quoteNumber' => $qn]);
    exit;
}

if ($action === 'patch-stone-work') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    $raw = file_get_contents('php://input');
    $body = json_decode((string) $raw, true);
    if (!is_array($body) || !isset($body['patch']) || !is_array($body['patch'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Expected { "quoteNumber": "...", "patch": { ... } }.']);
        exit;
    }
    $qn = customersApiSanitizeId($body['quoteNumber'] ?? ($body['patch']['quoteNumber'] ?? ''));
    if ($qn === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'quoteNumber missing or invalid.']);
        exit;
    }
    $path = customersApiSummaryPath($summariesDir, $qn);
    if (!is_file($path)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Quote summary not found.']);
        exit;
    }
    $oldRaw = @file_get_contents($path);
    $summary = $oldRaw ? json_decode($oldRaw, true) : null;
    if (!is_array($summary)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Invalid quote summary JSON.']);
        exit;
    }
    $patch = $body['patch'];
    $allowed = [
        'name', 'job', 'salesperson', 'rep', 'sqft', 'totalSF',
        'invoiceNumber', 'invoiceTotal', 'invoiceDate', 'installDate',
        'hrsAct', 'hrsEst', 'stoneWorkHours', 'cosTotal', 'jobCode', 'jobType',
        'taxMode', 'taxBackoutAmount', 'taxBase', 'taxExemptReason', 'useNcRpSplit', 'cosTax',
    ];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $patch)) {
            $summary[$key] = $patch[$key];
        }
    }
    $summary['quoteNumber'] = $qn;
    $summary['productLine'] = 'stone';
    $summary['workflowPhase'] = 'Invoiced';
    $summary['status'] = 'invoiced';
    $summary['updatedAt'] = gmdate('c');
    customersApiAppendSummaryChangeLog(json_decode((string) $oldRaw, true), $summary);
    $ok = @file_put_contents($path, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    if ($ok === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not save quote summary.']);
        exit;
    }
    $fullUpdated = customersApiPatchFullQuoteFromStoneWork($quotesDir, $qn, $summary);
    echo json_encode(['ok' => true, 'quoteNumber' => $qn, 'summary' => $summary, 'fullQuoteUpdated' => $fullUpdated], JSON_UNESCAPED_SLASHES);
    exit;
}

// ── ACTION: link-clickup-task ────────────────────────────────────────────────
// POST  customers-api.php?action=link-clickup-task
// Body: { "customerId": "abc123", "taskId": "86xyz...", "quoteNumber": "Q-2026-4821" }
//
// Adds the ClickUp task ID to customers/{id}.clickupJobs array.
// Each entry: { taskId, quoteNumber, createdAt }
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'link-clickup-task') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    $raw = file_get_contents('php://input');
    $body = json_decode((string) $raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON.']);
        exit;
    }

    $id         = customersApiSanitizeId($body['customerId'] ?? '');
    $taskId     = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($body['taskId'] ?? ''));
    $quoteNum   = customersApiSanitizeId($body['quoteNumber'] ?? '') ?? '';

    if (!$id || !$taskId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'customerId and taskId are required.']);
        exit;
    }

    $path = customersApiCustomerPath($customersDir, $id);
    if (!is_file($path)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Customer not found.']);
        exit;
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Corrupt customer record.']);
        exit;
    }

    if (!isset($data['clickupJobs']) || !is_array($data['clickupJobs'])) {
        $data['clickupJobs'] = [];
    }

    $already = false;
    foreach ($data['clickupJobs'] as $entry) {
        if (isset($entry['taskId']) && $entry['taskId'] === $taskId) {
            $already = true;
            break;
        }
    }

    if (!$already) {
        $data['clickupJobs'][] = [
            'taskId'      => $taskId,
            'quoteNumber' => $quoteNum,
            'createdAt'   => gmdate('c'),
        ];
    }

    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    echo json_encode(['ok' => true, 'linked' => !$already, 'taskId' => $taskId]);
    exit;
}

// ── ACTION: get-customer-jobs ────────────────────────────────────────────────
// GET  customers-api.php?action=get-customer-jobs&id=abc123
//
// Returns the customer's clickupJobs array.
// Job Tracking calls this during prefillNewJob() to check for existing tasks
// and warn the user before creating a duplicate.
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'get-customer-jobs') {
    $id = customersApiSanitizeId($_GET['id'] ?? '');
    if (!$id) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing id.']);
        exit;
    }

    $path = customersApiCustomerPath($customersDir, $id);
    if (!is_file($path)) {
        echo json_encode(['ok' => true, 'jobs' => []]);
        exit;
    }

    $data = json_decode((string) file_get_contents($path), true);
    $jobs = (is_array($data) && isset($data['clickupJobs']) && is_array($data['clickupJobs']))
        ? $data['clickupJobs']
        : [];

    echo json_encode(['ok' => true, 'jobs' => array_values($jobs)], JSON_UNESCAPED_SLASHES);
    exit;
}

// ── ACTION: add-note ─────────────────────────────────────────────────────────
// POST  customers-api.php?action=add-note
// Body: { "customerId": "abc123", "taskId": "86xyz...", "text": "...", "user": "Tanya" }
//
// Replaces the localStorage notes in OGM_JobTracking.html.
// Notes are stored on the customer record so every device sees them.
// Also stores notes indexed by taskId so Job Tracking can load per-job notes.
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'add-note') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    $raw  = file_get_contents('php://input');
    $body = json_decode((string) $raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON.']);
        exit;
    }

    $customerId = customersApiSanitizeId($body['customerId'] ?? '');
    $taskId     = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($body['taskId'] ?? ''));
    $text       = trim((string) ($body['text'] ?? ''));
    $user       = htmlspecialchars(trim((string) ($body['user'] ?? 'Rep')), ENT_QUOTES, 'UTF-8');

    if (!$taskId || $text === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'taskId and text are required.']);
        exit;
    }

    $note = [
        'taskId' => $taskId,
        'text'   => $text,
        'user'   => $user,
        'date'   => gmdate('c'),
    ];

    if ($customerId) {
        $custPath = customersApiCustomerPath($customersDir, $customerId);
        if (is_file($custPath)) {
            $data = json_decode((string) file_get_contents($custPath), true);
            if (is_array($data)) {
                if (!isset($data['notes']) || !is_array($data['notes'])) {
                    $data['notes'] = [];
                }
                $data['notes'][] = $note;
                @file_put_contents($custPath, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
        }
    }

    $notesDir  = __DIR__ . DIRECTORY_SEPARATOR . 'task_notes';
    if (!is_dir($notesDir)) {
        @mkdir($notesDir, 0755, true);
    }
    $notePath  = $notesDir . DIRECTORY_SEPARATOR . $taskId . '.json';
    $existing  = [];
    if (is_file($notePath)) {
        $existing = json_decode((string) file_get_contents($notePath), true);
        if (!is_array($existing)) $existing = [];
    }
    $existing[] = $note;
    @file_put_contents($notePath, json_encode($existing, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    echo json_encode(['ok' => true, 'note' => $note]);
    exit;
}

// ── ACTION: get-notes ────────────────────────────────────────────────────────
// GET  customers-api.php?action=get-notes&taskId=86xyz...
//
// Returns all server-side notes for a ClickUp task ID.
// Job Tracking calls this when opening the Notes tab.
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'get-notes') {
    $taskId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($_GET['taskId'] ?? ''));
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing taskId.']);
        exit;
    }

    $notesDir  = __DIR__ . DIRECTORY_SEPARATOR . 'task_notes';
    $notePath  = $notesDir . DIRECTORY_SEPARATOR . $taskId . '.json';
    $notes     = [];
    if (is_file($notePath)) {
        $raw = file_get_contents($notePath);
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) $notes = $decoded;
    }

    echo json_encode(['ok' => true, 'notes' => array_values($notes)], JSON_UNESCAPED_SLASHES);
    exit;
}

// ── ACTION: search-by-name ───────────────────────────────────────────────────
// GET  customers-api.php?action=search-by-name&q=Jane+Smith&limit=12
//
// Returns customers whose name / phone / address fields match the query
// (substring or all whitespace-separated tokens). Used by stone quoter
// customer-name autocomplete.
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'search-by-name') {
    $q = strtolower(trim((string) ($_GET['q'] ?? $_GET['name'] ?? '')));
    $limit = (int) ($_GET['limit'] ?? 12);
    if ($limit < 1) {
        $limit = 12;
    }
    if ($limit > 50) {
        $limit = 50;
    }

    if (strlen($q) < 2) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Query too short (min 2 characters).']);
        exit;
    }

    $matches = [];
    $glob    = glob($customersDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    foreach ($glob as $file) {
        $raw  = @file_get_contents($file);
        if (!$raw) {
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['id'])) {
            continue;
        }
        if (!customersApiMatchesSearchQuery($data, $q)) {
            continue;
        }

        $displayName = customersApiDisplayName($data);
        $matches[] = [
            'id'        => $data['id'],
            'name'      => $displayName,
            'phone'     => $data['phone'] ?? '',
            'email'     => $data['email'] ?? '',
            'svcStreet' => $data['svcStreet'] ?? '',
            'svcCity'   => $data['svcCity'] ?? '',
        ];
    }

    usort($matches, static function ($a, $b) {
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    echo json_encode([
        'ok'        => true,
        'customers' => array_slice(array_values($matches), 0, $limit),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ── ACTION: search-by-phone ──────────────────────────────────────────────────
// GET  customers-api.php?action=search-by-phone&phone=9105550000
//
// Returns customers whose phone number contains the search string.
// Job Tracking uses this during "New Job" creation to detect duplicates
// when no customerId was passed from the quoter.
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'search-by-phone') {
    $phone = trim((string) ($_GET['phone'] ?? ''));
    $phone = preg_replace('/[^0-9]/', '', $phone); // digits only for fuzzy match

    if (strlen($phone) < 7) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Phone too short to search.']);
        exit;
    }

    $matches = [];
    $glob    = glob($customersDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    foreach ($glob as $file) {
        $raw  = @file_get_contents($file);
        if (!$raw) continue;
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['id'])) continue;

        $custPhone = preg_replace('/[^0-9]/', '', (string) ($data['phone'] ?? ''));
        if ($custPhone !== '' && (str_contains($custPhone, $phone) || str_contains($phone, $custPhone))) {
            $displayName = customersApiDisplayName($data);

            $matches[] = [
                'id'          => $data['id'],
                'name'        => $displayName,
                'phone'       => $data['phone'] ?? '',
                'email'       => $data['email'] ?? '',
                'clickupJobs' => $data['clickupJobs'] ?? [],
            ];
        }
    }

    echo json_encode(['ok' => true, 'customers' => array_values($matches)], JSON_UNESCAPED_SLASHES);
    exit;
}

// ── ACTION: dedupe-customers-by-phone ─────────────────────────────────────────
// POST  customers-api.php?action=dedupe-customers-by-phone
//
// Groups customers by normalized digits on primary phone (phone, else phone2).
// Keeps the richest record (quotes + ClickUp jobs), merges scalar fields / quotes /
// jobs / notes into it, deletes duplicate JSON files, and rewires quote_summaries
// linkedCustomerId values that pointed at removed ids.
// ───────────────────────────────────────────────────────────────────────────────
if ($action === 'dedupe-customers-by-phone') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    $result = customersApiExecutePhoneDedupe($customersDir, $summariesDir);
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES);
    exit;
}

// ── ACTION: dedupe-customers-by-email ─────────────────────────────────────────
// POST  customers-api.php?action=dedupe-customers-by-email
//
// Groups customers by exact normalized primary email (email, else email2).
// Keeps the richest record and uses the same merge/remap/backup behavior as
// phone dedupe. This is intentionally exact-match only.
// ───────────────────────────────────────────────────────────────────────────────
if ($action === 'dedupe-customers-by-email') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    $result = customersApiExecuteEmailDedupe($customersDir, $summariesDir);
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES);
    exit;
}

// ── ACTION: upsert-from-quote ────────────────────────────────────────────────
// POST  customers-api.php?action=upsert-from-quote
// Body: { "customerId"?: "C…", "fields": { name, phone, email, addr/city/… }, "source"?: "…" }
// Finds by linked id, else phone/name; merges contact fields only (no quote rows on customer).
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'upsert-from-quote') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing request body.']);
        exit;
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON.']);
        exit;
    }

    $fields = isset($body['fields']) && is_array($body['fields']) ? $body['fields'] : [];
    $linkedId = customersApiSanitizeId($body['customerId'] ?? '');
    $source = trim((string) ($body['source'] ?? 'Quoter — save'));

    $phoneDigits = customersApiNormPhoneDigits($fields['phone'] ?? '');
    $emailLow = customersApiNormEmail($fields['email'] ?? '');

    $targetId = null;
    $created = false;

    if ($linkedId !== null) {
        $path = customersApiCustomerPath($customersDir, $linkedId);
        if (is_file($path)) {
            $targetId = $linkedId;
        }
    }

    if ($targetId === null) {
        $targetId = customersApiFindCustomerIdByContact($customersDir, $phoneDigits, $emailLow);
    }

    if ($targetId === null && !customersApiCanCreateFromQuoteFields($fields)) {
        echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'insufficient_contact']);
        exit;
    }

    if ($targetId === null) {
        $targetId = customersApiGenCustomerId();
        $created = true;
        $rawName = trim((string) ($fields['name'] ?? ''));
        $nameForSplit = $rawName !== '' ? $rawName : trim((string) ($fields['jobName'] ?? ($fields['job'] ?? '')));
        $parts = customersApiSplitDisplayName($nameForSplit);
        $svcStr = trim((string) ($fields['installAddr'] ?? ($fields['svcStreet'] ?? ($fields['addr'] ?? ''))));
        $svcCit = trim((string) ($fields['installCity'] ?? ($fields['svcCity'] ?? ($fields['city'] ?? ''))));
        $cust = [
            'id'        => $targetId,
            'firstName' => $parts['firstName'] !== '' ? $parts['firstName'] : 'Lead',
            'lastName'  => $parts['lastName'],
            'phone'     => (string) ($fields['phone'] ?? ''),
            'phone2'    => '',
            'email'     => (string) ($fields['email'] ?? ''),
            'email2'    => '',
            'svcStreet' => $svcStr,
            'svcCity'   => $svcCit,
            'sameAddr'  => true,
            'billStreet'=> $svcStr,
            'billCity'  => $svcCit,
            'jobName'   => (string) ($fields['jobName'] ?? ($fields['job'] ?? '')),
            'status'    => 'prospect',
            'rep'       => (string) ($fields['rep'] ?? ($fields['salesperson'] ?? '')),
            'referral'  => '',
            'source'    => $source,
            'notes'     => '',
            'createdAt' => gmdate('F j, Y'),
            'updatedAt' => gmdate('Y-m-d'),
            'quotes'    => [],
            'notesLog'  => [],
        ];
    } else {
        $path = customersApiCustomerPath($customersDir, $targetId);
        $rawCust = file_get_contents($path);
        if ($rawCust === false) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not read customer.']);
            exit;
        }
        $cust = json_decode($rawCust, true);
        if (!is_array($cust)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Invalid customer JSON.']);
            exit;
        }
    }

    customersApiMergeCustomerFromQuoteFields($cust, $fields);
    $cust['id'] = $targetId;
    $cust['updatedAt'] = gmdate('Y-m-d');

    $savePath = customersApiCustomerPath($customersDir, $targetId);
    if (@file_put_contents($savePath, json_encode($cust, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not save customer.']);
        exit;
    }

    $displayName = customersApiDisplayName($cust);
    echo json_encode([
        'ok'      => true,
        'id'      => $targetId,
        'created' => $created,
        'customer'=> [
            'id'        => $targetId,
            'name'      => $displayName,
            'phone'     => $cust['phone'] ?? '',
            'email'     => $cust['email'] ?? '',
            'svcStreet' => $cust['svcStreet'] ?? '',
            'svcCity'   => $cust['svcCity'] ?? '',
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
