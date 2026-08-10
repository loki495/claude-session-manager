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
class TranscriptView extends View
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
        return self::render('transcript/image', [
            'mediaType' => $image['media_type'],
            'data' => $image['data'],
        ]);
    }

    /** Mirrors formatFileSize() in session.js (JS-side counterpart). */
    public static function format_attachment_size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / (1024 * 1024), 1) . ' MB';
    }

    /**
     * $line is the raw JSONL line number a transcript entry was parsed
     * from (see TranscriptService::read_transcript_page()'s 'line' field) -
     * along with $fileUuid, that's enough for the host-agent to re-derive
     * the real file path itself (see TranscriptService::read_attachment()),
     * without the browser ever seeing it. $isArchived routes to the
     * archived-session-view counterpart endpoint, keyed by claude_session_id
     * rather than a live tmux session name - a dormant session has no
     * sidecar for session_attachment.php's own action to resolve one from.
     */
    public static function attachment_url(string $sessionIdentifier, int $line, string $fileUuid, bool $isArchived = false): string
    {
        if ($isArchived) {
            return '/archived_session_attachment.php?claude_session_id=' . rawurlencode($sessionIdentifier) . '&line=' . $line . '&file_uuid=' . rawurlencode($fileUuid);
        }

        return '/session_attachment.php?session=' . rawurlencode($sessionIdentifier) . '&line=' . $line . '&file_uuid=' . rawurlencode($fileUuid);
    }

    /**
     * A file Claude sent via SendUserFile (or, in principle, any future
     * source of the same toolUseResult.attachments shape - see
     * TranscriptService::transcript_attachments_from_tool_use_result() on
     * the host-agent side) - an actual thumbnail (reusing the same
     * .transcript-image tap-to-expand class/behavior as an inline base64
     * image) for an image, a download link showing filename + size for
     * anything else. The filename is always its own separate real link
     * (not just a caption) - deliberately not wrapped around the image
     * itself, since a click there needs to toggle the thumbnail (see the
     * delegated .transcript-image handler in session.js), not navigate.
     *
     * @param array<int, array{file_uuid:string, filename:string, size:int, isImage:bool, media_type:string}> $attachments
     */
    public static function render_transcript_attachments_html(array $attachments, string $sessionIdentifier, int $line, bool $isArchived = false): string
    {
        if ($attachments === []) {
            return '';
        }

        $itemsHtml = '';

        foreach ($attachments as $attachment) {
            $itemsHtml .= self::render('transcript/attachment', [
                'url' => self::attachment_url($sessionIdentifier, $line, $attachment['file_uuid'], $isArchived),
                'filename' => $attachment['filename'],
                'sizeLabel' => self::format_attachment_size($attachment['size']),
                'isImage' => $attachment['isImage'],
            ]);
        }

        return self::render('transcript/attachments', ['itemsHtml' => $itemsHtml]);
    }

    /**
     * $isSubagent picks the extra CSS class (subagent-use-block/subagent-detail,
     * alongside the always-present tool-use-block/tool-detail) that the
     * single "Show subagent calls and outputs" sidebar toggle targets - see
     * session.php's <style> block. A regular (non-subagent) tool_use/
     * tool_result block carries no such marker at all, since 2026-08-08 it's
     * never rendered standalone any more (see render_transcript_entries_html()) -
     * it's always inside a collapsible tool-group instead, whose own
     * <details> is the only show/hide affordance it needs.
     *
     * @param array{kind:string, text:string, image?:array{media_type:string, data:string}, attachments?:array<int, array{file_uuid:string, filename:string, size:int, isImage:bool, media_type:string}>} $block
     */
    public static function render_transcript_block(array $block, string $sessionIdentifier, int $line, bool $isArchived = false, bool $isSubagent = false): string
    {
        $imageHtml = isset($block['image']) ? self::render_transcript_image_html($block['image']) : '';
        $attachmentsHtml = !empty($block['attachments']) ? self::render_transcript_attachments_html($block['attachments'], $sessionIdentifier, $line, $isArchived) : '';

        // The image/attachments are SIBLINGS of .tool-detail, not nested
        // inside it - unlike the raw text output, Andres wants a
        // screenshot or a shared file visible regardless of the
        // show/hide-subagent toggle, since it's often the whole point of
        // having run the tool in the first place.
        $collapsibleHtml = match ($block['kind']) {
            'tool_use' => BlockedPromptView::render_collapsible_block($block['text'], 'border-sky-800/40', 'text-sky-300', '&rarr; '),
            'tool_result' => BlockedPromptView::render_collapsible_block($block['text'], 'border-slate-800', 'text-slate-400', ''),
            default => '',
        };

        return self::render('transcript/block', [
            'kind' => $block['kind'],
            'text' => $block['text'],
            'line' => $line,
            'collapsibleHtml' => $collapsibleHtml,
            'imageHtml' => $imageHtml,
            'attachmentsHtml' => $attachmentsHtml,
            'subagentClass' => $isSubagent ? ($block['kind'] === 'tool_use' ? ' subagent-use-block' : ' subagent-detail') : '',
        ]);
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
        return self::render('transcript/session-static-info', [
            'title' => (string)($detail['title'] ?? $detail['name']),
            'name' => (string)$detail['name'],
            'workdir' => $detail['workdir'] ?? null,
            'relativeTime' => SessionRowView::relative_time((int)$detail['activity']),
            'attached' => (bool)$detail['attached'],
            'contextUsedPercentage' => $detail['context_used_percentage'] ?? null,
            'gitWorktree' => $detail['git_worktree'] ?? null,
        ]);
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
     * thinking content (TranscriptService drops that entirely), just a live
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

        return self::render('transcript/thinking-indicator');
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

        return self::render('transcript/mode-toggle', [
            'mode' => $mode,
            'options' => self::MODE_OPTIONS,
        ]);
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
        $hasPlan = false;
        $planStatus = null;

        foreach ($blocks as $block) {
            match ($block['kind'] ?? null) {
                'text' => $hasText = true,
                'tool_use' => $hasToolUse = true,
                'tool_result' => $hasToolResult = true,
                'plan' => $hasPlan = true,
                default => null,
            };

            if (($block['agent_type'] ?? null) !== null) {
                $isSubagent = true;
            }

            if (($block['plan_status'] ?? null) !== null) {
                $planStatus = $block['plan_status'];
            }
        }

        // A presented/approved/rejected plan (ExitPlanMode - see 'plan'
        // kind and 'plan_status' in TranscriptService's
        // summarize_content_block()/parse_transcript_line()) gets its own
        // kind, ahead of the generic tool_use/tool_result check below, for
        // the same reason a subagent launch/report does: it's functionally
        // "waiting on you" in a way a routine tool call isn't, and deserves
        // to read as a distinct thing rather than just another tool call.
        if (!$hasText && $planStatus !== null) {
            return $planStatus === 'approved' ? 'plan_approved' : 'plan_rejected';
        }

        if (!$hasText && $hasPlan) {
            return 'plan_presented';
        }

        // A subagent launch/report (Claude Code's "Agent" tool - see
        // agent_type in TranscriptService's summarize_content_block()/
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
            // Same shared-color convention as subagent_call/subagent_result
            // above, extended to all three plan states (presented/approved/
            // rejected) - told apart by role label alone.
            'plan_presented', 'plan_approved', 'plan_rejected' => ['border' => 'border-amber-800/60', 'bg' => 'bg-amber-950/40', 'label' => 'text-amber-300'],
            default => ['border' => 'border-slate-800', 'bg' => 'bg-slate-900/50', 'label' => 'text-slate-400'],
        };
    }

    /**
     * The whole point of this app's session page: renders a full batch of
     * history entries (the initial SSR page load, or one "Load older
     * messages"/archived-history fragment fetch) with a run of consecutive
     * groupable tool_use/tool_result entries collapsed into a single
     * <details> "N tool calls" toggle instead of one boxed card per entry -
     * Andres's own framing 2026-08-08: the old per-call/per-output cards
     * were "spammy" shown, and a global on/off toggle lost too much context
     * hidden (no way to sanity-check a "found it" narration without seeing
     * what was actually checked). A single collapsed group per run is the
     * middle ground - always at least a "N tool calls" breadcrumb, full
     * detail one tap away, scoped to just that run rather than the whole
     * session at once.
     *
     * Purely batch-local - this only ever groups within the one array
     * passed in, same "no cross-batch stitching" scoping already used
     * elsewhere in this file (e.g. mode-change detection) - a run that
     * happens to straddle a pagination boundary renders as two groups
     * instead of one, which is an acceptable rare edge case, not something
     * worth the complexity of merging across separate page-load/poll
     * fetches. The live-poll tail-append path in session.js's own mirror of
     * this function is the one exception - it extends an already-open
     * group across separate poll cycles, since that's the common case for
     * a multi-step run that's still in progress.
     *
     * @param array<int, array{role?:?string, timestamp?:?string, line?:int, blocks:array<int, array{kind:string, text:string}>}> $entries
     */
    public static function render_transcript_entries_html(array $entries, string $sessionIdentifier, bool $isArchived = false): string
    {
        $html = '';
        $pendingGroup = [];

        foreach ($entries as $entry) {
            if (self::entry_is_groupable_tool_call($entry)) {
                $pendingGroup[] = $entry;

                continue;
            }

            if ($pendingGroup !== []) {
                $html .= self::render_tool_group_html($pendingGroup, $sessionIdentifier, $isArchived);
                $pendingGroup = [];
            }

            $html .= self::render_transcript_entry($entry, $sessionIdentifier, $isArchived);
        }

        if ($pendingGroup !== []) {
            $html .= self::render_tool_group_html($pendingGroup, $sessionIdentifier, $isArchived);
        }

        return $html;
    }

    /**
     * A subagent call/report is deliberately excluded (kept as its own
     * standalone card, unaffected by grouping - Andres's own call: those
     * stay on the older per-kind "Show subagent calls"/"Show subagent
     * outputs" toggle instead, see entry_color_kind()/render_transcript_
     * entry()'s $isSubagent handling), same as an image or file attachment
     * (must always stay visible on its own, never folded into a
     * collapsed-by-default group - same "always visible" exemption
     * render_transcript_entry() already made for the old per-entry hide
     * toggle).
     *
     * @param array{blocks:array<int, array{kind:string}>} $entry
     */
    private static function entry_is_groupable_tool_call(array $entry): bool
    {
        $colorKind = self::entry_color_kind($entry);

        if ($colorKind !== 'tool_use' && $colorKind !== 'tool_result') {
            return false;
        }

        foreach ($entry['blocks'] as $block) {
            if (isset($block['image']) || !empty($block['attachments'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * $callCount counts real tool_use members specifically (falling back to
     * the raw member count only in the unexpected case of none, e.g. a
     * pagination boundary splitting a pair) - a call+its own result are two
     * separate transcript entries but one logical "tool call" from Andres's
     * point of view, and the summary label should read that way ("2 tool
     * calls" for 2 calls + 2 results, not "4").
     *
     * Pairs each tool_use with the tool_result immediately following it
     * (Claude Code always writes them as consecutive entries) into one
     * combined card via render_tool_pair_html() - Andres's own call
     * 2026-08-08: the call and its own output belong under one entry, not
     * two separate ones, each still independently expandable.
     *
     * @param array<int, array{blocks:array<int, array{kind:string, text:string}>}> $members
     */
    private static function render_tool_group_html(array $members, string $sessionIdentifier, bool $isArchived): string
    {
        $callCount = 0;
        $pairsHtml = '';
        $index = 0;
        $count = count($members);

        while ($index < $count) {
            $member = $members[$index];

            if (self::entry_color_kind($member) === 'tool_use') {
                $callCount++;
                $next = $members[$index + 1] ?? null;
                $nextIsResult = $next !== null && self::entry_color_kind($next) === 'tool_result';
                $pairsHtml .= self::render_tool_pair_html($member, $nextIsResult ? $next : null, $sessionIdentifier, $isArchived);
                $index += $nextIsResult ? 2 : 1;

                continue;
            }

            // An orphaned tool_result with no preceding call in THIS group -
            // shouldn't normally happen (Claude Code always writes a call
            // before its result), but a pagination boundary could in
            // principle split a pair right down the middle.
            $pairsHtml .= self::render_tool_pair_html(null, $member, $sessionIdentifier, $isArchived);
            $index++;
        }

        if ($callCount === 0) {
            $callCount = count($members);
        }

        return self::render('transcript/tool-group', [
            'summaryLabel' => $callCount === 1 ? '1 tool call' : ($callCount . ' tool calls'),
            'membersHtml' => $pairsHtml,
        ]);
    }

    /**
     * One combined card per call+result pair - a single timestamp (the
     * call's own, falling back to the result's if the call is missing),
     * each half still independently expandable via its own existing
     * collapsible-block <details> (render_transcript_block() unchanged),
     * just no longer wrapped in two separate "Tool call"/"Tool output"
     * labeled entry cards. The result half is always wrapped in its own
     * .tool-pair-result-slot div, even when empty (a call with no result
     * yet) - PHP itself never needs it (a full page render always already
     * has both halves), but session.js's own live-poll mirror of this
     * function does: a call and its result can arrive in separate poll
     * cycles, and that div is what session.js fills in in place once the
     * result lands, rather than appending a second separate pair card for
     * what's really one logical tool call.
     */
    private static function render_tool_pair_html(?array $callEntry, ?array $resultEntry, string $sessionIdentifier, bool $isArchived): string
    {
        $timestampSource = $callEntry ?? $resultEntry;
        $parsedTimestamp = is_string($timestampSource['timestamp'] ?? null) ? strtotime($timestampSource['timestamp']) : false;
        $timestamp = $parsedTimestamp !== false ? SessionRowView::relative_time($parsedTimestamp) : '';

        return self::render('transcript/tool-pair', [
            'timestamp' => $timestamp,
            'callHtml' => $callEntry !== null ? self::render_entry_blocks_html($callEntry, $sessionIdentifier, $isArchived) : '',
            'resultHtml' => $resultEntry !== null ? self::render_entry_blocks_html($resultEntry, $sessionIdentifier, $isArchived) : '',
        ]);
    }

    /**
     * @param array{line?:int, blocks:array<int, array{kind:string, text:string}>} $entry
     */
    private static function render_entry_blocks_html(array $entry, string $sessionIdentifier, bool $isArchived): string
    {
        $line = (int)($entry['line'] ?? 0);

        return implode('', array_map(
            static fn(array $block): string => self::render_transcript_block($block, $sessionIdentifier, $line, $isArchived),
            $entry['blocks']
        ));
    }

    /**
     * $sessionIdentifier is a live tmux session name, unless $isArchived is
     * true, in which case it's a claude_session_id instead (see
     * attachment_url()) - the archived-session read-only view's own
     * counterpart to session.php passes its claude_session_id here.
     *
     * @param array{role:?string, timestamp:?string, line?:int, blocks:array<int, array{kind:string, text:string}>} $entry
     */
    public static function render_transcript_entry(array $entry, string $sessionIdentifier, bool $isArchived = false): string
    {
        $role = $entry['role'] ?? 'system';
        $colorKind = self::entry_color_kind($entry);
        // A tool_use/tool_result entry's real role is user/assistant only
        // because that's how Claude Code's own message format works, not
        // because it's meaningfully "the user" or "the assistant" talking -
        // labeling it "Tool" instead matches how it's actually colored.
        // A free-flowing assistant entry (see entry_wrapper_class()) skips
        // the label entirely - Andres's own call: no border/bubble to tell
        // it apart from a user message already, so "Assistant" on every
        // single one just repeats what the free-flowing treatment (plus
        // left alignment) already says, and the timestamp alone is enough.
        $roleLabel = match ($colorKind) {
            'assistant' => '',
            'tool_use' => 'Tool call',
            'tool_result' => 'Tool output',
            'subagent_call' => 'Subagent call',
            'subagent_result' => 'Subagent report',
            'plan_presented' => 'Plan',
            'plan_approved' => 'Plan approved',
            'plan_rejected' => 'Plan rejected',
            default => ucfirst((string)$role),
        };
        $parsedTimestamp = is_string($entry['timestamp'] ?? null) ? strtotime($entry['timestamp']) : false;
        $timestamp = $parsedTimestamp !== false ? SessionRowView::relative_time($parsedTimestamp) : '';
        $colors = self::entry_color_classes($colorKind);
        $isSubagent = $colorKind === 'subagent_call' || $colorKind === 'subagent_result';
        // Hides the WHOLE entry (not just the now-hidden tool_result/tool_use
        // block) once the single "Show subagent calls and outputs" toggle
        // turns off, since there'd be nothing left to show otherwise (a bare
        // role-label-only bubble). A plain (non-subagent) tool_use/
        // tool_result entry never gets this marker at all - since 2026-08-08
        // it's always swept into a collapsible tool-group instead (see
        // render_transcript_entries_html()), never rendered standalone. The
        // marker never applies to an entry carrying an image or a file
        // attachment either, regardless of who it came from (found live:
        // this was missing on the first pass, so an entry with a screenshot
        // still vanished entirely instead of just its text) - a shared file
        // is always worth keeping visible on its own.
        $hasAttachment = false;

        foreach ($entry['blocks'] as $block) {
            if (isset($block['image']) || !empty($block['attachments'])) {
                $hasAttachment = true;
                break;
            }
        }

        $extraClass = (!$hasAttachment && $isSubagent) ? ' entry-subagent-only' : '';

        $line = (int)($entry['line'] ?? 0);
        $blocksHtml = implode('', array_map(
            static fn(array $block): string => self::render_transcript_block($block, $sessionIdentifier, $line, $isArchived, $isSubagent),
            $entry['blocks']
        ));

        return self::render('transcript/entry', [
            'labelClass' => $colors['label'],
            'roleLabel' => $roleLabel,
            'timestamp' => $timestamp,
            'blocksHtml' => $blocksHtml,
            'wrapperClass' => self::entry_wrapper_class($colorKind, $colors, $extraClass),
        ]);
    }

    /**
     * Claude-app-style treatment: a real user message reads as a filled
     * bubble (own background, fully rounded, right-aligned on desktop - see
     * the isUserEntry comment this replaces for why that alignment stays
     * desktop-only); a plain assistant reply reads as free-flowing text,
     * no border/background/max-width, same as the real Claude app - even
     * when it also carries tool_use blocks alongside its text, since those
     * blocks keep their own independent border via
     * BlockedPromptView::render_collapsible_block() regardless of the
     * entry wrapper around them. Every other kind (tool call/output alone,
     * subagent, plan, system) keeps today's boxed-card treatment
     * unchanged - Andres's own call: those stay "as they are".
     *
     * A free-flowing assistant entry also gets a bit of extra top margin
     * (on top of #history-list's own flex gap-2) - without a border/bg to
     * separate it from whatever's above, consecutive entries read as too
     * cramped without it. The entry-free-flowing marker class carries no
     * styling of its own - it's what session.php's <style> block hooks the
     * new-content glow's wider ring onto specifically for this kind (found
     * live 2026-08-08: with no padding of its own, the glow's crisp inner
     * ring sat flush against the text, looking like a stray outline rather
     * than a glow with room to breathe).
     *
     * @param array{border:string, bg:string, label:string} $colors
     */
    private static function entry_wrapper_class(string $colorKind, array $colors, string $extraClass): string
    {
        if ($colorKind === 'assistant') {
            return 'entry-free-flowing mt-2 lg:max-w-full lg:self-start' . $extraClass;
        }

        $isBubble = $colorKind === 'user';
        $rounding = $isBubble ? 'rounded-2xl' : 'rounded-lg';

        return $rounding . ' border ' . $colors['border'] . ' ' . $colors['bg'] . ' px-3 py-2' . $extraClass . ' lg:max-w-[75%] ' . ($isBubble ? 'lg:self-end' : 'lg:self-start');
    }
}
