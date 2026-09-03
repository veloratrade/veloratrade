<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\RateLimiter;
use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Core\SecureCredentialStore;
use Velora\Core\Exceptions\ValidationException;

/**
 * Phase A — Admin-managed n8n Gemini Relay configuration.
 *
 * Goal: allow an authorized Super Admin to set the relay URL + relay token from
 * the Admin Panel, persist them securely (encrypted at rest), and have the
 * runtime actually consume them WITHOUT editing a file / `.env`.
 *
 * Authorization (server-side, existing RBAC model — never frontend-only):
 *   - Read/status:  Role::P_INTEGRATIONS_VIEW   (admin + super_admin)
 *   - Write/delete: Role::P_INTEGRATIONS_MANAGE (super_admin only)
 *   `P_INTEGRATIONS_MANAGE` is granted only to super_admin in
 *   Role::permissionMap(); a plain `admin` receives HTTP 403 PERMISSION_DENIED.
 *
 * Storage: SecureCredentialStore encrypted secret store
 * ({VELORA_PRIVATE_ROOT}/config/velora-secrets.json, file mode 0600,
 * AES-256-GCM via Crypto with APP_ENCRYPTION_KEY). The plaintext velora.env is
 * left as the backward-compatible FALLBACK for existing deployments.
 *
 * Security hard rules:
 *   - The relay TOKEN is never returned, echoed, logged, or audited.
 *     All responses expose only booleans + a URL host "safe representation".
 *   - The URL IS returned to authorized admins (it is needed to render the
 *     panel and is not a secret), but this endpoint never echoes the request
 *     body blindly; it reflects only the validated, persisted value's host.
 *   - Audit events record that a relay config WAS changed and safe metadata
 *     (which key), never the value.
 */
final class RelayConfigController
{
    public function __construct(
        private readonly AdminAuditLogRepository $audit = new AdminAuditLogRepository(),
    ) {
    }

    /**
     * GET /api/v1/admin/integrations/relay/config
     *
     * Returns SAFE metadata only. Never the token. For the URL we return a
     * host-only representation (no path/query, which may embed tokens) plus the
     * encrypted-status booleans. Effective values are resolved by a dedicated
     * resolver (RelayConfigResolver) so the reported "configured" reflects the
     * exact precedence the runtime uses.
     */
    public function show(Request $request): never
    {
        Response::json([
            'config' => [
                'configured' => RelayConfigResolver::isConfigured(),
                'urlConfigured' => RelayConfigResolver::hasUrl(),
                'tokenConfigured' => RelayConfigResolver::hasToken(),
                // Safe host representation (never full URL; never token).
                'urlHost' => RelayConfigResolver::safeUrlHost(),
            ],
        ]);
    }

    /**
     * PUT /api/v1/admin/integrations/relay/config
     *
     * Body: { url?: string, token?: string }
     * At least one of url/token required. Validates the URL server-side
     * (https required, no embedded credentials, no SSRF-prone shape), stores the
     * token encrypted at rest, and audits a safe event.
     */
    public function update(Request $request): never
    {
        RateLimiter::hit('admin-relay-config', 15, 300);
        $this->write($request);
    }

    /**
     * DELETE /api/v1/admin/integrations/relay/config
     *
     * Clears both the encrypted relay URL and token (explicit removal is
     * supported by the store). The runtime then falls back to env / becomes
     * unavailable.
     */
    public function clear(Request $request): never
    {
        RateLimiter::hit('admin-relay-config', 15, 300);
        $changed = false;
        if (SecureCredentialStore::secretStatus(SecureCredentialStore::SECRET_GEMINI_RELAY_URL)) {
            SecureCredentialStore::encryptDelete(SecureCredentialStore::SECRET_GEMINI_RELAY_URL);
            $changed = true;
        }
        if (SecureCredentialStore::secretStatus(SecureCredentialStore::SECRET_GEMINI_RELAY_TOKEN)) {
            SecureCredentialStore::encryptDelete(SecureCredentialStore::SECRET_GEMINI_RELAY_TOKEN);
            $changed = true;
        }
        if ($changed) {
            $this->audit->record(
                (int) ($request->attributes['user_id'] ?? 0),
                (string) ($request->attributes['user_role'] ?? ''),
                'relay_config.cleared',
                'integration',
                null,
                'success',
                'Admin cleared n8n Gemini relay configuration.',
                $request->clientIp() ?? null,
                $request->headers['user-agent'] ?? null,
                $request->contextId(),
                ['scope' => 'gemini_relay'],
            );
        }
        Response::json(['config' => RelayConfigResolver::safeStatus()]);
    }

    // ---------------------------------------------------------------- private

    private function write(Request $request): never
    {
        $url = trim((string) $request->input('url', ''));
        $token = (string) $request->input('token', '');

        if ($url === '' && $token === '') {
            throw new ValidationException('At least one of url or token is required.', [
                'config' => ['code' => 'RELAY_CONFIG_EMPTY'],
            ]);
        }

        $urlChanged = false;
        $tokenChanged = false;

        if ($url !== '') {
            $this->assertValidUrl($url);
            SecureCredentialStore::encryptWrite(SecureCredentialStore::SECRET_GEMINI_RELAY_URL, $url);
            $urlChanged = true;
        }
        if ($token !== '') {
            // Token is validated by the store (non-empty, size, no line controls);
            // it is encrypted before persistence and never returned.
            SecureCredentialStore::encryptWrite(SecureCredentialStore::SECRET_GEMINI_RELAY_TOKEN, $token);
            $tokenChanged = true;
        }

        // Inventory of safe metadata recorded (never any value).
        $meta = ['scope' => 'gemini_relay'];
        if ($urlChanged) {
            $meta['urlUpdated'] = true;
        }
        if ($tokenChanged) {
            $meta['tokenUpdated'] = true;   // that a token changed, never the token
        }
        $this->audit->record(
            (int) ($request->attributes['user_id'] ?? 0),
            (string) ($request->attributes['user_role'] ?? ''),
            'relay_config.updated',
            'integration',
            null,
            'success',
            'Admin updated n8n Gemini relay configuration.',
            $request->clientIp() ?? null,
            $request->headers['user-agent'] ?? null,
            $request->contextId(),
            $meta,
        );

        Response::json(['config' => RelayConfigResolver::safeStatus()]);
    }

    /**
     * Server-side URL validation (SSRF-conscious). Rejects:
     *   - non-https schemes (production relay is HTTPS-only)
     *   - embedded userinfo (user:pass@)
     *   - empty/bad host
     *   - obviously internal targets (loopback / link-local / private) — this
     *     is a defensive guard so an admin cannot accidentally point the relay
     *     at an internal address.
     */
    private function assertValidUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
            throw new ValidationException('Relay URL must use https.', ['url' => ['code' => 'INVALID_RELAY_URL']]);
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new ValidationException('Relay URL must not embed credentials.', ['url' => ['code' => 'INVALID_RELAY_URL']]);
        }
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            throw new ValidationException('Relay URL must include a host.', ['url' => ['code' => 'INVALID_RELAY_URL']]);
        }
        if ($this->isInternalHost($host)) {
            throw new ValidationException('Relay URL must not target an internal address.', ['url' => ['code' => 'INVALID_RELAY_URL']]);
        }
        if (strlen($url) > 2048) {
            throw new ValidationException('Relay URL is too long.', ['url' => ['code' => 'INVALID_RELAY_URL']]);
        }
    }

    private function isInternalHost(string $host): bool
    {
        $h = strtolower(rtrim($host, '.'));
        if ($h === 'localhost' || $h === '127.0.0.1' || str_ends_with($h, '.localhost') || $h === '::1') {
            return true;
        }
        // IP-literal private/loopback/link-local ranges.
        if (filter_var($h, FILTER_VALIDATE_IP)) {
            return !filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        return false;
    }
}
