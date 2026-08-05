<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AgentClient;

class PushController extends Controller
{
    /**
     * POST-only JSON endpoint for the "Notify me" button's subscribe flow
     * (and its own quiet resubscribe-on-every-open call, which self-heals
     * iOS's flaky push subscription lifecycle - see the README). The real
     * PushSubscription object (from PushManager.subscribe().toJSON()) is
     * sent as a JSON-encoded string in a normal form field rather than as
     * the raw POST body, so this can reuse require_post_json() unchanged
     * like every other mutating endpoint here (that only ever reads
     * $_POST).
     */
    public function subscribe(): void
    {
        $this->require_post_json();

        $subscription = json_decode((string)($_POST['subscription'] ?? ''), true);

        if (!is_array($subscription)) {
            echo json_encode(['ok' => false, 'message' => 'Malformed subscription']);

            return;
        }

        echo json_encode(AgentClient::agent_call(['action' => 'push_subscribe', 'subscription' => $subscription]));
    }

    /**
     * POST-only JSON endpoint for the "Notify me" button's disable flow -
     * mirrors subscribe() above.
     */
    public function unsubscribe(): void
    {
        $this->require_post_json();

        echo json_encode(AgentClient::agent_call(['action' => 'push_unsubscribe', 'endpoint' => (string)($_POST['endpoint'] ?? '')]));
    }
}
