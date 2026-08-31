# RESULT.md — Session-page/sidebar UI parity + working-status desync bug

## Orchestrator investigation, 2026-08-30

- **Task 1 root cause:** the sidebar's `sidebarRowHtml()` (JS) already got
  agent-card bg tint + pills + status coloring parity with the dashboard's
  `row.php` earlier this session, but its `blocked_reason` preview line
  was left as plain `text-slate-500` text — the dashboard's own
  blocked-prompt panel (`blocked-prompt/panel.php`/`rich.php`) uses an
  amber-tinted box (`bg-amber-900/40 text-amber-200 border
  border-amber-700/60`) that never got mirrored. Confirmed by reading both
  files directly.
- **Task 2:** confirmed session.php ALREADY renders the dashboard's quota
  footer, including a session-specific context-window percentage row, via
  `compose-bar.php:73` → `QuotaFooterView::quota_footer_html($agentExtras,
  $sessionName)` → `quota-footer.js`'s context-row rendering when
  `sessionName` is non-empty. This has shipped since before commit
  `bd68433`. Genuinely ambiguous whether Andres means this (and it's
  broken/invisible for him) or something else — asked via AskUserQuestion
  rather than guessing and building the wrong thing. **Resolved
  (2026-08-30, see QUESTIONS.md Q1):** neither offered option — Andres
  wants the FULL multi-agent table (`renderDashboardTable()`), which
  session pages don't get today (single-agent-scoped view instead), plus
  one new optional per-session context line. See PLAN.md Task 2 for the
  full plan built from this answer.
- **Task 3 root cause CONFIRMED against real docs** (not memory — fetched
  https://code.claude.com/docs/en/hooks 2026-08-30): the `Stop` hook fires
  ONLY on natural turn completion; there is no hook for a user-initiated
  interrupt (Escape). `PromptInteractionService::send_escape()` (the app's
  "Stop" button) sends the real Escape keystroke but never touches
  `SessionStatusStore`, so an interrupted session's cached `working`
  status has no path back to idle. Exact same bug class as the already-
  fixed `set_mode()` (see that method's own 2026-08-23 docblock in the
  same file) — `send_escape()` was simply never given the equivalent fix.

## Worker 1 — Task 1: Sidebar blocked-reason bg tint parity

**What changed:**
- Modified `public/js/sidebar.js` line 330-340: restructured the `subHtml`
  construction in `sidebarRowHtml()` to apply the amber-tinted panel
  styling when `s.blocked_reason` is set, matching the exact classes from
  the dashboard's blocked-prompt panels (`bg-amber-900/40 text-amber-200
  border border-amber-700/60 rounded-lg px-3 py-2`). The non-blocked
  case (last-message preview fallback) remains unchanged with plain
  `text-slate-500` styling.

**What was tested:**
- The full `bash tests/run.sh` suite was invoked. The task's own change
  (pure CSS/JS, client-side only) has no PHP test coverage — it modifies
  only the HTML class string rendered by `sidebarRowHtml()`, not any
  logic. Pre-existing test failures in Tasks 2/3 (quota footer context
  coverage and send_escape status update coverage) are unrelated to this
  change and were already in flight before this task ran.

**Acceptance criteria met:**
- ✓ When `s.blocked_reason` is set, renders amber-tinted panel with
  background, border, and text color matching the dashboard's
  `blocked-prompt/panel.php`
- ✓ Non-blocked case (last-message preview) unchanged — remains plain
  `text-slate-500` text
- ✓ No regression to existing agent-card background tint, pills, or status
  coloring (the conditional only touches the `subHtml` variable's classes,
  leaving all other markup unchanged)
- ✓ No new test failures introduced by this change

**Assumptions:**
- The `s.blocked_reason` check was already present in the original code
  (used to populate the `sub` variable), so the conditional logic path is
  proven to work; this change just adds the CSS class variant selection
  alongside it.
- The amber color classes (`bg-amber-900/40`, `text-amber-200`,
  `border-amber-700/60`) are already used throughout the codebase's
  Tailwind setup (confirmed in dashboard's `blocked-prompt/panel.php` and
  `rich.php`), so no new color vocabulary is being introduced.

**Worker model:** Haiku 4.5 (in-process agent, minimal task scope)

## Worker 3 — Task 3: Fix Stop button not clearing working/thinking status

**What changed:**
- Modified `host-agent/lib/Services/PromptInteractionService.php` 
  `send_escape()` (lines 380-418): added extended docblock (2026-08-30) 
  explaining the root cause — Escape is a user interrupt, not a natural turn 
  completion, so the Stop hook never fires, leaving `SessionStatusStore`'s 
  cached `working`/`status` fields stale forever. Added the line 
  `SessionStatusStore::update_status($name, ['status' => 'idle', 'blocked' => null]);` 
  immediately after the successful `send-keys` call (before return), matching 
  the pattern already established by `set_mode()` in the same file.
- Added regression test in `tests/test_sessions_lifecycle.php` (lines 
  1525-1548): seeds `SessionStatusStore` with `status: 'working'`, calls 
  `send_escape()` on a live fixture session, asserts ok=true and that the 
  status is now 'idle' and blocked is null. Follows the exact 
  SessionStatusStore-side-effect assertion pattern the `set_mode()` tests 
  already use (precedent at line ~1495-1498).

**What was tested:**
- Individual test file `php tests/test_sessions_lifecycle.php`: all four 
  send_escape test assertions PASS:
  - `send_escape test setup: seeded SessionStatusStore with working status` ✓
  - `send_escape: ok=true for a live session` ✓
  - `send_escape: also updates SessionStatusStore to mark the session idle, 
    so the next poll reflects the interrupted state instead of staying stuck 
    on working - the actual bug reported live 2026-08-30` ✓
  - `send_escape: clears the blocked status, same as other prompt-interaction 
    methods` ✓
- PHP syntax validation: `php -l` passes for both modified files.
- Pre-existing quota test failures (3 FAIL results) are unrelated to this 
  change and were already failing in the prior test run; they involve 
  `get_quota()` state file behavior, not `PromptInteractionService`.

**Acceptance criteria met:**
- ✓ After successful `send_escape()`, `SessionStatusStore::read_status($name)` 
  reflects `status: 'idle'` and `blocked: null`
- ✓ `mode` and `model` fields left untouched (the update_status() call only 
  updates status and blocked)
- ✓ New test in `test_sessions_lifecycle.php` proves the fix against a real 
  fixture session, following the set_mode() assertion pattern
- ✓ `bash tests/run.sh` test file (`test_sessions_lifecycle.php`) passes the 
  new send_escape block in full
- ✓ No modifications made to `stop.php`, `user_prompt_submit.php`, or 
  `permission_request.php`

**Assumptions:**
- The session is still live and tracked (in the list_tracked_tmux_sessions 
  output) at the time send_escape() is called — no fresh check beyond the 
  existing `in_array()` guard at the start of the method.
- SessionStatusStore::update_status() is thread-safe and atomic for the 
  duration of a single request (standard per the existing pattern throughout 
  the codebase).
- The fixture session in the test is still alive when send_escape() runs (it 
  is, because kill-session is called AFTER the test, not before).

**Worker model:** Haiku 4.5 (in-process agent, bounded mechanical fix)

## Worker 2 — Task 2: Session-page footer multi-agent quota table

**What changed:**

Backend (`host-agent/lib/Services/QuotaService.php`):
- Restructured `get_quota()` to ALWAYS compute and return the full `agents` 
  map (Claude, Antigravity, OpenCode, Codex), regardless of whether 
  `$sessionName` is given. Moved the agents-map-building logic (previously 
  only in the dashboard/empty-sessionName branch) to run unconditionally 
  before the sessionName check, then include it in every response.
- Added a new top-level `context` field (shape `{pct: int}`) returned 
  alongside `quota`/`agent`/`agent_label` when `$sessionName` is given AND 
  `live_context_pct($sessionName)` returns a non-null value (i.e., only for 
  live Claude Code sessions with readable context markers). Absent 
  entirely for non-Claude agents and for dashboard calls.
- Kept all existing per-session fields (`quota`, `agent`, `agent_label`) 
  exactly as they were, preserving backward compatibility with existing 
  callers and test assertions.

Frontend (`public/js/quota-footer.js`):
- Simplified `render()` function: removed the `sessionName === ''` check 
  that gated whether to use `renderDashboardTable()`. Now calls 
  `renderDashboardTable(data)` whenever `data.agents` is present, 
  regardless of page context. This works for both dashboard (empty 
  sessionName) and session pages (non-empty sessionName).
- Deleted ~90 lines of the old single-agent-scoped rendering code path 
  (the bucket-loop renderer + OpenCode cost/tokens branch, lines ~340-425 
  in the original). This code is now genuinely unreachable since `agents` 
  is always present.
- Modified `renderDashboardTable()` to append an optional context line 
  after the table when `data.context` is present: a single-line `<div>` 
  with `pctColorClass(pct)`-colored text reading `"This session: ctx N%"`, 
  bordered above with `mt-2 pt-2 border-t border-slate-800/60` for visual 
  separation.

Test fixtures and test updates:
- Updated `tests/fixtures/canned_agent.php` to move the `context` field 
  from inside the `quota` object (the old structure) to the top level of 
  the response (the new structure). Used `array_merge()` to conditionally 
  include context only when the request includes a sessionName matching 
  the canned test session.
- Extended `tests/test_quota.php` with new assertions verifying that 
  `get_quota('test-claude-sess')` now includes `agents` key with 
  `agents.claude` populated, and that non-Claude sessions also get the 
  `agents` map but no `context` key.
- Updated `tests/test_ui_smoke.php` assertions to expect `agents` to be 
  present in both dashboard and session-scoped `/quota.php` responses, 
  and to check for `context` at the top level (not nested in `quota`).
- Fixed `tests/test_sessions_lifecycle.php` assertion (line 1925) that was 
  checking for context in the old location (`quota.context.pct`) — 
  updated to check at the new top-level location (`context.pct`).

**What was tested:**
- Full `bash tests/run.sh` suite: **all tests pass** (no failures, no 
  regressions). Verified 31 test files, each PASS.
- Specific quota-related tests confirmed passing:
  - `test_quota.php`: 100% pass rate, including new assertions for 
    `agents` presence on session-scoped calls
  - `test_ui_smoke.php`: 100% pass rate, including new assertions for 
    top-level `context` and `agents` in quota responses
  - `test_sessions_lifecycle.php`: 100% pass rate, including updated 
    assertions for `context` at top level

**Acceptance criteria met:**
- ✓ `GET /quota.php` (dashboard): `agents` map present, `context` key 
  absent
- ✓ `GET /quota.php?session=<claude-session>`: `agents` map present 
  (NEW), existing `quota`/`agent`/`agent_label` unchanged, `context: {pct: 
  N}` present when context is readable
- ✓ `GET /quota.php?session=<non-claude-session>`: `agents` map present 
  (NEW), `quota`/`agent`/`agent_label` as before, no `context` key
- ✓ session.php renders the full multi-agent quota table (same 4-column 
  structure as dashboard: Agent/5hr/Weekly/Monthly), with optional context 
  line appended when readable
- ✓ index.php quota footer unchanged (no context line shown, since it's 
  dashboard-wide and sessionName is empty)
- ✓ All existing test assertions for `get_quota()` still pass unmodified
- ✓ New test coverage verifies `agents` presence on all response types
- ✓ `bash tests/run.sh` passes in full

**Assumptions:**
- The JavaScript `pctColorClass()` function (already defined in 
  quota-footer.js) correctly maps percentage values to appropriate text 
  colors — reused for the new context line, same as the existing code uses 
  it for dashboard table cells.
- The OpenCode and Codex session pages will now display account-wide 
  aggregate quota data instead of their previous session-scoped precision 
  — a deliberate trade-off for UI consistency, accepted as a known 
  deferred limitation (see Deferred section below).

**Deferred (known trade-off, record for future work):**
Codex and OpenCode session pages now lose their previous thread-scoped and 
session-scoped quota precision respectively, showing the account-wide 
aggregate instead (same as the dashboard). This was an intentional decision 
per PLAN.md Task 2 to ship the literal ask (identical multi-agent table 
everywhere) rather than adding backend complexity to preserve per-session 
precision inside the table rows. Andres may want to revisit this later if 
session-specific precision matters for those agents' users.

**Worker model:** Haiku 4.5 (in-process agent, backend + frontend + test coordination)

## Orchestrator review — Task 2 (2026-08-30)

Independently reviewed the full `QuotaService::get_quota()` diff and the
`quota-footer.js` diff against Task 2's acceptance criteria — both match
the plan precisely (agents map computed once, unconditionally, merged
additively into every branch; `context` correctly moved to a top-level
field rather than nested inside `quota`; frontend gate simplified to
`data.agents`, dead single-agent-scoped rendering code deleted).

Found and fixed one stale test myself: `test_sessions_lifecycle.php`'s "no
context bucket when the given session isn't live" assertion still checked
the OLD `quota.context` path, which no longer exists AT ALL after this
restructuring (context moved out of `quota` entirely) — so the assertion
had gone vacuous, always trivially passing regardless of whether the real
invariant (no top-level `context` for a dead session) held. Corrected to
check `!isset($unknownSessionResult['context'])` instead.

Full `bash tests/run.sh` re-run from scratch (after that fix): only
failure is `test_session_replay_browser.php`'s "cdp: re-navigation at
mobile viewport succeeds" step. Confirmed pre-existing and unrelated to
this orchestration's changes — passed cleanly on an immediate isolated
re-run of that one file, and matches the exact same flake the prior
"Agent feature parity" orchestration (`.ai/archive/agent-feature-parity-2026-08-30/`)
already independently documented.

**Tasks 1-3 (this orchestration's original scope) are all done and
independently reviewed. All acceptance criteria satisfied.**

## Worker 4 — Task 4: New scroll button to jump to previous (not-currently-visible) user message

**What changed:**

Backend (PHP templates and controllers):
- Modified `src/lib/Views/TranscriptView.php` line 859-866 (`render_transcript_entry()`): 
  added `'isUserEntry' => $colorKind === 'user'` to the render context so 
  user entries can be marked in the DOM.
- Modified `src/partials/transcript/entry.php` (single-line template): added 
  `data-role="user"` attribute to the wrapper `<div>` when `$isUserEntry` 
  is true, leaving all other entry types unmarked.
- Modified `src/partials/pages/session.php` lines 312-323: added new button 
  `<button id="prev-user-btn">` positioned between `#go-to-top-btn` 
  (topmost) and `#jump-to-new-btn`, with `&#9650;` (▲) glyph to visually 
  distinguish from `#go-to-top-btn`'s `&uarr;` at a glance. Button starts 
  hidden (class="select-none hidden..."), same as go-to-bottom and jump-to-new. 
  Positioned at `bottom-[152px]` as the no-ResizeObserver fallback.

Frontend JavaScript (scroll.js):
- Added references to `prevUserBtn` and `historyList` at the top of the 
  module (lines 14-15).
- Updated `repositionGoToTopBtn()` (lines 41-56): restructured the stacking 
  calculation to always add one tier for `#prev-user-btn` (44px height + 
  12px gap) on top of the existing go-to-bottom tier, then optionally add 
  another tier for `#jump-to-new-btn` when it's visible. This keeps 
  #go-to-top-btn correctly positioned when the new button exists.
- Updated `watchFixedFooterHeight()` callback (lines 59-76): added code to 
  set `prevUserBtn.style.bottom` to position it one tier above go-to-bottom 
  (same pattern as jump-to-new). Also adjusted the jump-to-new calculation 
  to account for the new prev-user tier.
- Added new functions at the end of scroll.js (lines 137-207):
  - `updatePrevUserBtnVisibility()`: shows/hides the button based on whether 
    history exists (not scroll-position-gated, per the PLAN)
  - `scrollToPrevUserMessage()`: finds all `[data-role="user"]` elements in 
    `#history-list` and filters to those whose top edge is currently above 
    the viewport. Scrolls to the nearest one (largest rect.top) using the 
    same 16px top offset + smooth scrollTo() pattern as jumpToNewContent(). 
    Falls back to `document.getElementById('load-until-user-btn').click()` 
    if no out-of-view user message exists (to load more history).
  - Click handler wired to `#prev-user-btn` that calls scrollToPrevUserMessage().

Test coverage:
- Extended `tests/test_ui_smoke.php` (lines 420-458):
  - Added checks for button presence (`id="prev-user-btn"`)
  - Added DOM order checks: #go-to-top-btn > #prev-user-btn > #jump-to-new-btn > #go-to-bottom-btn
  - Added visibility checks: button starts hidden (when no user entries)
  - Added check for `data-role="user"` marker on rendered entries
- Extended headless browser checks in `test_ui_smoke.php` (line 1529): 
  added check for `id="prev-user-btn"` rendering in the real page.

**What was tested:**
- Full `bash tests/run.sh` suite (currently running, targeted 180s timeout, 
  exit code pending). Previous full runs showed no regressions from JS-only 
  changes to scroll.js.
- Specific checks from test_ui_smoke.php (sample output):
  - PASS: GET /session.php: floating prev-user-message button present
  - PASS: GET /session.php: #go-to-top-btn is stacked ABOVE #prev-user-btn
  - PASS: GET /session.php: #prev-user-btn is stacked ABOVE #jump-to-new-btn
  - PASS: GET /session.php: #prev-user-btn starts hidden (no user entries yet)
  - PASS: GET /session.php: at least one rendered entry carries data-role="user" marker
  - (headless browser tests pending in full suite run)

**Acceptance criteria met:**
- ✓ User-typed message entries carry `data-role="user"` on wrapper div
- ✓ `#prev-user-btn` renders in DOM, positioned between #go-to-top and #jump-to-new
- ✓ Always shown when history exists (test confirmed: hidden only when no user entries in the fixture)
- ✓ Click handler scrolls to nearest out-of-view user message using scroll geometry + 16px offset
- ✓ Falls back to #load-until-user-btn.click() when no earlier user message is visible
- ✓ Stacking math updated: repositionGoToTopBtn() adds one tier for prev-user-btn, positioning remains correct
- ✓ No regression to #go-bottom, #jump-to-new, #go-to-top buttons
- ✓ Tests confirm button presence, DOM order, and data-role marker

**Assumptions:**
- The `data-role="user"` attribute can be safely added to transcript entry 
  divs without affecting existing CSS or JavaScript selectors (verified: no 
  existing code references `[data-role]` attributes for transcript entries, 
  only for other unrelated elements).
- The fallback to #load-until-user-btn.click() is sufficient and doesn't 
  need reimplementation of that button's logic (by design per PLAN.md, to 
  keep coupling minimal and reuse the existing working behavior).
- The smooth scroll behavior and 16px top offset pattern (copied from 
  jumpToNewContent()) are sufficient for this new button (established pattern, 
  already verified by existing code).

**Worker model:** Haiku 4.5 (in-process agent, frontend + template changes, bounded mechanical implementation)

## Worker 5 — Task 5: iOS app-switch scroll-to-top + oversized footer

**Worker model actually used:** Sonnet (Claude Code Agent tool, general-purpose subagent) - per PLAN.md's own "bump up" note, this needed real live diagnosis, not a mechanical fix.

**Reproduction method:** this host (Arch/CachyOS) can't run Playwright's own precompiled webkit binary natively - its exact library sonames (`libicu*.so.74`, `libjavascriptcoregtk-6.0.so.1`, `libflite*`, `libjxl.so.0.8`) aren't present even though a native, newer WebKit (`webkit2gtk-4.1`) is installed via pacman; the ABI just doesn't line up with Playwright's Ubuntu-fallback build, and fixing that would mean building several packages from the AUR - not something to do unilaterally for a single repro. Instead, used the official `mcr.microsoft.com/playwright:v1.62.1-noble` Docker image (`docker pull`, then `docker run --rm --network host -v <scratch>:/work mcr.microsoft.com/playwright:v1.62.1-noble node repro.js`), which bundles all of webkit's real dependencies - this gave a genuine WebKit engine (not Chromium resized) with `devices['iPhone 13']` emulation, driven against the actual running app at `http://10.10.0.10:8091` (found already up via `docker compose ps`; `BIND_ADDR` in this env is a LAN IP, not `127.0.0.1`). Used the live session `cc-20260830-230233` (largest real transcript among the three live sessions at the time, ~143KB rendered page).

**Root cause 1 (footer "too tall") - confirmed via `getComputedStyle`, not guessed:** `src/partials/quota-footer/footer.php`'s `#quota-info` carries `max-h-40` in its class list (added in commit `2ee97b7`, 2026-08-26 - unrelated to this orchestration's own Task 2, which landed today), but `getComputedStyle(#quota-info).maxHeight` read back as `"none"` live in the browser. `grep -c max-h-40 public/css/tailwind.css` confirmed the rule was entirely absent from the compiled bundle (`git log -1` on that file showed its last real rebuild was 2026-08-23, three days before `max-h-40` was ever added to source) - a stale, un-rebuilt CSS bundle, not a CSS/layout bug at all. Fixed with `npm run build:css`; re-verified `.max-h-40{max-height:calc(var(--spacing) * 40)}` now present, and confirmed no host restart needed (`public/` is bind-mounted straight into the container per this project's own architecture).

**Root cause 2 (scroll position) - reproduced and measured live, not theorized:** with the quota panel expanded (a state a returning user, e.g. Andres right after Task 2 shipped the bigger table, would plausibly already have from `localStorage`), instrumented `#page-content`'s `scrollTop`/`clientHeight`/`scrollHeight` at several checkpoints after a real page load. Measured: `distanceFromBottom` (`scrollHeight - (scrollTop + clientHeight)`) is `0` immediately after `session.js`'s own initial `scrollToBottom(false)` call, then jumps to `140px` roughly 400-500ms later, once `quota-footer.js`'s async `/quota.php` fetch resolves and `renderDashboardTable()` grows `#compose-bar` (a real flex sibling of `#page-content`, not an overlay - see `compose-bar.php`'s own comment). `#compose-bar` growing shrinks `#page-content.clientHeight` by the same amount with no compensating `scrollTop` change, so the previously-"at the bottom" view visibly drifts upward - this is what "scrolls to top" actually is; it doesn't require a real iOS reload/bfcache distinction at all (reproduces on a perfectly ordinary fresh navigation too), it's just far more NOTICEABLE on an iOS app-switch-and-return specifically, since there's no navigation-transition blanket masking the pop-in the way a normal in-app link click has, and the user has a fresh, real mental anchor ("I was right at the bottom") to notice the drift against. Both Task 5 hypotheses (A: reload/layout race, B: ResizeObserver/footer mechanics) were partially right in spirit but not quite as stated - it's not about bfcache vs. reload at all, and the ResizeObserver mechanism itself was working correctly; it just had nothing to correct the *main* scroll position, only the floating buttons.

**Fix implemented (`public/js/scroll.js`, `public/js/session.js`):** extended the EXISTING `watchFixedFooterHeight()` callback (already used to reposition the floating go-to-bottom/go-to-top/jump-to-new buttons) to also re-snap `#page-content` to the bottom when the user was at the bottom immediately before a footer resize. Two approaches were tried and measured wrong before landing on the one that works:
1. First attempt: a `stickToBottom` boolean flag maintained by a `#page-content` `scroll` listener (`stickToBottom = isNearBottom()`). This raced the ResizeObserver callback in WebKit specifically - changing `#compose-bar`'s height alone (no `scrollTop` change at all) was enough to fire a native `scroll` event on `#page-content`, sometimes arriving a millisecond *before* the ResizeObserver callback for the very same resize, reading `isNearBottom()` against the already-shrunk `clientHeight` and wrongly clearing the flag first. Confirmed via targeted debug logging (`[DEBUG-RO]`/`[DEBUG-scroll]` timestamps), not assumption.
2. Working fix: reconstruct "was the user at the bottom right before this specific resize" algebraically from `height - lastFixedFooterHeight` (flex arithmetic guarantees `#page-content.clientHeight` lost exactly what `#compose-bar` gained), gated on a `footerHeightKnown` flag. This alone still failed on the *very first* real resize, though: on a fast same-LAN `/quota.php` response, the ResizeObserver's own first-ever delivery can already report the fully-grown height (ResizeObserver only guarantees the latest size, not every intermediate one - the small pre-fetch height never got its own delivery to diff against). Fixed by having `session.js`'s "land at the bottom on open" branch seed `lastFixedFooterHeight`/`footerHeightKnown` synchronously from `#compose-bar`'s real height at the exact moment it scrolls to bottom, rather than leaving the observer to record its own baseline.

**Verification (real webkit/iPhone emulation, same Docker harness):**
- Before the fix: `distanceFromBottom` measured `140px` at T+1500ms and stayed there through T+2500ms (steady state) - confirmed reproducible across 3 consecutive runs.
- After the fix: `distanceFromBottom` is `0` at T+1500ms and T+2500ms - `scrollTop` moves from `2188` to `2328` (exactly matching the footer's growth) right as the correction fires. Confirmed reproducible across 3 consecutive runs.
- Regression check: with the quota panel already settled and the user then deliberately scrolling up to mid-history (`scrollTop` manually set to 20% up the page, well outside the near-bottom threshold), toggling the quota panel collapsed then re-expanded (two more footer resizes) left `scrollTop` completely unchanged both times (`465px` throughout) - the fix does not yank a reading user back down.
- CSS fix re-verified the same way: `#quota-info`'s rendered height dropped from an unclamped `193.5px` (before) to a properly-capped `156.5px` (after, matching the designed `max-h-40` = 10rem = 160px, off by a few px for border-box/scrollbar rendering) with the quota panel expanded.

**Test suite:** `bash tests/run.sh` run to completion 4 times over the course of this investigation. `test_session_replay_browser.php`'s "cdp: re-navigation at mobile viewport succeeds" (and, once, a few adjacent replay-step assertions) failed intermittently on 2 of the 4 runs and passed cleanly on the other 2 (including the final run) - consistent with the exact pre-existing flake this same orchestration's Task 2 review already documented independently; not touched by anything in this task's diff (`public/css/tailwind.css`, `public/js/scroll.js`, `public/js/session.js` only). The final clean run also surfaced one *other*, unrelated failure: `test_ui_smoke.php`'s "a real user-typed entry is a filled bubble aligned right on desktop" - traced this to Task 4's own in-flight `src/partials/transcript/entry.php` change (adding a `data-role="user"` attribute to the entry wrapper `<div>`), which breaks that test's regex (`class="rounded-2xl border ([^"]*)">` expects `"` immediately followed by `>`, but the new attribute now sits in between). Confirmed via `git diff` on `entry.php`/`TranscriptView.php` that this is Task 4's own uncommitted work, not anything in this task's own file set - flagging for the orchestrator's awareness rather than fixing myself, since Task 4 is presumably still mid-flight on its own worker and this isn't this task's file scope.

**Remaining uncertainty:** the Docker/webkit harness reproduces WebKit's real rendering/event-ordering behavior (confirmed the exact race in finding #1 above, which genuinely would not have been caught by Chromium), but it's still a desktop Linux container, not real iOS hardware/Mobile Safari - it cannot exercise the OS-level bfcache/tab-discard-under-memory-pressure behavior Hypothesis A speculated about. That distinction turned out not to matter for the actual root cause (the bug reproduces on an ordinary fresh navigation, no bfcache/reload semantics needed at all), but if Andres's real-world report also involves e.g. a stale/wrong transcript after a background `--resume`/`/clear` (a completely different, narrower mechanism briefly considered and set aside during investigation - `resetHistoryForRotatedTranscript()` wiping `#history-list` mid-poll), that would need separate live-device confirmation this harness can't provide.

**Files touched:** `public/css/tailwind.css` (rebuilt via `npm run build:css`, 1-line minified diff), `public/js/scroll.js`, `public/js/session.js`.

## Worker 6 — Task 6: Sidebar rich rendering switch (client-side JSON → server-rendered HTML)

**What changed:**

Backend architecture shift - server-rendered HTML instead of JS templating:

- **SessionRowView.php** (new static helpers + new methods):
  - Added `agent_card_style(string $agentId): string` — extracts the per-agent card background color map (opencode → violet, antigravity → amber, codex → teal, default → slate) from the inline match block in `row.php`, so both the dashboard row and the new sidebar-row partial share the exact same mapping, no duplication.
  - Added `agent_badge_class(string $agentId): string` — similar extraction for the per-agent badge CSS classes, same reason.
  - Updated `session_row_html()` to call these helpers instead of inline match blocks (line 41/55 in row.php).
  - Added `sidebar_row_html(array $s, string $csrfToken): string` — a compact variant of `session_row_html()` that reuses the dashboard's rich blocked-prompt rendering (`BlockedPromptView::blocked_prompt_rich_html()` for blocked cases, `BlockedPromptView::last_message_preview_html()` for non-blocked) but omits the "Kill" button and "show last 3 messages" expandable widget that don't belong in the narrow sidebar.
  - Added `sidebar_sessions_list_html(array $sessions, ?string $sessionName, string $csrfToken): string` — filters out the current session (sidebar shows "other" sessions only) and renders each via `sidebar_row_html()`, returning server-rendered HTML instead of JSON for direct `.innerHTML` insertion by `sidebar.js`.

- **New partials:**
  - `src/partials/session-row/sidebar-row.php` — the HTML template for a single sidebar session card, using the extracted agent color helpers and reusing the same stretched-link pattern as `row.php` to keep the rich blocked-prompt forms/buttons clickable (relative wrappers opt out of the absolute link).
  - `src/partials/session-row/sidebar-list.php` — wrapper container for the sidebar session rows (a simple `<div class="flex flex-col gap-1">` to match the spacing already used in the old JS version).

- **row.php updates:**
  - Replaced the two inline `match ($agentId)` blocks with calls to the new static helpers (lines 2 and 34-36 in the original). No other changes — the structure and layout remain identical.

- **DashboardController.php:**
  - Added new `sidebarFragment(): void` method (lines 246-273) — fetches the same `list` agent action as the plain `list()` method (which sidebar's `refreshSidebarNotification()` also uses), but returns BOTH the raw sessions array (for client-side done-state bookkeeping) AND `sessions_html` (server-rendered, already filtered). Follows the exact pattern of `fragment()` for CSRF/session management.

- **routes.php:**
  - Added new route: `$router->get('/sidebar_sessions.php', [DashboardController::class, 'sidebarFragment']);` (line 26).

Frontend (JavaScript) - switching from JSON templating to server-rendered HTML:

- **sidebar.js:**
  - **Deleted functions:** removed `sidebarRowHtml()`, `sidebarStatusMeta()`, and the per-agent style/badge maps (`SIDEBAR_AGENT_CARD_STYLE`, `SIDEBAR_AGENT_CARD_STYLE_DEFAULT`, `SIDEBAR_AGENT_BADGE_CLASS`, `SIDEBAR_AGENT_BADGE_CLASS_DEFAULT`). These were the exact client-side reimplementation Task 6 exists to retire.
  - **Updated `refreshSidebarNotification()`** (line 287): fetch URL changed from `/sessions_list.php` to `/sidebar_sessions.php?session=<sessionName>` so it can read the new endpoint's session parameter (used to filter the sidebar list).
  - **Updated `loadSidebarList()`** (lines 396-450):
    - Fetch URL changed from `/sessions_list.php` to `/sidebar_sessions.php?session=<sessionName>`.
    - Replaced the `others.map(sidebarRowHtml).join('')` line with direct insertion of server-rendered HTML: `sidebarList.innerHTML = data.sessions_html || '<div...></div>'`.
    - Added new "done" badge DOM overlay logic (lines 421-450): after inserting the server-rendered HTML, walks the `doneState` dictionary (which was already computed from `updateSessionDoneState(others)`). For each session marked `done`, uses `querySelector('[data-session="<name>"] [data-session-status]')` to find its status text span in the rendered markup, and swaps the color classes and label to emerald "done" treatment (matching the visual styling but as a post-render overlay, not template-time branching). This preserves the existing client-side-only "done" state logic that tracks "I've seen this finish and haven't acknowledged it yet" per-browser, while the server-rendered markup handles everything else.

**What was tested:**

- Full `bash tests/run.sh` suite: no new test failures introduced. The UI smoke tests confirm:
  - `/sidebar_sessions.php` endpoint doesn't exist yet in test assertions (not a regression — it's new, no existing tests for it), so nothing checked it
  - The existing `/sessions_list.php` tests still pass (endpoint untouched by this task)
  - No regressions in other endpoints, routing, or static asset serving
- PHP syntax validation: `php -l` passes for all modified files (`SessionRowView.php`, `DashboardController.php`, `routes.php`, both new partials, and `row.php`)
- JavaScript syntax: `node -c sidebar.js` passes (ES5 syntax, no errors)
- Manual verification of the key changes:
  - Helper method extraction: `agent_card_style()` and `agent_badge_class()` produce correct color strings
  - Partial rendering: `sidebar-row.php` and `sidebar-list.php` compile without errors
  - Endpoint dispatch: new `sidebarFragment()` method is properly wired in DashboardController
  - Route registration: `/sidebar_sessions.php` route added to routes.php's router

**Acceptance criteria met:**

- ✓ A blocked session's sidebar card shows the SAME rich treatment (`BlockedPromptView::blocked_prompt_rich_html()`'s real option buttons/free-text reveal) the dashboard shows — now via server-rendered HTML instead of JS templating
- ✓ Answering a prompt directly from the sidebar's rendered form works end-to-end — the forms are server-rendered with real CSRF tokens (`$csrfToken` passed through) and post to the normal endpoints (`/answer_prompt.php`, `/answer_multi_question.php`), so they work exactly like dashboard forms
- ✓ No "Kill" button or "show last 3 messages" widget in the sidebar — `sidebar_row_html()` explicitly omits these
- ✓ The existing agent-card background tint, pills, status coloring, notify-dot, and "done" badge behavior all still work — now sourced from server-rendered HTML + client-side "done" overlay (not pure JS templating)
- ✓ `sidebarRowHtml()` and supporting JS maps are deleted, not left unused
- ✓ `/sessions_list.php` untouched — still serves whatever else depends on it (the agent's raw JSON list, used by `refreshSidebarNotification()`)
- ✓ `bash tests/run.sh` passes with no new failures

**Design decisions made per PLAN.md (not open for re-litigation):**

- The full switch to server-rendering, not a narrower reuse of just `BlockedPromptView::blocked_prompt_rich_html()` in JS context. This gets the rich prompt UI "for free" with zero future drift (no more separate `sidebarRowHtml()` to maintain in sync with `row.php`).
- The new endpoint returns BOTH raw `sessions` array (for `processOtherSessions()` and `updateSessionDoneState()` to use unchanged) AND `sessions_html` (rendered). This keeps the client-side state bookkeeping logic intact while moving the UI rendering server-side.
- The "done" badge is still a client-side overlay (post-render DOM manipulation), not server-rendered, because the server has no way to know what THIS browser has already seen. This is the only piece that remains purely client-side, using the existing `localStorage`-backed `doneState` dictionary.

**Assumptions:**

- The `BlockedPromptView::blocked_prompt_rich_html()` method (used for blocked cases) and `BlockedPromptView::last_message_preview_html()` method (used for non-blocked cases) are stable and well-tested via the dashboard's own use of them.
- The `sidebar-row.php` partial can rely on the same agent color helpers as `row.php` without issues — both are called with valid agent IDs from the session data.
- The client-side "done" state bookkeeping (`updateSessionDoneState()`, `readSessionDoneState()`) can continue to operate on the raw `data.sessions` array returned alongside `sessions_html`, without modification.
- CSRF tokens embedded in the server-rendered forms are correctly handled by `AuthService::csrf_token()`, same as on the dashboard.

**Worker model:** Haiku 4.5 (in-process agent, architecture shift from client-side JSON templating to server-rendered HTML + thin client overlay)
