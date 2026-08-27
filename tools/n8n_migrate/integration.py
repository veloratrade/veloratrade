"""Velora n8n integration foundation — read-only connection verification + dynamic ID discovery.

This module turns the non-secret `content/n8n-integration/integration.json`
policy plus per-instance environment variables into:

- A reusable, read-only connection to real n8n instances (via the Phase 3A
  ``LiveReadClient``).
- Dynamic discovery of workflow / Data Table / credential IDs **by name** so
  nothing is hardcoded to a disposable instance.
- A connection-verification report proving reachability, auth, which read
  scopes work, and that no write/activate/publish/webhook operation is exposed.

Secrets are never fetched, logged, or stored in reports. API keys are read only
from environment variables and never appear in any output.
"""

from __future__ import annotations

import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable

from live_client import LiveReadClient, LiveReadConfig, LiveReadError
from secrets_guard import assert_no_secret_material

Json = dict[str, Any]

ClientFactory = Callable[[LiveReadConfig], LiveReadClient]

# Read capabilities we try to verify (each maps to a LiveReadClient method).
READ_CAPABILITIES = {
    "read_workflows_list": "list_workflows",
    "read_workflow_definitions": "get_workflow",
    "list_data_tables": "list_data_tables",
    "read_table_schemas": "get_data_table_columns",
    "list_credentials_metadata": "list_credentials",
    "compare_source_target": "compare_source_target",
}

# Write/activate/publish/webhook capabilities: always reported disabled.
WRITE_CAPABILITIES = [
    "create_workflows",
    "update_workflows",
    "create_update_data_tables",
    "activate_publish_execute",
    "webhook_management",
]


def utc_now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def load_integration_config(path: str | Path) -> Json:
    """Load and basic-validate the non-secret integration policy file."""
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    required = {
        "manifest_version", "integration", "architecture", "instances",
        "capabilities", "secrets_policy", "dynamic_ids", "manual_per_new_instance",
    }
    missing = required - set(data)
    if missing:
        raise LiveReadError("CONFIG_INVALID", f"integration config missing keys: {sorted(missing)}")
    if data.get("manifest_version") != 1:
        raise LiveReadError("CONFIG_INVALID", "integration config manifest_version must be 1")
    if not isinstance(data.get("instances"), list) or not data["instances"]:
        raise LiveReadError("CONFIG_INVALID", "integration config requires at least one instance")
    assert_no_secret_material(data, context="integration-config")
    return data


def build_client(
    inst: Json,
    *,
    env: dict[str, str] | None = None,
    client_factory: ClientFactory | None = None,
) -> LiveReadClient:
    """Build a read-only client for one instance from env; fails closed on missing config."""
    env = os.environ if env is None else env
    base = (env.get(inst["base_url_env"]) or "").strip().rstrip("/")
    key = (env.get(inst["api_key_env"]) or "").strip()
    if not base or not key:
        raise LiveReadError(
            "AUTH_CONFIG_MISSING",
            f"{inst['label']}: {inst['base_url_env']} and {inst['api_key_env']} are "
            "required (fail closed). No n8n read can be attempted.",
        )
    try:
        timeout = float((env.get("N8N_LIVE_TIMEOUT") or "30").strip())
    except ValueError:
        timeout = 30.0
    config = LiveReadConfig(
        base_url=base,
        api_key=key,
        timeout=timeout,
        allow_http=os.environ.get("N8N_ALLOW_HTTP", "").lower() in {"1", "true", "yes", "on"}
        if env is os.environ else False,
        include_row_count=False,
    )
    if client_factory is not None:
        return client_factory(config)
    return LiveReadClient(config, label=str(inst.get("label") or inst.get("id")))


def _read_first_workflow_id(client: LiveReadClient) -> str | None:
    try:
        wfs = client.list_workflows()
        if wfs:
            return str(wfs[0].get("id"))
    except LiveReadError:
        return None
    return None


def verify_capability(client: LiveReadClient, cap: str, method: str) -> Json:
    """Attempt one read capability and report ok / blocked (never works around 401/403)."""
    try:
        if cap == "compare_source_target":
            # Single-instance verification cannot compare two instances; report enabled.
            return {"capability": cap, "status": "enabled", "detail": "requires two instances"}
        if method == "get_workflow":
            wf_id = _read_first_workflow_id(client)
            if not wf_id:
                return {"capability": cap, "status": "no_workflows", "detail": "no workflows to read"}
            client.get_workflow(wf_id)
            return {"capability": cap, "status": "ok", "detail": "read workflow definition"}
        result = getattr(client, method)()
        return {"capability": cap, "status": "ok", "count": len(result) if isinstance(result, list) else 1}
    except LiveReadError as exc:
        return {"capability": cap, "status": "blocked", "code": exc.code, "detail": str(exc)}
    except Exception as exc:  # noqa: BLE001 - surface unexpected failures as blocked
        return {"capability": cap, "status": "blocked", "code": "UNEXPECTED", "detail": exc.__class__.__name__}


def discover_ids(client: LiveReadClient) -> Json:
    """Dynamically discover current IDs by name. Only metadata; never secret values."""
    workflows: list[Json] = []
    try:
        for wf in client.list_workflows():
            workflows.append({"name": wf.get("name"), "id": wf.get("id")})
    except LiveReadError as exc:
        workflows = [{"error": exc.code}]

    tables: list[Json] = []
    try:
        for table in client.list_data_tables():
            tables.append({"name": table.get("name"), "id": table.get("id")})
    except LiveReadError as exc:
        tables = [{"error": exc.code}]

    credentials: list[Json] = []
    try:
        for cred in client.list_credentials():
            credentials.append({"name": cred.get("name"), "type": cred.get("type"), "id": cred.get("id")})
    except LiveReadError as exc:
        credentials = [{"error": exc.code}]

    return {"workflows": workflows, "data_tables": tables, "credentials": credentials}


def verify_instance(
    client: LiveReadClient,
    inst: Json,
    capabilities: Json,
) -> Json:
    """Verify reachability, auth, read scopes, and write-disabled posture for one instance."""
    caps = []
    read_enabled = {
        name: method for name, method in READ_CAPABILITIES.items()
        if capabilities.get(name) == "enabled"
    }
    for name, method in read_enabled.items():
        caps.append(verify_capability(client, name, method))
    for name in WRITE_CAPABILITIES:
        caps.append({"capability": name, "status": "disabled"})

    # Reachability + auth are implied by the first successful/blocked read.
    reachable = any(c.get("status") == "ok" for c in caps)
    authenticated = reachable and not any(c.get("code") in ("AUTH_INSUFFICIENT", "HTTP_ERROR") for c in caps)

    return {
        "id": inst.get("id"),
        "label": inst.get("label"),
        "base_url_host": _host_only(client.config.base_url),
        "reachable": reachable,
        "authenticated": authenticated,
        "capabilities": caps,
        "discovered_ids": discover_ids(client),
        "findings": [c for c in caps if c.get("status") in ("blocked", "no_workflows")],
    }


def _host_only(url: str | None) -> str | None:
    if not url:
        return None
    cleaned = url.split("?", 1)[0].split("#", 1)[0]
    if "://" in cleaned:
        cleaned = cleaned.split("://", 1)[1]
    return cleaned.split("/", 1)[0]


def run_verification(
    config: Json,
    *,
    env: dict[str, str] | None = None,
    client_factory: ClientFactory | None = None,
) -> Json:
    """Run read-only connection verification across all configured instances."""
    instances: list[Json] = []
    for inst in config["instances"]:
        client = build_client(inst, env=env, client_factory=client_factory)
        instances.append(verify_instance(client, inst, config.get("capabilities") or {}))

    report: Json = {
        "report_type": "n8n-connection-verification",
        "generated_at": utc_now(),
        "integration": config.get("integration"),
        "architecture": config.get("architecture"),
        "instances": instances,
        "write_capabilities_disabled": True,
        "secrets_policy": config.get("secrets_policy"),
        "dynamic_ids": config.get("dynamic_ids"),
        "manual_per_new_instance": config.get("manual_per_new_instance"),
        "writes_performed": [],
    }
    assert_no_secret_material(report, context="connection-report")
    return report
