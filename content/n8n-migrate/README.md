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
