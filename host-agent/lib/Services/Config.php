<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Host-specific paths/thresholds, overridable via env (see
 * host-agent/.env.example, loaded by systemd's EnvironmentFile= in
 * production) so tests can point at an isolated tmux socket and a fixture
 * claude binary instead of the real host session.
 *
 * No installer's own personal paths are hardcoded here (removed
 * 2026-08-09, ahead of open-sourcing this repo) - every default below is
 * either genuinely environment-derived (this process's real $HOME/uid,
 * which is correct on ANY machine, not just the original author's) or
 * empty, which downstream code/README setup steps treat as "not
 * configured yet" rather than silently trying to use someone else's
 * machine's paths. See host-agent/.env.example for what actually needs
 * setting on a fresh install.
 */
class Config
{
    public static function csm_config(string $key, string $default): string
    {
        $value = getenv($key);
        return $value !== false && $value !== '' ? $value : $default;
    }

    /**
     * No safe universal guess exists for where `claude` was actually
     * installed (npm global, nvm, a native installer, ...) - unlike
     * every other path below, there's no environment-derived fallback
     * that's reliably correct, so this is empty until CLAUDE_BIN is set
     * explicitly. Run `which claude` to find the real path.
     */
    public static function claude_bin(): string
    {
        return self::csm_config('CLAUDE_BIN', '');
    }

    /**
     * Starting folder for the New Session browser - home_root() itself is
     * a reasonable generic default (always exists, always readable by
     * this process); set WWW_ROOT explicitly to wherever projects
     * actually live if that's not directly under the home directory.
     */
    public static function www_root(): string
    {
        return self::csm_config('WWW_ROOT', self::home_root());
    }

    public static function home_root(): string
    {
        return self::csm_config('HOME_ROOT', getenv('HOME') ?: '');
    }

    /**
     * tmux's own real default socket path already embeds the real uid
     * (getmyuid() - core PHP, no posix extension needed) rather than any
     * one specific installer's - this is what a plain `tmux` command with
     * no -S/-L would resolve to on ANY machine, not a hardcoded guess.
     */
    public static function tmux_socket(): string
    {
        return self::csm_config('TMUX_SOCKET', '/tmp/tmux-' . getmyuid() . '/default');
    }

    public static function sidecar_dir(): string
    {
        return self::csm_config('SIDECAR_DIR', '/run/user/' . getmyuid() . '/csm-sessions');
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

    /**
     * A `claude-quota`-shaped script (scrapes Claude Code's own `/usage`
     * panel - see README) is NOT part of this repo and has no universal
     * install location - empty (the quota footer's already-existing
     * "unavailable" fallback, not a new failure mode) until set explicitly.
     */
    public static function claude_quota_bin(): string
    {
        return self::csm_config('CLAUDE_QUOTA_BIN', '');
    }

    public static function quota_cache_file(): string
    {
        return self::csm_config('QUOTA_CACHE_FILE', '/run/user/' . getmyuid() . '/csm-agent-quota-cache.json');
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
     * This app's own checkout root - derived from this very file's real
     * location (Config.php lives at host-agent/lib/Services/Config.php,
     * three levels below repo root) rather than any hardcoded path, so
     * it's correct regardless of where the repo was actually cloned to.
     * Still overridable via env for tests, same convention as every other
     * value here.
     */
    public static function csm_repo_root(): string
    {
        return self::csm_config('CSM_REPO_ROOT', dirname(__DIR__, 3));
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

    /**
     * Fallback statusline script this app installs (and points
     * ~/.claude/settings.json's statusLine at) only when Andres has no
     * statusLine of his own configured yet - see StatuslineMarkerService.
     * When one already exists, its own script file is appended to instead;
     * this path is never touched in that case.
     */
    public static function statusline_fallback_script_path(): string
    {
        return self::home_root() . '/.claude/csm-statusline.sh';
    }
}
