<?php
/**
 * GAME FRAME — the only door to the static game files.
 *
 * The games in /games/<slug>/ are plain HTML, so before this file existed a
 * guessed URL (/games/sudoku/index.html) was a way around the login: the
 * page opened, the game ran, and only the score never saved. Now Apache
 * refuses those .html files (see games/.htaccess) and play.php points its
 * iframe here instead. This script checks the session first and only then
 * reads the file off disk and prints it.
 *
 * The game markup links to its assets relatively ("../../assets/css/game.css",
 * "engine.js"), and this file answers from the site root, so a <base> tag is
 * injected into <head> to keep every one of those links resolving to the
 * folder the game actually lives in.
 */
require_once __DIR__ . '/config/config.php';

/* ---------- the gate ---------- */
// Inside an iframe a redirect to login.php would draw the login form in the
// little game box, which looks broken. A short notice with a _top link is
// clearer. This only shows if a session expires mid-page — play.php already
// turns guests away before they get this far.
if (!is_logged_in()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Login required</title><style>'
       . 'html,body{height:100%;margin:0}body{display:grid;place-items:center;'
       . 'background:#0e1116;color:#e7ecf3;font:400 15px/1.55 system-ui,-apple-system,'
       . 'Segoe UI,Roboto,sans-serif;text-align:center;padding:24px}'
       . 'h1{font-size:1.15rem;margin:14px 0 6px}p{margin:0 0 18px;color:#94a3b8}'
       . 'a{display:inline-block;padding:11px 22px;border-radius:100px;font-weight:700;'
       . 'text-decoration:none;background:#a4d94c;color:#0e1116}</style></head><body>'
       . '<div><div style="font-size:2.4rem">&#128274;</div>'
       . '<h1>Login required</h1><p>You need an account to play this game.</p>'
       . '<a href="' . e(url('login.php')) . '" target="_top">Log in to play</a>'
       . '</div></body></html>';
    exit;
}

/* ---------- which game ---------- */
$slug = get('game');
$game = fetch_one('SELECT * FROM games WHERE slug = ? AND is_active = 1', [$slug]);
if (!$game) {
    http_response_code(404);
    exit('That game does not exist.');
}

/* ---------- find its index.html, without leaving the games folder ---------- */
// The path column is admin-editable, so it is treated as untrusted: realpath()
// resolves any "../" in it and the prefix test throws out anything that lands
// outside /games/.
$rel    = trim(str_replace('\\', '/', (string) $game['path']), '/');
$target = realpath(ROOT_PATH . '/' . $rel . '/index.html');
$root   = realpath(ROOT_PATH . '/games');

if ($target === false || $root === false
    || strncmp($target, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0
    || !is_file($target)) {
    http_response_code(404);
    exit('The files for this game are missing.');
}

$html = (string) file_get_contents($target);

/* ---------- keep the game's relative links working ---------- */
$base = '<base href="' . e(url($rel) . '/') . '">';
$out  = preg_replace('/<head\b[^>]*>/i', '$0' . "\n" . $base, $html, 1);
if ($out === null || $out === $html) {
    // No <head> to hook into (or preg failed) — prepend and let the browser
    // sort it out rather than serving a page with broken asset links.
    $out = $base . "\n" . $html;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo $out;
