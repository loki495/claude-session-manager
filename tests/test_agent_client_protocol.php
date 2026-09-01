<?php
declare(strict_types=1);

/**
 * Tests src/lib/AgentClient.php's AgentClient::agent_call() against the *real*
 * host-agent/agent.php + Sessions.php, over a real Unix socket, using
 * tests/lib/socket_harness.php to stand in for systemd's Accept=yes
 * socket activation. Runs against the isolated fixtures from
 * tests/.env.testing (inherited from the environment tests/run.sh
 * exported), so 'list'/'browse_dir' see fixture data, not the real
 * host - this file never creates a tmux session itself.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use App\AgentClient;

$socketPath = sys_get_temp_dir() . '/sessioneer-test-agent-protocol.sock';
$agentPhp = dirname(__DIR__) . '/host-agent/agent.php';

$harness = start_harness(['php', $agentPhp], $socketPath);
putenv("SESSIONEER_AGENT_SOCKET={$socketPath}");

try {
    // --- list ---
    $result = AgentClient::agent_call(['action' => 'list']);
    assert_true($result['ok'] ?? false, 'list: ok=true');
    assert_true(is_array($result['sessions'] ?? null), 'list: sessions is an array');
    assert_equal([], $result['sessions'], 'list: no sessions on a fresh isolated tmux socket');

    // --- browse_dir: no path -> defaults to WWW_ROOT ---
    $result = AgentClient::agent_call(['action' => 'browse_dir', 'path' => '']);
    assert_true($result['ok'] ?? false, 'browse_dir (default): ok=true');
    assert_equal(getenv('WWW_ROOT'), $result['path'] ?? null, 'browse_dir (default): path defaults to WWW_ROOT');
    assert_equal(['.hidden-dir', 'project-a', 'project-b'], $result['dirs'] ?? null, 'browse_dir (default): includes hidden dirs, sorted');
    assert_equal(getenv('HOME_ROOT'), $result['parent'] ?? null, 'browse_dir (default): parent is HOME_ROOT');

    // --- browse_dir: outside HOME_ROOT is rejected ---
    $result = AgentClient::agent_call(['action' => 'browse_dir', 'path' => '/etc']);
    assert_equal(false, $result['ok'] ?? null, 'browse_dir (/etc): rejected as outside the home directory');

    // --- create_dir: dispatched through to SessionService::create_dir() over the real socket ---
    $newDirPath = getenv('WWW_ROOT') . '/sessioneer-test-protocol-new-folder';
    @rmdir($newDirPath); // in case a previous failed run left it behind

    $result = AgentClient::agent_call(['action' => 'create_dir', 'path' => getenv('WWW_ROOT'), 'name' => 'sessioneer-test-protocol-new-folder']);
    assert_true($result['ok'] ?? false, 'create_dir: ok=true');
    assert_true(is_dir($newDirPath), 'create_dir: the directory really exists on disk afterward');
    assert_equal($newDirPath, $result['path'] ?? null, 'create_dir: response path is the new folder itself');

    @rmdir($newDirPath);

    $result = AgentClient::agent_call(['action' => 'create_dir', 'path' => '/etc', 'name' => 'sessioneer-test-protocol-escape']);
    assert_equal(false, $result['ok'] ?? null, 'create_dir (/etc): rejected as outside the home directory');

    // --- session_detail / session_history: wired over the socket (deeper
    // coverage, incl. the actual create -> transcript-not-found path, lives
    // in test_sessions_lifecycle.php against real tmux) ---
    $result = AgentClient::agent_call(['action' => 'session_detail', 'session' => 'cc-not-a-real-session']);
    assert_equal(false, $result['ok'] ?? null, 'session_detail: ok=false for a session that does not exist on a fresh isolated tmux socket');

    $result = AgentClient::agent_call(['action' => 'session_history', 'session' => 'cc-not-a-real-session', 'before' => null, 'limit' => 10]);
    assert_equal(false, $result['ok'] ?? null, 'session_history: ok=false for a session with no sidecar');

    // --- list_archived / archived_session_detail / archived_session_history:
    // wired over the socket the same way (deeper coverage - real archived
    // transcripts, exclusion of a tracked session - lives in
    // test_transcript.php and test_sessions_lifecycle.php) ---
    $result = AgentClient::agent_call(['action' => 'list_archived']);
    assert_true($result['ok'] ?? false, 'list_archived: ok=true');
    assert_equal([], $result['archived'] ?? null, 'list_archived: no archived transcripts under the isolated fixture HOME_ROOT');

    $result = AgentClient::agent_call(['action' => 'archived_session_detail', 'agent_session_id' => '00000000-0000-4000-8000-000000000000']);
    assert_equal(false, $result['ok'] ?? null, 'archived_session_detail: ok=false for an unknown (but well-formed) agent_session_id');

    $result = AgentClient::agent_call(['action' => 'archived_session_history', 'agent_session_id' => '00000000-0000-4000-8000-000000000000', 'before' => null, 'limit' => 10]);
    assert_equal(false, $result['ok'] ?? null, 'archived_session_history: ok=false for an unknown (but well-formed) agent_session_id');

    // --- malformed request (raw socket, bypassing AgentClient::agent_call()'s own encoding) ---
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
    putenv('SESSIONEER_AGENT_SOCKET=' . sys_get_temp_dir() . '/sessioneer-test-does-not-exist.sock');
    $result = AgentClient::agent_call(['action' => 'list']);
    assert_equal(false, $result['ok'] ?? null, 'unreachable: ok=false');
    assert_contains('Cannot reach host agent', $result['message'] ?? '', 'unreachable: message explains the failure');
} finally {
    stop_harness($harness, $socketPath);
}

test_exit();
