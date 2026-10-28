<?php
/**
 * ===================================================================
 * GAMSS MIGRATION  —  Asviora Games schema v4   (standalone, v2)
 * ===================================================================
 * Ekdom eka cholte pare. Site-er onno kono file lage na — config.php
 * include-o kore na, sudhu porey DB credentials ta bar kore ney.
 *
 *      https://asviora.in/gamssmigration.php?key=gamss-2026
 *
 * Keno eibhabe: site-er kono file jodi missing ba purono hoy, tahole
 * config.php include korte gele ei page-tao 500 hoye jeto — ar tokhon
 * somossa ta ki setai dekha jeto na. Ekhon ei page ta nijei bole dey
 * kothay ki attke ache.
 *
 * PHP 7.0+ e chole (purono host-eo), tai version kom hole-o error na
 * diye version ta dekhiye debe.
 *
 * KAJ SESH HOLE EI FILE TA SERVER THEKE DELETE KORE DEBEN.
 * ===================================================================
 */

/* ---------- error gulo dekhabo, karon eta diagnostic page ---------- */
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        echo '<pre style="white-space:pre-wrap;background:#2a0f14;color:#ff9b97;border:1px solid #7a2b2b;'
           . 'padding:16px;border-radius:12px;font:13px/1.6 monospace;margin:18px">'
           . 'FATAL: ' . htmlspecialchars($e['message']) . "\n"
           . 'File : ' . htmlspecialchars($e['file']) . ':' . (int) $e['line']
           . '</pre>';
    }
});

/* ---------- key ---------- */
$MIGRATION_KEY = 'gamss-2026';

$ROOT = __DIR__;
$keyOk = isset($_GET['key']) && hash_equals($MIGRATION_KEY, (string) $_GET['key']);

/* ===================================================================
 * Server diagnostics
 * =================================================================== */
$diag = array(
    'PHP version'          => PHP_VERSION,
    'PDO MySQL'            => extension_loaded('pdo_mysql') ? 'ache' : 'NEI — host support lagbe',
    'ZipArchive (.xlsx)'   => class_exists('ZipArchive') ? 'ache' : 'NEI — xlsx import cholbe na, csv cholbe',
    'SimpleXML (.xlsx)'    => function_exists('simplexml_load_string') ? 'ache' : 'NEI — xlsx import cholbe na',
    'mbstring'             => function_exists('mb_substr') ? 'ache' : 'NEI — lagbe',
    'upload_max_filesize'  => ini_get('upload_max_filesize'),
    'post_max_size'        => ini_get('post_max_size'),
    'Site folder'          => $ROOT,
);
$phpOldForSite = version_compare(PHP_VERSION, '8.0.0', '<');

/* ===================================================================
 * config/config.php theke DB credentials ber kora
 * =================================================================== */
function gamss_creds($file)
{
    if (!is_file($file)) {
        return array();
    }
    $src = file_get_contents($file);
    if ($src === false) {
        return array();
    }

    // define('DB_HOST','...') gulo je order-e ache sei order-e tule ani.
    // config.php te dui set thake: local (XAMPP) ar live.
    preg_match_all(
        "/define\\(\\s*'(DB_HOST|DB_NAME|DB_USER|DB_PASS)'\\s*,\\s*'([^']*)'\\s*\\)/",
        $src, $m, PREG_SET_ORDER);

    $sets = array();
    $cur  = array();
    foreach ($m as $one) {
        $k = $one[1];
        $v = $one[2];
        if ($k === 'DB_HOST' && $cur) {
            $sets[] = $cur;
            $cur = array();
        }
        $cur[$k] = $v;
    }
    if ($cur) {
        $sets[] = $cur;
    }

    // live er set ta age try korbo (host localhost na hole seta age)
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
    $isLocal = ($host === 'localhost' || strpos($host, 'localhost:') === 0
             || strpos($host, '127.0.0.1') === 0 || strpos($host, '192.168.') === 0);
    if (!$isLocal) {
        $sets = array_reverse($sets);
    }
    return $sets;
}

/** Prothom je set diye connect hoy, seta niye ni. */
function gamss_connect($sets, &$why)
{
    $why = array();
    foreach ($sets as $s) {
        if (!isset($s['DB_NAME'])) {
            continue;
        }
        $host = isset($s['DB_HOST']) ? $s['DB_HOST'] : 'localhost';
        $user = isset($s['DB_USER']) ? $s['DB_USER'] : 'root';
        $pass = isset($s['DB_PASS']) ? $s['DB_PASS'] : '';
        try {
            $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $s['DB_NAME'] . ';charset=utf8mb4',
                           $user, $pass,
                           array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
            $why[] = $s['DB_NAME'] . ' @ ' . $host . ' — connected';
            return array($pdo, $s['DB_NAME']);
        } catch (PDOException $e) {
            $why[] = $s['DB_NAME'] . ' @ ' . $host . ' — ' . $e->getMessage();
        }
    }
    return array(null, '');
}

/* ===================================================================
 * Migration steps
 * =================================================================== */
function gamss_steps()
{
    return array(

array('quiz_questions.qcode column (bulk sheet-er Question ID)',
"ALTER TABLE `quiz_questions`
   ADD COLUMN `qcode` VARCHAR(40) DEFAULT NULL
   COMMENT 'Question ID from the bulk sheet' AFTER `id`"),

array('quiz_questions.marks column',
"ALTER TABLE `quiz_questions`
   ADD COLUMN `marks` SMALLINT UNSIGNED NOT NULL DEFAULT 10 AFTER `correct`"),

array('quiz_questions.time_limit column (per-question ghori)',
"ALTER TABLE `quiz_questions`
   ADD COLUMN `time_limit` SMALLINT UNSIGNED DEFAULT NULL
   COMMENT 'seconds; NULL = use the level default' AFTER `marks`"),

array('quiz_questions unique key uq_qq_code',
"ALTER TABLE `quiz_questions` ADD UNIQUE KEY `uq_qq_code` (`qcode`)"),

array('quiz_sessions.level_no column',
"ALTER TABLE `quiz_sessions`
   ADD COLUMN `level_no` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `difficulty`"),

array('quiz_sessions.marks_total column',
"ALTER TABLE `quiz_sessions`
   ADD COLUMN `marks_total` INT NOT NULL DEFAULT 0 AFTER `correct`"),

array('quiz_sessions.marks_scored column',
"ALTER TABLE `quiz_sessions`
   ADD COLUMN `marks_scored` INT NOT NULL DEFAULT 0 AFTER `marks_total`"),

array('quiz_sessions.passed column',
"ALTER TABLE `quiz_sessions`
   ADD COLUMN `passed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `marks_scored`"),

array('quiz_sessions index idx_qs_level',
"ALTER TABLE `quiz_sessions` ADD KEY `idx_qs_level` (`user_id`,`difficulty`,`level_no`)"),

array('quiz_level_progress table (kon player kon level clear korlo)',
"CREATE TABLE IF NOT EXISTS `quiz_level_progress` (
   `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
   `user_id`      INT UNSIGNED NOT NULL,
   `difficulty`   ENUM('easy','mid','hard') NOT NULL DEFAULT 'easy',
   `level_no`     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
   `attempts`     INT UNSIGNED NOT NULL DEFAULT 0,
   `best_correct` INT UNSIGNED NOT NULL DEFAULT 0,
   `best_marks`   INT UNSIGNED NOT NULL DEFAULT 0,
   `best_percent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
   `passed`       TINYINT(1) NOT NULL DEFAULT 0,
   `passed_at`    DATETIME DEFAULT NULL,
   `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
   PRIMARY KEY (`id`),
   UNIQUE KEY `uq_qlp` (`user_id`,`difficulty`,`level_no`),
   KEY `idx_qlp_pass` (`user_id`,`difficulty`,`passed`),
   CONSTRAINT `fk_qlp_user` FOREIGN KEY (`user_id`)
     REFERENCES `users` (`id`) ON DELETE CASCADE
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),

array('quiz_seen table (6 mash repeat block)',
"CREATE TABLE IF NOT EXISTS `quiz_seen` (
   `user_id`     INT UNSIGNED NOT NULL,
   `question_id` INT UNSIGNED NOT NULL,
   `session_id`  BIGINT UNSIGNED DEFAULT NULL,
   `seen_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
   PRIMARY KEY (`user_id`,`question_id`),
   KEY `idx_seen_when` (`user_id`,`seen_at`),
   CONSTRAINT `fk_seen_user` FOREIGN KEY (`user_id`)
     REFERENCES `users` (`id`) ON DELETE CASCADE,
   CONSTRAINT `fk_seen_q` FOREIGN KEY (`question_id`)
     REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),

array('users.spin_access column (admin ke spin assign korbe)',
"ALTER TABLE `users`
   ADD COLUMN `spin_access` ENUM('default','allowed','blocked')
   NOT NULL DEFAULT 'default' AFTER `status`"),

array('chess_matches table (AI + staked match)',
"CREATE TABLE IF NOT EXISTS `chess_matches` (
   `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
   `code`         VARCHAR(12) NOT NULL,
   `mode`         ENUM('ai','pvp') NOT NULL DEFAULT 'pvp',
   `host_id`      INT UNSIGNED NOT NULL,
   `guest_id`     INT UNSIGNED DEFAULT NULL,
   `white_id`     INT UNSIGNED DEFAULT NULL,
   `black_id`     INT UNSIGNED DEFAULT NULL,
   `stake`        INT UNSIGNED NOT NULL DEFAULT 0,
   `pot`          INT UNSIGNED NOT NULL DEFAULT 0,
   `minutes`      SMALLINT UNSIGNED NOT NULL DEFAULT 10,
   `increment`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
   `ai_level`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
   `fen`          VARCHAR(120) NOT NULL
                  DEFAULT 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
   `moves`        MEDIUMTEXT,
   `turn`         ENUM('w','b') NOT NULL DEFAULT 'w',
   `white_ms`     INT NOT NULL DEFAULT 600000,
   `black_ms`     INT NOT NULL DEFAULT 600000,
   `draw_offer`   INT UNSIGNED DEFAULT NULL,
   `status`       ENUM('open','active','finished','cancelled') NOT NULL DEFAULT 'open',
   `result`       ENUM('white','black','draw','abort') DEFAULT NULL,
   `reason`       VARCHAR(40) DEFAULT NULL,
   `winner_id`    INT UNSIGNED DEFAULT NULL,
   `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
   `started_at`   DATETIME DEFAULT NULL,
   `last_move_at` DATETIME DEFAULT NULL,
   `ended_at`     DATETIME DEFAULT NULL,
   PRIMARY KEY (`id`),
   UNIQUE KEY `uq_chess_code` (`code`),
   KEY `idx_chess_status` (`status`,`created_at`),
   KEY `idx_chess_host` (`host_id`,`status`),
   KEY `idx_chess_guest` (`guest_id`,`status`)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),

array('notun settings (quiz level, spin access, chess)',
"INSERT IGNORE INTO `settings` (`skey`,`svalue`,`sgroup`,`slabel`) VALUES
   ('quiz_level_mode',         '1',   'quiz',  'Level mode on (levels unlock one by one)'),
   ('quiz_questions_per_level','10',  'quiz',  'Questions in one level'),
   ('quiz_pass_percent',       '60',  'quiz',  'Marks % needed to clear a level'),
   ('quiz_levels_easy',        '0',   'quiz',  'Easy levels (0 = auto from the bank)'),
   ('quiz_levels_mid',         '0',   'quiz',  'Mid levels (0 = auto from the bank)'),
   ('quiz_levels_hard',        '0',   'quiz',  'Hard levels (0 = auto from the bank)'),
   ('quiz_repeat_months',      '6',   'quiz',  'Months before a question may repeat for a player'),
   ('quiz_level_bonus',        '50',  'quiz',  'Bonus coins for clearing a level'),
   ('quiz_default_marks',      '10',  'quiz',  'Default marks for a new question'),
   ('spin_access_mode',        'all', 'spin',  'Who may spin: all / assigned'),
   ('chess_enabled',           '1',   'chess', 'Chess enabled'),
   ('chess_ai_enabled',        '1',   'chess', 'Play against the computer enabled'),
   ('chess_pvp_enabled',       '1',   'chess', 'Staked player vs player matches enabled'),
   ('chess_min_stake',         '0',   'chess', 'Minimum coins a player may stake'),
   ('chess_max_stake',         '5000','chess', 'Maximum coins a player may stake'),
   ('chess_house_cut',         '0',   'chess', 'Percent of the pot kept by the house'),
   ('chess_ai_reward',         '25',  'chess', 'Coins for beating the computer')"),

array('proctor settings (purono file-er column-nam bug er jonno dhoke ni)',
"INSERT IGNORE INTO `settings` (`skey`,`svalue`,`sgroup`,`slabel`) VALUES
   ('quiz_strike_limit','1','quiz','Quiz warnings before a round is voided'),
   ('quiz_require_fullscreen','1','quiz','Force fullscreen exam mode in the quiz')"),

array('schema_version = 4',
"INSERT INTO `settings` (`skey`,`svalue`,`sgroup`,`slabel`)
   VALUES ('schema_version','4','general','Database schema version')
   ON DUPLICATE KEY UPDATE `svalue` = '4'"),
    );
}

/** "Age thekei chilo" — error na, khobor. */
function gamss_benign(PDOException $e)
{
    $code = 0;
    $info = $e->errorInfo;
    if (is_array($info) && isset($info[1])) {
        $code = (int) $info[1];
    }
    return in_array($code, array(1050, 1060, 1061, 1062, 1022, 1826, 1091), true);
}

function gamss_table($pdo, $db, $t)
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $st->execute(array($db, $t));
    return (bool) $st->fetchColumn();
}

function gamss_column($pdo, $db, $t, $c)
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute(array($db, $t, $c));
    return (bool) $st->fetchColumn();
}

function gamss_setting($pdo, $k)
{
    try {
        $st = $pdo->prepare('SELECT 1 FROM settings WHERE skey = ?');
        $st->execute(array($k));
        return (bool) $st->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/* ===================================================================
 * File check
 * =================================================================== */
$newFiles = array(
    'chess.php', 'chess-play.php', 'api/chess.php',
    'includes/chess.php', 'includes/quiz.php', 'includes/sheet.php',
    'admin/modules.php', 'admin/quiz-import.php', 'admin/quiz-levels.php', 'admin/chess.php',
    'assets/js/chess.js', 'assets/js/chess-play.js', 'assets/css/chess.css',
    'config/upgrade-v4.sql',
);
$markers = array(
    'config/config.php'         => "includes/quiz.php",
    'includes/migrate.php'      => "SCHEMA_VERSION = 4",
    'includes/header.php'       => "chess_on()",
    'includes/arena.php'        => "chess_pvp_on()",
    'quiz.php'                  => "quiz_level_mode()",
    'quiz-play.php'             => "quiz_deal(",
    'api/quiz.php'              => "quiz_save_level_result",
    'api/spin.php'              => "spin_allowed(",
    'spin.php'                  => "spin_allowed(",
    'admin/quiz.php'            => "time_limit",
    'admin/users.php'           => "spin_access",
    'admin/spin.php'            => "spin_access_mode",
    'admin/includes/layout.php' => "quiz-levels.php",
);

$missingFiles = array();
foreach ($newFiles as $f) {
    if (!is_file($ROOT . '/' . $f)) {
        $missingFiles[] = $f;
    }
}
$staleFiles = array();
foreach ($markers as $f => $needle) {
    $p = $ROOT . '/' . $f;
    if (!is_file($p)) {
        $staleFiles[$f] = 'file-i nei';
    } else {
        $txt = file_get_contents($p);
        if ($txt === false || strpos($txt, $needle) === false) {
            $staleFiles[$f] = 'purono version';
        }
    }
}

// Site 500 dicche kina — sob theke common karon
$configWantsNew = is_file($ROOT . '/config/config.php')
    && strpos((string) file_get_contents($ROOT . '/config/config.php'), "includes/quiz.php") !== false;
$brokenSite = array();
if ($configWantsNew) {
    foreach (array('includes/quiz.php', 'includes/chess.php') as $need) {
        if (!is_file($ROOT . '/' . $need)) {
            $brokenSite[] = $need;
        }
    }
}

/* ===================================================================
 * Connect + run
 * =================================================================== */
$connectLog = array();
$pdo = null;
$dbName = '';
$credSets = gamss_creds($ROOT . '/config/config.php');

if ($keyOk) {
    $r = gamss_connect($credSets, $connectLog);
    $pdo = $r[0];
    $dbName = $r[1];
}

$baseOk = false;
if ($pdo) {
    $baseOk = gamss_table($pdo, $dbName, 'users')
           && gamss_table($pdo, $dbName, 'settings')
           && gamss_table($pdo, $dbName, 'quiz_questions');
}

$run     = $keyOk && $pdo && $baseOk && isset($_GET['run']) && $_GET['run'] === '1';
$results = array();
$fails   = 0;

if ($run) {
    foreach (gamss_steps() as $step) {
        $label = $step[0];
        $sql   = $step[1];
        try {
            $pdo->exec($sql);
            $results[] = array($label, 'done', 'hoye gelo');
        } catch (PDOException $e) {
            if (gamss_benign($e)) {
                $results[] = array($label, 'skip', 'age thekei chilo');
            } else {
                $results[] = array($label, 'fail', $e->getMessage());
                $fails++;
            }
        }
    }
}

/* ---------- verification ---------- */
$verify = array();
$verifyOk = true;
if ($pdo && $baseOk) {
    foreach (array('quiz_level_progress', 'quiz_seen', 'chess_matches') as $t) {
        $ok = gamss_table($pdo, $dbName, $t);
        $verify[] = array('Table', $t, $ok);
        $verifyOk = $verifyOk && $ok;
    }
    $cols = array(
        'quiz_questions' => array('qcode', 'marks', 'time_limit'),
        'quiz_sessions'  => array('level_no', 'marks_total', 'marks_scored', 'passed'),
        'users'          => array('spin_access'),
    );
    foreach ($cols as $t => $list) {
        foreach ($list as $c) {
            $ok = gamss_column($pdo, $dbName, $t, $c);
            $verify[] = array('Column', $t . '.' . $c, $ok);
            $verifyOk = $verifyOk && $ok;
        }
    }
    $keys = array('quiz_level_mode', 'quiz_questions_per_level', 'quiz_pass_percent',
                  'quiz_repeat_months', 'quiz_level_bonus', 'quiz_default_marks',
                  'spin_access_mode', 'chess_enabled', 'chess_ai_enabled',
                  'chess_pvp_enabled', 'chess_min_stake', 'chess_max_stake', 'chess_ai_reward');
    foreach ($keys as $k) {
        $ok = gamss_setting($pdo, $k);
        $verify[] = array('Setting', $k, $ok);
        $verifyOk = $verifyOk && $ok;
    }
}

$self = htmlspecialchars(basename(__FILE__), ENT_QUOTES);
$qkey = isset($_GET['key']) ? '&key=' . rawurlencode((string) $_GET['key']) : '';
function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Gamss Migration — schema v4</title>
<style>
  body { margin:0; padding:34px 18px; background:#0b0f1a; color:#e9eefc;
         font:15px/1.65 system-ui,"Segoe UI",sans-serif; }
  .wrap { max-width:960px; margin:0 auto; }
  h1 { font-size:1.55rem; margin:0 0 4px; }
  h2 { font-size:1.02rem; margin:0 0 12px; color:#b6e857; }
  .sub { color:#93a0b8; margin:0 0 22px; }
  .card { background:#121729; border:1px solid #232c48; border-radius:14px;
          padding:18px 20px; margin-bottom:18px; }
  .card.warn { border-color:#7a5a1e; background:#1d1708; }
  .card.err  { border-color:#7a2b2b; background:#1d0f11; }
  table { width:100%; border-collapse:collapse; font-size:.9rem; }
  th,td { text-align:left; padding:8px 10px; border-bottom:1px solid #1e2740; vertical-align:top; }
  th { color:#93a0b8; font-weight:600; }
  tr:last-child td { border-bottom:0; }
  .tag { display:inline-block; padding:2px 9px; border-radius:99px; font-size:.76rem; font-weight:700; }
  .ok{background:rgba(143,206,63,.16);color:#b6e857}
  .skip{background:rgba(255,255,255,.07);color:#93a0b8}
  .bad{background:rgba(217,83,79,.18);color:#ff9b97}
  .btn{display:inline-block;padding:11px 20px;border-radius:11px;text-decoration:none;font-weight:700;
       background:linear-gradient(90deg,#6aa521,#b6e857);color:#0b0f1a}
  .btn.ghost{background:transparent;border:1px solid #2b3550;color:#e9eefc}
  code{background:#0e1424;padding:2px 6px;border-radius:6px;font-size:.85em}
  .note{color:#93a0b8;font-size:.9rem}
  ul{margin:8px 0 0 18px;padding:0}
</style>
</head>
<body>
<div class="wrap">

  <h1>Gamss Migration &mdash; Asviora Games schema v4</h1>
  <p class="sub">Quiz levels &middot; marks &middot; 6-month repeat block &middot; spin access &middot; chess</p>

  <div class="card">
    <h2>Server</h2>
    <table>
      <?php foreach ($diag as $k => $v): ?>
        <tr><td style="width:230px" class="note"><?= h($k) ?></td><td><code><?= h($v) ?></code></td></tr>
      <?php endforeach; ?>
    </table>
    <?php if ($phpOldForSite): ?>
      <p style="margin-bottom:0;color:#ffcf6b"><b>Dhyan din:</b> ei site-er code PHP <b>8.0+</b> chay
        (<code>str_starts_with</code> byabohar kore). Ekhon <?= h(PHP_VERSION) ?> chalche —
        hPanel &rarr; <b>PHP Configuration</b> theke 8.1 ba 8.2 kore nin, na hole site 500 debe.</p>
    <?php endif; ?>
  </div>

  <?php if ($brokenSite): ?>
    <div class="card err">
      <h2 style="color:#ff9b97">Site keno 500 dicche &mdash; ekhane</h2>
      <p><code>config/config.php</code> notun version ta upload hoyeche, kintu se je file gulo
         khoje sei gulo server-e nei:</p>
      <ul><?php foreach ($brokenSite as $f): ?><li><code><?= h($f) ?></code></li><?php endforeach; ?></ul>
      <p style="margin-bottom:0">Ei file gulo upload korlei site abar cholbe.</p>
    </div>
  <?php endif; ?>

<?php if (!$keyOk): ?>

  <div class="card">
    <h2>Key lagbe</h2>
    <p>URL-er sesh-e key ta jog korun:</p>
    <p><code><?= $self ?>?key=<?= h($MIGRATION_KEY) ?></code></p>
    <p class="note">Key ta ei file-er upor-er dike <code>$MIGRATION_KEY</code> theke pallte paren.</p>
  </div>

<?php else: ?>

  <div class="card <?= $pdo ? '' : 'err' ?>">
    <h2>Database connection</h2>
    <?php if (!$credSets): ?>
      <p><code>config/config.php</code> theke DB credentials para gelo na.
         File ta <code><?= h($ROOT) ?>/config/</code> te ache to?</p>
    <?php else: ?>
      <table>
        <?php foreach ($connectLog as $line): ?>
          <tr><td><code><?= h($line) ?></code></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <?php if ($pdo && !$baseOk): ?>
      <p style="color:#ff9b97;margin-bottom:0"><b><?= h($dbName) ?></b> database-e
        <code>users</code> / <code>settings</code> / <code>quiz_questions</code> table pawa jacche na.</p>
    <?php endif; ?>
  </div>

  <?php if ($pdo && $baseOk): ?>

    <?php if (!$run): ?>
      <div class="card">
        <h2>Ki ki hobe</h2>
        <p><b>16</b> ta step &mdash; sob gulo sudhu <b>ADD / CREATE</b>. Kono table drop hoy na,
           kono data mochha jay na. Age theke thakle skip kore jabe, baar baar chalaleo khoti nei.</p>
        <table>
          <tr><td class="note" style="width:150px">Notun table</td>
              <td>quiz_level_progress, quiz_seen, chess_matches</td></tr>
          <tr><td class="note">Notun column</td>
              <td>quiz_questions: qcode, marks, time_limit<br>
                  quiz_sessions: level_no, marks_total, marks_scored, passed<br>
                  users: spin_access</td></tr>
          <tr><td class="note">Notun index</td><td>uq_qq_code, idx_qs_level</td></tr>
          <tr><td class="note">Notun settings</td><td>19 ta</td></tr>
          <tr><td class="note">Sesh-e</td><td>schema_version = 4</td></tr>
        </table>
        <p style="margin:18px 0 0"><a class="btn" href="?run=1<?= $qkey ?>">Migration chalao</a></p>
        <p class="note">Age hPanel &rarr; phpMyAdmin theke ekta backup niye nile sob theke bhalo.</p>
      </div>
    <?php else: ?>
      <div class="card">
        <h2>Result</h2>
        <table>
          <tr><th style="width:110px">Status</th><th>Step</th><th>Detail</th></tr>
          <?php foreach ($results as $r): ?>
            <tr>
              <td><span class="tag <?= $r[1] === 'done' ? 'ok' : ($r[1] === 'skip' ? 'skip' : 'bad') ?>">
                <?= $r[1] === 'done' ? 'HOYECHE' : ($r[1] === 'skip' ? 'AGE CHILO' : 'FAIL') ?></span></td>
              <td><?= h($r[0]) ?></td>
              <td class="note"><?= h($r[2]) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <?php if ($fails): ?>
          <p style="color:#ff9b97;margin-bottom:0"><b><?= (int) $fails ?> ta step fail koreche.</b>
             Upor-er message ta amake janan. Abar chalate paren, khoti nei.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2>Database verification</h2>
      <table>
        <tr><th style="width:100px">Ki</th><th>Nam</th><th style="width:100px">Ache?</th></tr>
        <?php foreach ($verify as $v): ?>
          <tr><td class="note"><?= h($v[0]) ?></td><td><code><?= h($v[1]) ?></code></td>
              <td><span class="tag <?= $v[2] ? 'ok' : 'bad' ?>">
                <?= $v[2] ? '&#10003; ACHE' : '&#10007; NEI' ?></span></td></tr>
        <?php endforeach; ?>
      </table>
      <p style="margin-bottom:0">
        <?php if ($verifyOk): ?><span class="tag ok">Database ready</span>
        <?php else: ?><span class="tag bad">Ekhono baki ache</span>
          &nbsp;<a class="btn ghost" href="?run=1<?= $qkey ?>">Migration chalao</a><?php endif; ?>
      </p>
    </div>

  <?php endif; ?>

  <div class="card <?= ($missingFiles || $staleFiles) ? 'warn' : '' ?>">
    <h2>File verification</h2>
    <?php if (!$missingFiles && !$staleFiles): ?>
      <p style="margin-bottom:0"><span class="tag ok">Sob file thik ache</span>
        &mdash; 14 ta notun file ache, 14 ta modified file-o notun version.</p>
    <?php else: ?>
      <?php if ($missingFiles): ?>
        <p><b>Ei notun file gulo server-e nei &mdash; upload korte hobe:</b></p>
        <ul><?php foreach ($missingFiles as $f): ?><li><code><?= h($f) ?></code></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <?php if ($staleFiles): ?>
        <p style="margin-top:14px"><b>Ei file gulo purono version &mdash; notun ta upload korun:</b></p>
        <ul><?php foreach ($staleFiles as $f => $why): ?>
          <li><code><?= h($f) ?></code> <span class="note">(<?= h($why) ?>)</span></li>
        <?php endforeach; ?></ul>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Sesh kaj</h2>
    <ol style="margin:0 0 0 18px;padding:0">
      <li>Admin &rarr; <b>Modules</b> &mdash; sob mode live ache kina dekhun.</li>
      <li>Admin &rarr; <b>Import Questions</b> &rarr; QSTN.xlsx (sheet number 2).</li>
      <li>Admin &rarr; <b>Quiz Levels</b> &rarr; level size / pass mark.</li>
      <li>Admin &rarr; <b>Chess</b> &rarr; stake limit.</li>
      <li><b>Ei file ta (<code><?= $self ?></code>) delete kore din.</b></li>
    </ol>
  </div>

<?php endif; ?>

</div>
</body>
</html>
