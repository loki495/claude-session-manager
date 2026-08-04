<?php
declare(strict_types=1);

/**
 * GET-only JSON endpoint, polled asynchronously by index.php's (and
 * session.php's) sticky footer so a slow quota refresh on the host agent
 * never blocks page render. Read-only (no state mutated here), so no
 * CSRF/same-origin check is needed - matching GET / itself, which also
 * has none.
 *
 * AuthService::start_app_session() here keeps the session (and its CSRF token) alive
 * for as long as this page is open and polling - see session_detail.php
 * for the full story on why that matters.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\AgentClient;
use App\Services\AuthService;

AuthService::start_app_session();

// Overrides the Cache-Control: private, max-age=60 that AuthService::start_app_session()
// itself sets (fine for session.php's own HTML, wrong here) - see
// session_history.php for the full story on why a browser can otherwise
// serve a stale cached response to this exact fetch() URL for up to 60s.
// Doesn't hurt quota.php's own separate cached-reading logic (get_quota()
// in Sessions.php) - that's a server-side cache keyed by file mtime/TTL,
// unrelated to this HTTP response header.
header('Cache-Control: no-store');
header('Content-Type: application/json');
echo json_encode(AgentClient::agent_call(['action' => 'quota']));
