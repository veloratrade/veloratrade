<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Core\SystemLogRepository;

/**
 * Phase D — System Log viewer.
 *
 * GET /api/v1/admin/logs/system (P_SYSTEM_LOGS_VIEW)
 *   Filters: severity | source | since | until | q (message/request/error).
 *   Pagination: page, per_page. Newest first.
 *
 * Every row is redacted at read time (in addition to redaction at write time),
 * so a SecretRedactor bug can never leak a stored secret to the Admin UI.
 */
final class SystemLogController
{
    public function __construct(private readonly SystemLogRepository $repo = new SystemLogRepository())
    {
    }

    public function index(Request $request): never
    {
        $result = $this->repo->list(
            filters: $request->query,
            page: (int) ($request->query['page'] ?? 1),
            perPage: (int) ($request->query['per_page'] ?? 50),
        );
        Response::json([
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
        ]);
    }
}
