#!/usr/bin/env python3
"""Read-only / dry-run tests for the backup state machine. No real FTP/DB/mutating
operations — purely in-memory fixtures covering the required lifecycle cases."""
import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.abspath(os.path.join(HERE, "..")))

import backup as bk  # noqa: E402

SHA = "e3909bae99d110bf9cd0596680a918ddb5fc9bb5"
RUN = "999"


def mk(rec_id, env="staging", btype="database", state=bk.STATE_UNVERIFIED,
       created="2026-09-02T00:00:00Z", expires=None, sha=SHA):
    return bk.BackupRecord(
        environment=env, backup_type=btype, backup_id=rec_id, source_commit_sha=sha,
        created_at=created, location=f"artifact:{rec_id}", size_bytes=1000,
        checksum_sha256="abc", expected_checksum_sha256="abc",
        verification_status=state, restore_test_status=bk.STATE_UNVERIFIED,
        retention_expires_at=expires, workflow_run_id=RUN)


def gate(env, manifest):
    return bk.mutation_backup_gate(manifest, env, manifest.backup_type)


class MutationGateTests(unittest.TestCase):
    def test_01_no_backup_blocks(self):
        m = bk.database_backup_manifest("staging")
        d = gate("staging", m)
        self.assertFalse(d.allowed)
        self.assertEqual(d.decision, "STOP")
        self.assertIn("NO VERIFIED DATABASE BACKUP MECHANISM", d.reasons[0])

    def test_02_created_but_unverified_blocks_db(self):
        # staging database requires INTEGRITY_VERIFIED
        m = bk.database_backup_manifest("staging", [mk("b1", state=bk.STATE_CREATED)])
        d = gate("staging", m)
        self.assertFalse(d.allowed)
        self.assertEqual(d.backup_state, bk.STATE_CREATED)

    def test_03_verified_backup_still_requires_approval(self):
        m = bk.database_backup_manifest("staging", [mk("b1", state=bk.STATE_INTEGRITY_VERIFIED)])
        d = gate("staging", m)
        self.assertTrue(d.allowed, d.reasons)          # backup gate passes...
        # ...but approval is a separate gate
        lc = bk.lifecycle_status("migrate", d, approval_valid=False, backup_id_match=False,
                                 mutation_succeeded=False, post_verify_passed=False)
        self.assertEqual(lc["status"], "STOP")
        self.assertEqual(lc["failed_at"], "approval")

    def test_04_staging_approval_cannot_authorize_production(self):
        # production file backup present + integrity verified; but approval env mismatch
        m = bk.BackupManifest("production", "file",
                              [mk("f1", env="production", btype="file",
                                  state=bk.STATE_INTEGRITY_VERIFIED)])
        d = gate("production", m)
        self.assertTrue(d.allowed)
        # simulate approval minted for staging used against production => env mismatch
        approval_valid = False  # env mismatch makes the approval invalid
        lc = bk.lifecycle_status("deploy", d, approval_valid=approval_valid,
                                 backup_id_match=False, mutation_succeeded=False,
                                 post_verify_passed=False)
        self.assertEqual(lc["status"], "STOP")

    def test_14_production_locked_by_default(self):
        # no production database backup => locked
        m = bk.database_backup_manifest("production")
        d = gate("production", m)
        self.assertFalse(d.allowed)
        # production file mutation requires INTEGRITY_VERIFIED (stronger than staging's CREATED)
        mf = bk.BackupManifest("production", "file",
                               [mk("f1", env="production", btype="file", state=bk.STATE_CREATED)])
        df = gate("production", mf)
        self.assertFalse(df.allowed)
        self.assertEqual(df.backup_state, bk.STATE_CREATED)


class ApprovalBindingTests(unittest.TestCase):
    def _plan_like(self, d):
        return d

    def test_07_changing_backup_identity_invalidates_approval(self):
        self.assertFalse(bk.approval_covers_backup("bk-old", "bk-new"))
        self.assertFalse(bk.approval_covers_backup(None, "bk-new"))
        self.assertTrue(bk.approval_covers_backup("bk-same", "bk-same"))


class RetentionSafetyTests(unittest.TestCase):
    def _manifest(self, states, env="staging", btype="database", expired=False):
        recs = []
        for i, st in enumerate(states):
            exp = "2000-01-01T00:00:00Z" if expired else None  # far-past => expired if expired True
            recs.append(mk(f"b{i}", env=env, btype=btype, state=st,
                           created=f"2026-09-{i+1:02d}T00:00:00Z", expires=exp))
        return bk.BackupManifest(env, btype, recs)

    def test_08_failed_backup_keeps_previous(self):
        m = self._manifest([bk.STATE_INTEGRITY_VERIFIED, bk.STATE_CREATED], expired=True)
        r = bk.plan_retention_cleanup(m, mutation_succeeded=False, post_verify_passed=False)
        self.assertTrue(r["stopped"])
        self.assertEqual(r["cleanup"], [])           # nothing deleted
        self.assertEqual(len(r["retained"]), 2)

    def test_09_failed_verification_keeps_previous(self):
        m = self._manifest([bk.STATE_INTEGRITY_VERIFIED], expired=True)
        r = bk.plan_retention_cleanup(m, mutation_succeeded=True, post_verify_passed=False)
        self.assertTrue(r["stopped"])
        self.assertEqual(r["cleanup"], [])

    def test_10_failed_mutation_no_cleanup(self):
        m = self._manifest([bk.STATE_INTEGRITY_VERIFIED, bk.STATE_INTEGRITY_VERIFIED], expired=True)
        r = bk.plan_retention_cleanup(m, mutation_succeeded=False, post_verify_passed=False)
        self.assertEqual(r["cleanup"], [])

    def test_11_success_may_cleanup_expired(self):
        # many verified backups, some expired; newest few must always be retained
        states = [bk.STATE_INTEGRITY_VERIFIED] * 8
        m = self._manifest(states, expired=True)   # all marked expired
        r = bk.plan_retention_cleanup(m, mutation_succeeded=True, post_verify_passed=True,
                                      keep_last_n=5)
        self.assertFalse(r["stopped"])
        # newest 5 (highest index = latest created) are protected from expiry
        newest5 = ["b3", "b4", "b5", "b6", "b7"]
        self.assertTrue(all(x in r["retained"] for x in newest5), r["retained"])
        self.assertNotIn("b7", r["cleanup"])  # newest never cleaned

    def test_12_never_remove_newest_verified(self):
        m = self._manifest([bk.STATE_INTEGRITY_VERIFIED] + [bk.STATE_CREATED]*6, expired=True)
        r = bk.plan_retention_cleanup(m, mutation_succeeded=True, post_verify_passed=True,
                                      keep_last_n=1)
        # b0 is the only INTEGRITY_VERIFIED record; it AND the newest present record
        # are both protected (never delete newest backup even if only CREATED).
        self.assertIn("b0", r["retained"])     # integrity-verified anchor retained
        self.assertNotIn("b0", r["cleanup"])
        self.assertIn("b6", r["retained"])     # newest present record also retained

    def test_13_multiple_historical_coexist(self):
        m = self._manifest([bk.STATE_INTEGRITY_VERIFIED]*3, expired=False)
        r = bk.plan_retention_cleanup(m, mutation_succeeded=True, post_verify_passed=True)
        self.assertEqual(set(r["retained"]), {"b0", "b1", "b2"})
        self.assertEqual(r["cleanup"], [])


class VerificationStateTests(unittest.TestCase):
    def test_state_ladder(self):
        self.assertTrue(bk.state_at_least(bk.STATE_RESTORE_VERIFIED, bk.STATE_INTEGRITY_VERIFIED))
        self.assertFalse(bk.state_at_least(bk.STATE_CREATED, bk.STATE_INTEGRITY_VERIFIED))

    def test_evaluate_unverified_when_missing(self):
        rec = mk("x")
        rec.verification_status = bk.STATE_RESTORE_VERIFIED  # start high; bad evidence must drop it
        rec = bk.evaluate_backup(rec, {"exists": False})
        self.assertEqual(rec.verification_status, bk.STATE_UNVERIFIED)

    def test_evaluate_integrity_requires_checksum(self):
        rec = mk("x"); rec.size_bytes = 10; rec.expected_checksum_sha256 = "h"
        rec.checksum_sha256 = "h"
        rec = bk.evaluate_backup(rec, {"exists": True, "non_zero_size": True,
                                       "checksum_matches": True, "identity_ok": True})
        self.assertEqual(rec.verification_status, bk.STATE_INTEGRITY_VERIFIED)
        # mismatched checksum stays at CREATED
        rec2 = mk("y"); rec2.size_bytes = 10; rec2.expected_checksum_sha256 = "h"
        rec2.checksum_sha256 = "different"
        rec2 = bk.evaluate_backup(rec2, {"exists": True, "non_zero_size": True,
                                         "checksum_matches": False, "identity_ok": True})
        self.assertEqual(rec2.verification_status, bk.STATE_CREATED)

    def test_restore_verified_only_with_restore_test(self):
        rec = mk("x"); rec.size_bytes = 10; rec.expected_checksum_sha256 = "h"; rec.checksum_sha256 = "h"
        rec = bk.evaluate_backup(rec, {"exists": True, "non_zero_size": True,
                                       "checksum_matches": True, "identity_ok": True,
                                       "restore_test_ok": True})
        self.assertEqual(rec.verification_status, bk.STATE_RESTORE_VERIFIED)
        self.assertEqual(rec.restore_test_status, bk.STATE_RESTORE_VERIFIED)


class FileBackupDiscoveryTests(unittest.TestCase):
    def test_15_real_production_file_artifacts_discovered(self):
        artifacts = [
            {"name": "pre-deploy-backup-e3909bae99d110bf9cd0596680a918ddb5fc9bb5",
             "size_in_bytes": 24495541, "created_at": "2026-09-01T17:35:34Z",
             "expires_at": "2026-09-15T17:17:09Z", "expired": False,
             "workflow_run": {"id": 123}},
            {"name": "velora-mgmt-staging-plan-999", "size_in_bytes": 5000,
             "created_at": "2026-09-02T00:00:00Z", "expires_at": None, "expired": False},
            {"name": "pre-deploy-backup-1f2bcc76e1d35655939328f282ff598e6eb219c7",
             "size_in_bytes": 24495514, "created_at": "2026-09-01T15:09:58Z",
             "expires_at": "2026-09-15T14:47:31Z", "expired": False},
        ]
        m = bk.manifest_from_github_artifacts("production", artifacts)
        self.assertEqual(len(m.records), 2)               # only the backup pattern matches
        # honestly classified: CREATED (non-empty guard) but not integrity/restore verified
        for r in m.records:
            self.assertEqual(r.verification_status, bk.STATE_CREATED)
            self.assertEqual(r.restore_test_status, bk.STATE_UNVERIFIED)
            self.assertNotIn("database", r.backup_type)

    def test_staging_file_manifest_empty(self):
        m = bk.manifest_from_github_artifacts("staging", [
            {"name": "pre-deploy-backup-" + "a" * 40, "size_in_bytes": 1}])
        self.assertEqual(m.records, [])

    def test_database_manifest_empty_by_default(self):
        for env in ("staging", "production"):
            self.assertEqual(bk.database_backup_manifest(env).records, [])


if __name__ == "__main__":
    unittest.main(verbosity=2)
