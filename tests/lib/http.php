<?php
declare(strict_types=1);

/**
 * @param string[] $extraArgs extra curl args (e.g. ['-u', 'user:pass'], ['-d', 'a=b'])
 * @return array{status:int, headers:array<string,string>, body:string}
 */
function curl_request(string $method, string $url, array $extraArgs = []): array
{
    $cmd = array_merge(['curl', '-s', '-i', '--max-time', '5', '-X', $method], $extraArgs, [$url]);

    $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        return ['status' => 0, 'headers' => [], 'body' => ''];
    }

    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $parts = explode("\r\n\r\n", (string)$out, 2);
    $headerBlock = $parts[0] ?? '';
    $body = $parts[1] ?? '';

    $lines = explode("\r\n", $headerBlock);
    $statusLine = array_shift($lines) ?? '';
    $status = 0;

    if (preg_match('/^HTTP\/\S+\s+(\d+)/', $statusLine, $m) === 1) {
        $status = (int)$m[1];
    }

    $headers = [];

    foreach ($lines as $line) {
        if (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($key))] = trim($value);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}
