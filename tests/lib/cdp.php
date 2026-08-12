<?php
declare(strict_types=1);

/**
 * A minimal, hand-rolled Chrome DevTools Protocol client - no Node/
 * Playwright/Puppeteer dependency (this repo has none, deliberately - see
 * CLAUDE.md), just `google-chrome-stable` (already required for
 * test_ui_smoke.php's own best-effort headless checks) plus PHP's raw
 * stream sockets. Exists because test_ui_smoke.php's own
 * `headless_dump_dom()` is a ONE-SHOT `chrome --dump-dom` - it can render
 * and dump a page, but can't click a button or type into a field. This
 * gives test_session_replay_browser.php exactly enough to do that:
 * navigate, evaluate JS (used for both reading rendered state and driving
 * clicks/typing via direct DOM calls rather than synthesized input events -
 * see cdp_click()/cdp_navigate()'s own doc comments for why that's enough
 * here), nothing else. No Target-domain multiplexing, no event
 * subscriptions - every page opened gets its own single WebSocket
 * connection (via Chrome's HTTP `/json/new` endpoint, which hands back
 * that one page's own `webSocketDebuggerUrl` directly), and "did the page
 * finish loading" is answered by polling `document.readyState` rather than
 * listening for `Page.loadEventFired` - both deliberately the simplest
 * thing that works for one page, one caller, fully serial.
 *
 * Same "best-effort, skip gracefully if unavailable" convention as
 * test_ui_smoke.php's find_headless_browser()/headless_dump_dom(): every
 * function here that can fail (chrome missing, the WS handshake failing,
 * a call timing out) echoes a SKIP line and returns null rather than
 * throwing - a host with no browser installed, or a chrome version with
 * different endpoint behavior, must never fail the suite outright.
 */

function cdp_find_chrome(): ?string
{
    foreach (['/usr/bin/google-chrome-stable', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * @return array{process:resource, port:int, user_data_dir:string}|null
 */
function cdp_launch(string $chromeBin): ?array
{
    // --remote-debugging-port=0 (OS-assigned) + a per-process-unique
    // --user-data-dir means nothing here is a fixed/reused resource path -
    // unlike tests/lib/socket_harness.php's fixed socket, there's no
    // stale-listener case to guard against on launch.
    $userDataDir = sys_get_temp_dir() . '/csm-test-cdp-profile-' . getmypid();
    @mkdir($userDataDir, 0700, true);

    $cmd = [
        $chromeBin, '--headless=new', '--disable-gpu', '--no-sandbox',
        // --disable-crash-reporter: chrome's crashpad handler deliberately
        // detaches from the main chrome process (so it survives to report
        // a crash even as chrome itself dies) - found live: that means
        // proc_terminate() on the main process alone leaves it running,
        // orphaned, forever. Same accumulating-orphan shape as the
        // tmux-socket-harness incident this project's CLAUDE.md already
        // documents; this flag stops it from being spawned at all rather
        // than trying to hunt it down after the fact.
        '--disable-crash-reporter',
        '--remote-debugging-port=0', '--user-data-dir=' . $userDataDir, 'about:blank',
    ];
    $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        echo "  SKIP: chrome found at {$chromeBin} but failed to launch\n";
        cdp_rmdir_recursive($userDataDir);
        return null;
    }

    fclose($pipes[0]);

    $portFile = $userDataDir . '/DevToolsActivePort';
    $deadline = microtime(true) + 5.0;

    while (!file_exists($portFile)) {
        if (microtime(true) > $deadline) {
            echo "  SKIP: chrome never wrote {$portFile} (remote-debugging-port) within 5s\n";
            proc_terminate($process);
            proc_close($process);
            cdp_rmdir_recursive($userDataDir);
            return null;
        }
        usleep(20000);
    }

    $lines = file($portFile, FILE_IGNORE_NEW_LINES);
    $port = (int)($lines[0] ?? 0);

    if ($port <= 0) {
        echo "  SKIP: DevToolsActivePort file did not contain a usable port\n";
        proc_terminate($process);
        proc_close($process);
        cdp_rmdir_recursive($userDataDir);
        return null;
    }

    return ['process' => $process, 'port' => $port, 'user_data_dir' => $userDataDir];
}

function cdp_shutdown(array $browser): void
{
    proc_terminate($browser['process']);
    proc_close($browser['process']);
    cdp_rmdir_recursive($browser['user_data_dir']);
}

function cdp_rmdir_recursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = "{$dir}/{$entry}";
        is_dir($path) ? cdp_rmdir_recursive($path) : @unlink($path);
    }

    @rmdir($dir);
}

/**
 * @return array{status:int, body:string}
 */
function cdp_http(string $method, string $url): array
{
    $process = proc_open(['curl', '-s', '-o', '-', '-w', '\n%{http_code}', '-X', $method, $url], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        return ['status' => 0, 'body' => ''];
    }

    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $lastNewline = strrpos($out, "\n");

    if ($lastNewline === false) {
        return ['status' => 0, 'body' => $out];
    }

    return ['status' => (int)substr($out, $lastNewline + 1), 'body' => substr($out, 0, $lastNewline)];
}

/**
 * Opens a fresh tab via Chrome's HTTP `/json/new` endpoint (a PUT, not GET -
 * newer Chrome rejects GET here, a CSRF hardening change verified live
 * against Chrome 151) and connects directly to that tab's OWN
 * `webSocketDebuggerUrl`, no Target.createTarget/attachToTarget dance
 * needed - every CDP command sent on this connection already targets this
 * one page.
 *
 * @return array{sock:resource, next_id:int}|null
 */
function cdp_open_page(array $browser, string $url = 'about:blank'): ?array
{
    $created = cdp_http('PUT', "http://127.0.0.1:{$browser['port']}/json/new?" . rawurlencode($url));
    $target = json_decode($created['body'], true);
    $wsUrl = is_array($target) ? ($target['webSocketDebuggerUrl'] ?? null) : null;

    if (!is_string($wsUrl)) {
        echo "  SKIP: chrome /json/new did not return a webSocketDebuggerUrl (got: {$created['body']})\n";
        return null;
    }

    $parts = parse_url($wsUrl);
    $sock = @stream_socket_client("tcp://{$parts['host']}:{$parts['port']}", $errno, $errstr, 5);

    if ($sock === false) {
        echo "  SKIP: could not connect to chrome's devtools websocket ({$errstr})\n";
        return null;
    }

    $key = base64_encode(random_bytes(16));
    $handshakeRequest = "GET {$parts['path']} HTTP/1.1\r\n"
        . "Host: {$parts['host']}:{$parts['port']}\r\n"
        . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n";
    fwrite($sock, $handshakeRequest);

    stream_set_timeout($sock, 5);
    $response = '';

    while (!str_contains($response, "\r\n\r\n")) {
        $byte = fread($sock, 1);

        if ($byte === false || $byte === '') {
            echo "  SKIP: chrome's websocket handshake response never completed\n";
            fclose($sock);
            return null;
        }

        $response .= $byte;
    }

    if (!str_contains($response, '101')) {
        echo "  SKIP: chrome's websocket handshake did not return 101 (got: " . trim($response) . ")\n";
        fclose($sock);
        return null;
    }

    return ['sock' => $sock, 'next_id' => 1];
}

function cdp_close_page(array $page): void
{
    fclose($page['sock']);
}

/**
 * RFC 6455 client->server text frame - client frames MUST be masked.
 */
function cdp_ws_send(mixed $sock, string $payload): void
{
    $len = strlen($payload);
    $mask = random_bytes(4);
    $frame = chr(0x81); // FIN + opcode 0x1 (text)

    if ($len <= 125) {
        $frame .= chr($len | 0x80);
    } elseif ($len <= 0xFFFF) {
        $frame .= chr(126 | 0x80) . pack('n', $len);
    } else {
        $frame .= chr(127 | 0x80) . pack('J', $len);
    }

    $frame .= $mask;

    for ($i = 0; $i < $len; $i++) {
        $frame .= $payload[$i] ^ $mask[$i % 4];
    }

    fwrite($sock, $frame);
}

/**
 * Reads exactly one WebSocket frame's payload (server->client frames are
 * never masked). Only handles single-frame text messages (FIN=1, opcode
 * 0x1) - every CDP command/event response fits in one frame in practice
 * for what this client sends/receives; a continuation frame would just
 * come back null here rather than being silently misparsed.
 */
function cdp_ws_recv(mixed $sock): ?string
{
    $header = fread($sock, 2);

    if ($header === false || strlen($header) < 2) {
        return null;
    }

    $b0 = ord($header[0]);
    $b1 = ord($header[1]);

    if (($b0 & 0x0F) !== 0x1) {
        return null; // not a single-frame text message - not produced by chrome's devtools server for our own calls
    }

    $len = $b1 & 0x7F;

    if ($len === 126) {
        $ext = fread($sock, 2);
        $len = $ext === false ? 0 : unpack('n', $ext)[1];
    } elseif ($len === 127) {
        $ext = fread($sock, 8);
        $len = $ext === false ? 0 : unpack('J', $ext)[1];
    }

    $payload = '';

    while (strlen($payload) < $len) {
        $chunk = fread($sock, $len - strlen($payload));

        if ($chunk === false || $chunk === '') {
            break;
        }

        $payload .= $chunk;
    }

    return $payload;
}

/**
 * Sends one CDP command and blocks (up to $timeout) for ITS OWN response,
 * transparently skipping over any unrelated event frames (e.g.
 * Page.frameStartedLoading) that arrive first - id-matching is what tells
 * a command's response apart from an event notification (events carry no
 * "id" field at all).
 *
 * @param array{sock:resource, next_id:int} $page
 * @return array<string, mixed>|null the command's "result" object, or null on timeout/error
 */
function cdp_call(array &$page, string $method, array $params = [], float $timeout = 5.0): ?array
{
    $id = $page['next_id']++;
    cdp_ws_send($page['sock'], json_encode(['id' => $id, 'method' => $method, 'params' => $params]));

    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $raw = cdp_ws_recv($page['sock']);

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (($decoded['id'] ?? null) === $id) {
            return is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
        }
    }

    return null;
}

/**
 * Navigates, then polls document.readyState instead of subscribing to
 * Page.loadEventFired - simpler for a client with no event-buffering
 * layer, and sufficient here since every page this drives is a small,
 * already-fast local `php -S` response, not a heavy real-world page.
 */
function cdp_navigate(array &$page, string $url, float $timeout = 5.0): bool
{
    if (cdp_call($page, 'Page.navigate', ['url' => $url]) === null) {
        return false;
    }

    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $state = cdp_evaluate($page, 'document.readyState');

        if ($state === 'complete') {
            return true;
        }

        usleep(50000);
    }

    return false;
}

/**
 * Runtime.evaluate wrapper returning the JS value directly (decoded from
 * JSON via returnByValue) instead of CDP's {type, value, description}
 * wrapper - null on any failure (including a thrown JS exception, since no
 * caller here needs to distinguish "evaluated to null" from "failed").
 */
function cdp_evaluate(array &$page, string $expression): mixed
{
    $result = cdp_call($page, 'Runtime.evaluate', ['expression' => $expression, 'returnByValue' => true]);

    if ($result === null || isset($result['exceptionDetails'])) {
        return null;
    }

    return $result['result']['value'] ?? null;
}

/**
 * Clicks the first element matching $selector via the DOM's own .click()
 * rather than synthesizing real mouse events (CDP's Input.dispatchMouseEvent) -
 * simpler, and sufficient here: session.js's answer-prompt handler is a
 * real `submit` event listener on the form (see blocked-prompt/options.php),
 * and a real <button type="submit">'s .click() dispatches that same event
 * exactly as a genuine click would. Returns false if no element matched.
 */
function cdp_click(array &$page, string $selector): bool
{
    $expression = 'var __el = document.querySelector(' . json_encode($selector) . ');'
        . 'if (__el) { __el.click(); true; } else { false; }';

    return cdp_evaluate($page, $expression) === true;
}

/**
 * Raw PNG bytes of the current page, or null on failure - used only for
 * failure diagnostics (see test_session_replay_browser.php's
 * browser_assert()), never asserted on directly, so no pixel comparison
 * lives here.
 */
function cdp_screenshot(array &$page): ?string
{
    $result = cdp_call($page, 'Page.captureScreenshot', ['format' => 'png']);
    $base64 = is_string($result['data'] ?? null) ? $result['data'] : null;

    if ($base64 === null) {
        return null;
    }

    $decoded = base64_decode($base64, true);

    return $decoded === false ? null : $decoded;
}

/**
 * Emulation.setDeviceMetricsOverride - lets a caller check the SAME
 * already-rendered page at a different viewport size (e.g. a phone-sized
 * one) without a real device, by re-navigating after this call.
 * $deviceScaleFactor:0 leaves it at the device default; $mobile also
 * flips the CSS "mobile" media hint chrome reports internally, matching
 * how a real phone browser would present itself.
 */
function cdp_set_viewport(array &$page, int $width, int $height, float $deviceScaleFactor, bool $mobile): bool
{
    return cdp_call($page, 'Emulation.setDeviceMetricsOverride', [
        'width' => $width,
        'height' => $height,
        'deviceScaleFactor' => $deviceScaleFactor,
        'mobile' => $mobile,
    ]) !== null;
}
