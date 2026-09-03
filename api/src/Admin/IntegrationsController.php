<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\IntegrationConfigResolver;
use Velora\Core\RateLimiter;
use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Core\SecureCredentialStore;
use Velora\Core\Exceptions\ValidationException;

/**
 * Phase C — Admin-managed external integrations (MetaAPI, Email).
 *
 * Follows the exact Phase A (RelayConfigController) + Phase B (AiGlobalRouteController)
 * pattern: server-authoritative, minimal API, RBAC via the existing permission
 * map, secrets encrypted at rest and never returned/logged/audited, and runtime
 * consumption proven through IntegrationConfigResolver (single authority).
 *
 * Authorization (server-side, never frontend-only):
 *   - Read/status:   Role::P_INTEGRATIONS_VIEW   (admin + super_admin)
 *   - Write/delete:  Role::P_INTEGRATIONS_MANAGE (super_admin only)
 *   - Test:          Role::P_INTEGRATIONS_MANAGE (super_admin only; it performs
 *                    an authenticated upstream call and/or uses credentials)
 *   - User:          not a panel role (adminOnly middleware rejects).
 *
 * Storage:
 *   - SECRETS   -> SecureCredentialStore encrypted store (AES-256-GCM,
 *                  APP_ENCRYPTION_KEY, VELORA_PRIVATE_ROOT/config/velora-secrets.json, 0600).
 *   - NON-SECRET operational config (base URL, driver, from-address/name, SMTP
 *                  host/port/user) -> generic settings table (reused, no new table).
 *
 * Config vs Reachability: `configured` reflects the effective resolver value;
 * `reachability` is only ever a real probe result (default 'unknown'). We never
 * display "Connected" merely because a string was saved.
 */
final class IntegrationsController
{
    public function __construct(
        private readonly AdminAuditLogRepository $audit = new AdminAuditLogRepository(),
        private readonly IntegrationConnectivityProbe $probe = new IntegrationConnectivityProbe(),
    ) {
    }

    /**
     * GET /api/v1/admin/integrations — aggregate safe status of every managed
     * platform integration. No secrets. Reachability defaults to 'unknown'
     * unless a probe actually ran.
     */
    public function inventory(Request $request): never
    {
        Response::json([
            'integrations' => [
                'metaapi' => $this->metaApiStatus(),
                'email' => $this->emailStatus(),
            ],
        ]);
    }

    // ======================================================================
    // MetaAPI
    // ======================================================================

    /** GET /api/v1/admin/integrations/metaapi */
    public function metaApi(Request $request): never
    {
        Response::json(['integration' => $this->metaApiStatus()]);
    }

    /**
     * PUT /api/v1/admin/integrations/metaapi
     * Body: { token?: string, webhook_secret?: string, base_url?: string }
     * At least one field required. Secrets are never returned.
     */
    public function updateMetaApi(Request $request): never
    {
        RateLimiter::hit('admin-metaapi-config', 15, 300);
        $token = (string) $request->input('token', '');
        $webhookSecret = (string) $request->input('webhook_secret', '');
        $baseUrl = trim((string) $request->input('base_url', ''));

        if ($token === '' && $webhookSecret === '' && $baseUrl === '') {
            throw new ValidationException('At least one MetaAPI field is required.', [
                'integration' => ['code' => 'INTEGRATION_CONFIG_EMPTY'],
            ]);
        }

        $meta = ['integration' => 'metaapi'];
        $actorId = (int) ($request->attributes['user_id'] ?? 0);

        if ($token !== '') {
            SecureCredentialStore::encryptWrite(IntegrationConfigResolver::SECRET_METAAPI_TOKEN, $token);
            $meta['tokenUpdated'] = true; // never the token
        }
        if ($webhookSecret !== '') {
            SecureCredentialStore::encryptWrite(IntegrationConfigResolver::SECRET_METAAPI_WEBHOOK, $webhookSecret);
            $meta['webhookSecretUpdated'] = true; // never the secret
        }
        if ($baseUrl !== '') {
            $this->assertValidEndpointUrl($baseUrl);
            (new \Velora\Core\IntegrationSettingsRepository())->set(
                IntegrationConfigResolver::SETTING_METAAPI_BASE_URL,
                $baseUrl,
                $actorId,
            );
            $meta['baseUrlUpdated'] = true;
        }

        $this->audit->record(
            $actorId,
            (string) ($request->attributes['user_role'] ?? ''),
            'integration.metaapi.updated',
            'integration',
            null,
            'success',
            'Admin updated MetaAPI integration configuration.',
            $request->clientIp() ?? null,
            $request->headers['user-agent'] ?? null,
            $request->contextId(),
            $meta,
        );

        Response::json(['integration' => $this->metaApiStatus()]);
    }

    /** DELETE /api/v1/admin/integrations/metaapi — reset to inherited/legacy. */
    public function clearMetaApi(Request $request): never
    {
        RateLimiter::hit('admin-metaapi-config', 15, 300);
        $changed = false;

        if (SecureCredentialStore::secretStatus(IntegrationConfigResolver::SECRET_METAAPI_TOKEN)) {
            SecureCredentialStore::encryptDelete(IntegrationConfigResolver::SECRET_METAAPI_TOKEN);
            $changed = true;
        }
        if (SecureCredentialStore::secretStatus(IntegrationConfigResolver::SECRET_METAAPI_WEBHOOK)) {
            SecureCredentialStore::encryptDelete(IntegrationConfigResolver::SECRET_METAAPI_WEBHOOK);
            $changed = true;
        }
        $repo = new \Velora\Core\IntegrationSettingsRepository();
        if ($repo->delete(IntegrationConfigResolver::SETTING_METAAPI_BASE_URL)) {
            $changed = true;
        }

        if ($changed) {
            $this->audit->record(
                (int) ($request->attributes['user_id'] ?? 0),
                (string) ($request->attributes['user_role'] ?? ''),
                'integration.metaapi.reset',
                'integration',
                null,
                'success',
                'Admin reset MetaAPI integration configuration (inherit legacy).',
                $request->clientIp() ?? null,
                $request->headers['user-agent'] ?? null,
                $request->contextId(),
                ['integration' => 'metaapi'],
            );
        }
        Response::json(['integration' => $this->metaApiStatus()]);
    }

    /** POST /api/v1/admin/integrations/metaapi/test — real probe, classified. */
    public function testMetaApi(Request $request): never
    {
        RateLimiter::hit('admin-metaapi-test', 10, 120);
        $result = $this->probe->metaApi();
        $this->recordProbe($request, 'metaapi', $result['status']);
        Response::json(['test' => $result, 'integration' => $this->metaApiStatusWith($result)]);
    }

    // ======================================================================
    // Email
    // ======================================================================

    /** GET /api/v1/admin/integrations/email */
    public function email(Request $request): never
    {
        Response::json(['integration' => $this->emailStatus()]);
    }

    /**
     * PUT /api/v1/admin/integrations/email
     * Body (subset): { driver?, from?, from_name?, smtp_host?, smtp_port?,
     *                  smtp_user?, smtp_password?, resend_api_key? }
     * Secret fields are write-only and never returned.
     */
    public function updateEmail(Request $request): never
    {
        RateLimiter::hit('admin-email-config', 15, 300);
        $driver = strtolower(trim((string) $request->input('driver', '')));
        $from = trim((string) $request->input('from', ''));
        $fromName = trim((string) $request->input('from_name', ''));
        $host = trim((string) $request->input('smtp_host', ''));
        $port = (string) $request->input('smtp_port', '');
        $user = trim((string) $request->input('smtp_user', ''));
        $pass = (string) $request->input('smtp_password', '');
        $resendKey = (string) $request->input('resend_api_key', '');

        $hasAny = ($driver !== '' || $from !== '' || $fromName !== '' || $host !== ''
            || $port !== '' || $user !== '' || $pass !== '' || $resendKey !== '');
        if (!$hasAny) {
            throw new ValidationException('At least one email field is required.', [
                'integration' => ['code' => 'INTEGRATION_CONFIG_EMPTY'],
            ]);
        }

        $actorId = (int) ($request->attributes['user_id'] ?? 0);
        $repo = new \Velora\Core\IntegrationSettingsRepository();
        $meta = ['integration' => 'email'];

        if ($driver !== '') {
            $this->assertValidDriver($driver);
            $repo->set(IntegrationConfigResolver::SETTING_MAIL_DRIVER, $driver, $actorId);
            $meta['driverUpdated'] = true;
        }
        if ($from !== '') {
            $this->assertValidEmail($from);
            $repo->set(IntegrationConfigResolver::SETTING_MAIL_FROM, $from, $actorId);
            $meta['fromUpdated'] = true;
        }
        if ($fromName !== '') {
            if (mb_strlen($fromName) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $fromName) === 1) {
                throw new ValidationException('Invalid sender name.', ['from_name' => ['code' => 'INVALID_FORMAT']]);
            }
            $repo->set(IntegrationConfigResolver::SETTING_MAIL_FROM_NAME, $fromName, $actorId);
            $meta['fromNameUpdated'] = true;
        }
        if ($host !== '') {
            $repo->set(IntegrationConfigResolver::SETTING_MAIL_HOST, $host, $actorId);
            $meta['smtpHostUpdated'] = true;
        }
        if ($port !== '') {
            $p = (int) $port;
            if ($p < 1 || $p > 65535) {
                throw new ValidationException('Invalid SMTP port.', ['smtp_port' => ['code' => 'INVALID_FORMAT']]);
            }
            $repo->set(IntegrationConfigResolver::SETTING_MAIL_PORT, (string) $p, $actorId);
            $meta['smtpPortUpdated'] = true;
        }
        if ($user !== '') {
            $repo->set(IntegrationConfigResolver::SETTING_MAIL_USER, $user, $actorId);
            $meta['smtpUserUpdated'] = true;
        }
        if ($pass !== '') {
            SecureCredentialStore::encryptWrite(IntegrationConfigResolver::SECRET_SMTP_PASSWORD, $pass);
            $meta['smtpPasswordUpdated'] = true; // never the password
        }
        if ($resendKey !== '') {
            SecureCredentialStore::encryptWrite(IntegrationConfigResolver::SECRET_RESEND_API_KEY, $resendKey);
            $meta['resendKeyUpdated'] = true; // never the key
        }

        $this->audit->record(
            $actorId,
            (string) ($request->attributes['user_role'] ?? ''),
            'integration.email.updated',
            'integration',
            null,
            'success',
            'Admin updated email integration configuration.',
            $request->clientIp() ?? null,
            $request->headers['user-agent'] ?? null,
            $request->contextId(),
            $meta,
        );

        Response::json(['integration' => $this->emailStatus()]);
    }

    /** DELETE /api/v1/admin/integrations/email — reset to inherited/legacy. */
    public function clearEmail(Request $request): never
    {
        RateLimiter::hit('admin-email-config', 15, 300);
        $repo = new \Velora\Core\IntegrationSettingsRepository();
        $changed = false;
        foreach ([
            IntegrationConfigResolver::SETTING_MAIL_DRIVER,
            IntegrationConfigResolver::SETTING_MAIL_FROM,
            IntegrationConfigResolver::SETTING_MAIL_FROM_NAME,
            IntegrationConfigResolver::SETTING_MAIL_HOST,
            IntegrationConfigResolver::SETTING_MAIL_PORT,
            IntegrationConfigResolver::SETTING_MAIL_USER,
        ] as $key) {
            if ($repo->delete($key)) {
                $changed = true;
            }
        }
        if (SecureCredentialStore::secretStatus(IntegrationConfigResolver::SECRET_SMTP_PASSWORD)) {
            SecureCredentialStore::encryptDelete(IntegrationConfigResolver::SECRET_SMTP_PASSWORD);
            $changed = true;
        }
        if (SecureCredentialStore::secretStatus(IntegrationConfigResolver::SECRET_RESEND_API_KEY)) {
            SecureCredentialStore::encryptDelete(IntegrationConfigResolver::SECRET_RESEND_API_KEY);
            $changed = true;
        }

        if ($changed) {
            $this->audit->record(
                (int) ($request->attributes['user_id'] ?? 0),
                (string) ($request->attributes['user_role'] ?? ''),
                'integration.email.reset',
                'integration',
                null,
                'success',
                'Admin reset email integration configuration (inherit legacy).',
                $request->clientIp() ?? null,
                $request->headers['user-agent'] ?? null,
                $request->contextId(),
                ['integration' => 'email'],
            );
        }
        Response::json(['integration' => $this->emailStatus()]);
    }

    /** POST /api/v1/admin/integrations/email/test — connectivity/auth probe. Never sends mail. */
    public function testEmail(Request $request): never
    {
        RateLimiter::hit('admin-email-test', 10, 120);
        $result = $this->probe->email();
        $this->recordProbe($request, 'email', $result['status']);
        Response::json(['test' => $result, 'integration' => $this->emailStatusWith($result)]);
    }

    // ======================================================================
    // helpers
    // ======================================================================

    private function recordProbe(Request $request, string $integration, string $status): void
    {
        $this->audit->record(
            (int) ($request->attributes['user_id'] ?? 0),
            (string) ($request->attributes['user_role'] ?? ''),
            'integration.test_ran',
            'integration',
            null,
            'success',
            'Admin ran an integration connectivity test.',
            $request->clientIp() ?? null,
            $request->headers['user-agent'] ?? null,
            $request->contextId(),
            ['integration' => $integration, 'result' => $status],
        );
    }

    /** @return array<string,mixed> MetaAPI status (configured + source + reachability). */
    private function metaApiStatus(): array
    {
        $s = IntegrationConfigResolver::metaApiSafeStatus();
        $s['baseUrl'] = IntegrationConfigResolver::metaApiBaseUrl(); // non-secret operational value
        return $s;
    }

    /** @return array<string,mixed> Email status (configured + driver + reachability). */
    private function emailStatus(): array
    {
        return IntegrationConfigResolver::mailSafeStatus();
    }

    /** @param array<string,mixed> $probe */
    private function metaApiStatusWith(array $probe): array
    {
        $s = $this->metaApiStatus();
        $s['reachability'] = $probe['status'];
        $s['lastCheckedAt'] = $probe['checkedAt'];
        $s['latencyMs'] = $probe['latencyMs'];
        return $s;
    }

    /** @param array<string,mixed> $probe */
    private function emailStatusWith(array $probe): array
    {
        $s = $this->emailStatus();
        $s['reachability'] = $probe['status'];
        $s['lastCheckedAt'] = $probe['checkedAt'];
        $s['latencyMs'] = $probe['latencyMs'];
        return $s;
    }

    private function assertValidDriver(string $driver): void
    {
        if (!in_array($driver, ['log', 'mail', 'smtp', 'resend'], true)) {
            throw new ValidationException('Unsupported mail driver.', ['driver' => ['code' => 'INVALID_MAIL_DRIVER']]);
        }
    }

    private function assertValidEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Invalid from address.', ['from' => ['code' => 'INVALID_FROM_EMAIL']]);
        }
    }

    /** SSRF-conscious endpoint validation (https, no userinfo, no internal host). */
    private function assertValidEndpointUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
            throw new ValidationException('Endpoint must use https.', ['base_url' => ['code' => 'INVALID_ENDPOINT_URL']]);
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new ValidationException('Endpoint must not embed credentials.', ['base_url' => ['code' => 'INVALID_ENDPOINT_URL']]);
        }
        $host = (string) ($parts['host'] ?? '');
        if ($host === '' || $this->isInternalHost($host)) {
            throw new ValidationException('Endpoint must not target an internal address.', ['base_url' => ['code' => 'INVALID_ENDPOINT_URL']]);
        }
        if (strlen($url) > 2048) {
            throw new ValidationException('Endpoint is too long.', ['base_url' => ['code' => 'INVALID_ENDPOINT_URL']]);
        }
    }

    private function isInternalHost(string $host): bool
    {
        $h = strtolower(rtrim($host, '.'));
        if ($h === 'localhost' || $h === '127.0.0.1' || str_ends_with($h, '.localhost') || $h === '::1') {
            return true;
        }
        if (filter_var($h, FILTER_VALIDATE_IP)) {
            return !filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        return false;
    }
}
