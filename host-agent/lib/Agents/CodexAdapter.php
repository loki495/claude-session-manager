<?php

declare(strict_types=1);

namespace HostAgent\Agents;

use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\PushHealthService;

/** Codex is server-owned in Sessioneer; it is never spawned into tmux. */
class CodexAdapter implements AgentAdapter
{
    public function id(): string { return 'codex'; }
    public function label(): string { return 'Codex'; }
    public function session_name_prefix(): string { return 'cx'; }

    public function build_spawn_argv(array $options): array
    {
        return ['argv' => [], 'assigned_id' => null];
    }

    public function check_hooks(): array
    {
        $reachable = PushHealthService::codex_bridge_reachable();

        return ['ok' => $reachable['ok'] ?? false, 'installed' => true, 'message' => $reachable['detail'] ?? 'Codex bridge unavailable'];
    }

    public function install_hooks(): array
    {
        return $this->check_hooks();
    }

    public function permission_mode_map(): array { return []; }

    public function supported_runtimes(): array
    {
        return [RuntimeType::HEADLESS];
    }
}
