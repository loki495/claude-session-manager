<?php
declare(strict_types=1);

require __DIR__ . '/lib/AgentClient.php';
require __DIR__ . '/lib/Auth.php';

start_app_session();

/* ---------- handle actions (POST) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!same_origin_or_no_origin()) {
        http_response_code(403);
        echo "Rejected: cross-origin request.";
        exit;
    }

    require_csrf();

    $action = $_POST['action'] ?? '';
    $message = '';
    $ok = true;

    switch ($action) {
        case 'new':
            $workdir = trim((string)($_POST['workdir'] ?? ''));
            $result = agent_call(['action' => 'create', 'workdir' => $workdir]);
            $ok = (bool)($result['ok'] ?? false);
            $message = (string)($result['message'] ?? 'Unknown error');
            break;

        case 'kill':
            $requested = (string)($_POST['session'] ?? '');
            $result = agent_call(['action' => 'kill', 'session' => $requested]);
            $ok = (bool)($result['ok'] ?? false);
            $message = (string)($result['message'] ?? 'Unknown error');
            break;

        case 'kill_bare':
            $pid = (int)($_POST['pid'] ?? 0);
            $result = agent_call(['action' => 'kill_bare', 'pid' => $pid]);
            $ok = (bool)($result['ok'] ?? false);
            $message = (string)($result['message'] ?? 'Unknown error');
            break;

        case 'cleanup':
            $result = agent_call(['action' => 'cleanup']);
            $killed = $result['killed'] ?? [];
            $failed = $result['failed'] ?? [];
            $ok = (bool)($result['ok'] ?? false);
            $message = count($killed) > 0
                ? 'Killed: ' . implode(', ', $killed)
                : 'No sessions inactive for more than 12h';
            if (!empty($failed)) {
                $message .= ' (failed to kill: ' . implode(', ', $failed) . ')';
            }
            break;

        case 'install_hook':
            $result = agent_call(['action' => 'install_session_hook']);
            $ok = (bool)($result['ok'] ?? false);
            $message = $ok
                ? 'App hooks installed in ~/.claude/settings.json.'
                : (string)($result['message'] ?? 'Failed to install hooks');
            break;

        default:
            $ok = false;
            $message = 'Unknown action';
    }

    $_SESSION['flash'] = ['msg' => $message, 'ok' => $ok];
    header('Location: /', true, 303);
    exit;
}

/* ---------- render (GET) ---------- */

$listResult = agent_call(['action' => 'list']);
$agentReachable = (bool)($listResult['ok'] ?? false);
$sessions = $agentReachable ? ($listResult['sessions'] ?? []) : [];
$bare = $agentReachable ? ($listResult['bare'] ?? []) : [];

// Only checked when the agent is reachable at all - no point surfacing a
// second, redundant warning about host state we already can't see.
$hookResult = $agentReachable ? agent_call(['action' => 'check_session_hook']) : ['ok' => false];
$hookCheckOk = (bool)($hookResult['ok'] ?? false);
$hookInstalled = (bool)($hookResult['installed'] ?? false);

$pushResult = $agentReachable ? agent_call(['action' => 'push_public_key']) : ['ok' => false];
$vapidPublicKey = (string)($pushResult['public_key'] ?? '');

$healthResult = $agentReachable ? agent_call(['action' => 'health_check']) : ['ok' => false];
$healthChecks = (bool)($healthResult['ok'] ?? false) ? ($healthResult['checks'] ?? []) : [];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashMsg = is_array($flash) ? (string)($flash['msg'] ?? '') : null;
$flashOk = !is_array($flash) || ($flash['ok'] ?? true);

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Claude Session Manager</title>
<link rel="manifest" href="data:application/manifest+json,%7B%22name%22%3A%22Claude%20Sessions%22%2C%22display%22%3A%22standalone%22%7D">
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="max-w-2xl mx-auto px-4 py-6 pb-32">

  <header class="mb-6 flex items-start justify-between gap-2">
    <div class="min-w-0">
      <h1 class="text-xl font-semibold tracking-tight">Claude Session Manager</h1>
      <p id="session-count-text" class="text-sm text-slate-400 mt-1"><?= session_count_label_html(count($sessions)) ?></p>
    </div>
    <select id="poll-interval-select" aria-label="Polling interval"
      class="shrink-0 text-xs font-medium pl-1.5 pr-5 py-1 rounded-full border border-slate-700 bg-slate-800 text-slate-400">
      <option value="1000">1s</option>
      <option value="3000" selected>3s</option>
      <option value="5000">5s</option>
      <option value="10000">10s</option>
      <option value="15000">15s</option>
    </select>
  </header>

  <?php if (!$agentReachable): ?>
    <div class="mb-4 rounded-lg px-4 py-3 text-sm bg-red-900/50 text-red-200 border border-red-700">
      <p class="font-medium">Cannot reach the host agent.</p>
      <p class="mt-1"><?= htmlspecialchars((string)($listResult['message'] ?? 'Unknown error'), ENT_QUOTES) ?></p>
      <p class="mt-1 text-red-300">Check on the host: <code>systemctl --user status csm-agent.socket</code></p>
    </div>
  <?php endif; ?>

  <?php if ($flashMsg !== null && $flashMsg !== ''): ?>
    <div class="mb-4 rounded-lg px-4 py-3 text-sm <?= $flashOk ? 'bg-emerald-900/50 text-emerald-200 border border-emerald-700' : 'bg-red-900/50 text-red-200 border border-red-700' ?>">
      <?= htmlspecialchars($flashMsg, ENT_QUOTES) ?>
    </div>
  <?php endif; ?>

  <?php if ($agentReachable && $hookCheckOk && !$hookInstalled): ?>
    <div class="mb-4 rounded-lg px-4 py-3 text-sm bg-amber-900/40 text-amber-200 border border-amber-700/60">
      <p class="font-medium">App hooks aren't fully installed.</p>
      <p class="mt-1 text-amber-300/90">Without the SessionStart hook, a session's transcript view goes stale forever after a <code>/clear</code>, <code>/compact</code>, or resume. Without the PreToolUse hook, a blocked prompt's preview can come out truncated for long commands/files instead of showing the full thing.</p>
      <form method="post" action="/" class="mt-2">
        <input type="hidden" name="action" value="install_hook">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <button type="submit" class="rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2">
          Install hooks
        </button>
      </form>
    </div>
  <?php elseif ($agentReachable && !$hookCheckOk): ?>
    <div class="mb-4 rounded-lg px-4 py-3 text-sm bg-amber-900/40 text-amber-200 border border-amber-700/60">
      <p class="font-medium">Could not check the app hooks.</p>
      <p class="mt-1 text-amber-300/90"><?= htmlspecialchars((string)($hookResult['message'] ?? 'Unknown error'), ENT_QUOTES) ?></p>
    </div>
  <?php endif; ?>

  <?= health_box_html($healthChecks) ?>

  <details id="new-session-details" class="mb-3 rounded-xl border border-slate-800 bg-slate-900/50">
    <summary id="new-session-summary" class="min-h-[3rem] flex items-center justify-center rounded-xl bg-indigo-600 active:bg-indigo-700 font-medium text-base px-4 py-3 cursor-pointer list-none [&::-webkit-details-marker]:hidden">
      + New Session
    </summary>
    <form method="post" action="/" class="px-4 pt-4 pb-4 flex flex-col gap-3" id="new-session-form">
      <input type="hidden" name="action" value="new">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
      <input type="hidden" name="workdir" id="workdir_value">
      <div class="text-sm text-slate-300">Working directory</div>
      <div class="rounded-lg border border-slate-700 bg-slate-800 overflow-hidden">
        <div id="browser_path" class="px-3 py-2 text-xs font-mono text-slate-400 truncate border-b border-slate-700">Loading&hellip;</div>
        <ul id="browser_list" class="max-h-56 overflow-y-auto divide-y divide-slate-700/60 text-sm"></ul>
      </div>
      <button type="submit" id="new-session-submit" disabled
        class="min-h-[3rem] rounded-lg bg-indigo-600 active:bg-indigo-700 disabled:opacity-50 disabled:active:bg-indigo-600 font-medium text-base px-4 py-3">
        Start Session Here
      </button>
    </form>
  </details>

  <form method="post" action="/" class="mb-6" onsubmit="return confirm('Kill all cc-* sessions inactive for more than 12h?');">
    <input type="hidden" name="action" value="cleanup">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <button type="submit"
      class="w-full min-h-[3rem] rounded-lg bg-amber-700 active:bg-amber-800 font-medium text-base px-4 py-3">
      Kill inactive &gt;12h
    </button>
  </form>

  <?php if ($agentReachable): ?>
    <div id="sessions-container"><?= sessions_list_html($sessions, $csrfToken) ?></div>
  <?php endif; ?>

  <?php if ($agentReachable): ?>
    <div id="bare-container"><?= bare_processes_html($bare, $csrfToken) ?></div>
  <?php endif; ?>

  <div class="fixed bottom-0 inset-x-0 bg-slate-950/90 backdrop-blur border-t border-slate-800 px-4 py-3">
    <div class="max-w-2xl mx-auto">
      <div class="flex items-start justify-between gap-3">
        <?= quota_footer_html() ?>
        <a href="/"
          class="min-h-[2.75rem] flex items-center rounded-lg bg-slate-800 active:bg-slate-700 font-medium text-sm px-4 py-2 shrink-0">
          Refresh
        </a>
      </div>
      <?= push_notify_button_html($vapidPublicKey, $csrfToken) ?>
    </div>
  </div>

</div>
<script>
// Answer-prompt buttons (see blocked_prompt_rich_html() in AgentClient.php)
// use data-confirm-label the same way session.php's do - one delegated
// listener here instead of inline onsubmit, since these forms are
// rendered per-row and their count varies with how many sessions are
// currently blocked. AJAX, not a real form submission - answering a
// prompt shouldn't reload the whole dashboard. There's no live poll here
// yet (see the "poll for updates" todo item) to pick up the session's
// new state, so a successful answer just swaps the buttons for a quick
// confirmation note rather than fully re-syncing the row.
// Shared with session.php's sidebar checkbox (same localStorage key) -
// this page has no sidebar of its own to host the toggle, but still
// respects whatever the user set there.
var CONFIRM_BEFORE_ANSWER_KEY = 'csm-confirm-before-answer';

function shouldConfirmBeforeAnswer() {
  try {
    return window.localStorage.getItem(CONFIRM_BEFORE_ANSWER_KEY) !== '0';
  } catch (e) {
    return true;
  }
}

// Mirrors parseJsonResponse() in session.php - reads the raw response text
// and only then tries to parse it, so a parse failure can report the
// actual status code and a body snippet right in the alert, not just a
// bare "something went wrong".
function parseJsonResponse(r, label) {
  return r.text().then(function (text) {
    try {
      return JSON.parse(text);
    } catch (e) {
      return { ok: false, message: 'Unexpected response [' + label + '] (status ' + r.status + '): ' + text.slice(0, 200) };
    }
  });
}

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
          container.innerHTML = '<span class="text-xs text-emerald-400">&#10003; Sent - refresh to see the result</span>';
        }
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
// textarea is already a deliberate step. No live poll on this page yet,
// so a successful send swaps the same way the plain-option case above does.
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
        wrapper.innerHTML = '<span class="text-xs text-emerald-400">&#10003; Sent - refresh to see the result</span>';
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

document.addEventListener('keydown', function (e) {
  if (e.key === 'Enter' && !e.shiftKey && e.target.classList.contains('freetext-reply-textarea')) {
    e.preventDefault();
    submitFreetextReply(e.target.closest('.freetext-reply'));
  }
});

// "Show last 3 messages" toggle, one per session row. Lazy-loaded on
// first click (via session_history.php, the same endpoint session.php's
// "load more" uses) and cached in the DOM after that - toggling again
// just shows/hides rather than re-fetching.
(function () {
  var ROLE_LABELS = { user: 'User', assistant: 'Assistant', system: 'System' };

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Mirrors entry_color_kind()/entry_color_classes() in session.php - see
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
    p.innerHTML = '<span class="font-medium ' + colors.label + '">' + roleLabel + ':</span> ' + escapeHtml(text);
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
  var agentReachable = <?= json_encode($agentReachable) ?>;
  var sessionsContainer = document.getElementById('sessions-container');
  var bareContainer = document.getElementById('bare-container');
  var countText = document.getElementById('session-count-text');

  if (!agentReachable || !sessionsContainer) {
    return; // nothing to keep live - the "cannot reach host agent" banner is SSR-only
  }

  var POLL_INTERVAL_STORAGE_KEY = 'csm-poll-interval-ms';
  var POLL_INTERVAL_ALLOWED_MS = [1000, 3000, 5000, 10000, 15000];
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
</script>
</body>
</html>
