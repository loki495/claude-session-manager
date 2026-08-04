<li class="rounded-xl border border-slate-800/60 bg-slate-900/30 px-4 py-3 flex items-center justify-between gap-3">
  <div class="min-w-0">
    <?php if (!empty($title)): ?><div class="text-sm truncate text-slate-300"><?= $this->e((string)$title) ?></div><?php endif ?>
    <div class="font-mono text-xs text-slate-500 truncate mt-0.5">pid <?= $pid ?><?= $tmuxSession !== null ? ' · tmux: ' . $this->e($tmuxSession) : ' · no tmux (plain process)' ?></div>
    <?php if (!empty($cwd)): ?><div class="text-xs text-slate-500 truncate mt-0.5"><?= $this->e((string)$cwd) ?></div><?php endif ?>
    <div class="text-xs text-slate-500 mt-1"><?= $startedAt !== null ? $this->e($startedAt) : 'start time unknown' ?></div>
  </div>
  <form method="post" action="/" onsubmit="return confirm('Kill pid <?= $pid ?><?= $tmuxSession !== null ? ' (tmux session ' . $this->e($tmuxSession) . ')' : '' ?>? This process was not started by this tool.');">
    <input type="hidden" name="action" value="kill_bare">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
    <input type="hidden" name="pid" value="<?= $pid ?>">
    <button type="submit" class="min-h-[2.75rem] shrink-0 rounded-lg bg-red-900/70 active:bg-red-800 text-red-100 font-medium text-sm px-4 py-2">Kill</button>
  </form>
</li>
