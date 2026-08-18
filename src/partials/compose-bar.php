<?php

use App\Views\PushNotifyView;
use App\Views\QuotaFooterView;
use App\Views\TranscriptView;

if ($found): ?>
  <?php $composeBlocked = !empty($detail['blocked_reason']); ?>
  <!-- A normal (non-fixed) flex item, last child of #app-shell (see
       session.php) - position:fixed here used to visually detach mid-
       scroll on iOS Safari (found live 2026-08-17, a known-buggy pairing
       with the page-level scroll that used to drive this whole page), so
       the fix was to stop relying on fixed positioning for this element
       at all rather than patch around it. -->
  <div id="compose-bar" class="select-none flex-none bg-slate-950/95 border-t border-slate-800 px-4 py-3">
    <div class="max-w-2xl lg:max-w-4xl mx-auto">
      <div id="compose-input-row" class="<?= $composeBlocked ? 'hidden' : '' ?>">
        <div id="compose-attachments-preview" class="hidden flex flex-wrap gap-2 mb-2"></div>
        <div class="flex items-stretch gap-2">
          <button type="button" id="compose-attach-btn" aria-label="Attach file"
            class="min-h-[2.75rem] w-11 shrink-0 rounded-lg bg-slate-800 border border-slate-700 active:bg-slate-700 disabled:opacity-50 text-slate-300 text-xl leading-none">
            +
          </button>
          <input type="file" id="compose-file-input" class="hidden" multiple>
          <textarea id="compose-textarea" rows="1" placeholder="Message&hellip;"
            class="flex-1 resize-none rounded-lg bg-slate-800 border border-slate-700 text-base text-slate-100 px-3 py-2 max-h-32 overflow-y-auto overscroll-contain focus:outline-none focus:border-slate-500"></textarea>
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
        <?= QuotaFooterView::quota_footer_html(TranscriptView::render_mode_toggle_html($detail), $sessionName) ?>
        <?= PushNotifyView::push_notify_button_html($vapidPublicKey, $csrfToken) ?>
      </div>
    </div>
  </div>
<?php endif; ?>
