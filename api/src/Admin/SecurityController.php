<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Auth\Role;

/**
 * Admin security view (Module C / Module I).
 *
 * Returns the CURRENT administrator's authentication/authorization state. This
 * is derived SERVER-SIDE from the authenticated user (set by AuthMiddleware) —
 * never inferred on the client. The frontend uses this only to render the role
 * and which controls the user may see; it is NEVER an authorization boundary
 * (the backend enforces everything).
 */
final class SecurityController
{
    public function me(Request $request): never
    {
        $role = (string) ($request->attributes['user_role'] ?? '');
        $userId = (int) ($request->attributes['user_id'] ?? 0);

        // Recent admin actions attributable to this actor (safe, no secrets).
        $audit = new AdminAuditLogRepository();
        $recent = $audit->list(['actor' => $userId], 1, 8);

        Response::json([
            'me' => [
                'userId' => $userId,
                'role' => $role,
                'isSuperAdmin' => $role === Role::SUPER_ADMIN,
                'panel' => Role::isPanel($role),
                'permissions' => Role::permissionsFor($role),
            ],
            'recentAdminActions' => array_map(static fn (array $r) => [
                'action' => $r['action'],
                'targetType' => $r['targetType'],
                'targetId' => $r['targetId'],
                'result' => $r['result'],
                'summary' => $r['summary'],
                'createdAt' => $r['createdAt'],
            ], $recent['items']),
        ]);
    }

    public function permissions(Request $request): never
    {
        Response::json(['permissions' => Role::permissionMap()]);
    }
}
