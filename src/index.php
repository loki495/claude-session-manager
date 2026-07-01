<?php
declare(strict_types=1);

require __DIR__ . '/lib/Tmux.php';

/* ---------- Basic Auth ---------- */

function require_basic_auth(): void
{
    $expectedUser = getenv('BASIC_AUTH_USER');
    $expectedPass = getenv('BASIC_AUTH_PASS');

    if ($expectedUser === false || $expectedPass === false || $expectedUser === '' || $expectedPass === '') {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "Server misconfigured: BASIC_AUTH_USER / BASIC_AUTH_PASS are not set.";
        exit;
    }

    $providedUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $providedPass = $_SERVER['PHP_AUTH_PW'] ?? '';

    $ok = hash_equals($expectedUser, $providedUser) && hash_equals($expectedPass, $providedPass);

    if (!$ok) {
        header('WWW-Authenticate: Basic realm="Claude Session Manager"');
        http_response_code(401);
        echo "Authentication required.";
        exit;
    }
}

require_basic_auth();

/* ---------- light CSRF guard ---------- */
/* No sessions/DB are used, so this is a same-origin check rather than a
   token. Basic Auth is the real access control; this just blocks a stray
   cross-site form post from a page loaded in the same authenticated browser. */

function same_origin_or_no_origin(): bool
{
    $source = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? null;

    if ($source === null) {
        return true;
    }

    $sourceHost = parse_url($source, PHP_URL_HOST);
    $sourcePort = parse_url($source, PHP_URL_PORT);
    $sourceAuthority = $sourcePort !== null ? "{$sourceHost}:{$sourcePort}" : $sourceHost;

    $host = $_SERVER['HTTP_HOST'] ?? null;

    return $sourceAuthority === $host || $sourceHost === $host;
}

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
            $result = create_cc_session();
            $ok = $result['ok'];
            $message = $result['message'];
            break;

        case 'kill':
            $requested = (string)($_POST['session'] ?? '');
            $result = kill_cc_session($requested);
            $ok = $result['ok'];
            $message = $result['message'];
            break;

        case 'cleanup':
            $result = cleanup_inactive_sessions(43200);
            $ok = empty($result['failed']);
            $message = count($result['killed']) > 0
                ? 'Killed: ' . implode(', ', $result['killed'])
                : 'No sessions inactive for more than 12h';
            if (!empty($result['failed'])) {
                $message .= ' (failed to kill: ' . implode(', ', $result['failed']) . ')';
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

$sessions = list_cc_sessions();
$flashMsg = isset($_GET['msg']) ? (string)$_GET['msg'] : null;
$flashOk = ($_GET['ok'] ?? '1') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta http-equiv="refresh" content="15">
<title>Claude Session Manager</title>
<link rel="manifest" href="data:application/manifest+json,%7B%22name%22%3A%22Claude%20Sessions%22%2C%22display%22%3A%22standalone%22%7D">
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="max-w-2xl mx-auto px-4 py-6 pb-24">

  <header class="mb-6">
    <h1 class="text-xl font-semibold tracking-tight">Claude Session Manager</h1>
    <p class="text-sm text-slate-400 mt-1"><?= count($sessions) ?> active <code>cc-*</code> session<?= count($sessions) === 1 ? '' : 's' ?></p>
  </header>

  <?php if ($flashMsg !== null && $flashMsg !== ''): ?>
    <div class="mb-4 rounded-lg px-4 py-3 text-sm <?= $flashOk ? 'bg-emerald-900/50 text-emerald-200 border border-emerald-700' : 'bg-red-900/50 text-red-200 border border-red-700' ?>">
      <?= htmlspecialchars($flashMsg, ENT_QUOTES) ?>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-2 gap-3 mb-6">
    <form method="post" action="/">
      <input type="hidden" name="action" value="new">
      <button type="submit"
        class="w-full min-h-[3rem] rounded-lg bg-indigo-600 active:bg-indigo-700 font-medium text-base px-4 py-3">
        + New Session
      </button>
    </form>

    <form method="post" action="/" onsubmit="return confirm('Kill all cc-* sessions inactive for more than 12h?');">
      <input type="hidden" name="action" value="cleanup">
      <button type="submit"
        class="w-full min-h-[3rem] rounded-lg bg-amber-700 active:bg-amber-800 font-medium text-base px-4 py-3">
        Kill inactive &gt;12h
      </button>
    </form>
  </div>

  <?php if (empty($sessions)): ?>
    <div class="rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-10 text-center text-slate-400">
      <p class="text-base">No active Claude sessions.</p>
      <p class="text-sm mt-1">Tap "New Session" to start one.</p>
    </div>
  <?php else: ?>
    <ul class="flex flex-col gap-3">
      <?php foreach ($sessions as $s): ?>
        <li class="rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-3 flex items-center justify-between gap-3">
          <div class="min-w-0">
            <div class="font-mono text-sm truncate"><?= htmlspecialchars($s['name'], ENT_QUOTES) ?></div>
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

  <div class="fixed bottom-0 inset-x-0 bg-slate-950/90 backdrop-blur border-t border-slate-800 px-4 py-3">
    <div class="max-w-2xl mx-auto flex items-center justify-between">
      <span class="text-xs text-slate-500">auto-refreshes every 15s</span>
      <a href="/"
        class="min-h-[2.75rem] flex items-center rounded-lg bg-slate-800 active:bg-slate-700 font-medium text-sm px-4 py-2">
        Refresh
      </a>
    </div>
  </div>

</div>
</body>
</html>
