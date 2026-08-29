---
id: archived-sessions
based_on: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# Audit: archived-sessions

Verified against HEAD `44e4caab492481c850d4dceec97d5c65e41a5b53` — the same
commit `DETAILS.md` was scanned at, so the map is current; every `file:line`
below was re-read from source, not trusted from the summary. Line numbers in
`DETAILS.md` §4/§9 align with the code.

## Findings (ranked most-severe first)

### 1. Archived detail/browse path resolves transcripts with the Claude-Code-only resolver — Antigravity/OpenCode archived sessions render "Session not found"

- **Recommendation:** `fix` — route `archived_session_detail` (and its cwd/title
  derivation) through `TranscriptRouter` + `SessionService::session_title()`
  instead of the Claude-Code-only `TranscriptService` primitives.
- **Evidence:** `host-agent/lib/Services/SessionDetailService.php:177`
  (`archived_session_detail` resolves via `TranscriptService::find_transcript_path`,
  a glob under `~/.claude/projects`); `:134` (`archived_session_history` computes
  `cwd` via the same claude-only resolver); `:191` (title via
  `title_cascade($aiTitle,...)` where `$aiTitle` is claude-only
  `find_latest_ai_title`). Contrast with the sibling paths that already use the
  multi-agent router: `:148` (`transcript_page_for_claude_session` →
  `TranscriptRouter::find_transcript_path`), `:250` (`read_attachment_for_claude_session` →
  `TranscriptRouter::find_transcript_path`). The listing side
  (`ArchivedSessionService.php:49-62, 79-97`) actively emits Antigravity and
  OpenCode rows into the dashboard's archived toggle.
- **Impact:** Every Antigravity conversation id (a UUID, stored as
  `claude_session_id`) and every OpenCode `ses_*` id fails
  `TranscriptService::find_transcript_path` (the opencode one fails the UUID
  regex at `TranscriptService.php:112`; the antigravity one globs a
  `~/.claude/projects` dir that never holds it). So clicking an archived
  Antigravity/OpenCode row → `archived_session.php?claude_session_id=...` →
  `archived_session_detail` returns `['ok'=>false, 'message'=>'Session not found']`
  (`SessionDetailService.php:179-181`) → the page renders the not-found card
  (`src/partials/pages/archived-session.php:20-24`). The same applies to a live
  **OpenCode** session opened on the archived page. The history fragment and
  attachment endpoints already work for these agents (they go through
  `TranscriptRouter`), so the detail page is the odd one out — a transient, reachable
  divergence introduced by the 2026-08-24 split that did not keep the *detail*
  path consistent with the *paging*/attachment paths.
- **Proposed representation:** one resolver for the whole archived surface —
  `TranscriptRouter::find_transcript_path($claudeSessionId)` at `:177`, `:134`;
  and title via the agent-aware `SessionService::session_title(...)` (which already
  handles claude ai-title / opencode `session.title` / antigravity-none at
  `SessionService.php:69-84`) rather than the inlined `title_cascade(
  $claudeOnlyAiTitle, null, ...)`. This makes `archived_session_detail` agree with
  both the listing (`list_archived_sessions`) and the row title.
- **Smallest credible implementation scope:** two swaps in
  `SessionDetailService.php` (`:177`, `:134`) plus reusing
  `SessionService::session_title` for the return's `title` field (`:191`). No
  signature changes; `TranscriptionRouter` and `SessionService` are already
  dependencies of this file.
- **Regression risks / migration concerns:** low. `TranscriptRouter` falls
  through Claude → Antigravity → OpenCode in id spaces that cannot collide
  (`TranscriptRouter.php:28-33`), so Claude-Code-only behavior is unchanged.
  The one behavioral change beyond the not-found fix: an OpenCode archived
  detail title becomes `session.title` instead of nothing (improvement), and an
  Antigravity detail title becomes its `claude_session_id` (still not the
  conversation's own label — Antigravity has no title field; see note below).
- **Secondary (do not hide in the fix):** even after the router swap, a
  non-Claude archived session's detail **title** is sub-par because
  `find_first_cwd`/`find_latest_ai_title` are claude-specific and return null
  (`SessionDetailService.php:183-184`) — acceptable, but the page would show a
  bare id for OpenCode unless `session_title()` is used. Also the hardcoded
  agent label `'Claude Code'` threaded into the render (`SessionController.php:172`,
  `archived-session.php:86`) mislabels a non-Claude assistant entry — see Finding 5.
- **Validation:** no existing test exercises archived detail/history for a
  non-Claude agent (`test_transcript.php:582-598` and `test_ui_smoke.php:1259-1274`
  are claude-UUID only; `test_sessions_lifecycle.php:735-738` only asserts the
  claude exclude/include). Add a unit test in `test_transcript.php` (or the
  shared lifecycle harness) that stubs an Antigravity/OpenCode archived id and
  asserts `archived_session_detail` + `archived_session_history` both return
  `ok=true`, and a smoke assertion for the not-found-string absence.
- **Confidence:** `high`
- **Priority/severity:** `high`

### 2. Content search (dashboard-wide + per-session) silently covers Claude Code only; the contract claims "every known transcript, live and archived alike"

- **Recommendation:** `refactor` — make the search scope either genuinely
  multi-agent (implement per-backend `search_transcript_file`) or honest about
  being Claude-Code-only. Do not leave a contract that the code does not meet.
- **Evidence:** `host-agent/lib/Services/ArchivedSessionService.php:167`
  (`search_transcripts` iterates `TranscriptService::list_all_transcripts()` —
  Claude-Code-only builder, `TranscriptService.php:222-240`); `:173` and `:244`
  call `TranscriptService::search_transcript_file` (the Claude-JSONL two-stage
  grep, `TranscriptService.php:272-325`); `:238` resolves the per-session path
  via `TranscriptService::find_transcript_path` (claude-only). No equivalent
  `search_transcript_file` exists in `AntigravityTranscriptService` or
  `OpenCodeTranscriptService`. The docblock (`:135-151`) and `DETAILS.md` §4
  both say "every known transcript, live and archived alike".
- **Impact:** Antigravity/OpenCode conversation content is invisible to the
  dashboard's `/search_sessions.php` and to the per-session sidebar/archived
  search boxes. A live OpenCode session's sidebar search returns
  `['ok'=>false,'message'=>'Transcript file not found']` (a real, freshly
  reachable case once opencode sessions are self-healed); an archived
  Antigravity/OpenCode in-page search never finds anything. Because
  `search_transcripts` only ever returns Claude results, the
  `session_name`-null→archived branch of the client linker
  (`public/js/index.js:954-956`) is effectively dead for non-Claude sessions —
  there is no non-Claude transcript to link to.
- **Proposed representation:** the cleanest is a small registry/map of
  per-agent `search(path, query, maxMatches)` alongside the existing
  per-agent `read_transcript_page`, dispatched by path shape exactly the way
  `TranscriptRouter` already dispatches `find_transcript_path`/
  `read_transcript_page` (`TranscriptRouter.php:28-85`). If that's too large for
  now, the honest alternative is to keep the search Claude-Code-only and say so
  in the docblock/DETAILS.md and surface "search is Claude-Code-only today" in
  the UI rather than pretending.
- **Smallest credible implementation scope:** add `search_transcript_file`
  (and a `TranscriptRouter::search_transcript_file` dispatcher) for Antigravity
  and OpenCode, or narrow the two docblocks + one UI string. If adding search
  backends: `AntigravityTranscriptService` and `OpenCodeTranscriptService` each
  get a parity method returning the canonical
  `{line, snippet, role, kind, timestamp}` shape; `ArchivedSessionService`
  switches `:167` to iterate all three lists and route each file through
  `TranscriptRouter`.
- **Regression risks / migration concerns:** a new per-backend search must reuse
  the two-stage raw-line-then-parsed-text filter (so a metadata-only raw hit is
  not falsely reported), and must report `line` as the same 1-indexed value
  `read_transcript_page` uses so the jump-landing still lands on the right
  `[data-line]`.
- **Validation:** extend `test_transcript.php`'s search block (`:539-576`) with an
  Antigravity/OpenCode fixture asserting `search_transcripts`/per-session search
  return the same shapes; an HTTP smoke assertion on
  `/archived_session_search.php` for a `ses_*` id returning `ok=true`.
- **Confidence:** `high`
- **Priority/severity:** `medium`

### 3. `archived-session.js` load-more error handling is asymmetric — a server `ok=false` leaves the button permanently dead, while a network error allows retry

- **Recommendation:** `tweak` — mirror the network-catch retry path in the
  `!data.ok` branch so a transient server-side failure is recoverable without a
  reload.
- **Evidence:** `public/js/archived-session.js:33-35` (on `!data.ok` it sets
  `btn.textContent = message` and returns, leaving `btn.disabled = true` from
  `:24` and never re-enabling); `:53-56` (network `.catch` re-enables and offers
  "Network error - try again").
- **Current complexity / invalid states:** if the host-agent returns `ok=false`
  mid-session (e.g. the transcript file vanished between page load and a
  "Load older" click, or a transient agent/socket failure), the button is stuck
  on the error text and can never be retried — the only recovery is a full page
  reload. The window of "delete the transcript dir while the page is open"
  (`archived_session_history` → `TranscriptRouter::find_transcript_path` → null →
  `ok=false`, `SessionDetailService.php:150-152`) is a realistic way to hit it.
- **Proposed representation:** in the `!data.ok` branch, also
  `btn.disabled = false;` (and optionally keep the original label) so the reader
  can click again (it will just re-fetch and get the same `ok=false` if the file
  is still gone, but the button won't look permanently frozen). Leaving
  `btn.dataset.before` unchanged is correct either way.
- **Smallest credible implementation scope:** `public/js/archived-session.js:33-35`
  only. ES5-safe (already `var`/`function`).
- **Regression risks / migration concerns:** none — it only restores the retry
  affordance already available in the network-error path.
- **Validation:** the existing headless-DOM load-more control
  (`test_ui_smoke.php:1505-1511`) exercises the happy path; add a DOM-level
  assertion that a mocked `ok=false` response re-enables the button (or, at
  minimum, a manual verification note).
- **Confidence:** `high`
- **Priority/severity:** `low`

### 4. Archived transcript render hardcodes the agent label `'Claude Code'`, mislabeling a non-Claude assistant entry

- **Recommendation:** `refactor` — thread the archived row/page's known
  `agent_label` into `render_transcript_entries_html` rather than hardcoding it.
- **Evidence:** `src/lib/Controllers/SessionController.php:172`
  (`archivedHistoryFragment` passes `'Claude Code'` as the `$agentLabel` arg);
  `src/partials/pages/archived-session.php:86` (same hardcode). The label is used
  as the assistant entry's role label at `TranscriptView.php:741`.
- **Current complexity / invalid states:** the archived row already knows the
  real agent (`archived-row.php:6-13` via `$agentLabel`; sourced from the
  listing's `agent_label`), but the detail page and the load-more fragment throw
  that away and assume Claude Code. Today this is unreachable for non-Claude
  because of Finding 1 (the detail never loads for them), but the moment Finding
  1 is fixed it becomes a visible mislabel.
- **Proposed representation:** `archived_session_detail` already returns `title`/
  `cwd`/`last_activity`; add `agent_label` (or `agent`) to its return, thread it
  through `showArchived`/`archivedHistoryFragment`, and pass it to
  `render_transcript_entries_html` instead of the literal.
- **Smallest credible implementation scope:** `SessionDetailService::
  archived_session_detail` (add `agent_label`), `SessionController::
  showArchived`/`archivedHistoryFragment`, `archived-session.php`, and the two
  `'Claude Code'` literals.
- **Regression risks / migration concerns:** none for Claude (label stays
  "Claude Code"). Only changes non-Claude display.
- **Validation:** an HTTP smoke assertion that a canned archived Antigravity/
  OpenCode session's assistant entry shows its own agent label rather than
  "Claude Code".
- **Confidence:** `high`
- **Priority/severity:** `low`

### 5. `search_transcript_file` fully loads each transcript and walks the whole file on the first raw hit — a per-keystroke multi-MB read for the dashboard-wide search

- **Recommendation:** `research-more` — the current design is a documented
  on-demand trade-off, but a dashboard-wide search over ~160 transcripts reads
  each matching file wholly into memory (`@file($path, FILE_IGNORE_NEW_LINES)`,
  `TranscriptService.php:280`) and, on the first raw hit, runs the whole-file
  `find_exit_plan_mode_tool_use_ids` walk (`:294`). Verify the real transcript
  size distribution before changing it.
- **Evidence:** `TranscriptService.php:280` (`@file(...)`, full array), `:294`
  (`$exitPlanModeToolUseIds ??= self::find_exit_plan_mode_tool_use_ids($lines)`),
  `:1660-1662` (files "verified live 50MB/~10k-line" for a single session).
  Driven by `ArchivedSessionService::search_transcripts` on every debounced
  keystroke (`index.js:1016-1036`, 400ms).
- **Current complexity / invalid states:** peak transient memory per request can
  be large if several big transcripts match; a single pathological 50MB file is
  loaded into a string array every time its session is searched. On-demand +
  debounced, so not a liveness bug, but a real spike risk on a busy machine.
- **Proposed representation:** stream the file (fgets) backward from the tail
  with a bounded candidate check, and defer the ExitPlanMode id-map to only the
  candidate lines that clear a cheap raw `stripos` (already largely how the
  two-stage filter works, just not streamed).
- **Smallest credible implementation scope:** `TranscriptService::
  search_transcript_file` only; keep the exact return shape.
- **Regression risks / migration concerns:** must preserve newest-match-first
  ordering and the raw-line-then-parsed-text filtering; a streaming backward read
  must still report the correct 1-indexed `line`.
- **Validation:** the existing search tests (`test_transcript.php:512-534`)
  pin the shape/ordering/ellipsis behavior and would catch a regression.
- **Confidence:** `medium`
- **Priority/severity:** `low`

### 6. Doc/comment drift: `list_archived_sessions` antigravity null-title and out-of-date `@return` shape

- **Recommendation:** `tweak` — comments on code with a latent trap and a stale
  `@return`.
- **Evidence:** `host-agent/lib/Services/ArchivedSessionService.php:57`
  (`SessionService::title_cascade(null, null, $t['cwd'], ...)` hardcodes null for
  antigravity's ai_title) while the class docblock in `DETAILS.md:109-111` and the
  `@return` at `:27` omit `agent`/`agent_label` (the method returns them at
  `:44-45, :59-60, :94-95`). This is not a bug today — Antigravity never returns
  an `ai_title` (`AntigravityTranscriptService.php:102`) — but the comment claims
  it does, and the hardcoded null would silently drop a future title.
- **Proposed representation:** pass `$t['ai_title']` (always null today, but
  correct if Antigravity grows one) and update the `@return` docblock to include
  `agent`/`agent_label` to match the real shape.
- **Smallest credible implementation scope:** `ArchivedSessionService.php:27-28`
  and `:57`.
- **Regression risks / migration concerns:** none (behavioral no-op for the
  current always-null input).
- **Validation:** existing `test_transcript.php:475-494` covers the claude path;
  the antigravity branch has no dedicated assertion.
- **Confidence:** `high`
- **Priority/severity:** `low`

## What's done well

- **The Claude-Code search core is genuinely good.** The two-stage
  raw-line-then-parsed-block filter (`TranscriptService.php:289-304`) correctly
  drops a raw hit that resolves only to metadata (the tool_id "pineapple" trap is
  pinned by `test_transcript.php:503-514`), and the snippet builder
  (`build_search_snippet`, `:335-349`) is tight — whitespace-collapsed, both-side
  ellipsis, `mb_*` on the collapsed string. Case-insensitivity, empty-query
  short-circuit, and missing-file resilience are all tested.
- **The search-linking signal is correct and single-sourced.** `session_name`
  non-null→`session.php` vs null→`archived_session.php` (`:159-181`, consumed at
  `index.js:954-956`) is the sole live/archived discriminator and is populated
  from a fresh `list_all_sessions()` scan and keyed by the claude_session_id, so a
  mid-conversation archive/unarchive correctly flips the link target on the next
  search.
- **On-demand-not-periodic discipline is honored and documented** in both
  `list_archived_dashboard` (`:108-114`) and `search_transcripts` (`:135-143`);
  the archived list is fetched once on toggle-open with no live poll
  (`archived-session.js:1-11`), and the dashboard-wide search is debounced
  (`index.js:1002-1036`). The `BareProcessService` consumer is also a listing
  consumer, not a periodic one.
- **Server-rendered fragments for a session that can't change** — a genuinely
  good choice: `archivedHistoryFragment` ships pre-rendered HTML and no client
  renderer is duplicated, unlike `session.php`'s live path.
- **`jump_line` landing is solid.** `before = jumpLine + 1` (`SessionController:
  showArchived:121`) is load-balanced so the page ends exactly at the jump line,
  entries are 1:1 with raw JSONL lines (`TranscriptService.php:1704`), and the
  JS handles the collapsed-`<details>` zeroed-rect pitfall via
  `openAncestorDetails` + computed `getBoundingClientRect()` scroll
  (`archived-session.js:78-95`).
- **Resume cwd-gating is correct** — Resume/Unarchive only renders when a real
  workdir is known (`archived-row.php:18-26`, `archived-session.php:32-40`), so a
  workdir-less resume the agent would reject is never offered.
- **ES5 compliance holds** throughout `archived-session.js`: `var`/`function`
  only, no arrow functions / template literals / `const`, matching the repo
  convention (and the earlier mobile-Safari-driven rule).

## Out-of-scope

- **`SessionLifecycleService::resume_cc_session()` / the "Unarchive" and "Resume"
  POSTs** — they leave this subsystem for `session-core`; this audit only checked
  that the archive side gates on a known cwd, not the resume target's behavior.
- **`BareProcessService`** take-over-bare candidate listing
  (`BareProcessService.php:163-179`) also uses the claude-only
  `find_transcript_path`/`find_first_timestamp`; it is a listing+bare-process
  concern owned by `session-core`/`bare-process`, not fixed here.
- **`archived-session.php`'s "Unarchive" form** and the `archived-row.php`
  "Resume" form are duplicated hidden-field markup; folding them into a shared
  partial would touch the dashboard too and is a low-value cross-subsystem
  refactor.
- **`show()` vs `showArchived()` duplicated `jump_line`/`before` handling** — the
  two methods differ by key (tmux name vs claude_session_id) and view; the
  duplication is documented as deliberate and low-risk.
- **The archived-list client-side title/folder filter** (`index.js:
  applyPagination`/`filterArchivedRows`) is the dashboard's file and is
  deliberately substring-only (can't reach paginated-away content) — a known,
  documented limitation, not a defect in this subsystem.

## Cross-Cutting Observations

- **`TranscriptRouter`/`TranscriptService` inconsistency is a `session-view` +
  `session-core` seam.** The archived surface's use of claude-only resolution
  (`SessionDetailService.php:134,177,191`; `ArchivedSessionService.php:167,173,238,244`)
  diverges from every paging/attachment path that already routes through
  `TranscriptRouter`. Fixing it once on the archived side (Finding 1/2) leaves
  the same pattern in `BareProcessService.php:171` (take-over-bare heuristic) and
  `SessionService.php:269,357` (model read / self-heal guard) — those touch
  `session-core`/`bare-process` and should be swept in a coordinated pass.
- **Dashboard vs archived-list staleness is accepted-by-design.** The archived
  list is fetched once and not refreshed on a timer; it can lag the live list
  until the next toggle-open. This is intentional (on-demand scan) but worth a
  note in the dashboard subsystem audit rather than here.
- **`session_name` as the sole live/archived discriminator** is a data-flow
  coupling between this subsystem's `search_transcripts` and `index.js`/`search.js`.
  It works because both consumers re-render per request; a future change that
  caches search results on the client would need to also cache the `session_name`
  signal or results could point at the wrong page.
