<form method="post" action="/" class="flex items-center gap-2 pt-2 mt-2 border-t border-slate-800">
  <input type="hidden" name="action" value="set_push_timer_interval">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
  <label for="push-timer-interval-select" class="text-slate-400">Push check interval</label>
  <select id="push-timer-interval-select" name="seconds" class="rounded border border-slate-700 bg-slate-800 text-slate-300 text-xs px-1.5 py-1 ml-auto">
    <?php foreach ($presets as $seconds): ?><option value="<?= $seconds ?>"<?= $seconds === $currentSeconds ? ' selected' : '' ?>><?= $seconds ?>s</option><?php endforeach ?>
  </select>
  <button type="submit" class="rounded border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-2 py-1">Save</button>
</form>
