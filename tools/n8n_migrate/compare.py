"""Pre- and post-migration structural comparison. No network."""

from __future__ import annotations

from typing import Any

from model import inspect_tables, logic_checksum

Json = dict[str, Any]


def _node_map(workflow: Json) -> dict[str, Json]:
    return {str(n.get("name")): n for n in workflow.get("nodes") or []}


def compare_workflows(source: Json, target: Json | None, *, prepared: Json | None = None) -> Json:
    diffs: list[str] = []
    if target is None:
        return {
            "status": "missing_target",
            "differences": ["TARGET workflow not present"],
            "source_logic_sha256": logic_checksum(source),
            "target_logic_sha256": None,
        }

    src_nodes = _node_map(source)
    tgt_nodes = _node_map(target)
    src_names = set(src_nodes)
    tgt_names = set(tgt_nodes)
    for name in sorted(src_names - tgt_names):
        diffs.append(f"missing_on_target_node:{name}")
    for name in sorted(tgt_names - src_names):
        diffs.append(f"extra_on_target_node:{name}")

    for name in sorted(src_names & tgt_names):
        s, t = src_nodes[name], tgt_nodes[name]
        if s.get("type") != t.get("type"):
            diffs.append(f"type_mismatch:{name}")
        if s.get("typeVersion") != t.get("typeVersion"):
            diffs.append(f"typeVersion_mismatch:{name}")
        for flag in ("onError", "retryOnFail", "maxTries", "waitBetweenTries", "continueOnFail", "alwaysOutputData", "executeOnce"):
            if s.get(flag) != t.get(flag):
                diffs.append(f"{flag}_mismatch:{name}")

    src_settings = source.get("settings") or {}
    tgt_settings = target.get("settings") or {}
    for key in ("timezone", "executionOrder", "binaryMode"):
        if src_settings.get(key) != tgt_settings.get(key):
            diffs.append(f"settings.{key}_mismatch")

    src_conn = source.get("connections") or {}
    tgt_conn = target.get("connections") or {}
    if src_conn != tgt_conn:
        diffs.append("connections_mismatch")

    expected_overrides: list[str] = []
    if target.get("active"):
        diffs.append("target_is_active")
    else:
        expected_overrides.append("target_active_false")

    for name, node in tgt_nodes.items():
        if node.get("type") == "n8n-nodes-base.telegramTrigger" and not node.get("disabled"):
            diffs.append(f"telegram_trigger_enabled:{name}")
        if node.get("type") == "n8n-nodes-base.scheduleTrigger" and not node.get("disabled"):
            diffs.append(f"schedule_trigger_enabled:{name}")
        if "webhookId" in node and prepared is not None:
            # TARGET may assign its own webhookId after create; that is not a SOURCE copy.
            expected_overrides.append(f"target_assigned_webhook:{name}")

    result = {
        "status": "match" if not diffs else "mismatch",
        "differences": diffs,
        "expected_overrides": expected_overrides,
        "source_logic_sha256": logic_checksum(source),
        "target_logic_sha256": logic_checksum(target),
        "prepared_logic_sha256": logic_checksum(prepared) if prepared is not None else None,
    }
    return result


def compare_table_schemas(source_tables: list[Json], target_tables: list[Json]) -> list[Json]:
    src = {t["name"]: t for t in inspect_tables(source_tables)}
    tgt = {t["name"]: t for t in inspect_tables(target_tables)}
    names = sorted(set(src) | set(tgt))
    rows = []
    for name in names:
        s = src.get(name)
        t = tgt.get(name)
        if s is None:
            rows.append(
                {
                    "name": name,
                    "source_id": None,
                    "target_id": t.get("id") if t else None,
                    "schema_status": "missing_source",
                    "source_columns": 0,
                    "target_columns": t["column_count"] if t else 0,
                    "differences": ["missing on SOURCE"],
                }
            )
            continue
        if t is None:
            rows.append(
                {
                    "name": name,
                    "source_id": s.get("id"),
                    "target_id": None,
                    "schema_status": "missing_target",
                    "source_columns": s["column_count"],
                    "target_columns": 0,
                    "differences": ["missing on TARGET"],
                }
            )
            continue
        s_cols = {c["name"]: c for c in s["columns"]}
        t_cols = {c["name"]: c for c in t["columns"]}
        diffs: list[str] = []
        for col in sorted(set(s_cols) - set(t_cols)):
            diffs.append(f"missing_column:{col}")
        for col in sorted(set(t_cols) - set(s_cols)):
            diffs.append(f"extra_column:{col}")
        for col in sorted(set(s_cols) & set(t_cols)):
            if s_cols[col]["type"] != t_cols[col]["type"]:
                diffs.append(f"type_mismatch:{col}")
            if s_cols[col]["required"] != t_cols[col]["required"]:
                diffs.append(f"required_mismatch:{col}")
        rows.append(
            {
                "name": name,
                "source_id": s.get("id"),
                "target_id": t.get("id"),
                "schema_status": "match" if not diffs else "mismatch",
                "source_columns": s["column_count"],
                "target_columns": t["column_count"],
                "differences": diffs,
            }
        )
    return rows
