<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\SidecarStore;

/**
 * Detail/history/attachment paging for BOTH live and archived sessions -
 * kept together because both sides share two private helpers
 * (transcript_page_for_claude_session, read_attachment_for_claude_session)
 * neither would have any reason to exist without the other. Split out of
 * SessionService.php (2026-08-24 readability audit - see the plan this
 * followed) - depends on core SessionService (build_session_entry,
 * title_cascade) but nothing else. Methods/bodies moved verbatim, no
 * behavior changes.
 */
class SessionDetailService
{
    /**
     * Fresh, single-session snapshot for the detail page - re-derives
     * everything from a live tmux/proc scan by name rather than trusting
     * anything from the caller, same discipline as SessionLifecycleService::
     * kill_cc_session()'s whitelist re-check.
     *
     * @return array{ok:bool, message?:string, has_transcript?:bool, todos?:?array}
     */
    public static function session_detail(string $name): array
    {
        $tmuxSession = null;

        foreach (TmuxService::list_tracked_tmux_sessions() as $s) {
            if ($s['name'] === $name) {
                $tmuxSession = $s;
                break;
            }
        }

        if ($tmuxSession === null) {
            return ['ok' => false, 'message' => 'Session not found'];
        }

        $entry = SessionService::build_session_entry($tmuxSession, ProcessInspector::find_claude_processes(), ProcessInspector::build_ppid_map());
        $transcriptPath = $entry['claude_session_id'] !== null ? TranscriptService::find_transcript_path($entry['claude_session_id']) : null;

        // Scoped to session_detail() (the sidebar's own poll), not
        // build_session_entry() itself - that function also backs the
        // dashboard's bulk per-poll listing of EVERY session, where this
        // scan would run once per row for no reason any current UI uses.
        //
        // Cascade: prefer the Task family (TaskCreate/TaskUpdate) when this
        // session ever called it - null means it never did (this app's own
        // enableTaskTools checkbox is opt-in, and only newer models even
        // have the Task family available - see find_current_task_list()'s
        // own docblock), in which case fall back to the older TodoWrite
        // reader. Deliberately NOT "prefer whichever is non-empty" - a
        // Task-family session that has since deleted every task should
        // show nothing, not resurrect an unrelated historical TodoWrite
        // list from earlier in the same transcript.
        $tasks = $transcriptPath !== null ? TranscriptService::find_current_task_list($transcriptPath) : null;
        $todos = $tasks !== null ? $tasks : ($transcriptPath !== null ? TranscriptService::find_latest_todo_list($transcriptPath) : null);

        return ['ok' => true] + $entry + ['has_transcript' => $transcriptPath !== null, 'todos' => $todos];
    }

    /**
     * $after (when given) takes priority over $before - session.php's
     * regular poll passes the last line it's already rendered so only
     * genuinely new entries come back (see TranscriptService::
     * read_transcript_page_since()), while "Load older messages" (which
     * never has an $after) still pages backward via $before as before.
     *
     * $untilRealUserMessage - session.php's "Load until last message"
     * button (Andres's own ask 2026-08-24) - see TranscriptService::
     * read_transcript_page()'s own docblock for the full contract. Only
     * ever combined with $before, same as a normal "Load older messages"
     * click.
     *
     * @return array{ok:bool, entries?:array<int, array>, next_before?:?int, has_more?:bool, message?:string}
     */
    public static function session_history(string $name, ?int $before, int $limit, ?int $after = null, bool $untilRealUserMessage = false): array
    {
        $sidecar = SidecarStore::read_sidecar($name);
        $claudeSessionId = $sidecar['claude_session_id'] ?? null;

        if (!is_string($claudeSessionId)) {
            return ['ok' => false, 'message' => 'No transcript recorded for this session'];
        }

        return self::transcript_page_for_claude_session($claudeSessionId, $before, $limit, $after, $untilRealUserMessage);
    }

    /**
     * The archived-session-view counterpart to session_history() - same
     * paging behavior, but reads straight from $claudeSessionId with no
     * sidecar/tmux-name lookup at all, since a dormant session has neither.
     * A tracked (live) session's own $claudeSessionId is never accepted
     * here for this reason on its own - see the note on
     * archived_session_detail() below, which is what session.php actually
     * calls first and is where that distinction is enforced.
     *
     * `cwd` is included here too (not just archived_session_detail() below)
     * - archivedHistoryFragment() (its own "Load older messages" page fetch,
     * a separate on-demand request) needs it independently to relativize a
     * Write/Edit/Read tool-call entry's summary filename, see
     * TranscriptView::relativize_path().
     *
     * @return array{ok:bool, entries?:array<int, array>, next_before?:?int, has_more?:bool, message?:string, cwd?:?string}
     */
    public static function archived_session_history(string $claudeSessionId, ?int $before, int $limit, ?int $after = null): array
    {
        $result = self::transcript_page_for_claude_session($claudeSessionId, $before, $limit, $after);

        if (!($result['ok'] ?? false)) {
            return $result;
        }

        $path = TranscriptService::find_transcript_path($claudeSessionId);

        return $result + ['cwd' => $path !== null ? TranscriptService::find_first_cwd($path) : null];
    }

    /**
     * Shared by session_history() (resolves $claudeSessionId via a live
     * session's sidecar first) and archived_session_history() (already has
     * it) - both just want a page of a transcript once they know which one.
     *
     * @return array{ok:bool, entries?:array<int, array>, next_before?:?int, has_more?:bool, message?:string}
     */
    private static function transcript_page_for_claude_session(string $claudeSessionId, ?int $before, int $limit, ?int $after, bool $untilRealUserMessage = false): array
    {
        $path = TranscriptService::find_transcript_path($claudeSessionId);

        if ($path === null) {
            return ['ok' => false, 'message' => 'Transcript file not found'];
        }

        if ($after !== null) {
            return TranscriptService::read_transcript_page_since($path, $after, max(1, min($limit, 200)));
        }

        return TranscriptService::read_transcript_page($path, $before, max(1, min($limit, 200)), $untilRealUserMessage);
    }

    /**
     * The archived-session-view counterpart to session_detail() - the
     * header data (title/cwd/last_activity) for a dormant session's
     * read-only view, keyed by $claudeSessionId (a dormant session has no
     * tmux name to look up by). Deliberately does NOT check whether this
     * id also belongs to a currently-tracked (live) session - the
     * read-only archived view rendering something for a live session's id
     * is harmless (it'd just show slightly stale data next to the real,
     * live view reachable from the main list), and re-deriving "is this
     * one tracked" here would mean re-running list_all_sessions() on every
     * single archived-view page load for no real benefit.
     *
     * @return array{ok:bool, message?:string, claude_session_id?:string, cwd?:?string, title?:string, last_activity?:?int}
     */
    public static function archived_session_detail(string $claudeSessionId): array
    {
        $path = TranscriptService::find_transcript_path($claudeSessionId);

        if ($path === null) {
            return ['ok' => false, 'message' => 'Session not found'];
        }

        $cwd = TranscriptService::find_first_cwd($path);
        $aiTitle = TranscriptService::find_latest_ai_title($path);
        $mtime = @filemtime($path);

        return [
            'ok' => true,
            'claude_session_id' => $claudeSessionId,
            'cwd' => $cwd,
            'title' => SessionService::title_cascade($aiTitle, null, $cwd, $claudeSessionId),
            'last_activity' => $mtime !== false ? $mtime : null,
        ];
    }

    /**
     * Same claude_session_id -> transcript path resolution as
     * session_history() above, then delegates to
     * TranscriptService::read_attachment() to fetch one attachment's real
     * bytes for session_attachment.php.
     *
     * @return array{ok:bool, message?:string, data?:string, media_type?:string, filename?:string, size?:int}
     */
    public static function session_attachment(string $name, int $line, string $fileUuid): array
    {
        $sidecar = SidecarStore::read_sidecar($name);
        $claudeSessionId = $sidecar['claude_session_id'] ?? null;

        if (!is_string($claudeSessionId)) {
            return ['ok' => false, 'message' => 'No transcript recorded for this session'];
        }

        return self::read_attachment_for_claude_session($claudeSessionId, $line, $fileUuid);
    }

    /**
     * The archived-session-view counterpart to session_attachment() - same
     * reasoning as archived_session_history()/session_history() above.
     *
     * @return array{ok:bool, message?:string, data?:string, media_type?:string, filename?:string, size?:int}
     */
    public static function archived_session_attachment(string $claudeSessionId, int $line, string $fileUuid): array
    {
        return self::read_attachment_for_claude_session($claudeSessionId, $line, $fileUuid);
    }

    /**
     * Shared by session_attachment() (resolves $claudeSessionId via a live
     * session's sidecar first) and archived_session_attachment() (already
     * has it).
     *
     * @return array{ok:bool, message?:string, data?:string, media_type?:string, filename?:string, size?:int}
     */
    private static function read_attachment_for_claude_session(string $claudeSessionId, int $line, string $fileUuid): array
    {
        $path = TranscriptService::find_transcript_path($claudeSessionId);

        if ($path === null) {
            return ['ok' => false, 'message' => 'Transcript file not found'];
        }

        return TranscriptService::read_attachment($path, $line, $fileUuid);
    }
}
