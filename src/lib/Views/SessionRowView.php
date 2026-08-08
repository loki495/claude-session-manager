<?php

declare(strict_types=1);

namespace App\Views;

/**
 * The dashboard's session/bare-process list rows - shared between
 * index.php's SSR render and sessions_fragment.php's poll response so
 * both always render from the exact same markup.
 */
class SessionRowView extends View
{
    /**
     * A compact "Thinking…" badge for a dashboard row - the dashboard's own
     * version of TranscriptView::render_thinking_indicator_html() (same
     * $s['working'] source field, see pane_title_is_working() in
     * Sessions.php), minus the Stop button: this row has no dedicated place
     * to put a per-session action button, and the session's own detail page
     * is one tap away via the row's own link for anyone who wants to actually
     * intervene. Mutually exclusive with the blocked-prompt treatment - a
     * session actively working isn't also sitting on an unanswered prompt.
     */
    public static function dashboard_thinking_indicator_html(array $s): string
    {
        if (empty($s['working']) || !empty($s['blocked_reason'])) {
            return '';
        }

        return self::render('session-row/thinking-indicator');
    }

    /**
     * One session's dashboard row - extracted verbatim from index.php so both
     * the initial SSR page and sessions_fragment.php's poll response render
     * from the exact same markup, never two copies to keep in sync.
     *
     * @param array<string, mixed> $s
     */
    public static function session_row_html(array $s, string $csrfToken): string
    {
        if (!empty($s['blocked_reason']) && !empty($s['prompt_is_folder_trust'])) {
            $blockedHtml = BlockedPromptView::blocked_prompt_panel_html($s);
        } elseif (!empty($s['blocked_reason'])) {
            $blockedHtml = BlockedPromptView::blocked_prompt_rich_html($s, $csrfToken, true);
        } else {
            $blockedHtml = self::dashboard_thinking_indicator_html($s)
                . BlockedPromptView::last_message_preview_html($s['last_message'] ?? null, 'mt-1');
        }

        return self::render('session-row/row', [
            'name' => (string)$s['name'],
            'title' => (string)($s['title'] ?? $s['name']),
            'hasExplicitTitle' => ($s['title'] ?? null) !== null,
            'workdir' => $s['workdir'] ?? null,
            'relativeTime' => self::relative_time((int)$s['activity']),
            'attached' => !empty($s['attached']),
            'blockedHtml' => $blockedHtml,
            'csrfToken' => $csrfToken,
        ]);
    }

    /**
     * The dashboard's whole session list, including the "nothing running yet"
     * empty state - see session_row_html() for why this is shared between
     * index.php's SSR render and sessions_fragment.php's poll response.
     *
     * @param array<int, array<string, mixed>> $sessions
     */
    public static function sessions_list_html(array $sessions, string $csrfToken): string
    {
        if ($sessions === []) {
            return self::render('session-row/empty-state');
        }

        $rows = '';

        foreach ($sessions as $s) {
            $rows .= self::session_row_html($s, $csrfToken);
        }

        return self::render('session-row/list', [
            'rowsHtml' => $rows,
        ]);
    }

    /**
     * One "other claude process on host" (not managed by this tool) dashboard
     * row - see session_row_html() for why this is shared between SSR and the
     * poll fragment.
     *
     * @param array<string, mixed> $b
     */
    public static function bare_process_row_html(array $b, string $csrfToken): string
    {
        $tmuxSession = !empty($b['tmux_session']) ? (string)$b['tmux_session'] : null;

        return self::render('session-row/bare-process-row', [
            'pid' => (int)$b['pid'],
            'title' => $b['title'] ?? null,
            'tmuxSession' => $tmuxSession,
            'cwd' => $b['cwd'] ?? null,
            'startedAt' => ($b['started_at'] ?? null) !== null ? self::relative_time((int)$b['started_at']) : null,
            'csrfToken' => $csrfToken,
        ]);
    }

    /**
     * The dashboard's "Other claude processes on host" section - empty string
     * (nothing rendered at all) when there are none, matching index.php's own
     * $agentReachable && !empty($bare) gate.
     *
     * @param array<int, array<string, mixed>> $bare
     */
    public static function bare_processes_html(array $bare, string $csrfToken): string
    {
        if ($bare === []) {
            return '';
        }

        $rows = '';

        foreach ($bare as $b) {
            $rows .= self::bare_process_row_html($b, $csrfToken);
        }

        return self::render('session-row/bare-processes', [
            'rowsHtml' => $rows,
        ]);
    }

    /**
     * The dashboard header's "N active tracked sessions" line - shared so a
     * poll (sessions_fragment.php) can keep the count in sync with the list
     * below it without duplicating the pluralization rule in JS.
     */
    public static function session_count_label_html(int $count): string
    {
        return self::render('session-row/count-label', [
            'count' => $count,
        ]);
    }

    public static function relative_time(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return 'just now';
        }

        if ($diff < 3600) {
            $m = intdiv($diff, 60);
            return "{$m} min ago";
        }

        if ($diff < 86400) {
            $h = intdiv($diff, 3600);
            return "{$h} hr" . ($h > 1 ? 's' : '') . ' ago';
        }

        $d = intdiv($diff, 86400);
        return "{$d} day" . ($d > 1 ? 's' : '') . ' ago';
    }
}
