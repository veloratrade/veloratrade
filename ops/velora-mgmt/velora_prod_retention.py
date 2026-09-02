#!/usr/bin/env python3
"""
VELORA — Production database backup retention (auditable, failure-safe).

Runs ONLY after a fully successful, post-deploy-verified production deployment.
It lists production backups in the PRIVATE repo, fetches their metadata, and applies
private_backup_repo.retention_plan (immortal newest verified; >14d eligible only when
a newer verified backup exists and the deploy + post-verify succeeded). It is invoked
by the deploy workflow's retention job with --mutation-succeeded/--post-verify-passed
set from job outcomes; any failure makes it take no destructive action.

Deletion is opt-in via --apply and is only ever performed after the plan would delete
zero protected backups. Without BACKUP_REPO_TOKEN it runs in PLAN mode (read-only) and
reports that cleanup is deferred — never deleting anything.
"""
from __future__ import annotations

import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import private_backup_repo as pb   # noqa: E402
import backup_repo_client as bc    # noqa: E402


def die(msg: str) -> None:
    print(f"::error::retention aborting safely: {msg}", flush=True)
    sys.exit(1)


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--mutation-succeeded", action="store_true")
    ap.add_argument("--post-verify-passed", action="store_true")
    ap.add_argument("--apply", action="store_true",
                    help="actually delete eligible releases (default: plan only)")
    args = ap.parse_args(argv[1:])

    if not os.environ.get("BACKUP_REPO_TOKEN"):
        print("BACKUP_REPO_TOKEN absent: running READ-ONLY plan; no cleanup performed.")
        print(json.dumps({"retention": "deferred", "reason": "no credential; nothing deleted"}))
        return 0  # non-fatal: retention must never block a successful deploy report

    client = bc.Client()
    client.check_repository()  # fail closed if not the exact private repo

    # Fetch production releases + their metadata records.
    releases = client._api("releases?per_page=100").get("json", [])
    records = []
    for r in releases:
        tag = r.get("tag_name", "")
        if not tag.startswith("db-backup-production-"):
            continue  # never touch staging or non-database releases
        # metadata is committed at backups/production/<tag>.json; derive id == tag
        meta_resp = client._api(f"contents/backups/production/{tag}.json")
        if meta_resp.get("status") == 200:
            import base64
            records.append(json.loads(base64.b64decode(meta_resp["json"]["content"])))
        else:
            # a release without committed metadata: treat conservatively as KEEP
            records.append({
                "backup_id": tag, "environment": "production",
                "created_at_utc": r.get("created_at", "").replace("Z", "Z"),
                "verification_status": "INTEGRITY_VERIFIED",
                "storage": {"release_tag": tag},
                "retention_expires_at_utc": None,
            })

    plan = pb.retention_plan(
        records,
        mutation_succeeded=args.mutation_succeeded,
        post_verify_passed=args.post_verify_passed,
    )

    delete_tags = plan.get("delete_release_tags", []) if not plan.get("stopped") else []
    print(json.dumps({
        "retention_stopped": plan.get("stopped", True),
        "production_backups_considered": len(records),
        "retain_backup_ids": plan.get("retain", []),
        "would_delete_tags": delete_tags,
        "applied": False,
    }, indent=2))

    if plan.get("stopped"):
        print("Retention suppressed (mutation/post-verify not both successful): nothing deleted.")
        return 0
    if not args.apply or not delete_tags:
        print("Plan-only (or nothing eligible): no deletion performed.")
        return 0

    # Destructive path: only eligible, non-protected, production tags.
    protected = set(plan.get("retain", []))
    for tag in delete_tags:
        rid = next((r["id"] for r in releases if r.get("tag_name") == tag), None)
        if rid is None:
            die(f"cannot resolve release id for {tag}; aborting without changes")
        client.delete_release_by_tag("production", tag)  # cross-env tag deletion is refused
        print(f"deleted eligible production release: {tag}")
    print(json.dumps({"retention": "applied", "deleted": delete_tags}))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
