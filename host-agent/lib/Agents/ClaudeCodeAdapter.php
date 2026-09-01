<?php

declare(strict_types=1);

namespace HostAgent\Agents;

use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\Config;
use HostAgent\Services\HookService;
use HostAgent\Services\PermissionMode;
use HostAgent\Services\SelectableModel;
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
     * mirror create_agent_session()'s own former inline parameters exactly -
     * see that method's docblock (unchanged) for why each exists.
     * $options['model'] (?string) is one of SelectableModel::PICKER_OPTIONS'
     * keys minus 'default' (sonnet/fable/opus/haiku) - the real CLI's
     * `--model` flag accepts these bare aliases directly (confirmed against
     * https://code.claude.com/docs/en/cli-reference), so this is passed
     * straight through rather than resolved to a full model id. 'default'/
     * empty/missing means "no --model flag at all", same "omit rather than
     * pass a sentinel" shape starting_mode already uses below.
     */
    public function build_spawn_argv(array $options): array
    {
        $sessionId = SessionLifecycleService::generate_uuid_v4();
        $argv = [Config::claude_bin(), '--session-id', $sessionId];

        if (!empty($options['enable_task_tools'])) {
            $argv[] = '--allowedTools';
            $argv[] = 'TaskCreate,TaskGet,TaskList,TaskUpdate';
        }

        $model = $options['model'] ?? null;

        if (is_string($model) && $model !== '' && $model !== 'default' && array_key_exists($model, SelectableModel::PICKER_OPTIONS)) {
            $argv[] = '--model';
            $argv[] = $model;
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

    /**
     * Claude Code's headless path (the Agent SDK, or one-shot `claude -p`)
     * is pay-per-API and not a drivable session Sessioneer can watch like a tmux
     * pane, so headless is intentionally not offered here - see the
     * headless-runtime plan. Tmux is the only runtime.
     */
    public function supported_runtimes(): array
    {
        return [RuntimeType::TMUX];
    }
}
