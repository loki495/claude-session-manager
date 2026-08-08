<li class="relative rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-3 flex items-start justify-between gap-3" data-archived-row>
  <a href="/archived_session.php?claude_session_id=<?= urlencode($claudeSessionId) ?>" class="absolute inset-0 rounded-xl" aria-label="View archived transcript for <?= $this->e($title) ?>"></a>
  <div class="min-w-0 flex-1">
    <div class="select-none text-sm truncate"><?= $this->e($title) ?></div>
    <?php if (!empty($cwd)): ?><div class="select-none text-xs text-slate-500 truncate mt-0.5"><?= $this->e((string)$cwd) ?></div><?php endif ?>
    <div class="select-none text-xs text-slate-400 mt-1"><?= $this->e($relativeTime) ?></div>
  </div>
  <?php if (!empty($cwd)): ?>
  <form method="post" action="/" class="relative">
    <input type="hidden" name="action" value="resume">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
    <input type="hidden" name="claude_session_id" value="<?= $this->e($claudeSessionId) ?>">
    <input type="hidden" name="workdir" value="<?= $this->e((string)$cwd) ?>">
    <button type="submit" class="select-none min-h-[2.75rem] shrink-0 rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-200 font-medium text-sm px-4 py-2">Resume</button>
  </form>
  <?php endif ?>
</li>
