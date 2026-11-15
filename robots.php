<?php
/* CODEX_FILENAME_GUARD_V1 */
if (\basename(__FILE__) !== 'robots.php') { \http_response_code(404); exit; }
/* CODEX_FILENAME_GUARD_END_V1 */
// Standalone public robots endpoint: no database, sessions or network requests.
header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
echo <<<'ROBOTS'
User-agent: *
Disallow: /admin
Disallow: /uploads/
Disallow: /seo_data.json
Disallow: /login_attempts.json
Disallow: /calls.log
Disallow: /orders.log

# Advertising click IDs and UTM labels do not change page content. Keep spb meaningful.
Clean-param: utm_source&utm_medium&utm_campaign&utm_content&utm_term&yclid&gclid&fbclid&ysclid&_openstat

Sitemap: https://avtokai.ru/sitemap.xml

ROBOTS;
