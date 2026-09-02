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
  CLI: `inspect | plan | verify`. No network; consumes probe metadata JSON.
- `probe/mgmt_probe.php.tmpl` — one-use PHP probe template (fixed ops only; no arbitrary
  SQL/PHP; read-only metadata; self-deleting). Placeholders `__HASH__/__OP__/__ENV__`.
- `tests/test_velora_mgmt.py` — read-only/dry-run unit tests (fixtures + synthetic).
- `tests/fixtures/staging-gateb.json` — real captured staging metadata (MySQL 8.0.46).
- `artifacts/` — example inspect/plan/probe outputs (metadata only; no secrets).
- Workflow: `.github/workflows/velora-mgmt.yml`.

## State machine
```
INSPECT → PLAN → BACKUP(+VERIFY on prod) → APPROVAL → EXECUTE → VERIFY → COMPLETE
```
Any failure → **STOP**. No auto-fallback, no auto-retry on production, no bypass of
approval or backup. Read-only is the default.

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
