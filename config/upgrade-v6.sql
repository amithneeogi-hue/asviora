-- ===================================================================
-- ASVIORA GAMES — schema upgrade v6
-- -------------------------------------------------------------------
-- Adds three things:
--
--   1. Visitor analytics  — every page view is logged with the day it
--      happened and, where it can be worked out, the country / region /
--      city it came from. A small `ip_geo` cache means one IP is only
--      ever looked up once.
--
--   2. Spin limits        — the daily spin gains a real, admin-editable
--      limit: spins per day, a cooldown between spins, and optional
--      weekly / monthly caps. Per-player overrides live on `spin_access`.
--
--   3. Govt Competitive Exam — a "coming soon" module with its own
--      landing page and an interest list players can join.
--
-- Every statement is idempotent: running this file twice changes
-- nothing the second time.
-- ===================================================================

-- -------------------------------------------------------------------
-- 1. VISITOR ANALYTICS
-- -------------------------------------------------------------------

-- One row per page view. Kept narrow on purpose — this is the table
-- that grows fastest on a busy site, and Admin -> Visitors can purge
-- anything older than the retention window.
CREATE TABLE IF NOT EXISTS `visits` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visitor_key`  CHAR(40)     NOT NULL COMMENT 'hash of ip + user agent + salt — identifies a device, stores no PII',
  `user_id`      INT UNSIGNED DEFAULT NULL,
  `ip_address`   VARCHAR(45)  DEFAULT NULL,
  `page`         VARCHAR(190) NOT NULL DEFAULT '/',
  `page_title`   VARCHAR(160) DEFAULT NULL,
  `referrer`     VARCHAR(190) DEFAULT NULL,
  `referrer_host` VARCHAR(120) DEFAULT NULL,
  `country_code` CHAR(2)      DEFAULT NULL,
  `country`      VARCHAR(80)  DEFAULT NULL,
  `region`       VARCHAR(80)  DEFAULT NULL,
  `city`         VARCHAR(80)  DEFAULT NULL,
  `device`       ENUM('desktop','mobile','tablet','bot','other') NOT NULL DEFAULT 'other',
  `browser`      VARCHAR(40)  DEFAULT NULL,
  `os`           VARCHAR(40)  DEFAULT NULL,
  `is_new`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = first ever view from this device',
  `geo_state`    ENUM('pending','done','skipped') NOT NULL DEFAULT 'pending',
  `visit_date`   DATE         NOT NULL,
  `visit_hour`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_visit_date`    (`visit_date`),
  KEY `idx_visit_country` (`country_code`,`visit_date`),
  KEY `idx_visit_key`     (`visitor_key`,`visit_date`),
  KEY `idx_visit_page`    (`page`,`visit_date`),
  KEY `idx_visit_user`    (`user_id`),
  KEY `idx_visit_geo`     (`geo_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IP -> location cache. One lookup per address, ever.
CREATE TABLE IF NOT EXISTS `ip_geo` (
  `ip`           VARCHAR(45)  NOT NULL,
  `country_code` CHAR(2)      DEFAULT NULL,
  `country`      VARCHAR(80)  DEFAULT NULL,
  `region`       VARCHAR(80)  DEFAULT NULL,
  `city`         VARCHAR(80)  DEFAULT NULL,
  `isp`          VARCHAR(120) DEFAULT NULL,
  `source`       VARCHAR(24)  NOT NULL DEFAULT 'api' COMMENT 'header | api | manual | local',
  `looked_up_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip`),
  KEY `idx_geo_country` (`country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rolled-up totals so the year / month views stay fast once `visits`
-- has been purged. Written by the same tracker that writes `visits`.
CREATE TABLE IF NOT EXISTS `visit_daily` (
  `visit_date`   DATE         NOT NULL,
  `country_code` CHAR(2)      NOT NULL DEFAULT '--',
  `views`        INT UNSIGNED NOT NULL DEFAULT 0,
  `visitors`     INT UNSIGNED NOT NULL DEFAULT 0,
  `members`      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`visit_date`,`country_code`),
  KEY `idx_vd_country` (`country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 2. SPIN LIMITS
--    Per-player overrides. NULL in any of these means "use the site
--    default from Admin -> Daily Spin".
-- -------------------------------------------------------------------
ALTER TABLE `spin_access`
  ADD COLUMN `cooldown_minutes` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = site default' AFTER `bonus_spins`;

ALTER TABLE `spin_access`
  ADD COLUMN `weekly_cap` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = site default, 0 = no cap' AFTER `cooldown_minutes`;

ALTER TABLE `spin_access`
  ADD COLUMN `monthly_cap` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = site default, 0 = no cap' AFTER `weekly_cap`;

-- The API needs "when was the last spin", to the second, for cooldowns.
ALTER TABLE `spin_results`
  ADD KEY `idx_spin_user_time` (`user_id`,`created_at`);

-- v5 already dropped this, but an install that skipped straight here would
-- still carry the old "exactly one spin a day" unique key, which makes any
-- allowance above 1 fail on the second insert. Dropping it twice is benign.
ALTER TABLE `spin_results` DROP INDEX `uq_spin_day`;
ALTER TABLE `spin_results` ADD KEY `idx_spin_day` (`user_id`,`spin_date`);

-- -------------------------------------------------------------------
-- 3. GOVT COMPETITIVE EXAM — interest list
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exam_notify` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `name`       VARCHAR(100) DEFAULT NULL,
  `email`      VARCHAR(150) NOT NULL,
  `phone`      VARCHAR(24)  DEFAULT NULL,
  `exam_pref`  VARCHAR(80)  DEFAULT NULL COMMENT 'WBCS, SSC, Rail, Bank, ...',
  `note`       VARCHAR(190) DEFAULT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exam_email` (`email`),
  KEY `idx_exam_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The module switch, so Admin -> Modules can hide the whole thing.
INSERT IGNORE INTO `modules` (`mkey`,`label`,`icon`,`blurb`,`kind`,`is_active`,`nav_visible`,`sort_order`) VALUES
  ('govt_exam', 'Govt Exam', '&#127891;',
   'Government competitive exam practice — currently in coming-soon mode.', 'feature', 1, 1, 25),
  ('analytics', 'Visitor analytics', '&#128200;',
   'Page-view logging with country, region and city.', 'feature', 1, 0, 80);

-- -------------------------------------------------------------------
-- 4. SETTINGS
-- -------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`sgroup`,`slabel`) VALUES
  -- spin limits
  ('spin_default_per_day',    '1',    'spin',      'Spins per player per day'),
  ('spin_cooldown_minutes',   '0',    'spin',      'Minutes between two spins (0 = none)'),
  ('spin_weekly_cap',         '0',    'spin',      'Spins per player per week (0 = no cap)'),
  ('spin_monthly_cap',        '0',    'spin',      'Spins per player per month (0 = no cap)'),
  ('spin_limit_note',         '',     'spin',      'Extra line shown on the spin page'),
  -- analytics
  ('analytics_enabled',       '1',    'analytics', 'Log visitors'),
  ('analytics_geo_mode',      'auto', 'analytics', 'off | header | auto (header, then IP lookup)'),
  ('analytics_ignore_admin',  '1',    'analytics', 'Do not log views made by a signed-in admin'),
  ('analytics_ignore_bots',   '1',    'analytics', 'Do not log crawlers'),
  ('analytics_retention_days','365',  'analytics', 'Delete raw page views older than this'),
  ('analytics_salt',          '',     'analytics', 'Random salt for the visitor hash — set once'),
  -- govt exam
  ('govt_exam_enabled',       '1',    'exam',      'Show Govt Exam in the navigation'),
  ('govt_exam_status',        'soon', 'exam',      'soon | live'),
  ('govt_exam_label',         'Govt Exam', 'exam', 'Navigation label'),
  ('govt_exam_launch',        '',     'exam',      'Launch date (YYYY-MM-DD), blank = no countdown'),
  ('govt_exam_tagline',       'WBCS, SSC, Rail, Bank & PSC practice — built the Asviora way.', 'exam', 'Landing page tagline'),
  ('govt_exam_blurb',
   'Full-length mock tests, previous-year papers, sectional practice and a level ladder that actually tracks what you are weak at. Join the list and you will be the first in.',
   'exam', 'Landing page paragraph');
