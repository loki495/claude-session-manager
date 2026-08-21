<?php
$this->layout('layout', [
    'title' => $found ? (string)($detail['title'] ?? $detail['name']) : 'Claude Session Manager',
    'viewportContent' => 'width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=5, viewport-fit=cover',
    'fixedShell' => true,
]);
?>
<?php $this->start('style') ?>
<style>
  /* Toggled via the single "Show subagent calls and outputs" sidebar
     setting - a body-level class + CSS rule so it applies uniformly to
     blocks rendered later by the poll too, without needing to walk/re-tag
     the DOM on every render. Regular (non-subagent) tool_use/tool_result
     entries don't use this at all any more (see render_transcript_entries_
     html() in TranscriptView.php) - since 2026-08-08 those are always
     grouped into a collapsible "N tool calls" run instead, whose own
     <details> is its own show/hide affordance, scoped to just that run
     rather than the whole session.
     Hidden by DEFAULT, revealed only once body.show-subagent is added -
     the opposite of the naive "visible by default, hidden by a body.hide-
     subagent class" version this replaced (x-cloak-style fix, found live
     2026-08-08). The stored preference lives in localStorage, which PHP
     can't see at render time - session.js only adds/removes this class
     AFTER its own script runs, near the end of the page, well after first
     paint. The old hide-* version could therefore only ever hide subagent
     content a moment too late for anyone who'd actually turned the toggle
     off: it rendered visible by default and js had to catch up, a real
     flash of real content. Defaulting to hidden and revealing it once
     script confirms the (usually "on") preference means the flash - if
     any - is the far less jarring "briefly nothing, then it pops in" for
     the common case, never "here's real content, oops, hide it". */
  .subagent-detail,
  .subagent-use-block,
  .entry-subagent-only { display: none; }
  body.show-subagent .subagent-detail,
  body.show-subagent .subagent-use-block,
  body.show-subagent .entry-subagent-only { display: block; }
  /* Marks where newly-polled entries start (see markNewContent() in the
     <script> below) - opacity transition only, no layout-affecting
     property, so the fade-out never causes a scroll jump right as the user
     is looking at it. */
  .new-content-divider { opacity: 1; transition: opacity 0.8s ease-out; }
  .new-content-divider.fading { opacity: 0; }
  /* Highlights the actual new entry bubbles, not just the divider above
     them - a box-shadow glow rather than a background tint, so it doesn't
     fight with each entry's own role-color background/border (see
     entry_color_classes()). Three stacked shadows at decreasing alpha/
     increasing spread fake a radial gradient falloff (100% down to 0%
     alpha) - CSS box-shadow has no literal multi-stop gradient, this is
     the practical equivalent (picked over a single blurred shadow after
     a live side-by-side comparison). Same two-class fade pattern as
     .new-content-divider above and for the same reason: the `transition`
     property has to stay on the element for the whole fade, so toggling
     .fading (which zeroes every layer's alpha) is what animates, rather
     than removing .new-content-highlight itself mid-fade - that would
     strip `transition` at the same instant as `box-shadow`, snapping it
     off instead of fading (caught live: the ring vanished with no
     animation at all before this fix). */
  .new-content-highlight {
    transition: box-shadow 1.2s ease-out;
  }
  .new-content-highlight.fading {
    box-shadow: 0 0 0 1px rgba(251, 191, 36, 0), 0 0 8px 4px rgba(251, 191, 36, 0), 0 0 16px 8px rgba(251, 191, 36, 0);
  }
  /* A free-flowing assistant entry (see entry_wrapper_class()/entry-free-
     flowing) has no padding of its own, so the plain .new-content-highlight
     ring above sits flush against the text with no room to breathe (found
     live 2026-08-08).
     Two things were tried and rejected before this, both confirmed live
     against the real running app:
     - Widening box-shadow's spread: doesn't work AT ALL - a shadow's
       visible edge always starts exactly at the box's own border edge no
       matter how large its spread is; spread only changes how far OUT it
       reaches, not whether it touches. Confirmed live: widening it just
       made the ring thicker, still flush against the text.
     - outline + outline-offset: DOES create real separation (outline never
       affects box size/layout, unlike padding - confirmed live that a flex
       `gap`-based sibling distance was unchanged by it, unlike the
       equivalent padding+negative-margin trick, which visibly ate into
       that gap). But a crisp offset outline reads as a hard rectangular
       BOX suddenly appearing around the text (confirmed live via
       screenshot) - defeating the entire point of "free-flowing, no
       border/box" in the first place.
     What actually works: a ::before pseudo-element, inset OUTSIDE the real
     text via a negative `inset`, carrying the soft blurred box-shadow
     layers itself. Since the pseudo-element's own phantom box already
     starts several pixels away from the text, ITS box-shadow reads as a
     soft glow with genuine breathing room around the real text -
     `position: absolute` pulls it completely out of normal flow, so (like
     outline) it has zero effect on the real entry's size or its flex `gap`
     spacing to neighbors either. A real inset (-12px, bigger than the
     first attempt's -6px) plus near-zero spread (relying on blur radius
     alone for the glow's spatial extent, not spread's hard-edged band) is
     what actually reads as a diffuse ambient halo rather than a
     soft-edged-but-still-rectangular box - confirmed live via a zoomed
     screenshot crop, after an initial smaller-inset/higher-spread attempt
     still looked too close to a box at normal viewing size on a real
     phone. */
  .new-content-highlight.entry-free-flowing {
    position: relative;
  }
  .new-content-highlight.entry-free-flowing::before {
    content: '';
    position: absolute;
    margin: 5px;
    inset: -12px;
    border-radius: 0.625rem;
    box-shadow: 0 0 12px 0px rgba(251, 191, 36, 0.5), 0 0 24px 4px rgba(251, 191, 36, 0.2);
    transition: box-shadow 1.2s ease-out;
    pointer-events: none;
  }
  .new-content-highlight.entry-free-flowing.fading::before {
    box-shadow: 0 0 12px 0px rgba(251, 191, 36, 0), 0 0 24px 4px rgba(251, 191, 36, 0);
  }
  /* Landing on a search result (see highlightJumpTarget() in the <script>
     below) - a distinct green ring/glow, on purpose, so it doesn't read as
     "new message" (the amber ring above) when what actually happened is
     "you searched and jumped here" (Andres's own ask, 2026-08-20). Same
     box-shadow/fade shape as .new-content-highlight, just a different
     color and lit immediately (no NEW_CONTENT_VISIBLE_DELAY_MS wait -
     there's no risk of an intersection-observer race here, the scroll
     that lands on this target already happened by the time this class is
     added). */
  .jump-target-highlight {
    box-shadow: 0 0 0 1px rgba(52, 211, 153, 0.8), 0 0 8px 4px rgba(52, 211, 153, 0.5), 0 0 16px 8px rgba(52, 211, 153, 0.25);
    transition: box-shadow 1.2s ease-out;
  }
  .jump-target-highlight.fading {
    box-shadow: 0 0 0 1px rgba(52, 211, 153, 0), 0 0 8px 4px rgba(52, 211, 153, 0), 0 0 16px 8px rgba(52, 211, 153, 0);
  }
  .jump-target-highlight.entry-free-flowing {
    position: relative;
  }
  .jump-target-highlight.entry-free-flowing::before {
    content: '';
    position: absolute;
    margin: 5px;
    inset: -12px;
    border-radius: 0.625rem;
    box-shadow: 0 0 12px 0px rgba(52, 211, 153, 0.5), 0 0 24px 4px rgba(52, 211, 153, 0.2);
    transition: box-shadow 1.2s ease-out;
    pointer-events: none;
  }
  .jump-target-highlight.entry-free-flowing.fading::before {
    box-shadow: 0 0 12px 0px rgba(52, 211, 153, 0), 0 0 24px 4px rgba(52, 211, 153, 0);
  }
</style>
<?php $this->stop() ?>

<?php include __DIR__ . '/../sidebar.php'; ?>

<!-- #app-shell: a full-height flex column so #page-content can be the
     ONLY scrolling element on this page, instead of the whole body -
     needed to fix #compose-bar's position:fixed detaching mid-scroll on
     iOS Safari (see its own comment). #sidebar/#go-to-bottom-btn/the two
     layout.php modals deliberately stay OUTSIDE this div, as body-level
     siblings same as before - nesting a position:fixed element inside an
     overflow-hidden flex ancestor risks it being clipped, exactly the
     kind of thing worth not risking on the same browser this is already
     working around a fixed-position bug on. -->
<div id="app-shell" class="flex flex-col h-full min-h-0">
<?php include __DIR__ . '/../header.php'; ?>

<div id="page-content" class="flex-1 min-h-0 overflow-y-auto overscroll-contain">
<div class="max-w-2xl lg:max-w-4xl mx-auto px-4 py-6">

  <?php if (!$found): ?>
    <div class="select-none rounded-lg px-4 py-3 text-sm bg-red-900/50 text-red-200 border border-red-700">
      <p class="font-medium">Session not found.</p>
      <p class="mt-1"><?= $this->e((string)($detail['message'] ?? 'Unknown error')) ?></p>
    </div>
  <?php else: ?>
    <div id="session-info" class="select-none mb-4 rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-3">
      <?= \App\Views\TranscriptView::render_session_static_info_html($detail) ?>
    </div>

    <?php if ($jumpLine !== null): ?>
      <!-- Landed here from a search result (see sidebar.php's search box/
           session.js) - the page above this loads a window ENDING at
           jumpLine, via session_history's existing `before` cursor, rather
           than the usual latest-tail page. "Back to latest" is a plain
           link (full navigation), not a JS action - simplest way back to
           the normal view, and matches this app's existing classic-link
           pattern for anything that isn't a repeated, high-frequency
           action (see CLAUDE.md's own AJAX-vs-redirect distinction). -->
      <div class="select-none mb-4 rounded-lg border border-sky-800/40 bg-sky-950/20 px-3 py-2 flex items-center justify-between gap-2 text-xs text-sky-300">
        <span>Showing a search result</span>
        <a href="/session.php?session=<?= urlencode($sessionName) ?>" class="text-sky-400 active:text-sky-200 font-medium">Back to latest &rarr;</a>
      </div>
    <?php endif; ?>

    <h2 class="select-none text-sm font-medium text-slate-400 mb-2">History</h2>

    <!-- #history-list and #load-more-btn are ALWAYS rendered (never
         conditionally, unlike the old version of this template) - both are
         captured ONCE by session.js at load time (document.getElementById,
         never re-queried), so a session opened before its first line
         exists (a brand-new session, or one that's genuinely empty so
         far) used to leave `list` permanently null for the rest of the
         page's life once either container was missing, crashing the very
         first appendPendingEntry() call (list.appendChild() on null) the
         moment a message was actually sent - found live 2026-08-08. The
         "nothing here yet" messaging now lives INSIDE #history-list as a
         plain placeholder note instead, which session.js's own
         appendPendingEntry()/pollHistory() already remove the moment any
         real content (optimistic or polled) actually lands - see
         removeHistoryEmptyNote() there. -->
    <button type="button" id="load-more-btn"
      data-session="<?= $this->e($sessionName) ?>"
      data-before="<?= $nextBefore !== null ? (int)$nextBefore : '' ?>"
      class="select-none w-full mb-2 rounded-lg border border-slate-800 bg-slate-900/50 active:bg-slate-800 text-xs text-slate-400 px-3 py-2 <?= $hasMore ? '' : 'hidden' ?>">
      Load older messages
    </button>
    <div id="history-list" class="flex flex-col gap-2">
      <?php if (!$historyOk): ?>
        <p id="history-empty-note" class="select-none rounded-lg px-4 py-3 text-sm bg-slate-900/50 border border-slate-800 text-slate-500">
          <?= $this->e((string)($history['message'] ?? 'No transcript available for this session.')) ?>
        </p>
      <?php elseif (empty($entries)): ?>
        <p id="history-empty-note" class="select-none rounded-lg px-4 py-3 text-sm bg-slate-900/50 border border-slate-800 text-slate-500">
          Nothing recorded yet.
        </p>
      <?php else: ?>
        <?= \App\Views\TranscriptView::render_transcript_entries_html($entries, $sessionName) ?>
      <?php endif; ?>
    </div>

    <div id="thinking-indicator" class="mt-4">
      <?= \App\Views\TranscriptView::render_thinking_indicator_html($detail) ?>
    </div>

    <!-- The live, actionable prompt state sits after history, not pinned
         above it - reads like the "current" end of the chat, and is what
         the initial/poll-triggered auto-scroll brings into view. -->
    <div id="blocked-prompt-section" class="mt-4">
      <?= \App\Views\TranscriptView::render_blocked_prompt_section_html($detail, $csrfToken) ?>
    </div>
  <?php endif; ?>

</div>
</div>

<?php include __DIR__ . '/../compose-bar.php'; ?>
</div>

<button type="button" id="go-to-bottom-btn"
  class="select-none hidden fixed bottom-24 right-5 z-20 w-11 h-11 rounded-full border border-slate-700 bg-slate-800 text-slate-200 shadow-lg active:bg-slate-700 flex items-center justify-center text-lg">
  &darr;
</button>

<script>
window.CSM_BOOTSTRAP = <?= json_encode(['session' => $sessionName, 'csrfToken' => $csrfToken, 'newestLine' => $newestLine, 'claudeSessionId' => $detail['claude_session_id'] ?? null, 'jumpLine' => $jumpLine]) ?>;
</script>
<script src="<?= \App\Assets::versioned_url('/js/common.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/session.js') ?>"></script>
