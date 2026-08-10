// The dashboard footer's height varies (quota box collapsed/expanded with
// a variable number of live-data lines, the push-notify button shown/
// hidden), so #page-content's bottom padding is tracked live rather than
// left at the static pb-44 CSS fallback - see watchFixedFooterHeight() in
// common.js (shared with session.js's own compose-bar, which has the same
// variable-height problem). Deliberately its own IIFE, not folded into the
// agent-reachability-gated poll IIFE below - the footer renders (and can
// still grow taller than the static fallback) even when the agent is
// unreachable.
(function () {
  watchFixedFooterHeight(document.getElementById('dashboard-footer'), function (height) {
    var pageContent = document.getElementById('page-content');

    if (pageContent) {
      pageContent.style.paddingBottom = (height + 16) + 'px';
    }
  });
})();

// Reassigned once the live-poll IIFE further down actually defines
// pollOnce() (a no-op stub until then, and permanently if the agent is
// unreachable - see there) - lets the answer-prompt/freetext handlers
// above it in this file trigger an immediate re-sync right after a
// successful send, instead of leaving the user looking at a stale
// confirmation note for however long is left on the regular interval
// (up to 15s, if they've picked a slower one).
var requestSessionsPollNow = function () {};

// Answer-prompt buttons (see BlockedPromptView::blocked_prompt_rich_html())
// use data-confirm-label the same way session.php's do - one delegated
// listener here instead of inline onsubmit, since these forms are
// rendered per-row and their count varies with how many sessions are
// currently blocked. AJAX, not a real form submission - answering a
// prompt shouldn't reload the whole dashboard. The dashboard's own live
// poll (sessions_fragment.php, see the IIFE further down) picks up the
// session's new state and replaces this row entirely - a successful
// answer shows a brief confirmation note and immediately requests a poll
// (requestSessionsPollNow()) rather than waiting for the next scheduled
// tick.
// shouldConfirmBeforeAnswer()/parseJsonResponse() are shared with
// session.js - see common.js (loaded before this file).

document.addEventListener('submit', function (e) {
  var form = e.target.closest('form[data-confirm-label]');

  if (!form) {
    return;
  }

  e.preventDefault();

  if (shouldConfirmBeforeAnswer() && !confirm('Send "' + form.dataset.confirmLabel + '" to this session?')) {
    return;
  }

  var container = form.closest('.prompt-options-wrapper') || form.parentElement;
  var buttons = container ? container.querySelectorAll('button') : [];
  buttons.forEach(function (b) { b.disabled = true; });

  fetch('/answer_prompt.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(new FormData(form)).toString()
  })
    .then(function (r) { return parseJsonResponse(r, 'dashboard-answer-prompt'); })
    .then(function (data) {
      if (data && data.ok) {
        if (container) {
          container.innerHTML = '<span class="select-none text-xs text-emerald-400">&#10003; Sent - updating&hellip;</span>';
        }
        requestSessionsPollNow();
      } else {
        alert((data && data.message) || 'Failed to send answer.');
        buttons.forEach(function (b) { b.disabled = false; });
      }
    })
    .catch(function () {
      alert('Network error - answer not sent.');
      buttons.forEach(function (b) { b.disabled = false; });
    });
});

// --- free-text reply (the "Type something." option) - see session.php's
// matching handler; skips the confirm() dialog since revealing the
// textarea is already a deliberate step. A successful send swaps the
// same way the plain-option case above does, requesting an immediate
// poll rather than waiting for the next scheduled tick.
function submitFreetextReply(replyDiv) {
  var wrapper = replyDiv.closest('.prompt-options-wrapper');
  var textarea = replyDiv.querySelector('.freetext-reply-textarea');
  var sendBtn = replyDiv.querySelector('.freetext-reply-send-btn');
  var text = textarea.value;

  if (text.trim() === '') {
    return;
  }

  textarea.disabled = true;
  sendBtn.disabled = true;

  fetch('/answer_prompt.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      session: wrapper.dataset.session,
      csrf_token: wrapper.dataset.csrfToken,
      option: replyDiv.dataset.option,
      text: text
    }).toString()
  })
    .then(function (r) { return parseJsonResponse(r, 'dashboard-answer-prompt-freetext'); })
    .then(function (data) {
      if (data && data.ok) {
        wrapper.innerHTML = '<span class="select-none text-xs text-emerald-400">&#10003; Sent - updating&hellip;</span>';
        requestSessionsPollNow();
      } else {
        alert((data && data.message) || 'Failed to send reply.');
        textarea.disabled = false;
        sendBtn.disabled = false;
      }
    })
    .catch(function () {
      alert('Network error - reply not sent.');
      textarea.disabled = false;
      sendBtn.disabled = false;
    });
}

document.addEventListener('click', function (e) {
  var revealBtn = e.target.closest('.reveal-freetext-btn');

  if (revealBtn) {
    var replyDiv = revealBtn.closest('.prompt-options-wrapper').querySelector('.freetext-reply');
    replyDiv.dataset.option = revealBtn.dataset.option;
    replyDiv.classList.toggle('hidden');

    if (!replyDiv.classList.contains('hidden')) {
      replyDiv.querySelector('.freetext-reply-textarea').focus();
    }

    return;
  }

  var sendBtn = e.target.closest('.freetext-reply-send-btn');

  if (sendBtn) {
    submitFreetextReply(sendBtn.closest('.freetext-reply'));
    return;
  }

  var navBtn = e.target.closest('.nav-prompt-btn');

  if (navBtn) {
    var navWrapper = navBtn.closest('.prompt-options-wrapper');
    navBtn.disabled = true;

    fetch('/session_navigate.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        session: navWrapper.dataset.session,
        csrf_token: navWrapper.dataset.csrfToken,
        direction: navBtn.dataset.direction
      }).toString()
    })
      .then(function (r) { return parseJsonResponse(r, 'dashboard-navigate-prompt'); })
      .then(function (data) {
        navBtn.disabled = false;

        if (!data || !data.ok) {
          alert((data && data.message) || 'Failed to navigate to the other question.');
        }
        // No optimistic swap here (unlike the answer-prompt/freetext
        // handlers above) - the dashboard's own live poll (sessions_fragment.php)
        // picks up the other tab's question/options on its own within a
        // few seconds, same as any other blocked-prompt state change.
      })
      .catch(function () {
        navBtn.disabled = false;
        alert('Network error - could not navigate to the other question.');
      });
  }
});

// Plain Enter inserts a newline everywhere text gets typed in this app -
// only Shift+Enter submits (same convention as session.php's own compose
// box/freetext reply). Used to be the opposite here specifically (plain
// Enter submitted) - changed 2026-08-08 per Andres's own explicit call
// for one consistent rule app-wide, while separately chasing a mobile
// Shift+Enter false-positive (see shiftKeyPhysicallyHeld's own doc
// comment in common.js) that this dashboard reply box was never
// actually vulnerable to itself (it didn't require shiftKey at all
// before), but the inconsistency was the actual thing being flagged.
document.addEventListener('keydown', function (e) {
  if (e.key === 'Enter' && e.shiftKey && shiftKeyPhysicallyHeld && e.target.classList.contains('freetext-reply-textarea')) {
    e.preventDefault();
    submitFreetextReply(e.target.closest('.freetext-reply'));
  }
});

// --- "Take over" a bare (untracked) process - unify-claude-sessions
// plan's phase 6. The confirm() dialog lives inline on the form's own
// onsubmit (see bare-process-row.php, same pattern as the Kill form
// right next to it) - by the time this listener runs, that's already
// been accepted. Two outcomes from the server: a confident match (pid
// already killed and a new session already resumed server-side - just
// redirect), or needs_choice (nothing killed yet - show a picker built
// from the candidates already in the response, no second fetch). ---
(function () {
  function actionButtonsWrapper(row) {
    return row.querySelector('.take-over-form').closest('.flex.flex-col');
  }

  function restoreRow(row) {
    var picker = row.querySelector('.take-over-picker');
    picker.classList.add('hidden');
    picker.innerHTML = '';
    actionButtonsWrapper(row).classList.remove('hidden');
  }

  function renderPicker(row, data) {
    actionButtonsWrapper(row).classList.add('hidden');

    var picker = row.querySelector('.take-over-picker');
    var options = (data.candidates || []).map(function (c) {
      var when = c.last_activity ? new Date(c.last_activity * 1000).toLocaleString() : '';
      var selected = c.claude_session_id === data.suggested_claude_session_id ? ' selected' : '';
      return '<option value="' + escapeHtml(c.claude_session_id) + '"' + selected + '>'
        + escapeHtml(c.title || c.claude_session_id) + ' - ' + escapeHtml(when) + '</option>';
    }).join('');

    if (options === '') {
      picker.innerHTML = '<div class="text-xs text-slate-500">No past conversations found for this working directory.</div>'
        + '<button type="button" class="take-over-cancel-btn mt-2 select-none rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-3 py-1.5">Cancel</button>';
      picker.classList.remove('hidden');
      return;
    }

    picker.innerHTML = '<div class="text-xs text-slate-400 mb-1">Which conversation should this pid resume?</div>'
      + '<select class="take-over-select w-full rounded-lg border border-slate-700 bg-slate-800 px-2 py-1.5 text-xs text-slate-200">' + options + '</select>'
      + '<div class="mt-2 flex gap-2">'
      + '<button type="button" class="take-over-confirm-btn select-none rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-200 text-xs font-medium px-3 py-1.5">Resume</button>'
      + '<button type="button" class="take-over-cancel-btn select-none rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-3 py-1.5">Cancel</button>'
      + '</div>';
    picker.classList.remove('hidden');
    picker.dataset.workdir = data.workdir;
  }

  document.addEventListener('submit', function (e) {
    var form = e.target.closest('.take-over-form');

    if (!form) {
      return;
    }

    e.preventDefault();

    var row = form.closest('[data-bare-row]');
    var btn = form.querySelector('button');
    btn.disabled = true;

    fetch('/take_over_bare.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(new FormData(form)).toString()
    })
      .then(function (r) { return parseJsonResponse(r, 'take-over-bare'); })
      .then(function (data) {
        if (data && data.ok && data.name) {
          window.location.href = '/session.php?session=' + encodeURIComponent(data.name);
          return;
        }

        if (data && data.ok && data.needs_choice) {
          renderPicker(row, data);
          return;
        }

        alert((data && data.message) || 'Failed to take over this process.');
        btn.disabled = false;
      })
      .catch(function () {
        alert('Network error - take-over not started.');
        btn.disabled = false;
      });
  });

  document.addEventListener('click', function (e) {
    var cancelBtn = e.target.closest('.take-over-cancel-btn');

    if (cancelBtn) {
      var cancelRow = cancelBtn.closest('[data-bare-row]');
      restoreRow(cancelRow);
      cancelRow.querySelector('.take-over-form button').disabled = false;
      return;
    }

    var confirmBtn = e.target.closest('.take-over-confirm-btn');

    if (!confirmBtn) {
      return;
    }

    var confirmRow = confirmBtn.closest('[data-bare-row]');
    var picker = confirmRow.querySelector('.take-over-picker');
    var select = picker.querySelector('.take-over-select');
    confirmBtn.disabled = true;

    fetch('/take_over_bare_confirm.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        pid: confirmRow.dataset.pid,
        csrf_token: confirmRow.dataset.csrfToken,
        workdir: picker.dataset.workdir,
        claude_session_id: select.value
      }).toString()
    })
      .then(function (r) { return parseJsonResponse(r, 'take-over-bare-confirm'); })
      .then(function (data) {
        if (data && data.ok && data.name) {
          window.location.href = '/session.php?session=' + encodeURIComponent(data.name);
          return;
        }

        alert((data && data.message) || 'Failed to resume the chosen session.');
        confirmBtn.disabled = false;
      })
      .catch(function () {
        alert('Network error - take-over not completed.');
        confirmBtn.disabled = false;
      });
  });
})();

// "Show last 3 messages" toggle, one per session row. Lazy-loaded on
// first click (via session_history.php, the same endpoint session.php's
// "load more" uses) and cached in the DOM after that - toggling again
// just shows/hides rather than re-fetching.
(function () {
  var ROLE_LABELS = { user: 'User', assistant: 'Assistant', system: 'System' };

  // escapeHtml() is shared with session.js - see common.js.

  // Mirrors TranscriptView::entry_color_kind()/entry_color_classes() - see
  // there for why this isn't just entry.role (a tool_result entry carries
  // role="user" under the hood, same as a real typed message).
  function entryColorKind(entry) {
    var blocks = entry.blocks || [];
    var hasText = blocks.some(function (b) { return b.kind === 'text'; });

    if (!hasText && blocks.length > 0) {
      return 'tool';
    }

    if (entry.role === 'assistant' || entry.role === 'user') {
      return entry.role;
    }

    return 'system';
  }

  function entryColorClasses(kind) {
    switch (kind) {
      case 'user':
        return { border: 'border-indigo-800/60', bg: 'bg-indigo-950/40', label: 'text-indigo-300' };
      case 'assistant':
        return { border: 'border-emerald-800/60', bg: 'bg-emerald-950/40', label: 'text-emerald-300' };
      case 'tool':
        return { border: 'border-sky-800/60', bg: 'bg-sky-950/40', label: 'text-sky-300' };
      default:
        return { border: 'border-slate-800', bg: 'bg-slate-900/50', label: 'text-slate-400' };
    }
  }

  // Only ever called with an assistant/text entry now (see the fetch
  // handler below, which filters to that before rendering anything) - the
  // color/role machinery still runs generically for consistency with
  // session.php, it just always resolves to the same "assistant" look here.
  function renderRecentEntry(entry) {
    var roleLabel = ROLE_LABELS[entry.role] || (entry.role ? escapeHtml(entry.role) : 'System');
    var textBlock = (entry.blocks || []).find(function (b) { return b.kind === 'text'; });
    var text = textBlock ? textBlock.text : '';
    var colors = entryColorClasses(entryColorKind(entry));

    var p = document.createElement('p');
    p.className = 'text-xs text-slate-400 whitespace-pre-wrap break-words rounded border ' + colors.border + ' ' + colors.bg + ' px-1.5 py-1';
    p.innerHTML = '<span class="select-none font-medium ' + colors.label + '">' + roleLabel + ':</span> ' + escapeHtml(text);
    return p;
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.show-recent-btn');

    if (!btn) {
      return;
    }

    var container = btn.nextElementSibling;

    if (btn.dataset.loaded === '1') {
      container.classList.toggle('hidden');
      btn.textContent = container.classList.contains('hidden') ? 'Show last 3 messages' : 'Hide recent messages';
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Loading…';

    // Fetches a bigger page than the 3 actually shown - only agent text
    // replies count (no tool_use/tool_result/user messages), and those
    // can easily be outnumbered within the last handful of raw entries by
    // a run of tool calls, so a plain limit=3 off the raw transcript could
    // come back with zero real replies to show.
    fetch('/session_history.php?session=' + encodeURIComponent(btn.dataset.session) + '&limit=20', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;

        if (!data || !data.ok || !data.entries || data.entries.length === 0) {
          btn.textContent = (data && data.message) || 'No messages to show.';
          return;
        }

        var agentReplies = data.entries.filter(function (entry) {
          return entry.role === 'assistant' && (entry.blocks || []).some(function (b) { return b.kind === 'text'; });
        }).slice(-3);

        if (agentReplies.length === 0) {
          btn.textContent = 'No agent replies to show.';
          return;
        }

        container.innerHTML = '';
        agentReplies.forEach(function (entry) { container.appendChild(renderRecentEntry(entry)); });
        container.classList.remove('hidden');
        btn.dataset.loaded = '1';
        btn.textContent = 'Hide recent messages';
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Network error - try again';
      });
  });
})();

(function () {
  var details = document.getElementById('new-session-details');
  var summary = document.getElementById('new-session-summary');
  var pathEl = document.getElementById('browser_path');
  var listEl = document.getElementById('browser_list');
  var hiddenInput = document.getElementById('workdir_value');
  var submitBtn = document.getElementById('new-session-submit');
  var loaded = false;

  function setStatusRow(text) {
    listEl.innerHTML = '';
    var li = document.createElement('li');
    li.className = 'px-3 py-2 text-slate-500';
    li.textContent = text;
    listEl.appendChild(li);
  }

  function renderRow(label, muted, onClick) {
    var li = document.createElement('li');
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'w-full text-left px-3 py-2 active:bg-slate-700 truncate ' + (muted ? 'text-slate-400' : 'text-slate-100');
    btn.textContent = label;
    btn.addEventListener('click', onClick);
    li.appendChild(btn);
    listEl.appendChild(li);
  }

  function load(path) {
    hiddenInput.value = '';
    submitBtn.disabled = true;
    setStatusRow('Loading…');

    fetch('/browse.php?path=' + encodeURIComponent(path || ''), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          pathEl.textContent = 'Unavailable';
          setStatusRow((data && data.message) || 'Could not load folders.');
          return;
        }

        hiddenInput.value = data.path;
        pathEl.textContent = data.path;
        pathEl.title = data.path;
        submitBtn.disabled = false;
        listEl.innerHTML = '';

        if (data.parent !== null) {
          renderRow('.. (up)', true, function () { load(data.parent); });
        }

        if (data.dirs.length === 0) {
          var li = document.createElement('li');
          li.className = 'px-3 py-2 text-slate-500';
          li.textContent = 'No subfolders here.';
          listEl.appendChild(li);
        }

        data.dirs.forEach(function (dir) {
          renderRow(dir, false, function () { load(data.path + '/' + dir); });
        });
      })
      .catch(function () {
        pathEl.textContent = 'Unavailable';
        setStatusRow('Network error.');
      });
  }

  details.addEventListener('toggle', function () {
    summary.textContent = details.open ? '− Cancel' : '+ New Session';
    summary.classList.toggle('bg-indigo-600', !details.open);
    summary.classList.toggle('active:bg-indigo-700', !details.open);
    summary.classList.toggle('bg-red-900/70', details.open);
    summary.classList.toggle('active:bg-red-800', details.open);

    if (details.open && !loaded) {
      loaded = true;
      load('');
    }
  });
})();

// --- visibility-gated live polling: keeps the session list, bare-process
// list, and header count in sync without a manual refresh - same pattern
// as session.php's own poll (stopped while the tab isn't visible, so a
// backgrounded tab doesn't keep hitting the socket for nobody), sharing
// its localStorage key so a chosen interval carries over between pages. ---
(function () {
  // Whether the host agent was reachable at page-render time (real,
  // render-specific state, not something this static file can know) - set
  // by the small inline bootstrap-data <script> tag index.php renders
  // right before this file is loaded.
  var agentReachable = window.CSM_BOOTSTRAP.agentReachable;
  var sessionsContainer = document.getElementById('sessions-container');
  var bareContainer = document.getElementById('bare-container');
  var countText = document.getElementById('session-count-text');

  if (!agentReachable || !sessionsContainer) {
    return; // nothing to keep live - the "cannot reach host agent" banner is SSR-only
  }

  // POLL_INTERVAL_STORAGE_KEY/POLL_INTERVAL_ALLOWED_MS are shared with
  // session.js - see common.js.
  var pollIntervalMs = (function () {
    try {
      var stored = parseInt(window.localStorage.getItem(POLL_INTERVAL_STORAGE_KEY), 10);
      return POLL_INTERVAL_ALLOWED_MS.indexOf(stored) !== -1 ? stored : 3000;
    } catch (e) {
      return 3000;
    }
  })();

  var pollTimer = null;
  var pollingActive = false;
  var pollAbortController = new AbortController();

  // Skip-if-unchanged, same reasoning as session.php's renderBlockedSection():
  // the common case is a poll landing on data identical to what's already
  // shown, and replacing innerHTML unconditionally would collapse any
  // mid-interaction state (an expanded "Show last 3 messages" panel, a
  // focused free-text reply box) on every single cycle for no reason.
  var lastSessionsHtml = sessionsContainer.innerHTML;
  var lastBareHtml = bareContainer ? bareContainer.innerHTML : null;

  function pollOnce() {
    return fetch('/sessions_fragment.php', { credentials: 'same-origin', signal: pollAbortController.signal })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          return; // agent unreachable this cycle - leave the last-good state on screen rather than blanking it
        }

        if (data.sessions_html !== lastSessionsHtml) {
          sessionsContainer.innerHTML = data.sessions_html;
          lastSessionsHtml = data.sessions_html;
        }

        if (bareContainer && data.bare_html !== lastBareHtml) {
          bareContainer.innerHTML = data.bare_html;
          lastBareHtml = data.bare_html;
        }

        if (countText && typeof data.session_count_html === 'string') {
          countText.innerHTML = data.session_count_html;
        }
      })
      .catch(function () {});
  }

  // Lets the answer-prompt/freetext-reply handlers (defined earlier in
  // this file, before pollOnce exists) trigger an immediate sync right
  // after a successful send - see requestSessionsPollNow's own comment.
  requestSessionsPollNow = pollOnce;

  function startPolling() {
    if (pollingActive) {
      return;
    }

    pollingActive = true;
    pollAbortController = new AbortController();

    function cycle() {
      pollOnce().finally(function () {
        if (pollingActive) {
          pollTimer = setTimeout(cycle, pollIntervalMs);
        }
      });
    }

    cycle();
  }

  function stopPolling() {
    if (!pollingActive) {
      return;
    }

    pollingActive = false;

    if (pollTimer !== null) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }

    pollAbortController.abort();
  }

  window.addEventListener('pagehide', function () {
    pollAbortController.abort();
  });

  var pollIntervalSelect = document.getElementById('poll-interval-select');

  if (pollIntervalSelect) {
    pollIntervalSelect.value = String(pollIntervalMs);

    pollIntervalSelect.addEventListener('change', function () {
      var chosen = parseInt(pollIntervalSelect.value, 10);

      if (POLL_INTERVAL_ALLOWED_MS.indexOf(chosen) === -1) {
        return;
      }

      pollIntervalMs = chosen;

      try {
        window.localStorage.setItem(POLL_INTERVAL_STORAGE_KEY, String(chosen));
      } catch (e) {}

      var wasPolling = pollTimer !== null;
      stopPolling();

      if (wasPolling) {
        startPolling();
      }
    });
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      startPolling();
    } else {
      stopPolling();
    }
  });

  if (document.visibilityState === 'visible') {
    startPolling();
  }
})();

// Archived-sessions toggle - deliberately NOT part of the poll IIFE above:
// fetched once, lazily, only when Andres actually opens it (see
// DashboardController::archivedFragment()'s own doc comment for why a full
// ~/.claude/projects scan has no business running on a timer). Once
// loaded, the search field filters the already-rendered rows client-side
// (a plain substring match against each row's own text) rather than
// round-tripping to the server per keystroke - the real list is small
// enough for that to be instant (see the unify-claude-sessions plan's own
// research: this app's own real ~/.claude/projects had ~160 sessions
// total, trivial for a client-side filter).
(function () {
  var btn = document.getElementById('show-archived-btn');
  var container = document.getElementById('archived-container');

  if (!btn || !container) {
    return;
  }

  var loaded = false;

  function filterArchivedRows() {
    var searchInput = document.getElementById('archived-search');
    var noMatches = document.getElementById('archived-no-matches');
    var rows = container.querySelectorAll('[data-archived-row]');

    if (!searchInput) {
      return;
    }

    var query = searchInput.value.toLowerCase();
    var anyVisible = false;

    rows.forEach(function (row) {
      var matches = row.textContent.toLowerCase().indexOf(query) !== -1;
      row.classList.toggle('hidden', !matches);

      if (matches) {
        anyVisible = true;
      }
    });

    if (noMatches) {
      noMatches.classList.toggle('hidden', anyVisible || rows.length === 0);
    }
  }

  btn.addEventListener('click', function () {
    if (loaded) {
      container.classList.toggle('hidden');
      btn.textContent = container.classList.contains('hidden') ? 'Show archived sessions' : 'Hide archived sessions';

      return;
    }

    btn.disabled = true;
    btn.textContent = 'Loading…';

    fetch('/archived_sessions_fragment.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;

        if (!data || !data.ok) {
          btn.textContent = (data && data.message) || 'Failed to load archived sessions';

          return;
        }

        container.innerHTML = data.archived_html;
        container.classList.remove('hidden');
        loaded = true;
        btn.textContent = 'Hide archived sessions';

        var searchInput = document.getElementById('archived-search');

        if (searchInput) {
          searchInput.addEventListener('input', filterArchivedRows);
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Network error - try again';
      });
  });
})();

// --- dashboard-wide content search: unlike the archived-list filter above
// (a plain client-side substring match against each row's own rendered
// text - title/name/workdir only), this greps every known transcript's
// REAL message content, live and archived alike, server-side (see
// SessionService::search_transcripts()'s own doc comment). Debounced for
// the same reason session.js's own per-session search box is - every
// keystroke round-tripping to a host-agent grep over ~160 real transcripts
// would be real, wasted work. Clicking a result is a full navigation to
// that session with a jump_line param (session.php for a live result -
// session_name is non-null - or archived_session.php otherwise), reusing
// the exact same SSR "load the page ending at this line" path an ordinary
// page load already takes. ---
(function () {
  var input = document.getElementById('dashboard-search-input');
  var results = document.getElementById('dashboard-search-results');

  if (!input || !results) {
    return;
  }

  var debounceTimer = null;
  var abortController = null;

  function renderResults(sessionResults) {
    if (!sessionResults || sessionResults.length === 0) {
      results.innerHTML = '<div class="text-xs text-slate-500 px-1">No matches.</div>';
      return;
    }

    results.innerHTML = sessionResults.map(function (r) {
      var url = r.session_name
        ? '/session.php?session=' + encodeURIComponent(r.session_name) + '&jump_line=' + encodeURIComponent(r.matches[0].line)
        : '/archived_session.php?claude_session_id=' + encodeURIComponent(r.claude_session_id) + '&jump_line=' + encodeURIComponent(r.matches[0].line);

      var matchesHtml = r.matches.map(function (m) {
        var roleLabel = m.role === 'user' ? 'You' : (m.role === 'assistant' ? 'Claude' : (m.kind === 'tool_use' ? 'Tool call' : 'Tool output'));
        return '<div class="text-xs text-slate-400 mt-1"><span class="text-slate-500">' + escapeHtml(roleLabel) + ':</span> ' + escapeHtml(m.snippet) + '</div>';
      }).join('');

      return '<a href="' + url + '" class="block rounded-xl border border-slate-800 bg-slate-900/50 active:bg-slate-800 px-3 py-2">'
        + '<div class="flex items-center gap-1.5 text-sm font-medium text-slate-200 truncate">'
        + escapeHtml(r.title)
        + (r.session_name ? ' <span class="shrink-0 text-[10px] font-normal text-emerald-400 border border-emerald-800/60 rounded-full px-1.5 py-0.5">live</span>' : '')
        + '</div>'
        + matchesHtml
        + '</a>';
    }).join('');
  }

  input.addEventListener('input', function () {
    var query = input.value.trim();

    clearTimeout(debounceTimer);

    if (abortController) {
      abortController.abort();
    }

    if (query === '') {
      results.innerHTML = '';
      return;
    }

    debounceTimer = setTimeout(function () {
      abortController = new AbortController();

      fetch('/search_sessions.php?q=' + encodeURIComponent(query), { credentials: 'same-origin', signal: abortController.signal })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) {
            results.innerHTML = '<div class="text-xs text-red-400 px-1">' + escapeHtml((data && data.message) || 'Search failed.') + '</div>';
            return;
          }

          renderResults(data.results);
        })
        .catch(function (e) {
          if (e && e.name === 'AbortError') {
            return;
          }

          results.innerHTML = '<div class="text-xs text-red-400 px-1">Network error - search failed.</div>';
        });
    }, 400);
  });
})();
