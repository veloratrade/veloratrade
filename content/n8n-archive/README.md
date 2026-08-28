# n8n article archive (GitHub snapshot)

Public, secret-free representation of **human-approved** articles archived in n8n.

## Purpose

Bridge n8n (approval source of truth) to GitHub (website source of truth)
without putting a GitHub PAT inside n8n and without giving Claude n8n JWT.

## Source of truth

| Concern | Authority |
|---|---|
| Human approval / archive | n8n Data Tables |
| Website HTML / i18n / sitemap | this Git repository |

Claude must not invent `approved`.

> ⚠️ An empty `snapshots/` directory means **no exports yet** — never "no
> archive exists". Live archive state requires a read-only read of the live
> SOURCE n8n instance (AGENTS.md §2.4); without access: stop and ask the owner.

## Eligibility

A snapshot is processable only when:

- `approval_status` = `approved`
- `archive_status` = `archived`

Anything else: do not build site pages.

## Security

Never commit:

- Telegram chat/user/thread IDs
- bot tokens, PAT, n8n JWT, OAuth tokens, passwords, API keys
- operator PII

Schema + `tools/n8n_archive/validate_snapshot.py` reject those patterns.

## Layout

```
content/n8n-archive/
  schema.json
  index.json                 # catalog only (ids, slug, hash, statuses)
  snapshots/<archive_id>.json
  state/<archive_id>.json    # per-article Git process ledger
  README.md
```

Independent `state/<archive_id>.json` files avoid merge conflicts from a single
shared `processed.json`. Each `state/<archive_id>.json` may also carry a
`read_evidence` block (see below).

## Archive Read Guard

`tools/n8n_archive/read_guard.py` enforces "read the archive before acting on
it". Policy authority is **AGENTS.md §2.2.1**; this section documents only the
mechanism.

- `read` — opens/reads a **canonical** `content/n8n-archive/snapshots/<id>.json`,
  derives `file_sha256` + `content_sha256` from the file, records the actual
  `read_range` / `bytes_read` / `lines_read`, integrity-checks against the
  snapshot's `content_sha256`, and emits `ARCHIVE_READ_EVIDENCE`.
- `verify` — recomputes the canonical file itself and rejects missing/mismatched
  hashes, missing/unreadable files, wrong ranges, wrong `guard_version`, malformed
  evidence, and any evidence that does not match current content. A JSON
  `"content_read_status":"verified"` is never proof by itself.
- `gate` — fail-closed WRITE gate for protected paths (`blog/`, `en/blog/`,
  `content/n8n-archive/snapshots/`, `content/n8n-archive/state/`): no valid
  `read_evidence` for an affected `archive_id` → FAIL. Also fails on
  unknown/ambiguous/missing classification and on an unresolved
  archive/current-source conflict — archive is evidence, not authority (§11).

Fail-closed: missing/unreadable/corrupt/hash-mismatched/secret-bearing archives
produce **no** evidence. Evidence means the file was actually read and
integrity-checked; it is **not** permission to write, publish, migrate or deploy,
and it does not replace owner authorization (§2.3 / §9).

In-repo limitation: the guard cannot prove the agent *understood* the content and
does not replace human judgment — ambiguity still means STOP + owner.

## Idempotency

- Primary key: `archive_id`
- Fingerprint: `content_sha256` of UTF-8 `article_html`
- Same id + same hash = no-op
- Existing `blog/` or `en/blog/` path is not overwrite permission

## Lifecycle (Git)

`pending` → `in_progress` → `pr_open` → `ci_passed` → `staged` → `deployed`
or `blocked` / `skipped`

`publication_status` in the snapshot stays `not_published` until an owner-approved
Production deploy provides evidence.

## Phase 1

Foundation only: schema, catalog, validator, agent contract.
No live n8n webhook and no GitHub App in this phase.
