-- ============================================================================
-- VELORA — v1.5 System Observability (Phase D)
--   * system_logs        : append-only structured application log (redacted)
--   * integration_health : last bounded probe outcome per integration (real)
-- Follows existing conventions: InnoDB, utf8mb4, idempotent (IF NOT EXISTS).
-- Rollback: api/database/migrations/v1.5_system_observability_rollback.sql
-- ============================================================================
-- PURPOSE (Phase D):
--   Make Velora observable/diagnosable without replacing the Phase A-C
--   config/secret architecture. system_logs is the SINGLE structured log store
--   (no duplicate logging) and does NOT hold secrets (enforced defensively at
--   write time + central redaction before any admin rendering). 
--   integration_health stores ONLY the last REAL probe outcome so an admin can
--   see historical last-check without us ever fabricating a timestamp.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS system_logs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    severity      VARCHAR(8)      NOT NULL DEFAULT 'INFO' COMMENT 'DEBUG|INFO|WARN|ERROR',
    source        VARCHAR(64)     NOT NULL COMMENT 'component: api|database|auth|metaapi|n8n|ai|mailer|worker|...',
    message       VARCHAR(1000)   NULL COMMENT 'sanitized message; never a secret',
    request_id    VARCHAR(64)     NULL COMMENT 'correlation/request id (Request::contextId)',
    correlation_id VARCHAR(64)    NULL COMMENT 'context id for traceability',
    user_id       BIGINT UNSIGNED NULL COMMENT 'actor when safely available; NULL = system',
    error_code    VARCHAR(64)     NULL COMMENT 'machine error/status code',
    metadata_json TEXT            NULL COMMENT 'sanitized safe metadata; never secrets',
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_syslog_severity (severity),
    KEY idx_syslog_source (source),
    KEY idx_syslog_created (created_at),
    KEY idx_syslog_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_health (
    integration   VARCHAR(32)     NOT NULL COMMENT 'metaapi|n8n_relay|ai|email',
    status        VARCHAR(32)     NOT NULL COMMENT 'HEALTHY|DEGRADED|UNHEALTHY|NOT_CONFIGURED|UNKNOWN',
    latency_ms    INT UNSIGNED    NULL,
    error_code    VARCHAR(64)     NULL COMMENT 'machine classification (AUTH_FAILED|...|null)',
    message       VARCHAR(500)    NULL COMMENT 'sanitized diagnostic (never a secret)',
    checked_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (integration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
