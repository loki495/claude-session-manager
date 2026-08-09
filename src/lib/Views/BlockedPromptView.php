<?php

declare(strict_types=1);

namespace App\Views;

/**
 * Everything about rendering a session's "waiting on input" state -
 * shared between index.php's dashboard rows and session.php's detail
 * view so the two never drift apart.
 */
class BlockedPromptView extends View
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

        return self::render('blocked-prompt/panel', [
            'blockedReason' => (string)$session['blocked_reason'],
            'resumeHint' => $session['resume_hint'] ?? null,
        ]);
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

        return self::render('blocked-prompt/collapsible-block', [
            'isExpandable' => $summary !== $trimmed,
            'borderClass' => $borderClass,
            'textClass' => $textClass,
            'prefix' => $prefix,
            'summary' => $summary,
            'rawText' => $rawText,
        ]);
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

        $hasFreeText = false;

        foreach ($session['prompt_options'] as $opt) {
            if (stripos((string)$opt['label'], 'type something') !== false) {
                $hasFreeText = true;
                break;
            }
        }

        return self::render('blocked-prompt/options', [
            'sessionName' => (string)$session['name'],
            'csrfToken' => $csrfToken,
            // An AskUserQuestion call with more than one question renders as a
            // tab bar Claude Code itself navigates with the Left/Right arrow
            // keys (see multi_question in parse_blocking_prompt()) -
            // prompt_options only ever reflects whichever tab currently
            // happens to be showing, so without these there'd be no way to
            // reach the other questions in the set from this app at all,
            // short of attaching to tmux directly and pressing the arrow
            // keys by hand.
            'isMultiQuestion' => !empty($session['prompt_multi_question']),
            'options' => $session['prompt_options'],
            'hasFreeText' => $hasFreeText,
        ]);
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

        $lastMessageHtml = $includeLastMessage
            ? self::last_message_preview_html($session['last_message'] ?? null, 'text-amber-300/80 italic mb-1')
            : '';

        // The pending command/description this prompt is asking about gets
        // its own entry, immediately before the card below rather than
        // nested inside it - Andres's own explicit call, 2026-08-08,
        // prioritizing readability (matches how a real tool_use entry reads
        // elsewhere in the transcript) over the tradeoff that it can now
        // read as "something that already happened" the way a real past
        // entry does, even though it's still only pending.
        $pendingContextEntryHtml = self::pending_context_entry_html((string)($session['prompt_context'] ?? ''));

        $optionsHtml = !empty($session['prompt_options'])
            ? self::blocked_prompt_options_html($session, $csrfToken)
            : '';

        return $pendingContextEntryHtml . self::render('blocked-prompt/rich', [
            'blockedReason' => (string)$session['blocked_reason'],
            'lastMessageHtml' => $lastMessageHtml,
            'optionsHtml' => $optionsHtml,
        ]);
    }

    /**
     * The standalone entry for pending_context_entry_html()'s caller - see
     * blocked_prompt_rich_html()'s own doc comment above for why this is
     * separate from the amber "waiting on input" card rather than nested
     * inside it. Styled like a real tool_use entry (TranscriptView::
     * render_transcript_entry()) - same border-radius/padding/max-width
     * shape, amber instead of sky since this is still only pending, no
     * timestamp since it isn't a real transcript line. Empty string when
     * there's no context to show.
     */
    public static function pending_context_entry_html(string $promptContext): string
    {
        if ($promptContext === '') {
            return '';
        }

        return self::render('blocked-prompt/pending-context-entry', [
            'contentHtml' => self::render_collapsible_block($promptContext, 'border-amber-700/40', 'text-amber-100', ''),
        ]);
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
        $roleLabel = ucfirst((string)$role);
        $text = (string)($entry['blocks'][0]['text'] ?? '');
        $preview = mb_strlen($text) > 140 ? mb_substr($text, 0, 140) . '…' : $text;

        if ($preview === '') {
            return '';
        }

        $class = trim('text-xs text-slate-400 truncate ' . $extraClass);

        return self::render('blocked-prompt/last-message-preview', [
            'class' => $class,
            'roleLabel' => $roleLabel,
            'preview' => $preview,
        ]);
    }
}
