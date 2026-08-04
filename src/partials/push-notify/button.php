<div id="push-notify-control" class="mt-1" data-vapid-key="<?= $this->e($vapidPublicKey) ?>" data-csrf-token="<?= $this->e($csrfToken) ?>">
  <button type="button" id="push-notify-btn" class="rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-3 py-1.5 hidden">
    Enable notifications
  </button>
  <span id="push-notify-status" class="text-xs text-slate-500"></span>
</div>
<script src="<?= \App\Assets::versioned_url('/js/push-notify.js') ?>"></script>
