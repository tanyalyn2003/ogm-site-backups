<?php
/**
 * customers-api-new-actions.php
 * ─────────────────────────────────────────────────────────────────────────────
 * DROP-IN ADDITIONS for customers-api.php
 *
 * Paste each block into customers-api.php BEFORE the final catch-all
 * "Unknown action" response at the bottom of that file.
 *
 * New actions added:
 *  • link-clickup-task   — appends a ClickUp task ID to a customer record
 *  • get-customer-jobs   — returns a customer's linked ClickUp task IDs
 *  • add-note            — appends a timestamped note to a customer record
 *                          (replaces the localStorage-only notes in Job Tracking)
 *  • search-by-phone     — finds a customer by phone number (for duplicate detection)
 *  • search-by-name      — finds customers by name / contact fields (quoter autocomplete)
 * ─────────────────────────────────────────────────────────────────────────────
 */

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

    // Build / update clickupJobs array
    if (!isset($data['clickupJobs']) || !is_array($data['clickupJobs'])) {
        $data['clickupJobs'] = [];
    }

    // De-duplicate by taskId
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
        // Not found — return empty rather than 404 so callers don't need try/catch
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

    // Save to customer record if we have a customerId
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

    // Also save to a per-task notes file so Job Tracking can load without knowing customerId
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
            // CustomerDB stores firstName + lastName; fall back to top-level name for legacy records.
            $displayName = trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''));
            if ($displayName === '') $displayName = (string) ($data['name'] ?? '');

            // Return a safe subset — no need to expose full record
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
