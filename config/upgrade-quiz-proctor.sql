-- ===================================================================
-- ASVIORA GAMES  |  Schema v3 — quiz proctoring
-- -------------------------------------------------------------------
-- Adds the columns the anti-cheat layer writes to. Applied automatically
-- by includes/migrate.php; every statement is safe to re-run.
-- ===================================================================

-- A round that was killed for cheating is neither 'active' nor a clean
-- 'finished' — it needs its own state so leaderboards can exclude it.
ALTER TABLE `quiz_sessions`
  MODIFY `status` ENUM('active','finished','abandoned','voided')
    NOT NULL DEFAULT 'active';

-- How many times the proctor caught something, and what ended the round.
-- Kept on the session so admins can see the pattern per player.
ALTER TABLE `quiz_sessions`
  ADD COLUMN `strikes` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `status`;

ALTER TABLE `quiz_sessions`
  ADD COLUMN `void_reason` VARCHAR(40) DEFAULT NULL AFTER `strikes`;

-- Full audit trail. One row per detection, so a support query can answer
-- "why was my round cancelled" with something better than a guess.
CREATE TABLE IF NOT EXISTS `quiz_violations` (
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

-- How many strikes a player gets before the round dies. 1 = one warning,
-- the second detection voids. Editable from Admin -> Settings later.
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`sgroup`,`slabel`) VALUES
('quiz_strike_limit','1','quiz','Quiz warnings before a round is voided'),
('quiz_require_fullscreen','1','quiz','Force fullscreen exam mode in the quiz');
