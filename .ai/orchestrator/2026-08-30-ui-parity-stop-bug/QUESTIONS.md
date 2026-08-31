# QUESTIONS.md

## Q1 (orchestrator → Andres, 2026-08-30): what does "same footer... plus the context of the session" mean for session pages?

**What I found:** session.php already renders a quota footer identical to
the dashboard's — `src/partials/compose-bar.php:73` calls
`QuotaFooterView::quota_footer_html($agentExtras, $sessionName)`, and
passing `$sessionName` (not empty, unlike index.php's own call) already
makes `quota-footer.js` fetch and render that session's own context-window
percentage as a "Context" row inside the quota panel (see
`public/js/quota-footer.js` lines ~7, ~335-378). This has been wired up
since before commit `bd68433`.

**Why this is genuinely ambiguous, not a "just go check" question:** I
can't tell from the request alone whether:

- (A) Andres hasn't noticed this existing footer/context row and this is
  effectively "no work needed, already ships" (worth confirming either
  way, since if he's asking for it he's presumably not seeing it);
- (B) it's currently broken/not rendering for him for some reason (a real
  bug to find and fix);
- (C) he means something else entirely — e.g. the compact status/pills
  meta line (agent badge, status dot+text, attached, ctx%, git worktree)
  that dashboard rows (`src/partials/session-row/row.php`) and now the
  sidebar (this session's earlier work) both show, reproduced somewhere in
  session.php's own header/session-info area — not the quota bar at the
  bottom at all.

**Recommendation:** (C) reads most likely given the conversational
context — this request came immediately after asking for sidebar-row
visual parity with the dashboard, so "same footer... plus context" may
really mean "the same per-session status/pills strip", with "footer"
used loosely for "the info strip at the bottom of a dashboard row" rather
than literally the page-bottom quota bar.

**Status:** answered (2026-08-30) — "I meant that for session pages i want
the same footer as the dashboard, as in the while [sic] table with all the
agents quotas. The template can probably be reused, just needs an optional
'context' line that the dashboard can ignore." Neither A/B/C as offered —
it's the full multi-agent table (`renderDashboardTable()`), which session
pages don't get today (they get a single-agent-scoped view instead), plus
one new optional context line. See PLAN.md Task 2 for the concrete plan
built from this answer.
