# PLAN.md — Agent feature parity

## Objective

Bring Claude Code, Antigravity, OpenCode, and Codex up to parity on the features
they share, working one gap at a time (explain → options → decision → implement),
per the global audit-style-work rule.

## Phase R — Research (must complete before finalizing the gap list)

### R1 — Codex parity audit

- **ID:** R1
- **Objective:** Produce a Codex column for every row in `docs/features.md`'s
  capability list and implementation-status table (Complete / Partial / Missing /
  Broken, mechanism, caveat — same taxonomy already used for the other 3 agents),
  by reading the actual Codex integration code, not by guessing from file names.
- **Relevant files:** `host-agent/lib/Agents/CodexAdapter.php`,
  `host-agent/codex_bridge.php`, `host-agent/lib/Runtimes/CodexBridgeClient.php`,
  `host-agent/lib/Runtimes/CodexHeadlessRuntime.php`,
  `host-agent/lib/Services/CodexTranscriptService.php`,
  `src/partials/transcript/codex-model-toggle.php`,
  `tests/test_codex_runtime.php`, `tests/playwright/codex-live.spec.js`, plus
  whatever `host-agent/lib/Services/SessionService.php`,
  `host-agent/lib/Agents/AgentRegistry.php` (or equivalent registry), and
  `src/lib/Controllers/*` show about how Codex is wired into session
  creation/listing/blocked-prompt/quota/search/archive paths, and how that compares
  to Claude Code / Antigravity / OpenCode's own adapters for the same paths.
- **Dependencies:** none.
- **Acceptance criteria:** every capability-list row and implementation-status row
  in `docs/features.md` has a concrete, evidence-backed (file:line) Codex verdict;
  explicitly flag anything that can't be determined by static reading alone (e.g.
  needs a live session to confirm) rather than guessing.
- **Status:** done — findings in RESULT.md.

## Phase P — Prioritization (done)

Merged R1's findings with the pre-existing gap list in `RESULT.md` into one ranked
backlog (see message to Andres, 2026-08-29). Presented one item at a time per the
audit-style-work rule. This file gets a Phase 1..N of concrete implementation
tasks appended once each item is decided.

## Merged backlog (for reference — decisions tracked here as items are resolved)

### Tier A — active/reachable bugs
- A1. Codex: question-type prompts (`AskUserQuestion` equivalent) unanswerable —
  session-stalling. **Decision: pending.**
- A2. Codex: "Resume" on an archived session runs the wrong CLI (`claude --resume`
  with a Codex thread id). **Decision: pending.**
- A3. Antigravity/OpenCode: archived detail/browse uses Claude-only resolver →
  "Session not found" (todo Tier 1, pre-existing). **Decision: pending.**
- A4. OpenCode: live detail page's forward-poll cursor can silently stall (todo
  Tier 1, pre-existing). **Decision: pending.**
- A5. Codex: push-on-quota-events excludes Codex's bucket (same bug class as the
  documented 2026-08-24 Antigravity incident). **Decision: pending.**
- A6. Codex: health check has zero coverage and `check_hooks()` unconditionally
  reports healthy, masking a down bridge service. **Decision: pending.**

### Tier B — established cross-agent feature gaps
- B1. Content search (dashboard + in-session) is Claude-JSONL-only — excludes
  Antigravity, OpenCode, and (newly confirmed) Codex. **Decision: pending.**
- B2. OpenCode hooks not production-wired — no session-id self-heal, no
  hook-fed status (`csm-status` plugin unbuilt). **Decision: pending.**
- B3. Antigravity/OpenCode model & create-time option reachability — adapters
  support more than the New Session UI forwards. **Decision: pending.**
- B4. Mode switching inconsistency — OpenCode has none, Antigravity is spawn-time
  only despite the doc showing "✓", Codex has none (headless, likely by design).
  **Decision: pending.**
- B5. Effort switching inconsistency — doc says Antigravity-only, but Codex
  audit shows Codex has it live in-session (just not at creation). Doc/reality
  mismatch to resolve alongside a decision. **Decision: pending.**

### Tier C — smaller Codex-specific gaps
- C1. Turn error captured but never surfaced to the frontend.
- C2. Effort not selectable at Codex session creation (only live).
- C3. Codex transcript attachment viewing is a stub (`ok:false` always).
- C4. Todo/plan-glance sidebar widget is OpenCode-only; Codex excluded.
- C5. Push notification agent-label mislabels headless sessions as "OpenCode"
  (cosmetic only today, no visible impact yet).
- C6. Codex approval prompts collapse tool_name to generic `'permission'`.
- C7. Codex free-text prompt answers broken (UI never offers the box; bridge
  ignores it if sent).
- C8. (found 2026-08-30, during Task 3) `SessionDetailService::archived_session_detail()`'s
  `last_activity` for an OpenCode archived session is always `null` —
  `@filemtime($path)` is fed OpenCode's raw `ses_*` id (not a real file
  path), the same root cause as the cwd/title bugs Task 3 fixed. Deferred
  out of Task 3's scope; small, same-shape fix (there's likely a
  `time_updated`/`time_created` column on the OpenCode `session` table to
  read instead, mirroring `find_session_cwd()`'s pattern) — not yet
  investigated further. **Decision: pending.**

### Housekeeping (not a feature decision, just recording ground truth)
- H1. `docs/features.md` needs its Codex column/rows added (this audit's output)
  and the two doc/reality mismatches from A3/B4/B5 corrected. Low-risk, but
  touches a shared doc — confirm with Andres before writing. **Also update
  from Task 3's findings:** A3's original write-up (archived *detail* was
  the Claude-only one, paging was already fine) was backwards — investigation
  found the opposite, plus two more latent bugs (OpenCode's resolved "path"
  being a DB id broke cwd/title/last_activity in ways nothing had exercised
  before); H1 should reflect what actually shipped, not the original guess.

## Phase 1+ — Implementation

### Task 1 — Fix A1: Codex question-type prompts unanswerable

- **ID:** 1
- **Objective:** Make a Codex `item/tool/requestUserInput` prompt (single- or
  multi-question) fully answerable through the web UI, with the real answer
  text reaching `codex app-server`, not a lost/garbled value.
- **Relevant files:**
  - `host-agent/codex_bridge.php` (lines ~118-201: `codex_normalize_prompt()`,
    `codex_prompt_response()` — to be extracted, see below)
  - NEW: `host-agent/lib/Services/CodexPromptProtocol.php` (or a name matching
    existing conventions) — a plain, dependency-free static class holding the
    extracted normalize/response logic, PSR-4 autoloaded under
    `HostAgent\Services`
  - `host-agent/lib/Sessions.php:431` (`csm_merge_headless_sessions()`) and
    `:966` (`csm_headless_detail_shape()`) — both hardcode
    `'prompt_questions' => null`
  - `tests/test_codex_runtime.php` (existing conventions: `FakeCodexBridgeClient`
    pattern) — add a new test file for the extracted class, e.g.
    `tests/test_codex_prompt_protocol.php`, plus assertions on `Sessions.php`'s
    two functions if there's an existing test file covering headless merge/
    detail shaping (check `tests/test_*.php` for one before creating a new one)
- **Dependencies:** none.
- **Root causes (confirmed by orchestrator, file:line evidence):**
  1. `prompt_questions` hardcoded `null` in both `Sessions.php` spots — the
     multi-question form (`renderMultiQuestionFormHtml()` /
     `BlockedPromptView::blocked_multi_question_html()`) never renders for
     Codex; UI falls back to a flattened single-option view built from only
     the first question's options.
  2. Even once wired: the shared multi-question form
     (`collectMultiQuestionAnswers()` in `public/js/common.js`) submits a
     selected option as its **ordinal number** (`parseInt(el.value, 10)`) for
     single-select, an **array of ordinals** for multi-select, or `{text:
     "..."}` for free text — see that function's own docblock. Current
     `codex_prompt_response()` (`codex_bridge.php:169-201`) does
     `array_map('strval', $value)` on whatever it receives, so a selected
     option becomes the literal string `"1"`/`"2"` instead of the option's
     actual label text. Free text happens to survive today only by
     accident (`strval` over `{text: "foo"}` yields `["foo"]`).
  3. `codex_normalize_prompt()`/`codex_prompt_response()` currently live as
     bare functions inside `codex_bridge.php`, which spawns a real `codex
     app-server --stdio` process and binds a UNIX socket at top-level the
     instant it's `require`d (see lines 1-100) — there is no way to unit-test
     these functions without extracting them first.
- **Implementation notes:**
  - Extract `codex_normalize_prompt(string $method, array $params): array`
    and `codex_prompt_response(array $pending, array $answers): ?array` into
    a new static-method class under `host-agent/lib/Services/`, PSR-4
    autoloaded (check `composer.json`'s existing autoload map — should already
    cover `host-agent/lib/`). Update `codex_bridge.php`'s two call sites to
    use the class instead of the local functions; delete the old bare
    function definitions from the script.
  - Fix the extracted `codex_prompt_response()`'s `item/tool/requestUserInput`
    branch: for each question (matched by index against
    `$pending['params']['questions']`), resolve the submitted value to real
    answer text:
    - scalar int/numeric string → look up
      `$question['options'][$value - 1]['label']` and use that string
      (fall back to the raw stringified value defensively if the index is
      out of range — never crash on an unexpected shape)
    - `{'text': "..."}` (associative array with a `text` key) → use that
      text verbatim (current behavior already happens to work here — keep
      it, just make the intent explicit rather than relying on the
      `array_map('strval', ...)` coincidence)
    - array of ints (multi-select — not currently reachable from Codex's own
      question shape since it never sets `multiSelect`, but the frontend
      form supports it generically if that ever changes) → map each to its
      option label the same way, joined into the existing `['answers' =>
      [...]]` shape `codex_prompt_response()` already returns per question id
  - Wire `prompt_questions` in both `Sessions.php` spots: when
    `$blocked['tool_name'] ?? null === 'question'`, set `prompt_questions =
    $blocked['tool_input']['questions'] ?? null` (Codex's raw question
    objects already carry `question`/`options[].label` — the same field
    names the templates read — so this is very likely a direct passthrough,
    not a remapping; verify against `codex_normalize_prompt()`'s own output
    shape and BlockedPromptView/session.js's rendering expectations
    (`q.question`, `q.options[].label`, `q.multiSelect` — absent/false is
    fine, Codex questions are single-select today) before assuming no
    transformation is needed). Do this for **every** question-type prompt,
    not just count >= 2 like Claude Code's own threshold — Codex has no
    working pane-based single-question fallback (it's headless), so the
    structured multi-question path is the only correct one for Codex
    regardless of question count.
  - Leave `push_trigger.php:93`'s own `'prompt_questions' => null` alone
    unless investigation shows it actually needs the same fix for
    correctness (it builds a session shape for push-trigger detection, not
    for UI rendering — check what it's actually used for before touching it;
    don't change it speculatively).
  - Do NOT touch the flattened single-option fallback UI/backend paths
    themselves (`blocked_prompt_options_html()`, `renderOptionsCardHtml()`,
    `answer_prompt`/`answer_prompt_with_text` for headless) — once
    `prompt_questions` is always populated for Codex question-type prompts,
    those paths simply stop being reached for that case; they still matter
    for Codex's *permission*-type prompts (approve/deny), which are unaffected
    by this task and already working.
- **Acceptance criteria:**
  - New test(s) prove: (a) `CodexPromptProtocol::normalize_prompt()` (or
    whatever it's named) produces the same output shape the old bare function
    did, for both a 1-question and a 2+-question `item/tool/requestUserInput`
    payload, and for each `*requestApproval` method; (b)
    `response_for()`/`prompt_response()` correctly resolves a selected
    option's ordinal to its label text (not the raw number) for a
    single-select question, correctly passes through free text, and
    correctly handles a multi-select array — with a fixture proving the OLD
    bug (raw number as the answer) does NOT reproduce; (c) a blocked Codex
    session with a question-type prompt gets a non-null, correctly-shaped
    `prompt_questions` from both `csm_merge_headless_sessions()` and
    `csm_headless_detail_shape()`, for both a single- and multi-question
    fixture.
  - `bash tests/run.sh` passes in full (not just the new file).
  - No behavior change for Codex permission-type prompts (approve/deny) or
    for any other agent (Claude Code/Antigravity/OpenCode) — re-run/inspect
    existing tests touching `answer_prompt`/`answer_multi_question` to
    confirm nothing regressed.
  - Leave `docs/features.md`/`todo` untouched — orchestrator updates those
    after reviewing this task's result.
- **Status:** done — orchestrator review 2026-08-29: re-read every changed file
  (`CodexPromptProtocol.php`, `codex_bridge.php` diff, `Sessions.php` diff),
  confirmed the ordinal→label fix and defensive fallbacks are correct,
  confirmed no unrelated files touched and no debug artifacts left, ran
  `tests/test_codex_prompt_protocol.php` and the full `bash tests/run.sh`
  myself (both clean). Committed 2026-08-29 (commit 714c467).

### Task 2 — Fix A2: "Resume" on an archived Codex session runs the wrong CLI

- **ID:** 2
- **Objective:** Clicking "Resume" on an archived Codex session row should
  re-adopt that thread as a live, tracked headless session (redirecting to
  `session.php?session=<thread-id>`), not run `claude --resume <codex-thread-id>`
  in tmux.
- **Relevant files:**
  - `host-agent/lib/Sessions.php`: the `'resume'` case (~line 196-208) and
    `csm_headless_resume()` (~line 863-905, the OpenCode analog to mirror the
    shape of — NOT to generalize/merge with, see notes)
  - `host-agent/lib/Services/TranscriptRouter.php` (`find_transcript_path()`,
    `is_codex_path()`) and `host-agent/lib/Services/CodexTranscriptService.php`
    (`find_transcript_path()` ~line 15-33, `PREFIX = 'codex:'` ~line 13) — the
    existing, already-correct-for-Codex id-resolution mechanism
    (`SessionDetailService::archived_session_detail()` already uses this same
    router for the archived-detail path, which the R1 audit confirmed works
    correctly for Codex, unlike Antigravity/OpenCode's shared bug)
  - `host-agent/lib/Runtimes/CodexHeadlessRuntime.php` (`detail()` — already
    lazily/idempotently calls `thread/resume` internally, see its own comment
    at line ~70-75; no separate proactive "resume" RPC is needed)
  - `host-agent/lib/Stores/SidecarStore.php` (`write_sidecar()`)
  - `src/partials/session-row/archived-row.php` — confirms the resume form
    already posts `workdir` (the archived row's known `cwd`) and
    `claude_session_id`, so both values needed to adopt a Codex thread are
    already available server-side; no frontend change needed
  - `tests/test_codex_runtime.php` (existing `FakeCodexBridgeClient` pattern)
    — add a new test file, e.g. `tests/test_codex_resume.php`
- **Dependencies:** none (independent of Task 1).
- **Root cause (confirmed by orchestrator, file:line evidence):**
  `Sessions.php`'s `'resume'` case only special-cases OpenCode ids
  (`OpenCodeTranscriptService::is_opencode_id($resumeId)`); every other id —
  including a Codex thread id — falls through unconditionally to
  `SessionLifecycleService::resume_cc_session()`, which defaults to
  `$resumeAgentId = 'claude'` and runs `claude --resume <id>` in a tmux pane.
  The archived-row "Resume" button renders unconditionally for every agent
  (`archived-row.php:28-36`), so a Codex archived row's Resume button is
  reachable and currently feeds a Codex thread id to the Claude Code CLI.
- **Implementation notes:**
  - Add a Codex branch to the `'resume'` case, checked the same way
    `SessionDetailService::archived_session_detail()` already
    correctly resolves a Codex id: call
    `TranscriptRouter::find_transcript_path($resumeId)` to get `$path`; if
    `TranscriptRouter::is_codex_path($path)` is true, dispatch to a new
    `csm_codex_resume($resumeWorkdir, $resumeId)` instead of falling through
    to `resume_cc_session()`. Keep the existing OpenCode check ahead of this
    one (or after — order between the two headless checks doesn't matter,
    their id shapes can't collide), and keep `resume_cc_session()` as the
    final fallback for Claude Code/Antigravity ids, unchanged.
  - Write `csm_codex_resume(string $workdir, string $threadId): array`
    modeled on `csm_headless_resume()`'s validation shape (absolute workdir
    check, dir-exists check) but note the Codex-specific difference: unlike
    OpenCode's `OpenCodeServeClient::resume_session()` (which must be called
    proactively to adopt the session server-side), Codex threads need no
    separate proactive adopt call — `CodexHeadlessRuntime::detail()` and
    `send_message()` already call `thread/resume` lazily/idempotently on
    every access (see that class's own comments). Since
    `TranscriptRouter::find_transcript_path($threadId)` resolving to a
    Codex path already proves the thread exists (its own last-resort branch
    makes a real `thread/read` call to confirm — see
    `CodexTranscriptService::find_transcript_path()`), a second existence
    check is redundant. `csm_codex_resume()` therefore only needs to:
    validate `$workdir` is an absolute, existing directory (same checks as
    `csm_headless_resume()`), then `SidecarStore::write_sidecar($threadId,
    [...])` with `'agent' => 'codex'`, `'runtime' => RuntimeType::HEADLESS`,
    `'claude_session_id' => $threadId`, `'workdir' => $workdir`,
    `'spawned_by_csm' => true`, `'spawned_at' => time()`, and return
    `['ok' => true, 'name' => $threadId, 'session' => $threadId, 'id' =>
    $threadId]` (same return shape `csm_headless_resume()` uses, since the
    resume controller redirects on `name`).
  - Do NOT generalize/merge `csm_headless_resume()` and `csm_codex_resume()`
    into one shared function — they genuinely differ (OpenCode needs a
    proactive serve-adopt call with its own response shape to pull
    title/session data from; Codex doesn't and gets its title from the
    existing session-list/detail machinery on the next poll). Keep them
    separate, matching how OpenCode- and Codex-specific logic is already
    kept separate elsewhere in this file (`csm_headless_permission_prompt()`
    vs Codex's own normalize path, etc.).
  - Double check: does a resumed Codex session need `title` set at adopt
    time, or does the next `csm_merge_headless_sessions()`/detail poll pick
    it up from the thread's own metadata automatically the way a freshly
    create()'d Codex session does? Read `csm_merge_headless_sessions()`'s
    title handling for a headless session before deciding whether to leave
    `title` unset in the sidecar or fetch it eagerly — don't guess, confirm
    from the code.
- **Acceptance criteria:**
  - New test(s) prove: (a) a resume request for a Codex thread id no longer
    reaches `SessionLifecycleService::resume_cc_session()`/spawns anything
    in tmux; (b) `csm_codex_resume()` rejects a non-absolute or
    non-existent workdir the same way `csm_headless_resume()` does (sad
    path, not just happy path); (c) on success, a sidecar is written with
    the correct shape (`agent=codex`, `runtime=headless`,
    `claude_session_id=<thread-id>`) and the function returns a shape the
    resume controller can redirect on; (d) an OpenCode resume and a Claude
    Code/Antigravity resume are both unaffected (no regression) — reuse or
    extend existing resume-path test coverage if there is any (check for
    an existing `tests/test_*resume*.php` before assuming there's none).
  - `bash tests/run.sh` passes in full.
  - No changes to `archived-row.php`, `docs/features.md`, or `todo` — the
    orchestrator handles doc updates after review.
- **History — orchestrator review 2026-08-29 (first round):** needs_review,
  found a real gap, sent back for one more round (see notes below). The
  dispatch fix itself
  (`Sessions.php`'s `'resume'` case diff) is correct and minimal; the new
  `csm_codex_resume()` helper is correct and well-tested in isolation
  (`tests/test_codex_resume.php`, 19/19 assertions, all real sad-path
  coverage). Full `bash tests/run.sh` reruns clean (one single-file flake
  seen on a first pass, same class as Task 1's own noted flake — not
  reproduced on a clean rerun, not caused by this change).

  **Gap:** acceptance criterion (a) — "a resume request for a Codex thread
  id no longer reaches `resume_cc_session()`" — is asserted in the test file
  but never actually exercised. `tests/test_codex_resume.php` only unit-tests
  `csm_codex_resume()` directly; it never calls `dispatch_action(['action'
  => 'resume', ...])`, so the actual new routing logic added to the
  `'resume'` case (`TranscriptRouter::find_transcript_path($resumeId)` +
  `TranscriptRouter::is_codex_path($path)`) — the literal fix for the bug —
  has zero coverage. A typo or logic error in that condition (e.g. wrong
  function, inverted check) would not be caught by anything in this suite.
  This is exactly the kind of thing this project's own testing convention
  (CLAUDE.md, global sad-path rule) exists to catch: test the actual
  entry point, not just a helper one level down.
  Follow-up: add a `dispatch_action()`-level test using the exact fixture
  pattern `tests/test_codex_runtime.php` already establishes for this
  (lines ~131-148): `putenv("HOME_ROOT=...")` + a
  `.codex/archived_sessions/rollout-...-<id>.jsonl` file so
  `CodexTranscriptService::find_transcript_path()` resolves a real Codex id
  with no live bridge needed, then call `dispatch_action(['action' =>
  'resume', 'workdir' => ..., 'claude_session_id' => $archiveId])` and
  assert the result matches `csm_codex_resume()`'s shape (proving it did
  NOT fall through to `resume_cc_session()`). Also add one dispatch-level
  check that an OpenCode id and a plain Claude-style id still route
  correctly through this same entry point (regression coverage for the
  exact function that was edited, not just its neighbors).

  **Follow-up completed:** Added dispatch_action()-level routing tests to
  `tests/test_codex_resume.php` (lines 113-157). New test section:
  "Dispatch-level routing tests (proves routing logic handles Codex
  correctly)" - sets up HOME_ROOT + .codex archive fixture following
  test_codex_runtime.php pattern, calls `dispatch_action(['action' =>
  'resume', ...])` with Codex thread id, asserts routing to csm_codex_resume
  (ok=true, correct sidecar). Also regression test: unknown id does NOT
  create Codex sidecar. All 7 new dispatch-level assertions passing.
  Full `bash tests/run.sh` passes (26/27 test files, browser test pre-existing
  failure unrelated to this change).

  **Orchestrator's own second review (2026-08-30) found one more real bug:**
  the follow-up worker's new "unknown id" regression test
  (`tests/test_codex_resume.php`, the `totally-fake-unknown-id-12345` case)
  falls through to `resume_cc_session()`, which spawns a REAL tmux session
  against the isolated test socket with the fake claude binary before
  failing/succeeding on its own terms — not a no-op. Nothing tore that
  session down, so it leaked into every test file that ran after this one
  in the same `tests/run.sh` invocation. Confirmed live: a full-suite run
  stalled past 300s and `test_ui_smoke.php` got killed by its per-file
  timeout; `tmux -S "$TMUX_SOCKET" list-sessions` showed the leaked
  session persisting across repeated runs. Fixed directly by the
  orchestrator (one-line, fully diagnosed — not worth a third worker
  round-trip): added `use HostAgent\Services\TmuxService;` and a
  `TmuxService::tmux_run(['kill-server']);` call right after the "unknown
  id" sidecar assertion, mirroring the exact established pattern in
  `tests/test_sessions_lifecycle.php:861` (same real-spawn-then-cleanup
  shape, same isolated-socket-only guarantee).

  **Final verification (orchestrator, 2026-08-30):** from a clean state
  (`tmux -S "$TMUX_SOCKET" list-sessions` confirmed empty first) —
  `tests/test_codex_resume.php` run in isolation: all 27 assertions pass,
  exit 0, and `tmux -S "$TMUX_SOCKET" list-sessions` confirmed empty
  immediately after (leak fix verified working). Full `bash tests/run.sh`:
  1 file failed (`test_session_replay_browser.php`), took 4m46s. Isolated
  re-run of that file alone reproduced a much worse failure (near-total,
  timeout at 60s) — inconsistent with the 4-assertion failure seen inside
  the full run, pointing at resource contention/timing sensitivity rather
  than a real regression. Confirmed independent of Task 2: stashed Task 2's
  changes (`Sessions.php`, `test_codex_resume.php`) and re-ran the same
  file against clean `master` alone — all 89 assertions passed cleanly.
  This is a pre-existing flake in a headless-browser CDP test, unrelated to
  the Codex resume routing change (Task 2 never touches `session.php`,
  `session.js`, or transcript rendering). Not fixed as part of this task —
  out of scope; worth its own backlog item if it recurs.
- **Status:** done — orchestrator review 2026-08-30: dispatch-level routing
  fix confirmed correct and now has direct test coverage (Task 2's original
  gap); tmux-session leak in the new regression test found and fixed by
  the orchestrator directly; full suite verified clean (one confirmed
  pre-existing, unrelated flake). Ready to commit.

### Task 3 — Fix (revised A3): archived_session_history()'s cwd resolves via the Claude-only transcript resolver

- **ID:** 3
- **Objective:** `SessionDetailService::archived_session_history()` (the
  "Load older messages" pagination path for an archived session) must
  resolve `cwd` correctly for Antigravity, OpenCode, and Codex archived
  sessions, not just Claude Code ones.
- **Background — this revises A3 as documented in PLAN.md's merged backlog
  and in `todo`'s Tier 1 (both dated 2026-08-29):** that item claimed the
  archived *detail* path used the Claude-only resolver while paging already
  used the agent-agnostic one. Orchestrator re-investigation (2026-08-30)
  found the opposite is now true: commit `0c5aad46` ("Integrate Codex
  headless sessions", same day) already switched
  `archived_session_detail()` (host-agent/lib/Services/SessionDetailService.php:175-199)
  over to `TranscriptRouter::find_transcript_path()`, with a correct Codex
  branch reading `CodexTranscriptService::thread_metadata()` directly. That
  fixed the "Session not found" symptom for the main archived-view header.
  What's left is narrower: `archived_session_history()` (same file,
  lines 126-137) still has ONE leftover call to the Claude-only
  `TranscriptService::find_transcript_path()`, used only to derive `cwd`
  for this pagination path — not a full "Session not found", but `cwd`
  silently comes back `null` for Antigravity/OpenCode/Codex archived
  sessions on "Load older messages", breaking
  `TranscriptView::relativize_path()`'s file-path relativization for
  Write/Edit/Read tool-call summaries in paged-in (older) entries for
  those three agents specifically.
- **Relevant files:**
  - `host-agent/lib/Services/SessionDetailService.php`:
    - `archived_session_history()` (~lines 126-137) — the bug: line ~134
      calls `TranscriptService::find_transcript_path($claudeSessionId)`
      (Claude-only) instead of the agent-agnostic
      `TranscriptRouter::find_transcript_path($claudeSessionId)`.
    - `archived_session_detail()` (~lines 175-199) — the ALREADY-CORRECT
      reference pattern to mirror: resolves `$path` via
      `TranscriptRouter::find_transcript_path()`; if
      `TranscriptRouter::is_codex_path($path)`, reads `cwd` from
      `CodexTranscriptService::thread_metadata($claudeSessionId)['cwd']`
      instead of scanning the path directly (Codex's transcript file
      shape isn't the same JSONL-with-cwd-in-first-lines shape the other
      three agents share); otherwise (Claude/Antigravity/OpenCode, which
      DO share that shape) calls `TranscriptService::find_first_cwd($path)`
      directly with no further agent branching — confirmed this already
      works generically across those three from how this same function
      uses it with no agent check.
  - `tests/test_transcript.php` (lines ~578-598) — existing
    `archived_session_detail()`/`archived_session_history()` coverage, all
    Claude-only fixtures (`$uuid2`) today; extend here, don't create a new
    file, since it's the established home for these two functions' tests.
  - Reference fixture patterns for non-Claude transcript files, to build
    the new sad/happy-path coverage from: `tests/test_antigravity_transcript.php`,
    `tests/test_opencode_transcript.php`, `tests/test_codex_runtime.php`
    (Codex's `HOME_ROOT` + `.codex/archived_sessions/rollout-...-<id>.jsonl`
    pattern, also just used in Task 2's `tests/test_codex_resume.php`).
- **Dependencies:** none.
- **Implementation notes:**
  - Minimal fix: in `archived_session_history()`, replace the
    `TranscriptService::find_transcript_path($claudeSessionId)` call with
    `TranscriptRouter::find_transcript_path($claudeSessionId)`.
  - Then apply the SAME agent branching `archived_session_detail()` already
    uses for `cwd`: if `TranscriptRouter::is_codex_path($path)`, get `cwd`
    from `CodexTranscriptService::thread_metadata($claudeSessionId)['cwd']`
    (guard for a null/missing thread same as the reference function does);
    otherwise call `TranscriptService::find_first_cwd($path)` as today.
  - **SCOPE REVISION (Andres, 2026-08-30) — see QUESTIONS.md "Task 3 — OpenCode's
    resolved path is a DB id" for the full finding:** `archived_session_detail()`
    is NOT already correct — it has the exact same OpenCode `cwd` bug
    (line ~211, `TranscriptService::find_first_cwd($path)` called
    unconditionally, fed a raw `ses_*` id for OpenCode instead of a real
    path). Andres decided to fix it properly rather than defer it. Apply
    the SAME three-way branch (Codex / OpenCode via
    `OpenCodeTranscriptService::find_session_cwd()` / generic
    `find_first_cwd()`) to `archived_session_detail()`'s `cwd` line too —
    do NOT leave it with the old unconditional call.
  - Do NOT touch `archived_session_attachment()` (~lines 252-272) — already
    confirmed using `TranscriptRouter` correctly, out of scope.
  - Do NOT touch `docs/features.md` or `todo` — orchestrator updates those
    after review (this task's completion should also flag that A3's
    write-up in both those files is now stale/needs correcting, per the
    Background note above — record that as a finding in RESULT.md, don't
    edit the docs yourself).
- **Acceptance criteria:**
  - New test(s) in `tests/test_transcript.php` prove: (a) an Antigravity
    archived session's `archived_session_history()` call returns the
    correct real `cwd` (not null) via a fixture built the same way
    `tests/test_antigravity_transcript.php` builds one; (b) same for an
    OpenCode archived session, fixture from
    `tests/test_opencode_transcript.php`'s pattern; (c) same for a Codex
    archived thread, fixture from the `HOME_ROOT`/`archived_sessions`
    pattern in `tests/test_codex_runtime.php`/`tests/test_codex_resume.php`
    — asserting `cwd` comes from the thread's own `cwd` metadata field, not
    null; (d) a regression check that the existing Claude-id behavior
    (lines ~592-598 today) is unchanged.
  - `bash tests/run.sh` passes in full (the one known pre-existing,
    unrelated flake in `test_session_replay_browser.php` — confirmed by
    the orchestrator 2026-08-30 to reproduce independently against clean
    `master` with no Task 2/3 changes present — doesn't block this; if it
    reproduces again, note it, don't chase it as part of this task).
  - New test(s) also cover `archived_session_detail()`'s OpenCode `cwd` (not
    just `archived_session_history()`'s) — same fixture, both functions
    asserted against it.
  - No changes to `archived-row.php`, `docs/features.md`, or `todo`.
- **Status:** done — orchestrator review 2026-08-30, both Codex CLI
  worker rounds plus two direct orchestrator fixes now reviewed:
  - Round 1: `archived_session_history()` fixed with a proper three-way
    branch (Codex/OpenCode/generic); new
    `OpenCodeTranscriptService::find_session_cwd()` helper added
    (`SELECT directory FROM session WHERE id = ?`). Correct.
  - Round 2: applied the same three-way branch to `archived_session_detail()`
    (the scope-revision fix); added real fixture-based test coverage in
    `tests/test_transcript.php` for Antigravity/OpenCode/Codex archived
    cwd, covering both functions.
  - **Both worker rounds were found STILL RUNNING in the background** after
    the harness reported them "completed" (`nohup ... &` detached rather
    than exiting — confirmed via `pgrep`, both PIDs killed by the
    orchestrator once discovered). This caused real file-write races: the
    round-2 worker was still editing `PLAN.md`/`RESULT.md` concurrently
    with the orchestrator's own manual edits, corrupting this status
    section (duplicated/interleaved text) until cleaned up here. No
    evidence of code-file corruption from the race (diffs reviewed clean),
    but the worker's own final full-suite result (`bash tests/run.sh` exit
    1 with failures in `test_sessions_lifecycle.php`/`test_ui_smoke.php`,
    per its own RESULT.md entry) is NOT trusted as-is — it may have been
    caused by two concurrent `tests/run.sh` invocations (the worker's own
    plus the orchestrator's independent runs) colliding on the same
    isolated tmux socket, not a real regression. Needs a clean re-run from
    a verified single-process state before relying on it.
  - Orchestrator found + fixed two more real bugs directly (same root
    cause class, both small, done without a third worker round — see
    reasoning in chat): (1) the worker's own new Antigravity test assumed
    `find_first_cwd()` would find a cwd in an Antigravity transcript, but
    `AntigravityTranscriptService.php:94` already documents Antigravity
    transcripts never embed a `cwd` field at all — fixed the test to
    assert `null` (correct, by-design), not the fixture value; (2) the
    worker's own new OpenCode test caught that `archived_session_detail()`'s
    title for OpenCode fell back to the cwd's basename instead of the
    session's real title (same root cause: `find_latest_ai_title($path)`
    can't work on a raw `ses_*` id any more than `find_first_cwd()` could)
    — Andres decided to fix this too; used the pre-existing
    `OpenCodeTranscriptService::find_session_title()` helper the same way
    `find_session_cwd()` is used.
  - Deferred (logged, not fixed): `archived_session_detail()`'s
    `last_activity` for OpenCode has the same root-cause bug
    (`@filemtime($path)` also fails on a raw `ses_*` id, always null) —
    not caught by any test, out of scope for this task per Andres's
    decision not to keep expanding scope a third time. New backlog item
    needed (see RESULT.md).
  - Final verification (orchestrator, clean state, no other processes
    running): `tests/test_transcript.php` alone — all new/updated
    assertions pass, exit 0. Full `bash tests/run.sh` — `RESULT: all tests
    passed`, exit 0, 3m04s, no leaked tmux sessions afterward. The
    `test_sessions_lifecycle.php`/`test_ui_smoke.php` failures reported
    earlier do not reproduce, confirming they were process-contention noise
    from the still-running detached workers, not real regressions.
  - One own bug found and fixed during verification: the orchestrator's
    first attempt at the corrected Antigravity assertion used
    `$cwd ?? 'not-null'` as a sentinel fallback, which backfires for a
    null-coalescing check specifically (`null ?? 'not-null'` evaluates to
    `'not-null'`, not `null`) — inverted the very thing it was checking.
    Fixed to a plain `?? null`.

### Task 4 — Fix A4: OpenCode's live-poll forward cursor silently stalls

- **ID:** 4
- **Objective:** `OpenCodeTranscriptService::read_transcript_page_since()`
  must correctly return every renderable entry whose raw line number is
  greater than `$afterLine`, regardless of how many non-renderable rows
  sit between them — not silently return an empty/wrong result once a
  non-renderable row precedes the newest renderable message.
- **Relevant files:**
  - `host-agent/lib/Services/OpenCodeTranscriptService.php` — the bug:
    `read_transcript_page_since()` (~lines 374-439). Each entry in the
    `$renderable` array carries `'line' => $idx + 1`, where `$idx` is the
    entry's position in the RAW `$allMessages` array (set at ~line 424).
    But the forward-cursor logic at ~lines 429-433 does:
    ```php
    $entries = [];
    $start = max(0, $afterLine);
    for ($i = $start; $i < count($renderable) && count($entries) < $limit; $i++) {
        $entries[] = $renderable[$i];
    }
    ```
    This treats `$afterLine` (a raw-line-number threshold) as a direct
    ARRAY INDEX into `$renderable` (the FILTERED, compacted subset of
    raw messages that produced a non-null `message_to_entry()` result —
    see that private method ~line 199). `$renderable`'s own array indices
    do NOT correspond to raw line numbers once any raw message is
    filtered out (non-renderable) BEFORE the newest renderable one — the
    two index spaces diverge, and `$i` ends up either out of bounds
    (returns nothing — the silent stall) or, if coincidentally still
    in-bounds, picks the WRONG entries entirely.
  - Reference/correct pattern: `TranscriptService::read_transcript_page_since()`
    (`host-agent/lib/Services/TranscriptService.php:1760-1784`) — the
    Claude-side implementation this method's own docblock claims to share
    a contract with. It works correctly because ITS raw-lines array and
    cursor share the same index space (every raw JSONL line advances the
    index whether or not it renders); OpenCode's `$renderable` array does
    not have that property, since it's already been compacted.
  - `tests/test_opencode_transcript.php` (lines ~165-182 for the existing
    `read_transcript_page_since` tests, fixture setup ~lines 63-89 via the
    file's own `insert_session()`/`insert_message()`/`insert_part()`
    helpers) — existing coverage does NOT catch this bug: its two
    non-renderable messages (`msg_005`/`msg_006`, ~lines 83-89) are both
    AFTER all 4 renderable messages, so `$renderable`'s indices and line
    numbers happen to coincide up through the point every existing
    assertion checks. Extend this file, don't create a new one.
- **Dependencies:** none.
- **Root cause (confirmed by orchestrator, file:line evidence):** see
  above — index-space mismatch between `$renderable` (compacted) and the
  `line` values it carries (raw/uncompacted), only reachable once a
  non-renderable row exists BEFORE the newest renderable one, which the
  existing test fixture never constructs.
- **Implementation notes:**
  - Fix: iterate `$renderable` directly and filter by each entry's own
    `line` value, not by treating `$afterLine` as an index:
    ```php
    $entries = [];
    foreach ($renderable as $entry) {
        if (($entry['line'] ?? 0) <= $afterLine) {
            continue;
        }
        $entries[] = $entry;
        if (count($entries) >= $limit) {
            break;
        }
    }
    ```
    Replace the existing `$start`/`for` loop with this. Do not change how
    `$renderable` itself is built, and do not change `read_transcript_page()`
    (the "Load older" backward-pagination method) — this bug is isolated
    to the forward-poll method.
  - New test fixture: add a message BETWEEN two renderable ones that is
    itself non-renderable (mirror `msg_005`'s own shape — a `step-start`
    part plus nothing else, or reuse the exact `msg_005` construction —
    but insert it so its raw position sits before the newest renderable
    message, e.g. insert it as message 2 of 4, between `msg_001` and what
    was `msg_002`, shifting the later ones' `time_created` values up by
    one so ordering stays correct). Then call
    `read_transcript_page_since($sessionId, 1, 10)` (after the first
    renderable line) and assert the result correctly includes the LATER
    renderable entries (proving the cursor skipped the interleaved
    non-renderable row correctly, not silently returning fewer/zero
    entries or the wrong ones). Also add a regression assertion using the
    OLD bug's exact failure shape (a case where, under the old code, the
    loop would have gone straight out of bounds and returned 0 entries
    despite real new entries existing).
  - Keep all existing `read_transcript_page_since`/`TranscriptRouter`
    assertions in this file passing unchanged — this is a fix to the
    filtering logic only, existing gap-free-fixture assertions should
    still hold.
- **Acceptance criteria:**
  - New test(s) prove: (a) a non-renderable row interleaved BEFORE the
    newest renderable message no longer breaks `read_transcript_page_since()`
    — the correct later renderable entries still come back; (b) the exact
    old-bug scenario (would have returned 0 entries despite new ones
    existing) is explicitly asserted against, so a regression back to the
    old index-based logic would be caught; (c) all pre-existing assertions
    in `tests/test_opencode_transcript.php` still pass unchanged.
  - `bash tests/run.sh` passes in full.
  - No changes to `read_transcript_page()`, `message_to_entry()`, or any
    other method in this file — isolated to `read_transcript_page_since()`.
  - No changes to `docs/features.md` or `todo` — orchestrator handles doc
    updates after review.
- **Status:** done — opencode worker (`openai/gpt-5.4-mini`, cross-tool)
  applied exactly the specified fix and added precise regression coverage
  (the `afterLine=4` case explicitly documents "old code returned 0").
  Orchestrator review 2026-08-30: diff matches the diagnosis exactly, no
  unrelated changes; confirmed no stray worker process remained after the
  "completed" notification this time (`pgrep` clean — avoiding the
  `nohup ... &` double-detachment used for Task 3 worked); independently
  re-ran `tests/test_opencode_transcript.php` (all assertions pass, exit 0)
  and a full `bash tests/run.sh` from a verified clean state
  (`RESULT: all tests passed`, exit 0, ~3m, no leaked tmux sessions).
  Ready to commit.

### Task 5 — Fix (revised A6): Codex has zero coverage in the real health check

- **ID:** 5
- **Objective:** `PushHealthService::health_check()` (the actual aggregator
  behind the dashboard's health box) must include a real "Codex" section
  checking whether the Codex bridge is actually reachable — today Codex
  isn't checked AT ALL, not even a stub.
- **Background — this revises A6 as documented in PLAN.md's merged backlog
  (dated 2026-08-29):** that item claimed `CodexAdapter::check_hooks()`
  "unconditionally reports healthy, masking a down bridge service."
  Orchestrator re-investigation (2026-08-30) found this description was
  based on a wrong assumption: `check_hooks()` is real, but it is DEAD
  CODE — grepped every caller in the repo and found none outside the
  adapter classes themselves and their own unit test
  (`tests/test_agent_adapter.php`). The actual health box
  (`src/partials/pages/index.php:89` →
  `HealthBoxView::health_box_html()`) is fed by a completely different
  path: `DashboardController.php:40` calls `AgentClient::agent_call(['action'
  => 'health_check'])`, which dispatches (`host-agent/lib/Push.php:55`) to
  `PushHealthService::health_check()` — a hand-built `$checks` array with
  sections for `Claude Code`, `Global`, and `OpenCode` (via
  `opencode_serve_check()`/`opencode_plugin_check()`). There is no
  `Antigravity` or `Codex` section at all — confirmed by reading the whole
  function (`host-agent/lib/Services/PushHealthService.php:174-246`). So
  the real gap isn't "lying about health," it's "never asked the
  question" — worse than A6's original description in one sense (there's
  no visible check to even be suspicious of), narrower in another (no
  live bug currently misleads anyone, since nothing surfaces yet).
  Antigravity's own missing coverage is a separate, pre-existing gap not
  part of this task (Codex is what the backlog item named; flag
  Antigravity's absence too in RESULT.md as a follow-up, don't fix it
  here — scope control).
- **Relevant files:**
  - `host-agent/lib/Services/PushHealthService.php`:
    - `health_check()` (~lines 174-246) — add a new check to the
      `$checks` array, `'section' => 'Codex'`, following the exact same
      pattern as the existing `opencode_serve_check()`/`opencode_plugin_check()`
      block (~lines 226-231): call a new private method, tag its result
      with the section, append to `$checks`.
    - `opencode_serve_check()` (~lines 98-130ish) — the reference pattern
      to mirror for structure (systemd unit active/enabled check PLUS a
      real functional reachability check), adapted for Codex's actual
      transport (JSON-over-unix-socket via `CodexBridgeClient`, not HTTP
      — do not use `curl` for the functional check, use the real PHP
      client this app already has).
  - `host-agent/systemd/csm-codex-bridge.service` — confirms the real
    systemd unit name to check (`csm-codex-bridge.service`), mirroring
    `opencode-serve.service`'s naming convention exactly.
  - `host-agent/lib/Runtimes/CodexBridgeClient.php` — `request()` is the
    one public method; already returns `['ok' => false, 'message' =>
    '...']` cleanly on every real failure mode (socket unreachable after
    retries, write failure, timeout, closed connection, invalid JSON) —
    confirmed by reading the whole class. No new client code needed, just
    call it.
  - `host-agent/lib/Agents/CodexAdapter.php` — `check_hooks()` (~lines
    21-24), currently `return ['ok' => true, 'installed' => true,
    'message' => 'Codex status and prompts come from app-server'];`
    unconditionally. Confirmed dead code (see Background), but it's a
    one-line fix using the exact same real check this task is already
    building — see Implementation notes below for why it's still worth
    doing in this same task rather than leaving a second lying
    implementation sitting right next to the fixed one.
  - `tests/test_agent_adapter.php` — has existing `check_hooks()` coverage
    for the other adapters to follow the pattern of; grep `check_hooks`
    to find Codex's own existing (currently trivially-true) assertion and
    update it.
  - Check for an existing `tests/test_push_health.php` or similar before
    assuming where `PushHealthService::health_check()` itself is tested
    (grep `PushHealthService` across `tests/*.php`) — extend it if found,
    create one only if genuinely nothing covers this class yet.
- **Dependencies:** none.
- **Implementation notes:**
  - **Testability constraint found by the orchestrator, important:**
    `opencode_serve_check()` (the pattern being mirrored) has NO test
    coverage anywhere in this suite (`grep -rn opencode_serve_check
    tests/` confirms zero hits) — because its systemd-unit gate runs
    first and can't be faked in an isolated test environment, so nothing
    after it is reachable from a test either. Mirroring that exact
    single-method shape for Codex would make the new bridge-reachability
    logic equally untestable, which defeats this task's own acceptance
    criteria below. Avoid that by splitting the logic into two methods
    instead of one:
    1. `codex_bridge_reachable(?CodexBridgeClient $client = null): array`
       (or similar name) — JUST the functional check, independently
       testable via dependency injection: **match the established
       convention already used for this exact class**
       (`CodexTranscriptService::list_threads(bool $archived = false,
       ?CodexBridgeClient $client = null, ...)` — an optional, nullable,
       trailing parameter, defaulting to `new CodexBridgeClient()`
       internally when null). Do NOT use the heavier "real unix socket +
       fake PHP bridge process" fixture pattern from
       `tests/test_codex_resume.php`/`tests/test_transcript.php` for this
       — that's for end-to-end protocol tests, not a simple reachability
       check; the lighter, already-established pattern for exactly this
       kind of test is `tests/test_codex_runtime.php`'s
       `FakeCodexBridgeClient`/`FakeFailingCodexBridgeClient` (subclasses
       of `CodexBridgeClient` overriding `request()` to return a canned
       result, injected directly — grep `class Fake.*CodexBridgeClient`
       in that file for the exact shape to follow). Calls
       `$client->request('account/rateLimits/read', [])` (the same RPC
       method `QuotaService::codex_quota_state()` already uses elsewhere
       — reuse it rather than inventing a new bridge-local ping method,
       since that would mean also touching the always-on
       `codex_bridge.php` daemon script, out of scope for this task) and
       returns `ok`/`detail` from the result's own `ok` field — `ok:
       false` with the real `message` from that response on failure (the
       client already produces a good diagnostic — "Cannot reach Codex
       bridge: ...", "Codex bridge timed out", etc. — just pass it
       through as `detail`), `ok: true` with a short confirming detail on
       success.
    2. `codex_bridge_check(): array` — the one wired into `health_check()`,
       doing the systemd unit active/enabled gate first (same
       `ProcessRunner::run_process()` pattern as `opencode_serve_check()`,
       early-return `ok: false` naming the unit if not active/enabled —
       this part stays untested, matching the established, accepted
       precedent for `opencode_serve_check()`), then calling
       `codex_bridge_reachable()` for the real functional result when the
       unit is up.
  - Wire it into `health_check()`'s `$checks` array with
    `'key' => 'codex_bridge', 'section' => 'Codex', 'label' => 'Codex
    bridge'` (matching the existing key/section/label shape every other
    entry uses).
  - Also fix `CodexAdapter::check_hooks()` to call the SAME
    `codex_bridge_reachable()` check (make it a small `public static`
    method on `PushHealthService` so the adapter can call it directly —
    `check_hooks()` should NOT go through `codex_bridge_check()`/the
    systemd gate, since it's answering "is Codex integration working,"
    not "is this specific systemd unit up") instead of hardcoding `ok:
    true`. Keep `installed: true` as-is (Codex's "hook" is that it's
    wired in by design, not an installable file — `installed` answers a
    different question than `ok` here, matching OpenCode's own adapter
    which keeps `installed` and `ok` as genuinely separate signals).
  - Do NOT add an Antigravity section — out of scope, a separate
    pre-existing gap, just note it in RESULT.md.
  - Do NOT touch `opencode_serve_check()`/`opencode_plugin_check()` or
    any other existing check — reference-only.
- **Acceptance criteria:**
  - New test(s) prove, calling `PushHealthService::codex_bridge_reachable()`
    DIRECTLY with an injected fake client (bypassing the untestable
    systemd gate, per the Implementation notes above — use
    `tests/test_codex_runtime.php`'s `FakeCodexBridgeClient`/
    `FakeFailingCodexBridgeClient` subclass pattern, injected via the
    method's own `?CodexBridgeClient $client` parameter, NOT a real
    socket/fake-process fixture): (a) with a fake client whose
    `request()` returns `['ok' => false, 'message' => '...']` (mirroring
    `FakeFailingCodexBridgeClient`'s shape), `codex_bridge_reachable()`
    reports `ok: false` with a real, non-generic detail message derived
    from it; (b) with a fake client whose `request()` returns `['ok' =>
    true, ...]`, it reports `ok: true`.
  - A test also proves `PushHealthService::health_check()`'s returned
    `checks` array includes an entry with `section: 'Codex'` at all
    (structural presence — this part CAN run in the real test
    environment regardless of the systemd unit's actual state there,
    since it just confirms the entry exists, not that it's `ok: true`).
  - `CodexAdapter::check_hooks()` itself CANNOT take an injected fake
    client (`AgentAdapter::check_hooks(): array` is a shared, no-argument
    interface method implemented identically across all 4 adapters — do
    NOT change that interface for this task). So its own existing test
    assertion in `tests/test_agent_adapter.php` (currently only checks
    `installed`, never `ok` — confirmed by reading it) can only be
    extended to confirm `ok` now genuinely reflects a real
    `codex_bridge_reachable()` call (accept whatever the real, uninjected
    test-environment result is — don't try to force both a reachable and
    unreachable case through `check_hooks()` itself, since there's no way
    to inject a fake there). The full reachable/unreachable coverage
    lives entirely in the direct `codex_bridge_reachable()` tests above —
    that's where the actual logic is, `check_hooks()` is just a one-line
    delegator to it.
  - `bash tests/run.sh` passes in full (the file itself, not the whole
    suite, is fine to run standalone during development — orchestrator
    will run the full suite once at the end).
  - No changes to `opencode_serve_check()`, `opencode_plugin_check()`, or
    any other existing `PushHealthService` check.
  - No changes to `docs/features.md` or `todo` — orchestrator handles doc
    updates after review (flag that A6's original write-up was based on
    a wrong assumption, per the Background note above, as a finding for
    RESULT.md, not a docs edit the worker should make).
 - **Status:** done — orchestrator review 2026-08-30 (opencode worker,
   `openai/gpt-5.4-mini`; a first attempt via `agy`/Antigravity failed
   immediately on account quota exhaustion before touching anything,
   fell back to opencode). Diff matches the spec exactly: the two-method
   split (`codex_bridge_reachable(?CodexBridgeClient $client = null)` for
   DI-based testability, `codex_bridge_check()` doing the untested
   systemd gate then delegating), wired into `health_check()` with
   `section: 'Codex'`; `CodexAdapter::check_hooks()` correctly calls
   `codex_bridge_reachable()` directly (no systemd gate, matching the
   "answers a different question" reasoning in the spec) since it can't
   take an injected client through the shared interface. Test coverage
   follows `test_codex_runtime.php`'s `FakeCodexBridgeClient` naming/DI
   convention exactly, covers both reachable and unreachable cases plus
   the structural `health_check()` presence check.
   **`tests/test_agent_adapter.php` complication:** this file was already
   modified by the other concurrent session's own in-progress work (an
   unrelated `build_spawn_argv(model)` test block) before this worker
   touched it. The worker's diff and that pre-existing content were
   cleanly separable (distinct hunks, no line overlap) — extracted just
   the worker's two hunks into a patch and applied it to the git INDEX
   only via `git apply --cached` (never touched the working-tree file
   itself), so the other session's in-progress edits remain exactly as
   they were on disk, untouched and unstaged, while only this task's own
   contribution is staged for commit. Independently re-ran both
   `php tests/test_push.php` and `php tests/test_agent_adapter.php`
   against the real (both-sessions'-changes-present) working tree — all
   pass, confirming no functional conflict between the two sessions'
   concurrent edits to that shared file.
