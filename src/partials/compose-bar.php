<?php

use App\Views\PushNotifyView;
use App\Views\QuotaFooterView;
use App\Views\TranscriptView;

if ($found): ?>
  <?php $composeBlocked = !empty($detail['blocked_reason']); ?>
  <div id="compose-bar" class="select-none fixed bottom-0 inset-x-0 z-20 bg-slate-950/95 backdrop-blur border-t border-slate-800 px-4 py-3">
    <div class="max-w-2xl mx-auto">
      <div id="compose-input-row" class="<?= $composeBlocked ? 'hidden' : '' ?>">
        <div class="flex items-stretch gap-2">
          <button type="button" id="compose-attach-btn" aria-label="Attach file"
            class="min-h-[2.75rem] w-11 shrink-0 rounded-lg bg-slate-800 border border-slate-700 active:bg-slate-700 disabled:opacity-50 text-slate-300 text-xl leading-none">
            +
          </button>
          <input type="file" id="compose-file-input" class="hidden" multiple>
          <textarea id="compose-textarea" rows="1" placeholder="Message&hellip;"
            class="flex-1 resize-none rounded-lg bg-slate-800 border border-slate-700 text-base text-slate-100 px-3 py-2 max-h-32 overflow-y-auto focus:outline-none focus:border-slate-500"></textarea>
          <button type="button" id="compose-send-btn" disabled
            class="min-h-[2.75rem] shrink-0 rounded-lg bg-indigo-600 active:bg-indigo-700 disabled:opacity-50 disabled:active:bg-indigo-600 font-medium text-sm px-4 py-2">
            Send
          </button>
        </div>
        <div id="compose-upload-status" class="hidden text-xs text-slate-500 mt-1"></div>
        <div id="compose-status" class="hidden text-xs text-red-400 mt-1"></div>
      </div>
      <div id="compose-blocked-note" class="<?= $composeBlocked ? '' : 'hidden' ?> text-xs text-slate-500 py-2">
        Answer the prompt above to continue.
      </div>
      <div class="mt-2">
        <?= QuotaFooterView::quota_footer_html(TranscriptView::render_mode_toggle_html($detail)) ?>
        <?= PushNotifyView::push_notify_button_html($vapidPublicKey, $csrfToken) ?>
      </div>
    </div>
  </div>
<?php endif; ?>
