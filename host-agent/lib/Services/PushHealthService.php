<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\GlobalStateStore;

/**
 * "Is everything this app needs actually installed/configured" - backs
 * the dashboard's health box, instead of leaving Andres to discover each
 * missing piece separately (a stale/never-set VAPID key, tmux's socket
 * dir wiped by a reboot, whether the csm-push-check timer is actually
 * ticking, etc).
 */
class PushHealthService
{
    /**
     * Shared by push_delivery_check() and push_quota_delivery_check() below
     * - both read a GlobalStateStore key PushDeliveryService::
     * record_push_check_result() writes every tick (a different key per
     * pass - see PushDeliveryService::push_quota_check_status_key()'s own
     * doc comment for why they can't share one), and both report the same
     * three things: never run, stale (timer stopped ticking), or ran with
     * some number of failed sends.
     *
     * @return array{key:string, label:string, ok:bool, detail:?string}
     */
    private static function push_status_file_check(string $key, string $label, string $statusKey): array
    {
        if (!PushDeliveryService::push_configured()) {
            return ['key' => $key, 'label' => $label, 'ok' => true, 'detail' => 'VAPID not configured yet - nothing to check'];
        }

        $status = GlobalStateStore::read($statusKey);

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
     * health_check() entry for the csm-push-check timer's session-transition
     * pass - not just "are VAPID keys configured" (that's its own separate
     * check, a prerequisite rather than this), but whether the timer is
     * actually running AND whether its most recent tick's sends succeeded.
     *
     * @return array{key:string, label:string, ok:bool, detail:?string}
     */
    public static function push_delivery_check(): array
    {
        return self::push_status_file_check('push_delivery', 'Push delivery', PushDeliveryService::push_check_status_key());
    }

    /**
     * Same idea as push_delivery_check(), for the timer's OTHER pass -
     * PushDeliveryService::check_and_send_quota_pushes(), its own separate
     * status key so one pass's heartbeat can't mask the other's.
     *
     * @return array{key:string, label:string, ok:bool, detail:?string}
     */
    public static function push_quota_delivery_check(): array
    {
        return self::push_status_file_check('push_quota_delivery', 'Quota push delivery', PushDeliveryService::push_quota_check_status_key());
    }

    /**
     * "Is everything this app needs actually installed/configured" - one
     * combined check for the dashboard's health box, instead of leaving
     * Andres to discover each missing piece separately (a stale/never-set
     * VAPID key, tmux's socket dir wiped by a reboot, etc.). Lives here rather than Sessions.php despite covering
     * plenty of non-push things, since it needs PushDeliveryService::push_configured()
     * and Sessions.php can't require this file back without a cycle - same
     * reasoning as dispatch_push_action() (in Push.php) has for staying separate.
     *
     * @return array{ok:bool, checks:array<int, array{key:string, label:string, ok:bool, detail:?string}>}
     */
    public static function health_check(): array
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

        $checks[] = self::push_delivery_check();
        $checks[] = self::push_quota_delivery_check();

        $vendorAutoload = Config::csm_repo_root() . '/vendor/autoload.php';
        $checks[] = [
            'key' => 'composer_vendor',
            'label' => 'Composer vendor/',
            'ok' => is_file($vendorAutoload),
            'detail' => $vendorAutoload,
        ];

        return ['ok' => true, 'checks' => $checks];
    }
}
