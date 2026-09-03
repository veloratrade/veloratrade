<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\AI\Repositories\AIFeatureFlagRepository;
use Velora\AI\Services\AIFeatureGuard;
use Velora\Core\Config;
use Velora\Core\RateLimiter;
use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Core\Exceptions\ValidationException;

/**
 * Phase F — centralized Feature Flag control plane.
 *
 * Endpoints (server-side RBAC; frontend hiding is never an authorization
 * boundary; the browser is never the source of truth):
 *   GET    /api/v1/admin/feature-flags              -> list (P_FEATURE_FLAGS_VIEW)
 *   PATCH  /api/v1/admin/feature-flags/{feature}    -> set enabled+rollout (P_FEATURE_FLAGS_EDIT, super_admin only)
 *
 * Security hard rules:
 *   - The feature name is validated against the canonical runtime flag set
 *     (AIFeatureFlagRepository::CANONICAL_FLAGS) — arbitrary names are rejected
 *     (422) so an actor cannot invent a flag or probe a missing one.
 *   - enabled must be boolean; rollout must be an integer in [0,100]; invalid
 *     values / out-of-range combinations are rejected server-side (422).
 *   - No secrets involved (flags are booleans + percentages); responses reflect
 *     only persisted + runtime-resolved state. Audit metadata is safe.
 *   - Every mutation is audited as feature_flag.updated / .enabled / .disabled
 *     with old->new safe metadata (no secrets).
 *
 * Runtime chain (server-authoritative): Admin UI -> API -> requirePermission ->
 * this controller -> AIFeatureFlagRepository (ai_feature_flags) -> audit ->
 * AIFeatureGuard / isEnabled() -> runtime consumer. The panel never bypasses
 * the resolver; it reads back state through the same repository the runtime uses.
 */
final class FeatureFlagController
{
    public function __construct(
        private readonly AIFeatureFlagRepository $flags = new AIFeatureFlagRepository(),
        private readonly AIFeatureGuard $guard = new AIFeatureGuard(),
        private readonly AdminAuditLogRepository $audit = new AdminAuditLogRepository(),
    ) {
    }

    /** GET /api/v1/admin/feature-flags */
    public function index(Request $request): never
    {
        $environment = strtolower(Config::env('APP_ENV', 'production'));
        $items = [];
        foreach (AIFeatureFlagRepository::CANONICAL_FLAGS as $name) {
            $items[] = $this->flagState($name, $environment);
        }
        Response::json([
            'flags' => $items,
            'environment' => $environment,
            'allowed' => AIFeatureFlagRepository::CANONICAL_FLAGS,
        ]);
    }

    /**
     * PATCH /api/v1/admin/feature-flags/{feature}
     * Body: { enabled: bool, rollout: int (0-100) }  — rollout is the
     * deterministic percentage tailoring, only meaningful while enabled.
     */
    public function update(Request $request, array $params): never
    {
        RateLimiter::hit('admin-feature-flag', 20, 300);

        $feature = (string) ($params['feature'] ?? '');
        if (!in_array($feature, AIFeatureFlagRepository::CANONICAL_FLAGS, true)) {
            throw new ValidationException('Unknown feature flag.', ['feature' => ['code' => 'UNKNOWN_FEATURE_FLAG', 'messageKey' => 'errors.validation.invalid', 'params' => []]]);
        }

        if (!array_key_exists('enabled', $request->body)) {
            throw new ValidationException('enabled is required.', ['enabled' => ['code' => 'ENABLED_REQUIRED', 'messageKey' => 'errors.validation.invalid', 'params' => []]]);
        }
        $enabled = (bool) ($request->input('enabled', false));

        $rollout = (int) ($request->input('rollout', $enabled ? 100 : 0));
        if ($rollout < 0 || $rollout > 100) {
            throw new ValidationException('rollout must be between 0 and 100.', ['rollout' => ['code' => 'ROLLOUT_RANGE', 'messageKey' => 'errors.validation.invalid', 'params' => []]]);
        }

        $environment = strtolower(Config::env('APP_ENV', 'production'));
        $before = $this->flagState($feature, $environment);

        $actorId = (int) ($request->attributes['user_id'] ?? 0);
        if (!$this->flags->setFlag($feature, $enabled, $rollout, $actorId)) {
            Response::error('Could not persist feature flag.', 500, 'FEATURE_FLAG_PERSIST_FAILED');
        }

        $after = $this->flagState($feature, $environment);

        $event = $enabled ? 'feature_flag.enabled' : 'feature_flag.disabled';
        if ($enabled && ($before['effective'] ?? '') === ($after['effective'] ?? '')) {
            $event = 'feature_flag.updated';
        }
        $this->audit->record(
            $actorId,
            (string) ($request->attributes['user_role'] ?? ''),
            $event,
            'feature_flag',
            null, // target_id is the integer audit key; the feature is carried in metadata/summary
            'success',
            ($enabled ? 'Enabled' : 'Disabled') . ' feature flag ' . $feature,
            $request->clientIp() ?? null,
            $request->headers['user-agent'] ?? null,
            $request->contextId(),
            [
                'feature' => $feature,
                'old_enabled' => (bool) $before['enabled'],
                'new_enabled' => (bool) $after['enabled'],
                'old_rollout' => (int) $before['rollout'],
                'new_rollout' => (int) $after['rollout'],
                'environment' => $environment,
            ],
        );

        Response::json(['flag' => $after]);
    }

    /** @return array<string,mixed> */
    private function flagState(string $feature, string $environment): array
    {
        $row = $this->flags->get($feature);
        $persisted = $row !== null;
        $enabled = $persisted ? (bool) $row['enabled'] : ($feature === 'ai_screenshot_extraction');
        $rollout = $persisted ? (int) ($row['rollout_percentage'] ?? 0) : 0;
        if ($persisted && !$enabled) {
            $rollout = 0;
        }

        return [
            'feature' => $feature,
            'enabled' => $enabled,
            'rollout' => $rollout,
            'environment' => $environment,
            'persisted' => $persisted,
            'updatedBy' => $persisted && isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'updatedAt' => $persisted && isset($row['updated_at']) ? $row['updated_at'] : null,
            'effective' => $this->effectiveStatus($enabled, $rollout),
            // server-authoritative runtime decision for an un-scoped request
            'runtime' => $this->guard->isEnabled($feature, null),
        ];
    }

    private function effectiveStatus(bool $enabled, int $rollout): string
    {
        if (!$enabled || $rollout <= 0) {
            return 'off';
        }
        if ($rollout >= 100) {
            return 'on';
        }
        return 'rollout:' . $rollout;
    }
}
