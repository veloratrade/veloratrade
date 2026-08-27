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
shared `processed.json`.

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
