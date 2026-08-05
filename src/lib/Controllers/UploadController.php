<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AgentClient;

class UploadController extends Controller
{
    /**
     * GET-only JSON endpoint, polled by session.php's sidebar to show the
     * current session's uploaded files (name/size/total) - same read-only
     * pattern as DashboardController::list()/QuotaController::show()/
     * BrowseController::browse().
     */
    public function list(): void
    {
        $this->start_readonly_json();

        $sessionName = trim((string)($_GET['session'] ?? ''));

        echo json_encode(AgentClient::agent_call(['action' => 'list_uploaded_files', 'session' => $sessionName]));
    }
}
