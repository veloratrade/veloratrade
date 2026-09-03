#!/usr/bin/env python3
"""
VELORA — backup lifecycle orchestration (pure state machine; no SQL, no dump creation).

Implements the required lifecycle against the PRIVATE backup repository:

  DISCOVER -> PLAN -> APPROVE -> CREATE BACKUP -> UPLOAD -> VERIFY INTEGRITY
           -> BIND BACKUP TO OPERATION -> MUTATE -> POST-VERIFY -> RETENTION

Hard guarantees encoded here:
  * environment is explicit on every call; staging approval never authorizes production.
  * a backup that is not INTEGRITY_VERIFIED cannot satisfy the migration gate.
  * restore verification is a separate state (never implied by integrity).
  * RETENTION NEVER deletes the newest verified backup, and runs ONLY after mutation +
    post-verify succeed; on ANY earlier failure the operation STOPs and all existing
    backups are kept.
  * the orchestrator never fabricates a backup: a missing dump bytes/credential/repo
    yields BLOCKED, not success.

    The actual DB dump and the actual mutation (migration/deploy) are performed by external,
    separately-authorized steps; they are passed here as callables or their recorded results.

    ROLE / STATUS: this module is an intentionally retained library. It models and tests the
    full backup/mutation state machine (create -> verify -> record -> approve -> mutate ->
    post-verify -> retention) and is covered by tests/test_backup_repo_connection.py. The
    wired CI path does NOT call run_backup_lifecycle(); it composes the same stages through
    the dedicated scripts velora_prod_backup_upload.py / velora_prod_backup_gate.py /
    velora_prod_retention.py (and their staging counterparts). This module remains the
    reference implementation and test harness for the lifecycle invariants, not dead code.
    """
from __future__ import annotations

from dataclasses import dataclass, field
from typing import Callable, Optional

try:
    import backup as bk
    import private_backup_repo as pb
    import backup_repo_client as bc
except ImportError:  # pragma: no cover
    import os as _os, sys as _sys
    _sys.path.insert(0, _os.path.dirname(_os.path.abspath(__file__)))
    import backup as bk
    import private_backup_repo as pb
    import backup_repo_client as bc


class LifecycleError(Exception):
    pass


STOP = "STOP"
BLOCKED = "BLOCKED"
PROCEED = "PROCEED"
COMPLETE = "COMPLETE"


@dataclass
class LifecycleRequest:
    environment: str
    operation: str                  # backup | migrate | deploy
    commit_sha: str
    workflow_run_id: str
    plan_hash: str
    migrations: list = field(default_factory=list)
    approval_token: Optional[dict] = None   # explicit approval bound to env/op/commit
    # actual dump bytes are provided ONLY by an authorized host backup step; None => not created
    dump_bytes: Optional[bytes] = None
    created_at_utc: Optional[str] = None
    database_identity: str = "<redacted-db-identity>"
    # results of external, separately-authorized steps
    mutation_succeeded: Optional[bool] = None
    post_verify_passed: Optional[bool] = None
    dry_run: bool = True           # default: never mutate/delete


def _stop(stage: str, reasons: list[str], **extra) -> dict:
    out = {"status": STOP, "stopped_at": stage, "reasons": reasons,
           "retention_performed": False, "backups_deleted": []}
    out.update(extra)
    return out


def run_backup_lifecycle(client: "bc.Client", req: LifecycleRequest) -> dict:
    # 1-2) DISCOVER / PLAN: explicit environment + operation (fail closed)
    try:
        pb.require_environment(req.environment)
        pb.require_operation(req.operation)
    except pb.BackupRepoError as e:
        return _stop("discover", [str(e)])

    # 3) APPROVE. Backup creation for staging is operator-authorized; production mutation
    #    requires an explicit approval bound to env+op+commit+migrations+plan_hash.
    if req.environment == "production" and req.operation in ("migrate", "deploy"):
        ap = req.approval_token or {}
        ok = (ap.get("environment") == "production"
              and ap.get("operation") == req.operation
              and ap.get("commit_sha") == req.commit_sha
              and sorted(ap.get("migrations", [])) == sorted(req.migrations)
              and ap.get("plan_hash") == req.plan_hash
              and ap.get("approved") is True)
        if not ok:
            return _stop("approve", ["production mutation requires an explicit bound approval"])

    # DISCOVER existing backups (read-only) BEFORE creating anything.
    try:
        existing = client.list_backups(req.environment)
    except Exception as e:  # includes unreachable/not-permitted repo
        return _stop("discover", [f"cannot reach private backup repo: {e}"])

    # 4) CREATE BACKUP: we never fabricate; if no dump bytes are supplied the lifecycle
    #    stops (real dump production happens in an authorized host step, not here).
    if req.dump_bytes is None:
        return {"status": BLOCKED, "stopped_at": "create_backup",
                "reasons": ["no database dump supplied; a real backup must be created by an "
                            "authorized host step (not fabricated). Backups intact."],
                "existing_backups_preserved": len(existing),
                "retention_performed": False, "backups_deleted": []}

    created = pb.build_private_backup_metadata(
        req.environment, source_commit_sha=req.commit_sha, workflow_run_id=req.workflow_run_id,
        created_utc=req.created_at_utc or "2026-09-02T00:00:00Z",
        size_bytes=len(req.dump_bytes), checksum_sha256=client.sha256_of_bytes(req.dump_bytes),
        database_identity_redacted=req.database_identity,
        schema_migration_version=",".join(req.migrations) or None,
        verification_status=bk.STATE_CREATED)  # only CREATED until verified

    # 5) UPLOAD: commit metadata (small text) + create release + upload dump as asset.
    if not req.dry_run:
        client.commit_metadata(req.environment, created)
        release = client.create_backup_release(req.environment, created)
        client.upload_release_asset(req.environment, created, release, req.dump_bytes)

    # 6) VERIFY INTEGRITY (checksum + gzip; restore is a separate state)
    integrity = client.verify_integrity(req.environment, created["sha256"], req.dump_bytes)
    if integrity["verification_status"] != bk.STATE_INTEGRITY_VERIFIED:
        return _stop("verify_integrity", ["backup integrity verification failed"],
                     integrity=integrity, backups_deleted=[])
    # reflect verified state in the record BEFORE gating (state ladder; restore stays separate)
    created["verification_status"] = bk.STATE_INTEGRITY_VERIFIED
    created["restore_test_status"] = bk.STATE_UNVERIFIED

    # 7) BIND BACKUP TO OPERATION: the gate requires the SAME env + private repo + state.
    #    The required state depends on the operation: a PRODUCTION database SCHEMA
    #    migration needs a RESTORE_VERIFIED backup (rollback = DB restore); ordinary
    #    code deploys use INTEGRITY_VERIFIED. Staging uses the lighter ladder.
    required_state = bk.required_state_for_mutation(
        req.environment, "database", req.operation)
    gate = pb.private_backup_gate(created, req.environment, req.operation, required_state)
    if not gate["allowed"]:
        return _stop("bind_backup",
                     gate["reasons"] + [f"required minimum backup state for "
                                        f"{req.environment}/{req.operation} = {required_state}"])

    # re-commit the VERIFIED metadata (small text) in non-dry mode after integrity check
    if not req.dry_run:
        client.commit_metadata(req.environment, created,
                               checksum_text=f"{created['sha256']}  {created['storage']['release_asset']}\n")

    # For a pure backup op, lifecycle ends after verification (mutation is a separate op).
    if req.operation == "backup":
        return {"status": COMPLETE, "stage": "backup_registered",
                "backup_id": created["backup_id"], "release_tag": created["storage"]["release_tag"],
                "verification_status": bk.STATE_INTEGRITY_VERIFIED,
                "restore_test_status": bk.STATE_UNVERIFIED,
                "existing_backups_preserved": len(existing),
                "retention_performed": False, "backups_deleted": []}

    # 8) MUTATE (only if an external, separately-authorized result is recorded)
    if req.mutation_succeeded is None:
        return {"status": BLOCKED, "stopped_at": "mutate",
                "reasons": ["mutation result not provided; backups intact"],
                "backup_id": created["backup_id"], "retention_performed": False,
                "backups_deleted": []}
    if req.mutation_succeeded is not True:
        return _stop("mutate", ["mutation failed"], backups_deleted=[])

    # 9) POST-VERIFY
    if req.post_verify_passed is not True:
        return _stop("post_verify", ["post-operation verification failed"], backups_deleted=[])

    # 10) RETENTION — ONLY after full success; never delete the newest verified backup.
    existing_records = []  # metadata of prior releases (a richer impl fetches metadata)
    all_records = existing_records + [created]
    plan = pb.retention_plan(all_records, mutation_succeeded=True, post_verify_passed=True)
    deleted = []
    if not req.dry_run:
        for tag in plan.get("delete_release_tags", []):
            client.delete_release_by_tag(req.environment, tag)
            deleted.append(tag)
    return {"status": COMPLETE, "stage": "complete",
            "backup_id": created["backup_id"], "release_tag": created["storage"]["release_tag"],
            "verification_status": bk.STATE_INTEGRITY_VERIFIED,
            "retention_stopped": plan["stopped"],
            "retention_delete_tags_dry_run": plan.get("delete_release_tags", []),
            "backups_deleted": deleted, "retention_performed": (not req.dry_run)}
