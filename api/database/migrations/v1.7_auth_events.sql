-- ============================================================================
-- VELORA — v1.7 Authentication Events — Admin login history (Phase 3)
-- Smallest correct extension: real success/failure authentication events
-- recorded at AuthService::login. No secrets are stored (no passwords,
-- tokens, or reset material — only a reason code). user_id is NULL when a
-- login attempt names an unknown account (anti-enumeration preserved).
-- Additive + reversible (see v1.7_auth_events_rollback.sql).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS auth_events (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NULL,                                  -- NULL = unknown account at attempt time
    event_type  VARCHAR(32)     NOT NULL DEFAULT 'login',              -- login (future: logout, refresh …)
    result      VARCHAR(16)     NOT NULL,                              -- success | failure
    reason      VARCHAR(64)     NULL,                                  -- invalid_credentials | account_inactive | email_not_verified
    ip_address  VARCHAR(45)     NULL,                                  -- IPv4/IPv6
    user_agent  VARCHAR(250)    NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_auth_events_user_time (user_id, created_at),
    KEY idx_auth_events_result (result),
    CONSTRAINT fk_auth_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
