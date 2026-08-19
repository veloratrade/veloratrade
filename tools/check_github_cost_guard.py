#!/usr/bin/env python3
"""Fail closed if a workflow introduces a potentially billable GitHub feature."""

from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = ROOT / ".github" / "workflows"
ALLOWED_RUNNERS = {"ubuntu-latest", "ubuntu-24.04", "ubuntu-22.04"}
MAX_ARTIFACT_RETENTION_DAYS = 14

errors: list[str] = []
runner_count = 0
artifact_count = 0

for path in sorted((*WORKFLOWS.glob("*.yml"), *WORKFLOWS.glob("*.yaml"))):
    source = path.read_text(encoding="utf-8")
    rel = path.relative_to(ROOT)

    for match in re.finditer(r"(?m)^\s*runs-on:\s*([^#\n]+)", source):
        runner_count += 1
        label = match.group(1).strip().strip("'\"")
        if label not in ALLOWED_RUNNERS:
            errors.append(f"{rel}: runner `{label}` is not an approved standard Linux runner")

    if re.search(r"(?mi)^\s*schedule:\s*$|^\s*-\s*cron:\s*", source):
        errors.append(f"{rel}: scheduled workflows are prohibited; use explicit manual/push policy")

    if re.search(r"(?i)actions/cache@|cache:\s*(?:npm|pip|composer|gradle)", source):
        errors.append(f"{rel}: Actions cache requires explicit storage review")

    if re.search(r"(?mi)^\s*packages:\s*write\s*$", source):
        errors.append(f"{rel}: package publishing requires explicit billing review")

    for match in re.finditer(r"(?m)^\s*retention-days:\s*(\d+)\s*$", source):
        artifact_count += 1
        days = int(match.group(1))
        if days > MAX_ARTIFACT_RETENTION_DAYS:
            errors.append(f"{rel}: artifact retention {days}d exceeds {MAX_ARTIFACT_RETENTION_DAYS}d")

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
    f"runners={runner_count} allowed={','.join(sorted(ALLOWED_RUNNERS))} "
    f"artifact_rules={artifact_count} retention_max={MAX_ARTIFACT_RETENTION_DAYS}d "
    "schedule=none cache=none packages_write=none"
)
