"""Secure n8n archive ingest (no public API route, no PAT, no n8n connection)."""
from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from github_writer import GitHubAppWriter, GitHubWriterError
from hmac_guard import IngestAuthError, ReplayStore, verify_hmac
from normalize import NormalizeError, normalize_n8n_payload

MAX_BODY_BYTES = 262144  # 256 KiB


class IngestError(Exception):
    def __init__(self, code: str, message: str, http_status: int):
        super().__init__(message)
        self.code = code
        self.http_status = http_status


def _err(exc: IngestAuthError | NormalizeError | GitHubWriterError | IngestError) -> dict[str, Any]:
    return {
        "ok": False,
        "code": exc.code,
        "error": str(exc),
        "http_status": exc.http_status,
    }


def ingest(
    *,
    raw_body: bytes,
    signature: str | None,
    timestamp: str | None,
    hmac_secret: str,
    snapshots_dir: Path,
    replay_dir: Path,
    github: GitHubAppWriter | None = None,
    now: int | None = None,
) -> dict[str, Any]:
    if not raw_body or len(raw_body) > MAX_BODY_BYTES:
        return _err(IngestError("PAYLOAD_TOO_LARGE", "Payload missing or too large.", 413))
    try:
        sig = verify_hmac(raw_body, hmac_secret, signature, timestamp, now=now)
        ReplayStore(replay_dir).check_and_remember(sig, now=now)
    except IngestAuthError as exc:
        return _err(exc)
    try:
        payload = json.loads(raw_body.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return _err(IngestError("INVALID_JSON", "Malformed JSON.", 400))
    if not isinstance(payload, dict):
        return _err(IngestError("INVALID_JSON", "JSON root must be an object.", 400))
    try:
        snapshot = normalize_n8n_payload(payload)
    except NormalizeError as exc:
        return _err(exc)

    archive_id = snapshot["archive_id"]
    snapshots_dir.mkdir(parents=True, exist_ok=True)
    dest = snapshots_dir / f"{archive_id}.json"
    encoded = json.dumps(snapshot, ensure_ascii=False, indent=2) + "\n"
    if dest.exists():
        existing = json.loads(dest.read_text(encoding="utf-8"))
        if existing.get("content_sha256") == snapshot["content_sha256"]:
            return {
                "ok": True,
                "noop": True,
                "archive_id": archive_id,
                "http_status": 200,
                "reason": "identical_snapshot",
            }
        return _err(
            IngestError(
                "ARCHIVE_ID_CONFLICT",
                "archive_id exists with different content_sha256.",
                409,
            )
        )
    dest.write_text(encoded, encoding="utf-8")

    github_result: dict[str, Any] | None = None
    if github is not None:
        try:
            github_result = github.publish_snapshot(archive_id, encoded, snapshot["content_sha256"])
        except GitHubWriterError as exc:
            dest.unlink(missing_ok=True)
            return _err(exc)

    return {
        "ok": True,
        "noop": False,
        "archive_id": archive_id,
        "content_sha256": snapshot["content_sha256"],
        "http_status": 201,
        "github": github_result,
    }
