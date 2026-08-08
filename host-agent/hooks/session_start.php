#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Registered as Claude Code's SessionStart hook (see HookService::install_session_hook()
 * in ../lib/Sessions.php, and the README) - fires on every session start,
 * including /clear, /compact, --resume, and --fork-session, each of which
 * rotates Claude Code's own transcript to a brand new session-id file
 * while staying in the same tmux pane/process. Without this, a session's
 * sidecar (which records claude_session_id exactly once, at spawn) goes
 * stale the moment any of those happen, and the app silently keeps
 * reading an abandoned, no-longer-growing transcript forever after.
 *
 * Two ways a session gets tracked here, in priority order:
 *
 * 1. CSM_SESSION_NAME (set via `tmux new-session -e` in create_cc_session())
 *    - this app's own spawned pane. Only ever REBINDS an existing sidecar,
 *    never creates one - create_cc_session() already wrote it before this
 *    hook could ever fire, so a missing one here means the session was
 *    genuinely killed/cleaned up already (see the bail-out below), not a
 *    session worth resurrecting a sidecar for.
 *
 * 2. Any OTHER claude session running inside a real tmux pane (Andres
 *    started it by hand, in a pane this app never touched) - adopted by
 *    asking that pane's own tmux server "what session are you" (`tmux
 *    display-message -p '#S'`, resolved from the TMUX env var this hook
 *    inherits as a child of that pane's own shell, same as any other
 *    process running inside it) and keying a BRAND NEW sidecar off that
 *    real tmux session name, first-seen. This is the whole "unify tracked
 *    and bare sessions" mechanism - once a sidecar exists, build_session_
 *    entry() can find its transcript and every other feature just works
 *    the same as for a cc-* session.
 *
 * A claude process with NO tmux pane at all (bare terminal/SSH, no
 * wrapper) is left alone entirely in BOTH cases - it can never get send-
 * keys/capture-pane support regardless of a sidecar existing, and still
 * shows up in the archived-sessions listing once its transcript exists on
 * disk (a plain directory scan, no sidecar involved) - so there's nothing
 * a sidecar would actually buy it. Keeping tmux out of the picture
 * entirely for that case is deliberate, not an oversight.
 */

require __DIR__ . '/../lib/Sessions.php';

use HostAgent\Services\TmuxService;
use HostAgent\Stores\SidecarStore;

$input = stream_get_contents(STDIN);
$payload = json_decode((string)$input, true);
$claudeSessionId = is_array($payload) ? ($payload['session_id'] ?? null) : null;

if (!is_string($claudeSessionId) || $claudeSessionId === '') {
    exit(0);
}

$spawnedByCsm = getenv('CSM_SESSION_NAME');
$createIfMissing = false;

if (is_string($spawnedByCsm) && $spawnedByCsm !== '') {
    $sessionName = $spawnedByCsm;
} else {
    if (getenv('TMUX') === false) {
        exit(0); // no tmux pane at all - nothing to key an adopted sidecar by
    }

    $paneSession = TmuxService::tmux_run(['display-message', '-p', '#S']);
    $sessionName = $paneSession['exit'] === 0 ? trim($paneSession['stdout']) : '';

    // Never trust this as a bare filename - a sidecar is one file per
    // session name (see SidecarStore::sidecar_path()), so anything but a
    // plain name is refused rather than risking it resolving outside the
    // sidecar directory.
    if ($sessionName === '' || $sessionName === '.' || $sessionName === '..' || str_contains($sessionName, '/')) {
        exit(0);
    }

    $createIfMissing = true;
}

$existingSidecar = SidecarStore::read_sidecar($sessionName);

if ($existingSidecar === null && !$createIfMissing) {
    exit(0); // session already killed/cleaned up since this hook fired - nothing to rebind
}

// cwd: prefers the hook's own payload field (Claude Code sends this on
// every hook invocation), falls back to this process's own getcwd() -
// claude itself was launched with the real project dir as its cwd, and
// this hook inherits that same cwd as its child, so either source lands
// on the same real path.
$cwd = is_array($payload) && is_string($payload['cwd'] ?? null) && $payload['cwd'] !== '' ? $payload['cwd'] : (getcwd() ?: null);

SidecarStore::write_sidecar($sessionName, [
    'workdir' => $existingSidecar['workdir'] ?? $cwd,
    'spawned_at' => $existingSidecar['spawned_at'] ?? time(),
    'claude_session_id' => $claudeSessionId,
    // Lets the dashboard tell "this app spawned the pane" apart from "this
    // app adopted a pane Andres started by hand" without re-deriving it
    // from the session name later (e.g. to decide whether Kill should
    // just tear down a pane this app made, versus needing more care for
    // one Andres is also using directly outside the app).
    'spawned_by_csm' => $existingSidecar['spawned_by_csm'] ?? (is_string($spawnedByCsm) && $spawnedByCsm !== ''),
]);
