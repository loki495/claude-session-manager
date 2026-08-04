<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\AgentClient;
use App\Services\AuthService;
use App\Views\PageView;

AuthService::start_app_session();

/* ---------- handle actions (POST) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthService::same_origin_or_no_origin()) {
        http_response_code(403);
        echo "Rejected: cross-origin request.";
        exit;
    }

    AuthService::require_csrf();

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

$csrfToken = AuthService::csrf_token();

echo PageView::render_index_page([
    'agentReachable' => $agentReachable,
    'listResult' => $listResult,
    'sessions' => $sessions,
    'bare' => $bare,
    'hookCheckOk' => $hookCheckOk,
    'hookInstalled' => $hookInstalled,
    'hookResult' => $hookResult,
    'vapidPublicKey' => $vapidPublicKey,
    'healthChecks' => $healthChecks,
    'pushTimerIntervalSeconds' => $pushTimerIntervalSeconds,
    'flashMsg' => $flashMsg,
    'flashOk' => $flashOk,
    'csrfToken' => $csrfToken,
]);
