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

require_once __DIR__ . '/Transcript.php';

const CLK_TCK = 100; // USER_HZ has been 100 on Linux/x86_64 since the 2.6 era

/**
 * Host-specific paths/thresholds, overridable via env (see
 * host-agent/.env.example, loaded by systemd's EnvironmentFile= in
 * production) so tests can point at an isolated tmux socket and a fixture
 * claude binary instead of the real host session. Falls back to the real
 * production values when unset.
 */
function csm_config(string $key, string $default): string
{
    $value = getenv($key);
    return $value !== false && $value !== '' ? $value : $default;
}

function claude_bin(): string
{
    return csm_config('CLAUDE_BIN', '/home/andres/.local/bin/claude');
}

function www_root(): string
{
    return csm_config('WWW_ROOT', '/home/andres/www');
}

function home_root(): string
{
    return csm_config('HOME_ROOT', '/home/andres');
}

function tmux_socket(): string
{
    return csm_config('TMUX_SOCKET', '/tmp/tmux-1000/default');
}

function sidecar_dir(): string
{
    return csm_config('SIDECAR_DIR', '/run/user/1000/csm-sessions');
}

function cleanup_threshold_seconds(): int
{
    return (int)csm_config('CLEANUP_THRESHOLD_SECONDS', '43200'); // 12h
}

function claude_quota_bin(): string
{
    return csm_config('CLAUDE_QUOTA_BIN', '/home/andres/dotfiles/bin/claude-quota');
}

function quota_cache_file(): string
{
    return csm_config('QUOTA_CACHE_FILE', '/run/user/1000/csm-agent-quota-cache.json');
}

function quota_cache_ttl_seconds(): int
{
    return (int)csm_config('QUOTA_CACHE_TTL_SECONDS', '300'); // 5min
}

function quota_timeout_seconds(): int
{
    return (int)csm_config('QUOTA_TIMEOUT_SECONDS', '90');
}

/**
 * @param string[] $cmd
 * @return array{exit:int,stdout:string,stderr:string}
 */
function run_process(array $cmd): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        return ['exit' => -1, 'stdout' => '', 'stderr' => 'failed to start process'];
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
 * tmux only auto-creates its socket's parent directory when using its own
 * default naming ($TMPDIR/tmux-$UID); since this app always passes an
 * explicit -S path, tmux instead expects that directory to already exist
 * and fails outright if it doesn't. /tmp is wiped on every host reboot,
 * and nothing else recreates this directory afterward - so without this,
 * every session-create attempt fails until someone notices and mkdirs it
 * by hand. Cheap enough (an is_dir check) to just do on every call.
 */
function ensure_tmux_socket_dir(): void
{
    $dir = dirname(tmux_socket());

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
}

/**
 * @param string[] $args
 * @return array{exit:int,stdout:string,stderr:string}
 */
function tmux_run(array $args): array
{
    ensure_tmux_socket_dir();

    return run_process(array_merge(['tmux', '-S', tmux_socket()], $args));
}

/**
 * @return array<int, array{name:string, activity:int, attached:bool}>
 */
function list_cc_tmux_sessions(): array
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

    return $sessions;
}

/**
 * @return array{pids:int[], title:?string} pane pids and the first pane's
 * title, belonging to the given tmux session
 */
function tmux_session_panes(string $session): array
{
    $result = tmux_run(['list-panes', '-t', $session, '-s', '-F', '#{pane_pid}|#{pane_title}']);

    if ($result['exit'] !== 0) {
        return ['pids' => [], 'title' => null];
    }

    $pids = [];
    $title = null;

    foreach (explode("\n", trim($result['stdout'])) as $line) {
        if ($line === '') {
            continue;
        }

        [$pid, $paneTitle] = array_pad(explode('|', $line, 2), 2, '');
        $pids[] = (int)$pid;

        if ($title === null) {
            $title = clean_pane_title($paneTitle);
        }
    }

    return ['pids' => $pids, 'title' => $title];
}

/**
 * Claude Code sets the terminal title to a short description of the
 * current task, prefixed with an animated braille spinner glyph while
 * actively working (e.g. "⠂ Fix login bug") - tmux captures this as
 * pane_title via the standard OSC title escape sequence, no special tmux
 * config needed. Strips the spinner so only the description remains; an
 * empty/spinner-only title (nothing set yet, or a non-Claude process)
 * returns null so callers can fall back to the session name.
 */
function clean_pane_title(string $title): ?string
{
    $stripped = preg_replace('/^[\x{2800}-\x{28FF}]+\s*/u', '', $title);
    $title = trim($stripped ?? $title);

    return $title !== '' ? $title : null;
}

/**
 * The current, visible content of a tmux pane - used to detect an
 * interactive prompt Claude Code is waiting on (see
 * detect_blocking_prompt()) that a headless tmux session will otherwise
 * just sit in forever, since there's no human attached to answer it.
 */
function tmux_capture_pane(string $session): string
{
    $result = tmux_run(['capture-pane', '-t', $session, '-p']);

    return $result['exit'] === 0 ? $result['stdout'] : '';
}

/**
 * Claude Code renders every interactive choice it needs a human for -
 * the "Do you trust the files in this folder?" prompt on first launch in
 * a new directory, tool-permission approval, etc. - as a numbered option
 * list with a leading "❯" cursor on the selected line. That marker is
 * stable across prompt wording/versions, so it's used as the detection
 * signal rather than matching specific prompt text. Returns the nearest
 * question-like line above the option list (if one is found within a few
 * lines) as a short human-readable reason, or a generic fallback.
 */
/**
 * Number of pane lines above the choice list to consider as context - a
 * fixed window rather than trying to detect the exact top of the prompt
 * (a bordered box for tool-permission previews, plain text for the trust
 * dialog, and other shapes over time). Calibrated against two real
 * captures: the trust dialog's own explanation fits in ~8 lines, and a
 * Bash-permission prompt's tool name + command + description fits in
 * ~10; 15 comfortably covers both without dragging in a full screen's
 * worth of unrelated earlier scrollback.
 */
const BLOCKING_PROMPT_CONTEXT_WINDOW = 15;

/**
 * True for a line that's purely decorative box-drawing/rule characters
 * (─│╭╮╰╯┌┐└┘┏┓┗┛━┃ etc.) and whitespace - carries no information of its
 * own, so it's dropped from context rather than shown as a bare line of
 * dashes.
 */
function is_decorative_pane_line(string $line): bool
{
    return trim($line) !== '' && preg_match('/^[\s\x{2500}-\x{257F}\x{2580}-\x{259F}]*$/u', $line) === 1;
}

/**
 * The full parse behind detect_blocking_prompt(): a short single-line
 * label, the fuller surrounding context (the tool call/command/trust-dialog
 * explanation actually being approved - not just a bare "proceed?"), and
 * every numbered option offered, so a caller can render real
 * Approve/Deny-style buttons with enough information to make an informed
 * choice, not just a blind rubber stamp.
 *
 * $question used to require a line ending in "?" within a narrow window,
 * but real prompts wrap their question across lines (verified against a
 * live capture of Claude Code's own trust dialog, where the "?" lands
 * mid-line, not at the end of any single one) - it now looks for a "?"
 * anywhere in a line and truncates there, falling back to the nearest
 * non-blank context line, and only to a generic label if there's truly
 * nothing above the choice list at all.
 *
 * @return array{question:string, context:string, options: array<int, array{number:int, label:string}>}|null
 */
function parse_blocking_prompt(string $paneContent): ?array
{
    $lines = explode("\n", $paneContent);
    $choiceIndex = null;

    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*❯\s*\d+[.)]/u', $line) === 1) {
            $choiceIndex = $i;
            break;
        }
    }

    if ($choiceIndex === null) {
        return null;
    }

    $start = max(0, $choiceIndex - BLOCKING_PROMPT_CONTEXT_WINDOW);
    $contextLines = array_map('rtrim', array_slice($lines, $start, $choiceIndex - $start));
    $contextLines = array_values(array_filter($contextLines, fn(string $l) => !is_decorative_pane_line($l)));

    while ($contextLines !== [] && trim($contextLines[0]) === '') {
        array_shift($contextLines);
    }

    while ($contextLines !== [] && trim(end($contextLines)) === '') {
        array_pop($contextLines);
    }

    $context = preg_replace('/\n{3,}/', "\n\n", implode("\n", $contextLines)) ?? implode("\n", $contextLines);

    $question = null;

    for ($i = count($contextLines) - 1; $i >= 0 && $question === null; $i--) {
        $line = trim($contextLines[$i]);
        $qPos = $line !== '' ? strpos($line, '?') : false;

        if ($qPos !== false) {
            $question = substr($line, 0, $qPos + 1);
        }
    }

    if ($question === null) {
        for ($i = count($contextLines) - 1; $i >= 0 && $question === null; $i--) {
            $line = trim($contextLines[$i]);

            if ($line !== '') {
                $question = $line;
            }
        }
    }

    $options = [];

    for ($i = $choiceIndex; $i < count($lines); $i++) {
        if (preg_match('/^\s*❯?\s*(\d+)[.)]\s*(.+?)\s*$/u', $lines[$i], $m) !== 1) {
            break;
        }

        $options[] = ['number' => (int)$m[1], 'label' => $m[2]];
    }

    return [
        'question' => $question ?? 'Waiting on an interactive prompt (permission or trust dialog)',
        'context' => $context,
        'options' => $options,
    ];
}

function detect_blocking_prompt(string $paneContent): ?string
{
    return parse_blocking_prompt($paneContent)['question'] ?? null;
}

/**
 * The exact command to attach to a session from the host and answer
 * whatever it's waiting on - shown alongside the blocked_reason warning
 * so approving it is a copy-paste away instead of a guessing game. Never
 * sent automatically: blindly injecting an "approve" keystroke could just
 * as easily rubber-stamp a destructive tool call nobody's actually looked
 * at.
 */
function tmux_attach_hint(string $sessionName): string
{
    return 'tmux -S ' . tmux_socket() . ' attach -t ' . $sessionName;
}

/**
 * @return array{pid:int,ppid:int}[] keyed by pid
 */
function build_ppid_map(): array
{
    $map = [];

    foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $procDir) {
        $pid = (int)basename($procDir);
        $stat = @file_get_contents("$procDir/stat");

        if ($stat === false) {
            continue;
        }

        $rparen = strrpos($stat, ')');

        if ($rparen === false) {
            continue;
        }

        $fields = preg_split('/\s+/', trim(substr($stat, $rparen + 1))) ?: [];

        // $stat fields are 1-indexed in `man proc`; after splitting off
        // "pid (comm) ", $fields[0] is field 3 (state), $fields[1] is
        // field 4 (ppid), $fields[19] is field 22 (starttime).
        if (isset($fields[1])) {
            $map[$pid] = (int)$fields[1];
        }
    }

    return $map;
}

function process_start_time(int $pid): ?int
{
    $stat = @file_get_contents("/proc/$pid/stat");

    if ($stat === false) {
        return null;
    }

    $rparen = strrpos($stat, ')');

    if ($rparen === false) {
        return null;
    }

    $fields = preg_split('/\s+/', trim(substr($stat, $rparen + 1))) ?: [];

    if (!isset($fields[19])) {
        return null;
    }

    $startTicks = (int)$fields[19];
    $uptimeRaw = @file_get_contents('/proc/uptime');

    if ($uptimeRaw === false) {
        return null;
    }

    $uptime = (float)explode(' ', trim($uptimeRaw))[0];
    $bootEpoch = time() - (int)$uptime;

    return $bootEpoch + intdiv($startTicks, CLK_TCK);
}

function is_descendant(int $pid, int $ancestorPid, array $ppidMap, int $maxDepth = 25): bool
{
    $current = $pid;

    for ($i = 0; $i < $maxDepth; $i++) {
        if ($current === $ancestorPid) {
            return true;
        }

        if (!isset($ppidMap[$current]) || $ppidMap[$current] === 0) {
            return false;
        }

        $current = $ppidMap[$current];
    }

    return false;
}

/**
 * Scans /proc for every real `claude` process on the host, regardless of
 * whether it was started by this tool, another tmux session, or by hand
 * in a plain terminal. argv[0] is matched rather than /proc/pid/exe,
 * because claude re-execs into a versioned binary under
 * ~/.local/share/claude/versions/*, so exe changes on every update while
 * the launcher path in argv stays stable.
 *
 * @return array{pid:int, cwd:?string, started_at:?int}[]
 */
function find_claude_processes(): array
{
    $procs = [];

    foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $procDir) {
        $pid = (int)basename($procDir);
        $cmdlineRaw = @file_get_contents("$procDir/cmdline");

        if ($cmdlineRaw === false || $cmdlineRaw === '') {
            continue;
        }

        $argv = explode("\0", rtrim($cmdlineRaw, "\0"));

        // Match argv[0] specifically, not "appears anywhere in argv": the
        // tmux server process that auto-starts to run `new-session ...
        // /home/andres/.local/bin/claude` retains that whole command line
        // as its own argv, which would otherwise false-positive-match the
        // tmux server itself as a bare claude process.
        if (($argv[0] ?? null) !== claude_bin()) {
            continue;
        }

        $procs[] = [
            'pid' => $pid,
            'cwd' => @readlink("$procDir/cwd") ?: null,
            'started_at' => process_start_time($pid),
        ];
    }

    return $procs;
}

/**
 * Every pane on the host, across every tmux session regardless of name -
 * unlike tmux_session_panes(), which only ever looks inside one cc-*
 * session. Used to enrich "bare" claude processes (ones find_claude_processes()
 * found but that aren't inside a cc-* session this tool manages) with a
 * session name/title when they happen to live in some other, manually
 * created tmux session instead of a plain terminal.
 *
 * @return array<int, array{session:string, title:?string}> keyed by pane_pid
 */
function all_tmux_panes(): array
{
    $result = tmux_run(['list-panes', '-a', '-F', '#{session_name}|#{pane_pid}|#{pane_title}']);

    if ($result['exit'] !== 0) {
        return [];
    }

    $panes = [];

    foreach (explode("\n", trim($result['stdout'])) as $line) {
        if ($line === '') {
            continue;
        }

        [$session, $pid, $title] = array_pad(explode('|', $line, 3), 3, '');
        $panes[(int)$pid] = ['session' => $session, 'title' => clean_pane_title($title)];
    }

    return $panes;
}

/**
 * Finds the tmux pane (if any, from an already-fetched all_tmux_panes()
 * map) that $pid runs under, by walking its ancestry same as the cc-*
 * matching in list_all_sessions() does.
 *
 * @param array<int, array{session:string, title:?string}> $allPanes
 * @return array{session:string, title:?string}|null
 */
function find_owning_pane(int $pid, array $allPanes, array $ppidMap): ?array
{
    foreach ($allPanes as $panePid => $pane) {
        if (is_descendant($pid, $panePid, $ppidMap)) {
            return $pane;
        }
    }

    return null;
}

function sidecar_path(string $sessionName): string
{
    return sidecar_dir() . '/' . $sessionName . '.json';
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
    if (!is_dir(sidecar_dir())) {
        @mkdir(sidecar_dir(), 0700, true);
    }

    @file_put_contents(sidecar_path($sessionName), json_encode($data));
}

function delete_sidecar(string $sessionName): void
{
    @unlink(sidecar_path($sessionName));
}

/**
 * A session can die on its own (crash, host reboot, bad cwd) without ever
 * going through kill_cc_session(), leaving its sidecar file behind on
 * tmpfs. Since this runs on every listing anyway, prune anything whose
 * session no longer exists rather than letting them accumulate.
 */
function prune_orphaned_sidecars(array $liveSessionNames): void
{
    foreach (glob(sidecar_dir() . '/*.json') ?: [] as $path) {
        $name = basename($path, '.json');

        if (!in_array($name, $liveSessionNames, true)) {
            @unlink($path);
        }
    }
}

/**
 * Builds one cc-* session's list-row/detail data from already-fetched
 * process state - shared by list_all_sessions() (called once per tmux
 * session found) and session_detail() (called for exactly one, by name).
 *
 * @param array{name:string, activity:int, attached:bool} $tmuxSession
 * @param array<int, array{pid:int, cwd:?string, started_at:?int}> $claudeProcs
 * @param array<int, int> $ppidMap
 * @return array{name:string, activity:int, attached:bool, pid:?int, workdir:?string, spawned_by_csm:bool, title:?string, blocked_reason:?string, resume_hint:?string, prompt_context:?string, prompt_options:array<int, array{number:int, label:string}>, claude_session_id:?string}
 */
function build_session_entry(array $tmuxSession, array $claudeProcs, array $ppidMap): array
{
    $panes = tmux_session_panes($tmuxSession['name']);
    $matchedPid = null;

    foreach ($claudeProcs as $proc) {
        foreach ($panes['pids'] as $panePid) {
            if (is_descendant($proc['pid'], $panePid, $ppidMap)) {
                $matchedPid = $proc['pid'];
                break 2;
            }
        }
    }

    $sidecar = read_sidecar($tmuxSession['name']);
    $prompt = parse_blocking_prompt(tmux_capture_pane($tmuxSession['name']));

    return [
        'name' => $tmuxSession['name'],
        'activity' => $tmuxSession['activity'],
        'attached' => $tmuxSession['attached'],
        'pid' => $matchedPid,
        'workdir' => $sidecar['workdir'] ?? null,
        'spawned_by_csm' => $sidecar !== null,
        'title' => $panes['title'],
        'blocked_reason' => $prompt['question'] ?? null,
        'resume_hint' => $prompt !== null ? tmux_attach_hint($tmuxSession['name']) : null,
        'prompt_context' => $prompt['context'] ?? null,
        'prompt_options' => $prompt['options'] ?? [],
        'claude_session_id' => is_string($sidecar['claude_session_id'] ?? null) ? $sidecar['claude_session_id'] : null,
    ];
}

/**
 * @return array{sessions: array<int, array>, bare: array<int, array>}
 */
function list_all_sessions(): array
{
    $tmuxSessions = list_cc_tmux_sessions();
    $claudeProcs = find_claude_processes();
    $ppidMap = build_ppid_map();

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

    $allPanes = all_tmux_panes();
    $bare = [];

    foreach ($claudeProcs as $proc) {
        if (isset($trackedPids[$proc['pid']])) {
            continue;
        }

        $owningPane = find_owning_pane($proc['pid'], $allPanes, $ppidMap);

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

    foreach (list_cc_tmux_sessions() as $s) {
        if ($s['name'] === $name) {
            $tmuxSession = $s;
            break;
        }
    }

    if ($tmuxSession === null) {
        return ['ok' => false, 'message' => 'Session not found'];
    }

    $entry = build_session_entry($tmuxSession, find_claude_processes(), build_ppid_map());
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

    $result = tmux_run([
        'new-session', '-d', '-s', $name,
        '-c', $workdir,
        claude_bin(), '--session-id', $claudeSessionId,
    ]);

    if ($result['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Failed to create session: ' . trim($result['stderr'])];
    }

    // tmux new-session returns success as soon as the session is
    // registered, before checking whether the pane's command actually
    // stayed running (e.g. bad cwd). Confirm it actually persisted.
    usleep(300000);

    $stillThere = in_array($name, array_column(list_cc_tmux_sessions(), 'name'), true);

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
 * list_cc_tmux_sessions() call made inside this same request.
 *
 * @return array{ok:bool, message:string}
 */
function kill_cc_session(string $requested): array
{
    $whitelist = array_column(list_cc_tmux_sessions(), 'name');

    if (!in_array($requested, $whitelist, true)) {
        return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
    }

    $result = tmux_run(['kill-session', '-t', $requested]);

    if ($result['exit'] !== 0) {
        return ['ok' => false, 'message' => "Failed to kill {$requested}: " . trim($result['stderr'])];
    }

    delete_sidecar($requested);

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
    if (!in_array($name, array_column(list_cc_tmux_sessions(), 'name'), true)) {
        return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
    }

    $prompt = parse_blocking_prompt(tmux_capture_pane($name));

    if ($prompt === null) {
        return ['ok' => false, 'message' => 'Rejected: this session is not currently waiting on a prompt'];
    }

    if (!in_array($option, array_column($prompt['options'], 'number'), true)) {
        return ['ok' => false, 'message' => 'Rejected: that option is not currently offered by this prompt'];
    }

    $result = tmux_run(['send-keys', '-t', $name, (string)$option, 'Enter']);

    if ($result['exit'] !== 0) {
        return ['ok' => false, 'message' => "Failed to send response: " . trim($result['stderr'])];
    }

    return ['ok' => true, 'message' => "Sent option {$option} to {$name}"];
}

/**
 * @return array{ok:bool, killed:string[], failed:string[]}
 */
function cleanup_inactive_sessions(): array
{
    $now = time();
    $killed = [];
    $failed = [];

    foreach (list_cc_tmux_sessions() as $session) {
        if (($now - $session['activity']) <= cleanup_threshold_seconds()) {
            continue;
        }

        $result = tmux_run(['kill-session', '-t', $session['name']]);

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
 * Kills a "bare" claude process (one find_claude_processes() found running
 * on the host that isn't inside a cc-* session this tool manages) by pid.
 * $pid is re-scanned against a fresh find_claude_processes() rather than
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

    foreach (find_claude_processes() as $proc) {
        if ($proc['pid'] === $pid) {
            $stillRunning = true;
            break;
        }
    }

    if (!$stillRunning) {
        return ['ok' => false, 'message' => 'Rejected: not a currently running claude process'];
    }

    $owningPane = find_owning_pane($pid, all_tmux_panes(), build_ppid_map());

    if ($owningPane !== null) {
        $result = tmux_run(['kill-session', '-t', $owningPane['session']]);

        return $result['exit'] === 0
            ? ['ok' => true, 'message' => "Killed tmux session {$owningPane['session']} (pid {$pid})"]
            : ['ok' => false, 'message' => "Failed to kill session {$owningPane['session']}: " . trim($result['stderr'])];
    }

    $result = run_process(['kill', '-TERM', (string)$pid]);

    return $result['exit'] === 0
        ? ['ok' => true, 'message' => "Sent SIGTERM to pid {$pid}"]
        : ['ok' => false, 'message' => "Failed to kill pid {$pid}: " . trim($result['stderr'])];
}

/**
 * Lists the immediate subdirectories of $path (hidden ones included), for
 * the New Session folder browser - lets a session start anywhere under the
 * home directory, not just under www_root(). $path (after resolving symlinks)
 * must be home_root() itself or a descendant of it; anything else is
 * rejected rather than letting the browser wander into the rest of the
 * filesystem. An empty $path defaults to www_root(), the common case,
 * rather than home_root() itself - the browser can still walk up to
 * home_root() from there via the returned `parent`.
 *
 * @return array{ok:bool, path?:string, parent?:?string, dirs?:string[], message?:string}
 */
function browse_dir(string $path): array
{
    $root = home_root();
    $realRoot = realpath($root);

    if ($realRoot === false) {
        return ['ok' => false, 'message' => 'Home directory is not configured correctly on the host'];
    }

    $real = realpath($path !== '' ? $path : www_root());

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
 * Runs the claude-quota script (a wrapper that scrapes Claude Code's own
 * /usage panel via a detached screen session - see the script itself for
 * the mechanism). This is slow, 10-40s, since it drives a real TUI, so it
 * must only ever be called in the background (see trigger_background_quota_refresh()),
 * never inline while a request is waiting.
 *
 * @return array{ok:bool, quota?:array, message?:string}
 */
function run_claude_quota(): array
{
    $result = run_process(['timeout', (string)quota_timeout_seconds(), claude_quota_bin()]);

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
    $raw = @file_get_contents(quota_cache_file());

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
    $dir = dirname(quota_cache_file());

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    @file_put_contents(quota_cache_file(), json_encode(['quota' => $quota, 'fetched_at' => $fetchedAt]));
}

function quota_refresh_marker_file(): string
{
    return quota_cache_file() . '.refreshing';
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

    return (time() - (int)trim($raw)) < quota_timeout_seconds();
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
    $ttl = quota_cache_ttl_seconds();
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

        case 'cleanup':
            return cleanup_inactive_sessions();

        case 'browse_dir':
            return browse_dir((string)($request['path'] ?? ''));

        case 'quota':
            return get_quota();

        default:
            return ['ok' => false, 'message' => 'Unknown action'];
    }
}
