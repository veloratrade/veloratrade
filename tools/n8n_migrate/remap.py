"""Remap Data Table IDs and credential IDs. Never copies secrets or webhook IDs."""

from __future__ import annotations

import copy
from typing import Any

from constants import SCHEDULE_TRIGGER_TYPE, TELEGRAM_TRIGGER_TYPE
from secrets_guard import SecretGuardError, credential_public_view

Json = dict[str, Any]


class RemapError(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


def remap_datatable_ids(obj: Any, id_by_name: dict[str, str]) -> Any:
    if isinstance(obj, dict):
        if "dataTableId" in obj and isinstance(obj["dataTableId"], dict):
            dt = dict(obj["dataTableId"])
            name = dt.get("cachedResultName")
            source_id = dt.get("value")
            if not name:
                raise RemapError("UNMAPPED_DATATABLE", "dataTableId missing cachedResultName.")
            target_id = id_by_name.get(name)
            if not target_id:
                raise RemapError("UNMAPPED_DATATABLE", f"No TARGET id for table {name}.")
            dt["value"] = target_id
            dt["mode"] = "id"
            copied = {k: remap_datatable_ids(v, id_by_name) for k, v in obj.items() if k != "dataTableId"}
            copied["dataTableId"] = dt
            _ = source_id
            return copied
        return {k: remap_datatable_ids(v, id_by_name) for k, v in obj.items()}
    if isinstance(obj, list):
        return [remap_datatable_ids(v, id_by_name) for v in obj]
    return obj


def prepare_workflow_for_target(
    source_workflow: Json,
    *,
    table_id_by_name: dict[str, str],
    credential_id_by_type_name: dict[tuple[str, str], str],
    allow_unmapped_disabled_credentials: bool = True,
) -> Json:
    prepared = copy.deepcopy(source_workflow)
    prepared.pop("pinData", None)
    prepared.pop("staticData", None)
    prepared.pop("parentFolderId", None)
    prepared.pop("shared", None)
    prepared.pop("usedCredentials", None)
    prepared["active"] = False
    prepared["nodes"] = remap_datatable_ids(prepared.get("nodes") or [], table_id_by_name)

    new_nodes = []
    for node in prepared["nodes"]:
        node = dict(node)
        node.pop("webhookId", None)
        if node.get("type") == TELEGRAM_TRIGGER_TYPE:
            node["disabled"] = True
        if node.get("type") == SCHEDULE_TRIGGER_TYPE:
            node["disabled"] = True
        creds = node.get("credentials") or {}
        mapped: Json = {}
        for cred_type, body in creds.items():
            public = credential_public_view(body if isinstance(body, dict) else {}, cred_type)
            name = public.get("name") or ""
            key = (cred_type, name)
            target_id = credential_id_by_type_name.get(key)
            if not target_id:
                if allow_unmapped_disabled_credentials and node.get("disabled"):
                    # Keep public metadata only; do not invent a TARGET id.
                    mapped[cred_type] = {"id": public.get("id"), "name": name}
                    continue
                raise RemapError("UNMAPPED_CREDENTIAL", f"{cred_type}/{name}")
            mapped[cred_type] = {"id": target_id, "name": name}
        if mapped:
            node["credentials"] = mapped
        new_nodes.append(node)
    prepared["nodes"] = new_nodes
    return prepared


def assert_prepared_safe(prepared: Json) -> None:
    if prepared.get("active"):
        raise RemapError("WORKFLOW_WOULD_ACTIVATE", "Prepared workflow is active.")
    for node in prepared.get("nodes") or []:
        if "webhookId" in node:
            raise RemapError("WEBHOOK_ID_COPY", f"webhookId still present on {node.get('name')}.")
        if node.get("type") == TELEGRAM_TRIGGER_TYPE and not node.get("disabled"):
            raise RemapError("TELEGRAM_TRIGGER_WOULD_ENABLE", str(node.get("name")))
        if node.get("type") == SCHEDULE_TRIGGER_TYPE and not node.get("disabled"):
            raise RemapError("SCHEDULE_TRIGGER_WOULD_ENABLE", str(node.get("name")))
        creds = node.get("credentials") or {}
        for body in creds.values():
            if isinstance(body, dict) and ("data" in body or "oauthTokenData" in body):
                raise SecretGuardError("CREDENTIAL_SECRET_PRESENT", "Prepared credentials include secret fields.")
