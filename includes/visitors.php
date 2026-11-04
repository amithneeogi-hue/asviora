<?php
/**
 * Visitor analytics.
 * ------------------------------------------------------------------
 * One row in `visits` per page view, with the day, the hour, the page,
 * the device and — where it can be worked out — the country, region and
 * city it came from.
 *
 * Location is resolved in the cheapest order:
 *
 *   1. a header the host already set (Cloudflare's CF-IPCountry and the
 *      handful of equivalents other CDNs use). Free, no network call.
 *   2. the `ip_geo` cache table. One row per address, looked up once.
 *   3. one HTTP call to ip-api.com, with a hard 1.2s timeout, and at
 *      most ONE such call per request. Anything not resolved in time is
 *      left as `geo_state = pending` and picked up later — either by a
 *      following request or by the "Resolve pending" button in
 *      Admin -> Visitors. A slow lookup can therefore never hold up a
 *      page for a player.
 *
 * Nothing here throws on an install that has not run the v6 migration
 * yet: every function checks its table first and quietly does nothing.
 */

// ---------------------------------------------------------------
// Switches
// ---------------------------------------------------------------

/** Is visitor logging installed and switched on? */
function analytics_on(): bool
{
    static $on = null;
    if ($on !== null) {
        return $on;
    }
    return $on = (setting('analytics_enabled', '1') === '1'
                  && module_on('analytics')
                  && has_table('visits'));
}

/** 'off' | 'header' | 'auto' */
function analytics_geo_mode(): string
{
    $m = setting('analytics_geo_mode', 'auto');
    return in_array($m, ['off', 'header', 'auto'], true) ? $m : 'auto';
}

/** Leave 404s and asset requests (favicon, robots, images, CSS/JS) out of the log. */
function analytics_ignore_noise(): bool
{
    return setting('analytics_ignore_404', '1') === '1';
}

/** Leave traffic from known cloud / hosting / proxy providers out of the log. */
function analytics_ignore_datacenter(): bool
{
    return setting('analytics_ignore_datacenter', '1') === '1';
}

/**
 * The salt behind the visitor hash. Generated once and stored, so the
 * same device keeps the same key — but the raw IP can never be worked
 * back out of it by anyone who only has the database.
 */
function analytics_salt(): string
{
    $s = setting('analytics_salt');
    if ($s === '') {
        $s = bin2hex(random_bytes(16));
        set_setting('analytics_salt', $s);
    }
    return $s;
}

// ---------------------------------------------------------------
// Noise filters
// ---------------------------------------------------------------

/**
 * Is this request a real page, or just an asset / probe / 404?
 *
 * Analytics used to count /favicon.ico, /.well-known/assetlinks.json and
 * hundreds of WordPress / .env scans as "visitors", which made the country
 * report look like the site had a global audience of bots.
 */
function analytics_is_noise_path(string $path): bool
{
    $p = '/' . ltrim(strtolower($path), '/');

    if ($p === '/') {
        return false;
    }

    $exact = [
        '/favicon.ico', '/robots.txt', '/sitemap.xml', '/sitemap_index.xml',
        '/apple-touch-icon.png', '/apple-touch-icon-precomposed.png',
        '/browserconfig.xml', '/site.webmanifest', '/manifest.json',
        '/ads.txt', '/security.txt', '/xmlrpc.php',
    ];
    if (in_array($p, $exact, true)) {
        return true;
    }
    if (str_starts_with($p, '/.well-known/')) {
        return true;
    }
    if (preg_match('#^/(wp-admin|wp-login|wp-content|wp-includes|wp-json|cgi-bin|phpmyadmin|pma|myadmin|adminer)(/|\.|$)#', $p)) {
        return true;
    }
    foreach (['/assets/', '/uploads/', '/games/', '/storage/', '/api/', '/admin/'] as $pre) {
        if (str_starts_with($p, $pre)) {
            return true;
        }
    }
    if (preg_match('/\.(ico|css|js|mjs|json|png|jpe?g|gif|webp|svg|bmp|woff2?|ttf|eot|otf|map|txt|xml|sql|env|bak|old|zip|gz|rar|pdf)$/i', $p)) {
        return true;
    }
    return false;
}

/**
 * Does this ISP look like a cloud / hosting / proxy provider rather than a
 * normal home or mobile connection? Those addresses are overwhelmingly
 * crawlers, scanners, uptime monitors and bots.
 */
function isp_is_datacenter(?string $isp): bool
{
    $s = strtolower(trim((string) $isp));
    if ($s === '') {
        return false;
    }
    return (bool) preg_match(
        '/amazon|aws|digitalocean|google|microsoft|azure|cloudflare|ovh|hetzner|'
        . 'leaseweb|akamai|fastly|scaleway|zenlayer|layerswitch|colocation|'
        . 'hostroyale|quickpacket|egihosting|logicweb|b2 net|ip-only|'
        . 'internet viking|glesys|portlane|netcup|ultahost|dogado|contabo|'
        . 'linode|vultr|oracle cloud|alibaba|tencent|m247|psychz|choopa|'
        . 'frantech|buyvm|hosthatch|hostinger|godaddy|namecheap|ionos|strato|'
        . 'serverhub|quadranet|sharktech|fiberpower|bl networks|hivelocity|'
        . 'onyphe|3xk tech|ace data centers|cogent|ntt america|zscaler|'
        . 'facebook, inc|active search internet|acuit|korte|gsys|bolaget|'
        . 'packet|hosting|data ?cent(er|re)|vpn|proxy/i',
        $s
    );
}

// ---------------------------------------------------------------
// The visitor
// ---------------------------------------------------------------

/**
 * The real client IP.
 *
 * REMOTE_ADDR is the only value the client cannot fake. Proxy/CDN headers
 * are trusted only when the admin explicitly says the site sits behind one
 * (Admin -> Visitors -> Trust proxy headers), because otherwise any bot can
 * send an X-Forwarded-For of its choosing and invent a country.
 */
function visitor_ip(): string
{
    $remote = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    if (setting('analytics_trust_proxy', '0') !== '1') {
        return $remote;
    }

    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP',
             'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'];

    foreach ($keys as $k) {
        if (empty($_SERVER[$k])) {
            continue;
        }
        // X-Forwarded-For can be a chain: the client is the first entry
        foreach (explode(',', (string) $_SERVER[$k]) as $part) {
            $ip = trim($part);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return substr($ip, 0, 45);
            }
        }
    }
    return $remote;
}

/** Is this a LAN / loopback address? Those can never be geolocated. */
function ip_is_private(string $ip): bool
{
    if ($ip === '') {
        return true;
    }
    return !filter_var($ip, FILTER_VALIDATE_IP,
                       FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** A stable, non-reversible id for this device. */
function visitor_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    return $key = sha1(visitor_ip() . '|'
                     . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
                     . '|' . analytics_salt());
}

/** Device / browser / OS, worked out from the user agent string. */
function visitor_agent(): array
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $l  = strtolower($ua);

    $bot = ($ua === '') || (bool) preg_match(
        '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp|telegram|'
        . 'headless|lighthouse|pingdom|uptime|monitor|curl|wget|python-requests|axios|'
        . 'go-http|java\/|scrapy|semrush|ahrefs|mj12|dotbot|petalbot|yandex|duckduck|'
        . 'googleother|google-inspectiontool|google-read-aloud|adsbot|mediapartners|'
        . 'feedfetcher|apis-google|google-site-verification|pagespeed|gtmetrix|'
        . 'site24x7|statuscake|newrelic|datadog|uptimerobot|hetrix|freshping|'
        . 'phantomjs|puppeteer|playwright|selenium|headlesschrome|'
        . 'facebookcatalog|linkedinbot|twitterbot|pinterestbot|applebot|'
        . 'discordbot|embedly|outbrain|masscan|nmap|nikto|sqlmap|zmeu|zgrab|'
        . 'nuclei|censys|shodan|internetmeasurement|binaryedge|expanse/i', $ua);

    if ($bot) {
        $device = 'bot';
    } elseif (preg_match('/ipad|tablet|playbook|silk|(android(?!.*mobile))/i', $ua)) {
        $device = 'tablet';
    } elseif (preg_match('/mobile|iphone|ipod|android|blackberry|opera mini|iemobile|windows phone/i', $ua)) {
        $device = 'mobile';
    } elseif (preg_match('/windows|macintosh|mac os|linux|cros|x11/i', $ua)) {
        $device = 'desktop';
    } else {
        $device = 'other';
    }

    $browser = 'Other';
    foreach ([
        'Edge'      => '/edg(e|a|ios)?\//i',
        'Opera'     => '/opr\/|opera/i',
        'Samsung'   => '/samsungbrowser/i',
        'UC Browser'=> '/ucbrowser/i',
        'Chrome'    => '/chrome|crios/i',
        'Firefox'   => '/firefox|fxios/i',
        'Safari'    => '/safari/i',
        'IE'        => '/msie|trident/i',
    ] as $name => $re) {
        if (preg_match($re, $ua)) { $browser = $name; break; }
    }

    $os = 'Other';
    foreach ([
        'Android' => '/android/i',
        'iOS'     => '/iphone|ipad|ipod|ios/i',
        'Windows' => '/windows/i',
        'macOS'   => '/macintosh|mac os x/i',
        'Linux'   => '/linux|x11/i',
        'ChromeOS'=> '/cros/i',
    ] as $name => $re) {
        if (preg_match($re, $ua)) { $os = $name; break; }
    }

    return ['device' => $device, 'browser' => $browser, 'os' => $os, 'is_bot' => $bot];
}

// ---------------------------------------------------------------
// Location
// ---------------------------------------------------------------

/**
 * Country the hosting layer already told us about — Cloudflare and most
 * CDNs set one of these. Costs nothing, so it is always tried first.
 */
function geo_from_headers(): ?array
{
    foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY',
              'HTTP_GEOIP_COUNTRY_CODE', 'HTTP_X_APPENGINE_COUNTRY'] as $h) {
        $cc = strtoupper(trim((string) ($_SERVER[$h] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX' && $cc !== 'T1') {
            $city   = trim((string) ($_SERVER['HTTP_CF_IPCITY']
                                   ?? $_SERVER['HTTP_X_APPENGINE_CITY'] ?? ''));
            $region = trim((string) ($_SERVER['HTTP_CF_REGION']
                                   ?? $_SERVER['HTTP_X_APPENGINE_REGION'] ?? ''));
            return [
                'country_code' => $cc,
                'country'      => country_name($cc),
                'region'       => $region !== '' ? mb_substr($region, 0, 80) : null,
                'city'         => $city   !== '' ? mb_substr(ucwords($city), 0, 80) : null,
                'isp'          => null,
                'source'       => 'header',
            ];
        }
    }
    return null;
}

/** The cached row for an IP, or null. */
function geo_cached(string $ip): ?array
{
    if (!has_table('ip_geo') || $ip === '') {
        return null;
    }
    return fetch_one('SELECT * FROM ip_geo WHERE ip = ?', [$ip]);
}

/** Write (or refresh) the cache row for an IP. */
function geo_cache_put(string $ip, array $g): void
{
    if (!has_table('ip_geo') || $ip === '') {
        return;
    }
    q('INSERT INTO ip_geo (ip, country_code, country, region, city, isp, source)
       VALUES (?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE country_code = VALUES(country_code),
                               country      = VALUES(country),
                               region       = VALUES(region),
                               city         = VALUES(city),
                               isp          = VALUES(isp),
                               source       = VALUES(source),
                               looked_up_at = CURRENT_TIMESTAMP',
      [$ip, $g['country_code'] ?? null, $g['country'] ?? null, $g['region'] ?? null,
       $g['city'] ?? null, $g['isp'] ?? null, $g['source'] ?? 'api']);
}

/**
 * Ask ip-api.com where an address is. Hard 1.2 second ceiling; a slow or
 * unreachable service simply returns null and the view stays pending.
 */
function geo_api_lookup(string $ip): ?array
{
    if (ip_is_private($ip)) {
        return null;
    }

    $url = 'http://ip-api.com/json/' . rawurlencode($ip)
         . '?fields=status,country,countryCode,regionName,city,isp';
    $raw = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'AsvioraGames/1.0',
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'timeout' => 2, 'method' => 'GET',
            'header'  => "User-Agent: AsvioraGames/1.0\r\n",
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
    }

    if (!$raw) {
        return null;
    }
    $d = json_decode($raw, true);
    if (!is_array($d) || ($d['status'] ?? '') !== 'success') {
        return null;
    }

    return [
        'country_code' => strtoupper(mb_substr((string) ($d['countryCode'] ?? ''), 0, 2)) ?: null,
        'country'      => mb_substr((string) ($d['country'] ?? ''), 0, 80) ?: null,
        'region'       => mb_substr((string) ($d['regionName'] ?? ''), 0, 80) ?: null,
        'city'         => mb_substr((string) ($d['city'] ?? ''), 0, 80) ?: null,
        'isp'          => mb_substr((string) ($d['isp'] ?? ''), 0, 120) ?: null,
        'source'       => 'api',
    ];
}

/** True when a location has a region and a city, not just a country. */
function geo_has_detail(?array $g): bool
{
    return $g !== null
        && trim((string) ($g['region'] ?? '')) !== ''
        && trim((string) ($g['city'] ?? '')) !== '';
}

/** Fill gaps in $base with non-empty values from $extra. */
function geo_merge(array $base, array $extra): array
{
    foreach (['country_code', 'country', 'region', 'city', 'isp'] as $k) {
        if (trim((string) ($extra[$k] ?? '')) !== '') {
            $base[$k] = $extra[$k];
        }
    }
    if (!empty($extra['source'])) {
        $base['source'] = $base['source'] !== $extra['source']
            ? 'header+api' : $extra['source'];
    }
    return $base;
}

/**
 * Best location we can get for an address without stalling the page.
 *
 * A CDN usually sends only the country (Cloudflare's CF-IPCountry, for
 * example), so a header result alone is not enough in "auto" mode: the
 * IP lookup is still tried once to fill region and city, and the header
 * values win where both know the answer.
 *
 * @param bool $allowApi false on the hot path once one call has already
 *                       been made this request.
 */
function geo_for_ip(string $ip, bool $allowApi = true): ?array
{
    $mode = analytics_geo_mode();
    if ($mode === 'off') {
        return null;
    }

    $h = geo_from_headers();

    // Header-only mode is deliberately country-only and never calls out.
    if ($mode === 'header') {
        if ($h !== null) {
            geo_cache_put($ip, $h);
        }
        return $h;
    }

    $c = geo_cached($ip);

    // A full header result (paid Cloudflare plans and some hosts) is best.
    if ($h !== null && geo_has_detail($h)) {
        geo_cache_put($ip, $h);
        return $h;
    }

    // A cached full result needs no lookup; refresh the country from the
    // header if one is available.
    if ($c !== null && geo_has_detail($c)) {
        if ($h !== null) {
            $c = geo_merge($c, $h);
            geo_cache_put($ip, $c);
        }
        return $c;
    }

    $base = $h ?? $c;

    if (ip_is_private($ip)) {
        if ($base === null) {
            $base = ['country_code' => null, 'country' => 'Local network',
                     'region' => null, 'city' => null, 'source' => 'local'];
        }
        geo_cache_put($ip, $base);
        return (($base['country'] ?? '') === 'Local network') ? null : $base;
    }

    // Auto mode: ask the IP lookup for the missing region/city.
    if (!$allowApi) {
        return $base;
    }

    $g = geo_api_lookup($ip);
    if ($g !== null) {
        if ($h !== null) {
            $g = geo_merge($g, $h);
        } elseif ($c !== null) {
            $g = geo_merge($g, $c);
        }
        geo_cache_put($ip, $g);
        return $g;
    }

    if ($base !== null) {
        geo_cache_put($ip, $base);
    }
    return $base;
}

// ---------------------------------------------------------------
// Recording a view
// ---------------------------------------------------------------

/**
 * Log the current page view. Called once from includes/header.php.
 *
 * Repeat views of the same page by the same device inside 30 minutes are
 * folded together, so a player refreshing a game page does not turn into
 * fifty rows.
 */
function track_visit(?string $pageTitle = null): void
{
    static $done = false;

    if ($done || !analytics_on()) {
        return;
    }
    $done = true;

    try {
        // Only real GET page views. HEAD probes, POSTs and asset requests
        // are not visitors.
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return;
        }

        $page = mb_substr((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), 0, 190);
        if ($page === '') {
            $page = '/';
        }

        if (analytics_ignore_noise()) {
            if (http_response_code() === 404) {
                return;
            }
            if (analytics_is_noise_path($page)) {
                return;
            }
        }

        $agent = visitor_agent();

        if ($agent['is_bot'] && setting('analytics_ignore_bots', '1') === '1') {
            return;
        }
        if (setting('analytics_ignore_admin', '1') === '1' && !empty($_SESSION['admin_id'])) {
            return;
        }

        $ip  = visitor_ip();
        $key = visitor_key();

        // Cheap early exit when the ISP is already cached.
        if (analytics_ignore_datacenter()) {
            $cached = geo_cached($ip);
            if ($cached !== null && isp_is_datacenter($cached['isp'] ?? null)) {
                return;
            }
        }

        // fold refreshes of the same page together
        $recent = fetch_col(
            'SELECT id FROM visits
              WHERE visitor_key = ? AND page = ? AND created_at > (NOW() - INTERVAL 30 MINUTE)
              ORDER BY id DESC LIMIT 1', [$key, $page], 0);
        if ($recent) {
            return;
        }

        // LIMIT 1, not COUNT(*) — we only need to know whether this device
        // has ever been here, and the log can grow to millions of rows.
        $seenBefore = (int) fetch_col(
            'SELECT 1 FROM visits WHERE visitor_key = ? LIMIT 1', [$key], 0);

        $ref     = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $refHost = $ref !== '' ? (string) parse_url($ref, PHP_URL_HOST) : '';
        // our own pages are not a referrer worth reporting
        if ($refHost !== '' && $refHost === (string) parse_url(SITE_URL, PHP_URL_HOST)) {
            $ref = $refHost = '';
        }

        // has this device already been counted today?
        $firstToday = !fetch_col(
            'SELECT 1 FROM visits WHERE visitor_key = ? AND visit_date = CURDATE() LIMIT 1',
            [$key], 0);

        $geo = geo_for_ip($ip, true);

        // A fresh lookup may now tell us this is a cloud/hosting address.
        if (analytics_ignore_datacenter() && $geo !== null
            && isp_is_datacenter($geo['isp'] ?? null)) {
            return;
        }

        $state = $geo ? 'done' : (ip_is_private($ip) || analytics_geo_mode() === 'off'
                                  ? 'skipped' : 'pending');

        insert_row('visits', [
            'visitor_key'   => $key,
            'user_id'       => is_logged_in() ? (int) $_SESSION['user_id'] : null,
            'ip_address'    => $ip ?: null,
            'page'          => $page,
            'page_title'    => $pageTitle ? mb_substr($pageTitle, 0, 160) : null,
            'referrer'      => $ref !== '' ? mb_substr($ref, 0, 190) : null,
            'referrer_host' => $refHost !== '' ? mb_substr($refHost, 0, 120) : null,
            'country_code'  => $geo['country_code'] ?? null,
            'country'       => $geo['country'] ?? null,
            'region'        => $geo['region'] ?? null,
            'city'          => $geo['city'] ?? null,
            'device'        => $agent['device'],
            'browser'       => $agent['browser'],
            'os'            => $agent['os'],
            'is_new'        => $seenBefore === 0 ? 1 : 0,
            'geo_state'     => $state,
            'visit_date'    => date('Y-m-d'),
            'visit_hour'    => (int) date('G'),
        ]);

        visit_rollup_bump(date('Y-m-d'), $geo['country_code'] ?? '--',
                          $firstToday, is_logged_in());

    } catch (Throwable $e) {
        // analytics must never break a page
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('[visits] ' . $e->getMessage());
        }
    }
}

/**
 * Recount one day/country cell of the roll-up from the raw table.
 * Accurate but not cheap — used after a batch geo resolve, never on the
 * hot path. track_visit() uses visit_rollup_bump() instead.
 */
function visit_rollup(string $day, ?string $cc): void
{
    if (!has_table('visit_daily')) {
        return;
    }
    $cc = $cc && preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '--';

    $row = fetch_one(
        'SELECT COUNT(*) AS views,
                COUNT(DISTINCT visitor_key) AS visitors,
                COUNT(DISTINCT user_id) AS members
           FROM visits
          WHERE visit_date = ? AND COALESCE(country_code, "--") = ?', [$day, $cc]);

    q('INSERT INTO visit_daily (visit_date, country_code, views, visitors, members)
       VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE views = VALUES(views),
                               visitors = VALUES(visitors),
                               members = VALUES(members)',
      [$day, $cc, (int) $row['views'], (int) $row['visitors'], (int) $row['members']]);
}

/**
 * Add one view to the roll-up. A single indexed UPSERT, so it costs the
 * same whether the log holds a hundred rows or ten million.
 */
function visit_rollup_bump(string $day, ?string $cc, bool $firstToday, bool $isMember): void
{
    if (!has_table('visit_daily')) {
        return;
    }
    $cc = $cc && preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '--';
    $v  = $firstToday ? 1 : 0;
    $m  = ($firstToday && $isMember) ? 1 : 0;

    q('INSERT INTO visit_daily (visit_date, country_code, views, visitors, members)
       VALUES (?,?,1,?,?)
       ON DUPLICATE KEY UPDATE views    = views + 1,
                               visitors = visitors + ' . $v . ',
                               members  = members + ' . $m,
      [$day, $cc, $v, $m]);
}

/**
 * Fill in the location for views that were left pending.
 * Called from Admin -> Visitors; ip-api's free tier allows ~45 lookups a
 * minute, so the batch is deliberately small.
 */
function resolve_pending_geo(int $limit = 25): int
{
    // "Header only" promises no outside calls, so only auto mode may look up.
    if (!analytics_on() || analytics_geo_mode() !== 'auto') {
        return 0;
    }

    // Include older rows that got a country from a CDN header but never got
    // a region/city, which is the usual Cloudflare case.
    $ips = fetch_all(
        'SELECT ip_address, COUNT(*) AS n FROM visits
          WHERE ip_address IS NOT NULL
            AND (geo_state = "pending"
                 OR (geo_state = "done"
                     AND country_code IS NOT NULL
                     AND COALESCE(country, "") <> "Local network"
                     AND (region IS NULL OR region = "" OR city IS NULL OR city = "")))
          GROUP BY ip_address ORDER BY n DESC LIMIT ' . max(1, min(100, $limit)));

    $fixed = 0;
    foreach ($ips as $r) {
        $ip     = (string) $r['ip_address'];
        $cached = geo_cached($ip);
        $g      = $cached;

        // A cached country-only row is not enough - ask for the detail too.
        if ($g === null || !geo_has_detail($g)) {
            $api = geo_api_lookup($ip);
            if ($api !== null) {
                $g = $cached !== null ? geo_merge($api, $cached) : $api;
                geo_cache_put($ip, $g);
            }
        }

        if ($g === null || empty($g['country_code'])) {
            q('UPDATE visits SET geo_state = "skipped" WHERE ip_address = ? AND geo_state = "pending"',
              [$ip]);
            continue;
        }

        $fixed += q(
            'UPDATE visits SET country_code = ?, country = ?, region = ?, city = ?,
                    geo_state = "done"
              WHERE ip_address = ?
                AND (geo_state = "pending"
                     OR (geo_state = "done"
                         AND (region IS NULL OR region = "" OR city IS NULL OR city = "")))',
            [$g['country_code'], $g['country'], $g['region'], $g['city'], $ip])->rowCount();

        usleep(150000);          // stay well inside the free rate limit
    }

    if ($fixed) {
        foreach (fetch_all('SELECT DISTINCT visit_date FROM visits
                             WHERE visit_date > (CURDATE() - INTERVAL 60 DAY)') as $d) {
            foreach (fetch_all('SELECT DISTINCT COALESCE(country_code,"--") AS cc FROM visits
                                 WHERE visit_date = ?', [$d['visit_date']]) as $c) {
                visit_rollup($d['visit_date'], $c['cc']);
            }
        }
    }
    return $fixed;
}

/** Delete raw views older than the retention window. Returns rows removed. */
function purge_old_visits(?int $days = null): int
{
    if (!has_table('visits')) {
        return 0;
    }
    $days = max(7, (int) ($days ?? setting('analytics_retention_days', '365')));
    return q('DELETE FROM visits WHERE visit_date < (CURDATE() - INTERVAL ' . $days . ' DAY)')
             ->rowCount();
}

// ---------------------------------------------------------------
// Reading it back — used by Admin -> Visitors and the dashboard
// ---------------------------------------------------------------

/** [from, to, label] for a named range. */
function visit_range(string $key): array
{
    switch ($key) {
        case 'today':  return [date('Y-m-d'), date('Y-m-d'), 'Today'];
        case 'yest':   return [date('Y-m-d', strtotime('-1 day')),
                               date('Y-m-d', strtotime('-1 day')), 'Yesterday'];
        case '7d':     return [date('Y-m-d', strtotime('-6 day')), date('Y-m-d'), 'Last 7 days'];
        case '90d':    return [date('Y-m-d', strtotime('-89 day')), date('Y-m-d'), 'Last 90 days'];
        case 'month':  return [date('Y-m-01'), date('Y-m-t'), 'This month'];
        case 'year':   return [date('Y-01-01'), date('Y-12-31'), 'This year'];
        case 'all':    return ['2000-01-01', date('Y-m-d'), 'All time'];
        case '30d':
        default:       return [date('Y-m-d', strtotime('-29 day')), date('Y-m-d'), 'Last 30 days'];
    }
}

/** Headline totals for a date range. */
function visit_totals(string $from, string $to): array
{
    if (!has_table('visits')) {
        return ['views' => 0, 'visitors' => 0, 'members' => 0,
                'countries' => 0, 'cities' => 0, 'new' => 0];
    }
    $r = fetch_one(
        'SELECT COUNT(*) AS views,
                COUNT(DISTINCT visitor_key) AS visitors,
                COUNT(DISTINCT user_id) AS members,
                COUNT(DISTINCT country_code) AS countries,
                COUNT(DISTINCT CONCAT(COALESCE(country_code,""), "|", COALESCE(city,""))) AS cities,
                SUM(is_new) AS `new`
           FROM visits WHERE visit_date BETWEEN ? AND ?', [$from, $to]);

    return [
        'views'     => (int) $r['views'],
        'visitors'  => (int) $r['visitors'],
        'members'   => (int) $r['members'],
        'countries' => (int) $r['countries'],
        'cities'    => (int) $r['cities'],
        'new'       => (int) $r['new'],
    ];
}

/** Views + visitors per day, oldest first, with empty days filled in. */
function visits_by_day(string $from, string $to): array
{
    $out = [];
    $cur = strtotime($from);
    $end = strtotime($to);
    // never render more than ~120 columns
    if (($end - $cur) / 86400 > 120) {
        $cur = strtotime('-119 day', $end);
    }
    while ($cur <= $end) {
        $out[date('Y-m-d', $cur)] = ['views' => 0, 'visitors' => 0];
        $cur = strtotime('+1 day', $cur);
    }
    if (!has_table('visits')) {
        return $out;
    }
    foreach (fetch_all(
        'SELECT visit_date, COUNT(*) AS views, COUNT(DISTINCT visitor_key) AS visitors
           FROM visits WHERE visit_date BETWEEN ? AND ?
          GROUP BY visit_date', [$from, $to]) as $r) {
        if (isset($out[$r['visit_date']])) {
            $out[$r['visit_date']] = ['views' => (int) $r['views'],
                                      'visitors' => (int) $r['visitors']];
        }
    }
    return $out;
}

/** Views + visitors per calendar month. */
function visits_by_month(int $months = 12): array
{
    if (!has_table('visits')) {
        return [];
    }
    return fetch_all(
        'SELECT DATE_FORMAT(visit_date, "%Y-%m") AS ym,
                COUNT(*) AS views,
                COUNT(DISTINCT visitor_key) AS visitors,
                COUNT(DISTINCT user_id) AS members
           FROM visits
          WHERE visit_date > (CURDATE() - INTERVAL ' . max(1, $months) . ' MONTH)
          GROUP BY ym ORDER BY ym DESC');
}

/** Views + visitors per year. */
function visits_by_year(): array
{
    if (!has_table('visits')) {
        return [];
    }
    return fetch_all(
        'SELECT YEAR(visit_date) AS y,
                COUNT(*) AS views,
                COUNT(DISTINCT visitor_key) AS visitors,
                COUNT(DISTINCT user_id) AS members
           FROM visits GROUP BY y ORDER BY y DESC');
}

/** Per hour of the day, 0-23, for a range. */
function visits_by_hour(string $from, string $to): array
{
    $out = array_fill(0, 24, 0);
    if (!has_table('visits')) {
        return $out;
    }
    foreach (fetch_all(
        'SELECT visit_hour, COUNT(*) AS n FROM visits
          WHERE visit_date BETWEEN ? AND ? GROUP BY visit_hour', [$from, $to]) as $r) {
        $out[(int) $r['visit_hour']] = (int) $r['n'];
    }
    return $out;
}

/**
 * Group a range by any of the location / device columns.
 *
 * @param string $col country|region|city|page|device|browser|os|referrer_host
 */
function visits_group(string $col, string $from, string $to, int $limit = 15): array
{
    $allowed = ['country', 'country_code', 'region', 'city', 'page',
                'device', 'browser', 'os', 'referrer_host'];
    if (!in_array($col, $allowed, true) || !has_table('visits')) {
        return [];
    }

    // Group by the expression rather than by the alias: `country` and
    // `city` are also real column names, and an ambiguous GROUP BY is
    // rejected outright under ONLY_FULL_GROUP_BY.
    $expr = 'COALESCE(NULLIF(`' . $col . '`, ""), "Unknown")';

    return fetch_all(
        'SELECT ' . $expr . ' AS label,
                MAX(country_code) AS cc,
                COUNT(*) AS views,
                COUNT(DISTINCT visitor_key) AS visitors
           FROM visits
          WHERE visit_date BETWEEN ? AND ?
          GROUP BY ' . $expr . '
          ORDER BY views DESC
          LIMIT ' . max(1, min(100, $limit)), [$from, $to]);
}

/** Country + region + city together — the "in detail" view. */
function visits_by_place(string $from, string $to, int $limit = 25): array
{
    if (!has_table('visits')) {
        return [];
    }
    // Same reason as visits_group(): group by the expressions, not the
    // aliases, since the aliases shadow real column names.
    return fetch_all(
        'SELECT COALESCE(NULLIF(country, ""), "Unknown") AS country,
                country_code AS cc,
                COALESCE(NULLIF(region, ""), "—") AS region,
                COALESCE(NULLIF(city, ""), "—") AS city,
                COUNT(*) AS views,
                COUNT(DISTINCT visitor_key) AS visitors,
                MAX(created_at) AS last_seen
           FROM visits
          WHERE visit_date BETWEEN ? AND ?
          GROUP BY COALESCE(NULLIF(country, ""), "Unknown"),
                   country_code,
                   COALESCE(NULLIF(region, ""), "—"),
                   COALESCE(NULLIF(city, ""), "—")
          ORDER BY views DESC
          LIMIT ' . max(1, min(200, $limit)), [$from, $to]);
}

/** The most recent individual page views. */
function visits_recent(int $limit = 25): array
{
    if (!has_table('visits')) {
        return [];
    }
    return fetch_all(
        'SELECT v.*, u.username
           FROM visits v LEFT JOIN users u ON u.id = v.user_id
          ORDER BY v.id DESC LIMIT ' . max(1, min(200, $limit)));
}

/**
 * How many views still need location work: either no lookup at all, or a
 * country from a CDN header with no region/city.
 */
function visits_pending_geo(): int
{
    if (!has_table('visits')) {
        return 0;
    }
    return (int) fetch_col(
        'SELECT COUNT(*) FROM visits
          WHERE geo_state = "pending"
             OR (geo_state = "done"
                 AND country_code IS NOT NULL
                 AND COALESCE(country, "") <> "Local network"
                 AND (region IS NULL OR region = "" OR city IS NULL OR city = ""))', [], 0);
}

/** A flag emoji from a two-letter country code. */
function country_flag(?string $cc): string
{
    $cc = strtoupper((string) $cc);
    if (!preg_match('/^[A-Z]{2}$/', $cc)) {
        return '&#127760;';                       // globe
    }
    $out = '';
    foreach (str_split($cc) as $ch) {
        $out .= '&#' . (127397 + ord($ch)) . ';';
    }
    return $out;
}

/** Country name for a code — the ones a games site actually sees, then a fallback. */
function country_name(?string $cc): string
{
    static $map = [
        'IN' => 'India',        'BD' => 'Bangladesh',   'PK' => 'Pakistan',
        'NP' => 'Nepal',        'LK' => 'Sri Lanka',    'US' => 'United States',
        'GB' => 'United Kingdom','CA' => 'Canada',      'AU' => 'Australia',
        'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar',
        'KW' => 'Kuwait',       'OM' => 'Oman',         'BH' => 'Bahrain',
        'SG' => 'Singapore',    'MY' => 'Malaysia',     'ID' => 'Indonesia',
        'PH' => 'Philippines',  'TH' => 'Thailand',     'VN' => 'Vietnam',
        'CN' => 'China',        'JP' => 'Japan',        'KR' => 'South Korea',
        'DE' => 'Germany',      'FR' => 'France',       'IT' => 'Italy',
        'ES' => 'Spain',        'NL' => 'Netherlands',  'SE' => 'Sweden',
        'RU' => 'Russia',       'UA' => 'Ukraine',      'TR' => 'Turkey',
        'BR' => 'Brazil',       'MX' => 'Mexico',       'AR' => 'Argentina',
        'ZA' => 'South Africa', 'NG' => 'Nigeria',      'EG' => 'Egypt',
        'KE' => 'Kenya',        'NZ' => 'New Zealand',  'IE' => 'Ireland',
        'PL' => 'Poland',       'PT' => 'Portugal',     'CH' => 'Switzerland',
        'BE' => 'Belgium',      'AT' => 'Austria',      'NO' => 'Norway',
        'DK' => 'Denmark',      'FI' => 'Finland',      'IL' => 'Israel',
        'HK' => 'Hong Kong',    'TW' => 'Taiwan',       'BT' => 'Bhutan',
        'MV' => 'Maldives',     'MM' => 'Myanmar',      'AF' => 'Afghanistan',
    ];
    $cc = strtoupper((string) $cc);
    return $map[$cc] ?? ($cc !== '' ? $cc : 'Unknown');
}
