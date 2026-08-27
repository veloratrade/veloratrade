"""Credential mapping by type+name. Secrets are never read or written."""

from __future__ import annotations

from typing import Any

from model import extract_credential_uses, inspect_credential_catalog

Json = dict[str, Any]


def build_credential_report(
    source_workflow: Json,
    source_catalog: list[Json],
    target_catalog: list[Json],
) -> list[Json]:
    source_meta = inspect_credential_catalog(source_catalog)
    target_meta = inspect_credential_catalog(target_catalog)
    uses = extract_credential_uses(source_workflow)

    target_by_type_name: dict[tuple[str, str], list[Json]] = {}
    target_by_type: dict[str, list[Json]] = {}
    for item in target_meta:
        target_by_type_name.setdefault((item.get("type") or "", item.get("name") or ""), []).append(item)
        target_by_type.setdefault(item.get("type") or "", []).append(item)

    seen: dict[tuple[str, str], Json] = {}
    for use in uses:
        key = (str(use.get("type") or ""), str(use.get("name") or ""))
        if key not in seen:
            seen[key] = {
                "type": key[0],
                "source_id": use.get("id"),
                "source_name": key[1],
                "target_id": None,
                "target_name": None,
                "mapping_status": "unmapped",
                "used_by_nodes": [],
                "used_by_enabled_nodes": [],
                "secret_transfer": "never",
            }
        if use.get("node") not in seen[key]["used_by_nodes"]:
            seen[key]["used_by_nodes"].append(use["node"])
        if not use.get("node_disabled") and use.get("node") not in seen[key]["used_by_enabled_nodes"]:
            seen[key]["used_by_enabled_nodes"].append(use["node"])

    for key, row in seen.items():
        exact = target_by_type_name.get(key) or []
        if len(exact) == 1:
            row["target_id"] = exact[0].get("id")
            row["target_name"] = exact[0].get("name")
            row["mapping_status"] = "mapped"
            continue
        if len(exact) > 1:
            row["mapping_status"] = "ambiguous"
            continue
        same_type = target_by_type.get(key[0]) or []
        if not same_type:
            row["mapping_status"] = "missing_on_target"
        elif len(same_type) == 1 and not key[1]:
            row["target_id"] = same_type[0].get("id")
            row["target_name"] = same_type[0].get("name")
            row["mapping_status"] = "mapped"
        else:
            row["mapping_status"] = "name_mismatch"

    # Include SOURCE catalog entries unused by the workflow (metadata only).
    used_ids = {row.get("source_id") for row in seen.values()}
    for item in source_meta:
        if item.get("id") in used_ids:
            continue
        key = (item.get("type") or "", item.get("name") or "")
        if key in seen:
            continue
        seen[key] = {
            "type": key[0],
            "source_id": item.get("id"),
            "source_name": key[1],
            "target_id": None,
            "target_name": None,
            "mapping_status": "unmapped",
            "used_by_nodes": [],
            "used_by_enabled_nodes": [],
            "secret_transfer": "never",
        }

    return sorted(seen.values(), key=lambda r: (r["type"], r["source_name"]))


def mapping_index(report: list[Json]) -> dict[tuple[str, str], str]:
    out: dict[tuple[str, str], str] = {}
    for row in report:
        if row.get("mapping_status") == "mapped" and row.get("target_id"):
            out[(row["type"], row["source_name"])] = str(row["target_id"])
    return out
