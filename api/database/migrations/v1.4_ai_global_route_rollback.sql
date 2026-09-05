-- ============================================================================
-- VELORA — v1.4 AI Global Route — ROLLBACK
-- Reverses v1.4_ai_global_route.sql. Drops the admin global AI route settings
-- table. No secrets are lost: routes are non-secret allowlisted values and the
-- runtime simply falls back to legacy env / ai_gemini_relay_route flag / direct.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS ai_global_settings;

SET FOREIGN_KEY_CHECKS = 1;
