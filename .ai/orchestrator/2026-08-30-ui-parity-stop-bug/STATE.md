# STATE.md — Session-page/sidebar UI parity + working-status desync bug

- **Current objective:** Three items from Andres (2026-08-30):
  1. Sidebar session cards: apply the same amber "blocked" bg tint the
     dashboard's blocked-prompt panel has (not just the per-agent card tint
     already shipped this session).
  2. Session pages should show "the same footer the dashboard has, plus the
     context of the session" — clarified 2026-08-30 (see QUESTIONS.md Q1):
     the full multi-agent quota table (`renderDashboardTable()`), which
     session pages don't get today (single-agent-scoped view instead),
     plus one new optional per-session context line.
  3. Bug: a Claude Code session pressed "Stop" on (still shows
     working/thinking in CSM even though the real Claude Code app confirms
     it's no longer thinking).
- **Current step:** All three tasks investigated and fully planned
  (orchestrator, not delegated — each was a bounded single-file/single-
  topic lookup, or in Task 2's case a direct follow-up question rather
  than broad exploration). All three ready to delegate; no remaining
  blockers.
- **Worker status:** About to launch all three in parallel — no file
  overlap between them (Task 1: `public/js/sidebar.js`. Task 2:
  `host-agent/lib/Services/QuotaService.php`, `public/js/quota-footer.js`,
  `tests/test_quota.php`, `tests/test_ui_smoke.php`. Task 3:
  `host-agent/lib/Services/PromptInteractionService.php`,
  `tests/test_sessions_lifecycle.php`).
- **Worker model:** Both tasks are small, bounded, mechanical fixes once
  the investigation above locked in the exact change (same "add a
  SessionStatusStore::update_status() call" shape as the set_mode() fix
  this file's own docblock at PromptInteractionService.php:404 already
  narrates for the same class of bug) - cheapest capable in-process tier
  (Claude Code Agent tool, general-purpose, Haiku 4.5), same choice already
  used successfully for equivalent-sized tasks in the prior "Agent feature
  parity" project (see .ai/archive/agent-feature-parity-2026-08-30/).
- **Important decisions:**
  - The previous `.ai/` contents (Agent feature parity, 2026-08-29/30, all
    4 tasks done/committed) are archived to
    `.ai/archive/agent-feature-parity-2026-08-30/`, not deleted. Do not
    resume that plan; it predates this objective.
  - Task 3's root cause is CONFIRMED via the real Claude Code hooks docs
    (fetched 2026-08-30, https://code.claude.com/docs/en/hooks), not
    memory: the `Stop` hook fires ONLY on natural turn completion, never on
    a user-initiated interrupt (Escape) - there is no dedicated interrupt
    hook. `PromptInteractionService::send_escape()` (the app's own "Stop"
    button) sends Escape to the real pane but never touches
    `SessionStatusStore` itself, so a session interrupted mid-turn has no
    mechanism to ever clear its cached `working`/`status` state back to
    idle. Exactly the same bug class `set_mode()` was already fixed for
    (see that method's own docblock, 2026-08-23) - `send_escape()` was
    simply never given the same treatment.
  - Task 2: deliberately NOT preserving Codex's thread-scoped / OpenCode's
    session-scoped quota precision inside the multi-agent table's rows for
    a session page — ships the literal ask (identical account-wide table
    everywhere) and records the precision loss as a deferred item in
    RESULT.md rather than adding complexity to avoid it. Flag this to
    Andres once Task 2 lands.
- **Incident (2026-08-30, mid-flight, resolved):** This orchestration was
  originally planned on the OLD flat-file layout (`.ai/PLAN.md` etc, no
  per-orchestration folder). While its 3 workers were already running, a
  PEER interactive session on this same repo (`claude-session-manager-fb`,
  a separate, unrelated "Agent feature parity" orchestration that had been
  using the same flat files since before this session started) committed
  its own work (`b0d8337`, "Fix OpenCode's forward-poll cursor..."),
  clobbering this orchestration's flat-file content with its own. No
  actual code conflict — different files entirely. This orchestration's
  plan was migrated to this namespaced folder (one of the in-flight
  workers did this migration itself, following the newer skill version's
  Worker Completion Protocol, faithfully carrying over this orchestration's
  real content — verified by the orchestrator against what it originally
  wrote). The orchestrator then restored the flat `.ai/PLAN.md`/`STATE.md`/
  `QUESTIONS.md`/`RESULT.md` to their HEAD (committed) content via `git
  checkout HEAD -- .ai/PLAN.md ...`, so the peer session's own bookkeeping
  is intact if it resumes — those files are NOT this orchestration's
  concern going forward. Also deleted one duplicate folder the orchestrator
  itself briefly created under a slightly different slug
  (`2026-08-30-session-ui-parity-stop-bug`) before discovering the
  worker-created one already existed with the correct, complete content.
- **Worker status (updated after completion notifications):**
  - Task 1 (sidebar bg tint) — agent id `acd740c7fa3f3e3af` — **done**,
    reviewed by orchestrator: `git diff public/js/sidebar.js` confirmed the
    blocked-reason `subHtml` branch now uses
    `text-amber-200 bg-amber-900/40 border border-amber-700/60 rounded-lg
    px-3 py-2` (exact match to the dashboard's blocked-prompt panel
    classes), the non-blocked last-message branch is unchanged in style,
    and no other regressions in the diff. Acceptance criteria satisfied.
  - Task 2 (session-page quota table) — agent id `a86e9c666704e2e7e` —
    **done**, reviewed by orchestrator: read the full `QuotaService::get_quota()`
    diff (agents map now computed unconditionally, additively merged into
    every branch, `context` correctly moved to a top-level field per the
    plan) and the `quota-footer.js` diff (gate simplified to `data.agents`,
    ~90 lines of now-dead single-agent rendering deleted, context line
    appended in `renderDashboardTable()`). Found and fixed one stale test
    left behind by the restructuring myself: `test_sessions_lifecycle.php`'s
    "no context bucket when the given session isn't live" assertion still
    checked the OLD `quota.context` path (which no longer exists at all,
    regardless of session liveness, so the assertion had gone vacuous —
    always trivially true) instead of the new top-level `context` field;
    corrected to `!isset($unknownSessionResult['context'])`. Full
    `bash tests/run.sh` re-run from scratch after that fix: only failure is
    `test_session_replay_browser.php`'s "cdp: re-navigation at mobile
    viewport succeeds" step — confirmed a pre-existing, unrelated flake
    (passed cleanly on an immediate isolated re-run; same flake the prior
    "Agent feature parity" orchestration already documented independently).
    Acceptance criteria satisfied.
  - Task 3 (Stop-button status desync) — agent id `a806c653dc9a65713` —
    **done**: send_escape() now calls SessionStatusStore::update_status() 
    with status:'idle', blocked:null after a successful Escape send, matching 
    the set_mode() precedent. Regression test added in test_sessions_lifecycle.php 
    covering the SessionStatusStore side effect. All four test assertions pass 
    (send_escape test setup, ok=true, status update to idle, blocked cleared). 
    Acceptance criteria satisfied.
- **Tasks 4/5 — done, reviewed by orchestrator (2026-08-30):**
  - Task 4 (prev-user-message scroll button) — agent id `a3cea2a398d320baf`,
    Haiku 4.5, in-process. Implementation matches PLAN.md's spec: `data-role="user"`
    added to `entry.php`, `#prev-user-btn` positioned correctly in the DOM/
    stacking math, fallback to `#load-until-user-btn.click()` implemented
    as specified. **Found on review**: its `entry.php` change (adding
    `data-role="user"` between the `class` attribute and the closing `>`)
    broke an existing `test_ui_smoke.php` regex
    (`preg_match('/<div class="rounded-2xl border ([^"]*)">.../')` assumed
    `class` was the wrapper's last/only attribute) — fixed directly by the
    orchestrator (widened the regex to `"[^>]*>`, one line, no behavior
    change). Not a design flaw in Task 4's own work, just an interaction
    with a pre-existing test's fragile assumption; caught and closed
    myself rather than sent back for a 3rd round.
  - Task 5 (iOS resume scroll/footer bug) — agent id `aed8f8e745f7791ba`,
    Sonnet, in-process, resumed once after its first pass stalled without
    finishing its own bookkeeping. Second pass: **reproduced for real**
    against actual webkit (Playwright's official Docker image, since this
    Arch/CachyOS host can't run the precompiled webkit binary natively —
    a genuinely correct workaround, not a shortcut) with `devices['iPhone 13']`
    against the real running app. Found and fixed TWO independently-verified,
    compounding root causes: (1) `public/css/tailwind.css` was stale —
    `.max-h-40` (added 4 days ago, meant to cap the quota panel) was never
    rebuilt into the compiled CSS, confirmed via `getComputedStyle` showing
    `max-height: none`; rebuilt via `npm run build:css`, verified myself
    that `.max-h-40{max-height:calc(var(--spacing) * 40)}` is now really
    in the file — this explains "footer too tall". (2) The layout race:
    `session.js`'s initial `scrollToBottom(false)` runs before
    `quota-footer.js`'s async fetch grows `#compose-bar` (a real flex
    sibling), leaving `scrollTop` stale against a shrunk `clientHeight` —
    measured live at a 140px gap, reproduces on ANY page load, just far
    more visible on iOS resume since there's no navigation transition
    masking the pop-in. Fixed via `watchFixedFooterHeight()` reconstructing
    "was the user at the bottom right before this resize" via flex
    arithmetic, seeded synchronously from `session.js` to cover the very
    first resize event too (a plain boolean-flag attempt was tried first,
    found to race a WebKit-specific `scroll` event the resize itself
    fires, and replaced). Verified via repeated before/after measurement
    (140px → 0px) across 3 runs, plus a regression check that a
    deliberate mid-history scroll position is NOT yanked back by a footer
    resize. Orchestrator independently confirmed the CSS fix (grep above)
    and re-ran the full suite twice after applying the Task-4-interaction
    fix above; only remaining failure is the pre-existing, already-
    documented `test_session_replay_browser.php` CDP flake (re-runs clean
    in isolation).
- **Task 6 — launched** (Andres confirmed 2026-08-30: full switch, not
  the narrower alternative). See PLAN.md for the complete design (new
  `SessionRowView::sidebar_row_html()`/`sidebar_sessions_list_html()`,
  new `DashboardController::sidebarFragment()` endpoint, `sidebar.js`
  swapping to consume server-rendered HTML with a client-side "done"-badge
  DOM overlay, dead JS rendering code deleted). Launched — agent id
  `a7c97398e01c58793`, Haiku 4.5, in-process — running.
  The Codex/OpenCode session-scoped quota precision trade-off from Task 2
  (see RESULT.md) is still open and should be flagged to Andres.
- **Outstanding blockers:** None. Tasks 1-5 done and independently
  reviewed. Task 6 spec'd and ready to launch.
