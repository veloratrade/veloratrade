-- VELORA v1.1 — MetaApi durable fill/deal ledger (Phase 5, Objective A)
--
-- Additive, idempotent, non-destructive. Targets MySQL 8.0 / MariaDB.
-- PURPOSE: give realtime webhook fills and historical-sync fills a durable,
-- normalized home so IN and OUT fills can be paired ACROSS separate events,
-- workers, retries, restarts and out-of-order delivery (closes the Phase 4A
-- "realtime cross-event pairing blocked by data model" finding).
--
-- DESIGN:
--   * One row per external FILL (deal). Fill identity = (account_id,
--     external_deal_id) — a UNIQUE constraint makes every fill processed once.
--   * Position aggregation key = (account_id, position_id). A closed trade is
--     derived by MetaApiDealAssembler over a position's ledgered fills.
--   * Canonical instant columns (occurred_at_utc) are populated ONLY from
--     offset-explicit MetaApi `time` (via MetaApiInstantResolver). Naive
--     brokerTime is stored as evidence only (broker_time_text) and NEVER used
--     to derive an instant. time_status mirrors the canonical model.
--   * processing_state tracks the fill -> trade reconciliation lifecycle.
--
-- This migration does NOT touch trades.* semantics, does not backfill, and is
-- NOT run automatically by application deploy. Run manually after a protected
-- backup + preflight (see v0.2 runbook). Rollback: v1.1_metaapi_fill_ledger_rollback.sql.

CREATE TABLE IF NOT EXISTS metaapi_fills (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id           BIGINT UNSIGNED NOT NULL,
    user_id              BIGINT UNSIGNED NOT NULL,
    external_deal_id     VARCHAR(64)     NOT NULL,
    position_id          VARCHAR(64)     NULL,
    order_id             VARCHAR(64)     NULL,
    entry_type           VARCHAR(16)     NULL,                 -- in | out
    direction            VARCHAR(8)      NULL,                 -- buy | sell
    symbol               VARCHAR(32)     NULL,
    volume               DECIMAL(18,8)   NULL,
    price                DECIMAL(18,8)   NULL,
    profit               DECIMAL(24,8)   NULL,
    commission           DECIMAL(18,8)   NULL,                 -- normalized cost (signed)
    swap                 DECIMAL(18,8)   NULL,                 -- normalized cost (signed)
    occurred_at_utc      DATETIME        NULL,                 -- canonical instant, offset-explicit time only
    time_status          VARCHAR(16)     NOT NULL DEFAULT 'unresolved',
    raw_time_text        VARCHAR(64)     NULL,                 -- verbatim absolute `time`
    broker_time_text     VARCHAR(64)     NULL,                 -- naive brokerTime, evidence only
    ingestion_source     VARCHAR(16)     NOT NULL DEFAULT 'unknown', -- historical | webhook
    event_ref            CHAR(64)        NULL,                 -- webhook_events.event_key when from webhook
    processing_state     VARCHAR(16)     NOT NULL DEFAULT 'received',  -- received | aggregated | skipped | rejected
    processed_trade_id   BIGINT UNSIGNED NULL,
    skip_reason         VARCHAR(48)     NULL,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_metaapi_fills_deal (account_id, external_deal_id),
    KEY idx_metaapi_fills_position (account_id, position_id, processing_state),
    KEY idx_metaapi_fills_state (processing_state),
    KEY idx_metaapi_fills_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
