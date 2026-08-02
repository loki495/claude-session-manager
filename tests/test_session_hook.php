<?php
declare(strict_types=1);

/**
 * Exercises check_session_hook()/install_session_hook() (the
 * ~/.claude/settings.json read-modify-write logic covering both the
 * SessionStart and PreToolUse hooks) and the actual
 * host-agent/hooks/session_start.php and host-agent/hooks/pre_tool_use.php
 * scripts Claude Code invokes - both against isolated fixture paths, never
 * the real ~/.claude/settings.json or the real sidecar dir. Uses its own
 * temp HOME_ROOT/SIDECAR_DIR (overridden via putenv(), not
 * tests/.env.testing's shared ones) so a stray settings.json can never end
 * up committed under tests/fixtures.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

const REAL_HOME_ROOT = '/home/andres';

$fixtureHome = sys_get_temp_dir() . '/csm-test-hook-home-' . bin2hex(random_bytes(4));
$fixtureSidecarDir = sys_get_temp_dir() . '/csm-test-hook-sidecars-' . bin2hex(random_bytes(4));

putenv("HOME_ROOT={$fixtureHome}");
putenv("SIDECAR_DIR={$fixtureSidecarDir}");

if (home_root() === REAL_HOME_ROOT) {
    fwrite(STDERR, "REFUSING TO RUN: HOME_ROOT still resolves to the real home directory.\n");
    exit(1);
}

mkdir($fixtureSidecarDir, 0700, true);

$settingsPath = claude_settings_path();

try {
    // --- check_session_hook() / install_session_hook(): fresh machine, no settings.json yet ---

    $check = check_session_hook();
    assert_equal(true, $check['ok'], 'check_session_hook: ok on a missing settings.json');
    assert_equal(false, $check['installed'], 'check_session_hook: not installed when settings.json does not exist yet');

    $install = install_session_hook();
    assert_equal(true, $install['ok'], 'install_session_hook: succeeds on a missing settings.json');
    assert_equal(true, $install['installed'], 'install_session_hook: reports installed after creating the file');
    assert_true(is_file($settingsPath), 'install_session_hook: creates ~/.claude/settings.json');

    $decoded = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal(
        session_start_hook_command(),
        $decoded['hooks']['SessionStart'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: written file has our SessionStart hook command'
    );
    assert_equal('*', $decoded['hooks']['SessionStart'][0]['matcher'] ?? null, 'install_session_hook: matcher fires on every session-start source');
    assert_equal(
        pre_tool_use_hook_command(),
        $decoded['hooks']['PreToolUse'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: written file has our PreToolUse hook command'
    );
    assert_equal('*', $decoded['hooks']['PreToolUse'][0]['matcher'] ?? null, 'install_session_hook: PreToolUse matcher fires on every tool');

    assert_equal(true, check_session_hook()['installed'], 'check_session_hook: installed after install_session_hook()');

    // --- idempotency: installing again must not duplicate either entry ---

    install_session_hook();
    $decoded = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal(1, count($decoded['hooks']['SessionStart']), 'install_session_hook: calling twice does not duplicate the SessionStart entry');
    assert_equal(1, count($decoded['hooks']['PreToolUse']), 'install_session_hook: calling twice does not duplicate the PreToolUse entry');

    // --- partial install (only one of the two hooks present) is topped up, not left alone ---

    $onlySessionStart = [
        'hooks' => [
            'SessionStart' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => session_start_hook_command()]]]],
        ],
    ];
    file_put_contents($settingsPath, json_encode($onlySessionStart, JSON_PRETTY_PRINT));

    $partialCheck = check_session_hook();
    assert_equal(false, $partialCheck['installed'], 'check_session_hook: installed=false when only one of the two hooks is present');

    install_session_hook();
    $decoded = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal(1, count($decoded['hooks']['SessionStart']), 'install_session_hook: topping up PreToolUse does not duplicate the existing SessionStart entry');
    assert_equal(
        pre_tool_use_hook_command(),
        $decoded['hooks']['PreToolUse'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: adds the missing PreToolUse entry when SessionStart was already present'
    );

    // --- merge safety: an existing file's unrelated hooks/settings survive untouched ---

    $preexisting = [
        'hooks' => [
            'Stop' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => 'notify-send done']]]],
        ],
        'theme' => 'dark',
    ];
    file_put_contents($settingsPath, json_encode($preexisting, JSON_PRETTY_PRINT));

    install_session_hook();
    $decoded = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal('notify-send done', $decoded['hooks']['Stop'][0]['hooks'][0]['command'] ?? null, 'install_session_hook: preserves a pre-existing unrelated hook');
    assert_equal('dark', $decoded['theme'] ?? null, 'install_session_hook: preserves pre-existing top-level settings');
    assert_equal(
        session_start_hook_command(),
        $decoded['hooks']['SessionStart'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: still adds the SessionStart entry alongside pre-existing hooks'
    );
    assert_equal(
        pre_tool_use_hook_command(),
        $decoded['hooks']['PreToolUse'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: still adds the PreToolUse entry alongside pre-existing hooks'
    );

    // --- reindent_json_pretty(): PHP's 4-space JSON_PRETTY_PRINT output is halved to 2-space ---

    $rawWritten = (string)file_get_contents($settingsPath);
    assert_true(str_contains($rawWritten, "\n  \"hooks\""), 'install_session_hook: writes 2-space indent, not PHP default 4-space');

    // --- malformed existing file: refuses to overwrite, never resets to empty ---

    file_put_contents($settingsPath, '{not valid json');

    $checkMalformed = check_session_hook();
    assert_equal(false, $checkMalformed['ok'], 'check_session_hook: ok=false on a malformed settings.json');
    assert_equal(false, $checkMalformed['installed'], 'check_session_hook: installed=false on a malformed settings.json');

    $installMalformed = install_session_hook();
    assert_equal(false, $installMalformed['ok'], 'install_session_hook: refuses to touch a malformed settings.json');
    assert_equal('{not valid json', file_get_contents($settingsPath), 'install_session_hook: leaves a malformed settings.json byte-for-byte untouched');

    unlink($settingsPath);

    // --- session_start_hook_present()/pre_tool_use_hook_present(): key off the exact command string, not just hook presence ---

    assert_equal(false, session_start_hook_present(['hooks' => ['SessionStart' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => 'something-unrelated.sh']]]]]]), 'session_start_hook_present: false for an unrelated SessionStart hook');
    assert_equal(true, session_start_hook_present(['hooks' => ['SessionStart' => [['matcher' => 'clear', 'hooks' => [['type' => 'command', 'command' => session_start_hook_command()]]]]]]), 'session_start_hook_present: true when our command is present under any matcher');
    assert_equal(false, pre_tool_use_hook_present(['hooks' => ['PreToolUse' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => 'something-unrelated.sh']]]]]]), 'pre_tool_use_hook_present: false for an unrelated PreToolUse hook');
    assert_equal(true, pre_tool_use_hook_present(['hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => pre_tool_use_hook_command()]]]]]]), 'pre_tool_use_hook_present: true when our command is present under any matcher');

    // --- format_pending_tool_input(): full-text preview per tool shape ---

    assert_equal(
        "npm test",
        format_pending_tool_input('Bash', ['command' => 'npm test']),
        'format_pending_tool_input: Bash with no description is just the command'
    );
    assert_equal(
        "Run tests\n\nnpm test",
        format_pending_tool_input('Bash', ['command' => 'npm test', 'description' => 'Run tests']),
        'format_pending_tool_input: Bash description is prepended when present'
    );
    assert_equal(null, format_pending_tool_input('Bash', []), 'format_pending_tool_input: Bash with no command returns null');
    assert_equal(
        "Write /tmp/foo.txt\n\nline1\nline2",
        format_pending_tool_input('Write', ['file_path' => '/tmp/foo.txt', 'content' => "line1\nline2"]),
        'format_pending_tool_input: Write shows the full file content, not truncated'
    );
    assert_equal(null, format_pending_tool_input('Write', ['file_path' => '/tmp/foo.txt']), 'format_pending_tool_input: Write with no content returns null');
    assert_equal(
        "Edit /tmp/foo.txt\n\n--- old ---\nfoo\n\n--- new ---\nbar",
        format_pending_tool_input('Edit', ['file_path' => '/tmp/foo.txt', 'old_string' => 'foo', 'new_string' => 'bar']),
        'format_pending_tool_input: Edit shows old/new'
    );
    assert_equal(null, format_pending_tool_input('Edit', []), 'format_pending_tool_input: Edit with no file_path returns null');
    assert_true(
        str_starts_with(format_pending_tool_input('WebFetch', ['url' => 'https://example.com']) ?? '', "WebFetch\n\n"),
        'format_pending_tool_input: unrecognized tool falls back to a labeled JSON dump'
    );

    // --- augment_prompt_with_pending_tool(): only replaces context when the pending tool matches the pane's own marker ---

    $basePrompt = [
        'question' => 'Do you want to proceed?',
        'context' => "● Bash(npm test (truncated…",
        'options' => [],
        'multi_question' => false,
        'is_folder_trust' => false,
    ];

    assert_equal(
        $basePrompt,
        augment_prompt_with_pending_tool($basePrompt, null),
        'augment_prompt_with_pending_tool: no pending-tool file leaves the pane-scraped prompt untouched'
    );
    assert_equal(
        $basePrompt,
        augment_prompt_with_pending_tool($basePrompt, ['tool_name' => 'Write', 'tool_input' => ['file_path' => '/x', 'content' => 'y']]),
        'augment_prompt_with_pending_tool: a tool-name mismatch against the pane marker is left untouched (stale/wrong pending file)'
    );
    assert_equal(
        $basePrompt,
        augment_prompt_with_pending_tool($basePrompt, ['tool_name' => 'Bash', 'tool_input' => null]),
        'augment_prompt_with_pending_tool: a malformed pending-tool entry (no tool_input) is left untouched'
    );

    $augmented = augment_prompt_with_pending_tool($basePrompt, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'npm test --full-real-command-not-truncated']]);
    assert_equal('npm test --full-real-command-not-truncated', $augmented['context'], 'augment_prompt_with_pending_tool: a matching tool name replaces the truncated pane context with the full hook-sourced one');
    assert_equal('Do you want to proceed?', $augmented['question'], 'augment_prompt_with_pending_tool: only context is replaced, question/options/etc are untouched');

    // --- pending-tool sidecar: read/write/delete round-trip ---

    $pendingName = 'cc-pendingtest-' . bin2hex(random_bytes(3));
    assert_equal(null, read_pending_tool($pendingName), 'read_pending_tool: null when no file exists yet');

    write_pending_tool($pendingName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls'], 'written_at' => 1000]);
    $read = read_pending_tool($pendingName);
    assert_equal('Bash', $read['tool_name'] ?? null, 'write_pending_tool/read_pending_tool: round-trips tool_name');
    assert_equal('ls', $read['tool_input']['command'] ?? null, 'write_pending_tool/read_pending_tool: round-trips tool_input');

    delete_pending_tool($pendingName);
    assert_equal(null, read_pending_tool($pendingName), 'delete_pending_tool: file is gone after delete');

    // --- prune_orphaned_sidecars(): correctly matches pending-tool files back to their session name ---

    $liveName = 'cc-prunelive-' . bin2hex(random_bytes(3));
    $deadName = 'cc-prunedead-' . bin2hex(random_bytes(3));
    write_sidecar($liveName, ['workdir' => '/x', 'spawned_at' => 1]);
    write_pending_tool($liveName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]);
    write_sidecar($deadName, ['workdir' => '/x', 'spawned_at' => 1]);
    write_pending_tool($deadName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]);

    prune_orphaned_sidecars([$liveName]);

    assert_true(read_sidecar($liveName) !== null, 'prune_orphaned_sidecars: a live session\'s plain sidecar survives');
    assert_true(read_pending_tool($liveName) !== null, 'prune_orphaned_sidecars: a live session\'s pending-tool file survives (not mistaken for an orphan by its own filename)');
    assert_equal(null, read_sidecar($deadName), 'prune_orphaned_sidecars: a dead session\'s plain sidecar is pruned');
    assert_equal(null, read_pending_tool($deadName), 'prune_orphaned_sidecars: a dead session\'s pending-tool file is pruned too');

    // --- the actual hook script: no CSM_SESSION_NAME env -> no-op ---

    $sidecarName = 'cc-hooktest-' . bin2hex(random_bytes(3));
    write_sidecar($sidecarName, ['workdir' => '/fixture/workdir', 'spawned_at' => 1000, 'claude_session_id' => 'old-id']);

    run_session_start_hook(null, ['session_id' => 'new-id']);
    assert_equal('old-id', read_sidecar($sidecarName)['claude_session_id'] ?? null, 'session_start.php: no-op (sidecar untouched) when CSM_SESSION_NAME is unset');

    // --- CSM_SESSION_NAME set, but no matching sidecar (already killed/never tracked) -> no-op, no crash ---

    run_session_start_hook('cc-does-not-exist', ['session_id' => 'new-id']);
    assert_equal(null, read_sidecar('cc-does-not-exist'), 'session_start.php: no-op when CSM_SESSION_NAME has no sidecar file');

    // --- CSM_SESSION_NAME set + real sidecar + valid payload -> rebinds claude_session_id, keeps the rest ---

    run_session_start_hook($sidecarName, ['session_id' => 'new-id']);
    $rebound = read_sidecar($sidecarName);
    assert_equal('new-id', $rebound['claude_session_id'] ?? null, 'session_start.php: rebinds claude_session_id to the new session-id from stdin');
    assert_equal('/fixture/workdir', $rebound['workdir'] ?? null, 'session_start.php: preserves workdir across the rebind');
    assert_equal(1000, $rebound['spawned_at'] ?? null, 'session_start.php: preserves spawned_at across the rebind');

    // --- malformed/empty stdin -> no-op, never crashes, sidecar untouched ---

    run_session_start_hook($sidecarName, null);
    assert_equal('new-id', read_sidecar($sidecarName)['claude_session_id'] ?? null, 'session_start.php: no-op on empty/malformed stdin payload');

    // --- pre_tool_use.php: no CSM_SESSION_NAME env -> no-op ---

    $preToolSessionName = 'cc-pretooltest-' . bin2hex(random_bytes(3));

    run_pre_tool_use_hook(null, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]);
    assert_equal(null, read_pending_tool($preToolSessionName), 'pre_tool_use.php: no-op (no file written) when CSM_SESSION_NAME is unset');

    // --- CSM_SESSION_NAME set + valid payload -> writes tool_name/tool_input, no sidecar required first ---

    run_pre_tool_use_hook($preToolSessionName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'echo hi'], 'tool_use_id' => 'toolu_1']);
    $written = read_pending_tool($preToolSessionName);
    assert_equal('Bash', $written['tool_name'] ?? null, 'pre_tool_use.php: records tool_name from stdin');
    assert_equal('echo hi', $written['tool_input']['command'] ?? null, 'pre_tool_use.php: records the full tool_input from stdin');

    // --- a later tool call overwrites the previous one (only the latest is ever kept) ---

    run_pre_tool_use_hook($preToolSessionName, ['tool_name' => 'Write', 'tool_input' => ['file_path' => '/tmp/x', 'content' => 'y']]);
    $overwritten = read_pending_tool($preToolSessionName);
    assert_equal('Write', $overwritten['tool_name'] ?? null, 'pre_tool_use.php: a later tool call overwrites the earlier pending-tool file');

    // --- malformed/empty stdin, or a payload missing tool_name/tool_input -> no-op, never crashes ---

    delete_pending_tool($preToolSessionName);
    run_pre_tool_use_hook($preToolSessionName, null);
    assert_equal(null, read_pending_tool($preToolSessionName), 'pre_tool_use.php: no-op on empty/malformed stdin payload');

    run_pre_tool_use_hook($preToolSessionName, ['hook_event_name' => 'PreToolUse']);
    assert_equal(null, read_pending_tool($preToolSessionName), 'pre_tool_use.php: no-op when tool_name/tool_input are missing from the payload');

    // --- never emits stdout - a hook that prints anything (even {}) could be read as an explicit permission decision ---

    assert_equal('', run_pre_tool_use_hook($preToolSessionName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]), 'pre_tool_use.php: writes nothing to stdout, deferring the permission decision entirely to Claude Code\'s normal flow');
} finally {
    @unlink($settingsPath);
    @rmdir(dirname($settingsPath));
    array_map('unlink', glob("{$fixtureSidecarDir}/*") ?: []);
    @rmdir($fixtureSidecarDir);
    @rmdir($fixtureHome);
}

test_exit();

/**
 * Runs the real host-agent/hooks/session_start.php as a subprocess, same
 * as Claude Code itself would - $csmSessionName becomes its CSM_SESSION_NAME
 * env var (omitted entirely when null, mirroring a plain untracked claude
 * process), $payload is JSON-encoded to its stdin (raw '' when null, to
 * exercise the empty/malformed-input path).
 *
 * @param array<string, mixed>|null $payload
 */
function run_session_start_hook(?string $csmSessionName, ?array $payload): void
{
    $env = [
        'HOME_ROOT' => home_root(),
        'SIDECAR_DIR' => sidecar_dir(),
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    ];

    if ($csmSessionName !== null) {
        $env['CSM_SESSION_NAME'] = $csmSessionName;
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['php', dirname(__DIR__) . '/host-agent/hooks/session_start.php'],
        $descriptors,
        $pipes,
        null,
        $env
    );

    if (!is_resource($process)) {
        assert_true(false, 'run_session_start_hook: failed to start subprocess');
        return;
    }

    fwrite($pipes[0], $payload !== null ? json_encode($payload) : '');
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}

/**
 * Same shape as run_session_start_hook(), for host-agent/hooks/pre_tool_use.php
 * - returns its stdout so callers can assert it's always empty (see
 * write_pending_tool()'s "never affects the permission decision" contract).
 *
 * @param array<string, mixed>|null $payload
 */
function run_pre_tool_use_hook(?string $csmSessionName, ?array $payload): string
{
    $env = [
        'HOME_ROOT' => home_root(),
        'SIDECAR_DIR' => sidecar_dir(),
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    ];

    if ($csmSessionName !== null) {
        $env['CSM_SESSION_NAME'] = $csmSessionName;
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['php', dirname(__DIR__) . '/host-agent/hooks/pre_tool_use.php'],
        $descriptors,
        $pipes,
        null,
        $env
    );

    if (!is_resource($process)) {
        assert_true(false, 'run_pre_tool_use_hook: failed to start subprocess');
        return '';
    }

    fwrite($pipes[0], $payload !== null ? json_encode($payload) : '');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return (string)$stdout;
}
