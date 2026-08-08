<?php
declare(strict_types=1);

/**
 * Exercises StatuslineMarkerService (parsing a live-pane session-id marker,
 * locating/installing into a statusLine script) and
 * SessionService::self_heal_claude_session_id() (the consuming side - see
 * both classes' own docblocks for why this exists: a second, independent
 * cross-check against SidecarStore's claude_session_id, on top of the
 * SessionStart hook's own transcript-existence check added the same day,
 * 2026-08-08). Uses its own fixture HOME_ROOT/SIDECAR_DIR, never the real
 * ~/.claude/settings.json, statusline script, or sidecar dir.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\SessionService;
use HostAgent\Services\StatuslineMarkerService;
use HostAgent\Stores\SidecarStore;

const REAL_HOME_ROOT_SM = '/home/andres';

$fixtureHome = sys_get_temp_dir() . '/csm-test-statusline-home-' . bin2hex(random_bytes(4));
$fixtureSidecarDir = sys_get_temp_dir() . '/csm-test-statusline-sidecars-' . bin2hex(random_bytes(4));

putenv("HOME_ROOT={$fixtureHome}");
putenv("SIDECAR_DIR={$fixtureSidecarDir}");

if (Config::home_root() === REAL_HOME_ROOT_SM) {
    fwrite(STDERR, "REFUSING TO RUN: HOME_ROOT still resolves to the real home directory.\n");
    exit(1);
}

mkdir($fixtureSidecarDir, 0700, true);
mkdir("{$fixtureHome}/.claude", 0700, true);

$settingsPath = Config::claude_settings_path();

try {
    // --- parse_marker_from_pane(): happy/sad paths ---

    $id = 'abcdef01-2345-6789-abcd-ef0123456789';
    $empty = ['session_id' => null, 'context_used_percentage' => null, 'git_worktree' => null];

    $full = StatuslineMarkerService::parse_marker_from_pane("Opus | ctx: 12%\n\x1b[2mcsm-data:{\"session_id\":\"{$id}\",\"ctx_pct\":42,\"git_worktree\":\"my-feature\"}\x1b[0m\n");
    assert_equal($id, $full['session_id'], 'parse_marker_from_pane: extracts session_id from a real rendered pane (ANSI codes and all)');
    assert_equal(42.0, $full['context_used_percentage'], 'parse_marker_from_pane: extracts context_used_percentage');
    assert_equal('my-feature', $full['git_worktree'], 'parse_marker_from_pane: extracts git_worktree');

    // Fields the shell side drops (jq's with_entries(select(.value != null))
    // omits null/absent ones - see StatuslineMarkerService::JQ_FILTER) come
    // back null here too, without the whole marker being rejected.
    $partial = StatuslineMarkerService::parse_marker_from_pane("csm-data:{\"session_id\":\"{$id}\"}");
    assert_equal($id, $partial['session_id'], 'parse_marker_from_pane: session_id alone (no ctx_pct/git_worktree keys) still parses');
    assert_equal(null, $partial['context_used_percentage'], 'parse_marker_from_pane: a genuinely absent key is null, not 0 or an error');
    assert_equal(null, $partial['git_worktree'], 'parse_marker_from_pane: a genuinely absent key is null, not an error');

    assert_equal($empty, StatuslineMarkerService::parse_marker_from_pane('no marker here at all'), 'parse_marker_from_pane: all-null when the marker is entirely absent');
    assert_equal($empty, StatuslineMarkerService::parse_marker_from_pane('csm-data:{not valid json'), 'parse_marker_from_pane: all-null on unparseable JSON after the marker, never crashes');
    assert_equal(null, StatuslineMarkerService::parse_marker_from_pane("csm-data:{\"session_id\":\"not-a-real-uuid\"}")['session_id'], 'parse_marker_from_pane: session_id is null when the JSON value is not UUID-shaped');
    assert_equal(strtolower($id), StatuslineMarkerService::parse_marker_from_pane("csm-data:{\"session_id\":\"" . strtoupper($id) . "\"}")['session_id'], 'parse_marker_from_pane: session_id is case-insensitive, normalized to lowercase');

    // --- locate_statusline_script(): happy/sad paths ---

    $scriptPath = "{$fixtureHome}/my-statusline.sh";
    file_put_contents($scriptPath, "#!/usr/bin/env bash\necho hi\n");
    chmod($scriptPath, 0700);

    assert_equal(
        $scriptPath,
        StatuslineMarkerService::locate_statusline_script(['statusLine' => ['type' => 'command', 'command' => "bash {$scriptPath}"]]),
        'locate_statusline_script: finds the script file referenced by a real, writable command'
    );
    assert_equal(null, StatuslineMarkerService::locate_statusline_script([]), 'locate_statusline_script: null when statusLine is not configured at all');
    assert_equal(null, StatuslineMarkerService::locate_statusline_script(['statusLine' => ['type' => 'other', 'command' => "bash {$scriptPath}"]]), 'locate_statusline_script: null when type is not "command"');
    assert_equal(null, StatuslineMarkerService::locate_statusline_script(['statusLine' => ['type' => 'command', 'command' => 'bash /does/not/exist.sh']]), 'locate_statusline_script: null when the referenced file does not exist');
    assert_equal(null, StatuslineMarkerService::locate_statusline_script(['statusLine' => ['type' => 'command', 'command' => 'echo inline-one-liner']]), 'locate_statusline_script: null for an inline command with no locatable file path');

    unlink($scriptPath);

    // --- install_statusline_marker(): appends into an EXISTING statusline script, preserving its own output ---

    file_put_contents($scriptPath, "#!/usr/bin/env bash\ninput=\$(cat)\nmodel=\$(echo \"\$input\" | jq -r '.model.display_name // \"\"')\nprintf '%s' \"\$model\"\necho \"\"\n");
    chmod($scriptPath, 0700);
    file_put_contents($settingsPath, json_encode(['statusLine' => ['type' => 'command', 'command' => "bash {$scriptPath}"], 'theme' => 'dark']));

    $preCheck = StatuslineMarkerService::check_statusline_marker();
    assert_equal(true, $preCheck['ok'], 'check_statusline_marker: ok before install');
    assert_equal(false, $preCheck['installed'], 'check_statusline_marker: not installed before install');

    $install = StatuslineMarkerService::install_statusline_marker();
    assert_equal(true, $install['ok'], 'install_statusline_marker: succeeds against an existing statusline script');
    assert_equal(true, $install['installed'], 'install_statusline_marker: reports installed');

    $postCheck = StatuslineMarkerService::check_statusline_marker();
    assert_equal(true, $postCheck['installed'], 'check_statusline_marker: installed after install_statusline_marker()');

    $settingsAfter = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal('dark', $settingsAfter['theme'] ?? null, 'install_statusline_marker: preserves unrelated pre-existing settings.json content');
    assert_equal("bash {$scriptPath}", $settingsAfter['statusLine']['command'] ?? null, 'install_statusline_marker: leaves the pre-existing statusLine command untouched (appends to the script itself, not a new one)');

    // Running the patched script for real with a JSON payload must still
    // print the original script's own output (model name), AND our marker -
    // the stdin-replay trick (exec 0<<< "$csm_statusline_input") must not
    // break the original script's own later `cat`.
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['bash', $scriptPath], $descriptors, $pipes);
    fwrite($pipes[0], json_encode(['model' => ['display_name' => 'Opus'], 'session_id' => $id]));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    assert_contains('Opus', $out, 'install_statusline_marker: the patched script still produces the original script\'s own output');
    assert_equal($id, StatuslineMarkerService::parse_marker_from_pane($out)['session_id'], 'install_statusline_marker: the patched script\'s output carries a parseable session-id marker');

    // Idempotency: installing again does not duplicate the marker block.
    $secondInstall = StatuslineMarkerService::install_statusline_marker();
    assert_equal(true, $secondInstall['installed'], 'install_statusline_marker: calling twice still reports installed');
    // Distinct from the CAPTURE_* marker (which also ends in "... session-id
    // marker (managed, safe to delete) >>>") - only the exact MARKER_BEGIN
    // line itself is unique to the actual marker block being duplicated.
    $scriptContent = (string)file_get_contents($scriptPath);
    assert_equal(1, substr_count($scriptContent, '# >>> claude-session-manager: session-id marker (managed, safe to delete) >>>'), 'install_statusline_marker: calling twice does not duplicate the marker block');

    unlink($settingsPath);
    unlink($scriptPath);

    // --- install_statusline_marker(): no statusLine configured at all -> installs a fallback script this app owns ---

    $fallbackPath = Config::statusline_fallback_script_path();
    @unlink($fallbackPath);

    file_put_contents($settingsPath, json_encode(['theme' => 'light']));
    $fallbackInstall = StatuslineMarkerService::install_statusline_marker();
    assert_equal(true, $fallbackInstall['ok'], 'install_statusline_marker: succeeds when no statusLine was configured at all');
    assert_equal(true, $fallbackInstall['installed'], 'install_statusline_marker: installed=true after creating the fallback script');
    assert_true(is_file($fallbackPath), 'install_statusline_marker: creates the fallback script file');

    $settingsAfterFallback = json_decode((string)file_get_contents($settingsPath), true);
    assert_equal('light', $settingsAfterFallback['theme'] ?? null, 'install_statusline_marker: preserves unrelated settings when installing the fallback');
    assert_equal('bash ' . $fallbackPath, $settingsAfterFallback['statusLine']['command'] ?? null, 'install_statusline_marker: points statusLine at the new fallback script');

    unlink($fallbackPath);
    unlink($settingsPath);

    // --- install_statusline_marker(): malformed settings.json -> refuses, leaves it untouched ---

    file_put_contents($settingsPath, '{not valid json');
    $malformedInstall = StatuslineMarkerService::install_statusline_marker();
    assert_equal(false, $malformedInstall['ok'], 'install_statusline_marker: refuses a malformed settings.json');
    assert_equal('{not valid json', file_get_contents($settingsPath), 'install_statusline_marker: leaves a malformed settings.json byte-for-byte untouched');
    unlink($settingsPath);

    // --- install_statusline_marker(): statusLine configured but unrecognized shape -> refuses, does not guess ---

    file_put_contents($settingsPath, json_encode(['statusLine' => ['type' => 'command', 'command' => 'some-inline-thing --no-file-path']]));
    $unrecognized = StatuslineMarkerService::install_statusline_marker();
    assert_equal(false, $unrecognized['ok'], 'install_statusline_marker: refuses when statusLine is configured but no script file can be located');
    unlink($settingsPath);

    // --- SessionService::self_heal_claude_session_id(): the consuming side ---

    $projectsDir = "{$fixtureHome}/.claude/projects/fixture-project";
    mkdir($projectsDir, 0700, true);

    $realId = '11111111-1111-4111-8111-111111111111';
    $phantomId = '99999999-9999-4999-8999-999999999999';
    file_put_contents("{$projectsDir}/{$realId}.jsonl", json_encode(['type' => 'user', 'sessionId' => $realId]) . "\n");

    $sidecar = ['workdir' => '/fixture/workdir', 'spawned_at' => 1000, 'claude_session_id' => 'stale-old-id', 'spawned_by_csm' => true];

    // Happy path: live marker disagrees and resolves to a real transcript -> heals.
    $healed = SessionService::self_heal_claude_session_id('cc-selfheal-test', $sidecar, 'stale-old-id', $realId);
    assert_equal($realId, $healed, 'self_heal_claude_session_id: returns the live id when it resolves to a real transcript');
    $healedSidecar = SidecarStore::read_sidecar('cc-selfheal-test');
    assert_equal($realId, $healedSidecar['claude_session_id'] ?? null, 'self_heal_claude_session_id: rewrites the sidecar with the healed id');
    assert_equal('/fixture/workdir', $healedSidecar['workdir'] ?? null, 'self_heal_claude_session_id: preserves workdir across the heal');
    assert_equal(1000, $healedSidecar['spawned_at'] ?? null, 'self_heal_claude_session_id: preserves spawned_at across the heal');
    assert_equal(true, $healedSidecar['spawned_by_csm'] ?? null, 'self_heal_claude_session_id: preserves spawned_by_csm across the heal');

    // Sad path: live marker disagrees but has NO real transcript (phantom, e.g. a nested claude invocation that never wrote one) -> not trusted, no heal.
    SidecarStore::write_sidecar('cc-selfheal-phantom', ['workdir' => '/x', 'spawned_at' => 500, 'claude_session_id' => 'original-id', 'spawned_by_csm' => true]);
    $notHealed = SessionService::self_heal_claude_session_id('cc-selfheal-phantom', SidecarStore::read_sidecar('cc-selfheal-phantom'), 'original-id', $phantomId);
    assert_equal('original-id', $notHealed, 'self_heal_claude_session_id: a live id with no matching transcript is never trusted enough to override a working sidecar');
    assert_equal('original-id', SidecarStore::read_sidecar('cc-selfheal-phantom')['claude_session_id'] ?? null, 'self_heal_claude_session_id: sidecar is left untouched when the live id is phantom');

    // Sad path: no sidecar at all -> no-op, no crash.
    $noSidecarResult = SessionService::self_heal_claude_session_id('cc-selfheal-nosidecar', null, null, $realId);
    assert_equal(null, $noSidecarResult, 'self_heal_claude_session_id: no-op (returns the original null) when there is no sidecar to preserve fields from');
    assert_equal(null, SidecarStore::read_sidecar('cc-selfheal-nosidecar'), 'self_heal_claude_session_id: never creates a sidecar from a live marker alone');

    // Sad path: pane has no marker at all -> parse_marker_from_pane() would
    // hand build_session_entry() a null session_id, so this is what it
    // passes through -> no-op.
    $noMarkerResult = SessionService::self_heal_claude_session_id('cc-selfheal-nomarker', $sidecar, 'stale-old-id', null);
    assert_equal('stale-old-id', $noMarkerResult, 'self_heal_claude_session_id: no-op when there is no live id at all (pane carried no marker)');

    // Sad path: live marker already matches the current id -> no unnecessary write, same value returned.
    $alreadyMatching = SessionService::self_heal_claude_session_id('cc-selfheal-match', $healedSidecar, $realId, $realId);
    assert_equal($realId, $alreadyMatching, 'self_heal_claude_session_id: returns the same id unchanged when the live marker already matches');
} finally {
    @unlink($settingsPath);
    @unlink(Config::statusline_fallback_script_path());
    array_map('unlink', glob("{$fixtureSidecarDir}/*") ?: []);
    @rmdir($fixtureSidecarDir);
    array_map('unlink', glob("{$fixtureHome}/.claude/projects/fixture-project/*") ?: []);
    @rmdir("{$fixtureHome}/.claude/projects/fixture-project");
    @rmdir("{$fixtureHome}/.claude/projects");
    @rmdir("{$fixtureHome}/.claude");
    @unlink("{$fixtureHome}/my-statusline.sh");
    @rmdir($fixtureHome);
}

test_exit();
