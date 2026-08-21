#!/usr/bin/env python3
"""TEST-26 — Artifact Freshness.

Proves the generated artifacts (localized/, public/locales/chunks,
public/locales/feature-manifest.json, csp-manifest.json, .csp-release.json)
are fresh with respect to the current source tree:

  1. provenance (commitSha + sourceDigest) is recorded in csp-manifest.json,
  2. sourceDigest matches the current source inputs,
  3. a clean regeneration is byte-for-byte identical (no drift).

Read-only: builds into a temp dir and never writes to the repository.
Exits non-zero with a clear message when drift is detected, e.g.
"source changed but generated artifact is stale".
"""

import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

sys.path.insert(0, str(ROOT / "tools" / "localization"))

from build_csp_artifacts import (  # noqa: E402
    compute_source_digest,
    validate_commit_sha,
)
from build_localized_static import (  # noqa: E402
    check_artifact_freshness,
    compare_generated_targets,
)


def _negative_probe() -> bool:
    """The comparison primitive must actually detect a byte difference."""
    with tempfile.TemporaryDirectory(prefix="velora-freshness-probe-") as td:
        base = Path(td)
        repo = base / "repo"
        stage = base / "stage"
        for root in (repo, stage):
            (root / "public" / "locales").mkdir(parents=True)
            (root / "localized").mkdir(parents=True)
            (root / "public" / "locales" / "chunks").mkdir(parents=True)
            (root / "public" / "locales" / "csp-manifest.json").write_text("{}")
            (root / "public" / "locales" / "feature-manifest.json").write_text("{}")
            (root / "localized" / ".csp-release.json").write_text("{}")
        (stage / "localized" / ".csp-release.json").write_text('{"drifted": true}')
        mismatches = compare_generated_targets(repo, stage)
        return bool(mismatches)


def main() -> int:
    failures: list[str] = []

    ok, errors = check_artifact_freshness(ROOT)
    failures.extend(errors)

    # Independent provenance sanity checks (clear, targeted messages).
    manifest_path = ROOT / "public" / "locales" / "csp-manifest.json"
    import json

    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    recorded_sha = manifest.get("commitSha")
    recorded_digest = manifest.get("sourceDigest")

    if not recorded_sha:
        failures.append(
            "committed csp-manifest.json is missing commitSha (provenance not recorded)"
        )
    else:
        try:
            validate_commit_sha(recorded_sha)
        except Exception as exc:  # CspArtifactError
            failures.append(str(exc))

    if recorded_digest:
        try:
            if compute_source_digest(ROOT) != recorded_digest:
                failures.append(
                    "source changed but generated artifact is stale "
                    "(sourceDigest mismatch)"
                )
        except Exception as exc:  # CspArtifactError
            failures.append(f"cannot compute source digest: {exc}")
    else:
        failures.append(
            "committed csp-manifest.json is missing sourceDigest (provenance not recorded)"
        )

    if not _negative_probe():
        failures.append("drift-detection primitive did not detect an injected difference")

    if failures:
        print("TEST-26 Artifact Freshness: FAIL")
        for failure in failures:
            print(f"  - {failure}")
        return 1

    print("TEST-26 Artifact Freshness: PASS")
    print(f"  - commitSha recorded and validated")
    print(f"  - sourceDigest matches current source")
    print(f"  - clean regeneration is byte-for-byte identical")
    print(f"  - drift-detection primitive verified")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
