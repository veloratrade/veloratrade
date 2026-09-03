#!/usr/bin/env python3
"""
VELORA — PRIVATE database-backup repository integration (architecture + pure logic).

Separation:
  veloratrade/veloratrade       PUBLIC   application source, workflows, non-secret config.
  veloratrade/velora-backups    PRIVATE  DB backups, backup metadata, checksums,
                                        verification records, rollback info.

This module performs NO network I/O and creates NO repository/credential/backup. It models:
  * strict, explicit environment targeting (staging|production) with no inference/fallback;
  * the private-repo LAYOUT and NAMING conventions;
  * storage design (metadata/checksums as small committed text; large .sql.gz dumps as
    GitHub RELEASE ASSETS — never git blobs, never LFS — see cost/limits docs);
  * the required SECRETS/PERMISSIONS (names only) and the GitHub-App vs PAT auth model;
  * cross-environment contamination guards (a record's namespace must match its target);
  * retention planning against release-asset backups (multiple history; never delete the
    newest verified; cleanup ONLY after a successful, post-verified operation);
  * a secret-leak guard for metadata.

State ladder is reused from backup.py: UNVERIFIED < CREATED < INTEGRITY_VERIFIED <
RESTORE_VERIFIED. Nothing here fabricates a verification level.
"""
from __future__ import annotations

import json
import re
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any, Optional

try:
    import backup as bk
except ImportError:  # pragma: no cover
    import os as _os, sys as _sys
    _sys.path.insert(0, _os.path.dirname(_os.path.abspath(__file__)))
    import backup as bk

# --------------------------------------------------------------------------- #
# Constants (no secrets — identifiers/names only)
# --------------------------------------------------------------------------- #

PUBLIC_APP_REPO = "veloratrade/veloratrade"     # public, application
PRIVATE_BACKUP_REPO = "veloratrade/velora-backups"  # private, backups (to be created by owner)
ENVIRONMENTS = ("staging", "production")
DB_BACKUP_TYPE = "database"

# Private repo layout
METADATA_PREFIX = "backups/{env}/"              # small JSON + .sha256 committed in git
RELEASE_TAG_PREFIX = "db-backup-{env}-"         # one release per backup; dump = release asset
ASSET_NAME = "{env}/{backup_id}.sql.gz"

# Retention defaults (mirror the verified 14-day file policy; backups are release assets).
DEFAULT_RETENTION_DAYS = 14
DEFAULT_KEEP_LAST_N = 5

# Required credentials/secrets (NAMES ONLY — values never printed or stored).
# The backup-write token must be scoped to the PRIVATE repo only and exposed to
# push-triggered / environment-protected workflows (NOT to untrusted pull_request runs).
REQUIRED_SECRETS = {
    # Preferred: a GitHub APP (least privilege, no long-lived PAT).
    "preferred_app": [
        "BACKUP_REPO_APP_ID",          # GitHub App ID (numeric)
        "BACKUP_REPO_APP_INSTALLATION_ID",  # installation id on veloratrade org
        "BACKUP_REPO_APP_PRIVATE_KEY",  # PEM private key (secret)
    ],
    # Fallback: a fine-grained PAT scoped to velora-backups ONLY (documented dependency).
    "fallback_pat": [
        "BACKUP_REPO_TOKEN",          # fine-grained PAT: contents:write + metadata on private repo
    ],
}

# Fine-grained token/App permissions the identity must have ON THE PRIVATE REPO ONLY.
REQUIRED_PERMISSIONS = {
    "contents": "write",     # commit metadata/checksums; create releases/assets
    "metadata": "read",
    # NOT requested: actions, administration, secrets, deployments, etc.
}

# Substrings that must NEVER appear in committed metadata.
SECRET_MARKERS = ["password", "passwd", "secret", "token", "api_key", "apikey",
                  "private_key", "begin rsa", "begin openpgp", ".env", "dsn",
                  "mysql://", "ftp://", "xoxb-", "ghp_", "github_pat_"]

_HEX40 = re.compile(r"^[0-9a-f]{40}$")
_SHA256 = re.compile(r"^[0-9a-f]{64}$")
# public aliases for the deploy gate / upload scripts
HEX40 = _HEX40
SHA256_RE = _SHA256


class BackupRepoError(Exception):
    pass


# --------------------------------------------------------------------------- #
# Validation / fail-closed guards
# --------------------------------------------------------------------------- #

def require_environment(env: str) -> str:
    if env not in ENVIRONMENTS:
        raise BackupRepoError(f"target environment must be explicit and one of "
                              f"{ENVIRONMENTS}; got {env!r} (fail closed)")
    return env


def require_operation(op: str) -> str:
    allowed = ("inspect", "plan", "verify", "backup", "migrate", "deploy")
    if op not in allowed:
        raise BackupRepoError(f"operation must be one of {allowed}; got {op!r} (fail closed)")
    return op


def validate_namespace(environment: str, release_tag: str, metadata_path: str,
                        asset_name: str) -> dict:
    """Cross-environment contamination guard: every stored location must embed the SAME
    explicit environment. A production dump must never land in staging namespace and
    vice versa. Raises on mismatch (fail closed)."""
    require_environment(environment)
    checks = {
        "release_tag": release_tag.startswith(f"db-backup-{environment}-"),
        "metadata_path": metadata_path.startswith(f"backups/{environment}/"),
        "asset_name": asset_name.startswith(f"{environment}/"),
    }
    bad = [k for k, ok in checks.items() if not ok]
    if bad:
        raise BackupRepoError(
            f"ENVIRONMENT MISMATCH: record for {environment!r} has wrong namespace in {bad} "
            f"(tag={release_tag}, meta={metadata_path}, asset={asset_name})")
    return {"ok": True, "checks": checks}


def assert_metadata_has_no_secrets(record: dict) -> None:
    """Fail if a metadata blob contains credential-like content. We allow structural KEYS
    that happen to mention a marker only when they are boolean/None policy fields, but any
    VALUE containing a secret pattern is rejected."""
    blob = json.dumps(record, default=str).lower()
    hits = [m for m in SECRET_MARKERS if m in blob]
    if hits:
        raise BackupRepoError(f"backup metadata contains secret-like content: {sorted(set(hits))}")


# --------------------------------------------------------------------------- #
# Naming / deterministic identity
# --------------------------------------------------------------------------- #

def make_release_tag(environment: str, created_utc: str, backup_id: str) -> str:
    require_environment(environment)
    dt = datetime.strptime(created_utc, "%Y-%m-%dT%H:%M:%SZ")
    stamp = dt.strftime("%Y%m%d%H%M%S")
    suffix = backup_id.split("-")[-1] if "-" in backup_id else backup_id[:12]
    return f"{RELEASE_TAG_PREFIX.format(env=environment)}{stamp}-{suffix}"


def make_metadata_path(environment: str, backup_id: str) -> str:
    require_environment(environment)
    return f"backups/{environment}/{backup_id}.json"


def make_asset_name(environment: str, backup_id: str) -> str:
    require_environment(environment)
    return ASSET_NAME.format(env=environment, backup_id=backup_id)


# --------------------------------------------------------------------------- #
# Private backup record (metadata committed to the PRIVATE repo)
# --------------------------------------------------------------------------- #

def build_private_backup_metadata(
    environment: str, *, source_commit_sha: str, workflow_run_id: str,
    created_utc: str, size_bytes: Optional[int], checksum_sha256: str,
    database_identity_redacted: str, schema_migration_version: Optional[str],
    creation_mechanism: str = "github-release-upload",
    verification_status: str = bk.STATE_UNVERIFIED,
    restore_test_status: str = bk.STATE_UNVERIFIED,
    expected_checksum_sha256: Optional[str] = None,
) -> dict:
    """Build the deterministic, secret-free metadata object that is committed to
    backups/<env>/<backup_id>.json in the PRIVATE repo and registered on the release."""
    require_environment(environment)
    if not _HEX40.match(source_commit_sha or ""):
        raise BackupRepoError("source_commit_sha must be a 40-hex commit SHA (explicit binding)")
    if not _SHA256.match(checksum_sha256 or ""):
        raise BackupRepoError("checksum_sha256 must be 64-hex sha256 of the dump")

    backup_id = bk.make_backup_id(environment, DB_BACKUP_TYPE, source_commit_sha,
                                  workflow_run_id, created_utc)
    tag = make_release_tag(environment, created_utc, backup_id)
    meta_path = make_metadata_path(environment, backup_id)
    asset = make_asset_name(environment, backup_id)
    validate_namespace(environment, tag, meta_path, asset)

    dt = datetime.strptime(created_utc, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    expires = datetime.fromtimestamp(dt.timestamp() + DEFAULT_RETENTION_DAYS * 86400,
                                     tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    record = {
        "schema": "velora-db-backup/1",
        "backup_id": backup_id,
        "environment": environment,                 # explicit, never inferred
        "backup_type": DB_BACKUP_TYPE,
        "source_commit_sha": source_commit_sha,     # binds backup to an exact app commit
        "created_at_utc": created_utc,
        "database_identity": database_identity_redacted,  # e.g. "***_velora_staging / MySQL 8.0.46"
        "size_bytes": size_bytes,
        "sha256": checksum_sha256,
        "expected_sha256": expected_checksum_sha256 or checksum_sha256,
        "schema_migration_version": schema_migration_version,
        "verification_status": verification_status,
        "restore_test_status": restore_test_status,
        "creation_mechanism": creation_mechanism,
        "retention_expires_at_utc": expires,
        "retention_days": DEFAULT_RETENTION_DAYS,
        "storage": {
            "repository": PRIVATE_BACKUP_REPO,
            "repository_visibility": "private",
            "release_tag": tag,
            "release_asset": asset,                # the .sql.gz lives HERE (not in git)
            "metadata_path": meta_path,            # small JSON committed in git
        },
        "workflow_run_id": str(workflow_run_id),
        # never include: passwords, tokens, keys, .env, DSN, raw data
    }
    assert_metadata_has_no_secrets(record)
    return record


# --------------------------------------------------------------------------- #
# Retention over PRIVATE-REPO release backups
# --------------------------------------------------------------------------- #

def retention_plan(records: list[dict], *, mutation_succeeded: bool,
                   post_verify_passed: bool, keep_days: int = DEFAULT_RETENTION_DAYS,
                   keep_last_n: int = DEFAULT_KEEP_LAST_N, now_utc: Optional[str] = None) -> dict:
    """Decide which private-repo release backups may be deleted. Cleanup is ONLY permitted
    after a fully successful, post-verified operation. Never delete the newest VERIFIED
    backup; never drop below keep_last_n verified backups; multiple history coexist.
    `records` are metadata dicts (must all share one environment)."""
    if records:
        envs = {r.get("environment") for r in records}
        if len(envs) != 1:
            raise BackupRepoError(f"refusing cross-environment retention set: {envs}")
    if not (mutation_succeeded and post_verify_passed):
        return {"stopped": True, "delete_release_tags": [], "retain": [r["backup_id"] for r in records],
                "reason": "retention suppressed: mutation or post-verification not successful"}
    now = datetime.strptime(now_utc or datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
                            "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    ordered = sorted(records, key=lambda r: r["created_at_utc"], reverse=True)
    verified = [r for r in ordered
                if bk.state_at_least(r.get("verification_status", bk.STATE_UNVERIFIED),
                                     bk.STATE_CREATED)]
    protected = {r["backup_id"] for r in verified[:keep_last_n]}
    # newest integrity-verified anchor always protected
    for r in ordered:
        if bk.state_at_least(r.get("verification_status", ""), bk.STATE_INTEGRITY_VERIFIED):
            protected.add(r["backup_id"]); break
    delete, retain = [], []
    cutoff = now.timestamp() - keep_days * 86400
    for r in ordered:
        expired = False
        exp = r.get("retention_expires_at_utc")
        if exp:
            try:
                expired = datetime.strptime(exp, "%Y-%m-%dT%H:%M:%SZ")\
                    .replace(tzinfo=timezone.utc).timestamp() < cutoff
            except ValueError:
                expired = False
        if r["backup_id"] in protected:
            retain.append(r["backup_id"])
        elif expired:
            delete.append(r["storage"]["release_tag"])
        else:
            retain.append(r["backup_id"])
    return {"stopped": False, "delete_release_tags": delete, "retain": retain}


# --------------------------------------------------------------------------- #
# Approval / gate binding (delegates to backup.py; adds private-backup binding)
# --------------------------------------------------------------------------- #

def private_backup_gate(metadata: Optional[dict], environment: str, operation: str,
                        minimum_state: str) -> dict:
    """A migration/deploy may proceed only when a PRIVATE-REPO backup exists for the SAME
    environment and reached the required verification state. Production requires stronger
    state and (upstream) the protected environment. Fails closed."""
    require_environment(environment)
    require_operation(operation)
    if not metadata:
        return {"allowed": False, "decision": "STOP", "reasons":
                [f"no private-repo backup registered for {environment} {operation}"]}
    if metadata.get("environment") != environment:
        return {"allowed": False, "decision": "STOP", "reasons":
                [f"backup environment {metadata.get('environment')} != target {environment} "
                 f"(cross-environment contamination blocked)"]}
    if metadata.get("storage", {}).get("repository") != PRIVATE_BACKUP_REPO:
        return {"allowed": False, "decision": "STOP", "reasons": ["backup is not in the PRIVATE repo"]}
    state = metadata.get("verification_status", bk.STATE_UNVERIFIED)
    if not bk.state_at_least(state, minimum_state):
        return {"allowed": False, "decision": "STOP", "backup_state": state, "reasons":
                [f"backup state {state} < required {minimum_state}"]}
    return {"allowed": True, "decision": "PROCEED", "backup_state": state,
            "backup_id": metadata.get("backup_id"),
            "release_tag": metadata.get("storage", {}).get("release_tag")}


# --------------------------------------------------------------------------- #
# Dry-run CLI (no network; validates targeting/metadata only)
# --------------------------------------------------------------------------- #

def _main(argv=None):
    import argparse
    ap = argparse.ArgumentParser(prog="private-backup-repo")
    sub = ap.add_subparsers(dest="cmd", required=True)
    dm = sub.add_parser("dry-run-metadata", help="generate secret-free metadata locally (no backup)")
    dm.add_argument("--environment", required=True, choices=ENVIRONMENTS)
    dm.add_argument("--commit", required=True)
    dm.add_argument("--run-id", default="0")
    dm.add_argument("--created", default=datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"))
    args = ap.parse_args(argv)
    if args.cmd == "dry-run-metadata":
        # NOTE: checksum/size here are PLACEHOLDERS — a real record gets them from the
        # actual verified dump. We mark state UNVERIFIED so nothing can be mistaken for a
        # real backup.
        rec = build_private_backup_metadata(
            args.environment, source_commit_sha=args.commit, workflow_run_id=args.run_id,
            created_utc=args.created, size_bytes=None,
            checksum_sha256="0" * 64,
            database_identity_redacted=f"***_velora_{args.environment} / <engine-verified-inspect>",
            schema_migration_version=None,
            verification_status=bk.STATE_UNVERIFIED, restore_test_status=bk.STATE_UNVERIFIED,
            creation_mechanism="dry-run-placeholder")
        print(json.dumps(rec, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    _main()
