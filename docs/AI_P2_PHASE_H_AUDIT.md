# Phase H — Analytics + Revenue Intelligence · Read-Only Step-0 Audit

**Method:** read-only inspection of the live SQLite schema (`/home/user/pvt/data/velora.sqlite`), the source tree, the migration files, and the runtime-observed rows. No file was modified, no data written, no migration applied.

**Canonical timestamp convention:** all DB timestamps are stored as UTC `YYYY-MM-DD HH:MM:SS` (`CURRENT_TIMESTAMP` / `datetime('now')`, default DB timezone `UTC` — see `api/init-sqlite.php:19` `timezone TEXT DEFAULT 'UTC'` and `api/database/schema.sql` `DATETIME … (UTC)` comments). Date boundaries in Phase H are therefore computed **in UTC** over the stored UTC timestamps. No browser/server/Tehran timezone mixing.

---

## Domain inventory (live DB, 19 tables)

### Users — `users` (17 cols)
`id, email, password_hash, full_name, role, status, email_verified_at, locale, locale_source, timezone, plan, subscription_status, plan_started_at, plan_expires_at, plan_updated_at, created_at, updated_at`

- Registration time: `created_at` (authoritative). Roles: `user`/`admin`/`super_admin` (`role`). Account status: `active`/`suspended` (`status`). Locale: `locale`.
- **PII present (email, full_name, password_hash)** — analytics must remain **aggregated**; no email/full_name/password material is ever returned.
- Sample: 3 users (super_admin/user/admin), all `active`, all `plan=free`, `subscription_status=none`, registered `2026-09-03 18:27:08`.

### Trading — `trades` (32 cols) + `trading_accounts` (22 cols)
`trades.id, user_id, account_id, symbol, direction, entry_price, exit_price, volume, commission, swap, profit_loss, r_multiple, open_time, close_time, occurred_open_at_utc, created_at …`
`trading_accounts.id, user_id, provider, platform, sync_status, last_synced_at, connection_checked_at, consecutive_errors, last_error …`

- `profit_loss` is the canonical per-trade P&L. A documented, canonical aggregator already exists: `Velora\Dashboard\MetricsService` (bcmath: win/loss/breakeven, totalPnl, win rate). Trading P&L is **trading performance ⇒ NOT platform revenue**.
- Sample: 3 trades (XAUUSD buy +118, EURUSD sell +90, BTCUSD buy −50), all user_id=2.

### AI — `ai_requests` (10 cols) + `ai_jobs` + `ai_feature_providers` + `ai_global_settings`
`ai_requests.id, user_id, feature, provider, model, prompt_hash, tokens_used, status, cost, created_at`

- **`ai_requests` is the authoritative request ledger** (not `system_logs`). `status` includes `success` / `quota_exhausted`. `tokens_used`, `cost` present.
- Canonical accessor: `Velora\AI\Repositories\AIRequestRepository` (note its `usageStats()` is written in MySQL syntax and is therefore not used here; Phase H queries `ai_requests` directly through the service, which is the same authoritative table).
- Sample: 3 requests (trade_analysis gemini success 320t; extraction gemini success 880t; weekly_report gemini quota_exhausted 120t), user_id=2. `ai_jobs`=0, `ai_feature_providers`=0.

### Integrations — `integration_health` (6 cols) + `integration_settings`
`integration_health.integration, status, latency_ms, error_code, message, checked_at`

- Authoritative health ledger (`IntegrationHealthRepository`). Sample: metaapi NOT_CONFIGURED, email HEALTHY.

### Operational — `system_logs` (10 cols) + `admin_audit_logs` (13 cols)
`system_logs.id, severity, source, message, request_id, correlation_id, user_id, error_code, metadata_json, created_at` — canonical via `Velora\Core\SystemLogRepository`.
`admin_audit_logs.id, actor_user_id, actor_role, action, target_type, target_id, result, summary, ip_address, user_agent, context_id, metadata_json, created_at` — canonical via `Velora\Admin\AdminAuditLogRepository`.
Sample: 3 system_logs (INFO auth, WARN metaapi, ERROR api); 40 admin_audit_logs.

### Feature flags — `ai_feature_flags` (canonical via `AIFeatureFlagRepository`), `ai_provider_credentials` (secrets — **never surfaced**).

---

## Billing re-verification (against the current tree)

A precise tree-wide search for genuine billing terms in `api/src`, `api/index.php`, and `api/database/migrations` returned:

| Term | Files |
|---|---|
| Stripe / stripe | 0 |
| Paddle | 0 |
| PayPal | 0 |
| invoice | 2 (both = Phase G honest "no billing provider" doc/comments + `available:false` reason) |
| billing_customer | 0 |
| subscriptions (as a real table/entity) | 0 (only a UserManagement comment about subscription *mutations*) |
| revenue | 2 (both = `AdminOverviewService` + `BillingService` honest `available:false, reason: …no payment/billing integration…`) |
| MRR / churn / LTV | 0 |
| checkout / Checkout | 0 |

**No subscriptions/invoices/payments/plans/checkout/provider table exists.** The only subscription-related state is the internal, manual `users.plan` + `users.subscription_status` layer (v1.3), which is **not** backed by any payment provider or revenue ledger.

**Conclusion: Phase G's classification (C — no billing system) is RECONFIRMED against the current tree.** Billing source: **NOT AVAILABLE.**

---

## Data-source matrix

| Metric | Source | Query / derivation | Runtime-backed | Available |
|---|---|---|---|---|
| Total users | `users` | `COUNT(*)` | yes | yes |
| Users by account status | `users` | `GROUP BY status` | yes | yes |
| Users by role | `users` | `GROUP BY role` | yes | yes |
| Users by locale | `users` | `GROUP BY locale` | yes | yes |
| New users (range) | `users.created_at` | `COUNT(*) WHERE created_at BETWEEN …` | yes | yes |
| Registration trend | `users.created_at` | daily `GROUP BY DATE(created_at)` | yes | yes |
| Total trades | `trades` | `COUNT(*)` | yes | yes |
| Trades by symbol | `trades` | `GROUP BY symbol` | yes | yes |
| Win / loss / breakeven | `trades.profit_loss` | canonical sign split (as `MetricsService`) | yes | yes |
| Aggregate trading P&L | `trades.profit_loss` | `SUM(profit_loss)` (labelled **trading P&L, ≠ revenue**) | yes | yes |
| AI requests (range) | `ai_requests` | `COUNT(*)` | yes | yes |
| AI success / failed | `ai_requests.status` | `GROUP BY status` | yes | yes |
| AI by provider / feature / model | `ai_requests` | `GROUP BY` | yes | yes |
| AI tokens | `ai_requests.tokens_used` | `SUM` | yes | yes |
| System errors | `system_logs.severity` | `GROUP BY severity` (also by source) | yes | yes |
| Integration failures | `integration_health` | `GROUP BY status` | yes | yes |
| Admin audit events | `admin_audit_logs` | `COUNT(*)` (range) | yes | yes |
| Revenue | (none) | — | **no source** | **no** |
| MRR | (none) | — | **no source** | **no** |
| ARR | (none) | — | **no source** | **no** |
| Churn | (none) | — | **no source** | **no** |
| LTV | (none) | — | **no source** | **no** |
| Payment volume / refunds | (none) | — | **no source** | **no** |

---

## Conclusions

1. **Billing source: NOT AVAILABLE.** All financial metrics are reported as `available:false, reason:NO_BILLING_SOURCE` — never zero, never derived, never fabricated.
2. No PII / secrets (email, full_name, password_hash, provider credentials, tokens) will be returned by analytics endpoints. All output is aggregated.
3. No DB schema change is needed for product/usage metrics (`users`, `trades`, `ai_requests`, `system_logs`, `integration_health` already hold the authoritative data). No financial tables will be created.
4. Timezone: date boundaries computed in UTC over UTC-stored timestamps.
5. Canonical layers reused / not bypassed for semantics (trading P&L sign-split matches `MetricsService`; `ai_requests` not raw logs; `system_logs`/`integration_health`/`admin_audit_logs` from existing observable architecture). Aggregation SQL is bounded and parameterized in a dedicated `AnalyticsService`.
