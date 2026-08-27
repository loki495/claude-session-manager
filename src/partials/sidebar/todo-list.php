<div class="px-4 py-3 border-t border-slate-800">
  <span class="block text-xs font-medium text-slate-500 mb-2">Tasks</span>
  <div class="flex flex-col gap-1.5">
    <?php foreach ($todos as $todo): ?>
      <?php $status = $todo['status']; ?>
      <div class="flex items-start gap-2 text-sm<?= $status === 'completed' ? ' text-slate-500' : ' text-slate-200' ?>">
        <span class="shrink-0 leading-5<?= $status === 'in_progress' ? ' text-indigo-400' : ($status === 'completed' ? ' text-emerald-500' : ' text-slate-600') ?>"><?= $status === 'completed' ? '&#10003;' : ($status === 'in_progress' ? '&#9679;' : '&#9675;') ?></span>
        <span class="break-words<?= $status === 'completed' ? ' line-through' : '' ?>"><?= $this->e($status === 'in_progress' ? $todo['activeForm'] : $todo['content']) ?></span>
      </div>
    <?php endforeach ?>
  </div>
</div>
