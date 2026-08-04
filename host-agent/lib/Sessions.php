<?php
declare(strict_types=1);

/**
 * All logic here runs natively on the host (invoked by systemd per
 * connection, see host-agent/agent.php) - never inside the Docker
 * container. That matters: tmux auto-starts a server as a child of
 * whichever process first talks to an unstarted socket, so the process
 * issuing tmux commands must always be a genuine host process. If the
 * container issued these calls directly, an accidental auto-started
 * server would run inside the container's own namespace and any spawned
 * claude process would be unreachable from the host.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/Transcript.php';

use HostAgent\Services\Config;

use HostAgent\Services\ProcessRunner;
use HostAgent\Services\TmuxService;
use HostAgent\Services\PromptParser;
use HostAgent\Services\ProcessInspector;

function sidecar_path(string $sessionName): string
{
    return Config::sidecar_dir() . '/' . $sessionName . '.json';
}

/**
 * @return array{workdir:?string, spawned_at:?int}|null
 */
function read_sidecar(string $sessionName): ?array
{
    $path = sidecar_path($sessionName);
    $raw = @file_get_contents($path);

    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

function write_sidecar(string $sessionName, array $data): void
{
    if (!is_dir(Config::sidecar_dir())) {
        @mkdir(Config::sidecar_dir(), 0700, true);
    }

    @file_put_contents(sidecar_path($sessionName), json_encode($data));
}

function delete_sidecar(string $sessionName): void
{
    @unlink(sidecar_path($sessionName));
}

/**
 * Suffixes (beyond plain "sessionName.json") every other kind of
 * session-keyed sidecar file uses - see pending_tool_path(). Kept as one
 * list so prune_orphaned_sidecars() only has one place to update when a
 * new sidecar kind is added.
 */
const SIDECAR_FILE_SUFFIXES = ['.pending-tool'];

/**
 * A session can die on its own (crash, host reboot, bad cwd) without ever
 * going through kill_cc_session(), leaving its sidecar file(s) behind on
 * tmpfs. Since this runs on every listing anyway, prune anything whose
 * session no longer exists rather than letting them accumulate.
 *
 * Globs every sidecar kind (plain sessionName.json, plus each suffixed
 * kind in SIDECAR_FILE_SUFFIXES) in one pass - the suffix is stripped
 * back off before the liveness check so a live session's own pending-tool
 * file is never mistaken for an orphan just because its filename doesn't
 * equal a session name verbatim.
 */
function prune_orphaned_sidecars(array $liveSessionNames): void
{
    foreach (glob(Config::sidecar_dir() . '/*.json') ?: [] as $path) {
        $base = basename($path, '.json');
        $name = $base;

        foreach (SIDECAR_FILE_SUFFIXES as $suffix) {
            if (str_ends_with($base, $suffix)) {
                $name = substr($base, 0, -strlen($suffix));
                break;
            }
        }

        if (!in_array($name, $liveSessionNames, true)) {
            @unlink($path);
        }
    }
}

function pending_tool_path(string $sessionName): string
{
    return Config::sidecar_dir() . '/' . $sessionName . '.pending-tool.json';
}

/**
 * The most recent PreToolUse hook payload recorded for this session (see
 * host-agent/hooks/pre_tool_use.php) - one file per session, always
 * overwritten by the latest tool call, never appended. Only meaningful
 * alongside a pane-detected blocking prompt (see
 * PromptParser::augment_prompt_with_pending_tool()); by itself this says nothing about
 * whether that tool call actually ended up needing approval.
 *
 * @return array{tool_name:?string, tool_input:?array, written_at:?int}|null
 */
function read_pending_tool(string $sessionName): ?array
{
    $raw = @file_get_contents(pending_tool_path($sessionName));

    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

function write_pending_tool(string $sessionName, array $data): void
{
    if (!is_dir(Config::sidecar_dir())) {
        @mkdir(Config::sidecar_dir(), 0700, true);
    }

    @file_put_contents(pending_tool_path($sessionName), json_encode($data));
}

function delete_pending_tool(string $sessionName): void
{
    @unlink(pending_tool_path($sessionName));
}

/**
 * Builds one cc-* session's list-row/detail data from already-fetched
 * process state - shared by list_all_sessions() (called once per tmux
 * session found) and session_detail() (called for exactly one, by name).
 *
 * @param array{name:string, activity:int, attached:bool} $tmuxSession
 * @param array<int, array{pid:int, cwd:?string, started_at:?int}> $claudeProcs
 * @param array<int, int> $ppidMap
 * @return array{name:string, activity:int, attached:bool, pid:?int, workdir:?string, spawned_by_csm:bool, title:?string, working:bool, blocked_reason:?string, resume_hint:?string, prompt_context:?string, prompt_options:array<int, array{number:int, label:string}>, prompt_multi_question:bool, prompt_is_folder_trust:bool, prompt_tool_name:?string, prompt_tool_input:?array, current_mode:?string, claude_session_id:?string, last_message:?array}
 */
function build_session_entry(array $tmuxSession, array $claudeProcs, array $ppidMap): array
{
    $panes = TmuxService::tmux_session_panes($tmuxSession['name']);
    $matchedPid = null;

    foreach ($claudeProcs as $proc) {
        foreach ($panes['pids'] as $panePid) {
            if (ProcessInspector::is_descendant($proc['pid'], $panePid, $ppidMap)) {
                $matchedPid = $proc['pid'];
                break 2;
            }
        }
    }

    $sidecar = read_sidecar($tmuxSession['name']);
    $paneContent = TmuxService::tmux_capture_pane($tmuxSession['name']);
    $prompt = PromptParser::parse_blocking_prompt($paneContent);

    if ($prompt !== null) {
        $prompt = PromptParser::augment_prompt_with_pending_tool($prompt, read_pending_tool($tmuxSession['name']));
    }

    $claudeSessionId = is_string($sidecar['claude_session_id'] ?? null) ? $sidecar['claude_session_id'] : null;

    return [
        'name' => $tmuxSession['name'],
        'activity' => $tmuxSession['activity'],
        'attached' => $tmuxSession['attached'],
        'pid' => $matchedPid,
        'workdir' => $sidecar['workdir'] ?? null,
        'spawned_by_csm' => $sidecar !== null,
        'title' => $panes['title'],
        'working' => $panes['working'],
        'blocked_reason' => $prompt['question'] ?? null,
        'resume_hint' => $prompt !== null ? TmuxService::tmux_attach_hint($tmuxSession['name']) : null,
        'prompt_context' => $prompt['context'] ?? null,
        'prompt_options' => $prompt['options'] ?? [],
        'prompt_multi_question' => $prompt['multi_question'] ?? false,
        'prompt_is_folder_trust' => $prompt['is_folder_trust'] ?? false,
        'prompt_tool_name' => $prompt['tool_name'] ?? null,
        'prompt_tool_input' => $prompt['tool_input'] ?? null,
        'current_mode' => PromptParser::parse_current_mode($paneContent),
        'claude_session_id' => $claudeSessionId,
        'last_message' => session_last_message($claudeSessionId),
    ];
}

/**
 * The single most recent transcript entry - used for the dashboard's
 * per-row preview, and to give a blocked prompt's card the message that
 * led up to it. That's worth doing specifically for the blocked case
 * because the pending tool_use itself usually ISN'T in the transcript
 * yet (Claude Code only writes it once it's approved and actually runs -
 * see prompt_context in PromptParser::parse_blocking_prompt() for the live-pane
 * alternative), but the assistant's own preceding explanation almost
 * always already is, written as its own separate line just before.
 *
 * @return array{role:?string, timestamp:?string, blocks:array<int, array{kind:string, text:string}>}|null
 */
function session_last_message(?string $claudeSessionId): ?array
{
    if ($claudeSessionId === null) {
        return null;
    }

    $path = find_transcript_path($claudeSessionId);

    if ($path === null) {
        return null;
    }

    $page = read_transcript_page($path, null, 1);

    if (!($page['ok'] ?? false) || empty($page['entries'])) {
        return null;
    }

    return $page['entries'][0];
}

/**
 * @return array{sessions: array<int, array>, bare: array<int, array>}
 */
function list_all_sessions(): array
{
    $tmuxSessions = TmuxService::list_cc_tmux_sessions();
    $claudeProcs = ProcessInspector::find_claude_processes();
    $ppidMap = ProcessInspector::build_ppid_map();

    prune_orphaned_sidecars(array_column($tmuxSessions, 'name'));

    $trackedPids = [];
    $sessions = [];

    foreach ($tmuxSessions as $session) {
        $entry = build_session_entry($session, $claudeProcs, $ppidMap);

        if ($entry['pid'] !== null) {
            $trackedPids[$entry['pid']] = true;
        }

        $sessions[] = $entry;
    }

    usort($sessions, fn(array $a, array $b) => $b['activity'] <=> $a['activity']);

    $allPanes = TmuxService::all_tmux_panes();
    $bare = [];

    foreach ($claudeProcs as $proc) {
        if (isset($trackedPids[$proc['pid']])) {
            continue;
        }

        $owningPane = ProcessInspector::find_owning_pane($proc['pid'], $allPanes, $ppidMap);

        $bare[] = $proc + [
            'tmux_session' => $owningPane['session'] ?? null,
            'title' => $owningPane['title'] ?? null,
        ];
    }

    usort($bare, fn(array $a, array $b) => ($b['started_at'] ?? 0) <=> ($a['started_at'] ?? 0));

    return ['sessions' => $sessions, 'bare' => $bare];
}

/**
 * Fresh, single-session snapshot for the detail page - re-derives
 * everything from a live tmux/proc scan by name rather than trusting
 * anything from the caller, same discipline as kill_cc_session()'s
 * whitelist re-check.
 *
 * @return array{ok:bool, message?:string, has_transcript?:bool}
 */
function session_detail(string $name): array
{
    $tmuxSession = null;

    foreach (TmuxService::list_cc_tmux_sessions() as $s) {
        if ($s['name'] === $name) {
            $tmuxSession = $s;
            break;
        }
    }

    if ($tmuxSession === null) {
        return ['ok' => false, 'message' => 'Session not found'];
    }

    $entry = build_session_entry($tmuxSession, ProcessInspector::find_claude_processes(), ProcessInspector::build_ppid_map());
    $transcriptPath = $entry['claude_session_id'] !== null ? find_transcript_path($entry['claude_session_id']) : null;

    return ['ok' => true] + $entry + ['has_transcript' => $transcriptPath !== null];
}

/**
 * @return array{ok:bool, entries?:array<int, array>, next_before?:?int, has_more?:bool, message?:string}
 */
function session_history(string $name, ?int $before, int $limit): array
{
    $sidecar = read_sidecar($name);
    $claudeSessionId = $sidecar['claude_session_id'] ?? null;

    if (!is_string($claudeSessionId)) {
        return ['ok' => false, 'message' => 'No transcript recorded for this session'];
    }

    $path = find_transcript_path($claudeSessionId);

    if ($path === null) {
        return ['ok' => false, 'message' => 'Transcript file not found'];
    }

    return read_transcript_page($path, $before, max(1, min($limit, 200)));
}

/**
 * PHP's JSON_PRETTY_PRINT always uses 4-space indent with no way to
 * configure it - re-indented to 2 spaces here to match how
 * ~/.claude/settings.json already looks (and how Claude Code itself
 * writes it), so installing the hook doesn't reformat every other line
 * in a file that isn't otherwise ours to restyle.
 */
function reindent_json_pretty(string $json): string
{
    $lines = explode("\n", $json);

    foreach ($lines as &$line) {
        if (preg_match('/^( +)/', $line, $m) === 1) {
            $line = str_repeat(' ', intdiv(strlen($m[1]), 2)) . substr($line, strlen($m[1]));
        }
    }

    return implode("\n", $lines);
}

/**
 * True if ~/.claude/settings.json already has a hook entry under $event
 * running the exact given command - checked by command string, not just
 * "a hook of this type exists", so a user's own unrelated hooks are never
 * mistaken for ours. Shared by session_start_hook_present() and
 * pre_tool_use_hook_present() below.
 *
 * @param array<string, mixed> $settings
 */
function hook_command_present(array $settings, string $event, string $command): bool
{
    $entries = $settings['hooks'][$event] ?? [];

    if (!is_array($entries)) {
        return false;
    }

    foreach ($entries as $matcherGroup) {
        $hooks = is_array($matcherGroup) ? ($matcherGroup['hooks'] ?? []) : [];

        foreach ((is_array($hooks) ? $hooks : []) as $hook) {
            if (is_array($hook) && ($hook['command'] ?? null) === $command) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $settings
 */
function session_start_hook_present(array $settings): bool
{
    return hook_command_present($settings, 'SessionStart', Config::session_start_hook_command());
}

/**
 * @param array<string, mixed> $settings
 */
function pre_tool_use_hook_present(array $settings): bool
{
    return hook_command_present($settings, 'PreToolUse', Config::pre_tool_use_hook_command());
}

/**
 * Every hook event + command this app installs - check_session_hook()/
 * install_session_hook() are entirely data-driven off this list, so
 * adding a new hook only ever needs one line added here (plus the new
 * script itself and its own *_hook_command()/*_hook_present() pair, kept
 * as real named functions rather than folded into this list too, since
 * tests and other call sites reference them directly by name).
 *
 * @return array<int, array{event:string, command:string, present:bool}>
 */
function app_hooks_status(array $settings): array
{
    return [
        ['event' => 'SessionStart', 'command' => Config::session_start_hook_command(), 'present' => session_start_hook_present($settings)],
        ['event' => 'PreToolUse', 'command' => Config::pre_tool_use_hook_command(), 'present' => pre_tool_use_hook_present($settings)],
    ];
}

/**
 * Reads ~/.claude/settings.json (if any) and reports whether every one of
 * this app's hooks (see app_hooks_status()) is already registered. A
 * missing file is a normal, expected "not set up yet" state, not an
 * error; a file that exists but fails to parse as JSON is an error, since
 * installing on top of it risks Claude Code refusing to start (or
 * install_session_hook() below refusing to touch it at all).
 *
 * @return array{ok:bool, installed:bool, message?:string}
 */
function check_session_hook(): array
{
    $raw = @file_get_contents(Config::claude_settings_path());

    if ($raw === false) {
        return ['ok' => true, 'installed' => false];
    }

    $settings = json_decode($raw, true);

    if (!is_array($settings)) {
        return ['ok' => false, 'installed' => false, 'message' => '~/.claude/settings.json exists but is not valid JSON'];
    }

    $allPresent = true;

    foreach (app_hooks_status($settings) as $hook) {
        if (!$hook['present']) {
            $allPresent = false;
            break;
        }
    }

    return ['ok' => true, 'installed' => $allPresent];
}

/**
 * Adds every missing app_hooks_status() entry to ~/.claude/settings.json,
 * creating the file if it doesn't exist yet. Never overwrites an existing
 * file that fails to parse - a blind reset-to-empty-then-write would
 * silently discard every other hook/setting Andres already has configured
 * there. Idempotent per hook: each is only added if not already present,
 * so this is safe to call from a "just make sure it's there" dashboard
 * button without a separate check first, and safe to re-run after only
 * some of them were ever installed.
 *
 * @return array{ok:bool, installed:bool, message?:string}
 */
function install_session_hook(): array
{
    $path = Config::claude_settings_path();
    $raw = @file_get_contents($path);
    $settings = [];

    if ($raw !== false) {
        $settings = json_decode($raw, true);

        if (!is_array($settings)) {
            return ['ok' => false, 'installed' => false, 'message' => '~/.claude/settings.json exists but is not valid JSON - fix or add the hooks manually, see README'];
        }
    }

    $missing = array_filter(app_hooks_status($settings), fn(array $hook): bool => !$hook['present']);

    if ($missing === []) {
        return ['ok' => true, 'installed' => true];
    }

    $settings['hooks'] ??= [];

    foreach ($missing as $hook) {
        $settings['hooks'][$hook['event']] ??= [];
        $settings['hooks'][$hook['event']][] = [
            'matcher' => '*',
            'hooks' => [
                ['type' => 'command', 'command' => $hook['command']],
            ],
        ];
    }

    $encoded = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($encoded === false) {
        return ['ok' => false, 'installed' => false, 'message' => 'Failed to encode updated settings'];
    }

    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0700, true);
    }

    if (@file_put_contents($path, reindent_json_pretty($encoded) . "\n") === false) {
        return ['ok' => false, 'installed' => false, 'message' => 'Could not write ~/.claude/settings.json'];
    }

    return ['ok' => true, 'installed' => true];
}

/**
 * A random (v4) UUID, RFC 4122 §4.4 - used as the --session-id passed to
 * `claude` at launch, so this app controls the id up front instead of
 * having to discover whatever Claude Code would have picked on its own.
 * That's what makes find_transcript_path() a plain glob instead of having
 * to reproduce Claude Code's own cwd -> directory-name encoding.
 */
function generate_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

/**
 * @return array{ok:bool, message:string}
 */
function create_cc_session(string $workdir): array
{
    if ($workdir === '' || $workdir[0] !== '/') {
        return ['ok' => false, 'message' => 'Working directory must be an absolute path'];
    }

    $name = 'cc-' . date('Ymd-Hi');
    $claudeSessionId = generate_uuid_v4();

    $result = TmuxService::tmux_run([
        // CSM_SESSION_NAME is how the SessionStart hook (see
        // host-agent/hooks/session_start.php) tells this pane's claude
        // process apart from any other on the box, so it knows which
        // sidecar to rebind when Claude Code rotates to a new session-id
        // transcript (/clear, /compact, --resume, --fork-session) without
        // this tmux pane itself ever restarting.
        'new-session', '-d', '-s', $name,
        '-c', $workdir,
        '-e', "CSM_SESSION_NAME={$name}",
        '-x', (string)Config::new_session_pane_width(),
        '-y', (string)Config::new_session_pane_height(),
        Config::claude_bin(), '--session-id', $claudeSessionId,
    ]);

    if ($result['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Failed to create session: ' . trim($result['stderr'])];
    }

    // tmux new-session returns success as soon as the session is
    // registered, before checking whether the pane's command actually
    // stayed running (e.g. bad cwd). Confirm it actually persisted.
    usleep(300000);

    $stillThere = in_array($name, array_column(TmuxService::list_cc_tmux_sessions(), 'name'), true);

    if (!$stillThere) {
        return [
            'ok' => false,
            'message' => "Session {$name} did not stay running - check the working directory is valid and the claude binary starts correctly",
        ];
    }

    write_sidecar($name, ['workdir' => $workdir, 'spawned_at' => time(), 'claude_session_id' => $claudeSessionId]);

    return ['ok' => true, 'message' => "Created session {$name} in {$workdir}"];
}

/**
 * $requested must exactly match a name from a freshly-fetched
 * TmuxService::list_cc_tmux_sessions() call made inside this same request.
 *
 * @return array{ok:bool, message:string}
 */
function kill_cc_session(string $requested): array
{
    $whitelist = array_column(TmuxService::list_cc_tmux_sessions(), 'name');

    if (!in_array($requested, $whitelist, true)) {
        return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
    }

    $result = TmuxService::tmux_run(['kill-session', '-t', $requested]);

    if ($result['exit'] !== 0) {
        return ['ok' => false, 'message' => "Failed to kill {$requested}: " . trim($result['stderr'])];
    }

    delete_sidecar($requested);
    delete_pending_tool($requested);

    return ['ok' => true, 'message' => "Killed {$requested}"];
}

/**
 * Answers a session's pending interactive prompt by sending the chosen
 * option's number followed by Enter - exactly what a human attached over
 * tmux would type. Re-validates immediately before sending, against a
 * fresh capture-pane, that the session is still live and that $option is
 * still actually one of the options currently on screen - not just "some
 * session with this name exists" - so a stale page (the prompt was
 * already answered, the session was killed, or a *different* prompt is
 * now showing) can't fire a keystroke at nobody. Never called
 * automatically anywhere in this app - only in direct response to a
 * human tapping a button that showed them this exact option's label.
 *
 * @return array{ok:bool, message:string}
 */
function answer_prompt(string $name, int $option): array
{
    if (!in_array($name, array_column(TmuxService::list_cc_tmux_sessions(), 'name'), true)) {
        return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
    }

    $prompt = PromptParser::parse_blocking_prompt(TmuxService::tmux_capture_pane($name));

    if ($prompt === null) {
        return ['ok' => false, 'message' => 'Rejected: this session is not currently waiting on a prompt'];
    }

    if (!in_array($option, array_column($prompt['options'], 'number'), true)) {
        return ['ok' => false, 'message' => 'Rejected: that option is not currently offered by this prompt'];
    }

    // Sent as two separate keys, not one send-keys call - verified live that
    // for an AskUserQuestion-style prompt, the digit only moves the on-screen
    // cursor (it doesn't auto-confirm), so an Enter sent in the same instant
    // can race ahead and confirm whatever was *previously* highlighted
    // instead. See TMUX_KEY_STEP_DELAY_USEC.
    $digitResult = TmuxService::tmux_run(['send-keys', '-t', $name, (string)$option]);

    if ($digitResult['exit'] !== 0) {
        return ['ok' => false, 'message' => "Failed to send response: " . trim($digitResult['stderr'])];
    }

    usleep(TMUX_KEY_STEP_DELAY_USEC);

    $enterResult = TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']);

    if ($enterResult['exit'] !== 0) {
        return ['ok' => false, 'message' => "Failed to send response: " . trim($enterResult['stderr'])];
    }

    // The pending-tool file (see read_pending_tool()) only ever describes
    // whatever's currently blocking - once this app itself has just
    // submitted the answer, it's guaranteed stale for any future prompt.
    delete_pending_tool($name);

    return ['ok' => true, 'message' => "Sent option {$option} to {$name}"];
}

/**
 * Answers a prompt's free-text option (Claude Code's AskUserQuestion
 * always offers one labeled "Type something.") with custom typed text,
 * instead of just the bare numbered choice. Verified live: selecting
 * that option by digit (without Enter) turns it into an inline text
 * field right there in the option list - typing replaces its label live,
 * and Enter submits whatever was typed. Declining to type anything
 * before pressing Enter is treated as skipping the question entirely,
 * which is why $text is required here and rejected empty, unlike
 * answer_prompt()'s plain numbered choice.
 *
 * @return array{ok:bool, message:string}
 */
function answer_prompt_with_text(string $name, int $option, string $text): array
{
    if (trim($text) === '') {
        return ['ok' => false, 'message' => 'Reply cannot be empty'];
    }

    if (!in_array($name, array_column(TmuxService::list_cc_tmux_sessions(), 'name'), true)) {
        return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
    }

    $prompt = PromptParser::parse_blocking_prompt(TmuxService::tmux_capture_pane($name));

    if ($prompt === null) {
        return ['ok' => false, 'message' => 'Rejected: this session is not currently waiting on a prompt'];
    }

    if (!in_array($option, array_column($prompt['options'], 'number'), true)) {
        return ['ok' => false, 'message' => 'Rejected: that option is not currently offered by this prompt'];
    }

    $digitResult = TmuxService::tmux_run(['send-keys', '-t', $name, (string)$option]);

    if ($digitResult['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Failed to select the free-text option: ' . trim($digitResult['stderr'])];
    }

    usleep(TMUX_KEY_STEP_DELAY_USEC);

    $set = TmuxService::tmux_run(['set-buffer', '--', $text]);

    if ($set['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Failed to stage reply: ' . trim($set['stderr'])];
    }

    $paste = TmuxService::tmux_run(['paste-buffer', '-t', $name]);

    if ($paste['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Failed to send reply: ' . trim($paste['stderr'])];
    }

    usleep(TMUX_KEY_STEP_DELAY_USEC);

    $enterResult = TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']);

    if ($enterResult['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Reply sent but failed to submit: ' . trim($enterResult['stderr'])];
    }

    delete_pending_tool($name);

    return ['ok' => true, 'message' => "Sent free-text reply to {$name}"];
}

/**
 * Moves between tabs in a multi-question AskUserQuestion prompt (Left =
 * previous question, Right = next / toward Submit) - the arrow-key
 * navigation a human would use while attached, sent the same way
 * answer_prompt() sends a numbered choice. Re-validates that the session
 * is still live and still actually showing a multi-question prompt right
 * before sending, same discipline as answer_prompt().
 *
 * @return array{ok:bool, message:string}
 */
function navigate_prompt(string $name, string $direction): array
{
    if (!in_array($direction, ['left', 'right'], true)) {
        return ['ok' => false, 'message' => 'Rejected: invalid direction'];
    }

    if (!in_array($name, array_column(TmuxService::list_cc_tmux_sessions(), 'name'), true)) {
        return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
    }

    $prompt = PromptParser::parse_blocking_prompt(TmuxService::tmux_capture_pane($name));

    if ($prompt === null || empty($prompt['multi_question'])) {
        return ['ok' => false, 'message' => 'Rejected: this session is not currently showing a multi-question prompt'];
    }

    $key = $direction === 'left' ? 'Left' : 'Right';
    $result = TmuxService::tmux_run(['send-keys', '-t', $name, $key]);

    if ($result['exit'] !== 0) {
        return ['ok' => false, 'message' => "Failed to navigate: " . trim($result['stderr'])];
    }

    return ['ok' => true, 'message' => "Sent {$key} to {$name}"];
}

/**
 * Interrupts whatever Claude is currently doing (mid-generation or
 * mid-tool-call), same as pressing Escape while attached - the "stop"
 * button. No pane-content check first (unlike navigate_prompt()/
 * set_mode(), which validate against a specific expected state): Escape
 * is a safe no-op if nothing is actually running, so there's nothing to
 * reject up front beyond "is this a real managed session at all".
 */
function send_escape(string $name): array
{
    if (!in_array($name, array_column(TmuxService::list_cc_tmux_sessions(), 'name'), true)) {
        return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
    }

    $result = TmuxService::tmux_run(['send-keys', '-t', $name, 'Escape']);

    if ($result['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Failed to send Escape: ' . trim($result['stderr'])];
    }

    return ['ok' => true, 'message' => "Sent Escape to {$name}"];
}

/**
 * A 300ms gap between rapid, related keypresses sent to a live Claude
 * Code pane - not cosmetic, verified live twice over: (1) 3 BTab presses
 * with no gap between them landed 2 steps short (a key got dropped), 300ms
 * between each was reliable every time; (2) selecting an AskUserQuestion
 * option by digit moves the on-screen cursor but doesn't confirm it - a
 * same-instant follow-up Enter can still be processed against the *old*
 * cursor position, confirming the wrong option, unless there's a real
 * gap first. Used by set_mode() (between BTab presses) and answer_prompt()
 * (between the digit and the Enter that confirms it).
 */
const TMUX_KEY_STEP_DELAY_USEC = 300000;

function set_mode(string $name, string $targetMode): array
{
    if (!array_key_exists($targetMode, PromptParser::CLAUDE_CODE_MODE_STATUS_PHRASES)) {
        return ['ok' => false, 'message' => 'Rejected: not a recognized mode'];
    }

    if (!in_array($name, array_column(TmuxService::list_cc_tmux_sessions(), 'name'), true)) {
        return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
    }

    $currentMode = PromptParser::parse_current_mode(TmuxService::tmux_capture_pane($name));

    if ($currentMode === null) {
        return ['ok' => false, 'message' => 'Rejected: current mode is not readable right now (a prompt may be covering the status line)'];
    }

    $modes = array_keys(PromptParser::CLAUDE_CODE_MODE_STATUS_PHRASES);
    $steps = (array_search($targetMode, $modes, true) - array_search($currentMode, $modes, true) + count($modes)) % count($modes);

    for ($i = 0; $i < $steps; $i++) {
        if ($i > 0) {
            usleep(TMUX_KEY_STEP_DELAY_USEC);
        }

        $result = TmuxService::tmux_run(['send-keys', '-t', $name, 'BTab']);

        if ($result['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Failed to set mode: ' . trim($result['stderr'])];
        }
    }

    return ['ok' => true, 'message' => "Set mode for {$name} to {$targetMode}"];
}

/**
 * Sends a free-text message to a session, exactly as if a human had
 * typed it while attached, then pressed Enter to submit - the actual,
 * intended point of this whole app (remote-controlling a session, same
 * as attaching from the iOS app). Uses a tmux paste-buffer, not
 * send-keys with the raw text as a "key": send-keys treats embedded
 * newlines in a multi-line message as individual Enter keypresses, each
 * prematurely submitting whatever's been typed so far, where a real
 * terminal paste delivers the whole block as one unit (verified live)
 * and only the explicit trailing Enter submits it.
 *
 * @return array{ok:bool, message:string}
 */
function send_message(string $name, string $text): array
{
    if (trim($text) === '') {
        return ['ok' => false, 'message' => 'Message cannot be empty'];
    }

    if (!in_array($name, array_column(TmuxService::list_cc_tmux_sessions(), 'name'), true)) {
        return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
    }

    $set = TmuxService::tmux_run(['set-buffer', '--', $text]);

    if ($set['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Failed to stage message: ' . trim($set['stderr'])];
    }

    $paste = TmuxService::tmux_run(['paste-buffer', '-t', $name]);

    if ($paste['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Failed to send message: ' . trim($paste['stderr'])];
    }

    $enter = TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']);

    if ($enter['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Message sent but failed to submit: ' . trim($enter['stderr'])];
    }

    return ['ok' => true, 'message' => "Sent message to {$name}"];
}

/**
 * @return array{ok:bool, killed:string[], failed:string[]}
 */
function cleanup_inactive_sessions(): array
{
    $now = time();
    $killed = [];
    $failed = [];

    foreach (TmuxService::list_cc_tmux_sessions() as $session) {
        if (($now - $session['activity']) <= Config::cleanup_threshold_seconds()) {
            continue;
        }

        $result = TmuxService::tmux_run(['kill-session', '-t', $session['name']]);

        if ($result['exit'] === 0) {
            delete_sidecar($session['name']);
            $killed[] = $session['name'];
        } else {
            $failed[] = $session['name'];
        }
    }

    return ['ok' => empty($failed), 'killed' => $killed, 'failed' => $failed];
}

/**
 * Kills a "bare" claude process (one ProcessInspector::find_claude_processes() found running
 * on the host that isn't inside a cc-* session this tool manages) by pid.
 * $pid is re-scanned against a fresh ProcessInspector::find_claude_processes() rather than
 * trusted from the caller, so a stale or reused pid can't be used to kill
 * an unrelated process. If the pid lives inside some other tmux session
 * (one not named cc-*, e.g. created by hand), the whole session is killed
 * for a clean shutdown of that pane; otherwise SIGTERM is sent directly.
 *
 * @return array{ok:bool, message:string}
 */
function kill_bare_process(int $pid): array
{
    $stillRunning = false;

    foreach (ProcessInspector::find_claude_processes() as $proc) {
        if ($proc['pid'] === $pid) {
            $stillRunning = true;
            break;
        }
    }

    if (!$stillRunning) {
        return ['ok' => false, 'message' => 'Rejected: not a currently running claude process'];
    }

    $owningPane = ProcessInspector::find_owning_pane($pid, TmuxService::all_tmux_panes(), ProcessInspector::build_ppid_map());

    if ($owningPane !== null) {
        $result = TmuxService::tmux_run(['kill-session', '-t', $owningPane['session']]);

        return $result['exit'] === 0
            ? ['ok' => true, 'message' => "Killed tmux session {$owningPane['session']} (pid {$pid})"]
            : ['ok' => false, 'message' => "Failed to kill session {$owningPane['session']}: " . trim($result['stderr'])];
    }

    $result = ProcessRunner::run_process(['kill', '-TERM', (string)$pid]);

    return $result['exit'] === 0
        ? ['ok' => true, 'message' => "Sent SIGTERM to pid {$pid}"]
        : ['ok' => false, 'message' => "Failed to kill pid {$pid}: " . trim($result['stderr'])];
}

/**
 * Lists the immediate subdirectories of $path (hidden ones included), for
 * the New Session folder browser - lets a session start anywhere under the
 * home directory, not just under Config::www_root(). $path (after resolving symlinks)
 * must be Config::home_root() itself or a descendant of it; anything else is
 * rejected rather than letting the browser wander into the rest of the
 * filesystem. An empty $path defaults to Config::www_root(), the common case,
 * rather than Config::home_root() itself - the browser can still walk up to
 * Config::home_root() from there via the returned `parent`.
 *
 * @return array{ok:bool, path?:string, parent?:?string, dirs?:string[], message?:string}
 */
function browse_dir(string $path): array
{
    $root = Config::home_root();
    $realRoot = realpath($root);

    if ($realRoot === false) {
        return ['ok' => false, 'message' => 'Home directory is not configured correctly on the host'];
    }

    $real = realpath($path !== '' ? $path : Config::www_root());

    if ($real === false || !is_dir($real) || ($real !== $realRoot && !str_starts_with($real . '/', $realRoot . '/'))) {
        return ['ok' => false, 'message' => 'Path is outside the home directory'];
    }

    $dirs = [];

    foreach (scandir($real) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (is_dir($real . '/' . $entry)) {
            $dirs[] = $entry;
        }
    }

    sort($dirs, SORT_STRING | SORT_FLAG_CASE);

    return [
        'ok' => true,
        'path' => $real,
        'parent' => $real === $realRoot ? null : dirname($real),
        'dirs' => $dirs,
    ];
}

/**
 * Uploads for a session are kept inside its OWN project working dir
 * under .claude/uploads/ (Andres's own suggestion) rather than some
 * app-managed location elsewhere - that way an uploaded file's path is
 * naturally already something Claude Code can read directly (relative
 * to its own cwd) without needing to be told about an app-specific
 * location, and .claude/ already reads as tooling-owned rather than
 * real project content (likely already gitignored in most projects that
 * use Claude Code at all).
 */
function uploads_dir(string $workdir): string
{
    return rtrim($workdir, '/') . '/.claude/uploads';
}

/**
 * A self-contained .gitignore ("*") inside the uploads dir itself,
 * rather than touching the project's own root .gitignore - found live
 * testing this feature that .claude/ is NOT reliably already gitignored
 * (checked this very repo: it wasn't), so without this an uploaded file
 * would show up as untracked in `git status` in any project that hasn't
 * already excluded .claude/ itself. Self-healing: called on every save
 * (cheap - just an is_file() check in the common case), not only when
 * the directory is first created, so it survives a delete_all wiping
 * the directory back to empty.
 */
function ensure_uploads_gitignore(string $dir): void
{
    $path = $dir . '/.gitignore';

    if (!is_file($path)) {
        @file_put_contents($path, "*\n");
    }
}

/**
 * Resolves a session name to its known project working directory - the
 * same sidecar-backed value build_session_entry() exposes as 'workdir'
 * elsewhere, fetched directly here since uploads only ever need this one
 * field. Only ever set for app-spawned sessions (see write_sidecar() in
 * create_cc_session()) - a bare/manually-attached session has no sidecar
 * and so no known workdir, same limitation every other workdir-dependent
 * feature in this app already has.
 */
function session_workdir(string $name): ?string
{
    $sidecar = read_sidecar($name);
    $workdir = $sidecar['workdir'] ?? null;

    return is_string($workdir) && $workdir !== '' ? $workdir : null;
}

/**
 * Matches the upload_max_filesize/post_max_size raised in docker-
 * compose.yml's php.ini override - an independent, friendlier-error
 * check rather than relying solely on PHP silently truncating/rejecting
 * an oversized request.
 */
function max_upload_bytes(): int
{
    return (int)Config::csm_config('MAX_UPLOAD_BYTES', (string)(25 * 1024 * 1024));
}

/**
 * Strips any directory components and control characters from a
 * client-supplied filename down to a safe basename - a client could
 * send "../../etc/passwd" or similar as the filename field.
 */
function sanitize_upload_filename(string $filename): string
{
    $base = basename(trim($filename));
    $base = preg_replace('/[\x00-\x1f]/', '', $base) ?? $base;
    $base = ltrim($base, '.'); // no leading dot - avoid a hidden file, or matching '.'/'..' after stripping

    return $base !== '' ? $base : 'upload';
}

/**
 * Appends a numeric suffix before the extension until the name no
 * longer collides with an existing file - never silently overwrites an
 * earlier upload that happens to share a name.
 */
function unique_upload_filename(string $dir, string $filename): string
{
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $candidate = $filename;
    $i = 1;

    while (file_exists($dir . '/' . $candidate)) {
        $candidate = $ext !== '' ? "{$base}-{$i}.{$ext}" : "{$base}-{$i}";
        $i++;
    }

    return $candidate;
}

/**
 * @return array{ok:bool, message?:string, filename?:string, path?:string, size?:int}
 */
function save_uploaded_file(string $sessionName, string $filename, string $base64Content): array
{
    $workdir = session_workdir($sessionName);

    if ($workdir === null) {
        return ['ok' => false, 'message' => 'Unknown working directory for this session'];
    }

    $decoded = base64_decode($base64Content, true);

    if ($decoded === false) {
        return ['ok' => false, 'message' => 'Malformed upload data'];
    }

    if (strlen($decoded) > max_upload_bytes()) {
        return ['ok' => false, 'message' => 'File too large (max ' . intdiv(max_upload_bytes(), 1024 * 1024) . 'MB)'];
    }

    $dir = uploads_dir($workdir);

    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return ['ok' => false, 'message' => 'Could not create the uploads directory'];
    }

    ensure_uploads_gitignore($dir);

    $finalName = unique_upload_filename($dir, sanitize_upload_filename($filename));

    if (@file_put_contents($dir . '/' . $finalName, $decoded) === false) {
        return ['ok' => false, 'message' => 'Failed to write the uploaded file'];
    }

    return [
        'ok' => true,
        'filename' => $finalName,
        'path' => '.claude/uploads/' . $finalName,
        'size' => strlen($decoded),
    ];
}

/**
 * @return array{ok:bool, message?:string, files?:array<int, array{name:string, size:int, mtime:int}>, total_size?:int}
 */
function list_uploaded_files(string $sessionName): array
{
    $workdir = session_workdir($sessionName);

    if ($workdir === null) {
        return ['ok' => false, 'message' => 'Unknown working directory for this session'];
    }

    $dir = uploads_dir($workdir);

    if (!is_dir($dir)) {
        return ['ok' => true, 'files' => [], 'total_size' => 0];
    }

    $files = [];
    $totalSize = 0;

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.gitignore') {
            continue;
        }

        $full = $dir . '/' . $entry;

        if (!is_file($full)) {
            continue;
        }

        $size = filesize($full);
        $size = $size !== false ? $size : 0;

        $files[] = ['name' => $entry, 'size' => $size, 'mtime' => filemtime($full) ?: 0];
        $totalSize += $size;
    }

    usort($files, fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

    return ['ok' => true, 'files' => $files, 'total_size' => $totalSize];
}

/**
 * Resolves $filename against the uploads dir with a realpath boundary
 * check (same discipline as browse_dir()) - basename() alone already
 * stops plain "../" traversal in the filename itself, but not e.g. a
 * symlink planted inside the uploads dir pointing elsewhere.
 */
function resolve_upload_path(string $workdir, string $filename): ?string
{
    $dir = uploads_dir($workdir);
    $realDir = realpath($dir);

    if ($realDir === false) {
        return null;
    }

    $real = realpath($dir . '/' . basename($filename));

    if ($real === false || !str_starts_with($real, $realDir . '/')) {
        return null;
    }

    return $real;
}

/**
 * @return array{ok:bool, message?:string}
 */
function delete_uploaded_file(string $sessionName, string $filename): array
{
    if (basename($filename) === '.gitignore') {
        return ['ok' => false, 'message' => 'File not found']; // internal bookkeeping, not a real upload - same not-found response as any other name that isn't a real uploaded file, no need to expose that this one's special
    }

    $workdir = session_workdir($sessionName);

    if ($workdir === null) {
        return ['ok' => false, 'message' => 'Unknown working directory for this session'];
    }

    $real = resolve_upload_path($workdir, $filename);

    if ($real === null || !is_file($real)) {
        return ['ok' => false, 'message' => 'File not found'];
    }

    if (!@unlink($real)) {
        return ['ok' => false, 'message' => 'Failed to delete the file'];
    }

    return ['ok' => true];
}

/**
 * @return array{ok:bool, message?:string, deleted?:int}
 */
function delete_all_uploaded_files(string $sessionName): array
{
    $workdir = session_workdir($sessionName);

    if ($workdir === null) {
        return ['ok' => false, 'message' => 'Unknown working directory for this session'];
    }

    $dir = uploads_dir($workdir);

    if (!is_dir($dir)) {
        return ['ok' => true, 'deleted' => 0];
    }

    $deleted = 0;

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.gitignore') {
            continue;
        }

        $full = $dir . '/' . $entry;

        if (is_file($full) && @unlink($full)) {
            $deleted++;
        }
    }

    return ['ok' => true, 'deleted' => $deleted];
}

/**
 * claude-quota's "Resets" text is whatever Claude Code's own /usage panel
 * prints - either a bare time ("3pm", presumed to be the next occurrence
 * of that time - today unless that's already passed, then tomorrow) or a
 * dated time ("Jul 10, 8pm"), always followed by a parenthesized IANA
 * timezone. Converts that into an absolute unix timestamp so the frontend
 * can render a live "resets in Xh Ym" countdown instead of a fixed string
 * that goes stale the moment it's rendered.
 */
function parse_resets_at(string $resets, int $now): ?int
{
    if (preg_match('/^(.*)\s\(([^)]+)\)$/', $resets, $m) !== 1) {
        return null;
    }

    $timePart = trim($m[1]);
    $tzName = trim($m[2]);
    $hasDate = preg_match('/^[A-Za-z]{3}\s+\d{1,2}\b/', $timePart) === 1;

    // Normalize a bare "8pm" to "8:00pm" - PHP's parser otherwise misreads
    // the hour as a timezone abbreviation when a date precedes it (e.g.
    // "Jul 10 8pm"), and strip the comma between date and time for the
    // same reason.
    $normalized = preg_replace('/(?<!:)\b(\d{1,2})([ap]m)\b/i', '$1:00$2', str_replace(',', '', $timePart));

    try {
        $dt = new DateTime((string)$normalized, new DateTimeZone($tzName));
    } catch (Throwable) {
        return null;
    }

    if (!$hasDate && $dt->getTimestamp() <= $now) {
        $dt->modify('+1 day');
    }

    return $dt->getTimestamp();
}

/**
 * @param array<string, mixed> $quota
 * @return array<string, mixed>
 */
function enrich_quota_resets(array $quota, int $now): array
{
    foreach ($quota as $key => $bucket) {
        if (!is_array($bucket) || !isset($bucket['resets']) || !is_string($bucket['resets'])) {
            continue;
        }

        $resetsAt = parse_resets_at($bucket['resets'], $now);

        if ($resetsAt !== null) {
            $quota[$key]['resets_at'] = $resetsAt;
        }
    }

    return $quota;
}

/**
 * Claude Code's own status line already shows both rate-limit percentages
 * this app cares about, e.g. "... | ctx: 4% | 5h: 51% (1h 53m) | 7d: 40%
 * (5d 8h) ..." - "5h" is the rolling 5-hour window (labeled "session" to
 * match claude-quota's own key), "7d" the weekly one (labeled "week_all").
 * Verified live 2026-08-02 against claude-quota's own real scrape at
 * nearly the same moment: matching percentages (51% / ~41%). Only
 * "ctx" (context-window usage, not account quota - deliberately not
 * parsed here) is guaranteed present from the very first prompt; "5h"/"7d"
 * only appear once Claude Code has actually made an API call in that
 * pane, so a fresh welcome-screen pane with nothing sent yet won't match.
 *
 * @return array{session:array{pct:int,resets:string},week_all:array{pct:int,resets:string}}|null
 */
function parse_quota_from_pane(string $paneContent): ?array
{
    if (preg_match('/5h:\s*(\d+)%\s*\(([^)]+)\)/u', $paneContent, $sessionMatch) !== 1) {
        return null;
    }

    if (preg_match('/7d:\s*(\d+)%\s*\(([^)]+)\)/u', $paneContent, $weekMatch) !== 1) {
        return null;
    }

    return [
        'session' => ['pct' => (int)$sessionMatch[1], 'resets' => trim($sessionMatch[2])],
        'week_all' => ['pct' => (int)$weekMatch[1], 'resets' => trim($weekMatch[2])],
    ];
}

/**
 * Parses a short duration like "1h 53m" or "5d 8h" - exactly the shape
 * Claude Code's status line shows next to each percentage (see
 * parse_quota_from_pane()) - into seconds. Distinct from
 * parse_resets_at(), which parses a full clock-time-plus-timezone string
 * instead (what claude-quota's slower /usage-panel scrape produces).
 */
function parse_footer_duration(string $duration): ?int
{
    if (preg_match('/^(?:(\d+)d)?\s*(?:(\d+)h)?\s*(?:(\d+)m)?$/u', trim($duration), $m) !== 1) {
        return null;
    }

    $seconds = ((int)($m[1] ?? 0)) * 86400 + ((int)($m[2] ?? 0)) * 3600 + ((int)($m[3] ?? 0)) * 60;

    return $seconds > 0 ? $seconds : null;
}

/**
 * Tries every currently-live managed tmux session's pane for the
 * status-line quota shape (see parse_quota_from_pane()) and returns the
 * first one found - a single capture-pane call per live session, no
 * scraping subprocess, so this is near-instant compared to
 * run_claude_quota() below. Rate limits are account-wide, not per-session,
 * so it doesn't matter which live session's pane happens to answer first.
 * Returns null if no live session's pane currently shows quota (no
 * sessions running at all, or every one is still on its pre-first-message
 * welcome screen) - callers should fall back to run_claude_quota()'s
 * cached reading in that case.
 *
 * @return array{quota:array, fetched_at:int}|null
 */
function quota_from_live_pane(): ?array
{
    foreach (TmuxService::list_cc_tmux_sessions() as $tmuxSession) {
        $parsed = parse_quota_from_pane(TmuxService::tmux_capture_pane($tmuxSession['name']));

        if ($parsed === null) {
            continue;
        }

        $now = time();
        $quota = $parsed;

        foreach ($quota as $key => $bucket) {
            $seconds = parse_footer_duration($bucket['resets']);

            if ($seconds !== null) {
                $quota[$key]['resets_at'] = $now + $seconds;
            }
        }

        $quota['captured_at'] = date('c', $now);

        return ['quota' => $quota, 'fetched_at' => $now];
    }

    return null;
}

/**
 * Runs the claude-quota script (a wrapper that scrapes Claude Code's own
 * /usage panel via a detached screen session - see the script itself for
 * the mechanism). This is slow, 10-40s, since it drives a real TUI, so it
 * must only ever be called in the background (see trigger_background_quota_refresh()),
 * never inline while a request is waiting. Only still reached (via
 * get_quota()) when no live session's pane already shows quota (see
 * quota_from_live_pane()) - e.g. no sessions currently running at all.
 *
 * @return array{ok:bool, quota?:array, message?:string}
 */
function run_claude_quota(): array
{
    $result = ProcessRunner::run_process(['timeout', (string)Config::quota_timeout_seconds(), Config::claude_quota_bin()]);

    if ($result['exit'] !== 0) {
        $message = trim($result['stderr']) !== ''
            ? trim($result['stderr'])
            : "claude-quota exited with code {$result['exit']}";

        return ['ok' => false, 'message' => $message];
    }

    $decoded = json_decode($result['stdout'], true);

    if (!is_array($decoded)) {
        return ['ok' => false, 'message' => 'claude-quota returned malformed JSON'];
    }

    return ['ok' => true, 'quota' => enrich_quota_resets($decoded, time())];
}

/**
 * @return array{quota:array, fetched_at:int}|null
 */
function read_quota_cache(): ?array
{
    $raw = @file_get_contents(Config::quota_cache_file());

    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded) || !isset($decoded['quota'], $decoded['fetched_at']) || !is_array($decoded['quota'])) {
        return null;
    }

    return ['quota' => $decoded['quota'], 'fetched_at' => (int)$decoded['fetched_at']];
}

function write_quota_cache(array $quota, int $fetchedAt): void
{
    $dir = dirname(Config::quota_cache_file());

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    @file_put_contents(Config::quota_cache_file(), json_encode(['quota' => $quota, 'fetched_at' => $fetchedAt]));
}

function quota_refresh_marker_file(): string
{
    return Config::quota_cache_file() . '.refreshing';
}

/**
 * A refresh marker younger than the scrape timeout means some earlier
 * request already spawned a background refresh that's presumably still
 * running - don't spawn a second one. A marker older than the timeout is
 * treated as abandoned (the process that wrote it crashed, or the host
 * rebooted, without cleaning up) rather than blocking refreshes forever.
 */
function quota_refresh_in_flight(): bool
{
    $raw = @file_get_contents(quota_refresh_marker_file());

    if ($raw === false) {
        return false;
    }

    return (time() - (int)trim($raw)) < Config::quota_timeout_seconds();
}

/**
 * Atomically claims the right to spawn a refresh: fopen(..., 'x') is
 * O_CREAT|O_EXCL, which fails if the marker already exists. That's the
 * part that actually prevents a race - e.g. two browser tabs (or two
 * quick page reloads) both hitting /quota.php within the same instant
 * would otherwise both see "no marker yet" from a plain
 * file_exists()-then-write check and both spawn a scrape. With an
 * exclusive create, only one of them can ever win.
 */
function claim_quota_refresh_marker(): bool
{
    $handle = @fopen(quota_refresh_marker_file(), 'x');

    if ($handle === false) {
        return false;
    }

    fwrite($handle, (string)time());
    fclose($handle);

    return true;
}

/**
 * Fires a fully detached background process that runs the slow
 * claude-quota scrape and writes the result to the cache file itself, so
 * the request that triggered this can return immediately instead of
 * waiting on it. Stdio is bound to /dev/null via proc_open's 'file'
 * descriptor type (not pipes) specifically so the child has nothing tied
 * to this short-lived agent.php connection process - it keeps running
 * fine after this process has already sent its response and exited.
 *
 * @return bool true if a refresh is (now, or already) in flight
 */
function trigger_background_quota_refresh(): bool
{
    $dir = dirname(quota_refresh_marker_file());

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    if (!claim_quota_refresh_marker()) {
        // Someone else's marker is already there. If it's fresh, that
        // refresh is genuinely in flight - nothing more to do. If it's
        // stale (abandoned by a crashed process), reclaim it once; if
        // even that loses a race to another request doing the same
        // thing, defer to whichever of us won rather than double-spawning.
        if (quota_refresh_in_flight()) {
            return true;
        }

        @unlink(quota_refresh_marker_file());

        if (!claim_quota_refresh_marker()) {
            return true;
        }
    }

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ];

    $process = @proc_open([PHP_BINARY, __DIR__ . '/../quota_refresh.php'], $descriptors, $pipes);

    if (!is_resource($process)) {
        @unlink(quota_refresh_marker_file());
        return false;
    }

    // Deliberately not proc_close()'d - that blocks the caller until the
    // child exits, defeating the entire point of backgrounding this.
    return true;
}

/**
 * Cached in front of run_claude_quota() since that's expensive (spins up a
 * real `claude` TUI in a screen session just to read a percentage) and
 * always non-blocking: a stale/missing cache triggers a background
 * refresh (see trigger_background_quota_refresh()) and returns immediately
 * with whatever's cached (marked "stale") rather than making the request
 * wait 10-40s for a fresh scrape.
 *
 * @return array{ok:bool, quota:?array, fetched_at:?int, cached:bool, stale:bool, refreshing:bool, message?:string}
 */
function get_quota(): array
{
    $live = quota_from_live_pane();

    if ($live !== null) {
        return [
            'ok' => true,
            'quota' => $live['quota'],
            'fetched_at' => $live['fetched_at'],
            'cached' => false,
            'stale' => false,
            'refreshing' => false,
        ];
    }

    $ttl = Config::quota_cache_ttl_seconds();
    $cache = read_quota_cache();
    $now = time();
    $fresh = $cache !== null && ($now - $cache['fetched_at']) < $ttl;

    if ($fresh) {
        return [
            'ok' => true,
            'quota' => $cache['quota'],
            'fetched_at' => $cache['fetched_at'],
            'cached' => true,
            'stale' => false,
            'refreshing' => false,
        ];
    }

    $refreshing = trigger_background_quota_refresh();

    if ($cache !== null) {
        return [
            'ok' => true,
            'quota' => $cache['quota'],
            'fetched_at' => $cache['fetched_at'],
            'cached' => true,
            'stale' => true,
            'refreshing' => $refreshing,
        ];
    }

    return [
        'ok' => $refreshing,
        'quota' => null,
        'fetched_at' => null,
        'cached' => false,
        'stale' => false,
        'refreshing' => $refreshing,
        'message' => $refreshing
            ? 'Fetching quota for the first time - this can take up to a minute'
            : 'Could not start quota refresh',
    ];
}

/**
 * @return array
 */
function dispatch_action(array $request): array
{
    switch ($request['action'] ?? '') {
        case 'list':
            return ['ok' => true] + list_all_sessions();

        case 'session_detail':
            return session_detail((string)($request['session'] ?? ''));

        case 'session_history':
            return session_history(
                (string)($request['session'] ?? ''),
                isset($request['before']) ? (int)$request['before'] : null,
                isset($request['limit']) ? (int)$request['limit'] : 30
            );

        case 'create':
            return create_cc_session((string)($request['workdir'] ?? ''));

        case 'kill':
            return kill_cc_session((string)($request['session'] ?? ''));

        case 'kill_bare':
            return kill_bare_process((int)($request['pid'] ?? 0));

        case 'answer_prompt':
            return answer_prompt((string)($request['session'] ?? ''), (int)($request['option'] ?? 0));

        case 'answer_prompt_with_text':
            return answer_prompt_with_text((string)($request['session'] ?? ''), (int)($request['option'] ?? 0), (string)($request['text'] ?? ''));

        case 'navigate_prompt':
            return navigate_prompt((string)($request['session'] ?? ''), (string)($request['direction'] ?? ''));

        case 'send_escape':
            return send_escape((string)($request['session'] ?? ''));

        case 'send_message':
            return send_message((string)($request['session'] ?? ''), (string)($request['text'] ?? ''));

        case 'set_mode':
            return set_mode((string)($request['session'] ?? ''), (string)($request['mode'] ?? ''));

        case 'cleanup':
            return cleanup_inactive_sessions();

        case 'browse_dir':
            return browse_dir((string)($request['path'] ?? ''));

        case 'quota':
            return get_quota();

        case 'check_session_hook':
            return check_session_hook();

        case 'install_session_hook':
            return install_session_hook();

        case 'save_uploaded_file':
            return save_uploaded_file(
                (string)($request['session'] ?? ''),
                (string)($request['filename'] ?? ''),
                (string)($request['content_base64'] ?? '')
            );

        case 'list_uploaded_files':
            return list_uploaded_files((string)($request['session'] ?? ''));

        case 'delete_uploaded_file':
            return delete_uploaded_file((string)($request['session'] ?? ''), (string)($request['filename'] ?? ''));

        case 'delete_all_uploaded_files':
            return delete_all_uploaded_files((string)($request['session'] ?? ''));

        default:
            return ['ok' => false, 'message' => 'Unknown action'];
    }
}
