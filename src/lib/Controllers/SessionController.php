<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AgentClient;
use App\Services\AuthService;
use App\Views\PageView;
use App\Views\TranscriptView;

class SessionController extends Controller
{
    /**
     * The session-detail page's full-page GET render. Reads `session`
     * from either GET or POST with no method check at all - matches the
     * old file exactly, so both are registered to this same method in
     * routes.php. Deliberately doesn't call either Controller guard
     * helper for the same reason as DashboardController::index() - this
     * is a full HTML page/redirect, never JSON, never a 405.
     */
    public function show(): void
    {
        AuthService::start_app_session();

        $sessionName = trim((string)($_GET['session'] ?? $_POST['session'] ?? ''));

        if ($sessionName === '') {
            header('Location: /', true, 303);

            return;
        }

        $csrfToken = AuthService::csrf_token();

        $detail = AgentClient::agent_call(['action' => 'session_detail', 'session' => $sessionName]);
        $found = (bool)($detail['ok'] ?? false);

        $pushResult = AgentClient::agent_call(['action' => 'push_public_key']);
        $vapidPublicKey = (string)($pushResult['public_key'] ?? '');

        // A search result's "jump to this line" link (see session_search.php/
        // session.js) - loads the page ENDING at that line instead of the
        // usual latest-tail page, by reusing session_history's existing
        // `before` cursor (before = jumpLine + 1 means "the page whose last
        // entry is jumpLine itself") rather than inventing a second loading
        // path. Older messages above it still page normally via the
        // existing "Load older messages" button; newer-than-jumpLine
        // messages are deliberately NOT backfilled here - the live poll's
        // own forward (`after`) fetch picks them back up as ordinary "new
        // content" on its very next tick, so there's nothing extra to wire.
        $jumpLine = isset($_GET['jump_line']) ? (int)$_GET['jump_line'] : null;
        $jumpLine = $jumpLine !== null && $jumpLine > 0 ? $jumpLine : null;

        $history = $found ? AgentClient::agent_call([
            'action' => 'session_history',
            'session' => $sessionName,
            'before' => $jumpLine !== null ? $jumpLine + 1 : null,
            'limit' => 30,
        ]) : ['ok' => false];
        $historyOk = (bool)($history['ok'] ?? false);
        $entries = $historyOk ? ($history['entries'] ?? []) : [];
        $nextBefore = $historyOk ? ($history['next_before'] ?? null) : null;
        $hasMore = $historyOk && ($history['has_more'] ?? false);
        $newestLine = !empty($entries) ? end($entries)['line'] : null;

        echo PageView::render_session_page([
            'sessionName' => $sessionName,
            'csrfToken' => $csrfToken,
            'detail' => $detail,
            'found' => $found,
            'vapidPublicKey' => $vapidPublicKey,
            'history' => $history,
            'historyOk' => $historyOk,
            'entries' => $entries,
            'nextBefore' => $nextBefore,
            'hasMore' => $hasMore,
            'newestLine' => $newestLine,
            'jumpLine' => $jumpLine,
        ]);
    }

    /**
     * The archived (dormant) session read-only view's full-page render -
     * reads `claude_session_id` from either GET or POST with no method
     * check (matches show()'s own `session` handling), the counterpart to
     * show() above but keyed by claude_session_id (a dormant session has
     * no live tmux name to look up by) and rendering a deliberately
     * separate, much smaller template: no compose bar, no live polling, no
     * mode toggle, no Kill button - nothing here is actionable. This is
     * purely for browsing an old conversation before deciding whether to
     * resume it (see the unify-claude-sessions plan's own phase split -
     * Resume is its own later phase).
     */
    public function showArchived(): void
    {
        AuthService::start_app_session();

        $claudeSessionId = trim((string)($_GET['claude_session_id'] ?? $_POST['claude_session_id'] ?? ''));

        if ($claudeSessionId === '') {
            header('Location: /', true, 303);

            return;
        }

        $detail = AgentClient::agent_call(['action' => 'archived_session_detail', 'claude_session_id' => $claudeSessionId]);
        $found = (bool)($detail['ok'] ?? false);

        // See show()'s own jump_line handling above - same reasoning, minus
        // the "the live poll backfills anything newer" note, since an
        // archived view has no live poll at all. Anything newer than the
        // jump point simply isn't shown until the reader clicks "Back to
        // latest" (see the archived-session page's own jump banner).
        $jumpLine = isset($_GET['jump_line']) ? (int)$_GET['jump_line'] : null;
        $jumpLine = $jumpLine !== null && $jumpLine > 0 ? $jumpLine : null;

        $history = $found ? AgentClient::agent_call([
            'action' => 'archived_session_history',
            'claude_session_id' => $claudeSessionId,
            'before' => $jumpLine !== null ? $jumpLine + 1 : null,
            'limit' => 30,
        ]) : ['ok' => false];
        $historyOk = (bool)($history['ok'] ?? false);
        $entries = $historyOk ? ($history['entries'] ?? []) : [];
        $nextBefore = $historyOk ? ($history['next_before'] ?? null) : null;
        $hasMore = $historyOk && ($history['has_more'] ?? false);

        echo PageView::render_archived_session_page([
            'claudeSessionId' => $claudeSessionId,
            'detail' => $detail,
            'found' => $found,
            'history' => $history,
            'historyOk' => $historyOk,
            'entries' => $entries,
            'nextBefore' => $nextBefore,
            'hasMore' => $hasMore,
            'jumpLine' => $jumpLine,
            'csrfToken' => AuthService::csrf_token(),
        ]);
    }

    /**
     * GET-only JSON endpoint backing the archived-session view's own "Load
     * older messages" button. Unlike session_history.php (which ships raw
     * JSON entries for session.js to render client-side, since a live view
     * needs that same renderer for polled-in new messages too), this
     * renders straight to HTML server-side and ships that instead - a
     * dormant session never gets new messages, so there's no live-append
     * path that would ever need the raw JSON shape here.
     */
    public function archivedHistoryFragment(): void
    {
        $this->start_readonly_json();

        $claudeSessionId = (string)($_GET['claude_session_id'] ?? '');
        $before = isset($_GET['before']) ? (int)$_GET['before'] : null;

        $history = AgentClient::agent_call([
            'action' => 'archived_session_history',
            'claude_session_id' => $claudeSessionId,
            'before' => $before,
            'limit' => 30,
        ]);

        if (!($history['ok'] ?? false)) {
            echo json_encode(['ok' => false, 'message' => (string)($history['message'] ?? 'Unknown error')]);

            return;
        }

        $html = TranscriptView::render_transcript_entries_html($history['entries'] ?? [], $claudeSessionId, true);

        echo json_encode([
            'ok' => true,
            'html' => $html,
            'has_more' => (bool)($history['has_more'] ?? false),
            'next_before' => $history['next_before'] ?? null,
        ]);
    }

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
     * GET-only JSON endpoint backing the sidebar's "Plan/handoff files"
     * glance (see session.js's loadPlanFiles()) - read-only, same
     * no-CSRF-needed reasoning as detail() above.
     */
    public function planFiles(): void
    {
        $this->start_readonly_json();

        $sessionName = trim((string)($_GET['session'] ?? ''));

        echo json_encode(AgentClient::agent_call(['action' => 'list_plan_files', 'session' => $sessionName]));
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
            'after' => isset($_GET['after']) ? (int)$_GET['after'] : null,
        ]));
    }

    /**
     * GET-only JSON endpoint backing session.php's sidebar search box -
     * searches this session's ENTIRE transcript server-side (see
     * SessionService::session_transcript_search()'s own doc comment for
     * why this can't just filter the DOM the way the archived list's
     * title/name filter does: older messages paginated away via "Load
     * older messages" aren't in the DOM at all). Read-only, same
     * no-CSRF-needed reasoning as detail() above.
     */
    public function search(): void
    {
        $this->start_readonly_json();

        echo json_encode(AgentClient::agent_call([
            'action' => 'session_transcript_search',
            'session' => (string)($_GET['session'] ?? ''),
            'query' => trim((string)($_GET['q'] ?? '')),
            'max_matches' => 20,
        ]));
    }

    /**
     * The archived-session-view counterpart to search() above - same
     * search, keyed by claude_session_id instead of a live tmux name.
     */
    public function archivedSearch(): void
    {
        $this->start_readonly_json();

        echo json_encode(AgentClient::agent_call([
            'action' => 'archived_session_transcript_search',
            'claude_session_id' => (string)($_GET['claude_session_id'] ?? ''),
            'query' => trim((string)($_GET['q'] ?? '')),
            'max_matches' => 20,
        ]));
    }

    /**
     * POST-only JSON endpoint for session.php's message compose box.
     * Unlike a classic form POST + redirect + flash (fine for rare,
     * occasional actions), sending a message is the primary, repeated
     * interaction the compose box exists for, so a full page reload per
     * send would be poor UX. Called via fetch() instead.
     */
    public function send(): void
    {
        $this->require_post_json(); // plain-text 403 body on failure, same as every other POST handler - the JS caller treats a non-JSON response as a generic send failure

        $sessionName = trim((string)($_POST['session'] ?? ''));
        $text = (string)($_POST['message'] ?? '');
        $attachmentPaths = is_array($_POST['attachments'] ?? null) ? array_map('strval', $_POST['attachments']) : [];

        echo json_encode(AgentClient::agent_call(['action' => 'send_message', 'session' => $sessionName, 'text' => $text, 'attachment_paths' => $attachmentPaths]));
    }

    /**
     * POST-only JSON endpoint for session.php's mode toggle. Same AJAX
     * pattern as send() above - clicked often enough that a full page
     * reload per click would be poor UX.
     */
    public function setMode(): void
    {
        $this->require_post_json();

        $sessionName = trim((string)($_POST['session'] ?? ''));
        $mode = trim((string)($_POST['mode'] ?? ''));

        echo json_encode(AgentClient::agent_call(['action' => 'set_mode', 'session' => $sessionName, 'mode' => $mode]));
    }

    /**
     * POST-only JSON endpoint for session.php's "stop" button - sends
     * Escape to interrupt whatever Claude is currently doing. Same AJAX
     * pattern as setMode()/send().
     */
    public function escape(): void
    {
        $this->require_post_json();

        $sessionName = trim((string)($_POST['session'] ?? ''));

        echo json_encode(AgentClient::agent_call(['action' => 'send_escape', 'session' => $sessionName]));
    }

    /**
     * POST-only JSON endpoint for a multi-question AskUserQuestion
     * prompt's Prev/Next buttons (see
     * App\Views\BlockedPromptView::blocked_prompt_options_html(), shown
     * when prompt_multi_question is true) - sends the Left/Right arrow
     * key Claude Code's own tab bar navigates with. Same AJAX pattern as
     * escape()/setMode().
     */
    public function navigate(): void
    {
        $this->require_post_json();

        $sessionName = trim((string)($_POST['session'] ?? ''));
        $direction = trim((string)($_POST['direction'] ?? ''));

        echo json_encode(AgentClient::agent_call(['action' => 'navigate_prompt', 'session' => $sessionName, 'direction' => $direction]));
    }

    /**
     * GET-only binary endpoint backing an attachment's <img src> (for an
     * image) or download link (for anything else) in the transcript - see
     * App\Views\TranscriptView's attachment rendering. Reads `line` (the
     * raw JSONL line number returned with every transcript entry) and
     * `file_uuid`, never a real host path - the host-agent re-derives the
     * path itself from the transcript file (see TranscriptService::
     * read_attachment()) rather than trusting one from the client.
     */
    public function attachment(): void
    {
        AuthService::start_app_session();

        self::stream_attachment_result(AgentClient::agent_call([
            'action' => 'session_attachment',
            'session' => (string)($_GET['session'] ?? ''),
            'line' => (int)($_GET['line'] ?? 0),
            'file_uuid' => (string)($_GET['file_uuid'] ?? ''),
        ]));
    }

    /**
     * The archived-session-view counterpart to attachment() above - same
     * binary-endpoint contract, just backed by archived_session_attachment
     * (keyed by claude_session_id, no live tmux session/sidecar involved).
     */
    public function archivedAttachment(): void
    {
        AuthService::start_app_session();

        self::stream_attachment_result(AgentClient::agent_call([
            'action' => 'archived_session_attachment',
            'claude_session_id' => (string)($_GET['claude_session_id'] ?? ''),
            'line' => (int)($_GET['line'] ?? 0),
            'file_uuid' => (string)($_GET['file_uuid'] ?? ''),
        ]));
    }

    /**
     * Shared by attachment()/archivedAttachment() - both just want to
     * stream whatever session_attachment/archived_session_attachment came
     * back with as the real binary response.
     *
     * @param array<string, mixed> $result
     */
    private static function stream_attachment_result(array $result): void
    {
        if (!($result['ok'] ?? false)) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo (string)($result['message'] ?? 'Attachment not found');

            return;
        }

        $data = base64_decode((string)($result['data'] ?? ''), true);

        if ($data === false) {
            http_response_code(502);
            header('Content-Type: text/plain');
            echo 'Malformed attachment data';

            return;
        }

        $mediaType = (string)($result['media_type'] ?? 'application/octet-stream');
        $isImage = str_starts_with($mediaType, 'image/');

        header('Content-Type: ' . $mediaType);
        // file_uuid never gets reused for different content, so this is safe to cache hard rather than re-fetching on every render.
        header('Cache-Control: private, max-age=86400, immutable');
        header('Content-Disposition: ' . ($isImage ? 'inline' : 'attachment') . '; filename="' . self::content_disposition_safe_filename((string)($result['filename'] ?? 'attachment')) . '"');
        echo $data;
    }

    /**
     * Strips characters that could break out of the quoted filename in a
     * Content-Disposition header (control chars, the closing quote itself)
     * - the filename ultimately comes from basename() of a real file on
     * disk, but that disk could in principle hold anything.
     */
    private static function content_disposition_safe_filename(string $filename): string
    {
        $safe = preg_replace('/[\x00-\x1f"]/', '', $filename) ?? '';

        return $safe !== '' ? $safe : 'attachment';
    }

    /**
     * POST-only JSON endpoint, shared by index.php's dashboard rows and
     * session.php's blocked-prompt card - same AJAX pattern as send()/
     * setMode() (replacing the old classic POST+redirect+flash: answering
     * a prompt is common enough that a full page reload per answer was
     * poor UX, same reasoning as compose send).
     */
    public function answerPrompt(): void
    {
        $this->require_post_json();

        $sessionName = trim((string)($_POST['session'] ?? ''));
        $option = (int)($_POST['option'] ?? 0);
        $text = trim((string)($_POST['text'] ?? ''));

        // A free-text reply (the "Type something." option) needs the typed
        // text staged and submitted alongside the option - see
        // SessionService::answer_prompt_with_text() in host-agent/lib/.
        // Every other option just sends the bare numbered choice.
        if ($text !== '') {
            echo json_encode(AgentClient::agent_call(['action' => 'answer_prompt_with_text', 'session' => $sessionName, 'option' => $option, 'text' => $text]));
        } else {
            echo json_encode(AgentClient::agent_call(['action' => 'answer_prompt', 'session' => $sessionName, 'option' => $option]));
        }
    }
}
