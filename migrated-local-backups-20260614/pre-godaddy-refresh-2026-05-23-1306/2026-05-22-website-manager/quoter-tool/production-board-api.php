<?php
header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, max-age=0');

$keyFile = __DIR__ . DIRECTORY_SEPARATOR . '.data'
         . DIRECTORY_SEPARATOR . 'clickup-api-key.json';
$apiKey  = '';
if (is_file($keyFile)) {
    $raw = @file_get_contents($keyFile);
    if ($raw) {
        $d = json_decode($raw, true);
        if (is_array($d) && !empty($d['apiKey'])) {
            $apiKey = trim((string) $d['apiKey']);
        }
    }
}
if ($apiKey === '') {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'ClickUp API key not configured. Open Job Tracking → Settings and save your API key.',
    ]);
    exit;
}

/** Bump when deploying — visible in ?diag=1 to confirm the server file updated. */
const API_BUILD = '20260518-4';
const CLICKUP_WORKSPACE = '9017868498';

/**
 * ClickUp list id → production-board column (0–5).
 * Col 0 merges Engineering + Production “Approved for Production”.
 */
const LIST_TO_COL = [
    '901713809349' => 0, // Engineering — Approved for Production
    '901713809352' => 0, // Production — Approved for Production
    '901713809354' => 1, // Saw Cut
    '901713809356' => 2, // Router / Polish
    '901713809359' => 3, // Final Polish
    '901713809364' => 4, // QC Check
    '901713809367' => 5, // Ready for Install
];

const STAGE_LABELS = [
    0 => 'Approved for Prod',
    1 => 'Saw Cut',
    2 => 'Router / Polish',
    3 => 'Final Polish',
    4 => 'QC Check',
    5 => 'Ready for Install',
];

const COL_TO_TARGET_LIST = [
    0 => '901713809352', // Production — Approved for Production
    1 => '901713809354', // Saw Cut
    2 => '901713809356', // Router / Polish
    3 => '901713809359', // Final Polish
    4 => '901713809364', // QC Check
    5 => '901713809367', // Ready for Install
];

const CF_MATERIAL = '59c18cac-11e6-4c4d-9e5c-b5c8801429d3';
const CF_SQFT     = 'ef784862-3c93-4b74-9a40-1e26d9b08bc2';
const CF_INSTALL  = '0ec46de0-c900-4212-93c7-8811b633b81d'; // Countertop install date
const CF_REP      = '2b3dab25-786d-4708-b1de-fe8e4777138a';

/**
 * @return array{tasks: array, error: ?string}
 */
function cupFetchJson(string $url, string $apiKey): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $apiKey,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['data' => null, 'error' => 'Network error: ' . $curlErr];
    }

    $data = json_decode((string) $raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($data)
            ? (string) ($data['err'] ?? $data['ECODE'] ?? $data['error'] ?? 'HTTP ' . $code)
            : 'HTTP ' . $code;
        return ['data' => null, 'error' => $msg];
    }

    return ['data' => $data, 'error' => null];
}

function cupRequestJson(string $method, string $url, string $apiKey, $body = null): array
{
    $headers = [
        'Authorization: ' . $apiKey,
        'Accept: application/json',
    ];
    $curlBody = null;
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $curlBody = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    if ($curlBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $curlBody);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['data' => null, 'error' => 'Network error: ' . $curlErr, 'status' => 502];
    }
    $data = json_decode((string) $raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($data)
            ? (string) ($data['err'] ?? $data['ECODE'] ?? $data['error'] ?? 'HTTP ' . $code)
            : 'HTTP ' . $code;
        return ['data' => $data, 'error' => $msg, 'status' => $code];
    }

    return ['data' => $data, 'error' => null, 'status' => $code];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode((string) $raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body', 'build' => API_BUILD]);
        exit;
    }
    $action = strtolower(trim((string) ($body['action'] ?? '')));
    if ($action !== 'move') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action', 'build' => API_BUILD]);
        exit;
    }
    $taskId = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($body['taskId'] ?? ''));
    $col = (int) ($body['col'] ?? -1);
    if ($taskId === '' || !isset(COL_TO_TARGET_LIST[$col])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing task or destination column', 'build' => API_BUILD]);
        exit;
    }
    $destList = COL_TO_TARGET_LIST[$col];
    $url = 'https://api.clickup.com/api/v3/workspaces/' . rawurlencode(CLICKUP_WORKSPACE)
        . '/tasks/' . rawurlencode($taskId)
        . '/home_list/' . rawurlencode($destList);
    $result = cupRequestJson('PUT', $url, $apiKey);
    if ($result['error']) {
        http_response_code((int) ($result['status'] ?: 502));
        echo json_encode([
            'ok' => false,
            'error' => 'ClickUp move failed: ' . $result['error'],
            'build' => API_BUILD,
        ]);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'build' => API_BUILD,
        'taskId' => $taskId,
        'col' => $col,
        'listId' => $destList,
    ]);
    exit;
}

/**
 * @return array{tasks: array, error: ?string}
 */
function fetchList(string $listId, string $apiKey): array
{
    $tasks = [];
    $page = 0;

    do {
        $url = 'https://api.clickup.com/api/v2/list/' . rawurlencode($listId)
            . '/task?include_closed=false&subtasks=false&page=' . $page;

        $result = cupFetchJson($url, $apiKey);
        if ($result['error']) {
            return ['tasks' => [], 'error' => $result['error']];
        }

        $data = $result['data'];
        $batch = is_array($data['tasks'] ?? null) ? $data['tasks'] : [];
        $tasks = array_merge($tasks, $batch);
        $lastPage = (bool) ($data['last_page'] ?? true);
        $page++;
    } while (!$lastPage && $page < 20);

    return ['tasks' => $tasks, 'error' => null];
}

function cfVal(array $task, string $fieldId): string
{
    foreach ($task['custom_fields'] ?? [] as $f) {
        if (($f['id'] ?? '') !== $fieldId) {
            continue;
        }
        if ($f['value'] === null || $f['value'] === '') {
            return '';
        }
        if (($f['type'] ?? '') === 'drop_down') {
            foreach ($f['type_config']['options'] ?? [] as $o) {
                if ($o['orderindex'] === $f['value'] || $o['id'] === $f['value']) {
                    return (string) ($o['name'] ?? '');
                }
            }
            return '';
        }
        if (($f['type'] ?? '') === 'date') {
            return $f['value'] ? date('Y-m-d', (int) ($f['value'] / 1000)) : '';
        }
        return trim((string) $f['value']);
    }
    return '';
}

function timeInStage(array $task): array
{
    $updated = (int) ($task['date_updated'] ?? 0) / 1000 ?: time();
    $hrs  = (time() - $updated) / 3600;
    $days = $hrs / 24;
    $status = $days > 2 ? 'red' : ($days >= 1 ? 'amber' : 'green');
    if ($hrs < 1) {
        $label = round($hrs * 60) . ' min';
    } elseif ($hrs < 24) {
        $label = round($hrs) . ' hr' . (round($hrs) !== 1 ? 's' : '');
    } else {
        $label = round($days) . ' day' . (round($days) !== 1 ? 's' : '');
    }
    return ['label' => $label, 'status' => $status];
}

function assignTask(array &$byCol, array $task): bool
{
    $listId = (string) ($task['list']['id'] ?? '');
    if (!isset(LIST_TO_COL[$listId])) {
        return false;
    }
    $col = LIST_TO_COL[$listId];
    $id = (string) ($task['id'] ?? '');
    if ($id === '') {
        return false;
    }
    $byCol[$col][$id] = $task;
    return true;
}

$diag = isset($_GET['diag']) && $_GET['diag'] === '1';
$byCol = array_fill(0, 6, []);
$fetchErrors = [];
$listCounts = [];

foreach (array_keys(LIST_TO_COL) as $listId) {
    $result = fetchList($listId, $apiKey);
    if ($result['error']) {
        $fetchErrors[] = $listId . ': ' . $result['error'];
        $listCounts[$listId] = -1;
        continue;
    }
    $listCounts[$listId] = count($result['tasks']);
    foreach ($result['tasks'] as $task) {
        assignTask($byCol, $task);
    }
}

$loadedTasks = 0;
foreach ($byCol as $colTasks) {
    $loadedTasks += count($colTasks);
}

if ($loadedTasks === 0 && $fetchErrors) {
    http_response_code(502);
    echo json_encode([
        'ok'    => false,
        'error' => 'ClickUp error: ' . implode('; ', $fetchErrors),
        'build' => API_BUILD,
    ]);
    exit;
}

$today = date('Y-m-d');
$columns = [];
$totalSF = 0;
$todayInstalls = [];
$totalTasks = 0;

for ($col = 0; $col < 6; $col++) {
    $cards = [];
    foreach ($byCol[$col] as $task) {
        $id       = trim((string) ($task['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $name     = trim((string) ($task['name'] ?? ''));
        $sf       = (float) cfVal($task, CF_SQFT) ?: 0;
        $material = cfVal($task, CF_MATERIAL);
        $instDate = cfVal($task, CF_INSTALL);
        $rep      = cfVal($task, CF_REP);

        if ($col < 5 && $sf > 0) {
            $totalSF += $sf;
        }

        $desc   = strtolower((string) ($task['description'] ?? ''));
        $tags   = array_map(static fn($t) => strtolower((string) ($t['name'] ?? '')), $task['tags'] ?? []);
        $qcFlag = strpos($desc, 'flag') !== false || strpos($desc, 'reseal') !== false
            || in_array('flag', $tags, true) || in_array('qc-flag', $tags, true);
        $time   = timeInStage($task);
        $color  = $qcFlag ? 'blue' : $time['status'];

        $cards[] = [
            'id'          => $id,
            'col'         => $col,
            'client'      => $name,
            'material'    => $material,
            'sf'          => $sf > 0 ? round($sf, 1) : null,
            'installDate' => $instDate,
            'rep'         => $rep,
            'timeLabel'   => $time['label'],
            'color'       => $color,
            'qcFlag'      => $qcFlag,
        ];

        if ($col >= 4 && $instDate === $today) {
            $todayInstalls[] = [
                'client'   => $name,
                'material' => $material,
                'sf'       => $sf > 0 ? round($sf, 1) : null,
                'rep'      => $rep,
            ];
        }
    }

    $totalTasks += count($cards);
    $columns[] = [
        'stage' => STAGE_LABELS[$col],
        'col'   => $col,
        'count' => count($cards),
        'cards' => $cards,
    ];
}

$hint = '';
if ($totalTasks === 0) {
    $hint = 'No jobs in shop stages (Approved for Production through Ready for Install). '
        . 'Jobs still in Sales or earlier Engineering (Template, CAD, etc.) only appear on Job Tracking.';
}

$out = [
    'ok'            => true,
    'build'         => API_BUILD,
    'totalSF'       => round($totalSF),
    'columns'       => $columns,
    'todayInstalls' => $todayInstalls,
    'totalTasks'    => $totalTasks,
    'hint'          => $hint,
];

if ($diag) {
    $out['diag'] = [
        'build'        => API_BUILD,
        'listCounts'   => $listCounts,
        'errors'       => $fetchErrors,
        'columnCounts' => array_map('count', $byCol),
    ];
}

echo json_encode($out);
