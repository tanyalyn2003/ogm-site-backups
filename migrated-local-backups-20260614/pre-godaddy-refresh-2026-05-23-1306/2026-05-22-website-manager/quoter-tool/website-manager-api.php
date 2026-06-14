<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

qtSendNoIndexHeaders();
qtStartSession();

header('Content-Type: application/json; charset=UTF-8');

if (!qtIsLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

function ogmWebsiteManagerDataDir() {
    return __DIR__ . DIRECTORY_SEPARATOR . '.data';
}

function ogmWebsiteManagerBannerPath() {
    return ogmWebsiteManagerDataDir() . DIRECTORY_SEPARATOR . 'website-manager-banner.json';
}

function ogmWebsiteManagerDefaultBanner() {
    return [
        'active' => false,
        'preset' => 'custom',
        'message' => '',
        'ctaLabel' => '',
        'ctaUrl' => '',
        'startAt' => '',
        'endAt' => '',
        'updatedAt' => '',
    ];
}

function ogmWebsiteManagerCleanText($value, $max) {
    $s = trim(strip_tags((string) $value));
    $s = preg_replace('/\s+/', ' ', $s);
    if (strlen($s) > $max) {
        $s = substr($s, 0, $max);
    }
    return $s;
}

function ogmWebsiteManagerCleanDate($value) {
    $s = trim((string) $value);
    if ($s === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $s;
    }
    return '';
}

function ogmWebsiteManagerCleanUrl($value) {
    $s = trim((string) $value);
    if ($s === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $s)) {
        return filter_var($s, FILTER_VALIDATE_URL) ? $s : '';
    }
    if ($s[0] === '/' || preg_match('/^[a-z0-9][a-z0-9._-]*\.(html|php)([?#].*)?$/i', $s)) {
        return $s;
    }
    return '';
}

function ogmWebsiteManagerNormalizeBanner($row) {
    $base = ogmWebsiteManagerDefaultBanner();
    if (!is_array($row)) {
        return $base;
    }
    $preset = ogmWebsiteManagerCleanText($row['preset'] ?? 'custom', 40);
    $allowed = ['holiday', 'weather', 'financing', 'hiring', 'event', 'custom'];
    if (!in_array($preset, $allowed, true)) {
        $preset = 'custom';
    }
    $out = [
        'active' => !empty($row['active']),
        'preset' => $preset,
        'message' => ogmWebsiteManagerCleanText($row['message'] ?? '', 240),
        'ctaLabel' => ogmWebsiteManagerCleanText($row['ctaLabel'] ?? '', 40),
        'ctaUrl' => ogmWebsiteManagerCleanUrl($row['ctaUrl'] ?? ''),
        'startAt' => ogmWebsiteManagerCleanDate($row['startAt'] ?? ''),
        'endAt' => ogmWebsiteManagerCleanDate($row['endAt'] ?? ''),
        'updatedAt' => ogmWebsiteManagerCleanText($row['updatedAt'] ?? '', 40),
    ];
    if ($out['ctaUrl'] === '') {
        $out['ctaLabel'] = '';
    }
    return $out;
}

function ogmWebsiteManagerReadBanner() {
    $path = ogmWebsiteManagerBannerPath();
    if (!is_file($path)) {
        return ogmWebsiteManagerDefaultBanner();
    }
    $raw = (string) file_get_contents($path);
    $data = json_decode($raw, true);
    return ogmWebsiteManagerNormalizeBanner(is_array($data) ? $data : []);
}

function ogmWebsiteManagerWriteBanner($banner) {
    $dir = ogmWebsiteManagerDataDir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create data directory.');
    }
    $banner['updatedAt'] = gmdate('c');
    $json = json_encode($banner, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not encode banner.');
    }
    $tmp = ogmWebsiteManagerBannerPath() . '.tmp';
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not write banner.');
    }
    if (!rename($tmp, ogmWebsiteManagerBannerPath())) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace banner.');
    }
    return $banner;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        echo json_encode(['ok' => true, 'banner' => ogmWebsiteManagerReadBanner()]);
        exit;
    }

    if ($method === 'POST') {
        $raw = (string) file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
            exit;
        }
        $banner = ogmWebsiteManagerNormalizeBanner($decoded['banner'] ?? $decoded);
        if ($banner['active'] && $banner['message'] === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Active banner needs a message.']);
            exit;
        }
        $saved = ogmWebsiteManagerWriteBanner($banner);
        echo json_encode(['ok' => true, 'banner' => $saved]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

