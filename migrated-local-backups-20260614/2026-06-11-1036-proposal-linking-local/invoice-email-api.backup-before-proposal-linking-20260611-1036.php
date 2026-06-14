<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'POST required.']);
  exit;
}

if (!qtIsLoggedIn()) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
  exit;
}

if (!function_exists('mail')) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Mail is not available on this server.']);
  exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid JSON.']);
  exit;
}

$inv = isset($payload['invoice']) && is_array($payload['invoice']) ? $payload['invoice'] : [];
$quote = isset($inv['quoteData']) && is_array($inv['quoteData']) ? $inv['quoteData'] : [];
$to = trim((string)($quote['email'] ?? $payload['to'] ?? ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Customer email is missing or invalid.']);
  exit;
}

$invoiceNumber = ogmInvoiceCleanText($inv['invoiceNumber'] ?? '');
if ($invoiceNumber === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invoice number is required.']);
  exit;
}

$emailTemplate = ogmInvoiceNormalizeEmailTemplate($inv['emailTemplate'] ?? []);
$customerName = ogmInvoiceCleanText($quote['name'] ?? 'Customer');
$subject = ogmInvoiceApplyEmailTemplate($emailTemplate['subjectTemplate'], $inv);
$html = ogmInvoiceEmailHtml($inv, $emailTemplate);
$plain = ogmInvoiceEmailPlainText($inv, $emailTemplate);
$pdf = ogmInvoicePdf($inv);
$filename = 'OGM-Invoice-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoiceNumber) . '.pdf';

$ok = ogmSendInvoiceMail($to, $subject, $plain, $html, $pdf, $filename);
ogmInvoiceEmailLog([
  'ok' => (bool)$ok,
  'to' => $to,
  'invoiceNumber' => $invoiceNumber,
  'subject' => $subject,
  'pdfBytes' => strlen($pdf),
  'customer' => $customerName,
]);
if (!$ok) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Server could not send the email.']);
  exit;
}

echo json_encode(['ok' => true, 'to' => $to]);

function ogmInvoiceCleanText($value) {
  $s = trim((string)$value);
  $s = preg_replace('/[\r\n\t]+/', ' ', $s);
  $s = preg_replace('/\s{2,}/', ' ', $s);
  return trim($s);
}

function ogmInvoiceHeaderText($value) {
  return str_replace(["\r", "\n"], '', ogmInvoiceCleanText($value));
}

function ogmInvoiceH($value) {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ogmInvoiceEmailLog($entry) {
  $dir = __DIR__ . DIRECTORY_SEPARATOR . '.data';
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
  if (!is_dir($dir) || !is_writable($dir)) {
    return;
  }
  $entry = is_array($entry) ? $entry : [];
  $entry['at'] = gmdate('c');
  $entry['ip'] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  $path = $dir . DIRECTORY_SEPARATOR . 'invoice-email-log.jsonl';
  @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

function ogmInvoiceSmtpConfig() {
  $path = __DIR__ . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . 'invoice-email-smtp.php';
  if (!is_file($path)) {
    return null;
  }
  $cfg = require $path;
  if (!is_array($cfg)) {
    return null;
  }
  $host = trim((string)($cfg['host'] ?? ''));
  $user = trim((string)($cfg['username'] ?? ''));
  $pass = (string)($cfg['password'] ?? '');
  if ($host === '' || $user === '' || $pass === '') {
    return null;
  }
  return [
    'host' => $host,
    'port' => (int)($cfg['port'] ?? 587),
    'secure' => strtolower(trim((string)($cfg['secure'] ?? 'tls'))),
    'username' => $user,
    'password' => $pass,
    'from_email' => trim((string)($cfg['from_email'] ?? $user)),
    'from_name' => trim((string)($cfg['from_name'] ?? 'Olive Glass & Marble')),
    'timeout' => (int)($cfg['timeout'] ?? 20),
  ];
}

function ogmInvoiceMoney($value) {
  $n = is_numeric($value) ? (float)$value : 0.0;
  return '$' . number_format($n, 2);
}

function ogmInvoiceDateLabel($value) {
  $s = trim((string)$value);
  if ($s === '') {
    return '';
  }
  $ts = strtotime($s . ' 00:00:00');
  return $ts ? date('F j, Y', $ts) : $s;
}

function ogmInvoiceNormalizeEmailTemplate($raw) {
  $raw = is_array($raw) ? $raw : [];
  $layout = trim((string)($raw['layoutStyle'] ?? 'classic'));
  if ($layout !== 'simple') {
    $layout = 'classic';
  }
  $out = [
    'layoutStyle' => $layout,
    'subjectTemplate' => trim((string)($raw['subjectTemplate'] ?? '')),
    'introTemplate' => trim((string)($raw['introTemplate'] ?? '')),
    'closingTemplate' => trim((string)($raw['closingTemplate'] ?? '')),
    'pdfNote' => trim((string)($raw['pdfNote'] ?? '')),
  ];
  if ($out['subjectTemplate'] === '') {
    $out['subjectTemplate'] = 'Invoice {{invoiceNumber}} - {{customerName}}';
  }
  if ($out['introTemplate'] === '') {
    $out['introTemplate'] = "Hi {{customerName}},\n\nPlease find invoice {{invoiceNumber}} for {{amountDue}}. A copy of the invoice is below and the PDF is attached.";
  }
  if ($out['closingTemplate'] === '') {
    $out['closingTemplate'] = "Thank you,\nOlive Glass & Marble";
  }
  if ($out['pdfNote'] === '') {
    $out['pdfNote'] = 'A PDF copy of this invoice is attached.';
  }
  return $out;
}

function ogmInvoiceTemplateValues($inv) {
  $quote = isset($inv['quoteData']) && is_array($inv['quoteData']) ? $inv['quoteData'] : [];
  $deposit = is_numeric($inv['depositApplied'] ?? null) ? (float)$inv['depositApplied'] : 0.0;
  $total = is_numeric($inv['total'] ?? null) ? (float)$inv['total'] : 0.0;
  $balance = is_numeric($inv['balanceDue'] ?? null) ? (float)$inv['balanceDue'] : max(0, $total - $deposit);
  $amountDue = $deposit > 0.004 ? $balance : $total;
  $customerName = ogmInvoiceCleanText($quote['name'] ?? 'Customer');
  return [
    'customerName' => $customerName !== '' ? $customerName : 'Customer',
    'invoiceNumber' => ogmInvoiceCleanText($inv['invoiceNumber'] ?? ''),
    'invoiceDate' => ogmInvoiceDateLabel($inv['invoiceDate'] ?? ''),
    'amountDue' => ogmInvoiceMoney($amountDue),
    'total' => ogmInvoiceMoney($total),
    'balanceDue' => ogmInvoiceMoney($balance),
    'deposit' => ogmInvoiceMoney($deposit),
    'dueDate' => ogmInvoiceDateLabel($inv['dueDate'] ?? ''),
    'jobName' => ogmInvoiceCleanText($quote['job'] ?? ''),
    'salesperson' => ogmInvoiceCleanText($quote['sp'] ?? ''),
  ];
}

function ogmInvoiceApplyEmailTemplate($template, $inv) {
  $values = ogmInvoiceTemplateValues($inv);
  return preg_replace_callback('/\{\{([A-Za-z0-9_]+)\}\}/', function ($m) use ($values) {
    $key = $m[1] ?? '';
    return array_key_exists($key, $values) ? $values[$key] : $m[0];
  }, (string)$template);
}

function ogmInvoiceMessageHtml($text) {
  $lines = preg_split('/\R/', (string)$text);
  $out = [];
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') {
      $out[] = '<br>';
    } else {
      $out[] = ogmInvoiceH($line);
    }
  }
  return implode('<br>', $out);
}

function ogmInvoiceEmailHtml($inv, $emailTemplate = []) {
  $emailTemplate = ogmInvoiceNormalizeEmailTemplate($emailTemplate);
  $quote = isset($inv['quoteData']) && is_array($inv['quoteData']) ? $inv['quoteData'] : [];
  $lines = isset($inv['lines']) && is_array($inv['lines']) ? $inv['lines'] : [];
  $invoiceNumber = ogmInvoiceCleanText($inv['invoiceNumber'] ?? '');
  $invoiceDate = ogmInvoiceDateLabel($inv['invoiceDate'] ?? '');
  $terms = ogmInvoiceCleanText($inv['terms'] ?? '');
  $dueDate = ogmInvoiceDateLabel($inv['dueDate'] ?? '');
  $po = ogmInvoiceCleanText($inv['poNumber'] ?? '');
  $memo = ogmInvoiceCleanText($inv['memo'] ?? '');
  $deposit = is_numeric($inv['depositApplied'] ?? null) ? (float)$inv['depositApplied'] : 0.0;
  $total = is_numeric($inv['total'] ?? null) ? (float)$inv['total'] : 0.0;
  $balance = is_numeric($inv['balanceDue'] ?? null) ? (float)$inv['balanceDue'] : max(0, $total - $deposit);
  $billAddr = array_values(array_filter([
    ogmInvoiceCleanText($quote['addr'] ?? ''),
    ogmInvoiceCleanText($quote['city'] ?? ''),
  ]));
  $shipAddr = array_values(array_filter([
    ogmInvoiceCleanText($quote['installAddr'] ?? ''),
    ogmInvoiceCleanText($quote['installCity'] ?? ''),
  ]));
  $rows = '';
  foreach ($lines as $line) {
    if (!is_array($line)) {
      continue;
    }
    $desc = ogmInvoiceCleanText($line['description'] ?? $line['item'] ?? '');
    $amount = is_numeric($line['amount'] ?? null) ? (float)$line['amount'] : 0.0;
    $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #eae6da;color:#4a4538;">' . ogmInvoiceH($desc) . '</td><td style="padding:8px 0;border-bottom:1px solid #eae6da;text-align:right;color:#1c1917;">' . ogmInvoiceH(ogmInvoiceMoney($amount)) . '</td></tr>';
  }
  if ($rows === '') {
    $rows = '<tr><td style="padding:8px 0;border-bottom:1px solid #eae6da;color:#7a7260;">No billable lines</td><td style="padding:8px 0;border-bottom:1px solid #eae6da;text-align:right;color:#1c1917;">$0.00</td></tr>';
  }
  $amountDue = $deposit > 0.004 ? $balance : $total;
  $simple = $emailTemplate['layoutStyle'] === 'simple';
  $bodyStyle = $simple
    ? 'margin:0;background:#ffffff;padding:18px;font-family:Arial,sans-serif;color:#1c1917;'
    : 'margin:0;background:#f7f5ef;padding:24px;font-family:Arial,sans-serif;color:#1c1917;';
  $cardStyle = $simple
    ? 'max-width:720px;margin:0 auto;background:#fff;padding:0;'
    : 'max-width:720px;margin:0 auto;background:#fff;padding:28px;border:1px solid #eae6da;';
  $intro = ogmInvoiceMessageHtml(ogmInvoiceApplyEmailTemplate($emailTemplate['introTemplate'], $inv));
  $closing = ogmInvoiceMessageHtml(ogmInvoiceApplyEmailTemplate($emailTemplate['closingTemplate'], $inv));
  $pdfNote = ogmInvoiceApplyEmailTemplate($emailTemplate['pdfNote'], $inv);
  return '<!DOCTYPE html><html><body style="' . $bodyStyle . '">'
    . '<div style="' . $cardStyle . '">'
    . ($intro !== '' ? '<div style="font-size:14px;line-height:1.65;color:#2f2a22;margin-bottom:24px;">' . $intro . '</div>' : '')
    . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><tr>'
    . '<td style="vertical-align:top;"><div style="font-family:Georgia,serif;font-size:32px;letter-spacing:.08em;">OGM</div><div style="font-size:10px;letter-spacing:.18em;color:#7a7260;">OLIVE GLASS &amp; MARBLE</div><div style="font-size:12px;line-height:1.7;color:#4a4538;margin-top:8px;">714 Robeson Street<br>Fayetteville, NC 28305<br>(910) 484-5277</div></td>'
    . '<td style="vertical-align:top;text-align:right;"><div style="font-family:Georgia,serif;font-size:26px;color:#7a7260;">Invoice</div><div style="font-size:12px;line-height:1.8;color:#4a4538;margin-top:8px;">Invoice #: ' . ogmInvoiceH($invoiceNumber) . '<br>Date: ' . ogmInvoiceH($invoiceDate) . '<br>Terms: ' . ogmInvoiceH($terms) . ($dueDate ? '<br>Due: ' . ogmInvoiceH($dueDate) : '') . ($po ? '<br>PO #: ' . ogmInvoiceH($po) : '') . '</div></td>'
    . '</tr></table>'
    . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:24px;border-top:1px solid #eae6da;border-bottom:1px solid #eae6da;"><tr>'
    . '<td style="width:50%;vertical-align:top;padding:16px 20px 16px 0;"><div style="font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:#b8b09c;margin-bottom:8px;">Bill To</div><div style="font-size:15px;color:#1c1917;">' . ogmInvoiceH($quote['name'] ?? '') . '</div><div style="font-size:12px;line-height:1.7;color:#4a4538;margin-top:6px;">' . ogmInvoiceH(implode(', ', $billAddr)) . '<br>' . ogmInvoiceH($quote['phone'] ?? '') . '<br>' . ogmInvoiceH($quote['email'] ?? '') . '</div></td>'
    . '<td style="width:50%;vertical-align:top;padding:16px 0 16px 20px;"><div style="font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:#b8b09c;margin-bottom:8px;">Ship To</div><div style="font-size:12px;line-height:1.7;color:#4a4538;">' . ogmInvoiceH($shipAddr ? implode(', ', $shipAddr) : implode(', ', $billAddr)) . '</div>' . (!empty($quote['job']) ? '<div style="font-size:12px;color:#1c1917;margin-top:8px;"><strong>Job:</strong> ' . ogmInvoiceH($quote['job']) . '</div>' : '') . '</td>'
    . '</tr></table>'
    . '<div style="margin-top:22px;font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:#b8b09c;">Line Items</div>'
    . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:6px;font-size:13px;">' . $rows
    . ($deposit > 0.004 ? '<tr><td style="padding:10px 0;color:#4a4538;">Subtotal</td><td style="padding:10px 0;text-align:right;color:#1c1917;">' . ogmInvoiceH(ogmInvoiceMoney($total)) . '</td></tr><tr><td style="padding:8px 0;color:#4a4538;">Less: deposit received</td><td style="padding:8px 0;text-align:right;color:#1c1917;">-' . ogmInvoiceH(ogmInvoiceMoney($deposit)) . '</td></tr>' : '')
    . '<tr><td style="padding:14px 0 0;border-top:2px solid #cbbf9d;font-family:Georgia,serif;font-size:20px;color:#7a7260;">' . ($deposit > 0.004 ? 'Balance due' : 'Total due') . '</td><td style="padding:14px 0 0;border-top:2px solid #cbbf9d;text-align:right;font-family:Georgia,serif;font-size:30px;color:#9e7c3a;">' . ogmInvoiceH(ogmInvoiceMoney($amountDue)) . '</td></tr>'
    . '</table>'
    . ($memo ? '<div style="margin-top:24px;padding-top:14px;border-top:1px solid #eae6da;"><div style="font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:#b8b09c;margin-bottom:8px;">Memo</div><div style="font-size:12px;line-height:1.7;color:#4a4538;">' . ogmInvoiceH($memo) . '</div></div>' : '')
    . ($closing !== '' ? '<div style="margin-top:24px;padding-top:14px;border-top:1px solid #eae6da;font-size:14px;line-height:1.65;color:#2f2a22;">' . $closing . '</div>' : '')
    . '<div style="margin-top:24px;padding-top:14px;border-top:1px solid #eae6da;text-align:center;font-size:12px;line-height:1.7;color:#7a7260;">' . ogmInvoiceH($pdfNote) . '</div>'
    . '</div></body></html>';
}

function ogmInvoiceEmailPlainText($inv, $emailTemplate = []) {
  $emailTemplate = ogmInvoiceNormalizeEmailTemplate($emailTemplate);
  $quote = isset($inv['quoteData']) && is_array($inv['quoteData']) ? $inv['quoteData'] : [];
  $lines = isset($inv['lines']) && is_array($inv['lines']) ? $inv['lines'] : [];
  $deposit = is_numeric($inv['depositApplied'] ?? null) ? (float)$inv['depositApplied'] : 0.0;
  $total = is_numeric($inv['total'] ?? null) ? (float)$inv['total'] : 0.0;
  $balance = is_numeric($inv['balanceDue'] ?? null) ? (float)$inv['balanceDue'] : max(0, $total - $deposit);
  $out = [];
  $intro = trim(ogmInvoiceApplyEmailTemplate($emailTemplate['introTemplate'], $inv));
  if ($intro !== '') {
    $out[] = $intro;
    $out[] = '';
  }
  $out[] = 'Olive Glass & Marble';
  $out[] = 'Invoice ' . ogmInvoiceCleanText($inv['invoiceNumber'] ?? '');
  $out[] = 'Date: ' . ogmInvoiceDateLabel($inv['invoiceDate'] ?? '');
  $out[] = 'Customer: ' . ogmInvoiceCleanText($quote['name'] ?? '');
  if (!empty($quote['job'])) {
    $out[] = 'Job: ' . ogmInvoiceCleanText($quote['job']);
  }
  $out[] = '';
  $out[] = 'Line Items:';
  foreach ($lines as $line) {
    if (!is_array($line)) {
      continue;
    }
    $out[] = '- ' . ogmInvoiceCleanText($line['description'] ?? $line['item'] ?? '') . ': ' . ogmInvoiceMoney($line['amount'] ?? 0);
  }
  $out[] = '';
  $out[] = $deposit > 0.004 ? ('Subtotal: ' . ogmInvoiceMoney($total)) : ('Total due: ' . ogmInvoiceMoney($total));
  if ($deposit > 0.004) {
    $out[] = 'Less deposit: -' . ogmInvoiceMoney($deposit);
    $out[] = 'Balance due: ' . ogmInvoiceMoney($balance);
  }
  if (!empty($inv['memo'])) {
    $out[] = '';
    $out[] = 'Memo: ' . ogmInvoiceCleanText($inv['memo']);
  }
  $out[] = '';
  $closing = trim(ogmInvoiceApplyEmailTemplate($emailTemplate['closingTemplate'], $inv));
  if ($closing !== '') {
    $out[] = $closing;
    $out[] = '';
  }
  $out[] = ogmInvoiceApplyEmailTemplate($emailTemplate['pdfNote'], $inv);
  return implode("\n", $out);
}

function ogmSendInvoiceMail($to, $subject, $plain, $html, $pdf, $filename) {
  $mixed = 'ogm-invoice-mixed-' . bin2hex(random_bytes(12));
  $alt = 'ogm-invoice-alt-' . bin2hex(random_bytes(12));
  $fromEmail = 'info@oliveglassandmarble.com';
  $from = '"Olive Glass & Marble" <' . $fromEmail . '>';
  $smtp = ogmInvoiceSmtpConfig();
  if ($smtp) {
    $fromEmail = $smtp['from_email'] ?: $smtp['username'];
    $fromName = $smtp['from_name'] ?: 'Olive Glass & Marble';
    $from = '"' . addcslashes($fromName, '"\\') . '" <' . $fromEmail . '>';
  }
  $headers = [];
  $headers[] = 'From: ' . $from;
  $headers[] = 'Reply-To: ' . $from;
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixed . '"';

  $parts = [];
  $parts[] = '--' . $mixed;
  $parts[] = 'Content-Type: multipart/alternative; boundary="' . $alt . '"';
  $parts[] = '';
  $parts[] = '--' . $alt;
  $parts[] = 'Content-Type: text/plain; charset=UTF-8';
  $parts[] = 'Content-Transfer-Encoding: 8bit';
  $parts[] = '';
  $parts[] = $plain;
  $parts[] = '--' . $alt;
  $parts[] = 'Content-Type: text/html; charset=UTF-8';
  $parts[] = 'Content-Transfer-Encoding: 8bit';
  $parts[] = '';
  $parts[] = $html;
  $parts[] = '--' . $alt . '--';
  $parts[] = '';
  $parts[] = '--' . $mixed;
  $parts[] = 'Content-Type: application/pdf; name="' . ogmInvoiceHeaderText($filename) . '"';
  $parts[] = 'Content-Transfer-Encoding: base64';
  $parts[] = 'Content-Disposition: attachment; filename="' . ogmInvoiceHeaderText($filename) . '"';
  $parts[] = '';
  $parts[] = chunk_split(base64_encode($pdf), 76, "\r\n");
  $parts[] = '--' . $mixed . '--';
  $parts[] = '';

  $safeSubject = ogmInvoiceHeaderText($subject);
  $body = implode("\r\n", $parts);
  $headerText = implode("\r\n", $headers);
  if ($smtp) {
    return ogmInvoiceSendSmtp($smtp, $fromEmail, $to, $safeSubject, $headerText, $body);
  }
  $sent = @mail($to, $safeSubject, $body, $headerText, '-f' . $fromEmail);
  if (!$sent) {
    $sent = @mail($to, $safeSubject, $body, $headerText);
  }
  return $sent;
}

function ogmInvoiceSmtpRead($fp) {
  $data = '';
  while (!feof($fp)) {
    $line = fgets($fp, 515);
    if ($line === false) {
      break;
    }
    $data .= $line;
    if (strlen($line) >= 4 && $line[3] === ' ') {
      break;
    }
  }
  return $data;
}

function ogmInvoiceSmtpCmd($fp, $cmd, $expect) {
  if ($cmd !== null) {
    fwrite($fp, $cmd . "\r\n");
  }
  $resp = ogmInvoiceSmtpRead($fp);
  $code = (int)substr($resp, 0, 3);
  $expects = is_array($expect) ? $expect : [$expect];
  if (!in_array($code, $expects, true)) {
    throw new RuntimeException('SMTP error after ' . ($cmd ?: 'connect') . ': ' . trim($resp));
  }
  return $resp;
}

function ogmInvoiceSmtpAddress($addr) {
  $addr = trim((string)$addr);
  return str_replace(["\r", "\n", '<', '>'], '', $addr);
}

function ogmInvoiceSendSmtp($cfg, $fromEmail, $to, $subject, $headers, $body) {
  $host = $cfg['host'];
  $port = $cfg['port'] ?: 587;
  $secure = $cfg['secure'] ?: 'tls';
  $timeout = $cfg['timeout'] ?: 20;
  $target = ($secure === 'ssl' ? 'ssl://' : '') . $host;
  $errno = 0;
  $errstr = '';
  $fp = @fsockopen($target, $port, $errno, $errstr, $timeout);
  if (!$fp) {
    ogmInvoiceEmailLog(['ok' => false, 'transport' => 'smtp', 'stage' => 'connect', 'host' => $host, 'port' => $port, 'error' => $errstr ?: ('errno ' . $errno)]);
    return false;
  }
  stream_set_timeout($fp, $timeout);
  try {
    ogmInvoiceSmtpCmd($fp, null, 220);
    ogmInvoiceSmtpCmd($fp, 'EHLO oliveglassandmarble.com', 250);
    if ($secure === 'tls') {
      ogmInvoiceSmtpCmd($fp, 'STARTTLS', 220);
      if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        throw new RuntimeException('Could not enable TLS');
      }
      ogmInvoiceSmtpCmd($fp, 'EHLO oliveglassandmarble.com', 250);
    }
    ogmInvoiceSmtpCmd($fp, 'AUTH LOGIN', 334);
    ogmInvoiceSmtpCmd($fp, base64_encode($cfg['username']), 334);
    ogmInvoiceSmtpCmd($fp, base64_encode($cfg['password']), 235);
    ogmInvoiceSmtpCmd($fp, 'MAIL FROM:<' . ogmInvoiceSmtpAddress($fromEmail) . '>', 250);
    ogmInvoiceSmtpCmd($fp, 'RCPT TO:<' . ogmInvoiceSmtpAddress($to) . '>', [250, 251]);
    ogmInvoiceSmtpCmd($fp, 'DATA', 354);
    $message = 'To: <' . ogmInvoiceSmtpAddress($to) . ">\r\n"
      . 'Subject: ' . $subject . "\r\n"
      . $headers . "\r\n\r\n"
      . $body;
    $message = str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $message);
    fwrite($fp, $message . "\r\n.\r\n");
    ogmInvoiceSmtpCmd($fp, null, 250);
    ogmInvoiceSmtpCmd($fp, 'QUIT', [221, 250]);
    fclose($fp);
    ogmInvoiceEmailLog(['ok' => true, 'transport' => 'smtp', 'host' => $host, 'port' => $port, 'to' => $to]);
    return true;
  } catch (Throwable $e) {
    ogmInvoiceEmailLog(['ok' => false, 'transport' => 'smtp', 'host' => $host, 'port' => $port, 'to' => $to, 'error' => $e->getMessage()]);
    @fclose($fp);
    return false;
  }
}

function ogmPdfAscii($value) {
  $s = (string)$value;
  $s = str_replace(["\xE2\x80\x94", "\xE2\x80\x93", "\xE2\x80\x99", "\xE2\x80\x98", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xC2\xB7"], ['-', '-', "'", "'", '"', '"', '-'], $s);
  if (function_exists('iconv')) {
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($converted !== false) {
      $s = $converted;
    }
  }
  return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $s);
}

function ogmPdfEscape($value) {
  $s = ogmPdfAscii($value);
  $s = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
  return $s;
}

function ogmPdfText($x, $y, $text, $size = 10, $font = 'F1') {
  return 'BT /' . $font . ' ' . (float)$size . ' Tf 1 0 0 1 ' . (float)$x . ' ' . (float)$y . ' Tm (' . ogmPdfEscape($text) . ") Tj ET\n";
}

function ogmPdfLine($x1, $y1, $x2, $y2) {
  return (float)$x1 . ' ' . (float)$y1 . ' m ' . (float)$x2 . ' ' . (float)$y2 . " l S\n";
}

function ogmPdfWrap($text, $chars) {
  $text = trim(preg_replace('/\s+/', ' ', ogmPdfAscii($text)));
  if ($text === '') {
    return [''];
  }
  $words = explode(' ', $text);
  $lines = [];
  $cur = '';
  foreach ($words as $word) {
    if ($cur === '') {
      $cur = $word;
      continue;
    }
    if (strlen($cur . ' ' . $word) <= $chars) {
      $cur .= ' ' . $word;
    } else {
      $lines[] = $cur;
      $cur = $word;
    }
  }
  if ($cur !== '') {
    $lines[] = $cur;
  }
  return $lines ?: [''];
}

function ogmInvoicePdf($inv) {
  $quote = isset($inv['quoteData']) && is_array($inv['quoteData']) ? $inv['quoteData'] : [];
  $lines = isset($inv['lines']) && is_array($inv['lines']) ? $inv['lines'] : [];
  $pages = [];
  $ops = [];
  $y = 748;
  $newPage = function () use (&$pages, &$ops, &$y) {
    if ($ops) {
      $pages[] = $ops;
    }
    $ops = [];
    $y = 748;
  };
  $ensure = function ($need) use (&$y, $newPage) {
    if ($y < $need) {
      $newPage();
    }
  };
  $add = function ($text, $size = 10, $font = 'F1', $x = 54, $gap = 15) use (&$ops, &$y, $ensure) {
    $ensure(64);
    $ops[] = ogmPdfText($x, $y, $text, $size, $font);
    $y -= $gap;
  };
  $addWrapped = function ($text, $x = 54, $chars = 82, $size = 10, $gap = 13) use (&$ops, &$y, $ensure) {
    foreach (ogmPdfWrap($text, $chars) as $line) {
      $ensure(64);
      $ops[] = ogmPdfText($x, $y, $line, $size, 'F1');
      $y -= $gap;
    }
  };

  $invoiceNumber = ogmInvoiceCleanText($inv['invoiceNumber'] ?? '');
  $invoiceDate = ogmInvoiceDateLabel($inv['invoiceDate'] ?? '');
  $terms = ogmInvoiceCleanText($inv['terms'] ?? '');
  $dueDate = ogmInvoiceDateLabel($inv['dueDate'] ?? '');
  $deposit = is_numeric($inv['depositApplied'] ?? null) ? (float)$inv['depositApplied'] : 0.0;
  $total = is_numeric($inv['total'] ?? null) ? (float)$inv['total'] : 0.0;
  $balance = is_numeric($inv['balanceDue'] ?? null) ? (float)$inv['balanceDue'] : max(0, $total - $deposit);

  $ops[] = ogmPdfText(54, 748, 'OGM', 30, 'F2');
  $ops[] = ogmPdfText(54, 728, 'OLIVE GLASS & MARBLE', 9, 'F1');
  $ops[] = ogmPdfText(54, 710, '714 Robeson Street, Fayetteville, NC 28305', 9, 'F1');
  $ops[] = ogmPdfText(54, 696, '(910) 484-5277 - www.oliveglassandmarble.com', 9, 'F1');
  $ops[] = ogmPdfText(460, 748, 'Invoice', 22, 'F2');
  $ops[] = ogmPdfText(410, 724, 'Invoice #: ' . $invoiceNumber, 10, 'F1');
  $ops[] = ogmPdfText(410, 710, 'Date: ' . $invoiceDate, 10, 'F1');
  $ops[] = ogmPdfText(410, 696, 'Terms: ' . $terms, 10, 'F1');
  if ($dueDate !== '') {
    $ops[] = ogmPdfText(410, 682, 'Due: ' . $dueDate, 10, 'F1');
  }
  $ops[] = ogmPdfLine(54, 666, 558, 666);
  $y = 644;
  $add('Bill To', 9, 'F1', 54, 14);
  $add(ogmInvoiceCleanText($quote['name'] ?? ''), 12, 'F1', 54, 15);
  $addWrapped(implode(', ', array_filter([ogmInvoiceCleanText($quote['addr'] ?? ''), ogmInvoiceCleanText($quote['city'] ?? '')])), 54, 48, 9, 12);
  if (!empty($quote['phone'])) {
    $add(ogmInvoiceCleanText($quote['phone']), 9, 'F1', 54, 12);
  }
  if (!empty($quote['email'])) {
    $add(ogmInvoiceCleanText($quote['email']), 9, 'F1', 54, 12);
  }
  $shipY = 644;
  $ops[] = ogmPdfText(320, $shipY, 'Ship To', 9, 'F1');
  $shipY -= 18;
  $ship = implode(', ', array_filter([ogmInvoiceCleanText($quote['installAddr'] ?? ''), ogmInvoiceCleanText($quote['installCity'] ?? '')]));
  if ($ship === '') {
    $ship = implode(', ', array_filter([ogmInvoiceCleanText($quote['addr'] ?? ''), ogmInvoiceCleanText($quote['city'] ?? '')]));
  }
  foreach (ogmPdfWrap($ship, 36) as $line) {
    $ops[] = ogmPdfText(320, $shipY, $line, 9, 'F1');
    $shipY -= 12;
  }
  if (!empty($quote['job'])) {
    $ops[] = ogmPdfText(320, $shipY - 4, 'Job: ' . ogmInvoiceCleanText($quote['job']), 9, 'F1');
  }
  $y = min($y, $shipY) - 24;
  $ops[] = ogmPdfLine(54, $y + 10, 558, $y + 10);
  $add('Line Items', 9, 'F1', 54, 18);
  foreach ($lines as $line) {
    if (!is_array($line)) {
      continue;
    }
    $desc = ogmInvoiceCleanText($line['description'] ?? $line['item'] ?? '');
    $amount = ogmInvoiceMoney($line['amount'] ?? 0);
    $wrapped = ogmPdfWrap($desc, 72);
    foreach ($wrapped as $idx => $part) {
      $ensure(72);
      $ops[] = ogmPdfText(54, $y, $part, 10, 'F1');
      if ($idx === 0) {
        $ops[] = ogmPdfText(486, $y, $amount, 10, 'F1');
      }
      $y -= 14;
    }
  }
  $ensure(118);
  $ops[] = ogmPdfLine(54, $y, 558, $y);
  $y -= 20;
  if ($deposit > 0.004) {
    $ops[] = ogmPdfText(370, $y, 'Subtotal', 10, 'F1');
    $ops[] = ogmPdfText(486, $y, ogmInvoiceMoney($total), 10, 'F1');
    $y -= 16;
    $ops[] = ogmPdfText(370, $y, 'Less: deposit received', 10, 'F1');
    $ops[] = ogmPdfText(486, $y, '-' . ogmInvoiceMoney($deposit), 10, 'F1');
    $y -= 20;
    $ops[] = ogmPdfText(370, $y, 'Balance due', 14, 'F2');
    $ops[] = ogmPdfText(470, $y, ogmInvoiceMoney($balance), 16, 'F2');
  } else {
    $ops[] = ogmPdfText(370, $y, 'Total due', 14, 'F2');
    $ops[] = ogmPdfText(470, $y, ogmInvoiceMoney($total), 16, 'F2');
  }
  $y -= 32;
  if (!empty($inv['memo'])) {
    $add('Memo', 9, 'F1', 54, 15);
    $addWrapped($inv['memo'], 54, 86, 9, 12);
  }
  $ensure(92);
  $ops[] = ogmPdfLine(54, 64, 558, 64);
  $ops[] = ogmPdfText(120, 46, 'Please remit payment by the due date. Make checks payable to Olive Glass & Marble.', 9, 'F1');
  $pages[] = $ops;
  return ogmBuildPdf($pages);
}

function ogmBuildPdf($pages) {
  $objects = [];
  $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
  $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
  $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman >>';
  $kids = [];
  $next = 5;
  foreach ($pages as $ops) {
    $pageId = $next++;
    $contentId = $next++;
    $content = implode('', $ops);
    $kids[] = $pageId . ' 0 R';
    $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
    $objects[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
  }
  $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
  ksort($objects, SORT_NUMERIC);
  $max = max(array_keys($objects));
  $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
  $offsets = [0];
  for ($i = 1; $i <= $max; $i++) {
    $offsets[$i] = strlen($pdf);
    $pdf .= $i . " 0 obj\n" . ($objects[$i] ?? '<< >>') . "\nendobj\n";
  }
  $xref = strlen($pdf);
  $pdf .= "xref\n0 " . ($max + 1) . "\n";
  $pdf .= "0000000000 65535 f \n";
  for ($i = 1; $i <= $max; $i++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
  }
  $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
  return $pdf;
}
