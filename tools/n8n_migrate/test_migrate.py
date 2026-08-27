#!/usr/bin/env python3
"""Synthetic migration-prep tests. No live n8n, Telegram, Git, or Production."""

from __future__ import annotations

import copy
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))

from client import FileBundle, SourceReadOnly, TargetDryRun  # noqa: E402
from compare import compare_table_schemas, compare_workflows  # noqa: E402
from credentials import build_credential_report, mapping_index  # noqa: E402
from manifest import validate_manifest  # noqa: E402
from model import find_datatable_refs, find_expressions, inspect_workflow, logic_checksum  # noqa: E402
from pipeline import run_pipeline  # noqa: E402
from remap import assert_prepared_safe, prepare_workflow_for_target  # noqa: E402
from safety import SafetyStop, evaluate_blockers  # noqa: E402
from secrets_guard import SecretGuardError, assert_no_secret_material, credential_public_view  # noqa: E402

FIX = ROOT / "fixtures"


def load(name: str):
    return json.loads((FIX / name).read_text(encoding="utf-8"))


def need(passed: bool, label: str, failed: list[str]) -> None:
    print(f"{label}: {'OK' if passed else 'NOT_OK'}")
    if not passed:
        failed.append(label)


def source_target(tables="target_tables.json", creds="target_credentials.json", twf=None):
    swf = load("source_workflow.json")
    source = SourceReadOnly(
        FileBundle(
            workflows={swf["id"]: swf},
            tables=load("source_tables.json"),
            credentials=load("source_credentials.json"),
        )
    )
    tw_map = {}
    if twf is not None:
        tw_map[twf["id"]] = twf
        tw_map[twf["name"]] = twf
    target = TargetDryRun(
        FileBundle(
            workflows=tw_map,
            tables=load(tables),
            credentials=load(creds),
        )
    )
    return swf, source, target


def main() -> int:
    failed: list[str] = []
    swf = load("source_workflow.json")

    inspected = inspect_workflow(swf)
    need(inspected["node_count"] == 6, "inspect_node_count", failed)
    need(inspected["connection_count"] == 1, "inspect_connections", failed)
    need(inspected["settings"]["timezone"] == "Asia/Tehran", "inspect_timezone", failed)
    need(any(r["name"] == "P1 — Store Snapshot" and r["retryOnFail"] for r in inspected["retry_error"]), "inspect_retry", failed)
    need(any(n["name"] == "Article — OpenAI Draft" and n["disabled"] for n in inspected["nodes"]), "inspect_disabled", failed)
    need(inspected["telegram_triggers"][0]["disabled"] is False, "inspect_telegram_enabled_on_source", failed)
    need(len(find_datatable_refs(swf)) == 1, "inspect_datatable_refs", failed)
    need(len(find_expressions(swf)) >= 1, "inspect_expressions", failed)
    sha1 = logic_checksum(swf)
    sha2 = logic_checksum(copy.deepcopy(swf))
    need(sha1 == sha2 and len(sha1) == 64, "checksum_stable", failed)

    creds = build_credential_report(swf, load("source_credentials.json"), load("target_credentials.json"))
    by_type = {c["type"]: c for c in creds}
    need(by_type["telegramApi"]["mapping_status"] == "mapped", "cred_telegram_mapped", failed)
    need(by_type["googleOAuth2Api"]["mapping_status"] == "mapped", "cred_google_mapped", failed)
    need(by_type["openAiApi"]["mapping_status"] == "missing_on_target", "cred_openai_missing", failed)
    need(all(c["secret_transfer"] == "never" for c in creds), "cred_never_transfer_flag", failed)
    need("data" not in json.dumps(creds), "cred_report_no_data_field", failed)

    tables = compare_table_schemas(load("source_tables.json"), load("target_tables.json"))
    need(all(t["schema_status"] == "match" for t in tables), "schema_match", failed)
    bad = compare_table_schemas(load("source_tables.json"), load("target_tables_mismatch.json"))
    scan = next(t for t in bad if t["name"] == "seo_scan_snapshots")
    need(scan["schema_status"] == "mismatch", "schema_mismatch_detected", failed)
    opp = next(t for t in bad if t["name"] == "seo_opportunities")
    need(opp["schema_status"] == "missing_target", "schema_missing_target", failed)

    table_ids = {"seo_scan_snapshots": "tgtScan001"}
    prepared = prepare_workflow_for_target(
        swf,
        table_id_by_name=table_ids,
        credential_id_by_type_name=mapping_index(creds),
        allow_unmapped_disabled_credentials=True,
    )
    store = next(n for n in prepared["nodes"] if n["name"] == "P1 — Store Snapshot")
    need(store["parameters"]["dataTableId"]["value"] == "tgtScan001", "remap_table_id", failed)
    tg = next(n for n in prepared["nodes"] if n["type"] == "n8n-nodes-base.telegramTrigger")
    need(tg.get("disabled") is True and "webhookId" not in tg, "prepare_strips_webhook_and_disables_tg", failed)
    sched = next(n for n in prepared["nodes"] if n["type"] == "n8n-nodes-base.scheduleTrigger")
    need(sched.get("disabled") is True, "prepare_disables_schedule", failed)
    need(prepared.get("active") is False, "prepare_inactive", failed)
    gsc = next(n for n in prepared["nodes"] if n["name"] == "P2A — Fetch GSC")
    need(gsc["credentials"]["googleOAuth2Api"]["id"] == "tgtGo01", "remap_google_cred_id", failed)
    try:
        assert_prepared_safe(prepared)
        need(True, "prepared_safe", failed)
    except Exception as exc:  # noqa: BLE001
        print("  prepared_safe error", exc)
        need(False, "prepared_safe", failed)

    twf = load("target_workflow.json")
    cmp = compare_workflows(swf, twf, prepared=prepared)
    need("target_is_active" not in cmp["differences"], "compare_target_inactive", failed)
    need("telegram_trigger_enabled:TELEGRAM — Article Actions" not in cmp["differences"], "compare_tg_disabled", failed)
    need(cmp["source_logic_sha256"] != cmp["prepared_logic_sha256"], "checksum_source_vs_prepared_differs_on_gates", failed)

    # SOURCE write forbidden
    _swf, source, target = source_target()
    try:
        source.write("nope")
        need(False, "source_write_forbidden", failed)
    except SafetyStop as exc:
        need(exc.code == "SOURCE_WRITE_FORBIDDEN", "source_write_forbidden", failed)

    try:
        target.activate()
        need(False, "target_activate_forbidden", failed)
    except SafetyStop as exc:
        need(exc.code == "PUBLISH_OR_ACTIVATE_REQUESTED", "target_activate_forbidden", failed)

    try:
        target.write()
        need(False, "target_dry_run_write_forbidden", failed)
    except SafetyStop as exc:
        need(exc.code == "APPLY_NOT_AUTHORIZED", "target_dry_run_write_forbidden", failed)

    # Dry-run with empty TARGET: plan create, no writes
    _swf, source, target = source_target()
    result = run_pipeline(
        mode="dry-run",
        source=source,
        target=target,
        source_workflow_id=swf["id"],
    )
    man = result["manifest"]
    need(result["writes"] == [], "dry_run_no_writes", failed)
    need(man["writes_performed"] == [], "manifest_no_writes", failed)
    need(man["safety"]["credentials_transfer"] is False, "manifest_no_cred_transfer", failed)
    need(not validate_manifest(man), "manifest_schema_ok", failed)
    need(any(p.get("op") == "create_workflow" for p in result["planned"]), "dry_run_plans_create", failed)
    need(man["migration_status"] in {"dry_run_ok", "inspected"}, "dry_run_status", failed)
    print("  dry-run blockers", man.get("blockers"))

    # Duplicate identical TARGET -> noop
    _swf, source, target = source_target()
    target.bundle.workflows[twf["id"]] = twf
    target.bundle.workflows[twf["name"]] = twf
    result = run_pipeline(mode="dry-run", source=source, target=target, source_workflow_id=swf["id"])
    need(result["manifest"]["migration_status"] == "noop", "idempotent_noop", failed)
    need(result["planned"] == [], "idempotent_no_plan", failed)

    # Drift duplicate
    drifted = copy.deepcopy(twf)
    drifted["nodes"] = [n for n in drifted["nodes"] if n["name"] != "P2A — Fetch GSC"]
    _swf, source, target = source_target()
    target.bundle.workflows[drifted["id"]] = drifted
    target.bundle.workflows[drifted["name"]] = drifted
    result = run_pipeline(mode="compare", source=source, target=target, source_workflow_id=swf["id"])
    need("DUPLICATE_WORKFLOW_DRIFT" in result["manifest"]["blockers"], "duplicate_drift_stop", failed)

    # Schema mismatch STOP
    _swf, source, target = source_target(tables="target_tables_mismatch.json")
    result = run_pipeline(mode="validate", source=source, target=target, source_workflow_id=swf["id"])
    need(any(b.startswith("SCHEMA_MISMATCH") for b in result["manifest"]["blockers"]), "schema_mismatch_stop", failed)

    # Enabled unmapped credential STOP
    no_tg = [c for c in load("target_credentials.json") if c["type"] != "telegramApi"]
    _swf, source, target = source_target()
    target.bundle.credentials = no_tg
    result = run_pipeline(mode="dry-run", source=source, target=target, source_workflow_id=swf["id"])
    need(any(b.startswith("UNMAPPED_CREDENTIAL:telegramApi") for b in result["manifest"]["blockers"]), "unmapped_enabled_cred_stop", failed)

    # Secret extraction refused
    try:
        credential_public_view({"id": "x", "name": "n", "data": {"oauthToken": "secret"}}, "telegramApi")
        need(False, "secret_field_rejected", failed)
    except SecretGuardError as exc:
        need(exc.code == "CREDENTIAL_SECRET_PRESENT", "secret_field_rejected", failed)

    try:
        assert_no_secret_material({"note": "github_pat_00SYNTHETICNOTAREALTOKEN0000000000000000000000000000"}, context="wf")
        need(False, "pat_in_export_rejected", failed)
    except SecretGuardError as exc:
        need(exc.code == "GITHUB_PAT_IN_N8N", "pat_in_export_rejected", failed)

    # Apply always blocked
    _swf, source, target = source_target()
    result = run_pipeline(
        mode="inspect",
        source=source,
        target=target,
        source_workflow_id=swf["id"],
        apply_requested=True,
        apply_flags_ok=True,
    )
    need(result["manifest"]["mode"] == "apply-blocked", "apply_blocked_mode", failed)
    need("APPLY_NOT_AUTHORIZED" in result["manifest"]["blockers"], "apply_blocked_even_with_flags", failed)
    need(result["writes"] == [], "apply_no_writes", failed)

    live = evaluate_blockers(live_network=True)
    need("LIVE_NETWORK_FORBIDDEN" in live, "live_network_stop", failed)
    act = evaluate_blockers(activate_requested=True, publish_requested=True, execute_requested=True)
    need("PUBLISH_OR_ACTIVATE_REQUESTED" in act, "activate_publish_execute_stop", failed)

    print("SUMMARY failed=%s %s" % (len(failed), failed))
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
