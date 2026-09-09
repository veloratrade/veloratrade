#!/usr/bin/env python3
"""BACKUP GATE enforcement tests (law of 2026-09-09).

Proves — without any real staging/production contact — that:

  1. a complete, verified backup evidence set PASSES the gate;
  2. backup creation failure            → gate fails;
  3. integrity verification failure     → gate fails;
  4. missing SHA256                     → gate fails;
  5. missing backup_id                  → gate fails;
  6. official-storage upload failure    → gate fails;
  7. verification_status != INTEGRITY_VERIFIED → gate fails;
  8. deploy attempted without successful backup output → deploy blocked (static);
  9. migration attempted without successful backup output → migration blocked (static);
 10. manual backup invocation exists and performs backup-only (static);
 11. manual backup invocation can never run a migration/deploy (static).

All dynamic cases run against the canonical gate module
(ops/velora-mgmt/backup_gate.py) with synthetic evidence dicts; no network,
no workflows dispatched, no databases touched.
"""
import os
import subprocess
import sys
import unittest

import yaml

HERE = os.path.dirname(os.path.abspath(__file__))
MGMT = os.path.abspath(os.path.join(HERE, ".."))
ROOT = os.path.abspath(os.path.join(MGMT, "..", ".."))
WF = os.path.join(ROOT, ".github", "workflows")
sys.path.insert(0, MGMT)

from backup_gate import evaluate_backup_gate, INTEGRITY_VERIFIED  # noqa: E402


def load(name):
    with open(os.path.join(WF, name)) as f:
        text = f.read()
    return yaml.safe_load(text), text


def valid_evidence(env="staging"):
    return {
        "backup_id": f"db-backup-{env}-20260909-abcd1234",
        "release_tag": "db-backup-staging-20260909-abcd1234",
        "sha256": "a" * 64,
        "source_commit_sha": "23d616767b0bfb2c9349ffc14dddda714775fce6",
        "verification_status": INTEGRITY_VERIFIED,
        "environment": env,
    }


class GateModuleTests(unittest.TestCase):
    def test_01_complete_verified_backup_passes(self):
        ok, reasons = evaluate_backup_gate(valid_evidence(), "staging")
        self.assertTrue(ok, reasons)

    def test_02_backup_creation_failure_blocks(self):
        # a failed creation surfaces as absent evidence: the gate sees nothing
        ok, reasons = evaluate_backup_gate({}, "staging")
        self.assertFalse(ok)
        self.assertTrue(any("missing evidence" in r for r in reasons))

    def test_03_integrity_verification_failure_blocks(self):
        bad = valid_evidence()
        bad["verification_status"] = "INTEGRITY_FAILED"
        ok, reasons = evaluate_backup_gate(bad, "staging")
        self.assertFalse(ok)
        self.assertTrue(any("verification_status" in r for r in reasons))

    def test_04_missing_sha256_blocks(self):
        bad = valid_evidence()
        del bad["sha256"]
        ok, _ = evaluate_backup_gate(bad, "staging")
        self.assertFalse(ok)
        bad["sha256"] = ""
        ok, _ = evaluate_backup_gate(bad, "staging")
        self.assertFalse(ok)

    def test_05_missing_backup_id_blocks(self):
        bad = valid_evidence()
        del bad["backup_id"]
        ok, _ = evaluate_backup_gate(bad, "staging")
        self.assertFalse(ok)

    def test_06_upload_failure_blocks(self):
        # an upload failure removes the official-storage identifier and flips status
        bad = valid_evidence()
        bad["release_tag"] = ""
        bad["verification_status"] = "UPLOAD_FAILED"
        ok, reasons = evaluate_backup_gate(bad, "staging")
        self.assertFalse(ok)
        self.assertTrue(any("release_tag" in r or "verification_status" in r for r in reasons))

    def test_07_status_other_than_integrity_verified_blocks(self):
        for status in ("CREATED", "PARTIALLY_VERIFIED", "pending", ""):
            bad = valid_evidence()
            bad["verification_status"] = status
            ok, _ = evaluate_backup_gate(bad, "staging")
            self.assertFalse(ok, status)

    def test_extra_wrong_environment_blocks(self):
        ok, _ = evaluate_backup_gate(valid_evidence(env="production"), "staging")
        self.assertFalse(ok)

    def test_extra_tampered_sha_blocks(self):
        bad = valid_evidence()
        bad["sha256"] = "ZZ" * 32
        ok, _ = evaluate_backup_gate(bad, "staging")
        self.assertFalse(ok)

    def test_extra_cross_env_backup_id_blocks(self):
        bad = valid_evidence()
        bad["backup_id"] = "db-backup-production-20260909-deadbeef"
        ok, _ = evaluate_backup_gate(bad, "staging")
        self.assertFalse(ok)

    def test_cli_exact_invocation_used_by_workflows(self):
        evidence = {k.upper(): v for k, v in valid_evidence().items()}
        env = {**os.environ, "EXPECTED_ENV": "staging", **evidence}
        rc = subprocess.run(
            [sys.executable, os.path.join(MGMT, "backup_gate.py")],
            env=env, capture_output=True, text=True,
        ).returncode
        self.assertEqual(rc, 0)
        env["VERIFICATION_STATUS"] = "CREATED"
        rc = subprocess.run(
            [sys.executable, os.path.join(MGMT, "backup_gate.py")],
            env=env, capture_output=True, text=True,
        ).returncode
        self.assertEqual(rc, 1)


class DeployBlockedWithoutBackupTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc, cls.text = load("deploy-staging.yml")
        cls.jobs = cls.doc["jobs"]

    def test_08_deploy_requires_successful_verified_backup(self):
        # the backup job is unconditional (no opt-in/skip path)
        self.assertIsNone(self.jobs["db_backup"].get("if"))
        self.assertIn("velora-db-backup-staging.yml", self.jobs["db_backup"]["uses"])
        cond = self.jobs["deploy-staging"].get("if", "")
        self.assertIn("needs.db_backup.result == 'success'", cond)
        self.assertNotIn("db_backup.result == 'skipped'", cond)
        # and the deploy re-proves the machine evidence before any write
        gate_steps = [s for s in self.jobs["deploy-staging"]["steps"]
                      if "BACKUP GATE" in (s.get("name") or "")]
        self.assertEqual(len(gate_steps), 1)
        self.assertIn("backup_gate.py", gate_steps[0].get("run", ""))
        self.assertEqual(gate_steps[0].get("env", {}).get("EXPECTED_ENV"), "staging")
        # the gate step runs BEFORE the packaging step (writes happen only after)
        names = [s.get("name") or "" for s in self.jobs["deploy-staging"]["steps"]]
        self.assertLess(names.index([n for n in names if "BACKUP GATE" in n][0]),
                        next(i for i, n in enumerate(names) if "docroot" in n))

    def test_08b_no_bypass_flags_anywhere_in_deploy(self):
        for banned in ("SKIP_BACKUP", "IGNORE_BACKUP", "FORCE_DEPLOY"):
            self.assertNotIn(banned, self.text)


class MigrationBlockedWithoutBackupTests(unittest.TestCase):
    def assert_gated(self, filename, mode_expr):
        doc, text = load(filename)
        jobs = doc["jobs"]
        self.assertIn("backup", jobs)
        self.assertIn("velora-db-backup-staging.yml", jobs["backup"].get("uses", ""))
        # backup runs exactly for the mutating mode
        self.assertEqual(jobs["backup"].get("if"), mode_expr)
        # migrate depends on backup and may only run on backup success (apply)
        # or with backup intentionally skipped (read-only check mode)
        self.assertIn("backup", jobs["migrate"].get("needs"))
        cond = jobs["migrate"].get("if", "")
        self.assertIn("needs.backup.result == 'success'", cond)
        self.assertIn("needs.backup.result == 'skipped'", cond)
        # explicit in-job gate step before the probe (apply mode only)
        gate_steps = [s for s in jobs["migrate"]["steps"]
                      if "BACKUP GATE" in (s.get("name") or "")]
        self.assertEqual(len(gate_steps), 1, filename)
        self.assertIn("backup_gate.py", gate_steps[0].get("run", ""))
        return text

    def test_09_admin_migration_gated(self):
        self.assert_gated(
            "admin-migration-staging.yml",
            "${{ (inputs.mode || github.event.inputs.mode) == 'apply' }}",
        )

    def test_09b_trade_migration_gated(self):
        self.assert_gated(
            "trade-migration-staging.yml",
            "${{ (inputs.mode || github.event.inputs.mode) == 'apply' }}",
        )

    def test_09c_ai_migration_gated(self):
        self.assert_gated(
            "ai-migration-staging.yml",
            "${{ github.event.inputs.mode == 'apply' }}",
        )


class ManualBackupInvocationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc, cls.text = load("velora-db-backup-staging.yml")
        cls.jobs = cls.doc["jobs"]

    def test_10_manual_dispatch_exists_and_is_backup_only(self):
        on = self.doc.get(True) or self.doc.get("on")
        self.assertIn("workflow_dispatch", on)
        self.assertIn("workflow_call", on)
        self.assertEqual(list(self.jobs.keys()), ["backup"])

    def test_11_manual_invocation_cannot_migrate_or_deploy(self):
        for job in self.jobs.values():
            for step in job.get("steps", []) or []:
                name = (step.get("name") or step.get("uses") or "").lower()
                self.assertNotIn("migrat", name)
                self.assertNotIn("deploy", name)
        # and the run never touches migration SQL files
        self.assertNotIn("migrations/", self.text)


if __name__ == "__main__":
    unittest.main(verbosity=2)
