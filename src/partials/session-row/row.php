<li class="rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-3 flex items-start justify-between gap-3">
  <div class="min-w-0 flex-1">
    <div class="text-sm truncate"><a href="/session.php?session=<?= urlencode($name) ?>" class="hover:underline"><?= $this->e($title) ?></a></div>
    <?php if ($hasExplicitTitle): ?><div class="font-mono text-xs text-slate-500 truncate mt-0.5"><?= $this->e($name) ?></div><?php endif ?>
    <?php if (!empty($workdir)): ?><div class="text-xs text-slate-500 truncate mt-0.5"><?= $this->e((string)$workdir) ?></div><?php endif ?>
    <div class="text-xs text-slate-400 mt-1 flex items-center gap-2"><span><?= $this->e($relativeTime) ?></span><span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span><?= $attached ? '<span class="text-emerald-400">attached</span>' : '<span class="text-slate-500">detached</span>' ?></div>
    <div class="mt-1">
      <button type="button" class="show-recent-btn rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-3 py-1.5" data-session="<?= $this->e($name) ?>" data-loaded="0">Show last 3 messages</button>
      <div class="recent-messages hidden mt-1 flex flex-col gap-1 max-h-64 overflow-y-auto"></div>
    </div>
    <?= $blockedHtml ?>
  </div>
  <form method="post" action="/" onsubmit="return confirm('Kill session <?= $this->e($name) ?>?');">
    <input type="hidden" name="action" value="kill">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
    <input type="hidden" name="session" value="<?= $this->e($name) ?>">
    <button type="submit" class="min-h-[2.75rem] shrink-0 rounded-lg bg-red-900/70 active:bg-red-800 text-red-100 font-medium text-sm px-4 py-2">Kill</button>
  </form>
</li>
