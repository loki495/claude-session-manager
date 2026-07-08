<?php
declare(strict_types=1);

/**
 * Exercises the quota caching/background-refresh logic in
 * host-agent/lib/Sessions.php against the fixture claude-quota script
 * (tests/fixtures/fake_claude_quota - never the real one, which would spin
 * up a real screen/claude TUI). Calls the underlying functions in-process
 * for the pure-logic pieces, and lets trigger_background_quota_refresh()
 * spawn a real (but fixture-backed) background process for the
 * integration-style checks, since that detachment is the whole point of
 * this feature.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

const REAL_QUOTA_CACHE_FILE = '/run/user/1000/csm-agent-quota-cache.json';

if (quota_cache_file() === REAL_QUOTA_CACHE_FILE) {
    fwrite(STDERR, "REFUSING TO RUN: QUOTA_CACHE_FILE resolves to the real host cache. Check tests/.env.testing.\n");
    exit(1);
}

function reset_quota_state(): void
{
    @unlink(quota_cache_file());
    @unlink(quota_refresh_marker_file());
}

function wait_until(callable $check, float $timeoutSeconds, float $intervalSeconds = 0.1): bool
{
    $deadline = microtime(true) + $timeoutSeconds;

    do {
        if ($check()) {
            return true;
        }
        usleep((int)($intervalSeconds * 1_000_000));
    } while (microtime(true) < $deadline);

    return $check();
}

// --- parse_resets_at(): bare time (no date) rolls to the next occurrence ---
// A bare time (no date) is parsed by PHP's DateTime against the real
// system clock's current date, not against $now's date - $now is only
// used for the rollover comparison. So the "future"/"past" labels below
// have to be built relative to the actual current UTC time, not a fixed
// fictional date, or this test would be timezone/date-dependent noise.
$nowUtc = new DateTime('now', new DateTimeZone('UTC'));
$nowUtc->setTime((int)$nowUtc->format('H'), (int)$nowUtc->format('i'), 0);
$noonUtc = $nowUtc->getTimestamp();

$futureBareTime = (clone $nowUtc)->modify('+2 hours');
assert_equal(
    $futureBareTime->getTimestamp(),
    parse_resets_at(strtolower($futureBareTime->format('g:ia')) . ' (UTC)', $noonUtc),
    'parse_resets_at: bare future time today is used as-is'
);

$pastBareTime = (clone $nowUtc)->modify('-2 hours');
assert_equal(
    (clone $pastBareTime)->modify('+1 day')->getTimestamp(),
    parse_resets_at(strtolower($pastBareTime->format('g:ia')) . ' (UTC)', $noonUtc),
    'parse_resets_at: bare time already passed today rolls to tomorrow'
);
assert_equal(
    strtotime('2026-07-04 20:00:00 UTC'),
    parse_resets_at('Jul 4, 8pm (UTC)', $noonUtc),
    'parse_resets_at: dated time is used as-is, no rollover'
);
assert_equal(null, parse_resets_at('sometime soon', $noonUtc), 'parse_resets_at: no trailing timezone -> null');
assert_equal(null, parse_resets_at('3pm (Not/AZone)', $noonUtc), 'parse_resets_at: unrecognized timezone -> null');

// --- enrich_quota_resets(): adds resets_at only where parseable, leaves everything else untouched ---
$enriched = enrich_quota_resets([
    'session' => ['pct' => 50, 'resets' => '3pm (UTC)'],
    'week_all' => ['pct' => 10, 'resets' => 'garbage'],
    'captured_at' => '2026-06-15T12:00:00+0000',
], $noonUtc);
assert_true(isset($enriched['session']['resets_at']), 'enrich_quota_resets: adds resets_at to a parseable bucket');
assert_true(!isset($enriched['week_all']['resets_at']), 'enrich_quota_resets: leaves resets_at unset for an unparseable bucket');
assert_equal('2026-06-15T12:00:00+0000', $enriched['captured_at'], 'enrich_quota_resets: non-array values (captured_at) pass through untouched');

// --- read/write cache round trip ---
reset_quota_state();
write_quota_cache(['session' => ['pct' => 5]], 1000);
$cache = read_quota_cache();
assert_true($cache !== null, 'read_quota_cache: reads back what write_quota_cache wrote');
assert_equal(5, $cache['quota']['session']['pct'] ?? null, 'read_quota_cache: quota payload round-trips');
assert_equal(1000, $cache['fetched_at'] ?? null, 'read_quota_cache: fetched_at round-trips');

// --- claim_quota_refresh_marker(): atomic exclusive create ---
reset_quota_state();
assert_true(claim_quota_refresh_marker(), 'claim_quota_refresh_marker: succeeds when no marker exists');
assert_true(!claim_quota_refresh_marker(), 'claim_quota_refresh_marker: fails while a marker already exists (this is what prevents duplicate scrapes)');
assert_true(quota_refresh_in_flight(), 'quota_refresh_in_flight: true for a marker written just now');

@unlink(quota_refresh_marker_file());
file_put_contents(quota_refresh_marker_file(), (string)(time() - quota_timeout_seconds() - 5));
assert_true(!quota_refresh_in_flight(), 'quota_refresh_in_flight: false for a marker older than the scrape timeout (treated as abandoned)');

reset_quota_state();

try {
    // --- get_quota(): no cache yet -> triggers a background refresh and
    // returns immediately without waiting for it ---
    putenv('FAKE_QUOTA_MODE=ok');
    $result = get_quota();
    assert_true($result['refreshing'] ?? false, 'get_quota() with no cache: reports a refresh as triggered');
    assert_equal(null, $result['quota'], 'get_quota() with no cache: nothing to show yet');

    $appeared = wait_until(fn () => read_quota_cache() !== null, quota_timeout_seconds() + 2.0);
    assert_true($appeared, 'get_quota(): background refresh populates the cache within the timeout');

    $result = get_quota();
    assert_true($result['ok'], 'get_quota() after background refresh: ok');
    assert_equal(false, $result['stale'], 'get_quota() after background refresh: not stale');
    assert_equal(false, $result['refreshing'], 'get_quota() after background refresh: no refresh in flight anymore');
    assert_equal(42, $result['quota']['session']['pct'] ?? null, 'get_quota(): quota matches the fixture claude-quota output');
    assert_true(is_int($result['quota']['session']['resets_at'] ?? null), 'get_quota(): resets_at was computed and cached alongside the raw reading');

    // --- get_quota(): fresh cache is served without spawning another refresh ---
    $beforeMarker = file_exists(quota_refresh_marker_file());
    $result = get_quota();
    assert_true($result['cached'] ?? false, 'get_quota() within TTL: served from cache');
    assert_equal(false, $result['refreshing'], 'get_quota() within TTL: does not trigger another refresh');
    assert_equal($beforeMarker, file_exists(quota_refresh_marker_file()), 'get_quota() within TTL: no new marker was created');

    // --- get_quota(): a stale cache is still returned immediately, marked stale, with a refresh kicked off ---
    $cache = read_quota_cache();
    write_quota_cache($cache['quota'], time() - quota_cache_ttl_seconds() - 1);
    $result = get_quota();
    assert_true($result['ok'], 'get_quota() with stale cache: still ok');
    assert_true($result['stale'] ?? false, 'get_quota() with stale cache: marked stale');
    assert_true($result['refreshing'] ?? false, 'get_quota() with stale cache: a background refresh was triggered');
    assert_equal(42, $result['quota']['session']['pct'] ?? null, 'get_quota() with stale cache: still returns the last-known reading, not null');

    wait_until(fn () => !quota_refresh_in_flight(), quota_timeout_seconds() + 2.0);

    // --- trigger_background_quota_refresh(): concurrent callers don't double-spawn ---
    reset_quota_state();
    $counterFile = sys_get_temp_dir() . '/csm-test-quota-counter-' . getmypid() . '.txt';
    @unlink($counterFile);
    putenv('FAKE_QUOTA_SLEEP=2');
    putenv("FAKE_QUOTA_COUNTER_FILE={$counterFile}");

    trigger_background_quota_refresh();
    trigger_background_quota_refresh(); // simulates a second near-simultaneous request

    usleep(400000); // well before the fixture's 2s sleep finishes
    $invocations = file_exists($counterFile) ? count(array_filter(explode("\n", trim((string)file_get_contents($counterFile))))) : 0;
    assert_equal(1, $invocations, 'trigger_background_quota_refresh(): a second call while one is in flight does not spawn a duplicate scrape');

    wait_until(fn () => !quota_refresh_in_flight(), quota_timeout_seconds() + 2.0);
    putenv('FAKE_QUOTA_SLEEP');
    putenv('FAKE_QUOTA_COUNTER_FILE');
    @unlink($counterFile);

    // --- run_claude_quota(): a failing scrape is reported, not silently swallowed ---
    reset_quota_state();
    putenv('FAKE_QUOTA_MODE=fail');
    $failResult = run_claude_quota();
    putenv('FAKE_QUOTA_MODE');
    assert_true(!$failResult['ok'], 'run_claude_quota(): a non-zero exit is reported as a failure');
    assert_true(($failResult['message'] ?? '') !== '', 'run_claude_quota(): failure includes a message');
} finally {
    reset_quota_state();
    putenv('FAKE_QUOTA_MODE');
    putenv('FAKE_QUOTA_SLEEP');
    putenv('FAKE_QUOTA_COUNTER_FILE');
}

test_exit();
