<?php
/**
 * ASVIORA GAMES - Core configuration
 * -----------------------------------------------------------
 * The same file works on XAMPP and on the live server: it detects
 * which one it is running on from the hostname.
 *
 * LOCAL  -> XAMPP defaults (root / no password), errors visible.
 * LIVE   -> the credentials in the $live block below, errors hidden.
 *
 * The live values come from your host's MySQL Databases screen
 * (Hostinger hPanel / cPanel). The host prefixes both the database and
 * the user with your account number, e.g. u188331983_gamef.
 *
 * Which branch runs is decided by the hostname, not by which machine
 * the file is sitting on — so the same file can be uploaded to both.
 */

// ---------- Environment ----------
$__host = strtolower($_SERVER['HTTP_HOST'] ?? 'localhost');
$__isLocal = (
    $__host === 'localhost'
    || str_starts_with($__host, 'localhost:')
    || str_starts_with($__host, '127.0.0.1')
    || str_starts_with($__host, '192.168.')
    || str_ends_with($__host, '.local')
    || str_ends_with($__host, '.test')
);
define('IS_LOCAL', $__isLocal);

// ---------- Database ----------
if (IS_LOCAL) {
    // XAMPP on your own machine
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'asviora_games');
    define('DB_USER', 'root');
    define('DB_PASS', '');
} else {
    // asviora.in — live hosting
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'u188331983_gamef');
    define('DB_USER', 'u188331983_gamef');
    define('DB_PASS', 'Techsujit21@');
}
define('DB_CHARSET', 'utf8mb4');

// ---------- Site ----------
// Base URL of the site. Leave as auto-detect, or hardcode e.g. 'http://localhost/games'
define('SITE_URL', (function () {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir    = str_replace('\\', '/', dirname($script));
    // strip /admin or /api from the end
    $dir    = preg_replace('#/(admin|api)$#', '', $dir);
    // strip /games/<slug> if inside games folder
    $dir    = preg_replace('#/games/[^/]+$#', '', $dir);
    $dir    = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
    return $scheme . '://' . $host . $dir;
})());

define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('UPLOAD_URL',  SITE_URL . '/uploads');

// ---------- Behaviour ----------
define('TIMEZONE', 'Asia/Kolkata');
define('POSTS_PER_PAGE', 6);
define('LEADERBOARD_LIMIT', 25);

// Errors on screen locally, silent on the live site.
define('DEBUG_MODE', IS_LOCAL);

// ---------- Bootstrap ----------
date_default_timezone_set(TIMEZONE);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

/**
 * ---------- Long-lived login sessions ----------
 * The default PHP session dies after ~24 minutes of inactivity and its cookie
 * is dropped the moment the browser closes, which is why a login never seemed
 * to "stick". Three things fix that:
 *
 *   1. a real cookie lifetime (30 days) instead of a browser-session cookie;
 *   2. a matching gc_maxlifetime so the server keeps the session file that long;
 *   3. a private session directory, so a shared host's aggressive cleanup of
 *      /tmp cannot delete our sessions early.
 *
 * The cookie is also re-issued on every request, so 30 days is counted from the
 * last visit rather than from the first login — an active player stays signed in
 * indefinitely.
 */
define('SESSION_LIFETIME', 60 * 60 * 24 * 30); // 30 days

if (session_status() === PHP_SESSION_NONE) {
    // cookie scoped to this install, so two projects on the same localhost
    // cannot overwrite each other's session cookie
    $__path = parse_url(SITE_URL, PHP_URL_PATH);
    $__path = ($__path === null || $__path === '') ? '/' : rtrim($__path, '/') . '/';

    $__secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    ini_set('session.cookie_lifetime', (string) SESSION_LIFETIME);
    ini_set('session.use_strict_mode', '1');
    // some distros disable probabilistic GC and rely on a cron job that only
    // knows about the default directory — re-enable it for our own directory
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '1000');

    $__sessDir = ROOT_PATH . '/storage/sessions';
    if (!is_dir($__sessDir)) {
        @mkdir($__sessDir, 0700, true);
    }
    if (is_dir($__sessDir) && is_writable($__sessDir)) {
        session_save_path($__sessDir);
    }

    $__cookie = [
        'lifetime' => SESSION_LIFETIME,
        'path'     => $__path,
        'domain'   => '',
        'secure'   => $__secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($__cookie);
    session_start();

    // slide the expiry forward on every request (rolling session)
    if (isset($_COOKIE[session_name()]) && !headers_sent()) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + SESSION_LIFETIME,
            'path'     => $__path,
            'domain'   => '',
            'secure'   => $__secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

require_once ROOT_PATH . '/includes/db.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/arena.php';

// Apply any database migration the site has not seen yet. This is a no-op on
// every request except the first one after an update — see includes/migrate.php.
// install.php runs before the database exists, so it opts out.
if (!defined('SKIP_MIGRATIONS')) {
    require_once ROOT_PATH . '/includes/migrate.php';
    run_pending_migrations();
}
