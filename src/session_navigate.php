<?php
declare(strict_types=1);

/**
 * POST-only JSON endpoint for a multi-question AskUserQuestion prompt's
 * Prev/Next buttons (see App\Views\BlockedPromptView::blocked_prompt_options_html(),
 * shown when prompt_multi_question is true) - sends the Left/Right arrow
 * key Claude Code's own tab bar navigates with. Same AJAX pattern as
 * session_mode.php/session_escape.php.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/Auth.php';

use App\AgentClient;

start_app_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'POST required']);
    exit;
}

if (!same_origin_or_no_origin()) {
    http_response_code(403);
    echo "Rejected: cross-origin request.";
    exit;
}

require_csrf();

header('Content-Type: application/json');

$sessionName = trim((string)($_POST['session'] ?? ''));
$direction = trim((string)($_POST['direction'] ?? ''));

echo json_encode(AgentClient::agent_call(['action' => 'navigate_prompt', 'session' => $sessionName, 'direction' => $direction]));
