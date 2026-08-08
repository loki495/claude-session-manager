<li class="relative rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-3" data-archived-row>
  <a href="/archived_session.php?claude_session_id=<?= urlencode($claudeSessionId) ?>" class="absolute inset-0 rounded-xl" aria-label="View archived transcript for <?= $this->e($title) ?>"></a>
  <div class="min-w-0">
    <div class="select-none text-sm truncate"><?= $this->e($title) ?></div>
    <?php if (!empty($cwd)): ?><div class="select-none text-xs text-slate-500 truncate mt-0.5"><?= $this->e((string)$cwd) ?></div><?php endif ?>
    <div class="select-none text-xs text-slate-400 mt-1"><?= $this->e($relativeTime) ?></div>
  </div>
</li>
