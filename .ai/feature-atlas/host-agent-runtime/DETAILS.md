---
id: host-agent-runtime
name: Host-agent bootstrap, protocol, config & packaging
owned_paths:
  - host-agent/agent.php
  - host-agent/lib/Sessions.php
  - host-agent/lib/Push.php
  - host-agent/lib/Services/Config.php
  - host-agent/install.sh
  - host-agent/.env
  - host-agent/.env.example
  - host-agent/systemd/*
  - tests/run.sh
  - tests/.env.testing
  - tests/lib/
  - tests/test_agent_client_protocol.php
  - tests/test_socket_harness.php
last_scanned_commit: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-26
---

# Host-agent bootstrap, protocol, config & packaging

## Identity

- **id:** `host-agent-runtime`
- **name:** Host-agent bootstrap, protocol, config & packaging

This subsystem is the host-native agent's *frame*, not its features: the
per-connection process lifecycle, the one-request-one-response JSON
protocol over a UNIX socket, the two action dispatchers, the env/Config layer
every host service reads, and the systemd + `install.sh` packaging that makes
it run under socket activation. It owns no tmux/`/proc` logic and no feature
service — those live in the services under `host-agent/lib/Services/*` and
`host-agent/lib/Stores/*`, each owned by its own feature subsystem.

## Ownership boundary

**In scope (owned paths above, described below):**

- `host-agent/agent.php` — the entry point, one request per process.
- `host-agent/lib/Sessions.php` — `dispatch_action()`, the main (non-push) switch.
- `host-agent/lib/Push.php` — `dispatch_push_action()`, the push/health/timer switch that falls out to Sessions.
- `host-agent/lib/Services/Config.php` — env-driven paths/thresholds, cross-cutting.
- `host-agent/install.sh` — packaging: composer, `.env`, systemd unit rendering, enable/no-enable decisions.
- `host-agent/.env` + `host-agent/.env.example` — the real host env + portable template.
- `host-agent/systemd/*` — the user units (socket pair, two timers + services, opencode-serve). Note `host-agent/systemd/opencode-serve.service` is **untracked** in the current working tree (see `## Co-owned / cross-subsystem`).
- `tests/run.sh` + `tests/.env.testing` — the suite driver and its isolated-fixture env.
- `tests/lib/` — the assertion/HTTP/socket-harness helpers; note `cdp.php` and `replay_fixture.php` are co-located but belong to the browser-replay feature (see Co-owned).
- `tests/test_agent_client_protocol.php` — end-to-end protocol test through the real `agent.php`.
- `tests/test_socket_harness.php` — harness self-cleanup safeguard test.

**Out of scope (explicitly NOT here):**

- The container web UI (`public/`, `src/lib/Controllers/*`, `src/lib/Views/*`, `src/lib/Http/*`) — a separate subsystem. **`src/lib/AgentClient.php` is owned by the web-UI subsystem**, but it is the authoritative remote consumer of this subsystem's dispatchers and is referenced below.
- Every feature service it dispatches to: `SessionService`, `SessionDetailService`, `SessionLifecycleService`, `ArchivedSessionService`, `BareProcessService`, `PromptInteractionService`, `PlanFileService`, `UploadService`, `QuotaService`, `HookService`, `TmuxService`, `TranscriptService`, `ProcessInspector`, `ProcessRunner`, `PromptParser`, `StatuslineMarkerService`, the Agents/* adapters, and the push services/stores — each owned by its own feature subsystem.
- `host-agent/lib/Stores/*` (SidecarStore, SessionStatusStore, PendingToolStore, PushSubscriptionStore, PushSessionStateStore, PushQuotaStateStore, GlobalStateStore, SqliteDb) — feature store subsystems, not this frame.
- Per-feature entry scripts launched by the systemd units packaged here but not resident in this frame: `host-agent/push_trigger.php` (push subsystem), `host-agent/quota_live_state_write.php` (quota subsystem), `host-agent/antigravity_quota_poll.php` (antigravity-quota subsystem). `host-agent/opencode_diagnose.php` is **untracked** and owned by `prompt-interaction`.
- `host-agent/hooks/*` (the five Claude Code hooks + `hooks/antigravity/*`) and `host-agent/opencode-plugins/*` — hook/plugin subsystems.
- Config-referenced paths that are the *data* of other subsystems (the SQLite DBs, statusline script) — Config.php only computes the paths.

## Key implementation files

- **`host-agent/agent.php`** (`host-agent/agent.php`) — the bootstrapper. Raises `memory_limit` to 512M (line 21), requires `lib/Sessions.php` + `lib/Push.php` (lines 23–24), reads one JSON request from STDIN (lines 26–27), validates shape (lines 29–32), dispatches via `dispatch_push_action() ?? dispatch_action()` (line 38), writes one JSON response to STDOUT (line 40), and exits.
- **`host-agent/lib/Sessions.php`** — `dispatch_action(array $request): array` (line 31), the main switch mapping every non-push `action` to a `HostAgent\Services\*` call. This is the largest surface in the subsystem (37 cases + default).
- **`host-agent/lib/Push.php`** — `dispatch_push_action(array $request): ?array` (line 33), handling `push_*` actions plus `health_check` and the push-timer actions, and returning `null` for anything it doesn't recognize so `agent.php` falls through to Sessions.
- **`host-agent/lib/Services/Config.php`** — `class Config` (line 22) of static accessors reading env vars (via `csm_config()`, lines 24–28) with environment-derived or empty defaults. Read by essentially every host service.
- **`host-agent/install.sh`** — the packaging script. Ensures vendor + `.env` (lines 13–22), warns on missing `CLAUDE_BIN` (lines 28–33), `render_unit()` substitutes `@REPO_ROOT@`, `@PHP_BIN@`, `@SOCKET_GROUP@`, `@OPENCODE_BIN@`, `@HOME@` placeholders into the unit templates (lines 53–61), installs/enables the socket (lines 64–68), install-but-not-enables the push and antigravity timers (lines 83–98), enables `opencode-serve.service` + the CSM plugin only when `OPENCODE_BIN` is known (lines 106–142), and checks linger (lines 144–148).
- **`host-agent/.env`** / **`host-agent/.env.example`** — the production env for the running agent + the portable template describing each var and its default.
- **`host-agent/systemd/*`** — `csm-agent.socket` (the listening socket, `Accept=yes`), `csm-agent@.service` (per-connection instance), `csm-push-check.{service,timer}`, `csm-antigravity-quota-check.{service,timer}`, `opencode-serve.service`. The `.socket` + `@.service` pair is the socket-activation mechanism the whole runtime depends on.
- **`tests/run.sh`** — the suite driver: parses flags, locks against a second run of the same checkout (lines 95–100), sources `.env.testing` (lines 102–105), refuses to touch the real tmux/sidecar (lines 111–122), runs each `test_*.php` (lines 223–238), and guarantees `tmux kill-server`/sidecar cleanup on exit (trap, lines 190–191).
- **`tests/.env.testing`** — the isolated-fixture env: fake `claude`/`agy`/`opencode` binaries, fixture `WWW_ROOT`/`HOME_ROOT`, an isolated `TMUX_SOCKET`/`SIDECAR_DIR`, a 2-second cleanup threshold, and a fake push-timer unit name.
- **`tests/lib/socket_harness.php`** — a generic per-connection UNIX-socket harness standing in for systemd `Accept=yes`; runs `<command...>` with the accepted stream bound to its stdin *and* stdout (line 122). `kill_stale_listener()` (line 39) kills a previous holder of the same socket path before rebinding.
- **`tests/lib/harness.php`** — `start_harness()` (line 14) / `stop_harness()` (line 41) wrappers that spawn `socket_harness.php` and wait for the socket file.
- **`tests/lib/assert.php`** — `assert_true`/`assert_equal`/`assert_contains` (lines 6, 17, 30) and `test_exit()` (line 44, exit code drives `run.sh` pass/fail).
- **`tests/lib/http.php`** — `curl_request()` (line 11) helper (used by other test files; owned by the web-UI test concern).
- **`tests/test_agent_client_protocol.php`** — drives `App\AgentClient::agent_call()` against the *real* `agent.php` over the harness socket.
- **`tests/test_socket_harness.php`** — tests `kill_stale_listener()`; its `pid_alive()` helper (line 31) deliberately excludes zombie state.

## Public interfaces & contracts

### Entry point — `agent.php`

- **Input:** one JSON object read from STDIN (`stream_get_contents(STDIN)`, line 26). Precondition: `memory_limit` raised to 512M (line 21) so base64-relayed large uploads decode (container-side counterpart `UploadController::upload()`).
- **Output:** one JSON object written to STDOUT (line 40), then the process exits. No length prefix, no multiplexing — strictly one request / one response.
- **Error contract on malformed input** (not an array or missing `action`, lines 29–32): writes `{"ok":false,"message":"Malformed request"}` and `exit(0)` — a handled error, never a crash/500-equivalent, and a zero exit so systemd doesn't flag it.
- **Dispatch chain (line 38):** `dispatch_push_action($request) ?? dispatch_action($request)` — the push dispatcher gets first refusal, returning `null` to fall through.

### `dispatch_action(array $request): array` — `lib/Sessions.php:31`

The main switch. Every non-push action maps to a `HostAgent\Services\*` call. All use `(string)`/`(int)` casts on `$request` fields (the web UI is the only caller, but the dispatcher defensive-casts rather than trusting it). Default (line 205) → `{'ok':false,'message':'Unknown action'}`.

| `action` | Call (with arg coaxing) | Notable pre-condition |
|---|---|---|
| `list` | `SessionService::list_all_sessions()` (35) | |
| `list_archived` | `ArchivedSessionService::list_archived_dashboard()` (38) | |
| `session_detail` | `SessionDetailService::session_detail((string) session)` (41) | |
| `archived_session_detail` | `SessionDetailService::archived_session_detail((string) claude_session_id)` (44) | |
| `session_history` | `SessionDetailService::session_history(session, before?:int, limit default 30, after?:int, until_user?:bool)` (47–53) | |
| `archived_session_history` | `SessionDetailService::archived_session_history(claude_session_id, before?:int, limit default 30, after?:int)` (56–61) | |
| `search_transcripts` | `ArchivedSessionService::search_transcripts(query, max_sessions default 30, max_matches_per_session default 3)` (64–68) | |
| `session_transcript_search` | `ArchivedSessionService::session_transcript_search(session, query, max_matches default 20)` (71–75) | |
| `archived_session_transcript_search` | `ArchivedSessionService::archived_session_transcript_search(claude_session_id, query, max_matches default 20)` (77–82) | |
| `session_attachment` | `SessionDetailService::session_attachment(session, line?:int, file_uuid)` (85–89) | |
| `archived_session_attachment` | `SessionDetailService::archived_session_attachment(claude_session_id, line?:int, file_uuid)` (92–96) | |
| `create` | `SessionLifecycleService::create_cc_session(workdir, enable_task_tools?:bool, starting_mode?:string`, `agent?:string)` (99–107) | mode/agent only forwarded if non-empty strings |
| `resume` | `SessionLifecycleService::resume_cc_session(workdir, claude_session_id)` (110) | |
| `kill` | `SessionLifecycleService::kill_cc_session(session)` (113) | |
| `kill_bare` | `BareProcessService::kill_bare_process(pid?:int)` (116) | |
| `take_over_bare` | `BareProcessService::take_over_bare_process(pid?:int)` (119) | |
| `take_over_bare_with_id` | `BareProcessService::take_over_bare_process_with_id(pid, workdir, claude_session_id)` (122–126) | |
| `answer_prompt` | `PromptInteractionService::answer_prompt(session, option?:int)` (129) | |
| `answer_prompt_with_text` | `PromptInteractionService::answer_prompt_with_text(session, option?:int, text)` (132) | |
| `answer_multi_question` | `PromptInteractionService::answer_multi_question(session, answers?:array)` (135) | answers coerced to `[]` if not an array |
| `send_escape` | `PromptInteractionService::send_escape(session)` (138) | |
| `send_message` | `PromptInteractionService::send_message(session, text, attachment_paths?:array of str)` (141–145) | |
| `set_mode` | `PromptInteractionService::set_mode(session, mode)` (148) | |
| `set_model` | `PromptInteractionService::set_model(session, model)` (151) | |
| `set_antigravity_model` | `PromptInteractionService::set_antigravity_model(session, model)` (154) | |
| `cleanup` | `SessionLifecycleService::cleanup_inactive_sessions()` (157) | |
| `browse_dir` | `SessionService::browse_dir(path)` (160) | |
| `create_dir` | `SessionService::create_dir(path, name)` (163) | |
| `list_plan_files` | `PlanFileService::list_plan_files(session)` (166) | |
| `read_plan_file` | `PlanFileService::read_plan_file(session, filename)` (169) | |
| `read_todo_file` | `PlanFileService::read_todo_file(session)` (172) | |
| `quota` | `QuotaService::get_quota(session? )` (175–177) | empty `session` trimmed to `null` |
| `check_session_hook` | `HookService::check_session_hook()` (180) | |
| `install_session_hook` | `HookService::install_session_hook()` (183) | |
| `save_uploaded_file` | `UploadService::save_uploaded_file(session, filename, content_base64)` (186–190) | base64 relayed over socket |
| `list_uploaded_files` | `UploadService::list_uploaded_files(session)` (193) | |
| `read_uploaded_file` | `UploadService::read_uploaded_file(session, filename)` (196) | |
| `delete_uploaded_file` | `UploadService::delete_uploaded_file(session, filename)` (199) | |
| `delete_all_uploaded_files` | `UploadService::delete_all_uploaded_files(session)` (202) | |

**Return contract:** always a JSON-serializable associative array; every case carries `ok`, some add feature-specific keys (`sessions`, `archived`, `path`, `dirs`, `parent`, `files`, `data`, `questions`, `public_key`, …). Unknown action → `{'ok':false,'message':'Unknown action'}` (line 205). This is not a typed schema — the shape is whatever the service returns, wrapped in `ok`. Because the process exits after one response, there is no persistent server-side session/state to keep consistent; freshness is re-derived per request by the services.

### `dispatch_push_action(array $request): ?array` — `lib/Push.php:33`

| `action` | Call | Contract |
|---|---|---|
| `push_public_key` | `PushDeliveryService::push_configured()` + `vapid_public_key()` (37) | `{'ok':true,'configured':bool,'public_key':string}` |
| `push_subscribe` | `PushSubscriptionStore::add_push_subscription($request['subscription'])` (46–48) | missing `subscription` → `{'ok':false,'message':'Missing subscription'}` (43); malformed → `{'ok':false,'message':'Malformed subscription'}` (47); success → `{'ok':true}` |
| `push_unsubscribe` | `PushSubscriptionStore::remove_push_subscription((string) endpoint)` (51–53) | always `{'ok':true}` |
| `health_check` | `PushHealthService::health_check()` (56) | health-box payload (covers non-push checks too) |
| `get_push_timer_interval` | `PushTimerService::get_push_timer_interval()` (59) | |
| `set_push_timer_interval` | `PushTimerService::set_push_timer_interval(seconds?:int)` (62) | |
| anything else | — | `null` (line 65) — the fall-through signal that makes `agent.php`'s `??` route to `dispatch_action()`. |

**Key contract:** returning `null` (not a shaped error) is the *intentional* fall-through mechanism. This is what keeps the two dispatchers separate yet composable — `agent.php` does the `??`, so `dispatch_push_action()` never needs to know every Sessions action and vice versa.

### `Config` accessors — `lib/Services/Config.php:22`

`csm_config(string $key, string $default): string` (line 24) reads `getenv($key)` and returns the default only when unset **or empty string**. Derived accessors (defaults shown):

- Paths: `claude_bin()` → `''` (37); `antigravity_bin()` → `''` (49); `opencode_bin()` → `''` (60); `www_root()` → `home_root()` (71); `home_root()` → `getenv('HOME') ?: ''` (76); `tmux_socket()` → `/tmp/tmux-<uid>/default` (87); `sidecar_dir()` → `/run/user/<uid>/csm-sessions` (92); `opencode_permission_dir()` → `sidecar_dir()/opencode-permissions` (105); `sessions_sqlite_path()` → `sidecar_dir()/sessions.sqlite` (201); `push_sqlite_path()` → `csm_repo_root()/host-agent/state/push.sqlite` (219); `opencode_db_path()` → `~/.local/share/opencode/opencode.db` (231); `opencode_server_url()` → `http://localhost:4096` (246); `csm_repo_root()` → `dirname(__DIR__, 3)` (258); `claude_settings_path()` → `~/.claude/settings.json` (263).
- Thresholds/sizes: `cleanup_threshold_seconds()` → `'43200'` (110); `new_session_pane_width()` → `'200'` (128); `new_session_pane_height()` → `'150'` (133).
- Computed: `antigravity_transcript_path(conversationId)` (147) → `~/.gemini/antigravity-cli/brain/<id>/.system_generated/logs/transcript_full.jsonl`.
- Keys: `quota_live_state_key()` → `'quota_live_state'` (164); `antigravity_quota_live_state_key()` → `'antigravity_quota_live_state'` (184).
- Hook-command builders (each `'php ' . csm_repo_root() . '/host-agent/hooks/...'`): `session_start_hook_command()` (274), `pre_tool_use_hook_command()` (284), `permission_request_hook_command()` (294), `user_prompt_submit_hook_command()` (304), `stop_hook_command()` (314), Antigravity variants (337–355), `quota_live_state_write_command()` (364), and `statusline_fallback_script_path()` (376).

**Design contract:** every default is environment-derived (`getmyuid()`, real `$HOME`, this file's own location) or empty — never a hardcoded path from any one machine. Empty means "not configured yet"; downstream setup steps treat it as such rather than silently using a wrong machine's path. This is intentional portability for open-sourcing (see the `No installer's own personal paths are hardcoded` note, lines 13–21).

## Major call sites

**Within this subsystem:** `agent.php:38` calls both dispatchers; the dispatchers' case bodies call the feature services (each owned by another subsystem). `Config.php` is read by essentially every `HostAgent\Services\*`/`HostAgent\Stores\*` class (cross-cutting).

**External — who reaches INTO this subsystem:**

- **Container web UI (primary consumer):** `App\AgentClient::agent_call()` (`src/lib/AgentClient.php:28`) opens the socket at `/run/csm-agent.sock` (or `CSM_AGENT_SOCKET`), writes `json_encode($request)`, shuts down the write side, reads the whole response, and `json_decode`'s it (lines 39–51). It decodes a "Cannot reach host agent" failure into `{'ok':false,'message':...}` (lines 32–37) and a non-JSON response into `{'ok':false,'message':'Malformed response from host agent'}` (lines 47–49). Controllers calling it: `DashboardController` (`list`, `check_session_hook`, `push_public_key`), `SessionController` (`session_detail`, `archived_session_detail`, `session_history`, `list_plan_files`, `send_message`, `set_mode`, `set_model`, `push_public_key`, …), `BrowseController` (`browse_dir`, `create_dir`), `QuotaController` (`quota`), `PushController` (`push_subscribe`, `push_unsubscribe`). All are owned by the web-UI subsystem; they are the only runtime callers of the dispatchers.
- **`docker-compose.yml`** bind-mounts the host socket at `/run/csm-agent.sock` for the container (via `CSM_AGENT_SOCKET_HOST`) and sets `CSM_AGENT_SOCKET` there — the socket is the one shared artifact across the two runtimes.
- **systemd units** reference `agent.php` (`csm-agent@.service` ExecStart, line 7), `push_trigger.php`, `antigravity_quota_poll.php`, and the `opencode serve` daemon — all rendered/installed by `install.sh`.

**What this subsystem depends on (reverse):** `agent.php` and `Push.php`/`Sessions.php` `require` the root `vendor/autoload.php` (Sessions.php:15, Push.php:14), so a missing vendor breaks the *entire* agent, not just the web-push feature (explicitly noted in `install.sh:9-16` and `agent.php`'s reliance on Push.php). The runtime depends on the presence of the `claude`/`agy`/`opencode` binaries (paths from Config) and on systemd socket activation being available.

## Tests

- **`tests/test_agent_client_protocol.php`** — end-to-end: starts `socket_harness.php` around the *real* `host-agent/agent.php` (`start_harness(['php', $agentPhp], $socketPath)`, line 23), then calls `AgentClient::agent_call()` against it. **Happy path:** `list` returns `ok` + empty `sessions` (28–31); `browse_dir` default path + hidden-dir sorting + `parent` (34–38); `create_dir` really creates on disk (48–51); `list_archived`/`archived_session_detail`/`archived_session_history` (71–79). **Sad path:** `browse_dir` to `/etc` rejected outside HOME_ROOT (41–42); `create_dir` to `/etc` rejected (55–56); `session_detail`/`session_history` for nonexistent session return `ok=false` (61–65); malformed JSON over a raw socket → `{'ok':false,'message':'Malformed request'}` (82–90); unreachable socket → `{'ok':false}` + "Cannot reach host agent" (93–96). Cleaned up in `finally` via `stop_harness` (98).
- **`tests/test_socket_harness.php`** — tests the harness's own `kill_stale_listener()` via real subprocesses. **Happy path:** a fresh socket path starts cleanly and the first instance is alive (83–85). **The fix (this is a live discovery, 2026-08-08):** starting a *second* harness on the same path kills the first instance's process (not just the socket file) — `!pid_alive(first)` and `second` still alive (90–96); the reused socket is connectable and actually echoes (106–114). Uses `pid_alive()` (line 31) that excludes zombie `/proc` state.
- **`tests/run.sh`** — the orchestrator (no assertions of its own). Guarantees: single-flight lock per checkout (95–100), refuses to run against real tmux/sidecar sockets (114–122), `trap cleanup EXIT` + `trap interrupt INT TERM` (190–191), `--bail`/`--cleanup`/`--replay`/`--no-browser`/`--browser`/`--headed` flag handling (49–80, 197–238).
- **Dispatcher wiring is covered in feature-test files** (each owned by its own subsystem): `test_push.php` exhaustively drives `dispatch_push_action()` incl. the fall-through-to-null for a non-push action (lines 629–651); `test_file_uploads.php` drives `dispatch_action()` for the four upload actions (lines 181–202); `test_sessions_lifecycle.php` exercises `create`/`kill`/`answer_prompt`; `test_quota.php`, `test_transcript.php`, `test_session_hook.php`, `test_opencode_spawn.php` (its own `create`-via-dispatch check, lines 143–150) all call the dispatchers. `test_ui_smoke.php` also mounts `agent.php` via a canned harness.

## Dependencies

- **Composer (root):** `minishlink/web-push ^9.0`, `league/plates ^3.6` (`composer.json`). Dual PSR-4: `"HostAgent\\": "host-agent/lib/"` and `"App\\": "src/lib/"` (lines 10–15). `web-push` is the agent's runtime dependency; `league/plates` is the web-UI's.
- **PHP runtime:** `stream_socket_*`, `proc_open`, `json_*`, `getenv`, `getmyuid` (core, no posix extension needed — see `Config::tmux_socket()` docblock).
- **Systemd (user units):** socket activation (`csm-agent.socket` `Accept=yes`), `EnvironmentFile=-<repo>/host-agent/.env`, `StandardInput/Output=socket`, `KillMode=process` (explained at `csm-agent@.service:10-17` — the tmux server it may fork must survive the per-connection instance exiting), timers via `OnBootSec`/`OnUnitActiveSec`.
- **External binaries (paths via Config):** `claude`, `agy` (Antigravity CLI), `opencode`; `tmux`; `systemctl --user`, `loginctl`, `getent`, `composer`, `sed` (install.sh).
- **The socket is the only resource shared across runtimes** — container `AgentClient` ↔ host `agent.php`.

## Data & schema

### Socket request / response shape

- **Request:** a JSON object with at least `{ "action": "<string>" }` plus action-specific fields. There is no envelope beyond `action`; fields are read directly by `$request['...']`. `action` is required (missing → `Malformed request` at `agent.php:29`).
- **Response:** always a JSON object, no envelope. Two universal keys: `ok` (bool) and, on failure, `message` (string). Success responses add feature keys. Framing: whole-object, one write, `stream_socket_shutdown(STREAM_SHUT_WR)` on the client side and `stream_get_contents` on both ends — no length prefix, no back-and-forth, connection closed per request.

### Config env vars & path defaults

From `.env.example`/`Config` defaults (var → default): `CLAUDE_BIN`→`''`, `ANTIGRAVITY_BIN`→`''`, `OPENCODE_BIN`→`''`, `WWW_ROOT`→`HOME_ROOT`, `HOME_ROOT`→`$HOME`, `TMUX_SOCKET`→`/tmp/tmux-<uid>/default`, `SIDECAR_DIR`→`/run/user/<uid>/csm-sessions`, `CLEANUP_THRESHOLD_SECONDS`→`43200`, `TMUX_PANE_WIDTH`→`200`, `TMUX_PANE_HEIGHT`→`150`, `PUSH_SQLITE_FILE` (via `push_sqlite_path()`)→`<repo>/host-agent/state/push.sqlite`, `SESSIONS_SQLITE_FILE`→`<sidecar_dir>/sessions.sqlite`, `OPENCODE_DB_PATH`→`~/.local/share/opencode/opencode.db`, `OPENCODE_SERVE_URL`→`http://localhost:4096`, `OPENCODE_PERMISSION_DIR`→`<sidecar_dir>/opencode-permissions`. Push-specific: `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY`/`VAPID_SUBJECT`, `PUSH_SUBSCRIPTIONS_FILE`/`PUSH_STATE_FILE`/`PUSH_QUOTA_STATE_FILE`/`PUSH_QUOTA_CHECK_STATUS_FILE`, `PUSH_MIN_WORKING_SECONDS_FOR_FINISH_NOTIFY`→`60`, `PUSH_QUOTA_NOTIFICATIONS_ENABLED`→`1`, `PUSH_QUOTA_NEAR_THRESHOLD_PCT`→`90`, `PUSH_TIMER_UNIT_NAME` (test-only). Legacy `CLAUDE_QUOTA_BIN`/`QUOTA_CACHE_FILE`/`QUOTA_CACHE_TTL_SECONDS`/`QUOTA_TIMEOUT_SECONDS` noted in `.env` as superseded (the `quota` action now reads `quota_live_state_key()`).

### SQLite data paths this runtime computes

The runtime itself holds no DB but exposes the paths the Stores use (owned by feature-store subsystems): `sessions_sqlite_path()` → ephemeral tmpfs `sessions.sqlite` (SidecarStore/SessionStatusStore/PendingToolStore, WAL — see `SqliteDb`); `push_sqlite_path()` → persistent `host-agent/state/push.sqlite` (PushSubscriptionStore/PushSessionStateStore/PushQuotaStateStore/GlobalStateStore); `opencode_db_path()` → the OpenCode TUI's own `opencode.db` (read-only by the agent).

### systemd units & placeholders

Unit templates → `install.sh` `render_unit()` substitutions: `@REPO_ROOT@`→repo root, `@PHP_BIN@`→`command -v php`, `@SOCKET_GROUP@`→`id -gn` (at `csm-agent.socket:7` `SocketGroup`), `@OPENCODE_BIN@`→`.env OPENCODE_BIN` else PATH, `@HOME@`→`$HOME`. `csm-agent.socket` listens on `%t/csm-agent.sock` (`SocketMode=0660`), `Accept=yes`. `csm-agent@.service` is the per-connection instance (`StandardInput/Output=socket`, `KillMode=process`). The two timers are `Type=oneshot` services fired on `OnBootSec`/`OnUnitActiveSec` (push: 10s; antigravity-quota: 60s). `opencode-serve.service` is `Type=simple`, `Restart=always`, `WorkingDirectory=@HOME@`, `ExecStart=@OPENCODE_BIN@ serve --hostname 0.0.0.0 --port 4096 --mdns`, with a PATH that includes `~/.local/bin`/`~/.npm-global/bin` (lines 13–21).

### install.sh behavior

Creates vendor (if missing, `composer install`), copies `.env.example`→`.env` (if missing), warns when `CLAUDE_BIN` is unset, renders + installs `csm-agent.socket`/`csm-agent@.service` and enables the socket, installs-but-does-not-enable the push and antigravity timers (deliberate opt-in, lines 77–104), enables `opencode-serve.service` + the CSM plugin only when `OPENCODE_BIN` is resolvable (lines 106–142), and reports lingering state (144–148).

## Conventions & quirks

- **Per-connection process:** systemd `Accept=yes` spawns a fresh `agent.php` per request; it reads one request, writes one response, exits. There is no long-lived listener in the agent's own code — systemd owns the socket lifecycle (`agent.php:5-11`). This is why raising `memory_limit` to 512M unconditionally is safe (single, short-lived process).
- **JSON framing, no length prefix:** the entire request is one `json_encode`d object; the client `stream_socket_shutdown(WR)` then `stream_get_contents()`; the agent just `fwrite(STDOUT, json_encode(...))`. Both sides plainly `json_decode`/`json_encode`; there is no length/type header (so relayed base64 uploads are fine, but the whole payload must fit in the read).
- **Malformed requests are handled, not crashes:** a non-array or action-less request still emits a well-formed `{'ok':false,'message':'Malformed request'}` with `exit(0)` (lines 29–32), so systemd never sees a failure and the client always gets parseable JSON.
- **`dispatch_push_action` fall-through via `null`:** the push dispatcher is tried first; any action it doesn't own returns `null` and `agent.php`'s `??` routes it to `dispatch_action()`. The two switches never enumerate each other's actions — documented rationale at `lib/Push.php:21-28`.
- **`ok` + `message` is the universal result contract:** services return `['ok' => true, ...]` or `['ok' => false, 'message' => ...]`; the dispatchers just wrap/forward. Note the odd shape `['ok' => true] + SessionService::list_all_sessions()` (Sessions.php:35) — list_all_sessions() itself returns `['sessions' => [...]]`, so the `+` union keeps both keys.
- **Defensive casts everywhere:** the dispatchers cast `$request` fields to `(string)`/`(int)`/`(bool)`/`is_array` at the boundary (e.g. `(int)($request['before'] ?? 0)`, `(bool)($request['enable_task_tools'] ?? false)`, `is_array(...) ? ... : []`). The web UI is trusted less than re-checked.
- **Config is env-derived, never machine-hardcoded:** defaults use `getmyuid()`, real `$HOME`, and `dirname(__DIR__, 3)` so the same checkout works in any clone/location (portability for open-sourcing).
- **`vendor/autoload.php` is mandatory for the whole agent, not just push:** `agent.php` requires `Push.php`, which requires the autoloader for its `minishlink/web-push` dep — so a missing vendor breaks *everything*, which `install.sh` tests for first.
- **Timers are opt-in by design; the socket and opencode-serve are enable-now:** `install.sh` won't silently start a recurring push/antigravity background service (no-op until keys/bin set), but `csm-agent.socket` and `opencode-serve.service` are enabled unconditionally when possible.
- **`KillMode=process`** on `csm-agent@.service` is critical: the per-connection agent may fork+detach a brand-new tmux server, and the unit's default `KillMode=control-group` would kill that server the moment the instance exits (which is immediately).
- **Untracked working-tree files:** `host-agent/systemd/opencode-serve.service` (in this glob, untracked) and `host-agent/opencode_diagnose.php` (untracked, owned by `prompt-interaction`) — the DETAILS here reflects the working tree, not an inventory-only view.

## Co-owned / cross-subsystem

- **`host-agent/lib/Services/Config.php`** is the most cross-cutting file in the repo: it is *titled* here (it's the runtime's env/path layer) but it is read by nearly every `HostAgent\Services\*` and `HostAgent\Stores\*` class. Its per-var docblocks delegate meaning to the owning service (e.g. `quota_live_state_key()` doc → `QuotaService`, `${hook}_command()` → the hook services, `opencode_*` → the OpenCode adapters).
- **Per-feature entry scripts are launched by frames owned here but are their own subsystem:** the systemd `csm-push-check.timer`/`csm-antigravity-quota-check.timer` (packaged by this subsystem) exec `host-agent/push_trigger.php` (push), `host-agent/antigravity_quota_poll.php` (antigravity-quota), and the `opencode-serve.service`/`csm-permissions.js` plugin wiring touches `host-agent/opencode-plugins/*` (opencode subsystem). None of these go through the dispatchers — they call services directly.
- **`tests/lib/cdp.php` and `tests/lib/replay_fixture.php`** live in the owned `tests/lib/` glob but are the browser-replay-diff feature's tooling (CDP client + replay fixture runner), used by `test_session_replay*.php`. They are recorded here only because they sit in a path this subsystem owns.
- **`host-agent/opencode_diagnose.php`** (untracked) belongs to `prompt-interaction` — referenced here only as a runtime-adjacent artifact, not owned.
- **The protocol's far end** — `App\AgentClient` in `src/lib/AgentClient.php` — is owned by the web-UI subsystem but is the only consumer of the dispatchers' action vocabulary; any change to a dispatch case is an interface change for it.
