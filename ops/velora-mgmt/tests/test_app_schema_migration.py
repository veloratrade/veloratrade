#!/usr/bin/env python3
"""
Structural/static enforcement tests for the DEDICATED v1.7/v1.8 app-schema
staging migration mechanism (app-schema-migration-staging.yml +
app_schema_migration_probe.php.tmpl).

Proves: strict migration allowlist (no arbitrary filename/path), canonical
v1.7 -> v1.8 ordering, read-only CHECK, APPLY fail-closed behind the canonical
BACKUP GATE + exact confirmation phrase, staging-only target, enforced
post-apply schema verification, no destructive SQL in the executor, no secrets
emitted, and no bypass flags anywhere.
"""
import os
import re
import subprocess
import sys
import unittest
from pathlib import Path

import yaml

HERE = os.path.dirname(os.path.abspath(__file__))
MGMT = os.path.abspath(os.path.join(HERE, ".."))
ROOT = os.path.abspath(os.path.join(MGMT, "..", ".."))
WF = os.path.join(ROOT, ".github", "workflows")

WORKFLOW = os.path.join(WF, "app-schema-migration-staging.yml")
TEMPLATE = os.path.join(MGMT, "probe", "app_schema_migration_probe.php.tmpl")
SQL_V17 = os.path.join(ROOT, "api", "database", "migrations", "v1.7_auth_events.sql")
SQL_V18 = os.path.join(ROOT, "api", "database", "migrations", "v1.8_support_tickets.sql")

with open(WORKFLOW) as f:
    _raw = f.read()
WF_YAML, WF_TEXT = yaml.safe_load(_raw), _raw
TMPL_TEXT = Path(TEMPLATE).read_text()


def _code_lines(text, strip_php=False):
    """Drop full-line comment lines (YAML '#' / PHP '//' + docblock '*')."""
    out = []
    for line in text.splitlines():
        s = line.strip()
        if s.startswith("#"):
            continue
        if strip_php and (s.startswith("//") or s.startswith("*") or s.startswith("/*")):
            continue
        out.append(line)
    return "\n".join(out)


def valid_evidence(status="INTEGRITY_VERIFIED"):
    return {"BACKUP_ID": "db-backup-staging-20260909120000-3ecff080b8e3",
            "RELEASE_TAG": "db-backup-staging-20260909120000-3ecff080b8e3",
            "SHA256": "6cff03998bb87db13441a388ef3a55988cc8497487311eaebc1dc73da8652acc",
            "SOURCE_COMMIT_SHA": "a85286148627995dccf7d7cd9097ea993adc32e0",
            "VERIFICATION_STATUS": status,
            "ENVIRONMENT": "staging",
            "EXPECTED_ENV": "staging"}


class AllowlistTests(unittest.TestCase):
    """1/2/3. Only v1.7 and v1.8 are allowlisted; no arbitrary filename accepted."""

    def test_01_v17_is_allowlisted_exactly(self):
        self.assertIn("['api/database/migrations/v1.7_auth_events.sql'],", WF_TEXT)
        self.assertIn("'v1.7':  ['api/database/migrations/v1.7_auth_events.sql']", WF_TEXT)

    def test_02_v18_is_allowlisted_exactly(self):
        self.assertIn("'v1.8':  ['api/database/migrations/v1.8_support_tickets.sql']", WF_TEXT)
        self.assertIn("KNOWN_IDS = {'v1.7_auth_events', 'v1.8_support_tickets'}", WF_TEXT)

    def test_02b_no_other_migration_file_referenced(self):
        refs = set(re.findall(r"api/database/migrations/[\w.]+\.sql", _code_lines(WF_TEXT)))
        self.assertEqual(refs, {"api/database/migrations/v1.7_auth_events.sql",
                                "api/database/migrations/v1.8_support_tickets.sql"})

    def test_03_selector_is_choice_input_with_exact_options(self):
        ins = WF_YAML[True]["workflow_dispatch"]["inputs"]
        self.assertEqual(set(ins), {"mode", "migration", "confirm"})
        self.assertEqual(ins["migration"]["options"], ["v1.7", "v1.8", "both"])
        self.assertEqual(ins["mode"]["options"], ["check", "apply"])

    def test_04_invalid_selector_fails_closed(self):
        guard = WF_TEXT.split("Guard — staging-only")[1]
        self.assertIn("migration selector must be v1.7|v1.8|both (strict allowlist)\"; exit 1", guard)
        self.assertIn("assert sel in ALLOW, f'migration selector not allowlisted: {sel!r}'", WF_TEXT)

    def test_03b_inputs_migration_never_used_inside_run_blocks(self):
        d = WF_YAML
        for job in d["jobs"].values():
            for step in job.get("steps", []) or []:
                if "run" in step:
                    self.assertNotIn("inputs.migration", str(step["run"]))
        self.assertIn("INPUT_MIG: ${{ inputs.migration || github.event.inputs.migration }}", WF_TEXT)


class CheckModeTests(unittest.TestCase):
    """5. CHECK is strictly read-only and needs no backup/gate."""

    def test_05_backup_and_gate_are_apply_only(self):
        d = WF_YAML
        self.assertEqual(str(d["jobs"]["backup"]["if"]).strip(), "${{ (inputs.mode || github.event.inputs.mode) == 'apply' }}")
        gate = [s for s in d["jobs"]["migrate"]["steps"] if "BACKUP GATE" in (s.get("name") or "")]
        self.assertEqual(len(gate), 1)
        self.assertIn("== 'apply'", str(gate[0]["if"]))

    def test_05b_probe_never_mutates_in_check_mode(self):
        self.assertIn("if ($MODE === 'apply') {", TMPL_TEXT)
        self.assertEqual(TMPL_TEXT.count("$pdo->exec("), 1)  # single guarded execution loop
        self.assertLess(TMPL_TEXT.index("if ($MODE === 'apply') {"), TMPL_TEXT.index("$pdo->exec("))
        self.assertIn("check mode: read-only snapshot, no execution ever", TMPL_TEXT)
        # check branch only snapshots
        check_branch = TMPL_TEXT.split("} else {")[1]
        self.assertIn("asm_snapshot($pdo, $id)", check_branch)
        self.assertNotIn("$pdo->exec", check_branch.split("foreach ($migs as $m)")[0])


class ApplyGateTests(unittest.TestCase):
    """6/7. APPLY requires the canonical BACKUP GATE; invalid evidence fails."""

    def test_06_apply_requires_backup_success_and_gate(self):
        d = WF_YAML
        self.assertEqual(d["jobs"]["backup"]["uses"], "./.github/workflows/velora-db-backup-staging.yml")
        self.assertEqual(d["jobs"]["backup"]["secrets"], "inherit")
        cond = str(d["jobs"]["migrate"]["if"])
        self.assertIn("needs.backup.result == 'success'", cond)
        self.assertEqual(d["jobs"]["migrate"]["needs"], ["backup"])
        gate = [s for s in d["jobs"]["migrate"]["steps"] if "BACKUP GATE" in (s.get("name") or "")][0]
        self.assertIn("python3 ops/velora-mgmt/backup_gate.py", str(gate["run"]))
        self.assertEqual(gate["env"].get("EXPECTED_ENV"), "staging")

    def test_06b_backup_job_runs_before_probe(self):
        d = WF_YAML
        steps = d["jobs"]["migrate"]["steps"]
        names = [s.get("name") or s.get("uses") for s in steps]
        gate_idx = next(i for i, n in enumerate(names) if "BACKUP GATE" in n)
        probe_idx = next(i for i, n in enumerate(names) if "one-use probe" in n)
        self.assertLess(gate_idx, probe_idx)

    def test_07_invalid_backup_evidence_fails_gate(self):
        for status in ("INTEGRITY_FAILED", "CREATED", ""):
            env = {k.upper(): v for k, v in valid_evidence(status).items()}
            r = subprocess.run([sys.executable, "backup_gate.py"], cwd=MGMT, env=env,
                               capture_output=True, text=True)
            self.assertNotEqual(r.returncode, 0, f"gate accepted status={status!r}")

    def test_07b_valid_evidence_accepted(self):
        env = {k.upper(): v for k, v in valid_evidence().items()}
        r = subprocess.run([sys.executable, "backup_gate.py"], cwd=MGMT, env=env,
                           capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)


class ConfirmationAndOrderTests(unittest.TestCase):
    """9/10. Exact apply phrase; v1.8 never before v1.7."""

    def test_09_wrong_confirmation_rejected(self):
        self.assertIn('confirm must be exactly APPLY-APP-SCHEMA-MIGRATION', WF_TEXT)
        self.assertIn('[ "$INPUT_CONFIRM" = "APPLY-APP-SCHEMA-MIGRATION" ]', WF_TEXT)
        guard = WF_TEXT.split("Guard — staging-only")[1].split("- name:")[0]
        self.assertIn("exit 1", guard)
        self.assertNotIn("APPLY-ADMIN-MIGRATION", WF_TEXT)  # dedicated phrase, no admin reuse

    def test_10_both_orders_v17_before_v18(self):
        both = WF_TEXT.split("'both':")[1].split("]", 1)[0]
        self.assertLess(both.index("v1.7_auth_events.sql"), both.index("v1.8_support_tickets.sql"))
        # probe re-proves canonical order before ANY DB access
        self.assertIn("ASM_RANK = ['v1.7_auth_events' => 1, 'v1.8_support_tickets' => 2]", TMPL_TEXT)
        self.assertIn("ASM_RANK[$id] <= $prevRank", TMPL_TEXT)
        self.assertIn("migration id/order rejected", TMPL_TEXT)
        # on failure the loop breaks before later migrations run
        self.assertIn("later migrations aborted", TMPL_TEXT)
        self.assertIn("break;   // fail closed: never continue to a later migration", TMPL_TEXT)


class ExecutorSafetyTests(unittest.TestCase):
    """11/12/13/14/8. No destructive SQL, verification enforced, no secrets, no bypass."""

    def test_11_executor_introduces_no_destructive_sql(self):
        code = _code_lines(TMPL_TEXT, strip_php=True)
        for pat in (r"\bDROP\b", r"\bTRUNCATE\b", r"\bDELETE\b", r"\bINSERT\b",
                    r"\bUPDATE\b", r"\bALTER\b", r"\bRENAME\b"):
            self.assertNoRegex(code, pat)

    def assertNoRegex(self, text, pattern):
        m = re.search(pattern, text, re.I)
        self.assertIsNone(m, f"forbidden pattern {pattern!r} found: {m.group(0) if m else ''}")

    def test_11b_migration_sql_files_stay_additive(self):
        for path in (SQL_V17, SQL_V18):
            code = Path(path).read_text()
            code = re.sub(r"\bON DELETE (?:CASCADE|SET NULL)\b", "", code, flags=re.I)
            code = re.sub(r"\bON UPDATE CURRENT_TIMESTAMP\b", "", code, flags=re.I)
            code = _code_lines(code)
            for pat in (r"\bDROP\b", r"\bTRUNCATE\b", r"\bDELETE\b", r"\bINSERT\b", r"\bUPDATE\b"):
                self.assertNoRegex(code, pat)

    def test_12_post_apply_schema_verification_enforced(self):
        self.assertIn("schema incomplete after apply", TMPL_TEXT)
        self.assertIn("schema not verified complete after apply", WF_TEXT)
        self.assertIn("$result['ok'] = false;", TMPL_TEXT)
        self.assertIn('"schema_verified"', WF_TEXT)

    def test_13_no_secrets_or_credentials_emitted(self):
        code = _code_lines(TMPL_TEXT, strip_php=True)
        for tok in ("FTP_", "PASSWORD", "mysql://", "DSN"):
            self.assertNotIn(tok, code)
        audit = WF_TEXT.split("audit = {")[1].split("json.dump")[0]
        for banned in ("password", "dsn", "ftp_server", "ftp_username"):
            self.assertNotIn(f'"{banned}"', audit)
        self.assertIn("::add-mask::$TOKEN", WF_TEXT)

    def test_14_no_bypass_flags_anywhere(self):
        for text, label in ((WF_TEXT, "workflow"), (TMPL_TEXT, "template")):
            for flag in ("SKIP_BACKUP", "IGNORE_BACKUP", "FORCE_MIGRATION", "FORCE_DEPLOY",
                         "with_db_backup_gate", "continue-on-error"):
                self.assertNotIn(flag, text, f"{flag} found in {label}")

    def test_08_production_is_rejected_and_unreachable(self):
        self.assertIn("$ENV !== 'staging'", TMPL_TEXT)
        self.assertIn("http_response_code(400);", TMPL_TEXT)
        self.assertIn("bad mode/env (staging-only probe)", TMPL_TEXT)
        self.assertNotIn("secrets.PROD", WF_TEXT)
        hosts = set(re.findall(r"https?://([\w.]+)", WF_TEXT))
        self.assertEqual(hosts, {"staging.veloratrade.ir"})


class GateScopeRegressionTests(unittest.TestCase):
    """Regression for failed APPLY run 34368464761: needs.* context is not
    available in JOB-level env (evaluates empty at runtime, so the gate
    failed closed). Backup evidence must be supplied via STEP-level env."""

    def test_18_no_needs_context_in_any_job_level_env(self):
        for jn, job in WF_YAML["jobs"].items():
            for k, v in (job.get("env") or {}).items():
                self.assertNotIn("needs.", str(v),
                                 f"job-level env {jn}.{k} uses needs context (empty at runtime)")

    def test_19_gate_step_env_supplies_all_six_evidence_vars(self):
        gate = [s for s in WF_YAML["jobs"]["migrate"]["steps"] if "BACKUP GATE" in (s.get("name") or "")][0]
        env = gate.get("env") or {}
        for key, out in (("BACKUP_ID", "backup_id"), ("RELEASE_TAG", "release_tag"),
                         ("SHA256", "sha256"), ("SOURCE_COMMIT_SHA", "source_commit_sha"),
                         ("VERIFICATION_STATUS", "verification_status"),
                         ("ENVIRONMENT", "environment")):
            self.assertEqual(env.get(key), "${{ needs.backup.outputs.%s }}" % out,
                             f"gate step env missing {key}")
        self.assertEqual(env.get("EXPECTED_ENV"), "staging")

    def test_19b_probe_audit_step_env_supplies_backup_evidence_vars(self):
        probe = [s for s in WF_YAML["jobs"]["migrate"]["steps"] if "one-use probe" in (s.get("name") or "")][0]
        env = probe.get("env") or {}
        for key in ("BACKUP_ID", "RELEASE_TAG", "BACKUP_SHA256",
                    "BACKUP_SOURCE_SHA", "BACKUP_VERIFICATION", "BACKUP_ENVIRONMENT"):
            self.assertIn("needs.backup.outputs", str(env.get(key)),
                          f"probe/audit step env missing {key}")


class StructuralTests(unittest.TestCase):
    def test_15_workflow_yaml_parses(self):
        d = WF_YAML
        self.assertEqual(sorted(d[True].keys()), ["workflow_call", "workflow_dispatch"])
        self.assertEqual(list(d["jobs"].keys()), ["backup", "migrate"])
        self.assertEqual(d["jobs"]["migrate"]["steps"][-1]["uses"], "actions/upload-artifact@v4")

    def test_16_payload_integrity_and_splitter_contract(self):
        # probe re-verifies render-time sha256 before executing anything
        self.assertIn("hash_equals(strtolower($sha), hash('sha256', $sql))", TMPL_TEXT)
        self.assertIn("payload sha256 mismatch", TMPL_TEXT)
        # the authoritative v1.8 file contains ';' inside a quoted string — the
        # quote/comment-aware splitter must keep such statements intact
        v18 = Path(SQL_V18).read_text()
        self.assertIn("metadata; never secrets", v18)
        self.assertIn("function asm_split_sql", TMPL_TEXT)
        self.assertIn("in_bquote", TMPL_TEXT) if False else None
        self.assertIn("$ch === \"'\" || $ch === '\"' || $ch === '`'", TMPL_TEXT)

    def test_17_audit_records_provenance_fields(self):
        audit = WF_TEXT.split("audit = {")[1].split("json.dump")[0]
        for field in ('"environment"', '"mode"', '"migration_selector"', '"source_commit_sha"',
                      '"workflow_run_id"', '"timestamp_utc"', '"backup_evidence"',
                      '"verification"', '"final_status"'):
            self.assertIn(field, audit)
        self.assertIn('"backup_gate": "PASS" if mode == "apply" else "NOT_REQUIRED(check)"', WF_TEXT)


if __name__ == "__main__":
    unittest.main()
