-- ============================================================================
-- VELORA — v1.3 Admin Management Foundation
-- Additive, idempotent, reversible (see v1.3_admin_management_rollback.sql).
--
-- CRITICAL ARCHITECTURE: RBAC role and subscription plan are SEPARATE.
--   users.role        -> 'user' | 'admin' | 'super_admin' (authorization ONLY)
--   users.plan        -> 'free' | 'pro'        (subscription, NO authorization)
--   users.subscription_status -> subscription lifecycle (authorization-neutral)
--
-- A Pro customer must NEVER gain an administrative role via subscription:
--   role='user', plan='pro'  is valid and expected.
-- This migration therefore keeps users.role to ('user','admin','super_admin')
-- and represents 'pro' only as a subscription plan.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 1. Internal subscription/plan layer on users (additive, guarded).
--    Added BEFORE widening role so a legacy 'pro'-as-role row can be migrated.
-- ---------------------------------------------------------------------------
SET @v13_has_plan = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'plan');
SET @v13_sql_plan = IF(@v13_has_plan = 0,
    'ALTER TABLE users ADD COLUMN plan ENUM(''free'',''pro'') NOT NULL DEFAULT ''free'' COMMENT ''subscription plan (RBAC-neutral)'' AFTER role',
    'SELECT 1');
PREPARE v13_stmt_plan FROM @v13_sql_plan; EXECUTE v13_stmt_plan; DEALLOCATE PREPARE v13_stmt_plan;

SET @v13_has_sub_status = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'subscription_status');
SET @v13_sql_sub = IF(@v13_has_sub_status = 0,
    'ALTER TABLE users ADD COLUMN subscription_status ENUM(''none'',''active'',''past_due'',''grace'',''expired'',''cancelled'') NOT NULL DEFAULT ''none'' COMMENT ''subscription lifecycle (RBAC-neutral)'' AFTER plan',
    'SELECT 1');
PREPARE v13_stmt_sub FROM @v13_sql_sub; EXECUTE v13_stmt_sub; DEALLOCATE PREPARE v13_stmt_sub;

SET @v13_has_plan_started = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'plan_started_at');
SET @v13_sql_ps = IF(@v13_has_plan_started = 0,
    'ALTER TABLE users ADD COLUMN plan_started_at DATETIME NULL AFTER subscription_status',
    'SELECT 1');
PREPARE v13_stmt_ps FROM @v13_sql_ps; EXECUTE v13_stmt_ps; DEALLOCATE PREPARE v13_stmt_ps;

SET @v13_has_plan_expires = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'plan_expires_at');
SET @v13_sql_pe = IF(@v13_has_plan_expires = 0,
    'ALTER TABLE users ADD COLUMN plan_expires_at DATETIME NULL AFTER plan_started_at',
    'SELECT 1');
PREPARE v13_stmt_pe FROM @v13_sql_pe; EXECUTE v13_stmt_pe; DEALLOCATE PREPARE v13_stmt_pe;

SET @v13_has_plan_updated = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'plan_updated_at');
SET @v13_sql_pu = IF(@v13_has_plan_updated = 0,
    'ALTER TABLE users ADD COLUMN plan_updated_at DATETIME NULL AFTER plan_expires_at',
    'SELECT 1');
PREPARE v13_stmt_pu FROM @v13_sql_pu; EXECUTE v13_stmt_pu; DEALLOCATE PREPARE v13_stmt_pu;

-- ---------------------------------------------------------------------------
-- 2. Data migration: reconcile any legacy row that held 'pro' as an RBAC role.
--    Such a customer is a normal member with a Pro subscription — demote the
--    role to 'user' and record the pro plan, so subscription NEVER grants an
--    administrative role and no customer is locked out.
-- ---------------------------------------------------------------------------
UPDATE users SET role = 'user', plan = 'pro' WHERE role = 'pro';

-- ---------------------------------------------------------------------------
-- 3. Widen users.role to the authorization-only set. Re-running MODIFY is
--    harmless (idempotent in effect); values are additive only.
-- ---------------------------------------------------------------------------
ALTER TABLE users
    MODIFY COLUMN role ENUM('user','admin','super_admin')
        NOT NULL DEFAULT 'user' COMMENT 'RBAC authorization role; guest is the unauthenticated state (never stored); NOT a subscription';

-- ---------------------------------------------------------------------------
-- 4. Admin audit log (immutable-from-UI; no UPDATE/DELETE path in the code).
--    Only sanitized summary text and sanitized metadata; never secrets.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NOT NULL COMMENT 'admin/super-admin actor; 0 = system',
    actor_role    VARCHAR(32)     NOT NULL DEFAULT 'admin',
    action        VARCHAR(64)     NOT NULL COMMENT 'user.suspend, provider.enabled, ...',
    target_type   VARCHAR(32)     NOT NULL DEFAULT 'user' COMMENT 'user|provider|system|...',
    target_id     BIGINT UNSIGNED NULL,
    result        VARCHAR(16)     NOT NULL DEFAULT 'success' COMMENT 'success|denied|error',
    summary       VARCHAR(500)    NULL COMMENT 'sanitized human-readable description (no secrets)',
    ip_address    VARCHAR(45)     NULL,
    user_agent    VARCHAR(250)    NULL,
    context_id    VARCHAR(64)     NULL COMMENT 'request/context id for correlation',
    metadata_json TEXT            NULL COMMENT 'sanitized safe metadata; never secrets',
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_actor (actor_user_id),
    KEY idx_audit_action (action),
    KEY idx_audit_target (target_type, target_id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
