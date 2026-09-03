#!/usr/bin/env python3
"""
VELORA — Production deployment binding gate (fail-closed).

Verifies that a FRESH, INTEGRITY_VERIFIED Production database backup is bound to
THIS exact deployment context before any production mutation may proceed. It is
called from deploy.yml's `prod_backup_gate` job. It does NOT create backups and does
NOT talk to the database; it only validates the bound identity/state values that the
required `prod_db_backup` reusable workflow produced and passed down, AND — by
default — independently RE-READS the private backup repository to confirm the bound
backup actually exists, is in the production namespace, is INTEGRITY_VERIFIED, and
matches the deployment commit and checksum.

The independent check prevents an operator/upstream claim such as «I already took a
backup» from being accepted on the strength of workflow outputs alone. When the
private repo cannot be contacted (no credential / offline), the gate FAILS CLOSED
for a real deployment. Tests inject a mock client via `--client` injection hook
(`build_client`) so the verification logic is exercised without network or secrets.
"""
from __future__ import annotations

import argparse
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import private_backup_repo as pb  # noqa: E402
import backup_repo_client as bc  # noqa: E402
import backup as bk            # noqa: E402


def die(msg: str) -> None:
    print(f"::error::PRODUCTION DEPLOYMENT BLOCKED: {msg}", flush=True)
    sys.exit(1)


def build_client():
    """Construct the real private-repo client. Raises (fail closed) if
    BACKUP_REPO_TOKEN is absent — there is NO GITHUB_TOKEN fallback."""
    return bc.Client()


def verify_bound_backup(client, *, backup_id: str, release_tag: str, sha256: str,
                        expected_commit: str) -> dict:
    """Independently re-read the private backup repository and confirm the bound
    backup is real, production-scoped, INTEGRITY_VERIFIED, and matches the bound
    identity/checksum/commit. Raises BackupRepoClientError on any discrepancy."""
    # 1) namespace binding must be production BEFORE any read (cross-env refused).
    if not backup_id.startswith("db-backup-production-"):
        die(f"backup_id is not a production backup: {backup_id}")
    if not release_tag.startswith("db-backup-production-"):
        die(f"release tag is not in the production namespace: {release_tag}")

    # 2) the release must exist in the PRIVATE repo under that exact tag.
    release = client.get_release_by_tag("production", release_tag)
    asset_names = [a.get("name") for a in release.get("assets", [])]
    if not any((n or "").endswith(".sql.gz") for n in asset_names):
        die(f"release {release_tag} has no .sql.gz backup asset in the private repo")

    # 3) the committed metadata must exist and match on every bound field.
    meta = client.get_backup_metadata("production", backup_id)
    if meta.get("environment") != "production":
        die(f"metadata environment is {meta.get('environment')!r}, expected production "
            f"(cross-environment backup blocked)")
    if meta.get("backup_id") != backup_id:
        die(f"metadata backup_id {meta.get('backup_id')!r} != bound {backup_id!r}")
    storage = meta.get("storage", {})
    if storage.get("repository") != pb.PRIVATE_BACKUP_REPO:
        die("bound backup is not stored in the PRIVATE velora-backups repository")
    if storage.get("release_tag") != release_tag:
        die(f"metadata release_tag {storage.get('release_tag')!r} != bound {release_tag!r}")
    if meta.get("sha256") != sha256:
        die("metadata sha256 does not match the bound checksum (backup mismatch)")
    # 4) state must actually be INTEGRITY_VERIFIED in the repo (not merely claimed).
    state = meta.get("verification_status", bk.STATE_UNVERIFIED)
    if not bk.state_at_least(state, bk.STATE_INTEGRITY_VERIFIED):
        die(f"backup state in repo is {state!r}, required INTEGRITY_VERIFIED")
    # 5) the backup must be of THIS deployment commit (no stale/foreign backup).
    src_commit = (meta.get("source_commit_sha") or "").strip()
    if src_commit != expected_commit:
        die(f"backup commit {src_commit[:12]} != deployment commit "
            f"{expected_commit[:12]} (stale or unrelated backup)")
    return {"backup_id": backup_id, "release_tag": release_tag,
            "state": state, "source_commit_sha": src_commit,
            "verified_against_private_repo": True}


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--backup-id", required=True)
    ap.add_argument("--release-tag", required=True)
    ap.add_argument("--sha256", required=True)
    ap.add_argument("--source-commit", required=True)
    ap.add_argument("--expected-commit", required=True)
    ap.add_argument("--environment", default="production")
    ap.add_argument("--no-remote-verify", action="store_true",
                    help="TEST ONLY: skip the independent private-repo re-read. "
                         "Never used by the real deploy workflow.")
    args = ap.parse_args(argv[1:])

    # explicit production environment only
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
    # valid checksum + commit identity shape
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

    # Independent verification against the PRIVATE backup repository. This is the
    # strengthening step: do not trust upstream workflow outputs alone.
    if not args.no_remote_verify:
        try:
            client = build_client()
        except Exception as e:  # missing credential / misconfiguration => fail closed
            die(f"cannot establish private backup-repository verification: {e}")
        try:
            verify_bound_backup(client, backup_id=bid, release_tag=tag,
                                sha256=sha, expected_commit=args.expected_commit)
        except bc.BackupRepoClientError as e:
            die(f"independent backup verification failed: {e}")
    else:
        print("WARNING: --no-remote-verify supplied; independent private-repo "
              "re-read SKIPPED (test-only path, never used by real deploy).")

    print("PRODUCTION BACKUP GATE PASSED")
    print(f"  backup_id        = {bid}")
    print(f"  release_tag      = {tag}")
    print(f"  sha256           = {sha}")
    print(f"  bound to commit  = {args.source_commit}")
    print(f"  state            = INTEGRITY_VERIFIED "
          f"({'independently re-verified vs private repo' if not args.no_remote_verify else 'claimed upstream; remote verify SKIPPED'})")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
