"""Inspect workflows, Data Tables, and credential *metadata* only."""

from __future__ import annotations

import hashlib
import json
from typing import Any

from constants import (
    DATATABLE_TYPE,
    NODE_LOGIC_KEYS,
    SCHEDULE_TRIGGER_TYPE,
    TELEGRAM_TRIGGER_TYPE,
)
from secrets_guard import assert_no_secret_material, credential_public_view

Json = dict[str, Any]


def _stable_dumps(obj: Any) -> str:
    return json.dumps(obj, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def sha256_json(obj: Any) -> str:
    return hashlib.sha256(_stable_dumps(obj).encode("utf-8")).hexdigest()


def walk(obj: Any):
    if isinstance(obj, dict):
        yield obj
        for value in obj.values():
            yield from walk(value)
    elif isinstance(obj, list):
        for item in obj:
            yield from walk(item)


def find_datatable_refs(workflow: Json) -> list[dict[str, Any]]:
    refs: list[dict[str, Any]] = []
    for node in workflow.get("nodes") or []:
        for blob in walk(node.get("parameters")):
            dt = blob.get("dataTableId") if isinstance(blob, dict) else None
            if not isinstance(dt, dict):
                continue
            refs.append(
                {
                    "node": node.get("name"),
                    "node_type": node.get("type"),
                    "id": dt.get("value"),
                    "name": dt.get("cachedResultName"),
                    "mode": dt.get("mode"),
                }
            )
    return refs


def find_expressions(workflow: Json) -> list[dict[str, str]]:
    found: list[dict[str, str]] = []

    def rec(obj: Any, path: str, node_name: str) -> None:
        if isinstance(obj, dict):
            for key, value in obj.items():
                rec(value, f"{path}.{key}", node_name)
        elif isinstance(obj, list):
            for i, value in enumerate(obj):
                rec(value, f"{path}[{i}]", node_name)
        elif isinstance(obj, str) and obj.startswith("="):
            found.append({"node": node_name, "path": path, "expression": obj})

    for node in workflow.get("nodes") or []:
        rec(node.get("parameters"), "parameters", str(node.get("name")))
    return found


def extract_credential_uses(workflow: Json) -> list[dict[str, Any]]:
    uses: list[dict[str, Any]] = []
    for node in workflow.get("nodes") or []:
        creds = node.get("credentials") or {}
        if not creds:
            continue
        for cred_type, body in creds.items():
            public = credential_public_view(body if isinstance(body, dict) else {}, cred_type)
            uses.append(
                {
                    "node": node.get("name"),
                    "node_type": node.get("type"),
                    "type": public.get("type") or cred_type,
                    "name": public.get("name"),
                    "id": public.get("id"),
                    "node_disabled": bool(node.get("disabled")),
                }
            )
    return uses


def inspect_workflow(workflow: Json) -> Json:
    assert_no_secret_material(workflow, context="workflow")
    nodes = workflow.get("nodes") or []
    connections = workflow.get("connections") or {}
    settings = workflow.get("settings") or {}
    telegram = [n for n in nodes if n.get("type") == TELEGRAM_TRIGGER_TYPE]
    schedules = [n for n in nodes if n.get("type") == SCHEDULE_TRIGGER_TYPE]
    retry_nodes = []
    for node in nodes:
        if any(k in node for k in ("onError", "retryOnFail", "maxTries", "waitBetweenTries", "continueOnFail")):
            retry_nodes.append(
                {
                    "name": node.get("name"),
                    "onError": node.get("onError"),
                    "retryOnFail": node.get("retryOnFail"),
                    "maxTries": node.get("maxTries"),
                    "waitBetweenTries": node.get("waitBetweenTries"),
                    "continueOnFail": node.get("continueOnFail"),
                    "disabled": bool(node.get("disabled")),
                }
            )
    return {
        "id": workflow.get("id"),
        "name": workflow.get("name"),
        "active": bool(workflow.get("active")),
        "version_id": workflow.get("versionId"),
        "settings": settings,
        "node_count": len(nodes),
        "connection_count": sum(1 for _ in connections),
        "nodes": [
            {
                "name": n.get("name"),
                "type": n.get("type"),
                "typeVersion": n.get("typeVersion"),
                "disabled": bool(n.get("disabled")),
                "has_webhook": "webhookId" in n,
            }
            for n in nodes
        ],
        "telegram_triggers": [
            {
                "name": n.get("name"),
                "disabled": bool(n.get("disabled")),
                "has_webhook_id": "webhookId" in n,
            }
            for n in telegram
        ],
        "schedule_triggers": [
            {"name": n.get("name"), "disabled": bool(n.get("disabled"))} for n in schedules
        ],
        "retry_error": retry_nodes,
        "datatable_refs": find_datatable_refs(workflow),
        "credential_uses": extract_credential_uses(workflow),
        "expression_count": len(find_expressions(workflow)),
        "logic_sha256": logic_checksum(workflow),
    }


def _replace_table_ids_with_names(obj: Any) -> Any:
    if isinstance(obj, dict):
        if "dataTableId" in obj and isinstance(obj["dataTableId"], dict):
            dt = obj["dataTableId"]
            copied = {k: _replace_table_ids_with_names(v) for k, v in obj.items()}
            copied["dataTableId"] = {
                "__rl": True,
                "mode": "name",
                "value": dt.get("cachedResultName") or dt.get("value"),
                "cachedResultName": dt.get("cachedResultName"),
            }
            return copied
        return {k: _replace_table_ids_with_names(v) for k, v in obj.items()}
    if isinstance(obj, list):
        return [_replace_table_ids_with_names(v) for v in obj]
    return obj


def canonical_logic(workflow: Json) -> Json:
    nodes_out = []
    for node in sorted(workflow.get("nodes") or [], key=lambda n: str(n.get("name"))):
        item: Json = {}
        for key in NODE_LOGIC_KEYS:
            if key == "parameters":
                item[key] = _replace_table_ids_with_names(node.get("parameters") or {})
            elif key == "disabled":
                item[key] = bool(node.get("disabled"))
            elif key in node:
                item[key] = node.get(key)
        creds = node.get("credentials") or {}
        item["credentials"] = {
            cred_type: {
                "type": cred_type,
                "name": (body or {}).get("name") if isinstance(body, dict) else None,
            }
            for cred_type, body in sorted(creds.items())
        }
        nodes_out.append(item)
    return {
        "name": workflow.get("name"),
        "settings": workflow.get("settings") or {},
        "nodes": nodes_out,
        "connections": workflow.get("connections") or {},
    }


def logic_checksum(workflow: Json) -> str:
    return sha256_json(canonical_logic(workflow))


def inspect_tables(tables: list[Json]) -> list[Json]:
    out = []
    for table in tables:
        assert_no_secret_material(
            {k: table.get(k) for k in ("id", "name", "columns", "schema") if k in table},
            context="datatable",
        )
        columns = table.get("columns") or table.get("schema") or []
        col_norm = []
        for col in columns:
            col_norm.append(
                {
                    "name": col.get("name") or col.get("id") or col.get("displayName"),
                    "type": col.get("type"),
                    "required": bool(col.get("required")),
                }
            )
        col_norm.sort(key=lambda c: str(c["name"]))
        out.append(
            {
                "id": table.get("id"),
                "name": table.get("name"),
                "columns": col_norm,
                "column_count": len(col_norm),
                "row_count": table.get("row_count"),
                "schema_sha256": sha256_json(col_norm),
            }
        )
    return out


def inspect_credential_catalog(catalog: list[Json]) -> list[Json]:
    """Catalog entries must already be metadata-only (id, name, type)."""
    out = []
    for item in catalog:
        public = credential_public_view(item, item.get("type"))
        out.append(public)
    return out
