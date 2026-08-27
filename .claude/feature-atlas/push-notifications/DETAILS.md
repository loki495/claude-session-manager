---
id: push-notifications
name: Web-push notifications (subscription, delivery, health checks, timer)
owned_paths:
  - host-agent/lib/Services/PushDeliveryService.php
  - host-agent/lib/Services/PushHealthService.php
  - host-agent/lib/Services/PushTimerService.php
  - host-agent/lib/Services/NotificationContentBuilder.php
  - host-agent/lib/Stores/PushSubscriptionStore.php
  - host-agent/lib/Stores/PushSessionStateStore.php
  - host-agent/lib/Stores/PushQuotaStateStore.php
  - host-agent/lib/Stores/GlobalStateStore.php
  - host-agent/push_trigger.php
  - src/lib/Controllers/PushController.php
  - src/lib/Views/PushNotifyView.php
  - src/partials/push-notify/button.php
  - public/js/push-notify.js
  - public/sw.js
  - tests/test_push.php
last_scanned_commit: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# Web-push notifications (subscription, delivery, health checks, timer)

## Identity

- **id:** `push-notifications`
- **name:** Web-push notifications (subscription, delivery, health checks, timer)

## Ownership boundary

**In scope (owned):**

- `host-agent/lib/Services/PushDeliveryService.php` — the VAPID-signed send + both push-trigger passes.
- `host-agent/lib/Services/PushHealthService.php` — the `health_check()` diagnostics backend (and its sub-checks).
- `host-agent/lib/Services/PushTimerService.php` — read/adjust the installed `csm-push-check.timer` interval.
- `host-agent/lib/Services/NotificationContentBuilder.php` — pure title/body text + session-state classification.
- `host-agent/lib/Stores/PushSubscriptionStore.php` — `push_subscriptions` table (persistent `push.sqlite`).
- `host-agent/lib/Stores/PushSessionStateStore.php` — `push_session_state` table (last state + `since`).
- `host-agent/lib/Stores/PushQuotaStateStore.php` — `push_quota_state` table (bucket pct/resets_at + one-shot flags).
- `host-agent/lib/Stores/GlobalStateStore.php` — generic key→JSON store (heartbeat keys, quota_live_state).
- `host-agent/push_trigger.php` — the `csm-push-check` timer's standalone entry point.
- `src/lib/Controllers/PushController.php` — POST JSON endpoints for subscribe/unsubscribe.
- `src/lib/Views/PushNotifyView.php` — renders the "Notify me" button (or '').
- `src/partials/push-notify/button.php` — the button partial.
- `public/js/push-notify.js` — SW registration + subscribe/resubscribe client logic.
- `public/sw.js` — service worker `push`/`notificationclick` handlers.
- `tests/test_push.php` — the push test file.

**Out of scope (neighboring subsystems, named):**

- **`host-agent/lib/Push.php`** — the `dispatch_push_action()` dispatcher. NOT owned here; it is the **entry point** that routes the `push_*` actions (plus `health_check`/`get_push_timer_interval`/`set_push_timer_interval`) into this subsystem's services. Trace-only.
- **`src/partials/health-box/box.php`, `src/partials/health-box/push-timer-interval-control.php`, `src/lib/Views/HealthBoxView.php`** — owned by the **dashboard** subsystem (rendered there at `src/partials/pages/index.php:89`). The *backend* `PushHealthService::health_check()` lives here; the *view* lives in dashboard. See `## Co-owned / cross-subsystem`.
- **`SessionService`** (`host-agent/lib/Services/SessionService.php`) — upstream provider of the live-session rows fed to `check_and_send_pushes()` (via `SessionService::list_all_sessions()`).
- **`QuotaService`** (`host-agent/lib/Services/QuotaService.php`) — upstream provider of the quota buckets fed to `check_and_send_quota_pushes()` (`quota` sub-buckets).
- **`SqliteDb`** (`host-agent/lib/Stores/SqliteDb.php`) — shared PDO/SQLite connection helper + `push_schema()` (not owned here; consumed by all four push stores).
- **`SidecarStore` / `SessionStatusStore` / `PendingToolStore`** — the tmpfs `sessions.sqlite` cluster, the *other* half of the `push.sqlite` vs `sessions.sqlite` split; sibling stores, not owned here.
- **`Config`** — upstream config/paths.
- **`HookService`** — `app_hooks_status()` consumed by `health_check()`.
- **`ProcessRunner`** — consumed by `PushHealthService::opencode_serve_check()` and `PushTimerService::set_push_timer_interval()`.

## Key implementation files

- **`PushDeliveryService.php`** — the core. Owns VAPID config accessors, `send_push_notification()` (the actual minishlink/web-push send), and the two main timer-triggered passes: `check_and_send_pushes()` (session-state transition detection) and `check_and_send_quota_pushes()` (quota near/over/reset transitions).
- **`PushHealthService.php`** — the dashboard health-box backend. `health_check()` aggregates hook/tmux/VAPID/vendor checks plus the push-delivery, quota-delivery, opencode-serve, and opencode-plugin checks, each returned in the `{key,label,ok,detail}` shape.
- **`NotificationContentBuilder.php`** — deliberately I/O-free formatting. Builds every push title/body (blocked/finished/permission/quota-*), and the `push_session_state()` classification that drives transition detection.
- **`PushTimerService.php`** — reads the interval straight from the installed systemd unit file (so it can never drift from what systemd runs), and rewrites `OnBootSec=`/`OnUnitActiveSec=` + daemon-reload/restart.
- **`PushSubscriptionStore.php` / `PushSessionStateStore.php` / `PushQuotaStateStore.php`** — the three persistent tables in `push.sqlite`; subscription storage is the only one a phone needs across reboots (hence persistent vs the tmpfs sidecar DB).
- **`GlobalStateStore.php`** — generic key→JSON blob store; carries the two push-check heartbeat keys and `quota_live_state` (shared with the quota subsystem).
- **`push_trigger.php`** — the `csm-push-check` timer's per-tick entry point: two independent, each-`try/catch`-wrapped passes.
- **`PushController.php`** — thin container-side POST-JSON endpoint pair (`subscribe`/`unsubscribe`) that forwards to the agent over the UNIX-socket protocol.

## Public interfaces & contracts

### `PushDeliveryService.php`

- `vapid_public_key(): string` (`PushDeliveryService.php:25`) — `Config::csm_config('VAPID_PUBLIC_KEY', '')`.
- `vapid_private_key(): string` (`:30`) — `Config::csm_config('VAPID_PRIVATE_KEY', '')`.
- `vapid_subject(): string` (`:41`) — `Config::csm_config('VAPID_SUBJECT', '')`; a mailto:/https: contact point required by the Web Push spec. No default — set it explicitly or the send fails.
- `push_min_working_seconds_for_finish_notify(): int` (`:52`) — `(int)Config::csm_config('PUSH_MIN_WORKING_SECONDS_FOR_FINISH_NOTIFY', '60')`.
- `push_quota_near_threshold_pct(): int` (`:63`) — `(int)Config::csm_config('PUSH_QUOTA_NEAR_THRESHOLD_PCT', '90')`.
- `push_configured(): bool` (`:73`) — `true` iff BOTH public and private VAPID keys are non-empty. When `false`, every push action is a harmless no-op.
- `push_check_status_key(): string` (`:87`) — always `'push_check_status'`.
- `push_quota_check_status_key(): string` (`:101`) — always `'push_quota_check_status'` (separate from the session-pass key so one pass's heartbeat can't clobber the other's).
- `send_push_notification(array $subscription, string $title, string $body, ?string $url = null, ?array $actions = null): array` (`:128`)
  - `$subscription`: `{endpoint:string, keys:{p256dh:string, auth:string}}` (a Web Push subscription object).
  - `$actions`: `{session:string, approve_option:int, deny_option:int}|null` — only ever set for a "blocked" notification, lets `sw.js` render one-tap Approve/Deny.
  - Returns `{ok:bool, expired:bool, message?:string}`. `expired=true` (404/410) means the caller should prune the subscription; a non-expiry failure logs a `message`.
  - Never throws: wraps the minishlink/web-push call in `try/\Throwable` (`:141–171`) because it runs unattended under the systemd timer — an uncaught exception would silently kill the whole tick. Precondition: `push_configured()` else `{ok:false, expired:false, message:'VAPID keys not configured'}`.
- `record_push_check_result(array $sendResults, ?string $statusKey = null): void` (`:201`) — persists `{checked_at:int, sent:int, failed:int, last_failure_message:?string}` to `GlobalStateStore`, and `error_log`s each non-expiry failure. Called every tick (even with nothing to send), so `checked_at` doubles as a timer heartbeat. `$statusKey` defaults to `push_check_status_key()`; the quota pass passes `push_quota_check_status_key()`.
- `approve_deny_actions(string $sessionName, array $promptOptions): ?array` (`:242`) — maps the first option's number (must start with "yes") to `approve_option` and the last option's number (must start with "no") to `deny_option`. Returns `{session, approve_option, deny_option}` or `null` for anything that doesn't genuinely look like a Yes/No choice (checks label prefix, not position alone).
- `check_and_send_pushes(array $sessions, ?int $now = null): array` (`:289`)
  - `$sessions`: list of `build_session_entry()`-shaped rows — `{name:string, blocked_reason?:?string, working?:bool, title?:?string, workdir?:?string, last_message?:?array, prompt_options?:?array}`.
  - `$now`: overridable so tests exercise the duration gate without real sleeps.
  - Returns `{ok:bool, notified:array<int,string>, pruned:int}`. Notifies on INTO `'blocked'` (a fresh transition, so a long-sitting prompt doesn't re-notify every tick) and on `'working'`→`'idle'` only once it's been working continuously ≥ `push_min_working_seconds_for_finish_notify()`. Prunes any subscription a send reports as expired. Always writes `PushSessionStateStore` and `record_push_check_result()`.
- `check_and_send_quota_pushes(?array $quota): array` (`:432`)
  - `$quota`: account-wide `QuotaService::get_quota()` "quota" sub-array (`array<string,{pct:int, resets_at:?int}>`); `null`/`[]` is a harmless no-op.
  - Returns `{ok:bool, notified:array<int,string>}` where each entry is `"{bucketKey}:{title}"`. Fires "near" (pct ≥ threshold, one-shot), "over" (pct ≥ 100, one-shot), and "reset" (wall-clock passes `resets_at`, tracked via a one-shot `notified_reset` flag) per bucket.

### `PushHealthService.php`

- `push_delivery_check(): array` (`:69`) — `{key:'push_delivery', label:'Push delivery', ok:bool, detail:?string}`. Reads `push_check_status_key()`.
- `push_quota_delivery_check(): array` (`:81`) — same shape, key `'push_quota_delivery'`, reads `push_quota_check_status_key()`.
- `opencode_serve_check(): array` (`:98`) — checks the `opencode-serve.service` unit is enabled + active and `/global/health` answers on `:4096`.
- `opencode_plugin_check(): array` (`:145`) — checks the CSM OpenCode plugin file exists at `~/.config/opencode/plugins/csm-permissions.js`.
- `health_check(): array` (`:174`) — returns `{ok:true, checks:array<int,{key:string,label:string,ok:bool,detail:?string}>}`. Aggregates: hook status (via `HookService::app_hooks_status`), tmux socket dir, VAPID push keys, `push_delivery_check()`, `push_quota_delivery_check()`, `opencode_serve_check()`, `opencode_plugin_check()`, and Composer `vendor/autoload.php`.
- `push_status_file_check(...)` (`:29`) — private helper. A VAPID-not-configured state reports `ok:true, detail:'VAPID not configured yet - nothing to check'` (not a false alarm, since "VAPID push keys" is its own separate check); a missing `checked_at` reports the timer never ran; >120s reports the timer may have stopped; `failed>0` reports the failure count + last message.

### `PushTimerService.php`

- `push_timer_unit_path(): string` (`:17`) — `Config::csm_config('PUSH_TIMER_UNIT_PATH', ~/.config/systemd/user/csm-push-check.timer)`.
- `push_timer_unit_name(): string` (`:33`) — `Config::csm_config('PUSH_TIMER_UNIT_NAME', 'csm-push-check.timer')`; overridable so tests never `systemctl` the real timer.
- `push_timer_interval_min_seconds(): int` (`:47`) — `5`; `push_timer_interval_max_seconds(): int` (`:52`) — `300`.
- `get_push_timer_interval(): array` (`:64`) — `{ok:bool, interval_seconds:?int, message?:string}`. Reads the interval straight from the installed unit file; `ok:false` if the unit isn't installed or `OnUnitActiveSec=` can't be parsed.
- `set_push_timer_interval(int $seconds): array` (`:91`) — bounds-checks, rewrites both `OnBootSec=` and `OnUnitActiveSec=`, runs `systemctl --user daemon-reload`, then `restart` **only if** the timer was already active (install.sh leaves it inactive until VAPID keys exist). Returns `{ok:bool, interval_seconds?:int, message?:string}`.

### `NotificationContentBuilder.php`

- `push_session_state(array $session): string` (`:25`) — `'blocked'` if `blocked_reason` non-empty (wins regardless of `working`), else `'working'` if `working`, else `'idle'`.
- `push_notification_title(array $session): string` (`:50`) — prefers the live pane-title, strips a leading `\p{So}` icon glyph, falls back to workdir basename, then the raw session name, then `'Claude session'`.
- `push_truncate(string $text, int $limit = 140): string` (`:81`) — 140-char preview + `…` (matches `BlockedPromptView::last_message_preview_html()`).
- `push_finished_body(?array $lastMessage): string` (`:95`) — the real assistant reply text (truncated) or `'Finished - no input needed'`.
- `push_permission_body(string $toolName, array $toolInput): string` (`:134`) — per-tool body: `Bash` (command, with optional `description:` prefix), `Write`/`Edit` (file path), `ExitPlanMode` (plan's first line, stripped of `# `), generic `"Run <tool>"` fallback.
- `push_blocked_body(array $session): string` (`:206`) — the real command for a matched pending tool (non-AskUserQuestion), else the `blocked_reason` text, always truncated.
- `push_blocked_title(array $session): string` (`:237`) — type-labeled: `Needs folder trust:`, `Has a question:`, `Plan ready for review:`, `Needs permission:`, generic `Needs input:` — each followed by `push_notification_title()`.
- `push_finished_title(array $session): string` (`:269`) — `"Finished: " . push_notification_title()`.
- `push_quota_bucket_label(string $key): string` (`:280`) — maps `session`→`Session`, `week_all`→`Week`, `gemini-weekly`→`Gemini`, `3p-weekly`→`Claude & GPT`, `week_<plan>`→`"<Plan> (week)"`, else `ucwords` of the key. Mirrors `quota-footer.js`'s `label()`.
- `push_quota_near_title/body` (`:308`/`:313`), `push_quota_over_title/body` (`:319`/`:324`), `push_quota_reset_title/body` (`:334`/`:339`) — quota notification text.

### Store classes

- **`PushSubscriptionStore`** (`PushSubscriptionStore.php:19`):
  - `read_push_subscriptions(): array` (`:29`) — `array<int,{endpoint:string, keys:{p256dh:string, auth:string}}>`.
  - `write_push_subscriptions(array $subscriptions): void` (`:42`) — replaces the whole table (transactional, delete-then-insert).
  - `add_push_subscription(array $subscription): bool` (`:66`) — validates endpoint + `keys.p256dh` + `keys.auth` (returns `false` on malformed), then atomic upsert `ON CONFLICT(endpoint) DO UPDATE` — this is what makes the frontend's resubscribe-on-every-open self-heal without duplicating.
  - `remove_push_subscription(string $endpoint): void` (`:84`) — delete by endpoint.
- **`PushSessionStateStore`** (`:19`):
  - `read_push_session_state(): array` (`:29`) — `array<string,{state:string, since:int}>`, keyed by session name, loaded fresh each tick.
  - `write_push_session_state(array $state): void` (`:44`) — transactional replace.
  - `clear_all(): void` (`:65`) — test-only reset of just this table.
- **`PushQuotaStateStore`** (`:32`):
  - `read_push_quota_state(): array` (`:42`) — `array<string,{pct:int, resets_at:?int, notified_near:bool, notified_over:bool, notified_reset:bool}>`.
  - `write_push_quota_state(array $state): void` (`:63`) — transactional replace.
  - `clear_all(): void` (`:93`) — test-only reset.
- **`GlobalStateStore`** (`:17`):
  - `read(string $key): ?array` (`:27`) — decodes `value_json`; `null` on missing key or non-array JSON.
  - `write(string $key, array $value): void` (`:45`) — upsert with `updated_at`.
  - `delete(string $key): void` (`:59`).

### `push_trigger.php`

Standalone script, not a class. Entry flow (`push_trigger.php:52–83`):
1. Exits 0 immediately if `!push_configured()` (`:52`).
2. Session pass: `SessionService::list_all_sessions()['sessions']` → `PushDeliveryService::check_and_send_pushes()`, wrapped in `try/\Throwable` so a crash can't take down the quota pass (`:65–70`, found-live 2026-08-08 `\Error` case).
3. Quota pass (gated on `PUSH_QUOTA_NOTIFICATIONS_ENABLED`): merges both agents' buckets from `QuotaService::get_quota()['agents']` (Claude + Antigravity, found-live 2026-08-24 fix so Antigravity buckets aren't hidden when Claude quota data exists) → `check_and_send_quota_pushes()`, again `try/\Throwable`-wrapped (`:72–82`).

### `PushController.php` / `PushNotifyView.php`

- `PushController::subscribe(): void` (`:21`) — `require_post_json()`; reads `$_POST['subscription']` (JSON-encoded string, decoded to array); malformed → `{ok:false, message:'Malformed subscription'}`; else forwards `{action:'push_subscribe', subscription}` via `AgentClient::agent_call()`. POST-only (a GET registers to the same method and hits `require_post_json()`'s 405).
- `PushController::unsubscribe(): void` (`:40`) — same pattern, forwards `{action:'push_unsubscribe', endpoint}`.
- `PushNotifyView::push_notify_button_html(string $vapidPublicKey, string $csrfToken): string` (`:18`) — renders `push-notify/button` partial, or `''` when `$vapidPublicKey` is empty (nothing useful for the button to do without VAPID).

### `public/js/push-notify.js`

An IIFE, no exports. Registers `/sw.js`, and on every page load: caches the CSRF token in IndexedDB (`csm-push` / `kv` store, `:30–43`); re-POSTs an existing subscription (`:88–96`); feature-detects `serviceWorker`/`PushManager` (`:45`); clears the app badge (`:55–57`); and wires the "Enable notifications" button (`requestPermission` → `pushManager.subscribe({userVisibleOnly:true, applicationServerKey})` → POST to `/push_subscribe.php`, `:112–144`). Uses ES5 only (`var`/`function`, no `const`/`let`).

### `public/sw.js`

- `push` handler (`:83–137`) — parses the JSON payload `{title, body, url, session, approve_option, deny_option}`; always calls `event.waitUntil(showNotification(...))` synchronously (iOS Safari treats no-shown-notification as "silent" and kills the subscription after 3); adds `actions` approve/deny only when both option numbers are present; collects both the `showNotification` and (if supported) `setAppBadge(1)` promises into ONE `Promise.all` in one `waitUntil` (WebKit's documented pattern, found-live fix).
- `notificationclick` handler (`:139–192`) — for an approve/deny action, reads the cached CSRF token (`readPushCsrfToken`, `:23`), `fetch`s `/answer_prompt.php` headlessly with no window opening, falling back to `openOrFocusUrl` when the token/option is unavailable; the plain-tap path opens/focuses the notification URL. Always calls `event.waitUntil(Promise.all(...))`.

## Major call sites

**Entry points (outside owned dir but the flow's origin):**

- **`dispatch_push_action()`** in `host-agent/lib/Push.php:33` — the dispatcher. Routes `push_public_key` (`:36`), `push_subscribe` (`:39`), `push_unsubscribe` (`:50`), `health_check` (`:55`), `get_push_timer_interval` (`:58`), `set_push_timer_interval` (`:61`); returns `null` otherwise so `agent.php` falls through. Invoked by `host-agent/agent.php:38` (the per-connection entry point spawned by systemd socket activation).
- **`host-agent/push_trigger.php`** — the `csm-push-check.timer` (systemd `Type=oneshot`) per-tick entry.

**Container-side callers (other subsystems / the protocol seam):**

- `src/lib/Controllers/DashboardController.php:37–44` (dashboard) — calls `AgentClient::agent_call(['action'=>'push_public_key'])`, `...['action'=>'health_check']`, and `...['action'=>'get_push_timer_interval'])` to populate the dashboard render.
- `src/lib/Controllers/DashboardController.php:160` (dashboard) — `case 'set_push_timer_interval'` forwards `['action'=>'set_push_timer_interval', 'seconds'=>$seconds]`.
- `src/lib/Controllers/SessionController.php:39` (session) — `AgentClient::agent_call(['action'=>'push_public_key'])` to get `$vapidPublicKey` for the compose bar.
- `src/partials/pages/index.php:89` (dashboard) — `HealthBoxView::health_box_html($healthChecks, $pushTimerIntervalSeconds, $csrfToken)`.
- `src/partials/pages/index.php:216` (dashboard footer) — `PushNotifyView::push_notify_button_html(...)`.
- `src/partials/compose-bar.php:61` (session) — `PushNotifyView::push_notify_button_html(...)`.
- `src/lib/AgentClient.php:28` — `agent_call()`, the UNIX-socket one-request/one-response JSON protocol seam to the host agent.

**Host-agent upstream producers:**

- `SessionService::list_all_sessions()` (`SessionService.php:408`) — feeds `check_and_send_pushes()` from `push_trigger.php:66`.
- `QuotaService::get_quota()` (`QuotaService.php`) — feeds the quota pass from `push_trigger.php:74`.
- `HookService::app_hooks_status()` (`HookService.php:123`) — consumed by `PushHealthService::health_check()` (`PushHealthService.php:194`).

## Tests

- **`tests/test_push.php`** — the dedicated test file, run via `php tests/test_push.php` directly or by `tests/run.sh` (it globs `tests/test_*.php`). Isolates everything: `PUSH_TIMER_UNIT_PATH`, `PUSH_SQLITE_FILE`, `PUSH_TIMER_UNIT_NAME` (all pointed at `sys_get_temp_dir()/csm-test-push-*`) and a guard that refuses to run if the real push sqlite path or timer unit name is resolved (`:45–51`). **Happy + sad coverage** — comprehensive:
  - `push_configured` true/false transitions (`:54–64`).
  - `PushSubscriptionStore` read/write/add/remove round-trips, dedupe-by-endpoint, and rejection of malformed/missing-key subscriptions (`:66–96`).
  - `push_session_state` classification incl. blocked-wins-over-working and missing-field defaults (`:98–104`).
  - `check_and_send_pushes` transition detection incl. no-op-when-not-configured, already-blocked-don't-re-notify, resolving-not-a-prompts, re-blocking, and the working→idle duration gate (60s) with `$now` overrides and blocked-takes-priority (`:106–378`).
  - `approve_deny_actions` happy paths (Yes/No, folder-trust wording) + sad paths (no options, single option, non-Yes/No labels → null) (`:144–171`).
  - `push_notification_title` precedence + icon stripping (`:173–183`).
  - `push_finished_body` / `push_permission_body` / `push_blocked_body` / `push_blocked_title` happy + truncation + generic fallback + the found-live 4078-octet oversized-payload truncation (`:185–333`).
  - `send_push_notification` malformed-VAPID-key returns `ok=false` not a crash, plus a real-failed-send against `127.0.0.1:1` that reports gracefully (`:382–413`).
  - `record_push_check_result`/`push_delivery_check` tracing of the failure, the heartbeat (quiet tick, nothing-to-send), the never-ran case, and the VAPID-not-configured non-false-alarm case (`:415–448`).
  - `check_and_send_quota_pushes` near/over/reset transition detection incl. no-op-when-unconfigured/null/empty, one-shot-per-crossing, the found-live reset-detection-via-`resets_at`-vs-pct bug, fresh-window re-arming, and first-ever-observation-no-reset (`:450–578`).
  - `PushTimerService` get/set interval: not-installed, parses unit, rejects below-min/above-max, rewrites both `OnBootSec=`+`OnUnitActiveSec=`, leaves rest untouched (`:580–627`).
  - `dispatch_push_action` routing: null for non-push, `push_public_key`/`push_subscribe`/`push_unsubscribe` happy + malformed/missing-subscription sad paths (`:629–651`).

## Dependencies

**Upstream (consumed):**

- `HostAgent\Services\Config` — `csm_config()` for every VAPID/PUSH_* key; `push_sqlite_path()` for the stores; `home_root()`/`csm_repo_root()`/`claude_settings_path()`/`tmux_socket()` for `PushHealthService`.
- `HostAgent\Stores\SqliteDb` — `SqliteDb::connect()` + `SqliteDb::push_schema()` for all four stores.
- `HostAgent\Services\SessionService`, `HostAgent\Services\QuotaService` — upstream session rows / quota buckets (via `push_trigger.php`).
- `HostAgent\Services\HookService::app_hooks_status()` — `PushHealthService::health_check()`.
- `HostAgent\Services\ProcessRunner` — `opencode_serve_check()` (`systemctl --user is-active/is-enabled`, `curl`) and `set_push_timer_interval()` (`daemon-reload`/`restart`).
- `App\AgentClient` — the container→agent UNIX-socket protocol seam used by `PushController`, `DashboardController`, `SessionController`.
- `App\Views\View` (League Plates engine) — `PushNotifyView`/`HealthBoxView` rendering.
- `App\Assets::versioned_url()` — used by `button.php` for the JS asset URL.

**External packages:**

- `minishlink/web-push` `^9.0` (`composer.json:7`) — VAPID signing + `WebPush`/`Subscription`/`VAPID` classes for `send_push_notification()` and the test's real-keypair generation.

**Downstream (consumed by):**

- **`public/sw.js`** + **`public/js/push-notify.js`** (the web client) — consume the JSON payload `{title, body, url, session, approve_option, deny_option}` produced by `send_push_notification()`, and the `push_public_key` protocol action.
- **Dashboard** (`src/partials/health-box/*`, `HealthBoxView`) — consumes `health_check()` / `push_delivery_check()` / `push_quota_delivery_check()` shapes and the `get_push_timer_interval`/`set_push_timer_interval` actions.

**Coupled (not a class dependency):**

- The **`push.sqlite`** file is persistent (`host-agent/state/push.sqlite`) while **`sessions.sqlite`** is tmpfs — a deliberate split: a phone's subscription shouldn't be lost on reboot, unlike per-session state tied to sessions that don't survive a reboot either.

## Data & schema

Shared schema (from `SqliteDb::push_schema()`, `SqliteDb.php:144–171`) applied to `Config::push_sqlite_path()` (`Config.php:219`, default `host-agent/state/push.sqlite`, overridable via `PUSH_SQLITE_FILE`):

- **`push_subscriptions`** (`:147`) — `endpoint TEXT PRIMARY KEY`, `p256dh TEXT NOT NULL`, `auth TEXT NOT NULL`. Persistent, upsert-on-same-endpoint, delete-by-endpoint.
- **`push_session_state`** (`:152`) — `session_name TEXT PRIMARY KEY`, `state TEXT` (one of `blocked`/`working`/`idle`), `since INTEGER`. Whole-table replacement per tick.
- **`push_quota_state`** (`:157`) — `bucket_key TEXT PRIMARY KEY`, `pct INTEGER`, `resets_at INTEGER` (nullable), `notified_near INTEGER`, `notified_over INTEGER`, `notified_reset INTEGER`. Whole-table replacement per tick.
- **`global_state`** (`:165`) — `key TEXT PRIMARY KEY`, `value_json TEXT`, `updated_at INTEGER`. Generic key→JSON blob store.

**`GlobalStateStore` keys written/read by this subsystem:** `push_check_status` (`{checked_at, sent, failed, last_failure_message}`) and `push_quota_check_status` (same shape). The `quota_live_state` and `antigravity_quota_live_state` keys are written by the **quota** subsystem (via `quota_live_state_write.php` / `antigravity_quota_poll.php`) but live in this same table — shared cross-subsystem.

**VAPID / push config keys** (from `host-agent/.env.example`): `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` (all required to enable; generate the keypair via `Minishlink\WebPush\VAPID::createVapidKeys()`); `PUSH_MIN_WORKING_SECONDS_FOR_FINISH_NOTIFY` (default 60); `PUSH_QUOTA_NEAR_THRESHOLD_PCT` (default 90); `PUSH_QUOTA_NOTIFICATIONS_ENABLED` (default 1, separate kill-switch); `PUSH_TIMER_UNIT_PATH`, `PUSH_TIMER_UNIT_NAME`, `PUSH_SQLITE_FILE` (test/overridable paths). Note: `.env.example` still documents a few now-obsolete plain-JSON state filenames (`PUSH_SUBSCRIPTIONS_FILE`, `PUSH_STATE_FILE`, `PUSH_QUOTA_STATE_FILE`, `PUSH_QUOTA_CHECK_STATUS_FILE`) — those states migrated into the `push.sqlite` tables/`GlobalStateStore` on 2026-08-24, and the code no longer reads those env vars; the doc comment lag is a known drift, not a live behavior.

## Co-owned / cross-subsystem

- **`PushHealthService::health_check()`** lives HERE (backend), but the **view** that renders its output — `src/lib/Views/HealthBoxView.php`, `src/partials/health-box/box.php`, `src/partials/health-box/push-timer-interval-control.php` — is **owned by the `dashboard`** subsystem (rendered at `src/partials/pages/index.php:89`). This subsystem owns the service; the dashboard owns the view. Same split: `set_push_timer_interval` is implemented here (`PushTimerService`) but the form that triggers it lives in the dashboard's health-box partial.
- **`GlobalStateStore`** is **shared with `quota`** (cross-ref): `Config::quota_live_state_key()` / `Config::antigravity_quota_live_state_key()` are written by quota-side scripts into this same `global_state` table, read by `QuotaService::get_quota()`. Push-only keys (`push_check_status`, `push_quota_check_status`) are written by `PushDeliveryService::record_push_check_result()`; but the table itself is shared state, not push-private.
- **`push_trigger.php`** / **`dispatch_push_action()`** is the entry point shared with the **session-core** subsystem (which owns `Sessions.php`'s `dispatch_action()`) — the two dispatchers deliberately split (see `Push.php:21–31` docblock: keeping `PushHealthService::health_check()` here avoids a require-cycle with `Sessions.php`).
- **`SessionService::build_session_entry()`** (session-core) provides the `blocked_reason`/`working`/`title`/`workdir`/`last_message`/`prompt_options` fields that `NotificationContentBuilder::push_session_state()` and `check_and_send_pushes()` consume — a data interlock across subsystems, not a physical one.
- **`QuotaService`** (quota) provides the bucket data that `check_and_send_quota_pushes()` consumes.
