<?php
declare(strict_types=1);

require __DIR__ . '/lib/AgentClient.php';
require __DIR__ . '/lib/Auth.php';

require_basic_auth();

/* ---------- handle actions (POST) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!same_origin_or_no_origin()) {
        http_response_code(403);
        echo "Rejected: cross-origin request.";
        exit;
    }

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

        default:
            $ok = false;
            $message = 'Unknown action';
    }

    $redirect = '/?msg=' . rawurlencode($message) . '&ok=' . ($ok ? '1' : '0');
    header('Location: ' . $redirect, true, 303);
    exit;
}

/* ---------- render (GET) ---------- */

$listResult = agent_call(['action' => 'list']);
$agentReachable = (bool)($listResult['ok'] ?? false);
$sessions = $agentReachable ? ($listResult['sessions'] ?? []) : [];
$bare = $agentReachable ? ($listResult['bare'] ?? []) : [];

$flashMsg = isset($_GET['msg']) ? (string)$_GET['msg'] : null;
$flashOk = ($_GET['ok'] ?? '1') === '1';
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

  <details id="new-session-details" class="mb-3 rounded-xl border border-slate-800 bg-slate-900/50">
    <summary id="new-session-summary" class="min-h-[3rem] flex items-center justify-center rounded-xl bg-indigo-600 active:bg-indigo-700 font-medium text-base px-4 py-3 cursor-pointer list-none [&::-webkit-details-marker]:hidden">
      + New Session
    </summary>
    <form method="post" action="/" class="px-4 pt-4 pb-4 flex flex-col gap-3" id="new-session-form">
      <input type="hidden" name="action" value="new">
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
        <li class="rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-3 flex items-center justify-between gap-3">
          <div class="min-w-0">
            <div class="text-sm truncate"><?= htmlspecialchars($s['title'] ?? $s['name'], ENT_QUOTES) ?></div>
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
          </div>
          <form method="post" action="/" onsubmit="return confirm('Kill session <?= htmlspecialchars($s['name'], ENT_QUOTES) ?>?');">
            <input type="hidden" name="action" value="kill">
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
          <li class="rounded-xl border border-slate-800/60 bg-slate-900/30 px-4 py-3">
            <div class="font-mono text-sm text-slate-300">pid <?= (int)$b['pid'] ?></div>
            <?php if (!empty($b['cwd'])): ?>
              <div class="text-xs text-slate-500 truncate mt-0.5"><?= htmlspecialchars($b['cwd'], ENT_QUOTES) ?></div>
            <?php endif; ?>
            <div class="text-xs text-slate-500 mt-1">
              <?= $b['started_at'] !== null ? htmlspecialchars(relative_time($b['started_at']), ENT_QUOTES) : 'start time unknown' ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="fixed bottom-0 inset-x-0 bg-slate-950/90 backdrop-blur border-t border-slate-800 px-4 py-3">
    <div class="max-w-2xl mx-auto flex items-start justify-between gap-3">
      <div id="quota-info" class="flex flex-wrap items-baseline gap-x-3 gap-y-1 min-w-0 text-xl font-medium" aria-live="polite">
        <span class="text-slate-500">Loading quota&hellip;</span>
      </div>
      <a href="/"
        class="min-h-[2.75rem] flex items-center rounded-lg bg-slate-800 active:bg-slate-700 font-medium text-sm px-4 py-2 shrink-0">
        Refresh
      </a>
    </div>
  </div>

</div>
<script>
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

(function () {
  var el = document.getElementById('quota-info');

  function pctColorClass(pct) {
    if (pct >= 90) return 'text-red-400';
    if (pct >= 70) return 'text-amber-400';
    return 'text-slate-300';
  }

  function label(key) {
    if (key === 'session') return 'Session';
    if (key === 'week_all') return 'Week';
    return key.replace(/^week_/, '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }) + ' (week)';
  }

  // No leading zeros by construction (Math.floor results are used bare).
  function formatDuration(seconds, kind) {
    if (seconds <= 0) return 'now';

    if (kind === 'session') {
      var h = Math.floor(seconds / 3600);
      var m = Math.floor((seconds % 3600) / 60);
      return h > 0 ? (h + 'h ' + m + 'm') : (m + 'm');
    }

    var d = Math.floor(seconds / 86400);
    var wh = Math.floor((seconds % 86400) / 3600);
    return d > 0 ? (d + 'd ' + wh + 'h') : (wh + 'h');
  }

  function showUnavailable(data) {
    el.title = '';
    el.innerHTML = '';
    var line = document.createElement('span');
    line.className = 'text-slate-600';
    line.textContent = 'Quota unavailable' + (data && data.message ? ': ' + data.message : '');
    el.appendChild(line);
  }

  function render(data) {
    if (!data || !data.quota) {
      showUnavailable(data);
      return;
    }

    var q = data.quota;
    var order = ['session', 'week_all'].concat(Object.keys(q).filter(function (k) {
      return k.indexOf('week_') === 0 && k !== 'week_all';
    }).sort());

    var nowSeconds = Math.floor(Date.now() / 1000);
    var lines = [];

    order.forEach(function (key) {
      var bar = q[key];
      if (!bar || typeof bar.pct !== 'number') return;

      var text = label(key) + ' ' + bar.pct + '%';

      if (typeof bar.resets_at === 'number') {
        var kind = key === 'session' ? 'session' : 'week';
        text += ' · resets ' + formatDuration(bar.resets_at - nowSeconds, kind);
      }

      lines.push({ text: text, pct: bar.pct });
    });

    if (lines.length === 0) {
      showUnavailable(data);
      return;
    }

    var metaParts = [];
    if (data.cached) metaParts.push(data.stale ? 'cached, stale' : 'cached');
    if (data.refreshing) metaParts.push('refreshing in background…');

    el.title = q.captured_at ? 'Captured ' + q.captured_at : '';
    el.innerHTML = '';

    // A left border marks every item after the first when there's room for
    // them to sit on one row (sm: and up). On mobile, where each bucket
    // stacks onto its own line, that border/padding is dropped so the text
    // lines up flush left instead of looking indented.
    lines.forEach(function (line, i) {
      var item = document.createElement('span');
      item.className = pctColorClass(line.pct) + (i > 0 ? ' sm:pl-3 sm:border-l sm:border-slate-700' : '');
      item.textContent = line.text;
      el.appendChild(item);
    });

    if (metaParts.length > 0) {
      var meta = document.createElement('span');
      meta.className = 'text-sm font-normal text-slate-400';
      meta.textContent = '(' + metaParts.join(' · ') + ')';
      el.appendChild(meta);
    }
  }

  var loading = false;

  function load() {
    if (loading) return; // a slow request is still out there - don't pile another on top of it
    loading = true;

    fetch('/quota.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(render)
      .catch(function () {
        showUnavailable(null);
      })
      .finally(function () {
        loading = false;
      });
  }

  load();
  setInterval(load, 60000);
})();
</script>
</body>
</html>
