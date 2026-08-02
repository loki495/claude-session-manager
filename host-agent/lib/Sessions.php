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
 * This app's own checkout root - hardcoded default matches every other
 * host-specific path in this file (e.g. claude_bin()); overridable via env
 * for tests, same convention.
 */
function csm_repo_root(): string
{
    return csm_config('CSM_REPO_ROOT', '/home/andres/www/claude-session-manager');
}

function claude_settings_path(): string
{
    return home_root() . '/.claude/settings.json';
}

/**
 * The exact `command` string this app's SessionStart hook entry is
 * registered under - both session_start_hook_present() and
 * install_session_hook() key off this same string, so "is it already
 * there" and "what do we add" can never drift apart.
 */
function session_start_hook_command(): string
{
    return 'php ' . csm_repo_root() . '/host-agent/hooks/session_start.php';
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
        return ['pids' => [], 'title' => null, 'working' => false];
    }

    $pids = [];
    $title = null;
    $working = false;

    foreach (explode("\n", trim($result['stdout'])) as $line) {
        if ($line === '') {
            continue;
        }

        [$pid, $paneTitle] = array_pad(explode('|', $line, 2), 2, '');
        $pids[] = (int)$pid;

        if ($title === null) {
            $title = clean_pane_title($paneTitle);
            $working = pane_title_is_working($paneTitle);
        }
    }

    return ['pids' => $pids, 'title' => $title, 'working' => $working];
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
 * True while Claude Code is actively working (thinking, streaming text,
 * running a tool) - the same animated braille spinner clean_pane_title()
 * strips off is the live "is it doing something right now" signal, so a
 * caller that needs the presence rather than the cleaned title reads it
 * here instead of re-deriving it from the raw title itself.
 */
function pane_title_is_working(string $title): bool
{
    return preg_match('/^[\x{2800}-\x{28FF}]+\s*/u', $title) === 1;
}

/**
 * Every mode Claude Code's own Shift+Tab cycle visits, in the exact order
 * it cycles through them, mapped to the exact phrase it prints in its
 * bottom status line for each - all confirmed live against a real running
 * session, not guessed. Three say "<mode> mode on"; "accept edits" is its
 * own inconsistency and just says "accept edits on" (no "mode") - caught
 * by testing against a real capture rather than a hand-written one, which
 * a plausible-looking regex-guess would have silently missed.
 */
const CLAUDE_CODE_MODE_STATUS_PHRASES = [
    'manual' => 'manual mode on',
    'accept edits' => 'accept edits on',
    'plan' => 'plan mode on',
    'auto' => 'auto mode on',
];

/**
 * Reads the current permission mode straight from Claude Code's own
 * bottom status line (e.g. "⏸ manual mode on · ← for agents" or "⏵⏵ auto
 * mode on (shift+tab to cycle) · ← for agents") - there's no other way to
 * learn it live short of parsing the same status bar a human would read.
 * Returns null if the session isn't currently showing that line at all
 * (e.g. it's showing a blocking prompt instead).
 */
function parse_current_mode(string $paneContent): ?string
{
    foreach (CLAUDE_CODE_MODE_STATUS_PHRASES as $mode => $phrase) {
        if (str_contains($paneContent, $phrase)) {
            return $mode;
        }
    }

    return null;
}

/**
 * The current, visible content of a tmux pane - used to detect an
 * interactive prompt Claude Code is waiting on (see
 * detect_blocking_prompt()) that a headless tmux session will otherwise
 * just sit in forever, since there's no human attached to answer it.
 */
function tmux_capture_pane(string $session): string
{
    // -J rejoins any line the terminal soft-wrapped across multiple pane
    // rows back into one - without it, a long command (a common case for
    // the permission-prompt text this feeds into parse_blocking_prompt())
    // comes back split mid-word at the wrap point instead of intact.
    $result = tmux_run(['capture-pane', '-t', $session, '-p', '-J']);

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
 * @return array{question:string, context:string, options: array<int, array{number:int, label:string}>, multi_question:bool, is_folder_trust:bool}|null
 */
function parse_blocking_prompt(string $paneContent): ?array
{
    $lines = explode("\n", $paneContent);
    $choiceIndex = null;

    // Scans from the bottom: the ❯ cursor only ever appears on one line
    // per active choice list, but if the pane's visible screen still
    // holds an earlier, already-resolved list above the current one, the
    // most recent one - furthest down - is the one actually still
    // waiting on input.
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if (preg_match('/^\s*❯\s*\d+[.)]/u', $lines[$i]) === 1) {
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

    // The question label groups $contextLines into paragraphs (consecutive
    // non-blank lines, joined with spaces) before picking one, rather than
    // searching line-by-line - real prompts wrap a single sentence across
    // several physical terminal lines (verified against a live capture of
    // the trust dialog: the "?" lands mid-line, with the rest of the
    // sentence continuing on the next one), so a per-line search would
    // either miss the question or truncate it mid-sentence. $context above
    // still preserves the original, unmerged line breaks for the full
    // verbatim display.
    $paragraphs = [];
    $current = [];

    foreach ($contextLines as $line) {
        if (trim($line) === '') {
            if ($current !== []) {
                $paragraphs[] = trim(implode(' ', $current));
                $current = [];
            }

            continue;
        }

        $current[] = trim($line);
    }

    if ($current !== []) {
        $paragraphs[] = trim(implode(' ', $current));
    }

    $question = null;

    for ($i = count($paragraphs) - 1; $i >= 0; $i--) {
        if (str_contains($paragraphs[$i], '?')) {
            $question = $paragraphs[$i];
            break;
        }
    }

    if ($question === null && $paragraphs !== []) {
        $question = end($paragraphs);
    }

    // Walks until the first blank line, not the first non-matching one -
    // a multi-question AskUserQuestion prompt (verified against a real,
    // live capture) interleaves each numbered option with its own
    // indented description line, plus a purely decorative divider before
    // a trailing "Chat about this" option. Neither of those matches the
    // option pattern, but neither should end the list early either - only
    // a genuine blank line (the real end of the choice block in every
    // captured prompt shape so far) does.
    $options = [];

    for ($i = $choiceIndex; $i < count($lines); $i++) {
        if (trim($lines[$i]) === '') {
            break;
        }

        if (preg_match('/^\s*❯?\s*(\d+)[.)]\s*(.+?)\s*$/u', $lines[$i], $m) === 1) {
            $options[] = ['number' => (int)$m[1], 'label' => $m[2]];
        }
    }

    // A multi-question AskUserQuestion call renders as a tab bar - one tab
    // per question plus a trailing "Submit" tab, cycled with the Left/Right
    // arrow keys (verified live) - rather than one linear prompt. Detected
    // so the frontend can offer prev/next-question navigation alongside
    // the normal numbered-option buttons for whichever tab is showing.
    $multiQuestion = false;

    foreach ($contextLines as $line) {
        if (str_contains($line, '←') && str_contains($line, '→') && str_contains($line, 'Submit')) {
            $multiQuestion = true;
            break;
        }
    }

    // The initial per-folder trust check is the one prompt where declining
    // exits the whole session outright, rather than just declining one
    // action - every other prompt shape's "no" option just moves on. That
    // makes an "exit" option a reliable, wording-independent signal for
    // "this is the trust dialog specifically" (verified against a live
    // capture: its options are "Yes, I trust this folder" / "No, exit").
    // Used to keep the dashboard's per-row treatment to the plain
    // attach-and-look tip for this one case, while other prompts get the
    // richer context+buttons treatment there too.
    $isFolderTrust = false;

    foreach ($options as $opt) {
        if (stripos($opt['label'], 'exit') !== false) {
            $isFolderTrust = true;
            break;
        }
    }

    return [
        'question' => $question ?? 'Waiting on an interactive prompt (permission or trust dialog)',
        'context' => $context,
        'options' => $options,
        'multi_question' => $multiQuestion,
        'is_folder_trust' => $isFolderTrust,
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
 * @return array{name:string, activity:int, attached:bool, pid:?int, workdir:?string, spawned_by_csm:bool, title:?string, working:bool, blocked_reason:?string, resume_hint:?string, prompt_context:?string, prompt_options:array<int, array{number:int, label:string}>, prompt_multi_question:bool, prompt_is_folder_trust:bool, current_mode:?string, claude_session_id:?string, last_message:?array}
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
    $paneContent = tmux_capture_pane($tmuxSession['name']);
    $prompt = parse_blocking_prompt($paneContent);
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
        'resume_hint' => $prompt !== null ? tmux_attach_hint($tmuxSession['name']) : null,
        'prompt_context' => $prompt['context'] ?? null,
        'prompt_options' => $prompt['options'] ?? [],
        'prompt_multi_question' => $prompt['multi_question'] ?? false,
        'prompt_is_folder_trust' => $prompt['is_folder_trust'] ?? false,
        'current_mode' => parse_current_mode($paneContent),
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
 * see prompt_context in parse_blocking_prompt() for the live-pane
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
 * True if ~/.claude/settings.json already has a SessionStart hook entry
 * running our exact hook script (see session_start_hook_command()) -
 * checked by command string, not just "a SessionStart hook exists", so a
 * user's own unrelated SessionStart hooks are never mistaken for ours.
 *
 * @param array<string, mixed> $settings
 */
function session_start_hook_present(array $settings): bool
{
    $command = session_start_hook_command();
    $entries = $settings['hooks']['SessionStart'] ?? [];

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
 * Reads ~/.claude/settings.json (if any) and reports whether the
 * SessionStart hook (see host-agent/hooks/session_start.php) is already
 * registered - a missing file is a normal, expected "not set up yet"
 * state, not an error; a file that exists but fails to parse as JSON is
 * an error, since installing on top of it risks Claude Code refusing to
 * start (or install_session_hook() below refusing to touch it at all).
 *
 * @return array{ok:bool, installed:bool, message?:string}
 */
function check_session_hook(): array
{
    $raw = @file_get_contents(claude_settings_path());

    if ($raw === false) {
        return ['ok' => true, 'installed' => false];
    }

    $settings = json_decode($raw, true);

    if (!is_array($settings)) {
        return ['ok' => false, 'installed' => false, 'message' => '~/.claude/settings.json exists but is not valid JSON'];
    }

    return ['ok' => true, 'installed' => session_start_hook_present($settings)];
}

/**
 * Adds this app's SessionStart hook entry to ~/.claude/settings.json,
 * creating the file if it doesn't exist yet. Never overwrites an existing
 * file that fails to parse - a blind reset-to-empty-then-write would
 * silently discard every other hook/setting Andres already has configured
 * there. Idempotent: a no-op (still ok:true) if the hook is already
 * present, so this is safe to call from a "just make sure it's there"
 * dashboard button without a separate check first.
 *
 * @return array{ok:bool, installed:bool, message?:string}
 */
function install_session_hook(): array
{
    $path = claude_settings_path();
    $raw = @file_get_contents($path);
    $settings = [];

    if ($raw !== false) {
        $settings = json_decode($raw, true);

        if (!is_array($settings)) {
            return ['ok' => false, 'installed' => false, 'message' => '~/.claude/settings.json exists but is not valid JSON - fix or add the SessionStart hook manually, see README'];
        }
    }

    if (session_start_hook_present($settings)) {
        return ['ok' => true, 'installed' => true];
    }

    $settings['hooks'] ??= [];
    $settings['hooks']['SessionStart'] ??= [];
    $settings['hooks']['SessionStart'][] = [
        'matcher' => '*',
        'hooks' => [
            ['type' => 'command', 'command' => session_start_hook_command()],
        ],
    ];

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

    $result = tmux_run([
        // CSM_SESSION_NAME is how the SessionStart hook (see
        // host-agent/hooks/session_start.php) tells this pane's claude
        // process apart from any other on the box, so it knows which
        // sidecar to rebind when Claude Code rotates to a new session-id
        // transcript (/clear, /compact, --resume, --fork-session) without
        // this tmux pane itself ever restarting.
        'new-session', '-d', '-s', $name,
        '-c', $workdir,
        '-e', "CSM_SESSION_NAME={$name}",
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

    // Sent as two separate keys, not one send-keys call - verified live that
    // for an AskUserQuestion-style prompt, the digit only moves the on-screen
    // cursor (it doesn't auto-confirm), so an Enter sent in the same instant
    // can race ahead and confirm whatever was *previously* highlighted
    // instead. See TMUX_KEY_STEP_DELAY_USEC.
    $digitResult = tmux_run(['send-keys', '-t', $name, (string)$option]);

    if ($digitResult['exit'] !== 0) {
        return ['ok' => false, 'message' => "Failed to send response: " . trim($digitResult['stderr'])];
    }

    usleep(TMUX_KEY_STEP_DELAY_USEC);

    $enterResult = tmux_run(['send-keys', '-t', $name, 'Enter']);

    if ($enterResult['exit'] !== 0) {
        return ['ok' => false, 'message' => "Failed to send response: " . trim($enterResult['stderr'])];
    }

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

    $digitResult = tmux_run(['send-keys', '-t', $name, (string)$option]);

    if ($digitResult['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Failed to select the free-text option: ' . trim($digitResult['stderr'])];
    }

    usleep(TMUX_KEY_STEP_DELAY_USEC);

    $set = tmux_run(['set-buffer', '--', $text]);

    if ($set['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Failed to stage reply: ' . trim($set['stderr'])];
    }

    $paste = tmux_run(['paste-buffer', '-t', $name]);

    if ($paste['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Failed to send reply: ' . trim($paste['stderr'])];
    }

    usleep(TMUX_KEY_STEP_DELAY_USEC);

    $enterResult = tmux_run(['send-keys', '-t', $name, 'Enter']);

    if ($enterResult['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Reply sent but failed to submit: ' . trim($enterResult['stderr'])];
    }

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

    if (!in_array($name, array_column(list_cc_tmux_sessions(), 'name'), true)) {
        return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
    }

    $prompt = parse_blocking_prompt(tmux_capture_pane($name));

    if ($prompt === null || empty($prompt['multi_question'])) {
        return ['ok' => false, 'message' => 'Rejected: this session is not currently showing a multi-question prompt'];
    }

    $key = $direction === 'left' ? 'Left' : 'Right';
    $result = tmux_run(['send-keys', '-t', $name, $key]);

    if ($result['exit'] !== 0) {
        return ['ok' => false, 'message' => "Failed to navigate: " . trim($result['stderr'])];
    }

    return ['ok' => true, 'message' => "Sent {$key} to {$name}"];
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
    if (!array_key_exists($targetMode, CLAUDE_CODE_MODE_STATUS_PHRASES)) {
        return ['ok' => false, 'message' => 'Rejected: not a recognized mode'];
    }

    if (!in_array($name, array_column(list_cc_tmux_sessions(), 'name'), true)) {
        return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
    }

    $currentMode = parse_current_mode(tmux_capture_pane($name));

    if ($currentMode === null) {
        return ['ok' => false, 'message' => 'Rejected: current mode is not readable right now (a prompt may be covering the status line)'];
    }

    $modes = array_keys(CLAUDE_CODE_MODE_STATUS_PHRASES);
    $steps = (array_search($targetMode, $modes, true) - array_search($currentMode, $modes, true) + count($modes)) % count($modes);

    for ($i = 0; $i < $steps; $i++) {
        if ($i > 0) {
            usleep(TMUX_KEY_STEP_DELAY_USEC);
        }

        $result = tmux_run(['send-keys', '-t', $name, 'BTab']);

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

    if (!in_array($name, array_column(list_cc_tmux_sessions(), 'name'), true)) {
        return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
    }

    $set = tmux_run(['set-buffer', '--', $text]);

    if ($set['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Failed to stage message: ' . trim($set['stderr'])];
    }

    $paste = tmux_run(['paste-buffer', '-t', $name]);

    if ($paste['exit'] !== 0) {
        return ['ok' => false, 'message' => 'Failed to send message: ' . trim($paste['stderr'])];
    }

    $enter = tmux_run(['send-keys', '-t', $name, 'Enter']);

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

        case 'answer_prompt_with_text':
            return answer_prompt_with_text((string)($request['session'] ?? ''), (int)($request['option'] ?? 0), (string)($request['text'] ?? ''));

        case 'navigate_prompt':
            return navigate_prompt((string)($request['session'] ?? ''), (string)($request['direction'] ?? ''));

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

        default:
            return ['ok' => false, 'message' => 'Unknown action'];
    }
}
