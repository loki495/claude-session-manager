<?php
declare(strict_types=1);

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Runtimes\OpenCodeServeClient;

$stub = __DIR__ . '/fixtures/opencode_serve_client_stub.php';
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($sock === false) {
    fwrite(STDERR, "could not reserve a port: {$errstr}\n");
    exit(1);
}

$address = stream_socket_get_name($sock, false);
fclose($sock);
$port = (int)substr((string)$address, strrpos((string)$address, ':') + 1);
$server = proc_open(
    ['php', '-S', "127.0.0.1:{$port}", $stub],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);

if (!is_resource($server)) {
    fwrite(STDERR, "failed to start serve stub\n");
    exit(1);
}

putenv("OPENCODE_SERVE_URL=http://127.0.0.1:{$port}");

try {
    for ($i = 0; $i < 20; $i++) {
        usleep(100000);
        if (@file_get_contents("http://127.0.0.1:{$port}/session/missing") !== false) {
            break;
        }
    }

    $client = new OpenCodeServeClient();

    $created = $client->create_session('/tmp/project', 'Stub session');
    assert_true($created['ok'] ?? false, 'create_session: decodes a successful response');
    assert_equal('ses_stub_create', $created['id'] ?? null, 'create_session: returns the session id');

    $sessions = $client->list_sessions();
    assert_equal(true, $sessions['ok'] ?? null, 'list_sessions: successful response is handled');
    assert_equal(2, count($sessions['sessions'] ?? []), 'list_sessions: returns all session records');

    $detail = $client->get_session('ses_stub_create');
    assert_equal('model', $detail['session']['model']['id'] ?? null, 'get_session: returns decoded detail');

    $sent = $client->send_message('ses_stub_create', 'hello', [], ['providerID' => 'stub', 'modelID' => 'model']);
    assert_true($sent['ok'] ?? false, 'send_message: accepts a successful 204 response');

    $missing = $client->get_session('missing');
    assert_equal(false, $missing['ok'] ?? null, 'get_session: HTTP failure is returned as a handled error');
    assert_true(str_contains($missing['message'] ?? '', 'HTTP 404'), 'get_session: failure includes the HTTP status');

    putenv('OPENCODE_SERVE_URL=http://127.0.0.1:1');
    $unavailable = (new OpenCodeServeClient())->list_sessions();
    assert_equal(false, $unavailable['ok'] ?? null, 'list_sessions: unavailable serve is handled without throwing');
    assert_true(($unavailable['message'] ?? '') !== '', 'list_sessions: unavailable serve provides an error message');
} finally {
    proc_terminate($server);
    proc_close($server);
}

test_exit();
