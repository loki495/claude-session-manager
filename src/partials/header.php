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
