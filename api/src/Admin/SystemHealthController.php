<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Core\RateLimiter;
use Velora\Core\SecretRedactor;

/**
 * Phase D — System + Integration health (view + bounded refresh).
 *
 * GET  /api/v1/admin/system/diagnostics  -> snapshot (P_SYSTEM_HEALTH_VIEW)
 * POST /api/v1/admin/system/diagnostics/refresh
 *       -> bounded, rate-limited live probe of the integrations that support a
 *          safe probe (MetaAPI, Email via IntegrationConnectivityProbe). Never
 *          sends email. Result is cached in integration_health so a repeated
 *          refresh cannot storm external providers. (P_SYSTEM_HEALTH_VIEW)
 *
 * Every returned value is secret-free. The cache lookup supersedes config guess
 * only with a REAL probe outcome; no fabricated timestamp is ever produced.
 */
final class SystemHealthController
{
    public function __construct(
        private readonly SystemHealthService $service = new SystemHealthService(),
        private readonly IntegrationHealthRepository $health = new IntegrationHealthRepository(),
        private readonly IntegrationConnectivityProbe $probe = new IntegrationConnectivityProbe(),
    ) {
    }

    public function diagnostics(Request $request): never
    {
        Response::json(['health' => $this->service->snapshot()]);
    }

    public function refresh(Request $request): never
    {
        // Debounce: heavy rate limit so an admin refresh is not a DDoS against
        // external integrations. Reuses the existing RateLimiter (DB buckets).
        RateLimiter::hit('admin-system-health-refresh', 5, 120);

        $results = [];

        $meta = $this->probe->metaApi();
        $this->health->remember(
            'metaapi',
            $this->probeToHealth($meta['status']),
            (int) ($meta['latencyMs'] ?? 0),
            $meta['status'] === 'SUCCESS' ? null : $meta['status'],
            $this->safeMessage($meta['message']),
        );
        $results['metaapi'] = $this->probeResult($meta);

        $mail = $this->probe->email();
        $this->health->remember(
            'email',
            $this->probeToHealth($mail['status']),
            (int) ($mail['latencyMs'] ?? 0),
            $mail['status'] === 'SUCCESS' ? null : $mail['status'],
            $this->safeMessage($mail['message']),
        );
        $results['email'] = $this->probeResult($mail);

        Response::json([
            'health' => $this->service->snapshot(),
            'probe' => $results,
        ]);
    }

    private function probeToHealth(string $probeStatus): string
    {
        return match ($probeStatus) {
            'SUCCESS' => 'HEALTHY',
            'NOT_CONFIGURED' => 'NOT_CONFIGURED',
            'AUTH_FAILED', 'TIMEOUT', 'NETWORK_ERROR', 'SERVICE_UNAVAILABLE' => 'UNHEALTHY',
            default => 'UNKNOWN',
        };
    }

    private function probeResult(array $p): array
    {
        return [
            'status' => $p['status'],
            'reachable' => (bool) ($p['reachable'] ?? false),
            'verified' => (bool) ($p['verified'] ?? false),
            'latencyMs' => $p['latencyMs'] ?? null,
            'checkedAt' => $p['checkedAt'] ?? null,
            'message' => $this->safeMessage($p['message'] ?? null),
        ];
    }

    private function safeMessage(?string $m): ?string
    {
        return $m === null ? null : (string) SecretRedactor::text($m);
    }
}
