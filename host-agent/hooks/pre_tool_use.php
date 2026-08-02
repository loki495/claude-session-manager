#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Registered as Claude Code's PreToolUse hook (see install_session_hook()
 * in ../lib/Sessions.php, and the README) - fires right before every tool
 * call, whether or not it ends up needing user approval, and before any
 * approval prompt is shown. Its only job is to record the full,
 * untruncated tool_name/tool_input JSON to a per-session sidecar file
 * (see write_pending_tool()), so build_session_entry() can show a pending
 * permission prompt's real full command/file content instead of whatever
 * tmux's rendered pane happens to have room to display (see
 * BLOCKING_PROMPT_CONTEXT_WINDOW / TMUX_PANE_WIDTH/HEIGHT for the
 * pane-scraping fallback this complements, not replaces).
 *
 * Deliberately writes nothing to stdout and always exits 0 - Claude Code
 * treats that as "no opinion", leaving its own permission decision
 * completely untouched. This hook only ever observes.
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

if (!is_array($payload)) {
    exit(0);
}

$toolName = is_string($payload['tool_name'] ?? null) ? $payload['tool_name'] : null;
$toolInput = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : null;

if ($toolName === null || $toolInput === null) {
    exit(0);
}

write_pending_tool($sessionName, [
    'tool_name' => $toolName,
    'tool_input' => $toolInput,
    'written_at' => time(),
]);
