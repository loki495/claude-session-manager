<div id="quota-footer" class="select-none min-w-0 flex-1" style="min-width:0;flex:1 1 auto" data-session="<?= $this->e($sessionName) ?>">
  <div class="flex items-center justify-between gap-2 mb-1">
    <button type="button" id="quota-toggle-btn" class="flex items-center gap-1 text-xs text-slate-500 active:text-slate-300">
      <span id="quota-toggle-icon">&#9662;</span>
      <span>Quota</span>
    </button>
    <?= $extraHtml ?>
  </div>
  <div id="quota-info" class="flex flex-col gap-0.5 min-w-0 text-sm font-medium max-h-40 overflow-y-auto overscroll-contain pr-1" aria-live="polite">
    <span class="text-slate-500">Loading quota&hellip;</span>
  </div>
</div>
<script src="<?= \App\Assets::versioned_url('/js/quota-footer.js') ?>"></script>
