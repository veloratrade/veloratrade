<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Request;
use Velora\Core\Response;

/**
 * Phase H — Analytics + Revenue Intelligence (read-only, admin-only).
 *
 * Endpoints (server-side RBAC; browser is never the source of truth):
 *   GET /api/v1/admin/analytics/overview   -> mixed-domain summary (P_ANALYTICS_VIEW)
 *   GET /api/v1/admin/analytics/users      -> product: users (P_ANALYTICS_VIEW)
 *   GET /api/v1/admin/analytics/trading    -> product: trades (P_ANALYTICS_VIEW)
 *   GET /api/v1/admin/analytics/ai         -> product: AI requests (P_ANALYTICS_VIEW)
 *   GET /api/v1/admin/analytics/operations -> operational (P_ANALYTICS_VIEW)
 *   GET /api/v1/admin/analytics/revenue    -> financial: ALWAYS unavailable
 *                                             (P_ANALYTICS_VIEW)
 *
 * Authorization: admin + super_admin. Ordinary users are denied (P_ANALYTICS_VIEW
 * is only administered to admin/super_admin in Role::permissionMap()).
 *
 * Honesty contract:
 *   - Financial metrics are never fabricated/zeroed: available:false,
 *     reason:NO_BILLING_SOURCE (no real billing system exists in the repo).
 *   - Trading aggregate P&L is labelled trading performance, NOT revenue.
 *   - All output is aggregated; no PII (email/full_name/password_hash) and no
 *     secrets (provider credentials/tokens/session ids) are ever returned.
 *   - Options: range=today|7d|30d|90d|all OR custom start/end (YYYY-MM-DD); all
 *     date validation is server-side, bounded to a max range, in UTC.
 *
 * Audit: these are read-only observability reads — no mutation audit events are
 * generated (consistent with the other admin read endpoints). No export/config
 * mutations are introduced by this module.
 */
final class AnalyticsController
{
    public function __construct(
        private readonly AnalyticsService $service = new AnalyticsService(),
    ) {
    }

    /** GET /api/v1/admin/analytics/overview */
    public function overview(Request $request): never
    {
        Response::json($this->service->overview($request->query));
    }

    /** GET /api/v1/admin/analytics/users */
    public function users(Request $request): never
    {
        Response::json($this->service->users($request->query));
    }

    /** GET /api/v1/admin/analytics/trading */
    public function trading(Request $request): never
    {
        Response::json($this->service->trading($request->query));
    }

    /** GET /api/v1/admin/analytics/ai */
    public function ai(Request $request): never
    {
        Response::json($this->service->ai($request->query));
    }

    /** GET /api/v1/admin/analytics/operations */
    public function operations(Request $request): never
    {
        Response::json($this->service->operations($request->query));
    }

    /** GET /api/v1/admin/analytics/revenue */
    public function revenue(Request $request): never
    {
        Response::json($this->service->revenue());
    }
}
