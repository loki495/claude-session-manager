<?php
$this->layout('layout', [
    'title' => 'Claude Session Manager',
    'viewportContent' => 'width=device-width, initial-scale=1, viewport-fit=cover',
]);
?>
<?php $this->start('head-extra') ?>
<link rel="manifest" href="data:application/manifest+json,%7B%22name%22%3A%22Claude%20Sessions%22%2C%22display%22%3A%22standalone%22%7D">
<?php $this->stop() ?>

<div class="max-w-2xl mx-auto px-4 py-6 pb-32">

  <header class="select-none mb-6 flex items-start justify-between gap-2">
    <div class="min-w-0">
      <h1 class="text-xl font-semibold tracking-tight">Claude Session Manager</h1>
      <p id="session-count-text" class="text-sm text-slate-400 mt-1"><?= \App\Views\SessionRowView::session_count_label_html(count($sessions)) ?></p>
    </div>
    <select id="poll-interval-select" aria-label="Polling interval"
      class="shrink-0 text-xs font-medium pl-1.5 pr-5 py-1 rounded-full border border-slate-700 bg-slate-800 text-slate-400">
      <option value="1000">1s</option>
      <option value="3000" selected>3s</option>
      <option value="5000">5s</option>
      <option value="10000">10s</option>
      <option value="15000">15s</option>
    </select>
  </header>

  <?php if (!$agentReachable): ?>
    <div class="select-none mb-4 rounded-lg px-4 py-3 text-sm bg-red-900/50 text-red-200 border border-red-700">
      <p class="font-medium">Cannot reach the host agent.</p>
      <p class="mt-1"><?= $this->e((string)($listResult['message'] ?? 'Unknown error')) ?></p>
      <p class="mt-1 text-red-300">Check on the host: <code>systemctl --user status csm-agent.socket</code></p>
    </div>
  <?php endif; ?>

  <?php if ($flashMsg !== null && $flashMsg !== ''): ?>
    <div class="select-none mb-4 rounded-lg px-4 py-3 text-sm <?= $flashOk ? 'bg-emerald-900/50 text-emerald-200 border border-emerald-700' : 'bg-red-900/50 text-red-200 border border-red-700' ?>">
      <?= $this->e($flashMsg) ?>
    </div>
  <?php endif; ?>

  <?php if ($agentReachable && $hookCheckOk && !$hookInstalled): ?>
    <div class="select-none mb-4 rounded-lg px-4 py-3 text-sm bg-amber-900/40 text-amber-200 border border-amber-700/60">
      <p class="font-medium">App hooks aren't fully installed.</p>
      <p class="mt-1 text-amber-300/90">Without the SessionStart hook, a session's transcript view goes stale forever after a <code>/clear</code>, <code>/compact</code>, or resume. Without the PreToolUse hook, a blocked prompt's preview can come out truncated for long commands/files instead of showing the full thing.</p>
      <form method="post" action="/" class="mt-2">
        <input type="hidden" name="action" value="install_hook">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
        <button type="submit" class="rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2">
          Install hooks
        </button>
      </form>
    </div>
  <?php elseif ($agentReachable && !$hookCheckOk): ?>
    <div class="select-none mb-4 rounded-lg px-4 py-3 text-sm bg-amber-900/40 text-amber-200 border border-amber-700/60">
      <p class="font-medium">Could not check the app hooks.</p>
      <p class="mt-1 text-amber-300/90"><?= $this->e((string)($hookResult['message'] ?? 'Unknown error')) ?></p>
    </div>
  <?php endif; ?>

  <?= \App\Views\HealthBoxView::health_box_html($healthChecks, $pushTimerIntervalSeconds, $csrfToken) ?>

  <details id="new-session-details" class="select-none mb-3 rounded-xl border border-slate-800 bg-slate-900/50">
    <summary id="new-session-summary" class="min-h-[3rem] flex items-center justify-center rounded-xl bg-indigo-600 active:bg-indigo-700 font-medium text-base px-4 py-3 cursor-pointer list-none [&::-webkit-details-marker]:hidden">
      + New Session
    </summary>
    <form method="post" action="/" class="px-4 pt-4 pb-4 flex flex-col gap-3" id="new-session-form">
      <input type="hidden" name="action" value="new">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
      <input type="hidden" name="workdir" id="workdir_value">
      <div class="text-sm text-slate-300">Working directory</div>
      <div class="rounded-lg border border-slate-700 bg-slate-800 overflow-hidden">
        <div id="browser_path" class="px-3 py-2 text-xs font-mono text-slate-400 truncate border-b border-slate-700">Loading&hellip;</div>
        <ul id="browser_list" class="max-h-56 overflow-y-auto overscroll-contain divide-y divide-slate-700/60 text-sm"></ul>
      </div>
      <button type="submit" id="new-session-submit" disabled
        class="min-h-[3rem] rounded-lg bg-indigo-600 active:bg-indigo-700 disabled:opacity-50 disabled:active:bg-indigo-600 font-medium text-base px-4 py-3">
        Start Session Here
      </button>
    </form>
  </details>

  <form method="post" action="/" class="select-none mb-6" onsubmit="return confirm('Kill all tracked sessions inactive for more than 12h?');">
    <input type="hidden" name="action" value="cleanup">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
    <button type="submit"
      class="w-full min-h-[3rem] rounded-lg bg-amber-700 active:bg-amber-800 font-medium text-base px-4 py-3">
      Kill inactive &gt;12h
    </button>
  </form>

  <?php if ($agentReachable): ?>
    <div id="sessions-container"><?= \App\Views\SessionRowView::sessions_list_html($sessions, $csrfToken) ?></div>
  <?php endif; ?>

  <?php if ($agentReachable): ?>
    <div id="bare-container"><?= \App\Views\SessionRowView::bare_processes_html($bare, $csrfToken) ?></div>
  <?php endif; ?>

  <div class="fixed bottom-0 inset-x-0 bg-slate-950/90 backdrop-blur border-t border-slate-800 px-4 py-3">
    <div class="max-w-2xl mx-auto">
      <div class="flex items-start justify-between gap-3">
        <?= \App\Views\QuotaFooterView::quota_footer_html() ?>
        <a href="/"
          class="select-none min-h-[2.75rem] flex items-center rounded-lg bg-slate-800 active:bg-slate-700 font-medium text-sm px-4 py-2 shrink-0">
          Refresh
        </a>
      </div>
      <?= \App\Views\PushNotifyView::push_notify_button_html($vapidPublicKey, $csrfToken) ?>
    </div>
  </div>

</div>
<script>
window.CSM_BOOTSTRAP = <?= json_encode(['agentReachable' => $agentReachable]) ?>;
</script>
<script src="<?= \App\Assets::versioned_url('/js/common.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/index.js') ?>"></script>
