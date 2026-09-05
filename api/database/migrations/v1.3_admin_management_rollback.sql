-- ============================================================================
-- VELORA — v1.3 Admin Management Foundation — ROLLBACK
-- Reverses v1.3_admin_management.sql. Reverts users.role to the pre-v1.3
-- ENUM, drops the internal subscription/plan columns, and drops the admin
-- audit log table. No operational data loss beyond the role/plan/audit
-- columns themselves; the audit table is intentionally dropped on rollback
-- (its contents are operator-facing, not authoritative business data).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE users
    MODIFY COLUMN role ENUM('user','admin') NOT NULL DEFAULT 'user'
        COMMENT 'role; guest is the unauthenticated state (never stored)';

-- Dropping columns added by the forward migration (assumes it ran).
ALTER TABLE users
    DROP COLUMN plan,
    DROP COLUMN subscription_status,
    DROP COLUMN plan_started_at,
    DROP COLUMN plan_expires_at,
    DROP COLUMN plan_updated_at;

DROP TABLE IF EXISTS admin_audit_logs;

SET FOREIGN_KEY_CHECKS = 1;
