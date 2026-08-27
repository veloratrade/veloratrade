# Velora n8n integration foundation

This directory holds the **non-secret, reusable** configuration for the Velora ↔
n8n integration. It is intentionally independent of any single disposable n8n
instance so that after the owner recreates the n8n instance (~every 14 days),
only the per-instance secrets/credentials need to be re-entered.

## Architecture

```
Cloud / ChatGPT
        ↓
Existing n8n Integration   ← this foundation (config + read-only client + discovery)
        ↓
n8n instance               ← disposable; recreated periodically
```

## Files

| Path | Purpose | Secrets? |
|---|---|---|
| `integration.json` | Canonical, non-secret integration policy (capabilities, security, manual checklist, dynamic-ID rule). Safe to commit. | No |
| `integration.schema.json` | JSON Schema validating `integration.json`. | No |
| `../n8n-migrate/` | Read-only live client + comparison tooling (`tools/n8n_migrate/`). | No secrets in code |

## Principles

1. **Non-secret config lives in Git** (`integration.json`); secrets live only in
   the environment or the n8n UI — never in Git, chat, or manifests.
2. **n8n IDs are dynamic.** Workflow / Data Table / credential IDs change on
   every recreation and are **discovered at runtime by name** — they are never
   hardcoded into configuration.
3. **Read-first.** The foundation verifies connections with the minimum
   read-only permissions. Write, activate, publish, and webhook capabilities
   are documented but **disabled** here.
4. **Reusable across instances.** Point the foundation at a new base URL + API
   key and it re-discovers everything; nothing is tied to a prior instance.

## Verification command

```bash
python tools/n8n_migrate/migrate.py verify-connection \
  --allow-live-read \
  --config content/n8n-integration/integration.json \
  --out connection-report.json
```

Per-instance values come from the environment (see `docs/N8N_INTEGRATION_FOUNDATION.md`).
This command only reads: it confirms reachability/auth, checks which read
scopes work, and reports the currently discovered IDs. It never writes.
