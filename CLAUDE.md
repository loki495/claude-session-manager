# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A personal, single-user web UI for managing `cc-*` tmux sessions running
`claude` on this dev box — list sessions, see blocked prompts, answer them,
send messages, view transcripts, kill sessions. No database, no user
accounts; access control is the network binding (LAN-only), not a login.

## Commands

```bash
bash tests/run.sh          # run the whole test suite
bash tests/run.sh --bail   # stop at the first failing test file
```

No Composer test runner, no Pest, no build step for JS/CSS (plain files,
no bundler/npm — there is no `package.json`). `tests/run.sh` runs each
`tests/test_*.php` directly via the `php` CLI. Tests are self-isolating:
they point `TMUX_SOCKET`/`CLAUDE_BIN`/sidecar paths at fixtures
(`tests/.env.testing`), so they never touch the real tmux server or spawn
a real (billable) `claude` process, and `run.sh` always cleans up its
isolated tmux server + fixture processes on exit, failure, or interrupt.

To exercise a single area, run that one `tests/test_*.php` file directly
with `php` rather than the whole suite (check its own header comment for
what it covers and what fixtures it needs).

No PHPStan/Pint/Rector here — this is not a Laravel project.

The container never needs a rebuild for PHP/JS edits (`src/` is
bind-mounted); `docker compose up -d --build` is only needed if
`docker-compose.yml`'s inline Dockerfile itself changes. The host agent
(`host-agent/`) also runs directly off the checked-out repo path with no
restart needed per edit — each connection gets a fresh PHP process, spawned
by systemd socket activation.

## Architecture: two runtimes, one repo

The web UI (`src/`) runs in a Docker container. It **never touches tmux or
the host process table directly** — it can't; the container has no such
access. It only speaks a one-request-one-response JSON protocol over a UNIX
socket (`src/lib/AgentClient.php`) to a separate, host-native **agent**
(`host-agent/`, installed via `host-agent/install.sh`, run by systemd
socket activation — not containerized).

**Why the split exists**: tmux auto-spawns its server as a child of
whichever process first talks to an unstarted socket. If the container ever
became that first process, the tmux server (and every session in it) would
be born inside the container's own filesystem namespace, unreachable from
the host and pointing at paths that don't exist there. Keeping all tmux/
`/proc` access in a process that is *always* host-native, never
Docker-spawned, makes that impossible by construction — not by convention.

### Request flow

1. A browser hits an entry-point script directly under `src/` (`index.php`,
   `session.php`, `session_send.php`, ...). Each is a thin controller: call
   `AuthService` (CSRF/session), call `AgentClient::agent_call([...])` to
   talk to the agent, then hand the result to a `PageView`/`App\Views\*`
   class to render — no inline HTML in the controller files themselves.
2. `AgentClient` opens the UNIX socket, writes one JSON request, reads one
   JSON response. `agent.php` (host-agent's entry point) is a per-connection
   process spawned by systemd; it decodes the request, dispatches on
   `action`, and writes back one JSON response.
3. Two dispatchers on the host-agent side: `Push.php`'s
   `dispatch_push_action()` handles `push_*` actions, falling through
   (`null`) to `Sessions.php`'s `dispatch_action()` for everything else.
   Both are now thin switches — the real logic lives in
   `host-agent/lib/Services/*` (`SessionService`, `TmuxService`,
   `QuotaService`, `UploadService`, `HookService`,
   `TranscriptService`, `PromptParser`, `ProcessInspector`,
   `ProcessRunner`, `Config`, push-related services) and
   `host-agent/lib/Stores/*` (`SidecarStore`, `PendingToolStore`,
   `PushSubscriptionStore`, `PushSessionStateStore`) — all PSR-4 autoloaded
   under namespace `HostAgent\Services`/`HostAgent\Stores`.
4. On the container side, the equivalent split is `App\Services\AuthService`
   (CSRF/session) and `App\Views\*` (one render class per feature area —
   `TranscriptView`, `SessionRowView`, `BlockedPromptView`, `QuotaFooterView`,
   `HealthBoxView`, `PushNotifyView`, plus `PageView` for the two full-page
   templates). Views extend `App\Views\View`, which owns a shared
   `League\Plates` engine rooted at `src/partials/` — templates are grouped
   by feature (`partials/transcript/`, `partials/blocked-prompt/`,
   `partials/session-row/`, `partials/pages/`, ...), not one flat directory.

Both `App\` (→ `src/lib/`) and `HostAgent\` (→ `host-agent/lib/`) are
Composer PSR-4 autoloaded from the one root `composer.json` — `vendor/` is
bind-mounted into the container alongside `src/` so the same autoloader
works in both places.

### Two Claude Code hooks this app installs into `~/.claude/settings.json`

- **SessionStart** (`host-agent/hooks/session_start.php`) — Claude Code
  rotates to a new transcript-file UUID on `/clear`/`/compact`/`--resume`/
  `--fork-session` while staying in the same tmux pane. This hook rebinds
  the tracked session's sidecar to the new UUID every time it fires, or the
  app would keep reading an abandoned, no-longer-growing transcript file.
- **PreToolUse** (`host-agent/hooks/pre_tool_use.php`) — records the exact,
  untruncated `tool_name`/`tool_input` for a pending tool call straight from
  the hook's stdin, so a blocked permission prompt's preview doesn't depend
  on scraping a small, best-effort-rendered tmux pane. Never approves or
  denies anything — writes nothing to stdout, always exits 0.

Both are no-ops for a plain `claude` session started by hand outside this
app (they key off a `CSM_SESSION_NAME` tmux pane environment variable that
only `create_cc_session()`-spawned sessions have).

## Conventions worth knowing before editing

- **`src/js/*.js` is plain ES5** (`var`, `function`, no `const`/`let`,
  arrow functions, `Set`, or template literals) — no transpiler in this
  project, and mobile Safari compatibility has repeatedly been the reason
  (see the several iOS-specific comments throughout `session.js`). Match
  this style; don't introduce ES6+ syntax.
- Every request re-validates state fresh rather than trusting client input:
  a session name for `kill`, an option number for `answer_prompt`, etc. are
  all re-checked against a fresh listing/whitelist inside the same request.
  Follow this pattern for any new state-changing action.
- All tmux/process invocations use `proc_open()` with the command as an
  **array**, never a shell string — no shell metacharacter injection
  surface. Keep new host-agent commands in this form.
- Comments throughout this codebase frequently record a specific bug found
  live (often "found live: ...", "verified live YYYY-MM-DD ...") and the
  reasoning that led to the fix — read them before assuming something is
  over-engineered; the non-obvious behavior it's guarding against is
  usually explained right there.
- Branch model actually used here: work happens directly on `master`
  (pushed straight to `origin/master`); a `refactor/*` branch (sometimes in
  a second git worktree, e.g. `../claude-session-manager-refactor`) is only
  spun up for a large phased refactor and merged back when done. This repo
  does not use the generic global `master → local → feature` model.
