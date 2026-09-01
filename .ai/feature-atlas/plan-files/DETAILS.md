---
id: plan-files
name: Sidebar plan/handoff + todo-file glance
owned_paths:
  - host-agent/lib/Services/PlanFileService.php
  - src/partials/sidebar/todo-list.php
last_scanned_commit: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# plan-files — Sidebar plan/handoff + todo-file glance

## 1. Identity

- **id:** plan-files
- **name:** Sidebar plan/handoff + todo-file glance

The sidebar's read-only glance at ad-hoc markdown plan/handoff files and the `todo`
file sitting directly in a session's own working directory. It surfaces otherwise-invisible
scratch docs that would otherwise be forgotten once stale, and gives one-click access to the
session's cwd-level `todo` bookkeeping file (the same convention this repo itself keeps).

This is a **read-only** glance by design — no delete/rename/write action exists anywhere in
the owned boundary; cleanup stays a manual shell operation.

## 2. Ownership boundary

**In scope (owned):**

- `host-agent/lib/Services/PlanFileService.php` — all three host-agent plan/todo methods.
- `src/partials/sidebar/todo-list.php` — the "Tasks" section markup (co-owned with
  `session-view`; see §9).

**Out of scope (neighboring subsystems):**

- `session-view` — owns the **todo list data source and rendering semantics**: the
  `detail['todos']` cascade (`TodoWrite`/`TaskCreate`/`TaskUpdate` tool calls) and the
  `todo-list.php` partial's actual *meaning* (transcript-sourced task checklist). The partial
  is physically here but feature-attributed to `session-view`.
- `uploads` — the sidebar's "Uploaded files" section (`uploaded-files-list`/
  `uploaded-files-total`/`delete-all-uploads-btn`), sibling to the plan/todo blocks but a
  different subsystem.
- `session-core` / `prompt-interaction` / `session-lifecycle` — session listing, input
  sending, and session create/resume/kill respectively. The plan/todo glance never mutates
  any of them; it only reads the sidecar for the workdir.
- `host-agent-runtime` (two-runtime seam) — the web UI → host-agent UNIX-socket JSON
  protocol (`AgentClient`), which carries these requests. Not owned here; only used.

## 3. Key implementation files

- **`host-agent/lib/Services/PlanFileService.php`** — the single service class. Host-agent
  side; fully self-contained (split out of `SessionService` on 2026-08-24, bodies moved
  verbatim). Three public static methods + one private path-resolution helper. All workdir
  resolution is server-side from the session sidecar.
- **`src/partials/sidebar/todo-list.php`** — the "Tasks" section render partial, taking a
  `$todos` array. Belongs to `session-view` semantically; co-owned here.

The other files that carry this feature are **co-reported** (physically in `session-view`):
`SessionController.php` (three controller methods), `sidebar.js`/`session.js` (JS blocks),
and `src/partials/sidebar.php` (the DOM container). See §9.

## 4. Public interfaces & contracts

### `PlanFileService::list_plan_files(string $sessionName): array` — host-agent/lib/Services/PlanFileService.php:34

Lists every top-level `*.md` file in the session's own workdir. Never recurses into
subdirectories. Excludes `readme.md`/`claude.md` (case-insensitive) — permanent, expected
project docs, not ad-hoc scratch.

- **Returns** `array{ok:bool, files?:array<int, array{name:string, size:int, mtime:int}>, message?:string}`.
  `files` is sorted **most-recently-modified first** (`usort` on `mtime`, :72).
- **Errors / sad paths:**
  - Unknown/unset workdir → `['ok' => false, 'message' => 'Unknown working directory for this session']` (:39-41).
  - Workdir doesn't exist on disk → `['ok' => true, 'files' => []]` (an empty glance, not an error, :43-45).
- **Pre-conditions:** none caller-facing; the workdir is never accepted from the caller —
  re-derived from `SidecarStore::read_sidecar($sessionName)` (:36).

### `PlanFileService::read_plan_file(string $sessionName, string $filename): array` — host-agent/lib/Services/PlanFileService.php:117

The new-tab view target for the "Plan/handoff files" list. Re-validates the `.md`/
`README`/`CLAUDE.md` rules **itself** rather than trusting a caller-supplied filename
(discipline commented at :80-84).

- **Returns** `array{ok:bool, message?:string, data?:string, media_type?:string, filename?:string}`.
  On success: `data` is the file content **base64-encoded**, `media_type` =
  `text/markdown; charset=utf-8`, `filename` = `basename($path)`.
- **Errors / sad paths:** all → `['ok' => false, 'message' => ...]`: unknown workdir (:122-124),
  path fails resolution / boundary check → `'File not found'` (:128-130), unreadable file →
  `'Could not read file'` (:134-136).
- **Path safety:** `resolve_plan_file_path()` (:86) enforces (a) extension must be `.md`,
  (b) basename must not be `readme.md`/`claude.md`, (c) `realpath($workdir)` must not be
  `false`, (d) `realpath($realDir . '/' . basename($filename))` must exist and must start
  with `$realDir . '/'` — this blocks traversal (a `../../etc/passwd` fails the `.md`
  check anyway) and subdirectory paths (by the `basename()` collapse in the join).

### `PlanFileService::read_todo_file(string $sessionName): array` — host-agent/lib/Services/PlanFileService.php:160

Reads the session's cwd-level, **no-extension** `todo` file for the "Open todo file" link.
Deliberately separate from `list_plan_files()`/`read_plan_file()`: `todo` is a
no-extension, always-expected project bookkeeping file that must stay readable regardless of
the `.md`/`README`/`CLAUDE.md` rules.

- **Returns** `array{ok:bool, message?:string, data?:string, media_type?:string, filename?:string}`.
  On success: `data` base64-encoded, `media_type` = `text/plain; charset=utf-8`,
  `filename` = `'todo'`.
- **Errors / sad paths:** unknown workdir (:165-167), workdir no longer exists →
  `'Working directory no longer exists'` (:171-173), no `todo` file →
  `'No todo file in this session\'s working directory'` (:177-179), unreadable →
  `'Could not read todo file'` (:183-185).
- **Path safety:** same `realpath` boundary discipline (`$realDir = realpath($workdir)`,
  then `$realDir . '/todo'`, :169-175). Workdir re-derived from the sidecar, never the client.

### Private helper

`resolve_plan_file_path(string $workdir, string $filename): ?string` — PlanFileService.php:86.
Shared by `read_plan_file()` (reachable path) — not a public contract, but the single choke
point for the `.md`/README/CLAUDE.md + realpath boundary rules.

## 5. Major call sites

**Host-agent dispatcher (upstream):**

- `Sessions.php::dispatch_action()` (host-agent/lib/Sessions.php:31) — the one switch that
  routes these actions:
  - `case 'list_plan_files':` → `PlanFileService::list_plan_files((string)($request['session'] ?? ''))` (:165-166)
  - `case 'read_plan_file':` → `PlanFileService::read_plan_file(..., (string)($request['filename'] ?? ''))` (:168-169)
  - `case 'read_todo_file':` → `PlanFileService::read_todo_file(...)` (:171-172)
  Return values are the `dispatch_action()` return values (invoked by `host-agent/agent.php`
  per systemd-spawned connection).

**Web-UI controller → agent (co-reported, see §9):**

- `SessionController::planFiles()` → `AgentClient::agent_call(['action' => 'list_plan_files', ...])`
  (:221), reads `$_GET['session']`.
- `SessionController::planFileContent()` → `AgentClient::agent_call(['action' => 'read_plan_file', ...])`
  (:425-429), streams via `Controller::stream_binary_result(..., immutable: false, inlineText: true)`.
- `SessionController::todoFile()` → `AgentClient::agent_call(['action' => 'read_todo_file', ...])`
  (:444-447), JSON echo.

**Sidebar render (co-reported):**

- `src/partials/sidebar.php` renders the DOM containers `#todo-file-link`/`#todo-file-status`
  (:63-68) and `#plan-files-list` (:81-86), and the Tasks wrapper `#todo-list-section` (:61).

**JS callers (co-reported):**

- `sidebar.js::openSidebar()` → `loadPlanFiles()` (:613) and `loadUploadedFiles()` (:612).
- `session.js::pollOnce()` → `sidebarCurrentlyOpen ? loadPlanFiles() : Promise.resolve()` (:2081).
- `sidebar.js` todo-file click handler → `fetch('/session_todo_file.php?...')` (:492), success
  path opens the base64-decoded content via `window.openFullscreenTextModal(...)` (:503).

## 6. Tests

- **`tests/test_sessions_lifecycle.php`** :471-538 — direct unit tests of all three
  `PlanFileService` methods. **Happy + sad both covered:** unknown-sidecar rejection for all
  three; `list_plan_files` (top-level-`.md`-only, README/CLAUDE excluded, mtime-desc sort,
  real size); `read_plan_file` (real content round-trips base64, media_type, rejects
  `notes.txt`/`README.md`/`CLAUDE.md`/nonexistent/`../../etc/passwd`/`nested/deep-plan.md`);
  `read_todo_file` (real content, `todo` filename, text/plain media_type, ok=false on no
  todo file). Cleanup hooks delete the fixture files/partial dirs on exit.
- **`tests/test_ui_smoke.php`** — HTTP-level assertions:
  - :727-745 — `/session_plan_file.php` binary-endpoint contract (200, real bytes,
    `text/markdown` content-type, inline content-disposition, 404 on unknown filename).
  - :866 — `#plan-files-list` present in the session page.
  - :921-932 — `#todo-list-section` wrapper present and the canned TodoWrite list renders all
    three tasks in order (completed checkmark + strikethrough, in_progress dot + activeForm).
  - :1107-1116 — `/session_plan_files.php` JSON shape (ok=true, canned names pass through,
    ok=false for an unrecognized session).
  - :1426-1427 — a brand-new session with no TodoWrite renders an **empty**
    `#todo-list-section`.
- **`tests/fixtures/canned_agent.php`** — the canned host-agent used by the UI smoke test
  provides `list_plan_files` (:243-248) / `read_plan_file` (:249-251) canned responses and the
  `todos` fixture in the canned session detail (:205-209).

## 7. Dependencies

**Upstream (what this depends on):**

- `HostAgent\Stores\SidecarStore` — `SidecarStore::read_sidecar($sessionName)` resolves the
  `workdir` for every method (PlanFileService.php:36, :119, :162). This is the sole source of
  the workdir; the client never supplies it.
- `HostAgent\Stores\SqliteDb` + `HostAgent\Services\Config` — indirect via `SidecarStore::db()`
  (`SqliteDb::connect(Config::sessions_sqlite_path(), ...)`); PlanFileService itself does not
  touch Config or the DB directly, it only reads the `workdir` the sidecar returns.
- PHP built-ins: `scandir`, `pathinfo`, `is_dir`/`is_file`, `filesize`/`filemtime`,
  `realpath`, `file_get_contents`, `base64_encode` — no external packages.

**Downstream (what depends on this):**

- `host-agent/lib/Sessions.php::dispatch_action()` dispatches directly into the three methods.
- Web-UI `AgentClient` calls flow into the three controller actions / routes.
- `src/partials/sidebar.php` + `src/lib/Views/TranscriptView::render_todo_list_html()`
  (:292) consume `todo-list.php` for the Tasks section (co-owned; the partial is rendered by
  `TransactView`, not by plan-files).

**Shared helpers (used, not owned):** `escapeHtml`/`relativeTimeLabel` (`public/js/common.js`
:359 / :477), `window.openFullscreenTextModal` (`common.js` :650, exposed at :832),
`Controller::stream_binary_result` (`src/lib/Controllers/Controller.php` :82).

## 8. Data & schema

No DB tables are written by this subsystem. It is file-system read-only and reads the
`workdir` from the `sidecars` SQLite table (via `SidecarStore`), whose `workdir` column is the
only persisted piece of state it consumes.

**Working directory file conventions (the glance's contract):**

- **Plan/handoff files** — any top-level `*.md` file in the session workdir, excluding
  `readme.md`/`claude.md` (case-insensitive). Never subdirectories. Shape per file:
  `{name:string, size:int, mtime:int}`.
- **`todo` file** — a no-extension file named exactly `todo` at the cwd level. No markdown
  subtitle; plain text.

**Todo partial data shape (`todo-list.php` reads a `$todos` array):**

```php
[
  ['content' => 'Find the redirect bug', 'activeForm' => 'Finding the redirect bug', 'status' => 'completed'],
  ['content' => 'Write a regression test', 'activeForm' => 'Writing a regression test', 'status' => 'in_progress'],
  ['content' => 'Update the changelog', 'activeForm' => 'Updating the changelog', 'status' => 'pending'],
]
```

`status` ∈ `{completed, in_progress, pending}`; the partial defaults the visible label to
`activeForm` when `in_progress`, else `content` (todo-list.php:8). The source of this array is
the `session-view` subsystem's `detail['todos']` cascade — `TranscriptService::find_latest_todo_list()`
(TodoWrite) or the Task-family finder, both normalizing to the same `{content, activeForm,
status}` shape (see `SessionDetailService.php` :52-64 and `TranscriptService.php` :1042+,
:1205+). plan-files only renders the markup — the data meaning belongs to `session-view`.

**Model/class shape:** no entity classes; everything is plain associative arrays over the
agent JSON protocol. The `todo-list.php` partial receives `['todos' => $todos]` and the two
agent actions return the arrays documented in §4.

## 9. Co-owned / cross-subsystem

Per the co-report model, the following are physically `session-view` but **feature-attributed
to `plan-files`** (recorded here as partitions, not owned by plan-files):

- **`src/lib/Controllers/SessionController.php` → plan-files:** `planFiles()` (:215,
  `/session_plan_files.php`), `planFileContent()` (:421, `/session_plan_file.php`),
  `todoFile()` (:440, `/session_todo_file.php`). (Recording them here; `session-view` also
  records them as partitions per the shared model.)
- **`public/js/sidebar.js`:** the plan-file/todo JS blocks — `planFilesList`/`todoFileLink`/
  `todoFileStatus` element refs (:358-360), `planFileRowHtml()` (:439), `loadPlanFiles()` (:448),
  the "Open todo file" click handler (:481-510, fetch + base64-decode → `openFullscreenTextModal`).
- **`public/js/session.js`:** `todoListSection` element ref (:31), `renderTodoList()` (:1158,
  the JS mirror of `todo-list.php`/`render_todo_list_html()`), and the `loadPlanFiles()` call
  in `pollOnce()` (:2081). `session.js` and `sidebar.js` must be kept in sync as PHP/JS mirrors
  of the same sidebar widgets.
- **`src/partials/sidebar.php`:** the DOM containers the plan/todo glance fills — the Tasks
  wrapper `#todo-list-section` (:61), the `#todo-file-link`/`#todo-file-status` pair (:63-68),
  and the `#plan-files-list` (:81-86), plus the "Plan/handoff files" section heading (:82).
- **`src/lib/Views/TranscriptView.php::render_todo_list_html()`** (:292) — renders
  `sidebar/todo-list` from `$detail['todos']`; returns `''` (zero-height section) when there
  are no todos.

**`todo-list.php` (owned here, rendered under `session-view`'s sidebar):** note this explicitly —
the partial is in `plan-files`' owned_paths per the boundary, but its *data source and meaning*
(transcript TodoWrite/Task-family tasks) belong to `session-view`. Keep the JS mirror
(`renderTodoList()`) in step with it when either changes.

## Conventions / quirks

- **Read-only by design** — no delete/rename/write in the owned boundary; cleanup stays a
  manual shell operation (commented at PlanFileService.php:24-26 and sidebar.js:437-438).
- **Workdir never trusted from the caller** — every method re-derives it from the sidecar
  server-side; the client only ever sends `session`/`filename` (and `filename` is re-validated
  against the `.md`/README/CLAUDE.md rules + a realpath boundary check). Same discipline as
  `UploadService::resolve_upload_path()`.
- **`base64` over the wire** — file content is base64-encoded in the agent JSON response and
  decoded client-side (`window.atob(...)` in sidebar.js:503) or re-streamed by
  `stream_binary_result` for the new-tab case.
- **Server-rendered vs. poll-time mirroring** — the `todo-list.php` PHP partial and
  `session.js::renderTodoList()` (and the plan-file list refreshed on sidebar open + each poll
  while open) share markup. Both must be kept in sync.
- **ES5 only** — `sidebar.js`/`session.js` use `var`/`function`, no `const`/`let`/arrows/
  template literals (mobile Safari compatibility, per repo CLAUDE.md). The new todo-file click
  handler and `loadPlanFiles()` follow this.
- **No CSRF on a read-only GET** — `planFiles()`/`todoFile()` use `start_readonly_json()` (no
  CSRF check needed, nothing mutated); `planFileContent()` uses `AuthService::start_app_session()`
  + `stream_binary_result(immutable: false)`. The glance is deliberately **not immutable**
  (plan/todo files are edited in place at the same filename), so no `immutable`/long max-age
  cache header (commented SessionController.php:416-418).
- **Config default project note** — the service itself doesn't hardcode project roots; test
  fixtures use `Config::www_root()`/`Config::home_root()` to pick a directory, but the
  production path always comes from the sidecar's `workdir`.
