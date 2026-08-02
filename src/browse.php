<?php
declare(strict_types=1);

/**
 * GET-only JSON endpoint backing the New Session folder browser widget
 * (see index.php's inline script). Read-only (no state mutated here), so
 * no CSRF/same-origin check is needed - matching GET / itself and
 * quota.php, which also have none.
 */

require __DIR__ . '/lib/AgentClient.php';

header('Content-Type: application/json');
echo json_encode(agent_call(['action' => 'browse_dir', 'path' => (string)($_GET['path'] ?? '')]));
