-- ============================================================================
-- VELORA — v1.4 AI Global Route (Admin-managed global AI route)
-- Follows existing conventions: InnoDB, utf8mb4, idempotent (IF NOT EXISTS)
-- Rollback: api/database/migrations/v1.4_ai_global_route_rollback.sql
-- ============================================================================
--
-- Purpose (Phase B):
--   Allow a Super Admin to set the GLOBAL AI default route (direct | n8n_relay)
--   from the Admin Panel. This is the single source of truth for the runtime
--   resolver's default route, and it must take precedence over the legacy
--   GEMINI_ROUTE env and the legacy ai_gemini_relay_route feature flag so that a
--   value explicitly saved by an administrator is never silently overridden.
--
--   This is a generic key-value settings table (NOT a duplicate of
--   ai_feature_flags: that table is boolean+rollout only and cannot represent a
--   tri-state route value). It holds the explicit admin global route.
--
--   No secrets are stored here. Routes are allowlisted values only
--   ('direct' | 'n8n_relay'); validation happens server-side in the controller
--   and resolver.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ai_global_settings (
    setting_key     VARCHAR(64)     NOT NULL,
    setting_value   VARCHAR(64)     NULL,
    updated_by      BIGINT UNSIGNED NULL COMMENT 'admin actor (0/unknown = system or migration)',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
