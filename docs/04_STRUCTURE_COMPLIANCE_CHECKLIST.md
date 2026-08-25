# VELORA — STRUCTURE COMPLIANCE CHECKLIST

**Status:** DRAFT — awaiting approval before upload
**Companion to:** `03_VELORA_PROJECT_STRUCTURE_BASELINE` (Structure.pdf)
**Repository:** `veloratrade/veloratrade` (private)
**Verified against live repo + production host:** 2026-08-16

---

## Purpose

Structure.pdf records the **as-is** state. This document is the **enforcement**
counterpart: the recurring checks that keep the tree from drifting away from
that baseline.

Run this checklist:

- before every production release
- after any file move, rename, or directory change
- after any change to `.htaccess`, `locale-router.php`, or `api/index.php`
- after any localization rebuild
- after any change to secret or runtime-path handling

**Audit-only rule (inherited from the Security Checklist):** report first, fix
after approval. Do not move, delete, or rewrite files during the audit.

---

## 1. Runtime Secret Location — RESOLVED DRIFT

The baseline (Structure.pdf, §Runtime, lines 80/399/1062) documents secrets at:

```
api/.env
```

The code no longer reads that path as primary. `api/src/Core/Config.php`
resolves in this order:

| Order | Source | Line |
|---|---|---|
| 1 | Real process environment variables | `Config::env()` |
| 2 | `$VELORA_PRIVATE_ROOT/config/velora.env` — **outside document root** | `Config.php:121` |
| 3 | Legacy in-tree `api/.env` — **only when `APP_ENV=dev` AND `VELORA_PRIVATE_ROOT` is unset** | `Config.php:117-118` |

### Verified production location

```
/home/piknet/velora_private/config/velora.env      (3.66 KB)
```

Document root is `/home/piknet/public_html/`, so the secret file sits in a
**sibling directory one level above** the web root. It cannot be reached by any
URL — there is no path traversal from `public_html` to `velora_private`.

```
/home/piknet/
├── public_html/          ← document root (web-served)
└── velora_private/       ← NOT web-served
    └── config/
        └── velora.env    ← runtime secrets
```

### Why this is fail-closed

`privateRoot()` (`Config.php:56-81`) enforces four conditions in sequence:

1. `VELORA_PRIVATE_ROOT` must be set — otherwise production **throws**
   (`Config.php:70`); only a dev process may fall back to the in-tree path
2. The path must be absolute (`:72`)
3. The directory must already exist (`:76`)
4. `assertOutsideDocumentRoot()` must pass (`:78`)

`privatePath()` adds traversal rejection (`:87`), symlink-escape checks
(`:96`, `:109`), and a second `assertOutsideDocumentRoot()` (`:95`).

**Verdict:** this is a security **improvement**, not a regression — and a
stronger design than the baseline describes. The baseline text is stale and
should be amended.

### Checks

- [ ] `api/.env` is absent from the repository — *verified 2026-08-16: absent*
- [ ] `api/.env.example` is present as the template — *verified: present*
- [ ] `VELORA_PRIVATE_ROOT=/home/piknet/velora_private` is set in the host
      environment (cPanel → Setup PHP App → Environment Variables, or
      `SetEnv` in `.htaccess`)
- [ ] `/home/piknet/velora_private/config/velora.env` exists and is readable by PHP
- [ ] `velora_private/` is a **sibling** of `public_html/`, never inside it
- [ ] `APP_ENV` is **not** `dev` in production
- [ ] File permission on `velora.env` is `600` (owner-only); the directory is `700`
- [ ] `assertOutsideDocumentRoot()` remains in force — never removed to "simplify" a deploy
- [ ] Backup jobs that archive `/home/piknet/` do not write the archive into `public_html/`

### Web-exposure probe (must all deny)

Verified 2026-08-16 against `https://veloratrade.ir`:

| Path | Result | Status |
|---|---|---|
| `/.env` | 403 | pass |
| `/api/.env` | 404 | pass |
| `/api/.env.example` | 404 | pass |
| `/.git/config` | 403 | pass |
| `/api/storage/velora.sqlite` | 403 | pass |
| `/api/database/db_backup.sql` | 403 | pass |
| `/_database/database_corrected.sql` | 404 | pass |
| `/velora_private/config/velora.env` | 404 | pass |
| `/velora_private/` | 404 | pass |
| `/config/velora.env` | 404 | pass |
| `/velora.env` | 404 | pass |

Also probe after every deploy: `/backup/`, `/logs/`, `/private/`, `/database/`.

---

## 2. Directory Contract

Root-level directories are enumerated **once** — in the machine-readable index at
`03_PROJECT_STRUCTURE_BASELINE.md` §0, between the `VELORA_STRUCTURE_INDEX_BEGIN` /
`VELORA_STRUCTURE_INDEX_END` markers. That index is maintained by
`tools/structure_sync.py` and enforced in CI by `.github/workflows/quality-gate.yml`
(step *"Structure index drift guard (OC-14)"*).

**This checklist deliberately does not restate that list.** A second, hand-maintained
copy drifts silently, because nothing in CI compares it to the repository — which is
exactly how this section came to list a directory that no longer exists while omitting
one that does. Verify live instead:

```bash
python tools/structure_sync.py --report   # read-only, always exit 0
python tools/structure_sync.py --check    # CI mode, non-zero on drift
```

The tool defines drift as a directory present in the repo but not indexed (`NEW`), or
indexed but no longer present in the repo (`REMOVED`). On drift, run
`python tools/structure_sync.py --update` and commit the regenerated index block.

### Checks

- [ ] `python tools/structure_sync.py --check` exits 0 — **authoritative structure validation**
- [ ] No new root-level directory without an approved baseline amendment
- [ ] `localized/` is builder-owned — never hand-edited
- [ ] `tools/` and `docs/` are excluded from the production package (see §4)
- [ ] `api/storage/` exists on the host but is never committed
- [ ] The `MANIFEST.json` / `manifest.json` case-fold collision is not duplicated elsewhere

---

## 3. Localization and CSP Route Integrity

**Standing rule:** any HTML edit must ship with a regenerated
`public/locales/csp-manifest.json` **and** `localized/.csp-release.json`,
or `locale-router.php` returns HTTP 503 "Security policy unavailable."

This is enforced automatically by `.github/workflows/csp-guard.yml`.

### Checks

- [ ] Every route in `csp-manifest.json` hash-matches its file on disk
- [ ] `cspManifestSha256` in `.csp-release.json` matches the manifest bytes
- [ ] `policyVersion`, `releaseId`, `releaseHtmlSha256`, `routeCount` agree across both files
- [ ] No `localized/**.html` file exists without a manifest record
- [ ] The manifest served by the host is byte-identical to the repository copy
- [ ] No Cloudflare feature rewrites HTML after it leaves the server
      (**Email Obfuscation, Rocket Loader, and HTML Auto Minify must stay OFF**
      — Email Obfuscation caused a full-site 503 on 2026-08-16)

### Known count drift

| Metric | Baseline | Live repo (2026-08-21, HEAD `8eb0d21`) | Note |
|---|---|---|---|
| Generated locale outputs | 59 | **61** | grew since the audit; routes.json now has 29 routes |
| Total files | 630 | **735 files / 169 directories** | the interim 809 count (2026-08-16) predates removal of runtime artifacts |
| API endpoints | 35 + `/health` | **37 + `/health`** | added: `GET`/`PUT /api/v1/auth/email-preferences` (BUG-A9 fix, TEST-15) |

Neither is a defect — the baseline predates later work. Both need a baseline
amendment rather than a code change.

---

## 4. Deployment Package Contract

The production package must **exclude**:

```
api/.env          api/storage/**     _database/**      **/*.sql
**/*.sqlite       **/*.log           tools/**          .github/**
vendor/           node_modules/      **/*.md           **/*.zip
docs/**
```

### Checks

- [ ] `api/.env` and `api/storage/` on the host are never overwritten by a release
- [ ] `dangerous-clean-slate` remains `false` in `deploy.yml`
- [ ] Database migrations are treated as **server actions**, not file uploads
- [ ] Full-tree deploys only — the 2026-08-16 logout failure was caused by a
      partial upload that omitted `api/`, leaving stale server code for weeks
- [ ] After deploy, host manifest hash equals repo manifest hash
      (automated in `healthcheck-production.yml`)

---

## 5. Repository Hygiene

### Checks

- [ ] No `.env`, `.sqlite`, `.pem`, `.key`, or `id_rsa` in the tree
      (automated in `csp-guard.yml`)
- [ ] No real bcrypt hashes, real email addresses, or session tokens in any `.sql`
      (automated — this check caught two files the manual review had missed:
      `api/database/database_corrected.sql` and `api/database/db_backup.sql`)
- [ ] 11 SQL files remain schema-only
- [ ] Repository stays **private** until the security baseline is fully satisfied

---

## 6. Governance Document Locations

All permanent project documents live under `docs/` at the repository root.
Nothing governance-related stays loose in the root directory.

```
veloratrade/veloratrade
└── docs/
    ├── 01_SECURITY_CHECKLIST.md              ← from Security Checklist.pdf
    ├── 02_ROADMAP.md                         ← RESERVED-ABSENT — locked by B-6
    ├── 03_PROJECT_STRUCTURE_BASELINE.md      ← from Structure.pdf
    ├── 04_STRUCTURE_COMPLIANCE_CHECKLIST.md  ← this document
    ├── 05_BILINGUAL_CHECKLIST.md             ← fa/en governance
    ├── 06_MERGE_REVIEW_POLICY.md             ← merge-time review (advisory)
    ├── README.md                             ← operations handbook — entry point
    ├── QUALITY_GATE_MATRIX.md                ← gate ↔ bug ↔ test registry
    ├── RELEASE_CHECKLIST.md                  ← human release surface
    ├── STAGING_ENVIRONMENT.md                ← P2 environment policy
    ├── LOCALIZATION_CLOSURE_CHECKLIST.md
    ├── ARTIFACT_INTEGRITY_CHECKLIST.md
    ├── REAL_HOST_LOCALIZATION_VALIDATION.md
    ├── PROJECT_STATE.json                    ← snapshot — not governance
    ├── SESSION_STATE.json                    ← session handoff — not governance
    ├── incidents/
    │   └── 2026-08-csp-deployment-incident.md
    └── pdf/
        ├── Roadmap.pdf                       ← LOCKED / DO NOT USE (B-6)
        ├── Security Checklist.pdf
        └── Structure.pdf                     ← signed originals, read-only
```

> Verify with `git ls-files docs`. This tree must match that output exactly; if it
> does not, the tree is wrong — not the repository.

### Rules

- **Numbered prefixes are permanent once assigned and are never reused.**
  Assigned: `01`, `03`, `04`, `05`, `06`.
  **`02` is RESERVED and intentionally absent** — it belongs to the roadmap, which is
  locked under B-6 in `docs/README.md`. Do not allocate `02` to anything else, and do
  not renumber existing documents to close the gap.
  **Next available governance document number: `07`.** Confirm a number is free before
  allocating it: `ls docs/[0-9][0-9]_*.md`.
- **Unnumbered documents are permitted** for operational handbooks, matrices and
  checklists (`README.md`, `QUALITY_GATE_MATRIX.md`, `RELEASE_CHECKLIST.md` and
  similar). A document takes a number when it is a standing governance contract with
  its own identifiers, phases, or gates.
- **Markdown is the working copy.** Diffs and review happen on the `.md` files.
- **`docs/pdf/` holds the signed originals.** They are historical records and
  are never edited in place — a superseded PDF is replaced only alongside a
  version bump in the matching `.md`.
- **Deployment excludes `docs/`** (see §4). Governance documents never reach
  the production host.
- **Amending the baseline requires amending this checklist** whenever the change
  touches a path contract, directory list, or count in §2 or §3.

### Legacy documents pending triage

Eleven documents currently sit in the repository root. They are **not**
governance documents and are out of scope for this checklist, but they overlap
in subject matter and should be reviewed separately:

```
00-INSTALL-FA.txt              DEPLOYMENT_GUIDE_FA.md
ALL_INCLUDED_CHANGES_FA.txt    DEPLOYMENT_README.txt
CHANGELOG_FA.txt               HOST_TEST_README.md
LOCALIZATION_ARCHITECTURE.md   PATCH_INSTRUCTIONS_FA.txt
LOCALIZATION_IMPLEMENTATION_REPORT.md          README.txt
robots.txt                     ← not a document; leave in root
```

No move is proposed here. Relocating them requires its own approval.

---

## 7. Cross-Document Dependencies

| Document | Relationship |
|---|---|
| `03_PROJECT_STRUCTURE_BASELINE` (Structure.pdf) | This checklist enforces it; §1 and §3 flag stale entries |
| `01_SECURITY_CHECKLIST` | Owns §1 exposure probes; this document owns path-contract enforcement |
| `02_ROADMAP` | Phase changes that add directories require a baseline amendment first |
| `.github/workflows/csp-guard.yml` | Automates §3 and §5 |
| `.github/workflows/healthcheck-production.yml` | Automates §1 probes and §4 post-deploy verification |
| `05_BILINGUAL_CHECKLIST` | Owns fa/en governance; every new user-facing surface runs its §1–§5 |
| `06_MERGE_REVIEW_POLICY` | Advisory merge-time review; flags `api/**` for human review |
| `README` (operations handbook) | Owns the `NP-` / `OC-` / `B-` / `RB-` registers and the session bootstrap protocol |
| `QUALITY_GATE_MATRIX` | Registry of gate ↔ bug ↔ test; new release-blocking gates are recorded there |
| `RELEASE_CHECKLIST` | Human-facing release surface; mirrors the automated gates |
| **`tools/structure_sync.py`** | **Owns the top-level directory index** (`03` §0). §2 delegates to it and does not restate the list |
| `.github/workflows/quality-gate.yml` | Automates §2 — runs `structure_sync.py --check` (step "Structure index drift guard (OC-14)") |

---

## 8. Amendments Required to the Baseline

These are **reported, not applied** — approval required.

| # | Baseline says | Actual | Action |
|---|---|---|---|
| A-1 | Runtime secrets at `api/.env` (lines 80, 399, 1062) | `/home/piknet/velora_private/config/velora.env` — sibling of `public_html`, gated by `VELORA_PRIVATE_ROOT`; in-tree path is dev-only fallback | Amend baseline text and the tree diagram at line 1062 |
| A-2 | 59 generated locale outputs | 61 | Recount and amend |
| A-3 | 630 files / 159 directories | 735 files / 169 directories (2026-08-21; interim 809 count was pre-cleanup) | Re-run the walk and amend |
| A-4 | 35 API endpoints + `/health` | 37 + `/health` (2026-08-21) — added `GET`/`PUT /api/v1/auth/email-preferences` (BUG-A9 fix, TEST-15) | Verify, then amend if changed |
| A-5 | No CI/CD in the structure map | `.github/workflows/` now holds 20 workflows (see `docs/QUALITY_GATE_MATRIX.md`) | Add a CI/CD layer to the map |
| A-6 | **This checklist**, §2, restated the root-directory list by hand and drifted: it listed `_next/` (absent from the repo — 0 tracked files) and omitted `docs/` (present). The stated count "29" was correct only by coincidence, so a count-only review passed. §6's numbering rule likewise named `05` as next while `05`/`06` already existed | The authoritative list is the script-maintained index in `03` §0; `python tools/structure_sync.py --check` exits 0 with `NEW none / REMOVED none`. No baseline change is required — the baseline was already correct | **Resolved 2026-08-25 by R-1…R-5 in this checklist**: §2 now delegates to `structure_sync.py` instead of restating the list, the obsolete `_next/` check is removed, §6's numbering rule and document tree match the repository, and §7 names the index owner |
