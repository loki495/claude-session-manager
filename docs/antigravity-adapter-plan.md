# Antigravity CLI support — implementation plan

Status: **in progress**, started 2026-08-24. Supersedes the "long-term,
explicitly not near-term" sequencing note in `todo` — Andres asked to start
this directly, ahead of the CSM-own-plugin-hooks item it was previously
queued behind. Phase 0 (groundwork), Phase 1 (AgentAdapter interface +
ClaudeCodeAdapter), and Phase 2 (AntigravityAdapter spawn + identity + New
Session UI picker) are done. Next up: Phase 3 (hooks).

Goal: get a second agent (Google's Antigravity CLI, binary `agy`) working
through this same tmux/web-UI/SQLite pipeline, via a real `AgentAdapter`
abstraction with Claude Code as its first implementation — not bolted on top
of the current Claude-Code-only code. MVP means: spawn, list, see status,
read the transcript. Non-required features (statusline/quota, real
interactive permission-prompt answering) can stay broken/deferred, per
Andres's explicit tolerance for that at kickoff.

## Research findings (live-verified 2026-08-24)

Both confirmed against a real `agy 1.1.19` installation already
authenticated on this machine, not just the public docs (which are close to
accurate but incomplete/occasionally wrong on file locations).

### Transcript format — good news, no protobuf needed

Every hook payload carries `transcriptPath`, which points at:

```
<conversationDir>/.system_generated/logs/transcript_full.jsonl
```

(`conversationDir` = `~/.gemini/antigravity-cli/brain/<conversationId>/`,
confirmed via `artifactDirectoryPath`). This is plain, readable JSONL, one
object per line:

```json
{"step_index":0,"source":"USER_EXPLICIT","type":"USER_INPUT","status":"DONE","created_at":"2026-08-24T20:18:50Z","content":"<USER_REQUEST>\n...\n</USER_REQUEST>..."}
{"step_index":1,"source":"SYSTEM","type":"CHECKPOINT","status":"DONE","created_at":"...","content":"{{ CHECKPOINT 0 }}\n...context-truncation summary..."}
{"step_index":2,"source":"MODEL","type":"PLANNER_RESPONSE","status":"DONE","created_at":"...","thinking":"...optional reasoning trace...","tool_calls":[{"name":"run_command","args":{"CommandLine":"...","Cwd":"..."}}],"content":"..."}
```

Observed `source`/`type` pairs so far: `USER_EXPLICIT`/`USER_INPUT`,
`SYSTEM`/`CHECKPOINT` (a context-truncation summary marker — no Claude Code
equivalent, needs its own render treatment or a skip), `MODEL`/
`PLANNER_RESPONSE` (optionally carrying `thinking` and/or `tool_calls`).
**Not yet observed**: a tool-RESULT-shaped entry (every live tool call this
research made got denied before executing — see below). Confirming that
shape is the first thing to do once Phase 3 can actually run a tool.

There is *also* a per-conversation SQLite database
(`~/.gemini/antigravity-cli/conversations/<id>.db`, tables `steps` /
`gen_metadata` / etc., content in protobuf-encoded `blob` columns — the
binary embeds full `genai.*` proto schema strings, e.g. `TextContent`,
`ToolCallContent`, `ToolResultContent`). This is irrelevant to us — it looks
like an internal fast-access cache/index sitting alongside the real JSONL
log, not something we need to touch. **Do not build a protobuf parser** —
the plain JSONL file already has everything.

### Hooks — real config format (differs from a literal reading of the docs)

Global config file: `~/.gemini/config/hooks.json` (confirmed via the
binary's own embedded changelog: `/hooks` command used to write to the wrong
path, `~/.gemini/antigravity-cli/hooks.json`, and was fixed to write here
instead). Per-workspace override also exists: `<workspace>/.agents/hooks.json`
— not needed for MVP (global is enough to start).

Real schema (confirmed by extracting the CLI's own embedded doc text, then
verified live):

```json
{
  "<hook-name>": {
    "enabled": true,
    "PreToolUse": [
      { "matcher": ".*", "hooks": [ { "type": "command", "command": "..." } ] }
    ],
    "PostToolUse": [
      { "matcher": ".*", "hooks": [ { "type": "command", "command": "..." } ] }
    ],
    "PreInvocation": [ { "type": "command", "command": "..." } ],
    "PostInvocation": [ { "type": "command", "command": "..." } ],
    "Stop": [ { "type": "command", "command": "..." } ]
  }
}
```

`PreToolUse`/`PostToolUse` are **grouped** (need a `matcher` regex against
the tool name + a `hooks` array); `PreInvocation`/`PostInvocation`/`Stop`
are **flat** (a plain list of `{type, command}` handler objects, no
matcher). This is genuinely close to Claude Code's own
`hooks[event][] = {matcher, hooks:[{type,command}]}` shape — just with an
extra named-hook-group wrapper key, and the three non-tool events skipping
the matcher layer entirely.

Common fields on every hook's stdin payload (confirmed live):
`conversationId`, `workspacePaths`, `transcriptPath`, `artifactDirectoryPath`,
`modelName`.

Per-event fields (confirmed live except `PostToolUse`, which never fired
during this research — see open question #1):

| Event | Extra input fields (confirmed) | Expected stdout |
|---|---|---|
| `PreToolUse` | `toolCall: {name, args}`, `stepIdx` | `{"decision": "allow"\|"deny"\|"ask"\|"force_ask", "reason"?, "permissionOverrides"?, "overwrite"?}` |
| `PostToolUse` | `stepIdx`, `error?` (documented, not live-confirmed) | `{}` |
| `PreInvocation` | `invocationNum`, `initialNumSteps` | `{"injectSteps"?: [...]}` |
| `PostInvocation` | `invocationNum`, `initialNumSteps` | `{"injectSteps"?, "terminationBehavior"?: "force_continue"\|"terminate"}` |
| `Stop` | `executionNum`, `terminationReason`, `error?`, `fullyIdle` | `{"decision": "continue"\|anything-else, "reason"?}` |

**Architecturally different from Claude Code**: `PreToolUse`'s `decision`
field actually *gates* whether the tool runs — Claude Code's hooks are
observe-only by design (never approve/deny anything; the real approval UI
is separate, driven by pane-scraping). Antigravity's hook contract folds
the two together. Good news for later (permission answering can route
through the hook's own response instead of reinventing pane-scraping), but
it means the hook script itself has to actually decide something, which is
new territory for this codebase.

**Docs also say**: hooks run synchronously and block the agent loop; only
`type: "command"` is supported (no HTTP/prompt hooks yet).

### CLI flags relevant to spawning/resuming

From `agy --help` (binary v1.1.19):

- Interactive TUI: plain `agy` (optionally `--agent`, `--model`, `--effort`,
  `--mode accept-edits|plan`, `--project`, `--new-project`, `--add-dir`).
  **No `--session-id`/`--conversation-id` equivalent for starting a NEW
  interactive session** — unlike `claude --session-id <uuid>`, there is no
  way to pre-assign a conversation id at spawn time. `--conversation <id>`
  only *resumes* an existing one; `-c`/`--continue` resumes the most recent.
- Headless/print mode: `-p`/`--print`, `--output-format text|json|stream-json`,
  `--input-format text|stream-json`, `--json-schema`, `--print-timeout`
  (default 5m). Each invocation's JSON envelope includes `conversation_id`.
- `--dangerously-skip-permissions`, `--sandbox` also exist.

**Consequence for CSM**: session identity can't be bound at spawn time the
way `SessionLifecycleService::create_cc_session()` does today (generate a
UUID, pass `--session-id`, done). It has to be bound *reactively*, off the
`conversationId` in whichever hook fires first after spawn (almost
certainly `PreInvocation`, since that's the earliest hook after a prompt is
submitted) — closer to how `session_start.php` already rebinds a sidecar on
`/clear`/`/compact`/`--resume` for Claude Code, just needed on *every*
session's first turn instead of only on a rotation.

### Free tier / models

Confirmed real (not a trial): Free tier includes Gemini 3.x models, plus
Claude Sonnet 4.6/Opus 4.6/GPT-OSS-120b on Free+Pro. Model selection via
`--model`.

## Open questions (flagged, not blocking Phase 1)

1. **Does `PostToolUse` fire on a denied tool call?** Never observed live —
   every tool-call attempt in this research hit a *headless-mode-specific*
   hard wall ("headless mode cannot prompt for permission", independent of
   our own hook's `decision`). This wall may simply not apply the same way
   to an *interactive* TUI session running attached to a real pty inside
   tmux (which is what CSM actually spawns) — headless (`-p`) was only used
   here for convenience during research. Needs a real interactive-mode test
   in Phase 3, not more headless testing.
2. ~~Conversation-id binding timing~~ — **resolved above**: no pre-assignment
   possible, bind reactively off the first hook firing.

## Adjustted plan vs. the original chat draft

- Confirmed the `cc-` tmux-session-name prefix is CSM's own convention
  (used in exactly 2 places, `SessionLifecycleService.php:83,258`), not
  Claude Code's, and session *tracking* is sidecar-existence-based, not a
  `cc-*` glob (`SessionService.php`'s own comment: "must include every real
  tmux session on the box, not just cc-* ones" — bare/adopted sessions
  already work this way). Low risk either way; going with a distinct `ag-`
  prefix for Antigravity-spawned sessions, reusing the existing adoption
  path rather than inventing a new one.
- `HookService::app_hooks_status()` is already a clean, data-driven
  `list<{event, command, present}>` design — the Claude/Antigravity split
  mostly just needs a different *settings-file shape* (flat top-level
  `hooks[event]` vs. a named/grouped `hooks.json`), not a different
  algorithm. Confirmed while reading the real code before starting.
- Headless-mode testing turned out to be a poor proxy for the
  permission-prompt question — noted above, deferred to Phase 3 with the
  right (interactive) test setup instead of chasing it further in headless
  mode.

## Phases

**Phase 0 — groundwork — DONE**
- Added an `agent` column to the `sidecars` table (`SqliteDb::sessions_schema()`),
  plus `SqliteDb::add_column_if_missing()` so an existing (tmpfs, but not
  guaranteed-fresh-since-last-reboot) `sessions.sqlite` gets it retroactively
  rather than breaking every write until the next reboot. Every real
  `write_sidecar()` call site now passes `agent` explicitly (either the
  resolved adapter's id, or `$existingSidecar['agent'] ?? 'claude'` when
  preserving across a partial rebind - same established pattern already
  used for `workdir`/`spawned_at`).
- Added `Config::antigravity_bin()` (`ANTIGRAVITY_BIN` env var), mirroring
  `claude_bin()` exactly. **Deviation from the original plan text above**:
  skipped the generalized `Config::agent_bin(string $agent)` dispatcher -
  each adapter already owns calling its own binary getter directly
  (`ClaudeCodeAdapter` → `Config::claude_bin()`, `AntigravityAdapter` →
  `Config::antigravity_bin()`), so a `Config`-level dispatcher would've
  been a redundant extra indirection layer with nothing left to decide.

**Phase 1 — `AgentAdapter` interface, Claude-only refactor — DONE (see the
earlier commit this plan doc already described)**

**Phase 2 — Antigravity adapter: spawn + identity — DONE**
- `AntigravityAdapter::build_spawn_argv()` for the interactive TUI -
  `--model`/`--effort`/`--mode` when given, `assigned_id` always null (see
  the resolved open question above). `check_hooks()`/`install_hooks()` are
  deliberate honest stubs (`installed: false`, refuses) rather than fake
  success - Phase 3's job, not this one's.
- `SessionLifecycleService::create_cc_session()` gained a 4th `?string
  $agentId = null` parameter, whitelisted against `AgentRegistry::
  known_agent_ids()` (unrecognized/omitted falls back to Claude Code,
  byte-identical to every pre-Phase-2 caller). Wired through `Sessions.php`'s
  `dispatch_action()` case `'create'` (`request['agent']`) and
  `DashboardController::handleAction()`'s `case 'new'` (`$_POST['agent']`).
- Agent picker added to the New Session form (`PageView::AGENT_OPTIONS`,
  a plain view-layer constant mirroring `AgentRegistry`'s known ids rather
  than reaching into `HostAgent\` classes from container-side view code -
  see that constant's own docblock for why), defaulting to Claude Code.
  Verified live in a real browser (screenshot via the existing CDP test
  helper) - renders correctly, both options present, zero console errors.
- Reactive sidecar binding off the first hook's `conversationId` is NOT
  built yet - it's a Phase 3 concern (needs a real hook script to react
  from). A freshly-spawned Antigravity session today gets a sidecar with
  `claude_session_id: null` and sits there until Phase 3's first hook
  script binds it - confirmed via a real end-to-end test against a new
  `tests/fixtures/fake_agy` stand-in (mirrors `fake_claude`).

**Phase 3 — hooks**
- Write `~/.gemini/config/hooks.json` in the confirmed nested schema.
- New scripts under `host-agent/hooks/antigravity/`: `pre_tool_use.php`
  (MVP: always returns `{"decision":"allow"}`, stays observe-only —
  matches Claude Code's own philosophy for now), `pre_invocation.php`
  (~`UserPromptSubmit` + first-hook session bind), `post_tool_use.php`
  (clears `PendingToolStore`), `stop.php` (marks idle — note: `Stop`'s own
  payload carries no message text, so "last message" has to come from
  tailing `transcript_full.jsonl`'s last `PLANNER_RESPONSE` entry instead
  of a hook field the way Claude Code's `stop.php` gets it directly).
- Resolve open question #1 here, with a real interactive tmux-attached
  session, not headless mode.

**Phase 4 — transcript rendering**
- Tail `transcript_full.jsonl` the same append-only-polling way
  `TranscriptService` already tails Claude Code's file — different, simpler
  field mapping, same underlying mechanism.
- `CHECKPOINT` entries: render as a subtle divider or skip for v1.
- Confirm the tool-result entry shape once Phase 3 can run a real tool.

**Phase 5 — permission mode + display**
- Small map: confirmed `--mode` values are `accept-edits`/`plan` (plus the
  interactive default with no explicit flag) → CSM's canonical vocabulary.
- Dashboard/session-page mode control becomes agent-aware (don't show
  Claude-only modes for an Antigravity session or vice versa).

**Phase 6 — real interactive permission answering (defer past MVP)**
- The hard one: `PreToolUse`'s `decision` gates execution, and per the
  docs, hooks run **synchronously and block the agent loop**. Answering
  "allow/deny" from the web UI means the hook process itself has to wait on
  a real browser click before it can exit — a genuinely new request/response
  mechanism (nothing like it exists today; Claude Code's hooks are never
  blocking-decision-capable in the first place). Phase 3's MVP sidesteps
  this by always auto-allowing. Build this only once the rest works.

**Phase 7 — statusline/quota (defer)**
- Docs mention "Status Line Customization" for the TUI but give no detail.
  Not required; revisit later.

## Testing conventions

Mirror the existing test suite's isolation discipline: fixture paths for
`hooks.json`/transcript files via env-var overrides, a saved real
`transcript_full.jsonl` sample from this research as a first fixture, no
live `agy` process in the automated suite — same "never touch the real
thing" principle already used for `claude`/tmux throughout `tests/`.

## Recommended execution order

Phase 1 first regardless (pure refactor, immediately testable by the
existing suite, unblocks everything else) → Phase 2 → Phase 3 (resolving
open question #1 as its first step) → Phase 4, each shippable/testable on
its own before starting the next. Phases 5–7 are smaller and can slot in
once 2–4 are solid.
