#!/usr/bin/env python3
"""
VELORA — store a freshly-created, already-verified STAGING DB backup in the PRIVATE
backup repository as a GitHub Release Asset, commit secret-free metadata, and
byte-verify the uploaded asset.

STAGING-ONLY. This is the staging counterpart of velora_prod_backup_upload.py. It is
structurally incapable of writing the production namespace: it hard-requires probe
env == "staging" and a "db-backup-staging-" id, stores under backups/staging/, and
uploads a Release Asset named "staging/<id>.sql.gz". Production paths never appear.

Runs INSIDE the GitHub Actions staging backup job (velora-db-backup-staging.yml),
where BACKUP_REPO_TOKEN is injected as a secret. It NEVER prints the token, NEVER
writes the dump to Git, and fails closed (exit 1) on any problem. There is NO fallback
to GITHUB_TOKEN (which cannot reach the private repo).

Usage:
  velora_staging_backup_upload.py <probe-result.json> <source_commit_sha> <run_id> <dump.sql.gz>
"""
from __future__ import annotations

import gzip
import hashlib
import io
import json
import os
import sys
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import private_backup_repo as pb   # noqa: E402
import backup_repo_client as bc    # noqa: E402

ENV = "staging"
ID_PREFIX = f"db-backup-{ENV}-"
DB_IDENTITY_DEFAULT = "***_velora_staging"
CREATION_MECHANISM = "staging one-use PHP probe via FTP; uploaded by velora-db-backup-staging.yml"


def die(msg: str) -> None:
    print(f"::error::{msg}", flush=True)
    sys.exit(1)


def main(argv: list[str]) -> int:
    if len(argv) != 5:
        die("usage: velora_staging_backup_upload.py <probe.json> <commit_sha> <run_id> <dump.sql.gz>")
    probe_path, commit_sha, run_id, dump_path = argv[1], argv[2], argv[3], argv[4]

    # Hard credential requirement — fail closed; never fall back to GITHUB_TOKEN.
    token = os.environ.get("BACKUP_REPO_TOKEN", "").strip()
    if not token:
        die("BACKUP_REPO_TOKEN is required but not available to the staging backup job.")

    probe = json.load(open(probe_path))
    if probe.get("env") != ENV:
        die(f"refuse: probe env is {probe.get('env')!r}, expected {ENV!r} (staging-only)")
    if probe.get("ok") is not True or probe.get("verification_status") != "INTEGRITY_VERIFIED":
        die("refuse: probe did not report an INTEGRITY_VERIFIED staging backup")
    if not pb.HEX40.match(commit_sha or ""):
        die("refuse: source commit sha must be a 40-hex SHA")

    backup_id = probe["backup_id"]
    if not backup_id.startswith(ID_PREFIX):
        die(f"refuse: backup_id is not in the staging namespace: {backup_id}")
    tag = probe["release_tag"]
    asset = probe["release_asset"]
    if tag != backup_id or not asset.startswith(f"{ENV}/") or not asset.endswith(".sql.gz"):
        die("refuse: release tag/asset namespace mismatch (not staging)")
    meta_path = f"backups/{ENV}/{backup_id}.json"
    expected_sha = probe["sha256"]
    if not pb.SHA256_RE.match(expected_sha or ""):
        die("refuse: probe sha256 is not a 64-hex digest")

    data = open(dump_path, "rb").read()
    if not data:
        die("refuse: dump file is empty")
    local_sha = hashlib.sha256(data).hexdigest()
    if local_sha != expected_sha:
        die(f"local dump sha256 {local_sha} != probe sha256 {expected_sha}")

    metadata = {
        "schema": "velora-db-backup/1",
        "backup_id": backup_id,
        "environment": ENV,
        "backup_type": "database",
        "source_commit_sha": commit_sha,
        "source_workflow_run_id": str(run_id),
        "created_at_utc": probe.get("created_utc"),
        "database_identity": probe.get("database_identity", DB_IDENTITY_DEFAULT),
        "db_engine": probe.get("engine"),
        "db_engine_version": probe.get("engine_version"),
        "table_count": probe.get("table_count"),
        "view_count": probe.get("view_count"),
        "backup_format": "sql.gz (pure-PHP/PDO logical dump, REPEATABLE READ snapshot)",
        "source_environment": ENV,
        "size_bytes": probe.get("dump_size_bytes"),
        "sha256": expected_sha,
        "verification_status": "INTEGRITY_VERIFIED",
        "restore_test_status": "UNVERIFIED",
        "creation_mechanism": CREATION_MECHANISM,
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
    # Fail closed on any secret marker; enforce a consistent staging namespace.
    pb.assert_metadata_has_no_secrets(metadata)
    pb.validate_namespace(ENV, tag, meta_path, asset)

    client = bc.Client()  # token from BACKUP_REPO_TOKEN env; never printed
    info = client.check_repository()  # exists + exact owner/name + private, else raises
    if info["full_name"] != pb.PRIVATE_BACKUP_REPO or info["private"] is not True:
        die("refuse: target repository is not the exact private velora-backups")

    # Idempotency: never overwrite an existing backup.
    existing = {e["tag"] for e in client.list_backups(ENV)}
    if tag in existing:
        die(f"refuse: release tag already exists (no overwrite): {tag}")

    # 1) commit metadata + checksum FIRST (small secret-free text), then
    client.commit_metadata(ENV, metadata,
                           checksum_text=f"{expected_sha}  {asset}\n")
    # 2) create the release (staging => prerelease in the client), then
    release = client.create_backup_release(ENV, metadata)
    # 3) upload the .sql.gz as a Release Asset ONLY (never a git blob/LFS/artifact).
    upload = client.upload_release_asset(ENV, metadata, release, data)

    # 4) BYTE-LEVEL verification: download the asset back and recompute sha256+size.
    rid = release["id"]
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
            "Authorization": f"Bearer {token}",
            "Accept": "application/octet-stream",
            "User-Agent": "velora-backup",
        })
    with urllib.request.urlopen(req, timeout=120) as resp:
        downloaded = resp.read()
    dl_sha = hashlib.sha256(downloaded).hexdigest()
    if dl_sha != expected_sha or len(downloaded) != len(data):
        die(f"uploaded asset byte verification FAILED: sha {dl_sha} size {len(downloaded)}")
    with gzip.GzipFile(fileobj=io.BytesIO(downloaded)) as gz:
        while gz.read(65536):
            pass

    print(json.dumps({
        "ok": True, "environment": ENV, "backup_id": backup_id, "release_tag": tag,
        "release_asset": upload["asset"], "release_id": rid,
        "sha256": dl_sha, "size_bytes": len(downloaded),
        "verification_status": "INTEGRITY_VERIFIED", "byte_verified": True,
    }, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
