# VELORA — n8n Configuration & Re-Deployment Ground-Truth Audit

**Audit type:** Read-only ground-truth investigation. No files, config, DB, n8n,
workflows, credentials, env, deployment, or CI/CD were modified.
**Repository HEAD:** `742636930ae26cb6e645e1b59137c62b79fa8943` (branch `main`).
**Working tree:** partial sparse-checkout; `tools/n8n_archive/` and
`tools/n8n_migrate/` are git-listed but **not checked out** (they are offline
tooling, not part of the runtime app).
**Date of audit:** 2026-09-03.

> Evidence method: source-level trace of `Config::env()`, `config.php`,
> `N8nGeminiRelayTransport`, `GeminiProvider`, `FeatureRouter`, `AIManager`,
> `ProviderCatalog`, `AIConfigController`, `SecureCredentialStore`,
> `api/index.php` routes, migrations, `.env.example`, and `effective-config`
> service. Where a fact is only visible via code inspection (not a live HTTP
> call) it is marked **VERIFIED (static)**; where it cannot be proven it is
> marked **UNVERIFIED**.

---

## 1. Executive Verdict

**NO / PARTIALLY — functionally PARTIALLY possible, but the stated goal is NOT
currently achievable without manual file/env editing.**

Specifically:

- **AI provider *route* can be set from the Admin Panel** (`direct` / `n8n_relay`)
  via the DB-backed `ai_feature_providers.route` column (PATCH
  `/api/v1/admin/ai/feature-providers/{id}`), and this **does** flow into the
  runtime Gemini call (`AIManager` line ~198 → `GeminiProvider::generate`).
- **The Gemini API key can be set from the Admin Panel** (POST
  `/api/v1/admin/ai/credentials/{provider}` → `SecureCredentialStore` → private
  `velora.env`; verified-config/`test-connection` endpoints exist).
- **BUT the n8n relay URL and relay token ARE NOT admin-editable.** There is **no
  endpoint** that writes `GEMINI_RELAY_URL` / `GEMINI_RELAY_TOKEN`, even though:
  - `SecureCredentialStore::manageableKeys()` *does* allowlist them, and
  - `ProviderCatalog::relayKeys('gemini')` declares them.
  - `AIConfigController::replaceCredential()` writes **only** `credentialKeys[0]`
    (i.e. `GEMINI_API_KEY`), never the relay keys. The `credentials/{provider}`
    route is the only secret-write path.
- **The n8n relay URL/token are infrastructure/env values today.** Without them,
  `N8nGeminiRelayTransport::isConfigured()` is false and any routed relay call
  throws `AITimeoutException`/"not configured". You must edit `velora.env` (or
  process env) to supply them. **This breaks the "no file editing" goal.**
- **The `ai_gemini_relay_route` feature flag is a DB switch but has NO admin
  endpoint.** It can only be toggled by direct DB mutation (there is no
  `feature-flags` admin route; only `feature-providers` routes exist).
- **The n8n workflow itself (and Google-key-not-in-Velora) is external.** Velora
  never holds the upstream Gemini key on the relay route (n8n Cloud does), so a
  fresh deploy cannot be made "fully operational" from Velora alone:
  the **n8n instance + consumer-side workflow + n8n-side Gemini credential must
  exist in n8n Cloud** independent of Velora.

**Bottom line:** After a fresh deploy you can log in as Super Admin and point
Gemini at `direct` (set `GEMINI_API_KEY` in the panel) and get Gemini working —
provided the host's network can reach `generativelanguage.googleapis.com` (it
allegedly cannot from the staging host in this region, which is *why* the relay
was introduced). To use the **n8n relay** you must still manually place
`GEMINI_RELAY_URL` + `GEMINI_RELAY_TOKEN` into the private env file. So the goal
is **PARTIALLY** achievable for `direct`, **NOT** for `n8n_relay`.

---

## 2. Actual Architecture (verified runtime path)

Two distinct n8n-related systems exist. Only the first is wired into the
runtime app.

### 2a. Gemini relay runtime path (WIRED — this is what serves AI today)

```
Velora Frontend (admin|app)
        │  POST /api/v1/...  (bearer JWT, HttpOnly refresh)
        ▼
Velora API  →  AIManager::generate()
        │  FeatureRouter::resolveChain(feature)
        │    ├─ ai_feature_providers (DB rows; route column)   ← admin-editable
        │    └─ else env-default chain (AI_ENABLED_PROVIDERS)
        ▼
GeminiProvider::getRoute()
        │  precedence: chain row.route > GEMINI_ROUTE env
        │               > ai_gemini_relay_route (DB flag) > direct
        │  relay is selected ONLY for extraction-shaped calls
        │  (responseMimeType===application/json OR feature==='extraction');
        │  free-text analysis ALWAYS uses direct transport.
        ▼
  n8n_relay: N8nGeminiRelayTransport            direct: DirectGeminiTransport
        │  POST {GEMINI_RELAY_URL}                       https://generativelanguage.googleapis.com/v1beta/models/
        │  X-Velora-Relay-Token: {GEMINI_RELAY_TOKEN}
        ▼
   n8n Cloud: "VELORA — Gemini Vision Extraction Relay" workflow
        ▼
   Gemini (key held by n8n Cloud, NOT Velora)
        ▼
   n8n responds {success, request_id, provider, model, extraction, meta}
        ▼
   Velora API (maps errors via N8nGeminiRelayTransport::mapRelayError)
        ▼
   Frontend
```

Authentication on the relay hop: `X-Velora-Relay-Token` header only (never in URL,
logs, or error bodies). Timeout: `GEMINI_RELAY_TIMEOUT` (env, default 45s; also
min/max clamped 5–90 in `config.php`). No Velora-side retry in the transport
itself; the `AIManager` chain provides provider fallback (fallback_index) and the
global deadline.

### 2b. n8n SEO/article archive + instance-migration tooling (NOT wired)

A separate, offline tooling surface: `content/n8n-integration`, `content/n8n-archive`,
`content/n8n-migrate`, `docs/N8N_*.md`, `tools/n8n_migrate/`, `tools/n8n_archive/`.
It connects to an n8n instance via `N8N_SOURCE/TARGET_BASE_URL` +
`N8N_SOURCE/TARGET_API_KEY` for **read-only** verification and (disabled) migration
of approved-article Data Tables. These are **not** imported by `api/` and are not
part of the AI request path. Not checked out in this workspace.

---

## 3. Complete Configuration Inventory

Legend: Secret? **Y/N**; Persisted? **yes/no**; Admin Editable? **A/–**;
Survives fresh deploy? **Y/N**.

| Configuration | Current Source | Exact Location | Runtime Consumer | Secret? | Persisted? | Admin Editable? | Survives Fresh Deploy? | Status |
|---|---|---|---|---|---|---|---|---|
| `GEMINI_ROUTE` | env → private `velora.env` | `config.php` `ai.gemini_route` | `GeminiProvider::getRoute()` | N | env/file | **No endpoint** | N (not in repo) | **GAP-B** |
| `GEMINI_RELAY_URL` | env → private `velora.env` | `config.php` `ai.gemini_relay_url` | `N8nGeminiRelayTransport` ctor | N (URL) | env/file | **No endpoint** | N | **GAP-B** |
| `GEMINI_RELAY_TOKEN` | env → private `velora.env` | `config.php` `ai.gemini_relay_token` | `N8nGeminiRelayTransport` header | **Y** | env/file | **No endpoint** | N | **GAP-D** |
| `GEMINI_RELAY_TIMEOUT` | env → private `velora.env` | `config.php` `ai.gemini_relay_timeout` (clamp 5–90) | relay transport timeout | N | env/file | **No endpoint** | N | GAP-B |
| `GEMINI_API_KEY` | env → private `velora.env` | `config.php` `ai.gemini_api_key` | `DirectGeminiTransport` | **Y** | env/file | **YES** (`credentials/gemini`) | N | A/D |
| `GEMINI_MODEL` | env → private `velora.env` | `config.php` `ai.gemini_model` | `GeminiProvider` ctor | N | env/file | **No endpoint** | N | GAP-B |
| `GEMINI_TIMEOUT` | env → private `velora.env` | `config.php` `ai.gemini_timeout` (clamp 2–30) | `GeminiProvider` ctor | N | env/file | **No endpoint** | N | GAP-B |
| `AI_ENABLED_PROVIDERS` | env → private `velora.env` | `config.php` `ai.enabled_providers` | env-default chain | N | env/file | **No endpoint** | N | GAP-B |
| per-feature `route` | **DB** `ai_feature_providers.route` | migration v0.9 | `FeatureRouter` → `AIManager` → `GeminiProvider::generate` | N | **yes (DB)** | **YES** (PATCH feature-providers) | **Y** | **A** |
| per-feature `model` | **DB** `ai_feature_providers.model` | migration v0.9 | `AIManager` callOptions['model'] | N | **yes (DB)** | **YES** | **Y** | **A** |
| per-feature `priority`/`enabled` | **DB** `ai_feature_providers.priority/enabled` | migration v0.9 | `FeatureRouter::buildFromRows` | N | **yes (DB)** | **YES** | **Y** | **A** |
| `ai_gemini_relay_route` flag | **DB** `ai_feature_flags` | migration v0.5 (seeded) | `GeminiProvider::getRoute()` | N | **yes (DB)** | **No endpoint** | **Y** | **GAP-C** |
| provider credential verification metadata | **DB** `ai_provider_credentials` (safe status/fingerprint class only) | migration v1.2 | `CredentialVerificationGate`, admin overview | N (no values) | **yes (DB)** | **YES** (verify endpoints) | **Y** | A |
| provider quota limits | **DB** `ai_provider_quotas` | migration v0.4/v0.9 | `AIManager` reservation | N | **yes (DB)** | **No endpoint** | **Y** | GAP-B |
| `JWT_SECRET` | env → private `velora.env` | `config.php` | auth middleware | **Y** | env/file | No | N | D |
| `APP_ENCRYPTION_KEY` (AES-256-GCM) | env → private `velora.env` | `config.php` | bridge credential encryption | **Y** | env/file | No | N | D |
| `DB_USER/DB_PASS/DB_HOST/DB_NAME` | env → private `velora.env` | `config.php` | PDO | **Y** | env/file | No | N | D |
| `METAAPI_TOKEN` / `METAAPI_WEBHOOK_SECRET` | env → private `velora.env` | `config.php` `metaapi` | MetaApi bridge/webhook | **Y** | env/file | No (no endpoint) | N | D |
| `RESEND_API_KEY` | env → private `velora.env` | mailer | Resend mail | **Y** | env/file | No | N | D |
| `TRANSLATION_SERVICE_TOKEN` | env → private `velora.env` | CLI worker only | content translation worker | **Y** | env/file | No | N | D |
| `METAAPI_BASE_URL` | env → private `velora.env` | `config.php` | MetaApi | N | env/file | No endpoint | N | B |
| `FRONTEND_URL` | env → private `velora.env` | `config.php` | password-reset links | N | env/file | No endpoint | N | B |
| `CORS_ALLOWED_ORIGINS` | env → private `velora.env` | `config.php` | CORS | N | env/file | No | N | B/D |
| n8n instance base URLs / API keys (SEO tooling) | env (CI/local) | `N8N_SOURCE/TARGET_BASE_URL`, `N8N_SOURCE/TARGET_API_KEY` | `tools/n8n_migrate` (NOT runtime) | **Y** (keys) | env | No | N | D (offline tooling) |

---

## 4. n8n Runtime Findings

### Verified
- **Verified (static):** Velora's runtime n8n touchpoint is exclusively the Gemini
  relay transport via `GEMINI_RELAY_URL` + `GEMINI_RELAY_TOKEN` env keys.
- **Verified (static):** The relay is only used for **extraction-shaped** calls;
  all free-text analysis takes the direct `generativelanguage.googleapis.com`
  route even when `GEMINI_ROUTE=n8n_relay`.
- **Verified (static):** The relay auth is `X-Velora-Relay-Token` header; no
  token in URL/log/errors. `N8nGeminiRelayTransport::mapRelayError` maps only
  sanitized codes.
- **Verified (static):** The relay connection can be probe-tested from the Admin
  Panel (`test-connection` → `GeminiCredentialVerifier` returns `RELAY_REACHABLE`,
  deliberately never upstream-credential validity).
- **Verified (static):** No real n8n URL/token/key exists anywhere in the tracked
  repository (only `relay.example.test` and `<instance>.app.n8n.cloud` literals
  in docs/tests).

### Unverified
- The actual n8n Cloud instance, the live "VELORA — Gemini Vision Extraction
  Relay" workflow, its webhook path, its executions, and its Gemini credential
  **cannot be inspected from this environment** (no reachable n8n access; it is
  external and the `N8N_*` live-access env vars are not present here).

### Missing access
- **n8n runtime inspection: UNVERIFIED.** There is no n8n base URL/API key
  supplied to this workspace, and the n8n Cloud instance is not reachable from
  the sandbox. No live workflow IDs, webhook paths, execution data, credentials,
  or per-instance secrets were observed. The empty `content/n8n-archive/snapshots/`
  directory is **not** evidence that an n8n archive is empty — by policy (§2.4)
  this must be treated as unknown pending a live read.

---

## 5. Current Source of Truth (per important setting)

| Setting | Source of truth | Classification |
|---|---|---|
| Gemini route (direct/n8n_relay) | env → `velora.env` (`GEMINI_ROUTE`), overridden by DB `ai_feature_providers.route` > flag `ai_gemini_relay_route` | C (route is DB-capable) / B (env) |
| n8n relay URL | env → private `velora.env` | **B** (currently) — should become **C** |
| n8n relay token | env → private `velora.env` | **D** (must stay secret; but should be *written by admin* into secure store, not a plaintext UI field) |
| Gemini API key | env → private `velora.env` | **D**, but admin-writable via `SecureCredentialStore` (A-adjacent) |
| Provider model | DB `ai_feature_providers.model` > env `GEMINI_MODEL` > catalog default | A (DB) / B (env) |
| Per-feature chain (provider, priority, enabled, route) | DB `ai_feature_providers` | **A** |
| Feature flags | DB `ai_feature_flags` | **A-capable but no admin endpoint** → effectively C |
| Provider credential verification metadata | DB `ai_provider_credentials` | **A** |
| Provider quota | DB `ai_provider_quotas` | C |
| JWT secret / encryption key / DB creds / MetaApi / Resend / translation | env → private `velora.env` | **D** (infrastructure/secret only) |

Classification legend: **A** Application-managed (admin-configurable and
effective) · **B** Infrastructure-managed (must stay outside panel, but should
become A) · **C** Should become application-managed · **D** Secret /
infrastructure-only (must not become a plaintext UI field) · **E** Unknown.

---

## 6. Admin Panel Coverage

| Capability | UI/API exists? | Persists? | Runtime reads it? | Secure storage? | Audit? | Effective without .env edit? |
|---|---|---|---|---|---|---|
| Set Gemini API key | **Yes** (`credentials/gemini`) | Yes (`SecureCredentialStore` → `velora.env`) | Yes (`DirectGeminiTransport`) | Yes (0600, outside docroot) | Partial (audit repo exists) | **Yes** |
| Verify Gemini credential | **Yes** (`providers/gemini/verify`) | Yes (`ai_provider_credentials` metadata only) | — (metadata) | n/a (no values) | Yes | Yes |
| Test relay connection | **Yes** (`providers/gemini/test-connection`) | No | reaches relay | n/a | Yes | Yes (needs relay already configured) |
| Set per-feature provider + model + route (direct/n8n_relay) | **Yes** (`feature-providers/{id}`) | Yes (`ai_feature_providers`) | Yes (`FeatureRouter`/`AIManager`) | n/a | Yes | Yes |
| Reorder provider chain | **Yes** (`feature-providers/reorder`) | Yes | Yes | n/a | Yes | Yes |
| **Set n8n relay URL** | **No** | **No** | (relay transport reads env) | — | — | **No** |
| **Set n8n relay token** | **No** | **No** | (relay transport reads env) | — | — | **No** |
| **Set Gemini route globally** (`GEMINI_ROUTE`) | **No** (only per-feature route exist) | **No** | env | — | — | **No** |
| Toggle `ai_gemini_relay_route` flag | **No** | **No** | DB flag | — | — | **No** |
| Set `GEMINI_MODEL` globally | **No** (per-feature model only) | No | env | — | — | No |
| Set `AI_ENABLED_PROVIDERS` | **No** | No | env | — | — | No |

---

## 7. Fresh Deployment Requirements

### Bootstrap (required before the app can start — fail-closed in `config.php`)
- `DB_*` (host/port/name/user/pass, `DB_DRIVER`).
- `JWT_SECRET` (≥32 bytes, not the default, else `RuntimeException`).
- `APP_ENCRYPTION_KEY` (base64 of exactly 32 bytes in production, else throw).
- `CORS_ALLOWED_ORIGINS` (must be explicit, no `*`, else throw).
- PHP ≥ 8.1 + extensions (curl, pdo_mysql, gd, mbstring, openssl, intl).
- MySQL, schema migrated (all `api/database/migrations/*.sql`).
- `VELORA_PRIVATE_ROOT` pointing to a directory outside the web root; a readable
  `config/velora.env` must exist (production reads env only from there).

### Admin-configurable (no file edit) — with current panel
- Gemini API key.
- Gemini route per-feature (direct/n8n_relay) and provider chain/model/priority.

### Integration configuration (can NOT be done via panel today)
- **n8n relay URL + relay token (needs manual `velora.env` write).**
- Global `GEMINI_ROUTE`, `GEMINI_MODEL`, `AI_ENABLED_PROVIDERS`.
- MetaApi token + base URL, Resend key, translation token.
- Existence of the n8n Cloud instance + "Gemini Vision Extraction Relay"
  workflow + its Gemini credential (external, owner-created).

### Secrets that must remain infrastructure-level (never a plaintext UI field)
- `JWT_SECRET`, `APP_ENCRYPTION_KEY`, `DB_PASS`, `RESEND_API_KEY`,
  `METAAPI_TOKEN`, `METAAPI_WEBHOOK_SECRET`, `TRANSLATION_SERVICE_TOKEN`.
- `GEMINI_RELAY_TOKEN`, `GEMINI_API_KEY` values must be written into the secure
  store (0600, outside docroot), never returned by any API.

---

## 8. Security Findings

| Concern | Finding |
|---|---|
| Secrets exposed to frontend JS | **NOT FOUND** — AI/security assets render `textContent` only; credential values never reach DOM. |
| Secrets returned by APIs | **NOT FOUND** — `credentials/*` returns `{configured: bool}`; `overview`/`effective` return presence booleans + status; the relay value is never returned. |
| Secrets in localStorage/sessionStorage | **NOT FOUND** — `velora-data.js` stores only access/refresh tokens + user; no provider secrets. |
| Secrets written to logs | **NOT FOUND** — transport/controller never log token values; error messages carry only sanitized codes. |
| Secrets in error responses | **NOT FOUND** — `mapRelayError` maps codes only, no upstream bodies. |
| Secrets in plaintext DB columns | **NOT FOUND for AI credential values** — `ai_provider_credentials` stores metadata + non-reversible HMAC fingerprint only. Plaintext provider secrets live in `velora.env` (0600, outside docroot). |
| Secrets embedded in generated static files / HTML | **NOT FOUND** — CSP/checksum manifests track scripts/styles; no secret content in localized HTML. |
| Secrets in Git / committed config | **NOT FOUND** — `.env` is gitignored (except `api/.env.example` placeholders); no live relay/token values in tracked files. |
| Secrets in workflow exports / n8n | **UNVERIFIED** (external; not inspectable from here). |
| Secrets in patches | **NOT FOUND** — no patch under review contains secrets. |
| Encryption at rest for provider secrets | **Present via `APP_ENCRYPTION_KEY`**; however **relay token is stored as plaintext line in `velora.env`** (not encrypted) — matches existing design, but worth noting if relay token becomes panel-managed. |
| RBAC on admin AI endpoints | **Verified** — all admin routes behind `adminOnly()` + `requirePermission`. |
| Route-activation gate for invalid credentials | **Verified** — `CredentialVerificationGate` excludes CONFIRMED-INVALID credentials from both chains. |

---

## 9. Gap Matrix

| Area | Current State | Required for Fresh Deploy | Admin Panel Can Configure? | Must Edit Files/ENV? | Recommended Architecture | Priority |
|---|---|---|---|---|---|---|
| Manual `velora.env` provisioning | app fails closed if missing | must exist outside docroot with all bootstrap secrets | No | **Yes** | keep as CLI/secret-manager bootstrap (unchanged) | **P0** |
| n8n relay URL + token | env-only, no endpoint | required to use relay route | **No** | **Yes** | add admin endpoint writing to `SecureCredentialStore` (secret, encrypted) | **P0** |
| n8n cloud relay workflow existence | external, owner-managed | must exist + hold Gemini key/credential | No | n/a | document as infrastructure dependency; panel can only point at it | **P0** (blocker if relay intended) |
| Global `GEMINI_ROUTE` toggle | env-only | to pick direct vs relay without file edit | No | Yes | add DB-backed setting (env override still wins) | **P1** |
| Per-feature route/model/chain | **DB, admin-editable, runtime-effective** | none extra | **Yes** | No | keep DB as source; add docs | **P1** (already done) |
| `ai_gemini_relay_route` flag toggle | DB-only, no endpoint | none for direct | No | No (manual DB) | expose feature-flag admin endpoint | **P1** |
| Gemini API key | admin-editable | only for direct route | **Yes** | No | keep | done |
| `GEMINI_MODEL` / `AI_ENABLED_PROVIDERS` global | env-only | default model/provider selection | No | Yes | DB-backed provider settings (or accept env-only as deployment config) | **P2** |
| Provider quota caps | DB-only, no endpoint | — | No | No | optional admin endpoint | **P2** |
| n8n archive/migration tooling | offline, read-only, not runtime | — | No | n/a | leave as owner-run tooling | **P3** |
| Relay token encryption at rest | plaintext line in `velora.env` | — | n/a | n/a | encrypt if panel-managed (use APP_ENCRYPTION_KEY) | **P2** |
| n8n live credential verification of upstream Gemini | not possible without upstream key (n8n holds it) | — | n/a | n/a | document as `RELAY_UPSTREAM_CREDENTIAL_UNVERIFIABLE` | P3 |

---

## 10. Recommended Target Architecture (NOT implemented)

To achieve **Deploy → Login → Configure → Save → Runtime uses DB/secure config →
No source-file editing**:

1. **Introduce a DB-backed "integration environment" table** (`integration_settings`
   or reuse a generic `app_settings` key/value store) holding the *non-secret*
   runtime integration values Velora legitimately owns: relay base URL, metric
   defaults, timeouts, feature flags, provider defaults, enabled-provider list.
   Values are validated server-side against allowlists and written only through
   an admin RBAC endpoint (Super Admin), each mutation audited.

2. **Move the sensitive relay token (and any future secret) to the existing
   `SecureCredentialStore`** (private `velora.env`, 0600, outside docroot) and
   expose **write-only** admin endpoints (`POST /api/v1/admin/integrations/{key}`
   returning `{configured: bool}` only). Optionally encrypt at rest with
   `APP_ENCRYPTION_KEY` (AES-256-GCM) rather than a plaintext line.

3. **Config read precedence change:** at runtime, `Config::env()` becomes the
   *override*; the effective value resolves as **DB/admin setting → velora.env →
   process env → default**. (This is the inverse of today's env-wins, but is the
   only way a panel save can take effect without a file edit. Env must remain the
   highest-priority *secret* override for infra-managed values.)

4. **Expose feature-flag + provider-quota + provider-default admin endpoints**
   (`/api/v1/admin/ai/flags`, `/api/v1/admin/ai/providers/{name}/defaults`), all
   behind `P_SETTINGS_MANAGE` (Super Admin only), validated + audited.

5. **For the n8n *workflow* itself:** treat it as an external, owner-managed
   infrastructure dependency. Velora configures only where to reach it
   (URL + token) and the relay contract; the workflow, its internal nodes, and
   its Gemini credential remain in n8n Cloud. Provide a panel "Integrations"
   status view that reports reachability (already available via
   `test-connection` → `RELAY_REACHABLE`) and documents that upstream Gemini
   credential validity is **unverifiable from Velora**.

6. **Keep the AI provider chain (model/priority/route/enabled) as the DB-driven
   `ai_feature_providers` source** — this is already the mature,
   admin-editable, runtime-effective layer; do not replace it.

---

## 11. Exact Implementation Plan (PLAN ONLY — not executed)

### Phase A — Admin-manageable relay connection (P0)
- **Files:** `api/src/Admin/IntegrationConfigController.php` (new);
  `api/index.php` (routes, Super-Admin perm); `api/src/AI/Services/ProviderCatalog.php`
  (reuse `relayKeys`); `api/src/Core/SecureCredentialStore.php` (already supports
  relay keys — no change needed); `admin/index.html` + a new
  `public/assets/velora-admin-integrations.js` (UI) and the localization
  `en.json`/`fa.json` + canonical localization build + validator.
- **DB migration:** none required (reuses `SecureCredentialStore`).
- **API:** `POST /api/v1/admin/integrations/relay` (write-only URL + token),
  `DELETE .../relay`, `GET .../relay/status` (`{urlConfigured, tokenConfigured, reachable}`).
- **Admin UI:** "Integrations" panel module with URL field + token field
  (password input), save → status → `test-connection`.
- **Secret handling:** token stored via `SecureCredentialStore` (0600, outside
  docroot); values never returned (only booleans/status) — optionally AES-GCM
  encrypt at rest.
- **Runtime change:** `N8nGeminiRelayTransport` keeps reading `Config::env()`
  (which reads the same `velora.env` the store writes) — no transport change.
- **Tests:** extend `test_admin_panel.php`/new `test_integration_config.php`:
  write → read-back status, values never echo, RBAC (user denied), 422 on bad
  URL/token, persistence across a simulated config reload (`Config::clearCache`).
- **Deployment implications:** none (no new table; only env file writes).

### Phase B — DB-backed global route/defaults + flag endpoint (P1)
- **Files:** `api/src/Admin/AISettingsController.php` (new);
  `api/database/migrations/v1.4_app_settings.sql` (+ rollback);
  `api/index.php`; `GeminiProvider.php` (read effective from DB first, env
  override remains for infra-managed values); `FeatureRouter.php`;
  `EffectiveConfigService.php`; `admin/index.html` + asset + localization.
- **DB migration:** new `app_settings` (key/value, type, updated_by,
  audited). Seed `gemini_route`, `gemini_model`, `enabled_providers`.
- **API:** `GET/PATCH /api/v1/admin/ai/settings` (Super Admin, validate vs allowlists).
- **Admin UI:** "Settings"/"AI" panel sections for global route, default model,
  enabled providers; feature-flag toggles.
- **Secret handling:** none (non-secret values only).
- **Runtime change:** documented precedence becomes
  `DB setting → velora.env → process env → default` for these keys.
- **Tests:** route resolution precedence, flag toggle effect, RBAC, read-back,
  validator (0 issues).
- **Deployment implications:** migration must run on deploy.

### Phase C — Relay token encryption at rest (P2)
- **Files:** `SecureCredentialStore.php` (AES-256-GCM via `APP_ENCRYPTION_KEY`),
  migration-free (format-versioned blob). Tests for round-trip + fail-closed.

### Phase D — Integrations status + honest "Not Implemented" labels (P1/P2)
- **Files:** `admin/index.html`, `velora-admin-integrations.js`, catalog +
  build. Render reachability and clearly label external billing / analytics
  revenue / n8n-workflow-vendor as **Not Implemented** (never fabricated).

---

## Constraints honoured
Read-only; no repo/config/DB/n8n/workflow/credential/env/deploy change; no
commits/branches/PRs/pushes/migrations; no secrets printed; `pro` never treated
as an RBAC role; unsupported/unknown marked explicitly; runtime facts separated
from code-inspection; UI-existence separated from persistence/runtime effect;
env removal in `.env.example` (it isn't removed) not treated as admin-managed.

**Key unsupported facts to re-verify later (UNVERIFIED):** live reachability of
the direct Gemini endpoint from the actual host; existence/state of the n8n
Cloud relay workflow; live n8n instance inventory; whether the n8n relay URL/token
are already present in the *deployed* `velora.env` (not inspectable here).
