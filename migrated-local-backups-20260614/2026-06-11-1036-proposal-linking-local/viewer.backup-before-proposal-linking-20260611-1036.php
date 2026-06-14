<?php

/**
 * Public 3D/2D viewer — no quoter login session required.
 *
 * Access is granted only when:
 * - `token` matches a saved share file under /shared/, or
 * - `localPreviewKey` is present (snapshot is read client-side from localStorage).
 *
 * Do not require qtIsLoggedIn() or require auth.php here: cold visitors opening
 * a share link have no session; loading auth.php is unnecessary for this page.
 */
header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: private, no-store, max-age=0', true);

$localPreviewKey = trim((string)($_GET['localPreviewKey'] ?? ''));
$localPreviewMode = $localPreviewKey !== '';
$embeddedMode = (string)($_GET['embedded'] ?? '') === '1';
$token = trim((string)($_GET['token'] ?? ''));
$encoded = 'null';
if (!$localPreviewMode) {
  if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
  }

  $path = __DIR__ . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . $token . '.json';
  if (!is_file($path)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
  }

  $raw = file_get_contents($path);
  if ($raw === false) {
    http_response_code(500);
    echo 'Could not load viewer data.';
    exit;
  }

  $payload = json_decode($raw, true);
  $snapshot = is_array($payload) ? ($payload['snapshot'] ?? null) : null;
  if (!is_array($snapshot)) {
    http_response_code(500);
    echo 'Invalid viewer data.';
    exit;
  }

  $encoded = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($encoded === false) {
    http_response_code(500);
    echo 'Could not encode viewer data.';
    exit;
  }
}

$localPreviewKeyEncoded = json_encode($localPreviewKey, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Countertop Viewer</title>
  <style>
    :root{--bg:#0b1222;--panel:#0f172a;--txt:#e5e7eb;--muted:#94a3b8;--line:#1e293b;--btn:#111827;--btn2:#0b1222;--accent:#f59e0b;}
    body{margin:0;background:var(--bg);color:var(--txt);font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;}
    .wrap{min-height:100vh;display:flex;flex-direction:column;}
    .top{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-bottom:1px solid var(--line);background:rgba(15,23,42,.9);position:sticky;top:0;z-index:10;backdrop-filter:blur(8px)}
    .title{font-weight:700;font-size:14px;letter-spacing:.02em}
    .tabs{display:flex;gap:8px;align-items:center}
    .tab{border:1px solid var(--line);background:var(--btn2);color:var(--txt);padding:8px 10px;border-radius:10px;font-size:12px;cursor:pointer}
    .tab.active{border-color:rgba(245,158,11,.55);box-shadow:0 0 0 2px rgba(245,158,11,.15) inset}
    .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
    .btn{border:1px solid var(--line);background:var(--btn);color:var(--txt);padding:8px 10px;border-radius:10px;font-size:12px;cursor:pointer}
    .btn.secondary{background:var(--btn2)}
    .main{flex:1;display:grid;grid-template-rows:1fr}
    .pane{display:none;height:calc(100vh - 54px)}
    .pane.active{display:block}
    .twoD{padding:14px;overflow:auto}
    .stone{border:1px solid var(--line);background:var(--panel);border-radius:12px;padding:12px;margin:0 0 12px}
    .stoneHead{display:flex;justify-content:space-between;gap:10px;align-items:flex-end;margin:0 0 10px}
    .stoneName{font-weight:700;font-size:13px}
    .stoneMeta{color:var(--muted);font-size:11px}
    canvas{background:#0b1222;border:1px solid #334155;border-radius:10px;display:block;max-width:100%}
    #threeWrap{height:calc(100vh - 54px);position:relative;}
    #three{position:absolute;inset:0;width:100%;height:100%;display:block}
    .hint{position:absolute;left:12px;bottom:12px;background:rgba(2,6,23,.72);border:1px solid rgba(148,163,184,.25);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--txt);max-width:min(520px,calc(100% - 24px))}
    .hint .muted{color:var(--muted)}
    body.embedded .top{display:none}
    body.embedded .pane{height:100vh}
    body.embedded #threeWrap{height:100vh}
    body.embedded .twoD{padding:0;background:transparent}
    body.embedded .stone{margin:0;border-radius:0;border-color:#1e293b}
    body.embedded .hint{display:none}
  </style>
</head>
<body class="<?php echo $embeddedMode ? 'embedded' : ''; ?>">
  <div class="wrap">
    <div class="top">
      <div class="title">Countertop Viewer</div>
      <div class="tabs">
        <button class="tab active" type="button" data-tab="3d">3D</button>
        <button class="tab" type="button" data-tab="2d">2D</button>
      </div>
      <div class="actions">
        <button class="btn secondary" type="button" id="btn-theme">Theme</button>
        <button class="btn secondary" type="button" id="btn-reset">Reset View</button>
        <button class="btn secondary" type="button" id="btn-shot">Snapshot</button>
        <button class="btn" type="button" id="btn-full">Fullscreen</button>
      </div>
    </div>

    <div class="main">
      <div class="pane active" id="pane-3d">
        <div id="threeWrap">
          <canvas id="three"></canvas>
          <div class="hint"><div><b>Mouse</b>: rotate • <b>Right</b>-drag: pan • wheel: zoom • hold <b>Space</b> + drag: pan</div><div class="muted">This link is view-only (2D + 3D).</div></div>
        </div>
      </div>
      <div class="pane" id="pane-2d">
        <div class="twoD" id="twoD"></div>
      </div>
    </div>
  </div>

  <script>
    window.__OGM_VIEWER_SNAPSHOT__ = <?php echo $encoded; ?>;
    window.__OGM_VIEWER_LOCAL_KEY__ = <?php echo $localPreviewKeyEncoded ?: '""'; ?>;
  </script>
  <script type="module" src="client-viewer.js?v=<?php echo (int)@filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'client-viewer.js'); ?>"></script>
</body>
</html>
