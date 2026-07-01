#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Generic per-connection Unix socket harness, used by tests to stand in
 * for systemd's Accept=yes socket activation (StandardInput=socket /
 * StandardOutput=socket in csm-agent@.service) without needing systemd.
 *
 * Usage: php socket_harness.php <socket-path> <command...>
 *
 * Listens on <socket-path>; for every connection, runs <command...> with
 * the accepted stream bound directly to its stdin *and* stdout (proc_open
 * accepts an existing stream resource as a descriptor and dup()s it into
 * the child), then goes back to accepting. Runs until the parent test
 * kills this process (proc_terminate) once its assertions are done.
 */

[, $socketPath, ] = $argv;
$command = array_slice($argv, 2);

@unlink($socketPath);
$server = stream_socket_server('unix://' . $socketPath, $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "socket_harness: failed to listen on {$socketPath}: {$errstr}\n");
    exit(1);
}

while (true) {
    $conn = @stream_socket_accept($server, -1);

    if ($conn === false) {
        continue;
    }

    $process = proc_open($command, [0 => $conn, 1 => $conn, 2 => ['pipe', 'w']], $pipes);

    if (is_resource($process)) {
        fclose($pipes[2]);
        proc_close($process);
    }

    fclose($conn);
}
