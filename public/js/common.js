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

// Mirrors App\Views\SessionRowView::relative_time() so a poll-refreshed
// timestamp reads the same as the server-rendered one. Shared by
// session.js's own live updates and index.js's search results (moved here
// from session.js 2026-08-20 - a pure function, no reason it couldn't
// serve both).
function relativeTimeLabel(timestamp) {
  var diff = Math.floor(Date.now() / 1000) - timestamp;

  if (diff < 60) return 'just now';
  if (diff < 3600) return Math.floor(diff / 60) + ' min ago';

  if (diff < 86400) {
    var h = Math.floor(diff / 3600);
    return h + ' hr' + (h > 1 ? 's' : '') + ' ago';
  }

  var d = Math.floor(diff / 86400);
  return d + ' day' + (d > 1 ? 's' : '') + ' ago';
}

// Wraps the query itself in <mark> within an already-escaped snippet -
// escaping first, then matching against the SAME escaping applied to the
// query, so a query containing &/</> still lines up with what actually
// appears in the escaped snippet text. $& in the replacement re-inserts
// the exact matched text (preserving its original casing) rather than the
// query's own casing. Shared by session.js's own sidebar search and
// index.js's dashboard-wide search (Andres's own ask, 2026-08-20:
// "highlight the match").
function highlightSnippet(snippet, query) {
  var escapedSnippet = escapeHtml(snippet);
  var escapedQuery = escapeHtml(query).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

  if (escapedQuery === '') {
    return escapedSnippet;
  }

  return escapedSnippet.replace(new RegExp('(' + escapedQuery + ')', 'ig'), '<mark class="bg-amber-400/30 text-amber-200 rounded-sm">$&</mark>');
}

// --- copy-to-clipboard: shared by every ".copy-btn" this app renders
// (transcript text/plan entries, tool_use/tool_result collapsible blocks,
// the fullscreen text modal - see openFullscreenTextModal() below and the
// PHP/JS mirrors that render these buttons: BlockedPromptView::render_
// collapsible_block()/renderCollapsibleBlock(), transcript/block.php/
// renderBlock()). navigator.clipboard requires a secure context (HTTPS or
// localhost) - this app is also reachable over plain HTTP on the LAN (see
// README), where it's simply undefined, so a real fallback (not just a
// silent no-op) matters here, not just for ancient browsers. ---
function copyTextToClipboard(text) {
  if (navigator.clipboard && window.isSecureContext) {
    return navigator.clipboard.writeText(text);
  }

  return new Promise(function (resolve, reject) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    // Off-screen, not display:none/hidden - execCommand('copy') needs the
    // element to actually be selectable, which a non-rendered element isn't.
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.setAttribute('readonly', '');
    document.body.appendChild(textarea);
    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);

    try {
      document.execCommand('copy') ? resolve() : reject(new Error('execCommand(copy) returned false'));
    } catch (e) {
      reject(e);
    } finally {
      document.body.removeChild(textarea);
    }
  });
}

// Finds the text this trigger button is meant to copy - either its own
// nearest <details> (an expandable collapsible block) or the nearest
// ".copy-block" wrapper (every other copy-btn site: non-expandable
// collapsible blocks, transcript text/plan/system entries) - then copies
// its ".copy-source" descendant's real textContent, same "read it straight
// off the rendered element" approach as openFullscreenTextModal() below,
// so copied text can never drift from what's actually shown.
document.addEventListener('click', function (e) {
  var trigger = e.target.closest('.copy-btn');

  if (!trigger) {
    return;
  }

  var container = trigger.closest('details, .copy-block');
  var source = container ? container.querySelector('.copy-source') : null;

  if (!source) {
    return;
  }

  var originalLabel = trigger.textContent;

  copyTextToClipboard(source.textContent).then(function () {
    trigger.textContent = 'Copied!';
  }, function () {
    trigger.textContent = 'Copy failed';
  }).finally(function () {
    setTimeout(function () {
      trigger.textContent = originalLabel;
    }, 1200);
  });
});

// --- full screen text view: a shared "View full screen" affordance for
// any long tool call/output or prompt context that's still hard to read
// even expanded (BlockedPromptView::render_collapsible_block() in PHP,
// renderCollapsibleBlock() in session.js - both share this one modal
// rather than each page rolling its own). Reads the text straight off the
// triggering button's own <details>'s "pre.copy-source" instead of a data
// attribute, so there's no risk of the modal ever showing something
// different from what was actually expanded on the page - same reasoning
// as the ".copy-btn" handler above, and deliberately the same lookup (not
// previousElementSibling) since both buttons now share one footer row. ---
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
      var pre = trigger.closest('details') ? trigger.closest('details').querySelector('.copy-source') : null;

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

    if (e.target.closest('#fullscreen-text-modal-copy')) {
      var copyTrigger = e.target.closest('#fullscreen-text-modal-copy');
      var originalLabel = copyTrigger.textContent;

      copyTextToClipboard(fullscreenTextModalContent.textContent).then(function () {
        copyTrigger.textContent = 'Copied!';
      }, function () {
        copyTrigger.textContent = 'Copy failed';
      }).finally(function () {
        setTimeout(function () {
          copyTrigger.textContent = originalLabel;
        }, 1200);
      });

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

// Shared by session.js/archived-session.js's own "jump to a search
// result" scroll logic - a jump target sitting inside a collapsed
// tool-group (<details class="tool-group">, closed by default - see
// TranscriptView::render_tool_group_html()) is present in the DOM but not
// actually rendered while its <details> stays closed, so its own
// getBoundingClientRect() comes back as if it doesn't exist at all
// (browsers never lay out a closed <details>'s children), making any
// scroll-to-it calculation land on the wrong spot entirely - found live
// 2026-08-20 (Andres: "clicking on a result doesn't go to the right
// one"). Opens every ancestor <details>, not just the immediate one, in
// case of nesting.
function openAncestorDetails(target) {
  var el = target.parentElement;

  while (el) {
    if (el.tagName === 'DETAILS' && !el.open) {
      el.open = true;
    }

    el = el.parentElement;
  }
}

// --- navigation-away loading blanket: covers the iOS edge-swipe-back
// gesture (Andres, 2026-08-08) - going from session.php to the dashboard
// via that gesture left the old page's content sitting frozen on screen
// for however long the browser actually took to swap in the next page,
// unlike a real native-app transition. There's no click handler to hook
// for a swipe gesture the way there is for a normal link tap, so this
// listens for the one event that fires regardless of WHAT triggered the
// navigation - swipe-back, the browser's own back button, a plain link
// tap, all of it. pagehide fires right as the current page is being torn
// down for that navigation, whether it succeeds or not; showing the
// blanket immediately there means it's already covering the screen by the
// time anything visibly changes. ---
var navigationBlanket = document.getElementById('navigation-blanket');

if (navigationBlanket) {
  window.addEventListener('pagehide', function () {
    navigationBlanket.classList.remove('hidden');
  });

  // pagehide ALSO fires when the page is merely being tucked into the
  // bfcache (backgrounding the browser/switching apps can trigger this on
  // iOS, not just a real navigation) - persisted=true on the matching
  // pageshow is what tells the two apart. Without hiding it back here,
  // the blanket would stay stuck over a page the user never actually
  // left, with no real navigation ever coming along to replace it.
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      navigationBlanket.classList.add('hidden');
    }
  });
}
