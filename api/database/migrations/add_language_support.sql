-- VELORA dynamic-content localization pipeline (MySQL 8+)
--
-- This schema deliberately does not attach UI locale to users, trades, prices,
-- statistics, symbols or live API payloads. Original content is stored by its
-- source locale; reusable translations are versioned by source_hash and created
-- asynchronously by a CLI worker.

CREATE TABLE IF NOT EXISTS content_translation_cache (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    content_type VARCHAR(64) NOT NULL,
    content_id VARCHAR(191) NOT NULL,
    source_locale VARCHAR(35) NOT NULL,
    target_locale VARCHAR(35) NOT NULL,
    source_hash VARCHAR(128) NOT NULL,
    translated_fields JSON NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'ready',
    provider VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_content_translation_version (content_type, content_id, source_hash, target_locale),
    KEY idx_translation_lookup (target_locale, status, content_type, content_id),
    KEY idx_translation_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_translation_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    content_type VARCHAR(64) NOT NULL,
    content_id VARCHAR(191) NOT NULL,
    source_locale VARCHAR(35) NOT NULL,
    target_locale VARCHAR(35) NOT NULL,
    source_hash VARCHAR(128) NOT NULL,
    source_fields JSON NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    locked_by VARCHAR(96) NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_translation_job_version (content_type, content_id, source_hash, target_locale),
    KEY idx_translation_job_claim (status, available_at, id),
    KEY idx_translation_job_lock (status, locked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Queue insertion belongs in content ingestion/import code, not in a page request:
-- INSERT INTO content_translation_jobs
--   (content_type, content_id, source_locale, target_locale, source_hash, source_fields)
-- VALUES
--   ('news', 'provider-item-id', 'en', 'fa', '<sha256>', JSON_OBJECT('title', '...', 'summary', '...'));
--
-- Worker:
--   php api/workers/content_translation_worker.php --max=20
--
-- Cache-only browser lookup:
--   POST /api/v1/content-translations/lookup
-- A miss returns immediately and never calls/enqueues a provider.
