<?php

declare(strict_types=1);

namespace HostAgent\Agents;

use HostAgent\Services\Config;
use HostAgent\Services\HookService;
use HostAgent\Services\PermissionMode;
use HostAgent\Services\SessionLifecycleService;

/**
 * The first (and, until Antigravity ships, only) AgentAdapter
 * implementation - a thin wrapper around this app's existing
 * Claude-Code-specific code (Config::claude_bin(), HookService,
 * PermissionMode), not a rewrite of any of it. Extracted 2026-08-24 as
 * Phase 1 of docs/antigravity-adapter-plan.md - a pure refactor, byte-for-
 * byte identical spawn argv/hook behavior to what SessionLifecycleService
 * built inline before this existed.
 */
class ClaudeCodeAdapter implements AgentAdapter
{
    public function id(): string
    {
        return 'claude';
    }

    public function label(): string
    {
        return 'Claude Code';
    }

    public function session_name_prefix(): string
    {
        return 'cc';
    }

    /**
     * $options['enable_task_tools'] (bool) and $options['starting_mode']
     * (?string, this app's own manual/accept edits/plan/auto vocabulary)
     * mirror create_cc_session()'s own former inline parameters exactly -
     * see that method's docblock (unchanged) for why each exists.
     */
    public function build_spawn_argv(array $options): array
    {
        $sessionId = SessionLifecycleService::generate_uuid_v4();
        $argv = [Config::claude_bin(), '--session-id', $sessionId];

        if (!empty($options['enable_task_tools'])) {
            $argv[] = '--allowedTools';
            $argv[] = 'TaskCreate,TaskGet,TaskList,TaskUpdate';
        }

        $startingMode = $options['starting_mode'] ?? null;
        $realStartingMode = is_string($startingMode)
            ? (array_flip(PermissionMode::HOOK_PERMISSION_MODE_MAP)[$startingMode] ?? null)
            : null;

        if ($realStartingMode !== null) {
            $argv[] = '--permission-mode';
            $argv[] = $realStartingMode;
        }

        return ['argv' => $argv, 'assigned_id' => $sessionId];
    }

    public function check_hooks(): array
    {
        return HookService::check_session_hook();
    }

    public function install_hooks(): array
    {
        return HookService::install_session_hook();
    }

    public function permission_mode_map(): array
    {
        return PermissionMode::HOOK_PERMISSION_MODE_MAP;
    }
}
