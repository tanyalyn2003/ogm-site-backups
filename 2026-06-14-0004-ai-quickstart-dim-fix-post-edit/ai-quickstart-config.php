<?php
/**
 * OGM Quoter — AI Quick Start helper.
 * Include-only. Guard: define('OGM_QUICKSTART', true) before including.
 */
if (!defined('OGM_QUICKSTART')) { http_response_code(403); exit('Forbidden'); }

// ---------------------------------------------------------------------------
// Paths — API key shares the email module's .data/email/ directory
// ---------------------------------------------------------------------------

function ogm_qs_data_dir(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . 'email';
}

function ogm_qs_key_path(): string {
    return ogm_qs_data_dir() . DIRECTORY_SEPARATOR . 'claude-api-key.json';
}

function ogm_qs_log_path(): string {
    return ogm_qs_data_dir() . DIRECTORY_SEPARATOR . 'ai-quickstart-log.json';
}

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

function ogm_qs_load_config(): array {
    $path = ogm_qs_key_path();
    if (!is_file($path)) return [];
    $d = json_decode((string)@file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

function ogm_qs_api_key(): string {
    $cfg = ogm_qs_load_config();
    foreach (['apiKey', 'api_key', 'anthropicApiKey', 'key'] as $k) {
        $v = trim((string)($cfg[$k] ?? ''));
        if ($v !== '') return $v;
    }
    return trim((string)getenv('ANTHROPIC_API_KEY'));
}

function ogm_qs_model(): string {
    $cfg = ogm_qs_load_config();
    $m   = trim((string)($cfg['model'] ?? ''));
    return $m !== '' ? $m : 'claude-haiku-4-5';
}

function ogm_qs_daily_limit(): int {
    $cfg = ogm_qs_load_config();
    $lim = (int)($cfg['qsDailyCallLimit'] ?? $cfg['qs_daily_call_limit'] ?? 100);
    return max(1, min(500, $lim));
}

function ogm_qs_configured(): bool {
    return ogm_qs_api_key() !== '';
}

// ---------------------------------------------------------------------------
// Logging  (45-day retention, separate from email drafter log)
// ---------------------------------------------------------------------------

function ogm_qs_load_log(): array {
    $path = ogm_qs_log_path();
    if (!is_file($path)) return [];
    $d = json_decode((string)@file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

function ogm_qs_save_log(array $log): void {
    $cutoff = strtotime('-45 days');
    foreach (array_keys($log) as $day) {
        if (strtotime((string)$day) < $cutoff) unset($log[$day]);
    }
    $path = ogm_qs_log_path();
    @file_put_contents($path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($path, 0600);
}

function ogm_qs_today_call_count(): int {
    $log = ogm_qs_load_log();
    return (int)($log[date('Y-m-d')]['calls'] ?? 0);
}

function ogm_qs_log_call(string $username, int $inTokens, int $outTokens, string $model): void {
    $today = date('Y-m-d');
    $log   = ogm_qs_load_log();
    if (!isset($log[$today]) || !is_array($log[$today])) {
        $log[$today] = ['calls' => 0, 'inTokens' => 0, 'outTokens' => 0, 'byUser' => []];
    }
    $userKey = strtolower(trim($username)) ?: 'unknown';
    if (!isset($log[$today]['byUser'][$userKey])) {
        $log[$today]['byUser'][$userKey] = ['calls' => 0, 'inTokens' => 0, 'outTokens' => 0, 'model' => $model];
    }
    $log[$today]['calls']++;
    $log[$today]['inTokens']  += $inTokens;
    $log[$today]['outTokens'] += $outTokens;
    $log[$today]['byUser'][$userKey]['calls']++;
    $log[$today]['byUser'][$userKey]['inTokens']  += $inTokens;
    $log[$today]['byUser'][$userKey]['outTokens'] += $outTokens;
    $log[$today]['byUser'][$userKey]['model'] = $model;
    ogm_qs_save_log($log);
}

// ---------------------------------------------------------------------------
// System prompt
// ---------------------------------------------------------------------------

function ogm_qs_system_prompt(): string {
    return <<<'PROMPT'
You are an AI assistant for Olive Glass & Marble (OGM), a countertop company in
Fayetteville, NC. A sales rep is starting a quote. Extract structured information
from their job description and return a JSON draft.

RULES — HARD STOPS:
- NEVER output any dollar amount, price, cost, or rate field.
- NEVER invent dimensions. Dimensions are extracted server-side from DXF files
  and from explicit L×W patterns in rep notes. Do NOT output counterPieces,
  splashPieces, or cutouts. Do not write "not extracted" for dimensions that
  appear in the notes — the server parser handles them.
- Output ONLY the JSON object — no prose, no markdown fences, no explanation.
- If a value cannot be determined confidently, output null and set confidence:"low".

CONFIDENCE:
- "high"   = explicitly stated in the description or directly from a DXF label
- "medium" = reasonable inference (e.g., kitchen without stated edge → "Eased")
- "low"    = educated guess — flag for rep review

OGM CATALOG:
Stone types: Quartz, Granite, Marble, Quartzite, Porcelain, Other
Thickness: "3cm" (standard), "2cm" (budget/older jobs)
Edge profiles: Eased, Demi-Bullnose, Full Bullnose, Ogee, Chamfer, Miter, Waterfall
Splash options: "Standard 4\"", "Full Splash", "No Splash"
Removal: "No Removal", "Remove and Haul Away"

CUSTOMER FIELDS:
Extract name, phone, email, addr, city only if explicitly stated.
Leave as empty string "" if not mentioned — do not infer contact details.

OGM PHRASE INTERPRETATION:
- "Taj" → stoneMatch:"Taj Mahal", stoneType:"Quartzite"
- "Calacatta" → stoneMatch:"Calacatta", stoneType:"Marble", stoneConfidence:"medium"
- "bath"/"master bath"/"mbath" → label:"Master Bath"
- "powder"/"half bath" → label:"Powder Bath"
- "outdoor"/"outdoor kitchen" → label:"Outdoor Kitchen"
- "laundry"/"utility" → label:"Laundry Room"
- "removal" without detail → removalSuggestion:"Remove and Haul Away"
- "new construction"/"no removal" → removalSuggestion:"No Removal"
- Stone described as applying to "all" or "everything" → assign it to every room.

ROOM MATCHING:
If DXF_DATA is present, its "rooms" array lists detected rooms.
Match each DXF room to one entry in your output rooms array (same order).
Use the DXF room's "label" as the starting name; refine it from the description.
Do NOT add rooms not present in DXF_DATA.
If no DXF_DATA, infer rooms from the description.

NOTES FIELD:
Use "notes" for anything that doesn't fit a structured field — ambiguous stone
variant, unusual room label, conflicting info. Leave "" if nothing to flag.

TIME ON SITE:
- If the rep states hours ("8 hours on site", "full day" ≈ 8h, "two days" ≈ 16h),
  set estimatedHours on the affected room(s) or estimatedHoursOnSite for the whole job.
- Do NOT invent hours. Use null and hoursConfidence:"low" when unknown.
- Rough OGM guides (only when rep gives no hours): kitchen 6–10h, bath 3–5h, powder 2–3h.

OUTPUT FORMAT (exact JSON, no extra keys):
{
  "customer": {
    "name": "<string or null>",
    "phone": "<string or null>",
    "email": "<string or null>",
    "addr": "<string or null>",
    "city": "<string or null>"
  },
  "jobName": "<string or null>",
  "estimatedHoursOnSite": <number or null>,
  "hoursConfidence": "<high|medium|low>",
  "rooms": [
    {
      "label": "<room name>",
      "stoneMatch": "<stone name or null>",
      "stoneType": "<Quartz|Granite|Marble|Quartzite|Porcelain|Other|null>",
      "thickness": "<3cm|2cm|null>",
      "stoneConfidence": "<high|medium|low>",
      "edgeSuggestion": "<edge profile or null>",
      "edgeConfidence": "<high|medium|low>",
      "splashSuggestion": "<Standard 4\"|Full Splash|No Splash|null>",
      "splashConfidence": "<high|medium|low>",
      "removalSuggestion": "<No Removal|Remove and Haul Away|null>",
      "removalConfidence": "<high|medium|low>",
      "estimatedHours": <number or null>,
      "hoursConfidence": "<high|medium|low>",
      "notes": "<string>"
    }
  ],
  "globalEdgeSuggestion": "<edge profile or null>",
  "globalEdgeConfidence": "<high|medium|low>",
  "globalRemovalSuggestion": "<No Removal|Remove and Haul Away|null>",
  "globalRemovalConfidence": "<high|medium|low>"
}
PROMPT;
}

// ---------------------------------------------------------------------------
// Text measurement parser (explicit dimensions only — never guess)
// ---------------------------------------------------------------------------

function ogm_qs_norm_label(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim((string)$s);
}

function ogm_qs_parse_inches_token(string $raw): ?float
{
    $raw = trim(str_replace(['″', '"', '\\'], '', $raw));
    if ($raw === '') return null;
    if (preg_match('/^(\d+)\s*\'(?:\s*(\d+(?:\.\d+)?))?\s*$/', $raw, $m)) {
        return (float)$m[1] * 12 + (float)($m[2] ?? 0);
    }
    if (!is_numeric($raw)) return null;
    $n = (float)$raw;
    return $n > 0 ? $n : null;
}

/** Normalize notes so 76" x 36" and 76 x 25.5" parse reliably. */
function ogm_qs_normalize_dim_text(string $text): string
{
    $text = str_replace(['\\"', '″'], '"', $text);
    // 76" x 36" → 76 x 36
    $text = preg_replace('/(\d+(?:\.\d+)?)\s*"\s*/u', '$1 ', (string)$text);
    return (string)$text;
}

/**
 * Extract L×W pairs from free-form text. Optional $before context sets island vs counter label.
 */
function ogm_qs_extract_dim_pairs(string $text): array
{
    $text = ogm_qs_normalize_dim_text($text);
    $pairs = [];
    if (!preg_match_all(
        '/(\d+(?:\.\d+)?)\s*(?:in|inch|inches)?\s*[x×]\s*(\d+(?:\.\d+)?)\s*(?:in|inch|inches)?/i',
        $text,
        $matches,
        PREG_OFFSET_CAPTURE
    )) {
        return $pairs;
    }

    $n = count($matches[0]);
    for ($i = 0; $i < $n; $i++) {
        $a = ogm_qs_parse_inches_token($matches[1][$i][0]);
        $b = ogm_qs_parse_inches_token($matches[2][$i][0]);
        if ($a === null || $b === null) continue;
        $offset = (int)($matches[0][$i][1] ?? 0);
        $beforeLen = min(48, max(0, $offset));
        $before = $beforeLen > 0 ? substr($text, max(0, $offset - 48), $beforeLen) : '';
        $pairs[] = [
            'lengthIn' => max($a, $b),
            'widthIn'  => min($a, $b),
            'before'   => $before,
        ];
    }
    return $pairs;
}

function ogm_qs_default_text_room_label(string $description): string
{
    if (preg_match('/\b(?:kitchen|countertop|counter top|island)\b/i', $description)) {
        return 'Kitchen';
    }
    if (preg_match('/\bmaster\s+bath\b/i', $description)) return 'Master Bath';
    if (preg_match('/\b(?:powder|half)\s+bath\b/i', $description)) return 'Powder Bath';
    if (preg_match('/\bbath(?:room)?\b/i', $description)) return 'Bath';
    return 'Room 1';
}

function ogm_qs_piece_from_pair(float $lengthIn, float $widthIn, string $kind, string $label = ''): array
{
    $lengthIn = round($lengthIn, 2);
    $widthIn  = round($widthIn, 2);
    $sqFt     = round($lengthIn * $widthIn / 144, 2);
    if ($kind === 'splash') {
        return ['label' => $label ?: 'Splash (text)', 'lengthIn' => $lengthIn, 'heightIn' => $widthIn, 'sqFt' => $sqFt, 'source' => 'text'];
    }
    return ['label' => $label ?: 'Counter (text)', 'lengthIn' => $lengthIn, 'widthIn' => $widthIn, 'sqFt' => $sqFt, 'source' => 'text'];
}

/**
 * Parse explicit L×W measurements from rep notes when no DXF is attached.
 * Returns ['rooms'=>[...], 'warnings'=>[...]]
 */
function ogm_qs_parse_text_measurements(string $description): array
{
    $warnings = [];
    if (trim($description) === '') {
        return ['rooms' => [], 'warnings' => []];
    }

    $lines = preg_split('/\r\n|\r|\n|;/', $description) ?: [];
    $rooms = [];
    $currentLabel = ogm_qs_default_text_room_label($description);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (preg_match('/^([a-z0-9][a-z0-9 \\/\-]{1,40}):\s*(.+)$/i', $line, $labelMatch)) {
            $currentLabel = trim($labelMatch[1]);
            $line = trim($labelMatch[2]);
        } elseif (preg_match('/^((?:master\s+)?(?:kitchen|bath|powder(?:\s+bath)?|laundry|bar|outdoor(?:\s+kitchen)?))(?:\s|$)/i', $line, $roomLead)) {
            $currentLabel = trim($roomLead[1]);
        }

        foreach (ogm_qs_extract_dim_pairs($line) as $pair) {
            $roomKey = ogm_qs_norm_label($currentLabel);
            if (!isset($rooms[$roomKey])) {
                $rooms[$roomKey] = [
                    'label'          => $currentLabel,
                    'counterPieces'  => [],
                    'splashPieces'   => [],
                    'cutouts'        => [],
                ];
            }
            $lengthIn = $pair['lengthIn'];
            $widthIn  = $pair['widthIn'];
            $ctx      = $pair['before'];
            $isIsland = (bool)preg_match('/\bisland\b/i', $ctx);
            $isSplash = !preg_match('/no backsplash/i', $line)
                && (bool)preg_match('/\b(?:backsplash|splash run|splash)\b/i', $ctx);
            if ($isSplash || ($widthIn <= 12 && $widthIn > 0 && !$isIsland)) {
                $rooms[$roomKey]['splashPieces'][] = ogm_qs_piece_from_pair($lengthIn, $widthIn, 'splash');
            } else {
                $label = $isIsland ? 'Island (text)' : 'Counter (text)';
                $rooms[$roomKey]['counterPieces'][] = ogm_qs_piece_from_pair($lengthIn, $widthIn, 'counter', $label);
            }
        }
    }

    // Whole-note scan when textarea is one paragraph (common case)
    if (!$rooms) {
        $currentLabel = ogm_qs_default_text_room_label($description);
        $roomKey = ogm_qs_norm_label($currentLabel);
        foreach (ogm_qs_extract_dim_pairs($description) as $pair) {
            if (!isset($rooms[$roomKey])) {
                $rooms[$roomKey] = [
                    'label'          => $currentLabel,
                    'counterPieces'  => [],
                    'splashPieces'   => [],
                    'cutouts'        => [],
                ];
            }
            $lengthIn = $pair['lengthIn'];
            $widthIn  = $pair['widthIn'];
            $ctx      = $pair['before'];
            $isIsland = (bool)preg_match('/\bisland\b/i', $ctx);
            $isSplash = !preg_match('/no backsplash/i', $description)
                && (bool)preg_match('/\b(?:backsplash|splash run|splash)\b/i', $ctx);
            if ($isSplash || ($widthIn <= 12 && $widthIn > 0 && !$isIsland)) {
                $rooms[$roomKey]['splashPieces'][] = ogm_qs_piece_from_pair($lengthIn, $widthIn, 'splash');
            } else {
                $label = $isIsland ? 'Island (text)' : 'Counter (text)';
                $rooms[$roomKey]['counterPieces'][] = ogm_qs_piece_from_pair($lengthIn, $widthIn, 'counter', $label);
            }
        }
    }

    $out = array_values($rooms);
    if ($out) {
        $warnings[] = 'Measurements parsed from rep notes — verify each run before quoting.';
    }
    return ['rooms' => $out, 'warnings' => $warnings];
}

function ogm_qs_find_dxf_room(?array $dxfParsed, array $aiRoom, int $index): ?array
{
    if ($dxfParsed === null || empty($dxfParsed['rooms'])) return null;
    $label = ogm_qs_norm_label((string)($aiRoom['label'] ?? ''));
    foreach ($dxfParsed['rooms'] as $dr) {
        $dl = ogm_qs_norm_label((string)($dr['label'] ?? ''));
        if ($label === '' || $dl === '') continue;
        if ($label === $dl || str_contains($dl, $label) || str_contains($label, $dl)) {
            return $dr;
        }
    }
    return $dxfParsed['rooms'][$index] ?? null;
}

function ogm_qs_merge_text_room_dims(array &$room, array $textRoom): void
{
    if (empty($room['counterPieces']) && !empty($textRoom['counterPieces'])) {
        $room['counterPieces'] = $textRoom['counterPieces'];
    }
    if (empty($room['splashPieces']) && !empty($textRoom['splashPieces'])) {
        $room['splashPieces'] = $textRoom['splashPieces'];
    }
}

function ogm_qs_match_text_room(array $textRooms, string $label): ?array
{
    $want = ogm_qs_norm_label($label);
    foreach ($textRooms as $tr) {
        $tl = ogm_qs_norm_label((string)($tr['label'] ?? ''));
        if ($want === $tl || ($want && $tl && (str_contains($tl, $want) || str_contains($want, $tl)))) {
            return $tr;
        }
    }
    return null;
}

/** Combine all parsed text rooms into one bucket (single-room quotes). */
function ogm_qs_combine_text_rooms(array $textRooms): array
{
    $combined = ['label' => 'Combined', 'counterPieces' => [], 'splashPieces' => [], 'cutouts' => []];
    foreach ($textRooms as $tr) {
        foreach ($tr['counterPieces'] ?? [] as $p) $combined['counterPieces'][] = $p;
        foreach ($tr['splashPieces'] ?? [] as $p) $combined['splashPieces'][] = $p;
    }
    if (count($textRooms) === 1) {
        $combined['label'] = (string)($textRooms[0]['label'] ?? 'Combined');
    }
    return $combined;
}

/** Attach orphan text dimensions when label match fails (Kitchen vs Room 1). */
function ogm_qs_distribute_text_dims(array &$aiRooms, array $textRooms): void
{
    if (!$textRooms || !$aiRooms) return;

    $combined = ogm_qs_combine_text_rooms($textRooms);
    if (empty($combined['counterPieces']) && empty($combined['splashPieces'])) return;

    foreach ($aiRooms as &$room) {
        if (!empty($room['counterPieces']) || !empty($room['splashPieces'])) continue;
        $textRoom = ogm_qs_match_text_room($textRooms, (string)($room['label'] ?? ''));
        if ($textRoom === null && (count($aiRooms) === 1 || count($textRooms) === 1)) {
            $textRoom = $combined;
        }
        if ($textRoom !== null) {
            ogm_qs_merge_text_room_dims($room, $textRoom);
        }
    }
    unset($room);
}

/** Detect sink mention in notes for cutout count. */
function ogm_qs_apply_text_sink(array &$room, string $description): void
{
    if (!empty($room['cutouts'])) return;
    if (preg_match('/\b(intrepid\s*[0-9\-]+(?:\s*[0-9\-]+)?)\b/i', $description, $m)) {
        $room['cutouts'][] = [
            'type'       => 'sink',
            'model'      => trim($m[1]),
            'confidence' => 'high',
        ];
        return;
    }
    if (preg_match('/\b(?:sink|basin)\b[^.;\n]{0,80}?\b(in\s+)?([a-z][a-z0-9 \-]{2,30})/i', $description, $m)) {
        $model = trim($m[2] ?? '');
        if ($model !== '' && !preg_match('/^(?:an|a|the|in|no|us|eased|east)$/i', $model)) {
            $room['cutouts'][] = [
                'type'       => 'sink',
                'model'      => $model,
                'confidence' => 'medium',
            ];
            return;
        }
    }
    if (preg_match('/\b(?:sink|basin)\b/i', $description)) {
        $room['cutouts'][] = ['type' => 'sink', 'confidence' => 'medium'];
    }
}

/** Estimate install hours when AI did not provide them. */
function ogm_qs_resolve_room_hours(array $room, string $label): array
{
    if (isset($room['estimatedHours']) && is_numeric($room['estimatedHours'])) {
        $h = round(((float)$room['estimatedHours']) * 2) / 2;
        return [
            'estimatedHours'  => max(0.5, min(24.0, $h)),
            'hoursConfidence' => (string)($room['hoursConfidence'] ?? 'medium'),
        ];
    }

    $labelN = ogm_qs_norm_label($label);
    $base   = 6.0;
    if (str_contains($labelN, 'powder')) $base = 2.5;
    elseif (str_contains($labelN, 'bath')) $base = 4.0;
    elseif (str_contains($labelN, 'laundry')) $base = 3.0;
    elseif (str_contains($labelN, 'outdoor')) $base = 5.0;
    elseif (str_contains($labelN, 'bar')) $base = 3.5;

    $counterSF = 0.0;
    foreach (($room['counterPieces'] ?? []) as $p) {
        $counterSF += (float)($p['sqFt'] ?? 0);
    }
    $cutouts = count($room['cutouts'] ?? []);
    $removal = stripos((string)($room['removalSuggestion'] ?? ''), 'haul') !== false
        || stripos((string)($room['removalSuggestion'] ?? ''), 'remove') !== false;
    $hours = $base + ($counterSF / 18.0) + ($cutouts * 0.75) + ($removal ? 1.5 : 0.0);
    $hours = max(2.0, min(24.0, round($hours * 2) / 2));

    return ['estimatedHours' => $hours, 'hoursConfidence' => 'low'];
}

// ---------------------------------------------------------------------------
// Claude API call
// ---------------------------------------------------------------------------

function ogm_qs_call(string $userMsg): array {
    $apiKey = ogm_qs_api_key();
    if ($apiKey === '') return ['_error' => 'Claude API key not configured.', '_status' => 503];
    $model   = ogm_qs_model();
    $payload = [
        'model'      => $model,
        'max_tokens' => 1024,
        'system'     => ogm_qs_system_prompt(),
        'messages'   => [['role' => 'user', 'content' => $userMsg]],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: '          . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 60,
    ]);
    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['_error' => 'connection_failed: ' . $err, '_status' => 502];
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) return ['_error' => 'invalid_response', '_status' => 502];
    if ($status >= 400) {
        return ['_error' => (string)($json['error']['message'] ?? ('Claude API error ' . $status)), '_status' => $status];
    }
    $text = '';
    foreach (($json['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= (string)($block['text'] ?? '');
    }
    return [
        'text'       => trim($text),
        'inTokens'   => (int)($json['usage']['input_tokens']  ?? 0),
        'outTokens'  => (int)($json['usage']['output_tokens'] ?? 0),
        'stopReason' => (string)($json['stop_reason'] ?? ''),
        'model'      => $model,
    ];
}

// ===========================================================================
// DXF PARSER
// ===========================================================================

function ogm_qs_dxf_to_inches(float $v, int $units): float {
    switch ($units) {
        case 2:  return $v * 12.0;      // feet → inches
        case 4:  return $v / 25.4;      // mm → inches
        case 5:  return $v / 2.54;      // cm → inches
        case 6:  return $v * 39.3701;   // m → inches
        default: return $v;             // 0=unitless, 1=inches → passthrough
    }
}

/**
 * Parse DXF text → ['entities' => [...], 'units' => int].
 * Handles LWPOLYLINE, CIRCLE, TEXT, MTEXT, LINE entities.
 */
function ogm_qs_dxf_parse(string $raw): array {
    $lines = preg_split('/\r?\n/', $raw);
    $pairs = [];
    $n     = count($lines);
    for ($i = 0; $i + 1 < $n; $i += 2) {
        $pairs[] = [(int)trim($lines[$i]), trim($lines[$i + 1])];
    }

    $insunits  = 1; // default: inches
    $entities  = [];
    $inSection = '';
    $i = 0; $m = count($pairs);

    while ($i < $m) {
        [$c, $v] = $pairs[$i];

        if ($c === 0 && $v === 'EOF') break;

        if ($c === 0 && $v === 'SECTION') {
            $i++;
            if ($i < $m && $pairs[$i][0] === 2) $inSection = $pairs[$i][1];
            $i++;
            continue;
        }
        if ($c === 0 && $v === 'ENDSEC') { $inSection = ''; $i++; continue; }

        // Read $INSUNITS from HEADER
        if ($inSection === 'HEADER') {
            if ($c === 9 && $v === '$INSUNITS') {
                $i++;
                if ($i < $m && $pairs[$i][0] === 70) $insunits = (int)$pairs[$i][1];
            } else {
                $i++;
            }
            continue;
        }

        if ($inSection !== 'ENTITIES') { $i++; continue; }

        if ($c !== 0) { $i++; continue; }

        $type = $v; $i++;
        $ent  = [
            'type'   => $type,
            'layer'  => '0',
            'color'  => null,
            'pts'    => [],
            'closed' => false,
            'text'   => '',
        ];

        while ($i < $m && $pairs[$i][0] !== 0) {
            [$ec, $ev] = $pairs[$i]; $i++;
            switch ($ec) {
                case 8:  $ent['layer']  = $ev; break;
                case 62: $ent['color']  = (int)$ev; break;
                case 10: $ent['pts'][]  = [ogm_qs_dxf_to_inches((float)$ev, $insunits), 0.0]; break;
                case 20:
                    if (!empty($ent['pts'])) {
                        $last = count($ent['pts']) - 1;
                        $ent['pts'][$last][1] = ogm_qs_dxf_to_inches((float)$ev, $insunits);
                    }
                    break;
                case 11: $ent['x2'] = ogm_qs_dxf_to_inches((float)$ev, $insunits); break;
                case 21: $ent['y2'] = ogm_qs_dxf_to_inches((float)$ev, $insunits); break;
                case 40: $ent['r']  = ogm_qs_dxf_to_inches((float)$ev, $insunits); break;
                case 70:
                    if ($type === 'LWPOLYLINE') $ent['closed'] = ((int)$ev & 1) === 1;
                    break;
                case 1:
                case 3:  $ent['text'] .= $ev; break;
            }
        }

        $accepted = ['LWPOLYLINE', 'CIRCLE', 'TEXT', 'MTEXT', 'LINE'];
        if (!in_array($type, $accepted, true)) continue;

        // For LINE: add second point from x2/y2
        if ($type === 'LINE' && isset($ent['x2']) && !empty($ent['pts'])) {
            $ent['pts'][] = [$ent['x2'], $ent['y2'] ?? 0.0];
        }

        $entities[] = $ent;
    }

    return ['entities' => $entities, 'units' => $insunits];
}

function ogm_qs_bbox(array $pts): array {
    if (empty($pts)) return [0, 0, 0, 0];
    $xs = array_column($pts, 0);
    $ys = array_column($pts, 1);
    return [min($xs), min($ys), max($xs), max($ys)];
}

function ogm_qs_is_red(array $ent): bool {
    $c = $ent['color'];
    if ($c !== null && (int)$c === 1) return true;
    // Also treat entities on layers named "cutout", "cutouts", "red"
    $layer = strtolower($ent['layer'] ?? '');
    return in_array($layer, ['cutout', 'cutouts', 'red'], true);
}

function ogm_qs_pt_in_bbox(float $x, float $y, array $bbox): bool {
    return $x >= $bbox[0] && $x <= $bbox[2] && $y >= $bbox[1] && $y <= $bbox[3];
}

/**
 * Classify entities into room containers, pieces, cutouts, circles, texts.
 * Returns ['rooms'=>[...], 'warnings'=>[...], 'allShapes'=>[...]] where
 * allShapes holds containers/pieces/cutouts/circles for SVG rendering.
 */
function ogm_qs_dxf_rooms(array $entities): array {
    $containers = []; $pieces = []; $cutouts = []; $circles = []; $texts = [];

    foreach ($entities as $ent) {
        $type  = $ent['type'];
        $isRed = ogm_qs_is_red($ent);

        if ($type === 'LWPOLYLINE' && $ent['closed'] && count($ent['pts']) >= 3) {
            $bbox = ogm_qs_bbox($ent['pts']);
            $ent['bbox'] = $bbox;
            if ($isRed) {
                $cutouts[] = $ent;
            } elseif (count($ent['pts']) === 4) {
                $containers[] = $ent; // axis-aligned rectangle → room container
            } else {
                $pieces[] = $ent;     // irregular polygon → stone piece outline
            }
        } elseif ($type === 'LWPOLYLINE' && count($ent['pts']) >= 2) {
            $ent['bbox'] = ogm_qs_bbox($ent['pts']);
            if (!$isRed) $pieces[] = $ent;
        } elseif ($type === 'CIRCLE' && !empty($ent['pts'])) {
            $cx = $ent['pts'][0][0]; $cy = $ent['pts'][0][1]; $r = $ent['r'] ?? 0;
            $ent['cx']   = $cx; $ent['cy'] = $cy;
            $ent['bbox'] = [$cx - $r, $cy - $r, $cx + $r, $cy + $r];
            $circles[] = $ent;
        } elseif (($type === 'TEXT' || $type === 'MTEXT') && !empty($ent['pts'])) {
            $ent['tx'] = $ent['pts'][0][0]; $ent['ty'] = $ent['pts'][0][1];
            $content = trim($ent['text'] ?? '');
            if ($content !== '') $texts[] = $ent;
        } elseif ($type === 'LINE' && count($ent['pts']) >= 2) {
            $ent['bbox'] = ogm_qs_bbox($ent['pts']);
            if (!$isRed) $pieces[] = $ent;
        }
    }

    $warnings  = [];
    $fallback  = empty($containers);

    if ($fallback) {
        // No room containers: wrap everything in a synthetic bounding box
        $all = array_merge(
            array_column($pieces, 'bbox'),
            array_column($cutouts, 'bbox'),
            array_map(fn($c) => $c['bbox'], $circles)
        );
        if (empty($all)) {
            return ['rooms' => [], 'warnings' => ['No geometry found in DXF.'], 'allShapes' => compact('containers', 'pieces', 'cutouts', 'circles')];
        }
        $xs1 = array_column($all, 0); $ys1 = array_column($all, 1);
        $xs2 = array_column($all, 2); $ys2 = array_column($all, 3);
        $containers[] = ['type' => 'LWPOLYLINE', 'pts' => [], 'bbox' => [min($xs1), min($ys1), max($xs2), max($ys2)], 'color' => null, 'synthetic' => true];
        $warnings[] = 'No room container boxes found — all geometry grouped as Room 1.';
    }

    $rooms = [];
    foreach ($containers as $ci => $container) {
        $cb   = $container['bbox'];
        $room = [
            'containerBox' => ['minX' => round($cb[0], 2), 'minY' => round($cb[1], 2), 'maxX' => round($cb[2], 2), 'maxY' => round($cb[3], 2)],
            'label'        => '',
            'pieces'       => [],
            'cutouts'      => [],
            'synthetic'    => !empty($container['synthetic']),
        ];

        // Find label from text entities inside the container
        foreach ($texts as $t) {
            if (ogm_qs_pt_in_bbox($t['tx'], $t['ty'], $cb)) {
                $room['label'] = $t['content'] ?? (trim($t['text'] ?? ''));
                break;
            }
        }
        if ($room['label'] === '') {
            $num = $ci + 1;
            $room['label'] = "Room {$num}";
            if (!$fallback) $warnings[] = "Room {$num} had no text label — assigned \"Room {$num}\".";
        }

        // Pieces inside container
        foreach ($pieces as $piece) {
            $pb  = $piece['bbox'];
            $pcx = ($pb[0] + $pb[2]) / 2;
            $pcy = ($pb[1] + $pb[3]) / 2;
            if (!ogm_qs_pt_in_bbox($pcx, $pcy, $cb)) continue;
            $w = round(abs($pb[2] - $pb[0]), 2);
            $h = round(abs($pb[3] - $pb[1]), 2);
            $room['pieces'][] = [
                'pts'      => array_map(fn($p) => [round($p[0], 2), round($p[1], 2)], $piece['pts']),
                'lengthIn' => max($w, $h),
                'widthIn'  => min($w, $h),
                'sqFt'     => round(max($w, $h) * min($w, $h) / 144, 2),
            ];
        }

        // Cutouts inside container
        foreach ($cutouts as $cut) {
            $cb2 = $cut['bbox'];
            $ccx = ($cb2[0] + $cb2[2]) / 2;
            $ccy = ($cb2[1] + $cb2[3]) / 2;
            if (!ogm_qs_pt_in_bbox($ccx, $ccy, $cb)) continue;
            $w = round(abs($cb2[2] - $cb2[0]), 2);
            $h = round(abs($cb2[3] - $cb2[1]), 2);
            $room['cutouts'][] = [
                'shape'    => 'rectangle',
                'lengthIn' => max($w, $h),
                'widthIn'  => min($w, $h),
                'pts'      => array_map(fn($p) => [round($p[0], 2), round($p[1], 2)], $cut['pts']),
            ];
        }

        // Circles (sinks) inside container
        foreach ($circles as $circ) {
            if (!ogm_qs_pt_in_bbox($circ['cx'], $circ['cy'], $cb)) continue;
            $room['cutouts'][] = [
                'shape'      => 'circle',
                'diameterIn' => round(($circ['r'] ?? 0) * 2, 2),
                'cx'         => round($circ['cx'], 2),
                'cy'         => round($circ['cy'], 2),
            ];
        }

        $rooms[] = $room;
    }

    return [
        'rooms'     => $rooms,
        'warnings'  => $warnings,
        'allShapes' => compact('containers', 'pieces', 'cutouts', 'circles'),
    ];
}

// ===========================================================================
// SVG GENERATOR
// ===========================================================================

/**
 * Render parsed DXF data as an SVG string (~580px wide viewBox).
 * Black outlines for piece shapes, red for cutouts,
 * thin gray dashed rectangles for room containers.
 * No text labels in the SVG.
 */
function ogm_qs_dxf_to_svg(array $parsed): string {
    $shapes = $parsed['allShapes'] ?? [];
    $rooms  = $parsed['rooms']     ?? [];

    // Collect all coordinates for global bounding box
    $allX = []; $allY = [];
    foreach (array_merge($shapes['pieces'] ?? [], $shapes['cutouts'] ?? [], $shapes['containers'] ?? []) as $s) {
        foreach ($s['pts'] as [$px, $py]) { $allX[] = $px; $allY[] = $py; }
    }
    foreach ($shapes['circles'] ?? [] as $circ) {
        $r = $circ['r'] ?? 0;
        $allX[] = $circ['cx'] - $r; $allX[] = $circ['cx'] + $r;
        $allY[] = $circ['cy'] - $r; $allY[] = $circ['cy'] + $r;
    }
    if (empty($allX)) return '';

    $minX = min($allX); $maxX = max($allX);
    $minY = min($allY); $maxY = max($allY);
    $dxfW = $maxX - $minX ?: 1;
    $dxfH = $maxY - $minY ?: 1;

    $svgW  = 580;
    $svgH  = max(180, (int)round($svgW * $dxfH / $dxfW));
    $pad   = 0.04; // 4% each side
    $scale = min($svgW * (1 - 2 * $pad) / $dxfW, $svgH * (1 - 2 * $pad) / $dxfH);
    $offX  = ($svgW  - $dxfW * $scale) / 2 - $minX * $scale;
    $offY  = ($svgH  + $dxfH * $scale) / 2 + $minY * $scale; // Y-flip origin

    $tx = fn($x) => round($x * $scale + $offX, 2);
    $ty = fn($y) => round(-$y * $scale + $offY, 2); // DXF Y → SVG Y (flip)

    $buf = '';

    // Room containers (gray dashed)
    foreach ($rooms as $room) {
        if (!empty($room['synthetic'])) continue;
        $cb = $room['containerBox'];
        $x1 = $tx($cb['minX']); $y1 = $ty($cb['maxY']);
        $w  = round(($cb['maxX'] - $cb['minX']) * $scale, 2);
        $h  = round(($cb['maxY'] - $cb['minY']) * $scale, 2);
        $buf .= "<rect x=\"{$x1}\" y=\"{$y1}\" width=\"{$w}\" height=\"{$h}\" fill=\"none\" stroke=\"#64748b\" stroke-width=\"0.5\" stroke-dasharray=\"4 3\"/>\n";
    }

    // Piece outlines (black)
    foreach ($shapes['pieces'] ?? [] as $piece) {
        if (count($piece['pts']) < 2) continue;
        $pts = implode(' ', array_map(fn($p) => $tx($p[0]) . ',' . $ty($p[1]), $piece['pts']));
        $tag = $piece['closed'] ?? false ? 'polygon' : 'polyline';
        $buf .= "<{$tag} points=\"{$pts}\" fill=\"none\" stroke=\"#e2e8f0\" stroke-width=\"1.5\"/>\n";
    }

    // Cutouts (red fill + stroke)
    foreach ($shapes['cutouts'] ?? [] as $cut) {
        if (count($cut['pts']) < 3) continue;
        $pts = implode(' ', array_map(fn($p) => $tx($p[0]) . ',' . $ty($p[1]), $cut['pts']));
        $buf .= "<polygon points=\"{$pts}\" fill=\"rgba(220,38,38,0.15)\" stroke=\"#dc2626\" stroke-width=\"1\"/>\n";
    }

    // Circles (red)
    foreach ($shapes['circles'] ?? [] as $circ) {
        $r = round(($circ['r'] ?? 0) * $scale, 2);
        if ($r < 1) $r = 1;
        $buf .= "<circle cx=\"{$tx($circ['cx'])}\" cy=\"{$ty($circ['cy'])}\" r=\"{$r}\" fill=\"rgba(220,38,38,0.15)\" stroke=\"#dc2626\" stroke-width=\"1\"/>\n";
    }

    return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$svgW} {$svgH}\" style=\"width:100%;height:auto;display:block\">\n<rect width=\"{$svgW}\" height=\"{$svgH}\" fill=\"#0f172a\"/>\n{$buf}</svg>";
}
