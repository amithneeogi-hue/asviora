-- ===================================================================
-- ASVIORA GAMES  |  LIVE HOSTING IMPORT FILE
-- -------------------------------------------------------------------
-- Use this file (NOT database.sql, NOT install.php) on a live server.
-- No CREATE DATABASE / USE statement, so phpMyAdmin imports it into
-- whichever database you have already selected in the left sidebar.
--
-- WARNING: this DROPs and recreates every table. Only run it on a
-- brand-new, empty database.
-- ===================================================================



-- -------------------------------------------------------------------
-- Admins
-- -------------------------------------------------------------------
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL,
  `email`         VARCHAR(150) NOT NULL,
  `username`      VARCHAR(60)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('super','manager','editor') NOT NULL DEFAULT 'manager',
  `avatar`        VARCHAR(255) DEFAULT NULL,
  `last_login`    DATETIME     DEFAULT NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_username` (`username`),
  UNIQUE KEY `uq_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Players / Users
-- -------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL,
  `username`      VARCHAR(60)  NOT NULL,
  `email`         VARCHAR(150) NOT NULL,
  `phone`         VARCHAR(20)  DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `avatar`        VARCHAR(255) DEFAULT NULL,
  `coins`         INT          NOT NULL DEFAULT 500,
  `xp`            INT          NOT NULL DEFAULT 0,
  `level`         INT          NOT NULL DEFAULT 1,
  `referral_code` VARCHAR(20)  DEFAULT NULL,
  `referred_by`   INT UNSIGNED DEFAULT NULL,
  `bio`           VARCHAR(255) DEFAULT NULL,
  `status`        ENUM('active','suspended','banned') NOT NULL DEFAULT 'active',
  `last_login`    DATETIME     DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_username` (`username`),
  UNIQUE KEY `uq_user_email` (`email`),
  UNIQUE KEY `uq_user_refcode` (`referral_code`),
  KEY `idx_user_status` (`status`),
  KEY `idx_user_xp` (`xp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Games catalogue (managed from admin panel)
-- -------------------------------------------------------------------
DROP TABLE IF EXISTS `games`;
CREATE TABLE `games` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`          VARCHAR(60)  NOT NULL,
  `title`         VARCHAR(120) NOT NULL,
  `tagline`       VARCHAR(180) DEFAULT NULL,
  `description`   TEXT,
  `category`      VARCHAR(60)  NOT NULL DEFAULT 'Puzzle',
  `path`          VARCHAR(200) NOT NULL COMMENT 'relative folder of the HTML5 game',
  `thumbnail`     VARCHAR(255) DEFAULT NULL,
  `icon`          VARCHAR(20)  DEFAULT NULL COMMENT 'emoji / short glyph',
  `entry_coins`   INT          NOT NULL DEFAULT 0,
  `reward_coins`  INT          NOT NULL DEFAULT 50,
  `score_order`   ENUM('desc','asc') NOT NULL DEFAULT 'desc'
                  COMMENT 'desc = higher is better, asc = lower (time) is better',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `is_featured`   TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`    INT          NOT NULL DEFAULT 0,
  `plays`         INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_game_slug` (`slug`),
  KEY `idx_game_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Scores / match history
-- -------------------------------------------------------------------
DROP TABLE IF EXISTS `scores`;
CREATE TABLE `scores` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `game_id`      INT UNSIGNED NOT NULL,
  `score`        INT          NOT NULL DEFAULT 0,
  `time_taken`   INT          NOT NULL DEFAULT 0 COMMENT 'seconds',
  `moves`        INT          NOT NULL DEFAULT 0,
  `difficulty`   VARCHAR(20)  DEFAULT NULL,
  `coins_earned` INT          NOT NULL DEFAULT 0,
  `result`       ENUM('win','loss','incomplete') NOT NULL DEFAULT 'win',
  `meta`         VARCHAR(255) DEFAULT NULL,
  `ip_address`   VARCHAR(45)  DEFAULT NULL,
  `played_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_score_user` (`user_id`),
  KEY `idx_score_game` (`game_id`),
  KEY `idx_score_board` (`game_id`,`score`),
  CONSTRAINT `fk_score_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_score_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Coin ledger (virtual currency only - no real money)
-- -------------------------------------------------------------------
DROP TABLE IF EXISTS `coin_transactions`;
CREATE TABLE `coin_transactions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `amount`     INT          NOT NULL COMMENT 'positive = credit, negative = debit',
  `balance`    INT          NOT NULL DEFAULT 0,
  `type`       ENUM('signup','reward','entry_fee','bonus','referral','admin_adjust','daily','quiz','spin') NOT NULL,
  `note`       VARCHAR(200) DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ct_user` (`user_id`),
  CONSTRAINT `fk_ct_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Quiz contest
-- -------------------------------------------------------------------
DROP TABLE IF EXISTS `quiz_sessions`;
DROP TABLE IF EXISTS `quiz_questions`;
DROP TABLE IF EXISTS `quiz_categories`;

CREATE TABLE `quiz_categories` (
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

CREATE TABLE `quiz_questions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `difficulty`  ENUM('easy','mid','hard') NOT NULL DEFAULT 'easy',
  `question`    TEXT         NOT NULL,
  `option_a`    VARCHAR(255) NOT NULL,
  `option_b`    VARCHAR(255) NOT NULL,
  `option_c`    VARCHAR(255) DEFAULT NULL,
  `option_d`    VARCHAR(255) DEFAULT NULL,
  `correct`     TINYINT      NOT NULL DEFAULT 1 COMMENT '1=A 2=B 3=C 4=D',
  `explanation` VARCHAR(500) DEFAULT NULL,
  `image`       VARCHAR(255) DEFAULT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `times_asked` INT UNSIGNED NOT NULL DEFAULT 0,
  `times_right` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_qq_level` (`difficulty`,`is_active`),
  KEY `idx_qq_cat`   (`category_id`),
  CONSTRAINT `fk_qq_cat` FOREIGN KEY (`category_id`)
    REFERENCES `quiz_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quiz_sessions` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `category_id`  INT UNSIGNED DEFAULT NULL,
  `difficulty`   ENUM('easy','mid','hard') NOT NULL DEFAULT 'easy',
  `total`        INT          NOT NULL DEFAULT 0,
  `answered`     INT          NOT NULL DEFAULT 0,
  `correct`      INT          NOT NULL DEFAULT 0,
  `coins_earned` INT          NOT NULL DEFAULT 0,
  `xp_earned`    INT          NOT NULL DEFAULT 0,
  `time_taken`   INT          NOT NULL DEFAULT 0,
  `payload`      MEDIUMTEXT,
  `status`       ENUM('active','finished','abandoned','voided') NOT NULL DEFAULT 'active',
  `strikes`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `void_reason`  VARCHAR(40)  DEFAULT NULL,
  `started_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at`  DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_qs_user`  (`user_id`,`status`),
  KEY `idx_qs_board` (`difficulty`,`correct`),
  CONSTRAINT `fk_qs_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Quiz proctoring — one row per anti-cheat detection
-- -------------------------------------------------------------------
DROP TABLE IF EXISTS `quiz_violations`;
CREATE TABLE `quiz_violations` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `kind`       VARCHAR(40) NOT NULL,
  `detail`     VARCHAR(190) DEFAULT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_qv_session` (`session_id`),
  KEY `idx_qv_user` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------------
-- Daily spin wheel
-- -------------------------------------------------------------------
DROP TABLE IF EXISTS `spin_results`;
CREATE TABLE `spin_results` (
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
-- Blog
-- -------------------------------------------------------------------
DROP TABLE IF EXISTS `blog_categories`;
CREATE TABLE `blog_categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(80) NOT NULL,
  `slug`       VARCHAR(80) NOT NULL,
  `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bcat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `blog_posts`;
CREATE TABLE `blog_posts` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`         VARCHAR(200) NOT NULL,
  `slug`          VARCHAR(220) NOT NULL,
  `excerpt`       VARCHAR(400) DEFAULT NULL,
  `content`       LONGTEXT,
  `cover_image`   VARCHAR(255) DEFAULT NULL,
  `category_id`   INT UNSIGNED DEFAULT NULL,
  `author_id`     INT UNSIGNED DEFAULT NULL COMMENT 'admins.id',
  `tags`          VARCHAR(255) DEFAULT NULL,
  `meta_title`    VARCHAR(200) DEFAULT NULL,
  `meta_desc`     VARCHAR(300) DEFAULT NULL,
  `status`        ENUM('draft','published') NOT NULL DEFAULT 'draft',
  `is_featured`   TINYINT(1)   NOT NULL DEFAULT 0,
  `views`         INT UNSIGNED NOT NULL DEFAULT 0,
  `published_at`  DATETIME     DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_post_slug` (`slug`),
  KEY `idx_post_status` (`status`),
  KEY `idx_post_cat` (`category_id`),
  CONSTRAINT `fk_post_cat` FOREIGN KEY (`category_id`) REFERENCES `blog_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Contact / support messages
-- -------------------------------------------------------------------
DROP TABLE IF EXISTS `contact_messages`;
CREATE TABLE `contact_messages` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `email`      VARCHAR(150) NOT NULL,
  `subject`    VARCHAR(200) DEFAULT NULL,
  `message`    TEXT         NOT NULL,
  `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cm_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Newsletter
-- -------------------------------------------------------------------
DROP TABLE IF EXISTS `subscribers`;
CREATE TABLE `subscribers` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(150) NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sub_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Site settings (editable from admin panel)
-- -------------------------------------------------------------------
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `skey`   VARCHAR(60)  NOT NULL,
  `svalue` TEXT,
  `sgroup` VARCHAR(40)  NOT NULL DEFAULT 'general',
  `slabel` VARCHAR(120) DEFAULT NULL,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- SEED DATA
-- ===================================================================

-- Default admin -> username: admin   password: Admin@123
INSERT INTO `admins` (`name`,`email`,`username`,`password_hash`,`role`) VALUES
('Site Administrator','admin@asviora.com','admin',
 '$2y$10$etAw8lJwK5g8Hj.mg5f63.cReXh864z.yYAMfXLb9iiLeHq1yKVRW','super');

INSERT INTO `settings` (`skey`,`svalue`,`sgroup`,`slabel`) VALUES
('site_name','Asviora Games','general','Site Name'),
('site_tagline','Where Skill Wins','general','Tagline'),
('site_description','Asviora Games is a skill-based gaming platform where players compete in puzzles, memory challenges and 3D brain teasers to climb the leaderboard.','general','Meta Description'),
('contact_email','support@asviora.com','contact','Support Email'),
('contact_phone','+91 00000 00000','contact','Phone'),
('contact_address','Kolkata, West Bengal, India','contact','Address'),
('social_facebook','https://www.facebook.com/asvioragames','social','Facebook URL'),
('social_instagram','https://www.instagram.com/asvioragames','social','Instagram URL'),
('social_youtube','','social','YouTube URL'),
('social_whatsapp','','social','WhatsApp Link'),
('social_twitter','','social','X / Twitter URL'),
('signup_bonus','500','coins','Signup Bonus Coins'),
('daily_bonus','50','coins','Daily Login Bonus'),
('referral_bonus','100','coins','Referral Bonus'),
('site_logo','','general','Logo file'),
('schema_version','3','general','Database schema version'),
('quiz_enabled','1','quiz','Quiz contest enabled'),
('quiz_questions_per_round','10','quiz','Questions per round'),
('quiz_time_easy','30','quiz','Easy — seconds per question'),
('quiz_time_mid','20','quiz','Mid — seconds per question'),
('quiz_time_hard','15','quiz','Hard — seconds per question'),
('quiz_reward_easy','10','quiz','Easy — coins per correct answer'),
('quiz_reward_mid','25','quiz','Mid — coins per correct answer'),
('quiz_reward_hard','50','quiz','Hard — coins per correct answer'),
('quiz_perfect_bonus','100','quiz','Bonus coins for a perfect round'),
('quiz_strike_limit','1','quiz','Quiz warnings before a round is voided'),
('quiz_require_fullscreen','1','quiz','Force fullscreen exam mode in the quiz'),
('quiz_daily_rounds','5','quiz','Rewarded rounds per player per day'),
('spin_enabled','1','spin','Daily spin enabled'),
('spin_segments','','spin','Wheel segments (JSON)');

INSERT INTO `quiz_categories` (`id`,`name`,`slug`,`icon`,`sort_order`) VALUES
(1,'General Knowledge','general-knowledge','&#127758;',1),
(2,'Gaming & Esports','gaming-esports','&#127918;',2),
(3,'Science & Tech','science-tech','&#128300;',3),
(4,'Sports','sports','&#9917;',4);

INSERT INTO `quiz_questions`
 (`category_id`,`difficulty`,`question`,`option_a`,`option_b`,`option_c`,`option_d`,`correct`,`explanation`) VALUES
(1,'easy','Which planet is known as the Red Planet?','Venus','Mars','Jupiter','Mercury',2,'Iron oxide dust on the surface gives Mars its reddish colour.'),
(1,'easy','How many continents are there on Earth?','5','6','7','8',3,'Asia, Africa, North America, South America, Antarctica, Europe and Australia.'),
(1,'easy','What is the largest ocean on Earth?','Atlantic','Indian','Arctic','Pacific',4,'The Pacific covers about a third of the planet''s surface.'),
(1,'easy','Which gas do plants absorb from the air?','Oxygen','Carbon dioxide','Nitrogen','Hydrogen',2,'Plants take in CO2 and release oxygen during photosynthesis.'),
(1,'easy','What is the capital of India?','Mumbai','Kolkata','New Delhi','Chennai',3,'New Delhi has been the capital since 1911.'),
(1,'easy','How many sides does a hexagon have?','5','6','7','8',2,'Hexa means six.'),
(1,'easy','Which is the longest river in the world?','Amazon','Nile','Yangtze','Ganges',2,'The Nile runs roughly 6,650 km through north-east Africa.'),
(1,'easy','What colour do you get by mixing blue and yellow?','Green','Purple','Orange','Brown',1,'Blue and yellow are the classic primary pair for green.'),
(1,'easy','How many minutes are there in a full day?','1200','1440','1600','2400',2,'24 hours x 60 minutes = 1,440.'),
(1,'easy','Which animal is known as the King of the Jungle?','Tiger','Elephant','Lion','Leopard',3,'The lion carries the title, despite living mostly on savannah.'),
(2,'easy','In chess, how many squares are on the board?','36','49','64','81',3,'An 8x8 grid gives 64 squares.'),
(2,'easy','How many players are on a football (soccer) team on the pitch?','9','10','11','12',3,'Eleven per side, including the goalkeeper.'),
(2,'mid','Which company developed the game engine "Unreal Engine"?','Valve','Epic Games','Ubisoft','Crytek',2,'Epic Games has developed Unreal Engine since 1998.'),
(2,'mid','What does "FPS" stand for in gaming?','Fast Play Style','First Person Shooter','Frames Per Second','Both B and C',4,'FPS commonly means both First Person Shooter and Frames Per Second.'),
(2,'mid','Which puzzle game was created by Alexey Pajitnov in 1984?','Tetris','Pac-Man','Snake','Minesweeper',1,'Pajitnov built Tetris while at the Soviet Academy of Sciences.'),
(2,'hard','How many possible permutations does a standard 3x3 Rubik''s Cube have?','About 43 quintillion','About 43 million','About 43 billion','About 43 trillion',1,'Exactly 43,252,003,274,489,856,000 states.'),
(3,'mid','What does "CPU" stand for?','Central Process Unit','Central Processing Unit','Computer Personal Unit','Control Processing Unit',2,'Central Processing Unit — the main processor.'),
(3,'mid','Which element has the chemical symbol "Au"?','Silver','Aluminium','Gold','Argon',3,'From the Latin aurum.'),
(3,'mid','How many bits are in one byte?','4','8','16','32',2,'One byte is eight bits.'),
(3,'mid','What is the speed of light in a vacuum, approximately?','300,000 km/s','150,000 km/s','30,000 km/s','3,000 km/s',1,'Roughly 299,792 km per second.'),
(3,'hard','Who is credited with formulating the theory of general relativity?','Isaac Newton','Niels Bohr','Albert Einstein','Max Planck',3,'Einstein published general relativity in 1915.'),
(3,'hard','In computing, what does "SQL" stand for?','Simple Query Language','Structured Question Language','Structured Query Language','Sequential Query Logic',3,'Structured Query Language.'),
(3,'hard','What is the time complexity of binary search on a sorted array?','O(n)','O(log n)','O(n log n)','O(1)',2,'It halves the search space each step.'),
(4,'mid','How many rings are on the Olympic flag?','4','5','6','7',2,'Five interlocking rings, one per inhabited continent grouping.'),
(4,'mid','In cricket, how many players are in one team on the field?','9','10','11','12',3,'Eleven players per side.'),
(4,'hard','Which country has won the most FIFA World Cup titles?','Germany','Italy','Argentina','Brazil',4,'Brazil has five titles.'),
(4,'hard','In tennis, what is the term for a score of 40-40?','Advantage','Deuce','Break','Love',2,'At 40-40 the game goes to deuce.'),
(1,'mid','Which is the smallest country in the world by area?','Monaco','Nauru','Vatican City','San Marino',3,'Vatican City covers about 0.44 square kilometres.'),
(1,'mid','In which year did World War II end?','1943','1944','1945','1946',3,'The war ended in 1945.'),
(1,'hard','Which is the deepest point in the world''s oceans?','Puerto Rico Trench','Mariana Trench','Java Trench','Tonga Trench',2,'The Challenger Deep in the Mariana Trench, about 10,935 m.'),
(1,'hard','Who wrote the play "Hamlet"?','Charles Dickens','William Shakespeare','John Milton','Christopher Marlowe',2,'Shakespeare wrote Hamlet around 1600.');

INSERT INTO `blog_categories` (`name`,`slug`) VALUES
('Game Guides','game-guides'),
('Announcements','announcements'),
('Strategy & Tips','strategy-tips'),
('Community','community');

INSERT INTO `games`
 (`slug`,`title`,`tagline`,`description`,`category`,`path`,`thumbnail`,`icon`,`entry_coins`,`reward_coins`,`score_order`,`is_active`,`is_featured`,`sort_order`) VALUES
('cube3d','3D Cube Solver','Twist, learn, conquer the cube',
 'A fully interactive 3D Rubik''s Cube built with WebGL. Scramble it, solve it manually with drag gestures or keyboard moves, or watch the step-by-step auto-solver replay the solution. Fewer moves and faster times score higher.',
 'Brain / 3D','games/cube3d','cube3d.png','&#129513;',0,100,'desc',1,1,1),
('memory','Memory Match','Sharpen your recall speed',
 'Flip the tiles and pair them up before the clock runs out. Four grid sizes, combo streak bonuses and a move counter that rewards focus over luck.',
 'Memory','games/memory','memory.png','&#127183;',0,60,'desc',1,1,2),
('sudoku','Sudoku','Pure logic, zero luck',
 'Classic 9x9 Sudoku with four difficulty levels, pencil-mark notes, mistake tracking, hints and an instant validity checker. Solve clean and fast for the top of the board.',
 'Logic','games/sudoku','sudoku.png','&#128290;',0,80,'desc',1,1,3);

INSERT INTO `blog_posts`
 (`title`,`slug`,`excerpt`,`content`,`cover_image`,`category_id`,`author_id`,`tags`,`status`,`is_featured`,`published_at`) VALUES
('Welcome to Asviora Games','welcome-to-asviora-games',
 'Asviora Games is live. Here is what we built, why skill-based play matters, and what is coming next.',
 '<p>Welcome to <strong>Asviora Games</strong> &mdash; a home for players who would rather win with their brain than their luck.</p><h2>Why skill-based?</h2><p>Every game on this platform is decided by what you do, not by what a random number generator decides for you. Faster recall, tighter logic, fewer wasted moves &mdash; that is the whole scoreboard.</p><h2>What is live today</h2><ul><li><strong>3D Cube Solver</strong> &ndash; a real WebGL Rubik''s cube with an auto-solver you can learn from.</li><li><strong>Memory Match</strong> &ndash; four grid sizes and combo streak scoring.</li><li><strong>Sudoku</strong> &ndash; four difficulties, pencil notes and hints.</li></ul><h2>What is next</h2><p>Quiz tournaments, chess, weekly championships and a referral programme. Follow us on Facebook and Instagram so you do not miss a launch.</p><p>See you on the leaderboard.</p>',
 'welcome.png',2,1,'launch,announcement','published',1,NOW()),
('5 Habits That Cut Your Sudoku Time in Half','cut-your-sudoku-time-in-half',
 'Scanning, pencil marks, and knowing when to stop guessing. The small habits that separate a 20-minute solve from an 8-minute one.',
 '<p>Most players lose time to the same handful of habits. Fix these five and your solve time drops fast.</p><h2>1. Scan rows and columns, not cells</h2><p>Beginners hunt cell by cell. Strong solvers scan a full band of three rows for a single digit and place every certain instance of it before moving on.</p><h2>2. Use pencil marks, but only when stuck</h2><p>Filling every candidate into every cell from move one is slow and noisy. Solve the easy placements first, then pencil in only the region that has gone quiet.</p><h2>3. Look for the last empty cell</h2><p>Any row, column or box with eight filled cells is a free placement. Sweep for these after every few moves.</p><h2>4. Never guess on a clean grid</h2><p>A guess that turns out wrong costs you every move after it. If nothing is placeable, you have missed a scan &mdash; go back and re-scan the digit you looked at least.</p><h2>5. Finish the digit you started</h2><p>Jumping between digits resets your mental map. Pick a digit, exhaust it across the whole grid, then switch.</p><p>Put these into practice on our <a href="games.php">Sudoku board</a> and watch the timer drop.</p>',
 'sudoku-tips.png',3,1,'sudoku,tips,strategy','published',0,NOW()),
('How to Solve a Rubik''s Cube: The Layer Method','how-to-solve-a-rubiks-cube-layer-method',
 'The beginner method broken into seven stages, with the exact move sequences you need to memorise.',
 '<p>The layer-by-layer method is how almost every solver starts. It needs about seven short algorithms &mdash; here is the map.</p><h2>Notation first</h2><p>Each letter is a quarter turn of a face: <code>R</code> right, <code>L</code> left, <code>U</code> up, <code>D</code> down, <code>F</code> front, <code>B</code> back. A prime mark (<code>R''</code>) means turn it the other way. A <code>2</code> (<code>R2</code>) means a half turn.</p><h2>The seven stages</h2><ol><li><strong>White cross</strong> &ndash; intuitive, no algorithm needed.</li><li><strong>White corners</strong> &ndash; repeat <code>R'' D'' R D</code> until the corner drops in.</li><li><strong>Middle layer edges</strong> &ndash; <code>U R U'' R'' U'' F'' U F</code>.</li><li><strong>Yellow cross</strong> &ndash; <code>F R U R'' U'' F''</code>.</li><li><strong>Yellow edges</strong> &ndash; <code>R U R'' U R U2 R'' U</code>.</li><li><strong>Corner positions</strong> &ndash; <code>U R U'' L'' U R'' U'' L</code>.</li><li><strong>Corner orientation</strong> &ndash; <code>R'' D'' R D</code> repeated per corner.</li></ol><p>Our <a href="games.php">3D Cube Solver</a> has an auto-solve replay so you can watch each stage happen slowly and copy it.</p>',
 'rubiks-guide.png',1,1,'rubiks-cube,3d,guide','published',0,NOW());
