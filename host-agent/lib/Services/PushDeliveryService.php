<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\PushQuotaStateStore;
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

    /**
     * Required by the Web Push spec itself (a mailto: address or an HTTPS
     * URL push services can use to contact you about your VAPID usage) -
     * no default here, set it explicitly in .env alongside the VAPID
     * keypair when enabling push notifications (see README).
     */
    public static function vapid_subject(): string
    {
        return Config::csm_config('VAPID_SUBJECT', '');
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
     * A quota bucket's pct at or above this counts as "close to over" for
     * check_and_send_quota_pushes() - deliberately below 100 (that's its own
     * separate "over" notification), configurable since what counts as
     * "close enough to want a warning" is a judgment call, not a fact.
     */
    public static function push_quota_near_threshold_pct(): int
    {
        return (int)Config::csm_config('PUSH_QUOTA_NEAR_THRESHOLD_PCT', '90');
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
     * Same heartbeat idea as push_check_status_file(), its own separate
     * file - check_and_send_quota_pushes() runs as its own pass alongside
     * check_and_send_pushes() every tick (see push_trigger.php), and each
     * writing to the SAME file would have the second call's counts
     * silently clobber the first's, breaking push_delivery_check()'s
     * existing "the session-transition pass ran and its own sends
     * succeeded" reading of that file.
     */
    public static function push_quota_check_status_file(): string
    {
        return Config::csm_config('PUSH_QUOTA_CHECK_STATUS_FILE', Config::csm_repo_root() . '/host-agent/state/push-quota-check-status.json');
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
     * $statusFile defaults to push_check_status_file() - check_and_send_quota_pushes()
     * passes push_quota_check_status_file() instead, its own separate file,
     * so the two passes' heartbeats never clobber each other (see that
     * file's own doc comment).
     *
     * @param array<int, array{ok:bool, expired:bool, message?:string}> $sendResults
     */
    public static function record_push_check_result(array $sendResults, ?string $statusFile = null): void
    {
        $statusFile ??= self::push_check_status_file();
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

        $dir = dirname($statusFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents($statusFile, json_encode($status));
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

    /**
     * A bucket's pct dropping by at least this many points since the last
     * tick counts as its window actually resetting - see
     * check_and_send_quota_pushes()'s own doc comment for why this (not
     * resets_at moving forward) is the reliable signal.
     */
    public static function push_quota_reset_drop_threshold_pct(): int
    {
        return (int)Config::csm_config('PUSH_QUOTA_RESET_DROP_THRESHOLD_PCT', '10');
    }

    /**
     * The quota counterpart to check_and_send_pushes() above, called
     * alongside it on every csm-push-check timer tick (see
     * host-agent/push_trigger.php) with QuotaService::get_quota(null)'s
     * 'quota' sub-array - account-wide only (no session name is ever
     * passed there), so the per-session 'context' bucket never appears
     * here and needs no special exclusion.
     *
     * Three distinct notification kinds per bucket, all independent of each
     * other within the same tick:
     * - "near" once pct first reaches push_quota_near_threshold_pct()
     * - "over" once pct first reaches 100
     * - "reset" once pct is observed to DROP by at least
     *   push_quota_reset_drop_threshold_pct() points since the last tick -
     *   pct only ever climbs within a window otherwise (it's cumulative
     *   usage), so a real drop is a reliable sign the window rolled over.
     *   Deliberately NOT based on resets_at moving forward, despite that
     *   seeming like the more obvious signal: found live (2026-08-05) that
     *   resets_at is re-parsed from the live pane's own duration text (e.g.
     *   "1h 53m") on every tick, which only has minute-level precision - it
     *   jitters by up to ~60s between ticks even with nothing actually
     *   resetting, and that alone was enough to fire repeated false
     *   "reset" notifications every ~10s in production before this was
     *   caught and fixed.
     *
     * Both the near and over flags are one-shot per window: once fired,
     * they don't fire again until either a real reset (the pct-drop above)
     * or the pct drops back under the near-threshold on its own (e.g. a
     * plan change) - re-arming both for the next climb. $quota being
     * null/empty (the quota fetch itself failed or hasn't completed yet
     * this tick) is a harmless no-op, same as check_and_send_pushes() with
     * zero live sessions.
     *
     * @param array<string, mixed>|null $quota
     * @return array{ok:bool, notified:array<int, string>}
     */
    public static function check_and_send_quota_pushes(?array $quota): array
    {
        if (!self::push_configured() || $quota === null || $quota === []) {
            return ['ok' => false, 'notified' => []];
        }

        $threshold = self::push_quota_near_threshold_pct();
        $resetDropThreshold = self::push_quota_reset_drop_threshold_pct();
        $previousState = PushQuotaStateStore::read_push_quota_state();
        $currentState = [];
        $notified = [];
        $subscriptions = PushSubscriptionStore::read_push_subscriptions();
        $expiredEndpoints = [];
        $sendResults = [];

        foreach ($quota as $key => $bucket) {
            if (!is_array($bucket) || !isset($bucket['pct'])) {
                continue; // not a real bucket (e.g. captured_at) - nothing to track
            }

            $pct = (int)$bucket['pct'];

            $previousEntry = is_array($previousState[$key] ?? null) ? $previousState[$key] : null;
            $previousPct = is_int($previousEntry['pct'] ?? null) ? $previousEntry['pct'] : null;
            $notifiedNear = !empty($previousEntry['notified_near']);
            $notifiedOver = !empty($previousEntry['notified_over']);

            $justReset = $previousPct !== null && ($previousPct - $pct) >= $resetDropThreshold;

            if ($justReset) {
                $notifiedNear = false;
                $notifiedOver = false;
            }

            $notifications = [];

            if ($justReset) {
                $notifications[] = ['title' => NotificationContentBuilder::push_quota_reset_title((string)$key), 'body' => NotificationContentBuilder::push_quota_reset_body((string)$key)];
            }

            if ($pct >= 100 && !$notifiedOver) {
                $notifications[] = ['title' => NotificationContentBuilder::push_quota_over_title((string)$key), 'body' => NotificationContentBuilder::push_quota_over_body((string)$key, $pct)];
                $notifiedOver = true;
                $notifiedNear = true; // over implies near - no separate near notification right after
            } elseif ($pct >= $threshold && !$notifiedNear) {
                $notifications[] = ['title' => NotificationContentBuilder::push_quota_near_title((string)$key), 'body' => NotificationContentBuilder::push_quota_near_body((string)$key, $pct)];
                $notifiedNear = true;
            } elseif ($pct < $threshold) {
                // Dropped back under the threshold on its own (a plan
                // change, or a reset already handled above) - re-arm both
                // so a future climb notifies again instead of staying
                // permanently silenced from one earlier crossing.
                $notifiedNear = false;
                $notifiedOver = false;
            }

            $currentState[$key] = ['pct' => $pct, 'notified_near' => $notifiedNear, 'notified_over' => $notifiedOver];

            foreach ($notifications as $notification) {
                $notified[] = "{$key}:{$notification['title']}";

                foreach ($subscriptions as $subscription) {
                    $result = self::send_push_notification($subscription, $notification['title'], $notification['body']);
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

        PushQuotaStateStore::write_push_quota_state($currentState);
        self::record_push_check_result($sendResults, self::push_quota_check_status_file());

        return ['ok' => true, 'notified' => $notified];
    }
}
