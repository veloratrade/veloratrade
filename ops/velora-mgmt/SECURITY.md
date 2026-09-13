# Velora Management System — Security & Threat Model

Path: **GitHub Actions → environment-scoped FTP → temporary randomized PHP → HTTPS →
`Database::connection()` → MySQL → result → probe self-deletes + FTP cleanup.**

## Credential isolation
- Staging uses only `STAGING_FTP_SERVER/USERNAME/PASSWORD` + `STAGING_VELORA_ENV`.
- Production uses only `FTP_SERVER/USERNAME/PASSWORD` (+ production private root env).
- Secret name sets do not overlap; the workflow guard selects them by explicit
  `environment` input and never falls back. Secret VALUES are never printed (GitHub
  masks env secrets; probe emits metadata only; DB name is redacted to `***_<env>`).

## Who can do what (least privilege)
- **Inspect/plan/verify:** anyone with Actions dispatch on the repo; read-only metadata.
- **Create plans:** the same; plans are deterministic artifacts (plan_hash binds content).
- **Approve / migrate / deploy / target production:** require the exact approval token
  `APPROVE-<env>-<mode>` bound to commit+migrations+plan_hash AND `backup_verified=true`;
  production additionally goes through the protected GitHub environment `production`
  (required reviewers — **owner must confirm reviewers are configured**).
- A staging approval never authorizes production and vice-versa. Changing environment,
  commit, migration set, or plan hash invalidates prior approval.

## Probe hardening
- Random filename (`_velora_mgmt_<16 hex>.php`), 256-bit one-time token, timing-safe
  `hash_equals(sha256(provided))`; single use (`@unlink(__FILE__)` immediately on the
  authorized request) plus FTP `glob rm` cleanup and runner `shred`.
- Accepts only a predefined `__OP__`; **never** arbitrary SQL or PHP from input. Read-only
  ops run fixed `information_schema`/`@@`/`SHOW` queries; no business-row/PII/credential
  reads. `migrate`/`deploy` are refused server-side unless bound approval + (production)
  verified-backup headers are present; in the current build that branch always returns
  403 (disabled).
- No permanent endpoint is created (no `/admin/db.php`); the template lives in `ops/`
  and is uploaded transiently to the target docroot.

## Prevented threats
- Replay: one-time token + random name + self-delete.
- Arbitrary SQL/PHP: fixed operation allow-list; no SQL passes through inputs.
- Secret leakage: metadata-only output, name redaction, mask directives, artifacts scrub.
- Wrong target: explicit environment, docroot selection (`public_html/staging.../` vs
  `public_html/`), guard fails closed on missing env-specific secrets.
- Unverified backup: migrate/deploy cannot run without `backup_verified=true`; production
  also requires protected-env reviewers.
- Destructive SQL: planner scans migration files for DROP DATABASE/TABLE, TRUNCATE,
  DELETE, GRANT/REVOKE and marks the plan; such operations never ship in normal flow.

## Privilege audit (live staging, read-only)
- App DB account: `CURRENT_USER()` = `<account>@localhost`; global grants = **USAGE**
  only; `IS_GRANTABLE = NO` (no GRANT OPTION); no explicit SCHEMA/TABLE-privilege rows
  (cPanel account-owner implicit rights on its own DBs).
- Note (report-only): the account can clearly perform DDL/DML on its own databases (AI
  tables exist via prior migrations). It is **not** a least-privilege, read-only account.
  Least-privilege separation (read-only inspection user vs. migration user) is recommended
  before production migrations. No privileges were changed.

## Cleanup verification
- Throwaway validation branch deleted; `main` unchanged at `117ab67`; temporary probes
  self-delete and are FTP-cleaned; no workflows/endpoints persist beyond the feature branch.

## BACKUP GATE security notes (2026-09-09)

- The BACKUP GATE (`ops/velora-mgmt/backup_gate.py`) enforces backup EVIDENCE, not
  transport security. It does not weaken, alter, or mask any TLS/FTP setting.
- **Historical record (2026-09-09) — SUPERSEDED on 2026-09-14, see below.** The generic
  management engine (`velora-mgmt.yml` + `mgmt_probe.php.tmpl`) then enforced strict FTPS
  (`ssl:verify-certificate yes`) and failed against the staging FTP endpoint's untrusted
  certificate (run 34299123683, 2026-09-09) — the probe was never uploaded and never
  reached the database. The operational workflows (deploy/backup/migrations) use the
  long-established `ftp:ssl-allow no` transport with one-time token-gated probes. The gate
  does not depend on pretending either transport is more secure than it is. This note
  originally left the management engine's certificate trust open as an owner decision;
  **that question is now closed — do not treat it as outstanding.**
- **RESOLVED OWNER DECISION (2026-09-14) — authoritative record:
  `docs/adr/ADR-014-census-probe-transport.md`.** For the temporary Census probe transport:
  - **TLS remains MANDATORY.** `ftp:ssl-allow yes` + `ftp:ssl-force yes` +
    `ftp:ssl-protect-data yes` are retained; if TLS cannot be negotiated the transfer
    **fails closed**.
  - **Certificate trust verification is INTENTIONALLY DISABLED**
    (`ssl:verify-certificate no`). This is a deliberate, approved configuration — not a
    defect, not an oversight, and not an unresolved requirement. Certificate *authenticity*
    is independent of *encryption*; relaxing trust keeps credentials encrypted in transit.
  - **Plaintext FTP is NOT ALLOWED on this path.** `ftp:ssl-allow no` (as used by the
    operational workflows) was explicitly rejected for Census because it would expose FTP
    credentials in transit.
  - **Database behaviour is unchanged:** `inspect`/`plan`/`verify` remain strictly
    read-only, token-gated, self-deleting, and limited to repository-defined queries.
  - **Residual risk (accepted):** the FTP session is encrypted but unauthenticated and
    therefore MITM-capable. Mitigate by rotating `FTP_*` secrets if compromise is suspected.
  - **Do not revert this decision** on the basis of the older 2026-09-09 note above, or of
    any general "strict FTPS" guidance elsewhere in this document. Certificate verification
    must NOT be reintroduced as a mandatory prerequisite for the Census probe transport.
    Any change to this posture requires superseding ADR-014 by explicit owner decision.
- Backup evidence identifiers (backup_id, release_tag, sha256, commit SHA) are
  non-secret by design; the gate module never handles credentials.
