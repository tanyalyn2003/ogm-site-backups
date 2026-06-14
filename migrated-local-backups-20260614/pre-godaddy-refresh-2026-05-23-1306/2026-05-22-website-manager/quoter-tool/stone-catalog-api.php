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

function ogmStoneCatalogDataDir() {
    return __DIR__ . DIRECTORY_SEPARATOR . '.data';
}

function ogmStoneCatalogPath() {
    return ogmStoneCatalogDataDir() . DIRECTORY_SEPARATOR . 'stone-catalog.json';
}

function ogmStoneCatalogNormalizeRate($value, $fallback = 0) {
    if (is_string($value)) {
        $value = str_replace(['$', ','], '', $value);
    }
    $n = is_numeric($value) ? (float) $value : (float) $fallback;
    return is_finite($n) ? $n : (float) $fallback;
}

function ogmStoneCatalogNormalizeStone($stone) {
    if (!is_array($stone)) {
        return null;
    }

    $name = trim((string) ($stone['n'] ?? $stone['name'] ?? ''));
    if ($name === '') {
        return null;
    }

    $rates = $stone['r'] ?? [];
    if (!is_array($rates)) {
        $rates = [];
    }
    $price = ogmStoneCatalogNormalizeRate($stone['price'] ?? $stone['priceSf'] ?? ($rates[2] ?? ($rates[0] ?? 0)), 0);
    $r0 = ogmStoneCatalogNormalizeRate($rates[0] ?? $price, $price);
    $r1 = ogmStoneCatalogNormalizeRate($rates[1] ?? $price, $price);
    $r2 = ogmStoneCatalogNormalizeRate($rates[2] ?? $price, $price);

    return [
        'n' => $name,
        'lv' => trim((string) ($stone['lv'] ?? $stone['level'] ?? '1')) ?: '1',
        't' => trim((string) ($stone['t'] ?? $stone['type'] ?? 'Quartz')) ?: 'Quartz',
        'd' => trim((string) ($stone['d'] ?? $stone['brand'] ?? $stone['supplier'] ?? '')),
        'f' => trim((string) ($stone['f'] ?? $stone['finish'] ?? 'Polished')) ?: 'Polished',
        'tk' => trim((string) ($stone['tk'] ?? $stone['thickness'] ?? '3cm')) ?: '3cm',
        'sl' => ogmStoneCatalogNormalizeRate($stone['sl'] ?? $stone['slabLength'] ?? 127, 127),
        'sw' => ogmStoneCatalogNormalizeRate($stone['sw'] ?? $stone['slabWidth'] ?? 63, 63),
        'csf' => ogmStoneCatalogNormalizeRate($stone['csf'] ?? $stone['materialCostSf'] ?? $price, $price),
        'r' => [$r0, $r1, $r2],
    ];
}

function ogmStoneCatalogNormalizeList($rows) {
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $stone = ogmStoneCatalogNormalizeStone($row);
        if ($stone !== null) {
            $out[] = $stone;
        }
    }
    usort($out, function ($a, $b) {
        return strcasecmp((string) $a['n'], (string) $b['n']);
    });
    return $out;
}

function ogmStoneCatalogDecodeJsBuiltins() {
    $htmlPath = __DIR__ . DIRECTORY_SEPARATOR . 'ogm-quoter-internal.html';
    if (!is_file($htmlPath)) {
        return [];
    }
    $html = (string) file_get_contents($htmlPath);
    if (!preg_match('/const\s+STONES_BUILTIN\s*=\s*(\[[\s\S]*?\]);/m', $html, $m)) {
        return [];
    }
    $js = $m[1];
    $json = preg_replace('/([{\[,]\s*)([A-Za-z_][A-Za-z0-9_]*)\s*:/', '$1"$2":', $js);
    $json = preg_replace('/,\s*([\]}])/', '$1', $json);
    $decoded = json_decode($json, true);
    return is_array($decoded) ? ogmStoneCatalogNormalizeList($decoded) : [];
}

function ogmStoneCatalogRead() {
    $path = ogmStoneCatalogPath();
    if (is_file($path)) {
        $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $rows = $decoded['stones'] ?? $decoded;
            $stones = ogmStoneCatalogNormalizeList($rows);
            if ($stones) {
                return ['stones' => $stones, 'source' => 'server'];
            }
        }
    }

    $seed = ogmStoneCatalogDecodeJsBuiltins();
    if ($seed) {
        ogmStoneCatalogWrite($seed);
        return ['stones' => $seed, 'source' => 'seeded-from-quoter'];
    }
    return ['stones' => [], 'source' => 'empty'];
}

function ogmStoneCatalogWrite($stones) {
    $dir = ogmStoneCatalogDataDir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create data directory.');
    }

    $payload = [
        'updatedAt' => gmdate('c'),
        'count' => count($stones),
        'stones' => array_values($stones),
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not encode catalog.');
    }
    $tmp = ogmStoneCatalogPath() . '.tmp';
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not write catalog.');
    }
    if (!rename($tmp, ogmStoneCatalogPath())) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace catalog.');
    }
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        $result = ogmStoneCatalogRead();
        echo json_encode([
            'ok' => true,
            'source' => $result['source'],
            'count' => count($result['stones']),
            'stones' => $result['stones'],
        ]);
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
        $stones = ogmStoneCatalogNormalizeList($decoded['stones'] ?? $decoded);
        if (!$stones) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'No valid stones supplied']);
            exit;
        }
        ogmStoneCatalogWrite($stones);
        echo json_encode(['ok' => true, 'count' => count($stones)]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

