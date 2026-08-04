#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Standalone entry point run periodically by the csm-push-check systemd
 * timer (see host-agent/systemd/) - checks every live session for a
 * fresh transition into a blocked/waiting-on-input state and fires a Web
 * Push notification for it. See PushDeliveryService::check_and_send_pushes()
 * for the actual logic; this script is just the timer's entry point.
 *
 * A no-op (exits 0 immediately) if VAPID keys aren't configured yet, so
 * installing the timer unit before generating keys is harmless.
 */

require __DIR__ . '/lib/Push.php';

use HostAgent\Services\PushDeliveryService;
use HostAgent\Services\SessionService;

if (!PushDeliveryService::push_configured()) {
    exit(0);
}

$sessions = SessionService::list_all_sessions()['sessions'] ?? [];

PushDeliveryService::check_and_send_pushes($sessions);
