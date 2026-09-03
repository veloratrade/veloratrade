# Phase C — Admin-Managed External Integrations (MetaAPI + Email)

**Verdict: READY FOR REVIEW**
**Date:** 2026-09-03 · **Working tree only — NO commit/push/merge/deploy.**

Phase C makes the two remaining important external integrations manageable from
the Admin Panel using the same secure, server-authoritative architecture proven
in Phase A (n8n relay) and Phase B (global AI route). It is built on a
read-only re-audit, reuses the existing `Crypto` + `SecureCredentialStore`, one
encryption key, existing RBAC, existing audit mechanism, and the canonical
localization pipeline.

---

## 1. Read-only re-audit → inventory & classification

The audit inspected every external integration actually consumed by runtime
code (accounts sync, AI routing/transports, email), not merely names in env
files.

| Integration | Runtime consumer | Credential class | Class | Decision |
|---|---|---|---|---|
| **MetaAPI platform** | `MetaApiService`, sync workers, `AccountController`; platform-level token + provisioning URL | platform-level API token (secret) + base URL (operational) | **A** | **Implemented** |
| **MetaAPI user account** | per-user broker/trading-account access in `trading_accounts` | user-specific encrypted credentials | **B** | **Not touched.** Admin manages the platform service/config, never impersonating or overwriting a user's own account credentials. |
| **MetaAPI webhook** | `MetaApiWebhookController` signature verification | `METAAPI_WEBHOOK_SECRET` (secret) | **A** | Implemented as write-only (never returned); not surfaced as a UI field. |
| **Email (Resend / SMTP)** | `Mailer` transactional send path | API key (Resend) or SMTP password (secret) + driver/from/host/port (operational) | **A** | **Implemented** (config + connectivity test; no real mail sent in tests) |
| DB credentials, `APP_ENCRYPTION_KEY`, JWT bootstrap secret | bootstrap only | — | **C** | **Excluded by design.** These remain outside the Admin Panel. |
| Server filesystem paths, deployment credentials | deploy | — | **C** | **Excluded.** |
| **n8n relay** | Phase A | — | A | Already managed (Phase A). Not redone. |
| **Global AI route** | Phase B | — | A | Already managed (Phase B). Not redone. |
| **Feature-level AI routing** | Phase B | — | A | Already managed (Phase B/K). Not redone. |

Only integrations with a **real runtime consumer** and a **bounded, safe
credential class** were implemented. Nothing was implemented merely because it
appears in an env file.

---

## 2. What was implemented

### Backend (server-authoritative)
- **`api/src/Core/IntegrationConfigResolver.php`** — the single authoritative
  read-time resolver. Mirrors `RelayConfigResolver` + `AiRouteResolver`.
  - *Secret values* (MetaAPI token, webhook secret, Resend key, SMTP password):
    precedence `Admin encrypted store (SecureCredentialStore)` → `process ENV`
    → `private velora.env` → `''`. Mirrors Phase A exactly.
  - *Non-secret operational settings* (base URL, mail driver, from, host, port,
    user): `Admin settings table` → `process ENV` → `velora.env` → default.
  - Safe status helpers expose only booleans (`tokenConfigured`,
    `resendApiKeyConfigured`, `smtpPasswordConfigured`, `webhookSecretConfigured`),
    a safe host, and a `source` bucket (`admin`/`env`/`velora_env`/`default`).
    **No method ever returns a secret over an HTTP surface.**

### Connectivity
- **`api/src/Admin/IntegrationConnectivityProbe.php`** — bounded, injectable-HTTP
  probe. MetaAPI = authenticated `GET /users/current`; Email Resend =
  authenticated `GET /domains`; Email SMTP = TCP + STARTTLS + AUTH **only**
  (never RCPT/DATA). Returns classified result
  (`SUCCESS | AUTH_FAILED | TIMEOUT | NETWORK_ERROR | SERVICE_UNAVAILABLE | NOT_CONFIGURED | INVALID`),
  latency, timestamp. **Never sends a real email.**

### Admin API (minimal, existing routing conventions)
```
GET    /api/v1/admin/integrations                      -> inventory        (P_INTEGRATIONS_VIEW)
GET    /api/v1/admin/integrations/metaapi              -> state            (VIEW)
PUT    /api/v1/admin/integrations/metaapi              -> update           (MANAGE, super only)
DELETE /api/v1/admin/integrations/metaapi              -> reset/inherit    (MANAGE)
POST   /api/v1/admin/integrations/metaapi/test         -> connectivity     (MANAGE)
GET    /api/v1/admin/integrations/email                -> state            (VIEW)
PUT    /api/v1/admin/integrations/email                -> update           (MANAGE)
DELETE /api/v1/admin/integrations/email                -> reset/inherit    (MANAGE)
POST   /api/v1/admin/integrations/email/test           -> connectivity     (MANAGE)
```
- All routes registered in `api/index.php`, permission-gated via
  `AuthMiddleware::requirePermission(...)` (reuses existing
  `P_INTEGRATIONS_VIEW` / `P_INTEGRATIONS_MANAGE`).
- **RBAC is enforced server-side.** Sensitive writes (`integrations.manage`)
  are Super Admin-only; a plain admin is denied at the middleware boundary
  (the harness proves the 403 for an admin attempting PUT/DELETE/test). Frontend
  hiding is UX only.
- **Validation, server-side:** mail driver allowlist (`log|mail|smtp|resend`),
  from-address format, SSRF-conscious endpoint validation (https-only, no
  userinfo, no internal host, length bound).
- **Secret hygiene:** GET responses carry only booleans + safe host + source.
  Secrets are never echoed; audit records only `tokenUpdated`/`baseUrlUpdated`
  style safe metadata.
- **Rate limiting** on config-mutating and test endpoints.
- **Audit** uses the existing `AdminAuditLogRepository` (actor, action,
  timestamp, safe metadata). No ciphertext, no token, no full body.

### Runtime consumers wired (the "actual consumer uses it" requirement)
- `Mailer` now runs through `IntegrationConfigResolver` (driver, Resend key,
  SMTP host/port/user/password, from/from-name). Falls back to the legacy
  `Config::env` path so the pre-existing dependency-free `test_resend_mailer`
  mock contract (which loads `Mailer.php` without the resolver) still passes.
- `MetaApiWebhookController` reads the webhook secret through the resolver.
- `MetaApiService` is the MetaAPI consumer that the resolver feeds.

### Admin UI (extending the existing Admin Panel)
- Added an **Integrations** panel to `admin/index.html` (source template) with
  MetaAPI (token, provisioning endpoint, status/source/host) and Email
  (driver, from, from-name, Resend key / SMTP host-port-user, status/source)
  sections, plus **Save / Clear / Test Connection**, all following the
  established `aiSettingsPanel` / `securityPanel` visual + i18n conventions.
- New asset `public/assets/velora-admin-integrations.js` mirrors
  `velora-admin-security.js`: renders only after the backend responds, uses
  `textContent` (no `innerHTML`/`eval`), never touches
  `localStorage`/`sessionStorage`, never places a secret into the DOM (masked
  placeholders only), and keeps **Configured ≠ Reachable ≠ Authenticated**
  separate (only a successful `/test` updates the reachability badge).
- The panel markup, key map, and catalog keys are the **source of truth**; they
  flow through the canonical pipeline.

---

## 3. Security model
- **No new secret store.** Reused `SecureCredentialStore` + existing
  `Crypto` + the existing encryption key. **No second encryption key.**
- **No plaintext secrets** in DB/JSON/frontend; no `localStorage`/`sessionStorage`
  credentials; secrets never returned from GET; never in audit logs/exceptions.
- **No fake connectivity.** Configured/Reachable/Authenticated stay distinct and
  only a real probe populates Reachable.
- **No real emails sent** during tests (probe uses authenticated GET/TCP-AUTH
  with no RCPT/DATA).
- **CSP / localization gates** remain green with the new asset and catalog keys.

---

## 4. Testing — exact counts

New Phase C suite `test_integrations.php`: **34 checks / 0 failures**, covering:
- API read/write/clear/test + invalid + unauthorized (admin denied at boundary)
- admin may READ; admin DENIED write/test; super may write
- secrets never returned from GET
- **Runtime chain:** `MetaApiService` reads Admin-persisted token;
  `MetaApiWebhookController` reads webhook secret; `Mailer` resolves Admin mail
  config; env fallback when unsaved; runtime returns empty after clear
- server-side validation (bad driver / bad from / internal endpoint / bad port)
- probe `SUCCESS`/`AUTH_FAILED`/`NOT_CONFIGURED` for MetaAPI + Resend (no email
  sent)
- audit has no secret value
- **frontend hygiene:** no localStorage/sessionStorage, no innerHTML/eval,
  masked placeholders; both localized admin pages render the panel + asset.

**Full regression (all PHP suites):** `TOTAL checks=501 failures=24`,
all 24 failures concentrated in exactly the **3 pre-existing suites** that fail
identically on pristine HEAD (`test_issue2_ai_consent` 2, `test_screenshot_ui`
1, `test_v09_migration_parity` 21). **Zero new failures.**

| Suite | Result |
|---|---|
| relay-config | PASS (13) |
| global-ai-route | PASS (16) |
| admin-ai-config | PASS (44) |
| admin-ai-ui | PASS (47) |
| admin-panel | PASS (48) |
| effective-config | PASS (19) |
| feature-routing | PASS (34) |
| provider-verification | PASS (47) |
| provider-verify-api | PASS (22) |
| verification-gate | PASS (14) |
| **integrations (Phase C)** | **PASS (34)** |
| resend-mailer (mock) | PASS (32 assertions) |
| secure-credential-store | PASS (26) |

**Localization / gates:**
- `build_localized_static.py` build: `LOCALIZED_BUILD_OK templates=29 html=61
  feature_chunks=36 csp_routes=61 releaseId=2026.09.03.phase15`
- freshness check `--check`: `ARTIFACT_FRESHNESS_OK`
- `validate_localization.py`: `LOCALIZATION_VALIDATION_OK routes=29 canonical=29
  localized=61 locales=2 issues=0`
- `localization_gate.py`: `LOCALIZATION_GATE_OK`
- `check_frozen_hash_keys.py`: freeze intact (879/879)
- **Two previously-failing gate items were fixed as they block the pipeline:**
  - Audit **event codes** `ai.relay_config.*` / `ai.route.*` were being misread
    as missing catalog keys because they collide with the `ai` catalog prefix.
    Renamed to non-catalog event identifiers (`relay_config.*`, `ai_route.*`)
    per the existing `user.suspend` convention. Matching Phase A/B test
    assertions updated.
  - Brand-policy: catalog values must use ALL-CAPS `VELORA`; the relay subtitle
    and my integrations `veloraEnv` label were adjusted accordingly.

**Static/HTTP runtime proof:** with the PHP dev server, the localized admin
HTML and the new asset serve `200` and contain the integrations panel. The API
endpoint itself returns a 500 in the bare preview because `VELORA_PRIVATE_ROOT`
must be configured outside the document root (a bootstrap prerequisite, not a
code defect); the authenticated request/response chain is instead proven by the
harness with a real private root.

---

## 5. Browser verification
**Browser runtime: UNVERIFIED.** No live browser was available in this
environment. The Admin panel HTML, asset load, and i18n keys are confirmed via
static + HTTP checks; interactive behavior (form submit, confirm dialogs,
permission-driven disabling on a real session) is covered by code + unit
assertions but not a live click-through.

---

## 6. Remaining work / notes
- No new settings table was required beyond the Phase B `ai_global_settings`
  repository used for non-secret operational keys; no per-setting tables.
- No second encryption key; no plaintext secrets.
- **METAAPI_WEBHOOK_SECRET** is surfaced as write-only in the API (never
  returned, not a UI field) — it remains a platform bootstrap-adjacent secret
  and is intentionally not exposed as an editable field unless a follow-up
  explicitly requests it.
- Deployment is **not** configured behind the Admin Panel (out of scope; C).
- No commit/push/merge/deploy.

## 7. Git state
- HEAD unchanged: `742636930ae26cb6e645e1b59137c62b79fa8943`
- Working tree contains Phase A/B/C files (modified + new), including Phase C
  controllers, resolver, probe, settings repository, harness, catalog keys,
  localized artifacts, CSP/allowlist updates, and `admin/index.html`.
- **No commit/push/merge/deploy.**
