-- ===================================================================
-- AEGIS ESPORTS  |  Upgrade: Quiz Contest + Daily Spin Wheel
-- -------------------------------------------------------------------
-- Run this ONCE on an existing install:
--   mysql -u root -p asviora_games < config/upgrade-quiz-spin.sql
-- or paste it into phpMyAdmin > asviora_games > SQL.
--
-- Safe to re-run: every statement is IF NOT EXISTS / idempotent.
-- ===================================================================

USE `asviora_games`;

-- -------------------------------------------------------------------
-- Quiz categories
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(80)  NOT NULL,
  `slug`       VARCHAR(80)  NOT NULL,
  `icon`       VARCHAR(20)  DEFAULT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order` INT          NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_qcat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Quiz questions
--   difficulty: easy | mid | hard   (the three levels from the brief)
--   correct:    1-4, pointing at option_a..option_d
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_questions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `difficulty`  ENUM('easy','mid','hard') NOT NULL DEFAULT 'easy',
  `question`    TEXT         NOT NULL,
  `option_a`    VARCHAR(255) NOT NULL,
  `option_b`    VARCHAR(255) NOT NULL,
  `option_c`    VARCHAR(255) DEFAULT NULL,
  `option_d`    VARCHAR(255) DEFAULT NULL,
  `correct`     TINYINT      NOT NULL DEFAULT 1,
  `explanation` VARCHAR(500) DEFAULT NULL,
  `image`       VARCHAR(255) DEFAULT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `times_asked` INT UNSIGNED NOT NULL DEFAULT 0,
  `times_right` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_qq_level`  (`difficulty`,`is_active`),
  KEY `idx_qq_cat`    (`category_id`),
  CONSTRAINT `fk_qq_cat` FOREIGN KEY (`category_id`)
    REFERENCES `quiz_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Quiz rounds (one row per attempt)
--   The question order + per-question state lives in `payload` (JSON),
--   server-side only, so the answers never reach the browser early.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_sessions` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `category_id`  INT UNSIGNED DEFAULT NULL,
  `difficulty`   ENUM('easy','mid','hard') NOT NULL DEFAULT 'easy',
  `total`        INT          NOT NULL DEFAULT 0,
  `answered`     INT          NOT NULL DEFAULT 0,
  `correct`      INT          NOT NULL DEFAULT 0,
  `coins_earned` INT          NOT NULL DEFAULT 0,
  `xp_earned`    INT          NOT NULL DEFAULT 0,
  `time_taken`   INT          NOT NULL DEFAULT 0 COMMENT 'seconds',
  `payload`      MEDIUMTEXT   COMMENT 'JSON: question ids + per-question result',
  `status`       ENUM('active','finished','abandoned') NOT NULL DEFAULT 'active',
  `started_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at`  DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_qs_user`  (`user_id`,`status`),
  KEY `idx_qs_board` (`difficulty`,`correct`),
  CONSTRAINT `fk_qs_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Daily spin results  (one row per player per day = the daily lock)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `spin_results` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `spin_date`  DATE         NOT NULL,
  `segment`    INT          NOT NULL DEFAULT 0,
  `coins`      INT          NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_spin_day` (`user_id`,`spin_date`),
  CONSTRAINT `fk_spin_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Coin ledger: allow the two new sources
-- -------------------------------------------------------------------
ALTER TABLE `coin_transactions`
  MODIFY `type` ENUM('signup','reward','entry_fee','bonus','referral',
                     'admin_adjust','daily','quiz','spin') NOT NULL;

-- -------------------------------------------------------------------
-- New settings
-- -------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`sgroup`,`slabel`) VALUES
('site_logo','','general','Logo file'),
('contact_email','support@aegisesports.com','contact','Support Email'),
('social_facebook','','social','Facebook URL'),
('social_instagram','','social','Instagram URL'),

('quiz_enabled','1','quiz','Quiz contest enabled'),
('quiz_questions_per_round','10','quiz','Questions per round'),
('quiz_time_easy','30','quiz','Easy — seconds per question'),
('quiz_time_mid','20','quiz','Mid — seconds per question'),
('quiz_time_hard','15','quiz','Hard — seconds per question'),
('quiz_reward_easy','10','quiz','Easy — coins per correct answer'),
('quiz_reward_mid','25','quiz','Mid — coins per correct answer'),
('quiz_reward_hard','50','quiz','Hard — coins per correct answer'),
('quiz_perfect_bonus','100','quiz','Bonus coins for a perfect round'),
('quiz_daily_rounds','5','quiz','Rewarded rounds per player per day'),

('spin_enabled','1','spin','Daily spin enabled'),
('spin_segments','','spin','Wheel segments (JSON)');

-- -------------------------------------------------------------------
-- Seed: quiz categories
-- -------------------------------------------------------------------
-- explicit ids so the seeded questions below always attach to the right topic
INSERT IGNORE INTO `quiz_categories` (`id`,`name`,`slug`,`icon`,`sort_order`) VALUES
(1,'General Knowledge','general-knowledge','&#127758;',1),
(2,'Gaming & Esports','gaming-esports','&#127918;',2),
(3,'Science & Tech','science-tech','&#128300;',3),
(4,'Sports','sports','&#9917;',4);

-- -------------------------------------------------------------------
-- Seed: a starter question bank (so the quiz works immediately).
-- Add / edit the rest from Admin > Contests > Quiz Questions.
--
-- Explicit ids + INSERT IGNORE make this safe to re-run: a second pass
-- collides on the primary key and is skipped, rather than duplicating
-- the bank. (A self-referencing "WHERE NOT EXISTS (SELECT .. FROM
-- quiz_questions)" is not an option — MySQL error 1093 forbids reading
-- the table you are inserting into.)
-- -------------------------------------------------------------------
INSERT IGNORE INTO `quiz_questions`
 (`id`,`category_id`,`difficulty`,`question`,`option_a`,`option_b`,`option_c`,`option_d`,`correct`,`explanation`) VALUES
(1,1,'easy','Which planet is known as the Red Planet?','Venus','Mars','Jupiter','Mercury',2,'Iron oxide dust on the surface gives Mars its reddish colour.'),
(2,1,'easy','How many continents are there on Earth?','5','6','7','8',3,'Asia, Africa, North America, South America, Antarctica, Europe and Australia.'),
(3,1,'easy','What is the largest ocean on Earth?','Atlantic','Indian','Arctic','Pacific',4,'The Pacific covers about a third of the planet''s surface.'),
(4,1,'easy','Which gas do plants absorb from the air?','Oxygen','Carbon dioxide','Nitrogen','Hydrogen',2,'Plants take in CO2 and release oxygen during photosynthesis.'),
(5,1,'easy','What is the capital of India?','Mumbai','Kolkata','New Delhi','Chennai',3,'New Delhi has been the capital since 1911.'),
(6,1,'easy','How many sides does a hexagon have?','5','6','7','8',2,'Hexa means six.'),
(7,1,'easy','Which is the longest river in the world?','Amazon','Nile','Yangtze','Ganges',2,'The Nile runs roughly 6,650 km through north-east Africa.'),
(8,1,'easy','What colour do you get by mixing blue and yellow?','Green','Purple','Orange','Brown',1,'Blue and yellow are the classic primary pair for green.'),
(9,1,'easy','How many minutes are there in a full day?','1200','1440','1600','2400',2,'24 hours x 60 minutes = 1,440.'),
(10,1,'easy','Which animal is known as the King of the Jungle?','Tiger','Elephant','Lion','Leopard',3,'The lion carries the title, despite living mostly on savannah.'),
(11,2,'easy','In chess, how many squares are on the board?','36','49','64','81',3,'An 8x8 grid gives 64 squares.'),
(12,2,'easy','How many players are on a football (soccer) team on the pitch?','9','10','11','12',3,'Eleven per side, including the goalkeeper.'),
(13,2,'mid','Which company developed the game engine "Unreal Engine"?','Valve','Epic Games','Ubisoft','Crytek',2,'Epic Games has developed Unreal Engine since 1998.'),
(14,2,'mid','What does "FPS" stand for in gaming?','Fast Play Style','First Person Shooter','Frames Per Second','Both B and C',4,'FPS commonly means both First Person Shooter and Frames Per Second.'),
(15,2,'mid','Which puzzle game was created by Alexey Pajitnov in 1984?','Tetris','Pac-Man','Snake','Minesweeper',1,'Pajitnov built Tetris while at the Soviet Academy of Sciences.'),
(16,2,'hard','How many possible permutations does a standard 3x3 Rubik''s Cube have?','About 43 quintillion','About 43 million','About 43 billion','About 43 trillion',1,'Exactly 43,252,003,274,489,856,000 states.'),
(17,3,'mid','What does "CPU" stand for?','Central Process Unit','Central Processing Unit','Computer Personal Unit','Control Processing Unit',2,'Central Processing Unit — the main processor.'),
(18,3,'mid','Which element has the chemical symbol "Au"?','Silver','Aluminium','Gold','Argon',3,'From the Latin aurum.'),
(19,3,'mid','How many bits are in one byte?','4','8','16','32',2,'One byte is eight bits.'),
(20,3,'mid','What is the speed of light in a vacuum, approximately?','300,000 km/s','150,000 km/s','30,000 km/s','3,000 km/s',1,'Roughly 299,792 km per second.'),
(21,3,'hard','Who is credited with formulating the theory of general relativity?','Isaac Newton','Niels Bohr','Albert Einstein','Max Planck',3,'Einstein published general relativity in 1915.'),
(22,3,'hard','In computing, what does "SQL" stand for?','Simple Query Language','Structured Question Language','Structured Query Language','Sequential Query Logic',3,'Structured Query Language.'),
(23,3,'hard','What is the time complexity of binary search on a sorted array?','O(n)','O(log n)','O(n log n)','O(1)',2,'It halves the search space each step.'),
(24,4,'mid','How many rings are on the Olympic flag?','4','5','6','7',2,'Five interlocking rings, one per inhabited continent grouping.'),
(25,4,'mid','In cricket, how many players are in one team on the field?','9','10','11','12',3,'Eleven players per side.'),
(26,4,'hard','Which country has won the most FIFA World Cup titles?','Germany','Italy','Argentina','Brazil',4,'Brazil has five titles.'),
(27,4,'hard','In tennis, what is the term for a score of 40-40?','Advantage','Deuce','Break','Love',2,'At 40-40 the game goes to deuce.'),
(28,1,'mid','Which is the smallest country in the world by area?','Monaco','Nauru','Vatican City','San Marino',3,'Vatican City covers about 0.44 square kilometres.'),
(29,1,'mid','In which year did World War II end?','1943','1944','1945','1946',3,'The war ended in 1945.'),
(30,1,'hard','Which is the deepest point in the world''s oceans?','Puerto Rico Trench','Mariana Trench','Java Trench','Tonga Trench',2,'The Challenger Deep in the Mariana Trench, about 10,935 m.'),
(31,1,'hard','Who wrote the play "Hamlet"?','Charles Dickens','William Shakespeare','John Milton','Christopher Marlowe',2,'Shakespeare wrote Hamlet around 1600.');

-- Done.
