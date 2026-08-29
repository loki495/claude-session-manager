<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Services\CodexTranscriptService;

/** Native Codex runtime backed exclusively by codex app-server. */
class CodexHeadlessRuntime implements RuntimeProvider
{
    public function __construct(private ?CodexBridgeClient $client = null)
    {
        $this->client ??= new CodexBridgeClient();
    }

    public function id(): string { return RuntimeType::HEADLESS; }
    public function isHeadless(): bool { return true; }
    public function isTmux(): bool { return false; }

    public function create(array $options): array
    {
        $workdir = is_string($options['workdir'] ?? null) ? $options['workdir'] : '';
        if ($workdir === '' || $workdir[0] !== '/') {
            return ['ok' => false, 'message' => 'create() requires an absolute workdir'];
        }

        $params = ['cwd' => $workdir, 'approvalPolicy' => 'on-request', 'sandbox' => 'workspace-write'];
        foreach (['model', 'serviceTier'] as $key) {
            if (is_string($options[$key] ?? null) && $options[$key] !== '') {
                $params[$key] = $options[$key];
            }
        }

        $result = $this->client->request('thread/start', $params);
        $thread = is_array($result['result']['thread'] ?? null) ? $result['result']['thread'] : null;
        $id = is_string($thread['id'] ?? null) ? $thread['id'] : null;

        return $result['ok'] === true && $id !== null
            ? ['ok' => true, 'id' => $id, 'session' => $thread]
            : ['ok' => false, 'message' => $result['message'] ?? 'Codex did not return a thread id'];
    }

    public function list(): array
    {
        $result = CodexTranscriptService::list_threads(false, $this->client);
        return $result['ok'] === true
            ? ['ok' => true, 'sessions' => $result['threads'] ?? []]
            : $result;
    }

    public function detail(string $sessionRef): array
    {
        $result = $this->client->request('thread/read', ['threadId' => $sessionRef, 'includeTurns' => true]);

        // A thread/start result is writable immediately, but Codex does not
        // materialize its rollout until the first user message. Asking that
        // brand-new thread for turns is therefore rejected by design. Read
        // metadata only so session.php can render its normal empty-history
        // state and expose the composer for that first message.
        $readMessage = is_string($result['message'] ?? null) ? $result['message'] : '';
        if (($result['ok'] ?? false) !== true && str_contains($readMessage, 'includeTurns is unavailable before first user message')) {
            $result = $this->client->request('thread/read', ['threadId' => $sessionRef]);
        }

        if ($result['ok'] !== true || !is_array($result['result']['thread'] ?? null)) {
            return ['ok' => false, 'message' => $result['message'] ?? 'Codex thread not found'];
        }

        // Reading persisted history does not prove this app-server can
        // write the thread. Probe the stable resume operation while the
        // full page is loading: it claims a released thread for CSM, but
        // reports the single-writer conflict without disturbing a thread
        // still open in another Codex client.
        $resume = $this->client->request('thread/resume', ['threadId' => $sessionRef]);
        $message = is_string($resume['message'] ?? null) ? $resume['message'] : '';
        $activeWriter = str_contains(strtolower($message), 'active writer');
        $unmaterialized = str_contains($message, 'no rollout found for thread id');
        $thread = $result['result']['thread'];
        $thread['writable'] = ($resume['ok'] ?? false) === true || $unmaterialized;
        $thread['readOnlyReason'] = $activeWriter
            ? 'This session is owned by a Codex process on the host. Stop the terminal or background `codex remote-control` process, then refresh this page. Closing the mobile app does not stop it.'
            : ((($resume['ok'] ?? false) === true || $unmaterialized) ? null : ($message !== '' ? $message : 'Codex could not open this session for writing.'));

        return ['ok' => true, 'session' => $thread];
    }

    public function kill(string $sessionRef): array
    {
        $result = $this->client->request('thread/archive', ['threadId' => $sessionRef]);
        return $result['ok'] === true ? ['ok' => true, 'message' => 'Codex thread archived'] : $result;
    }

    public function status(string $sessionRef): array
    {
        $pending = $this->pending_prompt($sessionRef);
        if ($pending !== null) return ['ok' => true, 'status' => 'blocked', 'blocked' => $pending];
        $detail = $this->detail($sessionRef);
        if ($detail['ok'] !== true) return ['ok' => false, 'status' => 'idle', 'message' => $detail['message'] ?? 'Status unavailable'];
        $type = $detail['session']['status']['type'] ?? 'idle';
        return ['ok' => true, 'status' => $type === 'active' ? 'working' : 'idle', 'blocked' => null];
    }

    public function send_message(string $sessionRef, string $text, array $attachmentPaths = []): array
    {
        // thread/read can inspect a persisted thread without loading it into
        // this app-server process. That distinction matters after the bridge
        // is restarted: turn/start otherwise fails with "thread not found"
        // even though the session page and transcript loaded successfully.
        // Resume is idempotent for an already-running thread and rejoins it
        // when another Codex client currently owns the active turn.
        $resumed = $this->client->request('thread/resume', ['threadId' => $sessionRef]);
        if (($resumed['ok'] ?? false) !== true) {
            $message = is_string($resumed['message'] ?? null) ? $resumed['message'] : '';
            if (str_contains(strtolower($message), 'active writer')) {
                return [
                    'ok' => false,
                    'message' => 'This session is read-only because a Codex process on the host owns it. Stop the terminal or background `codex remote-control` process, then refresh this page. Closing the mobile app does not stop it.',
                ];
            }
            // A thread created by this persistent bridge has no rollout to
            // resume until its first user message. It is already loaded in
            // app-server, so fall through to csm/sendInput to materialize
            // that first turn. Any other resume failure remains fatal.
            if (!str_contains($message, 'no rollout found for thread id')) {
                return $resumed;
            }
        }

        $input = [['type' => 'text', 'text' => $text, 'text_elements' => []]];
        foreach ($attachmentPaths as $path) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $input[] = in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)
                ? ['type' => 'localImage', 'path' => $path]
                : ['type' => 'mention', 'name' => basename($path), 'path' => $path];
        }
        $result = $this->client->request('csm/sendInput', ['threadId' => $sessionRef, 'input' => $input]);
        return $result['ok'] === true ? ['ok' => true, 'message' => 'Message sent'] : $result;
    }

    /**
     * Interrupts the bridge's currently tracked active turn.
     * @return array<string,mixed>
     */
    public function interrupt(string $sessionRef): array
    {
        return $this->client->request('csm/interrupt', ['threadId' => $sessionRef]);
    }

    /**
     * Applies sticky model/effort settings to subsequent turns.
     * @return array<string,mixed>
     */
    public function update_settings(string $sessionRef, ?string $model = null, ?string $effort = null): array
    {
        $params = ['threadId' => $sessionRef];
        if ($model !== null && $model !== '') $params['model'] = $model;
        if ($effort !== null && $effort !== '') $params['effort'] = $effort;
        if (count($params) === 1) return ['ok' => false, 'message' => 'No Codex settings supplied'];
        $result = $this->client->request('thread/settings/update', $params);
        return $result['ok'] === true ? ['ok' => true, 'message' => 'Codex settings updated'] : $result;
    }

    public function pending_prompt(string $sessionRef): ?array
    {
        $result = $this->client->request('csm/pendingPrompt', ['threadId' => $sessionRef]);
        return $result['ok'] === true && is_array($result['prompt'] ?? null) ? $result['prompt'] : null;
    }

    public function answer_prompt(string $sessionRef, array $answers): array
    {
        return $this->client->request('csm/answerPrompt', ['threadId' => $sessionRef, 'answers' => $answers]);
    }
}
