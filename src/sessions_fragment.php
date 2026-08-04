<?php
declare(strict_types=1);

/**
 * GET-only JSON endpoint, polled by index.php's own visibility-gated poll
 * (mirrors session.php's) to keep the dashboard's session list, bare-
 * process list, and active-session count live without a manual refresh.
 * Renders through the exact same App\Views\SessionRowView methods
 * (sessions_list_html()/bare_processes_html()/session_count_label_html())
 * index.php's own SSR render calls - one source of truth for the markup,
 * never two copies (a JS port and a PHP original) to keep in sync.
 *
 * Read-only from this endpoint's own perspective (no state mutated here),
 * same as quota.php/sessions_list.php/browse.php - no CSRF/same-origin
 * check needed. AuthService::start_app_session() keeps the session (and its CSRF
 * token, used by the per-row Kill/answer-prompt forms this renders) alive
 * for as long as the page is open and polling - see session_detail.php
 * for the full story on why that matters.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\AgentClient;
use App\Services\AuthService;
use App\Views\SessionRowView;

AuthService::start_app_session();

// Overrides the Cache-Control: private, max-age=60 that AuthService::start_app_session()
// itself sets (fine for index.php's own HTML, wrong here) - see
// session_history.php for the full story on why a browser can otherwise
// serve a stale cached response to this exact fetch() URL for up to 60s.
header('Cache-Control: no-store');
header('Content-Type: application/json');

$csrfToken = AuthService::csrf_token();
$listResult = AgentClient::agent_call(['action' => 'list']);
$agentReachable = (bool)($listResult['ok'] ?? false);

if (!$agentReachable) {
    echo json_encode(['ok' => false, 'message' => (string)($listResult['message'] ?? 'Unknown error')]);
    exit;
}

$sessions = $listResult['sessions'] ?? [];
$bare = $listResult['bare'] ?? [];

echo json_encode([
    'ok' => true,
    'session_count_html' => SessionRowView::session_count_label_html(count($sessions)),
    'sessions_html' => SessionRowView::sessions_list_html($sessions, $csrfToken),
    'bare_html' => SessionRowView::bare_processes_html($bare, $csrfToken),
]);
