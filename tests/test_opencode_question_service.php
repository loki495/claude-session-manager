<?php
declare(strict_types=1);

/**
 * Exercises HostAgent\Services\OpenCodeQuestionService - the serve-API
 * question client (GET /question for detection, POST /question/{id}/reply for
 * answering) - against a throwaway `php -S` stub, so the real `opencode serve`
 * process is never contacted and no live tmux pane is needed.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\OpenCodeQuestionService;

$stub = __DIR__ . '/fixtures/opencode_question_stub.php';
$stateFile = sys_get_temp_dir() . '/csm-test-ocq-state-' . bin2hex(random_bytes(4));
putenv("CSM_STUB_STATE={$stateFile}");
file_put_contents($stateFile, json_encode(['replies' => []]));

// Pick a free port, start `php -S`, and point OPENCODE_SERVE_URL at it.
$port = 0;
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$sock) {
    fwrite(STDERR, "could not reserve a port: {$errstr}\n");
    exit(1);
}
$addr = stream_socket_get_name($sock, false);
fclose($sock);
$port = (int)substr($addr, strrpos($addr, ':') + 1);

$serverProc = proc_open(
    ['php', '-S', "127.0.0.1:{$port}", $stub],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);

if (!is_resource($serverProc)) {
    fwrite(STDERR, "failed to start php -S stub\n");
    exit(1);
}

putenv("OPENCODE_SERVE_URL=http://127.0.0.1:{$port}");

try {
    // Give the stub a moment to bind.
    $up = false;
    for ($i = 0; $i < 20 && !$up; $i++) {
        usleep(100000);
        $r = @file_get_contents("http://127.0.0.1:{$port}/question");
        $up = is_string($r);
    }
    assert_true($up, 'stub server: reached on the reserved port');

    // --- pending_question: finds a live question for the matching session ---
    $pending = OpenCodeQuestionService::pending_question('ses_stub123');
    assert_true($pending !== null, 'pending_question: returns a live question for the matching session');
    assert_equal('que_stubrequest', $pending['requestID'] ?? null, 'pending_question: captures the requestID');
    assert_equal('Which approach?', ($pending['questions'][0]['question'] ?? null), 'pending_question: carries the question text');

    // A different session has no pending question.
    assert_equal(null, OpenCodeQuestionService::pending_question('ses_other'), 'pending_question: null for a session with no live question');

    // --- to_prompt: canonical {question, context, options, multi_question} ---
    $prompt = OpenCodeQuestionService::to_prompt($pending);
    assert_equal('Which approach?', $prompt['question'] ?? null, 'to_prompt: question text');
    assert_equal('Approach', $prompt['context'] ?? null, 'to_prompt: header becomes context');
    assert_equal('question', $prompt['tool_name'] ?? null, 'to_prompt: tool_name is question');
    $labels = array_column($prompt['options'], 'label', 'number');
    assert_equal('Alpha', $labels[1] ?? null, 'to_prompt: option 1 is Alpha');
    assert_equal('Beta', $labels[2] ?? null, 'to_prompt: option 2 is Beta');

    // --- answer: POSTs the chosen label and returns ok ---
    $answer = OpenCodeQuestionService::answer('ses_stub123', ['Alpha']);
    assert_equal(true, $answer['ok'] ?? null, 'answer: ok=true for a live question');

    $state = json_decode((string)file_get_contents($stateFile), true);
    $lastReply = end($state['replies']) ?: [];
    assert_equal('que_stubrequest', $lastReply['requestID'] ?? null, 'answer: POSTed to the correct requestID');
    assert_equal([['Alpha']], $lastReply['answers'] ?? null, 'answer: sent the chosen label as answers[[label]]');

    // Answer with no live question -> rejected.
    $noQ = OpenCodeQuestionService::answer('ses_other', ['Alpha']);
    assert_equal(false, $noQ['ok'] ?? null, 'answer: rejects when there is no live question for the session');
} finally {
    proc_terminate($serverProc);
    proc_close($serverProc);
    @unlink($stateFile);
}

test_exit();
