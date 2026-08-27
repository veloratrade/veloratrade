#!/usr/bin/env python3
"""n8n SOURCE→TARGET migration CLI.

Offline commands (inspect/compare/dry-run/validate/apply) run purely on JSON
export fixtures and are read-only / dry-run. ``apply`` is always refused.

``live-inspect`` (Phase 3A) performs READ-ONLY live inspection of the real
SOURCE and TARGET n8n instances using ``X-N8N-API-KEY`` auth read from the
environment. It requires ``--allow-live-read`` and never writes, activates,
publishes, executes, or registers webhooks on either instance.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))

from client import FileBundle, SourceReadOnly, TargetDryRun, refuse_live_http  # noqa: E402
from pipeline import run_pipeline  # noqa: E402
from safety import SafetyStop  # noqa: E402

Json = dict[str, Any]


def _load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def _bundle_from_args(prefix: str, args: argparse.Namespace) -> FileBundle:
    wf_path = getattr(args, f"{prefix}_workflow")
    tables_path = getattr(args, f"{prefix}_tables")
    creds_path = getattr(args, f"{prefix}_credentials")
    workflows: dict[str, Json] = {}
    if wf_path:
        wf = _load(Path(wf_path))
        workflows[str(wf.get("id"))] = wf
        if wf.get("name"):
            workflows.setdefault(str(wf["name"]), wf)
    tables = _load(Path(tables_path)) if tables_path else []
    creds = _load(Path(creds_path)) if creds_path else []
    if not isinstance(tables, list):
        tables = tables.get("tables") or []
    if not isinstance(creds, list):
        creds = creds.get("credentials") or []
    return FileBundle(workflows=workflows, tables=tables, credentials=creds, label=prefix)


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(description="Velora n8n instance migration (preparation).")
    p.add_argument(
        "command",
        choices=["inspect", "compare", "dry-run", "validate", "apply", "live-inspect", "verify-connection"],
        help="apply is always refused by this preparation CLI; live-inspect and verify-connection are read-only",
    )
    # Offline export paths (required only for offline commands).
    p.add_argument("--source-workflow", default=None)
    p.add_argument("--source-tables", default=None)
    p.add_argument("--source-credentials", default=None)
    p.add_argument("--target-workflow", default=None)
    p.add_argument("--target-tables", default=None)
    p.add_argument("--target-credentials", default=None)
    p.add_argument("--source-workflow-id", default=None)
    p.add_argument("--include-rows", action="store_true")
    p.add_argument("--allow-live-read", action="store_true")
    p.add_argument("--i-understand-this-writes-to-target", action="store_true")
    p.add_argument("--owner-authorized-apply", action="store_true")
    p.add_argument("--config", default="content/n8n-integration/integration.json",
                   help="Path to the non-secret n8n integration policy file")
    p.add_argument("--out", default=None, help="Write manifest/report JSON to this path")
    return p


def _run_verify_connection(args: argparse.Namespace) -> int:
    if not args.allow_live_read:
        print("STOP LIVE_READ_FLAG_REQUIRED: --allow-live-read is required for verify-connection")
        return 2

    from integration import LiveReadError, load_integration_config, run_verification  # noqa: E402

    try:
        config = load_integration_config(args.config)
        report = run_verification(config)
    except LiveReadError as exc:
        print(f"STOP {exc.code}: {exc}")
        return 2

    if args.out:
        Path(args.out).write_text(
            json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
        )

    print("mode=verify-connection (READ-ONLY)")
    for inst in report["instances"]:
        print(
            f"instance={inst['label']} host={inst['base_url_host']} "
            f"reachable={inst['reachable']} authenticated={inst['authenticated']} "
            f"blocked={[c.get('code') for c in inst['findings']]}"
        )
        print(
            f"  discovered workflows={len(inst['discovered_ids'].get('workflows') or [])} "
            f"tables={len(inst['discovered_ids'].get('data_tables') or [])} "
            f"credentials={len(inst['discovered_ids'].get('credentials') or [])}"
        )
    print("write_capabilities=disabled writes=[]")
    return 0 if all(i["reachable"] and i["authenticated"] for i in report["instances"]) else 1


def _run_live_inspect(args: argparse.Namespace) -> int:
    if not args.allow_live_read:
        print("STOP LIVE_READ_FLAG_REQUIRED: --allow-live-read is required for live-inspect")
        return 2

    from live_client import LiveReadClient, LiveReadError, load_config  # noqa: E402
    from live_report import build_inventory, build_live_report  # noqa: E402

    try:
        source_cfg = load_config("SOURCE")
        target_cfg = load_config("TARGET")
    except LiveReadError as exc:
        print(f"STOP {exc.code}: {exc}")
        return 2

    source_client = LiveReadClient(source_cfg, label="SOURCE")
    target_client = LiveReadClient(target_cfg, label="TARGET")

    try:
        source_inv = build_inventory(
            source_client, label="SOURCE", include_row_count=source_cfg.include_row_count
        )
        target_inv = build_inventory(
            target_client, label="TARGET", include_row_count=target_cfg.include_row_count
        )
        report = build_live_report(source_inv, target_inv)
    except LiveReadError as exc:
        print(f"STOP {exc.code}: {exc}")
        return 2

    if args.out:
        Path(args.out).write_text(
            json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
        )

    print("mode=live-inspect (READ-ONLY)")
    print(f"source={report['REAL_SOURCE_INVENTORY'].get('base_url_host')} "
          f"workflows={len(report['REAL_SOURCE_INVENTORY']['workflows'])}")
    print(f"target={report['REAL_TARGET_INVENTORY'].get('base_url_host')} "
          f"workflows={len(report['REAL_TARGET_INVENTORY']['workflows'])}")
    print(f"workflow_mapping={len(report['WORKFLOW_MAPPING'])}")
    print(f"data_table_mapping={len(report['DATA_TABLE_MAPPING'])}")
    print(f"missing_credentials={len(report['MISSING_CREDENTIALS'])}")
    print(f"blockers={len(report['BLOCKERS'])}")
    for b in report["BLOCKERS"]:
        print(f"  STOP {b}")
    print("writes=[]")
    return 0 if not report["BLOCKERS"] else 1


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)

    if args.command == "live-inspect":
        return _run_live_inspect(args)

    if args.command == "verify-connection":
        return _run_verify_connection(args)

    if args.allow_live_read:
        print("STOP LIVE_NETWORK_FORBIDDEN: live n8n is disabled in preparation tooling")
        refuse_live_http()
        return 2

    missing = [
        name for name in (
            "source_workflow", "source_tables", "source_credentials",
            "target_tables", "target_credentials",
        )
        if not getattr(args, name)
    ]
    if missing:
        print("ERROR: offline commands require export paths: " + ", ".join(missing))
        return 2

    source_bundle = _bundle_from_args("source", args)
    target_bundle = _bundle_from_args("target", args)
    source = SourceReadOnly(source_bundle)
    target = TargetDryRun(target_bundle)

    wf = _load(Path(args.source_workflow))
    wf_id = args.source_workflow_id or str(wf.get("id"))

    apply_requested = args.command == "apply"
    apply_flags_ok = bool(args.i_understand_this_writes_to_target and args.owner_authorized_apply)
    mode = "apply-blocked" if apply_requested else args.command

    try:
        result = run_pipeline(
            mode=mode if mode != "apply-blocked" else "inspect",
            source=source,
            target=target,
            source_workflow_id=wf_id,
            include_rows=args.include_rows,
            apply_requested=apply_requested,
            apply_flags_ok=apply_flags_ok,
        )
    except SafetyStop as exc:
        print(f"STOP {exc.code}: {exc}")
        return 2

    manifest = result["manifest"]
    if args.out:
        Path(args.out).write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    print(f"mode={manifest.get('mode')} status={manifest.get('migration_status')} validation={manifest.get('validation_status')}")
    print(f"source={manifest['source'].get('workflow_id')} {manifest['source'].get('workflow_name')}")
    print(f"target={manifest['target'].get('workflow_id')}")
    print(f"blockers={len(manifest.get('blockers') or [])}")
    for b in manifest.get("blockers") or []:
        print(f"  STOP {b}")
    print(f"differences={len(manifest.get('differences') or [])}")
    print(f"writes={manifest.get('writes_performed')}")
    if apply_requested:
        print("APPLY refused (preparation CLI never writes).")
        return 2
    return 0 if result.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
