<?php
declare(strict_types=1);

/**
 * GET-only JSON endpoint, polled by session.php's sliding sidebar to show
 * every other session's status/prompt. Read-only, same as quota.php/browse.php.
 *
 * start_app_session() here keeps the session (and its CSRF token) alive
 * for as long as this page is open and polling - see session_detail.php
 * for the full story on why that matters.
 */

require __DIR__ . '/lib/AgentClient.php';
require __DIR__ . '/lib/Auth.php';

start_app_session();

// Overrides the Cache-Control: private, max-age=60 that start_app_session()
// itself sets (fine for session.php's own HTML, wrong here) - see
// session_history.php for the full story on why a browser can otherwise
// serve a stale cached response to this exact fetch() URL for up to 60s.
header('Cache-Control: no-store');
header('Content-Type: application/json');
echo json_encode(agent_call(['action' => 'list']));
