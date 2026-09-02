# Velora Backup & Rollback Policy

## Production — mandatory verified pre-change backup
For **every** production database-changing operation (migration, transfer, import,
structural change, approved sync) AND production **file** deployment:

1. Identify exact target (production docroot / production DB).
2. Create a backup of the CURRENT state immediately before execution.
3. **Verify** the backup completed and is usable (independent confirmation / checksum).
4. Record metadata: timestamp, backup id, target environment, source DB state, commit
   SHA, operation/migration, backup result, verification result.
5. Require explicit production approval.
6. Execute the approved operation.
7. Verify the resulting state.
8. On failure: STOP; use the verified rollback only if authorized and available.

**HARD RULE:** if backup creation fails, backup verification fails, or the backup cannot
be independently confirmed → **STOP**. No production modification. User approval does
**not** override a missing/failed backup. "We can probably restore it" is not a backup.

## Current verification status (this implementation)
- In-probe backup creation: **NOT AVAILABLE / NOT USED**. Shared hosting does not expose
  a safe in-PHP dump path to the probe; the system **never fabricates** a backup. The
  `backup` op reports `state: UNVERIFIED, supported_in_probe: false`.
- Hosting/cPanel snapshot & `mysqldump`: **UNVERIFIED** from this environment (no cPanel
  API/SSH/MySQL client). Owner must confirm a recent snapshot/dump + a tested restore.
- Rollback SQL for migrations exists in-repo
  (`v1.0_*_rollback.sql`, `v1.1_*_rollback.sql`) but a rollback script is **not** a
  database backup.
- Therefore: production and staging **migration execution stays BLOCKED** until a
  verified backup/restore path is evidenced (staging uses a lighter policy but still
  requires rollback protection for a migration).

## Files (production)
Before replacing production files: backup current files → verify → record → deploy (with
approval) → post-deploy health + integrity. No backup / no approval / wrong target ⇒
NO DEPLOYMENT.
