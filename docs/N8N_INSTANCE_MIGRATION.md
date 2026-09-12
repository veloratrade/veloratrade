# n8n instance migration — OLD → NEW (Claude-direct contract)

This document is the **authoritative contract** for migrating n8n workflows and
Data Tables from the current (**OLD**) n8n instance to a new (**NEW**) n8n
instance.

> **Retired tooling (historical note).** A previous Python preparation
> framework (`tools/n8n_migrate/`, `content/n8n-migrate/`,
> `content/n8n-integration/`) existed for offline migration planning. It is
> **retired and deleted** and must NOT be recreated. The migration mechanism
> is now the procedure in this document: Claude working directly against the
> OLD and NEW n8n APIs. Its safety rules were carried over into §§9–14 and
> §19 below; nothing else from it survives.

Related but **separate** active systems (never merged with instance
migration):

- Article archive: `docs/N8N_ARCHIVE_AGENT.md`, `docs/N8N_ARCHIVE_INGEST.md`,
  `tools/n8n_archive/`, `content/n8n-archive/`.
- Shared n8n API connection policy: `docs/N8N_INTEGRATION_FOUNDATION.md`.
- Agent policy: `AGENTS.md` §§2.3–2.4, §11.

---

## 1. Purpose

Define a reusable, owner-gated procedure for Claude to inventory OLD and NEW,
compare them, propose a transfer plan, perform only owner-approved transfers,
verify the result, and report. No permanent migration framework lives in this
repository; the logic lives in this procedure plus direct n8n API interaction.

## 2. Source of truth

| Concern | Authority |
|---|---|
| Live OLD workflows / tables / executions / settings | The live **OLD** n8n instance |
| Live NEW workflows / tables / settings | The live **NEW** n8n instance |
| Migration decisions for this run | The owner-approved transfer plan (§16) + this contract |
| Human article approval | n8n archive (**separate system**, see §3) |

Repository snapshots, exports, notes, or chat history are **never**
automatically authoritative over a live n8n instance. When in doubt, re-read
the live instance.

## 3. Old → New migration architecture

```text
Claude
  ↓  (owner-provided OLD credentials, runtime only)
OLD n8n API → read-only inventory
  ↓  (owner-provided NEW credentials, runtime only)
NEW n8n API → read-only inventory
  ↓
comparison / classification (§§9–10)
  ↓
owner-approved transfer plan (§16)
  ↓
controlled transfer (approved actions only)
  ↓
post-transfer verification (§17)
  ↓
final report (§18)
```

- **Claude connects directly to OLD and NEW n8n using user-provided API
  credentials.** The owner supplies the OLD and NEW instance base URLs and
  API tokens at session start. They are runtime-only: never written to Git,
  chat logs, files, manifests, reports, or workflow JSON.
- **Archive and Gemini Relay are separate active systems.** The n8n Archive
  (approved-article pipeline) is NOT the same thing as instance migration.
  Do not reuse archive tooling to copy instance graphs, and do not reuse
  this migration procedure to process archive articles.
- The Gemini Relay (Velora runtime → Gemini via n8n) is untouched by
  migration except as noted by the owner.

## 4. Read-only discovery phase

Before any plan or transfer:

1. Confirm both credential sets are present (fail closed if missing).
2. Confirm read access on OLD (list workflows, read one workflow definition,
   list Data Tables, read table schemas, list credential metadata).
3. Confirm read access on NEW (same checks).
4. Record instance labels, n8n versions (if exposed), and discovery
   timestamps.
5. If any read fails (auth, permission, network): STOP, report the exact
   failing check, and wait. Never work around a restriction.

Discovery performs **zero writes** on either instance.

## 5. Workflow inventory

For each instance, record per workflow:

- name, live id, active flag, version id (if exposed)
- node names + types + `typeVersion`, connections, settings (timezone,
  executionOrder), retry/error flags, disabled flags
- trigger nodes: Telegram triggers, schedule triggers, webhook nodes
  (**record webhook existence, never copy webhook ids**)
- referenced Data Tables (by id → resolve to name) and credentials
  (by id → resolve to type + name)

Exclude execution residue (`pinData`, `staticData`) from comparison material.

## 6. Data Table inventory

For each instance, record per table: name, live id, columns (name + type +
required), row count (count only; row content only if the owner later
authorizes row transfer).

Canonical Velora table names (compare by **name**, never by id):

`seo_scan_snapshots`, `seo_gsc_snapshots`, `seo_opportunities`,
`seo_run_log`, `seo_article_drafts`, `seo_article_archive`.

Row-transfer idempotency columns (only relevant if row copy is ever
authorized): `seo_scan_snapshots.id`, `seo_gsc_snapshots.id`,
`seo_opportunities.opportunity_id`, `seo_run_log.id`,
`seo_article_drafts.draft_id`, `seo_article_archive.archive_id`.
Row copy is **opt-in per run** and never assumed.

## 7. Credential metadata inventory

Record per credential **metadata only**: type, name, id, used-by (node
names). Credential secret values (`data`, OAuth tokens, bot tokens, keys)
are **never fetched, never copied, never printed, never stored**.

## 8. Environment/variable inventory (if applicable)

If either instance exposes variables / environment / external secrets
configuration relevant to the workflows in scope, inventory them by name and
presence only (never values). If the n8n plan/API exposes nothing here,
record "no variable surface observed" and continue.

## 9. Comparison rules

1. **Match by stable business identity, never by id.** Workflows by name;
   Data Tables by name; credentials by (type, name).
2. **Workflow IDs are dynamic. Data Table IDs are dynamic. Credential ids
   are dynamic.** IDs must be discovered from the live instance on every
   run; never persist, hardcode, or assume them.
3. Compare workflow structure: nodes (name/type/version), parameters and
   expressions, connections, settings, retry/error and disabled flags.
   Ignore volatile fields (ids, webhook ids, execution residue).
4. Compare table schemas: column name + type + required. Any difference is
   a schema mismatch → STOP for that table (§19).
5. Compare credential (type, name) presence on NEW. Missing credentials
   follow §11.
6. **No guessing of workflow/table/credential mappings.** If identity is
   ambiguous (duplicate names, renamed candidates, fuzzy matches),
   classify as `unresolved` and STOP that item (§10, §19).
7. **Existing NEW workflows must be compared before overwrite.** Same name
   + same structure → no-op. Same name + different structure → conflict;
   never overwrite without explicit owner approval for that item.

## 10. Classification

Every in-scope item ends in exactly one class:

| Class | Meaning |
|---|---|
| `transfer` | Missing on NEW (or owner-approved refresh); will be created/updated after gate |
| `skip` | Deliberately not transferred (disabled draft, superseded, out of scope) with stated reason |
| `already_present` | Exists on NEW with matching structure/schema → no-op |
| `conflict` | Same identity, different structure/schema/content → needs owner decision; default: no overwrite |
| `obsolete` | Exists on NEW but no longer on OLD (or retired) → report only; deletion needs explicit approval |
| `unresolved` | Mapping ambiguous or evidence missing → STOP; never guess |

## 11. Credential handling

- Credentials are **not transferable**. Only (type, name, presence on NEW)
  is inventoried and reported.
- The owner creates every NEW credential secret **manually in the NEW n8n
  UI**. Claude only reports which ones are needed and where each is used.
- Missing credential on an **enabled** node → STOP that workflow's transfer
  until the owner creates the credential or orders the node left disabled
  and disconnected.
- Missing credential on a **disabled** node → warning during planning;
  STOP at transfer unless the owner explicitly accepts the gap.
- Match order: exact (type, name) first; otherwise report
  `missing_on_target` / `name_mismatch` / `ambiguous` — never fuzzy-remap
  silently.

## 12. Secret handling

- **Credential secret values are never copied or printed.** Not in chat,
  reports, plans, exports, logs, errors, or files.
- Secrets (API tokens, OAuth tokens/refresh tokens, bot tokens, private
  keys, JWTs, webhook secrets, GitHub PATs) are never committed to Git
  (§22), never placed inside workflow JSON, and never embedded in URLs.
- A GitHub PAT must never appear in n8n parameters. If detected: STOP and
  report.
- Owner-provided OLD/NEW API credentials are session-scoped and
  minimal-scope (read for discovery; write only if a transfer is approved
  and the owner accepts the scope).

## 13. Telegram webhook safety

- **Telegram webhook ownership must never be duplicated.** Only one
  instance may own the bot webhook at a time.
- Transferred Telegram Triggers are created **disabled** on NEW. Enabling
  is a manual owner action (§15), only after OLD ownership is switched off.
- Webhook ids are never copied between instances; NEW mints its own.
- Migration never sends Telegram messages and never executes workflows.

## 14. Activation/publish safety

- Transferred workflows are created **inactive / unpublished** on NEW.
- Never auto-activate, auto-publish, or auto-execute as part of migration.
- Schedule triggers (including P1 / P2A / P2B patterns) are created
  **disabled** on NEW; enabling is a manual owner action.
- `pinData` / `staticData` are never transferred (execution residue).

## 15. Manual owner actions

Only the owner (never Claude unilaterally):

- creates NEW credential secrets in the n8n UI (Telegram bot token,
  Google OAuth, OpenAI key, others);
- confirms Google OAuth identity / GSC property access on NEW;
- switches Telegram webhook ownership (OLD off first, then NEW on);
- enables Telegram Triggers or schedule triggers on NEW;
- publishes / activates NEW workflows;
- authorizes any deletion on either instance;
- authorizes any row-data copy (with idempotency columns from §6).

## 16. Transfer authorization gate

No transfer action happens before **all** of these hold:

1. Read-only discovery (§4) and full inventories (§§5–8) are complete.
2. Comparison (§9) and classification (§10) are reported to the owner.
3. The owner explicitly approves the transfer plan — which items, which
   conflicts resolved how, and which unresolved items stay stopped.
4. Credential gaps are resolved per §11 (created or explicitly accepted).
5. No STOP condition (§19) is open for the approved items.

Approval is per-run and per-item. Approval of one run never authorizes the
next. Scope creep (extra workflows, rows, deletions) requires fresh
approval.

## 17. Post-transfer verification

After performing approved transfers, verify against NEW (read-only):

- node names/types/`typeVersion`, connections, settings (timezone,
  executionOrder), retry/error and disabled flags match the approved plan;
- Data Table references point to NEW table ids; credential references
  point to NEW credential ids;
- `active` is false; Telegram/schedule triggers are disabled;
- no OLD webhook ids exist on NEW;
- no secret material was written anywhere (spot-check payloads before
  sending: no `data`, tokens, keys, or PATs).

Record every verification check as pass/fail in the final report. Any fail
→ report + STOP; do not self-remediate by further writes without approval.

## 18. Final report requirements

Every migration run ends with a report containing, at minimum:

- **transferred**: item, OLD identity → NEW identity (ids), checks passed
- **skipped**: item + reason
- **already present**: item (no-op, evidence of match)
- **missing**: required on NEW but absent/blocked (e.g. credential gaps)
- **conflicting**: item + nature of conflict + resolution or deferral
- **unresolved**: item + what is ambiguous + what is needed to proceed
- **requiring user decision**: anything not decided in this run
- errors encountered (sanitized, no secrets), verification table, and the
  exact approved plan that was executed

## 19. Stop conditions

STOP the affected item (or the run, if marked run-wide) when any holds:

**Never (run-wide prohibitions, carried over from the retired tooling):**

- `write_to_old` — any mutating call to OLD outside an explicitly approved
  transfer step (discovery is read-only; OLD is normally never written)
- `extract_credential_secrets` — reading or copying credential secret values
- `put_github_pat_in_n8n` — GitHub PAT in any n8n parameter
- `activate_or_publish_automatically` — any auto activate/publish/execute
- `execute_workflows_as_part_of_migration`
- `send_telegram_messages`
- `enable_schedule_triggers` — without explicit per-trigger owner approval
- `enable_telegram_trigger_on_new_while_old_owns_webhook`
- `copy_webhook_ids_between_instances`
- `delete_old_or_new_resources` — without explicit per-item owner approval

**Item stops:**

- `UNMAPPED_CREDENTIAL` — a node credential has no NEW equivalent (§11)
- `UNMAPPED_DATATABLE` — a table reference has no NEW table mapping
- `SCHEMA_MISMATCH` — OLD and NEW table schemas differ; never coerce types
- `TELEGRAM_TRIGGER_WOULD_ENABLE` / `SCHEDULE_TRIGGER_WOULD_ENABLE` /
  `WORKFLOW_WOULD_ACTIVATE` — prepared NEW state violates §§13–14
- `PUBLISH_OR_ACTIVATE_REQUESTED` — outside the authorization gate
- `DUPLICATE_WORKFLOW_DRIFT` — NEW already has the name with different
  structure (§9.7)
- `WEBHOOK_ID_COPY` — an OLD webhook id would be carried to NEW
- `GITHUB_PAT_IN_N8N` — GitHub PAT detected in workflow parameters
- `CREDENTIAL_SECRET_PRESENT` / `SECRET_MATERIAL_IN_EXPORT` — secret
  material detected in any payload, export, or report draft
- `AMBIGUOUS_MAPPING` — identity cannot be established without guessing
- `NOT_AUTHORIZED` — any transfer step lacking §16 approval

When stopped: report the code, the item, the evidence, and the precise
owner decision needed. Then wait.

## 20. No-production-data rule

- No production Velora application data is in scope for n8n instance
  migration. n8n Data Table **row content** is copied only if the owner
  explicitly authorizes row transfer for named tables (§§6, 15–16).
- **Production data is not copied into velora-modern** as part of this
  procedure.

## 21. No-database-migration rule

- **The production Velora database is not part of this migration.** No
  schema change, no data move, no backup/restore, no seed, no
  deploy-adjacent database work is authorized by this contract.
- Database migration is OUT OF SCOPE. If database work is ever requested,
  it follows its own owner-authorized procedure (including BACKUP GATE
  rules where applicable) — never this document.

## 22. No-Git-secret rule

- **Credentials/secrets are never committed to Git.** No tokens, keys,
  bot tokens, OAuth material, JWTs, private keys, webhook secrets, or
  credential values in the repository, in any branch, commit message,
  PR text, log, artifact, or report file.
- Discovery notes and migration reports kept anywhere near Git must be
  secret-free (names and metadata only).

## 23. Recovery / rollback expectations

- Prefer non-destructive recovery: disable or rename a wrongly transferred
  NEW workflow rather than deleting; deletions need explicit approval (§19).
- Keep the pre-transfer NEW inventory (§§5–8) so any transfer can be
  diffed back.
- If a transfer violates §§13–14 after the fact (e.g. a trigger got
  enabled), immediately disable it if the owner authorizes the corrective
  write; otherwise STOP and report.
- Webhook ownership mistakes (both instances receiving) are P0: report
  immediately and take no corrective write without explicit approval.

## 24. Continuation state for future Claude sessions

A new session must be able to resume from this document plus a short
handoff. Record per run (outside Git, or in a secret-free Git note):

```text
RUN: <date> | OLD: <label> | NEW: <label>
DISCOVERY: complete/partial/failed (+ failing checks)
CLASSIFICATION: transfer=N skip=N already_present=N conflict=N obsolete=N unresolved=N
GATE: approved/pending/blocked (+ plan reference)
TRANSFERRED: <items> | VERIFICATION: pass/fail per item
OPEN: <unresolved/conflicts/decisions with codes from §19>
NEXT SAFE STEP: <one sentence>
```

Current standing state:

- Old Python migration framework: **retired and deleted** (see historical
  note at top). Do not recreate.
- Mechanism: Claude-direct via OLD/NEW n8n APIs with owner-provided
  runtime credentials.
- Archive, Archive ingest, and Gemini Relay: **active, separate systems**;
  this contract does not govern them.
- No standing transfer approval exists. Every run needs its own §16 gate.
