---
id: prompt-interaction
based_on: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# prompt-interaction — AUDIT

Re-read against the current tree (HEAD = `44e4caab` matches `last_scanned_commit` in
`DETAILS.md`, so that map is current). Focus: blocked-prompt detection/answering across
Claude/Antigravity/OpenCode, the multi-question tab-bar sequence, the single-vs-multi
AskUserQuestion carve-out, sad-path/error handling, and the OpenCode permission bridge.
Read-only on source; findings ranked by severity.

## Findings

### 1. `build_multi_question_key_sequence` ships an explicitly-unverified generalization for a multiSelect-as-the-LAST-question — the single highest-consequence unknown in the write path
- **Priority/severity:** `high`
- **Recommendation:** `research-more` (then, until confirmed, reject rather than send)
- **Evidence:** `host-agent/lib/Services/PromptParser.php:565-570` — the docblock states the
  "toggle then Right" shape for a multiSelect question is "Not independently confirmed, but
  inferred as a safe generalization … Re-verify live before relying on this if it ever
  misbehaves." Yet the code implements it unconditionally: `:623` appends `['type'=>'right']`
  after every multiSelect, and `:639` appends the trailing `digit '1'` ("Submit answers") no
  matter which question is last. `PromptInteractionService::answer_multi_question()`
  (`PromptInteractionService.php:327-378`) drives the whole sequence in one shot with no
  per-tab pane confirmation.
- **Current complexity / invalid states:** every multi-question answer ends the loop by trusting
  that a trailing `right` (from a last-question multiSelect) lands on the Review tab, then
  confirms option 1. If the generalization is wrong for exactly the last-question case, the
  sequence sends one off-by-one `Right` and then a `1` that confirms a *different* control than
  the intended "Submit answers" — a silent wrong answer, in a path that is supposed to be
  fail-safe (the rest of `build_multi_question_key_sequence` returns `null` on any structural
  doubt rather than sending a partial sequence).
- **Proposed representation:** treat "multiSelect is the last question" as a distinct,
  explicitly-confirmed-or-rejected branch. While unverified, return `null` for that shape so
  `answer_multi_question` rejects it (`PromptInteractionService.php:355-357`) instead of sending
  an inferred sequence — matching the method's own "never send a partial/uncertain sequence"
  contract.
- **Smallest credible implementation scope:** `PromptParser::build_multi_question_key_sequence()`
  (`:592-642`) only — branch on whether the current multiSelect is the last question and either
  (a) return `null` (reject), or (b) once verified, keep the single `right` but add a fixture.
  Optionally a comment pointing at the verified capture.
- **Regression risks / migration:** if accepted as a rejection, a real user with a
  last-question-multiSelect prompt can no longer answer it from the app (they must attach).
  That is strictly safer than risking a wrong answer. No data migration; no interface change
  beyond a possible new `{ok:false}` message.
- **Validation:** existing `answer_multi_question` coverage lives in
  `tests/test_sessions_lifecycle.php:1357-1468` and `build_multi_question_key_sequence` in
  `tests/test_session_hook.php:318-373` — but no fixture/case has the LAST question as a
  multiSelect (the real capture at `:331` uses `[colorToppings, [1,[1,2],1]]` with the
  multiSelect in the middle). Add a real-capture fixture (or a synthetic one) with
  multiSelect-as-last, assert the exact sequence, and re-verify live.
- **Confidence:** `medium` (the docblock itself flags the doubt; the code ships it).

### 2. The PHP `PermissionStore` is an orphaned half-bridge — no production caller, and it carries a latent stale-intent race
- **Priority/severity:** `medium`
- **Recommendation:** `research-more` (decide: delete the unused PHP half, or wire it up as the
  intended source of truth)
- **Evidence:** `host-agent/lib/Services/PermissionStore.php` defines `write_pending_permission`
  (`:56`), `read_pending_permission` (`:72`), `find_by_session_id` (`:88`),
  `write_answer_intent` (`:94`), `consume_answer_intent` (`:108`), `delete_permission` (`:121`).
  A project-wide grep shows the ONLY caller is `tests/test_opencode_permission_store.php` — no
  production PHP calls any of them. Meanwhile the actual logic path is:
  - surfacing: `SessionService::build_session_entry()` `SessionService.php:155-170` says a
    permission is surfaced "ONLY when the pane shows a permission dialog; PermissionStore is
    used just to corroborate" — but `:166-170` sets `$prompt = $ocPanePrompt` and never reads
    the store, so the "corroboration" is aspirational, not real.
  - answering: `PromptInteractionService::answer_prompt()` `PromptInteractionService.php:129-159`
    answers an opencode permission by pane arrow-keys, never by writing an intent.
  So the plugin (`csm-permissions.js`) writes records that PHP never consumes for a decision, and
  PHP never writes an `intent` the plugin's `permission.ask` branch (`csm-permissions.js:128-132`)
  could apply.
- **Current complexity / invalid states:** two competing sources (pane vs the store) for the same
  "is opencode blocked" question, with the READ side (store) dead. The latent race lives in the
  plugin's intent branch: `permission.ask` applies `record.intent` only when one exists,
  clear-on-apply at `:130`; but if a permission is resolved in-pane (no `permission.replied`
  event — the exact case the class docblock at `PermissionStore.php:12-18` and
  `csm-permissions.js:5-11` warn about), the record is cleared only by `permission.replied`
  (`csm-permissions.js:161-167`), so a written intent would survive to auto-answer the NEXT,
  unrelated permission on the same session. Not live today because nothing writes intents, but
  the code is a trap for the next person who wires it up.
- **Proposed representation:** single source of truth. Either (a) accept the live finding that
  the pane is more current and delete the unused PHP read/write methods and the plugin's
  `intent` branch (keep the plugin's `permission.asked` recording purely as a diagnostic
  heartbeat), or (b) commit to the store as the authority, which contradicts the documented live
  stale-dialog finding at `SessionService.php:155-165`. Absent that decision, the stray half is
  misleading.
- **Smallest credible implementation scope:** if deleting: `PermissionStore.php` (whole class),
  `tests/test_opencode_permission_store.php`, and `csm-permissions.js:128-132`. If keeping:
  wire a real `write_answer_intent` caller and drain the intent on `permission.replied` too.
- **Regression risks / migration:** deleting removes the only coverage of the store contract (the
  test), so confirm the plugin still records `_csm-heartbeat.txt`/`permission.asked` as the
  instrument. No user-visible behavior changes either way today.
- **Validation:** current coverage is `tests/test_opencode_permission_store.php` only. A keep-decision
  needs a test that a staged intent is cleared on `permission.replied` AND that a stale intent
  from an earlier permission is not applied to a later one.
- **Confidence:** `high` (grep-verified no production caller).

### 3. OpenCode question-modal parser has no top boundary/heading anchor — a scrollback numbered list can pollute `options`/`question`
- **Priority/severity:** `medium`
- **Recommendation:** `refactor` (small) + add a fixture/test
- **Evidence:** `OpenCodePromptParser::parse_question_modal()` `host-agent/lib/Services/OpenCodePromptParser.php:275` —
  `$scanStart = max(0, $footerIndex - 25)` and `:276-284` collects every `^\s*(\d+)\.\s+` line in
  that window as an option, with no heading to stop it. By contrast the permission modal
  (`parse_permission_modal`) binds to a "△ Permission required"/"△ Always allow" heading
  (`:204-225`). The permission side even has a scrollback fixture
  (`tests/fixtures/opencode_permission_pane_with_scrollback.txt`, asserted in
  `test_opencode_prompt_parser.php:39,69`); the question modal does not.
- **Current complexity / invalid states:** a question modal rendered over scrollback that contains
  an earlier numbered list (e.g. a pasted diff or an earlier "1. … 2. …" assistant output —
  the exact failure the class docblock at `:10-17` records for the permission modal) will be
  mis-parsed: `options` gains phantom entries and `question` (`:292-303`) reads the nearest
  non-option line above the (wrong) first option. The `is_blocked()` footer gate (`:49-52`)
  correctly says a modal is up, but the option extraction isn't bounded above the same way the
  permission extractor is. Bottom-anchored detection protects `is_blocked`; it does not protect the
  option scan.
- **Proposed representation:** mirror `parse_permission_modal` — anchor the question scan by
  walking up for the modal's own heading/boundary line (the '↑↓'/'⇆' chrome is already filtered
  at `:311`; use the modal's first content line above the options, or a small "modal top" scan
  that stops at a blank-separated region boundary) before collecting options, rather than a raw
  25-line window.
- **Smallest credible implementation scope:** `OpenCodePromptParser.php` only (`parse_question_modal`),
  plus a new fixture `tests/fixtures/opencode_question_pane_with_scrollback.txt` and 2-3
  assertions in `tests/test_opencode_prompt_parser.php`.
- **Regression risks / migration:** tightening the scan could reject a legitimately-tall question
  modal whose options sit >25 lines up (unlikely; the modal is compact). The fixture + existing
  tests guard the happy path. No data migration.
- **Validation:** existing `parse_question_modal` is only exercised indirectly (via
  `test_opencode_prompt_parser`'s permission fixtures are permission-only). Add a question-modal
  scrollback fixture and assert `options`/`question` come from the modal, not the scrollback.
- **Confidence:** `medium` (the risk is plausible; no question-modal scrollback capture to prove it
  is happening today).

### 4. `prompt_is_folder_trust` is inferred from any option label merely *containing* "exit" — non-trust prompts get downgraded to the attach-only tip
- **Priority/severity:** `medium`
- **Recommendation:** `tweak`
- **Evidence:** `PromptParser::parse_blocking_prompt()` `host-agent/lib/Services/PromptParser.php:326-333`
  sets `is_folder_trust = true` when `stripos($opt['label'], 'exit') !== false`. Downstream:
  `SessionService::build_session_entry()` `SessionService.php:232` uses `is_folder_trust` to decide
  whether the pane-scraped prompt is the *only* blocker (a no-hook prompt); `SessionRowView.php:43-44`
  then renders only the plain attach tip for that row instead of the rich Approve/Deny buttons
  (`blocked_prompt_rich_html`), i.e. the buttons disappear.
- **Current complexity / invalid states:** the heuristic conflates "folder trust dialog" with "any
  prompt whose option label contains the substring 'exit'". An AskUserQuestion option like "Exit
  and discard changes", or a permission for a command whose name/path contains "exit", would be
  misclassified: on the dashboard the row loses its answer buttons and shows "Attach to answer
  it"; and because `build_session_entry` also uses this flag to gate the no-hook pane-scrape path,
  a non-folder-trust prompt with no hook status could be surfaced as if it were. There is no
  other on-screen signal tying this to the actual trust dialog.
- **Proposed representation:** require the trust dialog's own distinctive signal rather than a
  substring: the question text containing "trust the files in this folder" (the known live
  wording), or the option-pair shape "Yes, I trust …" / "No, exit". Keep the `exit` match only as
  a secondary corroborator ANDed with the question signal.
- **Smallest credible implementation scope:** `PromptParser::parse_blocking_prompt()` (`:326-333`)
  + its `is_folder_trust` consumers (`SessionService.php:232`, `SessionRowView.php:43`); no
  interface change. A fixture for a non-folder-trust prompt with an "exit"-containing option.
- **Regression risks / migration:** the real folder-trust dialog must still be detected; the test
  suite (`test_sessions_lifecycle.php:1695`) asserts `prompt_is_folder_trust` for the
  trust-dialog capture, so keep that green. No data migration.
- **Validation:** `test_sessions_lifecycle.php:1695` (trust case) is the only
  `prompt_is_folder_trust` assertion. Add the negative case: a normal permission/AskUserQuestion
  prompt whose label contains "exit" must NOT be flagged.
- **Confidence:** `medium` (the heuristic is objectively over-broad; trigger likelihood is low but
  real).

### 5. `set_antigravity_model` ignores the confirm-Enter result — a false success
- **Priority/severity:** `low`
- **Recommendation:** `tweak`
- **Evidence:** `PromptInteractionService::set_antigravity_model()`
  `host-agent/lib/Services/PromptInteractionService.php:611` —
  `TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']);` discards the exit code, and the
  `/model`+Enter sends at `:594-597` are also unchecked. Every other state-changing method in this
  file checks the send-keys result and returns `{ok:false,...}` on a non-zero exit (e.g.
  `answer_prompt:163-165`, `set_mode:442-444`, `set_model:521-523`).
- **Current complexity / invalid states:** if the confirm Enter fails (or the picker was already
  dismissed between the cursor-confirm and the Enter), the method returns
  `{ok:true, "Set default model to …"}` and the UI reports success with no change applied. The
  message also omits the `SessionStatusStore::update_status` that sibling mutators do
  (intentional — no mode/model cached-status to snap back), but the optimistic `ok:true` is a
  genuine false success.
- **Proposed representation:** capture the final Enter's `exit`; if non-zero return
  `{ok:false, 'Failed to confirm model selection: ' . trim(stderr)}`, mirroring the file's own
  convention. Optionally re-capture the pane after Enter to confirm the picker closed.
- **Smallest credible implementation scope:** `PromptInteractionService.php:611` only (and
  optionally `:594-597`).
- **Regression risks / migration:** none — this only changes a failure case from a false success to
  a returned error. `test_antigravity_model_switch.php` runs against `fake_antigravity_picker.php`
  which always succeeds, so it stays green.
- **Validation:** `tests/test_antigravity_model_switch.php` (happy path + reject-unknown +
  reject-while-working). Add a sad-path where the picker Enter fails (fixture returns failure)
  and assert `ok:false`.
- **Confidence:** `high` (the line clearly discards the result).

### 6. Mode vocabulary is defined twice — `TranscriptView::MODE_OPTIONS` vs `PermissionMode`
- **Priority/severity:** `low`
- **Recommendation:** `refactor` (small)
- **Evidence:** `host-agent/lib/Services/PermissionMode.php:33-38` owns the four keys
  `manual/accept edits/plan/auto` (as `CLAUDE_CODE_MODE_STATUS_PHRASES`); `src/lib/Views/TranscriptView.php:21`
  independently declares `MODE_OPTIONS = ['manual'=>'Manual', ...]` with the same four keys;
  consumed as the mode dropdown/starting-mode labels at `src/partials/pages/index.php:157` and as
  the select's value by `set_mode` (validated against `PermissionMode::CLAUDE_CODE_MODE_STATUS_PHRASES`
  at `PromptInteractionService.php:418`). This subsystem owns the vocabulary; `TranscriptView` is a
  co-owned render consumer.
- **Current complexity / invalid states:** two constants must be kept in sync manually. Adding a
  mode to one silently desyncs the UI label set from the accepted key set — the dropdown could
  offer a mode that `set_mode` rejects, or `set_mode` accept a mode the dropdown never offers.
  Today they are in sync.
- **Proposed representation:** one source of truth in `PermissionMode`; derive `MODE_OPTIONS` from
  it, e.g. `array_combine(array_keys(PermissionMode::CLAUDE_CODE_MODE_STATUS_PHRASES), ['Manual',
  'Accept Edits','Plan','Auto'])`, so a new mode is added once.
- **Smallest credible implementation scope:** `TranscriptView.php:21` (derive), confirm
  `index.php:157-160` and `set_mode`/`create_cc_session` still get the same keys. No behavior change.
- **Regression risks / migration:** none — the rendered keys/labels are byte-identical. Keep the
  key order consistent (it is today).
- **Validation:** existing UI/tests exercise the four modes (`test_session_replay_browser`,
  `test_ui_smoke`). A unit assertion that `array_keys(MODE_OPTIONS) ===
  array_keys(PermissionMode::CLAUDE_CODE_MODE_STATUS_PHRASES)` would lock it.
- **Confidence:** `high` (two literal constants, grep-verified).

### 7. `answer_multi_question`'s live-pane guard uses a strict `!==` on the question text — brittle fail-safe
- **Priority/severity:** `low`
- **Recommendation:** `tweak`
- **Evidence:** `PromptInteractionService::answer_multi_question()`
  `host-agent/lib/Services/PromptInteractionService.php:346-351` compares
  `$paneScraped['question'] !== $firstQuestionText`, where `$firstQuestionText` is the raw
  hook-fed `questions[0]['question']` and `$paneScraped['question']` is produced by
  `PromptParser::parse_blocking_prompt()` (which picks the last `?`-containing paragraph, or the
  last paragraph as a fallback — `PromptParser.php:270-281`).
- **Current complexity / invalid states:** the guard fails SAFE (returns `{ok:false,
  '…already moved on'}` at `:350` — nothing wrong is sent), but it can false-negative: a prompt
  whose first question has no `?` (it falls back to the last paragraph), or one the pane wraps /
  reflows slightly, or one the modal renders with leading punctuation, will be rejected even while
  genuinely live. The intent is to catch "the prompt moved on," which is already covered by
  `$paneScraped === null` + the `multi_question` flag; the strict text compare adds a robustness
  risk on top.
- **Proposed representation:** relax the text compare to a containment/substring or
  whitespace-normalized check (e.g. `str_contains($paneScraped['question'], $firstQuestionText)`
  or normalized-prefix), keeping `!== null` and `!empty($paneScraped['multi_question'])`. The
  decision stays a safe-fail.
- **Smallest credible implementation scope:** `PromptInteractionService.php:349` only.
- **Regression risks / migration:** minimal; the guard still rejects a genuinely-moved-on prompt
  (which is the `null`/`multi_question=false` cases). The strict test at
  `test_sessions_lifecycle.php:1409` (reject when pane shows a different first question) must stay
  green — a substring compare still rejects a truly different question.
- **Validation:** `test_sessions_lifecycle.php:1403-1411` covers the reject-when-moved-on case; add
  a case where the pane's rendered first question differs only by trailing punctuation/whitespace
  and assert it no longer false-rejects.
- **Confidence:** `medium` (the failure mode is plausible but unproven).

### 8. `PermissionStore::write_file` leaks the tmp file if `rename` fails
- **Priority/severity:** `low`
- **Recommendation:** `tweak`
- **Evidence:** `host-agent/lib/Services/PermissionStore.php:157-178` — `file_put_contents` failure
  unlinks the tmp (`:170-173`) but the `@rename($tmp, $path)` at `:177` has no `unlink($tmp)` on
  failure. Same-session concurrent writers (plugin + host-agent) share the dir, so a failed rename
  leaves a `.tmp.<hex>` orphan.
- **Current complexity / invalid states:** purely cosmetic — a stray tmp file under
  `Config::opencode_permission_dir()`. No correctness impact (atomic rename either fully applies
  or the old file remains).
- **Proposed representation:** mirror the put-contents branch: `if (!@rename($tmp,$path)) { @unlink($tmp); }`.
- **Smallest credible implementation scope:** `PermissionStore.php:177`.
- **Regression risks / migration:** none.
- **Validation:** `tests/test_opencode_permission_store.php` (round-trips); optionally assert no
  leftover `.tmp.*` after a forced rename failure.
- **Confidence:** `high`.

## What's done well

- **Re-validate-then-send is consistently and thoroughly honored.** Every state-changing action
  (`answer_prompt`, `answer_prompt_with_text`, `answer_multi_question`, `set_mode`, `set_model`,
  `set_antigravity_model`, `send_message`) re-derives the live prompt/status from a fresh capture
  or serve-API and returns a specific handled `{ok:false, message}` on every stale/malformed case —
  no uncaught crashes reach the client (`PromptInteractionService.php` passim). The opencode
  question path is additionally orphan-safe: `OpenCodeQuestionService::answer()` re-fetches
  `pending_question` right before POSTing (`OpenCodeQuestionService.php:86`) so a stale/orphaned
  question surfaces as an error rather than a silent no-op.
- **The single-vs-multi AskUserQuestion carve-out is coherent and documented.** A single-question
  AskUserQuestion (no tab bar) stays on the pane-scraped path; `build_prompt_from_hook_status` is
  never called for it (`PromptParser.php:488-494`), and `answer_multi_question` hard-rejects
  `<2` questions (`PromptInteractionService.php:337-344`). This is a genuinely structural decision,
  not an accident.
- **`build_multi_question_key_sequence` returns `null` (never a partial send) on any structural
  mismatch**, and the caller treats `null` as a rejection rather than sending a partial sequence
  (`PromptParser.php:594-641`, `PromptInteractionService.php:353-357`) — a strong fail-safe for the
  highest-risk write primitive.
- **The OpenCode structural `is_blocked()` gate is the right design.** Bottom-anchored, single
  content line above the persistent version footer, never scanning scrollback
  (`OpenCodePromptParser.php:49-114`) — it sidesteps the naive-keyword false-positive class the
  class docblock itself records (`:10-17`), and the `permission` path has a real scrollback fixture.
- **The dense docblocks genuinely earn their length.** `set_mode`'s 2026-08-23 stale-status snap-back
  fix (`PromptInteractionService.php:403-415`), the 300ms key gap rationale (`:22-33`), the
  uniquely-named `csm-<hex>` tmux buffer against cross-request contamination (`:255-266`),
  Antigravity's arrow-drop capture-after-each-press (`:616-640`), and the multi-question review-tab
  capture (`PromptParser.php:170-219`) are all live-verified WHY notes, not changelog padding.
- **`renderBlockedSection`'s dirty-check key** (`session.js:1378`) prevents a whole family of
  poll-during-interaction bugs (lost focus/scroll/expanded-details) by not touching the DOM when
  the prompt hasn't changed.
- **Test coverage for the write paths is real and sad-path-aware.** `answer_prompt`,
  `answer_multi_question`, `set_mode`, `set_model`, `send_message` are covered end-to-end in
  `test_sessions_lifecycle.php` (including the reject-an-invalid-session and reject-while-blocked
  cases); `build_multi_question_key_sequence` has real-capture unit tests and thorough `null`
  rejection cases in `test_session_hook.php:318-373`; `answer_prompt_with_text`/`send_escape` are
  exercised via the browser replay suite; the antigravity model switch has a deterministic fixture.

## Cross-Cutting Observations (described, not solved here)

- **Spawn-mode reverse translation (touches `agent-abstraction`).**
  `PermissionMode::HOOK_PERMISSION_MODE_MAP` maps *hook enum* → *app mode* (`default→manual`,
  `acceptEdits→accept edits`, …). `ClaudeCodeAdapter`/`create_cc_session` need the reverse
  (app mode → `--permission-mode` enum). A naive `array_flip` would map `manual` → `default`
  (correct) but `accept edits` → `acceptEdits` (correct) — so the reverse is not a literal
  per-value mirror; verify the adapter actually derives the enum from this map rather than having
  its own second mapping. Subsystem: `agent-abstraction`.
- **Two competing "is opencode blocked" sources (touches `session-status-state` / the plugins).**
  This is the root of finding #2: OpenCode has no Claude-equivalent hook-fed blocked path, so
  `build_session_entry` uses the pane for permissions and the serve-API/DB for questions, while the
  plugin's `permission.asked` record is never read for a decision. Once opencode's plugin gains a
  reliable `permission.replied` clear (the documented gap at `SessionService.php:155-165` and
  `csm-permissions.js:161-167`), the store could become the authoritative source and the pane
  fallback be retired — until then the two must not drift on which wins.
- **The ES5 rule does not apply to `csm-permissions.js`** but is worth explicitly noting: it is a
  Node.js plugin (`host-agent/opencode-plugins/`), not a `public/js/*` browser file, so its
  `import`/`const`/arrow/optional-chaining (`?:`, `csm-permissions.js:90`) are appropriate. The
  browser files it complements (`session.js`, `common.js`) remain plain ES5 and stay in sync with
  the PHP partials — that ES5 constraint is respected there (no `const`/arrow in the audited
  `session.js` blocks).

## Out-of-scope

- `SessionStatusStore`/`PendingToolStore`/`SidecarStore` internals (owned by `session-status-state`)
  — the blocked-state staleness-clearing via PreToolUse and the `status:working` transitions are
  co-observed but not owned here.
- The agent adapters' `--permission-mode` spawn translation internals (`agent-abstraction`) —
  referenced only as a cross-ref above.
- The push/notification bodies that reuse `format_pending_tool_input` /
  `build_options_from_permission_suggestions` (`push` subsystem) — a downstream consumer, not owned.
- `SessionService::build_session_entry`'s non-prompt fields (title/model/mode/context-marker) —
  owned by `session-core`; only the prompt-relevant lines are audited here.
