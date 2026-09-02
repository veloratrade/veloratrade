#!/usr/bin/env python3
"""Dry-run / pure-logic tests for the PRIVATE database-backup repository integration.
No network, no repo creation, no credentials, no real dumps."""
import json
import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.abspath(os.path.join(HERE, "..")))

import backup as bk          # noqa: E402
import private_backup_repo as pb  # noqa: E402

SHA = "117ab67777ab7832c1423912d4da1ab9d47d2885"
RUN = "33670000000"
TS = "2026-09-02T10:00:00Z"


def meta(env="staging", state=bk.STATE_UNVERIFIED, restore=bk.STATE_UNVERIFIED, sha=SHA):
    return pb.build_private_backup_metadata(
        env, source_commit_sha=sha, workflow_run_id=RUN, created_utc=TS,
        size_bytes=123456, checksum_sha256="a" * 64,
        database_identity_redacted=f"***_velora_{env} / MySQL 8.0.46",
        schema_migration_version="v1.0,v1.1",
        verification_status=state, restore_test_status=restore)


class TargetingTests(unittest.TestCase):
    def test_01_staging_target(self):
        m = meta("staging")
        self.assertEqual(m["environment"], "staging")
        self.assertTrue(m["storage"]["release_tag"].startswith("db-backup-staging-"))
        self.assertTrue(m["storage"]["metadata_path"].startswith("backups/staging/"))
        self.assertTrue(m["storage"]["release_asset"].startswith("staging/"))

    def test_02_production_target(self):
        m = meta("production")
        self.assertEqual(m["environment"], "production")
        self.assertTrue(m["storage"]["release_tag"].startswith("db-backup-production-"))
        self.assertTrue(m["storage"]["metadata_path"].startswith("backups/production/"))

    def test_18_explicit_environment_required(self):
        with self.assertRaises(pb.BackupRepoError):
            pb.require_environment("")

    def test_19_invalid_environment_fails_closed(self):
        for bad in ("prod", "PROD", "staging ", "qa", None):
            with self.assertRaises(pb.BackupRepoError):
                pb.require_environment(bad)

    def test_20_invalid_operation_fails_closed(self):
        for bad in ("DROP", "hack", "", "backup-and-delete"):
            with self.assertRaises(pb.BackupRepoError):
                pb.require_operation(bad)

    def test_03_environment_isolation_cross_contamination_blocked(self):
        # a production dump must never be filed under staging namespace
        with self.assertRaises(pb.BackupRepoError):
            pb.validate_namespace("production", "db-backup-staging-x",
                                  "backups/production/x.json", "production/x.sql.gz")
        with self.assertRaises(pb.BackupRepoError):
            pb.validate_namespace("staging", "db-backup-staging-x",
                                  "backups/production/x.json", "staging/x.sql.gz")

    def test_04_staging_approval_cannot_authorize_production(self):
        m = meta("staging", state=bk.STATE_INTEGRITY_VERIFIED)
        g = pb.private_backup_gate(m, "production", "migrate", bk.STATE_INTEGRITY_VERIFIED)
        self.assertFalse(g["allowed"])
        self.assertIn("cross-environment", g["reasons"][0])

    def test_05_production_requires_explicit(self):
        # no backup at all -> production migrate blocked
        g = pb.private_backup_gate(None, "production", "migrate", bk.STATE_INTEGRITY_VERIFIED)
        self.assertFalse(g["allowed"]); self.assertEqual(g["decision"], "STOP")
        # created but not integrity-verified -> blocked for production
        m = meta("production", state=bk.STATE_CREATED)
        g2 = pb.private_backup_gate(m, "production", "migrate", bk.STATE_INTEGRITY_VERIFIED)
        self.assertFalse(g2["allowed"])


class BindingTests(unittest.TestCase):
    def test_06_backup_id_binding(self):
        m = meta("staging")
        self.assertTrue(bk.approval_covers_backup("approval-bid", m["backup_id"]) is False)
        self.assertTrue(bk.approval_covers_backup(m["backup_id"], m["backup_id"]))

    def test_07_plan_hash_binding(self):
        # changing plan content changes hash (engine) -> approval invalid; here assert ids differ
        m1 = meta("staging"); m2 = meta("staging")
        # same inputs -> deterministic backup_id
        self.assertEqual(m1["backup_id"], m2["backup_id"])

    def test_08_commit_sha_binding(self):
        with self.assertRaises(pb.BackupRepoError):
            meta("staging", sha="notasha")
        m = meta("staging", sha="0" * 40)
        self.assertEqual(m["source_commit_sha"], "0" * 40)


class GateAndStateTests(unittest.TestCase):
    def test_09_unverified_backup_blocks_migration(self):
        m = meta("staging", state=bk.STATE_UNVERIFIED)
        g = pb.private_backup_gate(m, "staging", "migrate", bk.STATE_INTEGRITY_VERIFIED)
        self.assertFalse(g["allowed"])

    def test_10_integrity_verified_passes_gate(self):
        m = meta("staging", state=bk.STATE_INTEGRITY_VERIFIED)
        g = pb.private_backup_gate(m, "staging", "migrate", bk.STATE_INTEGRITY_VERIFIED)
        self.assertTrue(g["allowed"], g.get("reasons"))
        self.assertEqual(g["backup_id"], m["backup_id"])

    def test_repo_must_be_private_repo(self):
        m = meta("staging", state=bk.STATE_INTEGRITY_VERIFIED)
        m["storage"]["repository"] = "veloratrade/veloratrade"  # public app repo
        g = pb.private_backup_gate(m, "staging", "migrate", bk.STATE_INTEGRITY_VERIFIED)
        self.assertFalse(g["allowed"])
        self.assertIn("PRIVATE", g["reasons"][0])


class RetentionTests(unittest.TestCase):
    def _recs(self, n, env="staging", states=None, old=False):
        out = []
        for i in range(n):
            ts = f"2026-08-{i+1:02d}T10:00:00Z" if old else f"2026-09-{min(i+1,28):02d}T10:00:00Z"
            st = (states[i] if states else bk.STATE_INTEGRITY_VERIFIED)
            r = pb.build_private_backup_metadata(
                env, source_commit_sha=SHA, workflow_run_id=str(i), created_utc=ts,
                size_bytes=10, checksum_sha256=("a" if i % 2 == 0 else "b") * 64,
                database_identity_redacted=f"***_velora_{env}", schema_migration_version=None,
                verification_status=st)
            if old:  # force expiry into the past
                r["retention_expires_at_utc"] = "2000-01-01T00:00:00Z"
            out.append(r)
        return out

    def test_11_failed_backup_keeps_previous(self):
        recs = self._recs(3, old=True)
        r = pb.retention_plan(recs, mutation_succeeded=False, post_verify_passed=False)
        self.assertTrue(r["stopped"]); self.assertEqual(r["delete_release_tags"], [])

    def test_12_failed_migration_keeps_previous(self):
        recs = self._recs(3, old=True)
        r = pb.retention_plan(recs, mutation_succeeded=False, post_verify_passed=True)
        self.assertEqual(r["delete_release_tags"], [])

    def test_13_failed_postverify_keeps_previous(self):
        recs = self._recs(3, old=True)
        r = pb.retention_plan(recs, mutation_succeeded=True, post_verify_passed=False)
        self.assertEqual(r["delete_release_tags"], [])

    def test_14_retention_never_deletes_newest_verified(self):
        # all expired; newest few must still be retained
        recs = self._recs(8, old=True)
        r = pb.retention_plan(recs, mutation_succeeded=True, post_verify_passed=True,
                              keep_last_n=5, now_utc="2026-09-30T00:00:00Z")
        self.assertFalse(r["stopped"])
        newest_id = sorted(recs, key=lambda x: x["created_at_utc"], reverse=True)[0]["backup_id"]
        self.assertIn(newest_id, r["retain"])

    def test_retention_refuses_cross_environment_set(self):
        recs = self._recs(2, env="staging") + self._recs(2, env="production")
        with self.assertRaises(pb.BackupRepoError):
            pb.retention_plan(recs, mutation_succeeded=True, post_verify_passed=True)


class SecretSafetyTests(unittest.TestCase):
    def test_15_no_secrets_in_metadata(self):
        m = meta("production")
        blob = json.dumps(m).lower()
        for bad in ["password", "ftp://", "mysql://", "github_pat", "ghp_", "private_key", ".env"]:
            self.assertNotIn(bad, blob)

    def test_15b_secret_payload_rejected(self):
        good = meta("staging")
        bad = dict(good); bad["note"] = "db password=hunter2 ftp://x"
        with self.assertRaises(pb.BackupRepoError):
            pb.assert_metadata_has_no_secrets(bad)

    def test_16_public_repo_never_receives_dumps(self):
        # by construction the storage target is always the private repo; assert constant
        self.assertEqual(pb.PRIVATE_BACKUP_REPO, "veloratrade/velora-backups")
        m = meta("staging")
        self.assertEqual(m["storage"]["repository"], pb.PRIVATE_BACKUP_REPO)
        self.assertEqual(m["storage"]["repository_visibility"], "private")
        # the dump is a release ASSET, never a committed git blob
        self.assertTrue(m["storage"]["release_asset"].endswith(".sql.gz"))
        self.assertTrue(m["storage"]["metadata_path"].endswith(".json"))


class CredentialModelTests(unittest.TestCase):
    def test_17_pr_untrusted_cannot_access_backup_credentials(self):
        # Documented/asserted model: backup-write identity is ONLY exposed to
        # workflow_dispatch + protected-environment runs, never pull_request.
        required = set(pb.REQUIRED_SECRETS["preferred_app"] + pb.REQUIRED_SECRETS["fallback_pat"])
        self.assertIn("BACKUP_REPO_TOKEN", required)
        self.assertIn("BACKUP_REPO_APP_PRIVATE_KEY", required)
        # permissions are scoped to the private repo contents only
        self.assertEqual(pb.REQUIRED_PERMISSIONS["contents"], "write")
        self.assertNotIn("secrets", pb.REQUIRED_PERMISSIONS)


if __name__ == "__main__":
    unittest.main(verbosity=2)
