---
id: dashboard
based_on: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-26
---

# Audit — dashboard subsystem

Verified against `DETAILS.md` commit `44e4caa`; repo `HEAD` is exactly
`44e4caa`, so `DETAILS.md` is **current** (not stale). All owned files were
re-read from disk rather than trusted from the summary. Sorted by severity
(most severe first).

---

## 1. Inline `onsubmit` confirm strings break on an apostrophe → destructive action runs with NO confirmation

- **Recommendation**: `fix`
- **Severity / Priority**: `medium` (higher for the bare-row variants)
- **Confidence**: `high`

**Evidence**

- `src/partials/session-row/row.php:49`
  ```html
  <form method="post" action="/" class="relative"
    onsubmit="return confirm('Kill session <?= $this->e($name) ?>?');">
  ```
- `src/partials/session-row/bare-process-row.php:11`
  ```html
  <form method="post" action="/take_over_bare.php" class="take-over-form"
    onsubmit="return confirm('Take over pid <?= $pid ?><?= $tmuxSession !== null ? ' (tmux session ' . $this->e($tmuxSession) . ')' : '' ?>? This process ... will be closed.');">
  ```
- `src/partials/session-row/bare-process-row.php:17` — same pattern for the bare Kill form.

**Current complexity / invalid states**

Plates' `e()` is `htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`
(verified in `vendor/league/plates/src/Template/Template.php:364,371`). That
encodes a `'` to `&#039;` in the HTML source, which is correct for the
*attribute* (single-quote is not special inside a double-quoted attribute, so
there is no XSS). But the attribute's value is a **JS source string literal**:
the browser decodes `&#039;` back to a literal `'` while reading the attribute,
so the JS engine receives e.g. `return confirm('Kill session foo's?');` → a
`SyntaxError`. The inline handler never attaches, and the browser then does the
default form submission — **the kill / take-over executes with the confirm
dialog silently skipped**.

This is a confirmation-guard **bypass**, not an injection: a session name or
tmux session name containing an apostrophe defeats the one protective confirm()
that guards a destructive act.

- Session names are app-generated (`cc-YYYYMMDD-HHMM`, agent-prefixed for
  opencode/antigravity) so they never contain `'` — negligible real-world
  likelihood on `row.php:49`.
- The bare rows are the real exposure: `$tmuxSession` (and the row `title`) come
  from host/`/proc`/tmux data of a process **not started by this tool** (see
  `SessionRowView::bare_process_row_html()` `:107-119` and
  `host-agent/lib/Services/BareProcessService.php`). A tmux session name may
  legally contain a `'`. If one does, that row's Take-over/Kill form submits
  without confirmation.

**Proposed representation**

Do not interpolate untrusted text into a JS string literal at all. Use the same
runtime-concatenation pattern the app already uses elsewhere (safe because the
value is never re-parsed as JS): a `data-confirm-label` attribute + a single
delegated `submit` listener in `index.js` that reads
`form.dataset.confirmLabel` and calls `confirm('Kill session ' + label + '?')`.
The dashboard already does exactly this for the answer-prompt forms
(`index.js:33-66`) and the search-result Archive form (`index.js:937-943`).
`index.php:170`'s confirm is static text (no interpolation) — leave it.

**Smallest credible implementation scope**

- `src/partials/session-row/row.php:49` — drop the inline `onsubmit`, add
  `data-confirm-label="<?= $this->e($name) ?>"` to the form.
- `src/partials/session-row/bare-process-row.php:11,17` — same, with the
  constructed label (pid + optional tmux session) in the data attribute.
- `public/js/index.js` — add the delegated submit-confirm handler inside the
  existing `document.addEventListener('submit', ...)` (or the take-over IIFE),
  gating on the new `data-confirm-label`.
- `src/partials/session-row/bare-process-row.php` is co-owned with
  `session-lifecycle` — coordinate; the form semantics live there.

**Regression risks / migration concerns**

- Two forms on a bare row share the same `data-confirm-label` attribute name;
  give the take-over and kill forms distinct labels so the right confirm text
  shows.
- The confirm must still run **before** the delegated submit handler's
  `preventDefault()`/fetch (it will — the listener body runs after the browser
  fires the form's default action path; keep the `confirm()` short-circuit at
  the top of the handler).
- Mobile Safari: `form.dataset.confirmLabel` is runtime data (no source-level
  literal), so the apostrophe problem disappears — this is the established,
  already-tested pattern in the same file.

**Validation**

- Existing tests cover only "confirm is present in the HTTP layer"
  (`test_ui_smoke.php:1399-1407` asserts the form's shape, not the JS
  confirm behavior). Add a sad-path case with an apostrophe in a bare row's
  `tmux_session`/`title` and assert the confirm dialog is still offered (or
  that the form does not silently submit). If a real headless browser is
  available it should be exercised there; at minimum assert the rendered
  attribute is `data-confirm-label="..."` (runtime-safe) and no inline
  `onsubmit="return confirm('...$name...')"` remains.

---

## 2. Take-over confirm step trusts client-supplied `workdir` (re-validate-then-send breach)

- **Recommendation**: `research-more` (dashboard wrapper) / `fix` (backend)
- **Severity / Priority**: `medium`
- **Confidence**: `high`

**Evidence**

- `src/lib/Controllers/DashboardController.php:311-325` — `takeOverBareConfirm()`
  forwards `$_POST['workdir']` (`:316`) and `$_POST['claude_session_id']`
  (`:317`) verbatim to `action: take_over_bare_with_id`.
- `host-agent/lib/Services/BareProcessService.php:257-272` —
  `take_over_bare_process_with_id()` takes the caller's `$workdir`/`$claudeSessionId`
  straight through to `SessionLifecycleService::resume_cc_session($workdir, $claudeSessionId)`
  (`:271`). It does **not** re-derive `workdir` from the pid's own `/proc` cwd
  (contrast `take_over_bare_process()` `:206-245`, which uses `$proc['cwd']`,
  the authoritative value) and does **not** check `$claudeSessionId` is still
  one of `bare_process_take_over_candidates()`'s candidates.
- `resume_cc_session()` (`host-agent/lib/Services/SessionLifecycleService.php:214-278`)
  only validates that `workdir` is absolute (`:216`) and `claudeSessionId`
  non-empty (`:220`) and not already-live (`:235`) — it never checks the id was
  offered as a candidate for this pid.

**Current complexity / invalid states**

The first take-over step (`take_over_bare()`) is correct: it re-scans the pid
against a fresh `ProcessInspector::find_claude_processes()` and uses the real
`$proc['cwd']`. The **confirm** step, however, lets the client dictate the
resume directory and pick an arbitrary archived id. For a single-user,
LAN-bound, CSRF-protected app the marginal risk is low (the plain `resume`
action at `DashboardController.php:107-123` already accepts an arbitrary
`workdir`+`claude_session_id` from POST), so this is a **consistency breach of
the documented re-validate pattern** rather than a new capability — but it
means the "confirm" endpoint can resume into a different directory than the
bare process's actual cwd, exactly the kind of stale/contradictory state the
CLAUDE.md re-validate rule exists to prevent.

**Proposed representation**

The confirm step should use the same authoritative source as the discovery
step: look the pid up in a fresh process list, use `$proc['cwd']`, and reject
if the pid is no longer running or its cwd is no longer resolvable (it already
skips the kill if gone, `:259-269` — extend that to also refuse to resume a
workdir the client supplied that doesn't match the pid's). If desired, also
verify `$claudeSessionId` is still within the candidates the first call
returned (the canned fixture already asserts this is expected behavior — see
validation below).

**Smallest credible implementation scope**

- The real fix belongs in `host-agent/lib/Services/BareProcessService.php`
  (`session-lifecycle`, named out of scope here) — re-derive `workdir` from the
  pid's `cwd` and/or re-validate the candidate. The dashboard wrapper
  (`DashboardController::takeOverBareConfirm()`) needs no change if the backend
  fixes it; the wrapper just forwards.

**Regression risks / migration concerns**

- A bare process that exited between the picker and confirm would have no
  authoritative cwd anymore. The current design intentionally resumes anyway
  (`:267` comment: "the resume below still makes sense either way"). Any fix
  must keep the window-tolerance: if the pid is gone but the human picked a
  candidate, still allow the resume using the pid's **last-known** cwd captured
  at discovery time (i.e. capture `workdir` server-side during
  `take_over_bare()`, keyed by pid, rather than trusting the client now).

**Validation**

- `test_ui_smoke.php:1373-1398` exercises the HTTP layer against the canned
  fixture. Note `:1394`-`:1397` ("canned agent rejects a claude_session_id that
  does not match the resolved candidate") validates the **canned fixture's**
  stricter logic, not the real `BareProcessService` — the real backend has no
  such candidate check. This audit-relevant test gap should be closed on the
  host-agent side by `test_sessions_lifecycle.php` (which owns the real
  `take_over_bare_process_with_id()`): add a sad-path assertion that a
  mismatched `claude_session_id` is rejected, matching the fixture's contract.
- See Cross-Cutting Observations for the full picture.

---

## 3. Duplicated agent-badge `match` branching in two row partials

- **Recommendation**: `refactor`
- **Severity / Priority**: `low`
- **Confidence**: `high`

**Evidence**

- `src/partials/session-row/row.php:20-26`
  ```php
  $agentBadgeClass = match ($agentId ?? 'claude') {
    'opencode' => 'bg-violet-900/50 text-violet-300 border-violet-700/50',
    'antigravity' => 'bg-amber-900/40 text-amber-300 border-amber-700/40',
    default => 'bg-slate-800 text-slate-400 border-slate-700',
  };
  ```
- `src/partials/session-row/archived-row.php:7-13` — byte-for-byte the same
  `match`, with the same three classes.

**Current complexity / invalid states**

The dashboard makes the agent-badge color a per-partial concern, so the three-way
agent classification is duplicated verbatim across the live row and the archived
row. Adding a fourth agent (a fourth `AgentAdapter`) or tweaking a color requires
editing both, and the two can silently drift. This is the exact "duplicated
branching that a small map/registry would remove" smell.

**Proposed representation**

A single small helper (e.g. `SessionRowView::agent_badge_class(string $agentId): string`,
or a `agentBadgeClass()` template function) returning the class string, called
by both `row.php` and `archived-row.php`. This is the dashboard's own render
vocabulary (both partials are rendered from `SessionRowView`), so the helper
belongs in `SessionRowView`.

**Smallest credible implementation scope**

- `src/lib/Views/SessionRowView.php` — add `private/static agent_badge_class()`.
- `src/partials/session-row/row.php:20-26` and
  `src/partials/session-row/archived-row.php:7-13` — replace the `match` with a
  call to it.
- `archived-row.php` is co-owned with `archived-sessions` — coordinate.

**Regression risks / migration concerns**

- The class strings must stay identical; the helper returns them unchanged.
- `index.js:776-796` (`updateAgentFilterButtons`) independently hard-codes the
  same three color palettes for the archived **filter buttons** — a separate,
  older duplication in the co-owned client renderer. Not in scope here
  (that's `archived-sessions` filtering logic); note it for that subsystem.

**Validation**

- Markup is unchanged (the helper returns the same strings), so existing UI
  assertions in `test_ui_smoke.php` (agent badge presence, e.g. `:96-99`) keep
  passing. No new test strictly needed; a trivial one asserting
  `agent_badge_class('opencode')` etc. is worthwhile if PHPUnit-style unit tests
  exist for views (they don't today — see coverage note).

---

## 4. `index()` coerces a missing `interval_seconds` to `0` (not `null`)

- **Recommendation**: `tweak`
- **Severity / Priority**: `low`
- **Confidence**: `medium`

**Evidence**

- `src/lib/Controllers/DashboardController.php:43-44`
  ```php
  $pushTimerResult = $agentReachable ? AgentClient::agent_call(['action' => 'get_push_timer_interval']) : ['ok' => false];
  $pushTimerIntervalSeconds = (bool)($pushTimerResult['ok'] ?? false) ? (int)($pushTimerResult['interval_seconds'] ?? 0) : null;
  ```

**Current complexity / invalid states**

If `get_push_timer_interval` ever returns `ok:true` without an
`interval_seconds` key, this collapses to `0`, not `null`. `null` is the
contract that means "timer not installed / nothing to adjust"
(`HealthBoxView::push_timer_interval_control_html()` `:33-35` renders `''` only
for `null`), so a `0` reaches the control, where `0` is not a preset
(`:37`) and therefore gets appended (`:39-42`) and rendered as a spurious `0s`
option. The real `PushTimerService::get_push_timer_interval()` always returns
`interval_seconds` when `ok` (`host-agent/lib/Services/PushTimerService.php`), so
today this is a defensive edge, not a live bug.

**Proposed representation**

Use `null` for the missing-key case explicitly:
`? (int)($pushTimerResult['interval_seconds'] ?? 0) : null` →
`? (($pushTimerResult['interval_seconds'] ?? null) !== null ? (int)$pushTimerResult['interval_seconds'] : null) : null`
(or a helper that returns `?int`).

**Smallest credible implementation scope**

- `src/lib/Controllers/DashboardController.php:44` only.

**Regression risks / migration concerns**

- None meaningful: a correctly-formed response (`ok:true` + `interval_seconds`)
  is unaffected.

**Validation**

- `tests/test_push.php:588-625` covers the backend's get/set interval. The
  dashboard rendering path when `interval_seconds` is `null` (control hidden)
  versus present (control shown) is untested in `test_ui_smoke.php` — the
  canned agent returns `ok:false` for `get_push_timer_interval` (default arm,
  `canned_agent.php:425`), so the **control-shown** branch is never exercised in
  the UI smoke test. Add a canned `get_push_timer_interval` + a UI assertion for
  the folded-in control.

---

## 5. Client-side take-over flow and the push-timer control render path are untested

- **Recommendation**: `fix` (add tests — never delete/weaken existing ones)
- **Severity / Priority**: `low`
- **Confidence**: `high`

**Evidence**

- `tests/test_ui_smoke.php:1343-1398` exercises `/take_over_bare.php` and
  `/take_over_bare_confirm.php` via `curl_request` at the **HTTP layer only**.
- `public/js/index.js:204-331` — the take-over client: the `needs_choice` →
  `renderPicker` branch (`:270-272`), the no-candidates fallback
  (`:227-231`), the picker's confirm fetch (`:305-315`) and `restoreRow`/cancel
  re-enable (`:209-292`) are all **JS-only** and have no browser-level test.
- `public/js/index.js:580-723` — the visibility-gated, skip-if-unchanged,
  abortable poll is likewise untested at the client level.
- `HealthBoxView::push_timer_interval_control_html()` control-shown path (see
  finding 4) is untested.

**Current complexity / invalid states**

The HTTP contract is well covered (405 on GET, 403 bad CSRF, ok/needs_choice/
candidates/name payloads, unknown-pid and mismatched-id rejections). But the
branching *the user actually sees* — picker render, no-candidates message,
cancel restores the form, confirm re-enables on failure — is the portion where
the take-over feature's correctness lives, and it has zero automated coverage.
The hard rule (happy + sad path) is not met for this client flow.

**Smallest credible implementation scope**

- If a headless browser harness is available (`test_ui_smoke.php` uses a
  headless browser for the canned PNG decode, per `canned_agent.php:18-20`),
  extend it: click a bare row's Take over, assert the picker appears, assert the
  no-candidates Cancel-only state, assert the confirm POSTs and redirects, and
  assert a network-error path re-enables the button.
- At minimum, assert the rendered `bare_html` take-over form carries a
  `data-confirm-label` (post finding-1 fix) and that `/take_over_bare_confirm.php`
  failure JSON re-enables the confirm button (hard without a browser).

**Regression risks / migration concerns**

- Adding assertions is additive only; no existing test is modified or removed.

**Validation**

- Manual verification of the happy path (picker → resume → redirect), the
  no-candidates path, the network-error path, and the cancel-restore path before
  trusting the client; then encode them as tests.

---

## What's done well

- **Single source of truth for row markup.** `DashboardController::fragment()`
  (`:214-219`) and `index()` (`:53-67`) render through the same
  `SessionRowView` methods (`sessions_list_html`, `bare_processes_html`,
  `session_count_label_html`), so the SSR page and the poll can never drift
  (`SessionRowView.php:35-40` document this deliberately).
- **Re-validate-then-send is real for the primary actions.** Session name for
  `kill` is re-whitelisted against a fresh list
  (`SessionLifecycleService::kill_cc_session()` `:287-293`); bare `kill_bare`
  and the first take-over step re-scan the pid
  (`BareProcessService::kill_bare_process()` `:34-64`, `take_over_bare_process()`
  `:206-245`); CSRF + same-origin guard every mutating path
  (`Controller::require_post_json()` `:31-51`, `handleAction()` `:83-90`); and
  all tmux/`/proc` access stays behind `AgentClient`
  (`DashboardController` never touches host state directly).
- **Thorough HTML escaping.** Every untrusted field (session name, title,
  workdir, git worktree, agent label, health label/detail, search snippets) goes
  through `$this->e()` / `escapeHtml()`; the JS result renderer uses
  `encodeURIComponent` for URLs and `escapeHtml` everywhere else
  (`index.js:945-997`). No XSS found.
- **Visibility-gated, abortable, skip-if-unchanged polling.** Stops on hide,
  aborts in-flight polls on interval change, and avoids `innerHTML` replacement
  when the fragment is identical so an expanded "Show last 3 messages" panel or a
  focused reply box survives (`index.js:580-723`).
- **Defensive empty/error states.** Empty session list → empty-state; empty bare
  → `''` (nothing rendered); empty archived → archived-empty-state; empty health
  checks / unreachable agent → box hidden / banner shown; `relative_time()` on a
  future/zero timestamp degrades to "just now" rather than a crash.
- **Dense docblocks encode real live-found bugs** (stretched-link `row.php:7-9`,
  footer `index.php:199-206`, resume-lock TOCTOU
  `SessionLifecycleService.php:193-210`) — the non-obvious WHY is documented in
  place, matching the project's stated convention.

---

## Out-of-scope (named neighboring subsystems)

- **`session-core`** — the `sessions[]`/`bare[]` model (`SessionService::
  build_session_entry()`/`title_cascade()`), its nullable fields, and the
  shared row-shape assumptions. This audit did not validate the model's field
  invariants beyond the dashboard's consumption.
- **`session-lifecycle`** — `BareProcessService::take_over_bare_process_with_id()`
  candidate re-validation and pid-cwd re-derivation (finding 2's real fix),
  `SessionLifecycleService::resume_cc_session()`, and the bare-process-row form
  semantics. The dashboard only forwards/wires these.
- **`archived-sessions`** — `ArchivedSessionService`, the `archived-*` partials,
  `search_transcripts()` backend, and the `index.js:725-906` archived
  toggle/pagination/agent-filter client (the duplicated badge palettes in
  `updateAgentFilterButtons` `:776-796` belong there).
- **`push-notifications`** — `PushHealthService::health_check()`, 
  `PushTimerService::get/set_push_timer_interval()`; the dashboard only renders
  their results.
- **`session-view`** — `SessionController::history` ("Show last 3 messages"),
  `BrowseController` (New Session folder browser), and `QuotaFooterView`/
  `PushNotifyView`, all called/included by `pages/index.php` but owned here.

---

## Cross-Cutting Observations

**1. The canned take-over fixture is stricter than the real backend (touch:
`session-lifecycle`, reported via `test_ui_smoke.php:1394-1397`).**
The canned fixture rejects a `claude_session_id` that "does not match the
resolved candidate" (`canned_agent.php:304-308`), and the test asserts that
rejection — but the real `BareProcessService::take_over_bare_process_with_id()`
(`:257-272`) has no such candidate check; it resumes whatever id it is handed.
Either the real backend should be tightened to match the fixture's contract
(the right fix, per finding 2), or the fixture/test is asserting behavior the
production code doesn't have and should be corrected. This is a genuine
documented-pattern-vs-implementation mismatch worth resolving in
`session-lifecycle`'s next pass.

**2. Dashboard take-over test coverage is HTTP-only (touch: automated JS).**
`test_ui_smoke.php` drives `/take_over_bare*.php` with `curl`, never the
`index.js:204-331` picker/client branching. Sessions that depend on the JS
branching (no-candidates Cancel-only state, cancel-restore, network-error
re-enable) are unverified; see finding 5.

**3. `index()` makes five serial `AgentClient` calls per full-page load**
(`DashboardController.php:26,33,37,40,43`) that query genuinely independent
host state (list, hook status, VAPID key, health, timer interval). When the
agent is reachable, a failure of any one is silently swallowed into an empty/
`null` render value; there's no partial-failure surfacing beyond the hook banner.
For a single-user LAN dashboard this is acceptable, but if it ever becomes a
hot path the five independent reads could be parallelized or merged. Not a bug
today.

**4. `test_ui_smoke.php` uses `curl_request` (HTTP), not a real browser for the
interactive flows.** The canned-PNG decode confirms a headless browser is
available in the harness (`canned_agent.php:18-20`), yet the dashboard's
interactive JS behaviors (poll skip-if-unchanged, answer-prompt AJAX swap,
multi-question submit, take-over picker) are asserted only at the HTTP layer.
The happy-path/sad-path rule is met for the controllers but not the client.
