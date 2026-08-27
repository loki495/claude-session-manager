<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AgentClient;

/**
 * Backs the New Session folder browser widget (see index.php's inline
 * script).
 *
 * browse() is GET-only and read-only (no state mutated), so no CSRF/
 * same-origin check is needed - matching GET / itself and
 * QuotaController, which also have none. Deliberately doesn't call
 * Controller's guard helpers - this endpoint has never called
 * AuthService::start_app_session() at all, unlike every other read-only
 * endpoint. Preserved as-is, not "fixed" as a side effect of this
 * migration.
 *
 * mkdir() is a real mutation (creates a directory on the host), so it
 * uses the standard require_post_json() guard like every other
 * mutating-POST-JSON endpoint - unlike browse(), it's not exempt.
 */
class BrowseController extends Controller
{
    public function browse(): void
    {
        header('Content-Type: application/json');
        echo json_encode(AgentClient::agent_call(['action' => 'browse_dir', 'path' => (string)($_GET['path'] ?? '')]));
    }

    public function mkdir(): void
    {
        $this->require_post_json();

        echo json_encode(AgentClient::agent_call([
            'action' => 'create_dir',
            'path' => (string)($_POST['path'] ?? ''),
            'name' => (string)($_POST['name'] ?? ''),
        ]));
    }
}
