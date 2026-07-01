#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Entry point for a single request. Invoked by systemd socket activation
 * (see systemd/csm-agent.socket + csm-agent@.service, Accept=yes) with
 * STDIN/STDOUT bound directly to the accepted connection: one JSON
 * request in, one JSON response out, then this process exits. No
 * networking code needed here - systemd owns the socket lifecycle.
 */

require __DIR__ . '/lib/Sessions.php';

$input = stream_get_contents(STDIN);
$request = json_decode((string)$input, true);

if (!is_array($request) || !isset($request['action'])) {
    fwrite(STDOUT, json_encode(['ok' => false, 'message' => 'Malformed request']));
    exit(0);
}

$response = dispatch_action($request);

fwrite(STDOUT, json_encode($response));
