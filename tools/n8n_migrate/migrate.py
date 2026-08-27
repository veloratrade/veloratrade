#!/usr/bin/env python3
"""n8n SOURCE→TARGET migration CLI. Preparation default: inspect/compare/dry-run only."""

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
        choices=["inspect", "compare", "dry-run", "validate", "apply"],
        help="apply is always refused by this preparation CLI",
    )
    p.add_argument("--source-workflow", required=True)
    p.add_argument("--source-tables", required=True)
    p.add_argument("--source-credentials", required=True)
    p.add_argument("--target-workflow", default=None)
    p.add_argument("--target-tables", required=True)
    p.add_argument("--target-credentials", required=True)
    p.add_argument("--source-workflow-id", default=None)
    p.add_argument("--include-rows", action="store_true")
    p.add_argument("--allow-live-read", action="store_true")
    p.add_argument("--i-understand-this-writes-to-target", action="store_true")
    p.add_argument("--owner-authorized-apply", action="store_true")
    p.add_argument("--out", default=None, help="Write manifest JSON to this path")
    return p


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    if args.allow_live_read:
        print("STOP LIVE_NETWORK_FORBIDDEN: live n8n is disabled in preparation tooling")
        refuse_live_http()
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
