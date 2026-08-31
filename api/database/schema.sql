-- ============================================================================
-- VELORA — v0.1 Core Data Engine — MySQL 8.0 / MariaDB 11 compatible
-- Schema per Master Roadmap v0.1: users, user_sessions, trading_accounts,
-- trades, trade_exits. InnoDB + FK constraints + 3NF normalization.
-- All monetary values use DECIMAL — never FLOAT (CTO checklist #5).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- (اصلاح باگ: حذف CREATE DATABASE و USE — تا ایمپورت مستقیم در دیتابیس مقصد درست کار کند)
-- CREATE DATABASE IF NOT EXISTS velora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE velora;

-- ----------------------------------------------------------------------------
-- USERS
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(255)    NOT NULL,
    password_hash VARCHAR(255)    NOT NULL,                 -- bcrypt (cost 12)
    full_name     VARCHAR(120)    NOT NULL DEFAULT '',
    role          ENUM('user','admin') NOT NULL DEFAULT 'user',
    timezone      VARCHAR(64)     NOT NULL DEFAULT 'UTC',
    locale        VARCHAR(35)     NOT NULL DEFAULT 'fa',          -- UI language preference (fa/en) — PR-03
    locale_source VARCHAR(16)     NOT NULL DEFAULT 'default',     -- default|browser|cookie|user — PR-03
    locale_updated_at DATETIME    NULL,                           -- last explicit preference write (UTC) — PR-03
    ai_consent_at   DATETIME    NULL,                           -- external AI processing consent (UTC) — v0.6
    status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
    email_verified_at DATETIME    NULL,                       -- ستون تأیید ایمیل (لینک فعال‌سازی)
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- USER SESSIONS (refresh-token store; access token is stateless JWT)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sessions (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            BIGINT UNSIGNED NOT NULL,
    refresh_token_hash CHAR(64)        NOT NULL,            -- sha256 hex of refresh token
    access_token_hash  CHAR(64)        NOT NULL,            -- sha256 hex of current access token (for revoke-on-logout)
    ip_address         VARCHAR(45)     NULL,                -- IPv4/IPv6
    user_agent         VARCHAR(255)    NULL,
    expires_at         DATETIME        NOT NULL,
    revoked_at         DATETIME        NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_refresh_hash (refresh_token_hash),
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_access_hash (access_token_hash),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- AUTHENTICATION, SECURITY, AND ACCOUNT-NOTIFICATION SUPPORT
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64)        NOT NULL,
    expires_at DATETIME        NOT NULL,
    used_at    DATETIME        NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pr_token (token_hash),
    KEY idx_pr_user (user_id),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_verifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64)        NOT NULL,
    expires_at  DATETIME        NOT NULL,
    verified_at DATETIME        NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ev_user (user_id),
    KEY idx_ev_token (token_hash),
    CONSTRAINT fk_ev_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_notifications (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    event_type      VARCHAR(50)     NOT NULL,
    recipient_email VARCHAR(255)    NOT NULL,
    subject         VARCHAR(255)    NOT NULL,
    payload_json    LONGTEXT        NULL,
    status          VARCHAR(20)     NOT NULL DEFAULT 'queued',
    sent_at         DATETIME        NULL,
    failed_at       DATETIME        NULL,
    error_message   VARCHAR(500)    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_en_user (user_id),
    KEY idx_en_event_type (event_type),
    KEY idx_en_status (status),
    CONSTRAINT fk_en_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_preferences (
    user_id                   BIGINT UNSIGNED NOT NULL,
    welcome_email             TINYINT(1)      NOT NULL DEFAULT 1,
    security_alerts           TINYINT(1)      NOT NULL DEFAULT 1,
    trade_notifications       TINYINT(1)      NOT NULL DEFAULT 1,
    weekly_report             TINYINT(1)      NOT NULL DEFAULT 1,
    monthly_report            TINYINT(1)      NOT NULL DEFAULT 1,
    achievement_notifications TINYINT(1)      NOT NULL DEFAULT 1,
    updated_at                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_ep_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_achievements (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    achievement_key VARCHAR(80)     NOT NULL,
    achieved_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    metadata_json   LONGTEXT        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ua_user_key (user_id, achievement_key),
    KEY idx_ua_user (user_id),
    CONSTRAINT fk_ua_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_devices (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    fingerprint   CHAR(64)        NOT NULL,
    ip_address    VARCHAR(45)     NULL,
    user_agent    VARCHAR(250)    NULL,
    first_seen_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ud_user_fp (user_id, fingerprint),
    KEY idx_ud_last_seen (last_seen_at),
    CONSTRAINT fk_ud_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket       VARCHAR(255) NOT NULL,
    hits         INT UNSIGNED NOT NULL DEFAULT 1,
    window_start DATETIME     NOT NULL,
    PRIMARY KEY (bucket),
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- TRADING ACCOUNTS (broker accounts owned by a user)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trading_accounts (
    id                               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id                          BIGINT UNSIGNED NOT NULL,
    provider                         ENUM('MT4','MT5','MANUAL') NOT NULL DEFAULT 'MANUAL',
    platform                         ENUM('MT4','MT5','MANUAL') NOT NULL DEFAULT 'MANUAL',
    broker                           VARCHAR(100)    NULL,
    server                           VARCHAR(100)    NULL,
    mt_login                         VARCHAR(50)     NULL,
    account_type                     VARCHAR(20)     NOT NULL DEFAULT 'STANDARD',
    metaapi_account_id               VARCHAR(64)     NULL,
    sync_status                      ENUM('DISCONNECTED','CONNECTING','SYNCING','CONNECTED','ERROR') NOT NULL DEFAULT 'DISCONNECTED',
    last_synced_at                   DATETIME        NULL,
    connection_credentials_encrypted VARBINARY(2048) NULL,
    connected_at                     DATETIME        NULL,
    disconnected_at                  DATETIME        NULL,
    auto_sync_enabled                TINYINT(1)      NOT NULL DEFAULT 1,
    last_incremental_at              DATETIME        NULL,
    connection_checked_at            DATETIME        NULL,
    consecutive_errors               TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error                       VARCHAR(500)    NULL,
    dev_force_error                  TINYINT(1)      NOT NULL DEFAULT 0,
    starting_balance                 DECIMAL(18,2)   NOT NULL DEFAULT 0.00,
    current_balance                  DECIMAL(18,2)   NOT NULL DEFAULT 0.00,
    label                            VARCHAR(120)    NOT NULL DEFAULT '',
    account_number_masked            VARCHAR(32)     NOT NULL DEFAULT '',
    currency                         CHAR(3)         NOT NULL DEFAULT 'USD',
    leverage                         VARCHAR(16)     NULL,
    status                           ENUM('connected','error','disconnected') NOT NULL DEFAULT 'disconnected',
    balance                          DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
    equity                           DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
    created_at                       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_accounts_user (user_id),
    UNIQUE KEY uq_accounts_metaapi (metaapi_account_id),
    UNIQUE KEY uq_accounts_connection (user_id, platform, server, mt_login),
    KEY idx_accounts_user_sync (user_id, sync_status),
    CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- TRADES — core journal entity
-- Note: per roadmap the composite indexes (account_id, open_time) and
-- (account_id, symbol) are required for the sub-200ms latency SLA.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trades (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    account_id      BIGINT UNSIGNED NULL,
    external_deal_id VARCHAR(64)    NULL,
    symbol          VARCHAR(32)     NOT NULL,
    direction       ENUM('buy','sell') NOT NULL,
    entry_price     DECIMAL(18,8)   NOT NULL,
    exit_price      DECIMAL(18,8)   NOT NULL,
    volume          DECIMAL(18,8)   NOT NULL,               -- lot size
    contract_size   DECIMAL(18,8)   NOT NULL DEFAULT 1.00000000,
    commission      DECIMAL(18,8)   NOT NULL DEFAULT 0.00000000, -- positive = cost
    swap            DECIMAL(18,8)   NOT NULL DEFAULT 0.00000000, -- positive = cost
    profit_loss     DECIMAL(24,8)   NOT NULL,               -- net PnL (money), service-computed
    r_multiple      DECIMAL(18,8)   NULL,                   -- PnL / risk
    stop_loss       DECIMAL(18,8)   NULL,
    take_profit     DECIMAL(18,8)   NULL,
    open_time       DATETIME        NOT NULL,
    close_time      DATETIME        NOT NULL,
    strategy_tag    VARCHAR(64)     NULL,
    emotional_score TINYINT UNSIGNED NULL,                  -- 1..5
    notes           TEXT            NULL,
    source          ENUM('manual','auto_sync') NOT NULL DEFAULT 'manual',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_trades_user_open (user_id, open_time),
    KEY idx_trades_user_symbol (user_id, symbol),
    KEY idx_trades_account_open (account_id, open_time),
    KEY idx_trades_account_symbol (account_id, symbol),
    KEY idx_trades_close_time (close_time),
    KEY idx_user_account_time (user_id, account_id, close_time),
    UNIQUE KEY uq_trades_external_deal (account_id, external_deal_id),
    CONSTRAINT fk_trades_user    FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_trades_account FOREIGN KEY (account_id) REFERENCES trading_accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- TRADE EXITS — 3NF: a single trade may have multiple partial exits
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trade_exits (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    trade_id   BIGINT UNSIGNED NOT NULL,
    exit_type  ENUM('tp','sl','manual','partial') NOT NULL DEFAULT 'manual',
    exit_price DECIMAL(18,8)   NOT NULL,
    volume     DECIMAL(18,8)   NOT NULL,
    pnl        DECIMAL(24,8)   NOT NULL DEFAULT 0.00000000,
    exited_at  DATETIME        NOT NULL,
    notes      VARCHAR(255)    NULL,
    PRIMARY KEY (id),
    KEY idx_exits_trade (trade_id),
    CONSTRAINT fk_exits_trade FOREIGN KEY (trade_id) REFERENCES trades (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- METAAPI LIFECYCLE, FENCED SYNCHRONIZATION QUEUE AND WEBHOOK REPLAY LEDGER
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS metaapi_operations (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_key       CHAR(64)        NOT NULL,
    provider_marker     VARCHAR(64)     NOT NULL,
    request_fingerprint CHAR(64)        NOT NULL,
    user_id             BIGINT UNSIGNED NOT NULL,
    account_id          BIGINT UNSIGNED NULL,
    operation_type      VARCHAR(16)     NOT NULL DEFAULT 'CONNECT',
    status              VARCHAR(40)     NOT NULL DEFAULT 'PENDING',
    provider_account_id VARCHAR(64)     NULL,
    attempts            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error_code     VARCHAR(64)     NULL,
    completed_at        DATETIME        NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_metaapi_operation_key (operation_key),
    UNIQUE KEY uq_metaapi_provider_marker (provider_marker),
    UNIQUE KEY uq_metaapi_request_fingerprint (request_fingerprint),
    KEY idx_metaapi_operation_account (account_id),
    KEY idx_metaapi_operation_user_status (user_id, status),
    CONSTRAINT fk_metaapi_operation_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_metaapi_operation_account FOREIGN KEY (account_id) REFERENCES trading_accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sync_jobs (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id       BIGINT UNSIGNED NOT NULL,
    user_id          BIGINT UNSIGNED NOT NULL,
    type             ENUM('HISTORICAL','INCREMENTAL','WEBHOOK') NOT NULL DEFAULT 'HISTORICAL',
    status           ENUM('PENDING','RUNNING','COMPLETED','FAILED','DEAD_LETTER') NOT NULL DEFAULT 'PENDING',
    payload          JSON            NULL,
    attempts         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    available_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at        DATETIME        NULL,
    locked_by        VARCHAR(96)     NULL,
    lease_token      CHAR(64)        NULL,
    dedupe_key       VARCHAR(191)    NULL,
    last_error       TEXT            NULL,
    range_from       DATETIME        NULL,
    range_to         DATETIME        NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at       DATETIME        NULL,
    completed_at     DATETIME        NULL,
    dead_lettered_at DATETIME        NULL,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sync_dedupe (dedupe_key),
    UNIQUE KEY uq_sync_lease (lease_token),
    KEY idx_sync_claim (status, available_at, id),
    KEY idx_sync_stale (status, locked_at),
    KEY idx_sync_account_created (account_id, created_at),
    CONSTRAINT fk_sync_account FOREIGN KEY (account_id) REFERENCES trading_accounts (id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS webhook_events (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key             CHAR(64)        NOT NULL,
    account_id            BIGINT UNSIGNED NULL,
    metaapi_account_id    VARCHAR(64)     NULL,
    event_type            VARCHAR(50)     NOT NULL,
    payload               JSON            NOT NULL,
    hmac_verified         TINYINT(1)      NOT NULL DEFAULT 0,
    processed             TINYINT(1)      NOT NULL DEFAULT 0,
    processing_token      CHAR(64)        NULL,
    processing_started_at DATETIME        NULL,
    last_error            VARCHAR(64)     NULL,
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at          DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_event_key (event_key),
    UNIQUE KEY uq_webhook_processing_token (processing_token),
    KEY idx_webhook_account (account_id),
    KEY idx_webhook_type (event_type),
    KEY idx_webhook_created (created_at),
    CONSTRAINT fk_webhook_account FOREIGN KEY (account_id) REFERENCES trading_accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- DYNAMIC CONTENT TRANSLATION — cache-only reads + asynchronous worker queue
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content_translation_cache (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    content_type VARCHAR(64) NOT NULL,
    content_id VARCHAR(191) NOT NULL,
    source_locale VARCHAR(35) NOT NULL,
    target_locale VARCHAR(35) NOT NULL,
    source_hash VARCHAR(128) NOT NULL,
    translated_fields JSON NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'ready',
    provider VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_content_translation_version (content_type, content_id, source_hash, target_locale),
    KEY idx_translation_lookup (target_locale, status, content_type, content_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS content_translation_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    content_type VARCHAR(64) NOT NULL,
    content_id VARCHAR(191) NOT NULL,
    source_locale VARCHAR(35) NOT NULL,
    target_locale VARCHAR(35) NOT NULL,
    source_hash VARCHAR(128) NOT NULL,
    source_fields JSON NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    locked_by VARCHAR(96) NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_translation_job_version (content_type, content_id, source_hash, target_locale),
    KEY idx_translation_job_claim (status, available_at, id),
    KEY idx_translation_job_lock (status, locked_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- AI FOUNDATION (v0.4) — extraction cache, provider quotas, provider health logs
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_extractions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    provider        VARCHAR(32)     NOT NULL DEFAULT 'gemini',
    image_hash      CHAR(64)        NOT NULL,
    original_result JSON            NULL,
    final_result    JSON            NULL,
    confidence      FLOAT           NOT NULL DEFAULT 0.0,
    latency_ms      INT UNSIGNED    NOT NULL DEFAULT 0,
    status          ENUM('success','fallback','failed') NOT NULL DEFAULT 'success',
    error_code      VARCHAR(64)     NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_extractions_user (user_id),
    KEY idx_ai_extractions_hash (image_hash),
    KEY idx_ai_extractions_provider (provider),
    KEY idx_ai_extractions_status (status),
    KEY idx_ai_extractions_created (created_at),
    CONSTRAINT fk_ai_extractions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_provider_quotas (
    provider        VARCHAR(32)     NOT NULL,
    daily_used      INT UNSIGNED    NOT NULL DEFAULT 0,
    quota_limit     INT UNSIGNED    NOT NULL DEFAULT 1500,
    reset_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (provider)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_provider_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider        VARCHAR(32)     NOT NULL,
    status          ENUM('success','failed','quota_exhausted','timeout') NOT NULL,
    latency_ms      INT UNSIGNED    NOT NULL DEFAULT 0,
    error_code      VARCHAR(64)     NULL,
    feature         VARCHAR(64)     NULL,
    model           VARCHAR(64)     NULL,
    route           VARCHAR(16)     NULL,
    fallback_index  SMALLINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_provider_logs_provider (provider),
    KEY idx_provider_logs_status (status),
    KEY idx_provider_logs_created (created_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- AI REQUESTS / FEATURE FLAGS / AUDIT LOGS / FEEDBACK (v0.5)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_requests (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    feature         VARCHAR(32)     NOT NULL DEFAULT 'extraction',
    provider        VARCHAR(32)     NOT NULL DEFAULT 'gemini',
    model           VARCHAR(64)     NOT NULL DEFAULT 'gemini-1.5-flash',
    prompt_hash     CHAR(64)        NOT NULL,
    tokens_used     INT UNSIGNED    NOT NULL DEFAULT 0,
    latency_ms      INT UNSIGNED    NOT NULL DEFAULT 0,
    status          ENUM('success','failed','quota_exhausted','timeout') NOT NULL DEFAULT 'success',
    cost            DECIMAL(10,6)   NOT NULL DEFAULT 0.000000,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_requests_user (user_id),
    KEY idx_ai_requests_feature (feature),
    KEY idx_ai_requests_provider (provider),
    KEY idx_ai_requests_status (status),
    KEY idx_ai_requests_created (created_at),
    KEY idx_ai_requests_prompt_hash (prompt_hash),
    CONSTRAINT fk_ai_requests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_feature_flags (
    feature_name        VARCHAR(64)     NOT NULL,
    enabled             TINYINT(1)      NOT NULL DEFAULT 0,
    rollout_percentage  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (feature_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_audit_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    feature         VARCHAR(32)     NOT NULL DEFAULT 'extraction',
    provider        VARCHAR(32)     NOT NULL DEFAULT 'gemini',
    image_hash      CHAR(64)        NOT NULL,
    action          VARCHAR(32)     NOT NULL DEFAULT 'extraction',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_audit_user (user_id),
    KEY idx_ai_audit_feature (feature),
    KEY idx_ai_audit_provider (provider),
    KEY idx_ai_audit_hash (image_hash),
    KEY idx_ai_audit_created (created_at),
    CONSTRAINT fk_ai_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_feedback (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    extraction_id       BIGINT UNSIGNED NULL,
    original_result     JSON            NULL,
    corrected_result    JSON            NULL,
    changed_fields      JSON            NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_feedback_user (user_id),
    KEY idx_ai_feedback_extraction (extraction_id),
    KEY idx_ai_feedback_created (created_at),
    CONSTRAINT fk_ai_feedback_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_feedback_extraction FOREIGN KEY (extraction_id) REFERENCES ai_extractions (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- AI JOBS QUEUE (v0.7)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_jobs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    job_type        VARCHAR(32)     NOT NULL,
    payload         JSON            NOT NULL,
    status          ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_jobs_user (user_id),
    KEY idx_ai_jobs_type (job_type),
    KEY idx_ai_jobs_status (status),
    KEY idx_ai_jobs_available (available_at),
    KEY idx_ai_jobs_created (created_at),
    KEY idx_ai_jobs_status_available (status, available_at),
    CONSTRAINT fk_ai_jobs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- AI REPORTS / ANALYSIS (v0.8)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_reports (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    period_start    DATE            NOT NULL,
    period_end      DATE            NOT NULL,
    locale          VARCHAR(10)     NOT NULL DEFAULT 'en',
    content         JSON            NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_reports_user (user_id),
    KEY idx_ai_reports_period (period_start, period_end),
    KEY idx_ai_reports_locale (locale),
    KEY idx_ai_reports_created (created_at),
    UNIQUE KEY uq_ai_reports_user_period_locale (user_id, period_start, period_end, locale),
    CONSTRAINT fk_ai_reports_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_analysis (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    provider        VARCHAR(32)     NOT NULL DEFAULT 'gemini',
    model           VARCHAR(64)     NOT NULL DEFAULT 'gemini-1.5-flash',
    result_json     JSON            NOT NULL,
    confidence      FLOAT           NOT NULL DEFAULT 0.0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_analysis_user (user_id),
    KEY idx_ai_analysis_created (created_at),
    CONSTRAINT fk_ai_analysis_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- AI PROVIDER ROUTING (v0.9)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_feature_providers (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    feature     VARCHAR(64)     NOT NULL,
    provider    VARCHAR(32)     NOT NULL,
    model       VARCHAR(64)     NULL,
    priority    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    enabled     TINYINT(1)      NOT NULL DEFAULT 1,
    route       VARCHAR(16)     NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_afp_feature_provider (feature, provider),
    KEY idx_afp_lookup (feature, enabled, priority)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
