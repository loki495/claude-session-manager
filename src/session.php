<?php
declare(strict_types=1);

require __DIR__ . '/lib/AgentClient.php';
require __DIR__ . '/lib/Auth.php';

require_basic_auth();
start_app_session();

$sessionName = trim((string)($_GET['session'] ?? $_POST['session'] ?? ''));

if ($sessionName === '') {
    header('Location: /', true, 303);
    exit;
}

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
        case 'answer_prompt':
            $option = (int)($_POST['option'] ?? 0);
            $result = agent_call(['action' => 'answer_prompt', 'session' => $sessionName, 'option' => $option]);
            $ok = (bool)($result['ok'] ?? false);
            $message = (string)($result['message'] ?? 'Unknown error');
            break;

        default:
            $ok = false;
            $message = 'Unknown action';
    }

    $_SESSION['flash'] = ['msg' => $message, 'ok' => $ok];
    header('Location: /session.php?session=' . rawurlencode($sessionName), true, 303);
    exit;
}

$csrfToken = csrf_token();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashMsg = is_array($flash) ? (string)($flash['msg'] ?? '') : null;
$flashOk = !is_array($flash) || ($flash['ok'] ?? true);

$detail = agent_call(['action' => 'session_detail', 'session' => $sessionName]);
$found = (bool)($detail['ok'] ?? false);

$history = $found ? agent_call(['action' => 'session_history', 'session' => $sessionName, 'before' => null, 'limit' => 30]) : ['ok' => false];
$historyOk = (bool)($history['ok'] ?? false);
$entries = $historyOk ? ($history['entries'] ?? []) : [];
$nextBefore = $historyOk ? ($history['next_before'] ?? null) : null;
$hasMore = $historyOk && ($history['has_more'] ?? false);
$newestLine = !empty($entries) ? end($entries)['line'] : null;

/**
 * @param array{kind:string, text:string} $block
 */
function render_transcript_block(array $block): string
{
    $text = htmlspecialchars($block['text'], ENT_QUOTES);

    return match ($block['kind']) {
        'text' => '<p class="whitespace-pre-wrap text-sm text-slate-100">' . $text . '</p>',
        'thinking' => '<p class="whitespace-pre-wrap text-xs italic text-slate-500">Thinking: ' . $text . '</p>',
        'tool_use' => '<span class="inline-block rounded bg-slate-800 px-2 py-0.5 text-xs text-sky-300">&rarr; ' . $text . '</span>',
        'tool_result' => '<pre class="whitespace-pre-wrap break-all rounded border border-slate-800 bg-slate-950/60 px-2 py-1.5 text-xs text-slate-400">' . $text . '</pre>',
        default => $text !== '' ? '<p class="text-xs text-slate-600">' . $text . '</p>' : '',
    };
}

/**
 * The session-info card's inner content (title/name/workdir/activity,
 * blocked-prompt panel, Approve/Deny buttons) - used for the initial
 * render and mirrored in JS for the visibility-gated poll that keeps it
 * live without a page reload (see the inline script).
 */
function render_session_info_html(array $detail, string $sessionName, string $csrfToken): string
{
    $html = '<div class="text-base font-medium truncate">' . htmlspecialchars((string)($detail['title'] ?? $detail['name']), ENT_QUOTES) . '</div>';
    $html .= '<div class="font-mono text-xs text-slate-500 truncate mt-0.5">' . htmlspecialchars((string)$detail['name'], ENT_QUOTES) . '</div>';

    if (!empty($detail['workdir'])) {
        $html .= '<div class="text-xs text-slate-500 truncate mt-0.5">' . htmlspecialchars((string)$detail['workdir'], ENT_QUOTES) . '</div>';
    }

    $html .= '<div class="text-xs text-slate-400 mt-1 flex items-center gap-2">';
    $html .= '<span>' . htmlspecialchars(relative_time((int)$detail['activity']), ENT_QUOTES) . '</span>';
    $html .= '<span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span>';
    $html .= $detail['attached'] ? '<span class="text-emerald-400">attached</span>' : '<span class="text-slate-500">detached</span>';
    $html .= '</div>';

    $html .= blocked_prompt_panel_html($detail);

    if (!empty($detail['prompt_context'])) {
        $html .= '<pre class="mt-2 whitespace-pre-wrap break-words rounded border border-amber-800/40 bg-slate-950/60 px-2 py-1.5 text-[11px] text-amber-100/80 max-h-48 overflow-y-auto">'
            . htmlspecialchars((string)$detail['prompt_context'], ENT_QUOTES)
            . '</pre>';
    }

    if (!empty($detail['prompt_options'])) {
        $html .= '<div class="mt-2 flex flex-wrap gap-2">';

        foreach ($detail['prompt_options'] as $opt) {
            $label = htmlspecialchars((string)$opt['label'], ENT_QUOTES);
            $number = (int)$opt['number'];
            $html .= '<form method="post" action="/session.php?session=' . urlencode($sessionName) . '" data-confirm-label="' . $label . '">'
                . '<input type="hidden" name="action" value="answer_prompt">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES) . '">'
                . '<input type="hidden" name="session" value="' . htmlspecialchars($sessionName, ENT_QUOTES) . '">'
                . '<input type="hidden" name="option" value="' . $number . '">'
                . '<button type="submit" class="rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2">'
                . $number . '. ' . $label
                . '</button>'
                . '</form>';
        }

        $html .= '</div>';
    }

    return $html;
}

/**
 * @param array{role:?string, timestamp:?string, blocks:array<int, array{kind:string, text:string}>} $entry
 */
function render_transcript_entry(array $entry): string
{
    $role = $entry['role'] ?? 'system';
    $roleLabel = htmlspecialchars(ucfirst((string)$role), ENT_QUOTES);
    $timestamp = htmlspecialchars((string)($entry['timestamp'] ?? ''), ENT_QUOTES);

    $blocksHtml = implode('', array_map('render_transcript_block', $entry['blocks']));

    return '<div class="rounded-lg border border-slate-800 bg-slate-900/50 px-3 py-2">'
        . '<div class="mb-1 flex items-center gap-2 text-xs text-slate-500">'
        . '<span class="font-medium text-slate-400">' . $roleLabel . '</span>'
        . ($timestamp !== '' ? '<span>' . $timestamp . '</span>' : '')
        . '</div>'
        . '<div class="flex flex-col gap-1.5">' . $blocksHtml . '</div>'
        . '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Claude Session Manager</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="max-w-2xl mx-auto px-4 py-6 pb-16">

  <header class="mb-4">
    <a href="/" class="text-sm text-slate-400 hover:underline">&larr; All sessions</a>
  </header>

  <?php if ($flashMsg !== null && $flashMsg !== ''): ?>
    <div class="mb-4 rounded-lg px-4 py-3 text-sm <?= $flashOk ? 'bg-emerald-900/50 text-emerald-200 border border-emerald-700' : 'bg-red-900/50 text-red-200 border border-red-700' ?>">
      <?= htmlspecialchars($flashMsg, ENT_QUOTES) ?>
    </div>
  <?php endif; ?>

  <?php if (!$found): ?>
    <div class="rounded-lg px-4 py-3 text-sm bg-red-900/50 text-red-200 border border-red-700">
      <p class="font-medium">Session not found.</p>
      <p class="mt-1"><?= htmlspecialchars((string)($detail['message'] ?? 'Unknown error'), ENT_QUOTES) ?></p>
    </div>
  <?php else: ?>
    <div id="session-info" class="mb-4 rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-3">
      <?= render_session_info_html($detail, $sessionName, $csrfToken) ?>
    </div>

    <h2 class="text-sm font-medium text-slate-400 mb-2">History</h2>

    <?php if (!$historyOk): ?>
      <div class="rounded-lg px-4 py-3 text-sm bg-slate-900/50 border border-slate-800 text-slate-500">
        <?= htmlspecialchars((string)($history['message'] ?? 'No transcript available for this session.'), ENT_QUOTES) ?>
      </div>
    <?php elseif (empty($entries)): ?>
      <div class="rounded-lg px-4 py-3 text-sm bg-slate-900/50 border border-slate-800 text-slate-500">
        Nothing recorded yet.
      </div>
    <?php else: ?>
      <button type="button" id="load-more-btn"
        data-session="<?= htmlspecialchars($sessionName, ENT_QUOTES) ?>"
        data-before="<?= $nextBefore !== null ? (int)$nextBefore : '' ?>"
        class="w-full mb-2 rounded-lg border border-slate-800 bg-slate-900/50 active:bg-slate-800 text-xs text-slate-400 px-3 py-2 <?= $hasMore ? '' : 'hidden' ?>">
        Load older messages
      </button>
      <div id="history-list" class="flex flex-col gap-2">
        <?php foreach ($entries as $entry): ?>
          <?= render_transcript_entry($entry) ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

</div>
<script>
(function () {
  var infoBox = document.getElementById('session-info');

  if (!infoBox) {
    return; // session not found - nothing here to wire up
  }

  var sessionName = <?= json_encode($sessionName) ?>;
  var csrfToken = <?= json_encode($csrfToken) ?>;
  var btn = document.getElementById('load-more-btn');
  var list = document.getElementById('history-list');
  var newestLine = <?= json_encode($newestLine) ?>;

  var ROLE_LABELS = { user: 'User', assistant: 'Assistant', system: 'System' };
  var POLL_INTERVAL_MS = 15000;

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Mirrors host-agent's relative_time() (see src/lib/AgentClient.php) so
  // a poll-refreshed timestamp reads the same as the server-rendered one.
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

  function renderBlock(block) {
    var text = escapeHtml(block.text);

    switch (block.kind) {
      case 'text':
        return '<p class="whitespace-pre-wrap text-sm text-slate-100">' + text + '</p>';
      case 'thinking':
        return '<p class="whitespace-pre-wrap text-xs italic text-slate-500">Thinking: ' + text + '</p>';
      case 'tool_use':
        return '<span class="inline-block rounded bg-slate-800 px-2 py-0.5 text-xs text-sky-300">&rarr; ' + text + '</span>';
      case 'tool_result':
        return '<pre class="whitespace-pre-wrap break-all rounded border border-slate-800 bg-slate-950/60 px-2 py-1.5 text-xs text-slate-400">' + text + '</pre>';
      default:
        return text ? '<p class="text-xs text-slate-600">' + text + '</p>' : '';
    }
  }

  function renderEntry(entry) {
    var roleLabel = ROLE_LABELS[entry.role] || (entry.role ? escapeHtml(entry.role) : 'System');
    var timestamp = entry.timestamp ? escapeHtml(entry.timestamp) : '';
    var blocksHtml = (entry.blocks || []).map(renderBlock).join('');

    var div = document.createElement('div');
    div.className = 'rounded-lg border border-slate-800 bg-slate-900/50 px-3 py-2';
    div.innerHTML = '<div class="mb-1 flex items-center gap-2 text-xs text-slate-500">'
      + '<span class="font-medium text-slate-400">' + roleLabel + '</span>'
      + (timestamp ? '<span>' + timestamp + '</span>' : '')
      + '</div>'
      + '<div class="flex flex-col gap-1.5">' + blocksHtml + '</div>';

    return div;
  }

  // Mirrors render_session_info_html() in session.php - kept alongside
  // renderEntry()/renderBlock() as the JS-side counterpart of the same PHP
  // renderer, both feeding this one visibility-gated poll.
  function renderInfoPanel(detail) {
    var html = '<div class="text-base font-medium truncate">' + escapeHtml(detail.title || detail.name) + '</div>'
      + '<div class="font-mono text-xs text-slate-500 truncate mt-0.5">' + escapeHtml(detail.name) + '</div>';

    if (detail.workdir) {
      html += '<div class="text-xs text-slate-500 truncate mt-0.5">' + escapeHtml(detail.workdir) + '</div>';
    }

    html += '<div class="text-xs text-slate-400 mt-1 flex items-center gap-2">'
      + '<span>' + escapeHtml(relativeTimeLabel(detail.activity)) + '</span>'
      + '<span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span>'
      + (detail.attached ? '<span class="text-emerald-400">attached</span>' : '<span class="text-slate-500">detached</span>')
      + '</div>';

    if (detail.blocked_reason) {
      html += '<div class="mt-2 rounded-lg px-3 py-2 text-xs bg-amber-900/40 text-amber-200 border border-amber-700/60">'
        + '<p class="font-medium">Waiting on input: ' + escapeHtml(detail.blocked_reason) + '</p>';

      if (detail.resume_hint) {
        html += '<p class="mt-1 text-amber-300/90">Attach to answer it:</p>'
          + '<code class="block mt-0.5 font-mono text-[11px] text-amber-100 break-all select-all">' + escapeHtml(detail.resume_hint) + '</code>';
      }

      html += '</div>';

      if (detail.prompt_context) {
        html += '<pre class="mt-2 whitespace-pre-wrap break-words rounded border border-amber-800/40 bg-slate-950/60 px-2 py-1.5 text-[11px] text-amber-100/80 max-h-48 overflow-y-auto">'
          + escapeHtml(detail.prompt_context)
          + '</pre>';
      }

      if (detail.prompt_options && detail.prompt_options.length) {
        html += '<div class="mt-2 flex flex-wrap gap-2">';

        detail.prompt_options.forEach(function (opt) {
          var label = escapeHtml(opt.label);
          html += '<form method="post" action="/session.php?session=' + encodeURIComponent(sessionName) + '" data-confirm-label="' + label + '">'
            + '<input type="hidden" name="action" value="answer_prompt">'
            + '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrfToken) + '">'
            + '<input type="hidden" name="session" value="' + escapeHtml(sessionName) + '">'
            + '<input type="hidden" name="option" value="' + opt.number + '">'
            + '<button type="submit" class="rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2">'
            + opt.number + '. ' + label
            + '</button></form>';
        });

        html += '</div>';
      }
    }

    infoBox.innerHTML = html;
  }

  // Event delegation, not per-form listeners: covers both the
  // PHP-rendered forms on first paint and any poll-rebuilt ones, without
  // needing to re-attach anything after renderInfoPanel() replaces the DOM.
  infoBox.addEventListener('submit', function (e) {
    var form = e.target.closest('form[data-confirm-label]');

    if (form && !confirm('Send "' + form.dataset.confirmLabel + '" to this session?')) {
      e.preventDefault();
    }
  });

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

        var fragment = document.createDocumentFragment();
        (data.entries || []).forEach(function (entry) { fragment.appendChild(renderEntry(entry)); });
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
  var pollTimer = null;

  function pollInfo() {
    fetch('/session_detail.php?session=' + encodeURIComponent(sessionName), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) {
          renderInfoPanel(data);
        }
      })
      .catch(function () {});
  }

  function pollHistory() {
    if (!list) {
      return; // no transcript for this session - nothing to append to
    }

    fetch('/session_history.php?session=' + encodeURIComponent(sessionName) + '&limit=50', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          return;
        }

        var fresh = (data.entries || []).filter(function (entry) {
          return newestLine === null || entry.line > newestLine;
        });

        if (fresh.length === 0) {
          return;
        }

        var fragment = document.createDocumentFragment();
        fresh.forEach(function (entry) {
          fragment.appendChild(renderEntry(entry));
          newestLine = entry.line;
        });
        list.appendChild(fragment);
      })
      .catch(function () {});
  }

  function pollOnce() {
    pollInfo();
    pollHistory();
  }

  function startPolling() {
    if (pollTimer !== null) {
      return;
    }

    pollOnce();
    pollTimer = setInterval(pollOnce, POLL_INTERVAL_MS);
  }

  function stopPolling() {
    if (pollTimer === null) {
      return;
    }

    clearInterval(pollTimer);
    pollTimer = null;
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
