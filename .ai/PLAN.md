# PLAN.md — Agent feature parity

## Objective

Bring Claude Code, Antigravity, OpenCode, and Codex up to parity on the features
they share, working one gap at a time (explain → options → decision → implement),
per the global audit-style-work rule.

## Phase R — Research (must complete before finalizing the gap list)

### R1 — Codex parity audit

- **ID:** R1
- **Objective:** Produce a Codex column for every row in `docs/features.md`'s
  capability list and implementation-status table (Complete / Partial / Missing /
  Broken, mechanism, caveat — same taxonomy already used for the other 3 agents),
  by reading the actual Codex integration code, not by guessing from file names.
- **Relevant files:** `host-agent/lib/Agents/CodexAdapter.php`,
  `host-agent/codex_bridge.php`, `host-agent/lib/Runtimes/CodexBridgeClient.php`,
  `host-agent/lib/Runtimes/CodexHeadlessRuntime.php`,
  `host-agent/lib/Services/CodexTranscriptService.php`,
  `src/partials/transcript/codex-model-toggle.php`,
  `tests/test_codex_runtime.php`, `tests/playwright/codex-live.spec.js`, plus
  whatever `host-agent/lib/Services/SessionService.php`,
  `host-agent/lib/Agents/AgentRegistry.php` (or equivalent registry), and
  `src/lib/Controllers/*` show about how Codex is wired into session
  creation/listing/blocked-prompt/quota/search/archive paths, and how that compares
  to Claude Code / Antigravity / OpenCode's own adapters for the same paths.
- **Dependencies:** none.
- **Acceptance criteria:** every capability-list row and implementation-status row
  in `docs/features.md` has a concrete, evidence-backed (file:line) Codex verdict;
  explicitly flag anything that can't be determined by static reading alone (e.g.
  needs a live session to confirm) rather than guessing.
- **Status:** done — findings in RESULT.md.

## Phase P — Prioritization (done)

Merged R1's findings with the pre-existing gap list in `RESULT.md` into one ranked
backlog (see message to Andres, 2026-08-29). Presented one item at a time per the
audit-style-work rule. This file gets a Phase 1..N of concrete implementation
tasks appended once each item is decided.

## Merged backlog (for reference — decisions tracked here as items are resolved)

### Tier A — active/reachable bugs
- A1. Codex: question-type prompts (`AskUserQuestion` equivalent) unanswerable —
  session-stalling. **Decision: pending.**
- A2. Codex: "Resume" on an archived session runs the wrong CLI (`claude --resume`
  with a Codex thread id). **Decision: pending.**
- A3. Antigravity/OpenCode: archived detail/browse uses Claude-only resolver →
  "Session not found" (todo Tier 1, pre-existing). **Decision: pending.**
- A4. OpenCode: live detail page's forward-poll cursor can silently stall (todo
  Tier 1, pre-existing). **Decision: pending.**
- A5. Codex: push-on-quota-events excludes Codex's bucket (same bug class as the
  documented 2026-08-24 Antigravity incident). **Decision: pending.**
- A6. Codex: health check has zero coverage and `check_hooks()` unconditionally
  reports healthy, masking a down bridge service. **Decision: pending.**

### Tier B — established cross-agent feature gaps
- B1. Content search (dashboard + in-session) is Claude-JSONL-only — excludes
  Antigravity, OpenCode, and (newly confirmed) Codex. **Decision: pending.**
- B2. OpenCode hooks not production-wired — no session-id self-heal, no
  hook-fed status (`csm-status` plugin unbuilt). **Decision: pending.**
- B3. Antigravity/OpenCode model & create-time option reachability — adapters
  support more than the New Session UI forwards. **Decision: pending.**
- B4. Mode switching inconsistency — OpenCode has none, Antigravity is spawn-time
  only despite the doc showing "✓", Codex has none (headless, likely by design).
  **Decision: pending.**
- B5. Effort switching inconsistency — doc says Antigravity-only, but Codex
  audit shows Codex has it live in-session (just not at creation). Doc/reality
  mismatch to resolve alongside a decision. **Decision: pending.**

### Tier C — smaller Codex-specific gaps
- C1. Turn error captured but never surfaced to the frontend.
- C2. Effort not selectable at Codex session creation (only live).
- C3. Codex transcript attachment viewing is a stub (`ok:false` always).
- C4. Todo/plan-glance sidebar widget is OpenCode-only; Codex excluded.
- C5. Push notification agent-label mislabels headless sessions as "OpenCode"
  (cosmetic only today, no visible impact yet).
- C6. Codex approval prompts collapse tool_name to generic `'permission'`.
- C7. Codex free-text prompt answers broken (UI never offers the box; bridge
  ignores it if sent).

### Housekeeping (not a feature decision, just recording ground truth)
- H1. `docs/features.md` needs its Codex column/rows added (this audit's output)
  and the two doc/reality mismatches from A3/B4/B5 corrected. Low-risk, but
  touches a shared doc — confirm with Andres before writing.

## Phase 1+ — Implementation

### Task 1 — Fix A1: Codex question-type prompts unanswerable

- **ID:** 1
- **Objective:** Make a Codex `item/tool/requestUserInput` prompt (single- or
  multi-question) fully answerable through the web UI, with the real answer
  text reaching `codex app-server`, not a lost/garbled value.
- **Relevant files:**
  - `host-agent/codex_bridge.php` (lines ~118-201: `codex_normalize_prompt()`,
    `codex_prompt_response()` — to be extracted, see below)
  - NEW: `host-agent/lib/Services/CodexPromptProtocol.php` (or a name matching
    existing conventions) — a plain, dependency-free static class holding the
    extracted normalize/response logic, PSR-4 autoloaded under
    `HostAgent\Services`
  - `host-agent/lib/Sessions.php:431` (`csm_merge_headless_sessions()`) and
    `:966` (`csm_headless_detail_shape()`) — both hardcode
    `'prompt_questions' => null`
  - `tests/test_codex_runtime.php` (existing conventions: `FakeCodexBridgeClient`
    pattern) — add a new test file for the extracted class, e.g.
    `tests/test_codex_prompt_protocol.php`, plus assertions on `Sessions.php`'s
    two functions if there's an existing test file covering headless merge/
    detail shaping (check `tests/test_*.php` for one before creating a new one)
- **Dependencies:** none.
- **Root causes (confirmed by orchestrator, file:line evidence):**
  1. `prompt_questions` hardcoded `null` in both `Sessions.php` spots — the
     multi-question form (`renderMultiQuestionFormHtml()` /
     `BlockedPromptView::blocked_multi_question_html()`) never renders for
     Codex; UI falls back to a flattened single-option view built from only
     the first question's options.
  2. Even once wired: the shared multi-question form
     (`collectMultiQuestionAnswers()` in `public/js/common.js`) submits a
     selected option as its **ordinal number** (`parseInt(el.value, 10)`) for
     single-select, an **array of ordinals** for multi-select, or `{text:
     "..."}` for free text — see that function's own docblock. Current
     `codex_prompt_response()` (`codex_bridge.php:169-201`) does
     `array_map('strval', $value)` on whatever it receives, so a selected
     option becomes the literal string `"1"`/`"2"` instead of the option's
     actual label text. Free text happens to survive today only by
     accident (`strval` over `{text: "foo"}` yields `["foo"]`).
  3. `codex_normalize_prompt()`/`codex_prompt_response()` currently live as
     bare functions inside `codex_bridge.php`, which spawns a real `codex
     app-server --stdio` process and binds a UNIX socket at top-level the
     instant it's `require`d (see lines 1-100) — there is no way to unit-test
     these functions without extracting them first.
- **Implementation notes:**
  - Extract `codex_normalize_prompt(string $method, array $params): array`
    and `codex_prompt_response(array $pending, array $answers): ?array` into
    a new static-method class under `host-agent/lib/Services/`, PSR-4
    autoloaded (check `composer.json`'s existing autoload map — should already
    cover `host-agent/lib/`). Update `codex_bridge.php`'s two call sites to
    use the class instead of the local functions; delete the old bare
    function definitions from the script.
  - Fix the extracted `codex_prompt_response()`'s `item/tool/requestUserInput`
    branch: for each question (matched by index against
    `$pending['params']['questions']`), resolve the submitted value to real
    answer text:
    - scalar int/numeric string → look up
      `$question['options'][$value - 1]['label']` and use that string
      (fall back to the raw stringified value defensively if the index is
      out of range — never crash on an unexpected shape)
    - `{'text': "..."}` (associative array with a `text` key) → use that
      text verbatim (current behavior already happens to work here — keep
      it, just make the intent explicit rather than relying on the
      `array_map('strval', ...)` coincidence)
    - array of ints (multi-select — not currently reachable from Codex's own
      question shape since it never sets `multiSelect`, but the frontend
      form supports it generically if that ever changes) → map each to its
      option label the same way, joined into the existing `['answers' =>
      [...]]` shape `codex_prompt_response()` already returns per question id
  - Wire `prompt_questions` in both `Sessions.php` spots: when
    `$blocked['tool_name'] ?? null === 'question'`, set `prompt_questions =
    $blocked['tool_input']['questions'] ?? null` (Codex's raw question
    objects already carry `question`/`options[].label` — the same field
    names the templates read — so this is very likely a direct passthrough,
    not a remapping; verify against `codex_normalize_prompt()`'s own output
    shape and BlockedPromptView/session.js's rendering expectations
    (`q.question`, `q.options[].label`, `q.multiSelect` — absent/false is
    fine, Codex questions are single-select today) before assuming no
    transformation is needed). Do this for **every** question-type prompt,
    not just count >= 2 like Claude Code's own threshold — Codex has no
    working pane-based single-question fallback (it's headless), so the
    structured multi-question path is the only correct one for Codex
    regardless of question count.
  - Leave `push_trigger.php:93`'s own `'prompt_questions' => null` alone
    unless investigation shows it actually needs the same fix for
    correctness (it builds a session shape for push-trigger detection, not
    for UI rendering — check what it's actually used for before touching it;
    don't change it speculatively).
  - Do NOT touch the flattened single-option fallback UI/backend paths
    themselves (`blocked_prompt_options_html()`, `renderOptionsCardHtml()`,
    `answer_prompt`/`answer_prompt_with_text` for headless) — once
    `prompt_questions` is always populated for Codex question-type prompts,
    those paths simply stop being reached for that case; they still matter
    for Codex's *permission*-type prompts (approve/deny), which are unaffected
    by this task and already working.
- **Acceptance criteria:**
  - New test(s) prove: (a) `CodexPromptProtocol::normalize_prompt()` (or
    whatever it's named) produces the same output shape the old bare function
    did, for both a 1-question and a 2+-question `item/tool/requestUserInput`
    payload, and for each `*requestApproval` method; (b)
    `response_for()`/`prompt_response()` correctly resolves a selected
    option's ordinal to its label text (not the raw number) for a
    single-select question, correctly passes through free text, and
    correctly handles a multi-select array — with a fixture proving the OLD
    bug (raw number as the answer) does NOT reproduce; (c) a blocked Codex
    session with a question-type prompt gets a non-null, correctly-shaped
    `prompt_questions` from both `csm_merge_headless_sessions()` and
    `csm_headless_detail_shape()`, for both a single- and multi-question
    fixture.
  - `bash tests/run.sh` passes in full (not just the new file).
  - No behavior change for Codex permission-type prompts (approve/deny) or
    for any other agent (Claude Code/Antigravity/OpenCode) — re-run/inspect
    existing tests touching `answer_prompt`/`answer_multi_question` to
    confirm nothing regressed.
  - Leave `docs/features.md`/`todo` untouched — orchestrator updates those
    after reviewing this task's result.
- **Status:** done — orchestrator review 2026-08-29: re-read every changed file
  (`CodexPromptProtocol.php`, `codex_bridge.php` diff, `Sessions.php` diff),
  confirmed the ordinal→label fix and defensive fallbacks are correct,
  confirmed no unrelated files touched and no debug artifacts left, ran
  `tests/test_codex_prompt_protocol.php` and the full `bash tests/run.sh`
  myself (both clean). Working tree left uncommitted for Andres.
