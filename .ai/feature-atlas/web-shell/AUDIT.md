---
id: web-shell
based_on: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-26
---

# Web Shell — Maintainability Audit

Verification note: `DETAILS.md`'s `last_scanned_commit` (`44e4caab…`) matches current `HEAD`, so the code has **not** moved out from under the scout — the map is current. Two *coverage* gaps in DETAILS.md are called out in the Cross-Cutting section (an unlisted test file that exercises the owned `AgentClient`, and a documented-but-red dev gate that DETAILS.md presents as healthy). Neither is commit-skew; both are inventory/completeness drift.

Findings below are ordered by severity (most severe first). Per the audit rubric, every finding is verified against the actual current source at the line numbers cited.

---

## F1 — HIGH — `AgentClient::agent_call()` has no read timeout: a wedged host agent hangs the request forever

- **Recommendation:** `fix`
- **Evidence:**
  - `src/lib/AgentClient.php:30` — connect is bounded: `stream_socket_client('unix://'.…, $errno, $errstr, 5)` (5s connect timeout).
  - `src/lib/AgentClient.php:42` — `$raw = stream_get_contents($socket);` — **no** `stream_set_timeout()` anywhere in the class, so the response read blocks indefinitely.
  - `src/lib/AgentClient.php:39` — `fwrite($socket, json_encode($request));` — return value (and `json_encode() === false`) are unchecked.
  - `tests/test_agent_client_protocol.php:81-96` — the only AgentClient-level sad paths tested are connect-failure and a malformed request/response; **no test for a connection that accepts but never responds**.
- **Current complexity / invalid states:**
  The connect phase is the only thing bounded. In PHP, `max_execution_time` does **not** count blocking stream I/O, so no server-side timeout saves the worker either. If the host agent accepts the socket (systemd socket activation gives it a fresh process) and then wedges — a slow `web-push` send to a real endpoint, a `take_over_bare`/`resume` that spawns something, a hung `tmux` command, or `agent.php` crashing after accepting but before writing — the container's PHP worker is stuck until the process is externally killed. Every controller and one view (`DashboardController`, `SessionController`, `UploadController`, `BrowseController`, `PushController`, `QuotaController`, `App\Views\TranscriptView`) calls `agent_call()` on the request path, so one wedged action ties up the whole request and the browser `fetch()` hangs with no error surfaced. Under `php -S` (single-threaded) this blocks *every* subsequent request to the app.
- **Proposed representation:** Bound the read (and the write) explicitly. Set a per-call read timeout before reading and inspect the socket meta after:
  ```php
  stream_set_timeout($socket, 30);           // or a configurable default
  $raw = stream_get_contents($socket);
  $meta = stream_get_meta_data($socket);
  if ($meta['timed_out']) { fclose($socket); return ['ok' => false, 'message' => 'Host agent timed out']; }
  ```
  And guard the write: if `json_encode()` returns `false`, or `fwrite()` returns fewer than `strlen($encoded)` bytes, close and return an `ok:false` "request could not be sent" rather than silently sending a partial/empty request that the agent will block reading. This keeps the single-response contract intact (still one request → one response) but makes every failure mode a *returned* `['ok' => false, …]`, which is exactly the shape every call site already handles (`(bool)($result['ok'] ?? false)`).
- **Smallest credible implementation scope:** `src/lib/AgentClient.php` only. No interface change; `agent_call()` already returns `['ok' => false, 'message' => …]` on failure and the call sites already branch on `ok`.
- **Regression risks / migration concerns:** A timeout that is too short would mis-classify a genuinely slow (but legal) action as failed — notably large uploads and push-sends. Make the read timeout configurable (`CSM_AGENT_TIMEOUT`, default e.g. 30s) and generous; pick a value comfortably above the slowest legitimate action rather than the fastest. Do **not** reduce the existing 5s connect timeout.
- **Validation:** Existing coverage — `tests/test_agent_client_protocol.php` covers connect-failure and malformed-response only. Add a sad-path: a harness fixture that accepts the socket, drains the request, then never answers (`stream_sleep`/loop) — assert `agent_call()` returns `ok:false` (with the timeout message) within a bounded wall-clock time *rather than hanging the test runner*. The global test rule (happy + sad, and "explicitly rule out crashes/500s") applies: this is exactly the "failing dependency hangs" sad path that is currently untested.
- **Confidence:** `high`
- **Priority/severity:** `high`

---

## F2 — MEDIUM — `npm run typecheck` is red (134 errors); `types.d.ts` has drifted from the real `CSM_BOOTSTRAP` / page contracts

- **Recommendation:** `refactor`
- **Evidence:**
  - I ran `node_modules/.bin/tsc --noEmit` (the script behind `npm run typecheck`, `package.json:7`) — **exit 1, 134 errors**.
  - `public/js/types.d.ts:7-16` declares `CsmBootstrap` with `session/csrfToken/newestLine/claudeSessionId/jumpLine/workdir/agent/agentLabel` — but **omits `agentReachable`**, which `src/partials/pages/index.php:222` sets (`window.CSM_BOOTSTRAP = …['agentReachable' => …]`) and `public/js/index.js:585` reads → `error TS2339: Property 'agentReachable' does not exist on type 'CsmBootstrap'`.
  - `public/js/types.d.ts:18-21` declares `Window` with only `CSM_BOOTSTRAP` and `openFullscreenTextModal` — **omits `CSM_ARCHIVED_BOOTSTRAP`**, which `src/partials/pages/archived-session.php:94` sets and `public/js/archived-session.js:71` and `:104` read → `TS2339`.
  - `public/js/common.js:832` — `window.openFullscreenTextModal = openFullscreenTextModal;` references a `function` declared inside the `if` DOM-guard block at `common.js:650` → `TS2552: Cannot find name 'openFullscreenTextModal'` (TS uses ES6 block-scoped-function semantics; the code *works* at runtime only via ES5 non-strict function-declaration hoisting).
  - Plus a large, uniform family of `TS2339` across every JS file: `Property 'closest' does not exist on type 'EventTarget'` and `Property 'value'/'disabled'/'placeholder' does not exist on type 'HTMLElement'` (e.g. `common.js:555,693,724,859,869`, `index.js:34,484`, `session.js` ×~60). `tsconfig.json:8-10` sets `strict:false`/`noImplicitAny:false`, which does **not** suppress TS2339.
- **Current complexity / invalid states:** The documented dev gate is effectively dead — it has never been green, so it provides no signal at all, and its stated purpose in `types.d.ts:1-5` ("instead of reporting 'Property does not exist on Window' everywhere") is not met. The d.ts is a broken contract against two *actual* page bootstraps it declares itself to cover. Any developer running `npm run typecheck` (as the project's packaging implies it should be part of the workflow) gets 134 cryptic errors that all point at the ambient-types layer.
- **Proposed representation:** (a) Add `agentReachable?: boolean` to `CsmBootstrap`. (b) Add a `CsmArchivedBootstrap { claudeSessionId?: string; jumpLine?: number | null; }` interface and declare `CSM_ARCHIVED_BOOTSTRAP: CsmArchivedBootstrap` on `Window`. (c) For the `.closest`/`.value`/`.disabled` family, the honest fix is typed casts/helpers (e.g. a `closestFromEvent(e)` that returns the nearest match or `null`), which also tightens null-safety. These belong to the *other* subsystems' JS files (`index.js`, `session.js`, `sidebar.js`, `search.js`, `archived-session.js`, `push-notify.js`).
- **Smallest credible implementation scope:** The d.ts additions (`public/js/types.d.ts`) are web-shell-owned and small — do those now. The `.closest`/`.value` mass is cross-subsystem; don't sweep it into this audit. Use the `// @ts-check` + d.ts pattern already in use rather than converting to modules.
- **Regression risks / migration concerns:** Typecheck has no semantic value today, so there is no green state to regress. The risk is the opposite: making `.closest`/`.value` casts could hide a genuine null deref if a listener ever fires on a detached node — but the existing handlers already guard by `closest()` returning `null` (e.g. `common.js:561-558`), so the casts preserve behavior. For the `window.openFullscreenTextModal` error, prefer restructuring so the function is declared at a scope both `tsc` and runtime agree on (declare at top level, or assign from within the guard) rather than suppressing it.
- **Validation:** Re-run `npm run typecheck` and require 0 errors for the web-shell-owned files (`common.js`, `types.d.ts`); the remaining per-feature-file errors are tracked as a cross-subsystem cleanup.
- **Confidence:** `high` (ran the command — not inferred)
- **Priority/severity:** `medium`

---

## F3 — LOW/MEDIUM — Router cannot emit `405` for a method mismatch on GET-only routes; the unmatched-route `404` is plain text with no `Content-Type`

- **Recommendation:** `tweak`
- **Evidence:**
  - `src/lib/Http/Router.php:34-37` — `match()` is a bare `$this->routes[$method][$path] ?? null;`; it cannot distinguish "path exists, wrong method" from "no such path."
  - `src/routes.php:15-19` — the project *deliberately* implements 405 for the POST-only direction by registering GET+POST to the same method so the method's `require_post_json()` emits the 405. That works because the router returns the handler for either verb.
  - Get-only endpoints register only `get()`, e.g. `routes.php:37-50` (`session_history.php`, `session_attachment.php`, `session_search.php`, `quota.php` at `:85`, `browse.php` at `:67`, `sessions_list.php` at `:25`, `sessions_fragment.php` at `:24`). A POST to any of these → `match()` → `null` → `public/index.php:34-39` hard `404` `echo 'Not found'` (no `Content-Type` header set).
- **Current complexity / invalid states:** 405 (Method Not Allowed) is implemented for exactly one direction and 404 (Not Found) for the other — semantically contradictory for a client that POSTs to a read-only route. The project cares about getting 405 right (see the `routes.php` comment), but the router shape makes the opposite direction impossible. The 404 body is also `text` with no charset, and the browser/CLI can't tell a missing endpoint from a wrong-verb one. No *current* client triggers it (the UI only POSTs to the POST+GET-registered endpoints), so it's a correctness-of-contract issue rather than a live bug.
- **Proposed representation:** Keep the deliberately simple matcher, add one tiny capability: a `hasPath(string $path): bool` accessor, and in `public/index.php` emit `405` when the path exists under another verb:
  ```php
  if ($handler === null) {
      if ($router->hasPath($path)) {
          http_response_code(405);
          echo 'Method not allowed';
          return;
      }
      http_response_code(404);
      echo 'Not found';
      return;
  }
  ```
  If the plain-text body is worth tightening, set `header('Content-Type: text/plain; charset=UTF-8')` on both the 404 and 405 branches. Nothing else about the router needs to grow.
- **Smallest credible implementation scope:** `src/lib/Http/Router.php` + `public/index.php` (both web-shell). One new 4-line method + a small branch.
- **Regression risks / migration concerns:** Minimal — no existing request changes behavior; only the previously-404 wrong-verb case becomes 405. Nothing that currently returns `404` for a path that truly does not exist changes.
- **Validation:** Add to `tests/test_ui_smoke.php` an assertion that POST to a GET-only endpoint (`/quota.php`, `/browse.php`) returns `405` (currently unasserted; the suite asserts 405 only for the POST-only endpoints at `:796,:941,:977,:1002,:1027,:1053,:1069`). Keep asserting a truly-unknown path still 404s.
- **Confidence:** `high`
- **Priority/severity:** `low`

---

## F4 — LOW — CSRF/security hardening: no session-fixation mitigation, no cookie `SameSite`/`HttpOnly` flags; origin check is lenient and partly redundant

- **Recommendation:** `tweak`
- **Evidence:**
  - `src/lib/Services/AuthService.php:74-83` — `start_app_session()` calls `session_start()` with no `session_regenerate_id()` anywhere in the class (grep confirms none).
  - `src/lib/Services/AuthService.php:79-80` — only `session.gc_maxlifetime` and `session.cookie_lifetime` are set; `session.cookie_samesite`, `session.cookie_httponly`, and `session.cookie_secure` are never configured (grep across `src/` and `host-agent/` returns nothing).
  - `src/lib/Services/AuthService.php:27-29` — `same_origin_or_no_origin()` returns `true` when there is no `Origin`/`Referer` at all, so the origin layer is a no-op for "no header" requests (privacy settings, `referrerPolicy: no-referrer`) and the real defense is entirely `require_csrf()` (`:102-112`). Line `:37` also accepts `$sourceHost === $host`, which treats a same-host-different-default-port origin as same-origin.
  - `src/lib/Services/AuthService.php:107` — `hash_equals($expected, $provided)` with `random_bytes(32)` (`:92`) is correct; token never leaves the page/response.
- **Current complexity / invalid states:** This is a defense-in-depth observation, **not** a CSRF break. The session-bound token plus `hash_equals` is what actually prevents cross-site POST forgery, and it holds because the token is unreadable cross-origin. But: (a) no `session_regenerate_id` means a pre-set session id survives into the app (moot for escalation here — there are no roles/privileges to escalate — but it contradicts "CSRF blocks a stray cross-site form post" framing, since a fixation attack paired with a same-*host* token leak becomes thinkable); (b) the `PHPSESSID` (and thus the token) rides cleartext over the plain-HTTP LAN serve with neither `HttpOnly` nor `SameSite`; (c) the origin layer's "no header → pass" means it contributes nothing when `Referer` is stripped, so the token layer is the sole guard — worth being explicit about, not relying on.
- **Proposed representation:** In `start_app_session()`, after the guarded `session_start()`, set `session.cookie_httponly = '1'` and `session.cookie_samesite = 'Lax'` (Lax keeps the bfcache/home-screen cross-navigation the app relies on while still blocking the classic cross-site top-level POST that CSRF targets). Do **not** set `cookie_secure` — the app is deliberately served over plain HTTP on the LAN. Session fixation: regenerate only on a *brand-new* session (PHP already issues a new id for a fresh session, so the practical exposure is near-zero here); if regeneration is added, gate it so it does not rotate an id that an already-open tab is holding (that would 403 open tabs on their next POST) — mark this as research-more/optional rather than a hard change.
- **Smallest credible implementation scope:** `src/lib/Services/AuthService.php` only (the two `ini_set` lines + optional regenerate-id guard). No controller/route changes.
- **Regression risks / migration concerns:** `SameSite=Lax` must be confirmed not to break the push-notification service-worker fetch (`sw.js`'s `fetch('/answer_prompt.php' …)`, `test_ui_smoke.php:913`) or the WKWebView iframe embedding noted in `AuthService.php:46-52` — both are same-site and Lax-safe, but verify with the browser smoke tests. `HttpOnly` on `PHPSESSID` is a strict improvement (the token is delivered in-page JSON, not read from the cookie by JS).
- **Validation:** Extend `tests/test_ui_smoke.php` to assert the `Set-Cookie` on `GET /` carries `SameSite=Lax` and `HttpOnly` when the flags are enabled. Existing suite already asserts the CSRF round-trip (`:140-162`) and the poll-keepalive path (`:261-287`).
- **Confidence:** `medium` (the session-regenerate-id behavioral nuance vs open tabs; the cookie flags are trivially safe)
- **Priority/severity:** `low`

---

## F5 — LOW — `require_post_json()` mixes JSON `405` with text `403`, and leaves mutation responses cacheable at the HTML page's `private, max-age=60`

- **Recommendation:** `tweak`
- **Evidence:**
  - `src/lib/Controllers/Controller.php:36-39` — the `405` branch sets `Content-Type: application/json` and emits JSON.
  - `src/lib/Controllers/Controller.php:42-46` — the cross-origin branch emits *plain text* ("Rejected: cross-origin request.") with no `Content-Type`.
  - `src/lib/Controllers/Controller.php:48` — `AuthService::require_csrf()` (`AuthService.php:108-110`) also emits plain text ("Rejected: missing or invalid CSRF token.").
  - `src/lib/Controllers/Controller.php:50` — on success it sets `Content-Type: application/json` but does **not** override `Cache-Control`, so the response keeps the session limiter's `private, max-age=60` (`AuthService.php:77-78`) that is meant for the HTML pages.
  - `src/lib/Controllers/SessionController.php:299` — a comment documents the text-vs-JSON split as deliberate ("plain-text 403 body on failure, same as every other POST handler - the JS caller treats a non-JSON response as a generic send failure").
- **Current complexity / invalid states:** The plain-text `403` is intentional, documented, and correctly handled by the shared `parseJsonResponse()` text→JSON fallback (`common.js:187-195`), so the JS never crashes on it. The genuinely debatable point is the cache header on a **mutation** response: `require_post_json` reuses the four HTML-page cache settings and never pins `no-store` the way `start_readonly_json()` does (`Controller.php:57`). Real browsers don't cache POST responses, so the practical impact is near-zero, but a mutation response carrying `private, max-age=60` is an inconsistency with the read-only endpoints' deliberate `no-store` and is the kind of "forgotten default" that a future cache-related bug would orbit.
- **Proposed representation:** In the `require_post_json()` success path, add `header('Cache-Control: no-store')` so no mutation response is ever nominally cacheable (mirrors the read-only contract). Leave the text `403` bodies as-is (deliberate per `:299`); only, if tightening, unify them to JSON *only if* every caller is JSON (they are not — `DashboardController::handleAction()` shares `require_csrf()` on a redirect path, so keep `require_csrf`'s text body). So: scope to the `Cache-Control: no-store` line only.
- **Smallest credible implementation scope:** `src/lib/Controllers/Controller.php` — one added `header()` line in `require_post_json()`.
- **Regression risks / migration concerns:** None for the JS (returns are handled by status/body already; a POST response becoming `no-store` can only remove the possibility of a stale cached mutation, since browsers don't cache POST anyway).
- **Validation:** Existing `tests/test_ui_smoke.php` asserts `no-store` for the read-only poll endpoints (`:276`) but never inspects the cache header on a POST-JSON response — add one assertion on `/answer_prompt.php` or `/session_send.php` success that `Cache-Control` is `no-store`.
- **Confidence:** `medium`
- **Priority/severity:** `low`

---

## What's done well

- **Static passthrough is correct and complete.** `public/index.php:23-27` strips the query string, then returns `false` for `/sw.js`, `/js/<word>.js`, `/css/<word>.css` *before* `vendor/autoload.php` and before any output, so nothing leaks into the next served file and the `?v=<mtime>` cache-buster never defeats the match. I enumerated `public/js/` and `public/css/`: every real asset (`common.js`, `session.js`, `index.js`, `sidebar.js`, `search.js`, `markdown.js`, `scroll.js`, `highlights.js`, `quota-footer.js`, `push-notify.js`, `archived-session.js`, `tailwind.css`) matches the `[\w-]+` pattern; nothing falls through to routing. `types.d.ts` (not meant to be served) correctly 404s.
- **Portability precedent honored, no `php -S`-only tricks.** `index.php` works as a `php -S` router-script argument, behind Apache via `public/.htaccess:1-6` (`!-f`/`!-d` rewrite), and behind nginx via the documented `try_files $uri $uri/ /index.php?$query_string` (`README.md:151`). The `return false` static path is a portable fallback, not a `php -S`-specific artifact.
- **No GET side-effect anywhere.** Every mutating endpoint registers `GET` *only* so its own `require_post_json()` can emit the 405; I verified each mutating controller method guards with `require_post_json()` — `takeOverBare` (`DashboardController.php:299`), `takeOverBareConfirm` (`:313`), `mkdir` (`BrowseController.php:35`), `upload` (`UploadController.php:39`), `deleteOne` (`:122`), `deleteAll` (`:136`), `send`/`setMode`/`setModel`/`setAntigravityModel`/`escape`/`answerPrompt`/`answerMultiQuestion` (`SessionController.php:299,315,333,353,368,459,488`). The one non-JSON mutation, `handleAction` (`DashboardController.php:79`), is registered POST-only and inlines same-origin + CSRF (`:83-90`). `session.php`/`archived_session.php` accept GET+POST (`routes.php:34-35,46-47`) but their `show()`/`showArchived()` only render — no side effect, no CSRF exposure.
- **Two-layer CSRF is sound.** `same_origin_or_no_origin()` plus a session-bound token via `hash_equals` (`AuthService.php:23-38,102-112`); token from `random_bytes(32)`; origin layer correctly rejects `Origin: null`, cross-site, and same-IP different-port cases. The token is delivered in-page, never in a URL.
- **Socket-path traversal is not a concern.** `AgentClient::agent_socket_path()` (`AgentClient.php:18-22`) reads only an operator-configured env var (default `/run/csm-agent.sock`); it is never user input. The `unix://` connection fails cleanly to `['ok' => false, …]` when the socket is absent.
- **Protocol framing is correct.** One `fwrite`, `stream_socket_shutdown(SHUT_WR)`, read-to-EOF, `fclose` — matches the socket-activated per-connection agent model (no keep-alive/length-prefix needed).
- **Cache-busting and read-only cache policy are right.** `Assets::versioned_url()` uses `filemtime` → new URL per change, and `start_readonly_json()` pins `no-store` (verified by `test_ui_smoke.php:276`), overriding the HTML page's `private, max-age=60`.
- **ES5 discipline is intact in `common.js`.** `var`/`function` only, no `const`/`let`/arrow/template literals; the shared `copyTextToClipboard()` (`common.js:520-545`) correctly falls back to `execCommand('copy')` because the app is reachable over plain HTTP on the LAN where `navigator.clipboard` is undefined. The block-scoped `window.openFullscreenTextModal` re-export (`common.js:832`) fails `tsc` (F2) but is *correct at runtime* via ES5 non-strict hoisting — the documented intent is sound.
- **Extensive happy + sad test coverage.** `test_ui_smoke.php` exercises the shell's static assets, cache-busting, layout-shell markers, all the `405`s, CSRF `403`s, the no-store contract, and common.js's shipped helpers; `test_agent_client_protocol.php` covers connect-failure and malformed responses; `test_session_replay*.php` drive the real front controller and client-side JS. The happy-path and sad-path convention is well met here — the one real gap is F1's wedged-agent (half-open socket) case.

## Out of scope

- **Network binding / deployment config** — how hard the app is pinned to the LAN (`BIND_ADDR`/`APP_PORT` in `docker-compose.yml`, README). Access control is the binding, not code in `src/`; the `cookie_secure`/TLS question is a deployment decision, not a web-shell change.
- **The host-agent runtime** (`host-agent/agent.php`, `Sessions.php`, `Services/*`, `Stores/*`) — a separate process on the other side of the socket; its own timeouts/behavior are `session-core`/`agent-abstraction` territory.
- **Per-feature controllers' validation logic** and the per-feature JS (`session.js`, `index.js`, `sidebar.js`, `search.js`, `scroll.js`, `highlights.js`, `quota-footer.js`, `push-notify.js`, `markdown.js`, `archived-session.js`) — owned by the feature subsystems; only their *interaction* with the shell's shared contracts (d.ts, `common.js`, `Assets`) is in scope.
- **Per-feature `App\Views\*` classes and partials** under `src/partials/{transcript,blocked-prompt,session-row,pages,quota-footer,push-notify,health-box,sidebar,compose-bar}` (except `layout.php`/`header.php`).

## Cross-cutting observations (described, not solved)

- **`tests/test_agent_client_protocol.php` exercises a web-shell-owned class but is not in web-shell's `owned_paths`/Tests list.** It directly tests `App\AgentClient` (`test_agent_client_protocol.php:28,94`) but DETAILS.md's web-shell test inventory lists only `test_ui_smoke.php`, `test_session_replay.php`, `test_session_replay_browser.php`. This is the natural home for the F1 read-timeout sad-path test. Recommend the coordinator attribute it (likely to `web-shell`, or to `agent-abstraction` if that subsystem claims protocol testing) and add it to `owned_paths`. Other subsystems affected: `agent-abstraction`.
- **The typecheck red state (F2) is cross-subsystem.** The `e.target.closest()` / `.value` / `.disabled` TS2339 family spans `common.js` (web-shell) and the JS files of `session-view`, `archived-sessions`, `search`, `push-notifications`, `quota` (dominated by `session.js` ×~60, `index.js` ×~34). Fully greening `npm run typecheck` requires touching those subsystems' files; keep the d.ts additions in scope here and track the rest as a dedicated cleanup.
- **`PageView::AGENT_OPTIONS` (`PageView.php:27`) is a hand-mirrored copy of `HostAgent\Agents\AgentRegistry`'s adapter list.** It currently includes `opencode` (synced with the 44e4caa commit that added OpenCode), but the docblock itself notes the sync is by hand — a future adapter added on the host side silently missing here is the obvious failure mode. Solved where? `agent-abstraction` owns `AgentRegistry`; web-shell owns the mirror. Not solved in this audit; worth a shared-contract or test-lock recommendation between the two.
- **`DETAILS.md` completeness** (not code-skew): it presents `npm run typecheck` and the d.ts as working, whereas they are presently red (F2), and it omits `test_agent_client_protocol.php`. Recommend a scout re-run per the "re-validate before trusting" rule before relying on this audit as the last word on the shell's type contract.
