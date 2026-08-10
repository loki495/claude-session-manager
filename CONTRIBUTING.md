# Contributing

Thanks for taking a look. This started as, and still primarily is, a
personal tool - see the note at the top of [README.md](README.md) about
scope. Issues and PRs are welcome; this doc is the architecture/workflow
reference for working on the code itself. For "how do I install and run
this," see the README instead.

## Architecture: container + host-native agent

The web UI runs in a Docker container, but **it never touches tmux or the
host process table directly.** It only knows how to speak a tiny
request/response protocol over a UNIX socket. A separate, host-native
**agent** (`host-agent/`, installed directly on the host via
`host-agent/install.sh` - not containerized) owns tmux, `/proc` scanning,
and everything else that has to run in the host's own namespace.

**Why the split exists:** tmux has a client/server model where the *first*
process to talk to a not-yet-running tmux socket auto-spawns the server as
its own child. Early on this app ran tmux directly from inside the
container, and once all `cc-*` sessions were killed and the host's tmux
server exited, the *next* "New Session" click caused the **container** to
become the one auto-spawning the tmux server - inside the container's own
filesystem namespace, where the real `claude` binary and project
directories don't exist. `tmux new-session` returns success before
checking whether the pane's command actually stayed running, so the UI
reported "Created session cc-...", the pane died instantly, and the session
never existed anywhere reachable from the host. Moving all tmux/process
control into a small agent that is *always* a genuine host process
(invoked by systemd, not Docker) fixes this at the root: the container is
now incapable of ever being the one to start a tmux server.

As a side benefit, the agent also scans `/proc` directly for every real
`claude` process on the host (matching `argv[0]` against the configured
`CLAUDE_BIN` - not `/proc/pid/exe`, which resolves to a versioned binary
under `~/.local/share/claude/versions/*` and changes on every update).
This finds Claude processes regardless of how they were started, not just
ones matching the `cc-*` tmux naming convention. Ones running inside a
tracked `cc-*` tmux session are shown as normal, killable session cards;
any other real `claude` process found on the host (started by hand in a
plain terminal, for example) is shown read-only, for visibility, with no
Kill button by default - killing those was deliberately left out of scope
to avoid a second, SIGTERM-based kill path alongside `tmux kill-session`
(though "Take over" can adopt one into a tracked tmux session instead).

## How commands are actually run

All tmux invocations are built in `host-agent/lib/Services/TmuxService.php`
and run via `host-agent/lib/Services/ProcessRunner.php`'s `proc_open()`
with the command given as an **array**, e.g.
`['tmux', '-S', $socket, 'kill-session', '-t', $name]`. That form never
goes through `/bin/sh`, so there's no shell metacharacter injection surface
at all. Every session name used for `kill-session` is re-validated against
a fresh whitelist inside the same request, regardless.

The container (`src/lib/AgentClient.php`) never runs a shell command at
all - it only opens a UNIX socket and exchanges one JSON request/response
pair with the agent.

## Usage quota footer

The sticky footer shows session/weekly usage percentages sourced from a
`claude-quota`-shaped script (not part of this repo - see `CLAUDE_QUOTA_BIN`
in the README's configuration table) that scrapes Claude Code's own
`/usage` panel via a detached `screen` session. That scrape is slow
(10-40s, it drives a real TUI), so it's never run inline while a request
is waiting:

- `GET /quota.php` (polled by the footer's `fetch()`, ~60s apart) always
  returns immediately with whatever's in `host-agent`'s cache
  (`QUOTA_CACHE_FILE`), marked `cached`/`stale` as appropriate.
- If the cache is missing or older than `QUOTA_CACHE_TTL_SECONDS`, the
  agent fires a **fully detached** background process
  (`host-agent/quota_refresh.php`) that runs the scrape and writes the
  result to the cache itself, then returns the (possibly stale) cache
  immediately rather than waiting on it. The footer shows "refreshing in
  background…" while this is happening.
- A marker file (`QUOTA_CACHE_FILE .refreshing`) prevents duplicate
  scrapes - e.g. two browser tabs polling at once, or a page reload
  landing mid-refresh. It's claimed with an atomic exclusive file create
  (`fopen(..., 'x')`), not a plain "check then write", specifically so two
  near-simultaneous requests can't both decide "nothing in flight" and
  both spawn a scrape.
- Each bucket's `resets` text (whatever Claude Code's own panel prints,
  e.g. `"3pm (America/Los_Angeles)"`) is parsed into an absolute
  `resets_at` unix timestamp (`parse_resets_at()` in `QuotaService.php`)
  before caching, so the frontend can render a live countdown instead of a
  string that goes stale the moment it's rendered.
- If `CLAUDE_QUOTA_BIN` is unset, missing, or the scrape fails and there's
  no prior cache to fall back to, the footer just shows "Quota
  unavailable" - this is a nice-to-have, never a hard dependency for the
  rest of the app.

## Session/dashboard content search

Broader than the archived list's own client-side title/name filter
(`filterArchivedRows()` in `index.js`, a plain substring match against
each row's already-rendered text) - this searches actual message
content, server-side, since most of it was never loaded into any page's
DOM in the first place.

- `TranscriptService::search_transcript_file()` walks a transcript's raw
  JSONL lines newest-first, two-stage matching per candidate line: a
  cheap `stripos()` against the RAW line first (skips `json_decode()` +
  `parse_transcript_line()` entirely for the overwhelming majority of
  lines in a real transcript - verified against a real ~50MB/~10k-line
  session), then a second `stripos()` against the PARSED block text once
  a line clears the first check. That second check matters: a raw-line
  hit can land inside metadata this app never renders as content at all
  (a `tool_use_id`, an internal param never surfaced as block text) -
  without it, a "match" could point at nothing findable once the user
  actually clicks through to it.
- `SessionService::search_transcripts()` (dashboard-wide, `/search_sessions.php`)
  runs that across every known transcript, live and archived alike, and
  cross-references the live-tracked-session list (same reconciliation
  `list_archived_dashboard()` already does) so each result's own
  `session_name` is set only when something currently tracks it live -
  that's what tells the client which page to link to.
  `SessionService::session_transcript_search()`/
  `archived_session_transcript_search()` (`/session_search.php`,
  `/archived_session_search.php`) do the same for one session's own
  transcript.
- Clicking a result is a **full page navigation**, not an in-place fetch:
  `session.php`/`archived_session.php?...&jump_line=<line>`
  (`SessionController::show()`/`showArchived()`) reuses the *existing*
  `before` pagination cursor - `before = jumpLine + 1` is exactly "the
  page whose last entry is `jumpLine` itself," the same semantics
  `TranscriptService::read_transcript_page()` already had - rather than
  inventing a second loading path. A "Showing a search result / Back to
  latest" banner is the way back to the normal live-tail view. Anything
  *newer* than the jump point is deliberately not backfilled on a live
  session's jump landing - the regular live poll's own forward (`after`)
  fetch picks it back up as ordinary "new content" on its very next tick,
  so there's nothing extra to wire; an archived (dormant, non-polling)
  session has no such follow-up, hence "Back to latest" being the only
  way forward there.
- Every rendered transcript block carries `data-line="<line>"` (both the
  PHP render and `session.js`'s own poll-time mirror - see CLAUDE.md's
  "Conventions worth knowing" for the exact pairing requirement), which
  is how a jump target is found regardless of whether it ended up
  standalone or swept into a grouped "N tool calls" pair.
- **Found live 2026-08-09**: `Element.scrollIntoView()` silently no-op'd
  on this page in a real headless-Chrome automation context used to
  verify the jump-to-match flow end to end - confirmed by calling
  `window.scrollTo()` directly in that same context immediately
  afterward, which worked. The jump-scroll in both `session.js` and
  `archived-session.js` uses an explicit `window.scrollTo()` computed
  from the target's own `getBoundingClientRect()` instead, sidestepping
  whatever `scrollIntoView()`-specific behavior caused this rather than
  risking the same silent no-op for a real user on some browser/context.

## File structure

```
claude-session-manager/
├── docker-compose.yml     # container: includes the Dockerfile inline (dockerfile_inline)
├── composer.json           # one root autoloader: App\ -> src/lib/, HostAgent\ -> host-agent/lib/
├── .env.example           # copy to .env, fill in real values, never commit .env
├── .gitignore
├── README.md
├── CONTRIBUTING.md         # this file
├── CLAUDE.md               # architecture/conventions guidance for Claude Code sessions
├── public/                 # the document root - the ONLY directory a web server ever serves from
│   ├── index.php            # the one front controller - bootstraps, loads src/routes.php, dispatches.
│   │                         # Also directly usable as a `php -S` router-script argument (how Docker
│   │                         # runs it - see docker-compose.yml); public/.htaccess covers Apache,
│   │                         # nginx needs an equivalent try_files directive in its own config.
│   ├── .htaccess             # standard front-controller rewrite rules (inert unless served via Apache)
│   └── js/*.js, sw.js        # the only real static files - everything else funnels through index.php
├── src/                    # application code - never directly web-accessible (see public/ above)
│   ├── routes.php           # route table: App\Http\Router::get()/post() registrations, one per URL
│   └── lib/
│       ├── AgentClient.php  # App\AgentClient - talks to the host agent over a UNIX socket
│       ├── Assets.php       # App\Assets - cache-busting for public/js/*.js via a ?v=<mtime> query string
│       ├── Http/
│       │   └── Router.php    # App\Http\Router - deliberately simple exact-path matcher, no groups/
│       │                     # middleware/path params yet; match() is a pure lookup, no output of its own
│       ├── Controllers/      # App\Controllers\* - one class per feature area (DashboardController,
│       │                     # SessionController, BrowseController, UploadController, PushController,
│       │                     # QuotaController), each method a thin action: an AuthService guard (via
│       │                     # Controller's require_post_json()/start_readonly_json() helpers, for
│       │                     # everything except the two full-page renders), an AgentClient::agent_call(),
│       │                     # then handing the result to a View to render
│       ├── Services/
│       │   └── AuthService.php  # same-origin check + CSRF token + session start, shared by every controller
│       └── Views/           # App\Views\* - one render class per feature area (TranscriptView,
│                             # SessionRowView, BlockedPromptView, QuotaFooterView, HealthBoxView,
│                             # PushNotifyView, plus PageView for the two full-page templates) -
│                             # each a thin self::render('template', [...]) wrapper, no HTML of its
│                             # own; View.php owns the shared League\Plates engine they all extend
├── src/partials/            # every template Plates resolves against, grouped by feature, not one flat dir
│   ├── layout.php             # shared <html>/<head>/<body> shell (Plates layout()/section())
│   ├── pages/                  # the dashboard/session-detail page content - what PageView renders
│   ├── header.php, sidebar.php, compose-bar.php  # session page chrome - plain `include`s, not Plates templates
│   └── transcript/, blocked-prompt/, session-row/, quota-footer/, health-box/, push-notify/
│                             # one subdirectory of templates per App\Views\* class above
├── host-agent/             # installed natively on the HOST, not in Docker
│   ├── agent.php            # per-connection entry point (systemd socket activation)
│   ├── push_trigger.php     # entry point run periodically by the csm-push-check systemd timer
│   ├── quota_refresh.php    # standalone entry point for a background quota scrape
│   ├── .env.example         # copy to .env, host-specific paths, never commit .env
│   ├── hooks/
│   │   ├── session_start.php  # Claude Code SessionStart hook - see "Why the SessionStart hook exists"
│   │   └── pre_tool_use.php   # Claude Code PreToolUse hook - see "Why the PreToolUse hook exists"
│   ├── lib/
│   │   ├── Sessions.php      # dispatch_action() - thin switch, routes every non-push action
│   │   ├── Push.php          # dispatch_push_action() - thin switch, routes every push_* action
│   │   ├── Services/         # the real logic: SessionService, TmuxService, QuotaService,
│   │   │                     # UploadService, HookService, TranscriptService, PromptParser,
│   │   │                     # ProcessInspector, ProcessRunner, Config, plus the push-related
│   │   │                     # services (PushDeliveryService, PushHealthService, PushTimerService,
│   │   │                     # NotificationContentBuilder)
│   │   └── Stores/           # SidecarStore, PendingToolStore, PushSubscriptionStore, PushSessionStateStore
│   ├── systemd/
│   │   ├── csm-agent.socket / csm-agent@.service   # unit TEMPLATES (@REPO_ROOT@/@PHP_BIN@/
│   │   │                                            # @SOCKET_GROUP@ placeholders) - install.sh
│   │   │                                            # substitutes real values in, never a raw `cp`
│   │   └── csm-push-check.timer / .service          # periodic push_trigger.php run (opt-in, see README)
│   └── install.sh            # installs + enables the systemd units, creates .env
└── tests/                  # dependency-free test suite, see "Running tests" below
    ├── run.sh               # entrypoint: bash tests/run.sh
    ├── .env.testing         # points every host-specific path at isolated fixtures
    ├── lib/                 # assert helpers + reusable socket/HTTP test harnesses
    ├── fixtures/            # fake claude binary, fake www root, canned fake agent
    └── test_*.php           # one file per area (protocol, session lifecycle, UI)
```

Both `App\` (→ `src/lib/`) and `HostAgent\` (→ `host-agent/lib/`) are
Composer PSR-4 autoloaded from the one root `composer.json` - `vendor/`
and `src/` are bind-mounted into the container as siblings of `public/`
(mirroring the host's own repo-root layout) so the same autoloader works
in both places (see `docker-compose.yml`'s volumes comment for why the
container's directory layout has to mirror the host's).

There is no standalone `Dockerfile` for the container - its build steps
live inline in `docker-compose.yml` under `build.dockerfile_inline`.
`public/`, `src/`, and `vendor/` are all bind-mounted rather than copied
into the image, so editing any PHP file takes effect on the next page
load with no rebuild or restart - only a `docker-compose.yml`/Dockerfile
change itself (docroot, CMD, PHP extensions, ...) needs
`docker compose up -d --build`.

## Why the SessionStart hook exists

Claude Code rotates to a brand-new session-id transcript file (a new UUID
under `~/.claude/projects/<cwd>/`) on `/clear`, `/compact` (auto or
manual), `--resume`, or `--fork-session` - all while staying in the same
tmux pane/process. This app's sidecar (one JSON file per tracked session,
under `SIDECAR_DIR`) records that session-id exactly once, at spawn
(`create_cc_session()` in `host-agent/lib/Services/SessionService.php`), and
has no other way to learn it changed. Without the hook, any of those
events leaves the sidecar pointing at an abandoned, no-longer-growing
transcript file forever after - not a polling-speed problem, the file the
app is reading has genuinely stopped receiving new lines.

`host-agent/hooks/session_start.php`, registered as Claude Code's
`SessionStart` hook (fires on every session start, matcher `*` so it
covers `startup`/`resume`/`clear`/`compact`/`fork`), fixes this by
rebinding the sidecar's `claude_session_id` live every time it fires.
`create_cc_session()` passes `CSM_SESSION_NAME=<session name>` as a tmux
pane environment variable (`tmux new-session -e ...`) specifically so the
hook - inherited into that pane's `claude` process and anything it spawns
- can tell which sidecar (if any) belongs to it; a plain `claude` session
started by hand outside this app has no `CSM_SESSION_NAME` and the hook
is a no-op for it.

This only takes effect going forward: a session that already rotated
before the hook was installed needs a one-time manual sidecar rebind (or
its next natural `/clear`/`/compact`) to catch up.

## Why the PreToolUse hook exists

A blocked permission prompt's "preview" (the command being run, the file
being written) is normally scraped straight from `tmux capture-pane` -
just whatever's currently rendered in the pane. That has two independent
size limits stacked on top of each other: the pane's own height/width (a
headless tmux session has no attached client to inherit a real terminal
size from, so it defaults to tmux's own 80x24 unless `TMUX_PANE_WIDTH`/
`TMUX_PANE_HEIGHT` are configured larger - nowhere near enough for a large
`Write` or a multi-line script to render in full), and
`parse_blocking_prompt()`'s own context-window scan on top of whatever
*did* render. Both are best-effort reconstructions of something that was
never meant to be machine-read in the first place.

`host-agent/hooks/pre_tool_use.php`, registered as Claude Code's
`PreToolUse` hook (fires immediately before every tool call, including
ones that never end up needing approval - before any permission prompt is
shown), sidesteps both limits by recording the tool call's `tool_name`
and full, untruncated `tool_input` JSON straight from the hook's own
stdin, no terminal rendering involved. `build_session_entry()` prefers
this recorded data over the pane-scraped context whenever a blocking
prompt is currently detected *and* the recorded tool name matches the
pane's own "● ToolName(...)" marker line (a cheap sanity check against
showing a stale or mismatched previous tool call's data - see
`augment_prompt_with_pending_tool()`). The hook writes nothing to stdout
and always exits `0`, which Claude Code treats as "no opinion" - it never
approves, denies, or otherwise affects the real permission decision, only
observes it.

Same `CSM_SESSION_NAME` mechanism as the `SessionStart` hook above: a
plain `claude` session started by hand outside this app has no
`CSM_SESSION_NAME` and the hook is a no-op for it. The recorded pending-
tool file is cleared once this app itself submits an answer to the prompt
or the session is killed; it's otherwise just overwritten by the next
tool call, so a stale leftover from answering outside this app (e.g.
attaching directly over tmux) only ever lingers until the *next* tool call
fires the hook again.

## Updating the host agent

`host-agent/agent.php` and everything under `host-agent/lib/` run directly
off the checked-out repo path (`ExecStart=` in the generated
`csm-agent@.service`), so editing any of them takes effect on the *next*
connection with no restart needed - each connection gets a fresh PHP
process. You only need to re-run `install.sh` (or `systemctl --user
daemon-reload`) if you change the `.socket`/`.service` unit *templates*
under `host-agent/systemd/` themselves.

## The agent socket caveat (read this)

- The agent's socket lives at `$XDG_RUNTIME_DIR/csm-agent.sock`, created by
  systemd from the generated `csm-agent.socket` unit with `SocketMode=0660`
  and a `SocketGroup=` matching whoever ran `install.sh`. Only the owning
  user and that group can connect - the container needs `APP_GID` set to
  that same numeric gid (`install.sh` prints it).
- `Accept=yes` means systemd spawns a **new** `agent.php` process per
  connection (classic inetd-style activation) with STDIN/STDOUT bound
  directly to that connection - no daemon loop, no manual socket-handling
  code, systemd owns the whole lifecycle.
- If the app shows "Cannot reach host agent", check on the host:
  `systemctl --user status csm-agent.socket` and
  `journalctl --user -u 'csm-agent@*' -n 50`.
- If `docker compose up` was run before the agent was installed, the bind
  mount will have created a plain directory at the socket path instead of
  passing through the real socket. Fix: `docker compose down`, confirm
  `ls -la $XDG_RUNTIME_DIR/csm-agent.sock` shows a real socket (reinstall
  via `install.sh` if not), then `docker compose up -d`.

## Web Push delivery mechanism

See the README's "Web Push notifications" section for setup. Mechanism:
no client-side background mechanism exists on iOS to detect a session
transitioning to blocked (no Periodic Background Sync support), so it's
entirely server/host-triggered - the `csm-push-check` timer runs
`host-agent/push_trigger.php` on an interval (default 10s), which compares
each live session's current blocked/working/idle state
(`NotificationContentBuilder::push_session_state()`) against what it was on
the previous tick (`host-agent/state/push-session-state.json` by default,
which also tracks *how long* a session has been in its current state) and
sends one of two notification types, only on the relevant transition
(never every tick):

- **Blocked**: on the transition INTO blocked - not on every tick a prompt
  sits unanswered, and not when a session resolves out of blocked either.
  The body (`push_blocked_body()`) shows the real command/action for a
  permission prompt (via the `PreToolUse` hook's recorded `tool_input` -
  see `push_permission_body()`) rather than the generic pane-scraped "Do
  you want to proceed?" question; an `AskUserQuestion` prompt still shows
  the real question text. Every push body is length-capped
  (`push_truncate()`) before being handed to the payload builder, not just
  at send time - Web Push has a hard payload size limit, and truncating
  the assembled JSON as a whole risks cutting a multi-byte UTF-8 character
  in half or landing inside the JSON structure itself.
- **Finished**: on the transition from working INTO idle, but only once
  the session has been continuously working for at least
  `PUSH_MIN_WORKING_SECONDS_FOR_FINISH_NOTIFY` seconds (default 60) - this
  avoids a notification for every trivial quick reply, reserving it for
  genuinely long-running tasks that finish without ever needing input.

A separate, parallel pass (`check_and_send_quota_pushes()`, same
`push_trigger.php` tick) handles quota near/over/reset notifications - see
`PUSH_QUOTA_*` variables in `host-agent/.env.example`.

**iOS's subscription lifecycle is flaky, by design of the platform, not a
bug here**: a subscription can silently die after roughly 1-2 weeks, or
after as few as 3 pushes the service worker doesn't turn into a shown
notification (see `public/sw.js` - its `push` handler is written specifically
to never skip calling `showNotification`, to avoid tripping that). There's
no error surfaced to the app when this happens. `check_and_send_pushes()`
prunes any subscription a real send reports as permanently expired (HTTP
404/410) rather than retrying it forever.

**IPv6 caveat**: `send_push_notification()` forces IPv4 for the actual send
(`CURLOPT_IPRESOLVE`) - found live: on some networks, IPv6 to
`web.push.apple.com` silently times out after the full 30s while IPv4 to
the exact same endpoint responds in well under a second.

**Optional**: `minishlink/web-push`'s own EC crypto is noticeably faster
with the GMP or BCMath PHP extension installed (`php -m | grep -i
'gmp\|bcmath'` to check) - not required, sends still work without it, just
slower.

## Running tests

```
bash tests/run.sh          # run every test file
bash tests/run.sh --bail   # stop at the first failing test file
```

No Composer, no Pest - plain PHP scripts (`tests/test_*.php`) run directly
by the `php` CLI, driven by a bash entrypoint. Nothing beyond `php`,
`bash`, `curl`, and `tmux` (already required to run this app at all) is
needed; a headless browser (`google-chrome-stable`, `google-chrome`,
`chromium`, or `chromium-browser`) is used for a couple of extra checks in
`test_ui_smoke.php` *if* one is already installed, and skipped cleanly
otherwise.

**Isolation, since this tool can create real tmux sessions and spawn real
processes on the host:**

- `tests/.env.testing` points `TMUX_SOCKET` at `/tmp/csm-test-tmux/socket`
  - a completely separate tmux **server**, never your real one. It cannot
  see or touch your real `cc-*` sessions.
- `CLAUDE_BIN` points at `tests/fixtures/fake_claude`, a script that behaves
  like `cat` with no arguments (blocks on stdin) regardless of what real
  flags it's actually invoked with (e.g. `--session-id <uuid>`), using
  `exec -a "$0" cat` so `argv[0]` still ends up exactly this path, matching
  the real launcher for `find_claude_processes()`'s matching. The real,
  billable `claude` CLI is never invoked by the test suite.
- `tests/run.sh` traps normal exit, Ctrl-C, and `TERM` and always runs
  cleanup: `tmux -S <test socket> kill-server` (kills the isolated server
  and every process it started), a `pkill -f fake_claude` sweep, and
  removal of the isolated sidecar/socket dirs - on success, on any test
  failure, `--bail` or not, and on interrupt (the interrupt handler
  cleans up *then* terminates the script, rather than resuming the loop).
  It also refuses to run at all if `.env.testing` ever points at the real
  socket/sidecar paths.
- `test_ui_smoke.php` talks to a canned fake agent
  (`tests/fixtures/canned_agent.php`) instead of the real one, so it never
  touches tmux at all.

`tests/test_agent_client_protocol.php` covers the socket wire protocol
(`src/lib/AgentClient.php` against the real `host-agent/agent.php`),
`tests/test_sessions_lifecycle.php` covers create/list/kill/cleanup against
the isolated tmux fixture, `tests/test_transcript.php` covers
`TranscriptService`/transcript-reading `SessionService` methods (parsing,
pagination, search, archived-session listing) against real fixture JSONL
files under a fake `HOME_ROOT`, and `tests/test_ui_smoke.php` covers the
web UI end to end via curl (plus the optional headless-browser tier
above).

## Code style / conventions

- Any script that binds to a fixed, reused resource path (a Unix socket, a
  lock file, a PID file) must find and terminate whatever process is
  already holding that resource *before* it unlinks/rebinds it, as the
  very first thing it does - see `tests/lib/socket_harness.php`'s own
  `/proc/net/unix`-based cleanup for the pattern (matching a bound AF_UNIX
  socket back to its owning process needs that, not `fileinode()`/`stat()`
  on the socket file - a completely different, unrelated number space).
- Tests: never weaken an assertion or delete a test to force a passing
  state. Include sad-path coverage (invalid input, failed
  auth/validation, a dependency being down) alongside the happy path, not
  as an afterthought - a sad-path test should assert the *specific*
  expected handled outcome (a proper error response, an exception, a
  redirect), not just "didn't crash."
- No comments explaining *what* code does (well-named identifiers already
  do that) - only *why*, when it's genuinely non-obvious: a hidden
  constraint, a workaround for a specific bug, something that would
  surprise a future reader. Many existing comments in this codebase record
  a specific bug "found live" and the reasoning that led to the fix - read
  them before assuming something is over-engineered.
