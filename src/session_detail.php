<?php
declare(strict_types=1);

/**
 * GET-only JSON endpoint backing session.php's live info/blocked-prompt
 * panel (polled while the page is visible - see session.php's inline
 * script). Read-only (no state mutated here), so no CSRF/same-origin
 * check is needed - matching GET / itself and quota.php/browse.php, which
 * also have none.
 *
 * start_app_session() here isn't for CSRF (nothing to protect on a GET) -
 * it's so a tab left open just watching (polling, never sending/answering)
 * still touches the session on every poll, keeping it alive. Without this,
 * a long-idle-but-open tab's session (and the CSRF token session.php
 * issued at load) could expire via normal PHP session GC while the page
 * itself never notices - found live: the resulting stale-token POST comes
 * back 403 with a plain-text body ("Rejected: missing or invalid CSRF
 * token."), which a fetch().then(r => r.json()) can't parse, surfacing as
 * a bare "Unexpected response" with no indication it was actually a CSRF
 * issue. Most likely to bite an iOS "Add to Home Screen" launch: no
 * refresh gesture exists there at all, and the app can stay resumed in
 * the same JS session for hours.
 */
require_once __DIR__ . '/lib/AgentClient.php';
require_once __DIR__ . '/lib/Auth.php';

start_app_session();

// Overrides the Cache-Control: private, max-age=60 that start_app_session()
// itself sets (fine for session.php's own HTML, wrong here) - see
// session_history.php for the full story on why a browser can otherwise
// serve a stale cached response to this exact fetch() URL for up to 60s.
header('Cache-Control: no-store');
header('Content-Type: application/json');
echo json_encode(agent_call(['action' => 'session_detail', 'session' => (string)($_GET['session'] ?? '')]));
