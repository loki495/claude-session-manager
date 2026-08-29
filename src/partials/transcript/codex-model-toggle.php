<div class="flex items-center gap-2">
  <select id="codex-model-select" disabled data-current-model="<?= $this->e((string)($model ?? '')) ?>" class="select-none text-xs font-medium pl-2 pr-6 py-1 rounded-full border border-slate-700 bg-slate-800 text-slate-300 disabled:opacity-50">
    <option value="" selected>Loading models&hellip;</option>
  </select>
  <select id="codex-effort-select" disabled data-current-effort="<?= $this->e((string)($effort ?? '')) ?>" aria-label="Reasoning effort" class="select-none text-xs font-medium pl-2 pr-6 py-1 rounded-full border border-slate-700 bg-slate-800 text-slate-300 disabled:opacity-50">
    <option value="">Default effort</option>
  </select>
</div>
