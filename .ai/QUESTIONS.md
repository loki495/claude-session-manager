# QUESTIONS.md — OpenCode TUI agent integration

No worker questions yet. Orchestrator's own open questions (to be resolved during Phase 0 research, before implementation):

## Q0 — Resolved before planning (evidence gathered 2026-08-24/25)

- **Binary:** `opencode` at `/usr/bin/opencode`, v1.18.21, config at `~/.config/opencode/opencode.jsonc` (empty), data at `~/.local/share/opencode/opencode.db`.
- **TUI spawn:** `opencode [project_path]` starts interactive TUI; flags include `--model provider/model`, `--agent`, `--session <id>` (resume), `--continue` (last session), `--auto` (auto-approve). No `--session-id` for pre-assigning a new session's ID (like Claude Code's `--session-id` or Antigravity's absent equivalent).
- **Storage:** SQLite, tables `session` (id `ses_*`, slug, directory, title, agent, model, cost, tokens_*), `message` (role/user/assistant, time, agent, model, summary), `part` (type text/step-start/etc.). Transcript is distributed across `message`+`part`, not a single JSONL file. `opencode export <sessionID>` dumps session JSON; `opencode db "SELECT ..."` is a direct query tool.
- **Plugin system (amendment a, verified 2026-08-25 via https://opencode.ai/docs/plugins/ + local types):**
  - Docs: `https://opencode.ai/docs/plugins/` — plugins are JS/TS modules exporting hooks, loaded from `~/.config/opencode/plugins/` (global) and `.opencode/plugins/` (project), plus npm `plugin` entries in `opencode.json` (installed via Bun). Load order: global config → project config → global plugin dir → project plugin dir.
  - `Hooks` interface (`@opencode-ai/plugin/dist/index.d.ts`): `permission.ask` (`input: Permission` → `output: {status: ask|deny|allow}`), `tool.execute.before` (`input: {tool, sessionID, callID}` → `output: {args}`), `tool.execute.after`, `chat.message`, `event`, `config`, `tool`, `auth`, `provider`, etc.
  - `Event` types (`@opencode-ai/sdk/dist/gen/types.gen.d.ts`): `permission.updated` (`properties: Permission`), `permission.replied` (`{sessionID, permissionID, response}`), `session.status` (`{sessionID, status: idle|busy|retry{attempt,message,next}}`), `session.idle` (`{sessionID}`), `message.updated`, `session.created/updated/deleted/compacted`, `file.edited`, `todo.updated`, etc.
  - TUI API (`plugin/dist/tui.d.ts`): `TuiAttentionSoundNames: ["default","question","permission","error","done","subagent_done"]`, `permission(sessionID) => PermissionRequest[]` — permission is a first-class TUI concern.
  - Preliminary assessment: `permission.ask` + `permission.updated`/`permission.replied` + `session.status`/`session.idle` should be able to replace most `capture-pane` parsing for working/idle/blocked detection. Task 0.2 will confirm with a real TUI session and propose a minimal CSM global plugin that writes to `SessionStatusStore`/`PendingToolStore`.

## Q1 — Live-verified 2026-08-25 (Task 0.2 — isolated tmux + real binary)

1. **Session ID assignment — CONFIRMED REACTIVE (like Antigravity):**
   - `opencode --session ses_test_doesnotexist123` for a NEW session fails with `Error: Session not found` — `--session` is resume-only, same as Antigravity's `--conversation`. No `--session-id` for pre-assigning.
   - `opencode /tmp` (plain TUI, no `--session`) in isolated tmux (`/tmp/csm-research-*/socket`, 200×50) starts (`opencode` process present, 148% CPU) but creates NO new `session` row in `opencode.db` until the first prompt is submitted — same lazy creation as Antigravity. Verified: `SELECT COUNT(*) FROM session` unchanged (16) after spawn; only after `opencode run "say hello..." --dir /tmp --format json` did a new row appear (`ses_fc84b56ddffeqmBhbrqlDo6K56`, `directory=/tmp`, `title` from prompt), with `sessionID` visible in the JSON stream's first `step_start` event.
   - `opencode run --format json` streams `{"type":"step_start","sessionID":"ses_...","part":{...}}` — the session ID is available in-stream immediately, but for TUI mode the earliest signal is polling `opencode.db` for a new row whose `directory` matches the spawned workdir. Latency: DB row appears only after first user message, not at spawn time.
   - Implication for Phase 2.1: sidecar must start with `claude_session_id=NULL` and bind reactively (poll `opencode.db` or plugin `session.created` event). No pre-assignment possible — identical to Antigravity's Phase 2.

2. **Plugin/hook system — DOCS + TYPES VERIFIED, LIVE PLUGIN NOT YET TESTED:**
   - `~/.config/opencode/plugins/` and `.opencode/plugins/` do NOT exist yet on this machine — no global/project plugin has been installed, but the load convention is documented (create the dir, drop a `.ts` file, it auto-loads; no install step beyond `bun install` for npm deps).
   - `plugin/dist/index.d.ts` Hooks confirmed: `permission.ask` (`Permission → {status: ask|deny|allow}` — the decision hook that gates execution), `tool.execute.before/after`, `chat.message`, `event` (generic `Event` union), `config`, `tool`, `auth`, `provider`, `experimental.session.compacting`.
   - `sdk/dist/gen/types.gen.d.ts` Events confirmed: `permission.updated` (`Permission`), `permission.replied` (`{sessionID, permissionID, response}`), `session.status` (`{sessionID, status: idle|busy|retry}`), `session.idle` (`{sessionID}`), `message.updated`, `session.created/updated/deleted`, `todo.updated`, `file.edited`, etc.
   - Preliminary assessment stands: `permission.ask` + `event: permission.updated`/ `session.status` / `session.idle` should replace capture-pane for blocked/working/idle. Task for Phase 5: create `~/.config/opencode/plugins/csm-status.ts` that on `event` writes to `SessionStatusStore` (same `sessions.sqlite` Claude/Antigravity use) and on `permission.ask` records `PendingToolStore` + returns `ask` (no-op, like Antigravity's `pre_tool_use.php` returning `ask` — don't auto-allow). Live confirmation of the plugin actually firing will happen in Phase 5's own verification (spawn a real `oc-*` session with the plugin installed, trigger a permission, check `sessions.sqlite`).
   - Open question deferred to Phase 5: can pane parsing be eliminated entirely? Likely yes for blocked detection (permission events are structured), but keep `OpenCodePromptParser` as fallback if some prompt shape has no event coverage (same pattern as Antigravity's pane-scraped trust dialog).

3. **Permission/blocked-prompt model — TUI PANE BLANK, PLUGIN PAYLOAD IS THE SOURCE:**
   - Isolated TUI (`opencode` in tmux 200×50) capture-pane is blank (all `\n`s, both default and `-a` alternate buffers) — `opencode` process IS running (ps shows `/usr/bin/opencode` at 148% CPU, `fish -c opencode` wrapper), but nothing renders to the capturable buffer. Same result with `-x 200 -y 50` and `-c /tmp` or `-c /home/user/www/claude-session-manager`. Possibly alternate-screen or raw-mode rendering that tmux's capture-pane can't see without different flags, or TUI does lazy init.
   - `opencode --help` via `send-keys` DOES render correctly in the same tmux pane (help banner visible), so tmux plumbing itself is fine — the TUI's own rendering path is the gap, not tmux.
   - Headless `opencode run --format json` works correctly (streams `step_start` etc.) — confirms the binary is functional.
   - Implication: blocked-prompt detection MUST go via plugin `permission.ask` / `event: permission.updated` payloads, not pane scraping. The pane is not a reliable source for OpenCode even at idle, unlike Claude Code's pane. Phase 5 should not attempt `OpenCodePromptParser` pane parsing as primary — plugin is primary, pane is fallback only if proven to work for a specific shape.

4. **Transcript read path — CONFIRMED SAFE (WAL, no lock):**
   - `PRAGMA journal_mode` → `wal` on `opencode.db`.
   - `SELECT COUNT(*) FROM session` and `SELECT data FROM message WHERE session_id='ses_...'` succeed while the TUI `opencode` process is still running (exit 0, no `database is locked`) — concurrent readers are not blocked by the writer in WAL mode.
   - Existing CSM SQLite helper `SqliteDb::connect()` already uses `PRAGMA journal_mode=WAL` + `busy_timeout=5000` — same discipline applies; OpenCodeTranscriptService should open `opencode.db` read-only (`SQLITE_OPEN_READONLY`) with busy retry, mirroring that pattern.
   - `opencode export <sessionID>` is an alternative read path (dumps JSON), but direct `SELECT` from `message`/`part` is the intended polling path (same as CSM's existing `sessions.sqlite` reads).

## Q2 — Deferred (not needed for MVP)

- Quota: `opencode stats` shows token usage/cost, but no rate-limit quota like Claude/Antigravity's quota polling. Can be a later phase if desired.
- Resume: `opencode --session <id>` resumes an existing session; `opencode --continue` resumes last. Wiring `resume_cc_session()` for OpenCode sessions is a stretch goal, not MVP.
