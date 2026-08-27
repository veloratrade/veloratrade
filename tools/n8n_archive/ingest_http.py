"""Optional loopback HTTPS-incapable stdlib server for local tests only.

Do not bind this on a public webroot. Production ingest belongs outside
public_html and is not enabled in this phase.
"""
from __future__ import annotations

import json
import os
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

from ingest import ingest


class Handler(BaseHTTPRequestHandler):
    snapshots_dir = Path("/tmp/n8n-archive-snapshots")
    replay_dir = Path("/tmp/n8n-archive-replay")

    def do_POST(self) -> None:  # noqa: N802
        length = int(self.headers.get("Content-Length") or "0")
        raw = self.rfile.read(length)
        result = ingest(
            raw_body=raw,
            signature=self.headers.get("X-Velora-Signature"),
            timestamp=self.headers.get("X-Velora-Timestamp"),
            hmac_secret=os.environ.get("N8N_ARCHIVE_HMAC_SECRET", ""),
            snapshots_dir=self.snapshots_dir,
            replay_dir=self.replay_dir,
            github=None,
        )
        status = int(result.get("http_status") or 500)
        payload = json.dumps({k: v for k, v in result.items() if k != "http_status"}).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, fmt: str, *args) -> None:
        # Never log bodies or signatures.
        sys_stderr_write = self.address_string()
        _ = (fmt, args, sys_stderr_write)


def main() -> None:
    server = HTTPServer(("127.0.0.1", 8787), Handler)
    server.serve_forever()


if __name__ == "__main__":
    main()
