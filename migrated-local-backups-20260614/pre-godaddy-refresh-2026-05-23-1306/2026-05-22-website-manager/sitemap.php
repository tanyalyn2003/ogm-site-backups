<?php

header('Content-Type: application/xml; charset=UTF-8');

$siteRoot = __DIR__;
$baseUrl = 'https://oliveglassandmarble.com/';

/**
 * Extract a tag attribute from a small, known-safe HTML file.
 */
function sitemapExtractAttribute($html, $pattern)
{
    if (preg_match($pattern, $html, $matches) === 1) {
        return html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return null;
}

/**
 * Build the canonical URL list for indexable root-level HTML pages.
 *
 * @return array<int, array{loc: string, lastmod: string}>
 */
function sitemapCollectEntries($siteRoot, $baseUrl)
{
    $entries = [];
    $files = glob($siteRoot . '/*.html') ?: [];
    $excludedFiles = [
        'our-process.html',
    ];
    sort($files, SORT_STRING);

    foreach ($files as $filePath) {
        $fileName = basename($filePath);
        if (in_array($fileName, $excludedFiles, true)) {
            continue;
        }

        $html = @file_get_contents($filePath);
        if ($html === false) {
            continue;
        }

        $robots = sitemapExtractAttribute(
            $html,
            '/<meta\s+name=["\']robots["\']\s+content=["\']([^"\']+)["\']/i'
        );

        if ($robots !== null && stripos($robots, 'noindex') !== false) {
            continue;
        }

        $canonical = sitemapExtractAttribute(
            $html,
            '/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)["\']/i'
        );

        $loc = $canonical;
        if ($loc === null || $loc === '') {
            $loc = $fileName === 'index.html' ? $baseUrl : $baseUrl . $fileName;
        }

        $lastModified = filemtime($filePath);
        if ($lastModified === false) {
            continue;
        }

        $entries[] = [
            'loc' => $loc,
            'lastmod' => gmdate('Y-m-d', $lastModified),
        ];
    }

    usort(
        $entries,
        function ($left, $right) {
            if ($left['loc'] === 'https://oliveglassandmarble.com/') {
                return -1;
            }

            if ($right['loc'] === 'https://oliveglassandmarble.com/') {
                return 1;
            }

            return strcmp($left['loc'], $right['loc']);
        }
    );

    return $entries;
}

$entries = sitemapCollectEntries($siteRoot, $baseUrl);

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

foreach ($entries as $entry) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
    echo '    <lastmod>' . htmlspecialchars($entry['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</lastmod>\n";
    echo "  </url>\n";
}

echo "</urlset>\n";
