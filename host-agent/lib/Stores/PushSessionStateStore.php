<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * sessionName => last-known push state ('blocked'|'working'|'idle') plus
 * the timestamp it's been in that state continuously since - the "since"
 * half is what lets PushDeliveryService::check_and_send_pushes() tell a
 * session that just finished a genuinely long task apart from one that
 * only worked for a couple of seconds.
 */
class PushSessionStateStore
{
    public static function push_state_file(): string
    {
        return Config::csm_config('PUSH_STATE_FILE', Config::csm_repo_root() . '/host-agent/state/push-session-state.json');
    }

    /**
     * @return array<string, array{state:string, since:int}>
     */
    public static function read_push_session_state(): array
    {
        $raw = @file_get_contents(self::push_state_file());

        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, array{state:string, since:int}> $state
     */
    public static function write_push_session_state(array $state): void
    {
        $dir = dirname(self::push_state_file());

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents(self::push_state_file(), json_encode($state));
    }
}
