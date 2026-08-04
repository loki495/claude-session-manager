<?php
declare(strict_types=1);

/**
 * GET-only JSON endpoint, polled by session.php's sidebar to show the
 * current session's uploaded files (name/size/total) - same read-only
 * pattern as sessions_list.php/quota.php/browse.php.
 */

require __DIR__ . '/lib/AgentClient.php';
require __DIR__ . '/lib/Auth.php';

start_app_session();

header('Cache-Control: no-store');
header('Content-Type: application/json');

$sessionName = trim((string)($_GET['session'] ?? ''));

echo json_encode(agent_call(['action' => 'list_uploaded_files', 'session' => $sessionName]));
