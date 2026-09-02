#!/usr/bin/env python3
"""
VELORA — store a freshly-created, already-verified Production DB backup in the
PRIVATE backup repository as a GitHub Release Asset, commit secret-free metadata,
and byte-verify the uploaded asset.

Intended to run INSIDE the GitHub Actions production backup job
(.github/workflows/velora-db-backup.yml), where BACKUP_REPO_TOKEN is available.
It never prints the token, never writes the dump to Git, and fails closed on any
error so a caller that `needs:` this job cannot deploy on failure.

Usage:
  velora_prod_backup_upload.py <probe-result.json> <source_commit_sha> <run_id> <dump.sql.gz>
"""
from __future__ import annotations

import gzip
import hashlib
import io
import json
import os
import sys
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import private_backup_repo as pb   # noqa: E402
import backup_repo_client as bc    # noqa: E402


def die(msg: str) -> None:
    print(f"::error::{msg}", flush=True)
    sys.exit(1)


def main(argv: list[str]) -> int:
    if len(argv) != 5:
        die("usage: velora_prod_backup_upload.py <probe.json> <commit_sha> <run_id> <dump.sql.gz>")
    probe_path, commit_sha, run_id, dump_path = argv[1], argv[2], argv[3], argv[4]

    probe = json.load(open(probe_path))
    if probe.get("env") != "production":
        die(f"refuse: probe env is {probe.get('env')!r}, expected production")
    if probe.get("ok") is not True or probe.get("verification_status") != "INTEGRITY_VERIFIED":
        die("refuse: probe did not report an INTEGRITY_VERIFIED production backup")
    if not pb.HEX40.match(commit_sha or ""):
        die("refuse: source commit sha must be a 40-hex SHA")

    backup_id = probe["backup_id"]
    if not backup_id.startswith("db-backup-production-"):
        die(f"refuse: backup_id is not in the production namespace: {backup_id}")
    tag = probe["release_tag"]
    asset = probe["release_asset"]
    meta_path = f"backups/production/{backup_id}.json"
    expected_sha = probe["sha256"]
    if not pb.SHA256_RE.match(expected_sha or ""):
        die("refuse: probe sha256 is not a 64-hex digest")

    data = open(dump_path, "rb").read()
    local_sha = hashlib.sha256(data).hexdigest()
    if local_sha != expected_sha:
        die(f"local dump sha256 {local_sha} != probe sha256 {expected_sha}")

    # Metadata (small, secret-free, environment/backup-id/checksum-bound).
    metadata = {
        "schema": "velora-db-backup/1",
        "backup_id": backup_id,
        "environment": "production",
        "backup_type": "database",
        "source_commit_sha": commit_sha,
        "source_workflow_run_id": str(run_id),
        "created_at_utc": probe.get("created_utc"),
        "database_identity": probe.get("database_identity", "***_velora_production"),
        "db_engine": probe.get("engine"),
        "db_engine_version": probe.get("engine_version"),
        "table_count": probe.get("table_count"),
        "view_count": probe.get("view_count"),
        "backup_format": "sql.gz (pure-PHP/PDO logical dump, REPEATABLE READ snapshot)",
        "source_environment": "production",
        "size_bytes": probe.get("dump_size_bytes"),
        "sha256": expected_sha,
        "verification_status": "INTEGRITY_VERIFIED",
        "restore_test_status": "UNVERIFIED",
        "creation_mechanism": "production one-use PHP probe via FTP; uploaded by velora-db-backup.yml",
        "retention_days": pb.DEFAULT_RETENTION_DAYS,
        "storage": {
            "repository": pb.PRIVATE_BACKUP_REPO,
            "repository_visibility": "private",
            "release_tag": tag,
            "release_asset": asset,
            "metadata_path": meta_path,
        },
        "workflow_run_id": str(run_id),
    }
    # Fail closed if any secret marker appears; validate the whole namespace.
    pb.assert_metadata_has_no_secrets(metadata)
    pb.validate_namespace("production", tag, meta_path, asset)

    client = bc.Client()  # token from BACKUP_REPO_TOKEN env; never printed
    info = client.check_repository()  # exists + exact owner/name + private, else raises
    if info["full_name"] != pb.PRIVATE_BACKUP_REPO or info["private"] is not True:
        die("refuse: target repository is not the exact private velora-backups")

    # Idempotency: never overwrite an existing backup.
    existing = {e["tag"] for e in client.list_backups("production")}
    if tag in existing:
        die(f"refuse: release tag already exists (no overwrite): {tag}")

    # 1) commit metadata + checksum FIRST (also anchors main on a fresh private repo).
    checksum_text = f"{expected_sha}  {asset}\n"
    client.commit_metadata("production", metadata, checksum_text=checksum_text)

    # 2) create the release.
    release = client.create_backup_release("production", metadata)

    # 3) upload the .sql.gz as a Release Asset ONLY (never a git blob/LFS/artifact).
    upload = client.upload_release_asset("production", metadata, release, data)

    # 4) BYTE-LEVEL verification: download the asset back and recompute sha256.
    import urllib.request, urllib.error
    rid = release["id"]
    # find asset id
    got = client._api(f"releases/{rid}/assets")
    asset_id = None
    for a in (got.get("json") or []):
        if a.get("name") == os.path.basename(asset):
            asset_id = a["id"]
    if asset_id is None:
        die("uploaded asset not found on release for byte verification")
    req = urllib.request.Request(
        f"{bc.GITHUB_API}/repos/{pb.PRIVATE_BACKUP_REPO}/releases/assets/{asset_id}",
        headers={
            "Authorization": f"Bearer {os.environ['BACKUP_REPO_TOKEN']}",
            "Accept": "application/octet-stream",
            "User-Agent": "velora-backup",
        })
    with urllib.request.urlopen(req, timeout=120) as resp:
        downloaded = resp.read()
    dl_sha = hashlib.sha256(downloaded).hexdigest()
    if dl_sha != expected_sha or len(downloaded) != len(data):
        die(f"uploaded asset byte verification FAILED: sha {dl_sha} size {len(downloaded)}")
    # gzip recheck on the downloaded bytes
    with gzip.GzipFile(fileobj=io.BytesIO(downloaded)) as gz:
        while gz.read(65536):
            pass

    print(json.dumps({
        "ok": True, "backup_id": backup_id, "release_tag": tag,
        "release_asset": upload["asset"], "release_id": rid,
        "sha256": dl_sha, "size_bytes": len(downloaded),
        "verification_status": "INTEGRITY_VERIFIED",
        "byte_verified": True,
    }, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
