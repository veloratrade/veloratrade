# ADR-014 — Production Read-Only Census: Probe Transport Constraint

**Status:** Accepted
**Date:** 2026-09-14
**Scope:** `.github/workflows/velora-mgmt.yml` + `ops/velora-mgmt/probe/mgmt_probe.php.tmpl`
**Supersedes:** nothing. **Amends:** the transport note in `ops/velora-mgmt/SECURITY.md` (2026-09-09).

---

## Decision

> Production read-only Census uses the existing one-use PHP probe architecture. Certificate
> verification must not be a blocking prerequisite for temporary probe-file transport. The
> probe remains strictly read-only, token-gated, self-deleting, and limited to
> repository-defined queries. No database credentials are stored in the repository or
> exposed in logs.

---

## Context

The read-only Census (`mode=inspect`) is required to resolve two open migration blockers —
**D-01** (which schema actually exists in live production) and **D-10** (trading-timestamp
provenance). Neither can be answered from repository evidence alone.

The only governed mechanism for reaching the production database is the established one-use
PHP probe:

```
GitHub Actions -> temporary PHP probe upload (FTP/TLS) -> HTTPS token-gated execution
  -> read-only SQL -> JSON result -> probe self-deletes -> workflow FTP cleanup
```

On **2026-09-09**, workflow run **34299123683** failed before reaching the database:

```
glob: Fatal error: Certificate verification: The certificate is NOT trusted.
      The certificate issuer is unknown.
put: /tmp/probe.php: Fatal error: Certificate verification: The certificate is NOT trusted.
::error::probe upload failed
```

The probe was never uploaded, never executed, and never contacted the database. The shared
hosting FTP endpoint presents a certificate whose issuer the GitHub runner does not trust.

Obtaining a trusted certificate for that endpoint is outside the project's control (shared
cPanel hosting). Making Census depend on it would make an owner-critical, read-only
diagnostic permanently unavailable for reasons unrelated to database safety.

---

## Why certificate verification is not a prerequisite

**Certificate verification and encryption are independent properties.**

| `lftp` setting | Guarantees |
|---|---|
| `ftp:ssl-allow yes` | Attempt `AUTH TLS` — **encrypts the control channel** (credentials) |
| `ftp:ssl-force yes` | **Abort** if TLS cannot be negotiated — never silently fall back to plaintext |
| `ftp:ssl-protect-data yes` | **Encrypts the data channel** (file contents) |
| `ssl:verify-certificate` | Validates the certificate chain/hostname — **authenticity only** |

Disabling only `ssl:verify-certificate` leaves the session **encrypted but unauthenticated**.
It is *not* a downgrade to plaintext. Credentials are never transmitted in the clear.

This distinction is decisive: the failure that blocked Census was an **authenticity** check,
while the property that protects credentials is **encryption** — and encryption is retained
in full.

The alternative already used by 19 of the repository's other workflows —
`ftp:ssl-allow no`, i.e. **plaintext FTP** — was explicitly **rejected** here, because it
would expose FTP credentials in transit. This ADR therefore holds the Census transport to a
*higher* standard than the repository's long-established operational transport, not a lower
one.

---

## What transport is actually used

For temporary probe-file transfer (upload and cleanup):

```
lftp -u "$FTP_USERNAME","$FTP_PASSWORD" "ftp://$FTP_SERVER" \
  -e "set ftp:ssl-allow yes; set ftp:ssl-force yes; set ftp:ssl-protect-data yes; \
      set ssl:verify-certificate no; ..."
```

**FTP over mandatory TLS (FTPS), with certificate trust verification relaxed.** If TLS cannot
be established the transfer **fails closed** — `ftp:ssl-force yes` guarantees there is no
plaintext fallback path.

Probe *execution* is unchanged and occurs over **HTTPS** (`curl` to `$BASE_URL/public/$NAME`
with the `X-Velora-Mgmt` token header), with normal certificate validation. **This ADR does
not relax HTTPS verification anywhere.**

---

## Security guarantees that remain

1. **Credentials encrypted in transit** — `ftp:ssl-allow yes` + `ftp:ssl-force yes`; no plaintext fallback.
2. **Data channel encrypted** — `ftp:ssl-protect-data yes`.
3. **HTTPS unchanged** — probe execution still uses fully verified TLS.
4. **Database operations strictly read-only** — `$READONLY_OPS = ['inspect','plan','verify']`; these paths run only `information_schema` / `@@` / `SHOW` metadata queries.
5. **No arbitrary SQL** — the probe accepts no `$_GET`/`$_POST`/`$_REQUEST`/`php://input` SQL. Only `__OP__`, `__ENV__`, `__HASH__`, `__DIR__` are substituted; all queries are repository-defined.
6. **Token gate** — `hash_equals()` timing-safe comparison of a SHA-256 hashed one-time token.
7. **Random filename** — `_velora_mgmt_$(openssl rand -hex 8).php`.
8. **Immediate self-deletion** — `@unlink(__FILE__)` on authorized execution.
9. **Second deletion mechanism** — workflow `cleanup()` runs under `trap cleanup EXIT` and removes the file over FTP regardless of outcome.
10. **Environment separation** — staging uses `STAGING_FTP_*` + `staging.veloratrade.ir`; production uses `FTP_*` + `veloratrade.ir`. Hard-coded per branch of the guard; never mixed, no fallback.
11. **No credentials in the repository** — all transport credentials are GitHub Actions secrets; GitHub masks them in logs. Database credentials are read server-side by the application's own `Database::connection()` and never leave the host.
12. **Mutating operations still gated** — `migrate`/`deploy` continue to require the exact `APPROVE-<env>-<mode>` token, `backup_verified=true`, and the protected `production` environment.

---

## Security limitation that remains

**The FTP control/data channel is encrypted but the server is not authenticated.** A
man-in-the-middle positioned between the GitHub runner and the hosting provider could in
principle present a substitute certificate and intercept the FTP session, obtaining the FTP
credentials and the probe file.

Residual risk assessment:

- **Exposure window:** seconds per run, only while a Census is actively dispatched.
- **Probe contents are non-secret** — repository-defined metadata queries; the template is public in this repository.
- **Database credentials are never transmitted** — they are read server-side; a MITM on the FTP channel does not obtain them.
- **Not a new exposure class:** 19 existing workflows (`deploy.yml`, `velora-db-backup.yml`, all staging migrations) already use `ftp:ssl-allow no` — full plaintext. This ADR's transport is **strictly stronger** than the repository's prevailing practice.
- **Accepted trade-off:** an unauthenticated-but-encrypted channel for a short-lived, read-only diagnostic, versus an indefinitely blocked Census and therefore a blocked migration programme.

**Compensating control:** should the FTP credentials ever be suspected of compromise, rotate
`FTP_*` / `STAGING_FTP_*` in GitHub Secrets. No repository change is required.

**Not accepted:** plaintext FTP (`ftp:ssl-allow no`) for the Census transport, and any
relaxation of HTTPS verification. Both remain prohibited for this workflow.

---

## Consequences

- The read-only Census is no longer blocked by certificate trust.
- Future agents **must not** reintroduce `ssl:verify-certificate yes` in `velora-mgmt.yml` as a mandatory prerequisite. The setting is deliberate and documented at the call site.
- No certificate files, certificate secrets, or certificate-management processes are introduced. Nothing must be provisioned, renewed, or rotated for Census to run.
- The database access model is **unchanged**. This ADR alters file-transport trust only.
- `ops/velora-mgmt/SECURITY.md` (2026-09-09) recorded the certificate failure as "a separate owner decision". **This ADR is that decision.**

---

## Explicitly out of scope

- `trade-migration-staging.yml` also sets `ssl:verify-certificate yes`. It is a **staging** workflow and is not on the Census path; it is left unchanged to keep this change minimal.
- Repairing certificate trust at the hosting provider — not required, and deliberately not made a prerequisite.

---

## Verification status

This ADR authorises the transport. It does **not** constitute evidence that a Census has run.

**No production database has been queried.** A real Production Census remains outstanding and
requires an explicit owner dispatch of `velora-mgmt.yml` with `environment=production`,
`mode=inspect`. Until that produces a JSON result, production schema state stays
**UNVERIFIED**.
