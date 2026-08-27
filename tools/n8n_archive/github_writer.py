"""GitHub App writer: branch n8n-archive/<id> + snapshot file + PR. No PAT."""
from __future__ import annotations

import json
import urllib.error
import urllib.request
from typing import Any, Callable

HttpFn = Callable[[str, str, dict[str, str], bytes | None], tuple[int, dict[str, Any]]]


class GitHubWriterError(Exception):
    def __init__(self, code: str, message: str, http_status: int = 502):
        super().__init__(message)
        self.code = code
        self.http_status = http_status


def _default_http(url: str, method: str, headers: dict[str, str], body: bytes | None) -> tuple[int, dict[str, Any]]:
    req = urllib.request.Request(url, data=body, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            raw = resp.read().decode("utf-8")
            data = json.loads(raw) if raw else {}
            return resp.status, data if isinstance(data, dict) else {"data": data}
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        try:
            data = json.loads(raw) if raw else {}
        except json.JSONDecodeError:
            data = {"message": "http_error"}
        return exc.code, data if isinstance(data, dict) else {}


class GitHubAppWriter:
    def __init__(
        self,
        *,
        owner: str,
        repo: str,
        token: str,
        http: HttpFn | None = None,
    ):
        self.owner = owner
        self.repo = repo
        self.token = token
        self.http = http or _default_http

    def _headers(self) -> dict[str, str]:
        return {
            "Authorization": f"Bearer {self.token}",
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
            "Content-Type": "application/json",
            "User-Agent": "velora-n8n-archive-ingest",
        }

    def _api(self, method: str, path: str, payload: dict[str, Any] | None = None) -> tuple[int, dict[str, Any]]:
        url = f"https://api.github.com{path}"
        body = json.dumps(payload).encode("utf-8") if payload is not None else None
        return self.http(url, method, self._headers(), body)

    def publish_snapshot(self, archive_id: str, snapshot_json: str, content_sha256: str) -> dict[str, Any]:
        branch = f"n8n-archive/{archive_id}"
        path = f"content/n8n-archive/snapshots/{archive_id}.json"
        status, repo = self._api("GET", f"/repos/{self.owner}/{self.repo}")
        if status != 200:
            raise GitHubWriterError("GITHUB_REPO", "GitHub repository lookup failed.", status)
        default_branch = str(repo.get("default_branch") or "main")
        status, ref = self._api("GET", f"/repos/{self.owner}/{self.repo}/git/ref/heads/{default_branch}")
        if status != 200:
            raise GitHubWriterError("GITHUB_REF", "GitHub default branch ref failed.", status)
        sha = ((ref.get("object") or {}).get("sha"))
        if not sha:
            raise GitHubWriterError("GITHUB_REF", "Missing default branch SHA.")

        status, _existing = self._api("GET", f"/repos/{self.owner}/{self.repo}/git/ref/heads/{branch}")
        if status == 404:
            status, created = self._api(
                "POST",
                f"/repos/{self.owner}/{self.repo}/git/refs",
                {"ref": f"refs/heads/{branch}", "sha": sha},
            )
            if status not in (200, 201):
                raise GitHubWriterError("GITHUB_BRANCH", "Could not create archive branch.", status)
            branch_created = True
        elif status == 200:
            branch_created = False
        else:
            raise GitHubWriterError("GITHUB_BRANCH", "Could not inspect archive branch.", status)

        import base64

        encoded = base64.b64encode(snapshot_json.encode("utf-8")).decode("ascii")
        put_payload = {
            "message": f"chore(content): add n8n archive snapshot {archive_id}",
            "content": encoded,
            "branch": branch,
        }
        status, put = self._api(
            "PUT",
            f"/repos/{self.owner}/{self.repo}/contents/{path}",
            put_payload,
        )
        if status not in (200, 201):
            raise GitHubWriterError("GITHUB_CONTENT", "Could not write snapshot file.", status)

        pr_body = {
            "title": f"chore(content): n8n archive snapshot {archive_id}",
            "head": branch,
            "base": default_branch,
            "body": (
                "Automated snapshot ingest. Single file only.\n\n"
                f"archive_id: `{archive_id}`\n"
                f"content_sha256: `{content_sha256}`\n\n"
                "Does not deploy Production. Does not modify blog pages."
            ),
        }
        status, pr = self._api("POST", f"/repos/{self.owner}/{self.repo}/pulls", pr_body)
        if status == 422 and "already exists" in json.dumps(pr).lower():
            return {
                "ok": True,
                "noop": True,
                "branch": branch,
                "path": path,
                "branch_created": branch_created,
                "pr_url": None,
            }
        if status not in (200, 201):
            raise GitHubWriterError("GITHUB_PR", "Could not open snapshot PR.", status)
        return {
            "ok": True,
            "noop": False,
            "branch": branch,
            "path": path,
            "branch_created": branch_created,
            "pr_url": pr.get("html_url"),
            "pr_number": pr.get("number"),
        }
