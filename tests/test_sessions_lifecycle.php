<?php
declare(strict_types=1);

/**
 * Exercises the real create/list/kill/cleanup logic in
 * host-agent/lib/Sessions.php against an isolated tmux socket and the
 * tests/fixtures/fake_claude stand-in (never the real tmux server or the
 * real claude binary - see tests/.env.testing). Calls dispatch_action()'s
 * underlying functions in-process; no socket layer involved here, that's
 * covered by test_agent_client_protocol.php.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

const REAL_TMUX_SOCKET = '/tmp/tmux-1000/default';

if (tmux_socket() === REAL_TMUX_SOCKET) {
    fwrite(STDERR, "REFUSING TO RUN: TMUX_SOCKET resolves to the real host socket. Check tests/.env.testing.\n");
    exit(1);
}

/** @var string[] $createdSessions names still possibly running, for the finally-block safety net */
$createdSessions = [];

/** @var resource|null $bareProc a plain (non-tmux) fake claude process, for the finally-block safety net */
$bareProc = null;

/** @var string|null $adhocName a non-cc-* tmux session hosting a fake claude process, for the finally-block safety net */
$adhocName = null;

/** @var string|null $promptTestSession a cc-* session used to test answer_prompt(), for the finally-block safety net */
$promptTestSession = null;

/**
 * @return array{ok:bool, name:?string, message:string}
 */
function create_and_track(string $workdir, array &$createdSessions): array
{
    $result = create_cc_session($workdir);
    $name = null;

    if (preg_match('/Created session (cc-\S+) in/', (string)($result['message'] ?? ''), $m) === 1) {
        $name = $m[1];
        $createdSessions[] = $name;
    }

    return ['ok' => (bool)($result['ok'] ?? false), 'name' => $name, 'message' => (string)($result['message'] ?? '')];
}

function find_session(string $name): ?array
{
    foreach (list_all_sessions()['sessions'] as $session) {
        if ($session['name'] === $name) {
            return $session;
        }
    }

    return null;
}

// --- clean_pane_title(): strips Claude Code's animated spinner glyph,
// leaving the short task description it sets via terminal title escapes ---
assert_equal('Fix login bug', clean_pane_title('⠂ Fix login bug'), 'clean_pane_title: strips a leading spinner glyph');
assert_equal('Fix login bug', clean_pane_title('⠐ Fix login bug'), 'clean_pane_title: strips a different spinner frame');
assert_equal('No spinner here', clean_pane_title('No spinner here'), 'clean_pane_title: leaves a plain title untouched');
assert_equal(null, clean_pane_title(''), 'clean_pane_title: empty title -> null (caller falls back to session name)');
assert_equal(null, clean_pane_title('   '), 'clean_pane_title: whitespace-only title -> null');

// --- detect_blocking_prompt(): flags a session stuck on an interactive
// prompt (folder trust, tool permission, ...) via the leading "❯ N."
// cursor Claude Code renders on the selected option, regardless of the
// exact prompt wording ---
assert_equal(null, detect_blocking_prompt("Just some normal output\nmore output\n"), 'detect_blocking_prompt: plain output -> not blocked');
assert_equal(
    'Do you trust the files in this folder?',
    detect_blocking_prompt("Do you trust the files in this folder?\n\n❯ 1. Yes, proceed\n  2. No, exit\n"),
    'detect_blocking_prompt: finds the question line directly above the choice list'
);
assert_equal(
    'Do you want to proceed?',
    detect_blocking_prompt("Some other line\nDo you want to proceed?\n❯ 1. Yes\n  2. No\n"),
    'detect_blocking_prompt: works for the tool-permission prompt shape too'
);
assert_equal(
    'no question line here',
    detect_blocking_prompt("no question line here\n❯ 1. Yes\n  2. No\n"),
    'detect_blocking_prompt: falls back to the nearest context line, even without a "?", rather than a bare generic message'
);
assert_equal(
    'Waiting on an interactive prompt (permission or trust dialog)',
    detect_blocking_prompt("❯ 1. Yes\n  2. No\n"),
    'detect_blocking_prompt: only falls back to the generic message when there is truly no context above the choices'
);

// --- parse_blocking_prompt(): the fuller parse behind detect_blocking_prompt(),
// also extracting the surrounding context (the actual tool call / command /
// trust-dialog explanation, not just the bare question) and every numbered
// option, so a caller can render real Approve/Deny buttons with enough
// information to decide, not a blind rubber stamp (used by answer_prompt()
// below). The two multi-line fixtures below are verbatim tmux capture-pane
// output from a real, live session - a real trust dialog and a real Bash
// permission prompt - captured specifically because the original
// "line ending in ?" heuristic silently failed to find the real trust
// dialog's question (it wraps: the "?" lands mid-line, not at the end of
// any single one). ---
assert_equal(null, parse_blocking_prompt("Just some normal output\nmore output\n"), 'parse_blocking_prompt: plain output -> null');

$realTrustDialog = " Accessing workspace:\n"
    . "\n"
    . " /tmp/csm-prompt-inspect-1122606/scratch\n"
    . "\n"
    . " Quick safety check: Is this a project you created or one you trust? (Like your\n"
    . " own code, a well-known open source project, or work from your team). If not,\n"
    . " take a moment to review what's in this folder first.\n"
    . "\n"
    . " Claude Code'll be able to read, edit, and execute files here.\n"
    . "\n"
    . " Security guide\n"
    . "\n"
    . " ❯ 1. Yes, I trust this folder\n"
    . "   2. No, exit\n"
    . "\n"
    . " Enter to confirm · Esc to cancel\n";
$trustParsed = parse_blocking_prompt($realTrustDialog);
assert_equal(
    'Quick safety check: Is this a project you created or one you trust?',
    $trustParsed['question'] ?? null,
    'parse_blocking_prompt: real trust dialog - question truncated at the "?" even though it lands mid-line'
);
assert_true(str_contains($trustParsed['context'] ?? '', 'Accessing workspace'), 'parse_blocking_prompt: real trust dialog - context includes the workspace path line');
assert_true(str_contains($trustParsed['context'] ?? '', "Claude Code'll be able to read"), 'parse_blocking_prompt: real trust dialog - context includes the explanation, not just the question');
assert_equal(
    [['number' => 1, 'label' => 'Yes, I trust this folder'], ['number' => 2, 'label' => 'No, exit']],
    $trustParsed['options'] ?? null,
    'parse_blocking_prompt: real trust dialog - both options extracted'
);

$realPermissionPrompt = "● Bash(echo hello-permission-test > /tmp/csm-permission-test.txt)\n"
    . "\n"
    . str_repeat('─', 40) . "\n"
    . " Bash command\n"
    . "\n"
    . "   echo hello-permission-test > /tmp/csm-permission-test.txt\n"
    . "   Write test string to a temp file\n"
    . "\n"
    . " Do you want to proceed?\n"
    . " ❯ 1. Yes\n"
    . "   2. Yes, and always allow access to tmp/ from this project\n"
    . "   3. No\n"
    . "\n"
    . " Esc to cancel · Tab to amend · ctrl+e to explain\n";
$permissionParsed = parse_blocking_prompt($realPermissionPrompt);
assert_equal('Do you want to proceed?', $permissionParsed['question'] ?? null, 'parse_blocking_prompt: real permission prompt - question found directly');
assert_true(str_contains($permissionParsed['context'] ?? '', 'echo hello-permission-test > /tmp/csm-permission-test.txt'), 'parse_blocking_prompt: real permission prompt - context includes the actual command being approved');
assert_true(str_contains($permissionParsed['context'] ?? '', 'Write test string to a temp file'), 'parse_blocking_prompt: real permission prompt - context includes the tool-provided description');
assert_true(!str_contains($permissionParsed['context'] ?? '', str_repeat('─', 40)), 'parse_blocking_prompt: real permission prompt - the purely-decorative separator line is stripped');
assert_equal(
    [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'Yes, and always allow access to tmp/ from this project'], ['number' => 3, 'label' => 'No']],
    $permissionParsed['options'] ?? null,
    'parse_blocking_prompt: real permission prompt - all three options extracted'
);

$parsedNoQuestion = parse_blocking_prompt("no question line here\n❯ 1. Yes\n  2. No\n");
assert_equal(
    [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']],
    $parsedNoQuestion['options'] ?? null,
    'parse_blocking_prompt: options still extracted even when no question line precedes them'
);

// --- tmux_attach_hint(): the exact command shown to a human to go answer
// a blocked prompt themselves ---
assert_equal('tmux -S ' . tmux_socket() . ' attach -t cc-example', tmux_attach_hint('cc-example'), 'tmux_attach_hint: uses the configured socket path');

// --- generate_uuid_v4(): the id passed to `claude --session-id` at launch ---
$uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
assert_true(preg_match($uuidPattern, generate_uuid_v4()) === 1, 'generate_uuid_v4: matches the RFC 4122 v4 shape');
assert_true(generate_uuid_v4() !== generate_uuid_v4(), 'generate_uuid_v4: two calls produce different ids');

// --- browse_dir(): powers the New Session folder browser, walking from
// WWW_ROOT up to (but never past) HOME_ROOT ---
$result = browse_dir(www_root());
assert_true($result['ok'] ?? false, 'browse_dir(www_root): ok=true');
assert_equal(['.hidden-dir', 'project-a', 'project-b'], $result['dirs'] ?? null, 'browse_dir(www_root): includes hidden dirs, sorted');

$result = browse_dir(www_root() . '/project-a');
assert_true($result['ok'] ?? false, 'browse_dir(project-a): ok=true');
assert_equal(['nested'], $result['dirs'] ?? null, 'browse_dir(project-a): lists its one subfolder');
assert_equal(www_root(), $result['parent'] ?? null, 'browse_dir(project-a): parent is WWW_ROOT');

$result = browse_dir(www_root() . '/project-a/nested');
assert_true($result['ok'] ?? false, 'browse_dir(nested): ok=true');
assert_equal([], $result['dirs'] ?? null, 'browse_dir(nested): no subfolders');
assert_equal(www_root() . '/project-a', $result['parent'] ?? null, 'browse_dir(nested): parent is project-a');

$result = browse_dir(home_root());
assert_true($result['ok'] ?? false, 'browse_dir(home_root): ok=true');
assert_equal(null, $result['parent'], 'browse_dir(home_root): parent is null - can\'t go up further');

$result = browse_dir('/etc');
assert_equal(false, $result['ok'] ?? null, 'browse_dir(/etc): rejects a path outside home_root');

$result = browse_dir(www_root() . '/does-not-exist');
assert_equal(false, $result['ok'] ?? null, 'browse_dir(missing dir): rejects a nonexistent path');

try {
    // --- create ---
    $created = create_and_track(www_root() . '/project-a', $createdSessions);
    assert_true($created['ok'], 'create: ok=true');
    assert_true($created['name'] !== null, 'create: session name parsed from message');
    $name = $created['name'];

    // --- list sees it, sidecar + pid matching worked ---
    $session = $name !== null ? find_session($name) : null;
    assert_true($session !== null, 'list: created session appears');
    assert_equal(www_root() . '/project-a', $session['workdir'] ?? null, 'list: workdir recorded via sidecar');
    assert_true($session['spawned_by_csm'] ?? false, 'list: spawned_by_csm is true');
    assert_true(($session['pid'] ?? null) !== null, 'list: pane process pid matched via argv[0]');
    // fake_claude (behaves like /bin/cat) never sets a terminal title like the
    // real claude CLI does, so its content isn't asserted here - only that
    // list_all_sessions() always includes the key. The stripping behavior
    // itself is covered deterministically by the clean_pane_title() checks above.
    assert_true(array_key_exists('title', $session ?? []), 'list: title key present');
    assert_true(preg_match($uuidPattern, (string)($session['claude_session_id'] ?? '')) === 1, 'list: claude_session_id recorded via sidecar, uuid-shaped');

    // --- session_detail(): the same re-derived-from-a-live-scan data as one
    // list() row, plus has_transcript - fake_claude never actually writes a
    // real ~/.claude/projects transcript, so has_transcript is expected
    // false here (see test_transcript.php for the file-found path) ---
    $detail = $name !== null ? session_detail($name) : ['ok' => false];
    assert_true($detail['ok'] ?? false, 'session_detail: ok=true for a live session');
    assert_equal($session['claude_session_id'] ?? null, $detail['claude_session_id'] ?? null, 'session_detail: same claude_session_id as list()');
    assert_equal(false, $detail['has_transcript'] ?? null, 'session_detail: has_transcript=false (no real transcript file exists for this fixture)');

    $missingDetail = session_detail('cc-not-a-real-session');
    assert_equal(false, $missingDetail['ok'] ?? null, 'session_detail: rejects a name that is not currently live');

    // --- session_history(): a claude_session_id is recorded, but with no
    // real transcript file behind it (fake_claude doesn't write one) this
    // must fail gracefully, not error out ---
    $history = $name !== null ? session_history($name, null, 10) : ['ok' => true];
    assert_equal(false, $history['ok'] ?? null, 'session_history: ok=false when no transcript file exists for a recorded claude_session_id');

    $noSidecarHistory = session_history('cc-not-a-real-session', null, 10);
    assert_equal(false, $noSidecarHistory['ok'] ?? null, 'session_history: ok=false for a session with no sidecar at all');

    // --- reject kill of a name that isn't currently active ---
    $result = kill_cc_session('cc-not-a-real-session');
    assert_equal(false, $result['ok'] ?? null, 'kill: rejects a name not in the live whitelist');

    // --- kill ---
    if ($name !== null) {
        $result = kill_cc_session($name);
        assert_true($result['ok'] ?? false, 'kill: ok=true');
        $createdSessions = array_values(array_diff($createdSessions, [$name]));

        assert_true(find_session($name) === null, 'kill: session no longer listed');
        assert_true(!file_exists(sidecar_dir() . "/{$name}.json"), 'kill: sidecar file removed');
    }

    // --- input validation: relative path rejected before touching tmux ---
    $result = create_cc_session('relative/path');
    assert_equal(false, $result['ok'] ?? null, 'create: rejects a relative workdir');

    // --- self-healing: the tmux socket's parent directory can vanish
    // entirely (e.g. a host reboot wipes /tmp) since it's addressed via an
    // explicit -S path, which - unlike tmux's own default $TMPDIR/tmux-$UID
    // naming - tmux never auto-creates. tmux_run() must recreate it on
    // demand rather than every command failing until someone notices. ---
    tmux_run(['kill-server']); // empties the isolated test socket dir so it can be removed
    $socketDir = dirname(tmux_socket());
    foreach (glob("{$socketDir}/*") ?: [] as $leftover) {
        @unlink($leftover);
    }
    @rmdir($socketDir);
    assert_true(!is_dir($socketDir), 'self-heal setup: tmux socket dir removed');

    $healed = create_and_track(www_root() . '/project-a', $createdSessions);
    assert_true($healed['ok'], 'create: recreates a missing tmux socket dir and still succeeds');
    if ($healed['name'] !== null) {
        kill_cc_session($healed['name']);
        $createdSessions = array_values(array_diff($createdSessions, [$healed['name']]));
    }

    // --- claude binary fails to start: tmux registers the session, then the pane
    // exits immediately since the command doesn't exist - create_cc_session()'s
    // post-creation check must catch that and report failure ---
    $originalClaudeBin = claude_bin();
    putenv('CLAUDE_BIN=/definitely/does/not/exist/csm-test-claude-binary');
    $bad = create_and_track(www_root() . '/project-a', $createdSessions);
    putenv("CLAUDE_BIN={$originalClaudeBin}");
    assert_true(!$bad['ok'], 'create: a claude binary that fails to start is reported as failure');

    // --- cleanup respects the (short, test-only) inactivity threshold ---
    $created = create_and_track(www_root() . '/project-b', $createdSessions);
    assert_true($created['ok'], 'cleanup setup: session created');

    sleep(cleanup_threshold_seconds() + 1);

    $result = cleanup_inactive_sessions();
    assert_true($result['ok'] ?? false, 'cleanup: ok=true');
    assert_true(
        $created['name'] !== null && in_array($created['name'], $result['killed'] ?? [], true),
        'cleanup: killed the inactive session'
    );
    if ($created['name'] !== null) {
        $createdSessions = array_values(array_diff($createdSessions, [$created['name']]));
    }

    // --- bare processes: a plain (non-tmux) fake claude process must show
    // up in list_all_sessions()['bare'] with no tmux_session/title, and
    // kill_bare_process() must SIGTERM it directly ---
    $bareCwd = www_root() . '/project-a';
    $bareProc = proc_open([claude_bin()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $barePipes, $bareCwd);
    assert_true(is_resource($bareProc), 'bare setup: spawned a plain (non-tmux) fake claude process');
    $barePid = is_resource($bareProc) ? (proc_get_status($bareProc)['pid'] ?? null) : null;
    usleep(300000); // let /proc reflect the new process's argv/cwd

    $bareEntry = null;
    foreach (list_all_sessions()['bare'] as $b) {
        if ($b['pid'] === $barePid) {
            $bareEntry = $b;
            break;
        }
    }
    assert_true($bareEntry !== null, 'list: plain bare process appears in bare[]');
    assert_equal($bareCwd, $bareEntry['cwd'] ?? null, 'list: bare process cwd read via /proc');
    assert_equal(null, $bareEntry['tmux_session'], 'list: plain bare process has no owning tmux session');
    assert_equal(null, $bareEntry['title'], 'list: plain bare process has no title');

    assert_equal(false, kill_bare_process(999999)['ok'] ?? null, 'kill_bare_process: rejects a pid that is not a running claude process');

    $killResult = $barePid !== null ? kill_bare_process($barePid) : ['ok' => false];
    assert_true($killResult['ok'] ?? false, 'kill_bare_process: ok=true for a plain process');
    usleep(300000);

    $stillThere = false;
    foreach (find_claude_processes() as $p) {
        if ($p['pid'] === $barePid) {
            $stillThere = true;
        }
    }
    assert_true(!$stillThere, 'kill_bare_process: plain process no longer found after SIGTERM');

    if (is_resource($bareProc)) {
        proc_close($bareProc);
    }
    $bareProc = null;

    // --- bare processes: a fake claude process living inside a tmux
    // session this tool doesn't manage (not cc-* prefixed) must be
    // enriched with that session's name and pane title, and
    // kill_bare_process() must kill the whole session rather than just
    // SIGTERM the pid ---
    $adhocName = 'csm-test-adhoc-' . getmypid();
    $adhocCwd = www_root() . '/project-b';
    $adhocCreate = tmux_run(['new-session', '-d', '-s', $adhocName, '-c', $adhocCwd, claude_bin()]);
    assert_equal(0, $adhocCreate['exit'], 'bare setup: created an ad-hoc (non-cc-*) tmux session');
    usleep(300000);
    tmux_run(['select-pane', '-t', $adhocName, '-T', 'Adhoc bare title']);
    usleep(100000);

    $adhocEntry = null;
    foreach (list_all_sessions()['bare'] as $b) {
        if (($b['cwd'] ?? null) === $adhocCwd) {
            $adhocEntry = $b;
            break;
        }
    }
    assert_true($adhocEntry !== null, "list: ad-hoc tmux session's claude process appears in bare[]");
    assert_equal($adhocName, $adhocEntry['tmux_session'] ?? null, 'list: bare process inside a non-cc-* tmux session reports that session name');
    assert_equal('Adhoc bare title', $adhocEntry['title'] ?? null, 'list: bare process picks up its tmux pane title');

    $adhocPid = $adhocEntry['pid'] ?? null;
    $killResult = $adhocPid !== null ? kill_bare_process($adhocPid) : ['ok' => false];
    assert_true($killResult['ok'] ?? false, 'kill_bare_process: ok=true for a tmux-hosted bare process');

    $hasSession = tmux_run(['has-session', '-t', $adhocName]);
    assert_true($hasSession['exit'] !== 0, 'kill_bare_process: ad-hoc tmux session no longer exists');
    $adhocName = null;

    // --- answer_prompt(): sends the chosen option's number + Enter to a
    // live session's pane, exactly like a human attached over tmux would
    // type. fake_claude/cat doesn't understand a real permission prompt,
    // so a raw pane running `cat` with local echo disabled (stty -echo)
    // stands in here - crafted prompt text is typed via send-keys (which
    // cat echoes back exactly once, since nothing else is echoing it),
    // giving parse_blocking_prompt() something real to detect via an
    // actual capture-pane call, not a hand-fed string like the pure
    // parse_blocking_prompt() tests above. ---
    $promptTestSession = 'cc-test-answer-prompt-' . getmypid();
    $promptSetup = tmux_run(['new-session', '-d', '-s', $promptTestSession, '-c', www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $promptSetup['exit'], 'answer_prompt setup: created a live cc-* session to answer a prompt in');
    usleep(300000);

    tmux_run(['send-keys', '-t', $promptTestSession, 'Do you want to proceed?', 'Enter']);
    tmux_run(['send-keys', '-t', $promptTestSession, '❯ 1. Yes', 'Enter']);
    tmux_run(['send-keys', '-t', $promptTestSession, '  2. No', 'Enter']);
    usleep(300000);

    assert_equal(
        false,
        answer_prompt($promptTestSession, 99)['ok'] ?? null,
        'answer_prompt: rejects an option not currently offered by the prompt'
    );
    assert_equal(
        false,
        answer_prompt('cc-not-a-real-session', 1)['ok'] ?? null,
        'answer_prompt: rejects a session name that is not currently live'
    );

    $answered = answer_prompt($promptTestSession, 1);
    assert_true($answered['ok'] ?? false, 'answer_prompt: ok=true for a currently-offered option');
    usleep(300000);

    $paneAfterAnswer = trim(tmux_capture_pane($promptTestSession));
    assert_true(str_ends_with($paneAfterAnswer, '1'), 'answer_prompt: the option number was actually sent into the pane (echoed back by cat)');

    tmux_run(['kill-session', '-t', $promptTestSession]);
    $promptTestSession = null;
} finally {
    // Defense in depth - tests/run.sh's `tmux kill-server` on the isolated
    // socket is the real backstop regardless of what happens here, but
    // clean up explicitly too in case this script is ever run standalone.
    foreach ($createdSessions as $leftover) {
        kill_cc_session($leftover);
    }
    if ($adhocName !== null) {
        tmux_run(['kill-session', '-t', $adhocName]);
    }
    if ($promptTestSession !== null) {
        tmux_run(['kill-session', '-t', $promptTestSession]);
    }
    if ($bareProc !== null && is_resource($bareProc)) {
        proc_terminate($bareProc);
        proc_close($bareProc);
    }
}

test_exit();
