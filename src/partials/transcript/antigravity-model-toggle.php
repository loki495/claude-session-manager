<select id="antigravity-model-select" class="select-none text-xs font-medium pl-2 pr-6 py-1 rounded-full border border-slate-700 bg-slate-800 text-slate-300">
  <?php if ($model === null): ?><option value="" selected>Switch model&hellip;</option><?php endif ?>
  <?php foreach ($options as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $key === $model ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?>
</select>
