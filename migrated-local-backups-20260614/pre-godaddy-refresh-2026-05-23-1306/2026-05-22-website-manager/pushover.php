<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lead-storage.php';

if (!function_exists('ogmPushoverSettingsFile')) {
  function ogmPushoverSettingsFile() {
    return ogmLeadStorageDir() . DIRECTORY_SEPARATOR . 'pushover-settings.json';
  }
}

if (!function_exists('ogmNormalizePushoverPriority')) {
  function ogmNormalizePushoverPriority($priority) {
    $allowed = [-2, -1, 0, 1, 2];
    $priority = (int) $priority;
    return in_array($priority, $allowed, true) ? $priority : 0;
  }
}

if (!function_exists('ogmNormalizePushoverSettings')) {
  function ogmNormalizePushoverSettings($settings) {
    return [
      'enabled' => !empty($settings['enabled']),
      'token' => trim((string) ($settings['token'] ?? '')),
      'user' => trim((string) ($settings['user'] ?? '')),
      'device' => trim((string) ($settings['device'] ?? '')),
      'priority' => ogmNormalizePushoverPriority($settings['priority'] ?? 0),
      'sound' => trim((string) ($settings['sound'] ?? '')),
    ];
  }
}

if (!function_exists('ogmReadPushoverSettings')) {
  function ogmReadPushoverSettings() {
    return ogmNormalizePushoverSettings(ogmReadJsonFile(ogmPushoverSettingsFile(), []));
  }
}

if (!function_exists('ogmSavePushoverSettings')) {
  function ogmSavePushoverSettings($settings) {
    $normalized = ogmNormalizePushoverSettings($settings);
    $normalized['updated_at'] = gmdate('c');
    return ogmWriteJsonFile(ogmPushoverSettingsFile(), $normalized);
  }
}

if (!function_exists('ogmPushoverHasCredentials')) {
  function ogmPushoverHasCredentials($settings) {
    $settings = ogmNormalizePushoverSettings($settings);
    return $settings['token'] !== '' && $settings['user'] !== '';
  }
}

if (!function_exists('ogmPushoverIsEnabled')) {
  function ogmPushoverIsEnabled($settings) {
    $settings = ogmNormalizePushoverSettings($settings);
    return !empty($settings['enabled']) && ogmPushoverHasCredentials($settings);
  }
}

if (!function_exists('ogmPushoverLooksLikeToken')) {
  function ogmPushoverLooksLikeToken($value) {
    return preg_match('/^[A-Za-z0-9]{30}$/', trim((string) $value)) === 1;
  }
}

if (!function_exists('ogmPushoverLooksLikeUserKey')) {
  function ogmPushoverLooksLikeUserKey($value) {
    return preg_match('/^[A-Za-z0-9]{30}$/', trim((string) $value)) === 1;
  }
}

if (!function_exists('ogmPushoverLooksLikeDeviceList')) {
  function ogmPushoverLooksLikeDeviceList($value) {
    $value = trim((string) $value);
    if ($value === '') {
      return true;
    }

    return preg_match('/^[A-Za-z0-9_-]{1,25}(,[A-Za-z0-9_-]{1,25})*$/', $value) === 1;
  }
}

if (!function_exists('ogmPushoverErrorMessage')) {
  function ogmPushoverErrorMessage($response) {
    if (is_array($response['data']['errors'] ?? null) && $response['data']['errors']) {
      return implode(' ', array_map('strval', $response['data']['errors']));
    }

    $responseError = trim((string) ($response['error'] ?? ''));
    if ($responseError !== '') {
      return $responseError;
    }

    return 'Pushover did not accept the request.';
  }
}

if (!function_exists('ogmPushoverRequest')) {
  function ogmPushoverRequest($endpoint, $payload) {
    $url = 'https://api.pushover.net/1/' . ltrim((string) $endpoint, '/');
    $encodedPayload = http_build_query((array) $payload, '', '&', PHP_QUERY_RFC3986);
    $headers = [
      'Content-Type: application/x-www-form-urlencoded',
      'Content-Length: ' . strlen($encodedPayload),
      'User-Agent: OliveGlassAndMarbleLeadAlerts/1.0',
    ];

    $statusCode = 0;
    $body = '';
    $error = '';

    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedPayload);
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
      curl_setopt($ch, CURLOPT_TIMEOUT, 8);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

      $body = curl_exec($ch);
      if ($body === false) {
        $error = curl_error($ch);
      }

      $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
      curl_close($ch);
    } else {
      $context = stream_context_create([
        'http' => [
          'method' => 'POST',
          'header' => implode("\r\n", $headers),
          'content' => $encodedPayload,
          'timeout' => 8,
          'ignore_errors' => true,
        ],
      ]);

      $body = @file_get_contents($url, false, $context);
      if ($body === false) {
        $error = 'Could not connect to Pushover.';
      }

      foreach ((array) ($http_response_header ?? []) as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $headerLine, $matches)) {
          $statusCode = (int) $matches[1];
          break;
        }
      }
    }

    $data = json_decode((string) $body, true);
    $success = $statusCode >= 200 && $statusCode < 300 && (int) ($data['status'] ?? 0) === 1;

    return [
      'success' => $success,
      'status_code' => $statusCode,
      'body' => is_string($body) ? $body : '',
      'data' => is_array($data) ? $data : [],
      'error' => $error,
    ];
  }
}

if (!function_exists('ogmValidatePushoverSettings')) {
  function ogmValidatePushoverSettings($settings) {
    $settings = ogmNormalizePushoverSettings($settings);

    if (!ogmPushoverHasCredentials($settings)) {
      return [
        'success' => true,
        'message' => 'Pushover is not configured yet.',
      ];
    }

    if (!ogmPushoverLooksLikeToken($settings['token'])) {
      return [
        'success' => false,
        'message' => 'App token format looks invalid. Pushover tokens are 30 letters/numbers.',
      ];
    }

    if (!ogmPushoverLooksLikeUserKey($settings['user'])) {
      return [
        'success' => false,
        'message' => 'User or group key format looks invalid. Pushover keys are 30 letters/numbers.',
      ];
    }

    if (!ogmPushoverLooksLikeDeviceList($settings['device'])) {
      return [
        'success' => false,
        'message' => 'Device names can only use letters, numbers, underscores, and dashes.',
      ];
    }

    $payload = [
      'token' => $settings['token'],
      'user' => $settings['user'],
    ];

    if ($settings['device'] !== '') {
      $payload['device'] = $settings['device'];
    }

    $response = ogmPushoverRequest('users/validate.json', $payload);
    if ($response['success']) {
      return [
        'success' => true,
        'message' => 'Pushover validated successfully.',
        'devices' => is_array($response['data']['devices'] ?? null) ? $response['data']['devices'] : [],
      ];
    }

    return [
      'success' => false,
      'message' => ogmPushoverErrorMessage($response),
    ];
  }
}

if (!function_exists('ogmPushoverClip')) {
  function ogmPushoverClip($value, $maxLength) {
    $value = trim(preg_replace('/\s+/', ' ', (string) $value));
    if ($maxLength <= 0 || strlen($value) <= $maxLength) {
      return $value;
    }

    return rtrim(substr($value, 0, max(0, $maxLength - 3))) . '...';
  }
}

if (!function_exists('ogmPushoverDashboardUrl')) {
  function ogmPushoverDashboardUrl() {
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'oliveglassandmarble.com'));
    if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+(?::\d+)?$/', $host)) {
      $host = 'oliveglassandmarble.com';
    }

    return 'https://' . $host . '/admin/index.php';
  }
}

if (!function_exists('ogmBuildFullLeadPushoverPayload')) {
  function ogmBuildFullLeadPushoverPayload($settings, $lead) {
    $settings = ogmNormalizePushoverSettings($settings);
    $lead = is_array($lead) ? $lead : [];

    $contactParts = array_values(array_filter([
      trim((string) ($lead['phone'] ?? '')),
      trim((string) ($lead['email'] ?? '')),
    ]));

    $detailParts = array_values(array_filter([
      trim((string) ($lead['project_type'] ?? '')),
      trim((string) ($lead['city'] ?? '')),
      trim((string) ($lead['timeline'] ?? '')),
    ]));

    $summary = trim((string) ($lead['chat_summary'] ?? ''));
    if ($summary === '') {
      $summary = trim((string) ($lead['message'] ?? ''));
    }

    $lines = [
      'Lead: ' . (trim((string) ($lead['name'] ?? '')) ?: 'New website lead'),
    ];

    if ($contactParts) {
      $lines[] = 'Contact: ' . implode(' | ', $contactParts);
    }

    if ($detailParts) {
      $lines[] = 'Details: ' . implode(' | ', $detailParts);
    }

    if ($summary !== '') {
      $lines[] = 'Notes: ' . ogmPushoverClip($summary, 220);
    }

    $message = ogmPushoverClip(implode("\n", $lines), 900);
    $title = stripos((string) ($lead['source'] ?? ''), 'chat') !== false ? 'New Chatbot Lead' : 'New Website Lead';

    $payload = [
      'token' => $settings['token'],
      'user' => $settings['user'],
      'title' => ogmPushoverClip($title, 100),
      'message' => $message,
      'priority' => $settings['priority'],
      'url' => ogmPushoverDashboardUrl(),
      'url_title' => 'Open Lead Dashboard',
    ];

    if ($settings['device'] !== '') {
      $payload['device'] = $settings['device'];
    }

    if ($settings['sound'] !== '') {
      $payload['sound'] = $settings['sound'];
    }

    return $payload;
  }
}

if (!function_exists('ogmSendFullLeadPushover')) {
  function ogmSendFullLeadPushover($lead) {
    $settings = ogmReadPushoverSettings();
    if (!ogmPushoverIsEnabled($settings)) {
      return [
        'sent' => false,
        'status' => ogmPushoverHasCredentials($settings) ? 'disabled' : 'not_configured',
        'message' => ogmPushoverHasCredentials($settings) ? 'Pushover is saved but disabled.' : 'Pushover is not configured.',
      ];
    }

    $response = ogmPushoverRequest('messages.json', ogmBuildFullLeadPushoverPayload($settings, $lead));
    if ($response['success']) {
      return [
        'sent' => true,
        'status' => 'sent',
        'message' => 'Pushover notification sent.',
      ];
    }

    return [
      'sent' => false,
      'status' => 'failed',
      'message' => ogmPushoverErrorMessage($response),
    ];
  }
}

if (!function_exists('ogmBuildSocialMessagePushoverPayload')) {
  function ogmBuildSocialMessagePushoverPayload($settings, $message) {
    $settings = ogmNormalizePushoverSettings($settings);
    $message = is_array($message) ? $message : [];
    $channel = strtolower(trim((string) ($message['channel'] ?? 'meta')));
    $source = $channel === 'instagram' ? 'Instagram' : 'Facebook';
    $displayName = trim((string) ($message['name'] ?? '')) ?: ($source . ' Message');
    $bodyText = trim((string) ($message['message'] ?? ''));
    $attachmentSummary = trim((string) implode(', ', array_filter(array_map('strval', (array) ($message['attachment_names'] ?? [])))));

    $lines = [
      'From: ' . $displayName,
    ];

    if ($bodyText !== '') {
      $lines[] = 'Message: ' . ogmPushoverClip($bodyText, 220);
    }

    if ($attachmentSummary !== '') {
      $lines[] = 'Attachments: ' . ogmPushoverClip($attachmentSummary, 120);
    }

    $payload = [
      'token' => $settings['token'],
      'user' => $settings['user'],
      'title' => ogmPushoverClip('New ' . $source . ' Message', 100),
      'message' => ogmPushoverClip(implode("\n", $lines), 900),
      'priority' => $settings['priority'],
      'url' => ogmPushoverDashboardUrl(),
      'url_title' => 'Open Lead Dashboard',
    ];

    if ($settings['device'] !== '') {
      $payload['device'] = $settings['device'];
    }

    if ($settings['sound'] !== '') {
      $payload['sound'] = $settings['sound'];
    }

    return $payload;
  }
}

if (!function_exists('ogmSendSocialMessagePushover')) {
  function ogmSendSocialMessagePushover($message) {
    $settings = ogmReadPushoverSettings();
    if (!ogmPushoverIsEnabled($settings)) {
      return [
        'sent' => false,
        'status' => ogmPushoverHasCredentials($settings) ? 'disabled' : 'not_configured',
        'message' => ogmPushoverHasCredentials($settings) ? 'Pushover is saved but disabled.' : 'Pushover is not configured.',
      ];
    }

    $response = ogmPushoverRequest('messages.json', ogmBuildSocialMessagePushoverPayload($settings, $message));
    if ($response['success']) {
      return [
        'sent' => true,
        'status' => 'sent',
        'message' => 'Pushover notification sent.',
      ];
    }

    return [
      'sent' => false,
      'status' => 'failed',
      'message' => ogmPushoverErrorMessage($response),
    ];
  }
}

if (!function_exists('ogmSendPushoverTest')) {
  function ogmSendPushoverTest($settings, $requestedBy = '') {
    $settings = ogmNormalizePushoverSettings($settings);
    if (!ogmPushoverHasCredentials($settings)) {
      return [
        'sent' => false,
        'status' => 'not_configured',
        'message' => 'Enter your app token and user/group key first.',
      ];
    }

    $response = ogmPushoverRequest('messages.json', array_filter([
      'token' => $settings['token'],
      'user' => $settings['user'],
      'device' => $settings['device'],
      'title' => 'OGM Lead Alerts Test',
      'message' => ogmPushoverClip(
        'Test push from Olive Glass & Marble. Full submitted leads will alert this device when Pushover is enabled.'
        . ($requestedBy !== '' ? "\nTriggered by {$requestedBy}." : ''),
        900
      ),
      'priority' => $settings['priority'],
      'sound' => $settings['sound'],
      'url' => ogmPushoverDashboardUrl(),
      'url_title' => 'Open Lead Dashboard',
    ], function ($value) {
      return $value !== '' && $value !== null;
    }));

    if ($response['success']) {
      return [
        'sent' => true,
        'status' => 'sent',
        'message' => 'Test push sent successfully.',
      ];
    }

    return [
      'sent' => false,
      'status' => 'failed',
      'message' => ogmPushoverErrorMessage($response),
    ];
  }
}
