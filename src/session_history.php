<?php
declare(strict_types=1);

/**
 * GET-only JSON endpoint backing session.php's "load more" transcript
 * pagination (see session.php's inline script). Read-only (no state
 * mutated here), so no CSRF/same-origin check is needed - matching GET /
 * itself and quota.php/browse.php, which also have none.
 *
 * start_app_session() here keeps the session (and its CSRF token) alive
 * for as long as this page is open and polling - see session_detail.php
 * for the full story on why that matters.
 */

require __DIR__ . '/lib/AgentClient.php';
require __DIR__ . '/lib/Auth.php';

start_app_session();

// start_app_session() sets PHP's session cache limiter headers
// (Cache-Control: private, max-age=60), tuned for session.php's own HTML
// page to stay bfcache-friendly - but the exact same header on THIS
// polling endpoint means a browser can legally serve a stale cached
// response to the identical fetch() URL for up to 60s instead of ever
// hitting the network, no matter how fast the page polls. Confirmed live:
// iOS Safari does exactly this, which is what made mobile polling look
// "stuck" even across a manual refresh (the reload's own fetch hit the
// same cache entry). header() replaces same-named headers by default, so
// this overrides the session limiter's value.
header('Cache-Control: no-store');
header('Content-Type: application/json');
echo json_encode(agent_call([
    'action' => 'session_history',
    'session' => (string)($_GET['session'] ?? ''),
    'before' => isset($_GET['before']) ? (int)$_GET['before'] : null,
    'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 30,
]));
