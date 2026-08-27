# n8n instance migration (SOURCE → TARGET) — preparation only

This directory holds **contracts and examples** for migrating n8n **workflows**
and **Data Tables** between two n8n Cloud instances.

It is **not** the article-archive pipeline (`content/n8n-archive/`).

| Concern | Path |
|---|---|
| Article snapshots n8n → GitHub | `content/n8n-archive/` + `tools/n8n_archive/` |
| Instance workflow/table migration | this directory + `tools/n8n_migrate/` |

## Status

Preparation only. No live SOURCE/TARGET writes. No activation. No publish.
Credentials **secrets are never transferred**.

## Layout

```
content/n8n-migrate/
  README.md
  manifest.schema.json
  config.example.json
  stop_conditions.json
  manifest.example.json
tools/n8n_migrate/
  migrate.py            CLI (default: inspect / compare / dry-run)
  ...
docs/N8N_INSTANCE_MIGRATION.md
```

## Idempotency

- Workflow logic fingerprint: `logic_sha256` (names, types, versions, parameters
  with table **names** and credential **names**, connections, settings, retry,
  disabled). Volatile fields excluded.
- Data Table fingerprint: `schema_sha256` (column name + type + required).
- Same TARGET name + same fingerprints → no-op.
- Same TARGET name + different fingerprint → STOP (duplicate / drift).

## Phase 3A — live READ-ONLY inspection

`tools/n8n_migrate/live_client.py` + `tools/n8n_migrate/live_report.py` add a
**strictly read-only** live inspection path against the real SOURCE and TARGET
n8n instances using the n8n Public REST API (`/api/v1/*`, `X-N8N-API-KEY`).

```bash
python tools/n8n_migrate/migrate.py live-inspect --allow-live-read --out report.json
```

Guarantees (all offline tests in `tools/n8n_migrate/test_live_client.py`):

- Only `GET`/`HEAD` are permitted; `POST`/`PUT`/`PATCH`/`DELETE` are refused.
- Activation, publish, execution, and webhook registration are hard-refused.
- Credential **list** endpoint only → `id`/`name`/`type` metadata; any
  `data`/`oauthTokenData` field is dropped. Secret values are never fetched.
- HTTPS enforced (override with `N8N_ALLOW_HTTP=1` for local testing only).
- Request timeout (default 30s). Auth headers and tokens are never logged and
  never stored in the report.
- Fails closed if required config is missing.

### Environment variables (values live in the environment, never in Git)

| Variable | Required | Purpose |
|---|---|---|
| `N8N_SOURCE_BASE_URL` | yes | SOURCE n8n instance, e.g. `https://source.app.n8n.cloud` |
| `N8N_SOURCE_API_KEY` | yes | read-only n8n API key for SOURCE |
| `N8N_TARGET_BASE_URL` | yes | TARGET n8n instance, e.g. `https://target.app.n8n.cloud` |
| `N8N_TARGET_API_KEY` | yes | read-only n8n API key for TARGET |
| `N8N_LIVE_TIMEOUT` | no | request timeout seconds (default `30`) |
| `N8N_ALLOW_HTTP` | no | `1`/`true` to allow `http://` for local testing |
| `N8N_INCLUDE_ROW_COUNT` | no | `1`/`true` to fetch best-effort Data Table row counts |

The read-only keys must be provisioned with the **minimum** n8n scopes needed to
read (e.g. `workflow:read`, `workflow:list`, credential read, data-table read) —
never a full-owner key. Missing config is a hard stop (`AUTH_CONFIG_MISSING`).

`live-inspect` never writes, activates, publishes, executes, or registers
webhooks. For migration of the real instances, still see
`docs/N8N_INSTANCE_MIGRATION.md`.
