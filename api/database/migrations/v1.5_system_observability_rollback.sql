-- ============================================================================
-- VELORA — v1.5 System Observability — ROLLBACK
-- Reverses v1.5_system_observability.sql. Drops the structured log store and
-- the integration-health cache. Logs/health are non-authoritative diagnostics:
-- the application degrades to "no structured log first" and reports
-- integration state from configuration in real time. No secrets are lost.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS integration_health;
DROP TABLE IF EXISTS system_logs;

SET FOREIGN_KEY_CHECKS = 1;
