<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * Persistent, unlike the sidecar/quota-cache files this app otherwise
 * keeps under /run/user/1000 (tmpfs, wiped every reboot) - a phone's
 * subscription shouldn't need to be redone just because the host
 * rebooted, so this lives inside the repo checkout itself instead
 * (host-agent/state/, gitignored).
 */
class PushSubscriptionStore
{
    public static function push_subscriptions_file(): string
    {
        return Config::csm_config('PUSH_SUBSCRIPTIONS_FILE', Config::csm_repo_root() . '/host-agent/state/push-subscriptions.json');
    }

    /**
     * @return array<int, array{endpoint:string, keys:array{p256dh:string, auth:string}}>
     */
    public static function read_push_subscriptions(): array
    {
        $raw = @file_get_contents(self::push_subscriptions_file());

        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array{endpoint:string, keys:array{p256dh:string, auth:string}}> $subscriptions
     */
    public static function write_push_subscriptions(array $subscriptions): void
    {
        $dir = dirname(self::push_subscriptions_file());

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents(self::push_subscriptions_file(), json_encode(array_values($subscriptions), JSON_PRETTY_PRINT));
    }

    /**
     * Adds a subscription, or replaces an existing one with the same
     * endpoint (a browser can resubscribe with new keys under the same
     * endpoint - the frontend does this on every page load to self-heal iOS's
     * flaky subscription lifecycle) - deduped by endpoint, the only field
     * guaranteed unique per device+browser install.
     *
     * @param array{endpoint?:mixed, keys?:mixed} $subscription
     */
    public static function add_push_subscription(array $subscription): bool
    {
        $endpoint = (string)($subscription['endpoint'] ?? '');
        $keys = $subscription['keys'] ?? null;

        if ($endpoint === '' || !is_array($keys) || !is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null)) {
            return false;
        }

        $subscriptions = self::read_push_subscriptions();
        $subscriptions = array_values(array_filter($subscriptions, fn(array $s): bool => ($s['endpoint'] ?? null) !== $endpoint));
        $subscriptions[] = ['endpoint' => $endpoint, 'keys' => ['p256dh' => $keys['p256dh'], 'auth' => $keys['auth']]];

        self::write_push_subscriptions($subscriptions);

        return true;
    }

    public static function remove_push_subscription(string $endpoint): void
    {
        $subscriptions = self::read_push_subscriptions();
        $subscriptions = array_values(array_filter($subscriptions, fn(array $s): bool => ($s['endpoint'] ?? null) !== $endpoint));
        self::write_push_subscriptions($subscriptions);
    }
}
