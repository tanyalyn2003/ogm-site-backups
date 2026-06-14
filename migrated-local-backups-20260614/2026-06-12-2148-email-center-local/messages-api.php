<?php

/**
 * Internal Message Center — callback requests assigned to staff.
 * Storage: messages/{id}.json + customer.messageLog[] mirror.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

header('Content-Type: application/json; charset=UTF-8');

if (!qtIsLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    exit;
}

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$messagesDir = __DIR__ . DIRECTORY_SEPARATOR . 'messages';
$customersDir = __DIR__ . DIRECTORY_SEPARATOR . 'customers';

if (!is_dir($messagesDir)) {
    @mkdir($messagesDir, 0755, true);
}

function messagesApiSanitizeId($raw) {
    $s = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $raw);
    if ($s === null || $s === '') {
        return null;
    }
    return substr($s, 0, 80);
}

function messagesApiCustomerPath($dir, $id) {
    return $dir . DIRECTORY_SEPARATOR . $id . '.json';
}

function messagesApiMessagePath($dir, $id) {
    return $dir . DIRECTORY_SEPARATOR . $id . '.json';
}

function messagesApiCleanText($raw, $maxLen = 4000) {
    $t = trim((string) $raw);
    if ($t === '') {
        return '';
    }
    $t = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (strlen($t) > $maxLen) {
        $t = substr($t, 0, $maxLen);
    }
    return $t;
}

function messagesApiStaffAllowed($name) {
    $name = trim((string) $name);
    foreach (qtStaffRoster() as $allowed) {
        if ($name === $allowed) {
            return true;
        }
    }
    return false;
}

function messagesApiLoadCustomer($customersDir, $customerId) {
    $path = messagesApiCustomerPath($customersDir, $customerId);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function messagesApiSaveCustomer($customersDir, array $data) {
    $id = messagesApiSanitizeId($data['id'] ?? '');
    if (!$id) {
        return false;
    }
    $data['updatedAt'] = gmdate('c');
    return @file_put_contents(
        messagesApiCustomerPath($customersDir, $id),
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    ) !== false;
}

function messagesApiCustomerDisplayName(array $customer) {
    $name = trim(($customer['firstName'] ?? '') . ' ' . ($customer['lastName'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($customer['name'] ?? ''));
    }
    return $name !== '' ? $name : 'Customer';
}

function messagesApiSyncCustomerLogEntry($customersDir, array $message) {
    $customerId = messagesApiSanitizeId($message['customerId'] ?? '');
    if (!$customerId) {
        return;
    }
    $customer = messagesApiLoadCustomer($customersDir, $customerId);
    if (!$customer) {
        return;
    }
    if (!isset($customer['messageLog']) || !is_array($customer['messageLog'])) {
        $customer['messageLog'] = [];
    }
    $msgId = (string) ($message['id'] ?? '');
    $found = false;
    foreach ($customer['messageLog'] as $i => $entry) {
        if (is_array($entry) && ($entry['messageId'] ?? '') === $msgId) {
            $customer['messageLog'][$i] = messagesApiLogEntryFromMessage($message);
            $found = true;
            break;
        }
    }
    if (!$found) {
        $customer['messageLog'][] = messagesApiLogEntryFromMessage($message);
    }
    messagesApiSaveCustomer($customersDir, $customer);
}

function messagesApiLogEntryFromMessage(array $message) {
    return [
        'messageId'   => (string) ($message['id'] ?? ''),
        'type'        => (string) ($message['type'] ?? 'callback'),
        'status'      => (string) ($message['status'] ?? 'open'),
        'reason'      => (string) ($message['reason'] ?? ''),
        'assignedTo'  => (string) ($message['assignedTo'] ?? ''),
        'takenBy'     => (string) ($message['takenBy'] ?? ''),
        'createdAt'   => (string) ($message['createdAt'] ?? ''),
        'completedAt' => $message['completedAt'] ?? null,
        'completedBy' => $message['completedBy'] ?? null,
        'resolution'  => $message['resolution'] ?? null,
    ];
}

function messagesApiLoadMessage($messagesDir, $id) {
    $path = messagesApiMessagePath($messagesDir, $id);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function messagesApiSaveMessage($messagesDir, array $message) {
    $id = messagesApiSanitizeId($message['id'] ?? '');
    if (!$id) {
        return false;
    }
    return @file_put_contents(
        messagesApiMessagePath($messagesDir, $id),
        json_encode($message, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    ) !== false;
}

function messagesApiListAllMessages($messagesDir) {
    $out = [];
    if (!is_dir($messagesDir)) {
        return $out;
    }
    foreach (glob($messagesDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $data = json_decode((string) file_get_contents($file), true);
        if (is_array($data) && !empty($data['id'])) {
            $out[] = $data;
        }
    }
    usort($out, static function ($a, $b) {
        return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
    });
    return $out;
}

function messagesApiCurrentUsername() {
    return qtNormalizeUsername((string) ($_SESSION['qt_username'] ?? ''));
}

function messagesApiAlertEnabledForUsername($username) {
    $username = qtNormalizeUsername($username);
    if ($username === '') {
        return false;
    }
    $users = qtReadUsers();
    return isset($users[$username])
        && !empty($users[$username]['active'])
        && !empty($users[$username]['message_alerts']);
}

function messagesApiTextMentionsUsername($text, $username) {
    $username = qtNormalizeUsername($username);
    if ($username === '') {
        return false;
    }
    $decoded = html_entity_decode((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $pattern = '/(^|[^A-Za-z0-9._-])@?' . preg_quote($username, '/') . '([^A-Za-z0-9._-]|$)/i';
    return (bool) preg_match($pattern, $decoded);
}

function messagesApiTargetsCurrentUser(array $message) {
    $username = messagesApiCurrentUsername();
    if ($username === '') {
        return false;
    }
    $assignedTo = strtolower(trim((string) ($message['assignedTo'] ?? '')));
    $displayName = strtolower(trim((string) qtCurrentUser()));

    if ($assignedTo !== '' && ($assignedTo === strtolower($username) || ($displayName !== '' && $assignedTo === $displayName))) {
        return true;
    }

    return messagesApiTextMentionsUsername($message['reason'] ?? '', $username);
}

function messagesApiNewId() {
    return 'msg-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
}

// ── whoami ───────────────────────────────────────────────────────────────────
if ($action === 'whoami') {
    echo json_encode([
        'ok' => true,
        'displayName' => qtCurrentUser(),
        'staff' => qtStaffRoster(),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ── alert-status ─────────────────────────────────────────────────────────────
if ($action === 'alert-status') {
    $username = messagesApiCurrentUsername();
    $enabled = messagesApiAlertEnabledForUsername($username);
    $unreadCount = 0;
    $latestId = '';
    $latestCreatedAt = '';

    if ($enabled) {
        foreach (messagesApiListAllMessages($messagesDir) as $message) {
            if (($message['status'] ?? '') !== 'open') {
                continue;
            }
            if (!messagesApiTargetsCurrentUser($message)) {
                continue;
            }
            $unreadCount++;
            $createdAt = (string) ($message['createdAt'] ?? '');
            if ($latestId === '' || strcmp($createdAt, $latestCreatedAt) > 0) {
                $latestId = (string) ($message['id'] ?? '');
                $latestCreatedAt = $createdAt;
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'enabled' => $enabled,
        'username' => $username,
        'unread_count' => $unreadCount,
        'latest_id' => $latestId,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ── create-message ───────────────────────────────────────────────────────────
if ($action === 'create-message') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON.']);
        exit;
    }

    $customerId = messagesApiSanitizeId($body['customerId'] ?? '');
    $reason = messagesApiCleanText($body['reason'] ?? '', 2000);
    $assignedTo = trim((string) ($body['assignedTo'] ?? ''));
    $takenBy = trim((string) ($body['takenBy'] ?? qtCurrentUser()));

    if (!$customerId || $reason === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'customerId and reason are required.']);
        exit;
    }
    if (!messagesApiStaffAllowed($assignedTo)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Select a valid team member for this message.']);
        exit;
    }
    if ($takenBy === '') {
        $takenBy = qtCurrentUser();
    }

    $customer = messagesApiLoadCustomer($customersDir, $customerId);
    if (!$customer) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Customer not found.']);
        exit;
    }

    $message = [
        'id'            => messagesApiNewId(),
        'type'          => 'callback',
        'status'        => 'open',
        'customerId'    => $customerId,
        'customerName'  => messagesApiCustomerDisplayName($customer),
        'customerPhone' => trim((string) ($customer['phone'] ?? '')),
        'reason'        => $reason,
        'assignedTo'    => $assignedTo,
        'takenBy'       => messagesApiCleanText($takenBy, 120),
        'createdAt'     => gmdate('c'),
        'completedAt'   => null,
        'completedBy'   => null,
        'resolution'    => null,
    ];

    if (!messagesApiSaveMessage($messagesDir, $message)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not save message.']);
        exit;
    }
    messagesApiSyncCustomerLogEntry($customersDir, $message);

    echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

// ── complete-message ─────────────────────────────────────────────────────────
if ($action === 'complete-message') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON.']);
        exit;
    }

    $messageId = messagesApiSanitizeId($body['messageId'] ?? $body['id'] ?? '');
    $resolution = messagesApiCleanText($body['resolution'] ?? '', 4000);
    $completedBy = trim((string) ($body['completedBy'] ?? qtCurrentUser()));

    if (!$messageId || $resolution === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'messageId and resolution are required.']);
        exit;
    }
    if ($completedBy === '') {
        $completedBy = qtCurrentUser();
    }

    $message = messagesApiLoadMessage($messagesDir, $messageId);
    if (!$message) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Message not found.']);
        exit;
    }

    $message['status'] = 'completed';
    $message['resolution'] = $resolution;
    $message['completedBy'] = messagesApiCleanText($completedBy, 120);
    $message['completedAt'] = gmdate('c');

    if (!messagesApiSaveMessage($messagesDir, $message)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not update message.']);
        exit;
    }
    messagesApiSyncCustomerLogEntry($customersDir, $message);

    echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

// ── list-messages ────────────────────────────────────────────────────────────
if ($action === 'list-messages') {
    $status = strtolower(trim((string) ($_GET['status'] ?? 'open')));
    $assignedTo = trim((string) ($_GET['assignedTo'] ?? ''));
    $customerId = messagesApiSanitizeId($_GET['customerId'] ?? '');

    $all = messagesApiListAllMessages($messagesDir);
    $filtered = [];
    foreach ($all as $m) {
        if ($status !== '' && $status !== 'all' && ($m['status'] ?? '') !== $status) {
            continue;
        }
        if ($assignedTo !== '' && ($m['assignedTo'] ?? '') !== $assignedTo) {
            continue;
        }
        if ($customerId && ($m['customerId'] ?? '') !== $customerId) {
            continue;
        }
        $filtered[] = $m;
    }

    $openCount = 0;
    foreach ($all as $m) {
        if (($m['status'] ?? '') === 'open') {
            $openCount++;
        }
    }

    echo json_encode([
        'ok' => true,
        'messages' => $filtered,
        'openCount' => $openCount,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ── get-customer-messages ────────────────────────────────────────────────────
if ($action === 'get-customer-messages') {
    $customerId = messagesApiSanitizeId($_GET['customerId'] ?? '');
    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'customerId required.']);
        exit;
    }
    $all = messagesApiListAllMessages($messagesDir);
    $filtered = array_values(array_filter($all, static function ($m) use ($customerId) {
        return ($m['customerId'] ?? '') === $customerId;
    }));
    echo json_encode(['ok' => true, 'messages' => $filtered], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
