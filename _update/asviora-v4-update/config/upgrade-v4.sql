-- ===================================================================
-- ASVIORA GAMES  |  Schema v4
-- -------------------------------------------------------------------
-- Adds:
--   * per-question marks, per-question time limit and an import code
--   * the quiz LEVEL system (Easy -> Mid -> Hard unlock chain)
--   * a per-player "seen question" ledger, so a question is not repeated
--     inside the window the admin sets (default 6 months)
--   * admin-assigned access to the Daily Spin
--   * chess: matches vs the computer and staked matches between players
--
-- Applied automatically by includes/migrate.php. Every statement is safe
-- to re-run, so a half-applied migration can simply be repeated.
-- ===================================================================

-- -------------------------------------------------------------------
-- Questions: marks, own clock, import identity
-- -------------------------------------------------------------------
ALTER TABLE `quiz_questions`
  ADD COLUMN `qcode` VARCHAR(40) DEFAULT NULL COMMENT 'Question ID from the bulk sheet' AFTER `id`;

ALTER TABLE `quiz_questions`
  ADD COLUMN `marks` SMALLINT UNSIGNED NOT NULL DEFAULT 10 AFTER `correct`;

ALTER TABLE `quiz_questions`
  ADD COLUMN `time_limit` SMALLINT UNSIGNED DEFAULT NULL
  COMMENT 'seconds; NULL = use the level default' AFTER `marks`;

ALTER TABLE `quiz_questions`
  ADD UNIQUE KEY `uq_qq_code` (`qcode`);

-- -------------------------------------------------------------------
-- Sessions: which level was played and how many marks it was worth
-- -------------------------------------------------------------------
ALTER TABLE `quiz_sessions`
  ADD COLUMN `level_no` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `difficulty`;

ALTER TABLE `quiz_sessions`
  ADD COLUMN `marks_total` INT NOT NULL DEFAULT 0 AFTER `correct`;

ALTER TABLE `quiz_sessions`
  ADD COLUMN `marks_scored` INT NOT NULL DEFAULT 0 AFTER `marks_total`;

ALTER TABLE `quiz_sessions`
  ADD COLUMN `passed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `marks_scored`;

ALTER TABLE `quiz_sessions`
  ADD KEY `idx_qs_level` (`user_id`,`difficulty`,`level_no`);

-- -------------------------------------------------------------------
-- Level progress — one row per player per level attempted
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_level_progress` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Seen questions — the anti-repeat ledger
--
-- A question a player has already been served is not served again until
-- `quiz_repeat_months` have passed (admin setting, default 6). The row is
-- written the moment the question is dealt, not when it is answered, so a
-- round that is abandoned still burns the question.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_seen` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Daily spin: access decided by the admin
--   default  -> follow the site-wide spin_access_mode setting
--   allowed  -> always allowed, even in "assigned only" mode
--   blocked  -> never allowed
-- -------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN `spin_access` ENUM('default','allowed','blocked')
  NOT NULL DEFAULT 'default' AFTER `status`;

-- -------------------------------------------------------------------
-- Chess
--
-- One row per match. `mode` separates a practice game against the engine
-- from a staked match between two signed-up players. The board state and
-- both clocks live here: the browser is never trusted with either.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chess_matches` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`         VARCHAR(12) NOT NULL COMMENT 'invite code used in the share link',
  `mode`         ENUM('ai','pvp') NOT NULL DEFAULT 'pvp',
  `host_id`      INT UNSIGNED NOT NULL,
  `guest_id`     INT UNSIGNED DEFAULT NULL,
  `white_id`     INT UNSIGNED DEFAULT NULL,
  `black_id`     INT UNSIGNED DEFAULT NULL,
  `stake`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'coins put in by EACH player',
  `pot`          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'coins held by the match',
  `minutes`      SMALLINT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'clock per player',
  `increment`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'seconds added per move',
  `ai_level`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1 easy 2 medium 3 hard',
  `fen`          VARCHAR(120) NOT NULL
                 DEFAULT 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
  `moves`        MEDIUMTEXT COMMENT 'space separated long algebraic, e2e4 style',
  `turn`         ENUM('w','b') NOT NULL DEFAULT 'w',
  `white_ms`     INT NOT NULL DEFAULT 600000,
  `black_ms`     INT NOT NULL DEFAULT 600000,
  `draw_offer`   INT UNSIGNED DEFAULT NULL COMMENT 'user_id who offered',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Settings the new screens read
-- -------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`sgroup`,`slabel`) VALUES
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
('chess_ai_reward',         '25',  'chess', 'Coins for beating the computer');
