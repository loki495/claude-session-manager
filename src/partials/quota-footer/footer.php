<div id="quota-footer" class="select-none">
  <div class="flex items-center justify-between gap-2 mb-1">
    <button type="button" id="quota-toggle-btn" class="flex items-center gap-1 text-xs text-slate-500 active:text-slate-300">
      <span id="quota-toggle-icon">&#9662;</span>
      <span>Quota</span>
    </button>
    <?= $extraHtml ?>
  </div>
  <div id="quota-info" class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 min-w-0 text-sm font-medium" aria-live="polite">
    <span class="text-slate-500">Loading quota&hellip;</span>
  </div>
</div>
<script src="<?= \App\Assets::versioned_url('/js/quota-footer.js') ?>"></script>
