// @ts-check
// session.php's go-to-bottom/go-to-top floating buttons, plus keeping them
// (and #jump-to-new-btn, owned by highlights.js) correctly positioned
// above the variable-height compose bar footer. Own independent
// document.getElementById() lookups, same convention as common.js - other
// files (session.js, highlights.js) look up the same real DOM elements by
// the same IDs independently rather than this module passing references
// around; a plain global function call (scrollToBottom(), maybeAutoScroll(),
// repositionGoToTopBtn()) is how cross-file calls work here, matching
// escapeHtml()/parseJsonResponse() in common.js. Extracted from session.js
// 2026-08-24 (second cut of the "split session.js into modules" pass).
var pageContent = document.getElementById('page-content');
var goToBottomBtn = document.getElementById('go-to-bottom-btn');
var goToTopBtn = document.getElementById('go-to-top-btn');

var GO_TO_BOTTOM_GAP_PX = 12;
// Matches #go-to-bottom-btn/#jump-to-new-btn's own w-11 h-11 (44px) -
// needed to stack #jump-to-new-btn a full button-height plus gap above
// #go-to-bottom-btn, not just the same gap over the compose bar.
var GO_TO_BOTTOM_BTN_HEIGHT_PX = 44;
var SCROLL_BOTTOM_THRESHOLD_PX = 80;
var SCROLL_TOP_THRESHOLD_PX = 80;

// #page-content no longer needs bottom-padding to clear #compose-bar -
// since the flex-column layout fix (see compose-bar.php's own comment),
// the bar takes real flex space instead of overlaying content. Only
// #go-to-bottom-btn/#jump-to-new-btn/#go-to-top-btn (still position:fixed)
// need tracking, so they keep hovering just above the compose bar's real,
// variable height - #jump-to-new-btn stacked directly above
// #go-to-bottom-btn (Andres's own ask, 2026-08-22), #go-to-top-btn above
// THAT, one more button-height further up whenever #jump-to-new-btn is
// actually shown (Andres's own ask, 2026-08-23 - "the new entry button
// should show up between them") - see repositionGoToTopBtn() below, which
// also re-runs whenever #jump-to-new-btn's own visibility changes on
// scroll (its shown/hidden state isn't driven by footer height at all -
// see highlights.js's updateJumpToNewVisibility(), which calls this
// directly, a plain cross-file global call).
var lastFixedFooterHeight = 0;

function repositionGoToTopBtn() {
  if (!goToTopBtn) {
    return;
  }

  var jumpToNewBtnEl = document.getElementById('jump-to-new-btn');
  var stacked = GO_TO_BOTTOM_GAP_PX + GO_TO_BOTTOM_BTN_HEIGHT_PX + GO_TO_BOTTOM_GAP_PX;

  if (jumpToNewBtnEl && !jumpToNewBtnEl.classList.contains('hidden')) {
    stacked += GO_TO_BOTTOM_BTN_HEIGHT_PX + GO_TO_BOTTOM_GAP_PX;
  }

  goToTopBtn.style.bottom = (lastFixedFooterHeight + stacked) + 'px';
}

watchFixedFooterHeight(document.getElementById('compose-bar'), function (height) {
  lastFixedFooterHeight = height;

  if (goToBottomBtn) {
    goToBottomBtn.style.bottom = (height + GO_TO_BOTTOM_GAP_PX) + 'px';
  }

  var jumpToNewBtnEl = document.getElementById('jump-to-new-btn');

  if (jumpToNewBtnEl) {
    jumpToNewBtnEl.style.bottom = (height + GO_TO_BOTTOM_GAP_PX + GO_TO_BOTTOM_BTN_HEIGHT_PX + GO_TO_BOTTOM_GAP_PX) + 'px';
  }

  repositionGoToTopBtn();
});

// --- scroll-to-bottom: the floating button shows whenever there's more
// page below the viewport, and new content (polled messages, a
// freshly-appeared/updated prompt) only auto-scrolls into view if the
// user was already at the bottom - never yanks them away from history
// they scrolled up to read. ---

// #page-content is the page's own scrolling container (see #app-shell in
// session.php) rather than the whole body, so "near bottom" is measured
// against ITS scrollTop/clientHeight/scrollHeight - no visualViewport
// compensation needed here any more: #app-shell's body is sized with
// 100dvh (a viewport unit that itself shrinks when iOS's on-screen
// keyboard/dynamic toolbar appears), so #page-content's own clientHeight
// already reflects the real visible area without a separate check.
function isNearBottom() {
  return (pageContent.scrollTop + pageContent.clientHeight) >= (pageContent.scrollHeight - SCROLL_BOTTOM_THRESHOLD_PX);
}

function scrollToBottom(smooth) {
  pageContent.scrollTo({ top: pageContent.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
}

function updateGoToBottomVisibility() {
  if (goToBottomBtn) {
    goToBottomBtn.classList.toggle('hidden', isNearBottom());
  }
}

function maybeAutoScroll(wasNearBottom) {
  if (wasNearBottom) {
    scrollToBottom(true);
  }

  updateGoToBottomVisibility();
}

if (goToBottomBtn) {
  pageContent.addEventListener('scroll', updateGoToBottomVisibility, { passive: true });
  goToBottomBtn.addEventListener('click', function () { scrollToBottom(true); });
}

// --- scroll-to-top: the persistent counterpart above (Andres's own ask,
// 2026-08-23) - same "hidden only while already there" treatment as
// #go-to-bottom-btn, its own dedicated threshold (SCROLL_TOP_THRESHOLD_PX,
// not SCROLL_BOTTOM_THRESHOLD_PX reused under a top-facing name) even
// though the two happen to share the same value today, since there's no
// reason the two edges must always stay tuned together. ---
function isNearTop() {
  return pageContent.scrollTop <= SCROLL_TOP_THRESHOLD_PX;
}

function scrollToTop(smooth) {
  pageContent.scrollTo({ top: 0, behavior: smooth ? 'smooth' : 'auto' });
}

function updateGoToTopVisibility() {
  if (goToTopBtn) {
    goToTopBtn.classList.toggle('hidden', isNearTop());
  }
}

if (goToTopBtn) {
  pageContent.addEventListener('scroll', updateGoToTopVisibility, { passive: true });
  goToTopBtn.addEventListener('click', function () { scrollToTop(true); });
}
