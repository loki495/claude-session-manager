<?php
declare(strict_types=1);

/**
 * The container never touches tmux or the host process table directly.
 * It only knows how to speak a one-request-one-response JSON protocol
 * over a single UNIX socket, bind-mounted in from the host, where a
 * host-native systemd-activated agent (see host-agent/) does the actual
 * work. See README.md for why: tmux auto-starts a server as a child of
 * whichever process first talks to an unstarted socket, so that process
 * must always be a genuine host process, never this container.
 */

function agent_socket_path(): string
{
    $path = getenv('CSM_AGENT_SOCKET');
    return $path !== false && $path !== '' ? $path : '/run/csm-agent.sock';
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function agent_call(array $request): array
{
    $socket = @stream_socket_client('unix://' . agent_socket_path(), $errno, $errstr, 5);

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

/**
 * The "waiting on input" amber panel shown for a blocked session - shared
 * between index.php's list rows and session.php's detail view so the two
 * never drift apart.
 *
 * @param array{blocked_reason?:?string, resume_hint?:?string} $session
 */
function blocked_prompt_panel_html(array $session): string
{
    if (empty($session['blocked_reason'])) {
        return '';
    }

    $html = '<div class="mt-2 rounded-lg px-3 py-2 text-xs bg-amber-900/40 text-amber-200 border border-amber-700/60">';
    $html .= '<p class="font-medium">Waiting on input: ' . htmlspecialchars((string)$session['blocked_reason'], ENT_QUOTES) . '</p>';

    if (!empty($session['resume_hint'])) {
        $html .= '<p class="mt-1 text-amber-300/90">Attach to answer it:</p>';
        $html .= '<code class="block mt-0.5 font-mono text-[11px] text-amber-100 break-all select-all">' . htmlspecialchars((string)$session['resume_hint'], ENT_QUOTES) . '</code>';
    }

    $html .= '</div>';

    return $html;
}

function relative_time(int $timestamp): string
{
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'just now';
    }

    if ($diff < 3600) {
        $m = intdiv($diff, 60);
        return "{$m} min ago";
    }

    if ($diff < 86400) {
        $h = intdiv($diff, 3600);
        return "{$h} hr" . ($h > 1 ? 's' : '') . ' ago';
    }

    $d = intdiv($diff, 86400);
    return "{$d} day" . ($d > 1 ? 's' : '') . ' ago';
}
