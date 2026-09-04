---
id: host-agent-runtime
based_on: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-26
---

# Host-agent runtime — audit

Scope: the host-native agent's frame — `agent.php` entry point, the two
dispatchers (`dispatch_action` / `dispatch_push_action`), `Config.php`, the
`install.sh` + systemd packaging, and the protocol/socket-harness tests.
Verified against the current working tree (HEAD is `44e4caa`, matching
`DETAILS.md`'s `last_scanned_commit`, but the working tree carries
uncommitted modifications that I read directly, not the committed snapshot).

## Verify-before-trust note

`DETAILS.md` is broadly accurate on the dispatcher inventory and the
dual-runtime split, but is **stale in two specific ways** worth knowing
before trusting it blindly:

1. It describes `dispatch_action()` as "37 cases" (line 65, and the "37+
   default" wording). The real file has **39** `case` statements
   (`host-agent/lib/Sessions.php:34-206`). Cosmetic, but the count is
   wrong.
2. Its "Config env vars & path defaults" section (lines 199) lists the
   push/state **file** env vars (`PUSH_SUBSCRIPTIONS_FILE`,
   `PUSH_STATE_FILE`, `PUSH_QUOTA_STATE_FILE`, `PUSH_QUOTA_CHECK_STATUS_FILE`)
   as live without flagging that the implementation has moved to SQLite /
   `GlobalStateStore`. Those vars are **dead** (see Finding 1). This is the
   one place the map misleads.

I did not re-run the scout; the delta is confined to the `.env.example` /
Config storage-env-var surface and a case count, so the rest of the
inventory can be trusted as-is.

---

## Findings

### 1. `.env.example` (and the live `.env`) document env vars the implementation no longer reads — storage drifted to SQLite/GlobalStateStore, docs did not follow

- **Recommendation:** `refactor` (doc/config hygiene) — it misleads a
  fresh install, which is exactly the portability audience the repo is
  preparing for.
- **Severity / priority:** `medium` / **1**
- **Confidence:** `high`

**Evidence** (verified by grep across `host-agent/` and `src/`):

- `host-agent/.env.example:74` — `#QUOTA_LIVE_STATE_FILE=`. Not read
  anywhere.
- `host-agent/.env.example:91-142` — `#PUSH_SUBSCRIPTIONS_FILE=`,
  `#PUSH_STATE_FILE=`, `#PUSH_QUOTA_STATE_FILE=`, `#PUSH_QUOTA_CHECK_STATUS_FILE=`,
  and a comment at `:136-142` that points the reader at
  `PushDeliveryService::push_check_status_file()` for
  `PUSH_CHECK_STATUS_FILE`.
- `host-agent/.env:45-56` — live `CLAUDE_QUOTA_BIN`, `QUOTA_CACHE_FILE`,
  `QUOTA_CACHE_TTL_SECONDS`, `QUOTA_TIMEOUT_SECONDS`; `:72`
  `PUSH_SUBSCRIPTIONS_FILE=...`; `:80` `PUSH_STATE_FILE=...`.
- **None** of those strings is read in any `host-agent/**/*.php` or
  `src/**/*.php` file (grep for each returns NONE). `PushDeliveryService::
  push_check_status_file()` **does not exist** — grep for
  `push_check_status_file|PUSH_CHECK_STATUS` in `host-agent/` returns
  nothing.

**Current complexity / invalid state:** The actual storage mechanism is
`Config::push_sqlite_path()` (`host-agent/lib/Services/Config.php:219-222`,
env `PUSH_SQLITE_FILE`, default `<repo>/host-agent/state/push.sqlite`) and
`Config::quota_live_state_key()` (`Config.php:164-167`, a
`GlobalStateStore` key). Only `PUSH_SQLITE_FILE` and `SESSIONS_SQLITE_FILE`
are read from the environment. `install.sh:18-22` copies `.env.example` →
`.env` on first install, so a new user following the comments will set a
batch of variables that do nothing, and reasonably conclude push is
misconfigured.

**Proposed representation:** `host-agent/.env.example` should document only
the vars `Config::csm_config()` actually consumes (`CLAUDE_BIN`,
`ANTIGRAVITY_BIN`, `OPENCODE_BIN`, `WWW_ROOT`, `HOME_ROOT`, `TMUX_SOCKET`,
`SIDECAR_DIR`, `CLEANUP_THRESHOLD_SECONDS`, `TMUX_PANE_WIDTH/HEIGHT`,
`VAPID_*`, `PUSH_MIN_WORKING_SECONDS_FOR_FINISH_NOTIFY`,
`PUSH_QUOTA_NOTIFICATIONS_ENABLED`, `PUSH_QUOTA_NEAR_THRESHOLD_PCT`,
`PUSH_SQLITE_FILE`, `SESSIONS_SQLITE_FILE`, `CSM_REPO_ROOT`, `OPENCODE_*`),
and drop the file-based push/quota vars plus the `push_check_status_file()`
aside. Prune the same dead vars from the live `host-agent/.env`.

**Smallest credible scope:** `host-agent/.env.example` (rewrite the var
list), `host-agent/.env` (remove dead lines), and a matching paragraph in
`DETAILS.md`'s Config section. No code change needed.

**Regression risks / migration:** None at runtime — the vars are already
unread. The only risk is deleting a var someone still believes is binding;
mitigate by noting SQLite is now the home. The stale `*.json` state files
under `host-agent/state/` are harmless leftovers.

**Validation:** Existing tests don't cover `.env.example` (it's template
text). Add nothing; this is documentation. Sufficient validation is a
manual re-read plus a `rg` to confirm no code path reads a removed var.

---

### 2. `agent.php` reads the request with no read timeout and no size cap — a connecting-but-never-sending client wedges a per-connection process forever

- **Recommendation:** `fix` (hardening) — the audit's protocol-robustness
  lens explicitly targets this; systemd gives you no timeout for free.
- **Severity / priority:** `medium` / **2**
- **Confidence:** `high`

**Evidence:**

- `host-agent/agent.php:26` `$input = stream_get_contents(STDIN);` — no
  `stream_set_timeout()`, no length limit, no `json_last_error()` check on
  line 27.
- `host-agent/systemd/csm-agent@.service` — `StandardInput=socket`,
  `KillMode=process`, one instance per accepted connection, spawned fresh
  per request. There is no accept-side or read-side deadline anywhere.

**Current complexity / invalid state:** `stream_get_contents(STDIN)` blocks
until the client shuts down the write side. `AgentClient::agent_call()`
always does (`src/lib/AgentClient.php:40`), so happy-path is fine. But a
client that connects, sends nothing, and never closes write (a stray
port-scanner with group access to the 0660 socket, or a wedged agent
consumer) holds a `csm-agent@.service` instance indefinitely; with
`KillMode=process` it even survives the unit being stopped. Every such
connection adds a process and (after `agent.php:21` raises it) a 512M
`memory_limit` allotment. The stdout writes at `:30`/`:40` likewise ignore
a failed `fwrite`, so a client that disappears mid-read just produces a PHP
notice to journal with no user-visible signal.

**Proposed representation:** Set `stream_set_timeout(STDIN, 5)` before the
read, then treat `stream_get_meta_data(STDIN)['timed_out']` / an empty read
(or `json_last_error() !== JSON_ERROR_NONE`) as `Malformed request` rather
than a hang. Optionally cap the input length (e.g. `stream_get_contents(STDIN)
` is fine for the 512M headroom, but a fixed ceiling on base64 upload size
is the container's job to enforce; at minimum the timeout is the real
fix).

**Smallest credible scope:** `host-agent/agent.php` only (a few lines).
The malformed-request contract it already implements (`agent.php:29-32`) is
the natural landing spot.

**Regression risks / migration:** A 5s read timeout is far above the
agent's real work window (sub-second service calls), so no normal request
is affected. The existing malformed-request test at
`tests/test_agent_client_protocol.php:82-90` must still pass (it already
shuts down WR before the agent reads, so no behavior change).

**Validation:** `tests/test_agent_client_protocol.php:82-90` covers the
malformed-JSON path. Add a sad-path subtest: connect, write nothing, hold
the socket open ~6s, assert the agent responds `Malformed request` (or the
connection is closed) rather than hanging. This is a new-sad-path test to
add — do not modify the existing malformed test.

---

### 3. Unquoted `csm_repo_root()` in every `*_command()` builder breaks any clone path containing a space

- **Recommendation:** `refactor` (root-cause the interpolation);
  portability is an explicit repo convention ("lean portable by default").
- **Severity / priority:** `medium` / **3**
- **Confidence:** `high`

**Evidence:**

- `host-agent/lib/Services/Config.php:274-317` — `session_start_hook_command()`,
  `pre_tool_use_hook_command()`, `permission_request_hook_command()`,
  `user_prompt_submit_hook_command()`, `stop_hook_command()` all
  `return 'php ' . self::csm_repo_root() . '/host-agent/hooks/...';`.
- `Config.php:337-367` — same pattern for the Antigravity hook commands and
  `quota_live_state_write_command()`.
- These strings are consumed as **shell command lines**: the statusline
  path is interpolated into `/bin/sh` at
  `StatuslineMarkerService.php:481`
  (`... | ' . Config::quota_live_state_write_command() . ' >/dev/null 2>&1`)
  and the hook `command` values land in `~/.claude/settings.json`, which
  Claude Code runs via a shell.

**Current complexity / invalid state:** `csm_repo_root()` (`Config.php:258-261`)
defaults to `dirname(__DIR__, 3)` — correct and environment-derived, but it
is textually spliced into a shell string with **no quoting**. The current
deployment path `~/www/claude-session-manager` has no space, so
it works today; any future clone/packaging path with a space turns
`php /some path/host-agent/hooks/x.php` into a split command that silently
fails. Because the fallback statusline script and the hook install are
silent-on-failure by design, the breakage would be invisible until the
feature stops working.

**Proposed representation:** Either `escapeshellarg()` the path when
building the command string (`'php ' . escapeshellarg(self::csm_repo_root()
. '/host-agent/hooks/x.php')`), or — cleaner — have `Config` return a
`[PHP_BIN, scriptPath]` pair and let the few consumers that build shell
lines quote the individual pieces. Minimal credible change is the
`escapeshellarg()` wrap in the builders; it introduces no API change.

**Smallest credible scope:** `host-agent/lib/Services/Config.php` (the
`*_command()` builders). No consumer signature changes.

**Regression risks / migration:** `escapeshellarg()` on a path with no
special chars is a no-op, so the current deployment is unaffected.
`install.sh` hook/substitution paths and the `StatuslineMarkerService`
call already quote the surrounding pipeline pieces.

**Validation:** No test asserts the exact hook command string today.
Add/confirm a sad-path test that `quota_live_state_write_command()` /
`session_start_hook_command()` survive a `csm_repo_root()` containing a
space (a unit assertion on `Config`, or an install into a temp path with a
space). This is new coverage to add.

---

### 4. `json_encode` of the response can return `false` on invalid UTF-8 → empty response → client's generic "Malformed response from host agent"

- **Recommendation:** `tweak` — cheap, removes a silent-failure mode.
- **Severity / priority:** `medium` / **4**
- **Confidence:** `medium`

**Evidence:**

- `host-agent/agent.php:30` and `:40` — `fwrite(STDOUT, json_encode(...))`.
- `json_encode` returns `false` (not a string) when the value contains
  invalid UTF-8. `fwrite(STDOUT, false)` writes nothing.
- On the client, `src/lib/AgentClient.php:42-48` — `stream_get_contents`
  returns `''`, `json_decode('') === null`, → `{'ok':false,'message':
  'Malformed response from host agent'}`.

**Current complexity / invalid state:** A service that returns a dirty
byte (a read transcript that isn't cleanly UTF-8, a `read_uploaded_file`
of a binary payload relayed as base64 should be ASCII but a real transcript
could carry a stray byte) produces an empty socket response and a
generic client error with no upstream signal on the agent side — the agent
thinks it succeeded, the user sees "Malformed response".

**Proposed representation:** `json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE)`
and, where a distinct error is warranted, a `json_last_error()` guard that
falls back to `{'ok':false,'message':'Encoding error'}`. `JSON_INVALID_UTF8_SUBSTITUTE`
alone is the minimal fix.

**Smallest credible scope:** `host-agent/agent.php` (both write sites).

**Regression risks / migration:** Substituting invalid bytes changes
decoded content only for malformed input that currently yields an empty
response — strictly an improvement. No consumer contract change.

**Validation:** `tests/test_agent_client_protocol.php` already covers the
happy path. Add a sad-path subtest that a service response with a non-UTF-8
byte still yields a parseable JSON response (not empty). New coverage.

---

### 5. `tests/run.sh` `rm -rf "$(dirname "$TMUX_SOCKET")"` is unguarded — catastrophic if it ever runs with an empty value

- **Recommendation:** `fix` — defense-in-depth; the blast radius is
  disproportionate to the one-line change.
- **Severity / priority:** `low` / **5**
- **Confidence:** `high`

**Evidence:**

- `tests/run.sh:158` (in the `--cleanup` path) and `:176` (in `cleanup()`)
  both run `rm -rf "$(dirname "$TMUX_SOCKET")"` with **no** surrounding
  guard.
- Every neighboring destructive op is guarded by
  `[ -n "${TMUX_SOCKET:-}" ] && [ "$TMUX_SOCKET" != "$REAL_TMUX_SOCKET" ]`
  — `tmux kill-server` at `:166`, `rm -rf "$SIDECAR_DIR"` at `:172` — but
  this one is not.

**Current complexity / invalid state:** Today it is safe only by ordering:
the real-socket/empty checks at `tests/run.sh:114-122` abort before the traps
at `:190-191` are registered (and the suite is `set -u`). If `TMUX_SOCKET`
were ever empty or unset at this point, `dirname ""` → `.` and
`rm -rf .` deletes the current working directory (the repo). A single
future edit that reorders the guards, or that reuses `cleanup()` from a
path where the guard hasn't run, turns a tidy-up into a repo deletion.

**Proposed representation:** `[ -n "${TMUX_SOCKET:-}" ] && [ "$TMUX_SOCKET" != "$REAL_TMUX_SOCKET" ] && rm -rf "$(dirname "$TMUX_SOCKET")"` — the same predicate the two sibling lines use.

**Smallest credible scope:** `tests/run.sh:158` and `:176`.

**Regression risks / migration:** None — the guard is a subset of the
conditions already required for the surrounding cleanup.

**Validation:** `test_socket_harness.php` covers the harness's ownership
cleanup, not `run.sh`'s trap. Manual coverage only; the change is a no-op
under existing valid `.env.testing`.

---

## What's done well

- **Zero contract drift.** Every action the container actually sends — I
  cross-checked all 45 distinct `'action' => ...` strings in
  `src/lib/Controllers/*` against the two dispatchers — has a handler:
  all 39 `dispatch_action()` cases and all 6 `dispatch_push_action()`
  cases are reachable, and no controller action falls through. The precise
  reason there's no separate action registry: controllers are the *only*
  producer of actions, so the "route table vs dispatcher" drift the audit
  lens worries about can't exist.
- **The `??` fall-through contract is correct.** `agent.php:38`
  `dispatch_push_action($request) ?? dispatch_action($request)` relies on
  the push dispatcher returning `null` (never a falsy/empty array) for
  non-push actions — and it does (`Push.php:65`). PHP's `??` is null-only,
  so this composes cleanly.
- **Defensive boundary casts everywhere.** `Sessions.php` casts
  `$request` fields to `(string)`/`(int)`/`(bool)`/`is_array` before
  passing to services (e.g. `Sessions.php:48-53`, `:99-107`, `:134-145`),
  matching the repo's "re-check fresh, don't trust client input"
  convention.
- **Malformed requests are handled, never crash.** `agent.php:29-32`
  emits `{'ok':false,'message':'Malformed request'}` with `exit(0)`, so
  systemd never sees a failure and the client always gets JSON. The
  sadness tests at `tests/test_agent_client_protocol.php:82-96` cover both
  malformed input and an unreachable socket.
- **Isolation is genuinely preserved.** `tests/run.sh:114-122` refuses to
  touch the real tmux socket / sidecar dir, `tests/.env.testing` points
  everything at fixtures including a fake `claude`/`agy`/`opencode`, and
  the test that spawns the *real* `agent.php` never creates a tmux session
  itself (`test_agent_client_protocol.php` does only `list`/`browse_dir`/
  `session_detail` on a fresh isolated socket). The `kill_stale_listener()`
  safeguard is well-reasoned (the `/proc/net/unix` inode-vs-filesystem
  inode trap and the zombie-aware `pid_alive()` are documented live
  discoveries, not guesses).
- **The two-runtime invariant is structurally sound.** `agent.php` and both
  dispatchers touch only host paths/processes; `Config` computes paths
  from `getmyuid()`/`$HOME`/`dirname(__DIR__,3)`, never a container path.
  Nothing in this subsystem reaches into the container, and the container
  reaches the agent only through the single bind-mounted socket
  (`docker-compose.yml:39,65` → `AgentClient::agent_call()`).

## Cross-cutting observations (not owned here — described, not solved)

- **`host-agent/.env` and `host-agent/.env.example` vs the quota subsystem**
  (touches `quota` subsystem / `QuotaService`): the live `.env` still
  carries `CLAUDE_QUOTA_BIN`/`QUOTA_CACHE_FILE`/`QUOTA_CACHE_TTL_SECONDS`/
  `QUOTA_TIMEOUT_SECONDS` (`host-agent/.env:45-56`) and the old
  `QUOTA_LIVE_STATE_FILE` is referenced in `.env.example:74`. The `quota`
  action's source is now `Config::quota_live_state_key()` /
  `GlobalStateStore` (`Config.php:164-167`), not a CLI scrape or file.
  Should be reconciled with `QuotaService`'s own stale-env handling.
- **`quota_live_state_write.php` path-traversal shape** (touches
  `antigravity-quota`/`statusline`): `Config::antigravity_transcript_path()`
  (`Config.php:147-150`) interpolates a caller-supplied `$conversationId`
  into a filesystem path with no validation; the caller
  (`AntigravityTranscriptService`) validates UUID shape per its own
  docblock, but the path builder is the security-relevant seam. Worth a
  defensive `basename()`/pattern check at the `Config` boundary.
- **`opencode-serve.service` and `host-agent/opencode_diagnose.php` are
  untracked** (confirmed: `git status --short` shows `open-code-serve.service`
  and `opencode_diagnose.php` as `??`). `host-agent/systemd/opencode-serve.service`
  is referenced by `install.sh:113-116` and enabled on install, so it must
  be committed before an open-source publish; `opencode_diagnose.php` is
  owned by `prompt-interaction` per `DETAILS.md`.

## Out of scope

- Feature services the dispatchers call (`SessionService`,
  `SessionLifecycleService`, `PushDeliveryService`, `QuotaService`, etc.) and
  the `Stores/*` — each owns its own subsystem.
- Per-feature entry scripts not residing in this frame: `push_trigger.php`,
  `antigravity_quota_poll.php`, `opencode_diagnose.php` (report-only).
- `src/lib/AgentClient.php` and the container controllers/views/`routes.php`
  (web-UI subsystem) — referenced only as the protocol's far end.
- The `host-agent/hooks/*` and `host-agent/opencode-plugins/*` subsystems.
- `docker-compose.yml` socket/`CSM_AGENT_SOCKET` wiring (web-UI / deploy).
