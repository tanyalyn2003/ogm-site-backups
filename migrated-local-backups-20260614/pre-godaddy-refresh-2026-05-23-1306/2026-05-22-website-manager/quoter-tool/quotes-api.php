<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

$action = strtolower(trim((string)($_GET['action'] ?? '')));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Special: export-all streams a zip, so it sets its own Content-Type below.
if ($action !== 'export-all') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

if (!qtIsLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    exit;
}

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'quotes';
$archiveDir = $baseDir . DIRECTORY_SEPARATOR . '_archive';
$indexPath = $baseDir . DIRECTORY_SEPARATOR . '_index.json';

if (!is_dir($baseDir)) {
    if (!mkdir($baseDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not create quotes directory.']);
        exit;
    }
}

/** Sanitize an id to a-z, 0-9, dot, dash, underscore, max 80 chars. Empty => null. */
function quotesApiSanitizeId($raw) {
    $s = (string) $raw;
    $s = preg_replace('/[^A-Za-z0-9._-]/', '', $s);
    if ($s === null) {
        return null;
    }
    $s = substr($s, 0, 80);
    return $s === '' ? null : $s;
}

function quotesApiQuotePath($baseDir, $id) {
    return $baseDir . DIRECTORY_SEPARATOR . $id . '.json';
}

/** STO-2026-00001 style ids when the client does not send quoteNumber. */
function quotesApiGenerateQuoteId($baseDir) {
    $seqPath = $baseDir . DIRECTORY_SEPARATOR . '_quote_seq.json';
    $year = (int) date('Y');
    $n = 1;
    if (is_file($seqPath)) {
        $raw = json_decode((string) file_get_contents($seqPath), true);
        if (is_array($raw) && (int) ($raw['year'] ?? 0) === $year) {
            $n = max(1, (int) ($raw['n'] ?? 0) + 1);
        }
    }
    @file_put_contents($seqPath, json_encode(['year' => $year, 'n' => $n], JSON_UNESCAPED_SLASHES));
    return sprintf('STO-%d-%05d', $year, $n);
}

/** Count quote JSON files on disk (excludes _index.json and _*.json). */
function quotesApiCountQuoteFiles($baseDir) {
    $glob = glob($baseDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $n = 0;
    foreach ($glob as $file) {
        $name = basename($file);
        if ($name === '' || $name[0] === '_' || $name === '_index.json') {
            continue;
        }
        $n++;
    }
    return $n;
}

/** Match client-side quoteWorkflowPhase() for list rows + search. */
function quotesApiWorkflowPhase($entry) {
    if (!is_array($entry)) {
        return 'Draft';
    }
    if (!empty($entry['invoiceNumber']) || !empty($entry['convertedAt'])) {
        return 'Invoiced';
    }
    if (!empty($entry['depositReceiptNumber']) || !empty($entry['depositRecordedAt'])) {
        return 'Deposit';
    }
    if (!empty($entry['savedAt'])) {
        return 'Quoted';
    }
    return 'Draft';
}

/** Build a small index entry from full quote JSON. */
function quotesApiBuildIndexEntry($id, $data, $savedAt = null) {
    $get = function ($obj, $path, $fallback = '') {
        if (!is_array($obj)) {
            return $fallback;
        }
        $parts = explode('.', $path);
        $cur = $obj;
        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return $fallback;
            }
            $cur = $cur[$p];
        }
        if ($cur === null || $cur === '') {
            return $fallback;
        }
        return $cur;
    };
    $name = $get($data, 'name', $get($data, 'state.customer.name', ''));
    $phone = $get($data, 'phone', $get($data, 'state.customer.phone', ''));
    $email = $get($data, 'email', $get($data, 'state.customer.email', ''));
    $addr = $get($data, 'addr', $get($data, 'state.customer.addr', ''));
    $city = $get($data, 'city', $get($data, 'state.customer.city', ''));
    $installAddr = $get($data, 'installAddr', $get($data, 'state.customer.installAddr', ''));
    $installCity = $get($data, 'installCity', $get($data, 'state.customer.installCity', ''));
    $job = $get($data, 'job', $get($data, 'state.customer.job', ''));
    $sp = $get($data, 'state.customer.salesperson', $get($data, 'salesperson', $get($data, 'sp', '')));
    $date = $get($data, 'date', $get($data, 'state.customer.date', ''));
    $grand = $get($data, 'grand', 0);
    if (!is_numeric($grand)) {
        $grand = 0;
    }
    $invoiceNumber = $get($data, 'invoiceNumber', '');
    $invoiceDate = $get($data, 'invoiceDate', '');
    $convertedAt = $get($data, 'convertedAt', '');
    $depositReceiptNumber = $get($data, 'depositReceiptNumber', '');
    $depositRecordedAt = $get($data, 'depositRecordedAt', '');
    $depositAmountRaw = $get($data, 'depositAmount', '');
    $depositAmount = null;
    if (is_numeric($depositAmountRaw)) {
        $depositAmount = (float) $depositAmountRaw;
    }
    $savedAtStr = (string) ($savedAt ?: $get($data, '_savedAt', ''));
    $entry = [
        'id' => $id,
        'quoteNumber' => $get($data, 'quoteNumber', $id),
        'name' => (string) $name,
        'phone' => (string) $phone,
        'email' => (string) $email,
        'addr' => (string) $addr,
        'city' => (string) $city,
        'installAddr' => (string) $installAddr,
        'installCity' => (string) $installCity,
        'job' => (string) $job,
        'salesperson' => (string) $sp,
        'date' => (string) $date,
        'grand' => (float) $grand,
        'invoiceNumber' => (string) $invoiceNumber,
        'invoiceDate' => (string) $invoiceDate,
        'convertedAt' => (string) $convertedAt,
        'depositReceiptNumber' => (string) $depositReceiptNumber,
        'depositRecordedAt' => (string) $depositRecordedAt,
        'savedAt' => $savedAtStr,
    ];
    if ($depositAmount !== null) {
        $entry['depositAmount'] = $depositAmount;
    }
    $entry['workflowPhase'] = quotesApiWorkflowPhase($entry);
    return $entry;
}

/** Load index from disk; rebuild from quote files if missing or unreadable. */
function quotesApiLoadIndex($baseDir, $indexPath) {
    if (is_file($indexPath)) {
        $raw = file_get_contents($indexPath);
        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
                return $data;
            }
        }
    }
    return quotesApiRebuildIndex($baseDir, $indexPath);
}

function quotesApiRebuildIndex($baseDir, $indexPath) {
    $items = [];
    $glob = glob($baseDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    foreach ($glob as $file) {
        $name = basename($file);
        if ($name === '' || $name[0] === '_' || $name === '_index.json') {
            continue;
        }
        $id = preg_replace('/\.json$/', '', $name);
        $raw = file_get_contents($file);
        if ($raw === false) {
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }
        $savedAt = isset($data['_savedAt']) ? (string) $data['_savedAt'] : gmdate('c', filemtime($file) ?: time());
        $items[] = quotesApiBuildIndexEntry($id, $data, $savedAt);
    }
    usort($items, function ($a, $b) {
        return strcmp((string) ($b['savedAt'] ?? ''), (string) ($a['savedAt'] ?? ''));
    });
    $payload = ['version' => 1, 'updatedAt' => gmdate('c'), 'items' => $items];
    @file_put_contents($indexPath, json_encode($payload, JSON_UNESCAPED_SLASHES));
    return $payload;
}

/** Replace (or insert) one entry in the index, persist it. */
function quotesApiUpsertIndex($baseDir, $indexPath, $entry) {
    $idx = quotesApiLoadIndex($baseDir, $indexPath);
    $items = isset($idx['items']) && is_array($idx['items']) ? $idx['items'] : [];
    $next = [];
    $replaced = false;
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string) ($row['id'] ?? '') === (string) ($entry['id'] ?? '')) {
            $next[] = $entry;
            $replaced = true;
        } else {
            $next[] = $row;
        }
    }
    if (!$replaced) {
        $next[] = $entry;
    }
    usort($next, function ($a, $b) {
        return strcmp((string) ($b['savedAt'] ?? ''), (string) ($a['savedAt'] ?? ''));
    });
    $payload = ['version' => 1, 'updatedAt' => gmdate('c'), 'items' => $next];
    @file_put_contents($indexPath, json_encode($payload, JSON_UNESCAPED_SLASHES));
    return $payload;
}

function quotesApiRemoveFromIndex($baseDir, $indexPath, $id) {
    $idx = quotesApiLoadIndex($baseDir, $indexPath);
    $items = isset($idx['items']) && is_array($idx['items']) ? $idx['items'] : [];
    $next = array_values(array_filter($items, function ($row) use ($id) {
        return is_array($row) && (string) ($row['id'] ?? '') !== (string) $id;
    }));
    $payload = ['version' => 1, 'updatedAt' => gmdate('c'), 'items' => $next];
    @file_put_contents($indexPath, json_encode($payload, JSON_UNESCAPED_SLASHES));
    return $payload;
}

function quotesApiGetPathValue($obj, $path, $fallback = '') {
    if (!is_array($obj)) {
        return $fallback;
    }
    $parts = explode('.', $path);
    $cur = $obj;
    foreach ($parts as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) {
            return $fallback;
        }
        $cur = $cur[$p];
    }
    return $cur;
}

function quotesApiComparableValue($value) {
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

function quotesApiAppendChangeLog($oldData, &$newData) {
    if (!is_array($oldData) || !is_array($newData)) {
        return;
    }
    $fields = [
        'state.customer.salesperson' => 'Salesperson',
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
    ];
    $changes = [];
    foreach ($fields as $path => $label) {
        $old = quotesApiComparableValue(quotesApiGetPathValue($oldData, $path, ''));
        $new = quotesApiComparableValue(quotesApiGetPathValue($newData, $path, ''));
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
    $log = [];
    if (isset($oldData['_changeLog']) && is_array($oldData['_changeLog'])) {
        $log = $oldData['_changeLog'];
    }
    $log[] = [
        'at' => gmdate('c'),
        'by' => qtUsername() ?: 'Signed-in user',
        'changes' => $changes,
    ];
    if (count($log) > 100) {
        $log = array_slice($log, -100);
    }
    $newData['_changeLog'] = $log;
}

/** Lowercase + collapse non-alphanumeric runs to single spaces, mirroring quoteDbSearchText() in the front-end. */
function quotesApiNormalize($s) {
    $s = strtolower((string) $s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim((string) $s);
}

/** Server-side filter; mirrors front-end behavior so search feels identical. */
function quotesApiFilter($items, $q) {
    $q = trim((string) $q);
    if ($q === '') {
        return array_values($items);
    }
    $qNorm = quotesApiNormalize($q);
    $terms = $qNorm === '' ? [] : preg_split('/\s+/', $qNorm);
    $out = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $hayRaw = strtolower(implode(' ', array_filter([
            (string) ($row['quoteNumber'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['addr'] ?? ''),
            (string) ($row['city'] ?? ''),
            (string) ($row['installAddr'] ?? ''),
            (string) ($row['installCity'] ?? ''),
            (string) ($row['phone'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['job'] ?? ''),
            (string) ($row['salesperson'] ?? ''),
            (string) ($row['savedAt'] ?? ''),
            (string) ($row['invoiceNumber'] ?? ''),
            (string) ($row['invoiceDate'] ?? ''),
            (string) ($row['convertedAt'] ?? ''),
            (string) ($row['depositReceiptNumber'] ?? ''),
            (string) ($row['depositRecordedAt'] ?? ''),
            (string) ($row['workflowPhase'] ?? ''),
        ])));
        $hayNorm = quotesApiNormalize($hayRaw);
        $phoneDigits = preg_replace('/\D/', '', (string) ($row['phone'] ?? ''));
        $hay = "$hayRaw $hayNorm $phoneDigits";
        $matched = false;
        $qLower = strtolower($q);
        if ($qLower !== '' && strpos($hay, $qLower) !== false) {
            $matched = true;
        }
        if (!$matched && $terms) {
            $allHit = true;
            foreach ($terms as $t) {
                if ($t === '') {
                    continue;
                }
                if (strpos($hay, $t) === false) {
                    $allHit = false;
                    break;
                }
            }
            $matched = $allHit;
        }
        if ($matched) {
            $out[] = $row;
        }
    }
    return $out;
}

if ($action === 'list') {
    $idx = quotesApiLoadIndex($baseDir, $indexPath);
    $items = isset($idx['items']) && is_array($idx['items']) ? $idx['items'] : [];
    $fileCount = quotesApiCountQuoteFiles($baseDir);
    if (count($items) !== $fileCount) {
        $idx = quotesApiRebuildIndex($baseDir, $indexPath);
        $items = isset($idx['items']) && is_array($idx['items']) ? $idx['items'] : [];
    }
    $q = (string) ($_GET['q'] ?? '');
    if ($q !== '') {
        $items = quotesApiFilter($items, $q);
    }
    echo json_encode([
        'ok' => true,
        'updatedAt' => $idx['updatedAt'] ?? gmdate('c'),
        'count' => count($items),
        'items' => array_values($items),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'load') {
    $id = quotesApiSanitizeId($_GET['id'] ?? '');
    if ($id === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing id.']);
        exit;
    }
    $path = quotesApiQuotePath($baseDir, $id);
    if (!is_file($path)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Quote not found.']);
        exit;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not read quote file.']);
        exit;
    }
    // Pass-through: front-end already knows the v2 envelope.
    header('Content-Type: application/json; charset=UTF-8');
    echo $raw;
    exit;
}

if ($action === 'save') {
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
    if (strlen($raw) > 10_000_000) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'Quote too large.']);
        exit;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON.']);
        exit;
    }
    $idCandidate = quotesApiSanitizeId($data['quoteNumber'] ?? '');
    if ($idCandidate === null) {
        $idCandidate = quotesApiGenerateQuoteId($baseDir);
        $data['quoteNumber'] = $idCandidate;
    }
    if (!isset($data['_savedAt']) || !is_string($data['_savedAt']) || $data['_savedAt'] === '') {
        $data['_savedAt'] = gmdate('c');
    } else {
        $data['_savedAt'] = (string) $data['_savedAt'];
    }
    $path = quotesApiQuotePath($baseDir, $idCandidate);
    $oldData = null;
    if (is_file($path)) {
        $oldRaw = file_get_contents($path);
        $decodedOld = $oldRaw !== false ? json_decode($oldRaw, true) : null;
        if (is_array($decodedOld)) {
            $oldData = $decodedOld;
        }
    }
    if (is_array($oldData)) {
        quotesApiAppendChangeLog($oldData, $data);
    }
    $ok = file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    if ($ok === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not save quote.']);
        exit;
    }
    $entry = quotesApiBuildIndexEntry($idCandidate, $data, $data['_savedAt']);
    quotesApiUpsertIndex($baseDir, $indexPath, $entry);
    echo json_encode(['ok' => true, 'id' => $idCandidate, 'savedAt' => $data['_savedAt'], 'entry' => $entry]);
    exit;
}

if ($action === 'delete') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    $id = quotesApiSanitizeId($_GET['id'] ?? ($_POST['id'] ?? ''));
    if ($id === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing id.']);
        exit;
    }
    $src = quotesApiQuotePath($baseDir, $id);
    if (!is_file($src)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Quote not found.']);
        exit;
    }
    if (!is_dir($archiveDir)) {
        @mkdir($archiveDir, 0755, true);
    }
    $stamp = date('Ymd-His');
    $dst = $archiveDir . DIRECTORY_SEPARATOR . $id . '-' . $stamp . '.json';
    if (!@rename($src, $dst)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not archive quote.']);
        exit;
    }
    quotesApiRemoveFromIndex($baseDir, $indexPath, $id);
    echo json_encode(['ok' => true, 'id' => $id, 'archived' => $dst]);
    exit;
}

if ($action === 'rebuild-index') {
    $payload = quotesApiRebuildIndex($baseDir, $indexPath);
    echo json_encode([
        'ok' => true,
        'count' => count($payload['items'] ?? []),
        'updatedAt' => $payload['updatedAt'] ?? gmdate('c'),
    ]);
    exit;
}

if ($action === 'export-all') {
    if (!class_exists('ZipArchive')) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'ZipArchive not available on this PHP install.']);
        exit;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'ogm-quotes-');
    if ($tmp === false) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not create temp file.']);
        exit;
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not open zip.']);
        exit;
    }
    $glob = glob($baseDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    foreach ($glob as $file) {
        $name = basename($file);
        if ($name === '' || $name[0] === '_') {
            continue;
        }
        $zip->addFile($file, $name);
    }
    if (is_file($indexPath)) {
        $zip->addFile($indexPath, '_index.json');
    }
    $zip->close();
    $stamp = date('Ymd-His');
    $download = "OGM-quotes-$stamp.zip";
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $download . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
