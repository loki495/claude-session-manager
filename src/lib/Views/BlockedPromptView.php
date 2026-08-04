<?php

declare(strict_types=1);

namespace App\Views;

/**
 * Everything about rendering a session's "waiting on input" state -
 * shared between index.php's dashboard rows and session.php's detail
 * view so the two never drift apart.
 */
class BlockedPromptView
{
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
    public static function blocked_prompt_panel_html(array $session): string
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
    public static function collapsible_summary(string $text): string
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
    public static function render_collapsible_block(string $rawText, string $borderClass, string $textClass, string $prefix): string
    {
        $trimmed = trim($rawText);
        $summary = self::collapsible_summary($rawText);

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
    public static function blocked_prompt_options_html(array $session, string $csrfToken): string
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
     * $includeLastMessage: the message that led up to the prompt (e.g. the
     * assistant explaining why it's about to run a command) is worth showing
     * on the dashboard, where there's no scrollback to see it in - pass true
     * there. session.php leaves it false (the default): that same message is
     * already the newest bubble in its own history list, right above this
     * card, so repeating it here would just be duplication.
     *
     * @param array{name:string, blocked_reason?:?string, resume_hint?:?string, prompt_context?:?string, prompt_options?:array<int, array{number:int, label:string}>} $session
     */
    public static function blocked_prompt_rich_html(array $session, string $csrfToken, bool $includeLastMessage = false): string
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
            $html .= self::last_message_preview_html($session['last_message'] ?? null, 'text-amber-300/80 italic mb-1');
        }

        $html .= '<p class="font-medium break-words">Waiting on input: ' . htmlspecialchars((string)$session['blocked_reason'], ENT_QUOTES) . '</p>';

        if (!empty($session['prompt_context'])) {
            $html .= '<div class="mt-2">' . self::render_collapsible_block((string)$session['prompt_context'], 'border-amber-700/40', 'text-amber-100', '') . '</div>';
        }

        if (!empty($session['prompt_options'])) {
            $html .= self::blocked_prompt_options_html($session, $csrfToken);
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
    public static function last_message_preview_html(?array $entry, string $extraClass = ''): string
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
}
