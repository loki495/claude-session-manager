<?php
declare(strict_types=1);

require_once __DIR__ . '/assert.php';

/**
 * Starts tests/lib/socket_harness.php as a background subprocess listening
 * on $socketPath, running $command per connection. Blocks until the socket
 * file exists (or 2s elapses) so callers can connect immediately after.
 *
 * @param string[] $command
 * @return resource proc_open handle - pass to stop_harness() when done
 */
function start_harness(array $command, string $socketPath): mixed
{
    @unlink($socketPath);

    $harnessScript = __DIR__ . '/socket_harness.php';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['php', $harnessScript, $socketPath, ...$command], $descriptors, $pipes);

    if (!is_resource($process)) {
        fwrite(STDERR, "harness: failed to start\n");
        exit(1);
    }

    fclose($pipes[0]);

    $deadline = microtime(true) + 2.0;
    while (!file_exists($socketPath)) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "harness: socket {$socketPath} never appeared\n");
            exit(1);
        }
        usleep(10000);
    }

    return $process;
}

function stop_harness(mixed $process, string $socketPath): void
{
    proc_terminate($process);
    proc_close($process);
    @unlink($socketPath);
}
