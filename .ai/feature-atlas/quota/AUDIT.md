---
id: quota
based_on: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# Usage quota (Claude statusline + Antigravity `/usage` poll) — maintainability audit

Verified by re-reading the source against `.claude/feature-atlas/quota/DETAILS.md`
(repo HEAD `44e4caab` == DETAILS.md's `last_scanned_commit`, so the map is
current — no meaningful drift). All findings below cite current `file:line`.

Read-side behavior is genuinely well-built; the findings are concentrated in the
two standalone **writer** scripts and one contract gap in the read service.

---

## Findings (ranked by priority)

### 1. `antigravity_quota_poll.php` wipes a previously-good state to "no data" on a SUCCESS response with no parseable bucket

**Recommendation:** `fix` — guard the write so a poll that parsed nothing cannot
destroy the prior good row.

**Evidence**
- `host-agent/antigravity_quota_poll.php:62` — `$state = ['captured_at' => time()];`
- `host-agent/antigravity_quota_poll.php:64-104` — the loop only ever *adds*
  buckets to `$state`; a group/bucket that is not an array, has a null `id`, a
  non-numeric `remaining_fraction`, a null `reset_time`, or an unparseable
  `reset_time` is `continue`d (`:73`, `:81`, `:87`).
- `host-agent/antigravity_quota_poll.php:106` — `GlobalStateStore::write(...)` runs
  **unconditionally**, even when the loop added nothing.
- Compare the sibling writer: `host-agent/quota_live_state_write.php:59-76`'s
  `merge_quota_bucket()` deliberately **preserves** the previous bucket when the
  new one is malformed. The Antigravity script has the symmetric transport-level
  guards (non-zero exit, non-`SUCCESS`, non-array `groups` → `exit(0)` at `:46-60`)
  but **not** the "success but nothing parsed" guard, so it is the one writer of
  the two that can regress state.

**Current complexity / invalid state**
The only trigger the script treats as "harmless no-op" is at the transport layer.
A response that is `status=SUCCESS` and a real `groups` array yet whose buckets
all fail the `:81`/`:87` checks — e.g. an `agy` version bump that changes the
`reset_time` format (`strtotime` → `false`), renames the `remaining_fraction`
field, or returns a transiently partial body — silently overwrites the existing
`antigravity_quota_live_state` row with `{"captured_at": <now>}`. Read side
(`host-agent/lib/Services/QuotaService.php:127-158`) then returns `null`
(no valid buckets), so `get_quota()` reports `ok=false` /
`"No Antigravity quota data yet - csm-antigravity-quota-check timer has not run"`
(`QuotaService.php:285`) and the dashboard/push both stop showing Antigravity
until a later, fully-parseable poll happens to arrive. The wipe is transient and
self-healing, but the message is actively misleading ("timer has not run" when it
ran and then a response didn't parse), and this is the one spot where a real
adapter (Antigravity) can knock out otherwise-good data on a non-exotic input.

**Proposed representation**
Only write when the result is meaningful:
```php
if (count($state) > 1) {  // more than just captured_at
    GlobalStateStore::write(Config::antigravity_quota_live_state_key(), $state);
}
exit(0);
```
This mirrors Claude's `merge_quota_bucket()` preserve contract: a failed/shape-
drifted reading must never destroy the last known-good value. Optionally keep the
previous `captured_at` (not `time()`) so the UI doesn't report a fresh capture of
data that didn't change.

**Smallest credible implementation scope**
- `host-agent/antigravity_quota_poll.php` only (the three-line guard above). No
  interface or read-side change.

**Regression risks / migration concerns**
- Behavior only differs on a previously-overlooked path (success-but-nothing-
  parsed). Normal polls with ≥1 valid bucket are unaffected.
- The one behavioral change: after this fix a shape-drift response keeps the last
  good numbers instead of "no data". This is strictly more useful and cannot
  introduce a new failure mode.

**Validation**
- `tests/test_antigravity_quota_poll.php` covers no-bin, success, and nonexistent-
  binary (`:64-95`) but **not** "SUCCESS with unparseable buckets" — this is the
  exact gap. Add a sad-path case: run the script against a fixture `agy` whose
  `/usage` returns a `groups` array with a bucket missing `remaining_fraction` or
  with an unparseable `reset_time`, then assert the **prior** row is still intact
  (not `null`). Also assert `exit == 0` and empty stdout.

**Confidence:** `high` (code path is unambiguous; the unconditional write at
`:106` is directly readable)

**Priority/severity:** `high`

---

### 2. `quota_live_state_write.php` read-modify-write is not atomic; the docblock's "guarded by SQLite's own transaction" claim is not implemented

**Recommendation:** `refactor` — wrap the read→merge→write in one transaction (or
a conditional upsert), and correct the docblock.

**Evidence**
- `host-agent/quota_live_state_write.php:45` — `$prev = GlobalStateStore::read($key) ?? [];`
- `host-agent/quota_live_state_write.php:59-76` — the merge decision is computed
  in PHP from `$prev`.
- `host-agent/quota_live_state_write.php:92` — `GlobalStateStore::write($key, $merged);`
- `host-agent/lib/Stores/GlobalStateStore.php:19-21` (a fresh `SqliteDb::connect()`
  per call, no transaction) and `:45-57` (single autocommit `INSERT ... ON
  CONFLICT DO UPDATE`). `SqliteDb::connect()` (`:55-76`) sets WAL + busy_timeout but
  issues no `BEGIN`.
- Docblock mismatch: `quota_live_state_write.php:16-17` ("guarded by SQLite's own
  transaction instead of a shell tmp-file-then-mv") and
  `host-agent/lib/Services/StatuslineMarkerService.php:59-60` (same claim). No
  transaction spans the read and the write.

**Current complexity / invalid state**
`GlobalStateStore::read()` and `::write()` are two fully independent statements.
Even though WAL+busy_timeout make a single *write* safe against other *writers*,
the value being merged was read **before** the lock was ever taken. Two statusline
renders racing from two different panes/sessions (each its own PHP process, each
its own cached PDO — `SqliteDb.php:57-59`) can both read `prev pct=40`, both
compute a merge, and interleave so that the last writer publishes a value lower
than a concurrently-computed one — exactly the "visibly jump backward" defect the
merge rule was written to prevent. The rule correctly handles *logically*
out-of-order writes (later-in-clock, stale pane) but not *physically* concurrent
ones. The docblock overstates the protection, which makes the latent race easy to
miss on future edits.

**Proposed representation**
Make the read+merge+write one atomic unit. Minimal shape:
```php
$pdo = SqliteDb::connect(Config::push_sqlite_path(), SqliteDb::push_schema());
$pdo->exec('BEGIN IMMEDIATE');
$prev = GlobalStateStore::read($key) ?? [];   // same connection
// ... merge ...
GlobalStateStore::write($key, $merged);       // same connection
$pdo->exec('COMMIT');
```
(`BEGIN IMMEDIATE` takes the write lock up front, so the read cannot be
re-ordered behind a concurrent writer.) Alternatively, compute the new value with a
single conditional `UPDATE ... WHERE resets_at <> :prev_resets_at OR pct <= :new_pct`
so the decision lives in SQL and is atomic by construction.

**Smallest credible implementation scope**
- `host-agent/quota_live_state_write.php` only; `GlobalStateStore` could expose a
  `read_transactionally`/`update_if` helper rather than inlining the PDO calls.
- Correct the two misleading "guarded by SQLite transaction" docblocks
  (`quota_live_state_write.php:16-17`, `StatuslineMarkerService.php:59-60`) if the
  transaction is not added.

**Regression risks / migration concerns**
- A `BEGIN IMMEDIATE` on every statusline render adds a tiny lock hold; with WAL +
  busy_timeout=5000 this is safe on the existing concurrency model (the hook-vs-
  hook contention `SqliteDb.php:49-53` already expects). No external interface
  change.

**Validation**
- `tests/test_statusline_marker.php:143-197` already exercises the claude merge
  rule end-to-end (fresh write, same-`resets_at`-lower-`pct` ignored, different-
  `resets_at`-lower-`pct` accepted) — that confirms the *logic*; nothing confirms
  the *atomicity*. True concurrency is hard to unit-test deterministically; at
  minimum add a test that drives two sequential queries through the same connection
  while an interleaved writer commits between them, if it can be made deterministic
  with a barrier. If not, at minimum assert the new code path keeps the existing
  merge-rule assertions green.

**Confidence:** `medium` (the race is real but narrow; the doc/impl mismatch is
high-confidence)

**Priority/severity:** `medium`

---

### 3. `opencode_quota_state()` can throw past its connect-time try/catch, violating `get_quota()`'s "no throws" contract

**Recommendation:** `fix` — extend exception coverage to the query execution (or
wrap the whole body).

**Evidence**
- `host-agent/lib/Services/QuotaService.php:183-191` — the `try/catch (\PDOException)`
  wraps only `new \PDO(...)` and the `busy_timeout` `exec`.
- `host-agent/lib/Services/QuotaService.php:193-213` (per-session `SELECT ... FROM
  session WHERE id = ?`) and `:216-235` (dashboard `SELECT COUNT(*)/SUM(...) FROM
  session`) are **outside** the try, with `\PDO::ATTR_ERRMODE =>
  \PDO::ERRMODE_EXCEPTION` set at `:185`.
- Reachable from `get_quota()` dashboard path at `QuotaService.php:361`
  (unconditional) and the per-session 'opencode' path at `:291`.
- `QuotaService.php:148` states the contract: "No `throw`s; failure is surfaced as
  `ok => false` with a `message`."

**Current complexity / invalid state**
`is_file($path)` (`:179`) protects against a *missing* DB, but not against a
present-but-schema-drifted one. `opencode.db` is a fast-moving third-party file
(read via direct SQLite, not the app's own schema). If `OPENCODE_DB_PATH` points at
a database whose `session` table is absent/renamed or whose columns differ, the
`SELECT`/aggregate throws an uncaught `PDOException`. On the dashboard endpoint
this bubbles out of `get_quota()` → out of the `case 'quota'` dispatch
(`host-agent/lib/Sessions.php:174-177`) → `QuotaController::show()`
(`src/lib/Controllers/QuotaController.php:40`) → `AgentClient::agent_call(...)`,
so `GET /quota.php` (the dashboard footer poll) returns an error rather than
`{ok:false,...}`. `push_trigger.php` isolates itself via `try/catch \Throwable`
(`/home/.../push_trigger.php:72-82`), so push degrades to "logged" — but the
dashboard endpoint is not so shielded.

**Proposed representation**
Wrap the query and fetch phase in the same exception guard and return `null` on
failure:
```php
try {
    // connect + the SELECT/aggregate + fetch
} catch (\PDOException $e) {
    return null;
}
```
(or return the current success shape, and surface a message when `null`). This is
what `get_quota()`'s contract already promises.

**Smallest credible implementation scope**
- `host-agent/lib/Services/QuotaService.php` only — move the queries inside the
  existing `try` (or add a second). No interface, schema, or consumer change.

**Regression risks / migration concerns**
- A failure that previously (silently at worst) kept working because the DB was
  well-formed is unaffected; the change only converts a would-be throw into the
  documented `null`/`ok=false` path for the malformed case.

**Validation**
- `tests/test_quota.php` does **not** cover a DB whose `session` table is missing.
  Add a sad-path: point `OPENCODE_DB_PATH` at a fixture DB with one unrelated table
  (or an empty byte-valid sqlite file), assert `get_quota()` returns `ok=false` /
  the opencode agent `quota === null` and does **not** throw. (Existing test at
  `tests/test_quota.php:41` creates an `opencodeDbFixture` but never writes a
  `session` table to it — so the *empty-DB* branch on the dashboard path already
  needs the aggregate to be exercised.)

**Confidence:** `high`

**Priority/severity:** `medium`

---

### 4. No staleness signal for the event-driven Claude quota (or a never-fired/failed Antigravity timer)

**Recommendation:** `research-more` → likely `tweak` — surface the age of the
data, and stop hardcoding `stale` to `false`.

**Evidence**
- `host-agent/lib/Services/QuotaService.php:268-270, 276-278, 283-285, 312-315,
  329-332, 340-342, 394-396` — `'cached' => false, 'stale' => false,
  'refreshing' => false` hardcoded; docblock `:146-147` says these are "kept for
  frontend compatibility."
- `public/js/quota-footer.js:344-346` reads `data.cached`/`data.stale`/`data.refreshing`
  but they can never be true.
- `public/js/quota-footer.js:260-261, 292, 348` — the only staleness affordance is
  an inert `el.title` "Captured X ago" tooltip.
- The freeze is structural and documented: `PushDeliveryService.php:396-410`
  explains `quota_from_statusline_state()` is event-driven and "only ever updated
  by a LIVE session's own statusLine render... with no session open, pct/resets_at
  both sit frozen at whatever was last observed." It also explains why the *push*
  side is safe against that (real-epoch `resets_at`, `time()`-compared, no fresh
  render needed). The **UI** has no equivalent safeguard.
- The Antigravity `ok=false` message ("timer has not run",
  `QuotaService.php:285/376`) is inaccurate once the timer *has* run but a poll
  failed to produce a parseable bucket (also see Finding 1).

**Current complexity / invalid state**
For a Claude session with no recent activity, the footer can display a `pct` captured
hours ago and, because `stale` is hardcoded `false`, present it with the same
visual weight as a fresh reading. The only hint is a hover tooltip no one is
guaranteed to read. After Finding 1's fix, a lasting Antigravity poll failure would
similarly present old numbers (old `captured_at`) without a "data is stale" cue.

**Proposed representation**
Compute a `stale` flag from age rather than hardcoding it: `stale = now - captured_at
> STALE_AFTER` (e.g. 30–60 min) in `get_quota()` for the claude/antigravity agents,
and have `quota-footer.js` render an amber "stale" chip (it already has the `stale`
branch dead at `:345`). This keeps the existing shape and makes the pre-existing,
already-contracted field actually meaningful.

**Smallest credible implementation scope**
- `host-agent/lib/Services/QuotaService.php` — set `stale` from `fetched_at` age.
- `public/js/quota-footer.js` — render the `stale` chip that is currently dead
  code.

**Regression risks / migration concerns**
- Purely additive; `stale` was already in the contract and already consumed
  (dead) in the client. No consumer reads it as `false`-meaning-`"fresh"`.
- Choose the threshold carefully — a background `captured_at` that's legitimately
  a few minutes old during a single long session must not flash "stale".

**Validation**
- Unit: assert `get_quota()` (claude agent) sets `stale=true` when
  `captured_at` is older than the threshold and `false` when fresh. Sad-path: a
  row with a very old `captured_at` must still return a valid `quota` (not null)
  with `stale=true`, so the existing "partial read" tests stay green.
- `tests/test_quota.php` and `tests/test_sessions_lifecycle.php:1847-1914` are the
  natural homes.

**Confidence:** `medium` (real, documented gap; partly-by-design per
`PushDeliveryService.php:396-410`, so framed as a UI-surfacing tweak rather than a
bug)

**Priority/severity:** `low`

---

### 5. `quota_from_statusline_state()` and `antigravity_quota_state()` are near-duplicate parsers; dashboard aggregation repeats a first-non-null fallback

**Recommendation:** `refactor` — one shared bucket-normalizer, one first-non-null
helper.

**Evidence**
- `host-agent/lib/Services/QuotaService.php:87-113` and `:127-158` — identical
  shape: read `GlobalStateStore`, bail unless `captured_at` is int, loop buckets
  applying an `is_array/isset/is_int(pct)/is_int(resets_at)` filter (the only
  deltas: statusline iterates a fixed `['session','week_all']` list, Antigravity
  iterates all keys except `captured_at` and adds `group_name`), return
  `['quota'=>..., 'fetched_at'=>...]` with `date('c', $fetchedAt)`.
- `host-agent/lib/Services/QuotaService.php:387-398` — the dashboard picks
  `$claudeLive['quota'] ?? ($agLive['quota'] ?? ($ocLive['quota'] ?? null))` and
  repeats the same `??` chain for `fetched_at` and `ok`.

**Current complexity / invalid state**
Any change to `captured_at` validation or the bucket-validation rule must be
applied twice, and the two parsers have already drifted slightly (fixed-vs-any key
list, plus `group_name`). The dashboard's three-way `?? [?? null]` chains are the
fourth and fifth copy of a first-non-null-array pattern.

**Proposed representation**
A private `normalize_buckets(array $decoded, bool $withGroupName): array` returning
the validated bucket map (without `captured_at`), and a
`firstNonNull(...$candidates)` helper. Collapses ~50 duplicated lines and removes
the drift risk. This is a low-risk mechanical refactor.

**Smallest credible implementation scope**
- `host-agent/lib/Services/QuotaService.php` only; behavior identical.

**Regression risks / migration concerns**
- Near-zero if behavior is preserved; the existing narrow tests
  (`tests/test_quota.php:96-154`) should stay green unchanged as the safety net.

**Validation**
- Rely on `tests/test_quota.php`'s existing happy/sad bucket cases
  (pct-as-string treated absent, missing `resets_at` absent, partial single-bucket
  read, `group_name` preserved).

**Confidence:** `high`

**Priority/severity:** `low`

---

## What's done well

- **`captured_at` is validated strictly and treated as absent, not coerced.**
  `quota_from_statusline_state()` requires `captured_at` to be an int and a bucket's
  `pct`/`resets_at` to be ints (`QuotaService.php:91-103`, `:131-148`); malformed
  data degrades to `null`/partial-read rather than a crash or a fabricated number.
  `tests/test_quota.php:98-116` pins exactly those sad paths.
- **The writer scripts keep the "never disrupt the statusline / never crash"
  discipline.** Both exit 0 and write nothing to stdout
  (`quota_live_state_write.php:27-29`, `antigravity_quota_poll.php:46-60`), and
  the Antigravity script has correct transport-level no-op guards and a
  `--print-timeout 30s` safety net (`:42-44`).
- **The Claude merge rule is the right idea and is well-tested.** Accept a lower
  `pct` only on a `resets_at` rollover; preserve prev on malformed input
  (`quota_live_state_write.php:59-76`); covered end-to-end by
  `tests/test_statusline_marker.php:143-197`.
- **Read-side contract is consistently "no throw, surface `ok=false`."** The
  per-agent/dashboard `get_quota()` shape (`:254-399`) and its `cached/stale/
  refreshing` placeholders keep the frontend contract stable even though the
  cache/background-refresh machinery was deleted.
- **The two-runtime seam is respected.** The container never touches quota state
  directly — `QuotaController::show()` goes over the socket (`QuotaController.php:40`)
  to the host-agent's `case 'quota'` (`Sessions.php:174-177`), and only the
  host-native side reads `GlobalStateStore`/tmux/opencode.
- **`quota-footer.js` is clean ES5** — `var`/`function`/string-concat only, no
  arrows/`const`/templates/`Set` (`public/js/quota-footer.js` throughout). The
  `.finally()` usage (`:390`) is the codebase-wide convention (`common.js`,
  `session.js`, `index.js` all use it), so it is not an ES5 deviation here. A
  `loading` guard (`:376-380`) prevents polling pile-up, and localStorage is
  wrapped in try/catch.
- **`test_antigravity_quota_poll.php` runs the real script as a subprocess** with a
  `fake_agy` fixture that distinguishes `-p`/print mode from the interactive TUI,
  and asserts the pct conversion (remaining 0.75 → used 25; remaining 1 → used 0)
  and `reset_time`→epoch parsing.
- **`push_trigger.php` is defensively wrapped** — the quota pass sits in its own
  `try/catch \Throwable` (`:72-82`), so a quota failure cannot take down the
  session-transition pass (the exact incident the comment at `:56-64` records).

## Out of scope

- **`StatuslineMarkerService`'s quota-capture block** (`:38-39`, `:176-247`,
  `:428-447`, `:474-485`, `:491-530`) — owned by `session-status-state`, co-reported
  only. The install/upgrade/stale-body-replace logic and its install/fallback
  writing are not re-audited here.
- **`PushDeliveryService::check_and_send_quota_pushes()`**
  (`push_trigger.php`/`PushDeliveryService.php:432-552`) — owned by
  `push-notifications`. Its reset/near/over state machine and `PushQuotaStateStore`
  are not in scope; only its read-side coupling to quota is recorded below.
- **`GlobalStateStore` / `push.sqlite` schema** (`SqliteDb.php:144-171`) — shared
  with `push-notifications`. The quota keys are distinct rows, so there is no row
  collision; the shared file is the only coupling and is correctly documented.
  No row-level conflict found.
- **`SessionStatusStore`** — named in the boundary as an upstream dependency but
  not read by the quota path; not audited.
- **OpenCode itself** — `opencode.db` is read directly, read-only, WAL
  (`Config.php:224-234`); the OpenCode transcript/adapter subsystem is separate.

## Cross-cutting observations (described, not solved)

- **`push_trigger.php:74-79` reverse dependency.** The push pass calls
  `QuotaService::get_quota()` (dashboard shape, no session) and reads
  `['agents']['claude']['quota']` + `['agents']['antigravity']['quota']`, then
  merges with `$claudeQuota + $antigravityQuota`. Two notes, both for
  `push-notifications` to consider, not for this subsystem to act on:
  - It calls the **dashboard** `get_quota()`, which also invokes
    `opencode_quota_state()` (`QuotaService.php:361`) and opens `opencode.db` on
    every push tick — work the push consumer immediately discards (it only uses
    claude+antigravity). A narrower `QuotaService::quota_for_push()` returning just
    the two buckets would drop the opencode read and decouple the shape.
  - The coupling is via an **untyped array shape** (`agents.*.quota` keys
    `session`/`week_all`/`gemini-weekly`/`3p-weekly`/`captured_at`). The `+` union
    keeps the left side's `captured_at` when both agents have data, which is
    cosmetic here but is the kind of silent-key-collision a shared typed model
    would prevent. No ordering bug: the quota pass is a pure read independent of the
    session-transition pass and separately wrapped.
- **The shared `captured_at`-in-`quota` convention** (both the statusline and
  Antigravity readers place an ISO string `captured_at` inside the bucket *map*,
  unlike the sibling `fetched_at` field) is why `check_and_send_quota_pushes()` must
  skip non-bucket keys (`PushDeliveryService.php:447-450`). Not a bug, but the
  mixed "bucket map + metadata string" shape is the reason both the push consumer
  and the JS renderer need special-casing for `captured_at` (`quota-footer.js:316`),
  and is worth revisiting if a shared quota model ever emerges.
