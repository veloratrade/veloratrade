# Velora Environment Management System

A reusable, environment-aware, approval-gated system for **staging** and **production**
deployment and database management. One architecture, strict environment isolation.

- **Targets (always explicit):** `staging` | `production` — never inferred, never fallback.
- **Modes:** `inspect` (read-only) · `plan` (read-only) · `verify` (read-only) ·
  `backup` · `migrate` · `deploy` (mutating; gated).
- **Transport (proven):** GitHub Actions → environment-scoped FTP → one-time randomized
  token-gated PHP probe → HTTPS → `Database::connection()` → MySQL → result → probe
  self-deletes + FTP cleanup. Reference working run: staging inspect/plan run
  `33671484955` (HTTP 200) and the Gate B probe `33667628762`.

## Layout
- `velora_mgmt.py` — pure-logic engine (normalize, compare, plan, plan-hash, approval).
  CLI: `inspect | plan | verify | backup-discover`. No network; consumes probe metadata
  JSON / a read-only GitHub artifact listing.
- `backup.py` — backup discovery, verification ladder (CREATED→INTEGRITY_VERIFIED→
  RESTORE_VERIFIED / UNVERIFIED), mutation backup gate, approval↔backup-id binding, and
  retention lifecycle (multiple history; cleanup only after success; never delete newest
  verified). See `BACKUP_POLICY.md` and `ROLLBACK.md`.
- `probe/mgmt_probe.php.tmpl` — one-use PHP probe template (fixed ops only; no arbitrary
  SQL/PHP; read-only metadata; self-deleting). Placeholders `__HASH__/__OP__/__ENV__`.
- `tests/test_velora_mgmt.py` — read-only/dry-run unit tests (fixtures + synthetic).
- `tests/fixtures/staging-gateb.json` — real captured staging metadata (MySQL 8.0.46).
- `artifacts/` — example inspect/plan/probe outputs (metadata only; no secrets).
- Workflow: `.github/workflows/velora-mgmt.yml`.

## State machine
```
INSPECT → PLAN → BACKUP REQUIRED? → CREATE → VERIFY → RECORD → APPROVAL
   → EXECUTE → POST-VERIFY → RETENTION CLEANUP → COMPLETE
```
Any failure → **STOP**. No auto-fallback, no auto-retry on production, no bypass of
approval or backup. Read-only is the default. **New verified backup always precedes any
retention cleanup;** old backups are never deleted before/during creation.

## Backup discovery (read-only)
```
python3 ops/velora-mgmt/velora_mgmt.py backup-discover --environment production \
  --artifacts <(curl ... actions/artifacts)   # read-only artifact listing JSON
```
Reports the real production file-backup artifacts (`pre-deploy-backup-<sha>`, 14d,
multi-history — CREATED, not checksum/restore-verified) and explicitly reports
**NO VERIFIED DATABASE BACKUP MECHANISM** for staging/production (gate STOP, never
fabricated). See `BACKUP_POLICY.md` for verified findings.

## Local use (no DB access needed; works on a captured metadata file)
```
python3 ops/velora-mgmt/velora_mgmt.py inspect --environment staging \
  --metadata ops/velora-mgmt/tests/fixtures/staging-gateb.json
python3 ops/velora-mgmt/velora_mgmt.py plan --environment staging \
  --metadata ops/velora-mgmt/tests/fixtures/staging-gateb.json --commit-sha <sha>
python3 ops/velora-mgmt/velora_mgmt.py verify --environment staging \
  --metadata ops/velora-mgmt/tests/fixtures/staging-gateb.json
python3 ops/velora-mgmt/tests/test_velora_mgmt.py
```

## GitHub Actions use
Dispatch `Velora Environment Management` with inputs `environment` and `mode`.
Read-only modes (`inspect/plan/verify`) are safe today. Mutating modes (`backup/migrate/
deploy`) are **disabled in this build**: the workflow exits non-zero before any change,
and the probe refuses server-side unless a bound approval and (production) a verified
backup are present. Production runs target the protected GitHub environment `production`
(required reviewers) — see BACKUP_POLICY.md and SECURITY.md.

## Approvals are bound to
`environment` + `operation` + `commit_sha` + exact `migration` set + `plan_hash`.
Change any one → previous approval is invalid. A staging approval never authorizes
production and vice versa.
