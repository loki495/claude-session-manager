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

