---
id: session-lifecycle
based_on: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# Session Lifecycle — Audit

Verified against `44e4caab` (current HEAD; `DETAILS.md` is not stale — every owned
file matches the last-scanned commit). Read all owned files plus the upstream deps
the boundary actually touches (`TmuxService`, `ProcessInspector`, `ProcessRunner`,
`SessionService::browse_dir/create_dir/list_all_sessions`, `SidecarStore`).

The split out of `SessionService.php` (2026-08-24) left the two services clean and
well-documented. What remains is (a) one genuine correctness gap at the bare/managed
boundary, (b) one confirmed stale test assertion that now guards nothing, and (c) the
largest maintainability item: the create and resume flows are ~90% duplicate bodies,
which is the one DRY debt the split didn't consolidate.

The scout's flag on `tests/test_sessions_lifecycle.php:760` is **confirmed real** —
see Finding 2.

---

## Finding 1 — `kill_bare_process()` does not enforce "bare": it can kill a tracked/managed session and skip its cleanup

- **Severity / priority**: `high`
- **Recommendation**: `fix` (add the missing guard). Reasoning: the method's own
  docblock promises it is for a process "that isn't inside a tracked (sidecar-having)
  session", but the code never checks that — the only guard is "is this a running
  `claude` process?". This is a contract mismatch that permits a destructive action
  on state the surrounding subsystem treats as managed.

- **Evidence**:
  - Docblock claim: `host-agent/lib/Services/BareProcessService.php:22-24` ("one
    `ProcessInspector::find_claude_processes()` found running on the host that
    isn't inside a tracked - i.e. sidecar-having ... session").
  - Actual code: `BareProcessService.php:36-47` only sets `$stillRunning` from
    `find_claude_processes()`; nothing checks whether `$owningPane['session']`
    (`:49-52`) belongs to a tracked (sidecar-having) session before
    `tmux_run(['kill-session','-t', $owningPane['session']])` at `:52`.

- **Current complexity / invalid states**: `kill_bare_process` re-validates that the
  pid is a *running claude process* but not that it is an *untracked* one. Via a
  crafted `action=kill_bare&pid=<something>` POST (pids are rendered in managed
  session rows too, so a pid is guessable), a caller can `kill-session` a tracked
  `cc-*`/adopted session. That path bypasses `kill_cc_session()`'s cleanup —
  `SidecarStore::delete_sidecar`, `PendingToolStore::delete_pending_tool`,
  `SessionStatusStore::delete_status` (`SessionLifecycleService.php:301-303`) are
  never called. The orphan sidecar is later healed by
  `prune_orphaned_sidecars()` (`SessionService.php:422`), but the status/pending-tool
  rows are left stale per-managed-session, and the kill is silent about the sidecar
  mismatch. Normal dashboard flow cannot reach this (the `bare[]` set already excludes
  tracked pids, `SessionService.php:441-444`), so this is a boundary/defense-in-depth
  gap rather than a common-path bug — but it is the exact kind of "one more check
  before something destructive" this codebase already does everywhere else
  (`kill_cc_session` re-reads the whitelist at `:289-293`; `kill_bare_process`
  re-scans at `:36-47`).

- **Proposed representation**: after `find_owning_pane()` returns non-null, reject
  when that pane's session is tracked:
  ```php
  if (SidecarStore::read_sidecar($owningPane['session']) !== null) {
      return ['ok' => false, 'message' => 'Rejected: pid is inside a managed session - use the session kill action'];
  }
  ```
  Place it before the `kill-session -t` branch (`:51`). This keeps the SIGTERM path
  (plain, no-tmux bare process — no sidecar possible) unchanged and makes the
  documented contract true.

- **Smallest credible scope**: one guard inserted in `BareProcessService::kill_bare_process()`
  between `:50` and `:52`. No interface or signature change; `kill_bare`'s return
  shape (`{ok:bool, message:string}`) is unchanged.

- **Regression risks / migration**: near-zero. It only rejects a request the current
  UI cannot produce; the documented behavior for real bare processes (untracked, with
  or without a hand-made tmux session) is unchanged. No sidecar/status writes are
  touched.

- **Validation**: existing bare tests (`tests/test_sessions_lifecycle.php:956-1060`)
  all use untracked pids and stay green. Add a sad-path test: create a tracked session,
  then `kill_bare_process(<that pane's claude pid>)` must return `ok=false` with the
  rejection message, and the session + sidecar must still exist.

- **Confidence**: `high`

---

## Finding 2 — Stale test assertion: `kill` claims "sidecar file removed" via a `.json` path that can never exist (confirmed scout flag)

- **Severity / priority**: `high`
- **Recommendation**: `fix` (correct the assertion to check the real invariant; do not
  delete or weaken the test). Reasoning: the assertion is now a tautology — it passes
  whether or not the sidecar is removed, so the suite gives false confidence in the
  one invariant it was written to cover.

- **Evidence**:
  - `tests/test_sessions_lifecycle.php:760`:
    `assert_true(!file_exists(Config::sidecar_dir() . "/{$name}.json"), 'kill: sidecar file removed');`
  - Sidecars are SQLite-only since 2026-08-24: `SidecarStore.php:12,17-24` ("this store
    is SQLite-only now"); the DB file is one `sessions.sqlite`
    (`Config.php:203` `SESSIONS_SQLITE_FILE`), and neither `SidecarStore` nor
    `SessionStatusStore`/`PendingToolStore` writes any per-session `*.json` under
    `sidecar_dir()` anymore. So `sidecar_dir()/{$name}.json` never exists, and the
    assertion is always true even if `SidecarStore::delete_sidecar($name)` regressed.

- **Current complexity / invalid states**: this is the only lifecycle test that
  exercised the "kill removes the sidecar" invariant, and it now exercises nothing.
  A regression in `delete_sidecar()` would pass the entire suite silently. The
  correct codebase idiom already exists one file over:
  `tests/test_opencode_spawn.php:136`:
  `assert_true(SidecarStore::read_sidecar($name) === null, 'kill: opencode sidecar removed');`

- **Proposed representation**: replace the one line with a real SQLite-backed check:
  ```php
  assert_true(SidecarStore::read_sidecar($name) === null, 'kill: sidecar removed from SQLite');
  ```
  (`read_sidecar` returns `null` when absent — `SidecarStore.php:40-71`.)

- **Smallest credible scope**: the single call-site line. No production change.

- **Regression risks / migration**: none. The renamed test still guards exactly the
  original behavior (sidecar no longer present after kill), just against the real store
  instead of a dead file path.

- **Validation**: `tests/test_opencode_spawn.php:131-137` already proves the same
  assertion shape passes for `oc-*`; the updated `:760` line proves it for `cc-*`.
  Re-run `tests/test_sessions_lifecycle.php`.

- **Confidence**: `high`

---

## Finding 3 — `create_cc_session()` and `resume_cc_session()` are ~90% duplicated bodies (the DRY debt the 2026-08-24 split left behind)

- **Severity / priority**: `medium` (maintainability — highest-value refactor item)
- **Recommendation**: `refactor`. Reasoning: the same tmux-spawn wrapper, the same
  300ms-settle + still-alive re-check, and the same eager sidecar write are copy-pasted
  with only the agent argv and the two failure-message strings differing. Any future
  change to the spawn wrapper or the post-spawn confirmation must be made twice and can
  drift.

- **Evidence** (identical blocks, only names/messages differ):
  - tmux wrapper: `SessionLifecycleService.php:81-93` (create) vs `:248-254` (resume) —
    identical `new-session -d -s $name -c $workdir -e CSM_SESSION_NAME=$name -x -y` + argv merge.
  - settle + still-there: `:105-114` (create) vs `:260-269` (resume) — identical
    `usleep(300000)` + `in_array($name, array_column(list_all_tmux_sessions(),'name'), true)`.
  - sidecar write: `:121` (create, `'agent'=>$agent->id()`) vs `:271` (resume,
    `'agent'=>$resumeAgentId`) — identical shape otherwise.

- **Current complexity / invalid states**: ~45 lines of near-verbatim duplication whose
  only genuine variance is (a) the resuming argv (`--resume <id>` vs `--session-id
  <uuid>`/opencode positional) and (b) the create/resume words in the two failure
  messages. The risk is drift: e.g. if someone later adds a real post-spawn
  confirmation step (status-file generation, hook warm-up) to one flow and forgets the
  other, the two "managed session" shapes silently diverge.

- **Proposed representation**: extract a small private helper that owns the shared
  orchestration, leaving only the argv and message text at the call site:
  ```php
  private static function spawn_and_confirm(string $name, string $workdir, array $argv, string $failVerb, string $stillThereMsg): array/*{ok,bool,message,stderr?}*/
  ```
  doing: `tmux_run` → exit check (`Failed to {$failVerb} session: <stderr>`), `usleep`,
  still-alive re-check, and returning success; plus a `write_tracked_sidecar($name,
  $workdir, $claudeSessionId, $agentId)` helper for `:121/:271`. `create_cc_session()`
  and `resume_cc_session()` then keep only: input validation, agent resolution, argv
  construction, `name` from `date('Ymd-His')`, and the final `ok/message` return.
  The two distinct still-there messages (“Working directory is valid…binary starts
  correctly” vs “working directory still exists…binary starts correctly”) stay as the
  two caller-supplied strings, preserving the current UX wording.

- **Smallest credible scope**: `SessionLifecycleService.php` only. No signature change
  on either public method; the two public contracts (`array{ok:bool,message:string}`
  and `array{ok:bool,message:string,name?:string}`) and the exact message strings are
  preserved.

- **Regression risks / migration**: low — pure extraction of verbatim code into one
  helper; order of operations (spawn → exit check → settle → still-there → sidecar
  write → return) is preserved exactly. The flock life time in `resume_cc_session()`
  must remain *around* the helper calls (the lock is held across the whole
  check-spawn-write sequence, `:228-277`), so the helper must be invoked from inside
  the existing `try` block, not from a nested class method that would reorder the
  lock release.

- **Validation**: `tests/test_opencode_spawn.php` (oc-* create + fallback + kill) and
  `tests/test_sessions_lifecycle.php` (`:560-662` create, `:774-837` resume) cover both
  flows end-to-end against the isolated tmux socket; run both. Add a small
  happy-path assertion that resume still writes `spawned_by_csm => true` eagerly if it
  isn't covered (it is, indirectly, via `:788-789` and the lock-order test `:820-845`).

- **Confidence**: `high`

---

## Finding 4 — `take_over_bare_process_with_id()` trusts the client's raw `claude_session_id` without re-running the live-bare exclusion

- **Severity / priority**: `medium`
- **Recommendation**: `research-more` / `fix` (add a guard if confirmed reachable).
  Reasoning: the candidate gatherer deliberately excludes transcripts still being written
  by a *different* live bare process, but the confirm step (which accepts an arbitrary id
  from the client) does not re-check that exclusion, and `resume_cc_session()`'s own
  already-live guard cannot see bare processes because it only reads sidecars.

- **Evidence**:
  - The exclusion is implemented only in the gatherer: `BareProcessService.php:140-160`
    (iterate `list_all_sessions()['bare']`, resolve each other pid's marker id, add it to
    `$trackedIds` so it is filtered out of candidates).
  - The confirm path bypasses it: `BareProcessService.php:257-272`
    (`take_over_bare_process_with_id`) just kills the pid then calls
    `resume_cc_session($workdir, $claudeSessionId)` with the client-supplied id.
  - The only guard inside resume is `claude_session_id_already_live()`
    (`SessionLifecycleService.php:235-237`), which reads sidecars of *tracked* sessions
    only (`:143-158`) — a live bare pane has no sidecar, so it is invisible.

- **Current complexity / invalid states**: if a hand-crafted/or out-of-band confirm sends
  an id that a different live bare process's own statusline-marker currently reports
  (`:155`), the id is a *real, actively-written transcript* that resume would spawn a
  second pane onto — the exact two-panes-one-transcript corruption the sidecar guard
  exists to prevent, but which the sidecar guard structurally cannot detect here. The
  normal picker flow is protected (the id comes out of the pre-filtered candidate list),
  so this is a defense-in-depth gap, not a common-path bug.

- **Proposed representation**: before resuming, re-resolve other live bare pids' marker
  ids (cheap — same walk as `:150-160`) and reject if `$claudeSessionId` equals any of
  them (or pass that excluded-set into `resume_cc_session()` as an optional guard set).
  Simplest: add the same other-bare-id exclusion loop to `take_over_bare_process_with_id()`
  and return `['ok'=>false,'message'=>'That transcript is still owned by another live
  claude process']` if a match is found.

- **Smallest credible scope**: `BareProcessService.php` only; no public signature change.
  Reuse the existing `bare_process_live_claude_session_id()` helper.

- **Regression risks / migration**: low. It only adds a guard on a path that is already
  supposed to be "someone explicitly confirmed a candidate"; picking a live-elsewhere id
  was never valid behavior.

- **Validation**: add a sad-path test: two bare processes, one marker-resolvable to id X;
  call `take_over_bare_process_with_id(pidA, cwd, 'X')` and assert it is rejected and
  that `pidA` is still running (nothing killed on a rejected confirm). Existing happy-path
  coverage `tests/test_sessions_lifecycle.php:1102-1123` uses id `'X'` that is not
  live-elsewhere and stays green.

- **Confidence**: `medium` (the reachability via the UI is nil; the gap is real only
  against crafted requests, but is squarely inside this subsystem's "re-validate fresh
  each request" convention).

---

## Finding 5 — Advisory / minor (no single dominant fix)

- **5a. Resume lock handle leaked if `flock()` contends.** `SessionLifecycleService.php:228-232`
  returns early when `flock(..., LOCK_NB)` fails without `fclose($lockHandle)`. Today host-agent
  is a fresh single-request process (`agent.php`), so the OS reclaims the fd on exit — harmless
  at present. Recommendation: `tweak` — `fclose()` before the early return so a future long-lived
  worker doesn't leak one fd per contended resume. Confidence `high`, severity `low`.

- **5b. `resume_lock_path()`'s sha1 formula is duplicated in the test harness.** The real method is
  private (`SessionLifecycleService.php:177-180`); the contention test re-implements
  `Config::sidecar_dir().'/'.sha1($lockContentionId).'.resume-lock'` at
  `tests/test_sessions_lifecycle.php:820-821`. If the path scheme ever changes, the test silently
  stops exercising the real lock. Recommendation: `tweak` — make `resume_lock_path()` `public static`
  (it is already side-effect free and pure) and have the test call it. Confidence `high`, severity `low`.

- **5c. Same-second `date('Ymd-His')` name collision between any two creates/resumes.** Two calls
  within one clock-second for the *same agent* collide on an identical tmux session name; the second
  `new-session` fails cleanly (`SessionLifecycleService.php:76`, `:242`). Documented in `DETAILS.md`
  and the code comment (`:68-73`); tests `sleep(1)`. Non-destructive (clean failure), but a real
  double-tap on "New Session"/"Resume" yields an ugly "duplicate session" error. Recommendation:
  `research-more` (consider a monotonic suffix or re-read-and-retry on collision), not urgent.
  Confidence `high`, severity `low`.

- **5d. `take_over_bare_process_with_id()` trusts the client-sent `workdir`** rather than re-deriving
  it from the pid (`BareProcessService.php:257-272`). This is a deliberate tradeoff — the pid may
  have exited on its own by the time a human confirms, so its cwd can no longer be read — and the
  workdir only influences where the new pane starts, not a destructive target. Recommendation: `skip`
  (keep current behavior; it is the documented reason for trusting the picker's workdir). Confidence
  `high`, severity `low`.

---

## What's done well

- **The resume `flock()` is a genuinely correct TOCTOU fix, not over-engineering.** Per-id lock file
  (`SessionLifecycleService.php:177-180`), lock-before-check ordering (`:228-237`), held across the
  whole check-spawn-write sequence, and the "never unlink a flock file, keep one persistent path"
  rationale (`:168-176`) are all correct and well-argued.
- **Eager `spawned_by_csm => true` write** (`:121`, `:271`) closes the dashboard-poll race between
  tmux spawn and the SessionStart hook's first fire — a subtle real-world bug prevented by design,
  with the reasoning documented.
- **"Nothing destructive until a human confirms" is actually enforced for take-over.** The
  `needs_choice` branch (`BareProcessService.php:235-244`) kills nothing; the one-click path only
  fires on a marker-confident match (`:225-233`).
- **Re-validate-fresh discipline is applied consistently** on every destructive action
  (`kill_cc_session` whitelist `:289-293`; `kill_bare_process` re-scan `:36-47`),
- **proc_open array-form everywhere** (`ProcessRunner.php:18-40`), no shell-string injection surface.
- **Path-traversal containment is solid upstream** — `browse_dir`/`create_dir` do realpath
  symlink-resolution + home_root containment (`SessionService.php:480-484`, `:531-535`) and restrict
  `name` to a bare basename (`:537-541`). The container's `BrowseController` stays a thin forwarder.
- **Test isolation and the "REFUSE on real socket/state" guards** (`test_opencode_spawn.php:27-43`)
  are disciplined and repeatable.

## Cross-Cutting Observations (described, not solved)

- **No single typed model for a "bare process" row.** The `{pid, cwd, started_at, tmux_session, title}`
  shape is built inline in `SessionService::list_all_sessions()`
  (`host-agent/lib/Services/SessionService.php:439-454`) from `ProcessInspector::find_claude_processes()`
  (`:114-158`) plus pane lookup, and consumed with scattered `?? null` / `(int)` casts in two other
  subsystems: `session-lifecycle` (`BareProcessService.php:150-159`) and `dashboard`
  (`src/lib/Views/SessionRowView.php:107-119`). A `BareProcessInfo` value object (or a documented
  key const + normalizer) produced once by `session-core` and consumed by both would remove the
  repeated shape assumptions and the null-cast noise. **Relevant subsystem ids:** `session-core`
  (owner of the shape), `dashboard`. Not solved here because the shape's owner is upstream.

- **`kill_bare_process()` cannot re-derive "bare" on its own** — deciding tracked-vs-untracked
  requires the sidecar-gated view (`list_tracked_tmux_sessions()` / `list_all_sessions()`), which
  is `session-core`'s (`SessionService.php:410-456`). Finding 1's guard is the cheap fix inside this
  subsystem, but the *authoritative* "is this path managed" predicate lives upstream; consider
  surfacing a small `is_tracked_session(string $name): bool` helper on `session-core` rather than each
  subsystem re-deriving it. **Relevant subsystem ids:** `session-core`.

- **`browse_dir()` can enumerate any directory under `home_root()`** (including `~/.ssh`, `~/.claude`,
  etc.) — intentionally, for this single-user LAN tool, but it means the container's BrowseController
  forwards a path that the host agent then lists over a read-only boundary that is only ever the
  HOME_ROOT containment (`SessionService.php:480-484`). If this app is ever exposed beyond the LAN,
  or if `home_root()` were ever mis-set broad, `session-core`'s containment is the single boundary;
  there is no second layer in `BrowseController`. Worth an explicit note in `session-core`'s own audit,
  not a change here. **Relevant subsystem ids:** `session-core`.

## Out of scope

- Deep logic of `SessionService::browse_dir()`/`create_dir()` (the real traversal guard, upstream in
  `session-core`).
- `SidecarStore`/`SessionStatusStore`/`PendingToolStore` SQLite internals.
- `AgentAdapter` CLI details and `AgentRegistry` wiring (`agent-abstraction`).
- The `.js` take-over picker and ES5-file conventions.
- `home_root`/`www_root` configuration semantics.
