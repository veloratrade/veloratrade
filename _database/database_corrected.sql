
-- FIXES FOR VELORA DATABASE (Based on analysis)
-- Applied to: piknet_velora

ALTER TABLE users
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_email (email),
  ADD INDEX idx_role (role),
  ADD INDEX idx_status (status);

ALTER TABLE trading_accounts
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_user_id (user_id),
  ADD INDEX idx_metaapi (metaapi_account_id);

ALTER TABLE trades
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_user_symbol (user_id, symbol),
  ADD INDEX idx_open_time (open_time),
  ADD INDEX idx_close_time (close_time),
  ADD INDEX idx_status (status);

ALTER TABLE tags
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_user_id (user_id);

ALTER TABLE trade_events
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_trade_event (trade_id);

ALTER TABLE trade_exits
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_trade_exit (trade_id);

ALTER TABLE trade_features
  ADD PRIMARY KEY (trade_id),
  ADD INDEX idx_features_session (session);

ALTER TABLE trade_screenshots
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_trade_screenshot (trade_id);

ALTER TABLE trade_tags
  ADD PRIMARY KEY (trade_id, tag_id);

ALTER TABLE sync_jobs
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_sync_account (account_id);

ALTER TABLE notifications
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_notif_user (user_id);

ALTER TABLE email_notifications
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_email_notif_user (user_id),
  ADD INDEX idx_email_notif_status (status);

ALTER TABLE email_preferences
  MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (user_id);

ALTER TABLE email_verifications
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_email_verif_user (user_id);

ALTER TABLE password_resets
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_password_reset_user (user_id);

ALTER TABLE webhook_events
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id);

ALTER TABLE user_achievements
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_achievement_user (user_id);

ALTER TABLE user_devices
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_device_user (user_id);

ALTER TABLE user_sessions
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_session_user (user_id);

-- CREATE MISSING TABLE FOR v0.5 ANALYTICS (Per Roadmap Pivot)
CREATE TABLE IF NOT EXISTS user_analytics_daily (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED DEFAULT NULL,
  date DATE NOT NULL,
  trade_count INT UNSIGNED NOT NULL DEFAULT 0,
  win_count INT UNSIGNED NOT NULL DEFAULT 0,
  loss_count INT UNSIGNED NOT NULL DEFAULT 0,
  breakeven_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_pnl DECIMAL(18,2) NOT NULL DEFAULT '0.00',
  best_pnl DECIMAL(15,2) DEFAULT NULL,
  worst_pnl DECIMAL(15,2) DEFAULT NULL,
  win_rate DECIMAL(5,4) DEFAULT NULL,
  profit_factor DECIMAL(10,4) DEFAULT NULL,
  equity_peak DECIMAL(15,2) DEFAULT NULL,
  max_drawdown DECIMAL(15,2) DEFAULT NULL,
  computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_analytics_user_date (user_id, date),
  INDEX idx_analytics_account (account_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ADD SAMPLE DATA TO TRADES (Minimum 10 records for Analytics testing)
INSERT IGNORE INTO trades (id, user_id, account_id, symbol, direction, entry_price, exit_price, volume, lot_size, commission, swap, profit_loss, net_pnl, status, r_multiple, open_time, close_time, source, created_at, updated_at) VALUES
(10, 3, NULL, 'XAUUSD', 'BUY', 4320.00000, 4330.00000, 0.05, 0.05, 0.00, 0.00, 500.00, 500.00, 'CLOSED', 1.0000, '2026-08-01 10:00:00', '2026-08-01 11:00:00', 'MANUAL', NOW(), NOW()),
(11, 3, NULL, 'EURUSD', 'SELL', 1.08500, 1.08000, 0.10, 0.10, 0.00, -5.00, 500.00, 495.00, 'CLOSED', 2.5000, '2026-08-01 11:00:00', '2026-08-01 12:00:00', 'MANUAL', NOW(), NOW()),
(12, 3, NULL, 'GBPUSD', 'BUY', 1.25000, 1.24500, 0.10, 0.10, 0.00, 0.00, -500.00, -500.00, 'CLOSED', -1.0000, '2026-08-01 12:00:00', '2026-08-01 13:00:00', 'MANUAL', NOW(), NOW()),
(13, 3, NULL, 'USDJPY', 'BUY', 148.50000, 149.00000, 0.10, 0.10, 0.00, 0.00, 500.00, 500.00, 'CLOSED', 1.6667, '2026-08-01 13:00:00', '2026-08-01 14:00:00', 'MANUAL', NOW(), NOW()),
(14, 3, NULL, 'XAUUSD', 'SELL', 4330.00000, 4310.00000, 0.10, 0.10, 0.00, 0.00, 2000.00, 2000.00, 'CLOSED', 2.0000, '2026-08-01 14:00:00', '2026-08-02 10:00:00', 'MANUAL', NOW(), NOW()),
(15, 24, NULL, 'EURUSD', 'BUY', 1.09000, 1.09500, 0.10, 0.10, 0.00, 0.00, 500.00, 500.00, 'CLOSED', 1.0000, '2026-08-05 09:00:00', '2026-08-05 10:00:00', 'MANUAL', NOW(), NOW());

-- Original database dump follows
-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Aug 08, 2026 at 03:04 PM
-- Server version: 8.0.46-cll-lve
-- PHP Version: 8.4.24

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `piknet_velora`
--

-- --------------------------------------------------------

--
-- Table structure for table `email_notifications`
--

CREATE TABLE `email_notifications` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `event_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` longtext COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `sent_at` datetime DEFAULT NULL,
  `failed_at` datetime DEFAULT NULL,
  `error_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `email_notifications`
--

-- داده واقعی جدول `email_notifications` حذف شد (مخزن عمومی است).
-- ساختار جدول بالا حفظ شده؛ داده را از بکاپ خصوصی وارد کنید.
-- --------------------------------------------------------

--
-- Table structure for table `email_preferences`
--

CREATE TABLE `email_preferences` (
  `user_id` bigint UNSIGNED NOT NULL,
  `welcome_email` tinyint(1) NOT NULL DEFAULT '1',
  `security_alerts` tinyint(1) NOT NULL DEFAULT '1',
  `trade_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `weekly_report` tinyint(1) NOT NULL DEFAULT '1',
  `monthly_report` tinyint(1) NOT NULL DEFAULT '1',
  `achievement_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_verifications`
--

CREATE TABLE `email_verifications` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `email_verifications`
--

-- داده واقعی جدول `email_verifications` حذف شد (مخزن عمومی است).
-- ساختار جدول بالا حفظ شده؛ داده را از بکاپ خصوصی وارد کنید.
-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `type` enum('TRADE_RECEIVED','SYNC_SUCCESS','CONNECTION_LOST','CONNECTION_RESTORED','REPORT_READY','RISK_ALERT') NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` varchar(1000) DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `password_resets`
--

-- داده واقعی جدول `password_resets` حذف شد (مخزن عمومی است).
-- ساختار جدول بالا حفظ شده؛ داده را از بکاپ خصوصی وارد کنید.
-- --------------------------------------------------------

--
-- Table structure for table `sync_jobs`
--

CREATE TABLE `sync_jobs` (
  `id` bigint UNSIGNED NOT NULL,
  `account_id` bigint UNSIGNED NOT NULL,
  `job_type` enum('HISTORICAL','LIVE_CATCHUP') NOT NULL DEFAULT 'HISTORICAL',
  `sync_type` enum('INITIAL','AUTO','INCREMENTAL','MANUAL','LIVE_CATCHUP') NOT NULL DEFAULT 'MANUAL',
  `status` enum('PENDING','RUNNING','DONE','FAILED') NOT NULL DEFAULT 'PENDING',
  `attempts` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `max_attempts` tinyint UNSIGNED NOT NULL DEFAULT '3',
  `next_attempt_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `range_from` datetime DEFAULT NULL,
  `range_to` datetime DEFAULT NULL,
  `last_error` text,
  `error_code` varchar(64) DEFAULT NULL,
  `deals_synced` int UNSIGNED NOT NULL DEFAULT '0',
  `duplicates_skipped` int UNSIGNED NOT NULL DEFAULT '0',
  `duration_ms` int UNSIGNED DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tags`
--

CREATE TABLE `tags` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `name` varchar(60) NOT NULL,
  `kind` enum('STRATEGY','SETUP','MISTAKE','EMOTION','CUSTOM') NOT NULL DEFAULT 'CUSTOM',
  `color` varchar(7) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trades`
--

CREATE TABLE `trades` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `account_id` bigint UNSIGNED DEFAULT NULL,
  `external_deal_id` varchar(64) DEFAULT NULL,
  `ticket_id` varchar(32) DEFAULT NULL,
  `symbol` varchar(32) NOT NULL,
  `direction` varchar(4) NOT NULL,
  `entry_price` decimal(15,5) NOT NULL,
  `exit_price` decimal(15,5) DEFAULT NULL,
  `volume` decimal(15,2) NOT NULL,
  `lot_size` decimal(10,2) DEFAULT NULL,
  `commission` decimal(15,2) NOT NULL DEFAULT '0.00',
  `swap` decimal(15,2) NOT NULL DEFAULT '0.00',
  `profit_loss` decimal(15,2) NOT NULL,
  `net_pnl` decimal(18,2) DEFAULT NULL,
  `status` enum('OPEN','CLOSED') NOT NULL DEFAULT 'CLOSED',
  `r_multiple` decimal(10,4) DEFAULT NULL,
  `stop_loss` decimal(15,5) DEFAULT NULL,
  `take_profit` decimal(15,5) DEFAULT NULL,
  `open_time` datetime NOT NULL,
  `close_time` datetime DEFAULT NULL,
  `strategy_tag` varchar(64) DEFAULT NULL,
  `emotional_score` tinyint UNSIGNED DEFAULT NULL,
  `notes` text,
  `strategy` varchar(100) DEFAULT NULL,
  `setup` varchar(100) DEFAULT NULL,
  `emotion` varchar(50) DEFAULT NULL,
  `confidence` tinyint UNSIGNED DEFAULT NULL,
  `mistake` varchar(100) DEFAULT NULL,
  `market_context` varchar(300) DEFAULT NULL,
  `source` varchar(10) NOT NULL DEFAULT 'manual',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `trades`
--

-- داده واقعی جدول `trades` حذف شد (مخزن عمومی است).
-- ساختار جدول بالا حفظ شده؛ داده را از بکاپ خصوصی وارد کنید.
-- --------------------------------------------------------

--
-- Table structure for table `trade_events`
--

CREATE TABLE `trade_events` (
  `id` bigint UNSIGNED NOT NULL,
  `trade_id` bigint UNSIGNED NOT NULL,
  `event_type` enum('CREATED','UPDATED','CLOSED','DELETED','SYNCED') NOT NULL,
  `payload` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trade_exits`
--

CREATE TABLE `trade_exits` (
  `id` bigint UNSIGNED NOT NULL,
  `trade_id` bigint UNSIGNED NOT NULL,
  `exit_type` enum('tp','sl','manual','partial') NOT NULL DEFAULT 'manual',
  `exit_price` decimal(15,5) NOT NULL,
  `volume` decimal(15,2) NOT NULL,
  `pnl` decimal(15,2) NOT NULL DEFAULT '0.00',
  `exited_at` datetime NOT NULL,
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trade_features`
--

CREATE TABLE `trade_features` (
  `trade_id` bigint UNSIGNED NOT NULL,
  `session` enum('ASIA','LONDON','NEW YORK','LATE') DEFAULT NULL,
  `day_of_week` tinyint UNSIGNED DEFAULT NULL,
  `hour_utc` tinyint UNSIGNED DEFAULT NULL,
  `duration_seconds` int UNSIGNED DEFAULT NULL,
  `risk_amount` decimal(18,2) DEFAULT NULL,
  `rr` decimal(10,2) DEFAULT NULL,
  `pnl_r` decimal(10,2) DEFAULT NULL,
  `outcome` enum('WIN','LOSS','BREAKEVEN','OPEN') DEFAULT NULL,
  `emotion` varchar(50) DEFAULT NULL,
  `confidence` tinyint UNSIGNED DEFAULT NULL,
  `mistake` varchar(100) DEFAULT NULL,
  `strategy_tags` json DEFAULT NULL,
  `setup_tags` json DEFAULT NULL,
  `all_tags` json DEFAULT NULL,
  `notes_present` tinyint(1) NOT NULL DEFAULT '0',
  `market_context` varchar(300) DEFAULT NULL,
  `has_before_screenshot` tinyint(1) NOT NULL DEFAULT '0',
  `has_after_screenshot` tinyint(1) NOT NULL DEFAULT '0',
  `features_version` smallint UNSIGNED NOT NULL DEFAULT '1',
  `computed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trade_screenshots`
--

CREATE TABLE `trade_screenshots` (
  `id` bigint UNSIGNED NOT NULL,
  `trade_id` bigint UNSIGNED NOT NULL,
  `category` enum('BEFORE','AFTER','OTHER') NOT NULL DEFAULT 'OTHER',
  `user_id` bigint UNSIGNED NOT NULL,
  `file_name` varchar(64) NOT NULL,
  `mime` varchar(50) NOT NULL,
  `size_bytes` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trade_tags`
--

CREATE TABLE `trade_tags` (
  `trade_id` bigint UNSIGNED NOT NULL,
  `tag_id` bigint UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trading_accounts`
--

CREATE TABLE `trading_accounts` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `provider` enum('MT4','MT5','MANUAL') NOT NULL DEFAULT 'MANUAL',
  `platform` enum('MT4','MT5','MANUAL') NOT NULL DEFAULT 'MANUAL',
  `broker` varchar(100) DEFAULT NULL,
  `server` varchar(100) DEFAULT NULL,
  `mt_login` varchar(50) DEFAULT NULL,
  `account_type` varchar(20) NOT NULL DEFAULT 'STANDARD',
  `metaapi_account_id` varchar(64) DEFAULT NULL,
  `sync_status` enum('DISCONNECTED','CONNECTING','SYNCING','CONNECTED','ERROR') NOT NULL DEFAULT 'DISCONNECTED',
  `last_synced_at` datetime DEFAULT NULL,
  `connection_credentials_encrypted` varbinary(2048) DEFAULT NULL,
  `connected_at` datetime DEFAULT NULL,
  `disconnected_at` datetime DEFAULT NULL,
  `auto_sync_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `last_incremental_at` datetime DEFAULT NULL,
  `connection_checked_at` datetime DEFAULT NULL,
  `consecutive_errors` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `last_error` varchar(500) DEFAULT NULL,
  `dev_force_error` tinyint(1) NOT NULL DEFAULT '0',
  `starting_balance` decimal(18,2) NOT NULL DEFAULT '0.00',
  `current_balance` decimal(18,2) NOT NULL DEFAULT '0.00',
  `label` varchar(120) NOT NULL DEFAULT '',
  `account_number_masked` varchar(32) NOT NULL DEFAULT '',
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `leverage` varchar(16) DEFAULT NULL,
  `status` enum('connected','error','disconnected') NOT NULL DEFAULT 'disconnected',
  `balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `equity` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(120) NOT NULL DEFAULT '',
  `first_name` varchar(100) NOT NULL DEFAULT '',
  `last_name` varchar(100) NOT NULL DEFAULT '',
  `role` enum('user','admin') NOT NULL DEFAULT 'user',
  `timezone` varchar(64) NOT NULL DEFAULT 'UTC',
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

-- داده واقعی جدول `users` حذف شد (مخزن عمومی است).
-- ساختار جدول بالا حفظ شده؛ داده را از بکاپ خصوصی وارد کنید.
-- --------------------------------------------------------

--
-- Table structure for table `user_achievements`
--

CREATE TABLE `user_achievements` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `achievement_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `achieved_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `metadata_json` longtext COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_achievements`
--

-- داده واقعی جدول `user_achievements` حذف شد (مخزن عمومی است).
-- ساختار جدول بالا حفظ شده؛ داده را از بکاپ خصوصی وارد کنید.
-- --------------------------------------------------------

--
-- Table structure for table `user_devices`
--

CREATE TABLE `user_devices` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `fingerprint` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_devices`
--

-- داده واقعی جدول `user_devices` حذف شد (مخزن عمومی است).
-- ساختار جدول بالا حفظ شده؛ داده را از بکاپ خصوصی وارد کنید.
-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `refresh_token_hash` char(64) NOT NULL,
  `access_token_hash` char(64) NOT NULL DEFAULT '',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `user_sessions`
--

-- داده واقعی جدول `user_sessions` حذف شد (مخزن عمومی است).
-- ساختار جدول بالا حفظ شده؛ داده را از بکاپ خصوصی وارد کنید.
-- --------------------------------------------------------

--
-- Table structure for table `webhook_events`
--

CREATE TABLE `webhook_events` (
  `id` bigint UNSIGNED NOT NULL,
  `account_id` bigint UNSIGNED DEFAULT NULL,
  `event_type` varchar(64) NOT NULL,
  `payload` json NOT NULL,
  `processed` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `email_notifications`
--
ALTER TABLE `email_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_en_user_event` (`user_id`,`event_type`),
  ADD KEY `idx_en_status` (`status`);

--
-- Indexes for table `email_preferences`
--
ALTER TABLE `email_preferences`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `email_verifications`
--
ALTER TABLE `email_verifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ev_token` (`token_hash`),
  ADD KEY `idx_ev_user` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_user_unread` (`user_id`,`read_at`,`created_at`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pr_token` (`token_hash`),
  ADD KEY `idx_pr_user` (`user_id`);

--
-- Indexes for table `sync_jobs`
--
ALTER TABLE `sync_jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_jobs_queue` (`status`,`next_attempt_at`),
  ADD KEY `idx_jobs_account` (`account_id`);

--
-- Indexes for table `tags`
--
ALTER TABLE `tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tags_user_kind_name` (`user_id`,`kind`,`name`);

--
-- Indexes for table `trades`
--
ALTER TABLE `trades`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_trades_external_deal` (`account_id`,`external_deal_id`),
  ADD UNIQUE KEY `uq_trades_broker_ticket` (`account_id`,`ticket_id`),
  ADD KEY `idx_trades_user_open` (`user_id`,`open_time`),
  ADD KEY `idx_trades_user_symbol` (`user_id`,`symbol`),
  ADD KEY `idx_trades_account_open` (`account_id`,`open_time`),
  ADD KEY `idx_trades_account_symbol` (`account_id`,`symbol`),
  ADD KEY `idx_trades_close_time` (`close_time`);

--
-- Indexes for table `trade_events`
--
ALTER TABLE `trade_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_events_trade` (`trade_id`);

--
-- Indexes for table `trade_exits`
--
ALTER TABLE `trade_exits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_exits_trade` (`trade_id`);

--
-- Indexes for table `trade_features`
--
ALTER TABLE `trade_features`
  ADD PRIMARY KEY (`trade_id`);

--
-- Indexes for table `trade_screenshots`
--
ALTER TABLE `trade_screenshots`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shots_trade` (`trade_id`);

--
-- Indexes for table `trade_tags`
--
ALTER TABLE `trade_tags`
  ADD PRIMARY KEY (`trade_id`,`tag_id`),
  ADD KEY `idx_tt_tag` (`tag_id`);

--
-- Indexes for table `trading_accounts`
--
ALTER TABLE `trading_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_accounts_user` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_email` (`email`);

--
-- Indexes for table `user_achievements`
--
ALTER TABLE `user_achievements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_achievement` (`user_id`,`achievement_key`);

--
-- Indexes for table `user_devices`
--
ALTER TABLE `user_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_device_fingerprint` (`user_id`,`fingerprint`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sessions_refresh_hash` (`refresh_token_hash`),
  ADD KEY `idx_sessions_user` (`user_id`),
  ADD KEY `idx_sessions_access_hash` (`access_token_hash`);

--
-- Indexes for table `webhook_events`
--
ALTER TABLE `webhook_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_webhook_unprocessed` (`processed`,`created_at`),
  ADD KEY `fk_webhook_account` (`account_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `email_notifications`
--
ALTER TABLE `email_notifications`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=71;

--
-- AUTO_INCREMENT for table `email_verifications`
--
ALTER TABLE `email_verifications`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `sync_jobs`
--
ALTER TABLE `sync_jobs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tags`
--
ALTER TABLE `tags`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trades`
--
ALTER TABLE `trades`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `trade_events`
--
ALTER TABLE `trade_events`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trade_exits`
--
ALTER TABLE `trade_exits`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trade_screenshots`
--
ALTER TABLE `trade_screenshots`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trading_accounts`
--
ALTER TABLE `trading_accounts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `user_achievements`
--
ALTER TABLE `user_achievements`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `user_devices`
--
ALTER TABLE `user_devices`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `webhook_events`
--
ALTER TABLE `webhook_events`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `email_notifications`
--
ALTER TABLE `email_notifications`
  ADD CONSTRAINT `fk_en_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `email_preferences`
--
ALTER TABLE `email_preferences`
  ADD CONSTRAINT `fk_ep_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `email_verifications`
--
ALTER TABLE `email_verifications`
  ADD CONSTRAINT `fk_ev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sync_jobs`
--
ALTER TABLE `sync_jobs`
  ADD CONSTRAINT `fk_jobs_account` FOREIGN KEY (`account_id`) REFERENCES `trading_accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tags`
--
ALTER TABLE `tags`
  ADD CONSTRAINT `fk_tags_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trades`
--
ALTER TABLE `trades`
  ADD CONSTRAINT `fk_trades_account` FOREIGN KEY (`account_id`) REFERENCES `trading_accounts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_trades_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trade_events`
--
ALTER TABLE `trade_events`
  ADD CONSTRAINT `fk_events_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trade_exits`
--
ALTER TABLE `trade_exits`
  ADD CONSTRAINT `fk_exits_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trade_features`
--
ALTER TABLE `trade_features`
  ADD CONSTRAINT `fk_features_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trade_screenshots`
--
ALTER TABLE `trade_screenshots`
  ADD CONSTRAINT `fk_shots_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trade_tags`
--
ALTER TABLE `trade_tags`
  ADD CONSTRAINT `fk_tt_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tt_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_achievements`
--
ALTER TABLE `user_achievements`
  ADD CONSTRAINT `fk_ua_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_devices`
--
ALTER TABLE `user_devices`
  ADD CONSTRAINT `fk_ud_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `webhook_events`
--
ALTER TABLE `webhook_events`
  ADD CONSTRAINT `fk_webhook_account` FOREIGN KEY (`account_id`) REFERENCES `trading_accounts` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
-- v0.2 Step 1: sync_jobs improvement for MetaApi Sync Queue
ALTER TABLE sync_jobs
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_sync_account (account_id),
  ADD INDEX idx_sync_status (status),
  ADD INDEX idx_sync_created (created_at);

-- v0.2 Step 1: webhook_events improvement for MetaApi Webhook Ingestion
ALTER TABLE webhook_events
  MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id),
  ADD INDEX idx_webhook_account (account_id),
  ADD INDEX idx_webhook_type (event_type),
  ADD INDEX idx_webhook_created (created_at);
