<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Request;
use Velora\Core\Response;

/** Admin Overview + System Health endpoints (Modules A & F). */
final class OverviewController
{
    public function __construct(private readonly AdminOverviewService $service = new AdminOverviewService())
    {
    }

    public function overview(Request $request): never
    {
        Response::json(['overview' => $this->service->overview()]);
    }

    public function health(Request $request): never
    {
        $overview = $this->service->overview();
        Response::json([
            'health' => [
                'api' => $overview['system']['api'],
                'database' => $overview['system']['database'],
                'metaapi' => $overview['system']['metaapi'],
                'aiProviders' => $overview['system']['aiProviders'],
                'email' => $overview['system']['email'],
                'workers' => $overview['system']['workers'],
            ],
        ]);
    }
}
