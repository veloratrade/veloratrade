-- ============================================================================
-- VELORA — v1.2 Provider Credential Verification Metadata
-- Additive, idempotent, reversible (see v1.2_provider_credentials_rollback.sql).
--
-- IMPORTANT: this table stores ONLY safe metadata (status, verified flag,
-- non-reversible fingerprint, timestamps, error code, latency). It NEVER stores
-- a credential value, API key, token, or secret. Plaintext credentials remain in
-- the private {VELORA_PRIVATE_ROOT}/config/velora.env (SecureCredentialStore).
--
-- Design guarantees:
--   - A newly-saved credential is UNVERIFIED until a live provider call returns
--     VALID (see AICredentialMetadataRepository::markUnverified / record).
--   - Only status=VALID is eligible for activation/routing-as-verified.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ai_provider_credentials (
    provider        VARCHAR(32)     NOT NULL COMMENT 'gemini, openai, claude, ...',
    status          VARCHAR(32)     NOT NULL DEFAULT 'UNVERIFIED'
                    COMMENT 'VALID, INVALID_CREDENTIAL, EXPIRED, REVOKED, DISABLED, INSUFFICIENT_PERMISSION, QUOTA_EXCEEDED, RATE_LIMITED, PROVIDER_UNAVAILABLE, REGION_RESTRICTED, NETWORK_ERROR, UNKNOWN, UNVERIFIED',
    verified        TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 only when status=VALID',
    fingerprint     VARCHAR(128)    NULL COMMENT 'HMAC-SHA256 of credential (non-reversible, NEVER the secret)',
    verified_at     DATETIME        NULL COMMENT 'last time status became VALID (UTC)',
    last_checked_at DATETIME        NULL COMMENT 'last verification attempt (UTC)',
    error_code      VARCHAR(64)     NULL COMMENT 'safe/sanitized provider error classification',
    latency_ms      INT UNSIGNED    NOT NULL DEFAULT 0,
    version         INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT 'credential version (incremented on replacement)',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (provider),
    KEY idx_ai_provider_credentials_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
