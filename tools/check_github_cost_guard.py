#!/usr/bin/env python3
"""Fail closed if a workflow introduces a potentially billable or policy-prohibited GitHub feature.

Scope of this guard (static analysis only, no network, no dependencies):

  * Runner policy       — only standard Linux runners are allowed BY POLICY.
                          NOTE: Windows/macOS standard runners are FREE on public
                          repositories per official GitHub docs; they are blocked here
                          purely as a Velora policy simplification, not because GitHub
                          charges for them.
  * Timeouts            — every REAL job (one that declares runs-on:) must set
                          timeout-minutes so a hung job cannot burn the runner pool.
                          Reusable-only jobs (uses: another workflow) are exempt.
  * Schedule / triggers — schedule/cron, repository_dispatch and workflow_run are
                          prohibited (unattended runs).
  * Cache               — Actions cache requires explicit storage review.
  * Packages / registry — packages: write, docker build-push/login, ghcr.io and
                          npm publish require explicit billing review.
  * Artifacts           — every upload-artifact usage must set retention-days <= 14.
  * Permissions         — id-token: write and unexpected *: write permissions are
                          flagged (least-privilege).

The guard is FAIL-CLOSED: any finding prints GITHUB_COST_GUARD_FAIL and exits 1.
"""

from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = ROOT / ".github" / "workflows"

# Standard Linux runners are the only labels allowed by Velora policy.
ALLOWED_RUNNERS = {"ubuntu-latest", "ubuntu-24.04", "ubuntu-22.04"}
MAX_ARTIFACT_RETENTION_DAYS = 14

# Permission keys that may legitimately be granted (read is always fine).
# Anything set to `write` that is NOT in this allowlist is flagged.
ALLOWED_WRITE_PERMISSIONS: set[str] = set()  # none required today


errors: list[str] = []
runner_count = 0
artifact_count = 0
real_job_count = 0


def split_top_level_jobs(source: str) -> list[tuple[str, str]]:
    """Return (job_name, job_block_text) for each top-level job under `jobs:`.

    Reusable workflow definition files (top-level `on: workflow_call` with a
    `jobs:` map) are handled the same way; a job that only has `uses:` is
    recognised as reusable and exempt from the timeout rule.
    """
    m = re.search(r"(?ms)^jobs:\s*$(.*)", source)
    if not m:
        return []
    body = m.group(1)
    names = re.findall(r"(?m)^  ([A-Za-z0-9_-]+):\s*$", body)
    blocks: list[tuple[str, str]] = []
    for name in names:
        jm = re.search(
            r"(?ms)^  " + re.escape(name) + r":\s*$(.*?)(?=^  [A-Za-z0-9_-]+:\s*$|\Z)",
            body,
        )
        blocks.append((name, jm.group(1) if jm else ""))
    return blocks


def check_runner_label(rel: Path, raw: str) -> None:
    """Validate a single runs-on value. Handles scalar, array and expression forms."""
    global runner_count
    runner_count += 1
    value = raw.strip()

    # Dynamic / expression runner (matrix, input, var) — cannot be statically proven safe.
    if "${{" in value:
        errors.append(
            f"{rel}: dynamic/expression runner `{value}` cannot be verified; "
            f"pin an approved standard Linux label"
        )
        return

    # Array / group / labels forms.
    if value.startswith("[") or value.startswith("{"):
        inner = value.strip("[]{} ")
        parts = [p.strip().strip("'\"") for p in inner.split(",") if p.strip()]
        low = " ".join(parts).lower()
        if "self-hosted" in low:
            errors.append(f"{rel}: self-hosted runner form `{value}` is prohibited by policy")
            return
        # Every element of the array must be an approved label.
        for p in parts:
            if p and p not in ALLOWED_RUNNERS:
                errors.append(
                    f"{rel}: runner array element `{p}` in `{value}` is not an approved "
                    f"standard Linux runner"
                )
        return

    # key: value map form spread onto following lines (group:/labels:) is caught by the
    # scalar check failing below; also catch the inline `group:`/`labels:` keywords.
    if value.startswith("group:") or value.startswith("labels:"):
        errors.append(f"{rel}: runner group/labels form `{value}` is prohibited by policy")
        return

    label = value.strip("'\"")
    if label.lower() == "self-hosted":
        errors.append(f"{rel}: self-hosted runner is prohibited by policy")
        return
    if label not in ALLOWED_RUNNERS:
        errors.append(f"{rel}: runner `{label}` is not an approved standard Linux runner")


for path in sorted((*WORKFLOWS.glob("*.yml"), *WORKFLOWS.glob("*.yaml"))):
    source = path.read_text(encoding="utf-8")
    rel = path.relative_to(ROOT)

    # ── Runner labels (scalar occurrences; array/expr handled in checker) ──
    for match in re.finditer(r"(?m)^\s*runs-on:\s*(\[[^\]]*\]|[^#\n]+)", source):
        check_runner_label(rel, match.group(1))

    # Also catch the multi-line `runs-on:` followed by `group:`/`labels:` map form.
    if re.search(r"(?m)^\s*runs-on:\s*$", source) and re.search(
        r"(?m)^\s*(group|labels):\s", source
    ):
        errors.append(f"{rel}: runner group/labels map form is prohibited by policy")

    # ── Prohibited triggers ──
    if re.search(r"(?mi)^\s*schedule:\s*$|^\s*-\s*cron:\s*", source):
        errors.append(f"{rel}: scheduled workflows are prohibited; use explicit manual/push policy")
    if re.search(r"(?mi)^\s*repository_dispatch:\s*", source):
        errors.append(f"{rel}: repository_dispatch trigger is prohibited")
    if re.search(r"(?mi)^\s*workflow_run:\s*", source):
        errors.append(f"{rel}: workflow_run trigger is prohibited")

    # ── Cache ──
    if re.search(r"(?i)actions/cache@|cache:\s*(?:npm|pip|composer|gradle|yarn|maven)", source):
        errors.append(f"{rel}: Actions cache requires explicit storage review")

    # ── Packages / container registry / publishing ──
    if re.search(r"(?mi)^\s*packages:\s*write\s*$", source):
        errors.append(f"{rel}: package publishing (packages: write) requires explicit billing review")
    if re.search(r"(?i)docker/build-push-action", source):
        errors.append(f"{rel}: docker/build-push-action (image publishing) requires explicit review")
    if re.search(r"(?i)docker/login-action", source):
        errors.append(f"{rel}: docker/login-action (registry login) requires explicit review")
    if re.search(r"(?i)ghcr\.io", source):
        errors.append(f"{rel}: GitHub Container Registry (ghcr.io) usage requires explicit review")
    if re.search(r"(?i)\bnpm\s+publish\b", source):
        errors.append(f"{rel}: npm publish (package publishing) requires explicit review")

    # ── Permissions: id-token and unexpected write scopes ──
    if re.search(r"(?mi)^\s*id-token:\s*write\s*$", source):
        errors.append(f"{rel}: id-token: write (OIDC) requires explicit review")
    for pmatch in re.finditer(r"(?mi)^\s*([a-z-]+):\s*write\s*$", source):
        key = pmatch.group(1).lower()
        if key in {"packages", "id-token"}:
            continue  # already reported above
        if key not in ALLOWED_WRITE_PERMISSIONS:
            errors.append(f"{rel}: unexpected write permission `{key}: write` (least-privilege)")

    # ── Artifact retention: every upload-artifact must set retention-days <= 14 ──
    upload_iter = list(re.finditer(r"(?i)actions/upload-artifact@", source))
    if upload_iter:
        # Collect all retention-days values declared in the file.
        rets = [int(x) for x in re.findall(r"(?m)^\s*retention-days:\s*(\d+)\s*$", source)]
        if not rets:
            errors.append(
                f"{rel}: upload-artifact present but no retention-days set "
                f"(must be <= {MAX_ARTIFACT_RETENTION_DAYS})"
            )
        for days in rets:
            artifact_count += 1
            if days > MAX_ARTIFACT_RETENTION_DAYS:
                errors.append(f"{rel}: artifact retention {days}d exceeds {MAX_ARTIFACT_RETENTION_DAYS}d")

    # Independently flag any retention-days over the cap, even without upload-artifact.
    for match in re.finditer(r"(?m)^\s*retention-days:\s*(\d+)\s*$", source):
        if not upload_iter:
            artifact_count += 1
            if int(match.group(1)) > MAX_ARTIFACT_RETENTION_DAYS:
                errors.append(
                    f"{rel}: artifact retention {match.group(1)}d exceeds {MAX_ARTIFACT_RETENTION_DAYS}d"
                )

    # ── Timeout enforcement per REAL job ──
    for job_name, block in split_top_level_jobs(source):
        has_runs_on = re.search(r"(?m)^\s{4}runs-on:", block) is not None
        is_reusable = re.search(r"(?m)^\s{4}uses:", block) is not None and not has_runs_on
        if is_reusable:
            continue  # reusable-only jobs run on the callee's runner; exempt
        if has_runs_on:
            real_job_count += 1
            if "timeout-minutes:" not in block:
                errors.append(f"{rel}: job `{job_name}` has runs-on but no timeout-minutes")

    # ── Preserve the original Production backup dry-run guard ──
    if "actions/upload-artifact@" in source and rel.as_posix() == ".github/workflows/deploy.yml":
        block_start = source.find("- name: نگهداری بکاپ انتشار واقعی به‌عنوان artifact")
        block_end = source.find("\n      - name:", block_start + 1)
        block = source[block_start : block_end if block_end != -1 else None]
        if block_start == -1 or "if: ${{ !inputs.dry_run }}" not in block:
            errors.append(f"{rel}: Production backup artifact must be disabled during dry-run")


if runner_count == 0:
    errors.append("no runner declarations found")

if errors:
    print("GITHUB_COST_GUARD_FAIL")
    for error in errors:
        print(f"- {error}")
    raise SystemExit(1)

print(
    "GITHUB_COST_GUARD_OK "
    f"workflows={len(tuple(WORKFLOWS.glob('*.yml'))) + len(tuple(WORKFLOWS.glob('*.yaml')))} "
    f"runners={runner_count} real_jobs={real_job_count} "
    f"allowed={','.join(sorted(ALLOWED_RUNNERS))} "
    f"artifact_rules={artifact_count} retention_max={MAX_ARTIFACT_RETENTION_DAYS}d "
    "schedule=none repository_dispatch=none workflow_run=none "
    "cache=none packages=none docker=none npm_publish=none "
    "id_token=none unexpected_writes=none timeouts=enforced"
)
