# RESULT.md — Agent feature parity

## Pre-existing knowledge (gathered before any worker launch, 2026-08-29)

Source: `todo` (root of repo) + `docs/features.md`.

- `docs/features.md` capability list + per-agent matrix generated 2026-08-26 —
  predates Codex entirely (added via `wip: add native Codex integration` 2026-08-27
  and `Integrate Codex headless sessions` 2026-08-29). No Codex column exists yet
  anywhere in that doc.
- Already-known gaps (Claude Code / Antigravity / OpenCode only, from
  `docs/features.md`'s "Known gaps & partial parity" + the todo's Tier 1/3):
  1. Content search (`search_transcripts` + in-page search) is Claude-JSONL-only —
     Antigravity/OpenCode transcript content is invisible to search.
  2. Archived detail/browse uses `TranscriptService::find_transcript_path`
     (Claude-only) even though paging/attachments already use the agent-agnostic
     `TranscriptRouter` — Antigravity/OpenCode archived rows open "Session not
     found" (confirmed bug, todo Tier 1).
  3. OpenCode forward-poll line cursor can silently stop updating on the live
     detail page (todo Tier 1 bug).
  4. OpenCode hooks not production-wired — `check_hooks`/`install_hooks` are honest
     stubs; only `csm-permissions` plugin ships, `csm-status` plugin planned but
     not built — OpenCode lacks hook-fed status + session-id self-heal.
  5. Antigravity `--model`/`--effort` and OpenCode positional-workdir/`--model`/
     `--agent` exist in the adapters but aren't reachable from the New Session UI
     (`create_cc_session()` only forwards `enable_task_tools`/`starting_mode`).
  6. Mode switching absent for OpenCode (no vocabulary, only boolean `--auto`);
     effort switching Antigravity-only — flagged as by-design, but a real gap if a
     uniform control set is wanted.
  7. Antigravity has no LIVE in-session mode switch despite the matrix showing
     "✓" — `set_mode()` is hardcoded to Claude Code's status-line phrases; only
     spawn-time `--mode` exists for Antigravity. The doc's own "✓/✓" is misleading.
  8. `perf/session-list-polling` branch (2026-08-11) has real, unmerged fixes for a
     confirmed live perf bug (multi-tab session-list scans) — needs rebase against
     current `SessionService::list_all_sessions()` before merging, not a blind
     merge. Tier 2, not per-agent parity, but flagged as "de-risk before starting
     new work."

None of the above have been independently re-verified by a worker yet in this
session — treat as reliable starting hypotheses (dated 2026-08-29, sourced from a
prior atlas run), not settled fact, until confirmed against current code.

## Codex parity audit (R1)

Codex is architecturally different from the other three agents: it is
**server-owned, headless-only, never spawned into tmux**
(`host-agent/lib/Agents/CodexAdapter.php:9,33-36` — `supported_runtimes()`
returns only `RuntimeType::HEADLESS`). A persistent bridge process
(`host-agent/codex_bridge.php`, run by `csm-codex-bridge.service`) holds one
long-lived `codex app-server --stdio` connection and multiplexes CSM's
per-request host-agent processes over a UNIX socket
(`host-agent/lib/Runtimes/CodexBridgeClient.php`). Every verdict below is from
static reading only — see the "Could not determine statically" section for
what still needs a live session.

### 1. Per-agent coverage — Codex column

| Capability | Codex | Evidence |
|---|---|---|
| List / open / kill session | ✓ | `Sessions.php:142-187` (create), `csm_codex_sync()` `Sessions.php:665-715` (list/adopt), `Sessions.php:210-229` + `CodexHeadlessRuntime::kill()` `CodexHeadlessRuntime.php:88-92` (kill via `thread/archive`) |
| Create session (workdir browse) | ✓ | `PageView::AGENT_OPTIONS` includes `'codex'` (`src/lib/Views/PageView.php:27`); `Sessions.php:142-149` headless-create branch; `CodexHeadlessRuntime::create()` `CodexHeadlessRuntime.php:21-42` |
| Resume session | ✗ Broken | `Sessions.php:196-208`'s `'resume'` case only special-cases OpenCode ids; any other id (including a Codex thread id) falls to `SessionLifecycleService::resume_cc_session()`, which defaults to `$resumeAgentId = 'claude'` and runs `claude --resume <id>` in tmux (`SessionLifecycleService.php:239-246`). The archived-row "Resume" button renders unconditionally for every agent (`src/partials/session-row/archived-row.php:28-36`), so this is reachable |
| Take over / kill bare session | — N/A | Codex has no bare/tmux process of its own to discover — `BareProcessService` scans tmux/`ps`, and Codex is never spawned that way (`CodexAdapter.php:9`) |
| Session-id self-heal | — N/A (not needed) | `thread/start`'s response returns the real thread id synchronously (`CodexHeadlessRuntime.php:35-41`) — no rotation problem the way a tmux-hosted CLI has; `csm_codex_sync()` just reconciles against app-server's own catalog every poll |
| Idle / working / blocked status | ✓ | `codex_bridge.php:308-338` pushes `thread/status/changed`/`turn/started`/`turn/completed` straight into `SessionStatusStore`; `CodexHeadlessRuntime::status()` (`CodexHeadlessRuntime.php:94-102`) as a pull fallback |
| Blocked-permission detection | ◐ Partial | Complete for permission-type prompts (command/file-change/permissions approvals) via `codex_normalize_prompt()`/`codex_prompt_response()` (`codex_bridge.php:118-201`); Broken for the question-type prompt — see rows 11/12 |
| Exact tool call shown for block | ◐ Partial | `tool_input` carries the full raw params untruncated, but `tool_name` collapses every approval kind (commandExecution/fileChange/permissions) to one generic label, `'permission'` (`codex_bridge.php:141-161`) — not Codex's real per-item type |
| Approve / deny by option | ✓ (permission-type only) | `codex_prompt_response()` maps option 1-4 to accept/acceptForSession/decline/cancel (`codex_bridge.php:184-199`) |
| Free-text answer to a prompt | ✗ Broken/Missing | No free-text box is ever offered: `BlockedPromptView::blocked_prompt_options_html()` only reveals it when an option label contains "type something" (`src/lib/Views/BlockedPromptView.php:186-193`), which Codex's own labels never do; even if sent, `codex_prompt_response()` never reads `answers['text']`/`['option']` for `item/tool/requestUserInput` (`codex_bridge.php:169-182`) |
| Multi-question `AskUserQuestion` | ✗ Broken | The bridge supports it structurally, but nothing in CSM ever calls `answer_multi_question` for Codex — both `csm_merge_headless_sessions()` (`Sessions.php:431`) and `csm_headless_detail_shape()` (`Sessions.php:966`) hardcode `'prompt_questions' => null` for every headless session, and `session.js`'s `isMultiQuestion` gate reads exactly that field (`public/js/session.js:1448`) |
| Single-question `AskUserQuestion` | ✗ Broken | Same root cause — falls back to a flattened numbered-option UI built from only the *first* question's options (`codex_bridge.php:122-127`); submitting via `answer_prompt`/`answer_prompt_with_text` sends `{option/text}`, which `codex_prompt_response()` ignores for this method (only reads `'answers'`) — the real choice is silently lost |
| Folder-trust dialog | — N/A | `codex_normalize_prompt()` always sets `is_folder_trust => false` (`codex_bridge.php:135,151`) — sandbox/approval policy is set programmatically at `thread/start` (`CodexHeadlessRuntime.php:28`), no interactive dialog exists |
| OpenCode permission/plugin bridge | — N/A | OpenCode-specific row |
| Escape / interrupt | ✓ | `Sessions.php:273-279` → `CodexHeadlessRuntime::interrupt()` (`CodexHeadlessRuntime.php:145-148`, `turn/interrupt`) |
| Send message | ✓ | Including attachments (localImage/mention mapping) — `Sessions.php:284-295`; `CodexHeadlessRuntime::send_message()` `CodexHeadlessRuntime.php:104-139` |
| Switch mode | ✗ Missing (by design) | `Sessions.php:297-303`: `'set_mode'` unconditionally returns "Mode switching is not supported for headless sessions" for any headless session |
| Switch model | ✓ | At creation (`Sessions.php:149`) and live in-session (`Sessions.php:305-320` → `update_settings()`; `public/js/session.js:2707-2773`; `src/partials/transcript/codex-model-toggle.php`) |
| Switch effort level | ◐ Partial | Live in-session works (same `update_settings()` call/UI), but not exposed at creation — `Sessions.php:149` only forwards `'model'` to `CodexHeadlessRuntime::create()`, never `'effort'`/`'serviceTier'` even though `create()` accepts both (`CodexHeadlessRuntime.php:28-33`) |
| View transcript | ✓ | `CodexTranscriptService::parse_item()` (`CodexTranscriptService.php:145-240`) maps every retained app-server item type (user/agent messages, plan, commandExecution, fileChange, webSearch, MCP/dynamic tool calls, subagent activity, image gen/view, review mode, sleep) |
| Markdown / collapsible / copy block | ✓ | Generic, agent-agnostic block renderer — no Codex-specific gap |
| Search + jump-to-line (in-session) | ✗ Missing | `ArchivedSessionService::transcript_search_for_claude_session()` (`ArchivedSessionService.php:317-329`) has an OpenCode branch but falls through to `TranscriptService::search_transcript_file()` (Claude-JSONL-only) for a `"codex:..."` path; `@file()` on that non-path returns `false`, so it silently returns zero matches (`TranscriptService.php:280-284`) — no crash, just no results |
| History / paging / attachments | ◐ Partial | Paging works (`TranscriptRouter` dispatches to `CodexTranscriptService`, `TranscriptRouter.php:60-61,78-79`); attachment viewing is a stub — `CodexTranscriptService::read_attachment()` always returns `ok:false` (`CodexTranscriptService.php:278-281`) |
| Live polling | ✓ | Same generic poll mechanism; no cursor bug found (unlike OpenCode's) |
| Turn error display | ✗ Missing (captured, not surfaced) | `codex_bridge.php:327-334` writes `last_turn_error` into `SessionStatusStore` on `turn/completed`, but both frontend-facing shapes hardcode it back to `null` — `csm_merge_headless_sessions()` (`Sessions.php:435`) and `csm_headless_detail_shape()` (`Sessions.php:975`) |
| List archived sessions | ✓ | Dedicated Codex block in `ArchivedSessionService::list_archived_sessions()` (`ArchivedSessionService.php:104-148`) plus a durable-rollout-directory fallback merge (`ArchivedSessionService.php:131-148`) |
| Archived read-only view | ✓ | **Not** subject to the Antigravity/OpenCode "Session not found" bug — `SessionDetailService::archived_session_detail()` has an explicit `is_codex_path()` branch (`SessionDetailService.php:183-197`) that reads via `CodexTranscriptService::thread_metadata()` instead of falling through to the Claude-only `find_first_cwd()`/`find_latest_ai_title()` calls that break for Antigravity/OpenCode's own path shapes (`SessionDetailService.php:199-200`) |
| Search across live + archived | ✗ Missing | `ArchivedSessionService::search_transcripts()` (`ArchivedSessionService.php:200-275`) only branches on Claude JSONL and OpenCode's DB — no Codex branch exists at all |
| Usage quota | ✓ | `QuotaService::codex_quota_state()` (`QuotaService.php:32-69`) reads `account/rateLimits/read` + per-thread token usage; wired into `get_quota()` both per-session (`QuotaService.php:424-437`) and dashboard-wide (`QuotaService.php:483,513-519`) |
| Push on block / task-finished | ◐ Partial | Functionally works (transition detection is generic on `status`/`blocked_reason`, which the bridge populates correctly for Codex), but `push_trigger.php`'s own inline headless-merge loop (`push_trigger.php:80-81`) hardcodes `'agent' => 'opencode'`/`'agent_label' => 'OpenCode'` for every row from `csm_headless_sessions()['headless']`, which now includes Codex sessions. Currently cosmetic-only — `NotificationContentBuilder::push_blocked_title()`/`push_finished_title()` don't consume `agent_label` — but latent/fragile |
| Push on quota events | ✗ Missing | `push_trigger.php:111-118` only merges `$agents['claude']['quota'] + $agents['antigravity']['quota']` before calling `check_and_send_quota_pushes()` — Codex's own bucket (`$agents['codex']['quota']`, populated at `QuotaService.php:513-519`) is never included. Same bug class as the historical Antigravity incident documented in that file's own comment (`push_trigger.php:15-31`), not yet extended to Codex |
| Health check / diagnostics | ✗ Missing | `PushHealthService::health_check()` (`PushHealthService.php:174-247`) has sections for Claude Code hooks, tmux socket dir, push delivery/quota, and OpenCode serve+plugin — zero Codex checks (no bridge-reachability probe, no `CODEX_BIN`-configured check). `CodexAdapter::check_hooks()` (`CodexAdapter.php:21-24`) is also hardcoded `ok:true/installed:true` unconditionally, so it always reports healthy even when `csm-codex-bridge.service` is down |
| File uploads | ✓ | `UploadService` is workdir-based and agent-agnostic (`UploadService.php:17-19,42-52`); Codex sidecars carry a real workdir; `CodexHeadlessRuntime::send_message()` additionally understands attachment paths natively (localImage/mention mapping, `CodexHeadlessRuntime.php:130-136`) |
| Plan / handoff / todo glance | ✗ Missing (todo widget only) | Plan *content* renders inline in the transcript (`CodexTranscriptService.php:161-163`, the `'plan'` item branch), but the sidebar's dedicated todo-list widget is OpenCode-only — `csm_headless_detail_shape()`'s todo fetch is gated `$agentId === 'opencode' ? ... : ['ok' => false]` (`Sessions.php:925`), so `todos` stays `null` for Codex |

### 2. Implementation status by agent (detail) — Codex rows

Added as Codex rows for every feature already in `docs/features.md`'s detail
table, plus new rows for gaps this audit surfaced that aren't yet represented
there at all (Resume, Turn error, Push-on-quota, Health check, Todo glance).

| Feature | Agent | Status | Mechanism | Caveat |
|---|---|---|---|---|
| Blocked-permission detection | Codex | Partial | `codex_normalize_prompt()`/`codex_prompt_response()` (`codex_bridge.php:118-201`) | Complete for permission-type approvals; Broken for question-type (`item/tool/requestUserInput`) — see below |
| Session-id self-heal | Codex | N/A | Thread id known synchronously from `thread/start` (`CodexHeadlessRuntime.php:35-41`) | Not needed — no CLI-side rotation problem exists for a server-owned thread |
| Multi-question `AskUserQuestion` | Codex | Broken | Bridge normalizes it correctly (`codex_bridge.php:118-139`) but CSM never surfaces it | `prompt_questions` hardcoded `null` in both `Sessions.php:431` and `Sessions.php:966`; `session.js:1448`'s gate never fires |
| Archived detail/browse | Codex | Complete | `is_codex_path()` branch in `SessionDetailService::archived_session_detail()` (`SessionDetailService.php:183-197`) | Built correctly from the start — does not share Antigravity/OpenCode's "Session not found" bug |
| Content search (live + archived) | Codex | Missing | No Codex branch in `ArchivedSessionService::search_transcripts()` (`ArchivedSessionService.php:200-275`) or `transcript_search_for_claude_session()` (`ArchivedSessionService.php:317-329`) | Same gap class as Antigravity/OpenCode; per-session search degrades silently to zero matches rather than erroring |
| Model switch | Codex | Complete | `thread/settings/update` via `CodexHeadlessRuntime::update_settings()` (`CodexHeadlessRuntime.php:154-162`) | Reachable both at creation (`Sessions.php:149`) and live in-session |
| Switch mode | Codex | Missing (by design) | No mode vocabulary for any headless runtime | `Sessions.php:297-303` blanket-rejects `set_mode` for every headless session |
| Transcript view | Codex | Complete | App-server `thread/read` + `CodexTranscriptService::parse_item()` (`CodexTranscriptService.php:145-260`) | `entries()` re-fetches and re-parses the FULL thread on every page request (no server-side pagination at the RPC level, only client-side slicing) — potential perf concern on a very long thread, unverified live |
| Usage quota | Codex | Complete | `account/rateLimits/read` + token-usage overlay (`QuotaService.php:32-69`) | Push-on-quota-events is NOT wired for this bucket (see below) — display and push are two separate gaps |
| Resume archived session | Codex | Broken | `Sessions.php:196-208` `'resume'` case has no Codex branch | Falls to `SessionLifecycleService::resume_cc_session()`, which runs `claude --resume <codex-thread-id>` (`SessionLifecycleService.php:239-246`) — feeds a Codex thread id to the Claude Code CLI. The UI's "Resume" button is shown for Codex archived rows unconditionally (`archived-row.php:28-36`) |
| Turn error display | Codex | Missing | Captured (`codex_bridge.php:327-334`) but never surfaced — hardcoded `null` in `Sessions.php:435` and `Sessions.php:975` | Backend has the data; only the frontend-facing shape drops it |
| Push on quota events | Codex | Missing | `push_trigger.php:111-118` merges only claude+antigravity buckets | Same class as the documented 2026-08-24 Antigravity incident (`push_trigger.php:15-31`), not yet fixed for Codex |
| Health check / diagnostics | Codex | Missing | `PushHealthService::health_check()` has no Codex section at all | `CodexAdapter::check_hooks()` (`CodexAdapter.php:21-24`) also unconditionally reports healthy, masking a down bridge service |
| Plan/handoff/todo glance | Codex | Missing | `csm_headless_detail_shape()`'s todo fetch gated to `$agentId === 'opencode'` (`Sessions.php:925`) | Plan blocks still render inline in the transcript itself; only the sidebar todo widget is affected |

### 3. Could not determine by static reading alone

- Whether `csm-codex-bridge.service` is actually installed/enabled/running in
  this environment right now — the systemd unit
  (`host-agent/systemd/csm-codex-bridge.service`) and `install.sh` wiring
  exist, but live service state wasn't checked (would need `systemctl status`
  on the host, out of scope for a read-only code audit).
- The real end-to-end behavior when CSM submits an effectively-empty
  `answers` map back to app-server for a question-type prompt (the Multi/
  Single-question `AskUserQuestion` bug above) — does app-server reject it
  with a visible error, silently accept an empty answer and continue, or hang
  the turn? Only a live session with a real `request_user_input` call can
  confirm the actual failure mode/severity.
- The real user-facing failure mode of the "Resume archived Codex session"
  bug — does `claude --resume <codex-thread-id>` fail fast with a readable
  tmux-pane error, or does Claude Code silently start a brand-new session
  (wasting a real, billable Claude Code turn) because `--resume` couldn't
  find that id? Materially changes how urgent this is.
- Real-world performance of `CodexTranscriptService::entries()` re-fetching
  and re-parsing an entire thread's turns on every single transcript page
  request, for a thread with a genuinely large turn history — no caching or
  server-side pagination exists at the RPC layer today.
- Whether app-server's `thread/resume` "active writer" conflict detection
  (used throughout `CodexHeadlessRuntime`) behaves correctly under the
  bridge's restart-recovery path in practice, beyond what
  `tests/test_codex_runtime.php`'s fakes exercise.

### 4. Prioritized Codex gaps (worker's judgment)

1. **Question/`AskUserQuestion`-equivalent prompts are Broken, not just
   missing a nice UI** — a running Codex session that calls
   `item/tool/requestUserInput` currently cannot be answered correctly
   through the web UI at all (the real answer never reaches the bridge); this
   is a core part of the interactive loop, not a cosmetic gap, and could
   stall a session indefinitely with no way to unblock it from CSM.
2. **Resuming an archived Codex session is Broken** — a reachable, always-
   visible button that runs the wrong CLI (`claude --resume` with a Codex
   thread id) instead of the correct headless-runtime resume path; likely
   either confuses the user with an unrelated error or wastes a real Claude
   Code session.
3. **Health check has zero Codex visibility, and `check_hooks()` lies** — the
   single dependency every Codex feature relies on (the bridge process) is
   invisible on the dashboard, and the one function that's supposed to check
   it unconditionally reports healthy; a genuinely down bridge fails silently
   per-request instead of surfacing proactively.
4. **Push on quota events silently excludes Codex** — this is the exact bug
   class Andres already had a real, documented incident over for Antigravity
   (missed quota push, 2026-08-24) — the fix pattern is known and already
   proven for two other agents, just not yet applied to Codex's bucket.
5. **Content search (dashboard + in-session) excludes Codex** — same
   established gap class as Antigravity/OpenCode, same fix shape (add a
   Codex branch to `ArchivedSessionService`'s two search methods) — lower
   urgency than the above since it degrades silently (empty results) rather
   than doing something actively wrong.

Lower-priority but real: turn-error display (data captured, not shown),
effort not selectable at session-creation time (only live in-session),
transcript attachment viewing (explicit stub), todo-glance widget
(Codex-excluded), and the push-notification agent-label mislabeling
(currently cosmetic-only, no visible impact yet).

## Task 1 — Fix A1: Codex question-type prompts unanswerable (worker result)

**Status:** implementation complete, `needs_review` in PLAN.md. Worker ran as
a general-purpose Claude agent (model: Sonnet 5).

**Changed files:**
- NEW `host-agent/lib/Services/CodexPromptProtocol.php` — `normalize_prompt()`
  and `prompt_response()` extracted verbatim (normalize) / fixed (response)
  out of `codex_bridge.php`'s former bare functions `codex_normalize_prompt()`/
  `codex_prompt_response()`. PSR-4 autoloaded under `HostAgent\Services`
  (`composer.json` already maps `host-agent/lib/` → `HostAgent\`, no config
  change needed).
- `host-agent/codex_bridge.php` — old bare function bodies deleted; its two
  call sites now use `CodexPromptProtocol::normalize_prompt()`/
  `::prompt_response()`. No other behavior in this file touched (the
  event-loop, pending-prompt bookkeeping, socket handling are all unchanged).
- `host-agent/lib/Sessions.php` — `csm_merge_headless_sessions()` (~line 431)
  and `csm_headless_detail_shape()` (~line 966): `'prompt_questions' => null`
  replaced with `($blocked['tool_name'] ?? null) === 'question' ?
  ($blocked['tool_input']['questions'] ?? null) : null` in both places — a
  direct passthrough of Codex's raw question objects (already carrying
  `question`/`options[].label`/`multiSelect`-absent, the exact field names
  `BlockedPromptView`/`session.js`'s multi-question renderers read), applied
  for every question count, not just Claude Code's own count>=2 threshold
  (Codex is headless — no working single-question pane fallback exists for
  it, confirmed by reading `renderMultiQuestionCardHtml()`'s own gate in
  `session.js:1448`, which is just `.length`-truthy, no count check).
- NEW `tests/test_codex_prompt_protocol.php` — pure unit tests for the
  extracted class: `normalize_prompt()` for both question-type (1- and
  2-question) and all three `*requestApproval` methods; `prompt_response()`
  happy paths (single-select label resolution, free text, multi-select,
  2-question index matching) and sad paths (out-of-range ordinal, missing/
  malformed `options` array, no answer supplied, non-array `answers`
  payload, a question missing a string `id`); permission-type responses
  unchanged. Includes an explicit regression-shaped assertion
  (`$response->q1['answers'][0] !== '1'`) that fails against the OLD
  `array_map('strval', ...)` behavior and passes against the fix.
- NEW `tests/test_codex_prompt_questions_wiring.php` — exercises
  `csm_merge_headless_sessions()`/`csm_headless_detail_shape()` directly
  (same pattern as `tests/test_runtime_headless_routing.php`: isolated
  `SESSIONS_SQLITE_FILE`/`PUSH_SQLITE_FILE`, pre-seeded sync-throttle keys
  so the real serve/bridge round trip never fires) against real
  `SidecarStore`/`SessionStatusStore` fixtures for a 2-question Codex
  prompt, a 1-question Codex prompt, a Codex permission-type prompt, an
  idle Codex session, and an OpenCode permission-type prompt — proving
  `prompt_questions` is populated (question-type, any count) or stays null
  (permission-type / idle / non-Codex) in both functions.

**Not touched, per PLAN.md's explicit scope:**
- `push_trigger.php:93`'s own `'prompt_questions' => null` — read what it
  feeds (`PushDeliveryService::check_and_send_pushes()`, a pure status-
  transition detector keyed on `status`/`blocked_reason`); it never reads
  `prompt_questions`, so this hardcode has no UI-rendering consequence and
  was left alone as instructed.
- `blocked_prompt_options_html()`/`renderOptionsCardHtml()`/
  `answer_prompt`/`answer_prompt_with_text` for headless sessions — untouched;
  still the correct path for Codex's permission-type prompts.
- Confirmed (by reading `Sessions.php`'s `'answer_multi_question'` dispatch
  case, ~line 261) that headless sessions already route to
  `csm_headless_answer_prompt()` → `CodexHeadlessRuntime::answer_prompt()` →
  the bridge's `csm/answerPrompt`, never touching the tmux-only
  `PromptInteractionService::answer_multi_question()` — no routing change
  was needed for the frontend form to reach the fixed bridge logic once
  `prompt_questions` is populated.

**Testing:** `bash tests/run.sh` run 3x clean (0 failures) after a
`--cleanup`; one unrelated single-file flake seen on the very first run
(pre-existing stray state, not reproduced on 2 subsequent clean runs) —
not caused by these changes. New test files: 41 assertions in
`test_codex_prompt_protocol.php`, 16 in
`test_codex_prompt_questions_wiring.php`, all passing.

**Assumptions/judgment calls:**
- Missing-answer-for-a-question (`$supplied[$index]` absent) now resolves
  to an empty `answers` array for that question, matching the old code's
  behavior for the same case (`$value = $supplied[$index] ?? [];` then
  `array_map` over `[]`) — not explicitly specified in PLAN.md but kept
  for behavioral parity on an untouched edge case.
- Free-text detection distinguishes `{'text': '...'}` from a multi-select
  ordinal array by checking `is_string($value['text'] ?? null)` rather than
  `array_key_exists('text', ...)` alone, so a value that happens to be a
  list array can never collide with the free-text shape.

**Remaining concerns:** none blocking. A live end-to-end check against a
real `codex app-server` was out of scope for this worker (no live Codex
bridge process in this environment) — coverage here is static/unit-level
matching this repo's existing `tests/test_codex_runtime.php` convention
(`FakeCodexBridgeClient`), same as every other Codex test in this suite.
