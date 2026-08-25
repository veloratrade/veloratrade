-- ============================================================================
-- VELORA — v0.6 AI Privacy — consent and anonymization foundation
-- Minimal GDPR-ready foundation without overengineering.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Add ai_consent_at to users for external AI provider consent
SET @velora_v06_has_consent_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ai_consent_at'
);

SET @velora_v06_add_consent_sql = IF(
    @velora_v06_has_consent_col = 0,
    'ALTER TABLE users ADD COLUMN ai_consent_at DATETIME NULL COMMENT ''when user consented to external AI processing'' AFTER locale_updated_at',
    'SELECT 1'
);

PREPARE velora_v06_stmt FROM @velora_v06_add_consent_sql;
EXECUTE velora_v06_stmt;
DEALLOCATE PREPARE velora_v06_stmt;

-- Ensure ai_audit_logs exists (from v0.5) — if not, create minimal
CREATE TABLE IF NOT EXISTS ai_audit_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    feature         VARCHAR(32)     NOT NULL DEFAULT 'extraction',
    provider        VARCHAR(32)     NOT NULL DEFAULT 'gemini',
    image_hash      CHAR(64)        NOT NULL COMMENT 'sha256 of image, never raw image',
    action          VARCHAR(32)     NOT NULL DEFAULT 'extraction',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_audit_user (user_id),
    KEY idx_ai_audit_feature (feature),
    KEY idx_ai_audit_provider (provider),
    KEY idx_ai_audit_hash (image_hash),
    KEY idx_ai_audit_created (created_at),
    CONSTRAINT fk_ai_audit_user_v06 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
