// The archived (dormant) session read-only view's only interactive piece -
// "Load older messages". Deliberately much smaller than session.js: no
// compose bar, no live polling, no mode toggle - a dormant session never
// changes, so there's nothing else here that needs to stay "live". Unlike
// session.php's own load-more (which fetches raw JSON entries and renders
// them client-side via session.js's own renderEntry(), since a live view
// needs that same renderer for polled-in new messages too), this fetches
// pre-rendered HTML straight from the server (see SessionController::
// archivedHistoryFragment()) - no client-side rendering logic to duplicate
// at all.
(function () {
  var btn = document.getElementById('load-more-btn');
  var list = document.getElementById('history-list');

  if (!btn || !list) {
    return;
  }

  btn.addEventListener('click', function () {
    var claudeSessionId = btn.dataset.claudeSessionId;
    var before = btn.dataset.before;

    btn.disabled = true;
    btn.textContent = 'Loading…';

    var url = '/archived_session_history_fragment.php?claude_session_id=' + encodeURIComponent(claudeSessionId) + '&limit=30'
      + (before ? '&before=' + encodeURIComponent(before) : '');

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          btn.textContent = (data && data.message) || 'Could not load more.';
          return;
        }

        var wrapper = document.createElement('div');
        wrapper.innerHTML = data.html;

        while (wrapper.firstChild) {
          list.insertBefore(wrapper.firstChild, list.firstChild);
        }

        if (data.has_more && data.next_before !== null) {
          btn.dataset.before = data.next_before;
          btn.disabled = false;
          btn.textContent = 'Load older messages';
        } else {
          btn.classList.add('hidden');
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Network error - try again';
      });
  });
})();
