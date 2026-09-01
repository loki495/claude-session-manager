<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Services\Config;

/** One-request/one-response client for Sessioneer's persistent Codex bridge. */
class CodexBridgeClient
{
    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function request(string $method, array $params = []): array
    {
        $socket = false;
        $error = '';
        $retryDelaysUsec = [0, 250000, 750000, 1500000];

        // Retry only connection establishment. Once any request bytes have
        // been written, replaying automatically could duplicate a turn or
        // approval. The bounded delay bridges systemd's ordinary short
        // restart window without making a permanently-down service hang the
        // web request indefinitely.
        foreach ($retryDelaysUsec as $delayUsec) {
            if ($delayUsec > 0) usleep($delayUsec);
            $socket = @stream_socket_client('unix://' . Config::codex_bridge_socket(), $errno, $error, 0.5);
            if ($socket !== false) break;
        }

        if ($socket === false) {
            return ['ok' => false, 'message' => "Cannot reach Codex bridge: {$error}"];
        }

        stream_set_timeout($socket, 30);
        $payload = json_encode(['method' => $method, 'params' => (object)$params], JSON_UNESCAPED_SLASHES);

        if ($payload === false || fwrite($socket, $payload . "\n") === false) {
            fclose($socket);
            return ['ok' => false, 'message' => 'Failed to write to Codex bridge'];
        }

        $line = fgets($socket);
        $meta = stream_get_meta_data($socket);
        fclose($socket);

        if ($line === false) {
            return ['ok' => false, 'message' => !empty($meta['timed_out']) ? 'Codex bridge timed out' : 'Codex bridge closed the connection'];
        }

        $decoded = json_decode($line, true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'message' => 'Codex bridge returned invalid JSON'];
    }
}
