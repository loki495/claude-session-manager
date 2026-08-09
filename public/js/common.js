// Shared, byte-identical helpers between session.js and index.js - kept as
// plain top-level globals (loaded before either page script, see the
// <script> order in session.php/index.php) rather than an ES module, to
// match this app's existing no-build, no-module script style.

// Setting: whether answering a plain prompt option asks for confirmation
// first. Shared localStorage key between session.php's sidebar checkbox and
// index.php's dashboard rows (which answer prompts too but have no sidebar
// of their own to host the toggle) - both must read/write the same key.
var CONFIRM_BEFORE_ANSWER_KEY = 'csm-confirm-before-answer';

function shouldConfirmBeforeAnswer() {
  try {
    return window.localStorage.getItem(CONFIRM_BEFORE_ANSWER_KEY) !== '0';
  } catch (e) {
    return true;
  }
}

// --- physical Shift-key tracking: every Shift+Enter-submits handler in
// this app (session.php's compose box and freetext prompt reply,
// index.php's dashboard freetext prompt reply) needs to know Shift is
// GENUINELY held, not just trust event.shiftKey on the Enter keydown
// itself - found live 2026-08-08, Andres reported a second Enter press
// on his phone sending instead of adding a newline (Enter should always
// just insert a newline; only Shift+Enter submits, everywhere text gets
// typed in this app). Best-guess cause: some mobile virtual keyboards
// auto-capitalize the next letter after a newline and appear to leak
// that as shiftKey: true on the following Enter's own keydown event,
// even with no real Shift key involved at all. A virtual keyboard's own
// on-screen "shift" toggle never dispatches a real, standalone Shift
// keydown/keyup the way a physical key does, so this independently-
// tracked flag naturally stays false on mobile (an actual Bluetooth-
// paired keyboard's Shift key still works, same as desktop) - it only
// ever becomes true from a real Shift keydown, which is exactly what
// was missing from the false positive this guards against. ---
var shiftKeyPhysicallyHeld = false;
document.addEventListener('keydown', function (e) {
  if (e.key === 'Shift') {
    shiftKeyPhysicallyHeld = true;
  }
});
document.addEventListener('keyup', function (e) {
  if (e.key === 'Shift') {
    shiftKeyPhysicallyHeld = false;
  }
});

// Polling interval: user-selectable (dropdown in both pages' headers, 1/3/5/
// 10/15s), persisted per-browser and shared across pages via this key so a
// chosen interval carries over from one to the other.
var POLL_INTERVAL_STORAGE_KEY = 'csm-poll-interval-ms';
var POLL_INTERVAL_ALLOWED_MS = [1000, 3000, 5000, 10000, 15000];

// Reads the raw response text and only then tries to parse it (instead of
// jumping straight to r.json(), which throws before you ever see the body)
// so a parse failure can report the actual status code and a body snippet
// right in the alert/inline error, not just a bare "something went wrong" -
// no DevTools needed to tell which endpoint failed or why.
function parseJsonResponse(r, label) {
  return r.text().then(function (text) {
    try {
      return JSON.parse(text);
    } catch (e) {
      return { ok: false, message: 'Unexpected response [' + label + '] (status ' + r.status + '): ' + text.slice(0, 200) };
    }
  });
}

function escapeHtml(text) {
  var div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// --- full screen text view: a shared "View full screen" affordance for
// any long tool call/output or prompt context that's still hard to read
// even expanded (BlockedPromptView::render_collapsible_block() in PHP,
// renderCollapsibleBlock() in session.js - both share this one modal
// rather than each page rolling its own). Reads the text straight off the
// triggering button's own preceding <pre> sibling instead of a data
// attribute, so there's no risk of the modal ever showing something
// different from what was actually expanded on the page. ---
var fullscreenTextModal = document.getElementById('fullscreen-text-modal');
var fullscreenTextModalContent = document.getElementById('fullscreen-text-modal-content');
var fullscreenTextModalClose = document.getElementById('fullscreen-text-modal-close');
var fullscreenTextModalWrapToggle = document.getElementById('fullscreen-text-modal-wrap-toggle');

if (fullscreenTextModal && fullscreenTextModalContent && fullscreenTextModalClose) {
  var bodyOverflowBeforeModal = '';
  // Persisted across sessions/tabs, same convention as every other
  // sidebar-style toggle in this app (see SHOW_TOOL_DETAILS_KEY etc in
  // session.js) - defaults to off (horizontal-scroll, today's original
  // behavior) when unset.
  var FULLSCREEN_TEXT_WRAP_KEY = 'csm-fullscreen-text-wrap';

  function fullscreenTextWrapEnabled() {
    try {
      return window.localStorage.getItem(FULLSCREEN_TEXT_WRAP_KEY) === '1';
    } catch (e) {
      return false;
    }
  }

  function applyFullscreenTextWrap(wrap) {
    fullscreenTextModalContent.classList.toggle('whitespace-pre', !wrap);
    fullscreenTextModalContent.classList.toggle('whitespace-pre-wrap', wrap);
    fullscreenTextModalContent.classList.toggle('break-words', wrap);

    if (fullscreenTextModalWrapToggle) {
      fullscreenTextModalWrapToggle.textContent = wrap ? 'Wrap: On' : 'Wrap: Off';
      fullscreenTextModalWrapToggle.setAttribute('aria-pressed', wrap ? 'true' : 'false');
    }
  }

  function openFullscreenTextModal(text) {
    fullscreenTextModalContent.textContent = text;
    applyFullscreenTextWrap(fullscreenTextWrapEnabled());
    fullscreenTextModal.classList.remove('hidden');
    // Prevents the page behind the modal from also scrolling on iOS
    // Safari (the modal's own <pre> captures touch-scroll fine on its
    // own, but background scroll can still "leak through" underneath a
    // plain fixed overlay there without this).
    bodyOverflowBeforeModal = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
  }

  function closeFullscreenTextModal() {
    fullscreenTextModal.classList.add('hidden');
    fullscreenTextModalContent.textContent = '';
    document.body.style.overflow = bodyOverflowBeforeModal;
  }

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('.expand-fullscreen-btn');

    if (trigger) {
      var pre = trigger.previousElementSibling;

      if (pre) {
        openFullscreenTextModal(pre.textContent);
      }

      return;
    }

    if (e.target === fullscreenTextModalWrapToggle) {
      var nextWrap = !fullscreenTextWrapEnabled();

      try {
        window.localStorage.setItem(FULLSCREEN_TEXT_WRAP_KEY, nextWrap ? '1' : '0');
      } catch (ignored) {}

      applyFullscreenTextWrap(nextWrap);

      return;
    }

    if (e.target === fullscreenTextModalClose || e.target === fullscreenTextModal) {
      closeFullscreenTextModal();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !fullscreenTextModal.classList.contains('hidden')) {
      closeFullscreenTextModal();
    }
  });
}

// --- fixed-footer height tracking: shared between session.php's compose
// bar and index.php's dashboard footer, both position:fixed at the bottom
// of the viewport with genuinely variable height (the quota box alone is
// user-collapsible AND shows a variable number of lines depending on live
// data, and the push-notify button/mode-toggle rows can each independently
// appear or disappear). A static CSS padding-bottom guess (the class list's
// own pb-* is only the no-ResizeObserver CSS fallback) left the last
// history entries/dashboard rows tucked behind the footer whenever it grew
// taller than that guess - found live on the dashboard too, not just
// session.php, which is why this moved here instead of staying a
// session.js-only fix. ---
function watchFixedFooterHeight(footerEl, onHeightChange) {
  if (!footerEl || !window.ResizeObserver) {
    return;
  }

  new ResizeObserver(function () {
    onHeightChange(footerEl.offsetHeight);
  }).observe(footerEl);
}
