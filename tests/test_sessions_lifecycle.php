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

use HostAgent\Services\Config;
use HostAgent\Services\ProcessInspector;
use HostAgent\Services\PromptParser;
use HostAgent\Services\QuotaService;
use HostAgent\Services\SessionService;
use HostAgent\Services\TmuxService;

const REAL_TMUX_SOCKET = '/tmp/tmux-1000/default';

if (Config::tmux_socket() === REAL_TMUX_SOCKET) {
    fwrite(STDERR, "REFUSING TO RUN: TMUX_SOCKET resolves to the real host socket. Check tests/.env.testing.\n");
    exit(1);
}

/** @var string[] $createdSessions names still possibly running, for the finally-block safety net */
$createdSessions = [];

/** @var resource|null $bareProc a plain (non-tmux) fake claude process, for the finally-block safety net */
$bareProc = null;

/** @var string|null $adhocName a non-cc-* tmux session hosting a fake claude process, for the finally-block safety net */
$adhocName = null;

/** @var string|null $promptTestSession a cc-* session used to test SessionService::answer_prompt(), for the finally-block safety net */
$promptTestSession = null;

/** @var string|null $sendTestSession a cc-* session used to test SessionService::send_message(), for the finally-block safety net */
$sendTestSession = null;

/** @var string|null $wrapTestSession a cc-* session used to test TmuxService::tmux_capture_pane()'s line-wrap rejoin, for the finally-block safety net */
$wrapTestSession = null;

/** @var string|null $quotaTestSession a cc-* session used to test QuotaService::quota_from_live_pane(), for the finally-block safety net */
$quotaTestSession = null;

/**
 * @return array{ok:bool, name:?string, message:string}
 */
function create_and_track(string $workdir, array &$createdSessions): array
{
    $result = SessionService::create_cc_session($workdir);
    $name = null;

    if (preg_match('/Created session (cc-\S+) in/', (string)($result['message'] ?? ''), $m) === 1) {
        $name = $m[1];
        $createdSessions[] = $name;
    }

    return ['ok' => (bool)($result['ok'] ?? false), 'name' => $name, 'message' => (string)($result['message'] ?? '')];
}

function find_session(string $name): ?array
{
    foreach (SessionService::list_all_sessions()['sessions'] as $session) {
        if ($session['name'] === $name) {
            return $session;
        }
    }

    return null;
}

// --- PromptParser::clean_pane_title(): strips Claude Code's animated spinner glyph,
// leaving the short task description it sets via terminal title escapes ---
assert_equal('Fix login bug', PromptParser::clean_pane_title('⠂ Fix login bug'), 'clean_pane_title: strips a leading spinner glyph');
assert_equal('Fix login bug', PromptParser::clean_pane_title('⠐ Fix login bug'), 'clean_pane_title: strips a different spinner frame');
assert_equal('No spinner here', PromptParser::clean_pane_title('No spinner here'), 'clean_pane_title: leaves a plain title untouched');
assert_equal(null, PromptParser::clean_pane_title(''), 'clean_pane_title: empty title -> null (caller falls back to session name)');
assert_equal(null, PromptParser::clean_pane_title('   '), 'clean_pane_title: whitespace-only title -> null');

// --- PromptParser::pane_title_is_working(): the live "is it doing something right now"
// signal - the same leading spinner glyph PromptParser::clean_pane_title() strips off ---
assert_true(PromptParser::pane_title_is_working('⠂ Fix login bug'), 'pane_title_is_working: true when the spinner glyph is present');
assert_true(PromptParser::pane_title_is_working('⠐ Fix login bug'), 'pane_title_is_working: true for a different spinner frame');
assert_equal(false, PromptParser::pane_title_is_working('No spinner here'), 'pane_title_is_working: false for a plain title');
assert_equal(false, PromptParser::pane_title_is_working(''), 'pane_title_is_working: false for an empty title');

// --- PromptParser::detect_blocking_prompt(): flags a session stuck on an interactive
// prompt (folder trust, tool permission, ...) via the leading "❯ N."
// cursor Claude Code renders on the selected option, regardless of the
// exact prompt wording ---
assert_equal(null, PromptParser::detect_blocking_prompt("Just some normal output\nmore output\n"), 'detect_blocking_prompt: plain output -> not blocked');
assert_equal(
    'Do you trust the files in this folder?',
    PromptParser::detect_blocking_prompt("Do you trust the files in this folder?\n\n❯ 1. Yes, proceed\n  2. No, exit\n"),
    'detect_blocking_prompt: finds the question line directly above the choice list'
);
assert_equal(
    'Do you want to proceed?',
    PromptParser::detect_blocking_prompt("Some other line\n\nDo you want to proceed?\n❯ 1. Yes\n  2. No\n"),
    'detect_blocking_prompt: works for the tool-permission prompt shape too (a blank line separates it from unrelated context above, like a real capture)'
);
assert_equal(
    'no question line here',
    PromptParser::detect_blocking_prompt("no question line here\n❯ 1. Yes\n  2. No\n"),
    'detect_blocking_prompt: falls back to the nearest context line, even without a "?", rather than a bare generic message'
);
assert_equal(
    'Waiting on an interactive prompt (permission or trust dialog)',
    PromptParser::detect_blocking_prompt("❯ 1. Yes\n  2. No\n"),
    'detect_blocking_prompt: only falls back to the generic message when there is truly no context above the choices'
);

// --- PromptParser::parse_blocking_prompt(): the fuller parse behind PromptParser::detect_blocking_prompt(),
// also extracting the surrounding context (the actual tool call / command /
// trust-dialog explanation, not just the bare question) and every numbered
// option, so a caller can render real Approve/Deny buttons with enough
// information to decide, not a blind rubber stamp (used by SessionService::answer_prompt()
// below). The two multi-line fixtures below are verbatim tmux capture-pane
// output from a real, live session - a real trust dialog and a real Bash
// permission prompt - captured specifically because the original
// "line ending in ?" heuristic silently failed to find the real trust
// dialog's question (it wraps: the "?" lands mid-line, not at the end of
// any single one). ---
assert_equal(null, PromptParser::parse_blocking_prompt("Just some normal output\nmore output\n"), 'parse_blocking_prompt: plain output -> null');

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
$trustParsed = PromptParser::parse_blocking_prompt($realTrustDialog);
assert_equal(
    "Quick safety check: Is this a project you created or one you trust? (Like your own code, a well-known open source project, or work from your team). If not, take a moment to review what's in this folder first.",
    $trustParsed['question'] ?? null,
    'parse_blocking_prompt: real trust dialog - question is the FULL sentence, wrapped lines merged, not cut off at the first "?"'
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
$permissionParsed = PromptParser::parse_blocking_prompt($realPermissionPrompt);
assert_equal('Do you want to proceed?', $permissionParsed['question'] ?? null, 'parse_blocking_prompt: real permission prompt - question found directly');
assert_true(str_contains($permissionParsed['context'] ?? '', 'echo hello-permission-test > /tmp/csm-permission-test.txt'), 'parse_blocking_prompt: real permission prompt - context includes the actual command being approved');
assert_true(str_contains($permissionParsed['context'] ?? '', 'Write test string to a temp file'), 'parse_blocking_prompt: real permission prompt - context includes the tool-provided description');
assert_true(!str_contains($permissionParsed['context'] ?? '', str_repeat('─', 40)), 'parse_blocking_prompt: real permission prompt - the purely-decorative separator line is stripped');
assert_equal(
    [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'Yes, and always allow access to tmp/ from this project'], ['number' => 3, 'label' => 'No']],
    $permissionParsed['options'] ?? null,
    'parse_blocking_prompt: real permission prompt - all three options extracted'
);

// --- PromptParser::parse_blocking_prompt(): a command/preview taller than the old fixed
// BLOCKING_PROMPT_CONTEXT_WINDOW (15 lines) used to have its earlier lines
// silently cut off - found live (Andres reported a truncated command).
// Fixed by finding the real top of the block via Claude Code's own "● "
// tool-invocation marker line instead of a fixed window. This script body
// is 20 lines, deliberately more than the window, with the marker at the
// very top. ---
$longCommandLines = [];
for ($i = 1; $i <= 20; $i++) {
    $longCommandLines[] = "  echo \"step {$i}\"";
}
$realLongPermissionPrompt = "● Bash(run a 20-step deploy script)\n"
    . "\n"
    . str_repeat('─', 40) . "\n"
    . " Bash command\n"
    . "\n"
    . implode("\n", $longCommandLines) . "\n"
    . "\n"
    . " Do you want to proceed?\n"
    . " ❯ 1. Yes\n"
    . "   2. No\n";
$longPermissionParsed = PromptParser::parse_blocking_prompt($realLongPermissionPrompt);
assert_true(str_contains($longPermissionParsed['context'] ?? '', 'step 1"'), 'parse_blocking_prompt: a long (>15-line) command preview includes its FIRST line - the old fixed window would have cut this off');
assert_true(str_contains($longPermissionParsed['context'] ?? '', 'step 20"'), 'parse_blocking_prompt: a long command preview also includes its last line');

$parsedNoQuestion = PromptParser::parse_blocking_prompt("no question line here\n❯ 1. Yes\n  2. No\n");
assert_equal(
    [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']],
    $parsedNoQuestion['options'] ?? null,
    'parse_blocking_prompt: options still extracted even when no question line precedes them'
);
assert_equal(false, $parsedNoQuestion['multi_question'] ?? null, 'parse_blocking_prompt: not multi_question when there is no tab bar');

// --- PromptParser::parse_blocking_prompt(): a real, live capture of a multi-question
// AskUserQuestion prompt - a tabbed interface (one tab per question plus
// a trailing Submit tab, cycled with Left/Right - see SessionService::navigate_prompt()),
// where each numbered option is followed by its own indented description
// line and a purely decorative divider precedes the last option. Captured
// specifically because the original option-parsing loop (which stopped
// at the first line that didn't look like a numbered option) silently
// dropped every option after the first real one's description line. ---
$realMultiQuestion = "❯ Use the AskUserQuestion tool right now to ask me two separate questions at\n"
    . "  once, each with 2 short options: question 1 about favorite color (red or\n"
    . "  blue), question 2 about favorite animal (cat or dog).\n"
    . str_repeat('─', 40) . "\n"
    . "←  ☐ Color  ☐ Animal  ✔ Submit  →\n"
    . "\n"
    . "What's your favorite color?\n"
    . "\n"
    . "❯ 1. Red\n"
    . "     Favorite color is red\n"
    . "  2. Blue\n"
    . "     Favorite color is blue\n"
    . "  3. Type something.\n"
    . str_repeat('─', 40) . "\n"
    . "  4. Chat about this\n"
    . "\n"
    . "Enter to select · Tab/Arrow keys to navigate · Esc to cancel\n";
$multiQuestionParsed = PromptParser::parse_blocking_prompt($realMultiQuestion);
assert_equal("What's your favorite color?", $multiQuestionParsed['question'] ?? null, 'parse_blocking_prompt: real multi-question prompt - question for the current tab found');
assert_equal(
    [
        ['number' => 1, 'label' => 'Red'],
        ['number' => 2, 'label' => 'Blue'],
        ['number' => 3, 'label' => 'Type something.'],
        ['number' => 4, 'label' => 'Chat about this'],
    ],
    $multiQuestionParsed['options'] ?? null,
    'parse_blocking_prompt: real multi-question prompt - all four options found despite interleaved description lines and a divider'
);
assert_equal(true, $multiQuestionParsed['multi_question'] ?? null, 'parse_blocking_prompt: real multi-question prompt - tab bar detected');

// --- PromptParser::parse_current_mode(): reads Claude Code's own bottom status line -
// mode names and cycle order confirmed live against a real running
// session (Shift+Tab cycles manual -> accept edits -> plan -> auto). ---
assert_equal('manual', PromptParser::parse_current_mode("  andres@work ~\n  \xE2\x8F\xB8 manual mode on \xC2\xB7 \xE2\x86\x90 for agents\n"), 'parse_current_mode: manual');
assert_equal('accept edits', PromptParser::parse_current_mode("  \xE2\x8F\xB5\xE2\x8F\xB5 accept edits on (shift+tab to cycle) \xC2\xB7 \xE2\x86\x90 for agents\n"), 'parse_current_mode: accept edits - the one mode whose status line omits the word "mode" entirely');
assert_equal('plan', PromptParser::parse_current_mode("  \xE2\x8F\xB8 plan mode on (shift+tab to cycle) \xC2\xB7 \xE2\x86\x90 for agents\n"), 'parse_current_mode: plan');
assert_equal('auto', PromptParser::parse_current_mode("  \xE2\x8F\xB5\xE2\x8F\xB5 auto mode on (shift+tab to cycle) \xC2\xB7 \xE2\x86\x90 for agents\n"), 'parse_current_mode: auto');
assert_equal(null, PromptParser::parse_current_mode("Just some normal output\nmore output\n"), 'parse_current_mode: no status line -> null');
assert_equal(null, PromptParser::parse_current_mode($realTrustDialog), 'parse_current_mode: a blocking prompt covering the status line -> null, not a false match');

// --- TmuxService::tmux_attach_hint(): the exact command shown to a human to go answer
// a blocked prompt themselves ---
assert_equal('tmux -S ' . Config::tmux_socket() . ' attach -t cc-example', TmuxService::tmux_attach_hint('cc-example'), 'tmux_attach_hint: uses the configured socket path');

// --- SessionService::generate_uuid_v4(): the id passed to `claude --session-id` at launch ---
$uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
assert_true(preg_match($uuidPattern, SessionService::generate_uuid_v4()) === 1, 'generate_uuid_v4: matches the RFC 4122 v4 shape');
assert_true(SessionService::generate_uuid_v4() !== SessionService::generate_uuid_v4(), 'generate_uuid_v4: two calls produce different ids');

// --- SessionService::browse_dir(): powers the New Session folder browser, walking from
// WWW_ROOT up to (but never past) HOME_ROOT ---
$result = SessionService::browse_dir(Config::www_root());
assert_true($result['ok'] ?? false, 'SessionService::browse_dir(www_root): ok=true');
assert_equal(['.hidden-dir', 'project-a', 'project-b'], $result['dirs'] ?? null, 'SessionService::browse_dir(www_root): includes hidden dirs, sorted');

$result = SessionService::browse_dir(Config::www_root() . '/project-a');
assert_true($result['ok'] ?? false, 'SessionService::browse_dir(project-a): ok=true');
assert_equal(['nested'], $result['dirs'] ?? null, 'SessionService::browse_dir(project-a): lists its one subfolder');
assert_equal(Config::www_root(), $result['parent'] ?? null, 'SessionService::browse_dir(project-a): parent is WWW_ROOT');

$result = SessionService::browse_dir(Config::www_root() . '/project-a/nested');
assert_true($result['ok'] ?? false, 'SessionService::browse_dir(nested): ok=true');
assert_equal([], $result['dirs'] ?? null, 'SessionService::browse_dir(nested): no subfolders');
assert_equal(Config::www_root() . '/project-a', $result['parent'] ?? null, 'SessionService::browse_dir(nested): parent is project-a');

$result = SessionService::browse_dir(Config::home_root());
assert_true($result['ok'] ?? false, 'SessionService::browse_dir(home_root): ok=true');
assert_equal(null, $result['parent'], 'SessionService::browse_dir(home_root): parent is null - can\'t go up further');

$result = SessionService::browse_dir('/etc');
assert_equal(false, $result['ok'] ?? null, 'SessionService::browse_dir(/etc): rejects a path outside home_root');

$result = SessionService::browse_dir(Config::www_root() . '/does-not-exist');
assert_equal(false, $result['ok'] ?? null, 'SessionService::browse_dir(missing dir): rejects a nonexistent path');

try {
    // --- create ---
    $created = create_and_track(Config::www_root() . '/project-a', $createdSessions);
    assert_true($created['ok'], 'create: ok=true');
    assert_true($created['name'] !== null, 'create: session name parsed from message');
    $name = $created['name'];

    // --- list sees it, sidecar + pid matching worked ---
    $session = $name !== null ? find_session($name) : null;
    assert_true($session !== null, 'list: created session appears');
    assert_equal(Config::www_root() . '/project-a', $session['workdir'] ?? null, 'list: workdir recorded via sidecar');
    assert_true($session['spawned_by_csm'] ?? false, 'list: spawned_by_csm is true');
    assert_true(($session['pid'] ?? null) !== null, 'list: pane process pid matched via argv[0]');
    // fake_claude (behaves like /bin/cat) never sets a terminal title like the
    // real claude CLI does, so its content isn't asserted here - only that
    // SessionService::list_all_sessions() always includes the key. The stripping behavior
    // itself is covered deterministically by the PromptParser::clean_pane_title() checks above.
    assert_true(array_key_exists('title', $session ?? []), 'list: title key present');
    assert_true(preg_match($uuidPattern, (string)($session['claude_session_id'] ?? '')) === 1, 'list: claude_session_id recorded via sidecar, uuid-shaped');

    // --- SessionService::session_detail(): the same re-derived-from-a-live-scan data as one
    // list() row, plus has_transcript - fake_claude never actually writes a
    // real ~/.claude/projects transcript, so has_transcript is expected
    // false here (see test_transcript.php for the file-found path) ---
    $detail = $name !== null ? SessionService::session_detail($name) : ['ok' => false];
    assert_true($detail['ok'] ?? false, 'session_detail: ok=true for a live session');
    assert_equal($session['claude_session_id'] ?? null, $detail['claude_session_id'] ?? null, 'session_detail: same claude_session_id as list()');
    assert_equal(false, $detail['has_transcript'] ?? null, 'session_detail: has_transcript=false (no real transcript file exists for this fixture)');

    $missingDetail = SessionService::session_detail('cc-not-a-real-session');
    assert_equal(false, $missingDetail['ok'] ?? null, 'session_detail: rejects a name that is not currently live');

    // --- SessionService::session_history(): a claude_session_id is recorded, but with no
    // real transcript file behind it (fake_claude doesn't write one) this
    // must fail gracefully, not error out ---
    $history = $name !== null ? SessionService::session_history($name, null, 10) : ['ok' => true];
    assert_equal(false, $history['ok'] ?? null, 'session_history: ok=false when no transcript file exists for a recorded claude_session_id');

    $noSidecarHistory = SessionService::session_history('cc-not-a-real-session', null, 10);
    assert_equal(false, $noSidecarHistory['ok'] ?? null, 'session_history: ok=false for a session with no sidecar at all');

    // --- reject kill of a name that isn't currently active ---
    $result = SessionService::kill_cc_session('cc-not-a-real-session');
    assert_equal(false, $result['ok'] ?? null, 'kill: rejects a name not in the live whitelist');

    // --- kill ---
    if ($name !== null) {
        $result = SessionService::kill_cc_session($name);
        assert_true($result['ok'] ?? false, 'kill: ok=true');
        $createdSessions = array_values(array_diff($createdSessions, [$name]));

        assert_true(find_session($name) === null, 'kill: session no longer listed');
        assert_true(!file_exists(Config::sidecar_dir() . "/{$name}.json"), 'kill: sidecar file removed');
    }

    // --- input validation: relative path rejected before touching tmux ---
    $result = SessionService::create_cc_session('relative/path');
    assert_equal(false, $result['ok'] ?? null, 'create: rejects a relative workdir');

    // --- self-healing: the tmux socket's parent directory can vanish
    // entirely (e.g. a host reboot wipes /tmp) since it's addressed via an
    // explicit -S path, which - unlike tmux's own default $TMPDIR/tmux-$UID
    // naming - tmux never auto-creates. TmuxService::tmux_run() must recreate it on
    // demand rather than every command failing until someone notices. ---
    TmuxService::tmux_run(['kill-server']); // empties the isolated test socket dir so it can be removed
    $socketDir = dirname(Config::tmux_socket());
    foreach (glob("{$socketDir}/*") ?: [] as $leftover) {
        @unlink($leftover);
    }
    @rmdir($socketDir);
    assert_true(!is_dir($socketDir), 'self-heal setup: tmux socket dir removed');

    $healed = create_and_track(Config::www_root() . '/project-a', $createdSessions);
    assert_true($healed['ok'], 'create: recreates a missing tmux socket dir and still succeeds');
    if ($healed['name'] !== null) {
        SessionService::kill_cc_session($healed['name']);
        $createdSessions = array_values(array_diff($createdSessions, [$healed['name']]));
    }

    // --- claude binary fails to start: tmux registers the session, then the pane
    // exits immediately since the command doesn't exist - SessionService::create_cc_session()'s
    // post-creation check must catch that and report failure ---
    $originalClaudeBin = Config::claude_bin();
    putenv('CLAUDE_BIN=/definitely/does/not/exist/csm-test-claude-binary');
    $bad = create_and_track(Config::www_root() . '/project-a', $createdSessions);
    putenv("CLAUDE_BIN={$originalClaudeBin}");
    assert_true(!$bad['ok'], 'create: a claude binary that fails to start is reported as failure');

    // --- cleanup respects the (short, test-only) inactivity threshold ---
    $created = create_and_track(Config::www_root() . '/project-b', $createdSessions);
    assert_true($created['ok'], 'cleanup setup: session created');

    sleep(Config::cleanup_threshold_seconds() + 1);

    $result = SessionService::cleanup_inactive_sessions();
    assert_true($result['ok'] ?? false, 'cleanup: ok=true');
    assert_true(
        $created['name'] !== null && in_array($created['name'], $result['killed'] ?? [], true),
        'cleanup: killed the inactive session'
    );
    if ($created['name'] !== null) {
        $createdSessions = array_values(array_diff($createdSessions, [$created['name']]));
    }

    // --- bare processes: a plain (non-tmux) fake claude process must show
    // up in SessionService::list_all_sessions()['bare'] with no tmux_session/title, and
    // SessionService::kill_bare_process() must SIGTERM it directly ---
    $bareCwd = Config::www_root() . '/project-a';
    $bareProc = proc_open([Config::claude_bin()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $barePipes, $bareCwd);
    assert_true(is_resource($bareProc), 'bare setup: spawned a plain (non-tmux) fake claude process');
    $barePid = is_resource($bareProc) ? (proc_get_status($bareProc)['pid'] ?? null) : null;
    usleep(300000); // let /proc reflect the new process's argv/cwd

    $bareEntry = null;
    foreach (SessionService::list_all_sessions()['bare'] as $b) {
        if ($b['pid'] === $barePid) {
            $bareEntry = $b;
            break;
        }
    }
    assert_true($bareEntry !== null, 'list: plain bare process appears in bare[]');
    assert_equal($bareCwd, $bareEntry['cwd'] ?? null, 'list: bare process cwd read via /proc');
    assert_equal(null, $bareEntry['tmux_session'], 'list: plain bare process has no owning tmux session');
    assert_equal(null, $bareEntry['title'], 'list: plain bare process has no title');

    assert_equal(false, SessionService::kill_bare_process(999999)['ok'] ?? null, 'kill_bare_process: rejects a pid that is not a running claude process');

    $killResult = $barePid !== null ? SessionService::kill_bare_process($barePid) : ['ok' => false];
    assert_true($killResult['ok'] ?? false, 'kill_bare_process: ok=true for a plain process');
    usleep(300000);

    $stillThere = false;
    foreach (ProcessInspector::find_claude_processes() as $p) {
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
    // SessionService::kill_bare_process() must kill the whole session rather than just
    // SIGTERM the pid ---
    $adhocName = 'csm-test-adhoc-' . getmypid();
    $adhocCwd = Config::www_root() . '/project-b';
    $adhocCreate = TmuxService::tmux_run(['new-session', '-d', '-s', $adhocName, '-c', $adhocCwd, Config::claude_bin()]);
    assert_equal(0, $adhocCreate['exit'], 'bare setup: created an ad-hoc (non-cc-*) tmux session');
    usleep(300000);
    TmuxService::tmux_run(['select-pane', '-t', $adhocName, '-T', 'Adhoc bare title']);
    usleep(100000);

    $adhocEntry = null;
    foreach (SessionService::list_all_sessions()['bare'] as $b) {
        if (($b['cwd'] ?? null) === $adhocCwd) {
            $adhocEntry = $b;
            break;
        }
    }
    assert_true($adhocEntry !== null, "list: ad-hoc tmux session's claude process appears in bare[]");
    assert_equal($adhocName, $adhocEntry['tmux_session'] ?? null, 'list: bare process inside a non-cc-* tmux session reports that session name');
    assert_equal('Adhoc bare title', $adhocEntry['title'] ?? null, 'list: bare process picks up its tmux pane title');

    $adhocPid = $adhocEntry['pid'] ?? null;
    $killResult = $adhocPid !== null ? SessionService::kill_bare_process($adhocPid) : ['ok' => false];
    assert_true($killResult['ok'] ?? false, 'kill_bare_process: ok=true for a tmux-hosted bare process');

    $hasSession = TmuxService::tmux_run(['has-session', '-t', $adhocName]);
    assert_true($hasSession['exit'] !== 0, 'kill_bare_process: ad-hoc tmux session no longer exists');
    $adhocName = null;

    // --- SessionService::answer_prompt(): sends the chosen option's number + Enter to a
    // live session's pane, exactly like a human attached over tmux would
    // type. fake_claude/cat doesn't understand a real permission prompt,
    // so a raw pane running `cat` with local echo disabled (stty -echo)
    // stands in here - crafted prompt text is typed via send-keys (which
    // cat echoes back exactly once, since nothing else is echoing it),
    // giving PromptParser::parse_blocking_prompt() something real to detect via an
    // actual capture-pane call, not a hand-fed string like the pure
    // PromptParser::parse_blocking_prompt() tests above. ---
    $promptTestSession = 'cc-test-answer-prompt-' . getmypid();
    $promptSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $promptTestSession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $promptSetup['exit'], 'answer_prompt setup: created a live cc-* session to answer a prompt in');
    usleep(300000);

    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, 'Do you want to proceed?', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '❯ 1. Yes', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '  2. No', 'Enter']);
    usleep(300000);

    assert_equal(
        false,
        SessionService::answer_prompt($promptTestSession, 99)['ok'] ?? null,
        'answer_prompt: rejects an option not currently offered by the prompt'
    );
    assert_equal(
        false,
        SessionService::answer_prompt('cc-not-a-real-session', 1)['ok'] ?? null,
        'answer_prompt: rejects a session name that is not currently live'
    );
    assert_equal(
        false,
        SessionService::navigate_prompt($promptTestSession, 'left')['ok'] ?? null,
        'navigate_prompt: rejects a plain (non-multi-question) prompt'
    );
    assert_equal(
        false,
        SessionService::navigate_prompt($promptTestSession, 'sideways')['ok'] ?? null,
        'navigate_prompt: rejects an invalid direction'
    );

    $answered = SessionService::answer_prompt($promptTestSession, 1);
    assert_true($answered['ok'] ?? false, 'answer_prompt: ok=true for a currently-offered option');
    usleep(300000);

    $paneAfterAnswer = trim(TmuxService::tmux_capture_pane($promptTestSession));
    assert_true(str_ends_with($paneAfterAnswer, '1'), 'answer_prompt: the option number was actually sent into the pane (echoed back by cat)');

    // --- SessionService::navigate_prompt(): accept path, against a tab-bar-shaped pane
    // (same session, fresh content) - verifies the real Left/Right
    // keypress actually reaches the pane, the same way the SessionService::answer_prompt()
    // check above verifies a numbered option does. ---
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, "←  ☐ Color  ☐ Animal  ✔ Submit  →", 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, 'Pick one', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '❯ 1. A', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '  2. B', 'Enter']);
    usleep(300000);

    // Unlike SessionService::answer_prompt()'s digit case, a "Right" arrow keypress is an
    // escape sequence, not a printable byte cat's canonical-mode line
    // buffering would echo back visibly (it also never reaches cat's
    // read() at all without a following Enter to flush the line) -
    // properly proving delivery would mean putting the fixture pty in raw
    // mode to match how a real interactive TUI actually reads input,
    // which is disproportionate here: tmux's own send-keys is a
    // well-established mechanism (already exercised end-to-end by the
    // digit case above), so what actually needs coverage is this
    // function's own validation logic (already tested above: rejects a
    // non-multi-question prompt, rejects an invalid direction) - ok=true
    // confirms it correctly recognized this as answerable and the
    // underlying tmux command didn't error.
    $navigated = SessionService::navigate_prompt($promptTestSession, 'right');
    assert_true($navigated['ok'] ?? false, 'navigate_prompt: ok=true for a live multi-question prompt');

    // --- SessionService::set_mode(): jumps straight to a chosen mode by working out how
    // many Shift+Tab ("BTab") presses that is from the current mode, read
    // live from the pane. Each press is itself an escape sequence (not a
    // printable byte cat echoes back visibly - same as SessionService::navigate_prompt()'s
    // arrow-key case above), but here the *count* of presses is
    // independently verifiable: seed the pane with a real status line
    // (echoed back by cat) so PromptParser::parse_current_mode() reads a known starting
    // mode, then confirm ok=true for the multi-step jump. ---
    assert_equal(false, SessionService::set_mode($promptTestSession, 'not-a-real-mode')['ok'] ?? null, 'set_mode: rejects an unrecognized mode');
    assert_equal(false, SessionService::set_mode('cc-not-a-real-session', 'plan')['ok'] ?? null, 'set_mode: rejects a session name that is not currently live');
    assert_equal(
        false,
        SessionService::set_mode($promptTestSession, 'plan')['ok'] ?? null,
        'set_mode: rejects when the current mode cannot be read from the pane (no real Claude Code status line here yet)'
    );

    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, 'manual mode on', 'Enter']);
    usleep(300000);
    $modeSet = SessionService::set_mode($promptTestSession, 'auto'); // manual -> auto is 3 steps, the largest possible jump
    assert_true($modeSet['ok'] ?? false, 'set_mode: ok=true once the current mode is readable, for a live session');

    TmuxService::tmux_run(['kill-session', '-t', $promptTestSession]);
    $promptTestSession = null;

    // --- TmuxService::tmux_capture_pane(): a long single logical line (e.g. the command
    // in a permission prompt) that the terminal soft-wraps across several
    // pane rows must come back rejoined into one line, not split mid-word -
    // this is what PromptParser::parse_blocking_prompt() relies on to show the real,
    // complete command rather than a mangled fragment. Verified against a
    // real narrow pane, not a hand-fed string. ---
    $wrapTestSession = 'cc-test-capture-wrap-' . getmypid();
    $wrapSetup = TmuxService::tmux_run(['new-session', '-d', '-x', '60', '-y', '20', '-s', $wrapTestSession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $wrapSetup['exit'], 'tmux_capture_pane wrap test setup: created a narrow live pane');
    usleep(300000);

    $longCommand = "ssh media 'rm /tmp/apply_dashboard.py /tmp/another_very_long_filename_that_will_definitely_wrap_across_the_narrow_pane_width.py'";
    TmuxService::tmux_run(['set-buffer', '--', $longCommand]);
    TmuxService::tmux_run(['paste-buffer', '-t', $wrapTestSession]);
    TmuxService::tmux_run(['send-keys', '-t', $wrapTestSession, 'Enter']);
    usleep(300000);

    assert_true(
        str_contains(TmuxService::tmux_capture_pane($wrapTestSession), $longCommand),
        'tmux_capture_pane: a long line the terminal soft-wrapped across multiple pane rows is rejoined intact (-J), not split mid-word'
    );

    TmuxService::tmux_run(['kill-session', '-t', $wrapTestSession]);
    $wrapTestSession = null;

    // --- SessionService::send_message(): sends free text to a session's pane via tmux
    // paste-buffer + Enter, exactly as if a human had typed it while
    // attached. Verified end-to-end against a real pane: the full
    // multi-line message lands as one block (echoed back by cat), not
    // split into separate premature submits the way send-keys with the
    // raw text would (each embedded newline acting as its own Enter). ---
    $sendTestSession = 'cc-test-send-message-' . getmypid();
    $sendSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $sendTestSession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $sendSetup['exit'], 'send_message setup: created a live cc-* session to send a message to');
    usleep(300000);

    assert_equal(false, SessionService::send_message('cc-not-a-real-session', 'hello')['ok'] ?? null, 'send_message: rejects a session name that is not currently live');
    assert_equal(false, SessionService::send_message($sendTestSession, '   ')['ok'] ?? null, 'send_message: rejects a whitespace-only message');

    $sent = SessionService::send_message($sendTestSession, "Line one\nLine two");
    assert_true($sent['ok'] ?? false, 'send_message: ok=true for a live session');
    usleep(300000);

    $paneAfterSend = TmuxService::tmux_capture_pane($sendTestSession);
    assert_true(
        str_contains($paneAfterSend, 'Line one') && str_contains($paneAfterSend, 'Line two'),
        'send_message: the full multi-line message landed in the pane (echoed back by cat), not split into separate premature submits'
    );

    TmuxService::tmux_run(['kill-session', '-t', $sendTestSession]);
    $sendTestSession = null;

    // --- QuotaService::quota_from_live_pane()/QuotaService::get_quota(): prefers a live session's own
    // status-line quota over the slow claude-quota fallback - crafted via
    // a raw `cat` pane like the wrap-test above, since fake_claude never
    // renders a real status line. ---
    $quotaTestSession = 'cc-test-quota-' . getmypid();
    $quotaSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $quotaTestSession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $quotaSetup['exit'], 'quota_from_live_pane setup: created a live cc-* session');
    usleep(300000);

    assert_equal(null, QuotaService::quota_from_live_pane(), 'quota_from_live_pane: null while no live session shows a quota line yet');

    TmuxService::tmux_run(['send-keys', '-t', $quotaTestSession, 'andres@work /some/workdir | Sonnet 5 | ctx: 4% | 5h: 51% (1h 53m) | 7d: 40% (5d 8h)', 'Enter']);
    usleep(300000);

    $liveQuota = QuotaService::quota_from_live_pane();
    assert_true($liveQuota !== null, 'quota_from_live_pane: finds the quota line once a live session shows one');
    assert_equal(51, $liveQuota['quota']['session']['pct'] ?? null, 'quota_from_live_pane: session pct read from the real pane');
    assert_equal(40, $liveQuota['quota']['week_all']['pct'] ?? null, 'quota_from_live_pane: week_all pct read from the real pane');
    assert_true(is_int($liveQuota['quota']['session']['resets_at'] ?? null), 'quota_from_live_pane: resets_at computed from the parenthetical duration');

    $getQuotaResult = QuotaService::get_quota();
    assert_equal(51, $getQuotaResult['quota']['session']['pct'] ?? null, 'QuotaService::get_quota(): prefers the live pane reading over the cache/scrape fallback');
    assert_equal(false, $getQuotaResult['cached'] ?? null, 'QuotaService::get_quota(): a live pane reading is never reported as cached');

    TmuxService::tmux_run(['kill-session', '-t', $quotaTestSession]);
    $quotaTestSession = null;
} finally {
    // Defense in depth - tests/run.sh's `tmux kill-server` on the isolated
    // socket is the real backstop regardless of what happens here, but
    // clean up explicitly too in case this script is ever run standalone.
    foreach ($createdSessions as $leftover) {
        SessionService::kill_cc_session($leftover);
    }
    if ($adhocName !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $adhocName]);
    }
    if ($promptTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $promptTestSession]);
    }
    if ($sendTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $sendTestSession]);
    }
    if ($wrapTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $wrapTestSession]);
    }
    if ($quotaTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $quotaTestSession]);
    }
    if ($bareProc !== null && is_resource($bareProc)) {
        proc_terminate($bareProc);
        proc_close($bareProc);
    }
}

test_exit();
