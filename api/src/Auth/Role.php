<?php

declare(strict_types=1);

namespace Velora\Auth;

/**
 * Velora role + permission model.
 *
 * CRITICAL ARCHITECTURAL RULE: RBAC role and subscription plan are SEPARATE.
 *
 * Roles are stored as users.role. The canonical stored values are:
 *   guest        -> unauthenticated (never persisted; the pre-auth state)
 *   user         -> registered, verified member
 *   admin        -> operational administrator
 *   super_admin  -> full system control
 *
 * Subscription is NOT a role. It is stored in separate columns (users.plan,
 * users.subscription_status) and NEVER grants authorization. A "Pro" customer
 * is a normal `user` with `plan='pro'`; it has the SAME permissions as any
 * other user. The v1.3 migration therefore keeps users.role to
 * ('user','admin','super_admin') and holds the plan/status separately.
 *
 * "guest" is intentionally not a stored value: it is the state of a request
 * that carries no valid token.
 *
 * Every privileged operation is enforced SERVER-SIDE via a permission lookup
 * (Role::can()). The frontend MAY also hide controls, but that is never treated
 * as an authorization boundary. A subscription plan must never elevate a user
 * to an administrative role.
 */
final class Role
{
    public const GUEST = 'guest';
    public const USER = 'user';
    public const ADMIN = 'admin';
    public const SUPER_ADMIN = 'super_admin';

    /** Roles that may enter the Admin Panel at all. */
    public const PANEL_ROLES = [self::ADMIN, self::SUPER_ADMIN];

    // ---- Permission identifiers ----------------------------------------
    public const P_OVERVIEW_VIEW = 'overview.view';
    public const P_USERS_VIEW = 'users.view';
    public const P_USERS_SUSPEND = 'users.suspend';
    public const P_USERS_ACTIVATE = 'users.activate';
    public const P_USERS_CHANGE_ROLE = 'users.change_role';       // Super Admin only
    public const P_USERS_MANAGE_SUBSCRIPTION = 'users.manage_subscription';
    public const P_AUDIT_VIEW = 'audit.view';
    public const P_AUDIT_SENSITIVE_VIEW = 'audit.view_sensitive'; // Super Admin only
    public const P_SYSTEM_HEALTH_VIEW = 'system.health.view';
    public const P_SYSTEM_LOGS_VIEW = 'system.logs.view';
    public const P_SETTINGS_VIEW = 'settings.view';               // reserved (Module K)
    public const P_SETTINGS_MANAGE = 'system.settings.manage';     // Super Admin only (reserved)
    // Billing + Subscription (Phase G). Observable local subscription state +
    // real, runtime-backed entitlements; NO payment provider, NO fake financial
    // data. VIEW = admin + super_admin (read-only observability; subscription
    // mutation stays on the existing audited P_USERS_MANAGE_SUBSCRIPTION path).
    public const P_BILLING_VIEW = 'billing.view';
    // Feature Flags (Phase F). Dotted convention matches P_AUDIT_VIEW/P_SYSTEM_*.
    // VIEW = operational admin + super_admin; EDIT = super_admin only (flags are
    // server-authoritative runtime switches for ALL users => privileged).
    public const P_FEATURE_FLAGS_VIEW = 'feature_flags.view';
    public const P_FEATURE_FLAGS_EDIT = 'feature_flags.edit';       // Super Admin only
    public const P_INTEGRATIONS_VIEW = 'integrations.view';        // reserved (Module H)
    public const P_INTEGRATIONS_MANAGE = 'integrations.manage';    // Super Admin only (reserved)
    // Analytics + Revenue Intelligence (Phase H). Dotted convention. VIEW =
    // admin + super_admin (read-only product/operational analytics; financial
    // analytics are deliberately always `available:false, reason:NO_BILLING_SOURCE`
    // because no authoritative billing source exists in the repository).
    public const P_ANALYTICS_VIEW = 'analytics.view';
    // Existing AI config is Admin/Super Admin (routes use adminOnly + this perm).
    // Token kept non-dotted to avoid colliding with the i18n catalog namespace
    // ('ai.*' is a localization key prefix detected by the validators).
    public const P_AI_MANAGE = 'aiManage';
    public const P_AI_ROUTE_MANAGE = 'aiRouteManage';   // Super Admin only

    /**
     * Permission map. The frontend never decides authorization; this is the
     * single server-side source of truth for what a role may do.
     *
     * Admin = normal operational administration.
     * Super Admin = privileged/system-level operations.
     *
     * @return array<string, list<string>>
     */
    public static function permissionMap(): array
    {
        return [
            self::USER => [],
            self::ADMIN => [
                self::P_OVERVIEW_VIEW,
                self::P_USERS_VIEW,
                self::P_USERS_SUSPEND,
                self::P_USERS_ACTIVATE,
                self::P_USERS_MANAGE_SUBSCRIPTION,
                self::P_AUDIT_VIEW,
                self::P_SYSTEM_HEALTH_VIEW,
                self::P_SYSTEM_LOGS_VIEW,
                self::P_SETTINGS_VIEW,
                self::P_FEATURE_FLAGS_VIEW,
                self::P_BILLING_VIEW,
                self::P_INTEGRATIONS_VIEW,
                self::P_AI_MANAGE,
                self::P_ANALYTICS_VIEW,
            ],
            self::SUPER_ADMIN => [
                self::P_OVERVIEW_VIEW,
                self::P_USERS_VIEW,
                self::P_USERS_SUSPEND,
                self::P_USERS_ACTIVATE,
                self::P_USERS_CHANGE_ROLE,
                self::P_USERS_MANAGE_SUBSCRIPTION,
                self::P_AUDIT_VIEW,
                self::P_AUDIT_SENSITIVE_VIEW,
                self::P_SYSTEM_HEALTH_VIEW,
                self::P_SYSTEM_LOGS_VIEW,
                self::P_SETTINGS_VIEW,
                self::P_SETTINGS_MANAGE,
                self::P_FEATURE_FLAGS_VIEW,
                self::P_FEATURE_FLAGS_EDIT,
                self::P_BILLING_VIEW,
                self::P_INTEGRATIONS_VIEW,
                self::P_INTEGRATIONS_MANAGE,
                self::P_AI_MANAGE,
                self::P_AI_ROUTE_MANAGE,
                self::P_ANALYTICS_VIEW,
            ],
        ];
    }

    /** True if $role has permission $permission (server-side authorization). */
    public static function can(string $role, string $permission): bool
    {
        return in_array($permission, self::permissionMap()[$role] ?? [], true);
    }

    /** True if $role is a panel (Admin/Super Admin) role. */
    public static function isPanel(string $role): bool
    {
        return in_array($role, self::PANEL_ROLES, true);
    }

    /** Roles considered "privileged" (must never be granted by a plain admin). */
    public static function isPrivileged(string $role): bool
    {
        return $role === self::ADMIN || $role === self::SUPER_ADMIN;
    }

    public static function isValidStored(string $role): bool
    {
        return in_array($role, [self::USER, self::ADMIN, self::SUPER_ADMIN], true);
    }

    /** Effective permissions for a role (for the RBAC UI). */
    public static function permissionsFor(string $role): array
    {
        return self::permissionMap()[$role] ?? [];
    }
}
