-- VELORA — add_user_locale_preference (PR-03)
--
-- Persist the user's UI language preference next to the existing timezone
-- column. Schema-only; no user data is migrated. Run once, manually, against
-- MySQL/MariaDB — see docs/README.md (NP-3): inspect the live schema and run
-- on a non-production environment first. Production execution is a separate,
-- explicitly owner-approved operation and is NOT part of the PR that ships
-- this file.
--
-- Idempotent: each ADD COLUMN is guarded by an information_schema check, so
-- re-running is safe (a no-op for columns that already exist).
--
-- Column contract (mirrored in api/database/schema.sql):
--   locale            VARCHAR(35) NOT NULL DEFAULT 'fa'      -- manifest locale code (fa/en)
--   locale_source     VARCHAR(16) NOT NULL DEFAULT 'default' -- default|browser|cookie|user
--   locale_updated_at DATETIME NULL                          -- last explicit preference write (UTC)
--
-- Rollback (only if these columns are not consumed anywhere else):
--   ALTER TABLE users
--     DROP COLUMN locale_updated_at,
--     DROP COLUMN locale_source,
--     DROP COLUMN locale;

SET @velora_add_locale = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'locale') = 0,
    'ALTER TABLE users ADD COLUMN locale VARCHAR(35) NOT NULL DEFAULT ''fa'' AFTER timezone',
    'SELECT 1'
);
PREPARE velora_stmt FROM @velora_add_locale;
EXECUTE velora_stmt;
DEALLOCATE PREPARE velora_stmt;

SET @velora_add_locale_source = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'locale_source') = 0,
    'ALTER TABLE users ADD COLUMN locale_source VARCHAR(16) NOT NULL DEFAULT ''default'' AFTER locale',
    'SELECT 1'
);
PREPARE velora_stmt FROM @velora_add_locale_source;
EXECUTE velora_stmt;
DEALLOCATE PREPARE velora_stmt;

SET @velora_add_locale_updated_at = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'locale_updated_at') = 0,
    'ALTER TABLE users ADD COLUMN locale_updated_at DATETIME NULL AFTER locale_source',
    'SELECT 1'
);
PREPARE velora_stmt FROM @velora_add_locale_updated_at;
EXECUTE velora_stmt;
DEALLOCATE PREPARE velora_stmt;
