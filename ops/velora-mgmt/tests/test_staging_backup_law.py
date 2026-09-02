#!/usr/bin/env python3
"""
Structural/static enforcement tests for the STAGING backup & verification law
(the staging counterpart wired into the main-registered, dispatchable
deploy-staging.yml via reusable workflows).

Goal: prove the staging chain is hard-locked to staging (never production), fails
closed without BACKUP_REPO_TOKEN, never falls back to GITHUB_TOKEN, stores the dump
only as a private Release Asset, and that deploy-staging gates deploy on the backup
when enabled. Production files (velora-db-backup.yml / velora_prod_backup_upload.py)
must remain production-locked and untouched by these staging paths.
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


def strip_python_comments(src: str) -> str:
    """Remove docstrings and # comments so we don't flag the word in a sentence
    that documents a prohibition (e.g. 'no GITHUB_TOKEN fallback')."""
    import io, tokenize
    out = []
    try:
        import re
        toks = tokenize.generate_tokens(io.StringIO(src).readline)
        for tok in toks:
            if tok.type == tokenize.STRING and tok.string.startswith(('"""', "'''")):
                continue  # docstring
            if tok.type == tokenize.COMMENT:
                continue  # hash comment
            out.append(tok.string)
        # collapse ALL whitespace so dot-notation / f-string substring checks are robust
        return re.sub(r"\s+", "", "".join(out))
    except Exception:
        import re as _re
        return _re.sub(r"\s+", "", src)


class StagingBackupWorkflowTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc, cls.text = load("velora-db-backup-staging.yml")
        cls.jobs = cls.doc["jobs"]

    def test_01_reusable_call_only(self):
        on = self.doc.get("on", self.doc.get(True))
        self.assertIn("workflow_call", on)
        self.assertNotIn("pull_request", on)
        self.assertNotIn("push", on)

    def test_02_uses_staging_ftp_secrets_never_production(self):
        self.assertIn("secrets.STAGING_FTP_SERVER", self.text)
        self.assertIn("secrets.STAGING_FTP_USERNAME", self.text)
        self.assertIn("secrets.STAGING_FTP_PASSWORD", self.text)
        self.assertNotIn("secrets.FTP_SERVER }", self.text)
        self.assertNotIn("${{ secrets.FTP_SERVER }}", self.text)
        self.assertNotIn("${{ secrets.FTP_USERNAME }}", self.text)
        self.assertNotIn("${{ secrets.FTP_PASSWORD }}", self.text)

    def test_03_target_is_staging_url_and_docroot(self):
        self.assertIn("https://staging.veloratrade.ir", self.text)
        self.assertIn("public_html/staging.veloratrade.ir", self.text)
        self.assertIn("velora_private_staging", self.text)
        self.assertNotIn("public_html\"", self.text)          # no bare prod docroot
        self.assertNotIn("https://veloratrade.ir\"", self.text)

    def test_04_probe_rendered_staging_and_asserted(self):
        self.assertIn(".replace('__ENV__', 'staging')", self.text)
        self.assertIn("d.get('env') == 'staging'", self.text)
        self.assertIn("db-backup-staging-", self.text)
        # production id prefix must never be the staging assertion
        self.assertNotIn("backup_id'].startswith('db-backup-production-')", self.text)

    def test_05_backup_repo_token_required_fails_closed_no_github_fallback(self):
        self.assertIn("secrets.BACKUP_REPO_TOKEN", self.text)
        self.assertRegex(self.text, r"BACKUP_REPO_TOKEN is required but not currently available")
        # no GITHUB_TOKEN fallback for the private repo
        self.assertNotIn("secrets.GITHUB_TOKEN", self.text)
        # token masked before use
        self.assertIn("::add-mask::${BACKUP_REPO_TOKEN}", self.text)
        # token value never echoed (only mask + guidance)
        for line in self.text.splitlines():
            if "echo" in line and "BACKUP_REPO_TOKEN" in line:
                self.assertTrue("add-mask" in line or "::error::" in line
                                or "${#BACKUP_REPO_TOKEN}" in line,
                                f"token may be echoed: {line.strip()}")

    def test_06_local_integrity_and_byte_verify_present(self):
        self.assertIn("gzip -t dump.sql.gz", self.text)
        self.assertIn("SHA-256 mismatch", self.text)
        self.assertIn("size mismatch", self.text)
        self.assertIn("velora_staging_backup_upload.py", self.text)
        # remote temp dump removed after verified upload
        self.assertRegex(self.text, r"rm -f .*FTP_REL|remote temp dump removed")

    def test_07_outputs_bound_to_this_run(self):
        wfc = self.doc[True]["workflow_call"]
        for k in ("backup_id", "release_tag", "sha256", "source_commit_sha"):
            self.assertIn(k, wfc["outputs"])


class StagingVerifyWorkflowTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc, cls.text = load("velora-db-verify-staging.yml")
        cls.jobs = cls.doc["jobs"]

    def test_08_reusable_staging_locked(self):
        on = self.doc.get("on", self.doc.get(True))
        self.assertIn("workflow_call", on)
        self.assertIn("STAGING_FTP_SERVER", self.text)
        self.assertNotIn("${{ secrets.FTP_SERVER }}", self.text)
        self.assertIn("https://staging.veloratrade.ir", self.text)
        self.assertIn(".replace('__ENV__', 'staging')", self.text)
        self.assertIn("d.get('env') == 'staging'", self.text)

    def test_09_requires_postdeploy_db_ok(self):
        self.assertIn("POSTDEPLOY_DB_OK", self.text)
        self.assertIn("POSTDEPLOY_DB_FAILED", self.text)
        # read-only: no DB-mutating verbs shipped to the server
        self.assertNotIn("ALTER ", self.text)
        self.assertNotIn("DROP ", self.text)

    def test_10_reports_migration_schema_advisory(self):
        self.assertIn("migration_schema", self.text)
        self.assertIn("phase2a_applied", self.text)
        self.assertIn("metaapi_fills_exists", self.text)


class StagingUploaderScriptTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with open(os.path.join(MGMT, "velora_staging_backup_upload.py")) as f:
            cls.src = f.read()
        cls.code = strip_python_comments(cls.src)

    def test_11_staging_namespace_only(self):
        self.assertIn('ENV = "staging"', self.src)
        self.assertIn('db-backup-staging-', self.src)
        self.assertIn('backups/{ENV}/', self.src.replace("backups/%s/" % "{ENV}", "backups/{ENV}/"))
        self.assertIn('f"backups/{ENV}/{backup_id}.json"', self.code)
        # executable code must never reference production namespace
        self.assertNotIn("production", self.code)
        self.assertNotIn("db-backup-production-", self.code)

    def test_12_fails_closed_without_token_no_github_fallback(self):
        self.assertIn('os.environ.get("BACKUP_REPO_TOKEN"', self.code)
        self.assertIn("BACKUP_REPO_TOKEN is required", self.src)
        self.assertNotIn("GITHUB_TOKEN", self.code)

    def test_13_byte_reverify_and_no_overwrite(self):
        self.assertIn("already exists (no overwrite)", self.src)
        self.assertIn("byte verification FAILED", self.src)
        self.assertIn("assert_metadata_has_no_secrets", self.code)
        self.assertIn("validate_namespace", self.code)
        self.assertIn("gzip.GzipFile", self.code)


class DeployStagingWiringTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc, cls.text = load("deploy-staging.yml")
        cls.jobs = cls.doc["jobs"]

    def test_14_backup_job_uses_staging_reusable_before_deploy(self):
        self.assertIn("db_backup", self.jobs)
        self.assertIn("velora-db-backup-staging.yml", self.jobs["db_backup"].get("uses", ""))
        self.assertEqual(self.jobs["db_backup"].get("secrets"), "inherit")
        needs = self.jobs["deploy-staging"].get("needs")
        needs = [needs] if isinstance(needs, str) else needs
        self.assertIn("db_backup", needs)

    def test_15_failed_backup_skips_deploy(self):
        # deploy runs only on success/skipped (allowlist) — a failed/cancelled
        # backup is therefore never matched, so the deploy is skipped (fail closed).
        cond = self.jobs["deploy-staging"].get("if", "")
        self.assertIn("db_backup.result", cond)
        self.assertIn("'success'", cond)
        self.assertIn("'skipped'", cond)
        self.assertNotIn("'failure'", cond)
        self.assertNotIn("'cancelled'", cond)

    def test_16_postdeploy_verify_after_deploy_when_enabled(self):
        self.assertIn("db_verify", self.jobs)
        self.assertIn("velora-db-verify-staging.yml", self.jobs["db_verify"].get("uses", ""))
        needs = self.jobs["db_verify"].get("needs")
        self.assertIn("deploy-staging", needs)
        self.assertIn("with_postdeploy_db_verify", self.jobs["db_verify"].get("if", ""))

    def test_17_default_off_so_existing_deploys_unaffected(self):
        inp = self.doc[True]["workflow_dispatch"]["inputs"]
        self.assertIn("with_db_backup_gate", inp)
        self.assertIn("with_postdeploy_db_verify", inp)
        self.assertEqual(inp["with_db_backup_gate"].get("default"), False)
        self.assertEqual(inp["with_postdeploy_db_verify"].get("default"), False)


class StagingMigrationWiringTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc, cls.text = load("deploy-staging.yml")
        cls.jobs = cls.doc["jobs"]
        cls.mdoc, cls.mtext = load("trade-migration-staging.yml")
        probe = os.path.join(MGMT, "probe", "trade_migration_probe.php.tmpl")
        with open(probe) as f:
            cls.probe = f.read()

    def test_20_migration_reusable_jobs_present_and_after_backup(self):
        self.assertIn("db_migration_check", self.jobs)
        self.assertIn("db_migration_apply", self.jobs)
        chk = self.jobs["db_migration_check"]
        ap = self.jobs["db_migration_apply"]
        self.assertIn("trade-migration-staging.yml", chk.get("uses", ""))
        self.assertIn("trade-migration-staging.yml", ap.get("uses", ""))
        self.assertIn("db_backup", str(chk.get("needs")))
        self.assertIn("db_migration_check", str(ap.get("needs")))
        # apply must pass the confirmation phrase
        self.assertIn("APPLY-TRADE-MIGRATION", self.mtext)

    def test_21_migration_is_staging_locked_and_additive(self):
        self.assertIn("STAGING_FTP_SERVER", self.mtext)
        self.assertIn("https://staging.veloratrade.ir", self.mtext)
        self.assertIn("'staging'", self.probe)
        # additive DDL lives in the one-use probe
        for needle in ("occurred_open_at_utc", "metaapi_fills",
                       "idx_trades_occurred_open", "timezone_source",
                       "ADD COLUMN", "CREATE TABLE IF NOT EXISTS"):
            self.assertIn(needle, self.probe)
        # the forward migration probe never runs destructive data verbs
        for bad in ("DROP TABLE", "TRUNCATE", "DELETE FROM", "DROP DATABASE"):
            self.assertNotIn(bad, self.probe)
        # probe is check/apply-gated and self-deleting/token-gated
        self.assertIn("__MODE__", self.probe)
        self.assertIn("unlink(__FILE__)", self.probe)
        self.assertIn("hash_equals", self.probe)

    def test_22_migration_check_is_read_only_and_apply_gated(self):
        # apply requires the confirmation guard both in workflow and probe mode gate
        self.assertRegex(self.mtext, r'APPLY-TRADE-MIGRATION')
        # deploy proceeds only on success/skipped (an allowlist), never on failure
        dep_cond = self.jobs["deploy-staging"].get("if", "")
        for need in ("db_backup", "db_migration_check", "db_migration_apply"):
            self.assertIn(f"needs.{need}.result", dep_cond)
        self.assertIn("'skipped'", dep_cond)
        self.assertIn("'success'", dep_cond)
        self.assertNotIn("'failure'", dep_cond)
        self.assertNotIn("'cancelled'", dep_cond)


class ProductionPathUntouchedTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        _, cls.prod_bk = load("velora-db-backup.yml")
        _, cls.prod_deploy = load("deploy.yml")

    def test_18_production_backup_still_locked_to_production(self):
        self.assertIn("'production'", self.prod_bk)
        self.assertIn("options: [production]", self.prod_bk)
        self.assertIn("${{ secrets.FTP_SERVER }}", self.prod_bk)
        # production reusable workflow must not use staging secrets
        self.assertNotIn("STAGING_FTP_SERVER", self.prod_bk)

    def test_19_production_deploy_gate_intact(self):
        self.assertIn("prod_backup_gate", self.prod_deploy)
        self.assertIn("prod_db_backup", self.prod_deploy)
        self.assertIn("velora-db-backup.yml", self.prod_deploy)


if __name__ == "__main__":
    unittest.main(verbosity=2)
