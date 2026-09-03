<?php

declare(strict_types=1);

/**
 * VELORA API — front controller.
 * All /api/v1/* requests are rewritten here by .htaccess.
 *
 * Run locally:  php -S 0.0.0.0:8080 -t public public/index.php
 *
 * ⚠️ اصلاح امنیتی/عملکردی (2026-08-06):
 *   - مسیرهای resend-verification اضافه شدند (در نسخه قبلی این فایل غایب بودند و
 *     دکمه «ارسال مجدد ایمیل تأیید» در سایت زنده با خطای 404 مواجه می‌شد).
 */

require __DIR__ . '/src/bootstrap.php';

use Velora\Accounts\AccountController;
use Velora\Auth\AuthController;
use Velora\Auth\AuthMiddleware;
use Velora\Core\Exceptions\ApiException;
use Velora\Core\Exceptions\NotFoundException;
use Velora\Core\Exceptions\ServiceUnavailableException;
use Velora\Core\Locale\ContentTranslationController;
use Velora\Core\Request;
use Velora\Core\RateLimiter;
use Velora\Core\Response;
use Velora\Core\Router;
use Velora\Dashboard\DashboardController;
use Velora\Trades\ScreenshotExtractController;
use Velora\Trades\TradeController;
use Velora\Trades\TradeExitController;
use Velora\Webhooks\MetaApiWebhookController;
use Velora\AI\Controllers\AIController;

try {
    $request = Request::fromGlobals();
} catch (ApiException $e) {
    Response::error($e->getMessage(), $e->httpStatus(), $e->errorCode(), $e->details(), $e->messageKey(), $e->params());
}

// Basic health endpoint (no auth) — useful for uptime checks.
if ($request->path === '/health' && $request->method === 'GET') {
    Response::json(['status' => 'ok', 'time' => gmdate('c')]);
}

$router = new Router();

$auth = [AuthMiddleware::authenticate()];
$admin = [...$auth, AuthMiddleware::adminOnly()];

// ---- Public auth routes ---------------------------------------------
$router->post('/api/v1/auth/register', [AuthController::class, 'register']);
$router->post('/api/v1/auth/verify-email', [AuthController::class, 'verifyEmail']);
$router->post('/api/v1/auth/resend-verification', [AuthController::class, 'resendVerification']);
$router->post('/api/v1/auth/resend-verification-email', [AuthController::class, 'resendVerification']);
$router->post('/api/v1/auth/login', [AuthController::class, 'login']);
$router->post('/api/v1/auth/refresh', [AuthController::class, 'refresh']);
$router->post('/api/v1/auth/logout', [AuthController::class, 'logout']);
$router->post('/api/v1/auth/forgot-password', [AuthController::class, 'forgotPassword']);
$router->post('/api/v1/auth/reset-password', [AuthController::class, 'resetPassword']);

// Cache-only dynamic-content lookup. It never calls or queues a translation provider.
$router->post('/api/v1/content-translations/lookup', [ContentTranslationController::class, 'lookup']);

// ---- Protected routes -----------------------------------------------
$router->get('/api/v1/auth/me', [AuthController::class, 'me'], $auth);
$router->post('/api/v1/auth/change-password', [AuthController::class, 'changePassword'], $auth);
$router->get('/api/v1/auth/email-preferences', [AuthController::class, 'getEmailPreferences'], $auth);
$router->put('/api/v1/auth/email-preferences', [AuthController::class, 'updateEmailPreferences'], $auth);

// PR-04: persist the signed-in user's language preference (users.locale).
$router->add('PATCH', '/api/v1/auth/me/preferences', [AuthController::class, 'updatePreferences'], $auth);

$router->get('/api/v1/trades', [TradeController::class, 'index'], $auth);
$router->post('/api/v1/trades', [TradeController::class, 'store'], $auth);
$router->get('/api/v1/trades/symbols', [TradeController::class, 'symbols'], $auth);
$router->post('/api/v1/trades/extract-screenshot', [ScreenshotExtractController::class, 'extract'], $auth);
$router->get('/api/v1/trades/{id}/exits', [TradeExitController::class, 'index'], $auth);
$router->post('/api/v1/trades/{id}/exits', [TradeExitController::class, 'store'], $auth);
$router->delete('/api/v1/trades/exits/{exitId}', [TradeExitController::class, 'destroy'], $auth);
$router->get('/api/v1/trades/{id}', [TradeController::class, 'show'], $auth);
$router->put('/api/v1/trades/{id}', [TradeController::class, 'update'], $auth);
$router->delete('/api/v1/trades/{id}', [TradeController::class, 'destroy'], $auth);

$router->get('/api/v1/accounts', [AccountController::class, 'index'], $auth);
$router->post('/api/v1/accounts', [AccountController::class, 'store'], $auth);
$router->post('/api/v1/accounts/detect-server', [AccountController::class, 'detectServer'], $auth);
$router->post('/api/v1/accounts/connect-metaapi', [AccountController::class, 'connectMetaApi'], $auth);
$router->post('/api/v1/accounts/{id}/sync', [AccountController::class, 'sync'], $auth);
$router->get('/api/v1/accounts/{id}/sync-status', [AccountController::class, 'syncStatus'], $auth);
$router->add('PATCH', '/api/v1/accounts/{id}/timezone', [AccountController::class, 'updateTimezone'], $auth);
$router->delete('/api/v1/accounts/{id}', [AccountController::class, 'destroy'], $auth);

// v0.2 — MetaApi Webhooks (HMAC, no JWT — public but verified)
$router->post('/api/v1/webhooks/metaapi', [MetaApiWebhookController::class, 'handle']);
$router->get('/api/v1/webhooks/metaapi/test', [MetaApiWebhookController::class, 'test']);

$router->get('/api/v1/dashboard/summary', [DashboardController::class, 'summary'], $auth);
$router->get('/api/v1/dashboard/equity-curve', [DashboardController::class, 'equityCurve'], $auth);
$router->get('/api/v1/dashboard/strategies', [DashboardController::class, 'strategies'], $auth);

// ---- AI P1 endpoints (bounded context, no Core changes) ----
$router->post('/api/v1/ai/analyze-trades', [AIController::class, 'analyzeTrades'], $auth);
$router->post('/api/v1/ai/weekly-report', [AIController::class, 'weeklyReport'], $auth);
$router->post('/api/v1/ai/feedback', [AIController::class, 'feedback'], $auth);

// ---- Admin (RBAC) ----------------------------------------------------
$router->get('/api/v1/admin/users', [\Velora\Admin\AdminController::class, 'users'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_USERS_VIEW)]);

// ---- Admin AI configuration (RBAC; real persisted state only) ---------
$router->get('/api/v1/admin/ai/overview', [\Velora\Admin\AIConfigController::class, 'overview'], $admin);
$router->post('/api/v1/admin/ai/feature-providers', [\Velora\Admin\AIConfigController::class, 'create'], $admin);
$router->post('/api/v1/admin/ai/feature-providers/reorder', [\Velora\Admin\AIConfigController::class, 'reorder'], $admin);
$router->add('PATCH', '/api/v1/admin/ai/feature-providers/{id}', [\Velora\Admin\AIConfigController::class, 'update'], $admin);
$router->delete('/api/v1/admin/ai/feature-providers/{id}', [\Velora\Admin\AIConfigController::class, 'delete'], $admin);
$router->post('/api/v1/admin/ai/credentials/{provider}', [\Velora\Admin\AIConfigController::class, 'replaceCredential'], $admin);
$router->delete('/api/v1/admin/ai/credentials/{provider}', [\Velora\Admin\AIConfigController::class, 'deleteCredential'], $admin);

// ---- Phase B: Admin-managed GLOBAL AI route (view=admin; write=super_admin only) ----
$router->get('/api/v1/admin/ai/route', [\Velora\Admin\AiGlobalRouteController::class, 'show'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_AI_MANAGE)]);
$router->put('/api/v1/admin/ai/route', [\Velora\Admin\AiGlobalRouteController::class, 'update'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_AI_ROUTE_MANAGE)]);
$router->delete('/api/v1/admin/ai/route', [\Velora\Admin\AiGlobalRouteController::class, 'clear'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_AI_ROUTE_MANAGE)]);

// ---- Admin: Phase 1 + Phase 2 (effective config + provider credential verification) ----
$router->get('/api/v1/admin/config/effective', [\Velora\Admin\EffectiveConfigController::class, 'show'], $admin);
$router->post('/api/v1/admin/providers/{provider}/verify', [\Velora\Admin\AIConfigController::class, 'verifyCredential'], $admin);
$router->post('/api/v1/admin/providers/{provider}/test-connection', [\Velora\Admin\AIConfigController::class, 'testConnection'], $admin);

// ---- Phase A: Admin-managed n8n Gemini Relay config ----
// Read = view permission (admin + super_admin); write/clear = integrations.manage
// (super_admin only, per Role::permissionMap). Token values are never returned.
$router->get('/api/v1/admin/integrations/relay/config', [\Velora\Admin\RelayConfigController::class, 'show'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_INTEGRATIONS_VIEW)]);
$router->put('/api/v1/admin/integrations/relay/config', [\Velora\Admin\RelayConfigController::class, 'update'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_INTEGRATIONS_MANAGE)]);
$router->delete('/api/v1/admin/integrations/relay/config', [\Velora\Admin\RelayConfigController::class, 'clear'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_INTEGRATIONS_MANAGE)]);

// ---- Phase C: Admin-managed external integrations (MetaAPI, Email) ----
// Read/status = view (admin + super_admin); write/clear/test = manage (super_admin
// only). Secrets never returned; connectivity only from a real probe.
$router->get('/api/v1/admin/integrations', [\Velora\Admin\IntegrationsController::class, 'inventory'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_INTEGRATIONS_VIEW)]);
$router->get('/api/v1/admin/integrations/metaapi', [\Velora\Admin\IntegrationsController::class, 'metaApi'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_INTEGRATIONS_VIEW)]);
$router->put('/api/v1/admin/integrations/metaapi', [\Velora\Admin\IntegrationsController::class, 'updateMetaApi'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_INTEGRATIONS_MANAGE)]);
$router->delete('/api/v1/admin/integrations/metaapi', [\Velora\Admin\IntegrationsController::class, 'clearMetaApi'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_INTEGRATIONS_MANAGE)]);
$router->post('/api/v1/admin/integrations/metaapi/test', [\Velora\Admin\IntegrationsController::class, 'testMetaApi'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_INTEGRATIONS_MANAGE)]);
$router->get('/api/v1/admin/integrations/email', [\Velora\Admin\IntegrationsController::class, 'email'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_INTEGRATIONS_VIEW)]);
$router->put('/api/v1/admin/integrations/email', [\Velora\Admin\IntegrationsController::class, 'updateEmail'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_INTEGRATIONS_MANAGE)]);
$router->delete('/api/v1/admin/integrations/email', [\Velora\Admin\IntegrationsController::class, 'clearEmail'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_INTEGRATIONS_MANAGE)]);
$router->post('/api/v1/admin/integrations/email/test', [\Velora\Admin\IntegrationsController::class, 'testEmail'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_INTEGRATIONS_MANAGE)]);

// ---- Phase G: Billing + Subscription (read-only observability) ----
// Read-only; subscription mutation is NOT duplicated here — it lives on the
// audited POST /api/v1/admin/users/{id}/subscription (P_USERS_MANAGE_SUBSCRIPTION).
$router->get('/api/v1/admin/billing', [\Velora\Admin\BillingController::class, 'overview'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_BILLING_VIEW)]);
$router->get('/api/v1/admin/billing/users/{id}', [\Velora\Admin\BillingController::class, 'user'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_USERS_VIEW)]);

// ---- Phase H: Analytics + Revenue Intelligence (read-only, admin-only) ----
// Every endpoint requires P_ANALYTICS_VIEW (admin + super_admin). Financial
// analytics are deliberately ALWAYS unavailable (available:false,
// reason:NO_BILLING_SOURCE) — the repository has no authoritative billing source.
$router->get('/api/v1/admin/analytics/overview', [\Velora\Admin\AnalyticsController::class, 'overview'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_ANALYTICS_VIEW)]);
$router->get('/api/v1/admin/analytics/users', [\Velora\Admin\AnalyticsController::class, 'users'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_ANALYTICS_VIEW)]);
$router->get('/api/v1/admin/analytics/trading', [\Velora\Admin\AnalyticsController::class, 'trading'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_ANALYTICS_VIEW)]);
$router->get('/api/v1/admin/analytics/ai', [\Velora\Admin\AnalyticsController::class, 'ai'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_ANALYTICS_VIEW)]);
$router->get('/api/v1/admin/analytics/operations', [\Velora\Admin\AnalyticsController::class, 'operations'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_ANALYTICS_VIEW)]);
$router->get('/api/v1/admin/analytics/revenue', [\Velora\Admin\AnalyticsController::class, 'revenue'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_ANALYTICS_VIEW)]);

// ---- Phase F: centralized Feature Flag control plane ----
// Read = feature_flags.view (admin + super_admin); write = feature_flags.edit
// (super_admin only, per Role::permissionMap). Flags are server-authoritative
// runtime switches for ALL users => privileged write. No secrets involved.
$router->get('/api/v1/admin/feature-flags', [\Velora\Admin\FeatureFlagController::class, 'index'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_FEATURE_FLAGS_VIEW)]);
$router->add('PATCH', '/api/v1/admin/feature-flags/{feature}', [\Velora\Admin\FeatureFlagController::class, 'update'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_FEATURE_FLAGS_EDIT)]);

// ---- Admin panel (Professional Admin Panel, v1.3): RBAC-gated server-side ----
$router->get('/api/v1/admin/overview', [\Velora\Admin\OverviewController::class, 'overview'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_OVERVIEW_VIEW)]);
$router->get('/api/v1/admin/system/health', [\Velora\Admin\OverviewController::class, 'health'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_SYSTEM_HEALTH_VIEW)]);
// ---- Phase D: System + Integration health (diagnostics) & System logs ----
$router->get('/api/v1/admin/system/diagnostics', [\Velora\Admin\SystemHealthController::class, 'diagnostics'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_SYSTEM_HEALTH_VIEW)]);
$router->post('/api/v1/admin/system/diagnostics/refresh', [\Velora\Admin\SystemHealthController::class, 'refresh'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_SYSTEM_HEALTH_VIEW)]);
$router->get('/api/v1/admin/logs/system', [\Velora\Admin\SystemLogController::class, 'index'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_SYSTEM_LOGS_VIEW)]);
$router->get('/api/v1/admin/logs/audit', [\Velora\Admin\AuditLogController::class, 'index'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_AUDIT_VIEW)]);
$router->get('/api/v1/admin/me', [\Velora\Admin\SecurityController::class, 'me'], $admin);

// Users list is defined above (Controller::users) under $admin; detail + actions below.
$router->get('/api/v1/admin/users/{id}', [\Velora\Admin\UserManagementController::class, 'show'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_USERS_VIEW)]);
$router->post('/api/v1/admin/users/{id}/status', [\Velora\Admin\UserManagementController::class, 'setStatus'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_USERS_SUSPEND)]);
// Role assignment (authorization) is Super-Admin-only — subscription (plan) is a separate, RBAC-neutral concept.
$router->post('/api/v1/admin/users/{id}/role', [\Velora\Admin\UserManagementController::class, 'setRole'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_USERS_CHANGE_ROLE)]);
$router->post('/api/v1/admin/users/{id}/subscription', [\Velora\Admin\UserManagementController::class, 'setSubscription'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_USERS_MANAGE_SUBSCRIPTION)]);
// ---- Phase E: User 360 detail endpoints (read = P_USERS_VIEW; action = its own perm) ----
$router->get('/api/v1/admin/users/{id}/accounts', [\Velora\Admin\UserManagementController::class, 'accounts'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_USERS_VIEW)]);
$router->get('/api/v1/admin/users/{id}/trades', [\Velora\Admin\UserManagementController::class, 'trades'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_USERS_VIEW)]);
$router->get('/api/v1/admin/users/{id}/activity', [\Velora\Admin\UserManagementController::class, 'activity'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_USERS_VIEW)]);
$router->get('/api/v1/admin/users/{id}/audit', [\Velora\Admin\UserManagementController::class, 'audit'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_AUDIT_VIEW)]);
$router->post('/api/v1/admin/users/{id}/revoke-sessions', [\Velora\Admin\UserManagementController::class, 'revokeSessions'], [...$admin, AuthMiddleware::requirePermission(\Velora\Auth\Role::P_USERS_SUSPEND)]);

// Dispatch
try {
    // ---- Rate limiting (v0.1 security) — لاگین/ثبت‌نام/بازیابی رمز ----
    if ($request->method === 'POST') {
        switch ($request->path) {
            case '/api/v1/auth/login':
                RateLimiter::hit('login', 8, 300);
                break;
            case '/api/v1/auth/register':
                RateLimiter::hit('register', 5, 3600);
                break;
            case '/api/v1/auth/verify-email':
                RateLimiter::hit('verify-email', 20, 900);
                break;
            case '/api/v1/auth/resend-verification':
            case '/api/v1/auth/resend-verification-email':
                RateLimiter::hit('resend-verification', 4, 3600);
                break;
            case '/api/v1/auth/forgot-password':
                RateLimiter::hit('forgot', 4, 3600);
                break;
            case '/api/v1/auth/reset-password':
                RateLimiter::hit('reset', 6, 3600);
                break;
            case '/api/v1/auth/refresh':
                RateLimiter::hit('refresh', 30, 300);
                break;
            case '/api/v1/auth/change-password':
                RateLimiter::hit('change-password', 8, 900);
                break;
            case '/api/v1/accounts/connect-metaapi':
                RateLimiter::hit('metaapi-connect', 5, 900);
                break;
            case '/api/v1/accounts/detect-server':
                RateLimiter::hit('metaapi-detect', 20, 900);
                break;
            case '/api/v1/webhooks/metaapi':
                RateLimiter::hit('metaapi-webhook', 120, 60);
                break;
            case '/api/v1/ai/analyze-trades':
                RateLimiter::hit('ai-analyze', 10, 3600);
                break;
            case '/api/v1/ai/weekly-report':
                RateLimiter::hit('ai-report', 5, 3600);
                break;
            case '/api/v1/ai/feedback':
                RateLimiter::hit('ai-feedback', 20, 3600);
                break;
            default:
                if (preg_match('~\A/api/v1/accounts/\d+/sync\z~D', $request->path) === 1) {
                    RateLimiter::hit('metaapi-sync', 20, 300);
                }
                break;
        }
    }

    $router->dispatch($request);
} catch (ServiceUnavailableException $e) {
    // Retryable dependency failure (database unreachable). Log safe evidence —
    // route only, never message/credentials/DSN — then render the standard 503.
    error_log(sprintf('[VELORA_DB_UNAVAILABLE] route=%s status=503', $request->path));
    Response::error($e->getMessage(), 503, $e->errorCode(), null, $e->messageKey(), $e->params());
} catch (ApiException $e) {
    Response::error($e->getMessage(), $e->httpStatus(), $e->errorCode(), $e->details(), $e->messageKey(), $e->params());
} catch (NotFoundException $e) {
    Response::error($e->getMessage(), 404, 'NOT_FOUND');
} catch (\Throwable $e) {
    // Safe operational evidence for unexpected 500s. Never log the exception
    // message, request body, token, email, credential, or absolute path.
    error_log(sprintf(
        '[VELORA_UNHANDLED] route=%s class=%s file=%s line=%d',
        $request->path,
        get_class($e),
        basename($e->getFile()),
        $e->getLine(),
    ));
    $debug = \Velora\Core\Config::get('app_debug', false);
    Response::error(
        $debug ? $e->getMessage() : 'Internal server error.',
        500,
        'INTERNAL_ERROR',
        $debug ? ['file' => $e->getFile(), 'line' => $e->getLine()] : null
    );
}
