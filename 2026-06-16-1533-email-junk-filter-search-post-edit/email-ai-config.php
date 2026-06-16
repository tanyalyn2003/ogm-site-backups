<?php
/**
 * OGM Email Center — AI reply drafting helper.
 * Include-only. API keys live under protected .data/email storage.
 */

if (!defined('OGM_EMAIL_CENTER')) { http_response_code(403); exit('Forbidden'); }

function ogm_ai_config_path(): string {
  return ogm_email_data_dir() . DIRECTORY_SEPARATOR . 'claude-api-key.json';
}

function ogm_ai_log_path(): string {
  return ogm_email_data_dir() . DIRECTORY_SEPARATOR . 'ai-draft-log.json';
}

function ogm_ai_system_prompt(): string {
  return <<<PROMPT
You are drafting an email reply on behalf of an Olive Glass & Marble (OGM) sales rep.

ABOUT OGM:
- Family-owned countertop and glass company in Fayetteville, North Carolina
- Showroom at 714 Robeson Street. Phone (910) 484-5277
- Products: natural stone countertops, quartz, quartzite, marble, glass shower enclosures, and mirrors

VOICE AND TONE:
- Warm, professional, conversational, and direct
- Never corporate, stiff, or salesy
- Avoid clichés like "I hope this email finds you well"
- Use short paragraphs
- Always close with a clear next step
- Sign off with just the rep's first name, no signature block

HARD RULES:
- Do not invent prices, dates, square footage, product availability, or scheduling commitments
- If the customer asks for details not in the thread, say the rep will check and follow up
- Match the formality level of the customer's latest message
- If the customer is upset, acknowledge the concern before anything else
- If there is not enough context, write a brief holding reply instead of guessing
PROMPT;
}

function ogm_ai_load_config(): array {
  $path = ogm_ai_config_path();
  if (!is_file($path)) return [];
  $json = json_decode((string)@file_get_contents($path), true);
  return is_array($json) ? $json : [];
}

function ogm_ai_api_key(): string {
  $cfg = ogm_ai_load_config();
  foreach (['apiKey', 'api_key', 'anthropicApiKey', 'key'] as $key) {
    $value = trim((string)($cfg[$key] ?? ''));
    if ($value !== '') return $value;
  }
  return trim((string)getenv('ANTHROPIC_API_KEY'));
}

function ogm_ai_model(): string {
  $cfg = ogm_ai_load_config();
  $model = trim((string)($cfg['model'] ?? ''));
  return $model !== '' ? $model : 'claude-haiku-4-5';
}

function ogm_ai_daily_limit(): int {
  $cfg = ogm_ai_load_config();
  $limit = (int)($cfg['dailyCallLimit'] ?? $cfg['daily_call_limit'] ?? 200);
  return max(1, min(1000, $limit));
}

function ogm_ai_configured(): bool {
  return ogm_ai_api_key() !== '';
}

function ogm_ai_today_key(): string {
  return date('Y-m-d');
}

function ogm_ai_load_log(): array {
  $path = ogm_ai_log_path();
  if (!is_file($path)) return [];
  $json = json_decode((string)@file_get_contents($path), true);
  return is_array($json) ? $json : [];
}

function ogm_ai_save_log(array $log): void {
  $cutoff = strtotime('-45 days');
  foreach (array_keys($log) as $day) {
    if (strtotime((string)$day) < $cutoff) unset($log[$day]);
  }
  $path = ogm_ai_log_path();
  @file_put_contents($path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  @chmod($path, 0600);
}

function ogm_ai_today_call_count(): int {
  $log = ogm_ai_load_log();
  return (int)($log[ogm_ai_today_key()]['calls'] ?? 0);
}

function ogm_ai_estimate_cents(int $inTokens, int $outTokens): float {
  return ($inTokens * 0.0001) + ($outTokens * 0.0005);
}

function ogm_ai_log_call(string $username, string $mailbox, int $inTokens, int $outTokens, string $model): void {
  $today = ogm_ai_today_key();
  $log = ogm_ai_load_log();
  if (!isset($log[$today]) || !is_array($log[$today])) {
    $log[$today] = ['calls' => 0, 'inTokens' => 0, 'outTokens' => 0, 'byUser' => []];
  }
  $userKey = strtolower(trim($username)) ?: 'unknown';
  if (!isset($log[$today]['byUser'][$userKey])) {
    $log[$today]['byUser'][$userKey] = ['calls' => 0, 'inTokens' => 0, 'outTokens' => 0, 'mailbox' => $mailbox, 'model' => $model];
  }
  $log[$today]['calls']++;
  $log[$today]['inTokens'] += $inTokens;
  $log[$today]['outTokens'] += $outTokens;
  $log[$today]['byUser'][$userKey]['calls']++;
  $log[$today]['byUser'][$userKey]['inTokens'] += $inTokens;
  $log[$today]['byUser'][$userKey]['outTokens'] += $outTokens;
  $log[$today]['byUser'][$userKey]['mailbox'] = $mailbox;
  $log[$today]['byUser'][$userKey]['model'] = $model;
  ogm_ai_save_log($log);
}

function ogm_ai_clean_html(string $html, int $maxLen = 1800): string {
  $html = preg_replace('/<(style|script|head)\b[^>]*>.*?<\/\1>/is', '', $html);
  $html = preg_replace('/<!--\[if[^\]]*\]>.*?<!\[endif\]-->/is', '', (string)$html);
  $html = preg_replace('/<br\s*\/?>/i', "\n", (string)$html);
  $html = preg_replace('/<\/(p|div|tr|li|h[1-6])[^>]*>/i', "\n", (string)$html);
  $text = strip_tags((string)$html);
  $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $text = preg_replace('/[ \t]+/', ' ', (string)$text);
  $text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", (string)$text);
  $text = trim((string)$text);
  if (strlen($text) > $maxLen) $text = substr($text, 0, $maxLen) . "\n[message truncated]";
  return $text;
}

function ogm_ai_call(string $userMessage): array {
  $apiKey = ogm_ai_api_key();
  if ($apiKey === '') return ['_error' => 'Claude API key is not configured.', '_status' => 503];
  $model = ogm_ai_model();
  $payload = [
    'model' => $model,
    'max_tokens' => 800,
    'system' => ogm_ai_system_prompt(),
    'messages' => [['role' => 'user', 'content' => $userMessage]],
  ];
  $ch = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'x-api-key: ' . $apiKey,
      'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 45,
  ]);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($raw === false) return ['_error' => 'connection_failed: ' . $err, '_status' => 502];
  $json = json_decode((string)$raw, true);
  if (!is_array($json)) return ['_error' => 'invalid_response', '_status' => 502];
  if ($status >= 400) {
    return ['_error' => (string)($json['error']['message'] ?? ('Claude API error ' . $status)), '_status' => $status];
  }
  $draft = '';
  foreach (($json['content'] ?? []) as $block) {
    if (($block['type'] ?? '') === 'text') $draft .= (string)($block['text'] ?? '');
  }
  return [
    'draft' => trim($draft),
    'inTokens' => (int)($json['usage']['input_tokens'] ?? 0),
    'outTokens' => (int)($json['usage']['output_tokens'] ?? 0),
    'stopReason' => (string)($json['stop_reason'] ?? ''),
    'model' => $model,
  ];
}

function ogm_ai_search_system_prompt(): string {
  return <<<PROMPT
You are an email assistant for Olive Glass & Marble (OGM), a countertop fabrication business.
You help staff search and understand their live Outlook mailbox (inbox, sent, etc.).
Treat company name variants as the same: C&F, C and F, C & F, CandF, cfcabinets, etc.
Email addresses like cfcabinets5@nc.rr.com may represent C and F Cabinets even when the display name is only the address.
Answer using the mailbox messages provided. Be direct and concise.
Use plain text only — no markdown, no **bold**, no ## headers. Use dashes for bullet points if needed.
If the answer is not in the messages provided, say so clearly.
PROMPT;
}

function ogm_ai_search_answer(string $userMessage): array {
  $apiKey = ogm_ai_api_key();
  if ($apiKey === '') return ['_error' => 'Claude API key is not configured.', '_status' => 503];
  $model = ogm_ai_model();
  $payload = [
    'model' => $model,
    'max_tokens' => 1200,
    'system' => ogm_ai_search_system_prompt(),
    'messages' => [['role' => 'user', 'content' => $userMessage]],
  ];
  $ch = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'x-api-key: ' . $apiKey,
      'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 60,
  ]);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err = curl_error($ch);
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
    'text' => trim($text),
    'inTokens' => (int)($json['usage']['input_tokens'] ?? 0),
    'outTokens' => (int)($json['usage']['output_tokens'] ?? 0),
    'model' => $model,
  ];
}

function ogm_ai_classify_junk_batch(array $messages): array {
  $apiKey = ogm_ai_api_key();
  if ($apiKey === '') return ['_error' => 'Claude API key is not configured.', '_status' => 503];
  if (!$messages) return ['labels' => [], 'inTokens' => 0, 'outTokens' => 0, 'model' => ogm_ai_model()];

  $lines = [];
  foreach ($messages as $i => $msg) {
    if (!is_array($msg)) continue;
    $lines[] = ($i + 1) . '. id=' . ($msg['id'] ?? '')
      . ' | from=' . trim(($msg['from'] ?? '') . ' ' . ($msg['fromAddr'] ?? ''))
      . ' | subject=' . ($msg['subject'] ?? '')
      . ' | preview=' . substr((string)($msg['preview'] ?? ''), 0, 220);
  }
  $system = <<<'PROMPT'
You classify email list items for a countertop shop inbox.
Return ONLY valid JSON object mapping each message id to "junk" or "keep".
Mark as junk: marketing, newsletters, cold sales pitches, SEO scams, unrelated vendor spam, and obvious mass mail.
Mark as keep: real customers, contractors, suppliers OGM works with, job-related mail, quotes, scheduling, and anything that could be business-related.
When unsure, choose keep.
PROMPT;
  $user = "Classify these messages:\n" . implode("\n", $lines) . "\n\nReturn JSON like {\"message-id\":\"junk\"} with no other text.";
  $model = ogm_ai_model();
  $payload = [
    'model' => $model,
    'max_tokens' => 500,
    'system' => $system,
    'messages' => [['role' => 'user', 'content' => $user]],
  ];
  $ch = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'x-api-key: ' . $apiKey,
      'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 45,
  ]);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  if ($raw === false || $status >= 400) {
    return ['_error' => 'junk_classify_failed', '_status' => $status ?: 502];
  }
  $json = json_decode((string)$raw, true);
  $text = '';
  foreach (($json['content'] ?? []) as $block) {
    if (($block['type'] ?? '') === 'text') $text .= (string)($block['text'] ?? '');
  }
  $labels = json_decode(trim($text), true);
  if (!is_array($labels) && preg_match('/\{[\s\S]+\}/', $text, $m)) {
    $labels = json_decode($m[0], true);
  }
  if (!is_array($labels)) {
    return ['_error' => 'invalid_junk_labels', '_status' => 502];
  }
  $clean = [];
  foreach ($labels as $id => $label) {
    $clean[(string)$id] = (strtolower((string)$label) === 'junk') ? 'junk' : 'keep';
  }
  return [
    'labels' => $clean,
    'inTokens' => (int)($json['usage']['input_tokens'] ?? 0),
    'outTokens' => (int)($json['usage']['output_tokens'] ?? 0),
    'model' => $model,
  ];
}
