<?php
declare(strict_types=1);

/**
 * Boots PHP's built-in server serving src/ against a canned fake-agent
 * socket (tests/fixtures/canned_agent.php - never touches tmux) and drives
 * it with curl. Always runs standalone (no MCP / IDE dependency - just
 * php, curl, and optionally a headless browser binary already on the
 * host), per the requirement that tests/run.sh works outside Claude.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/http.php';

// Must match CANNED_TEST_IMAGE_BASE64 in fixtures/canned_agent.php - that
// file runs as its own separate process (spawned per-connection by
// socket_harness.php), so its constants aren't reachable from here.
const CANNED_TEST_IMAGE_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
const CANNED_VAPID_PUBLIC_KEY = 'BAhRdSrCIQS6QqCKKxkfmfSQ_DyQk63-8zoSMWlb2PXjhuTym7Lxyboe7HSFwi79IJN7-wqbUbZmYR1CkLvXZSc';

$agentSocket = sys_get_temp_dir() . '/csm-test-ui-agent.sock';
$agentHarness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $agentSocket);

$port = 18099;
$baseUrl = "http://127.0.0.1:{$port}";

$serverEnv = array_merge(getenv(), [
    'CSM_AGENT_SOCKET' => $agentSocket,
]);
$serverProcess = proc_open(
    ['php', '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/src'],
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
    assert_contains('Fix the login redirect bug', $result['body'], 'GET /: canned pane title shown as the primary label');
    assert_contains('cc-20260101-1200', $result['body'], 'GET /: raw session name still shown (secondary, since a title is present)');
    assert_contains('demo-project', $result['body'], 'GET /: canned workdir rendered');
    assert_contains('detached', $result['body'], 'GET /: canned session shown as detached');
    assert_contains('Bare title', $result['body'], "GET /: canned bare process's tmux pane title shown");
    assert_contains('csm-test-adhoc', $result['body'], "GET /: canned bare process's owning tmux session shown");
    assert_contains("I&#039;ll clean up the temp directory now", $result['body'], 'GET /: last-message preview shown under a non-blocked session row');
    assert_contains('show-recent-btn', $result['body'], 'GET /: "show last 3 messages" toggle button present');
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
    assert_true(!str_contains($result['body'], "isn't installed"), 'GET /: session-rotation hook banner not shown when the canned agent reports it already installed');
    assert_contains('id="push-notify-btn"', $result['body'], 'GET /: push-notification "Notify me" control present when the canned agent reports VAPID configured');
    assert_contains(CANNED_VAPID_PUBLIC_KEY, $result['body'], 'GET /: the actual VAPID public key is embedded for the frontend subscribe flow');
    // Both canned sessions have working=true, but only the non-blocked one
    // should ever show the indicator - the blocked one must not, proving
    // dashboard_thinking_indicator_html()'s blocked_reason check actually
    // wins rather than just happening to not be exercised by the fixture.
    assert_equal(1, substr_count($result['body'], 'Thinking&hellip;'), 'GET /: thinking indicator shown exactly once - for the working, non-blocked session, not the working-but-blocked one');
    assert_contains('id="poll-interval-select"', $result['body'], 'GET /: dashboard polling-interval dropdown present in the header');
    assert_contains('value="3000" selected', $result['body'], 'GET /: dashboard polling-interval dropdown defaults to 3s');
    assert_contains('id="session-count-text"', $result['body'], 'GET /: session-count text is a targetable element (updated live by the poll)');
    assert_contains('id="sessions-container"', $result['body'], 'GET /: session list lives inside a targetable container (swapped in place by the poll)');
    assert_contains('id="bare-container"', $result['body'], 'GET /: bare-process list lives inside a targetable container (swapped in place by the poll)');

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
    // through the exact same sessions_list_html()/bare_processes_html()/
    // session_count_label_html() functions index.php's SSR render itself
    // calls (see AgentClient.php), so this must contain the same
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
    assert_true(
        strpos($result['body'], 'id="blocked-prompt-section"') < strpos($result['body'], 'rm -rf /tmp/canned-example'),
        'GET /session.php: the pending command lives inside the blocked-prompt card, not a separate bubble above it'
    );
    assert_contains('id="go-to-bottom-btn"', $result['body'], 'GET /session.php: floating go-to-bottom button present');
    assert_contains('id="sidebar-toggle-btn"', $result['body'], 'GET /session.php: sidebar toggle button present');
    assert_contains('id="sidebar-notify-dot"', $result['body'], 'GET /session.php: sidebar notification dot present');
    assert_contains('id="confirm-before-answer-toggle"', $result['body'], 'GET /session.php: confirm-before-answering setting checkbox present in the sidebar');
    assert_contains('id="poll-interval-select"', $result['body'], 'GET /session.php: polling-interval dropdown present in the sticky header');
    assert_contains('value="3000" selected', $result['body'], 'GET /session.php: polling-interval dropdown defaults to 3s');
    assert_contains('id="show-tool-details-toggle"', $result['body'], 'GET /session.php: show-tool-details setting checkbox present in the sidebar');
    assert_contains('class="tool-detail"', $result['body'], 'GET /session.php: tool_result blocks are tagged hideable by the show/hide toggle');
    assert_contains('class="tool-use-block"', $result['body'], 'GET /session.php: tool_use blocks get their own class, kept separate from tool-detail');
    assert_contains('body.hide-tool-details .tool-detail', $result['body'], 'GET /session.php: the hide-tool-details CSS rule only targets tool_result (tool_use is a separate toggle)');
    assert_contains('body.hide-tool-details .entry-tool-result-only', $result['body'], 'GET /session.php: a second hide-tool-details rule hides whole entries left with nothing but a hidden tool_result');
    assert_contains('id="show-tool-calls-toggle"', $result['body'], 'GET /session.php: show-tool-calls setting checkbox present in the sidebar');
    assert_contains('id="show-tool-calls-toggle" class="rounded border-slate-600 bg-slate-800" checked', $result['body'], 'GET /session.php: show-tool-calls checkbox is checked (shown) by default');
    assert_contains('body.hide-tool-calls .tool-use-block', $result['body'], 'GET /session.php: the hide-tool-calls CSS rule targets tool_use blocks');
    assert_contains('body.hide-tool-calls .entry-tool-use-only', $result['body'], 'GET /session.php: a second hide-tool-calls rule hides whole entries left with nothing but a hidden tool_use');
    assert_contains('.new-content-divider.fading', $result['body'], 'GET /session.php: the new-content divider fade rule is shipped');
    assert_contains('.new-content-highlight.fading', $result['body'], 'GET /session.php: the new-content highlight fade rule is shipped (two-class pattern, not a plain classList.remove, so `transition` survives the fade)');
    assert_contains('function markNewContent(', $result['body'], 'GET /session.php: markNewContent() (divider + highlight ring on freshly-polled entries) is shipped');
    assert_contains('>Tool output<', $result['body'], 'GET /session.php: the canned tool_result entry ("done") is labeled "Tool output", not "User"');
    assert_contains('>Tool call<', $result['body'], 'GET /session.php: the canned tool_use entry ("Bash(pwd)") is labeled "Tool call", not "Assistant"');
    assert_contains('>Subagent call<', $result['body'], 'GET /session.php: the canned Agent tool_use entry is labeled "Subagent call", not "Tool call"');
    assert_contains('>Subagent report<', $result['body'], 'GET /session.php: the canned Agent tool_result entry is labeled "Subagent report", not "Tool output"');
    assert_contains('general-purpose: Investigate the login bug', $result['body'], 'GET /session.php: the subagent call summary shows subagent_type + description, not a raw param dump');
    assert_contains('Found it: the redirect URL was hardcoded.', $result['body'], 'GET /session.php: the subagent report shows its real output');
    assert_true(
        preg_match('/<div class="rounded-lg border ([^"]*)">(?:(?!<div class="rounded-lg border).)*?Investigate the login bug/s', $result['body'], $subagentCallMatch) === 1
            && str_contains($subagentCallMatch[1], 'border-fuchsia-800/60'),
        'GET /session.php: the subagent call entry uses the fuchsia color, distinct from a plain tool call'
    );
    assert_true(
        preg_match('/<div class="rounded-lg border ([^"]*)">(?:(?!<div class="rounded-lg border).)*?redirect URL was hardcoded/s', $result['body'], $subagentResultMatch) === 1
            && str_contains($subagentResultMatch[1], 'border-fuchsia-800/60'),
        'GET /session.php: the subagent report entry uses the fuchsia color too'
    );
    assert_true(
        preg_match('/<div class="rounded-lg border ([^"]*)">(?:(?!<div class="rounded-lg border).)*?Bash\(pwd\)/s', $result['body'], $toolUseEntryMatch) === 1
            && strpos($toolUseEntryMatch[1], 'entry-tool-result-only') === false,
        'GET /session.php: the canned tool_use entry ("Bash(pwd)") is NOT marked entry-tool-result-only - that marker is only for tool_result entries'
    );
    assert_true(
        isset($toolUseEntryMatch[1]) && strpos($toolUseEntryMatch[1], 'entry-tool-use-only') !== false,
        'GET /session.php: the canned tool_use entry ("Bash(pwd)") IS marked entry-tool-use-only - it has no other content, so hiding tool calls must hide the whole entry too'
    );
    // The canned "done" tool_result entry carries an image (a screenshot,
    // in practice) - found live: it was still getting entry-tool-result-only
    // (and so vanishing entirely under "hide tool details") even after
    // images were made to always show, since that marker didn't originally
    // account for an attached image at all.
    assert_true(
        preg_match('/<div class="rounded-lg border ([^"]*)">(?:(?!<div class="rounded-lg border).)*?<img src="data:image\/png;base64,' . preg_quote(CANNED_TEST_IMAGE_BASE64, '/') . '"[^>]*class="transcript-image/s', $result['body'], $imageEntryMatch) === 1
            && strpos($imageEntryMatch[1], 'entry-tool-result-only') === false,
        'GET /session.php: a tool_result entry carrying an image is NOT marked entry-tool-result-only - the image must always stay visible, so the whole entry must never be hidden'
    );
    assert_true(
        isset($imageEntryMatch[1]) && strpos($imageEntryMatch[1], 'entry-tool-use-only') === false,
        'GET /session.php: a tool_result entry carrying an image is NOT marked entry-tool-use-only either - it is not a tool_use entry at all'
    );
    assert_contains('id="sidebar-list"', $result['body'], 'GET /session.php: sidebar (other sessions) drawer present');
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
    assert_equal(6, count($historyBody['entries'] ?? []), 'GET /session_history.php: canned entries passed through');

    // --- session.php: compose bar present for a real session ---
    $result = curl_request('GET', "{$baseUrl}/session.php?session=cc-20260101-1200");
    assert_contains('id="compose-bar"', $result['body'], 'GET /session.php: message compose bar present');
    assert_contains('id="compose-textarea"', $result['body'], 'GET /session.php: compose textarea present');
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

    // --- cross-origin POST rejected ---
    $result = curl_request('POST', "{$baseUrl}/", [
        '-H', 'Origin: http://evil.example',
        '-d', 'action=cleanup',
    ]);
    assert_equal(403, $result['status'], 'POST with mismatched Origin: 403');

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
