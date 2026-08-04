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
