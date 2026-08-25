-- ============================================================================
-- VELORA — v0.4 AI Foundation — ai_extractions table
-- Follows existing conventions: InnoDB, utf8mb4, FK, ownership via user_id
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ai_extractions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    provider        VARCHAR(32)     NOT NULL DEFAULT 'gemini' COMMENT 'gemini, tesseract, openai, etc.',
    image_hash      CHAR(64)        NOT NULL COMMENT 'sha256 of image for dedup cache',
    original_result JSON            NULL COMMENT 'raw provider response',
    final_result    JSON            NULL COMMENT 'validated/corrected result',
    confidence      FLOAT           NOT NULL DEFAULT 0.0 COMMENT '0.0-1.0',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provider quota tracking for $0 budget management
CREATE TABLE IF NOT EXISTS ai_provider_quotas (
    provider        VARCHAR(32)     NOT NULL,
    daily_used      INT UNSIGNED    NOT NULL DEFAULT 0,
    quota_limit     INT UNSIGNED    NOT NULL DEFAULT 1500 COMMENT 'Gemini free = 1500/day',
    reset_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provider health logs for simple failover (no Redis)
CREATE TABLE IF NOT EXISTS ai_provider_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider        VARCHAR(32)     NOT NULL,
    status          ENUM('success','failed','quota_exhausted','timeout') NOT NULL,
    latency_ms      INT UNSIGNED    NOT NULL DEFAULT 0,
    error_code      VARCHAR(64)     NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_provider_logs_provider (provider),
    KEY idx_provider_logs_status (status),
    KEY idx_provider_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default quotas
INSERT INTO ai_provider_quotas (provider, quota_limit) VALUES
    ('gemini', 1500),
    ('tesseract', 100000)
ON DUPLICATE KEY UPDATE quota_limit = VALUES(quota_limit);

SET FOREIGN_KEY_CHECKS = 1;
