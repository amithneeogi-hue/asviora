<?php
/**
 * /robots.txt  (v7 — SEO manager)
 * Served at /robots.txt through a rewrite in .htaccess.
 */
require_once __DIR__ . '/config/config.php';

header('Content-Type: text/plain; charset=utf-8');

$base = rtrim(parse_url(SITE_URL, PHP_URL_PATH) ?: '', '/');

if (setting('seo_noindex_site', '0') === '1') {
    echo "User-agent: *\nDisallow: /\n";
    exit;
}

$lines = [
    'User-agent: *',
    'Allow: ' . $base . '/',
    'Disallow: ' . $base . '/admin/',
    'Disallow: ' . $base . '/api/',
    'Disallow: ' . $base . '/config/',
    'Disallow: ' . $base . '/includes/',
    'Disallow: ' . $base . '/storage/',
    'Disallow: ' . $base . '/dashboard.php',
    'Disallow: ' . $base . '/pay',
    'Disallow: ' . $base . '/game-frame.php',
    'Disallow: ' . $base . '/quiz-play.php',
    'Disallow: ' . $base . '/chess-play.php',
    'Disallow: ' . $base . '/logout.php',
];
$extra = trim(str_replace("\r", '', setting('seo_robots_extra')));
if ($extra !== '') {
    $lines[] = '';
    $lines[] = $extra;
}
$lines[] = '';
$lines[] = 'Sitemap: ' . url('sitemap.xml');

echo implode("\n", $lines) . "\n";
