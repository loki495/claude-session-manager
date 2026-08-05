<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AgentClient;

class SessionController extends Controller
{
    /**
     * GET-only JSON endpoint backing session.php's live info/blocked-prompt
     * panel (polled while the page is visible - see session.php's inline
     * script). Read-only (no state mutated here), so no CSRF/same-origin
     * check is needed - matching GET / itself and QuotaController/
     * BrowseController, which also have none.
     *
     * start_readonly_json()'s AuthService::start_app_session() call isn't
     * for CSRF (nothing to protect on a GET) - it's so a tab left open just
     * watching (polling, never sending/answering) still touches the
     * session on every poll, keeping it alive. Without this, a long-idle-
     * but-open tab's session (and the CSRF token session.php issued at
     * load) could expire via normal PHP session GC while the page itself
     * never notices - found live: the resulting stale-token POST comes
     * back 403 with a plain-text body ("Rejected: missing or invalid CSRF
     * token."), which a fetch().then(r => r.json()) can't parse, surfacing
     * as a bare "Unexpected response" with no indication it was actually a
     * CSRF issue. Most likely to bite an iOS "Add to Home Screen" launch:
     * no refresh gesture exists there at all, and the app can stay resumed
     * in the same JS session for hours.
     */
    public function detail(): void
    {
        $this->start_readonly_json();

        echo json_encode(AgentClient::agent_call(['action' => 'session_detail', 'session' => (string)($_GET['session'] ?? '')]));
    }

    /**
     * GET-only JSON endpoint backing session.php's "load more" transcript
     * pagination (see session.php's inline script). Read-only, same
     * no-CSRF-needed reasoning as detail() above.
     *
     * start_readonly_json()'s Cache-Control: no-store override matters a
     * lot here specifically - confirmed live: iOS Safari can legally serve
     * a stale cached response to this exact polling fetch() URL for up to
     * 60s under AuthService::start_app_session()'s own default limiter,
     * which is what made mobile polling look "stuck" even across a manual
     * refresh (the reload's own fetch hit the same cache entry).
     */
    public function history(): void
    {
        $this->start_readonly_json();

        echo json_encode(AgentClient::agent_call([
            'action' => 'session_history',
            'session' => (string)($_GET['session'] ?? ''),
            'before' => isset($_GET['before']) ? (int)$_GET['before'] : null,
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 30,
        ]));
    }
}
