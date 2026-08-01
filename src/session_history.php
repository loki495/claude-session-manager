<?php
declare(strict_types=1);

/**
 * GET-only JSON endpoint backing session.php's "load more" transcript
 * pagination (see session.php's inline script). Read-only (no state
 * mutated here), so no CSRF/same-origin check is needed - matching GET /
 * itself and quota.php/browse.php, which also have none.
 */

require __DIR__ . '/lib/AgentClient.php';
require __DIR__ . '/lib/Auth.php';

require_basic_auth();

header('Content-Type: application/json');
echo json_encode(agent_call([
    'action' => 'session_history',
    'session' => (string)($_GET['session'] ?? ''),
    'before' => isset($_GET['before']) ? (int)$_GET['before'] : null,
    'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 30,
]));
