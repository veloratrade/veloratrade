-- ============================================================================
-- VELORA — v1.7 rollback: drop auth_events (Phase 3 login history)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS auth_events;

SET FOREIGN_KEY_CHECKS = 1;
