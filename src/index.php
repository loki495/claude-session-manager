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
                ? 'Session-rotation hook installed in ~/.claude/settings.json.'
                : (string)($result['message'] ?? 'Failed to install hook');
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

  <header class="mb-6">
    <h1 class="text-xl font-semibold tracking-tight">Claude Session Manager</h1>
    <p class="text-sm text-slate-400 mt-1"><?= count($sessions) ?> active <code>cc-*</code> session<?= count($sessions) === 1 ? '' : 's' ?></p>
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
      <p class="font-medium">Session-rotation hook isn't installed.</p>
      <p class="mt-1 text-amber-300/90">Without it, a session's transcript view goes stale forever after a <code>/clear</code>, <code>/compact</code>, or resume - Claude Code starts a new transcript file this app can't discover on its own.</p>
      <form method="post" action="/" class="mt-2">
        <input type="hidden" name="action" value="install_hook">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <button type="submit" class="rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2">
          Install hook
        </button>
      </form>
    </div>
  <?php elseif ($agentReachable && !$hookCheckOk): ?>
    <div class="mb-4 rounded-lg px-4 py-3 text-sm bg-amber-900/40 text-amber-200 border border-amber-700/60">
      <p class="font-medium">Could not check the session-rotation hook.</p>
      <p class="mt-1 text-amber-300/90"><?= htmlspecialchars((string)($hookResult['message'] ?? 'Unknown error'), ENT_QUOTES) ?></p>
    </div>
  <?php endif; ?>

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

  <?php if ($agentReachable && empty($sessions)): ?>
    <div class="rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-10 text-center text-slate-400">
      <p class="text-base">No active Claude sessions.</p>
      <p class="text-sm mt-1">Tap "New Session" to start one.</p>
    </div>
  <?php elseif ($agentReachable): ?>
    <ul class="flex flex-col gap-3">
      <?php foreach ($sessions as $s): ?>
        <li class="rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-3 flex items-start justify-between gap-3">
          <div class="min-w-0 flex-1">
            <div class="text-sm truncate">
              <a href="/session.php?session=<?= urlencode($s['name']) ?>" class="hover:underline"><?= htmlspecialchars($s['title'] ?? $s['name'], ENT_QUOTES) ?></a>
            </div>
            <?php if ($s['title'] !== null): ?>
              <div class="font-mono text-xs text-slate-500 truncate mt-0.5"><?= htmlspecialchars($s['name'], ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if (!empty($s['workdir'])): ?>
              <div class="text-xs text-slate-500 truncate mt-0.5"><?= htmlspecialchars($s['workdir'], ENT_QUOTES) ?></div>
            <?php endif; ?>
            <div class="text-xs text-slate-400 mt-1 flex items-center gap-2">
              <span><?= htmlspecialchars(relative_time($s['activity']), ENT_QUOTES) ?></span>
              <span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span>
              <?php if ($s['attached']): ?>
                <span class="text-emerald-400">attached</span>
              <?php else: ?>
                <span class="text-slate-500">detached</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($s['blocked_reason']) && !empty($s['prompt_is_folder_trust'])): ?>
              <?= blocked_prompt_panel_html($s) ?>
            <?php elseif (!empty($s['blocked_reason'])): ?>
              <?= blocked_prompt_rich_html($s, $csrfToken, true) ?>
            <?php else: ?>
              <?= last_message_preview_html($s['last_message'] ?? null, 'mt-1') ?>
            <?php endif; ?>
            <div class="mt-1">
              <button type="button" class="show-recent-btn text-xs font-medium text-indigo-400 active:text-indigo-300"
                data-session="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>" data-loaded="0">
                Show last 3 messages
              </button>
              <div class="recent-messages hidden mt-1 flex flex-col gap-1"></div>
            </div>
          </div>
          <form method="post" action="/" onsubmit="return confirm('Kill session <?= htmlspecialchars($s['name'], ENT_QUOTES) ?>?');">
            <input type="hidden" name="action" value="kill">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
            <input type="hidden" name="session" value="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>">
            <button type="submit"
              class="min-h-[2.75rem] shrink-0 rounded-lg bg-red-900/70 active:bg-red-800 text-red-100 font-medium text-sm px-4 py-2">
              Kill
            </button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($agentReachable && !empty($bare)): ?>
    <div class="mt-8">
      <h2 class="text-sm font-medium text-slate-400 mb-1">Other claude processes on host</h2>
      <p class="text-xs text-slate-500 mb-2">Not managed by this tool.</p>
      <ul class="flex flex-col gap-2">
        <?php foreach ($bare as $b): ?>
          <li class="rounded-xl border border-slate-800/60 bg-slate-900/30 px-4 py-3 flex items-center justify-between gap-3">
            <div class="min-w-0">
              <?php if (!empty($b['title'])): ?>
                <div class="text-sm truncate text-slate-300"><?= htmlspecialchars((string)$b['title'], ENT_QUOTES) ?></div>
              <?php endif; ?>
              <div class="font-mono text-xs text-slate-500 truncate mt-0.5">
                pid <?= (int)$b['pid'] ?><?= !empty($b['tmux_session']) ? ' · tmux: ' . htmlspecialchars((string)$b['tmux_session'], ENT_QUOTES) : ' · no tmux (plain process)' ?>
              </div>
              <?php if (!empty($b['cwd'])): ?>
                <div class="text-xs text-slate-500 truncate mt-0.5"><?= htmlspecialchars($b['cwd'], ENT_QUOTES) ?></div>
              <?php endif; ?>
              <div class="text-xs text-slate-500 mt-1">
                <?= $b['started_at'] !== null ? htmlspecialchars(relative_time($b['started_at']), ENT_QUOTES) : 'start time unknown' ?>
              </div>
            </div>
            <form method="post" action="/" onsubmit="return confirm('Kill pid <?= (int)$b['pid'] ?><?= !empty($b['tmux_session']) ? ' (tmux session ' . htmlspecialchars((string)$b['tmux_session'], ENT_QUOTES) . ')' : '' ?>? This process was not started by this tool.');">
              <input type="hidden" name="action" value="kill_bare">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
              <input type="hidden" name="pid" value="<?= (int)$b['pid'] ?>">
              <button type="submit"
                class="min-h-[2.75rem] shrink-0 rounded-lg bg-red-900/70 active:bg-red-800 text-red-100 font-medium text-sm px-4 py-2">
                Kill
              </button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="fixed bottom-0 inset-x-0 bg-slate-950/90 backdrop-blur border-t border-slate-800 px-4 py-3">
    <div class="max-w-2xl mx-auto flex items-start justify-between gap-3">
      <?= quota_footer_html() ?>
      <a href="/"
        class="min-h-[2.75rem] flex items-center rounded-lg bg-slate-800 active:bg-slate-700 font-medium text-sm px-4 py-2 shrink-0">
        Refresh
      </a>
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

  function renderRecentEntry(entry) {
    var roleLabel = ROLE_LABELS[entry.role] || (entry.role ? escapeHtml(entry.role) : 'System');
    var text = (entry.blocks && entry.blocks[0] && entry.blocks[0].text) || '';

    var p = document.createElement('p');
    p.className = 'text-xs text-slate-400 whitespace-pre-wrap break-words';
    p.innerHTML = '<span class="font-medium">' + roleLabel + ':</span> ' + escapeHtml(text);
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

    fetch('/session_history.php?session=' + encodeURIComponent(btn.dataset.session) + '&limit=3', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;

        if (!data || !data.ok || !data.entries || data.entries.length === 0) {
          btn.textContent = (data && data.message) || 'No messages to show.';
          return;
        }

        container.innerHTML = '';
        data.entries.forEach(function (entry) { container.appendChild(renderRecentEntry(entry)); });
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
</script>
</body>
</html>
