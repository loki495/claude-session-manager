<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';

use HostAgent\Agents\AgentRegistry;
use HostAgent\Runtimes\CodexBridgeClient;
use HostAgent\Runtimes\CodexHeadlessRuntime;
use HostAgent\Runtimes\RuntimeRegistry;
use HostAgent\Runtimes\RuntimeType;

class FakeCodexBridgeClient extends CodexBridgeClient
{
    /** @var array<int,array{method:string,params:array<string,mixed>}> */
    public array $calls = [];

    public function request(string $method, array $params = []): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];
        return match ($method) {
            'thread/start' => ['ok' => true, 'result' => ['thread' => ['id' => 'codex-thread-1', 'cwd' => $params['cwd']]]],
            'thread/list' => ['ok' => true, 'result' => ['data' => [['id' => 'codex-thread-1']]]],
            'thread/read' => ['ok' => true, 'result' => ['thread' => ['id' => 'codex-thread-1', 'status' => ['type' => 'idle']]]],
            'thread/resume' => ['ok' => true, 'result' => ['thread' => ['id' => 'codex-thread-1']]],
            'thread/archive' => ['ok' => true, 'result' => (object)[]],
            'csm/sendInput' => ['ok' => true, 'result' => ['turn' => ['id' => 'turn-1']]],
            'csm/interrupt', 'thread/settings/update' => ['ok' => true, 'result' => (object)[]],
            'csm/pendingPrompt' => ['ok' => true, 'prompt' => null],
            default => ['ok' => false, 'message' => 'Unexpected method'],
        };
    }
}

class FakeUnmaterializedCodexBridgeClient extends CodexBridgeClient
{
    /** @var array<int,array{method:string,params:array<string,mixed>}> */
    public array $calls = [];

    public function request(string $method, array $params = []): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];
        if ($method === 'thread/read' && ($params['includeTurns'] ?? false) === true) {
            return ['ok' => false, 'message' => 'thread codex-empty is not materialized yet; includeTurns is unavailable before first user message'];
        }
        if ($method === 'thread/read') {
            return ['ok' => true, 'result' => ['thread' => ['id' => 'codex-empty', 'status' => ['type' => 'idle'], 'turns' => []]]];
        }
        if ($method === 'thread/resume') {
            return ['ok' => false, 'message' => 'no rollout found for thread id codex-empty'];
        }
        if ($method === 'csm/sendInput') {
            return ['ok' => true, 'result' => ['turn' => ['id' => 'turn-empty']]];
        }
        return ['ok' => false, 'message' => 'Unexpected method'];
    }
}

$adapter = AgentRegistry::get('codex');
assert_equal('Codex', $adapter->label(), 'Codex adapter is registered');
assert_equal([RuntimeType::HEADLESS], $adapter->supported_runtimes(), 'Codex is headless-only');
assert_true(RuntimeRegistry::runtime_for('codex', RuntimeType::TMUX) === null, 'Codex never resolves to tmux');
assert_true(RuntimeRegistry::runtime_for('codex', RuntimeType::HEADLESS) instanceof CodexHeadlessRuntime, 'Codex resolves to its own app-server runtime');

$fake = new FakeCodexBridgeClient();
$runtime = new CodexHeadlessRuntime($fake);
$created = $runtime->create(['workdir' => '/tmp/project', 'model' => 'gpt-test']);
assert_true($created['ok'] === true, 'Codex thread creation succeeds');
assert_equal('codex-thread-1', $created['id'], 'Codex create returns the native thread id');
assert_equal('thread/start', $fake->calls[0]['method'], 'Codex create uses thread/start');
assert_equal('workspace-write', $fake->calls[0]['params']['sandbox'], 'Codex create uses the workspace-write sandbox');

assert_true($runtime->list()['ok'] === true, 'Codex thread list succeeds');
assert_true($runtime->detail('codex-thread-1')['ok'] === true, 'Codex thread detail succeeds');
assert_equal('thread/resume', $fake->calls[3]['method'], 'Codex detail probes whether the thread is writable');
assert_true($runtime->send_message('codex-thread-1', 'hello')['ok'] === true, 'Codex message send succeeds');
assert_equal('thread/resume', $fake->calls[4]['method'], 'Codex message resumes a persisted thread before sending');
assert_equal('csm/sendInput', $fake->calls[5]['method'], 'Codex message delegates start-versus-steer to the persistent bridge');
assert_true($runtime->interrupt('codex-thread-1')['ok'] === true, 'Codex active turn can be interrupted');
assert_equal('csm/interrupt', $fake->calls[6]['method'], 'Codex interrupt is server-native');
assert_true($runtime->update_settings('codex-thread-1', 'gpt-test-2', 'high')['ok'] === true, 'Codex sticky model and effort can be updated');
assert_equal('thread/settings/update', $fake->calls[7]['method'], 'Codex settings use the native thread method');
assert_true($runtime->kill('codex-thread-1')['ok'] === true, 'Codex close succeeds');
assert_equal('thread/archive', $fake->calls[8]['method'], 'Codex close archives rather than deleting');

$emptyFake = new FakeUnmaterializedCodexBridgeClient();
$emptyDetail = (new CodexHeadlessRuntime($emptyFake))->detail('codex-empty');
assert_true($emptyDetail['ok'] === true, 'Codex brand-new unmaterialized thread detail succeeds');
assert_equal(true, $emptyDetail['session']['writable'], 'Codex brand-new unmaterialized thread is writable before its rollout exists');
assert_equal(true, $emptyFake->calls[0]['params']['includeTurns'], 'Codex detail first requests retained turns');
assert_true(!array_key_exists('includeTurns', $emptyFake->calls[1]['params']), 'Codex brand-new detail falls back to metadata-only thread/read');
assert_equal('thread/resume', $emptyFake->calls[2]['method'], 'Codex brand-new detail remains writable after metadata fallback');
assert_true((new CodexHeadlessRuntime($emptyFake))->send_message('codex-empty', 'first message')['ok'] === true, 'Codex brand-new thread sends its first message without a persisted rollout');

echo "Codex runtime tests passed.\n";
test_exit();
