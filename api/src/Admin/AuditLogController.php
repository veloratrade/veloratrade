<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Auth\Role;

/**
 * Admin audit-log viewer (Module H / I).
 *
 * The trail is append-only (no update/delete path in the codebase). Access is
 * gated server-side: listing requires `audit.view` (Admin/Super Admin);
 * sensitive correlation fields (ip, context id) are exposed only when the
 * actor holds `audit.sensitive.view` (Super Admin).
 */
final class AuditLogController
{
    public function __construct(private readonly AdminAuditLogRepository $audit = new AdminAuditLogRepository())
    {
    }

    public function index(Request $request): never
    {
        $sensitive = Role::can((string) ($request->attributes['user_role'] ?? ''), Role::P_AUDIT_SENSITIVE_VIEW);
        $result = $this->audit->list(
            filters: $request->query,
            page: (int) ($request->query['page'] ?? 1),
            perPage: (int) ($request->query['per_page'] ?? 50),
        );

        $items = array_map(static function (array $row) use ($sensitive): array {
            $out = [
                'id' => $row['id'],
                'actorUserId' => $row['actorUserId'],
                'actorRole' => $row['actorRole'],
                'action' => $row['action'],
                'targetType' => $row['targetType'],
                'targetId' => $row['targetId'],
                'result' => $row['result'],
                'summary' => $row['summary'],
                'createdAt' => $row['createdAt'],
            ];
            if ($sensitive) {
                $out['ipAddress'] = $row['ipAddress'];
                $out['contextId'] = $row['contextId'];
            }
            return $out;
        }, $result['items']);

        Response::json([
            'items' => $items,
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
        ]);
    }
}
