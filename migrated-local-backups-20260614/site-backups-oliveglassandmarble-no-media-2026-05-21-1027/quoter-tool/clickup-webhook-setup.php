<?php

/**
 * ClickUp Webhook Admin — register / list / delete / test the intake-list
 * webhook for the OGM workspace. Login required (same auth.php pattern as
 * clickup-api-key.php).
 *
 * UI is a single self-contained page. All write actions go to this same file
 * via POST { action } using a session-bound CSRF token.
 *
 * Stores the webhook secret returned by ClickUp in
 * .data/clickup-webhook-secret.json so clickup-webhook.php can verify
 * incoming HMAC signatures.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

const CWS_CLICKUP_BASE   = 'https://api.clickup.com/api/v2';
const CWS_INTAKE_LIST_ID = '901710745952';
const CWS_WORKSPACE_ID   = '9017868498';
const CWS_TRACKED_EVENTS = ['taskCreated'];

$DATA_DIR     = __DIR__ . DIRECTORY_SEPARATOR . '.data';
$API_KEY_FILE = $DATA_DIR . DIRECTORY_SEPARATOR . 'clickup-api-key.json';
$SECRET_FILE  = $DATA_DIR . DIRECTORY_SEPARATOR . 'clickup-webhook-secret.json';

/* ─── Login wall ──────────────────────────────────────────────────────────── */
if (!qtIsLoggedIn()) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }
    $self = (string) ($_SERVER['SCRIPT_NAME'] ?? 'clickup-webhook-setup.php');
    header('Location: index.php?next=' . urlencode($self));
    exit;
}

/* ─── CSRF token ──────────────────────────────────────────────────────────── */
if (empty($_SESSION['cws_csrf'])) {
    $_SESSION['cws_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['cws_csrf'];

/* ─── Helpers ─────────────────────────────────────────────────────────────── */
function cwsLoadApiKey(string $file): string {
    if (!is_file($file)) {
        return '';
    }
    $j = json_decode((string) @file_get_contents($file), true);
    return is_array($j) && isset($j['apiKey']) && is_string($j['apiKey']) ? trim($j['apiKey']) : '';
}

function cwsLoadSecret(string $file): string {
    if (!is_file($file)) {
        return '';
    }
    $j = json_decode((string) @file_get_contents($file), true);
    return is_array($j) && isset($j['secret']) && is_string($j['secret']) ? trim($j['secret']) : '';
}

function cwsSaveSecret(string $file, string $secret, ?string $webhookId = null): bool {
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $payload = ['secret' => $secret, 'updatedAt' => gmdate('c')];
    if ($webhookId !== null) {
        $payload['webhookId'] = $webhookId;
    }
    return @file_put_contents(
        $file,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    ) !== false;
}

function cwsClearSecret(string $file): bool {
    if (!is_file($file)) {
        return true;
    }
    return @unlink($file);
}

function cwsClickUp(string $method, string $endpoint, string $apiKey, $body = null): array {
    $ch = curl_init(CWS_CLICKUP_BASE . $endpoint);
    $headers = ['Authorization: ' . $apiKey, 'Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES));
    }
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return ['ok' => false, 'status' => 0, 'error' => $err, 'body' => null];
    }
    $decoded = json_decode((string) $resp, true);
    return [
        'ok'     => $code >= 200 && $code < 300,
        'status' => $code,
        'error'  => $code >= 400 ? (is_array($decoded) ? ($decoded['err'] ?? $decoded['error'] ?? '') : (string) $resp) : '',
        'body'   => is_array($decoded) ? $decoded : null,
        'raw'    => is_array($decoded) ? null : $resp,
    ];
}

function cwsCurrentWebhookUrl(): string {
    $proto = qtIsHttpsRequest() ? 'https' : 'http';
    $host  = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $self  = (string) ($_SERVER['SCRIPT_NAME'] ?? '/clickup-webhook-setup.php');
    $self  = str_replace('\\', '/', $self);
    $dir   = rtrim(dirname($self), '/');
    if ($dir === '.' || $dir === '') {
        $dir = '';
    }
    return $proto . '://' . $host . $dir . '/clickup-webhook.php';
}

function cwsJsonReply(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/* ─── POST router (JSON in / JSON out) ────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw  = (string) file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        cwsJsonReply(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
    }
    $token = (string) ($body['_csrf'] ?? '');
    if ($token === '' || !hash_equals($csrf, $token)) {
        cwsJsonReply(['ok' => false, 'error' => 'Invalid CSRF token. Reload the page.'], 403);
    }
    $action = strtolower(trim((string) ($body['action'] ?? '')));

    $apiKey = cwsLoadApiKey($API_KEY_FILE);
    if ($apiKey === '' && $action !== 'config') {
        cwsJsonReply([
            'ok' => false,
            'error' => 'ClickUp API key is not saved yet. Open Job Tracking → Settings → save the workspace API key first.',
        ], 400);
    }

    if ($action === 'config') {
        cwsJsonReply([
            'ok'             => true,
            'apiKeyPresent'  => $apiKey !== '',
            'secretPresent'  => cwsLoadSecret($SECRET_FILE) !== '',
            'webhookUrl'     => cwsCurrentWebhookUrl(),
            'workspaceId'    => CWS_WORKSPACE_ID,
            'intakeListId'   => CWS_INTAKE_LIST_ID,
            'events'         => CWS_TRACKED_EVENTS,
        ]);
    }

    if ($action === 'list') {
        $res = cwsClickUp('GET', '/team/' . CWS_WORKSPACE_ID . '/webhook', $apiKey);
        if (!$res['ok']) {
            cwsJsonReply([
                'ok' => false,
                'error' => 'ClickUp list webhooks failed.',
                'status' => $res['status'],
                'detail' => $res['error'] ?? '',
            ], 502);
        }
        $hooks = isset($res['body']['webhooks']) && is_array($res['body']['webhooks'])
            ? $res['body']['webhooks']
            : [];
        $expectedUrl = cwsCurrentWebhookUrl();
        $mine = [];
        foreach ($hooks as $h) {
            if (!is_array($h)) continue;
            $endpoint = (string) ($h['endpoint'] ?? '');
            $mine[] = [
                'id'         => (string) ($h['id'] ?? ''),
                'endpoint'   => $endpoint,
                'events'     => $h['events'] ?? [],
                'list_id'    => $h['list_id'] ?? null,
                'health'     => $h['health'] ?? null,
                'task_id'    => $h['task_id'] ?? null,
                'matchesUrl' => $endpoint === $expectedUrl,
            ];
        }
        cwsJsonReply(['ok' => true, 'webhooks' => $mine, 'expectedUrl' => $expectedUrl]);
    }

    if ($action === 'register') {
        $endpoint = cwsCurrentWebhookUrl();
        $payload  = [
            'endpoint' => $endpoint,
            'events'   => CWS_TRACKED_EVENTS,
            'list_id'  => CWS_INTAKE_LIST_ID,
        ];
        $res = cwsClickUp('POST', '/team/' . CWS_WORKSPACE_ID . '/webhook', $apiKey, $payload);
        if (!$res['ok'] || !is_array($res['body'])) {
            cwsJsonReply([
                'ok' => false,
                'error' => 'ClickUp register webhook failed.',
                'status' => $res['status'],
                'detail' => $res['error'] ?? '',
            ], 502);
        }
        $hook   = $res['body']['webhook'] ?? $res['body'];
        $hookId = is_array($hook) ? (string) ($hook['id'] ?? '') : '';
        $secret = is_array($hook) && isset($hook['secret']) ? (string) $hook['secret'] : '';
        if ($secret === '') {
            cwsJsonReply([
                'ok' => false,
                'error' => 'ClickUp returned no secret. Webhook may have been created — check the list and delete it manually.',
                'apiResponse' => $res['body'],
            ], 502);
        }
        if (!cwsSaveSecret($SECRET_FILE, $secret, $hookId !== '' ? $hookId : null)) {
            cwsJsonReply([
                'ok' => false,
                'error' => 'Webhook created at ClickUp but secret could not be saved locally. Delete the webhook in ClickUp and retry.',
                'webhookId' => $hookId,
            ], 500);
        }
        cwsJsonReply([
            'ok'        => true,
            'webhookId' => $hookId,
            'endpoint'  => $endpoint,
            'events'    => CWS_TRACKED_EVENTS,
        ]);
    }

    if ($action === 'delete') {
        $hookId = trim((string) ($body['webhookId'] ?? ''));
        if ($hookId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $hookId)) {
            cwsJsonReply(['ok' => false, 'error' => 'Missing or invalid webhookId.'], 400);
        }
        $res = cwsClickUp('DELETE', '/webhook/' . rawurlencode($hookId), $apiKey);
        if (!$res['ok']) {
            cwsJsonReply([
                'ok' => false,
                'error' => 'ClickUp delete webhook failed.',
                'status' => $res['status'],
                'detail' => $res['error'] ?? '',
            ], 502);
        }
        /* If we deleted the webhook whose secret we have stored, drop the
         * stored secret so the endpoint stops accepting old signed events. */
        cwsClearSecret($SECRET_FILE);
        cwsJsonReply(['ok' => true, 'webhookId' => $hookId]);
    }

    if ($action === 'test') {
        $secret = cwsLoadSecret($SECRET_FILE);
        if ($secret === '') {
            cwsJsonReply([
                'ok' => false,
                'error' => 'No webhook secret stored. Register the webhook first.',
            ], 400);
        }
        $endpoint = cwsCurrentWebhookUrl();
        $payload  = [
            'event'      => 'webhook.test',
            'webhook_id' => 'local-test',
            'task_id'    => 'local-test-' . bin2hex(random_bytes(4)),
            'note'       => 'Synthetic payload from clickup-webhook-setup.php Test button.',
        ];
        $rawBody  = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig      = hash_hmac('sha256', $rawBody, $secret);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Signature: ' . $sig,
                'User-Agent: clickup-webhook-setup-test',
            ],
            CURLOPT_POSTFIELDS     => $rawBody,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        cwsJsonReply([
            'ok'         => $resp !== false && $code >= 200 && $code < 300,
            'endpoint'   => $endpoint,
            'httpStatus' => $code,
            'curlError'  => $err,
            'response'   => $resp === false ? null : (json_decode((string) $resp, true) ?? $resp),
        ]);
    }

    cwsJsonReply(['ok' => false, 'error' => 'Unknown action.'], 400);
}

/* ─── HTML view ───────────────────────────────────────────────────────────── */
$webhookUrl = cwsCurrentWebhookUrl();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ClickUp Webhook · OGM Quoter</title>
<style>
  :root { --gold:#c9a14a; --bg:#0c0c0e; --panel:#15151a; --panel2:#1c1c22; --border:#2a2a33; --text:#e8e8ec; --muted:#9b9ba6; --green:#22c55e; --red:#ef4444; }
  * { box-sizing: border-box; }
  html, body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
  .wrap { max-width: 880px; margin: 0 auto; padding: 32px 20px 80px; }
  h1 { margin: 0 0 4px; font-size: 22px; letter-spacing: .3px; }
  .sub { color: var(--muted); margin-bottom: 24px; font-size: 13px; }
  .card { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; }
  .card h2 { margin: 0 0 12px; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; color: var(--gold); }
  .row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .kv { display: grid; grid-template-columns: 160px 1fr; gap: 6px 14px; font-size: 13px; }
  .kv .k { color: var(--muted); }
  .kv code { background: var(--panel2); padding: 2px 6px; border-radius: 4px; word-break: break-all; }
  .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; border: 1px solid var(--border); }
  .pill.ok { background: rgba(34,197,94,.12); border-color: rgba(34,197,94,.5); color: #6ee79a; }
  .pill.bad { background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.5); color: #ff8a8a; }
  button { background: var(--gold); color: #1a1300; border: 0; padding: 9px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px; }
  button.secondary { background: var(--panel2); color: var(--text); border: 1px solid var(--border); }
  button.danger { background: rgba(239,68,68,.18); color: #ff9b9b; border: 1px solid rgba(239,68,68,.5); }
  button:disabled { opacity: .5; cursor: not-allowed; }
  table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 13px; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
  th { color: var(--muted); font-weight: 500; font-size: 12px; text-transform: uppercase; letter-spacing: .8px; }
  td code { background: var(--panel2); padding: 1px 6px; border-radius: 4px; word-break: break-all; }
  pre { background: #0a0a0d; border: 1px solid var(--border); border-radius: 6px; padding: 12px; max-height: 280px; overflow: auto; font-size: 12px; }
  .muted { color: var(--muted); }
  a { color: var(--gold); }
  .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
  .topbar a { font-size: 13px; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <div>
        <h1>ClickUp Webhook</h1>
        <div class="sub">Auto-link CustomerDB records when a task lands in the Countertop intake list.</div>
      </div>
      <div><a href="hub.php">← Back to Hub</a></div>
    </div>

    <div class="card">
      <h2>Configuration</h2>
      <div class="kv" id="cfg-kv">
        <div class="k">Workspace</div><div><code><?= htmlspecialchars(CWS_WORKSPACE_ID, ENT_QUOTES) ?></code></div>
        <div class="k">Intake list</div><div><code><?= htmlspecialchars(CWS_INTAKE_LIST_ID, ENT_QUOTES) ?></code> (Countertop Form)</div>
        <div class="k">Events</div><div><code><?= htmlspecialchars(implode(', ', CWS_TRACKED_EVENTS), ENT_QUOTES) ?></code></div>
        <div class="k">Endpoint URL</div><div><code id="cfg-url"><?= htmlspecialchars($webhookUrl, ENT_QUOTES) ?></code></div>
        <div class="k">API key</div><div><span id="cfg-apikey" class="pill">checking…</span></div>
        <div class="k">Stored secret</div><div><span id="cfg-secret" class="pill">checking…</span></div>
      </div>
    </div>

    <div class="card">
      <h2>Registered webhooks (this workspace)</h2>
      <div class="row" style="margin-bottom: 12px;">
        <button id="btn-refresh" class="secondary">Refresh</button>
        <button id="btn-register">Register intake webhook</button>
        <button id="btn-test" class="secondary">Test endpoint</button>
        <span class="muted" id="hook-summary"></span>
      </div>
      <table id="hook-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Endpoint</th>
            <th>Events</th>
            <th>List</th>
            <th>Health</th>
            <th></th>
          </tr>
        </thead>
        <tbody><tr><td colspan="6" class="muted">Loading…</td></tr></tbody>
      </table>
    </div>

    <div class="card">
      <h2>Last action</h2>
      <pre id="log">(no action yet)</pre>
    </div>
  </div>

<script>
const CSRF = <?= json_encode($csrf) ?>;

async function api(action, extra) {
  const body = Object.assign({ action, _csrf: CSRF }, extra || {});
  const res = await fetch(window.location.pathname, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch (_) {}
  return { status: res.status, json, text };
}

function logResult(label, result) {
  const out = document.getElementById('log');
  const stamp = new Date().toISOString();
  out.textContent = `[${stamp}] ${label} (HTTP ${result.status})\n` +
    (result.json ? JSON.stringify(result.json, null, 2) : result.text);
}

async function refreshConfig() {
  const r = await api('config');
  if (!r.json || !r.json.ok) return;
  const c = r.json;
  document.getElementById('cfg-url').textContent = c.webhookUrl;
  document.getElementById('cfg-apikey').outerHTML =
    `<span id="cfg-apikey" class="pill ${c.apiKeyPresent ? 'ok' : 'bad'}">${c.apiKeyPresent ? 'present' : 'missing — save it in Job Tracking → Settings'}</span>`;
  document.getElementById('cfg-secret').outerHTML =
    `<span id="cfg-secret" class="pill ${c.secretPresent ? 'ok' : 'bad'}">${c.secretPresent ? 'stored' : 'not stored — register webhook'}</span>`;
}

function escapeHtml(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function refreshList() {
  const tbody = document.querySelector('#hook-table tbody');
  tbody.innerHTML = '<tr><td colspan="6" class="muted">Loading…</td></tr>';
  document.getElementById('hook-summary').textContent = '';
  const r = await api('list');
  logResult('list webhooks', r);
  if (!r.json || !r.json.ok) {
    tbody.innerHTML = `<tr><td colspan="6" class="bad">Could not list webhooks. See Last Action.</td></tr>`;
    return;
  }
  const expected = r.json.expectedUrl;
  const hooks = r.json.webhooks || [];
  if (!hooks.length) {
    tbody.innerHTML = `<tr><td colspan="6" class="muted">No webhooks registered for this workspace yet.</td></tr>`;
    document.getElementById('hook-summary').textContent = '0 webhooks';
    return;
  }
  document.getElementById('hook-summary').textContent =
    `${hooks.length} webhook${hooks.length === 1 ? '' : 's'} • expected endpoint: ${expected}`;
  tbody.innerHTML = hooks.map(h => {
    const events = Array.isArray(h.events) ? h.events.join(', ') : '';
    const health = h.health && typeof h.health === 'object' ? `${escapeHtml(h.health.status || '')}${h.health.fail_count != null ? ' (fails: ' + h.health.fail_count + ')' : ''}` : '';
    const matchPill = h.matchesUrl ? '<span class="pill ok">this server</span>' : '<span class="pill bad">other URL</span>';
    return `<tr>
      <td><code>${escapeHtml(h.id)}</code></td>
      <td><code>${escapeHtml(h.endpoint)}</code><br>${matchPill}</td>
      <td>${escapeHtml(events)}</td>
      <td>${escapeHtml(h.list_id || '')}</td>
      <td>${health}</td>
      <td><button class="danger" data-del="${escapeHtml(h.id)}">Delete</button></td>
    </tr>`;
  }).join('');

  tbody.querySelectorAll('button[data-del]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-del');
      if (!confirm(`Delete webhook ${id}?`)) return;
      btn.disabled = true;
      const r = await api('delete', { webhookId: id });
      logResult('delete webhook ' + id, r);
      await refreshConfig();
      await refreshList();
    });
  });
}

document.getElementById('btn-refresh').addEventListener('click', async () => {
  await refreshConfig();
  await refreshList();
});

document.getElementById('btn-register').addEventListener('click', async () => {
  if (!confirm('Register the intake-list webhook on ClickUp? This is safe to repeat — old webhooks must be deleted manually if you want only one.')) return;
  const btn = document.getElementById('btn-register');
  btn.disabled = true;
  try {
    const r = await api('register');
    logResult('register webhook', r);
    await refreshConfig();
    await refreshList();
  } finally {
    btn.disabled = false;
  }
});

document.getElementById('btn-test').addEventListener('click', async () => {
  const btn = document.getElementById('btn-test');
  btn.disabled = true;
  try {
    const r = await api('test');
    logResult('test webhook', r);
  } finally {
    btn.disabled = false;
  }
});

(async () => {
  await refreshConfig();
  await refreshList();
})();
</script>
</body>
</html>
