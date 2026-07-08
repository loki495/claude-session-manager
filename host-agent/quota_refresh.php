#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Standalone entry point for a background quota refresh - launched
 * fire-and-forget by trigger_background_quota_refresh() (see
 * lib/Sessions.php) whenever the cache is missing or stale, so the
 * request that noticed that can respond immediately instead of blocking
 * on the slow claude-quota scrape. Runs fully detached from that request:
 * by the time this executes, the connection that spawned it may already
 * be gone.
 */

require __DIR__ . '/lib/Sessions.php';

$result = run_claude_quota();

if ($result['ok']) {
    write_quota_cache($result['quota'], time());
}

@unlink(quota_refresh_marker_file());
