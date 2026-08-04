<?php
declare(strict_types=1);

/**
 * The container never touches tmux or the host process table directly.
 * It only knows how to speak a one-request-one-response JSON protocol
 * over a single UNIX socket, bind-mounted in from the host, where a
 * host-native systemd-activated agent (see host-agent/) does the actual
 * work. See README.md for why: tmux auto-starts a server as a child of
 * whichever process first talks to an unstarted socket, so that process
 * must always be a genuine host process, never this container.
 */

function agent_socket_path(): string
{
    $path = getenv('CSM_AGENT_SOCKET');
    return $path !== false && $path !== '' ? $path : '/run/csm-agent.sock';
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function agent_call(array $request): array
{
    $socket = @stream_socket_client('unix://' . agent_socket_path(), $errno, $errstr, 5);

    if ($socket === false) {
        return [
            'ok' => false,
            'message' => "Cannot reach host agent ({$errstr}). Is the csm-agent.socket systemd unit running on the host?",
        ];
    }

    fwrite($socket, json_encode($request));
    stream_socket_shutdown($socket, STREAM_SHUT_WR);

    $raw = stream_get_contents($socket);
    fclose($socket);

    $decoded = json_decode((string)$raw, true);

    if (!is_array($decoded)) {
        return ['ok' => false, 'message' => 'Malformed response from host agent'];
    }

    return $decoded;
}

/**
 * The "waiting on input" amber panel shown for a blocked session,
 * including the manual tmux-attach fallback - shared between
 * index.php's list rows and session.php's detail view. Only used where
 * no Approve/Deny buttons are shown alongside it (the dashboard's
 * folder-trust-dialog rows - see prompt_is_folder_trust in Sessions.php),
 * since the attach tip is the only way to act on those from here.
 * Everywhere buttons ARE shown, blocked_prompt_rich_html() builds its own
 * equivalent panel inline instead - the tip is redundant once there's a
 * button to tap.
 *
 * @param array{blocked_reason?:?string, resume_hint?:?string} $session
 */
function blocked_prompt_panel_html(array $session): string
{
    if (empty($session['blocked_reason'])) {
        return '';
    }

    $html = '<div class="mt-2 rounded-lg px-3 py-2 text-xs bg-amber-900/40 text-amber-200 border border-amber-700/60">';
    $html .= '<p class="font-medium break-words">Waiting on input: ' . htmlspecialchars((string)$session['blocked_reason'], ENT_QUOTES) . '</p>';

    if (!empty($session['resume_hint'])) {
        $html .= '<p class="mt-1 text-amber-300/90">Attach to answer it:</p>';
        $html .= '<code class="block mt-0.5 font-mono text-[11px] text-amber-100 break-all select-all">' . htmlspecialchars((string)$session['resume_hint'], ENT_QUOTES) . '</code>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * The first line of $text, capped at 80 chars, plus a trailing " …" if
 * anything was cut off - used as a collapsed <summary> for a tool
 * command/output (or a pending, not-yet-approved one) so a one-line
 * "Bash(...)" call doesn't need expanding, while a long or multi-line one
 * shows just enough to recognize it.
 */
function collapsible_summary(string $text): string
{
    $trimmed = trim($text);
    $firstLine = explode("\n", $trimmed, 2)[0];
    $summary = mb_strlen($firstLine) > 80 ? mb_substr($firstLine, 0, 80) . '…' : $firstLine;

    return $summary . (mb_strlen($trimmed) > mb_strlen($summary) ? ' …' : '');
}

/**
 * A tool command/output (or pending one) defaults to collapsed (a
 * <details> element, no JS required to expand) - shared between
 * session.php's history blocks and the pending-action preview so neither
 * clutters the page with an always-expanded command by default.
 *
 * Trivial content (short enough, single line - the collapsed summary
 * would show it in full anyway) skips the <details> wrapper entirely and
 * renders as plain text instead: an expand affordance for something
 * that's already fully visible is just extra chrome, not a real collapse.
 *
 * The expanded/full text never wraps - a long command or line of output
 * that gets broken across several lines reads as if it's been cut off,
 * even though nothing is actually missing. It scrolls both axes instead
 * (horizontally for a long line, vertically past a capped height for a
 * lot of output), so what's shown is always exactly what's really there.
 */
function render_collapsible_block(string $rawText, string $borderClass, string $textClass, string $prefix): string
{
    $trimmed = trim($rawText);
    $summary = collapsible_summary($rawText);

    if ($summary === $trimmed) {
        $full = htmlspecialchars($rawText, ENT_QUOTES);

        return '<div class="rounded border ' . $borderClass . ' bg-slate-950/60 overflow-x-auto px-2 py-1.5 text-xs ' . $textClass . '"><span class="whitespace-pre">' . $prefix . $full . '</span></div>';
    }

    $summaryHtml = htmlspecialchars($summary, ENT_QUOTES);
    $full = htmlspecialchars($rawText, ENT_QUOTES);

    return '<details class="rounded border ' . $borderClass . ' bg-slate-950/60">'
        . '<summary class="block w-full text-left cursor-pointer select-none whitespace-pre-wrap break-all px-2 py-1.5 text-xs ' . $textClass . '">' . $prefix . $summaryHtml . '</summary>'
        . '<pre class="whitespace-pre overflow-auto max-h-64 px-2 pb-1.5 text-xs ' . $textClass . '">' . $full . '</pre>'
        . '</details>';
}

/**
 * Real Approve/Deny-style buttons for a blocked session's numbered
 * options - shared between session.php (its blocked-prompt section) and
 * index.php's dashboard rows (blocked_prompt_rich_html(), below) so the
 * two never drift apart. Empty string if there's nothing to answer.
 *
 * A "Type something." option (Claude Code always offers one on an
 * AskUserQuestion prompt) gets a reveal button instead of an
 * immediate-submit form - verified live that selecting it needs custom
 * typed text sent alongside it (see answer_prompt_with_text() in
 * Sessions.php), not just the bare numbered choice. The paired reply
 * box (hidden until revealed) is shared by whichever free-text option
 * is open, wired up by the delegated JS listener in index.php/session.php.
 *
 * @param array{name:string, prompt_options?:array<int, array{number:int, label:string}>, prompt_multi_question?:bool} $session
 */
function blocked_prompt_options_html(array $session, string $csrfToken): string
{
    if (empty($session['prompt_options'])) {
        return '';
    }

    $sessionName = (string)$session['name'];
    $optionsHtml = '';
    $hasFreeText = false;

    // An AskUserQuestion call with more than one question renders as a tab
    // bar Claude Code itself navigates with the Left/Right arrow keys (see
    // multi_question in parse_blocking_prompt()) - prompt_options only ever
    // reflects whichever tab currently happens to be showing, so without
    // these there'd be no way to reach the other questions in the set from
    // this app at all, short of attaching to tmux directly and pressing the
    // arrow keys by hand.
    if (!empty($session['prompt_multi_question'])) {
        $optionsHtml .= '<button type="button" class="nav-prompt-btn rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2" data-direction="left" aria-label="Previous question">&larr;</button>';
    }

    foreach ($session['prompt_options'] as $opt) {
        $label = htmlspecialchars((string)$opt['label'], ENT_QUOTES);
        $number = (int)$opt['number'];

        if (stripos((string)$opt['label'], 'type something') !== false) {
            $hasFreeText = true;
            // break-words + max-w-full: an AskUserQuestion option label has
            // no length limit imposed by the tool itself - a long unbroken
            // one (a pasted identifier/URL, say) would otherwise widen this
            // button (and the whole page with it) instead of wrapping.
            // Verified live that break-words ALONE isn't enough: a flex
            // item's width is still its own shrink-to-fit content size
            // unless something caps it, so overflow-wrap never gets a
            // narrower box to actually wrap within - max-w-full is what
            // forces that cap, matching the button's flex-wrap row.
            $optionsHtml .= '<button type="button" class="reveal-freetext-btn rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2 break-words max-w-full" data-option="' . $number . '">'
                . $number . '. ' . $label
                . '</button>';
            continue;
        }

        $optionsHtml .= '<form method="post" action="/answer_prompt.php" data-confirm-label="' . $label . '">'
            . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES) . '">'
            . '<input type="hidden" name="session" value="' . htmlspecialchars($sessionName, ENT_QUOTES) . '">'
            . '<input type="hidden" name="option" value="' . $number . '">'
            . '<button type="submit" class="rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2 break-words max-w-full">'
            . $number . '. ' . $label
            . '</button>'
            . '</form>';
    }

    if (!empty($session['prompt_multi_question'])) {
        $optionsHtml .= '<button type="button" class="nav-prompt-btn rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2" data-direction="right" aria-label="Next question">&rarr;</button>';
    }

    $html = '<div class="prompt-options-wrapper mt-2" data-session="' . htmlspecialchars($sessionName, ENT_QUOTES) . '" data-csrf-token="' . htmlspecialchars($csrfToken, ENT_QUOTES) . '">'
        . '<div class="flex flex-wrap gap-2">' . $optionsHtml . '</div>';

    if ($hasFreeText) {
        $html .= '<div class="freetext-reply hidden mt-2">'
            . '<textarea class="freetext-reply-textarea w-full resize-none rounded-lg bg-slate-800 border border-slate-700 text-base text-slate-100 px-3 py-2" rows="2" placeholder="Type your reply&hellip;"></textarea>'
            . '<button type="button" class="freetext-reply-send-btn mt-1 rounded-lg bg-indigo-600 active:bg-indigo-700 text-white text-xs font-medium px-3 py-1.5">Send</button>'
            . '</div>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * The full "waiting on input" treatment for a dashboard row - the compact
 * panel, the actual pending context (the command/description/etc being
 * asked about, not just the bare question - session.php shows this same
 * context as its own bubble instead, so it isn't folded into
 * blocked_prompt_options_html() itself), and real Approve/Deny buttons.
 * Used for anything except the initial folder-trust dialog, which stays
 * on the plain tip only: declining it exits the whole session, a
 * decision that deserves attaching and looking, not a quick tap from a
 * list of rows - see prompt_is_folder_trust in Sessions.php.
 *
 * @param array{name:string, blocked_reason?:?string, resume_hint?:?string, prompt_context?:?string, prompt_options?:array<int, array{number:int, label:string}>} $session
 */
/**
 * $includeLastMessage: the message that led up to the prompt (e.g. the
 * assistant explaining why it's about to run a command) is worth showing
 * on the dashboard, where there's no scrollback to see it in - pass true
 * there. session.php leaves it false (the default): that same message is
 * already the newest bubble in its own history list, right above this
 * card, so repeating it here would just be duplication.
 */
function blocked_prompt_rich_html(array $session, string $csrfToken, bool $includeLastMessage = false): string
{
    if (empty($session['blocked_reason'])) {
        return '';
    }

    // One unified card, not a separate bubble stacked above it - a
    // pending command styled like a normal history entry read as
    // something that already happened, not the thing actually still
    // waiting on an answer.
    $html = '<div class="mt-2 rounded-lg px-3 py-2 text-xs bg-amber-900/40 text-amber-200 border border-amber-700/60">';

    if ($includeLastMessage) {
        $html .= last_message_preview_html($session['last_message'] ?? null, 'text-amber-300/80 italic mb-1');
    }

    $html .= '<p class="font-medium break-words">Waiting on input: ' . htmlspecialchars((string)$session['blocked_reason'], ENT_QUOTES) . '</p>';

    if (!empty($session['prompt_context'])) {
        $html .= '<div class="mt-2">' . render_collapsible_block((string)$session['prompt_context'], 'border-amber-700/40', 'text-amber-100', '') . '</div>';
    }

    if (!empty($session['prompt_options'])) {
        $html .= blocked_prompt_options_html($session, $csrfToken);
    }

    $html .= '</div>';

    return $html;
}

/**
 * A compact one-line "Role: text preview" for a single transcript entry
 * (as returned by session_last_message() in Sessions.php) - deliberately
 * terse compared to session.php's full block-kind rendering, since this
 * sits in a space-constrained dashboard row rather than a dedicated
 * detail page. Empty string if there's nothing to show.
 *
 * @param array{role?:?string, blocks?:array<int, array{kind:string, text:string}>}|null $entry
 */
function last_message_preview_html(?array $entry, string $extraClass = ''): string
{
    if ($entry === null || empty($entry['blocks'])) {
        return '';
    }

    $role = $entry['role'] ?? 'system';
    $roleLabel = htmlspecialchars(ucfirst((string)$role), ENT_QUOTES);
    $text = (string)($entry['blocks'][0]['text'] ?? '');
    $preview = mb_strlen($text) > 140 ? mb_substr($text, 0, 140) . '…' : $text;

    if ($preview === '') {
        return '';
    }

    $class = trim('text-xs text-slate-400 truncate ' . $extraClass);

    return '<p class="' . htmlspecialchars($class, ENT_QUOTES) . '"><span class="font-medium">' . $roleLabel . ':</span> ' . htmlspecialchars($preview, ENT_QUOTES) . '</p>';
}

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
function dashboard_thinking_indicator_html(array $s): string
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
function session_row_html(array $s, string $csrfToken): string
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
        . '<span>' . htmlspecialchars(relative_time((int)$s['activity']), ENT_QUOTES) . '</span>'
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
        $html .= blocked_prompt_panel_html($s);
    } elseif (!empty($s['blocked_reason'])) {
        $html .= blocked_prompt_rich_html($s, $csrfToken, true);
    } else {
        $html .= dashboard_thinking_indicator_html($s);
        $html .= last_message_preview_html($s['last_message'] ?? null, 'mt-1');
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
function sessions_list_html(array $sessions, string $csrfToken): string
{
    if ($sessions === []) {
        return '<div class="rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-10 text-center text-slate-400">'
            . '<p class="text-base">No active Claude sessions.</p>'
            . '<p class="text-sm mt-1">Tap "New Session" to start one.</p>'
            . '</div>';
    }

    $rows = '';

    foreach ($sessions as $s) {
        $rows .= session_row_html($s, $csrfToken);
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
function bare_process_row_html(array $b, string $csrfToken): string
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
        . (($b['started_at'] ?? null) !== null ? htmlspecialchars(relative_time((int)$b['started_at']), ENT_QUOTES) : 'start time unknown')
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
function bare_processes_html(array $bare, string $csrfToken): string
{
    if ($bare === []) {
        return '';
    }

    $rows = '';

    foreach ($bare as $b) {
        $rows .= bare_process_row_html($b, $csrfToken);
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
function session_count_label_html(int $count): string
{
    return $count . ' active <code>cc-*</code> session' . ($count === 1 ? '' : 's');
}

/**
 * The push-check interval control - a small preset dropdown + Save button
 * so Andres can adjust how often csm-push-check.timer polls without
 * editing/reinstalling the unit file by hand on the host (see
 * set_push_timer_interval() in host-agent/lib/Push.php for the actual
 * mechanics). $currentSeconds is always included as an option even when
 * it isn't one of the presets (a value set by hand outside this control,
 * or a future default change), so the dropdown never silently
 * misrepresents what's actually running.
 *
 * Renders nothing when $currentSeconds is null - the timer unit isn't
 * installed, or the agent is unreachable, and there's nothing to adjust
 * in either case.
 */
function push_timer_interval_control_html(?int $currentSeconds, string $csrfToken): string
{
    if ($currentSeconds === null) {
        return '';
    }

    $presets = [5, 10, 15, 30, 60, 120];

    if (!in_array($currentSeconds, $presets, true)) {
        $presets[] = $currentSeconds;
        sort($presets);
    }

    $options = '';

    foreach ($presets as $seconds) {
        $selected = $seconds === $currentSeconds ? ' selected' : '';
        $options .= "<option value=\"{$seconds}\"{$selected}>{$seconds}s</option>";
    }

    $csrf = htmlspecialchars($csrfToken, ENT_QUOTES);

    return <<<HTML
    <form method="post" action="/" class="flex items-center gap-2 pt-2 mt-2 border-t border-slate-800">
      <input type="hidden" name="action" value="set_push_timer_interval">
      <input type="hidden" name="csrf_token" value="{$csrf}">
      <label for="push-timer-interval-select" class="text-slate-400">Push check interval</label>
      <select id="push-timer-interval-select" name="seconds" class="rounded border border-slate-700 bg-slate-800 text-slate-300 text-xs px-1.5 py-1 ml-auto">
        {$options}
      </select>
      <button type="submit" class="rounded border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-2 py-1">Save</button>
    </form>
    HTML;
}

/**
 * Dashboard setup-status box - one place to see whether everything this
 * app needs (hooks, claude-quota, tmux socket dir, VAPID keys, Composer
 * vendor/) is actually installed, instead of discovering each piece is
 * missing separately (see health_check() in host-agent/lib/Push.php for
 * what's actually checked). Collapsed <details> rather than an always-open
 * block since most of the time there's nothing to look at - the summary
 * row alone (colored dot + "All systems OK"/"Some setup checks failed")
 * is enough for the common case.
 *
 * Renders nothing when $checks is empty - either the host agent is
 * unreachable (already covered by index.php's own red banner for that) or
 * the health_check call itself failed, neither of which this box can add
 * anything useful to.
 *
 * $pushTimerIntervalSeconds/$csrfToken: folds the push-check interval
 * control (see push_timer_interval_control_html()) into this same panel,
 * right below the "Push delivery" check it's directly related to, rather
 * than a separate floating control elsewhere on the page.
 *
 * @param array<int, array{key?:string, label?:string, ok?:bool, detail?:?string}> $checks
 */
function health_box_html(array $checks, ?int $pushTimerIntervalSeconds = null, string $csrfToken = ''): string
{
    if ($checks === []) {
        return '';
    }

    $allOk = true;

    foreach ($checks as $check) {
        if (!($check['ok'] ?? false)) {
            $allOk = false;
            break;
        }
    }

    $dotColor = $allOk ? 'bg-emerald-400' : 'bg-amber-400';
    $summaryColor = $allOk ? 'text-emerald-400' : 'text-amber-400';
    $summaryText = $allOk ? 'All systems OK' : 'Some setup checks failed';

    $rows = '';

    foreach ($checks as $check) {
        $ok = (bool)($check['ok'] ?? false);
        $label = htmlspecialchars((string)($check['label'] ?? ''), ENT_QUOTES);
        $detail = $check['detail'] ?? null;
        $icon = $ok
            ? '<span class="text-emerald-400">&#10003;</span>'
            : '<span class="text-amber-400">&#10007;</span>';
        $detailHtml = ($detail !== null && $detail !== '')
            ? '<div class="text-[11px] text-slate-500 font-mono break-all mt-0.5">' . htmlspecialchars((string)$detail, ENT_QUOTES) . '</div>'
            : '';

        $rows .= '<div class="flex items-start gap-2 py-1.5 border-t border-slate-800 first:border-t-0">'
            . '<span class="mt-0.5">' . $icon . '</span>'
            . '<div class="min-w-0 flex-1"><div class="text-slate-300">' . $label . '</div>' . $detailHtml . '</div>'
            . '</div>';
    }

    $intervalControl = push_timer_interval_control_html($pushTimerIntervalSeconds, $csrfToken);

    return <<<HTML
    <details class="mb-4 rounded-lg border border-slate-800 bg-slate-900/50 text-sm">
      <summary class="px-4 py-3 cursor-pointer list-none flex items-center gap-2 [&::-webkit-details-marker]:hidden">
        <span class="w-2 h-2 rounded-full {$dotColor} shrink-0"></span>
        <span class="{$summaryColor} font-medium">{$summaryText}</span>
        <span class="text-slate-500 ml-auto text-xs">Setup health</span>
      </summary>
      <div class="px-4 pb-3">{$rows}{$intervalControl}</div>
    </details>
    HTML;
}

function relative_time(int $timestamp): string
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

/**
 * The sticky quota footer's markup + its own self-contained fetch-and-poll
 * script - shared between index.php (its own standalone sticky bar) and
 * session.php (folded into the same sticky bar as the message compose
 * box, rather than stacking two separate fixed bars on mobile). A caller
 * echoes this once; it renders itself and keeps itself updated. Sized
 * for mobile (small text, not the dashboard's original text-xl) and
 * user-collapsible (persisted in localStorage, shared across both pages
 * since it's the same feature either place).
 *
 * $extraHtml renders on the same row as the "Quota" collapse toggle
 * (outside #quota-info, which the fetch/poll script above fully
 * replaces on every refresh - anything placed inside it would get wiped
 * out) - session.php uses this slot for its mode-toggle button so the
 * two controls share one line instead of stacking.
 */
function quota_footer_html(string $extraHtml = ''): string
{
    $html = <<<'HTML'
    <div id="quota-footer">
      <div class="flex items-center justify-between gap-2 mb-1">
        <button type="button" id="quota-toggle-btn" class="flex items-center gap-1 text-xs text-slate-500 active:text-slate-300">
          <span id="quota-toggle-icon">&#9662;</span>
          <span>Quota</span>
        </button>
        {{EXTRA_HTML}}
      </div>
      <div id="quota-info" class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 min-w-0 text-sm font-medium" aria-live="polite">
        <span class="text-slate-500">Loading quota&hellip;</span>
      </div>
    </div>
    <script>
    (function () {
      var footer = document.getElementById('quota-footer');
      var toggleBtn = document.getElementById('quota-toggle-btn');
      var toggleIcon = document.getElementById('quota-toggle-icon');
      var el = document.getElementById('quota-info');
      var STORAGE_KEY = 'csm-quota-collapsed';

      function applyCollapsed(collapsed) {
        el.classList.toggle('hidden', collapsed);
        toggleIcon.innerHTML = collapsed ? '&#9656;' : '&#9662;';
      }

      // Collapsed by default - only expanded if the user has explicitly
      // expanded it before (stored '0'). Private browsing / storage
      // disabled just falls back to the same collapsed default, no
      // persistence either way.
      var storedCollapsed = true;
      try {
        storedCollapsed = window.localStorage.getItem(STORAGE_KEY) !== '0';
      } catch (e) {}
      applyCollapsed(storedCollapsed);

      toggleBtn.addEventListener('click', function () {
        var collapsed = !el.classList.contains('hidden');
        applyCollapsed(collapsed);
        try {
          window.localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
        } catch (e) {}
      });

      function pctColorClass(pct) {
        if (pct >= 90) return 'text-red-400';
        if (pct >= 70) return 'text-amber-400';
        return 'text-slate-300';
      }

      function label(key) {
        if (key === 'session') return 'Session';
        if (key === 'week_all') return 'Week';
        return key.replace(/^week_/, '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }) + ' (week)';
      }

      // No leading zeros by construction (Math.floor results are used bare).
      function formatDuration(seconds, kind) {
        if (seconds <= 0) return 'now';

        if (kind === 'session') {
          var h = Math.floor(seconds / 3600);
          var m = Math.floor((seconds % 3600) / 60);
          return h > 0 ? (h + 'h ' + m + 'm') : (m + 'm');
        }

        var d = Math.floor(seconds / 86400);
        var wh = Math.floor((seconds % 86400) / 3600);
        return d > 0 ? (d + 'd ' + wh + 'h') : (wh + 'h');
      }

      // Mirrors host-agent's relative_time() (see src/lib/AgentClient.php) so
      // "Captured ..." reads the same relative-time style as the rest of the
      // app, instead of a raw ISO timestamp.
      function relativeTimeAgo(isoTimestamp) {
        var ms = Date.parse(isoTimestamp);
        if (isNaN(ms)) return isoTimestamp;

        var diff = Math.floor(Date.now() / 1000) - Math.floor(ms / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + ' min ago';

        if (diff < 86400) {
          var h = Math.floor(diff / 3600);
          return h + ' hr' + (h > 1 ? 's' : '') + ' ago';
        }

        var d = Math.floor(diff / 86400);
        return d + ' day' + (d > 1 ? 's' : '') + ' ago';
      }

      function showUnavailable(data) {
        el.title = '';
        el.innerHTML = '';
        var line = document.createElement('span');
        line.className = 'text-slate-600';
        line.textContent = 'Quota unavailable' + (data && data.message ? ': ' + data.message : '');
        el.appendChild(line);
      }

      function render(data) {
        if (!data || !data.quota) {
          showUnavailable(data);
          return;
        }

        var q = data.quota;
        var order = ['session', 'week_all'].concat(Object.keys(q).filter(function (k) {
          return k.indexOf('week_') === 0 && k !== 'week_all';
        }).sort());

        var nowSeconds = Math.floor(Date.now() / 1000);
        var lines = [];

        order.forEach(function (key) {
          var bar = q[key];
          if (!bar || typeof bar.pct !== 'number') return;

          var text = label(key) + ' ' + bar.pct + '%';

          if (typeof bar.resets_at === 'number') {
            var kind = key === 'session' ? 'session' : 'week';
            text += ' · resets ' + formatDuration(bar.resets_at - nowSeconds, kind);
          }

          lines.push({ text: text, pct: bar.pct });
        });

        if (lines.length === 0) {
          showUnavailable(data);
          return;
        }

        var metaParts = [];
        if (data.cached) metaParts.push(data.stale ? 'cached, stale' : 'cached');
        if (data.refreshing) metaParts.push('refreshing in background…');

        el.title = q.captured_at ? 'Captured ' + relativeTimeAgo(q.captured_at) : '';
        el.innerHTML = '';

        // A left border marks every item after the first when there's room for
        // them to sit on one row (sm: and up). On mobile, where each bucket
        // stacks onto its own line, that border/padding is dropped so the text
        // lines up flush left instead of looking indented.
        lines.forEach(function (line, i) {
          var item = document.createElement('span');
          item.className = pctColorClass(line.pct) + (i > 0 ? ' sm:pl-2 sm:border-l sm:border-slate-700' : '');
          item.textContent = line.text;
          el.appendChild(item);
        });

        if (metaParts.length > 0) {
          var meta = document.createElement('span');
          meta.className = 'text-xs font-normal text-slate-400';
          meta.textContent = '(' + metaParts.join(' · ') + ')';
          el.appendChild(meta);
        }
      }

      var loading = false;

      function load() {
        if (loading) return; // a slow request is still out there - don't pile another on top of it
        loading = true;

        fetch('/quota.php', { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(render)
          .catch(function () {
            showUnavailable(null);
          })
          .finally(function () {
            loading = false;
          });
      }

      load();
      setInterval(load, 60000);
    })();
    </script>
    HTML;

    return str_replace('{{EXTRA_HTML}}', $extraHtml, $html);
}

/**
 * A "Notify me" control for Web Push (see the README's "Web Push
 * notifications" section for the server-side setup this depends on) -
 * renders nothing at all when $vapidPublicKey is empty (VAPID keys not
 * generated/configured on the host yet), since there'd be nothing useful
 * for the button to do.
 *
 * Registers the service worker and, if a subscription already exists,
 * silently re-POSTs it on every page load - iOS's own push subscriptions
 * are prone to dying silently with no error signal to the app, so
 * resubscribing on every open is what actually keeps a stale one from
 * just quietly stopping forever.
 */
function push_notify_button_html(string $vapidPublicKey, string $csrfToken): string
{
    if ($vapidPublicKey === '') {
        return '';
    }

    $html = <<<'HTML'
    <div id="push-notify-control" class="mt-1">
      <button type="button" id="push-notify-btn" class="rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-3 py-1.5 hidden">
        Enable notifications
      </button>
      <span id="push-notify-status" class="text-xs text-slate-500"></span>
    </div>
    <script>
    (function () {
      var VAPID_PUBLIC_KEY = {{VAPID_PUBLIC_KEY_JSON}};
      var CSRF_TOKEN = {{CSRF_TOKEN_JSON}};
      var btn = document.getElementById('push-notify-btn');
      var status = document.getElementById('push-notify-status');

      if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return; // not supported on this browser/OS - button stays hidden
      }

      // Badging API: opening either page counts as "seen" - clears
      // whatever badge sw.js's push handler set for a notification that
      // arrived while the app wasn't open. Feature-detected since support
      // (particularly on iOS home-screen PWAs, which is the only real
      // target for the push feature this rides along with) isn't
      // guaranteed - a harmless no-op everywhere else.
      if ('setAppBadge' in navigator) {
        navigator.clearAppBadge().catch(function () {});
      }

      // Web Push's applicationServerKey wants a raw Uint8Array, not the
      // base64url string VAPID::createVapidKeys() produces.
      function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var outputArray = new Uint8Array(rawData.length);

        for (var i = 0; i < rawData.length; ++i) {
          outputArray[i] = rawData.charCodeAt(i);
        }

        return outputArray;
      }

      function postSubscription(subscription) {
        return fetch('/push_subscribe.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            subscription: JSON.stringify(subscription)
          }).toString()
        });
      }

      navigator.serviceWorker.register('/sw.js').then(function (registration) {
        return registration.pushManager.getSubscription().then(function (existing) {
          if (existing) {
            postSubscription(existing);

            if (status) {
              status.textContent = 'Push notifications enabled';
            }

            return;
          }

          if (Notification.permission === 'denied') {
            if (status) {
              status.textContent = 'Notifications blocked in browser settings';
            }

            return;
          }

          if (!btn) {
            return;
          }

          btn.classList.remove('hidden');

          btn.addEventListener('click', function () {
            btn.disabled = true;

            Notification.requestPermission().then(function (permission) {
              if (permission !== 'granted') {
                if (status) {
                  status.textContent = 'Permission not granted';
                }

                btn.disabled = false;
                return;
              }

              registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
              })
                .then(postSubscription)
                .then(function () {
                  btn.classList.add('hidden');

                  if (status) {
                    status.textContent = 'Push notifications enabled';
                  }
                })
                .catch(function () {
                  if (status) {
                    status.textContent = 'Could not enable notifications';
                  }

                  btn.disabled = false;
                });
            });
          });
        });
      }).catch(function () {});
    })();
    </script>
    HTML;

    $html = str_replace('{{VAPID_PUBLIC_KEY_JSON}}', json_encode($vapidPublicKey), $html);

    return str_replace('{{CSRF_TOKEN_JSON}}', json_encode($csrfToken), $html);
}
