<div id="sidebar-overlay" class="hidden fixed inset-0 bg-black/60 z-30"></div>
<aside id="sidebar"
  class="select-none fixed top-0 right-0 h-full w-72 max-w-[85vw] bg-slate-900 border-l border-slate-800 z-40 translate-x-full transition-transform duration-200 ease-out overflow-y-auto overscroll-contain">
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
      Show tool outputs
    </label>
    <label class="flex items-center gap-2 text-sm text-slate-300">
      <input type="checkbox" id="show-tool-calls-toggle" class="rounded border-slate-600 bg-slate-800" checked>
      Show tool calls
    </label>
  </div>
  <?php if ($found): ?>
    <div class="px-4 py-3 border-t border-slate-800">
      <div class="flex items-center justify-between mb-2">
        <span class="text-xs font-medium text-slate-500">Uploaded files</span>
        <span id="uploaded-files-total" class="text-xs text-slate-500"></span>
      </div>
      <div id="uploaded-files-list" class="flex flex-col gap-1.5 text-sm mb-2">
        <div class="text-slate-500 text-xs">Loading&hellip;</div>
      </div>
      <button type="button" id="delete-all-uploads-btn" class="hidden w-full rounded-lg border border-red-900/60 bg-red-950/30 active:bg-red-900/40 text-red-300 text-xs font-medium px-3 py-1.5">
        Delete all
      </button>
    </div>
    <div class="px-4 py-3 border-t border-slate-800">
      <span class="block text-xs font-medium text-slate-500 mb-2">Plan/handoff files</span>
      <div id="plan-files-list" class="flex flex-col gap-1.5 text-sm">
        <div class="text-slate-500 text-xs">Loading&hellip;</div>
      </div>
    </div>
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
