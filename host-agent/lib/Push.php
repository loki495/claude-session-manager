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

use HostAgent\Services\PushDeliveryService;
use HostAgent\Services\PushHealthService;
use HostAgent\Services\PushTimerService;
use HostAgent\Stores\PushSubscriptionStore;

/**
 * Push-related actions, dispatched separately from Sessions.php's own
 * dispatch_action() (see agent.php) rather than folded into it - keeps
 * push subscription/delivery/health/timer concerns out of Sessions.php's
 * dispatcher entirely. PushHealthService::health_check() rides along in
 * this same dispatcher for the same reason, despite covering non-push
 * checks too. Returns null for any action this doesn't recognize, so
 * agent.php can fall through to dispatch_action() for everything else.
 *
 * @param array<string, mixed> $request
 * @return array<string, mixed>|null
 */
function dispatch_push_action(array $request): ?array
{
    switch ($request['action'] ?? '') {
        case 'push_public_key':
            return ['ok' => true, 'configured' => PushDeliveryService::push_configured(), 'public_key' => PushDeliveryService::vapid_public_key()];

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
            return PushHealthService::health_check();

        case 'get_push_timer_interval':
            return PushTimerService::get_push_timer_interval();

        case 'set_push_timer_interval':
            return PushTimerService::set_push_timer_interval((int)($request['seconds'] ?? 0));

        default:
            return null;
    }
}
