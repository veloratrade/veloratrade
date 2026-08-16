-- ============================================================================
-- VELORA — MySQL 8.0 / MariaDB 11 compatible Schema Extensions
-- هماهنگ با ساختار واقعی دیتابیس سایت veloratrade.ir (piknet_velora)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. EMAIL NOTIFICATIONS
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_notifications` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `event_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` longtext COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `sent_at` datetime DEFAULT NULL,
  `failed_at` datetime DEFAULT NULL,
  `error_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_en_user` (`user_id`),
  KEY `idx_en_event_type` (`event_type`),
  KEY `idx_en_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. EMAIL PREFERENCES
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_preferences` (
  `user_id` bigint UNSIGNED NOT NULL,
  `welcome_email` tinyint(1) NOT NULL DEFAULT '1',
  `security_alerts` tinyint(1) NOT NULL DEFAULT '1',
  `trade_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `weekly_report` tinyint(1) NOT NULL DEFAULT '1',
  `monthly_report` tinyint(1) NOT NULL DEFAULT '1',
  `achievement_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. EMAIL VERIFICATIONS
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ev_user` (`user_id`),
  KEY `idx_ev_token` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. USER ACHIEVEMENTS
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_achievements` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `achievement_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `achieved_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `metadata_json` longtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ua_user_key` (`user_id`, `achievement_key`),
  KEY `idx_ua_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. USER DEVICES
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_devices` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `fingerprint` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ud_user_fp` (`user_id`, `fingerprint`),
  KEY `idx_ud_last_seen` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
