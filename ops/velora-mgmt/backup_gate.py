#!/usr/bin/env python3
"""VELORA BACKUP GATE — machine-enforced backup-evidence validator.

PERMANENT REPOSITORY LAW (2026-09-09):

    NO DEPLOY OR DATABASE MIGRATION MAY PROCEED WITHOUT A SUCCESSFULLY
    CREATED AND VERIFIED BACKUP.

This module is the single canonical implementation of that rule. Workflows
(deploy-staging, *-migration-staging, velora-db-backup-staging) call its CLI;
tests call :func:`evaluate_backup_gate` directly.

A backup is ACCEPTED only when ALL of the following machine-verifiable
evidence items are present and valid (fail-closed on any gap):

    backup_id            non-empty, prefixed ``db-backup-<environment>-``
    release_tag          non-empty (official storage identifier in
                         veloratrade/velora-backups release assets)
    sha256               lowercase 64-hex digest of the verified dump
    source_commit_sha    valid git SHA (7..64 hex) the backup was taken for
    verification_status  exactly ``INTEGRITY_VERIFIED``
    environment          exactly the expected target environment

There is NO override, NO skip flag, and NO fallback. A verbal or logical
statement that a backup "should exist" is NOT evidence; only workflow outputs
validated by this gate are.

Pure logic + a thin CLI. No network I/O. Never prints secret values (it only
sees evidence identifiers, which are non-secret by design).
"""
from __future__ import annotations

import os
import re
import sys

INTEGRITY_VERIFIED = "INTEGRITY_VERIFIED"

_SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
_GIT_SHA_RE = re.compile(r"^[0-9a-f]{7,64}$")

_EVIDENCE_KEYS = (
    "BACKUP_ID",
    "RELEASE_TAG",
    "SHA256",
    "SOURCE_COMMIT_SHA",
    "VERIFICATION_STATUS",
    "ENVIRONMENT",
)


def evaluate_backup_gate(backup: dict, expect_env: str) -> tuple[bool, list[str]]:
    """Return (allowed, reasons). ``backup`` maps evidence names (lowercase or
    the CLI's uppercase names) to strings; ``expect_env`` is 'staging' or
    'production'. Any missing/invalid item blocks the gate."""
    if expect_env not in ("staging", "production"):
        return False, [f"invalid expected environment: {expect_env!r}"]

    normalized = {str(k).lower(): v for k, v in (backup or {}).items()}
    reasons: list[str] = []

    def evidence(key: str) -> str | None:
        raw = normalized.get(key)
        if not isinstance(raw, str) or not raw.strip():
            reasons.append(f"missing evidence: {key}")
            return None
        return raw.strip()

    backup_id = evidence("backup_id")
    release_tag = evidence("release_tag")
    sha256 = evidence("sha256")
    source_commit_sha = evidence("source_commit_sha")
    status = evidence("verification_status")
    environment = evidence("environment")

    if backup_id and not backup_id.startswith(f"db-backup-{expect_env}-"):
        reasons.append(
            f"backup_id not in the {expect_env} namespace (expected prefix "
            f"'db-backup-{expect_env}-'): {backup_id}"
        )
    if sha256 and not _SHA256_RE.match(sha256):
        reasons.append("sha256 is not a lowercase 64-hex digest")
    if source_commit_sha and not _GIT_SHA_RE.match(source_commit_sha):
        reasons.append("source_commit_sha is not a valid git SHA (7..64 hex)")
    if status is not None and status != INTEGRITY_VERIFIED:
        reasons.append(
            f"verification_status must be exactly {INTEGRITY_VERIFIED!r}, got {status!r}"
        )
    if environment is not None and environment != expect_env:
        reasons.append(f"environment mismatch: expected {expect_env!r}, got {environment!r}")

    return (not reasons), reasons


def main(argv: list[str] | None = None) -> int:
    expect_env = (os.environ.get("EXPECTED_ENV") or "").strip()
    if not expect_env:
        print("::error::BACKUP GATE: EXPECTED_ENV is not set (staging|production)")
        return 2
    payload = {key: os.environ.get(key, "") for key in _EVIDENCE_KEYS}
    ok, reasons = evaluate_backup_gate(payload, expect_env)
    if ok:
        print(
            "BACKUP GATE PASS: verified backup evidence complete "
            f"(backup_id={payload['BACKUP_ID']} env={expect_env})"
        )
        return 0
    for reason in reasons:
        print(f"::error::BACKUP GATE: {reason}")
    print("BACKUP GATE FAIL: no verified backup — deploy/migration must NOT proceed")
    return 1


if __name__ == "__main__":
    sys.exit(main())
