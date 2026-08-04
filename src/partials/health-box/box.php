<details class="mb-4 rounded-lg border border-slate-800 bg-slate-900/50 text-sm">
  <summary class="px-4 py-3 cursor-pointer list-none flex items-center gap-2 [&::-webkit-details-marker]:hidden">
    <span class="w-2 h-2 rounded-full <?= $dotColor ?> shrink-0"></span>
    <span class="<?= $summaryColor ?> font-medium"><?= $summaryText ?></span>
    <span class="text-slate-500 ml-auto text-xs">Setup health</span>
  </summary>
  <div class="px-4 pb-3"><?php foreach ($checks as $check): ?><?php
    $ok = (bool)($check['ok'] ?? false);
    $detail = $check['detail'] ?? null;
  ?><div class="flex items-start gap-2 py-1.5 border-t border-slate-800 first:border-t-0"><span class="mt-0.5"><?php if ($ok): ?><span class="text-emerald-400">&#10003;</span><?php else: ?><span class="text-amber-400">&#10007;</span><?php endif ?></span><div class="min-w-0 flex-1"><div class="text-slate-300"><?= $this->e((string)($check['label'] ?? '')) ?></div><?php if ($detail !== null && $detail !== ''): ?><div class="text-[11px] text-slate-500 font-mono break-all mt-0.5"><?= $this->e((string)$detail) ?></div><?php endif ?></div></div><?php endforeach ?><?= $intervalControl ?></div>
</details>
