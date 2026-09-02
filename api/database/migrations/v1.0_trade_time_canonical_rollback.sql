-- VELORA v1.0 — rollback for trade_time_canonical foundation (Phase 2A)
--
-- Drops the additive columns/indexes introduced by v1.0_trade_time_canonical.sql.
-- This does NOT restore or alter open_time / close_time (they were never
-- changed). Run only if the v1.0 columns/indexes are not consumed anywhere.
--
-- Idempotent: guarded by information_schema so re-running is a no-op.

-- trades indexes (drop before columns)
SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND INDEX_NAME = 'idx_trades_time_status') > 0,
    'ALTER TABLE trades DROP INDEX idx_trades_time_status',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND INDEX_NAME = 'idx_trades_occurred_open') > 0,
    'ALTER TABLE trades DROP INDEX idx_trades_occurred_open',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

-- trades columns
SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'raw_close_text') > 0,
    'ALTER TABLE trades DROP COLUMN raw_close_text',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'raw_open_text') > 0,
    'ALTER TABLE trades DROP COLUMN raw_open_text',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'source_calendar') > 0,
    'ALTER TABLE trades DROP COLUMN source_calendar',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'source_timezone_source') > 0,
    'ALTER TABLE trades DROP COLUMN source_timezone_source',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'source_timezone') > 0,
    'ALTER TABLE trades DROP COLUMN source_timezone',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'time_status') > 0,
    'ALTER TABLE trades DROP COLUMN time_status',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'occurred_close_at_utc') > 0,
    'ALTER TABLE trades DROP COLUMN occurred_close_at_utc',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'occurred_open_at_utc') > 0,
    'ALTER TABLE trades DROP COLUMN occurred_open_at_utc',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

-- trading_accounts columns
SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trading_accounts'
       AND COLUMN_NAME = 'timezone_source') > 0,
    'ALTER TABLE trading_accounts DROP COLUMN timezone_source',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trading_accounts'
       AND COLUMN_NAME = 'timezone') > 0,
    'ALTER TABLE trading_accounts DROP COLUMN timezone',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;
