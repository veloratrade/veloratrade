-- ============================================================================
-- VELORA — v1.6 Feature-Flag Actor Tracking (Phase F)
-- Follows existing conventions: additive, IF NOT EXISTS, InnoDB, utf8mb4.
-- Rollback: api/database/migrations/v1.6_feature_flags_rollback.sql
-- ============================================================================
-- Purpose (Phase F):
--   Track the admin actor who last changed an ai_feature_flags row so the
--   Feature Flags admin module can display "last updated by" without
--   cross-joining the audit log. Additive only: no existing column is altered,
--   no row is dropped, no fabricated value is inserted (existing rows keep
--   updated_by = NULL = "system / pre-existing").
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE ai_feature_flags
    ADD COLUMN updated_by BIGINT UNSIGNED NULL COMMENT 'admin actor (0/unknown = system or migration)'
    AFTER rollout_percentage;
