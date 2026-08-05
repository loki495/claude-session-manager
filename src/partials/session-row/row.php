<li class="relative rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-3 flex items-start justify-between gap-3 active:bg-slate-800">
  <?php // Stretched-link pattern: a plain <a> absolutely covering the whole
        // card, painted below (position:static, no stacking context of its
        // own) plain text content, so a click there falls through to it -
        // but ABOVE anything that's actually interactive, which needs its
        // own "relative" wrapper to opt out of that fall-through (found
        // live: giving the WHOLE content column "relative" instead lifted
        // the plain text too, silently swallowing every click on the card
        // and breaking navigation entirely - only the specific interactive
        // bits below get their own wrapper). Never nests a <button>/<form>
        // inside an <a> itself (invalid HTML, unpredictable across
        // browsers - notably mobile Safari, which this app already has to
        // special-case elsewhere) the way wrapping the whole card's markup
        // in one <a> would have. ?>
  <a href="/session.php?session=<?= urlencode($name) ?>" class="absolute inset-0 rounded-xl" aria-label="Open transcript for <?= $this->e($title) ?>"></a>
  <div class="min-w-0 flex-1">
    <div class="select-none text-sm truncate"><?= $this->e($title) ?></div>
    <?php if ($hasExplicitTitle): ?><div class="select-none font-mono text-xs text-slate-500 truncate mt-0.5"><?= $this->e($name) ?></div><?php endif ?>
    <?php if (!empty($workdir)): ?><div class="select-none text-xs text-slate-500 truncate mt-0.5"><?= $this->e((string)$workdir) ?></div><?php endif ?>
    <div class="select-none text-xs text-slate-400 mt-1 flex items-center gap-2"><span><?= $this->e($relativeTime) ?></span><span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span><?= $attached ? '<span class="text-emerald-400">attached</span>' : '<span class="text-slate-500">detached</span>' ?></div>
    <div class="relative mt-1">
      <button type="button" class="select-none show-recent-btn rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-3 py-1.5" data-session="<?= $this->e($name) ?>" data-loaded="0">Show last 3 messages</button>
      <div class="recent-messages hidden mt-1 flex flex-col gap-1 max-h-64 overflow-y-auto"></div>
    </div>
    <div class="relative"><?= $blockedHtml ?></div>
  </div>
  <form method="post" action="/" class="relative" onsubmit="return confirm('Kill session <?= $this->e($name) ?>?');">
    <input type="hidden" name="action" value="kill">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
    <input type="hidden" name="session" value="<?= $this->e($name) ?>">
    <button type="submit" class="select-none min-h-[2.75rem] shrink-0 rounded-lg bg-red-900/70 active:bg-red-800 text-red-100 font-medium text-sm px-4 py-2">Kill</button>
  </form>
</li>
