<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Claude usage/rate-limit quota, read from whichever's fastest/freshest
 * available: a live tmux session's own status line first (near-instant),
 * falling back to a cached scrape (claude-quota, slow - 10-40s, always
 * refreshed in the background, never inline).
 */
class QuotaService
{
    public static function parse_resets_at(string $resets, int $now): ?int
    {
        if (preg_match('/^(.*)\s\(([^)]+)\)$/', $resets, $m) !== 1) {
            return null;
        }

        $timePart = trim($m[1]);
        $tzName = trim($m[2]);
        $hasDate = preg_match('/^[A-Za-z]{3}\s+\d{1,2}\b/', $timePart) === 1;

        // Normalize a bare "8pm" to "8:00pm" - PHP's parser otherwise misreads
        // the hour as a timezone abbreviation when a date precedes it (e.g.
        // "Jul 10 8pm"), and strip the comma between date and time for the
        // same reason.
        $normalized = preg_replace('/(?<!:)\b(\d{1,2})([ap]m)\b/i', '$1:00$2', str_replace(',', '', $timePart));

        try {
            $dt = new \DateTime((string)$normalized, new \DateTimeZone($tzName));
        } catch (\Throwable) {
            return null;
        }

        if (!$hasDate && $dt->getTimestamp() <= $now) {
            $dt->modify('+1 day');
        }

        return $dt->getTimestamp();
    }

    /**
     * @param array<string, mixed> $quota
     * @return array<string, mixed>
     */
    public static function enrich_quota_resets(array $quota, int $now): array
    {
        foreach ($quota as $key => $bucket) {
            if (!is_array($bucket) || !isset($bucket['resets']) || !is_string($bucket['resets'])) {
                continue;
            }

            $resetsAt = self::parse_resets_at($bucket['resets'], $now);

            if ($resetsAt !== null) {
                $quota[$key]['resets_at'] = $resetsAt;
            }
        }

        return $quota;
    }

    /**
     * Claude Code's own status line already shows both rate-limit percentages
     * this app cares about, e.g. "... | ctx: 4% | 5h: 51% (1h 53m) | 7d: 40%
     * (5d 8h) ..." - "5h" is the rolling 5-hour window (labeled "session" to
     * match claude-quota's own key), "7d" the weekly one (labeled "week_all").
     * Verified live 2026-08-02 against claude-quota's own real scrape at
     * nearly the same moment: matching percentages (51% / ~41%). Only
     * "ctx" (context-window usage, not account quota - deliberately not
     * parsed here) is guaranteed present from the very first prompt; "5h"/"7d"
     * only appear once Claude Code has actually made an API call in that
     * pane, so a fresh welcome-screen pane with nothing sent yet won't match.
     *
     * @return array{session:array{pct:int,resets:string},week_all:array{pct:int,resets:string}}|null
     */
    public static function parse_quota_from_pane(string $paneContent): ?array
    {
        if (preg_match('/5h:\s*(\d+)%\s*\(([^)]+)\)/u', $paneContent, $sessionMatch) !== 1) {
            return null;
        }

        if (preg_match('/7d:\s*(\d+)%\s*\(([^)]+)\)/u', $paneContent, $weekMatch) !== 1) {
            return null;
        }

        return [
            'session' => ['pct' => (int)$sessionMatch[1], 'resets' => trim($sessionMatch[2])],
            'week_all' => ['pct' => (int)$weekMatch[1], 'resets' => trim($weekMatch[2])],
        ];
    }

    /**
     * Parses a short duration like "1h 53m" or "5d 8h" - exactly the shape
     * Claude Code's status line shows next to each percentage (see
     * parse_quota_from_pane()) - into seconds. Distinct from
     * parse_resets_at(), which parses a full clock-time-plus-timezone string
     * instead (what claude-quota's slower /usage-panel scrape produces).
     */
    public static function parse_footer_duration(string $duration): ?int
    {
        if (preg_match('/^(?:(\d+)d)?\s*(?:(\d+)h)?\s*(?:(\d+)m)?$/u', trim($duration), $m) !== 1) {
            return null;
        }

        $seconds = ((int)($m[1] ?? 0)) * 86400 + ((int)($m[2] ?? 0)) * 3600 + ((int)($m[3] ?? 0)) * 60;

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * Context-window usage for one specific session's own pane - unlike
     * every other bucket in this class, this is genuinely per-session, not
     * account-wide (see parse_quota_from_pane()'s docblock: "ctx" is
     * deliberately not parsed there for exactly that reason). A dedicated
     * capture-pane call keyed to the requested session, not folded into
     * quota_from_live_pane()'s any-live-session-will-do scan, which would
     * otherwise silently return some OTHER session's context percentage.
     * Returns null if the session isn't live or its pane doesn't currently
     * show a status line (e.g. mid-response, or mid a slash command).
     */
    public static function live_context_pct(string $sessionName): ?int
    {
        $paneContent = TmuxService::tmux_capture_pane($sessionName);

        if (preg_match('/ctx:\s*(\d+)%/u', $paneContent, $m) !== 1) {
            return null;
        }

        return (int)$m[1];
    }

    /**
     * Tries every currently-live managed tmux session's pane for the
     * status-line quota shape (see parse_quota_from_pane()) and returns the
     * first one found - a single capture-pane call per live session, no
     * scraping subprocess, so this is near-instant compared to
     * run_claude_quota() below. Rate limits are account-wide, not per-session,
     * so it doesn't matter which live session's pane happens to answer first.
     * Returns null if no live session's pane currently shows quota (no
     * sessions running at all, or every one is still on its pre-first-message
     * welcome screen) - callers should fall back to run_claude_quota()'s
     * cached reading in that case.
     *
     * @return array{quota:array, fetched_at:int}|null
     */
    public static function quota_from_live_pane(): ?array
    {
        foreach (TmuxService::list_cc_tmux_sessions() as $tmuxSession) {
            $parsed = self::parse_quota_from_pane(TmuxService::tmux_capture_pane($tmuxSession['name']));

            if ($parsed === null) {
                continue;
            }

            $now = time();
            $quota = $parsed;

            foreach ($quota as $key => $bucket) {
                $seconds = self::parse_footer_duration($bucket['resets']);

                if ($seconds !== null) {
                    $quota[$key]['resets_at'] = $now + $seconds;
                }
            }

            $quota['captured_at'] = date('c', $now);

            return ['quota' => $quota, 'fetched_at' => $now];
        }

        return null;
    }

    /**
     * Runs the claude-quota script (a wrapper that scrapes Claude Code's own
     * /usage panel via a detached screen session - see the script itself for
     * the mechanism). This is slow, 10-40s, since it drives a real TUI, so it
     * must only ever be called in the background (see trigger_background_quota_refresh()),
     * never inline while a request is waiting. Only still reached (via
     * get_quota()) when no live session's pane already shows quota (see
     * quota_from_live_pane()) - e.g. no sessions currently running at all.
     *
     * @return array{ok:bool, quota?:array, message?:string}
     */
    public static function run_claude_quota(): array
    {
        $result = ProcessRunner::run_process(['timeout', (string)Config::quota_timeout_seconds(), Config::claude_quota_bin()]);

        if ($result['exit'] !== 0) {
            $message = trim($result['stderr']) !== ''
                ? trim($result['stderr'])
                : "claude-quota exited with code {$result['exit']}";

            return ['ok' => false, 'message' => $message];
        }

        $decoded = json_decode($result['stdout'], true);

        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'claude-quota returned malformed JSON'];
        }

        return ['ok' => true, 'quota' => self::enrich_quota_resets($decoded, time())];
    }

    /**
     * @return array{quota:array, fetched_at:int}|null
     */
    public static function read_quota_cache(): ?array
    {
        $raw = @file_get_contents(Config::quota_cache_file());

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['quota'], $decoded['fetched_at']) || !is_array($decoded['quota'])) {
            return null;
        }

        return ['quota' => $decoded['quota'], 'fetched_at' => (int)$decoded['fetched_at']];
    }

    public static function write_quota_cache(array $quota, int $fetchedAt): void
    {
        $dir = dirname(Config::quota_cache_file());

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents(Config::quota_cache_file(), json_encode(['quota' => $quota, 'fetched_at' => $fetchedAt]));
    }

    public static function quota_refresh_marker_file(): string
    {
        return Config::quota_cache_file() . '.refreshing';
    }

    /**
     * A refresh marker younger than the scrape timeout means some earlier
     * request already spawned a background refresh that's presumably still
     * running - don't spawn a second one. A marker older than the timeout is
     * treated as abandoned (the process that wrote it crashed, or the host
     * rebooted, without cleaning up) rather than blocking refreshes forever.
     */
    public static function quota_refresh_in_flight(): bool
    {
        $raw = @file_get_contents(self::quota_refresh_marker_file());

        if ($raw === false) {
            return false;
        }

        return (time() - (int)trim($raw)) < Config::quota_timeout_seconds();
    }

    /**
     * Atomically claims the right to spawn a refresh: fopen(..., 'x') is
     * O_CREAT|O_EXCL, which fails if the marker already exists. That's the
     * part that actually prevents a race - e.g. two browser tabs (or two
     * quick page reloads) both hitting /quota.php within the same instant
     * would otherwise both see "no marker yet" from a plain
     * file_exists()-then-write check and both spawn a scrape. With an
     * exclusive create, only one of them can ever win.
     */
    public static function claim_quota_refresh_marker(): bool
    {
        $handle = @fopen(self::quota_refresh_marker_file(), 'x');

        if ($handle === false) {
            return false;
        }

        fwrite($handle, (string)time());
        fclose($handle);

        return true;
    }

    /**
     * Fires a fully detached background process that runs the slow
     * claude-quota scrape and writes the result to the cache file itself, so
     * the request that triggered this can return immediately instead of
     * waiting on it. Stdio is bound to /dev/null via proc_open's 'file'
     * descriptor type (not pipes) specifically so the child has nothing tied
     * to this short-lived agent.php connection process - it keeps running
     * fine after this process has already sent its response and exited.
     *
     * @return bool true if a refresh is (now, or already) in flight
     */
    public static function trigger_background_quota_refresh(): bool
    {
        $dir = dirname(self::quota_refresh_marker_file());

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        if (!self::claim_quota_refresh_marker()) {
            // Someone else's marker is already there. If it's fresh, that
            // refresh is genuinely in flight - nothing more to do. If it's
            // stale (abandoned by a crashed process), reclaim it once; if
            // even that loses a race to another request doing the same
            // thing, defer to whichever of us won rather than double-spawning.
            if (self::quota_refresh_in_flight()) {
                return true;
            }

            @unlink(self::quota_refresh_marker_file());

            if (!self::claim_quota_refresh_marker()) {
                return true;
            }
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        // One directory deeper than when this lived directly in lib/
        // (lib/Services/ now, not lib/) - quota_refresh.php itself hasn't
        // moved, still directly under host-agent/.
        $process = @proc_open([PHP_BINARY, __DIR__ . '/../../quota_refresh.php'], $descriptors, $pipes);

        if (!is_resource($process)) {
            @unlink(self::quota_refresh_marker_file());
            return false;
        }

        // Deliberately not proc_close()'d - that blocks the caller until the
        // child exits, defeating the entire point of backgrounding this.
        return true;
    }

    /**
     * Cached in front of run_claude_quota() since that's expensive (spins up a
     * real `claude` TUI in a screen session just to read a percentage) and
     * always non-blocking: a stale/missing cache triggers a background
     * refresh (see trigger_background_quota_refresh()) and returns immediately
     * with whatever's cached (marked "stale") rather than making the request
     * wait 10-40s for a fresh scrape.
     *
     * $sessionName, when given, additionally overlays that ONE session's own
     * context-window percentage (see live_context_pct()) as a 'context'
     * bucket - independent of which of the branches below the rest of the
     * quota data came from, since context is a completely separate,
     * per-session concept from the account-wide session/week_all buckets.
     * Omitted entirely (not merged) when null/not live - the caller (the
     * dashboard, which has no single relevant session) simply doesn't pass
     * one, and session.php's footer degrades to showing session/week only if
     * its own session's pane doesn't currently have a status line to read.
     *
     * @return array{ok:bool, quota:?array, fetched_at:?int, cached:bool, stale:bool, refreshing:bool, message?:string}
     */
    public static function get_quota(?string $sessionName = null): array
    {
        $contextPct = $sessionName !== null && $sessionName !== '' ? self::live_context_pct($sessionName) : null;

        $live = self::quota_from_live_pane();

        if ($live !== null) {
            $result = [
                'ok' => true,
                'quota' => $live['quota'],
                'fetched_at' => $live['fetched_at'],
                'cached' => false,
                'stale' => false,
                'refreshing' => false,
            ];
        } else {
            $ttl = Config::quota_cache_ttl_seconds();
            $cache = self::read_quota_cache();
            $now = time();
            $fresh = $cache !== null && ($now - $cache['fetched_at']) < $ttl;

            if ($fresh) {
                $result = [
                    'ok' => true,
                    'quota' => $cache['quota'],
                    'fetched_at' => $cache['fetched_at'],
                    'cached' => true,
                    'stale' => false,
                    'refreshing' => false,
                ];
            } else {
                $refreshing = self::trigger_background_quota_refresh();

                if ($cache !== null) {
                    $result = [
                        'ok' => true,
                        'quota' => $cache['quota'],
                        'fetched_at' => $cache['fetched_at'],
                        'cached' => true,
                        'stale' => true,
                        'refreshing' => $refreshing,
                    ];
                } else {
                    $result = [
                        'ok' => $refreshing,
                        'quota' => null,
                        'fetched_at' => null,
                        'cached' => false,
                        'stale' => false,
                        'refreshing' => $refreshing,
                        'message' => $refreshing
                            ? 'Fetching quota for the first time - this can take up to a minute'
                            : 'Could not start quota refresh',
                    ];
                }
            }
        }

        if ($contextPct === null) {
            return $result;
        }

        $contextBucket = ['context' => ['pct' => $contextPct]];
        $result['quota'] = is_array($result['quota']) ? $contextBucket + $result['quota'] : $contextBucket;
        $result['ok'] = true;
        $result['fetched_at'] ??= time();

        return $result;
    }
}
