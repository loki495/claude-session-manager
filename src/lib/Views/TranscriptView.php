<?php

declare(strict_types=1);

namespace App\Views;

/**
 * session.php's transcript-rendering helpers - the history entries, the
 * session-info card, the thinking indicator, the mode select, and the
 * blocked-prompt section wrapper. Kept together (rather than split further)
 * since they all feed the same page and its matching JS-side mirrors (see
 * the comments on each method pointing at their session.js counterpart).
 */
class TranscriptView
{
    /**
     * Mirrors CLAUDE_CODE_MODE_STATUS_PHRASES's key order in Sessions.php
     * (host-agent, a separate process reached only via the socket - not
     * directly shareable) - keys must match set_mode()'s $targetMode exactly.
     */
    public const MODE_OPTIONS = ['manual' => 'Manual', 'accept edits' => 'Accept Edits', 'plan' => 'Plan', 'auto' => 'Auto'];

    /**
     * @param array{media_type:string, data:string} $image
     */
    // Starts as a small square thumbnail (cropped via object-cover, not
    // scaled - overflow-hidden isn't needed separately since object-cover
    // itself never overflows its box) - a full-size screenshot inline by
    // default would dominate the transcript. Tapping toggles to full size and
    // back (see the delegated click handler in session.js) by swapping these
    // classes for w-full h-auto object-contain, not a separate lightbox/modal.
    public static function render_transcript_image_html(array $image): string
    {
        $mediaType = htmlspecialchars($image['media_type'], ENT_QUOTES);
        $data = htmlspecialchars($image['data'], ENT_QUOTES);

        return '<img src="data:' . $mediaType . ';base64,' . $data . '" loading="lazy" alt="Image" class="transcript-image mt-1.5 rounded border border-slate-800 cursor-pointer w-24 h-24 object-cover">';
    }

    /**
     * @param array{kind:string, text:string, image?:array{media_type:string, data:string}} $block
     */
    public static function render_transcript_block(array $block): string
    {
        $text = htmlspecialchars($block['text'], ENT_QUOTES);
        $imageHtml = isset($block['image']) ? self::render_transcript_image_html($block['image']) : '';

        // break-words (not break-all, used elsewhere for compact collapsed
        // summary lines) - this is prose, so only a genuinely too-long token
        // (a long constant name, URL, hash, ...) should ever break mid-word;
        // normal short words shouldn't. Found live: a message containing
        // "FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE" (no spaces,
        // 51 chars) widened the whole page horizontally without this.
        return match ($block['kind']) {
            'text' => '<p class="whitespace-pre-wrap break-words text-sm text-slate-100">' . $text . '</p>',
            'tool_use' => '<div class="tool-use-block">' . BlockedPromptView::render_collapsible_block($block['text'], 'border-sky-800/40', 'text-sky-300', '&rarr; ') . '</div>',
            // The image (a browser-automation screenshot, most likely) is a
            // SIBLING of .tool-detail, not nested inside it - unlike the raw
            // text output, Andres wants a screenshot visible regardless of
            // the show/hide-tool-details toggle, since it's often the whole
            // point of having run the tool in the first place.
            'tool_result' => '<div class="tool-detail">' . BlockedPromptView::render_collapsible_block($block['text'], 'border-slate-800', 'text-slate-400', '') . '</div>' . $imageHtml,
            'image' => $imageHtml !== '' ? $imageHtml : ($text !== '' ? '<p class="break-words text-xs text-slate-600">' . $text . '</p>' : ''),
            default => $text !== '' ? '<p class="break-words text-xs text-slate-600">' . $text . '</p>' : '',
        };
    }

    /**
     * The session-info card's static content (title/name/workdir/activity) -
     * used for the initial render and mirrored in JS for the visibility-gated
     * poll that keeps it live without a page reload (see session.js).
     * The blocked-prompt panel is deliberately NOT part of this card - see
     * render_blocked_prompt_section_html(), placed after the history list so
     * the actionable part of the page reads bottom-up like a chat, not stuck
     * above a scrollable transcript.
     */
    public static function render_session_static_info_html(array $detail): string
    {
        $html = '<div class="text-base font-medium truncate">' . htmlspecialchars((string)($detail['title'] ?? $detail['name']), ENT_QUOTES) . '</div>';
        $html .= '<div class="font-mono text-xs text-slate-500 truncate mt-0.5">' . htmlspecialchars((string)$detail['name'], ENT_QUOTES) . '</div>';

        if (!empty($detail['workdir'])) {
            $html .= '<div class="text-xs text-slate-500 truncate mt-0.5">' . htmlspecialchars((string)$detail['workdir'], ENT_QUOTES) . '</div>';
        }

        $html .= '<div class="text-xs text-slate-400 mt-1 flex items-center gap-2">';
        $html .= '<span>' . htmlspecialchars(SessionRowView::relative_time((int)$detail['activity']), ENT_QUOTES) . '</span>';
        $html .= '<span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span>';
        $html .= $detail['attached'] ? '<span class="text-emerald-400">attached</span>' : '<span class="text-slate-500">detached</span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * The blocked-prompt card (question, the pending command/context in a
     * collapsed-by-default block, Approve/Deny buttons - all one unified
     * card, via the shared BlockedPromptView::blocked_prompt_rich_html()) - empty string when
     * the session isn't currently blocked. Placed after the history list, and
     * re-rendered in place by the same visibility-gated poll that appends new
     * messages, so it always sits right where the latest activity is, not
     * pinned above a long transcript.
     */
    public static function render_blocked_prompt_section_html(array $detail, string $csrfToken): string
    {
        return BlockedPromptView::blocked_prompt_rich_html($detail, $csrfToken);
    }

    /**
     * A single, transient "Claude is thinking…" indicator - never the actual
     * thinking content (Transcript.php drops that entirely), just a live
     * "something is happening right now" signal sourced from the pane title's
     * spinner glyph (see pane_title_is_working() in Sessions.php). Mutually
     * exclusive with the blocked-prompt section: a session that's actively
     * working isn't also sitting on an unanswered prompt.
     */
    public static function render_thinking_indicator_html(array $detail): string
    {
        if (empty($detail['working']) || !empty($detail['blocked_reason'])) {
            return '';
        }

        return '<div class="rounded-lg border border-slate-800 bg-slate-900/50 px-3 py-2 text-xs text-slate-400 flex items-center justify-between gap-2">'
            . '<span class="flex items-center gap-2">'
            . '<span class="flex items-center gap-1">'
            . '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:0ms"></span>'
            . '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:150ms"></span>'
            . '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:300ms"></span>'
            . '</span>'
            . '<span>Thinking&hellip;</span>'
            . '</span>'
            . '<button type="button" id="stop-btn" class="rounded border border-red-800/60 bg-red-950/40 active:bg-red-900/60 text-red-300 text-xs font-medium px-2 py-1">Stop</button>'
            . '</div>';
    }

    /**
     * A small select showing the session's current permission mode, next to
     * the compose box - choosing a different one jumps straight to it (via
     * set_mode() in Sessions.php, which works out the needed Shift+Tab steps
     * server-side). Disabled while the mode can't currently be read from the
     * pane (e.g. a blocking prompt is covering the status line) - set_mode()
     * needs a known starting point to compute the jump.
     */
    public static function render_mode_toggle_html(array $detail): string
    {
        $mode = is_string($detail['current_mode'] ?? null) ? $detail['current_mode'] : null;

        $options = '';
        foreach (self::MODE_OPTIONS as $key => $label) {
            $selected = $key === $mode ? ' selected' : '';
            $options .= '<option value="' . htmlspecialchars($key, ENT_QUOTES) . '"' . $selected . '>' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
        }

        if ($mode === null) {
            $options = '<option value="" selected>Mode unknown</option>' . $options;
        }

        return '<select id="mode-select"' . ($mode === null ? ' disabled' : '')
            . ' class="text-xs font-medium pl-2 pr-6 py-1 rounded-full border border-slate-700 bg-slate-800 text-slate-300 disabled:opacity-50">'
            . $options
            . '</select>';
    }

    /**
     * "user"/"assistant"/"tool_use"/"tool_result"/"system" - not the same
     * thing as $entry['role'] (Claude Code's own tool_result entries carry
     * role=user under the hood, same as a real typed message - there's no
     * separate "tool" role at the transcript level). An entry with no text at
     * all reads as a tool action, not a conversational message, regardless of
     * its literal role, so it's colored (and labeled - see render_transcript_
     * entry()) as one instead - tool_use and tool_result get their own
     * distinct kinds, not lumped into one "tool" bucket, so a call and its
     * output are never confusable at a glance either.
     *
     * @param array{role?:?string, blocks?:array<int, array{kind:string}>} $entry
     */
    public static function entry_color_kind(array $entry): string
    {
        $blocks = $entry['blocks'] ?? [];
        $hasText = false;
        $hasToolUse = false;
        $hasToolResult = false;
        $isSubagent = false;

        foreach ($blocks as $block) {
            match ($block['kind'] ?? null) {
                'text' => $hasText = true,
                'tool_use' => $hasToolUse = true,
                'tool_result' => $hasToolResult = true,
                default => null,
            };

            if (($block['agent_type'] ?? null) !== null) {
                $isSubagent = true;
            }
        }

        // A subagent launch/report (Claude Code's "Agent" tool - see
        // agent_type in Transcript.php's summarize_content_block()/
        // parse_transcript_line()) gets its own kind, ahead of the generic
        // tool_use/tool_result check below, so it reads as a distinct "this
        // is a subagent" thing rather than just another tool call.
        if (!$hasText && $isSubagent) {
            return $hasToolUse ? 'subagent_call' : 'subagent_result';
        }

        if (!$hasText && $hasToolUse) {
            return 'tool_use';
        }

        if (!$hasText && $hasToolResult) {
            return 'tool_result';
        }

        return match ($entry['role'] ?? null) {
            'assistant' => 'assistant',
            'user' => 'user',
            default => 'system',
        };
    }

    /**
     * @return array{border:string, bg:string, label:string}
     */
    public static function entry_color_classes(string $kind): array
    {
        return match ($kind) {
            // Deliberately not indigo/blue - tool_use (below) is sky to match
            // the existing tool_use block-border convention, and indigo sits
            // too close to sky on the color wheel to reliably tell apart at a
            // glance (found live: they read as "the same color").
            'user' => ['border' => 'border-rose-800/60', 'bg' => 'bg-rose-950/40', 'label' => 'text-rose-300'],
            'assistant' => ['border' => 'border-emerald-800/60', 'bg' => 'bg-emerald-950/40', 'label' => 'text-emerald-300'],
            'tool_use' => ['border' => 'border-sky-800/60', 'bg' => 'bg-sky-950/40', 'label' => 'text-sky-300'],
            'tool_result' => ['border' => 'border-violet-800/60', 'bg' => 'bg-violet-950/40', 'label' => 'text-violet-300'],
            // Shared between call and report - same "this is subagent stuff"
            // color for both, told apart by role label alone, same as every
            // other kind here.
            'subagent_call', 'subagent_result' => ['border' => 'border-fuchsia-800/60', 'bg' => 'bg-fuchsia-950/40', 'label' => 'text-fuchsia-300'],
            default => ['border' => 'border-slate-800', 'bg' => 'bg-slate-900/50', 'label' => 'text-slate-400'],
        };
    }

    /**
     * @param array{role:?string, timestamp:?string, blocks:array<int, array{kind:string, text:string}>} $entry
     */
    public static function render_transcript_entry(array $entry): string
    {
        $role = $entry['role'] ?? 'system';
        $colorKind = self::entry_color_kind($entry);
        // A tool_use/tool_result entry's real role is user/assistant only
        // because that's how Claude Code's own message format works, not
        // because it's meaningfully "the user" or "the assistant" talking -
        // labeling it "Tool" instead matches how it's actually colored.
        $roleLabel = match ($colorKind) {
            'tool_use' => 'Tool call',
            'tool_result' => 'Tool output',
            'subagent_call' => 'Subagent call',
            'subagent_result' => 'Subagent report',
            default => htmlspecialchars(ucfirst((string)$role), ENT_QUOTES),
        };
        $parsedTimestamp = is_string($entry['timestamp'] ?? null) ? strtotime($entry['timestamp']) : false;
        $timestamp = $parsedTimestamp !== false ? htmlspecialchars(SessionRowView::relative_time($parsedTimestamp), ENT_QUOTES) : '';
        $colors = self::entry_color_classes($colorKind);
        // Hides the WHOLE entry (not just the now-hidden tool_result/tool_use
        // block) once the matching "Show tool outputs"/"Show tool calls"
        // toggle turns off, since there'd be nothing left to show otherwise (a
        // bare role-label-only bubble). Neither marker applies to an entry
        // carrying an image, regardless of its kind (found live: this was
        // missing on the first pass for entry-tool-result-only, so an entry
        // with a screenshot still vanished entirely instead of just its text) -
        // an image is always worth keeping visible on its own.
        $hasImage = false;

        foreach ($entry['blocks'] as $block) {
            if (isset($block['image'])) {
                $hasImage = true;
                break;
            }
        }

        $extraClass = '';

        if (!$hasImage) {
            if ($colorKind === 'tool_result' || $colorKind === 'subagent_result') {
                $extraClass = ' entry-tool-result-only';
            } elseif ($colorKind === 'tool_use' || $colorKind === 'subagent_call') {
                $extraClass = ' entry-tool-use-only';
            }
        }

        $blocksHtml = implode('', array_map([self::class, 'render_transcript_block'], $entry['blocks']));

        return '<div class="rounded-lg border ' . $colors['border'] . ' ' . $colors['bg'] . ' px-3 py-2' . $extraClass . '">'
            . '<div class="mb-1 flex items-center gap-2 text-xs text-slate-500">'
            . '<span class="font-medium ' . $colors['label'] . '">' . $roleLabel . '</span>'
            . ($timestamp !== '' ? '<span>' . $timestamp . '</span>' : '')
            . '</div>'
            . '<div class="flex flex-col gap-1.5">' . $blocksHtml . '</div>'
            . '</div>';
    }
}
