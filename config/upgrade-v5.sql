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
