<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * "Is everything this app needs actually installed/configured" - backs
 * the dashboard's health box, instead of leaving Andres to discover each
 * missing piece separately (a stale/never-set VAPID key, a missing
 * claude-quota binary, tmux's socket dir wiped by a reboot, whether the
 * csm-push-check timer is actually ticking, etc).
 */
class PushHealthService
{
    /**
     * health_check() entry for the csm-push-check timer itself - not just
     * "are VAPID keys configured" (that's its own separate check, a
     * prerequisite rather than this), but whether the timer is actually
     * running AND whether its most recent tick's sends succeeded. Reads the
     * status PushDeliveryService::record_push_check_result() writes every tick.
     *
     * @return array{key:string, label:string, ok:bool, detail:?string}
     */
    public static function push_delivery_check(): array
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

        $checks[] = self::push_delivery_check();

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
