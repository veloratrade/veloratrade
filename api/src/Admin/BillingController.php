<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Request;
use Velora\Core\Response;

/**
 * Phase G — Billing + Subscription read-only control plane.
 *
 * Endpoints (server-side RBAC; the browser is never the source of truth):
 *   GET /api/v1/admin/billing             -> platform billing overview (P_BILLING_VIEW)
 *   GET /api/v1/admin/billing/users/{id}  -> per-user billing + entitlement state (P_USERS_VIEW)
 *
 * Authorization: admin + super_admin (read-only observability, no provider
 * credentials involved). Subscription MUTATION is intentionally NOT added here:
 * it already exists as the audited, RBAC-gated
 * POST /api/v1/admin/users/{id}/subscription (P_USERS_MANAGE_SUBSCRIPTION) on
 * the UserManagementController, which Phase G reuses rather than duplicating.
 *
 * Honesty contract (classification C — no real billing system):
 *   - Reports real internal subscription state (users.* v1.3).
 *   - Reports real entitlements (account limit from config, per-user AI usage,
 *     internal provider quota).
 *   - Reports `provider.available=false`, plan price not authoritative, and
 *     history unavailable — never fabricates customer/provider/trial/invoice.
 *   - No secrets are read or returned (there is no provider credential).
 */
final class BillingController
{
    public function __construct(
        private readonly BillingService $service = new BillingService(),
    ) {
    }

    /** GET /api/v1/admin/billing */
    public function overview(Request $request): never
    {
        Response::json($this->service->overview());
    }

    /** GET /api/v1/admin/billing/users/{id} */
    public function user(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        Response::json($this->service->user($id));
    }
}
