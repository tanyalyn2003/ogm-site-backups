<?php
/**
 * OGM Quoter — AI Quick Start endpoint.
 * POST action=create-draft
 *   fields: description (text), dxf (optional file upload)
 * Returns JSON draft for the quoter UI to review and apply.
 */
define('OGM_QUICKSTART', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ai-quickstart-config.php';

header('Content-Type: application/json; charset=utf-8');

function ogm_qs_json_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function ogm_qs_short_text($value, int $limit): string
{
    $value = (string)$value;
    if (function_exists('mb_substr')) return mb_substr($value, 0, $limit);
    return substr($value, 0, $limit);
}

function ogm_qs_can_use_ai(): bool
{
    if (function_exists('qtCan')) {
        if (qtCan('user_admin') || qtCan('report_editing') || qtCan('ai_quickstart')) return true;
    }
    $role = function_exists('qtCurrentRole') ? (string)qtCurrentRole() : (string)($_SESSION['qt_role'] ?? '');
    return in_array(strtolower($role), ['general_manager', 'division_manager'], true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ogm_qs_json_error('Method not allowed', 405);
}

if (!qtIsLoggedIn()) {
    ogm_qs_json_error('Not authenticated', 401);
}

if (!ogm_qs_can_use_ai()) {
    ogm_qs_json_error('AI Quick Start is limited to managers or permitted users.', 403);
}

$action = trim($_POST['action'] ?? '');
if ($action !== 'create-draft') {
    ogm_qs_json_error('Unknown action', 400);
}

// ---------------------------------------------------------------------------
// Guard: configured + rate limit
// ---------------------------------------------------------------------------

if (!ogm_qs_configured()) {
    ogm_qs_json_error('AI Quick Start is not configured. Add the Claude API key on the server.', 503);
}

$limit = ogm_qs_daily_limit();
if (ogm_qs_today_call_count() >= $limit) {
    ogm_qs_json_error("Daily AI call limit of {$limit} reached. Try again tomorrow.", 429);
}

// ---------------------------------------------------------------------------
// DXF parsing (optional)
// ---------------------------------------------------------------------------

$dxfParsed = null;
$dxfSvg    = null;

if (!empty($_FILES['dxf']['tmp_name']) && is_uploaded_file($_FILES['dxf']['tmp_name'])) {
    $uploadError = (int)($_FILES['dxf']['error'] ?? UPLOAD_ERR_OK);
    if ($uploadError !== UPLOAD_ERR_OK) {
        ogm_qs_json_error('DXF upload failed. Try again with a smaller .dxf file.', 400);
    }
    $ext = strtolower(pathinfo((string)($_FILES['dxf']['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== 'dxf') {
        ogm_qs_json_error('Only .dxf files are accepted.', 400);
    }
    $maxDxfBytes = 3 * 1024 * 1024;
    $dxfSize = (int)($_FILES['dxf']['size'] ?? 0);
    if ($dxfSize <= 0 || $dxfSize > $maxDxfBytes) {
        ogm_qs_json_error('DXF must be a valid .dxf file under 3 MB.', 400);
    }
    $rawDxf = (string)@file_get_contents($_FILES['dxf']['tmp_name']);
    if ($rawDxf === '') {
        ogm_qs_json_error('DXF could not be read. Try exporting it again from CAD.', 400);
    }
    $parseResult = ogm_qs_dxf_parse($rawDxf);
    $dxfParsed   = ogm_qs_dxf_rooms($parseResult['entities']);
    $dxfSvg      = ogm_qs_dxf_to_svg($dxfParsed);
    if ($dxfSvg === '') $dxfSvg = null;
}

// ---------------------------------------------------------------------------
// Build user message for Claude
// ---------------------------------------------------------------------------

$description = trim($_POST['description'] ?? '');
$textParsed  = ogm_qs_parse_text_measurements($description);
$userMsg     = '';

if ($description !== '') {
    $userMsg .= "Rep description:\n" . ogm_qs_short_text($description, 1500) . "\n\n";
} else {
    $userMsg .= "Rep description: (none provided)\n\n";
}

if ($dxfParsed !== null && !empty($dxfParsed['rooms'])) {
    // Summarize DXF rooms for Claude — omit raw point arrays for brevity
    $summary = ['rooms' => [], 'warnings' => $dxfParsed['warnings'] ?? []];
    foreach ($dxfParsed['rooms'] as $r) {
        $rOut = [
            'label'       => $r['label'],
            'pieceCount'  => count($r['pieces']),
            'cutouts'     => array_map(function ($c) {
                $out = ['shape' => $c['shape']];
                if (!empty($c['diameterIn'])) $out['diameterIn'] = $c['diameterIn'];
                if (!empty($c['lengthIn']))   $out['lengthIn']   = $c['lengthIn'];
                if (!empty($c['widthIn']))    $out['widthIn']    = $c['widthIn'];
                return $out;
            }, $r['cutouts']),
        ];
        $summary['rooms'][] = $rOut;
    }
    $userMsg .= "DXF_DATA:\n" . json_encode($summary, JSON_PRETTY_PRINT) . "\n";
} else {
    $userMsg .= "No DXF attached.\n";
}

// ---------------------------------------------------------------------------
// Call Claude
// ---------------------------------------------------------------------------

$result = ogm_qs_call($userMsg);

if (isset($result['_error'])) {
    $status = (int)($result['_status'] ?? 500);
    if ($status < 400 || $status > 599) $status = 500;
    ogm_qs_json_error('AI Quick Start is unavailable right now. Check the server API key or try again later.', $status);
}

// Strip markdown fences Claude might add despite the prompt's instruction
$rawText = preg_replace('/^```(?:json)?\s*/m', '', $result['text']);
$rawText = preg_replace('/\s*```\s*$/m', '', (string)$rawText);
$aiDraft = json_decode(trim((string)$rawText), true);

if (!is_array($aiDraft)) {
    ogm_qs_json_error('AI returned an unreadable draft. Try again with a shorter description.', 500);
}

// ---------------------------------------------------------------------------
// Merge DXF dimensions into AI room data + compute roomConfidence
// ---------------------------------------------------------------------------

$confRank = ['high' => 2, 'medium' => 1, 'low' => 0];
$confName = ['low', 'medium', 'high'];

$aiRooms = is_array($aiDraft['rooms'] ?? null) ? $aiDraft['rooms'] : [];
$textRooms = $textParsed['rooms'] ?? [];

foreach ($aiRooms as $ri => &$room) {
    $dxfRoom = ogm_qs_find_dxf_room($dxfParsed, $room, $ri);

    // Attach dimensions from DXF parser (never from Claude)
    $room['counterPieces'] = [];
    $room['splashPieces']  = [];
    $room['cutouts']       = [];

    if ($dxfRoom !== null) {
        foreach ($dxfRoom['pieces'] as $piece) {
            $l = $piece['lengthIn'];
            $w = $piece['widthIn'];
            // Heuristic: if narrow dimension ≤ 12" → treat as backsplash run
            if ($w <= 12.0) {
                $room['splashPieces'][] = [
                    'label'    => 'Splash run ' . (count($room['splashPieces']) + 1),
                    'lengthIn' => $l,
                    'heightIn' => $w,
                    'sqFt'     => round($l * $w / 144, 2),
                    'source'   => 'dxf',
                ];
            } else {
                $room['counterPieces'][] = [
                    'label'    => 'Counter run ' . (count($room['counterPieces']) + 1),
                    'lengthIn' => $l,
                    'widthIn'  => $w,
                    'sqFt'     => round($l * $w / 144, 2),
                    'source'   => 'dxf',
                ];
            }
        }

        // Cutout classification: circle → sink; rectangle → sink or cooktop by size
        foreach ($dxfRoom['cutouts'] as $cut) {
            if ($cut['shape'] === 'circle') {
                $room['cutouts'][] = ['type' => 'sink', 'shape' => 'circle', 'diameterIn' => $cut['diameterIn'] ?? null, 'confidence' => 'high'];
            } else {
                $l    = max($cut['lengthIn'] ?? 0, $cut['widthIn'] ?? 0);
                $type = $l > 24 ? 'cooktop' : 'sink';
                $room['cutouts'][] = ['type' => $type, 'shape' => 'rectangle', 'lengthIn' => $cut['lengthIn'] ?? null, 'widthIn' => $cut['widthIn'] ?? null, 'confidence' => 'medium'];
            }
        }
    }

    // Text measurements when DXF did not supply runs (explicit L×W in notes only)
    $textRoom = ogm_qs_match_text_room($textRooms, (string)($room['label'] ?? ''));
    if ($textRoom !== null) {
        ogm_qs_merge_text_room_dims($room, $textRoom);
    }

    // Install hours: AI-stated or formula fallback
    $hoursResolved = ogm_qs_resolve_room_hours($room, (string)($room['label'] ?? ''));
    $room['estimatedHours']  = $hoursResolved['estimatedHours'];
    $room['hoursConfidence'] = $hoursResolved['hoursConfidence'];

    // roomConfidence = minimum confidence across all attribute fields
    $fields = [
        $room['stoneConfidence']   ?? 'low',
        $room['edgeConfidence']    ?? 'low',
        $room['splashConfidence']  ?? 'low',
        $room['removalConfidence'] ?? 'low',
        $room['hoursConfidence']   ?? 'low',
    ];
    $minRank = 2;
    foreach ($fields as $f) { $minRank = min($minRank, $confRank[$f] ?? 0); }
    $room['roomConfidence'] = $confName[$minRank];

    // Sanitize notes
    $room['notes'] = trim((string)($room['notes'] ?? ''));
}
unset($room);

// If AI returned no rooms but text measurements exist, build rooms from text only
if (!$aiRooms && $textRooms) {
    foreach ($textRooms as $tr) {
        $hoursResolved = ogm_qs_resolve_room_hours($tr, (string)($tr['label'] ?? ''));
        $aiRooms[] = [
            'label'           => $tr['label'] ?? 'Room',
            'counterPieces'   => $tr['counterPieces'] ?? [],
            'splashPieces'    => $tr['splashPieces'] ?? [],
            'cutouts'         => [],
            'stoneMatch'      => null,
            'stoneConfidence' => 'low',
            'edgeConfidence'  => 'low',
            'splashConfidence'=> 'low',
            'removalConfidence'=> 'low',
            'estimatedHours'  => $hoursResolved['estimatedHours'],
            'hoursConfidence' => $hoursResolved['hoursConfidence'],
            'roomConfidence'  => 'low',
            'notes'           => 'Built from explicit measurements in rep notes.',
        ];
    }
}

// Sort rooms: low-confidence first so the rep sees issues immediately
usort($aiRooms, fn($a, $b) => ($confRank[$a['roomConfidence']] ?? 0) - ($confRank[$b['roomConfidence']] ?? 0));

$estimatedHoursOnSite = null;
if (isset($aiDraft['estimatedHoursOnSite']) && is_numeric($aiDraft['estimatedHoursOnSite'])) {
    $estimatedHoursOnSite = round(((float)$aiDraft['estimatedHoursOnSite']) * 2) / 2;
} elseif ($aiRooms) {
    $estimatedHoursOnSite = round(array_sum(array_map(fn($r) => (float)($r['estimatedHours'] ?? 0), $aiRooms)) * 2) / 2;
}

// ---------------------------------------------------------------------------
// Log and respond
// ---------------------------------------------------------------------------

$username = (string)($_SESSION['qt_username'] ?? 'unknown');
ogm_qs_log_call($username, $result['inTokens'], $result['outTokens'], $result['model']);

echo json_encode([
    'ok'    => true,
    'draft' => [
        'customer'                => is_array($aiDraft['customer'] ?? null) ? $aiDraft['customer'] : [],
        'jobName'                 => is_string($aiDraft['jobName']  ?? null) ? $aiDraft['jobName'] : null,
        'rooms'                   => $aiRooms,
        'globalEdgeSuggestion'    => $aiDraft['globalEdgeSuggestion']    ?? null,
        'globalEdgeConfidence'    => $aiDraft['globalEdgeConfidence']    ?? 'low',
        'globalRemovalSuggestion' => $aiDraft['globalRemovalSuggestion'] ?? null,
        'globalRemovalConfidence' => $aiDraft['globalRemovalConfidence'] ?? 'low',
        'estimatedHoursOnSite'  => $estimatedHoursOnSite,
        'hoursConfidence'         => (string)($aiDraft['hoursConfidence'] ?? 'low'),
        'dxfSvg'                  => $dxfSvg,
        'warnings'                => array_values(array_merge(
            $dxfParsed['warnings'] ?? [],
            $textParsed['warnings'] ?? [],
            // Note if Claude output rooms don't align with DXF rooms
            ($dxfParsed && count($aiRooms) !== count($dxfParsed['rooms'] ?? []))
                ? ['Room count from AI (' . count($aiRooms) . ') differs from DXF (' . count($dxfParsed['rooms'] ?? []) . ') — verify room assignments.']
                : []
        )),
    ],
    'usage' => [
        'model'     => $result['model'],
        'inTokens'  => $result['inTokens'],
        'outTokens' => $result['outTokens'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
