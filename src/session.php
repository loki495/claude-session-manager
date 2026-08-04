<?php
declare(strict_types=1);

require __DIR__ . '/lib/AgentClient.php';
require __DIR__ . '/lib/Auth.php';

start_app_session();

$sessionName = trim((string)($_GET['session'] ?? $_POST['session'] ?? ''));

if ($sessionName === '') {
    header('Location: /', true, 303);
    exit;
}

$csrfToken = csrf_token();

$detail = agent_call(['action' => 'session_detail', 'session' => $sessionName]);
$found = (bool)($detail['ok'] ?? false);

$pushResult = agent_call(['action' => 'push_public_key']);
$vapidPublicKey = (string)($pushResult['public_key'] ?? '');

$history = $found ? agent_call(['action' => 'session_history', 'session' => $sessionName, 'before' => null, 'limit' => 30]) : ['ok' => false];
$historyOk = (bool)($history['ok'] ?? false);
$entries = $historyOk ? ($history['entries'] ?? []) : [];
$nextBefore = $historyOk ? ($history['next_before'] ?? null) : null;
$hasMore = $historyOk && ($history['has_more'] ?? false);
$newestLine = !empty($entries) ? end($entries)['line'] : null;

/**
 * @param array{media_type:string, data:string} $image
 */
// Starts as a small square thumbnail (cropped via object-cover, not
// scaled - overflow-hidden isn't needed separately since object-cover
// itself never overflows its box) - a full-size screenshot inline by
// default would dominate the transcript. Tapping toggles to full size and
// back (see the delegated click handler below) by swapping these classes
// for w-full h-auto object-contain, not a separate lightbox/modal.
function render_transcript_image_html(array $image): string
{
    $mediaType = htmlspecialchars($image['media_type'], ENT_QUOTES);
    $data = htmlspecialchars($image['data'], ENT_QUOTES);

    return '<img src="data:' . $mediaType . ';base64,' . $data . '" loading="lazy" alt="Image" class="transcript-image mt-1.5 rounded border border-slate-800 cursor-pointer w-24 h-24 object-cover">';
}

/**
 * @param array{kind:string, text:string, image?:array{media_type:string, data:string}} $block
 */
function render_transcript_block(array $block): string
{
    $text = htmlspecialchars($block['text'], ENT_QUOTES);
    $imageHtml = isset($block['image']) ? render_transcript_image_html($block['image']) : '';

    // break-words (not break-all, used elsewhere for compact collapsed
    // summary lines) - this is prose, so only a genuinely too-long token
    // (a long constant name, URL, hash, ...) should ever break mid-word;
    // normal short words shouldn't. Found live: a message containing
    // "FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE" (no spaces,
    // 51 chars) widened the whole page horizontally without this.
    return match ($block['kind']) {
        'text' => '<p class="whitespace-pre-wrap break-words text-sm text-slate-100">' . $text . '</p>',
        'tool_use' => '<div class="tool-use-block">' . render_collapsible_block($block['text'], 'border-sky-800/40', 'text-sky-300', '&rarr; ') . '</div>',
        // The image (a browser-automation screenshot, most likely) is a
        // SIBLING of .tool-detail, not nested inside it - unlike the raw
        // text output, Andres wants a screenshot visible regardless of
        // the show/hide-tool-details toggle, since it's often the whole
        // point of having run the tool in the first place.
        'tool_result' => '<div class="tool-detail">' . render_collapsible_block($block['text'], 'border-slate-800', 'text-slate-400', '') . '</div>' . $imageHtml,
        'image' => $imageHtml !== '' ? $imageHtml : ($text !== '' ? '<p class="break-words text-xs text-slate-600">' . $text . '</p>' : ''),
        default => $text !== '' ? '<p class="break-words text-xs text-slate-600">' . $text . '</p>' : '',
    };
}

/**
 * The session-info card's static content (title/name/workdir/activity) -
 * used for the initial render and mirrored in JS for the visibility-gated
 * poll that keeps it live without a page reload (see the inline script).
 * The blocked-prompt panel is deliberately NOT part of this card - see
 * render_blocked_prompt_section_html(), placed after the history list so
 * the actionable part of the page reads bottom-up like a chat, not stuck
 * above a scrollable transcript.
 */
function render_session_static_info_html(array $detail): string
{
    $html = '<div class="text-base font-medium truncate">' . htmlspecialchars((string)($detail['title'] ?? $detail['name']), ENT_QUOTES) . '</div>';
    $html .= '<div class="font-mono text-xs text-slate-500 truncate mt-0.5">' . htmlspecialchars((string)$detail['name'], ENT_QUOTES) . '</div>';

    if (!empty($detail['workdir'])) {
        $html .= '<div class="text-xs text-slate-500 truncate mt-0.5">' . htmlspecialchars((string)$detail['workdir'], ENT_QUOTES) . '</div>';
    }

    $html .= '<div class="text-xs text-slate-400 mt-1 flex items-center gap-2">';
    $html .= '<span>' . htmlspecialchars(relative_time((int)$detail['activity']), ENT_QUOTES) . '</span>';
    $html .= '<span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span>';
    $html .= $detail['attached'] ? '<span class="text-emerald-400">attached</span>' : '<span class="text-slate-500">detached</span>';
    $html .= '</div>';

    return $html;
}

/**
 * The pending action that triggered the current prompt (a tool call
 * that's awaiting approval, the folder being trust-checked, ...), shown
 * as its own message bubble right before the "waiting on input" panel -
 * this is the one real thing missing from the history list itself: the
 * transcript file typically doesn't get the triggering tool_use written
 * to it until *after* it's approved and actually runs, so without this
 * the pending action would otherwise just be invisible. Sourced from the
 * same live pane capture as the question/options (prompt_context), not
 * from the transcript.
 */
/**
 * The blocked-prompt card (question, the pending command/context in a
 * collapsed-by-default block, Approve/Deny buttons - all one unified
 * card, via the shared blocked_prompt_rich_html()) - empty string when
 * the session isn't currently blocked. Placed after the history list, and
 * re-rendered in place by the same visibility-gated poll that appends new
 * messages, so it always sits right where the latest activity is, not
 * pinned above a long transcript.
 */
function render_blocked_prompt_section_html(array $detail, string $csrfToken): string
{
    return blocked_prompt_rich_html($detail, $csrfToken);
}

/**
 * A single, transient "Claude is thinking…" indicator - never the actual
 * thinking content (Transcript.php drops that entirely), just a live
 * "something is happening right now" signal sourced from the pane title's
 * spinner glyph (see pane_title_is_working() in Sessions.php). Mutually
 * exclusive with the blocked-prompt section: a session that's actively
 * working isn't also sitting on an unanswered prompt.
 */
function render_thinking_indicator_html(array $detail): string
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
 * Display label for a mode key as returned by parse_current_mode() in
 * Sessions.php - those keys are the lowercase phrase Claude Code itself
 * prints in its status line ("manual", "accept edits", "plan", "auto").
 */
/**
 * Mirrors CLAUDE_CODE_MODE_STATUS_PHRASES's key order in Sessions.php
 * (host-agent, a separate process reached only via the socket - not
 * directly shareable) - keys must match set_mode()'s $targetMode exactly.
 */
const MODE_OPTIONS = ['manual' => 'Manual', 'accept edits' => 'Accept Edits', 'plan' => 'Plan', 'auto' => 'Auto'];

/**
 * A small select showing the session's current permission mode, next to
 * the compose box - choosing a different one jumps straight to it (via
 * set_mode() in Sessions.php, which works out the needed Shift+Tab steps
 * server-side). Disabled while the mode can't currently be read from the
 * pane (e.g. a blocking prompt is covering the status line) - set_mode()
 * needs a known starting point to compute the jump.
 */
function render_mode_toggle_html(array $detail): string
{
    $mode = is_string($detail['current_mode'] ?? null) ? $detail['current_mode'] : null;

    $options = '';
    foreach (MODE_OPTIONS as $key => $label) {
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
function entry_color_kind(array $entry): string
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
function entry_color_classes(string $kind): array
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
function render_transcript_entry(array $entry): string
{
    $role = $entry['role'] ?? 'system';
    $colorKind = entry_color_kind($entry);
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
    $timestamp = $parsedTimestamp !== false ? htmlspecialchars(relative_time($parsedTimestamp), ENT_QUOTES) : '';
    $colors = entry_color_classes($colorKind);
    // Hides the WHOLE entry (not just the now-hidden tool_result/tool_use
    // block) once the matching "Show tool usage details"/"Show tool calls"
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

    $blocksHtml = implode('', array_map('render_transcript_block', $entry['blocks']));

    return '<div class="rounded-lg border ' . $colors['border'] . ' ' . $colors['bg'] . ' px-3 py-2' . $extraClass . '">'
        . '<div class="mb-1 flex items-center gap-2 text-xs text-slate-500">'
        . '<span class="font-medium ' . $colors['label'] . '">' . $roleLabel . '</span>'
        . ($timestamp !== '' ? '<span>' . $timestamp . '</span>' : '')
        . '</div>'
        . '<div class="flex flex-col gap-1.5">' . $blocksHtml . '</div>'
        . '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=5, viewport-fit=cover">
<title><?= $found ? htmlspecialchars((string)($detail['title'] ?? $detail['name']), ENT_QUOTES) : 'Claude Session Manager' ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  /* Toggled via the "Show tool usage details" sidebar setting - a body-level
     class + CSS rule so it applies uniformly to blocks rendered later by the
     poll too, without needing to walk/re-tag the DOM on every render. */
  body.hide-tool-details .tool-detail { display: none; }
  /* An entry whose ONLY blocks are tool_result (marked at render time, see
     entry-tool-result-only in render_transcript_entry()/renderEntry()) has
     nothing left to show once the rule above hides its content - without
     this it's a superfluous empty "User" bubble (role label + timestamp,
     no body). */
  body.hide-tool-details .entry-tool-result-only { display: none; }
  /* Toggled via the separate "Show tool calls" sidebar setting - tool_use
     blocks (the call itself, e.g. "Bash(...)") are unaffected by the
     hide-tool-details rule above, which only ever targets tool_result (the
     output), so this needs its own class + rule pair, same pattern. */
  body.hide-tool-calls .tool-use-block { display: none; }
  /* Same reasoning as entry-tool-result-only above, mirrored for entries
     whose ONLY blocks are tool_use (marked at render time, see
     entry-tool-use-only in render_transcript_entry()/renderEntry()). */
  body.hide-tool-calls .entry-tool-use-only { display: none; }
  /* Marks where newly-polled entries start (see markNewContent() in the
     <script> below) - opacity transition only, no layout-affecting
     property, so the fade-out never causes a scroll jump right as the user
     is looking at it. */
  .new-content-divider { opacity: 1; transition: opacity 0.8s ease-out; }
  .new-content-divider.fading { opacity: 0; }
  /* Highlights the actual new entry bubbles, not just the divider above
     them - a box-shadow ring rather than a background tint, so it doesn't
     fight with each entry's own role-color background/border (see
     entry_color_classes()). Same two-class fade pattern as
     .new-content-divider above and for the same reason: the `transition`
     property has to stay on the element for the whole fade, so toggling
     .fading (which zeroes the ring's alpha) is what animates, rather than
     removing .new-content-highlight itself mid-fade - that would strip
     `transition` at the same instant as `box-shadow`, snapping it off
     instead of fading (caught live: the ring vanished with no animation
     at all before this fix). */
  .new-content-highlight { box-shadow: 0 0 0 2px rgba(129, 140, 248, 0.6); transition: box-shadow 1.2s ease-out; }
  .new-content-highlight.fading { box-shadow: 0 0 0 2px rgba(129, 140, 248, 0); }
</style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">

<header class="sticky top-0 z-20 bg-slate-950/95 backdrop-blur border-b border-slate-800">
  <div class="max-w-2xl mx-auto px-4 py-2 grid grid-cols-[auto_1fr_auto] items-center gap-2">
    <a href="/" class="text-sm text-slate-400 hover:underline whitespace-nowrap">&larr; All sessions</a>
    <div id="header-title" class="text-sm font-medium text-slate-200 truncate text-center">
      <?= $found ? htmlspecialchars((string)($detail['title'] ?? $detail['name']), ENT_QUOTES) : '' ?>
    </div>
    <div class="flex items-center gap-1 justify-self-end">
      <select id="poll-interval-select" aria-label="Polling interval"
        class="text-xs font-medium pl-1.5 pr-5 py-1 rounded-full border border-slate-700 bg-slate-800 text-slate-400">
        <option value="1000">1s</option>
        <option value="3000" selected>3s</option>
        <option value="5000">5s</option>
        <option value="10000">10s</option>
        <option value="15000">15s</option>
      </select>
      <button type="button" id="sidebar-toggle-btn" aria-label="Show other sessions"
        class="relative text-slate-400 active:text-slate-200 -mr-2 px-2 py-1 text-lg leading-none">
        &#9776;
        <span id="sidebar-notify-dot" class="hidden absolute top-0.5 right-1 w-2 h-2 rounded-full"></span>
      </button>
    </div>
  </div>
</header>

<div id="sidebar-overlay" class="hidden fixed inset-0 bg-black/60 z-30"></div>
<aside id="sidebar"
  class="fixed top-0 right-0 h-full w-72 max-w-[85vw] bg-slate-900 border-l border-slate-800 z-40 translate-x-full transition-transform duration-200 ease-out overflow-y-auto">
  <div class="flex items-center justify-between px-4 py-3 border-b border-slate-800 sticky top-0 bg-slate-900">
    <span class="text-sm font-medium text-slate-200">Other sessions</span>
    <button type="button" id="sidebar-close-btn" aria-label="Close" class="text-slate-400 active:text-slate-200 px-1 text-lg leading-none">&times;</button>
  </div>
  <div id="sidebar-list" class="divide-y divide-slate-800 text-sm">
    <div class="px-4 py-3 text-slate-500">Loading&hellip;</div>
  </div>
  <div class="px-4 py-3 border-t border-slate-800 flex flex-col gap-2">
    <span class="block text-xs font-medium text-slate-500 mb-1">Settings</span>
    <label class="flex items-center gap-2 text-sm text-slate-300">
      <input type="checkbox" id="confirm-before-answer-toggle" class="rounded border-slate-600 bg-slate-800">
      Confirm before sending prompt answers
    </label>
    <label class="flex items-center gap-2 text-sm text-slate-300">
      <input type="checkbox" id="show-tool-details-toggle" class="rounded border-slate-600 bg-slate-800">
      Show tool usage details
    </label>
    <label class="flex items-center gap-2 text-sm text-slate-300">
      <input type="checkbox" id="show-tool-calls-toggle" class="rounded border-slate-600 bg-slate-800" checked>
      Show tool calls
    </label>
  </div>
  <?php if ($found): ?>
    <div class="px-4 py-3 border-t border-slate-800">
      <form method="post" action="/" onsubmit="return confirm('Close session <?= htmlspecialchars($sessionName, ENT_QUOTES) ?>?');">
        <input type="hidden" name="action" value="kill">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <input type="hidden" name="session" value="<?= htmlspecialchars($sessionName, ENT_QUOTES) ?>">
        <button type="submit"
          class="w-full min-h-[2.75rem] rounded-lg bg-red-900/70 active:bg-red-800 text-red-100 font-medium text-sm px-4 py-2">
          Close session
        </button>
      </form>
    </div>
  <?php endif; ?>
</aside>

<div class="max-w-2xl mx-auto px-4 py-6 pb-44">

  <?php if (!$found): ?>
    <div class="rounded-lg px-4 py-3 text-sm bg-red-900/50 text-red-200 border border-red-700">
      <p class="font-medium">Session not found.</p>
      <p class="mt-1"><?= htmlspecialchars((string)($detail['message'] ?? 'Unknown error'), ENT_QUOTES) ?></p>
    </div>
  <?php else: ?>
    <div id="session-info" class="mb-4 rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-3">
      <?= render_session_static_info_html($detail) ?>
    </div>

    <h2 class="text-sm font-medium text-slate-400 mb-2">History</h2>

    <?php if (!$historyOk): ?>
      <div class="rounded-lg px-4 py-3 text-sm bg-slate-900/50 border border-slate-800 text-slate-500">
        <?= htmlspecialchars((string)($history['message'] ?? 'No transcript available for this session.'), ENT_QUOTES) ?>
      </div>
    <?php elseif (empty($entries)): ?>
      <div class="rounded-lg px-4 py-3 text-sm bg-slate-900/50 border border-slate-800 text-slate-500">
        Nothing recorded yet.
      </div>
    <?php else: ?>
      <button type="button" id="load-more-btn"
        data-session="<?= htmlspecialchars($sessionName, ENT_QUOTES) ?>"
        data-before="<?= $nextBefore !== null ? (int)$nextBefore : '' ?>"
        class="w-full mb-2 rounded-lg border border-slate-800 bg-slate-900/50 active:bg-slate-800 text-xs text-slate-400 px-3 py-2 <?= $hasMore ? '' : 'hidden' ?>">
        Load older messages
      </button>
      <div id="history-list" class="flex flex-col gap-2">
        <?php foreach ($entries as $entry): ?>
          <?= render_transcript_entry($entry) ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div id="thinking-indicator" class="mt-4">
      <?= render_thinking_indicator_html($detail) ?>
    </div>

    <!-- The live, actionable prompt state sits after history, not pinned
         above it - reads like the "current" end of the chat, and is what
         the initial/poll-triggered auto-scroll brings into view. -->
    <div id="blocked-prompt-section" class="mt-4">
      <?= render_blocked_prompt_section_html($detail, $csrfToken) ?>
    </div>
  <?php endif; ?>

</div>

<button type="button" id="go-to-bottom-btn"
  class="hidden fixed bottom-24 right-5 z-20 w-11 h-11 rounded-full border border-slate-700 bg-slate-800 text-slate-200 shadow-lg active:bg-slate-700 flex items-center justify-center text-lg">
  &darr;
</button>

<?php if ($found): ?>
  <?php $composeBlocked = !empty($detail['blocked_reason']); ?>
  <div id="compose-bar" class="fixed bottom-0 inset-x-0 z-20 bg-slate-950/95 backdrop-blur border-t border-slate-800 px-4 py-3">
    <div class="max-w-2xl mx-auto">
      <div id="compose-input-row" class="<?= $composeBlocked ? 'hidden' : '' ?>">
        <div class="flex items-stretch gap-2">
          <textarea id="compose-textarea" rows="1" placeholder="Message&hellip;"
            class="flex-1 resize-none rounded-lg bg-slate-800 border border-slate-700 text-base text-slate-100 px-3 py-2 max-h-32 overflow-y-auto focus:outline-none focus:border-slate-500"></textarea>
          <button type="button" id="compose-send-btn"
            class="min-h-[2.75rem] shrink-0 rounded-lg bg-indigo-600 active:bg-indigo-700 disabled:opacity-50 disabled:active:bg-indigo-600 font-medium text-sm px-4 py-2">
            Send
          </button>
        </div>
        <div id="compose-status" class="hidden text-xs text-red-400 mt-1"></div>
      </div>
      <div id="compose-blocked-note" class="<?= $composeBlocked ? '' : 'hidden' ?> text-xs text-slate-500 py-2">
        Answer the prompt above to continue.
      </div>
      <div class="mt-2">
        <?= quota_footer_html(render_mode_toggle_html($detail)) ?>
        <?= push_notify_button_html($vapidPublicKey, $csrfToken) ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
(function () {
  var infoBox = document.getElementById('session-info');
  var headerTitle = document.getElementById('header-title');

  if (!infoBox) {
    return; // session not found - nothing here to wire up
  }

  var sessionName = <?= json_encode($sessionName) ?>;
  var csrfToken = <?= json_encode($csrfToken) ?>;
  var btn = document.getElementById('load-more-btn');
  var list = document.getElementById('history-list');
  var thinkingIndicator = document.getElementById('thinking-indicator');
  var blockedSection = document.getElementById('blocked-prompt-section');
  var goToBottomBtn = document.getElementById('go-to-bottom-btn');
  var composeBar = document.getElementById('compose-bar');
  var composeInputRow = document.getElementById('compose-input-row');
  var composeBlockedNote = document.getElementById('compose-blocked-note');
  var newestLine = <?= json_encode($newestLine) ?>;

  // --- optimistic UI state: entries appended locally right after sending,
  // before a poll has confirmed they actually landed. See appendPendingEntry()
  // and pollHistory() below for the append/reconcile lifecycle, and
  // markBlockedSectionAnswerPending()/renderBlockedSection() for the
  // matching "answered, waiting to confirm" treatment on the blocked-prompt
  // card itself. ---
  var pendingEntries = [];
  var currentBlockedReason = null;
  var answerPendingReason = null;
  // The pending history bubble for a just-submitted prompt answer - unlike
  // a compose message (real text, closely matches its eventual transcript
  // entry), an answer's confirmed entry likely isn't literally the button
  // label text, so reconcilePendingEntries()'s content-matching may never
  // find it - found live: it stayed dimmed "Sending…" forever even once
  // the prompt itself had genuinely resolved. Tied directly to
  // answerPendingReason instead, in renderBlockedSection() below: the
  // prompt actually resolving is a far more reliable confirmation signal
  // for THIS entry specifically than generic content-matching.
  var answerPendingHistoryEl = null;
  var lastRenderedBlockedKey; // undefined, not null - see renderBlockedSection()
  var lastRenderedThinkingShown; // undefined, not null - see renderThinkingIndicator()
  var lastRenderedStaticInfoKey; // undefined, not null - see renderStaticInfo()

  // The most recent "new since you last looked" markers (see
  // markNewContent()) - a divider above the batch plus a highlight ring on
  // each entry in it, tracked so a later poll can clear both before
  // placing a fresh batch's markers, rather than letting them pile up one
  // per poll cycle.
  var newContentDivider = null;
  var newContentHighlighted = [];

  // Mirrors the $composeBlocked SSR toggle above - hides the message
  // input (not the whole compose bar; quota/mode stay visible) while a
  // prompt is pending, forcing it to be answered first. The textarea
  // itself is only hidden via CSS, never removed from the DOM, so
  // whatever's been typed survives a prompt appearing mid-draft.
  function renderComposeVisibility(detail) {
    if (!composeInputRow || !composeBlockedNote) {
      return;
    }

    composeInputRow.classList.toggle('hidden', !!detail.blocked_reason);
    composeBlockedNote.classList.toggle('hidden', !detail.blocked_reason);
  }

  // The compose bar's height varies (quota footer collapsed/expanded, textarea
  // auto-grow), so the floating button's offset is tracked live rather than fixed.
  if (goToBottomBtn && composeBar && window.ResizeObserver) {
    var GO_TO_BOTTOM_GAP_PX = 12;
    new ResizeObserver(function () {
      goToBottomBtn.style.bottom = (composeBar.offsetHeight + GO_TO_BOTTOM_GAP_PX) + 'px';
    }).observe(composeBar);
  }

  // iOS Safari's position:fixed is anchored to the *layout* viewport, not
  // the *visual* one - its dynamic toolbar (hides/shows on scroll) shrinks
  // and grows the visual viewport independently of the layout viewport, so
  // a fixed bottom-0 element visibly drifts/jumps rather than staying
  // glued to what's actually on screen. Found live: this was most obvious
  // with an empty textarea (a shorter page, closer to the toolbar's
  // collapse threshold) - with text in it the page happened to already sit
  // past that threshold, masking the bug rather than avoiding it.
  // visualViewport tracks the real visible area (both for this and for the
  // keyboard, same API isNearBottom() below already relies on) -
  // translating the fixed elements by how much of the layout viewport
  // currently sits below the visible area keeps them correctly pinned
  // regardless of toolbar state or keyboard.
  function updateFixedFooterOffset() {
    if (!window.visualViewport) {
      return;
    }

    var hiddenBelow = window.innerHeight - (window.visualViewport.height + window.visualViewport.offsetTop);
    var transform = hiddenBelow > 0 ? 'translateY(-' + hiddenBelow + 'px)' : '';

    if (composeBar) {
      composeBar.style.transform = transform;
    }

    if (goToBottomBtn) {
      goToBottomBtn.style.transform = transform;
    }
  }

  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', updateFixedFooterOffset);
    window.visualViewport.addEventListener('scroll', updateFixedFooterOffset);
    updateFixedFooterOffset();
  }

  // --- slideable sidebar: other sessions' status/prompt, fetched fresh each
  // time it's opened rather than polled continuously in the background. ---

  var sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
  var sidebarCloseBtn = document.getElementById('sidebar-close-btn');
  var sidebarOverlay = document.getElementById('sidebar-overlay');
  var sidebar = document.getElementById('sidebar');
  var sidebarList = document.getElementById('sidebar-list');
  var sidebarNotifyDot = document.getElementById('sidebar-notify-dot');

  // Setting: whether answering a plain prompt option asks for confirmation
  // first. Shared (same localStorage key, same helper duplicated) with
  // index.php's dashboard rows, which answer prompts too but have no
  // sidebar of their own to host the checkbox.
  var CONFIRM_BEFORE_ANSWER_KEY = 'csm-confirm-before-answer';

  function shouldConfirmBeforeAnswer() {
    try {
      return window.localStorage.getItem(CONFIRM_BEFORE_ANSWER_KEY) !== '0';
    } catch (e) {
      return true;
    }
  }

  var confirmBeforeAnswerToggle = document.getElementById('confirm-before-answer-toggle');

  if (confirmBeforeAnswerToggle) {
    confirmBeforeAnswerToggle.checked = shouldConfirmBeforeAnswer();

    confirmBeforeAnswerToggle.addEventListener('change', function () {
      try {
        window.localStorage.setItem(CONFIRM_BEFORE_ANSWER_KEY, confirmBeforeAnswerToggle.checked ? '1' : '0');
      } catch (e) {}
    });
  }

  // Setting: whether tool_use/tool_result blocks show in the transcript at
  // all - a body-level class + CSS rule (see <style> in <head>) so it
  // applies to blocks the poll renders later too, without re-walking the DOM.
  var SHOW_TOOL_DETAILS_KEY = 'csm-show-tool-details';

  function shouldShowToolDetails() {
    try {
      return window.localStorage.getItem(SHOW_TOOL_DETAILS_KEY) !== '0';
    } catch (e) {
      return true;
    }
  }

  function applyShowToolDetails(show) {
    document.body.classList.toggle('hide-tool-details', !show);
  }

  var showToolDetailsToggle = document.getElementById('show-tool-details-toggle');

  if (showToolDetailsToggle) {
    var showToolDetails = shouldShowToolDetails();
    showToolDetailsToggle.checked = showToolDetails;
    applyShowToolDetails(showToolDetails);

    showToolDetailsToggle.addEventListener('change', function () {
      applyShowToolDetails(showToolDetailsToggle.checked);

      try {
        window.localStorage.setItem(SHOW_TOOL_DETAILS_KEY, showToolDetailsToggle.checked ? '1' : '0');
      } catch (e) {}
    });
  }

  // Setting: whether tool_use blocks (the tool CALL itself - "Bash(...)",
  // "Write(...)", etc.) show in the transcript at all - separate from
  // SHOW_TOOL_DETAILS_KEY above, which only ever affects tool_result
  // blocks (the output). Same body-level-class + CSS-rule pattern.
  var SHOW_TOOL_CALLS_KEY = 'csm-show-tool-calls';

  function shouldShowToolCalls() {
    try {
      return window.localStorage.getItem(SHOW_TOOL_CALLS_KEY) !== '0';
    } catch (e) {
      return true;
    }
  }

  function applyShowToolCalls(show) {
    document.body.classList.toggle('hide-tool-calls', !show);
  }

  var showToolCallsToggle = document.getElementById('show-tool-calls-toggle');

  if (showToolCallsToggle) {
    var showToolCalls = shouldShowToolCalls();
    showToolCallsToggle.checked = showToolCalls;
    applyShowToolCalls(showToolCalls);

    showToolCallsToggle.addEventListener('change', function () {
      applyShowToolCalls(showToolCallsToggle.checked);

      try {
        window.localStorage.setItem(SHOW_TOOL_CALLS_KEY, showToolCallsToggle.checked ? '1' : '0');
      } catch (e) {}
    });
  }

  // Lets the toggle button itself signal severity without opening the
  // drawer: amber if another session is blocked (waiting on a prompt) -
  // always live, never "seen" since it's still actionable right now; else
  // emerald if another session just finished all its work (went idle) and
  // that finish hasn't been observed yet (see markOthersSeen()); else no
  // dot at all. Persisted per-session state (SIDEBAR_SESSION_STATE_KEY)
  // is what lets "just finished" survive across poll cycles until the
  // sidebar is actually opened and looked at, and what stops an idle
  // session that's simply always been idle from lighting up green on
  // first-ever observation (a transition has to be detected, not just a
  // state).
  var SIDEBAR_SESSION_STATE_KEY = 'csm-sidebar-session-state';

  function readSidebarSessionState() {
    try {
      var raw = window.localStorage.getItem(SIDEBAR_SESSION_STATE_KEY);
      return raw ? JSON.parse(raw) : {};
    } catch (e) {
      return {};
    }
  }

  function writeSidebarSessionState(state) {
    try {
      window.localStorage.setItem(SIDEBAR_SESSION_STATE_KEY, JSON.stringify(state));
    } catch (e) {}
  }

  function otherSessionState(s) {
    if (s.blocked_reason) {
      return 'blocked';
    }
    if (s.working) {
      return 'working';
    }
    return 'idle';
  }

  function applySidebarNotifyDot(kind) {
    if (!sidebarNotifyDot) {
      return;
    }
    sidebarNotifyDot.classList.toggle('hidden', kind === null);
    sidebarNotifyDot.classList.toggle('bg-amber-400', kind === 'blocked');
    sidebarNotifyDot.classList.toggle('bg-emerald-400', kind === 'finished');
  }

  // Shared by refreshSidebarNotification() (every poll cycle, markSeen
  // false) and loadSidebarList() (sidebar actually opened, markSeen true -
  // that's the "look" that clears the green dot for any idle session it
  // just displayed).
  function processOtherSessions(others, markSeen) {
    var stored = readSidebarSessionState();
    var next = {};
    var anyBlocked = false;
    var anyUnseenFinished = false;

    others.forEach(function (s) {
      var state = otherSessionState(s);
      var prev = stored[s.name];
      var unseen = !!(prev && prev.unseen);

      if (state === 'idle') {
        if (prev && prev.state !== 'idle') {
          unseen = true;
        }
        if (markSeen) {
          unseen = false;
        }
      } else {
        unseen = false;
        if (state === 'blocked') {
          anyBlocked = true;
        }
      }

      next[s.name] = { state: state, unseen: unseen };

      if (state === 'idle' && unseen) {
        anyUnseenFinished = true;
      }
    });

    writeSidebarSessionState(next);
    applySidebarNotifyDot(anyBlocked ? 'blocked' : (anyUnseenFinished ? 'finished' : null));
  }

  function refreshSidebarNotification() {
    if (!sidebarNotifyDot) {
      return Promise.resolve();
    }

    return fetch('/sessions_list.php', { credentials: 'same-origin', signal: pollAbortController.signal })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) {
          return;
        }

        var others = (data.sessions || []).filter(function (s) { return s.name !== sessionName; });
        processOtherSessions(others, false);
      })
      .catch(function () {});
  }

  function sidebarStatusBadge(s) {
    if (s.blocked_reason) {
      return '<span class="inline-block px-1.5 py-0.5 rounded text-xs bg-amber-900/60 text-amber-300">waiting</span>';
    }
    if (s.working) {
      return '<span class="inline-block px-1.5 py-0.5 rounded text-xs bg-indigo-900/60 text-indigo-300">working</span>';
    }
    return '<span class="inline-block px-1.5 py-0.5 rounded text-xs bg-slate-800 text-slate-400">' + (s.attached ? 'attached' : 'detached') + '</span>';
  }

  function sidebarRowHtml(s) {
    var label = s.title || s.name;
    var sub = s.blocked_reason
      ? s.blocked_reason
      : (s.last_message && s.last_message.blocks && s.last_message.blocks[0] ? s.last_message.blocks[0].text : '');
    var subHtml = sub ? '<div class="text-xs text-slate-500 mt-0.5 line-clamp-2">' + escapeHtml(sub) + '</div>' : '';
    return (
      '<a href="/session.php?session=' + encodeURIComponent(s.name) + '" class="block px-4 py-3 active:bg-slate-800">' +
      '<div class="flex items-center justify-between gap-2">' +
      '<span class="text-slate-200 truncate">' + escapeHtml(label) + '</span>' +
      sidebarStatusBadge(s) +
      '</div>' +
      subHtml +
      '</a>'
    );
  }

  function loadSidebarList() {
    sidebarList.innerHTML = '<div class="px-4 py-3 text-slate-500">Loading&hellip;</div>';
    fetch('/sessions_list.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) {
          sidebarList.innerHTML = '<div class="px-4 py-3 text-slate-500">Could not load sessions.</div>';
          return;
        }
        var others = (data.sessions || []).filter(function (s) { return s.name !== sessionName; });
        // Opening the sidebar IS "looking" - clears the green (finished,
        // unseen) dot for anything it's about to display.
        processOtherSessions(others, true);
        if (others.length === 0) {
          sidebarList.innerHTML = '<div class="px-4 py-3 text-slate-500">No other sessions.</div>';
          return;
        }
        sidebarList.innerHTML = others.map(sidebarRowHtml).join('');
      })
      .catch(function () {
        sidebarList.innerHTML = '<div class="px-4 py-3 text-slate-500">Could not load sessions.</div>';
      });
  }

  function openSidebar() {
    sidebarOverlay.classList.remove('hidden');
    sidebar.classList.remove('translate-x-full');
    loadSidebarList();
  }

  function closeSidebar() {
    sidebarOverlay.classList.add('hidden');
    sidebar.classList.add('translate-x-full');
  }

  if (sidebarToggleBtn) {
    sidebarToggleBtn.addEventListener('click', openSidebar);
    sidebarCloseBtn.addEventListener('click', closeSidebar);
    sidebarOverlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeSidebar();
      }
    });
  }

  // --- swipe gestures (touch devices): swipe left anywhere opens the
  // sidebar (it slides in from the right, so this matches "pulling" it
  // into view), swipe right closes it if it's open, else goes back to
  // the dashboard. Ignored for anything that isn't a clearly horizontal
  // gesture, so it doesn't fight with normal vertical scrolling. ---
  if (sidebarToggleBtn) {
    var SWIPE_MIN_DISTANCE_PX = 80;
    var SWIPE_MAX_VERTICAL_RATIO = 0.5;
    var touchStartX = null;
    var touchStartY = null;

    // A non-collapsed selection means this touch is (or might become)
    // dragging a text-selection handle, not swiping - those handles are
    // native OS chrome, not real DOM elements, so there's no element to
    // target-check the way the scrollable-block case below does; checking
    // the selection itself is the only reliable signal. Checked on both
    // touchstart (the selection already exists from an earlier long-press)
    // and touchend (in case it changed mid-touch), since real devices vary
    // in whether those are the same touch sequence or two separate ones.
    function touchTargetsActiveSelection() {
      var selection = window.getSelection();
      return !!selection && !selection.isCollapsed;
    }

    document.addEventListener('touchstart', function (e) {
      // Ignore touches starting inside a horizontally-scrollable command/
      // output block - that gesture is for scrolling the block itself,
      // not for opening/closing the sidebar.
      if (e.touches.length !== 1 || (e.target.closest && e.target.closest('.overflow-x-auto, .overflow-auto')) || touchTargetsActiveSelection()) {
        touchStartX = null;
        touchStartY = null;
        return;
      }

      touchStartX = e.touches[0].clientX;
      touchStartY = e.touches[0].clientY;
    }, { passive: true });

    document.addEventListener('touchend', function (e) {
      if (touchStartX === null || e.changedTouches.length !== 1 || touchTargetsActiveSelection()) {
        touchStartX = null;
        touchStartY = null;
        return;
      }

      var deltaX = e.changedTouches[0].clientX - touchStartX;
      var deltaY = e.changedTouches[0].clientY - touchStartY;
      touchStartX = null;
      touchStartY = null;

      if (Math.abs(deltaX) < SWIPE_MIN_DISTANCE_PX || Math.abs(deltaY) > Math.abs(deltaX) * SWIPE_MAX_VERTICAL_RATIO) {
        return;
      }

      var sidebarOpen = !sidebar.classList.contains('translate-x-full');

      if (deltaX < 0) {
        if (!sidebarOpen) {
          openSidebar();
        }
      } else if (sidebarOpen) {
        closeSidebar();
      } else {
        window.location.href = '/';
      }
    }, { passive: true });
  }

  var ROLE_LABELS = { user: 'User', assistant: 'Assistant', system: 'System' };
  var SCROLL_BOTTOM_THRESHOLD_PX = 80;

  // Polling interval: user-selectable (dropdown in the sticky header, 1/3/5/
  // 10/15s), persisted per-browser. Defaults to 3s.
  var POLL_INTERVAL_STORAGE_KEY = 'csm-poll-interval-ms';
  var POLL_INTERVAL_ALLOWED_MS = [1000, 3000, 5000, 10000, 15000];
  var pollIntervalMs = (function () {
    try {
      var stored = parseInt(window.localStorage.getItem(POLL_INTERVAL_STORAGE_KEY), 10);
      return POLL_INTERVAL_ALLOWED_MS.indexOf(stored) !== -1 ? stored : 3000;
    } catch (e) {
      return 3000;
    }
  })();

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Replaces the old bare "Unexpected response" fallback - reading the raw
  // response text and only then trying to parse it (instead of jumping
  // straight to r.json(), which throws before you ever see the body) means
  // a parse failure can report the actual status code and a body snippet
  // right in the alert/inline error, not just "something went wrong" - no
  // DevTools needed to tell which endpoint failed or why.
  function parseJsonResponse(r, label) {
    return r.text().then(function (text) {
      try {
        return JSON.parse(text);
      } catch (e) {
        return { ok: false, message: 'Unexpected response [' + label + '] (status ' + r.status + '): ' + text.slice(0, 200) };
      }
    });
  }

  // --- scroll-to-bottom: the floating button shows whenever there's more
  // page below the viewport, and new content (polled messages, a
  // freshly-appeared/updated prompt) only auto-scrolls into view if the
  // user was already at the bottom - never yanks them away from history
  // they scrolled up to read. ---

  // window.innerHeight doesn't shrink when the on-screen keyboard opens on
  // iOS Safari - the layout viewport stays full-height while the keyboard
  // visually covers the bottom portion of it, so window.innerHeight +
  // window.scrollY can claim "near bottom" even while the compose bar is
  // actually hidden behind the keyboard. Found live: that false positive
  // is what made maybeAutoScroll() below pull the page back to the
  // (keyboard-covered) bottom on a later poll, with nothing actually typed
  // to explain it. visualViewport tracks the real visible area - its
  // height genuinely shrinks with the keyboard, and pageTop already
  // accounts for scroll (comparable directly to scrollHeight, no separate
  // + scrollY needed) - falls back to the old calculation on anything
  // without visualViewport support.
  function isNearBottom() {
    if (window.visualViewport) {
      return (window.visualViewport.pageTop + window.visualViewport.height) >= (document.documentElement.scrollHeight - SCROLL_BOTTOM_THRESHOLD_PX);
    }

    return (window.innerHeight + window.scrollY) >= (document.documentElement.scrollHeight - SCROLL_BOTTOM_THRESHOLD_PX);
  }

  function scrollToBottom(smooth) {
    window.scrollTo({ top: document.documentElement.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
  }

  function updateGoToBottomVisibility() {
    if (goToBottomBtn) {
      goToBottomBtn.classList.toggle('hidden', isNearBottom());
    }
  }

  function maybeAutoScroll(wasNearBottom) {
    if (wasNearBottom) {
      scrollToBottom(true);
    }

    updateGoToBottomVisibility();
  }

  if (goToBottomBtn) {
    window.addEventListener('scroll', updateGoToBottomVisibility, { passive: true });
    goToBottomBtn.addEventListener('click', function () { scrollToBottom(true); });
  }

  // Mirrors host-agent's relative_time() (see src/lib/AgentClient.php) so
  // a poll-refreshed timestamp reads the same as the server-rendered one.
  function relativeTimeLabel(timestamp) {
    var diff = Math.floor(Date.now() / 1000) - timestamp;

    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + ' min ago';

    if (diff < 86400) {
      var h = Math.floor(diff / 3600);
      return h + ' hr' + (h > 1 ? 's' : '') + ' ago';
    }

    var d = Math.floor(diff / 86400);
    return d + ' day' + (d > 1 ? 's' : '') + ' ago';
  }

  // Mirrors collapsible_summary() in session.php.
  function collapsibleSummary(text) {
    var trimmed = text.trim();
    var firstLine = trimmed.split('\n', 1)[0];
    var summary = firstLine.length > 80 ? firstLine.slice(0, 80) + '…' : firstLine;
    return summary + (trimmed.length > summary.length ? ' …' : '');
  }

  // Mirrors render_collapsible_block() in AgentClient.php - tool commands/
  // output default to collapsed (a <details>, no JS needed to expand/
  // collapse), except trivial content (short, single line - the summary
  // would show it in full anyway), which skips the wrapper entirely.
  function renderCollapsibleBlock(rawText, borderClass, textClass, prefix, forceOpen) {
    var trimmed = rawText.trim();
    var summary = collapsibleSummary(rawText);
    var full = escapeHtml(rawText);

    if (summary === trimmed) {
      return '<div class="rounded border ' + borderClass + ' bg-slate-950/60 overflow-x-auto px-2 py-1.5 text-xs ' + textClass + '"><span class="whitespace-pre">' + prefix + full + '</span></div>';
    }

    var summaryHtml = escapeHtml(summary);

    return '<details' + (forceOpen ? ' open' : '') + ' class="rounded border ' + borderClass + ' bg-slate-950/60">'
      + '<summary class="block w-full text-left cursor-pointer select-none whitespace-pre-wrap break-all px-2 py-1.5 text-xs ' + textClass + '">' + prefix + summaryHtml + '</summary>'
      + '<pre class="whitespace-pre overflow-auto max-h-64 px-2 pb-1.5 text-xs ' + textClass + '">' + full + '</pre>'
      + '</details>';
  }

  // Mirrors render_transcript_image_html() in session.php (PHP).
  function renderImageHtml(image) {
    var mediaType = escapeHtml(image.media_type);
    var data = escapeHtml(image.data);

    return '<img src="data:' + mediaType + ';base64,' + data + '" loading="lazy" alt="Image" class="transcript-image mt-1.5 rounded border border-slate-800 cursor-pointer w-24 h-24 object-cover">';
  }

  function renderBlock(block) {
    var text = escapeHtml(block.text);
    var imageHtml = block.image ? renderImageHtml(block.image) : '';

    // break-words - see render_transcript_block() in session.php (the
    // PHP-side counterpart) for why: a long unbroken token (a constant
    // name, URL, hash, ...) in prose text can otherwise widen the whole
    // page horizontally instead of wrapping.
    switch (block.kind) {
      case 'text':
        return '<p class="whitespace-pre-wrap break-words text-sm text-slate-100">' + text + '</p>';
      case 'tool_use':
        // Collapsed by default regardless of the show/hide-tool-details
        // toggle - it used to force-open when details were hidden (on the
        // theory that there'd be no result to click into for confirmation),
        // but that's backwards from what's wanted: collapsed either way.
        return '<div class="tool-use-block">' + renderCollapsibleBlock(block.text, 'border-sky-800/40', 'text-sky-300', '&rarr; ') + '</div>';
      case 'tool_result':
        // The image (a browser-automation screenshot, most likely) is a
        // SIBLING of .tool-detail, not nested inside it - shown regardless
        // of the show/hide-tool-details toggle, since it's often the
        // whole point of having run the tool in the first place.
        return '<div class="tool-detail">' + renderCollapsibleBlock(block.text, 'border-slate-800', 'text-slate-400', '') + '</div>' + imageHtml;
      case 'image':
        return imageHtml || (text ? '<p class="break-words text-xs text-slate-600">' + text + '</p>' : '');
      default:
        return text ? '<p class="break-words text-xs text-slate-600">' + text + '</p>' : '';
    }
  }

  // Mirrors entry_color_kind()/entry_color_classes() in session.php (the
  // PHP-side counterpart) - "user"/"assistant"/"tool_use"/"tool_result"/
  // "system" is not the same thing as entry.role (a tool_result entry
  // carries role="user" under the hood, same as a real typed message); an
  // entry with no text at all reads as a tool action regardless of its
  // literal role, so it's colored (and labeled - see renderEntry()) as
  // one instead - tool_use and tool_result get their own distinct kinds,
  // not lumped into one "tool" bucket, so a call and its output are never
  // confusable at a glance either.
  function entryColorKind(entry) {
    var blocks = entry.blocks || [];
    var hasText = blocks.some(function (b) { return b.kind === 'text'; });
    var hasToolUse = blocks.some(function (b) { return b.kind === 'tool_use'; });
    var hasToolResult = blocks.some(function (b) { return b.kind === 'tool_result'; });
    var isSubagent = blocks.some(function (b) { return b.agent_type != null; });

    // See entry_color_kind() in session.php (PHP) for why this check comes
    // before the generic tool_use/tool_result one below - a subagent
    // launch/report should read as its own distinct thing, not just
    // another tool call.
    if (!hasText && isSubagent) {
      return hasToolUse ? 'subagent_call' : 'subagent_result';
    }

    if (!hasText && hasToolUse) {
      return 'tool_use';
    }

    if (!hasText && hasToolResult) {
      return 'tool_result';
    }

    if (entry.role === 'assistant' || entry.role === 'user') {
      return entry.role;
    }

    return 'system';
  }

  function entryColorClasses(kind) {
    switch (kind) {
      case 'user':
        // See entry_color_classes() in session.php (PHP) for why this is
        // rose, not indigo/blue - too close to sky (tool_use) otherwise.
        return { border: 'border-rose-800/60', bg: 'bg-rose-950/40', label: 'text-rose-300' };
      case 'assistant':
        return { border: 'border-emerald-800/60', bg: 'bg-emerald-950/40', label: 'text-emerald-300' };
      case 'tool_use':
        return { border: 'border-sky-800/60', bg: 'bg-sky-950/40', label: 'text-sky-300' };
      case 'tool_result':
        return { border: 'border-violet-800/60', bg: 'bg-violet-950/40', label: 'text-violet-300' };
      case 'subagent_call':
      case 'subagent_result':
        return { border: 'border-fuchsia-800/60', bg: 'bg-fuchsia-950/40', label: 'text-fuchsia-300' };
      default:
        return { border: 'border-slate-800', bg: 'bg-slate-900/50', label: 'text-slate-400' };
    }
  }

  function renderEntry(entry) {
    var colorKind = entryColorKind(entry);
    // See entry_color_kind()'s label comment in session.php (PHP) - a
    // tool_use/tool_result entry is labeled "Tool", not its literal
    // user/assistant role, to match how it's actually colored.
    var roleLabel = colorKind === 'tool_use' ? 'Tool call'
      : colorKind === 'tool_result' ? 'Tool output'
      : colorKind === 'subagent_call' ? 'Subagent call'
      : colorKind === 'subagent_result' ? 'Subagent report'
      : (ROLE_LABELS[entry.role] || (entry.role ? escapeHtml(entry.role) : 'System'));
    var parsedMs = entry.timestamp ? Date.parse(entry.timestamp) : NaN;
    var timestamp = !isNaN(parsedMs) ? escapeHtml(relativeTimeLabel(Math.floor(parsedMs / 1000))) : '';
    var blocksHtml = (entry.blocks || []).map(renderBlock).join('');
    var colors = entryColorClasses(colorKind);
    // Hides the WHOLE entry (not just the now-hidden tool_result/tool_use
    // block) once the matching toggle turns off - see the PHP comment in
    // render_transcript_entry() for why, including why an entry carrying
    // an image is excluded either way.
    var hasImage = (entry.blocks || []).some(function (b) { return !!b.image; });
    var extraClass = '';

    if (!hasImage) {
      if (colorKind === 'tool_result' || colorKind === 'subagent_result') {
        extraClass = ' entry-tool-result-only';
      } else if (colorKind === 'tool_use' || colorKind === 'subagent_call') {
        extraClass = ' entry-tool-use-only';
      }
    }

    var div = document.createElement('div');
    div.className = 'rounded-lg border ' + colors.border + ' ' + colors.bg + ' px-3 py-2' + extraClass;
    div.innerHTML = '<div class="mb-1 flex items-center gap-2 text-xs text-slate-500">'
      + '<span class="font-medium ' + colors.label + '">' + roleLabel + '</span>'
      + (timestamp ? '<span>' + timestamp + '</span>' : '')
      + '</div>'
      + '<div class="flex flex-col gap-1.5">' + blocksHtml + '</div>';

    return div;
  }

  // --- optimistic history entries: rendered with renderEntry() itself (so
  // a pending compose message/prompt answer looks exactly like the real
  // thing once confirmed, just dimmed), tracked in pendingEntries so
  // pollHistory() can reconcile them against real incoming data - see
  // reconcilePendingEntries() below for the matching logic. ---

  function pendingEntryText(blocks) {
    var textBlock = (blocks || []).find(function (b) { return b.kind === 'text'; });
    return textBlock ? textBlock.text : '';
  }

  function appendPendingEntry(role, blocks) {
    if (!list) {
      return null;
    }

    var wasNearBottom = isNearBottom();
    var el = renderEntry({ role: role, timestamp: new Date().toISOString(), blocks: blocks });
    el.classList.add('opacity-50');
    el.dataset.pendingRole = role;
    el.dataset.pendingText = pendingEntryText(blocks);

    var pendingNote = document.createElement('span');
    pendingNote.className = 'italic';
    pendingNote.textContent = 'Sending…';
    el.querySelector('.mb-1').appendChild(pendingNote);

    list.appendChild(el);
    pendingEntries.push(el);
    maybeAutoScroll(wasNearBottom);

    return el;
  }

  // Only used to undo a pending entry after a failed send - the success
  // path never calls this directly, pollHistory() reconciles pending
  // entries against real incoming data instead (see there).
  function removePendingEntry(el) {
    if (!el) {
      return;
    }

    if (el.parentNode) {
      el.parentNode.removeChild(el);
    }

    var idx = pendingEntries.indexOf(el);

    if (idx !== -1) {
      pendingEntries.splice(idx, 1);
    }
  }

  // Called by pollHistory() with whatever fresh entries just came back -
  // a pending entry is only cleared once one of them actually matches it
  // (same role + same text), not just because SOME fresh data landed.
  // That matters because a compose send's own confirming line can take a
  // couple of seconds to actually reach the transcript file (measured
  // live - Claude Code's own write latency, nothing this app controls),
  // so an earlier poll can easily see OTHER new content first; clearing
  // every pending entry on any fresh batch made the just-sent message
  // vanish (cleared as if confirmed) without ever actually rendering the
  // real one, since it genuinely wasn't in that batch yet.
  function reconcilePendingEntries(freshEntries) {
    if (pendingEntries.length === 0) {
      return;
    }

    pendingEntries = pendingEntries.filter(function (el) {
      var matched = freshEntries.some(function (entry) {
        return entry.role === el.dataset.pendingRole && pendingEntryText(entry.blocks) === el.dataset.pendingText;
      });

      if (matched && el.parentNode) {
        el.parentNode.removeChild(el);
      }

      return !matched;
    });
  }

  // Mirrors render_session_static_info_html() in session.php - kept
  // alongside renderEntry()/renderBlock() as the JS-side counterpart of
  // the same PHP renderer, both feeding this one visibility-gated poll.
  //
  // Only rebuilds the block when title/name/workdir/attached actually
  // change - same reasoning as renderBlockedSection()'s skip-if-unchanged
  // key, here for a lower-stakes reason (no focus/scroll to protect, just
  // an in-progress text selection inside the box - e.g. copying the
  // session name or workdir path - that a full innerHTML replacement
  // would silently clear on every poll for no reason, since none of this
  // actually changes poll to poll in the common case). The relative-time
  // label is genuinely time-varying though (its DISPLAYED text can change
  // even with no new poll data at all), so it's always updated via its
  // own stable id rather than being covered by the skip.
  function renderStaticInfo(detail) {
    var key = JSON.stringify([detail.title || null, detail.name, detail.workdir || null, !!detail.attached]);

    if (key !== lastRenderedStaticInfoKey) {
      lastRenderedStaticInfoKey = key;

      var html = '<div class="text-base font-medium truncate">' + escapeHtml(detail.title || detail.name) + '</div>'
        + '<div class="font-mono text-xs text-slate-500 truncate mt-0.5">' + escapeHtml(detail.name) + '</div>';

      if (detail.workdir) {
        html += '<div class="text-xs text-slate-500 truncate mt-0.5">' + escapeHtml(detail.workdir) + '</div>';
      }

      html += '<div class="text-xs text-slate-400 mt-1 flex items-center gap-2">'
        + '<span id="static-info-activity"></span>'
        + '<span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span>'
        + (detail.attached ? '<span class="text-emerald-400">attached</span>' : '<span class="text-slate-500">detached</span>')
        + '</div>';

      infoBox.innerHTML = html;

      if (headerTitle) {
        headerTitle.textContent = detail.title || detail.name;
      }
    }

    var activityEl = document.getElementById('static-info-activity');

    if (activityEl) {
      activityEl.textContent = relativeTimeLabel(detail.activity);
    }
  }

  // Mirrors render_thinking_indicator_html() - a single transient "is it
  // doing something right now" signal, never the actual thinking content
  // (that's dropped entirely server-side), and never shown at the same
  // time as the blocked-prompt section.
  //
  // Skips the rebuild when the shown/hidden state hasn't actually changed
  // - same "no-op unless something real changed" pattern as
  // renderBlockedSection(). Found live: rebuilding on every single poll
  // (the previous behavior) tore out and replaced the Stop button on
  // every cycle even while a session just sat "working" poll after poll
  // with nothing new to show - if that landed while a stop click's own
  // fetch was still in flight, the disabled state from the click handler
  // (see the delegated #stop-btn listener below) applied to the OLD,
  // now-detached button; the freshly rebuilt one showed up enabled again
  // mid-request, opening a real double-submit window. Only mattered while
  // working (never while blocked, per the two states' own mutual
  // exclusion above), so the key is just the shown/hidden boolean, not
  // the (static, unchanging) markup itself.
  function renderThinkingIndicator(detail) {
    if (!thinkingIndicator) {
      return;
    }

    var shouldShow = !!detail.working && !detail.blocked_reason;

    if (shouldShow === lastRenderedThinkingShown) {
      return;
    }

    lastRenderedThinkingShown = shouldShow;

    if (!shouldShow) {
      thinkingIndicator.innerHTML = '';
      return;
    }

    thinkingIndicator.innerHTML = '<div class="rounded-lg border border-slate-800 bg-slate-900/50 px-3 py-2 text-xs text-slate-400 flex items-center justify-between gap-2">'
      + '<span class="flex items-center gap-2">'
      + '<span class="flex items-center gap-1">'
      + '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:0ms"></span>'
      + '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:150ms"></span>'
      + '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:300ms"></span>'
      + '</span>'
      + '<span>Thinking&hellip;</span>'
      + '</span>'
      + '<button type="button" id="stop-btn" class="rounded border border-red-800/60 bg-red-950/40 active:bg-red-900/60 text-red-300 text-xs font-medium px-2 py-1">Stop</button>'
      + '</div>';
  }

  // Mirrors render_mode_toggle_html() in session.php - options are static
  // (rendered once server-side), only the selected value/disabled state
  // changes here. Left showing its last known value (just disabled) if
  // the mode becomes unreadable after having been known - not worth a
  // placeholder swap for what's a rare, transient state.
  function renderModeToggle(detail) {
    if (!modeSelect) {
      return;
    }

    modeSelect.disabled = !detail.current_mode;

    if (detail.current_mode) {
      modeSelect.value = detail.current_mode;
    }
  }

  // Mirrors blocked_prompt_rich_html() in AgentClient.php - the JS-side
  // counterpart feeding the same poll. One unified card (question, the
  // pending command collapsed by default, Approve/Deny buttons) - not a
  // separate bubble, which read as something that already happened
  // rather than the thing still waiting on an answer. No attach-tip
  // here: it's only shown where there are no buttons to tap instead (the
  // dashboard's folder-trust rows - see renderStaticInfo() for why this
  // page never needs that fallback). Empties the section when no longer
  // blocked, so an answered prompt disappears without a reload.
  function renderBlockedSection(detail) {
    if (!blockedSection) {
      return;
    }

    // A poll is a no-op here whenever the blocked-prompt data hasn't
    // actually changed from what's already on screen - the common case
    // while a prompt just sits unanswered, poll after poll. Skipping the
    // rebuild entirely (rather than rebuilding every time and carefully
    // trying to restore state afterward) is what actually fixes the whole
    // family of poll-during-interaction bugs found live: lost textarea
    // focus/cursor, the page scrolling back to the top, an expanded
    // command's own scroll position resetting (so only its last lines were
    // visible), a manually-opened <details> snapping shut - none of that
    // needs preserving if the DOM was never touched to begin with.
    var key = JSON.stringify([detail.blocked_reason || null, detail.prompt_context || null, detail.prompt_options || null]);

    if (key === lastRenderedBlockedKey) {
      return;
    }

    lastRenderedBlockedKey = key;

    // The rebuild below can still occasionally happen while the free-text
    // reply box is open mid-draft or the command <details> is manually
    // expanded (typically just the first poll after page load, landing on
    // the same prompt the server already rendered) - preserved as a safety
    // net for that case, same mechanism as before, just rarely exercised now.
    var existingReply = blockedSection.querySelector('.freetext-reply');
    var freetextWasOpen = existingReply && !existingReply.classList.contains('hidden');
    var existingTextarea = existingReply ? existingReply.querySelector('.freetext-reply-textarea') : null;
    var freetextDraft = existingTextarea ? existingTextarea.value : '';
    var freetextOption = existingReply ? existingReply.dataset.option : null;
    var freetextHadFocus = existingTextarea !== null && existingTextarea === document.activeElement;
    var freetextSelectionStart = freetextHadFocus ? existingTextarea.selectionStart : null;
    var freetextSelectionEnd = freetextHadFocus ? existingTextarea.selectionEnd : null;

    var existingContextDetails = blockedSection.querySelector('details');
    var contextDetailsWasOpen = existingContextDetails ? existingContextDetails.open : false;
    var existingPre = existingContextDetails ? existingContextDetails.querySelector('pre') : null;
    var contextScrollTop = existingPre ? existingPre.scrollTop : 0;

    // Page scroll position, restored (if captured) after the rebuild below
    // - only when there was actually something on screen worth not
    // yanking the user away from, never fights normal scrolling otherwise.
    var scrollYBeforeRebuild = (freetextHadFocus || contextDetailsWasOpen) ? window.scrollY : null;

    if (!detail.blocked_reason) {
      blockedSection.innerHTML = '';
      currentBlockedReason = null;
      answerPendingReason = null;
      removePendingEntry(answerPendingHistoryEl);
      answerPendingHistoryEl = null;
      return;
    }

    currentBlockedReason = detail.blocked_reason;

    var html = '<div class="rounded-lg px-3 py-2 text-xs bg-amber-900/40 text-amber-200 border border-amber-700/60">'
      + '<p class="font-medium break-words">Waiting on input: ' + escapeHtml(detail.blocked_reason) + '</p>';

    if (detail.prompt_context) {
      html += '<div class="mt-2">' + renderCollapsibleBlock(detail.prompt_context, 'border-amber-700/40', 'text-amber-100', '') + '</div>';
    }

    if (detail.prompt_options && detail.prompt_options.length) {
      var optionsHtml = '';
      var hasFreeText = false;

      // See blocked_prompt_options_html() in AgentClient.php (PHP) for why
      // - a multi-question AskUserQuestion prompt needs Prev/Next buttons
      // to reach any question besides whichever tab currently happens to
      // be showing.
      if (detail.prompt_multi_question) {
        optionsHtml += '<button type="button" class="nav-prompt-btn rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2" data-direction="left" aria-label="Previous question">&larr;</button>';
      }

      detail.prompt_options.forEach(function (opt) {
        var label = escapeHtml(opt.label);

        if (opt.label.toLowerCase().indexOf('type something') !== -1) {
          hasFreeText = true;
          // break-words + max-w-full - see blocked_prompt_options_html() in
          // AgentClient.php (PHP) for why both are needed together (an
          // option label has no length limit imposed by the tool itself,
          // and break-words alone doesn't help without max-w-full capping
          // the flex item's width first).
          optionsHtml += '<button type="button" class="reveal-freetext-btn rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2 break-words max-w-full" data-option="' + opt.number + '">'
            + opt.number + '. ' + label
            + '</button>';
          return;
        }

        optionsHtml += '<form method="post" action="/answer_prompt.php" data-confirm-label="' + label + '">'
          + '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrfToken) + '">'
          + '<input type="hidden" name="session" value="' + escapeHtml(sessionName) + '">'
          + '<input type="hidden" name="option" value="' + opt.number + '">'
          + '<button type="submit" class="rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2 break-words max-w-full">'
          + opt.number + '. ' + label
          + '</button></form>';
      });

      if (detail.prompt_multi_question) {
        optionsHtml += '<button type="button" class="nav-prompt-btn rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2" data-direction="right" aria-label="Next question">&rarr;</button>';
      }

      html += '<div class="prompt-options-wrapper mt-2" data-session="' + escapeHtml(sessionName) + '" data-csrf-token="' + escapeHtml(csrfToken) + '">'
        + '<div class="flex flex-wrap gap-2">' + optionsHtml + '</div>';

      if (hasFreeText) {
        html += '<div class="freetext-reply hidden mt-2">'
          + '<textarea class="freetext-reply-textarea w-full resize-none rounded-lg bg-slate-800 border border-slate-700 text-base text-slate-100 px-3 py-2" rows="2" placeholder="Type your reply&hellip;"></textarea>'
          + '<button type="button" class="freetext-reply-send-btn mt-1 rounded-lg bg-indigo-600 active:bg-indigo-700 text-white text-xs font-medium px-3 py-1.5">Send</button>'
          + '</div>';
      }

      html += '</div>';
    }

    html += '</div>';
    blockedSection.innerHTML = html;

    if (freetextWasOpen) {
      var newReply = blockedSection.querySelector('.freetext-reply');

      if (newReply) {
        newReply.classList.remove('hidden');
        newReply.dataset.option = freetextOption;
        var newTextarea = newReply.querySelector('.freetext-reply-textarea');
        newTextarea.value = freetextDraft;

        if (freetextHadFocus) {
          newTextarea.focus();
          newTextarea.setSelectionRange(freetextSelectionStart, freetextSelectionEnd);
        }
      }
    }

    if (contextDetailsWasOpen) {
      var newContextDetails = blockedSection.querySelector('details');

      if (newContextDetails) {
        newContextDetails.open = true;
        var newPre = newContextDetails.querySelector('pre');

        if (newPre) {
          newPre.scrollTop = contextScrollTop;
        }
      }
    }

    if (scrollYBeforeRebuild !== null) {
      // Applied a frame later, after the browser's own focus/reflow-driven
      // scroll-into-view (if any) has already happened, so this is the
      // last word rather than getting immediately overridden by it.
      requestAnimationFrame(function () {
        window.scrollTo(0, scrollYBeforeRebuild);
      });
    }

    // A poll can land mid-flight, between an answer being submitted and the
    // next poll actually seeing it land - since this rebuilds the section
    // from scratch every time, that would otherwise silently drop the
    // "answered, waiting to confirm" dimming the instant a same-prompt poll
    // comes back. Reapplied here as long as the SAME prompt is still
    // showing; the moment it changes or clears, the answer really did land
    // (or a new prompt replaced it), so there's nothing left to reapply.
    if (answerPendingReason !== null && answerPendingReason === currentBlockedReason) {
      markBlockedSectionAnswerPending();
    } else {
      answerPendingReason = null;
      removePendingEntry(answerPendingHistoryEl);
      answerPendingHistoryEl = null;
    }
  }

  // Dims the blocked-prompt card and disables everything in it, right after
  // an answer is submitted (and reapplied by renderBlockedSection() above if
  // a poll rebuilds the same still-pending prompt before confirmation
  // arrives). revertBlockedSectionAnswerPending() undoes this on a failed
  // send; a successful one needs no explicit revert - the card either gets
  // replaced (new/no prompt) or re-dimmed by the check above on the next
  // rebuild, either way never left stale.
  function markBlockedSectionAnswerPending() {
    var card = blockedSection.firstElementChild;

    if (card) {
      card.classList.add('opacity-50');

      if (!card.querySelector('.answer-pending-note')) {
        var note = document.createElement('p');
        note.className = 'answer-pending-note mt-2 text-amber-300/70 italic';
        note.textContent = 'Answered - waiting to confirm…';
        card.appendChild(note);
      }
    }

    blockedSection.querySelectorAll('button, textarea').forEach(function (el) { el.disabled = true; });
  }

  function revertBlockedSectionAnswerPending() {
    var card = blockedSection.firstElementChild;

    if (card) {
      card.classList.remove('opacity-50');
      var note = card.querySelector('.answer-pending-note');

      if (note) {
        note.remove();
      }
    }

    blockedSection.querySelectorAll('button, textarea').forEach(function (el) { el.disabled = false; });
  }

  // Event delegation, not per-form listeners: covers both the
  // PHP-rendered forms on first paint and any poll-rebuilt ones, without
  // needing to re-attach anything after renderBlockedSection() replaces
  // the DOM. AJAX, not a real form submission - answering a prompt is
  // common enough that a full page reload per answer would be poor UX
  // (same reasoning as compose send).
  if (blockedSection) {
    blockedSection.addEventListener('submit', function (e) {
      var form = e.target.closest('form[data-confirm-label]');

      if (!form) {
        return;
      }

      e.preventDefault();

      if (shouldConfirmBeforeAnswer() && !confirm('Send "' + form.dataset.confirmLabel + '" to this session?')) {
        return;
      }

      answerPendingReason = currentBlockedReason;
      markBlockedSectionAnswerPending();
      var pendingEl = appendPendingEntry('user', [{ kind: 'text', text: form.dataset.confirmLabel }]);
      answerPendingHistoryEl = pendingEl;

      fetch('/answer_prompt.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(new FormData(form)).toString()
      })
        .then(function (r) { return parseJsonResponse(r, 'answer-prompt'); })
        .then(function (data) {
          if (data && data.ok) {
            // The request only waits for the keys to be *sent*, not for
            // Claude Code to actually process them and redraw past the
            // prompt - polling immediately can still catch the old,
            // now-stale blocked state (found live: the prompt appeared
            // "stuck" until the next regular poll tick, up to the full
            // interval later). Same fix as the mode-select's redraw wait.
            setTimeout(pollOnce, 300);
          } else {
            alert((data && data.message) || 'Failed to send answer.');
            answerPendingReason = null;
            answerPendingHistoryEl = null;
            removePendingEntry(pendingEl);
            revertBlockedSectionAnswerPending();
          }
        })
        .catch(function () {
          alert('Network error - answer not sent.');
          answerPendingReason = null;
          answerPendingHistoryEl = null;
          removePendingEntry(pendingEl);
          revertBlockedSectionAnswerPending();
        });
    });
  }

  // --- free-text reply (the "Type something." option) - revealing the
  // textarea is its own deliberate step, so unlike the plain option
  // buttons above, sending it skips the confirm() dialog. ---
  if (blockedSection) {
    function submitFreetextReply(replyDiv) {
      var wrapper = replyDiv.closest('.prompt-options-wrapper');
      var textarea = replyDiv.querySelector('.freetext-reply-textarea');
      var sendBtn = replyDiv.querySelector('.freetext-reply-send-btn');
      var text = textarea.value;

      if (text.trim() === '') {
        return;
      }

      textarea.disabled = true;
      sendBtn.disabled = true;

      answerPendingReason = currentBlockedReason;
      markBlockedSectionAnswerPending();
      var pendingEl = appendPendingEntry('user', [{ kind: 'text', text: text }]);
      answerPendingHistoryEl = pendingEl;

      fetch('/answer_prompt.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          session: wrapper.dataset.session,
          csrf_token: wrapper.dataset.csrfToken,
          option: replyDiv.dataset.option,
          text: text
        }).toString()
      })
        .then(function (r) { return parseJsonResponse(r, 'answer-prompt-freetext'); })
        .then(function (data) {
          if (data && data.ok) {
            // See the plain-option handler above for why this waits a beat
            // before polling instead of polling immediately.
            setTimeout(pollOnce, 300);
          } else {
            alert((data && data.message) || 'Failed to send reply.');
            textarea.disabled = false;
            sendBtn.disabled = false;
            answerPendingReason = null;
            answerPendingHistoryEl = null;
            removePendingEntry(pendingEl);
            revertBlockedSectionAnswerPending();
          }
        })
        .catch(function () {
          alert('Network error - reply not sent.');
          textarea.disabled = false;
          sendBtn.disabled = false;
          answerPendingReason = null;
          answerPendingHistoryEl = null;
          removePendingEntry(pendingEl);
          revertBlockedSectionAnswerPending();
        });
    }

    blockedSection.addEventListener('click', function (e) {
      var revealBtn = e.target.closest('.reveal-freetext-btn');

      if (revealBtn) {
        var replyDiv = revealBtn.closest('.prompt-options-wrapper').querySelector('.freetext-reply');
        replyDiv.dataset.option = revealBtn.dataset.option;
        replyDiv.classList.toggle('hidden');

        if (!replyDiv.classList.contains('hidden')) {
          replyDiv.querySelector('.freetext-reply-textarea').focus();
        }

        return;
      }

      var sendBtn = e.target.closest('.freetext-reply-send-btn');

      if (sendBtn) {
        submitFreetextReply(sendBtn.closest('.freetext-reply'));
        return;
      }

      var navBtn = e.target.closest('.nav-prompt-btn');

      if (navBtn) {
        var navWrapper = navBtn.closest('.prompt-options-wrapper');
        navBtn.disabled = true;

        fetch('/session_navigate.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            session: navWrapper.dataset.session,
            csrf_token: navWrapper.dataset.csrfToken,
            direction: navBtn.dataset.direction
          }).toString()
        })
          .then(function (r) { return parseJsonResponse(r, 'navigate-prompt'); })
          .then(function (data) {
            navBtn.disabled = false;

            if (!data || !data.ok) {
              alert((data && data.message) || 'Failed to navigate to the other question.');
              return;
            }

            // The pane state has moved to the other tab, but this card
            // still shows the one just left - forces the very next poll
            // to actually rebuild instead of skipping as "unchanged" (see
            // the key comparison above), so the new tab's question/options
            // show up on the next cycle rather than waiting for something
            // else to invalidate the cache first.
            lastRenderedBlockedKey = undefined;
          })
          .catch(function () {
            navBtn.disabled = false;
            alert('Network error - could not navigate to the other question.');
          });
      }
    });

    // Plain Enter inserts a newline (the browser's own default - no
    // handling needed here); only Shift+Enter submits, same convention as
    // the compose box.
    blockedSection.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.shiftKey && e.target.classList.contains('freetext-reply-textarea')) {
        e.preventDefault();
        submitFreetextReply(e.target.closest('.freetext-reply'));
      }
    });
  }

  function loadMore() {
    var before = btn.dataset.before;

    btn.disabled = true;
    btn.textContent = 'Loading…';

    var url = '/session_history.php?session=' + encodeURIComponent(sessionName) + '&limit=30'
      + (before ? '&before=' + encodeURIComponent(before) : '');

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          btn.textContent = (data && data.message) || 'Could not load more.';
          return;
        }

        var fragment = document.createDocumentFragment();
        (data.entries || []).forEach(function (entry) { fragment.appendChild(renderEntry(entry)); });
        list.insertBefore(fragment, list.firstChild);

        if (data.has_more && data.next_before !== null) {
          btn.dataset.before = data.next_before;
          btn.disabled = false;
          btn.textContent = 'Load older messages';
        } else {
          btn.classList.add('hidden');
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Network error - try again';
      });
  }

  if (btn) {
    btn.addEventListener('click', loadMore);
  }

  // --- visibility-gated polling: refreshes the info/blocked-prompt panel
  // and appends any new messages, but only while this tab is the visible,
  // foregrounded one - cleared on hidden, restarted (with an immediate
  // refresh) on visible, so a background tab doesn't keep hitting the
  // socket for nobody. ---
  var pollTimer = null; // pending setTimeout ID for the next cycle, or null while a cycle's own requests are in flight (nothing pending to clear right then)
  var pollingActive = false; // whether polling should keep going - distinct from pollTimer, which is null during a cycle's in-flight window
  var pollAbortController = new AbortController(); // reset in startPolling() each time polling (re)starts, so a lingering abort from a previous stop can't affect a fresh one

  function pollInfo(wasNearBottom) {
    return fetch('/session_detail.php?session=' + encodeURIComponent(sessionName), { credentials: 'same-origin', signal: pollAbortController.signal })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) {
          renderStaticInfo(data);
          renderThinkingIndicator(data);
          renderModeToggle(data);
          renderBlockedSection(data);
          renderComposeVisibility(data);
          maybeAutoScroll(wasNearBottom);
        }
      })
      .catch(function () {});
  }

  // Minimum time the "New" divider/highlight must actually be on screen
  // before it starts fading - without this, a poll that lands while the
  // user is already scrolled to the bottom (the common case, since
  // maybeAutoScroll() keeps them there) would have the divider intersect
  // the viewport and start fading the instant it's inserted, defeating
  // the entire point of marking it as new.
  var NEW_CONTENT_VISIBLE_DELAY_MS = 2500;

  // Must match the .new-content-highlight.fading box-shadow transition
  // duration in <style> above - the delay before the highlight classes are
  // actually removed from the DOM, so cleanup happens after the fade is
  // visually complete rather than cutting it off mid-animation.
  var NEW_CONTENT_HIGHLIGHT_FADE_MS = 1200;

  // Removes both markers from the previous "new" batch (if any) - the
  // divider is removed from the DOM outright (purely decorative), each
  // entry just loses its highlight ring (real content, stays put).
  function clearNewContentMarkers() {
    if (newContentDivider && newContentDivider.parentNode) {
      newContentDivider.parentNode.removeChild(newContentDivider);
    }
    newContentDivider = null;

    newContentHighlighted.forEach(function (el) { el.classList.remove('new-content-highlight'); });
    newContentHighlighted = [];
  }

  // Marks entries fresh off this poll cycle: a "New" divider above the
  // batch plus a highlight ring on each entry in it, so it's obvious what
  // just arrived without having to spot it by eye in a long list. Clears
  // the previous cycle's markers first - only ever one batch marked at a
  // time. $beforeNode and every element in $entryElements must already be
  // attached to `list` (not a detached DocumentFragment) - the
  // IntersectionObserver below only fires once the divider is actually
  // connected to the document, and inserting into a fragment first would
  // leave a window where "attached but not yet observed" could miss the
  // very first paint.
  function markNewContent(beforeNode, entryElements) {
    clearNewContentMarkers();

    var divider = document.createElement('div');
    divider.className = 'new-content-divider flex items-center gap-2 my-1 text-xs text-indigo-400';
    divider.innerHTML = '<span class="flex-1 border-t border-indigo-500/50"></span>'
      + '<span>New</span>'
      + '<span class="flex-1 border-t border-indigo-500/50"></span>';
    list.insertBefore(divider, beforeNode);
    newContentDivider = divider;

    entryElements.forEach(function (el) { el.classList.add('new-content-highlight'); });
    newContentHighlighted = entryElements;

    if (typeof IntersectionObserver === 'undefined') {
      return; // no observer support - markers just stay put, harmless
    }

    var observer = new IntersectionObserver(function (observerEntries) {
      observerEntries.forEach(function (observerEntry) {
        if (!observerEntry.isIntersecting) {
          return;
        }

        observer.disconnect();

        setTimeout(function () {
          if (newContentDivider === divider) {
            divider.classList.add('fading');
            divider.addEventListener('transitionend', function () {
              if (divider.parentNode) {
                divider.parentNode.removeChild(divider);
              }
              if (newContentDivider === divider) {
                newContentDivider = null;
              }
            }, { once: true });
          }

          if (newContentHighlighted === entryElements) {
            // .fading (not a straight classList.remove('new-content-highlight'))
            // so `transition` stays on the element for the whole animation -
            // removing the base class immediately would strip `transition`
            // at the same instant as `box-shadow`, snapping the ring off
            // instead of fading it. Full cleanup (both classes) happens
            // after the animation's own duration, not tied to transitionend
            // per-element (cheaper for a whole batch, and box-shadow's
            // computed value is already fully faded out by then either way).
            entryElements.forEach(function (el) { el.classList.add('fading'); });
            setTimeout(function () {
              entryElements.forEach(function (el) { el.classList.remove('new-content-highlight', 'fading'); });
            }, NEW_CONTENT_HIGHLIGHT_FADE_MS);
            newContentHighlighted = [];
          }
        }, NEW_CONTENT_VISIBLE_DELAY_MS);
      });
    });

    observer.observe(divider);
  }

  function pollHistory(wasNearBottom) {
    if (!list) {
      return Promise.resolve(); // no transcript for this session - nothing to append to
    }

    return fetch('/session_history.php?session=' + encodeURIComponent(sessionName) + '&limit=50', { credentials: 'same-origin', signal: pollAbortController.signal })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          return;
        }

        var fresh = (data.entries || []).filter(function (entry) {
          return newestLine === null || entry.line > newestLine;
        });

        if (fresh.length === 0) {
          return;
        }

        reconcilePendingEntries(fresh);

        var fragment = document.createDocumentFragment();
        var newEntryElements = [];
        fresh.forEach(function (entry) {
          var el = renderEntry(entry);
          newEntryElements.push(el);
          fragment.appendChild(el);
          newestLine = entry.line;
        });
        var firstNewEntry = fragment.firstChild;
        list.appendChild(fragment);
        if (firstNewEntry) {
          markNewContent(firstNewEntry, newEntryElements);
        }
        maybeAutoScroll(wasNearBottom);
      })
      .catch(function () {});
  }

  // Self-rescheduling (setTimeout, not setInterval) rather than a fixed
  // tick: the next cycle is only queued once this one's requests have all
  // actually come back, so a slow response (or a fast interval like the
  // 1s option) can never pile up overlapping in-flight requests - each
  // cycle waits its full interval AFTER the previous one finishes, not on
  // a fixed clock regardless of how long that previous one took.
  function pollOnce() {
    // Captured once, synchronously, before either fetch fires - both
    // independent responses use this same snapshot so a poll cycle either
    // scrolls once (if the user was at the bottom when it started) or not
    // at all, never a half-scrolled-then-not gap.
    var wasNearBottom = isNearBottom();

    return Promise.all([
      pollInfo(wasNearBottom),
      pollHistory(wasNearBottom),
      refreshSidebarNotification()
    ]);
  }

  function startPolling() {
    if (pollingActive) {
      return;
    }

    pollingActive = true;
    pollAbortController = new AbortController();

    function cycle() {
      pollOnce().finally(function () {
        if (pollingActive) {
          pollTimer = setTimeout(cycle, pollIntervalMs);
        }
      });
    }

    cycle();
  }

  function stopPolling() {
    if (!pollingActive) {
      return;
    }

    pollingActive = false;

    if (pollTimer !== null) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }

    pollAbortController.abort();
  }

  // Belt and suspenders on top of stopPolling()'s abort (which only fires
  // for an explicit hidden/switch-away while this script is still alive) -
  // guarantees any poll mid-flight is cancelled the instant the browser
  // actually tears the page down, e.g. navigating to a different session
  // via the sidebar.
  window.addEventListener('pagehide', function () {
    pollAbortController.abort();
  });

  // Changing the interval mid-poll restarts the timer at the new rate
  // immediately, rather than waiting out whatever was left of the old one.
  var pollIntervalSelect = document.getElementById('poll-interval-select');

  if (pollIntervalSelect) {
    pollIntervalSelect.value = String(pollIntervalMs);

    pollIntervalSelect.addEventListener('change', function () {
      var chosen = parseInt(pollIntervalSelect.value, 10);

      if (POLL_INTERVAL_ALLOWED_MS.indexOf(chosen) === -1) {
        return;
      }

      pollIntervalMs = chosen;

      try {
        window.localStorage.setItem(POLL_INTERVAL_STORAGE_KEY, String(chosen));
      } catch (e) {}

      var wasPolling = pollTimer !== null;
      stopPolling();

      if (wasPolling) {
        startPolling();
      }
    });
  }

  // --- message compose bar: sends free text to the session at any time,
  // same as attaching and typing - see send_message() in Sessions.php for
  // why a tmux paste-buffer is used instead of send-keys with the raw
  // text. AJAX, not a page reload per send (unlike Kill/Approve, which
  // are rare enough that a reload is fine) - this is the primary,
  // repeated interaction the compose box exists for. ---
  var composeTextarea = document.getElementById('compose-textarea');
  var composeSendBtn = document.getElementById('compose-send-btn');
  var composeStatus = document.getElementById('compose-status');

  if (composeTextarea && composeSendBtn) {
    var COMPOSE_MAX_HEIGHT_PX = 128; // matches max-h-32
    var COMPOSE_DRAFT_KEY = 'csm-compose-draft-' + sessionName;

    function autoGrowCompose() {
      composeTextarea.style.height = 'auto';
      composeTextarea.style.height = Math.min(composeTextarea.scrollHeight, COMPOSE_MAX_HEIGHT_PX) + 'px';
    }

    // Per-session draft, so it survives navigating to the dashboard or
    // switching sessions via the sidebar and coming back - lost otherwise,
    // since the textarea itself doesn't persist across a page load.
    function saveComposeDraft() {
      try {
        if (composeTextarea.value) {
          window.localStorage.setItem(COMPOSE_DRAFT_KEY, composeTextarea.value);
        } else {
          window.localStorage.removeItem(COMPOSE_DRAFT_KEY);
        }
      } catch (e) {
        // Private browsing / storage disabled - draft just isn't persisted.
      }
    }

    function clearComposeDraft() {
      try {
        window.localStorage.removeItem(COMPOSE_DRAFT_KEY);
      } catch (e) {}
    }

    try {
      var savedDraft = window.localStorage.getItem(COMPOSE_DRAFT_KEY);

      if (savedDraft) {
        composeTextarea.value = savedDraft;
        autoGrowCompose();
      }
    } catch (e) {}

    function setComposeStatus(text) {
      if (text) {
        composeStatus.textContent = text;
        composeStatus.classList.remove('hidden');
      } else {
        composeStatus.textContent = '';
        composeStatus.classList.add('hidden');
      }
    }

    function sendComposedMessage() {
      var text = composeTextarea.value;

      if (text.trim() === '') {
        return;
      }

      composeTextarea.disabled = true;
      composeSendBtn.disabled = true;
      setComposeStatus('');

      var pendingEl = appendPendingEntry('user', [{ kind: 'text', text: text }]);

      fetch('/session_send.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken, message: text }).toString()
      })
        .then(function (r) { return parseJsonResponse(r, 'compose-send'); })
        .then(function (data) {
          if (data && data.ok) {
            composeTextarea.value = '';
            autoGrowCompose();
            clearComposeDraft();
            pollOnce(); // pick up the new message (and whatever happens next) right away, not on the next 15s tick
          } else {
            removePendingEntry(pendingEl);
            setComposeStatus((data && data.message) || 'Failed to send message.');
          }
        })
        .catch(function () {
          removePendingEntry(pendingEl);
          setComposeStatus('Network error - message not sent.');
        })
        .finally(function () {
          composeTextarea.disabled = false;
          composeSendBtn.disabled = false;
          composeTextarea.focus();
        });
    }

    composeTextarea.addEventListener('input', function () {
      autoGrowCompose();
      saveComposeDraft();
    });
    composeSendBtn.addEventListener('click', sendComposedMessage);

    // Plain Enter inserts a newline (the browser's own default - no
    // handling needed here); only Shift+Enter submits. The opposite of the
    // usual chat-box convention, deliberately: multi-line messages are
    // common enough here (pasted logs/commands) that submit-on-Enter kept
    // firing mid-paste/mid-thought.
    composeTextarea.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.shiftKey) {
        e.preventDefault();
        sendComposedMessage();
      }
    });
  }

  // --- mode select: jumps directly to the chosen mode (set_mode() in
  // Sessions.php works out the Shift+Tab steps and sends them, spaced
  // 300ms apart - verified live that back-to-back presses with no gap
  // get dropped). The request blocks until every press is sent, so by
  // the time it resolves the mode has already changed - the extra 300ms
  // below is just for Claude Code's last status-line redraw to land
  // before polling re-reads it. Disabled for the same window so a second
  // change can't race the first. ---
  var modeSelect = document.getElementById('mode-select');

  if (modeSelect) {
    modeSelect.addEventListener('change', function () {
      var chosenMode = modeSelect.value;
      modeSelect.disabled = true;

      fetch('/session_mode.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken, mode: chosenMode }).toString()
      })
        .then(function () {
          setTimeout(pollOnce, 300);
        })
        .catch(function () {
          modeSelect.disabled = false;
        });
    });
  }

  // --- transcript images: start as a small square thumbnail (see
  // renderImageHtml()/render_transcript_image_html()), tapping toggles to
  // full size and back - not a separate lightbox/modal, just swapping the
  // sizing classes on the same <img> in place. ---
  var TRANSCRIPT_IMAGE_THUMB_CLASSES = ['w-24', 'h-24', 'object-cover'];
  var TRANSCRIPT_IMAGE_FULL_CLASSES = ['w-full', 'h-auto', 'object-contain'];

  document.addEventListener('click', function (e) {
    var img = e.target.closest('.transcript-image');

    if (!img) {
      return;
    }

    var isThumbnail = img.classList.contains('w-24');
    img.classList.remove.apply(img.classList, isThumbnail ? TRANSCRIPT_IMAGE_THUMB_CLASSES : TRANSCRIPT_IMAGE_FULL_CLASSES);
    img.classList.add.apply(img.classList, isThumbnail ? TRANSCRIPT_IMAGE_FULL_CLASSES : TRANSCRIPT_IMAGE_THUMB_CLASSES);
  });

  // --- collapsible tool_use/tool_result blocks: tapping anywhere on one
  // toggles it, collapsed OR expanded (including inside the expanded
  // <pre>), not just the exact summary text/marker - a real mobile
  // annoyance otherwise (small precise tap target to collapse a long
  // command/output back down). The <summary>'s own native click-to-toggle
  // already handles the collapsed case (helped along by the block/w-full
  // class on it, so the whole row is a real tap target, not just wherever
  // the text glyphs render); this delegated handler is the backstop for
  // the rest of the <details> box, expanded content included. Two things
  // it must never do: double-toggle a tap that landed on <summary> itself
  // (native behavior already fired), or collapse out from under an active
  // text selection (a plain click event doesn't fire for a scroll-drag
  // gesture to begin with, so normal scrolling/reading is unaffected
  // either way - this guard is specifically for "tap elsewhere to dismiss
  // a selection", not scrolling). ---
  document.addEventListener('click', function (e) {
    if (e.target.closest('summary')) {
      return;
    }

    var details = e.target.closest('.tool-use-block details, .tool-detail details');

    if (!details) {
      return;
    }

    var selection = window.getSelection();

    if (selection && !selection.isCollapsed) {
      return;
    }

    details.open = !details.open;
  });

  // --- stop button: interrupts Claude mid-response (sends Escape, same
  // as pressing it while attached). Delegated at the document level, not
  // attached directly to the button, since renderThinkingIndicator()
  // recreates it (or removes it entirely) on every poll. ---
  document.addEventListener('click', function (e) {
    var stopBtn = e.target.closest('#stop-btn');

    if (!stopBtn) {
      return;
    }

    stopBtn.disabled = true;

    fetch('/session_escape.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken }).toString()
    })
      .then(function (r) { return parseJsonResponse(r, 'session-escape'); })
      .then(function (data) {
        if (!data || !data.ok) {
          alert((data && data.message) || 'Failed to stop.');
        }

        setTimeout(pollOnce, 300);
      })
      .catch(function () {
        alert('Network error - stop not sent.');
      })
      .finally(function () {
        stopBtn.disabled = false;
      });
  });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      startPolling();
    } else {
      stopPolling();
    }
  });

  // Land at the bottom on open - the current/latest activity (and any
  // pending prompt) is what matters first, same as any chat app.
  scrollToBottom(false);
  updateGoToBottomVisibility();

  if (document.visibilityState === 'visible') {
    startPolling();
  }
})();
</script>
</body>
</html>
