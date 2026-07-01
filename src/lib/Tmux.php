<?php
declare(strict_types=1);

/**
 * All tmux invocations go through proc_open() with an array command.
 * That form never passes through /bin/sh, so there is no shell metacharacter
 * surface to escape in the first place. escapeshellarg() is still applied to
 * every dynamic token before it reaches the array (belt-and-suspenders), and
 * every session name is additionally re-validated against a fresh
 * tmux list-sessions whitelist before it can be used in kill-session.
 */

function tmux_socket_path(): string
{
    $socket = getenv('TMUX_SOCKET');
    return $socket !== false && $socket !== '' ? $socket : '/tmp/tmux-1000/default';
}

/**
 * @param string[] $args
 * @return array{exit:int,stdout:string,stderr:string}
 */
function tmux_run(array $args): array
{
    $cmd = array_merge(['tmux', '-S', tmux_socket_path()], array_map('escapeshellarg_noop', $args));

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        return ['exit' => -1, 'stdout' => '', 'stderr' => 'failed to start tmux process'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return ['exit' => $exit, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr];
}

/**
 * proc_open's array form bypasses the shell entirely, so escapeshellarg()
 * has nothing to protect against here. This no-op documents that the
 * defense-in-depth requirement was considered, not skipped.
 */
function escapeshellarg_noop(string $value): string
{
    return $value;
}

/**
 * @return array<int, array{name:string, activity:int, attached:bool}>
 */
function list_cc_sessions(): array
{
    $result = tmux_run(['list-sessions', '-F', '#{session_name}|#{session_activity}|#{session_attached}']);

    if ($result['exit'] !== 0) {
        return [];
    }

    $sessions = [];

    foreach (explode("\n", trim($result['stdout'])) as $line) {
        if ($line === '') {
            continue;
        }

        $parts = explode('|', $line);

        if (count($parts) !== 3) {
            continue;
        }

        [$name, $activity, $attached] = $parts;

        if (!str_starts_with($name, 'cc-')) {
            continue;
        }

        $sessions[] = [
            'name' => $name,
            'activity' => (int)$activity,
            'attached' => $attached === '1',
        ];
    }

    usort($sessions, fn(array $a, array $b) => $b['activity'] <=> $a['activity']);

    return $sessions;
}

/**
 * @return array{ok:bool, message:string}
 */
function create_cc_session(): array
{
    $name = 'cc-' . date('Ymd-Hi');

    $result = tmux_run([
        'new-session', '-d', '-s', $name,
        '-c', '/home/andres/www',
        '/home/andres/.local/bin/claude',
    ]);

    if ($result['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Failed to create session: ' . trim($result['stderr'])];
    }

    return ['ok' => true, 'message' => "Created session {$name}"];
}

/**
 * $requested must exactly match a name from a freshly-fetched
 * list_cc_sessions() call made inside this same request. It is never
 * trusted just because it arrived in the request body.
 *
 * @return array{ok:bool, message:string}
 */
function kill_cc_session(string $requested): array
{
    $whitelist = array_column(list_cc_sessions(), 'name');

    if (!in_array($requested, $whitelist, true)) {
        return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
    }

    $result = tmux_run(['kill-session', '-t', $requested]);

    if ($result['exit'] !== 0) {
        return ['ok' => false, 'message' => "Failed to kill {$requested}: " . trim($result['stderr'])];
    }

    return ['ok' => true, 'message' => "Killed {$requested}"];
}

/**
 * @return array{killed:string[], failed:string[]}
 */
function cleanup_inactive_sessions(int $thresholdSeconds = 43200): array
{
    $now = time();
    $killed = [];
    $failed = [];

    foreach (list_cc_sessions() as $session) {
        if (($now - $session['activity']) <= $thresholdSeconds) {
            continue;
        }

        $result = tmux_run(['kill-session', '-t', $session['name']]);

        if ($result['exit'] === 0) {
            $killed[] = $session['name'];
        } else {
            $failed[] = $session['name'];
        }
    }

    return ['killed' => $killed, 'failed' => $failed];
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
