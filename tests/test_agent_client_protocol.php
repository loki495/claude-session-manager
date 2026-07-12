<?php
declare(strict_types=1);

/**
 * Tests src/lib/AgentClient.php's agent_call() against the *real*
 * host-agent/agent.php + Sessions.php, over a real Unix socket, using
 * tests/lib/socket_harness.php to stand in for systemd's Accept=yes
 * socket activation. Runs against the isolated fixtures from
 * tests/.env.testing (inherited from the environment tests/run.sh
 * exported), so 'list'/'browse_dir' see fixture data, not the real
 * host - this file never creates a tmux session itself.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require dirname(__DIR__) . '/src/lib/AgentClient.php';

$socketPath = sys_get_temp_dir() . '/csm-test-agent-protocol.sock';
$agentPhp = dirname(__DIR__) . '/host-agent/agent.php';

$harness = start_harness(['php', $agentPhp], $socketPath);
putenv("CSM_AGENT_SOCKET={$socketPath}");

try {
    // --- list ---
    $result = agent_call(['action' => 'list']);
    assert_true($result['ok'] ?? false, 'list: ok=true');
    assert_true(is_array($result['sessions'] ?? null), 'list: sessions is an array');
    assert_equal([], $result['sessions'], 'list: no sessions on a fresh isolated tmux socket');

    // --- browse_dir: no path -> defaults to WWW_ROOT ---
    $result = agent_call(['action' => 'browse_dir', 'path' => '']);
    assert_true($result['ok'] ?? false, 'browse_dir (default): ok=true');
    assert_equal(getenv('WWW_ROOT'), $result['path'] ?? null, 'browse_dir (default): path defaults to WWW_ROOT');
    assert_equal(['project-a', 'project-b'], $result['dirs'] ?? null, 'browse_dir (default): dotfiles excluded, sorted');
    assert_equal(getenv('HOME_ROOT'), $result['parent'] ?? null, 'browse_dir (default): parent is HOME_ROOT');

    // --- browse_dir: outside HOME_ROOT is rejected ---
    $result = agent_call(['action' => 'browse_dir', 'path' => '/etc']);
    assert_equal(false, $result['ok'] ?? null, 'browse_dir (/etc): rejected as outside the home directory');

    // --- malformed request (raw socket, bypassing agent_call()'s own encoding) ---
    $conn = stream_socket_client('unix://' . $socketPath, $errno, $errstr, 5);
    assert_true($conn !== false, 'malformed: connected to harness');
    fwrite($conn, 'not valid json{');
    stream_socket_shutdown($conn, STREAM_SHUT_WR);
    $raw = stream_get_contents($conn);
    fclose($conn);
    $decoded = json_decode($raw, true);
    assert_equal(false, $decoded['ok'] ?? null, 'malformed: agent.php responds ok=false');
    assert_equal('Malformed request', $decoded['message'] ?? null, 'malformed: agent.php reports the expected message');

    // --- unreachable socket ---
    putenv('CSM_AGENT_SOCKET=' . sys_get_temp_dir() . '/csm-test-does-not-exist.sock');
    $result = agent_call(['action' => 'list']);
    assert_equal(false, $result['ok'] ?? null, 'unreachable: ok=false');
    assert_contains('Cannot reach host agent', $result['message'] ?? '', 'unreachable: message explains the failure');
} finally {
    stop_harness($harness, $socketPath);
}

test_exit();
