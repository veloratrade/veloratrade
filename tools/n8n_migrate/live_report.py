"""n8n Phase 3A — build the READ-ONLY live migration report.

Turns a SOURCE and TARGET live inventory into the migration report sections:

    REAL_SOURCE_INVENTORY
    REAL_TARGET_INVENTORY
    WORKFLOW_MAPPING
    DATA_TABLE_MAPPING
    CREDENTIAL_MAPPING
    MISSING_CREDENTIALS
    SCHEMA_DIFFERENCES
    WORKFLOW_DIFFERENCES
    TELEGRAM_WEBHOOK_CONFLICTS
    BLOCKERS
    RECOMMENDED_MIGRATION_ORDER

Credential output contains ONLY: name, type, source_exists, target_exists,
mapping_status. Secret values are never present.
"""

from __future__ import annotations

import copy
from typing import Any

from compare import compare_table_schemas, compare_workflows
from constants import SCHEDULE_TRIGGER_TYPE, TELEGRAM_TRIGGER_TYPE
from manifest import host_only
from model import inspect_tables, inspect_workflow, logic_checksum
from secrets_guard import assert_no_secret_material

Json = dict[str, Any]


def _gated_checksum(workflow: Json) -> str:
    """Checksum of the SOURCE workflow as it would be prepared for TARGET.

    Applies the migration contract's expected gates — Telegram and schedule
    triggers disabled — before checksumming, so the benign gate difference is
    NOT reported as drift.
    """
    prepared = copy.deepcopy(workflow)
    for node in prepared.get("nodes") or []:
        if node.get("type") in (TELEGRAM_TRIGGER_TYPE, SCHEDULE_TRIGGER_TYPE):
            node["disabled"] = True
    return logic_checksum(prepared)


def build_inventory(client: Any, *, label: str, include_row_count: bool = False) -> Json:
    """Gather a read-only inventory from a LiveReadClient (or test double).

    Returns a dict with ``raw`` workflow dicts (needed for structural compare),
    inspected summaries, normalized Data Table schemas, and credential metadata.
    """
    workflows: list[Json] = []
    for wf in client.list_workflows():
        assert_no_secret_material(wf, context="workflow")
        for key in ("pinData", "staticData", "parentFolderId"):
            wf.pop(key, None)
        workflows.append({"raw": wf, "inspect": inspect_workflow(wf)})

    tables_raw: list[Json] = []
    for table in client.list_data_tables():
        columns = client.get_data_table_columns(str(table.get("id")))
        normalized = [
            _normalize_column(col) for col in columns
        ]
        entry: Json = {
            "id": table.get("id"),
            "name": table.get("name"),
            "columns": normalized,
        }
        if include_row_count:
            entry["row_count"] = client.get_data_table_row_count(str(table.get("id")))
        tables_raw.append(entry)

    credentials = client.list_credentials()

    return {
        "label": label,
        "base_url_host": host_only(client.config.base_url),
        "workflows": workflows,
        "datatables": tables_raw,
        "credentials": credentials,
        "row_count_included": include_row_count,
    }


def _normalize_column(col: Json) -> Json:
    name = col.get("name") or col.get("id") or col.get("displayName")
    required = bool(col.get("required"))
    # n8n may express optionality as nullable; treat nullable=False as required.
    if "nullable" in col and not required:
        required = not bool(col.get("nullable"))
    return {"name": name, "type": col.get("type"), "required": required}


# --------------------------------------------------------------------------
# Report assembly
# --------------------------------------------------------------------------


def _inventory_section(inv: Json) -> Json:
    return {
        "label": inv["label"],
        "base_url_host": inv.get("base_url_host"),
        "row_count_included": inv.get("row_count_included", False),
        "workflows": [w["inspect"] for w in inv.get("workflows") or []],
        "datatables": inspect_tables(inv.get("datatables") or []),
        "credentials": [
            {"name": c.get("name"), "type": c.get("type")}
            for c in inv.get("credentials") or []
        ],
    }


def _workflow_mapping(source: Json, target: Json) -> list[Json]:
    target_by_name = {w["inspect"]["name"]: w for w in target.get("workflows") or []}
    mapping: list[Json] = []
    for sw in source.get("workflows") or []:
        si = sw["inspect"]
        name = si["name"]
        tw = target_by_name.get(name)
        entry: Json = {
            "source_id": si["id"],
            "source_name": name,
            "source_active": bool(si["active"]),
            "target_id": tw["inspect"]["id"] if tw else None,
            "target_name": name if tw else None,
            "target_active": bool(tw["inspect"]["active"]) if tw else False,
            "status": "exists" if tw else "missing",
        }
        diffs: list[str] = []
        if tw:
            cmp = compare_workflows(sw["raw"], tw["raw"])
            diffs = list(cmp.get("differences") or [])
            # Gate-aware: the SOURCE is considered matched to TARGET when its
            # gated (prepared-style) checksum equals the TARGET checksum.
            if _gated_checksum(sw["raw"]) == logic_checksum(tw["raw"]):
                entry["status"] = "match"
            else:
                entry["status"] = "drift"
        entry["differences"] = diffs
        mapping.append(entry)
    return mapping


def _credential_mapping(source: Json, target: Json) -> list[Json]:
    def index(inv: Json) -> dict[tuple[str, str], Json]:
        out: dict[tuple[str, str], Json] = {}
        for c in inv.get("credentials") or []:
            key = (str(c.get("type") or ""), str(c.get("name") or ""))
            out.setdefault(key, c)
        return out

    src = index(source)
    tgt = index(target)
    all_keys = sorted(set(src) | set(tgt), key=lambda k: (k[0], k[1]))
    rows: list[Json] = []
    for key in all_keys:
        s = src.get(key)
        t = tgt.get(key)
        if s and t:
            status = "mapped"
        elif s:
            status = "missing_on_target"
        elif t:
            status = "extra_on_target"
        else:  # pragma: no cover - unreachable
            status = "none"
        rows.append(
            {
                "name": key[1],
                "type": key[0],
                "source_exists": s is not None,
                "target_exists": t is not None,
                "mapping_status": status,
            }
        )
    return rows


def _enabled_credential_uses(source: Json) -> set[tuple[str, str]]:
    used: set[tuple[str, str]] = set()
    for w in source.get("workflows") or []:
        for use in w["inspect"].get("credential_uses") or []:
            if not use.get("node_disabled"):
                used.add((str(use.get("type") or ""), str(use.get("name") or "")))
    return used


def _telegram_conflicts(source: Json, target: Json) -> list[str]:
    conflicts: list[str] = []

    def telegram_state(inv: Json) -> list[Json]:
        states: list[Json] = []
        for w in inv.get("workflows") or []:
            insp = w["inspect"]
            for tg in insp.get("telegram_triggers") or []:
                states.append(
                    {
                        "workflow": insp["name"],
                        "name": tg.get("name"),
                        "enabled": not tg.get("disabled"),
                        "has_webhook": bool(tg.get("has_webhook_id")),
                    }
                )
        return states

    src_tg = telegram_state(source)
    tgt_tg = telegram_state(target)

    for s in src_tg:
        if s["enabled"] and s["has_webhook"]:
            conflicts.append(
                f"SOURCE owns Telegram webhook ({s['workflow']}/{s['name']}); "
                "the SOURCE bot webhook must be switched OFF before TARGET can own it."
            )
    # Same-name workflow with an enabled Telegram trigger on both sides → duplicate messages.
    src_enabled = {s["workflow"] for s in src_tg if s["enabled"] and s["has_webhook"]}
    for t in tgt_tg:
        if t["enabled"] and t["has_webhook"] and t["workflow"] in src_enabled:
            conflicts.append(
                f"TELEGRAM_DUPLICATE_MESSAGES: {t['workflow']} has an enabled Telegram "
                "trigger on BOTH instances (SOURCE + TARGET) — duplicate message risk."
            )

    # Schedule triggers enabled on both sides of the same workflow → duplicate executions.
    def enabled_schedules(inv: Json) -> set[str]:
        out: set[str] = set()
        for w in inv.get("workflows") or []:
            insp = w["inspect"]
            enabled = [s for s in insp.get("schedule_triggers") or [] if not s.get("disabled")]
            if enabled:
                out.add(insp["name"])
        return out

    for name in sorted(enabled_schedules(source) & enabled_schedules(target)):
        conflicts.append(
            f"SCHEDULE_DUPLICATE_EXECUTIONS: {name} has enabled schedule trigger(s) "
            "on BOTH instances."
        )

    return conflicts


def _collect_blockers(
    *,
    workflow_mapping: list[Json],
    table_mapping: list[Json],
    credential_mapping: list[Json],
    enabled_cred_uses: set[tuple[str, str]],
    telegram_conflicts: list[str],
) -> list[str]:
    blockers: list[str] = []

    # Duplicate / drift workflows
    for w in workflow_mapping:
        if w["status"] == "drift":
            blockers.append(
                f"DUPLICATE_WORKFLOW_DRIFT:{w['source_name']} — TARGET has a workflow "
                "with the same name but different logic."
            )
        elif w["status"] == "exists" and w["target_active"]:
            blockers.append(
                f"TARGET_ACTIVE_WORKFLOW_REQUIRES_DISABLE:{w['source_name']} — an active "
                "TARGET workflow must be disabled before migration."
            )

    # Schema mismatch / table drift
    for t in table_mapping:
        if t.get("schema_status") == "mismatch":
            blockers.append(f"SCHEMA_MISMATCH:{t['name']}")

    # Missing credentials required by enabled nodes
    missing_names = {
        (c["type"], c["name"]) for c in credential_mapping if c["mapping_status"] == "missing_on_target"
    }
    for key in sorted(enabled_cred_uses):
        if key in missing_names:
            blockers.append(
                f"MISSING_REQUIRED_CREDENTIAL:{key[0]}/{key[1]} — used by an enabled node; "
                "no TARGET credential exists."
            )

    # Telegram / schedule conflicts
    blockers.extend(telegram_conflicts)

    return blockers


def build_live_report(source: Json, target: Json) -> Json:
    """Assemble the full read-only migration report from two inventories."""
    workflow_mapping = _workflow_mapping(source, target)
    table_mapping = compare_table_schemas(source.get("datatables") or [], target.get("datatables") or [])
    credential_mapping = _credential_mapping(source, target)
    enabled_cred_uses = _enabled_credential_uses(source)
    telegram_conflicts = _telegram_conflicts(source, target)

    schema_differences = [
        {"name": t["name"], "source_id": t.get("source_id"), "target_id": t.get("target_id"),
         "source_columns": t.get("source_columns"), "target_columns": t.get("target_columns"),
         "differences": t.get("differences")}
        for t in table_mapping
        if t.get("schema_status") == "mismatch"
    ]
    workflow_differences: list[Json] = [
        {"source_name": w["source_name"], "status": w["status"], "differences": w["differences"]}
        for w in workflow_mapping
        if w.get("differences")
    ]
    missing_credentials = [
        {"name": c["name"], "type": c["type"]}
        for c in credential_mapping
        if c["mapping_status"] == "missing_on_target"
    ]

    blockers = _collect_blockers(
        workflow_mapping=workflow_mapping,
        table_mapping=table_mapping,
        credential_mapping=credential_mapping,
        enabled_cred_uses=enabled_cred_uses,
        telegram_conflicts=telegram_conflicts,
    )

    report: Json = {
        "REAL_SOURCE_INVENTORY": _inventory_section(source),
        "REAL_TARGET_INVENTORY": _inventory_section(target),
        "WORKFLOW_MAPPING": workflow_mapping,
        "DATA_TABLE_MAPPING": table_mapping,
        "CREDENTIAL_MAPPING": credential_mapping,
        "MISSING_CREDENTIALS": missing_credentials,
        "SCHEMA_DIFFERENCES": schema_differences,
        "WORKFLOW_DIFFERENCES": workflow_differences,
        "TELEGRAM_WEBHOOK_CONFLICTS": telegram_conflicts,
        "BLOCKERS": blockers,
        "RECOMMENDED_MIGRATION_ORDER": recommended_migration_order(
            missing_credentials=missing_credentials,
            schema_differences=schema_differences,
            telegram_conflicts=telegram_conflicts,
        ),
        "SAFETY": {
            "source_read_only": True,
            "target_dry_run": True,
            "live_http_methods_allowed": ["GET", "HEAD"],
            "credential_secret_transfer": "never",
            "writes_performed": [],
        },
    }
    assert_no_secret_material(report, context="live-report")
    return report


def recommended_migration_order(
    *,
    missing_credentials: list[Json],
    schema_differences: list[Json],
    telegram_conflicts: list[str],
) -> list[str]:
    order: list[str] = []
    if missing_credentials:
        order.append(
            "Owner manually creates TARGET credentials in the n8n UI (names/types: "
            + ", ".join(f"{c['name']} ({c['type']})" for c in missing_credentials)
            + "); paste secrets only in the n8n UI — never in Git/chat/manifests."
        )
    order.append("Migrate Data Tables first (create any missing tables with a matching "
                 "schema; never coerce types). Resolve all SCHEMA_MISMATCH before proceeding.")
    order.append("Migrate workflows as UNPUBLISHED (active=false), with Telegram and schedule "
                 "triggers disabled and webhook IDs stripped.")
    order.append("Owner confirms Google OAuth identity / GSC property access on TARGET.")
    order.append("Owner switches the Telegram bot webhook ownership: SOURCE off first, then "
                 "TARGET on. Never leave both receiving at once.")
    order.append("Owner publishes / activates TARGET and runs the article E2E "
                 "(Generate → Approve → Archive).")
    order.append("Post-migration structural compare; archive ingest stays a separate system.")
    if telegram_conflicts:
        order.insert(0, "⚠️ Resolve Telegram/schedule duplicate risks before any activation: "
                        + "; ".join(telegram_conflicts))
    return order
