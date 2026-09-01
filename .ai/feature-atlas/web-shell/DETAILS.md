---
id: web-shell
name: Web front-controller, router, protocol client, auth/CSRF, page shell, assets
owned_paths: [public/index.php, public/.htaccess, public/css/tailwind.css, public/js/common.js, public/js/types.d.ts, src/routes.php, src/lib/Http/Router.php, src/lib/AgentClient.php, src/lib/Assets.php, src/lib/Services/AuthService.php, src/lib/Controllers/Controller.php, src/lib/Views/View.php, src/lib/Views/PageView.php, src/partials/layout.php, src/partials/header.php, package.json, tsconfig.json, tests/test_ui_smoke.php, tests/test_session_replay.php, tests/test_session_replay_browser.php]
last_scanned_commit: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# Web Shell

The container-side request plumbing and shared page shell: how a browser request becomes a controller method, how the container talks to the host agent, the session/CSRF guard, cache-busting, and the shared Plates layout/header that every full page renders through.

## Identity

- **id:** `web-shell`
- **name:** Web front-controller, router, protocol client, auth/CSRF, page shell, assets

## Ownership boundary

**In scope** (all under `public/` and `src/` except per-feature views/controllers/JS, plus the frontend build config):

- `public/index.php` — the one front controller.
- `public/.htaccess` — Apache rewrite; the nginx `try_files` equivalent is documented in `README.md:151`.
- `public/css/tailwind.css` — generated Tailwind build output.
- `public/js/common.js`, `public/js/types.d.ts` — shared JS utility + ambient types.
- `src/routes.php`, `src/lib/Http/Router.php` — route table + exact-path matcher.
- `src/lib/AgentClient.php`, `src/lib/Assets.php` — the container↔agent protocol client and cache-busting.
- `src/lib/Services/AuthService.php` — session + CSRF (no login; access is LAN binding).
- `src/lib/Controllers/Controller.php` — the shared abstract base controller (guard helpers + binary streaming).
- `src/lib/Views/View.php`, `src/lib/Views/PageView.php` — shared Plates base + full-page render wrapper.
- `src/partials/layout.php`, `src/partials/header.php` — the shared page shell and session-page header.
- `package.json`, `tsconfig.json` — Tailwind CSS build and `tsc` type-check (dev-only; never needed to run the app).
- The three test files listed.

**Out of scope** (per-feature, each its own subsystem): the concrete per-feature controllers (`DashboardController`, `SessionController`, `BrowseController`, `UploadController`, `PushController`, `QuotaController`), the per-feature `App\Views\*` classes (`TranscriptView`, `SessionRowView`, `BlockedPromptView`, `QuotaFooterView`, `HealthBoxView`, `PushNotifyView`, `MarkdownRenderer`), all per-feature partials under `src/partials/{transcript,blocked-prompt,session-row,pages,quota-footer,push-notify,health-box,sidebar,compose-bar}` (except `layout.php`/`header.php`), and per-feature JS (`session.js`, `index.js`, `sidebar.js`, `markdown.js`, `scroll.js`, `highlights.js`, `search.js`, `quota-footer.js`, `push-notify.js`, `archived-session.js`). The host-agent (`host-agent/`) is a separate runtime entirely.

Neighboring subsystems that consume this shell: `session-view`, `session-core`, `prompt-interaction`, `push-notifications`, `quota`, `session-lifecycle`, `archived-sessions`, `agent-abstraction`.

## Key implementation files

- `public/index.php` — Front controller. Strips the query string, returns `false` for static assets (`/js/*.js`, `/css/*.css`, `/sw.js`) so the real web server serves them (or `php -S`'s own fallback — never echoes before that, to avoid leaking into the next served file); otherwise loads the Composer autoloader, `require`s `routes.php`, calls `Router::match()`, hard-404s on no match, else instantiates the mapped controller and calls the method. (`public/index.php:23-42`)
- `src/routes.php` — Builds the `Router` and registers every exact-path GET/POST pairing in one flat table, returning the instance. Notably every mutating-POST endpoint also registers a GET to the SAME method, so the method's `require_post_json()` — not the router — produces the 405. (`src/routes.php:13-87`)
- `src/lib/Http/Router.php` — Deliberately simple exact-string matcher, `array<string, array<string, array{0: class-string, 1: string}>>` keyed method→path→`[class, method]`, no groups/middleware/path params. `match()` is pure with no output. (`src/lib/Http/Router.php:18-37`)
- `src/lib/AgentClient.php` — The container↔agent contract. `agent_socket_path()` reads `CSM_AGENT_SOCKET` (default `/run/csm-agent.sock`); `agent_call()` opens the UNIX socket, writes one JSON request, shuts down the write side, reads the whole response to EOF, JSON-decodes it, and maps connect/parse failures to a uniform `['ok' => false, 'message' => ...]`. (`src/lib/AgentClient.php:18-52`)
- `src/lib/Assets.php` — Cache-busting for static files: `versioned_url()` appends `?v=<filemtime>` to a public path so a code change always yields a new URL (iOS Safari home-screen PWA cache). (`src/lib/Assets.php:20-26`)
- `src/lib/Services/AuthService.php` — No login (access is LAN binding). Provides the two CSRF layers (`same_origin_or_no_origin()` + session-bound token) and the session bootstrap with tuned cache/cookie lifetimes for WKWebView bfcache compatibility. (`src/lib/Services/AuthService.php:23-112`)
- `src/lib/Controllers/Controller.php` — Abstract base with `require_post_json()`, `start_readonly_json()`, and the shared binary-stream writer. (`src/lib/Controllers/Controller.php:22-122`)
- `src/lib/Views/View.php` — Owns the one lazily-built `League\Plates\Engine` rooted at `src/partials/` and exposes `render()`. (`src/lib/Views/View.php:18-27`)
- `src/lib/Views/PageView.php` — Thin full-page wrappers (`render_session_page`/`render_index_page`/`render_archived_session_page`) plus the `AGENT_OPTIONS` view-layer constant. (`src/lib/Views/PageView.php:14-42`)
- `src/partials/layout.php` — The page shell: `<html>`/`<body>` fixed-shell classes, the navigation blanket, the read-only and editable fullscreen modals, and the `content`/`style`/`head-extra` section anchors. (`src/partials/layout.php:1-93`)
- `src/partials/header.php` — The sticky session-page top header (title, cwd, poll-interval dropdown, sidebar toggle + notify dot). (`src/partials/header.php:1-29`)
- `public/js/common.js` — Shared byte-identical helpers loaded first on every page (viewport sync, scroll trap, CSRF/answer helpers, copy-to-clipboard, fullscreen modals, navigation blanket). ES5 only. (`public/js/common.js`)
- `public/js/types.d.ts` — Ambient `Window.CSM_BOOTSTRAP` / `openFullscreenTextModal` declarations for `tsc`. (`public/js/types.d.ts:7-21`)
- `public/css/tailwind.css` — Generated (single-line) Tailwind v4 build output; do not hand-edit. Built from `resources/tailwind.css` via `npm run build:css`.
- `package.json` / `tsconfig.json` — Dev-only tooling: `build:css` (Tailwind CLI) and `typecheck` (`tsc --noEmit` over `public/js/**/*.js` with `checkJs`). `package.json:4-17`, `tsconfig.json:1-10`.

## Public interfaces & contracts

### `public/index.php` (entry point)
Invoked by the web server for every non-static request. It does the static-asset passthrough check, then dispatches. No return value/service signature — it is a plain script. Behavior contract: static path → `return false` (let the front server serve the real file); unmatched route → `http_response_code(404)` + `echo 'Not found'` + `return`; matched → `(new $controllerClass())->$methodName()`.

### `App\Http\Router`
- `get(string $path, array $handler): void` — registers a GET route; `$handler` is `array{0: class-string, 1: string}`. (`Router.php:22-25`)
- `post(string $path, array $handler): void` — registers a POST route. (`Router.php:28-31`)
- `match(string $method, string $path): ?array` — returns the `[class, method]` handler or `null`. Pure; no side effects. (`Router.php:34-37`)
- Pre/post: no path params; every dynamic value is a query param (see `routes.php`).

### `src/routes.php` (returns a `Router`)
- Returns `App\Http\Router`. Registers exact paths; the full table lives at `routes.php:21-85`.

### `App\AgentClient`
- `static agent_socket_path(): string` — the UNIX socket path from `CSM_AGENT_SOCKET`, defaulting to `/run/csm-agent.sock`. (`AgentClient.php:18-22`)
- `static agent_call(array $request): array` — one request, one response over the socket. On a connect failure returns `['ok' => false, 'message' => "Cannot reach host agent ({$errstr}). Is the csm-agent.socket systemd unit running on the host?"]`; on non-array JSON response returns `['ok' => false, 'message' => 'Malformed response from host agent']`; otherwise returns the decoded agent response array (typically `['ok' => true, ...]`). `$request` is arbitrary `array<string, mixed>` (an `action` plus params). (`AgentClient.php:28-52`)
- Framing: socket opened with a 5s connect timeout; request written then `stream_socket_shutdown(SHUT_WR)`; response read to EOF; socket closed.

### `App\Assets`
- `static versioned_url(string $urlPath): string` — returns `"$urlPath?v=$mtime"` if the file exists, else the bare `$urlPath`. `$urlPath` is the public path used in a `<script src>`/`<link>` (e.g. `"/js/session.js"`). (`Assets.php:20-26`)

### `App\Services\AuthService`
- `static same_origin_or_no_origin(): bool` — true if there's no `HTTP_ORIGIN`/`HTTP_REFERER`, or its host[:port] matches `HTTP_HOST`. (`AuthService.php:23-38`)
- `static start_app_session(): void` — idempotent-safe session start; uses `private_no_expire` cache limiter, `session_cache_expire(1)` (1-minute max-age), `gc_maxlifetime`/`cookie_lifetime` = 30 days. (`AuthService.php:74-83`)
- `static csrf_token(): string` — returns this session's token, generating `bin2hex(random_bytes(32))` on first use. (`AuthService.php:89-96`)
- `static require_csrf(): void` — 403 + `exit` unless `$_POST['csrf_token']` matches via `hash_equals`. (`AuthService.php:102-112`)

### `App\Controllers\Controller` (abstract base)
- `protected require_post_json(): void` — starts the session; 405 (`{'ok': false, 'message': 'POST required'}`, `Content-Type: application/json`) if not POST; 403 if cross-origin; `require_csrf()`; then sets JSON content-type. This is what makes a GET to a POST-only route return 405 (the router registers GET+POST to the same method). (`Controller.php:31-51`)
- `protected start_readonly_json(): void` — starts the session, sets `Cache-Control: no-store` + JSON content-type. (`Controller.php:54-59`)
- `protected static stream_binary_result(array $result, bool $immutable = false, bool $inlineText = false): void` — streams a host-agent binary result (`data` base64 + `media_type` + `filename`) as the real HTTP response. `ok` false → 404 text; bad base64 → 502; images always inline, others attach unless `$inlineText` for `text/*`; `$immutable` adds `private, max-age=86400, immutable`. (`Controller.php:82-109`)
- `protected static content_disposition_safe_filename(string $filename): string` — strips control chars and `"` for the `Content-Disposition` filename. (`Controller.php:117-122`)

### `App\Views\View` / `App\Views\PageView`
- `View::render(string $template, array $data = []): string` — renders a Plates template from `src/partials/`. (`View.php:22-27`)
- `PageView::render_session_page(array $data): string` → `pages/session`; `render_index_page()` → `pages/index`; `render_archived_session_page()` → `pages/archived-session`. (`PageView.php:29-42`)
- `PageView::AGENT_OPTIONS` — `['claude' => 'Claude Code', 'antigravity' => 'Antigravity', 'opencode' => 'OpenCode']`, a view-layer mirror of `HostAgent\Agents\AgentRegistry` (the container never reaches host-agent config). (`PageView.php:27`)

### `src/partials/layout.php` (Plates layout)
Requires `$title`, `$viewportContent`, optional `$fixedShell` (bool); renders sections `content` (required, `layout.php:91`), `style` (`:21`), `head-extra` (`:19`). Fixed-shell pages get `h-full overflow-hidden` on `<html>` and `h-[var(--app-vh,100dvh)] overflow-hidden` on `<body>` (`:14`, `:23`), plus the `#navigation-blanket`, `#fullscreen-text-modal`, and `#fullscreen-edit-modal` chrome.

### `src/partials/header.php`
Expects `$found` (bool), `$detail` (array; `title`/`name`/`workdir`). Renders the sticky header with `#header-title`, `#header-cwd`, `#poll-interval-select`, `#sidebar-toggle-btn`, `#sidebar-notify-dot`. (`header.php:1-29`)

### `public/js/common.js` (globals + `window` exports)
Loaded first before the per-page script on `session.php`, `index.php`, and `archived-session.php`. Exposes, as plain top-level globals (ES5):
- `parseJsonResponse(r, label)` — reads text then JSON-parses, returning `{ok:false, message:'Unexpected response [label] (status N): ...'}` on parse failure. (`common.js:187-195`)
- `shouldConfirmBeforeAnswer()` — reads localStorage `csm-confirm-before-answer`. (`common.js:139-145`)
- `postAnswerPrompt(bodyParams, label)` / `postAnswerMultiQuestion(sessionName, csrfToken, answers, label)` — fetch POSTs returning the parsed JSON. (`common.js:214-240`)
- `collectMultiQuestionAnswers(wrapper)` — walks a `.multi-question-wrapper`'s `[data-question-index]` groups into `{answers, summaryParts}` or `null` if any unanswered. (`common.js:260-314`)
- `handleMultiQuestionFreetextToggle(target)`, `escapeHtml(text)`, `wireClearButton(fieldEl, buttonEl, confirmMessage)`, `wireTouchTooltip(el)`. (`common.js:328-470`)
- `relativeTimeLabel(timestamp)`, `highlightSnippet(snippet, query)`. (`common.js:477-509`)
- `copyTextToClipboard(text)` — `navigator.clipboard` (secure context) with a `textarea`+`execCommand('copy')` fallback; returns a Promise. (`common.js:520-545`)
- `openAncestorDetails(target)` — opens every ancestor `<details>` (for jump-to-search-result). (`common.js:956-966`)
- `watchFixedFooterHeight(footerEl, onHeightChange)` — `ResizeObserver` to keep content clear of a variable-height fixed footer. (`common.js:935-943`)
- `window.openFullscreenTextModal` — explicitly exposed on `window` because it's declared inside a block scope; used by `sidebar.js`'s "Open todo file" link. (`common.js:832`)

## Major call sites

- **Front controller** — `public/index.php` is the sole entry for every non-static request; the web server (Apache `.htaccess`, nginx `try_files`, or `php -S` router-script) routes all traffic to it. `routes.php` is `require`d only by `index.php:31`.
- **Router** — consumed only by `public/index.php:32` (`$router->match(...)`).
- **AgentClient::agent_call()** — every controller and one view: `DashboardController`, `SessionController`, `UploadController`, `BrowseController`, `PushController`, `QuotaController`, and `App\Views\TranscriptView` (grep `src/lib/`). This is the whole container↔host-agent surface.
- **AuthService** — every controller plus the base `Controller` guards. `require_post_json()` is used by `PushController`, `UploadController`, `DashboardController`, `BrowseController`, `SessionController`; `start_readonly_json()` by `QuotaController`, `UploadController`, `DashboardController`, `SessionController`.
- **Assets::versioned_url()** — `layout.php:20` (tailwind.css), and the page partials `pages/{session,index,archived-session}.php`, plus `push-notify/button.php:7` (push-notify.js) and `quota-footer/footer.php:13` (quota-footer.js).
- **View::render() / PageView** — every per-feature `App\Views\*` class extends `View`; the three full pages are rendered via `PageView` static methods from the controllers.
- **layout.php** — used as the Plates layout by `pages/session.php`, `pages/index.php`, `pages/archived-session.php`.
- **header.php** — included by `pages/session.php:194` (the live session page only).
- **common.js** — loaded first on `pages/session.php:353`, `pages/index.php:224`, `pages/archived-session.php:96`; its helpers are called from `session.js`, `index.js`, `sidebar.js`, `search.js`, `scroll.js`, `highlights.js`, `quota-footer.js`, `push-notify.js`, `markdown.js`.

## Tests

- `tests/test_ui_smoke.php` — boots `php -S` from `public/` against a canned fake-agent socket and drives it with curl. Extensive happy + sad path. Web-shell relevant: static assets (`/js/common.js?v=...`, `/sw.js`, etc.) return 200 as real files, not 404 (`:130`, `:134`, `:467`, `:907`); cache-busting `?v=<mtime>` present on every script/link (`:126`, `:463`); layout shell assertions (`overscroll-y-none` at `:85`/`:310`, `h-full overflow-hidden` on `<html>` at `:303`, `h-[var(--app-vh,100dvh)]` at `:302`, `navigation-blanket` present on both pages `:124`/`:437`); CSRF wrong-token → 403 `:159-162`; POST-required → 405 via GET to `/answer_prompt.php`, `/session_send.php`, `/session_mode.php`, `/session_escape.php`, `/push_subscribe.php`, `/push_unsubscribe.php`, `/upload_file.php` (`:796`, `:941`, `:977`, `:1002`, `:1027`, `:1053`, `:1069`); `common.js` contents shipped (pagehide/pageshow blanket `:131-132`, `copyTextToClipboard` `:486`, `openFullscreenTextModal` html param `:510`, swipe-to-close `:489-491`); resource 404s (`:723`, `:737`, `:745`).
- `tests/test_session_replay.php` — curl tier of the replay flow; real transcript file behind a real isolated tmux pane, real host-agent over a real socket. Happy + sad (refuses to run if `TMUX_SOCKET` is the real host socket `:26-29`). Exercises `session.php`, `session_history.php`, `session_detail.php`, `answer_prompt.php`, `answer_multi_question.php`, `session_send.php` through the real front controller, including CSRF token extraction from the rendered page `:36-39` and a 403-guarded flow.
- `tests/test_session_replay_browser.php` — browser tier; drives the actual client-side JS (real poll loop, real `fetch()`, real DOM click dispatching a real `submit`) via a hand-rolled CDP client. Happy + sad; SKIPs (exit 0) if no headless Chrome. Covers `common.js` fullscreen-edit modal's live two-way sync (`:491-497`), `openAncestorDetails()` opening a collapsed tool-call entry on `jump_line=4` (`:574-577`), and `console.error`/uncaught-error drains (`browser_assert_no_console_errors`, `:228-233`).

## Dependencies

**Upstream (none for the shell itself):** `index.php`/`routes.php`/`Router`/`AgentClient`/`Assets` are leaf plumbing. `AuthService`, `Controller`, `View`, `PageView`, `layout.php`, `header.php`, `common.js` are shared primitives that other subsystems build on.

**Downstream (what this shell serves), via the route table / base classes / shared template / common.js:**
- Every controller (`DashboardController` via `/`, `/sessions_fragment.php`, `/sessions_list.php`, `/archived_sessions_fragment.php`, `/take_over_bare*.php`, `/search_sessions.php`; `SessionController` via `/session*.php`, `/answer_prompt.php`, `/answer_multi_question.php`, `/archived_session*.php`; `BrowseController`; `UploadController`; `PushController`; `QuotaController`).
- Every per-feature `App\Views\*` class and every partial under `src/partials/{transcript,blocked-prompt,session-row,pages,quota-footer,push-notify,health-box,sidebar,compose-bar}`.
- Every feature JS file via the shared script-tag order and `Assets::versioned_url()`.

**External / libraries:** `vendor/autoload.php` (Composer), `League\Plates\Engine` (`View.php:24`), `minishlink/web-push` (used by `PushController`/push subsystem via the agent, not directly by the shell). Dev-only: `tailwindcss`, `@tailwindcss/cli`, `typescript` (`package.json:21-23`).

**On the other side of the protocol:** `host-agent/agent.php` — the systemd socket-activated host-native agent that `AgentClient` talks to. The shell never calls tmux/`/proc` directly; the UNIX socket is the only boundary.

## Data & schema

- **Route table shape** (`Router::$routes`): `array<string, array<string, array{0: class-string, 1: string}>>` — outer key = HTTP method (`'GET'`/`'POST'`), inner key = exact path string, value = `[ControllerClass, methodName]`. (`Router.php:18-19`)
- **Session/CSRF** (`AuthService`): PHP `$_SESSION` holds `csrf_token` (64-char hex from `bin2hex(random_bytes(32))`) and flash messages (e.g. `Created session`, `Killed`, `Rejected` — used by `DashboardController` redirects). `SESSION_LIFETIME_SECONDS = 60*60*24*30` (30 days) applied to both `session.gc_maxlifetime` and `session.cookie_lifetime`; `session_cache_expire(1)` → `Cache-Control: max-age=60` on HTML pages; `private_no_expire` cache limiter for WKWebView bfcache. (`AuthService.php:16`, `:74-83`, `:89-96`)
- **`PageView::AGENT_OPTIONS`**: `['claude' => 'Claude Code', 'antigravity' => 'Antigravity', 'opencode' => 'OpenCode']`. (`PageView.php:27`)
- **`window.CSM_BOOTSTRAP`** (typed in `types.d.ts:7-16`): `{ session?, csrfToken?, newestLine?, claudeSessionId?, jumpLine?, workdir?, agent?, agentLabel? }`. Populated in `pages/session.php` by `json_encode` at `:342-352`.
- **`stream_binary_result` result shape** (`Controller.php:82-109`): `['ok' => bool, 'data' => base64-string, 'media_type' => string, 'filename' => string, 'message' => string?]`.
- **layout.php template data**: `title` (string), `viewportContent` (string), `fixedShell` (bool, optional); sections `content`, `style`, `head-extra`. (`layout.php:14-91`)
- **header.php template data**: `found` (bool), `detail` (array `{title?, name, workdir?}`). (`header.php:6-10`)
- **Tailwind classes**: the shell/views use the Tailwind v4 classes compiled into `public/css/tailwind.css` (e.g. `h-[var(--app-vh,100dvh)]`, `overscroll-y-none`, `z-[60]`, `grid-cols-[auto_1fr_auto]`, `max-w-2xl lg:max-w-4xl`). The CSS is an opaque generated artifact; the source of truth is the class usage in the partials.

## Conventions / quirks

- **Static-path passthrough:** `public/index.php:25-27` matches `/sw.js`, `/js/<word>.js`, `/css/<word>.css` against the query-string-stripped path (via `parse_url`, `:23`) and returns `false` *before* echoing anything or requiring `vendor/autoload.php`. This means `Assets::versioned_url()`'s `?v=<mtime>` suffix never defeats the match, and no output leaks into the file the front server serves next (only matters under `php -S`; Apache/nginx never reach `index.php` for a real static asset per `.htaccess` / `try_files`). (`index.php:5-27`)
- **Front-controller portability precedent:** `index.php` is a conventional Slim-style front controller, not a `php -S`-router-script-only trick. It works as a `php -S` router-script argument (which is how the Docker container runs it), behind Apache via `public/.htaccess`, and behind nginx via the documented `try_files $uri $uri/ /index.php?$query_string` (`README.md:151`). Lean portable over `php -S`-only even though `php -S` is the only real server today.
- **Two-runtime socket framing:** one request, one response — `fwrite(json)`, `stream_socket_shutdown(SHUT_WR)`, `stream_get_contents()` to EOF, `fclose`. No keep-alive, no length-prefix; the socket is fresh per call (`AgentClient.php:30-43`). The container never touches tmux/`/proc` directly (see `AgentClient.php:7-15`).
- **Routing gives the 405 to the method, not the router:** every POST-only endpoint also registers GET to the same method (`routes.php:15-19`) so a GET hits `require_post_json()` and returns 405 rather than a 404. Each method re-validates state fresh rather than trusting client input.
- **ES5 only in `public/js/*.js`:** `public/js/common.js` uses `var`, `function`, no `const`/`let`/arrow functions/template literals/`Set` — no transpiler, and mobile Safari compatibility is repeatedly the stated reason. `common.js` flags `// @ts-check` while `types.d.ts` supplies the ambient globals.
- **`common.js` loaded first, as top-level globals, not an ES module** (`common.js:1-5`) — every page script depends on it already being defined. `window.openFullscreenTextModal` is explicitly re-exposed because block scope would hide it (`common.js:827-832`).
- **Shared full-page shell beats per-page duplication:** layout.php + header.php + common.js + View keep the fixed-shell/iOS quirks (100dvh recompute, layout-viewport pan, bfcache, swipe-back blanket) in one place, documented with the live bugs they guard against.

## Co-owned / cross-subsystem

This subsystem's shared primitives are consumed by, or duplicatively referenced alongside, many other subsystems — no physical split, so several files are REPORTed here while dominantly owned elsewhere.

- **`public/js/common.js` is the shared utility for every feature.** `copyTextToClipboard()` (`:520-545`) and the delegated `.copy-btn` click handler (`:554-579`) back every `.copy-btn`/`.copy-source` pair rendered by `blocked-prompt/*` and `transcript/*` partials and their JS mirrors. `openFullscreenTextModal()`/`closeFullscreenTextModal()` (`:650-689`) serve `BlockedPromptView` and `TranscriptView` render both in PHP and in session.js. `openAncestorDetails()` (`:956-966`) serves the jump-to-search-result logic in session.js/archived-session.js. `relativeTimeLabel()`, `highlightSnippet()`, `parseJsonResponse()`, `postAnswerPrompt()`, `postAnswerMultiQuestion()`, `collectMultiQuestionAnswers()`, `handleMultiQuestionFreetextToggle()`, `wireClearButton()`, `wireTouchTooltip()`, `watchFixedFooterHeight()` are all called from `session.js`, `index.js`, `sidebar.js`, `search.js`, `scroll.js`, `quota-footer.js`, and the fullscreen-edit modal interacts with `compose-bar.php`, `blocked-prompt/options.php`, and `blocked-prompt/multi-question.php`.
- **`markdown.js` (`public/js/markdown.js`, dominantly owned by `session-view`)** is a shared peer: its `renderMarkdown()` is the poll-time mirror of `MarkdownRenderer::render_html()` and its output is reused by `common.js`'s `openFullscreenTextModal(html)` through the `.markdown-body` sibling (`common.js:703-707`). It loads first among the per-page scripts (`pages/session.php:354`), immediately after common.js.
- **`types.d.ts`** declares `Window.CSM_BOOTSTRAP` consumed by every page script and `window.openFullscreenTextModal` re-exposed from common.js (`types.d.ts:18-21`).
- **`Assets::versioned_url()`** is used by the `push-notifications` (`push-notify/button.php:7`) and `quota` (`quota-footer/footer.php:13`) partials, not just the web-shell pages.
- **`View::render()` / `PageView`** are the base for `TranscriptView`, `SessionRowView`, `BlockedPromptView`, `QuotaFooterView`, `HealthBoxView`, `PushNotifyView`, `MarkdownRenderer` (all `App\Views\*`).
- **`Controller` guards + binary streaming** are used by `SessionController` (session-core / session-view), `UploadController`, `PushController`, `QuotaController` (quota), `BrowseController`.
- **`header.php`** is the session page header only (`pages/session.php:194`), sharing the poll-interval dropdown contract with `pages/index.php`'s own inline header and `archived-session.php`.
