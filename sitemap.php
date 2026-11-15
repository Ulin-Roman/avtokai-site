<?php
/* CODEX_FILENAME_GUARD_V1 */
if (\basename(__FILE__) !== 'sitemap.php') { \http_response_code(404); exit; }
/* CODEX_FILENAME_GUARD_END_V1 */
// Public sitemap: route /sitemap.xml to this exact file.
// Standalone endpoint: no database, sessions, forms, or external requests.
header('Content-Type: application/xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$siteUrl = 'https://avtokai.ru/';
$seoFile = __DIR__ . '/seo_data.json';
$stations = [];
$serviceSlugs = ['vykup-bityh-avto', 'vykup-kreditnyh-avto', 'vykup-neispravnyh-avto',
    'vykup-avto-posle-dtp', 'vykup-avto-s-probegom', 'vykup-staryh-avto'];

try {
    $catalogFile = __DIR__ . '/service-pages.json';
    if (!is_file($catalogFile)) {
        throw new RuntimeException('Service catalog is missing.');
    }
    $catalogContents = file_get_contents($catalogFile);
    if ($catalogContents === false) {
        throw new RuntimeException('Service catalog could not be read.');
    }
    $services = json_decode($catalogContents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($services)) {
        throw new RuntimeException('Service catalog is not an object.');
    }
    foreach ($serviceSlugs as $slug) {
        $service = $services[$slug] ?? null;
        if (!is_array($service)) {
            throw new RuntimeException('Service page is missing.');
        }
        foreach (['h1', 'title', 'description'] as $field) {
            if (!isset($service[$field]) || !is_string($service[$field]) || trim($service[$field]) === '') {
                throw new RuntimeException('Service page metadata is incomplete.');
            }
        }
    }
} catch (Throwable $error) {
    http_response_code(503);
    header('Retry-After: 3600');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<error>Sitemap temporarily unavailable</error>';
    exit;
}

if (is_file($seoFile)) {
    $contents = file_get_contents($seoFile);
    try {
        if ($contents === false) {
            throw new RuntimeException('SEO data could not be read.');
        }
        $stations = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($stations)) {
            throw new RuntimeException('SEO data is not an object.');
        }
    } catch (Throwable $error) {
        http_response_code(503);
        header('Retry-After: 3600');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<error>Sitemap temporarily unavailable</error>';
        exit;
    }
}

$urls = [$siteUrl, $siteUrl . 'avtovykup-v-spb/'];
$campaignSlugs = ['vykup-avto', 'srochnyj-vykup-avto', 'skupka-avto',
    'prodat-avto', 'avtovykup', 'prodat-mashinu-bystro'];
foreach ($campaignSlugs as $slug) {
    $urls[] = $siteUrl . $slug . '/';
}
foreach ($serviceSlugs as $slug) {
    $urls[] = $siteUrl . $slug . '/';
}
$stationUrls = [];
foreach ($stations as $key => $station) {
    $slug = (string) $key;
    // Match the current page router: trim(), empty(), and an array of page fields.
    if (empty($slug) || $slug !== trim($slug) || !is_array($station)) {
        continue;
    }
    $stationUrls[] = $siteUrl . '?spb=' . rawurlencode($slug);
}
sort($stationUrls, SORT_STRING);
$urls = array_merge($urls, $stationUrls);

if (count($urls) > 50000) {
    http_response_code(503);
    header('Retry-After: 3600');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<error>Sitemap index required</error>';
    exit;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $url) {
    echo '  <url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>' . "\n";
}
echo '</urlset>' . "\n";
// lastmod is intentionally omitted until reliable content modification dates exist.
