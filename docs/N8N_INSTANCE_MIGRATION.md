# n8n instance migration — SOURCE → TARGET (preparation)

This is the contract for **future** migration of n8n workflows and Data Tables
from a temporary SOURCE instance to a TARGET instance.

**This document does not authorize a migration.** Preparation tooling never
writes to SOURCE or TARGET, never activates, never publishes, never executes
workflows, never sends Telegram, and never copies credential secrets.

Related but **separate** system: article archive snapshots live under
`docs/N8N_ARCHIVE_AGENT.md` and `tools/n8n_archive/`. Do not reuse that
pipeline to copy instance graphs.

---

## 1. Goals

Reusable, idempotent, dry-run-first procedure that can later:

1. Copy workflow **structure** (nodes, versions, parameters, expressions,
   connections, settings, retry/error, disabled flags, relevant metadata).
2. Copy Data Table **names, schemas, columns, types, required flags**, and
   (when explicitly authorized later) row data with remapped table IDs.
3. Produce a credential **mapping report** (name + type only) so the owner
   can create TARGET secrets by hand.

## 2. Non-goals / never

- Transfer credential secret values (`data`, OAuth tokens, bot tokens, keys).
- Put a GitHub PAT inside n8n.
- Auto-activate or publish TARGET.
- Enable Telegram Trigger on TARGET while SOURCE owns the bot webhook.
- Enable P1 / P2A / P2B schedule triggers unless the owner later orders it.
- Execute workflows as part of migration.
- Delete SOURCE, TARGET, credentials, or Google OAuth clients.
- Commit/push this tooling unless the owner explicitly authorizes Git.

## 3. How the future migration will work

Default modes:

| Mode | SOURCE | TARGET | Writes |
|---|---|---|---|
| `inspect` | read-only export | read-only export | none |
| `compare` | read-only | read-only | none |
| `dry-run` | read-only | plan only | none |
| `validate` | read-only | read-only | none |
| `apply` | **forbidden in preparation CLI** | **forbidden** | none |

Live n8n HTTP is **off**. Operators pass JSON exports:

```bash
python tools/n8n_migrate/migrate.py dry-run \
  --source-workflow tools/n8n_migrate/fixtures/source_workflow.json \
  --source-tables tools/n8n_migrate/fixtures/source_tables.json \
  --source-credentials tools/n8n_migrate/fixtures/source_credentials.json \
  --target-tables tools/n8n_migrate/fixtures/target_tables.json \
  --target-credentials tools/n8n_migrate/fixtures/target_credentials.json \
  --out /tmp/n8n-migrate-manifest.json
```

Later, a **separate owner order** may add a live read-only client. That client
must still refuse SOURCE writes, TARGET writes without two apply flags, and
all activate/publish/execute calls.

### Phase 3A — live read-only client (added)

`tools/n8n_migrate/live_client.py` (transport) + `tools/n8n_migrate/live_report.py`
(report builder) implement a **strictly read-only** live inspection against the
real instances, driven by `migrate.py live-inspect --allow-live-read`.

- Uses the n8n Public REST API (`/api/v1/*`) with `X-N8N-API-KEY` from the
  environment (`N8N_SOURCE_BASE_URL` / `N8N_SOURCE_API_KEY` /
  `N8N_TARGET_BASE_URL` / `N8N_TARGET_API_KEY`); fails closed if missing.
- Only `GET`/`HEAD`; refuses `POST`/`PUT`/`PATCH`/`DELETE`, activation, publish,
  execution, and webhook registration.
- Credentials are inspected via the **list** endpoint only (`id`/`name`/`type`);
  secret `data` is never fetched and defensively dropped.
- Data Tables are read via `GET /api/v1/data-tables` and
  `GET /api/v1/data-tables/{id}/columns`; row content is never pulled into a
  report (row counts only if `N8N_INCLUDE_ROW_COUNT=1`).
- This is inspection only. It still performs **zero** writes and does not apply
  any migration. Offline tests: `tools/n8n_migrate/test_live_client.py`.

## 4. Workflow migration procedure (future, not now)

1. Export SOURCE workflow JSON (nodes, connections, settings). Do not export
   `pinData` / `staticData` (execution residue).
2. Inspect: node types + `typeVersion`, parameters, expressions (`=...`),
   `disabled`, `onError` / `retryOnFail` / `maxTries` / `waitBetweenTries`,
   timezone, `executionOrder`.
3. Build Data Table ID map (SOURCE id → name → TARGET id).
4. Build credential map (type + name → TARGET id). Owner must already have
   created TARGET credentials **manually**.
5. Prepare TARGET payload:
   - remap `dataTableId.value`
   - remap credential `id` (name unchanged)
   - strip every `webhookId` (TARGET must mint its own)
   - `active=false`
   - `telegramTrigger.disabled=true`
   - `scheduleTrigger.disabled=true`
   - drop `parentFolderId` / `pinData` / `staticData`
6. Dry-run plan: create vs no-op vs STOP on drift.
7. Only after an explicit owner apply order: create/update TARGET **unpublished**.
8. Post-migration structural compare (see §8). Do not publish.

## 5. Data Table migration procedure (future, not now)

1. List SOURCE tables by **name** (canonical Velora names):
   `seo_scan_snapshots`, `seo_gsc_snapshots`, `seo_opportunities`,
   `seo_run_log`, `seo_article_drafts`, `seo_article_archive`.
2. Compare schema: column name, type, required.
3. If TARGET table missing: plan create with the same schema (dry-run).
4. If schema mismatch: **STOP**. Do not coerce types.
5. Record SOURCE id and TARGET id in the manifest.
6. Rewrite workflow `dataTableId` references using TARGET ids.
7. Row copy is **opt-in** (`--include-rows`) and still a no-op in preparation.
   Future row copy must use per-table idempotency columns (see
   `content/n8n-migrate/config.example.json`) and must not invent values.

n8n Data Table nodes drop unknown fields (for example Telegram context).
Preserve-context Code nodes in the workflow graph must be copied with the
graph; do not “clean” them.

## 6. Credential mapping procedure

Credentials are **not transferable**.

```
SOURCE credential:  Telegram account / telegramApi
        ↓  inspect name + type only (never `data`)
TARGET credential:  Telegram account / telegramApi   ← owner pastes secret in UI
        ↓  mapping report: SOURCE id → TARGET id
Claude/tool rewrites node.credentials[type].id
```

Match order:

1. Exact `(type, name)`.
2. Otherwise `missing_on_target` / `name_mismatch` / `ambiguous`.

Rules:

- Missing credential on an **enabled** node → STOP.
- Missing credential on a **disabled** node (example: OpenAI draft) → warning
  on dry-run; STOP on apply until the owner maps or leaves the node disabled
  and disconnected.
- Never copy, print, or store secret values, OAuth refresh tokens, or bot tokens.
- GitHub PAT must never appear in n8n parameters (`GITHUB_PAT_IN_N8N`).

## 7. Safety checks

Always on:

- SOURCE read-only (`SOURCE_WRITE_FORBIDDEN`)
- TARGET dry-run unless a future apply CLI exists **and** both flags are set:
  `--i-understand-this-writes-to-target` and `--owner-authorized-apply`
- Preparation CLI still refuses apply even with those flags
- No activate / publish / execute / Telegram send / delete
- Secret-material scan on exports and manifests
- Telegram Trigger forced disabled on TARGET payload
- Schedule triggers forced disabled on TARGET payload
- webhook IDs never copied (one bot → one webhook; SOURCE currently owns it)
- Duplicate TARGET workflow name + different `logic_sha256` → STOP
- Same name + same prepared fingerprint → no-op

STOP codes: `content/n8n-migrate/stop_conditions.json`.

## 8. Dry-run procedure

1. Collect exports (or, later, live **GET** only).
2. `python tools/n8n_migrate/migrate.py dry-run ...`
3. Read manifest: blockers, credential mapping, table ids, checksums, planned ops.
4. If blockers: fix mapping/schema/credentials in TARGET UI; re-run dry-run.
5. Do not run apply.

## 9. Validation procedure

Pre-migration:

- workflow inspect
- table schema compare
- credential mapping report
- secret scan
- prepared-payload safety (`assert_prepared_safe`)

Post-migration (future, after an authorized apply):

- node name/type/`typeVersion` match
- connections match
- settings timezone / executionOrder match
- retry/error flags match
- disabled flags match except known gates (Telegram/schedule forced off)
- table IDs are TARGET ids
- credential IDs are TARGET ids
- `active` is false
- no SOURCE webhook IDs

## 10. Manifest

Schema: `content/n8n-migrate/manifest.schema.json`.

Required report fields: SOURCE/TARGET workflow id+name, checksums, Data Tables
used with SOURCE and TARGET ids, credential names/types and mapping status,
migration status, validation status, differences, errors, blockers.

`writes_performed` must stay `[]` until a future authorized apply.

## 11. What remains manual

- Creating TARGET credentials in the n8n UI and pasting secrets.
- Confirming Google OAuth identity / GSC property access.
- Switching the Telegram bot webhook (never as part of this prep; SOURCE
  currently owns it; both instances must not receive at once).
- Enabling Telegram Trigger or schedules.
- Publishing / activating TARGET.
- Executing article E2E (Generate → Approve → Archive).
- Git commit/push of this tooling.
- Connecting n8n to GitHub archive ingest (different system; GitHub App, no PAT).

## 12. Tests

Offline only:

```bash
python tools/n8n_migrate/test_migrate.py
python tools/n8n_migrate/migrate.py dry-run --source-workflow ... 
```

No live SOURCE/TARGET calls in preparation.

---

## 13. Continuation state (for a new agent session)

Read this section first. Do not rely on chat history.

### CURRENT STATE:

- Phase 1 archive contract: committed
- Phase 2 archive ingest foundation: committed
- n8n instance migration preparation: committed
- Secrets are NOT transferable/exportable and must be entered manually in the new n8n instance
- Workflows and Data Tables are intended to be migrated
- Credentials are mapped by name/type only; secret values are never copied
- Migration tooling is preparation/dry-run only
- SOURCE/TARGET have not been migrated
- No production changes

### NEXT SAFE STEP:

- Perform a READ-ONLY inspection of SOURCE and TARGET n8n.
- Compare workflows, Data Tables, credential names/types, node references, and table IDs.
- Produce a migration manifest.
- Do NOT apply changes until explicitly authorized.

### Hard stops until the owner authorizes otherwise

- Do not modify SOURCE n8n.
- Do not modify TARGET workflows or tables.
- Do not execute workflows, send Telegram, activate, or publish.
- Do not create/install a GitHub App or connect n8n to archive ingest.
- Do not put a GitHub PAT inside n8n.
- Do not push this branch unless the owner asks.

Tooling: `tools/n8n_migrate/` (instance migration). Not `tools/n8n_archive/` (article snapshots).
