<?php
declare(strict_types=1);

/**
 * POST-only JSON endpoint, shared by index.php's dashboard rows and
 * session.php's blocked-prompt card - same AJAX pattern as
 * session_send.php/session_mode.php, replacing the old classic
 * POST+redirect+flash (answering a prompt is common enough that a full
 * page reload per answer was poor UX, same reasoning as compose send).
 */

require_once __DIR__ . '/lib/AgentClient.php';
require_once __DIR__ . '/lib/Auth.php';

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
$option = (int)($_POST['option'] ?? 0);
$text = trim((string)($_POST['text'] ?? ''));

// A free-text reply (the "Type something." option) needs the typed text
// staged and submitted alongside the option - see answer_prompt_with_text()
// in Sessions.php. Every other option just sends the bare numbered choice.
if ($text !== '') {
    echo json_encode(agent_call(['action' => 'answer_prompt_with_text', 'session' => $sessionName, 'option' => $option, 'text' => $text]));
} else {
    echo json_encode(agent_call(['action' => 'answer_prompt', 'session' => $sessionName, 'option' => $option]));
}
