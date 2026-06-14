<?php

if (!function_exists('ogmEnsureDirectory')) {
  function ogmEnsureDirectory($directory) {
    return is_dir($directory) || (@mkdir($directory, 0755, true) && is_dir($directory));
  }
}

if (!function_exists('ogmLeadStorageDir')) {
  function ogmLeadStorageDir() {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'ogm-chat';
  }
}

if (!function_exists('ogmPartialLeadLogFile')) {
  function ogmPartialLeadLogFile() {
    return ogmLeadStorageDir() . DIRECTORY_SEPARATOR . 'chatbot-partial-leads.log';
  }
}

if (!function_exists('ogmFullLeadLogFile')) {
  function ogmFullLeadLogFile() {
    return ogmLeadStorageDir() . DIRECTORY_SEPARATOR . 'full-leads.log';
  }
}

if (!function_exists('ogmTrafficLogFile')) {
  function ogmTrafficLogFile() {
    return ogmLeadStorageDir() . DIRECTORY_SEPARATOR . 'site-traffic.log';
  }
}

if (!function_exists('ogmSocialMessageLogFile')) {
  function ogmSocialMessageLogFile() {
    return ogmLeadStorageDir() . DIRECTORY_SEPARATOR . 'social-messages.log';
  }
}

if (!function_exists('ogmLeadHistoryFile')) {
  function ogmLeadHistoryFile() {
    return ogmLeadStorageDir() . DIRECTORY_SEPARATOR . 'lead-history.json';
  }
}

if (!function_exists('ogmLeadDashboardStateFile')) {
  function ogmLeadDashboardStateFile() {
    return ogmLeadStorageDir() . DIRECTORY_SEPARATOR . 'dashboard-state.json';
  }
}

if (!function_exists('ogmNormalizePhoneDigits')) {
  function ogmNormalizePhoneDigits($phone) {
    return preg_replace('/\D+/', '', (string) $phone);
  }
}

if (!function_exists('ogmAppendNdjson')) {
  function ogmAppendNdjson($path, $entry) {
    $directory = dirname($path);
    if (!ogmEnsureDirectory($directory)) {
      return false;
    }

    $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if ($line === false) {
      return false;
    }

    return @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
  }
}

if (!function_exists('ogmReadNdjson')) {
  function ogmReadNdjson($path) {
    if (!is_file($path)) {
      return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
      return [];
    }

    $records = [];
    foreach ($lines as $line) {
      $decoded = json_decode((string) $line, true);
      if (is_array($decoded)) {
        $records[] = $decoded;
      }
    }

    return $records;
  }
}

if (!function_exists('ogmWriteNdjson')) {
  function ogmWriteNdjson($path, $records) {
    $directory = dirname($path);
    if (!ogmEnsureDirectory($directory)) {
      return false;
    }

    $lines = [];
    foreach ((array) $records as $entry) {
      if (!is_array($entry)) {
        continue;
      }

      $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
      if ($line === false) {
        return false;
      }

      $lines[] = $line;
    }

    $payload = $lines ? implode(PHP_EOL, $lines) . PHP_EOL : '';
    return @file_put_contents($path, $payload, LOCK_EX) !== false;
  }
}

if (!function_exists('ogmReadJsonFile')) {
  function ogmReadJsonFile($path, $default = []) {
    if (!is_file($path)) {
      return $default;
    }

    $decoded = json_decode((string) @file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $default;
  }
}

if (!function_exists('ogmWriteJsonFile')) {
  function ogmWriteJsonFile($path, $value) {
    $directory = dirname($path);
    if (!ogmEnsureDirectory($directory)) {
      return false;
    }

    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($encoded === false) {
      return false;
    }

    return @file_put_contents($path, $encoded, LOCK_EX) !== false;
  }
}

if (!function_exists('ogmBuildLeadKey')) {
  function ogmBuildLeadKey($entry) {
    $sessionId = trim((string) ($entry['session_id'] ?? ''));
    $email = strtolower(trim((string) ($entry['email'] ?? '')));
    $phone = ogmNormalizePhoneDigits($entry['phone'] ?? '');

    if ($email !== '' || $phone !== '') {
      return sha1($email . '|' . $phone);
    }

    if ($sessionId !== '') {
      return sha1('session|' . $sessionId);
    }

    $name = strtolower(trim((string) ($entry['name'] ?? '')));
    $fallback = strtolower(implode('|', [
      $name,
      trim((string) ($entry['project_type'] ?? '')),
      trim((string) ($entry['city'] ?? '')),
      trim((string) ($entry['question'] ?? '')),
      trim((string) ($entry['page_url'] ?? '')),
    ]));

    return sha1($fallback);
  }
}

if (!function_exists('ogmReadLeadHistory')) {
  function ogmReadLeadHistory() {
    $history = ogmReadJsonFile(ogmLeadHistoryFile(), []);
    return is_array($history) ? $history : [];
  }
}

if (!function_exists('ogmSaveLeadHistory')) {
  function ogmSaveLeadHistory($history) {
    return ogmWriteJsonFile(ogmLeadHistoryFile(), is_array($history) ? $history : []);
  }
}

if (!function_exists('ogmRecordLeadHistory')) {
  function ogmRecordLeadHistory($entry) {
    if (!is_array($entry)) {
      return false;
    }

    $leadKey = trim((string) ($entry['lead_key'] ?? ''));
    if ($leadKey === '') {
      $leadKey = ogmBuildLeadKey($entry);
    }

    if ($leadKey === '') {
      return false;
    }

    $timestamp = trim((string) ($entry['timestamp'] ?? '')) ?: gmdate('c');
    $leadKind = strtolower(trim((string) ($entry['lead_kind'] ?? '')));
    $hasFull = $leadKind === 'full';
    $hasPartial = $leadKind === 'partial';
    $history = ogmReadLeadHistory();
    $existing = isset($history[$leadKey]) && is_array($history[$leadKey]) ? $history[$leadKey] : [];

    $history[$leadKey] = [
      'lead_key' => $leadKey,
      'first_seen' => trim((string) ($existing['first_seen'] ?? '')) ?: $timestamp,
      'last_seen' => $timestamp,
      'first_kind' => trim((string) ($existing['first_kind'] ?? '')) ?: ($leadKind !== '' ? $leadKind : 'partial'),
      'has_full' => !empty($existing['has_full']) || $hasFull,
      'has_partial' => !empty($existing['has_partial']) || $hasPartial,
      'full_events' => (int) ($existing['full_events'] ?? 0) + ($hasFull ? 1 : 0),
      'partial_events' => (int) ($existing['partial_events'] ?? 0) + ($hasPartial ? 1 : 0),
      'source' => trim((string) ($entry['source'] ?? ($existing['source'] ?? ''))),
      'page_url' => trim((string) ($entry['page_url'] ?? ($existing['page_url'] ?? ''))),
      'name' => trim((string) ($entry['name'] ?? ($existing['name'] ?? ''))),
      'email' => trim((string) ($entry['email'] ?? ($existing['email'] ?? ''))),
      'phone' => trim((string) ($entry['phone'] ?? ($existing['phone'] ?? ''))),
    ];

    return ogmSaveLeadHistory($history);
  }
}

if (!function_exists('ogmStoreFullLead')) {
  function ogmStoreFullLead($lead) {
    $entry = [
      'timestamp' => gmdate('c'),
      'lead_kind' => 'full',
      'source' => trim((string) ($lead['source'] ?? 'Website Contact Form')),
      'name' => trim((string) ($lead['name'] ?? '')),
      'email' => trim((string) ($lead['email'] ?? '')),
      'phone' => trim((string) ($lead['phone'] ?? '')),
      'project_type' => trim((string) ($lead['project_type'] ?? '')),
      'city' => trim((string) ($lead['city'] ?? '')),
      'space_type' => trim((string) ($lead['space_type'] ?? '')),
      'material_interest' => trim((string) ($lead['material_interest'] ?? '')),
      'build_type' => trim((string) ($lead['build_type'] ?? '')),
      'timeline' => trim((string) ($lead['timeline'] ?? '')),
      'chat_summary' => trim((string) ($lead['chat_summary'] ?? '')),
      'chat_transcript' => trim((string) ($lead['chat_transcript'] ?? '')),
      'customer_type' => trim((string) ($lead['customer_type'] ?? '')),
      'measurements' => trim((string) ($lead['measurements'] ?? '')),
      'tile_complete' => trim((string) ($lead['tile_complete'] ?? '')),
      'project_scope' => trim((string) ($lead['project_scope'] ?? '')),
      'plans_ready' => trim((string) ($lead['plans_ready'] ?? '')),
      'pricing_or_scheduling' => trim((string) ($lead['pricing_or_scheduling'] ?? '')),
      'home_or_commercial' => trim((string) ($lead['home_or_commercial'] ?? '')),
      'message' => trim((string) ($lead['message'] ?? '')),
      'attachment_names' => array_values(array_filter(array_map('strval', (array) ($lead['attachment_names'] ?? [])))),
      'mail_status' => trim((string) ($lead['mail_status'] ?? 'unknown')),
      'push_status' => trim((string) ($lead['push_status'] ?? 'unknown')),
      'push_note' => trim((string) ($lead['push_note'] ?? '')),
      'ip' => trim((string) ($lead['ip'] ?? '')),
      'user_agent' => trim((string) ($lead['user_agent'] ?? '')),
      'lead_key' => '',
    ];

    $entry['lead_key'] = ogmBuildLeadKey($entry);
    $stored = ogmAppendNdjson(ogmFullLeadLogFile(), $entry);
    if (!$stored) {
      return false;
    }

    ogmRecordLeadHistory($entry);
    return true;
  }
}

if (!function_exists('ogmBuildSocialLeadKey')) {
  function ogmBuildSocialLeadKey($entry) {
    $channel = strtolower(trim((string) ($entry['channel'] ?? 'meta')));
    $customerId = trim((string) ($entry['customer_id'] ?? ''));

    if ($customerId !== '') {
      return sha1('social|' . $channel . '|' . $customerId);
    }

    $messageId = trim((string) ($entry['message_id'] ?? ''));
    if ($messageId !== '') {
      return sha1('social|' . $channel . '|' . $messageId);
    }

    return sha1('social|' . $channel . '|' . strtolower(trim((string) ($entry['name'] ?? ''))));
  }
}

if (!function_exists('ogmStoreSocialMessage')) {
  function ogmStoreSocialMessage($message) {
    $channel = strtolower(trim((string) ($message['channel'] ?? 'meta')));
    if (!in_array($channel, ['facebook', 'instagram'], true)) {
      $channel = 'meta';
    }

    $entry = [
      'timestamp' => trim((string) ($message['timestamp'] ?? '')) ?: gmdate('c'),
      'lead_kind' => 'social',
      'source' => trim((string) ($message['source'] ?? ($channel === 'instagram' ? 'Instagram Message' : 'Facebook Message'))),
      'channel' => $channel,
      'name' => trim((string) ($message['name'] ?? '')),
      'customer_id' => trim((string) ($message['customer_id'] ?? '')),
      'message_id' => trim((string) ($message['message_id'] ?? '')),
      'page_url' => trim((string) ($message['page_url'] ?? '')),
      'message' => trim((string) ($message['message'] ?? '')),
      'chat_transcript' => trim((string) ($message['chat_transcript'] ?? '')),
      'attachment_names' => array_values(array_filter(array_map('strval', (array) ($message['attachment_names'] ?? [])))),
      'lead_key' => trim((string) ($message['lead_key'] ?? '')),
    ];

    if ($entry['lead_key'] === '') {
      $entry['lead_key'] = ogmBuildSocialLeadKey($entry);
    }

    if ($entry['lead_key'] === '') {
      return [
        'success' => false,
        'duplicate' => false,
        'lead_key' => '',
      ];
    }

    $messageId = $entry['message_id'];
    if ($messageId !== '') {
      foreach (ogmReadNdjson(ogmSocialMessageLogFile()) as $existing) {
        if (
          trim((string) ($existing['message_id'] ?? '')) === $messageId &&
          strtolower(trim((string) ($existing['channel'] ?? 'meta'))) === $channel
        ) {
          return [
            'success' => true,
            'duplicate' => true,
            'lead_key' => $entry['lead_key'],
          ];
        }
      }
    }

    $stored = ogmAppendNdjson(ogmSocialMessageLogFile(), $entry);

    return [
      'success' => $stored,
      'duplicate' => false,
      'lead_key' => $entry['lead_key'],
    ];
  }
}
