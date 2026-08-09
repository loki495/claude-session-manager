<?php
declare(strict_types=1);

/**
 * Boots PHP's built-in server serving public/ (with public/index.php as
 * the router-script argument, same as production - see docker-compose.yml)
 * against a canned fake-agent socket (tests/fixtures/canned_agent.php -
 * never touches tmux) and drives it with curl. Always runs standalone (no
 * MCP / IDE dependency - just php, curl, and optionally a headless browser
 * binary already on the host), per the requirement that tests/run.sh works
 * outside Claude.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/http.php';

// Must match CANNED_TEST_IMAGE_BASE64 in fixtures/canned_agent.php - that
// file runs as its own separate process (spawned per-connection by
// socket_harness.php), so its constants aren't reachable from here.
const CANNED_TEST_IMAGE_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
const CANNED_VAPID_PUBLIC_KEY = 'BAhRdSrCIQS6QqCKKxkfmfSQ_DyQk63-8zoSMWlb2PXjhuTym7Lxyboe7HSFwi79IJN7-wqbUbZmYR1CkLvXZSc';
const CANNED_ARCHIVED_CLAUDE_SESSION_ID = '99999999-8888-4777-a666-555555555555';
const CANNED_RESUMED_SESSION_NAME = 'cc-20260101-1400';
const CANNED_TAKEN_OVER_SESSION_NAME = 'cc-20260101-1500';
const CANNED_NEW_SESSION_NAME = 'cc-20260101-1600';

$agentSocket = sys_get_temp_dir() . '/csm-test-ui-agent.sock';
$agentHarness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $agentSocket);

$port = 18099;
$baseUrl = "http://127.0.0.1:{$port}";

$serverEnv = array_merge(getenv(), [
    'CSM_AGENT_SOCKET' => $agentSocket,
]);
$serverProcess = proc_open(
    [
        'php', '-S', "127.0.0.1:{$port}",
        '-t', dirname(__DIR__) . '/public',
        dirname(__DIR__) . '/public/index.php', // absolute - resolves relative to CWD, not -t
    ],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $serverPipes,
    null,
    $serverEnv
);

if (!is_resource($serverProcess)) {
    fwrite(STDERR, "ui smoke: failed to start php -S\n");
    stop_harness($agentHarness, $agentSocket);
    exit(1);
}
fclose($serverPipes[0]);

$ready = false;
$deadline = microtime(true) + 3.0;
while (microtime(true) < $deadline) {
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($conn !== false) {
        fclose($conn);
        $ready = true;
        break;
    }
    usleep(50000);
}

if (!$ready) {
    fwrite(STDERR, "ui smoke: server on port {$port} never became ready\n");
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    stop_harness($agentHarness, $agentSocket);
    exit(1);
}

try {
    $cookieJar = tempnam(sys_get_temp_dir(), 'csm-test-cookies');

    // --- page reflects the canned agent's data ---
    $result = curl_request('GET', "{$baseUrl}/", [], $cookieJar);
    assert_equal(200, $result['status'], 'GET /: 200');
    assert_contains('Claude Session Manager', $result['body'], 'GET /: page title present');
    assert_contains('2 active', $result['body'], 'GET /: session count from canned agent');
    assert_true(
        preg_match('/<body class="[^"]*\boverscroll-none\b/', $result['body']) === 1,
        'GET /: body has overscroll-none, so the whole page never rubber-bands/bounces past its own top/bottom (found live: this app had no overscroll-behavior anywhere, reading as a plain webpage rather than a tight, native-app-like one)'
    );
    assert_contains('Fix the login redirect bug', $result['body'], 'GET /: canned pane title shown as the primary label');
    assert_contains('cc-20260101-1200', $result['body'], 'GET /: raw session name still shown (secondary, since a title is present)');
    assert_contains('demo-project', $result['body'], 'GET /: canned workdir rendered');
    assert_contains('detached', $result['body'], 'GET /: canned session shown as detached');
    assert_contains('Bare title', $result['body'], "GET /: canned bare process's tmux pane title shown");
    assert_contains('csm-test-adhoc', $result['body'], "GET /: canned bare process's owning tmux session shown");
    assert_contains("I&#039;ll clean up the temp directory now", $result['body'], 'GET /: last-message preview shown under a non-blocked session row');
    assert_contains('show-recent-btn', $result['body'], 'GET /: "show last 3 messages" toggle button present');
    assert_true(
        preg_match('#<a href="/session\.php\?session=cc-20260101-1200" class="absolute inset-0 rounded-xl"#', $result['body']) === 1,
        'GET /: the whole session-row card is a stretched link to its transcript page (a plain <a>, not JS-driven), so clicking anywhere on the card that isn\'t a real interactive element navigates there'
    );
    assert_contains('Found some old temp files worth cleaning up', $result['body'], 'GET /: blocked dashboard row includes the message that led up to the prompt');
    assert_contains('rm -rf /tmp/dashboard-example', $result['body'], 'GET /: blocked dashboard row (non-trust) shows the rich context+buttons treatment');
    $firstShowRecentPos = strpos($result['body'], 'show-recent-btn');
    $secondShowRecentPos = $firstShowRecentPos !== false ? strpos($result['body'], 'show-recent-btn', $firstShowRecentPos + 1) : false;
    assert_true(
        $secondShowRecentPos !== false && $secondShowRecentPos < strpos($result['body'], 'Found some old temp files worth cleaning up'),
        'GET /: "show last 3 messages" button renders above the blocked-prompt card, not below it (checked on the blocked row specifically - it\'s the 2nd of 2 canned rows)'
    );
    assert_contains('id="quota-footer"', $result['body'], 'GET /: collapsible quota footer present');
    assert_contains('id="quota-toggle-btn"', $result['body'], 'GET /: quota footer collapse/expand toggle present');
    assert_contains('data-session=""', $result['body'], 'GET /: dashboard-wide quota footer has no session name (context is per-session, not shown here)');
    assert_true(!str_contains($result['body'], "isn't installed"), 'GET /: session-rotation hook banner not shown when the canned agent reports it already installed');
    assert_contains('id="push-notify-btn"', $result['body'], 'GET /: push-notification "Notify me" control present when the canned agent reports VAPID configured');
    assert_contains(CANNED_VAPID_PUBLIC_KEY, $result['body'], 'GET /: the actual VAPID public key is embedded for the frontend subscribe flow');
    // Both canned sessions have working=true, but only the non-blocked one
    // should ever show the indicator - the blocked one must not, proving
    // SessionRowView::dashboard_thinking_indicator_html()'s blocked_reason check actually
    // wins rather than just happening to not be exercised by the fixture.
    assert_equal(1, substr_count($result['body'], 'Thinking&hellip;'), 'GET /: thinking indicator shown exactly once - for the working, non-blocked session, not the working-but-blocked one');
    assert_contains('id="poll-interval-select"', $result['body'], 'GET /: dashboard polling-interval dropdown present in the header');
    assert_contains('value="3000" selected', $result['body'], 'GET /: dashboard polling-interval dropdown defaults to 3s');
    assert_contains('id="session-count-text"', $result['body'], 'GET /: session-count text is a targetable element (updated live by the poll)');
    assert_contains('id="sessions-container"', $result['body'], 'GET /: session list lives inside a targetable container (swapped in place by the poll)');
    assert_contains('id="bare-container"', $result['body'], 'GET /: bare-process list lives inside a targetable container (swapped in place by the poll)');
    assert_true(
        preg_match('#<script src="(/js/common\.js\?v=\d+)"></script>\s*<script src="(/js/index\.js\?v=\d+)"></script>#', $result['body'], $indexScriptMatch) === 1,
        'GET /: loads common.js then index.js, both cache-busted with a ?v=<mtime> query string (App\Assets::versioned_url())'
    );
    $indexCommonJs = curl_request('GET', "{$baseUrl}{$indexScriptMatch[1]}");
    assert_equal(200, $indexCommonJs['status'], 'GET /js/common.js?v=...: 200 (served as a static file, no 404)');
    $indexJs = curl_request('GET', "{$baseUrl}{$indexScriptMatch[2]}");
    assert_equal(200, $indexJs['status'], 'GET /js/index.js?v=...: 200 (served as a static file, no 404)');

    // CSRF token must round-trip through the session (via the cookie jar), not the URL - every
    // POST below extracts it fresh from whatever page it's reacting to.
    $csrfToken = extract_csrf_token($result['body']);
    assert_true($csrfToken !== null, 'GET /: page includes a csrf_token field');

    // --- POST new: redirect + session-based flash (no message in the URL) ---
    $result = curl_request('POST', "{$baseUrl}/", [
        '-d', 'action=new&csrf_token=' . urlencode((string)$csrfToken) . '&workdir=' . urlencode('/home/andres/www/demo-project'),
    ], $cookieJar);
    assert_equal(303, $result['status'], 'POST new: 303 redirect');
    assert_equal('/', $result['headers']['location'] ?? '', 'POST new: redirects to / with no message in the URL');

    $follow = curl_request('GET', "{$baseUrl}/", [], $cookieJar);
    assert_equal(200, $follow['status'], 'POST new -> redirect target: 200');
    assert_contains('Created session', $follow['body'], 'POST new -> redirect target: flash message shown');
    $csrfToken = extract_csrf_token($follow['body']);

    $again = curl_request('GET', "{$baseUrl}/", [], $cookieJar);
    assert_true(!str_contains($again['body'], 'Created session'), 'GET / again: flash message does not reappear on refresh');

    // --- POST without a valid CSRF token is rejected ---
    $result = curl_request('POST', "{$baseUrl}/", [
        '-d', 'action=cleanup&csrf_token=not-the-real-token',
    ], $cookieJar);
    assert_equal(403, $result['status'], 'POST with a wrong csrf_token: 403');

    // --- POST kill: canned agent accepts this exact session name ---
    $result = curl_request('POST', "{$baseUrl}/", [
        '-d', 'action=kill&csrf_token=' . urlencode((string)$csrfToken) . '&session=cc-20260101-1200',
    ], $cookieJar);
    assert_equal(303, $result['status'], 'POST kill: 303 redirect');
    $follow = curl_request('GET', "{$baseUrl}/", [], $cookieJar);
    assert_contains('Killed', $follow['body'], 'POST kill: flash shows success for the canned session name');
    $csrfToken = extract_csrf_token($follow['body']);

    // --- POST kill: canned agent rejects any other name ---
    $result = curl_request('POST', "{$baseUrl}/", [
        '-d', 'action=kill&csrf_token=' . urlencode((string)$csrfToken) . '&session=cc-not-a-real-session',
    ], $cookieJar);
    $follow = curl_request('GET', "{$baseUrl}/", [], $cookieJar);
    assert_contains('Rejected', $follow['body'], 'POST kill: flash shows rejection for an unrecognized session name');
    $csrfToken = extract_csrf_token($follow['body']);

    // --- POST kill_bare: canned agent accepts this exact pid ---
    $result = curl_request('POST', "{$baseUrl}/", [
        '-d', 'action=kill_bare&csrf_token=' . urlencode((string)$csrfToken) . '&pid=54321',
    ], $cookieJar);
    assert_equal(303, $result['status'], 'POST kill_bare: 303 redirect');
    $follow = curl_request('GET', "{$baseUrl}/", [], $cookieJar);
    assert_contains('Killed', $follow['body'], 'POST kill_bare: flash shows success for the canned pid');
    $csrfToken = extract_csrf_token($follow['body']);

    // --- quota.php: passes the canned agent's quota action through as JSON ---
    $result = curl_request('GET', "{$baseUrl}/quota.php");
    assert_equal(200, $result['status'], 'GET /quota.php: 200');
    $quotaBody = json_decode($result['body'], true);
    assert_true(is_array($quotaBody) && ($quotaBody['ok'] ?? false), 'GET /quota.php: response decodes as ok=true JSON');
    assert_equal(73, $quotaBody['quota']['session']['pct'] ?? null, 'GET /quota.php: canned session percentage passed through');
    assert_true(!isset($quotaBody['quota']['context']), 'GET /quota.php: no context bucket without a ?session= param');

    // --- quota.php?session=...: the ?session= query param reaches the agent
    // call (QuotaController::show() -> agent_call(['session' => ...]) ->
    // Sessions.php's dispatch -> QuotaService::get_quota($session)) and a
    // per-session context bucket comes back alongside the account-wide ones ---
    $result = curl_request('GET', "{$baseUrl}/quota.php?session=cc-20260101-1200");
    assert_equal(200, $result['status'], 'GET /quota.php?session=...: 200');
    $quotaWithContext = json_decode($result['body'], true);
    assert_equal(12, $quotaWithContext['quota']['context']['pct'] ?? null, 'GET /quota.php?session=...: canned context percentage for the named session passed through');
    assert_equal(73, $quotaWithContext['quota']['session']['pct'] ?? null, 'GET /quota.php?session=...: account-wide session percentage still passed through alongside context');

    // --- browse.php: passes the canned agent's browse_dir action through as JSON ---
    $result = curl_request('GET', "{$baseUrl}/browse.php?path=" . urlencode('/home/andres/www'));
    assert_equal(200, $result['status'], 'GET /browse.php: 200');
    $browseBody = json_decode($result['body'], true);
    assert_true(is_array($browseBody) && ($browseBody['ok'] ?? false), 'GET /browse.php: response decodes as ok=true JSON');
    assert_equal(['project-a', 'project-b'], $browseBody['dirs'] ?? null, 'GET /browse.php: canned dirs passed through');

    // --- sessions_list.php: passes the canned agent's list action through as JSON ---
    $result = curl_request('GET', "{$baseUrl}/sessions_list.php");
    assert_equal(200, $result['status'], 'GET /sessions_list.php: 200');
    $sessionsListBody = json_decode($result['body'], true);
    assert_true(is_array($sessionsListBody) && ($sessionsListBody['ok'] ?? false), 'GET /sessions_list.php: response decodes as ok=true JSON');
    assert_equal(2, count($sessionsListBody['sessions'] ?? []), 'GET /sessions_list.php: canned sessions passed through');

    // --- sessions_fragment.php: pre-rendered HTML for index.php's own live
    // poll - same underlying data as sessions_list.php, but rendered
    // through the exact same App\Views\SessionRowView methods
    // (sessions_list_html()/bare_processes_html()/session_count_label_html())
    // index.php's SSR render itself calls, so this must contain the same
    // content/markers the initial GET / assertions above already checked. ---
    $result = curl_request('GET', "{$baseUrl}/sessions_fragment.php");
    assert_equal(200, $result['status'], 'GET /sessions_fragment.php: 200');
    $fragmentBody = json_decode($result['body'], true);
    assert_true(is_array($fragmentBody) && ($fragmentBody['ok'] ?? false), 'GET /sessions_fragment.php: response decodes as ok=true JSON');
    assert_contains('2 active', $fragmentBody['session_count_html'] ?? '', 'GET /sessions_fragment.php: session_count_html matches the canned session count');
    assert_contains('Fix the login redirect bug', $fragmentBody['sessions_html'] ?? '', 'GET /sessions_fragment.php: sessions_html carries the canned pane title');
    assert_equal(1, substr_count($fragmentBody['sessions_html'] ?? '', 'Thinking&hellip;'), 'GET /sessions_fragment.php: thinking indicator carried through the poll fragment too, still exactly once');
    assert_contains('rm -rf /tmp/dashboard-example', $fragmentBody['sessions_html'] ?? '', 'GET /sessions_fragment.php: sessions_html carries the blocked row\'s rich context');
    assert_contains('Bare title', $fragmentBody['bare_html'] ?? '', 'GET /sessions_fragment.php: bare_html carries the canned bare process');

    // --- archived_sessions_fragment.php: the dashboard's archived-sessions
    // toggle - a separate, on-demand endpoint (never part of the regular
    // sessions_fragment.php poll above - see DashboardController::
    // archivedFragment()'s own doc comment for why) ---
    $result = curl_request('GET', "{$baseUrl}/archived_sessions_fragment.php");
    assert_equal(200, $result['status'], 'GET /archived_sessions_fragment.php: 200');
    $archivedFragmentBody = json_decode($result['body'], true);
    assert_true(is_array($archivedFragmentBody) && ($archivedFragmentBody['ok'] ?? false), 'GET /archived_sessions_fragment.php: response decodes as ok=true JSON');
    assert_contains('Refactor the old widget', $archivedFragmentBody['archived_html'] ?? '', 'GET /archived_sessions_fragment.php: archived_html carries the canned archived session\'s title');
    assert_contains('/home/andres/www/old-project', $archivedFragmentBody['archived_html'] ?? '', 'GET /archived_sessions_fragment.php: archived_html carries the canned archived session\'s cwd');
    assert_true(
        preg_match('#<form method="post" action="/"[^>]*>\s*<input type="hidden" name="action" value="resume">\s*<input type="hidden" name="csrf_token"[^>]*>\s*<input type="hidden" name="claude_session_id" value="' . CANNED_ARCHIVED_CLAUDE_SESSION_ID . '">\s*<input type="hidden" name="workdir" value="/home/andres/www/old-project">\s*<button type="submit"[^>]*>\s*Resume#', $archivedFragmentBody['archived_html'] ?? '') === 1,
        'GET /archived_sessions_fragment.php: archived_html carries a Resume form with the row\'s claude_session_id and cwd (phase 5, known-id resume)'
    );

    // --- session_detail.php/session_history.php/sessions_list.php/quota.php now join the
    // session (not just AgentClient.php) - a tab left open just polling (no send/answer)
    // must keep its session, and the CSRF token it holds, alive rather than letting it
    // expire via PHP's session GC out from under a still-open page. Verified by polling
    // with an established session's cookie, then confirming that session's CSRF token is
    // still accepted afterwards - a session_start() that rotated or dropped the session
    // would break this. ---
    $pollCookieJar = tempnam(sys_get_temp_dir(), 'csm-test-poll-cookies');
    $pollPage = curl_request('GET', "{$baseUrl}/session.php?session=cc-20260101-1200", [], $pollCookieJar);
    $pollCsrfToken = extract_csrf_token($pollPage['body']);
    assert_true($pollCsrfToken !== null, 'GET /session.php: page includes a csrf_token field (poll-keepalive setup)');

    foreach (['session_detail.php?session=cc-20260101-1200', 'session_history.php?session=cc-20260101-1200', 'sessions_list.php', 'sessions_fragment.php', 'quota.php'] as $pollEndpoint) {
        $pollResult = curl_request('GET', "{$baseUrl}/{$pollEndpoint}", [], $pollCookieJar);
        assert_equal(200, $pollResult['status'], "GET /{$pollEndpoint} (poll, with session cookie): 200");

        // start_app_session() sets Cache-Control: private, max-age=60 by
        // default (tuned for session.php's own HTML page's bfcache
        // behavior) - each of these 4 polling endpoints must override that
        // with no-store, or a browser (confirmed live: iOS Safari) can
        // legally serve a stale cached response to this exact fetch() URL
        // for up to a minute, no matter how fast the page polls.
        assert_equal('no-store', $pollResult['headers']['cache-control'] ?? null, "GET /{$pollEndpoint}: Cache-Control: no-store (never a stale cached poll response)");
    }

    $answerAfterPolling = curl_request('POST', "{$baseUrl}/answer_prompt.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&option=1&csrf_token=' . urlencode((string)$pollCsrfToken),
    ], $pollCookieJar);
    $answerAfterPollingBody = json_decode($answerAfterPolling['body'], true);
    assert_true(
        is_array($answerAfterPollingBody) && ($answerAfterPollingBody['ok'] ?? false),
        'POST /answer_prompt.php: the original CSRF token still works after polling the 4 read-only endpoints - session was kept alive, not dropped or rotated'
    );
    unlink($pollCookieJar);

    // --- session.php: no ?session -> redirects home rather than erroring ---
    $result = curl_request('GET', "{$baseUrl}/session.php");
    assert_equal(303, $result['status'], 'GET /session.php with no session param: 303 redirect');
    assert_equal('/', $result['headers']['location'] ?? '', 'GET /session.php with no session param: redirects to /');

    // --- session.php: renders the canned session's detail + history ---
    $result = curl_request('GET', "{$baseUrl}/session.php?session=cc-20260101-1200", [], $cookieJar);
    assert_equal(200, $result['status'], 'GET /session.php: 200');
    assert_equal(
        'private, max-age=60',
        $result['headers']['cache-control'] ?? null,
        'GET /session.php: cache-control max-age is short (1 minute), not PHP\'s 180-minute session default - a stale copy (old HTML/JS after a code change) was being served on plain navigation, no reload needed'
    );
    assert_contains('Fix the login redirect bug', $result['body'], 'GET /session.php: canned title shown');
    assert_true(
        preg_match('/id="header-title"[^>]*>\s*Fix the login redirect bug/', $result['body']) === 1,
        'GET /session.php: the session title also appears centered in the sticky top header'
    );
    assert_contains('demo-project', $result['body'], 'GET /session.php: canned workdir shown');
    assert_contains('Looking into it now.', $result['body'], 'GET /session.php: canned history entry rendered');

    // --- desktop-only (lg:) layout: wider content column, bigger text,
    // and user messages aligned right vs everything else left (typical
    // desktop chat UI) - mobile is untouched (no breakpoint prefix means
    // no change at the default/mobile size at all). ---
    assert_true(
        preg_match('/id="page-content" class="[^"]*\bmax-w-2xl lg:max-w-4xl\b/', $result['body']) === 1,
        'GET /session.php: the main content column widens on desktop (lg:max-w-4xl) but keeps the mobile max-w-2xl as the base'
    );
    assert_true(
        preg_match('/<header[^>]*>\s*<div class="[^"]*\bmax-w-2xl lg:max-w-4xl\b/', $result['body']) === 1,
        'GET /session.php: the sticky header widens to match the content column on desktop'
    );
    assert_true(
        preg_match('/id="compose-bar"[^>]*>\s*<div class="[^"]*\bmax-w-2xl lg:max-w-4xl\b/', $result['body']) === 1,
        'GET /session.php: the compose bar widens to match the content column on desktop too, so it stays visually aligned with the transcript above it'
    );
    // User entries are filled bubbles (rounded-2xl, own border/bg) - see
    // TranscriptView::entry_wrapper_class(). Assistant text entries are
    // free-flowing instead (no border/bg/max-width) - Claude-app-style,
    // added 2026-08-08 - so they're identified by that literal prefix
    // rather than the "rounded-lg border" anchor used for boxed kinds.
    assert_true(
        preg_match('/<div class="rounded-2xl border ([^"]*)">(?:(?!<div class="rounded-(?:lg|2xl) border).)*?Fix the login redirect bug/s', $result['body'], $userEntryMatch) === 1
            && str_contains($userEntryMatch[1], 'lg:self-end') && str_contains($userEntryMatch[1], 'lg:max-w-[75%]'),
        'GET /session.php: a real user-typed entry is a filled bubble aligned right on desktop (lg:self-end), capped to 75% width so alignment reads as a real chat bubble, not a full-width block'
    );
    assert_true(
        preg_match('/<div class="(entry-free-flowing mt-2 lg:max-w-full lg:self-start[^"]*)">(?:(?!<div class="(?:rounded-(?:lg|2xl) border|entry-free-flowing)).)*?Looking into it now\./s', $result['body']) === 1,
        'GET /session.php: a plain assistant text entry is free-flowing (no border/background, lg:max-w-full) and aligns left on desktop (lg:self-start), opposite of a user bubble'
    );
    assert_contains('.new-content-highlight.entry-free-flowing', $result['body'], 'GET /session.php: the new-content glow gets a special-cased override for free-flowing (unpadded) assistant entries, so it does not hug the text');
    assert_contains('.new-content-highlight.entry-free-flowing::before', $result['body'], 'GET /session.php: the free-flowing glow override uses a ::before pseudo-element inset outside the real text (not box-shadow spread or outline directly on the entry, both tried and rejected live - see the CSS comment) so it reads as a soft glow with a real gap, not a hard box');
    assert_true(
        !str_contains($result['body'], 'font-medium text-emerald-300">Assistant<'),
        'GET /session.php: a plain assistant text entry has no "Assistant" role-label span - the free-flowing treatment plus timestamp already say enough, added 2026-08-08'
    );
    assert_true(
        preg_match('/<p class="[^"]*\btext-sm lg:text-base\b[^"]*">Looking into it now\./', $result['body']) === 1,
        'GET /session.php: conversational text bumps to a bigger font on desktop (lg:text-base), not just the mobile text-sm'
    );
    assert_true(
        preg_match('/<p class="whitespace-pre-wrap break-words[^"]*">\s*Looking into it now\./', $result['body']) === 1,
        'GET /session.php: text blocks get break-words, not just whitespace-pre-wrap - otherwise a long unbroken token (found live: a 51-char FILTER_FLAG_... constant name) widens the whole page horizontally instead of wrapping'
    );
    assert_true(
        preg_match('/<div class="rounded border[^>]*>\s*<span class="whitespace-pre">\s*&rarr;\s*Bash\(pwd\)/', $result['body']) === 1,
        'GET /session.php: a short/trivial tool_use block renders as plain (non-wrapping, scrollable) text, not a <details>'
    );
    assert_true(
        preg_match('/<div class="rounded border[^>]*>\s*<span class="whitespace-pre">\s*done\s*</', $result['body']) === 1,
        'GET /session.php: a short/trivial tool_result block ("done") renders as plain (non-wrapping, scrollable) text, not a <details>'
    );
    assert_true(
        !preg_match('/<details[^>]*>\s*<summary[^>]*>\s*&rarr;\s*Bash\(pwd\)/', $result['body']),
        'GET /session.php: the trivial tool_use block is not also wrapped in a <details>'
    );
    assert_contains('Load older messages', $result['body'], 'GET /session.php: load-more button shown when has_more=true');
    assert_contains('Do you want to proceed?', $result['body'], 'GET /session.php: canned blocked_reason shown');
    assert_true(
        preg_match('/<p class="font-medium break-words">Waiting on input:/', $result['body']) === 1,
        'GET /session.php: the blocked-prompt reason also gets break-words, same reasoning as history text blocks'
    );
    assert_contains('1. Yes', $result['body'], 'GET /session.php: real option button rendered (not just the copy-paste tip)');
    assert_contains('2. No', $result['body'], 'GET /session.php: second option button rendered');
    assert_contains('class="reveal-freetext-btn', $result['body'], 'GET /session.php: the "Type something." option renders as a reveal button, not an immediate-submit form');
    assert_true(
        preg_match('/<button type="submit" class="[^"]*\btext-left\b[^"]*">1\. Yes/', $result['body']) === 1,
        'GET /session.php: an option button has text-left - a <button> defaults to text-align:center, invisible for a short label but centers each wrapped line of a long one (found live from a real phone screenshot 2026-08-08, e.g. a long "don\'t ask again for: <path>" option)'
    );
    assert_true(
        preg_match('/<form[^>]*action="\/answer_prompt\.php"[^>]*>[^<]*<input[^>]*name="option"[^>]*value="3"/', $result['body']) !== 1,
        'GET /session.php: the "Type something." option is not also rendered as a plain submitting form'
    );
    assert_contains('class="freetext-reply hidden', $result['body'], 'GET /session.php: the free-text reply box is present but hidden by default');
    assert_contains('freetext-reply-textarea', $result['body'], 'GET /session.php: the free-text reply textarea is present');
    assert_contains('class="nav-prompt-btn', $result['body'], 'GET /session.php: multi-question prompt renders Prev/Next navigation buttons');
    assert_contains('data-direction="left"', $result['body'], 'GET /session.php: Prev button targets the left direction');
    assert_contains('data-direction="right"', $result['body'], 'GET /session.php: Next button targets the right direction');
    assert_contains('rm -rf /tmp/canned-example', $result['body'], 'GET /session.php: prompt_context (the actual command being approved) is shown, not just the bare question');
    assert_true(
        preg_match('/<details[^>]*>\s*<summary[^>]*>\s*Bash command/', $result['body']) === 1,
        'GET /session.php: a non-trivial (multi-line) prompt_context still uses a real <details> wrapper'
    );
    assert_true(!str_contains($result['body'], 'Attach to answer it'), 'GET /session.php: no attach-tip fallback shown once real Approve/Deny buttons are present');
    assert_contains('Awaiting approval', $result['body'], 'GET /session.php: prompt_context renders as its own standalone entry (BlockedPromptView::pending_context_entry_html()), not nested inline in the blocked-prompt card');
    assert_true(
        strpos($result['body'], 'Awaiting approval') < strpos($result['body'], 'Waiting on input:'),
        'GET /session.php: the pending-context entry renders BEFORE the "Waiting on input" card, as its own preceding entry rather than nested inside it'
    );
    assert_true(
        strpos($result['body'], 'rm -rf /tmp/canned-example') < strpos($result['body'], 'Waiting on input:'),
        'GET /session.php: the actual command text itself also comes before the card, confirming it moved out with the entry rather than just the label'
    );
    assert_contains('id="go-to-bottom-btn"', $result['body'], 'GET /session.php: floating go-to-bottom button present');
    assert_contains('id="sidebar-toggle-btn"', $result['body'], 'GET /session.php: sidebar toggle button present');
    assert_contains('id="sidebar-notify-dot"', $result['body'], 'GET /session.php: sidebar notification dot present');
    assert_contains('id="confirm-before-answer-toggle"', $result['body'], 'GET /session.php: confirm-before-answering setting checkbox present in the sidebar');
    assert_contains('id="poll-interval-select"', $result['body'], 'GET /session.php: polling-interval dropdown present in the sticky header');
    assert_contains('value="3000" selected', $result['body'], 'GET /session.php: polling-interval dropdown defaults to 3s');
    assert_contains('id="show-subagent-toggle"', $result['body'], 'GET /session.php: the single show-subagent setting checkbox is present in the sidebar (merged from two separate call/output checkboxes, 2026-08-08)');
    assert_contains('id="show-subagent-toggle" class="rounded border-slate-600 bg-slate-800" checked', $result['body'], 'GET /session.php: show-subagent checkbox is checked (shown) by default');
    assert_contains('class="tool-detail"', $result['body'], 'GET /session.php: a plain (non-subagent) tool_result block carries no subagent-only marker at all - it is never rendered standalone hideable any more, only ever inside a tool-group');
    assert_contains('class="tool-use-block"', $result['body'], 'GET /session.php: a plain (non-subagent) tool_use block carries no subagent-only marker either');
    assert_contains('body.hide-subagent .subagent-detail', $result['body'], 'GET /session.php: the single hide-subagent CSS rule targets subagent tool_result blocks');
    assert_contains('body.hide-subagent .subagent-use-block', $result['body'], 'GET /session.php: the same hide-subagent rule also targets subagent tool_use blocks');
    assert_contains('body.hide-subagent .entry-subagent-only', $result['body'], 'GET /session.php: a second hide-subagent rule hides whole entries left with nothing but a hidden subagent block');
    assert_contains('.new-content-divider.fading', $result['body'], 'GET /session.php: the new-content divider fade rule is shipped');
    assert_contains('.new-content-highlight.fading', $result['body'], 'GET /session.php: the new-content highlight fade rule is shipped (two-class pattern, not a plain classList.remove, so `transition` survives the fade)');
    assert_true(
        preg_match('#<script src="(/js/common\.js\?v=\d+)"></script>\s*<script src="(/js/session\.js\?v=\d+)"></script>#', $result['body'], $sessionScriptMatch) === 1,
        'GET /session.php: loads common.js then session.js, both cache-busted with a ?v=<mtime> query string (App\Assets::versioned_url())'
    );
    $commonJs = curl_request('GET', "{$baseUrl}{$sessionScriptMatch[1]}");
    assert_equal(200, $commonJs['status'], 'GET /js/common.js?v=...: 200 (served as a static file, no 404)');
    assert_contains('function parseJsonResponse(', $commonJs['body'], 'GET /js/common.js: parseJsonResponse() (shared between session.js and index.js) is shipped');
    $sessionJs = curl_request('GET', "{$baseUrl}{$sessionScriptMatch[2]}");
    assert_equal(200, $sessionJs['status'], 'GET /js/session.js?v=...: 200 (served as a static file, no 404)');
    assert_contains('function markNewContent(', $sessionJs['body'], 'GET /js/session.js: markNewContent() (divider + highlight ring on freshly-polled entries) is shipped');
    assert_contains('function resetHistoryForRotatedTranscript(', $sessionJs['body'], 'GET /js/session.js: resetHistoryForRotatedTranscript() (clears the rendered history on /clear, /compact, --resume, --fork-session) is shipped');
    assert_contains('"claudeSessionId":"11111111-2222-4333-8444-555555555555"', $result['body'], 'GET /session.php: the real claude_session_id is embedded in CSM_BOOTSTRAP, so a poll-detected change can be told apart from "not known yet"');
    // --- tool-call grouping (TranscriptView::render_transcript_entries_
    // html()/render_tool_group_html()/render_tool_pair_html(), added
    // 2026-08-08): a run of consecutive groupable tool_use/tool_result
    // entries collapses into one <details class="tool-group"> with a "N
    // tool calls" summary, each call paired with its own result under one
    // card - no more standalone "Tool call"/"Tool output" labeled entries
    // for these. The canned "Bash(pwd)" tool_use (line 4) has no groupable
    // tool_result right after it (line 5 carries an image and is excluded
    // from grouping - see below), so it renders as its own group of
    // exactly one call. ---
    assert_true(
        !str_contains($result['body'], '>Tool call<'),
        'GET /session.php: a grouped tool call no longer gets a standalone "Tool call" role label - it is merged into its tool-group pair instead'
    );
    assert_true(
        preg_match('/<details class="tool-group[^"]*"><summary[^>]*>1 tool call<\/summary>.*?Bash\(pwd\).*?<\/details>/s', $result['body']) === 1,
        'GET /session.php: the canned "Bash(pwd)" tool call collapses into its own "1 tool call" group'
    );
    assert_true(
        preg_match('/Bash\(pwd\).*?<div class="tool-pair-result-slot"><\/div>/s', $result['body']) === 1,
        'GET /session.php: that group\'s one pair has an EMPTY result slot - there is no groupable tool_result immediately following it in this fixture'
    );
    // --- the multi-call group (lines 11-14, added 2026-08-08 specifically
    // to exercise real pairing/counting): two clean, contiguous call+result
    // pairs with no image/attachment on either result - both calls count
    // toward ONE "2 tool calls" group, each still paired with its own real
    // result (not left with an empty slot like the lone Bash(pwd) case
    // above). ---
    assert_true(
        preg_match('/<details class="tool-group[^"]*"><summary[^>]*>2 tool calls<\/summary>/', $result['body'], $multiCallGroupMatch, PREG_OFFSET_CAPTURE) === 1,
        'GET /session.php: the two clean Read() call+result pairs collapse into ONE "2 tool calls" group'
    );
    // Both results are multi-line, so each renders as its own expandable
    // <details> (render_collapsible_block()'s non-trivial branch) NESTED
    // inside the outer tool-group <details> - a plain non-greedy regex
    // capture up to the first </details> would stop at that INNER one, not
    // the group's own closing tag, so this checks ordering via strpos
    // instead of trying to bound the group's content with regex.
    $multiCallGroupPos = $multiCallGroupMatch[0][1] ?? null;
    assert_true(
        $multiCallGroupPos !== null
            && strpos($result['body'], 'Read(app/Http/Kernel.php)', $multiCallGroupPos) > $multiCallGroupPos
            && strpos($result['body'], 'class Kernel {}', $multiCallGroupPos) > $multiCallGroupPos
            && strpos($result['body'], 'Read(routes/web.php)', $multiCallGroupPos) > $multiCallGroupPos
            && strpos($result['body'], 'Route::get', $multiCallGroupPos) > $multiCallGroupPos,
        'GET /session.php: both calls AND both their real results appear after that one group\'s summary, not split across two separate groups'
    );
    assert_true(
        substr_count($result['body'], '<details class="tool-group') === 2,
        'GET /session.php: exactly two tool-groups total render for this fixture - the lone "Bash(pwd)" one and the "2 tool calls" one - nothing over- or under-grouped'
    );
    assert_contains('>Subagent call<', $result['body'], 'GET /session.php: the canned Agent tool_use entry is labeled "Subagent call", not "Tool call" - subagent entries are NOT swept into tool-group grouping');
    assert_contains('>Subagent report<', $result['body'], 'GET /session.php: the canned Agent tool_result entry is labeled "Subagent report", not "Tool output"');
    assert_contains('general-purpose: Investigate the login bug', $result['body'], 'GET /session.php: the subagent call summary shows subagent_type + description, not a raw param dump');
    assert_contains('Found it: the redirect URL was hardcoded.', $result['body'], 'GET /session.php: the subagent report shows its real output');
    assert_true(
        preg_match('/<div class="rounded-lg border ([^"]*)">(?:(?!<div class="rounded-lg border).)*?Investigate the login bug/s', $result['body'], $subagentCallMatch) === 1
            && str_contains($subagentCallMatch[1], 'border-fuchsia-800/60'),
        'GET /session.php: the subagent call entry uses the fuchsia color, distinct from a plain tool call'
    );
    assert_true(
        strpos($subagentCallMatch[0], 'subagent-use-block') !== false,
        'GET /session.php: the subagent call entry\'s tool_use block also carries the subagent-use-block marker (what the single hide-subagent toggle actually targets)'
    );
    assert_true(
        isset($subagentCallMatch[1]) && strpos($subagentCallMatch[1], 'entry-subagent-only') !== false,
        'GET /session.php: the subagent call entry IS marked entry-subagent-only - it has no other content, so hiding subagent entries must hide the whole entry too'
    );
    assert_true(
        preg_match('/<div class="rounded-lg border ([^"]*)">(?:(?!<div class="rounded-lg border).)*?redirect URL was hardcoded/s', $result['body'], $subagentResultMatch) === 1
            && str_contains($subagentResultMatch[1], 'border-fuchsia-800/60'),
        'GET /session.php: the subagent report entry uses the fuchsia color too'
    );
    assert_true(
        strpos($subagentResultMatch[0], 'subagent-detail') !== false,
        'GET /session.php: the subagent report entry\'s tool_result block also carries the subagent-detail marker'
    );
    assert_true(
        isset($subagentResultMatch[1]) && strpos($subagentResultMatch[1], 'entry-subagent-only') !== false,
        'GET /session.php: the subagent report entry is marked entry-subagent-only too'
    );
    // The canned "done" tool_result entry (line 5) carries an image (a
    // screenshot, in practice) - excluded from grouping entirely (an
    // attachment/image must always stay visible on its own, never folded
    // into a collapsed-by-default group), so it still renders standalone
    // with its original "Tool output" label, same as before 2026-08-08.
    assert_true(
        preg_match('/<div class="rounded-lg border ([^"]*)">(?:(?!<div class="rounded-lg border).)*?<img src="data:image\/png;base64,' . preg_quote(CANNED_TEST_IMAGE_BASE64, '/') . '"[^>]*class="transcript-image/s', $result['body'], $imageEntryMatch) === 1
            && str_contains($imageEntryMatch[0], '>Tool output<'),
        'GET /session.php: a tool_result entry carrying an image renders standalone with its "Tool output" label, NOT swept into a tool-group'
    );
    assert_true(
        isset($imageEntryMatch[1]) && strpos($imageEntryMatch[1], 'entry-subagent-only') === false,
        'GET /session.php: a tool_result entry carrying an image is not a subagent entry, so it never gets entry-subagent-only either'
    );
    // The canned SendUserFile-style tool_result (line 8) carries two
    // attachments - real file metadata threaded from the outer
    // toolUseResult.attachments field (see TranscriptService::
    // transcript_attachments_from_tool_use_result()), not embedded content:
    // a plain download (notes.txt) and an image (screenshot.png), the same
    // mixed shape a real SendUserFile call sending a screenshot alongside a
    // report can produce (verified live 2026-08-04 against this app's own
    // transcript).
    $attachmentUrl = '/session_attachment.php?session=cc-20260101-1200&line=8&file_uuid=canned-file-uuid-1';
    $imageAttachmentUrl = '/session_attachment.php?session=cc-20260101-1200&line=8&file_uuid=canned-file-uuid-2';
    assert_contains(htmlspecialchars($attachmentUrl), $result['body'], 'GET /session.php: the canned download attachment renders a link pointing at session_attachment.php with session/line/file_uuid, never a raw host path');
    assert_contains('notes.txt', $result['body'], 'GET /session.php: the download attachment\'s real filename is shown');
    assert_true(
        preg_match('/<a href="' . preg_quote(htmlspecialchars($attachmentUrl), '/') . '" download="notes\.txt"/', $result['body']) === 1,
        'GET /session.php: a non-image attachment link uses download= (not target="_blank"), which on a Content-Disposition: attachment response opens a permanently blank tab instead of a real page - download saves the file with no navigation at all'
    );
    assert_true(
        preg_match('/<img src="' . preg_quote(htmlspecialchars($imageAttachmentUrl), '/') . '"[^>]*class="transcript-image/', $result['body']) === 1,
        'GET /session.php: the canned image attachment renders as a real <img> (reusing the .transcript-image tap-to-expand class), pointing at session_attachment.php too - never embedded as data: base64 like an inline screenshot'
    );
    assert_contains('screenshot.png', $result['body'], 'GET /session.php: the image attachment\'s real filename is shown too, as its own link below the thumbnail');
    assert_true(
        preg_match('/<div class="rounded-lg border ([^"]*)">(?:(?!<div class="rounded-lg border).)*?Sent 2 file\(s\) to the user\./s', $result['body'], $attachmentEntryMatch) === 1
            && str_contains($attachmentEntryMatch[0], '>Tool output<'),
        'GET /session.php: a tool_result entry carrying file attachments also renders standalone with its "Tool output" label, same "always visible, never grouped" exemption as an inline image'
    );
    $attachmentResult = curl_request('GET', "{$baseUrl}{$attachmentUrl}", [], $cookieJar);
    assert_equal(200, $attachmentResult['status'], 'GET /session_attachment.php: 200 for a real, matching session/line/file_uuid');
    assert_equal('canned attachment bytes', $attachmentResult['body'], 'GET /session_attachment.php: streams the real (canned) file bytes, not the base64 wrapper');
    assert_true(str_starts_with($attachmentResult['headers']['content-type'] ?? '', 'text/plain'), 'GET /session_attachment.php: Content-Type reflects the attachment\'s real media_type');
    assert_contains('attachment', $attachmentResult['headers']['content-disposition'] ?? '', 'GET /session_attachment.php: a non-image attachment is served as a download (Content-Disposition: attachment), not inline');
    assert_contains('notes.txt', $attachmentResult['headers']['content-disposition'] ?? '', 'GET /session_attachment.php: Content-Disposition carries the real filename');
    $imageAttachmentResult = curl_request('GET', "{$baseUrl}{$imageAttachmentUrl}", [], $cookieJar);
    assert_equal(200, $imageAttachmentResult['status'], 'GET /session_attachment.php: 200 for the image attachment too');
    assert_true(str_starts_with($imageAttachmentResult['headers']['content-type'] ?? '', 'image/png'), 'GET /session_attachment.php: Content-Type is image/png for the image attachment');
    assert_contains('inline', $imageAttachmentResult['headers']['content-disposition'] ?? '', 'GET /session_attachment.php: an image attachment is served inline (viewable in the <img> tag), not forced to download');
    $wrongAttachmentResult = curl_request('GET', "{$baseUrl}/session_attachment.php?session=cc-20260101-1200&line=8&file_uuid=not-the-real-uuid", [], $cookieJar);
    assert_equal(404, $wrongAttachmentResult['status'], 'GET /session_attachment.php: an unrecognized file_uuid -> 404, not a silent empty body');

    // --- ExitPlanMode (lines 9-10): its own 'plan' block kind, shown in
    // full (not collapsed like a routine tool call), plus the approved
    // outcome's short, clean text - not the real verbose "## Approved
    // Plan: ..." boilerplate that would otherwise duplicate the whole plan
    // a second time. ---
    assert_contains('Refactor the login flow', $result['body'], 'GET /session.php: the canned plan\'s real content is shown in full, not collapsed behind a one-line tool-call summary');
    assert_contains('>Plan<', $result['body'], 'GET /session.php: the plan-presented entry is labeled "Plan"');
    assert_contains('>Plan approved<', $result['body'], 'GET /session.php: the plan-approved entry is labeled "Plan approved"');
    assert_contains('Plan approved - starting work', $result['body'], 'GET /session.php: the approved-plan tool_result shows the short, clean text, not the real verbose re-dumped-plan boilerplate');
    assert_true(
        preg_match('/<div class="rounded-lg border ([^"]*)">(?:(?!<div class="rounded-lg border).)*?Refactor the login flow/s', $result['body'], $planPresentedMatch) === 1
            && str_contains($planPresentedMatch[1], 'border-amber-800/60'),
        'GET /session.php: the plan-presented entry uses the amber color, distinct from a plain tool call'
    );
    assert_true(
        isset($planPresentedMatch[1]) && strpos($planPresentedMatch[1], 'entry-subagent-only') === false,
        'GET /session.php: the plan-presented entry is not a subagent entry, so it never gets entry-subagent-only - it must always stay visible regardless of the subagent toggle, and is never swept into a tool-group either'
    );
    assert_true(
        preg_match('/<div class="rounded-lg border ([^"]*)">(?:(?!<div class="rounded-lg border).)*?Plan approved - starting work/s', $result['body'], $planApprovedMatch) === 1
            && str_contains($planApprovedMatch[1], 'border-amber-800/60'),
        'GET /session.php: the plan-approved entry uses the same amber color as the plan-presented one, told apart by label alone'
    );
    assert_true(
        isset($planApprovedMatch[1]) && strpos($planApprovedMatch[1], 'entry-subagent-only') === false,
        'GET /session.php: the plan-approved entry is not a subagent entry either - always visible, never grouped'
    );

    assert_contains('id="sidebar-list"', $result['body'], 'GET /session.php: sidebar (other sessions) drawer present');
    assert_true(
        preg_match('/id="sidebar"[^>]*class="[^"]*\boverscroll-contain\b/s', $result['body']) === 1,
        'GET /session.php: the sidebar drawer has overscroll-contain, so scrolling to its end does not scroll-chain into the page behind it'
    );
    assert_true(
        preg_match('#<form method="post" action="/"[^>]*>\s*<input type="hidden" name="action" value="kill">\s*<input type="hidden" name="csrf_token"[^>]*>\s*<input type="hidden" name="session" value="cc-20260101-1200">\s*<button type="submit"[^>]*>\s*Close session#', $result['body']) === 1,
        'GET /session.php: sidebar has a "Close session" action that kills THIS session'
    );
    assert_true(
        strpos($result['body'], 'id="history-list"') < strpos($result['body'], 'id="blocked-prompt-section"'),
        'GET /session.php: the blocked-prompt section is placed after the history list, not above it'
    );
    assert_true(
        strpos($result['body'], 'id="blocked-prompt-section"') < strpos($result['body'], 'name="option"'),
        'GET /session.php: the Approve/Deny option buttons live inside the bottom blocked-prompt section, not the top session-info card'
    );
    $sessionCsrfToken = extract_csrf_token($result['body']);

    // --- answer_prompt.php: GET not allowed ---
    $result = curl_request('GET', "{$baseUrl}/answer_prompt.php?session=cc-20260101-1200&option=1");
    assert_equal(405, $result['status'], 'GET /answer_prompt.php: 405 (POST required)');

    // --- answer_prompt.php: CSRF enforced ---
    $result = curl_request('POST', "{$baseUrl}/answer_prompt.php", [
        '-d', 'session=cc-20260101-1200&option=1&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /answer_prompt.php with a wrong csrf_token: 403');

    // --- answer_prompt.php: valid CSRF -> canned agent accepts option 1 for the canned session, returns JSON (no redirect) ---
    $result = curl_request('POST', "{$baseUrl}/answer_prompt.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&option=1&csrf_token=' . urlencode((string)$sessionCsrfToken),
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /answer_prompt.php with valid CSRF: 200 (JSON, not a redirect)');
    $answerBody = json_decode($result['body'], true);
    assert_true(is_array($answerBody) && ($answerBody['ok'] ?? false), 'POST /answer_prompt.php: canned agent accepts the option, response decodes as ok=true JSON');

    // --- answer_prompt.php: canned agent rejects an option it isn't currently offering ---
    $result = curl_request('POST', "{$baseUrl}/answer_prompt.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&option=99&csrf_token=' . urlencode((string)$sessionCsrfToken),
    ], $cookieJar);
    $answerRejectBody = json_decode($result['body'], true);
    assert_equal(false, $answerRejectBody['ok'] ?? null, 'POST /answer_prompt.php: canned agent rejects an option not currently offered');

    // --- answer_prompt.php: a non-empty "text" field routes to the free-text (answer_prompt_with_text) action instead ---
    $result = curl_request('POST', "{$baseUrl}/answer_prompt.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&option=3&text=' . urlencode('My custom reply') . '&csrf_token=' . urlencode((string)$sessionCsrfToken),
    ], $cookieJar);
    $freetextBody = json_decode($result['body'], true);
    assert_true(is_array($freetextBody) && ($freetextBody['ok'] ?? false), 'POST /answer_prompt.php: canned agent accepts a free-text reply, response decodes as ok=true JSON');

    // --- answer_prompt.php: a whitespace-only "text" field is treated as empty, falling back to the plain
    // answer_prompt path - option 3 isn't option 1, so the canned agent rejects it there too ---
    $result = curl_request('POST', "{$baseUrl}/answer_prompt.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&option=3&text=' . urlencode('   ') . '&csrf_token=' . urlencode((string)$sessionCsrfToken),
    ], $cookieJar);
    $emptyFreetextBody = json_decode($result['body'], true);
    assert_equal(false, $emptyFreetextBody['ok'] ?? null, 'POST /answer_prompt.php: whitespace-only text is treated as no text, falling back to the plain (rejecting) path');

    // --- session.php: unknown session name renders a "not found" state, not an error page ---
    $result = curl_request('GET', "{$baseUrl}/session.php?session=cc-not-a-real-session");
    assert_equal(200, $result['status'], 'GET /session.php for an unknown session: 200 (not an HTTP error)');
    assert_contains('Session not found', $result['body'], 'GET /session.php for an unknown session: shows a not-found message');

    // --- session_history.php: passes the canned agent's session_history action through as JSON ---
    $result = curl_request('GET', "{$baseUrl}/session_history.php?session=cc-20260101-1200&before=1&limit=30");
    assert_equal(200, $result['status'], 'GET /session_history.php: 200');
    $historyBody = json_decode($result['body'], true);
    assert_true(is_array($historyBody) && ($historyBody['ok'] ?? false), 'GET /session_history.php: response decodes as ok=true JSON');
    assert_equal(13, count($historyBody['entries'] ?? []), 'GET /session_history.php: canned entries passed through');

    // --- session_history.php: &after= (the regular-poll path) reaches the
    // agent action - proves the plumbing (controller -> agent_call ->
    // canned_session_history()) actually forwards it, not just that the
    // real filtering logic works (already unit-tested directly against
    // TranscriptService::read_transcript_page_since()). ---
    $afterResult = curl_request('GET', "{$baseUrl}/session_history.php?session=cc-20260101-1200&after=5");
    assert_equal(200, $afterResult['status'], 'GET /session_history.php?after=5: 200');
    $afterBody = json_decode($afterResult['body'], true);
    assert_true(is_array($afterBody) && ($afterBody['ok'] ?? false), 'GET /session_history.php?after=5: response decodes as ok=true JSON');
    assert_equal([6, 7, 8, 9, 10, 11, 12, 13, 14], array_column($afterBody['entries'] ?? [], 'line'), 'GET /session_history.php?after=5: only entries past line 5 come back, proving &after= reached the agent action');

    // --- session.php: compose bar present for a real session ---
    $result = curl_request('GET', "{$baseUrl}/session.php?session=cc-20260101-1200");
    assert_contains('id="compose-bar"', $result['body'], 'GET /session.php: message compose bar present');
    assert_contains('id="compose-textarea"', $result['body'], 'GET /session.php: compose textarea present');
    assert_contains('id="compose-attach-btn"', $result['body'], 'GET /session.php: attach-file button present');
    assert_contains('id="compose-file-input"', $result['body'], 'GET /session.php: hidden file input present for the attach button');
    assert_contains('id="compose-attachments-preview"', $result['body'], 'GET /session.php: compose-attachments preview container present, for pending uploads shown as removable chips above the textarea');
    assert_contains('id="uploaded-files-list"', $result['body'], 'GET /session.php: sidebar uploaded-files list present');
    assert_contains('id="delete-all-uploads-btn"', $result['body'], 'GET /session.php: sidebar delete-all-uploads button present');
    assert_contains('id="plan-files-list"', $result['body'], 'GET /session.php: sidebar plan/handoff-files list present');
    assert_true(
        preg_match('/id="compose-textarea"[^>]*class="[^"]*\btext-base\b/', $result['body']) === 1,
        'GET /session.php: compose textarea uses a >=16px font size, so focusing it does not trigger iOS zoom'
    );
    assert_contains('minimum-scale=1', $result['body'], 'GET /session.php: viewport meta blocks zoom-out below the normal layout');
    assert_true(
        !str_contains($result['body'], 'user-scalable=no') && !str_contains($result['body'], 'maximum-scale=1'),
        'GET /session.php: viewport meta still allows the user to pinch-zoom in'
    );
    assert_true(
        preg_match('/id="compose-input-row" class="[^"]*\bhidden\b/', $result['body']) === 1,
        'GET /session.php: compose input row is hidden by default for the canned (blocked) session'
    );
    assert_true(
        preg_match('/id="compose-blocked-note" class="(?!hidden)/', $result['body']) === 1,
        'GET /session.php: the "answer the prompt" note is shown instead, for the canned (blocked) session'
    );
    assert_contains('id="quota-footer"', $result['body'], 'GET /session.php: quota footer present inside the compose bar');
    assert_true(
        strpos($result['body'], 'id="quota-footer"') > strpos($result['body'], 'id="compose-textarea"'),
        'GET /session.php: quota footer is placed below the compose textarea, inside #compose-bar'
    );
    assert_contains('data-session="cc-20260101-1200"', $result['body'], 'GET /session.php: quota footer carries this session\'s own name, for the context-percent overlay');
    assert_contains('id="push-notify-btn"', $result['body'], 'GET /session.php: push-notification "Notify me" control present when the canned agent reports VAPID configured');
    assert_contains(CANNED_VAPID_PUBLIC_KEY, $result['body'], 'GET /session.php: the actual VAPID public key is embedded for the frontend subscribe flow');
    assert_contains('id="mode-select"', $result['body'], 'GET /session.php: mode select present');
    assert_true(
        strpos($result['body'], 'id="mode-select"') > strpos($result['body'], 'id="quota-toggle-btn"'),
        'GET /session.php: mode select shares the same row as the quota toggle, not a separate row'
    );

    // --- session_send.php: GET not allowed ---
    $result = curl_request('GET', "{$baseUrl}/session_send.php?session=cc-20260101-1200");
    assert_equal(405, $result['status'], 'GET /session_send.php: 405 (POST required)');

    // --- session_send.php: CSRF enforced ---
    $result = curl_request('POST', "{$baseUrl}/session_send.php", [
        '-d', 'session=cc-20260101-1200&csrf_token=not-the-real-token&message=hi',
    ]);
    assert_equal(403, $result['status'], 'POST /session_send.php with a wrong csrf_token: 403');

    // --- session_send.php: valid CSRF, real message -> canned agent accepts it, returns JSON (no redirect, no page reload) ---
    $csrfForSend = extract_csrf_token(curl_request('GET', "{$baseUrl}/", [], $cookieJar)['body']);
    $result = curl_request('POST', "{$baseUrl}/session_send.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&csrf_token=' . urlencode((string)$csrfForSend) . '&message=' . urlencode('Please continue'),
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /session_send.php with valid CSRF: 200 (JSON, not a redirect)');
    $sendBody = json_decode($result['body'], true);
    assert_true(is_array($sendBody) && ($sendBody['ok'] ?? false), 'POST /session_send.php: canned agent accepts the message, response decodes as ok=true JSON');

    // --- session_send.php: canned agent rejects an empty message ---
    $result = curl_request('POST', "{$baseUrl}/session_send.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&csrf_token=' . urlencode((string)$csrfForSend) . '&message=',
    ], $cookieJar);
    $emptyBody = json_decode($result['body'], true);
    assert_equal(false, $emptyBody['ok'] ?? null, 'POST /session_send.php: canned agent rejects an empty message');

    // --- session_send.php: an empty message with attachments[] present is
    // still accepted - proves attachments[] actually reaches the agent
    // action as attachment_paths (not just that a blank message alone is
    // rejected, already covered above). ---
    $result = curl_request('POST', "{$baseUrl}/session_send.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&csrf_token=' . urlencode((string)$csrfForSend) . '&message=&attachments%5B%5D=' . urlencode('.claude/uploads/report.pdf'),
    ], $cookieJar);
    $attachmentOnlySendBody = json_decode($result['body'], true);
    assert_true(is_array($attachmentOnlySendBody) && ($attachmentOnlySendBody['ok'] ?? false), 'POST /session_send.php: an empty message with attachments[] present is still accepted, proving attachments[] reaches the agent action');

    // --- session_mode.php: GET not allowed ---
    $result = curl_request('GET', "{$baseUrl}/session_mode.php?session=cc-20260101-1200");
    assert_equal(405, $result['status'], 'GET /session_mode.php: 405 (POST required)');

    // --- session_mode.php: CSRF enforced ---
    $result = curl_request('POST', "{$baseUrl}/session_mode.php", [
        '-d', 'session=cc-20260101-1200&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /session_mode.php with a wrong csrf_token: 403');

    // --- session_mode.php: valid CSRF, real mode -> canned agent accepts it, returns JSON ---
    $result = curl_request('POST', "{$baseUrl}/session_mode.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&csrf_token=' . urlencode((string)$csrfForSend) . '&mode=plan',
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /session_mode.php with valid CSRF: 200 (JSON, not a redirect)');
    $modeBody = json_decode($result['body'], true);
    assert_true(is_array($modeBody) && ($modeBody['ok'] ?? false), 'POST /session_mode.php: canned agent accepts the mode change, response decodes as ok=true JSON');

    // --- session_mode.php: canned agent rejects a session it doesn't recognize ---
    $result = curl_request('POST', "{$baseUrl}/session_mode.php", [
        '-d', 'session=' . urlencode('cc-not-a-real-session') . '&csrf_token=' . urlencode((string)$csrfForSend) . '&mode=plan',
    ], $cookieJar);
    $modeRejectBody = json_decode($result['body'], true);
    assert_equal(false, $modeRejectBody['ok'] ?? null, 'POST /session_mode.php: canned agent rejects an unrecognized session');

    // --- session_escape.php: GET not allowed ---
    $result = curl_request('GET', "{$baseUrl}/session_escape.php?session=cc-20260101-1200");
    assert_equal(405, $result['status'], 'GET /session_escape.php: 405 (POST required)');

    // --- session_escape.php: CSRF enforced ---
    $result = curl_request('POST', "{$baseUrl}/session_escape.php", [
        '-d', 'session=cc-20260101-1200&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /session_escape.php with a wrong csrf_token: 403');

    // --- session_escape.php: valid CSRF -> canned agent accepts it, returns JSON ---
    $result = curl_request('POST', "{$baseUrl}/session_escape.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /session_escape.php with valid CSRF: 200 (JSON, not a redirect)');
    $escapeBody = json_decode($result['body'], true);
    assert_true(is_array($escapeBody) && ($escapeBody['ok'] ?? false), 'POST /session_escape.php: canned agent accepts the stop request, response decodes as ok=true JSON');

    // --- session_escape.php: canned agent rejects a session it doesn't recognize ---
    $result = curl_request('POST', "{$baseUrl}/session_escape.php", [
        '-d', 'session=' . urlencode('cc-not-a-real-session') . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    $escapeRejectBody = json_decode($result['body'], true);
    assert_equal(false, $escapeRejectBody['ok'] ?? null, 'POST /session_escape.php: canned agent rejects an unrecognized session');

    // --- session_navigate.php: GET not allowed ---
    $result = curl_request('GET', "{$baseUrl}/session_navigate.php?session=cc-20260101-1200&direction=left");
    assert_equal(405, $result['status'], 'GET /session_navigate.php: 405 (POST required)');

    // --- session_navigate.php: CSRF enforced ---
    $result = curl_request('POST', "{$baseUrl}/session_navigate.php", [
        '-d', 'session=cc-20260101-1200&direction=left&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /session_navigate.php with a wrong csrf_token: 403');

    // --- session_navigate.php: valid CSRF -> canned agent accepts it, returns JSON ---
    $result = curl_request('POST', "{$baseUrl}/session_navigate.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&direction=right&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /session_navigate.php with valid CSRF: 200 (JSON, not a redirect)');
    $navigateBody = json_decode($result['body'], true);
    assert_true(is_array($navigateBody) && ($navigateBody['ok'] ?? false), 'POST /session_navigate.php: canned agent accepts the navigate request, response decodes as ok=true JSON');

    // --- session_navigate.php: canned agent rejects a session not showing a multi-question prompt ---
    $result = curl_request('POST', "{$baseUrl}/session_navigate.php", [
        '-d', 'session=' . urlencode('cc-not-a-real-session') . '&direction=right&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    $navigateRejectBody = json_decode($result['body'], true);
    assert_equal(false, $navigateRejectBody['ok'] ?? null, 'POST /session_navigate.php: canned agent rejects an unrecognized session');

    // --- push_subscribe.php: GET not allowed ---
    $result = curl_request('GET', "{$baseUrl}/push_subscribe.php");
    assert_equal(405, $result['status'], 'GET /push_subscribe.php: 405 (POST required)');

    // --- push_subscribe.php: CSRF enforced ---
    $fakeSubscriptionJson = json_encode(['endpoint' => 'https://push.example/x', 'keys' => ['p256dh' => 'p', 'auth' => 'a']]);
    $result = curl_request('POST', "{$baseUrl}/push_subscribe.php", [
        '-d', 'subscription=' . urlencode((string)$fakeSubscriptionJson) . '&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /push_subscribe.php with a wrong csrf_token: 403');

    // --- push_subscribe.php: valid CSRF + well-formed subscription -> canned agent accepts it ---
    $result = curl_request('POST', "{$baseUrl}/push_subscribe.php", [
        '-d', 'subscription=' . urlencode((string)$fakeSubscriptionJson) . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /push_subscribe.php with valid CSRF: 200 (JSON, not a redirect)');
    $subscribeBody = json_decode($result['body'], true);
    assert_true(is_array($subscribeBody) && ($subscribeBody['ok'] ?? false), 'POST /push_subscribe.php: canned agent accepts a well-formed subscription, response decodes as ok=true JSON');

    // --- push_subscribe.php: malformed/missing subscription field is rejected before ever reaching the agent ---
    $result = curl_request('POST', "{$baseUrl}/push_subscribe.php", [
        '-d', 'csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    $malformedSubscribeBody = json_decode($result['body'], true);
    assert_equal(false, $malformedSubscribeBody['ok'] ?? null, 'POST /push_subscribe.php: rejects a request with no subscription field at all');

    // --- push_unsubscribe.php: GET not allowed, CSRF enforced, valid request accepted ---
    $result = curl_request('GET', "{$baseUrl}/push_unsubscribe.php");
    assert_equal(405, $result['status'], 'GET /push_unsubscribe.php: 405 (POST required)');

    $result = curl_request('POST', "{$baseUrl}/push_unsubscribe.php", [
        '-d', 'endpoint=' . urlencode('https://push.example/x') . '&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /push_unsubscribe.php with a wrong csrf_token: 403');

    $result = curl_request('POST', "{$baseUrl}/push_unsubscribe.php", [
        '-d', 'endpoint=' . urlencode('https://push.example/x') . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /push_unsubscribe.php with valid CSRF: 200 (JSON, not a redirect)');
    $unsubscribeBody = json_decode($result['body'], true);
    assert_true(is_array($unsubscribeBody) && ($unsubscribeBody['ok'] ?? false), 'POST /push_unsubscribe.php: canned agent accepts it, response decodes as ok=true JSON');

    // --- upload_file.php: GET not allowed, CSRF enforced, a real file upload relayed to the canned agent ---
    $result = curl_request('GET', "{$baseUrl}/upload_file.php");
    assert_equal(405, $result['status'], 'GET /upload_file.php: 405 (POST required)');

    $uploadFixturePath = sys_get_temp_dir() . '/csm-test-upload-' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($uploadFixturePath, 'fixture upload content');

    $result = curl_request('POST', "{$baseUrl}/upload_file.php", [
        '-F', 'session=cc-20260101-1200',
        '-F', 'csrf_token=not-the-real-token',
        '-F', 'file=@' . $uploadFixturePath,
    ]);
    assert_equal(403, $result['status'], 'POST /upload_file.php with a wrong csrf_token: 403');

    $result = curl_request('POST', "{$baseUrl}/upload_file.php", [
        '-F', 'session=cc-20260101-1200',
        '-F', 'csrf_token=' . (string)$csrfForSend,
        '-F', 'file=@' . $uploadFixturePath,
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /upload_file.php with valid CSRF: 200 (JSON, not a redirect)');
    $uploadBody = json_decode($result['body'], true);
    assert_true(is_array($uploadBody) && ($uploadBody['ok'] ?? false), 'POST /upload_file.php: canned agent accepts the upload, response decodes as ok=true JSON');
    assert_equal(strlen('fixture upload content'), $uploadBody['size'] ?? null, 'POST /upload_file.php: the real file content was read and its true (decoded) size relayed through to the agent action, not e.g. the base64 length');

    $result = curl_request('POST', "{$baseUrl}/upload_file.php", [
        '-F', 'session=cc-20260101-1200',
        '-F', 'csrf_token=' . (string)$csrfForSend,
    ], $cookieJar);
    $noFileBody = json_decode($result['body'], true);
    assert_equal(false, $noFileBody['ok'] ?? null, 'POST /upload_file.php: rejects a request with no file field at all');

    @unlink($uploadFixturePath);

    // --- uploaded_files.php: GET-only, passes the canned agent's list through as JSON ---
    $result = curl_request('GET', "{$baseUrl}/uploaded_files.php?session=cc-20260101-1200");
    assert_equal(200, $result['status'], 'GET /uploaded_files.php: 200');
    $filesBody = json_decode($result['body'], true);
    assert_true(is_array($filesBody) && ($filesBody['ok'] ?? false), 'GET /uploaded_files.php: response decodes as ok=true JSON');
    assert_equal(2, count($filesBody['files'] ?? []), 'GET /uploaded_files.php: canned files passed through');

    // --- session_plan_files.php: GET-only, passes the canned agent's plan/handoff-files list through as JSON ---
    $result = curl_request('GET', "{$baseUrl}/session_plan_files.php?session=cc-20260101-1200");
    assert_equal(200, $result['status'], 'GET /session_plan_files.php: 200');
    $planFilesBody = json_decode($result['body'], true);
    assert_true(is_array($planFilesBody) && ($planFilesBody['ok'] ?? false), 'GET /session_plan_files.php: response decodes as ok=true JSON');
    assert_equal(['PLAN.md', 'handoff-2026-08-08.md'], array_column($planFilesBody['files'] ?? [], 'name'), 'GET /session_plan_files.php: canned files passed through');

    $planFilesUnknownResult = curl_request('GET', "{$baseUrl}/session_plan_files.php?session=cc-not-a-real-session");
    $planFilesUnknownBody = json_decode($planFilesUnknownResult['body'], true);
    assert_equal(false, $planFilesUnknownBody['ok'] ?? null, 'GET /session_plan_files.php: ok=false for an unrecognized session, not a crash');

    // --- delete_uploaded_file.php: GET not allowed, CSRF enforced, canned agent accepts/rejects by filename ---
    $result = curl_request('GET', "{$baseUrl}/delete_uploaded_file.php");
    assert_equal(405, $result['status'], 'GET /delete_uploaded_file.php: 405 (POST required)');

    $result = curl_request('POST', "{$baseUrl}/delete_uploaded_file.php", [
        '-d', 'session=cc-20260101-1200&filename=photo.jpg&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /delete_uploaded_file.php with a wrong csrf_token: 403');

    $result = curl_request('POST', "{$baseUrl}/delete_uploaded_file.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&filename=' . urlencode('photo.jpg') . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    $deleteFileBody = json_decode($result['body'], true);
    assert_true(is_array($deleteFileBody) && ($deleteFileBody['ok'] ?? false), 'POST /delete_uploaded_file.php: canned agent accepts the known filename, response decodes as ok=true JSON');

    $result = curl_request('POST', "{$baseUrl}/delete_uploaded_file.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&filename=' . urlencode('never-existed.txt') . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    $deleteMissingBody = json_decode($result['body'], true);
    assert_equal(false, $deleteMissingBody['ok'] ?? null, 'POST /delete_uploaded_file.php: canned agent rejects an unrecognized filename');

    // --- delete_all_uploaded_files.php: GET not allowed, CSRF enforced, valid request accepted ---
    $result = curl_request('GET', "{$baseUrl}/delete_all_uploaded_files.php");
    assert_equal(405, $result['status'], 'GET /delete_all_uploaded_files.php: 405 (POST required)');

    $result = curl_request('POST', "{$baseUrl}/delete_all_uploaded_files.php", [
        '-d', 'session=cc-20260101-1200&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /delete_all_uploaded_files.php with a wrong csrf_token: 403');

    $result = curl_request('POST', "{$baseUrl}/delete_all_uploaded_files.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    $deleteAllBody = json_decode($result['body'], true);
    assert_true(is_array($deleteAllBody) && ($deleteAllBody['ok'] ?? false), 'POST /delete_all_uploaded_files.php: canned agent accepts it, response decodes as ok=true JSON');
    assert_equal(2, $deleteAllBody['deleted'] ?? null, 'POST /delete_all_uploaded_files.php: canned deleted count passed through');

    // --- cross-origin POST rejected ---
    $result = curl_request('POST', "{$baseUrl}/", [
        '-H', 'Origin: http://evil.example',
        '-d', 'action=cleanup',
    ]);
    assert_equal(403, $result['status'], 'POST with mismatched Origin: 403');

    // --- archived_session.php: the read-only dormant-session view - no
    // ?claude_session_id -> redirects home, same as session.php's own
    // no-?session case. Uses its own $archived*-prefixed variables
    // throughout (not $result) - unlike most of this file's tests, which
    // run in one long sequential block reusing $result freely, several
    // EARLIER blocks above (the ExitPlanMode/sidebar/answer_prompt
    // assertions) reuse $result long after their own curl_request() call,
    // so reassigning it here would corrupt state for code that already
    // ran - this whole block is deliberately placed at the very end of the
    // curl-only tier for that reason, after nothing else depends on $result
    // anymore. ---
    $archivedResult = curl_request('GET', "{$baseUrl}/archived_session.php");
    assert_equal(303, $archivedResult['status'], 'GET /archived_session.php with no claude_session_id param: 303 redirect');
    assert_equal('/', $archivedResult['headers']['location'] ?? '', 'GET /archived_session.php with no claude_session_id param: redirects to /');

    // --- archived_session.php: renders the canned archived session's
    // detail + history, reusing the exact same TranscriptView rendering
    // session.php itself uses ---
    $archivedResult = curl_request('GET', "{$baseUrl}/archived_session.php?claude_session_id=" . CANNED_ARCHIVED_CLAUDE_SESSION_ID);
    assert_equal(200, $archivedResult['status'], 'GET /archived_session.php: 200');
    assert_contains('Refactor the old widget', $archivedResult['body'], 'GET /archived_session.php: canned title shown');
    assert_contains('old-project', $archivedResult['body'], 'GET /archived_session.php: canned cwd shown');
    assert_contains('Fix the login redirect bug', $archivedResult['body'], 'GET /archived_session.php: canned history entry rendered');
    assert_contains('Archived', $archivedResult['body'], 'GET /archived_session.php: the "Archived" badge is shown, distinguishing it from a live session.php view');
    assert_true(!str_contains($archivedResult['body'], 'compose-bar'), 'GET /archived_session.php: no compose bar - nothing here is actionable');
    assert_contains('Load older messages', $archivedResult['body'], 'GET /archived_session.php: load-more button shown when has_more=true');

    // --- archived_session.php: an unknown (but well-formed) claude_session_id
    // -> the page still renders (200), just with a "not found" state, same
    // as session.php's own not-found handling ---
    $archivedResult = curl_request('GET', "{$baseUrl}/archived_session.php?claude_session_id=00000000-0000-4000-8000-000000000000");
    assert_equal(200, $archivedResult['status'], 'GET /archived_session.php: 200 even for an unknown claude_session_id');
    assert_contains('Session not found', $archivedResult['body'], 'GET /archived_session.php: shows a not-found message for an unknown claude_session_id');

    // --- archived_session_history_fragment.php: "Load older messages" -
    // pre-rendered HTML (not raw JSON entries, unlike session_history.php -
    // see SessionController::archivedHistoryFragment()'s own doc comment) ---
    $archivedResult = curl_request('GET', "{$baseUrl}/archived_session_history_fragment.php?claude_session_id=" . CANNED_ARCHIVED_CLAUDE_SESSION_ID);
    assert_equal(200, $archivedResult['status'], 'GET /archived_session_history_fragment.php: 200');
    $archivedHistoryFragmentBody = json_decode($archivedResult['body'], true);
    assert_true(is_array($archivedHistoryFragmentBody) && ($archivedHistoryFragmentBody['ok'] ?? false), 'GET /archived_session_history_fragment.php: response decodes as ok=true JSON');
    assert_contains('Fix the login redirect bug', $archivedHistoryFragmentBody['html'] ?? '', 'GET /archived_session_history_fragment.php: html carries the canned history entry, already rendered');
    assert_true(
        preg_match('/<details class="tool-group[^"]*"><summary[^>]*>2 tool calls<\/summary>/', $archivedHistoryFragmentBody['html'] ?? '') === 1,
        'GET /archived_session_history_fragment.php: this server-rendered fragment also groups tool calls (render_transcript_entries_html(), not the single-entry render_transcript_entry()) - same as session.php\'s initial render and archived_session.php\'s own'
    );

    $archivedResult = curl_request('GET', "{$baseUrl}/archived_session_history_fragment.php?claude_session_id=00000000-0000-4000-8000-000000000000");
    $archivedHistoryFragmentMissingBody = json_decode($archivedResult['body'], true);
    assert_equal(false, $archivedHistoryFragmentMissingBody['ok'] ?? null, 'GET /archived_session_history_fragment.php: ok=false for an unknown claude_session_id, not a crash');

    // --- archived_session_attachment.php: same binary-endpoint contract as
    // session_attachment.php, just keyed by claude_session_id ---
    $archivedAttachmentUrl = "/archived_session_attachment.php?claude_session_id=" . CANNED_ARCHIVED_CLAUDE_SESSION_ID . "&line=8&file_uuid=canned-file-uuid-1";
    $archivedAttachmentResult = curl_request('GET', "{$baseUrl}{$archivedAttachmentUrl}");
    assert_equal(200, $archivedAttachmentResult['status'], 'GET /archived_session_attachment.php: 200 for a real, matching claude_session_id/line/file_uuid');
    assert_equal('canned attachment bytes', $archivedAttachmentResult['body'], 'GET /archived_session_attachment.php: streams the real (canned) file bytes');
    assert_contains('notes.txt', $archivedAttachmentResult['headers']['content-disposition'] ?? '', 'GET /archived_session_attachment.php: Content-Disposition carries the real filename');

    $wrongArchivedAttachmentResult = curl_request('GET', "{$baseUrl}/archived_session_attachment.php?claude_session_id=" . CANNED_ARCHIVED_CLAUDE_SESSION_ID . "&line=8&file_uuid=not-the-real-uuid");
    assert_equal(404, $wrongArchivedAttachmentResult['status'], 'GET /archived_session_attachment.php: an unrecognized file_uuid -> 404, not a silent empty body');

    // --- POST resume: unlike every other dashboard action, a successful
    // resume redirects straight to the now-live session.php view, not back
    // to / with a flash - decided explicitly with Andres 2026-08-08 (see
    // the unify-claude-sessions plan's phase 5). Own $archivedResume*
    // variables throughout, same reasoning as the rest of this
    // end-of-curl-only-tier block. ---
    $archivedResumeFrontPage = curl_request('GET', "{$baseUrl}/", [], $cookieJar);
    $archivedResumeCsrfToken = extract_csrf_token($archivedResumeFrontPage['body']);
    assert_true($archivedResumeCsrfToken !== null, 'GET / (resume setup): page includes a csrf_token field');

    $archivedResumeResult = curl_request('POST', "{$baseUrl}/", [
        '-d', 'action=resume&csrf_token=' . urlencode((string)$archivedResumeCsrfToken)
            . '&claude_session_id=' . urlencode(CANNED_ARCHIVED_CLAUDE_SESSION_ID)
            . '&workdir=' . urlencode('/home/andres/www/old-project'),
    ], $cookieJar);
    assert_equal(303, $archivedResumeResult['status'], 'POST resume: 303 redirect');
    assert_equal(
        '/session.php?session=' . CANNED_RESUMED_SESSION_NAME,
        $archivedResumeResult['headers']['location'] ?? '',
        'POST resume: redirects straight to the now-live session view, not back to / with a flash'
    );

    // --- POST resume: canned agent rejects an unrecognized claude_session_id
    // - falls back to the classic flash-to-/ behavior, same as every other
    // action here, since there is no new session name to redirect to. ---
    $archivedResumeRejectFrontPage = curl_request('GET', "{$baseUrl}/", [], $cookieJar);
    $archivedResumeRejectCsrfToken = extract_csrf_token($archivedResumeRejectFrontPage['body']);

    $archivedResumeRejectResult = curl_request('POST', "{$baseUrl}/", [
        '-d', 'action=resume&csrf_token=' . urlencode((string)$archivedResumeRejectCsrfToken)
            . '&claude_session_id=00000000-0000-4000-8000-000000000000'
            . '&workdir=' . urlencode('/home/andres/www/old-project'),
    ], $cookieJar);
    assert_equal(303, $archivedResumeRejectResult['status'], 'POST resume (unrecognized id): 303 redirect');
    assert_equal('/', $archivedResumeRejectResult['headers']['location'] ?? '', 'POST resume (unrecognized id): falls back to redirecting home, not to a session view');

    $archivedResumeRejectFollow = curl_request('GET', "{$baseUrl}/", [], $cookieJar);
    assert_contains('Rejected', $archivedResumeRejectFollow['body'], 'POST resume (unrecognized id): flash shows the rejection message');

    // --- take_over_bare.php/take_over_bare_confirm.php: AJAX JSON
    // endpoints (unlike resume above) - a bare-process row's "Take over"
    // button, and the picker's confirm step. Own $takeOver*-prefixed
    // variables, same end-of-curl-only-tier reasoning as the rest of this
    // block. ---
    $takeOverFrontPage = curl_request('GET', "{$baseUrl}/", [], $cookieJar);
    $takeOverCsrfToken = extract_csrf_token($takeOverFrontPage['body']);
    assert_true($takeOverCsrfToken !== null, 'GET / (take-over setup): page includes a csrf_token field');

    $takeOverMethodResult = curl_request('GET', "{$baseUrl}/take_over_bare.php");
    assert_equal(405, $takeOverMethodResult['status'], 'GET /take_over_bare.php: 405 (POST required)');

    $takeOverCsrfRejectResult = curl_request('POST', "{$baseUrl}/take_over_bare.php", [
        '-d', 'pid=54321&csrf_token=not-the-real-token',
    ], $cookieJar);
    assert_equal(403, $takeOverCsrfRejectResult['status'], 'POST /take_over_bare.php with a wrong csrf_token: 403');

    $takeOverResult = curl_request('POST', "{$baseUrl}/take_over_bare.php", [
        '-d', 'pid=54321&csrf_token=' . urlencode((string)$takeOverCsrfToken),
    ], $cookieJar);
    assert_equal(200, $takeOverResult['status'], 'POST /take_over_bare.php with valid CSRF: 200 (JSON, not a redirect)');
    $takeOverBody = json_decode($takeOverResult['body'], true);
    assert_true(is_array($takeOverBody) && ($takeOverBody['ok'] ?? false), 'POST /take_over_bare.php: canned agent accepts the pid, response decodes as ok=true JSON');
    assert_true($takeOverBody['needs_choice'] ?? false, 'POST /take_over_bare.php: canned agent returns needs_choice (no marker match), nothing killed yet');
    assert_equal('/home/andres/www/some-other-project', $takeOverBody['workdir'] ?? null, 'POST /take_over_bare.php: canned workdir passed through');
    assert_equal([CANNED_ARCHIVED_CLAUDE_SESSION_ID], array_column($takeOverBody['candidates'] ?? [], 'claude_session_id'), 'POST /take_over_bare.php: canned candidate list passed through');
    assert_equal(CANNED_ARCHIVED_CLAUDE_SESSION_ID, $takeOverBody['suggested_claude_session_id'] ?? null, 'POST /take_over_bare.php: canned suggested_claude_session_id passed through');

    $takeOverRejectResult = curl_request('POST', "{$baseUrl}/take_over_bare.php", [
        '-d', 'pid=99999&csrf_token=' . urlencode((string)$takeOverCsrfToken),
    ], $cookieJar);
    $takeOverRejectBody = json_decode($takeOverRejectResult['body'], true);
    assert_equal(false, $takeOverRejectBody['ok'] ?? null, 'POST /take_over_bare.php: canned agent rejects an unrecognized pid');

    $takeOverConfirmMethodResult = curl_request('GET', "{$baseUrl}/take_over_bare_confirm.php");
    assert_equal(405, $takeOverConfirmMethodResult['status'], 'GET /take_over_bare_confirm.php: 405 (POST required)');

    $takeOverConfirmCsrfRejectResult = curl_request('POST', "{$baseUrl}/take_over_bare_confirm.php", [
        '-d', 'pid=54321&workdir=' . urlencode('/home/andres/www/some-other-project') . '&claude_session_id=' . CANNED_ARCHIVED_CLAUDE_SESSION_ID . '&csrf_token=not-the-real-token',
    ], $cookieJar);
    assert_equal(403, $takeOverConfirmCsrfRejectResult['status'], 'POST /take_over_bare_confirm.php with a wrong csrf_token: 403');

    $takeOverConfirmResult = curl_request('POST', "{$baseUrl}/take_over_bare_confirm.php", [
        '-d', 'pid=54321&workdir=' . urlencode('/home/andres/www/some-other-project') . '&claude_session_id=' . CANNED_ARCHIVED_CLAUDE_SESSION_ID . '&csrf_token=' . urlencode((string)$takeOverCsrfToken),
    ], $cookieJar);
    assert_equal(200, $takeOverConfirmResult['status'], 'POST /take_over_bare_confirm.php with valid CSRF: 200 (JSON, not a redirect)');
    $takeOverConfirmBody = json_decode($takeOverConfirmResult['body'], true);
    assert_true(is_array($takeOverConfirmBody) && ($takeOverConfirmBody['ok'] ?? false), 'POST /take_over_bare_confirm.php: canned agent accepts the chosen session, response decodes as ok=true JSON');
    assert_equal(CANNED_TAKEN_OVER_SESSION_NAME, $takeOverConfirmBody['name'] ?? null, 'POST /take_over_bare_confirm.php: canned new session name passed through, for the client to redirect to');

    $takeOverConfirmRejectResult = curl_request('POST', "{$baseUrl}/take_over_bare_confirm.php", [
        '-d', 'pid=54321&workdir=' . urlencode('/home/andres/www/some-other-project') . '&claude_session_id=00000000-0000-4000-8000-000000000000&csrf_token=' . urlencode((string)$takeOverCsrfToken),
    ], $cookieJar);
    $takeOverConfirmRejectBody = json_decode($takeOverConfirmRejectResult['body'], true);
    assert_equal(false, $takeOverConfirmRejectBody['ok'] ?? null, 'POST /take_over_bare_confirm.php: canned agent rejects a claude_session_id that does not match the resolved candidate');

    // --- sessions_fragment.php's bare_html: proves the Take over form
    // (SessionRowView::bare_process_row_html() -> bare-process-row.php)
    // actually rendered, with the real pid and a fresh csrf_token, not
    // just that the endpoint itself works when called directly above. ---
    $takeOverFragmentResult = curl_request('GET', "{$baseUrl}/sessions_fragment.php");
    $takeOverFragmentBody = json_decode($takeOverFragmentResult['body'], true);
    assert_true(
        preg_match('#<form method="post" action="/take_over_bare\.php" class="take-over-form"[^>]*>\s*<input type="hidden" name="csrf_token"[^>]*>\s*<input type="hidden" name="pid" value="54321">\s*<button type="submit"[^>]*>\s*Take over#', $takeOverFragmentBody['bare_html'] ?? '') === 1,
        'GET /sessions_fragment.php: bare_html carries a Take over form for the canned bare pid'
    );

    // --- session.php: a brand-new session (found, but no transcript on
    // disk yet) must still render #history-list (with a placeholder note
    // inside it), not omit the container entirely - found live
    // 2026-08-08: session.js captures #history-list via a single
    // getElementById() at load time, never re-queried, so a missing
    // container left `list` permanently null for the whole page's life,
    // crashing the very first compose send (list.appendChild() on null)
    // rather than just showing an empty state. ---
    $newSessionResult = curl_request('GET', "{$baseUrl}/session.php?session=" . CANNED_NEW_SESSION_NAME);
    assert_equal(200, $newSessionResult['status'], 'GET /session.php (brand-new session): 200');
    assert_true(
        preg_match('#<div id="history-list"[^>]*>\s*<p id="history-empty-note"[^>]*>#', $newSessionResult['body']) === 1,
        'GET /session.php (brand-new session): #history-list is present and contains the placeholder note, not omitted'
    );
    assert_contains('id="compose-bar"', $newSessionResult['body'], 'GET /session.php (brand-new session): compose bar still renders normally');

    // --- optional richer tier: only if a headless browser is already on this host ---
    $browser = find_headless_browser();

    if ($browser === null) {
        echo "  SKIP: no headless browser found (checked google-chrome-stable/google-chrome/chromium/chromium-browser) - curl checks above are the required baseline\n";
    } else {
        run_headless_browser_checks($browser, $port);
    }
} finally {
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    stop_harness($agentHarness, $agentSocket);
    if (isset($cookieJar)) {
        @unlink($cookieJar);
    }
}

test_exit();

/**
 * Pulls the csrf_token hidden field's value out of a rendered page, the
 * same way a real form submission would - tests must never hardcode a
 * token, since a real one only exists inside the session the cookie jar
 * is carrying.
 */
function extract_csrf_token(string $html): ?string
{
    return preg_match('/name="csrf_token" value="([^"]*)"/', $html, $m) === 1 ? $m[1] : null;
}

function find_headless_browser(): ?string
{
    foreach (['/usr/bin/google-chrome-stable', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * Best-effort: renders the page in a real JS engine to catch things curl
 * can't (uncaught JS errors, whether the DOM curl already checked
 * actually parses in a browser). Does NOT open the New Session <details>
 * or exercise the folder browser's fetch()-driven navigation - that needs
 * a scriptable devtools protocol client (puppeteer/playwright), which
 * isn't assumed to be installed offline on this host. If that's ever
 * added, it belongs here.
 */
function run_headless_browser_checks(string $browser, int $port): void
{
    $base = "http://127.0.0.1:{$port}";

    $home = headless_dump_dom($browser, "{$base}/");

    if ($home === null) {
        return;
    }

    assert_contains('id="new-session-details"', $home['dom'], 'headless browser: renders the New Session folder browser');
    assert_true(!str_contains($home['stderr'], 'Uncaught'), 'headless browser: no uncaught JS errors on load');

    $detail = headless_dump_dom($browser, "{$base}/session.php?session=cc-20260101-1200");

    if ($detail === null) {
        return;
    }

    assert_contains('id="load-more-btn"', $detail['dom'], 'headless browser: session.php renders the load-more control');
    assert_contains('id="go-to-bottom-btn"', $detail['dom'], 'headless browser: session.php renders the floating go-to-bottom button');
    assert_true(!str_contains($detail['stderr'], 'Uncaught'), 'headless browser: no uncaught JS errors on session.php');

    $archivedDetail = headless_dump_dom($browser, "{$base}/archived_session.php?claude_session_id=" . CANNED_ARCHIVED_CLAUDE_SESSION_ID);

    if ($archivedDetail === null) {
        return;
    }

    assert_contains('id="load-more-btn"', $archivedDetail['dom'], 'headless browser: archived_session.php renders the load-more control');
    assert_true(!str_contains($archivedDetail['dom'], 'id="compose-bar"'), 'headless browser: archived_session.php renders with no compose bar');
    assert_true(!str_contains($archivedDetail['stderr'], 'Uncaught'), 'headless browser: no uncaught JS errors on archived_session.php');
}

/**
 * @return array{dom:string, stderr:string}|null null means "skip, couldn't get a usable DOM" -
 *   already logged, caller should just return.
 */
function headless_dump_dom(string $browser, string $url): ?array
{
    $cmd = [$browser, '--headless=new', '--disable-gpu', '--no-sandbox', '--virtual-time-budget=4000', '--dump-dom', $url];

    $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        echo "  SKIP: headless browser found at {$browser} but failed to launch\n";
        return null;
    }

    fclose($pipes[0]);
    $dom = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if (trim($dom) === '') {
        echo "  SKIP: headless browser produced no DOM for {$url} (offline/network-restricted host may block chrome's startup checks)\n";
        return null;
    }

    return ['dom' => $dom, 'stderr' => $stderr];
}
