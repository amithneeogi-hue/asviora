<?php
/**
 * ===================================================================
 * GAMSS MIGRATION  —  Asviora Games schema v4
 * ===================================================================
 * Ekta file. Live server-e upload korun ar browser theke ekbar khulun:
 *
 *      https://asviora.in/gamssmigration.php?key=gamss-2026
 *
 * Ki kore:
 *   1. Database-e ja ja notun lagbe (table, column, index, settings)
 *      sob toiri kore — ja age thekei ache setai chhuye dekhe chhere dey.
 *   2. Sesh-e ekta verification table dekhay: prottek jinis ✔ na ✘.
 *   3. `schema_version` ke 4 e set kore, jate includes/migrate.php
 *      abar same kaj korar chesta na kore.
 *
 * Baar baar chalale kono khoti nei — protita statement idempotent, ar
 * "already there" gulo error na dhore skip kora hoy. Kono data mochha
 * hoy na: sudhu ADD / CREATE, kono DROP nei.
 *
 * KAJ SESH HOLE EI FILE TA SERVER THEKE DELETE KORE DEBEN.
 * ===================================================================
 */

/* ---------- 1. Access ---------- */
// Ei key ta palte nite paren. URL-e ?key=... na dile, ba admin hisebe
// login kora na thakle, script kichui korbe na.
const MIGRATION_KEY = 'gamss-2026';

/* ---------- 2. Bootstrap ---------- */
// config.php nijer theke migration chalate chay; ei script tar dayitto
// nijer hate niche, tai oi ta bondho kore boot kora hocche.
define('SKIP_MIGRATIONS', true);

$configPath = __DIR__ . '/config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('config/config.php pawa jacche na. gamssmigration.php ta site-er root folder-e rakhun '
       . '(jekhane index.php ache).');
}
require_once $configPath;

$isAdmin = !empty($_SESSION['admin_id']);
$keyOk   = isset($_GET['key']) && hash_equals(MIGRATION_KEY, (string) $_GET['key']);

/* ---------- 3. Ja ja lagbe ---------- */

/**
 * Protita step: label + SQL. SQL gulo emon bhabe lekha je duibar chalale
 * o kichu bhange na.
 */
function migration_steps(): array
{
    return [

        // ---------- quiz_questions ----------
        ['quiz_questions.qcode column (bulk sheet-er Question ID)',
         "ALTER TABLE `quiz_questions`
            ADD COLUMN `qcode` VARCHAR(40) DEFAULT NULL
            COMMENT 'Question ID from the bulk sheet' AFTER `id`"],

        ['quiz_questions.marks column',
         "ALTER TABLE `quiz_questions`
            ADD COLUMN `marks` SMALLINT UNSIGNED NOT NULL DEFAULT 10 AFTER `correct`"],

        ['quiz_questions.time_limit column (per-question ghori)',
         "ALTER TABLE `quiz_questions`
            ADD COLUMN `time_limit` SMALLINT UNSIGNED DEFAULT NULL
            COMMENT 'seconds; NULL = use the level default' AFTER `marks`"],

        ['quiz_questions unique key uq_qq_code (duplicate import atkabe)',
         "ALTER TABLE `quiz_questions` ADD UNIQUE KEY `uq_qq_code` (`qcode`)"],

        // ---------- quiz_sessions ----------
        ['quiz_sessions.level_no column',
         "ALTER TABLE `quiz_sessions`
            ADD COLUMN `level_no` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `difficulty`"],

        ['quiz_sessions.marks_total column',
         "ALTER TABLE `quiz_sessions`
            ADD COLUMN `marks_total` INT NOT NULL DEFAULT 0 AFTER `correct`"],

        ['quiz_sessions.marks_scored column',
         "ALTER TABLE `quiz_sessions`
            ADD COLUMN `marks_scored` INT NOT NULL DEFAULT 0 AFTER `marks_total`"],

        ['quiz_sessions.passed column',
         "ALTER TABLE `quiz_sessions`
            ADD COLUMN `passed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `marks_scored`"],

        ['quiz_sessions index idx_qs_level',
         "ALTER TABLE `quiz_sessions` ADD KEY `idx_qs_level` (`user_id`,`difficulty`,`level_no`)"],

        // ---------- level progress ----------
        ['quiz_level_progress table (kon player kon level clear korlo)',
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
            `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_qlp` (`user_id`,`difficulty`,`level_no`),
            KEY `idx_qlp_pass` (`user_id`,`difficulty`,`passed`),
            CONSTRAINT `fk_qlp_user` FOREIGN KEY (`user_id`)
              REFERENCES `users` (`id`) ON DELETE CASCADE
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],

        // ---------- seen ledger ----------
        ['quiz_seen table (6 mash repeat block)',
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
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],

        // ---------- spin access ----------
        ['users.spin_access column (admin ke spin assign korbe)',
         "ALTER TABLE `users`
            ADD COLUMN `spin_access` ENUM('default','allowed','blocked')
            NOT NULL DEFAULT 'default' AFTER `status`"],

        // ---------- chess ----------
        ['chess_matches table (AI + staked match)',
         "CREATE TABLE IF NOT EXISTS `chess_matches` (
            `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code`         VARCHAR(12) NOT NULL COMMENT 'invite code used in the share link',
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
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],

        // ---------- settings ----------
        ['notun settings (level, spin access, chess)',
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
            ('chess_ai_reward',         '25',  'chess', 'Coins for beating the computer')"],

        // ---------- v3 patch ----------
        // Purono upgrade-quiz-proctor.sql e settings-er column nam bhul chilo
        // (`key`/`value`), tai oi duto setting kokhono dhoke ni. Ekhane thik kore
        // dewa hocche.
        ['proctor settings (quiz_strike_limit, quiz_require_fullscreen)',
         "INSERT IGNORE INTO `settings` (`skey`,`svalue`,`sgroup`,`slabel`) VALUES
            ('quiz_strike_limit','1','quiz','Quiz warnings before a round is voided'),
            ('quiz_require_fullscreen','1','quiz','Force fullscreen exam mode in the quiz')"],
    ];
}

/** Verification: ei gulo run-er por thakte-i hobe. */
function migration_checks(): array
{
    return [
        'table'   => ['quiz_level_progress', 'quiz_seen', 'chess_matches'],
        'column'  => [
            'quiz_questions' => ['qcode', 'marks', 'time_limit'],
            'quiz_sessions'  => ['level_no', 'marks_total', 'marks_scored', 'passed'],
            'users'          => ['spin_access'],
        ],
        'setting' => [
            'quiz_level_mode', 'quiz_questions_per_level', 'quiz_pass_percent',
            'quiz_repeat_months', 'quiz_level_bonus', 'quiz_default_marks',
            'spin_access_mode', 'chess_enabled', 'chess_ai_enabled',
            'chess_pvp_enabled', 'chess_min_stake', 'chess_max_stake',
            'chess_ai_reward',
        ],
    ];
}

/** "Ei change ta age thekei ache" — error na, khobor. */
function benign_sql_error(PDOException $e): bool
{
    //  1050 table exists    1060 duplicate column   1061 duplicate key
    //  1062 duplicate entry 1022 duplicate key      1826 duplicate FK
    //  1091 key/column does not exist
    return in_array((int) ($e->errorInfo[1] ?? 0),
                    [1050, 1060, 1061, 1062, 1022, 1826, 1091], true);
}

function table_exists(string $t): bool
{
    return (bool) fetch_one(
        'SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
}

function column_exists(string $t, string $c): bool
{
    return (bool) fetch_one(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
}

function setting_exists(string $k): bool
{
    return (bool) fetch_one('SELECT 1 FROM settings WHERE skey = ?', [$k]);
}

/* ---------- 4. Run ---------- */

$ran     = ($isAdmin || $keyOk) && ($_GET['run'] ?? '') === '1';
$results = [];
$fails   = 0;

// Ager schema (v2 = quiz + spin) na thakle ei script-er kono mane nei
$baseOk = table_exists('quiz_questions') && table_exists('settings') && table_exists('users');

if ($ran && $baseOk) {
    $pdo = db();
    foreach (migration_steps() as [$label, $sql]) {
        try {
            $pdo->exec($sql);
            $results[] = [$label, 'done', 'toiri holo'];
        } catch (PDOException $e) {
            if (benign_sql_error($e)) {
                $results[] = [$label, 'skip', 'age thekei chilo'];
            } else {
                $results[] = [$label, 'fail', $e->getMessage()];
                $fails++;
            }
        }
    }

    if ($fails === 0) {
        try {
            set_setting('schema_version', '4');
            $results[] = ['schema_version = 4', 'done', 'migrate.php ar cholbe na'];
        } catch (Throwable $e) {
            $results[] = ['schema_version = 4', 'fail', $e->getMessage()];
            $fails++;
        }
    }
}

/* ---------- 5. Verification ---------- */
$verify   = [];
$verifyOk = true;
if ($baseOk) {
    $c = migration_checks();
    foreach ($c['table'] as $t) {
        $ok = table_exists($t);
        $verify[] = ['Table', $t, $ok];
        $verifyOk = $verifyOk && $ok;
    }
    foreach ($c['column'] as $t => $cols) {
        foreach ($cols as $col) {
            $ok = column_exists($t, $col);
            $verify[] = ['Column', $t . '.' . $col, $ok];
            $verifyOk = $verifyOk && $ok;
        }
    }
    foreach ($c['setting'] as $k) {
        $ok = setting_exists($k);
        $verify[] = ['Setting', $k, $ok];
        $verifyOk = $verifyOk && $ok;
    }
}

/* ---------- 6. Notun file gulo server-e uthlo ki na ---------- */
$expectFiles = [
    'chess.php', 'chess-play.php',
    'api/chess.php',
    'includes/chess.php', 'includes/quiz.php', 'includes/sheet.php',
    'admin/modules.php', 'admin/quiz-import.php', 'admin/quiz-levels.php', 'admin/chess.php',
    'assets/js/chess.js', 'assets/js/chess-play.js', 'assets/css/chess.css',
    'config/upgrade-v4.sql',
];
$missingFiles = [];
foreach ($expectFiles as $f) {
    if (!is_file(__DIR__ . '/' . $f)) {
        $missingFiles[] = $f;
    }
}

// modified file gulo asolei notun version kina — ekta kore marker khuji
$markers = [
    'config/config.php'        => "includes/quiz.php",
    'includes/migrate.php'     => "SCHEMA_VERSION = 4",
    'includes/header.php'      => "chess_on()",
    'includes/arena.php'       => "chess_pvp_on()",
    'quiz.php'                 => "quiz_level_mode()",
    'quiz-play.php'            => "quiz_deal(",
    'api/quiz.php'             => "quiz_save_level_result",
    'api/spin.php'             => "spin_allowed(",
    'spin.php'                 => "spin_allowed(",
    'admin/quiz.php'           => "time_limit",
    'admin/users.php'          => "spin_access",
    'admin/spin.php'           => "spin_access_mode",
    'admin/includes/layout.php'=> "quiz-levels.php",
];
$staleFiles = [];
foreach ($markers as $f => $needle) {
    $p = __DIR__ . '/' . $f;
    if (!is_file($p)) {
        $staleFiles[$f] = 'file-i nei';
    } elseif (strpos((string) file_get_contents($p), $needle) === false) {
        $staleFiles[$f] = 'purono version';
    }
}

$self = htmlspecialchars(basename(__FILE__), ENT_QUOTES);
$key  = htmlspecialchars((string) ($_GET['key'] ?? ''), ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Gamss Migration — schema v4</title>
<style>
  :root { color-scheme: dark; }
  body { margin:0; padding:34px 18px; background:#0b0f1a; color:#e9eefc;
         font:15px/1.65 system-ui,"Segoe UI",sans-serif; }
  .wrap { max-width:940px; margin:0 auto; }
  h1 { font-size:1.6rem; margin:0 0 4px; }
  h2 { font-size:1.05rem; margin:28px 0 10px; color:#b6e857; }
  .sub { color:#93a0b8; margin:0 0 22px; }
  .card { background:#121729; border:1px solid #232c48; border-radius:14px;
          padding:18px 20px; margin-bottom:18px; }
  table { width:100%; border-collapse:collapse; font-size:.9rem; }
  th, td { text-align:left; padding:8px 10px; border-bottom:1px solid #1e2740; vertical-align:top; }
  th { color:#93a0b8; font-weight:600; }
  tr:last-child td { border-bottom:0; }
  .tag { display:inline-block; padding:2px 9px; border-radius:99px; font-size:.76rem; font-weight:700; }
  .ok   { background:rgba(143,206,63,.16); color:#b6e857; }
  .skip { background:rgba(255,255,255,.07); color:#93a0b8; }
  .bad  { background:rgba(217,83,79,.18); color:#ff9b97; }
  .btn { display:inline-block; padding:11px 20px; border-radius:11px; text-decoration:none;
         font-weight:700; background:linear-gradient(90deg,#6aa521,#b6e857); color:#0b0f1a; }
  .btn.ghost { background:transparent; border:1px solid #2b3550; color:#e9eefc; }
  code { background:#0e1424; padding:2px 6px; border-radius:6px; font-size:.85em; }
  .note { color:#93a0b8; font-size:.9rem; }
</style>
</head>
<body>
<div class="wrap">

  <h1>Gamss Migration &mdash; Asviora Games schema v4</h1>
  <p class="sub">Quiz levels &middot; marks &middot; 6-month repeat block &middot; spin access &middot; chess</p>

<?php if (!$isAdmin && !$keyOk): ?>

  <div class="card">
    <h2 style="margin-top:0">Access lagbe</h2>
    <p>Ei page ta khulte holo dutor ekta lagbe:</p>
    <ul>
      <li>admin panel e login kora thakte hobe (<code>/admin/</code>), <b>othoba</b></li>
      <li>URL-e key debe: <code><?= $self ?>?key=<?= htmlspecialchars(MIGRATION_KEY) ?></code></li>
    </ul>
    <p class="note">Key ta ei file-er 1st line-er kacher
       <code>MIGRATION_KEY</code> theke pallte paren.</p>
  </div>

<?php elseif (!$baseOk): ?>

  <div class="card">
    <h2 style="margin-top:0">Database ta ekhono toiri noy</h2>
    <p><code>users</code> / <code>settings</code> / <code>quiz_questions</code> — ei table
       gulo pawa jacche na. Age <code>config/database.sql</code> (notun install) ba
       <code>config/upgrade-quiz-spin.sql</code> (purono site) import korun, tarpor
       ei page ta abar khulun.</p>
  </div>

<?php else: ?>

  <?php if (!$ran): ?>
    <div class="card">
      <h2 style="margin-top:0">Ki ki hobe</h2>
      <p>Niche-r <b>13</b> ta step chalabo. Sob gulo-i sudhu <b>ADD / CREATE</b> &mdash;
         kono table drop hoy na, kono data mochha jay na. Age theke thakle skip kore jabe.</p>
      <table>
        <tr><th style="width:150px">Ki</th><th>Nam</th></tr>
        <tr><td>Notun table</td><td>quiz_level_progress, quiz_seen, chess_matches</td></tr>
        <tr><td>Notun column</td><td>quiz_questions: qcode, marks, time_limit<br>
            quiz_sessions: level_no, marks_total, marks_scored, passed<br>
            users: spin_access</td></tr>
        <tr><td>Notun index</td><td>uq_qq_code, idx_qs_level</td></tr>
        <tr><td>Notun settings</td><td>19 ta (quiz level, spin access, chess)</td></tr>
        <tr><td>Sesh-e</td><td>schema_version = 4</td></tr>
      </table>
      <p style="margin-top:18px">
        <a class="btn" href="?run=1<?= $key !== '' ? '&key=' . $key : '' ?>">Migration chalao</a>
      </p>
      <p class="note">Chalanor age hostinger/cPanel theke database-er ekta backup niye
         nile sob theke bhalo.</p>
    </div>
  <?php else: ?>
    <div class="card">
      <h2 style="margin-top:0">Result</h2>
      <table>
        <tr><th style="width:110px">Status</th><th>Step</th><th>Detail</th></tr>
        <?php foreach ($results as [$label, $state, $msg]): ?>
          <tr>
            <td><span class="tag <?= $state === 'done' ? 'ok' : ($state === 'skip' ? 'skip' : 'bad') ?>">
              <?= $state === 'done' ? 'HOYECHE' : ($state === 'skip' ? 'AGE CHILO' : 'FAIL') ?></span></td>
            <td><?= htmlspecialchars($label) ?></td>
            <td class="note"><?= htmlspecialchars($msg) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php if ($fails): ?>
        <p style="color:#ff9b97;margin-bottom:0"><b><?= $fails ?> ta step fail koreche.</b>
           Upor-er message ta dekhe janan &mdash; schema_version bumped hoyni, tai abar
           chalate paren.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2 style="margin-top:0">Database verification</h2>
    <table>
      <tr><th style="width:110px">Ki</th><th>Nam</th><th style="width:90px">Ache?</th></tr>
      <?php foreach ($verify as [$kind, $name, $ok]): ?>
        <tr><td class="note"><?= $kind ?></td><td><code><?= htmlspecialchars($name) ?></code></td>
            <td><span class="tag <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '&#10003; ACHE' : '&#10007; NEI' ?></span></td></tr>
      <?php endforeach; ?>
    </table>
    <p style="margin-bottom:0">
      <?php if ($verifyOk): ?>
        <span class="tag ok">Database ready</span>
      <?php else: ?>
        <span class="tag bad">Ekhono baki ache</span>
        &nbsp;<a class="btn ghost" href="?run=1<?= $key !== '' ? '&key=' . $key : '' ?>">Abar chalao</a>
      <?php endif; ?>
    </p>
  </div>

  <div class="card">
    <h2 style="margin-top:0">File verification</h2>
    <?php if (!$missingFiles && !$staleFiles): ?>
      <p><span class="tag ok">Sob file thik ache</span> &mdash; 14 ta notun file ache,
         14 ta modified file-o notun version.</p>
    <?php else: ?>
      <?php if ($missingFiles): ?>
        <p><b>Ei notun file gulo server-e nei &mdash; upload korte hobe:</b></p>
        <ul><?php foreach ($missingFiles as $f): ?>
          <li><code><?= htmlspecialchars($f) ?></code></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <?php if ($staleFiles): ?>
        <p><b>Ei file gulo purono version &mdash; notun ta upload korun:</b></p>
        <ul><?php foreach ($staleFiles as $f => $why): ?>
          <li><code><?= htmlspecialchars($f) ?></code> <span class="note">(<?= $why ?>)</span></li>
        <?php endforeach; ?></ul>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Sesh kaj</h2>
    <ol>
      <li>Admin &rarr; <b>Modules</b> khule dekhun sob mode live ache kina.</li>
      <li>Admin &rarr; <b>Import Questions</b> &rarr; QSTN.xlsx (sheet number 2) upload korun.</li>
      <li>Admin &rarr; <b>Quiz Levels</b> &rarr; level size / pass mark set korun.</li>
      <li>Admin &rarr; <b>Chess</b> &rarr; stake limit set korun.</li>
      <li><b>Ei file ta (<code><?= $self ?></code>) server theke delete kore din.</b></li>
    </ol>
  </div>

<?php endif; ?>

</div>
</body>
</html>
