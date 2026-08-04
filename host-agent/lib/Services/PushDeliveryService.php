<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\PushSessionStateStore;
use HostAgent\Stores\PushSubscriptionStore;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * The actual VAPID-signed send (via minishlink/web-push) and the main
 * push-trigger pass that decides WHEN a session's state transition is
 * worth sending for - called on every csm-push-check systemd timer tick
 * (see host-agent/push_trigger.php). iOS Safari has no working
 * client-side background-sync mechanism, so detecting a session
 * transitioning into a blocked state has to happen here, server/host-
 * triggered, not in the browser.
 */
class PushDeliveryService
{
    public static function vapid_public_key(): string
    {
        return Config::csm_config('VAPID_PUBLIC_KEY', '');
    }

    public static function vapid_private_key(): string
    {
        return Config::csm_config('VAPID_PRIVATE_KEY', '');
    }

    public static function vapid_subject(): string
    {
        return Config::csm_config('VAPID_SUBJECT', 'mailto:dasc495@gmail.com');
    }

    /**
     * How long a session must have been continuously 'working' before its
     * transition to 'idle' (finished, nothing left needing input) is worth a
     * push notification for - avoids notifying for every trivial quick reply,
     * only for something that actually took a while.
     */
    public static function push_min_working_seconds_for_finish_notify(): int
    {
        return (int)Config::csm_config('PUSH_MIN_WORKING_SECONDS_FOR_FINISH_NOTIFY', '60');
    }

    /**
     * False (and every push-related action a harmless no-op) until VAPID
     * keys are actually generated and set - see README for the one-time
     * generation step.
     */
    public static function push_configured(): bool
    {
        return self::vapid_public_key() !== '' && self::vapid_private_key() !== '';
    }

    /**
     * Last-tick outcome of check_and_send_pushes() - written on EVERY tick
     * (even ones with nothing to send), so its timestamp doubles as a
     * heartbeat proving the csm-push-check timer is actually running, not
     * just that sends succeed when attempted. Read back by
     * PushHealthService::push_delivery_check() for the dashboard.
     */
    public static function push_check_status_file(): string
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
    public static function send_push_notification(array $subscription, string $title, string $body, ?string $url = null): array
    {
        if (!self::push_configured()) {
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
                    'subject' => self::vapid_subject(),
                    'publicKey' => self::vapid_public_key(),
                    'privateKey' => self::vapid_private_key(),
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
        } catch (\Throwable $e) {
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
    public static function record_push_check_result(array $sendResults): void
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

        $dir = dirname(self::push_check_status_file());

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents(self::push_check_status_file(), json_encode($status));
    }

    /**
     * The main push-trigger pass, called on every csm-push-check timer tick
     * (see host-agent/push_trigger.php): for every currently-live session,
     * compares its current NotificationContentBuilder::push_session_state()
     * against what was last recorded (including how long it's been in that
     * state, tracked via "since" - see
     * PushSessionStateStore::read_push_session_state()), and sends a push to
     * every stored subscription for either of two transitions:
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
    public static function check_and_send_pushes(array $sessions, ?int $now = null): array
    {
        if (!self::push_configured()) {
            return ['ok' => false, 'notified' => [], 'pruned' => 0];
        }

        $now ??= time();
        $previousState = PushSessionStateStore::read_push_session_state();
        $currentState = [];
        $notified = [];
        $subscriptions = PushSubscriptionStore::read_push_subscriptions();
        $expiredEndpoints = [];
        $sendResults = [];
        $minWorkingSeconds = self::push_min_working_seconds_for_finish_notify();

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
                    $result = self::send_push_notification($subscription, $notification['title'], $notification['body'], '/session.php?session=' . urlencode($name));
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
        self::record_push_check_result($sendResults);

        return ['ok' => true, 'notified' => $notified, 'pruned' => count($expiredEndpoints)];
    }
}
