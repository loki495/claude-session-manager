<?php
declare(strict_types=1);

/**
 * Exercises HostAgent\Agents\AgentRegistry/ClaudeCodeAdapter - the seam
 * introduced 2026-08-24 (docs/antigravity-adapter-plan.md Phase 1) ahead
 * of a second AgentAdapter implementation. build_spawn_argv() must produce
 * BYTE-IDENTICAL argv to what SessionLifecycleService::create_cc_session()
 * built inline before this extraction - this file is what proves that, not
 * a rewrite of HookService's own coverage (see test_session_hook.php for
 * that; check_hooks()/install_hooks() here only confirm delegation, not
 * every edge case HookService itself already covers).
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Agents\AgentRegistry;
use HostAgent\Agents\ClaudeCodeAdapter;
use HostAgent\Services\Config;
use HostAgent\Services\HookService;
use HostAgent\Services\PermissionMode;

const REAL_HOME_ROOT_AA = '/home/user';

$fixtureHome = sys_get_temp_dir() . '/csm-test-agent-adapter-home-' . bin2hex(random_bytes(4));
putenv("HOME_ROOT={$fixtureHome}");

if (Config::home_root() === REAL_HOME_ROOT_AA) {
    fwrite(STDERR, "REFUSING TO RUN: HOME_ROOT still resolves to the real home directory.\n");
    exit(1);
}

$settingsPath = Config::claude_settings_path();

try {
    // --- AgentRegistry: identity/lookup ---

    assert_equal('claude', AgentRegistry::default_agent_id(), 'default_agent_id: claude, unchanged from every existing caller before this class existed');
    assert_equal(['claude'], AgentRegistry::known_agent_ids(), 'known_agent_ids: only claude registered so far');

    $adapter = AgentRegistry::get('claude');
    assert_true($adapter instanceof ClaudeCodeAdapter, 'AgentRegistry::get(\'claude\'): returns a ClaudeCodeAdapter instance');
    assert_true(AgentRegistry::get('claude') === $adapter, 'AgentRegistry::get(): returns the same cached instance on a second call');

    $threw = false;
    try {
        AgentRegistry::get('not-a-real-agent');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assert_true($threw, 'AgentRegistry::get(): throws InvalidArgumentException for an unknown agent id');

    // --- ClaudeCodeAdapter: static identity ---

    assert_equal('claude', $adapter->id(), 'id(): claude');
    assert_equal('Claude Code', $adapter->label(), 'label(): Claude Code');
    assert_equal('cc', $adapter->session_name_prefix(), 'session_name_prefix(): cc, matching the tmux session names this app has always generated');
    assert_equal(PermissionMode::HOOK_PERMISSION_MODE_MAP, $adapter->permission_mode_map(), 'permission_mode_map(): the exact same map PermissionMode already exposes, not a second copy of it');

    // --- build_spawn_argv(): the actual extraction from create_cc_session() ---

    $bare = $adapter->build_spawn_argv([]);
    assert_equal([Config::claude_bin(), '--session-id', $bare['assigned_id']], $bare['argv'], 'build_spawn_argv([]): bare argv is just the binary + --session-id <assigned uuid>, no extra flags');
    assert_equal(1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)$bare['assigned_id']), 'build_spawn_argv([]): assigned_id is a real v4 UUID');

    $second = $adapter->build_spawn_argv([]);
    assert_true($bare['assigned_id'] !== $second['assigned_id'], 'build_spawn_argv(): a fresh call gets a fresh session id, never reused');

    $withTaskTools = $adapter->build_spawn_argv(['enable_task_tools' => true]);
    assert_true(in_array('--allowedTools', $withTaskTools['argv'], true), 'build_spawn_argv(enable_task_tools): includes --allowedTools');
    assert_true(in_array('TaskCreate,TaskGet,TaskList,TaskUpdate', $withTaskTools['argv'], true), 'build_spawn_argv(enable_task_tools): names the exact Task* tool family');

    $withoutTaskTools = $adapter->build_spawn_argv(['enable_task_tools' => false]);
    assert_true(!in_array('--allowedTools', $withoutTaskTools['argv'], true), 'build_spawn_argv(enable_task_tools: false): omits --allowedTools entirely');

    $withMode = $adapter->build_spawn_argv(['starting_mode' => 'accept edits']);
    $modeIdx = array_search('--permission-mode', $withMode['argv'], true);
    assert_true($modeIdx !== false, 'build_spawn_argv(starting_mode): includes --permission-mode for a recognized mode');
    assert_equal('acceptEdits', $withMode['argv'][$modeIdx + 1] ?? null, 'build_spawn_argv(starting_mode): translates this app\'s "accept edits" to Claude Code\'s real "acceptEdits" enum value via the same HOOK_PERMISSION_MODE_MAP PermissionMode already owns');

    $withUnknownMode = $adapter->build_spawn_argv(['starting_mode' => 'not-a-real-mode']);
    assert_true(!in_array('--permission-mode', $withUnknownMode['argv'], true), 'build_spawn_argv(starting_mode): an unrecognized mode is silently ignored (whitelisted, never trusted straight through) rather than passed to Claude Code raw');

    $withNullMode = $adapter->build_spawn_argv(['starting_mode' => null]);
    assert_true(!in_array('--permission-mode', $withNullMode['argv'], true), 'build_spawn_argv(starting_mode: null): omits the flag entirely, same as never passing the option at all');

    // --- check_hooks()/install_hooks(): pure delegation to HookService ---

    assert_equal(HookService::check_session_hook(), $adapter->check_hooks(), 'check_hooks(): identical result to calling HookService::check_session_hook() directly');

    $adapterInstall = $adapter->install_hooks();
    assert_equal(true, $adapterInstall['ok'], 'install_hooks(): succeeds against a fresh fixture settings.json');
    assert_true(is_file($settingsPath), 'install_hooks(): actually wrote ~/.claude/settings.json, proving this reached the real HookService::install_session_hook(), not a stub');
    assert_equal(HookService::check_session_hook(), $adapter->check_hooks(), 'check_hooks(): still identical to HookService::check_session_hook() after installing');
} finally {
    @unlink($settingsPath);
    @rmdir(dirname($settingsPath));
    @rmdir($fixtureHome);
}

test_exit();
