#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Standalone entry point run periodically by the csm-push-check systemd
 * timer (see host-agent/systemd/) - two independent passes every tick:
 * every live session checked for a fresh transition into a blocked/
 * waiting-on-input state (PushDeliveryService::check_and_send_pushes()),
 * and account-wide quota checked for a bucket crossing near/over a
 * threshold or its window resetting (PushDeliveryService::
 * check_and_send_quota_pushes()). See those methods for the actual logic;
 * this script is just the timer's entry point.
 *
 * The quota pass has its own kill switch, PUSH_QUOTA_NOTIFICATIONS_ENABLED
 * (see .env.example) - deliberately separate from unsetting VAPID
 * entirely, which would also silence the session-transition pass. Since
 * this is a Type=oneshot service re-spawned fresh by the timer every
 * tick, systemd re-reads EnvironmentFile= (host-agent/.env) on every
 * single run - flipping this to 0 takes effect on the NEXT tick, no
 * restart of anything needed.
 *
 * A no-op (exits 0 immediately) if VAPID keys aren't configured yet, so
 * installing the timer unit before generating keys is harmless.
 */

require __DIR__ . '/lib/Push.php';

use HostAgent\Services\Config;
use HostAgent\Services\PushDeliveryService;
use HostAgent\Services\QuotaService;
use HostAgent\Services\SessionService;

if (!PushDeliveryService::push_configured()) {
    exit(0);
}

$sessions = SessionService::list_all_sessions()['sessions'] ?? [];

PushDeliveryService::check_and_send_pushes($sessions);

if (Config::csm_config('PUSH_QUOTA_NOTIFICATIONS_ENABLED', '1') === '1') {
    PushDeliveryService::check_and_send_quota_pushes(QuotaService::get_quota()['quota'] ?? null);
}
