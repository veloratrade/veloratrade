# Velora Backup, Retention & Rollback — Discovery & Architecture

> Status labels: **VERIFIED** (direct evidence) · **PARTIALLY VERIFIED** · **UNVERIFIED** ·
> **BLOCKED** / **NOT IMPLEMENTED**. No backup is claimed without evidence.
> This phase added architecture + discovery + tests only — **no backup was created, no
> DB/schema/data changed, no production touched.**

## 1. Discovered backup mechanisms (repository + live evidence)

### 1a. Production FILE backup — IMPLEMENTED & VERIFIED
`deploy.yml` step **"بکاپ docroot فعلی پیش از هر نوشتن (RB-6)"** runs **before any write**:
- Downloads the current production docroot over FTP (`mirror` read-only) into `backup/docroot/`,
  explicitly **excluding the nested `staging.veloratrade.ir/`** subtree.
- Fails the deploy if the download is **empty** (`find … -type f | wc -l` == 0 ⇒ stop).
- Tars to **`pre-deploy-<full-commit-sha>.tar.gz`** and uploads as a GitHub Actions artifact
  **`pre-deploy-backup-<full-sha>`** with **`retention-days: 14`** (real deploys only; dry-run
  creates none).
- Naming ties the backup to the exact deployed commit (`github.sha`).

**Live evidence (read-only GitHub API, 2026-09-02):** **13** `pre-deploy-backup-*` artifacts
exist (2026-08-20 → 2026-09-01), ~24 MB each (one ~49 MB), all `expires_at = created + 14 days`.
Multiple historical backups **coexist**; the newest is **e3909ba…** (2026-09-01).

| Property | Finding | Label |
|---|---|---|
| Created per deploy | yes, before writes | VERIFIED |
| Stored | GitHub Actions artifact (not on the server docroot) | VERIFIED |
| Naming / commit tie | `pre-deploy-backup-<full-sha>` | VERIFIED |
| Multiple retained | 13 present | VERIFIED |
| Retention | **14 days** | VERIFIED |
| Old deleted before new | **No** — new backup is created first | VERIFIED |
| Deleted after deploy | **No** automatic deletion; GitHub expires at 14d | VERIFIED |
| Integrity verification | **non-empty file count only**; no checksum, no archive test, no restore | VERIFIED (gap) |
| Restore tested | **No evidence** | UNVERIFIED |
| Covers database | **No** — files only; excludes staging subtree | VERIFIED |

`backup.py` honestly classifies these artifacts as **CREATED** (present + non-empty at creation),
**not** `INTEGRITY_VERIFIED` (no checksum) and **not** `RESTORE_VERIFIED`.

### 1b. Staging FILE backup — NOT IMPLEMENTED
`deploy-staging.yml` contains **no** backup step (no tar, no artifact). Discovery returns an empty
file manifest ⇒ gate STOP for staging file mutations.

### 1c. Database backup (staging & production) — NO VERIFIED MECHANISM FOUND
- **No** workflow/script runs `mysqldump`/`mariadb-dump` or any DB dump.
- `api/workers/preflight_v0_2.php` is a CLI **migration gate** that *verifies* an
  **operator-supplied** dump (`--backup=/protected/path/backup.sql.gz --backup-sha256=…`):
  it requires the file be outside the source tree, non-empty, non-group/world-writable, and that
  its SHA-256 matches the approved hash. It **does not create** a dump and is **not referenced by
  any GitHub Actions workflow** (not wired into CI).
- `api/database/db_backup.sql` is a **committed, scrubbed** phpMyAdmin export (11 tables,
  generated 2026-08-05; real user data was removed in commit `436344a`). It is source, **not** a
  live backup, and has no restore verification.
- Private-root (`velora_private*`) contents are not reachable via the deployed app or the
  read-only probe; hosting/cPanel snapshots and any off-host dumps are **UNVERIFIED**.

> **Conclusion: NO VERIFIED DATABASE BACKUP MECHANISM FOUND for staging or production. Database
> restore has never been tested (no evidence). The system reports this as BLOCKED/UNVERIFIED and
> never fabricates a backup.**

## 2. Required lifecycle (implemented in `ops/velora-mgmt/backup.py`)

```
INSPECT → PLAN → BACKUP REQUIRED? → CREATE BACKUP → VERIFY BACKUP → RECORD METADATA
   → APPROVAL (env+op+commit+migrations+plan_hash+backup_id) → EXECUTE
   → POST-EXECUTION VERIFY → RETENTION CLEANUP
```
Any failure ⇒ **STOP**. No cross-environment fallback, no inference.

**Ordering rule (the forbidden failure mode):**
`NEW VERIFIED BACKUP  >  OLD-BACKUP RETENTION CLEANUP`. Old backups are **never** deleted before
or during creation; cleanup runs **only** after create ✔ + integrity-verify ✔ + record ✔ +
mutation ✔ + post-verify ✔.

## 3. Backup verification ladder (real meaning)

- **CREATED** — artifact/dump exists and is non-zero size.
- **INTEGRITY_VERIFIED** — checksum matches expected **and** an archive/dump integrity check
  passes (`tar -tzf` for file; for DB: dump parse + expected database/schema identity + expected
  object/table presence). Not "exit 0", not "file exists", not "artifact uploaded".
- **RESTORE_VERIFIED** — an actual restore into an isolated/non-production DB succeeded and was
  checked. **This level is never claimed without evidence.**
- **UNVERIFIED** — absent or any required evidence missing.

Minimum for mutation (enforced by `mutation_backup_gate`): production file **and** database ⇒
`INTEGRITY_VERIFIED`; staging file ⇒ `CREATED`; staging database ⇒ `INTEGRITY_VERIFIED`
(staging is lighter but a DB migration still needs rollback protection — no fake backup).

## 4. Retention policy
- Keep **multiple** historical backups (never "one and replace").
- Defaults mirror the verified production behavior: **14 days**, and always keep the newest
  verified backups (`keep_last_n`, default 5) including the newest INTEGRITY_VERIFIED anchor.
- Cleanup computes an expiry/`protected` set and deletes only aged-out, non-protected records —
  **only after** a successful mutation + post-verification. On any failure, cleanup is suppressed
  and all history retained. (GitHub artifacts already expire at 14d server-side; the policy models
  this and would govern any server-side `/home/.../velora_backups` store if adopted.)

## 5. Backup identity / metadata (deterministic, secret-free)
`BackupRecord`: environment, backup_type (file|database), backup_id
(`bk-<env>-<type>-<sha12>`), source commit SHA, created_at, location (artifact name/path — never
credentials), size, checksum_sha256 + expected checksum, schema/version summary (DB), redacted
database identity, verification_status, restore_test_status, retention_expires_at,
operation/workflow run ids. **No passwords/FTP/DB credentials/tokens/PII** are stored.

## 6. Approval binding
Approval must bind to the exact **backup_id** that gates the mutation in addition to environment,
operation, commit SHA, migration versions, and plan hash. Changing any of these (including a newer
backup) **invalidates** the approval. A staging approval never authorizes production and vice
versa; production additionally requires the protected GitHub environment reviewers.

## 7. Rollback (separate mechanisms — never conflated)
- **File rollback:** R-1 redeploy the last-good commit (files changed ✔; newly-added files from a
  bad deploy are not removed because production mirror runs without `--delete` — documented
  limitation); R-2 cPanel full snapshot; R-3 the workflow `tar`/artifact. The `pre-deploy-<sha>`
  artifact is the concrete file restore source, but a restore procedure is **not automated/tested**.
- **Database rollback:** restore a verified `backup.sql.gz` (the preflight already defines the
  hash+location contract) or apply a migration rollback script
  (`v1.0_*_rollback.sql`, `v1.1_*_rollback.sql` — separate, explicitly-approved). A rollback
  script is **not** a database backup; database rollback is **UNVERIFIED/untested**.
- A file backup must never be treated as a DB backup nor vice-versa.

## 8. Current overall status
| Item | Env | Status |
|---|---|---|
| File backup created pre-deploy, 14d, multi-history | production | **VERIFIED** |
| File backup integrity (checksum/archive test) | production | **UNVERIFIED** (non-empty only) |
| File restore test | production | **UNVERIFIED** |
| File backup mechanism | staging | **NOT IMPLEMENTED** |
| Database dump mechanism | staging & production | **NO VERIFIED MECHANISM / BLOCKED** |
| Database restore test | staging & production | **UNVERIFIED** (never performed) |
| Production DB migration | — | **LOCKED** (needs verified backup + approval + protected env) |
| Staging DB migration | — | **BLOCKED** (needs verified backup/rollback) |
