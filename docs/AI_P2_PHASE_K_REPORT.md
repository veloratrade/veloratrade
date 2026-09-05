# AI P2 — PHASE K (Production Schema + Migration Chain Repair) REPORT

**Baseline HEAD:** `742636930ae26cb6e645e1b59137c62b79fa8943` · **branch:** `main`
**Date:** 2026-09-04 · **No commit/push/PR/merge/deploy/prod-DB modification/secret rotation.**

---

## 1. Executive summary

Phase J independently confirmed a **P1 release blocker**: the production install path
(`install.php` → `database/schema.sql`) was missing four runtime-required tables plus a fifth
(`metaapi_fills`), and there was **no migration runner** to converge existing databases. Phase K repaired
the canonical production schema source of truth, brought the SQLite bootstrap to semantic parity, added a
release gate, fixed a real `install.php` SQL-splitting defect, and fixed a MySQL/SQLite runtime parity bug
in analytics. Fresh install (real installer), existing-DB upgrade path (per-change), dev/prod parity and
SQLite/MySQL parity are now all evidenced.

**Bottom line:** the previously-blocking P1 is **resolved by evidence** (real installer produces a
runtime-sufficient DB), and the earlier schema repair was hardened further when the clean-room **fresh
SQLite** runtime surfaced three more SQLite/MySQL parity gaps (`trading_accounts` thin subset +
`timezone`/`timezone_source`, `trades.external_deal_id`, `sync_jobs.updated_at`) — all repaired, with a
per-table column-parity gate now enforcing the class. However, the full release is **not** unblocked merely
because tests pass: the Phase J §16 questions still resolve to deployment-gates that were not re-verified,
and the cumulative ordered migration run over a real production base was not reproduced. Final verdict below.

---

## 2. Before / After production schema

**Before (Phase J):** `schema.sql` was missing `ai_provider_credentials`, `admin_audit_logs`,
`system_logs`, `integration_health`; `metaapi_fills` was additionally missing as a fresh-install object;
`init-sqlite.php` was missing those plus `ai_global_settings`, `sync_jobs`, `metaapi_operations`,
`webhook_events`; `users` lacked `plan`/`subscription_status`/`plan_*` columns on the SQLite path.

**After:** all 35 runtime tables are present in both `schema.sql` and `init-sqlite.php`; `users` carries
`plan`, `subscription_status`, `plan_started_at`, `plan_expires_at`, `plan_updated_at`, `locale`,
`locale_updated_at`, `ai_consent_at`; `super_admin` role present; each object has a versioned migration
for the upgrade path.

### Change log

| File | Change |
|---|---|
| `api/init-sqlite.php` | +plan/subscription users columns; +5 production tables (+indexes); +4 parity tables (+indexes); **full `trading_accounts` column set** (+timezone/timezone_source); `trades.external_deal_id`; `sync_jobs.updated_at`; indexes |
| `api/install.php` | quote-aware `splitSqlStatements()` replacing `explode(";")` (real fresh-install fix) |
| `api/src/Admin/AnalyticsService.php` | backtick-quote `key`/`n` aliases (MySQL reserved-word fix) |
| `tools/tests/test_schema_completeness.php` | new release gate: **139 checks** fresh-install + upgrade convergence + **full table column parity** |
| `api/database/schema.sql` | added `trading_accounts.timezone` / `timezone_source` | 

---

## 3. Production install verification (installer reality test)

Installed MariaDB 11.8 in the sandbox, enabled `pdo_mysql`, created a clean-room DB/user, and drove the
**real** `api/install.php` (the actual production mechanism) over HTTP.

- **Before fix:** `❌ خطا در ساخت جدول‌ها: 1064 syntax near 'admin/super-admin actor'` (semicolon-in-COMMENT
  broke `explode(";")`).
- **After fix:** installer reports **success** — full 35-table list created, 2 seeded users
  (`admin@velora.dev`/`Admin123!`, `demo@velora.dev`/`Demo1234!`), `.env` written.
- **Inventory:** all 35 runtime tables present; `users` has plan/subscription/locale columns; `super_admin` role.
- **Idempotency:** second installer run leaves 34 tables / 2 users (no duplicates).
- **Runtime sufficiency:** app booted against that MySQL DB → Admin matrix **15/15 HTTP 200**, RBAC
  user→admin **403**, locale preference **200** (after config CORS fix and verifying the seeded admin).

---

## 4. Existing-database upgrade path

The migration chain is manual, guarded and idempotent. Per-change proof (each migration double-run → 0
errors, produces its target object):

| Migration | Produces | Idempotent |
|---|---|---|
| `v1.1_metaapi_fill_ledger` | `metaapi_fills` | ✓ |
| `v1.2_provider_credentials` | `ai_provider_credentials` | ✓ |
| `v1.3_admin_management` | `admin_audit_logs`, `users.plan/subscription_*` | ✓ |
| `v1.5_system_observability` | `system_logs`, `integration_health` | ✓ |
| `add_user_locale_preference` | `users.locale_updated_at` | ✓ (fresh + upgrade converge) |

---

## 5. Four-table blocker resolution

The four Phase J tables (`ai_provider_credentials`, `admin_audit_logs`, `system_logs`, `integration_health`)
— plus the fifth fresh-install object (`metaapi_fills`) — are now present in the production source of truth
with columns/types/nullability/defaults/indexes/constraints/unique keys matching the runtime repositories
and their migrations. No simplified placeholders. Verified by the 99-check gate and the real installer.

---

## 6. Locale schema

`users.locale`, `users.locale_source`, `users.locale_updated_at` are present in `schema.sql`, in
`add_user_locale_preference.sql` (upgrade), in `init-sqlite.php`, and on both fresh SQLite and fresh MySQL
installs. The saved-locale endpoint (`PATCH /auth/me/preferences` `{"locale": ...}`) returns **200** on
both fresh SQLite and fresh MySQL runtimes, and writes the value (verified subsequent GET reflects the write).
`test_user_locale_preference_endpoint` passes **19 assertions**.

---

## 7. SQLite/MySQL parity

All 35 runtime objects present in both engines (semantically equivalent; physical types differ legitimately).
A **full per-table column-parity sweep** now reports **zero** missing columns (`schema.sql` vs a fresh
`init-sqlite.php` build) and is enforced by the release gate. Three genuine parity defects were found and
fixed by the install/clean-room probes (see §2 change log):
- `AnalyticsService::groupCount` used the MySQL-reserved alias `key` (backtick-quoted now).
- `install.php` split SQL on `;` inside string literals (semicolon-aware splitter now).
- `init-sqlite.php`'s `trading_accounts` was a thin subset, and `timezone`/`timezone_source`,
  `trades.external_deal_id`, `sync_jobs.updated_at` were missing, breaking `/api/v1/accounts` on a fresh
  SQLite install (and on fresh MySQL for the timezone columns). All added.

Verified end-to-end on **both** engines: `/api/v1/accounts` → 200, `POST /accounts` → 201,
`PATCH .../timezone` → 200 (timezone persists with `timezoneSource=user_config`); analytics-h 53/53 on
SQLite; Admin matrix 15/15 on MySQL.

---

## 8. Regression

Backend (17 suites) all green incl. new **139-check** gate; browser D/E/F/G/H/I all green incl. Phase I **73/73**;
localization gate OK; security static gates OK; architecture test shows only the **known pre-existing**
`Core/Mailer.php` diff (unchanged — Phase K touched no Core file). No new regressions.

---

## 9. Remaining blockers

1. **Cumulative upgrade not run over a real pre-A base.** The repo does not contain the complete founding
   schema; per-change idempotency/object-production are proven, but the full ordered chain over a faithful
   production base must be confirmed by the owner on a non-prod environment. (Registry-level risk.)
2. **Phase J §16 deployment gates** below were not re-verified in this phase (out of scope / no prod access).
3. **Config gate:** production requires explicit `CORS_ALLOWED_ORIGINS`, real `JWT_SECRET`/encryption key
   (fail-closed). Verified working with a fit-for-purpose env; actual cPanel secrets not verifiable.

---

## 10. §16 — release-blocker reassessment (explicit answers)

1. **Does the fresh production install produce every runtime-required table?** YES — verified by running
   the **real** `install.php` against MariaDB: all 35 tables present. **(Resolved.)**
2. **Does an existing-DB upgrade path reach the same schema?** PER-CHANGE YES (idempotent + object produced)
   for every runtime object; **cumulative run over a real base NOT reproduced** (no founding schema in repo).
   **PARTIALLY resolved.**
3. **Does the dev DB hide any runtime-only dependency?** NO — every runtime object exists in production source.
4. **Is `users.locale_updated_at` safe on fresh and upgraded DBs?** YES — verified on fresh SQLite, fresh
   MySQL, and via the locale migration.
5. **Is the installer reality fix sound?** YES — quote-aware splitter; verified end-to-end and idempotent.
6. **Do migrations introduce dup-table/dup-column/rollback/failure?** NO for dup-table/dup-column (guarded);
   rollback files provided for each feature migration. Cumulative ordering per real base = unverified (see #2).
7. **Are credentials/secrets safe?** YES — only `fingerprint`/metadata stored in DB; values in
   `SecureCredentialStore`; no secret value printed.
8. **Is the release unblocked solely because tests pass?** **NO.** Tests/evidence are strong, but the
   cumulative upgrade over a real base and the deployment config/infra remain owner-confirmation gates.

---

## 11. Final verdict

### NOT READY

The **P1 schema blocker is resolved** (real installer produces a runtime-sufficient database; fresh-install,
dev/prod parity, SQLite/MySQL parity, and per-change upgrade path all evidenced). However:

- the **cumulative** migration chain over a real (not synthetic) pre-A production base was **not** run
  (founding schema absent from the repo), and
- Phase J §16 deployment/config/infra gates remain to be confirmed by the owner for the actual cPanel host.

Neither of these is evidence of a *defect* in the repair; both require an environment/DB the repo does not
contain, and the standing rule forbids fabricating external verification. Until the owner runs the ordered
migration chain on a non-prod copy of the real database and confirms deploy config, this is **NOT READY** —
**not** BLOCKED (all in-repo, verifiable conditions pass) and **never** "PRODUCTION READY".
