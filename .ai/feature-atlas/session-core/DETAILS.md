---
id: session-core
name: Session model + tmux/process primitives
owned_paths:
  - host-agent/lib/Services/SessionService.php
  - host-agent/lib/Services/TmuxService.php
  - host-agent/lib/Services/ProcessRunner.php
  - host-agent/lib/Services/ProcessInspector.php
last_scanned_commit: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# session-core — Session model + tmux/process primitives

## 1. Purpose

This is the foundational session-domain layer every other session feature builds on. Four
classes, all in `HostAgent\Services`, all run **host-native** (never inside the Docker container —
see the two-runtime protocol constraint below):

- **`SessionService`** owns the session **listing + one-session model** every downstream session
  feature reads: `list_all_sessions()`, the per-row `build_session_entry()` shape, the shared
  `title_cascade()` fallback chain, the session-id self-heal, and the New-Session folder browser
  (`browse_dir()` / `create_dir()`). It was carved out of a single 1940-line class in the 2026-08-24
  readability audit (`host-agent/lib/Services/SessionService.php:12`), so it has **zero** dependency
  on the six services split out alongside it — everything interactive (send/answer/mode/model),
  lifecycle (create/resume/kill/cleanup), archived/dormant browsing, detail/history/attachment
  paging, and bare-process takeover now lives elsewhere.
- **`TmuxService`** is the single place every `tmux` command is issued — `tmux_run()` wraps
  `ProcessRunner` with the configured `-S` socket path, plus the small amount of parsing needed to
  turn raw `tmux` output into directly-usable shapes (pane pids/titles, session activity, pane
  content).
- **`ProcessRunner`** is the generic, shell-injection-free process primitive behind *all* tmux and
  non-tmux invocations across the whole host-agent — `proc_open()` with the command as an **array**,
  never a shell string.
- **`ProcessInspector`** reads `/proc` directly to discover and correlate real host processes (find
  every `claude` process, map pid→ppid for ancestry checks, resolve a process's real start time).
  Nothing here touches tmux — it's pure process-table inspection.

Out of scope for this subsystem (owned by sibling subsystems, documented only as consumers):
create/resume/kill/cleanup (`session-lifecycle`, `SessionLifecycleService`), prompt parsing &
answer/navigation (`prompt-interaction`, `PromptParser`/`PromptInteractionService`), transcripts &
detail/history/attachment (`session-view`, `TranscriptService`/`TranscriptRouter`/`SessionDetailService`),
hook-fed live state (`session-status-state`, `SessionStatusStore`/`PendingToolStore`), archived
session browsing + search (`ArchivedSessionService`), and bare-process discovery/take-over
(`BareProcessService`).

## 2. Ownership boundary

**In scope (owned paths):**

- `host-agent/lib/Services/SessionService.php`
- `host-agent/lib/Services/TmuxService.php`
- `host-agent/lib/Services/ProcessRunner.php`
- `host-agent/lib/Services/ProcessInspector.php`

**Out of scope — neighboring subsystems explicitly named (documented as dependencies, never owned
here):** `session-lifecycle` (`SessionLifecycleService` — create/resume/kill/cleanup),
`prompt-interaction` (`PromptParser`, `PromptInteractionService` — prompt parsing + answering),
`session-view` (`TranscriptService`, `TranscriptRouter`, `OpenCodeTranscriptService`,
`AntigravityTranscriptService`, `SessionDetailService` — transcripts/detail/history/attachments),
`session-status-state` (`SessionStatusStore`, `PendingToolStore`, the hook scripts),
`host-agent-runtime` (`Config`, `SqliteDb`), plus `StatuslineMarkerService`,
`OpenCodePromptParser`/`OpenCodeQuestionService`/`AntigravityPromptParser`, `SelectableModel`/
`AntigravitySelectableModel`, `AgentRegistry`, and the shared `SidecarStore`.

`TmuxService` and `ProcessRunner` and `ProcessInspector` are **shared primitives** that many other
subsystems depend on — they live in this subsystem's owned paths, but are effectively co-owned
(see §9).

## 3. Key implementation files

| File | Responsibility |
|------|----------------|
| `host-agent/lib/Services/SessionService.php` | The session model + listing. `list_all_sessions()` builds the full dashboard set (tracked + bare), `build_session_entry()` per row, the title cascade, session-id self-heal, last-message preview, and the folder browser. |
| `host-agent/lib/Services/TmuxService.php` | All tmux commands + the parsing immediately around them (pane pids/titles, activity freshness, pane capture, all-panes map, attach hint). |
| `host-agent/lib/Services/ProcessRunner.php` | `proc_open()` array-form process primitive (tmux, kill signals, claude-quota, curl, systemctl). |
| `host-agent/lib/Services/ProcessInspector.php` | `/proc` scan: build pid→ppid map, start-time resolution, descendant check, `claude` process discovery, owning-pane lookup. |

## 4. Public interfaces & contracts

### `SessionService::title_cascade()` — `SessionService.php:44`
`static title_cascade(?string $aiTitle, ?string $livePaneTitle, ?string $workdir, string $fallbackName): string`

Pure fallback: ai-title → live-pane-title → workdir basename → raw name. Empty strings treated the
same as null, so a title never comes back blank. No side effects; safe for both live and dormant
sessions. Mirrors `NotificationContentBuilder::push_notification_title()`'s cascade
(`SessionService.php:40`).

### `SessionService::session_title()` — `SessionService.php:67`
`static session_title(?string $claudeSessionId, ?string $livePaneTitle, ?string $workdir, string $name): string`

`build_session_entry()`'s `title` field. Resolves `$claudeSessionId` to its transcript ai-title
(via `TranscriptRouter::find_transcript_path()`), but **only** for Claude Code paths — Antigravity
and OpenCode have no ai-title-equivalent, so they fall straight through to the live pane title /
workdir / name (`SessionService.php:76`). OpenCode's `session.title` is read instead via
`OpenCodeTranscriptService::find_session_title()`.

### `SessionService::build_session_entry()` — `SessionService.php:98`
`static build_session_entry(array $tmuxSession, array $claudeProcs, array $ppidMap): array`

Builds one tracked session's list-row/detail data from already-fetched process state — the
per-session model shared by `list_all_sessions()` (one call per tmux session) and
`SessionDetailService::session_detail()` (one call, by name).

Inputs (per docblock `SessionService.php:93`): `$tmuxSession` =
`{name:string, activity:int, attached:bool}`; `$claudeProcs` =
`array<int,{pid:int, cwd:?string, started_at:?int}>`; `$ppidMap` = `array<int,int>`.

Return (full shape documented at `SessionService.php:96`):
`name, activity, attached, pid:?int, workdir:?string, spawned_by_csm:bool, agent:string,
agent_label:string, title:string, working:bool, blocked_reason:?string, resume_hint:?string,
prompt_context:?string, prompt_options:array<int,{number:int,label:string}>,
prompt_multi_question:bool, prompt_is_folder_trust:bool, prompt_tool_name:?string,
prompt_tool_input:?array, prompt_questions:?array, current_mode:?string, current_model:?string,
current_antigravity_model:?string, last_turn_error:?string, claude_session_id:?string,
last_message:?array, context_used_percentage:?int, git_worktree:?string`.

Key semantics:
- **`pid`**: the first matching `claude` proc whose ancestry reaches a pane pid of the tmux session
  (`SessionService.php:100-110`).
- **agent / agent_label**: `agent` from the sidecar (`claude` default), `agent_label` from
  `AgentRegistry` with a `\Throwable` catch defaulting to `'Claude Code'` (`SessionService.php:112-113, 287-291`).
- **mode/working/blocked**: read **EXCLUSIVELY** from `SessionStatusStore` (2026-08-22 decision) for
  every tool except `AskUserQuestion` — no pane-scraping fallback (`SessionService.php:221-233, 260-261`).
- **current_model**: read from the transcript, not the pane (`SessionService.php:269-271`); resolved
  via `SelectableModel::family_from_raw_model()`.
- **current_antigravity_model**: only ever readable from the pane footer (`SessionService.php:277`).
- **last_turn_error**: carried from `SessionStatusStore` because Antigravity writes nothing to its
  transcript on a failed turn (`SessionService.php:285`).
- **prompt_questions**: the full multi-question set from the hook payload, only when ≥2 questions
  (`SessionService.php:245-248`).

### `SessionService::self_heal_claude_session_id()` — `SessionService.php:347`
`static self_heal_claude_session_id(string $sessionName, ?array $sidecar, ?string $claudeSessionId, ?string $liveId): ?string`

Cross-checks the sidecar's `claude_session_id` against `StatuslineMarkerService`'s live-pane signal
and self-heals a stale/wrong one. Only overwrites when (a) a sidecar actually exists and (b) the
live id resolves to a real transcript file (`SessionService.php:353-359`) — the same "don't trust an
id nothing backs" rule `session_start.php`'s own hook enforces, applied as a second independent
layer. Writes the healed id back via `SidecarStore::write_sidecar()` preserving workdir/spawned_at/
spawned_by_csm/agent (`SessionService.php:361-367`). No-op (returns unchanged) when there's no
sidecar, no live id, or the ids already match.

### `SessionService::session_last_message()` — `SessionService.php:384`
`static session_last_message(?string $claudeSessionId): ?array` (`{role:?string, timestamp:?string, blocks:array<int,{kind:string,text:string}>}|null`)

The single most recent transcript entry, for the dashboard's per-row preview and a blocked prompt's
card's "what led up to it". Returns null when there's no id / no transcript / no entries.

### `SessionService::list_all_sessions()` — `SessionService.php:408`
`static list_all_sessions(): array` (`{sessions: array<int,array>, bare: array<int,array>}`)

The **root entry point** of the listing layer. Sequence (`SessionService.php:410-422`): list tracked
tmux sessions (sidecar-gated), scan `claude` procs + ppid map, enumerate **all** tmux panes (to
preserve adopted non-`cc-*` sessions), prune orphaned sidecars. Then per tracked session calls
`build_session_entry()`, collects tracked pids, sorts by activity desc (`SessionService.php:437`).
Any `claude` proc not inside a tracked session is put in `bare[]`, enriched with an owning pane's
session name/title if it lives in some other tmux session (`SessionService.php:439-452`), sorted by
started_at desc.

### `SessionService::browse_dir()` — `SessionService.php:471`
`static browse_dir(string $path): array` (`{ok:bool, path?:string, parent?:?string, dirs?:string[], message?:string}`)

Lists immediate subdirectories (hidden included) of `$path`, for the New Session folder browser.
`$path` (after symlink resolution) must be `Config::home_root()` or a descendant — anything else
rejected (`SessionService.php:482`). Empty `$path` defaults to `Config::www_root()`.

### `SessionService::create_dir()` — `SessionService.php:522`
`static create_dir(string $parentPath, string $name): array` (same `browse_dir()` shape)

Creates a new subdirectory for the "New folder" button. Same `home_root()` boundary check on
`$parentPath` (`SessionService.php:533`); `$name` restricted to a bare basename (no `/`, no `.`/`..`,
non-empty) (`SessionService.php:539`). On success returns `browse_dir()` of the new folder itself —
same shape the caller already renders.

### `TmuxService` — `TmuxService.php:15`

- `ensure_tmux_socket_dir(): void` — `TmuxService.php:26`. tmux only auto-creates its socket
  parent under its default naming; with an explicit `-S` it expects the dir to exist. `/tmp` is
  wiped on reboot, so every call re-checks. Cheap `is_dir` guard.
- `tmux_run(array $args): array` (`{exit:int,stdout:string,stderr:string}`) — `TmuxService.php:39`.
  The only tmux entry point. Prepends `['tmux', '-S', Config::tmux_socket()]` and delegates to
  `ProcessRunner::run_process()`.
- `list_all_tmux_sessions(): array` (`array<int,{name,activity,attached}>`) — `TmuxService.php:71`.
  Every real tmux session regardless of name. **Activity is sourced from `#{window_activity}`, NOT
  `#{session_activity}`** — found live 2026-08-08, `session_activity` froze at spawn time for 8+
  real hours while `window_activity` updated in real time (`TmuxService.php:53-67`).
- `list_tracked_tmux_sessions(): array` — `TmuxService.php:116`. The sidecar-gated subset of
  `list_all_tmux_sessions()`; **sidecar existence, not the `cc-*` prefix, is the whitelist** every
  state-changing action re-checks against (`TmuxService.php:110-112`).
- `tmux_session_panes(string $session): array` (`{pids:int[], title:?string}`) — `TmuxService.php:128`.
  Pane pids + the first pane's cleaned title. Uses `list-panes -s` with `#{pane_pid}|#{pane_title}`.
- `tmux_capture_pane(string $session): string` — `TmuxService.php:162`. Current visible pane content
  via `capture-pane -p -J` (`-J` rejoins soft-wrapped lines so long command/permission text survives
  intact — `TmuxService.php:164-168`). Returns `''` on non-zero exit.
- `all_tmux_panes(): array` (`array<int,{session:string,title:?string}>` keyed by pane_pid) —
  `TmuxService.php:184`. Every pane on the host, across every session, used to enrich "bare" claude
  procs with a session name/title.
- `tmux_attach_hint(string $sessionName): string` — `TmuxService.php:214`. The exact `tmux -S <sock>
  attach -t <name>` command shown next to a blocked prompt (never auto-sent — blindly injecting
  "approve" could rubber-stamp a destructive call).

### `ProcessRunner` — `ProcessRunner.php:12`

- `run_process(array $cmd): array` (`{exit:int,stdout:string,stderr:string}`) — `ProcessRunner.php:18`.
  The generic `proc_open()` primitive. Command is **always an array** (no shell string → no
  metacharacter injection surface — this is a project-wide hard rule). Opens pipes for stdin/stdout/
  stderr, feof-drains both output pipes, `proc_close()` returns the real exit code. Returns
  `{exit:-1, stdout:'', stderr:'failed to start process'}` when `proc_open` fails
  (`ProcessRunner.php:28-29`). Note: it does **not** set a timeout — callers (tmux, quota) rely on
  the tools' own short `--max-time` (for curl) or fast-exit operation in practice.

### `ProcessInspector` — `ProcessInspector.php:14`

- `const CLK_TCK = 100` — `ProcessInspector.php:16`. USER_HZ = 100 on Linux/x86_64 since the 2.6 era,
  used to convert `/proc/<pid>/stat` starttime ticks to seconds.
- `build_ppid_map(): array` (`array<int,{pid:int,ppid:int}>` keyed by pid) — `ProcessInspector.php:21`.
  Walks `/proc/[0-9]*`, reads `stat`, splits after the `)` that ends the comm field; `$fields[1]`
  is field 4 (ppid). The stat field math is documented at `ProcessInspector.php:43`.
- `process_start_time(int $pid): ?int` — `ProcessInspector.php:52`. `$fields[19]` is field 22
  (starttime, ticks). Converts to epoch via `/proc/uptime`: `bootEpoch + ticks/CLK_TCK`.
- `is_descendant(int $pid, int $ancestorPid, array $ppidMap, int $maxDepth = 25): bool` —
  `ProcessInspector.php:85`. Walks the ppid chain up to `$maxDepth` (25) to protect against cycles /
  runaway orphaned chains.
- `find_claude_processes(): array` (`array<int,{pid:int, cwd:?string, started_at:?int}>`) —
  `ProcessInspector.php:114`. Scans `/proc` for every `claude` process regardless of starter.
  **Matches `argv[0]` by basename, not the full `Config::claude_bin()` path** — found live 2026-08-08:
  a PATH-resolved `claude` invocation has `argv[0] === "claude"` verbatim which never equals the
  full configured path. Also must match `argv[0]` specifically (not "anywhere in argv") to avoid
  false-matching the tmux server that keeps the whole `new-session ... claude` command line as its
  own argv (`ProcessInspector.php:137-145`). `cwd` via `readlink /proc/pid/cwd`; `started_at` via
  `process_start_time`.
- `find_owning_pane(int $pid, array $allPanes, array $ppidMap): ?array` (`{session:string,title:?string}|null`) —
  `ProcessInspector.php:168`. Finds which pane a bare proc runs under by walking ancestry against an
  already-fetched `TmuxService::all_tmux_panes()` map.

## 5. Major call sites

### In-repo (same host-agent process / same namespace)
- **`dispatch_action()`** (`host-agent/lib/Sessions.php`) is the dispatcher that reaches the entry
  points over the protocol seam:
  - `Sessions.php:35` → `SessionService::list_all_sessions()` (action `list`).
  - `Sessions.php:160` → `SessionService::browse_dir()` (action `browse_dir`).
  - `Sessions.php:163` → `SessionService::create_dir()` (action `create_dir`).
- **`SessionDetailService::session_detail()`** (`SessionDetailService.php:44`) calls
  `SessionService::build_session_entry()` (and reads `ProcessInspector::find_claude_processes()` +
  `build_ppid_map()`), producing the detail page / sidebar poll model. This is the *second* consumer
  of `build_session_entry()` beyond `list_all_sessions()`.
- **`ArchivedSessionService`** uses `SessionService::title_cascade()` at `ArchivedSessionService.php:42,57,182`
  and `list_all_sessions()` at `:126,161` to exclude live sessions from the archived list.
- **`BareProcessService`** reads `SessionService::list_all_sessions()` at `BareProcessService.php:134,150`;
  uses `ProcessInspector::find_claude_processes()`/`find_owning_pane()`/`build_ppid_map()` at
  `:38,49,90,211,259`; `TmuxService::all_tmux_panes()`/`tmux_capture_pane()` at `:49,96`;
  `ProcessRunner::run_process(['kill','-TERM',...])` at `:59`.
- **`SessionLifecycleService`** (`host-agent/lib/Services/SessionLifecycleService.php`) uses
  `TmuxService::tmux_run()` (`:81,248,295,322`), `list_all_tmux_sessions()` (`:107,262`),
  `list_tracked_tmux_sessions()` (`:145,289,317`) for create/kill/cleanup — the create/just-spawned
  still-alive check uses `list_all_tmux_sessions()` because it runs before the sidecar exists.
- **`PromptInteractionService`** is the heaviest tmux consumer (send/answer/mode/model/escape): many
  calls at `PromptInteractionService.php:51,78,86,87,141,149,161,187,233,237,247,267,273,276,282,329,347,361-364,390,394,426,440,486,515-518,578,594,596,611,631,635,639,673,683,689,692,707`.
- **`QuotaService::get_quota()`** reads `TmuxService::tmux_capture_pane()` at `QuotaService.php:52`
  (for the Antigravity live-pane context percentage).
- **`StatuslineMarkerService`** parses pane content the same way `TmuxService::tmux_capture_pane()`
  already does (referenced throughout `StatuslineMarkerService.php`); it is the **writer** side of the
  live-pane marker that `self_heal_claude_session_id()` cross-checks against.
- **Hooks** (`host-agent/hooks/session_start.php:69`, `hooks/antigravity/stop.php:189`) use
  `TmuxService::tmux_run()`/`tmux_capture_pane()`; the hook files' docblocks repeatedly reference
  `build_session_entry()` as the reader of the state they write.
- **Standalone host scripts** (not via socket): `host-agent/push_trigger.php:66` calls
  `SessionService::list_all_sessions()['sessions']` (wrapped so the whole script doesn't die — see the
  `push_trigger.php:56-64` rationale), `host-agent/antigravity_quota_poll.php:42` and
  `host-agent/opencode_diagnose.php:40` use `ProcessRunner::run_process()`.
- **Other `ProcessRunner` consumers** (shared primitive): `OpenCodeQuestionService.php:41,105`
  (`curl` the opencode serve HTTP API), `PushTimerService.php:118,125,128` and
  `PushHealthService.php:102,106,117` (`systemctl --user`).

### The two-runtime protocol seam (container → host)
- Web UI controllers **never** call this subsystem directly — every request goes
  `AgentClient::agent_call(['action' => ...])` → UNIX socket → `host-agent/agent.php:38`
  (`dispatch_push_action() ?? dispatch_action()`) → the SessionService branch:
  - `DashboardController::index()` (`src/lib/Controllers/DashboardController.php:26`) → `list` →
    `list_all_sessions()['sessions']`/`['bare']`.
  - `DashboardController::fragment()` (`:202`) and `DashboardController::list()` (`:231`) → `list`.
  - `BrowseController::browse()` (`src/lib/Controllers/BrowseController.php:30`) → `browse_dir`;
    `BrowseController::mkdir()` (`:37`) → `create_dir`.
  - `SessionController::show()` → `session_detail` (indirectly through `SessionDetailService`, which
    calls `build_session_entry()`).
- **Cross-cutting connector**: `App\AgentClient` (`src/lib/AgentClient.php`) is the only bridge
  between the container and this subsystem. The subsystem is reached **only** via the JSON
  action dispatcher — no tmux/`/proc` access ever happens inside the container (that's the whole
  point of the host-native agent).

## 6. Tests

Primary coverage in `tests/test_sessions_lifecycle.php` (127KB, the largest suite) and split out in
three smaller suites. All follow the isolation rule: tests point `TMUX_SOCKET`/`CLAUDE_BIN`/sidecar/
DB paths at fixtures via `tests/.env.testing` and refuse to run if `TMUX_SOCKET` resolves to the real
socket (`test_sessions_lifecycle.php:33-49`). They use `tests/fixtures/fake_claude` /
`tests/fixtures/fake_opencode` as stand-ins — never a real (billable) claude — and `run.sh` cleans up
the isolated tmux server + fixture processes on exit.

- **`tests/test_sessions_lifecycle.php`** — the main suite. Covers `browse_dir()` happy + sad paths
  (`:417-467`: includes hidden dirs, sorted; parent chain; rejects `/etc`, nonexistent, `..`
  traversal, empty/slashed names), `create_dir()` happy + sad (`:443-469`), the tmux socket-directory
  self-heal via `tmux_run()` (`:853-871`), `tmux_attach_hint()` (`:408-410`),
  `list_all_tmux_sessions()`'s `#{window_activity}` freshness (`:669-...`), the `find_claude_processes()`
  bare-name regression (`:987-1014`), bare-process owning-pane enrichment (`:1021-...`), the
  `#{}`/`tmux_capture_pane` `-J` line-wrap rejoin (`:1526-1548`), and — the crown jewel —
  `build_session_entry()`'s hook-fed `SessionStatusStore` wiring (`:1600-1699`): `blocked_reason`/
  `prompt_context`/`prompt_options`/`prompt_tool_name`/`current_mode` all come from the hook-fed
  status file against a **bare `cat` pane that shows nothing**, AskUserQuestion deliberately bypasses
  the hook-fed path, no-status-file yields nothing (no fallback), and the folder-trust dialog is the
  one case still pane-scraped. Shape: happy + sad.
- **`tests/test_transcript.php`** — `title_cascade()` pure logic (`:1100-1107`) and `session_title()`
  against a fixture HOME because it resolves a real transcript path internally (`:1109-1155`), plus
  `ArchivedSessionService`'s use of the same cascade. Happy + sad.
- **`tests/test_statusline_marker.php`** — `self_heal_claude_session_id()` (`:336-375`): heals when the
  live marker resolves to a real transcript, preserves workdir/spawned_at/spawned_by_csm, refuses to
  trust a phantom id, no-ops on no-sidecar / no-marker / already-matching. Happy + sad.
- **`tests/test_opencode_spawn.php`** — drives `list_all_sessions()` against a spawned `oc-*` session
  to confirm it appears with the right agent field (`:50,84`).
- **`tests/test_agent_client_protocol.php`** — the socket-level end-to-end for `browse_dir`/`create_dir`
  (`:33-56`: default path→`WWW_ROOT`, `/etc` rejected, `create_dir` really writes to disk, path-escape
  rejected). Happy + sad.
- **`tests/test_ui_smoke.php`** (`:208`) — passes the canned agent's `browse_dir` through as JSON.
- Incidental tmux `tmux_run()`/`tmux_capture_pane()` usage throughout `tests/test_antigravity_*`,
  `test_session_hook.php`, and `test_session_replay*.php` (used as the fixture harness, not the
  subsystem under test).

## 7. Dependencies

### Upstream (what this subsystem reads)
- **`Config`** (`host-agent/lib/Services/Config.php`) — every path/threshold. Read directly by
  `TmuxService` (`tmux_socket()`, `Config.php:87`), `SessionService` (`home_root()`, `www_root()`),
  and `ProcessInspector` (`claude_bin()`, `claude_bin()` basename). Config is env-overridable so tests
  can isolate.
- **`SidecarStore`** (`host-agent/lib/Stores/SidecarStore.php`) — reads/writes the per-session
  sidecar row (`read_sidecar`/`write_sidecar`) that `build_session_entry()`/
  `self_heal_claude_session_id()` depend on; `prune_orphaned_sidecars()` is called from
  `list_all_sessions()` (`SessionService.php:422`). The store itself is SQLite-only (migrated off JSON
  2026-08-24).
- **`SessionStatusStore`** (`host-agent/lib/Stores/SessionStatusStore.php`) — `build_session_entry()`
  reads mode/working/blocked **exclusively** from here (`SessionService.php:137-140, 261`), the 2026-08-22
  decision. This is the contract that makes `session-core` depend on `session-status-state`.
- **`PendingToolStore`** (`host-agent/lib/Stores/PendingToolStore.php`) — read only in the
  `AskUserQuestion` branch to augment the prompt with the pending tool (`SessionService.php:219`).
- **`TranscriptRouter`** / **`TranscriptService`** / **`OpenCodeTranscriptService`** /
  **`AntigravityTranscriptService`** (`host-agent/lib/Services/`) — `session_title()` resolves the
  transcript ai-title (`SessionService.php:69-81`); `session_last_message()` reads the latest entry
  (`SessionService.php:390-402`); model resolution uses `find_transcript_path()`/`find_latest_model()`.
- **`StatuslineMarkerService`** — `build_session_entry()` parses the pane marker for the self-heal
  cross-check and the context%/worktree (via `parse_marker_from_pane()`,
  `SessionService.php:252-253, 324-325`).
- **`PromptParser`**, **`OpenCodePromptParser`**, **`AntigravityPromptParser`**,
  **`OpenCodeQuestionService`** — prompt/blocked-prompt parsing in the agent-branch of
  `build_session_entry()` (`SessionService.php:166-233`).
- **`SelectableModel`** / **`AntigravitySelectableModel`** — resolve raw model ids to a display family
  (`SessionService.php:271, 277`).
- **`AgentRegistry`** (`host-agent/lib/Agents/AgentRegistry.php`) — maps `agent` → label
  (`SessionService.php:288`).
- **`SqliteDb`** (`host-agent/lib/Stores/SqliteDb.php`) — the shared connection helper behind
  `SidecarStore`/`SessionStatusStore`/`PendingToolStore`; documented in its own docblock as the
  reason a single request can read the sidecar + status row together (`SqliteDb.php:34`).

### Downstream (what depends on this subsystem)
- **`SessionLifecycleService`** (create/resume/kill/cleanup) — heavy `TmuxService` consumer.
- **`PromptInteractionService`** — heavy `TmuxService` + `tmux_capture_pane` consumer.
- **`SessionDetailService`** — `build_session_entry()`, `title_cascade()`.
- **`ArchivedSessionService`** — `list_all_sessions()`, `title_cascade()`.
- **`BareProcessService`** — `list_all_sessions()`, `ProcessInspector`, `TmuxService`,
  `ProcessRunner`.
- **`QuotaService`**, **`StatuslineMarkerService`** — `tmux_capture_pane()`.
- **`PushDeliveryService`** (via `push_trigger.php`) — `list_all_sessions()`.
- **`OpenCodeQuestionService`**, **`PushTimerService`**, **`PushHealthService`** — `ProcessRunner`.
- Hooks (`session_start.php`, `permission_request.php`, `user_prompt_submit.php`, `stop.php`,
  `antigravity/*`) — write the state this subsystem reads, and use `TmuxService`/`tmux_capture_pane`.

## 8. Data & schema

### SQLite tables (shared `Config::sessions_sqlite_path()`, tmpfs, schema in `SqliteDb::sessions_schema()` at `SqliteDb.php:115-142`)
- **`sidecars`** (written by `SidecarStore`): `session_name TEXT PRIMARY KEY`, `workdir TEXT`,
  `spawned_at INTEGER`, `claude_session_id TEXT`, `spawned_by_csm INTEGER` (three-state
  true/false/absent — see `SidecarStore.php:104-106`), `agent TEXT` (added 2026-08-24 via
  `add_column_if_missing`, `SidecarStore.php:32`; default `'claude'`).
- **`session_status`** (written by the hooks via `SessionStatusStore`): `session_name TEXT PK`,
  `status TEXT`, `blocked_json TEXT`, `mode TEXT`, `last_message TEXT`, `last_turn_error TEXT`,
  `updated_at INTEGER`.
- **`pending_tools`** (written by PreToolUse hook via `PendingToolStore`): `session_name TEXT PK`,
  `tool_name TEXT`, `tool_input_json TEXT`, `written_at INTEGER`.

### Sidecar row shape read by `build_session_entry()` / `self_heal_claude_session_id()`
`{workdir:?string, spawned_at:?int, claude_session_id:?string, spawned_by_csm:?bool, agent:?string}`.

### SessionStatusStore row shape read by `build_session_entry()`
`{mode:?string, status:?string, blocked:?array, last_message:?string, last_turn_error:?string, updated_at:?int}`.

### `build_session_entry()` return shape
The full 26-key array documented at `SessionService.php:96` (and §4 above) — the canonical per-session
model row.

### Process-table / tmux state (read-only, never persisted)
- tmux: the `#{session_name}|#{window_activity}|#{session_attached}` list, pane pids/titles, captured
  pane text, and the all-panes map — all ephemeral, from `tmux_run()`.
- `/proc`: pid→ppid map, per-pid starttime, `claude` argv[0] basename match, `cwd` readlink. All
  derived live, never written by this subsystem.

## 9. Conventions / quirks

- **Two-runtime boundary**: all four classes run host-native only. The container never issues tmux or
  `/proc` calls — if it did, tmux would auto-start a server as a child of the container process, born
  inside the container's namespaces, unreachable from the host. Everything here is reached from the
  web UI only through the JSON action dispatcher (`agent.php → dispatch_action`).
- **proc_open array form**: `ProcessRunner::run_process()` and every command in this subsystem pass
  the command as an **array**, never a shell string — no metacharacter injection surface. This is a
  project-wide hard rule (root `CLAUDE.md`).
- **`build_session_entry()` reads mode/working/blocked exclusively from `SessionStatusStore`** (decided
  2026-08-22). A session with no status file just reports unknown/idle/no-prompt — no pane-scraping
  fallback, except two structural carve-outs: the **folder-trust dialog** (fires none of the hooks)
  and **`AskUserQuestion`'s CONTENT** (`SessionService.php:207-233`). This contract is the reason
  `session-core` depends on `session-status-state`.
- **Title cascade** (`title_cascade()`/`session_title()`): ai-title → live-pane-title → workdir
  basename → raw name; empty strings treated the same as null so a title is never blank. Reused by
  `ArchivedSessionService` and mirrored in `NotificationContentBuilder` so push titles match.
- **Session-id self-heal**: two independent layers (the SessionStart hook, plus
  `self_heal_claude_session_id()` at poll time) both enforce "don't trust an id nothing backs" — a live
  marker id only overwrites the sidecar when it resolves to a real transcript file
  (`SessionService.php:357`).
- **`#{window_activity}` not `#{session_activity}`** for freshness — `session_activity` was found live
  2026-08-08 to freeze at spawn time for 8+ hours (`TmuxService.php:53-67`). Every tracked session is
  single-window, so the active window IS the session.
- **`argv[0]` basename match** for `find_claude_processes()` — a PATH-resolved `claude` has
  `argv[0] == "claude"`, never the full `Config::claude_bin()`. Matching `argv[0]` specifically (not
  "anywhere in argv") avoids false-matching the tmux server's retained command line
  (`ProcessInspector.php:137-145`).
- **Dense docblock convention**: the codebase records live-found bugs and dated decisions inline
  ("found live 2026-08-08", "verified live ...", "decided 2026-08-24"). The findings above are drawn
  from those docblocks — they are the documented reasons behind otherwise non-obvious behavior, not
  over-engineering.

## 10. Files owned by this subsystem

Exact owned list (all four, all primarily owned here):

- `host-agent/lib/Services/SessionService.php`
- `host-agent/lib/Services/TmuxService.php`
- `host-agent/lib/Services/ProcessRunner.php`
- `host-agent/lib/Services/ProcessInspector.php`

**Co-owned / shared-in-practice flags:**

- **`TmuxService.php`** — owned here, but heavily consumed by `session-lifecycle`
  (`SessionLifecycleService`), `prompt-interaction` (`PromptInteractionService`), `BareProcessService`,
  `QuotaService`, the hooks, and standalone scripts. The `tmux_run()` primitive is effectively a
  shared dependency; only the `list_*`/`tmux_session_panes`/`tmux_capture_pane` parsing belongs
  squarely to session-core.
- **`ProcessRunner.php`** — a generic primitive shared across `session-lifecycle` (kill),
  `prompt-interaction` (via tmux), `BareProcessService`, `OpenCodeQuestionService`,
  `PushTimerService`, `PushHealthService`, and the standalone quota/diagnose scripts. Not
  session-specific at all; co-owned in practice.
- **`ProcessInspector.php`** — the tmux-adjacent counterpart used by `BareProcessService` and
  `SessionDetailService` in addition to `SessionService` itself; the `find_owning_pane()` half is more
  `BareProcessService`'s concern than session-core's.
