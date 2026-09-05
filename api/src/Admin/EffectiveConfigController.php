<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\AI\Services\EffectiveConfigService;
use Velora\Core\Request;
use Velora\Core\Response;

/**
 * Phase 1 — effective configuration (read-only, admin-only).
 *
 * GET /api/v1/admin/config/effective
 *
 * Authorized server-side via the `admin` middleware (adminOnly). Read-only:
 * no mutation, no side effects. Secret-free: it reports presence booleans and
 * metadata status only — never a credential value.
 */
final class EffectiveConfigController
{
    public function __construct(
        private readonly EffectiveConfigService $service = new EffectiveConfigService(),
    ) {
    }

    public function show(Request $request): never
    {
        Response::json(['config' => $this->service->getConfig()]);
    }
}
