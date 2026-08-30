<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\AI\Repositories\AIFeatureFlagRepository;
use Velora\AI\Repositories\AIFeatureProviderRepository;
use Velora\AI\Repositories\AIProviderQuotaRepository;
use Velora\AI\Services\FeatureRouter;
use Velora\AI\Services\ProviderCatalog;
use Velora\Core\Config;
use Velora\Core\RateLimiter;
use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Core\SecureCredentialStore;

/**
 * Admin AI configuration API — REAL persisted state only.
 *
 * - Every route is behind the existing admin RBAC middleware (403
 *   ADMIN_REQUIRED for normal users; wired in api/index.php).
 * - All provider/model/route/feature/priority/enabled values are validated
 *   against server-side allowlists (ProviderCatalog). Invalid => 422 and no
 *   persisted change.
 * - Every mutation is verified by read-back; a mismatch aborts with 409
 *   AI_CONFIG_PERSIST_MISMATCH. Success responses carry the authoritative
 *   re-selected row, never an echo of the request.
 * - Credential endpoints return ONLY {configured: bool}. Values are never
 *   returned, echoed, or logged.
 * - When the routing table is empty, features report their REAL runtime
 *   fallback chain with source="env-default" — no fabricated rows, ever.
 */
final class AIConfigController
{
    public function __construct(
        private readonly AIFeatureProviderRepository $rows = new AIFeatureProviderRepository(),
        private readonly AIFeatureFlagRepository $flags = new AIFeatureFlagRepository(),
        private readonly AIProviderQuotaRepository $quotas = new AIProviderQuotaRepository(),
        private readonly FeatureRouter $router = new FeatureRouter(),
    ) {
    }

    // ------------------------------------------------------------------ GET

    public function overview(Request $request): never
    {
        Response::json([
            'providers' => $this->providerStatuses(),
            'features' => $this->featureStatuses(),
            'routingTableExists' => $this->rows->tableExists(),
            'routingRowCount' => $this->rows->countAll(),
        ]);
    }

    /**
     * Real runtime provider metadata: capabilities come from the actual
     * provider classes, credential status from env/file presence (booleans),
     * quota from the existing ai_provider_quotas rows when present.
     *
     * @return array<int,array<string,mixed>>
     */
    private function providerStatuses(): array
    {
        $out = [];
        foreach (ProviderCatalog::providerNames() as $name) {
            $class = ProviderCatalog::providerClass($name);
            $entry = [
                'provider' => $name,
                'registered' => true,
                'capabilities' => [],
                'available' => false,
                'credentialStatus' => ['required' => false, 'configured' => null, 'envKey' => null],
                'quota' => null,
            ];
            if ($class !== null && class_exists($class)) {
                try {
                    $instance = new $class();
                    $entry['capabilities'] = $instance->getCapabilities();
                    $entry['available'] = $instance->isAvailable();
                } catch (\Throwable $e) {
                    // status stays unavailable; never leak internals
                }
            }
            if (ProviderCatalog::isCredentialProvider($name)) {
                $key = ProviderCatalog::credentialKeys($name)[0];
                $entry['credentialStatus'] = [
                    'required' => true,
                    'configured' => SecureCredentialStore::status($key),
                    'envKey' => $key, // key NAME only, never the value
                ];
            }
            if ($name === 'gemini') {
                $entry['relay'] = [
                    'urlConfigured' => SecureCredentialStore::status('GEMINI_RELAY_URL'),
                    'tokenConfigured' => SecureCredentialStore::status('GEMINI_RELAY_TOKEN'),
                ];
                try {
                    $instance = new \Velora\AI\Providers\GeminiProvider();
                    $entry['effectiveRoute'] = $instance->getRoute();
                } catch (\Throwable $e) {
                    $entry['effectiveRoute'] = null;
                }
            }
            $entry['defaultModel'] = ProviderCatalog::defaultModel($name);
            $entry['modelAllowlist'] = ProviderCatalog::modelAllowlist($name);
            $entry['routeAllowlist'] = $name === 'gemini'
                ? ['direct', 'n8n_relay']
                : ($name === 'tesseract' ? [] : ['direct']);
            $entry['quota'] = $this->safeQuota($name);
            $out[] = $entry;
        }
        return $out;
    }

    /** Real per-feature state: flag + persisted chain or the true env-default. */
    private function featureStatuses(): array
    {
        $flagRows = [];
        try {
            foreach ($this->flags->all() as $row) {
                $flagRows[(string) $row['feature_name']] = [
                    'enabled' => (bool) $row['enabled'],
                    'rolloutPercentage' => (int) ($row['rollout_percentage'] ?? 100),
                ];
            }
        } catch (\Throwable $e) {
            // flags table missing — reported as unknown below
        }

        $out = [];
        foreach (ProviderCatalog::FEATURES as $feature) {
            $capability = ProviderCatalog::FEATURE_CAPABILITY[$feature] ?? null;
            $chain = $this->router->resolveChain($feature, $capability);
            $out[] = [
                'feature' => $feature,
                'capability' => $capability,
                'flag' => $flagRows[$feature] ?? null,
                'source' => $this->router->sourceFor($feature),
                'chain' => $chain,
                'rows' => $this->serializeRows($this->rows->chainFor($feature)),
            ];
        }
        return $out;
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
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ------------------------------------------------------------- mutations

    public function create(Request $request): never
    {
        RateLimiter::hit('admin-ai-config', 30, 300);
        $body = $request->body;
        $feature = (string) ($body['feature'] ?? '');
        $provider = strtolower(trim((string) ($body['provider'] ?? '')));
        $model = $this->normalizeModel($body['model'] ?? null);
        $priority = (int) ($body['priority'] ?? 0);
        $enabled = array_key_exists('enabled', $body) ? (bool) $body['enabled'] : true;
        $route = $this->normalizeRoute($body['route'] ?? null);

        $errors = $this->validate($feature, $provider, $model, $priority, $enabled, $route);
        if ($errors !== []) {
            Response::error('Invalid AI provider configuration.', 422, 'VALIDATION_FAILED', $errors);
        }

        try {
            $existing = $this->rows->chainFor($feature);
            foreach ($existing as $row) {
                if ((string) $row['provider'] === $provider) {
                    Response::error('Provider already exists for this feature.', 409, 'AI_CONFIG_DUPLICATE');
                }
            }
            if ($priority === 0) {
                $priority = count($existing) + 1; // append at the end
                if ($priority > 20) {
                    Response::error('Invalid AI provider configuration.', 422, 'VALIDATION_FAILED', ['priority' => 'chain is full']);
                }
            }
            $persisted = $this->rows->insert([
                'feature' => $feature,
                'provider' => $provider,
                'model' => $model,
                'priority' => $priority,
                'enabled' => $enabled,
                'route' => $route,
            ]);
        } catch (\Velora\Core\Exceptions\ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[VELORA_ADMIN_AI] create failed: ' . $e->getMessage());
            Response::error('AI configuration storage is unavailable.', 503, 'AI_CONFIG_STORAGE_UNAVAILABLE');
        }

        $this->verifyReadback($persisted, [
            'feature' => $feature,
            'provider' => $provider,
            'model' => $model,
            'priority' => $priority,
            'enabled' => $enabled,
            'route' => $route,
        ]);
        Response::json(['featureProvider' => $this->serializeRow($persisted)], 201);
    }

    public function update(Request $request, array $params): never
    {
        RateLimiter::hit('admin-ai-config', 30, 300);
        $id = (int) ($params['id'] ?? 0);
        $row = $this->rows->find($id);
        if ($row === null) {
            Response::error('Feature provider row not found.', 404, 'AI_CONFIG_NOT_FOUND');
        }

        $body = $request->body;
        $feature = array_key_exists('feature', $body) ? (string) $body['feature'] : (string) $row['feature'];
        $provider = array_key_exists('provider', $body)
            ? strtolower(trim((string) $body['provider']))
            : (string) $row['provider'];
        $model = array_key_exists('model', $body)
            ? $this->normalizeModel($body['model'] ?? null)
            : $this->normalizeModel($row['model'] ?? null);
        $priority = array_key_exists('priority', $body) ? (int) $body['priority'] : (int) $row['priority'];
        $enabled = array_key_exists('enabled', $body) ? (bool) $body['enabled'] : ((int) $row['enabled'] === 1);
        $route = array_key_exists('route', $body)
            ? $this->normalizeRoute($body['route'] ?? null)
            : $this->normalizeRoute($row['route'] ?? null);

        $errors = $this->validate($feature, $provider, $model, $priority, $enabled, $route);
        if ($errors !== []) {
            Response::error('Invalid AI provider configuration.', 422, 'VALIDATION_FAILED', $errors);
        }

        try {
            $persisted = $this->rows->update($id, [
                'feature' => $feature,
                'provider' => $provider,
                'model' => $model,
                'priority' => $priority,
                'enabled' => $enabled,
                'route' => $route,
            ]);
        } catch (\Velora\Core\Exceptions\ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[VELORA_ADMIN_AI] update failed: ' . $e->getMessage());
            Response::error('AI configuration storage is unavailable.', 503, 'AI_CONFIG_STORAGE_UNAVAILABLE');
        }
        if ($persisted === null) {
            Response::error('Feature provider row not found.', 404, 'AI_CONFIG_NOT_FOUND');
        }

        $this->verifyReadback($persisted, [
            'feature' => $feature,
            'provider' => $provider,
            'model' => $model,
            'priority' => $priority,
            'enabled' => $enabled,
            'route' => $route,
        ]);
        Response::json(['featureProvider' => $this->serializeRow($persisted)]);
    }

    public function delete(Request $request, array $params): never
    {
        RateLimiter::hit('admin-ai-config', 30, 300);
        $id = (int) ($params['id'] ?? 0);
        $row = $this->rows->find($id);
        if ($row === null) {
            Response::error('Feature provider row not found.', 404, 'AI_CONFIG_NOT_FOUND');
        }
        try {
            $deleted = $this->rows->delete($id);
        } catch (\Throwable $e) {
            error_log('[VELORA_ADMIN_AI] delete failed: ' . $e->getMessage());
            Response::error('AI configuration storage is unavailable.', 503, 'AI_CONFIG_STORAGE_UNAVAILABLE');
        }
        if (!$deleted || $this->rows->find($id) !== null) {
            Response::error('Persisted state does not match the requested operation.', 409, 'AI_CONFIG_PERSIST_MISMATCH');
        }
        Response::json(['deleted' => true, 'id' => $id]);
    }

    public function reorder(Request $request): never
    {
        RateLimiter::hit('admin-ai-config', 30, 300);
        $body = $request->body;
        $feature = (string) ($body['feature'] ?? '');
        $ids = $body['orderedIds'] ?? null;
        if (!in_array($feature, ProviderCatalog::FEATURES, true) || !is_array($ids)) {
            Response::error('Invalid AI provider configuration.', 422, 'VALIDATION_FAILED');
        }
        $rows = $this->rows->chainFor($feature);
        $existingIds = array_map(fn (array $r): int => (int) $r['id'], $rows);
        $requested = array_map('intval', $ids);
        sort($existingIds);
        sort($requested);
        if ($existingIds !== $requested || count(array_unique($requested)) !== count($requested)) {
            Response::error('Ordered ids must be a permutation of the feature chain.', 422, 'VALIDATION_FAILED');
        }

        try {
            $rows = $this->rows->reorder($feature, array_map('intval', $ids));
        } catch (\Throwable $e) {
            error_log('[VELORA_ADMIN_AI] reorder failed: ' . $e->getMessage());
            Response::error('AI configuration storage is unavailable.', 503, 'AI_CONFIG_STORAGE_UNAVAILABLE');
        }

        // Read-back: priorities must equal request order.
        foreach ($rows as $i => $row) {
            if ((int) $row['priority'] !== $i + 1 || (int) $row['id'] !== (int) $ids[$i]) {
                Response::error('Persisted state does not match the requested operation.', 409, 'AI_CONFIG_PERSIST_MISMATCH');
            }
        }
        Response::json(['feature' => $feature, 'rows' => $this->serializeRows($rows)]);
    }

    // ------------------------------------------------------------ credentials

    public function replaceCredential(Request $request, array $params): never
    {
        RateLimiter::hit('admin-ai-config', 15, 300);
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));
        $keys = ProviderCatalog::credentialKeys($provider);
        if ($keys === []) {
            Response::error('Provider does not use a credential key.', 422, 'VALIDATION_FAILED');
        }
        $value = (string) ($request->body['value'] ?? '');
        try {
            SecureCredentialStore::replace($keys[0], $value);
        } catch (\Throwable $e) {
            error_log('[VELORA_ADMIN_AI] credential replace failed for provider ' . $provider . ': ' . $e->getMessage());
            Response::error('Credential storage is unavailable on this host.', 503, 'AI_CREDENTIAL_STORE_UNAVAILABLE');
        }
        // Authoritative read-back — boolean only, never the value.
        Response::json(['provider' => $provider, 'configured' => SecureCredentialStore::status($keys[0])]);
    }

    public function deleteCredential(Request $request, array $params): never
    {
        RateLimiter::hit('admin-ai-config', 15, 300);
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));
        $keys = ProviderCatalog::credentialKeys($provider);
        if ($keys === []) {
            Response::error('Provider does not use a credential key.', 422, 'VALIDATION_FAILED');
        }
        try {
            SecureCredentialStore::delete($keys[0]);
        } catch (\Throwable $e) {
            error_log('[VELORA_ADMIN_AI] credential delete failed for provider ' . $provider . ': ' . $e->getMessage());
            Response::error('Credential storage is unavailable on this host.', 503, 'AI_CREDENTIAL_STORE_UNAVAILABLE');
        }
        Response::json(['provider' => $provider, 'configured' => SecureCredentialStore::status($keys[0])]);
    }

    // -------------------------------------------------------------- helpers

    /** Server-side allowlist validation; invalid values never reach storage. */
    private function validate(string $feature, string $provider, ?string $model, int $priority, bool $enabled, ?string $route): array
    {
        $errors = [];
        if (!in_array($feature, ProviderCatalog::FEATURES, true)) {
            $errors['feature'] = 'unsupported feature';
        }
        if (!ProviderCatalog::isRegisteredProvider($provider)) {
            $errors['provider'] = 'unsupported provider';
        } else {
            if (!ProviderCatalog::isValidModel($provider, $model)) {
                $errors['model'] = 'model not in provider allowlist';
            }
            if (!ProviderCatalog::isValidRoute($provider, $route)) {
                $errors['route'] = 'route not allowed for provider';
            }
        }
        if ($priority !== 0 && ($priority < 1 || $priority > 20)) {
            $errors['priority'] = 'must be 1..20';
        }
        if (!is_bool($enabled)) {
            $errors['enabled'] = 'must be boolean';
        }
        return $errors;
    }

    /** WRITE → SELECT read-back → compare; mismatch => 409, never fake success. */
    private function verifyReadback(array $persisted, array $intended): void
    {
        $actual = [
            'feature' => (string) $persisted['feature'],
            'provider' => strtolower((string) $persisted['provider']),
            'model' => $this->normalizeModel($persisted['model'] ?? null),
            'priority' => (int) $persisted['priority'],
            'enabled' => (int) $persisted['enabled'] === 1,
            'route' => $this->normalizeRoute($persisted['route'] ?? null),
        ];
        foreach ($intended as $key => $value) {
            if ($actual[$key] !== $value) {
                Response::error('Persisted state does not match the requested operation.', 409, 'AI_CONFIG_PERSIST_MISMATCH');
            }
        }
    }

    private function normalizeModel(mixed $model): ?string
    {
        if (!is_string($model)) {
            return null;
        }
        $model = trim($model);
        return $model === '' ? null : $model;
    }

    private function normalizeRoute(mixed $route): ?string
    {
        if (!is_string($route)) {
            return null;
        }
        $route = strtolower(trim($route));
        return $route === '' ? null : $route;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function serializeRows(array $rows): array
    {
        return array_map(fn (array $r): array => $this->serializeRow($r), $rows);
    }

    /** @param array<string,mixed> $row */
    private function serializeRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'feature' => (string) $row['feature'],
            'provider' => (string) $row['provider'],
            'model' => $row['model'],
            'priority' => (int) $row['priority'],
            'enabled' => (int) $row['enabled'] === 1,
            'route' => $row['route'],
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }
}
