<?php

declare(strict_types=1);

namespace App;

/**
 * The container never touches tmux or the host process table directly.
 * It only knows how to speak a one-request-one-response JSON protocol
 * over a single UNIX socket, bind-mounted in from the host, where a
 * host-native systemd-activated agent (see host-agent/) does the actual
 * work. See README.md for why: tmux auto-starts a server as a child of
 * whichever process first talks to an unstarted socket, so that process
 * must always be a genuine host process, never this container.
 */
class AgentClient
{
    public static function agent_socket_path(): string
    {
        $path = getenv('CSM_AGENT_SOCKET');
        return $path !== false && $path !== '' ? $path : '/run/csm-agent.sock';
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function agent_call(array $request): array
    {
        $socket = @stream_socket_client('unix://' . self::agent_socket_path(), $errno, $errstr, 5);

        if ($socket === false) {
            return [
                'ok' => false,
                'message' => "Cannot reach host agent ({$errstr}). Is the csm-agent.socket systemd unit running on the host?",
            ];
        }

        fwrite($socket, json_encode($request));
        stream_socket_shutdown($socket, STREAM_SHUT_WR);

        $raw = stream_get_contents($socket);
        fclose($socket);

        $decoded = json_decode((string)$raw, true);

        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'Malformed response from host agent'];
        }

        return $decoded;
    }
}
