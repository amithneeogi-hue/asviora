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

/** Get a setting value (cached for the request). */
function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (fetch_all('SELECT skey, svalue FROM settings') as $r) {
            $cache[$r['skey']] = (string) $r['svalue'];
        }
    }
    $v = $cache[$key] ?? '';
    return $v === '' ? $default : $v;
}

/** Save a setting. */
function set_setting(string $key, string $value): void
{
    q('INSERT INTO settings (skey, svalue) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$key, $value]);
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
