<?php

declare(strict_types=1);

namespace Velora\Core;

/**
 * Phase 8 — Platform-level operational settings (non-secret).
 *
 * The single resolution point for platform settings stored in the generic
 * settings table (`ai_global_settings`, via IntegrationSettingsRepository).
 * Mirrors the established resolver precedence EXACTLY (highest wins):
 *
 *   1. Admin-managed setting   (settings table; written only through the
 *      gated /admin/settings endpoints — system.settings.manage, audited)
 *   2. process ENV             (infra override; real getenv via Config)
 *   3. default                 (the historical hard-coded value)
 *
 * Absent/invalid values at ANY layer fall through to the next layer — the
 * historical behaviour is preserved bit-for-bit when nothing is configured.
 * Only enumerated, non-secret, bounded values are resolvable here; secrets
 * live exclusively in SecureCredentialStore and can never enter this table.
 */
final class PlatformSettings
{
    /** The default signup/UI locale for NEW users (was hard-coded 'fa'). */
    public const DEFAULT_LOCALE = 'platform.default_locale';

    /** Bounded enum for DEFAULT_LOCALE (mirror of the site's UI locales). */
    public const LOCALES = ['fa', 'en'];

    /** Env alias for DEFAULT_LOCALE (infra override layer). */
    public const DEFAULT_LOCALE_ENV = 'PLATFORM_DEFAULT_LOCALE';

    public static function defaultLocale(): string
    {
        // 1. Admin-managed DB value (validated at write time; re-validated here).
        $stored = (new IntegrationSettingsRepository())->get(self::DEFAULT_LOCALE);
        if ($stored !== null) {
            $candidate = strtolower(trim($stored));
            if (in_array($candidate, self::LOCALES, true)) {
                return $candidate;
            }
        }

        // 2. Infra override (process ENV / private velora.env).
        $env = strtolower(trim((string) Config::env(self::DEFAULT_LOCALE_ENV, '')));
        if (in_array($env, self::LOCALES, true)) {
            return $env;
        }

        // 3. Historical default — the pre-Phase-8 hard-coded value.
        return 'fa';
    }
}
