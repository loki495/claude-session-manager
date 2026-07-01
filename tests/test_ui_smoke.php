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

    // --- basic auth enforced ---
    $result = curl_request('GET', "{$baseUrl}/");
    assert_equal(401, $result['status'], 'GET / without auth: 401');

    // --- authed page reflects the canned agent's data ---
    $result = curl_request('GET', "{$baseUrl}/", $authArgs);
    assert_equal(200, $result['status'], 'GET / with auth: 200');
    assert_contains('Claude Session Manager', $result['body'], 'GET /: page title present');
    assert_contains('1 active', $result['body'], 'GET /: session count from canned agent');
    assert_contains('cc-20260101-1200', $result['body'], 'GET /: canned session name rendered');
    assert_contains('demo-project', $result['body'], 'GET /: canned workdir rendered');
    assert_contains('detached', $result['body'], 'GET /: canned session shown as detached');

    // --- POST new: redirect + flash message ---
    $result = curl_request('POST', "{$baseUrl}/", array_merge($authArgs, [
        '-d', 'action=new&workdir_choice=' . urlencode('/home/andres/www/demo-project'),
    ]));
    assert_equal(303, $result['status'], 'POST new: 303 redirect');
    $location = $result['headers']['location'] ?? '';
    assert_true(str_starts_with($location, '/?msg='), 'POST new: redirects to /?msg=...');
    assert_contains('ok=1', $location, 'POST new: ok=1 in redirect');

    if ($location !== '') {
        $follow = curl_request('GET', $baseUrl . $location, $authArgs);
        assert_equal(200, $follow['status'], 'POST new -> redirect target: 200');
        assert_contains('Created session', $follow['body'], 'POST new -> redirect target: flash message shown');
    }

    // --- POST kill: canned agent accepts this exact session name ---
    $result = curl_request('POST', "{$baseUrl}/", array_merge($authArgs, [
        '-d', 'action=kill&session=cc-20260101-1200',
    ]));
    assert_equal(303, $result['status'], 'POST kill: 303 redirect');
    assert_contains('ok=1', $result['headers']['location'] ?? '', 'POST kill: ok=1 for the canned session name');

    // --- POST kill: canned agent rejects any other name ---
    $result = curl_request('POST', "{$baseUrl}/", array_merge($authArgs, [
        '-d', 'action=kill&session=cc-not-a-real-session',
    ]));
    assert_contains('ok=0', $result['headers']['location'] ?? '', 'POST kill: ok=0 for an unrecognized session name');

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
}

test_exit();

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
 * actually parses in a browser). Does NOT simulate the workdir_choice
 * onchange click - that needs a scriptable devtools protocol client
 * (puppeteer/playwright), which isn't assumed to be installed offline on
 * this host. If that's ever added, it belongs here.
 */
function run_headless_browser_checks(string $browser, string $authUser, string $authPass, int $port): void
{
    $authedUrl = "http://{$authUser}:{$authPass}@127.0.0.1:{$port}/";
    $cmd = [$browser, '--headless=new', '--disable-gpu', '--no-sandbox', '--virtual-time-budget=4000', '--dump-dom', $authedUrl];

    $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        echo "  SKIP: headless browser found at {$browser} but failed to launch\n";
        return;
    }

    fclose($pipes[0]);
    $dom = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if (trim($dom) === '') {
        echo "  SKIP: headless browser produced no DOM (auth-via-URL may not be supported by this browser version)\n";
        return;
    }

    assert_contains('id="workdir_custom"', $dom, 'headless browser: renders the custom workdir input');
    assert_true(!str_contains($stderr, 'Uncaught'), 'headless browser: no uncaught JS errors on load');
}
