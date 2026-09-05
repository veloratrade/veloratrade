<?php

declare(strict_types=1);

namespace Velora\AI\Services;

use Velora\AI\Repositories\AIGlobalSettingRepository;
use Velora\AI\Repositories\AIFeatureFlagRepository;
use Velora\Core\Config;

/**
 * Phase B — Single authoritative runtime resolver for the GLOBAL AI route.
 *
 * Purpose: make an Admin-managed global route actually effective at runtime
 * and define the ONE place that decides the default route, so no other class
 * re-implements route logic.
 *
 * Precedence (documented + tested; per-feature explicit route is layered on top
 * by FeatureRouter/AIManager and is NOT consulted here):
 *
 *   1. Admin-managed global route (ai_global_settings)  [Super Admin saved]
 *   2. GEMINI_ROUTE env                                 [legacy infra override]
 *   3. ai_gemini_relay_route feature flag               [legacy compat fallback]
 *   4. 'direct'                                         [default]
 *
 * Requirement satisfied: once a Super Admin explicitly saves a global route,
 * an old GEMINI_ROUTE env value is NOT consulted and therefore can never
 * silently override the administrator's explicit decision. To revert to the
 * legacy behaviour an administrator RESETS (clear) the global route.
 *
 * This resolver returns only allowlisted route values, never secrets, and reads
 * the global setting through AIGlobalSettingRepository (DB-backed, the single
 * source of truth). It degrades gracefully when tables are unavailable, so a
 * fresh pre-migration deployment keeps legacy behaviour.
 */
final class AiRouteResolver
{
    /** Canonical route values (mirrors ProviderCatalog's allowlist). */
    public const ROUTE_DIRECT = 'direct';
    public const ROUTE_RELAY = 'n8n_relay';

    /** The global setting key in ai_global_settings. */
    public const SETTING_GLOBAL_ROUTE = 'ai_route_default';

    /** Source labels for the Admin UI / diagnostics. */
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_ENV = 'env';
    public const SOURCE_FLAG = 'legacy_flag';
    public const SOURCE_DEFAULT = 'default';

    public function __construct(
        private readonly ?AIGlobalSettingRepository $settings = null,
        private readonly ?AIFeatureFlagRepository $flags = null,
    ) {
    }

    /** True when $route is one of the allowlisted route values. */
    public static function isValidRoute(?string $route): bool
    {
        $r = strtolower(trim((string) $route));
        return $r === self::ROUTE_DIRECT || $r === self::ROUTE_RELAY;
    }

    /**
     * The explicitly-saved admin global route, or null when unset/inherit.
     * Only ever returns an allowlisted value.
     */
    public function configuredRoute(): ?string
    {
        $repo = $this->settings ?? new AIGlobalSettingRepository();
        $value = $repo->get(self::SETTING_GLOBAL_ROUTE);
        return self::isValidRoute($value) ? $value : null;
    }

    /**
     * The effective default route string ('direct' | 'n8n_relay'), following
     * the documented precedence. This is what GeminiProvider::getRoute()
     * delegates to.
     */
    public function resolve(): string
    {
        return $this->resolveWithSource()['route'];
    }

    /**
     * Resolve the route AND the source that produced it — so the Admin UI can
     * clearly distinguish Configured vs Effective vs Source without ambiguity.
     *
     * @return array{route:string, source:string}
     */
    public function resolveWithSource(): array
    {
        // 1. Admin-managed global route (highest precedence — beats ENV).
        $admin = $this->configuredRoute();
        if ($admin !== null) {
            return ['route' => $admin, 'source' => self::SOURCE_ADMIN];
        }

        // 2. Legacy GEMINI_ROUTE env override.
        $env = strtolower(trim(Config::env('GEMINI_ROUTE', '')));
        if ($env === self::ROUTE_DIRECT || $env === self::ROUTE_RELAY) {
            return ['route' => $env, 'source' => self::SOURCE_ENV];
        }

        // 3. Legacy boolean feature flag (enabled => n8n_relay).
        try {
            $flagRepo = $this->flags ?? new AIFeatureFlagRepository();
            if ($flagRepo->isEnabled('ai_gemini_relay_route')) {
                return ['route' => self::ROUTE_RELAY, 'source' => self::SOURCE_FLAG];
            }
        } catch (\Throwable $e) {
            // flag table unavailable — fall through to default.
        }

        // 4. Default.
        return ['route' => self::ROUTE_DIRECT, 'source' => self::SOURCE_DEFAULT];
    }

    /**
     * Reset the admin global route (clear => inherit legacy behaviour).
     * Returns true if a stored value was actually removed.
     */
    public function clear(): bool
    {
        $repo = $this->settings ?? new AIGlobalSettingRepository();
        return $repo->delete(self::SETTING_GLOBAL_ROUTE);
    }

    /** Save an allowlisted admin global route. Returns true on success. */
    public function save(string $route, int $updatedBy = 0): bool
    {
        $normalized = self::isValidRoute($route) ? strtolower(trim($route)) : null;
        if ($normalized === null) {
            return false;
        }
        $repo = $this->settings ?? new AIGlobalSettingRepository();
        return $repo->set(self::SETTING_GLOBAL_ROUTE, $normalized, $updatedBy);
    }
}
