<?php
declare(strict_types=1);

/**
 * GET-only JSON endpoint, polled by session.php's sidebar to show the
 * current session's uploaded files (name/size/total) - same read-only
 * pattern as sessions_list.php/quota.php/browse.php.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\AgentClient;
use App\Services\AuthService;

AuthService::start_app_session();

header('Cache-Control: no-store');
header('Content-Type: application/json');

$sessionName = trim((string)($_GET['session'] ?? ''));

echo json_encode(AgentClient::agent_call(['action' => 'list_uploaded_files', 'session' => $sessionName]));
