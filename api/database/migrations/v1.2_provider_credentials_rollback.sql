-- ============================================================================
-- VELORA — v1.2 provider credential verification metadata — ROLLBACK
-- Drops the ai_provider_credentials metadata table. This is a metadata-only
-- table; no credential value is removed by this rollback (plaintext secrets
-- live in the private velora.env and are untouched).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS ai_provider_credentials;

SET FOREIGN_KEY_CHECKS = 1;
