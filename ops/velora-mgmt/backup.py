#!/usr/bin/env python3
"""
VELORA Environment Management System — backup discovery, verification, retention,
and rollback STATE machine (pure logic; no I/O, no network, no real backup creation).

This module does NOT create backups. It models:
  * backup identity / metadata (deterministic, auditable, secret-free),
  * backup verification STATES (CREATED < INTEGRITY_VERIFIED < RESTORE_VERIFIED,
    with UNVERIFIED as the starting/failed state),
  * read-only discovery of existing backups (e.g. production file-backup artifacts),
  * the strict lifecycle: create -> verify -> record -> approve -> mutate ->
    post-verify -> retention cleanup, where cleanup NEVER runs on any failure and
    NEVER removes the newest verified backup,
  * approval binding to a specific backup identity (change => invalid),
  * retention that keeps MULTIPLE historical backups (never "one and replace").

Hard rules encoded here:
  * a mutation is BLOCKED unless a backup appropriate to the environment/type exists
    and is verified to at least INTEGRITY_VERIFIED (production) — UNVERIFIED is a hard
    stop, never a fabricated success.
  * no file backup is ever treated as a database backup and vice-versa.
  * failed create / failed verify / failed mutation / failed post-verify => STOP and
    do NOT delete any historical backup.
"""
from __future__ import annotations

import hashlib
import json
import re
from dataclasses import dataclass, field, asdict
from datetime import datetime, timezone
from typing import Any, Optional


# --------------------------------------------------------------------------- #
# States & types
# --------------------------------------------------------------------------- #

# Verification ladder (strictly increasing). UNVERIFIED is the bottom/absent state.
STATE_UNVERIFIED = "UNVERIFIED"
STATE_CREATED = "CREATED"                 # artifact/dump exists, non-zero
STATE_INTEGRITY_VERIFIED = "INTEGRITY_VERIFIED"   # checksum + identity + integrity checked
STATE_RESTORE_VERIFIED = "RESTORE_VERIFIED"       # an actual restore test passed
STATE_ORDER = [STATE_UNVERIFIED, STATE_CREATED, STATE_INTEGRITY_VERIFIED, STATE_RESTORE_VERIFIED]

STATE_RANK = {s: i for i, s in enumerate(STATE_ORDER)}

BACKUP_TYPES = ("file", "database")
ENVIRONMENTS = ("staging", "production")

# Default retention. Production file-backup artifacts are verified in-repo to expire
# 14 days after creation (deploy.yml upload-artifact retention-days: 14) with multiple
# historical backups coexisting. These defaults mirror that proven behavior.
DEFAULT_RETENTION_DAYS = 14
DEFAULT_KEEP_LAST_N = 5          # never prune below this many verified backups
# Production db changes require the stronger ladder; file changes require integrity.
MIN_STATE_FOR_MUTATION = {
    "production": {"file": STATE_INTEGRITY_VERIFIED, "database": STATE_INTEGRITY_VERIFIED},
    # Staging is lighter, but a DB migration still needs rollback protection, i.e. at
    # least an integrity-verified database backup; we never fabricate one.
    "staging":    {"file": STATE_CREATED,           "database": STATE_INTEGRITY_VERIFIED},
}


class BackupError(Exception):
    pass


def _now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def state_at_least(actual: str, required: str) -> bool:
    return STATE_RANK.get(actual, 0) >= STATE_RANK.get(required, 99)


# --------------------------------------------------------------------------- #
# Backup identity & record
# --------------------------------------------------------------------------- #

@dataclass
class BackupRecord:
    environment: str
    backup_type: str            # 'file' | 'database'
    backup_id: str
    source_commit_sha: str
    created_at: str
    location: str                       # human/artifact reference; never a secret
    size_bytes: Optional[int] = None
    checksum_sha256: Optional[str] = None
    expected_checksum_sha256: Optional[str] = None
    schema_version: Optional[str] = None          # DB only (e.g. v1.0/v1.1 applied set)
    database_identity: Optional[str] = None       # redacted db name/engine summary
    verification_status: str = STATE_UNVERIFIED
    restore_test_status: str = STATE_UNVERIFIED
    retention_expires_at: Optional[str] = None
    operation_id: Optional[str] = None
    workflow_run_id: Optional[str] = None
    note: Optional[str] = None

    def to_dict(self) -> dict:
        return asdict(self)


def make_backup_id(environment: str, backup_type: str, commit_sha: str,
                   workflow_run_id: str, created_at: str) -> str:
    raw = f"{environment}|{backup_type}|{commit_sha}|{workflow_run_id}|{created_at}"
    return f"bk-{environment[:4]}-{backup_type[0]}-{hashlib.sha256(raw.encode()).hexdigest()[:12]}"


# --------------------------------------------------------------------------- #
# Verification (pure: evaluate evidence; never fabricate)
# --------------------------------------------------------------------------- #

def evaluate_backup(rec: BackupRecord, observed: dict) -> BackupRecord:
    """Update a record's verification state from OBSERVED evidence only.

    observed keys (all optional, all derived from real checks — never assumed):
      exists: bool, non_zero_size: bool, checksum_matches: bool|None,
      integrity_check_ok: bool|None, identity_ok: bool|None,
      restore_test_ok: bool|None
    A missing/False evidence keeps the state LOWER; we never raise it on assumption.
    """
    exists = bool(observed.get("exists"))
    non_zero = bool(observed.get("non_zero_size")) and (rec.size_bytes or 0) > 0
    checksum_matches = observed.get("checksum_matches")
    integrity_ok = observed.get("integrity_check_ok")
    identity_ok = observed.get("identity_ok", True)
    restore_ok = observed.get("restore_test_ok")

    if not exists or not non_zero or not identity_ok:
        rec.verification_status = STATE_UNVERIFIED
        rec.restore_test_status = STATE_UNVERIFIED
        return rec

    state = STATE_CREATED
    # INTEGRITY_VERIFIED requires a positive integrity verdict. A checksum is required
    # when an expected checksum exists; if none exists, an explicit integrity_check_ok
    # (e.g. tar tz / dump parse / table-presence) must be True.
    integrity = False
    if rec.expected_checksum_sha256:
        integrity = (checksum_matches is True) and (integrity_ok in (True, None))
    else:
        integrity = integrity_ok is True
    if integrity:
        state = STATE_INTEGRITY_VERIFIED

    if state == STATE_INTEGRITY_VERIFIED and restore_ok is True:
        state = STATE_RESTORE_VERIFIED
        rec.restore_test_status = STATE_RESTORE_VERIFIED
    else:
        rec.restore_test_status = STATE_UNVERIFIED if restore_ok is not True else STATE_RESTORE_VERIFIED

    rec.verification_status = state
    return rec


# --------------------------------------------------------------------------- #
# Manifest / discovery
# --------------------------------------------------------------------------- #

@dataclass
class BackupManifest:
    environment: str
    backup_type: str
    records: list[BackupRecord] = field(default_factory=list)

    def sorted_newest_first(self) -> list[BackupRecord]:
        return sorted(self.records, key=lambda r: r.created_at, reverse=True)

    def newest_verified(self, minimum: str = STATE_CREATED) -> Optional[BackupRecord]:
        for r in self.sorted_newest_first():
            if state_at_least(r.verification_status, minimum):
                return r
        return None


# Name pattern of the IMPLEMENTED production file backups:
#   GitHub Actions artifact  pre-deploy-backup-<full 40-hex sha>
_FILE_BK_RE = re.compile(r"^pre-deploy-backup-([0-9a-f]{40})$")


def manifest_from_github_artifacts(environment: str, artifacts: list[dict]) -> BackupManifest:
    """Build a FILE-backup manifest from a read-only GitHub Actions artifact listing.

    Each artifact dict: {name, size_in_bytes, created_at, expires_at, expired,
    workflow_run?:{id}}. We honestly classify the existing deploy backups: the
    deploy workflow verified ONLY a non-zero docroot file count at creation (no
    checksum / no restore test), so an existing artifact is CREATED (present & was
    non-empty at creation) but NOT INTEGRITY_VERIFIED in the cryptographic sense and
    NOT RESTORE_VERIFIED.
    """
    if environment != "production":
        # Staging has no artifact backup step in deploy-staging.yml → empty manifest.
        return BackupManifest(environment=environment, backup_type="file", records=[])
    recs: list[BackupRecord] = []
    for a in artifacts:
        m = _FILE_BK_RE.match(a.get("name", ""))
        if not m:
            continue
        sha = m.group(1)
        created = a.get("created_at", "")
        run = (a.get("workflow_run") or {}).get("id")
        rec = BackupRecord(
            environment="production",
            backup_type="file",
            backup_id=f"bk-prod-f-{sha[:12]}",
            source_commit_sha=sha,
            created_at=created,
            location=f"github-artifact:{a['name']}",
            size_bytes=a.get("size_in_bytes"),
            verification_status=STATE_CREATED,          # non-empty guard ran at creation
            restore_test_status=STATE_UNVERIFIED,      # restore never tested
            retention_expires_at=a.get("expires_at"),
            workflow_run_id=str(run) if run else None,
            note=("Production docroot file backup; non-empty verified at creation; "
                  "no checksum/restore verification; files only (excludes staging "
                  "subtree and the database)."),
        )
        recs.append(rec)
    return BackupManifest(environment="production", backup_type="file", records=recs)


def database_backup_manifest(environment: str, records: Optional[list[BackupRecord]] = None) -> BackupManifest:
    """Discoverable DB backups. There is NO implemented DB dump mechanism; until a real
    verified dump is registered, the manifest is empty => callers must BLOCK."""
    return BackupManifest(environment=environment, backup_type="database",
                          records=list(records or []))


# --------------------------------------------------------------------------- #
# Mutation gate (the decision that blocks/allows)
# --------------------------------------------------------------------------- #

@dataclass
class GateDecision:
    allowed: bool
    decision: str
    backup_state: str
    backup_id: Optional[str]
    reasons: list[str]


def mutation_backup_gate(manifest: BackupManifest, environment: str,
                         backup_type: str) -> GateDecision:
    if environment not in ENVIRONMENTS:
        return GateDecision(False, "STOP", STATE_UNVERIFIED, None, [f"bad environment {environment}"])
    if backup_type not in BACKUP_TYPES:
        return GateDecision(False, "STOP", STATE_UNVERIFIED, None, [f"bad backup type {backup_type}"])
    if manifest.environment != environment or manifest.backup_type != backup_type:
        return GateDecision(False, "STOP", STATE_UNVERIFIED, None,
                            ["manifest does not match requested environment/type"])
    required = MIN_STATE_FOR_MUTATION[environment][backup_type]
    newest = manifest.newest_verified(minimum=STATE_CREATED)
    if newest is None:
        if backup_type == "database":
            reason = "NO VERIFIED DATABASE BACKUP MECHANISM / no database backup registered"
        else:
            reason = f"no {backup_type} backup present"
        return GateDecision(False, "STOP", STATE_UNVERIFIED, None,
                            [reason, f"minimum required state = {required}"])
    if not state_at_least(newest.verification_status, required):
        return GateDecision(False, "STOP", newest.verification_status, newest.backup_id,
                            [f"backup {newest.backup_id} state {newest.verification_status} "
                             f"< required {required}"])
    return GateDecision(True, "PROCEED", newest.verification_status, newest.backup_id, [])


# --------------------------------------------------------------------------- #
# Approval binding to backup identity
# --------------------------------------------------------------------------- #

def approval_covers_backup(approval_backup_id: Optional[str],
                           gate_backup_id: Optional[str]) -> bool:
    """Approval must be bound to the exact backup that gates the mutation. A different
    (or missing) backup identity invalidates the approval."""
    if not approval_backup_id or not gate_backup_id:
        return False
    return approval_backup_id == gate_backup_id


# --------------------------------------------------------------------------- #
# Retention (cleanup ONLY after success; multiple history; never newest verified)
# --------------------------------------------------------------------------- #

def plan_retention_cleanup(manifest: BackupManifest, *, keep_days: int = DEFAULT_RETENTION_DAYS,
                           keep_last_n: int = DEFAULT_KEEP_LAST_N,
                           mutation_succeeded: bool, post_verify_passed: bool) -> dict:
    """Return {cleanup:[ids...], retained:[ids...], policy:...}.

    Cleanup is ONLY permitted when mutation_succeeded AND post_verify_passed. On any
    failure → cleanup=[] (STOP, preserve everything). Even on success we NEVER delete
    the newest verified backup and never drop below keep_last_n verified backups.
    Multiple historical backups are otherwise retained until they age out.
    """
    newest_first = manifest.sorted_newest_first()
    newest_verified = manifest.newest_verified(minimum=STATE_CREATED)
    policy = {"keep_days": keep_days, "keep_last_n": keep_last_n}

    if not (mutation_succeeded and post_verify_passed):
        return {"cleanup": [], "retained": [r.backup_id for r in newest_first],
                "policy": policy,
                "stopped": True,
                "reason": "retention cleanup suppressed: mutation or post-verification not successful"}

    cutoff = datetime.now(timezone.utc).timestamp() - keep_days * 86400
    # The backup that gates mutation must survive. Anchor protection on the newest
    # INTEGRITY_VERIFIED record (stronger), falling back to the newest present record.
    newest_integrity = manifest.newest_verified(minimum=STATE_INTEGRITY_VERIFIED)
    newest_present = manifest.newest_verified(minimum=STATE_CREATED)
    verified = [r for r in newest_first
                if state_at_least(r.verification_status, STATE_CREATED)]
    protected_ids = {r.backup_id for r in verified[:keep_last_n]}
    if newest_integrity:
        protected_ids.add(newest_integrity.backup_id)
    if newest_present:
        protected_ids.add(newest_present.backup_id)

    cleanup, retained = [], []
    for r in newest_first:
        expired = False
        if r.retention_expires_at:
            try:
                expired = datetime.strptime(r.retention_expires_at, "%Y-%m-%dT%H:%M:%SZ")\
                    .replace(tzinfo=timezone.utc).timestamp() < cutoff
            except ValueError:
                expired = False
        if r.backup_id in protected_ids:
            retained.append(r.backup_id)
        elif expired:
            cleanup.append(r.backup_id)
        else:
            retained.append(r.backup_id)
    return {"cleanup": cleanup, "retained": retained, "policy": policy, "stopped": False}


# --------------------------------------------------------------------------- #
# Lifecycle orchestration (drives the required state machine; stops on failure)
# --------------------------------------------------------------------------- #

def lifecycle_status(step: str, gate: GateDecision, *, approval_valid: bool,
                     backup_id_match: bool, mutation_succeeded: bool,
                     post_verify_passed: bool) -> dict:
    """Evaluate the full INSPECT->PLAN->BACKUP->VERIFY->APPROVAL->EXECUTE->POSTVERIFY
    ->RETENTION chain and report STOP with the first failing reason. Pure decision."""
    chain = []
    def add(name, ok, detail=""):
        chain.append({"step": name, "ok": bool(ok), "detail": detail})
        return ok

    ok = True
    ok = add("backup_gate", gate.allowed, f"{gate.decision} {gate.backup_state} {gate.backup_id or ''}") and ok
    if not gate.allowed:
        return {"status": "STOP", "failed_at": "backup_gate", "chain": chain,
                "reasons": gate.reasons, "retention": "not reached (no cleanup)"}
    ok = add("approval", approval_valid, "approval bound to env/op/commit/migrations/plan_hash") and ok
    ok = add("approval_backup_binding", backup_id_match, "approval bound to the gating backup id") and ok
    if not (approval_valid and backup_id_match):
        return {"status": "STOP", "failed_at": "approval", "chain": chain,
                "reasons": ["missing/mismatched approval or backup binding"],
                "retention": "not reached (no cleanup)"}
    ok = add("execute", mutation_succeeded) and ok
    ok = add("post_verify", post_verify_passed) and ok
    retention = plan_retention_cleanup(
        # manifest supplied by caller in a richer wrapper; here just decision
        BackupManifest("staging", "file"),
        mutation_succeeded=mutation_succeeded, post_verify_passed=post_verify_passed)
    add("retention_cleanup", retention["stopped"] is False or mutation_succeeded and post_verify_passed,
        "cleanup only after success")
    status = "COMPLETE" if (mutation_succeeded and post_verify_passed) else "STOP"
    return {"status": status,
            "failed_at": None if status == "COMPLETE" else ("post_verify" if not post_verify_passed else "execute"),
            "chain": chain, "retention": retention}
