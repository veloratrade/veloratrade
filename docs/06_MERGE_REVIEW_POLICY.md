# VELORA — Merge Review Policy (Recommendation)

**Status:** RECOMMENDATION — documentation only, no GitHub configuration changed
**Companion to:** `05_BILINGUAL_CHECKLIST.md`, `.github/workflows/quality-gate.yml`
**Audited against live repo (branch ruleset for `main`):** 2026-08-22

---

## Purpose

This document records the current state of `main`'s merge-time protections and
recommends a specific, minimal human-review policy on top of them. It is
**advisory**: nothing in this document changes any GitHub branch protection
rule, required-check list, or reviewer requirement. Any change to the actual
ruleset requires separate, explicit owner approval and a separate, explicit
change (see `docs/README.md` NP-1).

## 1. Current state (verified, read-only, via the GitHub API)

`main`'s branch ruleset currently requires:

- **Automated status check:** `quality-gate (aggregate — release blocker)`
  must pass before merge is allowed. This aggregates all `gate-*` jobs
  (static, contract, auth, security, artifacts, browser, e2e, secrets) — see
  `docs/QUALITY_GATE_MATRIX.md` and `.github/workflows/quality-gate.yml`.
- **No human approval is currently required to merge a pull request into
  `main`**: `required_approving_review_count: 0`, `required_reviewers: []`,
  `require_code_owner_review: false`.
- Deletion and non-fast-forward pushes to `main` are blocked.
- No `CONTRIBUTING.md` or `CODEOWNERS` file exists in the repository today.

Separately, the `production` GitHub *environment* (used only by
`.github/workflows/deploy.yml`) already requires a human reviewer
(`veloratrade`) and restricts deployment to the `main` branch. That
protection fires at **deploy time**, not at **merge time** — it does not
cover changes merged into `main` that are never deployed, or the window
between merge and deploy.

## 2. What is already mandatory (do not weaken)

- Every PR must pass the `quality-gate` aggregate check before it is
  mergeable. This is enforced by GitHub itself (branch ruleset), not by
  convention, and covers: hardcoded-UI freeze, frozen hash keys, route
  contract, brand policy, PR-01 freeze self-tests, localization validation
  (including the PR-08 `mainEntityOfPage` check and the PR-09 route/E2E
  contract), artifact freshness, CSP linkage, auth/session/token contracts,
  and the main-only Playwright dashboard smoke test.
- These automated checks are **non-negotiable** and this document does not
  propose relaxing any of them.

## 3. Recommendation — human review for production-affecting changes

Automated checks verify *known, previously-encoded* rules. They cannot catch
novel design mistakes, unintended scope creep, or judgment calls about
whether a change *should* ship — that is what human review is for. Given the
current `required_approving_review_count: 0`, **no second set of eyes is
currently mandatory for any merge to `main`**, including changes to:

- `api/**` (authentication, sessions, payment/trading logic, mailer)
- `.github/workflows/**` (CI/CD behavior, including the deploy workflows)
- `tools/localization/**` validators themselves (a bug here can silently
  weaken every other guardrail)
- database schema/migrations

**Recommendation:** require **at least 1 approving review** for pull
requests before merge into `main`, scoped initially to the paths above if a
blanket requirement is judged too heavy for a single-maintainer repository.
GitHub branch rulesets support this either as a repository-wide
`pull_request` rule change (`required_approving_review_count: 1`) or,
alternately, as a lighter-weight `CODEOWNERS`-based path scoping if only
sensitive paths should require review. Either approach preserves the
existing mandatory automated checks unchanged.

This is a recommendation only. Implementing it requires:
1. Explicit owner decision on scope (blanket vs. path-scoped).
2. A separate, explicit GitHub ruleset change (not covered by this
   documentation change).
3. Awareness that a single-maintainer repository may need a documented
   exception process (e.g. self-approval permitted for solo maintenance
   windows) so the rule does not become a self-lockout.

## 4. Recommended merge-method policy

The current ruleset permits `merge`, `squash`, and `rebase` merge methods
interchangeably. PR-07/PR-08 (PR #27) were merged with a standard merge
commit, preserving both original commits' identity and messages intact —
this is the pattern this document recommends continuing, because it keeps
the commit history traceable to the PR-numbered documentation convention
already used throughout `docs/`. Squash/rebase are not inherently unsafe,
but mixing methods across PRs makes `git log` harder to correlate with PR
numbers referenced in this documentation set. No GitHub setting change is
proposed here; this is a documented convention for contributors to follow.

## 5. Summary

| Protection | State today | This document's recommendation |
|---|---|---|
| Automated checks (`quality-gate` aggregate) | ✅ Mandatory, enforced by GitHub | Keep as-is — do not weaken |
| Human approving review before merge | ❌ Not required (`count: 0`) | Recommend requiring ≥1, scope TBD by owner |
| `CODEOWNERS` | ❌ Does not exist | Recommend adding if path-scoped review is chosen |
| Merge method | Any of merge/squash/rebase permitted | Recommend standardizing on merge commits (convention only) |
| Production deploy review | ✅ Already required (`production` environment reviewer) | Keep as-is |

---

*Created as part of the PR-09 architecture-hardening phase (documentation
only). No branch protection rule, required-check list, or reviewer
requirement was changed by this document.*
