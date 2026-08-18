<div class="text-base font-medium truncate"><?= $this->e($title) ?></div>
<div class="font-mono text-xs text-slate-500 truncate mt-0.5"><?= $this->e($name) ?></div>
<?php if (!empty($workdir)): ?><div class="text-xs text-slate-500 truncate mt-0.5"><?= $this->e((string)$workdir) ?></div><?php endif ?>
<div class="text-xs text-slate-400 mt-1 flex items-center gap-2 flex-wrap">
  <span><?= $this->e($relativeTime) ?></span>
  <span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span>
  <?= $attached ? '<span class="text-emerald-400">attached</span>' : '<span class="text-slate-500">idle</span>' ?>
  <?php if ($contextUsedPercentage !== null): ?>
    <span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span>
    <span<?= $contextUsedPercentage >= 80 ? ' class="text-amber-400"' : '' ?>>context <?= (int)round($contextUsedPercentage) ?>% used</span>
  <?php endif ?>
  <?php if ($gitWorktree !== null): ?>
    <span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span>
    <span>worktree: <?= $this->e($gitWorktree) ?></span>
  <?php endif ?>
</div>
