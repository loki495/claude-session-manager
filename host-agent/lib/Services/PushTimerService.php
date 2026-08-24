<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Reads/adjusts the interval of the INSTALLED csm-push-check.timer unit -
 * the systemd timer that drives host-agent/push_trigger.php (see
 * PushDeliveryService). Not the repo template at
 * host-agent/systemd/csm-push-check.timer, which install.sh only ever
 * copies from once - editing the template would silently do nothing until
 * a manual reinstall.
 */
class PushTimerService
{
    public static function push_timer_unit_path(): string
    {
        return Config::csm_config('PUSH_TIMER_UNIT_PATH', Config::home_root() . '/.config/systemd/user/csm-push-check.timer');
    }

    /**
     * The systemd unit NAME passed to `systemctl --user`, separately
     * overridable from push_timer_unit_path() above - tests need to isolate
     * this too, not just the file path, since set_push_timer_interval() runs
     * real `systemctl --user is-active`/`restart` commands: without an
     * override, a test running on this same machine would query/restart the
     * REAL production csm-push-check.timer, not a fixture. Pointing this at a
     * name systemd has never heard of makes `is-active` reliably report
     * "inactive" (not "active"), which is what keeps the restart branch in
     * set_push_timer_interval() from ever firing in tests.
     */
    public static function push_timer_unit_name(): string
    {
        return Config::csm_config('PUSH_TIMER_UNIT_NAME', 'csm-push-check.timer');
    }

    /**
     * Bounds on the adjustable interval. Floor avoids hammering systemd/
     * journald for no real latency benefit below ~1-5s granularity (see the
     * shipped unit's own comment on why 10s was chosen); ceiling keeps a
     * "forgot I changed this" mistake from silently making notifications
     * near-useless, given iOS has no client-side background-sync mechanism -
     * this timer is the ONLY thing standing between a session blocking and
     * the phone finding out.
     */
    public static function push_timer_interval_min_seconds(): int
    {
        return 5;
    }

    public static function push_timer_interval_max_seconds(): int
    {
        return 300;
    }

    /**
     * Reads the interval straight from the installed unit file (not some
     * separately-tracked setting) so this can never drift from what systemd
     * is actually running.
     *
     * @return array{ok:bool, interval_seconds:?int, message?:string}
     */
    public static function get_push_timer_interval(): array
    {
        $raw = @file_get_contents(self::push_timer_unit_path());

        if ($raw === false) {
            return ['ok' => false, 'interval_seconds' => null, 'message' => 'csm-push-check.timer is not installed - see the README'];
        }

        if (!preg_match('/^OnUnitActiveSec=(\d+)s\s*$/m', $raw, $m)) {
            return ['ok' => false, 'interval_seconds' => null, 'message' => 'Could not parse OnUnitActiveSec from the installed timer unit'];
        }

        return ['ok' => true, 'interval_seconds' => (int)$m[1]];
    }

    /**
     * Rewrites both OnBootSec= and OnUnitActiveSec= (kept identical, matching
     * how the shipped unit already pairs them) to the new interval, then
     * daemon-reload + restart so the change actually takes effect right away
     * instead of waiting for the current cycle to finish under the old one.
     * Only restarts if the timer was already active - install.sh deliberately
     * leaves it uninstalled/inactive until VAPID keys exist (see its own
     * comment there), and adjusting the interval shouldn't be what silently
     * turns the timer on for the first time.
     *
     * @return array{ok:bool, interval_seconds?:int, message?:string}
     */
    public static function set_push_timer_interval(int $seconds): array
    {
        $min = self::push_timer_interval_min_seconds();
        $max = self::push_timer_interval_max_seconds();

        if ($seconds < $min || $seconds > $max) {
            return ['ok' => false, 'message' => "Interval must be between {$min} and {$max} seconds"];
        }

        $path = self::push_timer_unit_path();
        $raw = @file_get_contents($path);

        if ($raw === false) {
            return ['ok' => false, 'message' => 'csm-push-check.timer is not installed - see the README'];
        }

        $updated = preg_replace('/^OnBootSec=\d+s\s*$/m', "OnBootSec={$seconds}s", $raw, 1, $bootCount);
        $updated = preg_replace('/^OnUnitActiveSec=\d+s\s*$/m', "OnUnitActiveSec={$seconds}s", $updated, 1, $activeCount);

        if ($bootCount !== 1 || $activeCount !== 1) {
            return ['ok' => false, 'message' => 'Could not find OnBootSec=/OnUnitActiveSec= lines to update in the installed timer unit'];
        }

        if (@file_put_contents($path, $updated) === false) {
            return ['ok' => false, 'message' => 'Failed to write the updated timer unit - check file permissions'];
        }

        $reload = ProcessRunner::run_process(['systemctl', '--user', 'daemon-reload']);

        if ($reload['exit'] !== 0) {
            return ['ok' => false, 'message' => 'systemctl daemon-reload failed: ' . trim($reload['stderr'])];
        }

        $unitName = self::push_timer_unit_name();
        $isActive = ProcessRunner::run_process(['systemctl', '--user', 'is-active', $unitName]);

        if (trim($isActive['stdout']) === 'active') {
            $restart = ProcessRunner::run_process(['systemctl', '--user', 'restart', $unitName]);

            if ($restart['exit'] !== 0) {
                return ['ok' => false, 'message' => 'Interval updated but restarting the timer failed: ' . trim($restart['stderr'])];
            }
        }

        return ['ok' => true, 'interval_seconds' => $seconds];
    }
}
