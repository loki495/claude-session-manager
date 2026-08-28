<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Services\Config;

/** One-request/one-response client for CSM's persistent Codex bridge. */
class CodexBridgeClient
{
    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function request(string $method, array $params = []): array
    {
        $socket = @stream_socket_client('unix://' . Config::codex_bridge_socket(), $errno, $error, 3.0);

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
