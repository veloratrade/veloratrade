# n8n archive ingest — Phase 2 infrastructure (not connected)

This document describes the **GitHub App + HMAC ingest** path.
n8n is **not** connected yet. No PAT is stored in n8n.

## GitHub App (create in GitHub UI — not in Git)

| Setting | Value |
|---|---|
| Name | Velora n8n Archive Ingest |
| Repository access | **Only** `veloratrade/veloratrade` |
| Contents | **Read and write** |
| Pull requests | **Read and write** |
| Metadata | Read (mandatory) |
| Actions | none |
| Administration | none |
| Secrets | none |
| Workflows | none |
| Install on | this repository only |

Installation token: short-lived (GitHub App JWT → installation access token). Never a PAT.

## Secret names (values never in Git)

Store only outside webroot (`velora_private/config/velora.env`) **or** GitHub Encrypted Secrets:

| Name | Purpose |
|---|---|
| `N8N_ARCHIVE_HMAC_SECRET` | HMAC-SHA256 key for ingest POST |
| `GITHUB_APP_ID` | GitHub App numeric id |
| `GITHUB_APP_INSTALLATION_ID` | Installation id on this repo |
| `GITHUB_APP_PRIVATE_KEY` | PEM private key (server-side only) |
| `GITHUB_APP_OWNER` | `veloratrade` |
| `GITHUB_APP_REPO` | `veloratrade` |

Do **not** put these in n8n workflow parameters, source, docs examples, or public files.

## HTTP contract (future n8n POST — not enabled)

`POST /` to a process bound **outside** `public_html/` (not `api/index.php`).

Headers:

- `Content-Type: application/json`
- `X-Velora-Timestamp`: Unix seconds
- `X-Velora-Signature`: hex HMAC-SHA256 of `{timestamp}.{raw_body}`

Limits: body ≤ 256 KiB. Timestamp skew ≤ 300 s. Signature replay rejected (hashed signature stored for 600 s).

Comparison: `hmac.compare_digest` (constant time).

## Why not the public PHP API

`api/src/Webhooks/MetaApiWebhookController.php` is a live MetaApi ingest on `/api/v1/*`.
It is **not** reused for n8n archive (different secret, different payload, would become a public production webhook on next deploy).

Phase 2 ships a Python library + tests under `tools/n8n_archive/`. Wiring n8n to a private listener is Phase 3.

## Normalization (n8n row → Phase 1 snapshot)

| n8n | snapshot |
|---|---|
| `APPROVED` | `approved` |
| `ARCHIVED` | `archived` |
| `NOT_PUBLISHED` | `not_published` |
| `faq_json` string | `faq` array or `[]` |
| `language` / `market` | `fa` \| `en` |
| `article_html` | SHA-256 → `content_sha256` |
| `approved_at` / `archived_at` | `created_at` / `archived_at` |

Forbidden keys (`telegram_*`, tokens, jwt, passwords) are **rejected**, not stripped silently.

## GitHub write

Branch `n8n-archive/<archive_id>` from `main`.
File **only**: `content/n8n-archive/snapshots/<archive_id>.json`
Then open a PR to `main`. No `blog/**`, sitemap, locales, or deploy files.

## Idempotency

- Same `archive_id` + same `content_sha256` → HTTP 200 no-op
- Same `archive_id` + different hash → HTTP 409, no overwrite
