# Phase F — System Settings + Feature Flags: Step-0 Read-Only Audit

**Date:** 2026-09-03 · **Read-only — no modifications made for this audit.**
Working tree only; Phase A–E changes preserved. `HEAD 742636930ae26cb6e645e1b59137c62b79fa8943`, `main`.

## 1. Area | Existing | Reusable | Missing | Evidence

| Area | Existing | Reusable | Missing | Evidence |
|---|---|---|---|---|
| Runtime config | `Config` (env-first, then `velora.env`, then default) + `api/config/config.php` bootstrap; precedence documented per-setting | `Config::env()`, `Config::privatePath()` | A **generic** DB-backed settings store; most runtime values are env/config.php (bootstrap) | `api/src/Core/Config.php`, `api/config/config.php` |
| Admin settings | AI config (`ai_feature_providers`), AI global route (`ai_global_settings`), integrations (MetaAPI/Email/relay) all admin-managed; read-only effective config | `AIConfigController`, `AiGlobalRouteController`, `IntegrationsController`, `RelayConfigController`, `EffectiveConfigController` | A **unified** generic settings page (the actual runtime settings are already surfaced by the AI/Integration modules) | `api/index.php` (lines 111–158), `api/src/Admin/*` |
| Secure config | `SecureCredentialStore` (presence-only, `status()`), credential metadata, credential verification gate | `SecureCredentialStore::status()`, `AICredentialMetadataRepository::safeMetadata()` | Nothing — secret-bearing config already uses the secure store; values never returned | `api/src/Core/SecureCredentialStore.php`, `EffectiveConfigService::credentialState()` |
| AI config | `ai_global_settings` (route tri-state), `ai_feature_providers` (chains), provider quota | `AIGlobalSettingRepository`, `AIFeatureProviderRepository`, `AiRouteResolver` | Nothing critical (already fully wired in Phase B) | `api/database/schema.sql:572`, `api/src/AI/Repositories/*` |
| Integration config | n8n relay, MetaAPI, Email — seeded/persisted + verified | `IntegrationConfigResolver`, `IntegrationsController` | Nothing critical | `api/src/Admin/IntegrationsController.php`, `api/src/Admin/RelayConfigController.php` |
| Feature flags | `ai_feature_flags` (enabled + rollout_percentage), deterministic `crc32` rollout, central `AIFeatureGuard`, runtime consumers | `AIFeatureFlagRepository` (`isEnabled`/`all`/`setFlag`), `AIFeatureGuard` | **Admin management surface** — no admin API endpoint, no UI, no RBAC permission, no audit for flag toggles | `api/src/AI/Repositories/AIFeatureFlagRepository.php`, `api/src/AI/Services/AIFeatureGuard.php`, `tools/dev/serve_db.php` (no flag seeding) |
| Config precedence | Documented precedence in `EffectiveConfigService::precedence()` (per-feature > admin global > env > legacy > direct) | `AiRouteResolver`, `FeatureRouter` | A centralized **feature-flag** resolver (flags are already consumed centrally via `AIFeatureGuard`) | `api/src/AI/Services/EffectiveConfigService.php`, `api/src/AI/Services/AiRouteResolver.php`, `api/src/AI/Services/FeatureRouter.php` |
| Audit | `admin_audit_logs` + `AdminAuditLogRepository::record(...)` (actor, action, target, summary, ip, ua, context, metadata) | `AdminAuditLogRepository`, used by `AiGlobalRouteController`/`UserManagementController` | Audit wiring for feature-flag mutations | `api/src/Admin/AdminAuditLogRepository.php`, `api/src/Admin/AiGlobalRouteController.php` |
| RBAC | `Role::permissionMap()`; **`P_SETTINGS_VIEW` (admin+super) & `P_SETTINGS_MANAGE` (super-only) already declared & wired** ("reserved Module K"); `P_AI_ROUTE_MANAGE` super-only pattern | `Role::can`, `AuthMiddleware::requirePermission`, `$admin` middleware | Feature-flag permissions (`P_FEATURE_FLAGS_VIEW`/`P_FEATURE_FLAGS_EDIT`) | `api/src/Auth/Role.php` (lines 50–107) |

## 2. Findings that shape the implementation

1. **There is no generic `system_settings`/`feature_flags` second config system to fight.** The repository routes *actual runtime settings* through **domain-scoped** tables managed by dedicated admin controllers (`ai_global_settings`, `ai_feature_providers`, integration secret stores). A generic key-value `system_settings` table would **duplicate** these (violates rule 4) and has **no runtime consumer** (violates rule 5 inventing settings).
2. **Infrastructure/bootstrap secrets already live outside the panel.** `config.php` handles DB credentials, `APP_ENCRYPTION_KEY`, `JWT_SECRET`, CORS — none are exposed by any admin API; `SecureCredentialStore` returns presence booleans only. No UI fields, no APIs, and none migrate into settings tables. ✅ (No action needed; must be preserved.)
3. **Settings the repository actually supports at runtime are already admin-managed** (AI route, AI provider chains, MetaAPI/Email/relay). Adding a generic settings form for `site_name`/`maintenance`/`default_locale` would be inventing settings with no consumer → **out of scope on evidence grounds**.
4. **The genuine, evidence-backed gap is feature-flag management.** `ai_feature_flags` already has enable/disable + deterministic percentage rollout and is **runtime-consumed** via `AIFeatureGuard`/`AIFeatureFlagRepository::isEnabled()` (in `TradeAnalyzerService`, `WeeklyReportService`, `ScreenshotExtractController`). But there is **no admin API to toggle flags, no permission, no audit, no UI**. This is Phase F's concrete deliverable, fully built on existing architecture (rule 1, 3, 4).
5. **Flag naming convention:** runtime flags are the `ai_`-prefixed canonical set (`ai_screenshot_extraction`, `ai_trade_analysis`, `ai_weekly_report`, `ai_assistant`), matching `v0.5` seed and the runtime guards. `ProviderCatalog::FEATURES` (un-prefixed) is a separate provider-feature vocabulary; the admin flag module will use the runtime flag space.
6. **Environment:** application environment identity is already established by `Config::env('APP_ENV','production')`; per rule 16 we **reuse** it as read-only metadata and do **not** invent a second detector or an `environment` column.
7. **Targeting:** deterministic rollout already exists (`crc32(feature:user) % 100 < rollout`), stable per-user, server-side, auditable. Reuse it; do not add arbitrary user targeting (rule 15).
8. **Cache:** no Redis; settings/flags are read from the DB on each call (no persistent cache layer to invalidate). Documented accordingly (rule 22).

## 3. Minimal implementation plan

**IMPLEMENTED (evidence-backed): Feature Flag control plane** — a single, centralized, server-authoritative admin surface, reusing `ai_feature_flags` + `AIFeatureFlagRepository` + `AIFeatureGuard`.

1. **RBAC (backend)** — add `Role::P_FEATURE_FLAGS_VIEW` (admin + super_admin) and `Role::P_FEATURE_FLAGS_EDIT` (**super_admin only**), wired into `permissionMap()` (naming follows the existing `feature_flags.*` dotted convention used by `P_AUDIT_VIEW`/`P_SYSTEM_*`).
2. **Service** — extend `AIFeatureFlagRepository` with an actor-aware, success-reporting `setFlag()` and a `get()`; keep `isEnabled()`/rollout unchanged (no semantic change → rule 17).
3. **Controller** — new `api/src/Admin/FeatureFlagController.php`: `GET /api/v1/admin/feature-flags` (list canonical + persisted + effective + env), `PATCH /api/v1/admin/feature-flags/{feature}` (enable/disable + rollout%, rate-limited, validate allowlist + 0–100, **super_admin** audit `feature_flag.updated/enabled/disabled`, old→new safe metadata).
4. **Routes** — `api/index.php`.
5. **Runtime chain** — `Admin UI → API → requirePermission(P_FEATURE_FLAGS_EDIT) → FeatureFlagController → AIFeatureFlagRepository (ai_feature_flags) → audit → AIFeatureGuard/AIFeatureFlagRepository::isEnabled → runtime consumer` (server source of truth).
6. **Localization** — `admin.flags.*` en/fa keys + `VeloraAdminFlagKeys` inline map; rebuild.
7. **Frontend** — new "Feature Flags" admin module card (list + status + rollout targeting + Enable/Disable/Edit-targeting + confirmation dialog + server refresh + loading/error/unauth states; FA/EN; responsive).
8. **Tests** — backend harness (`test_feature_flags.php`) + browser E2E (`browser_verify_phase_f.mjs`).

**NOT IMPLEMENTED (evidence, rules 4–5, 11, 12, 25):**
- **Generic `system_settings` table / generic settings store** — no second config system; no generic runtime consumer; the repository's real runtime settings are already managed by dedicated controllers. Creating one would be a duplicate config architecture.
- **Site name / maintenance mode / default locale-as-global-setting** — no centralized runtime consumer (maintenance mode has no centralized request gate → rule 12 gap); locale/timezone are per-user, site name is not runtime-consumed → inventing settings.
- **`environment` column / second environment detection** — App env is already established by deployment (`APP_ENV`); reused as read-only metadata (rule 16).
- **Arbitrary user targeting** — only deterministic percentage rollout (reused) (rule 15).
- **URL/SSRF fields** — no new arbitrary URL inputs; integration URLs stay governed by `IntegrationConfigResolver` (rule 21).
