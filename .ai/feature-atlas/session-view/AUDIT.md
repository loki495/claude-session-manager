---
id: session-view
based_on: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# session-view — Audit

> **Verification note (working-tree drift).** `DETAILS.md` was scanned against
> commit `44e4caa` (HEAD). The working tree at audit time has **uncommitted
> modifications** to several owned files that were re-read directly for this
> audit and are the basis for every finding below:
> `host-agent/lib/Services/AntigravityTranscriptService.php`,
> `OpenCodeTranscriptService.php`, `public/js/session.js`, `sidebar.js`,
> `src/lib/Controllers/SessionController.php`,
> `src/lib/Views/TranscriptView.php`,
> `src/partials/{compose-bar,pages/session,sidebar}.php`, `src/routes.php`.
> `DETAILS.md`'s line anchors still land correctly on the current files (the
> drift is additive, not structural), but a future re-scout is advisable before
> trusting `DETAILS.md`'s method-line map as gospel. `based_on` records the
> commit the map was verified against, not the dirty working tree.

Line numbers below are against the **current working tree** files.

---

## Findings (most severe first)

### 1. HIGH — OpenCode forward-poll (`read_transcript_page_since`) misaligns the line cursor with the filtered entry array, so a live session's detail page silently stops updating

**Recommendation:** `fix`
**Priority/severity:** `high` · **Confidence:** `high`

**Evidence**
- `host-agent/lib/Services/OpenCodeTranscriptService.php:318` and `:407` set
  `$entry['line'] = $idx + 1` where `$idx` iterates `$allMessages` — i.e. `line`
  is the **raw message-row position**, not the renderable-entry position.
- `:413-414` (forward path): `$start = max(0, $afterLine); for ($i = $start; $i < count($renderable) ...) $entries[] = $renderable[$i];`
  — `$afterLine` is the raw `line` the client sent back (session.js
  `pollHistory()` sends `after=newestLine`), but it is used to **index the
  filtered `$renderable` array**.
- `:324` (backward path): `$upperBound = $before !== null ? max(0, min($before - 1, $totalRenderable)) : $totalRenderable;`
  — the backward cursor is likewise a *renderable* index, while the `line`
  values reported to the client are *raw* indices.

**Current complexity / invalid states**
`line = allMessageIndex + 1`, but the paging arrays (`$renderable`) and the
`before`/`after` cursors are *filtered* positions. These two coordinate systems
coincide only when **every** message row is renderable. `message_to_entry()`
returns `null` (and thus drops the message) for a message whose parts are all
`synthetic`, `step-start`/`step-finish`, `reasoning`, `file`, or otherwise
empty (`:198-239`). The moment even one such message sits *before* the newest
renderable line, `line` desynchronises from the renderable index by that gap.

Concrete failure: messages lines 1,2,3(step-start-only, dropped),4 → renderable
= [line1, line2, line4]. Client renders to `newestLine=4`; the next poll sends
`after=4`; `$start=4`, `count($renderable)=3`, the loop never runs and returns
`{ok:true, entries:[]}` **forever** — genuinely new messages appended later
(e.g. line 5) are never returned. The page reads a fresh-but-empty response
every cycle and just keeps showing the stale tail; no error surfaces, no retry,
nothing tells Andres the live view froze. This is the "stale/contradictory
state" failure the audit rules call out, and it is silent rather than handled.

**Proposed representation**
Make `line` and the paging cursors share one coordinate system: the
**renderable-entry position**. In both `read_transcript_page` and
`read_transcript_page_since`, replace
`$entry['line'] = $idx + 1;` with a 1-indexed position into `$renderable`
(e.g. `$entry['line'] = count($renderable) + 1;` immediately before the
`$renderable[] = $entry;` push). Then `line` (a renderable position), `$before`
(exclusive renderable bound), `next_before` (`index + 1`, already renderable),
and `after` (renderable position) all line up, and `$renderable[$afterLine]` is
correct because `$afterLine` is now a renderable position. OpenCode has no
stable "raw JSONL line" analog the way Claude Code does (there is no file to
grep a line number out of), so renderable position is the right and only
meaningful cursor; it also matches the `line` semantics Claude/Antigravity use
(entry-index-as-cursor), which keeps `TranscriptView`/`session.js` unchanged.

**Smallest credible implementation scope**
- `host-agent/lib/Services/OpenCodeTranscriptService.php` — the two `line`
  assignments (`:318`, `:407`) and nothing else. The backward paging math
  (`$upperBound`/`next_before`/`has_more`) already uses renderable indices, so
  it needs **no change**; only the `line` value needs to *become* a renderable
  index so it round-trips through `after`/`before` consistently.

**Regression risks / migration concerns**
- The `line` value reported to existing clients changes meaning (raw row → renderable position). This is a **behavior fix**, not a wire-contract break: every downstream consumer (`session.js` uses `line` only for `after`/`data-line`/`entry.line > newestLine` filtering; search uses filesystem-backed `line` for Claude/Antigravity only) treats `line` as an opaque monotonic cursor, so a smaller monotonic value is safe. The main visible effect: sessions that previously froze on the poll now keep updating, and `data-line` (search jump) stays consistent because it is server-populated.
- Claude/Antigravity are untouched (their `line` is a genuine raw JSONL count and both `after`/`before`/`next_before` already index by it).

**Validation**
- Existing: `tests/test_opencode_transcript.php:165-182` covers `read_transcript_page_since` only with the fixture whose dropped messages (`msg_005`, `msg_006`) are **at the tail**, after all renderable rows — that is exactly why it passes today and never trips the misalignment.
- Additional validation that must be added: a fixture with a non-renderable message (e.g. a `reasoning`-only or `step-start`-only row) **inserted mid-sequence**, then assert `read_transcript_page_since($sessionId, <line-of-last-renderable>, 10)` returns the messages after the gap, and assert the same for a backward `read_transcript_page` page boundary. Without this the fix would ship with the bug still invisible to the suite.

---

### 2. MEDIUM — PHP/JS markdown escaping diverge on `'` and `"`, and the byte-for-byte parity test never exercises them (blind spot in the project's stated invariant)

**Recommendation:** `fix` (align escaping or escape consistently) + `tweak` (extend test inputs)
**Priority/severity:** `medium` · **Confidence:** `high`

**Evidence**
- `src/lib/Views/MarkdownRenderer.php:176` — `htmlspecialchars(implode("\n", $lines), ENT_QUOTES, 'UTF-8')` escapes **both** `'` (`&#039;`) and `"` (`&quot;`).
- `public/js/markdown.js:114` — `mdRenderProse()` does `mdRenderInline(escapeHtml(...))`, and `escapeHtml()` (`public/js/common.js:359-363`) is `div.textContent = text; return div.innerHTML;`. Browser text-node serialization escapes `&`, `<`, `>` but **not** `'` or `"`. So for input `it's "here"`: PHP emits `it&#039;s &quot;here&quot;`, JS emits `it's "here"`.
- The same `escapeHtml` is used by `mdRenderList()` (`:119`) and `mdRenderCodeBlock()` (`:131`), so all three paths diverge identically.
- `tests/test_markdown_parity_browser.php:81-98` — the curated input set (reused from `test_markdown.php`) contains **no** apostrophe or double-quote anywhere, so the divergence is never asserted. The test is byte-for-byte (`:122`) but only over a quote-free sample.

**Current complexity / invalid states**
The project's documented invariant (CLAIMED in `MarkdownRenderer.php`'s own docblock: "Mirrored line-for-line … keep both in sync"; and the platform-level rule captured by the parity test) is broken in a way the guard cannot see. Both renderers produce visually identical HTML in a browser (a literal `'` and `&#039;` render the same), so there is **no user-visible bug today** — this is a contract divergence with a silent test hole, exactly the kind the project's own 2026-08-23 audit introduced the parity test to catch.

**Proposed representation**
Two clean directions, pick one:
1. Make `escapeHtml` quote-safe for this purpose — the simplest is for `markdown.js` to use its own escaper that matches `htmlspecialchars(ENT_QUOTES)` (`& → &amp;`, `< → &lt;`, `> → &gt;`, `" → &quot;`, `' → &#039;`) rather than the DOM-trick `escapeHtml` (which is fine for attribute contexts but not the markdown text context here).
2. Or relax PHP to not escape quotes in prose (drop `ENT_QUOTES` → `ENT_NOQUOTES`), which is safe because markdown output is always injected as text content / double-quoted-attribute-free. Option 1 is preferred: it keeps PHP's stricter escaping and makes the two genuinely byte-identical.

**Smallest credible implementation scope**
- `public/js/markdown.js` — add a local `escapeHtmlQuotes()` (or parameterise the existing calls) and use it in `mdRenderProse`/`mdRenderList`/`mdRenderCodeBlock`.
- `tests/test_markdown_parity_browser.php` — add `"it's a \"quoted\" reply"` (and a standalone apostrophe case) to `$inputs`.

**Regression risks / migration concerns**
- Option 1 changes only the JS output for inputs containing quotes; because the current JS output is *already browser-correct*, the only functional delta is that the parity test will start passing for those inputs. No visual change.
- Do not touch `escapeHtml` in `common.js` globally — it is shared by many call sites that rely on its current DOM-trick behaviour (and its exact casing); widen it there only with a separate, deliberate change.

**Validation**
- Existing: `test_markdown.php` (pure PHP) and `test_markdown_parity_browser.php` (best-effort, SKIPs without Chrome).
- Additional: extend `$inputs` with the quote cases and re-run both files; the parity test should then assert equality for quote-bearing inputs. Also re-run `tests/run.sh` (the parity test is `*_browser.php`, gated by `--browser`).

---

### 3. MEDIUM — Documented `data-line`/`.copy-btn`/`.copy-source` invariant is not actually enforced: `image` blocks carry no `data-line` at all, and tool/task blocks carry `copy` only inside the nested collapsible shell

**Recommendation:** `tweak` (tighten the documented contract, or enforce it) — not a behaviour bug in the common path
**Priority/severity:** `medium` · **Confidence:** `medium`

**Evidence**
- `src/partials/transcript/block.php:13` — `image` kind with `$imageHtml !== ''` renders `$imageHtml` **with no `data-line` and no `.copy-btn`/`.copy-source`** (`src/partials/transcript/image.php:1` has neither). `block.php:14` only adds `data-line`/copy when `$imageHtml === ''` but `$text !== ''`.
- `block.php:10` (`tool_use`), `:11` (`tool_result`), `:12` (`task_notification`) — the block **wrapper** (`tool-use-block`/`tool-detail`) carries `data-line` but **no** `.copy-btn`/`.copy-source`; copy is emitted by the *nested* `$collapsibleHtml` (from `BlockedPromptView::render_collapsible_block()`/`render_collapsible_markdown_block()`, which do emit their own `.copy-btn`/`.copy-source`).
- `public/js/session.js` mirrors **exactly** the same per-kind shapes (`:375`, `:393`, `:407`, `:410-411`) — so **the two runtimes are in sync with each other**; the drift is *documentation-vs-code*, not JS-vs-PHP.
- The stated invariant (repo `CLAUDE.md` and `DETAILS.md` §10): "Every transcript block (text/plan/tool_use/tool_result/task_notification) carries … `data-line` and `.copy-btn`/`.copy-source`."

**Current complexity / invalid states**
- Copy *does* work for tool/task blocks, but only via the innermost collapsible block — a future maintainer reading the documented invariant (or writing a new block kind against it) will assume copy/`data-line` live on the block wrapper and be silently wrong.
- The real functional gap is the `image`-with-`imageHtml` case: it has **no `data-line`**, so a search hit whose snippet came from an image block's text (an image block *can* carry non-empty `text` alongside `imageHtml`; `block.php:14`'s `$text !== ''` fallback proves the possibility) yields a jump target with no `[data-line=N]` element — `session.js:2730` `querySelector` finds nothing, and the scroll/highlight silently no-ops (no crash, just no jump). This only bites when a search matches an image block's text field, which is rare.

**Proposed representation**
Either (a) **document the actual contract honestly**: `data-line` on every block kind, `.copy-btn`/`.copy-source` on `text`/`plan` wrappers **and** inside collapsible/tool shells (nested, not wrapper-level); the `image` case is exempt from both unless a renderable-text fallback is shown; or (b) **enforce** the literal invariant: put `data-line` on the image thumbnail wrapper, and drop a `.copy-btn`/`.copy-source` on the tool/task wrapper. (a) is the smaller, lower-risk change and matches the code's careful nested-copy design; (b) risks double copy-buttons.

**Smallest credible implementation scope**
- `DETAILS.md` §10 (and the repo `CLAUDE.md` block-attribute bullet) — restate the invariant precisely, calling out the `image` exemption and the nested-copy detail. No PHP/JS change needed if you choose (a).
- If you choose (b): `src/partials/transcript/block.php:13` + the JS `session.js:410-411` image case; and the tool/task wrapper cases in both files. This touches the mirror pair and needs the parity/UI tests re-checked.

**Regression risks / migration concerns**
- (a) is doc-only — zero risk. (b) touches the PHP/JS block-render mirror; the two must stay byte-compatible (they already are), and adding a wrapper-level copy button to a collapsible item would show two "Copy" affordances (wrapper + inner shell), which is why (a) is recommended.

**Validation**
- Existing: `test_transcript.php` covers block *parsing*, not rendering attributes; `test_ui_smoke.php` is the only DOM-level check and is best-effort.
- Additional: a UI/parity assertion that every rendered block in the history list carries `data-line`, and that `session.php`/`session.js` output carry matching `.copy-btn`/`.copy-source` — the current test set has no such assertion, which is why this drift went unnoticed.

---

### 4. MEDIUM — `find_pending_question`'s docblock is malformed (nested `/**` and a truncated mid-sentence prose line)

**Recommendation:** `tweak`
**Priority/severity:** `medium` · **Confidence:** `high`

**Evidence**
- `host-agent/lib/Services/OpenCodeTranscriptService.php:512-522` — the comment begins `/**` at `:512`, runs into `:519`, then has a **stray `/**` at `:520`** inside the same comment block, and the `@return` type is on `:521`. The prose sentence at `:517-519` ends mid-thought: "…verified live 2026-08-25 on ses_fc8124)." then a fresh `/**` and `@return` — the intent (a docblock for `find_pending_question`) is split across two comment opens and reads as two half-docblocks.

**Current complexity / invalid states**
Not a syntax error (PHP treats `:520`'s `/**` as comment text), but it is a genuine readability/lint hazard: any docblock-oriented tooling (PHPStan/IDE) and any future reader will mis-attribute the `@return` to the wrong concept, and the truncated sentence suggests the comment was edited mid-edit. This is the one place in a generally meticulous codebase where the docblock convention this file otherwise follows is broken.

**Proposed representation**
One clean `/** … @return array{question:string, header:?string, options:array<int, array{number:int, label:string}>}|null */` block closing before the function; move the "Used by SessionService … verified live 2026-08-25 on ses_fc8124" sentence inside it and finish it.

**Smallest credible implementation scope**
- `host-agent/lib/Services/OpenCodeTranscriptService.php:512-522` — collapse the two `/**` into one, complete the sentence.

**Regression risks / migration concerns**
None (comment-only).

**Validation**
- Existing: `test_opencode_transcript.php` exercises `find_pending_question` via `SessionService` indirectly; none are affected by comment text.
- Additional: none required; an explicit `find_pending_question` unit test against a canned `question` part (running/pending/completed staleness) would be the valuable addition, but it is orthogonal to this finding (see Cross-Cutting note TS1).

---

### 5. LOW — Duplicated block-cap truncation loop + near-identical paging walk across the three transcript backends

**Recommendation:** `refactor` (small shared helper) — weighed against the documented "one class per backend" choice
**Priority/severity:** `low` · **Confidence:** `high`

**Evidence**
- The `strlen > TRANSCRIPT_BLOCK_HARD_CAP_LENGTH` truncation loop is duplicated verbatim (plus the same `"\n… (truncated)"` suffix) in:
  - `AntigravityTranscriptService.php:240-245`
  - `OpenCodeTranscriptService.php:241-246`
  - `TranscriptService.php:1617-1620`
- The backward paging walk (`$untilRealUserMessage` → `$effectiveLimit`, `array_reverse`, `next_before = $index > 0 ? $index + 1 : null`, `has_more = $index > 0`) is repeated in the same three files (`TranscriptService.php:1694-1719`, `AntigravityTranscriptService.php:275-300`, `OpenCodeTranscriptService.php:326-345`), each with only the "real user message" predicate differing.

**Current complexity / invalid states**
`TRANSCRIPT_BLOCK_HARD_CAP_LENGTH` is declared once on `TranscriptService`, so the value is shared, but the *logic* is three copies. If the truncation/suffix semantics change (e.g. a different suffix, a byte-vs-char boundary), three sites must be edited and kept identical; the OpenCode/Antigravity classes each re-implement it by reaching into `TranscriptService`'s constant — a mild cohesion smell.

**Proposed representation**
A tiny shared helper on `TranscriptService` (the existing "cap constants home"), e.g.
`public static function apply_block_caps(array &$blocks): void` that runs the truncation loop, plus a shared `finish_page(array $entries, int $index): array` that returns `{entries, next_before, has_more}`. This preserves the deliberate "separate class per backend" structure (which is well documented and correct — the storage shapes genuinely differ) while removing the boilerplate from inside it.

**Smallest credible implementation scope**
- `TranscriptService.php` — add `apply_block_caps()` / `finish_page()`.
- The three services — call them instead of the inline loops. Do **not** try to merge the three classes or the storage readers; that refactor is explicitly out of the project's design intent and would be high-risk.

**Regression risks / migration concerns**
- Touching three files' hot paths; the paging math must stay byte-identical. The `test_*_transcript.php` suites already assert page boundaries and caps precisely, so they are the safety net — run all three plus `tests/run.sh`.

**Validation**
- Existing: `test_transcript.php` (caps, paging, `$untilRealUserMessage`), `test_antigravity_transcript.php`, `test_opencode_transcript.php` all cover these paths.
- Additional: no new tests needed if the existing suites pass, but a cap-boundary assertion (`len == CAP`, `len == CAP+1`) per backend would lock the shared helper's exact contract.

---

### 6. LOW — `tool_part_to_blocks` has a dead duplicate `filePath` branch

**Recommendation:** `refactor` (delete dead branch)
**Priority/severity:** `low` · **Confidence:** `high`

**Evidence**
- `host-agent/lib/Services/OpenCodeTranscriptService.php:135-145` — `if (isset($input['filePath']))` at `:135` already captures the case; the `elseif (isset($input['filePath']))` at `:143` is unreachable dead code (identical body).

**Current complexity / invalid states**
Unreachable branch — no behaviour impact, but it is a maintenance trap: the second `filePath` branch looks like an intentional fallback but can never fire, and a reader may "fix" the first branch expecting the second to cover a variant.

**Proposed representation**
Delete `:143-145`. The `elseif` chain becomes `filePath → url → command → query` and the trailing default `$toolName . ': ' . json_encode($input)` from `:131`.

**Smallest credible implementation scope**
- `host-agent/lib/Services/OpenCodeTranscriptService.php:143-145`.

**Regression risks / migration concerns**
None (dead code removal); the existing `test_opencode_transcript.php:130-133` tool_use assertion still holds.

**Validation**
- Existing: `test_opencode_transcript.php` (tool block summary).
- Additional: none.

---

### 7. LOW — `read_transcript_page`/`_since`/`read_attachment`/`search_transcript_file` load the whole transcript and re-scan for ExitPlanMode on every request

**Recommendation:** `research-more` (measure before refactoring) — bounded by the file being re-read each poll anyway
**Priority/severity:** `low` · **Confidence:** `medium`

**Evidence**
- Full-file load per call: `TranscriptService.php:1684` (`@file()` in `read_transcript_page`), `:1762` (`_since`), `:1798` (`read_attachment`), `:280` (`search_transcript_file`).
- Full exit-plan id-map re-scan per page/poll: `find_exit_plan_mode_tool_use_ids($lines)` is called at `:1692` (every `read_transcript_page`), `:1769` (every `_since`), and `:294` (`search_transcript_file`). Each call loops every line with a `str_contains` short-circuit (`:888`) before any `json_decode`.
- The file grows monotonically in a live session; the poll fires every `POLL_INTERVAL` (default 3000ms) per visible session, so a multi-MB transcript is re-`file()`-ed and re-scanned every few seconds.

**Current complexity / invalid states**
The tail-scan discipline is applied elsewhere on purpose (see `find_latest_ai_title`/`find_latest_todo_list` comments: "so a multi-MB file isn't loaded into memory just to show a title/todo/cwd"), yet the hot paging path is the exception: every poll fully materialises the array and re-derives the exit-plan id set. For a single-user LAN app it is a latency/CPU cost, not a correctness bug, but it is the only per-poll cost that scales linearly with total transcript size.

**Proposed representation**
- Avoid re-deriving `$exitPlanModeToolUseIds` on the forward-poll path: `read_transcript_page_since` only needs it to classify a *plan* tool_result the poll might return. Memoise per-request by line-count (a static keyed by `filemtime`/count) or compute it lazily only when a line actually contains `ExitPlanMode`'s id. The implementation currently recomputes even when the page/`_since` window contains no plan at all.
- For `read_attachment`, read only the one line (seek) rather than the whole file — `@file()` on a 50MB transcript to fetch one attachment's bytes is the worst offender.

**Smallest credible implementation scope**
- `TranscriptService.php` `read_transcript_page_since` / `read_attachment` — only the two hot paths. Leave `read_transcript_page`/`search` as-is unless a measurement justifies it.

**Regression risks / migration concerns**
- Changing the ExitPlanMode scan lazily must preserve plan approved/rejected classification (covered by `test_transcript.php` plan cases). Keep short-circuiting identical; the scan is idempotent.

**Validation**
- Existing: `test_transcript.php` plan/ExitPlanMode + `_since` cases; runs against a small fixture so it won't catch a performance regression by itself.
- Additional: a timing/memory smoke on a large synthetic transcript (e.g. append 50k lines) asserting the poll path doesn't re-derive the id-map when no new plan exists — best as a manual check, not a CI assertion, given the project's no-op heavy tooling posture.

---

## Test coverage gaps (recommendations only — no test modified or weakened)

- **Forward-poll on a gap-bearing OpenCode transcript** — `tests/test_opencode_transcript.php:165-182` only exercises `after` when the dropped messages sit at the tail. Add a fixture with a `reasoning`/`step-start`-only message interspersed *before* the newest renderable line, then assert `read_transcript_page_since` continues past it. This is the test that would have caught Finding 1.
- **Markdown parity with quotes** — `tests/test_markdown_parity_browser.php:81-98` has no `'`/`"` input, so the Finding 2 divergence is invisible. Add them.

---

## What's done well

- **The PHP↔JS mirror discipline is real and well-maintained.** `render_transcript_block()`/`block.php` and `renderBlock()`/`session.js` are in genuinely exact sync for every block kind including the subtle `image` fallback and the `tool_result` "image/attachments are siblings, not nested" shape. The mirror comments cross-reference each other by name and the whole pairing/`$forceFullBlock` contract is documented on both sides.
- **Markdown XSS posture is solid.** Both renderers escape every raw run before any inline substitution, tokenise inline-code spans so they can't be re-parsed as bold/italic, and emit only a fixed allow-list of tags. The NUL-token collision safety is genuinely thought through on both sides.
- **Sad paths are handled, not crashed.** `read_attachment` re-derives the path, bounds by `ATTACHMENT_MAX_BYTES`, and returns `{ok:false, message}` for every failing edge; `read_transcript_page*` return structured `{ok:false, message}` on unreadable files; Antigravity/OpenCode attachments are honest stubs; malformed/thinking-only/`message:null` JSONL lines are dropped to `null` and filtered, never surfaced as a 500.
- **The paging contract is clean and well-named.** `before` (exclusive raw/cursor bound) vs `after` (forward), `untilRealUserMessage` with its 300-entry safety cap, and the "newest-first request / oldest-first response" discipline make the JS `pollHistory`/`loadHistoryPage` code readable and its intent explicit. The OpenCode reactive-bind heal and the OpenCode `find_session_for_workdir` dedup are careful.
- **The `$untilRealUserMessage` triage is correct across backends** (Claude `is_real_user_message` excludes tool_result-as-user; Antigravity stops on `USER_INPUT`; OpenCode stops on `role==='user'` because it has no tool_result-under-user wrinkle) — the divergent predicates are right per format, and each is documented.
- **The comment style ("found live", "verified live", with the date and the reason)** is consistent and genuinely explains the non-obvious behaviour (e.g. the iOS stale-cache, the double-quote-on-download, the `_` word-boundary italic guard, the icon-collision-safe NUL tokens). This is the exact convention the repo asks for.

---

## Cross-cutting observations (described, not solved — other subsystems)

- **`escapeHtml()` (public/js/common.js:359) does not escape `'`/`"`** (DOM `textContent→innerHTML` trick). It is used pervasively and is the shared XSS guard underpinning `markdown.js` (Finding 2). Today it is safe only because every injection site wraps it in double-quoted attributes, which is a fragile implicit invariant. This is `common.js` territory (outside owned paths) — worth a deliberate, separate hardening and a co-ordinated review, not a fix here. Touches: cross-cutting JS; could affect *every* page.
- **`tool_part_to_blocks` throws JSON into the block text** (`OpenCodeTranscriptService.php:131`: `$toolName . ': ' . json_encode($input)`). It is escaped downstream, so no XSS, but it surfaces unformatted JSON as a transcript line when none of the known field keys apply. Ascribable to `session-view` (it is owned here), but the "readable summary" shape is a `TranscriptView`/`tool_call_entry_summary` concern; noted here to keep the priority honest (Low).
- **`find_pending_question` (OpenCode) is only exercised indirectly** via `SessionService`; the staleness guard (newest question authoritative) lacks a direct unit test in `test_opencode_transcript.php`. This is `session-status-state`/`prompt-interaction` territory (the pending-question value feeds blocked state), so the test gap is cross-subsystem — flagged, not fixed here.
- **`Message`-to-`entry` `role` fallback for OpenCode** (`message_to_entry:195`, `'GENERIC'` type for a non-user/non-assistant role) produces `{type:'GENERIC', role:'GENERIC?'}` with the renderer's `default => ucfirst($role)` label. Unusual roles are currently tolerated gracefully, which is fine, but the type/role vocabulary is not documented beyond the three known roles — a `research-more` item if OpenCode grows more role variants.
- **`read_transcript_page` (OpenCode) routes `before`/`after` through the `$renderable` index while `line` is raw** (Finding 1's root cause) — the same "two coordinate systems" pattern exists, more mildly, in `read_transcript_page`'s `next_before` path. Fixing Finding 1 resolves both by unifying on renderable position.
