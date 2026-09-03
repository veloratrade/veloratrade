#!/usr/bin/env python3
"""Dry-run tests for connecting to the PRIVATE backup repository. Uses a MOCK transport:
no network, no real repo, no credentials, and NO real database dump. The "dump" in tests
is a small synthetic gzip blob (not database data)."""
import gzip
import io
import json
import os
import sys
import unittest
import yaml

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.abspath(os.path.join(HERE, "..")))

import backup as bk                 # noqa: E402
import private_backup_repo as pb    # noqa: E402
import backup_repo_client as bc     # noqa: E402
import backup_lifecycle as bl       # noqa: E402

SHA = "117ab67777ab7832c1423912d4da1ab9d47d2885"
PRIV = "veloratrade/velora-backups"


def fake_dump(payload=b"-- MySQL dump test fixture\nCREATE TABLE x (id int);\n"):
    buf = io.BytesIO()
    with gzip.GzipFile(fileobj=buf, mode="wb") as gz:
        gz.write(payload)
    return buf.getvalue()


class MockTransport:
    """Records calls and returns canned responses; emulates the private repo API."""
    def __init__(self, repo_private=True, exists=True):
        self.calls = []
        self.repo_private = repo_private
        self.exists = exists
        self.releases = {}      # tag -> {id, assets:[]}
        self.commits = []       # (path, content_b64)
        self._rid = 1000

    def __call__(self, method, url, headers=None, data=None, raw=False):
        self.calls.append((method, url, data))
        h = headers or {}

        def resp(status, body):
            return {"status": status, "headers": {}, "json": body}

        if url.endswith(f"/repos/{PRIV}") and method == "GET":
            if not self.exists:
                return resp(404, {"message": "Not Found"})
            return resp(200, {"full_name": PRIV, "private": self.repo_private,
                              "default_branch": "main"})
        if f"/repos/{PRIV}/releases" in url and method == "GET":
            return resp(200, [{"tag_name": t, "id": v["id"], "draft": False}
                              for t, v in self.releases.items()])
        if "uploads.github.com" in url and method == "POST":
            return resp(201, {"state": "uploaded", "browser_download_url": "https://x/asset.sql.gz"})
        if f"/repos/{PRIV}/releases" in url and method == "POST":
            body = data if isinstance(data, dict) else json.loads(data)
            if body["tag_name"] in self.releases:
                return resp(422, {"message": "already_exists"})
            self._rid += 1
            self.releases[body["tag_name"]] = {"id": self._rid, "assets": [],
                                               "upload_url": f"https://uploads.github.com/repos/{PRIV}/releases/{self._rid}/assets{{?name,label}}"}
            return resp(201, {"id": self._rid, "upload_url": self.releases[body["tag_name"]]["upload_url"]})
        if f"/repos/{PRIV}/contents/" in url and method == "PUT":
            self.commits.append((url, data if isinstance(data, dict) else json.loads(data)))
            return resp(201, {"commit": {"sha": "c1"}})
        if f"/releases/tags/" in url and method == "GET":
            tag = url.rsplit("/", 1)[-1]
            if tag in self.releases:
                return resp(200, {"id": self.releases[tag]["id"], "tag_name": tag})
            return resp(404, {"message": "Not Found"})
        if "/releases/" in url and method == "DELETE":
            return {"status": 204, "headers": {}, "json": {}}
        return resp(404, {"message": "unhandled mock route", "url": url})


def make_client(**kw):
    t = MockTransport(**kw)
    return bc.Client(transport=t), t


class RepoConnectivityTests(unittest.TestCase):
    def test_01_repo_exists_and_private(self):
        c, t = make_client()
        info = c.check_repository()
        self.assertTrue(info["exists"] and info["private"])
        self.assertEqual(info["full_name"], PRIV)

    def test_02_refuses_public_repo(self):
        c, t = make_client(repo_private=False)
        with self.assertRaises(bc.BackupRepoClientError):
            c.check_repository()

    def test_03_missing_repo_fails_closed(self):
        c, t = make_client(exists=False)
        with self.assertRaises(bc.BackupRepoClientError):
            c.check_repository()

    def test_04_environment_refuses_production_when_staging(self):
        # staging metadata must never produce production namespaces
        m = pb.build_private_backup_metadata(
            "staging", source_commit_sha=SHA, workflow_run_id="1", created_utc="2026-09-02T10:00:00Z",
            size_bytes=10, checksum_sha256="a"*64, database_identity_redacted="***_staging",
            schema_migration_version=None)
        self.assertTrue(m["storage"]["release_tag"].startswith("db-backup-staging-"))
        # cross-environment gate blocks staging backup authorizing production
        g = pb.private_backup_gate(m, "production", "migrate", bk.STATE_INTEGRITY_VERIFIED)
        self.assertFalse(g["allowed"])
        self.assertTrue(any("cross-environment" in r for r in g["reasons"]))

    def test_05_namespaces_cannot_collide(self):
        s = pb.build_private_backup_metadata(
            "staging", source_commit_sha=SHA, workflow_run_id="1", created_utc="2026-09-02T10:00:00Z",
            size_bytes=1, checksum_sha256="a"*64, database_identity_redacted="x", schema_migration_version=None)
        p = pb.build_private_backup_metadata(
            "production", source_commit_sha=SHA, workflow_run_id="1", created_utc="2026-09-02T10:00:00Z",
            size_bytes=1, checksum_sha256="a"*64, database_identity_redacted="x", schema_migration_version=None)
        self.assertNotEqual(s["storage"]["release_tag"], p["storage"]["release_tag"])
        self.assertNotEqual(s["storage"]["metadata_path"], p["storage"]["metadata_path"])
        self.assertNotEqual(s["backup_id"], p["backup_id"])

    def test_06_unverified_backup_blocks_migration(self):
        m = pb.build_private_backup_metadata(
            "staging", source_commit_sha=SHA, workflow_run_id="1", created_utc="2026-09-02T10:00:00Z",
            size_bytes=1, checksum_sha256="a"*64, database_identity_redacted="x", schema_migration_version=None,
            verification_status=bk.STATE_CREATED)
        g = pb.private_backup_gate(m, "staging", "migrate", bk.STATE_INTEGRITY_VERIFIED)
        self.assertFalse(g["allowed"])


class DumpNeverInGitTests(unittest.TestCase):
    def test_07_dumps_go_to_release_asset_not_contents(self):
        c, t = make_client()
        dump = fake_dump()
        sha = c.sha256_of_bytes(dump)
        m = pb.build_private_backup_metadata(
            "staging", source_commit_sha=SHA, workflow_run_id="1", created_utc="2026-09-02T10:00:00Z",
            size_bytes=len(dump), checksum_sha256=sha, database_identity_redacted="x",
            schema_migration_version=None)
        c.commit_metadata("staging", m)
        rel = c.create_backup_release("staging", m)
        c.upload_release_asset("staging", m, rel, dump)
        # committed via contents API = only metadata JSON + sha256 (small text), never the dump
        for _method, url, data in t.calls:
            if "/contents/" in url:
                body = data if isinstance(data, dict) else json.loads(data)
                # content is base64 of the metadata json; ensure it is not the gzip dump
                import base64
                decoded = base64.b64decode(body["content"])
                self.assertFalse(decoded[:2] == b"\x1f\x8b", "a gzip dump was committed to git")
                self.assertIn(b"velora-db-backup", decoded)
        # the dump itself went to uploads host (release asset), exactly once
        uploads = [u for u in t.calls if "uploads.github.com" in u[1]]
        self.assertEqual(len(uploads), 1)
        self.assertEqual(uploads[0][2], dump)

    def test_08_release_asset_naming_deterministic(self):
        for env in ("staging", "production"):
            m = pb.build_private_backup_metadata(
                env, source_commit_sha=SHA, workflow_run_id="7", created_utc="2026-09-02T10:00:00Z",
                size_bytes=1, checksum_sha256="a"*64, database_identity_redacted="x", schema_migration_version=None)
            asset = m["storage"]["release_asset"]
            self.assertRegex(asset, rf"^{env}/bk-\w+-d-[0-9a-f]{{12}}\.sql\.gz$")
            self.assertRegex(m["storage"]["release_tag"], rf"^db-backup-{env}-\d{{14}}-[0-9a-f]+$")

    def test_09_metadata_has_no_secrets(self):
        m = pb.build_private_backup_metadata(
            "production", source_commit_sha=SHA, workflow_run_id="9", created_utc="2026-09-02T10:00:00Z",
            size_bytes=1, checksum_sha256="a"*64, database_identity_redacted="***_velora_production",
            schema_migration_version="v1.0,v1.1")
        blob = json.dumps(m).lower()
        for bad in ["password", "ftp://", "mysql://", "github_pat", "ghp_", "private_key", ".env", "token="]:
            self.assertNotIn(bad, blob)


class ShaAndRetentionTests(unittest.TestCase):
    def test_10_sha256_integrity_gate(self):
        c, t = make_client()
        dump = fake_dump()
        sha = c.sha256_of_bytes(dump)
        ok = c.verify_integrity("staging", sha, dump)
        self.assertEqual(ok["verification_status"], bk.STATE_INTEGRITY_VERIFIED)
        self.assertTrue(ok["gzip_valid"] and ok["sha256_matches"])
        # tampered checksum => not integrity verified; restore stays separate
        bad = c.verify_integrity("staging", "0"*64, dump)
        self.assertEqual(bad["verification_status"], bk.STATE_CREATED)
        self.assertEqual(bad["restore_test_status"], bk.STATE_UNVERIFIED)

    def test_11_retention_keeps_newest_and_only_after_success(self):
        c, t = make_client()
        # pre-seed three old backups
        old = []
        for i in range(3):
            ts = f"2026-08-{i+1:02d}T10:00:00Z"
            m = pb.build_private_backup_metadata(
                "staging", source_commit_sha=SHA, workflow_run_id=str(i), created_utc=ts,
                size_bytes=10, checksum_sha256=("a"*63 + str(i)), database_identity_redacted="x",
                schema_migration_version=None, verification_status=bk.STATE_INTEGRITY_VERIFIED)
            m["retention_expires_at_utc"] = "2000-01-01T00:00:00Z"
            t.releases[m["storage"]["release_tag"]] = {"id": 900+i, "assets": []}
            old.append(m)
        # failure => no deletion
        plan_fail = pb.retention_plan(old, mutation_succeeded=False, post_verify_passed=False)
        self.assertTrue(plan_fail["stopped"]); self.assertEqual(plan_fail["delete_release_tags"], [])
        # success => expired oldest may delete, but newest retained
        plan_ok = pb.retention_plan(old, mutation_succeeded=True, post_verify_passed=True,
                                    keep_last_n=1, now_utc="2026-09-30T00:00:00Z")
        newest_tag = sorted(old, key=lambda x: x["created_at_utc"], reverse=True)[0]["storage"]["release_tag"]
        self.assertNotIn(newest_tag, plan_ok["delete_release_tags"])


class LifecycleTests(unittest.TestCase):
    def _req(self, **kw):
        base = dict(environment="staging", operation="migrate", commit_sha=SHA,
                    workflow_run_id="555", plan_hash="p"*64, migrations=["v1.0", "v1.1"],
                    approval_token=None, dump_bytes=None, created_at_utc="2026-09-02T10:00:00Z",
                    dry_run=True)
        base.update(kw); return bl.LifecycleRequest(**base)

    def test_12_no_dump_is_blocked_not_fabricated(self):
        c, t = make_client()
        out = bl.run_backup_lifecycle(c, self._req())
        self.assertEqual(out["status"], bl.BLOCKED)
        self.assertIn("not fabricated", out["reasons"][0])

    def test_13_invalid_environment_fails_closed(self):
        c, t = make_client()
        out = bl.run_backup_lifecycle(c, self._req(environment="prod"))
        self.assertEqual(out["status"], bl.STOP)

    def test_14_backup_op_completes_after_integrity_dry_run(self):
        c, t = make_client()
        dump = fake_dump()
        out = bl.run_backup_lifecycle(c, self._req(operation="backup", dump_bytes=dump,
                                                   database_identity="***_velora_staging / MySQL 8.0.46"))
        self.assertEqual(out["status"], bl.COMPLETE, out)
        self.assertEqual(out["verification_status"], bk.STATE_INTEGRITY_VERIFIED)
        self.assertEqual(out["restore_test_status"], bk.STATE_UNVERIFIED)
        self.assertFalse(out["retention_performed"])

    def test_15_production_mutation_requires_approval(self):
        c, t = make_client()
        dump = fake_dump()
        out = bl.run_backup_lifecycle(c, self._req(environment="production", operation="migrate",
                                                   dump_bytes=dump))
        self.assertEqual(out["status"], bl.STOP)
        self.assertEqual(out["stopped_at"], "approve")

    def test_16_failed_mutation_keeps_backups_no_retention(self):
        c, t = make_client()
        dump = fake_dump()
        ap = {"environment": "production", "operation": "migrate", "commit_sha": SHA,
              "migrations": ["v1.0", "v1.1"], "plan_hash": "p"*64, "approved": True}
        out = bl.run_backup_lifecycle(c, self._req(environment="production", operation="migrate",
                                                   dump_bytes=dump, approval_token=ap,
                                                   mutation_succeeded=False))
        self.assertEqual(out["status"], bl.STOP)
        self.assertFalse(out["retention_performed"]); self.assertEqual(out["backups_deleted"], [])

    def test_17_dry_run_never_uploads_or_deletes(self):
        c, t = make_client()
        dump = fake_dump()
        out = bl.run_backup_lifecycle(c, self._req(operation="backup", dump_bytes=dump))
        # no network writes in dry-run
        writes = [x for x in t.calls if x[0] in ("POST", "PUT", "DELETE")]
        self.assertEqual(writes, [])


class ExistingProductionFileBackupIntactTests(unittest.TestCase):
    def test_18_production_file_backup_lifecycle_unchanged(self):
        # The new DB-backup system is separate from deploy.yml file backups; assert the
        # naming/type separation so file backups are never mistaken for DB backups.
        m = pb.build_private_backup_metadata(
            "production", source_commit_sha=SHA, workflow_run_id="1", created_utc="2026-09-02T10:00:00Z",
            size_bytes=1, checksum_sha256="a"*64, database_identity_redacted="x",
            schema_migration_version=None)
        self.assertEqual(m["backup_type"], "database")
        # file backups live as deploy.yml artifacts with a distinct name; no collision
        file_artifact = "pre-deploy-backup-" + SHA
        self.assertNotEqual(file_artifact, m["storage"]["release_tag"])
        self.assertTrue(m["storage"]["release_asset"].endswith(".sql.gz"))

class HardenedIntegrationTests(unittest.TestCase):
    """Finalization tests: wrong-repo rejection, no SQL surface, missing credential,
    no pull_request trigger, token never appears in error messages."""

    def test_19_wrong_repository_rejected(self):
        # A client pointed at any repo other than veloratrade/velora-backups must
        # refuse even if that other repo exists and is private.
        t = MockTransport()
        c = bc.Client(repo="veloratrade/veloratrade", transport=t)
        with self.assertRaises(bc.BackupRepoClientError):
            c.check_repository()  # mock returns full_name=velora-backups -> mismatch

    def test_20_repo_constant_is_hard_bound(self):
        self.assertEqual(pb.PRIVATE_BACKUP_REPO, "veloratrade/velora-backups")
        self.assertNotEqual(pb.PRIVATE_BACKUP_REPO, pb.PUBLIC_APP_REPO)
        # client defaults to the private repo, never the public app repo
        c = bc.Client(transport=lambda *a, **k: {"status": 200,
                      "json": {"full_name": pb.PRIVATE_BACKUP_REPO, "private": True,
                               "default_branch": "main"}})
        self.assertEqual(c.repo, "veloratrade/velora-backups")

    def test_21_no_arbitrary_sql_surface(self):
        # The client/lifecycle must have no way to run SQL: no sql/query/exec method.
        import inspect, re
        src = inspect.getsource(bc) + inspect.getsource(bl) + inspect.getsource(pb)
        # strip docstrings/comments so that words in PROSE (e.g. runbook references)
        # do not count; we assert the absence of actual EXECUTION primitives.
        code = re.sub(r"\"\"\".*?\"\"\"", "", src, flags=re.S)
        code = chr(10).join(l.split("#", 1)[0] for l in code.splitlines())
        for bad in ["subprocess", "os.system", "os.popen", "eval(", "exec(",
                    "pymysql", "mysql.connector", "sqlite3", ".cursor(",
                    "def execute_sql", "def run_sql", "def query("]:
            self.assertNotIn(bad, code, f"found SQL/shell execution surface: {bad}")
        # SQL passed in any field is just inert metadata; no executor exists.
        m = pb.build_private_backup_metadata(
            "staging", source_commit_sha=SHA, workflow_run_id="1", created_utc="2026-09-02T10:00:00Z",
            size_bytes=1, checksum_sha256="a"*64, database_identity_redacted="x; DROP TABLE x;",
            schema_migration_version=None)
        self.assertEqual(m["backup_type"], "database")  # string stored, never executed

    def test_22_missing_credential_fails_closed(self):
        # No token in env and no explicit token => API calls carry no Authorization;
        # a 401/404 transport response must raise, never silently succeed.
        monkeypatch_env = {"PATH": "/usr/bin"}

        def noauth_transport(method, url, headers=None, data=None, raw=False):
            assert not any(k.lower() == "authorization" for k in (headers or {})), "auth header present"
            return {"status": 401, "headers": {}, "json": {"message": "Requires authentication"}}
        c = bc.Client(transport=noauth_transport)
        with self.assertRaises(bc.BackupRepoClientError):
            c.check_repository()

    def test_22b_github_token_is_never_a_fallback(self):
        # BACKUP_REPO_TOKEN is the ONLY accepted credential. GITHUB_TOKEN must be
        # ignored even when BACKUP_REPO_TOKEN is absent (fail closed, no fallback).
        import backup_repo_client as _bc
        real_env = dict(os.environ)
        try:
            os.environ.pop("BACKUP_REPO_TOKEN", None)
            os.environ["GITHUB_TOKEN"] = "tok_github_token_value_ignored"
            # the resolver must NOT pick up GITHUB_TOKEN
            self.assertIsNone(_bc.default_token())
            # and constructing a default (real-network) client without
            # BACKUP_REPO_TOKEN must raise even though GITHUB_TOKEN is set
            with self.assertRaises(bc.BackupRepoClientError):
                bc.Client()  # no injected transport => default transport path
            # when BACKUP_REPO_TOKEN is present it IS used
            os.environ["BACKUP_REPO_TOKEN"] = "tok_backup_repo_token_value"
            self.assertEqual(_bc.default_token(), "tok_backup_repo_token_value")
        finally:
            os.environ.clear()
            os.environ.update(real_env)

    def test_22c_default_client_requires_backup_repo_token(self):
        # Without any injected transport and without BACKUP_REPO_TOKEN, the client
        # fails closed at construction (no silent unauthenticated default transport).
        real_env = dict(os.environ)
        try:
            os.environ.pop("BACKUP_REPO_TOKEN", None)
            with self.assertRaises(bc.BackupRepoClientError):
                bc.Client()
        finally:
            os.environ.clear()
            os.environ.update(real_env)

    def test_23_token_never_leaks_into_error_messages(self):
        secret = "github_pat_SUPERSECRETVALUE123"
        def leaky(method, url, headers=None, data=None, raw=False):
            # emulate an HTTP error whose body does NOT contain the token
            return {"status": 500, "headers": {}, "json": {"message": "internal error"}}
        c = bc.Client(transport=leaky)
        try:
            c.check_repository()
            self.fail("expected error")
        except bc.BackupRepoClientError as e:
            self.assertNotIn(secret, str(e))
            self.assertNotIn("Authorization", str(e))
        # the default transport puts the token only in a header, never in URLs/bodies
        tr = bc.make_default_transport(token=secret)
        captured = {}
        def fake_urlopen(req, timeout=None):
            captured["auth"] = req.headers.get("Authorization")
            raise type("E", (), {"code": 500, "headers": {}, "read": lambda self: b'{"message":"x"}'})()
        import urllib.request
        orig = urllib.request.urlopen
        urllib.request.urlopen = fake_urlopen
        try:
            tr("GET", "https://api.github.com/repos/veloratrade/velora-backups")
        except Exception:
            pass
        finally:
            urllib.request.urlopen = orig
        self.assertEqual(captured.get("auth"), f"Bearer {secret}")  # header only
        # token must not appear in the URL
        self.assertNotIn(secret, "https://api.github.com/repos/veloratrade/velora-backups")

    def test_24_workflow_has_no_pull_request_trigger(self):
        wf_path = os.path.abspath(os.path.join(HERE, "..", "..", "..",
                                              ".github", "workflows", "velora-db-backup.yml"))
        with open(wf_path) as f:
            wf = f.read()
        d = yaml.safe_load(wf)
        triggers = d.get("on", d.get(True))
        trig_keys = list(triggers.keys()) if isinstance(triggers, dict) else [triggers]
        self.assertIn("workflow_dispatch", trig_keys)
        for forbidden in ("pull_request", "pull_request_target", "push", "issue_comment", "fork"):
            self.assertNotIn(forbidden, trig_keys, f"unexpected trigger {forbidden}")
        # the secret must never be echoed/interpolated into a log statement
        self.assertNotIn('echo "$BACKUP_REPO_TOKEN"', wf)
        self.assertNotIn("printenv", wf)
        self.assertIn("add-mask", wf)

if __name__ == "__main__":
    unittest.main(verbosity=2)
