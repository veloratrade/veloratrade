<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\RateLimiter;
use Velora\Core\Request;
use Velora\Core\Response;

/**
 * Phase 8 (8a) — Settings endpoints.
 *
 *  GET    /api/v1/admin/settings          (settings.view — supervisory read)
 *  PUT    /api/v1/admin/settings/{key}    (system.settings.manage — super_admin only)
 *  DELETE /api/v1/admin/settings/{key}    (system.settings.manage — reset to env/default)
 *
 * Server-side authorization is the only boundary (frontend gating is UX).
 * Mutations follow the established pattern: rate limit → strict allowlist +
 * typed validation → write → before/after audit (non-secret values only).
 */
final class SettingsController
{
    public function __construct(
        private readonly AdminSettingsService $settings = new AdminSettingsService(),
        private readonly AdminAuditLogRepository $audit = new AdminAuditLogRepository(),
    ) {
    }

    /** GET /api/v1/admin/settings */
    public function index(Request $request): never
    {
        Response::json(['settings' => $this->settings->inventory()]);
    }

    /** PUT /api/v1/admin/settings/{key} — body: { value: string } */
    public function update(Request $request, array $params): never
    {
        RateLimiter::hit('admin-settings', 15, 300);

        $key = (string) ($params['key'] ?? '');
        $value = (string) ($request->input('value', ''));
        $actorId = (int) ($request->attributes['user_id'] ?? 0);

        $result = $this->settings->set($key, $value, $actorId);

        $this->audit->record(
            $actorId,
            (string) ($request->attributes['user_role'] ?? ''),
            'settings.updated',
            'setting',
            null,
            'success',
            'Admin updated platform setting.',
            $request->clientIp() ?? null,
            $request->headers['user-agent'] ?? null,
            $request->contextId(),
            ['key' => $result['key'], 'old' => $result['old'], 'new' => $result['new']],
        );

        Response::json(['setting' => [
            'key' => $result['key'],
            'value' => $result['new'],
            'source' => 'admin',
            'writable' => true,
        ]]);
    }

    /** DELETE /api/v1/admin/settings/{key} — reset to env/default. */
    public function reset(Request $request, array $params): never
    {
        RateLimiter::hit('admin-settings', 15, 300);

        $key = (string) ($params['key'] ?? '');
        $actorId = (int) ($request->attributes['user_id'] ?? 0);

        $result = $this->settings->reset($key, $actorId);

        $this->audit->record(
            $actorId,
            (string) ($request->attributes['user_role'] ?? ''),
            'settings.reset',
            'setting',
            null,
            'success',
            'Admin reset platform setting to inherited value.',
            $request->clientIp() ?? null,
            $request->headers['user-agent'] ?? null,
            $request->contextId(),
            ['key' => $result['key'], 'old' => $result['old']],
        );

        Response::json(['setting' => [
            'key' => $result['key'],
            'value' => null,
            'source' => 'env-default',
            'writable' => true,
        ]]);
    }
}
