<?php
declare(strict_types=1);

/**
 * GET-only JSON endpoint backing the New Session folder browser widget
 * (see index.php's inline script). Read-only (no state mutated here), so
 * no CSRF/same-origin check is needed - matching GET / itself and
 * quota.php, which also have none.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\AgentClient;

header('Content-Type: application/json');
echo json_encode(AgentClient::agent_call(['action' => 'browse_dir', 'path' => (string)($_GET['path'] ?? '')]));
