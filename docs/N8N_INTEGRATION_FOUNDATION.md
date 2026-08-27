# Velora n8n integration foundation

This document defines the **reusable, read-first** integration layer between
Velora/Cloud and a disposable n8n instance. It exists so that when the owner
deletes and recreates the n8n instance (~every 14 days), only per-instance
secrets/credentials have to be re-entered — everything else persists and
re-discovers itself.

## Architecture

```
Cloud / ChatGPT
        ↓
Existing n8n Integration   ← this foundation
        ↓
n8n instance               ← disposable; recreated periodically
```

The integration is **independent of workflow contents**. It is a connection +
discovery + capability layer, not a workflow.

## What persists in Git vs. what is entered per instance

| Item | Persisted in Git? | Where / who enters |
|---|---|---|
| Integration policy (capabilities, security, manual checklist, dynamic-ID rule) | ✅ `content/n8n-integration/integration.json` (+ schema) | committed |
| Read-only live client + discovery tooling | ✅ `tools/n8n_migrate/` | committed |
| SOURCE/TARGET base URLs | ❌ | env `N8N_SOURCE_BASE_URL` / `N8N_TARGET_BASE_URL` (set per instance) |
| SOURCE/TARGET n8n API keys | ❌ (secret) | env `N8N_SOURCE_API_KEY` / `N8N_TARGET_API_KEY` (owner creates in n8n UI) |
| Credential secrets (Telegram bot token, Google OAuth, OpenAI key) | ❌ (secret) | n8n UI, per new instance |
| n8n workflow / Data Table / credential **IDs** | ❌ (dynamic) | discovered at runtime by name — never stored |

## Security model

- **Minimum permissions.** Use read-only n8n API keys with only the scopes
  needed to list/read workflows, list credentials (metadata), and read Data
  Tables/columns.
- **No PATs.** GitHub PATs are never used for n8n integration and never placed
  inside n8n.
- **Secrets never in Git/chat.** API keys, OAuth tokens, bot tokens, passwords,
  private keys, JWTs, and credential `data` are forbidden from the repository,
  logs, errors, and reports. They exist only in the environment or the n8n UI.
- **Fail closed.** If base URL or API key is missing, or a read returns
  401/403/404/429, the tool stops and reports the condition — it never works
  around the restriction.

## Dynamic ID discovery

n8n IDs change every time the instance is recreated, so they are **never
hardcoded**. The foundation discovers them at runtime **by name**:

- workflow IDs ← by workflow name
- Data Table IDs ← by table name
- credential IDs ← by `(type, name)`

This satisfies the requirement that no workflow JSON or Data Table ID be
permanently tied to one disposable instance.

## Capabilities

| Capability | Status |
|---|---|
| read workflows (list) | enabled |
| read workflow definitions | enabled |
| list Data Tables | enabled |
| read table schemas | enabled |
| list credentials (metadata only) | enabled |
| compare SOURCE ↔ TARGET | enabled |
| create / update workflows | **disabled** |
| create / update Data Tables | **disabled** |
| activate / publish / execute | **disabled** |
| webhook management | **disabled** |

The disabled capabilities are intentional for this foundation (read-first).
Enabling them would be a separate, explicit owner decision.

## Verification command

Reads both instances and reports reachability, auth, which read scopes work,
and the currently discovered IDs — without writing anything.

```bash
python tools/n8n_migrate/migrate.py verify-connection \
  --allow-live-read \
  --config content/n8n-integration/integration.json \
  --out connection-report.json
```

### Environment variables required from the owner

| Variable | Required | Purpose |
|---|---|---|
| `N8N_SOURCE_BASE_URL` | yes | SOURCE n8n instance base URL |
| `N8N_SOURCE_API_KEY` | yes | read-only SOURCE n8n API key |
| `N8N_TARGET_BASE_URL` | yes | TARGET n8n instance base URL |
| `N8N_TARGET_API_KEY` | yes | read-only TARGET n8n API key |
| `N8N_LIVE_TIMEOUT` | no | request timeout seconds (default 30) |
| `N8N_ALLOW_HTTP` | no | `1` to allow `http://` (local testing only) |

The owner creates the read-only keys in each n8n instance (Settings → API) and
sets the env vars. Values stay out of Git and chat.

## What the owner must re-enter after creating a new n8n instance

1. Set `N8N_<INSTANCE>_BASE_URL` and `N8N_<INSTANCE>_API_KEY` for the new instance.
2. Create an n8n API key with minimal **read** scopes.
3. Re-create credential secrets in the n8n UI: Telegram bot token, Google OAuth,
   OpenAI key.
4. Confirm Google OAuth identity / GSC property access.
5. (When migration is authorized and ready) switch Telegram webhook ownership —
   SOURCE off first, then TARGET on. Never leave both receiving at once.

After steps 1–2, `verify-connection` will re-discover all IDs automatically;
nothing else needs to change.

## Reuse across instances

Because base URL + API key come from the environment and IDs are discovered by
name, pointing the foundation at a freshly recreated instance requires no code
change and no config edit — only new env values and the manual credential
re-entry above.

## Tests

```bash
python tools/n8n_migrate/test_integration.py   # integration foundation
python tools/n8n_migrate/test_live_client.py   # live read-only client
python tools/n8n_migrate/test_migrate.py       # existing Phase 1/2
```

All run offline; none touch a live instance.
