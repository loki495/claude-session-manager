<?php
// break-words (not break-all, used elsewhere for compact collapsed summary lines) - this is
// prose, so only a genuinely too-long token (a long constant name, URL, hash, ...) should ever
// break mid-word; normal short words shouldn't. Found live: a message containing
// "FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE" (no spaces, 51 chars) widened the whole
// page horizontally without this.
?>
<?php if ($kind === 'text'): ?><p class="whitespace-pre-wrap break-words text-sm lg:text-base text-slate-100"><?= $this->e($text) ?></p>
<?php elseif ($kind === 'plan'): ?><div class="rounded border border-amber-800/40 bg-amber-950/20 px-3 py-2"><p class="whitespace-pre-wrap break-words text-sm lg:text-base text-amber-100"><?= $this->e($text) ?></p></div>
<?php elseif ($kind === 'tool_use'): ?><div class="tool-use-block<?= $subagentClass ?>"><?= $collapsibleHtml ?></div>
<?php elseif ($kind === 'tool_result'): ?><div class="tool-detail<?= $subagentClass ?>"><?= $collapsibleHtml ?></div><?= $imageHtml ?><?= $attachmentsHtml ?>
<?php elseif ($kind === 'image' && $imageHtml !== ''): ?><?= $imageHtml ?>
<?php elseif ($text !== ''): ?><p class="break-words text-xs text-slate-600"><?= $this->e($text) ?></p>
<?php endif ?>
