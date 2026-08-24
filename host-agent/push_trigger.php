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

// Each pass wrapped independently - found live 2026-08-08 that an
// uncaught error partway through list_all_sessions()/check_and_send_pushes()
// (a real incident: methods referenced mid-edit during unrelated live
// development elsewhere in this same unsandboxed codebase - see this
// project's own CLAUDE.md on that risk) took the ENTIRE script down
// before it ever reached the quota pass below, silently disabling BOTH
// for as long as the crash persisted. \Throwable, not \Exception - the
// actual incident was a \Error ("Call to undefined method ..."), which
// \Exception alone would not have caught.
try {
    $sessions = SessionService::list_all_sessions()['sessions'] ?? [];
    PushDeliveryService::check_and_send_pushes($sessions);
} catch (\Throwable $e) {
    error_log('csm-push-check: session-transition pass crashed - ' . $e->getMessage());
}

if (Config::csm_config('PUSH_QUOTA_NOTIFICATIONS_ENABLED', '1') === '1') {
    try {
        PushDeliveryService::check_and_send_quota_pushes(QuotaService::get_quota()['quota'] ?? null);
    } catch (\Throwable $e) {
        error_log('csm-push-check: quota pass crashed - ' . $e->getMessage());
    }
}
