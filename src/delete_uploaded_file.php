<?php
declare(strict_types=1);

/**
 * POST-only JSON endpoint for the sidebar's per-file delete (×) button -
 * same CSRF/origin-checked, fetch()-called pattern as session_send.php.
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
$filename = (string)($_POST['filename'] ?? '');

echo json_encode(agent_call(['action' => 'delete_uploaded_file', 'session' => $sessionName, 'filename' => $filename]));
