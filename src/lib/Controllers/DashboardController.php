<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AgentClient;
use App\Services\AuthService;
use App\Views\PageView;
use App\Views\SessionRowView;

class DashboardController extends Controller
{
    /**
     * The dashboard's full-page GET render. Deliberately doesn't call
     * either Controller guard helper - this is a full HTML page (never
     * JSON, never a 405: any non-POST method, not just GET, renders this
     * same page), so it inlines its own bare AuthService::start_app_session()
     * call instead, matching today's exact headers (the session limiter's
     * private, max-age=60, not start_readonly_json()'s no-store).
     */
    public function index(): void
    {
        AuthService::start_app_session();

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
    }

    /**
     * Classic POST + redirect + flash for the dashboard's occasional
     * actions (new/kill/kill_bare/cleanup/install_hook/
     * set_push_timer_interval) - only ever reached via the POST route, so
     * (unlike the mutating-JSON endpoints elsewhere) there's no 405 to
     * produce and no JSON Content-Type to set; inlines its own
     * same-origin/CSRF checks rather than require_post_json() for exactly
     * that reason.
     */
    public function handleAction(): void
    {
        AuthService::start_app_session();

        if (!AuthService::same_origin_or_no_origin()) {
            http_response_code(403);
            echo "Rejected: cross-origin request.";

            return;
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
    }

    /**
     * GET-only JSON endpoint, polled by index.php's own visibility-gated
     * poll (mirrors session.php's) to keep the dashboard's session list,
     * bare-process list, and active-session count live without a manual
     * refresh. Renders through the exact same App\Views\SessionRowView
     * methods (sessions_list_html()/bare_processes_html()/
     * session_count_label_html()) index.php's own SSR render calls - one
     * source of truth for the markup, never two copies (a JS port and a
     * PHP original) to keep in sync.
     *
     * Read-only from this endpoint's own perspective (no state mutated
     * here), same as QuotaController/UploadController::list()/
     * BrowseController - no CSRF/same-origin check needed.
     * start_readonly_json()'s AuthService::start_app_session() call keeps
     * the session (and its CSRF token, used by the per-row Kill/answer-
     * prompt forms this renders) alive for as long as the page is open
     * and polling - see SessionController::detail() for the full story on
     * why that matters.
     */
    public function fragment(): void
    {
        $this->start_readonly_json();

        $csrfToken = AuthService::csrf_token();
        $listResult = AgentClient::agent_call(['action' => 'list']);
        $agentReachable = (bool)($listResult['ok'] ?? false);

        if (!$agentReachable) {
            echo json_encode(['ok' => false, 'message' => (string)($listResult['message'] ?? 'Unknown error')]);

            return;
        }

        $sessions = $listResult['sessions'] ?? [];
        $bare = $listResult['bare'] ?? [];

        echo json_encode([
            'ok' => true,
            'session_count_html' => SessionRowView::session_count_label_html(count($sessions)),
            'sessions_html' => SessionRowView::sessions_list_html($sessions, $csrfToken),
            'bare_html' => SessionRowView::bare_processes_html($bare, $csrfToken),
        ]);
    }

    /**
     * GET-only JSON endpoint, polled by session.php's sliding sidebar to
     * show every other session's status/prompt. Read-only, same as
     * fragment() above.
     */
    public function list(): void
    {
        $this->start_readonly_json();

        echo json_encode(AgentClient::agent_call(['action' => 'list']));
    }
}
