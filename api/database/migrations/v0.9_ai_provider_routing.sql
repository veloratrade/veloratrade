-- ============================================================================
-- VELORA — v0.9 AI Provider Routing — per-feature ordered provider chains
-- Follows existing conventions: InnoDB, utf8mb4, idempotent (IF NOT EXISTS)
-- Rollback: api/database/migrations/v0.9_ai_provider_routing_rollback.sql
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Ordered, per-feature provider chain rows (admin-managed).
-- Values are validated against server-side allowlists (ProviderCatalog) before
-- insert/update; no FK on provider/feature strings (matches existing AI tables).
CREATE TABLE IF NOT EXISTS ai_feature_providers (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    feature     VARCHAR(64)     NOT NULL COMMENT 'screenshot_extraction, trade_analysis, weekly_report, assistant',
    provider    VARCHAR(32)     NOT NULL COMMENT 'gemini, openai, claude, tesseract',
    model       VARCHAR(64)     NULL COMMENT 'NULL = provider/env default model',
    priority    SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 = first; ordered ASC',
    enabled     TINYINT(1)      NOT NULL DEFAULT 1,
    route       VARCHAR(16)     NULL COMMENT 'gemini: direct|n8n_relay|NULL; others: direct|NULL; NULL = provider default resolution',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_afp_feature_provider (feature, provider),
    KEY idx_afp_lookup (feature, enabled, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial seed: ONLY screenshot_extraction. Do NOT auto-add openai/claude.
-- Idempotent via UNIQUE(feature, provider) + INSERT IGNORE.
INSERT IGNORE INTO ai_feature_providers (feature, provider, model, priority, enabled, route) VALUES
    ('screenshot_extraction', 'gemini',    NULL, 1, 1, NULL),
    ('screenshot_extraction', 'tesseract', NULL, 2, 1, NULL);

-- Observability: extend existing provider log rows with routing context.
-- Nullable, best-effort written by AIProviderLogRepository (older rows stay NULL).
ALTER TABLE ai_provider_logs
    ADD COLUMN feature        VARCHAR(64) NULL AFTER error_code,
    ADD COLUMN model          VARCHAR(64) NULL AFTER feature,
    ADD COLUMN route          VARCHAR(16) NULL AFTER model,
    ADD COLUMN fallback_index SMALLINT UNSIGNED NULL AFTER route;

-- Quota rows for new providers follow the v0.4 seed convention.
INSERT INTO ai_provider_quotas (provider, quota_limit) VALUES
    ('openai', 1500),
    ('claude', 1500)
ON DUPLICATE KEY UPDATE quota_limit = VALUES(quota_limit);

SET FOREIGN_KEY_CHECKS = 1;
