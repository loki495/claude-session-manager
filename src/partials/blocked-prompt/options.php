<div class="select-none prompt-options-wrapper mt-2" data-session="<?= $this->e($sessionName) ?>" data-csrf-token="<?= $this->e($csrfToken) ?>">
  <div class="flex flex-wrap gap-2">
    <?php if ($isMultiQuestion): ?><button type="button" class="nav-prompt-btn rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2" data-direction="left" aria-label="Previous question">&larr;</button><?php endif ?>
    <?php foreach ($options as $opt): ?>
      <?php if (stripos((string)$opt['label'], 'type something') !== false): ?>
        <?php // break-words + max-w-full: an AskUserQuestion option label has no length limit imposed by
        // the tool itself - a long unbroken one (a pasted identifier/URL, say) would otherwise widen this
        // button (and the whole page with it) instead of wrapping. Verified live that break-words ALONE
        // isn't enough: a flex item's width is still its own shrink-to-fit content size unless something
        // caps it, so overflow-wrap never gets a narrower box to actually wrap within - max-w-full is
        // what forces that cap, matching the button's flex-wrap row. ?>
        <button type="button" class="reveal-freetext-btn rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2 break-words max-w-full" data-option="<?= (int)$opt['number'] ?>"><?= (int)$opt['number'] ?>. <?= $this->e((string)$opt['label']) ?></button>
      <?php else: ?>
        <form method="post" action="/answer_prompt.php" data-confirm-label="<?= $this->e((string)$opt['label']) ?>">
          <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
          <input type="hidden" name="session" value="<?= $this->e($sessionName) ?>">
          <input type="hidden" name="option" value="<?= (int)$opt['number'] ?>">
          <button type="submit" class="rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2 break-words max-w-full"><?= (int)$opt['number'] ?>. <?= $this->e((string)$opt['label']) ?></button>
        </form>
      <?php endif ?>
    <?php endforeach ?>
    <?php if ($isMultiQuestion): ?><button type="button" class="nav-prompt-btn rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2" data-direction="right" aria-label="Next question">&rarr;</button><?php endif ?>
  </div>
  <?php if ($hasFreeText): ?>
  <div class="freetext-reply hidden mt-2">
    <textarea class="freetext-reply-textarea w-full resize-none rounded-lg bg-slate-800 border border-slate-700 text-base text-slate-100 px-3 py-2" rows="2" placeholder="Type your reply&hellip;"></textarea>
    <button type="button" class="freetext-reply-send-btn mt-1 rounded-lg bg-indigo-600 active:bg-indigo-700 text-white text-xs font-medium px-3 py-1.5">Send</button>
  </div>
  <?php endif ?>
</div>
