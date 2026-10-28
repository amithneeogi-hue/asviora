<?php
/**
 * SEO MANAGER  (v7)
 * ------------------------------------------------------------------
 * Every public page goes through seo_resolve() from includes/header.php.
 * What ends up in <head> is decided in this order:
 *
 *   1. an override saved in Admin -> SEO Manager (table `seo_pages`)
 *   2. what the page itself set ($page_title / $page_desc / $seo_*)
 *   3. the site-wide defaults in Admin -> SEO Manager -> Global
 *
 * It also writes the canonical link, robots, Open Graph / Twitter cards,
 * JSON-LD structured data (Organization, WebSite, BreadcrumbList and,
 * where it fits, VideoGame / BlogPosting), search-console verification
 * tags and Google Analytics / Tag Manager. sitemap.php and robots.php
 * build /sitemap.xml and /robots.txt from the same rules.
 *
 * The optional AI helper asks Claude (Anthropic API, the key is entered in
 * the admin) for a title, description and keywords. It only ever fills the
 * form — nothing is published until the admin presses Save.
 */

/** Script name -> page key, for the pages that do not set $seo_key. */
function seo_page_key(): string
{
    $file = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php', '.php');
    return $file === 'index' ? 'home' : $file;
}

/**
 * Every page the SEO manager can edit, with a label and its public URL.
 * Pages that are private by nature (login, dashboard, pay) are listed so
 * the admin can see they are noindex, but are not offered for editing.
 */
function seo_static_pages(): array
{
    $p = [
        'home'        => ['Home page',     'index.php'],
        'games'       => ['All games',     'games.php'],
        'quiz'        => ['Quiz Contest',  'quiz.php'],
        'spin'        => ['Daily Spin',    'spin.php'],
        'chess'       => ['Chess',         'chess.php'],
        'leaderboard' => ['Leaderboard',   'leaderboard.php'],
        'blog'        => ['Blog',          'blog.php'],
        'about'       => ['About us',      'about.php'],
        'contact'     => ['Contact',       'contact.php'],
        'govt-exam'   => ['Govt Exam',     'govt-exam.php'],
        'register'    => ['Create account', 'register.php'],
        'login'       => ['Log in',        'login.php'],
    ];
    $out = [];
    foreach ($p as $k => [$label, $file]) {
        $out[$k] = ['label' => $label, 'url' => url($file), 'kind' => 'page'];
    }
    return $out;
}

/** Pages that should never be indexed, whatever the admin saves. */
function seo_private_keys(): array
{
    return ['dashboard', 'pay', 'pay-result', 'pay-callback', 'quiz-play', 'chess-play',
            'logout', '404', 'game-frame', 'reset-admin-password', 'install', 'offline'];
}

/**
 * What a page says about itself when there is no override — mirrors the
 * $page_title / $page_desc each page file sets. Used by the admin list
 * and the AI helper so they can show and improve the real current text.
 */
function seo_default_meta(string $key): array
{
    $site = setting('site_name', 'Asviora Games');
    $map = [
        'home'        => ['', setting('site_description')],
        'games'       => ['All Games', 'Play skill games online on ' . $site . ' — 3D Cube Solver, Memory Match, Sudoku, Chess, the timed Quiz Contest and the Daily Spin. Play in your browser or on the Android app.'],
        'quiz'        => ['Quiz Contest', 'Levelled quiz — clear a level to open the next, with your own set of questions.'],
        'spin'        => ['Daily Spin', 'One free spin every day. Up to 1,000 coins, no entry fee, no catch.'],
        'chess'       => ['Chess', 'Play chess against the computer, or put coins on the table and challenge anyone with a link. Winner takes the pot.'],
        'leaderboard' => ['Leaderboard', 'Live leaderboards for every ' . $site . ' title — daily, weekly and all-time rankings.'],
        'blog'        => ['Blog', 'Game guides, strategy breakdowns and platform updates from the ' . $site . ' team.'],
        'about'       => ['About Us', $site . ' is a skill-based gaming platform built on the belief that games should be won with a sharp mind, not a lucky roll.'],
        'contact'     => ['Contact Us', 'Get in touch with the ' . $site . ' team by email, Facebook or Instagram.'],
        'govt-exam'   => [setting('govt_exam_label', 'Govt Exam'), setting('govt_exam_tagline', 'WBCS, SSC, Rail, Bank & PSC practice.')],
        'register'    => ['Create Account', 'Sign up on ' . $site . ' to play Sudoku, Memory Match, 3D Cube, Chess, the Quiz Contest and the Daily Spin — and climb the leaderboards.'],
        'login'       => ['Log In', 'Log in to ' . $site . ' to play skill games, quizzes and chess, and track your coins, XP and rank.'],
    ];
    if (isset($map[$key])) {
        return ['title' => $map[$key][0], 'desc' => $map[$key][1]];
    }
    if (str_starts_with($key, 'game:')) {
        $g = fetch_one('SELECT title, tagline, description FROM games WHERE slug = ?', [substr($key, 5)]);
        if ($g) {
            return ['title' => $g['title'], 'desc' => $g['tagline'] ?: (string) $g['description']];
        }
    }
    if (str_starts_with($key, 'post:')) {
        $p = fetch_one('SELECT title, excerpt, content, meta_title, meta_desc FROM blog_posts WHERE slug = ?',
                       [substr($key, 5)]);
        if ($p) {
            return ['title' => $p['meta_title'] ?: $p['title'],
                    'desc'  => $p['meta_desc'] ?: ($p['excerpt'] ?: seo_clip((string) $p['content'], 155))];
        }
    }
    return ['title' => '', 'desc' => ''];
}

/** Game and blog pages, for the admin list and the sitemap. */
function seo_dynamic_pages(): array
{
    $out = [];
    foreach (fetch_all('SELECT slug, title, tagline FROM games WHERE is_active = 1
                         ORDER BY sort_order, title') as $g) {
        $out['game:' . $g['slug']] = ['label' => 'Game — ' . $g['title'],
            'url' => url('play.php?game=' . urlencode($g['slug'])), 'kind' => 'game'];
    }
    foreach (fetch_all('SELECT slug, title FROM blog_posts WHERE status = "published"
                         ORDER BY published_at DESC LIMIT 300') as $b) {
        $out['post:' . $b['slug']] = ['label' => 'Blog — ' . $b['title'],
            'url' => url('post.php?slug=' . urlencode($b['slug'])), 'kind' => 'post'];
    }
    return $out;
}

/** The saved override for a page, or []. */
function seo_override(string $key): array
{
    static $cache = [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (!has_table('seo_pages')) {
        return $cache[$key] = [];
    }
    $r = fetch_one('SELECT * FROM seo_pages WHERE page_key = ?', [$key]);
    return $cache[$key] = $r ?: [];
}

function seo_clip(string $s, int $len): string
{
    $s = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8')));
    return mb_strlen($s) > $len ? rtrim(mb_substr($s, 0, $len - 1)) . '…' : $s;
}

/** The address search engines should treat as the one true URL of this page. */
function seo_canonical_default(): string
{
    $path  = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $base  = parse_url(SITE_URL, PHP_URL_PATH) ?: '';
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $path = ltrim($path, '/');
    if ($path === 'index.php') {
        $path = '';
    }
    // keep only the parameters that change what the page is about
    $keep = [];
    foreach (['game', 'slug', 'cat', 'page'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '' && is_scalar($_GET[$k])) {
            $keep[$k] = (string) $_GET[$k];
        }
    }
    return url($path) . ($keep ? '?' . http_build_query($keep) : '');
}

/** Absolute URL for an image stored by the SEO manager, or as given. */
function seo_image_url(string $v): string
{
    if ($v === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $v)) {
        return $v;
    }
    if (is_file(UPLOAD_PATH . '/seo/' . $v)) {
        return UPLOAD_URL . '/seo/' . rawurlencode($v);
    }
    return url($v);
}

/**
 * Everything <head> needs for this page.
 *
 * @param array $in  title, desc, image, type, key, schema (array of JSON-LD
 *                   objects), crumbs ([label => url, ...]), noindex (bool)
 */
function seo_resolve(array $in = []): array
{
    $site    = setting('site_name', 'Asviora Games');
    $tagline = setting('site_tagline', 'Where Skill Wins');
    $key     = $in['key'] ?? seo_page_key();
    $o       = seo_override($key);

    // ---- title -----------------------------------------------------
    $pageT = html_entity_decode((string) ($in['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    if (!empty($o['title'])) {
        $title = $o['title'];
    } elseif ($key === 'home' || $pageT === '') {
        $title = setting('seo_home_title') ?: ($site . ' — ' . $tagline);
    } else {
        $fmt   = setting('seo_title_format', '{page} | {site}') ?: '{page} | {site}';
        $title = strtr($fmt, ['{page}' => $pageT, '{site}' => $site, '{tagline}' => $tagline]);
    }

    // ---- description + keywords -------------------------------------
    $desc = !empty($o['description']) ? $o['description']
          : ((string) ($in['desc'] ?? '') !== '' ? (string) $in['desc'] : setting('site_description'));
    $desc = seo_clip($desc, 300);

    $kw = !empty($o['keywords']) ? $o['keywords'] : ($in['keywords'] ?? setting('seo_keywords'));

    // ---- image ------------------------------------------------------
    $img = !empty($o['og_image']) ? seo_image_url($o['og_image'])
         : ((string) ($in['image'] ?? '') !== '' ? (string) $in['image']
         : (setting('seo_og_image') !== '' ? seo_image_url(setting('seo_og_image')) : logo_wide()));

    // ---- robots -----------------------------------------------------
    $robots = 'index,follow,max-image-preview:large';
    if (!empty($in['noindex']) || in_array($key, seo_private_keys(), true)) {
        $robots = 'noindex,nofollow';
    }
    if (!empty($o['robots'])) {
        $robots = $o['robots'];
    }
    if (setting('seo_noindex_site', '0') === '1') {
        $robots = 'noindex,nofollow';
    }

    $canonical = !empty($o['canonical']) ? $o['canonical'] : seo_canonical_default();

    // ---- structured data ---------------------------------------------
    $schema = [];
    $org = [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => setting('seo_org_name') ?: $site,
        'url'      => url(''),
        'logo'     => logo_icon(512),
    ];
    $same = array_values(array_filter([
        setting('social_facebook'), setting('social_instagram'),
        setting('social_youtube'), setting('social_twitter'),
    ]));
    if ($same) {
        $org['sameAs'] = $same;
    }
    if (setting('contact_email') !== '') {
        $org['contactPoint'] = ['@type' => 'ContactPoint', 'contactType' => 'customer support',
                                'email' => setting('contact_email')]
                             + (setting('contact_phone') !== ''
                                ? ['telephone' => setting('contact_phone')] : []);
    }
    $schema[] = $org;

    if ($key === 'home') {
        $schema[] = [
            '@context'    => 'https://schema.org',
            '@type'       => 'WebSite',
            'name'        => $site,
            'alternateName' => $tagline,
            'url'         => url(''),
            'description' => $desc,
            'inLanguage'  => 'en-IN',
        ];
    }

    $crumbs = $in['crumbs'] ?? null;
    if ($crumbs === null && $key !== 'home' && $pageT !== '') {
        $crumbs = [$pageT => $canonical];
    }
    if ($crumbs) {
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => url('')]];
        $n = 2;
        foreach ($crumbs as $label => $u) {
            $items[] = ['@type' => 'ListItem', 'position' => $n++, 'name' => (string) $label,
                        'item' => $u];
        }
        $schema[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
                     'itemListElement' => $items];
    }
    foreach (($in['schema'] ?? []) as $s) {
        $schema[] = $s;
    }

    return [
        'key'         => $key,
        'title'       => seo_clip($title, 120),
        'description' => $desc,
        'keywords'    => seo_clip((string) $kw, 400),
        'image'       => $img,
        'canonical'   => $canonical,
        'robots'      => $robots,
        'type'        => $in['type'] ?? ($key === 'home' ? 'website' : 'article'),
        'site'        => $site,
        'schema'      => $schema,
    ];
}

/** The <head> block. */
function seo_head(array $s): string
{
    $h  = '<title>' . e($s['title']) . "</title>\n";
    $h .= '<meta name="description" content="' . e($s['description']) . "\">\n";
    if ($s['keywords'] !== '') {
        $h .= '<meta name="keywords" content="' . e($s['keywords']) . "\">\n";
    }
    $h .= '<meta name="robots" content="' . e($s['robots']) . "\">\n";
    $h .= '<link rel="canonical" href="' . e($s['canonical']) . "\">\n";

    $h .= '<meta property="og:site_name" content="' . e($s['site']) . "\">\n";
    $h .= '<meta property="og:locale" content="en_IN">' . "\n";
    $h .= '<meta property="og:type" content="' . e($s['type'] === 'article' ? 'article' : 'website') . "\">\n";
    $h .= '<meta property="og:title" content="' . e($s['title']) . "\">\n";
    $h .= '<meta property="og:description" content="' . e($s['description']) . "\">\n";
    $h .= '<meta property="og:url" content="' . e($s['canonical']) . "\">\n";
    $h .= '<meta property="og:image" content="' . e($s['image']) . "\">\n";
    $h .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
    $h .= '<meta name="twitter:title" content="' . e($s['title']) . "\">\n";
    $h .= '<meta name="twitter:description" content="' . e($s['description']) . "\">\n";
    $h .= '<meta name="twitter:image" content="' . e($s['image']) . "\">\n";
    if (($tw = setting('seo_twitter')) !== '') {
        $h .= '<meta name="twitter:site" content="@' . e(ltrim($tw, '@')) . "\">\n";
    }

    if (($g = setting('seo_google_verify')) !== '') {
        $h .= '<meta name="google-site-verification" content="' . e($g) . "\">\n";
    }
    if (($b = setting('seo_bing_verify')) !== '') {
        $h .= '<meta name="msvalidate.01" content="' . e($b) . "\">\n";
    }

    foreach ($s['schema'] as $obj) {
        $h .= '<script type="application/ld+json">'
            . json_encode($obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
            . "</script>\n";
    }

    // analytics — only on pages that are meant to be public
    $gtm = setting('seo_gtm_id');
    $ga  = setting('seo_ga4_id');
    if ($gtm !== '' && preg_match('/^GTM-[A-Z0-9]+$/i', $gtm)) {
        $h .= "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});"
            . "var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;"
            . "j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);"
            . "})(window,document,'script','dataLayer','" . e($gtm) . "');</script>\n";
    } elseif ($ga !== '' && preg_match('/^G-[A-Z0-9]+$/i', $ga)) {
        $h .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . e($ga) . "\"></script>\n"
            . "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
            . "gtag('js',new Date());gtag('config','" . e($ga) . "');</script>\n";
    }
    return $h;
}

/** Right after <body>: the Tag Manager fallback. */
function seo_body_open(): string
{
    $gtm = setting('seo_gtm_id');
    if ($gtm === '' || !preg_match('/^GTM-[A-Z0-9]+$/i', $gtm)) {
        return '';
    }
    return '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . e($gtm)
         . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
}

// ---------------------------------------------------------------
// Audit — what the SEO manager's score column shows
// ---------------------------------------------------------------

/**
 * Quick checks on one resolved page. Returns [score 0-100, [notes]].
 * The rules are the usual ones: a 30–60 character title, a 70–160
 * character description, keywords present, a share image, indexable.
 */
function seo_audit(array $s, string $label = ''): array
{
    $score = 100;
    $notes = [];
    $tl = mb_strlen($s['title']);
    $dl = mb_strlen($s['description']);

    if ($tl < 30)      { $score -= 15; $notes[] = 'Title is short (' . $tl . ' chars — aim for 30-60).'; }
    elseif ($tl > 65)  { $score -= 10; $notes[] = 'Title is long (' . $tl . ' chars — Google cuts after ~60).'; }
    if ($dl < 70)      { $score -= 20; $notes[] = 'Description is short (' . $dl . ' chars — aim for 70-160).'; }
    elseif ($dl > 165) { $score -= 10; $notes[] = 'Description is long (' . $dl . ' chars — cut to 160).'; }
    if (trim($s['keywords']) === '') { $score -= 10; $notes[] = 'No keywords.'; }
    if (str_contains($s['robots'], 'noindex')) { $score -= 30; $notes[] = 'Page is hidden from search (noindex).'; }

    $first = strtolower(trim(explode(',', $s['keywords'])[0] ?? ''));
    if ($first !== '' && !str_contains(strtolower($s['title'] . ' ' . $s['description']), $first)) {
        $score -= 10;
        $notes[] = 'Main keyword “' . $first . '” is not in the title or description.';
    }
    return [max(0, $score), $notes];
}

// ---------------------------------------------------------------
// AI helper (Claude)
// ---------------------------------------------------------------

function seo_ai_ready(): bool
{
    return setting('seo_ai_key') !== '' && function_exists('curl_init');
}

/**
 * Ask Claude for SEO copy for one page.
 *
 * @return array{ok:bool, title?:string, description?:string, keywords?:string, error?:string}
 */
function seo_ai_suggest(string $label, string $pageUrl, array $current, string $context = ''): array
{
    if (!seo_ai_ready()) {
        return ['ok' => false, 'error' => 'Add a Claude API key in SEO Manager → AI first.'];
    }
    $site  = setting('site_name', 'Asviora Games');
    $focus = setting('seo_ai_focus', 'skill games, quiz, India');

    $prompt = "You are an SEO specialist writing meta tags for a page on \"{$site}\", "
        . "a skill-gaming website (browser games, quiz contest, daily spin, chess) for players in India.\n\n"
        . "Page: {$label}\nURL: {$pageUrl}\n"
        . "Current title: " . ($current['title'] ?? '') . "\n"
        . "Current description: " . ($current['description'] ?? '') . "\n"
        . "Current keywords: " . ($current['keywords'] ?? '') . "\n"
        . ($context !== '' ? "About the page: " . mb_substr($context, 0, 1200) . "\n" : '')
        . "Focus topics: {$focus}\n\n"
        . "Write: a title of 45-60 characters that starts with the main keyword and ends with \" | {$site}\" "
        . "only if it fits; a meta description of 140-158 characters with a clear call to action; "
        . "and 8-12 comma-separated keywords people in India actually search, most important first. "
        . "Do not invent prizes, money or features that are not mentioned. "
        . "Reply with ONLY a JSON object: {\"title\":\"...\",\"description\":\"...\",\"keywords\":\"...\"}";

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . setting('seo_ai_key'),
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model'      => setting('seo_ai_model', 'claude-sonnet-4-5') ?: 'claude-sonnet-4-5',
            'max_tokens' => 600,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]),
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'Could not reach the Claude API: ' . $cerr];
    }
    $j = json_decode((string) $raw, true);
    if ($code !== 200) {
        return ['ok' => false, 'error' => 'Claude API: ' . ($j['error']['message'] ?? ('HTTP ' . $code))];
    }
    $text = '';
    foreach ($j['content'] ?? [] as $c) {
        if (($c['type'] ?? '') === 'text') {
            $text .= $c['text'];
        }
    }
    if (!preg_match('/\{.*\}/s', $text, $m)) {
        return ['ok' => false, 'error' => 'The AI answer could not be read. Try again.'];
    }
    $out = json_decode($m[0], true);
    if (!is_array($out)) {
        return ['ok' => false, 'error' => 'The AI answer could not be read. Try again.'];
    }
    return [
        'ok'          => true,
        'title'       => seo_clip((string) ($out['title'] ?? ''), 120),
        'description' => seo_clip((string) ($out['description'] ?? ''), 300),
        'keywords'    => seo_clip((string) ($out['keywords'] ?? ''), 400),
    ];
}
