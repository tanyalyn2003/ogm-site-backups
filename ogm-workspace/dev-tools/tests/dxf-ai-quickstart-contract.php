<?php
declare(strict_types=1);

define('OGM_QUICKSTART', true);
$root = getenv('OGM_ROOT') ?: dirname(__DIR__, 2);
if (!is_file($root . '/quoter-tool-working/ai-quickstart-config.php')) {
    $root = '/Users/tanyawhite/OGM';
}
require_once $root . '/quoter-tool-working/ai-quickstart-config.php';

function fail_test(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fail_test($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function dxf_entity_lwpoly(array $points, ?int $color = null, string $layer = '0', bool $closed = true): string {
    $parts = ["0", "LWPOLYLINE", "8", $layer];
    if ($color !== null) {
        $parts[] = "62";
        $parts[] = (string)$color;
    }
    $parts[] = "70";
    $parts[] = $closed ? "1" : "0";
    foreach ($points as $pt) {
        $parts[] = "10";
        $parts[] = (string)$pt[0];
        $parts[] = "20";
        $parts[] = (string)$pt[1];
    }
    return implode("\n", $parts) . "\n";
}

function dxf_entity_circle(float $x, float $y, float $r, ?int $color = null, string $layer = '0'): string {
    $parts = ["0", "CIRCLE", "8", $layer];
    if ($color !== null) {
        $parts[] = "62";
        $parts[] = (string)$color;
    }
    array_push($parts, "10", (string)$x, "20", (string)$y, "40", (string)$r);
    return implode("\n", $parts) . "\n";
}

function dxf_entity_text(string $text, float $x, float $y): string {
    return implode("\n", ["0", "TEXT", "8", "0", "10", (string)$x, "20", (string)$y, "1", $text]) . "\n";
}

function dxf_doc(string ...$entities): string {
    return "0\nSECTION\n2\nHEADER\n9\n\$INSUNITS\n70\n1\n0\nENDSEC\n"
        . "0\nSECTION\n2\nENTITIES\n"
        . implode('', $entities)
        . "0\nENDSEC\n0\nEOF\n";
}

function classify_doc(string $raw): array {
    $parsed = ogm_qs_dxf_parse($raw);
    return ogm_qs_dxf_rooms($parsed['entities']);
}

function zone(float $x1, float $y1, float $x2, float $y2): string {
    return dxf_entity_lwpoly([[$x1, $y1], [$x2, $y1], [$x2, $y2], [$x1, $y2]], 1);
}

function stone(float $x1, float $y1, float $x2, float $y2): string {
    return dxf_entity_lwpoly([[$x1, $y1], [$x2, $y1], [$x2, $y2], [$x1, $y2]]);
}

function green_rect(float $x1, float $y1, float $x2, float $y2): string {
    return dxf_entity_lwpoly([[$x1, $y1], [$x2, $y1], [$x2, $y2], [$x1, $y2]], 3);
}

$single = classify_doc(dxf_doc(stone(0, 0, 100, 25)));
assert_same(1, count($single['rooms']), 'black shape fallback room count');
assert_same(1, count($single['rooms'][0]['pieces']), 'black shape imports as stone');
assert_same(0, count($single['rooms'][0]['cutouts']), 'black shape has no cutouts');

$greenRect = classify_doc(dxf_doc(zone(0, 0, 200, 100), dxf_entity_text('Kitchen', 10, 90), stone(10, 10, 110, 35), green_rect(20, 15, 40, 30)));
assert_same('Kitchen', $greenRect['rooms'][0]['label'], 'red zone text labels room');
assert_same(1, count($greenRect['rooms'][0]['pieces']), 'stone inside zone imports as piece');
assert_same('rectangle', $greenRect['rooms'][0]['cutouts'][0]['shape'] ?? null, 'green polyline imports as rectangle cutout');

$greenCircle = classify_doc(dxf_doc(zone(0, 0, 200, 100), stone(10, 10, 110, 35), dxf_entity_circle(50, 22, 8, 3)));
assert_same('circle', $greenCircle['rooms'][0]['cutouts'][0]['shape'] ?? null, 'green circle imports as round cutout');

$plainCircle = classify_doc(dxf_doc(zone(0, 0, 200, 100), stone(10, 10, 110, 35), dxf_entity_circle(50, 22, 8)));
assert_same(0, count($plainCircle['rooms'][0]['cutouts']), 'non-green circle is not a cutout');

$multi = classify_doc(dxf_doc(
    zone(0, 0, 100, 100),
    dxf_entity_text('Room A', 10, 90),
    stone(10, 10, 80, 35),
    green_rect(20, 15, 30, 25),
    zone(200, 0, 300, 100),
    dxf_entity_text('Room B', 210, 90),
    stone(210, 10, 280, 35),
    dxf_entity_circle(240, 22, 6, 3)
));
assert_same(2, count($multi['rooms']), 'multiple red zones create multiple rooms');
assert_same(1, count($multi['rooms'][0]['cutouts']), 'zone A cutout assignment');
assert_same(1, count($multi['rooms'][1]['cutouts']), 'zone B cutout assignment');

$fallbackCutout = classify_doc(dxf_doc(stone(0, 0, 100, 25), dxf_entity_circle(30, 12, 6, 3)));
assert_same(1, count($fallbackCutout['rooms']), 'no-zone fallback room count');
assert_same(1, count($fallbackCutout['rooms'][0]['cutouts']), 'no-zone fallback still honors green cutout');

echo "AI Quick Start DXF parser contract OK\n";
