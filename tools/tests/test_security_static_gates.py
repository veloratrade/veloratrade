#!/usr/bin/env python3
"""VELORA — static security/enforcement gates (G10/G11/G12, static-verified).

These gates are static evidence ONLY. They never claim runtime security:
runtime IDOR / RBAC / live-MetaAPI verification is reported separately in the
audit matrix (BLOCKED without a live environment). The gates pin the WIRING
contracts so the same *structural* regressions cannot silently return:

  G10 — ownership isolation: every trade read/write path is user-scoped;
        screenshot extraction user comes from the SERVER session (never from
        the request body); dedup cache keys include the user id;
        AI analyze requires server-resolved trade_ids[] (client trades[] is
        rejected with 422).
  G11 — admin RBAC: if an admin controller exists it MUST gate on server-side
        admin authorization (403), not UI visibility. (On main the admin
        controller is ABSENT — the feature lives on fix/browser-ocr-gate.)
  G12 — live-data numeric path: the webhook/ingress and trade service pass
        numbers through raw (no number_format / locale-digit formatting in the
        backend numeric path) and the frontend ships the global Latin-digit
        layer (Intl -u-nu-latn patch).

Sparse-cone compatible: files are read via `git show HEAD:<path>`; a full-tree
path may be supplied with VSI_TREE for reviewing other refs.
"""
from __future__ import annotations

import os
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TREE = os.environ.get("VSI_TREE") or str(ROOT)
NO_LATIN_NUMERIC = re.compile(r"number_format\s*\(")
PERSIAN_DIGITS = re.compile(r"[\u06f0-\u06f9]")


def read(path: str) -> str:
    """Read a tracked file: working tree, or VSI_TREE, or `git show HEAD:`."""
    p = Path(TREE) / path
    if p.is_file():
        return p.read_text(encoding="utf-8", errors="replace")
    out = subprocess.run(
        ["git", "show", f"HEAD:{path}"], cwd=str(ROOT),
        capture_output=True, text=True,
    )
    if out.returncode != 0:
        return ""
    return out.stdout


def check(name: str, cond: bool, detail: str = ""):
    if not cond:
        raise AssertionError(f"{name}: {detail or 'contract violated'}")


class StaticSecurityGatesTest(unittest.TestCase):
    def test_g10_trade_repository_user_scoped(self):
        repo = read("api/src/Trades/TradeRepository.php")
        check("G10.repo-present", bool(repo))
        # every direct trades id-scoped read/update/delete must carry user_id
        id_queries = re.findall(
            r"(?:SELECT|UPDATE|DELETE)[^;]*?\b(?:id)\s*=\s*:id[^;]*", repo, re.S)
        check("G10.repo.id-queries-found", len(id_queries) >= 4,
              f"expected id-scoped queries, found {len(id_queries)}")
        for q in id_queries:
            # child-table writes (trade_exits) are anchored to a parent trade:
            # they are only legal when the function derives exit ids from a
            # trade_id-scoped SELECT of an owned parent (never client ids).
            if "trade_exits" in q:
                check("G10.repo.exits-parent-anchored",
                      "trade_id = :id" in repo or "trade_id = :tid" in repo,
                      f"trade_exits write without parent anchoring: {q[:80]!r}")
                continue
            check("G10.repo.user-scoped", "user_id" in q,
                  f"query without user_id: {q[:80]!r}")
        # listing path
        check("G10.repo.list-scoped", "t.user_id = :user_id" in repo)

    def test_g10_exit_repository_user_scoped(self):
        exits = read("api/src/Trades/TradeExitRepository.php")
        check("G10.exit-repo-present", bool(exits))
        check("G10.exit.reads-joined",
              "JOIN trades t" in exits and "t.user_id = :uid" in exits,
              "exit reads must JOIN the owning trade and scope user_id")
        check("G10.exit.delete-joined",
              re.search(r"DELETE te FROM trade_exits te[\s\S]{0,200}JOIN trades t[\s\S]{0,200}user_id", exits) is not None,
              "exit delete must JOIN the owning trade and scope user_id")
        check("G10.exit.parent-fetch-scoped",
              "FROM trades WHERE id = :id AND user_id" in exits,
              "insert path must fetch the parent trade user-scoped first")

    def test_g10_extraction_user_from_session(self):
        ctl = read("api/src/Trades/ScreenshotExtractController.php")
        check("G10.extract-controller-present", bool(ctl))
        check("G10.extract.session-user",
              "request->attributes['user_id']" in ctl or "attributes['user_id']" in ctl,
              "user id must come from server-side session attributes")
        check("G10.extract.no-body-user",
              "$request->body['user_id']" not in ctl and "$request->body['userId']" not in ctl,
              "user id must never be accepted from the request body")
        check("G10.extract.dedup-user-scoped",
              re.search(r"tryFindCachedExtraction\s*\([^)]*\$userId", ctl) is not None,
              "dedup cache lookup must be user-scoped")

    def test_g10_ai_client_payload_rejected(self):
        ctl = read("api/src/AI/Controllers/AIController.php")
        check("G10.ai-controller-present", bool(ctl))
        check("G10.ai.trades-rejected",
              "trade_ids" in ctl and re.search(r"trades'[\s\S]{0,120}422|422[\s\S]{0,120}trades", ctl) is not None,
              "client-supplied trades[] must be rejected (422; trade_ids[] only)")

    def test_g11_admin_rbac_server_side(self):
        ctl = read("api/src/Admin/AIConfigController.php")
        if not ctl:
            raise unittest.SkipTest(
                "G11: AIConfigController absent on this tree — admin RBAC is a "
                "fix/browser-ocr-gate feature; NOT VERIFIED on main (activates on the "
                "full tree that carries the feature)")
        check("G11.admin.isAdmin-guard",
              bool(re.search(r"isAdmin|is_admin|requireAdmin|Forbidden|403", ctl)),
              "admin controller must gate on server-side admin authorization")
        check("G11.admin.no-ui-only-auth",
              "classList" not in ctl and "hidden" not in ctl,
              "authorization must not rely on UI visibility")

    def test_g12_live_data_raw_numeric_passthrough(self):
        webhook = read("api/src/Webhooks/MetaApiWebhookController.php")
        service = read("api/src/Trades/TradeService.php")
        check("G12.metaapi-controller-present", bool(webhook))
        check("G12.metaapi.no-number-format",
              not NO_LATIN_NUMERIC.search(webhook) and not NO_LATIN_NUMERIC.search(service),
              "backend numeric path must not locale-format numbers")
        check("G12.metaapi.no-persian-digits",
              not PERSIAN_DIGITS.search(webhook),
              "webhook must not emit Persian digits")
        check("G12.metaapi.event-dedup",
              "eventKey" in webhook and "sha256" in webhook,
              "webhook replay/dedup must be keyed")

    def test_g12_frontend_latin_layer_global(self):
        asset = read("public/assets/velora-latin-digits.js")
        check("G12.latin-asset-present", bool(asset))
        check("G12.latin.intl-patch",
              "numberingSystem" in asset and "toLocaleString" in asset,
              "global Intl patch must force -u-nu-latn")
        check("G12.latin.dom-observer", "MutationObserver" in asset)

    def test_g12_registry_numbering_latn(self):
        registry = read("public/assets/velora-locale-registry.js")
        check("G12.registry-present", bool(registry))
        check("G12.registry.latn", '"latn"' in registry)


if __name__ == "__main__":
    unittest.main()
