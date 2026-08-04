<?php
declare(strict_types=1);

/**
 * POST-only JSON endpoint for session.php's message compose box. Unlike
 * kill (classic form POST + redirect + flash - fine for rare, occasional
 * actions), sending a message is the primary, repeated interaction the
 * compose box exists for, so a full page reload per send would be poor
 * UX. Called via fetch() instead.
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

AuthService::require_csrf(); // plain-text 403 body on failure, same as every other POST handler - the JS caller treats a non-JSON response as a generic send failure

header('Content-Type: application/json');

$sessionName = trim((string)($_POST['session'] ?? ''));
$text = (string)($_POST['message'] ?? '');

echo json_encode(AgentClient::agent_call(['action' => 'send_message', 'session' => $sessionName, 'text' => $text]));
