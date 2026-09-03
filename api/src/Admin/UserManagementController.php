<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Core\Exceptions\NotFoundException;
use Velora\Core\Exceptions\ValidationException;
use Velora\Core\RateLimiter;
use Velora\Auth\Role;
use Velora\Trades\TradeRepository;

/**
 * Protected Admin user-management actions (Module B / Module C).
 *
 * All routes carry RBAC middleware (requirePermission) in api/index.php so
 * ordinary/Pro users get 403 on direct HTTP requests. Target-relative checks
 * (e.g. an admin may not suspend another admin) are enforced inside
 * UserManagementService. Every state-changing action appends an audit event
 * (best-effort) and returns a sanitized response (never credentials).
 */
final class UserManagementController
{
    private UserManagementService $service;
    private AdminAuditLogRepository $audit;

    public function __construct(?UserManagementService $service = null, ?AdminAuditLogRepository $audit = null)
    {
        $this->service = $service ?? new UserManagementService();
        $this->audit = $audit ?? new AdminAuditLogRepository();
    }

    public function show(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $user = $this->service->userDetail($id, (int) ($request->attributes['user_id'] ?? 0), (string) ($request->attributes['user_role'] ?? ''));
        Response::json(['user' => $user]);
    }

    public function setStatus(Request $request, array $params): never
    {
        RateLimiter::hit('admin-user-action', 30, 300);
        $id = (int) ($params['id'] ?? 0);
        $status = (string) $request->input('status', '');
        $actorId = (int) ($request->attributes['user_id'] ?? 0);
        $actorRole = (string) ($request->attributes['user_role'] ?? '');

        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new ValidationException('Invalid status.', ['status' => ['code' => 'INVALID_STATUS']]);
        }

        $result = $this->service->setStatus($id, $status, $actorId, $actorRole, 'status');
        $this->audit->record(
            $actorId, $actorRole,
            $status === 'suspended' ? 'user.suspend' : 'user.activate',
            'user', $id, 'success',
            "User #$id $status",
            $request->clientIp() ?? null,
            $request->headers['user-agent'] ?? null,
            $request->contextId(),
            ['status' => $status, 'role' => $actorRole],
        );
        Response::json(['ok' => true, 'user' => $result]);
    }

    public function setRole(Request $request, array $params): never
    {
        RateLimiter::hit('admin-user-action', 30, 300);
        $id = (int) ($params['id'] ?? 0);
        $role = (string) $request->input('role', '');
        $actorId = (int) ($request->attributes['user_id'] ?? 0);
        $actorRole = (string) ($request->attributes['user_role'] ?? '');

        if (!Role::isValidStored($role)) {
            throw new ValidationException('Invalid role.', ['role' => ['code' => 'INVALID_ROLE']]);
        }

        $result = $this->service->setRole($id, $role, $actorId, $actorRole);
        $this->audit->record(
            $actorId, $actorRole, 'user.role.change', 'user', $id, 'success',
            "User #$id role -> $role",
            $request->clientIp() ?? null,
            $request->headers['user-agent'] ?? null,
            $request->contextId(),
            ['role' => $role],
        );
        Response::json(['ok' => true, 'user' => $result]);
    }

    public function setSubscription(Request $request, array $params): never
    {
        RateLimiter::hit('admin-user-action', 30, 300);
        $id = (int) ($params['id'] ?? 0);
        $actorId = (int) ($request->attributes['user_id'] ?? 0);
        $actorRole = (string) ($request->attributes['user_role'] ?? '');

        $data = [
            'plan' => $request->input('plan'),
            'status' => $request->input('status'),
            'expires_at' => $request->input('expires_at'),
            'started_at' => $request->input('started_at'),
        ];

        $result = $this->service->setSubscription($id, $data, $actorId, $actorRole);
        $this->audit->record(
            $actorId, $actorRole, 'user.subscription.change', 'user', $id, 'success',
            "User #$id subscription -> {$result['plan']}/{$result['status']}",
            $request->clientIp() ?? null,
            $request->headers['user-agent'] ?? null,
            $request->contextId(),
            ['plan' => $result['plan'], 'status' => $result['status']],
        );
        Response::json(['ok' => true, 'user' => $result]);
    }

    // ---- Phase E: User 360 read endpoints (P_USERS_VIEW-gated) -----------

    /** Safe per-user trading-account list (never the credential blob). */
    public function accounts(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $accounts = $this->service->userAccounts(
            $id,
            (int) ($request->attributes['user_id'] ?? 0),
            (string) ($request->attributes['user_role'] ?? ''),
        );
        Response::json(['accounts' => $accounts]);
    }

    /** Paginated per-user trades via the canonical TradeRepository. */
    public function trades(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        if ($this->service->userDetail($id, (int) ($request->attributes['user_id'] ?? 0), (string) ($request->attributes['user_role'] ?? '')) === null) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }
        $repo = new TradeRepository();
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->query['per_page'] ?? 20)));
        $result = $repo->search(
            ['user_id' => $id],
            ['limit' => $perPage, 'offset' => ($page - 1) * $perPage, 'order' => (string) ($request->query['order'] ?? 'close_time')],
        );
        Response::json([
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /** Per-user login/session activity (safe fields; no tokens). */
    public function activity(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $result = $this->service->userActivity(
            $id,
            (int) ($request->attributes['user_id'] ?? 0),
            (string) ($request->attributes['user_role'] ?? ''),
            (int) ($request->query['page'] ?? 1),
            (int) ($request->query['per_page'] ?? 25),
        );
        Response::json($result);
    }

    /** User-specific audit trail; sensitive fields only for Super Admin. */
    public function audit(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $actorRole = (string) ($request->attributes['user_role'] ?? '');
        $sensitive = Role::can($actorRole, Role::P_AUDIT_SENSITIVE_VIEW);
        $result = $this->service->userAudit(
            $id,
            (int) ($request->attributes['user_id'] ?? 0),
            $actorRole,
            $request->query,
            (int) ($request->query['page'] ?? 1),
            (int) ($request->query['per_page'] ?? 25),
        );

        $items = array_map(static function (array $row) use ($sensitive): array {
            $out = [
                'id' => $row['id'],
                'actorUserId' => $row['actorUserId'],
                'actorRole' => $row['actorRole'],
                'action' => $row['action'],
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

    /** Revoke all active sessions of a user (P_USERS_SUSPEND-gated, audited). */
    public function revokeSessions(Request $request, array $params): never
    {
        RateLimiter::hit('admin-user-action', 30, 300);
        $id = (int) ($params['id'] ?? 0);
        $actorId = (int) ($request->attributes['user_id'] ?? 0);
        $actorRole = (string) ($request->attributes['user_role'] ?? '');

        $n = $this->service->revokeSessions($id, $actorId, $actorRole);
        $this->audit->record(
            $actorId, $actorRole, 'user.sessions.revoked', 'user', $id, 'success',
            "User #$id sessions revoked ($n)",
            $request->clientIp() ?? null,
            $request->headers['user-agent'] ?? null,
            $request->contextId(),
            ['revoked' => $n],
        );
        Response::json(['ok' => true, 'revoked' => $n]);
    }
}
