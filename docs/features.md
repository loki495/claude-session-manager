# Feature reference

This is the canonical, exhaustive list of what Sessioneer can do.
It is the source of truth for the tool's *capabilities* (the atlas's
`.claude/feature-atlas/REPORT.md` is the source of truth for *finding/quality*
per subsystem — read these together when planning work).

The tool manages interactive coding-agent sessions (Claude Code, Antigravity,
OpenCode) running in tmux on this machine, from a single-user LAN web UI. The
table below shows everything it can do. The end sections break the same list
down **per agent** and flag the known **gaps / partial parity**.

> Generated from the feature atlas (`.claude/feature-atlas/`, 2026-08-26).
> Anything marked **verify** needs a live check before you rely on it as "the
> tool does X for agent Y"; the atlas audits flagged those as drift/gaps.

---

## Full capability list

### Session management
- List every running session, with name/title, working directory, last-active
  time, attached/detached, live context-usage %, and its flow state
  (`idle` / `working` / `blocked`).
- Create a new session for any agent in a chosen working directory, via an
  in-UI browsable folder picker.
- Open a session's detail view.
- Resume a previously-run session (per-session lock; sidecar re-linked when the
  coding agent rotates its transcript id).
- Kill / terminate a session (server-side re-validated against a fresh listing).
- Discover untracked **"bare"** sessions (an agent started by hand outside the
  tool) — list, kill, or **take over** them into the tool's tracked view.
- Take over a specific bare session by id, including a mid-sequence take-over.
- Clean up left-over/dead sessions.
- Per-session **title cascade** (derive a human-readable name).
- **Session-id self-heal** — keep following the correct transcript file across
  the agent's `/clear`, `/compact`, `/resume`, or fork operations.

### Blocked-prompt handling
- Detect and surface a **blocked permission prompt**, showing the **exact,
  untruncated** `tool_name`/`tool_input` of the pending tool call (captured from
  hooks, not scraped from a rendered pane).
- View the user message that triggered the block.
- **Approve** the permission, by option number (re-validated server-side).
- **Deny** it.
- Answer a prompt with **free text**.
- Answer a **multi-question** `AskUserQuestion` in one shot (computes and sends
  the whole tab-bar key sequence; no manual Prev/Next tab navigation).
- Answer a **single-question** `AskUserQuestion` from its content.
- Handle the **initial folder-trust dialog**.
- OpenCode-specific: answer **modal** permission prompts, **serve-API** questions,
  and the **plugin permission bridge**.
- **Escape / interrupt** a running session.
- **Send a message** from the compose bar.

### Mode / model switching
- Switch a session's **mode** (`manual` / `accept edits` / `plan` / `auto`).
- Switch the **model** (per-agent model picker).
- Switch the **effort** level (Antigravity-specific).
- Per-agent model/effort selectors in the UI.

### Transcript viewing
- View a live session's transcript.
- Render every block type: text, plan, tool_use, tool_result, task_notification,
  attachments, images.
- **Markdown** rendering (with a browser-side mirror kept in parity).
- **Collapsible** blocks (and collapsible markdown blocks).
- **Search** inside a session's transcript, then **jump to / highlight** the
  matching line.
- **Copy** any block to the clipboard.
- **History / paging** back through older messages (live and archived), with
  load-more.
- Live **polling** updates of the session page.
- Per-turn **thinking** indicator, **agent name** above replies, and **turn
  error** display.
- View **attachments** embedded in a message.

### Archives
- List **archived / dormant** sessions (an agent still has a transcript for).
- View an archived session's transcript **read-only**.
- Search content **across live and archived** sessions.
- Open an archived attachment / page through history (cwd-gated).

### Quota
- Show usage **quota per agent** (per-session live context %, weekly usage, reset
  countdowns), read from the agent's own status line / `/usage` API / SQLite —
  no external scraper.
- Per-agent quota display + a consolidated quota footer / table on the dashboard.

### Push notifications
- Register a phone/browser for **web push**.
- Get a notification when a session **blocks** or **finishes a task**, even with
  the tab closed.
- Get a notification on **quota events** (near / over / reset).
- Configure the **push-check timer interval**.
- Run **health check / diagnostics** from the dashboard health box (is the agent
  reachable, are hooks installed, is the timer running).

### Files & project info
- **Upload** a file into a session's project dir (`.claude/uploads/`) from the
  compose bar; list / view / delete one or all uploaded files.
- Read-only glance at a session's **plan / handoff** markdown files and its
  **`todo`** file.
- See **todo markers** rendered within the transcript / sidebar.

### Platform / access
- Single-user, **LAN-only** web UI (access control is the network bind, not a
  login), usable from a phone or any browser on the LAN.
- Mobile-Safari compatible (plain-ES5 frontend, no transpiler).
- Service-worker install for push.

---

## Per-agent coverage

Legend: **✓** supported · **◐** partial / works with caveats · **✗** not
available · **—** not applicable to that agent. The `verify` items are ones the
atlas flagged as drift/gap — confirm against a live session before treating them
as settled.

| Capability | Claude Code | Antigravity | OpenCode |
|---|---|---|---|
| List / open / kill session | ✓ | ✓ | ✓ |
| Create session (workdir browse) | ✓ | ✓ | ✓ |
| Resume session | ✓ | ◐ (reactive id bind; `--conversation` resume — **verify**) | ✓ |
| Take over / kill bare session | ✓ | ✓ | ✓ |
| Session-id self-heal | ✓ (hook) | ✓ (reactive `pre_invocation` binder) | ◐ (reactive from `opencode.db`; no session-id hook) |
| Idle / working / blocked status | ✓ (5 hooks) | ✓ (4 hooks + pane) | ◐ (serve-API + plugin + pane) |
| Blocked-permission detection | ✓ | ✓ | ✓ |
| Exact tool call shown for block | ✓ (PreToolUse hook) | ✓ (PreToolUse) | ◐ (plugin `PermissionStore`) |
| Approve / deny by option | ✓ | ✓ | ✓ |
| Free-text answer to a prompt | ✓ | ✓ | ◐ |
| Multi-question `AskUserQuestion` | ✓ (tab-bar sequence) | ✗ (no equivalent) | ✗ (uses its own serve-API questions) |
| Single-question `AskUserQuestion` | ✓ | ✗ | ✗ |
| Folder-trust dialog | ✓ | ◐ | ◐ |
| OpenCode permission/plugin bridge | — | — | ✓ |
| Escape / interrupt | ✓ | ✓ | ✓ |
| Send message | ✓ | ✓ | ✓ |
| Switch mode | ✓ | ✓ | ✗ (only `--auto`; no mode vocabulary) |
| Switch model | ✓ | ✓ | ✓ |
| Switch effort level | ✗ | ✓ | ✗ |
| View transcript | ✓ (JSONL) | ✓ (JSONL) | ✓ (SQLite) |
| Markdown / collapsible / copy block | ✓ | ✓ | ✓ |
| Search + jump-to-line (in-session) | ✓ | ◐ | ◐ |
| History / paging / attachments | ✓ | ✓ | ✓ |
| Live polling | ✓ | ✓ | ◐ (forward-poll line cursor bug in the atlas) |
| Turn error display | ◐ | ✓ | ◐ |
| List archived sessions | ✓ | ◐ (uses Claude-only resolver — **verify**) | ◐ (same resolver — **verify**) |
| Archived read-only view | ✓ | ◐ | ◐ |
| Search across live + archived | ✓ | ✗ (search is Claude-only) | ✗ (search is Claude-only) |
| Usage quota | ✓ (status line) | ✓ (`/usage` poll) | ✓ (SQLite) |
| Push on block / task-finished | ✓ | ✓ | ✓ |
| Push on quota events | ✓ | ✓ | ✓ |
| Health check / diagnostics | ✓ | ✓ | ✓ |
| File uploads | ✓ | ✓ | ✓ |
| Plan / handoff / todo glance | ✓ | ✓ | ✓ |

### Implementation status by agent (detail)

The matrix above is the at-a-glance view. This section states the actual
**implementation status** per agent per feature — status, concrete mechanism,
and the caveat. It's the more useful lens when you're deciding what a
"same feature for every agent" change has to touch.

Status legend: **Complete** · **Partial** (works with caveats / not fully wired) ·
**Missing** (not implemented) · **Broken** (implemented but produces a wrong
result) · **N/A** (not applicable to that agent).

| Feature | Agent | Status | Mechanism | Caveat |
|---|---|---|---|---|
| Blocked-permission detection | Claude Code | Complete | `PromptParser` pane-scrape + `PermissionRequest`/`PreToolUse` hooks | Multi-question tab-bar path is the only pane-only carve-out |
| | Antigravity | Complete | `AntigravityPromptParser` (substring match; no dedicated hook) | Content comes from the pane, not a hook payload |
| | OpenCode | Partial | `OpenCodePromptParser` (structural modal) + serve-API + `sessioneer-permissions` plugin | Permission bridge is authoritative; `permission.ask` hook dormant in opencode 1.18.21 |
| Session-id self-heal | Claude Code | Complete | `session_start` hook rebinds sidecar on `/clear`/`/compact`/`/resume`/`--fork` | Needs a real transcript + not-already-live guard |
| | Antigravity | Complete | `pre_invocation` hook reactively binds real `conversationId` on first firing | No up-front id; identity learned post-spawn |
| | OpenCode | Partial | Reactive binding from `opencode.db` (`ses_*`); **no session-id hook** | `sessioneer-status` plugin not shipped — `check_hooks`/`install_hooks` are stubs |
| Multi-question `AskUserQuestion` | Claude Code | Complete | `build_multi_question_key_sequence` sends the whole tab-bar sequence in one shot | 2+ questions only; single-question uses the pane path |
| | Antigravity | N/A | — | No equivalent mechanism |
| | OpenCode | N/A | — | Answers via its own serve-API questions instead |
| Archived detail/browse | Claude Code | Complete | `SessionDetailService` detail path | — |
| | Antigravity | Broken | Resolved via Claude-only `TranscriptService::find_transcript_path` | Opens "Session not found" (atlas `archived-sessions` #1) |
| | OpenCode | Broken | Same Claude-only resolver | Same bug |
| Content search (live + archived) | Claude Code | Complete | `search_transcript_file` / `list_all_transcripts` | — |
| | Antigravity | Missing | Search is Claude-only | Content invisible to dashboard + in-page search (atlas #2) |
| | OpenCode | Missing | Same | Same |
| Model switch | Claude Code | Complete | Drives `/model` picker; `SelectableModel::PICKER_OPTIONS` | Rejected while blocked |
| | Antigravity | Complete | `set_antigravity_model`; re-captures picker after each keypress (drop-safe) | Globally overwrites account-wide default (no session-only key) |
| | OpenCode | Partial | Adapter supports `--model`, but **not reachable** from the New Session UI | `create_agent_session()` only forwards `enable_task_tools`/`starting_mode` (atlas `agent-abstraction`) |
| Switch mode | Claude Code | Complete | `set_mode` (BTab relative steps) | — |
| | Antigravity | Complete | `--mode` flight; `SETTING_MODE_FLAGS` | `manual`/`auto` have no flag (omitting = manual default) |
| | OpenCode | Missing | No mode vocabulary; only boolean `--auto` | By design, not a bug |
| Transcript view | Claude Code | Complete | Jsonl reader (`TranscriptService`) | — |
| | Antigravity | Complete | Jsonl reader (`AntigravityTranscriptService`) | — |
| | OpenCode | Complete | SQLite reader (`OpenCodeTranscriptService`) | Live forward-poll line-cursor bug (atlas `session-view` #1) |
| Usage quota | Claude Code | Complete | Statusline marker captures status line JSON | Shows "Quota unavailable" until a session renders its status line once |
| | Antigravity | Complete | `/usage` poll (`antigravity_quota_poll.php`) | A SUCCESS w/ unparseable bucket wipes prior state (atlas `quota` #1) |
| | OpenCode | Complete | Direct read-only SQLite (`opencode_quota_state`) | Query block can throw past its connect-only try/catch (atlas `quota` #3) |

---

## Known gaps & partial parity (from the atlas audits)

These are the concrete spots where parity is missing or only partial. Treat this
list as the "same features for each agent" work queue.

1. **Content search is Claude-Code-only** — `search_transcripts` and the
   per-session search use `TranscriptService` (Claude JSONL) exclusively, so
   Antigravity and OpenCode content is invisible to the dashboard search box and
   in-page search. (atlas: `archived-sessions` finding 2)
2. **Archived detail/browse uses the Claude-only resolver** — the archived
   *detail* path resolves transcripts through `TranscriptService::
   find_transcript_path` (Claude) while the paging/attachment path already uses
   the agent-agnostic `TranscriptRouter`. Antigravity/OpenCode archived rows open
   "Session not found". (atlas: `archived-sessions` finding 1)
3. **OpenCode forward-poll line cursor** — the live detail page can silently stop
   updating once any non-renderable row precedes the newest renderable message.
   (atlas: `session-view` finding 1)
4. **OpenCode hooks are not production-wired** — the adapter's
   `check_hooks`/`install_hooks` are honest stubs (a `sessioneer-permissions` plugin is
   installed; a full `sessioneer-status` hook/plugin is planned but not shipped). So
   OpenCode lacks the hook-fed status and session-id self-heal the others have.
   (atlas: `agent-abstraction`)
5. **Antigravity/OpenCode model & create-option reachability** —
   `create_agent_session()` only forwards `enable_task_tools`/`starting_mode` to the
   adapter, so Antigravity's `--model`/`--effort` and OpenCode's
   positional-workdir/`--model`/`--agent` options are not reachable through the
   New Session UI even though the adapters support them.
6. **Mode switching** is absent for OpenCode (no mode vocabulary; only the
   boolean `--auto`), and **effort switching** is Antigravity-only — by design,
   not a bug, but a real parity gap if you want the same set of controls for
   every agent.

For the authoritative per-finding detail (file:line, impact, recommended fix) for
items 1–5, see `.claude/feature-atlas/REPORT.md`.
