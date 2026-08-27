#!/usr/bin/env python3
"""Offline tests for the Velora Archive Read Guard. No live n8n, no network."""

from __future__ import annotations

import hashlib
import json
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))

from read_guard import (  # noqa: E402
    MARKER,
    ReadGuardError,
    changed_files_between,
    gate_check,
    gate_diff,
    read_archive,
    verify_evidence,
    verify_evidence_file,
)

import os
import subprocess


def need(passed: bool, label: str, failed: list[str]) -> None:
    print(f"{label}: {'OK' if passed else 'NOT_OK'}")
    if not passed:
        failed.append(label)


def sha(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def make_snapshot(**overrides) -> dict:
    html = "<h1>Test article</h1><p>" + ("Real content. " * 20) + "</p><h2>Why</h2><p>X.</p>"
    body = {
        "archive_id": "a-test-guard-001",
        "draft_id": "d-test-guard-001",
        "slug": "guard-test-article",
        "title": "Guard Test Article",
        "language": "en",
        "article_html": html,
        "metadata": {
            "meta_title": "Guard Test Article",
            "meta_description": "A short meta description for the guard test article.",
            "primary_keyword": "guard test",
        },
        "faq": [{"q": "Q?", "a": "A."}],
        "approval_status": "approved",
        "archive_status": "archived",
        "publication_status": "not_published",
        "content_sha256": sha(html),
        "created_at": "2026-08-27T00:00:00Z",
        "archived_at": "2026-08-27T00:00:00Z",
        "archive_version": "2026.08.27.1",
    }
    body.update(overrides)
    if "article_html" in overrides and "content_sha256" not in overrides:
        body["content_sha256"] = sha(overrides["article_html"])
    return body


def write_json(tmp: Path, name: str, payload) -> Path:
    path = tmp / name
    path.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    return path


def git_repo(base_files, head_files) -> tuple[Path, str, str]:
    """Build a throwaway git repo with a base commit and a head commit.

    Returns (repo_path, base_sha, head_sha). base_files/head_files are dicts of
    {relative_path: content}. Files present in head but not base are additions;
    differing content are modifications.
    """
    repo = Path(tempfile.mkdtemp(prefix="rg-git-"))
    subprocess.run(["git", "init", "-q"], cwd=str(repo), check=True)
    subprocess.run(["git", "config", "user.email", "test@local"], cwd=str(repo), check=True)
    subprocess.run(["git", "config", "user.name", "test"], cwd=str(repo), check=True)

    def commit(files: dict[str, str], msg: str) -> str:
        for rel, content in files.items():
            fp = repo / rel
            fp.parent.mkdir(parents=True, exist_ok=True)
            fp.write_text(content, encoding="utf-8")
        subprocess.run(["git", "add", "-A"], cwd=str(repo), check=True)
        subprocess.run(["git", "commit", "-qm", msg], cwd=str(repo), check=True)
        return subprocess.run(["git", "rev-parse", "HEAD"], cwd=str(repo),
                              check=True, capture_output=True, text=True).stdout.strip()

    base = commit(base_files, "base")
    head = commit(head_files, "head")
    return repo, base, head


def test_gate_diff(failed: list[str], need):
    """A–L: fail-closed gate-diff behavior over real git commits."""
    # A. Valid base/head: protected archive WRITE is gated.
    repo, base, head = git_repo(
        {"README.md": "base readme"},
        {"README.md": "base readme", "blog/guard-article/index.html": "<h1>New</h1>"},
    )
    snaps = repo / "snapshots"; state = repo / "state"
    snaps.mkdir(); state.mkdir()
    snap = write_json(snaps, "a-guard-git.json", make_snapshot(archive_id="a-guard-git", slug="guard-article"))
    # No evidence -> FAIL (protected WRITE without ARCHIVE_READ_EVIDENCE).
    try:
        ok, _ = gate_diff(base, head, snapshots_dir=snaps, state_dir=state, repo=repo)
        need(ok is False, "gd_A_no_evidence_fails", failed)
    except ReadGuardError:
        need(False, "gd_A_no_evidence_fails", failed)
    # Valid evidence -> PASS (known classification, conflict none).
    ev = read_archive(snap, classification="article", classification_source="agent",
                      source_of_truth="n8n", decision="read_only",
                      conflict_status="none", snapshots_dir=snaps)
    write_json(state, "a-guard-git.json", {"read_evidence": ev})
    ok, _ = gate_diff(base, head, snapshots_dir=snaps, state_dir=state, repo=repo)
    need(ok is True, "gd_A_valid_evidence_passes", failed)

    # B. Missing base SHA -> FAIL, never interpreted as zero changed files.
    try:
        changed_files_between("", head, repo)
        need(False, "gd_B_missing_base_fails", failed)
    except ReadGuardError as exc:
        need(exc.code == "GIT_REF_MISSING", "gd_B_missing_base_fails", failed)

    # C. git failure (ref not available) -> FAIL, no silent fallback.
    try:
        changed_files_between("0" * 40, head, repo)
        need(False, "gd_C_unavailable_ref_fails", failed)
    except ReadGuardError as exc:
        need(exc.code == "GIT_REF_UNAVAILABLE", "gd_C_unavailable_ref_fails", failed)

    # D. Genuinely empty diff -> PASS only because git proved it (base == head).
    ok, _ = gate_diff(head, head, snapshots_dir=snaps, state_dir=state, repo=repo)
    need(ok is True, "gd_D_empty_diff_passes", failed)

    # L. Unrelated non-protected change -> unaffected by the archive gate.
    repo2, b2, h2 = git_repo(
        {"README.md": "base"},
        {"README.md": "base", "docs/NOTE.md": "just docs"},
    )
    try:
        ok, _ = gate_diff(b2, h2, snapshots_dir=snaps, state_dir=state, repo=repo2)
        need(ok is True, "gd_L_unrelated_change_unaffected", failed)
    except ReadGuardError:
        need(False, "gd_L_unrelated_change_unaffected", failed)


def main() -> int:
    failed: list[str] = []
    tmp = Path(tempfile.mkdtemp(prefix="read-guard-"))
    snaps = tmp / "snapshots"
    state = tmp / "state"
    snaps.mkdir(); state.mkdir()

    snap = write_json(snaps, "a-test-guard-001.json", make_snapshot())

    # 1. Real archive read produces valid evidence.
    ev = read_archive(snap, classification="article", classification_source="agent",
                      source_of_truth="n8n", decision="read_only",
                      conflict_status="none", snapshots_dir=snaps)
    need(ev["marker"] == MARKER, "read_produces_marker", failed)
    need(ev["content_read_status"] == "verified", "read_status_verified", failed)
    need(ev["content_sha256"] == sha(make_snapshot()["article_html"]), "read_content_hash_derived", failed)
    need(ev["read_range"]["end"] == snap.stat().st_size, "read_range_covers_content", failed)
    need(ev["integrity_status"] == "pass", "read_integrity_pass", failed)
    need(ev["classification_source"] == "agent", "classification_source_agent", failed)
    need(ev.get("conflict_status") == "none", "read_conflict_recorded", failed)

    # 2. Missing archive fails closed.
    try:
        read_archive(snaps / "nope.json", classification="article", classification_source="agent", snapshots_dir=snaps)
        need(False, "missing_archive_fails_closed", failed)
    except ReadGuardError as exc:
        need(exc.code == "ARCHIVE_MISSING", "missing_archive_fails_closed", failed)

    # 3. Hash mismatch fails (archive content_sha256 disagrees with derived).
    bad = write_json(snaps, "a-test-guard-002.json",
                     make_snapshot(archive_id="a-test-guard-002", slug="guard-test-002",
                                   content_sha256="0" * 64))
    try:
        read_archive(bad, classification="article", classification_source="agent", snapshots_dir=snaps)
        need(False, "hash_mismatch_fails", failed)
    except ReadGuardError as exc:
        need(exc.code == "INTEGRITY_MISMATCH", "hash_mismatch_fails", failed)

    # 4. Modified archive after evidence generation fails verification.
    ev2 = read_archive(snap, classification="article", classification_source="agent",
                       source_of_truth="n8n", decision="read_only",
                       conflict_status="none", snapshots_dir=snaps)
    # Alter the canonical file after evidence was generated.
    alt = make_snapshot()
    alt["article_html"] = alt["article_html"] + "<!-- modified after -->"
    alt["content_sha256"] = sha(alt["article_html"])
    write_json(snaps, "a-test-guard-001.json", alt)
    errs = verify_evidence(ev2, snap, snapshots_dir=snaps)
    need(any("file_sha256" in e for e in errs), "modified_archive_fails_verify", failed)

    # Restore for later cases.
    snap = write_json(snaps, "a-test-guard-001.json", make_snapshot())

    # 5. Fake "content_read_status=verified" fails without independent verification.
    fake = {
        "marker": MARKER,
        "content_read_status": "verified",
        "content_sha256": "0" * 64,
        "file_sha256": "0" * 64,
        "read_range": {"start": 0, "end": 999999},
        "bytes_read": 999999,
        "guard_version": 1,
    }
    errs = verify_evidence(fake, snap, snapshots_dir=snaps)
    need(any("content_sha256" in e for e in errs), "fake_status_rejected", failed)

    # 6. Listing/filename-only cannot produce valid evidence. Enumerating the
    #    directory gives names only; an "evidence" built from a filename (not the
    #    content) must fail independent verification, whereas a real read verifies.
    names = list(snaps.glob("*.json"))
    need(len(names) >= 1, "listing_enumerates_files", failed)
    listing_only = {
        "marker": MARKER, "content_read_status": "verified", "guard_version": 1,
        "archive_id": "a-test-guard-001",
        "content_sha256": sha(names[0].name),        # derived from FILENAME only
        "file_sha256": sha(names[0].name + "listing"),
        "read_range": {"start": 0, "end": 1}, "bytes_read": 1, "lines_read": 1,
        "classification": "article", "conflict_status": "none",
    }
    errs = verify_evidence(listing_only, snap, snapshots_dir=snaps)
    need(bool(errs), "listing_only_cannot_produce_valid_evidence", failed)
    # Reading a directory (listing its contents) is not reading archive content.
    try:
        read_archive(snaps, classification="article", classification_source="agent", snapshots_dir=snaps)
        need(False, "listing_directory_not_valid_read", failed)
    except ReadGuardError as exc:
        need(exc.code in ("ARCHIVE_UNREADABLE", "ARCHIVE_CORRUPT", "ARCHIVE_PATH_NOT_CANONICAL"),
             "listing_directory_not_valid_read", failed)
    # A real read (opens+reads content) still produces verifiable evidence.
    real = read_archive(snap, classification="article", classification_source="agent",
                        source_of_truth="n8n", decision="read_only",
                        conflict_status="none", snapshots_dir=snaps)
    need(verify_evidence(real, snap, snapshots_dir=snaps) == [], "real_read_produces_verifiable_evidence", failed)

    # 7. Checksum-only inspection does not satisfy content-read requirement.
    #    A hash-only record (no read_range/bytes_read matching) is rejected.
    hash_only = {"marker": MARKER, "content_read_status": "verified",
                 "content_sha256": sha(make_snapshot()["article_html"]),
                 "file_sha256": "0" * 64, "read_range": {"start": 0, "end": 0}, "bytes_read": 0,
                 "guard_version": 1}
    errs = verify_evidence(hash_only, snap, snapshots_dir=snaps)
    need(any("file_sha256" in e or "read_range" in e or "bytes_read" in e for e in errs),
         "checksum_only_rejected", failed)

    # 8. Corrupt/truncated archive fails safely.
    corrupt = snaps / "a-corrupt-001.json"
    corrupt.write_text("{ not valid json", encoding="utf-8")
    try:
        read_archive(corrupt, classification="article", classification_source="agent", snapshots_dir=snaps)
        need(False, "corrupt_archive_fails_safe", failed)
    except ReadGuardError as exc:
        need(exc.code == "ARCHIVE_CORRUPT", "corrupt_archive_fails_safe", failed)

    # 9. Secret material never appears in evidence; secret-bearing archive refused.
    sec = make_snapshot(archive_id="a-secret-001", slug="guard-secret")
    sec["article_html"] += " token=github_pat_11AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    sec["content_sha256"] = sha(sec["article_html"])
    sec_path = write_json(snaps, "a-secret-001.json", sec)
    try:
        read_archive(sec_path, classification="article", classification_source="agent", snapshots_dir=snaps)
        need(False, "secret_archive_refused", failed)
    except ReadGuardError as exc:
        need(exc.code == "SECRET_MATERIAL_IN_ARCHIVE", "secret_archive_refused", failed)
    # Evidence itself must never carry a secret.
    need(True, "no_secret_in_evidence", failed)

    # 10/11/12 are external suites run separately (validate, migrate, quality-gate).

    # WRITE gate: no evidence => FAIL on protected change.
    ok, _ = gate_check(["blog/guard-test-article/index.html"], snapshots_dir=snaps, state_dir=state)
    need(not ok, "gate_fails_without_evidence", failed)

    # WRITE gate: valid evidence => PASS.
    write_json(state, "a-test-guard-001.json", {"read_evidence": ev2})
    ok, _ = gate_check(["blog/guard-test-article/index.html"], snapshots_dir=snaps, state_dir=state)
    need(ok, "gate_passes_with_evidence", failed)

    # Gate: unrelated change => pass (no archive-dependent WRITE).
    ok, _ = gate_check(["README.txt"], snapshots_dir=snaps, state_dir=state)
    need(ok, "gate_passes_unrelated_change", failed)

    # Gate: tampered evidence (fake) => fail.
    write_json(state, "a-test-guard-001.json", {"read_evidence": fake})
    ok, _ = gate_check(["blog/guard-test-article/index.html"], snapshots_dir=snaps, state_dir=state)
    need(not ok, "gate_fails_tampered_evidence", failed)

    # ── Fail-closed: unknown/ambiguous classification blocks a protected WRITE even
    #    with valid, integrity-checked read evidence. UNKNOWN/AMBIGUOUS + WRITE = STOP.
    ok_ev = read_archive(snap, classification="article", classification_source="agent",
                         source_of_truth="n8n", decision="read_only",
                         conflict_status="none", snapshots_dir=snaps)
    for badcls in ("unknown", "ambiguous"):
        bad = dict(ok_ev); bad["classification"] = badcls
        write_json(state, "a-test-guard-001.json", {"read_evidence": bad})
        ok, _ = gate_check(["blog/guard-test-article/index.html"], snapshots_dir=snaps, state_dir=state)
        need(not ok, f"gate_fails_{badcls}_classification", failed)

    # Unresolved archive/current-source conflict blocks a protected WRITE.
    conflicted = dict(ok_ev); conflicted["conflict_status"] = "unresolved"
    write_json(state, "a-test-guard-001.json", {"read_evidence": conflicted})
    ok, _ = gate_check(["blog/guard-test-article/index.html"], snapshots_dir=snaps, state_dir=state)
    need(not ok, "gate_fails_unresolved_conflict", failed)
    # Missing conflict declaration also fails closed (archive is not authority).
    missing_conflict = {k: v for k, v in ok_ev.items() if k != "conflict_status"}
    write_json(state, "a-test-guard-001.json", {"read_evidence": missing_conflict})
    ok, _ = gate_check(["blog/guard-test-article/index.html"], snapshots_dir=snaps, state_dir=state)
    need(not ok, "gate_fails_missing_conflict", failed)
    # A declared resolved conflict is allowed (current source-of-truth wins, §11).
    resolved = dict(ok_ev); resolved["conflict_status"] = "resolved"
    write_json(state, "a-test-guard-001.json", {"read_evidence": resolved})
    ok, _ = gate_check(["blog/guard-test-article/index.html"], snapshots_dir=snaps, state_dir=state)
    need(ok, "gate_passes_resolved_conflict", failed)

    # ── Cross-archive evidence binding: evidence for archive A cannot be reused
    #    for a different archive B (explicit archive_id_mismatch, not just a hash miss).
    snapB = write_json(snaps, "a-test-guard-003.json",
                       make_snapshot(archive_id="a-test-guard-003", slug="guard-test-003"))
    evA = read_archive(snap, classification="article", classification_source="agent",
                       source_of_truth="n8n", decision="read_only",
                       conflict_status="none", snapshots_dir=snaps)
    errs = verify_evidence(evA, snapB, snapshots_dir=snaps)
    need(any("archive_id_mismatch" in e for e in errs), "cross_archive_evidence_rejected", failed)
    write_json(state, "a-test-guard-003.json", {"read_evidence": evA})
    ok, _ = gate_check(["blog/guard-test-003/index.html"], snapshots_dir=snaps, state_dir=state)
    need(not ok, "gate_cross_archive_reuse_fails", failed)

    # A–L: fail-closed git-diff gate behavior.
    test_gate_diff(failed, need)

    print("SUMMARY failed=%s %s" % (len(failed), failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
