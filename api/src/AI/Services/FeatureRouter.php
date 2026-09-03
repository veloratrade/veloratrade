<?php

declare(strict_types=1);

namespace Velora\AI\Services;

use Velora\AI\Providers\AIProviderInterface;
use Velora\AI\Repositories\AICredentialMetadataRepository;
use Velora\AI\Repositories\AIFeatureProviderRepository;
use Velora\Core\Config;

/**
 * Resolves the executable, ordered provider chain for a feature.
 *
 * Resolution order:
 *   1. Persisted rows from ai_feature_providers (source = "db"):
 *        enabled = 1, sorted by priority ASC, filtered by capability and
 *        credential availability.
 *   2. When the table is missing/empty for the feature (source =
 *      "env-default"): the existing environment-driven registry behavior
 *      (AI_ENABLED_PROVIDERS + DEFAULT_PRIORITY) — byte-compatible with the
 *      pre-routing AIManager iteration.
 *
 * Runtime activation gate: a provider whose credential is CONFIRMED-INVALID
 * (verified invalid/expired/revoked/disabled) is excluded from BOTH chains via
 * CredentialVerificationGate — an invalid credential can never be routed as a
 * healthy provider. UNVERIFIED / UNKNOWN / no-metadata providers remain
 * usable (backward compatible).
 *
 * A chain entry never contains secrets — only routing metadata.
 */
final class FeatureRouter
{
    public const SOURCE_DB = 'db';
    public const SOURCE_ENV_DEFAULT = 'env-default';

    public function __construct(
        private readonly ?AIFeatureProviderRepository $repository = null,
        private readonly ?AIProviderRegistry $registry = null,
        /** @var array<string,AIProviderInterface>|null injected instances (tests/DI); null = catalog classes */
        private readonly ?array $providers = null,
        private readonly ?AICredentialMetadataRepository $credentialRepo = null,
    ) {
    }

    /**
     * @param string $feature feature name (validated against ProviderCatalog by callers)
     * @param string|null $capability required capability (e.g. 'vision'); null = no filter
     * @return array<int,array<string,mixed>> executable chain entries:
     *   {provider, model, priority, route, fallback_index}
     */
    public function resolveChain(string $feature, ?string $capability = null): array
    {
        $repo = $this->repository ?? new AIFeatureProviderRepository();
        $rows = $repo->tableExists() ? $repo->chainFor($feature) : [];

        if ($rows !== []) {
            return $this->buildFromRows($rows, $capability);
        }

        return $this->buildEnvDefaultChain($capability);
    }

    /** Source of the last resolved chain — for admin overview and tests. */
    public function sourceFor(string $feature): string
    {
        $repo = $this->repository ?? new AIFeatureProviderRepository();
        $rows = $repo->tableExists() ? $repo->chainFor($feature) : [];
        return $rows !== [] ? self::SOURCE_DB : self::SOURCE_ENV_DEFAULT;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function buildFromRows(array $rows, ?string $capability): array
    {
        $chain = [];
        foreach ($rows as $row) {
            if ((int) $row['enabled'] !== 1) {
                continue;
            }
            $name = (string) $row['provider'];
            $provider = $this->instantiate($name);
            if ($provider === null) {
                continue;
            }
            if (!$this->hasCapability($provider, $capability)) {
                continue;
            }
            $route = isset($row['route']) && $row['route'] !== null && $row['route'] !== ''
                ? (string) $row['route']
                : null;
            if (!$this->credentialAvailable($provider, $name, $route)) {
                continue;
            }
            $chain[] = [
                'provider' => $name,
                'model' => isset($row['model']) && $row['model'] !== null && $row['model'] !== ''
                    ? (string) $row['model']
                    : ProviderCatalog::defaultModel($name),
                'priority' => (int) $row['priority'],
                'route' => $route,
                'fallback_index' => count($chain),
            ];
        }
        return $chain;
    }

    /**
     * Environment-driven default chain — replicates the legacy AIManager
     * iteration exactly: AIProviderRegistry::loadEnabledProviders() order.
     *
     * @return array<int,array<string,mixed>>
     */
    public function buildEnvDefaultChain(?string $capability = null): array
    {
        if ($this->providers !== null) {
            // Injected instances (tests/DI): registry priority over the map.
            $providers = $this->providers;
            usort($providers, fn (AIProviderInterface $a, AIProviderInterface $b): int =>
                AIProviderRegistry::getPriority($a->getName()) <=> AIProviderRegistry::getPriority($b->getName()));
        } else {
            $registry = $this->registry ?? new AIProviderRegistry();
            $providers = $registry->loadEnabledProviders();
        }
        $chain = [];
        foreach ($providers as $provider) {
            if (!$this->hasCapability($provider, $capability)) {
                continue;
            }
            $name = $provider->getName();
            if (!$provider->isAvailable()) {
                continue;
            }
            // Runtime activation gate: confirmed-invalid credentials are routed.
            if (CredentialVerificationGate::isBlocked($name, $this->credentialRepo)) {
                continue;
            }
            $chain[] = [
                'provider' => $name,
                'model' => ProviderCatalog::defaultModel($name),
                'priority' => AIProviderRegistry::getPriority($name),
                'route' => null, // provider resolves its own route (legacy behavior)
                'fallback_index' => count($chain),
            ];
        }
        return $chain;
    }

    private function hasCapability(AIProviderInterface $provider, ?string $capability): bool
    {
        if ($capability === null || $capability === '') {
            return true;
        }
        return in_array($capability, $provider->getCapabilities(), true);
    }

    /**
     * Credential availability without exposing values: env presence only.
     *
     * route === null  → provider's own isAvailable() (route-aware, identical
     *                    to the legacy registry behavior — e.g. Gemini on
     *                    GEMINI_ROUTE=n8n_relay is available without API key).
     * route === 'n8n_relay' → GEMINI_RELAY_URL + GEMINI_RELAY_TOKEN present.
     * route === 'direct'    → the provider's direct credential key present.
     */
    private function credentialAvailable(AIProviderInterface $provider, string $name, ?string $route): bool
    {
        if (!ProviderCatalog::isRegisteredProvider($name)) {
            return false;
        }
        // Runtime activation gate: a confirmed-invalid credential is never routed,
        // even when it is present in the environment.
        if (CredentialVerificationGate::isBlocked($name, $this->credentialRepo)) {
            return false;
        }
        if ($route === null || $route === '') {
            return $provider->isAvailable();
        }
        if ($name === 'gemini' && $route === 'n8n_relay') {
            foreach (ProviderCatalog::relayKeys('gemini') as $key) {
                if (trim(Config::env($key, '')) === '') {
                    return false;
                }
            }
            return true;
        }
        // direct route: provider credential keys must be present
        foreach (ProviderCatalog::credentialKeys($name) as $key) {
            if (trim(Config::env($key, '')) === '') {
                return false;
            }
        }
        return true;
    }

    private function instantiate(string $name): ?AIProviderInterface
    {
        if ($this->providers !== null) {
            return $this->providers[strtolower(trim($name))] ?? null;
        }
        $class = ProviderCatalog::providerClass($name);
        if ($class === null || !class_exists($class)) {
            return null;
        }
        try {
            $instance = new $class();
            return $instance instanceof AIProviderInterface ? $instance : null;
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_ROUTER] failed to instantiate provider ' . $name);
            return null;
        }
    }
}
