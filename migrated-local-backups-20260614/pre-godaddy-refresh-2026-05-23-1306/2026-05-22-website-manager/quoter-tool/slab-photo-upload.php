<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

header('Content-Type: application/json; charset=UTF-8');

function slabUploadRespond($status, $payload) {
  http_response_code($status);
  echo json_encode($payload);
  exit;
}

set_exception_handler(function($e) {
  slabUploadRespond(500, ['ok' => false, 'error' => 'Upload failed on the server: ' . $e->getMessage()]);
});

register_shutdown_function(function() {
  $err = error_get_last();
  if (!$err) return;
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
  if (!in_array((int) ($err['type'] ?? 0), $fatalTypes, true)) return;
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
  }
  echo json_encode(['ok' => false, 'error' => 'Upload failed on the server.']);
});

function slabUploadMime($path, $clientType) {
  if ($path && extension_loaded('fileinfo') && class_exists('finfo')) {
    try {
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      if ($finfo) {
        $mime = (string) $finfo->file($path);
        if ($mime !== '') {
          return $mime;
        }
      }
    } catch (Throwable $e) {
      // Missing or broken fileinfo on host — fall through.
    }
  }
  if ($path && function_exists('getimagesize')) {
    $info = @getimagesize($path);
    if (is_array($info) && !empty($info['mime'])) {
      return (string) $info['mime'];
    }
  }
  return (string) $clientType;
}

function slabUploadPrepareDir($dir) {
  if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;
  return is_dir($dir) && is_writable($dir);
}

if (!qtIsLoggedIn()) {
  slabUploadRespond(401, ['ok' => false, 'error' => 'Not logged in.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  slabUploadRespond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  $code = (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE);
  $detail = 'Upload failed. Code: ' . $code;
  if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
    $detail .= ' (file exceeds PHP upload_max_filesize or post_max_size — raise both in .user.ini; see GODADDY-PHP-UPLOAD.txt).';
  } elseif ($code === UPLOAD_ERR_PARTIAL) {
    $detail .= ' (incomplete upload — try again or check connection timeout).';
  }
  slabUploadRespond(400, ['ok' => false, 'error' => $detail]);
}

$file = $_FILES['image'];
$maxBytes = 12 * 1024 * 1024;
if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > $maxBytes) {
  slabUploadRespond(400, ['ok' => false, 'error' => 'Image must be 12 MB or smaller.']);
}

$tmpPath = $file['tmp_name'] ?? '';
if (!$tmpPath || !is_uploaded_file($tmpPath)) {
  slabUploadRespond(400, ['ok' => false, 'error' => 'No uploaded file was received.']);
}

$mime = slabUploadMime($tmpPath, $file['type'] ?? '');
$extByMime = [
  'image/jpeg' => 'jpg',
  'image/png' => 'png',
  'image/webp' => 'webp',
];

if (!isset($extByMime[$mime])) {
  slabUploadRespond(400, ['ok' => false, 'error' => 'Use a JPG, PNG, or WebP image.']);
}

$targets = [
  [
    'dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'slabs' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'slabs',
    'thumbs' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'slabs' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'slabs' . DIRECTORY_SEPARATOR . 'thumbs',
    'url' => '/slabs/storage/slabs/',
    'thumbUrl' => '/slabs/storage/slabs/thumbs/',
  ],
  [
    'dir' => __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'slab-photos',
    'thumbs' => __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'slab-photos' . DIRECTORY_SEPARATOR . 'thumbs',
    'url' => qtBasePath() . 'uploads/slab-photos/',
    'thumbUrl' => qtBasePath() . 'uploads/slab-photos/thumbs/',
  ],
];

$target = null;
foreach ($targets as $candidate) {
  if (slabUploadPrepareDir($candidate['dir']) && slabUploadPrepareDir($candidate['thumbs'])) {
    $target = $candidate;
    break;
  }
}
if (!$target) {
  slabUploadRespond(500, ['ok' => false, 'error' => 'Upload folder is not writable by PHP. Check folder permissions for slabs/storage/slabs or quoter-tool/uploads/slab-photos.']);
}

$ext = $extByMime[$mime];
$nameRaw = pathinfo((string) ($file['name'] ?? 'slab-photo'), PATHINFO_FILENAME);
$nameClean = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower($nameRaw));
$nameClean = trim((string) $nameClean, '-');
if ($nameClean === '') $nameClean = 'slab-photo';
$filename = 'local-' . $nameClean . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $target['dir'] . DIRECTORY_SEPARATOR . $filename;

if (!move_uploaded_file($tmpPath, $destPath) && !copy($tmpPath, $destPath)) {
  slabUploadRespond(500, ['ok' => false, 'error' => 'Could not save uploaded image.']);
}

$thumbPath = $target['thumbs'] . DIRECTORY_SEPARATOR . $filename;
@copy($destPath, $thumbPath);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'oliveglassandmarble.com'));
$baseUrl = $scheme . '://' . ($host ?: 'oliveglassandmarble.com');
$imageUrl = $baseUrl . $target['url'] . rawurlencode($filename);
$thumbUrl = $baseUrl . $target['thumbUrl'] . rawurlencode($filename);

echo json_encode([
  'ok' => true,
  'id' => 'local-' . pathinfo($filename, PATHINFO_FILENAME),
  'name' => $nameRaw ?: 'Uploaded slab photo',
  'image_url' => $imageUrl,
  'thumbnail_url' => $thumbUrl,
]);
