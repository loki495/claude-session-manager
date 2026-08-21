<div>
  <!-- text-base (16px), not text-sm - iOS Safari auto-zooms the whole
       viewport in on focusing any text input rendered under 16px. -->
  <div class="relative mb-3">
    <input type="text" id="archived-search" placeholder="Filter by title, folder, or name&hellip;" class="select-none w-full rounded-lg border border-slate-700 bg-slate-800 pl-3 pr-9 py-2 text-base text-slate-200 placeholder-slate-500" autocomplete="off">
    <button type="button" id="archived-search-clear-btn" aria-label="Clear filter" tabindex="-1"
      class="hidden absolute top-1/2 right-1.5 -translate-y-1/2 w-6 h-6 flex items-center justify-center rounded text-slate-500 active:text-slate-300 text-lg leading-none">&times;</button>
  </div>
  <ul id="archived-rows" class="flex flex-col gap-3"><?= $rowsHtml ?></ul>
  <p id="archived-no-matches" class="hidden select-none text-center text-xs text-slate-500 py-4">No sessions match your search.</p>
</div>
