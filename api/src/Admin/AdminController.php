<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Request;
use Velora\Core\Response;

/**
 * Admin list endpoints (roadmap v1.0 RBAC panel).
 * Protected by admin-only middleware — standard users get 403.
 *
 * GET /api/v1/admin/users — paginated/filterable user list. The top-level
 * "users" key is preserved for backward compatibility; pagination + filtering
 * metadata is added alongside it. Server-side RBAC is enforced by the route
 * middleware (requirePermission user.list).
 */
final class AdminController
{
    public function users(Request $request): never
    {
        $service = new UserManagementService();
        $result = $service->listUsers(
            filters: $request->query,
            page: (int) ($request->query['page'] ?? 1),
            perPage: (int) ($request->query['per_page'] ?? 25),
        );

        Response::json([
            'users' => $result['users'],
            'pagination' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'has_more' => $result['has_more'],
            ],
        ]);
    }
}
