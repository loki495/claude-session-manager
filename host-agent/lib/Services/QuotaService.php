<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\GlobalStateStore;

/**
 * Claude usage/rate-limit quota - read straight from the file this app's
 * own statusLine script writes on every Claude Code status-line render
 * (see quota_from_statusline_state()), event-driven, no scraping of any
 * kind. An earlier design also fell back to scanning a live tmux pane
 * directly, and beyond that to an external `claude-quota` binary (a slow,
 * 10-40s scrape of Claude Code's own /usage panel, cached and
 * background-refreshed) - both were deleted 2026-08-22 as confirmed dead
 * code: once any session has ever written the statusline-state file even
 * once, it never returns null again, so neither fallback could actually be
 * reached anymore. See CONTRIBUTING.md/git history if any of that ever
 * needs resurrecting.
 */
class QuotaService
{
    /**
     * Context-window usage for one specific session's own pane - unlike
     * every other bucket in this class, this is genuinely per-session, not
     * account-wide. A dedicated capture-pane call keyed to the requested
     * session.
     *
     * Reads StatuslineMarkerService's own JSON marker (parse_marker_from_pane())
     * rather than regexing Claude Code's own rendered status line text for
     * "ctx: N%" (removed 2026-08-21 - see that method's own former
     * docblock). That used to be a second, independent path to the exact
     * same underlying context_window.used_percentage number, and a
     * fragile one: it only ever matched because Claude Code's DEFAULT
     * status line happens to render that text, but this app always
     * installs a CUSTOM statusLine script (see StatuslineMarkerService),
     * which fully replaces whatever Claude Code's own default line would
     * have shown - so this only worked at all when the user's own custom
     * script happened to independently print matching "ctx:" text of its
     * own. The marker's ctx_pct is this app's OWN guaranteed, controlled
     * output format instead, always present regardless of anything else
     * the user's custom script prints.
     *
     * Returns null if the session isn't live, the marker isn't installed
     * yet, or its pane doesn't currently show a status line (e.g.
     * mid-response, or mid a slash command).
     */
    public static function live_context_pct(string $sessionName): ?int
    {
        $contextPct = StatuslineMarkerService::parse_marker_from_pane(TmuxService::tmux_capture_pane($sessionName))['context_used_percentage'];

        return $contextPct !== null ? (int)round($contextPct) : null;
    }

    /**
     * Reads account-wide rate-limit quota this app's own statusLine script
     * writes straight to disk (see StatuslineMarkerService's QUOTA_CAPTURE
     * block) on every Claude Code status-line render - event-driven, no
     * tmux/capture-pane involved at all. This is get_quota()'s ONLY source
     * now (the tmux-pane-scraping fallback, and the external `claude-quota`
     * binary scrape + its cache/background-refresh machinery behind it,
     * were both deleted 2026-08-22 as dead code - once ANY session has ever
     * written this file even once, it never returns null again below, so
     * neither of those fallback paths could ever actually be reached; see
     * the git history for what used to live here). resets_at here is a real
     * Unix epoch straight from Claude Code's own statusLine JSON
     * (rate_limits.*.resets_at), not reconstructed from rounded pane
     * duration text like "1h 53m" - the exact source of the 2026-08-05
     * jitter bug documented in PushDeliveryService::check_and_send_quota_pushes().
     *
     * The shell side already merges each write against whatever it last
     * saw (see the jq filter in StatuslineMarkerService) so a bucket only
     * ever moves DOWN when its resets_at also moved forward - a genuine
     * window rollover - rather than whichever session's script happened
     * to fire most recently. This method just reads the result; no merge
     * logic lives here.
     *
     * Returns null only on a genuinely fresh install (the script has never
     * fired at all yet, or no session has had a turn since) - get_quota()
     * reports "no quota data yet" for that case, nothing more to fall back
     * to.
     *
     * @return array{quota:array, fetched_at:int}|null
     */
    public static function quota_from_statusline_state(): ?array
    {
        $decoded = GlobalStateStore::read(Config::quota_live_state_key());

        if (!is_array($decoded) || !isset($decoded['captured_at']) || !is_int($decoded['captured_at'])) {
            return null;
        }

        $quota = [];

        foreach (['session', 'week_all'] as $key) {
            $bucket = $decoded[$key] ?? null;

            if (is_array($bucket) && isset($bucket['pct'], $bucket['resets_at']) && is_int($bucket['pct']) && is_int($bucket['resets_at'])) {
                $quota[$key] = ['pct' => $bucket['pct'], 'resets_at' => $bucket['resets_at']];
            }
        }

        if ($quota === []) {
            return null;
        }

        $fetchedAt = $decoded['captured_at'];
        $quota['captured_at'] = date('c', $fetchedAt);

        return ['quota' => $quota, 'fetched_at' => $fetchedAt];
    }

    /**
     * $sessionName, when given, additionally overlays that ONE session's own
     * context-window percentage (see live_context_pct()) as a 'context'
     * bucket - independent of where the rest of the quota data came from,
     * since context is a completely separate, per-session concept from the
     * account-wide session/week_all buckets. Omitted entirely (not merged)
     * when null/not live - the caller (the dashboard, which has no single
     * relevant session) simply doesn't pass one, and session.php's footer
     * degrades to showing session/week only if its own session's pane
     * doesn't currently have a status line to read.
     *
     * `cached`/`stale`/`refreshing` are always false now - kept in the
     * return shape (rather than dropped) purely so the existing frontend
     * (quota-footer.js's "cached, stale"/"refreshing in background…" meta
     * text) doesn't need a matching change; they can never actually be
     * true anymore now that quota_from_statusline_state() is the only
     * source (see this class's own docblock for why the cache/refresh
     * cascade behind them was deleted).
     *
     * @return array{ok:bool, quota:?array, fetched_at:?int, cached:bool, stale:bool, refreshing:bool, message?:string}
     */
    public static function get_quota(?string $sessionName = null): array
    {
        $contextPct = $sessionName !== null && $sessionName !== '' ? self::live_context_pct($sessionName) : null;

        $live = self::quota_from_statusline_state();

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
            $result = [
                'ok' => false,
                'quota' => null,
                'fetched_at' => null,
                'cached' => false,
                'stale' => false,
                'refreshing' => false,
                'message' => 'No quota data yet - open a Claude Code session to populate it',
            ];
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
