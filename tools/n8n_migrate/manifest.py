"""Build and lightly validate migration manifests (no secrets)."""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Any

from secrets_guard import SecretGuardError, assert_no_secret_material

Json = dict[str, Any]

REQUIRED = (
    "manifest_version",
    "generated_at",
    "mode",
    "source",
    "target",
    "workflow",
    "datatables",
    "credentials",
    "checksums",
    "migration_status",
    "validation_status",
    "differences",
    "errors",
    "blockers",
    "safety",
)

ALLOWED_MODES = {"inspect", "compare", "dry-run", "validate", "apply-blocked"}


def utc_now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def host_only(url: str | None) -> str | None:
    if not url:
        return None
    # Never store tokens or query strings.
    cleaned = url.split("?", 1)[0].split("#", 1)[0]
    if "://" in cleaned:
        cleaned = cleaned.split("://", 1)[1]
    return cleaned.split("/", 1)[0]


def build_manifest(
    *,
    mode: str,
    source_inspect: Json,
    target_inspect: Json | None,
    table_report: list[Json],
    credential_report: list[Json],
    checksums: Json,
    blockers: list[str],
    differences: list[str],
    errors: list[str] | None = None,
    source_host: str | None = None,
    target_host: str | None = None,
    writes_performed: list[str] | None = None,
) -> Json:
    if mode not in ALLOWED_MODES:
        mode = "inspect"
    if blockers:
        migration_status = "blocked" if mode == "apply-blocked" else (
            "dry_run_blocked" if mode == "dry-run" else "validated_blocked" if mode == "validate" else "inspected"
        )
        if mode == "compare":
            migration_status = "compared"
        validation_status = "fail"
    else:
        migration_status = {
            "inspect": "inspected",
            "compare": "compared",
            "dry-run": "dry_run_ok",
            "validate": "validated_ok",
            "apply-blocked": "blocked",
        }[mode]
        validation_status = "pass" if mode in {"dry-run", "validate", "compare"} else "not_run"

    tgt = target_inspect or {}
    manifest: Json = {
        "manifest_version": 1,
        "generated_at": utc_now(),
        "mode": mode,
        "source": {
            "label": "SOURCE",
            "base_url_host": host_only(source_host),
            "workflow_id": source_inspect.get("id"),
            "workflow_name": source_inspect.get("name"),
            "version_id": source_inspect.get("version_id"),
            "active": bool(source_inspect.get("active")),
        },
        "target": {
            "label": "TARGET",
            "base_url_host": host_only(target_host),
            "workflow_id": tgt.get("id"),
            "workflow_name": tgt.get("name") or source_inspect.get("name"),
            "version_id": tgt.get("version_id"),
            "active": tgt.get("active") if tgt else False,
        },
        "workflow": {
            "node_count": source_inspect.get("node_count"),
            "connection_count": source_inspect.get("connection_count"),
            "settings": source_inspect.get("settings") or {},
            "telegram_trigger": (source_inspect.get("telegram_triggers") or [None])[0],
            "schedule_triggers": source_inspect.get("schedule_triggers") or [],
        },
        "datatables": table_report,
        "credentials": credential_report,
        "checksums": checksums,
        "migration_status": migration_status,
        "validation_status": validation_status,
        "differences": differences,
        "errors": errors or [],
        "blockers": blockers,
        "safety": {
            "source_read_only": True,
            "target_dry_run": True,
            "never_activate": True,
            "never_publish": True,
            "credentials_transfer": False,
        },
        "writes_performed": writes_performed or [],
    }
    assert_no_secret_material(manifest, context="manifest")
    return manifest


def validate_manifest(manifest: Json) -> list[str]:
    errors: list[str] = []
    if not isinstance(manifest, dict):
        return ["root must be object"]
    try:
        assert_no_secret_material(manifest, context="manifest")
    except SecretGuardError as exc:
        errors.append(exc.code)
    for field in REQUIRED:
        if field not in manifest:
            errors.append(f"missing:{field}")
    if manifest.get("manifest_version") != 1:
        errors.append("manifest_version")
    if manifest.get("mode") not in ALLOWED_MODES:
        errors.append("mode")
    safety = manifest.get("safety") or {}
    if safety.get("credentials_transfer") is not False:
        errors.append("credentials_transfer_must_be_false")
    if safety.get("never_activate") is not True:
        errors.append("never_activate_required")
    if safety.get("never_publish") is not True:
        errors.append("never_publish_required")
    for row in manifest.get("credentials") or []:
        if row.get("secret_transfer") != "never":
            errors.append("credential_secret_transfer_flag")
        if "data" in row or "oauthTokenData" in row:
            errors.append("credential_secret_field")
    return errors
