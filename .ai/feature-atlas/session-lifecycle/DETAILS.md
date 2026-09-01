---
id: session-lifecycle
name: Create / resume / kill / adopt sessions + new-session folder browse
owned_paths: [host-agent/lib/Services/SessionLifecycleService.php, host-agent/lib/Services/BareProcessService.php, src/lib/Controllers/BrowseController.php, src/partials/session-row/bare-processes.php, src/partials/session-row/bare-process-row.php, tests/test_opencode_spawn.php]
last_scanned_commit: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# Session Lifecycle

Manages the life of a session, host-side always (never from the Docker
container — see the repo's two-runtime protocol seam in CLAUDE.md):
spawn/resume/kill/cleanup of managed `cc-*`/`oc-*`/`ag-*` tmux sessions,
discovery & take-over of untracked ("bare") claude processes, and the
working-directory browser for the New Session form. Everything here runs
through `dispatch_action()` in `host-agent/lib/Sessions.php` or the two
`BrowseController` routes, and talks to tmux only via `TmuxService` (which
uses `proc_open()` with the command as an array — never a shell string).

Both services listed first were split out of `SessionService.php` on
2026-08-24 during a readability audit — methods/bodies moved verbatim, no
behavior changes (see their class docblocks).

## Ownership boundary

**In scope:**
- `host-agent/lib/Services/SessionLifecycleService.php`
- `host-agent/lib/Services/BareProcessService.php`
- `src/lib/Controllers/BrowseController.php`
- `src/partials/session-row/bare-processes.php`, `bare-process-row.php`
- `tests/test_opencode_spawn.php`

**Out of scope (named neighbors):**
- `session-core` (`SessionService`, `TmuxService`, `ProcessRunner`,
  `ProcessInspector`, `Config`) — upstream dependencies consumed here, and
  the owners of `browse_dir()`/`create_dir()`/`list_all_sessions()`.
- `agent-abstraction` (`AgentRegistry` + the `AgentAdapter` impls) —
  upstream; `create_cc_session()`'s spawn argv is delegated to the chosen
  adapter.
- `session-status-state` (`SidecarStore`, `SessionStatusStore`,
  `PendingToolStore`) — upstream: session metadata/state stores.
- Archived/transcript services (`ArchivedSessionService`,
  `TranscriptService`, `OpenCodeTranscriptService`,
  `StatuslineMarkerService`) — upstream reads for take-over matching.
- `dashboard` (`DashboardController`, `SessionRowView`) — the caller that
  renders the bare-process rows and dispatches create/kill/take-over.

## Key implementation files

| File | Responsibility |
|---|---|
| `host-agent/lib/Services/SessionLifecycleService.php` | Spawn/resume/kill/cleanup of managed tmux sessions. Owns the `--session-id`/`--resume` spawn shape, per-id resume flock, and the sidecar write. |
| `host-agent/lib/Services/BareProcessService.php` | Discovery-and-take-over of untracked (`bare`) claude processes: kill-a-pid, kill-whole-pane, candidate list from archived transcripts, confident marker match, and the confirm step. |
| `src/lib/Controllers/BrowseController.php` | Thin JSON endpoints for the New Session folder browser — forwards to the agent's `browse_dir`/`create_dir` actions. |
| `src/partials/session-row/bare-processes.php` | The "Other claude processes on host" section wrapper (`$rowsHtml`). |
| `src/partials/session-row/bare-process-row.php` | One bare-process row: pid, tmux-or-plain marker, cwd, start time, Take over + Kill forms. |
| `tests/test_opencode_spawn.php` | OpenCode-adaptor spawn-path test (create+kill against the isolated fake opencode). |

## Public interfaces & contracts

### SessionLifecycleService

**`generate_uuid_v4(): string`** — `SessionLifecycleService.php:27`
Random RFC 4122 §4.4 UUID, no params. Used as Claude Code's `--session-id`.
Pre/post-condition: caller never validates shape (the id is always
produced, never taken from input).

**`create_cc_session(string $workdir, bool $enableTaskTools = false, ?string $startingMode = null, ?string $agentId = null): array`** — `:62`
Returns `array{ok:bool, message:string}`.

- Rejects `$workdir` that is empty or not absolute (`$workdir[0] !== '/'`)
  → `['ok' => false, 'message' => 'Working directory must be an absolute path']` (`:64`).
- `$agentId` is whitelisted against `AgentRegistry::known_agent_ids()`;
  null/empty/unrecognized falls back to `AgentRegistry::default_agent_id()`
  (`'claude'`) (`:74`).
- Builds the tmux `new-session -d -s <prefix>-<Ymd-His>` wrapper
  (`-c $workdir`, `-e "CSM_SESSION_NAME=<name>"`, pane `-x`/`-y` from
  `Config::new_session_pane_width/height()`) and merges the adapter's
  `build_spawn_argv()` argv (`:77-93`). `CSM_SESSION_NAME` is the env var
  the SessionStart hook keys off to identify this pane's claude process.
- On non-zero tmux exit → fail (`:95`). After a `usleep(300000)` settle,
  re-checks the session actually stayed up via
  `list_all_tmux_sessions()` (NOT the sidecar-gated list — the sidecar
  isn't written yet) → fail if gone (`:105-114`).
- Writes the sidecar with `spawned_by_csm => true` eagerly (not deferred to
  the SessionStart hook) with `workdir`, `spawned_at`, the adapter's
  `assigned_id`, and `agent` (`:121`). Post-condition: a successful call
  owns a live tmux session + sidecar row.
- Conventions/quirks: session name is `date('Ymd-His')`-based (second
  resolution) — two calls within the same clock-second collide on an
  identical name, so callers/tests `sleep(1)` between creates (found live
  2026-08-23, `:68-76`).

**`claude_session_id_already_live(string $claudeSessionId, string $excludeSessionName = ''): bool`** — `:143`
Checks every sidecar-gated (`list_tracked_tmux_sessions()`) session's
sidecar for a matching `claude_session_id`, skipping `$excludeSessionName`.
Cheap (sidecar reads only, no pane-scraping). Guards
`resume_cc_session()` against two panes fighting over one transcript, and
the `session_start.php` hook against rebinding onto another pane's real id
(found live 2026-08-23, `:126-141`).

**`resume_cc_session(string $workdir, string $claudeSessionId): array`** — `:214`
Returns `array{ok:bool, message:string, name?:string}`.

- Pre-conditions: absolute `$workdir` (`:216`), non-empty
  `$claudeSessionId` (`:220`); sidecar dir is ensured (`:224`).
- Takes a per-id `flock()` on
  `sidecar_dir()/sha1($claudeSessionId).resume-lock` (`:228-232`); failure →
  `['ok' => false, 'message' => 'This session already has a live pane - refusing to open a second one on the same transcript']`. This closes a real
  TOCTOU window: the sidecar-only check (`claude_session_id_already_live()`)
  runs before spawn, but the sidecar isn't written until AFTER the 300ms
  settle, so two near-simultaneous resumes for one id could both pass the
  check (found live 2026-08-22, `:193-210`). Lock file is deliberately
  never unlinked and is always the same persistent path (documented flock
  footgun, `:168-176`); `LOCK_EX | LOCK_NB` so a second request fails
  immediately.
- Routes to the right agent: if `OpenCodeTranscriptService::is_opencode_id()`
  matches, uses `--session <id>` with `opencode`; otherwise `--resume <id>`
  with `claude` (`:239-246`). Session name is `date('Ymd-His')`-based
  (`:242`) — same collision caveat as create, but no lock for that.
- Same spawn wrapper, settle, and still-there re-check as create
  (`:248-269`), then writes the sidecar with `spawned_by_csm => true` and
  the resolved agent id (`:271`).
- `finally` releases the flock and closes the handle (`:274-277`).

**`kill_cc_session(string $requested): array`** — `:287`
Returns `array{ok:bool, message:string}`.

- Re-reads the live whitelist inside the same request via
  `TmuxService::list_tracked_tmux_sessions()` (`:289`); a name not on it →
  `['ok' => false, 'message' => 'Rejected: not a currently active managed session']` (`:292`).
  This is the "re-validate fresh, don't trust client input" pattern.
- Non-zero tmux exit → fail (`:297`). On success deletes the sidecar, the
  pending tool, and the session status (`:301-303`) via
  `SidecarStore::delete_sidecar()`, `PendingToolStore::delete_pending_tool()`,
  `SessionStatusStore::delete_status()`.

**`cleanup_inactive_sessions(): array`** — `:311`
Returns `array{ok:bool, killed:string[], failed:string[]}`.

- Iterates `list_tracked_tmux_sessions()`; any session whose
  `now - activity > Config::cleanup_threshold_seconds()` (default 12h,
  `Config.php:110`) is `kill-session`'d (`:317-330`). On success deletes
  the sidecar; failures accrue in `failed`. `ok` is `empty($failed)`.

### BareProcessService

**`kill_bare_process(int $pid): array`** — `BareProcessService.php:34`
Returns `array{ok:bool, message:string}`.

- Re-scans `ProcessInspector::find_claude_processes()` for the pid; absent →
  `['ok' => false, 'message' => 'Rejected: not a currently running claude process']` (`:45-47`)
  — a stale/reused pid can't be used to kill an unrelated process.
- If the pid has an owning tmux pane (`ProcessInspector::find_owning_pane()`
  over `TmuxService::all_tmux_panes()` + `build_ppid_map()`), the WHOLE
  session is killed (`kill-session -t`) for a clean pane shutdown (`:51-57`);
  otherwise SIGTERM is sent directly via `ProcessRunner::run_process(['kill','-TERM',...])`
  (`:59-63`).

**`bare_process_live_claude_session_id(int $pid): ?string`** — private, `:88`
Reads the owning pane's content via `TmuxService::tmux_capture_pane()` and
`StatuslineMarkerService::parse_marker_from_pane()` to get a certain
`session_id` (Claude Code's statusLine feed). Returns null when there is no
owning pane (a truly bare process in a plain terminal can never be matched
— a pty has no scrollback of its own, `:74-87`), or when the marker names a
session with no real transcript on disk (`:99-101`).

**`bare_process_take_over_candidates(string $workdir, int $processStartedAt, int $excludePid): array`** — private, `:130`
Returns `array{candidates: array<int, array{claude_session_id, cwd, title, last_activity}>, suggested_claude_session_id: ?string}`.

- Collects already-tracked ids from
  `SessionService::list_all_sessions()['sessions']` (`:134-138`), and also
  excludes ANY other live bare process in the same cwd whose real id can be
  marker-resolved — resume's already-live guard only reads sidecars, but a
  bare process has none (Andres's concern, 2026-08-08, `:140-160`).
- Filters `ArchivedSessionService::list_archived_sessions($trackedIds)` down
  to the same cwd (`:162-165`).
- Suggests the candidate whose transcript's first-message timestamp
  (`TranscriptService::find_first_timestamp()`) is closest to the process's
  `/proc` start time — a heuristic, not a guarantee (`:167-184`).

**`take_over_bare_process(int $pid): array`** — `:206`
Returns `array{ok:bool, message?:string, name?:string, needs_choice?:bool, pid?:int, workdir?:string, candidates?:array, suggested_claude_session_id?:?string}`.

- Finds the process's cwd + started_at via `find_claude_processes()`
  (`:211-217`); unresolvable → `['ok' => false, 'message' => 'Rejected: not a currently running claude process, or its working directory could not be determined']` (`:220`).
- Confident marker match → kills the pid and resumes that exact session in
  one call (`:223-233`); otherwise returns `needs_choice => true` with the
  candidate list, WITHOUT killing anything — fully cancelable until a human
  confirms (`:235-244`).

**`take_over_bare_process_with_id(int $pid, string $workdir, string $claudeSessionId): array`** — `:257`
The confirm step after `needs_choice`. Kills `$pid` only if it's still a
running claude process (`:259-269`) — it may have exited on its own, which
is fine; the resume below still makes sense. Then calls
`SessionLifecycleService::resume_cc_session()`.

### BrowseController

**`browse(): void`** — `BrowseController.php:27`
`GET /browse.php` (`routes.php:67`). GET-only, read-only, so deliberately
no CSRF/same-origin guard and no `AuthService::start_app_session()` call
(never had one; preserved as-is — `:9-19`). Emits `AgentClient::agent_call(['action' => 'browse_dir', 'path' => $_GET['path'] ?? ''])`.
The real boundary check lives in `SessionService::browse_dir()` (upstream).

**`mkdir(): void`** — `BrowseController.php:33`
`GET`+`POST /create_folder.php` (`routes.php:68-69`). Real mutation
(creates a directory on the host), so it uses the standard
`require_post_json()` guard (`:35`). Emits `['action' => 'create_dir', ...]`.

### Partials

**`bare-processes.php`** — rendered by `SessionRowView::bare_processes_html()`
(`SessionRowView.php:128`), which returns `''` when there are none. Just a
heading + `$rowsHtml` container.

**`bare-process-row.php`** — rendered by `SessionRowView::bare_process_row_html()`
(`SessionRowView.php:107`). Carries `data-pid` and `data-csrf-token` for the
take-over picker; shows pid, tmux-or-plain, cwd, start time; two forms:
`/take_over_bare.php` (Take over, with a `confirm()` JS prompt) and `/`
(the Kill action posts `action=kill_bare`). `Take over` button only renders
when `cwd` is known.

## Major call sites

**From the host-agent dipatcher** (`Sessions.php`, `dispatch_action()`):
- `create` → `SessionLifecycleService::create_cc_session(...)` (`Sessions.php:98-107`)
- `resume` → `resume_cc_session()` (`:109-110`)
- `kill` → `kill_cc_session()` (`:112-113`)
- `kill_bare` → `BareProcessService::kill_bare_process()` (`:115-116`)
- `take_over_bare` → `take_over_bare_process()` (`:118-119`)
- `take_over_bare_with_id` → `take_over_bare_process_with_id()` (`:121-126`)
- `cleanup` → `cleanup_inactive_sessions()` (`:156-157`)
- `browse_dir`/`create_dir` → `SessionService::browse_dir()/create_dir()`
  (`:159-163`) — these are upstream `session-core` deps, invoked via the
  BrowseController flow.

**From the web UI (container side), all via `AgentClient::agent_call()`:**
- `DashboardController::handleAction()` — POST-only form actions `new`,
  `resume`, `kill`, `kill_bare`, `cleanup` (`DashboardController.php:97-150`).
  A successful `resume` 303-redirects straight to `/session.php` with the
  new pane name (`:118-122`).
- `DashboardController::takeOverBare()` / `takeOverBareConfirm()` —
  AJAX POST JSON endpoints for the take-over picker
  (`DashboardController.php:297-325`, routes `:27-30`).
- `DashboardController::fragment()` — poll response that renderS
  `SessionRowView::bare_processes_html()` (`DashboardController.php:197-220`).
- `BrowseController::browse()`/`mkdir()` — the New Session folder browser.

**Cross-subsystem callers:** `BareProcessService` is invoked from its own
dispatch cases; the bare-process partials are rendered exclusively by
`SessionRowView` (dashboard). `SessionLifecycleService::resume_cc_session()`
is called from `BareProcessService::take_over_bare_process()` /
`take_over_bare_process_with_id()`. Nothing outside these callers invokes
the two services directly today.

### Browse flow specifics
- `browse()` passes the raw `path` through to the agent; the HOME_ROOT
  boundary check happens in `SessionService::browse_dir()` (upstream),
  which rejects anything outside `Config::home_root()` (`SessionService.php:471-484`).
- `mkdir()` passes `path` + `name` to the agent; `SessionService::create_dir()`
  re-validates the parent against home_root and restricts `name` to a bare
  basename (`SessionService.php:522-541`).

## Tests

**`tests/test_opencode_spawn.php`** (sole owned):
Happy path: `create_cc_session(..., agent: 'opencode')` → `oc-*` prefix
(`:63-72`); sidecar `agent=opencode` (`:76`); `claude_session_id` starts as
`null` (reactive binding, `:80`); list shows `agent_label=OpenCode` (`:86`);
pane start command carries `fake_opencode` (`:90`). Sad/edge path: unknown
agent falls back to `cc-*`/`claude` (`:94-104`); null agent falls back
(`:108-118`); kill unknown name rejected (`:140-141`); kill opencode session
removes sidecar + listing (`:132-137`). Guarded by `tests/.env.testing`
against the real tmux socket / real push.sqlite (`:27-43`).

**`tests/test_sessions_lifecycle.php`** — CO-OWNED (shared integration
harness; reported here only for its lifecycle-relevant coverage, NOT
claimed wholly). Lifecycle assertions include:
- `create`: ok + workdir/sidecar/pid/title key (`:560-573`); `--allowedTools`
  task-tools flag verified via `#{pane_start_command}` (`:593-601`);
  `--permission-mode acceptEdits` translation (`:634-642`); bogus mode
  silently ignored (`:654-662`); relative workdir rejected (`:850-851`).
- `resume`: relative workdir + empty id rejected (`:764-768`); happy path
  spawns a new pane with the SAME `claude_session_id` (`:774-789`);
  duplicate live-pane refused (`:794-795`); flock contention gives the same
  rejection message (`:827-829`); lock released → resumes again (`:836-837`).
- `kill`: unknown name rejected (`:750-751`); kill removes from listing
  (`:759`). NOTE: `:760` asserts the old JSON sidecar file is removed —
  stale/meaningless since `SidecarStore` is SQLite-only now (2026-08-24),
  but still passes trivially.
- `cleanup`: kills a session past the (test-shortened) threshold
  (`:931-945`).
- Bare: plain process appears in `bare[]` with cwd/no-tmux/no-title
  (`:956-966`); kill rejects unknown pid (`:968`); kill SIGTERMs plain
  process (`:970-980`); bare-name (PATH-resolved) process still found
  (`:1008-1014`); ad-hoc tmux-hosted bare process carries session name +
  pane title (`:1034-1043`); adopted non-cc-* sidecar survives orphan-pruning
  (`:1051-1053`); kill kills the whole session (`:1056-1060`).
- Take-over: rejects unknown pid (`:1069`); needs_choice path returns
  cwd-scoped candidates + suggested id, kills nothing (`:1087-1100`);
  `take_over_bare_process_with_id` resumes with the chosen id (`:1102-1123`);
  tolerates a stale pid that already exited (`:1140-1146`); marker-confident
  one-click path (`:1190-1201`); dual-bare excludes another live bare
  process's marker-matched session but keeps a genuinely dormant transcript
  (`:1258-1263`).
- Browse (upstream `SessionService`): `browse_dir` happy/sad paths
  (`:417-441`), `create_dir` happy/sad paths incl. traversal/slash/empty
  rejections (`:447-467`) — reported to note the harness spans these too,
  though the logic lives in `session-core`.

**Other touching files:** `tests/test_ui_smoke.php` exercises the
`/take_over_bare.php` and `/take_over_bare_confirm.php` routes (405 on GET,
403 on bad CSRF, canned JSON shapes) and the `browse.php` route; 
`tests/test_agent_client_protocol.php` covers `browse_dir`/`create_dir`
over the socket against the canned agent. Neither is owned here.

## Dependencies

**Upstream (consumed here):**
- `HostAgent\Agents\AgentRegistry` + `AgentAdapter` impls (`agent-abstraction`):
  `create_cc_session()` delegates spawn argv to the adapter's
  `build_spawn_argv()` and reads `session_name_prefix()`/`label()`/`id()`
  (`SessionLifecycleService.php:74-79`); `resume_cc_session()` chooses
  `opencode` vs `claude` by id shape (`:239-241`).
- `HostAgent\Services\SessionService` (`session-core`):
  `list_all_sessions()` (for tracked + bare exclusion in
  `bare_process_take_over_candidates`, `BareProcessService.php:134,150`),
  and `browse_dir()`/`create_dir()` (upstream of the BrowseController flow).
- `HostAgent\Services\TmuxService` (`session-core`): `tmux_run()`,
  `list_all_tmux_sessions()`, `list_tracked_tmux_sessions()`,
  `all_tmux_panes()`, `tmux_capture_pane()`.
- `HostAgent\Services\ProcessRunner` (`session-core`): `run_process()`
  (array-form `proc_open`).
- `HostAgent\Services\ProcessInspector`: `find_claude_processes()`,
  `find_owning_pane()`, `build_ppid_map()`, `process_start_time()`.
- `HostAgent\Services\Config`: `claude_bin()`, `opencode_bin()`,
  `sidecar_dir()`, `new_session_pane_width/height()`,
  `cleanup_threshold_seconds()`, `home_root()`, `www_root()`.
- `HostAgent\Services\OpenCodeTranscriptService::is_opencode_id()` — routes
  resumes to `opencode`.
- `HostAgent\Services\ArchivedSessionService::list_archived_sessions()` —
  take-over candidate pool.
- `HostAgent\Services\TranscriptService::find_transcript_path()` /
  `find_first_timestamp()` — candidate matching / transcript-validity.
- `HostAgent\Services\StatuslineMarkerService::parse_marker_from_pane()` —
  confident take-over matching.
- `HostAgent\Stores\SidecarStore`, `PendingToolStore`, `SessionStatusStore`.

**Downstream (what depends on this):**
- `host-agent/lib/Sessions.php` `dispatch_action()` routes all lifecycle
  actions here.
- `DashboardController` (`handleAction`, `takeOverBare`,
  `takeOverBareConfirm`, `fragment`) and `SessionRowView` (bare-process
  partials) consume the bare-process output.
- `BrowseController` consumes the `browse_dir`/`create_dir` agent actions.

**External/repo tooling:** none beyond PHP built-ins (`random_bytes`,
`proc_open`, `flock`, `glob` on `/proc`); no Composer packages used directly
by these services.

## Data & schema

**Sidecar rows** (`SidecarStore`, `sidecars` table of
`Config::sessions_sqlite_path()` — tmpfs, SQLite-only since 2026-08-24):
`session_name` (PK, the tmux name), `workdir` (nullable string),
`spawned_at` (nullable int), `claude_session_id` (nullable string — null
until reactively learned for adapters like opencode/antigravity),
`spawned_by_csm` (nullable bool — genuinely absent vs false matters, see
`SidecarStore::read_sidecar()`), `agent` (nullable string, defaults to
`'claude'` when omitted). Created by `create_cc_session()`/`resume_cc_session()`
with `spawned_by_csm => true`; deleted by `kill_cc_session()`/
`cleanup_inactive_sessions()`; pruned by
`SidecarStore::prune_orphaned_sidecars()` (called from
`SessionService::list_all_sessions()`, upstream).

**Bare-process detection** (`ProcessInspector`):
`find_claude_processes()` scans `/proc/[0-9]*/cmdline`, matching argv[0]'s
basename against `basename(Config::claude_bin())` (not the full path, and
not `/proc/<pid>/exe` — claude re-execs into a versioned binary, found live
2026-08-08). Each row: `{pid, cwd (from readlink /proc/<pid>/cwd),
started_at}`. `find_owning_pane()` walks the ppid map to find the tmux pane
a pid runs under. The `bare[]` set is anything `find_claude_processes()`
found that isn't inside a sidecar-gated (tracked) session
(`SessionService::list_all_sessions()`).

**Resume lock files:** `sidecar_dir()/sha1($claudeSessionId).resume-lock`
(`SessionLifecycleService::resume_lock_path()`) — per-id flock, persistent
path, never unlinked.

## Conventions & quirks

- **Spawn argv via adapter.** `create_cc_session()` no longer builds Claude
  Code's argv inline; it delegates to `AgentAdapter::build_spawn_argv()`.
  Claude Code receives `--session-id <uuid>`; OpenCode receives a positional
  project path (no pre-assignable id → `assigned_id: null`, reactive
  binding); Antigravity has no pre-assignable id either. The tmux wrapper
  (`-c $workdir`, `CSM_SESSION_NAME`, `-x`/`-y`) stays agent-agnostic here.
- **Second-resolution session names.** Names are
  `<prefix>-<date('Ymd-His')>`; two creates in one clock-second collide.
  Test code `sleep(1)`; real double-taps can hit this too (by design the
  collision surfaces as a tmux "duplicate session" failure).
- **`CSM_SESSION_NAME` only exists on app-spawned panes.** Set in the
  `new-session -e` env; the SessionStart hook (and the five Claude Code
  hooks) key off it. Bare/hand-started sessions don't have it.
- **Resume/adopt flow.** `resume_cc_session()` does a per-id flock +
  already-live sidecar check + spawn + settle + still-there re-check, then
  writes the sidecar. Take-over (`BareProcessService`) is kill + resume,
  either one-click (marker-confident) or two-step (candidate picker) —
  nothing destructive happens until either a real match is found or a human
  confirms.
- **proc_open array-form, never shell strings.** All tmux / kill /
  process invocations go through `ProcessRunner::run_process()` with the
  command as an array — no shell metacharacter injection surface.
- **Re-validate fresh each request.** `kill_cc_session()` and
  `kill_bare_process()` re-scan the live whitelist / live process list
  inside the same request rather than trusting client-supplied names/pids.
- **workdir browse safety.** The boundary is enforced upstream in
  `SessionService::browse_dir()`/`create_dir()` (home_root containment +
  basename-only `name`); the container-side `BrowseController()` just
  forwards — `browse()` is read-only/no-guard, `mkdir()` is a real mutation
  so it uses `require_post_json()`.
- **Dense docblock convention.** Both services carry extensive
  "found live <date>" comments explaining non-obvious behavior
  (e.g. `window_activity` vs `session_activity`, the flock footgun, the
  marker-only/must-have-real-transcript rule). Read them before assuming
  something is over-engineered.

## Co-owned / cross-subsystem

- **`tests/test_sessions_lifecycle.php`** — shared integration harness
  spanning this subsystem AND the `session-core` listing/archived/search/
  transcript features. Reported here only for its lifecycle-relevant
  assertions (create/resume/kill/cleanup/bare/take-over/browse). It also
  covers `SessionDetailService`, `ArchivedSessionService`,
  `PromptInteractionService`, `QuotaService`, `SessionStatusStore` wiring —
  all out of this subsystem's scope. Do not treat it as exclusively owned
  here; it is durable de-facto integration coverage for the whole
  dispatch_action() surface.
- **`SessionLifecycleService` / `BareProcessService` vs `session-core`** —
  both were split OUT of `SessionService.php` (2026-08-24) and depend
  upstream on `SessionService::list_all_sessions()`,
  `TmuxService`, `ProcessRunner`, `ProcessInspector`, `Config`, and — for
  `BareProcessService` — `ArchivedSessionService`, `TranscriptService`,
  `OpenCodeTranscriptService`, `StatuslineMarkerService`. One-directional
  (a legitimate orchestration role per the class docblocks); nothing it
  depends on calls back into it.
- **`create_cc_session()` vs `agent-abstraction`** — spawn argv, session
  name prefix, and agent label are delegated to `AgentRegistry`/`AgentAdapter`
  (`claude`/`antigravity`/`opencode`). The adapter is the owner of the
  per-agent CLI details; this subsystem is the owner of the tmux
  wrapper + sidecar write + lifecycle semantics.
- **`browse_dir`/`create_dir` agent actions** — invoked via the
  `BrowseController` flow in this subsystem but implemented in
  `SessionService` (`session-core`). This subsystem co-reports the
  container-side routing/guard behavior only; the home_root containment
  logic belongs to `session-core`.
