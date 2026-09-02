-- VELORA v1.0 — trade date/time canonical foundation (Phase 2A)
--
-- Additive, idempotent schema foundation for the Trade Date/Time & Timezone
-- architecture. This migration ONLY adds nullable provenance/canonical columns
-- and account timezone columns. It does NOT:
--   * change, convert, or backfill open_time / close_time
--   * declare legacy timestamps to be UTC
--   * populate occurred_*_utc (they stay NULL = unresolved)
--   * touch or revive the legacy trade_features table
--   * implement session windows or calendar/timezone conversion
--
-- Canonical contract:
--   occurred_open_at_utc / occurred_close_at_utc
--       DATETIME NULL. A value is written ONLY when the source wall-clock has
--       been deterministically resolved to a true UTC instant using trustworthy
--       timezone/calendar evidence (later phases). NULL means "the system does
--       not have enough trustworthy information to establish the instant".
--       Existing rows are left NULL (never fabricated as UTC).
--   time_status
--       'resolved' only when both the canonical instants are established;
--       'unresolved' otherwise (DEFAULT). No third state in this phase.
--   source_timezone        IANA id (e.g. Europe/London) of the resolved source.
--   source_timezone_source  where the timezone came from (provenance).
--   source_calendar        calendar of the SOURCE evidence (gregorian|jalali|unknown),
--                          never derived from UI locale.
--   raw_open_text/raw_close_text  verbatim source datetime text (no normalization).
--
-- Enums are stored as short VARCHAR columns (project convention, e.g.
-- users.locale_source) rather than native ENUM, to keep future value additions
-- non-destructive. Application code validates allowed values.
--
-- Run once, manually, against MySQL/MariaDB — inspect live schema and run on a
-- non-production environment first. Production execution is a separate,
-- explicitly owner-approved operation and is NOT part of the PR that ships this
-- file. Idempotent: each ADD COLUMN/INDEX is guarded by information_schema.
--
-- Rollback: see v1.0_trade_time_canonical_rollback.sql.

-- ---------------------------------------------------------------------------
-- trades: canonical UTC instants (nullable; default unresolved)
-- ---------------------------------------------------------------------------
SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'occurred_open_at_utc') = 0,
    'ALTER TABLE trades ADD COLUMN occurred_open_at_utc DATETIME NULL AFTER close_time',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'occurred_close_at_utc') = 0,
    'ALTER TABLE trades ADD COLUMN occurred_close_at_utc DATETIME NULL AFTER occurred_open_at_utc',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'time_status') = 0,
    'ALTER TABLE trades ADD COLUMN time_status VARCHAR(16) NOT NULL DEFAULT ''unresolved'' AFTER occurred_close_at_utc',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'source_timezone') = 0,
    'ALTER TABLE trades ADD COLUMN source_timezone VARCHAR(64) NULL AFTER time_status',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'source_timezone_source') = 0,
    'ALTER TABLE trades ADD COLUMN source_timezone_source VARCHAR(20) NOT NULL DEFAULT ''unknown'' AFTER source_timezone',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'source_calendar') = 0,
    'ALTER TABLE trades ADD COLUMN source_calendar VARCHAR(16) NOT NULL DEFAULT ''unknown'' AFTER source_timezone_source',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'raw_open_text') = 0,
    'ALTER TABLE trades ADD COLUMN raw_open_text VARCHAR(64) NULL AFTER source_calendar',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'raw_close_text') = 0,
    'ALTER TABLE trades ADD COLUMN raw_close_text VARCHAR(64) NULL AFTER raw_open_text',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

-- New analytics/timezone-aware reads use canonical instants restricted to
-- resolved rows; index supports (user_id, occurred_open_at_utc) range scans for
-- those queries. Not added speculatively beyond the documented access pattern.
SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND INDEX_NAME = 'idx_trades_occurred_open') = 0,
    'ALTER TABLE trades ADD KEY idx_trades_occurred_open (user_id, occurred_open_at_utc)',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

-- Supports filtering/cohort scans over unresolved vs resolved rows.
SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades'
       AND INDEX_NAME = 'idx_trades_time_status') = 0,
    'ALTER TABLE trades ADD KEY idx_trades_time_status (time_status)',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

-- ---------------------------------------------------------------------------
-- trading_accounts: broker/account (SOURCE) timezone — separate from
-- users.timezone which is a display preference and must not be merged here.
-- ---------------------------------------------------------------------------
SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trading_accounts'
       AND COLUMN_NAME = 'timezone') = 0,
    'ALTER TABLE trading_accounts ADD COLUMN timezone VARCHAR(64) NULL AFTER server',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;

SET @velora_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trading_accounts'
       AND COLUMN_NAME = 'timezone_source') = 0,
    'ALTER TABLE trading_accounts ADD COLUMN timezone_source VARCHAR(20) NOT NULL DEFAULT ''unknown'' AFTER timezone',
    'SELECT 1');
PREPARE velora_stmt FROM @velora_sql; EXECUTE velora_stmt; DEALLOCATE PREPARE velora_stmt;
