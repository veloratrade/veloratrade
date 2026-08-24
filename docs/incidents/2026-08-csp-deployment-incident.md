# Incident Report: CSP/Critical-File Deployment Integrity Risk (August 2026)

**Status:** Resolved
**Severity:** High (potential silent security-artifact staleness in production)
**Affected systems:** Deployment pipeline (`deploy-staging.yml`, `deploy.yml`), CSP/critical-file artifacts
**Resolution:** Hybrid Critical Files Deployment Architecture (PR #40 staging, PR #41 production)
**Final production release:** `2026.08.24.1` (commit `5ce76be`, tag `release-2026.08.24.1`)

---

## 1. Incident Summary

The deployment pipeline for both Staging and Production used `lftp mirror --continue --ignore-time` to synchronize the built site to the live FTP host. This mirror strategy determines whether to skip or transfer a file using **file size** as its primary heuristic. Investigation during deployment-verification work found that this heuristic is **not content-safe**: a critical security file (`localized/.csp-release.json`, the release-provenance and CSP-policy manifest) could be identical in **byte size** across two different releases while having **materially different content** (different `commitSha`, `releaseId`, `cspManifestSha256`, `routeCount`, `policyVersion`, etc.). In that situation, `mirror` would treat the file as "already up to date" and **skip re-uploading it**, even though the file on the live server was actually the wrong release's provenance data.

Because `localized/.csp-release.json` and `public/locales/csp-manifest.json` are the artifacts that downstream CSP policy, health checks, and provenance verification all trust, a silent skip here could let a deployment **report success** while the live host continued serving a **stale CSP/security manifest** — a gap between "deploy said success" and "security-critical artifact is actually current."

This was caught and fixed **before** it caused a real customer-facing incident: it surfaced during hardening/verification work on the staging deploy pipeline (PR #38), was fully architected as a general fix for staging (PR #40), and was then deliberately ported to Production with equivalent safeguards (PR #41) rather than being left as a staging-only fix.

## 2. Timeline

| Date/Time (UTC) | Event |
|---|---|
| 2026-08-23 22:57 | PR-associated fix commit `922b067` — "force full lftp transfer and verify staging health post-deploy" — initial mitigation attempt for staging using `--transfer-all` (forces every file to re-upload every deploy, bypassing the skip heuristic entirely). |
| 2026-08-23 (PR #38 merged, `e9c4987`) | Staging: `--transfer-all` + provenance/health-check verification merged as first fix. Correct but costly — every deploy re-uploads the entire ~30MB, ~717-file staging package regardless of whether anything changed. |
| 2026-08-23 23:33 | Commit `2c33a93` — "remove `--continue` from staging mirror (conflicts with `--transfer-all`)" — cleanup of an internal flag conflict discovered while validating the PR #38 fix. |
| 2026-08-24 (PR #39 merged, `fe4e496`) | Staging: conflict-fix merged. |
| 2026-08-24 00:04 | Commit `05284cd` — "hybrid critical-files deployment architecture for staging" — replaces blanket `--transfer-all` with the targeted Hybrid architecture: exclude only the known critical files from the optimized mirror, force-upload just those via `put`, verify via SHA256 + routeCount/policyVersion after upload. Restores per-deploy efficiency for the ~715 normal files while closing the skip-based gap for the 2 critical files. |
| 2026-08-24 (PR #40 merged, `c135d75`) | Staging: Hybrid Critical Files architecture merged and proven live on staging. |
| 2026-08-24 | Design review: Production Hybrid Deployment Architecture designed as a deliberate port of the proven staging pattern to Production, respecting Production's distinct hardcoded destination (`public_html/`, vs staging's nested `public_html/staging.veloratrade.ir/`) and its stricter non-negotiable rule (C-2: Production's mirror destination must never be parameterized). |
| 2026-08-24 (PR #41 opened) | Production implementation opened as a separate, reviewable PR: `deploy.yml` split its single transfer step into (a) normal-file mirror excluding critical files, (b) forced `put` for critical files with independent retry, and extended post-upload provenance verification to check both critical files plus their internal routeCount/policyVersion consistency. `healthcheck-suite.yml` also received an additional HTTP-reachable routeCount/policyVersion sanity check. |
| 2026-08-24 | Full PR review: confirmed CI green, no application code changes, no secrets changes, no weakened security gates, no staging/production path mixing. |
| 2026-08-24 | PR #41 merged into `main` at merge commit `5ce76be`. |
| 2026-08-24 (dry-run) | Workflow run **#32678355672** (`dry_run=true`) executed against the **real production FTP host** — zero writes performed, backup step still ran and captured 658 files, dry-run listing correctly identified normal-file transfer candidates and reported critical-file hashes/sizes without uploading them. Run concluded `success`. |
| 2026-08-24 (real deploy) | Workflow run **#32723309835** (`dry_run=false`, `confirm_production_deploy=CONFIRM-PRODUCTION-DEPLOY`) executed. All prerequisite gates (confirm-phrase, staging health, quality-gate, CSP guard) passed. RB-6 backup captured 658 files. Normal-file mirror transferred successfully (1st attempt). Both critical files force-uploaded successfully (1st attempt each). Post-upload provenance verification passed all checks (SHA256 match vs CI artifact for both files, internal `cspManifestSha256` cross-check, `routeCount`/`policyVersion` cross-check). Post-deploy healthcheck suite passed (12 read-only probes + manifest hash + routeCount/policyVersion). Run concluded `success`. |
| 2026-08-24 | Post-deployment verification: release metadata (`releaseId=2026.08.24.1`, `commitSha=39e7f9115d50...`, `routeCount=61`, `policyVersion=2`) and critical-file SHA256 hashes independently re-confirmed against the live production host. Annotated tag `release-2026.08.24.1` created on commit `5ce76be` and pushed. |

## 3. Root Cause

**FTP mirror optimization could skip same-size/different-content critical files.**

`lftp mirror --continue --ignore-time` uses file size (not content hash) as its change-detection heuristic when deciding whether to re-transfer a file. `localized/.csp-release.json` is a small, fixed-shape JSON document; across consecutive releases it is common for its serialized byte length to stay identical (e.g., both the outgoing and incoming release's file were exactly 386 bytes) even though every field inside — `releaseId`, `commitSha`, `sourceDigest`, `releaseHtmlSha256`, `cspManifestSha256`, `routeCount`, `policyVersion` — differs. The mirror logic has no way to distinguish "unchanged" from "changed but same size," so it can silently skip the transfer, leaving the **previous** release's provenance/CSP artifact live on the server after a deploy that otherwise appeared to succeed.

This is a structural property of size-based mirror sync, not a one-off bug — it affects any small, fixed-format, frequently-updated file, which is exactly the shape of the two critical files in this system.

## 4. Impact

**Potential stale CSP/security artifacts after successful deployment.**

- If triggered, a deploy would report overall success while the live server continued serving an outdated `localized/.csp-release.json` and/or `public/locales/csp-manifest.json`.
- Downstream consequences of a stale critical file: CSP policy enforcement referencing an old `policyVersion`/route set, provenance checks (both automated and manual) trusting incorrect `commitSha`/`releaseId` metadata, and post-deploy health checks that only compared the manifest against the repo (not also checking internal `routeCount`/`policyVersion` consistency) potentially not catching a partial mismatch.
- **No evidence this ever manifested as a real production incident** — it was identified and closed during proactive deployment-pipeline hardening work, not from an observed production failure. It is documented here as a caught-and-fixed structural risk, not a confirmed live outage.

## 5. Detection

**Deployment verification investigation.**

The gap was found while reviewing and hardening the staging deployment workflow's file-transfer logic (leading to the PR #38 fix attempt), then more precisely characterized and fixed with a scoped test (`same-size/different-content` scenario, see §7) during design and implementation of the general Hybrid Critical Files architecture. It was not detected via a customer report, alert, or automated monitoring — it was found through direct engineering analysis of the `lftp mirror` skip heuristic and reproduced deterministically in a local sandbox before any workflow change was written.

## 6. Resolution

**Hybrid Deployment Architecture** — implemented identically in principle for staging (PR #40) and production (PR #41), respecting each environment's distinct hardcoded destination:

- **Normal files via mirror**: the bulk of the built site (~715 files) continues to use the efficient `lftp mirror --continue --ignore-time` sync, preserving fast, low-cost, resumable deploys.
- **Critical files via forced upload**: `localized/.csp-release.json` and `public/locales/csp-manifest.json` (defined once in the shared `.github/deploy/critical-deploy-files.sh`, consumed by both workflows) are excluded from the mirror via `--exclude` and instead force-uploaded with an explicit `put`, which has no skip logic and always transfers full content — closing the root-cause gap entirely for these two files.
- **SHA256 verification**: after upload, both critical files are downloaded back from the live server and their SHA256 is compared against the CI-built, provenance-verified artifact. Any mismatch fails the deployment job immediately.
- **routeCount/policyVersion consistency checks**: beyond matching the CI artifact, the two critical files are cross-checked against **each other** on the live server — the manifest's actual SHA256 must match the `cspManifestSha256` field recorded inside `.csp-release.json`, and both files' `routeCount`/`policyVersion` values must agree. This catches a class of failure that simple "does file X match its own source" checks cannot: two files that are each individually correct in isolation but mutually inconsistent.

## 7. Tests Added

The following scenarios were required and verified (via a local FTP sandbox reproducing the real workflow logic, plus a live dry-run against the actual production host) before the production PR was opened:

- **Same-size/different-content scenario** — reproduced the exact root-cause condition (two critical files, identical byte size, different content) and confirmed the mirror correctly excluded/skipped them while the forced `put` correctly overwrote them with the right content.
- **Upload failure scenario** — simulated a forced-upload connection failure; confirmed the independent 6-attempt retry loop for critical files exhausts and fails the job (`exit 1`) rather than proceeding silently.
- **Hash mismatch scenario** — simulated a corrupted/wrong critical file on the server after upload; confirmed the provenance-verification step detects the SHA256 mismatch and fails the job with a descriptive error, including a separate case where a `routeCount`/`policyVersion` field mismatch alone (unrelated to the per-file SHA256 checks) is independently detected.
- **Dry-run validation** — confirmed `dry_run=true` produces zero writes (verified via before/after checksum of all server files) while still surfacing an accurate preview (normal-file transfer candidates plus critical-file hashes/sizes that *would* be force-uploaded). This was validated first in a local sandbox, then empirically against the real production host in workflow run #32678355672.
- **Rollback safety validation** — confirmed the pre-existing RB-6 backup step is unconditional (not gated by dry-run or by the hybrid logic) and captures the entire docroot, including both critical files, before any write — so rollback capability is unaffected by the architecture change.

Additionally, before the production PR was opened, the full existing CI/test suite was run (`pytest tools/` — 115 passed relevant to this change) and `actionlint` validated both edited workflow files with zero errors.

## 8. Prevention

**New deployment guarantees**, now enforced structurally rather than by convention, in both staging and production:

1. Critical security/provenance files can never be silently skipped by a size-based mirror heuristic again — they are structurally routed through a different, skip-free code path (`put`).
2. Every deployment independently re-verifies, against the live server (not just the CI artifact), that both critical files are present, correct, and mutually consistent — before the deploy job is allowed to report success.
3. The list of "critical" files is a single, shared source of truth (`.github/deploy/critical-deploy-files.sh`) consumed by both environments, so adding a new critical file in the future requires one change, not two independently-maintained lists that can drift.
4. A production-side, HTTP-reachable health check (in `healthcheck-suite.yml`) provides an additional, independent layer of defense-in-depth that re-validates manifest correctness *after* the deploy job has already exited — catching the case where a future bug might exist in the deploy job's own verification logic.
5. All existing safety controls (backup-before-write, dry-run isolation, confirmation-phrase gate, GitHub Environment approval, rollback via backup artifact) were preserved unweakened throughout this change, and were re-verified via dedicated tests rather than assumed.
