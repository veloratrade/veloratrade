-- VELORA v1.1 rollback — MetaApi fill ledger (Phase 5, Objective A)
-- Non-destructive opt-in: drops ONLY the v1.1 ledger table. Does NOT touch
-- trades.* or any canonical column. Take a protected backup before running.

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'metaapi_fills') = 1,
    'DROP TABLE metaapi_fills',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql;
EXECUTE velora_stmt;
DEALLOCATE PREPARE velora_stmt;
