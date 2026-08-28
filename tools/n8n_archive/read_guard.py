#!/usr/bin/env python3
"""Velora Archive Read Guard — prove canonical archive content was actually accessed.

This guard turns the policy requirement "read the archive before acting on it"
into a machine-verifiable artifact. It does NOT classify content on the Agent's
behalf; it proves that the canonical archive file was actually opened and read,
and that the recorded evidence matches that file.

Design invariants
-----------------
- Only canonical archive files under ``content/n8n-archive/snapshots/`` are accepted.
- ``content_read_status`` is emitted only after the guard actually opens and reads
  the file. It is never taken from caller input.
- The content hash is always derived from the canonical file itself; a caller-supplied
  hash is never trusted as proof.
- Evidence records byte/line ranges actually read, establishing real content access.
- The verifier recomputes the canonical file hash itself and rejects any evidence that
  does not independently match the current canonical content.
- Fail closed: if the file cannot be reliably read/validated, no evidence is produced.
- No secret material is ever included in evidence.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path
from typing import Any

MARKER = "ARCHIVE_READ_EVIDENCE"
GUARD_VERSION = 1
SNAPSHOT_DIR = Path("content/n8n-archive/snapshots")
STATE_DIR = Path("content/n8n-archive/state")
CANONICAL_ARCHIVE_ID_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]*$")

# Protected WRITE paths that are archive-derived. If any of these changes and the
# change materially depends on archive content, the WRITE gate requires evidence.
PROTECTED_ARCHIVE_PATHS = {
    "blog/",       # FA article output (derived from archive)
    "en/blog/",    # EN article output (derived from archive)
    "content/n8n-archive/snapshots/",  # archive source
    "content/n8n-archive/state/",      # processing ledger (evidence lives here)
}

# Inside these two directories a file is an archive record only when it is named
# ``<archive_id>.json`` — see ``content/n8n-archive/README.md`` §Layout and
# ``docs/N8N_ARCHIVE_AGENT.md`` §2 ("idempotency key = filename/`archive_id`").
SNAPSHOT_DIR_PREFIX = "content/n8n-archive/snapshots/"
STATE_DIR_PREFIX = "content/n8n-archive/state/"
ARCHIVE_RECORD_PREFIXES = (SNAPSHOT_DIR_PREFIX, STATE_DIR_PREFIX)
ARCHIVE_RECORD_DIRS = tuple(d.rstrip("/") for d in ARCHIVE_RECORD_PREFIXES)

# Tracked directory placeholders. ``.gitkeep`` keeps the empty archive directories
# representable in git and was committed together with the archive contract itself,
# so it is repository structure, never an archive id. Exempting it opens no hole:
# every archive record is a ``<archive_id>.json`` file, and adding/removing one
# always shows up in the diff as its own path, which is still gated below.
ARCHIVE_DIR_PLACEHOLDERS = frozenset({".gitkeep"})

SECRET_PATTERNS = [
    re.compile(r"github_pat_[A-Za-z0-9_]{10,}"),
    re.compile(r"ghp_[A-Za-z0-9]{20,}"),
    re.compile(r"sk-[A-Za-z0-9]{20,}"),
    re.compile(r"AIza[A-Za-z0-9_\-]{20,}"),
    re.compile(r"\b\d{8,}:[A-Za-z0-9_-]{30,}\b"),  # Telegram bot token
    re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"),
    re.compile(r"\beyJ[A-Za-z0-9_\-]{20,}\.[A-Za-z0-9_\-]{10,}\."),  # JWT
]


class ReadGuardError(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


# --------------------------------------------------------------------------
# Helpers
# --------------------------------------------------------------------------


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def has_secret_material(text: str) -> bool:
    return any(p.search(text) for p in SECRET_PATTERNS)


def resolve_canonical_archive(path: Path, snapshots_dir: Path = SNAPSHOT_DIR) -> Path:
    """Resolve a path to a canonical archive file. Rejects anything outside snapshots/."""
    try:
        resolved = path.resolve()
    except OSError as exc:
        raise ReadGuardError("ARCHIVE_PATH_INVALID", f"cannot resolve path: {exc}") from exc
    canon_dir = snapshots_dir.resolve()
    # Must be inside the canonical snapshots directory.
    try:
        resolved.relative_to(canon_dir)
    except ValueError as exc:
        raise ReadGuardError(
            "ARCHIVE_PATH_NOT_CANONICAL",
            "Only canonical archive files under content/n8n-archive/snapshots/ are accepted.",
        ) from exc
    return resolved


def extract_archive_id_from_name(path: Path) -> str:
    name = path.name
    if not name.endswith(".json"):
        raise ReadGuardError("ARCHIVE_PATH_INVALID", "archive file must end in .json")
    return name[: -len(".json")]


# --------------------------------------------------------------------------
# Read + evidence generation
# --------------------------------------------------------------------------


def read_archive(
    path: Path,
    *,
    classification: str | None,
    classification_source: str,
    source_of_truth: str | None = None,
    decision: str | None = None,
    conflict_status: str | None = None,
    snapshots_dir: Path = SNAPSHOT_DIR,
) -> dict[str, Any]:
    """Open and read a canonical archive file, derive hashes, and emit evidence.

    ``classification_source`` must be ``"agent"`` for agent-declared classification;
    the guard never asserts classification as independently verified.
    ``conflict_status`` is agent-declared state about whether the archive conflicts
    with the current source of truth (one of ``none``/``resolved``/``unresolved``).
    The guard records it but does not judge it; the WRITE gate fails closed on
    ``unresolved``/missing (archive is evidence, not authority — §11).
    """
    if classification_source not in ("agent",):
        raise ReadGuardError("CLASSIFICATION_SOURCE_INVALID", "classification_source must be 'agent'")
    canonical = resolve_canonical_archive(path, snapshots_dir)

    if not canonical.exists():
        raise ReadGuardError("ARCHIVE_MISSING", f"archive does not exist: {canonical.name}")

    try:
        raw_bytes = canonical.read_bytes()
    except OSError as exc:
        raise ReadGuardError("ARCHIVE_UNREADABLE", f"cannot read archive: {exc}") from exc

    if not raw_bytes:
        raise ReadGuardError("ARCHIVE_EMPTY", "archive is empty; cannot read as valid content")

    # Parse JSON to validate structure / detect corruption.
    try:
        text = raw_bytes.decode("utf-8")
        data = json.loads(text)
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise ReadGuardError("ARCHIVE_CORRUPT", f"archive is not valid JSON: {exc}") from exc
    if not isinstance(data, dict):
        raise ReadGuardError("ARCHIVE_CORRUPT", "archive root must be a JSON object")

    # Secret scan — refuse to produce evidence for material that carries secrets.
    if has_secret_material(text):
        raise ReadGuardError("SECRET_MATERIAL_IN_ARCHIVE", "secret material detected in archive; refused")

    # Derive hashes from the canonical file itself.
    file_sha256 = sha256_bytes(raw_bytes)
    article_html = data.get("article_html")
    if isinstance(article_html, str):
        content_sha256 = sha256_bytes(article_html.encode("utf-8"))
    else:
        # If no article_html, derive content hash from the canonical serialized body.
        content_sha256 = sha256_bytes(
            json.dumps(data, sort_keys=True, ensure_ascii=False).encode("utf-8")
        )

    # Integrity: compare against recorded content_sha256 when present.
    integrity_status = "pass"
    recorded = data.get("content_sha256")
    if isinstance(recorded, str) and recorded != content_sha256:
        integrity_status = "hash_mismatch"

    captured_at = data.get("archived_at") or data.get("created_at") or data.get("captured_at")
    archive_version = data.get("archive_version")

    evidence: dict[str, Any] = {
        "marker": MARKER,
        "guard_version": GUARD_VERSION,
        "archive_file": str(canonical),
        "archive_id": data.get("archive_id") or extract_archive_id_from_name(canonical),
        "content_sha256": content_sha256,
        "file_sha256": file_sha256,
        "content_read_status": "verified",
        "read_range": {
            "start": 0,
            "end": len(raw_bytes),
        },
        "bytes_read": len(raw_bytes),
        "lines_read": text.count("\n") + (0 if text.endswith("\n") else 1),
        "integrity_status": integrity_status,
        "classification": classification,
        "classification_source": classification_source,
        "source_of_truth": source_of_truth,
        "captured_at": captured_at,
        "archive_version": archive_version,
        "decision": decision,
        "conflict_status": conflict_status,
    }
    if integrity_status != "pass":
        raise ReadGuardError(
            "INTEGRITY_MISMATCH",
            "archive content_sha256 does not match derived hash; refusing to emit evidence.",
        )
    return evidence


# --------------------------------------------------------------------------
# Verification (recompute everything from canonical file; reject fakes)
# --------------------------------------------------------------------------


def verify_evidence(
    evidence: dict[str, Any],
    canonical_path: Path,
    snapshots_dir: Path = SNAPSHOT_DIR,
) -> list[str]:
    """Verify evidence against the canonical archive. Returns a list of errors (empty = valid).

    Never trusts ``content_read_status`` by itself: it recomputes hashes and ranges
    from the actual canonical file.
    """
    errors: list[str] = []
    if evidence.get("marker") != MARKER:
        errors.append("marker_missing_or_wrong")
    if not isinstance(evidence.get("content_read_status"), str) or evidence.get("content_read_status") != "verified":
        errors.append("content_read_status_not_verified")

    canonical = resolve_canonical_archive(canonical_path, snapshots_dir)
    if not canonical.exists():
        errors.append("archive_missing")
        return errors

    try:
        raw_bytes = canonical.read_bytes()
        text = raw_bytes.decode("utf-8")
        data = json.loads(text)
    except (OSError, UnicodeDecodeError, json.JSONDecodeError):
        errors.append("archive_unreadable")
        return errors

    # Recompute.
    file_sha256 = sha256_bytes(raw_bytes)
    article_html = data.get("article_html")
    if isinstance(article_html, str):
        content_sha256 = sha256_bytes(article_html.encode("utf-8"))
    else:
        content_sha256 = sha256_bytes(json.dumps(data, sort_keys=True, ensure_ascii=False).encode("utf-8"))

    # Cross-archive binding: evidence must belong to THIS canonical snapshot's
    # archive_id. Never rely only on an indirect hash mismatch.
    canonical_archive_id = data.get("archive_id") or canonical.stem
    if evidence.get("archive_id") != canonical_archive_id:
        errors.append("archive_id_mismatch")

    if not evidence.get("content_sha256") or evidence["content_sha256"] != content_sha256:
        errors.append("content_sha256_missing_or_mismatch")
    if not evidence.get("file_sha256") or evidence["file_sha256"] != file_sha256:
        errors.append("file_sha256_missing_or_mismatch")

    # read_range must cover the actual content (no evidence without real access).
    rr = evidence.get("read_range")
    expected_end = len(raw_bytes)
    if not isinstance(rr, dict) or rr.get("start") != 0 or rr.get("end") != expected_end:
        errors.append("read_range_does_not_match_content")
    if evidence.get("bytes_read") != expected_end:
        errors.append("bytes_read_mismatch")

    if evidence.get("guard_version") != GUARD_VERSION:
        errors.append("guard_version_mismatch")

    return errors


def verify_evidence_file(evidence_path: Path, archive_path: Path, snapshots_dir: Path = SNAPSHOT_DIR) -> list[str]:
    try:
        evidence = json.loads(evidence_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return ["evidence_unreadable"]
    if not isinstance(evidence, dict):
        return ["evidence_not_object"]
    return verify_evidence(evidence, archive_path, snapshots_dir)


# --------------------------------------------------------------------------
# WRITE gate
# --------------------------------------------------------------------------


def is_protected_archive_path(path: str) -> bool:
    return any(path == p.rstrip("/") or path.startswith(p) for p in PROTECTED_ARCHIVE_PATHS)


def is_archive_record_path(path: str) -> bool:
    """True for anything inside the two archive record directories.

    The bare directory path counts too: a mode/symlink change on the directory
    itself appears as exactly that path and could hide or replace records.
    """
    return path.startswith(ARCHIVE_RECORD_PREFIXES) or path in ARCHIVE_RECORD_DIRS


def classify_archive_record(path: str) -> tuple[str, str]:
    """Classify one changed entry inside the archive record directories.

    Returns ``(kind, value)``:

    - ``("archive-id", id)`` — a ``<archive_id>.json`` snapshot/ledger record with a
      canonical id: validate it normally.
    - ``("placeholder", name)`` — a tracked directory placeholder (``.gitkeep``):
      not archive content, ignore it.
    - ``("unexpected", path)`` — anything else. The caller MUST fail closed; the
      repository contract permits no other file type in these directories, so an
      unrecognised entry is never treated as "nothing to gate".
    """
    if path in ARCHIVE_RECORD_DIRS:
        return "unexpected", path
    name = Path(path).name
    if name in ARCHIVE_DIR_PLACEHOLDERS:
        return "placeholder", name
    try:
        # Same rule the read/verify side already enforces: an archive file must end
        # in `.json`, and the id is exactly the filename without that suffix.
        archive_id = extract_archive_id_from_name(Path(path))
    except ReadGuardError:
        return "unexpected", path
    if not CANONICAL_ARCHIVE_ID_RE.match(archive_id):
        return "unexpected", path
    return "archive-id", archive_id


def slug_to_snapshot_id(slug: str, snapshots_dir: Path = SNAPSHOT_DIR) -> str | None:
    """Map an article slug to a canonical snapshot id by reading snapshot content."""
    if not snapshots_dir.exists():
        return None
    for snap in snapshots_dir.glob("*.json"):
        try:
            data = json.loads(snap.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            continue
        if isinstance(data, dict) and data.get("slug") == slug:
            return data.get("archive_id") or snap.name[: -len(".json")]
    return None


def gate_check(
    changed_files: list[str],
    *,
    snapshots_dir: Path = SNAPSHOT_DIR,
    state_dir: Path = STATE_DIR,
) -> tuple[bool, list[str]]:
    """Evaluate whether protected WRITE changes have valid archive-read evidence.

    Returns (ok, messages). Fails closed: if a protected archive-dependent change
    lacks a valid, self-verifying evidence record, it fails.
    """
    affected_ids: set[str] = set()
    messages: list[str] = []
    ok = True
    for f in changed_files:
        if not is_protected_archive_path(f):
            continue
        if is_archive_record_path(f):
            kind, value = classify_archive_record(f)
            if kind == "placeholder":
                continue
            if kind == "unexpected":
                messages.append(
                    f"unexpected non-archive entry in a protected archive directory: {value} "
                    "(only <archive_id>.json records and the tracked .gitkeep are permitted)"
                )
                ok = False
                continue
            affected_ids.add(value)
        else:
            # blog/<slug>/ or en/blog/<slug>/
            parts = f.split("/")
            # slug is the first directory component after blog/ or en/blog/
            slug = None
            for i, part in enumerate(parts):
                if part in ("blog",) and i + 1 < len(parts):
                    slug = parts[i + 1]
                    break
            if slug:
                sid = slug_to_snapshot_id(slug, snapshots_dir)
                if sid:
                    affected_ids.add(sid)

    for aid in sorted(affected_ids):
        snap = snapshots_dir / f"{aid}.json"
        state = state_dir / f"{aid}.json"
        if not snap.exists():
            messages.append(f"affected archive missing canonical snapshot: {aid}")
            ok = False
            continue
        if not state.exists():
            messages.append(f"no read-evidence ledger for archive: {aid} (no evidence => no WRITE)")
            ok = False
            continue
        try:
            state_data = json.loads(state.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            messages.append(f"state ledger unreadable for archive: {aid}")
            ok = False
            continue
        evidence = state_data.get("read_evidence")
        if not isinstance(evidence, dict):
            messages.append(f"missing ARCHIVE_READ_EVIDENCE for archive: {aid}")
            ok = False
            continue
        errs = verify_evidence(evidence, snap, snapshots_dir=snapshots_dir)
        if errs:
            messages.append(f"invalid read-evidence for archive {aid}: {', '.join(errs)}")
            ok = False
            continue
        # Fail-closed: a protected WRITE requires a known classification.
        # unknown/ambiguous/missing => STOP; never turn uncertainty into authorization.
        cls = evidence.get("classification")
        if cls is None or str(cls).strip().lower() in ("unknown", "ambiguous"):
            messages.append(
                f"archive {aid}: classification unknown/ambiguous ({cls!r}) — STOP, no protected WRITE"
            )
            ok = False
            continue
        # Fail-closed: an unresolved archive-vs-current-source-of-truth conflict
        # blocks a protected WRITE. Archive is evidence, not authority (§11).
        conflict = evidence.get("conflict_status")
        if conflict not in ("none", "resolved"):
            messages.append(
                f"archive {aid}: unresolved archive/current-source conflict "
                f"(conflict_status={conflict!r}) — STOP, no protected WRITE"
            )
            ok = False
            continue
        messages.append(f"verified read-evidence present for archive: {aid}")

    if not affected_ids:
        messages.append("no archive-dependent protected WRITE detected; gate passes")
    return ok, messages


# --------------------------------------------------------------------------
# Fail-closed changed-file detection (git diff base..head)
# --------------------------------------------------------------------------


def _git(cmd: list[str], repo: Path) -> subprocess.CompletedProcess:
    """Run a git command in the repo, returning the completed process."""
    return subprocess.run(cmd, capture_output=True, text=True, cwd=str(repo))


def changed_files_between(base_sha: str, head_sha: str, repo: Path = Path(".")) -> list[str]:
    """Return the list of changed file paths between two commits, FAIL-CLOSED.

    Never returns an empty list as a fallback for an error. If the changed-file
    set cannot be determined for ANY reason, raises ReadGuardError:
      - a required ref is missing/empty
      - a commit object is not available locally
      - the git diff command fails
    """
    if not base_sha or not head_sha:
        raise ReadGuardError(
            "GIT_REF_MISSING",
            "base_sha and head_sha are required; cannot determine changed-file set.",
        )

    # Both refs must name commits available locally before we trust any result.
    for label, ref in (("base", base_sha), ("head", head_sha)):
        r = _git(["git", "cat-file", "-e", f"{ref}^{{commit}}"], repo)
        if r.returncode != 0:
            raise ReadGuardError(
                "GIT_REF_UNAVAILABLE",
                f"{label} commit {ref} is not available locally; cannot determine changed-file set.",
            )

    r = _git(["git", "diff", "--name-only", base_sha, head_sha], repo)
    if r.returncode != 0:
        raise ReadGuardError(
            "GIT_DIFF_FAILED",
            f"git diff --name-only {base_sha} {head_sha} failed: {r.stderr.strip()}",
        )

    files = [ln for ln in r.stdout.splitlines() if ln.strip()]
    # Empty list here is ONLY valid because git diff succeeded and genuinely
    # produced no changes between the two commits. Errors never reach this point.
    return files


def gate_diff(
    base_sha: str,
    head_sha: str,
    *,
    snapshots_dir: Path = SNAPSHOT_DIR,
    state_dir: Path = STATE_DIR,
    repo: Path = Path("."),
) -> tuple[bool, list[str]]:
    """WRITE gate over the git diff between base..head, fail-closed.

    Raises ReadGuardError when the changed-file set cannot be determined.
    Returns (ok, messages) otherwise (empty diff => ok).
    """
    files = changed_files_between(base_sha, head_sha, repo)
    if not files:
        return True, ["git successfully proved empty diff base..head; no changes to gate"]
    return gate_check(files, snapshots_dir=snapshots_dir, state_dir=state_dir)


# --------------------------------------------------------------------------
# CLI
# --------------------------------------------------------------------------


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description="Velora Archive Read Guard")
    sub = p.add_subparsers(dest="cmd", required=True)

    r = sub.add_parser("read", help="Open/read a canonical archive and emit evidence")
    r.add_argument("--archive", required=True, help="canonical archive path under content/n8n-archive/snapshots/")
    r.add_argument("--classification", default=None, help="agent-declared classification")
    r.add_argument("--source-of-truth", default=None)
    r.add_argument("--decision", default=None)
    r.add_argument("--conflict-status", default=None,
                   help="agent-declared conflict vs current source of truth: none|resolved|unresolved")
    r.add_argument("--out", default=None, help="write evidence JSON to this path")

    v = sub.add_parser("verify", help="Verify evidence against the canonical archive")
    v.add_argument("--archive", required=True)
    v.add_argument("--evidence", required=True)

    g = sub.add_parser("gate", help="WRITE gate: check protected changes have valid evidence")
    g.add_argument("--changed-file", action="append", default=[], required=True)
    g.add_argument("--snapshots", default=str(SNAPSHOT_DIR))
    g.add_argument("--state", default=str(STATE_DIR))

    gd = sub.add_parser(
        "gate-diff",
        help="Fail-closed WRITE gate over the git diff between --base and --head "
             "(for CI; fails if the changed-file set cannot be determined).",
    )
    gd.add_argument("--base", required=True, help="base commit SHA (e.g. pull_request.base.sha)")
    gd.add_argument("--head", required=True, help="head commit SHA (e.g. github.sha)")
    gd.add_argument("--snapshots", default=str(SNAPSHOT_DIR))
    gd.add_argument("--state", default=str(STATE_DIR))
    gd.add_argument("--repo", default=str(Path(".")), help="git repository root (default .)")

    args = p.parse_args(argv)

    if args.cmd == "read":
        try:
            evidence = read_archive(
                Path(args.archive),
                classification=args.classification,
                classification_source="agent",
                source_of_truth=args.source_of_truth,
                decision=args.decision,
                conflict_status=args.conflict_status,
            )
        except ReadGuardError as exc:
            print(f"FAIL {exc.code}: {exc}")
            return 1
        blob = json.dumps(evidence, indent=2, ensure_ascii=False)
        if args.out:
            Path(args.out).write_text(blob + "\n", encoding="utf-8")
        print(f"PASS {MARKER} archive={evidence['archive_id']} status={evidence['content_read_status']} "
              f"sha={evidence['content_sha256'][:16]} range={evidence['read_range']['end']} bytes")
        return 0

    if args.cmd == "verify":
        errs = verify_evidence_file(Path(args.evidence), Path(args.archive))
        if errs:
            print("FAIL " + "; ".join(errs))
            return 1
        print("PASS evidence matches canonical archive")
        return 0

    if args.cmd == "gate":
        snap_dir = Path(args.snapshots)
        state_dir = Path(args.state)
        ok, messages = gate_check(args.changed_file, snapshots_dir=snap_dir, state_dir=state_dir)
        for m in messages:
            print(("OK  " if "verified" in m or "passes" in m or "present" in m else "WARN ") + m)
        if not ok:
            print("FAIL archive-read WRITE gate")
            return 1
        print("PASS archive-read WRITE gate")
        return 0

    if args.cmd == "gate-diff":
        snap_dir = Path(args.snapshots)
        state_dir = Path(args.state)
        repo = Path(args.repo)
        try:
            ok, messages = gate_diff(args.base, args.head,
                                     snapshots_dir=snap_dir, state_dir=state_dir, repo=repo)
        except ReadGuardError as exc:
            print(f"FAIL {exc.code}: {exc}")
            return 1
        for m in messages:
            print(("OK  " if "verified" in m or "passes" in m or "present" in m else "WARN ") + m)
        if not ok:
            print("FAIL archive-read WRITE gate")
            return 1
        print("PASS archive-read WRITE gate")
        return 0

    return 2


if __name__ == "__main__":
    sys.exit(main())
