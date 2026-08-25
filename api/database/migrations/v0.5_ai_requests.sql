-- ============================================================================
-- VELORA — v0.5 AI Requests, Feature Flags, Audit Logs
-- Foundation hardening for future AI features.
-- Follows existing conventions: InnoDB, utf8mb4, FK, ownership, IF NOT EXISTS
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Generic AI request tracking for all features (extraction, analysis, reports, assistant)
CREATE TABLE IF NOT EXISTS ai_requests (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    feature         VARCHAR(32)     NOT NULL DEFAULT 'extraction' COMMENT 'extraction, analysis, report, assistant, recommendation',
    provider        VARCHAR(32)     NOT NULL DEFAULT 'gemini',
    model           VARCHAR(64)     NOT NULL DEFAULT 'gemini-1.5-flash',
    prompt_hash     CHAR(64)        NOT NULL COMMENT 'sha256 of prompt for dedup and audit',
    tokens_used     INT UNSIGNED    NOT NULL DEFAULT 0,
    latency_ms      INT UNSIGNED    NOT NULL DEFAULT 0,
    status          ENUM('success','failed','quota_exhausted','timeout') NOT NULL DEFAULT 'success',
    cost            DECIMAL(10,6)   NOT NULL DEFAULT 0.000000 COMMENT 'cost in USD',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_requests_user (user_id),
    KEY idx_ai_requests_feature (feature),
    KEY idx_ai_requests_provider (provider),
    KEY idx_ai_requests_status (status),
    KEY idx_ai_requests_created (created_at),
    KEY idx_ai_requests_prompt_hash (prompt_hash),
    CONSTRAINT fk_ai_requests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Feature flags for gradual rollout
CREATE TABLE IF NOT EXISTS ai_feature_flags (
    feature_name        VARCHAR(64)     NOT NULL COMMENT 'ai_screenshot_extraction, ai_trade_analysis, etc.',
    enabled             TINYINT(1)      NOT NULL DEFAULT 0,
    rollout_percentage  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (feature_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit logs — security and privacy, never store raw screenshots
CREATE TABLE IF NOT EXISTS ai_audit_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    feature         VARCHAR(32)     NOT NULL DEFAULT 'extraction',
    provider        VARCHAR(32)     NOT NULL DEFAULT 'gemini',
    image_hash      CHAR(64)        NOT NULL COMMENT 'sha256 of image, never raw image',
    action          VARCHAR(32)     NOT NULL DEFAULT 'extraction' COMMENT 'extraction, analysis, report, chat',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_audit_user (user_id),
    KEY idx_ai_audit_feature (feature),
    KEY idx_ai_audit_provider (provider),
    KEY idx_ai_audit_hash (image_hash),
    KEY idx_ai_audit_created (created_at),
    CONSTRAINT fk_ai_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Feedback for future training (P1 preparation, create now for foundation)
CREATE TABLE IF NOT EXISTS ai_feedback (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    extraction_id       BIGINT UNSIGNED NULL COMMENT 'FK to ai_extractions',
    original_result     JSON            NULL,
    corrected_result    JSON            NULL,
    changed_fields      JSON            NULL COMMENT 'array of changed field names',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_feedback_user (user_id),
    KEY idx_ai_feedback_extraction (extraction_id),
    KEY idx_ai_feedback_created (created_at),
    CONSTRAINT fk_ai_feedback_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_feedback_extraction FOREIGN KEY (extraction_id) REFERENCES ai_extractions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed feature flags
INSERT INTO ai_feature_flags (feature_name, enabled, rollout_percentage) VALUES
    ('ai_screenshot_extraction', 1, 100),
    ('ai_trade_analysis', 0, 0),
    ('ai_weekly_report', 0, 0),
    ('ai_assistant', 0, 0),
    ('ai_recommendations', 0, 0),
    ('ai_risk_analysis', 0, 0)
ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), rollout_percentage = VALUES(rollout_percentage);

SET FOREIGN_KEY_CHECKS = 1;
