<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Exceptions\ValidationException;
use Velora\Core\IntegrationSettingsRepository;
use Velora\Core\PlatformSettings;

/**
 * Phase 8 (8a) — Settings Write: strict-allowlist operational settings.
 *
 * TAXONOMY (documented per owner decision; four classes, strict separation):
 *
 *  A. WRITABLE here (safe, non-secret, Admin-configurable, env-inheritable):
 *     platform.default_locale — enum('fa','en'), default 'fa'.
 *     Consumer: UserRepository::create/createByAdmin default signup locale
 *     (previously hard-coded 'fa'). No other key is writable; adding a key
 *     requires a real consumer + validation spec in this catalog.
 *
 *  B. SECRETS — NEVER writable or readable here (SecureCredentialStore only):
 *     METAAPI_TOKEN, METAAPI_WEBHOOK_SECRET, RESEND_API_KEY, MAIL_PASS,
 *     provider API keys, APP_ENCRYPTION_KEY. Any unknown key (including
 *     secret-shaped ones) is rejected identically as UNKNOWN_SETTING.
 *
 *  C. ENV-CONTROLLED (not exposed for writing; infra-owned):
 *     FRONTEND_URL, APP_ENV, AI provider models/timeouts (AI module domain),
 *     TRANSLATION_SERVICE_*.
 *
 *  D. READ-ONLY / system-derived: everything rendered by
 *     /admin/config/effective (EffectiveConfigService; secret-free by
 *     construction) — the settings page links to it, never duplicates it.
 *
 *  SUPERVISORY inventory (read-only rows): the existing module-managed keys
 *  in the same generic table (ai_route_default, metaapi.*, mail.*) are shown
 *  with their OWNING module/endpoint. Their writes stay in their owning
 *  modules (single-owner rule); this module never duplicates them.
 *
 * Precedence for class A keys (unchanged resolver model): DB row > process
 * ENV (alias) > default. DELETE (reset) removes the DB row so the value
 * inherits ENV/default again — never an ambiguous state.
 */
final class AdminSettingsService
{
    /** Writable catalog: key => spec. Deliberately minimal. */
    public const CATALOG = [
        PlatformSettings::DEFAULT_LOCALE => [
            'kind' => 'enum',
            'choices' => PlatformSettings::LOCALES,
            'default' => 'fa',
            'envAlias' => PlatformSettings::DEFAULT_LOCALE_ENV,
            'consumer' => 'signup default locale (UserRepository)',
            'module' => 'platform',
        ],
    ];

    /** Module-managed keys (read-only here; owner module + endpoint shown). */
    public const MANAGED_ELSEWHERE = [
        'ai_route_default' => ['module' => 'ai-route', 'endpoint' => '#/ai-route'],
        'metaapi.base_url' => ['module' => 'integrations-metaapi', 'endpoint' => '#/integrations-metaapi'],
        'mail.driver' => ['module' => 'integrations-email', 'endpoint' => '#/integrations-email'],
        'mail.from' => ['module' => 'integrations-email', 'endpoint' => '#/integrations-email'],
        'mail.from_name' => ['module' => 'integrations-email', 'endpoint' => '#/integrations-email'],
        'mail.smtp_host' => ['module' => 'integrations-email', 'endpoint' => '#/integrations-email'],
        'mail.smtp_port' => ['module' => 'integrations-email', 'endpoint' => '#/integrations-email'],
        'mail.smtp_user' => ['module' => 'integrations-email', 'endpoint' => '#/integrations-email'],
    ];

    public function __construct(
        private readonly IntegrationSettingsRepository $repo = new IntegrationSettingsRepository(),
    ) {
    }

    /** Validate + normalise a value for a catalog key. Fail-closed. */
    public function validate(string $key, string $value): string
    {
        $spec = self::CATALOG[$key] ?? null;
        if ($spec === null) {
            // Unknown OR secret-shaped keys are indistinguishable by design.
            throw new ValidationException('Unknown or non-writable setting.', ['key' => ['code' => 'UNKNOWN_SETTING', 'messageKey' => 'errors.validation.invalid', 'params' => []]]);
        }
        $value = strtolower(trim($value));
        if (in_array($spec['kind'], ['enum'], true) && !in_array($value, $spec['choices'], true)) {
            throw new ValidationException('Invalid setting value.', ['value' => ['code' => 'INVALID_CHOICE', 'messageKey' => 'errors.validation.choice', 'params' => []]]);
        }
        return $value;
    }

    /** Full supervisory inventory: writable catalog + module-managed keys. */
    public function inventory(): array
    {
        $rows = $this->repo->all();
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[(string) $r['setting_key']] = $r;
        }

        $out = [];
        foreach (self::CATALOG as $key => $spec) {
            $row = $byKey[$key] ?? null;
            $out[] = $this->row($key, $row, true, $spec['module'], null, $spec['envAlias']);
        }
        foreach (self::MANAGED_ELSEWHERE as $key => $meta) {
            $row = $byKey[$key] ?? null;
            $out[] = $this->row($key, $row, false, $meta['module'], $meta['endpoint'], null);
        }
        return $out;
    }

    private function row(string $key, ?array $row, bool $writable, string $module, ?string $endpoint, ?string $envAlias): array
    {
        $stored = $row['setting_value'] ?? null;
        return [
            'key' => $key,
            'value' => $stored !== null ? (string) $stored : null,
            'source' => $stored !== null ? 'admin' : 'env-default',
            'updatedBy' => isset($row['updated_by']) && $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'updatedAt' => $row['updated_at'] ?? null,
            'writable' => $writable,
            'module' => $module,
            'moduleEndpoint' => $endpoint,
            'envAlias' => $envAlias,
        ];
    }

    /** Validated write. Returns before/after (non-secret values only). */
    public function set(string $key, string $value, int $actorId): array
    {
        $value = $this->validate($key, $value);
        $before = $this->repo->get($key);
        $this->repo->set($key, $value, $actorId);
        return ['key' => $key, 'old' => $before, 'new' => $value];
    }

    /** Reset to env/default (remove the DB row). Returns the prior value. */
    public function reset(string $key, int $actorId): array
    {
        if (!isset(self::CATALOG[$key])) {
            throw new ValidationException('Unknown or non-writable setting.', ['key' => ['code' => 'UNKNOWN_SETTING', 'messageKey' => 'errors.validation.invalid', 'params' => []]]);
        }
        $before = $this->repo->get($key);
        $this->repo->delete($key);
        return ['key' => $key, 'old' => $before, 'new' => null];
    }
}
