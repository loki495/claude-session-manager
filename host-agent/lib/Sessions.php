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
        $panes = tmux_session_panes($session['name']);
        $matchedPid = null;

        foreach ($claudeProcs as $proc) {
            foreach ($panes['pids'] as $panePid) {
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
            'title' => $panes['title'],
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
 * Lists the immediate, non-hidden subdirectories of $path, for the New
 * Session folder browser - lets a session start anywhere under the home
 * directory, not just under www_root(). $path (after resolving symlinks)
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
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
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

        case 'create':
            return create_cc_session((string)($request['workdir'] ?? ''));

        case 'kill':
            return kill_cc_session((string)($request['session'] ?? ''));

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
