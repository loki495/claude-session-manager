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

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

function vapid_public_key(): string
{
    return csm_config('VAPID_PUBLIC_KEY', '');
}

function vapid_private_key(): string
{
    return csm_config('VAPID_PRIVATE_KEY', '');
}

function vapid_subject(): string
{
    return csm_config('VAPID_SUBJECT', 'mailto:dasc495@gmail.com');
}

/**
 * How long a session must have been continuously 'working' before its
 * transition to 'idle' (finished, nothing left needing input) is worth a
 * push notification for - avoids notifying for every trivial quick reply,
 * only for something that actually took a while.
 */
function push_min_working_seconds_for_finish_notify(): int
{
    return (int)csm_config('PUSH_MIN_WORKING_SECONDS_FOR_FINISH_NOTIFY', '60');
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
 * Persistent, unlike the sidecar/quota-cache files this app otherwise
 * keeps under /run/user/1000 (tmpfs, wiped every reboot) - a phone's
 * subscription shouldn't need to be redone just because the host
 * rebooted, so this lives inside the repo checkout itself instead
 * (host-agent/state/, gitignored).
 */
function push_subscriptions_file(): string
{
    return csm_config('PUSH_SUBSCRIPTIONS_FILE', csm_repo_root() . '/host-agent/state/push-subscriptions.json');
}

function push_state_file(): string
{
    return csm_config('PUSH_STATE_FILE', csm_repo_root() . '/host-agent/state/push-session-state.json');
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
    return csm_config('PUSH_CHECK_STATUS_FILE', csm_repo_root() . '/host-agent/state/push-check-status.json');
}

/**
 * @return array<int, array{endpoint:string, keys:array{p256dh:string, auth:string}}>
 */
function read_push_subscriptions(): array
{
    $raw = @file_get_contents(push_subscriptions_file());

    if ($raw === false) {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<int, array{endpoint:string, keys:array{p256dh:string, auth:string}}> $subscriptions
 */
function write_push_subscriptions(array $subscriptions): void
{
    $dir = dirname(push_subscriptions_file());

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    @file_put_contents(push_subscriptions_file(), json_encode(array_values($subscriptions), JSON_PRETTY_PRINT));
}

/**
 * Adds a subscription, or replaces an existing one with the same
 * endpoint (a browser can resubscribe with new keys under the same
 * endpoint - the frontend does this on every page load to self-heal iOS's
 * flaky subscription lifecycle) - deduped by endpoint, the only field
 * guaranteed unique per device+browser install.
 *
 * @param array{endpoint?:mixed, keys?:mixed} $subscription
 */
function add_push_subscription(array $subscription): bool
{
    $endpoint = (string)($subscription['endpoint'] ?? '');
    $keys = $subscription['keys'] ?? null;

    if ($endpoint === '' || !is_array($keys) || !is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null)) {
        return false;
    }

    $subscriptions = read_push_subscriptions();
    $subscriptions = array_values(array_filter($subscriptions, fn(array $s): bool => ($s['endpoint'] ?? null) !== $endpoint));
    $subscriptions[] = ['endpoint' => $endpoint, 'keys' => ['p256dh' => $keys['p256dh'], 'auth' => $keys['auth']]];

    write_push_subscriptions($subscriptions);

    return true;
}

function remove_push_subscription(string $endpoint): void
{
    $subscriptions = read_push_subscriptions();
    $subscriptions = array_values(array_filter($subscriptions, fn(array $s): bool => ($s['endpoint'] ?? null) !== $endpoint));
    write_push_subscriptions($subscriptions);
}

/**
 * @return array<string, array{state:string, since:int}> sessionName =>
 *   last-known state ('blocked'|'working'|'idle') plus the timestamp it's
 *   been in that state continuously since - the "since" half is what lets
 *   check_and_send_pushes() tell a session that just finished a genuinely
 *   long task apart from one that only worked for a couple of seconds.
 */
function read_push_session_state(): array
{
    $raw = @file_get_contents(push_state_file());

    if ($raw === false) {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, array{state:string, since:int}> $state
 */
function write_push_session_state(array $state): void
{
    $dir = dirname(push_state_file());

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    @file_put_contents(push_state_file(), json_encode($state));
}

/**
 * The state a session's row (as returned by build_session_entry()) is in
 * for push-transition purposes - simpler than the UI's own richer state
 * (a folder-trust dialog vs. a tool-permission prompt, etc. all just
 * count as "blocked" here), since all a push notification needs to
 * answer is "does this session need me right now or not".
 *
 * @param array{blocked_reason?:?string, working?:bool} $session
 */
function push_session_state(array $session): string
{
    if (!empty($session['blocked_reason'])) {
        return 'blocked';
    }

    if (!empty($session['working'])) {
        return 'working';
    }

    return 'idle';
}

/**
 * The title a push notification shows - prefers the session's own live
 * pane-title task description (see build_session_entry() in
 * Sessions.php), falling back to something friendlier than the raw
 * cc-YYYYMMDD-HHMM session name when that title isn't set yet. Found
 * live: a session can hit a blocking prompt within seconds of being
 * created, before Claude Code's own title-setting has had a chance to
 * run at all - the notification for that one showed the bare session
 * name instead of anything meaningful.
 *
 * @param array{name?:mixed, title?:mixed, workdir?:mixed} $session
 */
function push_notification_title(array $session): string
{
    $title = is_string($session['title'] ?? null) ? trim($session['title']) : '';

    if ($title !== '') {
        // Claude Code prefixes its idle/non-working pane title with a
        // static icon (e.g. "✳ Fix login bug", U+2733 - distinct from the
        // animated braille spinner clean_pane_title() already strips,
        // which only appears while actively working). Fine in a terminal
        // title bar, out of place at the start of a phone notification -
        // \p{So} (Symbol, other) covers this and similar single-glyph
        // icon prefixes generically rather than hardcoding this one
        // codepoint.
        return preg_replace('/^\p{So}\s*/u', '', $title) ?? $title;
    }

    $workdir = is_string($session['workdir'] ?? null) ? trim($session['workdir']) : '';

    if ($workdir !== '') {
        return basename($workdir);
    }

    return is_string($session['name'] ?? null) ? $session['name'] : 'Claude session';
}

/**
 * Same 140-char preview convention as last_message_preview_html() in
 * AgentClient.php, shared by every push body that echoes real
 * user/session-generated text (as opposed to a fixed generic string) so a
 * long command or reply doesn't blow out a notification.
 */
function push_truncate(string $text, int $limit = 140): string
{
    $text = trim($text);

    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
}

/**
 * The body for a "finished working, nothing needs your input" push - the
 * actual reply text if there is one, a generic fallback otherwise (e.g.
 * the session's last turn was only tool calls, no closing text reply).
 *
 * @param array{role?:?string, blocks?:array<int, array{kind:string, text:string}>}|null $lastMessage
 */
function push_finished_body(?array $lastMessage): string
{
    if ($lastMessage === null || ($lastMessage['role'] ?? null) !== 'assistant') {
        return 'Finished - no input needed';
    }

    foreach ($lastMessage['blocks'] ?? [] as $block) {
        if (($block['kind'] ?? null) === 'text' && is_string($block['text'] ?? null) && trim($block['text']) !== '') {
            return push_truncate($block['text']);
        }
    }

    return 'Finished - no input needed';
}

/**
 * The body for a permission prompt (Bash/Write/Edit/etc. awaiting
 * approval) - the actual command/action being asked about, not a generic
 * "do you want to proceed?" (that's what the pane-scraped blocked_reason
 * usually reduces to for this prompt shape - see push_blocked_body()).
 *
 * $taskTitle (push_notification_title() of the same session) is prefixed
 * on when given, e.g. "Check the todos: npm test" instead of just
 * "npm test" - the notification's own title field already carries this,
 * but some lock screens/previews de-emphasize or truncate the title
 * enough that the command alone reads as context-free. Optional (default
 * '', no prefix) so callers that only care about the command/action
 * formatting itself don't need to think about it.
 *
 * @param array<string, mixed> $toolInput
 */
function push_permission_body(string $toolName, array $toolInput, string $taskTitle = ''): string
{
    switch ($toolName) {
        case 'Bash':
            $command = is_string($toolInput['command'] ?? null) ? trim($toolInput['command']) : '';
            $action = $command !== '' ? $command : 'Run a Bash command';
            break;

        case 'Write':
            $path = is_string($toolInput['file_path'] ?? null) ? $toolInput['file_path'] : null;
            $action = $path !== null ? "Write {$path}" : 'Write a file';
            break;

        case 'Edit':
            $path = is_string($toolInput['file_path'] ?? null) ? $toolInput['file_path'] : null;
            $action = $path !== null ? "Edit {$path}" : 'Edit a file';
            break;

        default:
            $action = "Run {$toolName}";
    }

    return push_truncate($taskTitle !== '' ? "{$taskTitle}: {$action}" : $action);
}

/**
 * The body for a newly-blocked prompt: the real command/action for a
 * permission prompt (see push_permission_body()), or the real question
 * text for an AskUserQuestion prompt / anything else without a matched
 * pending tool (the trust dialog, a stale/missing PreToolUse record) -
 * unchanged from before, since blocked_reason is already the right thing
 * to show for those.
 *
 * @param array{blocked_reason?:mixed, prompt_tool_name?:mixed, prompt_tool_input?:mixed} $session
 */
function push_blocked_body(array $session): string
{
    $toolName = is_string($session['prompt_tool_name'] ?? null) ? $session['prompt_tool_name'] : null;
    $toolInput = is_array($session['prompt_tool_input'] ?? null) ? $session['prompt_tool_input'] : null;

    if ($toolName !== null && $toolName !== 'AskUserQuestion' && $toolInput !== null) {
        return push_permission_body($toolName, $toolInput, push_notification_title($session));
    }

    return (string)($session['blocked_reason'] ?? 'Waiting on input');
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
    $previousState = read_push_session_state();
    $currentState = [];
    $notified = [];
    $subscriptions = read_push_subscriptions();
    $expiredEndpoints = [];
    $sendResults = [];
    $minWorkingSeconds = push_min_working_seconds_for_finish_notify();

    foreach ($sessions as $session) {
        $name = (string)$session['name'];
        $state = push_session_state($session);

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
                'title' => push_notification_title($session),
                'body' => push_blocked_body($session),
            ];
        } elseif (
            $state === 'idle'
            && $previousStateName === 'working'
            && $previousSince !== null
            && ($now - $previousSince) >= $minWorkingSeconds
        ) {
            $notification = [
                'title' => push_notification_title($session),
                'body' => push_finished_body(is_array($session['last_message'] ?? null) ? $session['last_message'] : null),
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
        write_push_subscriptions($subscriptions);
    }

    write_push_session_state($currentState);
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
    $raw = @file_get_contents(claude_settings_path());

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

    foreach (app_hooks_status($settings) as $hook) {
        $checks[] = [
            'key' => 'hook_' . strtolower($hook['event']),
            'label' => $hook['event'] . ' hook',
            'ok' => $settingsOk && $hook['present'],
            'detail' => $settingsOk ? null : $settingsMessage,
        ];
    }

    $quotaBin = claude_quota_bin();
    $checks[] = [
        'key' => 'claude_quota_bin',
        'label' => 'claude-quota binary',
        'ok' => is_file($quotaBin) && is_executable($quotaBin),
        'detail' => $quotaBin,
    ];

    $tmuxSocketDir = dirname(tmux_socket());
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

    $vendorAutoload = csm_repo_root() . '/vendor/autoload.php';
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
 * already requires Sessions.php for csm_config()/csm_repo_root(), so the
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

            return add_push_subscription($subscription)
                ? ['ok' => true]
                : ['ok' => false, 'message' => 'Malformed subscription'];

        case 'push_unsubscribe':
            remove_push_subscription((string)($request['endpoint'] ?? ''));

            return ['ok' => true];

        case 'health_check':
            return health_check();

        default:
            return null;
    }
}
