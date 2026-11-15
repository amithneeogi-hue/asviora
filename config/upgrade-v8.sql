-- ===================================================================
-- ASVIORA GAMES — schema upgrade v8
-- -------------------------------------------------------------------
-- Adds (3D Cube deposit gate + daily tournament):
--
--   1. users.has_deposited, users.locked_coins (bonus unlock on first deposit)
--   2. coin_packages      — admin-managed top-up denominations
--   3. cube_tournament_results — daily prize distribution history
--   4. coin_transactions.type extended with 'deposit','tournament','unlock'
--   5. settings rows for cube tournament prizes / min plays
-- ===================================================================

-- -------------------------------------------------------------------
-- 1. USER DEPOSIT / LOCKED COINS
-- -------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN `has_deposited` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `locked_coins`  INT NOT NULL DEFAULT 0;

-- -------------------------------------------------------------------
-- 2. COIN PACKAGES (top-up denominations)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `coin_packages` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `label`        VARCHAR(80)  NOT NULL,
  `coins`        INT UNSIGNED NOT NULL DEFAULT 0,
  `bonus_coins`  INT UNSIGNED NOT NULL DEFAULT 0,
  `price_paise`  INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`   INT          NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------
-- 3. CUBE TOURNAMENT RESULTS (daily prize distribution)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cube_tournament_results` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tournament_date` DATE         NOT NULL,
  `distributed_at`  DATETIME     DEFAULT NULL,
  `distributed_by`  INT UNSIGNED DEFAULT NULL,
  `total_plays`     INT UNSIGNED NOT NULL DEFAULT 0,
  `rank1_user`      INT UNSIGNED DEFAULT NULL,
  `rank1_time`      INT UNSIGNED DEFAULT NULL,
  `rank1_prize`     INT UNSIGNED NOT NULL DEFAULT 0,
  `rank2_user`      INT UNSIGNED DEFAULT NULL,
  `rank2_time`      INT UNSIGNED DEFAULT NULL,
  `rank2_prize`     INT UNSIGNED NOT NULL DEFAULT 0,
  `rank3_user`      INT UNSIGNED DEFAULT NULL,
  `rank3_time`      INT UNSIGNED DEFAULT NULL,
  `rank3_prize`     INT UNSIGNED NOT NULL DEFAULT 0,
  `undone_at`       DATETIME     DEFAULT NULL,
  `note`            VARCHAR(250) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_date` (`tournament_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------
-- 4. COIN TRANSACTIONS — extend type enum
-- -------------------------------------------------------------------
ALTER TABLE `coin_transactions`
  MODIFY `type` ENUM('signup','reward','entry_fee','bonus','referral','admin_adjust','daily','quiz','spin','deposit','tournament','unlock') NOT NULL;

-- -------------------------------------------------------------------
-- 5. SETTINGS — cube tournament prizes + min plays
-- -------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`sgroup`,`slabel`) VALUES
  ('cube_tournament_enabled','0','game','3D Cube daily tournament on/off'),
  ('cube_prize_1st','500','game','3D Cube 1st prize (coins)'),
  ('cube_prize_2nd','250','game','3D Cube 2nd prize (coins)'),
  ('cube_prize_3rd','100','game','3D Cube 3rd prize (coins)'),
  ('cube_tournament_min_plays','10','game','Min completed solves to award 2nd/3rd'),
  ('cube_entry_unlock_min_deposit','1','game','First deposit must grant at least this many coins');

-- -------------------------------------------------------------------
-- 6. DEFAULT COIN PACKAGES
-- -------------------------------------------------------------------
INSERT IGNORE INTO `coin_packages` (`label`,`coins`,`bonus_coins`,`price_paise`,`is_active`,`sort_order`) VALUES
  ('Starter — 500 coins','500','0','4900',1,10),
  ('Booster — 1200 coins','1000','200','9900',1,20),
  ('Pro — 2600 coins','2000','600','19900',1,30);
