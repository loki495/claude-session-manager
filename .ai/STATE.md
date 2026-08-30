# STATE.md — Agent feature parity

- **Current objective:** Bring all four agents (Claude Code, Antigravity, OpenCode,
  Codex) up to parity on the features they all share, per Andres's request. Work
  one gap at a time: explain, present options, get a decision, implement — per the
  global "audit-style work" rule (CLAUDE.md).
- **Current step:** Task 1 done, reviewed, committed (714c467). Task 2 done,
  reviewed, committed (1f2e903). Task 3 (revised A3, expanded scope: cwd AND
  title for archived Antigravity/OpenCode/Codex sessions, both
  archived_session_history() and archived_session_detail()) done, reviewed,
  clean full-suite verified. Ready to commit.
- **Worker status:** R1 (Codex parity audit) done. Task 1 done and committed.
  Task 2 done and committed — first-round worker (Claude Code Agent tool,
  general-purpose, Haiku 4.5, in-process) done, code correct, test gap found
  on review; follow-up worker (same agent/tool/model) closed the gap but
  introduced a real tmux-session leak in its new regression test, found and
  fixed directly by the orchestrator; full suite re-verified clean except one
  confirmed pre-existing, unrelated headless-browser flake
  (`test_session_replay_browser.php`, reproduced independently against clean
  master). Task 3: cross-tool Codex worker (`codex exec -m gpt-5.4-mini`,
  non-in-process subprocess, two rounds) — first cross-tool worker launch
  this session, deliberately requested by Andres to test the orchestrator
  script's multi-tool path. Both rounds did real, correct, valuable work
  (found a genuine scope gap — OpenCode's resolved "path" is a DB id, not a
  file — and, via honest test-writing, a second one — OpenCode's archived
  title had the same bug), but **both launches were discovered still
  running in the background well after the harness reported them
  "completed"** (a `nohup ... &` detachment issue, not specific to Codex —
  worth watching for with any cross-tool worker launched this way). This
  caused a real file-write race that corrupted PLAN.md's status section
  until the orchestrator manually reconciled it, and made the worker's own
  "full suite exit 1" report untrustworthy (likely two concurrent
  `tests/run.sh` runs colliding on the same isolated tmux socket — did not
  reproduce on a clean, verified-single-process re-run). See RESULT.md's
  "Process leak" note for the practical mitigation used
  (`pgrep -af "codex exec"` after every background-launch "completed"
  notification, before trusting no further writes are coming). Two final
  small fixes (Antigravity cwd-is-null-by-design test correction, OpenCode
  title fix) applied directly by the orchestrator rather than a third
  worker round.
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
