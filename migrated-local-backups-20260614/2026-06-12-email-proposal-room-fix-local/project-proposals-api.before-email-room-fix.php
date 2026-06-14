<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action !== 'view' && $action !== 'public-view') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

if (!qtIsLoggedIn() && $action !== 'public-view') {
    if ($action === 'view') {
        http_response_code(401);
        echo '<!doctype html><title>Not logged in</title><body style="font-family:system-ui;padding:24px">Sign in to the Quoter Tool to view this project proposal.</body>';
    } else {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    }
    exit;
}

$dataRoot = __DIR__ . DIRECTORY_SEPARATOR . '.data';
$proposalDir = $dataRoot . DIRECTORY_SEPARATOR . 'project-proposals';
$selectionDir = $proposalDir . DIRECTORY_SEPARATOR . '_selections';

function ogmPpJson($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function ogmPpEnsureDir($dir) {
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0755, true);
}

function ogmPpProtectDataRoot($dir) {
    if (!ogmPpEnsureDir($dir)) return false;
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    return true;
}

function ogmPpSafeId($raw, $max = 100) {
    $s = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $raw);
    if ($s === null) return '';
    $s = substr($s, 0, $max);
    return $s;
}

function ogmPpReadJson($path, $fallback = null) {
    if (!is_file($path)) return $fallback;
    $raw = @file_get_contents($path);
    $data = $raw ? json_decode((string) $raw, true) : null;
    return is_array($data) ? $data : $fallback;
}

function ogmPpWriteJson($path, array $data) {
    return @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
}

function ogmPpReadBody() {
    $raw = file_get_contents('php://input');
    $body = json_decode((string) $raw, true);
    return is_array($body) ? $body : null;
}

function ogmPpBaseUrl() {
    $base = rtrim(qtBasePath(), '/');
    $path = $base . '/project-proposals-api.php';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return $path;
    $scheme = qtIsHttpsRequest() ? 'https' : 'http';
    return $scheme . '://' . $host . $path;
}

function ogmPpViewUrl($id) {
    return ogmPpBaseUrl() . '?action=view&id=' . rawurlencode($id);
}

function ogmPpPublicViewUrl($token) {
    return ogmPpBaseUrl() . '?action=public-view&token=' . rawurlencode($token);
}

function ogmPpPublicExpiresAt($days = 90) {
    $days = max(1, min(90, (int) $days));
    return gmdate('c', time() + ($days * 86400));
}

function ogmPpIsExpired($iso) {
    $iso = trim((string) $iso);
    if ($iso === '') return false;
    $ts = strtotime($iso);
    return $ts !== false && time() > $ts;
}

function ogmPpExpiredPage($message = 'This customer proposal link has expired.') {
    http_response_code(410);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Proposal link expired</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f5f4;color:#1c1917;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}.box{max-width:620px;padding:30px;border:1px solid #d8d1c2;border-radius:12px;background:#fff;box-shadow:0 18px 44px rgba(0,0,0,.12)}h1{margin:0 0 10px;font-size:24px}p{margin:0;color:#57534e;line-height:1.65}</style></head><body><main class="box"><h1>Proposal link expired</h1><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' Please contact Olive Glass &amp; Marble for a refreshed proposal link.</p></main></body></html>';
    exit;
}

function ogmPpViewerUrlWithParams($url, array $params) {
    $url = trim((string) $url);
    if ($url === '') return '';
    $parts = parse_url($url);
    if (!is_array($parts)) return $url;
    $query = [];
    if (isset($parts['query'])) parse_str((string) $parts['query'], $query);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]);
        else $query[$key] = (string) $value;
    }
    $rebuilt = '';
    if (isset($parts['scheme'])) $rebuilt .= $parts['scheme'] . '://';
    if (isset($parts['user'])) {
        $rebuilt .= $parts['user'];
        if (isset($parts['pass'])) $rebuilt .= ':' . $parts['pass'];
        $rebuilt .= '@';
    }
    if (isset($parts['host'])) $rebuilt .= $parts['host'];
    if (isset($parts['port'])) $rebuilt .= ':' . $parts['port'];
    $rebuilt .= $parts['path'] ?? '';
    if ($query) $rebuilt .= '?' . http_build_query($query);
    if (isset($parts['fragment'])) $rebuilt .= '#' . $parts['fragment'];
    return $rebuilt;
}

function ogmPpViewerSnapshotFromUrl($viewerUrl) {
    $parts = parse_url((string) $viewerUrl);
    if (!is_array($parts)) return null;
    $query = [];
    if (isset($parts['query'])) parse_str((string) $parts['query'], $query);
    $token = trim((string) ($query['token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . $token . '.json';
    $payload = ogmPpReadJson($path, null);
    $snapshot = is_array($payload) ? ($payload['snapshot'] ?? null) : null;
    return is_array($snapshot) ? $snapshot : null;
}

function ogmPpRoomKeyForPart($part) {
    if (!is_array($part)) return 'Room';
    $meta = is_array($part['meta'] ?? null) ? $part['meta'] : [];
    $assembly = is_array($part['assemblyPlacement'] ?? null) ? $part['assemblyPlacement'] : [];
    $raw = $assembly['roomId'] ?? $part['roomId'] ?? $meta['roomId'] ?? $meta['roomName'] ?? $part['roomName'] ?? $part['displayLabel'] ?? $part['label'] ?? 'Room';
    $key = trim((string) $raw);
    return $key !== '' ? substr($key, 0, 180) : 'Room';
}

function ogmPpRoomLabelForPart($part) {
    if (!is_array($part)) return 'Room';
    $meta = is_array($part['meta'] ?? null) ? $part['meta'] : [];
    $assembly = is_array($part['assemblyPlacement'] ?? null) ? $part['assemblyPlacement'] : [];
    $raw = $meta['displayLabel'] ?? $meta['roomName'] ?? $part['roomName'] ?? $part['displayLabel'] ?? $part['label'] ?? $assembly['roomId'] ?? $part['roomId'] ?? 'Room';
    $label = trim((string) $raw);
    return $label !== '' ? substr($label, 0, 180) : 'Room';
}

function ogmPpNormalizePrintOptions($opts, $base = null) {
    $baseOpts = is_array($base) ? ($base['printOptions'] ?? []) : [];
    if (!is_array($baseOpts)) $baseOpts = [];
    $in = is_array($opts) ? $opts : [];
    return [
        'include2DMockups' => array_key_exists('include2DMockups', $in)
            ? (bool) $in['include2DMockups']
            : (array_key_exists('include2DMockups', $baseOpts) ? (bool) $baseOpts['include2DMockups'] : true),
    ];
}

function ogmPpNormalizeRoomViews($views) {
    $out = [];
    if (!is_array($views)) return $out;
    foreach ($views as $view) {
        if (!is_array($view)) continue;
        $roomKey = trim((string) ($view['roomKey'] ?? ''));
        if ($roomKey === '') continue;
        $out[] = [
            'roomKey' => substr($roomKey, 0, 180),
            'roomLabel' => substr(trim((string) ($view['roomLabel'] ?? $roomKey)), 0, 180),
            'twoDUrl' => trim((string) ($view['twoDUrl'] ?? '')),
            'threeDUrl' => trim((string) ($view['threeDUrl'] ?? '')),
        ];
    }
    return $out;
}

function ogmPpBuildRoomViews($viewerUrl, $existing = []) {
    $existingViews = ogmPpNormalizeRoomViews($existing);
    if ($existingViews) return $existingViews;
    $viewerUrl = trim((string) $viewerUrl);
    if ($viewerUrl === '') return [];
    $snapshot = ogmPpViewerSnapshotFromUrl($viewerUrl);
    if (!is_array($snapshot)) return [];
    $groups = [];
    foreach ((array) ($snapshot['parts'] ?? []) as $part) {
        if (!is_array($part)) continue;
        if ((string) ($part['partType'] ?? '') !== 'countertop') continue;
        $key = ogmPpRoomKeyForPart($part);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'roomKey' => $key,
                'roomLabel' => ogmPpRoomLabelForPart($part),
                'twoDUrl' => ogmPpViewerUrlWithParams($viewerUrl, ['view' => '2d', 'roomKey' => $key, 'embedded' => '1']),
                'threeDUrl' => ogmPpViewerUrlWithParams($viewerUrl, ['view' => '3d', 'roomKey' => $key, 'embedded' => '1']),
            ];
        }
    }
    return array_values($groups);
}

function ogmPpFindPackageByPublicToken($dir, $token) {
    $token = trim((string) $token);
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    foreach ((glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: []) as $path) {
        $name = basename($path);
        if ($name === '' || $name[0] === '_') continue;
        $pkg = ogmPpReadJson($path, null);
        if (is_array($pkg) && hash_equals((string) ($pkg['publicToken'] ?? ''), $token)) return $pkg;
    }
    return null;
}

function ogmPpFindPackages($dir, $taskId = '', $quoteNumber = '') {
    $taskId = (string) $taskId;
    $quoteNumber = (string) $quoteNumber;
    $out = [];
    foreach ((glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: []) as $path) {
        $name = basename($path);
        if ($name === '' || $name[0] === '_') continue;
        $pkg = ogmPpReadJson($path, null);
        if (!is_array($pkg)) continue;
        if ($taskId !== '' && (string) ($pkg['taskId'] ?? '') !== $taskId) continue;
        if ($quoteNumber !== '' && (string) ($pkg['quoteNumber'] ?? '') !== $quoteNumber) continue;
        $out[] = $pkg;
    }
    usort($out, static function ($a, $b) {
        $av = (int) ($a['version'] ?? 0);
        $bv = (int) ($b['version'] ?? 0);
        if ($av !== $bv) return $bv <=> $av;
        return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
    });
    return $out;
}

function ogmPpLatest($dir, $taskId = '', $quoteNumber = '') {
    $all = ogmPpFindPackages($dir, $taskId, $quoteNumber);
    return $all ? $all[0] : null;
}

function ogmPpSelectionPath($dir, $taskId) {
    return $dir . DIRECTORY_SEPARATOR . ogmPpSafeId($taskId, 120) . '.json';
}

function ogmPpNormalizeDocs($docs) {
    $out = [];
    if (!is_array($docs)) return $out;
    foreach ($docs as $doc) {
        if (!is_array($doc)) continue;
        $key = trim((string) ($doc['key'] ?? ''));
        $url = trim((string) ($doc['url'] ?? ''));
        if ($key === '' || $url === '') continue;
        $out[] = [
            'key' => substr($key, 0, 300),
            'title' => substr(trim((string) ($doc['title'] ?? 'Document')), 0, 180),
            'url' => $url,
            'kind' => substr(trim((string) ($doc['kind'] ?? 'file')), 0, 40),
            'date' => substr(trim((string) ($doc['date'] ?? '')), 0, 80),
        ];
    }
    return $out;
}

function ogmPpRenderPackageHtml(array $pkg, $public = false) {
    $esc = static function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $proposalHtml = (string) ($pkg['proposalHtml'] ?? '');
    $viewerUrl = trim((string) ($pkg['viewerUrl'] ?? ''));
    $docs = is_array($pkg['includedDocuments'] ?? null) ? $pkg['includedDocuments'] : [];
    $roomViews = ogmPpNormalizeRoomViews($pkg['roomViews'] ?? []);
    $printOptions = ogmPpNormalizePrintOptions($pkg['printOptions'] ?? [], $pkg);
    $include2D = !empty($printOptions['include2DMockups']);
    $title = trim((string) ($pkg['customerName'] ?? 'Project Proposal'));
    $quote = trim((string) ($pkg['quoteNumber'] ?? ''));
    $version = (int) ($pkg['version'] ?? 1);
    $createdAt = trim((string) ($pkg['createdAt'] ?? ''));
    $publicUrl = trim((string) ($pkg['publicViewUrl'] ?? ''));
    $publicExpiresAt = trim((string) ($pkg['publicExpiresAt'] ?? ''));

    $docRows = '';
    foreach ($docs as $doc) {
        if (!is_array($doc)) continue;
        $docRows .= '<div class="pp-doc-row"><span>' . $esc($doc['title'] ?? 'Document') . '</span><a href="' . $esc($doc['url'] ?? '#') . '" target="_blank" rel="noopener">Open</a></div>';
    }

    $roomRows = '';
    foreach ($roomViews as $room) {
        $roomLabel = $esc($room['roomLabel'] ?? $room['roomKey'] ?? 'Room');
        $twoD = trim((string) ($room['twoDUrl'] ?? ''));
        $threeD = trim((string) ($room['threeDUrl'] ?? ''));
        $roomRows .= '<article class="pp-room-card">'
            . '<div class="pp-room-head"><h2>' . $roomLabel . '</h2><span>Room views</span></div>'
            . ($include2D && $twoD !== '' ? '<div class="pp-2d-wrap"><iframe class="pp-view-frame pp-2d-frame" data-pp-2d-frame src="' . $esc($twoD) . '" title="' . $roomLabel . ' 2D mockup" loading="eager"></iframe></div>' : '')
            . ($threeD !== '' ? '<details class="pp-3d-card" data-pp-3d><summary>Open interactive 3D view</summary><iframe class="pp-view-frame pp-3d-frame" data-src="' . $esc($threeD) . '" title="' . $roomLabel . ' interactive 3D" loading="lazy"></iframe></details>' : '')
            . '</article>';
    }

    $actions = '<div class="pp-actions"><button type="button" onclick="printProjectProposal()">Print Project Proposal</button>'
        . (!$public && $publicUrl !== '' ? '<a class="pp-action-link" href="' . $esc($publicUrl) . '" target="_blank" rel="noopener">Customer Digital Link</a>' : '')
        . '</div>';

    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Project Proposal ' . $esc($quote) . '</title>'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap">'
        . '<style>'
        . ':root{--gold:#b08a3c;--gold-p:#f0e6cc;--s50:#fafaf9;--s100:#f5f5f4;--s200:#e7e5e4;--s500:#57534e;--s900:#1c1917}'
        . '*{box-sizing:border-box}html,body{margin:0;background:#f5f5f4;color:#1c1917;font-family:"DM Sans",Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
        . '.pp-shell{max-width:8in;margin:24px auto;background:#fff;padding:.28in .34in;box-shadow:0 12px 36px rgba(0,0,0,.12)}'
        . '.pp-top{display:flex;justify-content:space-between;gap:24px;border-bottom:1px solid #eae6da;padding-bottom:16px;margin-bottom:18px}'
        . '.pp-logo{font-family:"Cormorant Garamond",serif;font-size:34px;letter-spacing:.08em}.pp-kicker{font-size:10px;letter-spacing:.2em;color:#57534e}'
        . '.pp-title{text-align:right}.pp-title h1{font-family:"Cormorant Garamond",serif;font-size:26px;font-weight:400;margin:0;color:#1c1917}.pp-meta{font-size:11px;color:#57534e;line-height:1.8;margin-top:6px}'
        . '.pp-section{border-bottom:1px solid #eae6da;padding-bottom:16px;margin-bottom:18px}.pp-h{font-size:9px;letter-spacing:.2em;text-transform:uppercase;color:#57534e;margin-bottom:8px}'
        . '.pp-link-card{border:1px solid #d4b87a;background:#f8f1de;padding:12px 14px;border-radius:2px;font-size:12px;line-height:1.7}.pp-link-card a{color:#7a5d25;font-weight:600}'
        . '.pp-doc-row{display:flex;justify-content:space-between;gap:16px;border-bottom:1px solid #f5f5f4;padding:8px 0;font-size:12px}.pp-doc-row a{color:#7a5d25;font-weight:600}'
        . '.pp-actions{position:sticky;top:0;background:#111827;color:#fff;padding:10px 12px;display:flex;justify-content:center;gap:8px;z-index:20}.pp-actions button,.pp-action-link{border:1px solid #c4a05a;background:#1f2937;color:#fff;padding:8px 16px;font-weight:600;letter-spacing:.08em;cursor:pointer;text-decoration:none;font-size:12px}'
        . '.pp-rooms{display:grid;gap:16px}.pp-room-card{border:1px solid #e5ddc9;background:#fff;padding:12px;break-inside:avoid}.pp-room-head{display:flex;justify-content:space-between;gap:12px;align-items:baseline;margin-bottom:10px}.pp-room-head h2{font-family:"Cormorant Garamond",serif;font-size:22px;font-weight:500;margin:0}.pp-room-head span{font-size:9px;letter-spacing:.18em;text-transform:uppercase;color:#8b7d61}.pp-view-frame{width:100%;border:1px solid #d8d1c2;background:#0b1222;border-radius:4px}.pp-2d-frame{height:360px}.pp-3d-card{margin-top:10px;border:1px solid #d4b87a;background:#f8f1de;padding:10px 12px}.pp-3d-card summary{cursor:pointer;font-weight:700;color:#6f5522}.pp-3d-frame{height:430px;margin-top:10px}'
        . '.ogm-customer-doc{background:#fff;color:#1c1917}.ogm-customer-doc .pm-sec{margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid #eae6da}.ogm-customer-doc .pm-h{font-size:9px;letter-spacing:.2em;text-transform:uppercase;color:#57534e;margin-bottom:8px}.ogm-customer-doc .pm-deposit{background:#f0e6cc!important;border:1px solid #d4b87a!important;padding:10px 14px;font-size:12px;color:#4a4538;line-height:1.8;margin-top:12px}.ogm-customer-doc .pm-total{display:flex;justify-content:space-between;align-items:baseline;border-top:2px solid #cbbf9d;padding:18px 0 20px}.ogm-customer-doc .pm-total span:last-child{font-family:"Cormorant Garamond",serif;font-size:28px;color:#b08a3c}.ogm-customer-doc .pm-room-note,.ogm-customer-doc .pm-bottom-note{margin-top:12px;padding:9px 12px;border-left:2px solid #cbbf9d;background:#fbfaf6;color:#44403c;font-size:12px;line-height:1.7}'
        . '@media print{@page{margin:.5in}body{background:#fff}.pp-actions,.pp-3d-card,.pp-link-card,.pp-doc-row a,.pp-room-section.pp-no-print-2d{display:none!important}.pp-shell{box-shadow:none;margin:0 auto;max-width:none;padding:0}.pp-section,.pp-room-card{break-inside:avoid}.pp-2d-frame{height:320px}}'
        . '</style></head><body>' . $actions . '<main class="pp-shell">'
        . '<div class="pp-top"><div><div class="pp-logo">OGM</div><div class="pp-kicker">OLIVE GLASS &amp; MARBLE</div><div class="pp-meta">714 Robeson Street · Fayetteville, NC 28305<br>(910) 484-5277 · www.oliveglassandmarble.com</div></div><div class="pp-title"><h1>Project Proposal</h1><div class="pp-meta">' . $esc($title) . '<br>' . $esc($quote) . '<br>Version ' . $version . ($createdAt ? '<br>' . $esc($createdAt) : '') . ($public && $publicExpiresAt ? '<br>Link expires: ' . $esc($publicExpiresAt) : '') . '</div></div></div>'
        . '<section class="pp-section"><div class="pp-h">Quote Proposal</div><div class="ogm-customer-doc">' . $proposalHtml . '</div></section>'
        . ($roomRows !== '' ? '<section class="pp-section pp-room-section' . (!$include2D ? ' pp-no-print-2d' : '') . '"><div class="pp-h">Room Mockups &amp; Interactive Views</div><div class="pp-rooms">' . $roomRows . '</div></section>' : ($viewerUrl !== '' ? '<section class="pp-section"><div class="pp-h">Layout / Assembly / 3D Viewer</div><div class="pp-link-card">Open the saved customer layout and 3D/2D viewer: <a href="' . $esc($viewerUrl) . '" target="_blank" rel="noopener">' . $esc($viewerUrl) . '</a></div></section>' : ''))
        . ($docRows !== '' ? '<section class="pp-section"><div class="pp-h">Included Job Documents</div>' . $docRows . '</section>' : '')
        . '</main><script>'
        . 'document.querySelectorAll("[data-pp-3d]").forEach(function(d){d.addEventListener("toggle",function(){if(!d.open)return;var f=d.querySelector("iframe[data-src]");if(f&&!f.src)f.src=f.getAttribute("data-src");},{once:true});});'
        . 'function printProjectProposal(){var frames=[].slice.call(document.querySelectorAll("[data-pp-2d-frame]"));if(!frames.length){window.print();return;}var pending=frames.length,done=false;function finish(){if(done)return;done=true;window.print();}var timer=setTimeout(finish,3500);function mark(){pending-=1;if(pending<=0){clearTimeout(timer);finish();}}window.addEventListener("message",function(e){if(e&&e.data&&e.data.type==="OGM_VIEWER_READY"&&e.data.view==="2d")mark();});frames.forEach(function(f){try{if(f.contentWindow&&f.contentDocument&&f.contentDocument.readyState==="complete")mark();}catch(_){}});}'
        . '</script></body></html>';
}

if (!ogmPpProtectDataRoot($dataRoot) || !ogmPpEnsureDir($proposalDir) || !ogmPpEnsureDir($selectionDir)) {
    ogmPpJson(['ok' => false, 'error' => 'Could not create project proposal storage.'], 500);
}

if ($action === 'public-view') {
    $token = trim((string) ($_GET['token'] ?? ''));
    $pkg = ogmPpFindPackageByPublicToken($proposalDir, $token);
    if (!is_array($pkg)) {
        http_response_code(404);
        echo '<!doctype html><title>Proposal not found</title><body style="font-family:system-ui;padding:24px">Project proposal not found.</body>';
        exit;
    }
    if (ogmPpIsExpired($pkg['publicExpiresAt'] ?? '')) {
        ogmPpExpiredPage();
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo ogmPpRenderPackageHtml($pkg, true);
    exit;
}

if ($action === 'view') {
    $id = ogmPpSafeId($_GET['id'] ?? '', 120);
    if ($id === '') {
        http_response_code(400);
        echo '<!doctype html><title>Missing proposal</title><body>Missing proposal id.</body>';
        exit;
    }
    $pkg = ogmPpReadJson($proposalDir . DIRECTORY_SEPARATOR . $id . '.json', null);
    if (!is_array($pkg)) {
        http_response_code(404);
        echo '<!doctype html><title>Proposal not found</title><body>Project proposal not found.</body>';
        exit;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo ogmPpRenderPackageHtml($pkg, false);
    exit;
}

if ($action === 'latest') {
    $taskId = ogmPpSafeId($_GET['taskId'] ?? '', 120);
    $quoteNumber = ogmPpSafeId($_GET['quoteNumber'] ?? '', 120);
    $pkg = ogmPpLatest($proposalDir, $taskId, $quoteNumber);
    ogmPpJson(['ok' => true, 'proposal' => $pkg]);
}

if ($action === 'selection') {
    $taskId = ogmPpSafeId($_GET['taskId'] ?? '', 120);
    if ($taskId === '') ogmPpJson(['ok' => false, 'error' => 'taskId is required.'], 400);
    $sel = ogmPpReadJson(ogmPpSelectionPath($selectionDir, $taskId), ['taskId' => $taskId, 'selectedKeys' => []]);
    ogmPpJson(['ok' => true, 'selection' => $sel]);
}

if ($action === 'save-selection') {
    if ($method !== 'POST') ogmPpJson(['ok' => false, 'error' => 'Method not allowed.'], 405);
    $body = ogmPpReadBody();
    if (!is_array($body)) ogmPpJson(['ok' => false, 'error' => 'Invalid JSON.'], 400);
    $taskId = ogmPpSafeId($body['taskId'] ?? '', 120);
    if ($taskId === '') ogmPpJson(['ok' => false, 'error' => 'taskId is required.'], 400);
    $keys = [];
    foreach ((array) ($body['selectedKeys'] ?? []) as $key) {
        $key = trim((string) $key);
        if ($key !== '') $keys[] = substr($key, 0, 300);
    }
    $keys = array_values(array_unique($keys));
    $sel = [
        'taskId' => $taskId,
        'selectedKeys' => $keys,
        'updatedAt' => gmdate('c'),
        'updatedBy' => qtCurrentUser(),
    ];
    if (!ogmPpWriteJson(ogmPpSelectionPath($selectionDir, $taskId), $sel)) {
        ogmPpJson(['ok' => false, 'error' => 'Could not save document selection.'], 500);
    }
    ogmPpJson(['ok' => true, 'selection' => $sel]);
}

if ($action === 'save' || $action === 'regenerate') {
    if ($method !== 'POST') ogmPpJson(['ok' => false, 'error' => 'Method not allowed.'], 405);
    $body = ogmPpReadBody();
    if (!is_array($body)) ogmPpJson(['ok' => false, 'error' => 'Invalid JSON.'], 400);

    $taskId = ogmPpSafeId($body['taskId'] ?? '', 120);
    $quoteNumber = ogmPpSafeId($body['quoteNumber'] ?? '', 120);
    $proposalId = ogmPpSafeId($body['proposalId'] ?? '', 120);
    $base = null;
    if ($proposalId !== '') $base = ogmPpReadJson($proposalDir . DIRECTORY_SEPARATOR . $proposalId . '.json', null);
    if (!is_array($base)) $base = ogmPpLatest($proposalDir, $taskId, $quoteNumber);

    $version = is_array($base) ? ((int) ($base['version'] ?? 0) + 1) : 1;
    $id = 'pp-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $proposalHtml = (string) ($body['proposalHtml'] ?? ($base['proposalHtml'] ?? ''));
    if (trim(strip_tags($proposalHtml)) === '' && trim($proposalHtml) === '') {
        ogmPpJson(['ok' => false, 'error' => 'proposalHtml is required.'], 400);
    }

    $now = gmdate('c');
    $currentUser = qtCurrentUser();
    $viewerUrl = trim((string) ($body['viewerUrl'] ?? ($base['viewerUrl'] ?? '')));
    // Customer-facing proposal links intentionally rotate with each saved version.
    // Internal proposal records stay permanent; only public access expires.
    $publicToken = bin2hex(random_bytes(16));
    $publicTtlDays = 90;
    $publicExpiresAt = ogmPpPublicExpiresAt($publicTtlDays);
    $printOptions = ogmPpNormalizePrintOptions($body['printOptions'] ?? null, $base);
    $roomViews = ogmPpBuildRoomViews($viewerUrl, $body['roomViews'] ?? ($base['roomViews'] ?? []));
    $pkg = [
        'id' => $id,
        'version' => $version,
        'createdAt' => $now,
        'createdBy' => $currentUser,
        'updatedAt' => $now,
        'updatedBy' => $currentUser,
        'source' => substr(trim((string) ($body['source'] ?? $action)), 0, 80),
        'productLine' => substr(trim((string) ($body['productLine'] ?? ($base['productLine'] ?? ''))), 0, 40),
        'quoteNumber' => $quoteNumber !== '' ? $quoteNumber : (string) ($base['quoteNumber'] ?? ''),
        'taskId' => $taskId !== '' ? $taskId : (string) ($base['taskId'] ?? ''),
        'customerId' => ogmPpSafeId($body['customerId'] ?? ($base['customerId'] ?? ''), 120),
        'customerName' => substr(trim((string) ($body['customerName'] ?? ($base['customerName'] ?? ''))), 0, 180),
        'jobName' => substr(trim((string) ($body['jobName'] ?? ($base['jobName'] ?? ''))), 0, 180),
        'quoteAmount' => is_numeric($body['quoteAmount'] ?? null) ? round((float) $body['quoteAmount'], 2) : ($base['quoteAmount'] ?? null),
        'proposalHtml' => $proposalHtml,
        'viewerUrl' => $viewerUrl,
        'printOptions' => $printOptions,
        'roomViews' => $roomViews,
        'publicToken' => $publicToken,
        'publicTtlDays' => $publicTtlDays,
        'publicExpiresAt' => $publicExpiresAt,
        'publicViewUrl' => ogmPpPublicViewUrl($publicToken),
        'includedDocuments' => ogmPpNormalizeDocs($body['includedDocuments'] ?? []),
        'previousProposalId' => is_array($base) ? (string) ($base['id'] ?? '') : '',
    ];
    $pkg['viewUrl'] = ogmPpViewUrl($id);

    if (!ogmPpWriteJson($proposalDir . DIRECTORY_SEPARATOR . $id . '.json', $pkg)) {
        ogmPpJson(['ok' => false, 'error' => 'Could not save project proposal.'], 500);
    }

    if ($pkg['taskId'] !== '') {
        $selected = array_map(static function ($doc) { return $doc['key']; }, $pkg['includedDocuments']);
        ogmPpWriteJson(ogmPpSelectionPath($selectionDir, $pkg['taskId']), [
            'taskId' => $pkg['taskId'],
            'selectedKeys' => array_values(array_unique($selected)),
            'updatedAt' => gmdate('c'),
            'updatedBy' => qtCurrentUser(),
        ]);
    }

    ogmPpJson(['ok' => true, 'proposal' => $pkg]);
}

if ($action === 'link-task') {
    if ($method !== 'POST') ogmPpJson(['ok' => false, 'error' => 'Method not allowed.'], 405);
    $body = ogmPpReadBody();
    if (!is_array($body)) ogmPpJson(['ok' => false, 'error' => 'Invalid JSON.'], 400);
    $proposalId = ogmPpSafeId($body['proposalId'] ?? '', 120);
    $taskId = ogmPpSafeId($body['taskId'] ?? '', 120);
    if ($proposalId === '' || $taskId === '') ogmPpJson(['ok' => false, 'error' => 'proposalId and taskId are required.'], 400);
    $path = $proposalDir . DIRECTORY_SEPARATOR . $proposalId . '.json';
    $pkg = ogmPpReadJson($path, null);
    if (!is_array($pkg)) ogmPpJson(['ok' => false, 'error' => 'Project proposal not found.'], 404);
    $pkg['taskId'] = $taskId;
    $pkg['linkedAt'] = gmdate('c');
    $pkg['linkedBy'] = qtCurrentUser();
    $pkg['updatedAt'] = $pkg['linkedAt'];
    $pkg['updatedBy'] = $pkg['linkedBy'];
    $pkg['viewUrl'] = ogmPpViewUrl($proposalId);
    if (!ogmPpWriteJson($path, $pkg)) ogmPpJson(['ok' => false, 'error' => 'Could not link project proposal to task.'], 500);
    ogmPpJson(['ok' => true, 'proposal' => $pkg]);
}

ogmPpJson(['ok' => false, 'error' => 'Unknown action.'], 404);
