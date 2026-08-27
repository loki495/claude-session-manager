<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\SidecarStore;
use HostAgent\Runtimes\RuntimeType;

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
                'agent' => 'claude',
                'agent_label' => 'Claude Code',
            ];
        }

        foreach (AntigravityTranscriptService::list_all_transcripts() as $t) {
            if (isset($exclude[$t['claude_session_id']])) {
                continue;
            }

            $archived[] = [
                'claude_session_id' => $t['claude_session_id'],
                'cwd' => $t['cwd'],
                'title' => SessionService::title_cascade(null, null, $t['cwd'], $t['claude_session_id']),
                'last_activity' => $t['last_activity'],
                'agent' => $t['agent'] ?? 'antigravity',
                'agent_label' => 'Antigravity',
            ];
        }

        // OpenCode archived sessions: every session in opencode.db not
        // currently tracked (i.e. dormant). Title comes from session.title
        // directly (see OpenCodeTranscriptService::find_session_title), not
        // a transcript-file ai-title.
        $opencodeDbPath = Config::opencode_db_path();

        if (is_file($opencodeDbPath)) {
            try {
                $pdo = new \PDO('sqlite:' . $opencodeDbPath, null, null, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
                ]);
                $pdo->exec('PRAGMA busy_timeout=5000');
                $stmt = $pdo->query('SELECT id, directory, title, time_updated FROM session ORDER BY time_updated DESC');
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $id = is_string($row['id'] ?? null) ? $row['id'] : null;
                    if ($id === null || isset($exclude[$id])) {
                        continue;
                    }
                    if (!OpenCodeTranscriptService::is_opencode_id($id)) {
                        continue;
                    }
                    $cwd = is_string($row['directory'] ?? null) && $row['directory'] !== '' ? $row['directory'] : null;
                    $title = is_string($row['title'] ?? null) && trim($row['title']) !== '' ? $row['title'] : $id;
                    $archived[] = [
                        'claude_session_id' => $id,
                        'cwd' => $cwd,
                        'title' => $title,
                        'last_activity' => is_numeric($row['time_updated'] ?? null) ? (int)($row['time_updated'] / 1000) : 0,
                        'agent' => 'opencode',
                        'agent_label' => 'OpenCode',
                    ];
                }
            } catch (\Throwable $e) {
                // Best-effort: on DB error, just skip opencode archived
            }
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

        // Merge headless sessions into the live-name map too.
        foreach (SidecarStore::list_runtime_sidecars(RuntimeType::HEADLESS) as $row) {
            if (is_string($row['session_name'] ?? null)) {
                $liveNamesByClaudeId[$row['session_name']] = $row['session_name'];
            }
        }

        $results = [];

        // Claude Code transcripts (JSONL files).
        $transcripts = TranscriptService::list_all_transcripts();
        usort($transcripts, fn(array $a, array $b) => $b['last_activity'] <=> $a['last_activity']);

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

        // OpenCode transcripts (opencode.db).
        if (count($results) < $maxSessions) {
            $ocTranscripts = OpenCodeTranscriptService::list_all_transcripts();

            foreach ($ocTranscripts as $t) {
                $matches = OpenCodeTranscriptService::search_transcript($t['session_id'], $query, max(1, $maxMatchesPerSession));

                if ($matches === []) {
                    continue;
                }

                $results[] = [
                    'claude_session_id' => $t['session_id'],
                    'session_name' => $liveNamesByClaudeId[$t['session_id']] ?? null,
                    'title' => SessionService::title_cascade($t['title'], null, $t['cwd'], $t['session_id']),
                    'cwd' => $t['cwd'],
                    'last_activity' => $t['last_activity'],
                    'matches' => $matches,
                ];

                if (count($results) >= $maxSessions) {
                    break;
                }
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
        $path = TranscriptRouter::find_transcript_path($claudeSessionId);

        if ($path === null) {
            return ['ok' => false, 'message' => 'Transcript file not found'];
        }

        if (TranscriptRouter::is_opencode_path($path)) {
            return ['ok' => true, 'matches' => OpenCodeTranscriptService::search_transcript($claudeSessionId, $query, max(1, min($maxMatches, 100)))];
        }

        return ['ok' => true, 'matches' => TranscriptService::search_transcript_file($path, $query, max(1, min($maxMatches, 100)))];
    }
}
