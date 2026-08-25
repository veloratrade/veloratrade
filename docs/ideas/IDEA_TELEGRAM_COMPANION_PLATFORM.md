# Velora Telegram Companion Platform

**Status:** Idea / Future Candidate
**Created:** 2026-08-25
**Record type:** Archived idea — **not** a roadmap entry, **not** approved, **not** scheduled, **not** in development
**Owner decision required before any work:** yes

---

## Summary

A future Telegram-based companion layer for Velora allowing traders to capture trades faster,
receive notifications, and eventually interact with AI-powered journaling features.

The concept is a **client surface**, not a new product: Velora's core loop stays
*record → analyze → improve* in the existing web application and API, while Telegram becomes a
fast, mobile place to feed that loop and to receive what it produces. Telegram would be the **first**
channel of a broader companion layer, not a one-off bot.

---

## Strategic Value

- **Reduce trade journaling friction.** Journaling fails mostly because it is slow, not because
  traders do not want it. Capturing a trade in one line from a phone, at the desk where the decision
  was just made, is the single largest lever on journal completeness.
- **Improve retention.** Daily and weekly summaries create a reason to return. A journal that only
  exists behind a login is visited occasionally; one that reports to you is visited habitually.
- **Increase accessibility outside the web dashboard.** Trading happens away from desks. A companion
  surface reaches the moments the dashboard cannot.
- **Prepare future multi-channel interaction.** Building the first channel as an *adapter over a
  shared core* — rather than as a bespoke bot — means any later channel is a configuration, not a
  rewrite.

**Honest framing:** this is a **retention** idea, not an **acquisition** idea. Capture and retention
value are supportable; distribution value is unproven, and revenue value is currently unbillable (see
below). It should not be justified internally as a growth lever.

---

## Why Not Now

Recorded blockers as of 2026-08-25, verified against the repository at the time of writing:

- **AI Insight platform does not exist yet.** No LLM/AI provider is integrated anywhere in the code.
  The web dashboard already advertises "AI Insights" — its readiness signal is a simple
  trade-count test and its action links to a static prototype page with no API call. Shipping an
  AI-labelled companion before the web capability exists would repeat that gap on a channel where the
  failure is public.
- **Web AI capability must be built first.** The rule this idea accepts: *no channel ships a
  capability the web lacks.* Web first, always.
- **Roadmap artifact is currently locked.** `docs/02_ROADMAP.md` does not exist and
  `docs/pdf/Roadmap.pdf` is `LOCKED / DO NOT USE` under backlog item **B-6** in `docs/README.md`,
  pending owner edit. There is therefore no roadmap to place this idea on, and no documented MVP
  scope to sequence it against.
- **Monetization infrastructure is not ready.** There are no plan, subscription, invoice, or payment
  tables and no entitlement code. Any tiered companion offering would have nothing to bill against.
- **Channel architecture is not ready.** There is no channel abstraction, no channel-aware
  notification preference model, and no outbound delivery ledger for messaging.

---

## Possible Future Placement

- **Product direction:** *Companion Platform* — a channel-agnostic companion layer, of which Telegram
  is the first candidate channel.
- **Candidate placement:** after core product maturity. Specifically, after the core journal loop is
  stable and after a real AI insight capability exists and has been proven on the web.
- **Engineering timing must be decided later, after roadmap ratification.** No phase number is
  claimed here. Phase numbering in this repository is already in active use for delivered engineering
  work, so assigning one to an unapproved idea would create a false commitment and a possible
  collision. Timing is deliberately left open until `docs/02_ROADMAP.md` exists.

---

## Dependencies

All of the following would need to exist first:

- **Real AI Insight system** — provider integration, structured (schema-validated) output, cost
  governance, and persisted insights. Not merely a UI surface.
- **Migration runner** — the repository has no automatic migration runner; migrations are applied
  manually, and one existing migration is recorded as incompatible with MySQL 8 grammar.
- **Notification architecture** — a channel-aware preference model. Today's preference storage is a
  small set of email-only boolean flags with no concept of a channel.
- **Media storage** — a place to hold chart screenshots and a safe way to serve them. There is
  currently no media table, and the API's storage path is not web-accessible by design.
- **Channel-aware preferences** — per-channel, per-category opt-in with quiet hours, so a companion
  informs rather than spams.
- **Security hardening** — scoped/audience-bound tokens, an idempotency contract for writes, webhook
  ingress verification, media validation, and a binding flow that never puts credentials in chat.
- **Monetization foundation** — plans, entitlements, quotas, and a payment rail that works for the
  target market.

---

## Non-Goals

This idea will **not** become:

- **A trading signal service.** No "what to buy" output. Legal exposure, and contrary to the product's
  identity as a journal.
- **A copy trading system.** No mirroring of one trader's positions to another's account.
- **Autonomous trade execution.** No automated writes. Anything recorded requires explicit user
  confirmation — one hallucinated number in a financial journal ends trust permanently.
- **Telegram as the source of truth.** The channel stores nothing authoritative. Deleting every
  channel-side record and rebuilding from the API must leave the product identical.
- **Public sharing of private trading data.** No group or public broadcast of P&L. Any personal
  journal channel would have to be owner-verified and private, with publication reversible.

---

## Decision Status

**This document only preserves the idea.**

- No implementation is approved.
- No roadmap commitment exists.
- No phase, milestone, or date is assigned.
- Nothing in this file authorises code, schema, configuration, workflow, or infrastructure work.
- Future adoption requires product review and an explicit owner decision.

The correct next step is not development — it is the prerequisite work listed under *Dependencies*,
most of which is valuable whether or not this idea is ever adopted.

---

## Retrieval

Revisit this idea when **all** of the following are true:

1. `docs/02_ROADMAP.md` exists and the roadmap is ratified (B-6 resolved).
2. A real AI insight capability is live on the web, with measured positive retention.
3. A migration runner exists and has been used without incident.
4. A channel-aware notification model and media storage exist.
5. A monetization foundation exists, if the idea is to be monetised.

Until then this record is deliberately inert.

---

*Archived 2026-08-25. Documentation only — no code, schema, environment value, workflow, or
configuration was created or modified by adding this file. Located under `docs/ideas/`, following the
existing `docs/incidents/` precedent for non-numbered, dated records inside `docs/`; excluded from the
production package by `docs/04_STRUCTURE_COMPLIANCE_CHECKLIST.md` §4.*
