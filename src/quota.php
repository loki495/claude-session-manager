<?php
declare(strict_types=1);

/**
 * GET-only JSON endpoint, polled asynchronously by index.php's sticky
 * footer so a slow quota refresh on the host agent never blocks page
 * render. Read-only (no state mutated here), so no CSRF/same-origin check
 * is needed - matching GET / itself, which also has none.
 */

require __DIR__ . '/lib/AgentClient.php';
require __DIR__ . '/lib/Auth.php';

require_basic_auth();

header('Content-Type: application/json');
echo json_encode(agent_call(['action' => 'quota']));
