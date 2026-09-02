#!/usr/bin/env python3
"""
Structural / static enforcement tests for the PERMANENT PRODUCTION BACKUP & DEPLOYMENT LAW.

These tests inspect the ACTUAL workflow files and deploy gate scripts (not just helper
libraries) and prove that the production mutation path structurally cannot run unless:
  * a FRESH production DATABASE backup is created, uploaded to the private repo, and
    byte-verified;
  * that verified backup_id is bound to THIS deployment commit;
  * the approval/gate passes;
  * post-deploy DATABASE verification runs;
and that there is no skip/no-backup/force bypass and no fallback to GITHUB_TOKEN.
"""
import os
import re
import unittest

import yaml

HERE = os.path.dirname(os.path.abspath(__file__))
MGMT = os.path.abspath(os.path.join(HERE, ".."))
ROOT = os.path.abspath(os.path.join(MGMT, "..", ".."))
WF = os.path.join(ROOT, ".github", "workflows")


def load(name):
    with open(os.path.join(WF, name)) as f:
        text = f.read()
    return yaml.safe_load(text), text


def jobs_of(doc):
    return doc.get("jobs", {})


class DeployWorkflowEnforcementTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc, cls.text = load("deploy.yml")
        cls.jobs = jobs_of(cls.doc)

    def test_01_mutating_deploy_job_depends_on_backup_gate(self):
        deploy = self.jobs.get("deploy")
        self.assertIsNotNone(deploy, "deploy job must exist")
        needs = deploy.get("needs", [])
        needs = [needs] if isinstance(needs, str) else needs
        self.assertIn("prod_backup_gate", needs,
                      "deploy must depend on the verified-backup gate")
        # gate in turn depends on the reusable DB backup
        gate_needs = self.jobs["prod_backup_gate"].get("needs", [])
        gate_needs = [gate_needs] if isinstance(gate_needs, str) else gate_needs
        self.assertIn("prod_db_backup", gate_needs)

    def test_02_prod_db_backup_is_reusable_private_repo_workflow(self):
        db = self.jobs.get("prod_db_backup")
        self.assertIsNotNone(db, "prod_db_backup reusable call must exist")
        self.assertIn("velora-db-backup.yml", db.get("uses", ""))
        # only for REAL deploys (dry-run performs no mutation)
        self.assertIn("if", db)
        self.assertIn("dry_run", db["if"])

    def test_03_post_deploy_db_verification_is_required(self):
        verify = self.jobs.get("prod_db_verify")
        self.assertIsNotNone(verify, "post-deploy DB verify job must exist")
        needs = verify.get("needs", [])
        needs = [needs] if isinstance(needs, str) else needs
        self.assertIn("deploy", needs)
        self.assertIn("prod_backup_gate", needs)
        self.assertIn("velora-db-verify.yml", verify.get("uses", ""))
        # the web healthcheck must not be reportable as success before DB verify
        hc = self.jobs.get("healthcheck")
        hc_needs = hc.get("needs", [])
        hc_needs = [hc_needs] if isinstance(hc_needs, str) else hc_needs
        self.assertIn("prod_db_verify", hc_needs,
                      "web health must depend on post-deploy DB verification")

    def test_04_retention_only_after_full_success_and_needs_verify(self):
        ret = self.jobs.get("retention")
        self.assertIsNotNone(ret, "retention job must exist")
        needs = ret.get("needs", [])
        needs = [needs] if isinstance(needs, str) else needs
        for n in ("deploy", "prod_db_verify", "healthcheck"):
            self.assertIn(n, needs, f"retention must need {n}")
        # retention step must pass success flags and not delete on failure
        self.assertIn("--mutation-succeeded", self.text)
        self.assertIn("--post-verify-passed", self.text)

    def test_05_file_backup_remains_mandatory_in_deploy(self):
        # the docroot file backup step + non-empty failure must still be present
        self.assertRegex(self.text, r"بکاپ docroot|pre-deploy-backup")
        self.assertIn("بکاپ خالی است", self.text)

    def test_06_no_bypass_flags(self):
        for bad in ["skip_backup", "no_backup", "force_backup", "skip-backup",
                    "SKIP_BACKUP", "ignore_backup"]:
            self.assertNotIn(bad, self.text, f"bypass flag present: {bad}")

    def test_07_deploy_is_workflow_dispatch_only(self):
        trig = self.doc.get("on", self.doc.get(True))
        keys = list(trig.keys()) if isinstance(trig, dict) else [trig]
        self.assertIn("workflow_dispatch", keys)
        for forbidden in ("pull_request", "pull_request_target", "push"):
            self.assertNotIn(forbidden, keys)

    def test_08_backup_id_propagated_to_report(self):
        # deployment report references the bound backup id from the gate
        self.assertIn("deployment_report", self.jobs)
        self.assertRegex(self.text, r"needs\.prod_backup_gate\.outputs\.backup_id|backup_id")

    def test_09_deploy_job_uses_protected_environment(self):
        self.assertEqual(self.jobs["deploy"].get("environment"), "production")


class ProdDbBackupWorkflowTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc, cls.text = load("velora-db-backup.yml")
        cls.jobs = jobs_of(cls.doc)

    def test_10_production_namespace_only(self):
        # the dispatch input choice is production-locked
        wd = self.doc[True]["workflow_dispatch"]
        opts = wd["inputs"]["environment"]["options"]
        self.assertEqual(opts, ["production"])

    def test_11_requires_backup_repo_token_and_fails_closed(self):
        self.assertIn("secrets.BACKUP_REPO_TOKEN", self.text)
        self.assertRegex(self.text, r"BACKUP_REPO_TOKEN is required but not currently available")
        # no fallback to GITHUB_TOKEN for the private repo
        self.assertNotIn("${{ secrets.GITHUB_TOKEN }}", self.text.replace("${{ github.token }}", ""))
        self.assertNotIn("GITHUB_TOKEN}}", self.text.replace("${{ secrets.GITHUB_TOKEN }}", ""))

    def test_12_no_db_dump_committed_and_uses_release_asset_path(self):
        # upload script stores release assets; never git add of dump
        self.assertNotIn("git add dump", self.text)
        self.assertIn("velora_prod_backup_upload.py", self.text)
        self.assertIn("byte", self.text.lower())

    def test_13_removes_temp_server_dump_after_verified_upload(self):
        self.assertRegex(self.text, r"rm -f .*\$FTP_REL|remote temp dump removed")

    def test_14_outputs_include_backup_id_sha_commit(self):
        wfc = self.doc[True].get("workflow_call", {})
        outs = wfc.get("outputs", {})
        for k in ("backup_id", "release_tag", "sha256", "source_commit_sha"):
            self.assertIn(k, outs)

    def test_15_uses_production_ftp_secrets_not_staging(self):
        self.assertIn("secrets.FTP_SERVER", self.text)
        self.assertNotIn("secrets.STAGING_FTP_SERVER", self.text)
        self.assertIn("veloratrade.ir", self.text)
        self.assertNotIn("staging.veloratrade.ir", self.text)


class UploadAndGateScriptTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        base = os.path.join(MGMT)
        with open(os.path.join(base, "velora_prod_backup_upload.py")) as f:
            cls.upload = f.read()
        with open(os.path.join(base, "velora_prod_backup_gate.py")) as f:
            cls.gate = f.read()
        with open(os.path.join(base, "velora_prod_retention.py")) as f:
            cls.ret = f.read()

    def test_16_upload_refuses_non_production_and_unverified(self):
        self.assertIn('!= "production"', self.upload)
        self.assertIn("INTEGRITY_VERIFIED", self.upload)
        self.assertIn("byte", self.upload.lower())
        # never prints token; uses env header only
        self.assertNotIn("print(", self.upload.replace("print(f\"::error::", "").replace("print(json.dumps", "").replace("print(", "@@print(") )

    def test_17_gate_rejects_staging_and_stale_commit(self):
        self.assertIn("db-backup-production-", self.gate)
        self.assertIn("stale or unrelated backup", self.gate)
        self.assertIn("no fresh verified Production backup", self.gate)

    def test_18_gate_validates_sha_and_commit_identity(self):
        self.assertIn("SHA256_RE", self.gate)
        self.assertIn("HEX40", self.gate)

    def test_19_retention_uses_engine_and_failure_is_non_destructive(self):
        self.assertIn("retention_plan", self.ret)
        self.assertIn("db-backup-production-", self.ret)
        # without flags success the engine suppresses deletion; script defaults to plan
        self.assertIn("--mutation-succeeded", self.ret)
        self.assertIn("Plan-only", self.ret)

    def test_20_no_credentials_or_dump_in_git(self):
        for src in (self.upload, self.gate, self.ret):
            for bad in ["github_pat_", "ghp_", "BEGIN PRIVATE KEY", "FTP_PASSWORD="]:
                self.assertNotIn(bad, src)


if __name__ == "__main__":
    unittest.main(verbosity=2)
