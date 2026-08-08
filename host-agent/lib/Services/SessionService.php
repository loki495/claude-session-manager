<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\PendingToolStore;

/**
 * Session lifecycle and control: listing, detail/history snapshots,
 * creating/killing tracked tmux sessions (app-spawned cc-* ones, and
 * adopted ones - see TmuxService::list_tracked_tmux_sessions()), and
 * sending input to a live session's pane (messages, prompt answers, mode
 * switches, escape).
 */
class SessionService
{
    /**
     * A 300ms gap between rapid, related keypresses sent to a live Claude
     * Code pane - not cosmetic, verified live twice over: (1) 3 BTab presses
     * with no gap between them landed 2 steps short (a key got dropped), 300ms
     * between each was reliable every time; (2) selecting an AskUserQuestion
     * option by digit moves the on-screen cursor but doesn't confirm it - a
     * same-instant follow-up Enter can still be processed against the *old*
     * cursor position, confirming the wrong option, unless there's a real
     * gap first. Used by set_mode() (between BTab presses) and answer_prompt()
     * (between the digit and the Enter that confirms it).
     */
    public const TMUX_KEY_STEP_DELAY_USEC = 300000;

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
     * Every known transcript NOT in $excludeClaudeSessionIds (the
     * currently-tracked sessions already shown in the main list) - the
     * dormant/archived half of the unify-claude-sessions plan's dashboard
     * segmentation. Sorted most-recently-active first (the file's own
     * mtime - the simplest available proxy for "last touched" without
     * re-parsing a potentially huge transcript).
     *
     * @param string[] $excludeClaudeSessionIds
     * @return array<int, array{claude_session_id:string, cwd:?string, title:string, last_activity:int}>
     */
    public static function list_archived_sessions(array $excludeClaudeSessionIds): array
    {
        $exclude = array_flip($excludeClaudeSessionIds);
        $archived = [];

        foreach (TranscriptService::list_all_transcripts() as $t) {
            if (isset($exclude[$t['claude_session_id']])) {
                continue;
            }

            $archived[] = [
                'claude_session_id' => $t['claude_session_id'],
                'cwd' => $t['cwd'],
                'title' => self::title_cascade($t['ai_title'], null, $t['cwd'], $t['claude_session_id']),
                'last_activity' => $t['last_activity'],
            ];
        }

        usort($archived, fn(array $a, array $b) => $b['last_activity'] <=> $a['last_activity']);

        return $archived;
    }

    /**
     * The dispatcher-facing wrapper around list_archived_sessions() - an
     * on-demand action (only ever called when Andres actually opens the
     * dashboard's archived-sessions toggle, never part of the regular
     * poll - see this project's own workflow reminders about being extra
     * careful with anything periodic vs explicitly user-triggered) that
     * computes the exclude set itself by re-running list_all_sessions().
     * That's a second full tracked-session scan on top of whatever poll
     * already did one moments ago, but it's cheap and only happens once
     * per toggle-open, not worth threading the caller's already-known
     * list through an extra request parameter for.
     *
     * @return array{archived: array<int, array>}
     */
    public static function list_archived_dashboard(): array
    {
        $trackedIds = [];

        foreach (self::list_all_sessions()['sessions'] as $s) {
            if (is_string($s['claude_session_id'] ?? null)) {
                $trackedIds[] = $s['claude_session_id'];
            }
        }

        return ['archived' => self::list_archived_sessions($trackedIds)];
    }

    /**
     * Builds one tracked session's (cc-* or adopted) list-row/detail data
     * from already-fetched process state - shared by list_all_sessions()
     * (called once per tmux session found) and session_detail() (called for
     * exactly one, by name).
     *
     * @param array{name:string, activity:int, attached:bool} $tmuxSession
     * @param array<int, array{pid:int, cwd:?string, started_at:?int}> $claudeProcs
     * @param array<int, int> $ppidMap
     * @return array{name:string, activity:int, attached:bool, pid:?int, workdir:?string, spawned_by_csm:bool, title:string, working:bool, blocked_reason:?string, resume_hint:?string, prompt_context:?string, prompt_options:array<int, array{number:int, label:string}>, prompt_multi_question:bool, prompt_is_folder_trust:bool, prompt_tool_name:?string, prompt_tool_input:?array, current_mode:?string, claude_session_id:?string, last_message:?array}
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
        $prompt = PromptParser::parse_blocking_prompt($paneContent);

        if ($prompt !== null) {
            $prompt = PromptParser::augment_prompt_with_pending_tool($prompt, PendingToolStore::read_pending_tool($tmuxSession['name']));
        }

        $claudeSessionId = is_string($sidecar['claude_session_id'] ?? null) ? $sidecar['claude_session_id'] : null;
        $workdir = is_string($sidecar['workdir'] ?? null) ? $sidecar['workdir'] : null;

        return [
            'name' => $tmuxSession['name'],
            'activity' => $tmuxSession['activity'],
            'attached' => $tmuxSession['attached'],
            'pid' => $matchedPid,
            'workdir' => $workdir,
            'spawned_by_csm' => $sidecar['spawned_by_csm'] ?? false,
            'title' => self::session_title($claudeSessionId, $panes['title'], $workdir, $tmuxSession['name']),
            'working' => $panes['working'],
            'blocked_reason' => $prompt['question'] ?? null,
            'resume_hint' => $prompt !== null ? TmuxService::tmux_attach_hint($tmuxSession['name']) : null,
            'prompt_context' => $prompt['context'] ?? null,
            'prompt_options' => $prompt['options'] ?? [],
            'prompt_multi_question' => $prompt['multi_question'] ?? false,
            'prompt_is_folder_trust' => $prompt['is_folder_trust'] ?? false,
            'prompt_tool_name' => $prompt['tool_name'] ?? null,
            'prompt_tool_input' => $prompt['tool_input'] ?? null,
            'current_mode' => PromptParser::parse_current_mode($paneContent),
            'claude_session_id' => $claudeSessionId,
            'last_message' => self::session_last_message($claudeSessionId),
        ];
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
     * Fresh, single-session snapshot for the detail page - re-derives
     * everything from a live tmux/proc scan by name rather than trusting
     * anything from the caller, same discipline as kill_cc_session()'s
     * whitelist re-check.
     *
     * @return array{ok:bool, message?:string, has_transcript?:bool}
     */
    public static function session_detail(string $name): array
    {
        $tmuxSession = null;

        foreach (TmuxService::list_tracked_tmux_sessions() as $s) {
            if ($s['name'] === $name) {
                $tmuxSession = $s;
                break;
            }
        }

        if ($tmuxSession === null) {
            return ['ok' => false, 'message' => 'Session not found'];
        }

        $entry = self::build_session_entry($tmuxSession, ProcessInspector::find_claude_processes(), ProcessInspector::build_ppid_map());
        $transcriptPath = $entry['claude_session_id'] !== null ? TranscriptService::find_transcript_path($entry['claude_session_id']) : null;

        return ['ok' => true] + $entry + ['has_transcript' => $transcriptPath !== null];
    }

    /**
     * $after (when given) takes priority over $before - session.php's
     * regular poll passes the last line it's already rendered so only
     * genuinely new entries come back (see TranscriptService::
     * read_transcript_page_since()), while "Load older messages" (which
     * never has an $after) still pages backward via $before as before.
     *
     * @return array{ok:bool, entries?:array<int, array>, next_before?:?int, has_more?:bool, message?:string}
     */
    public static function session_history(string $name, ?int $before, int $limit, ?int $after = null): array
    {
        $sidecar = SidecarStore::read_sidecar($name);
        $claudeSessionId = $sidecar['claude_session_id'] ?? null;

        if (!is_string($claudeSessionId)) {
            return ['ok' => false, 'message' => 'No transcript recorded for this session'];
        }

        return self::transcript_page_for_claude_session($claudeSessionId, $before, $limit, $after);
    }

    /**
     * The archived-session-view counterpart to session_history() - same
     * paging behavior, but reads straight from $claudeSessionId with no
     * sidecar/tmux-name lookup at all, since a dormant session has neither.
     * A tracked (live) session's own $claudeSessionId is never accepted
     * here for this reason on its own - see the note on
     * archived_session_detail() below, which is what session.php actually
     * calls first and is where that distinction is enforced.
     *
     * @return array{ok:bool, entries?:array<int, array>, next_before?:?int, has_more?:bool, message?:string}
     */
    public static function archived_session_history(string $claudeSessionId, ?int $before, int $limit, ?int $after = null): array
    {
        return self::transcript_page_for_claude_session($claudeSessionId, $before, $limit, $after);
    }

    /**
     * Shared by session_history() (resolves $claudeSessionId via a live
     * session's sidecar first) and archived_session_history() (already has
     * it) - both just want a page of a transcript once they know which one.
     *
     * @return array{ok:bool, entries?:array<int, array>, next_before?:?int, has_more?:bool, message?:string}
     */
    private static function transcript_page_for_claude_session(string $claudeSessionId, ?int $before, int $limit, ?int $after): array
    {
        $path = TranscriptService::find_transcript_path($claudeSessionId);

        if ($path === null) {
            return ['ok' => false, 'message' => 'Transcript file not found'];
        }

        if ($after !== null) {
            return TranscriptService::read_transcript_page_since($path, $after, max(1, min($limit, 200)));
        }

        return TranscriptService::read_transcript_page($path, $before, max(1, min($limit, 200)));
    }

    /**
     * The archived-session-view counterpart to session_detail() - the
     * header data (title/cwd/last_activity) for a dormant session's
     * read-only view, keyed by $claudeSessionId (a dormant session has no
     * tmux name to look up by). Deliberately does NOT check whether this
     * id also belongs to a currently-tracked (live) session - the
     * read-only archived view rendering something for a live session's id
     * is harmless (it'd just show slightly stale data next to the real,
     * live view reachable from the main list), and re-deriving "is this
     * one tracked" here would mean re-running list_all_sessions() on every
     * single archived-view page load for no real benefit.
     *
     * @return array{ok:bool, message?:string, claude_session_id?:string, cwd?:?string, title?:string, last_activity?:?int}
     */
    public static function archived_session_detail(string $claudeSessionId): array
    {
        $path = TranscriptService::find_transcript_path($claudeSessionId);

        if ($path === null) {
            return ['ok' => false, 'message' => 'Session not found'];
        }

        $cwd = TranscriptService::find_first_cwd($path);
        $aiTitle = TranscriptService::find_latest_ai_title($path);
        $mtime = @filemtime($path);

        return [
            'ok' => true,
            'claude_session_id' => $claudeSessionId,
            'cwd' => $cwd,
            'title' => self::title_cascade($aiTitle, null, $cwd, $claudeSessionId),
            'last_activity' => $mtime !== false ? $mtime : null,
        ];
    }

    /**
     * Same claude_session_id -> transcript path resolution as
     * session_history() above, then delegates to
     * TranscriptService::read_attachment() to fetch one attachment's real
     * bytes for session_attachment.php.
     *
     * @return array{ok:bool, message?:string, data?:string, media_type?:string, filename?:string, size?:int}
     */
    public static function session_attachment(string $name, int $line, string $fileUuid): array
    {
        $sidecar = SidecarStore::read_sidecar($name);
        $claudeSessionId = $sidecar['claude_session_id'] ?? null;

        if (!is_string($claudeSessionId)) {
            return ['ok' => false, 'message' => 'No transcript recorded for this session'];
        }

        $path = TranscriptService::find_transcript_path($claudeSessionId);

        if ($path === null) {
            return ['ok' => false, 'message' => 'Transcript file not found'];
        }

        return TranscriptService::read_attachment($path, $line, $fileUuid);
    }

    /**
     * A random (v4) UUID, RFC 4122 §4.4 - used as the --session-id passed to
     * `claude` at launch, so this app controls the id up front instead of
     * having to discover whatever Claude Code would have picked on its own.
     * That's what makes TranscriptService::find_transcript_path() a plain glob instead of having
     * to reproduce Claude Code's own cwd -> directory-name encoding.
     */
    public static function generate_uuid_v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * @return array{ok:bool, message:string}
     */
    public static function create_cc_session(string $workdir): array
    {
        if ($workdir === '' || $workdir[0] !== '/') {
            return ['ok' => false, 'message' => 'Working directory must be an absolute path'];
        }

        $name = 'cc-' . date('Ymd-Hi');
        $claudeSessionId = self::generate_uuid_v4();

        $result = TmuxService::tmux_run([
            // CSM_SESSION_NAME is how the SessionStart hook (see
            // host-agent/hooks/session_start.php) tells this pane's claude
            // process apart from any other on the box, so it knows which
            // sidecar to rebind when Claude Code rotates to a new session-id
            // transcript (/clear, /compact, --resume, --fork-session) without
            // this tmux pane itself ever restarting.
            'new-session', '-d', '-s', $name,
            '-c', $workdir,
            '-e', "CSM_SESSION_NAME={$name}",
            '-x', (string)Config::new_session_pane_width(),
            '-y', (string)Config::new_session_pane_height(),
            Config::claude_bin(), '--session-id', $claudeSessionId,
        ]);

        if ($result['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Failed to create session: ' . trim($result['stderr'])];
        }

        // tmux new-session returns success as soon as the session is
        // registered, before checking whether the pane's command actually
        // stayed running (e.g. bad cwd). Confirm it actually persisted. Must
        // use list_all_tmux_sessions() here, not list_tracked_tmux_sessions()
        // - the sidecar isn't written until a few lines below, so a
        // sidecar-gated check would always report "not there yet".
        usleep(300000);

        $stillThere = in_array($name, array_column(TmuxService::list_all_tmux_sessions(), 'name'), true);

        if (!$stillThere) {
            return [
                'ok' => false,
                'message' => "Session {$name} did not stay running - check the working directory is valid and the claude binary starts correctly",
            ];
        }

        // spawned_by_csm is set here too, not left for the SessionStart hook
        // to backfill - the hook only rebinds/confirms it on its own first
        // fire moments later, and a dashboard poll landing in that gap would
        // otherwise see this brand-new, definitely-app-spawned session
        // reported as spawned_by_csm=false.
        SidecarStore::write_sidecar($name, ['workdir' => $workdir, 'spawned_at' => time(), 'claude_session_id' => $claudeSessionId, 'spawned_by_csm' => true]);

        return ['ok' => true, 'message' => "Created session {$name} in {$workdir}"];
    }

    /**
     * $requested must exactly match a name from a freshly-fetched
     * TmuxService::list_tracked_tmux_sessions() call made inside this same
     * request.
     *
     * @return array{ok:bool, message:string}
     */
    public static function kill_cc_session(string $requested): array
    {
        $whitelist = array_column(TmuxService::list_tracked_tmux_sessions(), 'name');

        if (!in_array($requested, $whitelist, true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        $result = TmuxService::tmux_run(['kill-session', '-t', $requested]);

        if ($result['exit'] !== 0) {
            return ['ok' => false, 'message' => "Failed to kill {$requested}: " . trim($result['stderr'])];
        }

        SidecarStore::delete_sidecar($requested);
        PendingToolStore::delete_pending_tool($requested);

        return ['ok' => true, 'message' => "Killed {$requested}"];
    }

    /**
     * Answers a session's pending interactive prompt by sending the chosen
     * option's number followed by Enter - exactly what a human attached over
     * tmux would type. Re-validates immediately before sending, against a
     * fresh capture-pane, that the session is still live and that $option is
     * still actually one of the options currently on screen - not just "some
     * session with this name exists" - so a stale page (the prompt was
     * already answered, the session was killed, or a *different* prompt is
     * now showing) can't fire a keystroke at nobody. Never called
     * automatically anywhere in this app - only in direct response to a
     * human tapping a button that showed them this exact option's label.
     *
     * @return array{ok:bool, message:string}
     */
    public static function answer_prompt(string $name, int $option): array
    {
        if (!in_array($name, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        $prompt = PromptParser::parse_blocking_prompt(TmuxService::tmux_capture_pane($name));

        if ($prompt === null) {
            return ['ok' => false, 'message' => 'Rejected: this session is not currently waiting on a prompt'];
        }

        if (!in_array($option, array_column($prompt['options'], 'number'), true)) {
            return ['ok' => false, 'message' => 'Rejected: that option is not currently offered by this prompt'];
        }

        // Sent as two separate keys, not one send-keys call - verified live that
        // for an AskUserQuestion-style prompt, the digit only moves the on-screen
        // cursor (it doesn't auto-confirm), so an Enter sent in the same instant
        // can race ahead and confirm whatever was *previously* highlighted
        // instead. See TMUX_KEY_STEP_DELAY_USEC.
        $digitResult = TmuxService::tmux_run(['send-keys', '-t', $name, (string)$option]);

        if ($digitResult['exit'] !== 0) {
            return ['ok' => false, 'message' => "Failed to send response: " . trim($digitResult['stderr'])];
        }

        usleep(self::TMUX_KEY_STEP_DELAY_USEC);

        $enterResult = TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']);

        if ($enterResult['exit'] !== 0) {
            return ['ok' => false, 'message' => "Failed to send response: " . trim($enterResult['stderr'])];
        }

        // The pending-tool file (see PendingToolStore::read_pending_tool()) only ever describes
        // whatever's currently blocking - once this app itself has just
        // submitted the answer, it's guaranteed stale for any future prompt.
        PendingToolStore::delete_pending_tool($name);

        return ['ok' => true, 'message' => "Sent option {$option} to {$name}"];
    }

    /**
     * Answers a prompt's free-text option (Claude Code's AskUserQuestion
     * always offers one labeled "Type something.") with custom typed text,
     * instead of just the bare numbered choice. Verified live: selecting
     * that option by digit (without Enter) turns it into an inline text
     * field right there in the option list - typing replaces its label live,
     * and Enter submits whatever was typed. Declining to type anything
     * before pressing Enter is treated as skipping the question entirely,
     * which is why $text is required here and rejected empty, unlike
     * answer_prompt()'s plain numbered choice.
     *
     * @return array{ok:bool, message:string}
     */
    public static function answer_prompt_with_text(string $name, int $option, string $text): array
    {
        if (trim($text) === '') {
            return ['ok' => false, 'message' => 'Reply cannot be empty'];
        }

        if (!in_array($name, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        $prompt = PromptParser::parse_blocking_prompt(TmuxService::tmux_capture_pane($name));

        if ($prompt === null) {
            return ['ok' => false, 'message' => 'Rejected: this session is not currently waiting on a prompt'];
        }

        if (!in_array($option, array_column($prompt['options'], 'number'), true)) {
            return ['ok' => false, 'message' => 'Rejected: that option is not currently offered by this prompt'];
        }

        $digitResult = TmuxService::tmux_run(['send-keys', '-t', $name, (string)$option]);

        if ($digitResult['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Failed to select the free-text option: ' . trim($digitResult['stderr'])];
        }

        usleep(self::TMUX_KEY_STEP_DELAY_USEC);

        $set = TmuxService::tmux_run(['set-buffer', '--', $text]);

        if ($set['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Failed to stage reply: ' . trim($set['stderr'])];
        }

        $paste = TmuxService::tmux_run(['paste-buffer', '-t', $name]);

        if ($paste['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Failed to send reply: ' . trim($paste['stderr'])];
        }

        usleep(self::TMUX_KEY_STEP_DELAY_USEC);

        $enterResult = TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']);

        if ($enterResult['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Reply sent but failed to submit: ' . trim($enterResult['stderr'])];
        }

        PendingToolStore::delete_pending_tool($name);

        return ['ok' => true, 'message' => "Sent free-text reply to {$name}"];
    }

    /**
     * Moves between tabs in a multi-question AskUserQuestion prompt (Left =
     * previous question, Right = next / toward Submit) - the arrow-key
     * navigation a human would use while attached, sent the same way
     * answer_prompt() sends a numbered choice. Re-validates that the session
     * is still live and still actually showing a multi-question prompt right
     * before sending, same discipline as answer_prompt().
     *
     * @return array{ok:bool, message:string}
     */
    public static function navigate_prompt(string $name, string $direction): array
    {
        if (!in_array($direction, ['left', 'right'], true)) {
            return ['ok' => false, 'message' => 'Rejected: invalid direction'];
        }

        if (!in_array($name, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        $prompt = PromptParser::parse_blocking_prompt(TmuxService::tmux_capture_pane($name));

        if ($prompt === null || empty($prompt['multi_question'])) {
            return ['ok' => false, 'message' => 'Rejected: this session is not currently showing a multi-question prompt'];
        }

        $key = $direction === 'left' ? 'Left' : 'Right';
        $result = TmuxService::tmux_run(['send-keys', '-t', $name, $key]);

        if ($result['exit'] !== 0) {
            return ['ok' => false, 'message' => "Failed to navigate: " . trim($result['stderr'])];
        }

        return ['ok' => true, 'message' => "Sent {$key} to {$name}"];
    }

    /**
     * Interrupts whatever Claude is currently doing (mid-generation or
     * mid-tool-call), same as pressing Escape while attached - the "stop"
     * button. No pane-content check first (unlike navigate_prompt()/
     * set_mode(), which validate against a specific expected state): Escape
     * is a safe no-op if nothing is actually running, so there's nothing to
     * reject up front beyond "is this a real managed session at all".
     */
    public static function send_escape(string $name): array
    {
        if (!in_array($name, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        $result = TmuxService::tmux_run(['send-keys', '-t', $name, 'Escape']);

        if ($result['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Failed to send Escape: ' . trim($result['stderr'])];
        }

        return ['ok' => true, 'message' => "Sent Escape to {$name}"];
    }

    public static function set_mode(string $name, string $targetMode): array
    {
        if (!array_key_exists($targetMode, PromptParser::CLAUDE_CODE_MODE_STATUS_PHRASES)) {
            return ['ok' => false, 'message' => 'Rejected: not a recognized mode'];
        }

        if (!in_array($name, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        $currentMode = PromptParser::parse_current_mode(TmuxService::tmux_capture_pane($name));

        if ($currentMode === null) {
            return ['ok' => false, 'message' => 'Rejected: current mode is not readable right now (a prompt may be covering the status line)'];
        }

        $modes = array_keys(PromptParser::CLAUDE_CODE_MODE_STATUS_PHRASES);
        $steps = (array_search($targetMode, $modes, true) - array_search($currentMode, $modes, true) + count($modes)) % count($modes);

        for ($i = 0; $i < $steps; $i++) {
            if ($i > 0) {
                usleep(self::TMUX_KEY_STEP_DELAY_USEC);
            }

            $result = TmuxService::tmux_run(['send-keys', '-t', $name, 'BTab']);

            if ($result['exit'] !== 0) {
                return ['ok' => false, 'message' => 'Failed to set mode: ' . trim($result['stderr'])];
            }
        }

        return ['ok' => true, 'message' => "Set mode for {$name} to {$targetMode}"];
    }

    /**
     * Sends a free-text message to a session, exactly as if a human had
     * typed it while attached, then pressed Enter to submit - the actual,
     * intended point of this whole app (remote-controlling a session, same
     * as attaching from the iOS app). Uses a tmux paste-buffer, not
     * send-keys with the raw text as a "key": send-keys treats embedded
     * newlines in a multi-line message as individual Enter keypresses, each
     * prematurely submitting whatever's been typed so far, where a real
     * terminal paste delivers the whole block as one unit (verified live)
     * and only the explicit trailing Enter submits it.
     *
     * $attachmentPaths (compose-bar file uploads still pending when Send is
     * pressed) each become their own "[Attached: <path>]" line appended
     * after $text - added here, not client-side, so the user's own draft
     * never shows that bookkeeping text while they're still typing (see
     * session.js's compose-attachments preview, which shows the files as
     * their own removable chips instead). $text may be empty as long as at
     * least one attachment is present - an attachment-only send is valid.
     *
     * @param string[] $attachmentPaths
     * @return array{ok:bool, message:string}
     */
    public static function send_message(string $name, string $text, array $attachmentPaths = []): array
    {
        $attachmentLines = array_map(static fn(string $path): string => '[Attached: ' . $path . ']', $attachmentPaths);
        $text = $attachmentLines === [] ? $text : trim(rtrim($text) . "\n" . implode("\n", $attachmentLines));

        if (trim($text) === '') {
            return ['ok' => false, 'message' => 'Message cannot be empty'];
        }

        if (!in_array($name, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        $set = TmuxService::tmux_run(['set-buffer', '--', $text]);

        if ($set['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Failed to stage message: ' . trim($set['stderr'])];
        }

        $paste = TmuxService::tmux_run(['paste-buffer', '-t', $name]);

        if ($paste['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Failed to send message: ' . trim($paste['stderr'])];
        }

        $enter = TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']);

        if ($enter['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Message sent but failed to submit: ' . trim($enter['stderr'])];
        }

        return ['ok' => true, 'message' => "Sent message to {$name}"];
    }

    /**
     * @return array{ok:bool, killed:string[], failed:string[]}
     */
    public static function cleanup_inactive_sessions(): array
    {
        $now = time();
        $killed = [];
        $failed = [];

        foreach (TmuxService::list_tracked_tmux_sessions() as $session) {
            if (($now - $session['activity']) <= Config::cleanup_threshold_seconds()) {
                continue;
            }

            $result = TmuxService::tmux_run(['kill-session', '-t', $session['name']]);

            if ($result['exit'] === 0) {
                SidecarStore::delete_sidecar($session['name']);
                $killed[] = $session['name'];
            } else {
                $failed[] = $session['name'];
            }
        }

        return ['ok' => empty($failed), 'killed' => $killed, 'failed' => $failed];
    }

    /**
     * Kills a "bare" claude process (one ProcessInspector::find_claude_processes() found running
     * on the host that isn't inside a tracked - i.e. sidecar-having, see
     * TmuxService::list_tracked_tmux_sessions() - session) by pid.
     * $pid is re-scanned against a fresh ProcessInspector::find_claude_processes() rather than
     * trusted from the caller, so a stale or reused pid can't be used to kill
     * an unrelated process. If the pid lives inside some other, untracked
     * tmux session (e.g. one created by hand whose SessionStart hook hasn't
     * fired yet), the whole session is killed for a clean shutdown of that
     * pane; otherwise SIGTERM is sent directly.
     *
     * @return array{ok:bool, message:string}
     */
    public static function kill_bare_process(int $pid): array
    {
        $stillRunning = false;

        foreach (ProcessInspector::find_claude_processes() as $proc) {
            if ($proc['pid'] === $pid) {
                $stillRunning = true;
                break;
            }
        }

        if (!$stillRunning) {
            return ['ok' => false, 'message' => 'Rejected: not a currently running claude process'];
        }

        $owningPane = ProcessInspector::find_owning_pane($pid, TmuxService::all_tmux_panes(), ProcessInspector::build_ppid_map());

        if ($owningPane !== null) {
            $result = TmuxService::tmux_run(['kill-session', '-t', $owningPane['session']]);

            return $result['exit'] === 0
                ? ['ok' => true, 'message' => "Killed tmux session {$owningPane['session']} (pid {$pid})"]
                : ['ok' => false, 'message' => "Failed to kill session {$owningPane['session']}: " . trim($result['stderr'])];
        }

        $result = ProcessRunner::run_process(['kill', '-TERM', (string)$pid]);

        return $result['exit'] === 0
            ? ['ok' => true, 'message' => "Sent SIGTERM to pid {$pid}"]
            : ['ok' => false, 'message' => "Failed to kill pid {$pid}: " . trim($result['stderr'])];
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
