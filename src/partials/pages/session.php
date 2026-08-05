<?php
$this->layout('layout', [
    'title' => $found ? (string)($detail['title'] ?? $detail['name']) : 'Claude Session Manager',
    'viewportContent' => 'width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=5, viewport-fit=cover',
]);
?>
<?php $this->start('style') ?>
<style>
  /* Toggled via the "Show tool outputs" sidebar setting - a body-level
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
    box-shadow: 0 0 0 1px rgba(251, 191, 36, 0.8), 0 0 8px 4px rgba(251, 191, 36, 0.4), 0 0 16px 8px rgba(251, 191, 36, 0.15);
    transition: box-shadow 1.2s ease-out;
  }
  .new-content-highlight.fading {
    box-shadow: 0 0 0 1px rgba(251, 191, 36, 0), 0 0 8px 4px rgba(251, 191, 36, 0), 0 0 16px 8px rgba(251, 191, 36, 0);
  }
</style>
<?php $this->stop() ?>

<?php include __DIR__ . '/../header.php'; ?>

<?php include __DIR__ . '/../sidebar.php'; ?>

<div class="max-w-2xl mx-auto px-4 py-6 pb-44">

  <?php if (!$found): ?>
    <div class="select-none rounded-lg px-4 py-3 text-sm bg-red-900/50 text-red-200 border border-red-700">
      <p class="font-medium">Session not found.</p>
      <p class="mt-1"><?= $this->e((string)($detail['message'] ?? 'Unknown error')) ?></p>
    </div>
  <?php else: ?>
    <div id="session-info" class="select-none mb-4 rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-3">
      <?= \App\Views\TranscriptView::render_session_static_info_html($detail) ?>
    </div>

    <h2 class="select-none text-sm font-medium text-slate-400 mb-2">History</h2>

    <?php if (!$historyOk): ?>
      <div class="select-none rounded-lg px-4 py-3 text-sm bg-slate-900/50 border border-slate-800 text-slate-500">
        <?= $this->e((string)($history['message'] ?? 'No transcript available for this session.')) ?>
      </div>
    <?php elseif (empty($entries)): ?>
      <div class="select-none rounded-lg px-4 py-3 text-sm bg-slate-900/50 border border-slate-800 text-slate-500">
        Nothing recorded yet.
      </div>
    <?php else: ?>
      <button type="button" id="load-more-btn"
        data-session="<?= $this->e($sessionName) ?>"
        data-before="<?= $nextBefore !== null ? (int)$nextBefore : '' ?>"
        class="select-none w-full mb-2 rounded-lg border border-slate-800 bg-slate-900/50 active:bg-slate-800 text-xs text-slate-400 px-3 py-2 <?= $hasMore ? '' : 'hidden' ?>">
        Load older messages
      </button>
      <div id="history-list" class="flex flex-col gap-2">
        <?php foreach ($entries as $entry): ?>
          <?= \App\Views\TranscriptView::render_transcript_entry($entry, $sessionName) ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

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

<button type="button" id="go-to-bottom-btn"
  class="select-none hidden fixed bottom-24 right-5 z-20 w-11 h-11 rounded-full border border-slate-700 bg-slate-800 text-slate-200 shadow-lg active:bg-slate-700 flex items-center justify-center text-lg">
  &darr;
</button>

<?php include __DIR__ . '/../compose-bar.php'; ?>

<script>
window.CSM_BOOTSTRAP = <?= json_encode(['session' => $sessionName, 'csrfToken' => $csrfToken, 'newestLine' => $newestLine]) ?>;
</script>
<script src="<?= \App\Assets::versioned_url('/js/common.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/session.js') ?>"></script>
