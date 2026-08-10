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

The container never needs a rebuild for PHP/JS edits (`src/`, `public/`,
and `vendor/` are all bind-mounted); `docker compose up -d --build` is
only needed if `docker-compose.yml`'s inline Dockerfile itself changes
(docroot, CMD, PHP extensions, etc.). The host agent (`host-agent/`) also
runs directly off the checked-out repo path with no restart needed per
edit — each connection gets a fresh PHP process, spawned by systemd
socket activation.

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

1. `public/` is the document root - every request goes through
   `public/index.php`, the one front controller (standard Laravel/Symfony/
   Slim-style layout, not something tied to `php -S` specifically - it also
   works as a `php -S` router-script argument, which is how the Docker
   container actually runs it today; `public/.htaccess` covers Apache,
   nginx needs an equivalent `try_files` directive in its own config).
   `public/js/*.js` and `public/sw.js` are the only real static files there
   - `index.php` returns `false` for those two patterns and lets whatever's
   in front serve them directly.
2. `index.php` matches the request against `App\Http\Router`
   (`src/lib/Http/Router.php`, loaded via `src/routes.php`) - a
   deliberately simple exact-path matcher, no groups/middleware/path
   parameters yet. A match instantiates the mapped `App\Controllers\*`
   class (`src/lib/Controllers/`, one class per feature area -
   `DashboardController`, `SessionController`, `BrowseController`,
   `UploadController`, `PushController`, `QuotaController`) and calls the
   mapped method; no match is a hard 404. Every controller method is a
   thin action: call `AuthService` (CSRF/session - via one of
   `App\Controllers\Controller`'s two shared guard helpers,
   `require_post_json()`/`start_readonly_json()`, for everything except
   the two full-page renders and `BrowseController`, which have their own
   reasons not to), call `AgentClient::agent_call([...])` to talk to the
   agent, then hand the result to a `PageView`/`App\Views\*` class to
   render - no inline HTML in the controllers themselves.
3. `AgentClient` opens the UNIX socket, writes one JSON request, reads one
   JSON response. `agent.php` (host-agent's entry point) is a per-connection
   process spawned by systemd; it decodes the request, dispatches on
   `action`, and writes back one JSON response.
4. Two dispatchers on the host-agent side: `Push.php`'s
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
5. `App\Views\*` (one render class per feature area — `TranscriptView`,
   `SessionRowView`, `BlockedPromptView`, `QuotaFooterView`,
   `HealthBoxView`, `PushNotifyView`, plus `PageView` for the two full-page
   templates) is what controllers hand their `AgentClient` result to.
   Views extend `App\Views\View`, which owns a shared `League\Plates`
   engine rooted at `src/partials/` — templates are grouped by feature
   (`partials/transcript/`, `partials/blocked-prompt/`,
   `partials/session-row/`, `partials/pages/`, ...), not one flat directory.

Both `App\` (→ `src/lib/`) and `HostAgent\` (→ `host-agent/lib/`) are
Composer PSR-4 autoloaded from the one root `composer.json` — `public/`,
`src/`, and `vendor/` are bind-mounted into the container as siblings
(mirroring the host's own repo-root layout) so the same autoloader works
in both places.

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

- **`public/js/*.js` is plain ES5** (`var`, `function`, no `const`/`let`,
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
- Every transcript block (text/plan/tool_use/tool_result) carries two
  cross-cutting attributes in both its PHP render (`transcript/block.php`)
  and its JS poll-time mirror (`renderBlock()` in `session.js`) — keep
  both in sync when touching either:
  - `data-line="<line>"` — the block's own raw JSONL line number, used to
    scroll to and highlight a search result (see "Session/dashboard
    content search" in CONTRIBUTING.md).
  - A `.copy-btn` + `.copy-source` pair (wrapped in `.copy-block`, or the
    block's own `<pre>` for a `<details>`-expanded collapsible block) —
    the shared copy-to-clipboard affordance (`copyTextToClipboard()` in
    `common.js`). A new block kind needs both attached the same way, or it
    silently loses search-jump and copy support that every other kind has.
- Branch model actually used here: work happens directly on `master`
  (pushed straight to `origin/master`) for anything normal - a new feature,
  a bug fix, a doc update. A separate `refactor/*` or `feature/*` branch
  (sometimes in a second git worktree, e.g.
  `../claude-session-manager-refactor`) is reserved for changes big/risky
  enough that the live site could stop loading partway through - a
  multi-phase structural refactor (the Plates-templating and
  front-controller/router migrations were the two so far), not a small
  self-contained feature. Once merged, delete the branch (both local and
  remote) - don't let finished branches pile up. This repo does not use the
  generic global `master → local → feature` model.
- Andres may open-source this repo. When a design choice could go either
  toward "simplest for the one deployment this runs today" or "the
  standard/portable shape," default to the portable one, even if it's a
  bit more setup now. Concrete precedent: `public/index.php` is a
  conventional Laravel/Symfony/Slim-style front controller rather than a
  `php -S`-router-script-only trick, even though `php -S` is the only
  server this app actually runs on today - it still works as a `php -S`
  router-script argument, but also works unmodified behind Apache
  (`public/.htaccess`, added preemptively) or nginx (documented `try_files`
  equivalent in the README). Flag the trade-off if you're unsure it's
  worth the extra effort in a given case, but lean portable by default.
