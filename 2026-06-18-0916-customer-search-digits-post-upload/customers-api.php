<?php

/**
 * Server-side CRM data: customers + lightweight quote summaries for Hub / Customer DB.
 * Same auth session as quotes-api.php (sign in via index.php first).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ogm-heic-lib.php';

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

function customersApiReadJsonBody() {
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $raw = @file_get_contents('php://input');
    $decoded = $raw !== false && trim((string) $raw) !== '' ? json_decode((string) $raw, true) : [];
    $body = is_array($decoded) ? $decoded : [];
    return $body;
}

function customersApiCanManageDedupe() {
    return function_exists('qtCan') && qtCan('user_admin');
}

function customersApiCanDeleteInvoices() {
    if (function_exists('qtCan') && qtCan('user_admin')) {
        return true;
    }
    $role = '';
    if (function_exists('qtCurrentRole')) {
        $role = (string) qtCurrentRole();
    }
    if ($role === '') {
        $role = (string) ($_SESSION['qt_role'] ?? '');
    }
    $role = strtolower(trim($role));
    return in_array($role, ['owner', 'general_manager', 'general manager', 'manager', 'admin'], true);
}

function customersApiRequireDedupeEditMode() {
    if (!customersApiCanManageDedupe()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only managers can merge duplicate customer records.']);
        exit;
    }
    $body = customersApiReadJsonBody();
    if (empty($body['dedupeEditMode'])) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Turn on Dedupe Edit mode before merging duplicate customer records.']);
        exit;
    }
}

if ($action === 'dedupe-permissions') {
    echo json_encode([
        'ok' => true,
        'canDedupe' => customersApiCanManageDedupe(),
        'canDeleteInvoices' => customersApiCanDeleteInvoices(),
        'role' => function_exists('qtCurrentRole') ? qtCurrentRole() : '',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'customer-search-lib.php';

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

function customersApiEmailHistoryPath($customerId) {
    $id = customersApiSanitizeId($customerId);
    if ($id === null) {
        return null;
    }

    return __DIR__ . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . 'email'
        . DIRECTORY_SEPARATOR . 'customer-history' . DIRECTORY_SEPARATOR . $id . '.json';
}

function customersApiPublicEmailHistoryEntry(array $entry): array {
    return [
        'id' => (string) ($entry['id'] ?? ''),
        'messageId' => (string) ($entry['messageId'] ?? ''),
        'subject' => (string) ($entry['subject'] ?? '(no subject)'),
        'from' => is_array($entry['from'] ?? null) ? $entry['from'] : ['name' => '', 'email' => ''],
        'to' => is_array($entry['to'] ?? null) ? array_values($entry['to']) : [],
        'cc' => is_array($entry['cc'] ?? null) ? array_values($entry['cc']) : [],
        'date' => (string) ($entry['date'] ?? ''),
        'bodyText' => (string) ($entry['bodyText'] ?? ''),
        'tag' => (string) ($entry['tag'] ?? ''),
        'jobId' => (string) ($entry['jobId'] ?? ''),
        'jobName' => (string) ($entry['jobName'] ?? ''),
        'savedBy' => (string) ($entry['savedBy'] ?? ''),
        'savedAt' => (string) ($entry['savedAt'] ?? ''),
    ];
}

function customersApiCollectLinkedEmails(array $customer, array $entries): array {
    $emails = [];
    foreach (['email', 'email2'] as $field) {
        $addr = strtolower(trim((string) ($customer[$field] ?? '')));
        if ($addr !== '' && str_contains($addr, '@')) {
            $emails[$addr] = true;
        }
    }
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $from = is_array($entry['from'] ?? null) ? $entry['from'] : [];
        $fromAddr = strtolower(trim((string) ($from['email'] ?? '')));
        if ($fromAddr !== '' && str_contains($fromAddr, '@')) {
            $emails[$fromAddr] = true;
        }
        foreach (['to', 'cc'] as $listField) {
            $list = is_array($entry[$listField] ?? null) ? $entry[$listField] : [];
            foreach ($list as $addr) {
                $addr = strtolower(trim((string) $addr));
                if ($addr !== '' && str_contains($addr, '@')) {
                    $emails[$addr] = true;
                }
            }
        }
    }
    $out = array_keys($emails);
    sort($out);

    return $out;
}

/** Recompute lightweight email metadata on customer JSON from sidecar entries. */
function customersApiUpdateEmailMetadataFromSidecar($customersDir, string $customerId, array $entries): void {
    $custPath = customersApiCustomerPath($customersDir, $customerId);
    if (!is_file($custPath)) {
        return;
    }
    $customer = json_decode((string) file_get_contents($custPath), true);
    if (!is_array($customer)) {
        return;
    }

    usort($entries, static function ($a, $b) {
        $ta = strtotime((string) ($a['date'] ?? $a['savedAt'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['date'] ?? $b['savedAt'] ?? '')) ?: 0;

        return $tb <=> $ta;
    });

    $latest = $entries[0] ?? null;
    $customer['emailHistoryCount'] = count($entries);
    $customer['emailHistoryUpdatedAt'] = date('c');
    $customer['lastEmailSubject'] = $latest ? (string) ($latest['subject'] ?? '(no subject)') : '';
    $customer['lastEmailAt'] = $latest ? (string) ($latest['date'] ?? $latest['savedAt'] ?? '') : '';
    unset($customer['emailCommunications'], $customer['emailCommunicationsUpdatedAt']);

    @file_put_contents($custPath, json_encode($customer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** Fields written by Email Center / Message Center — never clobber on Customer DB save. */
function customersApiServerOwnedCustomerFields(): array {
    return [
        'messageLog',
        'clickupJobs',
        'emailHistoryCount',
        'emailHistoryUpdatedAt',
        'lastEmailSubject',
        'lastEmailAt',
    ];
}

function customersApiPreserveServerOwnedFields(array $incoming, ?array $existing): array {
    if (is_array($existing)) {
        foreach (customersApiServerOwnedCustomerFields() as $field) {
            if (array_key_exists($field, $existing)) {
                $incoming[$field] = $existing[$field];
            }
        }
    }

    unset($incoming['emailCommunications'], $incoming['emailCommunicationsUpdatedAt']);

    return $incoming;
}

function customersApiStripCustomerForList(array $data): array {
    unset(
        $data['emailCommunications'],
        $data['emailCommunicationsUpdatedAt']
    );

    return $data;
}

function customersApiSummaryPath($dir, $quoteNumber) {
    return $dir . DIRECTORY_SEPARATOR . $quoteNumber . '.json';
}

function customersApiQuotePath($dir, $quoteNumber) {
    return $dir . DIRECTORY_SEPARATOR . $quoteNumber . '.json';
}

function customersApiQuoteServerVersion($quotesDir, $quoteNumber) {
    $qn = customersApiSanitizeId($quoteNumber);
    if ($qn === null) {
        return null;
    }
    $path = customersApiQuotePath($quotesDir, $qn);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    return hash('sha256', (string) $raw);
}

function customersApiIsGuardedQuoteNumber($quoteNumber) {
    return (bool) preg_match('/^(STO|GLS)-\d{4}-\d{5,}$/', (string) $quoteNumber);
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
        $data['email2'] ?? '',
        $data['svcStreet'] ?? '',
        $data['svcCity'] ?? '',
        $data['billStreet'] ?? '',
        $data['billCity'] ?? '',
        $data['jobName'] ?? '',
        $data['notes'] ?? '',
        $data['id'] ?? '',
    ];
    if (is_array($data['searchAliases'] ?? null)) {
        foreach ($data['searchAliases'] as $alias) {
            $parts[] = (string) $alias;
        }
    }
    if (is_array($data['quotes'] ?? null)) {
        foreach ($data['quotes'] as $q) {
            if (is_array($q) && !empty($q['invoiceNum'])) {
                $parts[] = (string) $q['invoiceNum'];
            }
        }
    }

    return implode(' ', array_filter(array_map('strval', $parts), static function ($s) {
        return trim($s) !== '';
    }));
}

function customersApiMatchesSearchQuery(array $data, string $q) {
    return ogmSearchMatchesCustomerRecord($data, $q);
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
    if (strlen($phone) >= 7) {
        return true;
    }
    if ($email !== '' && str_contains($email, '@')) {
        return true;
    }
    if ($name !== '') {
        return true;
    }
    return false;
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
    $parts = customersApiSplitDisplayName($rawName);
    if (trim((string) ($cust['firstName'] ?? '')) === '' && $parts['firstName'] !== '') {
        $cust['firstName'] = $parts['firstName'];
    }
    if (trim((string) ($cust['lastName'] ?? '')) === '' && $parts['lastName'] !== '') {
        $cust['lastName'] = $parts['lastName'];
    }

    $fill('phone', $fields['phone'] ?? '');
    $fill('email', $fields['email'] ?? '');

    $svcStr = trim((string) ($fields['svcStreet'] ?? ($fields['addr'] ?? '')));
    $svcCit = trim((string) ($fields['svcCity'] ?? ($fields['city'] ?? '')));
    $fill('svcStreet', $svcStr);
    $fill('svcCity', $svcCit);
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

function customersApiQuoteNumbersMatch($a, $b) {
    $a = strtoupper(trim((string) $a));
    $b = strtoupper(trim((string) $b));
    return $a !== '' && $b !== '' && $a === $b;
}

function customersApiClearLinkedCustomerOnQuote($quotesDir, $quoteNumber) {
    $path = customersApiQuotePath($quotesDir, $quoteNumber);
    if (!is_file($path)) {
        return false;
    }
    $raw = @file_get_contents($path);
    $full = $raw ? json_decode($raw, true) : null;
    if (!is_array($full)) {
        return false;
    }
    unset($full['linkedCustomerId']);
    if (isset($full['state']) && is_array($full['state'])) {
        unset($full['state']['linkedCustomerId']);
        if (isset($full['state']['customer']) && is_array($full['state']['customer'])) {
            unset($full['state']['customer']['linkedCustomerId']);
        }
    }
    if (isset($full['workflow']) && is_array($full['workflow'])) {
        unset($full['workflow']['linkedCustomerId']);
    }
    return @file_put_contents($path, json_encode($full, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
}

/**
 * Remove a quote from a customer profile and clear linkedCustomerId when it points at this customer.
 *
 * @return array{ok:bool,error?:string,customerId?:string,quoteNumber?:string,removed?:bool}
 */
function customersApiUnlinkQuoteFromCustomer($customersDir, $summariesDir, $quotesDir, $customerId, $quoteNumber) {
    $custPath = customersApiCustomerPath($customersDir, $customerId);
    if (!is_file($custPath)) {
        return ['ok' => false, 'error' => 'Customer not found.'];
    }
    $custRaw = @file_get_contents($custPath);
    $cust = $custRaw ? json_decode($custRaw, true) : null;
    if (!is_array($cust)) {
        return ['ok' => false, 'error' => 'Corrupt customer record.'];
    }

    $quotes = isset($cust['quotes']) && is_array($cust['quotes']) ? $cust['quotes'] : [];
    $newQuotes = [];
    $removed = false;
    foreach ($quotes as $q) {
        if (!is_array($q)) {
            $newQuotes[] = $q;
            continue;
        }
        $qn = (string) ($q['quoteNumber'] ?? ($q['invoiceNum'] ?? ''));
        if (customersApiQuoteNumbersMatch($qn, $quoteNumber)) {
            $removed = true;
            continue;
        }
        $newQuotes[] = $q;
    }
    if (!$removed) {
        return ['ok' => false, 'error' => 'Quote not found on this customer.'];
    }
    $cust['quotes'] = array_values($newQuotes);

    if (isset($cust['clickupJobs']) && is_array($cust['clickupJobs'])) {
        $cust['clickupJobs'] = array_values(array_filter($cust['clickupJobs'], function ($entry) use ($quoteNumber) {
            if (!is_array($entry)) {
                return true;
            }
            $jobQn = (string) ($entry['quoteNumber'] ?? '');
            return !customersApiQuoteNumbersMatch($jobQn, $quoteNumber);
        }));
    }

    $summaryPath = customersApiSummaryPath($summariesDir, $quoteNumber);
    if (is_file($summaryPath)) {
        $sumRaw = @file_get_contents($summaryPath);
        $summary = $sumRaw ? json_decode($sumRaw, true) : null;
        if (is_array($summary)) {
            $linkedId = customersApiSanitizeId($summary['linkedCustomerId'] ?? '');
            if ($linkedId !== null && $linkedId === $customerId) {
                $oldSummary = $summary;
                $summary['linkedCustomerId'] = '';
                $summary['updatedAt'] = gmdate('c');
                customersApiAppendSummaryChangeLog($oldSummary, $summary);
                @file_put_contents($summaryPath, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
        }
    }

    customersApiClearLinkedCustomerOnQuote($quotesDir, $quoteNumber);

    if (!isset($cust['notesLog']) || !is_array($cust['notesLog'])) {
        $cust['notesLog'] = [];
    }
    $cust['notesLog'][] = [
        'date' => gmdate('Y-m-d'),
        'text' => 'Removed quote ' . $quoteNumber . ' from customer profile',
    ];
    $cust['updatedAt'] = gmdate('Y-m-d');

    $ok = @file_put_contents($custPath, json_encode($cust, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    if ($ok === false) {
        return ['ok' => false, 'error' => 'Could not save customer.'];
    }

    return [
        'ok' => true,
        'customerId' => $customerId,
        'quoteNumber' => $quoteNumber,
        'removed' => true,
    ];
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
        $customers[] = customersApiStripCustomerForList($data);
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
    echo json_encode(['ok' => true, 'customer' => customersApiStripCustomerForList($data)], JSON_UNESCAPED_SLASHES);
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
    $existing = null;
    if (is_file($path)) {
        $existingRaw = @file_get_contents($path);
        $decoded = $existingRaw !== false && $existingRaw !== '' ? json_decode($existingRaw, true) : null;
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }
    $customer = customersApiPreserveServerOwnedFields($customer, $existing);
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
    $quoteServerVersion = trim((string) ($summary['quoteServerVersion'] ?? ($summary['_serverVersion'] ?? '')));
    $currentQuoteVersion = customersApiQuoteServerVersion($quotesDir, $qn);
    if (customersApiIsGuardedQuoteNumber($qn) && $currentQuoteVersion !== null && ($quoteServerVersion === '' || !hash_equals($currentQuoteVersion, $quoteServerVersion))) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'error' => 'Quote summary is stale. Reload this quote before saving.',
            'quoteNumber' => $qn,
            'serverVersion' => $currentQuoteVersion,
            'conflict' => true,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    unset($summary['_serverVersion']);
    if ($currentQuoteVersion !== null) {
        $summary['quoteServerVersion'] = $currentQuoteVersion;
    }
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

if ($action === 'patch-quote-summary') {
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
    $allowed = [
        'linkedCustomerId', 'customerAddressMissing', 'customerAddressForAccounting',
        'qbCustomerStatus', 'qbReviewSource', 'qbReviewMarkedAt', 'qbReviewMarkedBy',
    ];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $body['patch'])) {
            $summary[$key] = $body['patch'][$key];
        }
    }
    $summary['quoteNumber'] = $qn;
    $summary['updatedAt'] = gmdate('c');
    customersApiAppendSummaryChangeLog(json_decode((string) $oldRaw, true), $summary);
    $ok = @file_put_contents($path, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    if ($ok === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not save quote summary.']);
        exit;
    }
    $linkedId = customersApiSanitizeId($summary['linkedCustomerId'] ?? '');
    if ($linkedId !== null && ($summary['qbCustomerStatus'] ?? '') === 'verified') {
        $custPath = customersApiCustomerPath($customersDir, $linkedId);
        if (is_file($custPath)) {
            $custRaw = @file_get_contents($custPath);
            $cust = $custRaw ? json_decode($custRaw, true) : null;
            if (is_array($cust)) {
                $cust['qbCustomerStatus'] = 'verified';
                $cust['qbReviewSource'] = $summary['qbReviewSource'] ?? 'manual';
                $cust['qbReviewMarkedAt'] = $summary['qbReviewMarkedAt'] ?? gmdate('c');
                $cust['qbReviewMarkedBy'] = $summary['qbReviewMarkedBy'] ?? 'Invoice Manager';
                $cust['updatedAt'] = gmdate('Y-m-d');
                @file_put_contents($custPath, json_encode($cust, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
        }
    }
    echo json_encode(['ok' => true, 'quoteNumber' => $qn, 'summary' => $summary], JSON_UNESCAPED_SLASHES);
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
        'linkedCustomerId', 'customerAddressMissing', 'customerAddressForAccounting',
        'qbCustomerStatus', 'qbReviewSource', 'qbReviewMarkedAt', 'qbReviewMarkedBy',
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
    customersApiRequireDedupeEditMode();

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
    customersApiRequireDedupeEditMode();

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
        $parts = customersApiSplitDisplayName($rawName);
        $svcStr = trim((string) ($fields['svcStreet'] ?? ($fields['addr'] ?? '')));
        $svcCit = trim((string) ($fields['svcCity'] ?? ($fields['city'] ?? '')));
        $qbReviewSource = stripos($source, 'deposit') !== false ? 'created_from_deposit' : 'created_from_quote';
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
            'jobName'   => '',
            'qbCustomerStatus' => 'needs_review',
            'qbReviewSource' => $qbReviewSource,
            'qbReviewMarkedAt' => '',
            'qbReviewMarkedBy' => '',
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
    if (!$created && trim((string) ($cust['qbCustomerStatus'] ?? '')) === '') {
        $cust['qbCustomerStatus'] = 'existing';
        $cust['qbReviewSource'] = 'existing_customer_db';
    }
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

// GET customers-api.php?action=get-customer-email-history&customerId=C…
if ($action === 'get-customer-email-history') {
    $id = customersApiSanitizeId($_GET['customerId'] ?? $_GET['id'] ?? '');
    if ($id === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing or invalid customer id.']);
        exit;
    }
    $custPath = customersApiCustomerPath($customersDir, $id);
    if (!is_file($custPath)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Customer not found.']);
        exit;
    }
    $customer = json_decode((string) file_get_contents($custPath), true);
    if (!is_array($customer)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Invalid customer JSON.']);
        exit;
    }

    $entries = [];
    $histPath = customersApiEmailHistoryPath($id);
    if ($histPath !== null && is_file($histPath)) {
        $history = json_decode((string) file_get_contents($histPath), true);
        if (is_array($history) && is_array($history['entries'] ?? null)) {
            $entries = $history['entries'];
        }
    }

    usort($entries, static function ($a, $b) {
        $ta = strtotime((string) ($a['date'] ?? $a['savedAt'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['date'] ?? $b['savedAt'] ?? '')) ?: 0;

        return $tb <=> $ta;
    });

    $publicEntries = array_map('customersApiPublicEmailHistoryEntry', $entries);

    echo json_encode([
        'ok' => true,
        'customerId' => $id,
        'linkedEmails' => customersApiCollectLinkedEmails($customer, $entries),
        'entries' => $publicEntries,
        'count' => count($publicEntries),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// POST customers-api.php?action=delete-customer-email-history
// Body: { "customerId": "C…", "entryIds": ["emh_…", …] }
if ($action === 'delete-customer-email-history') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    $body = customersApiReadJsonBody();
    $customerId = customersApiSanitizeId($body['customerId'] ?? '');
    $entryIds = $body['entryIds'] ?? [];

    if ($customerId === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing or invalid customer id.']);
        exit;
    }
    if (!is_array($entryIds) || count($entryIds) === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'entryIds must be a non-empty array.']);
        exit;
    }

    $idsToDelete = [];
    foreach ($entryIds as $entryId) {
        $safeId = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $entryId);
        if ($safeId !== '') {
            $idsToDelete[$safeId] = true;
        }
    }
    if (!$idsToDelete) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No valid entry ids provided.']);
        exit;
    }

    $custPath = customersApiCustomerPath($customersDir, $customerId);
    if (!is_file($custPath)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Customer not found.']);
        exit;
    }

    $histPath = customersApiEmailHistoryPath($customerId);
    $history = ['entries' => []];
    if ($histPath !== null && is_file($histPath)) {
        $decoded = json_decode((string) file_get_contents($histPath), true);
        if (is_array($decoded)) {
            $history = $decoded;
        }
    }
    if (!isset($history['entries']) || !is_array($history['entries'])) {
        $history['entries'] = [];
    }

    $before = count($history['entries']);
    $history['entries'] = array_values(array_filter(
        $history['entries'],
        static function ($entry) use ($idsToDelete) {
            if (!is_array($entry)) {
                return false;
            }
            $id = (string) ($entry['id'] ?? '');

            return $id === '' || !isset($idsToDelete[$id]);
        }
    ));
    $deleted = $before - count($history['entries']);
    if ($deleted === 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'No matching email entries found.']);
        exit;
    }

    $history['customerId'] = $customerId;
    $history['updatedAt'] = date('c');

    if ($histPath !== null) {
        $histDir = dirname($histPath);
        if (!is_dir($histDir) && !@mkdir($histDir, 0700, true) && !is_dir($histDir)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not save email history.']);
            exit;
        }
        if (@file_put_contents($histPath, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not save email history.']);
            exit;
        }
        @chmod($histPath, 0600);
    }

    customersApiUpdateEmailMetadataFromSidecar($customersDir, $customerId, $history['entries']);

    echo json_encode([
        'ok' => true,
        'customerId' => $customerId,
        'deleted' => $deleted,
        'count' => count($history['entries']),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ── ACTION: get-customer-files ───────────────────────────────────────────────
// GET  customers-api.php?action=get-customer-files&id=abc123
// Returns the saved-files manifest for a customer.
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'get-customer-files') {
    $id = customersApiSanitizeId($_GET['id'] ?? '');
    if (!$id) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing id.']);
        exit;
    }

    $manifestPath = __DIR__ . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR
        . 'customer-files' . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'manifest.json';

    if (!is_file($manifestPath)) {
        echo json_encode(['ok' => true, 'files' => []]);
        exit;
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest)) $manifest = [];

    echo json_encode(['ok' => true, 'files' => array_values($manifest)], JSON_UNESCAPED_SLASHES);
    exit;
}

// ── ACTION: download-customer-file ───────────────────────────────────────────
// GET  customers-api.php?action=download-customer-file&id=abc123&filename=foo.pdf
// Serves the raw file bytes with the correct Content-Type.
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'download-customer-file') {
    $id       = customersApiSanitizeId($_GET['id'] ?? '');
    $filename = preg_replace('/[^A-Za-z0-9._\-]/', '', (string) ($_GET['filename'] ?? ''));

    if (!$id || !$filename) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing id or filename.']);
        exit;
    }

    $filePath = __DIR__ . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR
        . 'customer-files' . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . $filename;

    if (!is_file($filePath)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'File not found.']);
        exit;
    }

    $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (ogm_heic_serve_path_inline($filePath, $filename)) {
        exit;
    }
    $mime = ogm_heic_mime_from_extension($ext);
    if ($mime === 'application/octet-stream' && $ext === 'pdf') {
        $mime = 'application/pdf';
    }

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

// ── ACTION: unlink-customer-quote ───────────────────────────────────────────
// POST  customers-api.php?action=unlink-customer-quote
// Body: { "customerId": "C…", "quoteNumber": "STO-2026-00001" }
// Removes quote from customer.quotes[] and clears linkedCustomerId when it matches.
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'unlink-customer-quote') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    $body = customersApiReadJsonBody();
    $customerId = customersApiSanitizeId($body['customerId'] ?? '');
    $quoteNumber = customersApiSanitizeId($body['quoteNumber'] ?? '');
    if ($customerId === null || $quoteNumber === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing or invalid customerId or quoteNumber.']);
        exit;
    }
    $result = customersApiUnlinkQuoteFromCustomer($customersDir, $summariesDir, $quotesDir, $customerId, $quoteNumber);
    if (empty($result['ok'])) {
        $code = (($result['error'] ?? '') === 'Customer not found.' || ($result['error'] ?? '') === 'Quote not found on this customer.') ? 404 : 500;
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Could not unlink quote.']);
        exit;
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
    exit;
}

// ── ACTION: delete-customer-file ─────────────────────────────────────────────
// POST  customers-api.php?action=delete-customer-file
// Body: { "customerId": "abc123", "filename": "foo.pdf" }
// Removes the file from disk and from the manifest.
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'delete-customer-file') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    $body     = json_decode((string) file_get_contents('php://input'), true);
    $id       = customersApiSanitizeId($body['customerId'] ?? '');
    $filename = preg_replace('/[^A-Za-z0-9._\-]/', '', (string) ($body['filename'] ?? ''));

    if (!$id || !$filename) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing customerId or filename.']);
        exit;
    }

    $filesDir     = __DIR__ . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR
        . 'customer-files' . DIRECTORY_SEPARATOR . $id;
    $filePath     = $filesDir . DIRECTORY_SEPARATOR . $filename;
    $manifestPath = $filesDir . DIRECTORY_SEPARATOR . 'manifest.json';

    // Remove file from disk (ignore if already gone).
    if (is_file($filePath)) {
        @unlink($filePath);
    }

    // Remove entry from manifest.
    if (is_file($manifestPath)) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (is_array($manifest)) {
            $manifest = array_values(array_filter($manifest, fn($e) => ($e['filename'] ?? '') !== $filename));
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
