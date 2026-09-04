---
id: session-status-state
name: Hook-fed session status, pending-tool & sidecar state, hook installation
owned_paths:
  - host-agent/hooks/session_start.php
  - host-agent/hooks/pre_tool_use.php
  - host-agent/hooks/permission_request.php
  - host-agent/hooks/user_prompt_submit.php
  - host-agent/hooks/stop.php
  - host-agent/hooks/antigravity/pre_tool_use.php
  - host-agent/hooks/antigravity/post_tool_use.php
  - host-agent/hooks/antigravity/pre_invocation.php
  - host-agent/hooks/antigravity/stop.php
  - host-agent/lib/Services/HookService.php
  - host-agent/lib/Services/AntigravityHookService.php
  - host-agent/lib/Services/StatuslineMarkerService.php
  - host-agent/lib/Stores/SessionStatusStore.php
  - host-agent/lib/Stores/PendingToolStore.php
  - host-agent/lib/Stores/SidecarStore.php
  - host-agent/lib/Stores/SqliteDb.php
last_scanned_commit: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25T00:00:00+00:00
---

# Feature Atlas — `session-status-state`

## 1. Identity

- **id:** `session-status-state`
- **name:** Hook-fed session status, pending-tool & sidecar state, hook installation

This subsystem is the on-disk/SQLite truth for "what state is a session in right now,"
fed exclusively by Claude Code / Antigravity hook events, plus everything needed to
install those hooks (into `~/.claude/settings.json` / `~/.gemini/config/hooks.json`) and
the statusline session-id self-heal. Prompt *content* parsing (what a blocked prompt
means, tab sequences, etc.) is out of scope (the `prompt-interaction` subsystem), as are
transcripts (`session-view`) and quota (see the split-pending note).

## 2. Ownership boundary

**In scope (owned):**

- The 5 Claude Code hook scripts: `host-agent/hooks/{session_start,pre_tool_use,permission_request,user_prompt_submit,stop}.php`
- The 4 Antigravity hook scripts: `host-agent/hooks/antigravity/{pre_tool_use,post_tool_use,pre_invocation,stop}.php`
- `HookService` (Claude Code hook registration into `~/.claude/settings.json`)
- `AntigravityHookService` (Antigravity hook registration into `~/.gemini/config/hooks.json`)
- `StatuslineMarkerService` (**co-owned / split-pending with `quota`** — see below)
- `SessionStatusStore`, `PendingToolStore`, `SidecarStore`, `SqliteDb`

**Out of scope (explicitly named neighbors):**

- `quota` — quota models/persist/notify (`QuotaService`, `PushQuotaStateStore`, `quota_live_state_write.php`). `SqliteDb`'s `push_schema` tables and `GlobalStateStore` belong there. **But** the quota-capture block *currently lives in* `StatuslineMarkerService` — see the split-pending note.
- `prompt-interaction` — `PromptParser`, `PromptInteractionService`, `PermissionMode` (service classes), `AntigravityPromptParser`, `OpenCodePromptParser`/`OpenCodeQuestionService`. These *consume* the stores; they do not own them.
- `session-view` — `TranscriptService`, `TranscriptRouter`, `SessionDetailService`, `ArchivedSessionService`.
- `Config` (cross-cutting shared dependency, not owned).
- `SessionService`/`SessionLifecycleService`/`TmuxService`/`ProcessInspector` (host-agent services that read the stores but are themselves other subsystems).

**Cross-subsystem conflict recorded (not resolved in code):** `StatuslineMarkerService` is
**split-pending**. Its session-id self-heal (parse/locate/install the `csm-data:` marker)
genuinely belongs to this subsystem. But it also installs a **quota state capture** block
into the statusline script (`QUOTA_CAPTURE_BEGIN`/`QUOTA_CAPTURE_END`,
`quota_capture_block()` at `host-agent/lib/Services/StatuslineMarkerService.php:474`), which
shells out to `Config::quota_live_state_write_command()` and writes quota state that the
`quota` subsystem consumes. **Decision (Andres):** the *target* boundary is that the
quota-capture block moves to `QuotaService` / `quota` subsystem. Until then this file is
documented as **co-owned / split-pending** between `session-status-state` (session-id
marker) and `quota` (quota-capture block). This split is a pending improvement, not a
current bug. `SqliteDb` is the **shared storage dependency** for ALL Stores (cross-cutting,
not owned by this subsystem's semantics).

## 3. Key implementation files

| File | Responsibility |
|------|----------------|
| `host-agent/hooks/session_start.php` | SessionStart hook — rebinds the sidecar's `claude_session_id` to a new UUID on `/clear`/`/compact`/`--resume`/`--fork-session`; adopts hand-started tmux sessions by keying a new sidecar off the pane's own `#S`. |
| `host-agent/hooks/pre_tool_use.php` | PreToolUse hook — writes the full untruncated `tool_name`/`tool_input` to `PendingToolStore`; clears stale `blocked` state; special-cases `AskUserQuestion` to write `blocked` directly. Pure-observe (no stdout, exit 0). |
| `host-agent/hooks/permission_request.php` | PermissionRequest hook — records `blocked` + normalized `mode` to `SessionStatusStore` when Claude Code actually needs a human decision. Pure-observe. |
| `host-agent/hooks/user_prompt_submit.php` | UserPromptSubmit hook — marks session `working`, clears `blocked`, records normalized `mode`. Pure-observe. |
| `host-agent/hooks/stop.php` | Stop hook — marks session `idle`, clears `blocked`, records `last_message` + normalized `mode`. Pure-observe. |
| `host-agent/hooks/antigravity/pre_tool_use.php` | Antigravity PreToolUse — writes pending tool, clears blocked to `working`; always returns `{"decision":"ask"}` (required field, see docblock for why not "allow"). |
| `host-agent/hooks/antigravity/post_tool_use.php` | Antigravity PostToolUse — deletes the pending-tool row; returns `{}`. |
| `host-agent/hooks/antigravity/pre_invocation.php` | Antigravity PreInvocation — marks `working`, clears `blocked`/`last_turn_error`; reactive session-id binding to the real `conversationId` on first firing. Returns `{}`. |
| `host-agent/hooks/antigravity/stop.php` | Antigravity Stop — marks `idle`, clears `blocked`/`last_turn_error`, derives `last_message` (tail-scan of transcript) and `last_turn_error` (tail-scan + live-pane re-capture). Returns `{"decision":"allow_stop"}`. |
| `host-agent/lib/Services/HookService.php` | Claude Code hook check/install into `~/.claude/settings.json`, data-driven off `app_hooks_status()`. |
| `host-agent/lib/Services/AntigravityHookService.php` | Antigravity hook check/install into `~/.gemini/config/hooks.json`, data-driven off `HOOK_GROUP` + `hook_defs()`. |
| `host-agent/lib/Services/StatuslineMarkerService.php` | **Split-pending.** Parse/locate/install the statusline session-id marker (`csm-data:{...}`); also installs the quota-capture block (quota subsystem target). |
| `host-agent/lib/Stores/SessionStatusStore.php` | One row per session in `session_status` (SQLite) — live mode/working-status/blocked-prompt. Atomic `update_status()` merge. |
| `host-agent/lib/Stores/PendingToolStore.php` | One row per session in `pending_tools` (SQLite) — latest PreToolUse payload. |
| `host-agent/lib/Stores/SidecarStore.php` | One row per session in `sidecars` (SQLite) — workdir/spawned_at/claude_session_id/spawned_by_csm/agent. Includes orphan pruning + id→name reverse lookup. |
| `host-agent/lib/Stores/SqliteDb.php` | Shared PDO/SQLite connection helper (`connect`, schema, `add_column_if_missing`, test connection reset). Cross-cutting. |

## 4. Public interfaces & contracts

### Entry points — the hook scripts

All Claude Code hook scripts and all Antigravity hook scripts are standalone PHP invoked
via `proc_open(['php', <script>])` by the respective CLI. Each reads one JSON payload from
**stdin** and returns a JSON/empty **stdout**. The five Claude Code hooks + also each
Antigravity script share the same **`CSM_SESSION_NAME` gate**: if the env var is unset/empty
the script is a deliberate no-op (for Claude Code, exit 0 with no writes; for Antigravity,
still return the required decision/handler shape so a globally-registered hook never breaks
an untracked session).

| Script | Entry contract | Returns |
|--------|----------------|---------|
| `session_start.php` | stdin: `{session_id, cwd}`; gate = `CSM_SESSION_NAME` (rebind path) OR `TMUX` env + `tmux display-message -p '#S'` (adopted path). Requires a real transcript for the id (4 tries × 150ms) and not already live on another session. | exit 0; writes/rebinds sidecar. |
| `pre_tool_use.php` | stdin: `{tool_name, tool_input}`; gate = `CSM_SESSION_NAME`. Requires both `tool_name` + `tool_input` arrays. | **empty stdout**, exit 0. Writes `PendingToolStore`, then `SessionStatusStore`. |
| `permission_request.php` | stdin: `{tool_name, tool_input, permission_suggestions, permission_mode}`; gate = `CSM_SESSION_NAME`. | **empty stdout**, exit 0. Writes `SessionStatusStore` (blocked + mode). |
| `user_prompt_submit.php` | stdin: `{permission_mode}`; gate = `CSM_SESSION_NAME`. | **empty stdout**, exit 0. Writes `working` + clears blocked + mode. |
| `stop.php` | stdin: `{last_assistant_message, permission_mode}`; gate = `CSM_SESSION_NAME`. | **empty stdout**, exit 0. Writes `idle` + clears blocked + `last_message` + mode. |
| `antigravity/pre_tool_use.php` | stdin: `{toolCall:{name,args}}`; gate = `CSM_SESSION_NAME`. | **always** `{"decision":"ask"}`. |
| `antigravity/post_tool_use.php` | stdin: arbitrary; gate = `CSM_SESSION_NAME`. | `{}`. |
| `antigravity/pre_invocation.php` | stdin: `{conversationId, workspacePaths}`; gate = `CSM_SESSION_NAME`. | `{}`. |
| `antigravity/stop.php` | stdin: `{transcriptPath, ...}`; gate = `CSM_SESSION_NAME`. | **always** `{"decision":"allow_stop"}`. |

**PreToolUse / PermissionRequest / UserPromptSubmit / Stop (Claude Code) contract** — pure
observe: **write nothing to stdout and always exit 0**. Claude Code interprets empty stdout
as "no opinion", leaving its own permission decision untouched. `pre_tool_use.php` additionally
clears `SessionStatusStore`'s blocked state on every firing, and for `AskUserQuestion` writes
`blocked` directly (AskUserQuestion never fires PermissionRequest — it is a distinct mechanism).

### `HookService` (Claude Code registration)

- `reindent_json_pretty(string $json): string` — `HookService.php:22`. Halves PHP's 4-space `JSON_PRETTY_PRINT` to 2-space to match Claude Code's own indentation.
- `hook_command_present(array $settings, string $event, string $command): bool` — `HookService.php:44`. True only if a hook under `$event` runs the *exact* command string (never mistakes a user's own unrelated hook for ours).
- `session_start_hook_present(array $settings): bool` — `HookService.php:68`; `pre_tool_use_hook_present()` `:76`; `permission_request_hook_present()` `:84`; `user_prompt_submit_hook_present()` `:92`; `stop_hook_present()` `:100`.
- `app_hooks_status(array $settings): array` — `HookService.php:123`. Returns `array<int,{event,command,present}>` for all 5 hooks; the single data-driven list install/check runs off.
- `check_session_hook(): array{ok,bool installed,message?}` — `HookService.php:144`. Missing file ⇒ `{ok:true, installed:false}` (normal, not error); unparseable file ⇒ `{ok:false, message:...}`; else `installed` = all 5 present.
- `install_session_hook(): array{ok,installed,message?}` — `HookService.php:182`. Creates `~/.claude/settings.json` if absent; adds only missing hooks (idempotent); never overwrites a malformed file; re-indents to 2-space; coexists with unrelated pre-existing hooks per event.

### `AntigravityHookService` (`~/.gemini/config/hooks.json`)

- `hook_defs(): array` — `AntigravityHookService.php:42`. `{event, command, grouped}` for PreToolUse/PostToolUse (grouped, `matcher` + `hooks` wrapper) and PreInvocation/Stop (flat `{type,command}`).
- `hook_command_present(array $config, string $event, string $command, bool $grouped): bool` — `AntigravityHookService.php:55`.
- `app_hooks_status(array $config): array` — `AntigravityHookService.php:90`.
- `check_session_hook(): array{ok,installed,message?}` — `AntigravityHookService.php:104`. Same missing-file/malformed-file discipline as `HookService`.
- `install_session_hook(): array{ok,installed,message?}` — `AntigravityHookService.php:139`. Writes under `HOOK_GROUP` (`claude-session-manager`, const at `:29`); idempotent; never touches another top-level group or a malformed file.

### `StatuslineMarkerService`

- `parse_marker_from_pane(string $paneContent): array{session_id,context_used_percentage,git_worktree}` — `StatuslineMarkerService.php:86`. Matches last `csm-data:{...}` blob; `session_id` validated UUID-normalized lowercase; all fields optional.
- `locate_statusline_script(array $settings): ?string` — `StatuslineMarkerService.php:126`. Returns the script path from a `{"type":"command","command":"<interp> <path>"}` statusLine (last path-like token); null for inline/unrecognized.
- `marker_installed(array $settings): bool` — `StatuslineMarkerService.php:155`.
- `quota_capture_installed(array $settings): bool` — `StatuslineMarkerService.php:176`. Requires markers present AND body up-to-date.
- `check_statusline_marker(): array{ok,installed,message?}` — `StatuslineMarkerService.php:252`.
- `install_statusline_marker(): array{ok,installed,message?}` — `StatuslineMarkerService.php:277`. Idempotent; appends into an existing script (preserving its own output), upgrades a marker-only script with the quota block, replaces a *stale* quota body, or installs the fallback script `~/.claude/csm-statusline.sh` (`install_fallback_script()` `:491`).
- Private helpers: `install_into_script()` `:322`, `append_marker_to_script()` `:381`, `append_quota_capture_block()` `:428`, `replace_quota_capture_block()` `:355`, `quota_capture_block()` `:474` (the split-pending quota block).

### `SessionStatusStore` (`session_status` table)

- `read_status(string $sessionName): ?array` — `SessionStatusStore.php:53`. Returns `{status, blocked, mode, last_message, last_turn_error, updated_at}` or null if no row.
- `update_status(string $sessionName, array $fields): void` — `SessionStatusStore.php:92`. **Atomic merge**: `INSERT OR IGNORE` a blank row, then a single `UPDATE` that only touches columns present in `$fields` (via `array_key_exists`, not `isset`, so a deliberate null like Stop clearing `blocked` still takes effect), stamps fresh `updated_at`. Never called with a `mode` key when `permission_mode` didn't map (omitting the key preserves the prior known mode).
- `write_status(string $sessionName, array $data): void` — `SessionStatusStore.php:123`. Full overwrite via `INSERT ... ON CONFLICT DO UPDATE` (used by tests/cleanup, not the hooks).
- `delete_status(string $sessionName): void` — `SessionStatusStore.php:146`.

### `PendingToolStore` (`pending_tools` table)

- `read_pending_tool(string $sessionName): ?array` — `PendingToolStore.php:30`. `{tool_name, tool_input, written_at}` or null.
- `write_pending_tool(string $sessionName, array $data): void` — `PendingToolStore.php:47`. Upsert (always overwritten by latest tool call, never appended).
- `delete_pending_tool(string $sessionName): void` — `PendingToolStore.php:66`.

### `SidecarStore` (`sidecars` table)

- `read_sidecar(string $sessionName): ?array` — `SidecarStore.php:40`. `{workdir, spawned_at, claude_session_id, spawned_by_csm, agent}`. Note `spawned_by_csm` is *genuinely null* (not false) when never written, so callers' `?? default` falls through (live fix 2026-08-24). `agent` null reads back as null for pre-2026-08-24 rows; callers treat null as `claude`.
- `write_sidecar(string $sessionName, array $data): void` — `SidecarStore.php:85`. Upsert; `agent` defaults to `claude` when key omitted; `spawned_by_csm` written as NULL (not 0) when genuinely absent.
- `delete_sidecar(string $sessionName): void` — `SidecarStore.php:111`.
- `prune_orphaned_sidecars(array $liveSessionNames): void` — `SidecarStore.php:123`. Deletes `sidecars`/`session_status`/`pending_tools` rows for any session not in the live list (runs on every listing).
- `find_by_claude_session_id(string $claudeSessionId): ?string` — `SidecarStore.php:147`. Reverse join `claude_session_id` (ses_* id) → tmux session name; null for '' or no binding.

### `SqliteDb` (cross-cutting)

- `connect(string $path, string $schemaSql): \PDO` — `SqliteDb.php:55`. Caches per absolute path for the process; WAL mode + `busy_timeout=5000` (comment `:43-53` explains the one-process-per-connection concurrency rationale). Creates parent dir 0700.
- `add_column_if_missing(\PDO $pdo, string $table, string $column, string $definition): void` — `SqliteDb.php:92`. One-off transitional migration; `ADD COLUMN` fails harmlessly once present.
- `reset_connections_for_tests(): void` — `SqliteDb.php:110`. Test-only.
- `sessions_schema(): string` — `SqliteDb.php:115`. `sidecars`/`session_status`/`pending_tools`.
- `push_schema(): string` — `SqliteDb.php:144`. `push_subscriptions`/`push_session_state`/`push_quota_state`/`global_state` (belongs to `quota`/push subsystems).

## 5. Major call sites

**Downstream (other subsystems consuming the stores/services):**

- `SessionService::build_session_entry()` — `host-agent/lib/Services/SessionService.php:137` reads `SessionStatusStore::read_status`; `:219` `PendingToolStore::read_pending_tool`; `:252` `StatuslineMarkerService::parse_marker_from_pane`; `:253` `self_heal_claude_session_id`. This is the authoritative "hooks fully own status, no pane-scraping fallback" contract (per the 2026-08-22 decision): a session with no status row reports unknown/idle/no-prompt.
- `SessionService::self_heal_claude_session_id()` — `SessionService.php:347` writes `SidecarStore::write_sidecar`; only trusts a live marker id that resolves to a real transcript.
- `SessionService::list_all_sessions()` — `SessionService.php:422` calls `SidecarStore::prune_orphaned_sidecars`.
- `PromptInteractionService` (answer flow) — `PromptInteractionService.php:63,66,104` read the sidecar; `:123-124,155-156,203,206-209,288-289,374-375` clear `PendingToolStore`/`SessionStatusStore` blocked→working on answer; `:333` read status; `:447` write mode; `:490,588` read status for guards.
- `SessionLifecycleService::create_cc_session()`/resume — `SessionLifecycleService.php:121,271` write the sidecar (with explicit `agent`); kill path `:301-303` deletes sidecar/status/pending; `:325` deletes stale sidecars; `claude_session_id_already_live()` `:143` guards the hooks.
- `TmuxService::list_tracked_tmux_sessions()` — `TmuxService.php:120` filters to sessions with a sidecar.
- `PushHealthService::health_check()` — `PushHealthService.php:194` iterates `HookService::app_hooks_status` to surface each hook as a mandatory health box check.
- `PermissionStore` — `PermissionStore.php:90` uses `SidecarStore::find_by_claude_session_id`.
- `BareProcessService` — `BareProcessService.php:97` uses `StatuslineMarkerService::parse_marker_from_pane`.
- `QuotaService` — `QuotaService.php:52` uses `StatuslineMarkerService::parse_marker_from_pane` for `context_used_percentage`; `:257` reads the sidecar. **This is the quota side consuming the statusline marker's live signals; the quota-capture block is the split-pending target to move here.**
- `ArchivedSessionService:206`, `PlanFileService:36,119,162`, `UploadService:54`, `SessionDetailService:84,91,206,212` all read/write the sidecar.

**Upstream (the hooks themselves calling these):** every hook script requires `host-agent/lib/Sessions.php` and calls `SessionStatusStore`/`PendingToolStore`/`SidecarStore` directly (e.g. `pre_tool_use.php:83`, `permission_request.php:77`, `session_start.php:139`, `antigravity/pre_invocation.php:75,86`).

**Web UI surface:** `DashboardController.php:33` (`check_session_hook` agent_call), `:40` (`health_check`), `:153` (`install_session_hook`); `HealthBoxView.php:69` renders the health box (hook install state). Session hooks flow through `host-agent/agent.php:38` → `Lib/Sessions::dispatch_action` → `HookService::check/install` (`host-agent/lib/Sessions.php:179-183`).

## 6. Tests

- `tests/test_session_hook.php` — covers `HookService::check/install_session_hook` (fresh machine, idempotency, partial top-up, merge-safety with pre-existing hooks, 2-space reindent, malformed-file refusal) and the 5 Claude Code hook scripts (`session_start`, `pre_tool_use`, `permission_request`, `user_prompt_submit`, `stop`) — each with CSM_SESSION_NAME gate, happy path, and sad path (empty/malformed stdin, missing fields, phantom id, already-live id, no-tmux, no-pane). Also covers `PendingToolStore`/`SessionStatusStore`/`SidecarStore::prune_orphaned_sidecars` round-trips. Shape: happy + sad.
- `tests/test_antigravity_hooks.php` — covers `AntigravityHookService::check/install_session_hook` (schema shape, idempotency, unrelated-group preservation, malformed refusal) and the 4 Antigravity hook scripts. Shape: happy + sad.
- `tests/test_statusline_marker.php` — covers `StatuslineMarkerService` (parse happy/sad, locate, install into existing script, upgrade marker-only → +quota, replace stale quota body, fallback script, malformed/unrecognized refusal) and `SessionService::self_heal_claude_session_id` (happy + phantom/no-sidecar/no-marker/already-matching sad paths). Also asserts quota capture writes to `GlobalStateStore` (the split-pending consumption).

**Test isolation rules** (from repo-root `CLAUDE.md`): each test overrides `HOME_ROOT`/`SIDECAR_DIR` (and `PUSH_SQLITE_FILE` for the statusline one) via `putenv()` to temp fixture dirs and refuses to run if `Config::home_root()` still resolves to the real host home directory. `session_start`/adoption tests use a fake `tmux` binary on a fixture `PATH` so the real tmux server is never touched. `tests/run.sh` cleans up its isolated tmux server + fixture processes on exit/failure/interrupt. `SqliteDb::reset_connections_for_tests()` (`SqliteDb.php:110`) drops cached PDO handles between tests.

## 7. Dependencies

**Upstream (this subsystem depends on):**

- `HostAgent\Services\Config` (cross-cutting, not owned) — the single provider of every path/command string: `sessions_sqlite_path()` `Config.php:201` (tmpfs, `SESSIONS_SQLITE_FILE` env / `<sidecar_dir>/sessions.sqlite`), `push_sqlite_path()` `:219`, `claude_settings_path()` `:263`, the five `*_hook_command()` `:274-317`, `antigravity_hooks_path()` `:327`, the four `antigravity_*_hook_command()` `:337-355`, `quota_live_state_write_command()` `:364`, `statusline_fallback_script_path()` `:376`.
- `HostAgent\Stores\SqliteDb` — `connect`/`add_column_if_missing`/`sessions_schema` used by all three stores (`SidecarStore.php:29`, `SessionStatusStore.php:42`, `PendingToolStore.php:22`).
- `HostAgent\Services\SessionLifecycleService::claude_session_id_already_live()` (`SessionLifecycleService.php:143`) — used by `session_start.php:128` and `antigravity/pre_invocation.php:71`.
- `HostAgent\Services\TmuxService` (`tmux_run`, `tmux_capture_pane`) — used by `session_start.php:69` and `antigravity/stop.php:189`.
- `HostAgent\Services\TranscriptService::find_transcript_path()` — `session_start.php:107`, `SessionService::self_heal` `:357`.
- `HostAgent\Services\PermissionMode::normalize_hook_permission_mode()` — `permission_request.php:71`, `user_prompt_submit.php:45`, `stop.php:52` (service is in the `prompt-interaction` subsystem, not owned).
- `HostAgent\Services\PromptInteractionService::TMUX_KEY_STEP_DELAY_USEC` — `antigravity/stop.php:186` (const cross-reference).
- `HostAgent\Stores\GlobalStateStore` — only from the test file (`test_statusline_marker.php`) for asserting the split-pending quota writes.

**Downstream (depends on this subsystem):** see §5 — chiefly `SessionService`, `SessionLifecycleService`, `PromptInteractionService`, `PushHealthService`, `TmuxService`, `BareProcessService`, `QuotaService` (statusline marker live signals), plus the `DashboardController`/`HealthBoxView` for install status.

**External packages/SDKs:** none beyond the PHP stdlib + PDO SQLite (`\PDO('sqlite:...')`, `SqliteDb.php:67`). The statusline install shells out to `jq` (the user's own statusline script) and to `php` (the `quota_live_state_write.php` writer).

## 8. Data & schema

### Two SQLite DBs (from `Config`)

- **`sessions_sqlite_path()`** (`Config.php:201`; tmpfs, `<sidecar_dir>/sessions.sqlite`, wiped on reboot) — schema `SqliteDb::sessions_schema()` (`SqliteDb.php:115`):
  - `sidecars(session_name TEXT PRIMARY KEY, workdir TEXT, spawned_at INTEGER, claude_session_id TEXT, spawned_by_csm INTEGER, agent TEXT)` — `agent` added 2026-08-24 via `add_column_if_missing` (`SidecarStore.php:32`).
  - `session_status(session_name TEXT PRIMARY KEY, status TEXT, blocked_json TEXT, mode TEXT, last_message TEXT, last_turn_error TEXT, updated_at INTEGER)` — `last_turn_error` added via `add_column_if_missing` (`SessionStatusStore.php:45`).
  - `pending_tools(session_name TEXT PRIMARY KEY, tool_name TEXT, tool_input_json TEXT, written_at INTEGER)`.
- **`push_sqlite_path()`** (`Config.php:219`; persistent, `host-agent/state/push.sqlite`) — schema `SqliteDb::push_schema()` (`SqliteDb.php:144`): `push_subscriptions`, `push_session_state`, `push_quota_state`, `global_state`. These belong to the push/quota subsystems; this subsystem's owned stores never touch them (only `test_statusline_marker.php` reads `global_state` to assert the split-pending quota write).

### Row shapes

- **Status** (`read_status`, `SessionStatusStore.php:53`): `{status:?string, blocked:?array, mode:?string, last_message:?string, last_turn_error:?string, updated_at:?int}`. `blocked` decodes from `blocked_json` and is `{tool_name:string, tool_input:array, permission_suggestions:array}` (or `[questions]` for AskUserQuestion). `status` ∈ `'working'|'idle'|'blocked'`; `mode` ∈ `'manual'|'accept edits'|'plan'|'auto'` (normalized).
- **Pending tool** (`read_pending_tool`, `PendingToolStore.php:30`): `{tool_name:?string, tool_input:?array, written_at:?int}`.
- **Sidecar** (`read_sidecar`, `SidecarStore.php:40`): `{workdir:?string, spawned_at:?int, claude_session_id?:?string, spawned_by_csm?:bool, agent?:?string}`. `agent` defaults to `claude` on write when omitted; read null → treated as `claude`.
- **Statusline marker blob** (from `parse_marker_from_pane`, `StatuslineMarkerService.php:86`): `{session_id:?string, context_used_percentage:?float, git_worktree:?string}`. Rendered as `csm-data:<json>`; jq filter `JQ_FILTER` (`:53`) drops null keys (`session_id`, `ctx_pct`, `git_worktree`).

### Atomicity / concurrency invariant (the 2026-08-24 JSON→SQLite migration)

`SessionStatusStore::update_status()` (`SessionStatusStore.php:92`) is the documented reason for the JSON→SQLite migration: the old read-json-merge-write-json version had a real read-modify-write race (found live 2026-08-23 — PreToolUse and PermissionRequest can fire close enough together that both read the same "current" content and each write their own merged version, silently losing whichever wrote first). The fix keeps a per-column `UPDATE` (SQLite serializes writers to the same row) using `INSERT OR IGNORE` to guarantee a row to coalesce against, then `array_key_exists`-gated column SETs with a fresh `updated_at`. **No separate PHP-side read; no full-file rewrite; structurally immune to that race class.** `SidecarStore`'s own docblock (`SidecarStore.php:18-25`) records that the migration's lazy legacy-JSON importer was removed once every live session had cut over — these stores are SQLite-only, no file fallback.

### Enums / constants

- `PermissionMode` vocabulary (service in `prompt-interaction`): `default→manual`, `acceptEdits→accept edits`, `plan`, `auto` pass through; unrecognized → null (key omitted from `update_status`).
- `StatuslineMarkerService` marker constants (`StatuslineMarkerService.php:34-39`): `CAPTURE_BEGIN`/`CAPTURE_END`, `MARKER_BEGIN`/`MARKER_END`, `QUOTA_CAPTURE_BEGIN`/`QUOTA_CAPTURE_END`; `JQ_FILTER` `:53`.
- `AntigravityHookService::HOOK_GROUP = 'claude-session-manager'` (`AntigravityHookService.php:29`).

## 9. Conventions / quirks

- **Hook sequence (Claude Code):** `UserPromptSubmit → working`; `PermissionRequest → blocked`; `Stop → idle`; `PreToolUse → clears blocked` (and for `AskUserQuestion`, writes `blocked` directly since no PermissionRequest ever fires for it). These three hook-fed transitions are the **only** source `build_session_entry()` uses for working/idle/blocked — no pane-scraping fallback (the `prompt-interaction` spinner-glyph detection was removed 2026-08-22). Session with no status row → unknown/idle/no-prompt.
- **Pure-observe hook contract (Claude Code):** `pre_tool_use.php`, `permission_request.php`, `user_prompt_submit.php`, `stop.php` write **nothing to stdout** and **always exit 0**. `pre_tool_use.php` never approves/denies anything. The Antigravity hooks differ: they **must** return a `decision`/handler JSON (required field), always `{"decision":"ask"}` for `pre_tool_use` (not `"allow"` — repeatedly confirmed live that "allow" does not suppress Antigravity's own approval UI, and "ask" is the safe no-op default for a globally-registered hook) and `{"decision":"allow_stop"}` for `stop` (any non-`continue` value lets the agent stop).
- **CSM_SESSION_NAME gate:** all five Claude Code hooks and all four Antigravity hooks no-op for a plain `claude`/`agy` session started by hand (no `CSM_SESSION_NAME`, set via `tmux new-session -e` in `create_cc_session()`). The Antigravity hooks are registered **globally** (`~/.gemini/config/hooks.json`) so they still return a valid decision even when untracked; Claude Code hooks are per-project (`~/.claude/settings.json`).
- **session_start.php two paths:** `CSM_SESSION_NAME` → *rebind only* (never create; `create_cc_session()` already wrote the sidecar); adopted (a real tmux pane, no CSM_SESSION_NAME) → *create* a new sidecar keyed off `tmux display-message -p '#S'`, first-seen. Requires a real transcript for the reported id (bounded 4×150ms retries) and refuses any id already live on a DIFFERENT tracked session — both guard against the live 2026-08-08 nested-`claude`-clobber and 2026-08-23 duplicate-binding bugs. A bare no-pane claude is left alone entirely (a sidecar buys it nothing; it shows in the archived listing via directory scan).
- **Antigravity pre_invocation.php is the reactive binder** (no `--session-id` equivalent for a fresh interactive Antigravity session): the first post-spawn firing learns the real `conversationId` and binds it; later same-id firings are a cheap read-and-skip. It also clears `last_turn_error` on every new turn.
- **last_turn_error** (Antigravity only): Stop derives it by tail-scanning the transcript for the most recent `USER_INPUT`'s `step_index` and checking whether any higher `step_index` responded; if not, it re-captures the live pane (up to 3 tries, 300ms apart) for a `⚠ ...` banner. Only needed because a quota-exhausted turn writes **nothing** to the transcript (confirmed live 2026-08-24). Cleared on next `pre_invocation`.
- **Hook registration merge-safety:** both `HookService` and `AntigravityHookService` never overwrite a malformed target file and never replace an unrelated pre-existing hook — they append a second matching-group entry (Claude Code) or a distinct named group (`AntigravityHookService::HOOK_GROUP`) rather than clobbering. Idempotent per-hook. `HookService::install_session_hook()` re-indents to 2-space to match Claude Code's own formatting.
- **statusline marker merge-safety:** `StatuslineMarkerService` appends the marker block into Andres's own existing statusline script (via `CAPTURE_BEGIN` stdin-replay so the original script's own `cat` still works), only creating the fallback `~/.claude/csm-statusline.sh` when no statusLine exists at all. Idempotent; a *stale* quota-capture body is replaced in place (not double-appended) — the `quota_capture_up_to_date()` check distinguishes "present" from "present-and-current".

## 10. Files owned (with co-owned / split-pending flags)

All files listed in §Key implementation files are owned. Flags:

- **`host-agent/lib/Services/StatuslineMarkerService.php` — CO-OWNED / SPLIT-PENDING**: the session-id marker (parse/locate/install) belongs to `session-status-state`; the `quota_capture_block()`/`QUOTA_CAPTURE_*` install logic (lines 38-39, 176-194, 338-339, 355-376, 428-447, 449-485, 501) writes quota state consumed by the `quota` subsystem. **Target boundary (Andres's decision):** the quota-capture block moves to `QuotaService`/`quota`; `quota` owns quota-capture. Recorded here as a pending improvement — not to be resolved in code by this atlas.
- **`host-agent/lib/Stores/SqliteDb.php` — CROSS-CUTTING shared storage dependency** for ALL Stores (both `sessions_schema` in this subsystem and `push_schema` in push/quota). It is not the semantic property of any single feature; treat it as shared infrastructure.
- All other files: owned by `session-status-state` exclusively.
