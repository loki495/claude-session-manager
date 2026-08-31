# PLAN.md — Session-page/sidebar UI parity + working-status desync bug

## Objective

Three items from Andres, 2026-08-30 (see STATE.md for full context):
1. Sidebar blocked-reason bg tint parity with the dashboard.
2. Session-page footer/context parity (blocked pending clarification).
3. Fix the "Stop button doesn't clear working/thinking status" bug.

---

## Task 1 — Sidebar: blocked-reason bg tint parity with the dashboard

**Objective:** The dashboard's blocked-prompt sub-panel
(`src/partials/blocked-prompt/panel.php` / `rich.php`) renders with an
amber tint (`bg-amber-900/40 text-amber-200 border border-amber-700/60`).
This session's earlier sidebar-parity work
(`public/js/sidebar.js:sidebarRowHtml()`) added the dashboard's per-agent
card background tint, status dot/text coloring, and pills (agent badge,
headless, attached, ctx%, git worktree) — but the sidebar's own
`blocked_reason` preview (the `subHtml` variable, currently plain
`text-slate-500` text) never got the matching amber tint treatment the
dashboard gives its own blocked-prompt preview. This is what "apply the bg
tint as well" refers to.

**Relevant files:**
- `public/js/sidebar.js` — `sidebarRowHtml()`, the `subHtml` block.
- `src/partials/blocked-prompt/panel.php` and `rich.php` — the exact
  classes to mirror (`bg-amber-900/40 text-amber-200 border
  border-amber-700/60 rounded-lg px-3 py-2 text-xs`).

**Dependencies:** none.

**Acceptance criteria:**
- When `s.blocked_reason` is set, the sidebar row's preview line renders
  with the same amber-tinted panel treatment (background, border, text
  color) as the dashboard's blocked-prompt panel — not plain gray text.
- The non-blocked case (last-message preview) is UNCHANGED — this tint is
  specific to the blocked-reason case only, same as the dashboard (the
  dashboard's own last-message preview,
  `BlockedPromptView::last_message_preview_html()`, has no amber tint).
- No regression to the existing agent-card background tint, pills, or
  status coloring already shipped.
- `bash tests/run.sh` still passes (this is a pure JS/CSS-class change with
  no PHP test coverage expected to need updating, but confirm nothing else
  broke).

**Implementation notes:** Don't touch the PHP partials — this is JS-only
(the sidebar renders client-side from `/sessions_list.php` JSON, unlike
the dashboard's server-rendered rows). Just change the `subHtml`
construction in `sidebarRowHtml()` to use the amber classes when
`s.blocked_reason` is truthy, keeping the existing plain style for the
last-message-preview fallback case.

**Status:** done

---

## Task 2 — Session-page footer: show the full multi-agent quota table, plus an optional per-session context line

**Andres's clarification (2026-08-30):** "I meant that for session pages i
want the same footer as the dashboard, as in the while [sic] table with
all the agents quotas. The template can probably be reused, just needs an
optional 'context' line that the dashboard can ignore." So the ask is: the
FULL multi-agent table (`renderDashboardTable()` in `quota-footer.js`),
not the current single-agent-scoped view session.php gets today — plus
one extra line for the current session's own context-window %.

**Current behavior (confirmed by reading the code):**
- `QuotaService::get_quota(?string $sessionName)`
  (`host-agent/lib/Services/QuotaService.php:352`) takes a COMPLETELY
  different branch when `$sessionName` is non-empty: it returns only
  ONE agent's quota (`quota`/`agent`/`agent_label` fields), never the
  `agents` map the dashboard branch builds. For a Claude Code session it
  also overlays a `context` bucket INSIDE that single `quota` object
  (`$contextBucket = ['context' => ['pct' => $contextPct]]`).
- `public/js/quota-footer.js`'s `render()` (line ~329) gates on
  `sessionName === '' && data.agents` to decide whether to call
  `renderDashboardTable(data)` — so even though the backend COULD return
  `agents`, the frontend never asks for/uses it once a session name is
  set, and always falls into the single-agent scoped rendering path
  instead (~85 lines: the generic bucket-loop renderer plus a
  OpenCode-specific cost/tokens branch).
- Per-session precision that would be LOST by naively switching to the
  dashboard's account-wide `agents` map: for a Codex session,
  `codex_quota_state($sessionName)` (per-session, THREAD-scoped) currently
  gets used instead of the dashboard's `codex_quota_state()` (account-wide,
  no thread). For an OpenCode session, `opencode_quota_state($opencodeSessionId)`
  (that session's own cost/tokens) currently gets used instead of the
  dashboard's `opencode_quota_state()` (aggregate). **Decision (orchestrator,
  not the worker's to make):** accept this as a known, documented trade-off
  for now — ship the literal ask (whole account-wide table, every page,
  identical) rather than adding complexity to preserve session-specific
  precision inside individual table rows. Note it in RESULT.md as a
  deferred item for Andres to weigh in on later; do not attempt to
  preserve it as part of this task.

**Relevant files:**
- `host-agent/lib/Services/QuotaService.php` — `get_quota()`.
- `public/js/quota-footer.js` — `render()`'s gate, and
  `renderDashboardTable()`'s end (where `capturedAt`/`el.innerHTML` get
  set, ~line 320-327) for where to append the optional context line.
- `tests/test_quota.php` — has existing `get_quota('test-claude-sess')`/
  `get_quota('test-agy-sess')`/`get_quota()` (dashboard) coverage to
  extend, not replace (see its existing assertions around line 180-205).
- `tests/test_ui_smoke.php` — grep `quota` to check if session.php's quota
  footer markup is asserted anywhere already; update if so.

**Backend change (`QuotaService::get_quota()`):**
Restructure so the dashboard's `$agents` map (currently only built in the
`$sessionName === null` branch, lines ~478-520 today) is ALWAYS computed
and always included in the return under the `agents` key — regardless of
whether `$sessionName` was given. Keep the EXISTING per-session-scoped
fields (`quota`, `agent`, `agent_label`) exactly as they are today,
additively, when `$sessionName` is given — some other future caller (or
this task's own `context` addition) may still want "just this session's
own agent" without re-deriving it from `agents`. This keeps every existing
`test_quota.php` assertion passing unchanged (purely additive).

Add a new top-level `context` field (shape `{pct: int}`, matching the
existing bucket shape so the frontend can reuse `renderBucketText`/
`pctColorClass`) — present only when `$sessionName` is given AND
`live_context_pct($sessionName)` returns non-null (i.e. only ever for a
live Claude Code session with a readable statusline marker; naturally
absent for every other agent and for the dashboard's own `$sessionName ===
null` call, which is exactly the "dashboard can ignore this" Andres asked
for — just omit the key rather than sending `null`).

**Frontend change (`quota-footer.js`):**
- `render()`: drop the `sessionName === ''` half of the gate — call
  `renderDashboardTable(data)` whenever `data.agents` is present,
  regardless of page. Since the backend change above makes `agents`
  unconditionally present on every real response now (dashboard AND
  session pages), the old single-agent-scoped rendering code path (the
  bucket-loop renderer + the OpenCode cost/tokens branch, roughly lines
  340-425) becomes genuinely unreachable from either caller — delete it,
  per this codebase's "don't leave unused code around" convention (see
  CLAUDE.md). Leave `showUnavailable()`/the `!data.agents` guard at the
  top of `render()` alone (still needed for a hard fetch failure).
- `renderDashboardTable(data)`: after the existing table-building code,
  append one extra line (reuse the existing single-line style already used
  elsewhere in this file — a `pctColorClass(pct)`-colored `<div>`, same
  visual weight as the per-bucket lines the old scoped renderer used) when
  `data.context` is present: something like `"This session: ctx " +
  data.context.pct + "%"`. Only render it when `data.context` exists —
  the dashboard's own response will never carry the key at all, so no
  extra guard needed there beyond "if present."

**Acceptance criteria:**
- `QuotaController::show()` (unchanged file, just confirm) still passes
  `$_GET['session']` through to `AgentClient`/`get_quota()` unchanged.
- `GET /quota.php` with no `session` param: `agents` present (unchanged
  from today), `context` key absent.
- `GET /quota.php?session=<a live claude session>`: `agents` present
  (NEW — this is the actual fix), plus the existing `quota`/`agent`/
  `agent_label` fields unchanged, plus `context: {pct: N}` present when a
  live context marker is readable for that session.
- `GET /quota.php?session=<a live antigravity/opencode/codex session>`:
  `agents` present, existing per-session `quota`/`agent`/`agent_label`
  unchanged, `context` key absent (never applies to non-Claude agents).
- session.php visually renders the SAME multi-agent table structure the
  dashboard does (same 4 columns: Agent/5hr/Weekly/Monthly, same 4 agent
  rows), with an extra "This session: ctx N%" line appended below the
  table when that session's context is readable.
- index.php's own footer is visually unchanged (still no context line,
  since `$sessionName` is empty there).
- Existing `test_quota.php` assertions for `get_quota('test-claude-sess')`/
  `get_quota('test-agy-sess')`/`get_quota()` all still pass unmodified.
- New `test_quota.php` coverage: `get_quota('test-claude-sess')` (or
  whichever fixture already exists) now also has `['agents']['claude']`
  populated, and (with a context marker fixture present) `['context']['pct']`
  set to the expected value; a non-Claude session-scoped call has `agents`
  present but no `context` key.
- `bash tests/run.sh` passes in full afterward.

**Deferred (record in RESULT.md, do not implement):** Codex/OpenCode
session pages losing their previous session/thread-scoped quota precision
in favor of the account-wide table row — flagged above, intentionally out
of scope for this task.

**Status:** done

---

## Task 3 — Fix: Stop button doesn't clear working/thinking status

**Objective:** `PromptInteractionService::send_escape()`
(`host-agent/lib/Services/PromptInteractionService.php:388`) sends the
real Escape keystroke (correctly interrupts the real Claude Code process —
confirmed by Andres against the real app) but never updates
`SessionStatusStore`. `SessionStatusStore`'s `status`/`working` fields are
ONLY ever refreshed by the `UserPromptSubmit`/`PermissionRequest`/`Stop`
hooks (see `SessionStatusStore`'s own class docblock) — and per the real
Claude Code hooks docs (confirmed live 2026-08-30,
https://code.claude.com/docs/en/hooks), the `Stop` hook fires ONLY on
natural turn completion, never on a user-initiated interrupt. There is no
dedicated interrupt hook. So a session interrupted via this app's own
"Stop" button has no mechanism to ever clear its stale `working: true`
status — it stays "thinking" in CSM forever (or until some unrelated later
hook fires), exactly matching Andres's report. This is the same bug class
already fixed once for `set_mode()` (see that method's own docblock,
2026-08-23 — "changing modes is broken in session pages", fixed by adding
an explicit `SessionStatusStore::update_status()` call after the tmux
mutation, since hooks don't fire for that action either).

**Relevant files:**
- `host-agent/lib/Services/PromptInteractionService.php` — `send_escape()`,
  and `set_mode()` right above it in the same file as the precedent to
  follow (both the code shape and the docblock style explaining the "why").
- `tests/test_sessions_lifecycle.php` — has existing hook-adjacent tests
  for `set_mode()`/`set_model()` asserting the `SessionStatusStore` side
  effect directly (grep `set_mode: also updates SessionStatusStore` for
  the exact precedent pattern to mirror for `send_escape()`).

**Dependencies:** none.

**Acceptance criteria:**
- After a successful `send_escape()` call, `SessionStatusStore::read_status($name)`
  reflects `status: 'idle'` and `blocked: null` (mirroring what `stop.php`
  itself would have set on a natural completion) — do NOT touch `mode` or
  `model` (an interrupt doesn't change either of those).
- A new test in `tests/test_sessions_lifecycle.php` (or wherever
  `send_escape()` is currently tested, if anywhere — check first) proves
  this against a real fixture session: seed `SessionStatusStore` with
  `status: 'working'`, call `send_escape()`, assert the status flips to
  idle. Follow the exact assertion-on-SessionStatusStore-side-effect
  pattern the `set_mode()`/`set_model()` tests already use.
- `bash tests/run.sh` passes in full afterward.
- Do not touch `stop.php`, `user_prompt_submit.php`, or `permission_request.php`
  — this fix belongs entirely in `send_escape()` itself, matching where
  `set_mode()`'s equivalent fix lives.

**Implementation notes:** Worker should first check whether `send_escape()`
already has ANY test coverage today (`grep -n "send_escape" tests/*.php`)
before assuming where to add the new test — don't duplicate an existing
block if one's already there for the ok/rejection cases.

**Status:** done

---

## Task 4 — New scroll button: jump to previous (not-currently-visible) user message

**Andres's ask (2026-08-30, deferred — "after your done, work over"):** a
new button on session.php, positioned between the existing "scroll to top
of page" and "scroll to first new entry" buttons, that scrolls up to the
nearest PREVIOUS user message that isn't currently visible in the
viewport. If there is no earlier user message above the current scroll
position (i.e. already at/above the first loaded one), it should behave
exactly like the existing "load up to last user message" button instead.

**Investigated (2026-08-30, orchestrator).** Existing button stack in
`src/partials/pages/session.php` (lines ~296-339), all `position:fixed`,
`right-5 z-20 w-11 h-11 rounded-full`, stacked bottom-to-top: `#go-to-bottom-btn`
→ `#jump-to-new-btn` (only shown when a "New" divider exists and isn't
visible) → `#go-to-top-btn` (topmost). Stacking math lives in
`public/js/scroll.js`'s `repositionGoToTopBtn()` +
`watchFixedFooterHeight()` callback, and gets nudged whenever
`#jump-to-new-btn`'s own visibility changes (see `highlights.js`'s
`updateJumpToNewVisibility()`, which calls `repositionGoToTopBtn()`
directly — a plain cross-file global call, matching this codebase's
established "own independent `getElementById()` lookups, call other
files' functions directly" convention, not a module system).

`highlights.js`'s `updateJumpToNewVisibility()`/`jumpToNewContent()`
(lines ~182-232) is the exact reusable pattern for both "is this target
currently visible" and "scroll to it": compares
`target.getBoundingClientRect()` against `pageContent.getBoundingClientRect()`
(`dividerRect.bottom > pageContentRect.top && dividerRect.top <
pageContentRect.bottom` = visible), and scrolls via a manual
`pageContent.scrollTo({top: ...})` computed from the rect delta plus a
16px top offset — not `scrollIntoView()` ("found live 2026-08-09: silently
a no-op in at least one real headless-Chrome context").

No entry in the transcript DOM currently carries a queryable "this is a
real user message" marker — `TranscriptView::entry_color_kind()` already
computes exactly that signal server-side (`'user'` colorKind — deliberately
the SAME simplification the visual rose-colored "bubble" rendering already
relies on, see that method's own docblock: an entry renders as a user
bubble precisely when it's colored 'user', so reusing it for "find the
nearest previous user bubble" is exactly the right, already-established
signal, not new logic), but it's currently discarded before reaching
`transcript/entry.php` — `render_transcript_entry()`
(`src/lib/Views/TranscriptView.php:806`) computes `$colorKind` locally but
never passes it into the `self::render('transcript/entry', [...])` call
at line 859.

`#load-until-user-btn` (`public/js/session.js:28`, click handler ~line
1897 calling `loadHistoryPage(true)`) is exactly the "act just like the
load up to last user message button" fallback Andres asked for — and
critically, `session.js` is wrapped in its own top-level IIFE (`(function
() { ... })()`, starting line 2), so `loadHistoryPage`/`untilUserBtn` are
PRIVATE to that closure, not reachable as globals from `scroll.js`. Rather
than restructuring that closure, the clean fallback is simply triggering
the real button's own existing click handler:
`document.getElementById('load-until-user-btn').click()` — zero coupling,
literally "act just like that button" as asked.

**Design decisions (orchestrator, not left to the worker):**
- New button id: `#prev-user-btn`. Lives in `scroll.js` (not `session.js`)
  — it's a peer of `#go-to-top-btn`/`#go-to-bottom-btn`, which `scroll.js`
  already owns, and needs no access to `session.js`'s private closure (see
  above).
- DOM placement: between `#go-to-top-btn` and `#jump-to-new-btn` in
  `session.php`, matching this stack's existing "markup order mirrors
  visual top-to-bottom order" convention — so it becomes the new topmost
  stack member's neighbor, immediately below `#go-to-top-btn`.
- Visibility: **always shown** whenever `#history-list` has at least one
  rendered entry (unconditional, not scroll-position-gated like
  `#go-to-top-btn`/`#go-to-bottom-btn`, and not divider-gated like
  `#jump-to-new-btn`) — deliberately, because hiding it near the top of
  the loaded transcript (the one place `isNearTop()`-style gating would
  naturally suggest) is exactly where the fallback-to-load-more behavior
  is most likely to matter. Flag to Andres once shipped in case he'd
  rather it hide when there's truly nothing before the current position
  AND no more history to load.
- Icon: `&#9650;` (▲) — visually distinct from `#go-to-top-btn`'s `&uarr;`
  glyph at a glance despite both meaning "up". Not amber (jump-to-new-btn's
  color is reserved for "new/unread content" semantics per its own
  comment) — same slate style as go-to-top/go-to-bottom.
- Stacking math: `repositionGoToTopBtn()` in `scroll.js` needs a 4th tier
  — `#prev-user-btn` always adds one more `GO_TO_BOTTOM_BTN_HEIGHT_PX +
  GO_TO_BOTTOM_GAP_PX` to `#go-to-top-btn`'s own stacked offset (on top of
  the existing conditional `#jump-to-new-btn` tier), and needs its own
  `style.bottom` set inside the same `watchFixedFooterHeight()` callback,
  positioned between the `#jump-to-new-btn` tier and the (now one-tier-higher)
  `#go-to-top-btn`.

**Relevant files:**
- `src/lib/Views/TranscriptView.php` — `render_transcript_entry()`: pass
  `'isUserEntry' => $colorKind === 'user'` (or similar) into the
  `transcript/entry` render call.
- `src/partials/transcript/entry.php` — emit `data-role="user"` on the
  wrapper `<div>` when that new flag is true (leave every other role
  un-marked — no other role is needed for this feature).
- `src/partials/pages/session.php` — new `<button id="prev-user-btn">`
  markup, placed between `#go-to-top-btn` and `#jump-to-new-btn`.
- `public/js/scroll.js` — new click handler + visibility/positioning
  logic, `repositionGoToTopBtn()`'s stacking math update.
- `tests/test_ui_smoke.php` — check existing coverage style for the other
  three floating buttons' presence/positioning (grep `go-to-top-btn`) and
  add equivalent coverage for `#prev-user-btn`; also confirm
  `data-role="user"` appears on a real rendered user entry.
- `tests/playwright/*.spec.js` (if any existing spec drives session.php's
  scroll behavior) — a real click-and-verify-scroll-position test may be
  more useful than a DOM-presence-only test here, given this is
  fundamentally a scroll-behavior feature; worker's own judgment on
  whether the existing Playwright suite is the right place, following
  whatever convention `dashboard-and-session.spec.js` already establishes.

**Dependencies:** none (independent of Tasks 1-3, 5, 6).

**Acceptance criteria:**
- A real user-typed message entry in the rendered transcript carries
  `data-role="user"` on its wrapper div; no other entry kind does.
- `#prev-user-btn` renders in `session.php`, positioned in the DOM between
  `#go-to-top-btn` and `#jump-to-new-btn`, always visible (not `hidden`)
  whenever `#history-list` has any rendered content.
- Clicking it: if at least one `[data-role="user"]` element exists inside
  `#history-list` whose `getBoundingClientRect()` is currently entirely
  above `#page-content`'s own visible area (not just "not the very first
  one" — genuinely scrolled out of view above), smooth-scrolls to the
  NEAREST such one (largest `rect.top` among the out-of-view candidates),
  using the same `pageContent.scrollTo({top, behavior:'smooth'})` +
  16px-top-offset pattern `jumpToNewContent()` already uses.
- Clicking it when NO such element exists (either there's no earlier user
  message at all in what's currently loaded, or the nearest one is
  already visible) triggers `document.getElementById('load-until-user-btn').click()`
  — i.e. genuinely defers to that button's real, existing behavior, not a
  reimplementation of it.
- `#go-to-top-btn` visually sits one tier higher whenever `#jump-to-new-btn`
  is also shown, same relative stacking as today, just with
  `#prev-user-btn` now permanently occupying the tier between
  `#jump-to-new-btn` and `#go-to-top-btn`.
- No regression to `#go-to-bottom-btn`/`#jump-to-new-btn`/`#go-to-top-btn`'s
  own existing show/hide/positioning behavior.
- `bash tests/run.sh` passes in full afterward.

**Status:** done

---

## Task 5 — Bug: iOS app-switch back to a session page scrolls to top + footer renders too tall

**Andres's ask (2026-08-30, deferred — same as Task 4):** when switching
away from and back to CSM on iOS (Safari/PWA) while a session.php page is
open, on return: (a) the page scrolls all the way to the top of the
loaded messages (instead of staying where it was), and (b) the sticky
footer renders too tall. Andres suggested using Playwright if needed to
reproduce/verify.

**Investigated so far (2026-08-30, orchestrator, static code reading
only — NOT yet reproduced live).** Task 2 has landed and been reviewed,
so this is unblocked. What's already confirmed in the code, as hypothesis
material for whoever picks this up — none of it is a confirmed root cause
yet, this needs live reproduction (Andres's own suggestion: Playwright):

- The app already has real, working awareness that iOS treats app-
  switching as a `pagehide`/`pageshow` bfcache cycle, not just a
  `visibilitychange` — see `common.js`'s navigation-blanket handler
  (~line 980): "pagehide ALSO fires when the page is merely being tucked
  into the bfcache (backgrounding the browser/switching apps can trigger
  this on iOS, not just a real navigation) - persisted=true on the
  matching pageshow is what tells the two apart." session.js's OWN
  handling of resume is narrower, though: its `visibilitychange` listener
  (~line 2868) only starts/stops polling (`startPolling()`/`stopPolling()`)
  — it does NOT distinguish a true bfcache restore (`pageshow` with
  `e.persisted === true`, DOM/scroll state preserved by the browser) from
  a full document reload (iOS Safari discarding the backgrounded tab under
  memory pressure and reloading it fresh — a well-known real iOS Safari
  behavior, not exclusive to this app). **Hypothesis A:** if iOS actually
  did a full reload rather than a bfcache restore, session.js's whole
  top-level IIFE re-runs from scratch, including its own unconditional
  `scrollToBottom(false)` call (~line 2930, "land at the bottom on open")
  — which should scroll to the BOTTOM, not top, so a naive reload alone
  doesn't explain "scrolls to top" by itself. Needs live checking whether
  `pageContent.scrollHeight`/`clientHeight` are reliable at the exact
  moment that call runs on a cold reload (a race against layout/footer
  height settling could make `scrollTo({top: scrollHeight})` target a
  wrong, too-small value).
- `watchFixedFooterHeight()` (`common.js` ~line 936) is the EXISTING
  mechanism specifically built to keep `#page-content`'s clearance and the
  floating buttons correctly positioned as `#compose-bar`'s real height
  changes — its own comment already anticipated "the quota box... shows a
  variable number of lines depending on live data" as exactly the
  scenario Task 2 just made bigger (1 agent's few lines → 4 agents' rows +
  a context line). It's ResizeObserver-driven, and `#quota-info` (the
  panel actually holding the table) already has `max-h-40 overflow-y-auto`
  (`quota-footer/footer.php`) — so in theory it should already be capped
  and scrollable internally, not free to grow the footer unboundedly.
  **Hypothesis B:** something about ResizeObserver firing (or not) during
  an iOS resume/reload specifically breaks this — e.g. the observer
  callback not firing before the user sees the page, or the quota panel
  being in an unexpectedly-EXPANDED state on resume (it's collapsed by
  default — `quota-footer.js`'s `applyCollapsed(storedCollapsed)`, gated
  by a `localStorage` flag — worth checking whether that state is being
  read correctly on whatever code path a resume actually takes).
- Both symptoms (scroll position, footer height) could plausibly share
  ONE root cause tied to page-lifecycle/layout-timing on resume, or could
  be two unrelated bugs that just happen to both surface "on resume" —
  don't assume they're the same bug going in.

**How to proceed:** this needs REAL reproduction, not more static reading
— per Andres's own instruction, use Playwright. Launch the app first (the
`run` skill, or manually: `docker compose up -d` then the app is at
`http://127.0.0.1:8091` per `docker-compose.yml`, or check for an
already-running dev instance first rather than assuming none exists).
Use Playwright's mobile device emulation (iPhone viewport + the actual
`webkit` browser engine, not just a resized chromium — Safari-specific
bfcache/layout behavior won't reproduce faithfully under Chromium) against
a real session with enough transcript history to have a meaningful
mid-scroll position to preserve. Simulate the resume by whatever Playwright
actually offers for this (check `page.evaluate()` dispatching a real
`pageshow`/`visibilitychange` event vs whether Playwright/CDP has a more
faithful backgrounding simulation — don't assume `visibilitychange` alone
is sufficient without checking, per Hypothesis A above suggesting a full
reload may be what's actually happening on real hardware). If Playwright
genuinely cannot reproduce iOS Safari's specific bfcache/reload behavior
faithfully (a real, known limitation of automated testing for this exact
class of mobile-browser-lifecycle bug), say so explicitly rather than
declaring false confidence in a fix that was never actually verified
against the real symptom — check what CAN be verified (e.g., the
ResizeObserver/footer-height mechanics in isolation) and report the gap
honestly.

**Relevant files (likely, not confirmed):** `public/js/session.js`
(`visibilitychange` handler ~2868, `scrollToBottom(false)` call ~2930),
`public/js/common.js` (`watchFixedFooterHeight()` ~936, the
`pagehide`/`pageshow` blanket handler ~980), `public/js/quota-footer.js`
(`applyCollapsed()`), `src/partials/quota-footer/footer.php`.

**Dependencies:** Task 2 (done — this was the reason Task 5 was
sequenced after it).

**Acceptance criteria:**
- Reproduced (or explicitly, honestly NOT reproduced, with the specific
  limitation stated) via Playwright against a real running instance,
  mobile/webkit emulation, with enough transcript history to have a
  meaningful scroll position.
- Root cause identified with actual evidence (a failing assertion, a
  captured DOM/layout state, console output) — not a plausible-sounding
  theory alone.
- A fix implemented and re-verified via the same Playwright reproduction
  now passing.
- `bash tests/run.sh` passes in full afterward; if a new Playwright spec
  was added, it runs cleanly as part of the suite (check how existing
  specs under `tests/playwright/` get wired into `tests/run.sh`, if at
  all, before assuming a new one needs manual wiring).

**Worker model note (orchestrator decision):** this is NOT the cheapest-
tier mechanical fix Tasks 1/3/4 were — it requires live diagnostic
reasoning (reproduce, form and test hypotheses, adapt) against a genuinely
uncertain root cause, the explicit "bump up" case Model Tiering calls out
("diagnosing a subtle bug... requires deep reasoning"). Launch at Sonnet,
not Haiku.

**Status:** done - see RESULT.md for the full write-up (real webkit/iPhone
reproduction via Docker's official Playwright image, since this host's own
Playwright webkit binary can't run natively on Arch/CachyOS; two
compounding root causes found and fixed with live before/after
measurements: a stale compiled `public/css/tailwind.css` missing
`.max-h-40` entirely, and a genuine layout race between the initial
scrollToBottom(false) call and quota-footer.js's async fetch growing
#compose-bar afterward).

---

## Task 6 — Sidebar: reuse dashboard's rich rendering for blocked-prompt/last-message instead of the JS reimplementation

**Andres's ask (2026-08-30, deferred):** "maybe for the sidebar sessions,
use the same template as the dashboard so we get the prompts and last
messages as well for free" — i.e. stop hand-maintaining a parallel JS
reimplementation of blocked-prompt/last-message rendering in
`sidebar.js` (which Task 1 just had to patch AGAIN to catch up with a
dashboard styling change), and instead reuse the dashboard's actual
rendering directly.

**Orchestrator's scoping note (given to Andres in-conversation, not yet
confirmed):** the dashboard's full row template
(`src/partials/session-row/row.php`) also carries a "Kill" button and an
expandable "show last 3 messages" widget that don't obviously belong in
the narrow (w-72) sidebar panel, and switching the sidebar from its
current client-side JSON rendering (`sidebarRowHtml()` in JS, fetches
`/sessions_list.php` as JSON and templates it in the browser) to
server-rendered HTML changes how the sidebar polls entirely (would need a
new HTML-fragment endpoint, mirroring how `sessions_fragment.php` already
serves the dashboard's own poll — see `DashboardController::fragment()`).
Recommended narrower alternative: reuse `BlockedPromptView`'s rich
blocked-prompt rendering (option buttons, free-text reveal — the actual
"prompts... for free" part of the ask) specifically, not the whole
dashboard row wholesale. Needs Andres's confirmation on exact scope before
a worker-ready spec can be written.

**Confirmed by Andres (2026-08-30): the full switch.** Sidebar moves from
client-side JSON templating to a server-rendered HTML fragment, same
architectural shape as the dashboard's own poll (`DashboardController::
fragment()` → `SessionRowView::sessions_list_html()`). Andres's own words:
"Yes, do the full switch" — gets the rich blocked-prompt UI (option
buttons, free-text reveal) and last-message rendering for free,
permanently, no more drift; the "done" badge logic gets adapted to work
against the rendered markup instead of raw JSON fields.

**Investigated (2026-08-30, orchestrator).**
`BlockedPromptView::blocked_prompt_rich_html($session, $csrfToken,
$includeLastMessage)` (the exact method the dashboard's own blocked rows
already call) needs precisely the same `$session` array shape
`/sessions_list.php`'s JSON already carries today (`blocked_reason`,
`last_message`, `prompt_questions`, `prompt_context`, `prompt_options`,
`name`, ...) — no new backend data needed, purely a rendering-location
change (JS → PHP).

**Design decisions (orchestrator):**
- New PHP view method: `SessionRowView::sidebar_row_html(array $s, string
  $csrfToken): string` (new partial `session-row/sidebar-row.php`) — a
  COMPACT variant of `session_row_html()`/`row.php`, not the full
  dashboard row: keep the agent-colored card background, the agent/
  headless pills, the status dot+text+attached/ctx%/worktree meta line
  (all already in `row.php`, extract the small `$agentCardStyle`/
  `$agentBadgeClass` `match` blocks into shared static helpers on
  `SessionRowView` — e.g. `agent_card_style(string $agentId): string` /
  `agent_badge_class(string $agentId): string` — so `row.php` and the new
  `sidebar-row.php` both call the same one, not two copies of the same
  4-line map) — but DROP the "Kill" button and the "show last 3 messages"
  expandable widget, neither of which belongs in the w-72 sidebar panel.
  For the blocked case, call `BlockedPromptView::blocked_prompt_rich_html($s,
  $csrfToken, true)` (same call the dashboard makes); for the non-blocked
  case, `BlockedPromptView::last_message_preview_html($s['last_message']
  ?? null, 'mt-1')`. Reuse `row.php`'s exact "stretched-link with
  `position:relative` opt-out for interactive children" pattern (its own
  comment explains why) so the rich blocked-prompt's real answer
  buttons/forms stay clickable without also triggering "open this
  session" navigation.
- New endpoint: add `DashboardController::sidebarFragment()` (new route,
  e.g. `/sidebar_sessions.php`) that calls the SAME `action: 'list'`
  agent call `list()` already does, but returns BOTH the raw `sessions`
  array (unchanged shape) AND a new `sessions_html` key rendered via a new
  `SessionRowView::sidebar_sessions_list_html($sessions, $sessionName,
  $csrfToken)` (filters out `$sessionName` itself, same as sidebar.js's
  own `others = sessions.filter(...)` today, so the PHP side does that
  filtering now instead of JS). Do NOT modify or remove `/sessions_list.php`
  — it may still have other callers, check before touching it (grep first).
  Read-only, no CSRF/same-origin check needed for the GET itself (matches
  `list()`'s own reasoning), but the CSRF token it EMBEDS in the rendered
  forms still needs `AuthService::csrf_token()`, same as `fragment()`.
- `sidebar.js` changes: `loadSidebarList()`/`refreshSidebarNotification()`
  now fetch the new endpoint instead of `/sessions_list.php`. The existing
  notify-dot/done-state bookkeeping (`processOtherSessions()`,
  `updateSessionDoneState()`, `readSidebarSessionState()`, etc.) all
  operate on the raw `data.sessions` array already — KEEP that logic
  completely unchanged, just swap `sidebarList.innerHTML =
  others.map(sidebarRowHtml).join('')` for `sidebarList.innerHTML =
  data.sessions_html` (server-rendered, already filtered). Delete
  `sidebarRowHtml()`/`sidebarStatusMeta()`/the `SIDEBAR_AGENT_CARD_STYLE`/
  `SIDEBAR_AGENT_BADGE_CLASS` JS maps entirely — this is exactly the
  now-dead reimplementation Task 6 exists to retire, don't leave it
  unused (same "no unused code" convention as Task 2's frontend cleanup).
- **"Done" badge overlay** (the one piece of state that's genuinely
  client-side-only and can't move server-side — the server has no way to
  know what THIS browser has already seen): the new `sidebar-row.php`
  partial must emit a `data-session="<name>"` on the row's own wrapper
  `<a>` AND wrap the status dot+text specifically in something targetable
  (e.g. `<span data-session-status>...</span>`) so `sidebar.js` can, after
  inserting `sessions_html`, do a small DOM pass: for each session
  `doneState` marks done, `querySelector('[data-session="X"]
  [data-session-status]')` and swap its dot/text classes+label to the
  emerald "done" treatment — same visual result as before, now a
  post-render overlay instead of a template-time branch.

**Relevant files:**
- `src/lib/Views/SessionRowView.php` — new `sidebar_row_html()`,
  `sidebar_sessions_list_html()`, `agent_card_style()`/`agent_badge_class()`
  helpers (extracted from `row.php`'s inline `match` blocks — update
  `row.php` to call them too, don't leave two copies of the mapping).
- `src/partials/session-row/row.php` — reuse the extracted helpers; no
  other change.
- `src/partials/session-row/sidebar-row.php` — NEW, the compact variant.
- `src/lib/Controllers/DashboardController.php` — new `sidebarFragment()`
  method.
- `src/routes.php` — new route registration.
- `public/js/sidebar.js` — swap the fetch target + row-rendering line,
  delete the now-dead JS rendering functions/maps, add the done-badge
  DOM-overlay pass.
- `tests/test_ui_smoke.php` / wherever sidebar rendering is currently
  covered — update for the new server-rendered shape; add coverage for
  the new endpoint and the rich blocked-prompt actually appearing in the
  sidebar's own rendered HTML (not just the dashboard's).

**Dependencies:** Task 1 (done — this task retires Task 1's own JS work,
so it needed to exist first; the amber-tint work Task 1 did in JS becomes
moot once this ships, since the real PHP partial being reused already has
its own correct amber treatment).

**Acceptance criteria:**
- A blocked session's sidebar card shows the SAME rich treatment
  (`BlockedPromptView::blocked_prompt_rich_html`'s real option buttons/
  free-text reveal) the dashboard shows — not a static text preview.
- Answering a prompt directly from the sidebar's own rendered form works
  end-to-end (submits, real state change) — this is new functionality the
  JS-only version never had at all, not just a visual change.
- No "Kill" button or "show last 3 messages" widget appears in the
  sidebar's rendering.
- The existing agent-card background tint, pills, status coloring,
  notify-dot, and "done" badge behavior all still work exactly as before
  (visually unchanged for the non-blocked case), now sourced from
  server-rendered HTML plus the done-badge DOM overlay instead of pure JS
  templating.
- `sidebarRowHtml()` and its supporting JS (the per-agent style maps, the
  status-meta function) are deleted, not left unused.
- `/sessions_list.php` itself is untouched (still serves whatever else
  currently depends on it) unless a `grep` first confirms it has no other
  callers — check before assuming.
- `bash tests/run.sh` passes in full afterward.

**Status:** done, but see Task 7 — orchestrator review found a real gap
in the "answering a prompt from the sidebar works end-to-end" acceptance
criterion (the worker's own testing only confirmed the forms RENDER
correctly with CSRF tokens, not that submitting one actually does
anything on session.php). The core rendering/architecture work IS
correct and stays done; the follow-up below closes the remaining gap.

---

## Task 7 — Task 6 follow-up: wire up sidebar prompt-answer submission (found on orchestrator review)

**What's broken:** Task 6 correctly renders `BlockedPromptView::
blocked_prompt_rich_html()`'s real forms (option buttons, free-text
reveal, multi-question) inside the sidebar now — but NOTHING on
session.php actually handles submitting them. session.js's own blocked-
prompt handlers (`blockedSection.addEventListener('submit'/'click', ...)`,
~line 1608/1764) are scoped to `#blocked-prompt-section` specifically
(session.php's own single-session transcript view) and are full of
THIS-session-specific side effects (`appendPendingEntry()` into
`#history-list`, `renderThinkingIndicator()`, `currentBlockedReason`) that
would be actively WRONG if triggered for a DIFFERENT session answered
from the sidebar — so simply broadening that listener's scope is not the
fix. Right now, a form submitted from inside the sidebar's rendered HTML
has no JS intercepting it at all — depending on the browser, this either
does nothing or triggers a raw, un-intercepted HTTP form POST that
navigates the whole page to a raw JSON response. Confirmed by reading the
code (not yet reproduced live in a browser — a real click-through
verification, not just a code read, is part of this task's own
acceptance criteria below).

**The exact pattern already exists and works** — `public/js/index.js`
(dashboard-only, never loaded on session.php) already solves the EXACT
same shape of problem: multiple sessions in one list, each with its own
independent blocked-prompt form, answered without leaving the page. Port
this pattern into `sidebar.js`, not `session.js`:
- `document.addEventListener('submit', ...)` (index.js ~line 33): plain-
  option form submission via `postAnswerPrompt(new FormData(form),
  'dashboard-answer-prompt')` (rename the 3rd arg's label for the sidebar
  context, e.g. `'sidebar-answer-prompt'`), disabling the row's own
  buttons, swapping its container to a "Sent - updating…" confirmation on
  success.
- `submitFreetextReply()` (index.js ~line 73): the free-text reply path,
  via `postAnswerPrompt({session, csrf_token, option, text}, ...)`.
- `submitMultiQuestionAnswers()` (index.js ~line 117): via
  `collectMultiQuestionAnswers()`/`postAnswerMultiQuestion()` (both
  already shared in `common.js`).
- The `document.addEventListener('change', ...)` (multi-question
  freetext toggle) and the `click` delegation for
  `.multi-question-submit-btn`/`.reveal-freetext-btn`/
  `.freetext-reply-send-btn` (index.js ~line 146-178).
- The `keydown` handler for Shift+Enter submitting a freetext reply
  (index.js ~line 189-194), if the sidebar's own compact layout still
  makes sense for that same interaction (worker's judgment - the sidebar
  panel is narrower than the dashboard, but the same textarea shape
  applies).

**Key difference from index.js's own version:** replace every
`requestSessionsPollNow()` call with `loadSidebarList()` (sidebar.js's
own existing refresh function) — there's no separate "poll" concept in
the sidebar the way the dashboard has its own `sessions_fragment.php`
cycle; reloading the whole list is the sidebar's existing refresh
mechanism and already re-fetches+re-renders everything correctly.

**Relevant files:**
- `public/js/sidebar.js` — add the ported handlers.
- `public/js/index.js` — READ ONLY, the source pattern to port from; do
  not modify.
- `public/js/common.js` — read `postAnswerPrompt()`/
  `postAnswerMultiQuestion()`/`collectMultiQuestionAnswers()`/
  `handleMultiQuestionFreetextToggle()`/`closestEventTarget()` (already
  shared, no changes needed there, just confirm the exact signatures).

**Dependencies:** Task 6 (done — this is a direct follow-up, same feature).

**Acceptance criteria:**
- From session.php's sidebar, with a DIFFERENT session in a blocked
  state, clicking one of its rendered option buttons actually sends the
  answer (confirm via a real functional check — spin up the app, or a
  Playwright/headless-browser test hitting the real `/answer_prompt.php`
  endpoint from within the sidebar's own rendered form — not just a code
  read) and the row updates to reflect it.
- Free-text reply from the sidebar works the same way.
- A multi-question `AskUserQuestion` prompt answered from the sidebar
  works the same way.
- None of this touches or duplicates `session.js`'s own
  `#blocked-prompt-section` handling — that stays exactly as it is,
  answering THIS page's own session.
- `bash tests/run.sh` passes in full afterward.

**Status:** done
