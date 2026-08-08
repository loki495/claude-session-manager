<?php
declare(strict_types=1);

/**
 * Exercises HookService::check_session_hook()/HookService::install_session_hook() (the
 * ~/.claude/settings.json read-modify-write logic covering both the
 * SessionStart and PreToolUse hooks) and the actual
 * host-agent/hooks/session_start.php and host-agent/hooks/pre_tool_use.php
 * scripts Claude Code invokes - both against isolated fixture paths, never
 * the real ~/.claude/settings.json or the real sidecar dir. Uses its own
 * temp HOME_ROOT/SIDECAR_DIR (overridden via putenv(), not
 * tests/.env.testing's shared ones) so a stray settings.json can never end
 * up committed under tests/fixtures.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\HookService;
use HostAgent\Services\PromptParser;
use HostAgent\Stores\PendingToolStore;
use HostAgent\Stores\SidecarStore;

const REAL_HOME_ROOT = '/home/andres';

$fixtureHome = sys_get_temp_dir() . '/csm-test-hook-home-' . bin2hex(random_bytes(4));
$fixtureSidecarDir = sys_get_temp_dir() . '/csm-test-hook-sidecars-' . bin2hex(random_bytes(4));

putenv("HOME_ROOT={$fixtureHome}");
putenv("SIDECAR_DIR={$fixtureSidecarDir}");

if (Config::home_root() === REAL_HOME_ROOT) {
    fwrite(STDERR, "REFUSING TO RUN: HOME_ROOT still resolves to the real home directory.\n");
    exit(1);
}

mkdir($fixtureSidecarDir, 0700, true);

$settingsPath = Config::claude_settings_path();

try {
    // --- HookService::check_session_hook() / HookService::install_session_hook(): fresh machine, no settings.json yet ---

    $check = HookService::check_session_hook();
    assert_equal(true, $check['ok'], 'check_session_hook: ok on a missing settings.json');
    assert_equal(false, $check['installed'], 'check_session_hook: not installed when settings.json does not exist yet');

    $install = HookService::install_session_hook();
    assert_equal(true, $install['ok'], 'install_session_hook: succeeds on a missing settings.json');
    assert_equal(true, $install['installed'], 'install_session_hook: reports installed after creating the file');
    assert_true(is_file($settingsPath), 'install_session_hook: creates ~/.claude/settings.json');

    $decoded = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal(
        Config::session_start_hook_command(),
        $decoded['hooks']['SessionStart'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: written file has our SessionStart hook command'
    );
    assert_equal('*', $decoded['hooks']['SessionStart'][0]['matcher'] ?? null, 'install_session_hook: matcher fires on every session-start source');
    assert_equal(
        Config::pre_tool_use_hook_command(),
        $decoded['hooks']['PreToolUse'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: written file has our PreToolUse hook command'
    );
    assert_equal('*', $decoded['hooks']['PreToolUse'][0]['matcher'] ?? null, 'install_session_hook: PreToolUse matcher fires on every tool');

    assert_equal(true, HookService::check_session_hook()['installed'], 'check_session_hook: installed after HookService::install_session_hook()');

    // --- idempotency: installing again must not duplicate either entry ---

    HookService::install_session_hook();
    $decoded = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal(1, count($decoded['hooks']['SessionStart']), 'install_session_hook: calling twice does not duplicate the SessionStart entry');
    assert_equal(1, count($decoded['hooks']['PreToolUse']), 'install_session_hook: calling twice does not duplicate the PreToolUse entry');

    // --- partial install (only one of the two hooks present) is topped up, not left alone ---

    $onlySessionStart = [
        'hooks' => [
            'SessionStart' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => Config::session_start_hook_command()]]]],
        ],
    ];
    file_put_contents($settingsPath, json_encode($onlySessionStart, JSON_PRETTY_PRINT));

    $partialCheck = HookService::check_session_hook();
    assert_equal(false, $partialCheck['installed'], 'check_session_hook: installed=false when only one of the two hooks is present');

    HookService::install_session_hook();
    $decoded = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal(1, count($decoded['hooks']['SessionStart']), 'install_session_hook: topping up PreToolUse does not duplicate the existing SessionStart entry');
    assert_equal(
        Config::pre_tool_use_hook_command(),
        $decoded['hooks']['PreToolUse'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: adds the missing PreToolUse entry when SessionStart was already present'
    );

    // --- merge safety: an existing file's unrelated hooks/settings survive untouched ---

    $preexisting = [
        'hooks' => [
            'Stop' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => 'notify-send done']]]],
        ],
        'theme' => 'dark',
    ];
    file_put_contents($settingsPath, json_encode($preexisting, JSON_PRETTY_PRINT));

    HookService::install_session_hook();
    $decoded = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal('notify-send done', $decoded['hooks']['Stop'][0]['hooks'][0]['command'] ?? null, 'install_session_hook: preserves a pre-existing unrelated hook');
    assert_equal('dark', $decoded['theme'] ?? null, 'install_session_hook: preserves pre-existing top-level settings');
    assert_equal(
        Config::session_start_hook_command(),
        $decoded['hooks']['SessionStart'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: still adds the SessionStart entry alongside pre-existing hooks'
    );
    assert_equal(
        Config::pre_tool_use_hook_command(),
        $decoded['hooks']['PreToolUse'][0]['hooks'][0]['command'] ?? null,
        'install_session_hook: still adds the PreToolUse entry alongside pre-existing hooks'
    );

    // --- HookService::reindent_json_pretty(): PHP's 4-space JSON_PRETTY_PRINT output is halved to 2-space ---

    $rawWritten = (string)file_get_contents($settingsPath);
    assert_true(str_contains($rawWritten, "\n  \"hooks\""), 'install_session_hook: writes 2-space indent, not PHP default 4-space');

    // --- malformed existing file: refuses to overwrite, never resets to empty ---

    file_put_contents($settingsPath, '{not valid json');

    $checkMalformed = HookService::check_session_hook();
    assert_equal(false, $checkMalformed['ok'], 'check_session_hook: ok=false on a malformed settings.json');
    assert_equal(false, $checkMalformed['installed'], 'check_session_hook: installed=false on a malformed settings.json');

    $installMalformed = HookService::install_session_hook();
    assert_equal(false, $installMalformed['ok'], 'install_session_hook: refuses to touch a malformed settings.json');
    assert_equal('{not valid json', file_get_contents($settingsPath), 'install_session_hook: leaves a malformed settings.json byte-for-byte untouched');

    unlink($settingsPath);

    // --- HookService::session_start_hook_present()/HookService::pre_tool_use_hook_present(): key off the exact command string, not just hook presence ---

    assert_equal(false, HookService::session_start_hook_present(['hooks' => ['SessionStart' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => 'something-unrelated.sh']]]]]]), 'session_start_hook_present: false for an unrelated SessionStart hook');
    assert_equal(true, HookService::session_start_hook_present(['hooks' => ['SessionStart' => [['matcher' => 'clear', 'hooks' => [['type' => 'command', 'command' => Config::session_start_hook_command()]]]]]]), 'session_start_hook_present: true when our command is present under any matcher');
    assert_equal(false, HookService::pre_tool_use_hook_present(['hooks' => ['PreToolUse' => [['matcher' => '*', 'hooks' => [['type' => 'command', 'command' => 'something-unrelated.sh']]]]]]), 'pre_tool_use_hook_present: false for an unrelated PreToolUse hook');
    assert_equal(true, HookService::pre_tool_use_hook_present(['hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => Config::pre_tool_use_hook_command()]]]]]]), 'pre_tool_use_hook_present: true when our command is present under any matcher');

    // --- PromptParser::format_pending_tool_input(): full-text preview per tool shape ---

    assert_equal(
        "npm test",
        PromptParser::format_pending_tool_input('Bash', ['command' => 'npm test']),
        'format_pending_tool_input: Bash with no description is just the command'
    );
    assert_equal(
        "Run tests\n\nnpm test",
        PromptParser::format_pending_tool_input('Bash', ['command' => 'npm test', 'description' => 'Run tests']),
        'format_pending_tool_input: Bash description is prepended when present'
    );
    assert_equal(null, PromptParser::format_pending_tool_input('Bash', []), 'format_pending_tool_input: Bash with no command returns null');
    assert_equal(
        "Write /tmp/foo.txt\n\nline1\nline2",
        PromptParser::format_pending_tool_input('Write', ['file_path' => '/tmp/foo.txt', 'content' => "line1\nline2"]),
        'format_pending_tool_input: Write shows the full file content, not truncated'
    );
    assert_equal(null, PromptParser::format_pending_tool_input('Write', ['file_path' => '/tmp/foo.txt']), 'format_pending_tool_input: Write with no content returns null');
    assert_equal(
        "Edit /tmp/foo.txt\n\n--- old ---\nfoo\n\n--- new ---\nbar",
        PromptParser::format_pending_tool_input('Edit', ['file_path' => '/tmp/foo.txt', 'old_string' => 'foo', 'new_string' => 'bar']),
        'format_pending_tool_input: Edit shows old/new'
    );
    assert_equal(null, PromptParser::format_pending_tool_input('Edit', []), 'format_pending_tool_input: Edit with no file_path returns null');
    assert_true(
        str_starts_with(PromptParser::format_pending_tool_input('WebFetch', ['url' => 'https://example.com']) ?? '', "WebFetch\n\n"),
        'format_pending_tool_input: unrecognized tool falls back to a labeled JSON dump'
    );

    // --- PromptParser::augment_prompt_with_pending_tool(): only replaces context when the pending tool matches the pane's own marker ---

    $basePrompt = [
        'question' => 'Do you want to proceed?',
        'context' => "● Bash(npm test (truncated…",
        'options' => [],
        'multi_question' => false,
        'is_folder_trust' => false,
    ];

    assert_equal(
        $basePrompt,
        PromptParser::augment_prompt_with_pending_tool($basePrompt, null),
        'augment_prompt_with_pending_tool: no pending-tool file leaves the pane-scraped prompt untouched'
    );
    assert_equal(
        $basePrompt,
        PromptParser::augment_prompt_with_pending_tool($basePrompt, ['tool_name' => 'Write', 'tool_input' => ['file_path' => '/x', 'content' => 'y']]),
        'augment_prompt_with_pending_tool: a tool-name mismatch against the pane marker is left untouched (stale/wrong pending file)'
    );
    assert_equal(
        $basePrompt,
        PromptParser::augment_prompt_with_pending_tool($basePrompt, ['tool_name' => 'Bash', 'tool_input' => null]),
        'augment_prompt_with_pending_tool: a malformed pending-tool entry (no tool_input) is left untouched'
    );

    $augmented = PromptParser::augment_prompt_with_pending_tool($basePrompt, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'npm test --full-real-command-not-truncated']]);
    assert_equal('npm test --full-real-command-not-truncated', $augmented['context'], 'augment_prompt_with_pending_tool: a matching tool name replaces the truncated pane context with the full hook-sourced one');
    assert_equal('Do you want to proceed?', $augmented['question'], 'augment_prompt_with_pending_tool: only context is replaced, question/options/etc are untouched');
    assert_equal('Bash', $augmented['tool_name'] ?? null, 'augment_prompt_with_pending_tool: exposes tool_name so callers (push body) can tell a permission prompt from a real question');
    assert_equal(['command' => 'npm test --full-real-command-not-truncated'], $augmented['tool_input'] ?? null, 'augment_prompt_with_pending_tool: exposes tool_input too');

    // AskUserQuestion renders with no "●" marker at all (verified live), so
    // there's nothing to cross-check against - the pane-scraped
    // question/context (already exactly what a human sees) must be left
    // untouched rather than replaced by a raw tool_input JSON dump, but
    // tool_name/tool_input still need to be exposed so the push body can
    // tell this apart from a permission prompt.
    $questionPrompt = [
        'question' => 'Which color do you prefer?',
        'context' => "☐ Color\n\nWhich color do you prefer?",
        'options' => [],
        'multi_question' => false,
        'is_folder_trust' => false,
    ];
    $questionInput = ['questions' => [['question' => 'Which color do you prefer?', 'header' => 'Color', 'options' => [['label' => 'Red'], ['label' => 'Blue']]]]];
    $augmentedQuestion = PromptParser::augment_prompt_with_pending_tool($questionPrompt, ['tool_name' => 'AskUserQuestion', 'tool_input' => $questionInput]);
    assert_equal($questionPrompt['context'], $augmentedQuestion['context'], 'augment_prompt_with_pending_tool: AskUserQuestion context is left untouched, not replaced with a raw JSON dump');
    assert_equal('AskUserQuestion', $augmentedQuestion['tool_name'] ?? null, 'augment_prompt_with_pending_tool: AskUserQuestion still exposes tool_name');
    assert_equal($questionInput, $augmentedQuestion['tool_input'] ?? null, 'augment_prompt_with_pending_tool: AskUserQuestion still exposes tool_input');

    // --- pending-tool sidecar: read/write/delete round-trip ---

    $pendingName = 'cc-pendingtest-' . bin2hex(random_bytes(3));
    assert_equal(null, PendingToolStore::read_pending_tool($pendingName), 'read_pending_tool: null when no file exists yet');

    PendingToolStore::write_pending_tool($pendingName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls'], 'written_at' => 1000]);
    $read = PendingToolStore::read_pending_tool($pendingName);
    assert_equal('Bash', $read['tool_name'] ?? null, 'write_pending_tool/read_pending_tool: round-trips tool_name');
    assert_equal('ls', $read['tool_input']['command'] ?? null, 'write_pending_tool/read_pending_tool: round-trips tool_input');

    PendingToolStore::delete_pending_tool($pendingName);
    assert_equal(null, PendingToolStore::read_pending_tool($pendingName), 'delete_pending_tool: file is gone after delete');

    // --- SidecarStore::prune_orphaned_sidecars(): correctly matches pending-tool files back to their session name ---

    $liveName = 'cc-prunelive-' . bin2hex(random_bytes(3));
    $deadName = 'cc-prunedead-' . bin2hex(random_bytes(3));
    SidecarStore::write_sidecar($liveName, ['workdir' => '/x', 'spawned_at' => 1]);
    PendingToolStore::write_pending_tool($liveName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]);
    SidecarStore::write_sidecar($deadName, ['workdir' => '/x', 'spawned_at' => 1]);
    PendingToolStore::write_pending_tool($deadName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]);

    SidecarStore::prune_orphaned_sidecars([$liveName]);

    assert_true(SidecarStore::read_sidecar($liveName) !== null, 'prune_orphaned_sidecars: a live session\'s plain sidecar survives');
    assert_true(PendingToolStore::read_pending_tool($liveName) !== null, 'prune_orphaned_sidecars: a live session\'s pending-tool file survives (not mistaken for an orphan by its own filename)');
    assert_equal(null, SidecarStore::read_sidecar($deadName), 'prune_orphaned_sidecars: a dead session\'s plain sidecar is pruned');
    assert_equal(null, PendingToolStore::read_pending_tool($deadName), 'prune_orphaned_sidecars: a dead session\'s pending-tool file is pruned too');

    // --- the actual hook script: no CSM_SESSION_NAME env -> no-op ---

    $sidecarName = 'cc-hooktest-' . bin2hex(random_bytes(3));
    $oldId = '11111111-1111-4111-8111-111111111111';
    $newId = '22222222-2222-4222-8222-222222222222';
    write_fixture_transcript($oldId);
    write_fixture_transcript($newId);
    SidecarStore::write_sidecar($sidecarName, ['workdir' => '/fixture/workdir', 'spawned_at' => 1000, 'claude_session_id' => $oldId]);

    run_session_start_hook(null, ['session_id' => $newId]);
    assert_equal($oldId, SidecarStore::read_sidecar($sidecarName)['claude_session_id'] ?? null, 'session_start.php: no-op (sidecar untouched) when CSM_SESSION_NAME is unset');

    // --- CSM_SESSION_NAME set, but no matching sidecar (already killed/never tracked) -> no-op, no crash ---

    run_session_start_hook('cc-does-not-exist', ['session_id' => $newId]);
    assert_equal(null, SidecarStore::read_sidecar('cc-does-not-exist'), 'session_start.php: no-op when CSM_SESSION_NAME has no sidecar file');

    // --- CSM_SESSION_NAME set + real sidecar + valid payload with a REAL matching transcript -> rebinds claude_session_id, keeps the rest ---

    run_session_start_hook($sidecarName, ['session_id' => $newId]);
    $rebound = SidecarStore::read_sidecar($sidecarName);
    assert_equal($newId, $rebound['claude_session_id'] ?? null, 'session_start.php: rebinds claude_session_id to the new session-id from stdin when a real transcript for it exists');
    assert_equal('/fixture/workdir', $rebound['workdir'] ?? null, 'session_start.php: preserves workdir across the rebind');
    assert_equal(1000, $rebound['spawned_at'] ?? null, 'session_start.php: preserves spawned_at across the rebind');
    assert_equal(true, $rebound['spawned_by_csm'] ?? null, 'session_start.php: a CSM_SESSION_NAME session is recorded as spawned_by_csm=true');

    // --- CSM_SESSION_NAME set + real sidecar + payload reports a session-id
    // with NO matching transcript anywhere -> the rebind is refused, the
    // working sidecar is left exactly as it was. Regression test for the
    // 2026-08-08 live incident: a `claude` process run manually from inside
    // a tracked pane's own Bash tool (e.g. testing `--resume` behavior)
    // inherits CSM_SESSION_NAME and fires its own genuine SessionStart with
    // its own, unrelated session_id, which the hook used to trust blindly -
    // clobbering a working sidecar with an id that never had a transcript,
    // permanently breaking "view transcript" for that pane. ---

    $phantomId = '99999999-9999-4999-8999-999999999999';
    run_session_start_hook($sidecarName, ['session_id' => $phantomId]);
    assert_equal($newId, SidecarStore::read_sidecar($sidecarName)['claude_session_id'] ?? null, 'session_start.php: a session-id with no matching transcript file anywhere is never trusted enough to rebind an existing, working sidecar');

    // --- malformed/empty stdin -> no-op, never crashes, sidecar untouched ---

    run_session_start_hook($sidecarName, null);
    assert_equal($newId, SidecarStore::read_sidecar($sidecarName)['claude_session_id'] ?? null, 'session_start.php: no-op on empty/malformed stdin payload');

    // --- adopted (non-CSM) sessions: no tmux pane at all -> no-op, no sidecar ever created ---

    $adoptedName = 'my-hand-picked-tmux-session';
    SidecarStore::delete_sidecar($adoptedName);

    run_session_start_hook(null, ['session_id' => 'adopted-id-1']); // TMUX unset in the base env - no pane at all
    assert_equal(null, SidecarStore::read_sidecar($adoptedName), 'session_start.php: no TMUX env at all -> no-op, never creates a sidecar (a bare/no-pane session can never get send-keys/capture-pane support regardless)');

    // --- adopted sessions: real tmux pane, first time seen -> CREATES a
    // brand new sidecar (unlike the CSM_SESSION_NAME path, which only ever
    // rebinds an already-existing one) - keyed off the pane's own tmux
    // session name (from `tmux display-message -p '#S'`, faked here - see
    // fake_tmux_bin_dir()), not anything app-set. ---

    $fakeTmuxDir = fake_tmux_bin_dir($adoptedName);

    $adoptedId1 = '33333333-3333-4333-8333-333333333333';
    write_fixture_transcript($adoptedId1);

    run_session_start_hook(null, ['session_id' => $adoptedId1, 'cwd' => '/home/andres/www/some-other-project'], [
        'TMUX' => '/tmp/fake-tmux-socket,12345,0',
        'PATH' => $fakeTmuxDir . ':' . (getenv('PATH') ?: '/usr/bin:/bin'),
    ]);
    $adopted = SidecarStore::read_sidecar($adoptedName);
    assert_equal($adoptedId1, $adopted['claude_session_id'] ?? null, 'session_start.php: an adopted session (real tmux pane, no CSM_SESSION_NAME) gets a brand new sidecar, first time seen');
    assert_equal('/home/andres/www/some-other-project', $adopted['workdir'] ?? null, 'session_start.php: an adopted session\'s workdir comes from the hook payload\'s own cwd field');
    assert_equal(false, $adopted['spawned_by_csm'] ?? null, 'session_start.php: an adopted session is recorded as spawned_by_csm=false, distinguishing it from an app-spawned one');
    assert_true(is_int($adopted['spawned_at'] ?? null), 'session_start.php: an adopted session gets a real spawned_at timestamp on first sight');

    // --- adopted sessions: firing again for the SAME pane rebinds (like
    // the CSM path), preserving the original workdir/spawned_at rather
    // than treating every subsequent /clear-triggered fire as "first
    // seen" again. ---

    $firstSpawnedAt = $adopted['spawned_at'];
    $adoptedId2 = '44444444-4444-4444-8444-444444444444';
    write_fixture_transcript($adoptedId2);
    run_session_start_hook(null, ['session_id' => $adoptedId2, 'cwd' => '/should/be/ignored'], [
        'TMUX' => '/tmp/fake-tmux-socket,12345,0',
        'PATH' => $fakeTmuxDir . ':' . (getenv('PATH') ?: '/usr/bin:/bin'),
    ]);
    $reboundAdopted = SidecarStore::read_sidecar($adoptedName);
    assert_equal($adoptedId2, $reboundAdopted['claude_session_id'] ?? null, 'session_start.php: an adopted session rotating (e.g. /clear) rebinds claude_session_id the same as a CSM one would');
    assert_equal('/home/andres/www/some-other-project', $reboundAdopted['workdir'] ?? null, 'session_start.php: an adopted session\'s workdir is preserved across a rebind, not overwritten from the new payload');
    assert_equal($firstSpawnedAt, $reboundAdopted['spawned_at'] ?? null, 'session_start.php: an adopted session\'s spawned_at is preserved across a rebind');

    SidecarStore::delete_sidecar($adoptedName);
    array_map('unlink', glob("{$fakeTmuxDir}/*") ?: []);
    rmdir($fakeTmuxDir);

    // --- adopted sessions: a path-traversal-shaped tmux session name is
    // refused rather than trusted as a bare filename (SidecarStore keys a
    // sidecar directly off this string - see sidecar_path()). ---

    $trickyTmuxDir = fake_tmux_bin_dir('../../etc/passwd');
    run_session_start_hook(null, ['session_id' => 'adopted-id-evil'], [
        'TMUX' => '/tmp/fake-tmux-socket,12345,0',
        'PATH' => $trickyTmuxDir . ':' . (getenv('PATH') ?: '/usr/bin:/bin'),
    ]);
    assert_equal(null, SidecarStore::read_sidecar('../../etc/passwd'), 'session_start.php: a tmux session name containing "/" is refused, never trusted as a sidecar filename');
    array_map('unlink', glob("{$trickyTmuxDir}/*") ?: []);
    rmdir($trickyTmuxDir);

    // --- adopted sessions: tmux itself failing (e.g. no current session
    // for that context) -> no-op, no crash, no sidecar ---

    $failingTmuxDir = fake_tmux_bin_dir(null);
    run_session_start_hook(null, ['session_id' => 'adopted-id-fail'], [
        'TMUX' => '/tmp/fake-tmux-socket,12345,0',
        'PATH' => $failingTmuxDir . ':' . (getenv('PATH') ?: '/usr/bin:/bin'),
    ]);
    assert_equal(null, SidecarStore::read_sidecar($adoptedName), 'session_start.php: tmux display-message itself failing -> no-op, never crashes');
    array_map('unlink', glob("{$failingTmuxDir}/*") ?: []);
    rmdir($failingTmuxDir);

    // --- pre_tool_use.php: no CSM_SESSION_NAME env -> no-op ---

    $preToolSessionName = 'cc-pretooltest-' . bin2hex(random_bytes(3));

    run_pre_tool_use_hook(null, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]);
    assert_equal(null, PendingToolStore::read_pending_tool($preToolSessionName), 'pre_tool_use.php: no-op (no file written) when CSM_SESSION_NAME is unset');

    // --- CSM_SESSION_NAME set + valid payload -> writes tool_name/tool_input, no sidecar required first ---

    run_pre_tool_use_hook($preToolSessionName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'echo hi'], 'tool_use_id' => 'toolu_1']);
    $written = PendingToolStore::read_pending_tool($preToolSessionName);
    assert_equal('Bash', $written['tool_name'] ?? null, 'pre_tool_use.php: records tool_name from stdin');
    assert_equal('echo hi', $written['tool_input']['command'] ?? null, 'pre_tool_use.php: records the full tool_input from stdin');

    // --- a later tool call overwrites the previous one (only the latest is ever kept) ---

    run_pre_tool_use_hook($preToolSessionName, ['tool_name' => 'Write', 'tool_input' => ['file_path' => '/tmp/x', 'content' => 'y']]);
    $overwritten = PendingToolStore::read_pending_tool($preToolSessionName);
    assert_equal('Write', $overwritten['tool_name'] ?? null, 'pre_tool_use.php: a later tool call overwrites the earlier pending-tool file');

    // --- malformed/empty stdin, or a payload missing tool_name/tool_input -> no-op, never crashes ---

    PendingToolStore::delete_pending_tool($preToolSessionName);
    run_pre_tool_use_hook($preToolSessionName, null);
    assert_equal(null, PendingToolStore::read_pending_tool($preToolSessionName), 'pre_tool_use.php: no-op on empty/malformed stdin payload');

    run_pre_tool_use_hook($preToolSessionName, ['hook_event_name' => 'PreToolUse']);
    assert_equal(null, PendingToolStore::read_pending_tool($preToolSessionName), 'pre_tool_use.php: no-op when tool_name/tool_input are missing from the payload');

    // --- never emits stdout - a hook that prints anything (even {}) could be read as an explicit permission decision ---

    assert_equal('', run_pre_tool_use_hook($preToolSessionName, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]), 'pre_tool_use.php: writes nothing to stdout, deferring the permission decision entirely to Claude Code\'s normal flow');
} finally {
    @unlink($settingsPath);
    @rmdir(dirname($settingsPath));
    array_map('unlink', glob("{$fixtureSidecarDir}/*") ?: []);
    @rmdir($fixtureSidecarDir);
    array_map('unlink', glob("{$fixtureHome}/.claude/projects/fixture-project/*") ?: []);
    @rmdir("{$fixtureHome}/.claude/projects/fixture-project");
    @rmdir("{$fixtureHome}/.claude/projects");
    @rmdir("{$fixtureHome}/.claude");
    @rmdir($fixtureHome);
}

test_exit();

/**
 * Runs the real host-agent/hooks/session_start.php as a subprocess, same
 * as Claude Code itself would - $csmSessionName becomes its CSM_SESSION_NAME
 * env var (omitted entirely when null, mirroring a plain untracked claude
 * process), $payload is JSON-encoded to its stdin (raw '' when null, to
 * exercise the empty/malformed-input path). $extraEnv merges in on top of
 * the base env - used by the tmux-adoption tests below to set TMUX (so the
 * hook believes it's running inside a pane) and override PATH to a
 * fixture directory containing a fake `tmux` executable (see
 * fake_tmux_bin_dir()) instead of the real one, so a test can never touch
 * the real tmux server.
 *
 * @param array<string, mixed>|null $payload
 * @param array<string, string> $extraEnv
 */
function run_session_start_hook(?string $csmSessionName, ?array $payload, array $extraEnv = []): void
{
    $env = [
        'HOME_ROOT' => Config::home_root(),
        'SIDECAR_DIR' => Config::sidecar_dir(),
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    ];

    if ($csmSessionName !== null) {
        $env['CSM_SESSION_NAME'] = $csmSessionName;
    }

    $env = $extraEnv + $env;

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['php', dirname(__DIR__) . '/host-agent/hooks/session_start.php'],
        $descriptors,
        $pipes,
        null,
        $env
    );

    if (!is_resource($process)) {
        assert_true(false, 'run_session_start_hook: failed to start subprocess');
        return;
    }

    fwrite($pipes[0], $payload !== null ? json_encode($payload) : '');
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}

/**
 * Creates a real, minimal transcript file under the fixture HOME_ROOT's
 * .claude/projects tree so TranscriptService::find_transcript_path()
 * (used by session_start.php to refuse trusting a session-id with no real
 * transcript - see the 2026-08-08 regression test above) finds it. The
 * containing directory name is arbitrary - find_transcript_path() globs by
 * session-id filename only, never decodes the directory name.
 */
function write_fixture_transcript(string $sessionId): void
{
    $dir = Config::home_root() . '/.claude/projects/fixture-project';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    file_put_contents("{$dir}/{$sessionId}.jsonl", json_encode(['type' => 'user', 'sessionId' => $sessionId]) . "\n");
}

/**
 * A fake `tmux` executable (just enough to fool TmuxService::tmux_run()'s
 * `['tmux', '-S', socket, 'display-message', '-p', '#S']` invocation) in
 * its own fixture directory - $sessionNameOutput is echoed verbatim
 * regardless of the real args passed, or nothing + a non-zero exit when
 * null (simulating a real tmux failure, e.g. no current session). Putting
 * this directory FIRST on PATH is what keeps the real system tmux (and so
 * the real, possibly-live production tmux server) completely out of reach
 * for these tests.
 */
function fake_tmux_bin_dir(?string $sessionNameOutput): string
{
    $dir = sys_get_temp_dir() . '/csm-test-fake-tmux-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    $script = $sessionNameOutput === null
        ? "#!/bin/bash\nexit 1\n"
        : "#!/bin/bash\necho " . escapeshellarg($sessionNameOutput) . "\n";

    file_put_contents("{$dir}/tmux", $script);
    chmod("{$dir}/tmux", 0700);

    return $dir;
}

/**
 * Same shape as run_session_start_hook(), for host-agent/hooks/pre_tool_use.php
 * - returns its stdout so callers can assert it's always empty (see
 * PendingToolStore::write_pending_tool()'s "never affects the permission decision" contract).
 *
 * @param array<string, mixed>|null $payload
 */
function run_pre_tool_use_hook(?string $csmSessionName, ?array $payload): string
{
    $env = [
        'HOME_ROOT' => Config::home_root(),
        'SIDECAR_DIR' => Config::sidecar_dir(),
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    ];

    if ($csmSessionName !== null) {
        $env['CSM_SESSION_NAME'] = $csmSessionName;
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['php', dirname(__DIR__) . '/host-agent/hooks/pre_tool_use.php'],
        $descriptors,
        $pipes,
        null,
        $env
    );

    if (!is_resource($process)) {
        assert_true(false, 'run_pre_tool_use_hook: failed to start subprocess');
        return '';
    }

    fwrite($pipes[0], $payload !== null ? json_encode($payload) : '');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return (string)$stdout;
}
