---
id: push-notifications
based_on: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# Web-push notifications (subscription, delivery, health checks, timer) — maintainability audit

Verified against `44e4caab` (HEAD at audit time, same commit `DETAILS.md` was scanned at — no drift detected). The map's line-number claims for every owned file (`PushDeliveryService`, `PushHealthService`, `PushTimerService`, `NotificationContentBuilder`, the four stores, `push_trigger.php`, `PushController`, `PushNotifyView`, `button.php`, `push-notify.js`, `sw.js`, `test_push.php`) all matched the current code on re-read. One small note: `DETAILS.md` correctly flags the `.env.example` stale-env-var comment as known drift; that is doc-lag, not a live behavior, and is not re-flagged here.

Findings ordered by severity (most severe first). Severity ratings are low relative to a commercial product — this is a single-user, LAN-bound personal tool; the items below are ranked by how they can plausibly bite on a real device/timer.

---

## 1. Subscription table is read-modify-write at the service layer — a concurrent subscribe can be clobbered by the timer's prune

**Recommendation:** `fix`
**Evidence:**
- `host-agent/lib/Services/PushDeliveryService.php:299` — `check_and_send_pushes()` snapshots `read_push_subscriptions()`.
- `host-agent/lib/Services/PushDeliveryService.php:363-366` — on any expired endpoint, it filters the stale snapshot and calls `PushSubscriptionStore::write_push_subscriptions($subscriptions)` (whole-table replace).
- `host-agent/lib/Stores/PushSubscriptionStore.php:42-55` — `write_push_subscriptions()` does `DELETE FROM push_subscriptions` then re-inserts whatever it was handed, inside one transaction.
- Concurrent writer: `host-agent/lib/Push.php:46` (`push_subscribe` → `add_push_subscription`) and `host-agent/lib/Stores/PushSubscriptionStore.php:66-82` (single atomic upsert).

**Current complexity / invalid states:** The transaction in `write_push_subscriptions` protects DB-level atomicity of the replace, but not the *higher-level* lost-update: the timer reads the full list, spends up to ~30s/endpoint sending (network timeouts), then rewrites the table from that stale snapshot. If a browser `push_subscribe` (a separate short-lived process, e.g. the frontend's resubscribe-on-every-open) commits a new endpoint between the timer's read and its rewrite, the `DELETE ... INSERT` faithfully removes it. This is the *only* remaining read-modify-write race in this subsystem — the other three stores are fine (see "what's done well"). `push_session_state` and `push_quota_state` are whole-table replaces too, but have a single writer (the timer, serialized by systemd), so they are safe.

**Proposed representation:** Prune by endpoint rather than by snapshot-replace. Either loop `PushSubscriptionStore::remove_push_subscription($endpoint)` over `$expiredEndpoints`, or add a single `DELETE FROM push_subscriptions WHERE endpoint IN (...)` to the store. That turns the prune into an isolated atomic delete that cannot clobber a concurrently-added row, and removes the need to carry the full snapshot forward at all.

**Smallest credible implementation scope:**
- `PushSubscriptionStore.php` — add `remove_push_subscriptions(array $endpoints): void` (prepare `DELETE ... WHERE endpoint IN (?,...)`).
- `PushDeliveryService.php:363-366` and `:543-546` — replace the `array_values(array_filter(...)) + write_push_subscriptions(...)` with the new bulk-delete call. No signature changes to the public `check_and_send_pushes`/`check_and_send_quota_pushes` returns.

**Regression risks / migration concerns:** None behavioral beyond fixing the race; `write_push_subscriptions` becomes unused (could be removed, but leaving it is harmless). Keep it if other future callers want explicit full-replace.

**Validation:** Existing `test_push.php` happy/sad paths for `add`/`remove`/`read` remain green. Add a test that calls `read_push_subscriptions`, adds a new subscription, then runs the prune path and asserts the new subscription survived (simulating the interleave).

**Confidence:** `medium`
**Priority/severity:** `medium`

---

## 2. A transient (non-expiry) send failure permanently swallows the notification — the edge is consumed regardless

**Recommendation:** `fix`
**Evidence:**
- `host-agent/lib/Services/PushDeliveryService.php:321-337` — the blocked / working→idle notification is decided, then the send loop runs once (`:352-359`).
- `host-agent/lib/Services/PushDeliveryService.php:368` — `PushSessionStateStore::write_push_session_state($currentState)` is written **unconditionally at the end of the tick, whether any send succeeded or not**.
- `host-agent/lib/Services/PushDeliveryService.php:321` — the blocked branch only re-notifies when `$previousStateName !== 'blocked'`. Once the transitioned state is persisted, the next tick sees `previous === current` and stays silent.
- Same shape in the quota pass: `:548` persists state after sends regardless of outcome.

**Current complexity / invalid states:** The design is a correct *edge-triggered* state machine for the normal case (a long-sitting prompt must not re-notify every tick). But because the state write is unconditional, a send that fails for a *transient* reason (push service briefly down, a timeout, a 5xx that isn't a 404/410) at the exact transition moment means that notification is lost forever — the edge was consumed, and nothing re-sends it on a later tick when the service recovers. The failure is *not* invisible: `record_push_check_result` (`:209-211`) `error_log`s it and the dashboard's "Push delivery" health box goes red with the failure count and message. But the specific blocked/finished notification never arrives and never retries, which defeats the subsystem's core purpose (iOS has no client-side background-sync, so this timer is the only thing standing between a session blocking and a phone finding out).

**Proposed representation:** Make edge-consumption contingent on delivery. Minimal rule: consume the transition (write state) only if **at least one** send returned `ok=true`, **or** there were no subscriptions to deliver to (nothing is lost). If subscriptions existed but *every* send returned a non-expiry failure, skip the state write for that tick so the next tick re-detects the same transition and retries the send. Expired sends are not "failures" — prune the endpoint, and if another subscription succeeded, consume normally. This preserves the "don't re-notify a still-sitting prompt" invariant while converting a temporary outage from a silent loss into a deferred retry.

**Smallest credible implementation scope:**
- `PushDeliveryService.php` — inside `check_and_send_pushes` (and `check_and_send_quota_pushes`), track whether any `$result['ok']` was observed per tick; gate the `write_push_session_state` / `write_push_quota_state` call on it (or on `$subscriptions === []`).
- Keep the send/record path unchanged.

**Regression risks / migration concerns:** The "already blocked, don't re-notify" and "one-shot quota crossing" tests assert current behavior; a test that has an unreachable subscription (the test's `$unreachableSubscription` against `127.0.0.1:1`) currently expects `notified` to still report the transition (`test_push.php:412-413`). Gating state-write on delivery success means a *subsequent identical* retry-tick would no longer report the transition again in that test — so the new behavior needs its own explicit test (transition + all-send-fail ⇒ retried next tick ⇒ notification delivered once the endpoint recovers), and the existing "still reports the transition even though the send failed" assertion should be reviewed. This is a deliberate semantic change, so it should be flagged to Andres as a behavior change before landing.

**Validation:** Existing quota/session one-shot tests plus a new sad-path: all-sends-fail ⇒ state not consumed; at-least-one-succeeds ⇒ consumed; no-subscriptions ⇒ consumed. Run via `php tests/test_push.php`.

**Confidence:** `medium`
**Priority/severity:** `medium`

---

## 3. `sw.js` approve/deny headless answer swallows a failed `fetch` and does not fall back to opening the app

**Recommendation:** `fix`
**Evidence:**
- `public/sw.js:154-168` — the fetch is `.catch(function () {})` (`:168`).
- `public/sw.js:155-156` — the fallback to `openOrFocusUrl` runs **only** when the CSRF token is missing, the session id is missing, or the option is null — not when the fetch itself fails.

**Current complexity / invalid states:** For an approve/deny tap, the notification is closed (`:150`) before the answer is attempted. If `fetch('/answer_prompt.php', ...)` rejects (network dropped, service worker killed mid-request) **or** resolves with a non-2xx (stale CSRF, the session was already answered → 422, a server 500), the promise settles and the catch is a no-op. The `response.ok` check is entirely absent, so even a 500 counts as "success" from the flow's perspective. Net result: no window opens, no retry, no user feedback — the notification is gone and the prompt stays unanswered. The comment at `:147-148` explicitly states "opening the app to answer it by hand beats silently doing nothing," but the code only honors that for the *missing-token* case, not the *failed-request* case.

**Proposed representation:** In the fetch chain, on rejection or on a non-ok response, fall back to `openOrFocusUrl(notifData.url || '/')` (as the missing-token branch already does). That turns every silent failure into an app open where the user can answer by hand — matching the stated intent.

**Smallest credible implementation scope:**
- `public/sw.js:159-168` — add `.then(res => res.ok ? res : Promise.reject())` (or `if (!res.ok) return openOrFocusUrl(...)`) and change the `.catch(function () {})` to `.catch(function () { return openOrFocusUrl(notifData.url || '/'); })`. The opened-window fallback already clears the badge via `openOrFocusUrl`? (It doesn't clear the badge — but the plain-tap path clears it; see `:185-189`. The approve/deny path clears it at `:173-175` regardless, so that part is fine.)

**Regression risks / migration concerns:** Minor: an extra window only opens in the failure case, which is strictly better than today. No change to the success path.

**Validation:** No JS test harness exists in this project (the suite is PHP-only via `tests/run.sh`), so this path is currently verified manually only. Recommend at minimum a manual check (kill the agent's answer endpoint, or use a stale CSRF token) and, if a browser test harness is ever added, a sad-path case asserting the fallback opens the app. Note: this coverage gap is real and is the direct reason a regression like this went unnoticed — see Out-of-scope.

**Confidence:** `high`
**Priority/severity:** `medium`

---

## 4. Health "timer may not be running" threshold is hardcoded at 120s but the interval is user-configurable up to 300s

**Recommendation:** `fix`
**Evidence:**
- `host-agent/lib/Services/PushHealthService.php:54` — `if ($ageSeconds > 120)` reports `ok=false, "csm-push-check timer may not be running"`.
- `host-agent/lib/Services/PushTimerService.php:52-55` — `push_timer_interval_max_seconds()` returns `300`.
- `host-agent/lib/Services/PushDeliveryService.php:87-104` — the status keys carry only `checked_at`/`sent`/`failed`, no interval; `PushHealthService::push_delivery_check()` (`:69-71`) never reads the configured interval.

**Current complexity / invalid states:** The staleness threshold is a fixed 120s, but the timer interval it's measuring against is a user-facing setting (dashboard control → `set_push_timer_interval`) bounded at 5–300s. With the default 10s interval the 120s slack is generous and correct. But the moment a user sets the interval to, say, 300s (the documented max), every tick is ~300s apart, so the very first health read after a single tick is already `>120s` and the dashboard permanently shows "csm-push-check timer may not be running" — a false alarm that persists for as long as the long interval is configured. The comment at `:51-53` ("generous slack over the default 10s interval regardless of whatever interval is actually configured") encodes an assumption that the interval can't exceed 120s, which the same subsystem's own adjustable bounds violate.

**Proposed representation:** Derive the staleness bound from the configured interval instead of a constant: read it via `PushTimerService::get_push_timer_interval()` (or accept it as a parameter through `push_status_file_check`) and compute a slack multiple (e.g. `interval * 3` with a floor, or `interval + 60s`). That makes the health check honest at every allowed interval. The health check and the interval read live in the same two services, so this is a contained change.

**Smallest credible implementation scope:**
- `PushHealthService.php:29-59` (`push_status_file_check`) — replace the `> 120` constant with `> max(120, 3 * intervalSeconds)`-style logic, reading the interval via `PushTimerService::get_push_timer_interval()` (fall back to the 120s default when the read fails).

**Regression risks / migration concerns:** At the default 10s interval, behavior is identical (120s still applies). Only the previously-broken long-interval case changes. Tests that exercise the "timer may not be running" branch (a manually-written stale `checked_at`) need the interval read to be isolated — point `PUSH_TIMER_UNIT_PATH`/`PUSH_TIMER_UNIT_NAME` at fixtures, which `test_push.php` already does.

**Validation:** Add a test: set the fixture interval to 300 via `PushTimerService::set_push_timer_interval`, write a `checked_at` ~200s ago, and assert `push_delivery_check()['ok']` is still true.

**Confidence:** `high`
**Priority/severity:** `medium`

---

## 5. Notification *titles* are not truncation-guarded against the 4078-octet payload limit that bodies were fixed for

**Recommendation:** `tweak`
**Evidence:**
- `host-agent/lib/Services/NotificationContentBuilder.php:50-73` — `push_notification_title()` returns the live pane title (trimmed, icon-stripped, **not** truncated) or the workdir basename.
- `host-agent/lib/Services/NotificationContentBuilder.php:237-258` — `push_blocked_title()` builds `"Needs permission: {$sessionTitle}"` etc. from that untruncated title.
- `host-agent/lib/Services/NotificationContentBuilder.php:269-272` — `push_finished_title()` is `"Finished: " . push_notification_title()`.
- Contrast: every *body* is truncated to 140 via `push_truncate()` (`:81-86`), and `push_blocked_body`'s 2026-08-08 fix (`:215`, docblock `:188-203`) was exactly to stop a long field blowing the 4078-octet payload limit.

**Current complexity / invalid states:** The 2026-08-08 send failures were pinned on an *unbounded* body. Bodies were then all made 140-char-bounded, but the title path was left unbounded. A very long Claude Code pane title (a verbose task description) or a deep workdir basename prefixed with "Needs permission:"/"Finished:"/"Needs folder trust:" can still push the JSON payload over the hard 4078-octet Web Push limit, producing the same class of send failure, just from the title side. In practice titles are usually short, so this is an edge rather than a live bug — but it is an inconsistency with the codebase's own stated invariant ("every body branch truncates so the whole payload can't blow the limit").

**Proposed representation:** Cap the title component. Either truncate `push_notification_title()`'s session-name portion at a modest bound (e.g. `push_truncate($title, 80)`), or truncate the assembled title inside `push_blocked_title()`/`push_finished_title()`. Truncating just the title portion (80 chars) keeps the type label readable while bounding the payload. The body truncation already ensures the payload never grows again from the body side.

**Smallest credible implementation scope:**
- `NotificationContentBuilder.php` — apply `push_truncate()` to the title string before (or after) prefixing the type label.

**Regression risks / migration concerns:** Only cosmetic; no existing test asserts a >80-char title survives intact. The icon-strip and title-precedence tests (`test_push.php:177-183`) use short titles and remain green.

**Validation:** Add a test with a very long `title` asserting the assembled `push_blocked_title` length is bounded.

**Confidence:** `medium`
**Priority/severity:** `low`

---

## 6. Whole-table-replace of `push_session_state` drops a transiently-absent session, which can cause a duplicate or a missed notification

**Recommendation:** `research-more`
**Evidence:**
- `host-agent/lib/Stores/PushSessionStateStore.php:44-57` — `write_push_session_state()` deletes the whole table and re-inserts only the rows handed to it.
- `host-agent/lib/Services/PushDeliveryService.php:368` — the rows handed in are exactly the `$sessions` from this tick (only currently-live sessions), built from `SessionService::list_all_sessions()` (`push_trigger.php:66`).
- `host-agent/lib/Services/PushDeliveryService.php:308-316` — the previous `since`/`state` is read from that same table; a gap makes `$previousStateName` null.
- `host-agent/lib/Services/SessionService.php:408-457` — `list_all_sessions()` enumerates live tmux sessions; a session absent for a tick is simply not in the array.

**Current complexity / invalid states:** Because the state table is replaced with *only this tick's* live sessions, a session that is momentarily absent from the listing for one tick loses its recorded `state`+`since`. The edge-triggered detection then misbehaves in two ways when it reappears:
- If it reappears still-blocked, `$previousStateName` is null ⇒ treated as a *fresh* transition ⇒ re-notified for the same still-sitting prompt (duplicate).
- If it had been working long and reappeared idle, the carried `since` is gone ⇒ the working→idle "finished long task" notification (`:327-337`) is missed, because `$previousSince !== null` no longer holds.

Likelihood is low: a live cc-* session persists in tmux (including detached), so listing is stable. It only bites on a transient tmux/process-listing failure or a session being torn down/recreated across a tick boundary. Worth a note rather than an immediate fix, because the whole-table replace is also what *correctly* drops genuinely-dead sessions from state, and switching to per-session upsert would require deciding how long to keep a dead session's row.

**Proposed representation (if pursued):** Per-session upsert keyed by `session_name` (insert-or-update per live session, delete rows for sessions that provably no longer exist), so a merely-absent session keeps its last state/`since` for one or more ticks instead of being wiped. Would need an explicit tombstone/age rule to avoid dead-session rows accumulating.

**Smallest credible implementation scope:**
- `PushSessionStateStore.php` — replace the delete-then-insert with a per-row upsert plus an explicit "remove sessions whose tmux session is gone" step driven by the caller.

**Regression risks / migration concerns:** This is a semantic change to dead-session cleanup and would need careful test updates in `test_push.php`'s transition scenarios. Given low likelihood, recommend treating as research, not a near-term change.

**Validation:** Existing transition tests (`test_push.php:121-142`) cover the happy transitions; a new test would simulate a one-tick absent session and assert no duplicate/ no missed notification.

**Confidence:** `low`
**Priority/severity:** `low`

---

## What's done well

- **Correct edge-triggered state machine.** `push_session_state` plus the carried `since` (`PushDeliveryService.php:316`) is the right shape: a long-sitting blocked prompt is notified once, not every tick; the "finished a long task" notification is correctly gated on *continuous* working ≥ the configurable threshold (`:327-337`), so trivial quick replies don't spam. The `$now` override makes the duration gate deterministically testable.
- **`send_push_notification` never throws, and distinguishes expiry from other failure** (`:141-171`). This is exactly right for code that runs unattended under a systemd timer: an uncaught exception would kill the whole tick, and the 404/410-expired path is separated out so the caller prunes rather than retries forever. The `record_push_check_result` heartbeat (`:201-221`) writes a status even on ticks with nothing to send, so "the timer is running" is observable independently of "sends succeed."
- **Two separate `GlobalStateStore` status keys** (`push_check_status` vs `push_quota_check_status`, `:87-104`) correctly prevent one pass's heartbeat from clobbering the other's — a subtle correctness point handled and documented.
- **Persistent `push.sqlite` vs tmpfs `sessions.sqlite` split.** `Config::push_sqlite_path()` (`Config.php:219-222`) and `Config::sessions_sqlite_path()` (`:201-204`) are a deliberate and correct polarity: a phone's subscription survives reboot; per-session state does not need to. This is the right architectural call, and the stores' docblocks explain it clearly.
- **Store-level atomicity where it matters.** `add_push_subscription` is a single atomic upsert (`PushSubscriptionStore.php:66-82`), which is what makes the frontend's resubscribe-on-every-open self-heal without duplicating. The three stores that are whole-table-replace are so with a single writer (the timer), so they are race-free. No cross-DB transaction hazard exists: push.sqlite and sessions.sqlite are independent concerns that never need a joint transaction (enumerated and confirmed).
- **VAPID-not-configured is a safe no-op everywhere** (`push_configured()` gates `push_trigger.php:52`, `check_and_send_pushes:291`, `check_and_send_quota_pushes:434`, and the `=> push-notify button` renders `''`). Bonus: because `check_and_send_pushes` returns early *without* writing session state when unconfigured (`:291-293`), a temporarily-missing key doesn't destroy transition detection — when keys return, previously-unknown transitions are detected fresh.
- **`approve_deny_actions` validates label text, not just position** (`:242-261`, checking the first option starts with "yes" and the last with "no"): a non-Yes/No choice returns `null` and degrades to a plain notification, rather than guessing from position and mapping Approve to something semantically wrong.
- **Subscription lifecycle robustly handled at the client.** `push-notify.js` re-POSTs an existing subscription on every page load to self-heal iOS's silently-dying subscriptions; the CSRF token is cached in IndexedDB (the only store a page and a service worker both share); the SW's `push` handler always calls `waitUntil(showNotification(...))` synchronously and collects badge + notification promises into a single `Promise.all` (WebKit's documented pattern), avoiding iOS's "silent push kills the subscription" trap.
- **Comprehensive PHP test coverage.** `tests/test_push.php` exercises happy **and** sad paths: malformed VAPID keys, a real (failed) send to a closed port, the never-ran-timer case, the VAPID-not-configured non-false-alarm case, one-shot quota crossings, the first-ever-observation-no-reset case, and the reset-via-`resets_at` fix. Found-live regressions (2026-08-08 payload truncation, 2026-08-22 approve/deny, 2026-08-23 reset detection, 2026-08-24 Antigravity bucket merge) are each pinned by a test.
- **Found-live concerns are attended to rather than deferred.** The IPv4-forcing (`:148-154`, IPv6 black-hole), the abort—the quota pass and session pass are each `try/\Throwable`-wrapped independently in `push_trigger.php:65-82` so one crash can't take down the other — and the "leave the tick's other work alive" philosophy are all consistent with the codebase's documented convention.

---

## Out-of-scope (cross-cutting, described not solved)

- **Blocked-state semantics live in `session-core`, not here.** `PushDeliveryService::check_and_send_pushes`'s transition detection consumes `blocked_reason`/`working`/`prompt_options` produced by `SessionService::build_session_entry()` (`SessionService.php:293-326`), which reads blocked/working state *exclusively* from `SessionStatusStore` (the tmpfs `sessions.sqlite` cluster), with `PreToolUse` clearing blocked on every tool-call start. Any stale/false-blocked, blocked-forever, or "resolved without a real answer" behavior originates in that subsystem (and the hook interplay), and the push layer merely follows it. If a duplicate or missed push ever traces back to blocked-state correctness, the fix belongs in session-core, not here. *(touches: `session-core`)*
- **`HealthBoxView` + `health-box/*` partials are `dashboard`-owned** (rendered at `src/partials/pages/index.php:89`), while `PushHealthService::health_check()` is just the backend. Finding #4 is implemented here (the 120s threshold) but *observed* there — fixing the threshold is in this subsystem; the view needs no change.
- **The `dispatch_push_action()` dispatcher (`Push.php`) and `push_trigger.php` are the entry-point seam shared with `session-core`** (`Sessions.php`'s `dispatch_action()` fallthrough, `agent.php:38`). The division of labor (push dispatcher separate to avoid a require-cycle with `Sessions.php`) is sound; changes to routing touch both subsystems.
- **`opencode_serve_check`/`opencode_plugin_check` in `health_check()`** are backed by the opencode systemd unit and the CSM OpenCode plugin, owned by the opencode-integration subsystem. They are surfaced here but their correctness belongs there.
- **`quota_live_state` / `antigravity_quota_live_state` rows live in this subsystem's `global_state` table** but are written by quota-side scripts (`quota_live_state_write.php`, `antigravity_quota_poll.php`). They are shared state, not push-private; the table is a convenient cross-subsystem home, not an ownership claim.
- **No JS/service-worker test harness exists** (the suite is PHP-only via `tests/run.sh`). The `sw.js` approve/deny failure-path gap (finding #3) and the resubscribe-on-every-open behavior are verified manually only. Closing that would require a new, separate JS/browser test subsystem — out of scope here, but worth noting as the reason the SW sad-path regressions have no automated guard.
