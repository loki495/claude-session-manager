<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/Auth.php';

use App\AgentClient;
use App\Assets;
use App\Views\HealthBoxView;
use App\Views\PushNotifyView;
use App\Views\QuotaFooterView;
use App\Views\SessionRowView;

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
            $result = AgentClient::agent_call(['action' => 'create', 'workdir' => $workdir]);
            $ok = (bool)($result['ok'] ?? false);
            $message = (string)($result['message'] ?? 'Unknown error');
            break;

        case 'kill':
            $requested = (string)($_POST['session'] ?? '');
            $result = AgentClient::agent_call(['action' => 'kill', 'session' => $requested]);
            $ok = (bool)($result['ok'] ?? false);
            $message = (string)($result['message'] ?? 'Unknown error');
            break;

        case 'kill_bare':
            $pid = (int)($_POST['pid'] ?? 0);
            $result = AgentClient::agent_call(['action' => 'kill_bare', 'pid' => $pid]);
            $ok = (bool)($result['ok'] ?? false);
            $message = (string)($result['message'] ?? 'Unknown error');
            break;

        case 'cleanup':
            $result = AgentClient::agent_call(['action' => 'cleanup']);
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
            $result = AgentClient::agent_call(['action' => 'install_session_hook']);
            $ok = (bool)($result['ok'] ?? false);
            $message = $ok
                ? 'App hooks installed in ~/.claude/settings.json.'
                : (string)($result['message'] ?? 'Failed to install hooks');
            break;

        case 'set_push_timer_interval':
            $seconds = (int)($_POST['seconds'] ?? 0);
            $result = AgentClient::agent_call(['action' => 'set_push_timer_interval', 'seconds' => $seconds]);
            $ok = (bool)($result['ok'] ?? false);
            $message = $ok
                ? "Push-check interval set to {$seconds}s."
                : (string)($result['message'] ?? 'Failed to update the push-check interval');
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

$listResult = AgentClient::agent_call(['action' => 'list']);
$agentReachable = (bool)($listResult['ok'] ?? false);
$sessions = $agentReachable ? ($listResult['sessions'] ?? []) : [];
$bare = $agentReachable ? ($listResult['bare'] ?? []) : [];

// Only checked when the agent is reachable at all - no point surfacing a
// second, redundant warning about host state we already can't see.
$hookResult = $agentReachable ? AgentClient::agent_call(['action' => 'check_session_hook']) : ['ok' => false];
$hookCheckOk = (bool)($hookResult['ok'] ?? false);
$hookInstalled = (bool)($hookResult['installed'] ?? false);

$pushResult = $agentReachable ? AgentClient::agent_call(['action' => 'push_public_key']) : ['ok' => false];
$vapidPublicKey = (string)($pushResult['public_key'] ?? '');

$healthResult = $agentReachable ? AgentClient::agent_call(['action' => 'health_check']) : ['ok' => false];
$healthChecks = (bool)($healthResult['ok'] ?? false) ? ($healthResult['checks'] ?? []) : [];

$pushTimerResult = $agentReachable ? AgentClient::agent_call(['action' => 'get_push_timer_interval']) : ['ok' => false];
$pushTimerIntervalSeconds = (bool)($pushTimerResult['ok'] ?? false) ? (int)($pushTimerResult['interval_seconds'] ?? 0) : null;

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
      <p id="session-count-text" class="text-sm text-slate-400 mt-1"><?= SessionRowView::session_count_label_html(count($sessions)) ?></p>
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

  <?= HealthBoxView::health_box_html($healthChecks, $pushTimerIntervalSeconds, $csrfToken) ?>

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
    <div id="sessions-container"><?= SessionRowView::sessions_list_html($sessions, $csrfToken) ?></div>
  <?php endif; ?>

  <?php if ($agentReachable): ?>
    <div id="bare-container"><?= SessionRowView::bare_processes_html($bare, $csrfToken) ?></div>
  <?php endif; ?>

  <div class="fixed bottom-0 inset-x-0 bg-slate-950/90 backdrop-blur border-t border-slate-800 px-4 py-3">
    <div class="max-w-2xl mx-auto">
      <div class="flex items-start justify-between gap-3">
        <?= QuotaFooterView::quota_footer_html() ?>
        <a href="/"
          class="min-h-[2.75rem] flex items-center rounded-lg bg-slate-800 active:bg-slate-700 font-medium text-sm px-4 py-2 shrink-0">
          Refresh
        </a>
      </div>
      <?= PushNotifyView::push_notify_button_html($vapidPublicKey, $csrfToken) ?>
    </div>
  </div>

</div>
<script>
window.CSM_BOOTSTRAP = <?= json_encode(['agentReachable' => $agentReachable]) ?>;
</script>
<script src="<?= Assets::versioned_url('/js/common.js') ?>"></script>
<script src="<?= Assets::versioned_url('/js/index.js') ?>"></script>
</body>
</html>
