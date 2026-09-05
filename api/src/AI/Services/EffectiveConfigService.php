<?php

declare(strict_types=1);

namespace Velora\AI\Services;

use Velora\AI\Repositories\AICredentialMetadataRepository;
use Velora\AI\Repositories\AIFeatureFlagRepository;
use Velora\AI\Repositories\AIFeatureProviderRepository;
use Velora\AI\Repositories\AIProviderQuotaRepository;
use Velora\Core\SecureCredentialStore;

/**
 * Phase 1 — effective / source-of-truth configuration.
 *
 * Builds a read-only, secret-free map that lets an administrator or developer
 * distinguish, for every setting:
 *   Configured Value  →  Persisted Value  →  Effective Runtime Value  →  Consumer.
 *
 * The map is derived entirely from the same components that runtime executes
 * (FeatureRouter, ProviderCatalog, Provider registry, quota repo, private env).
 * It never fabricates a chain: when no DB rows exist it reports the true
 * env-default chain and source=env-default.
 *
 * No secret value is ever read into the returned map — only boolean presence
 * plus metadata status.
 */
final class EffectiveConfigService
{
    public function __construct(
        private readonly AIFeatureProviderRepository $rows = new AIFeatureProviderRepository(),
        private readonly AIFeatureFlagRepository $flags = new AIFeatureFlagRepository(),
        private readonly AIProviderQuotaRepository $quotas = new AIProviderQuotaRepository(),
        private readonly AICredentialMetadataRepository $credMeta = new AICredentialMetadataRepository(),
        private readonly FeatureRouter $router = new FeatureRouter(),
        private readonly AIProviderRegistry $registry = new AIProviderRegistry(),
        private readonly AiRouteResolver $routeResolver = new AiRouteResolver(),
    ) {
    }

    /** @return array<string,mixed> */
    public function getConfig(): array
    {
        return [
            'providers' => $this->providers(),
            'features' => $this->features(),
            'globalRoute' => $this->globalRoute(),
            'precedence' => $this->precedence(),
        ];
    }

    /**
     * Phase B — the Admin-managed GLOBAL AI route, as the runtime resolver
     * actually sees it (configured vs effective vs source). No secrets.
     *
     * @return array<string,mixed>
     */
    private function globalRoute(): array
    {
        $resolved = $this->routeResolver->resolveWithSource();
        return [
            'configured' => $this->routeResolver->configuredRoute(),
            'effective' => $resolved['route'],
            'source' => $resolved['source'],
            'allowed' => [AiRouteResolver::ROUTE_DIRECT, AiRouteResolver::ROUTE_RELAY],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function providers(): array
    {
        $out = [];
        foreach (ProviderCatalog::providerNames() as $name) {
            $out[] = $this->provider($name);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function provider(string $name): array
    {
        $entry = [
            'provider' => $name,
            'registered' => true,
            'capabilities' => $this->providerCapabilities($name),
            'available' => $this->providerAvailable($name),
            'credential' => $this->credentialState($name),
            'effectiveModel' => ProviderCatalog::defaultModel($name),
            'quota' => $this->safeQuota($name),
        ];

        if ($name === 'gemini') {
            $route = null;
            try {
                $route = (new \Velora\AI\Providers\GeminiProvider())->getRoute();
            } catch (\Throwable $e) {
                // unknown
            }
            $entry['effectiveRoute'] = $route;
        }

        return $entry;
    }

    /** @return string[] */
    private function providerCapabilities(string $name): array
    {
        $class = ProviderCatalog::providerClass($name);
        if ($class === null || !class_exists($class)) {
            return [];
        }
        try {
            return (new $class())->getCapabilities();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function providerAvailable(string $name): bool
    {
        $class = ProviderCatalog::providerClass($name);
        if ($class === null || !class_exists($class)) {
            return false;
        }
        try {
            return (new $class())->isAvailable();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Credential state: boolean presence (real, from private env file) + safe
     * metadata status from ai_provider_credentials. Presence ≠ validity.
     *
     * @return array<string,mixed>
     */
    private function credentialState(string $name): array
    {
        $keys = ProviderCatalog::credentialKeys($name);
        $required = $keys !== [];
        $configured = null;
        if ($required) {
            $configured = SecureCredentialStore::status($keys[0]);
        }
        $meta = $this->credMeta->safeMetadata($name);
        $relay = [];
        if ($name === 'gemini') {
            $relay = [
                'urlConfigured' => SecureCredentialStore::status('GEMINI_RELAY_URL'),
                'tokenConfigured' => SecureCredentialStore::status('GEMINI_RELAY_TOKEN'),
            ];
        }
        return array_merge([
            'required' => $required,
            'configured' => $configured, // boolean only, NEVER the value
            'status' => $meta['status'],
            'verified' => $meta['verified'],
            'checkedAt' => $meta['checkedAt'],
            'lastCheckedAt' => $meta['lastCheckedAt'],
            'errorCode' => $meta['errorCode'],
        ], $relay);
    }

    /** @return array<int,array<string,mixed>> */
    private function features(): array
    {
        $out = [];
        foreach (ProviderCatalog::FEATURES as $feature) {
            $capability = ProviderCatalog::FEATURE_CAPABILITY[$feature] ?? null;
            $out[] = [
                'feature' => $feature,
                'capability' => $capability,
                'source' => $this->router->sourceFor($feature),
                'flag' => $this->flagState($feature),
                'configuredChain' => $this->serializeRows($this->rows->chainFor($feature)),
                'effectiveChain' => $this->router->resolveChain($feature, $capability),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function flagState(string $feature): ?array
    {
        try {
            $flagRows = $this->flags->all();
            foreach ($flagRows as $row) {
                if ((string) $row['feature_name'] === $feature) {
                    return [
                        'enabled' => (bool) $row['enabled'],
                        'rolloutPercentage' => (int) ($row['rollout_percentage'] ?? 0),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // flags table unavailable
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function safeQuota(string $provider): ?array
    {
        try {
            $q = $this->quotas->getQuota($provider);
            if (!is_array($q)) {
                return null;
            }
            return [
                'dailyUsed' => (int) ($q['daily_used'] ?? 0),
                'quotaLimit' => (int) ($q['quota_limit'] ?? 0),
                'resetAt' => $q['reset_at'] ?? null,
                'source' => 'internal', // Velora internal budget, NOT provider-reported
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function serializeRows(array $rows): array
    {
        return array_map(fn (array $r): array => [
            'provider' => (string) $r['provider'],
            'model' => $r['model'],
            'priority' => (int) $r['priority'],
            'enabled' => ((int) ($r['enabled'] ?? 1)) === 1,
            'route' => $r['route'],
            'fallbackIndex' => (int) ($r['fallback_index'] ?? 0),
        ], $rows);
    }

    /** @return array<int,array<string,string>> Explicit documented precedence. */
    private function precedence(): array
    {
        return [
            'provider_enabled' => 'ai_feature_providers(db) > AI_ENABLED_PROVIDERS(env). A feature with no DB rows uses the env-default chain (source=env-default).',
            'feature_chain' => 'For a routed feature, enabled + priority ASC + capability + credential-presence rows from ai_feature_providers; otherwise the legacy env-default chain.',
            'model' => 'chain row model > provider env model (e.g. GEMINI_MODEL) > ProviderCatalog default.',
            'route' => 'chain row route override > Admin global AI route (ai_global_settings) > GEMINI_ROUTE env > ai_gemini_relay_route(DB flag) > direct.',
            'credential' => 'private {VELORA_PRIVATE_ROOT}/config/velora.env (outside docroot); process env overrides it (Config::env). Presence-only, NOT validity.',
            'quota' => 'ai_provider_quotas (Velora internal budget; quota_limit seeded default 1500). Not provider-reported.',
        ];
    }
}
