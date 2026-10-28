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

/* ---------- which game ---------- */
$slug = get('game');

/* ---------- the app's door ---------- */
// The Android app carries a bearer token, not a cookie, so it cannot walk
// through the session check below. It asks /api/v1/games/ticket for a signed
// ticket first and opens this page with it; a good ticket opens a normal
// session for that WebView, and from here on the frame behaves exactly as
// it does in a browser. See includes/appframe.php for what a ticket is.
if (!is_logged_in()) {
    $ticket = trim((string) ($_GET['t'] ?? ''));
    if ($ticket !== '') {
        $appUser = app_frame_user($ticket, (string) $slug);
        if ($appUser > 0) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $appUser;
            $_SESSION['via']     = 'app';
        }
    }
}

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

/* ---------- the game row ---------- */
$game = fetch_one('SELECT * FROM games WHERE slug = ? AND is_active = 1', [$slug]);
if (!$game) {
    http_response_code(404);
    exit('That game does not exist.');
}

/* ---------- v7: registration fee / paid plan ---------- */
// The frame is the real door to the game files, so the paywall is checked
// here too — a bookmarked frame URL or an old app build cannot skip it.
$access = game_access(current_user(), (string) $game['slug']);
if (!$access['ok']) {
    http_response_code(402);
    header('Content-Type: text/html; charset=utf-8');
    $payUrl = $access['reason'] === 'registration'
        ? url('pay.php?for=registration')
        : url('pay.php?for=game&item=' . urlencode($game['slug']));
    if (($_SESSION['via'] ?? '') === 'app' && is_logged_in()) {
        $payUrl = app_pay_url((int) $_SESSION['user_id'],
                              $access['reason'] === 'registration' ? 'registration' : 'game',
                              $access['reason'] === 'registration' ? '' : (string) $game['slug']);
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex"><title>Unlock ' . e($game['title']) . '</title><style>'
       . 'html,body{height:100%;margin:0}body{display:grid;place-items:center;'
       . 'background:#0b0c0e;color:#eaeee8;font:400 15px/1.55 system-ui,-apple-system,'
       . 'Segoe UI,Roboto,sans-serif;text-align:center;padding:24px}'
       . 'h1{font-size:1.15rem;margin:14px 0 6px}p{margin:0 0 18px;color:#a2ab99;max-width:420px}'
       . 'a{display:inline-block;padding:11px 22px;border-radius:100px;font-weight:800;'
       . 'text-decoration:none;background:linear-gradient(135deg,#b6e857,#5c9418);color:#0b0c0e}</style></head><body>'
       . '<div><div style="font-size:2.4rem">&#128274;</div>'
       . '<h1>' . e($game['title']) . ' is locked</h1><p>' . e($access['message']) . '</p>'
       . ($access['reason'] !== 'unavailable'
           ? '<a href="' . e($payUrl) . '" target="_top">Unlock now</a>' : '')
       . '</div></body></html>';
    exit;
}

/* ---------- find its index.html, without leaving the games folder ---------- */
// The path column is admin-editable, so it is treated as untrusted: realpath()
// resolves any "../" in it and the prefix test throws out anything that lands
// outside /games/.
//
// Three spellings are tried, in order, because the same row that works on a
// Windows XAMPP can miss on the live host:
//
//   1. the path column exactly as it is stored          games/sudoku
//   2. games/<slug>, in case the column was never filled in
//   3. the same name matched without case, because Linux cares about
//      "Sudoku" vs "sudoku" and Windows does not — a folder uploaded
//      with a capital letter is the usual reason a game that plays
//      locally reports itself missing on the server
$root = realpath(ROOT_PATH . '/games');

$candidates = [];
$stored = trim(str_replace('\\', '/', (string) $game['path']), '/');
if ($stored !== '') {
    $candidates[] = $stored;
}
$candidates[] = 'games/' . $slug;

// case-insensitive match against what is actually on disk
if ($root !== false) {
    $want = strtolower(basename($stored !== '' ? $stored : $slug));
    foreach ((array) @scandir($root) as $entry) {
        if ($entry !== '.' && $entry !== '..' && strtolower($entry) === $want) {
            $candidates[] = 'games/' . $entry;
        }
    }
}

$rel = $target = null;
foreach ($candidates as $c) {
    $try = realpath(ROOT_PATH . '/' . $c . '/index.html');
    if ($try !== false && $root !== false
        && strncmp($try, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0
        && is_file($try)) {
        $rel    = $c;
        $target = $try;
        break;
    }
}

if ($target === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    // Whoever is signed into the admin panel gets to see which spellings
    // were tried; a player just gets the sentence.
    if (!empty($_SESSION['admin_id'])) {
        exit("The files for this game are missing.\n\nLooked for index.html in:\n  - "
           . implode("\n  - ", array_unique($candidates))
           . "\n\nunder: " . ROOT_PATH . "\ngames/ contains: "
           . implode(', ', array_diff((array) @scandir((string) $root), ['.', '..'])));
    }
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
