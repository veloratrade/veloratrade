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

Root directories verified present (29):

```
.github  404  _database  _next  accounts  admin  api  blog  checkout
dashboard  en  forgot-password  intelligence  localized  login  markets
news  performance  privacy  profile  public  register  reset-password
support  terms  tools  trades  verify-email  wallet
```

### Checks

- [ ] No new root-level directory without an approved baseline amendment
- [ ] `localized/` is builder-owned — never hand-edited
- [ ] `tools/` is excluded from the production package
- [ ] `_next/` remains a legacy artifact and is excluded from deployment
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
    ├── 02_ROADMAP.md                         ← from Roadmap.pdf
    ├── 03_PROJECT_STRUCTURE_BASELINE.md      ← from Structure.pdf
    ├── 04_STRUCTURE_COMPLIANCE_CHECKLIST.md  ← this document
    └── pdf/
        ├── Security Checklist.pdf
        ├── Roadmap.pdf
        └── Structure.pdf                     ← signed originals, read-only
```

### Rules

- **Numbered prefixes are permanent.** `01`–`04` are assigned; a new governance
  document takes `05`, never a reused number.
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
