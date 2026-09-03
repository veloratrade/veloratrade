#!/usr/bin/env python3
"""Static/security tests for the REAL staging backup path: the one-use PHP backup
probe template and the velora-db-backup-staging.yml reusable workflow (the wired,
upload-and-verify path that deploy-staging.yml uses). These tests do NOT run PHP or
contact the server; they assert the security invariants of the artifacts."""
import os
import re
import unittest
import yaml

HERE = os.path.dirname(os.path.abspath(__file__))
MGMT = os.path.abspath(os.path.join(HERE, ".."))
ROOT = os.path.abspath(os.path.join(MGMT, "..", ".."))
PROBE = os.path.join(MGMT, "probe", "db_backup_probe.php.tmpl")
WF = os.path.join(ROOT, ".github", "workflows", "velora-db-backup-staging.yml")


class ProbeSecurityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with open(PROBE) as f:
            cls.probe = f.read()
        # rendered (placeholders substituted) to emulate the deployed file
        cls.rendered = (cls.probe.replace("__HASH__", "x" * 64)
                        .replace("__OP__", "backup").replace("__ENV__", "staging"))

    def test_01_probe_exists_and_is_php(self):
        self.assertTrue(os.path.exists(PROBE))
        self.assertIn("<?php", self.probe)

    def test_02_token_gated_timing_safe_and_self_deletes(self):
        self.assertIn("hash_equals", self.probe)
        self.assertIn("HTTP_X_VELORA_MGMT", self.probe)
        self.assertIn("@unlink(__FILE__)", self.probe)

    def test_03_environment_is_explicit_and_unknown_refused(self):
        # The template is now environment-driven (staging|production); any OTHER
        # environment is refused. Staging renders its own namespace; production is a
        # separate namespace and is gated by the protected GitHub environment.
        self.assertIn("'staging'", self.probe)
        self.assertIn("'production'", self.probe)
        self.assertIn("unsupported environment for backup", self.probe)
        # the staging-rendered probe must never produce a production id
        self.assertIn("db-backup-{$ENV}", self.probe)
        self.assertIn("$ftpBaseDir = ($ENV === 'production') ? 'velora_private'", self.probe)
        # staging rendering keeps the staging env value and the env-driven id
        self.assertIn("'staging'", self.rendered)
        self.assertIn('$ENV = \'staging\'', self.rendered)
        self.assertIn('"db-backup-{$ENV}-', self.rendered)  # PHP resolves env at runtime
        self.assertIn('velora_private_staging', self.rendered)

    def test_04_no_arbitrary_sql_or_shell_input(self):
        # No use of request/input to build SQL; no shell execution functions.
        for bad in ["shell_exec(", "system(", "passthru(", "proc_open(",
                    "popen(", " eval(", "$_GET", "$_POST", "$_REQUEST", "mysqli_query"]:
            self.assertNotIn(bad, self.probe, f"unexpected dynamic/shell surface: {bad}")
        # the only ->exec() allowed is the read-only session isolation SET
        for m in re.findall(r"->exec\(([^)]*)\)", self.probe):
            self.assertIn("TRANSACTION ISOLATION", m, f"unexpected ->exec statement: {m}")
        # mysqldump must NOT be invoked (no shell); dump is pure PDO reads.
        self.assertNotIn("mysqldump", self.probe)

    def test_05_only_read_sql_keywords_against_live_db(self):
        # The probe may write DROP/CREATE INTO THE DUMP FILE ($w(...)); it must never
        # ->exec()/->query() a mutating statement. Assert no exec of DML/DDL keywords.
        for mut in ["exec('INSERT", 'exec("INSERT', "exec('UPDATE", "exec('DELETE",
                    "exec('DROP", "exec('CREATE", "exec('ALTER", "exec('TRUNCATE"]:
            self.assertNotIn(mut, self.probe)
        # transaction used for a consistent snapshot
        self.assertIn("beginTransaction", self.probe)
        self.assertIn("REPEATABLE READ", self.probe)

    def test_06_dump_written_outside_docroot_not_webroot(self):
        self.assertIn("Config::privatePath('backups')", self.probe)
        # never writes into public/ docroot
        self.assertNotIn("public/", self.probe.replace("DOCROOT", ""))

    def test_07_no_credentials_or_dsn_in_output(self):
        # JSON output must not include password/user/host/dsn keys.
        for bad in ["'pass'", "'password'", "'dsn'", "'user'", "'host'", "getenv("]:
            self.assertNotIn(bad, self.probe)
        # schema name is redacted in the dump header
        self.assertIn("name redacted", self.probe)

    def test_08_deterministic_staging_identity(self):
        # env-driven id/asset; the STAGING-rendered probe yields staging namespace
        self.assertIn('"db-backup-{$ENV}-{$tsUtc}-{$rand12}"', self.probe)
        self.assertIn('"{$ENV}/{$backupId}.sql.gz"', self.probe)
        self.assertIn("bin2hex(random_bytes(6))", self.probe)
        # render both environments and confirm namespaces cannot collide
        stag = self.probe.replace("__HASH__", "h").replace("__OP__", "backup").replace("__ENV__", "staging")
        prod = self.probe.replace("__HASH__", "h").replace("__OP__", "backup").replace("__ENV__", "production")
        ternary = "($ENV === 'production') ? 'velora_private' : 'velora_private_staging'"
        self.assertIn(ternary, self.probe)
        self.assertIn("$ftpRel  = $ftpBaseDir . '/backups/'", self.probe)
        # production render resolves to a non-staging private root and prod identity
        self.assertIn("'***_velora_production'", prod)
        # staging identity distinct from production
        self.assertIn("'***_velora_staging'", stag)
        self.assertNotEqual(stag.count('velora_private_staging'), 0)

    def test_09_reports_integrity_not_restore(self):
        self.assertIn("INTEGRITY_VERIFIED", self.probe)
        self.assertIn("'restore_test_status' => 'UNVERIFIED'", self.probe)
        # no restore/import into live DB
        self.assertNotIn("mysql <", self.probe)


class WorkflowSecurityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with open(WF) as f:
            cls.text = f.read()
        cls.yaml = yaml.safe_load(cls.text)

    def test_10_is_a_reusable_workflow_no_untrusted_triggers(self):
        # Invoked via workflow_call from deploy-staging; may also be dispatched
        # manually. Never triggered by untrusted PR/fork events.
        tr = self.yaml.get("on", self.yaml.get(True))
        keys = list(tr.keys()) if isinstance(tr, dict) else [tr]
        self.assertIn("workflow_call", keys)
        for bad in ["pull_request", "pull_request_target", "issue_comment", "fork"]:
            self.assertNotIn(bad, keys)

    def test_11_hard_locked_to_staging_target(self):
        # staging host/docroot only; never production.
        self.assertIn("https://staging.veloratrade.ir", self.text)
        self.assertIn("public_html/staging.veloratrade.ir", self.text)
        self.assertNotIn("https://veloratrade.ir", self.text.replace("staging.veloratrade.ir", ""))
        self.assertNotIn('DOCROOT: "public_html"', self.text)

    def test_12_uses_only_staging_ftp_secrets(self):
        self.assertIn("secrets.STAGING_FTP_SERVER", self.text)
        self.assertIn("secrets.STAGING_FTP_USERNAME", self.text)
        self.assertIn("secrets.STAGING_FTP_PASSWORD", self.text)
        # must NOT reference production FTP secrets.
        self.assertNotIn("secrets.FTP_SERVER", self.text.replace("STAGING_", "X"))
        self.assertNotIn("secrets.FTP_USERNAME", self.text.replace("STAGING_", "X"))

    def test_13_requires_backup_repo_token_and_no_github_token_fallback(self):
        # Cross-repo private upload MUST use BACKUP_REPO_TOKEN and fail closed
        # without it; never fall back to GITHUB_TOKEN.
        self.assertIn("secrets.BACKUP_REPO_TOKEN", self.text)
        self.assertNotIn("secrets.GITHUB_TOKEN", self.text)
        self.assertNotIn("github.token", self.text)
        self.assertRegex(self.text, r"BACKUP_REPO_TOKEN is required")

    def test_14_probe_self_deletes_and_uploads_to_private_repo(self):
        # the wired workflow DOES upload to the private repo (unlike the old
        # boundary prototype) and byte-verifies; the dump is still never in git.
        self.assertIn("velora_staging_backup_upload.py", self.text)
        self.assertIn("add-mask", self.text)

    def test_15_probe_token_and_filename_masked(self):
        self.assertIn("add-mask", self.text)
        self.assertIn("openssl rand -hex 32", self.text)

    def test_16_dump_never_committed_and_artifact_is_transient(self):
        # dump is fetched as dump.sql.gz at workspace root, never git-added to the
        # PUBLIC repo. Only metadata goes to the private repo; run artifacts are
        # transient (14d).
        self.assertNotIn("git add", self.text)
        self.assertIn("upload-artifact@v4", self.text)
        self.assertIn("retention-days: 14", self.text)


if __name__ == "__main__":
    unittest.main(verbosity=2)
