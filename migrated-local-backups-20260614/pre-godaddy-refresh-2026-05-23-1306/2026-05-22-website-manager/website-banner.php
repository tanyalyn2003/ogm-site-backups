<?php

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0', true);
header('X-Robots-Tag: noindex, nofollow', true);

function ogmPublicBannerDefault() {
    return [
        'active' => false,
        'message' => '',
        'ctaLabel' => '',
        'ctaUrl' => '',
        'startAt' => '',
        'endAt' => '',
    ];
}

function ogmPublicBannerDateActive($start, $end) {
    $today = gmdate('Y-m-d');
    if ($start !== '' && $today < $start) {
        return false;
    }
    if ($end !== '' && $today > $end) {
        return false;
    }
    return true;
}

$path = __DIR__ . DIRECTORY_SEPARATOR . 'quoter-tool' . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . 'website-manager-banner.json';
if (!is_file($path)) {
    echo json_encode(['ok' => true, 'banner' => ogmPublicBannerDefault()]);
    exit;
}

$raw = (string) file_get_contents($path);
$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(['ok' => true, 'banner' => ogmPublicBannerDefault()]);
    exit;
}

$banner = [
    'active' => !empty($data['active']),
    'message' => trim(strip_tags((string) ($data['message'] ?? ''))),
    'ctaLabel' => trim(strip_tags((string) ($data['ctaLabel'] ?? ''))),
    'ctaUrl' => trim((string) ($data['ctaUrl'] ?? '')),
    'startAt' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['startAt'] ?? '')) ? (string) $data['startAt'] : '',
    'endAt' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['endAt'] ?? '')) ? (string) $data['endAt'] : '',
];

if (!$banner['active'] || $banner['message'] === '' || !ogmPublicBannerDateActive($banner['startAt'], $banner['endAt'])) {
    $banner = ogmPublicBannerDefault();
}

echo json_encode(['ok' => true, 'banner' => $banner], JSON_UNESCAPED_SLASHES);

