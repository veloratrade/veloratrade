"""Orchestrate inspect / compare / dry-run / validate. Apply is always blocked here."""

from __future__ import annotations

from typing import Any

from client import SourceReadOnly, TargetDryRun
from compare import compare_table_schemas, compare_workflows
from credentials import build_credential_report, mapping_index
from manifest import build_manifest, validate_manifest
from model import inspect_tables, inspect_workflow, logic_checksum
from remap import RemapError, assert_prepared_safe, prepare_workflow_for_target
from safety import SafetyStop, evaluate_blockers
from secrets_guard import SecretGuardError, assert_no_secret_material

Json = dict[str, Any]


def run_pipeline(
    *,
    mode: str,
    source: SourceReadOnly,
    target: TargetDryRun,
    source_workflow_id: str,
    include_rows: bool = False,
    apply_requested: bool = False,
    apply_flags_ok: bool = False,
    source_host: str | None = None,
    target_host: str | None = None,
) -> Json:
    if apply_requested:
        mode = "apply-blocked"
        blockers = evaluate_blockers(apply_requested=True, apply_flags_ok=apply_flags_ok)
        # Even with flags, this preparation CLI never writes.
        blockers.append("APPLY_NOT_AUTHORIZED")
        dummy_source = source.get_workflow(source_workflow_id) or {"id": source_workflow_id, "name": None, "nodes": []}
        manifest = build_manifest(
            mode="apply-blocked",
            source_inspect=inspect_workflow(dummy_source) if dummy_source.get("nodes") is not None and dummy_source.get("name") else {
                "id": source_workflow_id,
                "name": None,
                "active": False,
                "version_id": None,
                "settings": {},
                "node_count": 0,
                "connection_count": 0,
            },
            target_inspect=None,
            table_report=[],
            credential_report=[],
            checksums={"source_logic_sha256": "0" * 64},
            blockers=sorted(set(blockers)),
            differences=[],
            errors=["apply is not implemented in preparation tooling"],
        )
        return {"ok": False, "manifest": manifest, "writes": []}

    wf = source.get_workflow(source_workflow_id)
    if wf is None:
        raise SafetyStop("SOURCE_WORKFLOW_MISSING", "SOURCE workflow export not found.")
    assert_no_secret_material(wf, context="source_workflow")

    source_inspect = inspect_workflow(wf)
    source_tables = source.list_tables()
    target_tables = target.list_tables()
    table_report = compare_table_schemas(source_tables, target_tables)

    cred_report = build_credential_report(
        wf,
        source.list_credentials_metadata(),
        target.list_credentials_metadata(),
    )

    table_id_by_name = {
        row["name"]: row["target_id"]
        for row in table_report
        if row.get("target_id") and row.get("schema_status") in {"match", "mismatch"}
    }

    differences: list[str] = []
    errors: list[str] = []
    prepared = None
    prepared_sha = None
    try:
        prepared = prepare_workflow_for_target(
            wf,
            table_id_by_name=table_id_by_name,
            credential_id_by_type_name=mapping_index(cred_report),
            allow_unmapped_disabled_credentials=True,
        )
        assert_prepared_safe(prepared)
        prepared_sha = logic_checksum(prepared)
    except (RemapError, SecretGuardError) as exc:
        errors.append(f"{exc.code}:{exc}")

    existing = target.find_workflow_by_name(str(wf.get("name")))
    target_inspect = inspect_workflow(existing) if existing else None
    wf_cmp = compare_workflows(wf, existing, prepared=prepared)
    differences.extend(wf_cmp.get("differences") or [])
    for row in table_report:
        for d in row.get("differences") or []:
            differences.append(f"table:{row['name']}:{d}")
    for row in cred_report:
        if row.get("mapping_status") != "mapped":
            differences.append(
                f"credential:{row.get('type')}/{row.get('source_name')}:{row.get('mapping_status')}"
            )

    blockers = evaluate_blockers(
        credential_report=cred_report,
        table_report=table_report,
        prepared=prepared,
        apply_requested=False,
        apply_flags_ok=False,
        live_network=False,
        source_write=False,
        target_existing=existing if existing and prepared_sha else None,
        prepared_logic_sha256=prepared_sha if existing else None,
    )

    # Duplicate with identical fingerprint is a no-op, not a blocker.
    if existing and prepared_sha and "DUPLICATE_WORKFLOW_DRIFT" not in blockers:
        noop = True
    else:
        noop = False

    planned = []
    if mode == "dry-run" and not blockers:
        if not existing:
            planned.append(target.plan_create_workflow(prepared or {"name": wf.get("name")}))
        for row in table_report:
            if row["schema_status"] == "missing_target":
                planned.append(target.plan_create_table({"name": row["name"]}))
        if include_rows:
            errors.append("include_rows requested but row copy is owner-gated and not executed")

    checksums = {
        "source_logic_sha256": source_inspect["logic_sha256"],
        "prepared_logic_sha256": prepared_sha,
        "target_logic_sha256": target_inspect["logic_sha256"] if target_inspect else None,
    }

    out_mode = mode if mode in {"inspect", "compare", "dry-run", "validate"} else "inspect"
    manifest = build_manifest(
        mode=out_mode,
        source_inspect=source_inspect,
        target_inspect=target_inspect,
        table_report=table_report,
        credential_report=cred_report,
        checksums=checksums,
        blockers=blockers,
        differences=differences,
        errors=errors,
        source_host=source_host,
        target_host=target_host,
        writes_performed=[],
    )
    if noop:
        manifest["migration_status"] = "noop"

    man_errors = validate_manifest(manifest)
    if man_errors:
        manifest["errors"] = list(manifest.get("errors") or []) + [f"manifest:{e}" for e in man_errors]
        manifest["validation_status"] = "fail"

    return {
        "ok": not blockers and not man_errors,
        "manifest": manifest,
        "planned": planned,
        "writes": [],
        "source_inspect": source_inspect,
        "table_report": table_report,
        "prepared": prepared,
        "tables_inspected": inspect_tables(source_tables),
    }
