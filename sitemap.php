<?php
/**
 * /sitemap.xml  (v7 — SEO manager)
 *
 * Served at /sitemap.xml through a rewrite in .htaccess; sitemap.php works
 * too. Lists only pages a visitor without an account can actually open
 * (pages behind the login would just redirect a crawler), and leaves out
 * anything the SEO manager marked noindex.
 */
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

$urls = [];
$add  = static function (string $key, string $loc, ?string $mod, string $freq, string $prio) use (&$urls): void {
    $o = seo_override($key);
    if (!empty($o['robots']) && str_contains($o['robots'], 'noindex')) {
        return;
    }
    $urls[] = [$loc, $mod, $freq, $prio];
};

if (setting('seo_noindex_site', '0') !== '1') {
    $add('home', url(''), null, 'daily', '1.0');
    if (setting('seo_public_catalogue', '0') === '1' && module_on('games')) {
        $add('games', url('games.php'), null, 'weekly', '0.9');
    }
    if (module_on('leaderboard')) {
        $add('leaderboard', url('leaderboard.php'), null, 'daily', '0.7');
    }
    if (module_on('blog')) {
        $last = fetch_col('SELECT MAX(published_at) FROM blog_posts WHERE status = "published"', [], null);
        $add('blog', url('blog.php'), $last, 'weekly', '0.8');
        foreach (fetch_all('SELECT slug, published_at, updated_at FROM blog_posts
                             WHERE status = "published" ORDER BY published_at DESC LIMIT 1000') as $p) {
            $add('post:' . $p['slug'], url('post.php?slug=' . urlencode($p['slug'])),
                 $p['updated_at'] ?: $p['published_at'], 'monthly', '0.6');
        }
    }
    $add('about',    url('about.php'),    null, 'monthly', '0.5');
    $add('contact',  url('contact.php'),  null, 'yearly',  '0.4');
    $add('register', url('register.php'), null, 'yearly',  '0.5');
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$loc, $mod, $freq, $prio]) {
    echo "  <url>\n    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
    if ($mod) {
        echo '    <lastmod>' . date('Y-m-d', strtotime($mod)) . "</lastmod>\n";
    }
    echo "    <changefreq>{$freq}</changefreq>\n    <priority>{$prio}</priority>\n  </url>\n";
}
echo "</urlset>\n";
