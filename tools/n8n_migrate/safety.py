"""STOP conditions. Default is refuse writes, activation, publish, execute."""

from __future__ import annotations

from typing import Any

from constants import DEFAULT_SAFETY
from secrets_guard import SecretGuardError, assert_no_secret_material

Json = dict[str, Any]


class SafetyStop(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


def evaluate_blockers(
    *,
    safety: Json | None = None,
    credential_report: list[Json] | None = None,
    table_report: list[Json] | None = None,
    prepared: Json | None = None,
    apply_requested: bool = False,
    apply_flags_ok: bool = False,
    live_network: bool = False,
    source_write: bool = False,
    activate_requested: bool = False,
    publish_requested: bool = False,
    execute_requested: bool = False,
    target_existing: Json | None = None,
    prepared_logic_sha256: str | None = None,
) -> list[str]:
    safety = {**DEFAULT_SAFETY, **(safety or {})}
    blockers: list[str] = []

    if source_write:
        blockers.append("SOURCE_WRITE_FORBIDDEN")
    if apply_requested and not apply_flags_ok:
        blockers.append("APPLY_NOT_AUTHORIZED")
    if apply_requested and safety.get("target_dry_run") and not apply_flags_ok:
        blockers.append("TARGET_NOT_DRY_RUN")
    if live_network and not safety.get("allow_live_network"):
        blockers.append("LIVE_NETWORK_FORBIDDEN")
    if activate_requested or publish_requested or execute_requested:
        blockers.append("PUBLISH_OR_ACTIVATE_REQUESTED")

    for row in credential_report or []:
        status = row.get("mapping_status")
        if status not in ("missing_on_target", "unmapped", "ambiguous", "name_mismatch"):
            continue
        enabled_uses = row.get("used_by_enabled_nodes") or []
        any_uses = row.get("used_by_nodes") or []
        if enabled_uses:
            blockers.append(f"UNMAPPED_CREDENTIAL:{row.get('type')}/{row.get('source_name')}")
        elif apply_requested and any_uses:
            blockers.append(f"UNMAPPED_CREDENTIAL:{row.get('type')}/{row.get('source_name')}")

    for row in table_report or []:
        if row.get("schema_status") == "mismatch":
            blockers.append(f"SCHEMA_MISMATCH:{row.get('name')}")
        if row.get("schema_status") in ("missing_target", "unmapped") and apply_requested:
            blockers.append(f"UNMAPPED_DATATABLE:{row.get('name')}")

    if prepared is not None:
        try:
            assert_no_secret_material(prepared, context="prepared")
        except SecretGuardError as exc:
            blockers.append(exc.code)
        if prepared.get("active"):
            blockers.append("WORKFLOW_WOULD_ACTIVATE")
        for node in prepared.get("nodes") or []:
            if "webhookId" in node:
                blockers.append("WEBHOOK_ID_COPY")
            if node.get("type") == "n8n-nodes-base.telegramTrigger" and not node.get("disabled"):
                blockers.append("TELEGRAM_TRIGGER_WOULD_ENABLE")
            if node.get("type") == "n8n-nodes-base.scheduleTrigger" and not node.get("disabled"):
                blockers.append("SCHEDULE_TRIGGER_WOULD_ENABLE")

    if target_existing is not None and prepared_logic_sha256:
        from model import logic_checksum

        existing_sha = logic_checksum(target_existing)
        if existing_sha != prepared_logic_sha256:
            blockers.append("DUPLICATE_WORKFLOW_DRIFT")

    # unique
    seen = set()
    out = []
    for item in blockers:
        if item not in seen:
            seen.add(item)
            out.append(item)
    return out
