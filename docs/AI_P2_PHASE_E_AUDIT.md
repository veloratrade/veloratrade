# Phase E — Users 360° · Step-0 Read-Only Architecture Audit

**Baseline:** branch `main` · HEAD `742636930` · working tree contains legitimate Phase A–D changes (preserved, not reverted).
**Date:** 2026-09-03 · **Read-only — no implementation in this document.**

## Backend audit

| Area | Existing | Reusable | Missing | Evidence |
|---|---|---|---|---|
| User model/entity | `users` table (id, email, password_hash, full_name, role, status, email_verified_at, locale, locale_source, timezone, plan, subscription_status, plan_* , ai_consent_at) | full row | — | `api/database/schema.sql:18` |
| User repository/service | `UserManagementService` (listUsers, userDetail, setStatus, setRole, setSubscription); `AdminController::users` | service layer for list/detail/actions | — | `api/src/Admin/UserManagementService.php`, `AdminController.php:21` |
| Auth/session | `AuthService`, `SessionRepository` (create/revoke/revokeAllForUser/cleanupExpired) | revoke + session data | no per-user session *list* endpoint | `api/src/Auth/SessionRepository.php` |
| Roles | `Role` enum: guest/user/admin/super_admin + `permissionMap` + `isPrivileged/isPanel/isValidStored` | RBAC model | — | `api/src/Auth/Role.php` |
| Plans/subscription | `users.plan` ('free'/'pro'), `subscription_status` ('none','active','past_due','grace','expired','cancelled'), `plan_*` timestamps | plan/subs fields (RBAC-neutral) | — | `v1.3_admin_management.sql` |
| Account status | `users.status` ENUM('active','suspended') | active/suspended model | — | `schema.sql` |
| Trading accounts | `trading_accounts` (provider, platform, broker, server, mt_login, account_type, metaapi_account_id, sync_status, last_synced_at, **connection_credentials_encrypted**) | per-user summary in `userDetail()` | no per-user *list* endpoint; MUST NOT return credential blob | `schema.sql:155`, `UserManagementService.php:userDetail` |
| Trades | `trades` + **canonical `TradeRepository::search()`** (user_id filter, paginated, `PUBLIC_COLUMNS`) | reusable paginated query | no admin per-user trades endpoint | `api/src/Trades/TradeRepository.php:52` |
| AI usage | `ai_requests` (provider, model, tokens_used, status, cost, created_at) | summary already in `userDetail()` | no per-user AI list (only summary — fine for Phase E) | `schema.sql:437`, `userDetail()` |
| Activity/login | `user_sessions` (ip_address, user_agent, created_at, revoked_at, expires_at) + `user_devices` + `system_logs` (login events) | session-derived events, safe fields | no per-user activity endpoint | `schema.sql:40` |
| Audit | `admin_audit_logs` + `AdminAuditLogRepository::record/list`; sensitive fields gated by `P_AUDIT_SENSITIVE_VIEW` (Super Admin) | full trail + RBAC viewer | `list()` lacks a `target_type`/`target_id` filter; no user-scoped audit endpoint | `AuditLogController.php`, `AdminAuditLogRepository.php:96` |
| Admin user endpoints | `GET /admin/users`, `GET /admin/users/{id}`, `POST /admin/users/{id}/status`, `/role`, `/subscription` | list/detail + actions | accounts/trades/activity/audit/revoke-sessions | `api/index.php:109,161-165` |
| Permissions | `P_USERS_VIEW/SUSPEND/CHANGE_ROLE/MANAGE_SUBSCRIPTION`, `P_AUDIT_VIEW/SENSITIVE_VIEW` | RBAC | — | `Role.php` |
| Middleware | `AuthMiddleware::requirePermission`, `$admin` route group | existing | — | `api/index.php` |
| Migrations/schema | `v1.3` (roles/plan), `v1.5` (observability) | additive migrations pattern | no migration needed for Phase E (all data exists) | `api/database/migrations/` |

## Frontend audit

| Area | Existing |
|---|---|
| Admin Users page | single-page `admin/index.html` (KPIs, growth, role donut, users table) |
| User table | `#tbody`, `#search`, `.chip` filters (all/admin/user/suspended), `#pager` pagination, `renderTable()` — client-side filter/paginate over the full list |
| User detail page | **None** — rows are not clickable; no 360° view |
| Admin routing | sidebar `sb-item` links to `/admin/index.html`; no internal hash routing between modules |
| Reusable UI | `VeloraDialog.confirm()` (Promise<boolean>), `.panel`, `.kpi-grid`, `.table-wrap`, `.filters`, `.chip`, `esc()`, `number()`/`VeloraLocale.date()`, `VeloraData.request()` |
| Permission guards | page-level `role !== 'admin' && role !== 'super_admin'` guard |
| Localization | canonical; admin keys via inline `VeloraAdmin*Keys` maps + feature chunks; `t(key, params)` interpolation |

## Tests audit

`test_admin_panel` (48), `test_system_health` (26), `test_issue1_admin_access` (24), `test_admin_ai_ui` (47), `test_admin_ai_config` (44), plus the standing pre-existing failures (email_asset_validation, security_headers_contract, issue2_ai_consent, screenshot_ui, v09_migration_parity). No dedicated User-360 or per-user accounts/trades/activity/audit security test exists yet.

## Smallest implementation plan (no scope expansion)

1. **Backend (additive, reusing services/repos):**
   - `AdminAuditLogRepository::list()` → add optional `target_type`, `target_id` filters (backward-compatible).
   - Extend `UserManagementController` with read actions `accounts()`, `trades()`, `activity()`, `audit()` (each `P_USERS_VIEW`-gated, server-authority checks, never secrets) and one action `revokeSessions()` (`P_USERS_SUSPEND`-gated, uses `SessionRepository::revokeAllForUser`, audited).
   - Add the 5 routes in `api/index.php`.
2. **Frontend:** add a User-360 detail view inside `admin/index.html` (opened by clicking a user row): identity + account/role/plan/subscription cards, Trading Accounts, Trades (paginated), AI Usage, Activity, Audit History, Administrative Actions (Suspend/Activate via `VeloraDialog`, role + plan/subscription) with loading/empty/error/permission-aware states and authoritative refresh. Reuse existing styles/components. Responsive.
3. **Localization:** add new `admin.user360.*` / `admin.userAction.*` keys to en+fa catalogs + admin chunk; rebuild.
4. **Tests:** extend `test_admin_panel`/add `test_user360.php` for backend + security (IDOR, RBAC, secret redaction); extend/extend the Playwright harness opening the User-360 view (incl. mobile viewport).
5. Rebuild, run all gates + regression; browser E2E.

**Deliberately NOT in scope (per §32):** billing, payments, checkout, invoices, revenue/MRR/churn, full analytics, feature flags, system settings. No new DB fields/tables (all user data already exists in schema).
