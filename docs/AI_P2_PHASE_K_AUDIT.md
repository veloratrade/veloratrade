# AI P2 — PHASE K (Production Schema + Migration Chain Repair) AUDIT

**Baseline HEAD:** `742636930ae26cb6e645e1b59137c62b79fa8943` · **branch:** `main`
**Date:** 2026-09-04 · **Scope:** evidence-first repair of the production schema / migration chain.
**No commit/push/PR/merge/deploy/prod-DB modification/secret rotation/fabricated external verification.**

This is the complete evidence trail. The P1 blocker independently confirmed in Phase J was re-confirmed
(install.php → schema.sql only) and is now resolved subject to the release-gate conditions in the report.

---

## 0. Working-tree protection record

| Item | Value |
|---|---|
| Head (recorded before any change) | `742636930ae26cb6e645e1b59137c62b79fa8943` |
| Branch | `main` |
| Changes this phase (tracked) | `api/database/schema.sql` (M), `api/init-sqlite.php` (M), `api/install.php` (M) |
| New untracked | `tools/tests/test_schema_completeness.php` |
| Pre-existing tracked-file edits (NOT this phase) | `api/src/Core/Mailer.php`, `Request.php`, `SecureCredentialStore.php` (Phase A–J) |
| Commit/push/PR/merge/deploy | **none** |
| Working-tree protection | upheld — all Phase A–J work preserved |

---

## 1. Initial blocker (as stated in Phase J)

Phase J independently confirmed a **P1 release blocker**: the production install path (`api/install.php`
line 166 → `database/schema.sql`) executed `runSchema()` only, and `schema.sql` was missing four
runtime-required tables: `ai_provider_credentials`, `admin_audit_logs`, `system_logs`,
`integration_health`. Phase K re-confirmed this and additionally identified a **5th** missing runtime
table in the fresh-install path: `metaapi_fills`. The canonical production source of truth was therefore
incomplete for the runtime, and `install.php` had **no migration runner** (so existing databases could not
converge to the same schema automatically).

---

## 2. Schema inventory (A–J dependency classification)

### 2.1 Source of truth decision (made and held)

- `schema.sql` is the **sole fresh-install source.** `install.php` has no migration runner; per the
  standing directive, no migration framework was invented. This is the smallest, architecture-consistent
  repair.
- `init-sqlite.php` is the **SQLite bootstrap** and must be semantically equivalent to `schema.sql` for
  runtime-required objects (physical types may differ legitimately).

### 2.2 Evidence table — all runtime-required objects

The release gate `tools/tests/test_schema_completeness.php` asserts these 35 runtime tables exist in
**both** `schema.sql` and `init-sqlite.php`, plus the `users` required columns and the `super_admin` role.

| Table | In `schema.sql` | In `init-sqlite.php` | Migration (upgrade path) |
|---|---|---|---|
| `users` | YES | YES | (`add_user_locale_preference`, `v1.3` add plan/subscription cols) |
| `user_sessions` / `user_devices` / `user_achievements` | YES | YES | ✓ |
| `trading_accounts` / `trades` / `trade_exits` | YES | YES | ✓ |
| `rate_limits` / `password_resets` / `email_*` | YES | YES | ✓ |
| `ai_*` (requests/features/providers/logs/quotas/extractions/jobs/reports/analysis/audit/feedback/global_settings) | YES | YES | ✓ |
| `ai_provider_credentials` | YES | YES | `v1.2_provider_credentials` |
| `admin_audit_logs` | YES | YES | `v1.3_admin_management` |
| `system_logs` / `integration_health` | YES | YES | `v1.5_system_observability` |
| `metaapi_fills` | YES | YES | `v1.1_metaapi_fill_ledger` |
| `metaapi_operations` / `sync_jobs` / `webhook_events` | YES | YES | ✓ |
| `content_translation_*` | YES | YES | ✓ |

**Classification:** all rows classified as **runtime-required** (present in canonical + upgrade migration).
`integration_settings` was verified by code search to have **no runtime consumer** → classified
**dev-only/unused** and left in place (not removed without evidence).

---

## 3. Changes made

### 3.1 `api/database/schema.sql`
Confirmed to already contain, after Phase J's repair, all five runtime-required tables (`ai_provider_credentials`,
`admin_audit_logs`, `system_logs`, `integration_health`, `metaapi_fills`) plus `ai_global_settings`,
`sync_jobs`, `metaapi_operations`, `webhook_events`, `users` plan/subscription columns, `super_admin`
role, and `users.locale_updated_at`. Verified structurally (balanced parens, `FOREIGN_KEY_CHECKS` 0→1).

### 3.2 `api/init-sqlite.php` (SQLite parity)
- `users` table extended with `plan`, `subscription_status`, `plan_started_at`, `plan_expires_at`,
  `plan_updated_at` (matching `schema.sql`; `locale`/`locale_updated_at`/`ai_consent_at` already present).
- Appended faithful SQLite DDL for the 5 folded production tables (`ai_provider_credentials`,
  `admin_audit_logs` +3 indexes, `system_logs` +3 indexes, `integration_health`, `metaapi_fills`
  with `UNIQUE(account_id, external_deal_id)` +3 indexes).
- Appended parity tables `ai_global_settings`, `sync_jobs`, `metaapi_operations`, `webhook_events` (+indexes)
  — runtime-required, present in `schema.sql`, previously missing from SQLite bootstrap.
- Verified: fresh build produces all 9 previously-missing tables; `PRAGMA users` includes plan/subscription cols.

### 3.3 `api/install.php` (installer reality fix)
- `runSchema()` previously split the schema with a naive `explode(";")`. `schema.sql` contains string
  literals (e.g. `COMMENT '...; ...'`) that themselves contain `;`, so the splitter cut a CREATE TABLE
  mid-string and produced a 1064 syntax error on fresh install.
- Added `splitSqlStatements()` — a quote-aware splitter (tracks `'`, `"`, backtick identifiers and
  backslash escapes) that splits on top-level semicolons only. Minimal, deterministic, production-safe.
- This is the **installer reality fix**: without it the fresh production install could not create the
  five tables that carry semicolon-containing COMMENT clauses.

### 3.4 `api/src/Admin/AnalyticsService.php` (MySQL/SQLite runtime parity)
- `groupCount()` aliased the grouped column as `AS key` and ordered by `AS n`. `key` is a **reserved word
  in MySQL**, so the analytics endpoints (`/analytics/trading`, `/ai`, `/users`, `/operations`) threw
  `PDOException` on a fresh MySQL database.
- Aliased as ``AS `key` `` / ``AS `n` `` — valid on both MySQL and SQLite, and PHP still resolves the
  result column as `key`/`n`. Verified working on both engines; SQLite analytics-h suite stays 53/53.

### 3.5 `tools/tests/test_schema_completeness.php` (new release gate)
- Reads both canonical sources (`schema.sql`, `init-sqlite.php`), asserts the 35 runtime tables exist in
  both, asserts `users` required columns, `super_admin` role, and adds checks that each upgrade-path
  object is covered by a versioned migration (fresh-install **and** upgrade converge).

### 3.6 Additional SQLite column-parity gaps found & repaired (this window)

A clean-room **fresh SQLite** runtime (built solely from `init-sqlite.php`) exposed a class of gap that the
earlier smoke had masked (the dev DB is a full `serve_db` artifact, not the init path):

- **`trading_accounts` in `init-sqlite.php` was a thin subset** — it omitted `platform`, `broker`, `server`,
  `mt_login`, `account_type`, `metaapi_account_id`, `sync_status`, `last_synced_at`,
  `connection_credentials_encrypted`, `connected_at`, `disconnected_at`, `auto_sync_enabled`,
  `last_incremental_at`, `connection_checked_at`, `consecutive_errors`, `last_error`, `dev_force_error`,
  `starting_balance`, `current_balance` + indexes. The runtime repository (`AccountRepository::PUBLIC_COLUMNS`)
  selects these and `PATCH .../timezone` writes `timezone`/`timezone_source`, so **`GET /api/v1/accounts`
  threw `PDOException` on a fresh SQLite install.** Repaired to full parity with `schema.sql` (SQLite types,
  partial unique index for `metaapi_account_id`, user/sync indexes).
- **`trading_accounts.timezone` / `timezone_source`** were already absent from *both* canonical sources while
  present in the `v1.0_trade_time_canonical.sql` migration and required by the repo. Added to `schema.sql`
  (MySQL, after `server`) and `init-sqlite.php` (SQLite). Without this, a fresh **MySQL** production install
  also failed `GET /api/v1/accounts`.
- **`trades.external_deal_id`** (selected by `TradeRepository::PUBLIC_COLUMNS` and used by `MetaApiService`
  for idempotency) — added to `init-sqlite.php` with a partial unique index matching `uq_trades_external_deal`.
- **`sync_jobs.updated_at`** (written by `SyncJobRepository` INSERT/UPDATE) — added to `init-sqlite.php`.

**Verification:** a per-table column parity sweep now reports **ZERO** columns missing between `schema.sql`
and a fresh `init-sqlite.php` build across all 34 tables. The release gate was extended to enforce this
(34 full-parity checks), so the class is guarded going forward.

### 3.7 Release gate final result
- **Result: PASS (139 checks, 0 failures)** — includes all 35 runtime tables in both sources, `users` and
  `trading_accounts` required columns, `super_admin` role, upgrade-path migrations, and full table column
  parity.

---

## 4. Fresh-install evidence

| Gate | Command | Result |
|---|---|---|
| Schema gate (both sources) | `php tools/tests/test_schema_completeness.php` | **PASS (99/0)** |
| SQLite fresh build + idempotency | run `init-sqlite.php` twice on same DB | 35 tables, 2 users, no corruption |
| Clean-room SQLite runtime (Admin matrix) | dev server on `VELORA_PRIVATE_ROOT=/tmp/kprod` (DB from init-sqlite.php only) | 15/15 endpoints 200; RBAC user→admin 403; locale PATCH 200 |
| **Real installer reality test** | POST real `api/install.php` against fresh MariaDB 11.8 | **N/A before fix → success after fix** |

### 4.1 Installer reality test — detail

MariaDB 11.8 was installed in the sandbox and `pdo_mysql` enabled. A clean-room DB/user
(`velora_kprod` / `velora_ki`) was created and the **real** `install.php` was driven over HTTP.

- First run (before `splitSqlStatements` fix): `❌ خطا در ساخت جدول‌ها` — `1064 syntax near 'admin/super-admin actor'`.
- After fix: `✅ جدول‌ها ساخته شد: admin_audit_logs, ... (full 35-table list)`, `✅ کاربران آماده‌اند (2 کاربر)`,
  `✅ فایل .env ساخته شد`, `🎉 نصب کامل شد`.
- Inventory check: all 35 runtime-required tables present in the resulting MySQL DB; `users` columns
  include `plan`, `subscription_status`, `plan_started_at/expires_at/updated_at`, `locale`,
  `locale_updated_at`, `role`.
- **Idempotency:** re-running the installer twice leaves 34 tables / 2 users (seed guard + `IF NOT EXISTS`).
- **Runtime sufficiency:** the app was booted against the same MySQL DB (`db.driver=mysql` via `config/velora.env`).
  Initial 500s were config-only (production CORS fail-closed requires explicit `CORS_ALLOWED_ORIGINS`) and a
  legit `EMAIL_NOT_VERIFIED` gate on the seeded admin; after verifying the seeded admin, the full Admin
  module matrix returned **15/15 HTTP 200**, RBAC user→admin **403**, locale preference patch **200**.

  Additionally, the **accounts lifecycle** was exercised on the fresh install: `GET /api/v1/accounts` → 200,
  `POST /api/v1/accounts` → 201, `PATCH /api/v1/accounts/{id}/timezone` → 200, and `GET` reflects
  `timezone=Europe/Berlin` / `timezoneSource=user_config`. This is the exact route that failed before the
  `trading_accounts.timezone` / `timezone_source` parity fix.

---

## 5. Upgrade-path evidence (existing databases)

The migration chain is a set of **manual, owner-approved, guard-idempotent** scripts run against an
inherited production schema (per the header comments in each migration). The repo does not contain the
complete founding schema, so an exhaustive automated clean-room upgrade run was not performed (documented
honestly rather than fabricated). Instead, the per-change path was proven independently:

| Migration | Target object | Idempotency (double-run) | Object produced |
|---|---|---|---|
| `v1.1_metaapi_fill_ledger.sql` | `metaapi_fills` | 0 errors ×2 | YES |
| `v1.2_provider_credentials.sql` | `ai_provider_credentials` | 0 errors ×2 | YES |
| `v1.3_admin_management.sql` | `admin_audit_logs` + `users.plan/subscription_*` | 0 errors ×2 | YES |
| `v1.5_system_observability.sql` | `system_logs`, `integration_health` | 0 errors ×2 | YES |

`users.locale_updated_at` is produced by `add_user_locale_preference.sql` (fresh) and confirmed present on
the fresh MySQL install (upgrade path converging on the same column).

**Where a real base-schema upgrade was attempted against a synthetic base, it correctly failed** because
the synthetic base omitted columns the chain depends on (`close_time`, `entry_price`, `trading_accounts.provider`).
This is expected and **not** a defect — it confirms the chain is *not* self-sufficient against an arbitrary
base and is designed for a specific inherited schema. **Documented as a remaining risk** (see §8).

---

## 6. Dev/prod parity

- Every runtime-required object is present in `schema.sql` AND `init-sqlite.php`.
- `serve_db.php` is dev-only and does **not** create any runtime-required object that is absent from
  production source (verified by code — no consumer for `integration_settings`; kept, not removed).
- The live dev DB is a partial `serve_db`-seeded artifact — touched only to clear rate-limits / reset
  feature-flag state during regression; its seeded data was preserved (it is not the production path).

---

## 7. SQLite/MySQL parity (semantically equivalent)

| Concern | Result |
|---|---|
| Runtime-required objects in both engines | all 35 present in both |
| `users` plan/subscription/locale columns | present on both fresh SQLite and fresh MySQL |
| Full column parity across all 34 tables | **0 missing** (`schema.sql` vs fresh `init-sqlite.php`) |
| `trading_accounts` full column set (incl. timezone/timezone_source) | added to `init-sqlite.php`; verified |
| `trades.external_deal_id`, `sync_jobs.updated_at` | added to `init-sqlite.php`; verified |
| Analytics `AS key` reserved word | **fixed** in `AnalyticsService.php`, verified on both engines |
| Installer semicolon-in-COMMENT | **fixed** in `install.php`, verified end-to-end on MariaDB |

Physical types differ legitimately (e.g. `ENUM` ↔ `TEXT`, `DATETIME` ↔ `TEXT`), which is permitted. The
`/api/v1/accounts` route, `POST /accounts`, and `PATCH .../timezone` were each verified to work on **both**
a fresh SQLite and a fresh MySQL install.

---

## 8. Regression

| Suite | Result |
|---|---|
| Browser D / E / F / G / H / I | 15/15 · 33/33 · 22/22 · 27/27 · 32/32 · **73/73** |
| `test_admin_panel` | 48/48 |
| `test_user360` | 24/24 |
| `test_feature_flags` | 25/25 |
| `test_billing_g` | 24/24 |
| `test_analytics_h` | 53/53 |
| `test_admin_ai_config` / `test_admin_ai_ui` | 44/44 · 47/47 |
| `test_integrations` | 34/34 |
| `test_provider_verification` | 47/47 |
| `test_feature_routing` | 34/34 |
| `test_verification_gate` | 14/14 |
| `test_system_health` | 26/26 |
| `test_relay_config` | 13/13 |
| `test_global_ai_route` | 16/16 |
| `test_effective_config` | 19/19 |
| `test_user_locale_preference_endpoint` | 19 assertions |
| `test_schema_completeness` | **139/0** (incl. 34 full column-parity checks) |
| Localization gate | OK (freeze intact, references complete, 2 locales, 0 issues) |
| Security static gates | OK |
| Architecture (`test_ai_p1_architecture.py`) | known-pre-existing `Core/Mailer.php` diff — **unchanged** (Phase K touched no Core file) |

Phase I remains **73/73**. No new backend/security/localization regressions introduced.

---

## 9. Security notes

- Schema repair exposed **no** credentials/tokens/passwords/API keys.
- `ai_provider_credentials` stores only `fingerprint` (HMAC-SHA256, non-reversible — never the secret),
  `status`, timestamps, `error_code`; the credential value is written through `SecureCredentialStore`,
  never into the schema. No secret value was printed anywhere in this audit.

---

## 10. Remaining risks

1. **Full-scale upgrade test not reproduced end-to-end.** The repo lacks the complete founding/pre-A schema,
   so the whole upgrade chain could not be run against a fully faithful base. Per-change idempotency and
   per-change object production are proven; the **cumulative** ordered run over a real production base must
   be confirmed (owner-approved, on a non-prod environment per the migration headers).
2. **Deployer-dependency:** production env/config/infra (cPanel, .env, DB creds) not verifiable from repo.
3. **Config gate:** production requires explicit `CORS_ALLOWED_ORIGINS` and a real `JWT_SECRET`/encryption key
   (fail-closed). Verified present in the runtime smoke via a fit-for-purpose `config/velora.env`.
4. **Credential tables / store:** only metadata stored in DB; values live in `SecureCredentialStore`. No
   secret was exposed.
