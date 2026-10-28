<?php
/**
 * Shared helper functions.
 */

// ---------------------------------------------------------------
// Output / escaping
// ---------------------------------------------------------------

/** HTML-escape. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build a site URL. */
function url(string $path = ''): string
{
    return SITE_URL . '/' . ltrim($path, '/');
}

/**
 * URL for a file in assets/, stamped with its last-modified time.
 *
 * .htaccess tells browsers to keep CSS and JS for a week, which is right for
 * visitors and painful after a deploy — the old file keeps being served long
 * after it was replaced. The ?v= changes whenever the file does, so a new
 * upload is fetched immediately and an unchanged one still comes from cache.
 */
function asset(string $path): string
{
    $rel  = ltrim($path, '/');
    $full = ROOT_PATH . '/' . $rel;
    $ver  = is_file($full) ? filemtime($full) : null;

    return url($rel) . ($ver ? '?v=' . $ver : '');
}

/** Redirect and stop. */
function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

/** Truncate plain text safely. */
function excerpt(?string $html, int $len = 160): string
{
    $t = trim(preg_replace('/\s+/', ' ', strip_tags((string) $html)));
    if (mb_strlen($t) <= $len) {
        return $t;
    }
    return mb_substr($t, 0, $len - 1) . '&hellip;';
}

/** Format an integer with thousands separators. */
function num($n): string
{
    return number_format((float) $n);
}

/** Human date. */
function fdate(?string $dt, string $fmt = 'd M Y'): string
{
    if (!$dt || $dt === '0000-00-00 00:00:00') {
        return '&mdash;';
    }
    return date($fmt, strtotime($dt));
}

/** Seconds -> m:ss */
function ftime(int $seconds): string
{
    if ($seconds <= 0) {
        return '&mdash;';
    }
    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}

/** URL-friendly slug. */
function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = strtolower(trim($text, '-'));
    $text = preg_replace('~-+~', '-', $text);
    return $text !== '' ? $text : 'item';
}

/** Make a slug unique within a table. */
function unique_slug(string $table, string $slug, ?int $ignoreId = null): string
{
    $base = $slug;
    $i    = 1;
    while (true) {
        $sql    = "SELECT id FROM `$table` WHERE slug = ?" . ($ignoreId ? ' AND id <> ?' : '');
        $params = $ignoreId ? [$slug, $ignoreId] : [$slug];
        if (!fetch_one($sql, $params)) {
            return $slug;
        }
        $slug = $base . '-' . (++$i);
    }
}

// ---------------------------------------------------------------
// Settings
// ---------------------------------------------------------------

/** All settings, cached for the request. Pass true to re-read from the DB. */
function settings_all(bool $reload = false): array
{
    static $cache = null;
    if ($cache === null || $reload) {
        $cache = [];
        foreach (fetch_all('SELECT skey, svalue FROM settings') as $r) {
            $cache[$r['skey']] = (string) $r['svalue'];
        }
    }
    return $cache;
}

/** Get a setting value (cached for the request). */
function setting(string $key, string $default = ''): string
{
    $all = settings_all();
    $v   = $all[$key] ?? '';
    return $v === '' ? $default : $v;
}

/** Save a setting. */
function set_setting(string $key, string $value): void
{
    q('INSERT INTO settings (skey, svalue) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$key, $value]);
    settings_all(true);
}

// ---------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']);
}

// ---------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------

function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function render_flash(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $out = '';
    foreach ($_SESSION['flash'] as $f) {
        $icon = $f['type'] === 'error' ? '&#9888;' : ($f['type'] === 'info' ? '&#8505;' : '&#10003;');
        $out .= '<div class="alert alert-' . e($f['type']) . '"><span>' . $icon . '</span>'
              . e($f['msg']) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

// ---------------------------------------------------------------
// Player auth
// ---------------------------------------------------------------

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/** Current logged-in player row, or null. */
function current_user(): ?array
{
    static $u = null;
    static $loaded = false;
    if ($loaded) {
        return $u;
    }
    $loaded = true;
    if (!is_logged_in()) {
        return null;
    }
    $u = fetch_one('SELECT * FROM users WHERE id = ? AND status = "active"',
                   [(int) $_SESSION['user_id']]);
    if (!$u) {
        unset($_SESSION['user_id']);
    }
    return $u;
}

function require_login(string $back = ''): void
{
    if (!is_logged_in()) {
        flash('Please log in to continue.', 'info');
        $_SESSION['redirect_after_login'] = $back ?: ($_SERVER['REQUEST_URI'] ?? '');
        redirect('login.php');
    }
}

/**
 * The gate in front of everything playable.
 *
 * Arcade games, the quiz, chess and the daily spin all call this, so the
 * rule ("no game without an account") lives in exactly one place: change
 * the body here and every entry point changes with it. It behaves like
 * require_login() but says why, and it remembers the page the player was
 * heading for so login.php can send them straight back to it.
 */
function require_play_login(string $back = ''): void
{
    if (is_logged_in()) {
        // v7: a one-time registration fee, when the admin has switched one
        // on, has to be paid before anything is playable. Same single gate.
        if (function_exists('reg_fee_due') && reg_fee_due(current_user())) {
            $_SESSION['redirect_after_pay'] = $back ?: ($_SERVER['REQUEST_URI'] ?? '');
            redirect('pay.php?for=registration');
        }
        return;
    }
    flash('Please log in to play. Creating an account is free and takes a minute.', 'info');
    $_SESSION['redirect_after_login'] = $back ?: ($_SERVER['REQUEST_URI'] ?? '');
    redirect('login.php');
}

// ---------------------------------------------------------------
// Coins
// ---------------------------------------------------------------

/** Credit / debit coins and write the ledger. Returns the new balance. */
function add_coins(int $userId, int $amount, string $type, string $note = ''): int
{
    q('UPDATE users SET coins = GREATEST(0, coins + ?) WHERE id = ?', [$amount, $userId]);
    $balance = (int) fetch_col('SELECT coins FROM users WHERE id = ?', [$userId], 0);
    insert_row('coin_transactions', [
        'user_id' => $userId,
        'amount'  => $amount,
        'balance' => $balance,
        'type'    => $type,
        'note'    => $note !== '' ? $note : null,
    ]);
    return $balance;
}

/** Add XP and recompute level (250 XP per level). */
function add_xp(int $userId, int $xp): void
{
    q('UPDATE users SET xp = xp + ?, level = GREATEST(1, FLOOR((xp + ?) / 250) + 1) WHERE id = ?',
      [$xp, $xp, $userId]);
}

// ---------------------------------------------------------------
// Misc
// ---------------------------------------------------------------

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Read a trimmed POST field. */
function post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

/** Read a trimmed GET field. */
function get(string $key, string $default = ''): string
{
    return trim((string) ($_GET[$key] ?? $default));
}

/** Is the current request page this file? Used for nav highlighting. */
function nav_active(string $file): string
{
    $cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return $cur === $file ? ' active' : '';
}

/** Avatar URL with an initials fallback. */
function avatar_url(?array $user): string
{
    if (!empty($user['avatar']) && file_exists(UPLOAD_PATH . '/avatars/' . $user['avatar'])) {
        return UPLOAD_URL . '/avatars/' . rawurlencode($user['avatar']);
    }
    $seed = urlencode($user['username'] ?? 'player');
    return 'https://ui-avatars.com/api/?background=1e2630&color=a4d94c&bold=true&name=' . $seed;
}

/**
 * Site logo URL.
 * Admin can upload a replacement (stored in /uploads/brand); otherwise the
 * bundled assets/img/new_logo.jpg is used. Also drives the page background.
 */
function logo_url(): string
{
    $f = setting('site_logo');
    if ($f !== '' && file_exists(UPLOAD_PATH . '/brand/' . $f)) {
        return UPLOAD_URL . '/brand/' . rawurlencode($f);
    }
    return asset('assets/img/new_logo.jpg');
}

/**
 * Square logo, pre-cropped and resized — for favicons, the app icon and any
 * place a wide 2752x1536 JPEG would be wasteful. Falls back to logo_url()
 * if the generated sizes are missing.
 *
 * @param int $size one of 512, 192, 180, 64, 32
 */
function logo_icon(int $size = 192): string
{
    $rel = 'assets/img/icon-' . $size . '.png';
    return is_file(ROOT_PATH . '/' . $rel) ? asset($rel) : logo_url();
}

/** Lighter, web-sized copy of the full wide logo (og:image, watermark). */
function logo_wide(): string
{
    $rel = 'assets/img/logo-wide.jpg';
    return is_file(ROOT_PATH . '/' . $rel) ? asset($rel) : logo_url();
}

/**
 * Does a table exist? Cached per request.
 * Lets the quiz / spin features stay hidden — instead of fatally erroring —
 * on an install where config/upgrade-quiz-spin.sql has not been run yet.
 */
function has_table(string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $found = fetch_one(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
        return $cache[$table] = (bool) $found;
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

// ---------------------------------------------------------------
// Quiz
// ---------------------------------------------------------------

/**
 * Is the quiz contest installed AND switched on?
 * Three things have to agree: the module switch the admin controls, the
 * legacy setting, and the tables actually being there.
 */
function quiz_on(): bool
{
    return module_on('quiz')
        && setting('quiz_enabled', '1') === '1'
        && has_table('quiz_questions');
}

/** Is the daily spin installed AND switched on? */
function spin_on(): bool
{
    return module_on('spin')
        && setting('spin_enabled', '1') === '1'
        && has_table('spin_results');
}

/** The three difficulty levels, with their per-question rules. */
function quiz_levels(): array
{
    return [
        'easy' => [
            'label'   => 'Easy',
            'icon'    => '&#127793;',
            'seconds' => (int) setting('quiz_time_easy', '30'),
            'reward'  => (int) setting('quiz_reward_easy', '10'),
            'xp'      => 5,
            'blurb'   => 'Warm up. Generous clock, forgiving questions.',
        ],
        'mid' => [
            'label'   => 'Mid',
            'icon'    => '&#9889;',
            'seconds' => (int) setting('quiz_time_mid', '20'),
            'reward'  => (int) setting('quiz_reward_mid', '25'),
            'xp'      => 12,
            'blurb'   => 'Tighter clock, sharper questions. The real test.',
        ],
        'hard' => [
            'label'   => 'Hard',
            'icon'    => '&#128293;',
            'seconds' => (int) setting('quiz_time_hard', '15'),
            'reward'  => (int) setting('quiz_reward_hard', '50'),
            'xp'      => 25,
            'blurb'   => 'Fifteen seconds. No second guesses. Top coins.',
        ],
    ];
}

/** Validate a level key, falling back to easy. */
function quiz_level(string $key): array
{
    $all = quiz_levels();
    return $all[$key] ?? $all['easy'];
}

/** How many questions make up one round. */
function quiz_round_size(): int
{
    return max(1, (int) setting('quiz_questions_per_round', '10'));
}

// ---------------------------------------------------------------
// Daily spin
// ---------------------------------------------------------------

/**
 * Prize wheel segments. Weight decides the odds; the sum need not be 100.
 * Stored as JSON in settings so the admin can retune it without code changes.
 */
function spin_segments(): array
{
    $raw = setting('spin_segments');
    $seg = $raw !== '' ? json_decode($raw, true) : null;

    if (!is_array($seg) || !$seg) {
        // wheel colours alternate the logo's green and gold, darkest = commonest
        $seg = [
            ['coins' => 10,   'weight' => 30, 'color' => '#4a7616'],
            ['coins' => 25,   'weight' => 24, 'color' => '#8fce3f'],
            ['coins' => 50,   'weight' => 18, 'color' => '#a97722'],
            ['coins' => 100,  'weight' => 12, 'color' => '#d9a63c'],
            ['coins' => 200,  'weight' => 8,  'color' => '#6aa521'],
            ['coins' => 500,  'weight' => 5,  'color' => '#f0c860'],
            ['coins' => 1000, 'weight' => 2,  'color' => '#b6e857'],
            ['coins' => 5,    'weight' => 35, 'color' => '#2e3239'],
        ];
    }

    // normalise
    $out = [];
    foreach ($seg as $s) {
        $c = max(0, (int) ($s['coins'] ?? 0));
        $w = max(0, (int) ($s['weight'] ?? 0));
        if ($w === 0) {
            continue;
        }
        $out[] = [
            'coins'  => $c,
            'weight' => $w,
            'color'  => preg_match('/^#[0-9a-f]{6}$/i', (string) ($s['color'] ?? ''))
                        ? $s['color'] : '#8fce3f',
        ];
    }
    return $out;
}

/** Pick a winning segment index using the configured weights. */
function spin_pick(array $segments): int
{
    $total = 0;
    foreach ($segments as $s) {
        $total += $s['weight'];
    }
    if ($total <= 0) {
        return 0;
    }
    $roll = random_int(1, $total);
    $acc  = 0;
    foreach ($segments as $i => $s) {
        $acc += $s['weight'];
        if ($roll <= $acc) {
            return $i;
        }
    }
    return count($segments) - 1;
}

/** Has this player already spun today? Returns the row, or null. */
function spin_today(int $userId): ?array
{
    if (!has_table('spin_results')) {
        return null;
    }
    return fetch_one(
        'SELECT * FROM spin_results WHERE user_id = ? AND spin_date = CURDATE()',
        [$userId]
    );
}

/** Seconds until the next local midnight. */
function seconds_to_midnight(): int
{
    return max(1, strtotime('tomorrow midnight') - time());
}

/** Blog cover image URL with a gradient fallback flag. */
function cover_url(?string $file): ?string
{
    if ($file && file_exists(UPLOAD_PATH . '/blog/' . $file)) {
        return UPLOAD_URL . '/blog/' . rawurlencode($file);
    }
    return null;
}

/** Sanitise admin-authored HTML: strip scripts / event handlers. */
function safe_html(string $html): string
{
    $html = preg_replace('#<\s*(script|iframe|object|embed|style)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
    $html = preg_replace('#<\s*/?\s*(script|iframe|object|embed|style)[^>]*>#i', '', $html);
    $html = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', $html);
    $html = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', $html);
    $html = preg_replace('#javascript\s*:#i', '', $html);
    return $html;
}

/** Handle an uploaded image; returns the stored filename or null. */
function save_upload(string $field, string $subdir): ?string
{
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return null;
    }
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'gif' => 'image/gif', 'webp' => 'image/webp'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return null;
    }
    $info = @getimagesize($_FILES[$field]['tmp_name']);
    if ($info === false) {
        return null;
    }
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) {
        return null;
    }
    $dir = UPLOAD_PATH . '/' . $subdir;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $name = bin2hex(random_bytes(8)) . '-' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $name)) {
        return null;
    }
    return $name;
}

/** Delete a stored upload. */
function delete_upload(?string $file, string $subdir): void
{
    if ($file && preg_match('/^[\w.\-]+$/', $file)) {
        @unlink(UPLOAD_PATH . '/' . $subdir . '/' . $file);
    }
}

// ---------------------------------------------------------------
// v4 libraries
// ---------------------------------------------------------------
// Loaded here rather than from config.php so that every entry point —
// pages, admin screens, the API and the game iframes — gets them from
// the one require it already makes.
require_once __DIR__ . '/modules.php';      // hide / live switches
require_once __DIR__ . '/quizlevels.php';   // the level ladder
require_once __DIR__ . '/spinaccess.php';   // who may spin, and how often
require_once __DIR__ . '/marks.php';        // admin score + marks editing

// ---------------------------------------------------------------
// v6 libraries
// ---------------------------------------------------------------
require_once __DIR__ . '/visitors.php';     // page-view + location logging
require_once __DIR__ . '/govtexam.php';     // govt competitive exam module

// ---------------------------------------------------------------
// v6.1 libraries
// ---------------------------------------------------------------
require_once __DIR__ . '/appframe.php';     // signed play tickets for the app

// ---------------------------------------------------------------
// v7 libraries
// ---------------------------------------------------------------
require_once __DIR__ . '/payments.php';     // gateways, plans, subscriptions, reg fee
require_once __DIR__ . '/seo.php';          // SEO manager: meta, schema, sitemap
