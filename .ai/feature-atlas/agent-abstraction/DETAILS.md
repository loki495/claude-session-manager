---
id: agent-abstraction
name: Agent adapter seam (Claude Code / Antigravity / OpenCode)
owned_paths:
  - host-agent/lib/Agents/AgentAdapter.php
  - host-agent/lib/Agents/AgentRegistry.php
  - host-agent/lib/Agents/ClaudeCodeAdapter.php
  - host-agent/lib/Agents/AntigravityAdapter.php
  - host-agent/lib/Agents/OpenCodeAdapter.php
  - tests/test_agent_adapter.php
last_scanned_commit: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# Agent adapter seam (Claude Code / Antigravity / OpenCode)

## Identity

- **id:** `agent-abstraction`
- **name:** Agent adapter seam (Claude Code / Antigravity / OpenCode)

## Ownership boundary

**In scope** — the thin seam that knows which coding-CLI agents exist and
what genuinely differs at the *spawn-argv / hook-registration /
permission-mode-vocabulary* level:

- `host-agent/lib/Agents/AgentAdapter.php`
- `host-agent/lib/Agents/AgentRegistry.php`
- `host-agent/lib/Agents/ClaudeCodeAdapter.php`
- `host-agent/lib/Agents/AntigravityAdapter.php`
- `host-agent/lib/Agents/OpenCodeAdapter.php`
- `tests/test_agent_adapter.php`

**Out of scope** (explicitly named neighbors — per-agent *behavior* these
are not glued through the seam, they live in their own subsystems):

- **`session-status-state`** — `SessionStatusStore`, hook *payload parsing*
  (the actual `host-agent/hooks/*.php` and `host-agent/hooks/antigravity/*.php`
  scripts), and `SessionService::build_session_entry()`'s consumption of
  agent identity/status. The `AgentAdapter` interface deliberately does NOT
  cover hook payload parsing (see `AgentAdapter.php:16-24`).
- **`prompt-interaction`** — `PermissionMode`, `SelectableModel`,
  `AntigravitySelectableModel`, `PromptParser`/`AntigravityPromptParser`/
  `OpenCodePromptParser`, `PromptInteractionService`. The adapters do read
  `PermissionMode`'s vocabulary (Claude), and refer semantically to the
  model pickers, but they do not own them.
- **`session-view`** — `TranscriptView`, `SessionRowView`,
  `BlockedPromptView`, `PageView::AGENT_OPTIONS` (a view-layer mirror of the
  registry). Per-agent rendering lives there, not here.
- Shared deps honored but not owned: `Config`, `HookService`,
  `AntigravityHookService`, `SessionLifecycleService` (`session-lifecycle`),
  `agent.php` / `lib/Sessions.php` (host-agent runtime dispatch).

## Key implementation files

- **`host-agent/lib/Agents/AgentAdapter.php`** — the `AgentAdapter`
  contract (interface). Deliberately narrow: only spawn argv, hook
  check/install, and permission-mode vocabulary; explicitly excludes hook
  payload parsing and tmux/SQLite plumbing (`AgentAdapter.php:7-24`).
- **`host-agent/lib/Agents/AgentRegistry.php`** — the seam's registry.
  The one place that knows which adapter class maps to which stable agent
  id; everything else asks it for an adapter rather than instantiating one.
  Adding an agent = one line in `ADAPTERS` (`AgentRegistry.php:18-22`).
- **`host-agent/lib/Agents/ClaudeCodeAdapter.php`** — first implementation
  (Phase 1 of `docs/antigravity-adapter-plan.md`), a thin wrapper over the
  pre-existing `HookService` + `PermissionMode` + `SessionLifecycleService::
  generate_uuid_v4()`. Byte-identical spawn behavior to the inline
  `create_cc_session()` it was extracted from (2026-08-24).
- **`host-agent/lib/Agents/AntigravityAdapter.php`** — second implementation
  (Phase 2/3), for Google's Antigravity CLI (`agy`), live-verified against
  `agy 1.1.19`. Encodes Antigravity's `--mode` flag vocabulary and its
  no-upfront-session-id reality.
- **`host-agent/lib/Agents/OpenCodeAdapter.php`** — third implementation,
  for the OpenCode TUI CLI (`opencode`), live-verified against `opencode
  1.18.21`. Encodes the positional `opencode [project]` arg shape and the
  `--session <id>` is-resume-only reality. Hook check/install are honest
  stubs (plugin not yet shipped).
- **`tests/test_agent_adapter.php`** — isolated unit test for the registry
  and all three adapters. Notably proves `ClaudeCodeAdapter::build_spawn_argv()`
  is byte-identical to the pre-extraction inline build.

## Public interfaces & contracts

### `AgentAdapter` (interface) — `AgentAdapter.php:25`

| Method | Line | Contract |
|---|---|---|
| `id(): string` | `AgentAdapter.php:32` | Stable machine identifier (`'claude'`, `'antigravity'`, `'opencode'`). Persisted in the `sidecars.agent` column so a session's own row says which adapter governs it. |
| `label(): string` | `AgentAdapter.php:38` | Human-readable name for the New Session UI picker and display. |
| `session_name_prefix(): string` | `AgentAdapter.php:47` | tmux session-name prefix (`cc`/`ag`/`oc`). Only affects the generated name — session *tracking* is sidecar-existence-based, not a prefix glob. |
| `build_spawn_argv(array $options): array` | `AgentAdapter.php:66` | Returns `array{argv: string[], assigned_id: ?string}`. `argv` is the agent's binary + CLI flags; `assigned_id` is the agent's own conversation id if choosable up front, else `null`. `$options` is adapter-specific; each adapter reads only keys it understands. |
| `check_hooks(): array` | `AgentAdapter.php:78` | `array{ok:bool, installed:bool, message?:string}` — same shape as `HookService::check_session_hook()`, so callers needn't know which agent. |
| `install_hooks(): array` | `AgentAdapter.php:86` | Registers every missing hook idempotently. Same shape as above. |
| `permission_mode_map(): array` | `AgentAdapter.php:99` | Raw per-agent mode enum values → CSM's own manual/accept-edits/plan/auto vocabulary (same shape as `PermissionMode::HOOK_PERMISSION_MODE_MAP`). Unmapped raw value = "unrecognized". |

### `AgentRegistry` — `AgentRegistry.php:15`

| Method | Line | Contract |
|---|---|---|
| `ADAPTERS` const | `AgentRegistry.php:18-22` | `array<string, class-string<AgentAdapter>>` — `'claude' => ClaudeCodeAdapter::class`, `'antigravity' => AntigravityAdapter::class`, `'opencode' => OpenCodeAdapter::class`. Hardcoded by design; adding an agent = one line. |
| `get(string $agentId): AgentAdapter` | `AgentRegistry.php:27` | Returns a cached singleton adapter instance (`self::$instances` `??=`). Throws `\InvalidArgumentException` for an unknown id (`AgentRegistry.php:30`). |
| `default_agent_id(): string` | `AgentRegistry.php:41` | Returns `'claude'` — the fallback used when nothing else says otherwise, unchanged from pre-registry behavior. |
| `known_agent_ids(): string[]` | `AgentRegistry.php:47` | `array_keys(self::ADAPTERS)` → `['claude','antigravity','opencode']`. Used as the whitelist by callers before trusting a caller-supplied agent id. |

### `ClaudeCodeAdapter` — `ClaudeCodeAdapter.php:21`

- `id()` `'claude'` (`:23`), `label()` `'Claude Code'` (`:28`), `session_name_prefix()` `'cc'` (`:33`).
- `build_spawn_argv(array $options): array` (`:44`):
  - Generates a v4 UUID up front via `SessionLifecycleService::generate_uuid_v4()` (`:46`) → `[claude_bin, '--session-id', <uuid>]` (`:47`). So `assigned_id` is never null for Claude.
  - `$options['enable_task_tools']` (bool) → appends `--allowedTools TaskCreate,TaskGet,TaskList,TaskUpdate` (`:49-52`).
  - `$options['starting_mode']` (CSM's own manual/accept-edits/plan/auto) → mapped through `PermissionMode::HOOK_PERMISSION_MODE_MAP` (flipped) to the real `--permission-mode` value (`:54-62`). Unrecognized/null mode omits the flag (whitelisted, never trusted raw).
- `check_hooks()/install_hooks()` (`:67-75`) — pure delegation to `HookService::check_session_hook()/install_session_hook()`.
- `permission_mode_map()` (`:77-80`) — returns `PermissionMode::HOOK_PERMISSION_MODE_MAP` directly (not a second copy).

### `AntigravityAdapter` — `AntigravityAdapter.php:19`

- `STARTING_MODE_FLAGS` const (`:32-35`): `['accept edits' => 'accept-edits', 'plan' => 'plan']`. `'manual'` is deliberately absent — omitting `--mode` IS Antigravity's manual/default; no observed `auto`/`bypass` 4th mode.
- `id()` `'antigravity'` (`:37`), `label()` `'Antigravity'` (`:42`), `session_name_prefix()` `'ag'` (`:47`).
- `build_spawn_argv(array $options): array` (`:73`):
  - argv always starts with `Config::antigravity_bin()` (`:75`).
  - `$options['model']` (string, non-empty) → `--model` (`:77-82`).
  - `$options['effort']` (string) → `--effort` only when `low|medium|high` (whitelisted, `:84-89`).
  - `$options['starting_mode']` → `--mode` via `STARTING_MODE_FLAGS` (`:91-97`); unrecognized (e.g. `'auto'`) silently ignored.
  - `$options['enable_task_tools']` silently ignored (Claude-only concept).
  - `assigned_id` is **always null** (`:99`) — no `--session-id/--conversation-id` exists for a fresh interactive session; `--conversation <id>` only *resumes*. Identity is learned reactively after spawn.
- `check_hooks()/install_hooks()` (`:105-116`) — delegate to `AntigravityHookService` (`~/.gemini/config/hooks.json`).
- `permission_mode_map()` (`:131-134`) — returns `array_flip(self::STARTING_MODE_FLAGS)`. **Placeholder**: no Antigravity hook payload observed to carry a mode/permission field, so nothing consumes it through `normalize_hook_permission_mode()`-style logic yet.

### `OpenCodeAdapter` — `OpenCodeAdapter.php:20`

- `id()` `'opencode'` (`:22`), `label()` `'OpenCode'` (`:27`), `session_name_prefix()` `'oc'` (`:32`).
- `build_spawn_argv(array $options): array` (`:53`):
  - argv starts with `Config::opencode_bin()` (`:55`).
  - `$options['workdir']` (or `$options['directory']`) → positional `opencode [project]` arg (`:57-61`).
  - `$options['model']` (non-empty string) → `--model provider/model` (`:63-68`).
  - `$options['agent']` (non-empty string) → `--agent <name>` (OpenCode's own agent concept) (`:70-75`).
  - `enable_task_tools`, `starting_mode`, `effort` silently ignored — no `--permission-mode`/`--mode`/`--effort` equivalents (only `--auto`, a boolean not wired here).
  - `assigned_id` **always null** (`:77`) — no `--session-id` for a fresh TUI; `--session <id>` is resume-only. Identity (`ses_*` from `opencode.db`) learned reactively.
- `check_hooks()/install_hooks()` (`:83-94`) — honest stubs returning `['ok' => true, 'installed' => false, 'message' => 'OpenCode plugin not yet installed (Phase 5 will add csm-status plugin)']`. No plugin registration yet.
- `permission_mode_map()` (`:106-109`) — `[]` (no mode vocabulary; only the boolean `--auto`).

## Major call sites

`AgentRegistry` / adapters are consumed by (grep across repo):

- **`SessionLifecycleService::create_cc_session()`** — `SessionLifecycleService.php:62`, the primary creator. Whitelists `$agentId` against `AgentRegistry::known_agent_ids()`, falling back to `default_agent_id()` (`:74`); resolves the adapter (`:75`), builds the session name from `session_name_prefix()` (`:76`), builds spawn argv via `build_spawn_argv()` (`:77-79`), merges the agent argv into the `tmux new-session` wrapper (`:93`), and writes the sidecar with `'agent' => $agent->id()` (`:121`).
- **`SessionLifecycleService::resume_cc_session()`** — `SessionLifecycleService.php:214`. Picks `'opencode'` vs `'claude'` from `OpenCodeTranscriptService::is_opencode_id()` (`:239-240`), resolves the adapter for the session-name prefix/label (`:241-242`), and uses it in the failure message (`:267`).
- **`Sessions.php::dispatch_action()`** — the host-agent runtime switch. `'create'` passes the caller-supplied `agent` through to `create_cc_session()` (`Sessions.php:100-107`). Note: `check_session_hook`/`install_session_hook` actions are **still routed to `HookService` directly** (`Sessions.php:179-183`), NOT through `AgentRegistry`/adapters — the adapters' `check_hooks()`/`install_hooks()` are currently exercised only by the test file.
- **`SessionService::build_session_entry()`** — `SessionService.php:288`. Resolves the display label via `AgentRegistry::get($agentId)->label()`, wrapped in try/catch (`:289-291`) falling back to `'Claude Code'` for an unknown/unset id.
- **`agent.php`** — `agent.php:38`, dispatches each connection via `dispatch_push_action() ?? dispatch_action()`; it reaches the registry transitively through `Sessions.php`/`SessionLifecycleService`.
- **`PageView::AGENT_OPTIONS`** — `src/lib/Views/PageView.php:27`. A **view-layer mirror** of `AgentRegistry::known_agent_ids()/label()`, deliberately NOT a call into the registry (the container web UI cannot reach host-agent's env/config — see `PageView.php:16-26`). Drift risk documented; sync by hand.
- **`src/partials/pages/index.php:129-130`** — renders the New Session `<select name="agent">` from `PageView::AGENT_OPTIONS`.
- **`src/partials/compose-bar.php:55`** and **`src/partials/pages/session.php:266-268,349-350`** — consume the per-session `agent`/`agent_label` values (produced up-stream by `build_session_entry()`), picking Antigravity-specific quota footer / label fallbacks.

## Tests

**`tests/test_agent_adapter.php`** (204 lines). Runs under `tests/run.sh` (with `--bail` support) via plain `php` CLI; self-isolating:

- **Isolation** — sets `HOME_ROOT` to a temp fixture home (`:33-34`) and **refuses to run** if `Config::home_root()` still resolves to `/home/user` (`:36-39`). Cleanup in the `finally` block unlinks fixture `settings.json`/`hooks.json` and temp dirs (`:195-202`).
- **Registry identity/lookup** (`:44-59`): `default_agent_id()` == `'claude'`; `known_agent_ids()` == `['claude','antigravity','opencode']`; `get('claude')` returns a `ClaudeCodeAdapter`, is the same cached instance on second call, and throws `InvalidArgumentException` for an unknown id.
- **ClaudeCodeAdapter** (`:61-102`): id/label/prefix; `permission_mode_map()` is literally `PermissionMode::HOOK_PERMISSION_MODE_MAP`; `build_spawn_argv([])` is `[claude_bin, '--session-id', <v4 uuid>]` with a fresh uuid per call; `--allowedTools`/`TaskCreate,TaskGet,TaskList,TaskUpdate` present when `enable_task_tools` and absent otherwise; `starting_mode => 'accept edits'` → `--permission-mode acceptEdits` (via the shared map); unknown/null mode omits the flag; `check_hooks()` identical to `HookService::check_session_hook()`, and `install_hooks()` actually writes `~/.claude/settings.json`.
- **AntigravityAdapter** (`:104-144`): id/label/prefix; bare argv is just the binary and `assigned_id` stays null; `model` passthrough; `effort` whitelist (`ludicrous` ignored, `high` accepted); `starting_mode => 'accept edits'` → `--mode accept-edits`; `'manual'` and `'auto'` omit `--mode`; `enable_task_tools` ignored; `check_hooks()` identical to `AntigravityHookService::check_session_hook()`, `install_hooks()` writes `~/.gemini/config/hooks.json`.
- **OpenCodeAdapter** (`:146-194`): id/label/prefix; bare argv is just the binary, `assigned_id` null; positional `workdir` follows the binary; `model` passthrough with empty-string omission; `agent` passthrough; `enable_task_tools`/`starting_mode` ignored (no `--permission-mode` and no `--mode`); `permission_mode_map()` == `[]`; `check_hooks()`/`install_hooks()` honestly report `installed=false` with `ok=true`.

Coverage shape: **happy + sad path** (unknown registry id, unrecognized mode/effort, empty model string, honest stub reporting) — this is the deliberate, thorough set that proves the extraction produced byte-identical argv for Claude, not a skim.

## Dependencies

**Upstream (used by this subsystem):**

- `HostAgent\Services\Config` — `claude_bin()`, `antigravity_bin()`, `opencode_bin()` (`Config.php:37,49,60`) in each adapter's `build_spawn_argv`; `claude_settings_path()` / `antigravity_hooks_path()` indirectly via HookService. Not an owned dependency; a shared cross-cutting service.
- `HostAgent\Services\HookService` (ClaudeCodeAdapter `:8`), `AntigravityHookService` (AntigravityAdapter `:7`) — the actual hook check/install implementations the adapters delegate to.
- `HostAgent\Services\PermissionMode` (ClaudeCodeAdapter `:9`) — vocabulary translation. **Cross-subsystem reference** (lives in `prompt-interaction`).
- `HostAgent\Services\SessionLifecycleService` (ClaudeCodeAdapter `:10`) — `generate_uuid_v4()` (`:46`).
- `SelectableModel` / `AntigravitySelectableModel` — referenced *conceptually* by the model options (Antigravity/OpenCode `model` passthrough) but **not imported** by any owned file; they live in `prompt-interaction` and are consumed by `SessionService::build_session_entry()`.

**Downstream (consumes this subsystem):**

- `SessionLifecycleService` (create/resume) — calls `AgentRegistry::get()` / `known_agent_ids()` / `default_agent_id()` / `session_name_prefix()` / `build_spawn_argv()` / `label()` / `id()`.
- `SessionService::build_session_entry()` — `AgentRegistry::get($agentId)->label()` (`SessionService.php:288`).
- `Sessions.php::dispatch_action()` — routes the `agent` field into `create_cc_session()`.
- `agent.php` — transitively, via the dispatchers.
- `PageView::AGENT_OPTIONS` (view-layer mirror) and the New Session picker in `index.php`.

**Notable cycle:** `ClaudeCodeAdapter` → `SessionLifecycleService::generate_uuid_v4()`, while `SessionLifecycleService` → `AgentRegistry` → `ClaudeCodeAdapter`. Benign because `generate_uuid_v4()` is a pure static utility, but the extraction left this conceptual cycle in place; the uuid helper is a candidate to move to a neutral utility class.

**Reverse dependency gap (accuracy note):** `AgentAdapter.check_hooks()/install_hooks()` docblock (`AgentAdapter.php:73`) claims callers include "the dashboard's health box" and `agent.php`'s `dispatch_action()`. In the current tree those paths do **not** route through the registry: the health box (`PushHealthService::health_check()`) iterates `HookService::app_hooks_status()` directly plus `opencode_serve_check()`/`opencode_plugin_check()` (`PushHealthService.php:194-201,220-221`), and `Sessions.php:179-183` calls `HookService` directly. So the adapter hook methods are contract-shape-complete and test-covered but not yet production-wired.

## Data & schema

- **DB column `sidecars.agent`** (`SqliteDb::sessions_schema()`, `SqliteDb.php:124` — `agent TEXT`). Added 2026-08-24 for multi-agent support via `add_column_if_missing` (`SqliteDb.php:92`, invoked at `SidecarStore.php:32`) because `CREATE TABLE IF NOT EXISTS` won't retrofit an existing table.
- **Sidecar read** (`SidecarStore.php:42-66`): default `agent` to `'claude'` when the row was written pre-column (`:60-66`).
- **Sidecar write** (`SidecarStore.php:88-107`): `'agent' => $data['agent'] ?? 'claude'` (`:107`); `create_cc_session()` sets it to `$agent->id()` (`SessionLifecycleService.php:121`); `resume_cc_session()` sets it to `$resumeAgentId` (`SessionLifecycleService.php:271`).
- **Spawn argv shape** (`AgentAdapter::build_spawn_argv`): `array{argv: string[], assigned_id: ?string}`. `argv[0]` is always the agent binary (`Config::claude_bin()`/`antigravity_bin()`/`opencode_bin()`).
- **Hook registration entries (adapter-owned), per agent:**
  - Claude Code → `~/.claude/settings.json` `hooks` object; each entry `{event, matcher:'*', hooks:[{type:'command', command: <Config::*_hook_command()>}]}` — built by `HookService` (`HookService.php:202-212`), driven off `app_hooks_status()` (`HookService.php:123-132`). Scripts: `host-agent/hooks/{session_start,pre_tool_use,permission_request,user_prompt_submit,stop}.php`.
  - Antigravity → `~/.gemini/config/hooks.json` (shared global path; `Config::antigravity_hooks_path()`, `Config.php:327`). Scripts: `host-agent/hooks/antigravity/{pre_tool_use,post_tool_use,pre_invocation,stop}.php` (command strings at `Config.php:337-355`).
  - OpenCode → not yet produced (`check_hooks`/`install_hooks` are stubs). Planned as a `csm-status` plugin; the dashboard health box already checks for a plugin file at `~/.config/opencode/plugins/csm-permissions.js` (`PushHealthService.php:147`), distinct from the stub seam.

## Conventions / quirks

The seam owns exactly three genuinely-per-agent surfaces; everything agent-like that's *already* agent-agnostic deliberately stays out:

1. **Spawn argv** — each adapter builds its own binary + flags:
   - Claude: v4 UUID assigned up front (`--session-id`), `--allowedTools` for Task tools, `--permission-mode` from CSM's vocabulary via `PermissionMode::HOOK_PERMISSION_MODE_MAP`.
   - Antigravity: `--model`/`--effort`/`--mode`; **no** up-front id (reactive binding after spawn); `manual`/`auto` have no flag.
   - OpenCode: positional project `[workdir]`, `--model`, `--agent`; **no** up-front id (reactive binding from `opencode.db`); no mode/effort flags.
   - Universal: `enable_task_tools` is a Claude-only option; `starting_mode` is CSM's own shared vocabulary, but each adapter whitelists it against its **own** real flag values. `assigned_id: null` ⇒ real id learned reactively (the only two adapters that are null).

2. **Hook registration** — `check_hooks()`/`install_hooks()` return a uniform `{ok, installed, message?}` so callers needn't know which agent. Claude + Antigravity delegate to their dedicated services; OpenCode is an honest stub. All are idempotent by contract.

3. **Permission-mode vocabulary** — `permission_mode_map()` returns raw enum → CSM vocabulary. Claude returns the fully-backed `PermissionMode::HOOK_PERMISSION_MODE_MAP`; Antigravity returns an `array_flip` of its own CLI map that is **not yet backed by an observed hook payload** (see `AntigravityAdapter.php:118-134`); OpenCode returns `[]` because it only has the boolean `--auto`.

Per-agent prompt parsing, transcript rendering, hook payload scripts, model pickers, and quota are **owned elsewhere** (see Ownership boundary) — the seam is intentionally not the place that interprets an agent's hook JSON or renders its UI.

**Seam philosophy (from `AgentAdapter.php:7-24`):** deliberately narrow — tmux plumbing, SQLite storage, and hook *payload* parsing are already agent-agnostic and stay out. Hook payload shapes differ too much (Claude Code flat `tool_name`/`tool_input`; Antigravity nested `toolCall.name/args`, decision-gated PreToolUse, no SessionStart equivalent) to force through one shared dispatch, so each agent keeps its own small explicit hook scripts instead of a shared indirection layer.

## Files owned

- `host-agent/lib/Agents/AgentAdapter.php` (interface — the contract)
- `host-agent/lib/Agents/AgentRegistry.php` (registry — the seam entry point)
- `host-agent/lib/Agents/ClaudeCodeAdapter.php`
- `host-agent/lib/Agents/AntigravityAdapter.php`
- `host-agent/lib/Agents/OpenCodeAdapter.php`
- `tests/test_agent_adapter.php`

No co-owned flags: every file is exclusively within this subsystem's boundary. All six are committed and clean at HEAD `44e4caa`.
