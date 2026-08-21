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
        # ahead_by/behind_by contract (CI-aware, still strict):
        # velora-status.sh only emits divergence counts when origin tracking for the
        # current branch exists (git rev-list HEAD...origin/<branch>). GitHub Actions
        # PR checkouts run in detached HEAD without upstream, where graceful omission
        # is the intended behavior — its absence must be accepted exactly there.
        repo = data["repository"]
        if repo.get("origin_tracking_head"):
            self.assertIn("ahead_by", repo)
            self.assertIsInstance(repo["ahead_by"], int)
            self.assertGreaterEqual(repo["ahead_by"], 0)
            self.assertIn("behind_by", repo)
            self.assertIsInstance(repo["behind_by"], int)
            self.assertGreaterEqual(repo["behind_by"], 0)
        else:
            self.assertNotIn("ahead_by", repo)
            self.assertNotIn("behind_by", repo)
        self.assertNotIn("names", data["secrets"])

    def test_text_contains_handoff_and_proof(self) -> None:
        result = run("--offline")
        self.assertIn("۷. مأموریت فعال", result.stdout)
        self.assertIn("B-11", result.stdout)
        self.assertIn("VELORA-RUN-", result.stdout)
        self.assertIn("هیچ درخواست شبکه‌ای انجام نمی‌شود", result.stdout)

    def test_workspace_footprint_guard_is_wired(self) -> None:
        """AGENTS.md §13.1 guard: non-sparse bulky checkouts must be detectable.

        The guard must exist in the script, use the canonical violation id,
        increment context_errors (so --check turns red), and stay silent in CI
        where a full checkout is legitimate.
        """
        source = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("WORKSPACE-FOOTPRINT-VIOLATION", source)
        self.assertIn("core.sparseCheckout", source)
        self.assertIn("GITHUB_ACTIONS", source)
        guard_block = source.split("WORKSPACE-FOOTPRINT-VIOLATION")[1]
        self.assertIn("context_errors", guard_block)

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
