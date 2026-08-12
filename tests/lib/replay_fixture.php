<?php
declare(strict_types=1);

/**
 * Shared setup for the session-replay tests (test_session_replay.php,
 * test_session_replay_browser.php): grows a REAL transcript file one line
 * at a time behind a real, isolated tmux pane (`stty -echo; exec cat`,
 * same stand-in used for SessionService::answer_prompt() coverage in
 * test_sessions_lifecycle.php), with each step's tmux pane content driven
 * independently via real `send-keys` - so the app under test always reads
 * genuine tmux_capture_pane()/TranscriptService output, never a stubbed
 * response. Both callers just call replay_setup(), then replay_step() in a
 * loop, then replay_teardown() - identical fixture mechanics regardless of
 * whether the test drives the app over curl (Tier 1) or a real browser via
 * CDP (Tier 2).
 *
 * A scenario is two files under tests/fixtures/replay/:
 *   <name>.jsonl        - the real transcript, one JSON line per entry.
 *                          Line 1 is written up front by replay_setup();
 *                          each subsequent line is appended by one
 *                          replay_step() call.
 *   <name>.replay.json  - {"steps": [...]}, in order - each entry's optional
 *                          "pane_text" is the lines of tmux pane content
 *                          (send-keys, one call per line) that should be
 *                          showing once this step has landed; "clear_before"
 *                          (bool) sends a real ANSI clear-screen first,
 *                          simulating Claude Code's own full-screen repaint
 *                          - the mechanism this fixture uses to make an
 *                          earlier blocked-prompt's text actually leave the
 *                          pane's CURRENTLY VISIBLE content (all
 *                          tmux_capture_pane() ever reads), the same way a
 *                          real redraw would. "append_line" (bool, default
 *                          true) - false for a step that only redraws the
 *                          pane without a new transcript line landing (e.g.
 *                          the second+ question of one multi-question
 *                          AskUserQuestion call - Claude Code only ever
 *                          writes ONE combined tool_result once every
 *                          question is answered, never one line per
 *                          question, so a step representing "the CLI
 *                          redrew to the next question" has no transcript
 *                          content of its own to append). Every
 *                          "append_line" !== false step consumes exactly
 *                          one line from <name>.jsonl, in order, after the
 *                          seed line - replay_load_scenario() checks this
 *                          count matches, not a raw step-count match.
 */

/**
 * @return array{seed_line:string, lines:string[], steps:array<int, array{pane_text:?array<int,string>, clear_before:bool}>}
 */
function replay_load_scenario(string $name): array
{
    $dir = __DIR__ . '/../fixtures/replay';
    $jsonlPath = "{$dir}/{$name}.jsonl";
    $stepsPath = "{$dir}/{$name}.replay.json";

    $rawLines = file($jsonlPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($rawLines === false || count($rawLines) < 2) {
        fwrite(STDERR, "replay_load_scenario: {$jsonlPath} missing or has fewer than 2 lines\n");
        exit(1);
    }

    $decodedSteps = json_decode((string)file_get_contents($stepsPath), true);
    $steps = is_array($decodedSteps['steps'] ?? null) ? $decodedSteps['steps'] : null;

    if ($steps === null) {
        fwrite(STDERR, "replay_load_scenario: {$stepsPath} missing or malformed\n");
        exit(1);
    }

    $seedLine = array_shift($rawLines);
    $appendingSteps = array_filter($steps, static fn(array $step): bool => $step['append_line'] ?? true);

    if (count($appendingSteps) !== count($rawLines)) {
        fwrite(STDERR, sprintf(
            "replay_load_scenario: %s has %d step(s) with append_line !== false but %s has %d line(s) after the seed line - must match 1:1\n",
            $stepsPath,
            count($appendingSteps),
            $jsonlPath,
            count($rawLines)
        ));
        exit(1);
    }

    return ['seed_line' => $seedLine, 'lines' => array_values($rawLines), 'steps' => $steps];
}

/**
 * @return array{
 *   session_name:string,
 *   claude_session_id:string,
 *   fixture_home:string,
 *   transcript_path:string,
 *   scenario:array,
 *   next_step:int
 * }
 */
function replay_setup(string $scenarioName, string $workdir): array
{
    $scenario = replay_load_scenario($scenarioName);

    $sessionName = 'cc-test-replay-' . getmypid();
    $claudeSessionId = '66666666-6666-4666-8666-' . str_pad((string)getmypid(), 12, '0', STR_PAD_LEFT);

    $fixtureHome = sys_get_temp_dir() . '/csm-test-replay-home-' . getmypid();
    $projectDir = $fixtureHome . '/.claude/projects/-replay-project';
    @mkdir($projectDir, 0700, true);

    $transcriptPath = "{$projectDir}/{$claudeSessionId}.jsonl";
    file_put_contents($transcriptPath, $scenario['seed_line'] . "\n");

    // Must happen before any subprocess (php -S / socket_harness) that
    // needs to resolve this same transcript is started - proc_open()
    // snapshots the current environment at launch time, so a later
    // putenv() here would never reach an already-running child.
    putenv("HOME_ROOT={$fixtureHome}");

    $tmuxSocket = (string)getenv('TMUX_SOCKET');
    $create = proc_open(
        ['tmux', '-S', $tmuxSocket, 'new-session', '-d', '-s', $sessionName, '-c', $workdir, 'bash', '-c', 'stty -echo; exec cat'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (is_resource($create)) {
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($create);
    }

    usleep(300000);

    $sidecarDir = (string)getenv('SIDECAR_DIR');

    if (!is_dir($sidecarDir)) {
        @mkdir($sidecarDir, 0700, true);
    }

    file_put_contents(
        "{$sidecarDir}/{$sessionName}.json",
        json_encode(['workdir' => $workdir, 'spawned_at' => time(), 'claude_session_id' => $claudeSessionId, 'spawned_by_csm' => true])
    );

    return [
        'session_name' => $sessionName,
        'claude_session_id' => $claudeSessionId,
        'fixture_home' => $fixtureHome,
        'transcript_path' => $transcriptPath,
        'scenario' => $scenario,
        'next_step' => 0,
        // Separate from next_step - only steps with append_line !== false
        // consume one of these, so a pane-only step (no new transcript
        // content) doesn't skip a real line.
        'next_line' => 0,
    ];
}

function replay_tmux_send_keys(string $sessionName, string $text, bool $literal = false): void
{
    $tmuxSocket = (string)getenv('TMUX_SOCKET');
    $args = ['tmux', '-S', $tmuxSocket, 'send-keys', '-t', $sessionName];

    if ($literal) {
        $args[] = '-l';
        $args[] = $text;
    } else {
        $args[] = $text;
        $args[] = 'Enter';
    }

    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (is_resource($process)) {
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
}

/**
 * The fixture-side equivalent of TmuxService::tmux_capture_pane() -
 * exposed so a caller (e.g. test_session_replay_browser.php, confirming a
 * DOM click's submit actually reached /answer_prompt.php) can see the raw
 * pane content for itself, without depending on host-agent's own classes
 * (these replay tests talk to the app purely over HTTP/CDP, never
 * in-process - see this file's own doc comment).
 */
function replay_capture_pane(string $sessionName): string
{
    $tmuxSocket = (string)getenv('TMUX_SOCKET');
    $process = proc_open(
        ['tmux', '-S', $tmuxSocket, 'capture-pane', '-t', $sessionName, '-p', '-J'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (!is_resource($process)) {
        return '';
    }

    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $out === false ? '' : $out;
}

/**
 * Advances $ctx by exactly one step: appends the next transcript line to
 * the real transcript file (unless this step's own "append_line" is
 * false - see this file's own doc comment), then (if this step calls for
 * it) redraws the fixture pane - a real ANSI clear-screen first when
 * "clear_before" is set, so tmux_capture_pane() (which only ever sees the
 * pane's CURRENTLY VISIBLE content) genuinely stops showing whatever
 * prompt text was there before, exactly like a real Claude Code repaint
 * would.
 *
 * @param array{session_name:string, transcript_path:string, scenario:array, next_step:int, next_line:int} $ctx
 * @return array{line_number:?int, step:array}|null null once every step has been consumed;
 *   line_number is null for an append_line:false step (no new transcript content this step)
 */
function replay_step(array &$ctx): ?array
{
    $steps = $ctx['scenario']['steps'];
    $i = $ctx['next_step'];

    if ($i >= count($steps)) {
        return null;
    }

    $step = $steps[$i];
    $lineNumber = null;

    if ($step['append_line'] ?? true) {
        $lineIndex = $ctx['next_line'];
        file_put_contents($ctx['transcript_path'], $ctx['scenario']['lines'][$lineIndex] . "\n", FILE_APPEND);
        // read_transcript_page()'s 'line' field is 1-indexed against the
        // whole file, and the seed line already occupies line 1 - see
        // replay_setup()/replay_load_scenario()'s own doc comments.
        $lineNumber = $lineIndex + 2;
        $ctx['next_line'] = $lineIndex + 1;
    }

    if (!empty($step['clear_before'])) {
        replay_tmux_send_keys($ctx['session_name'], "\x1b[2J\x1b[H", true);
    }

    foreach ($step['pane_text'] ?? [] as $line) {
        replay_tmux_send_keys($ctx['session_name'], $line);
    }

    usleep(300000);

    $ctx['next_step'] = $i + 1;

    return ['line_number' => $lineNumber, 'step' => $step];
}

/**
 * @param array{session_name:string, fixture_home:string} $ctx
 */
function replay_teardown(array $ctx): void
{
    $tmuxSocket = (string)getenv('TMUX_SOCKET');
    $kill = proc_open(
        ['tmux', '-S', $tmuxSocket, 'kill-session', '-t', $ctx['session_name']],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (is_resource($kill)) {
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($kill);
    }

    @unlink((string)getenv('SIDECAR_DIR') . "/{$ctx['session_name']}.json");

    $projectDir = $ctx['fixture_home'] . '/.claude/projects/-replay-project';

    foreach (glob($projectDir . '/*.jsonl') ?: [] as $leftover) {
        @unlink($leftover);
    }

    @rmdir($projectDir);
    @rmdir($ctx['fixture_home'] . '/.claude/projects');
    @rmdir($ctx['fixture_home'] . '/.claude');
    @rmdir($ctx['fixture_home']);

    // Same "unset the override" pattern as test_sessions_lifecycle.php's
    // own temporary HOME_ROOT blocks - there is no separate underlying
    // value to restore to (putenv() is a flat table, not a stack), so this
    // intentionally matches existing precedent rather than trying to
    // remember/reinstate .env.testing's own HOME_ROOT.
    putenv('HOME_ROOT');
}
