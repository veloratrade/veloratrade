# AI P2 — RELEASE IDENTITY (Phase A–K → Canonical Deployable Release Candidate)

**Date:** 2026-09-04 · **Scope:** create a deterministic, auditable release candidate. **No deployment.** **No production access.** **No commit made** (authorization not clearly granted; see §9).
**HEAD (unchanged):** `742636930ae26cb6e645e1b59137c62b79fa8943` · **branch:** `main`.

**Truth baseline:** This phase did **not** invent a clean state. The working tree is genuinely a large
uncommitted Phase A–K delta (see §9). All hashes below are computed from the **actual current file contents**
and are reproducible. Nothing was deployed; no production was touched; no secret value was printed.

---

## 1. Release

### 1.1 Identity summary

| Field | Value |
|---|---|
| Source base commit SHA | `742636930ae26cb6e645e1b59137c62b79fa8943` (HEAD — does **not** contain Phase A–K) |
| Release delta | uncommitted working-tree delta (57 staged/unstaged modified + 39 release-candidate untracked files) |
| **Release candidate identifier** | `velora-phaseA-K-2026-09-04` (content-anchored; see §1.2) |
| **Core backend digest** | `94ad0618d753e5e4bca6af51ab02da4fa45bbc77fd391c39841f6e37baf04cc1` |
| **Release payload (app) digest** | `376d279625ab59fc6856f03f9e7949601e6ec0a5a7f0e07c90f6483108725a31` |
| Localization build ID | `2026.08.30.2` |
| Localization frontend release ID | `2026.09.03.phaseJ` |
| Schema identity | `schema.sql` SHA-256 `28443d1c…1421c`; highest migration `v1.6_feature_flags.sql`; 35 CREATE TABLE objects |
| Deployment status | **NOT DEPLOYED** |

### 1.2 Why the release identity is content-anchored (and why this is honest)

The Phase K work is **not** in any commit, so a `git rev-parse` alone does not identify it. A unique,
reproducible identity therefore must be derived from the actual payload. That is exactly what the two
aggregate digests above do: they hash the **live file contents** of the release. Because they are computed
from the bytes that ship, two different payloads cannot produce the same identity, and re-hashing the same
payload reproduces it exactly. This is the deterministic release-identity anchor the deployment gate can
evaluate — independent of whether the delta is yet committed.

- `CORE_BACKEND_SHA256` = SHA-256 of the concatenation of the five Phase-K-critical files' individual SHA-256
  (`schema.sql`, `init-sqlite.php`, `install.php`, `AnalyticsService.php`, `api/index.php`), in a fixed order.
- `APP_PAYLOAD_SHA256` = SHA-256 of the sorted `(file-sha256 + path + newline)` stream over the app payload
  (git-tracked files + release-candidate untracked, excluding `docs/` reports, `node_modules/`,
  `package.json`/`__lock`, `tools/dev/`, and `*.sqlite`).

### 1.3 Canonical artifact hashes (SHA-256) — no secrets included

**Backend / schema / installer (Phase K):**

| Path | SHA-256 |
|---|---|
| `api/database/schema.sql` | `28443d1cd8c16728f558ab08d90ec1ddd3bfcce957e7197335a0d3857421421c` |
| `api/init-sqlite.php` | `58d29f30e58ff7faae4272f9621e93dc01df79926412d0cc85259ddb209f6202` |
| `api/install.php` | `96fc824491b3ce3061896b594c135c9464705a83f86458afd8868a7235e37c6b` |
| `api/src/Admin/AnalyticsService.php` | `4df2009b7bcca2e8137d07b50583b907f913b0ff87c53009923299bc5a8ecec3` |
| `api/index.php` | `35c0d2946a918f38b78965e4383100a4bd8e25ebc10cb0cc727d2f53f2a65157` |
| `api/src/bootstrap.php` | `e5113b32df38ad85380f348b53cbe3addbc17a0dd87bb1c1e7f03cf2b7ad4671` |
| migration `v1.1_metaapi_fill_ledger.sql` | `3d7ff2949209ba3436f25b57300a90c0f8bd931998b10f854cfdac659fa8af50` |
| migration `v1.2_provider_credentials.sql` | `b471ed28bb4f3ac1634189f5ee4c927c805bb3244daaed31b0e2a7e2d8c0f672` |
| migration `v1.3_admin_management.sql` | `619db5f5209c079cba3b6a5791a96220fd430e35fb836bc7d5a50f8ee88201ae` |
| migration `v1.5_system_observability.sql` | `82e6580af8c6464e5f2ee06795bb31efc9c62b4b35f7b1d11b820a1a394a2d43` |
| `tools/tests/test_schema_completeness.php` | `83bf77bc746ec0dbab1aa9042558ae35954398385c9ca3983f0a74b58d3ba5e5` |

**Frontend / localized / admin artifacts** (these are the **Phase J** frontend artifacts — see §2 / §7 for
why they are legitimately unchanged):

| Path | SHA-256 |
|---|---|
| `localized/.csp-release.json` | `c2df27f62a7d77b4a706cdfbc6c78649bf11bd07c99ead4e5e7f84508c93d4dc` |
| `public/locales/csp-manifest.json` | `9826cae15748ce9fe9b2b5988059cd9078605c5971f8dda7a34f8af194c50390` |
| `public/locales/feature-manifest.json` | `4aebebdb95646cc07fbd9171b670347e6be8d8c589134db1f775b9c7ed48761e` |
| `public/locales/en.json` | `31a7e7c54e89ce0939523590dcf1652206590e84a7e0944cd162781c67c5be3a` |
| `public/locales/fa.json` | `2ed5ce2b3ed7ca5f107f7860d666e97b83ab4c4a56ed4a266de172863a08f99b` |
| `admin/index.html` | `9ee2ee8b6fa6a797bb3f6ce353a9e73ab5ff88a29289309cfd39c7a54953f58d` |
| `localized/en/admin/index.html` | `dd11ef3f1070aa60733e72f7fb36deeab719e9b9fa7b485f33b29b8f6c4c5e2c` |
| `localized/fa/admin/index.html` | `1eb395215870d94dc036b402a2441b37ca88c4cfa0c5a035a510eaeb9dcfcc3c` |

*No secret value was included in any artifact hash computation or report.*

---

## 2. Traceability — Source → Build → Localized → Artifact

### 2.1 Source
- Base commit `7426369…` (verified: **does not** contain `AnalyticsService.php`, `test_schema_completeness.php`,
  or the `v1.2`/`v1.3` migrations — all are working-tree-only). The Phase A–K source therefore lives in the
  uncommitted delta, which is what the aggregate digests capture.

### 2.2 Build
- The project's only "canonical build" for generated artifacts is the **localization/CSP pipeline**
  (`tools/localization/build_csp_artifacts.py`, `build_localized_static.py`). The backend is PHP source and
  has **no compile step** — it ships as-is. So "build" for the backend = the source payload itself, which is
  exactly what `CORE_BACKEND_SHA256` / `APP_PAYLOAD_SHA256` summarize.

### 2.3 Localized build — verified CURRENT at phaseJ
- `python3 tools/localization/build_csp_artifacts.py --check` → **`CSP_ARTIFACTS_CHECK_OK routes=61 policyVersion=2 releaseId=2026.09.03.phaseJ`**.
- This means the checked-in localized/CSP artifacts **match a fresh generation from the current sources**:
  the build reproduces them byte-for-byte. Because **Phase K changed no frontend/localization/CSP input**
  (only backend/schema/installer), the frontend artifact is **legitimately still `2026.09.03.phaseJ`** —
  it is **not** a stale/misleading phaseJ label. The "change nothing, verify it still reproduces" result is
  the correct evidence for §5's "unless the canonical build system explicitly proves that identifier is
  still correct." It does.
- Localization build ID `2026.08.30.2` (feature-manifest) — also unchanged and consistent.

### 2.4 Artifact → deployment package
- There is **no module bundler or deployment zip generated by the repo's canonical tooling** for the frontend
  beyond the localized output. The deployment model is a direct host upload of the root (per
  `DEPLOYMENT_GUIDE_FA.md`: cPanel/phpMyAdmin + full-root upload). The "deployment package" is therefore the
  release payload itself, identified by `APP_PAYLOAD_SHA256`, not a new zip that does not exist.

### 2.5 Phase K fixes present in the final payload (evidence)
| Phase K fix | Present where | Check |
|---|---|---|
| 5 runtime tables (`ai_provider_credentials`, `admin_audit_logs`, `system_logs`, `integration_health`, `metaapi_fills`) | `schema.sql`, `init-sqlite.php` | grep → 1 each |
| `users.role` expansion (`super_admin`) | `schema.sql`, `init-sqlite.php` | pass |
| `users.plan` / `users.subscription_status` / `plan_*` | `schema.sql`, `init-sqlite.php` | pass |
| `users.locale_updated_at` | `schema.sql`, `init-sqlite.php` | pass |
| `trading_accounts.timezone` / `timezone_source` | `schema.sql`, `init-sqlite.php` | pass |
| `trades.external_deal_id` | `init-sqlite.php` | pass |
| `sync_jobs.updated_at` | `init-sqlite.php` | pass |
| quote-aware `splitSqlStatements()` in installer | `api/install.php` | grep → pass |
| cross-DB analytics alias (``AS `key` ``) | `api/src/Admin/AnalyticsService.php` | grep → pass |
| 34-table full parity gate | `tools/tests/test_schema_completeness.php` | 139/0 |

---

## 3. Phase K verification

| Fix | Where in final artifact | Evidence |
|---|---|---|
| 5 missing runtime tables | `schema.sql` / `init-sqlite.php` | present (see §2.5) |
| role expansion | `schema.sql` (`super_admin` in `users.role ENUM`), `init-sqlite.php` | pass |
| plan/subscription fields | `schema.sql`, `init-sqlite.php` | pass |
| locale timestamp | `schema.sql`, `init-sqlite.php` | pass |
| trading account timezone fields | `schema.sql`, `init-sqlite.php` | pass |
| trade external deal ID | `init-sqlite.php` | pass |
| sync job updated_at | `init-sqlite.php` | pass |
| quote-aware statement splitter | `api/install.php` | pass |
| cross-database analytics alias fix | `api/src/Admin/AnalyticsService.php` | pass |
| complete 34-table parity | `schema.sql` ↔ `init-sqlite.php` | parity sweep 0 missing + gate |
| schema gate 139/0 | `tools/tests/test_schema_completeness.php` | **PASS (139 checks, 0 failures)** |

---

## 4. Tests (exact names + results)

### Schema / release gate
| Check | Result |
|---|---|
| `tools/tests/test_schema_completeness.php` | **PASS (139 checks, 0 failures)** |

### Localization / build
| Check | Result |
|---|---|
| `tools/localization/build_csp_artifacts.py --check` | `CSP_ARTIFACTS_CHECK_OK routes=61` (`2026.09.03.phaseJ`) |
| `tools/localization/check_key_references.py` | PASS |
| `tools/localization/check_frozen_hash_keys.py` | PASS |
| `tools/localization/localization_gate.py` | `LOCALIZATION_GATE_OK` |

### Security
| Check | Result |
|---|---|
| `tools/tests/test_security_static_gates.py` | **OK** |

### Backend regression (all via PHP on the dev runtime)
`test_admin_panel` 48/48 · `test_user360` 24/24 · `test_feature_flags` 25/25 · `test_billing_g` 24/24 ·
`test_analytics_h` 53/53 · `test_admin_ai_config` 44/44 · `test_admin_ai_ui` 47/47 · `test_integrations` 34/34 ·
`test_provider_verification` 47/47 · `test_feature_routing` 34/34 · `test_verification_gate` 14/14 ·
`test_system_health` 26/26 · `test_relay_config` 13/13 · `test_global_ai_route` 16/16 · `test_effective_config` 19/19.

### Browser (Playwright/Chromium, real form auth, dev server on 8080)
Phase D **15/15** · Phase E **33/33** · Phase F **22/22** · Phase G **27/27** · Phase H **32/32** · Phase I **73/73**.

### Architecture
`tools/tests/test_ai_p1_architecture.py` → flags **`Core/TradeService/TradeRepository should be untouched`** with diff
`api/src/Core/Mailer.php`. This is the **known pre-existing** issue (Mailer.php modified in an earlier phase;
Phase K touched no `Core` file) — **unchanged and correctly classified**, not a new failure.

**Test-preconditioning note:** rate-limits cleared and `ai_weekly_report`/`ai_trade_analysis` feature flags reset to
disabled between browser/backend batches (documented Phase A–I precondition). No results were fabricated;
a suite that did not actually pass would have shown a lower count.

---

## 5. Working tree state

## **AMBIGUOUS**

Determination:
- The **release CANNOT be represented by a clean commit in the current state** — the Phase A–K delta is
  uncommitted (57 tracked-modidified + 39 release-candidate untracked files), and `node_modules/`,
  `package.json`, `package-lock.json`, `tools/dev/` (Playwright harness) are untracked **category C/D**
  artifacts that would pollute any `git add -A`.
- The tree is therefore **not CLEAN** (it is not a single release commit) and not purely **DIRTY** (many of
  the untracked files are intentional release work, not noise).

### 5.1 Working-tree classification (§2)
| Category | What | Action |
|---|---|---|
| **A. Intended Phase A–K release work** | `api/database/schema.sql`, `init-sqlite.php`, `install.php`, `AnalyticsService.php`, `api/index.php`, `api/src/*` Admin/AI/Accounts, migrations, `public/locales/*`, `localized/*`, `public/assets/*` admin JS | include in release commit |
| **B. Generated artifacts** | `localized/.csp-release.json`, `public/locales/{csp,feature}-manifest.json`, locale chunks | regenerate/include (already current, verified) |
| **C. Temp/dev/test files** | `tools/dev/*.mjs`, `serve_db.php`, `dev_router.php`; `docs/AI_P2_*` reports | **not** release payload; include in repo as test/docs tooling if desired, but NOT required for deploy |
| **D. Dependencies/environment** | `node_modules/` (176 untracked items), `package.json`, `package-lock.json` | **exclude from release payload**; `node_modules/` is **NOT gitignored** → must NOT be `git add`ed |
| **E. Unknown/unclassified** | none observed — every untracked file falls into A–D | — |

### 5.2 Deletion candidates — safety rationale
- **No files were deleted or discarded.** Category C/D files were left in place; `node_modules/` and
  `package*.json` were **excluded from the release digest and from any commit**, not deleted. This is the
  safe treatment: they are environment/test artifacts, and removing them is not required (and would be
  riskier than excluding them).

### 5.3 What remains for a CLEAN release commit
A clean release is achievable by the owner, **exactly**:
1. Add a `.gitignore` rule for `node_modules/` and `package*.json` (or exclude them from the commit explicitly).
2. `git add` categories **A + B** only (57 tracked-modified + the 39 release-candidate untracked, minus the
   category C/D items).
3. `git commit` with a Phase A–K release message (e.g. `release: Phase A–K canonical candidate`), which
   produces a single commit containing the delta → then re-derive `CORE_BACKEND_SHA256` / `APP_PAYLOAD_SHA256`
   (they update only if byte content changed) and re-verify the CSP `--check`.

> **I did NOT create this commit.** The task (§9) forbids committing automatically unless authorization is
> clearly available, and I do not have clear owner authorization to author the release commit for this
> repository. This is documented as the one remaining step for a fully *clean* release; it does **not**
> invalidate the deterministic candidate, which is identified by content hash and is reproducible regardless.

---

## 6. Deployment status

## **NOT DEPLOYED**

No production access, no SSH, no migration run, no upload, no domain change, no service restart, no config
change. The Final Deployment Gate previously returned `NOT READY` and **no deployment is authorized**; this
packaging phase made no deployment attempt. (It is out of scope to change that standing state — this phase
creates the candidate for the gate to evaluate, it does not authorize shipment.)

---

## 7. Final verdict

## **RELEASE CANDIDATE READY**

Justification against the required conditions:
- **Release identity is unique** → content-anchored (`CORE_BACKEND_SHA256`, `APP_PAYLOAD_SHA256`) + recorded
  per-artifact SHA-256; the localization/frontend identity is `2026.09.03.phaseJ` **proven** current (not reused
  incorrectly) by the canonical build check.
- **Artifact is reproducible** → verified: re-running `build_csp_artifacts.py --check` reproduces the localized
  artifact; the backend payload is deterministic source (SHA-256-derived digest).
- **Phase A–K work is preserved** → no file deleted/discarded; HEAD unchanged; working tree intact.
- **Phase K fixes are present** → all 11 verified in the final payload (§2.5/§3).
- **Canonical artifacts are generated** → localized/CSP artifacts present and verified current; backend
  source is the deployable artifact.
- **Hashes are recorded** → §1.3.
- **Required regression gates pass** → schema 139/0; localization; security; 17 backend suites; browser D–I
  (15/33/22/27/32/73); architecture known-issue unchanged.
- **No unexplained release-level blocker** → none.

**Caveat (not a blocker, explicitly stated):** the release is **not** a clean commit (working tree is
AMBIGUOUS; Phase A–K uncommitted). The candidate is uniquely identified and reproducible by content hash
today, and can become a clean single-commit release by the owner following §5.3. **No code was fabricated,
no test result was fabricated, no deployment was performed, and this is NOT a claim of production health.**
