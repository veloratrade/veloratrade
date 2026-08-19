#!/usr/bin/env python3
"""Offline contract tests for tools/velora-status.sh.

No network, GitHub workflow, environment, or secret access is performed.
"""

from __future__ import annotations

import json
import pathlib
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "tools" / "velora-status.sh"


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(SCRIPT), *args],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=check,
        timeout=30,
    )


class VeloraStatusContractTest(unittest.TestCase):
    def test_help_is_concise(self) -> None:
        result = run("--help")
        self.assertIn("VELORA live session bootstrap", result.stdout)
        self.assertIn("--context", result.stdout)
        self.assertLess(len(result.stdout.splitlines()), 20)

    def test_offline_json_contract(self) -> None:
        result = run("--json", "--offline")
        data = json.loads(result.stdout)
        self.assertEqual("offline", data["_meta"]["network_mode"])
        self.assertFalse(data["_meta"]["github_live"])
        self.assertIsNone(data["environments"]["production"]["live_probe_status"])
        self.assertEqual("B-11", data["session_state"]["active_task"]["id"])
        self.assertIn("ahead_by", data["repository"])
        self.assertNotIn("names", data["secrets"])

    def test_text_contains_handoff_and_proof(self) -> None:
        result = run("--offline")
        self.assertIn("۷. مأموریت فعال", result.stdout)
        self.assertIn("B-11", result.stdout)
        self.assertIn("VELORA-RUN-", result.stdout)
        self.assertIn("هیچ درخواست شبکه‌ای انجام نمی‌شود", result.stdout)

    def test_context_artifacts_are_generated_and_ignored(self) -> None:
        result = run("--context", "--offline")
        json_path = ROOT / ".session" / "SESSION_CONTEXT.json"
        md_path = ROOT / ".session" / "SESSION_CONTEXT.md"
        self.assertTrue(json_path.is_file())
        self.assertTrue(md_path.is_file())
        json.loads(json_path.read_text(encoding="utf-8"))
        self.assertIn("B-11", md_path.read_text(encoding="utf-8"))
        ignored = subprocess.run(
            ["git", "check-ignore", "-q", str(json_path.relative_to(ROOT))],
            cwd=ROOT,
        )
        self.assertEqual(0, ignored.returncode)
        self.assertIn("Context ساخته شد", result.stdout)


if __name__ == "__main__":
    unittest.main()
