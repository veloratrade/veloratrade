"""Reject secrets. Never extract, copy, or display credential values."""

from __future__ import annotations

import re
from typing import Any

SECRET_FIELD_NAMES = frozenset(
    {
        "data",
        "oauthTokenData",
        "raw",
        "secret",
        "password",
        "token",
        "accessToken",
        "access_token",
        "refreshToken",
        "apiKey",
        "api_key",
        "privateKey",
        "private_key",
        "botToken",
        "bot_token",
        "jwt",
        "n8n_jwt",
        "authorization",
        "clientSecret",
        "client_secret",
    }
)

SECRET_PATTERNS = [
    re.compile(r"github_pat_[A-Za-z0-9_]{10,}"),
    re.compile(r"ghp_[A-Za-z0-9]{20,}"),
    re.compile(r"\beyJ[A-Za-z0-9_\-]{20,}\.[A-Za-z0-9_\-]{10,}\."),
    re.compile(r"sk-[A-Za-z0-9]{20,}"),
    re.compile(r"AIza[A-Za-z0-9_\-]{20,}"),
    re.compile(r"\b\d{8,}:[A-Za-z0-9_-]{30,}\b"),
    re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"),
]


class SecretGuardError(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


def walk_keys(obj: Any, acc: list[str]) -> None:
    if isinstance(obj, dict):
        for key, value in obj.items():
            acc.append(str(key))
            walk_keys(value, acc)
    elif isinstance(obj, list):
        for item in obj:
            walk_keys(item, acc)


def walk_strings(obj: Any, acc: list[str]) -> None:
    if isinstance(obj, dict):
        for value in obj.values():
            walk_strings(value, acc)
    elif isinstance(obj, list):
        for item in obj:
            walk_strings(item, acc)
    elif isinstance(obj, str):
        acc.append(obj)


def credential_public_view(cred: dict[str, Any] | None, cred_type: str | None = None) -> dict[str, str]:
    """Return only type/name/id. Drop any secret-bearing fields."""
    cred = cred or {}
    if any(k in cred for k in SECRET_FIELD_NAMES):
        raise SecretGuardError("CREDENTIAL_SECRET_PRESENT", "Credential object contains a secret-bearing field.")
    out: dict[str, str] = {}
    if cred_type:
        out["type"] = cred_type
    if isinstance(cred.get("type"), str):
        out["type"] = cred["type"]
    if isinstance(cred.get("name"), str):
        out["name"] = cred["name"]
    if cred.get("id") is not None:
        out["id"] = str(cred["id"])
    allowed = {"id", "name", "type"}
    extra = set(cred.keys()) - allowed
    if extra:
        raise SecretGuardError(
            "CREDENTIAL_SECRET_PRESENT",
            "Credential object has unexpected fields (possible secret material).",
        )
    return out


def assert_no_secret_material(obj: Any, *, context: str = "export") -> None:
    keys: list[str] = []
    walk_keys(obj, keys)
    for key in keys:
        lowered = key.lower()
        if lowered in {n.lower() for n in SECRET_FIELD_NAMES} and lowered not in {"name", "type", "id"}:
            if lowered in {"data", "oauthtokendata", "password", "privatekey", "private_key", "bottoken", "jwt"}:
                raise SecretGuardError("CREDENTIAL_SECRET_PRESENT", f"Secret-bearing field in {context}.")
    strings: list[str] = []
    walk_strings(obj, strings)
    blob = "\n".join(strings)
    for pat in SECRET_PATTERNS:
        if pat.search(blob):
            if pat.pattern.startswith("github_pat") or pat.pattern.startswith("ghp_"):
                raise SecretGuardError("GITHUB_PAT_IN_N8N", "GitHub PAT detected in export.")
            raise SecretGuardError("SECRET_MATERIAL_IN_EXPORT", "Secret/token material detected in export.")
