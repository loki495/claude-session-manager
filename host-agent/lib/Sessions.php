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

/**
 * @param string[] $args
 * @return array{exit:int,stdout:string,stderr:string}
 */
function tmux_run(array $args): array
{
    $cmd = array_merge(['tmux', '-S', tmux_socket()], $args);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        return ['exit' => -1, 'stdout' => '', 'stderr' => 'failed to start tmux process'];
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
 * @return int[] pane pids belonging to the given tmux session
 */
function tmux_session_pane_pids(string $session): array
{
    $result = tmux_run(['list-panes', '-t', $session, '-s', '-F', '#{pane_pid}']);

    if ($result['exit'] !== 0) {
        return [];
    }

    return array_map('intval', array_filter(explode("\n", trim($result['stdout']))));
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
        $panePids = tmux_session_pane_pids($session['name']);
        $matchedPid = null;

        foreach ($claudeProcs as $proc) {
            foreach ($panePids as $panePid) {
                if (is_descendant($proc['pid'], $panePid, $ppidMap)) {
                    $matchedPid = $proc['pid'];
                    $trackedPids[$proc['pid']] = true;
                    break 2;
                }
            }
        }

        $sidecar = read_sidecar($session['name']);

        $sessions[] = [
            'name' => $session['name'],
            'activity' => $session['activity'],
            'attached' => $session['attached'],
            'pid' => $matchedPid,
            'workdir' => $sidecar['workdir'] ?? null,
            'spawned_by_csm' => $sidecar !== null,
        ];
    }

    usort($sessions, fn(array $a, array $b) => $b['activity'] <=> $a['activity']);

    $bare = [];

    foreach ($claudeProcs as $proc) {
        if (!isset($trackedPids[$proc['pid']])) {
            $bare[] = $proc;
        }
    }

    usort($bare, fn(array $a, array $b) => ($b['started_at'] ?? 0) <=> ($a['started_at'] ?? 0));

    return ['sessions' => $sessions, 'bare' => $bare];
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

    $result = tmux_run([
        'new-session', '-d', '-s', $name,
        '-c', $workdir,
        claude_bin(),
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

    write_sidecar($name, ['workdir' => $workdir, 'spawned_at' => time()]);

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
 * @return array{ok:bool, dirs:string[], root:string}
 */
function list_www_dirs(): array
{
    $dirs = [];

    $root = www_root();

    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
            continue;
        }

        if (is_dir($root . '/' . $entry)) {
            $dirs[] = $entry;
        }
    }

    sort($dirs, SORT_STRING | SORT_FLAG_CASE);

    return ['ok' => true, 'dirs' => $dirs, 'root' => $root];
}

/**
 * @return array
 */
function dispatch_action(array $request): array
{
    switch ($request['action'] ?? '') {
        case 'list':
            return ['ok' => true] + list_all_sessions();

        case 'create':
            return create_cc_session((string)($request['workdir'] ?? ''));

        case 'kill':
            return kill_cc_session((string)($request['session'] ?? ''));

        case 'cleanup':
            return cleanup_inactive_sessions();

        case 'list_www_dirs':
            return list_www_dirs();

        default:
            return ['ok' => false, 'message' => 'Unknown action'];
    }
}
