<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lead-storage.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'pushover.php';

if (!function_exists('ogmMetaSettingsFile')) {
  function ogmMetaSettingsFile() {
    return ogmLeadStorageDir() . DIRECTORY_SEPARATOR . 'meta-messaging-settings.json';
  }
}

if (!function_exists('ogmMetaGenerateVerifyToken')) {
  function ogmMetaGenerateVerifyToken() {
    try {
      return bin2hex(random_bytes(16));
    } catch (Exception $exception) {
      return sha1(uniqid('ogm-meta-', true));
    }
  }
}

if (!function_exists('ogmNormalizeMetaSettings')) {
  function ogmNormalizeMetaSettings($settings) {
    return [
      'enabled' => !empty($settings['enabled']),
      'verify_token' => trim((string) ($settings['verify_token'] ?? '')),
      'app_secret' => trim((string) ($settings['app_secret'] ?? '')),
      'facebook_page_id' => trim((string) ($settings['facebook_page_id'] ?? '')),
      'instagram_account_id' => trim((string) ($settings['instagram_account_id'] ?? '')),
      'social_push_enabled' => array_key_exists('social_push_enabled', (array) $settings) ? !empty($settings['social_push_enabled']) : true,
    ];
  }
}

if (!function_exists('ogmReadMetaSettings')) {
  function ogmReadMetaSettings() {
    $settings = ogmNormalizeMetaSettings(ogmReadJsonFile(ogmMetaSettingsFile(), []));

    if ($settings['verify_token'] === '') {
      $settings['verify_token'] = ogmMetaGenerateVerifyToken();
      $settings['updated_at'] = gmdate('c');
      ogmWriteJsonFile(ogmMetaSettingsFile(), $settings);
    }

    return $settings;
  }
}

if (!function_exists('ogmSaveMetaSettings')) {
  function ogmSaveMetaSettings($settings) {
    $normalized = ogmNormalizeMetaSettings($settings);
    if ($normalized['verify_token'] === '') {
      $normalized['verify_token'] = ogmMetaGenerateVerifyToken();
    }

    $normalized['updated_at'] = gmdate('c');
    return ogmWriteJsonFile(ogmMetaSettingsFile(), $normalized);
  }
}

if (!function_exists('ogmMetaIsEnabled')) {
  function ogmMetaIsEnabled($settings) {
    $settings = ogmNormalizeMetaSettings($settings);
    return !empty($settings['enabled']) && $settings['verify_token'] !== '' && $settings['app_secret'] !== '';
  }
}

if (!function_exists('ogmMetaStatusText')) {
  function ogmMetaStatusText($settings) {
    $settings = ogmNormalizeMetaSettings($settings);

    if (ogmMetaIsEnabled($settings)) {
      return 'Enabled and ready for incoming Facebook and Instagram messages.';
    }

    if ($settings['app_secret'] !== '' || $settings['facebook_page_id'] !== '' || $settings['instagram_account_id'] !== '') {
      return 'Saved but currently turned off.';
    }

    return 'Not configured yet.';
  }
}

if (!function_exists('ogmMetaCallbackUrl')) {
  function ogmMetaCallbackUrl() {
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'oliveglassandmarble.com')) ?: 'oliveglassandmarble.com';
    $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
    $scheme = ($https || $port === 443) ? 'https' : 'http';
    return $scheme . '://' . $host . '/meta-webhook.php';
  }
}

if (!function_exists('ogmMetaBusinessIdMatches')) {
  function ogmMetaBusinessIdMatches($candidateId, $settings) {
    $candidateId = trim((string) $candidateId);
    if ($candidateId === '') {
      return false;
    }

    $settings = ogmNormalizeMetaSettings($settings);
    $knownIds = array_filter([
      trim((string) ($settings['facebook_page_id'] ?? '')),
      trim((string) ($settings['instagram_account_id'] ?? '')),
    ]);

    return in_array($candidateId, $knownIds, true);
  }
}

if (!function_exists('ogmMetaValidateSignature')) {
  function ogmMetaValidateSignature($rawBody, $settings) {
    $settings = ogmNormalizeMetaSettings($settings);
    $secret = trim((string) ($settings['app_secret'] ?? ''));
    if ($secret === '') {
      return false;
    }

    $signature256 = trim((string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
    if ($signature256 !== '') {
      $expected256 = 'sha256=' . hash_hmac('sha256', (string) $rawBody, $secret);
      return hash_equals($expected256, $signature256);
    }

    $signature = trim((string) ($_SERVER['HTTP_X_HUB_SIGNATURE'] ?? ''));
    if ($signature !== '') {
      $expected = 'sha1=' . hash_hmac('sha1', (string) $rawBody, $secret);
      return hash_equals($expected, $signature);
    }

    return false;
  }
}

if (!function_exists('ogmMetaDetectChannel')) {
  function ogmMetaDetectChannel($payloadObject, $entry, $messagingItem, $settings) {
    $payloadObject = strtolower(trim((string) $payloadObject));
    $settings = ogmNormalizeMetaSettings($settings);
    $instagramId = trim((string) ($settings['instagram_account_id'] ?? ''));
    $entryId = trim((string) ($entry['id'] ?? ''));
    $senderId = trim((string) ($messagingItem['sender']['id'] ?? ''));
    $recipientId = trim((string) ($messagingItem['recipient']['id'] ?? ''));

    if ($payloadObject === 'instagram') {
      return 'instagram';
    }

    if ($instagramId !== '' && ($entryId === $instagramId || $senderId === $instagramId || $recipientId === $instagramId)) {
      return 'instagram';
    }

    return 'facebook';
  }
}

if (!function_exists('ogmMetaFormatTimestamp')) {
  function ogmMetaFormatTimestamp($timestamp) {
    $timestamp = (int) $timestamp;
    if ($timestamp <= 0) {
      return gmdate('c');
    }

    if ($timestamp > 20000000000) {
      $timestamp = (int) floor($timestamp / 1000);
    }

    return gmdate('c', $timestamp);
  }
}

if (!function_exists('ogmMetaDisplayName')) {
  function ogmMetaDisplayName($channel, $customerId, $fallbackName = '') {
    $fallbackName = trim((string) $fallbackName);
    if ($fallbackName !== '') {
      return $fallbackName;
    }

    $label = $channel === 'instagram' ? 'Instagram User' : 'Facebook User';
    $customerId = trim((string) $customerId);
    if ($customerId === '') {
      return $label;
    }

    return $label . ' #' . substr($customerId, -4);
  }
}

if (!function_exists('ogmMetaAttachmentLabels')) {
  function ogmMetaAttachmentLabels($messagingItem) {
    $labels = [];

    foreach ((array) (($messagingItem['message']['attachments'] ?? [])) as $attachment) {
      if (!is_array($attachment)) {
        continue;
      }

      $type = trim((string) ($attachment['type'] ?? 'attachment'));
      $labels[] = ucfirst($type) . ' attachment';
    }

    return array_values(array_unique(array_filter($labels)));
  }
}

if (!function_exists('ogmMetaMessageText')) {
  function ogmMetaMessageText($messagingItem, $attachmentLabels) {
    $text = trim((string) ($messagingItem['message']['text'] ?? ''));
    if ($text !== '') {
      return $text;
    }

    $postbackTitle = trim((string) ($messagingItem['postback']['title'] ?? ''));
    if ($postbackTitle !== '') {
      return 'Postback: ' . $postbackTitle;
    }

    $postbackPayload = trim((string) ($messagingItem['postback']['payload'] ?? ''));
    if ($postbackPayload !== '') {
      return 'Postback: ' . $postbackPayload;
    }

    if ($attachmentLabels) {
      return implode(', ', $attachmentLabels);
    }

    return '';
  }
}

if (!function_exists('ogmMetaShouldIgnoreItem')) {
  function ogmMetaShouldIgnoreItem($messagingItem, $settings) {
    if (!is_array($messagingItem)) {
      return true;
    }

    if (!empty($messagingItem['delivery']) || !empty($messagingItem['read']) || !empty($messagingItem['reaction'])) {
      return true;
    }

    if (!empty($messagingItem['message']['is_echo'])) {
      return true;
    }

    $senderId = trim((string) ($messagingItem['sender']['id'] ?? ''));
    if ($senderId !== '' && ogmMetaBusinessIdMatches($senderId, $settings)) {
      return true;
    }

    if (empty($messagingItem['message']) && empty($messagingItem['postback'])) {
      return true;
    }

    return false;
  }
}

if (!function_exists('ogmMetaParseMessages')) {
  function ogmMetaParseMessages($payload, $settings) {
    $messages = [];
    $payloadObject = trim((string) ($payload['object'] ?? ''));

    foreach ((array) ($payload['entry'] ?? []) as $entry) {
      if (!is_array($entry)) {
        continue;
      }

      foreach ((array) ($entry['messaging'] ?? []) as $messagingItem) {
        if (ogmMetaShouldIgnoreItem($messagingItem, $settings)) {
          continue;
        }

        $channel = ogmMetaDetectChannel($payloadObject, $entry, $messagingItem, $settings);
        $customerId = trim((string) ($messagingItem['sender']['id'] ?? ''));
        $attachmentLabels = ogmMetaAttachmentLabels($messagingItem);
        $messageText = ogmMetaMessageText($messagingItem, $attachmentLabels);

        if ($messageText === '' && !$attachmentLabels) {
          continue;
        }

        $messageId = trim((string) ($messagingItem['message']['mid'] ?? ''));
        if ($messageId === '') {
          $messageId = trim((string) ($messagingItem['postback']['mid'] ?? ''));
        }
        if ($messageId === '') {
          $messageId = sha1(json_encode($messagingItem, JSON_UNESCAPED_SLASHES));
        }

        $messages[] = [
          'timestamp' => ogmMetaFormatTimestamp($messagingItem['timestamp'] ?? ($entry['time'] ?? 0)),
          'source' => $channel === 'instagram' ? 'Instagram Message' : 'Facebook Message',
          'channel' => $channel,
          'name' => ogmMetaDisplayName($channel, $customerId),
          'customer_id' => $customerId,
          'message_id' => $messageId,
          'page_url' => 'https://business.facebook.com/latest/inbox',
          'message' => $messageText,
          'attachment_names' => $attachmentLabels,
        ];
      }
    }

    return $messages;
  }
}

if (!function_exists('ogmHandleMetaIncomingPayload')) {
  function ogmHandleMetaIncomingPayload($payload, $settings) {
    $settings = ogmNormalizeMetaSettings($settings);
    $messages = ogmMetaParseMessages((array) $payload, $settings);
    $storedCount = 0;
    $duplicateCount = 0;

    foreach ($messages as $message) {
      $result = ogmStoreSocialMessage($message);
      if (empty($result['success'])) {
        continue;
      }

      if (!empty($result['duplicate'])) {
        $duplicateCount += 1;
        continue;
      }

      $storedCount += 1;
      if (!empty($settings['social_push_enabled'])) {
        ogmSendSocialMessagePushover($message);
      }
    }

    return [
      'parsed_count' => count($messages),
      'stored_count' => $storedCount,
      'duplicate_count' => $duplicateCount,
    ];
  }
}
