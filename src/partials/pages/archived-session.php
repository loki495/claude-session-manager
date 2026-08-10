<?php
$this->layout('layout', [
    'title' => $found ? (string)($detail['title'] ?? $claudeSessionId) : 'Claude Session Manager',
    'viewportContent' => 'width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=5, viewport-fit=cover',
]);
?>

<header class="select-none sticky top-0 z-20 bg-slate-950/95 backdrop-blur border-b border-slate-800">
  <div class="max-w-2xl lg:max-w-4xl mx-auto px-4 py-2 flex items-center gap-2">
    <a href="/" class="text-sm text-slate-400 hover:underline whitespace-nowrap">&larr; All sessions</a>
    <div class="text-sm font-medium text-slate-200 truncate flex-1 text-center">
      <?= $found ? $this->e((string)($detail['title'] ?? $claudeSessionId)) : '' ?>
    </div>
    <span class="text-xs text-slate-500 shrink-0 rounded-full border border-slate-700 px-2 py-0.5">Archived</span>
  </div>
</header>

<div id="page-content" class="max-w-2xl lg:max-w-4xl mx-auto px-4 py-6">

  <?php if (!$found): ?>
    <div class="select-none rounded-lg px-4 py-3 text-sm bg-red-900/50 text-red-200 border border-red-700">
      <p class="font-medium">Session not found.</p>
      <p class="mt-1"><?= $this->e((string)($detail['message'] ?? 'Unknown error')) ?></p>
    </div>
  <?php else: ?>
    <div class="select-none mb-4 rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-3">
      <div class="text-sm text-slate-200"><?= $this->e((string)($detail['title'] ?? $claudeSessionId)) ?></div>
      <?php if (!empty($detail['cwd'])): ?><div class="text-xs text-slate-500 truncate mt-0.5"><?= $this->e((string)$detail['cwd']) ?></div><?php endif ?>
      <div class="text-xs text-slate-400 mt-1">Last active <?= $this->e(\App\Views\SessionRowView::relative_time((int)($detail['last_activity'] ?? 0))) ?></div>
    </div>

    <div class="select-none mb-4">
      <input type="search" id="session-search-input" placeholder="Search this conversation&hellip;" autocomplete="off"
        class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-200 placeholder:text-slate-500">
      <div id="session-search-results" class="mt-2 flex flex-col gap-1.5 text-sm"></div>
    </div>

    <?php if ($jumpLine !== null): ?>
      <!-- See session.php's own jump_line banner for the full reasoning -
           same idea, minus any live-poll follow-up (there is none here). -->
      <div class="select-none mb-4 rounded-lg border border-sky-800/40 bg-sky-950/20 px-3 py-2 flex items-center justify-between gap-2 text-xs text-sky-300">
        <span>Showing a search result</span>
        <a href="/archived_session.php?claude_session_id=<?= urlencode($claudeSessionId) ?>" class="text-sky-400 active:text-sky-200 font-medium">Back to latest &rarr;</a>
      </div>
    <?php endif; ?>

    <h2 class="select-none text-sm font-medium text-slate-400 mb-2">History (read-only)</h2>

    <?php if (!$historyOk): ?>
      <div class="select-none rounded-lg px-4 py-3 text-sm bg-slate-900/50 border border-slate-800 text-slate-500">
        <?= $this->e((string)($history['message'] ?? 'No transcript available for this session.')) ?>
      </div>
    <?php elseif (empty($entries)): ?>
      <div class="select-none rounded-lg px-4 py-3 text-sm bg-slate-900/50 border border-slate-800 text-slate-500">
        Nothing recorded.
      </div>
    <?php else: ?>
      <button type="button" id="load-more-btn"
        data-claude-session-id="<?= $this->e($claudeSessionId) ?>"
        data-before="<?= $nextBefore !== null ? (int)$nextBefore : '' ?>"
        class="select-none w-full mb-2 rounded-lg border border-slate-800 bg-slate-900/50 active:bg-slate-800 text-xs text-slate-400 px-3 py-2 <?= $hasMore ? '' : 'hidden' ?>">
        Load older messages
      </button>
      <div id="history-list" class="flex flex-col gap-2">
        <?= \App\Views\TranscriptView::render_transcript_entries_html($entries, $claudeSessionId, true) ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

</div>

<script>
window.CSM_ARCHIVED_BOOTSTRAP = <?= json_encode(['claudeSessionId' => $claudeSessionId, 'jumpLine' => $jumpLine]) ?>;
</script>
<script src="<?= \App\Assets::versioned_url('/js/common.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/archived-session.js') ?>"></script>
