# AI_P2 — Release-Introduced Orphan Key Removal Report

**Date:** 2026-09-04
**HEAD:** `e0caa422769a822085b572bf4da7437c87d4989e` — **unchanged** (verified `git rev-parse HEAD`)
**Frozen release:** `40fb6d9ca7f52ec5e209bde55a3b832561553b8d` — **unchanged**
**State:** uncommitted working tree. No commit, no push, no deploy, no Staging dispatch.

---

## 1. The 9 unique keys (18 catalog entries)

| # | Key | en value | fa value |
|---|---|---|---|
| 1 | `admin.integrations.status` | `Status` | `وضعیت` |
| 2 | `admin.integrations.latency` | `Latency` | `تأخیر` |
| 3 | `admin.integrations.smtpKey` | `SMTP pass (write-only)` | `رمز SMTP (فقط نوشتنی)` |
| 4 | `admin.relay.saved` | `Relay configuration saved.` | `Relay با موفقیت ذخیره شد.` |
| 5 | `admin.relay.cleared` | `Relay configuration cleared.` | `Relay پاک شد.` |
| 6 | `admin.relay.error` | `Failed to save relay configuration.` | `ذخیره‌سازی Relay ناموفق بود.` |
| 7 | `admin.relay.removeToken` | `Clear token` | `توکن را پاک کنید` |
| 8 | `admin.user360.saved` | `Saved` | `ذخیره شد` |
| 9 | `admin.user360.noRights` | `You do not have a current role.` | `نقش فعلی ندارید.` |

Both locale entries existed for all 9 → **18 entries total**. None appear in any
feature chunk under `public/locales/chunks/{en,fa}/*.json` (verified
programmatically: zero hits).

---

## 2. Proof of no consumer (task 2)

Exhaustive repo-wide scan excluding `.git`, `node_modules`, `docs/`:

* **Literal occurrences:** each key appears **only** on its own line in
  `public/locales/en.json` and `public/locales/fa.json`. Zero occurrences in any
  `.js`, `.html`, `.php`, template, chunk, or manifest.
* **Dynamic prefix concatenation:** grep for
  `'admin.(relay|user360|integrations).' +` across the whole tree → **zero matches**.
  No Pattern-B style dynamic construction can produce any of these 9.
* **K-map leaves:** programmatic scan of every `'<stem>': 'admin.…'` object-literal
  pair in `admin/index.html`, `localized/*/admin/index.html`, and
  `public/assets/*.js` → **none of the 9 appears as a map value**.
* **Bare stems:** `smtpKey`, `removeToken`, `noRights` have **zero** occurrences
  anywhere in the tree outside the two catalogs.
* **Accessor aliasing ruled out:** `aT('status')` / `aT('latency')` resolve via
  `aK(stem) → window.VeloraAdminAnalyticsKeys[stem]` to `admin.analytics.status` /
  `admin.analytics.latency`, which remain present and referenced. The
  `admin.integrations.*` variants are a distinct, unused pair.
* **Relay flow:** `mutateRelay()` in `public/assets/velora-admin-ai.js` submits
  directly and renders `err.message` / `showRelayError()`; it invokes no
  `admin.relay.saved/cleared/error/removeToken` key. The relay K-map declares 13
  relay stems, none of which are these 4.
* **user360:** its K-map is extensive but declares neither `saved` nor `noRights`.

**Verdict: all 9 proven dead. No key required a STOP.**

---

## 3. Proof of introduction by `40fb6d9` (task 3)

```
git show 7426369:public/locales/en.json   → 0 / 9 present   (parent of the release)
git show 7426369:public/locales/fa.json   → 0 / 9 present
git show 40fb6d9:public/locales/{en,fa}.json → 9 / 9 present
git show e0caa42:public/locales/{en,fa}.json → 9 / 9 present
git log -S'"admin.integrations.smtpKey"' -- public/locales/*.json → 40fb6d9 (sole commit)
git log -S'"admin.relay.removeToken"'    -- public/locales/*.json → 40fb6d9
git log -S'"admin.user360.noRights"'     -- public/locales/*.json → 40fb6d9
```

All 9 were introduced by the frozen release `40fb6d9` and are **not** pre-existing
baseline debt — which is precisely why allowlisting them was rejected.

---

## 4. Removal (task 4/5)

Line-precise removal of exactly the 18 matching `"key":` lines. Verified by
before/after key-set diff:

```
en removed: the 9 keys      en added: []   en changed values: []
fa removed: the 9 keys      fa added: []   fa changed values: []
top-level document structure identical; FA/EN key-set parity: True
```

`git diff --stat public/locales/{en,fa}.json` → `9 deletions` each, **zero
insertions**. No unrelated translation, no reordering, no reformatting.

---

## 5. Artifact rebuild (task 6)

Baseline freshness *before* the removal was verified clean
(`ARTIFACT_FRESHNESS_OK`, exit 0), proving the subsequent drift was caused solely
by this change.

```
$ python tools/localization/build_localized_static.py \
    --release-id velora-phaseA-L2-2026.09.04 \
    --commit-sha 742636930ae26cb6e645e1b59137c62b79fa8943
LOCALIZED_BUILD_OK templates=29 html=61 feature_chunks=36 csp_routes=61
$ python tools/localization/build_localized_static.py --release-id … --check
ARTIFACT_FRESHNESS_OK   (exit 0)
```

The original `commitSha` provenance was preserved, so the regenerated artifacts
differ **only** in `sourceDigest`:

* `public/locales/csp-manifest.json` — `sourceDigest` `c6e1c9ef…` → `9ae3d415…`
* `localized/.csp-release.json` — `sourceDigest` `c6e1c9ef…` → `9ae3d415…`, and
  `cspManifestSha256` `8f6ae4ca…` → `e85fd4f4…`

`releaseId`, `releaseHtmlSha256`, `routeCount` (61), `policyVersion`, and
`localizationVersion` are **unchanged**. No `localized/**/*.html` file changed
(the 9 keys were never rendered into any page — independent corroboration that
they were dead). No feature chunk changed.

---

## 6. Gate results (task 7/8)

| Gate | Before removal | After removal |
|---|---|---|
| **Orphan catalog gate** (`--fail`) | **exit 1** — blocking=18 | **exit 0** — blocking=**0**, stale=0 |
| `validate_localization` | 0 | **0** (routes=29 canonical=29 localized=61 locales=2 issues=0) |
| `localization_gate` (parity + hardcoded-UI) | 0 | **0** |
| `check_frozen_hash_keys` | 0 | **0** (879 hashed keys, freeze intact) |
| `check_key_references` | 0 | **0** |
| `check_ai_locale_contract` | 0 | **0** |
| `build_csp_artifacts --check` | 0 | **0** |
| `build_localized_static --check` (freshness/parity) | 0 | **0** ARTIFACT_FRESHNESS_OK |
| `report_catalog_anomalies --fail` | **1 (pre-existing)** | **1 (pre-existing)** — unchanged failure mode; the only delta is `fa.duplicates` 176 → **175**, a strict improvement |

Final orphan gate output:

```
## en — total=194 allowlisted=194 blocking=0 orphan key(s)
## fa — total=194 allowlisted=194 blocking=0 orphan key(s)
Summary: total=388 allowlisted=388 blocking=0 stale_allowlist=0
exit 0
```

**Final orphan blocking count = 0.** The 388 remaining are the pre-existing,
already-approved baseline allowlist; no allowlist entry was added or removed, and
none went stale.

### Test suites

`tools.localization.test_report_orphan_catalog_keys` — **Ran 18, OK**, including
the 4 tests added in the previous phase (7 dynamic keys NOT ORPHAN; lookalike key
still blocks; brand-new unused key still blocks; variable concatenation not
expanded).

All other localization modules: identical results before and after —
`test_dashboard_logout_contract` (4 OK), `test_localization_gate` (4 OK),
`test_migrate_static` (9 OK), `test_report_catalog_anomalies` (12 OK),
`test_route_contract` (11 OK), `test_tools_align` (5 OK),
`test_validate_localization` (10 OK). Three modules fail **identically to the
pre-change baseline** for environment reasons unrelated to this work:
`test_brand_policy` (sys.path import), `test_build_csp_artifacts` (3 errors —
missing `.github/workflows/*.yml` in this checkout), `test_route_e2e_contract`
(1 pre-existing failure on `trades/new/index.html`).

### Live regression proof — a brand-new orphan still blocks

Injected `admin.regression.brandNewOrphanProbe` into both real catalogs and ran
the real gate with the real allowlist:

```
## en — total=195 allowlisted=194 blocking=1 orphan key(s)
   admin.regression.brandNewOrphanProbe
## fa — total=195 allowlisted=194 blocking=1 orphan key(s)
   admin.regression.brandNewOrphanProbe
Summary: total=390 allowlisted=388 blocking=2 stale_allowlist=0
BLOCKING: 2 orphan key(s) are not allowlisted.        exit 1
```

The probe was then fully reverted; the gate returned to `blocking=0`, exit 0, and
`git diff` on the catalogs shows only the intended 9 deletions per locale. The
validator was **not weakened**: the only behavioural change remains the narrow
Pattern-B expansion from the prior phase.

---

## 7. Secrets / unrelated-file check (task 9)

* No credential, token, or key **value** was added, printed, or copied. The
  removed `admin.integrations.smtpKey` / `admin.relay.removeToken` entries are
  **UI label strings only** ("SMTP pass (write-only)", "Clear token") — never
  secret material.
* Diff scanned for `api_key|secret|password|token =|BEGIN … PRIVATE`: the only
  hits are prose inside two **pre-existing** uncommitted doc edits
  (`AI_P2_FINAL_DEPLOYMENT_GATE.md`, `AI_P2_RELEASE_IDENTITY.md`) from an earlier
  phase — not part of this change.
* `git diff --check`: one trailing-whitespace hit, in the pre-existing
  `AI_P2_FINAL_DEPLOYMENT_GATE.md` edit; nothing in any file I touched.

---

## 8. Exact files changed by this task (task 10)

```
 M public/locales/en.json                                   (-9)   the 9 dead keys
 M public/locales/fa.json                                   (-9)   the 9 dead keys
 M public/locales/csp-manifest.json                         (±1)   regenerated sourceDigest
 M localized/.csp-release.json                              (±1)   regenerated sourceDigest + cspManifestSha256
 ?? docs/AI_P2_ORPHAN_KEY_REMOVAL_REPORT.md                        this report
```

Carried over from the previous (already-reported) phase, still uncommitted:

```
 M tools/localization/report_orphan_catalog_keys.py         (+41)  Pattern-B dynamic-key fix
 M tools/localization/test_report_orphan_catalog_keys.py    (+74)  4 new tests
 ?? docs/AI_P2_ORPHAN_VALIDATOR_REMEDIATION_REPORT.md
```

Pre-existing, untouched by me: `docs/AI_P2_FINAL_DEPLOYMENT_GATE.md`,
`docs/AI_P2_RELEASE_IDENTITY.md`, the other `??` docs, `package*.json`,
`tools/dev/`.

Explicitly **not** modified: `tools/localization/catalog-quality-allowlist.json`
(no key allowlisted), `admin/index.html`, `public/assets/**`,
`localized/**/*.html`, `public/locales/chunks/**`, any workflow, and commits
`40fb6d9` / `e0caa42`.

---

## 9. Verdict

**ORPHAN REMEDIATION COMPLETE — GATE PASS**

7 dynamic false positives fixed in the validator; 9 release-introduced dead keys
removed from both catalogs; artifacts rebuilt and fresh; orphan blocking count
**0**; a brand-new orphan still blocks (exit 1) — proven live. Nothing committed,
pushed, or deployed.
