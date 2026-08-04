<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Host-specific paths/thresholds, overridable via env (see
 * host-agent/.env.example, loaded by systemd's EnvironmentFile= in
 * production) so tests can point at an isolated tmux socket and a fixture
 * claude binary instead of the real host session. Falls back to the real
 * production values when unset.
 */
class Config
{
    public static function csm_config(string $key, string $default): string
    {
        $value = getenv($key);
        return $value !== false && $value !== '' ? $value : $default;
    }

    public static function claude_bin(): string
    {
        return self::csm_config('CLAUDE_BIN', '/home/andres/.local/bin/claude');
    }

    public static function www_root(): string
    {
        return self::csm_config('WWW_ROOT', '/home/andres/www');
    }

    public static function home_root(): string
    {
        return self::csm_config('HOME_ROOT', '/home/andres');
    }

    public static function tmux_socket(): string
    {
        return self::csm_config('TMUX_SOCKET', '/tmp/tmux-1000/default');
    }

    public static function sidecar_dir(): string
    {
        return self::csm_config('SIDECAR_DIR', '/run/user/1000/csm-sessions');
    }

    public static function cleanup_threshold_seconds(): int
    {
        return (int)self::csm_config('CLEANUP_THRESHOLD_SECONDS', '43200'); // 12h
    }

    /**
     * A new session's tmux pane, with no real client ever attached to give it
     * a size to inherit, otherwise falls back to the SERVER's default-size
     * (confirmed live: 80x24, tmux's own classic default) - nowhere near
     * enough for Claude Code's own TUI to render a long tool-permission
     * preview (a big Write, a long Bash script) without cutting it short.
     * Found live: capture-pane (even with extra -S scrollback) came back
     * missing the earlier lines entirely - not truncated by this app's own
     * parsing, genuinely never rendered by Claude Code in the first place,
     * since it adapts its own output to whatever height it detects. Sized
     * generously since nothing is ever really "looking at" this pane as a
     * literal terminal window - it only ever gets read via capture-pane.
     */
    public static function new_session_pane_width(): int
    {
        return (int)self::csm_config('TMUX_PANE_WIDTH', '200');
    }

    public static function new_session_pane_height(): int
    {
        return (int)self::csm_config('TMUX_PANE_HEIGHT', '150');
    }

    public static function claude_quota_bin(): string
    {
        return self::csm_config('CLAUDE_QUOTA_BIN', '/home/andres/dotfiles/bin/claude-quota');
    }

    public static function quota_cache_file(): string
    {
        return self::csm_config('QUOTA_CACHE_FILE', '/run/user/1000/csm-agent-quota-cache.json');
    }

    public static function quota_cache_ttl_seconds(): int
    {
        return (int)self::csm_config('QUOTA_CACHE_TTL_SECONDS', '300'); // 5min
    }

    public static function quota_timeout_seconds(): int
    {
        return (int)self::csm_config('QUOTA_TIMEOUT_SECONDS', '90');
    }

    /**
     * This app's own checkout root - hardcoded default matches every other
     * host-specific path in this file (e.g. claude_bin()); overridable via env
     * for tests, same convention.
     */
    public static function csm_repo_root(): string
    {
        return self::csm_config('CSM_REPO_ROOT', '/home/andres/www/claude-session-manager');
    }

    public static function claude_settings_path(): string
    {
        return self::home_root() . '/.claude/settings.json';
    }

    /**
     * The exact `command` string this app's SessionStart hook entry is
     * registered under - both session_start_hook_present() and
     * install_session_hook() key off this same string, so "is it already
     * there" and "what do we add" can never drift apart.
     */
    public static function session_start_hook_command(): string
    {
        return 'php ' . self::csm_repo_root() . '/host-agent/hooks/session_start.php';
    }

    /**
     * Same convention as session_start_hook_command(), for the PreToolUse
     * hook (see host-agent/hooks/pre_tool_use.php) that records a pending
     * tool call's full, untruncated tool_input for the blocked-prompt preview.
     */
    public static function pre_tool_use_hook_command(): string
    {
        return 'php ' . self::csm_repo_root() . '/host-agent/hooks/pre_tool_use.php';
    }
}
