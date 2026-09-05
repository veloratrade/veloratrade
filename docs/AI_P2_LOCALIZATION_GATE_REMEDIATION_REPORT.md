# VELORA — LOCALIZATION QUALITY GATE — TARGETED FALSE-POSITIVE FIX REPORT

**Date:** 2026-09-04 · **Mode:** Controlled remediation of the localization quality gate so it stays accurate
(not looser). Uncommitted working-tree change on top of the frozen release — **no commit, no push, no
deployment**.

---

## 1. Problem

Staging deployment of the frozen release `40fb6d9ca7f52ec5e209bde55a3b832561553b8d` was fail-closed by the
`gate-static` job in `.github/workflows/quality-gate.yml`, which runs:

```
python tools/localization/report_catalog_anomalies.py \
  --allowlist tools/localization/catalog-quality-allowlist.json \
  --fail --fail-group en.empty --fail-group fa.en.identical
```

Before the fix: **`fa.en.identical` blocking = 6.**

## 2. Root cause — six false positives (verified by forensic audit)

The forensic audit confirmed the 6 blocking `fa.en.identical` items are **not** localization defects:

| Key | FA == EN | Classification |
|---|---|---|
| `admin.integrations.metaapi` | `MetaAPI` | legitimate technical term (brand) |
| `admin.system.metaapi` | `MetaAPI` | legitimate technical term (brand) |
| `admin.system.api` | `API` | legitimate technical term (protocol) |
| `admin.system.redis` | `Redis` | legitimate technical term (product) |
| `admin.user360.ip` | `IP` | legitimate technical term (protocol) |
| `admin.relay.maskedToken` | `••••••••` | **masked-secret display placeholder — redaction sentinel, not text** |

Five are approved technical terms that correctly remain untranslated (Latin) in the Persian UI — the project
already allowlists 21 such terms (e.g. `common.metaapi`, `common.stripe`, `common.velora`). The sixth is the
masked-token redaction sentinel, which is neither Persian nor English and must never be treated as an
untranslated-English localization.

## 3. Changes (exactly what was changed)

**`tools/localization/report_catalog_anomalies.py`** — added a narrowly-scoped placeholder rule:

- New `MASKED_SECRET_BULLET = '\u2022'` constant and `_is_masked_secret_placeholder(value)` helper.
  - Matches only a **non-empty string composed entirely of U+2022 bullet characters** — nothing else.
  - Cannot match arbitrary punctuation, Latin words, real English, or punctuation runs (they contain
    non-bullet chars), so no broad exemption.
  - Applied **only** to the `fa.en.identical` group: a masked-secret sentinel is excluded from the
    untranslated-English check. `fa.no-persian` is deliberately left unchanged (it is not a blocking group,
    and the sentinel genuinely contains no Persian script — it remains informational there).

**`tools/localization/catalog-quality-allowlist.json`** — added the **5 approved technical-term keys** to the
existing `fa.en.identical` allowlist (exact-key entries, matching the established convention for
`common.metaapi`, `common.stripe`, etc.):
`admin.integrations.metaapi`, `admin.system.metaapi`, `admin.system.api`, `admin.system.redis`,
`admin.user360.ip`. **`admin.relay.maskedToken` was NOT allowlisted** (it is handled by the rule; allowlisting
it would make it a "stale" allowlist entry and would itself fail-closed under `--fail`).

**`tools/localization/test_report_catalog_anomalies.py`** — added 8 unit tests (see §Tests).

**Not changed:** `fa.json`, `en.json`, all catalogs, `localization_gate.py`, `check_hardcoded_ui.py`, other
validators, workflows, and the `--fail-group` set. No catalog rewrite, no mass formatting, no dependency
change, no unrelated edits.

## 4. Security impact (masked-secret handling is safe)

- No secret is read, printed, logged, or returned. The change is **localization-tooling only**; it never
  touches `SecureCredentialStore`, API responses, or redaction logic.
- The bullet sentinel is a **display placeholder** that already exists in the catalogs (as `••••••••`,
  codepoints `[0x2022]*8`); the change merely prevents that placeholder from being misread as an
  untranslated-English value.
- The rule is keyed to the exact representation (all bullets only). It cannot match a real token or secret
  value, which would contain non-bullet characters. No credential exposure path is added.

## 5. Quality-gate preservation (genuine leakage still blocks)

The `fa.en.identical` group is retained as blocking; only exact technical terms (allowlist) and the exact
masked sentinel (rule) are tolerated. Genuine untranslated-English values still fail-closed:
`Save`, `Dashboard`, `Settings`, and any other real English FA value where FA==EN → exit 1 (proven, §Tests).

## 6. Tests (before / after)

**Exact staging command:**

| | before | after |
|---|---|---|
| `fa.en.identical` blocking | **6** | **0** |
| exit code | 1 | **0** |

**New unit tests added to `test_report_catalog_anomalies.py` (all pass, Ran 12 OK):**

| Test | Expected | Result |
|---|---|---|
| masked `••••••••` placeholder does NOT block | 0 | PASS |
| protected `Redis` **allowlisted** passes | 0 | PASS |
| protected `Redis` **without** allowlist still blocks | 1 | PASS |
| `Save` leakage still blocks | 1 | PASS |
| `Dashboard` leakage still blocks | 1 | PASS |
| `Settings` leakage still blocks | 1 | PASS |
| FA=`ذخیره` / EN=`Save` NOT identical → passes | 0 | PASS |
| arbitrary `----------` not exempted → blocks | 1 | PASS |

## 7. Regression results (full gate run, exit codes)

Localization sphere (exits captured):
- `report_catalog_anomalies` validator unit tests: **12 tests → exit 0** (OK).
- `validate_localization.py`: **LOCALIZATION_VALIDATION_OK** (routes=29 canonical=29 localized=61
  locales=2 issues=0) → exit 0.
- `check_hardcoded_ui.py`: exit 0. `check_frozen_hash_keys.py`: exit 0. `check_key_references.py` (+
  `--require-runtime`): exit 0. `check_ai_locale_contract.py`: exit 0. `localization_gate.py`: exit 0.
- `test_localization_gate`: exit 0 (OK — note it logs a non-fatal DRIFT/STALE note re `velora-i18n.js`
  allowlist count 153 vs tree 154; this is a **pre-existing, non-blocking informational** observation, not
  caused by this change).
- `test_validate_localization`: exit 0 (10 OK). `test_report_orphan_catalog_keys`: exit 0 (14 OK).
  `test_migrate_static`: exit 0 (9 OK). `test_route_contract`: exit 0 (11 OK).
- Security: `test_security_static_gates`: exit 0 (8 OK).

**Pre-existing / environmental failures (unrelated to this change, correctly not suppressed):**
- `report_orphan_catalog_keys.py --allowlist ... --fail` → exit 1, `blocking=32`. **PRE-EXISTING** — 32
  orphan catalog keys; independent of the `fa.en.identical` fix (I did not touch these keys or the orphan
  logic). This is a separate quality-gate item to be triaged by the owner.
- `test_build_csp_artifacts` → exit 1 (3 errors): `FileNotFoundError:
  .github/workflows/deploy-staging.yml` — **ENVIRONMENTAL** (`.github` is filtered out of this sandbox
  checkout; the CSP-guard workflow file is not present locally).
- `test_route_e2e_contract` → exit 1 (1 failure): undeclared route `trades/new/index.html` in
  `route_contract` — **PRE-EXISTING** route-coverage gap, unrelated to localization.
- `test_brand_policy` / `test_ai_locale_contract` → exit 1 (`_FailedTest` loader not found):
  **ENVIRONMENTAL** (module not importable in this sandbox), not a real assertion failure.
- `test_pr01_freeze`, `test_http_localization`, `test_http_routes` → exit 5 (0 tests): **ENVIRONMENTAL**
  (these are `__main__`-guarded scripts / require `.github` assets), not defects.

None of the above is introduced or affected by this targeted change. The change touches only the
`fa.en.identical` handling; `fa.duplicates` and `en.multi-fa` logic is untouched and their counts are
**unchanged** (176 and 33, both informational/non-blocking) as required.

## 8. Diff scope

Only these files changed (uncommitted, in working tree):
- `tools/localization/report_catalog_anomalies.py`
- `tools/localization/catalog-quality-allowlist.json`
- `tools/localization/test_report_catalog_anomalies.py`

(`docs/AI_P2_*` files also show as modified/untracked from prior phases' evidence; not part of this fix.)
`git diff --check`: **clean** for this change (only a pre-existing trailing-whitespace in an unrelated
prior-phase doc). No accidental deletions, no catalog rewrites, no dependency changes.

## 9. Release rule

The frozen release `40fb6d9ca7f52ec5e209bde55a3b832561553b8d` was **not** modified, amended, rewritten, or
force-pushed. No push, no dispatch, no deployment. This remediation is an uncommitted candidate for a
**future** release.

---

## Final response (§16)

```
Frozen release:    40fb6d9ca7f52ec5e209bde55a3b832561553b8d

Before:  fa.en.identical blocking = 6
After:   fa.en.identical blocking = 0

Technical terms:                 PASS
Masked placeholder:              PASS
Genuine English leakage negative test:  PASS
fa.duplicates behavior:          UNCHANGED   (176, informational)
en.multi-fa behavior:            UNCHANGED   (33, informational)
Localization gates:              PASS  (exact staging command exit 0)
Security tests:                  PASS  (test_security_static_gates 8 OK, exit 0)

Files changed:  tools/localization/report_catalog_anomalies.py
                tools/localization/catalog-quality-allowlist.json
                tools/localization/test_report_catalog_anomalies.py
Commit:         NONE
Push:           NO
Deployment:     NO

Separate pre-existing/environmental gate items reported (NOT from this fix),
to be triaged by owner: report_orphan_catalog_keys blocking=32 (RELEASE-side,
pre-existing); test_build_csp_artifacts env (missing .github); test_route_e2e_contract
pre-existing route gap; __main__-guarded env-only scripts.
```

## Final verdict

> **`TARGETED LOCALIZATION GATE FIX COMPLETE`**
