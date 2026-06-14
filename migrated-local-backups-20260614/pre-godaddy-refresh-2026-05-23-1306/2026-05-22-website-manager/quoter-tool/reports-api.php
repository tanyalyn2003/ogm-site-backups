<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';
qtSendNoIndexHeaders();
qtStartSession();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (!qtIsLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$action = trim((string)($_GET['action'] ?? ''));

function reportsApiDefaults() {
    return [
        'stone_install_labor' => 144.51,
        'stone_install_overhead' => 60.63,
        'stone_prod_labor' => 102.39,
        'stone_prod_overhead' => 42.95,
        'stone_daily_target' => 3282.20,
        'glass_install_labor' => 49.05,
        'glass_install_overhead' => 21.00,
        'glass_prod_labor' => 49.05,
        'glass_prod_overhead' => 21.00,
        'glass_daily_target' => 1120.80,
        'effective_date' => '',
    ];
}

function reportsApiRates() {
    $file = __DIR__ . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . 'overhead-rates.json';
    $rates = reportsApiDefaults();
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $rates = array_merge($rates, $decoded);
            }
        }
    }
    return $rates;
}

function reportsApiDateKey(array $row) {
    foreach (['installDate', 'invoiceDate', 'date', 'savedAt', '_savedAt'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            return substr($value, 0, 10);
        }
    }
    return '';
}

function reportsApiFloat($value) {
    if (is_numeric($value)) return (float)$value;
    $clean = preg_replace('/[^0-9.\-]/', '', (string)$value);
    return is_numeric($clean) ? (float)$clean : 0.0;
}

function reportsApiNormalizeJob(array $row) {
    $product = strtolower(trim((string)($row['productLine'] ?? $row['type'] ?? 'stone')));
    $isGlass = ($product === 'glass');
    $quoteNumber = (string)($row['quoteNumber'] ?? $row['id'] ?? '');
    $total = reportsApiFloat($row['grand'] ?? $row['total'] ?? $row['invoiceTotal'] ?? 0);
    return [
        'quoteNumber' => $quoteNumber,
        'invoiceNumber' => (string)($row['invoiceNumber'] ?? ''),
        'invoiceDate' => (string)($row['invoiceDate'] ?? ''),
        'savedAt' => (string)($row['savedAt'] ?? $row['_savedAt'] ?? ''),
        'productLine' => $isGlass ? 'glass' : 'stone',
        'isGlass' => $isGlass,
        'name' => (string)($row['name'] ?? $row['customerName'] ?? ''),
        'job' => (string)($row['job'] ?? $row['jobName'] ?? ''),
        'rep' => (string)($row['rep'] ?? $row['sp'] ?? $row['salesperson'] ?? ''),
        'salesperson' => (string)($row['salesperson'] ?? $row['rep'] ?? $row['sp'] ?? ''),
        'addr' => (string)($row['addr'] ?? ''),
        'city' => (string)($row['city'] ?? ''),
        'installDate' => (string)($row['installDate'] ?? ''),
        'jobCode' => (string)($row['jobCode'] ?? 'T1.Inv.Instal'),
        'jobType' => (string)($row['jobType'] ?? 'Installed'),
        'hrsEst' => reportsApiFloat($row['hrsEst'] ?? 0),
        'hrsAct' => reportsApiFloat($row['hrsAct'] ?? 0),
        'jtNotes' => (string)($row['jtNotes'] ?? ''),
        'cosSlabs' => reportsApiFloat($row['cosSlabs'] ?? 0),
        'cosSinks' => reportsApiFloat($row['cosSinks'] ?? 0),
        'cosMisc' => reportsApiFloat($row['cosMisc'] ?? 0),
        'cosTax' => reportsApiFloat($row['cosTax'] ?? 0),
        'sqft' => reportsApiFloat($row['sqft'] ?? $row['totalSF'] ?? 0),
        'grand' => $total,
        'total' => $total,
        'dateKey' => reportsApiDateKey($row),
    ];
}

function reportsApiLoadJobs($startDate, $endDate) {
    $jobs = [];
    $seen = [];
    $dirs = [
        __DIR__ . DIRECTORY_SEPARATOR . 'quote_summaries',
        __DIR__ . DIRECTORY_SEPARATOR . 'quotes',
    ];
    foreach ($dirs as $dir) {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $name = basename($file);
            if ($name === '' || $name[0] === '_' || $name === '_index.json') continue;
            $raw = @file_get_contents($file);
            if (!$raw) continue;
            $data = json_decode($raw, true);
            if (!is_array($data)) continue;
            $job = reportsApiNormalizeJob($data);
            $key = $job['quoteNumber'] ?: $name;
            if (isset($seen[$key])) continue;
            $dateKey = $job['dateKey'];
            if ($startDate && $dateKey && $dateKey < $startDate) continue;
            if ($endDate && $dateKey && $dateKey > $endDate) continue;
            if (!$dateKey && ($startDate || $endDate)) continue;
            $seen[$key] = true;
            $jobs[] = $job;
        }
    }
    usort($jobs, static function ($a, $b) {
        return strcmp((string)($a['dateKey'] ?? ''), (string)($b['dateKey'] ?? ''));
    });
    return $jobs;
}

if ($action === 'get-overhead') {
    echo json_encode(['ok' => true, 'rates' => reportsApiRates()]);
    exit;
}

if ($action === 'get-jobs') {
    $startDate = trim((string)($_GET['start'] ?? ''));
    $endDate   = trim((string)($_GET['end'] ?? ''));
    echo json_encode(['ok' => true, 'jobs' => reportsApiLoadJobs($startDate, $endDate)], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'generate' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode((string)$raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request']);
        exit;
    }

    $tmpInput = tempnam(sys_get_temp_dir(), 'ogm_report_in_');
    $tmpOutput = tempnam(sys_get_temp_dir(), 'ogm_report_out_') . '.xlsx';
    @file_put_contents($tmpInput, json_encode($body, JSON_UNESCAPED_SLASHES));

    $pyScript = __DIR__ . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'build_report.py';
    $cmd = 'python3 ' . escapeshellarg($pyScript) . ' ' . escapeshellarg($tmpInput) . ' ' . escapeshellarg($tmpOutput);
    exec($cmd . ' 2>&1', $output, $code);
    @unlink($tmpInput);

    if ($code !== 0 || !is_file($tmpOutput)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Report build failed: ' . implode("\n", $output)]);
        exit;
    }

    $bytes = file_get_contents($tmpOutput);
    @unlink($tmpOutput);
    echo json_encode(['ok' => true, 'xlsx' => base64_encode((string)$bytes)]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
