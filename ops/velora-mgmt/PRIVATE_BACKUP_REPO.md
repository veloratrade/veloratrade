# Velora — Private Database-Backup Repository: Architecture & Design

> Design + dry-run implementation only. This task creates **no** repository, credential,
> release, or dump; it changes nothing in staging/production and does not merge PR #106.
> Code lives in `ops/velora-mgmt/private_backup_repo.py` (+ tests). Labels:
> **VERIFIED** (fact) · **DESIGNED** (architecture, not yet exercised live) · **BLOCKED/OWNER**.

## 1. Repository separation
| Repo | Visibility | Contains | Never contains |
|---|---|---|---|
| `veloratrade/veloratrade` | **public** | app source, workflows, non-secret config | DB dumps, credentials, `.env`, backup PII |
| `veloratrade/velora-backups` | **private** (to be created) | DB backups (release assets), metadata JSON, checksums, verification/rollback records | DB passwords, API keys, PATs, MetaApi/webhook/SMTP secrets, encryption keys, `.env`, raw credentials |

Dumps are **never** committed to the public repo or to normal app git history.

## 2. Storage design (why not ".sql.gz in Git")
GitHub limits/quotas (verified 2026):
- Git file hard limit **100 MB** (warn 50 MB); recommended repo < 1 GB; history bloat is permanent.
- **Git LFS:** free data cap is only **1 GB storage + 1 GB/mo bandwidth**, then **paid** → rejected for zero-cost.
- **Actions artifacts:** Free plan gives **500 MB** shared artifact/package storage (private) and retention-capped; artifacts are also tied to a run, not an ideal durable backup store.
- **GitHub Releases:** each asset up to **2 GB**, **no total-size or bandwidth billing**, and release assets **do not consume the 500 MB Actions storage**.

**Chosen (zero-additional-cost, GitHub-native):**
- Large binary dumps → **private-repo GitHub Release assets** (`*.sql.gz`, gzip'd `mysqldump
  --single-transaction --routines --events`), one immutable release per backup.
- Small text (`<backup_id>.json` metadata + `<backup_id>.sql.gz.sha256` checksum + verification
  record) → committed under `backups/<env>/` in the **private** repo (tiny, auditable, versioned).

If a future dump exceeds 2 GB/file (not expected for this app — staging DB is tens of MB;
production docroot file-backups are ~24 MB, DB is far smaller), **STOP and report** rather than
silently adopting paid storage / LFS / external services.

## 3. Private repository layout
```
velora-backups/ (private)
  README.md                       # safety/usage notes; no secrets
  backups/
    staging/
      <backup_id>.json           # metadata (committed, secret-free)
      <backup_id>.sql.gz.sha256  # checksum text
    production/
      <backup_id>.json
      <backup_id>.sql.gz.sha256
  verification/
    staging/<backup_id>.json     # restore-test evidence (when performed)
    production/<backup_id>.json
  releases (GitHub Releases, NOT git):
    tag: db-backup-<env>-<yyyymmddhhmmss>-<id12>
      asset: <env>/<backup_id>.sql.gz   (the actual dump)
```
Staging and production are separate namespaces; cross-namespace writes are rejected in code.

## 4. Naming conventions (deterministic)
- `backup_id` = `bk-<env4>-d-<sha256(env|database|commit|run|time)[:12]>` (reused from backup.py).
- Release tag: `db-backup-<env>-<UTC yyyymmddhhmmss>-<id12>` (monotonic, unique, environment-prefixed).
- Release asset: `<env>/<backup_id>.sql.gz`.
- Metadata path: `backups/<env>/<backup_id>.json`; checksum `backups/<env>/<backup_id>.sql.gz.sha256`.

## 5. Metadata format (`backups/<env>/<id>.json`) — secret-free
Schema `velora-db-backup/1`: `backup_id`, `environment`, `backup_type=database`, `source_commit_sha`,
`created_at_utc`, `database_identity` (redacted, e.g. `***_velora_staging / MySQL 8.0.46`),
`size_bytes`, `sha256`, `expected_sha256`, `schema_migration_version`, `verification_status`,
`restore_test_status`, `creation_mechanism`, `retention_expires_at_utc`, `retention_days` (=14),
`storage{repository, repository_visibility=private, release_tag, release_asset, metadata_path}`,
`workflow_run_id`. Built by `build_private_backup_metadata()`; it validates commit SHA (40-hex),
checksum (64-hex), environment namespace, and **rejects any secret-like content**
(`assert_metadata_has_no_secrets`: password/token/key/.env/DSN/`mysql://`/`ftp://`/PAT prefixes).

## 6. Authentication / permission model (names only; no secret values)
**Preferred (least privilege, no long-lived PAT): a GitHub App** owned by the org, installed on
`velora-backups` only:
- secrets: `BACKUP_REPO_APP_ID`, `BACKUP_REPO_APP_INSTALLATION_ID`, `BACKUP_REPO_APP_PRIVATE_KEY`.
- permissions on the private repo: **contents: write**, metadata: read (nothing else).

**Fallback (documented dependency): a fine-grained PAT** scoped to `velora-backups` only with
contents:write + metadata: read, stored as `BACKUP_REPO_TOKEN`. The existing broad project PAT is
**not** silently reused/expanded: if the architecture ever relies on it that dependency must be
explicitly documented; the design strongly prefers the App.

**Exposure rules (prevent PR/untrusted access):**
- Backup-write workflows are `workflow_dispatch` (and protected-environment) only — **never**
  `pull_request`. Fork/untrusted PR runs therefore never receive the backup secrets.
- The secrets live on the repo that runs the backup job; production backup runs additionally use
  the protected GitHub **`production`** environment (required reviewers).
- The public app repo never stores backup-repo credentials in a context reachable by PR workflows.

## 7. Environment isolation & approval
- Target is an explicit `environment=staging|production` input; invalid/blank values fail closed
  (`require_environment`/`require_operation`).
- Every stored location (tag, metadata path, asset) is checked to embed the same environment;
  a mismatch raises (e.g. a production dump into staging namespace is refused).
- Approvals bind to environment + operation + commit SHA + migration set + plan hash + **backup_id**.
  A staging approval never authorizes production; production needs a separate explicit approval and
  the protected environment. A new backup (new backup_id) invalidates a prior approval.
- A production **backup** does **not** authorize migrate/deploy — those are separate approvals.

## 8. Lifecycle & gating (implemented in code)
`inspect → plan → approval → backup → verify integrity → bind backup_id to approval → mutate →
post-verify → retention`. Hard rules: never delete the previous backup before a new one is created
**and** verified; failed create/verify/mutate/post-verify ⇒ STOP and **keep all backups**.
`private_backup_gate()` blocks migrate/deploy unless a private-repo backup exists for the SAME
environment in the PRIVATE repo and meets the required state. Required levels:
production migrate/deploy ⇒ **INTEGRITY_VERIFIED** minimum; staging migrate ⇒ INTEGRITY_VERIFIED
(DB rollback protection); backup creation itself only CREATED until verified.

## 9. Verification policy (state ladder reused)
- **CREATED**: release asset uploaded, non-zero size.
- **INTEGRITY_VERIFIED**: SHA-256 of the downloaded asset matches metadata; `gzip -t` passes; the
  dump parses and contains expected DB/schema identity and expected tables
  (`mysqldump --no-data` sanity / table list); metadata consistent.
- **RESTORE_VERIFIED**: restored into an **isolated** non-production MySQL and checked via the
  read-only inspect/verify engine (engine/version, expected tables/columns). Not performed in this
  task; never claimed without evidence.
"File exists / exit 0 / artifact uploaded" is **not** verification.

## 10. Restore strategy (DESIGNED; not executed)
1. Download the release asset `<env>/<backup_id>.sql.gz` from the private repo (authorized App/PAT).
2. `gzip -t` + sha256 match against the committed `.sha256`.
3. Restore into an isolated scratch DB (never directly over prod outside an approved incident).
4. Run the read-only `inspect`/`verify` engine; record evidence under `verification/<env>/`.
Only a passed isolated restore flips state to RESTORE_VERIFIED.

## 11. Rollback strategy
- **Database rollback** = restore a RESTORE_VERIFIED (or at minimum INTEGRITY_VERIFIED during an
  approved incident) dump, OR apply a migration rollback script
  (`v1.0_*_rollback.sql`, `v1.1_*_rollback.sql`) as a separate, explicitly-approved action. A
  rollback script is not a backup.
- **File rollback** remains the existing `pre-deploy-backup-<sha>` artifact path (see ROLLBACK.md).
  File and database backups are never interchangeable.

## 12. Retention policy
- Multiple historical backups retained (never "one and replace"). Default **14 days**, keep newest
  **5** verified plus the newest INTEGRITY_VERIFIED anchor. Release assets are deleted **only** after
  a successful, post-verified mutation; on any failure retention is suppressed and nothing is
  deleted. `retention_plan()` enforces this and refuses cross-environment sets.

## 13. Threat model
- **Dump exposure:** mitigated by private repo + release assets (not public URLs, not in public git),
  redacted identity in metadata, no secrets in metadata (machine-checked).
- **PR/fork credential theft:** backup jobs are workflow_dispatch/protected-env only; App scoped to
  the private repo; no secrets on pull_request.
- **Cross-environment contamination:** explicit environment + namespace assertions + gate compare.
- **Reuse of old backup/plan:** approval binds commit/plan_hash/backup_id; any change invalidates.
- **Unverified backup treated as safe:** migration gate requires the state ladder; unverified ⇒ STOP.
- **Auditability:** every backup has immutable release + committed metadata/checksum + run id.

## 14. Cost analysis (zero-additional-cost target)
- Releases (assets ≤2 GB, unlimited total/bandwidth, not billed) → **$0**.
- Backup job runs on the **public** app repo → standard Linux runners are **free/unlimited for
  public repos** (the private backup repo receives only API release uploads; we don't need to run
  Actions in it).
- No LFS, no paid storage, no external service. **$0 added.**

## 15. GitHub Free limitations to respect
- 500 MB Actions artifact storage (private) → avoided by using Releases, not artifacts, for dumps.
- LFS 1 GB → not used. Git 100 MB/file → dumps never committed.
- Fine-grained PAT/App org availability depends on the account; if the org cannot use a GitHub App,
  the fine-grained PAT fallback is required (documented, not assumed).

## 16. Owner setup checklist (requires human action — NOT done here)
1. Create **private** repo `veloratrade/velora-backups` with the layout above (README + empty
   `backups/staging`, `backups/production`, `verification/…`).
2. Create a **GitHub App** (or fine-grained PAT fallback) scoped to that repo with
   contents:write + metadata:read; add secrets `BACKUP_REPO_APP_ID / _INSTALLATION_ID /
   _PRIVATE_KEY` (or `BACKUP_REPO_TOKEN`) to the backup-running repo.
3. Confirm the protected **`production`** environment has required reviewers.
4. (Later, separately authorized) enable the actual `backup` op to run `mysqldump` on the host via
   the one-use probe, upload to a release, commit metadata, verify; then the migrate gate can pass.

## 17. What is implemented vs. only designed
- **Implemented + unit-tested (dry-run):** naming/metadata builder, namespace & fail-closed
  validation, secret-leak guard, private-repo gate, retention planner, required-secret/permission
  model (`private_backup_repo.py`; 23 tests).
- **Designed only (not live):** the workflow step that produces a dump and uploads a release (needs
  the private repo + credentials + an authorized backup operation); restore testing.
- **Not done (blocked on owner):** repo creation, credentials, any real backup/restore/migration/
  deploy. No production/staging change.

---

## 18. Connection layer (implemented) + live status

Added components (branch only):
- `backup_repo_client.py` — `Client` with injectable transport (default reads `BACKUP_REPO_TOKEN`,
  never prints it): `check_repository()` (must exist AND be private AND match exact owner/name),
  `list_backups(env)`, `commit_metadata()` (small text via contents API; refuses out-of-namespace
  paths), `create_backup_release()`, `upload_release_asset()` (the ONLY dump path; enforces
  `<env>/<id>.sql.gz`), `verify_integrity()` (sha256 + gzip validity + non-empty), and guarded
  `delete_release_by_tag()` (env-prefixed tags only).
- `backup_lifecycle.py` — orchestrates DISCOVER→PLAN→APPROVE→CREATE→UPLOAD→VERIFY→BIND→MUTATE→
  POST-VERIFY→RETENTION; BLOCKS (never fabricates) when no dump is supplied; production mutate
  requires a bound approval; failures STOP with no retention/deletion; dry-run never uploads/deletes.
- `.github/workflows/velora-db-backup.yml` (production) / `.github/workflows/velora-db-backup-staging.yml`
  (staging) — reusable `workflow_call` (+ manual dispatch), explicit hard-locked environment,
  require `BACKUP_REPO_TOKEN` (no GITHUB_TOKEN fallback; fail closed), produce a FRESH verified
  dump → private-repo Release Asset → byte re-verify, and route production through the protected
  `production` environment. Invoked by `deploy.yml` / `deploy-staging.yml`.
- Tests: `tests/test_backup_repo_connection.py` (18 cases with a MOCK transport; synthetic gzip
  blob, not database data) covering repo-exists/private, public refusal, missing-repo fail-closed,
  env isolation, no namespace collision, unverified blocks migrate, dump never enters contents/git,
  deterministic release-asset naming, secret-free metadata, sha256 integrity gate, retention keeps
  newest after success and never deletes on failure, lifecycle no-dump BLOCKS, production approval
  required, dry-run makes no writes, and production file-backup lifecycle stays separate.

LIVE READ-ONLY STATUS (2026-09-02):
- `veloratrade/velora-backups` was reported created, but the CURRENT project fine-grained PAT returns
  **HTTP 404** for it (the token can read the public app repo, 200). A fine-grained PAT/GitHub App only
  sees repos it is explicitly granted; this token predates / is not scoped to the new private repo.
- Per the safety rule (do not guess/expand credentials), connection is therefore BLOCKED on a
  credential: the owner must grant the new private repo to a **dedicated identity** and provide
  `BACKUP_REPO_TOKEN` (GitHub App installation token preferred) scoped to `velora-backups` with
  `contents: write` + `metadata: read` ONLY. No real backup/metadata/release was created.
