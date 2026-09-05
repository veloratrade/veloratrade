# Phase D — Step 0 read-only audit

Evidence gathered 2026-09-03 against HEAD `742636930`. No files modified during audit.

| Area | Existing implementation | Reusable | Missing | Evidence |
|---|---|---|---|---|
| API health | `GET /health` (public, `{status:ok,time}`) + `GET /api/v1/admin/system/health` (gated `P_SYSTEM_HEALTH_VIEW`) | public liveness + authed health route | no classified component statuses; no diagnostics | `api/index.php:21-24`, `:152` |
| Database health | `AdminOverviewService::measureDbLatency()` (`SELECT 1`, ms) | latency measure | not exposed as a component classification with latency/status | `AdminOverviewService.php:224` |
| Redis health | **Not used anywhere** (DB-based fenced queues; no composer/predis, no env, no `\Redis`) | — | must be reported HONESTLY as not-applicable (no fake check) | `AIJobRepository.php:11`, no `new \Redis` in `api/src` |
| Workers/queue | DB fenced queues (`ai_jobs`, `sync_jobs`, `content_translation_jobs`); counts read in overview | real queue-depth data | no alive/heartbeat; report depth + last activity, not fabricate liveness | `AdminOverviewService.php` `workers`, `api/workers/*` |
| MetaAPI | `IntegrationConnectivityProbe::metaApi()` (Phase C bounded probe), `IntegrationConfigResolver` | full probe + config resolver | not surfaced as health component on every refresh (DoS concern) | `IntegrationConnectivityProbe.php`, `IntegrationConfigResolver.php` |
| n8n Relay | `RelayConfigResolver` (Phase A) | config presence/host | no live probe exists; report CONFIGURED/NOT_CONFIGURED only (accurate) | `api/src/Admin/RelayConfigResolver.php` |
| AI | `ai_provider_credentials.status`, `CredentialStatus`, `AIManager`, `AIProviderLogRepository` | real credential status + provider logs | provider operational health must not be equated with config presence | `CREDENTIAL` tables, `CredentialStatus.php` |
| Email | `IntegrationConnectivityProbe::email()` (Resend/SMTP, no real mail), `IntegrationConfigResolver` | full probe + resolver | health component | `IntegrationConnectivityProbe.php` |
| Logging | **No centralized structured app-log table.** Only `admin_audit_logs` (audit) & `ai_provider_logs` (per-provider). Global exception handler → JSON only | `Request::contextId()`, audit sanitize helpers | needs smallest viable structured logging foundation + redaction | `schema.sql` (no `system_logs`), `bootstrap.php:66` |
| Audit | `admin_audit_logs` (append-only), `AdminAuditLogRepository` (record/list/sanitize), `context_id` correlation | full trail + viewer + RBAC | — | `AuditLogController.php`, `AdminAuditLogRepository.php` |
| Correlation ID | `Request::contextId()` (x-request-id header or random), stored in audit `context_id` | reuse as request/context id | not yet threaded into a system log | `Request.php:122` |
| RBAC | `Role::permissionMap`, `AuthMiddleware::requirePermission`; `P_SYSTEM_HEALTH_VIEW` & `P_SYSTEM_LOGS_VIEW` already exist on Admin/Super | full model | — | `Role.php`, routes |
| Rate limit | `RateLimiter::hit()` (DB buckets) | reuse | must be applied to any live-probe/diagnostics action | `RateLimiter.php` |

## Design decision (smallest real implementation)

Health checks that require no live external call are cheap and run on read
(`api`, `database`, `workers`, config-derived integration statuses). **Live
probes** (MetaAPI/Email) stay behind the existing `/test` endpoints plus a new
bounded, rate-limited `/diagnostics/run` action that caches its result (so a
refresh button cannot storm external providers). No fabricated historical
data: `integration_health.last_checked_at` is null until a real probe runs.

System logs: new `system_logs` (append-only, redacted) + `integration_health`
tables (migration + rollback), a logger facade, a viewer endpoint, and wiring to
write ERROR entries from the global exception handler (sanitized) so an admin
can trace `error → request_id → log → backend event`. Redis is **not** reported
as healthy — it is reported as not-applicable because the architecture has no
Redis.
