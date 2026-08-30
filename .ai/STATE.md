# STATE.md — Agent feature parity

- **Current objective:** Bring all four agents (Claude Code, Antigravity, OpenCode,
  Codex) up to parity on the features they all share, per Andres's request. Work
  one gap at a time: explain, present options, get a decision, implement — per the
  global "audit-style work" rule (CLAUDE.md).
- **Current step:** Task 1 (A1: Codex unanswerable question-prompts) done and
  reviewed. Awaiting Andres's direction on which backlog item to tackle next
  (see PLAN.md's "Merged backlog" section for the remaining Tier A/B/C list).
- **Worker status:** R1 (Codex parity audit) done. Task 1 implementation
  worker done, independently reviewed by orchestrator, marked `done` in
  PLAN.md. Nothing committed to git yet — working tree left for Andres to
  review/commit.
- **Worker model:** Research: sonnet, in-process (Agent tool, subagent_type:
  general-purpose) — the audit requires real judgment (reading adapter code and
  matching it against docs/features.md's existing Complete/Partial/Missing/Broken
  taxonomy), not pure lookup. Implementation workers: default to a cheaper tier per
  task once the plan exists; bump up only for genuinely tricky bounded tasks.
- **Important decisions:**
  - The previous `.ai/` contents (OpenCode MVP integration plan, 2026-08-24/25) are
    stale/completed — archived to `.ai/archive/opencode-integration-2026-08-25/`,
    not deleted. Do not resume that plan; it predates this objective.
  - `docs/features.md`'s per-agent matrix is itself known-stale: generated
    2026-08-26, before Codex existed as a 4th agent (added 2026-08-27/29). It has
    no Codex column at all. That's the first gap to close before anything else,
    per the todo file's own Tier 2 item.
  - Known, already-documented parity gaps (Claude/Antigravity/OpenCode) live in
    `todo` (Tier 1 bugs + Tier 3 scoped feature-parity work) and
    `docs/features.md`'s "Known gaps & partial parity" section — treat these as
    reliable starting points, not things to re-derive from scratch, but spot-check
    any that a Codex-inclusive pass might change the shape of.
- **Known limitations:** No implementation started yet.
- **Outstanding blockers:** None yet.
