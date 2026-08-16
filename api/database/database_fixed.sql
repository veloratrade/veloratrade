
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
