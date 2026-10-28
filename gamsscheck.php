<?php
/**
 * ===================================================================
 * GAMSS CHECK — "index.php keno 500 dicche" ta dhorar jonno
 * ===================================================================
 * Site-er root-e upload korun ar khulun:
 *
 *      https://asviora.in/gamsscheck.php?key=gamss-2026
 *
 * Ki kore: site-er boot ta step by step kore, ar jekhane fatal error
 * hoy sekhanei theme giye **asol error message + file + line number**
 * dekhiye dey. Live-e error hidden thakleo ei page ta dekhabe.
 *
 * Ekta page-o test kora jay:
 *      gamsscheck.php?key=gamss-2026&page=index.php
 *
 * PHP 7.0+ e chole. Kaj sesh hole delete kore deben.
 * ===================================================================
 */

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

$MIGRATION_KEY = 'gamss-2026';
$ROOT = __DIR__;

// Session ta ekhane-i shuru kore ni, kono HTML chapar age. Na hole config.php
// jokhon session_start() korte jabe tokhon "headers already sent" warning-e
// puro page ta bhore jabe ar asol error ta khuje pawa jabe na.
$__sessDir = $ROOT . '/storage/sessions';
if (is_dir($__sessDir) && is_writable($__sessDir)) {
    @session_save_path($__sessDir);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$GLOBALS['gamss_step'] = 'shuru';
function gstep($s)
{
    $GLOBALS['gamss_step'] = $s;
}

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        echo '<div class="card err"><h2 style="color:#ff9b97">ASOL ERROR TA EI &mdash; ekhane atkeche</h2>'
           . '<p class="note">Step: <code>' . htmlspecialchars($GLOBALS['gamss_step']) . '</code></p>'
           . '<pre>' . htmlspecialchars($e['message']) . "\n\n"
           . 'File : ' . htmlspecialchars($e['file']) . "\n"
           . 'Line : ' . (int) $e['line'] . '</pre>'
           . '<p style="margin-bottom:0">Ei box-er screenshot ta pathiye din.</p></div>'
           . '</div></body></html>';
    } else {
        echo '</div></body></html>';
    }
});

$keyOk = isset($_GET['key']) && hash_equals($MIGRATION_KEY, (string) $_GET['key']);
function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }

?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Gamss check</title>
<style>
 body{margin:0;padding:30px 18px;background:#0b0f1a;color:#e9eefc;font:15px/1.6 system-ui,sans-serif}
 .wrap{max-width:940px;margin:0 auto}
 h1{font-size:1.4rem;margin:0 0 18px}
 h2{font-size:1rem;margin:0 0 12px;color:#b6e857}
 .card{background:#121729;border:1px solid #232c48;border-radius:14px;padding:16px 18px;margin-bottom:16px}
 .card.err{border-color:#7a2b2b;background:#1d0f11}
 .card.ok{border-color:#3f6b1c}
 pre{white-space:pre-wrap;background:#0e1424;padding:12px;border-radius:10px;font:13px/1.6 monospace;
     overflow:auto;margin:8px 0 0}
 table{width:100%;border-collapse:collapse;font-size:.9rem}
 td,th{text-align:left;padding:6px 8px;border-bottom:1px solid #1e2740;vertical-align:top}
 tr:last-child td{border-bottom:0}
 .tag{display:inline-block;padding:2px 9px;border-radius:99px;font-size:.75rem;font-weight:700}
 .g{background:rgba(143,206,63,.16);color:#b6e857}
 .r{background:rgba(217,83,79,.18);color:#ff9b97}
 .note{color:#93a0b8;font-size:.9rem}
 code{background:#0e1424;padding:2px 6px;border-radius:6px;font-size:.85em}
</style></head><body><div class="wrap">
<h1>Gamss check &mdash; boot diagnosis</h1>
<?php

if (!$keyOk) {
    echo '<div class="card"><h2>Key lagbe</h2><p><code>gamsscheck.php?key=' . h($MIGRATION_KEY)
       . '</code></p></div></div></body></html>';
    exit;
}

/* ---------------- 1. Server ---------------- */
$php80 = version_compare(PHP_VERSION, '8.0.0', '>=');
echo '<div class="card ' . ($php80 ? 'ok' : 'err') . '"><h2>Server</h2><table>';
$rows = array(
    'PHP version'        => PHP_VERSION . ($php80 ? '  (thik ache)' : '  — ei site-e PHP 8.0+ lagbe!'),
    'pdo_mysql'          => extension_loaded('pdo_mysql') ? 'ache' : 'NEI',
    'ZipArchive'         => class_exists('ZipArchive') ? 'ache' : 'NEI',
    'SimpleXML'          => function_exists('simplexml_load_string') ? 'ache' : 'NEI',
    'mbstring'           => function_exists('mb_substr') ? 'ache' : 'NEI',
    'session save path'  => (string) ini_get('session.save_path'),
    'Folder'             => $ROOT,
);
foreach ($rows as $k => $v) {
    echo '<tr><td class="note" style="width:200px">' . h($k) . '</td><td><code>' . h($v) . '</code></td></tr>';
}
echo '</table>';
if (!$php80) {
    echo '<p style="color:#ffcf6b;margin-bottom:0"><b>Eitai somossa hote pare.</b> '
       . 'hPanel &rarr; PHP Configuration &rarr; <b>8.1</b> ba <b>8.2</b> kore nin.</p>';
}
echo '</div>';

/* ---------------- 2. File gulo ---------------- */
$need = array(
    'config/config.php', 'includes/db.php', 'includes/functions.php',
    'includes/quiz.php', 'includes/chess.php', 'includes/sheet.php',
    'includes/arena.php', 'includes/migrate.php', 'includes/header.php',
    'includes/footer.php', 'index.php',
);
echo '<div class="card"><h2>File gulo</h2><table>'
   . '<tr><th>File</th><th style="width:90px">Ache</th><th style="width:90px">Size</th>'
   . '<th style="width:110px">Suru thik?</th></tr>';
$badStart = array();
foreach ($need as $f) {
    $p = $ROOT . '/' . $f;
    $ok = is_file($p);
    $size = $ok ? filesize($p) : 0;
    $head = $ok ? substr((string) file_get_contents($p, false, null, 0, 20), 0, 20) : '';
    $startOk = $ok ? (strpos($head, '<?php') === 0) : false;
    if ($ok && !$startOk) {
        $badStart[] = $f;
    }
    echo '<tr><td><code>' . h($f) . '</code></td>'
       . '<td><span class="tag ' . ($ok ? 'g' : 'r') . '">' . ($ok ? 'ACHE' : 'NEI') . '</span></td>'
       . '<td class="note">' . number_format($size) . '</td>'
       . '<td><span class="tag ' . ($startOk ? 'g' : 'r') . '">'
       . ($startOk ? 'thik' : 'GONDOGOL') . '</span></td></tr>';
}
echo '</table>';
if ($badStart) {
    echo '<p style="color:#ff9b97;margin-bottom:0">Ei file gulo <code>&lt;?php</code> diye suru '
       . 'hocche na &mdash; upload-er somoy nosto hoyeche (FTP ASCII mode ba half upload). '
       . 'Abar upload korun (Binary mode).</p>';
}
echo '</div>';

/* ---------------- 3. Boot ---------------- */
echo '<div class="card"><h2>Boot test</h2>';
echo '<p class="note">Ekhon <code>config/config.php</code> load kora hocche. Jodi ekhanei fatal hoy, '
   . 'niche lal box-e asol error ta dekhabe.</p>';
flush();

gstep('config/config.php load hocche');
if (!is_file($ROOT . '/config/config.php')) {
    echo '<p style="color:#ff9b97">config/config.php nei!</p></div></div></body></html>';
    exit;
}

define('SKIP_MIGRATIONS', true);
require_once $ROOT . '/config/config.php';
gstep('config.php load holo');

echo '<p><span class="tag g">config.php load holo</span></p>';

/* function gulo ache kina */
$fns = array(
    'db', 'q', 'fetch_one', 'fetch_all', 'insert_row', 'update_row',
    'e', 'url', 'setting', 'set_setting', 'has_table', 'csrf_token',
    'quiz_on', 'spin_on', 'quiz_levels', 'quiz_round_size',
    'quiz_chain', 'quiz_level_mode', 'quiz_per_level', 'quiz_deal',
    'quiz_save_level_result', 'spin_allowed',
    'chess_on', 'chess_ai_on', 'chess_pvp_on', 'chess_parse', 'chess_moves',
    'arena_items', 'arena_card',
);
$missing = array();
foreach ($fns as $fn) {
    if (!function_exists($fn)) {
        $missing[] = $fn;
    }
}
echo '<table><tr><th>Function check</th><th style="width:120px"></th></tr>';
echo '<tr><td class="note">' . count($fns) . ' ta function dekha holo</td><td><span class="tag '
   . ($missing ? 'r' : 'g') . '">' . ($missing ? count($missing) . ' ta NEI' : 'sob ache') . '</span></td></tr>';
if ($missing) {
    echo '<tr><td colspan="2"><code>' . h(implode(', ', $missing)) . '</code></td></tr>';
}
echo '</table>';

/* database */
gstep('database connect hocche');
try {
    $ver = fetch_col('SELECT VERSION()', array(), '?');
    $sv  = setting('schema_version', '?');
    $qn  = has_table('quiz_questions')
         ? (int) fetch_col('SELECT COUNT(*) FROM quiz_questions', array(), 0) : 0;
    echo '<p><span class="tag g">DB ok</span> &nbsp;MySQL <code>' . h($ver)
       . '</code> &nbsp; schema_version <code>' . h($sv) . '</code> &nbsp; questions <code>'
       . number_format($qn) . '</code></p>';
} catch (Throwable $t) {
    echo '<p><span class="tag r">DB error</span> <code>' . h($t->getMessage()) . '</code></p>';
}

/* helper gulo asolei chole kina */
gstep('helper function gulo chalano hocche');
$probe = array(
    'quiz_on()'        => 'quiz_on',
    'spin_on()'        => 'spin_on',
    'chess_on()'       => 'chess_on',
    'quiz_level_mode()'=> 'quiz_level_mode',
);
echo '<table><tr><th>Helper</th><th style="width:160px">Result</th></tr>';
foreach ($probe as $label => $fn) {
    if (!function_exists($fn)) {
        echo '<tr><td><code>' . h($label) . '</code></td><td><span class="tag r">function nei</span></td></tr>';
        continue;
    }
    try {
        $v = call_user_func($fn);
        echo '<tr><td><code>' . h($label) . '</code></td><td><span class="tag g">'
           . ($v ? 'true' : 'false') . '</span></td></tr>';
    } catch (Throwable $t) {
        echo '<tr><td><code>' . h($label) . '</code></td><td><span class="tag r">'
           . h($t->getMessage()) . '</span></td></tr>';
    }
}
echo '</table>';

gstep('arena_items() chalano hocche');
try {
    $items = arena_items();
    echo '<p><span class="tag g">arena_items ok</span> &mdash; ' . count($items) . ' ta item</p>';
} catch (Throwable $t) {
    echo '<p><span class="tag r">arena_items error</span> <code>' . h($t->getMessage())
       . '</code> <span class="note">' . h($t->getFile()) . ':' . (int) $t->getLine() . '</span></p>';
}

echo '</div>';

/* ---------------- 4. Ekta page render kore dekha ---------------- */
$page = isset($_GET['page']) ? basename((string) $_GET['page']) : '';
if ($page !== '' && preg_match('/^[a-z0-9\-]+\.php$/i', $page) && is_file($ROOT . '/' . $page)) {
    echo '<div class="card"><h2>Page test: ' . h($page) . '</h2>';
    gstep($page . ' render hocche');
    ob_start();
    try {
        include $ROOT . '/' . $page;
        $out = ob_get_clean();
        echo '<p><span class="tag g">' . h($page) . ' cholche</span> &mdash; '
           . number_format(strlen($out)) . ' bytes output</p>';
    } catch (Throwable $t) {
        ob_end_clean();
        echo '<p><span class="tag r">Error</span></p><pre>' . h($t->getMessage()) . "\n\n"
           . h($t->getFile()) . ':' . (int) $t->getLine() . "\n\n" . h($t->getTraceAsString()) . '</pre>';
    }
    echo '</div>';
} else {
    echo '<div class="card"><h2>Page test</h2><p>Ekta page chaliye dekhte URL-e jog korun: '
       . '<code>&amp;page=index.php</code></p></div>';
}

/* ---------------- 5. Error log ---------------- */
echo '<div class="card"><h2>Error log (sesh 25 line)</h2>';
$logs = array($ROOT . '/error_log', $ROOT . '/php_errorlog', dirname($ROOT) . '/error_log',
              (string) ini_get('error_log'));
$shown = false;
foreach ($logs as $lg) {
    if ($lg !== '' && is_file($lg) && is_readable($lg)) {
        $lines = @file($lg);
        if ($lines) {
            $tail = array_slice($lines, -25);
            echo '<p class="note"><code>' . h($lg) . '</code></p><pre>' . h(implode('', $tail)) . '</pre>';
            $shown = true;
            break;
        }
    }
}
if (!$shown) {
    echo '<p class="note">Kono error log file pawa gelo na. hPanel &rarr; <b>Error log</b> theke dekhun.</p>';
}
echo '</div>';

echo '<div class="card"><h2>Sesh</h2><p style="margin-bottom:0">Kaj sesh hole <code>gamsscheck.php</code> '
   . 'ar <code>gamssmigration.php</code> &mdash; dutoi delete kore deben.</p></div>';
