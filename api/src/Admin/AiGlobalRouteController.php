<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\AI\Providers\GeminiProvider;
use Velora\AI\Services\AiRouteResolver;
use Velora\Core\RateLimiter;
use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Core\Exceptions\ValidationException;

/**
 * Phase B — Admin-managed GLOBAL AI route.
 *
 * Endpoints (server-side RBAC; frontend hiding is never an authorization boundary):
 *   GET    /api/v1/admin/ai/route   -> read effective+configured+source   (admin)
 *   PUT    /api/v1/admin/ai/route   -> save explicit global route (super_admin only)
 *   DELETE /api/v1/admin/ai/route   -> reset to inherited/legacy       (super_admin only)
 *
 * Authorization:
 *   Read  -> P_AI_MANAGE (admin + super_admin).
 *   Write -> P_AI_ROUTE_MANAGE (super_admin only; admin receives 403 from the
 *            server, exactly the pattern used for integrations/manage and users
 *            change-role).
 *   User  -> denied entirely (not in the panel at all; adminOnly middleware).
 *
 * Security hard rules:
 *   - The route is an allowlisted value ('direct' | 'n8n_relay'), validated
 *     server-side; arbitrary strings are rejected (422) with a message code.
 *   - No secrets involved; the route is not a credential. We never echo a blind
 *     body; responses reflect only the validated, resolved value.
 *   - Audit records the actor, action, old->new route (safe metadata), never a
 *     secret.
 *
 * UI truthfulness (never fabricate connectivity): the response distinguishes
 *   configured  -> explicitly saved by Admin (or null = inherit)
 *   effective   -> what runtime actually uses (admin > env > flag > direct)
 *   source      -> which layer produced the effective value
 */
final class AiGlobalRouteController
{
    public function __construct(
        private readonly AiRouteResolver $resolver = new AiRouteResolver(),
        private readonly AdminAuditLogRepository $audit = new AdminAuditLogRepository(),
    ) {
    }

    /** GET /api/v1/admin/ai/route */
    public function show(Request $request): never
    {
        Response::json(['route' => $this->safeStatus()]);
    }

    /**
     * PUT /api/v1/admin/ai/route
     * Body: { route: 'direct' | 'n8n_relay' }
     */
    public function update(Request $request): never
    {
        RateLimiter::hit('admin-ai-route', 15, 300);

        $route = strtolower(trim((string) $request->input('route', '')));
        if (!AiRouteResolver::isValidRoute($route)) {
            throw new ValidationException('Invalid AI route.', ['route' => ['code' => 'INVALID_AI_ROUTE']]);
        }

        $before = $this->safeStatus();
        $actorId = (int) ($request->attributes['user_id'] ?? 0);
        if (!$this->resolver->save($route, $actorId)) {
            Response::error('Could not persist AI route.', 500, 'AI_ROUTE_PERSIST_FAILED');
        }

        $after = $this->safeStatus();
        $this->audit->record(
            $actorId,
            (string) ($request->attributes['user_role'] ?? ''),
            'ai_route.updated',
            'system',
            null,
            'success',
            'Admin updated global AI route.',
            $request->clientIp() ?? null,
            $request->headers['user-agent'] ?? null,
            $request->contextId(),
            ['old_route' => $before['effective'], 'new_route' => $after['effective']],
        );

        Response::json(['route' => $after]);
    }

    /** DELETE /api/v1/admin/ai/route — reset to inherited/legacy behaviour. */
    public function clear(Request $request): never
    {
        RateLimiter::hit('admin-ai-route', 15, 300);

        $before = $this->safeStatus();
        $actorId = (int) ($request->attributes['user_id'] ?? 0);
        $cleared = $this->resolver->clear();

        $after = $this->safeStatus();
        if ($cleared) {
            $this->audit->record(
                $actorId,
                (string) ($request->attributes['user_role'] ?? ''),
                'ai_route.reset',
                'system',
                null,
                'success',
                'Admin reset global AI route (inherit legacy).',
                $request->clientIp() ?? null,
                $request->headers['user-agent'] ?? null,
                $request->contextId(),
                ['old_route' => $before['effective'], 'effective_after' => $after['effective']],
            );
        }

        Response::json(['route' => $after]);
    }

    /**
     * Safe status map (no secrets). configured = explicitly-saved admin value
     * (null = inherit); effective + source come from the single runtime resolver.
     *
     * @return array<string,mixed>
     */
    private function safeStatus(): array
    {
        $resolved = $this->resolver->resolveWithSource();
        $configured = $this->resolver->configuredRoute();
        return [
            'configured' => $configured,
            'effective' => $resolved['route'],
            'source' => $resolved['source'],
            // allowlist echoed for the panel (non-secret)
            'allowed' => [AiRouteResolver::ROUTE_DIRECT, AiRouteResolver::ROUTE_RELAY],
            // real runtime view of the provider as it would run today
            'providerEffective' => (new GeminiProvider())->getRoute(),
        ];
    }
}
