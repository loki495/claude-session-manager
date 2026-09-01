---
id: dashboard
name: Home dashboard — session list/rows, fragments, search box, health box, index page
owned_paths:
  - src/lib/Controllers/DashboardController.php
  - src/lib/Views/SessionRowView.php
  - src/lib/Views/HealthBoxView.php
  - src/partials/pages/index.php
  - src/partials/session-row/row.php
  - src/partials/session-row/list.php
  - src/partials/session-row/empty-state.php
  - src/partials/session-row/count-label.php
  - src/partials/session-row/thinking-indicator.php
  - src/partials/health-box/box.php
  - src/partials/health-box/push-timer-interval-control.php
  - public/js/index.js
last_scanned_commit: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# Home dashboard — session list/rows, fragments, search box, health box, index page

## 1. Identity

- **id:** `dashboard`
- **name:** Home dashboard — session list/rows, fragments, search box, health box, index page

This subsystem is the home screen a user lands on at `/`: the live/archived
session rows, the lightweight live-`fragment()` poll that keeps them up to date
without a manual refresh, the dashboard-wide search box, the health/setup
box, and the index page (`pages/index.php`) + its ES5 `index.js`. It is the
**view + controller half** of list/render — the row *data* model is
`session-core`, the archived rows/search backend is `archived-sessions`, bare
rows are `session-lifecycle`, and the health *check* logic is
`push-notifications`; this subsystem wires those responses through
`AgentClient` and renders them with `PageView`/`SessionRowView`/`HealthBoxView`.

## 2. Ownership boundary

**In scope (owned paths):**

- `src/lib/Controllers/DashboardController.php` — every HTTP action behind `/`,
  `/sessions_fragment.php`, `/sessions_list.php`,
  `/archived_sessions_fragment.php`, `/take_over_bare.php`,
  `/take_over_bare_confirm.php`, `/search_sessions.php`.
- `src/lib/Views/SessionRowView.php` — the dashboard's session/bare row
  builder, the whole-list/empty-state/count-label/thinking-indicator renderers,
  and the `relative_time()` helper.
- `src/lib/Views/HealthBoxView.php` — the setup-health panel (collapsed
  `<details>` + the folded-in push-check interval control). Per Andres's #4
  decision this *view* is owned by the dashboard; the *backend*
  `PushHealthService::health_check()` it renders is `push-notifications`.
- `src/partials/pages/index.php` — the full-page dashboard template (layout
  fix, search box, banners, New Session form, bare + archived toggles, footer).
- `src/partials/session-row/row.php`, `list.php`, `empty-state.php`,
  `count-label.php`, `thinking-indicator.php` — the non-archived, non-bare
  session row family.
- `src/partials/health-box/box.php`, `health-box/push-timer-interval-control.php`
  — the health panel and the interval dropdown form.
- `public/js/index.js` — the dashboard's only client JS (poll, answer-prompt
  AJAX, take-over flow, "Show last 3 messages", New Session folder browser,
  archived toggle + pagination, dashboard-wide content search).

**Out of scope (named neighboring subsystems, physically owned elsewhere):**

- `session-core` owns the row-building model (`SessionService` /
  `build_session_entry()` / `title_cascade()` and `Sessions.php::dispatch_action()`)
  that produces the `sessions[]`/`bare[]` arrays this dashboard renders.
- `archived-sessions` owns `ArchivedSessionService`, the `session-row/archived-*`
  partials, and the search *backend* (`search_transcripts()`); the dashboard only
  calls `action=list_archived` and `action=search_transcripts` verbatim.
- `session-lifecycle` owns the bare-process rows and the take-over backend
  (`BareProcessService::take_over_bare_process()` / `..._with_id()`), including
  the `session-row/bare-processes.php` / `bare-process-row.php` partials whose
  markup this subsystem's `SessionRowView` assembles (the take-over **form**'s
  semantics live there, not here).
- `push-notifications` owns `PushHealthService::health_check()` and
  `PushTimerService::get/set_push_timer_interval()` — the health box's data.
- `session-view` owns `SessionController` (`session_history.php` for "Show last
  3 messages"), `BrowseController` (`/browse.php`, `/create_folder.php` for the
  New Session folder picker), `QuotaFooterView`, and `PushNotifyView` — all
  called/included by the dashboard page but physically owned elsewhere.

## 3. Key implementation files

- **`src/lib/Controllers/DashboardController.php`** — The one dashboard
  controller: `index()` (full-page SSR), `handleAction()` (POST+redirect+flash
  for new/kill/kill_bare/cleanup/install_hook/set_push_timer_interval), and the
  JSON endpoints `fragment()`/`list()`/`archivedFragment()`/`search()`/
  `takeOverBare()`/`takeOverBareConfirm()`. Every call that reaches host state
  goes through `AgentClient::agent_call()`, never tmux/`/proc` directly.
- **`src/lib/Views/SessionRowView.php`** — One shared source of the row markup
  used by BOTH the SSR render and the poll fragment (never two copies): a single
  session row, the whole list + empty state, a bare-process row + section, the
  "N active tracked sessions" count label, the dashboard "Thinking…" indicator,
  the archived row + section (co-owned with `archived-sessions`), and
  `relative_time()`.
- **`src/lib/Views/HealthBoxView.php`** — `health_box_html()` (collapsed
  `<details>`, colored dot + "All systems OK"/"Some setup checks failed") and
  `push_timer_interval_control_html()` (preset dropdown + Save).
- **`src/partials/pages/index.php`** — The full-page template: `<details>`-based
  New Session form (agent picker, task-tools checkbox, starting-mode select,
  folder browser), search box, hook/host banners, flash banner, health box, the
  `#sessions-container`/`#bare-container`/`#archived-container`, and the
  non-fixed `#dashboard-footer` (quota + push-notify + Refresh).
- **`src/partials/session-row/row.php`** — One live session card: stretched-link
  to `session.php`, agent badge, title/name/workdir, relative time +
  attached/idle + ctx% + git-worktree, the "Show last 3 messages" toggle, the
  blocked-prompt/thinking treatment, and a per-row Kill form.
- **`src/partials/health-box/box.php`** — The health `<details>`: summary
  line + per-check ✓/✗ rows + the folded-in interval control.
- **`public/js/index.js`** — All dashboard client behavior (ES5): the
  visibility-gated poll IIFE, delegated blocked-prompt answer/freetext/
  multi-question handlers, the bare take-over flow, "Show last 3 messages"
  lazy fetch, New Session folder browser + create-folder, the archived toggle +
  pagination + agent filter, and the debounced dashboard-wide content search.

## 4. Public interfaces & contracts

All `App\Controllers\DashboardController` methods are dispatched by
`public/index.php` → `App\Http\Router` (`src/routes.php:16-31`). Every
state-changing path that needs guarding calls one of `Controller::require_post_json()`
(`Controller.php:31-51`) or, for the full-page/redirect paths, inlines its own
`AuthService` calls — the dashboard's `index()`/`handleAction()` deliberately
do NOT use `require_post_json()` (never JSON, never a 405; see below).

### `index(): void` — `DashboardController.php:22`

Full-page GET render; also renders for ANY non-POST method (it is the target of
both the GET `/` and POST `/` routes' fallthrough; `handleAction()` is wired to
POST only). Inlines `AuthService::start_app_session()` (`:24`) rather than either
guard helper — the page uses the session limiter's `private, max-age=60`
headers, not `start_readonly_json()`'s `no-store`.

- **Returns:** echoes `PageView::render_index_page([...])` HTML (`:53-67`).
- Makes 6 `AgentClient::agent_call()` calls, only when the agent is reachable
  (a first `list` call gates the rest — `:33,37,40,43`):
  - `list` (→ `sessions`, `bare`), `check_session_hook` (→ `hookInstalled`),
    `push_public_key` (→ `vapidPublicKey`), `health_check` (→ `healthChecks`),
    `get_push_timer_interval` (→ `pushTimerIntervalSeconds`).
  - When `list` fails (`agentReachable=false`), the other four calls are skipped
    to avoid a second redundant "host state" warning (`:31-44`).
- Reads+clears `$_SESSION['flash']` (`:46-49`) and `AuthService::csrf_token()` (`:51`).
- **Data shape in:** compact scalar/array payload; **out:** a render-data array
  with `agentReachable`, `listResult`, `sessions`, `bare`, `hookCheckOk`,
  `hookInstalled`, `hookResult`, `vapidPublicKey`, `healthChecks`,
  `pushTimerIntervalSeconds`, `flashMsg`, `flashOk`, `csrfToken`.
- **Post-condition:** always renders the page — an unreachable agent yields the
  "Cannot reach the host agent" banner instead of a 500.

### `handleAction(): void` — `DashboardController.php:79`

POST+redirect+flash for the dashboard's occasional actions. Inlines its own
`start_app_session()` (`:81`), `same_origin_or_no_origin()`→403 (`:83-88`), and
`require_csrf()`→403 (`:90`) — not `require_post_json()`, since this is a
redirect+flash flow (never JSON, never a 405). Reads `$_POST['action']` (`:92`).

- **`action=new`** (`:97-105`): `workdir`, `enable_task_tools` ('1' flag),
  `starting_mode`, `agent` → `agent_call(['action'=>'create', ...])`.
  `ok`/`message` echoed into flash.
- **`action=resume`** (`:107-123`): `workdir` + `claude_session_id` →
  `agent_call(['action'=>'resume', ...])`. **On success with a `result['name']`,
  redirects 303 to `/session.php?session=<name>`** instead of back to `/`
  (decided 2026-08-08; phase 5 of the unify-claude-sessions plan) — the only
  action here that doesn't round-trip through the flash.
- **`action=kill`** (`:125-130`): `session` → `agent_call(['action'=>'kill', ...])`.
- **`action=kill_bare`** (`:132-137`): `pid` → `agent_call(['action'=>'kill_bare', ...])`.
- **`action=cleanup`** (`:139-150`): no params → `agent_call(['action'=>'cleanup'])`;
  builds a human message from `killed[]`/`failed[]`.
- **`action=install_hook`** (`:152-158`): → `agent_call(['action'=>'install_session_hook'])`.
- **`action=set_push_timer_interval`** (`:160-167`): `seconds` →
  `agent_call(['action'=>'set_push_timer_interval', ...])`.
- **`default`** (`:169-171`): `ok=false`, `message='Unknown action'`.
- **Post-condition:** sets `$_SESSION['flash'] = ['msg'=>$message,'ok'=>$ok]`
  (`:174`) and `header('Location: /', true, 303)` (`:175`).

### `fragment(): void` — `DashboardController.php:197`

GET-only. `start_readonly_json()` (`:199`). Calls `list`, returns JSON. **Not**
`require_post_json()` — read-only, no CSRF (but `start_app_session()` keeps the
session + CSRF token alive for the row forms it renders, see `:188-196`).

- **Returns:** `{'ok':false,'message':...}` (`:206`) when the agent is
  unreachable; else `{'ok':true,'session_count_html':..., 'sessions_html':...,
  'bare_html':...}` (`:214-219`) — rendered via the SAME
  `SessionRowView::session_count_label_html()`/`sessions_list_html()`/
  `bare_processes_html()` methods as `index()`'s SSR, one source of truth.

### `list(): void` — `DashboardController.php:227`

GET-only JSON, polled by session.php's sliding sidebar. `start_readonly_json()`.
`echo json_encode(AgentClient::agent_call(['action'=>'list']))` (`:231`) — raw
pass-through of the full `sessions[]`/`bare[]` array, **no** HTML rendering.

### `archivedFragment(): void` — `DashboardController.php:244`

GET-only JSON, fetched once lazily when the archived toggle opens (never part of
the `fragment()` poll — a full `~/.claude/projects` scan on a 3-15s timer is
unnecessary work; docblock `:235-243`). `start_readonly_json()`.

- **Returns:** `{'ok':false,'message':...}` (`:253`) or
  `{'ok':true,'archived_html':...}` (`:258-261`) via
  `SessionRowView::archived_sessions_html()`.

### `search(): void` — `DashboardController.php:273`

GET-only JSON, fired by the debounced search box (and session.php's global
scope). `start_readonly_json()`. Reads `$_GET['q']` (`:277`).

- **Returns:** `echo json_encode(agent_call(['action'=>'search_transcripts',
  'query'=>$query, 'max_sessions'=>30, 'max_matches_per_session'=>3]))`
  (`:279-284`) — raw pass-through; never part of a poll.

### `takeOverBare(): void` — `DashboardController.php:297`

POST-only JSON (uses `require_post_json()` `:299`). `pid` → `agent_call(
['action'=>'take_over_bare', 'pid'=>$pid])` (`:303`). The client inspects the
response: a confident match (`ok` + `name`) redirects, `needs_choice` renders a
picker — see `index.js:196-331`.

### `takeOverBareConfirm(): void` — `DashboardController.php:311`

POST-only JSON. `pid` + `workdir` + `claude_session_id` → `agent_call(
['action'=>'take_over_bare_with_id', ...])` (`:319-324`). Only reached after
`takeOverBare()` came back `needs_choice=true`.

### `SessionRowView` static methods (all `App\Views\SessionRowView`)

- `dashboard_thinking_indicator_html(array $s): string` — `:25`. Empty string if
  `!$s['working']` OR `!empty($s['blocked_reason'])` (`:27`); else renders
  `session-row/thinking-indicator`. Mutually exclusive with the blocked-prompt.
- `session_row_html(array $s, string $csrfToken): string` — `:41`. One live row.
  Blocked-prompt branch (`:43-50`): folder-trust → `BlockedPromptView::
  blocked_prompt_panel_html()`, else `blocked_prompt_rich_html(..., true)`; else
  the thinking indicator + `last_message_preview_html($s['last_message'], 'mt-1')`.
  Renders `session-row/row` with `name`, `title` (=`$s['title']?:$s['name']`),
  `hasExplicitTitle`, `workdir`, `relativeTime`,
  `attached`, `contextUsedPercentage`, `gitWorktree`, `blockedHtml`, `csrfToken`,
  `agentId`, `agentLabel` (`:55-73`).
- `sessions_list_html(array $sessions, string $csrfToken): string` — `:83`.
  Empty → `session-row/empty-state`; else concat of `session_row_html()` wrapped
  in `session-row/list` (`:85-98`).
- `bare_process_row_html(array $b, string $csrfToken): string` — `:107`.
  Renders `session-row/bare-process-row` with `pid`, `title`, `tmuxSession`, `cwd`,
  `startedAt`, `csrfToken` (`:111-118`). **CO-OWNED** — the partial & form semantics
  are `session-lifecycle`'s.
- `bare_processes_html(array $bare, string $csrfToken): string` — `:128`. Empty
  `bare` → `''` (nothing rendered, matching `index.php`'s
  `$agentReachable && !empty($bare)` gate); else `session-row/bare-processes`.
- `session_count_label_html(int $count): string` — `:150`. Renders
  `session-row/count-label` (`:151-154`).
- `archived_session_row_html(array $a, string $csrfToken): string` — `:169`.
  Renders `session-row/archived-row`. Resume button rendered when `$a['cwd']` is
  known (`:160-166`). **CO-OWNED** with `archived-sessions`.
- `archived_sessions_html(array $archived, string $csrfToken): string` — `:190`.
  Empty → `session-row/archived-empty-state`; else `session-row/archived-list`.
  **CO-OWNED** with `archived-sessions`.
- `relative_time(int $timestamp): string` — `:207`. `just now` (<60s),
  `<N> min ago` (<1h), `<N> hr[s] ago` (<1d), `<N> day[s] ago` (else).
  Reused by `TranscriptView` and the archived page.

### `HealthBoxView` static methods

- `push_timer_interval_control_html(?int $currentSeconds, string $csrfToken): string`
  — `:31`. Renders `''` when `null` (timer not installed / agent unreachable
  `:33-35`). Presets `[5,10,15,30,60,120]` (`:37`); a non-preset current value is
  appended + re-sorted so the dropdown never misrepresents reality (`:39-42`).
  Renders `health-box/push-timer-interval-control`.
- `health_box_html(array $checks, ?int $pushTimerIntervalSeconds = null, string $csrfToken = ''): string`
  — `:69`. Renders `''` when `$checks` is empty (`:71-73`). Computes `allOk`
  (`:75-82`), picks the dot/summary colors + text (`:84-86`), folds in
  `push_timer_interval_control_html()` (`:88`), renders `health-box/box`.
  `@param array<int, array{key?:string,label?:string,ok?:bool,detail?:?string}> $checks`.

## 5. Major call sites

**HTTP entry points (`src/routes.php`):**

- `GET /` → `index()` (`:16`); `POST /` → `handleAction()` (`:17`).
- `GET /sessions_fragment.php` → `fragment()` (`:24`); `GET /sessions_list.php`
  → `list()` (`:25`); `GET /archived_sessions_fragment.php` → `archivedFragment()`
  (`:26`); `GET`+`POST /take_over_bare.php` → `takeOverBare()` (`:27-28`);
  `GET`+`POST /take_over_bare_confirm.php` → `takeOverBareConfirm()` (`:29-30`);
  `GET /search_sessions.php` → `search()` (`:31`).

**Front controller:** `public/index.php:31-41` matches the path against the
router and instantiates `DashboardController` → calls the mapped method; no match
is a hard 404.

**Web-side JS callers (outside `owned_paths` — consumers of this subsystem's
endpoints):**

- `public/js/sidebar.js` `:268,317` — session.php's sliding sidebar polls
  `/sessions_list.php` (`DashboardController::list()`) for every other session's
  status/prompt; consumes the raw `sessions[]` array shape.
- `public/js/search.js` `:104` — session.php's search box, when in *global* scope,
  calls `/search_sessions.php` (`DashboardController::search()`); session-page-only
  scope uses `/session_search.php` (SessionController) instead.
- `public/js/session.js` — shares the common.js helpers dashboard `index.js` uses.

**Downstream view/render consumers within the dashboard page (included by
`pages/index.php`, owned elsewhere):**

- `App\Views\SessionRowView::session_count_label_html()` `index.php:22` (SSR).
- `App\Views\SessionRowView::sessions_list_html()` `index.php:180`.
- `App\Views\SessionRowView::bare_processes_html()` `index.php:184`.
- `App\Views\HealthBoxView::health_box_html()` `index.php:89`.
- `App\Views\QuotaFooterView::quota_footer_html()` `index.php:210` (CO-OWNED —
  owned by `quota`).
- `App\Views\PushNotifyView::push_notify_button_html()` `index.php:216` (CO-OWNED —
  owned by `push-notifications`).
- `App\Views\PageView::AGENT_OPTIONS` `index.php:130`; `TranscriptView::MODE_OPTIONS`
  `index.php:157` (CO-OWNED — owned by `session-view`/`session-core` vocab).

**Host-agent consumers of the actions this controller issues** (the backend half —
owned elsewhere, listed for the call graph):

- `host-agent/lib/Push.php:36-37,55-59` — `push_public_key`, `health_check`
  (`PushHealthService::health_check()` `:174`), `get_push_timer_interval`
  (`PushTimerService::get_push_timer_interval()`).
- `host-agent/lib/Sessions.php:118-122` — `take_over_bare` /
  `take_over_bare_with_id` → `BareProcessService`; `list`, `kill`, `kill_bare`,
  `create`, `resume`, `cleanup`, `install_session_hook` in `dispatch_action()`.
- `host-agent/lib/Services/ArchivedSessionService::list_archived_dashboard()`/
  `search_transcripts()` — backing `archivedFragment()` and `search()`.

## 6. Tests

- **`tests/test_ui_smoke.php`** — HTTP smoke vs a canned fake-agent socket; the
  main dashboard test surface. Happy + sad path. Covers: `GET /` (status/title,
  count label `:83`, canned pane title `:88`, raw name `:89`, workdir `:90`,
  idle `:91`, bare row title+tmux session `:92-93`, last-message preview `:94`,
  show-recent button `:95`, stretched link `:96-99`, blocked rich treatment
  `:100-101`, thinking indicator exactly-once `:118`, poll-interval dropdown
  `:119-120`, `#session-count-text`/`#sessions-container`/`#bare-container`
  `:121-123`, navigation blanket `:124`, common.js+index.js load + cache-busting
  `:125-136`, CSRF token presence `:140-141`); POST new/kill/kill_bare with
  CSRF + 303 flash round-trip (`:143-188`, sad: bad CSRF 403 `:159-162`,
  unknown session name "Rejected" `:173-178`); `/sessions_list.php` passthrough
  (`:215-220`); `/sessions_fragment.php` (`:228-236`: `session_count_html`,
  `sessions_html` title + thinking + rich context, `bare_html`); 
  `/archived_sessions_fragment.php` (`:242-252`: title/cwd/Resume form/load-more);
  `/search_sessions.php` (`:1118-1130`: results shape, live vs archived
  `session_name`, ok=true on no-match); resume (303, `:1304`); 
  `/take_over_bare.php`/`_confirm.php` (`:1343-1397`: 405 on GET, 403 bad CSRF,
  ok+needs_choice+candidates+suggested, ok+name, rejects unknown pid / non-matching
  claude_session_id); take-over form in `bare_html` (`:1399-1407`).
- **`tests/test_sessions_lifecycle.php`** — host-agent-side in-process harness for
  `take_over_bare_process()` / `take_over_bare_process_with_id()` (`:84` declares a
  fake `claude` proc). CO-REPORTED: exercises the backend the dashboard's take-over
  flow calls over the socket.
- **`tests/test_transcript.php`** — `ArchivedSessionService::search_transcripts()`
  (`:559-576`) and `test_push.php` — `PushTimerService::get/set_push_timer_interval()`
  (`:588-625`). These test the *backends* the dashboard's search box and interval
  control surface; reported as shared, not wholly owned here.

Overall shape: **happy + sad path** — CSRF/same-origin rejections, wrong session
names, no-match searches, and unknown pids are all asserted alongside the
success cases.

## 7. Dependencies

**Upstream (over the `AgentClient` socket — this subsystem never touches tmux or
`/proc` directly):**

- `App\AgentClient::agent_call()` — the one transport for every `list` /
  `create` / `kill` / `kill_bare` / `cleanup` / `resume` / `install_session_hook` /
  `list_archived` / `search_transcripts` / `take_over_bare` /
  `take_over_bare_with_id` / `check_session_hook` / `push_public_key` /
  `health_check` / `get_push_timer_interval` / `set_push_timer_interval` request.
- Backend services it (indirectly) reaches: `SessionService` (`session-core`),
  `ArchivedSessionService` (`archived-sessions`), `BareProcessService`
  (`session-lifecycle`), `PushHealthService` + `PushTimerService`
  (`push-notifications`).

**Web-side:**

- `App\Services\AuthService` — `start_app_session()`, `csrf_token()`,
  `require_csrf()`, `same_origin_or_no_origin()` (via the guard helpers and the
  two inlined calls in `index()`/`handleAction()`).
- `App\Views\View` — shared Plates engine rooted at `src/partials/`
  (`View.php:22-27`); `App\Views\PageView::render_index_page()` (`PageView.php:34-37`)
  and `PageView::AGENT_OPTIONS` (`:27`).
- `App\Views\BlockedPromptView` — `blocked_prompt_panel_html()`,
  `blocked_prompt_rich_html()`, `last_message_preview_html()` invoked from
  `SessionRowView::session_row_html()` (`:43-50`).
- `App\Assets::versioned_url()` for `common.js`/`index.js` cache-busting.
- JS shared helpers from `public/js/common.js` (loaded first):
  `shouldConfirmBeforeAnswer()` `:139`, `shiftKeyPhysicallyHeld` `:164`,
  `POLL_INTERVAL_STORAGE_KEY` `:179`, `POLL_INTERVAL_ALLOWED_MS` `:180`,
  `parseJsonResponse` `:187`, `postAnswerPrompt` `:214`, `postAnswerMultiQuestion`
  `:229`, `collectMultiQuestionAnswers` `:260`, `handleMultiQuestionFreetextToggle`
  `:328`, `escapeHtml` `:359`, `wireClearButton` `:383`, `relativeTimeLabel` `:477`,
  `highlightSnippet` `:500`.

**Downstream (owned elsewhere, called/included by the dashboard page):**

- `SessionController::history` (`/session_history.php`) for "Show last 3 messages"
  (`index.js:422`); `BrowseController::browse`/`mkdir` (`/browse.php`,
  `/create_folder.php`) for the New Session folder browser (`index.js:488,538`);
  `QuotaFooterView` and `PushNotifyView`.

**Reverse: what depends on this subsystem:**

- `public/js/sidebar.js` (`/sessions_list.php`) and `public/js/search.js`
  (`/search_sessions.php` global scope) — session-view's sidebar/search consume
  this subsystem's `list()`/`search()` endpoints.
- `TranscriptView` (`:607,752`) and `pages/archived-session.php` (`:30`) reuse
  `SessionRowView::relative_time()`; `common.js:472` and `quota-footer.js:90`
  mirror it in JS.

## 8. Data & schema

**No database/queries are owned here.** The dashboard is a pure read + render
surface over `AgentClient` responses; the row/model shapes below come from
`session-core`'s `SessionService::build_session_entry()` and are reshaped only
for display.

**Live session row shape** (`list` → `sessions[]`, as consumed by
`SessionRowView::session_row_html()` / `session_count_label_html()`), from the
canned fixture (`tests/fixtures/canned_agent.php:127-165`):

`{name:string, activity:int (unix), attached:bool, pid:int, workdir:string,
spawned_by_csm:bool, title:?string, working:bool, blocked_reason:?string,
resume_hint:?string, current_mode:string, last_message:
{role:string, timestamp:string, blocks:array<{kind:string, text?:string}>}}`.
Extra fields for a blocked session: `prompt_context`, `prompt_options[]`,
`prompt_is_folder_trust:bool`, plus (from `SessionStatusStore`) the
`prompt_questions[]` used by multi-question AskUserQuestion. The specific keys
`SessionRowView` reads: `name` (`row.php:15,51`), `title` (`row.php:17`),
`agent`/`agent_label` (`row.php:20-26`), `workdir` (`row.php:29`),
`activity`/`relativeTime` (`row.php:31`), `attached` (`row.php:33`),
`context_used_percentage` (`row.php:36`), `git_worktree` (`row.php:38-41`),
`working`/`blocked_reason` (`dashboard_thinking_indicator_html() :27`),
`last_message` (`:49`).

**Bare-process row shape:** `{pid:int, cwd:string, started_at:int (unix),
tmux_session:string, title:string}` (`canned_agent.php:158-164`), rendered by
`bare_process_row_html()` `:107-119`.

**Archived row shape:** `{claude_session_id:string, cwd:?string, title:string,
last_activity:int}` plus `agent`/`agent_label` (from `ArchivedSessionService`) —
rendered by `archived_session_row_html()` `:169-180`. CO-OWNED with
`archived-sessions`.

**`fragment()` response shape:** `{ok:true, session_count_html:string,
sessions_html:string, bare_html:string}` — pre-rendered HTML, not raw rows
(`DashboardController.php:214-219`).

**`archivedFragment()` response shape:** `{ok:true, archived_html:string}`
(`:258-261`). **`search()` response shape:** raw
`search_transcripts` result: `{ok:bool, results:array<{claude_session_id,
session_name:?string, title, cwd:?string, last_activity:int,
matches:array<{line:int, snippet:string, role:?string, kind:string,
timestamp:?int}>}>}` — `session_name` non-null ⇒ `session.php`, null ⇒
`archived_session.php`, per `index.js:954-956`.

**Health box shape** (`health_check` → `checks[]`): `array<{key:string,
label:string, ok:bool, detail:?string}>` — rendered directly in
`health-box/box.php:7-10`. The `pushTimerIntervalSeconds` value comes from
`get_push_timer_interval` (`interval_seconds`), folded into the same panel.

**Bootstrap shape:**
`window.CSM_BOOTSTRAP = {agentReachable:bool}` (`pages/index.php:222`) — read by
`index.js:585` to decide whether to start polling.

**Constants:** `PageView::AGENT_OPTIONS = ['claude'=>'Claude Code',
'antigravity'=>'Antigravity','opencode'=>'OpenCode']` (`PageView.php:27`);
`HealthBoxView` push presets `[5,10,15,30,60,120]` (`HealthBoxView.php:37`);
`index.js` `PAGE_SIZE = 25` (`:754`) and debounce `400`ms (`:1036`); the
polling interval presets come from `common.js`'s `POLL_INTERVAL_ALLOWED_MS`
(`[1000,3000,5000,10000,15000]`, default `3000`).

## 9. Co-owned / cross-subsystem

These are physically owned by another subsystem but are this subsystem's
feature-specific code (or vice versa) and are REPORTED here per the co-report
model. No physical split.

- **`src/lib/Views/SessionRowView.php`** — the archived and bare *render
  methods* live here (dashboard-owned) but render partials owned elsewhere:
  - `archived_session_row_html()` `:169-180` and `archived_sessions_html()`
    `:190-205` → render `session-row/archived-row.php` /
    `archived-list.php` / `archived-empty-state.php` (owned by `archived-sessions`).
  - `bare_process_row_html()` `:107-119` and `bare_processes_html()` `:128-143`
    → render `session-row/bare-process-row.php` / `bare-processes.php` (owned by
    `session-lifecycle`), including the take-over form whose confirm/semantics
    are `session-lifecycle`'s.
- **`src/lib/Views/HealthBoxView.php`** `:31,69` and `health-box/box.php`,
  `health-box/push-timer-interval-control.php` — the VIEW is dashboard-owned
  (Andres's #4 decision), but the BACKEND it renders is `push-notifications`:
  `PushHealthService::health_check()` (`host-agent/lib/Services/PushHealthService.php:174`)
  and `PushTimerService::get_push_timer_interval()` /
  `set_push_timer_interval()`. The interval Save form POSTs `action=
  set_push_timer_interval` back through `DashboardController::handleAction()`.
- **`public/js/index.js`** `:725-906` (archived toggle: `applyPagination`,
  `filterArchivedRows`, agent-filter buttons) and `:908-1038` (dashboard-wide
  content-search renderer) — these client renderers consume
  `archived-sessions`' `archived_html` and `search_transcripts` results. The
  file is the dashboard's; the feature logic is `archived-sessions`'.
- **`public/js/index.js`** `:196-331` — the take-over flow client. Its backend
  (`BareProcessService::take_over_bare_process()` /
  `take_over_bare_process_with_id()`) and the bare-row partials are
  `session-lifecycle`'s.
- **`src/partials/pages/index.php`** `:130,157,210,216` — includes
  `PageView::AGENT_OPTIONS`, `TranscriptView::MODE_OPTIONS`,
  `QuotaFooterView::quota_footer_html()`, and
  `PushNotifyView::push_notify_button_html()`, owned by `session-view`/`quota`/
  `push-notifications` respectively.
- **`SessionRowView::relative_time()`** `:207` — reused by `TranscriptView`
  (`:607,:752`), `pages/archived-session.php` (`:30`), and mirrored in JS
  (`common.js:472`, `quota-footer.js:90`). The row-*model* it sizes is
  `session-core`'s, but the formatter is dashboard-owned.
- **Tests:** `test_ui_smoke.php`, `test_sessions_lifecycle.php`,
  `test_transcript.php`, `test_push.php` all touch dashboard-invoked endpoints
  or their backends; reported as shared across `archived-sessions`,
  `session-lifecycle`, and `push-notifications`.

## 10. Conventions & quirks

- **ES5-only `index.js`** (`var`, `function`, `@ts-check`, no `const`/`let`/arrow/
  `template literals`/`Set`) — no transpiler, and mobile Safari has repeatedly
  driven the constraint (see `row.php`'s "never nests a `<button>`/`<form>`
  inside an `<a>`" comment `:9-14` and `index.php`'s `text-base` search-input
  auto-zoom comment `:40-44`). Match it; don't introduce ES6+.
- **One source of truth for row markup.** `SessionRowView` methods are used by BOTH
  the SSR render (`index.php:180,184`) and the `fragment()` poll response
  (`DashboardController.php:216-218`) so there is never two copies of a row to
  keep in sync (docblock `:35-40`).
- **Visibility-gated polling.** `index.js:580-723` polls `/sessions_fragment.php`
  only while the tab is visible (stops on `visibilitychange`/`pagehide`), shares
  the `localStorage` poll-interval key with `session.js`, aborts in-flight polls
  on interval change, and skips `innerHTML` replacement when the fragment hasn't
  changed (`lastSessionsHtml`/`lastBareHtml`, `:614-615`) so an expanded
  "Show last 3 messages" panel or a focused reply box survives a poll.
- **Deliberately separate endpoint scopes.** `archivedFragment()` and `search()`
  are NOT in the poll (a `~/.claude/projects` scan or a cross-transcript grep on
  a 3-15s timer is wasted work) — fetched lazily/on-demand (docblocks
  `:235-243`, `:264-272`); `list()` is consumed by session.php's sidebar, not the
  dashboard's own poll.
- **Take-over flow (phase 6, unify-claude-sessions).** The bare row's form
  `onsubmit` runs the confirm dialog, then `index.js:244-331` POSTs to
  `/take_over_bare.php` and branches on `needs_choice` (render a picker from
  `data.candidates`/`data.suggested_claude_session_id`, no second fetch) vs
  `data.name` (redirect to the resumed session). The picker renders a no-candidates
  "No past conversations" state with only a Cancel button (`:227-231`).
- **iOS Safari fixes are structural, not cosmetic.** `#app-shell` flex column with
  `#page-content` as the sole scroll container and a non-fixed `#dashboard-footer`
  (`index.php:17,207`) replace a `position:fixed` footer that visually detaching
  mid-scroll (see `index.php:199-206`); the search input is `text-base` to dodge
  the viewport auto-zoom; `appearance-none` suppresses the native WebKit
  search-cancel button (`:40-44`).
- **Blocked-prompt treatment precedence.** A working-but-blocked session shows the
  blocked prompt, NOT the "Thinking…" badge — `dashboard_thinking_indicator_html()`'s
  `!empty($s['blocked_reason'])` guard wins (`SessionRowView.php:27`), asserted by
  `test_ui_smoke.php:114-118`.
- **Every dashboard action re-validates state server-side.** The controller re-checks
  a session name for `kill`, a `pid` for `kill_bare`/take-over, `workdir`/`cwd`, and
  CSRF each request against the agent's authoritative answer — never trusting the
  already-rendered row (CLAUDE.md's re-validate pattern).
- **Dense docblocks record live-found bugs** (e.g. the stretched-link
  `row.php:7-9` "found live" note, the footer `index.php:199-206` note) — read them
  before assuming something is over-engineered; the non-obvious behavior is
  documented in place.
