<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AgentClient;

/**
 * GET-only JSON endpoint backing the New Session folder browser widget
 * (see index.php's inline script). Read-only (no state mutated here), so
 * no CSRF/same-origin check is needed - matching GET / itself and
 * QuotaController, which also have none.
 *
 * Deliberately doesn't extend Controller's guard helpers - this endpoint
 * has never called AuthService::start_app_session() at all, unlike every
 * other read-only endpoint. Preserved as-is, not "fixed" as a side effect
 * of this migration.
 */
class BrowseController
{
    public function browse(): void
    {
        header('Content-Type: application/json');
        echo json_encode(AgentClient::agent_call(['action' => 'browse_dir', 'path' => (string)($_GET['path'] ?? '')]));
    }
}
