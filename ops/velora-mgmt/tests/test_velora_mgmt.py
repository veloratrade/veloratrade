#!/usr/bin/env python3
"""
Read-only / dry-run tests for the VELORA environment management engine.
These tests use captured metadata fixtures and synthetic cases ONLY — they never
touch a database, the network, staging, or production. They assert the safety
properties: explicit targeting, no cross-environment authorization, plan-hash
binding, production backup gating, forbidden-SQL rejection, idempotent plan, and
schema-comparison correctness against the real Gate B staging snapshot.
"""
import copy
import json
import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", "..", ".."))
sys.path.insert(0, os.path.abspath(os.path.join(HERE, "..")))

import velora_mgmt as vm  # noqa: E402

FIX = os.path.join(HERE, "fixtures", "staging-gateb.json")
COMMIT = "117ab67777ab7832c1423912d4da1ab9d47d2885"


def load_staging():
    with open(FIX, encoding="utf-8") as fh:
        return vm.schema_from_probe(json.load(fh))


class EnvironmentIsolationTests(unittest.TestCase):
    def test_explicit_environment_required(self):
        with self.assertRaises(vm.VeloraMgmtError):
            vm.require_environment("prod")          # not an exact allowed value
        for env in vm.ENVIRONMENTS:
            self.assertEqual(vm.require_environment(env)["ftp_docroot"],
                             vm.ENV_CONFIG[env]["ftp_docroot"])

    def test_credentials_never_mix(self):
        s = vm.ENV_CONFIG["staging"]["ftp_secrets"]
        p = vm.ENV_CONFIG["production"]["ftp_secrets"]
        self.assertEqual(s, ("STAGING_FTP_SERVER", "STAGING_FTP_USERNAME", "STAGING_FTP_PASSWORD"))
        self.assertEqual(p, ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD"))
        self.assertFalse(set(s) & set(p), "staging and production secret names must not overlap")
        self.assertEqual(vm.ENV_CONFIG["staging"]["ftp_docroot"], "public_html/staging.veloratrade.ir/")
        self.assertEqual(vm.ENV_CONFIG["production"]["ftp_docroot"], "public_html/")

    def test_staging_approval_cannot_authorize_production(self):
        actual = load_staging()
        plan = vm.build_plan("production", actual, COMMIT, repo_root=ROOT)
        # approval minted for STAGING must be rejected against a PRODUCTION plan
        bad = vm.Approval(environment="staging", operation="migrate", commit_sha=COMMIT,
                          migrations=plan["migrations"], plan_hash=plan["plan_hash"])
        decision = vm.validate_approval(plan, bad, backup_verified=True,
                                        target_environment="production")
        self.assertFalse(decision["allowed"])
        self.assertTrue(any("staging" in r and "production" in r for r in decision["reasons"]))

    def test_production_requires_backup_even_with_approval(self):
        actual = load_staging()
        plan = vm.build_plan("production", actual, COMMIT, repo_root=ROOT)
        good = vm.Approval(environment="production", operation="migrate", commit_sha=COMMIT,
                           migrations=plan["migrations"], plan_hash=plan["plan_hash"])
        no_backup = vm.validate_approval(plan, good, backup_verified=False,
                                         target_environment="production")
        self.assertFalse(no_backup["allowed"])
        self.assertTrue(any("backup" in r.lower() for r in no_backup["reasons"]))
        with_backup = vm.validate_approval(plan, good, backup_verified=True,
                                           target_environment="production")
        # backup present + perfect approval -> allowed by the gate logic
        self.assertTrue(with_backup["allowed"], with_backup["reasons"])

    def test_missing_approval_blocks(self):
        actual = load_staging()
        plan = vm.build_plan("staging", actual, COMMIT, repo_root=ROOT)
        d = vm.validate_approval(plan, None, backup_verified=False, target_environment="staging")
        self.assertFalse(d["allowed"])
        self.assertIn("STOP", d["decision"])


class PlanHashBindingTests(unittest.TestCase):
    def test_any_change_invalidates_approval(self):
        actual = load_staging()
        plan = vm.build_plan("staging", actual, COMMIT, repo_root=ROOT)
        appr = vm.Approval(environment="staging", operation="migrate", commit_sha=COMMIT,
                           migrations=list(plan["migrations"]), plan_hash=plan["plan_hash"])
        # same -> valid (staging does not force backup)
        self.assertTrue(vm.validate_approval(plan, appr, False, "staging")["allowed"])
        # changed commit -> mismatch
        appr2 = vm.Approval("staging", "migrate", "0" * 40, plan["migrations"], plan["plan_hash"])
        self.assertFalse(vm.validate_approval(plan, appr2, False, "staging")["allowed"])
        # changed plan hash -> mismatch
        appr3 = vm.Approval("staging", "migrate", COMMIT, plan["migrations"], "deadbeef")
        self.assertFalse(vm.validate_approval(plan, appr3, False, "staging")["allowed"])
        # changed migration set -> mismatch
        appr4 = vm.Approval("staging", "migrate", COMMIT, ["v1.0"], plan["plan_hash"])
        self.assertFalse(vm.validate_approval(plan, appr4, False, "staging")["allowed"])

    def test_plan_hash_is_deterministic(self):
        actual = load_staging()
        p1 = vm.build_plan("staging", actual, COMMIT, repo_root=ROOT)
        p2 = vm.build_plan("staging", actual, COMMIT, repo_root=ROOT)
        self.assertEqual(p1["plan_hash"], p2["plan_hash"])
        self.assertEqual(len(p1["plan_hash"]), 64)


class StagingSnapshotTests(unittest.TestCase):
    """Validate against the REAL Gate B staging metadata (MySQL 8.0.46, 28 tables)."""
    @classmethod
    def setUpClass(cls):
        cls.actual = load_staging()
        cls.plan = vm.build_plan("staging", cls.actual, COMMIT, repo_root=ROOT)

    def test_engine_normalized(self):
        self.assertTrue(self.actual.engine_version.startswith("8.0.46"))
        self.assertEqual(self.actual.charset_database, "utf8mb4")
        self.assertEqual(self.actual.collation_database, "utf8mb4_unicode_ci")
        self.assertEqual(self.actual.transaction_isolation, "REPEATABLE-READ")

    def test_all_tables_innodb(self):
        base = [t for t in self.actual.tables.values() if t.exists and t.table_type == "BASE TABLE"]
        self.assertEqual(len(base), 28)
        self.assertTrue(all((t.engine or "").lower() == "innodb" for t in base))

    def test_pending_migrations_are_v10_v11_in_order(self):
        self.assertEqual(self.plan["migrations"], ["v1.0", "v1.1"])

    def test_metaapi_fills_missing_detected(self):
        self.assertIn("metaapi_fills", self.plan["detected_differences"]["missing_tables"])
        self.assertFalse(self.actual.tables["metaapi_fills"].exists)

    def test_v10_canonical_columns_flagged_missing(self):
        td = self.plan["detected_differences"]["tables"]["trades"]
        for c in ["occurred_open_at_utc", "occurred_close_at_utc", "time_status",
                  "source_timezone", "source_timezone_source", "source_calendar",
                  "raw_open_text", "raw_close_text"]:
            self.assertIn(c, td["missing_columns"])
        idx_names = [i["name"] for i in td["missing_indexes"]]
        self.assertIn("idx_trades_occurred_open", idx_names)
        self.assertIn("idx_trades_time_status", idx_names)
        ta = self.plan["detected_differences"]["tables"]["trading_accounts"]
        self.assertIn("timezone", ta["missing_columns"])
        self.assertIn("timezone_source", ta["missing_columns"])

    def test_no_type_mismatches_on_captured_tables(self):
        # baseline types must line up (bigint unsigned, varchar(64), enum, datetime...)
        tr = self.plan["detected_differences"]["tables"]["trades"]
        self.assertEqual(tr["type_mismatches"], [], tr["type_mismatches"])
        self.assertEqual(tr["nullability_mismatches"], [])
        ta = self.plan["detected_differences"]["tables"]["trading_accounts"]
        self.assertEqual(ta["type_mismatches"], [], ta["type_mismatches"])

    def test_no_forbidden_sql_in_repo_migrations(self):
        self.assertEqual(self.plan["forbidden_sql_hits"], [])

    def test_plan_defaults_blocked_and_awaiting_approval(self):
        self.assertEqual(self.plan["execution"], "BLOCKED")
        self.assertEqual(self.plan["approval"]["state"], "WAITING_FOR_EXPLICIT_APPROVAL")
        self.assertEqual(self.plan["backup_requirement"]["state"], "UNVERIFIED")

    def test_verify_fails_while_pending(self):
        # mirror the verify command logic
        expected = vm.expected_schema(ROOT)
        pending = vm.pending_migrations(self.actual, expected)
        self.assertEqual(pending, ["v1.0", "v1.1"])


class PostMigrationSyntheticTests(unittest.TestCase):
    """Simulate a fully-migrated schema by applying EXPECTED into an ACTUAL copy and
    confirm compare/verify flips to PASS and pending becomes empty (dry-run only)."""
    def test_after_applying_expected_pending_is_empty(self):
        actual = load_staging()
        expected = vm.expected_schema(ROOT)
        # simulate: add metaapi_fills + v1.0 columns/indexes to actual (in-memory only)
        for tname in ["metaapi_fills", "trades", "trading_accounts"]:
            e = expected.tables[tname]
            if tname == "metaapi_fills":
                actual.tables[tname] = copy.deepcopy(e)
                actual.tables[tname].exists = True
            else:
                have = {c.name for c in actual.tables[tname].columns}
                for c in e.columns:
                    if c.name not in have:
                        actual.tables[tname].columns.append(copy.deepcopy(c))
                have_idx = {i.name for i in actual.tables[tname].indexes}
                for i in e.indexes:
                    if i.name not in have_idx:
                        actual.tables[tname].indexes.append(copy.deepcopy(i))
        pending = vm.pending_migrations(actual, expected)
        self.assertEqual(pending, [])
        diff = vm.compare(actual, expected)
        self.assertNotIn("metaapi_fills", diff["missing_tables"])
        tr = diff["tables"]["trades"]
        self.assertEqual(tr["missing_columns"], [])
        self.assertEqual([i["name"] for i in tr["missing_indexes"]], [])


class RedactionTests(unittest.TestCase):
    def test_db_name_redacted(self):
        self.assertEqual(vm.redact_db_name("cpaneluser_velora_staging"), "***_staging")
        self.assertEqual(vm.redact_db_name(None), "<unknown>")

    def test_audit_record_contains_no_secret_fields(self):
        rec = vm.audit_record("staging", "inspect", COMMIT, "run-123", None,
                              "NONE", "UNVERIFIED", "SUCCESS")
        blob = json.dumps(rec).lower()
        for forbidden in ["password", "secret", "token_value", "ftp://"]:
            self.assertNotIn(forbidden, blob)


if __name__ == "__main__":
    unittest.main(verbosity=2)
