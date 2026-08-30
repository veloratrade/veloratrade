-- ============================================================================
-- VELORA — v0.9 AI Provider Routing — ROLLBACK
-- Reverts api/database/migrations/v0.9_ai_provider_routing.sql
-- WARNING: removes admin-managed routing rows; runtime falls back to the
--          environment-driven registry default (no data-loss beyond routing).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE ai_provider_logs
    DROP COLUMN fallback_index,
    DROP COLUMN route,
    DROP COLUMN model,
    DROP COLUMN feature;

DROP TABLE IF EXISTS ai_feature_providers;

-- Provider quota rows added by v0.9 seed are harmless; keep them (no action).

SET FOREIGN_KEY_CHECKS = 1;
