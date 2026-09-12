# Velora n8n integration policy (Claude ↔ n8n API)

Small reusable policy for **every** direct Claude ↔ n8n API contact:
instance migration (`docs/N8N_INSTANCE_MIGRATION.md`) and read-only live
reads (e.g. archive state checks per `AGENTS.md` §2.4).

> **Retired companions (historical note).** The machine-readable companions
> `content/n8n-integration/` (`integration.json`, schema) and the Python
> connection tooling (`tools/n8n_migrate/`) are **retired and deleted**.
> Their policy is preserved here in prose. No permanent migration CLI
> exists; do not create one.

## 1. Credential provisioning

- The owner provides n8n base URL + API token **at session start, per
  instance** (OLD and NEW for migration; live instance for archive reads).
- Credentials are **runtime-only**: never written to Git, chat logs,
  files, manifests, reports, workflow JSON, or URLs.
- Only credential **names** (`OLD`/`NEW` instance labels) appear in
  reports — never values.

## 2. Minimum permissions

- Discovery and inventory use **read-only** access with the minimum scopes
  needed (list/read workflows, list credential *metadata*, read Data
  Tables/columns).
- Write-capable tokens are used only inside an explicitly owner-authorized
  transfer step, and only against the approved instance.

## 3. Transport and fail-closed behavior

- HTTPS only. Requests carry a bounded timeout; auth material is never
  logged.
- Missing credentials, denied scopes, or unreachable instances are hard
  stops: report the exact failing check and wait. Never work around a
  restriction, never retry with elevated scope unasked.

## 4. Dynamic IDs

- Workflow, Data Table, credential, and webhook ids are **dynamic**: they
  change when an instance is recreated (~every 14 days).
- IDs are discovered from the live instance **by name** on every run and
  are never hardcoded, persisted, or assumed valid across runs.

## 5. Read-first, owner-authorized writes

- Default posture is **read-only**. Any write (create, update, delete,
  activate, publish, execute, webhook change) needs explicit per-action
  owner authorization under the governing contract:
  - migration writes → `docs/N8N_INSTANCE_MIGRATION.md` §16 gate;
  - archive processing → `docs/N8N_ARCHIVE_AGENT.md` + `AGENTS.md` §2.2.

## 6. Pointers (no duplication)

- Migration procedure, classification, safety, and reporting:
  `docs/N8N_INSTANCE_MIGRATION.md`.
- Archive eligibility, read evidence, and publishing rules:
  `docs/N8N_ARCHIVE_AGENT.md`, `docs/N8N_ARCHIVE_INGEST.md`.
- Agent mandates and sources of truth: `AGENTS.md` §§2.2–2.4, §11.
