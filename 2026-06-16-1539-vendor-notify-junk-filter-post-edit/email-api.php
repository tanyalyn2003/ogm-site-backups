<?php
/**
 * OGM Email Center — Microsoft Graph API proxy.
 * Requires existing Quoter Tool login and email_center permission.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';
qtSendNoIndexHeaders();
header('X-Robots-Tag: noindex, nofollow, noarchive', true);
qtStartSession();
header('Content-Type: application/json; charset=utf-8');

function ogm_email_out(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  exit;
}
function ogm_email_bad(string $message, int $status = 400): void {
  ogm_email_out(['ok' => false, 'error' => $message], $status);
}

if (!qtIsLoggedIn()) ogm_email_bad('Sign in required.', 401);
if (!qtCan('email_center')) ogm_email_bad('Access denied.', 403);

if (!defined('OGM_EMAIL_CENTER')) define('OGM_EMAIL_CENTER', 1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'graph-config.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'email-ai-config.php';

$username = qtNormalizeUsername((string)($_SESSION['qt_username'] ?? ''));
if ($username === '') ogm_email_bad('Sign in required.', 401);

$action = (string)($_GET['action'] ?? '');
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!empty($_POST)) {
    $input = $_POST;
  } else {
    $input = json_decode((string)file_get_contents('php://input'), true) ?: [];
  }
}

function ogm_email_normalize_email_list($list): array {
  if (is_string($list)) {
    $trimmed = trim($list);
    $decoded = null;
    if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
      $decoded = json_decode($trimmed, true);
    }
    if (is_array($decoded)) {
      $list = $decoded;
    } else {
      $list = preg_split('/[;,]/', $list) ?: [];
    }
  }
  if (!is_array($list)) $list = [];
  $out = [];
  foreach ($list as $item) {
    $email = trim(is_array($item) ? (string)($item['email'] ?? $item['address'] ?? '') : (string)$item);
    if ($email !== '') $out[] = $email;
  }
  return $out;
}

function ogm_email_recipients_out($list): array {
  $out = [];
  foreach (ogm_email_normalize_email_list($list) as $email) {
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $out[] = ['emailAddress' => ['address' => $email]];
    }
  }
  return $out;
}

function ogm_email_norm_email(string $raw): string {
  return strtolower(trim($raw));
}
function ogm_email_digits(string $raw): string {
  return preg_replace('/\D+/', '', $raw);
}
function ogm_email_display_name(array $customer): string {
  $name = trim((string)($customer['displayName'] ?? $customer['name'] ?? ''));
  if ($name !== '') return $name;
  $parts = array_filter([trim((string)($customer['firstName'] ?? '')), trim((string)($customer['lastName'] ?? ''))]);
  return $parts ? implode(' ', $parts) : trim((string)($customer['company'] ?? 'Customer'));
}
function ogm_email_array_contains_value($value, callable $predicate): bool {
  if (is_array($value)) {
    foreach ($value as $v) {
      if (ogm_email_array_contains_value($v, $predicate)) return true;
    }
    return false;
  }
  return $predicate((string)$value);
}
function ogm_email_message_context(array $message): array {
  $emails = [];
  $phones = [];
  $collectEmail = static function ($raw) use (&$emails): void {
    $raw = (string)$raw;
    if ($raw === '') return;
    if (filter_var($raw, FILTER_VALIDATE_EMAIL)) $emails[ogm_email_norm_email($raw)] = true;
    if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $m)) {
      foreach ($m[0] as $email) $emails[ogm_email_norm_email($email)] = true;
    }
  };
  $collectPhone = static function ($raw) use (&$phones): void {
    if (preg_match_all('/(?:\+?1[\s.\-]?)?(?:\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4})/', (string)$raw, $m)) {
      foreach ($m[0] as $phone) {
        $digits = ogm_email_digits($phone);
        if (strlen($digits) >= 10) $phones[substr($digits, -10)] = true;
      }
    }
  };

  $collectEmail($message['fromAddr'] ?? '');
  foreach (($message['to'] ?? []) as $email) $collectEmail($email);
  foreach (($message['cc'] ?? []) as $email) $collectEmail($email);
  $collectEmail($message['body'] ?? '');
  $collectEmail($message['subject'] ?? '');
  $collectPhone($message['body'] ?? '');
  $collectPhone($message['subject'] ?? '');

  $customers = [];
  $quotes = [];
  $jobs = [];
  $clickup = [];
  $customersDir = __DIR__ . DIRECTORY_SEPARATOR . 'customers';
  if (is_dir($customersDir)) {
    foreach ((glob($customersDir . DIRECTORY_SEPARATOR . '*.json') ?: []) as $file) {
      $customer = json_decode((string)@file_get_contents($file), true);
      if (!is_array($customer)) continue;
      $matched = false;
      foreach (array_keys($emails) as $email) {
        if (ogm_email_array_contains_value($customer, static fn($v) => ogm_email_norm_email($v) === $email)) { $matched = true; break; }
      }
      if (!$matched) {
        foreach (array_keys($phones) as $digits) {
          if (ogm_email_array_contains_value($customer, static fn($v) => substr(ogm_email_digits($v), -10) === $digits)) { $matched = true; break; }
        }
      }
      if (!$matched) continue;
      $cid = trim((string)($customer['id'] ?? pathinfo($file, PATHINFO_FILENAME)));
      $custQuotes = is_array($customer['quotes'] ?? null) ? $customer['quotes'] : [];
      $custJobs = is_array($customer['clickupJobs'] ?? null) ? $customer['clickupJobs'] : [];
      $customers[] = [
        'id' => $cid,
        'name' => ogm_email_display_name($customer),
        'email' => (string)($customer['email'] ?? ''),
        'phone' => (string)($customer['phone'] ?? ''),
        'url' => 'customer-db.php?cid=' . rawurlencode($cid),
      ];
      foreach ($custQuotes as $q) {
        if (is_array($q)) {
          $num = trim((string)($q['quoteNumber'] ?? $q['number'] ?? ''));
          if ($num !== '') $quotes[$num] = ['quoteNumber' => $num, 'label' => trim((string)($q['label'] ?? $q['status'] ?? 'Quote')), 'total' => $q['total'] ?? null];
        } elseif (is_string($q) && trim($q) !== '') {
          $quotes[trim($q)] = ['quoteNumber' => trim($q), 'label' => 'Quote', 'total' => null];
        }
      }
      foreach ($custJobs as $job) {
        if (is_array($job)) {
          $taskId = trim((string)($job['taskId'] ?? $job['id'] ?? ''));
          if ($taskId !== '') $jobs[$taskId] = ['taskId' => $taskId, 'name' => trim((string)($job['name'] ?? 'ClickUp job')), 'url' => (string)($job['url'] ?? '')];
        } elseif (is_string($job) && trim($job) !== '') {
          $jobs[trim($job)] = ['taskId' => trim($job), 'name' => 'ClickUp job', 'url' => ''];
        }
      }
    }
  }
  return [
    'matchedEmails' => array_keys($emails),
    'customers' => array_values(array_slice($customers, 0, 6)),
    'quotes' => array_values(array_slice($quotes, 0, 10)),
    'jobs' => array_values(array_slice($jobs, 0, 10)),
    'clickup' => array_values($clickup),
  ];
}


function ogm_email_user_state_dir(string $username): string {
  $safe = preg_replace('/[^a-z0-9._@-]/i', '_', strtolower(trim($username)));
  if ($safe === '') $safe = 'unknown';
  $dir = ogm_email_data_dir() . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . $safe;
  if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
    ogm_email_bad('Could not create Email Center user storage.', 500);
  }
  @chmod($dir, 0700);
  $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
  if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
  return $dir;
}

function ogm_email_state_defaults(): array {
  return ['tags' => [], 'reminders' => [], 'attachmentLog' => [], 'junkFilter' => false, 'junkCache' => [], 'junkAllow' => []];
}

function ogm_email_state_path(string $username): string {
  return ogm_email_user_state_dir($username) . DIRECTORY_SEPARATOR . 'state.json';
}

function ogm_email_load_state(string $username): array {
  $path = ogm_email_state_path($username);
  if (!is_file($path)) return ogm_email_state_defaults();
  $json = json_decode((string)@file_get_contents($path), true);
  if (!is_array($json)) return ogm_email_state_defaults();
  return array_replace_recursive(ogm_email_state_defaults(), $json);
}

function ogm_email_remove_message_state(string $username, array $messageIds): void {
  $messageIds = array_values(array_filter(array_map('strval', $messageIds), static fn($id) => $id !== ''));
  if (!$messageIds) return;
  $idSet = array_flip($messageIds);
  $stateData = ogm_email_load_state($username);
  foreach (array_keys($stateData['tags'] ?? []) as $mid) {
    if (isset($idSet[$mid])) unset($stateData['tags'][$mid]);
  }
  $stateData['reminders'] = array_values(array_filter($stateData['reminders'] ?? [], static function ($r) use ($idSet) {
    return !isset($idSet[(string)($r['messageId'] ?? '')]);
  }));
  ogm_email_save_state($username, $stateData);
}

function ogm_email_graph_get_json(string $token, string $path, array $query = [], bool $search = false): array {
  $url = 'https://graph.microsoft.com/v1.0' . $path;
  if ($query) {
    $url .= '?' . http_build_query($query);
  }
  $headers = ['Authorization: Bearer ' . $token];
  if ($search) {
    $headers[] = 'ConsistencyLevel: eventual';
  }
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 45,
  ]);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($raw === false) {
    return ['_status' => 0, '_error' => $err];
  }
  $json = json_decode((string)$raw, true);
  if (!is_array($json)) {
    $json = [];
  }
  $json['_status'] = $status;
  return $json;
}

function ogm_email_message_summary_from_graph(array $msg): array {
  return [
    'id' => (string)($msg['id'] ?? ''),
    'subject' => (string)($msg['subject'] ?? '(no subject)'),
    'from' => (string)($msg['from']['emailAddress']['name'] ?? ''),
    'fromAddr' => (string)($msg['from']['emailAddress']['address'] ?? ''),
    'date' => (string)($msg['receivedDateTime'] ?? $msg['sentDateTime'] ?? ''),
    'preview' => (string)($msg['bodyPreview'] ?? ''),
  ];
}

function ogm_email_fetch_folder_messages(string $token, string $folder, array $opts = []): array {
  $top = min(50, max(5, (int)($opts['top'] ?? 50)));
  $skip = max(0, (int)($opts['skip'] ?? 0));
  $search = trim((string)($opts['search'] ?? ''));
  $query = [
    '$top' => $top,
    '$select' => 'id,subject,from,toRecipients,receivedDateTime,sentDateTime,bodyPreview,isRead,hasAttachments',
  ];
  $isSearch = $search !== '';
  if ($isSearch) {
    $query['$search'] = '"' . str_replace('"', '', $search) . '"';
  } else {
    $query['$skip'] = $skip;
    $query['$orderby'] = ($folder === 'sentitems' ? 'sentDateTime' : 'receivedDateTime') . ' desc';
  }
  $res = ogm_email_graph_get_json(
    $token,
    '/me/mailFolders/' . $folder . '/messages',
    $query,
    $isSearch
  );
  if (($res['_status'] ?? 0) !== 200) {
    return [];
  }
  $out = [];
  foreach (($res['value'] ?? []) as $msg) {
    if (!is_array($msg)) {
      continue;
    }
    $summary = ogm_email_message_summary_from_graph($msg);
    if ($summary['id'] !== '') {
      $out[] = $summary;
    }
  }
  return $out;
}

function ogm_email_ai_graph_search_terms(string $query): string {
  $parts = [];
  if (function_exists('ogmSearchExtractFromClause')) {
    $fromClause = ogmSearchExtractFromClause($query);
    if ($fromClause !== '') {
      $parts[] = $fromClause;
      $compact = ogmSearchNormalizeCompact($fromClause);
      if (strlen($compact) >= 3) {
        $parts[] = $compact;
      }
    }
    foreach (ogmSearchSignificantTokens($query) as $token) {
      if (strlen($token) >= 2) {
        $parts[] = $token;
      }
    }
  } else {
    $parts[] = $query;
  }
  $parts = array_values(array_unique(array_filter(array_map('trim', $parts), static fn($p) => $p !== '')));
  return implode(' ', $parts);
}

function ogm_email_delete_graph_messages(string $token, array $messageIds): array {
  $deleted = [];
  $failed = [];
  foreach ($messageIds as $rawId) {
    $id = trim((string)$rawId);
    if ($id === '') continue;
    $res = ogm_graph_call($token, 'DELETE', '/me/messages/' . rawurlencode($id));
    $status = (int)($res['_status'] ?? 0);
    if ($status === 204 || $status === 200) {
      $deleted[] = $id;
      continue;
    }
    $failed[] = ['id' => $id, 'error' => (string)($res['error']['message'] ?? 'Could not delete message.')];
  }
  return ['deleted' => $deleted, 'failed' => $failed];
}

function ogm_email_save_state(string $username, array $state): void {
  $path = ogm_email_state_path($username);
  @file_put_contents($path, json_encode(array_replace_recursive(ogm_email_state_defaults(), $state), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  @chmod($path, 0600);
}


function ogm_email_default_templates(): array {
  return [
    ['id' => 'thanks', 'title' => 'Thanks / Received', 'note' => 'Quick acknowledgement', 'subject' => 'Thank you for reaching out', 'body' => "Hi [Name],\n\nThanks for reaching out. I received your message and will get back to you shortly.\n\nThank you,"],
    ['id' => 'quote-ready', 'title' => 'Quote Ready', 'note' => 'Send quote/proposal link', 'subject' => 'Your OGM proposal', 'body' => "Hi [Name],\n\nYour proposal is ready for review. Please let me know if you have any questions or would like to make adjustments.\n\nThank you,"],
    ['id' => 'follow-up', 'title' => 'Quote Follow-Up', 'note' => 'Follow up after quote', 'subject' => 'Following up on your OGM proposal', 'body' => "Hi [Name],\n\nI wanted to follow up on the proposal we sent over. Let me know if you have any questions or if you would like us to adjust anything.\n\nThank you,"],
    ['id' => 'template-ready', 'title' => 'Ready To Template', 'note' => 'Schedule template appointment', 'subject' => 'Ready to schedule your template', 'body' => "Hi [Name],\n\nWe are ready to schedule your template appointment. What days work best for you?\n\nThank you,"],
    ['id' => 'install-confirm', 'title' => 'Install Confirmed', 'note' => 'Install appointment note', 'subject' => 'Installation confirmation', 'body' => "Hi [Name],\n\nYour installation is confirmed for [date]. Our crew will call ahead that morning.\n\nThank you,"],
    ['id' => 'contractor-intro', 'title' => 'Contractor Intro', 'note' => 'Trade/customer intro', 'subject' => 'Olive Glass & Marble', 'body' => "Hi [Contact Name],\n\nThank you for considering Olive Glass & Marble. We specialize in countertops, glass shower enclosures, and mirrors for residential and commercial projects throughout the Fayetteville area.\n\nI would be happy to connect and help with your next project.\n\nThank you,"],
  ];
}

function ogm_email_templates_path(): string {
  return ogm_email_data_dir() . DIRECTORY_SEPARATOR . 'templates.json';
}

function ogm_email_normalize_templates($value): array {
  if (!is_array($value)) return ogm_email_default_templates();
  $out = [];
  foreach ($value as $tpl) {
    if (!is_array($tpl)) continue;
    $id = preg_replace('/[^a-z0-9._-]+/i', '-', trim((string)($tpl['id'] ?? '')));
    $title = trim((string)($tpl['title'] ?? ''));
    $body = (string)($tpl['body'] ?? '');
    if ($id === '' || $title === '' || trim($body) === '') continue;
    $out[] = [
      'id' => $id,
      'title' => $title,
      'note' => trim((string)($tpl['note'] ?? '')),
      'subject' => trim((string)($tpl['subject'] ?? '')),
      'body' => $body,
    ];
  }
  return $out ?: ogm_email_default_templates();
}

function ogm_email_load_templates(): array {
  $path = ogm_email_templates_path();
  if (!is_file($path)) {
    $defaults = ogm_email_default_templates();
    @file_put_contents($path, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($path, 0600);
    return $defaults;
  }
  $json = json_decode((string)@file_get_contents($path), true);
  return ogm_email_normalize_templates($json);
}

function ogm_email_can_manage_templates(): bool {
  return qtCan('user_admin') || in_array(qtCurrentRole(), ['general_manager', 'division_manager'], true);
}

function ogm_email_can_use_ai(): bool {
  return qtCan('email_ai_draft') || ogm_email_can_manage_templates();
}

function ogm_email_is_automated_sender(string $addr, string $subject = ''): bool {
  $addr = strtolower(trim($addr));
  $subject = strtolower(trim($subject));
  if ($addr === '') return true;
  foreach (['no-reply@', 'noreply@', 'donotreply@', 'mailer-daemon', 'postmaster@'] as $needle) {
    if (strpos($addr, $needle) !== false) return true;
  }
  return strpos($subject, 'undeliverable:') === 0 || strpos($subject, 'automatic reply') === 0;
}

function ogm_email_search_folders(): array {
  return ['inbox', 'sentitems', 'drafts'];
}

function ogm_email_folder_label(string $folder): string {
  return match ($folder) {
    'sentitems' => 'Sent',
    'drafts' => 'Drafts',
    'junkemail' => 'Junk',
    default => 'Inbox',
  };
}

/** Password resets, MFA codes, and identity verification — never hide when filtering. */
function ogm_email_is_security_sensitive(array $msg): bool {
  $subject = strtolower((string)($msg['subject'] ?? ''));
  $preview = strtolower((string)($msg['preview'] ?? ''));
  $text = $subject . ' ' . $preview;
  foreach ([
    'password reset', 'reset your password', 'reset password', 'forgot password', 'forgot your password',
    'verification code', 'verify your identity', 'identity verification', 'verify your email',
    'one-time code', 'one time code', 'one-time passcode', 'one time passcode',
    'security code', 'authentication code', 'login code', 'sign-in code', 'sign in code',
    'two-factor', 'two factor', '2fa', 'multi-factor', 'mfa code', 'confirm your email',
    'email verification', 'verify it\'s you', 'verify its you', 'confirm your identity',
    'your code is', 'use this code', 'enter this code', 'access code', 'otp',
  ] as $needle) {
    if (strpos($text, $needle) !== false) {
      return true;
    }
  }
  if (preg_match('/\b(?:code|verify|confirm|passcode)[^\n]{0,40}\b\d{4,8}\b/i', $text)) {
    return true;
  }
  if (preg_match('/\b\d{4,8}\b[^\n]{0,40}(?:code|verify|confirm)/i', $text)) {
    return true;
  }

  return false;
}

/** Routine notifications from tools OGM uses — hide unless security-sensitive. */
function ogm_email_is_vendor_notification(array $msg): bool {
  $subject = strtolower((string)($msg['subject'] ?? ''));
  $preview = strtolower((string)($msg['preview'] ?? ''));
  $from = strtolower((string)($msg['fromAddr'] ?? ''));
  $fromName = strtolower((string)($msg['from'] ?? ''));
  $blob = $from . ' ' . $fromName . ' ' . $subject . ' ' . $preview;

  if (strpos($blob, 'godaddy') !== false || preg_match('/@(godaddy|secureserver)\./i', $from)) {
    return true;
  }
  if (strpos($blob, 'clickup') !== false || preg_match('/@(clickup|tasks\.clickup)\./i', $from)) {
    return true;
  }
  if (strpos($blob, 'goreminders') !== false || strpos($blob, 'go reminders') !== false
    || preg_match('/@(goreminders|go-reminders)\./i', $from)) {
    return true;
  }

  return false;
}

function ogm_email_is_vendor_junk(array $msg): bool {
  return ogm_email_is_vendor_notification($msg) && !ogm_email_is_security_sensitive($msg);
}

function ogm_email_heuristic_junk_score(array $msg): int {
  $subject = strtolower((string)($msg['subject'] ?? ''));
  $preview = strtolower((string)($msg['preview'] ?? ''));
  $from = strtolower((string)($msg['fromAddr'] ?? ''));
  $fromName = strtolower((string)($msg['from'] ?? ''));
  $text = $subject . ' ' . $preview . ' ' . $from . ' ' . $fromName;
  $score = 0;

  if (ogm_email_is_security_sensitive($msg)) {
    return 0;
  }
  if (ogm_email_is_vendor_junk($msg)) {
    return 6;
  }

  if (ogm_email_is_automated_sender($from, $subject)) {
    $score += 2;
  }
  foreach ([
    'unsubscribe', 'newsletter', 'marketing email', 'limited time', 'limited-time', 'act now',
    'special offer', 'exclusive deal', 'free shipping', 'click here', 'view in browser',
    'you are receiving this', 'manage preferences', 'email preferences', 'promotional',
    'no longer wish to receive', 'opt out', 'daily digest', 'weekly digest',
  ] as $needle) {
    if (strpos($text, $needle) !== false) {
      $score += 2;
    }
  }
  if (preg_match('/\b(\d{1,2}%\s*off|save \$\d+|buy now|shop now)\b/i', $text)) {
    $score += 2;
  }
  if (preg_match('/@(mail|email|e|m)\.(?!oliveglassandmarble\.com)[a-z0-9.-]+\.[a-z]{2,}$/i', $from)) {
    $score += 1;
  }
  if (preg_match('/@(mailchimp|constantcontact|sendgrid|cmail|createsend|hubspotemail|campaign-archive)\./i', $from)) {
    $score += 4;
  }
  if (preg_match('/^(info|news|newsletter|promo|promotions|deals|offers|marketing|ads)@/i', $from)) {
    $score += 2;
  }

  return $score;
}

function ogm_email_is_heuristic_junk(array $msg): bool {
  return ogm_email_heuristic_junk_score($msg) >= 5;
}

function ogm_email_should_hide_as_junk(array $msg, array $stateData, bool $aiFilterOn): bool {
  $id = (string)($msg['id'] ?? '');
  if ($id === '') {
    return false;
  }
  $allow = array_flip(array_map('strval', $stateData['junkAllow'] ?? []));
  if (isset($allow[$id])) {
    return false;
  }
  if (!$aiFilterOn) {
    return false;
  }
  if (ogm_email_is_security_sensitive($msg)) {
    return false;
  }
  if (ogm_email_is_vendor_junk($msg)) {
    return true;
  }
  $cache = is_array($stateData['junkCache'] ?? null) ? $stateData['junkCache'] : [];
  if (array_key_exists($id, $cache)) {
    return ($cache[$id] ?? '') === 'junk';
  }
  if (ogm_email_is_heuristic_junk($msg)) {
    return true;
  }
  if (ogm_email_heuristic_junk_score($msg) <= 1) {
    return false;
  }
  return false;
}

function ogm_email_apply_junk_filter(string $username, array $messages, bool $aiFilterOn, bool $runAi = true): array {
  if (!$aiFilterOn || !$messages) {
    return $messages;
  }
  $stateData = ogm_email_load_state($username);
  $cache = is_array($stateData['junkCache'] ?? null) ? $stateData['junkCache'] : [];
  $needsAi = [];
  foreach ($messages as $msg) {
    if (!is_array($msg)) {
      continue;
    }
    $id = (string)($msg['id'] ?? '');
    if ($id === '' || array_key_exists($id, $cache)) {
      continue;
    }
    if (ogm_email_is_security_sensitive($msg)) {
      $cache[$id] = 'keep';
      continue;
    }
    if (ogm_email_is_vendor_junk($msg)) {
      $cache[$id] = 'junk';
      continue;
    }
    $score = ogm_email_heuristic_junk_score($msg);
    if ($score >= 5) {
      $cache[$id] = 'junk';
    } elseif ($score <= 1) {
      $cache[$id] = 'keep';
    } elseif ($runAi && ogm_ai_configured() && count($needsAi) < 12) {
      $needsAi[] = $msg;
    }
  }
  if ($needsAi && $runAi && function_exists('ogm_ai_classify_junk_batch')) {
    $aiResult = ogm_ai_classify_junk_batch($needsAi);
    if (!empty($aiResult['_error'])) {
      foreach ($needsAi as $msg) {
        $cache[(string)($msg['id'] ?? '')] = ogm_email_heuristic_junk_score($msg) >= 3 ? 'junk' : 'keep';
      }
    } else {
      foreach (($aiResult['labels'] ?? []) as $mid => $label) {
        $cache[(string)$mid] = ($label === 'junk') ? 'junk' : 'keep';
      }
    }
  }
  if ($cache !== ($stateData['junkCache'] ?? [])) {
    $stateData['junkCache'] = $cache;
    ogm_email_save_state($username, $stateData);
  }
  $stateData['junkCache'] = $cache;
  $out = [];
  $hidden = 0;
  foreach ($messages as $msg) {
    if (!is_array($msg)) {
      continue;
    }
    if (ogm_email_should_hide_as_junk($msg, $stateData, true)) {
      $hidden++;
      continue;
    }
    $out[] = $msg;
  }
  return ['messages' => $out, 'hidden' => $hidden];
}

function ogm_email_search_mailboxes(string $token, string $search, array $folders, int $topPerFolder = 25): array {
  $pool = [];
  foreach ($folders as $folder) {
    $batch = ogm_email_fetch_folder_messages($token, $folder, ['top' => $topPerFolder, 'search' => $search]);
    foreach ($batch as $msg) {
      $msg['folder'] = $folder;
      $msg['folderLabel'] = ogm_email_folder_label($folder);
      $pool[$msg['id']] = $msg;
    }
  }
  $messages = array_values($pool);
  usort($messages, static fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
  return $messages;
}

function ogm_email_save_templates(array $templates): array {
  $clean = ogm_email_normalize_templates($templates);
  $path = ogm_email_templates_path();
  @file_put_contents($path, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  @chmod($path, 0600);
  return $clean;
}

function ogm_email_public_reminder(array $r): array {
  return [
    'id' => (string)($r['id'] ?? ''),
    'messageId' => (string)($r['messageId'] ?? ''),
    'subject' => (string)($r['subject'] ?? ''),
    'fromAddr' => (string)($r['fromAddr'] ?? ''),
    'dueAt' => (string)($r['dueAt'] ?? ''),
    'note' => (string)($r['note'] ?? ''),
    'done' => !empty($r['done']),
    'createdAt' => (string)($r['createdAt'] ?? ''),
    'updatedAt' => (string)($r['updatedAt'] ?? ''),
  ];
}

function ogm_email_attachment_public(array $a): array {
  return [
    'id' => (string)($a['id'] ?? ''),
    'name' => (string)($a['name'] ?? 'Attachment'),
    'size' => (int)($a['size'] ?? 0),
    'contentType' => (string)($a['contentType'] ?? 'application/octet-stream'),
    'isInline' => !empty($a['isInline']),
  ];
}

function ogm_email_safe_filename(string $name): string {
  $base = basename(str_replace(["\0", "\r", "\n"], '', $name));
  $base = preg_replace('/[^\w .@()\-]+/u', '_', $base);
  $base = trim((string)$base, " .\t\n\r\0\x0B");
  return $base !== '' ? $base : 'email-attachment';
}


function ogm_email_uploaded_attachments(int $maxTotalBytes = 20971520): array {
  if (empty($_FILES['attachments'])) return [];
  $files = $_FILES['attachments'];
  $names = is_array($files['name'] ?? null) ? $files['name'] : [$files['name'] ?? 'attachment'];
  $tmpNames = is_array($files['tmp_name'] ?? null) ? $files['tmp_name'] : [$files['tmp_name'] ?? ''];
  $types = is_array($files['type'] ?? null) ? $files['type'] : [$files['type'] ?? 'application/octet-stream'];
  $sizes = is_array($files['size'] ?? null) ? $files['size'] : [$files['size'] ?? 0];
  $errors = is_array($files['error'] ?? null) ? $files['error'] : [$files['error'] ?? UPLOAD_ERR_NO_FILE];
  $out = [];
  $total = 0;
  foreach ($names as $i => $rawName) {
    $err = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) continue;
    if ($err !== UPLOAD_ERR_OK) {
      $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'Attachment is larger than the server upload limit.',
        UPLOAD_ERR_FORM_SIZE => 'Attachment is larger than the form upload limit.',
        UPLOAD_ERR_PARTIAL => 'Attachment upload was interrupted. Try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server upload folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded attachment.',
        UPLOAD_ERR_EXTENSION => 'Server blocked this attachment type.',
      ];
      ogm_email_bad($uploadErrors[$err] ?? 'Attachment upload failed. Try a smaller file.', 400);
    }
    $tmp = (string)($tmpNames[$i] ?? '');
    $size = (int)($sizes[$i] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) ogm_email_bad('Attachment upload could not be read.', 400);
    if ($size <= 0) ogm_email_bad('Attachment is empty.', 400);
    if ($size > 10 * 1024 * 1024) ogm_email_bad('Each attachment must be 10 MB or smaller.', 400);
    $total += $size;
    if ($total > $maxTotalBytes) ogm_email_bad('Total attachments must be 20 MB or smaller.', 400);
    $bytes = @file_get_contents($tmp);
    if ($bytes === false) ogm_email_bad('Attachment upload could not be prepared.', 500);
    $out[] = [
      'name' => ogm_email_safe_filename((string)$rawName),
      'contentType' => trim((string)($types[$i] ?? '')) ?: 'application/octet-stream',
      'size' => strlen($bytes),
      'bytes' => $bytes,
    ];
  }
  return $out;
}

function ogm_email_graph_attachment_payload(array $attachment): array {
  return [
    '@odata.type' => '#microsoft.graph.fileAttachment',
    'name' => (string)($attachment['name'] ?? 'attachment'),
    'contentType' => (string)($attachment['contentType'] ?? 'application/octet-stream'),
    'contentBytes' => base64_encode((string)($attachment['bytes'] ?? '')),
  ];
}

function ogm_email_graph_upload_large_attachment(string $uploadUrl, string $bytes): array {
  $length = strlen($bytes);
  $ch = curl_init($uploadUrl);
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_POSTFIELDS => $bytes,
    CURLOPT_HTTPHEADER => [
      'Content-Length: ' . $length,
      'Content-Range: bytes 0-' . max(0, $length - 1) . '/' . $length,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 90,
  ]);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($raw === false) {
    return ['_status' => 0, 'error' => ['message' => $err ?: 'Upload failed']];
  }
  $json = json_decode((string)$raw, true);
  if (!is_array($json)) $json = ['raw' => (string)$raw];
  $json['_status'] = $status;
  return $json;
}

function ogm_email_graph_attach_file(string $token, string $draftId, array $attachment): void {
  $name = (string)($attachment['name'] ?? 'attachment');
  $contentType = (string)($attachment['contentType'] ?? 'application/octet-stream');
  $bytes = (string)($attachment['bytes'] ?? '');
  $size = (int)($attachment['size'] ?? strlen($bytes));
  if ($size <= 0 || $bytes === '') return;

  if ($size <= 3 * 1024 * 1024) {
    $res = ogm_graph_call($token, 'POST', '/me/messages/' . rawurlencode($draftId) . '/attachments', ogm_email_graph_attachment_payload($attachment));
    if (($res['_status'] ?? 0) < 200 || ($res['_status'] ?? 0) >= 300) {
      ogm_email_bad('Could not attach file: ' . ($res['error']['message'] ?? 'Graph error ' . ($res['_status'] ?? 0)), 502);
    }
    return;
  }

  $session = ogm_graph_call($token, 'POST', '/me/messages/' . rawurlencode($draftId) . '/attachments/createUploadSession', [
    'AttachmentItem' => [
      'attachmentType' => 'file',
      'name' => $name,
      'size' => $size,
      'contentType' => $contentType,
    ],
  ]);
  if (($session['_status'] ?? 0) < 200 || ($session['_status'] ?? 0) >= 300 || empty($session['uploadUrl'])) {
    ogm_email_bad('Could not prepare large attachment upload: ' . ($session['error']['message'] ?? 'Graph error ' . ($session['_status'] ?? 0)), 502);
  }
  $upload = ogm_email_graph_upload_large_attachment((string)$session['uploadUrl'], $bytes);
  $status = (int)($upload['_status'] ?? 0);
  if ($status < 200 || $status >= 300) {
    ogm_email_bad('Could not upload large attachment: ' . ($upload['error']['message'] ?? 'Graph error ' . $status), 502);
  }
}

function ogm_email_graph_attach_files(string $token, string $draftId, array $attachments): void {
  foreach ($attachments as $attachment) {
    ogm_email_graph_attach_file($token, $draftId, $attachment);
  }
}

function ogm_email_graph_reply_with_attachments(string $token, string $messageId, string $html, bool $replyAll, array $attachments): void {
  $draftEndpoint = $replyAll ? '/createReplyAll' : '/createReply';
  $draft = ogm_graph_call($token, 'POST', '/me/messages/' . rawurlencode($messageId) . $draftEndpoint);
  if (($draft['_status'] ?? 0) < 200 || ($draft['_status'] ?? 0) >= 300 || empty($draft['id'])) {
    ogm_email_bad('Could not prepare reply draft: ' . ($draft['error']['message'] ?? 'Graph error ' . ($draft['_status'] ?? 0)), 502);
  }
  $draftId = (string)$draft['id'];
  $quoted = (string)($draft['body']['content'] ?? '');
  $content = $html . ($quoted !== '' ? '<br><br>' . $quoted : '');
  $patch = ogm_graph_call($token, 'PATCH', '/me/messages/' . rawurlencode($draftId), ['body' => ['contentType' => 'HTML', 'content' => $content]]);
  if (($patch['_status'] ?? 0) >= 300) {
    ogm_email_bad('Could not update reply body: ' . ($patch['error']['message'] ?? 'Graph error ' . ($patch['_status'] ?? 0)), 502);
  }
  ogm_email_graph_attach_files($token, $draftId, $attachments);
  $send = ogm_graph_call($token, 'POST', '/me/messages/' . rawurlencode($draftId) . '/send');
  if (($send['_status'] ?? 0) !== 202) {
    ogm_email_bad('Reply failed: ' . ($send['error']['message'] ?? 'Graph error ' . ($send['_status'] ?? 0)), 502);
  }
}

function ogm_email_clickup_api_key(): string {
  $paths = [
    __DIR__ . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . 'clickup-api-key.json',
    __DIR__ . DIRECTORY_SEPARATOR . 'clickup-api-key.json',
  ];
  foreach ($paths as $path) {
    if (!is_file($path)) continue;
    $json = json_decode((string)@file_get_contents($path), true);
    if (!is_array($json)) continue;
    foreach (['apiKey', 'api_key', 'token', 'key'] as $key) {
      $value = trim((string)($json[$key] ?? ''));
      if ($value !== '') return $value;
    }
  }
  return trim((string)getenv('CLICKUP_API_KEY'));
}

function ogm_email_clickup_upload_attachment(string $apiKey, string $taskId, string $tmpPath, string $fileName, string $mime): array {
  $url = 'https://api.clickup.com/api/v2/task/' . rawurlencode($taskId) . '/attachment';
  $curlFile = new CURLFile($tmpPath, $mime ?: 'application/octet-stream', $fileName);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Authorization: ' . $apiKey, 'Accept: application/json'],
    CURLOPT_POSTFIELDS => ['attachment' => $curlFile],
    CURLOPT_TIMEOUT => 90,
  ]);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($raw === false) return ['_status' => 0, '_error' => $err];
  $json = json_decode((string)$raw, true);
  if (!is_array($json)) $json = ['raw' => substr((string)$raw, 0, 500)];
  $json['_status'] = $status;
  return $json;
}

function ogm_email_clickup_add_comment(string $apiKey, string $taskId, string $comment): array {
  $url = 'https://api.clickup.com/api/v2/task/' . rawurlencode($taskId) . '/comment';
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: ' . $apiKey,
      'Accept: application/json',
      'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode(['comment_text' => $comment], JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 45,
  ]);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($raw === false) return ['_status' => 0, '_error' => $err];
  $json = json_decode((string)$raw, true);
  if (!is_array($json)) $json = ['raw' => substr((string)$raw, 0, 500)];
  $json['_status'] = $status;
  return $json;
}

function ogm_email_job_search(string $query): array {
  $q = strtolower(trim($query));
  $out = [];
  $seen = [];
  $customersDir = __DIR__ . DIRECTORY_SEPARATOR . 'customers';
  if (!is_dir($customersDir)) return [];
  foreach ((glob($customersDir . DIRECTORY_SEPARATOR . '*.json') ?: []) as $file) {
    $customer = json_decode((string)@file_get_contents($file), true);
    if (!is_array($customer)) continue;
    $customerName = ogm_email_display_name($customer);
    $jobs = is_array($customer['clickupJobs'] ?? null) ? $customer['clickupJobs'] : [];
    foreach ($jobs as $job) {
      if (!is_array($job)) continue;
      $taskId = trim((string)($job['taskId'] ?? $job['id'] ?? ''));
      if ($taskId === '' || isset($seen[$taskId])) continue;
      $name = trim((string)($job['name'] ?? $job['jobName'] ?? 'ClickUp job'));
      $haystack = strtolower($taskId . ' ' . $name . ' ' . $customerName . ' ' . json_encode($job));
      if ($q !== '' && strpos($haystack, $q) === false) continue;
      $seen[$taskId] = true;
      $out[] = [
        'taskId' => $taskId,
        'name' => $name !== '' ? $name : 'ClickUp job',
        'customer' => $customerName,
        'url' => (string)($job['url'] ?? ''),
      ];
      if (count($out) >= 40) return $out;
    }
  }
  return $out;
}

function ogm_email_safe_id(string $raw): string {
  $id = preg_replace('/[^A-Za-z0-9._-]/', '', $raw);
  return substr((string)$id, 0, 80);
}

function ogm_email_customer_history_dir(): string {
  $dir = ogm_email_data_dir() . DIRECTORY_SEPARATOR . 'customer-history';
  if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
    ogm_email_bad('Could not create customer email history storage.', 500);
  }
  @chmod($dir, 0700);
  $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
  if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
  return $dir;
}

function ogm_email_customer_path(string $customerId): string {
  return __DIR__ . DIRECTORY_SEPARATOR . 'customers' . DIRECTORY_SEPARATOR . ogm_email_safe_id($customerId) . '.json';
}

function ogm_email_customer_history_path(string $customerId): string {
  $id = ogm_email_safe_id($customerId);
  if ($id === '') ogm_email_bad('Missing customer id.');
  return ogm_email_customer_history_dir() . DIRECTORY_SEPARATOR . $id . '.json';
}

function ogm_email_clean_text(string $html, int $limit = 20000): string {
  $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', (string)$html);
  $html = preg_replace('/<br\s*\/?>/i', "\n", (string)$html);
  $html = preg_replace('/<\/(p|div|li|tr|h[1-6])>/i', "\n", (string)$html);
  $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $text = preg_replace("/[ \t]+/", ' ', (string)$text);
  $text = preg_replace("/\n{3,}/", "\n\n", (string)$text);
  $text = trim((string)$text);
  return strlen($text) > $limit ? substr($text, 0, $limit) . "\n\n[trimmed]" : $text;
}

function ogm_email_graph_message_for_archive(string $token, string $messageId): array {
  $id = trim($messageId);
  if ($id === '') ogm_email_bad('Missing message id.');
  $select = '$select=id,subject,from,toRecipients,ccRecipients,receivedDateTime,sentDateTime,body,isRead,hasAttachments,conversationId';
  $res = ogm_graph_call($token, 'GET', '/me/messages/' . rawurlencode($id) . '?' . $select);
  if (($res['_status'] ?? 0) !== 200) {
    ogm_email_bad('Could not load email for customer history.', 502);
  }
  return $res;
}

function ogm_email_public_archived_entry(array $entry): array {
  return [
    'id' => (string)($entry['id'] ?? ''),
    'messageId' => (string)($entry['messageId'] ?? ''),
    'subject' => (string)($entry['subject'] ?? ''),
    'from' => $entry['from'] ?? [],
    'to' => $entry['to'] ?? [],
    'cc' => $entry['cc'] ?? [],
    'date' => (string)($entry['date'] ?? ''),
    'bodyText' => (string)($entry['bodyText'] ?? ''),
    'tag' => (string)($entry['tag'] ?? ''),
    'jobId' => (string)($entry['jobId'] ?? ''),
    'jobName' => (string)($entry['jobName'] ?? ''),
    'note' => (string)($entry['note'] ?? ''),
    'savedBy' => (string)($entry['savedBy'] ?? ''),
    'savedAt' => (string)($entry['savedAt'] ?? ''),
  ];
}

function ogm_email_save_customer_history_entry(string $username, string $token, string $messageId, string $customerId, string $tag = '', string $jobId = '', string $jobName = '', string $note = ''): array {
  $customerId = ogm_email_safe_id($customerId);
  if ($customerId === '') ogm_email_bad('Choose a customer first.');
  if (!is_file(ogm_email_customer_path($customerId))) ogm_email_bad('Customer record was not found.', 404);
  $msg = ogm_email_graph_message_for_archive($token, $messageId);
  $messageId = (string)($msg['id'] ?? $messageId);
  $path = ogm_email_customer_history_path($customerId);
  $history = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
  if (!is_array($history)) $history = [];
  if (!isset($history['entries']) || !is_array($history['entries'])) $history['entries'] = [];
  $tokensNow = ogm_graph_load_tokens($username);
  $mailbox = strtolower(trim((string)($tokensNow['mailbox'] ?? '')));
  $entry = [
    'id' => 'emh_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)),
    'messageId' => $messageId,
    'conversationId' => (string)($msg['conversationId'] ?? ''),
    'mailboxUser' => $mailbox,
    'subject' => (string)($msg['subject'] ?? '(no subject)'),
    'from' => [
      'name' => (string)($msg['from']['emailAddress']['name'] ?? ''),
      'email' => (string)($msg['from']['emailAddress']['address'] ?? ''),
    ],
    'to' => array_values(array_filter(array_map(static fn($t) => (string)($t['emailAddress']['address'] ?? ''), $msg['toRecipients'] ?? []))),
    'cc' => array_values(array_filter(array_map(static fn($t) => (string)($t['emailAddress']['address'] ?? ''), $msg['ccRecipients'] ?? []))),
    'date' => (string)($msg['receivedDateTime'] ?? $msg['sentDateTime'] ?? ''),
    'bodyText' => ogm_email_clean_text((string)($msg['body']['content'] ?? '')),
    'bodyHtml' => substr((string)($msg['body']['content'] ?? ''), 0, 1000000),
    'tag' => substr(trim($tag), 0, 40),
    'jobId' => substr(trim($jobId), 0, 80),
    'jobName' => substr(trim($jobName), 0, 180),
    'note' => substr(trim($note), 0, 500),
    'savedBy' => qtCurrentUser(),
    'savedUsername' => $username,
    'savedAt' => date('c'),
  ];
  $updated = false;
  foreach ($history['entries'] as &$existing) {
    if (($existing['messageId'] ?? '') === $entry['messageId'] && strtolower((string)($existing['mailboxUser'] ?? '')) === $mailbox) {
      $existing = array_merge($existing, $entry, [
        'id' => (string)($existing['id'] ?? $entry['id']),
        'tag' => $entry['tag'] ?: (string)($existing['tag'] ?? ''),
        'jobId' => $entry['jobId'] ?: (string)($existing['jobId'] ?? ''),
        'jobName' => $entry['jobName'] ?: (string)($existing['jobName'] ?? ''),
        'note' => $entry['note'] ?: (string)($existing['note'] ?? ''),
      ]);
      $entry = $existing;
      $updated = true;
      break;
    }
  }
  unset($existing);
  if (!$updated) $history['entries'][] = $entry;
  $history['customerId'] = $customerId;
  $history['updatedAt'] = date('c');
  @file_put_contents($path, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  @chmod($path, 0600);
  ogm_email_update_customer_email_metadata($customerId, $history);
  return ogm_email_public_archived_entry($entry);
}

/** Lightweight email metadata on customer JSON — full bodies live in the sidecar only. */
function ogm_email_update_customer_email_metadata(string $customerId, array $history): void {
  $custPath = ogm_email_customer_path($customerId);
  if (!is_file($custPath)) return;
  $customer = json_decode((string)@file_get_contents($custPath), true);
  if (!is_array($customer)) return;

  $entries = is_array($history['entries'] ?? null) ? $history['entries'] : [];
  usort($entries, static function ($a, $b) {
    $ta = strtotime((string)($a['date'] ?? $a['savedAt'] ?? '')) ?: 0;
    $tb = strtotime((string)($b['date'] ?? $b['savedAt'] ?? '')) ?: 0;
    return $tb <=> $ta;
  });

  $latest = $entries[0] ?? null;
  $customer['emailHistoryCount'] = count($entries);
  $customer['emailHistoryUpdatedAt'] = date('c');
  $customer['lastEmailSubject'] = $latest ? (string)($latest['subject'] ?? '(no subject)') : '';
  $customer['lastEmailAt'] = $latest ? (string)($latest['date'] ?? $latest['savedAt'] ?? '') : '';
  unset($customer['emailCommunications'], $customer['emailCommunicationsUpdatedAt']);

  @file_put_contents($custPath, json_encode($customer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function ogm_email_find_customer_by_email(string $email): array {
  $email = ogm_email_norm_email($email);
  if ($email === '') return [];
  $customersDir = __DIR__ . DIRECTORY_SEPARATOR . 'customers';
  foreach ((glob($customersDir . DIRECTORY_SEPARATOR . '*.json') ?: []) as $file) {
    $customer = json_decode((string)@file_get_contents($file), true);
    if (!is_array($customer)) continue;
    foreach (['email', 'email2'] as $field) {
      if (ogm_email_norm_email((string)($customer[$field] ?? '')) === $email) {
        $customer['id'] = (string)($customer['id'] ?? pathinfo($file, PATHINFO_FILENAME));
        return $customer;
      }
    }
  }
  return [];
}

function ogm_email_create_customer_from_message(string $username, string $token, string $messageId): array {
  $msg = ogm_email_graph_message_for_archive($token, $messageId);
  $tokensNow = ogm_graph_load_tokens($username);
  $mailbox = strtolower(trim((string)($tokensNow['mailbox'] ?? '')));
  $fromEmail = strtolower(trim((string)($msg['from']['emailAddress']['address'] ?? '')));
  $fromName = trim((string)($msg['from']['emailAddress']['name'] ?? ''));
  if ($fromEmail === '' || $fromEmail === $mailbox || strpos($fromEmail, '@oliveglassandmarble.com') !== false) {
    ogm_email_bad('This message does not appear to be from a customer address.', 400);
  }
  $existing = ogm_email_find_customer_by_email($fromEmail);
  if ($existing) {
    return ['created' => false, 'customer' => [
      'id' => (string)$existing['id'],
      'name' => ogm_email_display_name($existing),
      'email' => (string)($existing['email'] ?? $fromEmail),
      'url' => 'customer-db.php?cid=' . rawurlencode((string)$existing['id']),
    ]];
  }
  $parts = preg_split('/\s+/', trim($fromName)) ?: [];
  $first = (string)($parts[0] ?? '');
  $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
  $idBase = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim(($first ?: 'email') . '-' . ($last ?: 'customer'))));
  $idBase = trim($idBase, '-');
  if ($idBase === '') $idBase = 'email-customer';
  $customersDir = __DIR__ . DIRECTORY_SEPARATOR . 'customers';
  if (!is_dir($customersDir)) @mkdir($customersDir, 0755, true);
  $id = ogm_email_safe_id($idBase);
  $suffix = 1;
  while ($id === '' || is_file($customersDir . DIRECTORY_SEPARATOR . $id . '.json')) {
    $id = ogm_email_safe_id($idBase . '-' . (++$suffix));
  }
  $customer = [
    'id' => $id,
    'firstName' => $first,
    'lastName' => $last,
    'email' => $fromEmail,
    'phone' => '',
    'svcStreet' => '',
    'svcCity' => '',
    'billStreet' => '',
    'billCity' => '',
    'sameAddr' => false,
    'jobName' => '',
    'status' => 'prospect',
    'rep' => qtCurrentUser(),
    'source' => 'Email Center',
    'notes' => 'Created from Email Center message: ' . (string)($msg['subject'] ?? '(no subject)'),
    'quotes' => [],
    'notesLog' => [[
      'date' => date('Y-m-d'),
      'text' => 'Created from email from ' . $fromEmail . ' by ' . qtCurrentUser(),
    ]],
    'createdAt' => date('Y-m-d'),
    'updatedAt' => date('c'),
  ];
  @file_put_contents($customersDir . DIRECTORY_SEPARATOR . $id . '.json', json_encode($customer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  return ['created' => true, 'customer' => [
    'id' => $id,
    'name' => ogm_email_display_name($customer),
    'email' => $fromEmail,
    'url' => 'customer-db.php?cid=' . rawurlencode($id),
  ]];
}

function ogm_email_leads_path(): string {
  $dir = ogm_email_data_dir() . DIRECTORY_SEPARATOR . 'leads';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  @chmod($dir, 0700);
  $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
  if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
  return $dir . DIRECTORY_SEPARATOR . 'email-leads.json';
}

if ($action === 'status') {
  $tokens = ogm_graph_load_tokens($username);
  $mailState = ogm_email_load_state($username);
  ogm_email_out([
    'ok' => true,
    'configured' => ogm_graph_configured(),
    'connected' => (bool)$tokens,
    'mailbox' => (string)($tokens['mailbox'] ?? ''),
    'name' => (string)($tokens['name'] ?? qtCurrentUser()),
    'quoterUser' => $username,
    'canManageTemplates' => ogm_email_can_manage_templates(),
    'canUseAiDraft' => ogm_email_can_use_ai(),
    'aiDraftConfigured' => ogm_ai_configured(),
    'aiDraftDailyUsed' => ogm_email_can_use_ai() ? ogm_ai_today_call_count() : 0,
    'aiDraftDailyLimit' => ogm_email_can_use_ai() ? ogm_ai_daily_limit() : 0,
    'junkFilter' => !empty($mailState['junkFilter']),
    'aiSearchConfigured' => ogm_ai_configured(),
  ]);
}

if ($action === 'junk-filter-set') {
  $enabled = !empty($input['enabled']);
  $stateData = ogm_email_load_state($username);
  $stateData['junkFilter'] = $enabled;
  ogm_email_save_state($username, $stateData);
  ogm_email_out(['ok' => true, 'junkFilter' => $enabled]);
}

if ($action === 'login-url') {
  if (!ogm_graph_configured()) ogm_email_bad('Email Center is missing the Microsoft Graph client secret.', 503);
  $state = bin2hex(random_bytes(16));
  $_SESSION['ogm_email_oauth_state'] = ['state' => $state, 'username' => $username, 'created_at' => time()];
  $url = 'https://login.microsoftonline.com/' . OGM_GRAPH_TENANT_ID . '/oauth2/v2.0/authorize?' . http_build_query([
    'client_id' => OGM_GRAPH_CLIENT_ID,
    'response_type' => 'code',
    'redirect_uri' => OGM_GRAPH_REDIRECT_URI,
    'response_mode' => 'query',
    'scope' => OGM_GRAPH_SCOPES,
    'state' => $state,
    'prompt' => 'select_account',
  ]);
  ogm_email_out(['ok' => true, 'url' => $url]);
}

if ($action === 'disconnect') {
  ogm_graph_delete_tokens($username);
  ogm_email_out(['ok' => true]);
}

if ($action === 'templates-list') {
  ogm_email_out(['ok' => true, 'templates' => ogm_email_load_templates(), 'canManage' => ogm_email_can_manage_templates()]);
}

if ($action === 'templates-save') {
  if (!ogm_email_can_manage_templates()) ogm_email_bad('You do not have permission to edit email templates.', 403);
  $templates = $input['templates'] ?? [];
  ogm_email_out(['ok' => true, 'templates' => ogm_email_save_templates(is_array($templates) ? $templates : [])]);
}

if ($action === 'templates-reset') {
  if (!ogm_email_can_manage_templates()) ogm_email_bad('You do not have permission to edit email templates.', 403);
  ogm_email_out(['ok' => true, 'templates' => ogm_email_save_templates(ogm_email_default_templates())]);
}

$err = null;
$token = ogm_graph_access_token($username, $err);
if (!$token) {
  if ($err === 'not_connected') ogm_email_bad('Not connected. Click Connect Email first.', 401);
  ogm_email_bad('Session with Microsoft expired (' . $err . '). Please reconnect your email.', 401);
}

switch ($action) {
  case 'inbox':
    $folderIn = strtolower((string)($_GET['folder'] ?? 'inbox'));
    $folder = in_array($folderIn, ['inbox', 'sentitems', 'drafts', 'junkemail'], true) ? $folderIn : 'inbox';
    $top = min(50, max(5, (int)($_GET['top'] ?? 25)));
    $skip = max(0, (int)($_GET['skip'] ?? 0));
    $search = trim((string)($_GET['search'] ?? ''));
    $aiFilter = (string)($_GET['aiFilter'] ?? '') === '1';
    $hiddenJunk = 0;

    if ($search !== '') {
      $searchFolders = ogm_email_search_folders();
      $messages = ogm_email_search_mailboxes($token, $search, $searchFolders, 25);
      $more = false;
    } else {
      $query = ['$top' => $top, '$select' => 'id,subject,from,toRecipients,receivedDateTime,sentDateTime,bodyPreview,isRead,hasAttachments,conversationId'];
      $query['$skip'] = $skip;
      $query['$orderby'] = ($folder === 'sentitems' ? 'sentDateTime' : 'receivedDateTime') . ' desc';
      $res = ogm_graph_call($token, 'GET', '/me/mailFolders/' . $folder . '/messages?' . http_build_query($query));
      if (($res['_status'] ?? 0) !== 200) ogm_email_bad('Graph error ' . ($res['_status'] ?? 0) . ': ' . ($res['error']['message'] ?? 'could not load messages'), 502);
      $messages = [];
      foreach (($res['value'] ?? []) as $msg) {
        $messages[] = [
          'id' => $msg['id'] ?? '',
          'subject' => $msg['subject'] ?? '(no subject)',
          'from' => $msg['from']['emailAddress']['name'] ?? ($msg['from']['emailAddress']['address'] ?? ''),
          'fromAddr' => $msg['from']['emailAddress']['address'] ?? '',
          'to' => array_map(static fn($t) => $t['emailAddress']['address'] ?? '', $msg['toRecipients'] ?? []),
          'date' => $msg['receivedDateTime'] ?? $msg['sentDateTime'] ?? '',
          'preview' => $msg['bodyPreview'] ?? '',
          'isRead' => (bool)($msg['isRead'] ?? true),
          'hasAttach' => (bool)($msg['hasAttachments'] ?? false),
          'convId' => $msg['conversationId'] ?? '',
          'folder' => $folder,
          'folderLabel' => ogm_email_folder_label($folder),
        ];
      }
      $more = !empty($res['@odata.nextLink']);
    }

    if ($aiFilter && $folder === 'inbox' && $search === '') {
      $filtered = ogm_email_apply_junk_filter($username, $messages, true, true);
      $messages = $filtered['messages'];
      $hiddenJunk = (int)($filtered['hidden'] ?? 0);
    }

    ogm_email_out(['ok' => true, 'messages' => $messages, 'more' => $more, 'hiddenJunk' => $hiddenJunk, 'searchScope' => $search !== '' ? ogm_email_search_folders() : [$folder]]);

  case 'message':
    $id = (string)($_GET['id'] ?? '');
    if ($id === '') ogm_email_bad('Missing message id.');
    $res = ogm_graph_call($token, 'GET', '/me/messages/' . rawurlencode($id) . '?$select=id,subject,from,toRecipients,ccRecipients,receivedDateTime,sentDateTime,body,isRead,hasAttachments');
    if (($res['_status'] ?? 0) !== 200) ogm_email_bad('Graph error ' . ($res['_status'] ?? 0) . ': ' . ($res['error']['message'] ?? 'could not load message'), 502);
    $attachments = [];
    if (!empty($res['hasAttachments'])) {
      $ares = ogm_graph_call($token, 'GET', '/me/messages/' . rawurlencode($id) . '/attachments?$select=id,name,size,contentType,isInline');
      if (($ares['_status'] ?? 0) === 200) {
        foreach (($ares['value'] ?? []) as $a) {
          $pub = ogm_email_attachment_public($a);
          if (!$pub['isInline'] && $pub['id'] !== '') $attachments[] = $pub;
        }
      }
    }
    $stateData = ogm_email_load_state($username);
    $message = [
      'id' => $res['id'] ?? '',
      'subject' => $res['subject'] ?? '(no subject)',
      'from' => $res['from']['emailAddress']['name'] ?? '',
      'fromAddr' => $res['from']['emailAddress']['address'] ?? '',
      'to' => array_map(static fn($t) => $t['emailAddress']['address'] ?? '', $res['toRecipients'] ?? []),
      'cc' => array_map(static fn($t) => $t['emailAddress']['address'] ?? '', $res['ccRecipients'] ?? []),
      'date' => $res['receivedDateTime'] ?? $res['sentDateTime'] ?? '',
      'bodyType' => $res['body']['contentType'] ?? 'html',
      'body' => $res['body']['content'] ?? '',
      'isRead' => (bool)($res['isRead'] ?? true),
      'hasAttach' => (bool)($res['hasAttachments'] ?? false),
      'attachments' => $attachments,
      'tag' => (string)($stateData['tags'][$id] ?? ''),
    ];
    ogm_email_out(['ok' => true, 'message' => $message, 'context' => ogm_email_message_context($message)]);

  case 'markread':
    $id = (string)($input['id'] ?? '');
    if ($id === '') ogm_email_bad('Missing message id.');
    $res = ogm_graph_call($token, 'PATCH', '/me/messages/' . rawurlencode($id), ['isRead' => (bool)($input['read'] ?? true)]);
    if (($res['_status'] ?? 0) >= 300) ogm_email_bad('Could not update message.', 502);
    ogm_email_out(['ok' => true]);

  case 'delete-message':
    $id = trim((string)($input['id'] ?? $input['messageId'] ?? ''));
    if ($id === '') ogm_email_bad('Missing message id.');
    $result = ogm_email_delete_graph_messages($token, [$id]);
    if (!$result['deleted']) {
      $err = $result['failed'][0]['error'] ?? 'Could not delete message.';
      ogm_email_bad($err, 502);
    }
    ogm_email_remove_message_state($username, $result['deleted']);
    ogm_email_out(['ok' => true, 'deleted' => count($result['deleted']), 'deletedIds' => $result['deleted']]);

  case 'delete-messages':
    $ids = $input['ids'] ?? $input['messageIds'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_filter(array_map('strval', $ids), static fn($id) => trim($id) !== ''));
    if (!$ids) ogm_email_bad('Choose at least one message to delete.');
    if (count($ids) > 50) ogm_email_bad('Delete up to 50 messages at a time.');
    $result = ogm_email_delete_graph_messages($token, $ids);
    if ($result['deleted']) ogm_email_remove_message_state($username, $result['deleted']);
    if (!$result['deleted'] && $result['failed']) {
      ogm_email_bad($result['failed'][0]['error'] ?? 'Could not delete messages.', 502);
    }
    ogm_email_out([
      'ok' => true,
      'deleted' => count($result['deleted']),
      'deletedIds' => $result['deleted'],
      'failed' => $result['failed'],
    ]);

  case 'tag-list':
    $stateData = ogm_email_load_state($username);
    ogm_email_out(['ok' => true, 'tags' => $stateData['tags']]);

  case 'tag-set':
    $messageId = trim((string)($input['messageId'] ?? ''));
    $tag = trim((string)($input['tag'] ?? ''));
    if ($messageId === '') ogm_email_bad('Missing message id.');
    $allowedTags = ['', 'lead', 'quote', 'follow', 'scheduled', 'resolved'];
    if (!in_array($tag, $allowedTags, true)) ogm_email_bad('Unknown tag.');
    $stateData = ogm_email_load_state($username);
    if ($tag === '') unset($stateData['tags'][$messageId]); else $stateData['tags'][$messageId] = $tag;
    ogm_email_save_state($username, $stateData);
    ogm_email_out(['ok' => true, 'tag' => $tag]);

  case 'reminders-list':
    $stateData = ogm_email_load_state($username);
    $list = array_map('ogm_email_public_reminder', $stateData['reminders']);
    usort($list, static fn($a, $b) => strcmp((string)$a['dueAt'], (string)$b['dueAt']));
    ogm_email_out(['ok' => true, 'reminders' => $list]);

  case 'reminders-due':
    $stateData = ogm_email_load_state($username);
    $now = time();
    $due = [];
    foreach ($stateData['reminders'] as $r) {
      if (!empty($r['done'])) continue;
      $ts = strtotime((string)($r['dueAt'] ?? ''));
      if ($ts && $ts <= $now) $due[] = ogm_email_public_reminder($r);
    }
    ogm_email_out(['ok' => true, 'count' => count($due), 'reminders' => $due]);

  case 'reminder-set':
    $messageId = trim((string)($input['messageId'] ?? ''));
    $dueAt = trim((string)($input['dueAt'] ?? ''));
    $dueTs = strtotime($dueAt);
    if ($messageId === '') ogm_email_bad('Missing message id.');
    if (!$dueTs) ogm_email_bad('Choose a valid reminder time.');
    $stateData = ogm_email_load_state($username);
    $reminder = [
      'id' => 'rem_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)),
      'messageId' => $messageId,
      'subject' => trim((string)($input['subject'] ?? '')),
      'fromAddr' => trim((string)($input['fromAddr'] ?? '')),
      'dueAt' => date('c', $dueTs),
      'note' => trim((string)($input['note'] ?? '')),
      'done' => false,
      'createdAt' => date('c'),
      'updatedAt' => date('c'),
    ];
    $stateData['reminders'][] = $reminder;
    ogm_email_save_state($username, $stateData);
    ogm_email_out(['ok' => true, 'reminder' => ogm_email_public_reminder($reminder)]);

  case 'reminder-done':
    $id = trim((string)($input['id'] ?? ''));
    if ($id === '') ogm_email_bad('Missing reminder id.');
    $stateData = ogm_email_load_state($username);
    foreach ($stateData['reminders'] as &$r) {
      if (($r['id'] ?? '') === $id) {
        $r['done'] = true;
        $r['updatedAt'] = date('c');
        break;
      }
    }
    unset($r);
    ogm_email_save_state($username, $stateData);
    ogm_email_out(['ok' => true]);

  case 'reminder-snooze':
    $id = trim((string)($input['id'] ?? ''));
    $dueAt = trim((string)($input['dueAt'] ?? ''));
    $dueTs = strtotime($dueAt);
    if ($id === '') ogm_email_bad('Missing reminder id.');
    if (!$dueTs) ogm_email_bad('Choose a valid snooze time.');
    $stateData = ogm_email_load_state($username);
    foreach ($stateData['reminders'] as &$r) {
      if (($r['id'] ?? '') === $id) {
        $r['dueAt'] = date('c', $dueTs);
        $r['done'] = false;
        $r['updatedAt'] = date('c');
        break;
      }
    }
    unset($r);
    ogm_email_save_state($username, $stateData);
    ogm_email_out(['ok' => true]);

  case 'job-search':
    ogm_email_out(['ok' => true, 'jobs' => ogm_email_job_search((string)($_GET['q'] ?? ''))]);

  case 'save-email-history':
    $messageId = trim((string)($input['messageId'] ?? ''));
    $customerId = trim((string)($input['customerId'] ?? ''));
    $entry = ogm_email_save_customer_history_entry(
      $username,
      $token,
      $messageId,
      $customerId,
      (string)($input['tag'] ?? ''),
      (string)($input['jobId'] ?? ''),
      (string)($input['jobName'] ?? ''),
      (string)($input['note'] ?? '')
    );
    ogm_email_out(['ok' => true, 'entry' => $entry]);

  case 'create-customer-from-email':
    $messageId = trim((string)($input['messageId'] ?? ''));
    if ($messageId === '') ogm_email_bad('Missing message id.');
    $result = ogm_email_create_customer_from_message($username, $token, $messageId);
    $entry = ogm_email_save_customer_history_entry($username, $token, $messageId, (string)$result['customer']['id'], 'lead');
    ogm_email_out(['ok' => true, 'created' => !empty($result['created']), 'customer' => $result['customer'], 'entry' => $entry]);

  case 'create-lead-from-email':
    $messageId = trim((string)($input['messageId'] ?? ''));
    if ($messageId === '') ogm_email_bad('Missing message id.');
    $msg = ogm_email_graph_message_for_archive($token, $messageId);
    $path = ogm_email_leads_path();
    $leads = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
    if (!is_array($leads)) $leads = [];
    $lead = [
      'id' => 'lead_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)),
      'messageId' => (string)($msg['id'] ?? $messageId),
      'subject' => (string)($msg['subject'] ?? '(no subject)'),
      'fromName' => (string)($msg['from']['emailAddress']['name'] ?? ''),
      'fromEmail' => (string)($msg['from']['emailAddress']['address'] ?? ''),
      'bodyText' => ogm_email_clean_text((string)($msg['body']['content'] ?? ''), 5000),
      'createdBy' => qtCurrentUser(),
      'createdUsername' => $username,
      'createdAt' => date('c'),
      'status' => 'new',
    ];
    $already = false;
    foreach ($leads as $existing) {
      if (($existing['messageId'] ?? '') === $lead['messageId']) {
        $already = true;
        $lead = $existing;
        break;
      }
    }
    if (!$already) $leads[] = $lead;
    @file_put_contents($path, json_encode($leads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($path, 0600);
    ogm_email_out(['ok' => true, 'lead' => $lead, 'created' => !$already]);

  case 'save-email-job-note':
    $messageId = trim((string)($input['messageId'] ?? ''));
    $taskId = trim((string)($input['taskId'] ?? ''));
    $taskName = trim((string)($input['taskName'] ?? ''));
    $customerId = trim((string)($input['customerId'] ?? ''));
    if ($messageId === '' || $taskId === '') ogm_email_bad('Choose an email and job first.');
    $apiKey = ogm_email_clickup_api_key();
    if ($apiKey === '') ogm_email_bad('ClickUp API key is not configured on the server.', 503);
    $msg = ogm_email_graph_message_for_archive($token, $messageId);
    $comment = "Email saved from OGM Email Center\n"
      . "Subject: " . (string)($msg['subject'] ?? '(no subject)') . "\n"
      . "From: " . (string)($msg['from']['emailAddress']['name'] ?? '') . " <" . (string)($msg['from']['emailAddress']['address'] ?? '') . ">\n"
      . "Date: " . (string)($msg['receivedDateTime'] ?? $msg['sentDateTime'] ?? '') . "\n\n"
      . ogm_email_clean_text((string)($msg['body']['content'] ?? ''), 6000);
    $commentRes = ogm_email_clickup_add_comment($apiKey, $taskId, $comment);
    if (($commentRes['_status'] ?? 0) < 200 || ($commentRes['_status'] ?? 0) >= 300) {
      ogm_email_bad('Could not save email note to ClickUp: ' . ($commentRes['err'] ?? $commentRes['_error'] ?? $commentRes['message'] ?? 'HTTP ' . ($commentRes['_status'] ?? 0)), 502);
    }
    $entry = null;
    if ($customerId !== '') {
      $entry = ogm_email_save_customer_history_entry($username, $token, $messageId, $customerId, 'follow', $taskId, $taskName);
    }
    ogm_email_out(['ok' => true, 'comment' => $commentRes, 'entry' => $entry]);


  case 'attachment-download':
    $messageId = trim((string)($_GET['messageId'] ?? $_GET['id'] ?? ''));
    $attachmentId = trim((string)($_GET['attachmentId'] ?? ''));
    $name = ogm_email_safe_filename((string)($_GET['name'] ?? 'email-attachment'));
    if ($messageId === '' || $attachmentId === '') ogm_email_bad('Missing attachment.');
    $download = ogm_graph_raw_call($token, 'GET', '/me/messages/' . rawurlencode($messageId) . '/attachments/' . rawurlencode($attachmentId) . '/$value');
    if (($download['_status'] ?? 0) !== 200 || !isset($download['_body'])) ogm_email_bad('Could not download attachment from Outlook.', 502);
    header_remove('Content-Type');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
    header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    echo (string)$download['_body'];
    exit;

  case 'attach-to-job':
    $messageId = trim((string)($input['messageId'] ?? ''));
    $attachmentId = trim((string)($input['attachmentId'] ?? ''));
    $taskId = trim((string)($input['taskId'] ?? ''));
    $taskName = trim((string)($input['taskName'] ?? ''));
    $name = ogm_email_safe_filename((string)($input['attachmentName'] ?? 'email-attachment'));
    if ($messageId === '' || $attachmentId === '' || $taskId === '') ogm_email_bad('Choose an attachment and job first.');
    $apiKey = ogm_email_clickup_api_key();
    if ($apiKey === '') ogm_email_bad('ClickUp API key is not configured on the server.', 503);
    $download = ogm_graph_raw_call($token, 'GET', '/me/messages/' . rawurlencode($messageId) . '/attachments/' . rawurlencode($attachmentId) . '/$value');
    if (($download['_status'] ?? 0) !== 200 || !isset($download['_body'])) ogm_email_bad('Could not download attachment from Outlook.', 502);
    $bytes = (string)$download['_body'];
    if (strlen($bytes) > 25 * 1024 * 1024) ogm_email_bad('Attachment is over the 25 MB limit for this handoff.');
    $tmp = tempnam(sys_get_temp_dir(), 'ogm-email-attach-');
    if ($tmp === false || @file_put_contents($tmp, $bytes) === false) ogm_email_bad('Could not prepare attachment upload.', 500);
    $mime = trim((string)($input['contentType'] ?? '')) ?: 'application/octet-stream';
    $upload = ogm_email_clickup_upload_attachment($apiKey, $taskId, $tmp, $name, $mime);
    @unlink($tmp);
    if (($upload['_status'] ?? 0) < 200 || ($upload['_status'] ?? 0) >= 300) {
      ogm_email_bad('ClickUp upload failed: ' . ($upload['err'] ?? $upload['_error'] ?? $upload['message'] ?? 'HTTP ' . ($upload['_status'] ?? 0)), 502);
    }
    $stateData = ogm_email_load_state($username);
    $stateData['attachmentLog'][] = [
      'messageId' => $messageId,
      'attachmentId' => $attachmentId,
      'attachmentName' => $name,
      'taskId' => $taskId,
      'taskName' => $taskName,
      'uploadedAt' => date('c'),
    ];
    ogm_email_save_state($username, $stateData);
    ogm_email_out(['ok' => true, 'upload' => $upload]);

  case 'save-attachment-to-customer':
    $messageId    = trim((string)($input['messageId']    ?? ''));
    $attachmentId = trim((string)($input['attachmentId'] ?? ''));
    $customerId   = ogm_email_safe_id((string)($input['customerId'] ?? ''));
    $name         = ogm_email_safe_filename((string)($input['attachmentName'] ?? 'email-attachment'));
    $subject      = substr(trim((string)($input['emailSubject'] ?? '')), 0, 200);
    if ($messageId === '' || $attachmentId === '' || $customerId === '') {
        ogm_email_bad('messageId, attachmentId, and customerId are required.');
    }
    if (!is_file(ogm_email_customer_path($customerId))) ogm_email_bad('Customer not found.', 404);
    $download = ogm_graph_raw_call($token, 'GET', '/me/messages/' . rawurlencode($messageId) . '/attachments/' . rawurlencode($attachmentId) . '/$value');
    if (($download['_status'] ?? 0) !== 200 || !isset($download['_body'])) {
        ogm_email_bad('Could not download attachment from Outlook.', 502);
    }
    $bytes = (string)$download['_body'];
    $size  = strlen($bytes);
    if ($size > 25 * 1024 * 1024) ogm_email_bad('Attachment exceeds 25 MB limit.', 413);
    $filesDir = __DIR__ . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . 'customer-files' . DIRECTORY_SEPARATOR . $customerId;
    if (!is_dir($filesDir) && !@mkdir($filesDir, 0700, true) && !is_dir($filesDir)) {
        ogm_email_bad('Could not create customer files directory.', 500);
    }
    // Avoid clobbering existing file with same name
    $saveName = $name;
    $attempt  = 1;
    while (is_file($filesDir . DIRECTORY_SEPARATOR . $saveName)) {
        $pi       = pathinfo($name);
        $saveName = ($pi['filename'] ?? 'file') . '-' . $attempt . (isset($pi['extension']) ? '.' . $pi['extension'] : '');
        $attempt++;
    }
    if (@file_put_contents($filesDir . DIRECTORY_SEPARATOR . $saveName, $bytes) === false) {
        ogm_email_bad('Could not save file to server.', 500);
    }
    @chmod($filesDir . DIRECTORY_SEPARATOR . $saveName, 0600);
    // Update manifest
    $manifestPath = $filesDir . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifest     = [];
    if (is_file($manifestPath)) {
        $m = json_decode((string)@file_get_contents($manifestPath), true);
        if (is_array($m)) $manifest = $m;
    }
    $manifest[] = [
        'filename'           => $saveName,
        'originalName'       => $name,
        'size'               => $size,
        'contentType'        => trim((string)($input['contentType'] ?? 'application/octet-stream')),
        'savedAt'            => date('c'),
        'savedBy'            => qtCurrentUser(),
        'sourceEmailSubject' => $subject,
        'sourceMessageId'    => $messageId,
    ];
    @file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($manifestPath, 0600);
    ogm_email_out(['ok' => true, 'filename' => $saveName, 'size' => $size]);

  case 'ai-draft-reply':
    if (!ogm_email_can_use_ai()) ogm_email_bad('AI drafting is limited to managers or permitted users.', 403);
    if (!ogm_ai_configured()) ogm_email_bad('AI drafting is not configured yet. Add the Claude API key on the server first.', 503);
    if (ogm_ai_today_call_count() >= ogm_ai_daily_limit()) {
      ogm_email_bad('AI drafting reached today\'s safety cap. It resets at midnight server time.', 429);
    }
    $messageId = trim((string)($input['messageId'] ?? ''));
    if ($messageId === '') ogm_email_bad('Missing message id.');
    $msg = ogm_graph_call($token, 'GET', '/me/messages/' . rawurlencode($messageId) . '?$select=id,subject,from,toRecipients,body,receivedDateTime,sentDateTime,conversationId');
    if (($msg['_status'] ?? 0) !== 200) ogm_email_bad('Could not load message for AI drafting.', 502);
    $fromAddr = strtolower((string)($msg['from']['emailAddress']['address'] ?? ''));
    $subject = (string)($msg['subject'] ?? '');
    if (ogm_email_is_automated_sender($fromAddr, $subject)) {
      ogm_email_bad('AI drafting is hidden for no-reply, bounce, and automated emails.', 400);
    }
    $thread = [];
    $convId = trim((string)($msg['conversationId'] ?? ''));
    if ($convId !== '') {
      $convQs = http_build_query([
        '$filter' => "conversationId eq '" . str_replace("'", "''", $convId) . "'",
        '$select' => 'id,subject,from,toRecipients,body,receivedDateTime,sentDateTime',
        '$orderby' => 'receivedDateTime desc',
        '$top' => 6,
      ]);
      $conv = ogm_graph_call($token, 'GET', '/me/messages?' . $convQs);
      if (($conv['_status'] ?? 0) === 200 && !empty($conv['value']) && is_array($conv['value'])) {
        $thread = array_reverse($conv['value']);
      }
    }
    if (!$thread) $thread = [$msg];
    $tokensNow = ogm_graph_load_tokens($username);
    $mailbox = strtolower(trim((string)($tokensNow['mailbox'] ?? '')));
    $repFullName = trim((string)($tokensNow['name'] ?? qtCurrentUser()));
    $repParts = preg_split('/\s+/', $repFullName);
    $repName = trim((string)($repParts[0] ?? ''));
    if ($repName === '') $repName = 'Tanya';
    $customerName = '';
    $customerAddr = '';
    for ($i = count($thread) - 1; $i >= 0; $i--) {
      $candidateAddr = strtolower(trim((string)($thread[$i]['from']['emailAddress']['address'] ?? '')));
      if ($candidateAddr === '' || $candidateAddr === $mailbox || strpos($candidateAddr, '@oliveglassandmarble.com') !== false) continue;
      $customerName = trim((string)($thread[$i]['from']['emailAddress']['name'] ?? ''));
      $customerAddr = $candidateAddr;
      break;
    }
    $convoText = '';
    foreach ($thread as $m) {
      $sender = trim((string)($m['from']['emailAddress']['name'] ?? $m['from']['emailAddress']['address'] ?? 'Unknown'));
      $whenRaw = (string)($m['receivedDateTime'] ?? $m['sentDateTime'] ?? '');
      $when = $whenRaw !== '' ? date('M j, Y g:ia', strtotime($whenRaw)) : '';
      $body = ogm_ai_clean_html((string)($m['body']['content'] ?? ''), 1500);
      if ($body === '') continue;
      $convoText .= "-----\nFROM: " . $sender . ($when ? " [" . $when . "]" : "") . "\n\n" . $body . "\n\n";
    }
    if (trim($convoText) === '') ogm_email_bad('This email does not have enough readable text for an AI draft.', 400);
    $prompt = "CUSTOMER: " . ($customerName ?: $customerAddr ?: 'Unknown') . "\n"
      . "REP: " . $repName . "\n\n"
      . "CONVERSATION HISTORY, OLDEST FIRST:\n\n"
      . $convoText
      . "-----\n\nDraft a reply to the most recent customer message. Sign off with just '" . $repName . "'.";
    $draft = ogm_ai_call($prompt);
    if (!empty($draft['_error'])) {
      ogm_email_bad('AI drafting unavailable: ' . (string)$draft['_error'], (int)($draft['_status'] ?? 502));
    }
    ogm_ai_log_call($username, $mailbox, (int)($draft['inTokens'] ?? 0), (int)($draft['outTokens'] ?? 0), (string)($draft['model'] ?? ogm_ai_model()));
    ogm_email_out([
      'ok' => true,
      'draft' => (string)($draft['draft'] ?? ''),
      'usage' => [
        'inputTokens' => (int)($draft['inTokens'] ?? 0),
        'outputTokens' => (int)($draft['outTokens'] ?? 0),
        'estCents' => round(ogm_ai_estimate_cents((int)($draft['inTokens'] ?? 0), (int)($draft['outTokens'] ?? 0)), 4),
      ],
    ]);

  case 'ai-search':
    if (!ogm_ai_configured()) {
      ogm_email_bad('AI search is not configured yet. Add the Claude API key on the server first.', 503);
    }
    if (ogm_ai_today_call_count() >= ogm_ai_daily_limit()) {
      ogm_email_bad('AI search reached today\'s safety cap. It resets at midnight server time.', 429);
    }
    $query = trim((string)($input['query'] ?? ''));
    if ($query === '') {
      ogm_email_bad('Missing search question.');
    }
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'customer-search-lib.php';
    $searchFolders = ogm_email_search_folders();

    $poolById = [];
    foreach ($searchFolders as $scanFolder) {
      for ($skip = 0; $skip < 100; $skip += 50) {
        $batch = ogm_email_fetch_folder_messages($token, $scanFolder, ['top' => 50, 'skip' => $skip]);
        if (!$batch) {
          break;
        }
        foreach ($batch as $msg) {
          $msg['folder'] = $scanFolder;
          $msg['folderLabel'] = ogm_email_folder_label($scanFolder);
          $poolById[$msg['id']] = $msg;
        }
        if (count($batch) < 50) {
          break;
        }
      }
    }
    $graphTerms = ogm_email_ai_graph_search_terms($query);
    if ($graphTerms !== '') {
      foreach (ogm_email_search_mailboxes($token, $graphTerms, $searchFolders, 25) as $msg) {
        $poolById[$msg['id']] = $msg;
      }
    }
    $totalScanned = count($poolById);

    $matched = [];
    foreach ($poolById as $msg) {
      $haystack = trim(($msg['from'] ?? '') . ' ' . ($msg['fromAddr'] ?? '') . ' ' . ($msg['subject'] ?? '') . ' ' . ($msg['preview'] ?? ''));
      $score = ogmSearchScoreNaturalQuery($haystack, $query);
      if ($score > 0) {
        $matched[] = array_merge($msg, ['score' => $score]);
      }
    }
    usort($matched, static fn($a, $b) => ($b['score'] <=> $a['score']));
    $candidates = array_slice($matched, 0, 20);
    $usedRecentFallback = false;
    if (!$candidates && $poolById) {
      $recent = array_values($poolById);
      usort($recent, static fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
      $candidates = array_slice($recent, 0, 25);
      $usedRecentFallback = true;
    }

    $ctxParts = [];
    $currentEmail = $input['currentEmail'] ?? null;
    if (is_array($currentEmail) && !empty($currentEmail['subject'])) {
      $ctxParts[] = "=== EMAIL CURRENTLY OPEN IN EMAIL CENTER ===\n"
        . 'Subject: ' . ($currentEmail['subject'] ?? '') . "\n"
        . 'From: ' . ($currentEmail['from'] ?? '') . "\n"
        . 'Date: ' . ($currentEmail['date'] ?? '') . "\n"
        . 'Body: ' . substr((string)($currentEmail['body'] ?? ''), 0, 1000);
    }
    $folderLabel = 'Inbox, Sent, and Drafts';
    if ($candidates) {
      $ctxParts[] = '=== LIVE MAILBOX — ' . $folderLabel . ' — ' . count($candidates) . ' MESSAGE(S) ===';
      foreach ($candidates as $i => $c) {
        $fromLabel = trim(($c['from'] ?? '') . ' ' . ($c['fromAddr'] ?? ''));
        $ctxParts[] = 'Email ' . ($i + 1) . ' [' . ($c['folderLabel'] ?? 'Mail') . "]\n"
          . 'Subject: ' . ($c['subject'] ?? '(no subject)') . "\n"
          . 'From: ' . $fromLabel . "\n"
          . 'Date: ' . ($c['date'] ?? '') . "\n"
          . 'Preview: ' . substr((string)($c['preview'] ?? ''), 0, 900);
      }
    } else {
      $ctxParts[] = 'No mailbox messages were loaded from ' . $folderLabel . '.';
    }

    $prompt = "MAILBOX SCOPE: $folderLabel\n"
      . 'MESSAGES SCANNED: ' . $totalScanned . "\n"
      . 'QUESTION: ' . $query . "\n\n"
      . implode("\n\n---\n\n", $ctxParts);
    $result = ogm_ai_search_answer($prompt);
    if (!empty($result['_error'])) {
      ogm_email_bad('AI search unavailable: ' . (string)$result['_error'], (int)($result['_status'] ?? 502));
    }
    $tokensNow = ogm_graph_load_tokens($username);
    $mailbox = strtolower(trim((string)($tokensNow['mailbox'] ?? '')));
    ogm_ai_log_call($username, $mailbox, (int)($result['inTokens'] ?? 0), (int)($result['outTokens'] ?? 0), (string)($result['model'] ?? ogm_ai_model()));
    ogm_email_out([
      'ok' => true,
      'answer' => (string)($result['text'] ?? ''),
      'matched' => count($matched),
      'totalScanned' => $totalScanned,
      'scope' => $searchFolders,
      'usedRecentFallback' => $usedRecentFallback,
      'inTokens' => (int)($result['inTokens'] ?? 0),
      'outTokens' => (int)($result['outTokens'] ?? 0),
    ]);

  case 'ai-usage-stats':
    if (!ogm_email_can_manage_templates()) ogm_email_bad('Only managers can view AI usage.', 403);
    $log = ogm_ai_load_log();
    $today = ogm_ai_today_key();
    $week = ['calls' => 0, 'inTokens' => 0, 'outTokens' => 0, 'estCents' => 0];
    $cutoff = strtotime('-7 days');
    foreach ($log as $day => $stats) {
      if (strtotime((string)$day) < $cutoff || !is_array($stats)) continue;
      $week['calls'] += (int)($stats['calls'] ?? 0);
      $week['inTokens'] += (int)($stats['inTokens'] ?? 0);
      $week['outTokens'] += (int)($stats['outTokens'] ?? 0);
    }
    $week['estCents'] = round(ogm_ai_estimate_cents((int)$week['inTokens'], (int)$week['outTokens']), 2);
    ogm_email_out([
      'ok' => true,
      'today' => $log[$today] ?? ['calls' => 0],
      'thisWeek' => $week,
      'dailyLimit' => ogm_ai_daily_limit(),
      'usedToday' => ogm_ai_today_call_count(),
    ]);


  case 'send':
    $to = ogm_email_recipients_out($input['to'] ?? []);
    if (!$to) ogm_email_bad('Add at least one valid recipient.');
    $subject = trim((string)($input['subject'] ?? ''));
    $html = (string)($input['html'] ?? '');
    if ($subject === '' && trim(strip_tags($html)) === '') ogm_email_bad('Nothing to send.');
    $message = ['subject' => $subject, 'body' => ['contentType' => 'HTML', 'content' => $html], 'toRecipients' => $to];
    $cc = ogm_email_recipients_out($input['cc'] ?? []);
    if ($cc) $message['ccRecipients'] = $cc;
    $attachments = ogm_email_uploaded_attachments();
    if ($attachments) {
      $draft = ogm_graph_call($token, 'POST', '/me/messages', $message);
      if (($draft['_status'] ?? 0) < 200 || ($draft['_status'] ?? 0) >= 300 || empty($draft['id'])) {
        ogm_email_bad('Could not prepare email draft: ' . ($draft['error']['message'] ?? 'Graph error ' . ($draft['_status'] ?? 0)), 502);
      }
      $draftId = (string)$draft['id'];
      ogm_email_graph_attach_files($token, $draftId, $attachments);
      $send = ogm_graph_call($token, 'POST', '/me/messages/' . rawurlencode($draftId) . '/send');
      if (($send['_status'] ?? 0) !== 202) ogm_email_bad('Send failed: ' . ($send['error']['message'] ?? 'Graph error ' . ($send['_status'] ?? 0)), 502);
      ogm_email_out(['ok' => true]);
    }
    $payload = ['message' => $message, 'saveToSentItems' => true];
    $res = ogm_graph_call($token, 'POST', '/me/sendMail', $payload);
    if (($res['_status'] ?? 0) !== 202) ogm_email_bad('Send failed: ' . ($res['error']['message'] ?? 'Graph error ' . ($res['_status'] ?? 0)), 502);
    ogm_email_out(['ok' => true]);

  case 'reply':
    $id = (string)($input['id'] ?? '');
    if ($id === '') ogm_email_bad('Missing message id.');
    $html = (string)($input['html'] ?? '');
    if (trim(strip_tags($html)) === '') ogm_email_bad('Reply is empty.');
    $attachments = ogm_email_uploaded_attachments();
    $replyAll = !empty($input['all']);
    if ($attachments) {
      ogm_email_graph_reply_with_attachments($token, $id, $html, $replyAll, $attachments);
      ogm_email_out(['ok' => true]);
    }
    $endpoint = $replyAll ? '/replyAll' : '/reply';
    $res = ogm_graph_call($token, 'POST', '/me/messages/' . rawurlencode($id) . $endpoint, ['message' => ['body' => ['contentType' => 'HTML', 'content' => $html]]]);
    if (($res['_status'] ?? 0) !== 202) ogm_email_bad('Reply failed: ' . ($res['error']['message'] ?? 'Graph error ' . ($res['_status'] ?? 0)), 502);
    ogm_email_out(['ok' => true]);

  default:
    ogm_email_bad('Unknown action.', 404);
}
