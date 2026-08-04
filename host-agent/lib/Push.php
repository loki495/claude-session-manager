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
use HostAgent\Services\PushDeliveryService;
use HostAgent\Services\PushTimerService;
use HostAgent\Stores\PushSubscriptionStore;

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

    if (!PushDeliveryService::push_configured()) {
        return ['key' => $key, 'label' => $label, 'ok' => true, 'detail' => 'VAPID not configured yet - nothing to check'];
    }

    $raw = @file_get_contents(PushDeliveryService::push_check_status_file());
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
        'ok' => PushDeliveryService::push_configured(),
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
            return health_check();

        case 'get_push_timer_interval':
            return PushTimerService::get_push_timer_interval();

        case 'set_push_timer_interval':
            return PushTimerService::set_push_timer_interval((int)($request['seconds'] ?? 0));

        default:
            return null;
    }
}
