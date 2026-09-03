<?php

declare(strict_types=1);

namespace Velora\Core;

/**
 * Phase C — Single authoritative runtime resolver for Admin-managed external
 * integrations (MetaAPI, Email).
 *
 * Mirrors the Phase A `RelayConfigResolver` + Phase B `AiRouteResolver` pattern:
 * ONE place decides the effective value so the Admin Panel and the runtime
 * consumer can never disagree, and so a value saved by a Super Admin is
 * actually effective without an .env / config edit.
 *
 * Two value classes, per the architecture:
 *
 *  SECRET values (API tokens / keys / passwords) live in the encrypted
 *  SecureCredentialStore (AES-256-GCM, APP_ENCRYPTION_KEY) when set from the
 *  Admin Panel. Precedence (highest wins):
 *     1. Admin-managed encrypted secret   (SecureCredentialStore)
 *     2. process ENV                       (infra override; real getenv)
 *     3. private velora.env                (existing plaintext fallback)
 *     4. ''                                (unavailable)
 *  Mirrors RelayConfigResolver exactly. Process ENV sits ABOVE velora.env to
 *  mirror Config::env()'s tenet; the encrypted store is above BOTH only because
 *  it is the application-managed value explicitly set from the Admin Panel.
 *
 *  NON-SECRET operational settings (base URLs, provider, from-address/name,
 *  SMTP host/port/user) live in the generic settings table. Precedence:
 *     1. Admin-managed setting   (IntegrationSettingsRepository, DB)
 *     2. process ENV             (infra override)
 *     3. private velora.env      (existing plaintext fallback)
 *     4. default
 *
 * RESET/clear => the Admin value is removed and the value inherits the
 * ENV/legacy/default layer — never an ambiguous state.
 *
 * No method here returns a secret over an HTTP surface. All values are consumed
 * internally; status endpoints expose booleans + safe hosts + classification.
 */
final class IntegrationConfigResolver
{
    // ---- setting keys (non-secret, generic settings table) ----------------
    public const SETTING_METAAPI_BASE_URL = 'metaapi.base_url';
    public const SETTING_MAIL_DRIVER      = 'mail.driver';
    public const SETTING_MAIL_FROM        = 'mail.from';
    public const SETTING_MAIL_FROM_NAME   = 'mail.from_name';
    public const SETTING_MAIL_HOST        = 'mail.smtp_host';
    public const SETTING_MAIL_PORT        = 'mail.smtp_port';
    public const SETTING_MAIL_USER        = 'mail.smtp_user';

    // ---- secret env key names (manages by SecureCredentialStore) ----------
    public const SECRET_METAAPI_TOKEN     = 'METAAPI_TOKEN';
    public const SECRET_METAAPI_WEBHOOK   = 'METAAPI_WEBHOOK_SECRET';
    public const SECRET_RESEND_API_KEY    = 'RESEND_API_KEY';
    public const SECRET_SMTP_PASSWORD     = 'MAIL_PASS';

    /**
     * Dot-based stored setting key -> conventional env var name. Without this
     * the env fallback would scan for "mail.driver" in the environment, which
     * never matches the real "MAIL_DRIVER", silently ignoring legacy/global env
     * configuration whenever the settings table row is absent.
     */
    private const SETTING_ENV_ALIASES = [
        self::SETTING_MAIL_DRIVER      => 'MAIL_DRIVER',
        self::SETTING_MAIL_FROM        => 'MAIL_FROM',
        self::SETTING_MAIL_FROM_NAME   => 'MAIL_FROM_NAME',
        self::SETTING_MAIL_HOST        => 'MAIL_HOST',
        self::SETTING_MAIL_PORT        => 'MAIL_PORT',
        self::SETTING_MAIL_USER        => 'MAIL_USER',
        self::SETTING_METAAPI_BASE_URL => 'METAAPI_BASE_URL',
    ];

    private static function settings(): IntegrationSettingsRepository
    {
        return new IntegrationSettingsRepository();
    }

    // ======================================================================
    // MetaAPI
    // ======================================================================

    /** MetaAPI token (secret). '' when not configured. */
    public static function metaApiToken(): string
    {
        return self::resolveSecret(self::SECRET_METAAPI_TOKEN);
    }

    /** MetaAPI webhook signature secret (secret). '' when not configured. */
    public static function metaApiWebhookSecret(): string
    {
        return self::resolveSecret(self::SECRET_METAAPI_WEBHOOK);
    }

    /**
     * Effective MetaAPI base URL (non-secret). Defaults to the provisioning
     * URL the code already used — so behaviour is unchanged when unset.
     */
    public static function metaApiBaseUrl(): string
    {
        $default = 'https://mt-provisioning-api-v1.london.agiliumtrade.ai';
        return self::resolveSetting(self::SETTING_METAAPI_BASE_URL, $default);
    }

    public static function metaApiConfigured(): bool
    {
        return self::metaApiToken() !== '';
    }

    /** Safe host-only representation of the effective base URL (never path/query). */
    public static function metaApiSafeHost(): string
    {
        return self::safeHost(self::metaApiBaseUrl());
    }

    /** @return array<string,mixed> Safe status (no secrets). */
    public static function metaApiSafeStatus(): array
    {
        $token = self::metaApiToken();
        $webhook = self::metaApiWebhookSecret();
        return [
            'configured' => $token !== '',
            'tokenConfigured' => $token !== '',
            'webhookSecretConfigured' => $webhook !== '',
            'baseUrlHost' => self::metaApiSafeHost(),
            'source' => self::metaApiSource(),
            // status is set by the connectivity check; kept separate from Configured.
            'reachability' => 'unknown',
        ];
    }

    /** Source of the effective token: 'admin' | 'env' | 'velora_env' | 'unset'. */
    public static function metaApiSource(): string
    {
        if (SecureCredentialStore::secretStatus(self::SECRET_METAAPI_TOKEN)) {
            return 'admin';
        }
        $env = getenv(self::SECRET_METAAPI_TOKEN);
        if ($env !== false && trim((string) $env) !== '') {
            return 'env';
        }
        if (trim(Config::env(self::SECRET_METAAPI_TOKEN, '')) !== '') {
            return 'velora_env';
        }
        return 'unset';
    }

    // ======================================================================
    // Email
    // ======================================================================

    public static function mailDriver(): string
    {
        $driver = strtolower(trim(self::resolveSetting(self::SETTING_MAIL_DRIVER, 'mail')));
        return in_array($driver, ['log', 'mail', 'smtp', 'resend'], true) ? $driver : 'mail';
    }

    public static function mailFrom(): string
    {
        return self::resolveSetting(self::SETTING_MAIL_FROM, 'no-reply@veloratrade.ir');
    }

    public static function mailFromName(): string
    {
        return self::resolveSetting(self::SETTING_MAIL_FROM_NAME, 'VELORA TRADE');
    }

    public static function mailSmtpHost(): string
    {
        // Default '' (unchanged from legacy Mailer): SMTP logs "not configured"
        // and the send fails gracefully until host/user/pass are set.
        return self::resolveSetting(self::SETTING_MAIL_HOST, '');
    }

    public static function mailSmtpPort(): int
    {
        $port = (int) self::resolveSetting(self::SETTING_MAIL_PORT, '587');
        return $port > 0 && $port <= 65535 ? $port : 587;
    }

    public static function mailSmtpUser(): string
    {
        // Default '' to preserve legacy Mailer "SMTP not configured" behaviour.
        return self::resolveSetting(self::SETTING_MAIL_USER, '');
    }

    public static function mailSmtpPassword(): string
    {
        return self::resolveSecret(self::SECRET_SMTP_PASSWORD);
    }

    public static function mailResendApiKey(): string
    {
        return self::resolveSecret(self::SECRET_RESEND_API_KEY);
    }

    /** Email secret considered configured depends on driver. */
    public static function mailSecretConfigured(): bool
    {
        return match (self::mailDriver()) {
            'resend' => self::mailResendApiKey() !== '',
            'smtp' => self::mailSmtpPassword() !== '',
            default => true, // log / mail() need no credential
        };
    }

    /** Safe email status (no secrets). */
    public static function mailSafeStatus(): array
    {
        $driver = self::mailDriver();
        return [
            'configured' => self::mailSecretConfigured(),
            'driver' => $driver,
            'from' => self::mailFrom(),
            'fromName' => self::mailFromName(),
            'smtpHost' => $driver === 'smtp' ? self::mailSmtpHost() : null,
            'smtpPort' => $driver === 'smtp' ? self::mailSmtpPort() : null,
            'smtpUser' => $driver === 'smtp' ? self::mailSmtpUser() : null,
            // Safe presence booleans — never the secret values themselves.
            'resendApiKeyConfigured' => self::mailResendApiKey() !== '',
            'smtpPasswordConfigured' => self::mailSmtpPassword() !== '',
            'source' => self::mailDriverSource(),
            'reachability' => 'unknown',
        ];
    }

    public static function mailDriverSource(): string
    {
        $stored = self::settings()->get(self::SETTING_MAIL_DRIVER);
        if ($stored !== null && $stored !== '') {
            return 'admin';
        }
        $env = getenv('MAIL_DRIVER');
        if ($env !== false && trim((string) $env) !== '') {
            return 'env';
        }
        if (trim(Config::env('MAIL_DRIVER', '')) !== '') {
            return 'velora_env';
        }
        return 'default';
    }

    // ======================================================================
    // core
    // ======================================================================

    /**
     * Secret precedence: encrypted store > process ENV > velora.env > ''.
     */
    private static function resolveSecret(string $key): string
    {
        $managed = SecureCredentialStore::read($key);
        if ($managed !== '') {
            return $managed;
        }
        $env = getenv($key);
        if ($env !== false && trim((string) $env) !== '') {
            return trim((string) $env);
        }
        return trim(Config::env($key, ''));
    }

    /**
     * Non-secret precedence: settings table (Admin) > process ENV > velora.env > default.
     */
    private static function resolveSetting(string $key, string $default): string
    {
        // The settings table may be unavailable (not yet migrated, or a legacy
        // caller/tool that never created the schema). Treat that as "no Admin
        // value" and fall through to ENV / velora.env / default — never fatal.
        $stored = null;
        try {
            $stored = self::settings()->get($key);
        } catch (\PDOException|\RuntimeException $e) {
            $stored = null;
        }
        if ($stored !== null && $stored !== '') {
            return $stored;
        }
        // Stored setting keys are dot-based ("mail.driver") but the conventional
        // env vars are uppercased ("MAIL_DRIVER"), so consult the mapped name.
        $envKey = self::SETTING_ENV_ALIASES[$key] ?? $key;
        $env = getenv($envKey);
        if ($env !== false && trim((string) $env) !== '') {
            return trim((string) $env);
        }
        $file = Config::env($envKey, '');
        return $file !== '' ? trim($file) : $default;
    }

    private static function safeHost(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return '';
        }
        return (string) $parts['host'];
    }
}
