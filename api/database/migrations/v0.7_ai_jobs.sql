-- ============================================================================
-- VELORA — v0.7 AI Jobs Queue — async processing for PHP-FPM
-- No Redis, uses DB lease pattern like MetaApi worker (fenced queue).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ai_jobs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    job_type        VARCHAR(32)     NOT NULL COMMENT 'extraction, analysis, report, assistant, recommendation',
    payload         JSON            NOT NULL COMMENT 'job data: trades, period, etc.',
    status          ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'when job becomes available (for retry delay)',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
