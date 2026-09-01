#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Entry point for a single request. Invoked by systemd socket activation
 * (see systemd/sessioneer-agent.socket + sessioneer-agent@.service, Accept=yes) with
 * STDIN/STDOUT bound directly to the accepted connection: one JSON
 * request in, one JSON response out, then this process exits. No
 * networking code needed here - systemd owns the socket lifecycle.
 */

// A large file upload (see UploadController::upload() in src/lib/
// Controllers/, the container-side counterpart of this same fix) relays
// as base64 JSON over this socket - decoding it back to raw bytes
// (UploadService::save_uploaded_file()) needs the same headroom above the
// host's own default 128M memory_limit. Safe to raise unconditionally
// here, unlike on the container side: this is a fresh, single-connection
// process (systemd Accept=yes) that exits right after this one request,
// never a shared long-lived server handling other requests concurrently.
ini_set('memory_limit', '512M');

require __DIR__ . '/lib/Sessions.php';
require __DIR__ . '/lib/Push.php';

$input = stream_get_contents(STDIN);
$request = json_decode((string)$input, true);

if (!is_array($request) || !isset($request['action'])) {
    fwrite(STDOUT, json_encode(['ok' => false, 'message' => 'Malformed request']));
    exit(0);
}

// dispatch_push_action() (lib/Push.php) handles push_* actions, falling
// through (null) to dispatch_action() (lib/Sessions.php) for everything
// else - see dispatch_push_action()'s own doc comment for why these live
// in two separate dispatchers instead of one.
$response = dispatch_push_action($request) ?? dispatch_action($request);

fwrite(STDOUT, json_encode($response));
