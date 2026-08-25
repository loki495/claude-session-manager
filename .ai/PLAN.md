# PLAN.md — OpenCode TUI agent integration

Goal: Add OpenCode TUI (`opencode` binary, v1.18.21 on this machine) as a third agent in Claude Session Manager, via the existing `AgentAdapter` abstraction (same pattern as Antigravity — see `docs/antigravity-adapter-plan.md` for the established phases). MVP means: spawn, list, see status, read the transcript from the web UI. Non-required features (quota, real interactive permission-prompt answering, resume) can stay broken/deferred, per the same tolerance as Antigravity's MVP.

Worker model: free model (default `opencode/muse-spark-1.2-contributor-free`; verify via `opencode models` before launching). Record exact `provider/model` in STATE.md per task.

---

## Phase 0 — Groundwork + live research (must complete first)

### Task 0.1 — Config + sidecar groundwork

- **ID:** 0.1
- **Objective:** Add `Config::opencode_bin()` (env `OPENCODE_BIN`, same shape as `claude_bin()`/`antigravity_bin()`), document in `host-agent/.env.example`. Ensure `sidecars.agent` column already handles a third value (added in Antigravity Phase 0 — verify, no migration needed if the column already exists and is `TEXT`).
- **Relevant files:** `host-agent/lib/Services/Config.php`, `host-agent/.env.example`, `host-agent/lib/Stores/SqliteDb.php` (verify only, no change expected).
- **Dependencies:** None.
- **Acceptance criteria:**
  - `Config::opencode_bin()` returns `OPENCODE_BIN` env or `''` when unset, matching `claude_bin()`/`antigravity_bin()` exactly.
  - `host-agent/.env.example` documents `OPENCODE_BIN` with `which opencode` hint, same style as `CLAUDE_BIN`/`ANTIGRAVITY_BIN`.
  - `php -l` on changed file passes; `bash tests/run.sh` still passes (no behavior change yet).
- **Implementation notes:** One-line Config method + one-line env.example addition, mirroring `antigravity_bin()` exactly. Do NOT add a generalized `Config::agent_bin(string $agent)` dispatcher (same deviation as Antigravity Phase 0 — each adapter calls its own getter).
- **Status:** done

### Task 0.2 — Live research: spawn identity, storage, plugin system

- **ID:** 0.2
- **Objective:** Live-verify the four open questions in QUESTIONS.md Q1 against a real `opencode` TUI session on this machine, **with a dedicated plugin-system review** (per amendment (a)), and document findings back into QUESTIONS.md and RESULT.md. This is the evidence that shapes every later phase — no later task should assume answers that weren't verified here. **Plugin-first**: the goal is to minimize tmux `capture-pane` parsing by using OpenCode's own plugin events/hooks wherever possible.
- **Relevant files:** `~/.local/share/opencode/opencode.db` (direct SQLite inspection), `opencode --help` / `opencode debug config` / `opencode debug paths`, `https://opencode.ai/docs/plugins/` (primary reference — see amendment (a)), `~/.config/opencode/node_modules/@opencode-ai/plugin/dist/index.d.ts` (Hooks interface: `permission.ask`, `tool.execute.before/after`, `chat.message`, `event`), `~/.config/opencode/node_modules/@opencode-ai/sdk/dist/gen/types.gen.d.ts` (Event types: `permission.updated`, `permission.replied`, `session.status`, `session.idle`, `message.updated`, etc.).
- **Dependencies:** 0.1 (needs `opencode_bin()` to exist, even if trivial).
- **Acceptance criteria:**
  - Spawn a real `opencode` TUI session in an isolated tmux pane (not the real CSM socket/sidecar — use a throwaway socket/dir like Antigravity's `tests/fixtures/fake_agy` pattern, but with the real binary for this one-off research).
  - Answer in writing, with evidence (pane capture, `sqlite3 opencode.db "SELECT ..."`, hook/plugin type grep):
    1. Can a new TUI session be started with a predetermined `ses_*` ID, or must the ID be learned reactively after spawn? If reactive, what's the earliest reliable signal (DB row appearance, plugin `event` with `session.created`/`session.status`, etc.) and its latency?
    2. **Plugin system review (amendment a):** Inventory the available plugin `Hooks` (`permission.ask`, `tool.execute.before/after`, `chat.message`, etc.) and `Event` types (`permission.updated`, `permission.replied`, `session.status` idle/busy/retry, `session.idle`, `message.updated`, `tool.execute.*`) from `index.d.ts` + SDK types + `https://opencode.ai/docs/plugins/`. For each, assess: can it replace a `capture-pane` read for working/idle/blocked detection or transcript tailing? Propose a minimal CSM global plugin (`~/.config/opencode/plugins/csm-*.ts` or `.opencode/plugins/`) that writes session status (working/idle/blocked + permission details) to `SessionStatusStore`/`PendingToolStore` — analogous to Claude Code's 5 hook scripts but via OpenCode's plugin `event` + `permission.ask` hooks. Document the plugin load order (global vs project) and install mechanism (local file vs npm) that would make it reliable.
    3. What does a permission/blocked prompt look like in `tmux capture-pane` (if observable without a real tool call)? This is the fallback only if the plugin path is insufficient — document whether pane parsing can be avoided entirely.
    4. Is direct SQLite reads from `opencode.db` safe while a live TUI holds it (WAL mode, readonly open, busy handling)?
  - QUESTIONS.md Q1 updated with answers; RESULT.md appended with findings; `.ai/PLAN.md` Phase 2-5 notes updated if findings change the approach.
  - No code changes to `host-agent/` or `src/` in this task — research and docs only.
- **Implementation notes:** This task is deliberately small and read-only. Use `tmux -S <tmp-socket> new-session -d -s <name> -- opencode <tmp-workdir>` for the throwaway session, then `sqlite3` and `capture-pane`. For the plugin review, read `https://opencode.ai/docs/plugins/` end-to-end, then grep `plugin/dist/index.d.ts` for the `Hooks` interface and `sdk/dist/gen/types.gen.d.ts` for `Event*` types — the docs page lists the event names but the `.d.ts` files carry the actual payload shapes (e.g. `EventPermissionUpdated.properties: Permission`, `EventSessionStatus.status: idle|busy|retry`). Check `opencode debug config` for how plugins are loaded (global `~/.config/opencode/plugins/` vs project `.opencode/plugins/` load order). Kill the throwaway tmux server when done.
- **Status:** done

---

## Phase 1 — AgentAdapter: OpenCodeAdapter + AgentRegistry

### Task 1.1 — OpenCodeAdapter + registry + picker

- **ID:** 1.1
- **Objective:** Implement `HostAgent\Agents\OpenCodeAdapter` (implements `AgentAdapter`) and register it in `AgentRegistry::ADAPTERS`, plus mirror the picker in `src/lib/Views/PageView::AGENT_OPTIONS` and `src/partials/pages/index.php`.
- **Relevant files:** `host-agent/lib/Agents/OpenCodeAdapter.php` (new), `host-agent/lib/Agents/AgentRegistry.php`, `src/lib/Views/PageView.php`, `src/partials/pages/index.php`, `tests/test_agent_adapter.php` (extend, not weaken).
- **Dependencies:** 0.1, 0.2 (needs Config getter + spawn-identity finding to shape `build_spawn_argv()` correctly).
- **Acceptance criteria:**
  - `OpenCodeAdapter::id()` returns `'opencode'`, `label()` returns `'OpenCode'`, `session_name_prefix()` returns `'oc'` (distinct from `cc`/`ag`).
  - `build_spawn_argv(array $options): array{argv: string[], assigned_id: ?string}` builds `[Config::opencode_bin(), <workdir>]` plus optional `--model <model>` / `--agent <agent>` when provided, whitelisted (non-empty string only). `assigned_id` is `null` if reactive binding is required (expected), or the passed-through ID if pre-assignment turns out to be possible (per 0.2 finding — adapt accordingly, document why).
  - `check_hooks()` / `install_hooks()` are honest stubs for now if no hook surface exists yet (`['ok'=>true,'installed'=>false,'message'=>'OpenCode hooks not yet implemented']` or similar) — do NOT fake success.
  - `permission_mode_map()` returns a map if OpenCode has a mode vocabulary, or `[]` if none observed (document in docblock).
  - `AgentRegistry::ADAPTERS` gains `'opencode' => OpenCodeAdapter::class` — one line, same as Antigravity.
  - `PageView::AGENT_OPTIONS` gains `'opencode' => 'OpenCode'` (view-layer mirror, same reason as Antigravity's docblock).
  - `tests/test_agent_adapter.php` covers the new adapter (spawn argv, registry lookup, picker) — sad path: unknown agent falls back to default, empty model not emitted.
  - `bash tests/run.sh` passes.
- **Implementation notes:** Follow `AntigravityAdapter.php` as the closest structural template (135 lines, same docblock style). Keep `build_spawn_argv()` narrow — only read keys the adapter understands, ignore the rest. Single-select `<select name="agent">` in the New Session form already exists (added for Antigravity); just add the third option, default stays `claude`.
- **Status:** done

---

## Phase 2 — Spawn + identity

### Task 2.1 — Wire spawn through SessionLifecycleService + dashboard

- **ID:** 2.1
- **Objective:** Wire `agent=opencode` from the dashboard through `Sessions.php` dispatch into `SessionLifecycleService::create_cc_session()`'s existing 4th `$agentId` parameter, creating a real `oc-*` tmux session running `opencode` with a sidecar `agent='opencode'`. Reactive ID binding if needed (per 0.2 finding).
- **Relevant files:** `host-agent/lib/Sessions.php` (already passes `request['agent']` — verify, no change if already generic), `host-agent/lib/Services/SessionLifecycleService.php` (verify `known_agent_ids()` whitelist already covers new id; sidecar write already uses `$agent->id()`), `src/lib/Controllers/DashboardController.php` (verify `$_POST['agent']` passthrough is generic — no change if already so).
- **Dependencies:** 1.1
- **Acceptance criteria:**
  - POST `/` with `action=new&agent=opencode&workdir=<path>` creates a tmux session named `oc-YYYYmmdd-HHMMSS`, running `opencode <workdir>`, with sidecar row `agent='opencode'` and correct `workdir`/`spawned_at`.
  - If reactive binding is required (expected): new sidecar starts with `claude_session_id=NULL` and a subsequent poll/hook/plugin binds it to the real `ses_*` once the DB row appears — document the expected latency. If pre-assignment is possible, verify the DB row's `id` matches `assigned_id`.
  - `bash tests/run.sh` passes; extend `tests/fixtures/fake_agy`-style fixture to `tests/fixtures/fake_opencode` (blocks like `cat`, `argv[0]` matches `OPENCODE_BIN`, distinct from `fake_claude`/`fake_agy`) and cover the new agent path in `tests/test_sessions_lifecycle.php` or a new `tests/test_opencode_spawn.php`.
  - Live smoke: spawn a real `oc-*` session, verify `tmux -S <real-socket> list-sessions` shows it, `sqlite3 csm-sessions/sessions.sqlite "SELECT agent, claude_session_id FROM sidecars WHERE name='<name>'"` is correct, kill via dashboard.
- **Implementation notes:** This is mostly verification — `create_cc_session()` is already generic via `AgentRegistry` (whitelist + `$agent->id()` + `$agent->session_name_prefix()` + `$agent->build_spawn_argv()`). **0.2 confirmed reactive binding is required** — `opencode --session <new>` fails (`Session not found`), `opencode /tmp` creates no DB row until first prompt, `opencode run --format json` streams `sessionID` in first `step_start`. Sidecar must start with `claude_session_id=NULL` and bind reactively (poll `opencode.db` for new `session` row whose `directory` matches the spawned workdir — DB-based, not hook-payload-based like Antigravity's `pre_invocation.php`). Also note: TUI `capture-pane` is blank even with `-a` (verified in 0.2 —   `opencode` process runs at 148% CPU but nothing capturable), so status must be plugin-fed, not pane-scraped.
- **Status:** done

---

## Phase 3 — Transcript rendering

### Task 3.1 — OpenCode transcript service + router

- **ID:** 3.1
- **Objective:** Implement `HostAgent\Services\OpenCodeTranscriptService` that reads `opencode.db` (`message`+`part` by `session_id`) and produces the same canonical `{type, role, timestamp, blocks:[{kind, text, ...}]}` shape that `TranscriptService`/`AntigravityTranscriptService` already produce, so `TranscriptView`/`src/partials/transcript/*` render without changes. Wire through `TranscriptRouter`.
- **Relevant files:** `host-agent/lib/Services/OpenCodeTranscriptService.php` (new), `host-agent/lib/Services/TranscriptRouter.php`, `host-agent/lib/Services/TranscriptService.php` (read for canonical shape, no edit), `host-agent/lib/Services/AntigravityTranscriptService.php` (read for second-example pattern), `src/lib/Views/TranscriptView.php` (verify zero changes needed — same as Antigravity Phase 4).
- **Dependencies:** 0.2, 2.1 (needs DB schema finding + a real `ses_*` to test against).
- **Acceptance criteria:**
  - `OpenCodeTranscriptService::find_transcript_path(string $sessionId): ?string` validates `ses_*` shape and checks that a `session` row exists in `opencode.db` (or: returns the DB path itself as the "transcript path" — decide per 0.2's storage finding; document the choice).
  - `read_transcript_page(string $sessionId, int $before, int $limit): array` and `read_transcript_page_since(string $sessionId, int $after): array` query `message` LEFT JOIN `part` where `session_id=?`, ordered by `time_created`, paginated by `before`/`after` cursors, mapped to canonical entries (user message → `{role:user, blocks:[{kind:text}]}`, assistant message → `{role:assistant, blocks:[{kind:text|tool_use|tool_result}]}` with `part.type` discrimination). Stateful `permission-mode` / `CHECKPOINT`-style entries are skipped or rendered as dividers if observed — document.
  - `TranscriptRouter::is_opencode_path()` / dispatch mirrors `is_antigravity_path()` pattern — routes by path/ID shape or by sidecar `agent` field (choose the more reliable per 0.2; path-shape alone may not distinguish if OpenCode reuses `~/.local/share/opencode/` for all sessions).
  - View layer needs zero changes (same as Antigravity Phase 4 — `TranscriptView::entry_color_kind()` already keys off `kind`, not role; `render_transcript_entries_html()` already pairs tool_use/tool_result positionally).
  - Live smoke: spawn a real `oc-*` session, send a prompt that produces a real reply (and optionally a tool call), screenshot `session.php` — text bubble + tool call/result grouped correctly, zero console errors.
  - `bash tests/run.sh` passes; fixture: a canned `opencode.db` SQLite fixture or a JSON export fixture under `tests/fixtures/` (like Antigravity's `transcript_full.jsonl` sample), covering text + tool_use + tool_result + pagination + `before`/`after` cursor semantics.
- **Implementation notes:** OpenCode's DB is WAL mode — open read-only (`sqlite3` `SQLITE_OPEN_READONLY` or PHP `new PDO('sqlite:...', null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY])`) and handle `SQLITE_BUSY` with a short retry. Map `part.type` values empirically (observed so far: `text`, `step-start`; enumerate the rest during 0.2). Prefer `toolSummary`/`toolAction`-style human summaries if present in `part.data` JSON, else hand-format like `TranscriptService` does. Attachments (`read_attachment()`) can be an honest stub.
- **Status:** done

---

## Phase 4 — Session listing + status

### Task 4.1 — Agent-aware session listing + working/idle

- **ID:** 4.1
- **Objective:** Make `SessionService::build_session_entry()` and `SessionDetailService` correctly surface `agent='opencode'` sessions in the dashboard list, with correct title (from `session.title` in opencode.db), last-message preview, and working/idle status (from `SessionStatusStore` if hooks exist, or from DB polling — new `message`/`part` row recency — if not).
- **Relevant files:** `host-agent/lib/Services/SessionService.php` (agent branching, title/last-message cascade), `host-agent/lib/Services/SessionDetailService.php` (live-session paths), `host-agent/lib/Stores/SessionStatusStore.php` (if hook-fed), `host-agent/lib/Services/TranscriptRouter.php` (title/last-message routing).
- **Dependencies:** 2.1, 3.1 (needs spawn + transcript path to resolve title/last-message).
- **Acceptance criteria:**
  - Dashboard lists `oc-*` sessions alongside `cc-*`/`ag-*` with correct `agent`/`agent_label` (`OpenCode`), title (from `opencode.db` `session.title`), last-message preview (last assistant `part.text`), and working/idle indicator.
  - If OpenCode has no hook surface (expected for MVP): `build_session_entry()` does NOT require a `SessionStatusStore` row to show a session — absence means unknown/idle, not hidden. Working/idle can be derived from DB recency or pane liveness, but must not block listing.
  - `bash tests/run.sh` passes.
- **Implementation notes:** Same branching pattern as `SessionService::build_session_entry()`'s existing `if ($agentId === 'antigravity')` block — add `if ($agentId === 'opencode')` with DB-backed title/last-message. Keep the `agent_label` resolution generic (`AgentRegistry::get($agentId)->label()` already handles any id).
- **Status:** done

---

## Phase 5 — Blocked-prompt detection + answering (IO with permissions)

- **ID:** 5
- **Objective:** Detect when an `oc-*` TUI session is blocked on an interactive prompt (permission/tool approval) and surface it in the dashboard's blocked-prompt card with Approve/Deny buttons, plus wire `send_message` for basic IO. **Plugin-first** (amendment a): prefer OpenCode's own plugin `Hooks` (`permission.ask` with `input: Permission` → `output: {status: ask|deny|allow}`, `tool.execute.before/after`) and `Event` stream (`permission.updated`/`permission.replied`, `session.status` idle/busy/retry, `session.idle`, `message.updated`) over `capture-pane` parsing. A CSM global plugin (`~/.config/opencode/plugins/csm-status.ts` or `.opencode/plugins/`) that writes to `SessionStatusStore`/`PendingToolStore` (same pattern as Claude Code's hook scripts, but via `event` + `permission.ask` hooks) is the intended mechanism — `OpenCodePromptParser` pane parsing is the fallback only where plugin coverage is structurally insufficient. Basic `send_message` (tmux send-keys) is included here so Phase 5 completes the "basic IO with prompt and permissions" milestone from amendment (b).
- **Relevant files:** `host-agent/lib/Services/OpenCodePromptParser.php` (new, only if pane fallback is needed), `host-agent/lib/Services/SessionService.php` (blocked-prompt branching, same `if ($agentId === 'opencode')` pattern as Antigravity), `host-agent/lib/Services/PromptInteractionService.php` (answer path — likely tmux send-keys against the live pane even when detection is plugin-fed, same as Antigravity Phase 6's simpler-of-two-options), `src/lib/Views/BlockedPromptView.php` / `public/js/session.js` (render + answer buttons — likely zero changes, same as Antigravity Phase 6), plus the new global plugin file itself (e.g. `opencode-plugins/csm-status.ts` or `~/.config/opencode/plugins/csm-status.ts` — location per 0.2's load-order finding).
- **Dependencies:** 0.2, 4.1 (needs plugin inventory + prompt shape + working listing).
- **Acceptance criteria:** TBD after 0.2 — only prompt shapes confirmed live should be built (same "don't guess" rule as Antigravity Phase 6). Must demonstrate: (1) a real permission prompt is detected via the plugin path (not pane scraping, if the plugin `event` covers it), (2) Approve/Deny from the web UI resolves it (via tmux send-keys or via the plugin's `permission.ask` response path — choose per 0.2, document why), (3) `send_message` delivers a new user prompt to a live `oc-*` session and the reply appears in the transcript.
- **Status:** pending (part of the post-piping IO milestone — runs before quotas per amendment b).

---

## Phase 6 — Quotas (amendment b — first priority after basic IO)

- **ID:** 6
- **Objective:** Surface OpenCode usage quotas/costs in the dashboard and session footer, after basic piping + IO with permissions is working. Source is OpenCode's own `opencode stats` (token usage + cost per session, from `opencode.db` `session.cost`/`tokens_*` columns) and/or `session` cost aggregation — unlike Claude Code (statusLine `rate_limits.*`) and Antigravity (`agy -p "/usage"`), there is no separate rate-limit quota endpoint observed; the closest equivalent is cost/tokens. Confirm live in 0.2/3.1 whether a rate-limit shape exists elsewhere, and adapt.
- **Relevant files:** `host-agent/lib/Services/QuotaService.php` (opencode_quota_state), `host-agent/lib/Services/Config.php` (opencode_db_path already, reused), `host-agent/lib/Stores/GlobalStateStore.php` (not needed for opencode — direct DB read, no polling), `src/lib/Views/QuotaFooterView.php` / `public/js/quota-footer.js` (display — per-agent section in dashboard table, like Antigravity Phase 5's renderDashboardTable()).
- **Dependencies:** 3.1, 5 (needs transcript cost/tokens shape + working IO to verify live numbers).
- **Acceptance criteria:**
  - Real `session.cost` / `tokens_input`/`tokens_output` (and `tokens_reasoning`/`tokens_cache_*` if useful) from `opencode.db` is read and displayed per-session and/or aggregated for the dashboard, with the same `pct`/`resets_at` conventions avoided if not applicable (cost is absolute, not a percentage remaining — display accordingly).
  - `QuotaService::opencode_quota_state(?sessionId)` reads directly from `opencode.db` WAL+readonly (no GlobalStateStore/polling), returns `{cost, tokens_input, tokens_output, ... session_count, captured_at}`. `get_quota(sessionName)` for opencode agent returns that session's cost/tokens; dashboard `get_quota()` includes `agents.opencode` in the map.
  - `bash tests/run.sh` passes; `tests/.env.testing` isolated via temp OPENCODE_DB_PATH for "no data" cases.
- **Implementation notes:** Per amendment (b): this is the first thing after basic IO + permissions — not deferred to polish. Keep it honest: if OpenCode has no windowed quota (only cumulative cost/tokens), show cost/tokens honestly rather than inventing a percentage. Check `opencode stats --help` and `opencode.db` `session` cost/tokens columns live before choosing the shape. Backend done 2026-08-25 (direct DB reads, no timer). Frontend footer rendering for cost/tokens vs pct is the remaining display piece.
- **Status:** done (backend via QuotaService::opencode_quota_state + Config::opencode_db_path + dashboard agents map; live-verified cost 0/tokens 3M aggregated, per-session tokens for ses_fc8124; tests isolated via OPENCODE_DB_PATH).

---

## Phase 7 — Polish / stretch goals (defer)

- **ID:** 7
- **Objective:** Anything not in the piping → IO+permissions → quotas chain: `resume` for OpenCode sessions (`opencode --session <id>` / `--continue`), mode control (`--auto` toggle), archived-session browsing for OpenCode, model/agent switching UI.
- **Dependencies:** Phases 0–6.
- **Status:** deferred

---

## Execution order

```
0.1 (groundwork) → 0.2 (live research + plugin review) → 1.1 (adapter+registry+picker) → 2.1 (spawn+identity) → 3.1 (transcript) → 4.1 (listing+status) → 5 (blocked prompts + basic IO) → 6 (quotas) → 7 (polish, deferred)
```

Per amendment (b): Phase 6 (quotas) runs **immediately after** Phase 5 (basic IO with prompt + permissions), not deferred to the end. Each of 0.1, 1.1, 2.1, 3.1, 4.1, 5, 6 is shippable/testable on its own before starting the next. Merge 0.1+1.1 if 0.2's spawn finding is simple (likely — reactive vs pre-assigned is a one-line `assigned_id` choice).

## Testing conventions (all phases)

- Mirror the existing isolation discipline: fixture paths via env-var overrides, canned DB/JSON fixtures, no live `opencode` process in the automated suite — same "never touch the real thing" principle already used for `claude`/tmux (`tests/.env.testing`, `tests/fixtures/fake_claude`, `tests/fixtures/fake_agy`).
- `bash tests/run.sh` must pass after every phase.
- `public/js/*.js` stays plain ES5 (see CLAUDE.md).
- All tmux/process invocations use `proc_open()` array form — no shell strings.
