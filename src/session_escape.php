<?php
declare(strict_types=1);

/**
 * POST-only JSON endpoint for session.php's "stop" button - sends Escape
 * to interrupt whatever Claude is currently doing. Same AJAX pattern as
 * session_mode.php/session_send.php.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\AgentClient;
use App\Services\AuthService;

AuthService::start_app_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'POST required']);
    exit;
}

if (!AuthService::same_origin_or_no_origin()) {
    http_response_code(403);
    echo "Rejected: cross-origin request.";
    exit;
}

AuthService::require_csrf();

header('Content-Type: application/json');

$sessionName = trim((string)($_POST['session'] ?? ''));

echo json_encode(AgentClient::agent_call(['action' => 'send_escape', 'session' => $sessionName]));
