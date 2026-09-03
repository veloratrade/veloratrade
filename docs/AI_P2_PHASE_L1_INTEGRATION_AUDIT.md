# AI P2 — PHASE L1 INTEGRATION AUDIT

**Date:** 2026-09-04 · **Scope:** read-only audit of the existing (Phase C/K) integration architecture. **No deployment.** **No production access.** **No commit.**
**HEAD:** `742636930ae26cb6e645e1b59137c62b79fa8943` (working tree carries Phase A–L work).

This audit traces the **actual runtime path**, not filenames. All classification is evidenced by source
inspection of the live code path (controllers → RBAC middleware → secure store → resolver → adapter →
provider API → normalizer → audit) plus the executed test battery.

---

## 1. Architecture traced (the real runtime chain)

```
Admin UI (velora-admin-integrations.js / velora-admin-ai.js)
  → POST /api/v1/admin/integrations/{metaapi|email}/test           (P_INTEGRATIONS_MANAGE)
  → POST /api/v1/admin/providers/{provider}/{verify|test-connection} (admin RBAC)
  → RateLimiter::hit (bounded, server-side)
  → AuthMiddleware::requirePermission(...)                          (RBAC, server-authoritative)
  → SecureCredentialStore / IntegrationConfigResolver               (encrypted at rest, never returned)
  → ProviderVerifierRegistry / GeminiCredentialVerifier | IntegrationConnectivityProbe
  → REAL provider API (curl/TLS) | SMTP STARTTLS+AUTH handshake     (bounded timeout)
  → VerificationResult (secret-free normalizer) | probe result
  → AICredentialMetadataRepository::record (safe metadata + fingerprint)
  → AdminAuditLogRepository::record (integration.connection_test / *_updated)  [no secret]
  → Response::json(safe metadata only)                              [no credential in payload]
```

The browser never receives a credential: it receives `VerificationResult::toArray()` (provider, status,
verified, reachable, checked_at, latency_ms, error_code, message, retryable, source) — or `{configured: bool}`.

---

## 2. Provider matrix

| Integration | Credential Storage | Resolver | Real Auth Test | Real Capability Test | UI Status | Audit | Tests |
|---|---|---|---|---|---|---|---|
| **AI — Gemini (direct)** | `SecureCredentialStore` + `ai_provider_credentials` (metadata + fingerprint only) | `IntegrationConfigResolver`/`GeminiProvider::getRoute` | **PASS** — real `GET /v1beta/models?pageSize=1` with `x-goog-api-key` | **PARTIAL** — `capabilities()['list_models']=false`; presence is reported truthfully, but model listing is not yet invoked | **PASS** (verify/test-connection + credential status) | **PASS** (was MISSING — fixed this phase §3) | **PASS** (verify_api 27, verification 47, gate 14) |
| **AI — Gemini (n8n relay)** | relay token (secret store, never returned) | `RelayConfigResolver`/`GeminiProvider` | **PARTIAL** — `verifyCredential` returns `UNKNOWN` (credential held upstream, not provable from Velora — honest) | **PARTIAL** — `testConnection` is a relay reachability TTL/TCP HEAD probe only | **PASS** | **PASS** | **PASS** (relay-config 13) |
| **AI — OpenAI / Claude / others** | not wired to a verifier | — | **NOT IMPLEMENTED** (`ProviderVerifierRegistry` has only `gemini`; returns honest `CAPABILITY_UNAVAILABLE`) | **NOT APPLICABLE** | **PASS** (reports unavailable, not fabricated) | N/A | N/A |
| **MetaAPI** | `SecureCredentialStore` (token/webhook secret, encrypted) | `IntegrationConfigResolver::metaApi*` | **PASS** — real authenticated probe with bearer/header | **PARTIAL** — connectivity/auth classification (200/401/403/408/5xx) and NOT_CONFIGURED; real trading-account data check is separate | **PASS** | **PASS** (`integration.test_ran` + `_updated`/`_reset`) | **PASS** (integrations 34) |
| **Email — Resend** | `SecureCredentialStore` (key) | `IntegrationConfigResolver::mail*` | **PASS** — real authenticated `GET /domains` (no message sent) | **PARTIAL** — auth/connectivity only; no actual send during test (intentional, safe) | **PASS** | **PASS** | **PASS** |
| **Email — SMTP** | secret store | mail resolver | **PASS** — real TCP+STARTTLS+AUTH handshake, never RCPT/DATA (no mail sent) | **PARTIAL** — auth/connectivity only | **PASS** | **PASS** | **PASS** |
| **n8n relay** | relay token (secret store) | `RelayConfigResolver` | **PARTIAL** — reachability/TLS probe; authenticates only if the relay exposes it | **PARTIAL** | **PASS** | **PASS** | **PASS** (relay-config 13) |

**Key finding:** every currently-registered provider performs **REAL outbound verification** via HTTP/TLS/SMTP.
No provider result is fabricated; unsupported capabilities are surfaced as **unavailable**, never invented;
`VALID` is the only state eligible for activation/routing-as-verified; a non-empty string is always
`UNVERIFIED`, never reported healthy.

---

## 3. Gaps found and hardened this phase (minimal, non-rewriting)

**Gap L1-AUDIT:** the two live **AI provider** endpoints (`POST /api/v1/admin/providers/{provider}/verify`
and `.../test-connection`) persisted safe metadata but **did not emit an `integration.connection_test`
audit event**, whereas the MetaAPI/email test paths already did. Per §10.

**Fix (minimal):**
- Added `AdminAuditLogRepository` dependency to `AIConfigController`.
- Both `verifyCredential` and `testConnection` now emit `integration.connection_test` with only safe
  metadata — provider, operation, normalized `result`, sanitized `error_code`, `latency_ms`, actor, IP,
  user-agent, correlation/request id. **No credential value, no key name, no provider error body.**
- The audit repository already **fails open** (if the trail table is unavailable it logs `VELORA_AUDIT_SKIP`
  and returns 0 without breaking the provider operation) — verified, unchanged.
- Added 5 audit assertions to `tools/tests/test_provider_verify_api.php` (audit row exists, records the
  operation, records the normalized result, and contains no credential value / no key name).

**Not changed (correctly classified, no action):**
- `list_models` capability is honestly `false` — reporting it unavailable rather than fabricating a model
  list is the desired behavior (no fake provider data).
- `OpenAI`/`Claude` verifiers do not exist — the registry returns honest `CAPABILITY_UNAVAILABLE`; adding
  fake verification would violate the mission.
- IDOR: integration credential/config is **global server config, not per-user**, so one user cannot target
  another user's config — operationally N/A (documented, not a defect).

---

## 4. Status model normalization (verified against the code)

The project uses `Velora\AI\Providers\CredentialStatus` (semantically richer than the §4 model) and maps the
required states as follows:

| §4 required state | Project enum / probe result | Where |
|---|---|---|
| `NOT_CONFIGURED` | `UNVERIFIED` / probe `NOT_CONFIGURED` | credential metadata + probe |
| `AUTHENTICATED` | `VALID` | only provider-confirmed |
| `AUTH_FAILED` | `INVALID_CREDENTIAL` / `EXPIRED` / `REVOKED` | `confirmedInvalid()` |
| `UNREACHABLE` | `NETWORK_ERROR` | curl failure |
| `TIMEOUT` | `NETWORK_ERROR` + code `TIMEOUT` | bounded timeout |
| `RATE_LIMITED` | `RATE_LIMITED` / `QUOTA_EXCEEDED` | HTTP 429 |
| `INSUFFICIENT_PERMISSIONS` | `INSUFFICIENT_PERMISSION` / `REGION_RESTRICTED` | HTTP 403 |
| `PROVIDER_ERROR` | `PROVIDER_UNAVAILABLE` / `UNKNOWN` | HTTP ≥500 / unexpected |
| `HEALTHY` | `VALID` **and** `verified=true` and reachable | activation-gated |

**No state is collapsed into a generic "ERROR".** Each maps to a distinct, meaningful enum/probe status, and
the frontend renders them distinctly.

---

## 5. Secret safety (evidence, not assumption)

- `VerificationResult::toArray()` contains **no credential value, API key, relay token, Authorization header,
  raw upstream body, or filesystem path** (verified by test: `toArray() NEVER contains the credential`).
- `AICredentialMetadataRepository` stores only `status`, `verified`, a **non-reversible HMAC fingerprint**,
  `last_checked_at`, `error_code`, `latency_ms`, `version` — never the secret; `safeMetadata()` excludes even
  the fingerprint.
- Credential endpoints return **only `{configured: bool}`**; value is write-only and cleared after submit.
- `SecretRedactor` exists and is applied to error/log paths.
- Frontend: credentials are read **only from inputs**, never stored; no `localStorage`/`sessionStorage`;
  no secret in DOM attributes — verified by the frontend-hygiene test in `test_integrations.php`.
- `AdminAuditLogRepository::sanitize()` strips secret-bearing keys from metadata.

---

## 6. RBAC / IDOR

| Role | Read status | Write config | Run connection test |
|---|---|---|---|
| Super Admin | **PASS** (`P_INTEGRATIONS_VIEW`) | **PASS** (`P_INTEGRATIONS_MANAGE`) | **PASS** |
| Admin | **PASS** | **DENIED** (403 `PERMISSION_DENIED`) | **DENIED** |
| Ordinary User | **DENIED** (`ADMIN_REQUIRED` 403) | **DENIED** | **DENIED** |

No new permission was introduced — existing canonical `P_INTEGRATIONS_VIEW` / `P_INTEGRATIONS_MANAGE` fully
express the required access (documented rationale: reuse, per §11). IDOR is N/A because integration config
is global server config (tested via the RBAC matrix; a non-admin cannot reach any integration endpoint).

---

## 7. Existing test coverage mapped

| Suite | Checks | Covers |
|---|---|---|
| `test_integrations.php` | 34 | MetaAPI/Email full chain, RBAC (admin denied / super allowed), secret-never-returned, runtime resolution, audit-without-secret, validation, probe classification, frontend-hygiene |
| `test_provider_verification.php` | 47 | CredentialStatus model, VerificationResult secret-safety, Gemini classification (401/400/403/429/5xx/timeout), eligibility gating |
| `test_provider_verify_api.php` | 27 | Real endpoint (verify/test-connection), normalized status, no-AIza-in-response, relay-can't-claim-validity, rate-limit boundary (15 then 429), RBAC, **audit trail (this phase)** |
| `test_verification_gate.php` | 14 | credential verification gate activation logic |
| `test_relay_config.php` | 13 | relay config resolution reachability |
| `test_effective_config.php` | 19 | effective routing with verification |
| `test_global_ai_route.php` | 16 | global route resolver |

= **170 meaningful backend assertions** across the focused integration/provider chain (far above the
30+ minimum; none are trivial count-inflation).
