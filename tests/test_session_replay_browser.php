<?php
declare(strict_types=1);

/**
 * "Replay" testing flow, Tier 2: the browser-interaction half of
 * test_session_replay.php. Boots the exact same fixture stack (real tmux
 * pane growing a real transcript file, real host-agent/agent.php over a
 * real socket, `php -S` serving public/) but drives it with an actual
 * headless Chrome tab over a hand-rolled Chrome DevTools Protocol client
 * (tests/lib/cdp.php) instead of curl - so this is the app's OWN
 * client-side JS (session.js's real poll loop, real fetch() calls, real
 * CSRF-token reading, a real DOM click dispatching a real `submit` event)
 * doing the work, not this test calling the backend directly. That's the
 * part curl-only testing structurally can't cover; test_session_replay.php
 * already covers the backend endpoints themselves in detail.
 *
 * Best-effort like test_ui_smoke.php's own headless-Chrome tier: SKIPs
 * (exit 0) rather than failing the suite if no usable Chrome is on this
 * host.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/replay_fixture.php';
require __DIR__ . '/lib/cdp.php';

const REAL_TMUX_SOCKET_REPLAY_BROWSER = '/tmp/tmux-1000/default';

if (getenv('TMUX_SOCKET') === REAL_TMUX_SOCKET_REPLAY_BROWSER || getenv('TMUX_SOCKET') === false) {
    fwrite(STDERR, "REFUSING TO RUN: TMUX_SOCKET resolves to the real host socket (or is unset). Check tests/.env.testing.\n");
    exit(1);
}

/**
 * Polls $check (a zero-arg callable returning bool) every 300ms until it
 * returns true or $timeoutSeconds elapses - the client's own poll loop
 * (session.js) runs on its own timer (seeded to the fastest allowed
 * interval below), so DOM updates land asynchronously, not the instant a
 * transcript line/pane change is made.
 */
function browser_wait_until(callable $check, float $timeoutSeconds = 4.0): bool
{
    $deadline = microtime(true) + $timeoutSeconds;

    do {
        if ($check()) {
            return true;
        }
        usleep(300000);
    } while (microtime(true) < $deadline);

    return $check();
}

/**
 * CSM_TEST_HEADED=1 only (tests/run.sh's --headed) - injects a small
 * heads-up panel (current step, last step's result, Run/Pause + Next
 * buttons) into the page. A no-op if it's already there (this gets
 * called again after every real navigation, since a navigation wipes all
 * injected DOM/JS state, including window.__csmReplay). Client-side
 * state lives entirely on window.__csmReplay = {mode, advance} -
 * control_panel_wait_for_go() below is the only thing that reads it.
 */
function inject_control_panel(array &$page): void
{
    $js = <<<'JS'
    (function () {
        if (document.getElementById('csm-replay-panel')) {
            return;
        }

        var panel = document.createElement('div');
        panel.id = 'csm-replay-panel';
        panel.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:2147483647;'
            + 'background:#111;color:#eee;font:12px/1.6 monospace;padding:8px 12px;'
            + 'border-bottom:2px solid #444;';
        panel.innerHTML =
            '<div id="csm-replay-current">Current: (starting...)</div>'
            + '<div id="csm-replay-last">Last: (none yet)</div>'
            + '<button id="csm-replay-run-pause" type="button" style="margin-top:4px;margin-right:6px;">Run</button>'
            + '<button id="csm-replay-next" type="button">Next</button>';
        document.body.appendChild(panel);

        window.__csmReplay = { mode: 'paused', advance: false };

        document.getElementById('csm-replay-run-pause').addEventListener('click', function () {
            if (window.__csmReplay.mode === 'running') {
                window.__csmReplay.mode = 'paused';
                this.textContent = 'Run';
            } else {
                window.__csmReplay.mode = 'running';
                this.textContent = 'Pause';
            }
        });

        document.getElementById('csm-replay-next').addEventListener('click', function () {
            window.__csmReplay.advance = true;
        });
    })();
    JS;

    cdp_evaluate($page, $js);
}

function control_panel_set_current(array &$page, string $text): void
{
    $textJs = json_encode('Current: ' . $text);
    cdp_evaluate($page, "var __e = document.getElementById('csm-replay-current'); if (__e) { __e.textContent = {$textJs}; }");
}

function control_panel_set_last(array &$page, string $text, bool $passed): void
{
    $textJs = json_encode('Last: ' . ($passed ? 'PASS' : 'FAIL') . ' - ' . $text);
    $colorJs = json_encode($passed ? '#8f8' : '#f88');
    cdp_evaluate($page, "var __e = document.getElementById('csm-replay-last'); if (__e) { __e.textContent = {$textJs}; __e.style.color = {$colorJs}; }");
}

/**
 * Blocks - no overall deadline, a human may take any amount of time -
 * until the panel says "go": Run clicked (mode === 'running', so every
 * subsequent call returns immediately too, already free-running) or Next
 * clicked (advance === true, consumed here by resetting it to false so a
 * second call doesn't also immediately advance). Returns false instead of
 * polling forever if the page/window seems to be gone (5 consecutive
 * failed evaluate() calls) - e.g. the human closed the browser window
 * while paused.
 */
function control_panel_wait_for_go(array &$page): bool
{
    $deadStrikes = 0;

    while (true) {
        $raw = cdp_evaluate($page, 'window.__csmReplay ? JSON.stringify(window.__csmReplay) : null');

        if (!is_string($raw)) {
            $deadStrikes++;

            if ($deadStrikes >= 5) {
                return false;
            }

            usleep(300000);
            continue;
        }

        $deadStrikes = 0;
        $state = json_decode($raw, true);

        if (($state['mode'] ?? 'paused') === 'running') {
            return true;
        }

        if (($state['advance'] ?? false) === true) {
            cdp_evaluate($page, 'window.__csmReplay.advance = false;');
            return true;
        }

        usleep(200000);
    }
}

// PID-suffixed (unique per run - nothing to orphan/collide across runs),
// deliberately NOT removed in this file's own `finally` cleanup below -
// the entire point is a human can inspect it after a failing run. Only
// ever grows on an actual FAILURE (browser_assert() below), so a normal
// green run leaves nothing here at all.
$debugDir = sys_get_temp_dir() . '/csm-test-replay-browser-failures-' . getmypid();
$debugCounter = 0;

/**
 * assert_true(), plus a screenshot + full-page HTML dump on failure -
 * assert_true()/assert_equal() alone (used everywhere else in this suite)
 * only ever print a PASS/FAIL line, which is enough when the assertion is
 * itself the whole story (a JSON field matched or didn't). A DOM/rendering
 * assertion failing needs more than that to actually debug, so this is
 * scoped to test_session_replay_browser.php only - the other 11 test
 * files' pass/fail behavior (tests/lib/assert.php) is untouched.
 */
function browser_assert(array &$page, bool $condition, string $message, string $label): void
{
    global $debugDir, $debugCounter;

    if (!$condition) {
        $debugCounter++;
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($label));
        $base = "{$debugDir}/{$debugCounter}-{$slug}";

        @mkdir($debugDir, 0700, true);

        $png = cdp_screenshot($page);
        if ($png !== null) {
            file_put_contents("{$base}.png", $png);
            echo "    debug screenshot: {$base}.png\n";
        }

        $html = cdp_evaluate($page, 'document.documentElement.outerHTML');
        if (is_string($html)) {
            file_put_contents("{$base}.html", $html);
            echo "    debug html: {$base}.html\n";
        }
    }

    assert_true($condition, $message);
}

$workdir = getenv('WWW_ROOT') . '/project-a';
$ctx = replay_setup('full-session', $workdir);

$agentSocket = sys_get_temp_dir() . '/csm-test-replay-browser-agent.sock';
$agentHarness = start_harness(['php', dirname(__DIR__) . '/host-agent/agent.php'], $agentSocket);

$port = 18198;
$baseUrl = "http://127.0.0.1:{$port}";

$serverEnv = array_merge(getenv(), ['CSM_AGENT_SOCKET' => $agentSocket]);
$serverProcess = proc_open(
    [
        'php', '-S', "127.0.0.1:{$port}",
        '-t', dirname(__DIR__) . '/public',
        dirname(__DIR__) . '/public/index.php',
    ],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $serverPipes,
    null,
    $serverEnv
);

if (!is_resource($serverProcess)) {
    fwrite(STDERR, "replay browser: failed to start php -S\n");
    stop_harness($agentHarness, $agentSocket);
    replay_teardown($ctx);
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
    fwrite(STDERR, "replay browser: server on port {$port} never became ready\n");
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    stop_harness($agentHarness, $agentSocket);
    replay_teardown($ctx);
    exit(1);
}

$browser = null;
$page = null;

try {
    $chromeBin = cdp_find_chrome();

    if ($chromeBin === null) {
        echo "  SKIP: no headless browser found (checked google-chrome-stable/google-chrome/chromium/chromium-browser) - this test file has nothing else to check\n";
    } else {
        $browser = cdp_launch($chromeBin);
        $page = $browser !== null ? cdp_open_page($browser) : null;
    }

    if ($page !== null) {
        // CSM_TEST_HEADED=1 (tests/run.sh's --headed) already told
        // cdp_launch() to open a real visible window instead of a
        // headless one - this additionally injects an on-page control
        // panel (see inject_control_panel() above) and makes the
        // per-step loop below PAUSE before every step by default, with
        // Run/Pause + Next buttons in the panel to control it. Still
        // fully automated end to end in the sense that nothing here
        // fakes/skips a real human click - it just waits for one instead
        // of a fixed timer.
        $headed = getenv('CSM_TEST_HEADED') === '1';

        if ($headed) {
            echo "  (headed mode - a real browser window should now be visible, paused before the first step; use its Run/Next buttons)\n";
        }

        $sessionName = $ctx['session_name'];
        $sessionUrl = "{$baseUrl}/session.php?session=" . urlencode($sessionName);

        // Explicit desktop size (1280x800) - previously relied on
        // whatever chrome's default headless window happened to be,
        // never actually asserted. The whole interactive walkthrough
        // below runs under this size; that IS this test's desktop
        // coverage. A dedicated mobile-viewport pass happens once at the
        // very end, after the scenario finishes (see bottom of this file).
        assert_true(cdp_set_viewport($page, 1280, 800, 1.0, false), 'cdp: desktop viewport (1280x800) set');

        // localStorage is per-origin - one throwaway navigation first so
        // the seeded poll-interval/confirm-toggle values are already in
        // place for THIS origin before session.js's own script (which
        // reads them once, synchronously, at load) runs on the real page.
        assert_true(cdp_navigate($page, "{$baseUrl}/"), 'cdp: initial navigation to establish origin succeeds');
        cdp_evaluate($page, "window.localStorage.setItem('csm-poll-interval-ms','1000'); window.localStorage.setItem('csm-confirm-before-answer','0');");

        assert_true(cdp_navigate($page, $sessionUrl), 'cdp: navigation to the replay fixture session.php succeeds');
        browser_assert($page, cdp_evaluate($page, "document.getElementById('compose-bar') !== null") === true, 'session.php: renders the compose bar', 'compose-bar-missing');
        browser_assert($page, cdp_evaluate($page, "document.getElementById('blocked-prompt-section') !== null") === true, 'session.php: renders the blocked-prompt section container', 'blocked-section-missing');

        // Data-driven off each step's own JSON (tests/fixtures/replay/
        // full-session.replay.json) rather than a hardcoded step index -
        // lets the scenario grow (more kinds, more prompts) without this
        // loop needing to change at all.
        $stepCount = count($ctx['scenario']['steps']);
        // Set once control_panel_wait_for_go() reports the window/page is
        // gone - every check after the loop below also needs $page, so
        // they're all skipped once this is true rather than each one
        // separately failing against a dead connection (found live: left
        // unguarded, this produced a cascade of confusing unrelated FAILs
        // - mobile viewport, compose bar, etc. - on top of the one real
        // "window closed" event, plus a Broken pipe notice per attempt).
        $browserClosed = false;

        if ($headed) {
            inject_control_panel($page);
        }

        for ($i = 0; $i < $stepCount; $i++) {
            $stepLabel = $ctx['scenario']['steps'][$i]['label'] ?? "Step {$i}";

            if ($headed) {
                control_panel_set_current($page, sprintf('Step %d/%d - %s', $i + 1, $stepCount, $stepLabel));

                if (!control_panel_wait_for_go($page)) {
                    echo "  (headed mode: the browser window appears to be closed - stopping the replay here)\n";
                    $browserClosed = true;
                    break;
                }
            }

            $failuresBeforeStep = $GLOBALS['__csm_test_failures'];

            // A single transcript LINE can render more than one DOM block
            // sharing the same data-line (e.g. an assistant message with
            // both a text block and an image block) - a running "+1 per
            // step" total would drift the moment that happens, so the
            // meta-only check below compares THIS step's own before/after
            // snapshot instead of any accumulated count.
            $countBefore = cdp_evaluate($page, "document.querySelectorAll('[data-line]').length");

            $advanced = replay_step($ctx);
            assert_true($advanced !== null, "replay_step: step {$i} advances (scenario has a matching line+step pair)");

            if ($advanced === null) {
                continue;
            }

            $expectRender = $advanced['step']['expect_render'] ?? true;

            if ($expectRender) {
                $lineSelector = json_encode('[data-line="' . $advanced['line_number'] . '"]');
                $sawLine = browser_wait_until(function () use ($page, $lineSelector) {
                    return cdp_evaluate($page, "document.querySelector({$lineSelector}) !== null") === true;
                });
                browser_assert($page, $sawLine, "session.php (step {$i}): the client's own poll picks up transcript line {$advanced['line_number']} and renders it (data-line)", "step-{$i}-missing-data-line");
            } else {
                // A meta-only line (e.g. "permission-mode" noise) - give
                // the poll a beat to (not) do anything, then confirm the
                // rendered entry count genuinely didn't move from this
                // step's own starting point, proving the noise line stayed
                // invisible client-side too, not just server-side
                // (test_session_replay.php already covers the server side
                // of this).
                usleep(1500000);
                $countAfter = cdp_evaluate($page, "document.querySelectorAll('[data-line]').length");
                browser_assert($page, $countAfter === $countBefore, "session.php (step {$i}): a meta-only transcript line renders zero new entries", "step-{$i}-meta-leaked");
            }

            $blockedPrompt = $advanced['step']['blocked_prompt'] ?? null;

            if ($blockedPrompt !== null) {
                $question = $blockedPrompt['question'];
                $questionJs = json_encode($question);
                $sawPrompt = browser_wait_until(function () use ($page, $questionJs) {
                    $text = cdp_evaluate($page, "document.getElementById('blocked-prompt-section').textContent");
                    return is_string($text) && str_contains($text, json_decode($questionJs));
                });
                browser_assert($page, $sawPrompt, "session.php (step {$i}): \"{$question}\" renders in the DOM via the client's own poll", "step-{$i}-prompt-missing");

                $option = (int)$blockedPrompt['answer']['option'];
                $mode = $blockedPrompt['answer']['mode'];

                if ($mode === 'freetext') {
                    $text = (string)$blockedPrompt['answer']['text'];
                    $revealed = cdp_click($page, ".reveal-freetext-btn[data-option=\"{$option}\"]");
                    browser_assert($page, $revealed, "session.php (step {$i}): the \"Type something.\" option reveals the free-text field", "step-{$i}-freetext-reveal-failed");

                    cdp_evaluate($page, 'document.querySelector(".freetext-reply-textarea").value = ' . json_encode($text) . ';');
                    $clicked = cdp_click($page, '.freetext-reply-send-btn');
                    browser_assert($page, $clicked, "session.php (step {$i}): the free-text Send button is found and clicked", "step-{$i}-freetext-send-failed");

                    // answer_prompt_with_text() sends the option digit,
                    // then pastes the reply text, then Enter - all before
                    // any Enter, so cat's canonical-mode line buffering
                    // only ever echoes it back as ONE completed line once
                    // Enter finally lands: "<option><text>", no separator.
                    $expectedPane = $option . $text;
                    $sentToPane = browser_wait_until(function () use ($ctx, $expectedPane) {
                        return str_ends_with(trim(replay_capture_pane($ctx['session_name'])), $expectedPane);
                    });
                    browser_assert($page, $sentToPane, "session.php (step {$i}): the free-text submit reached /answer_prompt.php and sent \"{$expectedPane}\" into the fixture pane", "step-{$i}-freetext-not-sent");
                } else {
                    $clicked = cdp_click($page, '#blocked-prompt-section form[data-confirm-label] button[type="submit"]');
                    browser_assert($page, $clicked, "session.php (step {$i}): the option {$option} button is found and clicked", "step-{$i}-option-click-failed");

                    $sentToPane = browser_wait_until(function () use ($ctx, $option) {
                        return str_ends_with(trim(replay_capture_pane($ctx['session_name'])), (string)$option);
                    });
                    browser_assert($page, $sentToPane, "session.php (step {$i}): the click's real submit reached /answer_prompt.php and sent option {$option} into the fixture pane", "step-{$i}-option-not-sent");
                }
            } else {
                // .prompt-options-wrapper (src/partials/blocked-prompt/
                // options.php) is the definitive DOM signal of an active,
                // answerable prompt - checked directly rather than
                // textContent emptiness, since #blocked-prompt-section can
                // legitimately render other, non-blocking content.
                $hasPrompt = cdp_evaluate($page, "document.querySelector('#blocked-prompt-section .prompt-options-wrapper') !== null");
                browser_assert(
                    $page,
                    $hasPrompt === false,
                    "session.php (step {$i}): no blocked prompt showing in the DOM",
                    "step-{$i}-unexpected-prompt"
                );
            }

            if ($headed) {
                $stepPassed = $GLOBALS['__csm_test_failures'] === $failuresBeforeStep;
                control_panel_set_last($page, $stepLabel, $stepPassed);
            }
        }

        // Everything below needs a live $page - skipped entirely once the
        // window/page is known gone (see $browserClosed above), rather
        // than letting each of these fail separately against a dead
        // connection.
        if (!$browserClosed) {
            browser_assert(
                $page,
                !str_contains((string)cdp_evaluate($page, 'document.body.innerHTML'), 'Uncaught'),
                'session.php: no uncaught-error text leaked into the rendered page after the full replay',
                'uncaught-error-leaked'
            );

            // Known-correct target: every step whose own JSON doesn't say
            // "expect_render": false. Compared against a WAIT-until-reached
            // live count below, not a second live snapshot taken elsewhere -
            // two live DOM snapshots can legitimately differ for a moment
            // even on a fully-working page (a poll cycle briefly reconciling
            // the list mid-render), so the stable, known-ahead-of-time number
            // from the fixture itself is the right thing to compare against.
            $expectedEntryLines = 0;
            foreach ($ctx['scenario']['steps'] as $step) {
                if ($step['expect_render'] ?? true) {
                    $expectedEntryLines++;
                }
            }

            if ($headed) {
                usleep(2000000);
            }

            // One extra pass at a phone-sized viewport, reusing the SAME
            // already-fully-populated session (server-side state persists
            // across a reload - nothing needs re-answering) - proves the
            // final rendered state holds up responsively, not that any JS
            // logic differs by viewport (session.js has no viewport-
            // conditional branching to begin with). In headed mode this is a
            // real DevTools-style viewport emulation within the same OS
            // window (like the devtools device toolbar) - the window itself
            // doesn't resize, only the page's own rendering area does.
            assert_true(cdp_set_viewport($page, 390, 844, 2.0, true), 'cdp: mobile viewport (390x844) set');
            assert_true(cdp_navigate($page, $sessionUrl), 'cdp: re-navigation at mobile viewport succeeds');

            // The navigation above wiped the previously-injected panel (a
            // full page load resets all DOM/JS state) - re-injected here
            // purely so it's still visible during this pass, not gated
            // behind control_panel_wait_for_go(): the mobile/desktop
            // viewport checks are fixed setup/teardown assertions, not
            // scenario steps, so they keep running automatically
            // regardless of Run/Pause/Next.
            if ($headed) {
                inject_control_panel($page);
                control_panel_set_current($page, 'Mobile viewport check (390x844)');
            }

            $sawExpectedCount = browser_wait_until(function () use ($page, $expectedEntryLines) {
                return cdp_evaluate($page, "document.querySelectorAll('[data-line]').length") === $expectedEntryLines;
            });
            browser_assert($page, $sawExpectedCount, "session.php (mobile viewport): renders all {$expectedEntryLines} expected transcript entries", 'mobile-entry-count-mismatch');

            $noOverflow = cdp_evaluate($page, 'document.documentElement.scrollWidth <= document.documentElement.clientWidth');
            browser_assert($page, $noOverflow === true, 'session.php (mobile viewport): no horizontal overflow', 'mobile-horizontal-overflow');

            browser_assert($page, cdp_evaluate($page, "document.getElementById('compose-bar') !== null") === true, 'session.php (mobile viewport): still renders the compose bar', 'mobile-compose-bar-missing');

            if ($headed) {
                usleep(2000000);
            }
        }
    }
} finally {
    if ($page !== null) {
        cdp_close_page($page);
    }
    if ($browser !== null) {
        cdp_shutdown($browser);
    }
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    stop_harness($agentHarness, $agentSocket);
    replay_teardown($ctx);
}

test_exit();
