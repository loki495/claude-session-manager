# STATE.md — Agent feature parity

- **Current objective:** Bring all four agents (Claude Code, Antigravity, OpenCode,
  Codex) up to parity on the features they all share, per Andres's request. Work
  one gap at a time: explain, present options, get a decision, implement — per the
  global "audit-style work" rule (CLAUDE.md).
- **Current step:** Task 1 done, committed (714c467). Task 2 done, committed
  (1f2e903). Task 3 done, committed (29adf02). Task 4 done, committed
  (b0d8337). Task 5 (revised A6: Codex zero coverage in the real health
  check) done, reviewed, clean full-suite verified. Ready to commit.
  This project now lives at `.ai/orchestrator/2026-08-29-agent-feature-parity/`
  (moved 2026-08-30 when the orchestrator-worker skill adopted
  per-orchestration folders — see `.ai/orchestrator/INDEX.md`). A second,
  unrelated orchestration (`.ai/orchestrator/2026-08-30-ui-parity-stop-bug/`)
  is concurrently active in this same project from a different session —
  its files/code changes are not this orchestration's concern and were
  never touched here.
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
  worker round. Task 4: opencode CLI worker (`opencode run --model
  openai/gpt-5.4-mini`, cross-tool) — picked for tool variety after Task 3's
  Codex process-leak issue, per Andres's go-ahead to use any agent/model.
  Launched WITHOUT `nohup ... &` wrapping this time (plain foreground
  command under the Bash tool's own `run_in_background: true` only) —
  confirmed this avoided the leak: `pgrep` after the "completed"
  notification found no stray process. Result: exactly the specified fix
  applied, precise regression test added, clean on independent review and
  a from-scratch full-suite verification. This confirms the double
  detachment (`nohup &` + harness backgrounding) was the real cause of
  Task 3's leak, not something Codex-specific — future cross-tool worker
  launches should use this same plain-foreground pattern. Task 5: first
  attempt via `agy` (Antigravity CLI, `gemini-3.5-flash-medium`) failed
  immediately — account quota exhausted, resets in ~18h, nothing touched.
  Fell back to opencode (`openai/gpt-5.4-mini`), same plain-foreground
  launch pattern. Orchestrator's own pre-delegation investigation found
  the original A6 backlog description was wrong (`check_hooks()` isn't
  wired to the real health box at all — dead code; the actual gap is
  `PushHealthService::health_check()` having zero Codex/Antigravity
  sections) and caught two testability issues in its own draft spec
  (mirroring `opencode_serve_check()` verbatim would make the new check
  as untestable as that one already is; `CodexBridgeClient` needs the
  established DI convention, not a real-socket fixture) before
  delegating — all confirmed correct once the worker's diff came back.
  One complication: `tests/test_agent_adapter.php` was already modified
  by the OTHER concurrent session's own in-progress work before this
  worker touched it. Cleanly separable (distinct hunks) — extracted just
  this task's hunks into a patch and staged them via `git apply --cached`
  without ever writing to the working-tree file, so the other session's
  edits were never touched or included.
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
- **Known limitations:** Tasks 1-5 done and committed. Remaining backlog
  (Tier B, Tier C, H1) all still `Decision: pending` in the merged
  backlog section above.
- **Outstanding blockers:** None.
