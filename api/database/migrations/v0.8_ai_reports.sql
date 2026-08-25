-- ============================================================================
-- VELORA — v0.8 AI Reports — weekly/monthly intelligent reports
-- Uses Analysis module, does not query trades directly.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ai_reports (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    period_start    DATE            NOT NULL COMMENT 'week start YYYY-MM-DD',
    period_end      DATE            NOT NULL COMMENT 'week end YYYY-MM-DD',
    locale          VARCHAR(10)     NOT NULL DEFAULT 'en' COMMENT 'fa/en',
    content         JSON            NOT NULL COMMENT 'report content from AI',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_reports_user (user_id),
    KEY idx_ai_reports_period (period_start, period_end),
    KEY idx_ai_reports_locale (locale),
    KEY idx_ai_reports_created (created_at),
    UNIQUE KEY uq_ai_reports_user_period_locale (user_id, period_start, period_end, locale),
    CONSTRAINT fk_ai_reports_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analysis results table for future (P1)
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
