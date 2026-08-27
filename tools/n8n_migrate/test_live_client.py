#!/usr/bin/env python3
"""Offline tests for the Phase 3A live READ-ONLY n8n client. No live network."""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))

from live_client import (  # noqa: E402
    LiveReadClient,
    LiveReadConfig,
    LiveReadError,
    load_config,
)
from live_report import build_inventory, build_live_report  # noqa: E402

FIX = ROOT / "fixtures"


def need(passed: bool, label: str, failed: list[str]) -> None:
    print(f"{label}: {'OK' if passed else 'NOT_OK'}")
    if not passed:
        failed.append(label)


def load(name: str):
    return json.loads((FIX / name).read_text(encoding="utf-8"))


class FakeN8N:
    """In-memory n8n Public API stand-in that serves GET only."""

    def __init__(self, workflows, credentials, tables, *, api_key="test-synthetic-key"):
        self.workflows = workflows
        self.credentials = credentials
        self.tables = tables
        self.api_key = api_key
        self.records: list[dict] = []  # method + url, never headers/keys

    def http(self, method: str, url: str, headers: dict, body: bytes | None) -> tuple[int, object]:
        self.records.append({"method": method, "url": url.split("?", 1)[0]})
        if method not in ("GET", "HEAD"):
            return 405, None
        path = url.split("?", 1)[0]
        # workflows
        if path.endswith("/workflows"):
            return 200, {"data": self.workflows}
        if "/workflows/" in path:
            wf_id = path.rsplit("/", 1)[-1]
            for w in self.workflows:
                if w.get("id") == wf_id:
                    return 200, w
            return 404, None
        # credentials (metadata only)
        if path.endswith("/credentials"):
            return 200, {"data": self.credentials}
        # data-tables
        if path.endswith("/data-tables"):
            return 200, {"data": [{"id": t["id"], "name": t["name"]} for t in self.tables]}
        if "/columns" in path:
            table_id = path.split("/data-tables/")[1].split("/columns")[0]
            for t in self.tables:
                if t["id"] == table_id:
                    return 200, {"data": t.get("columns", [])}
            return 404, None
        if "/rows" in path:
            return 200, {"data": [], "total": 0}
        return 404, None


def fake_source_client():
    return LiveReadClient(
        LiveReadConfig(base_url="https://source.example.invalid", api_key="sk-source-synthetic"),
        http_fn=FakeN8N(
            workflows=[load("source_workflow.json")],
            credentials=load("source_credentials.json"),
            tables=load("source_tables.json"),
        ).http,
        label="SOURCE",
    )


def fake_target_client():
    return LiveReadClient(
        LiveReadConfig(base_url="https://target.example.invalid", api_key="sk-target-synthetic"),
        http_fn=FakeN8N(
            workflows=[load("target_workflow.json")],
            credentials=load("target_credentials.json"),
            tables=load("target_tables.json"),
        ).http,
        label="TARGET",
    )


def main() -> int:
    failed: list[str] = []

    # 1) GET allowed.
    client = fake_source_client()
    workflows = client.list_workflows()
    need(isinstance(workflows, list) and len(workflows) == 1, "get_allowed_list_workflows", failed)
    need(workflows[0]["nodes"], "get_workflow_has_nodes", failed)

    # 2) POST/PUT/PATCH/DELETE rejected.
    for method in ("POST", "PUT", "PATCH", "DELETE"):
        try:
            client.request(method, "workflows")
            need(False, f"write_method_rejected_{method}", failed)
        except LiveReadError as exc:
            need(exc.code == "HTTP_METHOD_FORBIDDEN", f"write_method_rejected_{method}", failed)

    # 3) Missing authentication fails closed.
    try:
        load_config("SOURCE", env={})
        need(False, "missing_auth_fails_closed", failed)
    except LiveReadError as exc:
        need(exc.code == "AUTH_CONFIG_MISSING", "missing_auth_fails_closed", failed)

    # 4) Authorization/token values never appear in output or errors.
    src_inv = build_inventory(fake_source_client(), label="SOURCE")
    tgt_inv = build_inventory(fake_target_client(), label="TARGET")
    report = build_live_report(src_inv, tgt_inv)
    report_blob = json.dumps(report)
    need("sk-source-synthetic" not in report_blob, "no_source_key_in_report", failed)
    need("sk-target-synthetic" not in report_blob, "no_target_key_in_report", failed)
    # A 401 must not echo a token either.
    unauth = LiveReadClient(
        LiveReadConfig(base_url="https://src.example.invalid", api_key="secret-x-api-key-abc"),
        http_fn=lambda m, u, h, b: (401, None),
    )
    try:
        unauth.list_workflows()
        need(False, "unauth_raises", failed)
    except LiveReadError as exc:
        need(exc.code == "AUTH_INSUFFICIENT", "unauth_code", failed)
        need("secret-x-api-key-abc" not in str(exc), "unauth_does_not_echo_key", failed)

    # 5) Credential secret fields are removed from inspection output.
    secret_creds = [
        {"id": "c1", "name": "Telegram account", "type": "telegramApi",
         "data": {"accessToken": "TELEGRAM_BOT_TOKEN_12345", "username": "velora_bot"}},
        {"id": "c2", "name": "Google account", "type": "googleOAuth2Api",
         "data": {"oauthTokenData": {"access_token": "ya29.super-secret"}}},
    ]
    secret_client = LiveReadClient(
        LiveReadConfig(base_url="https://src.example.invalid", api_key="k"),
        http_fn=FakeN8N(workflows=[], credentials=secret_creds, tables=[]).http,
    )
    creds = secret_client.list_credentials()
    need(all(set(c) == {"id", "name", "type"} for c in creds), "cred_metadata_only_keys", failed)
    blob = json.dumps(creds)
    need("TELEGRAM_BOT_TOKEN_12345" not in blob and "ya29.super-secret" not in blob,
         "cred_secret_fields_removed", failed)
    need("data" not in blob and "oauthTokenData" not in blob, "cred_no_secret_key_in_output", failed)

    # 6) SOURCE/TARGET comparison works with fixtures.
    src_inv = build_inventory(fake_source_client(), label="SOURCE")
    tgt_inv = build_inventory(fake_target_client(), label="TARGET")
    report = build_live_report(src_inv, tgt_inv)
    wf_map = {w["source_name"]: w for w in report["WORKFLOW_MAPPING"]}
    entry = wf_map.get("VELORA — Test Unified")
    need(entry is not None and entry["status"] == "match", "wf_mapping_match_fixtures", failed)
    by_type = {(c["type"], c["name"]): c for c in report["CREDENTIAL_MAPPING"]}
    need(by_type[("telegramApi", "Telegram account")]["mapping_status"] == "mapped",
         "cred_telegram_mapped_fixtures", failed)
    need(by_type[("openAiApi", "OpenAI account")]["mapping_status"] == "missing_on_target",
         "cred_openai_missing_fixtures", failed)
    need(by_type[("openAiApi", "OpenAI account")]["target_exists"] is False,
         "missing_cred_target_exists_false", failed)
    need("data" not in json.dumps(report["CREDENTIAL_MAPPING"]), "cred_mapping_no_secrets", failed)
    cred_fields_ok = all(
        set(c) == {"name", "type", "source_exists", "target_exists", "mapping_status"}
        for c in report["CREDENTIAL_MAPPING"]
    )
    need(cred_fields_ok, "cred_mapping_only_5_fields", failed)
    table_map = {t["name"]: t for t in report["DATA_TABLE_MAPPING"]}
    need(table_map["seo_scan_snapshots"]["schema_status"] == "match", "table_schema_match_fixtures", failed)
    need(any(c["name"] == "OpenAI account" for c in report["MISSING_CREDENTIALS"]),
         "missing_credentials_listed", failed)

    # 7) No write operation can be triggered through the live client.
    write_blocked = True
    for op in (client.activate, client.publish, client.execute, client.register_webhook):
        try:
            op()
            write_blocked = False
        except LiveReadError as exc:
            write_blocked = write_blocked and exc.code in (
                "PUBLISH_OR_ACTIVATE_REQUESTED", "WEBHOOK_REGISTRATION_FORBIDDEN",
            )
    need(write_blocked, "no_write_ops_available", failed)

    # HTTPS enforced.
    try:
        LiveReadClient(
            LiveReadConfig(base_url="http://src.example.invalid", api_key="k"),
            http_fn=lambda m, u, h, b: (200, {"data": []}),
        ).list_workflows()
        need(False, "https_enforced", failed)
    except LiveReadError as exc:
        need(exc.code == "HTTPS_REQUIRED", "https_enforced", failed)

    print("SUMMARY failed=%s %s" % (len(failed), failed))
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
