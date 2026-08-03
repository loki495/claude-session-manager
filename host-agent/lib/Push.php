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
 * @return array<string, string> sessionName => last-known state ('blocked'|'working'|'idle')
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
 * @param array<string, string> $state
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
 * The main push-trigger pass, called on every csm-push-check timer tick
 * (see host-agent/push_trigger.php): for every currently-live session,
 * compares its current push_session_state() against what was last
 * recorded, sends a push to every stored subscription for any session
 * that just transitioned INTO 'blocked' (a new prompt appeared - the
 * transition matters, not the state itself, so a prompt that's been
 * sitting unanswered for an hour doesn't re-notify on every tick), and
 * prunes any subscription a send reports as permanently expired.
 *
 * @param array<int, array{name:string, blocked_reason?:?string, working?:bool, title?:?string}> $sessions
 * @return array{ok:bool, notified:array<int, string>, pruned:int}
 */
function check_and_send_pushes(array $sessions): array
{
    if (!push_configured()) {
        return ['ok' => false, 'notified' => [], 'pruned' => 0];
    }

    $previousState = read_push_session_state();
    $currentState = [];
    $notified = [];
    $subscriptions = read_push_subscriptions();
    $expiredEndpoints = [];

    foreach ($sessions as $session) {
        $name = (string)$session['name'];
        $state = push_session_state($session);
        $currentState[$name] = $state;

        $wasBlocked = ($previousState[$name] ?? null) === 'blocked';

        // $notified reflects the transition itself, independent of
        // whether there's actually anyone subscribed to receive it -
        // keeps "was a transition detected" and "did a send happen"
        // separately observable/testable, and means a fresh install with
        // zero subscriptions yet still correctly tracks state instead of
        // silently skipping the bookkeeping too.
        if ($state === 'blocked' && !$wasBlocked) {
            $notified[] = $name;

            if ($subscriptions !== []) {
                $title = (string)($session['title'] ?? $name);
                $body = (string)($session['blocked_reason'] ?? 'Waiting on input');

                foreach ($subscriptions as $subscription) {
                    $result = send_push_notification($subscription, $title, $body, '/session.php?session=' . urlencode($name));

                    if ($result['expired']) {
                        $expiredEndpoints[] = $subscription['endpoint'];
                    }
                }
            }
        }
    }

    if ($expiredEndpoints !== []) {
        $subscriptions = array_values(array_filter($subscriptions, fn(array $s): bool => !in_array($s['endpoint'], $expiredEndpoints, true)));
        write_push_subscriptions($subscriptions);
    }

    write_push_session_state($currentState);

    return ['ok' => true, 'notified' => $notified, 'pruned' => count($expiredEndpoints)];
}

/**
 * Push-related actions, dispatched separately from Sessions.php's own
 * dispatch_action() (see agent.php) rather than folded into it - Push.php
 * already requires Sessions.php for csm_config()/csm_repo_root(), so the
 * reverse dependency would make it a require cycle for no real benefit.
 * Returns null for any action this doesn't recognize, so agent.php can
 * fall through to dispatch_action() for everything else.
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

        default:
            return null;
    }
}
