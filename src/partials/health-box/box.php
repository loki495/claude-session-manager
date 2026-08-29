<details class="select-none mb-4 rounded-lg border border-slate-800 bg-slate-900/50 text-sm">
  <summary class="px-4 py-3 cursor-pointer list-none flex items-center gap-2 [&::-webkit-details-marker]:hidden">
    <span class="w-2 h-2 rounded-full <?= $dotColor ?> shrink-0"></span>
    <span class="<?= $summaryColor ?> font-medium"><?= $summaryText ?></span>
    <span class="text-slate-500 ml-auto text-xs">Setup health</span>
  </summary>
  <div class="px-4 pb-3"><?php foreach ($grouped as $section => $sectionChecks):
    $sectionFailed = 0;
    foreach ($sectionChecks as $sc) { if (!($sc['ok'] ?? false)) { $sectionFailed++; } }
    $sectionOk = $sectionFailed === 0;
    $sectionDot = $sectionOk ? 'text-emerald-400' : 'text-amber-400';
  ?><details class="mt-2 first:mt-0">
    <summary class="cursor-pointer list-none text-[11px] font-semibold uppercase tracking-wider <?= $sectionDot ?> [&::-webkit-details-marker]:hidden"><span class="hover:opacity-80"><?= $this->e($section) ?></span> <span class="font-normal normal-case tracking-normal text-slate-500">(<?= count($sectionChecks) - $sectionFailed ?>/<?= count($sectionChecks) ?>)</span></summary>
    <div class="ml-2 mt-1"><?php foreach ($sectionChecks as $check): $ok = (bool)($check['ok'] ?? false); $detail = $check['detail'] ?? null; ?><div class="flex items-start gap-2 py-1.5"><span class="mt-0.5"><?php if ($ok): ?><span class="text-emerald-400">&#10003;</span><?php else: ?><span class="text-amber-400">&#10007;</span><?php endif ?></span><div class="min-w-0 flex-1"><div class="text-slate-300"><?= $this->e((string)($check['label'] ?? '')) ?></div><?php if ($detail !== null && $detail !== ''): ?><div class="text-[11px] text-slate-500 font-mono break-all mt-0.5"><?= $this->e((string)$detail) ?></div><?php endif ?></div></div><?php endforeach ?></div>
  </details><?php endforeach ?><?= $intervalControl ?></div>
</details>
