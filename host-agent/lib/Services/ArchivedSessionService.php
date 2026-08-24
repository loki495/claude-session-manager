<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\SidecarStore;

/**
 * Archived/dormant session listing and dashboard-wide transcript search.
 * Split out of SessionService.php (2026-08-24 readability audit - see the
 * plan this followed) - depends on core SessionService (list_all_sessions,
 * title_cascade) but nothing else. Methods/bodies moved verbatim, no
 * behavior changes.
 */
class ArchivedSessionService
{
    /**
     * Every known transcript NOT in $excludeClaudeSessionIds (the
     * currently-tracked sessions already shown in the main list) - the
     * dormant/archived half of the unify-claude-sessions plan's dashboard
     * segmentation. Sorted most-recently-active first (the file's own
     * mtime - the simplest available proxy for "last touched" without
     * re-parsing a potentially huge transcript).
     *
     * @param string[] $excludeClaudeSessionIds
     * @return array<int, array{claude_session_id:string, cwd:?string, title:string, last_activity:int}>
     */
    public static function list_archived_sessions(array $excludeClaudeSessionIds): array
    {
        $exclude = array_flip($excludeClaudeSessionIds);
        $archived = [];

        foreach (TranscriptService::list_all_transcripts() as $t) {
            if (isset($exclude[$t['claude_session_id']])) {
                continue;
            }

            $archived[] = [
                'claude_session_id' => $t['claude_session_id'],
                'cwd' => $t['cwd'],
                'title' => SessionService::title_cascade($t['ai_title'], null, $t['cwd'], $t['claude_session_id']),
                'last_activity' => $t['last_activity'],
            ];
        }

        usort($archived, fn(array $a, array $b) => $b['last_activity'] <=> $a['last_activity']);

        return $archived;
    }

    /**
     * The dispatcher-facing wrapper around list_archived_sessions() - an
     * on-demand action (only ever called when Andres actually opens the
     * dashboard's archived-sessions toggle, never part of the regular
     * poll - see this project's own workflow reminders about being extra
     * careful with anything periodic vs explicitly user-triggered) that
     * computes the exclude set itself by re-running list_all_sessions().
     * That's a second full tracked-session scan on top of whatever poll
     * already did one moments ago, but it's cheap and only happens once
     * per toggle-open, not worth threading the caller's already-known
     * list through an extra request parameter for.
     *
     * @return array{archived: array<int, array>}
     */
    public static function list_archived_dashboard(): array
    {
        $trackedIds = [];

        foreach (SessionService::list_all_sessions()['sessions'] as $s) {
            if (is_string($s['claude_session_id'] ?? null)) {
                $trackedIds[] = $s['claude_session_id'];
            }
        }

        return ['archived' => self::list_archived_sessions($trackedIds)];
    }

    /**
     * Dashboard-wide content search - unlike the archived list's own
     * client-side title/name filter (index.js's filterArchivedRows(),
     * which only ever matches what's already rendered in a row), this
     * greps every known transcript's real message content, live and
     * archived alike, server-side. On-demand only (the dashboard's own
     * search box, debounced client-side - never part of the regular poll),
     * same "expensive, user-triggered, not periodic" reasoning as
     * list_archived_dashboard() above.
     *
     * A result's own claude_session_id doubling as a currently-live tmux
     * session name is what tells the caller which page to link to
     * (session.php vs archived_session.php) - same live-vs-archived
     * reconciliation list_archived_dashboard() already does, reused here
     * rather than a second tracked-session scan.
     *
     * @return array{ok:bool, results:array<int, array{claude_session_id:string, session_name:?string, title:string, cwd:?string, last_activity:int, matches:array<int, array{line:int, snippet:string, role:?string, kind:string}>}>}
     */
    public static function search_transcripts(string $query, int $maxSessions, int $maxMatchesPerSession): array
    {
        if (trim($query) === '') {
            return ['ok' => true, 'results' => []];
        }

        $liveNamesByClaudeId = [];

        foreach (SessionService::list_all_sessions()['sessions'] as $s) {
            if (is_string($s['claude_session_id'] ?? null)) {
                $liveNamesByClaudeId[$s['claude_session_id']] = $s['name'];
            }
        }

        $transcripts = TranscriptService::list_all_transcripts();
        usort($transcripts, fn(array $a, array $b) => $b['last_activity'] <=> $a['last_activity']);

        $results = [];

        foreach ($transcripts as $t) {
            $matches = TranscriptService::search_transcript_file($t['path'], $query, max(1, $maxMatchesPerSession));

            if ($matches === []) {
                continue;
            }

            $results[] = [
                'claude_session_id' => $t['claude_session_id'],
                'session_name' => $liveNamesByClaudeId[$t['claude_session_id']] ?? null,
                'title' => SessionService::title_cascade($t['ai_title'], null, $t['cwd'], $t['claude_session_id']),
                'cwd' => $t['cwd'],
                'last_activity' => $t['last_activity'],
                'matches' => $matches,
            ];

            if (count($results) >= $maxSessions) {
                break;
            }
        }

        return ['ok' => true, 'results' => $results];
    }

    /**
     * Per-session content search for a currently-live (tracked) session -
     * resolves $name to its claude_session_id via the sidecar, same
     * lookup session_history() already does, then defers to
     * transcript_search_for_claude_session() below.
     *
     * @return array{ok:bool, matches?:array<int, array>, message?:string}
     */
    public static function session_transcript_search(string $name, string $query, int $maxMatches): array
    {
        $sidecar = SidecarStore::read_sidecar($name);
        $claudeSessionId = $sidecar['claude_session_id'] ?? null;

        if (!is_string($claudeSessionId)) {
            return ['ok' => false, 'message' => 'No transcript recorded for this session'];
        }

        return self::transcript_search_for_claude_session($claudeSessionId, $query, $maxMatches);
    }

    /**
     * The archived-session-view counterpart to session_transcript_search()
     * above - same search, keyed straight by $claudeSessionId with no
     * sidecar/tmux-name lookup, same reasoning as archived_session_history().
     *
     * @return array{ok:bool, matches?:array<int, array>, message?:string}
     */
    public static function archived_session_transcript_search(string $claudeSessionId, string $query, int $maxMatches): array
    {
        return self::transcript_search_for_claude_session($claudeSessionId, $query, $maxMatches);
    }

    /**
     * Shared by session_transcript_search()/archived_session_transcript_search() -
     * both just want transcript matches once they know which claude_session_id
     * to search, same split as SessionDetailService::
     * transcript_page_for_claude_session() uses for paging.
     *
     * @return array{ok:bool, matches?:array<int, array>, message?:string}
     */
    private static function transcript_search_for_claude_session(string $claudeSessionId, string $query, int $maxMatches): array
    {
        $path = TranscriptService::find_transcript_path($claudeSessionId);

        if ($path === null) {
            return ['ok' => false, 'message' => 'Transcript file not found'];
        }

        return ['ok' => true, 'matches' => TranscriptService::search_transcript_file($path, $query, max(1, min($maxMatches, 100)))];
    }
}
