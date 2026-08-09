(function () {
  var infoBox = document.getElementById('session-info');
  var headerTitle = document.getElementById('header-title');

  if (!infoBox) {
    return; // session not found - nothing here to wire up
  }

  // Session-specific values that vary per page load (real transcript state,
  // not something this static file can know) - set by the small inline
  // bootstrap-data <script> tag session.php renders right before this file
  // is loaded.
  var sessionName = window.CSM_BOOTSTRAP.session;
  var csrfToken = window.CSM_BOOTSTRAP.csrfToken;
  var btn = document.getElementById('load-more-btn');
  var list = document.getElementById('history-list');
  var thinkingIndicator = document.getElementById('thinking-indicator');
  var blockedSection = document.getElementById('blocked-prompt-section');
  var goToBottomBtn = document.getElementById('go-to-bottom-btn');
  var composeBar = document.getElementById('compose-bar');
  var composeInputRow = document.getElementById('compose-input-row');
  var composeBlockedNote = document.getElementById('compose-blocked-note');
  var newestLine = window.CSM_BOOTSTRAP.newestLine;
  // /clear, /compact, --resume, and --fork-session all rotate Claude
  // Code's own transcript to a brand new session-id file while staying in
  // the same tmux pane (see host-agent/hooks/session_start.php) - none of
  // them ever appear as an entry INSIDE a transcript (there's nothing to
  // parse for), so a rotation is only detectable by this id changing
  // between polls. null means "not known yet" (e.g. a session_detail.php
  // call errors before this is ever set) - deliberately never treated as
  // a change on its own, only a real id -> a DIFFERENT real id is.
  var currentClaudeSessionId = window.CSM_BOOTSTRAP.claudeSessionId || null;

  // --- optimistic UI state: entries appended locally right after sending,
  // before a poll has confirmed they actually landed. See appendPendingEntry()
  // and pollHistory() below for the append/reconcile lifecycle, and
  // markBlockedSectionAnswerPending()/renderBlockedSection() for the
  // matching "answered, waiting to confirm" treatment on the blocked-prompt
  // card itself. ---
  var pendingEntries = [];
  var currentBlockedReason = null;
  var answerPendingReason = null;
  // The pending history bubble for a just-submitted prompt answer - unlike
  // a compose message (real text, closely matches its eventual transcript
  // entry), an answer's confirmed entry likely isn't literally the button
  // label text, so reconcilePendingEntries()'s content-matching may never
  // find it - found live: it stayed dimmed "Sending…" forever even once
  // the prompt itself had genuinely resolved. Tied directly to
  // answerPendingReason instead, in renderBlockedSection() below: the
  // prompt actually resolving is a far more reliable confirmation signal
  // for THIS entry specifically than generic content-matching.
  var answerPendingHistoryEl = null;
  var lastRenderedBlockedKey; // undefined, not null - see renderBlockedSection()
  var lastRenderedThinkingShown; // undefined, not null - see renderThinkingIndicator()
  var lastRenderedStaticInfoKey; // undefined, not null - see renderStaticInfo()

  // Mirrors the $composeBlocked SSR toggle above - hides the message
  // input (not the whole compose bar; quota/mode stay visible) while a
  // prompt is pending, forcing it to be answered first. The textarea
  // itself is only hidden via CSS, never removed from the DOM, so
  // whatever's been typed survives a prompt appearing mid-draft.
  function renderComposeVisibility(detail) {
    if (!composeInputRow || !composeBlockedNote) {
      return;
    }

    var wasHidden = composeInputRow.classList.contains('hidden');
    var isBlocked = !!detail.blocked_reason;

    composeInputRow.classList.toggle('hidden', isBlocked);
    composeBlockedNote.classList.toggle('hidden', !isBlocked);

    // Andres reported the textarea sometimes coming back too short (a
    // sliver, not its normal auto-grown height) right after a mid-typing
    // prompt cleared. The auto-grown height is a plain inline style on
    // #compose-textarea, unaffected in principle by the row's own
    // display:none while hidden - but autoGrowCompose() (defined further
    // down, in scope here via the enclosing IIFE) is only ever invoked by
    // direct user interaction (typing, sending, attaching), never on this
    // row becoming visible again, so nothing re-measures it at that point.
    // Re-running it right on the hidden->visible transition is a cheap,
    // self-correcting fix regardless of the exact browser mechanism that
    // left the stale height behind.
    if (wasHidden && !isBlocked && typeof autoGrowCompose === 'function') {
      autoGrowCompose();
    }
  }

  // The compose bar's height varies (quota footer collapsed/expanded, textarea
  // auto-grow, push-notify button shown/hidden), so both the floating button's
  // offset AND the page content's bottom padding are tracked live rather than
  // fixed - a static pb-44 (the CSS fallback for no-ResizeObserver browsers)
  // was tuned for the common case and left the last history entries tucked
  // behind the compose bar whenever it grew taller than that guess (e.g. the
  // quota footer expanded to 3 lines). watchFixedFooterHeight() is shared
  // with index.js/common.js - index.php's own dashboard footer has the exact
  // same variable-height problem.
  var pageContent = document.getElementById('page-content');
  var GO_TO_BOTTOM_GAP_PX = 12;
  var PAGE_CONTENT_GAP_PX = 16;

  watchFixedFooterHeight(composeBar, function (height) {
    if (goToBottomBtn) {
      goToBottomBtn.style.bottom = (height + GO_TO_BOTTOM_GAP_PX) + 'px';
    }
    if (pageContent) {
      pageContent.style.paddingBottom = (height + PAGE_CONTENT_GAP_PX) + 'px';
    }
  });

  // --- slideable sidebar: other sessions' status/prompt, fetched fresh each
  // time it's opened rather than polled continuously in the background. ---

  var sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
  var sidebarCloseBtn = document.getElementById('sidebar-close-btn');
  var sidebarOverlay = document.getElementById('sidebar-overlay');
  var sidebar = document.getElementById('sidebar');
  var sidebarList = document.getElementById('sidebar-list');
  var sidebarNotifyDot = document.getElementById('sidebar-notify-dot');

  // Setting: whether answering a plain prompt option asks for confirmation
  // first. CONFIRM_BEFORE_ANSWER_KEY/shouldConfirmBeforeAnswer() are shared
  // with index.js - see common.js. Shared localStorage key with index.php's
  // dashboard rows, which answer prompts too but have no sidebar of their
  // own to host the checkbox.
  var confirmBeforeAnswerToggle = document.getElementById('confirm-before-answer-toggle');

  if (confirmBeforeAnswerToggle) {
    confirmBeforeAnswerToggle.checked = shouldConfirmBeforeAnswer();

    confirmBeforeAnswerToggle.addEventListener('change', function () {
      try {
        window.localStorage.setItem(CONFIRM_BEFORE_ANSWER_KEY, confirmBeforeAnswerToggle.checked ? '1' : '0');
      } catch (e) {}
    });
  }

  // Setting: whether subagent call/report blocks show in the transcript at
  // all - a body-level class + CSS rule (see <style> in <head>) so it
  // applies to blocks the poll renders later too, without re-walking the
  // DOM. Per-session (keyed by sessionName, same pattern as COMPOSE_DRAFT_KEY
  // below) - a global key meant one session's "hide subagent output" choice
  // silently applied to every other session too, which read as broken when
  // a different session's own output just wasn't showing. Regular
  // (non-subagent) tool calls/outputs don't use this any more - since
  // 2026-08-08 those are always grouped into a collapsible "N tool calls"
  // run instead (see groupToolCalls() below), whose own <details> is its
  // own show/hide affordance. The two separate call/output toggles this
  // used to be also merged into this one, same date - a subagent call and
  // its own report are little enough traffic that splitting them wasn't
  // worth the extra checkbox.
  var SHOW_SUBAGENT_KEY = 'csm-show-subagent-' + sessionName;

  function shouldShowSubagent() {
    try {
      return window.localStorage.getItem(SHOW_SUBAGENT_KEY) !== '0';
    } catch (e) {
      return true;
    }
  }

  function applyShowSubagent(show) {
    document.body.classList.toggle('hide-subagent', !show);
  }

  var showSubagentToggle = document.getElementById('show-subagent-toggle');

  if (showSubagentToggle) {
    var showSubagent = shouldShowSubagent();
    showSubagentToggle.checked = showSubagent;
    applyShowSubagent(showSubagent);

    showSubagentToggle.addEventListener('change', function () {
      applyShowSubagent(showSubagentToggle.checked);

      try {
        window.localStorage.setItem(SHOW_SUBAGENT_KEY, showSubagentToggle.checked ? '1' : '0');
      } catch (e) {}
    });
  }

  // Lets the toggle button itself signal severity without opening the
  // drawer: amber if another session is blocked (waiting on a prompt) -
  // always live, never "seen" since it's still actionable right now; else
  // emerald if another session just finished all its work (went idle) and
  // that finish hasn't been observed yet (see markOthersSeen()); else no
  // dot at all. Persisted per-session state (SIDEBAR_SESSION_STATE_KEY)
  // is what lets "just finished" survive across poll cycles until the
  // sidebar is actually opened and looked at, and what stops an idle
  // session that's simply always been idle from lighting up green on
  // first-ever observation (a transition has to be detected, not just a
  // state).
  var SIDEBAR_SESSION_STATE_KEY = 'csm-sidebar-session-state';

  function readSidebarSessionState() {
    try {
      var raw = window.localStorage.getItem(SIDEBAR_SESSION_STATE_KEY);
      return raw ? JSON.parse(raw) : {};
    } catch (e) {
      return {};
    }
  }

  function writeSidebarSessionState(state) {
    try {
      window.localStorage.setItem(SIDEBAR_SESSION_STATE_KEY, JSON.stringify(state));
    } catch (e) {}
  }

  function otherSessionState(s) {
    if (s.blocked_reason) {
      return 'blocked';
    }
    if (s.working) {
      return 'working';
    }
    return 'idle';
  }

  function applySidebarNotifyDot(kind) {
    if (!sidebarNotifyDot) {
      return;
    }
    sidebarNotifyDot.classList.toggle('hidden', kind === null);
    sidebarNotifyDot.classList.toggle('bg-amber-400', kind === 'blocked');
    sidebarNotifyDot.classList.toggle('bg-emerald-400', kind === 'finished');
  }

  // Shared by refreshSidebarNotification() (every poll cycle, markSeen
  // false) and loadSidebarList() (sidebar actually opened, markSeen true -
  // that's the "look" that clears the green dot for any idle session it
  // just displayed).
  function processOtherSessions(others, markSeen) {
    var stored = readSidebarSessionState();
    var next = {};
    var anyBlocked = false;
    var anyUnseenFinished = false;

    others.forEach(function (s) {
      var state = otherSessionState(s);
      var prev = stored[s.name];
      var unseen = !!(prev && prev.unseen);

      if (state === 'idle') {
        if (prev && prev.state !== 'idle') {
          unseen = true;
        }
        if (markSeen) {
          unseen = false;
        }
      } else {
        unseen = false;
        if (state === 'blocked') {
          anyBlocked = true;
        }
      }

      next[s.name] = { state: state, unseen: unseen };

      if (state === 'idle' && unseen) {
        anyUnseenFinished = true;
      }
    });

    writeSidebarSessionState(next);
    applySidebarNotifyDot(anyBlocked ? 'blocked' : (anyUnseenFinished ? 'finished' : null));
  }

  function refreshSidebarNotification() {
    if (!sidebarNotifyDot) {
      return Promise.resolve();
    }

    return fetch('/sessions_list.php', { credentials: 'same-origin', signal: pollAbortController.signal })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) {
          return;
        }

        var others = (data.sessions || []).filter(function (s) { return s.name !== sessionName; });
        processOtherSessions(others, false);
      })
      .catch(function () {});
  }

  function sidebarStatusBadge(s) {
    if (s.blocked_reason) {
      return '<span class="inline-block px-1.5 py-0.5 rounded text-xs bg-amber-900/60 text-amber-300">waiting</span>';
    }
    if (s.working) {
      return '<span class="inline-block px-1.5 py-0.5 rounded text-xs bg-indigo-900/60 text-indigo-300">working</span>';
    }
    return '<span class="inline-block px-1.5 py-0.5 rounded text-xs bg-slate-800 text-slate-400">' + (s.attached ? 'attached' : 'detached') + '</span>';
  }

  function sidebarRowHtml(s) {
    var label = s.title || s.name;
    var sub = s.blocked_reason
      ? s.blocked_reason
      : (s.last_message && s.last_message.blocks && s.last_message.blocks[0] ? s.last_message.blocks[0].text : '');
    var subHtml = sub ? '<div class="text-xs text-slate-500 mt-0.5 line-clamp-2">' + escapeHtml(sub) + '</div>' : '';
    return (
      '<a href="/session.php?session=' + encodeURIComponent(s.name) + '" class="block px-4 py-3 active:bg-slate-800">' +
      '<div class="flex items-center justify-between gap-2">' +
      '<span class="text-slate-200 truncate">' + escapeHtml(label) + '</span>' +
      sidebarStatusBadge(s) +
      '</div>' +
      subHtml +
      '</a>'
    );
  }

  function loadSidebarList() {
    sidebarList.innerHTML = '<div class="px-4 py-3 text-slate-500">Loading&hellip;</div>';
    fetch('/sessions_list.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) {
          sidebarList.innerHTML = '<div class="px-4 py-3 text-slate-500">Could not load sessions.</div>';
          return;
        }
        var others = (data.sessions || []).filter(function (s) { return s.name !== sessionName; });
        // Opening the sidebar IS "looking" - clears the green (finished,
        // unseen) dot for anything it's about to display.
        processOtherSessions(others, true);
        if (others.length === 0) {
          sidebarList.innerHTML = '<div class="px-4 py-3 text-slate-500">No other sessions.</div>';
          return;
        }
        sidebarList.innerHTML = others.map(sidebarRowHtml).join('');
      })
      .catch(function () {
        sidebarList.innerHTML = '<div class="px-4 py-3 text-slate-500">Could not load sessions.</div>';
      });
  }

  // --- uploaded files: the sidebar's own list of whatever's been
  // uploaded for THIS session (see upload_file.php/the compose "+"
  // button) - name, size, a running total, and per-file/all-at-once
  // delete. Refreshed on sidebar open (like the other-sessions list
  // above) AND on every regular poll cycle while the sidebar stays open
  // (see pollOnce() below) - unlike other-sessions, which only needs a
  // fresh look each time you open it, files can change from an upload
  // still in flight or a delete just clicked, and Andres wants to see
  // that reflected without having to close/reopen the sidebar. ---
  var uploadedFilesList = document.getElementById('uploaded-files-list');
  var uploadedFilesTotal = document.getElementById('uploaded-files-total');
  var deleteAllUploadsBtn = document.getElementById('delete-all-uploads-btn');
  var planFilesList = document.getElementById('plan-files-list');

  function formatFileSize(bytes) {
    if (bytes < 1024) {
      return bytes + ' B';
    }
    if (bytes < 1024 * 1024) {
      return (bytes / 1024).toFixed(1) + ' KB';
    }
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function uploadedFileRowHtml(f) {
    var name = escapeHtml(f.name);
    return '<div class="flex items-center justify-between gap-2">'
      + '<span class="truncate text-slate-300" title="' + name + '">' + name + '</span>'
      + '<span class="shrink-0 flex items-center gap-2">'
      + '<span class="text-xs text-slate-500">' + formatFileSize(f.size) + '</span>'
      + '<button type="button" class="delete-upload-btn text-slate-500 active:text-red-400 text-base leading-none px-1" data-filename="' + name + '" aria-label="Delete ' + name + '">&times;</button>'
      + '</span>'
      + '</div>';
  }

  function loadUploadedFiles() {
    if (!uploadedFilesList) {
      return Promise.resolve();
    }

    return fetch('/uploaded_files.php?session=' + encodeURIComponent(sessionName), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          uploadedFilesList.innerHTML = '<div class="text-slate-500 text-xs">Could not load files.</div>';
          uploadedFilesTotal.textContent = '';
          deleteAllUploadsBtn.classList.add('hidden');
          return;
        }

        var files = data.files || [];

        if (files.length === 0) {
          uploadedFilesList.innerHTML = '<div class="text-slate-500 text-xs">No files uploaded.</div>';
          uploadedFilesTotal.textContent = '';
          deleteAllUploadsBtn.classList.add('hidden');
          return;
        }

        uploadedFilesList.innerHTML = files.map(uploadedFileRowHtml).join('');
        uploadedFilesTotal.textContent = formatFileSize(data.total_size || 0) + ' total';
        deleteAllUploadsBtn.classList.remove('hidden');
      })
      .catch(function () {
        uploadedFilesList.innerHTML = '<div class="text-slate-500 text-xs">Could not load files.</div>';
      });
  }

  // Sidebar "Plan/handoff files" glance (Andres's own idea, 2026-08-08) -
  // a read-only listing of *.md files sitting directly in this session's
  // own cwd (README.md/CLAUDE.md excluded server-side - see
  // SessionService::list_plan_files()), so ad-hoc plan docs/handoff
  // prompts don't go unnoticed once stale. No delete action here on
  // purpose - cleanup stays manual.
  function planFileRowHtml(f) {
    var name = escapeHtml(f.name);
    return '<div class="flex items-center justify-between gap-2">'
      + '<span class="truncate text-slate-300" title="' + name + '">' + name + '</span>'
      + '<span class="shrink-0 text-xs text-slate-500">' + escapeHtml(relativeTimeLabel(f.mtime)) + '</span>'
      + '</div>';
  }

  function loadPlanFiles() {
    if (!planFilesList) {
      return Promise.resolve();
    }

    return fetch('/session_plan_files.php?session=' + encodeURIComponent(sessionName), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          planFilesList.innerHTML = '<div class="text-slate-500 text-xs">Could not load files.</div>';
          return;
        }

        var files = data.files || [];

        if (files.length === 0) {
          planFilesList.innerHTML = '<div class="text-slate-500 text-xs">No plan/handoff files found.</div>';
          return;
        }

        planFilesList.innerHTML = files.map(planFileRowHtml).join('');
      })
      .catch(function () {
        planFilesList.innerHTML = '<div class="text-slate-500 text-xs">Could not load files.</div>';
      });
  }

  if (uploadedFilesList) {
    uploadedFilesList.addEventListener('click', function (e) {
      var btn = e.target.closest('.delete-upload-btn');

      if (!btn) {
        return;
      }

      btn.disabled = true;

      fetch('/delete_uploaded_file.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken, filename: btn.dataset.filename }).toString()
      })
        .then(function (r) { return parseJsonResponse(r, 'delete-uploaded-file'); })
        .then(function (data) {
          if (data && data.ok) {
            loadUploadedFiles();
          } else {
            btn.disabled = false;
            alert((data && data.message) || 'Failed to delete file.');
          }
        })
        .catch(function () {
          btn.disabled = false;
          alert('Network error - file not deleted.');
        });
    });
  }

  if (deleteAllUploadsBtn) {
    deleteAllUploadsBtn.addEventListener('click', function () {
      if (!confirm('Delete all uploaded files for this session?')) {
        return;
      }

      deleteAllUploadsBtn.disabled = true;

      fetch('/delete_all_uploaded_files.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken }).toString()
      })
        .then(function (r) { return parseJsonResponse(r, 'delete-all-uploaded-files'); })
        .then(function (data) {
          deleteAllUploadsBtn.disabled = false;

          if (data && data.ok) {
            loadUploadedFiles();
          } else {
            alert((data && data.message) || 'Failed to delete files.');
          }
        })
        .catch(function () {
          deleteAllUploadsBtn.disabled = false;
          alert('Network error - files not deleted.');
        });
    });
  }

  function openSidebar() {
    sidebarOverlay.classList.remove('hidden');
    sidebar.classList.remove('translate-x-full');
    // The sidebar is its own independently-scrollable element (#sidebar
    // has overflow-y-auto), not tied to the main page's scroll position -
    // but without this, it opens wherever its OWN scrollTop last was left
    // (e.g. still scrolled down from a previous open), reading as "opens
    // at the bottom" if the main page also happened to be scrolled far
    // down when it was last closed. Always starts fresh at the top.
    sidebar.scrollTop = 0;
    loadSidebarList();
    loadUploadedFiles();
    loadPlanFiles();
  }

  function closeSidebar() {
    sidebarOverlay.classList.add('hidden');
    sidebar.classList.add('translate-x-full');
  }

  if (sidebarToggleBtn) {
    sidebarToggleBtn.addEventListener('click', openSidebar);
    sidebarCloseBtn.addEventListener('click', closeSidebar);
    sidebarOverlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeSidebar();
      }
    });
  }

  // --- swipe gestures (touch devices): swipe left anywhere opens the
  // sidebar (it slides in from the right, so this matches "pulling" it
  // into view), swipe right closes it if it's open, else goes back to
  // the dashboard. Ignored for anything that isn't a clearly horizontal
  // gesture, so it doesn't fight with normal vertical scrolling. ---
  if (sidebarToggleBtn) {
    var SWIPE_MIN_DISTANCE_PX = 80;
    var SWIPE_MAX_VERTICAL_RATIO = 0.5;
    var touchStartX = null;
    var touchStartY = null;

    // A non-collapsed selection means this touch is (or might become)
    // dragging a text-selection handle, not swiping - those handles are
    // native OS chrome, not real DOM elements, so there's no element to
    // target-check the way the scrollable-block case below does; checking
    // the selection itself is the only reliable signal. Checked on both
    // touchstart (the selection already exists from an earlier long-press)
    // and touchend (in case it changed mid-touch), since real devices vary
    // in whether those are the same touch sequence or two separate ones.
    function touchTargetsActiveSelection() {
      var selection = window.getSelection();
      return !!selection && !selection.isCollapsed;
    }

    // window.getSelection() above never sees a selection inside a
    // <textarea> (#compose-textarea, the free-text prompt-reply textarea)
    // - form controls keep their own separate selectionStart/selectionEnd
    // state, invisible to the document-level Selection API - so a swipe-
    // to-select drag starting there used to fall straight through to the
    // sidebar/back-navigation gesture instead. Any touch landing on a
    // textarea at all is excluded here, not just an active-selection one:
    // that gesture belongs to the textarea (caret placement, selecting,
    // scrolling a tall one), never to the app-level swipe.
    function touchTargetsTextarea(e) {
      return !!(e.target.closest && e.target.closest('textarea'));
    }

    document.addEventListener('touchstart', function (e) {
      // Ignore touches starting inside a horizontally-scrollable command/
      // output block - that gesture is for scrolling the block itself,
      // not for opening/closing the sidebar.
      if (e.touches.length !== 1 || (e.target.closest && e.target.closest('.overflow-x-auto, .overflow-auto')) || touchTargetsActiveSelection() || touchTargetsTextarea(e)) {
        touchStartX = null;
        touchStartY = null;
        return;
      }

      touchStartX = e.touches[0].clientX;
      touchStartY = e.touches[0].clientY;
    }, { passive: true });

    document.addEventListener('touchend', function (e) {
      if (touchStartX === null || e.changedTouches.length !== 1 || touchTargetsActiveSelection() || touchTargetsTextarea(e)) {
        touchStartX = null;
        touchStartY = null;
        return;
      }

      var deltaX = e.changedTouches[0].clientX - touchStartX;
      var deltaY = e.changedTouches[0].clientY - touchStartY;
      touchStartX = null;
      touchStartY = null;

      if (Math.abs(deltaX) < SWIPE_MIN_DISTANCE_PX || Math.abs(deltaY) > Math.abs(deltaX) * SWIPE_MAX_VERTICAL_RATIO) {
        return;
      }

      var sidebarOpen = !sidebar.classList.contains('translate-x-full');

      if (deltaX < 0) {
        if (!sidebarOpen) {
          openSidebar();
        }
      } else if (sidebarOpen) {
        closeSidebar();
      } else {
        window.location.href = '/';
      }
    }, { passive: true });
  }

  var ROLE_LABELS = { user: 'User', assistant: 'Assistant', system: 'System' };
  var SCROLL_BOTTOM_THRESHOLD_PX = 80;

  // Polling interval: user-selectable (dropdown in the sticky header, 1/3/5/
  // 10/15s), persisted per-browser. Defaults to 3s.
  // POLL_INTERVAL_STORAGE_KEY/POLL_INTERVAL_ALLOWED_MS are shared with
  // index.js - see common.js.
  var pollIntervalMs = (function () {
    try {
      var stored = parseInt(window.localStorage.getItem(POLL_INTERVAL_STORAGE_KEY), 10);
      return POLL_INTERVAL_ALLOWED_MS.indexOf(stored) !== -1 ? stored : 3000;
    } catch (e) {
      return 3000;
    }
  })();

  // escapeHtml()/parseJsonResponse() are shared with index.js - see common.js.

  // --- scroll-to-bottom: the floating button shows whenever there's more
  // page below the viewport, and new content (polled messages, a
  // freshly-appeared/updated prompt) only auto-scrolls into view if the
  // user was already at the bottom - never yanks them away from history
  // they scrolled up to read. ---

  // window.innerHeight doesn't shrink when the on-screen keyboard opens on
  // iOS Safari - the layout viewport stays full-height while the keyboard
  // visually covers the bottom portion of it, so window.innerHeight +
  // window.scrollY can claim "near bottom" even while the compose bar is
  // actually hidden behind the keyboard. Found live: that false positive
  // is what made maybeAutoScroll() below pull the page back to the
  // (keyboard-covered) bottom on a later poll, with nothing actually typed
  // to explain it. visualViewport tracks the real visible area - its
  // height genuinely shrinks with the keyboard, and pageTop already
  // accounts for scroll (comparable directly to scrollHeight, no separate
  // + scrollY needed) - falls back to the old calculation on anything
  // without visualViewport support.
  function isNearBottom() {
    if (window.visualViewport) {
      return (window.visualViewport.pageTop + window.visualViewport.height) >= (document.documentElement.scrollHeight - SCROLL_BOTTOM_THRESHOLD_PX);
    }

    return (window.innerHeight + window.scrollY) >= (document.documentElement.scrollHeight - SCROLL_BOTTOM_THRESHOLD_PX);
  }

  function scrollToBottom(smooth) {
    window.scrollTo({ top: document.documentElement.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
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
    window.addEventListener('scroll', updateGoToBottomVisibility, { passive: true });
    goToBottomBtn.addEventListener('click', function () { scrollToBottom(true); });
  }

  // Mirrors App\Views\SessionRowView::relative_time() so a poll-refreshed
  // timestamp reads the same as the server-rendered one.
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

  // Mirrors BlockedPromptView::collapsible_summary().
  function collapsibleSummary(text) {
    var trimmed = text.trim();
    var firstLine = trimmed.split('\n', 1)[0];
    var summary = firstLine.length > 80 ? firstLine.slice(0, 80) + '…' : firstLine;
    return summary + (trimmed.length > summary.length ? ' …' : '');
  }

  // Mirrors BlockedPromptView::render_collapsible_block() - tool commands/
  // output default to collapsed (a <details>, no JS needed to expand/
  // collapse), except trivial content (short, single line - the summary
  // would show it in full anyway), which skips the wrapper entirely.
  function renderCollapsibleBlock(rawText, borderClass, textClass, prefix, forceOpen) {
    var trimmed = rawText.trim();
    var summary = collapsibleSummary(rawText);
    var full = escapeHtml(rawText);

    if (summary === trimmed) {
      return '<div class="rounded border ' + borderClass + ' bg-slate-950/60 overflow-x-auto px-2 py-1.5 text-xs ' + textClass + '"><span class="whitespace-pre">' + prefix + full + '</span></div>';
    }

    var summaryHtml = escapeHtml(summary);

    return '<details' + (forceOpen ? ' open' : '') + ' class="rounded border ' + borderClass + ' bg-slate-950/60">'
      + '<summary class="block w-full text-left cursor-pointer select-none whitespace-pre-wrap break-all px-2 py-1.5 text-xs ' + textClass + '">' + prefix + summaryHtml + '</summary>'
      + '<pre class="whitespace-pre overflow-auto overscroll-contain max-h-64 px-2 pb-1.5 text-xs ' + textClass + '">' + full + '</pre>'
      + '<button type="button" class="expand-fullscreen-btn select-none block w-full text-center text-[11px] text-slate-500 active:text-slate-300 border-t border-slate-800 py-1">View full screen</button>'
      + '</details>';
  }

  // Mirrors TranscriptView::render_transcript_image_html() (PHP).
  function renderImageHtml(image) {
    var mediaType = escapeHtml(image.media_type);
    var data = escapeHtml(image.data);

    return '<img src="data:' + mediaType + ';base64,' + data + '" loading="lazy" alt="Image" class="transcript-image mt-1.5 rounded border border-slate-800 cursor-pointer w-24 h-24 object-cover">';
  }

  // Mirrors TranscriptView::attachment_url() (PHP).
  function attachmentUrl(line, fileUuid) {
    return '/session_attachment.php?session=' + encodeURIComponent(sessionName) + '&line=' + line + '&file_uuid=' + encodeURIComponent(fileUuid);
  }

  // Mirrors TranscriptView::render_transcript_attachments_html()/attachment.php
  // (PHP) - a real thumbnail (reusing the same .transcript-image
  // tap-to-expand class as an inline base64 image) for an image, a
  // download link with filename + size for anything else. The filename
  // is always its own separate real link, not wrapped around the image
  // itself - a click there needs to toggle the thumbnail (see the
  // delegated .transcript-image handler below), not navigate.
  function renderAttachmentsHtml(attachments, line) {
    if (!attachments || attachments.length === 0) {
      return '';
    }

    var itemsHtml = attachments.map(function (a) {
      var url = attachmentUrl(line, a.file_uuid);
      var filename = escapeHtml(a.filename);

      if (a.isImage) {
        return '<div><img src="' + url + '" loading="lazy" alt="' + filename + '" class="transcript-image rounded border border-slate-800 cursor-pointer w-24 h-24 object-cover">'
          + '<a href="' + url + '" target="_blank" rel="noopener" class="block mt-0.5 max-w-24 truncate text-[11px] text-slate-500 active:text-slate-300">' + filename + '</a></div>';
      }

      // download (not target="_blank") - see attachment.php (PHP) for why:
      // target="_blank" on a Content-Disposition: attachment response
      // opens a permanently blank tab instead of a real page.
      return '<a href="' + url + '" download="' + filename + '" class="flex items-center gap-1.5 rounded border border-slate-800 bg-slate-950/60 px-2 py-1.5 text-xs text-sky-300 active:text-sky-200">'
        + '<span aria-hidden="true">&#8681;</span>'
        + '<span class="truncate max-w-[12rem]">' + filename + '</span>'
        + '<span class="shrink-0 text-slate-500">' + escapeHtml(formatFileSize(a.size)) + '</span></a>';
    }).join('');

    return '<div class="mt-1.5 flex flex-wrap items-start gap-2">' + itemsHtml + '</div>';
  }

  // Mirrors TranscriptView::render_transcript_block() (PHP) - isSubagent
  // picks the extra CSS class (subagent-use-block/subagent-detail) that the
  // single "Show subagent calls and outputs" toggle targets; a regular
  // (non-subagent) tool_use/tool_result block carries no such marker at
  // all, since it's never rendered standalone any more (see
  // groupToolCalls() below) - it's always inside a collapsible tool-group
  // instead, whose own <details> is the only show/hide affordance it needs.
  function renderBlock(block, line, isSubagent) {
    var text = escapeHtml(block.text);
    var imageHtml = block.image ? renderImageHtml(block.image) : '';
    var attachmentsHtml = renderAttachmentsHtml(block.attachments, line);

    // break-words - see render_transcript_block() in session.php (the
    // PHP-side counterpart) for why: a long unbroken token (a constant
    // name, URL, hash, ...) in prose text can otherwise widen the whole
    // page horizontally instead of wrapping.
    switch (block.kind) {
      case 'text':
        return '<p class="whitespace-pre-wrap break-words text-sm lg:text-base text-slate-100">' + text + '</p>';
      case 'plan':
        return '<div class="rounded border border-amber-800/40 bg-amber-950/20 px-3 py-2"><p class="whitespace-pre-wrap break-words text-sm lg:text-base text-amber-100">' + text + '</p></div>';
      case 'tool_use':
        // Collapsed by default regardless of the show/hide-subagent
        // toggle - it used to force-open when details were hidden (on the
        // theory that there'd be no result to click into for confirmation),
        // but that's backwards from what's wanted: collapsed either way.
        return '<div class="tool-use-block' + (isSubagent ? ' subagent-use-block' : '') + '">' + renderCollapsibleBlock(block.text, 'border-sky-800/40', 'text-sky-300', '&rarr; ') + '</div>';
      case 'tool_result':
        // The image/attachments are SIBLINGS of .tool-detail, not nested
        // inside it - shown regardless of the show/hide-subagent toggle,
        // since a shared file is often the whole point of having run the
        // tool in the first place.
        return '<div class="tool-detail' + (isSubagent ? ' subagent-detail' : '') + '">' + renderCollapsibleBlock(block.text, 'border-slate-800', 'text-slate-400', '') + '</div>' + imageHtml + attachmentsHtml;
      case 'image':
        return imageHtml || (text ? '<p class="break-words text-xs text-slate-600">' + text + '</p>' : '');
      default:
        return text ? '<p class="break-words text-xs text-slate-600">' + text + '</p>' : '';
    }
  }

  // Mirrors entry_color_kind()/entry_color_classes() in session.php (the
  // PHP-side counterpart) - "user"/"assistant"/"tool_use"/"tool_result"/
  // "system" is not the same thing as entry.role (a tool_result entry
  // carries role="user" under the hood, same as a real typed message); an
  // entry with no text at all reads as a tool action regardless of its
  // literal role, so it's colored (and labeled - see renderEntry()) as
  // one instead - tool_use and tool_result get their own distinct kinds,
  // not lumped into one "tool" bucket, so a call and its output are never
  // confusable at a glance either.
  function entryColorKind(entry) {
    var blocks = entry.blocks || [];
    var hasText = blocks.some(function (b) { return b.kind === 'text'; });
    var hasToolUse = blocks.some(function (b) { return b.kind === 'tool_use'; });
    var hasToolResult = blocks.some(function (b) { return b.kind === 'tool_result'; });
    var isSubagent = blocks.some(function (b) { return b.agent_type != null; });
    var hasPlan = blocks.some(function (b) { return b.kind === 'plan'; });
    var planStatus = null;
    blocks.forEach(function (b) { if (b.plan_status != null) { planStatus = b.plan_status; } });

    // See TranscriptView::entry_color_kind() (PHP) for why this check comes
    // before the generic tool_use/tool_result one below - a presented/
    // approved/rejected plan should read as its own distinct thing, not
    // just another tool call.
    if (!hasText && planStatus != null) {
      return planStatus === 'approved' ? 'plan_approved' : 'plan_rejected';
    }

    if (!hasText && hasPlan) {
      return 'plan_presented';
    }

    // See TranscriptView::entry_color_kind() (PHP) for why this check comes
    // before the generic tool_use/tool_result one below - a subagent
    // launch/report should read as its own distinct thing, not just
    // another tool call.
    if (!hasText && isSubagent) {
      return hasToolUse ? 'subagent_call' : 'subagent_result';
    }

    if (!hasText && hasToolUse) {
      return 'tool_use';
    }

    if (!hasText && hasToolResult) {
      return 'tool_result';
    }

    if (entry.role === 'assistant' || entry.role === 'user') {
      return entry.role;
    }

    return 'system';
  }

  function entryColorClasses(kind) {
    switch (kind) {
      case 'user':
        // See TranscriptView::entry_color_classes() (PHP) for why this is
        // rose, not indigo/blue - too close to sky (tool_use) otherwise.
        return { border: 'border-rose-800/60', bg: 'bg-rose-950/40', label: 'text-rose-300' };
      case 'assistant':
        return { border: 'border-emerald-800/60', bg: 'bg-emerald-950/40', label: 'text-emerald-300' };
      case 'tool_use':
        return { border: 'border-sky-800/60', bg: 'bg-sky-950/40', label: 'text-sky-300' };
      case 'tool_result':
        return { border: 'border-violet-800/60', bg: 'bg-violet-950/40', label: 'text-violet-300' };
      case 'subagent_call':
      case 'subagent_result':
        return { border: 'border-fuchsia-800/60', bg: 'bg-fuchsia-950/40', label: 'text-fuchsia-300' };
      case 'plan_presented':
      case 'plan_approved':
      case 'plan_rejected':
        return { border: 'border-amber-800/60', bg: 'bg-amber-950/40', label: 'text-amber-300' };
      default:
        return { border: 'border-slate-800', bg: 'bg-slate-900/50', label: 'text-slate-400' };
    }
  }

  function renderEntry(entry) {
    var colorKind = entryColorKind(entry);
    // See TranscriptView::entry_color_kind()'s label comment (PHP) - a
    // tool_use/tool_result entry is labeled "Tool", not its literal
    // user/assistant role, to match how it's actually colored. A plain
    // assistant entry gets no label at all (see the wrapperClass comment
    // below) - the free-flowing treatment already says "this is Claude".
    var roleLabel = colorKind === 'assistant' ? ''
      : colorKind === 'tool_use' ? 'Tool call'
      : colorKind === 'tool_result' ? 'Tool output'
      : colorKind === 'subagent_call' ? 'Subagent call'
      : colorKind === 'subagent_result' ? 'Subagent report'
      : colorKind === 'plan_presented' ? 'Plan'
      : colorKind === 'plan_approved' ? 'Plan approved'
      : colorKind === 'plan_rejected' ? 'Plan rejected'
      : (ROLE_LABELS[entry.role] || (entry.role ? escapeHtml(entry.role) : 'System'));
    var parsedMs = entry.timestamp ? Date.parse(entry.timestamp) : NaN;
    var timestamp = !isNaN(parsedMs) ? escapeHtml(relativeTimeLabel(Math.floor(parsedMs / 1000))) : '';
    var isSubagent = colorKind === 'subagent_call' || colorKind === 'subagent_result';
    var blocksHtml = (entry.blocks || []).map(function (b) { return renderBlock(b, entry.line, isSubagent); }).join('');
    var colors = entryColorClasses(colorKind);
    // Hides the WHOLE entry (not just the now-hidden tool_result/tool_use
    // block) once the single "Show subagent calls and outputs" toggle
    // turns off - see the PHP comment in render_transcript_entry() for why,
    // including why an entry carrying an image or a file attachment is
    // excluded either way, regardless of who it came from. A plain
    // (non-subagent) tool_use/tool_result entry never gets this marker at
    // all any more - see groupToolCalls() below.
    var hasAttachment = (entry.blocks || []).some(function (b) { return !!b.image || (b.attachments && b.attachments.length > 0); });
    var extraClass = (!hasAttachment && isSubagent) ? ' entry-subagent-only' : '';

    // Mirrors TranscriptView::entry_wrapper_class() (PHP) - a real user
    // message is a filled bubble (right-aligned, desktop-only, same as
    // before); a plain assistant reply is free-flowing text (no border/
    // background/max-width), even when it also carries tool_use blocks,
    // since those keep their own independent border regardless of the
    // entry wrapper around them - plus a bit of extra top margin, since
    // there's no border/bg left to visually separate it from whatever's
    // above. Every other kind keeps the boxed-card treatment unchanged.
    var wrapperClass;

    if (colorKind === 'assistant') {
      wrapperClass = 'entry-free-flowing mt-2 lg:max-w-full lg:self-start' + extraClass;
    } else {
      var isBubble = colorKind === 'user';
      var rounding = isBubble ? 'rounded-2xl' : 'rounded-lg';
      wrapperClass = rounding + ' border ' + colors.border + ' ' + colors.bg + ' px-3 py-2' + extraClass + ' lg:max-w-[75%] ' + (isBubble ? 'lg:self-end' : 'lg:self-start');
    }

    var div = document.createElement('div');
    div.className = wrapperClass;
    div.innerHTML = '<div class="select-none mb-1 flex items-center gap-2 text-xs text-slate-500">'
      + (roleLabel ? '<span class="font-medium ' + colors.label + '">' + roleLabel + '</span>' : '')
      + (timestamp ? '<span>' + timestamp + '</span>' : '')
      + '</div>'
      + '<div class="flex flex-col gap-1.5">' + blocksHtml + '</div>';

    return div;
  }

  // --- tool-call grouping: mirrors TranscriptView::render_transcript_
  // entries_html()/render_tool_group_html()/render_tool_pair_html() (PHP) -
  // a run of consecutive groupable tool_use/tool_result entries collapses
  // into one "N tool calls" <details>, each call paired with its own
  // result under one card. Unlike the PHP side (which only ever renders
  // one full batch at once - initial page load, or one "load older" fetch,
  // so a purely batch-local pass is enough), this also has to handle the
  // LIVE poll tail - a call and its own result routinely land in two
  // separate poll cycles (the tool takes real time to run), so
  // tailGroupState persists across pollHistory() calls and lets a new
  // result upgrade an already-rendered, already-in-the-DOM call-only pair
  // in place instead of appending a second card for what's really one
  // logical tool call. loadMore() and the initial fallback poll use their
  // own fresh, throwaway state instead (createGroupState()) - a batch of
  // OLDER entries being prepended above everything already shown must
  // never merge into (or be merged into) whatever's already on screen. ---

  function entryIsGroupableToolCall(entry) {
    var colorKind = entryColorKind(entry);

    if (colorKind !== 'tool_use' && colorKind !== 'tool_result') {
      return false;
    }

    return !(entry.blocks || []).some(function (b) { return !!b.image || (b.attachments && b.attachments.length > 0); });
  }

  function entryBlocksHtml(entry, isSubagent) {
    return (entry.blocks || []).map(function (b) { return renderBlock(b, entry.line, isSubagent); }).join('');
  }

  function toolPairTimestamp(entry) {
    var parsedMs = entry && entry.timestamp ? Date.parse(entry.timestamp) : NaN;
    return !isNaN(parsedMs) ? escapeHtml(relativeTimeLabel(Math.floor(parsedMs / 1000))) : '';
  }

  // Mirrors TranscriptView::render_tool_pair_html() (PHP) - the result half
  // is always wrapped in its own .tool-pair-result-slot, even when empty (a
  // call with no result yet), so a later-arriving result can upgrade it in
  // place via upgradeToolPairWithResult() below instead of appending a
  // second card.
  function renderToolPair(callEntry, resultEntry) {
    var timestamp = toolPairTimestamp(callEntry || resultEntry);
    var callHtml = callEntry ? entryBlocksHtml(callEntry, false) : '';
    var resultHtml = resultEntry ? entryBlocksHtml(resultEntry, false) : '';

    var div = document.createElement('div');
    div.className = 'rounded-lg border border-slate-800 bg-slate-900/50 px-3 py-2';
    div.innerHTML = (timestamp ? '<div class="select-none mb-1 text-xs text-slate-500">' + timestamp + '</div>' : '')
      + '<div class="flex flex-col gap-1.5">' + callHtml + '<div class="tool-pair-result-slot"></div></div>';

    var slot = div.querySelector('.tool-pair-result-slot');

    if (slot) {
      slot.innerHTML = resultHtml;
    }

    return div;
  }

  function upgradeToolPairWithResult(pairEl, resultEntry) {
    var slot = pairEl.querySelector('.tool-pair-result-slot');

    if (slot) {
      slot.innerHTML = entryBlocksHtml(resultEntry, false);
    }
  }

  // Mirrors TranscriptView's transcript/tool-group.php partial.
  function createToolGroupElement() {
    var details = document.createElement('details');
    details.className = 'tool-group rounded-lg border border-slate-800 bg-slate-900/30 px-3 py-2 lg:max-w-[75%] lg:self-start';
    details.innerHTML = '<summary class="select-none cursor-pointer text-xs font-medium text-slate-400"></summary>'
      + '<div class="tool-group-members flex flex-col gap-2 mt-2"></div>';

    return details;
  }

  function createGroupState() {
    return { element: null, membersContainer: null, pendingCallEntry: null, pendingCallPairEl: null, callCount: 0 };
  }

  function updateGroupSummary(state) {
    state.element.querySelector('summary').textContent = state.callCount === 1 ? '1 tool call' : (state.callCount + ' tool calls');
  }

  function closeGroup(state) {
    state.element = null;
    state.membersContainer = null;
    state.pendingCallEntry = null;
    state.pendingCallPairEl = null;
    state.callCount = 0;
  }

  // Appends one groupable entry into `state`'s currently-open group
  // (creating it in `container` - a DocumentFragment for a one-shot batch,
  // or the live `list` for the poll tail - the first time this state opens
  // a group). Returns the group element, for the caller's own new-content
  // bookkeeping (markNewContent()) - always the SAME element across
  // however many entries extend it, so callers naturally dedupe by
  // identity rather than double-counting.
  function appendGroupableEntry(state, container, entry) {
    if (!state.element) {
      state.element = createToolGroupElement();
      state.membersContainer = state.element.querySelector('.tool-group-members');
      container.appendChild(state.element);
    }

    var colorKind = entryColorKind(entry);

    if (colorKind === 'tool_use') {
      // A previous call that never got its own result (rare - e.g. Claude
      // was interrupted mid-tool) stays exactly as it rendered; this new
      // call starts its own fresh pending slot rather than waiting on it.
      state.pendingCallEntry = entry;
      state.callCount++;
      updateGroupSummary(state);
      state.pendingCallPairEl = renderToolPair(entry, null);
      state.membersContainer.appendChild(state.pendingCallPairEl);

      return state.element;
    }

    if (state.pendingCallEntry && state.pendingCallPairEl) {
      upgradeToolPairWithResult(state.pendingCallPairEl, entry);
      state.pendingCallEntry = null;
      state.pendingCallPairEl = null;

      return state.element;
    }

    // An orphaned result with no pending call in THIS group/state -
    // shouldn't normally happen, but a pagination boundary or a dropped
    // call could in principle leave one.
    state.membersContainer.appendChild(renderToolPair(null, entry));

    return state.element;
  }

  // Renders a batch of entries into `container` (a DocumentFragment for a
  // one-shot batch, or the live `list` for the poll tail), grouping
  // consecutive groupable tool_use/tool_result entries via `state` (see the
  // block comment above). Returns the distinct top-level nodes touched
  // (created OR extended) in this call, for the caller's own new-content
  // highlighting.
  function renderEntriesGrouped(entries, state, container) {
    var touched = [];

    entries.forEach(function (entry) {
      if (entryIsGroupableToolCall(entry)) {
        var groupEl = appendGroupableEntry(state, container, entry);

        if (touched.indexOf(groupEl) === -1) {
          touched.push(groupEl);
        }

        return;
      }

      closeGroup(state);
      var el = renderEntry(entry);
      container.appendChild(el);
      touched.push(el);
    });

    return touched;
  }

  // Persists across pollHistory() calls (unlike loadMore()'s own throwaway
  // createGroupState()) - see the block comment above for why the live
  // poll tail specifically needs this.
  var tailGroupState = createGroupState();

  // Seeds tailGroupState from whatever the server already rendered, if the
  // page loaded (or was refreshed) mid-tool-run - i.e. the transcript's
  // last entry so far is itself part of a still-open tool-group (see
  // TranscriptView::render_transcript_entries_html(), PHP). Without this,
  // a page load/refresh landing mid-run would leave tailGroupState at its
  // fresh createGroupState() default (believing nothing is open), so the
  // next poll's continuation of that same run would start a brand new
  // second <details> right after the server-rendered one instead of
  // extending it - same run, split across two group cards for no reason
  // other than "the page happened to load in the middle of it".
  (function seedTailGroupStateFromServerRender() {
    var lastEl = list ? list.lastElementChild : null;

    if (!lastEl || !lastEl.classList.contains('tool-group')) {
      return;
    }

    var membersContainer = lastEl.querySelector('.tool-group-members');
    var pairs = membersContainer ? membersContainer.children : [];

    if (!membersContainer || pairs.length === 0) {
      return;
    }

    var lastPair = pairs[pairs.length - 1];
    var resultSlot = lastPair.querySelector('.tool-pair-result-slot');
    // An empty slot on the LAST pair means the server rendered a call with
    // no result yet (the tool was still running as of that render) - the
    // one case where a follow-up poll's result needs to upgrade this exact
    // element rather than start a new pair.
    var hasPendingCall = !!resultSlot && resultSlot.children.length === 0 && resultSlot.textContent === '';

    tailGroupState.element = lastEl;
    tailGroupState.membersContainer = membersContainer;
    tailGroupState.callCount = pairs.length;
    tailGroupState.pendingCallEntry = hasPendingCall ? lastPair : null; // only ever checked for truthiness, see appendGroupableEntry()
    tailGroupState.pendingCallPairEl = hasPendingCall ? lastPair : null;
  })();

  // --- optimistic history entries: rendered with renderEntry() itself (so
  // a pending compose message/prompt answer looks exactly like the real
  // thing once confirmed, just dimmed), tracked in pendingEntries so
  // pollHistory() can reconcile them against real incoming data - see
  // reconcilePendingEntries() below for the matching logic. ---

  function pendingEntryText(blocks) {
    var textBlock = (blocks || []).find(function (b) { return b.kind === 'text'; });
    return textBlock ? textBlock.text : '';
  }

  // --- pending-compose-message persistence: survives a navigation away
  // and back to this same session, found live 2026-08-08 (Andres: a
  // message sent while Claude was mid-turn, still showing dimmed, was
  // gone entirely after navigating to another page and back before it
  // was confirmed). pendingEntries itself is plain in-memory JS state,
  // wiped on any real page navigation - sessionStorage is the one piece
  // that actually survives it. Only ever tracks ONE compose message at a
  // time (the textarea/send button are disabled while a send is in
  // flight, so there's never a second concurrent one from this same
  // tab) - deliberately NOT used for prompt-answer pendings, which
  // already have their own more reliable answerPendingReason-based
  // reconciliation (see the comment on that near the top of this file)
  // rather than generic text-matching. ---
  var PENDING_MESSAGE_STORAGE_KEY = 'csm-pending-message-' + sessionName;
  // A restored pending bubble that's actually already long confirmed
  // (the tab closed before its own poll could reconcile+clear storage,
  // then reopened well after Claude Code wrote the real transcript line)
  // would sit forever un-reconciled - pollHistory() only ever asks the
  // server for entries newer than THIS load's own newestLine, so an
  // already-rendered confirmation can never show up in a future `fresh`
  // batch to match against. Capping how old a restored entry can be
  // avoids that: Claude Code's own write latency for a compose send is a
  // couple of seconds at most (measured live elsewhere in this file), so
  // anything older than this is far more likely already-confirmed than
  // still genuinely in flight.
  var PENDING_MESSAGE_MAX_AGE_MS = 2 * 60 * 1000;

  function savePendingMessageToStorage(role, text) {
    try {
      window.sessionStorage.setItem(PENDING_MESSAGE_STORAGE_KEY, JSON.stringify({ role: role, text: text, savedAt: Date.now() }));
    } catch (e) {}
  }

  function clearPendingMessageFromStorage() {
    try {
      window.sessionStorage.removeItem(PENDING_MESSAGE_STORAGE_KEY);
    } catch (e) {}
  }

  // Called once at page load, before polling starts - re-renders whatever
  // compose message was still unconfirmed when this tab last navigated
  // away from this session, as a normal pending entry (pollHistory()'s
  // existing reconcilePendingEntries() then clears it exactly the same
  // way as any other pending entry, once the real confirming line
  // arrives).
  function restorePendingMessageFromStorage() {
    var raw;

    try {
      raw = window.sessionStorage.getItem(PENDING_MESSAGE_STORAGE_KEY);
    } catch (e) {
      return;
    }

    if (!raw) {
      return;
    }

    var saved;

    try {
      saved = JSON.parse(raw);
    } catch (e) {
      clearPendingMessageFromStorage();
      return;
    }

    if (!saved || typeof saved.text !== 'string' || saved.text === '' || typeof saved.savedAt !== 'number' || (Date.now() - saved.savedAt) > PENDING_MESSAGE_MAX_AGE_MS) {
      clearPendingMessageFromStorage();
      return;
    }

    appendPendingEntry(saved.role, [{ kind: 'text', text: saved.text }]);
  }

  // #history-list always exists now (see session.php's own comment on the
  // container) but starts with a placeholder note ("No transcript
  // available"/"Nothing recorded yet") when there's no real content yet -
  // removed the moment anything real actually shows up, optimistic or
  // polled, so it's not still sitting there once messages exist.
  function removeHistoryEmptyNote() {
    var note = document.getElementById('history-empty-note');

    if (note && note.parentNode) {
      note.parentNode.removeChild(note);
    }
  }

  function appendPendingEntry(role, blocks) {
    if (!list) {
      return null;
    }

    removeHistoryEmptyNote();

    var wasNearBottom = isNearBottom();
    var el = renderEntry({ role: role, timestamp: new Date().toISOString(), blocks: blocks });
    el.classList.add('opacity-50');
    el.dataset.pendingRole = role;
    el.dataset.pendingText = pendingEntryText(blocks);

    var pendingNote = document.createElement('span');
    pendingNote.className = 'italic';
    pendingNote.textContent = 'Sending…';
    el.querySelector('.mb-1').appendChild(pendingNote);

    // A real user message always breaks any still-open tool-group at the
    // tail (see tailGroupState) - otherwise a later-arriving tool result
    // would try to extend a group that's no longer actually at the tail of
    // the list, inserting itself above a message the user already sent.
    closeGroup(tailGroupState);
    list.appendChild(el);
    pendingEntries.push(el);
    maybeAutoScroll(wasNearBottom);

    return el;
  }

  // Only used to undo a pending entry after a failed send - the success
  // path never calls this directly, pollHistory() reconciles pending
  // entries against real incoming data instead (see there).
  function removePendingEntry(el) {
    if (!el) {
      return;
    }

    if (el.parentNode) {
      el.parentNode.removeChild(el);
    }

    var idx = pendingEntries.indexOf(el);

    if (idx !== -1) {
      pendingEntries.splice(idx, 1);
    }
  }

  // Called by pollHistory() with whatever fresh entries just came back -
  // a pending entry is only cleared once one of them actually matches it
  // (same role + same text), not just because SOME fresh data landed.
  // That matters because a compose send's own confirming line can take a
  // couple of seconds to actually reach the transcript file (measured
  // live - Claude Code's own write latency, nothing this app controls),
  // so an earlier poll can easily see OTHER new content first; clearing
  // every pending entry on any fresh batch made the just-sent message
  // vanish (cleared as if confirmed) without ever actually rendering the
  // real one, since it genuinely wasn't in that batch yet.
  function reconcilePendingEntries(freshEntries) {
    if (pendingEntries.length === 0) {
      return;
    }

    pendingEntries = pendingEntries.filter(function (el) {
      var matched = freshEntries.some(function (entry) {
        return entry.role === el.dataset.pendingRole && pendingEntryText(entry.blocks) === el.dataset.pendingText;
      });

      if (matched && el.parentNode) {
        el.parentNode.removeChild(el);
      }

      if (matched && el.dataset.pendingRole === 'user') {
        clearPendingMessageFromStorage();
      }

      return !matched;
    });
  }

  // Mirrors TranscriptView::render_session_static_info_html() - kept
  // alongside renderEntry()/renderBlock() as the JS-side counterpart of
  // the same PHP renderer, both feeding this one visibility-gated poll.
  //
  // Only rebuilds the block when title/name/workdir/attached actually
  // change - same reasoning as renderBlockedSection()'s skip-if-unchanged
  // key, here for a lower-stakes reason (no focus/scroll to protect, just
  // an in-progress text selection inside the box - e.g. copying the
  // session name or workdir path - that a full innerHTML replacement
  // would silently clear on every poll for no reason, since none of this
  // actually changes poll to poll in the common case). The relative-time
  // label is genuinely time-varying though (its DISPLAYED text can change
  // even with no new poll data at all), so it's always updated via its
  // own stable id rather than being covered by the skip.
  function renderStaticInfo(detail) {
    var key = JSON.stringify([detail.title || null, detail.name, detail.workdir || null, !!detail.attached]);

    if (key !== lastRenderedStaticInfoKey) {
      lastRenderedStaticInfoKey = key;

      var html = '<div class="text-base font-medium truncate">' + escapeHtml(detail.title || detail.name) + '</div>'
        + '<div class="font-mono text-xs text-slate-500 truncate mt-0.5">' + escapeHtml(detail.name) + '</div>';

      if (detail.workdir) {
        html += '<div class="text-xs text-slate-500 truncate mt-0.5">' + escapeHtml(detail.workdir) + '</div>';
      }

      html += '<div class="text-xs text-slate-400 mt-1 flex items-center gap-2">'
        + '<span id="static-info-activity"></span>'
        + '<span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span>'
        + (detail.attached ? '<span class="text-emerald-400">attached</span>' : '<span class="text-slate-500">detached</span>')
        + '</div>';

      infoBox.innerHTML = html;

      if (headerTitle) {
        headerTitle.textContent = detail.title || detail.name;
      }
    }

    var activityEl = document.getElementById('static-info-activity');

    if (activityEl) {
      activityEl.textContent = relativeTimeLabel(detail.activity);
    }
  }

  // Mirrors render_thinking_indicator_html() - a single transient "is it
  // doing something right now" signal, never the actual thinking content
  // (that's dropped entirely server-side), and never shown at the same
  // time as the blocked-prompt section.
  //
  // Skips the rebuild when the shown/hidden state hasn't actually changed
  // - same "no-op unless something real changed" pattern as
  // renderBlockedSection(). Found live: rebuilding on every single poll
  // (the previous behavior) tore out and replaced the Stop button on
  // every cycle even while a session just sat "working" poll after poll
  // with nothing new to show - if that landed while a stop click's own
  // fetch was still in flight, the disabled state from the click handler
  // (see the delegated #stop-btn listener below) applied to the OLD,
  // now-detached button; the freshly rebuilt one showed up enabled again
  // mid-request, opening a real double-submit window. Only mattered while
  // working (never while blocked, per the two states' own mutual
  // exclusion above), so the key is just the shown/hidden boolean, not
  // the (static, unchanging) markup itself.
  function renderThinkingIndicator(detail) {
    if (!thinkingIndicator) {
      return;
    }

    var shouldShow = !!detail.working && !detail.blocked_reason;

    if (shouldShow === lastRenderedThinkingShown) {
      return;
    }

    lastRenderedThinkingShown = shouldShow;

    if (!shouldShow) {
      thinkingIndicator.innerHTML = '';
      return;
    }

    thinkingIndicator.innerHTML = '<div class="select-none rounded-lg border border-slate-800 bg-slate-900/50 px-3 py-2 text-xs text-slate-400 flex items-center justify-between gap-2">'
      + '<span class="flex items-center gap-2">'
      + '<span class="flex items-center gap-1">'
      + '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:0ms"></span>'
      + '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:150ms"></span>'
      + '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:300ms"></span>'
      + '</span>'
      + '<span>Thinking&hellip;</span>'
      + '</span>'
      + '<button type="button" id="stop-btn" class="rounded border border-red-800/60 bg-red-950/40 active:bg-red-900/60 text-red-300 text-xs font-medium px-2 py-1">Stop</button>'
      + '</div>';
  }

  // Mirrors TranscriptView::render_mode_toggle_html() - options are static
  // (rendered once server-side), only the selected value/disabled state
  // changes here. Left showing its last known value (just disabled) if
  // the mode becomes unreadable after having been known - not worth a
  // placeholder swap for what's a rare, transient state.
  function renderModeToggle(detail) {
    if (!modeSelect) {
      return;
    }

    modeSelect.disabled = !detail.current_mode;

    if (detail.current_mode) {
      modeSelect.value = detail.current_mode;
    }
  }

  // Mirrors BlockedPromptView::blocked_prompt_rich_html() - the JS-side
  // counterpart feeding the same poll. One unified card (question, the
  // pending command collapsed by default, Approve/Deny buttons) - not a
  // separate bubble, which read as something that already happened
  // rather than the thing still waiting on an answer. No attach-tip
  // here: it's only shown where there are no buttons to tap instead (the
  // dashboard's folder-trust rows - see renderStaticInfo() for why this
  // page never needs that fallback). Empties the section when no longer
  // blocked, so an answered prompt disappears without a reload.
  function renderBlockedSection(detail) {
    if (!blockedSection) {
      return;
    }

    // A poll is a no-op here whenever the blocked-prompt data hasn't
    // actually changed from what's already on screen - the common case
    // while a prompt just sits unanswered, poll after poll. Skipping the
    // rebuild entirely (rather than rebuilding every time and carefully
    // trying to restore state afterward) is what actually fixes the whole
    // family of poll-during-interaction bugs found live: lost textarea
    // focus/cursor, the page scrolling back to the top, an expanded
    // command's own scroll position resetting (so only its last lines were
    // visible), a manually-opened <details> snapping shut - none of that
    // needs preserving if the DOM was never touched to begin with.
    var key = JSON.stringify([detail.blocked_reason || null, detail.prompt_context || null, detail.prompt_options || null]);

    if (key === lastRenderedBlockedKey) {
      return;
    }

    lastRenderedBlockedKey = key;

    // The rebuild below can still occasionally happen while the free-text
    // reply box is open mid-draft or the command <details> is manually
    // expanded (typically just the first poll after page load, landing on
    // the same prompt the server already rendered) - preserved as a safety
    // net for that case, same mechanism as before, just rarely exercised now.
    var existingReply = blockedSection.querySelector('.freetext-reply');
    var freetextWasOpen = existingReply && !existingReply.classList.contains('hidden');
    var existingTextarea = existingReply ? existingReply.querySelector('.freetext-reply-textarea') : null;
    var freetextDraft = existingTextarea ? existingTextarea.value : '';
    var freetextOption = existingReply ? existingReply.dataset.option : null;
    var freetextHadFocus = existingTextarea !== null && existingTextarea === document.activeElement;
    var freetextSelectionStart = freetextHadFocus ? existingTextarea.selectionStart : null;
    var freetextSelectionEnd = freetextHadFocus ? existingTextarea.selectionEnd : null;

    var existingContextDetails = blockedSection.querySelector('details');
    var contextDetailsWasOpen = existingContextDetails ? existingContextDetails.open : false;
    var existingPre = existingContextDetails ? existingContextDetails.querySelector('pre') : null;
    var contextScrollTop = existingPre ? existingPre.scrollTop : 0;

    // Page scroll position, restored (if captured) after the rebuild below
    // - only when there was actually something on screen worth not
    // yanking the user away from, never fights normal scrolling otherwise.
    var scrollYBeforeRebuild = (freetextHadFocus || contextDetailsWasOpen) ? window.scrollY : null;

    if (!detail.blocked_reason) {
      blockedSection.innerHTML = '';
      currentBlockedReason = null;
      answerPendingReason = null;
      removePendingEntry(answerPendingHistoryEl);
      answerPendingHistoryEl = null;
      return;
    }

    currentBlockedReason = detail.blocked_reason;

    // The pending command/description gets its own entry BEFORE the card
    // below, not nested inside it - mirrors BlockedPromptView::
    // pending_context_entry_html() (PHP), same reasoning there (Andres's
    // own explicit call, 2026-08-08: readability over it now reading like
    // a real, already-happened tool_use entry).
    var html = '';

    if (detail.prompt_context) {
      html += '<div class="rounded-lg border border-amber-700/60 bg-amber-900/40 px-3 py-2 mb-2 lg:max-w-[75%] lg:self-start">'
        + '<div class="select-none mb-1 flex items-center gap-2 text-xs text-slate-500"><span class="font-medium text-amber-300">Awaiting approval</span></div>'
        + '<div class="flex flex-col gap-1.5">' + renderCollapsibleBlock(detail.prompt_context, 'border-amber-700/40', 'text-amber-100', '') + '</div>'
        + '</div>';
    }

    html += '<div class="rounded-lg px-3 py-2 text-xs bg-amber-900/40 text-amber-200 border border-amber-700/60">'
      + '<p class="font-medium break-words">Waiting on input: ' + escapeHtml(detail.blocked_reason) + '</p>';

    if (detail.prompt_options && detail.prompt_options.length) {
      var optionsHtml = '';
      var hasFreeText = false;

      // See BlockedPromptView::blocked_prompt_options_html() (PHP) for why
      // - a multi-question AskUserQuestion prompt needs Prev/Next buttons
      // to reach any question besides whichever tab currently happens to
      // be showing.
      if (detail.prompt_multi_question) {
        optionsHtml += '<button type="button" class="nav-prompt-btn rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2" data-direction="left" aria-label="Previous question">&larr;</button>';
      }

      detail.prompt_options.forEach(function (opt) {
        var label = escapeHtml(opt.label);

        if (opt.label.toLowerCase().indexOf('type something') !== -1) {
          hasFreeText = true;
          // break-words + max-w-full - see BlockedPromptView::blocked_prompt_options_html() in
          // AgentClient.php (PHP) for why both are needed together (an
          // option label has no length limit imposed by the tool itself,
          // and break-words alone doesn't help without max-w-full capping
          // the flex item's width first).
          optionsHtml += '<button type="button" class="reveal-freetext-btn rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2 break-words max-w-full text-left" data-option="' + opt.number + '">'
            + opt.number + '. ' + label
            + '</button>';
          return;
        }

        optionsHtml += '<form method="post" action="/answer_prompt.php" data-confirm-label="' + label + '">'
          + '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrfToken) + '">'
          + '<input type="hidden" name="session" value="' + escapeHtml(sessionName) + '">'
          + '<input type="hidden" name="option" value="' + opt.number + '">'
          + '<button type="submit" class="rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2 break-words max-w-full text-left">'
          + opt.number + '. ' + label
          + '</button></form>';
      });

      if (detail.prompt_multi_question) {
        optionsHtml += '<button type="button" class="nav-prompt-btn rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2" data-direction="right" aria-label="Next question">&rarr;</button>';
      }

      html += '<div class="select-none prompt-options-wrapper mt-2" data-session="' + escapeHtml(sessionName) + '" data-csrf-token="' + escapeHtml(csrfToken) + '">'
        + '<div class="flex flex-wrap gap-2">' + optionsHtml + '</div>';

      if (hasFreeText) {
        html += '<div class="freetext-reply hidden mt-2">'
          + '<textarea class="freetext-reply-textarea w-full resize-none rounded-lg bg-slate-800 border border-slate-700 text-base text-slate-100 px-3 py-2" rows="2" placeholder="Type your reply&hellip;"></textarea>'
          + '<button type="button" class="freetext-reply-send-btn mt-1 rounded-lg bg-indigo-600 active:bg-indigo-700 text-white text-xs font-medium px-3 py-1.5">Send</button>'
          + '</div>';
      }

      html += '</div>';
    }

    html += '</div>';
    blockedSection.innerHTML = html;

    if (freetextWasOpen) {
      var newReply = blockedSection.querySelector('.freetext-reply');

      if (newReply) {
        newReply.classList.remove('hidden');
        newReply.dataset.option = freetextOption;
        var newTextarea = newReply.querySelector('.freetext-reply-textarea');
        newTextarea.value = freetextDraft;

        if (freetextHadFocus) {
          newTextarea.focus();
          newTextarea.setSelectionRange(freetextSelectionStart, freetextSelectionEnd);
        }
      }
    }

    if (contextDetailsWasOpen) {
      var newContextDetails = blockedSection.querySelector('details');

      if (newContextDetails) {
        newContextDetails.open = true;
        var newPre = newContextDetails.querySelector('pre');

        if (newPre) {
          newPre.scrollTop = contextScrollTop;
        }
      }
    }

    if (scrollYBeforeRebuild !== null) {
      // Applied a frame later, after the browser's own focus/reflow-driven
      // scroll-into-view (if any) has already happened, so this is the
      // last word rather than getting immediately overridden by it.
      requestAnimationFrame(function () {
        window.scrollTo(0, scrollYBeforeRebuild);
      });
    }

    // A poll can land mid-flight, between an answer being submitted and the
    // next poll actually seeing it land - since this rebuilds the section
    // from scratch every time, that would otherwise silently drop the
    // "answered, waiting to confirm" dimming the instant a same-prompt poll
    // comes back. Reapplied here as long as the SAME prompt is still
    // showing; the moment it changes or clears, the answer really did land
    // (or a new prompt replaced it), so there's nothing left to reapply.
    if (answerPendingReason !== null && answerPendingReason === currentBlockedReason) {
      markBlockedSectionAnswerPending();
    } else {
      answerPendingReason = null;
      removePendingEntry(answerPendingHistoryEl);
      answerPendingHistoryEl = null;
    }
  }

  // Dims the blocked-prompt card and disables everything in it, right after
  // an answer is submitted (and reapplied by renderBlockedSection() above if
  // a poll rebuilds the same still-pending prompt before confirmation
  // arrives). revertBlockedSectionAnswerPending() undoes this on a failed
  // send; a successful one needs no explicit revert - the card either gets
  // replaced (new/no prompt) or re-dimmed by the check above on the next
  // rebuild, either way never left stale.
  function markBlockedSectionAnswerPending() {
    var card = blockedSection.firstElementChild;

    if (card) {
      card.classList.add('opacity-50');

      if (!card.querySelector('.answer-pending-note')) {
        var note = document.createElement('p');
        note.className = 'select-none answer-pending-note mt-2 text-amber-300/70 italic';
        note.textContent = 'Answered - waiting to confirm…';
        card.appendChild(note);
      }
    }

    blockedSection.querySelectorAll('button, textarea').forEach(function (el) { el.disabled = true; });
  }

  function revertBlockedSectionAnswerPending() {
    var card = blockedSection.firstElementChild;

    if (card) {
      card.classList.remove('opacity-50');
      var note = card.querySelector('.answer-pending-note');

      if (note) {
        note.remove();
      }
    }

    blockedSection.querySelectorAll('button, textarea').forEach(function (el) { el.disabled = false; });
  }

  // Event delegation, not per-form listeners: covers both the
  // PHP-rendered forms on first paint and any poll-rebuilt ones, without
  // needing to re-attach anything after renderBlockedSection() replaces
  // the DOM. AJAX, not a real form submission - answering a prompt is
  // common enough that a full page reload per answer would be poor UX
  // (same reasoning as compose send).
  if (blockedSection) {
    blockedSection.addEventListener('submit', function (e) {
      var form = e.target.closest('form[data-confirm-label]');

      if (!form) {
        return;
      }

      e.preventDefault();

      if (shouldConfirmBeforeAnswer() && !confirm('Send "' + form.dataset.confirmLabel + '" to this session?')) {
        return;
      }

      answerPendingReason = currentBlockedReason;
      markBlockedSectionAnswerPending();
      var pendingEl = appendPendingEntry('user', [{ kind: 'text', text: form.dataset.confirmLabel }]);
      answerPendingHistoryEl = pendingEl;

      fetch('/answer_prompt.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(new FormData(form)).toString()
      })
        .then(function (r) { return parseJsonResponse(r, 'answer-prompt'); })
        .then(function (data) {
          if (data && data.ok) {
            // The request only waits for the keys to be *sent*, not for
            // Claude Code to actually process them and redraw past the
            // prompt - polling immediately can still catch the old,
            // now-stale blocked state (found live: the prompt appeared
            // "stuck" until the next regular poll tick, up to the full
            // interval later). Same fix as the mode-select's redraw wait.
            setTimeout(pollOnce, 300);
          } else {
            alert((data && data.message) || 'Failed to send answer.');
            answerPendingReason = null;
            answerPendingHistoryEl = null;
            removePendingEntry(pendingEl);
            revertBlockedSectionAnswerPending();
          }
        })
        .catch(function () {
          alert('Network error - answer not sent.');
          answerPendingReason = null;
          answerPendingHistoryEl = null;
          removePendingEntry(pendingEl);
          revertBlockedSectionAnswerPending();
        });
    });
  }

  // --- free-text reply (the "Type something." option) - revealing the
  // textarea is its own deliberate step, so unlike the plain option
  // buttons above, sending it skips the confirm() dialog. ---
  if (blockedSection) {
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

      answerPendingReason = currentBlockedReason;
      markBlockedSectionAnswerPending();
      var pendingEl = appendPendingEntry('user', [{ kind: 'text', text: text }]);
      answerPendingHistoryEl = pendingEl;

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
        .then(function (r) { return parseJsonResponse(r, 'answer-prompt-freetext'); })
        .then(function (data) {
          if (data && data.ok) {
            // See the plain-option handler above for why this waits a beat
            // before polling instead of polling immediately.
            setTimeout(pollOnce, 300);
          } else {
            alert((data && data.message) || 'Failed to send reply.');
            textarea.disabled = false;
            sendBtn.disabled = false;
            answerPendingReason = null;
            answerPendingHistoryEl = null;
            removePendingEntry(pendingEl);
            revertBlockedSectionAnswerPending();
          }
        })
        .catch(function () {
          alert('Network error - reply not sent.');
          textarea.disabled = false;
          sendBtn.disabled = false;
          answerPendingReason = null;
          answerPendingHistoryEl = null;
          removePendingEntry(pendingEl);
          revertBlockedSectionAnswerPending();
        });
    }

    blockedSection.addEventListener('click', function (e) {
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
          .then(function (r) { return parseJsonResponse(r, 'navigate-prompt'); })
          .then(function (data) {
            navBtn.disabled = false;

            if (!data || !data.ok) {
              alert((data && data.message) || 'Failed to navigate to the other question.');
              return;
            }

            // The pane state has moved to the other tab, but this card
            // still shows the one just left - forces the very next poll
            // to actually rebuild instead of skipping as "unchanged" (see
            // the key comparison above), so the new tab's question/options
            // show up on the next cycle rather than waiting for something
            // else to invalidate the cache first.
            lastRenderedBlockedKey = undefined;
          })
          .catch(function () {
            navBtn.disabled = false;
            alert('Network error - could not navigate to the other question.');
          });
      }
    });

    // Plain Enter inserts a newline (the browser's own default - no
    // handling needed here); only Shift+Enter submits, same convention as
    // the compose box. shiftKeyPhysicallyHeld cross-check - see its own
    // doc comment in common.js.
    blockedSection.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.shiftKey && shiftKeyPhysicallyHeld && e.target.classList.contains('freetext-reply-textarea')) {
        e.preventDefault();
        submitFreetextReply(e.target.closest('.freetext-reply'));
      }
    });
  }

  function loadMore() {
    var before = btn.dataset.before;

    btn.disabled = true;
    btn.textContent = 'Loading…';

    var url = '/session_history.php?session=' + encodeURIComponent(sessionName) + '&limit=30'
      + (before ? '&before=' + encodeURIComponent(before) : '');

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          btn.textContent = (data && data.message) || 'Could not load more.';
          return;
        }

        // A fresh, throwaway group state (not tailGroupState, which tracks
        // the LIVE poll tail) - a batch of OLDER entries being prepended
        // above everything already shown must never merge into (or be
        // merged into) whatever's already rendered there.
        var fragment = document.createDocumentFragment();
        renderEntriesGrouped(data.entries || [], createGroupState(), fragment);
        list.insertBefore(fragment, list.firstChild);

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
  }

  if (btn) {
    btn.addEventListener('click', loadMore);
  }

  // --- visibility-gated polling: refreshes the info/blocked-prompt panel
  // and appends any new messages, but only while this tab is the visible,
  // foregrounded one - cleared on hidden, restarted (with an immediate
  // refresh) on visible, so a background tab doesn't keep hitting the
  // socket for nobody. ---
  var pollTimer = null; // pending setTimeout ID for the next cycle, or null while a cycle's own requests are in flight (nothing pending to clear right then)
  var pollingActive = false; // whether polling should keep going - distinct from pollTimer, which is null during a cycle's in-flight window
  var pollAbortController = new AbortController(); // reset in startPolling() each time polling (re)starts, so a lingering abort from a previous stop can't affect a fresh one
  var pollRunning = false; // true while a pollOnce() cycle's requests are actually in flight - see pollOnce()'s own re-entrancy guard
  var pollQueuedAgain = false; // a pollOnce() call arrived while one was already running - run exactly one more pass once the current one finishes

  // Wipes the rendered history and pagination state clean, same in spirit
  // to how a real terminal clears on /clear - called once a rotation to a
  // brand new transcript file is detected (see currentClaudeSessionId
  // above), since every already-rendered entry belongs to the now-
  // abandoned old file and the "Load older messages" cursor (btn.dataset.
  // before) points into it too.
  function resetHistoryForRotatedTranscript() {
    if (list) {
      list.innerHTML = '';
    }

    newestLine = null;
    pendingEntries = [];
    currentDivider = null;

    if (btn) {
      btn.classList.add('hidden');
    }
  }

  function pollInfo(wasNearBottom) {
    return fetch('/session_detail.php?session=' + encodeURIComponent(sessionName), { credentials: 'same-origin', signal: pollAbortController.signal })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) {
          if (typeof data.claude_session_id === 'string' && data.claude_session_id !== '') {
            if (currentClaudeSessionId !== null && data.claude_session_id !== currentClaudeSessionId) {
              resetHistoryForRotatedTranscript();
            }

            currentClaudeSessionId = data.claude_session_id;
          }

          renderStaticInfo(data);
          renderThinkingIndicator(data);
          renderModeToggle(data);
          renderBlockedSection(data);
          renderComposeVisibility(data);
          maybeAutoScroll(wasNearBottom);
        }
      })
      .catch(function () {});
  }

  // Minimum time the "New" divider/highlight must actually be on screen
  // before it starts fading - without this, a poll that lands while the
  // user is already scrolled to the bottom (the common case, since
  // maybeAutoScroll() keeps them there) would have the divider intersect
  // the viewport and start fading the instant it's inserted, defeating
  // the entire point of marking it as new.
  var NEW_CONTENT_VISIBLE_DELAY_MS = 2500;

  // Must match the .new-content-highlight.fading box-shadow transition
  // duration in <style> above - the delay before the highlight classes are
  // actually removed from the DOM, so cleanup happens after the fade is
  // visually complete rather than cutting it off mid-animation.
  var NEW_CONTENT_HIGHLIGHT_FADE_MS = 1200;

  // Marks entries fresh off this poll cycle: a "New" divider above the
  // batch plus a highlight ring on each entry in it, so it's obvious what
  // just arrived without having to spot it by eye in a long list.
  //
  // Two independent lifecycles, on purpose:
  //  - At most ONE divider ever exists. A newer call removes the previous
  //    one immediately, no fade, no visibility check - it's purely a "new
  //    stuff starts here" landmark, and once a newer batch has arrived,
  //    that's no longer where new stuff starts. This also sidesteps a real
  //    bug the per-batch version this replaced had: if a batch's entries
  //    are ALL currently hidden (Andres toggles "Show subagent calls and
  //    outputs" off - see body.hide-subagent in the <style> above - which
  //    sets display:none on whole entries, not just a class), a
  //    display:none element can never intersect the
  //    viewport, so a fade condition requiring every element in a batch to
  //    be seen could never be satisfied - the divider got stuck forever,
  //    and a poll landing while it was still stuck (very likely, since it
  //    could never clear) produced another one right after it, reading as
  //    the "New" label repeating.
  //  - Each entry's own highlight ring fades independently, the instant
  //    THAT entry has actually been on screen for NEW_CONTENT_VISIBLE_DELAY_MS
  //    - not tied to the divider, not tied to any other entry in the same
  //    poll batch. A hidden entry's ring just never fades, same as before,
  //    but that's invisible and harmless on its own now that it can't also
  //    block the divider or any other entry's fade.
  //
  // $beforeNode and every element in $entryElements must already be
  // attached to `list` (not a detached DocumentFragment) - the
  // IntersectionObserver below only fires once an element is actually
  // connected to the document, and inserting into a fragment first would
  // leave a window where "attached but not yet observed" could miss the
  // very first paint.
  var currentDivider = null;

  var newEntryObserver = typeof IntersectionObserver === 'undefined' ? null : new IntersectionObserver(function (observerEntries) {
    observerEntries.forEach(function (observerEntry) {
      if (!observerEntry.isIntersecting) {
        return;
      }

      var el = observerEntry.target;
      newEntryObserver.unobserve(el);

      setTimeout(function () {
        // .fading (not a straight classList.remove('new-content-highlight'))
        // so `transition` stays on the element for the whole animation -
        // removing the base class immediately would strip `transition`
        // at the same instant as `box-shadow`, snapping the ring off
        // instead of fading it.
        el.classList.add('fading');
        setTimeout(function () {
          el.classList.remove('new-content-highlight', 'fading');
        }, NEW_CONTENT_HIGHLIGHT_FADE_MS);
      }, NEW_CONTENT_VISIBLE_DELAY_MS);
    });
  });

  // $beforeNode may be null - a poll cycle that only extended an already-
  // open tool-group (see tailGroupState) rather than inserting anything
  // brand new at the tail has no natural "new stuff starts here" position
  // to anchor a divider to (the growth happened mid-list, at the group's
  // own position, not at the bottom) - $entryElements (the group itself)
  // still gets highlighted either way, just no divider that poll cycle.
  function markNewContent(beforeNode, entryElements) {
    if (beforeNode) {
      if (currentDivider && currentDivider.parentNode) {
        currentDivider.parentNode.removeChild(currentDivider);
      }

      var divider = document.createElement('div');
      divider.className = 'select-none new-content-divider flex items-center gap-2 my-1 text-xs text-indigo-400';
      divider.innerHTML = '<span class="flex-1 border-t border-indigo-500/50"></span>'
        + '<span>New</span>'
        + '<span class="flex-1 border-t border-indigo-500/50"></span>';
      list.insertBefore(divider, beforeNode);
      currentDivider = divider;
    }

    entryElements.forEach(function (el) { el.classList.add('new-content-highlight'); });

    if (!newEntryObserver) {
      return; // no observer support - markers just stay put, harmless
    }

    entryElements.forEach(function (el) { newEntryObserver.observe(el); });

    if (!beforeNode) {
      return; // no divider this cycle - nothing to fade-and-remove later
    }

    var dividerObserver = new IntersectionObserver(function (observerEntries) {
      observerEntries.forEach(function (observerEntry) {
        if (!observerEntry.isIntersecting) {
          return;
        }

        dividerObserver.disconnect();

        setTimeout(function () {
          if (currentDivider !== divider) {
            return; // already replaced by a newer one, nothing left to fade
          }

          divider.classList.add('fading');
          divider.addEventListener('transitionend', function () {
            if (divider.parentNode) {
              divider.parentNode.removeChild(divider);
            }
          }, { once: true });
          currentDivider = null;
        }, NEW_CONTENT_VISIBLE_DELAY_MS);
      });
    });

    dividerObserver.observe(divider);
  }

  function pollHistory(wasNearBottom) {
    if (!list) {
      return Promise.resolve(); // no transcript for this session - nothing to append to
    }

    // Once there's a known newestLine, ask the server for only what's newer
    // than it (see TranscriptService::read_transcript_page_since() on the
    // host-agent side) instead of re-fetching and re-filtering the same
    // recent window every single poll cycle - only the very first poll of a
    // session with no history at all yet (newestLine still null) falls back
    // to the plain "most recent N" fetch.
    var url = '/session_history.php?session=' + encodeURIComponent(sessionName) + '&limit=50'
      + (newestLine !== null ? '&after=' + newestLine : '');

    return fetch(url, { credentials: 'same-origin', signal: pollAbortController.signal })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          return;
        }

        // The server already guarantees every entry is newer than
        // newestLine (via &after= above) once that's known - this filter
        // only still does real work on the null-newestLine bootstrap poll,
        // where nothing's rendered yet and everything returned is "fresh".
        var fresh = (data.entries || []).filter(function (entry) {
          return newestLine === null || entry.line > newestLine;
        });

        if (fresh.length === 0) {
          return;
        }

        removeHistoryEmptyNote();
        reconcilePendingEntries(fresh);

        // tailGroupState persists across poll cycles (unlike loadMore()'s
        // own throwaway state) - see the block comment on it for why: a
        // call and its own result routinely land in separate poll cycles,
        // and this is what lets a later result upgrade an already-rendered
        // group member in place instead of appending a second card.
        var fragment = document.createDocumentFragment();
        var touchedElements = renderEntriesGrouped(fresh, tailGroupState, fragment);
        fresh.forEach(function (entry) { newestLine = entry.line; });
        var firstNewNode = fragment.firstChild;
        list.appendChild(fragment);
        markNewContent(firstNewNode, touchedElements);
        maybeAutoScroll(wasNearBottom);
      })
      .catch(function () {});
  }

  // Self-rescheduling (setTimeout, not setInterval) rather than a fixed
  // tick: the next cycle is only queued once this one's requests have all
  // actually come back, so a slow response (or a fast interval like the
  // 1s option) can never pile up overlapping in-flight requests - each
  // cycle waits its full interval AFTER the previous one finishes, not on
  // a fixed clock regardless of how long that previous one took.
  //
  // That guarantee only ever covered the regular timer's own cycle()
  // calls, though - sendComposedMessage() also calls pollOnce() directly,
  // to pick up the just-sent message right away rather than waiting for
  // the next tick. If a scheduled cycle was already in flight at that
  // exact moment, two genuinely concurrent pollHistory() calls could
  // race: each computes `fresh`/reconciles pendingEntries against its own
  // snapshot of the shared, mutable `newestLine`, which is read again at
  // response-processing time rather than pinned to what it was when that
  // response's own fetch was issued - found live 2026-08-08 as the cause
  // of an optimistic "Sending…" bubble surviving alongside the real,
  // already-confirmed entry once both arrived close together. The guard
  // below makes pollOnce() itself re-entrant-safe regardless of caller:
  // a call that arrives while one's already running just marks "run
  // once more after this" instead of starting a second overlapping pass.
  function pollOnce() {
    if (pollRunning) {
      pollQueuedAgain = true;

      return Promise.resolve();
    }

    pollRunning = true;

    // Captured once, synchronously, before either fetch fires - both
    // independent responses use this same snapshot so a poll cycle either
    // scrolls once (if the user was at the bottom when it started) or not
    // at all, never a half-scrolled-then-not gap.
    var wasNearBottom = isNearBottom();

    // Uploaded files only need refetching while the sidebar's actually
    // open and showing them - same visibility gate the swipe-gesture
    // code already uses elsewhere for "is the sidebar open right now".
    var sidebarCurrentlyOpen = sidebar && !sidebar.classList.contains('translate-x-full');

    return Promise.all([
      pollInfo(wasNearBottom),
      pollHistory(wasNearBottom),
      refreshSidebarNotification(),
      sidebarCurrentlyOpen ? loadUploadedFiles() : Promise.resolve(),
      sidebarCurrentlyOpen ? loadPlanFiles() : Promise.resolve()
    ]).finally(function () {
      pollRunning = false;

      if (pollQueuedAgain) {
        pollQueuedAgain = false;
        pollOnce();
      }
    });
  }

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

  // Belt and suspenders on top of stopPolling()'s abort (which only fires
  // for an explicit hidden/switch-away while this script is still alive) -
  // guarantees any poll mid-flight is cancelled the instant the browser
  // actually tears the page down, e.g. navigating to a different session
  // via the sidebar.
  window.addEventListener('pagehide', function () {
    pollAbortController.abort();
  });

  // Changing the interval mid-poll restarts the timer at the new rate
  // immediately, rather than waiting out whatever was left of the old one.
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

  // --- message compose bar: sends free text to the session at any time,
  // same as attaching and typing - see send_message() in Sessions.php for
  // why a tmux paste-buffer is used instead of send-keys with the raw
  // text. AJAX, not a page reload per send (unlike Kill/Approve, which
  // are rare enough that a reload is fine) - this is the primary,
  // repeated interaction the compose box exists for. ---
  var composeTextarea = document.getElementById('compose-textarea');
  var composeSendBtn = document.getElementById('compose-send-btn');
  var composeStatus = document.getElementById('compose-status');
  var composeAttachmentsPreview = document.getElementById('compose-attachments-preview');

  if (composeTextarea && composeSendBtn) {
    var COMPOSE_MAX_HEIGHT_PX = 128; // matches max-h-32
    var COMPOSE_DRAFT_KEY = 'csm-compose-draft-' + sessionName;
    var COMPOSE_ATTACHMENTS_KEY = 'csm-compose-attachments-' + sessionName;

    // Files uploaded via the "+" button but not yet sent - shown as their
    // own removable chips above the textarea (see renderComposeAttachments()
    // below), not appended as visible "[Attached: ...]" text into the
    // user's own draft the way this used to work. That text still reaches
    // Claude - SessionService::send_message() (host-agent) adds it silently
    // right before the message is actually sent, from the plain paths this
    // array tracks, so it's real bookkeeping Claude needs but never
    // something the user has to see or accidentally edit/delete themselves.
    var pendingAttachments = []; // {path, filename, size}

    function autoGrowCompose() {
      composeTextarea.style.height = 'auto';
      composeTextarea.style.height = Math.min(composeTextarea.scrollHeight, COMPOSE_MAX_HEIGHT_PX) + 'px';
    }

    // Dims/disables Send whenever there's nothing (or only whitespace) AND
    // no pending attachment to send - an attachment-only send (no typed
    // text at all) is valid, same as SessionService::send_message() allows
    // server-side.
    function updateSendButtonState() {
      composeSendBtn.disabled = composeTextarea.value.trim() === '' && pendingAttachments.length === 0;
    }

    // Per-session draft, so it survives navigating to the dashboard or
    // switching sessions via the sidebar and coming back - lost otherwise,
    // since the textarea itself doesn't persist across a page load.
    function saveComposeDraft() {
      try {
        if (composeTextarea.value) {
          window.localStorage.setItem(COMPOSE_DRAFT_KEY, composeTextarea.value);
        } else {
          window.localStorage.removeItem(COMPOSE_DRAFT_KEY);
        }
      } catch (e) {
        // Private browsing / storage disabled - draft just isn't persisted.
      }
    }

    function clearComposeDraft() {
      try {
        window.localStorage.removeItem(COMPOSE_DRAFT_KEY);
      } catch (e) {}
    }

    // Same per-session persistence as the typed draft above, its own
    // separate key - an upload made, then the page reloaded/navigated away
    // from before Send was pressed, shouldn't silently lose track of a file
    // that's already sitting in .claude/uploads/ waiting to be referenced.
    function saveComposeAttachments() {
      try {
        if (pendingAttachments.length > 0) {
          window.localStorage.setItem(COMPOSE_ATTACHMENTS_KEY, JSON.stringify(pendingAttachments));
        } else {
          window.localStorage.removeItem(COMPOSE_ATTACHMENTS_KEY);
        }
      } catch (e) {}
    }

    function clearComposeAttachments() {
      pendingAttachments = [];

      try {
        window.localStorage.removeItem(COMPOSE_ATTACHMENTS_KEY);
      } catch (e) {}
    }

    function renderComposeAttachments() {
      if (!composeAttachmentsPreview) {
        return;
      }

      if (pendingAttachments.length === 0) {
        composeAttachmentsPreview.innerHTML = '';
        composeAttachmentsPreview.classList.add('hidden');
        return;
      }

      composeAttachmentsPreview.classList.remove('hidden');
      composeAttachmentsPreview.innerHTML = pendingAttachments.map(function (a, i) {
        return '<span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-800 text-xs text-slate-300 pl-2 pr-1 py-1">'
          + '<span class="truncate max-w-[10rem]">' + escapeHtml(a.filename) + '</span>'
          + '<span class="shrink-0 text-slate-500">' + escapeHtml(formatFileSize(a.size)) + '</span>'
          + '<button type="button" class="remove-compose-attachment-btn select-none shrink-0 rounded-full w-5 h-5 flex items-center justify-center text-slate-400 active:text-red-300 active:bg-red-900/40" data-index="' + i + '" aria-label="Remove ' + escapeHtml(a.filename) + '">&times;</button>'
          + '</span>';
      }).join('');
    }

    try {
      var savedDraft = window.localStorage.getItem(COMPOSE_DRAFT_KEY);

      if (savedDraft) {
        composeTextarea.value = savedDraft;
        autoGrowCompose();
      }
    } catch (e) {}

    try {
      var savedAttachments = window.localStorage.getItem(COMPOSE_ATTACHMENTS_KEY);

      if (savedAttachments) {
        pendingAttachments = JSON.parse(savedAttachments) || [];
        renderComposeAttachments();
      }
    } catch (e) {}

    updateSendButtonState();

    function setComposeStatus(text) {
      if (text) {
        composeStatus.textContent = text;
        composeStatus.classList.remove('hidden');
      } else {
        composeStatus.textContent = '';
        composeStatus.classList.add('hidden');
      }
    }

    function sendComposedMessage() {
      var text = composeTextarea.value;

      if (text.trim() === '' && pendingAttachments.length === 0) {
        return;
      }

      composeTextarea.disabled = true;
      composeSendBtn.disabled = true;
      setComposeStatus('');

      // Mirrors SessionService::send_message()'s own "[Attached: path]"
      // line-building (host-agent) so the optimistic bubble shown here
      // already matches what the real transcript entry will read once it
      // actually arrives, even though the user's own draft never showed
      // this text at any point.
      var attachmentLines = pendingAttachments.map(function (a) { return '[Attached: ' + a.path + ']'; });
      var optimisticText = attachmentLines.length === 0 ? text : (text ? text.replace(/\s*$/, '') + '\n' : '') + attachmentLines.join('\n');

      var body = new URLSearchParams({ session: sessionName, csrf_token: csrfToken, message: text });
      pendingAttachments.forEach(function (a) { body.append('attachments[]', a.path); });

      var pendingEl = appendPendingEntry('user', [{ kind: 'text', text: optimisticText }]);
      savePendingMessageToStorage('user', optimisticText);

      fetch('/session_send.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        // keepalive: a plain page navigation (this app has no client-side
        // router - see CLAUDE.md - so leaving this session is always a
        // real browser navigation/unload) can otherwise abort an in-flight
        // fetch outright, not just lose track of it client-side - found
        // live 2026-08-08 as the likely cause behind a compose message
        // reported as genuinely gone, not just visually stale, after
        // navigating away fast enough. keepalive lets the request finish
        // even once the page that started it is gone. Body is a short
        // compose message/attachment-path list, well under the spec's
        // ~64KB keepalive request-body cap.
        keepalive: true,
        body: body.toString()
      })
        .then(function (r) { return parseJsonResponse(r, 'compose-send'); })
        .then(function (data) {
          if (data && data.ok) {
            composeTextarea.value = '';
            autoGrowCompose();
            clearComposeDraft();
            clearComposeAttachments();
            renderComposeAttachments();
            pollOnce(); // pick up the new message (and whatever happens next) right away, not on the next 15s tick
          } else {
            removePendingEntry(pendingEl);
            clearPendingMessageFromStorage();
            setComposeStatus((data && data.message) || 'Failed to send message.');
          }
        })
        .catch(function () {
          removePendingEntry(pendingEl);
          clearPendingMessageFromStorage();
          setComposeStatus('Network error - message not sent.');
        })
        .finally(function () {
          composeTextarea.disabled = false;
          updateSendButtonState();
          composeTextarea.focus();
        });
    }

    composeTextarea.addEventListener('input', function () {
      autoGrowCompose();
      saveComposeDraft();
      updateSendButtonState();
    });
    composeSendBtn.addEventListener('click', sendComposedMessage);

    // Plain Enter inserts a newline (the browser's own default - no
    // handling needed here); only Shift+Enter submits. The opposite of the
    // usual chat-box convention, deliberately: multi-line messages are
    // common enough here (pasted logs/commands) that submit-on-Enter kept
    // firing mid-paste/mid-thought. shiftKeyPhysicallyHeld cross-check -
    // see its own doc comment in common.js.
    composeTextarea.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.shiftKey && shiftKeyPhysicallyHeld) {
        e.preventDefault();
        sendComposedMessage();
      }
    });

    // --- attach files: uploads via /upload_file.php (relayed to the
    // host-agent, which writes into the session's own project workdir -
    // see save_uploaded_file() in Sessions.php), then adds each to
    // pendingAttachments as its own removable chip above the textarea
    // (renderComposeAttachments() above) - the actual "[Attached: path]"
    // text Claude needs is only ever added server-side at send time (see
    // sendComposedMessage() and SessionService::send_message()). ---
    var composeAttachBtn = document.getElementById('compose-attach-btn');
    var composeFileInput = document.getElementById('compose-file-input');
    var composeUploadStatus = document.getElementById('compose-upload-status');

    if (composeAttachBtn && composeFileInput && composeUploadStatus) {
      function setUploadStatus(text) {
        if (text) {
          composeUploadStatus.textContent = text;
          composeUploadStatus.classList.remove('hidden');
        } else {
          composeUploadStatus.textContent = '';
          composeUploadStatus.classList.add('hidden');
        }
      }

      function addPendingAttachment(path, filename, size) {
        pendingAttachments.push({ path: path, filename: filename, size: size });
        saveComposeAttachments();
        renderComposeAttachments();
        updateSendButtonState();

        // Immediate refresh (don't wait for the next poll tick) if the
        // sidebar's open and showing the list this upload just changed.
        if (sidebar && !sidebar.classList.contains('translate-x-full')) {
          loadUploadedFiles();
        }
      }

      // Resolves to true/false (success), never rejects - each file's
      // failure is reported via setUploadStatus() and shouldn't stop the
      // rest of a multi-file selection from still being attempted.
      function uploadOneFile(file) {
        var formData = new FormData();
        formData.append('session', sessionName);
        formData.append('csrf_token', csrfToken);
        formData.append('file', file);

        return fetch('/upload_file.php', { method: 'POST', credentials: 'same-origin', body: formData })
          .then(function (r) { return parseJsonResponse(r, 'upload-file'); })
          .then(function (data) {
            if (data && data.ok) {
              addPendingAttachment(data.path, data.filename, data.size);
              return true;
            }

            setUploadStatus('Failed to upload ' + file.name + ': ' + ((data && data.message) || 'Unknown error'));
            return false;
          })
          .catch(function () {
            setUploadStatus('Network error - ' + file.name + ' not uploaded.');
            return false;
          });
      }

      // Removing a pending (not-yet-sent) attachment deletes the real
      // uploaded file too, not just its chip - otherwise a changed-my-mind
      // upload sits abandoned in .claude/uploads/ forever with no other way
      // to clean it up. Delegated (not bound directly to each chip's own
      // button) since chips are rebuilt wholesale on every render.
      document.addEventListener('click', function (e) {
        var removeBtn = e.target.closest('.remove-compose-attachment-btn');

        if (!removeBtn) {
          return;
        }

        var index = parseInt(removeBtn.dataset.index, 10);
        var removed = pendingAttachments.splice(index, 1)[0];
        saveComposeAttachments();
        renderComposeAttachments();
        updateSendButtonState();

        if (!removed) {
          return;
        }

        fetch('/delete_uploaded_file.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken, filename: removed.filename }).toString()
        }).catch(function () {});

        if (sidebar && !sidebar.classList.contains('translate-x-full')) {
          loadUploadedFiles();
        }
      });

      composeAttachBtn.addEventListener('click', function () {
        composeFileInput.click();
      });

      composeFileInput.addEventListener('change', function () {
        var files = Array.prototype.slice.call(composeFileInput.files || []);

        if (files.length === 0) {
          return;
        }

        composeAttachBtn.disabled = true;
        var hadError = false;

        // Sequential, not Promise.all - keeps the appended attachment
        // lines in a stable, predictable order even if individual upload
        // response times vary, and avoids hammering the host-agent with
        // N simultaneous file writes for one multi-file selection.
        files.reduce(function (chain, file, index) {
          return chain.then(function () {
            setUploadStatus('Uploading ' + file.name + ' (' + (index + 1) + '/' + files.length + ')…');

            return uploadOneFile(file).then(function (ok) {
              if (!ok) {
                hadError = true;
              }
            });
          });
        }, Promise.resolve())
          .then(function () {
            if (!hadError) {
              setUploadStatus('');
            }
          })
          .finally(function () {
            composeAttachBtn.disabled = false;
            composeFileInput.value = '';
          });
      });
    }
  }

  // --- mode select: jumps directly to the chosen mode (set_mode() in
  // Sessions.php works out the Shift+Tab steps and sends them, spaced
  // 300ms apart - verified live that back-to-back presses with no gap
  // get dropped). The request blocks until every press is sent, so by
  // the time it resolves the mode has already changed - the extra 300ms
  // below is just for Claude Code's last status-line redraw to land
  // before polling re-reads it. Disabled for the same window so a second
  // change can't race the first. ---
  var modeSelect = document.getElementById('mode-select');

  if (modeSelect) {
    modeSelect.addEventListener('change', function () {
      var chosenMode = modeSelect.value;
      modeSelect.disabled = true;

      fetch('/session_mode.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken, mode: chosenMode }).toString()
      })
        .then(function () {
          setTimeout(pollOnce, 300);
        })
        .catch(function () {
          modeSelect.disabled = false;
        });
    });
  }

  // --- transcript images: start as a small square thumbnail (see
  // renderImageHtml()/render_transcript_image_html()), tapping toggles to
  // full size and back - not a separate lightbox/modal, just swapping the
  // sizing classes on the same <img> in place. ---
  var TRANSCRIPT_IMAGE_THUMB_CLASSES = ['w-24', 'h-24', 'object-cover'];
  var TRANSCRIPT_IMAGE_FULL_CLASSES = ['w-full', 'h-auto', 'object-contain'];

  document.addEventListener('click', function (e) {
    var img = e.target.closest('.transcript-image');

    if (!img) {
      return;
    }

    var isThumbnail = img.classList.contains('w-24');
    img.classList.remove.apply(img.classList, isThumbnail ? TRANSCRIPT_IMAGE_THUMB_CLASSES : TRANSCRIPT_IMAGE_FULL_CLASSES);
    img.classList.add.apply(img.classList, isThumbnail ? TRANSCRIPT_IMAGE_FULL_CLASSES : TRANSCRIPT_IMAGE_THUMB_CLASSES);
  });

  // --- collapsible tool_use/tool_result blocks: tapping anywhere on one
  // toggles it, collapsed OR expanded (including inside the expanded
  // <pre>), not just the exact summary text/marker - a real mobile
  // annoyance otherwise (small precise tap target to collapse a long
  // command/output back down). The <summary>'s own native click-to-toggle
  // already handles the collapsed case (helped along by the block/w-full
  // class on it, so the whole row is a real tap target, not just wherever
  // the text glyphs render); this delegated handler is the backstop for
  // the rest of the <details> box, expanded content included. Three things
  // it must never do: double-toggle a tap that landed on <summary> itself
  // (native behavior already fired), collapse out from under an active
  // text selection (a plain click event doesn't fire for a scroll-drag
  // gesture to begin with, so normal scrolling/reading is unaffected
  // either way - this guard is specifically for "tap elsewhere to dismiss
  // a selection", not scrolling), or fire on the "View full screen" button
  // (see common.js) - collapsing the block right as its own fullscreen
  // modal opens over it would leave it collapsed once the modal closes. ---
  document.addEventListener('click', function (e) {
    if (e.target.closest('summary, .expand-fullscreen-btn')) {
      return;
    }

    var details = e.target.closest('.tool-use-block details, .tool-detail details');

    if (!details) {
      return;
    }

    var selection = window.getSelection();

    if (selection && !selection.isCollapsed) {
      return;
    }

    details.open = !details.open;
  });

  // --- stop button: interrupts Claude mid-response (sends Escape, same
  // as pressing it while attached). Delegated at the document level, not
  // attached directly to the button, since renderThinkingIndicator()
  // recreates it (or removes it entirely) on every poll. ---
  document.addEventListener('click', function (e) {
    var stopBtn = e.target.closest('#stop-btn');

    if (!stopBtn) {
      return;
    }

    stopBtn.disabled = true;

    fetch('/session_escape.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken }).toString()
    })
      .then(function (r) { return parseJsonResponse(r, 'session-escape'); })
      .then(function (data) {
        if (!data || !data.ok) {
          alert((data && data.message) || 'Failed to stop.');
        }

        setTimeout(pollOnce, 300);
      })
      .catch(function () {
        alert('Network error - stop not sent.');
      })
      .finally(function () {
        stopBtn.disabled = false;
      });
  });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      startPolling();
    } else {
      stopPolling();
    }
  });

  // Restored before the initial scroll-to-bottom below, so if it's the
  // newest thing on the page, landing at the bottom actually shows it.
  restorePendingMessageFromStorage();

  // Land at the bottom on open - the current/latest activity (and any
  // pending prompt) is what matters first, same as any chat app.
  scrollToBottom(false);
  updateGoToBottomVisibility();

  if (document.visibilityState === 'visible') {
    startPolling();
  }
})();
