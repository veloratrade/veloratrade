"""n8n Phase 3A — live READ-ONLY inspection client.

Talks to real SOURCE / TARGET n8n instances using the n8n Public REST API
(``/api/v1/*``) authenticated with the ``X-N8N-API-KEY`` header.

This client is STRICTLY read-only:

- Only ``GET`` / ``HEAD`` requests are permitted.
- ``POST`` / ``PUT`` / ``PATCH`` / ``DELETE`` are refused.
- Workflow activation, execution, and webhook registration are refused.
- Credential secret data is never retrieved. Only the credential *list*
  endpoint (id/name/type metadata) is used, and any ``data``/``oauthTokenData``
  field is defensively dropped.
- HTTPS is enforced unless explicitly allowed for local testing.
- Every request carries a timeout.
- Authorization headers are never logged, and tokens are never stored in
  manifests or reports.
- The client fails closed when required authentication configuration is
  missing.

Authentication configuration is read from environment variables only (never
from Git) — see ``load_config()`` for the exact variable names.
"""

from __future__ import annotations

import json
import os
from dataclasses import dataclass
from typing import Any, Callable
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from manifest import host_only
from secrets_guard import assert_no_secret_material

Json = dict[str, Any]

# --------------------------------------------------------------------------
# Transport contract
# --------------------------------------------------------------------------

#: Allowed HTTP methods. Anything else is refused.
ALLOWED_METHODS = frozenset({"GET", "HEAD"})
FORBIDDEN_METHODS = frozenset({"POST", "PUT", "PATCH", "DELETE"})

#: n8n public API auth header.
API_KEY_HEADER = "X-N8N-API-KEY"
DEFAULT_TIMEOUT = 30.0
LIST_LIMIT = 250  # n8n list endpoint cap.

HttpFn = Callable[[str, str, dict[str, str], bytes | None], tuple[int, Any]]


class LiveReadError(Exception):
    """Raised for any read failure. Carries a STOP code, never secret values."""

    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


# --------------------------------------------------------------------------
# Configuration (from environment only; fail closed)
# --------------------------------------------------------------------------
#
# Required (fail closed if absent):
#   N8N_SOURCE_BASE_URL   e.g. https://source.app.n8n.cloud
#   N8N_SOURCE_API_KEY    read-only n8n API key for SOURCE
#   N8N_TARGET_BASE_URL   e.g. https://target.app.n8n.cloud
#   N8N_TARGET_API_KEY    read-only n8n API key for TARGET
#
# Optional:
#   N8N_LIVE_TIMEOUT      request timeout seconds (default 30)
#   N8N_ALLOW_HTTP        1/true/yes/on to permit http:// (local testing only)
#   N8N_INCLUDE_ROW_COUNT 1/true/yes/on to fetch best-effort Data Table row counts
#                         (default off; pulls no row content into reports)


@dataclass
class LiveReadConfig:
    base_url: str
    api_key: str  # never printed, stored in manifests, or logged
    timeout: float = DEFAULT_TIMEOUT
    allow_http: bool = False
    include_row_count: bool = False


def _truthy(value: str) -> bool:
    return value.strip().lower() in {"1", "true", "yes", "on"}


def load_config(prefix: str, *, env: dict[str, str] | None = None) -> LiveReadConfig:
    """Build a client config from ``N8N_<prefix>_BASE_URL`` and ``N8N_<prefix>_API_KEY``.

    Fails closed with ``AUTH_CONFIG_MISSING`` if either required variable is
    missing or blank.
    """
    env = os.environ if env is None else env
    base = (env.get(f"N8N_{prefix}_BASE_URL") or "").strip().rstrip("/")
    key = (env.get(f"N8N_{prefix}_API_KEY") or "").strip()
    if not base or not key:
        raise LiveReadError(
            "AUTH_CONFIG_MISSING",
            f"N8N_{prefix}_BASE_URL and N8N_{prefix}_API_KEY are required "
            "(fail closed). No n8n read can be attempted.",
        )
    try:
        timeout = float((env.get("N8N_LIVE_TIMEOUT") or str(DEFAULT_TIMEOUT)).strip())
    except ValueError:
        timeout = DEFAULT_TIMEOUT
    return LiveReadConfig(
        base_url=base,
        api_key=key,
        timeout=timeout,
        allow_http=_truthy(env.get("N8N_ALLOW_HTTP") or ""),
        include_row_count=_truthy(env.get("N8N_INCLUDE_ROW_COUNT") or ""),
    )


# --------------------------------------------------------------------------
# Default HTTP transport
# --------------------------------------------------------------------------


def default_http(
    method: str,
    url: str,
    headers: dict[str, str],
    body: bytes | None,
    timeout: float = DEFAULT_TIMEOUT,
) -> tuple[int, Any]:
    """Minimal GET/HEAD transport. Never logs request headers or bodies."""
    req = Request(url, method=method, headers=headers, data=body)
    try:
        with urlopen(req, timeout=timeout) as resp:
            raw = resp.read()
            content_type = resp.headers.get("Content-Type", "")
            if method == "HEAD" or "json" not in content_type.lower():
                return resp.status, raw
            try:
                return resp.status, json.loads(raw.decode("utf-8"))
            except (ValueError, UnicodeDecodeError):
                return resp.status, raw.decode("utf-8", "replace")
    except HTTPError as exc:
        # Only the HTTP status is propagated; never the response body (it may
        # echo back a token). This is the fail-closed, secret-safe behaviour.
        return exc.code, None
    except (URLError, OSError) as exc:
        raise LiveReadError("NETWORK_ERROR", f"network failure: {exc.__class__.__name__}")


# --------------------------------------------------------------------------
# Live read-only client
# --------------------------------------------------------------------------


class LiveReadClient:
    """Read-only n8n client. See module docstring for guarantees."""

    def __init__(self, config: LiveReadConfig, *, http_fn: HttpFn | None = None, label: str = "LIVE"):
        self.config = config
        self.label = label
        self._http = http_fn or (lambda m, u, h, b: default_http(m, u, h, b, timeout=config.timeout))
        self._base = config.base_url if config.base_url.endswith("/") else config.base_url + "/"

    # -- low level ----------------------------------------------------------

    def _url(self, path: str) -> str:
        return self._base + path.lstrip("/")

    def request(self, method: str, path: str) -> Any:
        if method not in ALLOWED_METHODS:
            raise LiveReadError(
                "HTTP_METHOD_FORBIDDEN",
                f"HTTP {method} is not allowed (read-only; only GET/HEAD).",
            )
        base = self.config.base_url.lower()
        if not base.startswith("https://") and not self.config.allow_http:
            raise LiveReadError(
                "HTTPS_REQUIRED",
                "n8n base URL must be HTTPS (set N8N_ALLOW_HTTP=1 only for local testing).",
            )
        headers = {
            API_KEY_HEADER: self.config.api_key,
            "Accept": "application/json",
        }
        status, payload = self._http(method, self._url(path), headers, None)

        if method == "HEAD":
            if status < 200 or status >= 300:
                self._raise_status(status, path)
            return None

        if status < 200 or status >= 300:
            self._raise_status(status, path)
        return payload

    def _raise_status(self, status: int, path: str) -> None:
        # Report only the status and path; never bodies/headers/tokens.
        if status in (401, 403):
            code = "AUTH_INSUFFICIENT"
            msg = f"{status} — elevated/missing permissions for GET {path}"
        elif status == 404:
            code = "ENDPOINT_NOT_FOUND"
            msg = f"404 — n8n endpoint unavailable for GET {path}"
        elif status == 429:
            code = "RATE_LIMITED"
            msg = f"429 — rate limited on GET {path}"
        else:
            code = "HTTP_ERROR"
            msg = f"HTTP {status} on GET {path}"
        raise LiveReadError(code, msg)

    # -- hard refusals (defence in depth; never implemented) ----------------

    def activate(self, *args: Any, **kwargs: Any) -> Any:
        raise LiveReadError("PUBLISH_OR_ACTIVATE_REQUESTED", "Activation is forbidden (read-only).")

    def publish(self, *args: Any, **kwargs: Any) -> Any:
        raise LiveReadError("PUBLISH_OR_ACTIVATE_REQUESTED", "Publish is forbidden (read-only).")

    def execute(self, *args: Any, **kwargs: Any) -> Any:
        raise LiveReadError("PUBLISH_OR_ACTIVATE_REQUESTED", "Execute is forbidden (read-only).")

    def register_webhook(self, *args: Any, **kwargs: Any) -> Any:
        raise LiveReadError("WEBHOOK_REGISTRATION_FORBIDDEN", "Webhook registration is forbidden (read-only).")

    # -- read operations ------------------------------------------------------

    def list_workflows(self) -> list[Json]:
        payload = self.request("GET", f"workflows?limit={LIST_LIMIT}")
        items = self._extract_list(payload)
        out: list[Json] = []
        for item in items:
            if not isinstance(item, dict):
                continue
            assert_no_secret_material(item, context="workflow")
            # If the list response omits node graphs, fetch the single workflow.
            if not item.get("nodes"):
                item = self.get_workflow(str(item.get("id")))
            out.append(item)
        return out

    def get_workflow(self, workflow_id: str) -> Json:
        payload = self.request("GET", f"workflows/{workflow_id}")
        if not isinstance(payload, dict):
            raise LiveReadError("HTTP_ERROR", f"workflow {workflow_id} did not return an object")
        assert_no_secret_material(payload, context="workflow")
        return payload

    def list_credentials(self) -> list[Json]:
        """Return credential metadata (id/name/type) only. Secret fields dropped."""
        payload = self.request("GET", f"credentials?limit={LIST_LIMIT}")
        items = self._extract_list(payload)
        out: list[Json] = []
        for item in items:
            if not isinstance(item, dict):
                continue
            out.append(self._sanitize_credential_metadata(item))
        return out

    @staticmethod
    def _sanitize_credential_metadata(item: Json) -> Json:
        """Keep only id/name/type. Drop everything else, including secret fields."""
        clean: Json = {}
        for key in ("id", "name", "type"):
            if key in item:
                clean[key] = item[key]
        # Defensive: a single-credential response must never carry these.
        for forbidden in ("data", "oauthTokenData"):
            if forbidden in item:
                clean.pop(forbidden, None)
        return clean

    def list_data_tables(self) -> list[Json]:
        payload = self.request("GET", f"data-tables?limit={LIST_LIMIT}")
        items = self._extract_list(payload)
        out: list[Json] = []
        for item in items:
            if not isinstance(item, dict):
                continue
            out.append({"id": item.get("id"), "name": item.get("name")})
        return out

    def get_data_table_columns(self, table_id: str) -> list[Json]:
        payload = self.request("GET", f"data-tables/{table_id}/columns")
        return [c for c in self._extract_list(payload) if isinstance(c, dict)]

    def get_data_table_row_count(self, table_id: str) -> int | None:
        """Best-effort row count from a single-row fetch; never stores row content."""
        payload = self.request("GET", f"data-tables/{table_id}/rows?limit=1")
        if isinstance(payload, dict):
            for key in ("total", "count", "size"):
                val = payload.get(key)
                if isinstance(val, (int, float)):
                    return int(val)
            rows = payload.get("data")
            if isinstance(rows, list) and payload.get("nextCursor") in (None, ""):
                return len(rows)
        return None

    @staticmethod
    def _extract_list(payload: Any) -> list[Any]:
        if isinstance(payload, list):
            return payload
        if isinstance(payload, dict):
            for key in ("data", "items", "results"):
                value = payload.get(key)
                if isinstance(value, list):
                    return value
        return []
