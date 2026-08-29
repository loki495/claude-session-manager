---
id: session-core
based_on: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# session-core — Session model + tmux/process primitives

## 0. Re-verification

DETAILS.md's `last_scanned_commit` (`44e4caab492481c850d4dceec97d5c65e41a5b53`) equals the current
HEAD. Every line reference I spot-checked is accurate against the current source — no drift found
in the four owned files. Specifically verified:

- `SessionService` — `title_cascade()` :44, `session_title()` :67, `build_session_entry()` :98,
  `self_heal_claude_session_id()` :347, `session_last_message()` :384, `list_all_sessions()` :408,
  `browse_dir()` :471, `create_dir()` :522. Every intra-method line cited in §4 (`:100-110`,
  `:112-113`, `:221-233`, `:260-261`, `:269-271`, `:277`, `:245-248`, `:353-359`, `:361-367`,
  `:410-422`, `:437`, `:439-452`, `:482`, `:533`, `:539`) matches.
- `TmuxService` — all public methods and the `#{window_activity}` docblock `:53-67` match.
- `ProcessRunner` — `run_process()` :18, failure return :28-29.
- `ProcessInspector` — `CLK_TCK` :16, `build_ppid_map()` :21, stat-math doc :43,
  `process_start_time()` :52, `is_descendant()` :85, `find_claude_processes()` :114,
  argv[0]-basename rationale :137-145, `find_owning_pane()` :168.

Two minor internal doc drifts (not file:line drift, but accuracy drift inside the code itself) are
captured as findings #6 and #7 below.

---

## 1. Ranked findings

### F1 (High) — A transient tmux query failure is conflated with "no sessions", and the orphan-prune then wipes every sidecar/status/pending-tool row

References: `SessionService.php:420-422`, `TmuxService.php:186-190`, `SidecarStore.php:128-136`
(the prune's destructive empty branch), and its test `test_sessions_lifecycle.php:1051-1053`.

**Evidence**

- `list_all_sessions()` builds the "live session" set from `TmuxService::all_tmux_panes()`:
  `SessionService.php:420 $allPanes = TmuxService::all_tmux_panes();`, `:421 $liveSessionNames =
  array_column(...)`, `:422 SidecarStore::prune_orphaned_sidecars($liveSessionNames);`.
- `all_tmux_panes()` returns `[]` for **both** "no tmux sessions exist" (exit 0, empty stdout) and
  "the tmux query failed" (`TmuxService.php:188-189 if ($result['exit'] !== 0) { return []; }`).
  `list_all_tmux_sessions()` does the same (`TmuxService.php:75-77`).
- `SidecarStore::prune_orphaned_sidecars([])` (the `$liveSessionNames === []` branch, lines 131-132)
  runs `DELETE FROM sidecars`, `DELETE FROM session_status`, `DELETE FROM pending_tools` — i.e.
  **all three tables wiped**, not "prune nothing".

**Impact**

Any poll in which `tmux -S <sock> list-panes -a` returns a non-zero exit for a transient reason
(socket momentarily stale/being recreated after a `/tmp` wipe mid-flight, a version/permission
mismatch in the host-agent env, or a just-starting server) is silently indistinguishable from
"zero sessions exist", and the whole tracking layer — `claude_session_id`s, workdirs, `agent`,
`spawned_by_csm`, every status row, every pending tool — is destroyed in a single dashboard load.
Nothing later re-creates it; the sessions' sidecars are unrecoverable. This is precisely the class
of bug the repo's CLAUDE.md sad-path rule targets ("a failure surfaces as a handled error, not a
silent destructive outcome"), and the existing `:1045-1053` test proves the *intent* (preserve
adopted non-`cc-*` sidecars) but never exercises the empty-input path, so the wipe is untested.

The intent of the prune is correct (the `all_tmux_panes()`-not-`cc-**` choice at `:414-420` is a
deliberate, documented fix); the bug is the **failure-conflation** + the **empty-input wipe** on top
of it.

**Recommended fix**

- Make "query failed" distinguishable from "genuinely no sessions": have `all_tmux_panes()` return a
  sentinel (e.g. `['ok' => false, ...]`) or a separate `tmux_available()`/null result, and have
  `list_all_sessions()` **skip** `prune_orphaned_sidecars()` entirely when the query failed rather
  than passing `[]`.
- Independently, make `prune_orphaned_sidecars([])` non-destructive: the `NOT IN ()` empty-set
  workaround should be `WHERE 1 = 0` (delete nothing) rather than an unconditional `DELETE FROM`.
- Add a sad-path test: `all_tmux_panes()` returning failure (or `[]` from a failed run) must not
  delete existing sidecars; and `prune_orphaned_sidecars([])` must be a no-op.

**Smallest scope**: `TmuxService.php` (`all_tmux_panes`/`list_all_tmux_sessions` return shape),
`SessionService.php:420-422`, `SidecarStore.php:131-132`. `SidecarStore` is a cross-cutting store
(see Cross-Cutting), so confirm ownership before touching the prune branch.

**Regression risk / migration**: none — this only changes the failure path. The genuine-orphan
prune behavior (the `:1051-1053` regression test) must be preserved: a real, live non-`cc-*` tmux
session's sidecar must still survive, and genuinely-dead sessions' sidecars must still be pruned.

**Confidence**: high. **Severity**: high.

---

### F2 (Medium) — `ProcessRunner::run_process()` has no timeout, and drains stdout then stderr sequentially (latent pipe-buffer deadlock)

References: `ProcessRunner.php:26-37`.

**Evidence**

- `proc_open` is called at `:26`; then `:33 stream_get_contents($pipes[1])` is drained to EOF,
  **then** `:34 stream_get_contents($pipes[2])`. There is no `proc_get_status` polling loop and no
  `proc_terminate` anywhere in `:18-40`; the two output pipes are read synchronously to completion.
- Two consequences:
  1. **No timeout.** If a child never exits (a `tmux` server holding a lock, `curl` without a
     `--max-time` help, `systemctl` blocked on a busy `--user` bus), `run_process()` blocks forever.
     The host agent is a per-connection process spawned by systemd socket activation, so a wedged
     call holds that web request open indefinitely — the dashboard poll hangs with no recovery.
     (DETAILS.md notes this as a known characteristic, but for a foundation primitive feeding a
     poll-heavy UI it is an unmitigated availability risk.)
  2. **Sequential-drain deadlock.** If a child writes more than the OS pipe buffer (~64KiB) to
     stderr while we're still draining stdout, the child blocks writing stderr and never closes
     stdout, so `stream_get_contents($pipes[1])` never returns EOF. tmux/curl outputs are small in
     practice, so this is latent, but it is the textbook proc_open deadlock and the fix is cheap.

**Recommended fix**

- Add an optional `?int $timeoutSeconds = null` parameter; when set, poll `proc_get_status()` in a
  loop (e.g. 10-20ms) and `proc_terminate()` + return the "timed out" `{exit:-1, stderr:...}`
  shape on expiry.
- Drain stdout and stderr concurrently (non-blocking `stream_set_blocking(false)` + `stream_select`
  loop), or redirect fd 2 onto fd 1 (`2 => ['redirect', 1]`) so a single drain suffices and the
  deadlock is structurally impossible.

**Smallest scope**: `ProcessRunner.php` only — callers are untouched if the timeout is opt-in and
the return shape stays `{exit,stdout,stderr}`.

**Regression risk / migration**: existing callers (tmux, kill, curl, systemctl) keep default no-timeout
behavior; only a caller that opts in changes. Test the timed-out path explicitly.

**Validation**: no existing test covers a hung child or a stderr-heavy child. Add both sad paths
(a process that sleeps past the timeout → `exit=-1` with a handled message; a process that floods
stderr → still returns both streams, no hang).

**Confidence**: high (the no-timeout fact is unambiguous; the deadlock is conditional on output size).
**Severity**: medium.

---

### F3 (Medium) — Per-session read amplification: 2 tmux calls + 3 transcript-path resolutions with no sharing

References: `SessionService.php:100` (`tmux_session_panes`), `:136` (`tmux_capture_pane`),
`:69` (`session_title` → `TranscriptRouter::find_transcript_path`),
`:269` (`TranscriptService::find_transcript_path`), `:390` (`session_last_message` →
`TranscriptRouter::find_transcript_path`), invoked per session in the loop at `:427-435`.

**Evidence**

For **every** tracked session, `build_session_entry()` issues two separate tmux invocations
(`tmux_session_panes()` and `tmux_capture_pane()`) and resolves the transcript file path up to three
times (`session_title`, current-model, `session_last_message`), none of which share a result. With N
sessions that is 2N tmux round-trips + 3N transcript-path lookups per dashboard/list poll, where the
list is polled on a timer. The tmux pane-title in particular is already available from the
`all_tmux_panes()` map that `list_all_sessions()` fetched at `:420` but is re-queried per session
instead of being threaded through.

**Impact** — not a correctness bug, but avoidable per-poll cost that grows linearly with session
count on a hot path (the dashboard). Each transcript-path resolution typically globs the
`.claude/projects` tree, so 3N of those is meaningful at poll frequency.

**Recommended fix**

- Resolve the transcript path once in `build_session_entry()` and pass it to `session_title()` /
  `session_last_message()` / the model lookup rather than letting each re-resolve.
- For the pane title, thread the already-fetched `all_tmux_panes()` map (or a `session => title`
  lookup) into `build_session_entry()` instead of a per-session `tmux_session_panes()`; keep
  `tmux_capture_pane()` per-session (it is inherently per-session).

**Smallest scope**: `SessionService.php` (`build_session_entry` signature/body +
`list_all_sessions`), `TmuxService.php` (add a panes-by-session accessor if needed). No protocol/UI
change.

**Regression risk**: `build_session_entry()` is also called by `SessionDetailService::session_detail()`
with a single session; keep the new params optional/defaulted so that call site is unaffected.

**Validation**: existing `build_session_entry`/`list_all_sessions` happy-path tests must stay green;
add a test asserting the title fallback chain still resolves identically once the title is injected.

**Confidence**: medium. **Severity**: medium.

---

### F4 (Low-Medium) — Antigravity's blocked prompt is pane-scraped, an undocumented third carve-out from the "EXCLUSIVELY SessionStatusStore" contract

References: `SessionService.php:205-206` (antigravity branch →
`AntigravityPromptParser::parse_blocking_prompt($paneContent)`), and the contract text at
`SessionService.php:222-233` + `SessionStatusStore.php:27-38` + `DETAILS.md §9`.

**Evidence**

The 2026-08-22 contract (recorded in `SessionStatusStore.php:27-38` and `DETAILS.md §9`) states
`build_session_entry()` reads blocked-prompt content **exclusively** from the status store with
exactly two carve-outs: the folder-trust dialog and `AskUserQuestion`'s content. But the Antigravity
branch (line 205-206) is pane-scraped wholesale. The Antigravity hooks present
(`hooks/antigravity/pre_tool_use.php:70`, `pre_invocation.php:86`, `stop.php:233`) only ever write
`status => 'working'` and `blocked => null` (or `last_turn_error`) — none of them captures a
blocked-prompt payload, so there is genuinely **no** hook-fed source for Antigravity's blocked
prompt and the pane is the only option.

**Impact** — this works today, but the documented contract is inaccurate: a future maintainer
"correcting" the apparent violation (e.g. removing the pane-scrape to match the documented "no
fallback" rule) would silently break Antigravity blocked-prompt surfacing. It is a contract-vs-code
drift risk, not a live bug.

**Recommended fix**

- Update the contract text (`SessionStatusStore.php` docblock and `DETAILS.md §9`) to enumerate the
  Antigravity carve-out alongside the existing two, and state the *reason* (Antigravity's hooks
  never feed a blocked payload).
- If a hook-fed Antigravity blocked path is ever added, prefer it over the pane and re-mark
  Antigravity as covered by the exclusive-store contract.

**Validation**: existing Antigravity tests (if any drive the blocked-prompt path) must be checked to
confirm they expect pane-scraping; add one asserting the Antigravity blocked prompt comes from the
pane even when the status store has no blocked payload.

**Confidence**: medium (the carve-out is real; the "should it be routed differently" is a design
question). **Severity**: low.

---

### F5 (Low) — `session_status.last_message` is written by hooks but never read by the listing

References: `SessionStatusStore.php:55,64,104,141` (read/written) vs
`SessionService.php:318` which instead calls `session_last_message($claudeSessionId)` (transcript).

**Evidence**

`build_session_entry()` reads mode/working/blocked/`last_turn_error` from `$hookStatus` but never
uses `$hookStatus['last_message']`; the row preview comes from the transcript via
`session_last_message()` (:384-403). The `last_message` column is written by the hooks' atomic UPDATE
(`SessionStatusStore.php:104`) and read back in `read_status()` but is effectively write-only from
the listing's perspective.

**Impact** — dead-ish column: an extra SELECT column and an extra write on every hook fire, with
nothing consuming it. Not harmful, but it is a silent maintenance trap (someone will assume it's
used) and a candidate for removal or for a read-site that prefers it when a transcript isn't
available.

**Recommended fix** — either (a) drop `last_message` from the `session_status` schema/writes if the
transcript path is always preferred, or (b) use it as the fallback in `session_last_message()` when
the transcript resolution fails (a genuinely useful resilience win: the hook already captured it,
and a dead session with a missing transcript can still show a last message).

**Validation**: the `SessionStatusStore` wiring test (`test_sessions_lifecycle.php:1600-1699`) reads
the blocked/status/mode fields; confirm none of them assert on `last_message` before removing it.

**Confidence**: high that it's unused by this subsystem; medium on whether another consumer reads it.
**Severity**: low.

---

### F6 (Low) — `build_session_entry()`'s `@return` docblock omits two keys it actually returns

References: `SessionService.php:96` (docblock `@return`) vs `SessionService.php:324-325`
(`'context_used_percentage'`, `'git_worktree'`).

**Evidence**

The `@return` array shape at :96 ends at `last_message`, but the method also returns
`context_used_percentage` and `git_worktree` (:324-325). The DETAILS.md §4 return shape mentions 26
keys and does catalog these two — so DETAILS is correct and only the in-code docblock lags.

**Impact** — a static-analysis/reader confusion risk (the shape isn't what the docblock says), not a
runtime bug. Given the project has no PHPStan, this is purely a readability/maintainability flag.

**Recommended fix** — add `context_used_percentage:?float` and `git_worktree:?string` to the docblock
at :96 (and to DETAILS.md if it doesn't already).

**Confidence**: high. **Severity**: low.

---

### F7 (Low) — `agent_label` broad `\Throwable` catch falls back to "Claude Code" for any unknown agent id

References: `SessionService.php:287-291`.

**Evidence**

`try { $agentLabel = AgentRegistry::get($agentId)->label(); } catch (\Throwable) { $agentLabel =
'Claude Code'; }`. `AgentRegistry::get()` throws `InvalidArgumentException` for an id not in
`ADAPTERS` (claude/antigravity/opencode, `AgentRegistry.php:29-31`). An id outside that set — a
hand-edited sidecar, or a future agent added to `SidecarStore` but not to `AgentRegistry` — silently
renders as "Claude Code", which is actively misleading for an Antigravity/OpenCode session.

**Impact** — cosmetic/misleading-label risk; the worse the mismatch, the more wrong the label reads.

**Recommended fix** — default to a humanized version of the agent id (e.g. `ucfirst($agentId)`) or
`$agentId` itself rather than a hard-coded "Claude Code", so an unknown agent at least shows its own
(id-consistent) label.

**Confidence**: high. **Severity**: low.

---

## 2. What's done well

- **Two-runtime boundary is airtight.** All four classes are host-native; nothing here reaches into
  container-land, and the container reaches this subsystem only through the JSON action dispatcher.
  The `browse_dir`/`create_dir` boundary checks (`realpath` + descendant test at :482, :533, name
  basename restriction at :539) properly prevent home-directory escape.
- **`proc_open` array-form is universal** (`ProcessRunner.php:26`), so no shell metacharacter
  injection surface across tmux/kill/curl/systemctl.
- **Dense, evidence-backed docblocks.** The live-found/decided-DATE comments for `#{window_activity}`
  vs `#{session_activity}` (`TmuxService.php:53-67`), the `argv[0]`-basename + tmux-server
  false-positive rationale (`ProcessInspector.php:137-145`), the `read_sidecar` three-state
  `spawned_by_csm` nuance (`SidecarStore.php:51-58`), and the `-J` rejoin (`TmuxService.php:162-168`)
  are all genuinely non-obvious and fully explained — the project convention explicitly says to read
  them before assuming over-engineering, and they hold up.
- **The 2026-08-22 exclusive-store contract is honored** for the Claude Code path, with the
  folder-trust and `AskUserQuestion` carve-outs correctly scoped and commented (`SessionService.php:207-233`).
- **Session-id self-heal** is correctly cross-checked against a real transcript file before
  overwriting (`:357`), and the "last marker wins" fix (`StatuslineMarkerService.php:73-82`) is
  documented. Good balance of defense-in-depth against a known-live corruption class.
- **Per-request fresh re-validation** and the "don't trust an id nothing backs" rule are applied
  consistently.

## 3. Out-of-scope items (seen, intentionally left)

- **`SidecarStore::prune_orphaned_sidecars`** and `SessionStatusStore`/`PendingToolStore` bodies are
  owned by the stores subsystem; F1's fix touches `SidecarStore.php:131-132` but the *caller* is
  session-core. Cross-subsystem coordination required.
- **`PromptParser`/`OpenCodePromptParser`/`AntigravityPromptParser`** parsing shapes and
  `augment_prompt_with_pending_tool()` (`SessionService.php:216-220`) — the `blocked` payload shape
  is defined and owned by `prompt-interaction`.
- **`TranscriptRouter` vs `TranscriptService` vs `OpenCodeTranscriptService` vs
  `AntigravityTranscriptService`** `find_transcript_path` method-style duplication (F3 touches the
  calls, not the classes) — belongs to `session-view`.
- **`SessionLifecycleService`, `BareProcessService`, `ArchivedSessionService`, `SessionDetailService`,
  `QuotaService`, `StatuslineMarkerService`** — all are consumers only; their own behaviors are out
  of scope.
- **`Config` / `SqliteDb`** — the shared env/config layer and schema ownership.
- **`OpenCodeQuestionService::pending_question()` / `to_prompt()`** and the interim DB-poll path in
  `build_session_entry()` (`SessionService.php:152-204`) are opencode-agent concerns owned by the
  opencode adapter subsystem; noted here only because they share `build_session_entry`'s large
  branch.

## Cross-cutting observations (described, not solved)

- **F1's root cause spans two subsystems.** The destructive empty-array branch is in the cross-cutting
  `SidecarStore`, while the failure-conflation that reaches it lives in session-core's
  `list_all_sessions()` + `TmuxService`. The safe fix needs both halves; if the store's `NOT IN ()`
  branch is fixed to be non-destructive on its own, that alone removes the data-loss even before the
  caller's conflation is addressed. Coordinate with whichever subsystem owns `SidecarStore`.
- **The `session_status.last_message` write-only column (F5)** is a data-model concern shared with
  the `session-status-state` hooks (`stop.php`/`permission_request.php` feed it). Fixing the write
  side touches those hook files.
- **`find_transcript_path` is implemented in four classes** (`TranscriptRouter`, `TranscriptService`,
  `OpenCodeTranscriptService`, `AntigravityTranscriptService`) — a session-view refactor candidate to
  unify the resolution, which would also shrink F3.
