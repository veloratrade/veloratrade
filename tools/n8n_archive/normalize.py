"""Map a raw n8n archive row into the Phase 1 canonical snapshot."""
from __future__ import annotations

import hashlib
import json
from typing import Any

from validate_snapshot import FORBIDDEN_KEYS, USER_ID_FIELD_RE, validate

STATUS_MAP = {
    "APPROVED": "approved",
    "approved": "approved",
    "ARCHIVED": "archived",
    "archived": "archived",
    "NOT_PUBLISHED": "not_published",
    "not_published": "not_published",
    "PUBLISHED": "published",
    "published": "published",
    "FAILED": "failed",
    "failed": "failed",
}


class NormalizeError(Exception):
    def __init__(self, code: str, message: str, http_status: int = 422):
        super().__init__(message)
        self.code = code
        self.http_status = http_status


def _walk_keys(obj: Any, acc: list[str]) -> None:
    if isinstance(obj, dict):
        for k, v in obj.items():
            acc.append(str(k))
            _walk_keys(v, acc)
    elif isinstance(obj, list):
        for item in obj:
            _walk_keys(item, acc)


def reject_forbidden_fields(payload: dict[str, Any]) -> None:
    keys: list[str] = []
    _walk_keys(payload, keys)
    for key in keys:
        lowered = key.lower()
        if lowered in FORBIDDEN_KEYS or lowered.startswith("telegram") or USER_ID_FIELD_RE.search(key):
            raise NormalizeError("FORBIDDEN_FIELD", "Payload contains a forbidden identifier field.")


def _faq(payload: dict[str, Any]) -> list[dict[str, str]]:
    raw = payload.get("faq")
    if raw is None:
        raw = payload.get("faq_json", [])
    if isinstance(raw, str):
        raw = raw.strip()
        if not raw:
            return []
        try:
            raw = json.loads(raw)
        except json.JSONDecodeError as exc:
            raise NormalizeError("FAQ_INVALID", "faq_json is not valid JSON.") from exc
    if raw is None:
        return []
    if not isinstance(raw, list):
        raise NormalizeError("FAQ_INVALID", "faq must be an array.")
    out: list[dict[str, str]] = []
    for item in raw:
        if not isinstance(item, dict):
            continue
        q = item.get("q") or item.get("question")
        a = item.get("a") or item.get("answer")
        if isinstance(q, str) and isinstance(a, str):
            out.append({"q": q, "a": a})
    return out


def _language(payload: dict[str, Any]) -> str:
    lang = payload.get("language") or payload.get("market") or "en"
    lang = str(lang).strip().lower()
    if lang in ("fa-ir", "fa_ir", "persian", "farsi"):
        lang = "fa"
    if lang in ("en-us", "en_gb", "english"):
        lang = "en"
    if lang not in ("fa", "en"):
        raise NormalizeError("LANGUAGE_INVALID", "language must be fa or en.")
    return lang


def normalize_n8n_payload(payload: dict[str, Any]) -> dict[str, Any]:
    if not isinstance(payload, dict):
        raise NormalizeError("INVALID_JSON", "Payload must be a JSON object.")
    reject_forbidden_fields(payload)

    html = payload.get("article_html")
    if not isinstance(html, str):
        raise NormalizeError("HTML_MISSING", "article_html is required.")

    created = payload.get("created_at") or payload.get("approved_at") or payload.get("archived_at")
    archived = payload.get("archived_at") or created
    snapshot = {
        "archive_id": payload.get("archive_id"),
        "draft_id": payload.get("draft_id"),
        "slug": payload.get("slug"),
        "title": payload.get("title"),
        "language": _language(payload),
        "article_html": html,
        "metadata": {
            "meta_title": (payload.get("metadata") or {}).get("meta_title")
            if isinstance(payload.get("metadata"), dict)
            else payload.get("meta_title"),
            "meta_description": (payload.get("metadata") or {}).get("meta_description")
            if isinstance(payload.get("metadata"), dict)
            else payload.get("meta_description"),
        },
        "faq": _faq(payload),
        "approval_status": STATUS_MAP.get(str(payload.get("approval_status") or ""), payload.get("approval_status")),
        "archive_status": STATUS_MAP.get(str(payload.get("archive_status") or ""), payload.get("archive_status")),
        "publication_status": STATUS_MAP.get(
            str(payload.get("publication_status") or "not_published"),
            "not_published",
        ),
        "content_sha256": hashlib.sha256(html.encode("utf-8")).hexdigest(),
        "created_at": created,
        "archived_at": archived,
    }
    if payload.get("opportunity_id"):
        snapshot["opportunity_id"] = payload.get("opportunity_id")
    if payload.get("archive_version"):
        snapshot["archive_version"] = str(payload.get("archive_version"))

    errors = validate(snapshot)
    if errors:
        raise NormalizeError("SNAPSHOT_INVALID", "; ".join(errors))
    return snapshot
