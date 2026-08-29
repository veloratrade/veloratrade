<?php

declare(strict_types=1);

/**
 * VELORA — application configuration.
 *
 * مقادیر از طریق Config::env() خوانده می‌شوند که اول متغیر محیطی واقعی،
 * بعد فایل .env (پارس مستقیم) و بعد مقدار پیش‌فرض را برمی‌گرداند.
 * این روش روی همه هاست‌های اشتراکی (حتی بدون putenv) کار می‌کند.
 */

use Velora\Core\Config;

// Fail safe when host-side environment configuration is missing. Development
// mode must be explicitly requested with APP_ENV=dev.
$appEnv = strtolower(Config::env('APP_ENV', 'production'));
$isDevelopment = Config::isDevelopmentEnvironment();
$isProduction = !$isDevelopment;
$defaultJwtSecret = 'change-me-in-production-velora-2026';
$jwtSecret = Config::env('JWT_SECRET', $defaultJwtSecret);
$encryptionKey = Config::env(
    'APP_ENCRYPTION_KEY',
    $isProduction ? '' : base64_encode(hash('sha256', 'velora-dev-key', true))
);
$corsAllowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', Config::env('CORS_ALLOWED_ORIGINS', '*'))
)));
$dbDriver = strtolower(Config::env('DB_DRIVER', 'mysql'));
$dbDatabase = $dbDriver === 'sqlite'
    ? Config::privatePath('data/velora.sqlite')
    : Config::env('DB_DATABASE', 'piknet_velora');
$legacyBodyRefreshEnabled = strtolower(Config::env('AUTH_LEGACY_BODY_REFRESH_ENABLED', 'false')) === 'true';
$refreshCookieActivatedRaw = trim(Config::env('AUTH_REFRESH_COOKIE_ACTIVATED_AT', ''));
$refreshCookieActivatedAt = $refreshCookieActivatedRaw === '' ? false : strtotime($refreshCookieActivatedRaw);
if ($legacyBodyRefreshEnabled && ($refreshCookieActivatedAt === false || $refreshCookieActivatedAt <= 0)) {
    throw new RuntimeException('AUTH_REFRESH_COOKIE_ACTIVATED_AT must be a valid timestamp while legacy refresh exchange is enabled.');
}

// Fail closed on production security configuration mistakes. Never continue with
// a predictable JWT key, a malformed encryption key, or wildcard CORS.
if ($isProduction) {
    if (strlen($jwtSecret) < 32 || hash_equals($defaultJwtSecret, $jwtSecret)) {
        throw new RuntimeException('JWT_SECRET must be an explicit production secret of at least 32 bytes.');
    }
    $decodedEncryptionKey = base64_decode($encryptionKey, true);
    if ($decodedEncryptionKey === false || strlen($decodedEncryptionKey) !== 32) {
        throw new RuntimeException('APP_ENCRYPTION_KEY must be base64 of exactly 32 bytes in production.');
    }
    if ($corsAllowedOrigins === [] || in_array('*', $corsAllowedOrigins, true)) {
        throw new RuntimeException('CORS_ALLOWED_ORIGINS must explicitly list trusted origins in production.');
    }
}

return [
    // Runtime environment: 'dev' | 'prod'
    'app_env' => $appEnv,

    // Detailed errors are never permitted in production, even if APP_DEBUG is
    // accidentally set to true by the hosting environment.
    'app_debug' => !$isProduction && Config::env('APP_DEBUG', 'true') === 'true',

    // Secret used to sign access JWTs.
    'jwt_secret' => $jwtSecret,

    // Access token lifetime (dual-token auth: short-lived access, long refresh)
    'jwt_access_ttl_sec' => (int) (Config::env('JWT_ACCESS_TTL_SEC', '900')),          // 15 min
    'jwt_refresh_ttl_sec' => (int) (Config::env('JWT_REFRESH_TTL_SEC', '2592000')),    // 30 days

    'auth' => [
        // Existing body refresh tokens are accepted only during the approved
        // transition and are exchanged into the HttpOnly cookie contract.
        'legacy_body_refresh_enabled' => $legacyBodyRefreshEnabled,
        'refresh_cookie_activated_at' => $refreshCookieActivatedAt === false ? 0 : $refreshCookieActivatedAt,
        'legacy_body_refresh_cutoff_at' => $refreshCookieActivatedAt === false
            ? 0
            : $refreshCookieActivatedAt + 604_800,
    ],

    // Key used for AES-256-GCM encryption of bridge credentials (v0.2+).
    // In production this must be explicitly configured; dev fallback is never used in prod.
    'encryption_key_b64' => $encryptionKey,

    'db' => [
        'driver' => $dbDriver,
        'database' => $dbDatabase,
        'host' => Config::env('DB_HOST', '127.0.0.1'),
        'port' => (int) (Config::env('DB_PORT', '3306')),
        'name' => Config::env('DB_NAME', 'piknet_velora'),
        'user' => Config::env('DB_USER', 'piknet_velora'),
        'pass' => Config::env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],

    // CORS: restrict to your frontend origin(s) in production.
    'cors_allowed_origins' => $corsAllowedOrigins,

    'metaapi' => [
        'max_accounts_per_user' => max(1, (int) Config::env('METAAPI_MAX_ACCOUNTS_PER_USER', '10')),
        'sync_cooldown_seconds' => max(1, (int) Config::env('METAAPI_SYNC_COOLDOWN_SECONDS', '60')),
        'sync_max_attempts' => max(1, (int) Config::env('METAAPI_SYNC_MAX_ATTEMPTS', '5')),
        'sync_retry_base_seconds' => max(1, (int) Config::env('METAAPI_SYNC_RETRY_BASE_SECONDS', '30')),
        'sync_retry_cap_seconds' => max(30, (int) Config::env('METAAPI_SYNC_RETRY_CAP_SECONDS', '3600')),
        'webhook_max_age_seconds' => max(30, (int) Config::env('METAAPI_WEBHOOK_MAX_AGE_SECONDS', '300')),
    ],

    // Only honor forwarding headers when REMOTE_ADDR matches this explicit list.
    'trusted_proxy_cidrs' => array_values(array_filter(array_map(
        'trim',
        explode(',', Config::env('TRUSTED_PROXY_CIDRS', ''))
    ))),

    // Application-level cap applied before php://input is fully read (24 MiB).
    'request_max_bytes' => 25_165_824,
    'request_path_max_bytes' => [
        'POST /api/v1/webhooks/metaapi' => 1_048_576,
    ],

    // آدرس فرانت‌اند — برای ساخت لینک بازیابی رمز عبور
    'frontend_url' => Config::env('FRONTEND_URL', ''),

    // Bcrypt cost factor (roadmap v0.1 security spec)
    'bcrypt_cost' => 12,

    // Trade pagination default
    'pagination_default_limit' => 20,
    'pagination_max_limit' => 200,

    // AI module configuration (v0.4-v0.5) — secrets via Config::env(), never hardcoded
    'ai' => [
        'gemini_api_key' => Config::env('GEMINI_API_KEY', ''),
        'gemini_model' => Config::env('GEMINI_MODEL', 'gemini-1.5-flash'),
        'gemini_timeout' => max(2, min(30, (int) Config::env('GEMINI_TIMEOUT', '8'))),
        // Swappable Gemini transport: direct HTTPS | n8n relay (temporary region workaround)
        'gemini_route' => strtolower(Config::env('GEMINI_ROUTE', '')) === 'n8n_relay' ? 'n8n_relay' : 'direct',
        'gemini_relay_url' => Config::env('GEMINI_RELAY_URL', ''),
        'gemini_relay_token' => Config::env('GEMINI_RELAY_TOKEN', ''),
        'gemini_relay_timeout' => max(5, min(90, (int) Config::env('GEMINI_RELAY_TIMEOUT', '45'))),
        'openai_api_key' => Config::env('OPENAI_API_KEY', ''),
        'openai_model' => Config::env('OPENAI_MODEL', 'gpt-4o-mini'),
        'openai_timeout' => max(2, min(30, (int) Config::env('OPENAI_TIMEOUT', '10'))),
        'enabled_providers' => array_values(array_filter(array_map(
            'trim',
            explode(',', Config::env('AI_ENABLED_PROVIDERS', 'gemini,tesseract'))
        ))),
        'max_image_bytes' => 8_388_608,
        'max_total_bytes' => 16_777_216,
        // P2 hardening: image optimization
        'image_max_dimension' => max(256, min(2048, (int) Config::env('AI_IMAGE_MAX_DIMENSION', '1024'))),
        'image_jpeg_quality' => max(10, min(100, (int) Config::env('AI_IMAGE_JPEG_QUALITY', '80'))),
        // P2: prompt management
        'prompt_path' => Config::env('AI_PROMPT_PATH', dirname(__DIR__) . '/src/AI/Prompts/templates'),
        // P0/P1: audit and feature flags + retention
        'audit_enabled' => Config::env('AI_AUDIT_ENABLED', 'true') === 'true',
        'request_tracking_enabled' => Config::env('AI_REQUEST_TRACKING_ENABLED', 'true') === 'true',
        'feature_flags_enabled' => Config::env('AI_FEATURE_FLAGS_ENABLED', 'true') === 'true',
        'retention_days' => max(7, min(365, (int) Config::env('AI_RETENTION_DAYS', '30'))),
    ],
];
