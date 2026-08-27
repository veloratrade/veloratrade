"""n8n access layer. SOURCE is read-only. TARGET defaults to dry-run. No live HTTP unless allowed."""

from __future__ import annotations

from typing import Any, Callable

from safety import SafetyStop

Json = dict[str, Any]
HttpFn = Callable[[str, str, dict[str, str], bytes | None], tuple[int, Any]]


class FileBundle:
    """Offline exports. This is the only transport used in preparation tests."""

    def __init__(
        self,
        *,
        workflows: dict[str, Json] | None = None,
        tables: list[Json] | None = None,
        credentials: list[Json] | None = None,
        label: str = "bundle",
    ):
        self.label = label
        self.workflows = dict(workflows or {})
        self.tables = list(tables or [])
        self.credentials = list(credentials or [])
        self.writes: list[str] = []

    def get_workflow(self, workflow_id: str) -> Json | None:
        return self.workflows.get(workflow_id)

    def find_workflow_by_name(self, name: str) -> Json | None:
        for wf in self.workflows.values():
            if wf.get("name") == name:
                return wf
        return None

    def list_tables(self) -> list[Json]:
        return list(self.tables)

    def list_credentials_metadata(self) -> list[Json]:
        # Already metadata-only by contract.
        return [{k: c.get(k) for k in ("id", "name", "type") if k in c} for c in self.credentials]


class SourceReadOnly:
    def __init__(self, bundle: FileBundle):
        self.bundle = bundle

    def get_workflow(self, workflow_id: str) -> Json | None:
        return self.bundle.get_workflow(workflow_id)

    def list_tables(self) -> list[Json]:
        return self.bundle.list_tables()

    def list_credentials_metadata(self) -> list[Json]:
        return self.bundle.list_credentials_metadata()

    def write(self, *args: Any, **kwargs: Any) -> None:
        raise SafetyStop("SOURCE_WRITE_FORBIDDEN", "SOURCE is read-only.")


class TargetDryRun:
    def __init__(self, bundle: FileBundle):
        self.bundle = bundle
        self.planned: list[Json] = []

    def find_workflow_by_name(self, name: str) -> Json | None:
        return self.bundle.find_workflow_by_name(name)

    def list_tables(self) -> list[Json]:
        return self.bundle.list_tables()

    def list_credentials_metadata(self) -> list[Json]:
        return self.bundle.list_credentials_metadata()

    def plan_create_workflow(self, payload: Json) -> Json:
        self.planned.append({"op": "create_workflow", "name": payload.get("name"), "active": False})
        return {"op": "create_workflow", "dry_run": True, "name": payload.get("name")}

    def plan_create_table(self, payload: Json) -> Json:
        self.planned.append({"op": "create_table", "name": payload.get("name")})
        return {"op": "create_table", "dry_run": True, "name": payload.get("name")}

    def plan_insert_rows(self, table_name: str, rows: list[Json]) -> Json:
        self.planned.append({"op": "insert_rows", "table": table_name, "count": len(rows)})
        return {"op": "insert_rows", "dry_run": True, "table": table_name, "count": len(rows)}

    def activate(self, *args: Any, **kwargs: Any) -> None:
        raise SafetyStop("PUBLISH_OR_ACTIVATE_REQUESTED", "Activation is forbidden.")

    def publish(self, *args: Any, **kwargs: Any) -> None:
        raise SafetyStop("PUBLISH_OR_ACTIVATE_REQUESTED", "Publish is forbidden.")

    def execute(self, *args: Any, **kwargs: Any) -> None:
        raise SafetyStop("PUBLISH_OR_ACTIVATE_REQUESTED", "Execute is forbidden.")

    def write(self, *args: Any, **kwargs: Any) -> None:
        raise SafetyStop("APPLY_NOT_AUTHORIZED", "TARGET dry-run refuses writes.")


def refuse_live_http(*args: Any, **kwargs: Any) -> tuple[int, Any]:
    raise SafetyStop("LIVE_NETWORK_FORBIDDEN", "Live n8n HTTP is disabled in preparation mode.")
