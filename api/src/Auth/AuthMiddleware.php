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

    /** Restrict route to admins only (roadmap v1.0 RBAC, wired now). */
    public static function adminOnly(): callable
    {
        return static function (Request $request): void {
            if (($request->attributes['user_role'] ?? null) !== 'admin') {
                throw new ForbiddenException('Administrator access required.', 'ADMIN_REQUIRED', 'errors.auth.adminRequired');
            }
        };
    }
}
