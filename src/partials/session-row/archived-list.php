<div>
  <input type="text" id="archived-search" placeholder="Filter by title, folder, or name&hellip;" class="select-none w-full mb-3 rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-200 placeholder-slate-500" autocomplete="off">
  <ul id="archived-rows" class="flex flex-col gap-3"><?= $rowsHtml ?></ul>
  <p id="archived-no-matches" class="hidden select-none text-center text-xs text-slate-500 py-4">No sessions match your search.</p>
</div>
