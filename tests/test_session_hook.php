<?php
declare(strict_types=1);

/**
 * Exercises check_session_hook()/install_session_hook() (the
 * ~/.claude/settings.json read-modify-write logic) and the actual
 * host-agent/hooks/session_start.php script Claude Code invokes - both
 * against isolated fixture paths, never the real ~/.claude/settings.json
 * or the real sidecar dir. Uses its own temp HOME_ROOT/SIDECAR_DIR
 * (overridden via putenv(), not tests/.env.testing's shared ones) so a
 * stray settings.json can never end up committed under tests/fixtures.
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

    assert_equal(true, check_session_hook()['installed'], 'check_session_hook: installed after install_session_hook()');

    // --- idempotency: installing again must not duplicate the entry ---

    install_session_hook();
    $decoded = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal(1, count($decoded['hooks']['SessionStart']), 'install_session_hook: calling twice does not duplicate the SessionStart entry');

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

    // --- session_start_hook_present(): keys off the exact command string, not just hook presence ---

    assert_equal(false, session_start_hook_present(['hooks' => ['SessionStart' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => 'something-unrelated.sh']]]]]]), 'session_start_hook_present: false for an unrelated SessionStart hook');
    assert_equal(true, session_start_hook_present(['hooks' => ['SessionStart' => [['matcher' => 'clear', 'hooks' => [['type' => 'command', 'command' => session_start_hook_command()]]]]]]), 'session_start_hook_present: true when our command is present under any matcher');

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
