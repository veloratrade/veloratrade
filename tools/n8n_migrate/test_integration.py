#!/usr/bin/env python3
"""Offline tests for the Velora n8n integration foundation. No live network."""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))

from live_client import LiveReadClient, LiveReadConfig, LiveReadError  # noqa: E402
from integration import (  # noqa: E402
    load_integration_config,
    build_client,
    discover_ids,
    run_verification,
    verify_instance,
)

FIX = ROOT / "fixtures"
CONFIG = ROOT.parent.parent / "content" / "n8n-integration" / "integration.json"


def need(passed: bool, label: str, failed: list[str]) -> None:
    print(f"{label}: {'OK' if passed else 'NOT_OK'}")
    if not passed:
        failed.append(label)


def load(name: str):
    return json.loads((FIX / name).read_text(encoding="utf-8"))


class FakeN8N:
    def __init__(self, workflows, credentials, tables, *, block_workflows=False):
        self.workflows = workflows
        self.credentials = credentials
        self.tables = tables
        self.block_workflows = block_workflows

    def http(self, method, url, headers, body):
        path = url.split("?", 1)[0]
        if self.block_workflows and path.endswith("/workflows"):
            return 403, None
        if path.endswith("/workflows"):
            return 200, {"data": self.workflows}
        if "/workflows/" in path:
            wid = path.rsplit("/", 1)[-1]
            for w in self.workflows:
                if w.get("id") == wid:
                    return 200, w
            return 404, None
        if path.endswith("/credentials"):
            return 200, {"data": self.credentials}
        if path.endswith("/data-tables"):
            return 200, {"data": [{"id": t["id"], "name": t["name"]} for t in self.tables]}
        if "/columns" in path:
            tid = path.split("/data-tables/")[1].split("/columns")[0]
            for t in self.tables:
                if t["id"] == tid:
                    return 200, {"data": t.get("columns", [])}
            return 404, None
        if "/rows" in path:
            return 200, {"data": [], "total": 0}
        return 404, None


def make_client(fake, label="X"):
    return LiveReadClient(
        LiveReadConfig(base_url=f"https://{label.lower()}.example.invalid", api_key=f"sk-{label}-synthetic"),
        http_fn=fake.http,
        label=label,
    )


def main() -> int:
    failed: list[str] = []
    config = load_integration_config(CONFIG)

    # Config is non-secret; must not contain API keys.
    need(json.dumps(config) and "api_key_env" in json.dumps(config), "config_loads", failed)
    need("N8N_SOURCE_API_KEY" in json.dumps(config), "config_has_env_names_only", failed)
    need("ghp_" not in json.dumps(config) and "sk-" not in json.dumps(config), "config_no_secret_values", failed)
    need(config["integration"]["independent_of_workflow_contents"] is True, "independent_of_workflows", failed)
    need(config["dynamic_ids"]["never_hardcoded"] is True, "dynamic_ids_policy", failed)
    need(all(c == "disabled" for c in [config["capabilities"].get(k) for k in (
        "create_workflows", "update_workflows", "create_update_data_tables",
        "activate_publish_execute", "webhook_management")]), "write_caps_disabled_in_config", failed)
    need(len(config["manual_per_new_instance"]) >= 1, "manual_checklist_present", failed)

    # Missing auth fails closed.
    try:
        build_client(config["instances"][0], env={})
        need(False, "missing_auth_fails_closed", failed)
    except LiveReadError as exc:
        need(exc.code == "AUTH_CONFIG_MISSING", "missing_auth_fails_closed", failed)

    # Full verification via fake clients through a factory.
    def factory(inst, cap=config["capabilities"]):
        label = inst.get("label", "X").lower()
        return make_client(
            FakeN8N(
                workflows=[load("source_workflow.json")],
                credentials=load("source_credentials.json"),
                tables=load("source_tables.json"),
            ),
            label=label,
        )

    # Use build_client with a client_factory for both instances.
    def make_client_for(cfg):
        label = cfg.base_url.split("://")[1].split(".")[0]
        return make_client(FakeN8N(
            workflows=[load("source_workflow.json")],
            credentials=load("source_credentials.json"),
            tables=load("source_tables.json"),
        ), label=label)

    env = {
        "N8N_SOURCE_BASE_URL": "https://source.example.invalid",
        "N8N_SOURCE_API_KEY": "sk-source-synthetic",
        "N8N_TARGET_BASE_URL": "https://target.example.invalid",
        "N8N_TARGET_API_KEY": "sk-target-synthetic",
    }
    report = run_verification(config, env=env, client_factory=make_client_for)

    need(report["report_type"] == "n8n-connection-verification", "report_type", failed)
    need(len(report["instances"]) == 2, "two_instances_verified", failed)
    need(all(i["reachable"] and i["authenticated"] for i in report["instances"]), "all_reachable_auth", failed)
    need(report["write_capabilities_disabled"] is True, "write_disabled_flag", failed)
    need(report["writes_performed"] == [], "no_writes", failed)

    # Read caps ok; write caps disabled.
    source = next(i for i in report["instances"] if i["id"] == "source")
    read_ok = [c for c in source["capabilities"] if c["status"] == "ok"]
    need(len(read_ok) >= 4, "read_caps_ok", failed)
    write_disabled = [c for c in source["capabilities"] if c["capability"] in (
        "create_workflows", "update_workflows", "create_update_data_tables",
        "activate_publish_execute", "webhook_management")]
    need(all(c["status"] == "disabled" for c in write_disabled), "write_caps_disabled_in_report", failed)

    # Dynamic discovery returns IDs by name, no secrets.
    discovered = source["discovered_ids"]
    need(any(w["name"] == "VELORA — Test Unified" and w["id"] for w in discovered["workflows"]),
         "discover_workflow_id_by_name", failed)
    need(any(t["name"] == "seo_scan_snapshots" and t["id"] for t in discovered["data_tables"]),
         "discover_table_id_by_name", failed)
    need(any(c["name"] == "Telegram account" and c["id"] for c in discovered["credentials"]),
         "discover_cred_id", failed)
    blob = json.dumps(report)
    need("sk-source-synthetic" not in blob and "sk-target-synthetic" not in blob, "no_keys_in_report", failed)
    need("data" not in json.dumps(discovered["credentials"]), "no_cred_data_in_discovery", failed)
    # No hardcoded IDs in config.
    need("SRCWF01aaaaaaaa" not in json.dumps(config), "no_hardcoded_workflow_id_in_config", failed)

    # Per-instance capability blocking reported (does not work around 401/403).
    blocked_client = make_client(FakeN8N(
        workflows=[], credentials=[], tables=[], block_workflows=True
    ), label="B")
    blocked_inst = config["instances"][0].copy()
    blocked_inst["label"] = "B"
    b = verify_instance(blocked_client, blocked_inst, config["capabilities"])
    need(any(c.get("code") == "AUTH_INSUFFICIENT" for c in b["capabilities"]), "blocked_scope_reported", failed)

    print("SUMMARY failed=%s %s" % (len(failed), failed))
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
