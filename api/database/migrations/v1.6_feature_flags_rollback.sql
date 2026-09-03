-- ============================================================================
-- VELORA — v1.6 Feature-Flag Actor Tracking (Phase F) — ROLLBACK
-- Additive reverse: drops only the actor column added in v1.6. No data in any
-- other column is touched. Feature-flag enable/disable + rollout behaviour is
-- functionally unchanged without it; only the "updated by" display is lost.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE ai_feature_flags
    DROP COLUMN updated_by;
