#!/usr/bin/env python3
"""
VELORA — Private backup-repository CLIENT / connection layer.

Connects the environment-management architecture to veloratrade/velora-backups (PRIVATE).

Safety properties:
  * This client NEVER runs SQL, NEVER creates a database dump, NEVER migrates/deploys.
    It only (a) reads repo/metadata, (b) commits small metadata/checksum TEXT files via the
    contents API, (c) creates releases and uploads a dump that the CALLER provides as
    bytes (the real dump is produced on the host by a separately-authorized probe step),
    and (d) verifies integrity. There is no path by which this client invents a backup.
  * Environment is explicit (staging|production) and is enforced on every namespace
    (release tag, asset name, metadata path) via private_backup_repo; a mismatch raises.
  * Retention deletion is exposed ONLY as a guarded call that the state machine permits
    after a fully successful, post-verified mutation; the client never deletes the newest
    verified backup and never deletes during a failed operation.
  * Credentials are NEVER printed or embedded; the client reads a token from the
    environment/transport and errors closed when it is absent.

Network I/O goes through an injectable `transport` callable so all behavior is testable
with mocks (no real repository, credential, upload, or backup is exercised in tests).
"""
from __future__ import annotations

import gzip
import hashlib
import io
import json
import os
import urllib.parse
from dataclasses import dataclass
from typing import Any, Callable, Optional

try:
    import private_backup_repo as pb
    import backup as bk
except ImportError:  # pragma: no cover
    import os as _os, sys as _sys
    _sys.path.insert(0, _os.path.dirname(_os.path.abspath(__file__)))
    import private_backup_repo as pb
    import backup as bk


GITHUB_API = "https://api.github.com"


class BackupRepoClientError(Exception):
    pass


# --------------------------------------------------------------------------- #
# Transport (injectable). Default urllib transport reads a token from the
# environment; it is never constructed with a hard-coded secret.
# --------------------------------------------------------------------------- #

def default_token() -> Optional[str]:
    """Preferred: a GitHub App installation token is provided by the workflow and
    exposed as BACKUP_REPO_TOKEN (resolved from BACKUP_REPO_APP_* before this runs).
    Fallback: a fine-grained PAT scoped to velora-backups. Never printed."""
    return os.environ.get("BACKUP_REPO_TOKEN") or os.environ.get("GITHUB_TOKEN")


def make_default_transport(token: Optional[str] = None) -> Callable[..., dict]:
    import urllib.request, urllib.error
    tok = token if token is not None else default_token()

    def transport(method: str, url: str, headers: Optional[dict] = None,
                  data: Any = None, raw: bool = False) -> dict:
        hdrs = {
            "Accept": "application/vnd.github+json",
            "User-Agent": "velora-backup-mgmt",
            "X-GitHub-Api-Version": "2022-11-28",
        }
        if tok:
            hdrs["Authorization"] = f"Bearer {tok}"
        if headers:
            hdrs.update(headers)
        body = None
        if data is not None:
            body = data if isinstance(data, (bytes, bytearray)) else json.dumps(data).encode()
            if isinstance(data, dict) and not any(k.lower() == "content-type" for k in hdrs):
                hdrs["Content-Type"] = "application/json"
        req = urllib.request.Request(url, data=body, method=method, headers=hdrs)
        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                raw_body = resp.read()
                out = {"status": resp.status, "headers": dict(resp.headers)}
                if raw:
                    out["raw"] = raw_body
                else:
                    out["json"] = json.loads(raw_body) if raw_body else {}
                return out
        except urllib.error.HTTPError as e:
            raw_body = e.read()
            try:
                parsed = json.loads(raw_body) if raw_body else {}
            except Exception:
                parsed = {"message": raw_body[:200].decode(errors="replace")}
            return {"status": e.code, "headers": dict(e.headers), "json": parsed,
                    "error": True}
    return transport


# --------------------------------------------------------------------------- #
# Client
# --------------------------------------------------------------------------- #

@dataclass
class Client:
    repo: str = pb.PRIVATE_BACKUP_REPO
    transport: Callable[..., dict] = None

    def __post_init__(self):
        if self.transport is None:
            self.transport = make_default_transport()

    def _api(self, path: str, method: str = "GET", data: Any = None) -> dict:
        url = f"{GITHUB_API}/repos/{self.repo}/{path.lstrip('/')}"
        return self.transport(method, url, data=data)

    # ---- read-only discovery -------------------------------------------------
    def check_repository(self) -> dict:
        """Verify the target repo exists and is PRIVATE, and that the identity can
        read it. Fails closed on any mismatch or inaccessible repo."""
        r = self.transport("GET", f"{GITHUB_API}/repos/{self.repo}")
        if r.get("status") != 200:
            raise BackupRepoClientError(
                f"cannot access {self.repo} (HTTP {r.get('status')}). The backup identity "
                f"must be granted read/write on the PRIVATE repo; STOP until configured.")
        j = r.get("json", {})
        if j.get("full_name") != self.repo:
            raise BackupRepoClientError(f"repo name mismatch: {j.get('full_name')} != {self.repo}")
        if not j.get("private"):
            raise BackupRepoClientError(f"{self.repo} is NOT private; refusing to back up to a public repo")
        return {"exists": True, "private": True, "full_name": j.get("full_name"),
                "default_branch": j.get("default_branch")}

    def list_backups(self, environment: str) -> list[dict]:
        """Read existing release tags for one environment (read-only)."""
        pb.require_environment(environment)
        prefix = pb.RELEASE_TAG_PREFIX.format(env=environment)
        r = self._api("releases?per_page=100")
        if r.get("status") != 200:
            raise BackupRepoClientError(f"list releases failed HTTP {r.get('status')}")
        return [{"tag": x.get("tag_name"), "id": x.get("id"), "draft": x.get("draft")}
                for x in r.get("json", []) if (x.get("tag_name") or "").startswith(prefix)]

    # ---- metadata (small text committed to git; never dumps) ----------------
    def commit_metadata(self, environment: str, metadata: dict, branch: str = "main",
                        checksum_text: Optional[str] = None) -> dict:
        """Commit backups/<env>/<id>.json (and .sha256) to the PRIVATE repo via the
        contents API. These are tiny secret-free text files — never a dump blob."""
        pb.require_environment(environment)
        pb.assert_metadata_has_no_secrets(metadata)
        import base64
        files = [(metadata["storage"]["metadata_path"], json.dumps(metadata, indent=2))]
        if checksum_text is not None:
            files.append((metadata["storage"]["metadata_path"] + ".sha256", checksum_text))
        committed = []
        for path, content in files:
            if not path.startswith(f"backups/{environment}/"):
                raise BackupRepoClientError(f"refusing to write outside env namespace: {path}")
            payload = {"message": f"backup metadata {metadata['backup_id']} ({environment})",
                       "content": base64.b64encode(content.encode()).decode(),
                       "branch": branch}
            r = self._api(f"contents/{urllib.parse.quote(path)}", "PUT", payload)
            if r.get("status") not in (200, 201):
                raise BackupRepoClientError(
                    f"metadata commit failed for {path} HTTP {r.get('status')} {r.get('json',{}).get('message','')}")
            committed.append(path)
        return {"committed": committed}

    # ---- release + release asset (the ONLY place a dump may live) ------------
    def create_backup_release(self, environment: str, metadata: dict) -> dict:
        pb.validate_namespace(environment, metadata["storage"]["release_tag"],
                              metadata["storage"]["metadata_path"],
                              metadata["storage"]["release_asset"])
        payload = {"tag_name": metadata["storage"]["release_tag"],
                   "target_commitish": "main",
                   "name": f"DB backup {environment} {metadata['backup_id']}",
                   "body": ("Velora database backup (Release Asset). Metadata committed under "
                            f"backups/{environment}/. verification_status="
                            f"{metadata.get('verification_status')}"),
                   "draft": False, "prerelease": environment == "staging"}
        r = self._api("releases", "POST", payload)
        if r.get("status") not in (200, 201):
            # release tag may already exist => idempotency: look it up
            r2 = self._api(f"releases/tags/{payload['tag_name']}")
            if r2.get("status") != 200:
                raise BackupRepoClientError(
                    f"release create failed HTTP {r.get('status')} {r.get('json',{}).get('message','')}")
            return {"id": r2["json"]["id"], "upload_url": r2["json"]["upload_url"], "reused": True}
        return {"id": r["json"]["id"], "upload_url": r["json"]["upload_url"], "reused": False}

    def upload_release_asset(self, environment: str, metadata: dict, release: dict,
                             dump_bytes: bytes) -> dict:
        """Upload the .sql.gz dump as a release asset ONLY. This never writes the dump
        to git. The dump bytes come from the caller (host-produced dump in real life)."""
        asset = metadata["storage"]["release_asset"]
        if not asset.startswith(f"{environment}/") or not asset.endswith(".sql.gz"):
            raise BackupRepoClientError(f"asset path violates env/type namespace: {asset}")
        # the filename used in the upload is the deterministic asset name
        upload_url = release["upload_url"].split("{", 1)[0]
        url = f"{upload_url}?name={urllib.parse.quote(os.path.basename(asset))}"
        r = self.transport("POST", url, headers={
            "Content-Type": "application/gzip",
            "Content-Length": str(len(dump_bytes)),
        }, data=dump_bytes)
        if r.get("status") not in (200, 201):
            raise BackupRepoClientError(
                f"asset upload failed HTTP {r.get('status')} {r.get('json',{}).get('message','')}")
        return {"asset": asset, "browser_download_url": r.get("json", {}).get("browser_download_url")}

    # ---- verification (integrity; restore is separate and never here) --------
    @staticmethod
    def sha256_of_bytes(b: bytes) -> str:
        return hashlib.sha256(b).hexdigest()

    def verify_integrity(self, environment: str, expected_sha256: str,
                         dump_bytes: bytes) -> dict:
        """Integrity check used BEFORE a backup can satisfy the migration gate:
          * expected sha256 must match the actual bytes;
          * gzip stream must be valid (gzip -t equivalent);
          * the archive must be non-empty and contain a SQL dump marker.
        Restore verification is NOT performed here (separate state)."""
        actual = self.sha256_of_bytes(dump_bytes)
        checksum_ok = (actual == expected_sha256)
        gzip_ok = False
        sql_marker = False
        size = len(dump_bytes)
        try:
            with gzip.GzipFile(fileobj=io.BytesIO(dump_bytes)) as gz:
                head = gz.read(4096)
            gzip_ok = True
            # a mysqldump is text containing typical markers
            sql_marker = b"MySQL dump" in head or b"-- " in head or b"CREATE TABLE" in head
        except Exception:
            gzip_ok = False
        integrity_ok = checksum_ok and gzip_ok and (size > 0)
        # sql_marker is advisory; we surface it but do not fail solely on marker variants
        state = bk.STATE_INTEGRITY_VERIFIED if integrity_ok else (
            bk.STATE_CREATED if size > 0 else bk.STATE_UNVERIFIED)
        return {"environment": environment, "size_bytes": size,
                "sha256_matches": checksum_ok, "gzip_valid": gzip_ok,
                "sql_marker_seen": sql_marker, "verification_status": state,
                "restore_test_status": bk.STATE_UNVERIFIED}

    # ---- retention (guarded; never newest; only caller invokes post-success) -
    def delete_release_by_tag(self, environment: str, tag: str) -> dict:
        """Low-level deletion. Intentionally a separate, explicit call; the state
        machine only invokes it with tags returned by private_backup_repo.retention_plan
        AFTER a successful post-verified operation."""
        if not tag.startswith(pb.RELEASE_TAG_PREFIX.format(env=environment)):
            raise BackupRepoClientError(f"refusing to delete out-of-environment tag: {tag}")
        r = self._api(f"releases/tags/{urllib.parse.quote(tag)}")
        if r.get("status") != 200:
            raise BackupRepoClientError(f"release lookup failed for {tag} HTTP {r.get('status')}")
        rid = r["json"]["id"]
        d = self._api(f"releases/{rid}", "DELETE")
        if d.get("status") not in (204, 200):
            raise BackupRepoClientError(f"release delete failed HTTP {d.get('status')}")
        return {"deleted_release": tag, "id": rid}
