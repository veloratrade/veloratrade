<?php

declare(strict_types=1);

namespace Velora\Auth;

use Velora\Core\Exceptions\ForbiddenException;
use Velora\Core\Exceptions\UnauthorizedException;
use Velora\Core\Request;

/**
 * Auth middleware factory — attach to protected routes:
 *   $router->get('/api/v1/trades', [TradeController::class, 'index'], [AuthMiddleware::authenticate()]);
 *
 * Resolves the user from the Bearer access token and stores the public user
 * array on $request->attributes['user'].
 *
 * RBAC is SERVER-SIDE. The frontend may hide controls, but authorization is
 * always decided here against Role::can(). There is no role where hiding a
 * button in the UI is treated as a security boundary.
 */
final class AuthMiddleware
{
    public static function authenticate(): callable
    {
        return static function (Request $request): void {
            $service = new AuthService();
            $user = $service->authenticate($request->bearerToken());
            $request->attributes['user'] = $service->publicUser($user);
            $request->attributes['user_id'] = (int) $user['id'];
            $request->attributes['user_role'] = $user['role'];
        };
    }

    /**
     * Grant the Admin Panel to Admin and Super Admin roles.
     * Backward compatible: existing routes/tests that used adminOnly() continue
     * to allow role 'admin' and deny ordinary users.
     */
    public static function adminOnly(): callable
    {
        return static function (Request $request): void {
            $role = (string) ($request->attributes['user_role'] ?? '');
            if (!Role::isPanel($role)) {
                throw new ForbiddenException('Administrator access required.', 'ADMIN_REQUIRED', 'errors.auth.adminRequired');
            }
        };
    }

    /** Grant a route only to Super Admin (full system control). */
    public static function superAdminOnly(): callable
    {
        return static function (Request $request): void {
            if (($request->attributes['user_role'] ?? null) !== Role::SUPER_ADMIN) {
                throw new ForbiddenException('Super Administrator access required.', 'SUPER_ADMIN_REQUIRED', 'errors.auth.adminRequired');
            }
        };
    }

    /**
     * Grant a route only to a role that holds the given permission. This is the
     * granular authorization boundary for sensitive/privileged operations.
     */
    public static function requirePermission(string $permission): callable
    {
        return static function (Request $request) use ($permission): void {
            $role = (string) ($request->attributes['user_role'] ?? '');
            if (!Role::can($role, $permission)) {
                throw new ForbiddenException('Insufficient privileges.', 'PERMISSION_DENIED', 'errors.auth.adminRequired');
            }
        };
    }
}
