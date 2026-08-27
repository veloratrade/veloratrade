"""Shared constants for n8n instance migration. No live I/O."""

from __future__ import annotations

VOLATILE_WORKFLOW_KEYS = frozenset(
    {
        "updatedAt",
        "createdAt",
        "versionId",
        "activeVersionId",
        "activeVersion",
        "triggerCount",
        "nodeCount",
        "scopes",
        "canExecute",
        "pinData",
        "staticData",
        "parentFolderId",
        "meta",
        "tags",
        "shared",
        "usedCredentials",
        "active",
        "isArchived",
    }
)

VOLATILE_NODE_KEYS = frozenset(
    {
        "webhookId",
        "position",
        "id",
    }
)

NODE_LOGIC_KEYS = (
    "name",
    "type",
    "typeVersion",
    "parameters",
    "disabled",
    "onError",
    "retryOnFail",
    "maxTries",
    "waitBetweenTries",
    "alwaysOutputData",
    "executeOnce",
    "continueOnFail",
    "notes",
)

TELEGRAM_TRIGGER_TYPE = "n8n-nodes-base.telegramTrigger"
SCHEDULE_TRIGGER_TYPE = "n8n-nodes-base.scheduleTrigger"
DATATABLE_TYPE = "n8n-nodes-base.dataTable"

DEFAULT_SAFETY = {
    "source_read_only": True,
    "target_dry_run": True,
    "never_activate": True,
    "never_publish": True,
    "never_execute": True,
    "never_send_telegram": True,
    "never_delete": True,
    "disable_telegram_trigger_on_target": True,
    "disable_schedule_triggers_on_target": True,
    "strip_webhook_ids": True,
    "strip_pin_data": True,
    "credentials_transfer": False,
    "allow_live_network": False,
}

APPLY_FLAGS_REQUIRED = (
    "--i-understand-this-writes-to-target",
    "--owner-authorized-apply",
)
