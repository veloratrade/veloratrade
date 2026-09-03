<?php
// Initialize SQLite database for local test
$dbFile = __DIR__ . '/storage/velora.sqlite';
$dir = dirname($dbFile);
if (!is_dir($dir)) mkdir($dir, 0777, true);

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON;');

// Create tables
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    full_name TEXT NOT NULL DEFAULT '',
    role TEXT NOT NULL DEFAULT 'user',
    timezone TEXT NOT NULL DEFAULT 'UTC',
    locale TEXT NOT NULL DEFAULT 'fa',
    locale_source TEXT NOT NULL DEFAULT 'default',
    locale_updated_at DATETIME NULL,
    ai_consent_at DATETIME NULL,
    plan TEXT NOT NULL DEFAULT 'free',
    subscription_status TEXT NOT NULL DEFAULT 'none',
    plan_started_at DATETIME NULL,
    plan_expires_at DATETIME NULL,
    plan_updated_at DATETIME NULL,
    status TEXT NOT NULL DEFAULT 'active',
    email_verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    refresh_token_hash TEXT NOT NULL UNIQUE,
    access_token_hash TEXT NOT NULL,
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS trading_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    provider TEXT NOT NULL DEFAULT 'MANUAL',
    platform TEXT NOT NULL DEFAULT 'MANUAL',
    broker TEXT NULL,
    server TEXT NULL,
    timezone TEXT NULL,
    timezone_source TEXT NOT NULL DEFAULT 'unknown',
    mt_login TEXT NULL,
    account_type TEXT NOT NULL DEFAULT 'STANDARD',
    metaapi_account_id TEXT NULL,
    sync_status TEXT NOT NULL DEFAULT 'DISCONNECTED',
    last_synced_at DATETIME NULL,
    connection_credentials_encrypted BLOB NULL,
    connected_at DATETIME NULL,
    disconnected_at DATETIME NULL,
    auto_sync_enabled INTEGER NOT NULL DEFAULT 1,
    last_incremental_at DATETIME NULL,
    connection_checked_at DATETIME NULL,
    consecutive_errors INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    dev_force_error INTEGER NOT NULL DEFAULT 0,
    starting_balance REAL NOT NULL DEFAULT 0.00,
    current_balance REAL NOT NULL DEFAULT 0.00,
    label TEXT NOT NULL DEFAULT '',
    account_number_masked TEXT NOT NULL DEFAULT '',
    currency TEXT NOT NULL DEFAULT 'USD',
    leverage TEXT NULL,
    status TEXT NOT NULL DEFAULT 'disconnected',
    balance REAL NOT NULL DEFAULT 0.00,
    equity REAL NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_accounts_metaapi ON trading_accounts (metaapi_account_id) WHERE metaapi_account_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_accounts_user ON trading_accounts (user_id);
CREATE INDEX IF NOT EXISTS idx_accounts_user_sync ON trading_accounts (user_id, sync_status);

CREATE TABLE IF NOT EXISTS trades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    account_id INTEGER NULL,
    external_deal_id TEXT NULL,
    symbol TEXT NOT NULL,
    direction TEXT NOT NULL,
    entry_price REAL NOT NULL,
    exit_price REAL NOT NULL,
    volume REAL NOT NULL,
    contract_size REAL NOT NULL DEFAULT 1.00,
    commission REAL NOT NULL DEFAULT 0.00,
    swap REAL NOT NULL DEFAULT 0.00,
    profit_loss REAL NOT NULL,
    r_multiple REAL NULL,
    stop_loss REAL NULL,
    take_profit REAL NULL,
    open_time DATETIME NOT NULL,
    close_time DATETIME NOT NULL,
    strategy_tag TEXT NULL,
    emotional_score INTEGER NULL,
    notes TEXT NULL,
    source TEXT NOT NULL DEFAULT 'manual',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES trading_accounts(id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_trades_external_deal ON trades (account_id, external_deal_id) WHERE external_deal_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_trades_user ON trades (user_id);

CREATE TABLE IF NOT EXISTS trade_exits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    trade_id INTEGER NOT NULL,
    exit_type TEXT NOT NULL DEFAULT 'manual',
    exit_price REAL NOT NULL,
    volume REAL NOT NULL,
    pnl REAL NOT NULL DEFAULT 0.00,
    exited_at DATETIME NOT NULL,
    notes TEXT NULL,
    FOREIGN KEY (trade_id) REFERENCES trades(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket TEXT NOT NULL PRIMARY KEY,
    hits INTEGER NOT NULL DEFAULT 1,
    window_start DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS content_translation_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_type TEXT NOT NULL,
    content_id TEXT NOT NULL,
    source_locale TEXT NOT NULL,
    target_locale TEXT NOT NULL,
    source_hash TEXT NOT NULL,
    translated_fields TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'ready',
    provider TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(content_type, content_id, source_hash, target_locale)
);
CREATE INDEX IF NOT EXISTS idx_translation_lookup ON content_translation_cache(target_locale, status, content_type, content_id);

CREATE TABLE IF NOT EXISTS content_translation_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_type TEXT NOT NULL,
    content_id TEXT NOT NULL,
    source_locale TEXT NOT NULL,
    target_locale TEXT NOT NULL,
    source_hash TEXT NOT NULL,
    source_fields TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    locked_by TEXT NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(content_type, content_id, source_hash, target_locale)
);
CREATE INDEX IF NOT EXISTS idx_translation_job_claim ON content_translation_jobs(status, available_at, id);

CREATE TABLE IF NOT EXISTS email_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    recipient_email TEXT NOT NULL,
    subject TEXT NOT NULL,
    payload_json TEXT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    sent_at DATETIME NULL,
    failed_at DATETIME NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS email_preferences (
    user_id INTEGER PRIMARY KEY,
    welcome_email INTEGER NOT NULL DEFAULT 1,
    security_alerts INTEGER NOT NULL DEFAULT 1,
    trade_notifications INTEGER NOT NULL DEFAULT 1,
    weekly_report INTEGER NOT NULL DEFAULT 1,
    monthly_report INTEGER NOT NULL DEFAULT 1,
    achievement_notifications INTEGER NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS email_verifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_achievements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    achievement_key TEXT NOT NULL,
    achieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    metadata_json TEXT NULL,
    UNIQUE(user_id, achievement_key)
);

CREATE TABLE IF NOT EXISTS user_devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    fingerprint TEXT NOT NULL,
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS password_resets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ai_extractions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    provider TEXT NOT NULL DEFAULT 'gemini',
    image_hash TEXT NOT NULL,
    original_result TEXT NULL,
    final_result TEXT NULL,
    confidence REAL NOT NULL DEFAULT 0.0,
    latency_ms INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'success',
    error_code TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ai_provider_quotas (
    provider TEXT NOT NULL PRIMARY KEY,
    daily_used INTEGER NOT NULL DEFAULT 0,
    quota_limit INTEGER NOT NULL DEFAULT 1500,
    reset_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ai_provider_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider TEXT NOT NULL,
    status TEXT NOT NULL,
    latency_ms INTEGER NOT NULL DEFAULT 0,
    error_code TEXT NULL,
    feature TEXT NULL,
    model TEXT NULL,
    route TEXT NULL,
    fallback_index INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ai_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    feature TEXT NOT NULL DEFAULT 'extraction',
    provider TEXT NOT NULL DEFAULT 'gemini',
    model TEXT NOT NULL DEFAULT 'gemini-1.5-flash',
    prompt_hash TEXT NOT NULL,
    tokens_used INTEGER NOT NULL DEFAULT 0,
    latency_ms INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'success',
    cost REAL NOT NULL DEFAULT 0.000000,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ai_feature_flags (
    feature_name TEXT NOT NULL PRIMARY KEY,
    enabled INTEGER NOT NULL DEFAULT 0,
    rollout_percentage INTEGER NOT NULL DEFAULT 0,
    updated_by INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ai_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    feature TEXT NOT NULL DEFAULT 'extraction',
    provider TEXT NOT NULL DEFAULT 'gemini',
    image_hash TEXT NOT NULL,
    action TEXT NOT NULL DEFAULT 'extraction',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ai_feedback (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    extraction_id INTEGER NULL,
    original_result TEXT NULL,
    corrected_result TEXT NULL,
    changed_fields TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (extraction_id) REFERENCES ai_extractions(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS ai_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    job_type TEXT NOT NULL,
    payload TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ai_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    period_start TEXT NOT NULL,
    period_end TEXT NOT NULL,
    locale TEXT NOT NULL DEFAULT 'en',
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, period_start, period_end, locale),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ai_analysis (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    provider TEXT NOT NULL DEFAULT 'gemini',
    model TEXT NOT NULL DEFAULT 'gemini-1.5-flash',
    result_json TEXT NOT NULL,
    confidence REAL NOT NULL DEFAULT 0.0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ai_feature_providers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    feature TEXT NOT NULL,
    provider TEXT NOT NULL,
    model TEXT NULL,
    priority INTEGER NOT NULL DEFAULT 1,
    enabled INTEGER NOT NULL DEFAULT 1,
    route TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(feature, provider)
);

CREATE TABLE IF NOT EXISTS ai_provider_credentials (
    provider TEXT NOT NULL PRIMARY KEY,
    status TEXT NOT NULL DEFAULT 'UNVERIFIED',
    verified INTEGER NOT NULL DEFAULT 0,
    fingerprint TEXT NULL,
    verified_at DATETIME NULL,
    last_checked_at DATETIME NULL,
    error_code TEXT NULL,
    latency_ms INTEGER NOT NULL DEFAULT 0,
    version INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id INTEGER NOT NULL,
    actor_role TEXT NOT NULL DEFAULT 'admin',
    action TEXT NOT NULL,
    target_type TEXT NOT NULL DEFAULT 'user',
    target_id INTEGER NULL,
    result TEXT NOT NULL DEFAULT 'success',
    summary TEXT NULL,
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    context_id TEXT NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_actor ON admin_audit_logs(actor_user_id);
CREATE INDEX IF NOT EXISTS idx_audit_action ON admin_audit_logs(action);
CREATE INDEX IF NOT EXISTS idx_audit_created ON admin_audit_logs(created_at);

CREATE TABLE IF NOT EXISTS system_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    severity TEXT NOT NULL DEFAULT 'INFO',
    source TEXT NOT NULL,
    message TEXT NULL,
    request_id TEXT NULL,
    correlation_id TEXT NULL,
    user_id INTEGER NULL,
    error_code TEXT NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_syslog_severity ON system_logs(severity);
CREATE INDEX IF NOT EXISTS idx_syslog_source ON system_logs(source);
CREATE INDEX IF NOT EXISTS idx_syslog_created ON system_logs(created_at);

CREATE TABLE IF NOT EXISTS integration_health (
    integration TEXT PRIMARY KEY,
    status TEXT NOT NULL,
    latency_ms INTEGER NULL,
    error_code TEXT NULL,
    message TEXT NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS metaapi_fills (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    external_deal_id TEXT NOT NULL,
    position_id TEXT NULL,
    order_id TEXT NULL,
    entry_type TEXT NULL,
    direction TEXT NULL,
    symbol TEXT NULL,
    volume REAL NULL,
    price REAL NULL,
    profit REAL NULL,
    commission REAL NULL,
    swap REAL NULL,
    occurred_at_utc DATETIME NULL,
    time_status TEXT NOT NULL DEFAULT 'unresolved',
    raw_time_text TEXT NULL,
    broker_time_text TEXT NULL,
    ingestion_source TEXT NOT NULL DEFAULT 'unknown',
    event_ref TEXT NULL,
    processing_state TEXT NOT NULL DEFAULT 'received',
    processed_trade_id INTEGER NULL,
    skip_reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(account_id, external_deal_id)
);
CREATE INDEX IF NOT EXISTS idx_metaapi_fills_position ON metaapi_fills(account_id, position_id, processing_state);
CREATE INDEX IF NOT EXISTS idx_metaapi_fills_state ON metaapi_fills(processing_state);
CREATE INDEX IF NOT EXISTS idx_metaapi_fills_user ON metaapi_fills(user_id);

CREATE TABLE IF NOT EXISTS ai_global_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NULL,
    updated_by INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sync_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL DEFAULT 'HISTORICAL',
    status TEXT NOT NULL DEFAULT 'PENDING',
    payload TEXT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    locked_by TEXT NULL,
    lease_token TEXT NULL,
    dedupe_key TEXT NULL,
    last_error TEXT NULL,
    range_from DATETIME NULL,
    range_to DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    dead_lettered_at DATETIME NULL
);
CREATE INDEX IF NOT EXISTS idx_sync_jobs_status ON sync_jobs(status);
CREATE INDEX IF NOT EXISTS idx_sync_jobs_account ON sync_jobs(account_id);

CREATE TABLE IF NOT EXISTS metaapi_operations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    operation_key TEXT NOT NULL,
    provider_marker TEXT NULL,
    request_fingerprint TEXT NULL,
    user_id INTEGER NULL,
    account_id INTEGER NULL,
    operation_type TEXT NULL,
    status TEXT NULL,
    provider_account_id TEXT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error_code TEXT NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_metaapi_operations_key ON metaapi_operations(operation_key);

CREATE TABLE IF NOT EXISTS webhook_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_key TEXT NOT NULL,
    account_id INTEGER NULL,
    metaapi_account_id TEXT NULL,
    event_type TEXT NOT NULL,
    payload TEXT NOT NULL,
    hmac_verified INTEGER NOT NULL DEFAULT 0,
    processed INTEGER NOT NULL DEFAULT 0,
    processing_token TEXT NULL,
    processing_started_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_webhook_event_key ON webhook_events(event_key);
CREATE INDEX IF NOT EXISTS idx_webhook_account ON webhook_events(account_id);
CREATE INDEX IF NOT EXISTS idx_webhook_created ON webhook_events(created_at);
");

// Insert demo users
$stmt = $pdo->prepare("INSERT OR IGNORE INTO users (id, email, password_hash, full_name, role, timezone, status, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'), datetime('now'))");
$stmt->execute([1, 'demo@local.test', '$2y$12$.no5L8TxmGGBq1wdGpg.V.wqWjnC8i437eNeTD3FiNQ2DMz1MTKW.', 'کاربر دمو', 'user', 'UTC', 'active']);
$stmt->execute([2, 'admin@local.test', '$2y$12$mx4CdGXs/pFvzEHnbjEGju/sYZpoawLwXw0nd1mW5oHsdfwyBedcq', 'مدیر سیستم', 'admin', 'UTC', 'active']);

$stmtPrefs = $pdo->prepare("INSERT OR IGNORE INTO email_preferences (user_id, welcome_email, security_alerts, trade_notifications, weekly_report, monthly_report, achievement_notifications) VALUES (?, 1, 1, 1, 1, 1, 1)");
$stmtPrefs->execute([1]);
$stmtPrefs->execute([2]);

echo "SQLite database initialized and seeded successfully!\n";
