<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Cross-checks/self-heals SidecarStore's claude_session_id against a live,
 * on-screen signal - Claude Code's own statusLine feature includes
 * session_id in the JSON it feeds a configured statusline script, and that
 * script's rendered output is real terminal content, capturable the same
 * way TmuxService::tmux_capture_pane() already reads pane content for
 * everything else (blocked prompts, quota - see QuotaService::
 * quota_from_live_pane()).
 *
 * This is a stronger signal than the SessionStart hook alone for the
 * exact corruption class found live 2026-08-08 (a `claude` process run
 * manually from inside a tracked pane's own Bash tool inherits
 * CSM_SESSION_NAME and fires its own genuine SessionStart, clobbering the
 * pane's sidecar with an unrelated session_id - see session_start.php's
 * own transcript-existence check, added the same day). The statusline
 * reflects whatever process currently owns the pane's TUI - once a nested
 * invocation like that exits, control returns to the real interactive
 * session and its own statusline redraws, self-correcting automatically
 * rather than depending on catching the right hook fire.
 *
 * Installs into Andres's own pre-existing statusline script (if he has
 * one - see locate_statusline_script()) rather than replacing it, using
 * the same clearly-marked, idempotent, safe-to-delete block convention as
 * a merge-safe settings.json edit (see HookService), just for a plain
 * shell script instead of JSON.
 */
class StatuslineMarkerService
{
    private const CAPTURE_BEGIN = '# >>> claude-session-manager: capture stdin for session-id marker (managed, safe to delete) >>>';
    private const CAPTURE_END = '# <<< claude-session-manager: capture stdin <<<';
    private const MARKER_BEGIN = '# >>> claude-session-manager: session-id marker (managed, safe to delete) >>>';
    private const MARKER_END = '# <<< claude-session-manager: session-id marker <<<';

    /**
     * Builds the compact JSON blob parse_marker_from_pane() reads back -
     * session_id plus whatever extra live signals are worth surfacing on
     * the dashboard/session page (context-window usage, git worktree name,
     * see SessionService::build_session_entry()). with_entries(select(...))
     * drops any key whose source value was null/absent rather than writing
     * a literal JSON null, so the object only ever carries what Claude Code
     * actually reported for this session at this moment. workspace.git_worktree
     * covers a plain linked worktree; worktree.name is the --worktree-flag
     * equivalent (docs: "Absent for hook-based worktrees" - the two are
     * mutually exclusive in practice, tried in this order).
     */
    private const JQ_FILTER = '{session_id: .session_id, ctx_pct: .context_window.used_percentage, git_worktree: (.workspace.git_worktree // .worktree.name)} | with_entries(select(.value != null))';

    /**
     * The live pane text carries our marker as a single compact JSON blob,
     * "csm-data:{...}" on its own line - tmux capture-pane -p strips ANSI
     * escapes by default (confirmed by QuotaService::parse_quota_from_pane()
     * matching plain-text percentages with no escape-stripping of its own),
     * so the dim styling written in the script never has to be accounted
     * for here. Every field is independently optional (the shell side
     * drops any key whose source value was null/absent, via jq's
     * with_entries(select(.value != null)) - see append_marker_to_script())
     * so a session with no context-window data yet, or outside a worktree,
     * still yields a usable session_id.
     *
     * @return array{session_id:?string, context_used_percentage:?float, git_worktree:?string}
     */
    public static function parse_marker_from_pane(string $paneContent): array
    {
        $empty = ['session_id' => null, 'context_used_percentage' => null, 'git_worktree' => null];

        if (preg_match('/csm-data:(\{[^\n]*\})/', $paneContent, $m) !== 1) {
            return $empty;
        }

        $decoded = json_decode($m[1], true);

        if (!is_array($decoded)) {
            return $empty;
        }

        $sessionId = isset($decoded['session_id']) && is_string($decoded['session_id'])
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $decoded['session_id']) === 1
            ? strtolower($decoded['session_id'])
            : null;

        $ctxPct = isset($decoded['ctx_pct']) && is_numeric($decoded['ctx_pct']) ? (float)$decoded['ctx_pct'] : null;

        $worktree = isset($decoded['git_worktree']) && is_string($decoded['git_worktree']) && $decoded['git_worktree'] !== ''
            ? $decoded['git_worktree']
            : null;

        return ['session_id' => $sessionId, 'context_used_percentage' => $ctxPct, 'git_worktree' => $worktree];
    }

    /**
     * Finds the actual script file a `{"type":"command","command":"..."}`
     * statusLine entry invokes, so the marker can be appended to Andres's
     * own script rather than this app owning/replacing the whole thing.
     * Deliberately conservative: only ever returns a path that (a) is an
     * existing, writable regular file and (b) looks like a path (contains
     * a "/"), picking the LAST such token in the command (the interpreter,
     * e.g. "bash", normally comes first). Anything else (an inline
     * one-liner, an unrecognized shape) returns null rather than guessing.
     *
     * @param array<string, mixed> $settings
     */
    public static function locate_statusline_script(array $settings): ?string
    {
        $statusLine = $settings['statusLine'] ?? null;

        if (!is_array($statusLine) || ($statusLine['type'] ?? null) !== 'command') {
            return null;
        }

        $command = $statusLine['command'] ?? null;

        if (!is_string($command) || $command === '') {
            return null;
        }

        $tokens = preg_split('/\s+/', trim($command)) ?: [];
        $found = null;

        foreach ($tokens as $token) {
            if (str_contains($token, '/') && is_file($token) && is_writable($token)) {
                $found = $token;
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function marker_installed(array $settings): bool
    {
        $existingScript = self::locate_statusline_script($settings);

        if ($existingScript !== null) {
            $content = @file_get_contents($existingScript);
            return $content !== false && str_contains($content, self::MARKER_BEGIN);
        }

        $fallback = Config::statusline_fallback_script_path();
        $isFallbackConfigured = is_array($settings['statusLine'] ?? null)
            && ($settings['statusLine']['type'] ?? null) === 'command'
            && ($settings['statusLine']['command'] ?? null) === 'bash ' . $fallback;

        return $isFallbackConfigured && is_file($fallback) && str_contains((string)@file_get_contents($fallback), self::MARKER_BEGIN);
    }

    /**
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public static function check_statusline_marker(): array
    {
        $raw = @file_get_contents(Config::claude_settings_path());

        if ($raw === false) {
            return ['ok' => true, 'installed' => false];
        }

        $settings = json_decode($raw, true);

        if (!is_array($settings)) {
            return ['ok' => false, 'installed' => false, 'message' => '~/.claude/settings.json exists but is not valid JSON'];
        }

        return ['ok' => true, 'installed' => self::marker_installed($settings)];
    }

    /**
     * Idempotent: a script that already carries our marker is left alone.
     * Never overwrites a malformed settings.json (same refusal as
     * HookService::install_session_hook(), same reasoning - installing on
     * top of it risks Claude Code refusing to start).
     *
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public static function install_statusline_marker(): array
    {
        $path = Config::claude_settings_path();
        $raw = @file_get_contents($path);
        $settings = [];

        if ($raw !== false) {
            $settings = json_decode($raw, true);

            if (!is_array($settings)) {
                return ['ok' => false, 'installed' => false, 'message' => '~/.claude/settings.json exists but is not valid JSON - fix it manually first'];
            }
        }

        if (self::marker_installed($settings)) {
            return ['ok' => true, 'installed' => true];
        }

        $existingScript = self::locate_statusline_script($settings);

        if ($existingScript !== null) {
            return self::append_marker_to_script($existingScript);
        }

        if (isset($settings['statusLine'])) {
            return ['ok' => false, 'installed' => false, 'message' => 'statusLine is configured but not in a recognized {"type":"command","command":"<interpreter> <path>"} shape - could not locate a script file to append to. Add the session-id marker manually, see README.'];
        }

        return self::install_fallback_script($settings, $path);
    }

    /**
     * @return array{ok:bool, installed:bool, message?:string}
     */
    private static function append_marker_to_script(string $scriptPath): array
    {
        $content = @file_get_contents($scriptPath);

        if ($content === false) {
            return ['ok' => false, 'installed' => false, 'message' => "Could not read {$scriptPath}"];
        }

        $lines = explode("\n", $content);
        $captureBlock = [self::CAPTURE_BEGIN, 'csm_statusline_input=$(cat)', 'exec 0<<< "$csm_statusline_input"', self::CAPTURE_END];

        if (isset($lines[0]) && str_starts_with($lines[0], '#!')) {
            array_splice($lines, 1, 0, $captureBlock);
        } else {
            array_splice($lines, 0, 0, $captureBlock);
        }

        $markerBlock = [
            '',
            self::MARKER_BEGIN,
            'csm_json=$(printf \'%s\' "$csm_statusline_input" | jq -c \'' . self::JQ_FILTER . '\' 2>/dev/null)',
            '[ -n "$csm_json" ] && [ "$csm_json" != "{}" ] && printf "\\033[2mcsm-data:%s\\033[0m\\n" "$csm_json"',
            self::MARKER_END,
        ];

        $newContent = implode("\n", $lines);
        if (!str_ends_with($newContent, "\n")) {
            $newContent .= "\n";
        }
        $newContent .= implode("\n", $markerBlock) . "\n";

        if (@file_put_contents($scriptPath, $newContent) === false) {
            return ['ok' => false, 'installed' => false, 'message' => "Could not write {$scriptPath}"];
        }

        return ['ok' => true, 'installed' => true];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{ok:bool, installed:bool, message?:string}
     */
    private static function install_fallback_script(array $settings, string $settingsPath): array
    {
        $scriptPath = Config::statusline_fallback_script_path();
        $script = "#!/usr/bin/env bash\n"
            . "input=\$(cat)\n"
            . "\n"
            . self::MARKER_BEGIN . "\n"
            . "csm_json=\$(printf '%s' \"\$input\" | jq -c '" . self::JQ_FILTER . "' 2>/dev/null)\n"
            . '[ -n "$csm_json" ] && [ "$csm_json" != "{}" ] && printf "\033[2mcsm-data:%s\033[0m\n" "$csm_json"' . "\n"
            . self::MARKER_END . "\n";

        if (!is_dir(dirname($scriptPath))) {
            @mkdir(dirname($scriptPath), 0700, true);
        }

        if (@file_put_contents($scriptPath, $script) === false) {
            return ['ok' => false, 'installed' => false, 'message' => "Could not write {$scriptPath}"];
        }

        @chmod($scriptPath, 0700);

        $settings['statusLine'] = ['type' => 'command', 'command' => 'bash ' . $scriptPath];

        $encoded = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return ['ok' => false, 'installed' => false, 'message' => 'Failed to encode updated settings'];
        }

        if (!is_dir(dirname($settingsPath))) {
            @mkdir(dirname($settingsPath), 0700, true);
        }

        if (@file_put_contents($settingsPath, HookService::reindent_json_pretty($encoded) . "\n") === false) {
            return ['ok' => false, 'installed' => false, 'message' => 'Could not write ~/.claude/settings.json'];
        }

        return ['ok' => true, 'installed' => true];
    }
}
