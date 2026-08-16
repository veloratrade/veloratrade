-- VELORA v0.2 P0 — MetaApi lifecycle, fenced queue and webhook replay ledger
-- Target: MySQL 8.0. This migration is additive, idempotent and non-destructive.
-- IMPORTANT: P0-6 must take/read-verify a protected backup, stop workers,
-- validate this file's approved checksum and run duplicate preflight first.
-- If a duplicate blocker is reported, do not choose, merge or delete a winner.

SET NAMES utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS velora_v02_add_column$$
CREATE PROCEDURE velora_v02_add_column(
    IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_ddl TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
    ) THEN
        SET @velora_v02_sql = p_ddl;
        PREPARE velora_v02_stmt FROM @velora_v02_sql;
        EXECUTE velora_v02_stmt;
        DEALLOCATE PREPARE velora_v02_stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS velora_v02_add_index$$
CREATE PROCEDURE velora_v02_add_index(
    IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns VARCHAR(512),
    IN p_unique TINYINT, IN p_ddl TEXT
)
BEGIN
    DECLARE v_named BIGINT DEFAULT 0;
    DECLARE v_named_equivalent BIGINT DEFAULT 0;
    DECLARE v_equivalent BIGINT DEFAULT 0;

    SELECT COUNT(*) INTO v_named
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index;

    SELECT COUNT(*) INTO v_named_equivalent
    FROM (
        SELECT INDEX_NAME, MIN(NON_UNIQUE) AS non_unique,
               SUM(CASE WHEN SUB_PART IS NULL THEN 0 ELSE 1 END) AS partial_columns,
               SUM(CASE WHEN COLLATION = 'D' THEN 1 ELSE 0 END) AS descending_columns,
               MIN(INDEX_TYPE) AS index_type,
               GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS indexed_columns
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index
        GROUP BY INDEX_NAME
    ) AS named_index
    WHERE indexed_columns = p_columns AND partial_columns = 0
      AND descending_columns = 0 AND index_type = 'BTREE'
      AND non_unique = IF(p_unique = 1, 0, 1);

    SELECT COUNT(*) INTO v_equivalent
    FROM (
        SELECT INDEX_NAME, MIN(NON_UNIQUE) AS non_unique,
               SUM(CASE WHEN SUB_PART IS NULL THEN 0 ELSE 1 END) AS partial_columns,
               SUM(CASE WHEN COLLATION = 'D' THEN 1 ELSE 0 END) AS descending_columns,
               MIN(INDEX_TYPE) AS index_type,
               GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS indexed_columns
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
        GROUP BY INDEX_NAME
    ) AS candidate_indexes
    WHERE indexed_columns = p_columns AND partial_columns = 0
      AND descending_columns = 0 AND index_type = 'BTREE'
      AND (p_unique = 0 OR non_unique = 0);

    IF v_named > 0 AND v_named_equivalent = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BLOCKER: conflicting existing index name';
    END IF;
    IF v_equivalent = 0 THEN
        SET @velora_v02_sql = p_ddl;
        PREPARE velora_v02_stmt FROM @velora_v02_sql;
        EXECUTE velora_v02_stmt;
        DEALLOCATE PREPARE velora_v02_stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS velora_v02_add_constraint$$
CREATE PROCEDURE velora_v02_add_constraint(
    IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_constraint VARCHAR(64),
    IN p_ref_table VARCHAR(64), IN p_ref_column VARCHAR(64),
    IN p_delete_rule VARCHAR(16), IN p_update_rule VARCHAR(16), IN p_ddl TEXT
)
BEGIN
    DECLARE v_named BIGINT DEFAULT 0;
    DECLARE v_named_equivalent BIGINT DEFAULT 0;
    DECLARE v_equivalent BIGINT DEFAULT 0;

    SELECT COUNT(*) INTO v_named
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = p_constraint;

    SELECT COUNT(*) INTO v_named_equivalent
    FROM (
        SELECT kcu.CONSTRAINT_NAME, COUNT(*) AS column_count,
               SUM(CASE WHEN kcu.COLUMN_NAME = p_column
                         AND kcu.REFERENCED_TABLE_NAME = p_ref_table
                         AND kcu.REFERENCED_COLUMN_NAME = p_ref_column
                        THEN 1 ELSE 0 END) AS matching_columns,
               MIN(rc.DELETE_RULE) AS delete_rule, MIN(rc.UPDATE_RULE) AS update_rule
        FROM information_schema.KEY_COLUMN_USAGE kcu
        JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
          ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
         AND rc.TABLE_NAME = kcu.TABLE_NAME
         AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
        WHERE kcu.CONSTRAINT_SCHEMA = DATABASE() AND kcu.TABLE_NAME = p_table
          AND kcu.CONSTRAINT_NAME = p_constraint
        GROUP BY kcu.CONSTRAINT_NAME
    ) AS named_constraint
    WHERE column_count = 1 AND matching_columns = 1
      AND delete_rule = p_delete_rule
      AND (update_rule = p_update_rule
           OR (p_update_rule = 'NO ACTION' AND update_rule = 'RESTRICT'));

    SELECT COUNT(*) INTO v_equivalent
    FROM (
        SELECT kcu.CONSTRAINT_NAME, COUNT(*) AS column_count,
               SUM(CASE WHEN kcu.COLUMN_NAME = p_column
                         AND kcu.REFERENCED_TABLE_NAME = p_ref_table
                         AND kcu.REFERENCED_COLUMN_NAME = p_ref_column
                        THEN 1 ELSE 0 END) AS matching_columns,
               MIN(rc.DELETE_RULE) AS delete_rule, MIN(rc.UPDATE_RULE) AS update_rule
        FROM information_schema.KEY_COLUMN_USAGE kcu
        JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
          ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
         AND rc.TABLE_NAME = kcu.TABLE_NAME
         AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
        WHERE kcu.CONSTRAINT_SCHEMA = DATABASE() AND kcu.TABLE_NAME = p_table
        GROUP BY kcu.CONSTRAINT_NAME
    ) AS candidate_constraints
    WHERE column_count = 1 AND matching_columns = 1
      AND delete_rule = p_delete_rule
      AND (update_rule = p_update_rule
           OR (p_update_rule = 'NO ACTION' AND update_rule = 'RESTRICT'));

    IF v_named > 0 AND v_named_equivalent = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BLOCKER: conflicting existing constraint name';
    END IF;
    IF v_equivalent = 0 THEN
        SET @velora_v02_sql = p_ddl;
        PREPARE velora_v02_stmt FROM @velora_v02_sql;
        EXECUTE velora_v02_stmt;
        DEALLOCATE PREPARE velora_v02_stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS velora_v02_assert_zero$$
CREATE PROCEDURE velora_v02_assert_zero(IN p_count BIGINT, IN p_message VARCHAR(128))
BEGIN
    IF p_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = p_message;
    END IF;
END$$

DELIMITER ;

-- ---------------------------------------------------------------------------
-- 1. Trading-account bridge fields. Existing DECIMAL definitions are not
--    modified; only absent columns are added.
-- ---------------------------------------------------------------------------
CALL velora_v02_add_column('trading_accounts', 'provider',
  'ALTER TABLE trading_accounts ADD COLUMN provider ENUM(''MT4'',''MT5'',''MANUAL'') NOT NULL DEFAULT ''MANUAL''');
CALL velora_v02_add_column('trading_accounts', 'platform',
  'ALTER TABLE trading_accounts ADD COLUMN platform ENUM(''MT4'',''MT5'',''MANUAL'') NOT NULL DEFAULT ''MANUAL''');
CALL velora_v02_add_column('trading_accounts', 'broker',
  'ALTER TABLE trading_accounts ADD COLUMN broker VARCHAR(100) NULL');
CALL velora_v02_add_column('trading_accounts', 'server',
  'ALTER TABLE trading_accounts ADD COLUMN server VARCHAR(100) NULL');
CALL velora_v02_add_column('trading_accounts', 'mt_login',
  'ALTER TABLE trading_accounts ADD COLUMN mt_login VARCHAR(50) NULL');
CALL velora_v02_add_column('trading_accounts', 'account_type',
  'ALTER TABLE trading_accounts ADD COLUMN account_type VARCHAR(20) NOT NULL DEFAULT ''STANDARD''');
CALL velora_v02_add_column('trading_accounts', 'metaapi_account_id',
  'ALTER TABLE trading_accounts ADD COLUMN metaapi_account_id VARCHAR(64) NULL');
CALL velora_v02_add_column('trading_accounts', 'sync_status',
  'ALTER TABLE trading_accounts ADD COLUMN sync_status ENUM(''DISCONNECTED'',''CONNECTING'',''SYNCING'',''CONNECTED'',''ERROR'') NOT NULL DEFAULT ''DISCONNECTED''');
CALL velora_v02_add_column('trading_accounts', 'last_synced_at',
  'ALTER TABLE trading_accounts ADD COLUMN last_synced_at DATETIME NULL');
CALL velora_v02_add_column('trading_accounts', 'connection_credentials_encrypted',
  'ALTER TABLE trading_accounts ADD COLUMN connection_credentials_encrypted VARBINARY(2048) NULL');
CALL velora_v02_add_column('trading_accounts', 'connected_at',
  'ALTER TABLE trading_accounts ADD COLUMN connected_at DATETIME NULL');
CALL velora_v02_add_column('trading_accounts', 'disconnected_at',
  'ALTER TABLE trading_accounts ADD COLUMN disconnected_at DATETIME NULL');
CALL velora_v02_add_column('trading_accounts', 'auto_sync_enabled',
  'ALTER TABLE trading_accounts ADD COLUMN auto_sync_enabled TINYINT(1) NOT NULL DEFAULT 1');
CALL velora_v02_add_column('trading_accounts', 'last_incremental_at',
  'ALTER TABLE trading_accounts ADD COLUMN last_incremental_at DATETIME NULL');
CALL velora_v02_add_column('trading_accounts', 'connection_checked_at',
  'ALTER TABLE trading_accounts ADD COLUMN connection_checked_at DATETIME NULL');
CALL velora_v02_add_column('trading_accounts', 'consecutive_errors',
  'ALTER TABLE trading_accounts ADD COLUMN consecutive_errors TINYINT UNSIGNED NOT NULL DEFAULT 0');
CALL velora_v02_add_column('trading_accounts', 'last_error',
  'ALTER TABLE trading_accounts ADD COLUMN last_error VARCHAR(500) NULL');
CALL velora_v02_add_column('trading_accounts', 'dev_force_error',
  'ALTER TABLE trading_accounts ADD COLUMN dev_force_error TINYINT(1) NOT NULL DEFAULT 0');
CALL velora_v02_add_column('trading_accounts', 'starting_balance',
  'ALTER TABLE trading_accounts ADD COLUMN starting_balance DECIMAL(18,2) NOT NULL DEFAULT 0.00');
CALL velora_v02_add_column('trading_accounts', 'current_balance',
  'ALTER TABLE trading_accounts ADD COLUMN current_balance DECIMAL(18,2) NOT NULL DEFAULT 0.00');

-- All migration-owned duplicate checks and index creation occur together after
-- every legacy shape has been normalised. This prevents absent-column preflight
-- failures and ensures a blocker is reported before any data-bearing unique
-- index is added.

-- ---------------------------------------------------------------------------
-- 2. Durable, non-secret provider lifecycle journal.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS metaapi_operations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  operation_key CHAR(64) NOT NULL,
  provider_marker VARCHAR(64) NOT NULL,
  request_fingerprint CHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NULL,
  operation_type VARCHAR(16) NOT NULL DEFAULT 'CONNECT',
  status VARCHAR(40) NOT NULL DEFAULT 'PENDING',
  provider_account_id VARCHAR(64) NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error_code VARCHAR(64) NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Consolidate either historical queue shape into the canonical fenced shape.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  type ENUM('HISTORICAL','INCREMENTAL','WEBHOOK') NOT NULL DEFAULT 'HISTORICAL',
  status ENUM('PENDING','RUNNING','COMPLETED','FAILED','DEAD_LETTER') NOT NULL DEFAULT 'PENDING',
  payload JSON NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  locked_by VARCHAR(96) NULL,
  lease_token CHAR(64) NULL,
  dedupe_key VARCHAR(191) NULL,
  last_error TEXT NULL,
  range_from DATETIME NULL,
  range_to DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  dead_lettered_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL velora_v02_add_column('sync_jobs', 'user_id',
  'ALTER TABLE sync_jobs ADD COLUMN user_id BIGINT UNSIGNED NULL');
CALL velora_v02_add_column('sync_jobs', 'type',
  'ALTER TABLE sync_jobs ADD COLUMN type ENUM(''HISTORICAL'',''INCREMENTAL'',''WEBHOOK'') NOT NULL DEFAULT ''HISTORICAL''');
CALL velora_v02_add_column('sync_jobs', 'payload',
  'ALTER TABLE sync_jobs ADD COLUMN payload JSON NULL');
CALL velora_v02_add_column('sync_jobs', 'max_attempts',
  'ALTER TABLE sync_jobs ADD COLUMN max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5');
CALL velora_v02_add_column('sync_jobs', 'available_at',
  'ALTER TABLE sync_jobs ADD COLUMN available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
CALL velora_v02_add_column('sync_jobs', 'locked_at',
  'ALTER TABLE sync_jobs ADD COLUMN locked_at DATETIME NULL');
CALL velora_v02_add_column('sync_jobs', 'locked_by',
  'ALTER TABLE sync_jobs ADD COLUMN locked_by VARCHAR(96) NULL');
CALL velora_v02_add_column('sync_jobs', 'lease_token',
  'ALTER TABLE sync_jobs ADD COLUMN lease_token CHAR(64) NULL');
CALL velora_v02_add_column('sync_jobs', 'dedupe_key',
  'ALTER TABLE sync_jobs ADD COLUMN dedupe_key VARCHAR(191) NULL');
CALL velora_v02_add_column('sync_jobs', 'range_from',
  'ALTER TABLE sync_jobs ADD COLUMN range_from DATETIME NULL');
CALL velora_v02_add_column('sync_jobs', 'range_to',
  'ALTER TABLE sync_jobs ADD COLUMN range_to DATETIME NULL');
CALL velora_v02_add_column('sync_jobs', 'started_at',
  'ALTER TABLE sync_jobs ADD COLUMN started_at DATETIME NULL');
CALL velora_v02_add_column('sync_jobs', 'completed_at',
  'ALTER TABLE sync_jobs ADD COLUMN completed_at DATETIME NULL');
CALL velora_v02_add_column('sync_jobs', 'dead_lettered_at',
  'ALTER TABLE sync_jobs ADD COLUMN dead_lettered_at DATETIME NULL');
CALL velora_v02_add_column('sync_jobs', 'updated_at',
  'ALTER TABLE sync_jobs ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

-- Preserve historical queue intent, scheduling and completion data when
-- legacy columns are present. Dynamic guards keep this valid for either shape.
SET @velora_v02_has_job_type = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_jobs' AND COLUMN_NAME='job_type'
);
SET @velora_v02_sql = IF(@velora_v02_has_job_type > 0,
  'UPDATE sync_jobs SET type=CASE WHEN job_type=''LIVE_CATCHUP'' THEN ''INCREMENTAL'' ELSE ''HISTORICAL'' END',
  'DO 0');
PREPARE velora_v02_stmt FROM @velora_v02_sql;
EXECUTE velora_v02_stmt;
DEALLOCATE PREPARE velora_v02_stmt;

SET @velora_v02_has_sync_type = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_jobs' AND COLUMN_NAME='sync_type'
);
SET @velora_v02_sql = IF(@velora_v02_has_sync_type > 0,
  'UPDATE sync_jobs SET type=''INCREMENTAL'' WHERE sync_type IN (''AUTO'',''INCREMENTAL'',''LIVE_CATCHUP'')',
  'DO 0');
PREPARE velora_v02_stmt FROM @velora_v02_sql;
EXECUTE velora_v02_stmt;
DEALLOCATE PREPARE velora_v02_stmt;

SET @velora_v02_has_next_attempt = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_jobs' AND COLUMN_NAME='next_attempt_at'
);
SET @velora_v02_sql = IF(@velora_v02_has_next_attempt > 0,
  'UPDATE sync_jobs SET available_at=next_attempt_at WHERE next_attempt_at IS NOT NULL',
  'DO 0');
PREPARE velora_v02_stmt FROM @velora_v02_sql;
EXECUTE velora_v02_stmt;
DEALLOCATE PREPARE velora_v02_stmt;

SET @velora_v02_has_finished = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_jobs' AND COLUMN_NAME='finished_at'
);
SET @velora_v02_sql = IF(@velora_v02_has_finished > 0,
  'UPDATE sync_jobs SET completed_at=finished_at WHERE completed_at IS NULL AND finished_at IS NOT NULL',
  'DO 0');
PREPARE velora_v02_stmt FROM @velora_v02_sql;
EXECUTE velora_v02_stmt;
DEALLOCATE PREPARE velora_v02_stmt;

-- Existing jobs with no recorded owner inherit immutable ownership from their
-- account. Conflicting or orphaned ownership is not rewritten; it is checked in
-- the consolidated blocker phase below.
UPDATE sync_jobs sj
JOIN trading_accounts ta ON ta.id = sj.account_id
SET sj.user_id = ta.user_id
WHERE sj.user_id IS NULL;

-- Expand only a legacy enum containing DONE, map every legacy state, then
-- converge to the canonical enum. Workers are stopped before this migration,
-- so RUNNING rows have no valid owner and are safely returned to PENDING.
SET @velora_v02_status_type = (
  SELECT LOWER(COLUMN_TYPE) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_jobs' AND COLUMN_NAME='status'
);
SET @velora_v02_sql = IF(LOCATE('''done''', @velora_v02_status_type) > 0,
  'ALTER TABLE sync_jobs MODIFY COLUMN status ENUM(''PENDING'',''RUNNING'',''DONE'',''COMPLETED'',''FAILED'',''DEAD_LETTER'') NOT NULL DEFAULT ''PENDING''',
  'DO 0');
PREPARE velora_v02_stmt FROM @velora_v02_sql;
EXECUTE velora_v02_stmt;
DEALLOCATE PREPARE velora_v02_stmt;

UPDATE sync_jobs
SET status='COMPLETED', completed_at=COALESCE(completed_at, CURRENT_TIMESTAMP),
    locked_at=NULL, locked_by=NULL, lease_token=NULL, dedupe_key=NULL
WHERE status='DONE';
UPDATE sync_jobs
SET status='PENDING', available_at=COALESCE(available_at, CURRENT_TIMESTAMP),
    locked_at=NULL, locked_by=NULL, lease_token=NULL
WHERE status='RUNNING';
UPDATE sync_jobs
SET status='DEAD_LETTER', dead_lettered_at=COALESCE(dead_lettered_at, CURRENT_TIMESTAMP),
    completed_at=COALESCE(completed_at, CURRENT_TIMESTAMP),
    locked_at=NULL, locked_by=NULL, lease_token=NULL, dedupe_key=NULL
WHERE status='FAILED' AND attempts >= max_attempts;
UPDATE sync_jobs
SET status='PENDING', available_at=COALESCE(available_at, CURRENT_TIMESTAMP),
    locked_at=NULL, locked_by=NULL, lease_token=NULL
WHERE status='FAILED' AND attempts < max_attempts;

SET @velora_v02_status_type = (
  SELECT LOWER(COLUMN_TYPE) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_jobs' AND COLUMN_NAME='status'
);
SET @velora_v02_sql = IF(
  @velora_v02_status_type <> 'enum(''pending'',''running'',''completed'',''failed'',''dead_letter'')',
  'ALTER TABLE sync_jobs MODIFY COLUMN status ENUM(''PENDING'',''RUNNING'',''COMPLETED'',''FAILED'',''DEAD_LETTER'') NOT NULL DEFAULT ''PENDING''',
  'DO 0');
PREPARE velora_v02_stmt FROM @velora_v02_sql;
EXECUTE velora_v02_stmt;
DEALLOCATE PREPARE velora_v02_stmt;

-- Completed and dead-lettered jobs must not retain an active-intent key. Active
-- keys are assigned only after every migration-owned blocker has passed.
UPDATE sync_jobs SET dedupe_key=NULL WHERE status NOT IN ('PENDING','RUNNING');

-- ---------------------------------------------------------------------------
-- 4. Durable webhook event/replay uniqueness and a fenced processing lease.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_key CHAR(64) NULL,
  account_id BIGINT UNSIGNED NULL,
  metaapi_account_id VARCHAR(64) NULL,
  event_type VARCHAR(50) NOT NULL,
  payload JSON NOT NULL,
  hmac_verified TINYINT(1) NOT NULL DEFAULT 0,
  processed TINYINT(1) NOT NULL DEFAULT 0,
  processing_token CHAR(64) NULL,
  processing_started_at DATETIME NULL,
  last_error VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL velora_v02_add_column('webhook_events', 'event_key',
  'ALTER TABLE webhook_events ADD COLUMN event_key CHAR(64) NULL');
CALL velora_v02_add_column('webhook_events', 'metaapi_account_id',
  'ALTER TABLE webhook_events ADD COLUMN metaapi_account_id VARCHAR(64) NULL');
CALL velora_v02_add_column('webhook_events', 'hmac_verified',
  'ALTER TABLE webhook_events ADD COLUMN hmac_verified TINYINT(1) NOT NULL DEFAULT 0');
CALL velora_v02_add_column('webhook_events', 'processing_token',
  'ALTER TABLE webhook_events ADD COLUMN processing_token CHAR(64) NULL');
CALL velora_v02_add_column('webhook_events', 'processing_started_at',
  'ALTER TABLE webhook_events ADD COLUMN processing_started_at DATETIME NULL');
CALL velora_v02_add_column('webhook_events', 'last_error',
  'ALTER TABLE webhook_events ADD COLUMN last_error VARCHAR(64) NULL');
CALL velora_v02_add_column('webhook_events', 'processed_at',
  'ALTER TABLE webhook_events ADD COLUMN processed_at DATETIME NULL');

-- Historical rows did not carry a replay key. A deterministic per-row legacy
-- key preserves every row without pretending old payloads were deduplicated.
UPDATE webhook_events SET event_key=SHA2(CONCAT('legacy:', id), 256) WHERE event_key IS NULL;

-- ---------------------------------------------------------------------------
-- 5. External trade idempotency prerequisite.
-- ---------------------------------------------------------------------------
CALL velora_v02_add_column('trades', 'external_deal_id',
  'ALTER TABLE trades ADD COLUMN external_deal_id VARCHAR(64) NULL');

-- ---------------------------------------------------------------------------
-- 6. Consolidated blocker phase.
--
-- Every target table and column now exists and legacy rows have been normalised.
-- All migration-owned duplicate and ownership checks run before ALTER adds any
-- unique index to existing data. A blocker stops without choosing, merging,
-- reassigning or deleting a winner.
-- ---------------------------------------------------------------------------
SET @velora_v02_dupes = (
  SELECT COUNT(*) FROM (
    SELECT metaapi_account_id FROM trading_accounts
    WHERE metaapi_account_id IS NOT NULL
    GROUP BY metaapi_account_id HAVING COUNT(*) > 1
  ) AS duplicate_metaapi_ids
);
CALL velora_v02_assert_zero(@velora_v02_dupes, 'BLOCKER: duplicate metaapi_account_id values');

SET @velora_v02_dupes = (
  SELECT COUNT(*) FROM (
    SELECT user_id, platform, server, mt_login FROM trading_accounts
    WHERE user_id IS NOT NULL AND platform IS NOT NULL
      AND server IS NOT NULL AND mt_login IS NOT NULL
    GROUP BY user_id, platform, server, mt_login HAVING COUNT(*) > 1
  ) AS duplicate_connections
);
CALL velora_v02_assert_zero(@velora_v02_dupes, 'BLOCKER: duplicate account connection identities');

SET @velora_v02_dupes = (
  SELECT COUNT(*) FROM (
    SELECT operation_key FROM metaapi_operations
    GROUP BY operation_key HAVING COUNT(*) > 1
  ) AS duplicate_operation_keys
);
CALL velora_v02_assert_zero(@velora_v02_dupes, 'BLOCKER: duplicate MetaApi operation keys');

SET @velora_v02_dupes = (
  SELECT COUNT(*) FROM (
    SELECT provider_marker FROM metaapi_operations
    GROUP BY provider_marker HAVING COUNT(*) > 1
  ) AS duplicate_provider_markers
);
CALL velora_v02_assert_zero(@velora_v02_dupes, 'BLOCKER: duplicate MetaApi provider markers');

SET @velora_v02_dupes = (
  SELECT COUNT(*) FROM (
    SELECT request_fingerprint FROM metaapi_operations
    GROUP BY request_fingerprint HAVING COUNT(*) > 1
  ) AS duplicate_request_fingerprints
);
CALL velora_v02_assert_zero(@velora_v02_dupes, 'BLOCKER: duplicate MetaApi request fingerprints');

SET @velora_v02_orphans = (
  SELECT COUNT(*)
  FROM metaapi_operations mo
  LEFT JOIN users u ON u.id = mo.user_id
  LEFT JOIN trading_accounts ta ON ta.id = mo.account_id
  WHERE u.id IS NULL OR (mo.account_id IS NOT NULL AND ta.id IS NULL)
     OR (mo.account_id IS NOT NULL AND ta.user_id <> mo.user_id)
);
CALL velora_v02_assert_zero(@velora_v02_orphans, 'BLOCKER: orphan or conflicting MetaApi operation ownership');

SET @velora_v02_orphans = (
  SELECT COUNT(*)
  FROM sync_jobs sj
  LEFT JOIN trading_accounts ta ON ta.id = sj.account_id
  LEFT JOIN users u ON u.id = sj.user_id
  WHERE ta.id IS NULL OR sj.user_id IS NULL OR u.id IS NULL OR ta.user_id <> sj.user_id
);
CALL velora_v02_assert_zero(@velora_v02_orphans, 'BLOCKER: orphan or conflicting sync job ownership');

SET @velora_v02_dupes = (
  SELECT COUNT(*) FROM (
    SELECT account_id FROM sync_jobs
    WHERE status IN ('PENDING','RUNNING')
    GROUP BY account_id HAVING COUNT(*) > 1
  ) AS duplicate_active_sync_intents
);
CALL velora_v02_assert_zero(@velora_v02_dupes, 'BLOCKER: duplicate active sync intents');

SET @velora_v02_dupes = (
  SELECT COUNT(*) FROM (
    SELECT lease_token FROM sync_jobs
    WHERE lease_token IS NOT NULL
    GROUP BY lease_token HAVING COUNT(*) > 1
  ) AS duplicate_sync_lease_tokens
);
CALL velora_v02_assert_zero(@velora_v02_dupes, 'BLOCKER: duplicate sync lease tokens');

SET @velora_v02_dupes = (
  SELECT COUNT(*) FROM (
    SELECT event_key FROM webhook_events
    GROUP BY event_key HAVING COUNT(*) > 1
  ) AS duplicate_webhook_event_keys
);
CALL velora_v02_assert_zero(@velora_v02_dupes, 'BLOCKER: duplicate webhook event keys');

SET @velora_v02_dupes = (
  SELECT COUNT(*) FROM (
    SELECT processing_token FROM webhook_events
    WHERE processing_token IS NOT NULL
    GROUP BY processing_token HAVING COUNT(*) > 1
  ) AS duplicate_webhook_processing_tokens
);
CALL velora_v02_assert_zero(@velora_v02_dupes, 'BLOCKER: duplicate webhook processing tokens');

SET @velora_v02_orphans = (
  SELECT COUNT(*) FROM webhook_events we
  LEFT JOIN trading_accounts ta ON ta.id = we.account_id
  WHERE we.account_id IS NOT NULL AND ta.id IS NULL
);
CALL velora_v02_assert_zero(@velora_v02_orphans, 'BLOCKER: orphan webhook account ownership');

SET @velora_v02_dupes = (
  SELECT COUNT(*) FROM (
    SELECT account_id, external_deal_id FROM trades
    WHERE account_id IS NOT NULL AND external_deal_id IS NOT NULL
    GROUP BY account_id, external_deal_id HAVING COUNT(*) > 1
  ) AS duplicate_external_deals
);
CALL velora_v02_assert_zero(@velora_v02_dupes, 'BLOCKER: duplicate external trade deal identities');

SET @velora_v02_oversize = (
  SELECT COUNT(*) FROM webhook_events WHERE CHAR_LENGTH(event_key) > 64
);
CALL velora_v02_assert_zero(@velora_v02_oversize, 'BLOCKER: webhook event key exceeds 64 characters');

-- ---------------------------------------------------------------------------
-- 7. Final schema convergence. This phase is reached only after every blocker
-- above passes. Deterministic active-intent keys are assigned without deleting
-- rows, and every index/constraint helper validates conflicting same-name DDL.
-- ---------------------------------------------------------------------------
UPDATE sync_jobs
SET dedupe_key=CONCAT('metaapi-sync:', account_id)
WHERE status IN ('PENDING','RUNNING');

SET @velora_v02_user_nullable = (
  SELECT IS_NULLABLE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_jobs' AND COLUMN_NAME='user_id'
);
SET @velora_v02_sql = IF(@velora_v02_user_nullable = 'YES',
  'ALTER TABLE sync_jobs MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL',
  'DO 0');
PREPARE velora_v02_stmt FROM @velora_v02_sql;
EXECUTE velora_v02_stmt;
DEALLOCATE PREPARE velora_v02_stmt;

SET @velora_v02_event_key_definition = (
  SELECT CONCAT(LOWER(COLUMN_TYPE), ':', IS_NULLABLE)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='webhook_events' AND COLUMN_NAME='event_key'
);
SET @velora_v02_sql = IF(@velora_v02_event_key_definition <> 'char(64):NO',
  'ALTER TABLE webhook_events MODIFY COLUMN event_key CHAR(64) NOT NULL',
  'DO 0');
PREPARE velora_v02_stmt FROM @velora_v02_sql;
EXECUTE velora_v02_stmt;
DEALLOCATE PREPARE velora_v02_stmt;

CALL velora_v02_add_index('trading_accounts', 'uq_accounts_metaapi', 'metaapi_account_id', 1,
  'ALTER TABLE trading_accounts ADD UNIQUE INDEX uq_accounts_metaapi (metaapi_account_id)');
CALL velora_v02_add_index('trading_accounts', 'uq_accounts_connection', 'user_id,platform,server,mt_login', 1,
  'ALTER TABLE trading_accounts ADD UNIQUE INDEX uq_accounts_connection (user_id, platform, server, mt_login)');
CALL velora_v02_add_index('trading_accounts', 'idx_accounts_user_sync', 'user_id,sync_status', 0,
  'ALTER TABLE trading_accounts ADD INDEX idx_accounts_user_sync (user_id, sync_status)');

CALL velora_v02_add_index('metaapi_operations', 'uq_metaapi_operation_key', 'operation_key', 1,
  'ALTER TABLE metaapi_operations ADD UNIQUE INDEX uq_metaapi_operation_key (operation_key)');
CALL velora_v02_add_index('metaapi_operations', 'uq_metaapi_provider_marker', 'provider_marker', 1,
  'ALTER TABLE metaapi_operations ADD UNIQUE INDEX uq_metaapi_provider_marker (provider_marker)');
CALL velora_v02_add_index('metaapi_operations', 'uq_metaapi_request_fingerprint', 'request_fingerprint', 1,
  'ALTER TABLE metaapi_operations ADD UNIQUE INDEX uq_metaapi_request_fingerprint (request_fingerprint)');
CALL velora_v02_add_index('metaapi_operations', 'idx_metaapi_operation_account', 'account_id', 0,
  'ALTER TABLE metaapi_operations ADD INDEX idx_metaapi_operation_account (account_id)');
CALL velora_v02_add_index('metaapi_operations', 'idx_metaapi_operation_user_status', 'user_id,status', 0,
  'ALTER TABLE metaapi_operations ADD INDEX idx_metaapi_operation_user_status (user_id, status)');

CALL velora_v02_add_index('sync_jobs', 'uq_sync_dedupe', 'dedupe_key', 1,
  'ALTER TABLE sync_jobs ADD UNIQUE INDEX uq_sync_dedupe (dedupe_key)');
CALL velora_v02_add_index('sync_jobs', 'uq_sync_lease', 'lease_token', 1,
  'ALTER TABLE sync_jobs ADD UNIQUE INDEX uq_sync_lease (lease_token)');
CALL velora_v02_add_index('sync_jobs', 'idx_sync_claim', 'status,available_at,id', 0,
  'ALTER TABLE sync_jobs ADD INDEX idx_sync_claim (status, available_at, id)');
CALL velora_v02_add_index('sync_jobs', 'idx_sync_stale', 'status,locked_at', 0,
  'ALTER TABLE sync_jobs ADD INDEX idx_sync_stale (status, locked_at)');
CALL velora_v02_add_index('sync_jobs', 'idx_sync_account_created', 'account_id,created_at', 0,
  'ALTER TABLE sync_jobs ADD INDEX idx_sync_account_created (account_id, created_at)');

CALL velora_v02_add_index('webhook_events', 'uq_webhook_event_key', 'event_key', 1,
  'ALTER TABLE webhook_events ADD UNIQUE INDEX uq_webhook_event_key (event_key)');
CALL velora_v02_add_index('webhook_events', 'uq_webhook_processing_token', 'processing_token', 1,
  'ALTER TABLE webhook_events ADD UNIQUE INDEX uq_webhook_processing_token (processing_token)');
CALL velora_v02_add_index('webhook_events', 'idx_webhook_account', 'account_id', 0,
  'ALTER TABLE webhook_events ADD INDEX idx_webhook_account (account_id)');
CALL velora_v02_add_index('webhook_events', 'idx_webhook_type', 'event_type', 0,
  'ALTER TABLE webhook_events ADD INDEX idx_webhook_type (event_type)');
CALL velora_v02_add_index('webhook_events', 'idx_webhook_created', 'created_at', 0,
  'ALTER TABLE webhook_events ADD INDEX idx_webhook_created (created_at)');

CALL velora_v02_add_index('trades', 'uq_trades_external_deal', 'account_id,external_deal_id', 1,
  'ALTER TABLE trades ADD UNIQUE INDEX uq_trades_external_deal (account_id, external_deal_id)');
CALL velora_v02_add_index('trades', 'idx_user_account_time', 'user_id,account_id,close_time', 0,
  'ALTER TABLE trades ADD INDEX idx_user_account_time (user_id, account_id, close_time)');

CALL velora_v02_add_constraint('metaapi_operations', 'user_id', 'fk_metaapi_operation_user',
  'users', 'id', 'CASCADE', 'NO ACTION',
  'ALTER TABLE metaapi_operations ADD CONSTRAINT fk_metaapi_operation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
CALL velora_v02_add_constraint('metaapi_operations', 'account_id', 'fk_metaapi_operation_account',
  'trading_accounts', 'id', 'SET NULL', 'NO ACTION',
  'ALTER TABLE metaapi_operations ADD CONSTRAINT fk_metaapi_operation_account FOREIGN KEY (account_id) REFERENCES trading_accounts(id) ON DELETE SET NULL');
CALL velora_v02_add_constraint('sync_jobs', 'account_id', 'fk_sync_account',
  'trading_accounts', 'id', 'CASCADE', 'NO ACTION',
  'ALTER TABLE sync_jobs ADD CONSTRAINT fk_sync_account FOREIGN KEY (account_id) REFERENCES trading_accounts(id) ON DELETE CASCADE');
CALL velora_v02_add_constraint('sync_jobs', 'user_id', 'fk_sync_user',
  'users', 'id', 'CASCADE', 'NO ACTION',
  'ALTER TABLE sync_jobs ADD CONSTRAINT fk_sync_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
CALL velora_v02_add_constraint('webhook_events', 'account_id', 'fk_webhook_account',
  'trading_accounts', 'id', 'SET NULL', 'NO ACTION',
  'ALTER TABLE webhook_events ADD CONSTRAINT fk_webhook_account FOREIGN KEY (account_id) REFERENCES trading_accounts(id) ON DELETE SET NULL');

DROP PROCEDURE IF EXISTS velora_v02_add_column;
DROP PROCEDURE IF EXISTS velora_v02_add_index;
DROP PROCEDURE IF EXISTS velora_v02_add_constraint;
DROP PROCEDURE IF EXISTS velora_v02_assert_zero;
SET @velora_v02_sql = NULL;
SET @velora_v02_dupes = NULL;
SET @velora_v02_orphans = NULL;
SET @velora_v02_oversize = NULL;
SET @velora_v02_user_nullable = NULL;
SET @velora_v02_event_key_definition = NULL;
