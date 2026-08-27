"""HMAC + timestamp + replay protection for n8n archive ingest."""
from __future__ import annotations

import hashlib
import hmac
import time
from pathlib import Path


class IngestAuthError(Exception):
    def __init__(self, code: str, message: str, http_status: int = 401):
        super().__init__(message)
        self.code = code
        self.http_status = http_status


def parse_unix(value: str) -> int:
    try:
        ts = int(str(value).strip())
    except (TypeError, ValueError) as exc:
        raise IngestAuthError("TIMESTAMP_INVALID", "Invalid timestamp.") from exc
    return ts


def verify_hmac(
    raw_body: bytes,
    secret: str,
    signature_hex: str | None,
    timestamp: str | None,
    *,
    now: int | None = None,
    max_skew_sec: int = 300,
) -> str:
    if not secret:
        raise IngestAuthError("HMAC_SECRET_MISSING", "HMAC secret is not configured.", 503)
    if not signature_hex or not timestamp:
        raise IngestAuthError("HMAC_MISSING", "Signature or timestamp missing.")
    ts = parse_unix(timestamp)
    now_ts = int(now if now is not None else time.time())
    if abs(now_ts - ts) > max_skew_sec:
        raise IngestAuthError("TIMESTAMP_EXPIRED", "Request timestamp expired.")
    message = f"{ts}.".encode("ascii") + raw_body
    expected = hmac.new(secret.encode("utf-8"), message, hashlib.sha256).hexdigest()
    provided = signature_hex.strip().lower().removeprefix("sha256=")
    if len(provided) != 64 or any(c not in "0123456789abcdef" for c in provided):
        raise IngestAuthError("HMAC_FAILED", "HMAC verification failed.")
    if not hmac.compare_digest(expected, provided):
        raise IngestAuthError("HMAC_FAILED", "HMAC verification failed.")
    return expected


class ReplayStore:
    def __init__(self, directory: Path, ttl_sec: int = 600):
        self.directory = directory
        self.ttl_sec = ttl_sec
        self.directory.mkdir(parents=True, exist_ok=True)

    def _path(self, digest: str) -> Path:
        return self.directory / f"{digest}.replay"

    def check_and_remember(self, signature_hex: str, *, now: int | None = None) -> None:
        now_ts = int(now if now is not None else time.time())
        digest = hashlib.sha256(signature_hex.encode("ascii")).hexdigest()
        path = self._path(digest)
        if path.exists():
            raise IngestAuthError("REPLAY", "Replayed request rejected.")
        path.write_text(str(now_ts), encoding="utf-8")
        self._gc(now_ts)

    def _gc(self, now_ts: int) -> None:
        for item in self.directory.glob("*.replay"):
            try:
                created = int(item.read_text(encoding="utf-8").strip() or "0")
            except ValueError:
                item.unlink(missing_ok=True)
                continue
            if now_ts - created > self.ttl_sec:
                item.unlink(missing_ok=True)
