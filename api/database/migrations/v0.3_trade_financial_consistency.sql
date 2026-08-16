-- VELORA v0.3 — trade/partial-exit financial consistency
-- Run once against the production MySQL/MariaDB database before deploying
-- the matching API code. This migration contains schema only; no user data.

-- Authentication throttling is fail-closed in application code and requires
-- this shared table. Older MySQL deployment scripts omitted it entirely.
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket       VARCHAR(255) NOT NULL,
    hits         INT UNSIGNED NOT NULL DEFAULT 1,
    window_start DATETIME     NOT NULL,
    PRIMARY KEY (bucket),
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB;

SET @velora_add_contract_size = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'trades'
       AND COLUMN_NAME = 'contract_size') = 0,
    'ALTER TABLE trades ADD COLUMN contract_size DECIMAL(18,8) NOT NULL DEFAULT 1.00000000 AFTER volume',
    'SELECT 1'
);
PREPARE velora_stmt FROM @velora_add_contract_size;
EXECUTE velora_stmt;
DEALLOCATE PREPARE velora_stmt;

-- Fail safely instead of coercing unresolved legacy rows. Before deployment,
-- the following count must be 0; review and correct such journal records using
-- their broker/source history. In particular, never invent an exit price.
-- SELECT COUNT(*) AS unresolved_trade_rows FROM trades
-- WHERE entry_price IS NULL OR entry_price <= 0
--    OR exit_price IS NULL OR exit_price <= 0
--    OR volume IS NULL OR volume <= 0
--    OR contract_size IS NULL OR contract_size <= 0
--    OR commission IS NULL OR swap IS NULL OR profit_loss IS NULL
--    OR open_time IS NULL OR close_time IS NULL OR close_time < open_time;
-- Do not rely on strict SQL mode for these guards. If unresolved rows exist,
-- PREPARE fails on the deliberately nonexistent, diagnostic column name before
-- any narrowing ALTER can coerce legacy values.
SET @velora_v03_trade_guard_sql = IF(
    (SELECT COUNT(*) FROM trades
     WHERE entry_price IS NULL OR entry_price <= 0
        OR exit_price IS NULL OR exit_price <= 0
        OR volume IS NULL OR volume <= 0
        OR contract_size IS NULL OR contract_size <= 0
        OR commission IS NULL OR swap IS NULL OR profit_loss IS NULL
        OR open_time IS NULL OR close_time IS NULL OR close_time < open_time) = 0,
    'SELECT 1',
    'SELECT velora_v03_abort_unresolved_trade_rows_reconcile_from_broker FROM trades LIMIT 1'
);
PREPARE velora_stmt FROM @velora_v03_trade_guard_sql;
EXECUTE velora_stmt;
DEALLOCATE PREPARE velora_stmt;

SET @velora_v03_exit_guard_sql = IF(
    (SELECT COUNT(*) FROM trade_exits
     WHERE exit_price IS NULL OR exit_price <= 0
        OR volume IS NULL OR volume <= 0
        OR pnl IS NULL OR exited_at IS NULL) = 0,
    'SELECT 1',
    'SELECT velora_v03_abort_unresolved_exit_rows_reconcile_from_broker FROM trade_exits LIMIT 1'
);
PREPARE velora_stmt FROM @velora_v03_exit_guard_sql;
EXECUTE velora_stmt;
DEALLOCATE PREPARE velora_stmt;

ALTER TABLE trades
    MODIFY entry_price DECIMAL(18,8) NOT NULL,
    MODIFY exit_price DECIMAL(18,8) NOT NULL,
    MODIFY volume DECIMAL(18,8) NOT NULL,
    MODIFY contract_size DECIMAL(18,8) NOT NULL DEFAULT 1.00000000,
    MODIFY commission DECIMAL(18,8) NOT NULL DEFAULT 0.00000000,
    MODIFY swap DECIMAL(18,8) NOT NULL DEFAULT 0.00000000,
    MODIFY profit_loss DECIMAL(24,8) NOT NULL,
    MODIFY r_multiple DECIMAL(18,8) NULL,
    MODIFY stop_loss DECIMAL(18,8) NULL,
    MODIFY take_profit DECIMAL(18,8) NULL;

ALTER TABLE trade_exits
    MODIFY exit_price DECIMAL(18,8) NOT NULL,
    MODIFY volume DECIMAL(18,8) NOT NULL,
    MODIFY pnl DECIMAL(24,8) NOT NULL DEFAULT 0.00000000;
