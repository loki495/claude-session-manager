<?php
declare(strict_types=1);

/**
 * POST-only JSON endpoint for the "Notify me" button's subscribe flow
 * (and its own quiet resubscribe-on-every-open call, which self-heals
 * iOS's flaky push subscription lifecycle - see the README). The real
 * PushSubscription object (from PushManager.subscribe().toJSON()) is
 * sent as a JSON-encoded string in a normal form field rather than as
 * the raw POST body, so this can reuse require_csrf() unchanged like
 * every other endpoint here (that only ever reads $_POST).
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

$subscription = json_decode((string)($_POST['subscription'] ?? ''), true);

if (!is_array($subscription)) {
    echo json_encode(['ok' => false, 'message' => 'Malformed subscription']);
    exit;
}

echo json_encode(agent_call(['action' => 'push_subscribe', 'subscription' => $subscription]));
