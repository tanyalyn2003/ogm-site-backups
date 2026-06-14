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
- NEVER invent dimensions. Dimensions are extracted server-side from the DXF file
  and provided in DXF_DATA. Do NOT output counterPieces, splashPieces, or cutouts.
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
