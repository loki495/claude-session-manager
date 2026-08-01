<?php
declare(strict_types=1);

/**
 * GET-only JSON endpoint backing session.php's live info/blocked-prompt
 * panel (polled while the page is visible - see session.php's inline
 * script). Read-only (no state mutated here), so no CSRF/same-origin
 * check is needed - matching GET / itself and quota.php/browse.php, which
 * also have none.
 */

require __DIR__ . '/lib/AgentClient.php';
require __DIR__ . '/lib/Auth.php';

require_basic_auth();

header('Content-Type: application/json');
echo json_encode(agent_call(['action' => 'session_detail', 'session' => (string)($_GET['session'] ?? '')]));
