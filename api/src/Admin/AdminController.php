<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Database;
use Velora\Core\Request;
use Velora\Core\Response;

/**
 * Minimal admin endpoints (roadmap v1.0 RBAC panel, stub for v0.1).
 * Protected by admin-only middleware — standard users get 403.
 */
final class AdminController
{
    public function users(Request $request): never
    {
        $stmt = Database::connection()->query(
            'SELECT u.id, u.email, u.full_name, u.role, u.status, u.created_at,
                    (SELECT COUNT(*) FROM trades t WHERE t.user_id = u.id) AS trades_count
             FROM users u
             ORDER BY u.created_at DESC
             LIMIT 200'
        );

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'email' => $row['email'],
                'fullName' => $row['full_name'],
                'role' => $row['role'],
                'status' => $row['status'],
                'createdAt' => $row['created_at'],
                'tradesCount' => (int) $row['trades_count'],
            ];
        }

        Response::json(['users' => $rows]);
    }
}
