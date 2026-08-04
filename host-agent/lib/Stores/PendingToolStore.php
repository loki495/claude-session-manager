<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * The most recent PreToolUse hook payload recorded for a session (see
 * host-agent/hooks/pre_tool_use.php) - one file per session, always
 * overwritten by the latest tool call, never appended. Only meaningful
 * alongside a pane-detected blocking prompt (see
 * PromptParser::augment_prompt_with_pending_tool()); by itself this says
 * nothing about whether that tool call actually ended up needing approval.
 */
class PendingToolStore
{
    public static function pending_tool_path(string $sessionName): string
    {
        return Config::sidecar_dir() . '/' . $sessionName . '.pending-tool.json';
    }

    /**
     * @return array{tool_name:?string, tool_input:?array, written_at:?int}|null
     */
    public static function read_pending_tool(string $sessionName): ?array
    {
        $raw = @file_get_contents(self::pending_tool_path($sessionName));

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function write_pending_tool(string $sessionName, array $data): void
    {
        if (!is_dir(Config::sidecar_dir())) {
            @mkdir(Config::sidecar_dir(), 0700, true);
        }

        @file_put_contents(self::pending_tool_path($sessionName), json_encode($data));
    }

    public static function delete_pending_tool(string $sessionName): void
    {
        @unlink(self::pending_tool_path($sessionName));
    }
}
