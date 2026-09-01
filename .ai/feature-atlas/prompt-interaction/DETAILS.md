---
id: prompt-interaction
name: Blocked-prompt detection & answering, live send/mode/model/escape, permission bridge
owned_paths:
  - host-agent/lib/Services/PromptParser.php
  - host-agent/lib/Services/AntigravityPromptParser.php
  - host-agent/lib/Services/OpenCodePromptParser.php
  - host-agent/lib/Services/PromptInteractionService.php
  - host-agent/lib/Services/PermissionMode.php
  - host-agent/lib/Services/SelectableModel.php
  - host-agent/lib/Services/AntigravitySelectableModel.php
  - host-agent/lib/Services/PermissionStore.php
  - host-agent/lib/Services/OpenCodeQuestionService.php
  - host-agent/opencode-plugins/csm-permissions.js
  - host-agent/opencode_diagnose.php
  - src/lib/Views/BlockedPromptView.php
  - src/partials/blocked-prompt/*      (all files)
  - tests/test_antigravity_prompt_parser.php
  - tests/test_antigravity_model_switch.php
  - tests/test_opencode_prompt_parser.php
  - tests/test_opencode_question_service.php
  - tests/test_opencode_permission_store.php
  - tests/fixtures/antigravity_permission_prompt_pane.txt
  - tests/fixtures/opencode_always_allow_prompt_pane.txt
  - tests/fixtures/opencode_permission_pane_with_scrollback.txt
  - tests/fixtures/opencode_permission_prompt_pane.txt
  - tests/fixtures/opencode_question_stub.php
  - tests/fixtures/fake_antigravity_picker.php
last_scanned_commit: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# prompt-interaction — DETAILS

## 1. Identity

- **id:** `prompt-interaction`
- **name:** Blocked-prompt detection & answering, live send/mode/model/escape, permission bridge

The subsystem that answers "what is this session asking me for right now?" across all three agents (Claude Code, Antigravity, OpenCode), renders it, and lets a human respond over tmux/HTTP — plus the mode/model vocabularies and the OpenCode plugin permission bridge. It is the detection + answering + rendering + vocab half of the app's prompt story; it does NOT own session lifecycle (creation/kill), the agent adapters, or the pane-state store itself (those live in `session-core`/`agent-abstraction`/`session-status-state`).

## 2. Ownership boundary

**In scope (owned):** the prompt parsers (Claude pane, Antigravity pane, OpenCode modal), the interaction service (answer/send/escape/mode/model), the permission mode + selectable model vocabularies, the OpenCode permission store + question service, the OpenCode plugin permission bridge, the OpenCode diagnose CLI, the blocked-prompt view + partials, and the associated tests/fixtures.

**Out of scope (neighboring subsystems):**
- **`session-core`** — owns `SessionService::build_session_entry()` (which *consumes* this subsystem's parsers to build the prompt for the UI), `SessionLifecycleService` (create/kill/resume), `SessionStatusStore` (the hook-fed status file that this subsystem *reads*) — see `host-agent/lib/Services/SessionService.php:166-233`.
- **`agent-abstraction`** — owns `Agents\ClaudeCodeAdapter`/`AntigravityAdapter`, which reference `PermissionMode`/`SelectableModel` vocabularies as a cross-reference but do not own them (see `## Co-owned / cross-subsystem`).
- **`session-view`** — owns the physical controller (`SessionController`) and JS (`session.js`/`sidebar.js`/`common.js`) files that this subsystem's endpoints and rendering are wired into. This subsystem **co-reports** the prompt-answer/send/mode/model/escape controller methods and the matching JS blocks.
- **`session-status-state`** — owns `PendingToolStore`/`SessionStatusStore`/`SidecarStore`, which this subsystem reads and writes (`host-agent/lib/Stores/*`).
- **Push/notifications** — `NotificationContentBuilder`/`PushDeliveryService` reuse `PromptParser::format_pending_tool_input()`/`build_options_from_permission_suggestions()` but own their own delivery logic.

## 3. Key implementation files

- `host-agent/lib/Services/PromptParser.php` — The core Claude-Code pane-scraping prompt parser; also hosts the pure logic that turns a hook-fed permission payload into the canonical prompt shape, formats pending tool input, and builds the multi-question tab-bar key sequence. The biggest single logic file in the subsystem.
- `host-agent/lib/Services/PromptInteractionService.php` — The execution half: sends the chosen option/text/multi-question sequence/mode/model/escape to the pane, with the re-validate-then-send discipline. Route dispatch lands here.
- `host-agent/lib/Services/AntigravityPromptParser.php` — Antigravity's substring-matching prompt parser (no hook exists for it), same canonical shape.
- `host-agent/lib/Services/OpenCodePromptParser.php` — OpenCode's structural, bottom-anchored modal parser (permission/question), guarded by `is_blocked()`.
- `host-agent/lib/Services/OpenCodeQuestionService.php` — OpenCode serve-API question client (`GET /question` / `POST /question/{id}/reply`).
- `host-agent/lib/Services/PermissionStore.php` — JSON-file bridge store for OpenCode pending permissions + answer intents.
- `host-agent/lib/Services/PermissionMode.php`, `SelectableModel.php`, `AntigravitySelectableModel.php` — The closed mode/model vocabularies + translation to/from Claude/Antigravity representations.
- `host-agent/opencode-plugins/csm-permissions.js` — The OpenCode plugin that records `permission.ask` into the store and applies staged intents.
- `host-agent/opencode_diagnose.php` — Live multi-source diagnostic CLI for OpenCode blocked state.
- `src/lib/Views/BlockedPromptView.php` + `src/partials/blocked-prompt/*` — The shared "waiting on input" rendering (dashboard rows + session detail), including the answer-options/multi-question forms.

## 4. Public interfaces & contracts

### `PromptParser` (host-agent/lib/Services/PromptParser.php)

- `clean_pane_title(string $title): ?string` (`:61`) — strips a leading spinner glyph; null for empty/spinner-only.
- `is_decorative_pane_line(string $line): bool` (`:75`) — box-drawing/rule-only line.
- `parse_blocking_prompt(string $paneContent): ?array` (`:100`) — the canonical Claude pane-scrape. Returns `null` when no `❯` choice cursor found. On a prompt:
  `{question:string, context:string, options: array<int,{number:int,label:string}>, multi_question:bool, is_folder_trust:bool}`. `multi_question` is true when a `← … → Submit` tab bar is present; `is_folder_trust` when an option label contains "exit" (the trust dialog).
- `detect_blocking_prompt(string $paneContent): ?string` (`:344`) — convenience: question text or null.
- `format_pending_tool_input(string $toolName, array $toolInput): ?string` (`:358`) — renders a PreToolUse payload into a human preview (Bash/Write/Edit/other-tool-JSON); null when not renderable.
- `permission_suggestion_option_label(array $suggestion): ?string` (`:413`) — exact Claude Code option wording for one `permission_suggestion` (setMode/addDirectories/addRules); null for unknown.
- `build_options_from_permission_suggestions(array $suggestions): array` (`:452`) — `{number,label}[]` "Yes / <suggestion> / No"; only the most specific non-setMode suggestion gets its own option.
- `build_prompt_from_hook_status(?array $blocked): ?array` (`:509`) — builds the full canonical prompt from `SessionStatusStore`'s `blocked` field (never the pane). **Never called for AskUserQuestion.** Returns null if `blocked` is null OR tool_name/tool_input missing. Question is always "Do you want to proceed?"; adds `tool_name`/`tool_input` keys.
- `build_multi_question_key_sequence(array $questions, array $answers): ?array` (`:592`) — the flat `{type: digit|right|text|enter, value?}` sequence to drive Claude Code's tab bar. Returns **null** on any structural mismatch (wrong count, out-of-range index, multiSelect-free-text, empty text) — callers must treat null as a rejected request, never a partial send. Scoped to 2+ questions only.
- `augment_prompt_with_pending_tool(array $prompt, ?array $pendingTool): array` (`:675`) — merges the untruncated pending-tool input into a pane-scraped prompt; leaves pane context untouched when no matching pending tool or for AskUserQuestion; always exposes `tool_name`/`tool_input` keys when a pending tool is present. Cross-checks the pane's `● ToolName(...)` marker against the pending tool.

### `PromptInteractionService` (host-agent/lib/Services/PromptInteractionService.php)

All return `array{ok:bool, message:string}`. All reject with `{ok:false, message:'Rejected: not a currently active managed session'}` unless the name is in `TmuxService::list_tracked_tmux_sessions()`.

- `answer_prompt(string $name, int $option): array` (`:49`) — re-parses the pane (or serve-API/pane for opencode) fresh and reject unless `$option` is still offered. Branches: opencode `question` → POST via `OpenCodeQuestionService::answer`; opencode `permission` → arrow-Right(s)+Enter in pane; otherwise send digit (+ separate Enter only when `multi_question` empty). Clears `PendingToolStore` + `SessionStatusStore::update_status(...'working', blocked:null)` on success (single/two-shot only). Constant `TMUX_KEY_STEP_DELAY_USEC = 300000` (`:33`).
- `answer_prompt_with_text(string $name, int $option, string $text): array` (`:227`) — free-text "Type something." answer; rejects empty text; stages text via a uniquely-named tmux buffer (`csm-` + random) then paste-buffer + Enter.
- `answer_multi_question(string $name, array $answers): array` (`:327`) — re-derives `$questions` from `SessionStatusStore` (never trusts the request), rejects unless `status==='blocked'` and `tool_name==='AskUserQuestion'` and `count(questions)>=2`; one live pane check that the first question is still showing; then `build_multi_question_key_sequence` + send the whole sequence. Returns `{ok:false, message:'Rejected: this session is not currently showing a multi-question prompt'}` etc.
- `send_escape(string $name): array` (`:388`) — sends `Escape`. No pane-content guard (safe no-op).
- `set_mode(string $name, string $targetMode): array` (`:416`) — rejects unknown mode; reads current mode via `PermissionMode::parse_current_mode` (rejects if not readable); computes relative BTab steps; then `SessionStatusStore::update_status(...['mode'=>$targetMode])` — **required** so the next poll's cached status doesn't snap the dropdown back (found live 2026-08-23).
- `set_model(string $name, string $targetModel): array` (`:480`) — session-only model switch for Claude. Rejects while blocked. Drives the real `/model` picker: `/model`+Enter, `Up`×N to row 1, `Down`×(row-1), `s` to confirm session-only.
- `set_antigravity_model(string $name, string $targetModel): array` (`:572`) — Antigravity model switch. Rejects if not an antigravity session or if status is `working`. **Globally overwrites the account-wide default** (no session-only key in Antigravity). Uses `move_antigravity_picker_cursor()` (`:628`) which re-captures after EACH press because Antigravity's picker silently drops rapid arrow keys (found live 2026-08-24) — a blind fixed-count burst is NOT safe here, unlike `set_mode()/set_model()`.
- `send_message(string $name, string $text, array $attachmentPaths = []): array` (`:664`) — paste-buffer send (not raw send-keys) so embedded newlines paste as one unit; appends `[Attached: <path>]` lines; `$text` may be empty if at least one attachment present; trailing `TMUX_KEY_STEP_DELAY_USEC` gap before Enter.

### `AntigravityPromptParser` (host-agent/lib/Services/AntigravityPromptParser.php)

- `parse_blocking_prompt(string $paneContent): ?array` (`:47`) — matches Antigravity's "Do you want to proceed?" + numbered options (wrapped labels joined). Returns the SAME canonical `{question, context, options, multi_question, is_folder_trust}` shape; `multi_question`/`is_folder_trust` always false. Returns null unless that question line + at least one numbered option is present.

### `OpenCodePromptParser` (host-agent/lib/Services/OpenCodePromptParser.php)

- `is_blocked(string $paneContent): bool` (`:49`) — structural bottom-anchored check: the last content line above the "• OpenCode x.y.z" footer is one of `FOOTER_MARKERS` (`enter confirm`/`enter submit`/`esc dismiss`). Never inspects scrollback.
- `parse_blocking_prompt(string $paneContent): ?array` (`:57`) — only if `is_blocked()`. Returns `{question, context, options, multi_question, tool_name: 'permission'|'question'}` (no `is_folder_trust` key). Dispatches by footer hint.

### `AntigravitySelectableModel` (host-agent/lib/Services/AntigravitySelectableModel.php)

- `parse_current_model(string $paneContent): ?string` (`:60`) — reads the model off the live pane footer ("<label> · <effort>"); null if no recognized label. Keys are the 7 `PICKER_OPTIONS` keys.

### `PermissionStore` (host-agent/lib/Services/PermissionStore.php)

- `write_pending_permission(string $sessionId, array $permission): void` (`:56`) — seeds/refreshes the `permission` key.
- `read_pending_permission(string $sessionId): ?array` (`:72`) — the stored `permission` or null.
- `find_by_session_id(string $sessionId): ?string` (`:88`) — ses_* → CSM tmux session name via `SidecarStore::find_by_claude_session_id`.
- `write_answer_intent(string $sessionId, string $status): void` (`:94`) — sets `intent` to `'allow'`/`'deny'` or null.
- `consume_answer_intent(string $sessionId): ?string` (`:108`) — returns and clears the intent.
- `delete_permission(string $sessionId): void` (`:121`) — unlink record.
- All session ids validated against `/^ses_[A-Za-z0-9]+$/`; `file_for()` throws `\InvalidArgumentException` on a bad id (other methods return null/void instead). Record shape `{permission:?array, intent:?string}`, atomic tmp+rename writes, 0600.

### `OpenCodeQuestionService` (host-agent/lib/Services/OpenCodeQuestionService.php)

- `pending_question(string $sessionId): ?array` (`:38`) — `GET /question`, filters by `sessionID`, returns `{requestID, questions[]}` or null (also null when orphaned — server resolved but TUI shows it).
- `answer(string $sessionId, array $labels): array` (`:84`) — `POST /question/{id}/reply` with `{answers:[[label],...]}`; `{ok:true, message:'Question answered'}` only if the response JSON is literally `true`, else `{ok:false, ...}` (so an orphaned/stale answer surfaces as an error).
- `to_prompt(array $pending): array` (`:137`) — first-question canonical `{question, context, options, multi_question, tool_name:'question'}`; collapses extra questions into context as "(+ N more question(s))".

### `BlockedPromptView` (src/lib/Views/BlockedPromptView.php)

All static, render through `View::render('blocked-prompt/<partial>')`, return HTML string.

- `blocked_prompt_panel_html(array $session): string` (`:27`) — plain tip-only amber panel (dashboard folder-trust rows).
- `collapsible_summary(string $text): string` (`:46`) — first line ≤80 chars + "…".
- `render_collapsible_block(string $rawText, string $borderClass, string $textClass, string $prefix): string` (`:72`) — `<details>` for non-trivial, plain row for trivial.
- `render_full_block(...)` (`:114`) — always-visible scrollable box (no own toggle).
- `render_collapsible_markdown_block(...)` (`:147`) — markdown-rendered collapsible; raw source kept in hidden `.copy-source`.
- `blocked_prompt_options_html(array $session, string $csrfToken): string` (`:180`) — numbered-option Approve/Deny buttons; the "Type something." option becomes a reveal button + paired free-text reply box (`onclick` wired client-side).
- `blocked_multi_question_html(array $session, string $csrfToken): string` (`:221`) — the "answer every question at once" form, built from the full hook-fed `prompt_questions` set.
- `blocked_prompt_rich_html(array $session, string $csrfToken, bool $includeLastMessage = false): string` (`:254`) — the full dashboard/detail treatment; switches to the multi-question form when `prompt_questions` is set, else pending-context entry + rich card.
- `pending_context_entry_html(string $promptContext): string` (`:308`) — the separate "Awaiting approval" context entry.
- `last_message_preview_html(?array $entry, string $extraClass = ''): string` (`:328`) — compact role-prefixed one-line message preview.

## 5. Major call sites

**Upstream (who calls into this subsystem):**

- `host-agent/lib/Sessions.php:128-154` — the `dispatch_action()` switch routes `answer_prompt`/`answer_prompt_with_text`/`answer_multi_question`/`send_escape`/`send_message`/`set_mode`/`set_model`/`set_antigravity_model` to `PromptInteractionService`. This is the agent-protocol entry point (one JSON request → one response).
- `host-agent/lib/Services/SessionService.php:166-233` — `build_session_entry()` calls `OpenCodePromptParser::parse_blocking_prompt` (`:166`), `AntigravityPromptParser::parse_blocking_prompt` (`:206`), `PromptParser::parse_blocking_prompt` (`:216`), `PromptParser::augment_prompt_with_pending_tool` (`:219`), `PromptParser::build_prompt_from_hook_status` (`:199`, `:222`), and reads `OpenCodeQuestionService::pending_question`/`to_prompt` (`:178-182`). Also `SelectableModel::family_from_raw_model` (`:271`) and `AntigravitySelectableModel::parse_current_model` (`:277`) for the live current model.
- `src/lib/Controllers/SessionController.php` (co-owned partition) — thin AJAX endpoints that forward to the agent actions (see §Co-owned).
- `public/js/session.js` / `common.js` / `index.js` / `sidebar.js` (co-owned partition) — the client that POSTs the answers/sends (see §Co-owned).
- `host-agent/lib/Agents/ClaudeCodeAdapter.php:56,79` and `AgentAdapter.php:92-94` — reference `PermissionMode::HOOK_PERMISSION_MODE_MAP` (the map's keys/values) to translate spawn `--permission-mode`; `AntigravityAdapter.php:125` notes it hasn't added equivalent normalize logic yet.
- `host-agent/lib/Stores/SessionStatusStore.php:82-83` — doc-references `PermissionMode::normalize_hook_permission_mode()` for the `mode` key it stores.
- `host-agent/lib/Services/NotificationContentBuilder.php:127` and `PushDeliveryService.php:226` — reuse `PromptParser::format_pending_tool_input()` and `PromptParser::build_options_from_permission_suggestions()` for push bodies.
- `host-agent/lib/Services/TmuxService.php:148,200` and `:158` — call `PromptParser::clean_pane_title()`/`detect_blocking_prompt()` (co-agent-pane-title duties, but the helpers live here).

**Downstream (what this subsystem calls):**

- `HostAgent\Stores\PendingToolStore` (`pending tool read/delete`), `HostAgent\Stores\SessionStatusStore` (`read_status`/`update_status`), `HostAgent\Stores\SidecarStore` (`read_sidecar`/`find_by_claude_session_id`) — `use` statements at `PromptInteractionService.php:7-9`, `PermissionStore.php:7`.
- `TmuxService` (`tmux_capture_pane`, `tmux_run`, `list_tracked_tmux_sessions`) — the pane/keystroke primitive.
- `Config` (`opencode_server_url()` at `Config.php:245`, `opencode_permission_dir()` at `Config.php:105`) — via `OpenCodeQuestionService`/`PermissionStore`.
- `ProcessRunner::run_process()` — the curl calls for the serve API (`OpenCodeQuestionService.php:41,105`).

## 6. Tests

- `tests/test_antigravity_prompt_parser.php` — happy + sad: real captured pane parses (question/context/4 options incl. wrapped-continuation join, never `multi_question`/`is_folder_trust`); null for non-prompt and question-with-no-options; end-to-end `answer_prompt` routes an antigravity session through `AntigravityPromptParser` (valid option ok, out-of-range rejected). Fixture: `antigravity_permission_prompt_pane.txt`.
- `tests/test_antigravity_model_switch.php` — happy + sad: `set_antigravity_model` converges from already-on-target, below-target (up-then-down), and row-1→row-7; rejects an unknown model key without touching the pane; rejects a `working` session. Runs against `fake_antigravity_picker.php` (deterministic, key-drop-recovery only verified live).
- `tests/test_opencode_prompt_parser.php` — happy + sad: `is_blocked` true for permission/always-allow/scrollback-polluted panes, false for idle/empty; `parse_blocking_prompt` resolves permission modal options (Allow once/Allow always/Reject, Confirm/Cancel), keeps the anchored (not scrollback) dialog as the question, null when not structurally blocked. Fixtures: `opencode_permission_prompt_pane.txt`, `opencode_always_allow_prompt_pane.txt`, `opencode_permission_pane_with_scrollback.txt`.
- `tests/test_opencode_question_service.php` — happy + sad: `pending_question` finds the matching session's question, null for another session; `to_prompt` canonical shape; `answer` POSTs the chosen label (asserts requestID + `answers:[[label]]`), rejects when no live question. Runs against a `php -S` stub (`opencode_question_stub.php`); never touches the real serve or tmux.
- `tests/test_opencode_permission_store.php` — happy + sad: `read_pending_permission` null for missing/invalid id; write+read round-trip; `write_answer_intent` + `consume_answer_intent` allow/deny/null; `delete_permission`; `find_by_session_id` resolves a bound sidecar and null for unbound. Pure JSON-file isolation.

Note: `PromptParser::parse_blocking_prompt()`/`build_multi_question_key_sequence()` themselves have no dedicated unit test filed in this subsystem — their pane-scrape behavior is exercised indirectly via `test_antigravity_prompt_parser.php` (the shared canonical-shape contract) and covered live (see the repo's `tests/test_sessions_lifecycle.php`, other subsystems). Flag if a dedicated fixture for the Claude multi-question tab bar is wanted.

## 7. Dependencies

**Helpers used (intra-subsystem):**
- `PromptInteractionService` uses `PromptParser`/`AntigravityPromptParser`/`OpenCodePromptParser` for detection and `PermissionMode`/`SelectableModel`/`AntigravitySelectableModel` for the vocabularies.
- `OpenCodePromptParser` uses `PromptParser::BLOCKING_PROMPT_CONTEXT_WINDOW` for the context-window cap.

**Other subsystems used:**
- `session-status-state`: `PendingToolStore`, `SessionStatusStore`, `SidecarStore` (read/write of status + pending tool + sidecar agent/session-id).
- `session-core`: `TmuxService` (pane capture + send-keys/curl primitives), `Config` (opencode server URL + permission dir), `ProcessRunner` (curl).
- `agent-abstraction`: `ClaudeCodeAdapter`/`AgentAdapter` consume `PermissionMode::HOOK_PERMISSION_MODE_MAP` (cross-reference, not owned here).

**External:**
- `minishlink/web-push` — not directly; the permission bridge is plain files + curl.
- Node.js builtin `fs`/`path`/`os` (the plugin `csm-permissions.js`); `curl` CLI (serve-API calls + `opencode_diagnose.php`).

**Reverse (what depends on this subsystem):** listed in §Major call sites — the agent dispatcher (`Sessions.php`), `SessionService::build_session_entry`, the controllers/JS client, `NotificationContentBuilder`/`PushDeliveryService`, `TmuxService`, `ClaudeCodeAdapter`/`AgentAdapter`, `SessionStatusStore`.

## 8. Data & schema

No DB. State is file-based:

- **Canonical prompt shape** (from `parse_blocking_prompt`/`build_prompt_from_hook_status`/`to_prompt`): `{question:string, context:string, options: array<int,{number:int,label:string}>, multi_question:bool, is_folder_trust:bool}` with optional `tool_name:string`, `tool_input:array`. OpenCode variants swap `is_folder_trust` for `tool_name` only.
- **SessionStatusStore `blocked` record** (`build_prompt_from_hook_status`): `{tool_name:?string, tool_input:?array, permission_suggestions?:array}` — `tool_input['questions']` (from `PermissionRequest` payload) is the full multi-question set `PromptInteractionService::answer_multi_question()` reads.
- **`AnswerIntent`/`PermissionStore` record** (one JSON file per `ses_<id>` under `Config::opencode_permission_dir()`): `{permission:?array, intent:'allow'|'deny'|null}`. `clearRecord`/`delete_permission` unlink it; `consume_answer_intent` nulls `intent`. The plugin writes `permission` (canonical Permission shape: `{id, type, sessionID, pattern, title, metadata, time}`), the host-agent writes/clears `intent`. Atomic tmp+rename, 0600, dir 0700.
- **`OpenCodeQuestionService` request/response:** `GET /question` → `[{id:'queue_*', sessionID, questions:[{question, header, options:[{label, description}], multiple, custom}], tool:{messageID, callID}}]`; `POST /question/{id}/reply` body `{answers:[[label],...]}`, success response is literal `true`.
- **Enums/constants:**
  - `PermissionMode::CLAUDE_CODE_MODE_STATUS_PHRASES` (`:33`): `manual/accept edits/plan/auto` → the exact status-line phrase each (accept edits is its own inconsistency: "accept edits on").
  - `PermissionMode::HOOK_PERMISSION_MODE_MAP` (`:50`): hook `permission_mode` enum → app mode; `default→manual`, `acceptEdits→accept edits`, `plan→plan`, `auto→auto`.
  - `SelectableModel::PICKER_OPTIONS` (`:28`): Claude's 5-row order `default/sonnet/fable/opus/haiku`; `family_from_raw_model()` (`:46`) maps `claude-<family>` raw IDs → key, never "default".
  - `AntigravitySelectableModel::PICKER_OPTIONS` (`:35`): 7 rows (gemini-3.7/3.6/3.5-flash, gemini-3.1-pro, claude-sonnet-4.6-thinking, claude-opus-4.6-thinking, gpt-oss-120b-medium).
  - `PromptParser::BLOCKING_PROMPT_CONTEXT_WINDOW = 15` (`:26`).
  - `PromptInteractionService::TMUX_KEY_STEP_DELAY_USEC = 300000` (`:33`).
- **`opencode_diagnose.php` output:** JSON `{session_name, sidecar, session_id, pane:{is_blocked, parsed_tool, parsed_question, parsed_options}, permission_api:{full_count, for_session, raw}, question_api:{...}, session_api, captured_at}`.

## 9. Conventions & quirks

- **Multi-question tab-bar sequence (`build_multi_question_key_sequence`)** — the flat action sequence that drives Claude Code's real `←/← ☐ Q1 … ✔ Submit →` tab bar without reading the current tab: single-select real option = digit alone (auto-advances); single-select free-text = digit(`optionCount+1`) + text + Enter; multiSelect = each digit toggles (no advance) then an explicit Right; after the LAST question always a final digit `1` ("Submit answers") on the Review tab. `digit`/`right`/`text`/`enter` sends use `send-keys`, and a trailing 300ms gap after every step. Only valid for 2+ questions.
- **AskUserQuestion single-vs-multi structural carve-out** — a SINGLE-question AskUserQuestion has no tab bar at all, so it uses the normal pane-scraped `answer_prompt()`/`answer_prompt_with_text()` path; `build_prompt_from_hook_status()` is never called for `AskUserQuestion` by callers, and `answer_multi_question()` rejects any `count(questions) < 2`. `build_session_entry()` likewise only pane-scrapes for AskUserQuestion (the one prompt whose live pane is the only source of the *currently showing* tab — see `SessionService.php:207-216`).
- **`csm-permissions.js` bridge** — pure-observe except when an intent is staged; `permission.ask` records the pending permission and replies `ask` (TUI) unless an intent exists, in which case it applies the intent in-process (`output.status = record.intent`) and clears the record. `permission.asked` event on the bus is the authoritative "blocked" signal (the `permission.ask` HOOK is dormant in opencode 1.18.21). Heartbeat file `_csm-heartbeat.txt` proves the plugin fires. Installed globally to `~/.config/opencode/plugins/`.
- **Dense docblocks record live findings** — nearly every "don't over-engineer" assumption is backed by a real capture (the `◐` vs braille spinner, the trust-dialog `?` mid-line, the multi-answer review tab, Antigravity's arrow-key drop, the 300ms keygap). Read before changing.
- **Re-validate-then-send discipline** — every state-changing action re-derives live state (fresh pane capture or serve-API) and rejects stale calls; never trusts client input for the source of truth (answers re-derived from `SessionStatusStore`, the model/mode re-checked against fresh pane).
- **tmux buffer naming** — `answer_prompt_with_text()`/`send_message()` use a uniquely-named `csm-<hex>` buffer (not tmux's shared default) because each request is its own OS process (systemd socket activation) and concurrent set-buffer/paste-buffer pairings could otherwise cross-contaminate (found live 2026-08-14); `-d` deletes on paste, with a best-effort `delete-buffer` on failure.
- **`proc_open(..., array)` only** — no shell-string tmux/curl invocations; the paste/send-keys arrays keep metacharacters inert. The plugin's Node side uses `node:fs` primitives only.

## Co-owned / cross-subsystem

Files physically owned elsewhere but whose feature-specific lines this subsystem REPORTS (and vice versa).

**`src/lib/Controllers/SessionController.php`** (physical owner `session-view`) — the prompt-answer/send/mode/model/escape controller methods:
- `send()` `:297-306` — POST `/session_send.php`, forwards `send_message`.
- `setMode()` `:313-321` — POST `/session_mode.php`, forwards `set_mode`.
- `setModel()` `:331-339` — POST `/session_model.php`, forwards `set_model`.
- `setAntigravityModel()` `:351-359` — POST `/session_antigravity_model.php`, forwards `set_antigravity_model`.
- `escape()` `:366-373` — POST `/session_escape.php`, forwards `send_escape`.
- `answerPrompt()` `:457-474` — POST `/answer_prompt.php`; dispatches `answer_prompt_with_text` when a `text` field is present, else `answer_prompt`.
- `answerMultiQuestion()` `:486-495` — POST `/answer_multi_question.php`; JSON-decodes `answers`, forwards `answer_multi_question`.
- Route wiring: `src/routes.php:52-65` maps the eight `/session_*.php`/`/answer_*.php` paths to these methods. All (except `detail()`/the full-page renders) go through `Controller::require_post_json()` (`Controller.php:31`).

**`public/js/session.js`** (physical owner `session-view`) — the prompt-answer/send/mode/model/escape JS blocks:
- `renderMultiQuestionFormHtml()` `:1199-1245` — mirrors `BlockedPromptView::blocked_multi_question_html()` (multi-question form); free-text offered per single-select question only.
- `renderMultiQuestionCardHtml()` `:1260-1265`, `renderOptionsCardHtml()` `:1274-1294`, `renderPromptOptionsFormHtml()` `:1303-1349` — the blocked-prompt card + numbered-option buttons + paired free-text reply box.
- `renderBlockedSection()` `:1363` onward (incl. freetext-draft/focus restoration `:1391-1465`) — the poll-time blocked-prompt renderer.
- `submitFreetextReply()` `:1640-1691`, `submitMultiQuestionAnswers()` `:1699-1738`, delegated click `:1740-1767`, change `:1773-1775`, keydown `:1781-1786`.
- Compose send: `sendComposedMessage()` `:2297` (POST `/session_send.php` via fetch with `keepalive:true`).
- Mode select handler `:2535-2551` (POST `/session_mode.php`); model select handler `:2566-2582` (POST `/session_model.php`); antigravity model select handler `:2598-2614` (POST `/session_antigravity_model.php`).

**`public/js/sidebar.js`** (physical owner `session-status-state`) — only *reads* the prompt state so the sidebar pane list can show a "waiting on input" sub-label from `s.blocked_reason` (`:127`, `:283`, `:298-299`); it does not render answer buttons or the multi-question form (those stay in session.js/index.js + common.js).

**`public/js/common.js`** (physical owner `session-view`) — the shared prompt-answer primitives this subsystem's UI relies on:
- `postAnswerPrompt()` `:208-...` (POST `/answer_prompt.php`); `postAnswerMultiQuestion()` `:224-...` (POST `/answer_multi_question.php`); `collectMultiQuestionAnswers()` `:260-...` (walks a `.multi-question-wrapper`, strips newlines to spaces, returns `{answers, summaryParts}` or null if incomplete); `handleMultiQuestionFreetextToggle()` (`:317-...`).

**`public/js/index.js`** (physical owner `session-view`) — dashboard-row copy of the same answer-prompt/freetext/multi-question handlers (`:75-175`) delegating to the common.js helpers.

**Cross-reference vocabularies (owned here, referenced elsewhere):**
- `PermissionMode::HOOK_PERMISSION_MODE_MAP` — used by `host-agent/lib/Agents/ClaudeCodeAdapter.php:56,79` (spawn `--permission-mode` translation, `permission_mode_map()`) and `AgentAdapter.php:92-94`.
- `PermissionMode::normalize_hook_permission_mode()` — referenced by `host-agent/lib/Stores/SessionStatusStore.php:82-83`.
- `SelectableModel::PICKER_OPTIONS`/`family_from_raw_model()` and `AntigravitySelectableModel::parse_current_model()` — consumed by `SessionService::build_session_entry()` (`SessionService.php:267-277`).
- `TranscriptView::MODE_OPTIONS` (physical owner `session-view`) — the UI's mode dropdown labels are the same `manual/accept edits/plan/auto` keys this subsystem's `PermissionMode` defines; noted as the cross-reference in `PermissionMode.php:47`.
