<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AgentClient;
use App\Services\AuthService;
use App\Views\SessionRowView;

class DashboardController extends Controller
{
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
