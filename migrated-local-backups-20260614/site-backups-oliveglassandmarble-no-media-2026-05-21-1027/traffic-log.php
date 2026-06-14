<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lead-storage.php';

header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store, max-age=0', true);

function trafficExitNoContent() {
  http_response_code(204);
  exit;
}

function trafficAllowedHosts() {
  return [
    'oliveglassandmarble.com' => true,
    'www.oliveglassandmarble.com' => true,
  ];
}

function trafficNormalizeHost($value) {
  $host = strtolower(trim((string) $value));
  return preg_replace('/:\d+$/', '', $host);
}

function trafficIsAllowedHost($host) {
  $host = trafficNormalizeHost($host);
  if ($host === '') {
    return false;
  }

  $allowed = trafficAllowedHosts();
  return isset($allowed[$host]);
}

function trafficIsBotUserAgent($userAgent) {
  return (bool) preg_match('/bot|crawl|spider|slurp|preview|monitor|facebookexternalhit|bingpreview|linkedinbot|whatsapp|discordbot|telegrambot/i', (string) $userAgent);
}

function trafficSanitizeId($value, $maxLength = 80) {
  $value = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $value);
  return substr($value, 0, max(1, (int) $maxLength));
}

function trafficSanitizeEventName($value, $maxLength = 48) {
  $value = strtolower(trim((string) $value));
  $value = preg_replace('/[^a-z0-9_-]/', '', $value);
  return substr($value, 0, max(1, (int) $maxLength));
}

function trafficNormalizeTargetDetails($value) {
  $raw = trim((string) $value);
  if ($raw === '') {
    return [
      'url' => '',
      'host' => '',
      'path' => '',
    ];
  }

  $targetUrl = substr($raw, 0, 255);
  $targetHost = '';
  $targetPath = '';
  $parsedTarget = parse_url($raw);

  if ($parsedTarget !== false) {
    $scheme = strtolower(trim((string) ($parsedTarget['scheme'] ?? '')));
    if ($scheme === 'mailto' || $scheme === 'tel') {
      $targetPath = $scheme . ':' . trim((string) ($parsedTarget['path'] ?? ''));
    } else {
      $targetHost = trafficNormalizeHost($parsedTarget['host'] ?? '');
      $targetPath = trim((string) ($parsedTarget['path'] ?? ''));
      $query = trim((string) ($parsedTarget['query'] ?? ''));
      if ($query !== '') {
        $targetPath .= '?' . $query;
      }
    }
  }

  if ($targetPath === '') {
    $targetPath = $raw;
  }

  return [
    'url' => $targetUrl,
    'host' => $targetHost,
    'path' => substr($targetPath, 0, 180),
  ];
}

function trafficParsePayload() {
  $contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
  $rawBody = (string) @file_get_contents('php://input');

  if (strpos($contentType, 'application/json') !== false) {
    $decoded = json_decode($rawBody, true);
    return is_array($decoded) ? $decoded : [];
  }

  if (!empty($_POST)) {
    return (array) $_POST;
  }

  parse_str($rawBody, $parsed);
  return is_array($parsed) ? $parsed : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  trafficExitNoContent();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  trafficExitNoContent();
}

$requestHost = trafficNormalizeHost($_SERVER['HTTP_HOST'] ?? '');
if (!trafficIsAllowedHost($requestHost)) {
  trafficExitNoContent();
}

$origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '') {
  $originHost = trafficNormalizeHost(parse_url($origin, PHP_URL_HOST) ?: '');
  if ($originHost !== '' && !trafficIsAllowedHost($originHost)) {
    trafficExitNoContent();
  }
}

$payload = trafficParsePayload();
if (!$payload) {
  trafficExitNoContent();
}

$userAgent = substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
if (trafficIsBotUserAgent($userAgent)) {
  trafficExitNoContent();
}

$pathInput = trim((string) ($payload['path'] ?? ''));
if ($pathInput === '') {
  trafficExitNoContent();
}

$parsedPath = parse_url($pathInput);
if ($parsedPath === false) {
  trafficExitNoContent();
}

$path = trim((string) ($parsedPath['path'] ?? ''));
if ($path === '' || $path[0] !== '/') {
  $path = '/' . ltrim($path, '/');
}

$query = trim((string) ($parsedPath['query'] ?? ''));
if ($query !== '') {
  $path .= '?' . $query;
}

if ($path === '/traffic-log.php' || strpos($path, '/admin/') === 0) {
  trafficExitNoContent();
}

$pageUrl = 'https://' . $requestHost . $path;
$referrerUrl = trim((string) ($payload['referrer'] ?? ''));
$referrerHost = '';
$referrerPath = '';

if ($referrerUrl !== '') {
  $parsedReferrer = parse_url($referrerUrl);
  if ($parsedReferrer !== false) {
    $referrerHost = trafficNormalizeHost($parsedReferrer['host'] ?? '');
    $referrerPath = trim((string) ($parsedReferrer['path'] ?? ''));
  }
}

$visitorId = trafficSanitizeId($payload['visitor_id'] ?? '');
$sessionId = trafficSanitizeId($payload['session_id'] ?? '');
$ipAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$visitorKey = $visitorId !== ''
  ? 'visitor:' . $visitorId
  : 'fingerprint:' . hash('sha256', $ipAddress . '|' . $userAgent);
$targetDetails = trafficNormalizeTargetDetails($payload['target_url'] ?? '');

$entry = [
  'timestamp' => gmdate('c'),
  'event_type' => trim((string) ($payload['event_type'] ?? '')) ?: 'pageview',
  'event_name' => trafficSanitizeEventName($payload['event_name'] ?? ''),
  'page_id' => trafficSanitizeId($payload['page_id'] ?? ''),
  'path' => $path,
  'url' => $pageUrl,
  'title' => substr(trim((string) ($payload['title'] ?? '')), 0, 180),
  'visitor_id' => $visitorId,
  'visitor_key' => $visitorKey,
  'session_id' => $sessionId,
  'referrer_url' => substr($referrerUrl, 0, 255),
  'referrer_host' => $referrerHost,
  'referrer_path' => substr($referrerPath, 0, 180),
  'screen' => substr(trim((string) ($payload['screen'] ?? '')), 0, 40),
  'viewport' => substr(trim((string) ($payload['viewport'] ?? '')), 0, 40),
  'timezone' => substr(trim((string) ($payload['timezone'] ?? '')), 0, 64),
  'engaged_ms' => max(0, min((int) ($payload['engaged_ms'] ?? 0), 4 * 60 * 60 * 1000)),
  'target_url' => $targetDetails['url'],
  'target_host' => $targetDetails['host'],
  'target_path' => $targetDetails['path'],
  'target_label' => substr(trim((string) ($payload['target_label'] ?? '')), 0, 180),
  'user_agent' => $userAgent,
];

ogmAppendNdjson(ogmTrafficLogFile(), $entry);
trafficExitNoContent();
