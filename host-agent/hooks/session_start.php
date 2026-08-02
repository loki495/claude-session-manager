#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Registered as Claude Code's SessionStart hook (see install_session_hook()
 * in ../lib/Sessions.php, and the README) - fires on every session start,
 * including /clear, /compact, --resume, and --fork-session, each of which
 * rotates Claude Code's own transcript to a brand new session-id file
 * while staying in the same tmux pane/process. Without this, a session's
 * sidecar (which records claude_session_id exactly once, at spawn) goes
 * stale the moment any of those happen, and the app silently keeps
 * reading an abandoned, no-longer-growing transcript forever after.
 *
 * CSM_SESSION_NAME (set via `tmux new-session -e` in create_cc_session())
 * is how this app's own tmux-spawned sessions are told apart from any
 * other claude process on the box - inherited by this hook script as a
 * child process of that same pane's claude. Anything without it (a plain
 * claude session Andres started by hand) is a deliberate no-op.
 */

require __DIR__ . '/../lib/Sessions.php';

$sessionName = getenv('CSM_SESSION_NAME');

if ($sessionName === false || $sessionName === '') {
    exit(0);
}

$input = stream_get_contents(STDIN);
$payload = json_decode((string)$input, true);
$claudeSessionId = is_array($payload) ? ($payload['session_id'] ?? null) : null;

if (!is_string($claudeSessionId) || $claudeSessionId === '') {
    exit(0);
}

$sidecar = read_sidecar($sessionName);

if ($sidecar === null) {
    exit(0); // session already killed/cleaned up since this hook fired - nothing to rebind
}

write_sidecar($sessionName, [
    'workdir' => $sidecar['workdir'] ?? null,
    'spawned_at' => $sidecar['spawned_at'] ?? time(),
    'claude_session_id' => $claudeSessionId,
]);
