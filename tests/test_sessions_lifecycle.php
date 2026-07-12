<?php
declare(strict_types=1);

/**
 * Exercises the real create/list/kill/cleanup logic in
 * host-agent/lib/Sessions.php against an isolated tmux socket and the
 * tests/fixtures/fake_claude symlink (never the real tmux server or the
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

// --- browse_dir(): powers the New Session folder browser, walking from
// WWW_ROOT up to (but never past) HOME_ROOT ---
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
    // fake_claude (a symlink to /bin/cat) never sets a terminal title like the
    // real claude CLI does, so its content isn't asserted here - only that
    // list_all_sessions() always includes the key. The stripping behavior
    // itself is covered deterministically by the clean_pane_title() checks above.
    assert_true(array_key_exists('title', $session ?? []), 'list: title key present');

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
} finally {
    // Defense in depth - tests/run.sh's `tmux kill-server` on the isolated
    // socket is the real backstop regardless of what happens here, but
    // clean up explicitly too in case this script is ever run standalone.
    foreach ($createdSessions as $leftover) {
        kill_cc_session($leftover);
    }
}

test_exit();
