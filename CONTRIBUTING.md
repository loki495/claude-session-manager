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

The sticky footer shows session/weekly usage percentages sourced from
`QuotaService::quota_from_statusline_state()` - a small block this app
appends to your Claude Code statusLine script (`StatuslineMarkerService`'s
`QUOTA_CAPTURE` block) writes account-wide `rate_limits.*` straight from
Claude Code's own statusLine JSON to the `quota_live_state` key
(`Config::quota_live_state_key()`, a `GlobalStateStore` row, not a file -
see `host-agent/quota_live_state_write.php`) on every status-line render,
event-driven, no scraping of any kind. `GET /quota.php` just reads that
row back; `resets_at` is a real Unix epoch straight from Claude Code's own
JSON, so the frontend can render a live countdown with no parsing needed.
If the row doesn't exist yet (no session has rendered its status line with
the marker installed), the footer just shows "Quota unavailable" - a
nice-to-have, never a hard dependency for the rest of the app.

An earlier design also had a live tmux-pane-scraping fallback, and beyond
that an external `claude-quota`-shaped binary (a slow, 10-40s scrape of
Claude Code's own `/usage` panel via a detached `screen` session, cached
and background-refreshed via a `.refreshing` marker file with atomic
exclusive-create locking to prevent duplicate scrapes). Both were deleted
2026-08-22 as confirmed dead code: once any session had ever written the
statusline-state file even once, `quota_from_statusline_state()` never
returns null again, so neither fallback could actually be reached anymore.
See git history (`host-agent/lib/Services/QuotaService.php`,
`host-agent/quota_refresh.php`) if any of that ever needs resurrecting.

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
- `ArchivedSessionService::search_transcripts()` (dashboard-wide, `/search_sessions.php`)
  runs that across every known transcript, live and archived alike, and
  cross-references the live-tracked-session list (same reconciliation
  `list_archived_dashboard()` already does) so each result's own
  `session_name` is set only when something currently tracks it live -
  that's what tells the client which page to link to.
  `ArchivedSessionService::session_transcript_search()`/
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
sessioneer/
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
│                             # own; View.php owns the shared League\Plates engine they all extend.
│                             # MarkdownRenderer.php is the one exception - a pure string-in/HTML-out
│                             # helper (no template, no League\Plates), used by TranscriptView for
│                             # 'text'-kind transcript blocks; mirrored in public/js/session.js's
│                             # renderMarkdown() for the poll-time path
├── src/partials/            # every template Plates resolves against, grouped by feature, not one flat dir
│   ├── layout.php             # shared <html>/<head>/<body> shell (Plates layout()/section())
│   ├── pages/                  # the dashboard/session-detail page content - what PageView renders
│   ├── header.php, sidebar.php, compose-bar.php  # session page chrome - plain `include`s, not Plates templates
│   └── transcript/, blocked-prompt/, session-row/, quota-footer/, health-box/, push-notify/
│                             # one subdirectory of templates per App\Views\* class above
├── host-agent/             # installed natively on the HOST, not in Docker
│   ├── agent.php            # per-connection entry point (systemd socket activation)
│   ├── push_trigger.php     # entry point run periodically by the sessioneer-push-check systemd timer
│   ├── .env.example         # copy to .env, host-specific paths, never commit .env
│   ├── hooks/                # 10 hook scripts across three agents
│   │   ├── session_start.php  # Claude Code SessionStart hook - see "Why the SessionStart hook exists"
│   │   ├── pre_tool_use.php   # Claude Code PreToolUse hook - see "Why the PreToolUse hook exists"
│   │   ├── permission_request.php   # Claude Code PermissionRequest hook \
│   │   ├── user_prompt_submit.php   #  \_ see "Why the PermissionRequest/
│   │   ├── stop.php                 #  /  UserPromptSubmit/Stop hooks exist"
│   │   ├── antigravity/       # pre_invocation, pre_tool_use, post_tool_use, stop - the same
│   │   │                      # observe-only status feed, in Antigravity's own hook shape
│   │   └── codex/status.php   # Codex's equivalent status feed
│   ├── lib/
│   │   ├── Sessions.php      # dispatch_action() - thin switch, routes every non-push action
│   │   ├── Push.php          # dispatch_push_action() - thin switch, routes every push_* action
│   │   ├── Agents/           # the per-agent abstraction: AgentAdapter (the interface every
│   │   │                     # agent implements) + AgentRegistry, with ClaudeCodeAdapter,
│   │   │                     # CodexAdapter, OpenCodeAdapter, AntigravityAdapter behind it.
│   │   │                     # This is what keeps cc-*/cx-*/oc-*/ag-* differences out of the
│   │   │                     # services - add an agent here, not with branches elsewhere.
│   │   ├── Runtimes/         # HOW a session runs, independent of WHICH agent it is:
│   │   │                     # TmuxRuntime (a real pane) vs HeadlessRuntime, behind
│   │   │                     # RuntimeProvider/RuntimeRegistry/RuntimeType, plus the two
│   │   │                     # headless clients (OpenCodeServeClient, CodexBridgeClient) and
│   │   │                     # CodexHeadlessRuntime. See docs/headless-runtime-plan.md.
│   │   ├── Services/         # the real logic - 36 classes, too many to list here; the
│   │   │                     # load-bearing ones are Config, SessionService (listing +
│   │   │                     # build_session_entry), SessionLifecycleService (create/resume/
│   │   │                     # kill), PromptInteractionService (sending input/answers),
│   │   │                     # ArchivedSessionService, PlanFileService, TmuxService,
│   │   │                     # TranscriptService, PromptParser, ProcessInspector,
│   │   │                     # ProcessRunner, QuotaService, UploadService, HookService, and
│   │   │                     # the push set (PushDeliveryService, PushHealthService,
│   │   │                     # PushTimerService, NotificationContentBuilder)
│   │   └── Stores/           # SidecarStore, PendingToolStore, SessionStatusStore,
│   │                         # SessionListCacheStore, GlobalStateStore, PushSubscriptionStore,
│   │                         # PushSessionStateStore, PushQuotaStateStore, SqliteDb
│   ├── systemd/
│   │   ├── sessioneer-agent.socket / sessioneer-agent@.service   # unit TEMPLATES (@REPO_ROOT@/@PHP_BIN@/
│   │   │                                            # @SOCKET_GROUP@ placeholders) - install.sh
│   │   │                                            # substitutes real values in, never a raw `cp`
│   │   └── sessioneer-push-check.timer / .service          # periodic push_trigger.php run (opt-in, see README)
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
(`create_agent_session()` in `host-agent/lib/Services/SessionLifecycleService.php`), and
has no other way to learn it changed. Without the hook, any of those
events leaves the sidecar pointing at an abandoned, no-longer-growing
transcript file forever after - not a polling-speed problem, the file the
app is reading has genuinely stopped receiving new lines.

`host-agent/hooks/session_start.php`, registered as Claude Code's
`SessionStart` hook (fires on every session start, matcher `*` so it
covers `startup`/`resume`/`clear`/`compact`/`fork`), fixes this by
rebinding the sidecar's `claude_session_id` live every time it fires.
`create_agent_session()` passes `SESSIONEER_SESSION_NAME=<session name>` as a tmux
pane environment variable (`tmux new-session -e ...`) specifically so the
hook - inherited into that pane's `claude` process and anything it spawns
- can tell which sidecar (if any) belongs to it; a plain `claude` session
started by hand outside this app has no `SESSIONEER_SESSION_NAME` and the hook
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

Same `SESSIONEER_SESSION_NAME` mechanism as the `SessionStart` hook above: a
plain `claude` session started by hand outside this app has no
`SESSIONEER_SESSION_NAME` and the hook is a no-op for it. The recorded pending-
tool file is cleared once this app itself submits an answer to the prompt
or the session is killed; it's otherwise just overwritten by the next
tool call, so a stale leftover from answering outside this app (e.g.
attaching directly over tmux) only ever lingers until the *next* tool call
fires the hook again.

## Why the PermissionRequest/UserPromptSubmit/Stop hooks exist

Beyond a blocked prompt's *content* (the `PreToolUse` hook above), three more
things used to come only from scraping the rendered tmux pane: the current
permission mode (matching Claude Code's own status-line text, e.g. "manual
mode on"), whether the session is actively working (matching an animated
spinner glyph on the pane title - a source of recurring bugs whenever Claude
Code changed its spinner style), and whether a permission prompt is blocking
at all. All three are now fed by hook SEQUENCE instead:

- `host-agent/hooks/user_prompt_submit.php` (Claude Code's `UserPromptSubmit`
  hook, fires whenever a message is actually submitted) marks the session
  `working` and clears any previously-recorded blocked state.
- `host-agent/hooks/permission_request.php` (Claude Code's `PermissionRequest`
  hook, fires only when a decision is actually needed - not every tool call,
  unlike `PreToolUse`) marks the session `blocked` and records the full
  `tool_name`/`tool_input`/`permission_suggestions` straight from its own
  payload - no correlation against the `PreToolUse`-fed pending-tool file
  needed, this hook's own payload already carries everything.
- `host-agent/hooks/stop.php` (Claude Code's `Stop` hook, fires once Claude
  finishes responding) marks the session `idle`, clears any blocked state,
  and records `last_assistant_message`.

`host-agent/hooks/pre_tool_use.php` also clears blocked state, on top of its
main pending-tool-content job (see above) - found live 2026-08-22: a session
stuck showing a stale "waiting on input" prompt long after it was actually
resolved. `permission_request.php` sets `status=blocked` for a tool that
needs a decision, but once approved, nothing else in the sequence clears it
unless the SAME turn also happens to fire `UserPromptSubmit` or `Stop` - a
multi-tool-call turn where only the FIRST call needed approval left every
later call's `PreToolUse` firing silently ignored, so the dashboard kept
showing that first prompt as still-blocking indefinitely. Fix: since Claude
Code never starts executing tool call N+1 while tool call N's own permission
prompt is still genuinely unanswered, a LATER tool call's `PreToolUse` firing
is itself proof any earlier blocking has already been resolved - it now
marks the session `working` and clears `blocked` too, same as
`user_prompt_submit.php` does. If THIS tool call also needs a decision,
`permission_request.php` fires right after and sets `blocked` again, so the
net effect is correct either way, just briefly optimistic between the two
hook firings (invisible to a poll-based UI).

Each hook writes only the fields its own event actually carries to a new
per-session file (`SessionStatusStore`, `Config::sidecar_dir()`-based, same
convention as the `PreToolUse` hook's pending-tool file above) via a
read-modify-write merge, not a wholesale overwrite.

**These three hooks are mandatory, not "preferred with a fallback"** (decided
2026-08-22, after shipping them as an initially-optional upgrade path turned
out to just be a second, half-supported code path with no real benefit - see
the todo file's research entry). `build_session_entry()` reads
mode/working-status/blocked-prompt content EXCLUSIVELY from this file for
every tool except `AskUserQuestion` - there is no pane-scraping fallback for
a session with no status file (hooks not installed yet, or a script error):
it just reports mode as unknown, working as false, and no blocked prompt,
even if the pane happens to be showing a real one. This is why the
dashboard's health box (and its "Install hooks" banner) treats all five
hooks as one all-or-nothing gate - see `HookService::app_hooks_status()`.
The pane-scraping this replaced (`PromptParser::pane_title_is_working()`,
matching an animated spinner glyph, and the working-status half of
`TmuxService::tmux_session_panes()`) was deleted outright as dead code once
the fallback was removed, not left around unused.

Two cases still need the live pane regardless of hook installation status -
these aren't a "fallback available if you skip installing the hooks", they're
structural: no combination of hooks could ever cover them, since the
information genuinely doesn't exist anywhere except the pane at that instant:

- **The initial per-folder trust dialog** fires none of Claude Code's hooks
  at all (confirmed live) - it's a separate, pre-hook-system startup safety
  check, so there's no event to feed a status file with in the first place.
- **A single-question `AskUserQuestion`** keeps using the existing
  pane-scraped path unchanged for its CONTENT - it renders with no tab bar
  at all, so there's no "which tab" ambiguity to begin with, but also
  nothing to gain from reading `blocked.tool_input.questions[]` instead of
  the pane. `build_session_entry()` still needs the hook-fed
  `blocked.tool_name` to even KNOW a currently-showing prompt is an
  `AskUserQuestion` in the first place, though - so this case is really
  "hook tells us WHICH prompt shape it is, pane tells us the content", not
  "no hook involvement at all". That `blocked.tool_name` doesn't come from
  `PermissionRequest`, though: per the official tools reference,
  `AskUserQuestion` prompts are a distinct mechanism from permission
  prompts (they even have their own separate idle-timeout setting), and
  confirmed live it never fires `PermissionRequest` at all - an earlier
  version of this doc claimed otherwise ("confirmed live"), which was
  wrong and caused a real bug (found live 2026-08-23): `pre_tool_use.php`
  optimistically sets `status=working` for every tool call expecting
  `PermissionRequest` to correct it to `blocked` right after, and with no
  `PermissionRequest` ever coming for `AskUserQuestion`, sessions got stuck
  showing "Thinking..." indefinitely on a real, answerable question.
  `pre_tool_use.php` now special-cases `AskUserQuestion` and writes
  `blocked` itself instead of `working`.

A **multi-question** `AskUserQuestion` (2+ questions, the tab-bar shape)
is different: it's answered entirely from the hook data now, not the pane,
per Andres's own design 2026-08-22 - see "Answering a multi-question
AskUserQuestion without reading the pane" below.

None of this touches the OTHER, unrelated reasons `PromptParser::
parse_blocking_prompt()`/`PermissionMode::parse_current_mode()` read the
live pane - `PromptInteractionService::answer_prompt()`/
`answer_prompt_with_text()`/`set_mode()` each re-validate a prompt/mode is
still genuinely showing right before
sending a real keystroke - live pre-flight safety checks, not related to
SessionStatusStore at all, and would need the pane read regardless of how
complete hook coverage ever got.

Same `SESSIONEER_SESSION_NAME`-gated, pure-observe (never writes to stdout, always
exits `0`) conventions as the `SessionStart`/`PreToolUse` hooks above -
multiple hook commands already coexist per event in `~/.claude/settings.json`
today, so installing these never disturbs any hooks you've already
registered for the same events yourself.

## Answering a multi-question AskUserQuestion without reading the pane

A multi-question `AskUserQuestion` call (2+ questions) renders as a tab bar
Claude Code itself navigates with the Left/Right arrow keys, one question per
tab plus a final "Submit" review tab. The OLD design (still how a
single-question `AskUserQuestion` and every other prompt shape works) only
ever showed whichever tab the pane currently had up, needing Prev/Next
buttons (`SessionService::navigate_prompt()`, the `nav-prompt-btn` UI,
`/session_navigate.php`) to reach the others - all three deleted outright
2026-08-22 once this new form made them unreachable, not left around unused.

Andres pointed out 2026-08-22 that this doesn't need the pane at all:
`PermissionRequest`'s own payload already carries the FULL `questions[]` set
(every question, every option) the moment the call starts, not just whichever
tab happens to be showing - `SessionService::build_session_entry()` exposes
this as `prompt_questions` whenever `blocked.tool_name === 'AskUserQuestion'`
and there are 2+ questions. `BlockedPromptView::blocked_multi_question_html()`
renders every question as its own radio-group (single-select) or
checkbox-group (multiSelect) up front, with a per-question free-text input
for single-select questions' "Type something" option - all answerable in the
app before anything is sent, no live tab-tracking needed.

The exact tmux key sequence to reach that end state was confirmed live
2026-08-22 (a real 3-question call: single-select, multiSelect, and
single-select-with-free-text; a second real call confirming free-text
specifically auto-advances the same way a real option does) and is computed
by `PromptParser::build_multi_question_key_sequence()` (see its own docblock
for the full confirmed mechanics and the one inferred, not independently
verified, generalization). `PromptInteractionService::answer_multi_question()` sends
it - after ONE live pane check up front (confirming the prompt is still
genuinely sitting on the first question, unanswered - guarding against
someone else having already interacted with it via a different client in the
meantime), not a per-keystroke re-validation like `answer_prompt()`'s: this
method is meant to run the WHOLE sequence as one atomic action right after
the app first showed the question form, so there's nothing to re-check
between its own steps.

Deliberately out of scope for now (untested, not something live verification
covered): a multiSelect question's own "Type something" checkbox option -
`build_multi_question_key_sequence()` rejects free-text for a multiSelect
question rather than guessing at how it'd combine with checked boxes.

Found live 2026-08-23 (Andres: "the multi question send answer is not
working"): both `BlockedPromptView::blocked_multi_question_html()` and its
JS mirror (`session.js`'s `renderMultiQuestionFormHtml()`) put a
`data-question-index` attribute on the "Type something…" radio AND its
free-text `<input>`, not just the per-question wrapper `<div>` that
attribute is actually meant to uniquely identify - never read off those two
inner elements anywhere. That broke two separate lookups that both assume
`[data-question-index]` matches only the wrapper: `submitMultiQuestionAnswers()`'s
`wrapper.querySelectorAll('[data-question-index]')` picked up the inner
elements too, so `.querySelector('p')` on a bare `<input>` returned `null`
and `.textContent` threw - silently aborting the whole handler before it
ever reached `fetch()`, no alert, no request. Separately, the change-listener's
`e.target.closest('[data-question-index]')` matched the radio itself when
clicked (since it now also carried the attribute) instead of its containing
question `<div>`, so the free-text field never revealed. Fixed by dropping
the attribute from both inner elements - nothing ever needed it there.
Neither symptom was caught by this suite's own `test_session_replay_browser.php`
click on `.multi-question-submit-btn` despite exercising this exact path,
because a JS exception thrown inside a real DOM event listener never
propagates back through `.click()`'s own return value - see
`tests/lib/cdp.php`'s `cdp_drain_console_errors()` (added in response, along
with a per-step `browser_assert()` in `test_session_replay_browser.php`) for
the fix to that blind spot.

## Updating the host agent

`host-agent/agent.php` and everything under `host-agent/lib/` run directly
off the checked-out repo path (`ExecStart=` in the generated
`sessioneer-agent@.service`), so editing any of them takes effect on the *next*
connection with no restart needed - each connection gets a fresh PHP
process. You only need to re-run `install.sh` (or `systemctl --user
daemon-reload`) if you change the `.socket`/`.service` unit *templates*
under `host-agent/systemd/` themselves.

## The agent socket caveat (read this)

- The agent's socket lives at `$XDG_RUNTIME_DIR/sessioneer-agent.sock`, created by
  systemd from the generated `sessioneer-agent.socket` unit with `SocketMode=0660`
  and a `SocketGroup=` matching whoever ran `install.sh`. Only the owning
  user and that group can connect - the container needs `APP_GID` set to
  that same numeric gid (`install.sh` prints it).
- `Accept=yes` means systemd spawns a **new** `agent.php` process per
  connection (classic inetd-style activation) with STDIN/STDOUT bound
  directly to that connection - no daemon loop, no manual socket-handling
  code, systemd owns the whole lifecycle.
- If the app shows "Cannot reach host agent", check on the host:
  `systemctl --user status sessioneer-agent.socket` and
  `journalctl --user -u 'sessioneer-agent@*' -n 50`.
- If `docker compose up` was run before the agent was installed, the bind
  mount will have created a plain directory at the socket path instead of
  passing through the real socket. Fix: `docker compose down`, confirm
  `ls -la $XDG_RUNTIME_DIR/sessioneer-agent.sock` shows a real socket (reinstall
  via `install.sh` if not), then `docker compose up -d`.

## Web Push delivery mechanism

See the README's "Web Push notifications" section for setup. Mechanism:
no client-side background mechanism exists on iOS to detect a session
transitioning to blocked (no Periodic Background Sync support), so it's
entirely server/host-triggered - the `sessioneer-push-check` timer runs
`host-agent/push_trigger.php` on an interval (default 10s), which compares
each live session's current blocked/working/idle state
(`NotificationContentBuilder::push_session_state()`) against what it was on
the previous tick (`PushSessionStateStore`'s table in `push.sqlite` - see
"Per-session/global state storage" below - which also tracks *how long* a
session has been in its current state) and
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

## Per-session/global state storage (SQLite)

Requires the `pdo_sqlite` PHP extension on the **host** (not the
container - see "Architecture" above, these Stores are all host-agent
classes, never touched from `src/`). Check with `php -m | grep sqlite`;
if missing, enable it the same way as any other PHP extension for your
distro (e.g. Arch: `echo "extension=pdo_sqlite.so" | sudo tee
/etc/php/conf.d/pdo_sqlite.ini`) - the `.so` is commonly already present
even when not loaded by default.

Two SQLite DB files (`host-agent/lib/Stores/SqliteDb.php`), matching the
two different persistence lifetimes the plain-JSON-file versions of these
Stores already had before 2026-08-24:

- `Config::sessions_sqlite_path()` (defaults under `Config::sidecar_dir()`,
  tmpfs, wiped on reboot) - `SidecarStore`/`SessionStatusStore`/
  `PendingToolStore`'s three tables (`sidecars`/`session_status`/
  `pending_tools`).
- `Config::push_sqlite_path()` (defaults to `host-agent/state/push.sqlite`,
  persisted, gitignored) - `PushSubscriptionStore`/`PushSessionStateStore`/
  `PushQuotaStateStore`'s three tables, plus `GlobalStateStore`'s single
  `global_state` table (small single-blob concerns keyed by name, e.g.
  `quota_live_state` - see "Usage quota footer" above).

**Why**: `SessionStatusStore::update_status()`'s old read-json-merge-
write-json had a real, confirmed-live (2026-08-23) lost-update race
between two hooks firing close together (`PreToolUse` and
`PermissionRequest` for the same tool call); `PushQuotaStateStore` had the
same shape of race behind Andres's quota-double-push report. SQLite
serializes writers to the same row/table, so both are now a single atomic
`UPDATE`/`INSERT ... ON CONFLICT DO UPDATE` instead - the race is closed
structurally, not by adding a lock around the old read-modify-write.

**Live cutover, not a one-time migration script**: every read method
(`read_sidecar()`, `read_status()`, etc.) checks for a still-present
legacy JSON file on an SQLite miss, imports it, and deletes it -
important because these tmpfs files back **currently-running** sessions
whose `SessionStart`-hook-driven rebind only fires on `/clear`/`/compact`/
`--resume`/`--fork-session`, not every turn. A hard cutover with no
fallback would have silently dropped every already-running session out of
`list_tracked_tmux_sessions()` until it happened to rotate, possibly
hours later. Every direct-write method (`write_sidecar()`,
`update_status()`, `write_pending_tool()`) ALSO cleans up any leftover
legacy file itself, not just the read path - found live while building
this: `PreToolUse` fires `write_pending_tool()` on nearly every tool call,
so a session's first post-migration touch is almost always a write, not a
read, and the read-only cleanup left `.pending-tool.json` files stranded
on disk forever once a direct write had already created the SQLite row.
Verified against this app's own real, live sidecar directory while
building this feature, not just the test suite - every currently-tracked
session (including the one used to write this) migrated correctly with
zero tracking gap.

Replaced `AtomicFile.php` (temp-file-then-`rename()` for a single
whole-file write, atomic against torn reads but never against a
concurrent read-modify-write) entirely - deleted outright, along with its
dedicated test, once nothing referenced it any more, not left around
unused.

## Frontend CSS build (Tailwind)

```
npm install         # once
npm run build:css   # regenerate public/css/tailwind.css after editing any
                     # utility classes, in a .php partial/view or in JS
```

`public/css/tailwind.css` is a committed, precompiled file - `npm` is a
**dev-only** tool for whoever is changing markup/classes, never required to
install or run the app itself (matches the "no build step" rule for
`public/js/*.js`, which is still plain unbundled ES5 - only the CSS moved
off a live CDN script). `resources/tailwind.css` is the actual source
(`@import "tailwindcss"` plus `@source` globs pointing at
`src/partials/**/*.php`, `src/lib/Views/**/*.php`, and `public/js/**/*.js`
- Tailwind's class scanner reads raw file text regardless of language, and
a large share of this app's markup is built as HTML strings inside plain
JS, not just PHP, so it has to be told to scan there too).

This replaced a `<script src="https://cdn.tailwindcss.com">` (2026-08-24) -
the browser's own console warns against that pattern in production, and a
page load fetching a script from a third-party host at runtime is a real
external dependency for a project whose whole pitch is "everything runs
locally, no external services." There was no `tailwind.config.js` or
custom theme/darkMode setup to port - the CDN script was plain default
Tailwind, verified before writing the replacement.

## Type-checking the frontend (JSDoc + tsc)

```
npm run typecheck
```

`tsc --noEmit` against plain `.js` (see `tsconfig.json`) - not a build
step, no transpilation, no bundler: `public/js/*.js` stays exactly the
plain, unbundled ES5 it already was (`// @ts-check` at the top of each
file is only an editor/CLI signal, zero runtime effect). `public/sw.js`
is deliberately excluded - a service worker runs in a completely
different global scope (`self`/`clients`/notification-event fields, no
`window`/DOM) that would need its own separate tsconfig with the
`webworker` lib, not the browser-page one the rest of this app uses; not
set up yet. `public/js/types.d.ts` declares the one ambient global this
app adds itself, `window.SESSIONEER_BOOTSTRAP` - never loaded by the browser (a
`.d.ts` has no runtime output), type-checking only.

Same "don't grind through the whole codebase in one pass" convention as
the PHPStan baseline below: `npm run typecheck` reports 121 known errors
today (2026-08-24), not zero, and that's expected - not a broken setup.
Two clusters:

- **~119 of them** are `document.getElementById()` returning the generic
  `HTMLElement` (no `.value`/`.checked`/`.disabled`/`.files`) and
  `event.target` being typed as the generic `EventTarget` (no `.closest`/
  `.dataset`/`.classList`) - both real elements at runtime, just typed too
  loosely by the DOM lib's own generic return types. The real fix is a
  JSDoc type cast (`/** @type {HTMLInputElement} */`) at each specific
  call site that needs the narrower type - not done in this first pass
  given the sheer number of sites; chip away incrementally rather than
  block on it.
- **2 known TS/DOM-lib gaps**, left as-is (verified correct, not
  suppressed): `common.js`'s `new Promise(function (resolve) {...})`
  calling `resolve()` with no argument (perfectly valid - checkJs just
  wants a JSDoc hint to infer that), and `index.js`'s
  `new URLSearchParams(new FormData(form))` (real browsers accept
  `FormData` via its iterable-of-pairs protocol; lib.dom.d.ts's
  `URLSearchParams` constructor type just hasn't caught up to that).

One already-fixed finding from this same pass, not lib-typing noise: two
dead lines in `resetHistoryForRotatedTranscript()`
(`lastKnownContextUsedPercentage = null; lastKnownGitWorktree = null;`)
assigned two variables that were never declared OR read anywhere else in
the codebase - `tsc`'s "Cannot find name" caught real orphaned code from
a past refactor, removed rather than baselined. Also worth knowing:
`session.js`'s `autoGrowCompose()` is declared with `function
autoGrowCompose() {}` inside an `if` block, not directly in the file's
own top-level IIFE - real non-strict-mode browsers still hoist it to the
enclosing function scope via Annex B legacy compatibility semantics
(verified live), but `tsc` doesn't model that, hence the two
`@ts-expect-error` comments there instead of a "fix" that would just be
restructuring already-correct code.

## Static analysis (PHPStan)

```
composer phpstan
```

Runs on the **host**, not inside the container - unlike everything else in
this repo, `phpstan.neon` scans both `src/lib` (container-side) and
`host-agent/lib`/`host-agent/hooks` (host-native) in one pass, and
`host-agent/` is deliberately never mounted into the container at all (see
"Architecture: container + host-native agent" above) - `docker exec` has
no way to see it. Host PHP just needs to be a reasonably recent version;
PHPStan's own checks aren't sensitive to the host/container PHP version
difference at the level this project runs.

Level 6, with a baseline (`phpstan-baseline.neon`) regenerated most
recently at 119 findings (2026-08-24, after the SQLite migration below) -
per this project's own convention, a strict gate is pinned to today's real
number rather than demanding the whole codebase pass immediately. New code
should not add to the baseline; existing baselined findings can be cleaned
up incrementally, regenerating the baseline file as they are (a file whose
code changes shape entirely - as every Stores/*.php file did in this same
migration - invalidates its own old baseline entries by exact message/path,
surfaced as `ignore.unmatched` errors; regenerate rather than hand-edit).
Worth a dedicated look regardless of level: `src/lib/Views/TranscriptView.php`
has a cluster of `always false`/`always true` comparisons flagged in
its tool-call-entry rendering (roughly lines 322-360 and 578-706) - most
`missingType.iterableValue` findings elsewhere are just missing array-shape
docblocks (cosmetic), but this cluster is a different, more interesting
category: PHPStan is saying a branch's own array-shape narrowing makes
certain conditions provably always true/false, which is either a real dead
code path or an overly narrow docblock - not yet triaged.

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

- `tests/.env.testing` points `TMUX_SOCKET` at `/tmp/sessioneer-test-tmux/socket`
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
- Escaping in `src/partials/`: `$this->e($value)` (Plates' own
  `htmlspecialchars` wrapper, not a custom App method) for any value that
  isn't already provably safe. A bare `<?= $value ?>` is only correct when
  `$value` can never carry arbitrary string content - an `(int)`/`(bool)`-
  cast value, a hardcoded string literal picked by a conditional (e.g.
  `$flag ? 'hidden' : ''`), or already-escaped/trusted HTML assembled by a
  `View` class's own render method (`$rowsHtml`, `$footerHtml`,
  `$markdownHtml`, etc. - these come from code that already escaped
  whatever real data went into them; escaping again here would double-
  encode it). Traced every current bare `<?= ?>` in `src/partials/` as of
  the 2026-08-23 readability audit and confirmed all of them fall into one
  of those categories - none are unsafe today - but that invariant only
  lives in each call site's own reasoning, not anywhere visibly enforced,
  so a new bare `<?= ?>` for a value that ISN'T provably one of the above
  needs `$this->e()`, not an assumption that the existing pattern makes it
  safe by association.
