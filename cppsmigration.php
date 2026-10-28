<?php
/**
 * ===================================================================
 * CPPS MIGRATION  —  Asviora Games schema v6  (standalone + repair)
 * ===================================================================
 * Ekdom eka cholte pare — site-er kono file include kore na, sudhu
 * config/config.php theke DB credentials ta pore ney.
 *
 *      https://asviora.in/cppsmigration.php?key=cpps-2026
 *
 * REPAIR MODE (v2)
 * ----------------
 * Age ei file ta sudhu v6-er poriborton gulo apply korto, ar dhore
 * nito je agher migration gulo (v2..v5) age thekei chola ache. Live
 * server-e seta chilo na — `modules` ar `spin_access` table-i toiri
 * hoyni, othocho `schema_version` 6 hoye giyechilo, tai auto-migration
 * oi table gulo ar kokhonoi banato na.
 *
 * Ekhon ei page ta `schema_version` ke BISWAS KORE NA. Prottekbar
 * config/ folder-er SOB upgrade SQL file ek-ek kore chalay:
 *
 *      upgrade-quiz-spin.sql   -> quiz + spin er base table
 *      upgrade-quiz-proctor.sql-> quiz_violations
 *      upgrade-v5.sql          -> modules, spin_access, quiz levels,
 *                                 score_edits, api_tokens, chess
 *      upgrade-v6.sql          -> visits, ip_geo, visit_daily,
 *                                 exam_notify, spin limit column
 *
 * Sob statement idempotent (CREATE TABLE IF NOT EXISTS / INSERT IGNORE
 * / duplicate-column error ignore kora) — tai dubar-tinbar chalale-o
 * kichu nosto hoy na, ja age thekei ache seta sudhu skip hoy.
 *
 * Tarpor ekta CHECKLIST miliye dekhe sob table ar column jaygamoto
 * ache kina — ar sob thik thakle TOBEI schema_version 6 kore.
 * Kichu bakita thakle version bump kore na, jate porer bar abar
 * cheshta hoy.
 *
 * `USE`, `CREATE DATABASE` ar `DROP` statement gulo icche kore skip
 * kora hoy — hosting-er database-er nam alada, ar kono obosthatei
 * ei page theke kono table drop hobe na.
 *
 * KAJ SESH HOLE EI FILE TA SERVER THEKE DELETE KORE DEBEN.
 * ===================================================================
 */

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@set_time_limit(300);
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
$MIGRATION_KEY = 'cpps-2026';
$TARGET_VERSION = 6;

$ROOT  = __DIR__;
$keyOk = isset($_GET['key']) && hash_equals($MIGRATION_KEY, (string) $_GET['key']);
$run   = $keyOk && isset($_GET['run']) && $_GET['run'] === '1';

/* ===================================================================
 * Server diagnostics
 * =================================================================== */
$diag = array(
    'PHP version'         => PHP_VERSION,
    'PDO MySQL'           => extension_loaded('pdo_mysql') ? 'ache' : 'NEI — host support lagbe',
    'cURL (geo lookup)'   => function_exists('curl_init') ? 'ache'
                             : (ini_get('allow_url_fopen') ? 'nei, kintu allow_url_fopen ache'
                                                           : 'NEI — city/country lookup cholbe na'),
    'mbstring'            => function_exists('mb_substr') ? 'ache' : 'NEI — lagbe',
    'Site folder'         => $ROOT,
);

/* ===================================================================
 * config/config.php theke DB credentials ber kora
 * =================================================================== */
function cpps_creds($file)
{
    if (!is_file($file)) {
        return array();
    }
    $src = file_get_contents($file);
    if ($src === false) {
        return array();
    }

    preg_match_all(
        "/define\\(\\s*'(DB_HOST|DB_NAME|DB_USER|DB_PASS)'\\s*,\\s*'([^']*)'\\s*\\)/",
        $src, $m, PREG_SET_ORDER);

    $sets = array();
    $cur  = array();
    foreach ($m as $one) {
        if ($one[1] === 'DB_HOST' && $cur) {
            $sets[] = $cur;
            $cur = array();
        }
        $cur[$one[1]] = $one[2];
    }
    if ($cur) {
        $sets[] = $cur;
    }

    // live server hole live block ta age try korbo
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
    $isLocal = ($host === 'localhost' || strpos($host, 'localhost:') === 0
             || strpos($host, '127.0.0.1') === 0 || strpos($host, '192.168.') === 0);
    if (!$isLocal) {
        $sets = array_reverse($sets);
    }
    return $sets;
}

function cpps_connect($sets, &$why)
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
    return array(null, null);
}

/* ---------- choto helper gulo ---------- */
function cpps_has_table($pdo, $t)
{
    try {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $st->execute(array($t));
        return (bool) $st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function cpps_has_column($pdo, $t, $c)
{
    try {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute(array($t, $c));
        return (bool) $st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function cpps_has_index($pdo, $t, $k)
{
    try {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS
                              WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $st->execute(array($t, $k));
        return (bool) $st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/** "already there" type error gulo — egulo bhul na. */
function cpps_benign(PDOException $e)
{
    $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
    return in_array($code, array(1050, 1060, 1061, 1062, 1022, 1826, 1091, 1553), true);
}

/* ===================================================================
 * SQL FILE RUNNER — agher sob migration file chalanor jonno
 * =================================================================== */

/**
 * Ekta SQL dump ke statement-e bhag kore.
 * Quote-er bhitorer semicolon ar comment-er semicolon bhangbe na —
 * seed data-y `&#127758;` type HTML entity thake.
 */
function cpps_split_sql($sql)
{
    $out = array();
    $cur = '';
    $len = strlen($sql);
    $i   = 0;
    $q   = null;

    while ($i < $len) {
        $c = $sql[$i];

        if ($q !== null) {                       // quoted string-er bhitore
            $cur .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $cur .= $sql[$i + 1];
                $i += 2;
                continue;
            }
            if ($c === $q) {
                if ($i + 1 < $len && $sql[$i + 1] === $q) {   // '' escape
                    $cur .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                $q = null;
            }
            $i++;
            continue;
        }

        if (($c === '-' && substr($sql, $i, 2) === '--') || $c === '#') {
            $nl = strpos($sql, "\n", $i);
            if ($nl === false) { break; }
            $i = $nl + 1;
            continue;
        }
        if ($c === '/' && substr($sql, $i, 2) === '/*') {
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) { break; }
            $i = $end + 2;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $q = $c;
            $cur .= $c;
            $i++;
            continue;
        }
        if ($c === ';') {
            $t = trim($cur);
            if ($t !== '') { $out[] = $t; }
            $cur = '';
            $i++;
            continue;
        }

        $cur .= $c;
        $i++;
    }

    $t = trim($cur);
    if ($t !== '') { $out[] = $t; }
    return $out;
}

/**
 * upgrade-v5.sql er ekta built-in copy.
 *
 * Live server-e dekha gelo `config/upgrade-v5.sql` file ta upload-i
 * hoyni — tai `modules`, `spin_access` ar quiz level-er table gulo
 * kokhono toiri hoyni, ar tar upor `schema_version` 6 lekha chilo bole
 * auto-migration ar chestai korto na.
 *
 * Ekhon oi puro SQL ta ei file-er bhitorei rakha ache. config/ folder-e
 * file ta thakle setai chalano hoy; na thakle ei copy ta chole, tai
 * sudhu ei ekta file upload korlei database thik hoye jabe.
 */
function cpps_embedded_sql($file)
{
    if ($file !== 'upgrade-v5.sql') {
        return null;
    }
    return <<<'V5SQL'
-- ===================================================================
--  ASVIORA GAMES — schema upgrade v4
--  "Levels, modules, spin access, marks editing, chess, mobile API"
--
--  Applied automatically by includes/migrate.php (SCHEMA_VERSION = 4).
--  Every statement is idempotent: safe to run again on a live database.
-- ===================================================================

-- -------------------------------------------------------------------
-- 1. MODULES — one switch per feature, so the admin can take anything
--    live or hide it without touching code. Games keep their own
--    `games.is_active` flag; this table covers everything that is not
--    a game row (quiz, spin, leaderboard, blog, chess lobby, ...).
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `modules` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mkey`        VARCHAR(40)  NOT NULL,
  `label`       VARCHAR(80)  NOT NULL,
  `icon`        VARCHAR(20)  DEFAULT NULL,
  `blurb`       VARCHAR(190) DEFAULT NULL,
  `kind`        ENUM('feature','game') NOT NULL DEFAULT 'feature',
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `nav_visible` TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `updated_by`  INT UNSIGNED DEFAULT NULL,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_module_key` (`mkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `modules` (`mkey`,`label`,`icon`,`blurb`,`kind`,`is_active`,`sort_order`) VALUES
  ('games',       'Games arcade',   '&#127918;', 'The whole games catalogue and play pages.',      'feature', 1, 10),
  ('quiz',        'Quiz contest',   '&#129504;', 'Levelled quiz with timed questions and coins.',  'feature', 1, 20),
  ('spin',        'Daily spin',     '&#127920;', 'The prize wheel.',                               'feature', 1, 30),
  ('chess',       'Chess',          '&#9822;',   'Chess vs computer, local 2-player and online.',  'feature', 1, 40),
  ('chess_online','Chess online',   '&#127760;', 'Player-vs-player chess over the network.',       'feature', 1, 45),
  ('leaderboard', 'Leaderboards',   '&#127942;', 'Public ranking pages.',                          'feature', 1, 50),
  ('blog',        'Blog',           '&#128221;', 'Articles and post pages.',                       'feature', 1, 60),
  ('mobile_api',  'Mobile API',     '&#128241;', 'The /api/v1 endpoints the Android app uses.',    'feature', 1, 70);

-- -------------------------------------------------------------------
-- 2. QUIZ LEVELS
--    A bank of questions is cut into levels: 300 questions with a
--    level size of 10 = 30 levels. The size, the pass mark and the
--    unlock rules are all admin-controlled, per topic if wanted.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_level_config` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id`         INT UNSIGNED DEFAULT NULL COMMENT 'NULL row = the global default',
  `difficulty`          ENUM('easy','mid','hard') NOT NULL DEFAULT 'easy',
  `questions_per_level` INT UNSIGNED NOT NULL DEFAULT 10,
  `pass_percent`        INT UNSIGNED NOT NULL DEFAULT 60,
  `max_levels`          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = as many as the bank allows',
  `time_seconds`        INT UNSIGNED DEFAULT NULL COMMENT 'NULL = use the difficulty default',
  `reward_coins`        INT UNSIGNED DEFAULT NULL,
  `level_clear_bonus`   INT UNSIGNED NOT NULL DEFAULT 50,
  `is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
  `updated_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_qlc` (`category_id`,`difficulty`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `quiz_level_config`
  (`category_id`,`difficulty`,`questions_per_level`,`pass_percent`,`level_clear_bonus`) VALUES
  (NULL,'easy',10,60,50),
  (NULL,'mid', 10,60,100),
  (NULL,'hard',10,70,200);

-- Per-player progress. One row per (player, topic, difficulty, level).
CREATE TABLE IF NOT EXISTS `quiz_user_levels` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `category_id`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = all topics mixed',
  `difficulty`   ENUM('easy','mid','hard') NOT NULL DEFAULT 'easy',
  `level_no`     INT UNSIGNED NOT NULL DEFAULT 1,
  `status`       ENUM('unlocked','passed') NOT NULL DEFAULT 'unlocked',
  `attempts`     INT UNSIGNED NOT NULL DEFAULT 0,
  `best_correct` INT UNSIGNED NOT NULL DEFAULT 0,
  `best_total`   INT UNSIGNED NOT NULL DEFAULT 0,
  `best_percent` INT UNSIGNED NOT NULL DEFAULT 0,
  `stars`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `first_seen`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `passed_at`    DATETIME  DEFAULT NULL,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_qul` (`user_id`,`category_id`,`difficulty`,`level_no`),
  KEY `idx_qul_user` (`user_id`,`difficulty`,`status`),
  CONSTRAINT `fk_qul_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every question a player has actually been shown. This is what makes
-- "a question does not come back for six months" possible, and it is
-- also what keeps two players from seeing the same set.
CREATE TABLE IF NOT EXISTS `quiz_question_history` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `session_id`  BIGINT UNSIGNED DEFAULT NULL,
  `difficulty`  ENUM('easy','mid','hard') NOT NULL DEFAULT 'easy',
  `level_no`    INT UNSIGNED NOT NULL DEFAULT 0,
  `was_correct` TINYINT(1)   NOT NULL DEFAULT 0,
  `seen_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_qqh_lookup` (`user_id`,`question_id`,`seen_at`),
  KEY `idx_qqh_recent` (`user_id`,`seen_at`),
  CONSTRAINT `fk_qqh_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Level columns on the round + question tables
ALTER TABLE `quiz_sessions` ADD COLUMN `level_no` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `difficulty`;
ALTER TABLE `quiz_sessions` ADD COLUMN `passed`   TINYINT(1)   NOT NULL DEFAULT 0 AFTER `correct`;
ALTER TABLE `quiz_sessions` ADD COLUMN `marks_edited_by` INT UNSIGNED DEFAULT NULL AFTER `void_reason`;
ALTER TABLE `quiz_sessions` ADD COLUMN `marks_edited_at` DATETIME DEFAULT NULL AFTER `marks_edited_by`;

ALTER TABLE `quiz_questions` ADD COLUMN `import_batch` INT UNSIGNED DEFAULT NULL AFTER `is_active`;
ALTER TABLE `quiz_questions` ADD COLUMN `fingerprint`  CHAR(32)     DEFAULT NULL AFTER `import_batch`;
-- columns that match the QSTN.xlsx bulk-upload sheet one to one
ALTER TABLE `quiz_questions` ADD COLUMN `ext_id`     VARCHAR(40)  DEFAULT NULL COMMENT 'Question ID from the upload sheet' AFTER `id`;
ALTER TABLE `quiz_questions` ADD COLUMN `marks`      INT UNSIGNED NOT NULL DEFAULT 1 AFTER `correct`;
ALTER TABLE `quiz_questions` ADD COLUMN `time_limit` INT UNSIGNED DEFAULT NULL COMMENT 'seconds; NULL = use the level default' AFTER `marks`;
ALTER TABLE `quiz_questions` ADD KEY `idx_qq_fingerprint` (`fingerprint`);
ALTER TABLE `quiz_questions` ADD KEY `idx_qq_batch` (`import_batch`);
ALTER TABLE `quiz_questions` ADD UNIQUE KEY `uq_qq_ext` (`ext_id`);

-- marks-based scoring alongside the plain correct-answer count
ALTER TABLE `quiz_sessions` ADD COLUMN `marks_total`  INT UNSIGNED NOT NULL DEFAULT 0 AFTER `passed`;
ALTER TABLE `quiz_sessions` ADD COLUMN `marks_earned` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `marks_total`;

-- The per-player shuffle key. Two players with different seeds get a
-- different cut of the same bank, so the same level number is a
-- different set of questions for each of them.
ALTER TABLE `users` ADD COLUMN `quiz_seed` CHAR(16) DEFAULT NULL AFTER `level`;

-- -------------------------------------------------------------------
-- 3. BULK IMPORT LOG
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_imports` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`     INT UNSIGNED DEFAULT NULL,
  `filename`     VARCHAR(190) DEFAULT NULL,
  `rows_total`   INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_ok`      INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_dupe`    INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_failed`  INT UNSIGNED NOT NULL DEFAULT 0,
  `report`       MEDIUMTEXT,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_qi_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 4. SPIN ACCESS — the wheel can be switched from "everyone" to
--    "only players the admin has granted".
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `spin_access` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `is_allowed`    TINYINT(1)   NOT NULL DEFAULT 1,
  `spins_per_day` INT UNSIGNED NOT NULL DEFAULT 1,
  `bonus_spins`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'one-off extras, consumed first',
  `valid_from`    DATE         DEFAULT NULL,
  `valid_until`   DATE         DEFAULT NULL,
  `note`          VARCHAR(190) DEFAULT NULL,
  `granted_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_spin_access_user` (`user_id`),
  CONSTRAINT `fk_spin_access_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- More than one spin a day has to be possible, so the one-row-per-day
-- unique key has to go. The daily allowance is counted in code instead.
ALTER TABLE `spin_results` DROP INDEX `uq_spin_day`;
ALTER TABLE `spin_results` ADD KEY `idx_spin_day` (`user_id`,`spin_date`);

-- -------------------------------------------------------------------
-- 5. MARKS EDITING — every admin change to a score or a quiz result is
--    written down. Nothing is edited silently.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `score_edits` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED DEFAULT NULL,
  `admin_name` VARCHAR(100) DEFAULT NULL,
  `kind`       ENUM('score','quiz') NOT NULL DEFAULT 'score',
  `ref_id`     BIGINT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `field`      VARCHAR(40)  NOT NULL,
  `old_value`  VARCHAR(120) DEFAULT NULL,
  `new_value`  VARCHAR(120) DEFAULT NULL,
  `reason`     VARCHAR(190) DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_se_ref` (`kind`,`ref_id`),
  KEY `idx_se_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `scores` ADD COLUMN `edited_by` INT UNSIGNED DEFAULT NULL AFTER `meta`;
ALTER TABLE `scores` ADD COLUMN `edited_at` DATETIME DEFAULT NULL AFTER `edited_by`;

-- -------------------------------------------------------------------
-- 6. MOBILE API — bearer tokens for the Android app.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `token_hash`   CHAR(64)     NOT NULL COMMENT 'sha256 of the bearer token',
  `device_name`  VARCHAR(120) DEFAULT NULL,
  `platform`     VARCHAR(30)  DEFAULT 'android',
  `app_version`  VARCHAR(20)  DEFAULT NULL,
  `ip_address`   VARCHAR(45)  DEFAULT NULL,
  `last_used_at` DATETIME     DEFAULT NULL,
  `expires_at`   DATETIME     DEFAULT NULL,
  `revoked`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token_hash`),
  KEY `idx_token_user` (`user_id`,`revoked`),
  CONSTRAINT `fk_token_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 7. CHESS — online play. Vs-computer and pass-and-play need no rows.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chess_games` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`         CHAR(6)      NOT NULL COMMENT 'share code for private games',
  `mode`         ENUM('public','private') NOT NULL DEFAULT 'public',
  `white_id`     INT UNSIGNED DEFAULT NULL,
  `black_id`     INT UNSIGNED DEFAULT NULL,
  `host_id`      INT UNSIGNED NOT NULL,
  `fen`          VARCHAR(120) NOT NULL DEFAULT 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
  `moves`        MEDIUMTEXT COMMENT 'space separated long algebraic, e.g. e2e4 e7e5',
  `ply`          INT UNSIGNED NOT NULL DEFAULT 0,
  `turn`         ENUM('w','b') NOT NULL DEFAULT 'w',
  `status`       ENUM('waiting','active','finished','aborted') NOT NULL DEFAULT 'waiting',
  `result`       ENUM('white','black','draw','none') NOT NULL DEFAULT 'none',
  `reason`       VARCHAR(40)  DEFAULT NULL,
  `entry_coins`  INT UNSIGNED NOT NULL DEFAULT 0,
  `reward_coins` INT UNSIGNED NOT NULL DEFAULT 0,
  `draw_offer`   ENUM('none','w','b') NOT NULL DEFAULT 'none',
  `white_seen`   DATETIME     DEFAULT NULL,
  `black_seen`   DATETIME     DEFAULT NULL,
  `last_move_at` DATETIME     DEFAULT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chess_code` (`code`),
  KEY `idx_chess_status` (`status`,`mode`),
  KEY `idx_chess_players` (`white_id`,`black_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register chess in the games catalogue so it appears on games.php,
-- gets a leaderboard and can be hidden from Admin -> Modules.
INSERT IGNORE INTO `games`
  (`slug`,`title`,`tagline`,`description`,`category`,`path`,`icon`,
   `entry_coins`,`reward_coins`,`score_order`,`is_active`,`is_featured`,`sort_order`)
VALUES
  ('chess','Chess','Play the computer, a friend beside you, or the world',
   'Full-rules chess with castling, en passant and promotion. Three computer strengths, pass-and-play on one device, and online matches against other players.',
   'Board','games/chess','&#9822;',0,80,'desc',1,1,4);

-- -------------------------------------------------------------------
-- 8. SETTINGS
-- -------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`sgroup`,`slabel`) VALUES
  ('quiz_level_mode',             'ladder', 'quiz',  'Level progression mode'),
  ('quiz_questions_per_level',    '10',     'quiz',  'Questions per level'),
  ('quiz_pass_percent',           '60',     'quiz',  'Pass mark (%)'),
  ('quiz_repeat_block_days',      '180',    'quiz',  'Do not repeat a question for (days)'),
  ('quiz_unlock_next_difficulty', '1',      'quiz',  'Clear a difficulty before the next unlocks'),
  ('quiz_level_retry_free',       '1',      'quiz',  'Failed levels can be retried free'),
  ('quiz_level_clear_bonus',      '50',     'quiz',  'Coins for clearing a level'),
  ('spin_access_mode',            'all',    'spin',  'Who can spin (all / assigned)'),
  ('spin_default_per_day',        '1',      'spin',  'Default spins per player per day'),
  ('chess_online_enabled',        '1',      'chess', 'Online chess enabled'),
  ('chess_reward_win',            '80',     'chess', 'Coins for winning an online game'),
  ('chess_reward_draw',           '30',     'chess', 'Coins for a draw'),
  ('chess_move_timeout',          '600',    'chess', 'Seconds before an idle online game is abandoned'),
  ('api_enabled',                 '1',      'api',   'Mobile API enabled'),
  ('api_token_days',              '60',     'api',   'App login token lifetime (days)'),
  ('app_min_version',             '1',      'api',   'Minimum supported app version code'),
  ('app_latest_version',          '1',      'api',   'Latest app version code'),
  ('app_update_url',              '',       'api',   'Where the app should send users to update');

V5SQL;
}

/** config/ folder-er migration file gulo, je order-e chalate hobe. */
function cpps_sql_files()
{
    return array(
        'upgrade-quiz-spin.sql'    => 'Quiz + Daily Spin er base table',
        'upgrade-quiz-proctor.sql' => 'Quiz proctor (quiz_violations)',
        'upgrade-v5.sql'           => 'Modules, Spin Access, Quiz levels, Chess, API',
        'upgrade-v6.sql'           => 'Visitors, Spin limits, Govt Exam',
    );
}

/**
 * Ekta SQL text-er sob statement chalay (file theke hok ba built-in copy theke).
 * Return: array(ran, already, failed, skipped, errors[])
 */
function cpps_run_sql($pdo, $sql)
{
    $res = array('ran' => 0, 'already' => 0, 'failed' => 0,
                 'skipped' => 0, 'errors' => array());

    if (!is_string($sql) || trim($sql) === '') {
        $res['errors'][] = 'SQL ta khali';
        $res['failed']   = 1;
        return $res;
    }

    foreach (cpps_split_sql($sql) as $stmt) {
        // hosting-e database-er nam alada, ar kono obosthatei drop noy
        if (preg_match('/^\s*(USE|CREATE\s+DATABASE|DROP\s+DATABASE|DROP\s+TABLE)\b/i', $stmt)) {
            $res['skipped']++;
            continue;
        }
        try {
            $pdo->exec($stmt);
            $res['ran']++;
        } catch (PDOException $e) {
            if (cpps_benign($e)) {
                $res['already']++;
            } else {
                $res['failed']++;
                if (count($res['errors']) < 4) {
                    $res['errors'][] = $e->getMessage()
                                     . '  —  ' . substr(preg_replace('/\s+/', ' ', $stmt), 0, 110);
                }
            }
        }
    }
    return $res;
}

/* ===================================================================
 * CHECKLIST — sesh e sob jaygamoto ache kina miliye dekha
 * =================================================================== */
function cpps_checklist()
{
    return array(
        'table'  => array(
            'settings', 'users', 'games', 'scores',
            'quiz_categories', 'quiz_questions', 'quiz_sessions',
            'spin_results', 'quiz_violations',
            'modules', 'spin_access', 'quiz_level_config', 'quiz_user_levels',
            'quiz_question_history', 'quiz_imports', 'score_edits', 'api_tokens',
            'chess_games',
            'visits', 'ip_geo', 'visit_daily', 'exam_notify',
        ),
        'column' => array(
            array('spin_access', 'cooldown_minutes'),
            array('spin_access', 'weekly_cap'),
            array('spin_access', 'monthly_cap'),
            array('exam_notify', 'user_id'),
            array('exam_notify', 'name'),
            array('exam_notify', 'phone'),
            array('exam_notify', 'exam_pref'),
            array('exam_notify', 'note'),
            array('exam_notify', 'ip_address'),
            array('exam_notify', 'created_at'),
            array('visits',      'visitor_key'),
            array('visits',      'country_code'),
            array('visits',      'geo_state'),
            array('modules',     'nav_visible'),
        ),
    );
}

/* ===================================================================
 * MIGRATION STEP GULO (v6 — SQL file er upor extra safety pass)
 * =================================================================== */
function cpps_steps()
{
    return array(

        /* ---------- 1. VISITOR ANALYTICS ---------- */
        array(
            'name' => 'visits — page view log',
            'test' => array('table', 'visits'),
            'sql'  => "CREATE TABLE IF NOT EXISTS `visits` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visitor_key`   CHAR(40)     NOT NULL,
  `user_id`       INT UNSIGNED DEFAULT NULL,
  `ip_address`    VARCHAR(45)  DEFAULT NULL,
  `page`          VARCHAR(190) NOT NULL DEFAULT '/',
  `page_title`    VARCHAR(160) DEFAULT NULL,
  `referrer`      VARCHAR(190) DEFAULT NULL,
  `referrer_host` VARCHAR(120) DEFAULT NULL,
  `country_code`  CHAR(2)      DEFAULT NULL,
  `country`       VARCHAR(80)  DEFAULT NULL,
  `region`        VARCHAR(80)  DEFAULT NULL,
  `city`          VARCHAR(80)  DEFAULT NULL,
  `device`        ENUM('desktop','mobile','tablet','bot','other') NOT NULL DEFAULT 'other',
  `browser`       VARCHAR(40)  DEFAULT NULL,
  `os`            VARCHAR(40)  DEFAULT NULL,
  `is_new`        TINYINT(1)   NOT NULL DEFAULT 0,
  `geo_state`     ENUM('pending','done','skipped') NOT NULL DEFAULT 'pending',
  `visit_date`    DATE         NOT NULL,
  `visit_hour`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_visit_date`    (`visit_date`),
  KEY `idx_visit_country` (`country_code`,`visit_date`),
  KEY `idx_visit_key`     (`visitor_key`,`visit_date`),
  KEY `idx_visit_page`    (`page`,`visit_date`),
  KEY `idx_visit_user`    (`user_id`),
  KEY `idx_visit_geo`     (`geo_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ),

        array(
            'name' => 'ip_geo — IP theke location cache',
            'test' => array('table', 'ip_geo'),
            'sql'  => "CREATE TABLE IF NOT EXISTS `ip_geo` (
  `ip`           VARCHAR(45)  NOT NULL,
  `country_code` CHAR(2)      DEFAULT NULL,
  `country`      VARCHAR(80)  DEFAULT NULL,
  `region`       VARCHAR(80)  DEFAULT NULL,
  `city`         VARCHAR(80)  DEFAULT NULL,
  `isp`          VARCHAR(120) DEFAULT NULL,
  `source`       VARCHAR(24)  NOT NULL DEFAULT 'api',
  `looked_up_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip`),
  KEY `idx_geo_country` (`country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ),

        array(
            'name' => 'visit_daily — dinik roll-up',
            'test' => array('table', 'visit_daily'),
            'sql'  => "CREATE TABLE IF NOT EXISTS `visit_daily` (
  `visit_date`   DATE         NOT NULL,
  `country_code` CHAR(2)      NOT NULL DEFAULT '--',
  `views`        INT UNSIGNED NOT NULL DEFAULT 0,
  `visitors`     INT UNSIGNED NOT NULL DEFAULT 0,
  `members`      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`visit_date`,`country_code`),
  KEY `idx_vd_country` (`country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ),

        /* ---------- 2. SPIN LIMIT ---------- */
        array(
            'name'    => 'spin_access.cooldown_minutes',
            'test'    => array('column', 'spin_access', 'cooldown_minutes'),
            'needs'   => 'spin_access',
            'sql'     => "ALTER TABLE `spin_access`
                          ADD COLUMN `cooldown_minutes` INT UNSIGNED DEFAULT NULL AFTER `bonus_spins`",
        ),
        array(
            'name'    => 'spin_access.weekly_cap',
            'test'    => array('column', 'spin_access', 'weekly_cap'),
            'needs'   => 'spin_access',
            'sql'     => "ALTER TABLE `spin_access`
                          ADD COLUMN `weekly_cap` INT UNSIGNED DEFAULT NULL AFTER `cooldown_minutes`",
        ),
        array(
            'name'    => 'spin_access.monthly_cap',
            'test'    => array('column', 'spin_access', 'monthly_cap'),
            'needs'   => 'spin_access',
            'sql'     => "ALTER TABLE `spin_access`
                          ADD COLUMN `monthly_cap` INT UNSIGNED DEFAULT NULL AFTER `weekly_cap`",
        ),
        array(
            'name'    => 'spin_results — cooldown-er jonno index',
            'test'    => array('index', 'spin_results', 'idx_spin_user_time'),
            'needs'   => 'spin_results',
            'sql'     => "ALTER TABLE `spin_results`
                          ADD KEY `idx_spin_user_time` (`user_id`,`created_at`)",
        ),
        array(
            // Purono "ek dine ekta-i spin" unique key ta thakle per-day
            // limit 1-er beshi kora jay na — 2nd insert duplicate hoye jay.
            'name'    => 'spin_results — purono uq_spin_day key sorano',
            'needs'   => 'spin_results',
            'drop_if' => array('spin_results', 'uq_spin_day'),
            'sql'     => "ALTER TABLE `spin_results` DROP INDEX `uq_spin_day`",
        ),
        array(
            'name'    => 'spin_results — idx_spin_day',
            'test'    => array('index', 'spin_results', 'idx_spin_day'),
            'needs'   => 'spin_results',
            'sql'     => "ALTER TABLE `spin_results` ADD KEY `idx_spin_day` (`user_id`,`spin_date`)",
        ),

        /* ---------- 3. GOVT EXAM ---------- */
        array(
            'name' => 'exam_notify — govt exam interest list',
            'test' => array('table', 'exam_notify'),
            'sql'  => "CREATE TABLE IF NOT EXISTS `exam_notify` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `name`       VARCHAR(100) DEFAULT NULL,
  `email`      VARCHAR(150) NOT NULL,
  `phone`      VARCHAR(24)  DEFAULT NULL,
  `exam_pref`  VARCHAR(80)  DEFAULT NULL,
  `note`       VARCHAR(190) DEFAULT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exam_email` (`email`),
  KEY `idx_exam_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ),

        /* ---------- 3b. exam_notify-er column gulo ----------
           CREATE TABLE IF NOT EXISTS purono, adha-toiri table ke thik kore
           na — table ta thakle seta chhue-o dekhe na. Tai protita column
           alada kore miliye dekha hoy. */
        array(
            'name'    => 'exam_notify.user_id',
            'test'    => array('column', 'exam_notify', 'user_id'),
            'needs'   => 'exam_notify',
            'sql'     => "ALTER TABLE `exam_notify` ADD COLUMN `user_id` INT UNSIGNED DEFAULT NULL AFTER `id`",
        ),
        array(
            'name'    => 'exam_notify.name',
            'test'    => array('column', 'exam_notify', 'name'),
            'needs'   => 'exam_notify',
            'sql'     => "ALTER TABLE `exam_notify` ADD COLUMN `name` VARCHAR(100) DEFAULT NULL AFTER `user_id`",
        ),
        array(
            'name'    => 'exam_notify.phone',
            'test'    => array('column', 'exam_notify', 'phone'),
            'needs'   => 'exam_notify',
            'sql'     => "ALTER TABLE `exam_notify` ADD COLUMN `phone` VARCHAR(24)  DEFAULT NULL AFTER `email`",
        ),
        array(
            'name'    => 'exam_notify.exam_pref',
            'test'    => array('column', 'exam_notify', 'exam_pref'),
            'needs'   => 'exam_notify',
            'sql'     => "ALTER TABLE `exam_notify` ADD COLUMN `exam_pref` VARCHAR(80)  DEFAULT NULL AFTER `phone`",
        ),
        array(
            'name'    => 'exam_notify.note',
            'test'    => array('column', 'exam_notify', 'note'),
            'needs'   => 'exam_notify',
            'sql'     => "ALTER TABLE `exam_notify` ADD COLUMN `note` VARCHAR(190) DEFAULT NULL AFTER `exam_pref`",
        ),
        array(
            'name'    => 'exam_notify.ip_address',
            'test'    => array('column', 'exam_notify', 'ip_address'),
            'needs'   => 'exam_notify',
            'sql'     => "ALTER TABLE `exam_notify` ADD COLUMN `ip_address` VARCHAR(45)  DEFAULT NULL AFTER `note`",
        ),
        array(
            'name'    => 'exam_notify.created_at',
            'test'    => array('column', 'exam_notify', 'created_at'),
            'needs'   => 'exam_notify',
            'sql'     => "ALTER TABLE `exam_notify` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `ip_address`",
        ),
        array(
            'name'  => 'modules — govt_exam + analytics row',
            'needs' => 'modules',
            'sql'   => "INSERT IGNORE INTO `modules`
                          (`mkey`,`label`,`icon`,`blurb`,`kind`,`is_active`,`nav_visible`,`sort_order`)
                        VALUES
                          ('govt_exam','Govt Exam','&#127891;',
                           'Government competitive exam — coming-soon mode.','feature',1,1,25),
                          ('analytics','Visitor analytics','&#128200;',
                           'Page-view logging with country, region and city.','feature',1,0,80)",
        ),

        /* ---------- 4. SETTINGS ---------- */
        array(
            'name'  => 'settings — spin limit + analytics + exam',
            'needs' => 'settings',
            'sql'   => "INSERT IGNORE INTO `settings` (`skey`,`svalue`,`sgroup`,`slabel`) VALUES
  ('spin_default_per_day',    '1',    'spin',      'Spins per player per day'),
  ('spin_cooldown_minutes',   '0',    'spin',      'Minutes between two spins (0 = none)'),
  ('spin_weekly_cap',         '0',    'spin',      'Spins per player per week (0 = no cap)'),
  ('spin_monthly_cap',        '0',    'spin',      'Spins per player per month (0 = no cap)'),
  ('spin_limit_note',         '',     'spin',      'Extra line shown on the spin page'),
  ('analytics_enabled',       '1',    'analytics', 'Log visitors'),
  ('analytics_geo_mode',      'auto', 'analytics', 'off | header | auto'),
  ('analytics_ignore_admin',  '1',    'analytics', 'Skip views made by a signed-in admin'),
  ('analytics_ignore_bots',   '1',    'analytics', 'Skip crawlers'),
  ('analytics_retention_days','365',  'analytics', 'Keep raw page views for (days)'),
  ('analytics_salt',          '',     'analytics', 'Random salt for the visitor hash'),
  ('govt_exam_enabled',       '1',    'exam',      'Show Govt Exam in the navigation'),
  ('govt_exam_status',        'soon', 'exam',      'soon | live'),
  ('govt_exam_label',         'Govt Exam', 'exam', 'Navigation label'),
  ('govt_exam_launch',        '',     'exam',      'Launch date (YYYY-MM-DD)'),
  ('govt_exam_tagline',
   'WBCS, SSC, Rail, Bank & PSC practice — built the Asviora way.', 'exam', 'Landing tagline'),
  ('govt_exam_blurb',
   'Full-length mock tests, previous-year papers, sectional practice and a level ladder that actually tracks what you are weak at. Join the list and you will be the first in.',
   'exam', 'Landing paragraph')",
        ),
    );
}

/* ===================================================================
 * RUN
 * =================================================================== */
$sets   = cpps_creds($ROOT . '/config/config.php');
$why    = array();
$pdo    = null;
$dbName = null;
$report = array();
$before = null;
$after  = null;

if ($sets) {
    list($pdo, $dbName) = cpps_connect($sets, $why);
}

$fileReport = array();
$missing    = array('table' => array(), 'column' => array());

if ($pdo && $run) {
    try {
        $st = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ?');
        $st->execute(array('schema_version'));
        $before = $st->fetchColumn();
    } catch (Exception $e) {
        $before = null;
    }

    // -------------------------------------------------------------
    // PHASE 1 — config/ folder-er sob upgrade file, order onujayi.
    //
    // schema_version dekhi na icche kore: live-e version 6 lekha chilo
    // othocho v5-er `modules` / `spin_access` table-i toiri hoyni, tai
    // auto-migration oi gulo ar kokhonoi banato na. Sob file idempotent,
    // tai ja age thekei ache seta sudhu "agei chilo" hoye jabe.
    // -------------------------------------------------------------
    foreach (cpps_sql_files() as $file => $what) {
        $path  = $ROOT . '/config/' . $file;
        $where = 'config/ folder theke';

        if (is_file($path)) {
            $sql = @file_get_contents($path);
        } else {
            // file ta server-e nei — bhitore rakha copy ta chalai
            $sql   = cpps_embedded_sql($file);
            $where = 'ei file-er bhitorer built-in copy theke';
        }

        if ($sql === null || $sql === false) {
            $fileReport[$file] = array('what' => $what, 'state' => 'missing',
                                       'where' => '', 'res' => null);
            continue;
        }

        $res = cpps_run_sql($pdo, $sql);
        $fileReport[$file] = array(
            'what'  => $what,
            'where' => $where,
            'state' => $res['failed'] > 0 ? 'fail' : ($res['ran'] > 0 ? 'done' : 'ok'),
            'res'   => $res,
        );
    }

    // -------------------------------------------------------------
    // PHASE 2 — v6-er target step gulo, PHASE 1 kichu miss korle
    // -------------------------------------------------------------
    foreach (cpps_steps() as $step) {
        $name = $step['name'];

        // je table-er upor kaj, seta na thakle skip
        if (isset($step['needs']) && !cpps_has_table($pdo, $step['needs'])) {
            $report[] = array($name, 'skip',
                              'table `' . $step['needs'] . '` nei — age agher migration chalan');
            continue;
        }

        // ekta index/key sorano — na thakle kichu korar nei
        if (isset($step['drop_if'])) {
            $d = $step['drop_if'];
            if (!cpps_has_index($pdo, $d[0], $d[1])) {
                $report[] = array($name, 'ok', 'agei sorano chilo');
                continue;
            }
        }

        // already ache kina
        if (isset($step['test'])) {
            $t = $step['test'];
            $exists = false;
            if ($t[0] === 'table')  { $exists = cpps_has_table($pdo, $t[1]); }
            if ($t[0] === 'column') { $exists = cpps_has_column($pdo, $t[1], $t[2]); }
            if ($t[0] === 'index')  { $exists = cpps_has_index($pdo, $t[1], $t[2]); }
            if ($exists) {
                $report[] = array($name, 'ok', 'agei chilo');
                continue;
            }
        }

        try {
            $pdo->exec($step['sql']);
            $report[] = array($name, 'done', 'toiri holo');
        } catch (PDOException $e) {
            if (cpps_benign($e)) {
                $report[] = array($name, 'ok', 'agei chilo');
            } else {
                $report[] = array($name, 'fail', $e->getMessage());
            }
        }
    }

    // analytics salt — ekbar-i toiri hobe
    try {
        $st = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ?');
        $st->execute(array('analytics_salt'));
        $salt = (string) $st->fetchColumn();
        if ($salt === '') {
            $new = bin2hex(function_exists('random_bytes')
                           ? random_bytes(16) : pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand()));
            $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')
                ->execute(array('analytics_salt', $new));
            $report[] = array('analytics_salt', 'done', 'notun salt toiri holo');
        } else {
            $report[] = array('analytics_salt', 'ok', 'agei chilo');
        }
    } catch (Exception $e) {
        $report[] = array('analytics_salt', 'fail', $e->getMessage());
    }

    // -------------------------------------------------------------
    // PHASE 3 — checklist. Sotti sotti sob ache kina miliye dekhi.
    // -------------------------------------------------------------
    $check = cpps_checklist();
    foreach ($check['table'] as $t) {
        if (!cpps_has_table($pdo, $t)) {
            $missing['table'][] = $t;
        }
    }
    foreach ($check['column'] as $c) {
        if (cpps_has_table($pdo, $c[0]) && !cpps_has_column($pdo, $c[0], $c[1])) {
            $missing['column'][] = $c[0] . '.' . $c[1];
        }
    }

    // -------------------------------------------------------------
    // PHASE 4 — version tokhon-i bump kori jokhon checklist puropuri
    // pass koreche. Age skip-take-o "thik ache" dhore version tule
    // deya hoto — sei karonei live-e version 6 chilo othocho table
    // gulo chilo na, ar migration ar kokhono cholto na.
    // -------------------------------------------------------------
    $failed = false;
    foreach ($report as $r) {
        if ($r[1] === 'fail') { $failed = true; }
    }
    foreach ($fileReport as $fr) {
        if ($fr['state'] === 'fail') { $failed = true; }
    }
    $incomplete = (count($missing['table']) + count($missing['column'])) > 0;

    if (!$failed && !$incomplete) {
        try {
            $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')
                ->execute(array('schema_version', (string) $TARGET_VERSION));
            $after = $TARGET_VERSION;
            $report[] = array('schema_version', 'done', 'ekhon ' . $TARGET_VERSION);
        } catch (Exception $e) {
            $report[] = array('schema_version', 'fail', $e->getMessage());
        }
    } elseif ($incomplete) {
        // Version ke 5 e namiye rakhi, jate site-er nijer auto-migration
        // (includes/migrate.php) porer request-eo abar cheshta kore.
        try {
            $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')
                ->execute(array('schema_version', '3'));
            $after = 3;
        } catch (Exception $e) {
            // thak, choto bishoy
        }
        $report[] = array('schema_version', 'skip',
                          'kichu table/column ekhono nei — version 3 e rakha holo '
                        . 'jate site nijei abar migration cholate pare');
    } else {
        $report[] = array('schema_version', 'skip',
                          'upore kichu fail koreche — version bump kora holo na');
    }
}

/* ---------- ekhon ki obostha ---------- */
$state = array();
if ($pdo) {
    $state = array(
        'Database'                     => $dbName,
        'visits table'                 => cpps_has_table($pdo, 'visits') ? 'ache' : 'NEI',
        'ip_geo table'                 => cpps_has_table($pdo, 'ip_geo') ? 'ache' : 'NEI',
        'visit_daily table'            => cpps_has_table($pdo, 'visit_daily') ? 'ache' : 'NEI',
        'exam_notify table'            => cpps_has_table($pdo, 'exam_notify') ? 'ache' : 'NEI',
        'spin_access.cooldown_minutes' => cpps_has_column($pdo, 'spin_access', 'cooldown_minutes') ? 'ache' : 'NEI',
        'spin_access.weekly_cap'       => cpps_has_column($pdo, 'spin_access', 'weekly_cap') ? 'ache' : 'NEI',
        'spin_access.monthly_cap'      => cpps_has_column($pdo, 'spin_access', 'monthly_cap') ? 'ache' : 'NEI',
    );
    try {
        $st = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ?');
        $st->execute(array('schema_version'));
        $state['schema_version'] = (string) $st->fetchColumn();
    } catch (Exception $e) {
        $state['schema_version'] = '?';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CPPS Migration — Asviora Games v6</title>
<style>
  :root{--bg:#0b0c0e;--card:#13161b;--line:#242a33;--txt:#e9eefc;--dim:#8b94a4;
        --green:#8fce3f;--gold:#d9a63c;--red:#ff7a7a}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--txt);
       font:15px/1.65 system-ui,-apple-system,"Segoe UI",sans-serif;padding:28px 16px}
  .wrap{max-width:900px;margin:0 auto}
  h1{font-size:1.5rem;margin:0 0 4px;color:var(--green)}
  h2{font-size:1.05rem;margin:26px 0 10px}
  p.sub{color:var(--dim);margin:0 0 22px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;
        padding:18px 20px;margin-bottom:18px}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}
  th{color:var(--dim);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.05em}
  code{background:#0e1116;border:1px solid var(--line);border-radius:6px;padding:1px 6px;font-size:13px}
  .pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:800;
        text-transform:uppercase;letter-spacing:.05em}
  .p-done{background:rgba(143,206,63,.18);color:var(--green)}
  .p-ok{background:rgba(139,148,164,.18);color:var(--dim)}
  .p-skip{background:rgba(217,166,60,.18);color:var(--gold)}
  .p-fail{background:rgba(255,122,122,.18);color:var(--red)}
  .p-missing{background:rgba(255,122,122,.18);color:var(--red)}
  .btn{display:inline-block;padding:11px 20px;border-radius:10px;text-decoration:none;
       font-weight:800;background:linear-gradient(135deg,#8fce3f,#6aa521);color:#0b0c0e}
  .btn.ghost{background:transparent;border:1px solid var(--line);color:var(--txt)}
  .warn{background:rgba(217,166,60,.1);border:1px solid rgba(217,166,60,.4);
        border-radius:12px;padding:14px 16px;color:var(--gold);margin-bottom:18px}
  .bad{background:rgba(255,122,122,.1);border:1px solid rgba(255,122,122,.4);
       border-radius:12px;padding:14px 16px;color:var(--red);margin-bottom:18px}
  .good{background:rgba(143,206,63,.1);border:1px solid rgba(143,206,63,.4);
        border-radius:12px;padding:14px 16px;color:var(--green);margin-bottom:18px}
</style>
</head>
<body>
<div class="wrap">

  <h1>CPPS Migration — Asviora Games v6</h1>
  <p class="sub">Visitor analytics &middot; Spin limits &middot; Govt Exam module</p>

  <?php if (!$keyOk): ?>
    <div class="bad">
      <strong>Key lagbe.</strong> URL-e <code>?key=<?= htmlspecialchars($MIGRATION_KEY) ?></code>
      jog korun. Udahoron:<br>
      <code><?= htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
              . '://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'asviora.in')
              . (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/cppsmigration.php')
              . '?key=' . $MIGRATION_KEY) ?></code>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2 style="margin-top:0">Server</h2>
    <table>
      <?php foreach ($diag as $k => $v): ?>
        <tr><th style="width:230px"><?= htmlspecialchars($k) ?></th>
            <td><?= htmlspecialchars((string) $v) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php if ($keyOk): ?>

    <div class="card">
      <h2 style="margin-top:0">Database</h2>
      <?php if (!$sets): ?>
        <div class="bad"><code>config/config.php</code> theke DB credentials pawa gelo na.
          File ta ki thik jaygay ache?</div>
      <?php elseif (!$pdo): ?>
        <div class="bad">Kono set diyei connect kora gelo na:
          <ul><?php foreach ($why as $w): ?><li><?= htmlspecialchars($w) ?></li><?php endforeach; ?></ul>
        </div>
      <?php else: ?>
        <table>
          <?php foreach ($state as $k => $v): ?>
            <tr><th style="width:260px"><?= htmlspecialchars($k) ?></th>
                <td style="color:<?= $v === 'NEI' ? 'var(--red)' : 'inherit' ?>">
                  <?= htmlspecialchars((string) $v) ?></td></tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

    <?php if ($pdo && !$run): ?>
      <div class="warn">
        <strong>Chalanor age:</strong> database-er ekta backup niye nin
        (hPanel &rarr; phpMyAdmin &rarr; Export). Migration ta idempotent —
        dubar chalale kichu nosto hoy na — kintu backup thaka bhalo.
      </div>
      <p>
        <a class="btn" href="?key=<?= urlencode($MIGRATION_KEY) ?>&amp;run=1">
          Migration chalao &rarr;</a>
      </p>
    <?php endif; ?>

    <?php if ($report): ?>
      <?php
      $nFail = 0; $nDone = 0;
      foreach ($report as $r) {
          if ($r[1] === 'fail') { $nFail++; }
          if ($r[1] === 'done') { $nDone++; }
      }
      ?>
      <?php
      $nMissing = count($missing['table']) + count($missing['column']);
      $fileFail = 0;
      foreach ($fileReport as $fr) { if ($fr['state'] === 'fail') { $fileFail++; } }
      ?>

      <?php if ($nFail === 0 && $fileFail === 0 && $nMissing === 0): ?>
        <div class="good"><strong>Sob thik ache — database puropuri v6.</strong>
          Checklist-er protita table ar column jaygamoto ache.
          Ekhon admin panel-e <code>Modules</code>, <code>Visitors</code>,
          <code>Spin Access</code>, <code>Govt Exam</code> ar
          <code>Daily Spin &rarr; Spin limits</code> — sob dekhte paben.
          <br><br><strong>Ei file ta ekhon server theke delete kore din.</strong>
        </div>
      <?php elseif ($nMissing > 0): ?>
        <div class="bad"><strong><?= (int) $nMissing ?> ta table/column ekhono nei.</strong>
          Niche "Checklist" dekhun. schema_version icche kore 3 e rakha holo, jate
          site nijei porer request-e abar migration cholate pare. Ei page ta abar
          chalate paren — ja hoye geche seta abar hobe na.</div>
      <?php else: ?>
        <div class="bad"><strong><?= (int) ($nFail + $fileFail) ?> ta step fail koreche.</strong>
          Niche error ta dekhun. Thik kore abar chalate paren — jetuku hoye geche
          seta abar hobe na.</div>
      <?php endif; ?>

      <?php if ($fileReport): ?>
        <div class="card">
          <h2 style="margin-top:0">Migration file gulo</h2>
          <p style="color:var(--dim);margin:0 0 12px">
            schema_version ja-i boluk na keno, protita file ek-ek kore chalano hoy —
            ja age thekei ache seta apna theke skip hoye jay.</p>
          <table>
            <thead><tr><th>File</th><th style="width:90px">Result</th>
                       <th>Statement</th></tr></thead>
            <tbody>
              <?php foreach ($fileReport as $file => $fr): ?>
                <tr>
                  <td><code><?= htmlspecialchars($file) ?></code>
                    <div style="color:var(--dim);font-size:12px">
                      <?= htmlspecialchars($fr['what']) ?></div>
                    <?php if (!empty($fr['where'])): ?>
                      <div style="color:var(--dim);font-size:11px">
                        <?= htmlspecialchars($fr['where']) ?></div>
                    <?php endif; ?></td>
                  <td><span class="pill p-<?= htmlspecialchars($fr['state']) ?>">
                    <?= htmlspecialchars($fr['state']) ?></span></td>
                  <td style="color:var(--dim);font-size:12.5px">
                    <?php if ($fr['res'] === null): ?>
                      file ta config/ folder-e nei
                    <?php else: ?>
                      <?= (int) $fr['res']['ran'] ?> statement chollo,
                      <?= (int) $fr['res']['already'] ?> agei chilo,
                      <?= (int) $fr['res']['skipped'] ?> skip
                      <?php if ($fr['res']['failed'] > 0): ?>
                        , <strong style="color:var(--red)">
                          <?= (int) $fr['res']['failed'] ?> fail</strong>
                        <?php foreach ($fr['res']['errors'] as $err): ?>
                          <div style="color:var(--red);font:11.5px/1.6 ui-monospace,monospace;
                                      margin-top:6px"><?= htmlspecialchars($err) ?></div>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <div class="card">
        <h2 style="margin-top:0">Checklist</h2>
        <?php if ($nMissing === 0): ?>
          <p style="color:var(--green);margin:0">
            Protita expected table ar column ache. &#10003;</p>
        <?php else: ?>
          <?php if ($missing['table']): ?>
            <p style="margin:0 0 6px"><strong style="color:var(--red)">Table nei:</strong></p>
            <p style="font:12.5px/1.8 ui-monospace,monospace;color:var(--red);margin:0 0 14px">
              <?= htmlspecialchars(implode(', ', $missing['table'])) ?></p>
          <?php endif; ?>
          <?php if ($missing['column']): ?>
            <p style="margin:0 0 6px"><strong style="color:var(--red)">Column nei:</strong></p>
            <p style="font:12.5px/1.8 ui-monospace,monospace;color:var(--red);margin:0">
              <?= htmlspecialchars(implode(', ', $missing['column'])) ?></p>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2 style="margin-top:0">Ki holo</h2>
        <table>
          <thead><tr><th>Step</th><th style="width:90px">Result</th><th>Details</th></tr></thead>
          <tbody>
            <?php foreach ($report as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r[0]) ?></td>
                <td><span class="pill p-<?= htmlspecialchars($r[1]) ?>">
                  <?= htmlspecialchars($r[1]) ?></span></td>
                <td style="color:var(--dim)"><?= htmlspecialchars($r[2]) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p style="color:var(--dim);margin:14px 0 0">
          schema_version: <code><?= htmlspecialchars((string) ($before === null ? '?' : $before)) ?></code>
          &rarr; <code><?= htmlspecialchars((string) ($after === null ? ($before === null ? '?' : $before) : $after)) ?></code>
        </p>
      </div>

      <p>
        <a class="btn ghost" href="admin/visitors.php">Admin &rarr; Visitors</a>
        <a class="btn ghost" href="admin/spin.php">Admin &rarr; Daily Spin</a>
        <a class="btn ghost" href="admin/govt-exam.php">Admin &rarr; Govt Exam</a>
      </p>
    <?php endif; ?>

  <?php endif; ?>

  <p style="color:var(--dim);font-size:13px;margin-top:30px">
    Asviora Games &middot; cppsmigration.php &middot; target schema v<?= (int) $TARGET_VERSION ?>
  </p>
</div>
</body>
</html>
