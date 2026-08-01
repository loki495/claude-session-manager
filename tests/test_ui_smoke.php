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

$agentSocket = sys_get_temp_dir() . '/csm-test-ui-agent.sock';
$agentHarness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $agentSocket);

$port = 18099;
$authUser = 'testuser';
$authPass = 'testpass';
$baseUrl = "http://127.0.0.1:{$port}";

$serverEnv = array_merge(getenv(), [
    'CSM_AGENT_SOCKET' => $agentSocket,
    'BASIC_AUTH_USER' => $authUser,
    'BASIC_AUTH_PASS' => $authPass,
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
    $authArgs = ['-u', "{$authUser}:{$authPass}"];
    $cookieJar = tempnam(sys_get_temp_dir(), 'csm-test-cookies');

    // --- basic auth enforced ---
    $result = curl_request('GET', "{$baseUrl}/");
    assert_equal(401, $result['status'], 'GET / without auth: 401');

    // --- authed page reflects the canned agent's data ---
    $result = curl_request('GET', "{$baseUrl}/", $authArgs, $cookieJar);
    assert_equal(200, $result['status'], 'GET / with auth: 200');
    assert_contains('Claude Session Manager', $result['body'], 'GET /: page title present');
    assert_contains('1 active', $result['body'], 'GET /: session count from canned agent');
    assert_contains('Fix the login redirect bug', $result['body'], 'GET /: canned pane title shown as the primary label');
    assert_contains('cc-20260101-1200', $result['body'], 'GET /: raw session name still shown (secondary, since a title is present)');
    assert_contains('demo-project', $result['body'], 'GET /: canned workdir rendered');
    assert_contains('detached', $result['body'], 'GET /: canned session shown as detached');
    assert_contains('Bare title', $result['body'], "GET /: canned bare process's tmux pane title shown");
    assert_contains('csm-test-adhoc', $result['body'], "GET /: canned bare process's owning tmux session shown");

    // CSRF token must round-trip through the session (via the cookie jar), not the URL - every
    // POST below extracts it fresh from whatever page it's reacting to.
    $csrfToken = extract_csrf_token($result['body']);
    assert_true($csrfToken !== null, 'GET /: page includes a csrf_token field');

    // --- POST new: redirect + session-based flash (no message in the URL) ---
    $result = curl_request('POST', "{$baseUrl}/", array_merge($authArgs, [
        '-d', 'action=new&csrf_token=' . urlencode((string)$csrfToken) . '&workdir=' . urlencode('/home/andres/www/demo-project'),
    ]), $cookieJar);
    assert_equal(303, $result['status'], 'POST new: 303 redirect');
    assert_equal('/', $result['headers']['location'] ?? '', 'POST new: redirects to / with no message in the URL');

    $follow = curl_request('GET', "{$baseUrl}/", $authArgs, $cookieJar);
    assert_equal(200, $follow['status'], 'POST new -> redirect target: 200');
    assert_contains('Created session', $follow['body'], 'POST new -> redirect target: flash message shown');
    $csrfToken = extract_csrf_token($follow['body']);

    $again = curl_request('GET', "{$baseUrl}/", $authArgs, $cookieJar);
    assert_true(!str_contains($again['body'], 'Created session'), 'GET / again: flash message does not reappear on refresh');

    // --- POST without a valid CSRF token is rejected ---
    $result = curl_request('POST', "{$baseUrl}/", array_merge($authArgs, [
        '-d', 'action=cleanup&csrf_token=not-the-real-token',
    ]), $cookieJar);
    assert_equal(403, $result['status'], 'POST with a wrong csrf_token: 403');

    // --- POST kill: canned agent accepts this exact session name ---
    $result = curl_request('POST', "{$baseUrl}/", array_merge($authArgs, [
        '-d', 'action=kill&csrf_token=' . urlencode((string)$csrfToken) . '&session=cc-20260101-1200',
    ]), $cookieJar);
    assert_equal(303, $result['status'], 'POST kill: 303 redirect');
    $follow = curl_request('GET', "{$baseUrl}/", $authArgs, $cookieJar);
    assert_contains('Killed', $follow['body'], 'POST kill: flash shows success for the canned session name');
    $csrfToken = extract_csrf_token($follow['body']);

    // --- POST kill: canned agent rejects any other name ---
    $result = curl_request('POST', "{$baseUrl}/", array_merge($authArgs, [
        '-d', 'action=kill&csrf_token=' . urlencode((string)$csrfToken) . '&session=cc-not-a-real-session',
    ]), $cookieJar);
    $follow = curl_request('GET', "{$baseUrl}/", $authArgs, $cookieJar);
    assert_contains('Rejected', $follow['body'], 'POST kill: flash shows rejection for an unrecognized session name');
    $csrfToken = extract_csrf_token($follow['body']);

    // --- POST kill_bare: canned agent accepts this exact pid ---
    $result = curl_request('POST', "{$baseUrl}/", array_merge($authArgs, [
        '-d', 'action=kill_bare&csrf_token=' . urlencode((string)$csrfToken) . '&pid=54321',
    ]), $cookieJar);
    assert_equal(303, $result['status'], 'POST kill_bare: 303 redirect');
    $follow = curl_request('GET', "{$baseUrl}/", $authArgs, $cookieJar);
    assert_contains('Killed', $follow['body'], 'POST kill_bare: flash shows success for the canned pid');
    $csrfToken = extract_csrf_token($follow['body']);

    // --- quota.php: auth enforced, same as / ---
    $result = curl_request('GET', "{$baseUrl}/quota.php");
    assert_equal(401, $result['status'], 'GET /quota.php without auth: 401');

    // --- quota.php: authed request passes the canned agent's quota action through as JSON ---
    $result = curl_request('GET', "{$baseUrl}/quota.php", $authArgs);
    assert_equal(200, $result['status'], 'GET /quota.php with auth: 200');
    $quotaBody = json_decode($result['body'], true);
    assert_true(is_array($quotaBody) && ($quotaBody['ok'] ?? false), 'GET /quota.php: response decodes as ok=true JSON');
    assert_equal(73, $quotaBody['quota']['session']['pct'] ?? null, 'GET /quota.php: canned session percentage passed through');

    // --- browse.php: auth enforced, same as / ---
    $result = curl_request('GET', "{$baseUrl}/browse.php");
    assert_equal(401, $result['status'], 'GET /browse.php without auth: 401');

    // --- browse.php: authed request passes the canned agent's browse_dir action through as JSON ---
    $result = curl_request('GET', "{$baseUrl}/browse.php?path=" . urlencode('/home/andres/www'), $authArgs);
    assert_equal(200, $result['status'], 'GET /browse.php with auth: 200');
    $browseBody = json_decode($result['body'], true);
    assert_true(is_array($browseBody) && ($browseBody['ok'] ?? false), 'GET /browse.php: response decodes as ok=true JSON');
    assert_equal(['project-a', 'project-b'], $browseBody['dirs'] ?? null, 'GET /browse.php: canned dirs passed through');

    // --- session.php: auth enforced, same as / ---
    $result = curl_request('GET', "{$baseUrl}/session.php?session=cc-20260101-1200");
    assert_equal(401, $result['status'], 'GET /session.php without auth: 401');

    // --- session.php: no ?session -> redirects home rather than erroring ---
    $result = curl_request('GET', "{$baseUrl}/session.php", $authArgs);
    assert_equal(303, $result['status'], 'GET /session.php with no session param: 303 redirect');
    assert_equal('/', $result['headers']['location'] ?? '', 'GET /session.php with no session param: redirects to /');

    // --- session.php: authed request renders the canned session's detail + history ---
    $result = curl_request('GET', "{$baseUrl}/session.php?session=cc-20260101-1200", $authArgs, $cookieJar);
    assert_equal(200, $result['status'], 'GET /session.php with auth: 200');
    assert_contains('Fix the login redirect bug', $result['body'], 'GET /session.php: canned title shown');
    assert_contains('demo-project', $result['body'], 'GET /session.php: canned workdir shown');
    assert_contains('Looking into it now.', $result['body'], 'GET /session.php: canned history entry rendered');
    assert_contains('Load older messages', $result['body'], 'GET /session.php: load-more button shown when has_more=true');
    assert_contains('Do you want to proceed?', $result['body'], 'GET /session.php: canned blocked_reason shown');
    assert_contains('1. Yes', $result['body'], 'GET /session.php: real option button rendered (not just the copy-paste tip)');
    assert_contains('2. No', $result['body'], 'GET /session.php: second option button rendered');
    assert_contains('rm -rf /tmp/canned-example', $result['body'], 'GET /session.php: prompt_context (the actual command being approved) is shown, not just the bare question');
    $sessionCsrfToken = extract_csrf_token($result['body']);

    // --- POST answer_prompt: canned agent accepts option 1 for the canned session ---
    $result = curl_request('POST', "{$baseUrl}/session.php?session=cc-20260101-1200", array_merge($authArgs, [
        '-d', 'action=answer_prompt&csrf_token=' . urlencode((string)$sessionCsrfToken) . '&session=cc-20260101-1200&option=1',
    ]), $cookieJar);
    assert_equal(303, $result['status'], 'POST answer_prompt: 303 redirect');
    assert_equal('/session.php?session=cc-20260101-1200', $result['headers']['location'] ?? '', 'POST answer_prompt: redirects back to the same session, not home');
    $follow = curl_request('GET', "{$baseUrl}/session.php?session=cc-20260101-1200", $authArgs, $cookieJar);
    assert_contains('Sent option 1', $follow['body'], 'POST answer_prompt: flash shows success for the accepted option');
    $sessionCsrfToken = extract_csrf_token($follow['body']);

    // --- POST answer_prompt: canned agent rejects an option it isn't currently offering ---
    $result = curl_request('POST', "{$baseUrl}/session.php?session=cc-20260101-1200", array_merge($authArgs, [
        '-d', 'action=answer_prompt&csrf_token=' . urlencode((string)$sessionCsrfToken) . '&session=cc-20260101-1200&option=99',
    ]), $cookieJar);
    $follow = curl_request('GET', "{$baseUrl}/session.php?session=cc-20260101-1200", $authArgs, $cookieJar);
    assert_contains('Rejected', $follow['body'], 'POST answer_prompt: flash shows rejection for an option not currently offered');

    // --- session.php: unknown session name renders a "not found" state, not an error page ---
    $result = curl_request('GET', "{$baseUrl}/session.php?session=cc-not-a-real-session", $authArgs);
    assert_equal(200, $result['status'], 'GET /session.php for an unknown session: 200 (not an HTTP error)');
    assert_contains('Session not found', $result['body'], 'GET /session.php for an unknown session: shows a not-found message');

    // --- session_history.php: auth enforced, same as / ---
    $result = curl_request('GET', "{$baseUrl}/session_history.php?session=cc-20260101-1200");
    assert_equal(401, $result['status'], 'GET /session_history.php without auth: 401');

    // --- session_history.php: authed request passes the canned agent's session_history action through as JSON ---
    $result = curl_request('GET', "{$baseUrl}/session_history.php?session=cc-20260101-1200&before=1&limit=30", $authArgs);
    assert_equal(200, $result['status'], 'GET /session_history.php with auth: 200');
    $historyBody = json_decode($result['body'], true);
    assert_true(is_array($historyBody) && ($historyBody['ok'] ?? false), 'GET /session_history.php: response decodes as ok=true JSON');
    assert_equal(2, count($historyBody['entries'] ?? []), 'GET /session_history.php: canned entries passed through');

    // --- cross-origin POST rejected ---
    $result = curl_request('POST', "{$baseUrl}/", array_merge($authArgs, [
        '-H', 'Origin: http://evil.example',
        '-d', 'action=cleanup',
    ]));
    assert_equal(403, $result['status'], 'POST with mismatched Origin: 403');

    // --- optional richer tier: only if a headless browser is already on this host ---
    $browser = find_headless_browser();

    if ($browser === null) {
        echo "  SKIP: no headless browser found (checked google-chrome-stable/google-chrome/chromium/chromium-browser) - curl checks above are the required baseline\n";
    } else {
        run_headless_browser_checks($browser, $authUser, $authPass, $port);
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
function run_headless_browser_checks(string $browser, string $authUser, string $authPass, int $port): void
{
    $base = "http://{$authUser}:{$authPass}@127.0.0.1:{$port}";

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
        echo "  SKIP: headless browser produced no DOM for {$url} (auth-via-URL may not be supported by this browser version)\n";
        return null;
    }

    return ['dom' => $dom, 'stderr' => $stderr];
}
