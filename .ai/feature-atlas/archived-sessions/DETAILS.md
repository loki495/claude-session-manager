---
id: archived-sessions
name: Archived/dormant session listing, resume, transcript search
owned_paths:
  - host-agent/lib/Services/ArchivedSessionService.php
  - src/partials/pages/archived-session.php
  - src/partials/session-row/archived-row.php
  - src/partials/session-row/archived-list.php
  - src/partials/session-row/archived-empty-state.php
  - public/js/archived-session.js
last_scanned_commit: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# Archived/dormant session listing, resume, transcript search

## 1. Identity

- **id:** `archived-sessions`
- **name:** Archived/dormant session listing, resume, transcript search

This subsystem is everything that presents a session Claude Code (or
Antigravity, or OpenCode) still has a transcript for but that is **no
longer running**: the dashboard's "Show archived sessions" toggle, the
read-only `archived_session.php` detail view, and transcript **content
search** (per-session and dashboard-wide) across live and archived alike.
"Resume" here is scoped to a live action that only *leaves* this subsystem
(a POST to the dashboard, resolved by `session-core`'s
`SessionLifecycleService::resume_cc_session()`); this subsystem's own job is
to list dormant sessions and to browse/resume-flow them read-only.

## 2. Ownership boundary

**In scope (owned paths):**

- `host-agent/lib/Services/ArchivedSessionService.php` — the one service
  that owns the archived *listing* logic and all transcript *content* search.
- `src/partials/pages/archived-session.php` — the archived read-only page.
- `src/partials/session-row/archived-row.php` — one archived dashboard row
  (this IS the row's markup; the data assembler lives in `session-row/view`).
- `src/partials/session-row/archived-list.php` — the container: agent filter
  pills, title/folder client filter, load-more button.
- `src/partials/session-row/archived-empty-state.php` — the empty state.
- `public/js/archived-session.js` — the archived page's only JS (load-more,
  jump-to-search-result, in-page search).

**Out of scope (named neighboring subsystems, physically owned elsewhere):**

- `session-view` owns the archived **controller actions** and the shared
  **detail/history/attachment paging** (`src/lib/Controllers/SessionController.php`
  `showArchived`/`archivedHistoryFragment`/`archivedSearch`/`archivedAttachment`;
  `host-agent/lib/Services/SessionDetailService.php`). These are **CO-REPORTED**
  here with `path:line` — see section 9.
- `session-core` owns `Sessions.php::dispatch_action()` (the entry-point
  switch lines 37–96), `SessionService::title_cascade()`/`list_all_sessions()`,
  and `SessionLifecycleService::resume_cc_session()`.
- `session-status-state` owns the hooks/`SessionStatusStore` machinery — not
  touched here.
- `public/js/index.js` (dashboard) owns the archived-*toggle* client logic
  (`applyPagination()`, `filterArchivedRows()`, dashboard-wide content search
  renderer) — CO-REPORTED in section 9 because it consumes this subsystem's
  data and renders its rows, but the file itself is the dashboard's.
- `public/js/search.js` (session-view's sidebar search) also renders this
  subsystem's `search_transcripts` global results — co-consumer, not owned.

## 3. Key implementation files

- **`host-agent/lib/Services/ArchivedSessionService.php`** — Core logic.
  Lists dormant transcripts (`list_archived_sessions`), the dispatcher wrapper
  that self-derives the exclude set (`list_archived_dashboard`), the
  dashboard-wide content search (`search_transcripts`), the live per-session
  search (`session_transcript_search`), and its archived-keyed counterpart
  (`archived_session_transcript_search`), plus the private shared search core
  (`transcript_search_for_claude_session`).
- **`src/partials/pages/archived-session.php`** — The full-page read-only
  template: header with "Archived" badge + Unarchive form, per-conversation
  search box with results list, jump-to-result banner, and the history section
  rendered by `TranscriptView::render_transcript_entries_html()` with
  `$isArchived=true`.
- **`src/partials/session-row/archived-row.php`** — One archived dashboard
  row: absolute-stretched link to `archived_session.php`, agent badge, cwd,
  relative-time, and a Resume form (only when `cwd` is known). Backs BOTH the
  SSR render and the partial's server-side row builder.
- **`src/partials/session-row/archived-list.php`** — The archived list
  container with the agent filter pills, the client-side title/folder filter
  input, the `<ul id="archived-rows">`, the no-matches notice, and the
  client-side Load-more button.
- **`src/partials/session-row/archived-empty-state.php`** — The "No archived
  sessions." single-line empty state.
- **`public/js/archived-session.js`** — The page's only interactive JS:
  "Load older messages" (fetch a server-rendered HTML fragment), jump-to-line
  scroll for a search landing, and the in-page transcript search box. Written
  in ES5 (see `@ts-check` + `var`), deliberately small vs `session.js`.

## 4. Public interfaces & contracts

`ArchivedSessionService` (all `public static`, all in
`host-agent/lib/Services/ArchivedSessionService.php`):

### `list_archived_sessions(array $excludeClaudeSessionIds): array` — `:29`
Returns `array<int, array{claude_session_id:string, cwd:?string, title:string, last_activity:int, agent:string, agent_label:string}>`, sorted most-recently-active (`last_activity`) first.
- **Pre-condition:** `$excludeClaudeSessionIds` are the currently-tracked, live
  session IDs already shown in the main list; they are excluded.
- **Sources:** Claude `TranscriptService::list_all_transcripts()` (`:34`) for
  Claude Code; `AntigravityTranscriptService::list_all_transcripts()` (`:49`);
  OpenCode via a **read-only PDO** against `Config::opencode_db_path()`
  (`:70-101`, `PRAGMA busy_timeout=5000`, `SQLITE_OPEN_READONLY`) — best-effort,
  silently skipping on any `\Throwable` (`:98`).
- **Title:** `SessionService::title_cascade()` — claude/antigravity use
  `ai_title`; opencode uses `session.title` directly (falling back to `$id`),
  see `:42`, `:57`, `:88`.
- **Post-condition:** never throws; `agent` is `'claude' | 'antigravity' |
  'opencode'` and `agent_label` the display name.

### `list_archived_dashboard(): array` — `:122`
Returns `['archived' => array<int, array>]`.
- Re-runs `SessionService::list_all_sessions()` to build the exclude set itself
  (`:126`), so the caller need not thread a pre-computed list through a request
  parameter. On-demand only (never part of the regular poll — docblock `:109`).
- **Pre-condition:** internal; the exclude set is the live sessions' IDs.
- **Post-condition:** `['archived' => list_archived_sessions($trackedIds)]`.

### `search_transcripts(string $query, int $maxSessions, int $maxMatchesPerSession): array` — `:153`
Returns `['ok'=>bool, 'results'=>array<int, array{claude_session_id:string, session_name:?string, title:string, cwd:?string, last_activity:int, matches:array<int, array{line:int, snippet:string, role:?string, kind:string}>}>]`.
- Searches **every known transcript, live and archived alike**, server-side.
  Builds the live-name map first (`:159-165`) so a result's `session_name`
  (non-null ⇒ link to `session.php`, null ⇒ link to `archived_session.php`)
  tells the client which page to link to.
- Empty/whitespace query ⇒ `['ok'=>true, 'results'=>[]]` (`:155-157`).
- Per-session match count clamped to `max(1, $maxMatchesPerSession)` at the
  `search_transcript_file` call (`:173`); overall count capped at
  `$maxSessions` (`:188`).
- **Post-condition:** never errors on a missing/empty file (it just yields no
  result), fully on-demand only.

### `session_transcript_search(string $name, string $query, int $maxMatches): array` — `:204`
Returns `['ok'=>bool, 'matches'?:array, 'message'?:string]`. Resolves a live
tmux `$name` to its `claude_session_id` via `SidecarStore::read_sidecar()`
(`:206`); if none, returns `['ok'=>false, 'message'=>'No transcript recorded
for this session']` (`:209-211`). Otherwise defers to the private
`transcript_search_for_claude_session` (`:213`).

### `archived_session_transcript_search(string $claudeSessionId, string $query, int $maxMatches): array` — `:223`
Same shape, keyed straight by `$claudeSessionId` with **no** sidecar/tmux-name
lookup (a dormant session has neither). Defers to the same private core.

### `transcript_search_for_claude_session(string $claudeSessionId, string $query, int $maxMatches): array` — `:236` (private)
Shared core for the two public search wrappers. Resolves the path via
`TranscriptService::find_transcript_path()` (`:238`); unknown ⇒
`['ok'=>false, 'message'=>'Transcript file not found']` (`:240-242`). Match
count clamped to `max(1, min($maxMatches, 100))` (`:244`).

## 5. Major call sites

**Host-agent consumers:**

- `host-agent/lib/Sessions.php` `dispatch_action()` — the entry-point switch
  wiring every action string to the service:
  - `list_archived` → `list_archived_dashboard()` (`:37-38`)
  - `search_transcripts` → `search_transcripts()` (`:63-68`)
  - `session_transcript_search` → `session_transcript_search()` (`:70-75`)
  - `archived_session_transcript_search` → `archived_session_transcript_search()` (`:77-82`)
  - `archived_session_detail`/`archived_session_history`/`archived_session_attachment`
    → `SessionDetailService` (CORE/co-owned, lines 43–44, 55–61, 91–96) — those
    are the read-only page + paging + attachment path, physically in `session-view`.
- `host-agent/lib/Services/BareProcessService.php` `:163` — the take-over-bare
  candidate list calls `ArchivedSessionService::list_archived_sessions($trackedIds)`,
  filtered by matching `cwd`, to suggest which dormant transcript most likely
  owns a bare process. (This is a listing consumer, not a browse consumer.)

**Web/container consumers (over the `AgentClient` socket, `session-view` controller actions — CO-REPORTED):**

- `src/lib/Controllers/DashboardController.php`:
  - `archivedFragment()` `:244-262` → `action=list_archived`, renders the
    archived rows via `SessionRowView::archived_sessions_html()`. Lazily fetched
    only when the toggle opens (never in the poll).
  - `search()` `:273-285` → `action=search_transcripts` with
    `max_sessions=30`, `max_matches_per_session=3`.
- `src/lib/Controllers/SessionController.php`:
  - `showArchived()` `:95-141` → `action=archived_session_detail` + `archived_session_history`.
  - `archivedHistoryFragment()` `:152-180` → `action=archived_session_history`.
  - `archivedSearch()` `:278-288` → `action=archived_session_transcript_search`.
  - `archivedAttachment()` `:401-411` → `action=archived_session_attachment`.

**Front-end callers:**

- `public/js/index.js` `:908-1038` — dashboard-wide content search
  (`/search_sessions.php`) renders `search_transcripts()` results, linking to
  `session.php` vs `archived_session.php` by `session_name`. Its
  `:725-906` archived-toggle handler fetches `/archived_sessions_fragment.php`,
  renders the rows (via `SessionRowView`, CO-REPORTED `SessionRowView.php:169-205`),
  paginates with `applyPagination()` and filters with `filterArchivedRows()`.
- `public/js/search.js` `:56-82` — sidebar's global-scope search reuses the
  same `search_transcripts()` shape to render global results.
- `public/js/archived-session.js` `:20-57` (load-more), `:70-96` (jump-to-line),
  `:103-173` (in-page search) — feeds `archived_session_*` endpoints.

## 6. Tests

- **`tests/test_transcript.php`** — unit-level, happy+sad path. Covers
  `ArchivedSessionService::list_archived_sessions` (`:475-494`: exclude set,
  empty exclude, ai-title fallback, mtime sort),
  `archived_session_transcript_search` (`:539-548`: ok / zero-matches /
  unknown-id), `search_transcripts` (`:559-576`: match filtering, `session_name`
  null for untracked, per-session cap, empty query, no-match), and the
  `SessionDetailService` archived detail/history (`:582-598`). It also exercises
  the underlying `TranscriptService::search_transcript_file()` (`:512-534`,
  incl. the raw-line-only-tool-id false-positive trap and case-insensitivity).
- **`tests/test_ui_smoke.php`** — HTTP-layer, hit the canned-agent fixtures.
  Covers `/archived_sessions_fragment.php` (`:242-252`: canned title/cwd, Resume
  form, Load-more button), `/search_sessions.php` archived result `session_name`
  null + Archive/Unarchive (`:1123-1125`), `/archived_session_search.php`
  (`:1145-1153`: ok + unknown-id), `archived_session.php` jump_line banner
  (`:1168-1173`), the read-only view (`:1241-1274`: 303 no-param redirect, canned
  title/cwd/history, "Archived" badge, **no compose-bar**, unknown-id "Session
  not found"), `/archived_session_history_fragment.php` (`:1279-1291`),
  `/archived_session_attachment.php` (`:1295-1302`: bytes + Content-Disposition +
  404 on bad uuid), and a headless-DOM load-more control (`:1505-1511`).
- **`tests/test_agent_client_protocol.php`** — socket seam: `list_archived`,
  `archived_session_detail`, `archived_session_history` over `AgentClient`
  (`:67-79`), asserting `ok=false` (not a crash) for well-formed-but-unknown IDs.
- **`tests/test_sessions_lifecycle.php`** — shared in-process integration
  harness (exercises `dispatch_action()`'s underlying functions; no socket).
  CO-REPORTED: it imports `ArchivedSessionService` and `SessionDetailService`
  (`:16, 25`) and is the broader archived+search integration harness, not
  wholly owned here.

Overall shape: **happy + sad path** — every test file includes unknown-id /
no-match / missing-file assertions, not just the happy path.

## 7. Dependencies

**Upstream (produces this subsystem's inputs):**

- `TranscriptService` (transcript reading/search; shared with `session-view`):
  `list_all_transcripts()`, `find_transcript_path()`, `find_first_cwd()`,
  `find_latest_ai_title()`, `search_transcript_file()`.
- `SessionService`: `title_cascade()`, `list_all_sessions()` (for the exclude set
  and the live-name map).
- `AntigravityTranscriptService::list_all_transcripts()`,
  `OpenCodeTranscriptService` (via `Config::opencode_db_path()` + `is_opencode_id()`).
- `Config::opencode_db_path()` (read-only SQLite source).
- `BareProcessService` consumes `list_archived_sessions()` (reverse dependency —
  see section 5).

**Shared/co-owned (physically in `session-view`, used by both):**

- `SessionDetailService` — `archived_session_detail`/`archived_session_history`/
  `archived_session_attachment` and the private `transcript_page_for_claude_session`/
  `read_attachment_for_claude_session` helpers used by the archived view.
- `TranscriptRouter` — `find_transcript_path()`, `read_transcript_page()`,
  `read_transcript_page_since()`, `read_attachment()` — reached through
  `SessionDetailService`.

**Web-side:**

- `AgentClient::agent_call()` — the container↔host-agent socket seam; this
  subsystem has no other transport.
- `App\Views\TranscriptView::render_transcript_entries_html()`,
  `attachment_url()` (renders the archived-attachment URL at
  `TranscriptView.php:87-94`), `render_transcript_entry()`/`render_transcript_block()`.
- `App\Views\SessionRowView::archived_session_row_html()` /
  `archived_sessions_html()` (`SessionRowView.php:169-205`) — builds the
  `archived-row.php`/`archived-list.php`/`archived-empty-state.php` markup. CO-REPORTED.
- `App\Views\PageView::render_archived_session_page()` (`PageView.php:39-42`).
- `App\Assets::versioned_url()`, `AuthService` (CSRF via the guard helpers).
- JS shared helpers from `common.js`: `openAncestorDetails()`, `escapeHtml`,
  `relativeTimeLabel()`, `highlightSnippet()`, `wireClearButton()`, plus
  `copyTextToClipboard()` (the `.copy-btn`/`.copy-source` path).

**No external packages beyond Composer PSR-4. No DB write — OpenCode access is
read-only SQLite; everything else is filesystem reads.**

## 8. Data & schema

**No database schema is owned here.** Three transcript sources, all read-only:

1. **Claude Code** — `~/.claude/projects/<encoded-cwd>/<session-id>.jsonl`
   (`Config::home_root() . '/.claude/projects'`), globbed by
   `TranscriptService::list_all_transcripts()`. Archival identity is the
   **transcript filename = claude_session_id UUID** (validated UUID-shaped in
   `find_transcript_path()`). Per-transcript fields: `claude_session_id`,
   `cwd` (from `find_first_cwd()`), `ai_title` (from `find_latest_ai_title()`),
   `last_activity` (filesystem mtime), `path`.
2. **Antigravity** — `AntigravityTranscriptService::list_all_transcripts()`
   (under `~/.gemini/antigravity-cli/brain`), same row shape.
3. **OpenCode** — `Config::opencode_db_path()` (default
   `~/.local/share/opencode/opencode.db`), read-only PDO:
   `SELECT id, directory, title, time_updated FROM session ORDER BY time_updated DESC`
   (`:77`). `id` → `claude_session_id` (filtered by `is_opencode_id`), `directory`
   → `cwd`, `title` → archived title, `time_updated` → `last_activity = /1000`.

**Shared archive row shape** (`list_archived_sessions` return):
`{claude_session_id, cwd, title, last_activity:int, agent, agent_label}`.

**Transcript search index** (`search_transcript_file` return, per match):
`{line:int (1-based JSONL line), snippet:string (whitespace-collapsed, ±60-char
context around first hit), role:?string, kind:string, timestamp:?int (Unix secs
when the line carried an ISO timestamp)}`. No persistent index — it's a
per-request grep (two-stage: cheap `stripos` on the raw line, then on parsed
block text to drop metadata-only hits).

**Search result shapes (this subsystem's DTOs):**
- `search_transcripts` result: `{claude_session_id, session_name:?string, title,
  cwd:?string, last_activity:int, matches:array}`.
- archived/live per-session search: `{ok, matches?|message?}`.

**Read-only curl** for the archived page uses `before`/`limit` cursor paging
(`archived_session_history`, `limit` clamped to 200 in
`transcript_page_for_claude_session`; the controller passes `limit=30`), with
`before = jumpLine + 1` to land a page ending exactly at a search result line.

## 9. Co-owned / cross-subsystem

These are physically owned by **`session-view`** (and the dashboard's `index.js`),
but are this subsystem's feature-specific code and are **REPORTED here** per the
co-report model. No physical split; the file is owned once, reported twice.

- **`src/lib/Controllers/SessionController.php`** — the archived action surface
  (the live/archived HTTP seam for this subsystem):
  - `showArchived()` `:95-141` — full-page render keyed by `claude_session_id`;
    builds `archived_session_detail` + `archived_session_history` calls, reuses
    `show()`'s `jump_line` handling.
  - `archivedHistoryFragment()` `:152-180` — GET JSON for "Load older messages";
    renders to server-side HTML (unlike `session_history.php`'s raw JSON) since a
    dormant session never gets new messages.
  - `archivedSearch()` `:278-288` — GET JSON, keyed by `claude_session_id`.
  - `archivedAttachment()` `:401-411` — binary endpoint, keyed by
    `claude_session_id`, `stream_binary_result(..., immutable:true)`.
  - Routed in `src/routes.php:46-50` (`/archived_session.php` GET+POST,
    `/archived_session_history_fragment.php`, `/archived_session_attachment.php`,
    `/archived_session_search.php`).
- **`host-agent/lib/Services/SessionDetailService.php`** — shared detail/history
  paging, co-owned between live and archived:
  - `archived_session_history()` `:126-137` (includes `cwd` for path relativization).
  - `archived_session_detail()` `:175-194` (header data; deliberately does NOT
    reject a live session's id — harmless, page just shows slightly stale data).
  - `archived_session_attachment()` `:236-239`.
  - Private shared helpers `transcript_page_for_claude_session()` `:146-159` and
    `read_attachment_for_claude_session()` `:248-257`.
- **`host-agent/lib/Sessions.php`** `dispatch_action()` — the entry points
  `list_archived`/`archived_session_detail`/`archived_session_history`/
  `search_transcripts`/`session_transcript_search`/
  `archived_session_transcript_search`/`archived_session_attachment`
  (`:37-96`), wired over to the services.
- **`src/lib/Controllers/DashboardController.php`** `archivedFragment()` `:244-262`
  and `search()` `:273-285` — the dashboard-side wiring for the archived toggle
  and the global content search (co-owned with the dashboard subsystem).
- **`src/lib/Views/SessionRowView.php`** `archived_session_row_html()` `:169-180`
  and `archived_sessions_html()` `:190-205` — assembles the owned
  `archived-row.php`/`archived-list.php`/`archived-empty-state.php` markup.
- **`public/js/index.js`** `:725-906` (archived toggle: `applyPagination`,
  `filterArchivedRows`, agent filter buttons) and `:908-1038` (dashboard-wide
  content search renderer).
- **`public/js/search.js`** `:56-82` — global-scope sidebar search rendering of
  `search_transcripts()` results.
- **Tests:** `tests/test_sessions_lifecycle.php` (shared in-process integration
  harness for archived + search), `tests/test_transcript.php`,
  `tests/test_ui_smoke.php`, `tests/test_agent_client_protocol.php` — all cover
  this subsystem; reported as shared, not wholly owned here.

## 10. Conventions & quirks

- **Search across live + archived, server-side.** The dashboard's
  `/search_sessions.php` and sidebar-global search grep every known transcript's
  real message content via `search_transcripts()`; the archived *list*'s own
  filter (`filterArchivedRows()` in `index.js`) is deliberately only a
  client-side title/folder substring filter over already-rendered rows — it can
  never reach content that's paginated away. See
  `ArchivedSessionService::search_transcripts()` `:135-151` and
  `index.js:908-919`. Note: the `session_name` (live) vs null (archived) field is
  the sole signal telling every client which page (`session.php` vs
  `archived_session.php`) a search result links to.
- **Resume is read-only from this subsystem's side; the only action is the
  Unarchive/Resume POST.** The archived page and rows render a Resume/Unarchive
  button **only when `cwd` is known** (an absolute workdir is required to spawn
  into; `SessionRowView.php:160-166`). A null-cwd archived row just omits the
  button rather than posting a workdir-less resume the agent would reject.
- **On-demand, never periodic.** Every expensive path here (the `~/.claude/projects`
  scan for the archived list, the transcript-content grep) is explicitly
  user-triggered only (`list_archived_dashboard` `:108-114`,
  `search_transcripts` `:135-143`) — consciously kept out of the dashboard's
  regular poll. The archived page also has **no live poll** at all: a dormant
  session never changes.
- **Server-rendered fragments instead of client rendering.** Unlike
  `session.php`'s live load-more (raw JSON entries, rendered by `session.js`'s
  `renderEntry()` so polled-in new messages reuse it),
  `archivedHistoryFragment()` ships pre-rendered HTML and `archived-session.js`
  only `innerHTML`s it into the list (`archived-session.js:1-58`) — there's no
  client-side renderer to duplicate for a session that can't change.
- **`jump_line` landing.** `showArchived()` loads the page ending exactly at the
  jump line (`before = jumpLine + 1`), then `archived-session.js:70-96` finds
  `[data-line="<jumpLine>"]`, calls `openAncestorDetails()` (a jump target inside
  a collapsed tool-call `<details>` gets a meaningless zeroed rect while closed)
  and scrolls via computed `getBoundingClientRect()` (not `scrollIntoView()`,
  which no-ops in at least one headless-Chrome context). Unlike `session.php`,
  no live poll backfills anything newer — the reader uses the "Back to latest"
  banner instead (`archived-session.php:59-66`).
- **`data-line` + `.copy-btn`/`.copy-source` are carried automatically.** The
  archived page renders history via the same
  `TranscriptView::render_transcript_entries_html()`/`transcript/block.php`
  path as live session.php, so every block inherits `data-line="<line>"` and the
  `.copy-btn`/`.copy-source` pair (`transcript/block.php:8-14`) — search-jump and
  copy keep working for archived content with no extra code.
- **`data-agent` drives the row badge + filter.** `archived-row.php:1` emits
  `data-agent="<agentId>"`; the archived-list filter pills guard on it and
  `index.js:776-796`/`:823-834` match rows by it. `Agent: claude/antigravity/opencode`
  comes from the archive row's `agent` field.
- **ES5 only.** `public/js/archived-session.js` uses `var`/`function`, no arrow
  functions/`const`/`Set`/template literals, matching the whole `public/js/`
  convention (no transpiler; mobile Safari has repeatedly been the reason).
- **iOS text-input 16px rule.** The archived page's search input uses
  `text-base` (not `text-sm`) and `appearance-none` to avoid iOS viewport
  auto-zoom and the native WebKit search-cancel button
  (`archived-session.php:44-49`; same note in `archived-list.php:8-9`).
- **Dense docblocks record live findings.** This project's convention — the
  archived code's docblocks carry "found live 2026-08-20/22", "split out of
  SessionService.php (2026-08-24 readability audit)", and the periodic-vs
  user-triggered reminder; read them before assuming something is over-engineered.
