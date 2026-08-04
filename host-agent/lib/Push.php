<?php
declare(strict_types=1);

/**
 * Web Push notifications - lets a session's newly-blocked prompt reach
 * the phone without the tab open and polling. Server/host-triggered (see
 * host-agent/push_trigger.php, run periodically by the csm-push-check
 * systemd timer): iOS Safari has no working client-side background-sync
 * mechanism, so detecting a session transitioning INTO a blocked state
 * has to happen here, not in the browser. Uses minishlink/web-push
 * (Composer) for the actual VAPID-signed send.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\HookService;
use HostAgent\Services\NotificationContentBuilder;
use HostAgent\Services\ProcessRunner;
use HostAgent\Stores\PushSessionStateStore;
use HostAgent\Stores\PushSubscriptionStore;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

function vapid_public_key(): string
{
    return Config::csm_config('VAPID_PUBLIC_KEY', '');
}

function vapid_private_key(): string
{
    return Config::csm_config('VAPID_PRIVATE_KEY', '');
}

function vapid_subject(): string
{
    return Config::csm_config('VAPID_SUBJECT', 'mailto:dasc495@gmail.com');
}

/**
 * How long a session must have been continuously 'working' before its
 * transition to 'idle' (finished, nothing left needing input) is worth a
 * push notification for - avoids notifying for every trivial quick reply,
 * only for something that actually took a while.
 */
function push_min_working_seconds_for_finish_notify(): int
{
    return (int)Config::csm_config('PUSH_MIN_WORKING_SECONDS_FOR_FINISH_NOTIFY', '60');
}

/**
 * False (and every push-related action a harmless no-op) until VAPID
 * keys are actually generated and set - see README for the one-time
 * generation step.
 */
function push_configured(): bool
{
    return vapid_public_key() !== '' && vapid_private_key() !== '';
}

/**
 * Last-tick outcome of check_and_send_pushes() - written on EVERY tick
 * (even ones with nothing to send), so its timestamp doubles as a
 * heartbeat proving the csm-push-check timer is actually running, not
 * just that sends succeed when attempted. Read back by health_check() for
 * the dashboard.
 */
function push_check_status_file(): string
{
    return Config::csm_config('PUSH_CHECK_STATUS_FILE', Config::csm_repo_root() . '/host-agent/state/push-check-status.json');
}

/**
 * Sends one push notification to one subscription. Returns whether the
 * subscription is still good - a 404/410 means the browser/OS has
 * permanently discarded it, the caller should prune it rather than retry
 * it forever (iOS's own subscription lifecycle is especially prone to
 * this - see the README).
 *
 * @param array{endpoint:string, keys:array{p256dh:string, auth:string}} $subscription
 * @return array{ok:bool, expired:bool, message?:string}
 */
function send_push_notification(array $subscription, string $title, string $body, ?string $url = null): array
{
    if (!push_configured()) {
        return ['ok' => false, 'expired' => false, 'message' => 'VAPID keys not configured'];
    }

    // minishlink/web-push validates VAPID key format (and can throw for
    // other reasons too) - caught rather than left to propagate, since
    // this runs unattended via the csm-push-check systemd timer: an
    // uncaught exception here would silently kill that whole tick (every
    // other session's transition check included) rather than just this
    // one send failing. Found live while testing against a deliberately
    // malformed key: it's a hard ErrorException, not a normal return.
    try {
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => vapid_subject(),
                'publicKey' => vapid_public_key(),
                'privateKey' => vapid_private_key(),
            ],
        ], [], 30, [
            // Found live: IPv6 to web.push.apple.com can silently black-hole
            // on this network (times out after the full 30s) while IPv4 to
            // the exact same endpoint responds instantly - forcing IPv4
            // avoids paying that timeout on every send.
            'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
        ]);

        $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url]);

        $report = $webPush->sendOneNotification(
            Subscription::create($subscription),
            $payload !== false ? $payload : null
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'expired' => false, 'message' => $e->getMessage()];
    }

    if ($report->isSuccess()) {
        return ['ok' => true, 'expired' => false];
    }

    return [
        'ok' => false,
        'expired' => $report->isSubscriptionExpired(),
        'message' => $report->getReason(),
    ];
}

/**
 * Persists the outcome of one check_and_send_pushes() tick and logs any
 * non-expiry failure to the journal (via error_log(), which csm-push-
 * check.service's default StandardError=journal already routes there) -
 * previously the ONLY trace of a failed send was silently pruning an
 * expired subscription; anything else (malformed payload, the push
 * service unreachable, a bad VAPID key) left no record anywhere. Called
 * every tick regardless of whether there was anything to send, so
 * "checked_at" also works as a heartbeat - see push_check_status_file().
 *
 * @param array<int, array{ok:bool, expired:bool, message?:string}> $sendResults
 */
function record_push_check_result(array $sendResults): void
{
    $failures = array_values(array_filter(
        $sendResults,
        fn(array $r): bool => !$r['ok'] && !$r['expired']
    ));

    foreach ($failures as $failure) {
        error_log('csm-push-check: send failed - ' . ($failure['message'] ?? 'unknown reason'));
    }

    $status = [
        'checked_at' => time(),
        'sent' => count($sendResults),
        'failed' => count($failures),
        'last_failure_message' => $failures !== [] ? ($failures[count($failures) - 1]['message'] ?? 'unknown reason') : null,
    ];

    $dir = dirname(push_check_status_file());

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    @file_put_contents(push_check_status_file(), json_encode($status));
}

/**
 * The main push-trigger pass, called on every csm-push-check timer tick
 * (see host-agent/push_trigger.php): for every currently-live session,
 * compares its current push_session_state() against what was last
 * recorded (including how long it's been in that state, tracked via
 * "since" - see read_push_session_state()), and sends a push to every
 * stored subscription for either of two transitions:
 *
 * - INTO 'blocked' (a new prompt appeared) - the transition matters, not
 *   the state itself, so a prompt that's been sitting unanswered for an
 *   hour doesn't re-notify on every tick.
 * - FROM 'working' INTO 'idle', but only once it's been working
 *   continuously for at least push_min_working_seconds_for_finish_notify()
 *   - a session finishing a genuinely long task without needing any
 *   input at all previously had NO notification coverage whatsoever (only
 *   the "needs input" case did); the duration gate avoids notifying for
 *   every trivial quick reply.
 *
 * Also prunes any subscription a send reports as permanently expired.
 *
 * @param array<int, array{name:string, blocked_reason?:?string, working?:bool, title?:?string, workdir?:?string, last_message?:?array}> $sessions
 * @param int|null $now defaults to time() - overridable so tests can
 *   exercise the duration gate without real sleeps
 * @return array{ok:bool, notified:array<int, string>, pruned:int}
 */
function check_and_send_pushes(array $sessions, ?int $now = null): array
{
    if (!push_configured()) {
        return ['ok' => false, 'notified' => [], 'pruned' => 0];
    }

    $now ??= time();
    $previousState = PushSessionStateStore::read_push_session_state();
    $currentState = [];
    $notified = [];
    $subscriptions = PushSubscriptionStore::read_push_subscriptions();
    $expiredEndpoints = [];
    $sendResults = [];
    $minWorkingSeconds = push_min_working_seconds_for_finish_notify();

    foreach ($sessions as $session) {
        $name = (string)$session['name'];
        $state = NotificationContentBuilder::push_session_state($session);

        $previousEntry = is_array($previousState[$name] ?? null) ? $previousState[$name] : null;
        $previousStateName = is_string($previousEntry['state'] ?? null) ? $previousEntry['state'] : null;
        $previousSince = is_int($previousEntry['since'] ?? null) ? $previousEntry['since'] : null;

        // Carries the "since" timestamp forward as long as the state
        // itself hasn't changed - this is what lets a later tick compute
        // "how long has it actually been in this state" rather than just
        // "not the same as last tick".
        $since = ($previousStateName === $state && $previousSince !== null) ? $previousSince : $now;
        $currentState[$name] = ['state' => $state, 'since' => $since];

        $notification = null;

        if ($state === 'blocked' && $previousStateName !== 'blocked') {
            $notification = [
                'title' => NotificationContentBuilder::push_blocked_title($session),
                'body' => NotificationContentBuilder::push_blocked_body($session),
            ];
        } elseif (
            $state === 'idle'
            && $previousStateName === 'working'
            && $previousSince !== null
            && ($now - $previousSince) >= $minWorkingSeconds
        ) {
            $notification = [
                'title' => NotificationContentBuilder::push_finished_title($session),
                'body' => NotificationContentBuilder::push_finished_body(is_array($session['last_message'] ?? null) ? $session['last_message'] : null),
            ];
        }

        if ($notification === null) {
            continue;
        }

        // $notified reflects the transition itself, independent of
        // whether there's actually anyone subscribed to receive it -
        // keeps "was a transition detected" and "did a send happen"
        // separately observable/testable, and means a fresh install with
        // zero subscriptions yet still correctly tracks state instead of
        // silently skipping the bookkeeping too.
        $notified[] = $name;

        if ($subscriptions !== []) {
            foreach ($subscriptions as $subscription) {
                $result = send_push_notification($subscription, $notification['title'], $notification['body'], '/session.php?session=' . urlencode($name));
                $sendResults[] = $result;

                if ($result['expired']) {
                    $expiredEndpoints[] = $subscription['endpoint'];
                }
            }
        }
    }

    if ($expiredEndpoints !== []) {
        $subscriptions = array_values(array_filter($subscriptions, fn(array $s): bool => !in_array($s['endpoint'], $expiredEndpoints, true)));
        PushSubscriptionStore::write_push_subscriptions($subscriptions);
    }

    PushSessionStateStore::write_push_session_state($currentState);
    record_push_check_result($sendResults);

    return ['ok' => true, 'notified' => $notified, 'pruned' => count($expiredEndpoints)];
}

/**
 * health_check() entry for the csm-push-check timer itself - not just
 * "are VAPID keys configured" (that's its own separate check, a
 * prerequisite rather than this), but whether the timer is actually
 * running AND whether its most recent tick's sends succeeded. Reads the
 * status record_push_check_result() writes every tick.
 *
 * @return array{key:string, label:string, ok:bool, detail:?string}
 */
function push_delivery_check(): array
{
    $key = 'push_delivery';
    $label = 'Push delivery';

    if (!push_configured()) {
        return ['key' => $key, 'label' => $label, 'ok' => true, 'detail' => 'VAPID not configured yet - nothing to check'];
    }

    $raw = @file_get_contents(push_check_status_file());
    $status = $raw !== false ? json_decode($raw, true) : null;

    if (!is_array($status) || !is_int($status['checked_at'] ?? null)) {
        return ['key' => $key, 'label' => $label, 'ok' => false, 'detail' => 'csm-push-check timer has never run - is it installed and enabled?'];
    }

    $ageSeconds = time() - $status['checked_at'];
    $failed = (int)($status['failed'] ?? 0);

    if ($failed > 0) {
        $message = is_string($status['last_failure_message'] ?? null) ? $status['last_failure_message'] : 'unknown reason';

        return ['key' => $key, 'label' => $label, 'ok' => false, 'detail' => "Last check {$ageSeconds}s ago: {$failed} send(s) failed - {$message}"];
    }

    // A stale timestamp means the timer itself has stopped ticking, not
    // that sends are failing - worth its own message rather than reading
    // as a false "all good". 120s is generous slack over the default 10s
    // interval regardless of whatever interval is actually configured.
    if ($ageSeconds > 120) {
        return ['key' => $key, 'label' => $label, 'ok' => false, 'detail' => "Last check was {$ageSeconds}s ago - csm-push-check timer may not be running"];
    }

    return ['key' => $key, 'label' => $label, 'ok' => true, 'detail' => "Last check {$ageSeconds}s ago, no failures"];
}

/**
 * Path to the INSTALLED csm-push-check.timer unit - NOT the repo template
 * at host-agent/systemd/csm-push-check.timer, which install.sh only ever
 * copies from once. Editing the template would silently do nothing until
 * a manual reinstall; this is the file systemd actually reads.
 */
function push_timer_unit_path(): string
{
    return Config::csm_config('PUSH_TIMER_UNIT_PATH', Config::home_root() . '/.config/systemd/user/csm-push-check.timer');
}

/**
 * The systemd unit NAME passed to `systemctl --user`, separately
 * overridable from push_timer_unit_path() above - tests need to isolate
 * this too, not just the file path, since set_push_timer_interval() runs
 * real `systemctl --user is-active`/`restart` commands: without an
 * override, a test running on this same machine would query/restart the
 * REAL production csm-push-check.timer, not a fixture. Pointing this at a
 * name systemd has never heard of makes `is-active` reliably report
 * "inactive" (not "active"), which is what keeps the restart branch in
 * set_push_timer_interval() from ever firing in tests.
 */
function push_timer_unit_name(): string
{
    return Config::csm_config('PUSH_TIMER_UNIT_NAME', 'csm-push-check.timer');
}

/**
 * Bounds on the adjustable interval. Floor avoids hammering systemd/
 * journald for no real latency benefit below ~1-5s granularity (see the
 * shipped unit's own comment on why 10s was chosen); ceiling keeps a
 * "forgot I changed this" mistake from silently making notifications
 * near-useless, given iOS has no client-side background-sync mechanism -
 * this timer is the ONLY thing standing between a session blocking and
 * the phone finding out.
 */
function push_timer_interval_min_seconds(): int
{
    return 5;
}

function push_timer_interval_max_seconds(): int
{
    return 300;
}

/**
 * Reads the interval straight from the installed unit file (not some
 * separately-tracked setting) so this can never drift from what systemd
 * is actually running.
 *
 * @return array{ok:bool, interval_seconds:?int, message?:string}
 */
function get_push_timer_interval(): array
{
    $raw = @file_get_contents(push_timer_unit_path());

    if ($raw === false) {
        return ['ok' => false, 'interval_seconds' => null, 'message' => 'csm-push-check.timer is not installed - see the README'];
    }

    if (!preg_match('/^OnUnitActiveSec=(\d+)s\s*$/m', $raw, $m)) {
        return ['ok' => false, 'interval_seconds' => null, 'message' => 'Could not parse OnUnitActiveSec from the installed timer unit'];
    }

    return ['ok' => true, 'interval_seconds' => (int)$m[1]];
}

/**
 * Rewrites both OnBootSec= and OnUnitActiveSec= (kept identical, matching
 * how the shipped unit already pairs them) to the new interval, then
 * daemon-reload + restart so the change actually takes effect right away
 * instead of waiting for the current cycle to finish under the old one.
 * Only restarts if the timer was already active - install.sh deliberately
 * leaves it uninstalled/inactive until VAPID keys exist (see its own
 * comment there), and adjusting the interval shouldn't be what silently
 * turns the timer on for the first time.
 *
 * @return array{ok:bool, interval_seconds?:int, message?:string}
 */
function set_push_timer_interval(int $seconds): array
{
    $min = push_timer_interval_min_seconds();
    $max = push_timer_interval_max_seconds();

    if ($seconds < $min || $seconds > $max) {
        return ['ok' => false, 'message' => "Interval must be between {$min} and {$max} seconds"];
    }

    $path = push_timer_unit_path();
    $raw = @file_get_contents($path);

    if ($raw === false) {
        return ['ok' => false, 'message' => 'csm-push-check.timer is not installed - see the README'];
    }

    $updated = preg_replace('/^OnBootSec=\d+s\s*$/m', "OnBootSec={$seconds}s", $raw, 1, $bootCount);
    $updated = preg_replace('/^OnUnitActiveSec=\d+s\s*$/m', "OnUnitActiveSec={$seconds}s", $updated, 1, $activeCount);

    if ($bootCount !== 1 || $activeCount !== 1) {
        return ['ok' => false, 'message' => 'Could not find OnBootSec=/OnUnitActiveSec= lines to update in the installed timer unit'];
    }

    if (@file_put_contents($path, $updated) === false) {
        return ['ok' => false, 'message' => 'Failed to write the updated timer unit - check file permissions'];
    }

    $reload = ProcessRunner::run_process(['systemctl', '--user', 'daemon-reload']);

    if ($reload['exit'] !== 0) {
        return ['ok' => false, 'message' => 'systemctl daemon-reload failed: ' . trim($reload['stderr'])];
    }

    $unitName = push_timer_unit_name();
    $isActive = ProcessRunner::run_process(['systemctl', '--user', 'is-active', $unitName]);

    if (trim($isActive['stdout']) === 'active') {
        $restart = ProcessRunner::run_process(['systemctl', '--user', 'restart', $unitName]);

        if ($restart['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Interval updated but restarting the timer failed: ' . trim($restart['stderr'])];
        }
    }

    return ['ok' => true, 'interval_seconds' => $seconds];
}

/**
 * "Is everything this app needs actually installed/configured" - one
 * combined check for the dashboard's health box, instead of leaving
 * Andres to discover each missing piece separately (a stale/never-set
 * VAPID key, a missing claude-quota binary, tmux's socket dir wiped by a
 * reboot, etc.). Lives here rather than Sessions.php despite covering
 * plenty of non-push things, since it needs push_configured() and
 * Sessions.php can't require this file back without a cycle - same
 * reasoning as dispatch_push_action() below.
 *
 * @return array{ok:bool, checks:array<int, array{key:string, label:string, ok:bool, detail:?string}>}
 */
function health_check(): array
{
    $settings = [];
    $settingsOk = true;
    $settingsMessage = null;
    $raw = @file_get_contents(Config::claude_settings_path());

    if ($raw !== false) {
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            $settings = $decoded;
        } else {
            $settingsOk = false;
            $settingsMessage = '~/.claude/settings.json exists but is not valid JSON';
        }
    }

    $checks = [];

    foreach (HookService::app_hooks_status($settings) as $hook) {
        $checks[] = [
            'key' => 'hook_' . strtolower($hook['event']),
            'label' => $hook['event'] . ' hook',
            'ok' => $settingsOk && $hook['present'],
            'detail' => $settingsOk ? null : $settingsMessage,
        ];
    }

    $quotaBin = Config::claude_quota_bin();
    $checks[] = [
        'key' => 'claude_quota_bin',
        'label' => 'claude-quota binary',
        'ok' => is_file($quotaBin) && is_executable($quotaBin),
        'detail' => $quotaBin,
    ];

    $tmuxSocketDir = dirname(Config::tmux_socket());
    $checks[] = [
        'key' => 'tmux_socket_dir',
        'label' => 'tmux socket dir',
        'ok' => is_dir($tmuxSocketDir),
        'detail' => $tmuxSocketDir,
    ];

    $checks[] = [
        'key' => 'vapid_keys',
        'label' => 'VAPID push keys',
        'ok' => push_configured(),
        'detail' => null,
    ];

    $checks[] = push_delivery_check();

    $vendorAutoload = Config::csm_repo_root() . '/vendor/autoload.php';
    $checks[] = [
        'key' => 'composer_vendor',
        'label' => 'Composer vendor/',
        'ok' => is_file($vendorAutoload),
        'detail' => $vendorAutoload,
    ];

    return ['ok' => true, 'checks' => $checks];
}

/**
 * Push-related actions, dispatched separately from Sessions.php's own
 * dispatch_action() (see agent.php) rather than folded into it - Push.php
 * already requires Sessions.php for Config::csm_config()/Config::csm_repo_root(), so the
 * reverse dependency would make it a require cycle for no real benefit.
 * health_check() above rides along in this same dispatcher for the same
 * reason. Returns null for any action this doesn't recognize, so
 * agent.php can fall through to dispatch_action() for everything else.
 *
 * @param array<string, mixed> $request
 * @return array<string, mixed>|null
 */
function dispatch_push_action(array $request): ?array
{
    switch ($request['action'] ?? '') {
        case 'push_public_key':
            return ['ok' => true, 'configured' => push_configured(), 'public_key' => vapid_public_key()];

        case 'push_subscribe':
            $subscription = $request['subscription'] ?? null;

            if (!is_array($subscription)) {
                return ['ok' => false, 'message' => 'Missing subscription'];
            }

            return PushSubscriptionStore::add_push_subscription($subscription)
                ? ['ok' => true]
                : ['ok' => false, 'message' => 'Malformed subscription'];

        case 'push_unsubscribe':
            PushSubscriptionStore::remove_push_subscription((string)($request['endpoint'] ?? ''));

            return ['ok' => true];

        case 'health_check':
            return health_check();

        case 'get_push_timer_interval':
            return get_push_timer_interval();

        case 'set_push_timer_interval':
            return set_push_timer_interval((int)($request['seconds'] ?? 0));

        default:
            return null;
    }
}
