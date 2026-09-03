#!/usr/bin/env python3
"""Behavioral tests for the strengthened production binding gate and the
production backup-state policy.

These tests import the REAL gate/policy code and inject a fake backup-repo
client (no network, no credentials, no real repository). They prove:

  * the production gate independently RE-READS the private repository rather
    than trusting upstream workflow outputs;
  * cross-environment / stale / missing / unverified backups all BLOCK;
  * only a production-scoped, INTEGRITY_VERIFIED, commit-matched backup passes;
  * production DATABASE SCHEMA migration requires RESTORE_VERIFIED while an
    ordinary code deploy keeps the INTEGRITY_VERIFIED bar.
"""
import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.abspath(os.path.join(HERE, "..")))

import backup as bk                      # noqa: E402
import backup_repo_client as bc          # noqa: E402
import velora_prod_backup_gate as gate   # noqa: E402

SHA = "a" * 64
COMMIT = "b" * 40


class FakeClient:
    """In-memory stand-in for backup_repo_client.Client."""
    def __init__(self, metadata=None, releases=None, raise_on=None):
        self._meta = metadata or {}        # backup_id -> metadata dict
        self._releases = releases or {}    # tag -> release dict
        self.raise_on = raise_on

    def get_backup_metadata(self, environment, backup_id):
        if self.raise_on == "meta":
            raise bc.BackupRepoClientError("boom")
        if backup_id not in self._meta:
            raise bc.BackupRepoClientError(f"metadata not found for {backup_id}")
        return self._meta[backup_id]

    def get_release_by_tag(self, environment, tag):
        if self.raise_on == "release":
            raise bc.BackupRepoClientError("boom")
        if tag not in self._releases:
            raise bc.BackupRepoClientError(f"release not found for {tag}")
        return self._releases[tag]


def good_metadata(**over):
    bid = over.pop("backup_id", "db-backup-production-20260101000000-abcdef000000")
    meta = {
        "backup_id": bid,
        "environment": "production",
        "sha256": SHA,
        "verification_status": bk.STATE_INTEGRITY_VERIFIED,
        "source_commit_sha": COMMIT,
        "storage": {
            "repository": "veloratrade/velora-backups",
            "release_tag": bid,
        },
    }
    meta.update(over)
    return meta


GOOD = good_metadata()


class VerifyBoundBackupTests(unittest.TestCase):
    def _call(self, client, **kw):
        params = dict(backup_id=GOOD["backup_id"], release_tag=GOOD["backup_id"],
                      sha256=SHA, expected_commit=COMMIT)
        params.update(kw)
        return gate.verify_bound_backup(client, **params)

    def assert_blocks(self, client, **kw):
        """A blocking outcome is either die()->SystemExit (content/identity failure)
        or a BackupRepoClientError (repo unreachable/missing) — both fail the deploy
        (main() converts the latter to die())."""
        with self.assertRaises((SystemExit, bc.BackupRepoClientError)):
            self._call(client, **kw)

    def _client(self, meta=None, releases=None, raise_on=None):
        meta = meta if meta is not None else {GOOD["backup_id"]: GOOD}
        releases = releases if releases is not None else {
            GOOD["backup_id"]: {"id": 1, "assets": [{"name": f"{GOOD['backup_id']}.sql.gz"}]}}
        return FakeClient(metadata=meta, releases=releases, raise_on=raise_on)

    def test_passes_for_matching_verified_production_backup(self):
        out = self._call(self._client())
        self.assertTrue(out["verified_against_private_repo"])
        self.assertEqual(out["state"], bk.STATE_INTEGRITY_VERIFIED)

    def test_blocks_when_release_missing_in_private_repo(self):
        self.assert_blocks(self._client(releases={}))

    def test_blocks_when_release_has_no_sql_asset(self):
        bad = {GOOD["backup_id"]: {"id": 1, "assets": []}}
        with self.assertRaises(SystemExit):
            self._call(self._client(releases=bad))

    def test_blocks_when_metadata_missing(self):
        self.assert_blocks(self._client(meta={}))

    def test_blocks_cross_environment_staging_metadata(self):
        sm = good_metadata(environment="staging",
                           backup_id="db-backup-staging-20260101000000-aaaaaaaaaaaa")
        self.assert_blocks(FakeClient(metadata={GOOD["backup_id"]: sm}))

    def test_blocks_unverified_backup(self):
        um = good_metadata(verification_status=bk.STATE_CREATED)
        with self.assertRaises(SystemExit):
            self._call(self._client(meta={GOOD["backup_id"]: um}))

    def test_blocks_stale_commit_mismatch(self):
        with self.assertRaises(SystemExit):
            self._call(self._client(), expected_commit="c" * 40)

    def test_blocks_when_metadata_sha_differs(self):
        with self.assertRaises(SystemExit):
            self._call(self._client(), sha256="f" * 64)

    def test_blocks_when_repo_lookup_raises(self):
        self.assert_blocks(self._client(raise_on="release"))

    def test_blocks_staging_namespace_backup_id(self):
        with self.assertRaises(SystemExit):
            gate.verify_bound_backup(
                self._client(),
                backup_id="db-backup-staging-20260101000000-aaaaaaaaaaaa",
                release_tag="db-backup-staging-20260101000000-aaaaaaaaaaaa",
                sha256=SHA, expected_commit=COMMIT)


class RequiredStatePolicyTests(unittest.TestCase):
    def test_production_schema_migration_requires_restore_verified(self):
        self.assertEqual(
            bk.required_state_for_mutation("production", "database", "migrate"),
            bk.STATE_RESTORE_VERIFIED)

    def test_production_code_deploy_keeps_integrity_verified(self):
        self.assertEqual(
            bk.required_state_for_mutation("production", "database", "deploy"),
            bk.STATE_INTEGRITY_VERIFIED)
        self.assertEqual(
            bk.required_state_for_mutation("production", "file", "deploy"),
            bk.STATE_INTEGRITY_VERIFIED)

    def test_staging_migration_uses_lighter_ladder(self):
        self.assertEqual(
            bk.required_state_for_mutation("staging", "database", "migrate"),
            bk.STATE_INTEGRITY_VERIFIED)

    def test_integrity_verified_backup_cannot_satisfy_prod_migration(self):
        # The whole point: an INTEGRITY_VERIFIED prod DB backup must NOT be enough
        # for a schema migration.
        self.assertTrue(bk.state_at_least(bk.STATE_INTEGRITY_VERIFIED,
                                          bk.required_state_for_mutation("production", "database", "deploy")))
        self.assertFalse(bk.state_at_least(bk.STATE_INTEGRITY_VERIFIED,
                                           bk.required_state_for_mutation("production", "database", "migrate")))
        self.assertTrue(bk.state_at_least(bk.STATE_RESTORE_VERIFIED,
                                          bk.required_state_for_mutation("production", "database", "migrate")))


class TokenFallbackTests(unittest.TestCase):
    def test_default_token_ignores_github_token(self):
        # GITHUB_TOKEN must never be accepted as a fallback for the private repo.
        prev = dict(os.environ)
        try:
            os.environ.pop("BACKUP_REPO_TOKEN", None)
            os.environ["GITHUB_TOKEN"] = "tok_should_be_ignored_xxxxxxxx"
            self.assertIsNone(bc.default_token())
        finally:
            os.environ.clear()
            os.environ.update(prev)


if __name__ == "__main__":
    unittest.main(verbosity=2)
