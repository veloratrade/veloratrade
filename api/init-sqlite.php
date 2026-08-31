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

CREATE TABLE IF NOT EXISTS trades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    account_id INTEGER NULL,
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
");

// Insert demo users
$stmt = $pdo->prepare("INSERT OR IGNORE INTO users (id, email, password_hash, full_name, role, timezone, status, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'), datetime('now'))");
$stmt->execute([1, 'demo@local.test', '$2y$12$.no5L8TxmGGBq1wdGpg.V.wqWjnC8i437eNeTD3FiNQ2DMz1MTKW.', 'کاربر دمو', 'user', 'UTC', 'active']);
$stmt->execute([2, 'admin@local.test', '$2y$12$mx4CdGXs/pFvzEHnbjEGju/sYZpoawLwXw0nd1mW5oHsdfwyBedcq', 'مدیر سیستم', 'admin', 'UTC', 'active']);

$stmtPrefs = $pdo->prepare("INSERT OR IGNORE INTO email_preferences (user_id, welcome_email, security_alerts, trade_notifications, weekly_report, monthly_report, achievement_notifications) VALUES (?, 1, 1, 1, 1, 1, 1)");
$stmtPrefs->execute([1]);
$stmtPrefs->execute([2]);

echo "SQLite database initialized and seeded successfully!\n";
