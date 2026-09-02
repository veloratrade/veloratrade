# Velora Rollback Procedure (runbook — design; no automated restore is currently wired)

Two independent rollback domains. **Never treat one as the other.**

## File rollback (production)
1. Identify the last-good commit from the audit record / the `pre-deploy-backup-<sha>`
   artifact list (GitHub Actions → artifacts; retention 14 days).
2. Preferred quick path (R-1): redeploy the last-good commit via the normal production
   deploy (dry-run → approval). Caveat: production `mirror -R` runs **without `--delete`**,
   so files newly added by a bad release remain on the server; verify and remove any
   such stray files out-of-band if needed.
3. Full restore path (R-3): download the `pre-deploy-backup-<sha>.tar.gz` artifact,
   `tar -tzf` to verify archive integrity, then restore docroot contents over FTP
   (staging subtree excluded). **Verify integrity before restoring.**
4. Post-rollback: health + asset + CSP-manifest parity checks; do not rely on the static
   `/health` alone for DB health.

## Database rollback / restore
- A DB restore is only possible from a **verified** database backup (state
  `INTEGRITY_VERIFIED` or higher). **No such backup currently exists** — there is no
  automated dump, so DB restore is **BLOCKED/UNVERIFIED** until one is created and
  tested (out of scope for this phase; requires explicit authorization).
- Migration-scope rollback uses the repo rollback scripts as a separate, explicitly
  approved action: `api/database/migrations/v1.0_trade_time_canonical_rollback.sql`,
  `v1.1_metaapi_fill_ledger_rollback.sql` (and `v0.9_*_rollback.sql`). These are
  additive-column/table removals; a rollback **script is not a database backup**.
- The migration gate `api/workers/preflight_v0_2.php` already defines the evidence
  contract a real DB backup must satisfy before a migration: a `backup.sql.gz`
  **outside** the web source tree, non-empty, not group/world-writable, with SHA-256
  matching an approved hash (`--backup` + `--backup-sha256`), and workers stopped.
  This contract is reused by the management system's INTEGRITY_VERIFIED definition.

## Restore test (required before calling anything RESTORE_VERIFIED)
1. In an isolated/non-production DB, import the dump.
2. Verify engine/version, expected table set, row-count sanity, and (for migrations)
   the canonical columns / `metaapi_fills` objects via the read-only `inspect`/`verify`
   engine.
3. Record the result; only then mark the backup `RESTORE_VERIFIED`.
No restore test has been performed to date (nothing claims otherwise).
