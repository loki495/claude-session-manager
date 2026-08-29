---
id: quota
name: Usage quota (Claude statusline + Antigravity `/usage` poll)
owned_paths:
  - host-agent/lib/Services/QuotaService.php
  - host-agent/quota_live_state_write.php
  - host-agent/antigravity_quota_poll.php
  - src/lib/Controllers/QuotaController.php
  - src/lib/Views/QuotaFooterView.php
  - src/partials/quota-footer/footer.php
  - public/js/quota-footer.js
  - tests/test_quota.php
  - tests/test_antigravity_quota_poll.php
  - tests/fixtures/fake_agy
last_scanned_commit: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# Usage quota (Claude statusline + Antigravity `/usage` poll)

## 1. Identity

- **id:** `quota`
- **name:** Usage quota (Claude statusline + Antigravity `/usage` poll)

## 2. Ownership boundary

**In scope:**
- `host-agent/lib/Services/QuotaService.php` — the one read-side service that
  everything quota-related funnels through.
- `host-agent/quota_live_state_write.php` — event-driven statusline capture
  writer (Claude Code rate limits → `GlobalStateStore` key `quota_live_state`).
- `host-agent/antigravity_quota_poll.php` — periodic `/usage` poll writer
  (Antigravity account quota → `GlobalStateStore` key
  `antigravity_quota_live_state`).
- `src/lib/Controllers/QuotaController.php` — the HTTP endpoint (`GET /quota.php`)
  that reads quota via the agent protocol.
- `src/lib/Views/QuotaFooterView.php` + `src/partials/quota-footer/footer.php` +
  `public/js/quota-footer.js` — the sticky footer's markup and its own
  self-contained fetch-and-poll.
- Unit/integration tests: `tests/test_quota.php`,
  `tests/test_antigravity_quota_poll.php`, fixture `tests/fixtures/fake_agy`.

**Out of scope (neighboring subsystems, referenced only for the boundary):**
- `session-status-state` — owns `SessionStatusStore`, `StatuslineMarkerService`
  session-id marker, `SidecarStore`, `SessionService`. The statusline
  **quota-capture block** (`quota_capture_block()`/`QUOTA_CAPTURE_*`) physically
  lives in `StatuslineMarkerService.php` and is **co-reported** here (see §9).
- `push-notifications` — owns `PushDeliveryService`,
  `check_and_send_quota_pushes()`, `PushQuotaStateStore`. It *reads* quota from
  this subsystem and consumes the same `Config::push_sqlite_path()` DB for
  `GlobalStateStore`.
- `health`/`session`, `browse`, `upload`, `transcript` — other feature areas;
  none own quota's capture/read/display path.

## 3. Key implementation files

- **`host-agent/lib/Services/QuotaService.php`** (400 lines)
  The read-side service. Four public static readers plus one dispatcher:
  `live_context_pct()`, `quota_from_statusline_state()`,
  `antigravity_quota_state()`, `opencode_quota_state()`, and `get_quota()`,
  which switches on the requesting session's agent (Claude Code / Antigravity /
  OpenCode) and builds the per-session or dashboard-shaped response. No state is
  mutated on this side — it only reads.
- **`host-agent/quota_live_state_write.php`** (92 lines)
  Standalone statusline-capture entry point. Reads Claude Code's
  `{five_hour, seven_day}` rate limits as JSON on stdin, merges them against the
  previous `GlobalStateStore` row keyed by `Config::quota_live_state_key()`, and
  writes the result. The merge rule (`merge_quota_bucket()`) only moves a bucket's
  `pct` DOWN when its `resets_at` also moved forward (a genuine window rollover).
- **`host-agent/antigravity_quota_poll.php`** (106 lines)
  Standalone periodic timer entry point. Runs `agy -p "/usage" --output-format
  json`, parses the `command.data.groups[].buckets[]`, converts Antigravity's
  `remaining_fraction` (how much is left) into a used-percentage `pct`, and
  overwrites `GlobalStateStore` key `antigravity_quota_live_state_key()` in one
  shot — no merge logic needed (single writer).
- **`src/lib/Controllers/QuotaController.php`** (42 lines)
  GET-only JSON endpoint. Reads `$_GET['session']`, delegates to
  `AgentClient::agent_call(['action' => 'quota', ...])`, echoes the JSON result.
  Read-only, so no CSRF check — same as `GET /`.
- **`src/lib/Views/QuotaFooterView.php`** (40 lines)
  Thin view wrapper: one static `quota_footer_html()` that renders the
  `quota-footer/footer` Plates template.
- **`src/partials/quota-footer/footer.php`** (13 lines)
  The footer markup skeleton (`#quota-footer`, `#quota-toggle-btn`,
  `#quota-info`), its `data-session` attribute, and the versioned script tag for
  `quota-footer.js`.
- **`public/js/quota-footer.js`** (397 lines)
  Self-contained fetch-and-poll client. Collapse toggle (localStorage), bucket
  label/duration/absolute-time formatting, a single-session list render and a
  dashboard multi-agent table render, polling `GET /quota.php?session=...` every
  60s.
- **`tests/fixtures/fake_agy`** (26 lines)
  Fake `agy` binary. In `-p`/`--print` mode emits a canned `/usage` JSON envelope
  (two groups: "Gemini Models" / "Claude and GPT models"); otherwise `exec cat`
  to block like an idle interactive pane.

## 4. Public interfaces & contracts

### `HostAgent\Services\QuotaService`

**`live_context_pct(string $sessionName): ?int`** — `QuotaService.php:50`
- Reads `StatuslineMarkerService::parse_marker_from_pane(TmuxService::tmux_capture_pane($sessionName))['context_used_percentage']` and rounds to an int.
- Returns `null` if the session isn't live, the marker isn't installed yet, or its
  pane currently shows no status line (mid-response / mid-slash-command).
- Pre/post-conditions: genuinely per-session (unlike the account-wide buckets).
  Never throws on a bad pane; `context_used_percentage` may be `null`.

**`quota_from_statusline_state(): ?array`** — `QuotaService.php:87`
- Reads `GlobalStateStore::read(Config::quota_live_state_key())`.
- Returns `null` when there's no row, malformed JSON, `captured_at` missing/not
  int, or neither `session` nor `week_all` bucket is valid.
- Otherwise returns
  `['quota' => ['session' => ['pct' => int, 'resets_at' => int], 'week_all' => [...], 'captured_at' => ISO-8601 string], 'fetched_at' => int-epoch]`.
- A bucket with `pct` as a string (not int) or missing `resets_at` is treated as
  absent, not coerced; a single valid bucket still produces a partial read (not
  all-or-nothing).
- `resets_at` is a real Unix epoch straight from Claude Code's statusLine JSON
  (`rate_limits.*.resets_at`) — not reconstructed from rounded pane text.

**`antigravity_quota_state(): ?array`** — `QuotaService.php:127`
- Reads `GlobalStateStore::read(Config::antigravity_quota_live_state_key())`.
- `quota` is a bare array holding each bucket key with
  `['pct' => int, 'resets_at' => int, 'group_name' => ?string]`, plus a
  `captured_at` ISO-8601 entry. Returns `null` when no row / malformed /
  `captured_at` not int / no valid buckets.

**`opencode_quota_state(?string $sessionId = null): ?array`** — `QuotaService.php:175`
- Opens `opencode.db` read-only (`SQLITE_OPEN_READONLY`, `PRAGMA busy_timeout`),
  so a live TUI writer is never blocked. Returns `null` when the DB is missing or
  empty.
- With a valid `ses_*` id: per-session `{cost, tokens_input, tokens_output,
  tokens_reasoning, tokens_cache_read, tokens_cache_write, captured_at}`.
- Without: dashboard aggregate `{cost, tokens_input, tokens_output,
  tokens_reasoning, tokens_cache_read, tokens_cache_write, session_count,
  captured_at}`.

**`get_quota(?string $sessionName = null): array`** — `QuotaService.php:254`
- Per-session (name given): looks up `SidecarStore::read_sidecar()['agent']`
  (default `'claude'`). For `antigravity` → `antigravity_quota_state()`; for
  `opencode` → `opencode_quota_state(sidecar's claude_session_id)`; for `claude`
  → `quota_from_statusline_state()`, and additionally overlays
  `live_context_pct()` as a `'context'` bucket (which carries no `resets_at`).
- Dashboard (no name): returns an `agents` map (`claude` / `antigravity` /
  `opencode`), each `{label, ok, quota, fetched_at, message}`.
- `cached`/`stale`/`refreshing` are always `false` now — kept for frontend
  compatibility (the cache/background-refresh machinery was deleted 2026-08-22).
- No `throw`s; failure is surfaced as `ok => false` with a `message`.

### `host-agent/quota_live_state_write.php`

- **Entry point**: run once per Claude Code status-line render by the
  statusLine script's quota-capture block (shells out via
  `Config::quota_live_state_write_command()`). Reads JSON from stdin.
- **stdin contract**: `{"five_hour": {...}|null, "seven_day": {...}|null}`, where
  each bucket (if present) has `used_percentage` (float) and `resets_at` (int).
  The bash side does the jq shape-narrowing; PHP does the merge+write.
- **`merge_quota_bucket(?array $newBucket, ?array $prevBucket): ?array`** —
  `quota_live_state_write.php:59`. If new is malformed → keep prev (if prev
  valid). Otherwise accept the new bucket when `newPct >= prevPct` **or**
  `newResetsAt !== prevResetsAt` (rollover); else keep prev. Protects against
  several independent Claude Code sessions' statuslines racing each other.
- **Post-conditions**: writes `GlobalStateStore::write(
  Config::quota_live_state_key(), ['session'?, 'week_all'?, 'captured_at' =>
  time()])`. Never writes to stdout; always exits 0.

### `host-agent/antigravity_quota_poll.php`

- **Entry point**: run periodically by the opt-in `csm-antigravity-quota-check`
  systemd timer. **No-op** (exits 0 immediately) if `Config::antigravity_bin()`
  is `''`, or the run exits non-zero, or the JSON `status !== 'SUCCESS'`, or
  `command.data.groups` isn't an array.
- **`pct` encoding**: Antigravity reports `remaining_fraction` (how much is
  LEFT); this app stores **used** percentage: `pct = round((1 - remaining_fraction) * 100)`.
  `reset_time` (ISO-8601 string) is parsed to a Unix epoch with `strtotime()`; on
  parse failure the bucket is skipped. `group_name` is preserved per-bucket.
- **Post-conditions**: single writer, single overwrite. Writes
  `GlobalStateStore::write(Config::antigravity_quota_live_state_key(),
  ['captured_at' => time(), '<bucket>' => ['pct' => int, 'resets_at' => int,
  'group_name' => ?string]])`. Never writes to stdout.

### `App\Controllers\QuotaController::show(): void` — `QuotaController.php:18`

- `GET /quota.php` (`src/routes.php:85`). Calls `start_readonly_json()` (sets
  `Cache-Control: no-store` so a browser never serves a stale response to the
  same `fetch()` URL up to max-age=60). Reads optional `$_GET['session']`,
  delegates to `AgentClient::agent_call(['action' => 'quota', 'session' =>
  $sessionName])` and echoes `json_encode`. Read-only → no CSRF check.

### `App\Views\QuotaFooterView::quota_footer_html(string $extraHtml = '', string $sessionName = ''): string` — `QuotaFooterView.php:33`

- Renders `quota-footer/footer` with `['extraHtml' => ..., 'sessionName' => ...]`.
- `$extraHtml` renders on the same row as the collapse toggle (outside
  `#quota-info`, which the poll script fully replaces on every refresh).
- `$sessionName` threads into the canvas via `data-session`, so the client can
  additionally request that session's own context-window percentage.

### `public/js/quota-footer.js` client behavior

- Polls `GET /quota.php(?session=...)` every 60s (`load()` at `quota-footer.js:378`,
  interval at `:396`), `credentials: 'same-origin'`. A `loading` guard prevents
  piling requests on a slow one.
- Collapse persisted under `localStorage['csm-quota-collapsed']`; defaults to
  collapsed unless explicitly expanded.
- Renders a single-session list (`render()`, `:266`) or the dashboard multi-agent
  table (`renderDashboardTable()`, `:133`) when no session is given and
  `data.agents` is present. OpenCode cost/tokens have a distinct render path
  (`:284`). Unavailable → `showUnavailable(data)` (`:124`).

## 5. Major call sites

- **Agent protocol entry:** `host-agent/lib/Sessions.php:174-177` —
  `case 'quota':` calls `QuotaService::get_quota($quotaSession !== '' ?
  $quotaSession : null)`. This is the host-agent side of the socket request that
  `QuotaController::show()` initiates.
- **`GET /quota.php` route:** `src/routes.php:85` maps `/quota.php` →
  `[QuotaController::class, 'show']`.
- **HTTP endpoint caller:** `QuotaController.php:40` →
  `AgentClient::agent_call(['action' => 'quota', 'session' => $sessionName])`.
- **Footer render call sites (this subsystem's own display):**
  - `src/partials/pages/index.php:210` — dashboard footer:
    `QuotaFooterView::quota_footer_html()` (no session → no context bucket).
  - `src/partials/compose-bar.php:60` — session page
    `QuotaFooterView::quota_footer_html($agentExtras, $sessionName)` threaded into
    the compose bar's same row as the mode/model toggle buttons.
- **Quota write call site:** `host-agent/lib/Services/StatuslineMarkerService.php:481`
  — the quota-capture block shells out to `Config::quota_live_state_write_command()`
  (`quota_live_state_write.php`) on every status-line render.
- **Reverse dependency — `push-notifications` consumes quota:**
  `host-agent/push_trigger.php:74-79` — the quota pass merges both agents' buckets
  from `QuotaService::get_quota()['agents']` (`claude` session/week_all +
  `antigravity` gemini-weekly/3p-weekly) and hands the flat merge to
  `PushDeliveryService::check_and_send_quota_pushes()`.

## 6. Tests

- **`tests/test_quota.php`** (212 lines) — pure unit test (no tmux), focused on
  malformed-input edge cases. Covers `quota_from_statusline_state()` sad paths
  (no row, malformed JSON row, missing/non-int `captured_at`, pct-as-string not
  coerced, missing `resets_at`) and the partial-read + both-bucket happy path;
  `antigravity_quota_state()` happy + no-row; and `get_quota()` per-agent
  (claude/antigravity sidecar lookup) and the dashboard `agents` map, including
  `ok=false` with no data and `cached`/`refreshing` always `false`. Uses isolated
  `push.sqlite`/`sessions.sqlite`/`opencode.db` fixtures via env overrides, and
  refuses to run against the real host state files.
- **`tests/test_antigravity_quota_poll.php`** (103 lines) — runs the real
  `antigravity_quota_poll.php` as a subprocess (proc_open) against
  `tests/fixtures/fake_agy`. Happy path (real fake `/usage` → state written, pct
  conversion 0.75→25 and 1→0, `reset_time` parsed to epoch, `group_name`
  preserved, exactly 2 buckets + `captured_at`, plain overwrite); sad path (empty
  `ANTIGRAVITY_BIN` → harmless no-op, nonexistent binary → exits 0, no state
  written, no stdout).
- **`tests/test_sessions_lifecycle.php:1847-1914`** — integration coverage of
  `quota_from_statusline_state()`/`get_quota()` (read side, "no data yet"),
  `live_context_pct()` against a real tmux session (null before marker, 4 after
  a crafted `csm-data` pane, null for non-live session), the context overlay, and
  the deleted-state null path. Needs a real tmux session, so it lives with the
  lifecycle suite, not the fast unit test.
- **`tests/test_statusline_marker.php`** — co-owned (see §9): end-to-end
  exercises the quota-capture block (fresh install writes pct/resets_at from
  `rate_limits.*`; a lower pct with same `resets_at` is ignored; a lower pct with
  a different `resets_at` IS accepted; idempotent re-install; upgrade path
  appends one block; stale-body replace; fallback script; no state row when the
  payload has no `rate_limits`).
- **`tests/test_ui_smoke.php:190-206`** — `GET /quota.php` returns 200 and
  `ok=true` JSON, passes the canned session percentage through, and includes a
  `context` bucket only when `?session=` is given. Also in the poll-endpoint list
  at `:266`.

## 7. Dependencies

**Upstream (how quota data reaches this subsystem):**
- **Claude Code statusLine JSON** → `StatuslineMarkerService::quota_capture_block()`
  (co-owned) narrows `rate_limits.five_hour`/`seven_day` via jq and shells out to
  `quota_live_state_write.php` on every render → `GlobalStateStore`.
- **`GlobalStateStore`** (`host-agent/lib/Stores/GlobalStateStore.php`) — the
  `global_state` table of `Config::push_sqlite_path()`, holds keys
  `quota_live_state` and `antigravity_quota_live_state`. Read by
  `QuotaService::quota_from_statusline_state()` /
  `antigravity_quota_state()`; written by the two standalone scripts. Co-owned
  with `push-notifications` (same SQLite file).
- **`Config`** (`host-agent/lib/Services/Config.php`) — provides the quota keys
  (`:164`, `:184`), the write command (`:364`), and `opencode_db_path()`
  (`:231`).
- **`SidecarStore::read_sidecar()`** — used by `get_quota()` to determine the
  session's `agent` (`claude`/`antigravity`/`opencode`) and the OpenCode
  `claude_session_id`.
- **`StatuslineMarkerService::parse_marker_from_pane()`** + **`TmuxService::tmux_capture_pane()`**
  — `live_context_pct()` needs a live pane to read the `csm-data` marker's
  `ctx_pct`.
- **`OpenCodeTranscriptService::is_opencode_id()`** — `opencode_quota_state()`
  validates a `ses_*` id before a per-session query.

**Downstream (what depends on this subsystem):**
- **`QuotaController`/`quota-footer`** — the web display path (per-session footer,
  dashboard table).
- **`push-notifications`** — `push_trigger.php:74-79` reads
  `QuotaService::get_quota()['agents']` and feeds the merged buckets to
  `PushDeliveryService::check_and_send_quota_pushes()`.

**External packages:** none new for the quota path itself — uses PDO
(`sqlite:`), PHP stdlib, and the already-present `minishlink/web-push`
indirectly via the push consumer.

## 8. Data & schema

**Storage:** `GlobalStateStore` → `global_state` table of
`Config::push_sqlite_path()` (`SqliteDb::push_schema()`), columns
`key`, `value_json`, `updated_at` (upsert on `key`).

**`quota_live_state` key** (written by `quota_live_state_write.php`, read by
`QuotaService::quota_from_statusline_state()`):
```json
{
  "session":   {"pct": 51, "resets_at": 1738425600},
  "week_all":  {"pct": 40, "resets_at": 1739548800},
  "captured_at": 1725546000
}
```
- `session` ← Claude `rate_limits.five_hour`; `week_all` ←
  `rate_limits.seven_day`. `pct` is a used-percentage int (0-100), `resets_at` is
  a real Unix epoch. `captured_at` is an int epoch (the read side converts to
  ISO-8601 for the UI).

**`antigravity_quota_live_state` key** (written by `antigravity_quota_poll.php`,
read by `QuotaService::antigravity_quota_state()`):
```json
{
  "gemini-weekly": {"pct": 25, "resets_at": 1785611047, "group_name": "Gemini Models"},
  "3p-weekly":     {"pct": 0,  "resets_at": 1785615693, "group_name": "Claude and GPT models"},
  "captured_at": 1725546000
}
```
- Bucket ids are Antigravity's opaque ids (`gemini-weekly`, `3p-weekly`);
  `group_name` is the human-readable group. `pct` is stored **used** (converted
  from `remaining_fraction`), matching Claude Code's convention.

**OpenCode** (`Config::opencode_db_path()`, direct read-only SQLite read, no
`GlobalStateStore`/polling): `session` table columns read are `cost`,
`tokens_input`, `tokens_output`, `tokens_reasoning`, `tokens_cache_read`,
`tokens_cache_write`, `time_updated` (ms epoch → `/1000`), aggregated with
`COUNT(*)` for dashboard `session_count`.

**`SessionStatusStore`:** named by the subsystem boundary as an upstream
dependency — it is the per-session status file store (`session-status-state`),
but the quota path does **not** read it. Quota's Claude source is the
statusline-captured `rate_limits` payload → `GlobalStateStore`, not
`SessionStatusStore`. Documented here for boundary clarity, not as a read path.

## 9. Co-owned / cross-subsystem

### `StatuslineMarkerService` quota-capture block (physically in `session-status-state`)

The statusline **quota-capture** install/update logic lives in
`host-agent/lib/Services/StatuslineMarkerService.php` (owned by
`session-status-state`) but is **reported by this subsystem** because it writes
the quota state this subsystem reads. Co-owned lines:

- `:38-39` — `QUOTA_CAPTURE_BEGIN`/`QUOTA_CAPTURE_END` marker constants (managed,
  safe-to-delete block delimiters).
- `:176-179` — `quota_capture_installed()`: block present **and** body current
  (`quota_capture_up_to_date()`), so a stale-body script is not falsely reported
  installed.
- `:190-194` — `quota_capture_up_to_date()`: body matches current
  `quota_capture_block()` output.
- `:199-219` — `read_installed_script_content()`: shared with the session-id
  marker; reads the located script or the fallback script.
- `:334-340` — `install_into_script()` quota branch: append when missing, replace
  when body stale.
- `:355-376` — `replace_quota_capture_block()`: swaps only the text between the
  QUOTA_CAPTURE markers for the current body.
- `:428-447` — `append_quota_capture_block()`: appends just this block to a
  script that already has the (older) session-id marker.
- `:474-485` — `quota_capture_block()`: the actual shell block — jq-narrows
  `{five_hour, seven_day}` and shells out to
  `Config::quota_live_state_write_command()` (`quota_live_state_write.php`).
- `:491-530` — `install_fallback_script()`: builds `~/.claude/csm-statusline.sh`
  including the quota-capture block (line `:501`), points `statusLine` at it.

This is the only co-owned block; `parse_marker_from_pane()`/`JQ_FILTER`
session-id marker logic stays wholly with `session-status-state`.

### `GlobalStateStore` cross-reference (co-owned with `push-notifications`)

`host-agent/lib/Stores/GlobalStateStore.php` and the two quota keys
(`Config::quota_live_state_key()`, `Config::antigravity_quota_live_state_key()`)
live in the `global_state` table of `Config::push_sqlite_path()` — the same
SQLite file `push-notifications` uses (`PushSubscriptionStore`,
`PushQuotaStateStore`, `PushSessionStateStore`). The quota keys are dedicated
rows (separate `key` values), so there is no row collision; the shared file is
the only coupling. `push_trigger.php` is the other co-owned consumer (see §5).
