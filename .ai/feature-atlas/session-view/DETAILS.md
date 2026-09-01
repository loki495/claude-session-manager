---
id: session-view
name: Session detail page — transcript reading & rendering, history paging, attachments, markdown
owned_paths:
  - host-agent/lib/Services/TranscriptService.php
  - host-agent/lib/Services/AntigravityTranscriptService.php
  - host-agent/lib/Services/OpenCodeTranscriptService.php
  - host-agent/lib/Services/TranscriptRouter.php
  - host-agent/lib/Services/SessionDetailService.php
  - src/lib/Views/MarkdownRenderer.php
  - src/lib/Views/TranscriptView.php
  - src/lib/Controllers/SessionController.php
  - src/partials/transcript/*
  - src/partials/sidebar.php
  - src/partials/sidebar/todo-list.php
  - src/partials/compose-bar.php
  - src/partials/pages/session.php
  - public/js/session.js
  - public/js/sidebar.js
  - public/js/highlights.js
  - public/js/scroll.js
  - public/js/search.js
  - public/js/markdown.js
  - tests/test_transcript.php
  - tests/test_antigravity_transcript.php
  - tests/test_opencode_transcript.php
  - tests/test_markdown.php
  - tests/test_markdown_parity_browser.php
  - tests/fixtures/transcript_sample.jsonl
last_scanned_commit: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# session-view — Session detail page: transcript reading & rendering, history paging, attachments, markdown

## 1. Purpose

This is the **read-only transcript-reading + session-page-rendering layer**. It spans both runtimes
(container web UI + host-native agent; see repo CLAUDE.md's two-runtime protocol seam):

- **Host-agent, read-only (never writes a transcript):** `TranscriptService` (Claude Code JSONL),
  `AntigravityTranscriptService` (Antigravity `transcript_full.jsonl`), `OpenCodeTranscriptService`
  (OpenCode SQLite) all normalize to the **same canonical entry shape**
  `{type, role, timestamp, blocks:[{kind,text,...}]}` and expose the same paging contract.
  `TranscriptRouter` dispatches a session id to the right backend **by path/id shape, not a
  passed-in agent id** (Claude Code/Antigravity = UUID-with-dashes, OpenCode = `ses_*`), so no
  `$agent` param threads through call sites. `SessionDetailService` is the "detail / history /
  attachment" facade that `Sessions.php::dispatch_action()` routes `session_detail` /
  `session_history` / `session_attachment` and their `archived_*` twins to.
- **Container web UI:** `SessionController` (full-page render for `/session.php` +
  `/archived_session.php` plus the JSON/fragment endpoints), `TranscriptView` (PHP render layer),
  `MarkdownRenderer` (small markdown renderer), the `transcript/*` + `sidebar.php` +
  `compose-bar.php` + `pages/session.php` templates, and the session-page JS
  (`session.js` + the `markdown.js`/`scroll.js`/`highlights.js`/`sidebar.js`/`search.js` modules
  extracted out of it).

**Out of scope** (sibling subsystem consumers, never owned here): prompt parsing/answering/navigation
(`prompt-interaction`, `PromptParser`/`PromptInteractionService`); session model + tmux/process
primitives (`session-core`, `SessionService`/`TmuxService`/`ProcessRunner`/`ProcessInspector`);
hook-fed live status (`session-status-state`, `SessionStatusStore`/`PendingToolStore`/hook scripts —
the source of `working`/`blocked_reason`/`last_turn_error` this page renders); the full-page
`PageView` templates; archived-session *listing/search* (`archived-sessions`, `ArchivedSessionService`);
uploads + plan-file listing (`uploads`, `plan-files`).

## 2. Ownership boundary

**In scope (owned paths):** the five `host-agent/lib/Services` transcript/detail files;
`src/lib/Views/MarkdownRenderer.php` + `public/js/markdown.js` (PHP/JS markdown pair);
`src/lib/Views/TranscriptView.php`; `src/lib/Controllers/SessionController.php` (**dominant owner**,
many methods co-reported — §8); `src/partials/transcript/*` (block, entry, attachment, attachments,
image, tool-call-entry, mode-toggle, model-toggle, antigravity-model-toggle, thinking-indicator,
turn-error); `src/partials/sidebar.php`, `sidebar/todo-list.php`, `compose-bar.php`,
`pages/session.php`; `public/js/session.js`, `sidebar.js`, `highlights.js`, `scroll.js`, `search.js`,
`markdown.js`; tests `test_transcript.php`, `test_antigravity_transcript.php`,
`test_opencode_transcript.php`, `test_markdown.php`, `test_markdown_parity_browser.php`; fixture
`tests/fixtures/transcript_sample.jsonl`.

**Out of scope — explicitly named neighbors:** `session-core`, `session-status-state`,
`prompt-interaction`, `archived-sessions`, `uploads`, `plan-files`, `host-agent-runtime`
(`Config`/`SqliteDb`/`SidecarStore`), `agent-abstraction` (`SelectableModel`/
`AntigravitySelectableModel`/`AgentRegistry`). `MarkdownRenderer`/`markdown.js` and the `transcript/*`
templates used underneath `BlockedPromptView` rendering are **shared** — see §8.

## 3. Key implementation files

| File | Responsibility |
|------|----------------|
| `host-agent/lib/Services/TranscriptService.php` | Claude Code JSONL reader: path discovery, cwd/timestamp/title/model/todo/task extraction, `parse_transcript_line()`, backward+forward page reads, attachment re-read, search; the 7 cross-backend cap constants. |
| `host-agent/lib/Services/AntigravityTranscriptService.php` | Antigravity `transcript_full.jsonl` reader (`USER_INPUT`/`PLANNER_RESPONSE`/`GENERIC-MODEL`) → canonical shape + paging. Attachments/todo/task are stubs/unimplemented. |
| `host-agent/lib/Services/OpenCodeTranscriptService.php` | OpenCode SQLite reader (`session`/`message`/`part` tables) → canonical shape + paging + title + reactive workdir→`ses_*` bind + pending-question read. Attachments stub. |
| `host-agent/lib/Services/TranscriptRouter.php` | Static dispatcher: `find_transcript_path()` tries Claude→Antigravity→OpenCode; paging/attachment route by path shape. |
| `host-agent/lib/Services/SessionDetailService.php` | The `session_detail`/`session_history`/`session_attachment` + `archived_*` facade; OpenCode sidecar reactive-bind heal; shared private paging/attachment helpers. |
| `src/lib/Views/TranscriptView.php` | PHP render layer: batch renderer (tool-call pairing), per-entry/block renderer, `entry_color_kind()`/`entry_color_classes()`, attachments/images, mode/model toggles, todo-list, thinking/turn-error, blocked-prompt wrapper. |
| `src/lib/Views/MarkdownRenderer.php` | Small markdown→HTML renderer (bold/italic/inline-code/fenced-code/lists) — mirrored line-for-line by `public/js/markdown.js`'s `renderMarkdown()`. |
| `src/lib/Controllers/SessionController.php` | `show()`/`showArchived()` full pages + the JSON/fragment endpoints for detail/history/search/attachment/plan/todo. Send/answer/mode/model/escape are co-reported → `prompt-interaction`. |
| `src/partials/pages/session.php` | Session detail template: history list, load-older/load-until-last buttons, thinking/turn-error/blocked sections, floating scroll buttons, `CSM_BOOTSTRAP` inline data, module `<script>` loads. |
| `src/partials/sidebar.php` + `sidebar/todo-list.php` + `compose-bar.php` | Sidebar (search, other-sessions list, settings toggles, todos, uploaded files, plan files, archive), task-checklist partial, compose bar (textarea, attach, send, mode/model toggles, quota/push rows). |
| `src/partials/transcript/*` | The per-kind templates (block/entry/attachment/attachments/image/tool-call-entry/mode/model/antigravity-model/thinking-indicator/turn-error). |
| `public/js/session.js` | The page IIFE: SSR-parity renderers, live `pollInfo`/`pollHistory`/`pollOnce`/`startPolling`, optimistic pending-entry reconciliation, compose/send/attach, mode/model handlers, search-result jump, blocked-prompt answer wiring. |
| `public/js/markdown.js` | The JS mirror of `MarkdownRenderer` — `renderMarkdown()` + `mdXxx()` helpers, extracted out of session.js 2026-08-24. |
| `public/js/scroll.js` | Session-page scroll primitives (isNearBottom/Top, scrollToBottom/Top, button visibility, maybeAutoScroll, footer-height watcher). |
| `public/js/highlights.js` | New/older/jump-target highlight rings + fade observers, "new" divider, jump-to-new button. |
| `public/js/sidebar.js` | Sidebar open/close + swipe, other-sessions poll & badge, show-subagent toggle, uploaded-files + plan-files + todo-file loaders, notify dot, session-done state. |
| `public/js/search.js` | Session/global search box (`session_search.php`/`search_sessions.php`), debounced runner + result renderers. |

## 4. Public interfaces & contracts

### Host-agent reading layer

**`TranscriptService`** (all `static`; nothing writes):

- Cross-cutting caps, shared by all three backends: `TRANSCRIPT_BLOCK_HARD_CAP_LENGTH=50000` (:23),
  `TOOL_USE_SUMMARY_LINE_MAX=80` (:31), `TRANSCRIPT_IMAGE_MAX_BASE64_LENGTH=8_000_000` (:38),
  `ATTACHMENT_MAX_BYTES=64*1024*1024` (:44), `AI_TITLE_TAIL_SCAN_BYTES=262144` (:53),
  `TODO_LIST_TAIL_SCAN_BYTES=262144` (:59), `FIRST_CWD_SCAN_LINES=20` (:66),
  `UNTIL_USER_MESSAGE_MAX_ENTRIES=300` (:77).
- `claude_projects_dir(): string` (:79) — `Config::home_root() . '/.claude/projects'`.
- `transcript_meta_only_types(): array` (:96) — JSONL `type`s with no `message` payload.
- `find_transcript_path(string $claudeSessionId): ?string` (:110) — UUID-shape regex guard, then
  `glob("<projects>/*/<id>.jsonl")`. Returns path or `null`.
- `find_first_cwd(string $path): ?string` (:135) — first real line's `cwd`, streamed, cap 20.
- `find_first_timestamp(string $path): ?int` (:178) — first real line's `timestamp` as Unix epoch int.
- `list_all_transcripts(): array` (:222) — `[{claude_session_id, cwd, ai_title, last_activity, path}]`.
- `search_transcript_file(string $path, string $query, int $maxMatches): array` (:272) —
  `[{line, snippet, role, kind, timestamp}]`, newest-first. Two-stage matching (raw `stripos` then
  parsed-block `stripos`) so a metadata-only raw hit never false-matches.
- Tool-use summarizers: `tool_use_primary_arg_keys()` (:375), `humanize_tool_name()` (:387, MCP
  `mcp__srv__tool`→`srv.tool`), `summarize_ask_user_question()` (:406), `summarize_agent_tool_use()`
  (:454), `is_subagent_metadata_text()` (:484), `parse_task_notification()` (:506 —
  `{status,summary,result}` or null), `tool_use_param_lines()` (:536)/`tool_use_param_line()` (:564)/
  `format_tool_use_summary()` (:584), `tool_use_description()` (:615), `summarize_tool_use()` (:630 —
  `"tool: X - key: value"` or multi-line `Params:`), `summarize_content_block()` (:666 — canonical
  `{kind, text}` + optional `image`/`description`/`agent_type`/`tool_name`/`file_path`/`command`).
- Result/image/attachment extractors: `transcript_tool_result_text()` (:750, drops subagent-metadata),
  `transcript_image_from_block()` (:781), `transcript_attachments_from_tool_use_result()` (:813 —
  `[{file_uuid, filename, size, isImage, media_type}]` from `toolUseResult.attachments`),
  `transcript_tool_result_image()` (:845), `find_exit_plan_mode_tool_use_ids()` (:881 — id map for the
  rejected-plan case).
- Title/model/task readers: `find_latest_ai_title()` (:930, tail-scan), `find_latest_model()` (:995,
  newest assistant `message.model`), `find_latest_todo_list()` (:1067), `find_current_task_list()`
  (:1285 — TaskCreate/TaskUpdate CRUD replay over the whole file via the structured `toolUseResult`;
  `null` = never called TaskCreate, `[]` = emptied).
- `parse_transcript_line(string $line, array $exitPlanModeToolUseIds = []): ?array` (:1534) — **one
  JSONL line → `{type, role, timestamp, blocks}`** or `null` (meta/malformed/content-less/thinking-only).
  Applies block cap, threads `agent_type`/`attachments`/`plan_status` onto tool_result blocks.
- `read_transcript_page(string $path, ?int $before, int $limit, bool $untilRealUserMessage=false): array`
  (:1682) — `{ok, entries, next_before, has_more, message?}`. Newest-first walk / oldest-first out;
  `$before` is a 1-indexed raw-line cursor; `$limit` counts *renderable* entries. When
  `$untilRealUserMessage`, ignores `$limit` for `UNTIL_USER_MESSAGE_MAX_ENTRIES` and stops at a real
  user message.
- `read_transcript_page_since(string $path, int $afterLine, int $limit): array` (:1760) —
  `{ok, entries, message?}`, forward, every entry `line > $afterLine`.
- `read_attachment(string $path, int $line, string $fileUuid): array` (:1796) — `{ok, message?,
  data?, media_type?, filename?, size?}`; `$data` is base64 of the real file bytes; path re-derived
  from the transcript, never trusted from the browser.

**`AntigravityTranscriptService`:**

- `find_transcript_path(string $conversationId): ?string` (:49) — UUID guard +
  `Config::antigravity_transcript_path()` (`is_file`); `conversationId` **is** the session id.
- `list_all_transcripts(): array` (:73) — `[{claude_session_id, cwd:null, ai_title:null,
  last_activity, path, agent:'antigravity'}]` (cwd/title are null — Antigravity doesn't embed them).
- `parse_transcript_line(string $line): ?array` (:172) — maps `USER_INPUT`/`PLANNER_RESPONSE`/
  `GENERIC(source:MODEL)` to canonical shape; strips `<USER_REQUEST>` wrappers
  (`extract_user_request_text` :121); skips `CHECKPOINT`; `run_command` → `tool_name:'Bash'` +
  `command` so `tool_call_entry_summary()` renders "Ran <command>".
- `read_transcript_page` / `read_transcript_page_since` (:264 / :308) — same contract; no exit-plan
  id-map; `$untilRealUserMessage` stops on `role==='user' && type==='USER_INPUT'`.
- `read_attachment` (:340) — stub `{ok:false, message:'Attachments are not supported for Antigravity
  sessions yet'}`.

**`OpenCodeTranscriptService`:**

- `find_transcript_path(string $sessionId): ?string` (:48) — `ses_*` guard (regex at :37) + DB
  presence check (`SELECT id FROM session WHERE id=?`); returns the id itself as the "path".
- `is_opencode_id(string $sessionId): bool` (:72) — pure shape test for the router.
- `open_db_readonly(): ?\PDO` (:87, private) — `Config::opencode_db_path()`, `SQLITE_OPEN_READONLY`,
  `busy_timeout=5000`; null if DB missing/unopenable.
- `tool_part_to_blocks()` (:122, private) / `message_to_entry()` (:182, private) — one OpenCode part
  (call+output stored together) → up to two blocks; one message row → one entry; skips synthetic
  echoes + `step-start`/`step-finish`/`reasoning`/`file` parts.
- `read_transcript_page` / `read_transcript_page_since` (:263 / :357) — same contract; "line" is a
  1-indexed message position.
- `find_session_title(string $sessionId): ?string` (:431) — `session.title`.
- `find_session_for_workdir(string $workdir, int $spawnedAt): ?string` (:463) — reactive bind: newest
  `session` row whose `directory` matches and `time_created/1000 >= $spawnedAt`, skipping ids already
  bound to another live `oc-*` sidecar.
- `find_pending_question(string $sessionId): ?array` (:523) — newest `question` tool's
  `{question, header, options:[{number,label}]}` with a live-verified staleness guard (only the newest
  `question` tool is authoritative; a newer "completed" one clears an older "running" remnant).
- `read_attachment` (:620) — stub.

**`TranscriptRouter`:**

- `find_transcript_path(string $sessionId): ?string` (:28) — Claude→Antigravity→OpenCode `??` chain.
- `is_antigravity_path(string $path): bool` (:35) / `is_opencode_path(string $path): bool` (:40).
- `read_transcript_page` (:48) / `read_transcript_page_since` (:62) / `read_attachment` (:76) — route
  by path shape to the right backend.

**`SessionDetailService`:**

- `session_detail(string $name): array` (:29) — `{ok, message?, has_transcript?, todos?, ...entry}`.
  Re-derives from a live tmux scan by name (never trusts the caller), calls
  `SessionService::build_session_entry()`, resolves the path via `TranscriptRouter`, then the
  todo/task cascade (`find_current_task_list()` preferred, `find_latest_todo_list()` fallback).
- `session_history(string $name, ?int $before, int $limit, ?int $after=null, bool $untilRealUserMessage=false): array`
  (:82) — `{ok, entries?, next_before?, has_more?, message?}`; resolves `claude_session_id` via the
  sidecar (with the OpenCode reactive-bind heal, :88-100), then delegates to the shared private
  `transcript_page_for_claude_session()`. `$after` wins over `$before`.
- `archived_session_history(string $claudeSessionId, ?int $before, int $limit, ?int $after=null): array`
  (:126) — same paging, no sidecar/tmux lookup; adds `cwd` (for `relativize_path`).
- `archived_session_detail(string $claudeSessionId): array` (:175) — `{ok, message?, claude_session_id,
  cwd?, title?, last_activity?}` for a dormant session's read-only view.
- `session_attachment(string $name, int $line, string $fileUuid): array` (:204) /
  `archived_session_attachment(string $claudeSessionId, int $line, string $fileUuid): array` (:236).
- private `transcript_page_for_claude_session()` (:146) / `read_attachment_for_claude_session()` (:248)
  — path resolution + `$limit` clamp `max(1, min($limit, 200))`.

### Container render layer

**`TranscriptView`** — `render()`-based static HTML builders. Consts `MODE_OPTIONS` (:21),
`MODEL_OPTIONS` (:28), `ANTIGRAVITY_MODEL_OPTIONS` (:36) mirror the host-agent vocabularies (hand-kept
in sync, since the container can't reach those classes). Methods:

- `render_transcript_image_html(array $image): string` (:55); `format_attachment_size(int $bytes): string`
  (:64); `attachment_url(string $sessionIdentifier, int $line, string $fileUuid, bool $isArchived=false): string`
  (:87); `render_transcript_attachments_html(...): string` (:110).
- `render_transcript_block(array $block, string $sessionIdentifier, int $line, bool $isArchived=false,
  bool $isSubagent=false, bool $forceFullBlock=false): string` (:158) — the per-block renderer;
  `'text'` → `MarkdownRenderer::render_html()`, `'plan'`/`'tool_use'`/`'tool_result'`/`'task_notification'`
  → `BlockedPromptView` collapsible/full blocks (subagent results + task notifications get the markdown
  collapsible form). Emits `data-line` + `.copy-btn`/`.copy-source`.
- `render_blocked_prompt_section_html(array $detail, string $csrfToken): string` (:224) — thin wrapper
  over `BlockedPromptView::blocked_prompt_rich_html()`.
- `render_thinking_indicator_html(array $detail): string` (:239) — `''` unless `$detail['working']` and
  not blocked. `render_turn_error_html(array $detail): string` (:264) — Antigravity-only failed-turn
  notice.
- `render_todo_list_html(array $detail): string` (:292) — `''` when no todos, else `sidebar/todo-list`.
- `render_mode_toggle_html` (:311) / `render_model_toggle_html` (:332) /
  `render_antigravity_model_toggle_html` (:361) — the three small selects; model/mode disabled when the
  current value is unreadable (antigravity one never disabled).
- `entry_color_kind(array $entry): string` (:384) — `user`/`assistant`/`tool_use`/`tool_result`/
  `subagent_call`/`subagent_result`/`plan_presented`/`plan_approved`/`plan_rejected`/`system`, decided
  by `!hasText && ...` precedence then the literal role.
- `entry_color_classes(string $kind): array` (:465) — `{border, bg, label}` utility classes.
- `render_transcript_entries_html(array $entries, string $sessionIdentifier, bool $isArchived=false,
  ?string $cwd=null, ?string $agentLabel=null): string` (:514) — the batch renderer that pairs each
  groupable tool_use with its following tool_result into one `tool-call-entry`; everything else via
  `render_transcript_entry`.
- `entry_is_groupable_tool_call(array $entry): bool` (:563) — excludes subagent/image/attachment entries.
- `render_tool_call_entry_html(?array $callEntry, ?array $resultEntry, string $sessionIdentifier,
  bool $isArchived, ?string $cwd): string` (:603) — one `<details>`; `.tool-call-result-slot` always
  present even when empty.
- `tool_call_entry_summary(?array $callEntry, ?array $resultEntry, ?string $cwd): string` (:644) —
  "Write rel/path.php" / "Ran truncated command" / description / truncated text.
- `relativize_path(string $path, ?string $cwd): string` (:680) — prefix-strip, no realpath.
- `render_transcript_entry(array $entry, string $sessionIdentifier, bool $isArchived=false,
  ?string $agentLabel=null): string` (:731) — one entry wrapper + role label + timestamp + blocks.
- `entry_wrapper_class(string $colorKind, array $colors, string $extraClass): string` (:818) — assistant
  → free-flowing, user → bubble, others → boxed card.

**`MarkdownRenderer`** — single public method `render_html(string $text): string` (:49). Extracts fenced
code blocks, splits into `code`/`ul`/`ol`/`prose` segments, renders lists/code in their own elements,
everything else through one `<p class="whitespace-pre-wrap break-words">` with inline
bold/italic/code-span substitution. Every raw run is `htmlspecialchars()`-escaped before substitution;
inline code spans tokenized so they can't be re-parsed. No headings/links/images/blockquotes/tables.

### Controllers / endpoints

**`SessionController`** — session-view-owned methods:

- `show(): void` (:22) — `GET/POST /session.php`. Reads `session` from GET or POST (no method guard),
  starts the app session, resolves `session_detail`, `push_public_key`, then `session_history`
  (with `jump_line` → `before = jumpLine+1`), hands to `PageView::render_session_page()`. Never JSON.
- `showArchived(): void` (:95) — `GET/POST /archived_session.php`, dormant read-only view keyed by
  `claude_session_id`.
- `detail(): void` (:203) — `GET /session_detail.php`, JSON passthrough of `session_detail`.
- `history(): void` (:239) — `GET /session_history.php`, JSON `{ok, entries, next_before, has_more}`;
  passes `before`/`after`/`limit`/`until_user`.
- `search(): void` (:262) / `archivedSearch(): void` (:278) — `GET /session_search.php` /
  `/archived_session_search.php`, JSON from `session_transcript_search`/`archived_session_transcript_search`.
- `archivedHistoryFragment(): void` (:152) — `GET /archived_session_history_fragment.php`, JSON
  `{ok, html, has_more, next_before}` — server-renders HTML (no live-append path needs raw entries).
- `attachment(): void` (:384) / `archivedAttachment(): void` (:401) — streamed binary via
  `stream_binary_result(..., immutable: true)`.

Route map (`src/routes.php`): `/session.php` (:34), `/session_detail.php` (:37),
`/session_plan_files.php` (:38), `/session_plan_file.php` (:39), `/session_todo_file.php` (:40),
`/session_history.php` (:41), `/session_attachment.php` (:42), `/session_search.php` (:43);
`/archived_session.php` (:46), `/archived_session_history_fragment.php` (:48),
`/archived_session_attachment.php` (:49), `/archived_session_search.php` (:50). Each POST-only endpoint
also registers a GET route that falls through to the method's own `require_post_json()` 405.

## 5. Major call sites

- **Host-agent dispatcher** `host-agent/lib/Sessions.php::dispatch_action()` — `session_detail` (:40),
  `archived_session_detail` (:43), `session_history` (:46), `archived_session_history` (:55),
  `session_attachment` (:84), `archived_session_attachment` (:91) all delegate to `SessionDetailService`.
- **`SessionService::build_session_entry()`** (`session-core`) calls `TranscriptRouter::find_transcript_path()`
  and the transcript title/model readers to fill the dashboard + sidebar row model — this subsystem is
  a *producer* for `session-core`, not just a consumer.
- **`ArchivedSessionService`** (`archived-sessions`, outside) owns `session_transcript_search`/
  `archived_session_transcript_search`/`search_transcripts` (Sessions.php :70/:77/:63) which call
  `TranscriptService::search_transcript_file()` and `list_all_transcripts()`.
- **`SessionLifecycleService`** (`session-lifecycle`) and the OpenCode heal call
  `OpenCodeTranscriptService::find_session_for_workdir()` (via `SessionDetailService` :89/:210).
- **Live JS poll loop** (`session.js` `pollOnce()` → `pollInfo()`/`pollHistory()`) calls
  `/session_detail.php` and `/session_history.php` (`before`/`after`/`limit`) — the primary runtime
  consumer of the `SessionDetailService` face; `sidebar.js` calls the sidebar fragment endpoints.
- **PHP render call sites** (in-scope): `session.php` :270 (`render_transcript_entries_html`),
  :275/:279/:286 (thinking/turn-error/blocked-prompt), `sidebar.php` :61 (`render_todo_list_html`);
  the archived-session page template renders `archivedHistoryFragment` HTML.

## 6. Tests

- **`tests/test_transcript.php`** — large pure-unit suite against `transcript_sample.jsonl` (no
  tmux/socket/real home). Covers `parse_transcript_line` meta/malformed/thinking-only filtering;
  tool-use/AskUserQuestion/subagent summaries; `is_subagent_metadata_text`;
  `parse_task_notification`; ExitPlanMode approve/reject; images + oversized-image drop;
  `find_transcript_path`/`find_first_cwd`/`find_first_timestamp`/`list_all_transcripts`;
  `search_transcript_file`; `read_transcript_page` (`$untilRealUserMessage` + `_since`);
  `transcript_attachments_from_tool_use_result`/`read_attachment`; `find_latest_ai_title` (incl. tail
  window)/`find_latest_model`/`find_latest_todo_list`/`find_current_task_list`; plus the bottom
  `SessionService::title_cascade()`/`session_title()`/`list_archived_sessions()` friends.
  **Happy + sad path** (malformed JSON, missing files, oversized blocks/images, no-title/todo fallbacks).
- **`tests/test_antigravity_transcript.php`** — against a tmp `HOME_ROOT` (refuses to run against the
  real home). Covers `find_transcript_path`, `parse_transcript_line` per real shape, both paging
  methods, the honest attachment stub, and `TranscriptRouter` dispatch by path shape. **Happy + sad
  path** (unknown id / missing file).
- **`tests/test_opencode_transcript.php`** — against a canned `opencode.db` (`OPENCODE_DB_PATH` env).
  Covers `find_transcript_path`, `is_opencode_id`, router integration, both paging methods +
  `$untilRealUserMessage`, forward poll, missing/empty session, attachment stub, long-block truncation,
  missing-DB error. **Happy + sad path**.
- **`tests/test_markdown.php`** — pure unit for `MarkdownRenderer::render_html()`: plain text (unchanged
  `<p>` structure), XSS escaping, bold/italic (both markers, nesting), the underscore word-boundary
  guard, inline code-span tokenization, ul/ol lists, fenced code blocks, NUL placeholder collision
  safety. **Happy + sad path**.
- **`tests/test_markdown_parity_browser.php`** — cross-language parity: evals the real shipped
  `markdown.js` (+ `escapeHtml()` extracted from `common.js` by marker) in a blank page via CDP and
  compares JS `renderMarkdown()` output byte-for-byte against PHP for the same curated inputs.
  **Best-effort** — SKIPs (exit 0) if no usable Chrome (`*_browser.php` → picked up by `tests/run.sh`'s
  `--no-browser`/`--browser` filter).

The fixture `tests/fixtures/transcript_sample.jsonl` (10 lines) deliberately exercises: a `mode` meta
line, a real `user` message, an assistant text block, a `Bash` tool_use, a `tool_result`, a
`permission-mode` meta line, a **thinking-only** assistant line (must drop), another real assistant
text, an **invalid-JSON** line (must skip), and a `system` line with `message:null` (must drop).

## 7. Dependencies

**Upstream (consumed):** `HostAgent\Services\Config` (`home_root`, `antigravity_transcript_path`,
`opencode_db_path`, `sessions_sqlite_path`); `SessionService`/`TmuxService`/`ProcessInspector`
(`session-core` — `build_session_entry`, `title_cascade`, `list_tracked_tmux_sessions`,
`find_claude_processes`, `build_ppid_map`); `SessionLifecycleService` (`session-lifecycle` —
`claude_session_id_already_live`); `HostAgent\Stores\SidecarStore`; `ArchivedSessionService`
(`archived-sessions` — the search actions); `App\Views\View`/League Plates (every `render()`);
`BlockedPromptView` (render_collapsible/full/markdown blocks, `blocked_prompt_rich_html`,
`collapsible_summary`); `PageView` (page templates); `SessionRowView` (`relative_time` for timestamps);
`PushNotifyView`/`QuotaFooterView` (compose-bar rows); JS `common.js` globals (`escapeHtml`,
`parseJsonResponse`, `relativeTimeLabel`, `copyTextToClipboard`, `wireTouchTooltip`,
`POLL_INTERVAL_STORAGE_KEY`/`POLL_INTERVAL_ALLOWED_MS`).

**Downstream (depended on by):** `SessionService::build_session_entry()` and the dashboard (title/
current_model/etc. derived from the transcript readers); `ArchivedSessionService` (dormant list via
`list_all_transcripts()`/`search_transcript_file()`); `BlockedPromptView` (render of the blocked
collapsible markdown blocks reuses `MarkdownRenderer::render_html()` and the same `transcript/*`
template conventions); the sidebar todo/upload/plan UI in `sidebar.js` reuses this page's
`CSM_BOOTSTRAP`/`formatFileSize`/renderMarkdown infrastructure.

## 8. Co-owned / cross-subsystem

Per the co-report model, these files/methods are physically here but **feature-attributed to another
subsystem** (recorded as partitions, not owned here):

- **`SessionController.php` → `prompt-interaction`:** `send()` (:297, `/session_send.php`),
  `setMode()` (:313, `/session_mode.php`), `setModel()` (:331, `/session_model.php`),
  `setAntigravityModel()` (:351, `/session_antigravity_model.php`), `escape()` (:366,
  `/session_escape.php`), `answerPrompt()` (:457, `/answer_prompt.php`), `answerMultiQuestion()` (:486,
  `/answer_multi_question.php`).
- **`SessionController.php` → `plan-files`:** `planFiles()` (:215, `/session_plan_files.php`),
  `planFileContent()` (:421, `/session_plan_file.php`), `todoFile()` (:440, `/session_todo_file.php`).
- **`SessionController.php` → `archived-sessions`:** `showArchived()` (:95, `/archived_session.php`),
  `archivedHistoryFragment()` (:152, `/archived_session_history_fragment.php`),
  `archivedSearch()` (:278, `/archived_session_search.php`), `archivedAttachment()` (:401,
  `/archived_session_attachment.php`). (The detail/history/attachment **reads** these delegate to are
  session-view; the page *shapes* / archived-list integration belong to `archived-sessions`.)
- **`SessionController.php` → `uploads`:** the instruction names the `uploaded_*`/`upload_file`
  endpoints as uploads — note these actually live in `UploadController` (not `SessionController`); the
  session-page *upload* surface is `compose-bar.php`'s attach button + `session.js`'s
  `uploadOneFile()` (:2425-2530) and `sidebar.js`'s uploaded-files loaders
  (`loadUploadedFiles` :400, `uploadedFileRowHtml` :388, `deleteAllUploadsBtn` handling :514).
- **`session.js` → `prompt-interaction`:** the mode/model/answer/send/escape JS blocks —
  `renderModeToggle`/`renderModelToggle`/`renderAntigravityModelToggle` (:1099/:1115/:1137),
  `renderMultiQuestionCardHtml`/`renderOptionsCardHtml`/`renderPromptOptionsFormHtml`
  (:1260/:1274/:1303), `renderBlockedSection` (:1363), the answer/blocked-submit wiring (:1585-1800),
  `sendComposedMessage` (:2297), and the `modeSelect`/`modelSelect`/`antigravityModelSelect` change
  handlers (:2535/:2566/:2598).
- **`session.js`/`sidebar.js` → `uploads` and `plan-files`:** sidebar upload JS (above) and plan-file JS
  (`loadPlanFiles` :448, `planFileRowHtml` :439), plus `session.js`'s compose attachment
  preview/save/clear (:2228-2290).
- **`MarkdownRenderer.php` / `public/js/markdown.js`** are **shared**, not owned solely here:
  `BlockedPromptView::render_collapsible_markdown_block()` calls `MarkdownRenderer::render_html()`
  (BlockedPromptView.php:159) and `session.js`'s `renderCollapsibleMarkdownBlock()` (:245) uses
  `renderMarkdown()`; the instruction flags upload rendering as another consumer (no direct
  `MarkdownRenderer` call is present in the upload controller/views — it shares the blocked-prompt
  markdown shell). Keep PHP and JS mirrors in sync when touching either.
- **`transcript/block.php` + `session.js::renderBlock()`** are PHP/JS mirrors of the same block markup —
  must be kept in sync (same convention as every other PHP-render/JS-poll-mirror pair).

## 9. Data & schema

**Claude Code JSONL line shapes** (`~/.claude/projects/<encoded-cwd>/<uuid>.jsonl`), read-only:
- Meta-only `type`s skipped (`transcript_meta_only_types()` :96): `mode`, `permission-mode`,
  `bridge-session`, `last-prompt`, `attachment`, `ai-title`, `system`, `queue-operation`,
  `file-history-snapshot`, `file-history-delta`.
- `user` line: `{type:"user", timestamp, message:{role:"user", content}}` — content is either a plain
  string (a `<task-notification>`-wrapped subagent report, or bare text) or a list of
  `{type:"text"/"tool_use"/"tool_result"/"image"/"thinking"}` blocks. A tool result is written back
  under `role:"user"`; the matching outcome lives on the outer line's `toolUseResult` field (a sibling
  of `message`), carrying `attachments:[{file_uuid, path, size, isImage, media_type}]`, `agentType`,
  `plan`, `task.id`, `taskId`, `success`.
- `assistant` line: `{type:"assistant", timestamp, isSidechain, message:{role:"assistant",
  model:"claude-<x>", content:[blocks]}}`; `isSidechain:true` marks a subagent's own calls (excluded
  from todo/task reads).
- `ai-title` line: `{type:"ai-title", aiTitle, sessionId}` — re-written near-continuously, so only the
  tail matters.
- Entry "line" is the 1-indexed JSONL line count (a stable cursor — Claude Code only appends).

**Antigravity JSONL** (`~/.gemini/antigravity-cli/brain/<uuid>/.system_generated/logs/transcript_full.jsonl`):
entries are `{type:"USER_INPUT"|"PLANNER_RESPONSE"|"GENERIC"|"CHECKPOINT", created_at, content,
source, tool_calls:[{name, args}]}`. `USER_INPUT` content wraps the real request in
`<USER_REQUEST>...</USER_REQUEST>` (+ `<ADDITIONAL_METADATA>`/`<USER_SETTINGS_CHANGE>`).
`PLANNER_RESPONSE` has `content` + `tool_calls`; `GENERIC(source:"MODEL")` is a tool result.
`CHECKPOINT` (context-truncation summary) is skipped. `run_command` tool calls map to
`tool_name:'Bash'` + `command`.

**OpenCode SQLite** (`~/.local/share/opencode/opencode.db`, env-overridable via `OPENCODE_DB_PATH`,
opened `SQLITE_OPEN_READONLY`, `PRAGMA busy_timeout=5000`) — tables used: `session` (`id` `ses_*`,
`title`, `directory`, `time_created`), `message` (`id`, `session_id`, `time_created`, `data` JSON),
`part` (`message_id`, `time_created`, `data` JSON, plus `session_id`/`time_updated` for the pending
question read). `message.data` carries `{role, time:{created(ms)}}`; `part.data` carries
`{type: "text"|"tool"|"file"|"reasoning"|"step-start"|"step-finish", text, tool, state:{input, output}}`,
with `state.input` giving the tool call and `state.output` its result. A `tool: "question"` part's
`state.status` (`running`/`pending`/`completed`) drives blocked-prompt detection (only the newest is
authoritative).

**Archived layout:** a dormant session is just a transcript file (Claude/Antigravity) or a `ses_*` DB
id (OpenCode) with no live sidecar/tmux session — keyed by `claude_session_id` throughout the archived
views (`SessionDetailService::archived_*` methods). No DB tables are owned by this subsystem (it is
read-only for transcripts; sidecars/status/pending belong to `host-agent-runtime`/`session-status-state`).

## 10. Conventions & quirks

- **`data-line` + `.copy-btn`/`.copy-source`** on every rendered block (both PHP `transcript/block.php`
  and JS `renderBlock()` :363-413 mirror the same markup) — used for search-jump and the shared
  copy-to-clipboard affordance (`copyTextToClipboard()` in common.js). Keep both in sync.
- **Plain ES5** in all `public/js/*.js` — `var`/`function`, no `const`/`let`/arrow/`Set`/template
  literals (mobile Safari repeatedly the reason; see iOS comments in session.js). No transpiler.
- **PHP/JS markdown parity** is a real, tested invariant — `MarkdownRenderer` (PHP) and
  `markdown.js`'s `renderMarkdown()` must stay byte-identical (`test_markdown_parity_browser.php`).
- **Every state-changing action re-validates fresh** (never trusts client input): transcript/attachment
  paths re-derived server-side; todos re-read per poll; the answer option re-checked against the live
  prompt.
- **Two-runtime seam:** the container (`src/`, `public/`) never touches tmux/`/proc`; it only speaks
  JSON over the UNIX socket to the host agent. `TranscriptView`'s mode/model constants are therefore
  hand-mirrors of the host-agent vocabularies (never directly shareable).
- `TranscriptService` never writes a transcript; every read is bounded (tail-scan windows, line caps)
  so a multi-MB file isn't loaded into memory just to show a title/todo/cwd.
