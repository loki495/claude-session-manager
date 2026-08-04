<?php

declare(strict_types=1);

namespace App\Views;

require_once __DIR__ . '/BlockedPromptView.php';

/**
 * The dashboard's session/bare-process list rows - shared between
 * index.php's SSR render and sessions_fragment.php's poll response so
 * both always render from the exact same markup.
 */
class SessionRowView
{
    /**
     * A compact "Thinking…" badge for a dashboard row - the dashboard's own
     * version of session.php's render_thinking_indicator_html() (same
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

        return '<div class="mt-1 flex items-center gap-1.5 text-xs text-slate-400">'
            . '<span class="flex items-center gap-1">'
            . '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:0ms"></span>'
            . '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:150ms"></span>'
            . '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:300ms"></span>'
            . '</span>'
            . '<span>Thinking&hellip;</span>'
            . '</div>';
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
        $name = htmlspecialchars((string)$s['name'], ENT_QUOTES);
        $title = htmlspecialchars((string)($s['title'] ?? $s['name']), ENT_QUOTES);

        $html = '<li class="rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-3 flex items-start justify-between gap-3">'
            . '<div class="min-w-0 flex-1">'
            . '<div class="text-sm truncate">'
            . '<a href="/session.php?session=' . urlencode((string)$s['name']) . '" class="hover:underline">' . $title . '</a>'
            . '</div>';

        if (($s['title'] ?? null) !== null) {
            $html .= '<div class="font-mono text-xs text-slate-500 truncate mt-0.5">' . $name . '</div>';
        }

        if (!empty($s['workdir'])) {
            $html .= '<div class="text-xs text-slate-500 truncate mt-0.5">' . htmlspecialchars((string)$s['workdir'], ENT_QUOTES) . '</div>';
        }

        $html .= '<div class="text-xs text-slate-400 mt-1 flex items-center gap-2">'
            . '<span>' . htmlspecialchars(self::relative_time((int)$s['activity']), ENT_QUOTES) . '</span>'
            . '<span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span>'
            . (!empty($s['attached'])
                ? '<span class="text-emerald-400">attached</span>'
                : '<span class="text-slate-500">detached</span>')
            . '</div>';

        $html .= '<div class="mt-1">'
            . '<button type="button" class="show-recent-btn rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-3 py-1.5"'
            . ' data-session="' . $name . '" data-loaded="0">'
            . 'Show last 3 messages'
            . '</button>'
            . '<div class="recent-messages hidden mt-1 flex flex-col gap-1 max-h-64 overflow-y-auto"></div>'
            . '</div>';

        if (!empty($s['blocked_reason']) && !empty($s['prompt_is_folder_trust'])) {
            $html .= BlockedPromptView::blocked_prompt_panel_html($s);
        } elseif (!empty($s['blocked_reason'])) {
            $html .= BlockedPromptView::blocked_prompt_rich_html($s, $csrfToken, true);
        } else {
            $html .= self::dashboard_thinking_indicator_html($s);
            $html .= BlockedPromptView::last_message_preview_html($s['last_message'] ?? null, 'mt-1');
        }

        $html .= '</div>';

        $html .= '<form method="post" action="/" onsubmit="return confirm(\'Kill session ' . $name . '?\');">'
            . '<input type="hidden" name="action" value="kill">'
            . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES) . '">'
            . '<input type="hidden" name="session" value="' . $name . '">'
            . '<button type="submit" class="min-h-[2.75rem] shrink-0 rounded-lg bg-red-900/70 active:bg-red-800 text-red-100 font-medium text-sm px-4 py-2">'
            . 'Kill'
            . '</button>'
            . '</form>';

        $html .= '</li>';

        return $html;
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
            return '<div class="rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-10 text-center text-slate-400">'
                . '<p class="text-base">No active Claude sessions.</p>'
                . '<p class="text-sm mt-1">Tap "New Session" to start one.</p>'
                . '</div>';
        }

        $rows = '';

        foreach ($sessions as $s) {
            $rows .= self::session_row_html($s, $csrfToken);
        }

        return '<ul class="flex flex-col gap-3">' . $rows . '</ul>';
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
        $pid = (int)$b['pid'];
        $tmuxSession = !empty($b['tmux_session']) ? (string)$b['tmux_session'] : null;

        $html = '<li class="rounded-xl border border-slate-800/60 bg-slate-900/30 px-4 py-3 flex items-center justify-between gap-3">'
            . '<div class="min-w-0">';

        if (!empty($b['title'])) {
            $html .= '<div class="text-sm truncate text-slate-300">' . htmlspecialchars((string)$b['title'], ENT_QUOTES) . '</div>';
        }

        $html .= '<div class="font-mono text-xs text-slate-500 truncate mt-0.5">'
            . 'pid ' . $pid
            . ($tmuxSession !== null ? ' · tmux: ' . htmlspecialchars($tmuxSession, ENT_QUOTES) : ' · no tmux (plain process)')
            . '</div>';

        if (!empty($b['cwd'])) {
            $html .= '<div class="text-xs text-slate-500 truncate mt-0.5">' . htmlspecialchars((string)$b['cwd'], ENT_QUOTES) . '</div>';
        }

        $html .= '<div class="text-xs text-slate-500 mt-1">'
            . (($b['started_at'] ?? null) !== null ? htmlspecialchars(self::relative_time((int)$b['started_at']), ENT_QUOTES) : 'start time unknown')
            . '</div>';

        $html .= '</div>';

        $confirmMsg = 'Kill pid ' . $pid . ($tmuxSession !== null ? ' (tmux session ' . htmlspecialchars($tmuxSession, ENT_QUOTES) . ')' : '') . '? This process was not started by this tool.';

        $html .= '<form method="post" action="/" onsubmit="return confirm(\'' . $confirmMsg . '\');">'
            . '<input type="hidden" name="action" value="kill_bare">'
            . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES) . '">'
            . '<input type="hidden" name="pid" value="' . $pid . '">'
            . '<button type="submit" class="min-h-[2.75rem] shrink-0 rounded-lg bg-red-900/70 active:bg-red-800 text-red-100 font-medium text-sm px-4 py-2">'
            . 'Kill'
            . '</button>'
            . '</form>';

        $html .= '</li>';

        return $html;
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

        return '<div class="mt-8">'
            . '<h2 class="text-sm font-medium text-slate-400 mb-1">Other claude processes on host</h2>'
            . '<p class="text-xs text-slate-500 mb-2">Not managed by this tool.</p>'
            . '<ul class="flex flex-col gap-2">' . $rows . '</ul>'
            . '</div>';
    }

    /**
     * The dashboard header's "N active cc-* sessions" line - shared so a poll
     * (sessions_fragment.php) can keep the count in sync with the list below
     * it without duplicating the pluralization rule in JS.
     */
    public static function session_count_label_html(int $count): string
    {
        return $count . ' active <code>cc-*</code> session' . ($count === 1 ? '' : 's');
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
