-- ===================================================================
-- ASVIORA GAMES — schema upgrade v7
-- -------------------------------------------------------------------
-- Adds:
--
--   1. Payments        — Razorpay + Cashfree, every order in `payments`.
--   2. Game plans      — admin marks any game (or Quiz / Spin / Chess)
--                        Free or Paid, with a price and a number of days.
--   3. Subscriptions   — who has paid for what, and until when.
--   4. Registration fee — a one-time fee (switch + amount in settings);
--                        a paid fee is simply a `payments` row with
--                        purpose = 'registration'.
--   5. SEO manager     — per-page title / description / keywords /
--                        OG image / robots overrides in `seo_pages`.
--
-- No ALTER TABLE on existing tables, no foreign keys — every statement
-- is CREATE TABLE IF NOT EXISTS or INSERT IGNORE, so running the file
-- twice changes nothing the second time.
-- ===================================================================

-- -------------------------------------------------------------------
-- 1. PAYMENTS
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `purpose`       VARCHAR(20)  NOT NULL COMMENT 'registration | subscription',
  `item_key`      VARCHAR(60)  DEFAULT NULL COMMENT 'game slug / quiz / spin / chess',
  `plan_days`     INT UNSIGNED DEFAULT NULL,
  `amount_paise`  INT UNSIGNED NOT NULL DEFAULT 0,
  `currency`      CHAR(3)      NOT NULL DEFAULT 'INR',
  `gateway`       VARCHAR(20)  NOT NULL COMMENT 'razorpay | cashfree | manual',
  `order_ref`     VARCHAR(40)  NOT NULL COMMENT 'our own order id',
  `gw_order_id`   VARCHAR(100) DEFAULT NULL,
  `gw_payment_id` VARCHAR(100) DEFAULT NULL,
  `status`        VARCHAR(20)  NOT NULL DEFAULT 'created'
                  COMMENT 'created | paid | failed | cancelled | refunded',
  `source`        VARCHAR(10)  NOT NULL DEFAULT 'web' COMMENT 'web | app | admin',
  `note`          VARCHAR(255) DEFAULT NULL,
  `meta`          TEXT,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at`       DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pay_ref` (`order_ref`),
  KEY `idx_pay_user` (`user_id`),
  KEY `idx_pay_status` (`status`),
  KEY `idx_pay_gworder` (`gw_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 2. GAME PLANS — one row per playable item that has a plan
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `game_plans` (
  `item_key`      VARCHAR(60)  NOT NULL COMMENT 'game slug, or quiz / spin / chess',
  `is_paid`       TINYINT(1)   NOT NULL DEFAULT 0,
  `price_paise`   INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_days` INT UNSIGNED NOT NULL DEFAULT 30,
  `label`         VARCHAR(80)  DEFAULT NULL COMMENT 'e.g. Monthly pass',
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 3. SUBSCRIPTIONS
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `item_key`    VARCHAR(60)  NOT NULL,
  `starts_at`   DATETIME     NOT NULL,
  `expires_at`  DATETIME     NOT NULL,
  `payment_id`  INT UNSIGNED DEFAULT NULL,
  `source`      VARCHAR(10)  NOT NULL DEFAULT 'payment' COMMENT 'payment | admin',
  `status`      VARCHAR(10)  NOT NULL DEFAULT 'active'  COMMENT 'active | revoked',
  `note`        VARCHAR(190) DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sub_user_item` (`user_id`, `item_key`),
  KEY `idx_sub_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 5. SEO MANAGER — overrides per page
-- -------------------------------------------------------------------
-- page_key examples: home, games, about, contact, leaderboard, blog,
-- quiz, spin, chess, govt-exam, game:sudoku, post:welcome
CREATE TABLE IF NOT EXISTS `seo_pages` (
  `page_key`     VARCHAR(120) NOT NULL,
  `title`        VARCHAR(190) DEFAULT NULL,
  `description`  VARCHAR(400) DEFAULT NULL,
  `keywords`     VARCHAR(400) DEFAULT NULL,
  `og_image`     VARCHAR(255) DEFAULT NULL,
  `canonical`    VARCHAR(255) DEFAULT NULL,
  `robots`       VARCHAR(40)  DEFAULT NULL COMMENT 'blank = index,follow',
  `ai_generated` TINYINT(1)   NOT NULL DEFAULT 0,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Settings (all editable from the admin panel)
-- -------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`sgroup`,`slabel`) VALUES
  -- payments
  ('pay_currency',            'INR', 'payments', 'Currency'),
  ('pay_order',               'razorpay,cashfree', 'payments', 'Gateway display order'),
  ('pay_razorpay_enabled',    '0',   'payments', 'Razorpay on'),
  ('pay_razorpay_key_id',     '',    'payments', 'Razorpay Key ID'),
  ('pay_razorpay_key_secret', '',    'payments', 'Razorpay Key Secret'),
  ('pay_razorpay_webhook_secret', '', 'payments', 'Razorpay webhook secret'),
  ('pay_cashfree_enabled',    '0',   'payments', 'Cashfree on'),
  ('pay_cashfree_mode',       'sandbox', 'payments', 'Cashfree mode (sandbox / production)'),
  ('pay_cashfree_app_id',     '',    'payments', 'Cashfree App ID'),
  ('pay_cashfree_secret',     '',    'payments', 'Cashfree Secret Key'),
  ('pay_brand_note',          'Payments are processed securely by our payment partner.', 'payments', 'Note under the pay button'),
  -- registration fee
  ('reg_fee_enabled',         '0',   'payments', 'Registration fee on'),
  ('reg_fee_amount',          '0',   'payments', 'Registration fee (paise)'),
  ('reg_fee_apply_existing',  '0',   'payments', 'Existing players must pay too'),
  ('reg_fee_since',           '',    'payments', 'Fee applies to accounts created after'),
  ('reg_fee_note',            'A one-time registration fee unlocks your account for every free game.', 'payments', 'Registration fee note'),
  -- seo
  ('seo_title_format',        '{page} | {site}', 'seo', 'Title format'),
  ('seo_home_title',          '',    'seo', 'Home page title'),
  ('seo_keywords',            'online skill games, quiz contest, sudoku online, memory game, chess online, daily spin, play games online india', 'seo', 'Default keywords'),
  ('seo_og_image',            '',    'seo', 'Default share image'),
  ('seo_twitter',             '',    'seo', 'Twitter / X handle'),
  ('seo_google_verify',       '',    'seo', 'Google Search Console code'),
  ('seo_bing_verify',         '',    'seo', 'Bing Webmaster code'),
  ('seo_ga4_id',              '',    'seo', 'Google Analytics 4 ID'),
  ('seo_gtm_id',              '',    'seo', 'Google Tag Manager ID'),
  ('seo_noindex_site',        '0',   'seo', 'Hide whole site from search'),
  ('seo_robots_extra',        '',    'seo', 'Extra robots.txt lines'),
  ('seo_public_catalogue',    '0',   'seo', 'Games list visible to guests'),
  ('seo_org_name',            '',    'seo', 'Organisation name for schema'),
  ('seo_ai_key',              '',    'seo', 'Claude API key'),
  ('seo_ai_model',            'claude-sonnet-4-5', 'seo', 'Claude model'),
  ('seo_ai_focus',            'skill games, quiz, India', 'seo', 'What the AI should focus on');

-- A Payments row in Admin -> Modules, so the whole paywall can be
-- switched off in one click without losing any configuration.
INSERT IGNORE INTO `modules` (`mkey`,`label`,`icon`,`blurb`,`kind`,`is_active`,`nav_visible`,`sort_order`) VALUES
  ('payments', 'Payments & subscriptions', '&#128179;',
   'Registration fee and paid game plans (Razorpay / Cashfree).', 'feature', 1, 0, 85);
