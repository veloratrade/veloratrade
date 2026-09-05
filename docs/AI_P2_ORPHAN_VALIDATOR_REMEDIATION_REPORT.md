# AI_P2 — Orphan Validator Targeted Remediation Report

**Date:** 2026-09-04
**HEAD:** `e0caa42` (unchanged — not amended, not rewritten)
**Frozen release:** `40fb6d9ca7f52ec5e209bde55a3b832561553b8d` (untouched)
**Working tree:** uncommitted (no commit, no push, no deploy performed)

---

## 1. Problem

The orphan Quality Gate blocked the release:

```
$ python tools/localization/report_orphan_catalog_keys.py \
    --allowlist tools/localization/catalog-quality-allowlist.json --fail
Summary: total=420 allowlisted=388 blocking=32 stale_allowlist=0
BLOCKING: 32 orphan key(s) are not allowlisted.
exit 1
```

32 blocking entries = **16 unique keys × 2 locales (en, fa)**.

Forensic triage split the 16 keys into two **disjoint** classes:

| Class | Count | Nature |
|---|---|---|
| A | 7 | `admin.integrations.result.*` — **validator false positives** (dynamic key construction the static scanner cannot resolve) |
| B | 9 | `admin.integrations.{latency,status,smtpKey}`, `admin.relay.{saved,cleared,error,removeToken}`, `admin.user360.{saved,noRights}` — **genuinely unreferenced catalog keys** |

---

## 2. Class A — 7 dynamic-key false positives (validator fixed)

### Root cause

`public/assets/velora-admin-integrations.js:186–194`:

```js
var key = 'admin.integrations.result.' + {
  SUCCESS: 'success', AUTH_FAILED: 'authFailed', TIMEOUT: 'timeout',
  NETWORK_ERROR: 'networkError', SERVICE_UNAVAILABLE: 'serviceUnavailable',
  NOT_CONFIGURED: 'notConfigured', INVALID: 'invalid'
}[status];
return key ? t(key) : status;
```

The key is assembled at runtime from a literal prefix plus an inline object-literal
lookup. Neither `CALL_PATTERN`, `QUOTED_KEY_PATTERN` nor `I18N_KEY_ATTR_PATTERN`
can see the composed key, so all 7 produced keys were reported as orphans even
though they **are** referenced. This is a pure static-analysis blind spot — a
false negative in reference collection, not catalog debt.

### Change (narrow, additive)

`tools/localization/report_orphan_catalog_keys.py`:

* New `CONCAT_MAP_PATTERN` + `CONCAT_MAP_VALUE_PATTERN` and helper
  `_expand_concat_map_keys(text)`.
* Resolves **only** the deterministic form
  `'<literal dotted prefix>.' + { … literal string values … }[expr]`, expanding
  to exactly `{prefix + value}` for each literal value found in that single
  brace-balanced object literal (`[^{}]*` — no nesting, no recursion).
* Hooked into `_collect_references()`'s `scan_text`, gated by the **same**
  `known_prefixes` guard used by `QUOTED_KEY_PATTERN`.
* All three pre-existing patterns and the `Config::get` exclusion are **byte-for-byte
  unchanged**. Nothing is removed; no allowlist entry added for these 7.

### Why detection is not weakened

* Only literal prefixes and literal map values are expanded. Variable
  concatenation (`'common.' + suffix`) is explicitly **not** resolved (regression
  test included).
* A key that resembles the family but is absent from the inline map — e.g.
  `admin.integrations.result.rateLimited` — is still reported and still exits 1
  (regression test included).
* The expansion is prefix-gated, so it cannot invent references outside known
  catalog namespaces.

---

## 3. Class B — 9 keys: evidence and disposition

Operator instruction was to allowlist **only** if the evidence proves they are
**pre-existing** dead keys, and otherwise to stop and report. The evidence does
**not** prove that.

### Proof of provenance (decisive)

```
$ git show 7426369:public/locales/en.json  # parent of the frozen release
  → 0 / 9 keys present
$ git show 40fb6d9:public/locales/{en,fa}.json
  → 9 / 9 keys present (both locales)
$ git show e0caa42:public/locales/{en,fa}.json
  → 9 / 9 keys present (both locales)
$ git log -S'"admin.integrations.smtpKey"' -- public/locales/*.json
  → 40fb6d9  (sole introducing commit; same for removeToken, noRights)
```

**Conclusion: all 9 keys were introduced by the frozen release `40fb6d9` itself.**
They are *release-introduced*, **not** pre-existing baseline debt. Condition 1
("prove all 9 existed in the parent release") is **FALSIFIED**; condition 2
("prove they are not release-introduced") is **FALSIFIED**.

### Runtime-reference evidence (no credible consumer)

* `aT('status')` / `aT('latency')` in `admin/index.html:2431` resolve through
  `aK(stem) → window.VeloraAdminAnalyticsKeys[stem]` to
  `admin.analytics.status` / `admin.analytics.latency` — **not** the
  `admin.integrations.*` keys.
* `window.VeloraAdminIntegrationsKeys` declares `saved`/`cleared` but **not**
  `smtpKey`, `status`, `latency`.
* The relay K-map declares `relay.{title,subtitle,url,token,status,configured,
  notConfigured,urlConfigured,tokenConfigured,host,save,clear,maskedToken}` —
  **not** `saved/cleared/error/removeToken`. `mutateRelay()` in
  `public/assets/velora-admin-ai.js` renders `err.message` / `showRelayError()`
  directly, invoking no catalog key.
* The user360 K-map does not declare `saved` or `noRights`.
* `smtpKey`, `removeToken`, `noRights` have **zero** occurrences anywhere as raw
  stems, map leaves, accessor arguments, concatenation fragments, PHP literals,
  or in the built `localized/{en,fa}/admin/**` output.
* None of the 9 match the Pattern-B dynamic form, so the validator fix
  legitimately does not clear them.

### Disposition — NOT allowlisted

Per the operator's explicit fallback ("If the evidence does not prove the 9 are
safely pre-existing, stop and report them instead of allowlisting them"), **no
allowlist entry was added and `catalog-quality-allowlist.json` was not modified.**
`public/locales/{fa,en}.json` were not modified either. These 9 are
release-introduced dead catalog keys requiring a separate, authorized
catalog-hygiene decision (remove from catalogs, or wire up the intended UI).

---

## 4. Tests

Added to `tools/localization/test_report_orphan_catalog_keys.py` (4 new tests,
18 total in the module):

| Test | Purpose | Result |
|---|---|---|
| `test_concat_map_dynamic_keys_are_not_orphans` | **Positive** — all 7 `admin.integrations.result.*` reported NOT ORPHAN | PASS |
| `test_similar_key_absent_from_concat_map_still_blocks` | **Negative** — lookalike key not in the map is still an orphan and `--fail` exits 1 | PASS |
| `test_brand_new_unused_key_still_blocks_with_real_allowlist` | **Negative** — a brand-new unused key blocks the gate (exit 1) even with an allowlist present | PASS |
| `test_variable_concatenation_is_not_expanded` | **Negative** — arbitrary `'prefix.' + var` is NOT resolved; detection strength unchanged | PASS |

### Test suite run (`tools/localization/test_*.py`, per-module)

```
test_dashboard_logout_contract .......... Ran  4  OK
test_localization_gate .................. Ran  4  OK
test_migrate_static ..................... Ran  9  OK
test_report_catalog_anomalies ........... Ran 12  OK
test_report_orphan_catalog_keys ......... Ran 18  OK   <-- target module
test_route_contract ..................... Ran 11  OK
test_tools_align ........................ Ran  5  OK
test_validate_localization .............. Ran 10  OK
test_brand_policy ....................... ERROR (pre-existing: sys.path import of `brand_policy`)
test_build_csp_artifacts ................ 33 run, 3 ERROR (pre-existing: missing .github/workflows/*.yml in this checkout)
test_route_e2e_contract ................. 13 run, 1 FAIL (pre-existing: critical route 'trades/new/index.html')
test_http_localization / test_http_routes / test_pr01_freeze ... 0 tests collected
```

The 3 failing modules fail **identically before and after** this change; all are
environment/checkout artifacts unrelated to orphan detection.

---

## 5. Gate result — before / after

| | Command exit | total | allowlisted | blocking | stale |
|---|---|---|---|---|---|
| **Before** | `1` | 420 | 388 | **32** | 0 |
| **After** | `1` | 406 | 388 | **18** | 0 |

Exact final orphan count: **18 blocking entries = 9 unique keys × 2 locales** —
precisely the Class B set. The 14 Class A entries (7 keys × 2 locales) are
resolved by the validator fix. **The orphan Quality Gate still FAILS (exit 1).**

---

## 6. Exact files changed

```
 M tools/localization/report_orphan_catalog_keys.py       (+41)
 M tools/localization/test_report_orphan_catalog_keys.py  (+74)
 ?? docs/AI_P2_ORPHAN_VALIDATOR_REMEDIATION_REPORT.md     (new, this file)
```

`git diff --check`: the only trailing-whitespace hit is in
`docs/AI_P2_FINAL_DEPLOYMENT_GATE.md`, a **pre-existing** uncommitted doc edit
from an earlier phase, not part of this change.

Deliberately **not** changed: `public/locales/{fa,en}.json`,
`tools/localization/catalog-quality-allowlist.json`, `admin/index.html`,
`localized/**`, `public/assets/**`, any workflow, and commits `40fb6d9` /
`e0caa42`. No commit, no push, no deploy, no Staging dispatch.

---

## 7. Verdict

**ORPHAN VALIDATOR REMEDIATION BLOCKED — 9 GENUINE ORPHANS REMAIN**
