<?php

declare(strict_types=1);

namespace Velora\Dashboard;

use Velora\Core\Request;
use Velora\Core\Response;

/**
 * HTTP layer for /api/v1/dashboard/* — summary + equity curve.
 */
final class DashboardController
{
    public function __construct(
        private readonly MetricsService $metrics = new MetricsService(),
    ) {
    }

    public function summary(Request $request): never
    {
        $userId = (int) $request->attributes['user_id'];
        $summary = $this->metrics->summary($userId);
        $summary['equityCurve'] = $this->metrics->equityCurve($userId, 30);
        Response::json(['summary' => $summary]);
    }

    public function equityCurve(Request $request): never
    {
        $userId = (int) $request->attributes['user_id'];
        $days = max(7, min(365, (int) ($request->query['days'] ?? 30)));
        Response::json(['equityCurve' => $this->metrics->equityCurve($userId, $days)]);
    }

    public function strategies(Request $request): never
    {
        $userId = (int) $request->attributes['user_id'];
        Response::json(['strategies' => $this->metrics->perStrategy($userId)]);
    }
}
