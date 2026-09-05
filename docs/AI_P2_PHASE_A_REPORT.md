# Phase A Final Report — Admin-Managed n8n Gemini Relay Configuration

**Date:** 2026-09-03
**Branch state:** NOT committed / NOT pushed / NOT merged / NOT deployed (actionable item remains open for the owner).
**HEAD:** `742636930ae26cb6e645e1b59137c62b79fa8943`
**Pre-existing dirty tree:** 48 files (paused Admin Panel work) — preserved, untouched by this phase except where the phase legitimately extends the same feature surface (localization build regeneration).

---

## 0. Problem statement (the acceptance criterion this phase must prove)

> Allow an authorized Super Admin to configure the n8n Gemini Relay URL and Relay Token from the Admin Panel, securely persist them, and make the runtime actually use the persisted configuration without manual `.env` editing.

**Proof required (full chain):** Super Admin → Admin Panel → Admin API → `SecureCredentialStore` → runtime resolver → `N8nGeminiRelayTransport`, **with the token never exposed** in any response, frontend JS, HTML, storage, log, exception, audit payload, generated file, Git diff, or test output.

---

## 1. What was delivered

### Backend (server-side — the authorization boundary)

| Artifact | Path | Purpose |
|---|---|---|
| Controller | `api/src/Admin/RelayConfigController.php` | `GET / PUT / DELETE /api/v1/admin/integrations/relay/config` |
| Resolver | `api/src/Admin/RelayConfigResolver.php` | Runtime precedence: secured store → process ENV → `velora.env` → `''` |
| Store (extended) | `api/src/Core/SecureCredentialStore.php` | AES-256-GCM encrypted-at-rest secret store + two new `SECRET_*` constants |
| Routes | `api/index.php` | Registered GET (view), PUT/DELETE (manage) with RBAC permission middleware |
| Runtime consumer | `api/src/AI/Transports/N8nGeminiRelayTransport.php` | No-arg ctor now resolves URL/token through the resolver |
| Runtime consumer | `api/src/AI/Providers/GeminiCredentialVerifier.php` | Panel "test connection" uses the resolver (reflects persisted config) |

### Frontend (Admin Panel)

- `admin/index.html` — new "n8n Gemini Relay" sub‑section inside the existing AI settings panel with a write‑only token field, masked status, Save/Clear controls.
- `public/assets/velora-admin-ai.js` — relay module: fetch status, PUT/DELETE, always re‑fetch authoritative state, `textContent`‑only rendering, no `innerHTML` with server data.

### Localization (canonical)

- Keys added to `public/locales/en.json` & `public/locales/fa.json` (canonical source). **No hardcoded Persian strings.**
- Regenerated via the canonical build `tools/localization/build_localized_static.py` (`release-id 2026.09.03.phase13`): feature chunks, `feature-manifest.json`, `csp-manifest.json`, `localized/` admin HTML. Freshness check (`ARTIFACT_FRESHNESS_OK`) passes.

### Tests

- New harness `tools/tests/test_relay_config.php` — **13 checks**, all PASS.
- Extended `tools/tests/test_admin_ai_ui.php` with 15 Phase-A panel-wiring checks → **47 checks**, all PASS.

---

## 2. Acceptance proof — the full chain

The 13-check harness (`php tools/tests/test_relay_config.php`) exercises the real classes end-to-end:

```
relay-config: PASS (13 checks, 0 failures)
```

In order, the harness proves:

1. An **Admin** (non-super) may **read** relay metadata (`P_INTEGRATIONS_VIEW`).
2. An **Admin** is **DENIED** write (`P_INTEGRATIONS_MANAGE` = super_admin only) → project 403 path.
3. A **Super Admin** may **write** relay config.
4. A **Super Admin** **persists** the relay URL.
5. The **runtime resolver + real `N8nGeminiRelayTransport` ctor** read the persisted URL/token.
6. The **token value is NEVER returned** by the status endpoint (asserted against a literal sentinel).
7. Status reports `tokenConfigured` as a boolean only.
8. **Process-ENV fallback** still works when no stored value is present.
9. Non-`https` URL is **rejected** (not silently persisted).
10. Internal-host URL is **rejected** (SSRF guard: `127.0.0.1`, `localhost`, link-local/private IP ranges).
11. Userinfo-embedded URL (`user:pass@`) is **rejected**.
12. An **audit record exists** and **contains no token value**.
13. A Super Admin may **clear** relay config; the runtime is then no longer "configured".

---

## 3. Precedence (single source of truth, no competing store)

```
RelayConfigResolver::url()/token()
  1. SecureCredentialStore encrypted secret        (admin-managed, AES-256-GCM)
  2. process ENV  (GEMINI_RELAY_URL / GEMINI_RELAY_TOKEN)  — infra override
  3. private velora.env  (Config::env fallback)   — legacy plaintext
  4. ''                                            — unavailable
```

**Why the existing `SecureCredentialStore` was used (not a new store):** the constraint requires reuse of existing facilities and explicitly forbids a second competing secret store. The encrypted file lives at `{VELORA_PRIVATE_ROOT}/config/velora-secrets.json`, mode **0600**, encrypted with AES-256-GCM using `Crypto` + `APP_ENCRYPTION_KEY`, protected by `flock` + atomic rename + a `.bak` backup.

`Read`/`readSecrets` return `[]` when the file is absent or unreadable — so the resolver degrades gracefully to ENV/`velora.env` in a fresh production bootstrap **without** a missing-file error.

---

## 4. Token-never-exposed controls (enumerated against each surface)

| Surface | Control | Verified by |
|---|---|---|
| API response | `show()/update()/clear()` return only booleans + `safeUrlHost()`; token never echoed | harness checks 6, 7 |
| Frontend JS | token only read into a write-only input; cleared after submit; never stored in a variable after submit | `admin-ai-ui` "no raw admin.relay.* literal" + code |
| HTML | masked block `••••••••` (`admin.relay.maskedToken`); no value attribute | localization/generated HTML review |
| localStorage / sessionStorage | token never written to storage (module keeps server state only) | code review |
| Logs / exceptions | controller `throw`s carry only catalog message codes; store never places value in message text | code review + harness |
| Audit payload | `ai.relay_config.updated` records only `scope`, `urlUpdated`, `tokenUpdated` booleans — never the value | harness check 12 |
| Generated static files | `localized/`, chunks, `csp-manifest` scanned — no plaintext URL/token | repository scan (0 hits) |
| Git / test output | harness asserts sentinel token never appears in any string it produces | harness |

`GeminiCredentialVerifier` / transport use the **resolver** so a "test connection" reflects persisted values, but no code path ever emits the token.

---

## 5. URL validation (server-side, SSRF-conscious)

`RelayConfigController::assertValidUrl()` rejects, with dedicated message codes:

- scheme ≠ `https`
- embedded userinfo (`user:pass@`)
- empty host
- internal/loopback/link-local/private targets (`localhost`, `127.0.0.1`, `::1`, `.localhost`, private/reserved IP ranges)
- URL longer than 2048 chars

The controller returns only `INVALID_RELAY_URL` / `RELAY_CONFIG_EMPTY` **codes** (never the submitted URL or token) in error payloads.

---

## 6. RBAC — role ≠ subscription plan (unchanged rules)

- `P_INTEGRATIONS_VIEW` — granted to `admin` + `super_admin` → read/status.
- `P_INTEGRATIONS_MANAGE` — granted **only** to `super_admin` → write/clear.
- `pro` remains a **subscription plan**, not a role. **No** RBAC role definitions were changed.
- Authorization is enforced **server-side** in route middleware (`AuthMiddleware::requirePermission`). The frontend only *reflects* write capability for UX; the server remains the boundary. Unauthorized → project's established 403 response.

---

## 7. Scope discipline — what was NOT changed (preserved invariants)

No change to: n8n workflows/Cloud/credentials, Gemini behavior, provider-selection semantics, **extraction-vs-analysis routing** (relay stays extraction-only; free-text analysis → direct Gemini), RBAC role definitions, global route settings, feature flags, Billing, Analytics, MetaAPI, email config, or deployment infra.

**No commit / push / merge / deploy performed.**

---

## 8. Exclusion & remaining gaps (report-only, out of Phase A scope)

These items are intentionally **not** implemented and are reported as **remaining gaps**:

1. **Live end-to-end n8n verification** — this phase proves config acceptance, secure persistence, and runtime resolution. It does **not** and cannot claim that the live n8n workflow accepts the token. Per the "distinguish" rule, we deliberately avoid fabricating a successful live test. Distinguishing dimensions documented:
   - relay reachable ≠ relay auth accepted ≠ Gemini credential valid ≠ workflow functional.
2. **`api/config/config.php` relay keys (137–139)** — an inert config surface. No code path consumes `ai.gemini_relay_url`/`ai.gemini_relay_token` at runtime (verified by grep); the transport resolves via the resolver. The **timeout** stays env-bound (`GEMINI_RELAY_TIMEOUT`) intentionally.
3. **`GeminiProvider.php` line 59 timeout** — stays env-only intentionally; relay URL/token resolve through `N8nGeminiRelayTransport`, which `GeminiProvider::transport()` constructs with **no args** (resolver path).
4. **Global route settings / feature flags / Billing / Analytics / external n8n lifecycle** — explicitly out of scope; untouched.

---

## 9. Known pre-existing anomaly (NOT a Phase A regression)

`tools/tests/test_gemini_transport_routing.php` 500s at `N8nGeminiRelayTransport.php:98` (the `UPSTREAM_QUOTA_EXHAUSTED` error-mapping case) in the full-suite run, with "headers already sent" warnings from the test's own `echo`.

**Confirmed pre-existing:** reverting the transport file to the pristine `HEAD` version reproduces the identical 500 at the same logical line (94 on HEAD vs 98 on my copy — the line shift is only my added comment block). Isolated reproduction of both the refused-localhost-connect path and the unconfigured/empty-ctor path throws clean `AIException` subclasses with **no** 500. The failure is a test-harness/global-exception-handler artifact (constructing an exception while output has already been emitted), **not** caused by the resolver change. Per the standing rule, it was not silently modified; it is reported here.

---

## 10. Encryption-at-rest — no blocker

Encryption-at-rest was implemented **without** a new bootstrap secret or an unsafe migration: it reuses `Crypto` (AES-256-GCM) + the existing `APP_ENCRYPTION_KEY` already present in the deployment. The switch to the encrypted store is **additive** — existing plaintext `velora.env` deployments keep working through the fallback. Therefore the "stop and report the blocker" condition does **not** apply, and no weak/alternate scheme was invented.

---

## 11. Verification summary (final run)

| Suite | Result |
|---|---|
| `test_relay_config` (Phase A) | **PASS** (13/13) |
| `test_admin_panel` | PASS (48/48) |
| `test_admin_ai_config` | PASS (44/44) |
| `test_admin_ai_ui` (extended w/ Phase A wiring) | PASS (47/47) |
| `test_effective_config` | PASS (19/19) |
| `test_provider_verification` | PASS (47/47) |
| `test_verification_gate` | PASS (14/14) |
| `test_provider_verify_api` | PASS (22/22) |
| `test_provider_adapters` | PASS (39/39) |
| `test_feature_routing` | PASS (34/34) |
| Localization freshness (`--check`) | `ARTIFACT_FRESHNESS_OK` |
| PHP lint on all 7 changed PHP files | clean |
| `node --check velora-admin-ai.js` | clean |

**Total: 10/10 suites pass, 327 checks, 0 failures.**

---

## 12. Recommendation / next step

The Phase A chain is proven and green. The only remaining actionable item is for the owner to **run a live smoke test** against a real n8n relay (distinguishing reachable vs auth-accepted vs workflow-functional), then review and commit the working tree. Nothing here should be committed automatically; per standing instructions no commit/push/merge was performed.
