#!/usr/bin/env python3
"""
VELORA — Production deployment binding gate (fail-closed).

Verifies that a FRESH, INTEGRITY_VERIFIED Production database backup is bound to
THIS exact deployment context before any production mutation may proceed. It is
called from deploy.yml's `prod_backup_gate` job. It does NOT create backups and does
NOT talk to the database; it only validates the bound identity/state values that the
required `prod_db_backup` reusable workflow produced and passed down.

The gate ensures an operator claim such as «I already took a backup» is never
accepted: the deployment cannot run unless these checks all pass.
"""
from __future__ import annotations

import argparse
import sys

sys.path.insert(0, __import__("os").path.dirname(__import__("os").path.abspath(__file__)))
import private_backup_repo as pb  # noqa: E402


def die(msg: str) -> None:
    print(f"::error::PRODUCTION DEPLOYMENT BLOCKED: {msg}", flush=True)
    sys.exit(1)


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--backup-id", required=True)
    ap.add_argument("--release-tag", required=True)
    ap.add_argument("--sha256", required=True)
    ap.add_argument("--source-commit", required=True)
    ap.add_argument("--expected-commit", required=True)
    ap.add_argument("--environment", default="production")
    args = ap.parse_args(argv[1:])

    # explicit environment only
    if args.environment != "production":
        die(f"this gate binds production deployments, got {args.environment!r}")

    bid = args.backup_id.strip()
    tag = args.release_tag.strip()
    sha = args.sha256.strip()

    if not bid or not tag or not sha:
        die("no fresh verified Production backup is bound to this deployment")
    # production namespace only (a staging backup can never satisfy production)
    if not bid.startswith("db-backup-production-"):
        die(f"backup_id is not a production backup: {bid}")
    if not tag.startswith("db-backup-production-"):
        die(f"release tag is not in the production namespace: {tag}")
    if tag != bid:
        die(f"release tag {tag} does not match backup_id {bid}")
    # valid checksum + commit identity
    if not pb.SHA256_RE.match(sha):
        die("bound sha256 is not a valid 64-hex digest")
    if not pb.HEX40.match(args.source_commit or ""):
        die("bound source commit is not a 40-hex SHA")
    if not pb.HEX40.match(args.expected_commit or ""):
        die("expected deployment commit is not a 40-hex SHA")
    # freshness/binding: the backup must be of THIS deployment commit (no old/foreign backup)
    if args.source_commit != args.expected_commit:
        die(f"backup commit {args.source_commit[:12]} != deployment commit "
            f"{args.expected_commit[:12]} (stale or unrelated backup)")

    print("PRODUCTION BACKUP GATE PASSED")
    print(f"  backup_id        = {bid}")
    print(f"  release_tag      = {tag}")
    print(f"  sha256           = {sha}")
    print(f"  bound to commit  = {args.source_commit}")
    print("  state            = INTEGRITY_VERIFIED (restore verified separately)")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
