<?php
/**
 * ASVIORA GAMES - Core configuration
 * -----------------------------------------------------------
 * Edit the database credentials below to match your server.
 * For local XAMPP the defaults usually work as-is.
 */

// ---------- Database ----------
define('DB_HOST', 'localhost');
define('DB_NAME', 'games');
define('DB_USER', 'root');
define('DB_PASS', '');
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

// Set to false on a live server
define('DEBUG_MODE', true);

// ---------- Bootstrap ----------
date_default_timezone_set(TIMEZONE);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

require_once ROOT_PATH . '/includes/db.php';
require_once ROOT_PATH . '/includes/functions.php';
