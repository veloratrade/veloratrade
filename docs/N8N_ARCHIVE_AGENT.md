# N8N Archive Agent — Velora v1

This contract is mandatory when the owner’s task involves n8n article archives,
archived/approved articles, n8n → GitHub content processing, or publishing
articles that originated in n8n.

n8n is the **source of truth for human approval**.
GitHub is the **source of truth for the website version**.

The agent must never decide that an article is approved.

---

## 1. Eligibility (hard gate)

Process a snapshot **only if all** are true:

| Field | Required value |
|---|---|
| `approval_status` | `approved` |
| `archive_status` | `archived` |

Otherwise:

- DO NOT process
- DO NOT generate `blog/**` or `en/blog/**` pages
- DO NOT open an article PR
- Record `github_process_status=blocked` only if a snapshot file already exists and the owner asked for a ledger update

`publication_status` must be one of: `not_published`, `published`, `failed`.
v1 processing is allowed only when `publication_status` is `not_published` (or omitted, treated as `not_published`).

---

## 2. Where archive data lives in Git

| Path | Role |
|---|---|
| `content/n8n-archive/schema.json` | Strict snapshot schema |
| `content/n8n-archive/snapshots/<archive_id>.json` | One immutable snapshot per article (idempotency key = filename/`archive_id`) |
| `content/n8n-archive/state/<archive_id>.json` | Per-article Git process ledger (avoids a single contested `processed.json`) |
| `content/n8n-archive/index.json` | Public, secret-free catalog (ids, slugs, hashes, statuses only) |
| `content/n8n-archive/README.md` | Operator notes |

There is **no** n8n Data Table inside this repository. Snapshots are exports.
Do not give the agent n8n JWT/MCP credentials.

---

## 3. How to identify an approved article

An article is eligible **only** from snapshot fields written by n8n (or a
verified export of n8n):

- `approval_status === "approved"`
- `archive_status === "archived"`

Do not infer approval from Telegram screenshots, HTML quality, or model judgment.

---

## 4. Newly archived vs already processed

1. List `content/n8n-archive/snapshots/*.json`.
2. Run `python tools/n8n_archive/validate_snapshot.py <file>`.
3. Read `content/n8n-archive/state/<archive_id>.json` if present.
4. Skip (no-op) when state says `deployed` / `pr_open` / `skipped` **and**
   `content_sha256` matches the snapshot.
5. If `archive_id` matches but `content_sha256` differs: stop and ask the owner
   (content changed after archive; do not silently overwrite the site).

---

## 5. Idempotency

- Primary key: `archive_id`
- Fingerprint: `content_sha256` (SHA-256 hex of UTF-8 `article_html`)
- Same `archive_id` + same hash → **no-op**
- An existing website path (`blog/<slug>/`, `en/blog/<slug>/`) is **not**
  permission to overwrite. Owner must explicitly authorize overwrite.
- Do not use one shared `processed.json` as the lock for concurrent articles.

---

## 6. Rebuild, do not paste raw n8n HTML

Before creating pages, inspect:

- A representative FA article: `blog/what-is-trading-journal/index.html`
- Its EN pair: `en/blog/what-is-a-trading-journal/index.html`
- `blog/index.html` and `en/blog/index.html`
- `sitemap.xml`
- `CLAUDE.md` (i18n)
- `docs/05_BILINGUAL_CHECKLIST.md` when touching user-facing copy

Rebuild into the **existing Velora article shell** (nav, gold/dark theme,
`data-i18n`, canonical, OG, FAQ, CTA, related, footer).

Do **not** copy n8n standalone HTML (or its `#velora-status` aside) as the site page.
Do **not** hand-edit `localized/**` (NP-5). Use the existing catalog + build pipeline.

Language: produce **FA and EN** in the same change. Add every new UI string to
both `public/locales/fa.json` and `public/locales/en.json`.

---

## 7. Quality / SEO (minimum)

- Exactly one `h1`
- At least three `h2` sections
- FAQ when the source snapshot includes FAQ or the template requires it
- One CTA to Velora as a **trading journal** (never signals, “start investing”, profit guarantees)
- Disclaimer: not financial advice; no guaranteed returns
- `title`, meta description, canonical, `og:title` / `og:description` / `og:url` / `og:type=article`
- Slug `[a-z0-9-]+`
- Internal links only to existing Velora paths (`/`, `/blog`, `/en/blog`, product pages)

If facts, stats, or sources are missing: research **authoritative** sources when
the owner permitted research; otherwise mark `blocked` and report gaps.
**Never invent** facts, statistics, quotes, or citations.

---

## 8. GitHub output (v1)

Allowed end state: a **controlled PR** with the minimum files:

- `blog/<fa-slug>/index.html`
- `en/blog/<en-slug>/index.html`
- blog indexes if a new card is required
- `sitemap.xml`
- locale catalogs for new keys
- `content/n8n-archive/state/<archive_id>.json`
- `content/n8n-archive/index.json` (catalog only)

Forbidden:

- Direct push to production hosting
- Running `deploy.yml` / `deploy-staging.yml` without explicit owner approval
- Modifying n8n Generate/Approve/Telegram trigger
- Embedding secrets, Telegram IDs, JWTs, PAT values

---

## 9. Deployment

Staging and Production stay **manual** (`workflow_dispatch`, Production confirm
phrase). This agent does not deploy.

After a successful Production deploy **with evidence** (Actions run id + HTTP 200
on the live URL), n8n `publication_status` may be updated to `published` by a
separate, owner-approved process — not by Claude guessing.

---

## 10. What the agent may modify vs never modify

**May (after owner approval for the article task):** files listed in §8.

**Never:** `api/` auth/billing, `velora.env`, FTP/deploy workflow logic,
Telegram/n8n production graphs, `localized/**` by hand, unrelated dashboard/admin
files, GitHub Actions enablement except an owner-approved job.

---

## 11. Failures

Report short, sanitized:

```
archive_id: …
gate: eligible | blocked
reason: …
files_touched: none | list
next: …
```

Do not paste snapshot HTML, logs, or secrets.

---

## 12. Phase 1 vs later

Phase 1 (this directory + validator + this contract) does **not** connect n8n
to GitHub. Live ingest uses a GitHub App (contents + pull requests) **server-side**,
never a PAT inside n8n. That ingest is Phase 2 and requires a separate owner order.
