<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\PendingToolStore;
use HostAgent\Stores\SessionStatusStore;

/**
 * Session listing (list_all_sessions, build_session_entry) and the shared
 * title cascade - the foundation layer everything else depends on. Split
 * out of what was originally one 1940-line, 43-method class (2026-08-24
 * readability audit - see the plan this followed): sending input to a live
 * session's pane (messages, prompt answers, mode/model switches, escape)
 * now lives in PromptInteractionService, the sidebar's plan/handoff-file
 * glance now lives in PlanFileService, create/resume/kill/cleanup of
 * managed cc-* tmux sessions (app-spawned ones, and adopted ones - see
 * TmuxService::list_tracked_tmux_sessions()) now lives in
 * SessionLifecycleService, archived/dormant session listing plus
 * transcript search now lives in ArchivedSessionService,
 * detail/history/attachment paging for both live and archived sessions
 * now lives in SessionDetailService, and untracked ("bare") claude process
 * discovery/take-over now lives in BareProcessService. This class has zero
 * dependency on any of the six - it's what's left after removing them.
 */
class SessionService
{
    /**
     * The shared fallback cascade behind every session title this app
     * shows, live or dormant: the transcript's own ai-title first (see
     * TranscriptService::find_latest_ai_title()), since it needs no live
     * pane at all and works the same for a dormant session as a live one -
     * the unify-claude-sessions plan's "minimize tmux reliance" goal made
     * concrete. Falls back, in order, to a live-pane-title scrape (only
     * ever non-null for a currently-live session - see session_title()
     * below), the working directory's basename, and finally the raw
     * session name/id - the one source that's always available. Mirrors
     * NotificationContentBuilder::push_notification_title()'s own cascade
     * for the same reason: a title should never come back blank.
     */
    public static function title_cascade(?string $aiTitle, ?string $livePaneTitle, ?string $workdir, string $fallbackName): string
    {
        if ($aiTitle !== null && $aiTitle !== '') {
            return $aiTitle;
        }

        if ($livePaneTitle !== null && $livePaneTitle !== '') {
            return $livePaneTitle;
        }

        if ($workdir !== null && $workdir !== '') {
            return basename($workdir);
        }

        return $fallbackName;
    }

    /**
     * build_session_entry()'s title field: resolves $claudeSessionId to its
     * transcript's ai-title (if any), then applies title_cascade() with
     * today's live-pane-title scrape as the next fallback - the one part of
     * the cascade that's only ever available for a currently-live session.
     */
    public static function session_title(?string $claudeSessionId, ?string $livePaneTitle, ?string $workdir, string $name): string
    {
        $transcriptPath = $claudeSessionId !== null ? TranscriptService::find_transcript_path($claudeSessionId) : null;
        $aiTitle = $transcriptPath !== null ? TranscriptService::find_latest_ai_title($transcriptPath) : null;

        return self::title_cascade($aiTitle, $livePaneTitle, $workdir, $name);
    }

    /**
     * Builds one tracked session's (cc-* or adopted) list-row/detail data
     * from already-fetched process state - shared by list_all_sessions()
     * (called once per tmux session found) and SessionDetailService::
     * session_detail() (called for exactly one, by name).
     *
     * @param array{name:string, activity:int, attached:bool} $tmuxSession
     * @param array<int, array{pid:int, cwd:?string, started_at:?int}> $claudeProcs
     * @param array<int, int> $ppidMap
     * @return array{name:string, activity:int, attached:bool, pid:?int, workdir:?string, spawned_by_csm:bool, title:string, working:bool, blocked_reason:?string, resume_hint:?string, prompt_context:?string, prompt_options:array<int, array{number:int, label:string}>, prompt_multi_question:bool, prompt_is_folder_trust:bool, prompt_tool_name:?string, prompt_tool_input:?array, prompt_questions:?array, current_mode:?string, current_model:?string, claude_session_id:?string, last_message:?array}
     */
    public static function build_session_entry(array $tmuxSession, array $claudeProcs, array $ppidMap): array
    {
        $panes = TmuxService::tmux_session_panes($tmuxSession['name']);
        $matchedPid = null;

        foreach ($claudeProcs as $proc) {
            foreach ($panes['pids'] as $panePid) {
                if (ProcessInspector::is_descendant($proc['pid'], $panePid, $ppidMap)) {
                    $matchedPid = $proc['pid'];
                    break 2;
                }
            }
        }

        $sidecar = SidecarStore::read_sidecar($tmuxSession['name']);
        $paneContent = TmuxService::tmux_capture_pane($tmuxSession['name']);
        $hookStatus = SessionStatusStore::read_status($tmuxSession['name']);
        $hookStatusValue = is_string($hookStatus['status'] ?? null) ? $hookStatus['status'] : null;
        $hookBlocked = is_array($hookStatus['blocked'] ?? null) ? $hookStatus['blocked'] : null;
        $hookBlockedToolName = is_string($hookBlocked['tool_name'] ?? null) ? $hookBlocked['tool_name'] : null;

        // mode/working-status/blocked-prompt-content (for every tool EXCEPT
        // AskUserQuestion) are fully owned by SessionStatusStore now - the
        // PermissionRequest/UserPromptSubmit/Stop hooks are mandatory (see
        // the health box), not a "prefer this, fall back to pane-scraping if
        // missing" cascade - there is no fallback for these three things
        // beyond what's carved out below. Only two prompt shapes still need
        // the live pane at all, forever, regardless of hook installation:
        if ($hookStatusValue === 'blocked' && $hookBlockedToolName === 'AskUserQuestion') {
            // AskUserQuestion renders as a tab bar Claude Code itself
            // navigates with the Left/Right arrow keys - a single
            // PermissionRequest fire (at the start of the whole multi-
            // question call) can never say which tab is CURRENTLY showing,
            // only the live pane can (see PromptParser::
            // augment_prompt_with_pending_tool()'s own docblock). This is a
            // structural limitation of the prompt shape, not something more
            // hook coverage could ever fix.
            $prompt = PromptParser::parse_blocking_prompt($paneContent);

            if ($prompt !== null) {
                $prompt = PromptParser::augment_prompt_with_pending_tool($prompt, PendingToolStore::read_pending_tool($tmuxSession['name']));
            }
        } elseif ($hookStatusValue === 'blocked') {
            $prompt = PromptParser::build_prompt_from_hook_status($hookBlocked);
        } else {
            // The only prompt shape that can still be showing here is the
            // initial per-folder trust dialog - confirmed live to fire NONE
            // of this app's hooks at all (a separate, pre-hook-system
            // startup safety check). Pane-scraping is scoped to exactly that
            // case now, never trusted as a stand-in for a PermissionRequest
            // that should have fired but didn't (e.g. the hooks aren't
            // installed yet) - see CONTRIBUTING.md.
            $paneScraped = PromptParser::parse_blocking_prompt($paneContent);
            $prompt = ($paneScraped !== null && $paneScraped['is_folder_trust']) ? $paneScraped : null;
        }

        // The full question set for a MULTI-question AskUserQuestion call,
        // straight from the hook payload - lets the frontend render (and
        // answer) every question at once via SessionService::
        // answer_multi_question(), instead of only whichever tab the pane
        // currently has up (see that method's own docblock). Never set for
        // a single-question AskUserQuestion - no tab-bar ambiguity exists
        // there, so it keeps using the existing pane-scraped $prompt above
        // via answer_prompt()/answer_prompt_with_text() unchanged.
        $promptQuestions = null;

        if ($hookStatusValue === 'blocked' && $hookBlockedToolName === 'AskUserQuestion') {
            $rawQuestions = is_array($hookBlocked['tool_input']['questions'] ?? null) ? $hookBlocked['tool_input']['questions'] : null;
            $promptQuestions = ($rawQuestions !== null && count($rawQuestions) >= 2) ? $rawQuestions : null;
        }

        $claudeSessionId = is_string($sidecar['claude_session_id'] ?? null) ? $sidecar['claude_session_id'] : null;
        $workdir = is_string($sidecar['workdir'] ?? null) ? $sidecar['workdir'] : null;
        $liveMarker = StatuslineMarkerService::parse_marker_from_pane($paneContent);
        $claudeSessionId = self::self_heal_claude_session_id($tmuxSession['name'], $sidecar, $claudeSessionId, $liveMarker['session_id']);

        // Same "hooks fully own this, no pane-scraping fallback" reasoning
        // as $prompt above - working/current_mode are simply unknown
        // (false/null) for a session with no status file at all yet
        // (hooks not installed, or genuinely hasn't had one fire since
        // spawning).
        $working = $hookStatusValue === 'working';
        $currentMode = is_string($hookStatus['mode'] ?? null) ? $hookStatus['mode'] : null;

        // Read from the transcript, not any live-pane signal - see
        // TranscriptService::find_latest_model()'s own docblock for why
        // that's the only source portable to every user of this app (not
        // just Andres's own personal statusline customization). Never
        // resolves to "default" - see SelectableModel::family_from_raw_model()
        // for why that's inherently undetectable from a raw model ID alone.
        $transcriptPathForModel = $claudeSessionId !== null ? TranscriptService::find_transcript_path($claudeSessionId) : null;
        $rawModel = $transcriptPathForModel !== null ? TranscriptService::find_latest_model($transcriptPathForModel) : null;
        $currentModel = $rawModel !== null ? SelectableModel::family_from_raw_model($rawModel) : null;

        return [
            'name' => $tmuxSession['name'],
            'activity' => $tmuxSession['activity'],
            'attached' => $tmuxSession['attached'],
            'pid' => $matchedPid,
            'workdir' => $workdir,
            'spawned_by_csm' => $sidecar['spawned_by_csm'] ?? false,
            'title' => self::session_title($claudeSessionId, $panes['title'], $workdir, $tmuxSession['name']),
            'working' => $working,
            'blocked_reason' => $prompt['question'] ?? null,
            'resume_hint' => $prompt !== null ? TmuxService::tmux_attach_hint($tmuxSession['name']) : null,
            'prompt_context' => $prompt['context'] ?? null,
            'prompt_options' => $prompt['options'] ?? [],
            'prompt_multi_question' => $prompt['multi_question'] ?? false,
            'prompt_is_folder_trust' => $prompt['is_folder_trust'] ?? false,
            'prompt_tool_name' => $prompt['tool_name'] ?? null,
            'prompt_tool_input' => $prompt['tool_input'] ?? null,
            'prompt_questions' => $promptQuestions,
            'current_mode' => $currentMode,
            'current_model' => $currentModel,
            'claude_session_id' => $claudeSessionId,
            'last_message' => self::session_last_message($claudeSessionId),
            // Both sourced from StatuslineMarkerService's live-pane marker,
            // same mechanism as the self-heal cross-check above - null
            // whenever the marker isn't installed yet, the pane hasn't
            // rendered a statusline update since Claude Code had context-
            // window data to report, or the session isn't in a worktree.
            'context_used_percentage' => $liveMarker['context_used_percentage'],
            'git_worktree' => $liveMarker['git_worktree'],
        ];
    }

    /**
     * Cross-checks the sidecar's claude_session_id against
     * StatuslineMarkerService's live-pane signal and self-heals a
     * stale/wrong one - $liveSessionId is whatever build_session_entry()
     * already parsed out of the pane content it captured for prompt
     * parsing, no extra tmux capture-pane call here. Only ever overwrites
     * when (a) a sidecar actually exists (nothing to preserve workdir/
     * spawned_at from otherwise - an untracked/bare session gets no
     * sidecar from this) and (b) the live id resolves to a real transcript
     * file, the same "don't trust an id nothing backs" rule
     * session_start.php's SessionStart hook itself now enforces (see its
     * own docblock, added 2026-08-08) - applied here as a second,
     * independent layer that self-corrects even if a bad write already
     * slipped past the hook, rather than depending on catching the right
     * hook fire in the first place.
     *
     * @param array<string, mixed>|null $sidecar
     */
    public static function self_heal_claude_session_id(string $sessionName, ?array $sidecar, ?string $claudeSessionId, ?string $liveId): ?string
    {
        if ($sidecar === null) {
            return $claudeSessionId;
        }

        if ($liveId === null || $liveId === $claudeSessionId) {
            return $claudeSessionId;
        }

        if (TranscriptService::find_transcript_path($liveId) === null) {
            return $claudeSessionId;
        }

        SidecarStore::write_sidecar($sessionName, [
            'workdir' => $sidecar['workdir'] ?? null,
            'spawned_at' => $sidecar['spawned_at'] ?? time(),
            'claude_session_id' => $liveId,
            'spawned_by_csm' => $sidecar['spawned_by_csm'] ?? false,
        ]);

        return $liveId;
    }

    /**
     * The single most recent transcript entry - used for the dashboard's
     * per-row preview, and to give a blocked prompt's card the message that
     * led up to it. That's worth doing specifically for the blocked case
     * because the pending tool_use itself usually ISN'T in the transcript
     * yet (Claude Code only writes it once it's approved and actually runs -
     * see prompt_context in PromptParser::parse_blocking_prompt() for the live-pane
     * alternative), but the assistant's own preceding explanation almost
     * always already is, written as its own separate line just before.
     *
     * @return array{role:?string, timestamp:?string, blocks:array<int, array{kind:string, text:string}>}|null
     */
    public static function session_last_message(?string $claudeSessionId): ?array
    {
        if ($claudeSessionId === null) {
            return null;
        }

        $path = TranscriptService::find_transcript_path($claudeSessionId);

        if ($path === null) {
            return null;
        }

        $page = TranscriptService::read_transcript_page($path, null, 1);

        if (!($page['ok'] ?? false) || empty($page['entries'])) {
            return null;
        }

        return $page['entries'][0];
    }

    /**
     * @return array{sessions: array<int, array>, bare: array<int, array>}
     */
    public static function list_all_sessions(): array
    {
        $tmuxSessions = TmuxService::list_tracked_tmux_sessions();
        $claudeProcs = ProcessInspector::find_claude_processes();
        $ppidMap = ProcessInspector::build_ppid_map();

        // Must include every real tmux session on the box, not just cc-*
        // ones - an adopted (non-cc-*) sidecar would otherwise get pruned as
        // an "orphan" on the very next dashboard load, undoing session_start
        // hook's work within moments. all_tmux_panes() already enumerates
        // every session/pane regardless of name, so reuse the one call below
        // rather than issuing a second tmux query.
        $allPanes = TmuxService::all_tmux_panes();
        $liveSessionNames = array_values(array_unique(array_column($allPanes, 'session')));
        SidecarStore::prune_orphaned_sidecars($liveSessionNames);

        $trackedPids = [];
        $sessions = [];

        foreach ($tmuxSessions as $session) {
            $entry = self::build_session_entry($session, $claudeProcs, $ppidMap);

            if ($entry['pid'] !== null) {
                $trackedPids[$entry['pid']] = true;
            }

            $sessions[] = $entry;
        }

        usort($sessions, fn(array $a, array $b) => $b['activity'] <=> $a['activity']);

        $bare = [];

        foreach ($claudeProcs as $proc) {
            if (isset($trackedPids[$proc['pid']])) {
                continue;
            }

            $owningPane = ProcessInspector::find_owning_pane($proc['pid'], $allPanes, $ppidMap);

            $bare[] = $proc + [
                'tmux_session' => $owningPane['session'] ?? null,
                'title' => $owningPane['title'] ?? null,
            ];
        }

        usort($bare, fn(array $a, array $b) => ($b['started_at'] ?? 0) <=> ($a['started_at'] ?? 0));

        return ['sessions' => $sessions, 'bare' => $bare];
    }

    /**
     * Lists the immediate subdirectories of $path (hidden ones included), for
     * the New Session folder browser - lets a session start anywhere under the
     * home directory, not just under Config::www_root(). $path (after resolving symlinks)
     * must be Config::home_root() itself or a descendant of it; anything else is
     * rejected rather than letting the browser wander into the rest of the
     * filesystem. An empty $path defaults to Config::www_root(), the common case,
     * rather than Config::home_root() itself - the browser can still walk up to
     * Config::home_root() from there via the returned `parent`.
     *
     * @return array{ok:bool, path?:string, parent?:?string, dirs?:string[], message?:string}
     */
    public static function browse_dir(string $path): array
    {
        $root = Config::home_root();
        $realRoot = realpath($root);

        if ($realRoot === false) {
            return ['ok' => false, 'message' => 'Home directory is not configured correctly on the host'];
        }

        $real = realpath($path !== '' ? $path : Config::www_root());

        if ($real === false || !is_dir($real) || ($real !== $realRoot && !str_starts_with($real . '/', $realRoot . '/'))) {
            return ['ok' => false, 'message' => 'Path is outside the home directory'];
        }

        $dirs = [];

        foreach (scandir($real) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_dir($real . '/' . $entry)) {
                $dirs[] = $entry;
            }
        }

        sort($dirs, SORT_STRING | SORT_FLAG_CASE);

        return [
            'ok' => true,
            'path' => $real,
            'parent' => $real === $realRoot ? null : dirname($real),
            'dirs' => $dirs,
        ];
    }
}
