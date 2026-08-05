<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * bucketKey (session/week_all/week_<plan>) => last-known pct plus whether a
 * near/over notification has already fired for the CURRENT window - lets
 * PushDeliveryService::check_and_send_quota_pushes() notify once per
 * crossing rather than on every tick, and re-arm both flags once pct
 * actually drops (a real window rollover) or drops back under the
 * near-threshold on its own.
 */
class PushQuotaStateStore
{
    public static function push_quota_state_file(): string
    {
        return Config::csm_config('PUSH_QUOTA_STATE_FILE', Config::csm_repo_root() . '/host-agent/state/push-quota-state.json');
    }

    /**
     * @return array<string, array{pct:int, notified_near:bool, notified_over:bool}>
     */
    public static function read_push_quota_state(): array
    {
        $raw = @file_get_contents(self::push_quota_state_file());

        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, array{pct:int, notified_near:bool, notified_over:bool}> $state
     */
    public static function write_push_quota_state(array $state): void
    {
        $dir = dirname(self::push_quota_state_file());

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents(self::push_quota_state_file(), json_encode($state));
    }
}
