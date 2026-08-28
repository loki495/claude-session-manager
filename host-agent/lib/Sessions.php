<?php
declare(strict_types=1);

/**
 * All logic here runs natively on the host (invoked by systemd per
 * connection, see host-agent/agent.php) - never inside the Docker
 * container. That matters: tmux auto-starts a server as a child of
 * whichever process first talks to an unstarted socket, so the process
 * issuing tmux commands must always be a genuine host process. If the
 * container issued these calls directly, an accidental auto-started
 * server would run inside the container's own namespace and any spawned
 * claude process would be unreachable from the host.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use HostAgent\Services\SessionService;
use HostAgent\Services\PromptInteractionService;
use HostAgent\Services\PlanFileService;
use HostAgent\Services\SessionLifecycleService;
use HostAgent\Services\ArchivedSessionService;
use HostAgent\Services\SessionDetailService;
use HostAgent\Services\OpenCodeTranscriptService;
use HostAgent\Services\BareProcessService;
use HostAgent\Services\HookService;
use HostAgent\Services\UploadService;
use HostAgent\Services\QuotaService;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\GlobalStateStore;
use HostAgent\Runtimes\RuntimeRegistry;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Runtimes\OpenCodeServeClient;
use HostAgent\Agents\AgentRegistry;

/**
 * @return array
 */
function dispatch_action(array $request): array
{
    switch ($request['action'] ?? '') {
        case 'list':
            $list = ['ok' => true] + SessionService::list_all_sessions();
            $list['sessions'] = csm_merge_headless_sessions($list['sessions']);

            return $list;

        case 'list_archived':
            return ['ok' => true] + ArchivedSessionService::list_archived_dashboard();

        case 'session_detail':
            $session = (string)($request['session'] ?? '');
            if (csm_is_headless_session($session)) {
                $agent = csm_headless_agent($session);
                $headless = $agent !== null ? RuntimeRegistry::runtime_for($agent, RuntimeType::HEADLESS) : null;
                $serveDetail = $headless?->detail($session) ?? ['ok' => false, 'message' => 'Headless runtime unavailable'];

                if (($serveDetail['ok'] === true) && is_array($serveDetail['session'] ?? null)) {
                    return csm_headless_detail_shape($serveDetail['session'], $agent ?? 'opencode');
                }

                return $serveDetail;
            }
            return SessionDetailService::session_detail($session);

        case 'archived_session_detail':
            return SessionDetailService::archived_session_detail((string)($request['claude_session_id'] ?? ''));

        case 'session_history':
            $historySession = (string)($request['session'] ?? '');
            if (csm_is_headless_session($historySession)) {
                return SessionDetailService::archived_session_history(
                    $historySession,
                    isset($request['before']) ? (int)$request['before'] : null,
                    isset($request['limit']) ? (int)$request['limit'] : 30,
                    isset($request['after']) ? (int)$request['after'] : null
                );
            }
            return SessionDetailService::session_history(
                $historySession,
                isset($request['before']) ? (int)$request['before'] : null,
                isset($request['limit']) ? (int)$request['limit'] : 30,
                isset($request['after']) ? (int)$request['after'] : null,
                ($request['until_user'] ?? false) === true
            );

        case 'archived_session_history':
            return SessionDetailService::archived_session_history(
                (string)($request['claude_session_id'] ?? ''),
                isset($request['before']) ? (int)$request['before'] : null,
                isset($request['limit']) ? (int)$request['limit'] : 30,
                isset($request['after']) ? (int)$request['after'] : null
            );

        case 'search_transcripts':
            return ArchivedSessionService::search_transcripts(
                (string)($request['query'] ?? ''),
                isset($request['max_sessions']) ? (int)$request['max_sessions'] : 30,
                isset($request['max_matches_per_session']) ? (int)$request['max_matches_per_session'] : 3
            );

        case 'session_transcript_search':
            return ArchivedSessionService::session_transcript_search(
                (string)($request['session'] ?? ''),
                (string)($request['query'] ?? ''),
                isset($request['max_matches']) ? (int)$request['max_matches'] : 20
            );

        case 'archived_session_transcript_search':
            return ArchivedSessionService::archived_session_transcript_search(
                (string)($request['claude_session_id'] ?? ''),
                (string)($request['query'] ?? ''),
                isset($request['max_matches']) ? (int)$request['max_matches'] : 20
            );

        case 'session_attachment':
            return SessionDetailService::session_attachment(
                (string)($request['session'] ?? ''),
                (int)($request['line'] ?? 0),
                (string)($request['file_uuid'] ?? '')
            );

        case 'archived_session_attachment':
            return SessionDetailService::archived_session_attachment(
                (string)($request['claude_session_id'] ?? ''),
                (int)($request['line'] ?? 0),
                (string)($request['file_uuid'] ?? '')
            );

        case 'create':
            $startingMode = $request['starting_mode'] ?? null;
            $agentId = is_string($request['agent'] ?? null) ? $request['agent'] : null;
            $workdir = (string)($request['workdir'] ?? '');
            $modelId = is_string($request['model'] ?? null) ? $request['model'] : null;
            $modelProvider = is_string($request['model_provider'] ?? null) ? $request['model_provider'] : null;

            // OpenCode's default runtime is headless (no tmux) - a New
            // Session for opencode goes through `opencode serve`, so it
            // lands in the headless pool the dashboard already merges in.
            // Other agents (claude/antigravity) have no headless session
            // mode and stay on the tmux path.
            if ($agentId === 'opencode' || $agentId === 'codex') {
                $headless = RuntimeRegistry::runtime_for($agentId, RuntimeType::HEADLESS);

                if ($headless === null) {
                    return ['ok' => false, 'message' => "Headless runtime unavailable for {$agentId}"];
                }

                $result = $headless->create(['workdir' => $workdir, 'model' => $modelId]);
                $createdId = ($result['ok'] === true) && is_string($result['id'] ?? null) ? $result['id'] : null;

                if ($createdId !== null) {
                    $createdSession = is_array($result['session'] ?? null) ? $result['session'] : [];

                    // Adopt the brand-new headless session into a sidecar
                    // IMMEDIATELY, so the New Session redirect to
                    // session.php?session=<id> resolves right away and the
                    // session shows in the merged list - rather than waiting
                    // up to HEADLESS_SYNC_SECONDS for the throttled sync to
                    // pick it up (which otherwise left the redirect hitting
                    // the tmux detail path and reporting "Session not
                    // found"). Same fields the sync would write.
                    SidecarStore::write_sidecar($createdId, [
                        'workdir' => $workdir,
                        'spawned_at' => time(),
                        'claude_session_id' => $createdId,
                        'spawned_by_csm' => true,
                        'agent' => $agentId,
                        'runtime' => RuntimeType::HEADLESS,
                        'title' => is_string($createdSession['title'] ?? null) && $createdSession['title'] !== '' ? $createdSession['title'] : null,
                    ]);

                    // Apply model selection if specified - the serve creates
                    // sessions with no model by default; set_model POSTs to
                    // /api/session/{id}/model so the first turn uses it.
                    if ($agentId === 'opencode' && $modelId !== null && $modelId !== '' && $modelProvider !== null && $modelProvider !== '') {
                        (new OpenCodeServeClient())->set_model($createdId, $modelProvider, $modelId);
                    }

                    // Normalize to the create_cc_session() shape callers expect
                    // (a `name` for the redirect to session.php?session=name).
                    $result['name'] = $createdId;
                    $result['session'] = $createdId;
                }

                return $result;
            }

            return SessionLifecycleService::create_cc_session(
                $workdir,
                (bool)($request['enable_task_tools'] ?? false),
                is_string($startingMode) && $startingMode !== '' ? $startingMode : null,
                $agentId
            );

        case 'resume':
            $resumeWorkdir = (string)($request['workdir'] ?? '');
            $resumeId = (string)($request['claude_session_id'] ?? '');

            // An opencode session resumes via the serve (headless) - POST
            // /api/session with its existing id continues the SAME
            // conversation as a serve-owned session, no tmux pane. claude/
            // antigravity keep the tmux resume path.
            if (OpenCodeTranscriptService::is_opencode_id($resumeId)) {
                return csm_headless_resume($resumeWorkdir, $resumeId);
            }

            return SessionLifecycleService::resume_cc_session($resumeWorkdir, $resumeId);

        case 'kill':
            $killSession = (string)($request['session'] ?? '');
            if (csm_is_headless_session($killSession)) {
                $agent = csm_headless_agent($killSession);
                $headless = $agent !== null ? RuntimeRegistry::runtime_for($agent, RuntimeType::HEADLESS) : null;
                $result = $headless?->kill($killSession) ?? ['ok' => false, 'message' => 'Headless runtime unavailable'];

                // Delete the sidecar/status rows immediately on a successful
                // kill, so the session leaves the active list at once rather
                // than lingering until the throttled sync (HEADLESS_SYNC_SECONDS)
                // prunes it - the tmux kill path already does this (see
                // SessionLifecycleService::kill_cc_session()).
                if ($result['ok'] === true) {
                    SidecarStore::delete_sidecar($killSession);
                    SessionStatusStore::delete_status($killSession);
                }

                return $result;
            }
            return SessionLifecycleService::kill_cc_session($killSession);

        case 'kill_bare':
            return BareProcessService::kill_bare_process((int)($request['pid'] ?? 0));

        case 'take_over_bare':
            return BareProcessService::take_over_bare_process((int)($request['pid'] ?? 0));

        case 'take_over_bare_with_id':
            return BareProcessService::take_over_bare_process_with_id(
                (int)($request['pid'] ?? 0),
                (string)($request['workdir'] ?? ''),
                (string)($request['claude_session_id'] ?? '')
            );

        case 'answer_prompt':
            $answerSession = (string)($request['session'] ?? '');
            if (csm_is_headless_session($answerSession)) {
                return csm_headless_answer_prompt($answerSession, ['option' => (int)($request['option'] ?? 0)]);
            }
            return PromptInteractionService::answer_prompt($answerSession, (int)($request['option'] ?? 0));

        case 'answer_prompt_with_text':
            $answerTextSession = (string)($request['session'] ?? '');
            if (csm_is_headless_session($answerTextSession)) {
                return csm_headless_answer_prompt($answerTextSession, [
                    'option' => (int)($request['option'] ?? 0),
                    'text' => (string)($request['text'] ?? ''),
                ]);
            }
            return PromptInteractionService::answer_prompt_with_text($answerTextSession, (int)($request['option'] ?? 0), (string)($request['text'] ?? ''));

        case 'answer_multi_question':
            $answerMultiSession = (string)($request['session'] ?? '');
            if (csm_is_headless_session($answerMultiSession)) {
                return csm_headless_answer_prompt($answerMultiSession, [
                    'answers' => is_array($request['answers'] ?? null) ? $request['answers'] : [],
                ]);
            }
            return PromptInteractionService::answer_multi_question($answerMultiSession, is_array($request['answers'] ?? null) ? $request['answers'] : []);

        case 'send_escape':
            $escapeSession = (string)($request['session'] ?? '');

            if (csm_is_headless_session($escapeSession)) {
                if (csm_headless_agent($escapeSession) === 'codex') {
                    $runtime = RuntimeRegistry::runtime_for('codex', RuntimeType::HEADLESS);
                    return $runtime instanceof \HostAgent\Runtimes\CodexHeadlessRuntime
                        ? $runtime->interrupt($escapeSession)
                        : ['ok' => false, 'message' => 'Codex runtime unavailable'];
                }
                return (new OpenCodeServeClient())->interrupt($escapeSession);
            }
            return PromptInteractionService::send_escape($escapeSession);

        case 'send_message':
            $sendSession = (string)($request['session'] ?? '');
            $sendText = (string)($request['text'] ?? '');
            $sendAttachments = is_array($request['attachment_paths'] ?? null) ? array_map('strval', $request['attachment_paths']) : [];

            if (csm_is_headless_session($sendSession)) {
                $agent = csm_headless_agent($sendSession);
                $headless = $agent !== null ? RuntimeRegistry::runtime_for($agent, RuntimeType::HEADLESS) : null;
                return $headless?->send_message($sendSession, $sendText, $sendAttachments)
                    ?? ['ok' => false, 'message' => 'Headless runtime unavailable'];
            }
            return PromptInteractionService::send_message($sendSession, $sendText, $sendAttachments);

        case 'set_mode':
            $modeSession = (string)($request['session'] ?? '');

            if (csm_is_headless_session($modeSession)) {
                return ['ok' => false, 'message' => 'Mode switching is not supported for headless sessions'];
            }
            return PromptInteractionService::set_mode($modeSession, (string)($request['mode'] ?? ''));

        case 'set_model':
            $modelSession = (string)($request['session'] ?? '');
            if (csm_is_headless_session($modelSession)) {
                if (csm_headless_agent($modelSession) === 'codex') {
                    $runtime = RuntimeRegistry::runtime_for('codex', RuntimeType::HEADLESS);
                    return $runtime instanceof \HostAgent\Runtimes\CodexHeadlessRuntime
                        ? $runtime->update_settings($modelSession, (string)($request['model'] ?? ''), (string)($request['effort'] ?? ''))
                        : ['ok' => false, 'message' => 'Codex runtime unavailable'];
                }
                return (new OpenCodeServeClient())->set_model(
                    $modelSession,
                    (string)($request['model_provider'] ?? ''),
                    (string)($request['model'] ?? '')
                );
            }
            return PromptInteractionService::set_model($modelSession, (string)($request['model'] ?? ''));

        case 'list_models':
            return csm_list_models(is_string($request['agent'] ?? null) ? $request['agent'] : 'opencode');

        case 'set_antigravity_model':
            return PromptInteractionService::set_antigravity_model((string)($request['session'] ?? ''), (string)($request['model'] ?? ''));

        case 'cleanup':
            return SessionLifecycleService::cleanup_inactive_sessions();

        case 'browse_dir':
            return SessionService::browse_dir((string)($request['path'] ?? ''));

        case 'create_dir':
            return SessionService::create_dir((string)($request['path'] ?? ''), (string)($request['name'] ?? ''));

        case 'list_plan_files':
            return PlanFileService::list_plan_files((string)($request['session'] ?? ''));

        case 'read_plan_file':
            return PlanFileService::read_plan_file((string)($request['session'] ?? ''), (string)($request['filename'] ?? ''));

        case 'read_todo_file':
            return PlanFileService::read_todo_file((string)($request['session'] ?? ''));

        case 'quota':
            $quotaSession = trim((string)($request['session'] ?? ''));

            return QuotaService::get_quota($quotaSession !== '' ? $quotaSession : null);

        case 'check_session_hook':
            return HookService::check_session_hook();

        case 'install_session_hook':
            return HookService::install_session_hook();

        case 'save_uploaded_file':
            return UploadService::save_uploaded_file(
                (string)($request['session'] ?? ''),
                (string)($request['filename'] ?? ''),
                (string)($request['content_base64'] ?? '')
            );

        case 'list_uploaded_files':
            return UploadService::list_uploaded_files((string)($request['session'] ?? ''));

        case 'read_uploaded_file':
            return UploadService::read_uploaded_file((string)($request['session'] ?? ''), (string)($request['filename'] ?? ''));

        case 'delete_uploaded_file':
            return UploadService::delete_uploaded_file((string)($request['session'] ?? ''), (string)($request['filename'] ?? ''));

        case 'delete_all_uploaded_files':
            return UploadService::delete_all_uploaded_files((string)($request['session'] ?? ''));

        default:
            return ['ok' => false, 'message' => 'Unknown action'];
    }
}

/**
 * Merges headless (serve-hosted) OpenCode sessions into the dashboard's one
 * active-sessions list: normalizes every tmux entry to add a `runtime` and a
 * `status` field, then appends each headless session reshaped into the same
 * session-entry shape the row renderer already understands. Both tmux and
 * headless rows then flow through the SAME `sessions` list (dashboard card,
 * poll fragment, and session-page sidebar) - the "one list card with pills"
 * from the headless-runtime plan's Phase 3. `bare` processes stay separate
 * (they're "not managed by this tool").
 *
 * @param array<int, array<string, mixed>> $sessions
 * @return array<int, array<string, mixed>>
 */
function csm_merge_headless_sessions(array $sessions): array
{
    foreach ($sessions as &$s) {
        $s['runtime'] = RuntimeType::TMUX;
        $s['status'] = !empty($s['blocked_reason']) ? 'blocked' : (!empty($s['working']) ? 'working' : 'idle');
    }
    unset($s);

    foreach (csm_headless_sessions()['headless'] as $h) {
        $blocked = is_array($h['blocked'] ?? null) ? $h['blocked'] : null;
        $agentId = is_string($h['agent'] ?? null) ? $h['agent'] : 'opencode';
        $agentLabel = AgentRegistry::get($agentId)->label();

        $sessions[] = [
            'name' => $h['id'],
            'activity' => (int)($h['activity'] ?? 0),
            'attached' => false,
            'pid' => null,
            'workdir' => $h['workdir'],
            'spawned_by_csm' => true,
            'agent' => $agentId,
            'agent_label' => $agentLabel,
            'title' => $h['title'],
            'runtime' => RuntimeType::HEADLESS,
            'status' => $h['status'],
            'working' => $h['status'] === 'working',
            // Blocked-prompt fields come from the canonical shape the sync
            // wrote (csm_headless_permission_prompt/question_prompt), so the
            // shared row renderer surfaces the blocked panel + the answer
            // actions just like a tmux session's blocked prompt.
            'blocked_reason' => is_string($blocked['question'] ?? null) ? $blocked['question'] : null,
            'prompt_context' => is_string($blocked['context'] ?? null) ? $blocked['context'] : null,
            'prompt_options' => is_array($blocked['options'] ?? null) ? $blocked['options'] : [],
            'prompt_multi_question' => (bool)($blocked['multi_question'] ?? false),
            'prompt_is_folder_trust' => (bool)($blocked['is_folder_trust'] ?? false),
            'prompt_tool_name' => is_string($blocked['tool_name'] ?? null) ? $blocked['tool_name'] : null,
            'prompt_tool_input' => is_array($blocked['tool_input'] ?? null) ? $blocked['tool_input'] : null,
            'prompt_questions' => null,
            'current_mode' => null,
            'current_model' => null,
            'current_antigravity_model' => null,
            'last_turn_error' => null,
            'claude_session_id' => $h['id'],
            'last_message' => null,
            'context_used_percentage' => null,
            'git_worktree' => null,
            'resume_hint' => null,
        ];
    }

    return $sessions;
}

/**
 * The headless OpenCode sessions (those hosted by `opencode serve`, not a
 * tmux pane), as a `headless` key on the `list` action's payload - the
 * runtime-parallel counterpart to the tracked tmux `sessions` and the bare
 * claude `bare` groups. Reads CSM's own headless sidecars (metadata) plus
 * SessionStatusStore (status), so the per-poll listing never hits `opencode
 * serve`; the serve-backed refresh is throttled inside csm_headless_sync().
 * Fails soft: no headless sidecars (serve unreachable, or none adopted yet)
 * contributes an empty list, never a broken dashboard.
 *
 * @return array{headless: array<int, array<string, mixed>>}
 */
function csm_headless_sessions(): array
{
    csm_headless_sync();

    $rows = [];

    foreach (SidecarStore::list_runtime_sidecars(RuntimeType::HEADLESS) as $sidecar) {
        $id = $sidecar['session_name'];
        $workdir = is_string($sidecar['workdir'] ?? null) ? $sidecar['workdir'] : null;
        $status = SessionStatusStore::read_status($id);

        // Title from the sidecar (the serve sync populates it), falling back
        // to a workdir basename so a not-yet-synced session still has
        // something readable - rather than ever showing the bare basename as
        // if it were the real title.
        $title = is_string($sidecar['title'] ?? null) && $sidecar['title'] !== ''
            ? $sidecar['title']
            : ($workdir !== null && $workdir !== '' ? basename($workdir) : $id);

        $rows[] = [
            'id' => $id,
            'session' => $id,
            'name' => $id,
            'title' => $title,
            'directory' => $workdir,
            'workdir' => $workdir,
            'agent' => is_string($sidecar['agent'] ?? null) ? $sidecar['agent'] : 'opencode',
            'runtime' => RuntimeType::HEADLESS,
            'status' => is_string($status['status'] ?? null) ? $status['status'] : 'idle',
            'blocked' => is_array($status['blocked'] ?? null) ? $status['blocked'] : null,
            'activity' => $status['updated_at'] ?? $sidecar['spawned_at'] ?? null,
        ];
    }

    return ['headless' => $rows];
}

/**
 * Throttled reflection of `opencode serve` into CSM's own per-session
 * stores, so the dashboard's frequent `list` poll never makes an HTTP call
 * to serve.
 *
 * On each tick where the throttle interval has elapsed (HEADLESS_SYNC_SECONDS,
 * default 15), ONE serve round-trip adopts every live serve session into a
 * headless sidecar (keyed by its ses_* id, runtime=headless), batches the
 * serve `/session/status` map into SessionStatusStore, and prunes headless
 * rows for sessions serve no longer has. Between ticks `list` reads the
 * sidecars/status locally. Serve unreachable: leaves the last-known
 * sidecars/status untouched (keeps the dashboard stable, not empty).
 */
function csm_headless_sync(): void
{
    csm_codex_sync();

    $rawInterval = getenv('HEADLESS_SYNC_SECONDS');
    $interval = $rawInterval === false || $rawInterval === '' ? 15 : max(0, (int)$rawInterval);
    $meta = GlobalStateStore::read('headless_sessions_sync');
    $lastSync = is_array($meta) ? (int)($meta['last_sync'] ?? 0) : 0;

    if (time() - $lastSync < $interval) {
        return;
    }

    $client = new OpenCodeServeClient();

    $list = $client->list_sessions();

    if ($list['ok'] !== true) {
        // Serve unreachable - keep whatever we last knew rather than
        // blanking the section or pruning rows based on stale data.
        return;
    }

    $liveIds = [];
    $sessions = $list['sessions'] ?? [];

    // A session is "active" (show it, adopt a sidecar) only if it's had
    // activity within HEADLESS_ACTIVE_WINDOW_SECONDS (default 1h). liveIds
    // doubles as the prune set: dormant sessions (>window) fall out of the
    // active list and back to the archived pool (which reads opencode.db),
    // so a big historical session set doesn't flood the active list and a
    // just-resumed session isn't pruned the way the old v1 /session (which
    // returns only the tiny "currently live" set) caused.
    $rawWindow = getenv('HEADLESS_ACTIVE_WINDOW_SECONDS');
    $window = $rawWindow === false || $rawWindow === '' ? 3600 : max(1, (int)$rawWindow);
    $now = time();

    foreach ($sessions as $s) {
        $id = is_string($s['id'] ?? null) ? $s['id'] : null;

        if ($id === null || $id === '') {
            continue;
        }

        $timeArr = is_array($s['time'] ?? null) ? $s['time'] : [];
        $updatedMs = (int)($timeArr['updated'] ?? $timeArr['created'] ?? 0);
        $updated = $updatedMs > 0 ? (int)round($updatedMs / 1000) : 0;

        // Dormant / not recently active - don't adopt and don't keep a stale
        // sidecar around for it.
        if ($updated > 0 && ($now - $updated) > $window) {
            continue;
        }

        $liveIds[$id] = true;
        $createdMs = (int)($timeArr['created'] ?? 0);
        // v2 /api/session items carry the dir under location.directory (v1
        // used a top-level directory); accept both so workdir is captured.
        $workdir = is_string($s['directory'] ?? null)
            ? $s['directory']
            : (is_array($s['location'] ?? null) && is_string($s['location']['directory'] ?? null) ? $s['location']['directory'] : null);
        $title = is_string($s['title'] ?? null) ? $s['title'] : null;

        SidecarStore::write_sidecar($id, [
            'workdir' => $workdir,
            'spawned_at' => $createdMs > 0 ? (int)round($createdMs / 1000) : time(),
            'claude_session_id' => $id,
            'spawned_by_csm' => true,
            'agent' => 'opencode',
            'runtime' => RuntimeType::HEADLESS,
            'title' => $title,
        ]);
    }

    foreach (SidecarStore::list_runtime_sidecars(RuntimeType::HEADLESS) as $row) {
        if (($row['agent'] ?? 'opencode') !== 'opencode') {
            continue;
        }
        if (!isset($liveIds[$row['session_name']])) {
            SidecarStore::delete_sidecar($row['session_name']);
            SessionStatusStore::delete_status($row['session_name']);
        }
    }

    // Populate a status row for EVERY adopted session - GET /session/status
    // only lists sessions it considers "live" (busy/retry), silently omitting
    // idle ones, so relying on it alone left sessions with no status row at
    // all (observed live 2026-08-26: sidecars populated, session_status
    // empty). Default to 'idle' for anything it didn't report, so status is
    // deterministic across every headless session. Clearing `blocked` here
    // (then re-set below for genuinely-blocked ones) prevents a stale prompt
    // from lingering once it's been resolved.
    $statuses = $client->status_map()['statuses'] ?? [];

    foreach ($liveIds as $id => $_) {
        SessionStatusStore::update_status($id, ['status' => $statuses[$id] ?? 'idle', 'blocked' => null]);
    }

    // Blocked-prompt detection: ONE GET /permission + ONE GET /question
    // batched across all sessions, then a headless session with a pending
    // permission/question gets status 'blocked' + its normalized prompt
    // shape (what the merged list's blocked panel + answer_prompt use).
    //
    // GET /permission returns empty on opencode 1.18.21 (the permission
    // lives in-memory in the session's own process). The CSM plugin
    // (csm-permissions.js) bridges permission.ask events into a JSON store
    // the host-agent reads here as the authoritative fallback.
    $permBySession = [];
    foreach ($client->pending_permissions() as $perm) {
        $sid = is_string($perm['sessionID'] ?? null) ? $perm['sessionID'] : null;
        if ($sid !== null) {
            $permBySession[$sid] = $perm;
        }
    }

    $questionBySession = [];
    foreach ($client->pending_questions() as $q) {
        $sid = is_string($q['sessionID'] ?? null) ? $q['sessionID'] : null;
        if ($sid !== null) {
            $questionBySession[$sid] = $q;
        }
    }

    foreach ($liveIds as $id => $_) {
        if (isset($permBySession[$id])) {
            SessionStatusStore::update_status($id, ['status' => 'blocked', 'blocked' => csm_headless_permission_prompt($permBySession[$id])]);
        } elseif (isset($questionBySession[$id])) {
            SessionStatusStore::update_status($id, ['status' => 'blocked', 'blocked' => csm_headless_question_prompt($questionBySession[$id])]);
        } else {
            // Plugin store fallback: the CSM plugin writes permission.ask
            // records to PermissionStore; GET /permission doesn't see them.
            $pluginPerm = \HostAgent\Services\PermissionStore::read_pending_permission($id);

            if ($pluginPerm !== null) {
                SessionStatusStore::update_status($id, ['status' => 'blocked', 'blocked' => csm_headless_permission_prompt($pluginPerm)]);
            }
        }
    }

    GlobalStateStore::write('headless_sessions_sync', ['last_sync' => time()]);

    // Cache the serve's available models (they rarely change) so the session
    // page's model dropdown can be populated without a live serve call on
    // every render. Refresh at most hourly.
    $cachedModels = GlobalStateStore::read('opencode_models');
    $modelsFresh = is_array($cachedModels) && is_int($cachedModels['updated_at'] ?? null) && (time() - $cachedModels['updated_at']) < 3600;

    if (!$modelsFresh) {
        GlobalStateStore::write('opencode_models', [
            'models' => $client->available_models(),
            'updated_at' => time(),
        ]);
    }
}

/** Reconciles recent native Codex app-server threads into headless sidecars. */
function csm_codex_sync(): void
{
    $meta = GlobalStateStore::read('codex_headless_sessions_sync');
    $lastSync = is_array($meta) ? (int)($meta['last_sync'] ?? 0) : 0;
    $rawInterval = getenv('HEADLESS_SYNC_SECONDS');
    $interval = $rawInterval === false || $rawInterval === '' ? 15 : max(0, (int)$rawInterval);
    if (time() - $lastSync < $interval) return;

    $runtime = RuntimeRegistry::runtime_for('codex', RuntimeType::HEADLESS);
    $list = $runtime?->list() ?? ['ok' => false];
    if ($list['ok'] !== true) return;

    $rawWindow = getenv('HEADLESS_ACTIVE_WINDOW_SECONDS');
    $window = $rawWindow === false || $rawWindow === '' ? 3600 : max(1, (int)$rawWindow);
    $now = time();
    $live = [];

    foreach (($list['sessions'] ?? []) as $thread) {
        if (!is_string($thread['id'] ?? null)) continue;
        $updated = (int)($thread['updatedAt'] ?? $thread['createdAt'] ?? 0);
        if ($updated > 0 && $now - $updated > $window) continue;
        $id = $thread['id'];
        $live[$id] = true;
        $statusType = $thread['status']['type'] ?? 'idle';
        SidecarStore::write_sidecar($id, [
            'workdir' => is_string($thread['cwd'] ?? null) ? $thread['cwd'] : null,
            'spawned_at' => (int)($thread['createdAt'] ?? time()),
            'claude_session_id' => $id,
            'spawned_by_csm' => true,
            'agent' => 'codex',
            'runtime' => RuntimeType::HEADLESS,
            'title' => is_string($thread['name'] ?? null) && $thread['name'] !== ''
                ? $thread['name']
                : (is_string($thread['preview'] ?? null) ? $thread['preview'] : null),
        ]);
        $existing = SessionStatusStore::read_status($id);
        if (($existing['status'] ?? null) !== 'blocked') {
            SessionStatusStore::update_status($id, ['status' => $statusType === 'active' ? 'working' : 'idle']);
        }
    }

    foreach (SidecarStore::list_runtime_sidecars(RuntimeType::HEADLESS) as $row) {
        if (($row['agent'] ?? null) !== 'codex') continue;
        if (!isset($live[$row['session_name']])) {
            SidecarStore::delete_sidecar($row['session_name']);
            SessionStatusStore::delete_status($row['session_name']);
        }
    }

    GlobalStateStore::write('codex_headless_sessions_sync', ['last_sync' => time()]);
}

/**
 * True when $ref is a headless (serve-hosted) OpenCode session - read from
 * that session's own sidecar's `runtime` column, not from a session-id
 * shape heuristic. A tmux-TUI opencode session has a sidecar with
 * runtime tmux (or NULL for a pre-headless row); a headless session's
 * sidecar is keyed by its ses_* id with runtime=headless.
 */
function csm_is_headless_session(string $ref): bool
{
    $sidecar = SidecarStore::read_sidecar($ref);

    return $sidecar !== null && ($sidecar['runtime'] ?? 'tmux') === RuntimeType::HEADLESS;
}

function csm_headless_agent(string $ref): ?string
{
    $sidecar = SidecarStore::read_sidecar($ref);
    return is_string($sidecar['agent'] ?? null) ? $sidecar['agent'] : null;
}

/**
 * Builds CSM's canonical blocked-prompt shape for a pending opencode
 * PERMISSION request (GET /permission item: id ^per, sessionID, permission,
 * patterns, tool). Options map to the serve reply verbs 1=once, 2=always,
 * 3=reject; request_id lets answer_prompt route to the permission reply.
 *
 * @param array<string, mixed> $perm
 * @return array<string, mixed>
 */
function csm_headless_permission_prompt(array $perm): array
{
    // GET /permission shape: {permission: string, patterns: [...], id: ...}
    // Plugin store shape:    {type: string, pattern: string, metadata.patterns: [...], id: ...}
    $permission = is_string($perm['permission'] ?? null)
        ? $perm['permission']
        : (is_string($perm['type'] ?? null) ? $perm['type'] : 'permission');
    $patterns = is_array($perm['patterns'] ?? null)
        ? array_values(array_filter($perm['patterns'], 'is_string'))
        : (is_array($perm['metadata']['patterns'] ?? null) ? array_values(array_filter($perm['metadata']['patterns'], 'is_string')) : []);
    $context = $permission;

    if ($patterns !== []) {
        $context .= ': ' . implode(', ', $patterns);
    }

    return [
        'tool_name' => 'permission',
        'question' => 'Do you want to proceed?',
        'context' => $context,
        'options' => [
            ['number' => 1, 'label' => 'Allow'],
            ['number' => 2, 'label' => 'Always allow'],
            ['number' => 3, 'label' => 'Deny'],
        ],
        'multi_question' => false,
        'is_folder_trust' => false,
        'request_id' => is_string($perm['id'] ?? null) ? $perm['id'] : null,
        'tool_input' => ['permission' => $permission, 'patterns' => $patterns],
    ];
}

/**
 * Builds CSM's canonical blocked-prompt shape for a pending opencode QUESTION
 * request (GET /question item) - reuses OpenCodeQuestionService::to_prompt()
 * and threads the request id through so answer_prompt can answer by label.
 *
 * @param array<string, mixed> $q
 * @return array<string, mixed>
 */
function csm_headless_question_prompt(array $q): array
{
    $requestId = is_string($q['id'] ?? null) ? $q['id'] : null;
    $questions = is_array($q['questions'] ?? null) ? $q['questions'] : [];
    $prompt = \HostAgent\Services\OpenCodeQuestionService::to_prompt(['requestID' => $requestId, 'questions' => $questions]);
    $prompt['request_id'] = $requestId;

    return $prompt;
}

/**
 * The serve's available models (flattened {providerID, id, name, family}) -
 * read from the sync's cache (GlobalStateStore), falling back to a live
 * /config/providers fetch if it isn't cached yet. Returns the CSM-shaped
 * model list so the session page's model dropdown can be populated client-side.
 *
 * @return array{ok:bool, models?:array<int, array<string, mixed>>, message?:string}
 */
function csm_list_models(string $agent = 'opencode'): array
{
    if ($agent === 'codex') {
        $reply = (new \HostAgent\Runtimes\CodexBridgeClient())->request('model/list', ['limit' => 100, 'includeHidden' => false]);
        if ($reply['ok'] !== true) return $reply;
        $data = is_array($reply['result']['data'] ?? null) ? $reply['result']['data'] : [];
        $models = [];
        foreach ($data as $model) {
            if (!is_array($model) || !is_string($model['model'] ?? null)) continue;
            $efforts = [];
            foreach (($model['supportedReasoningEfforts'] ?? []) as $option) {
                if (is_array($option) && is_string($option['reasoningEffort'] ?? null)) $efforts[] = $option['reasoningEffort'];
            }
            $models[] = [
                'id' => $model['model'],
                'name' => is_string($model['displayName'] ?? null) ? $model['displayName'] : $model['model'],
                'isDefault' => (bool)($model['isDefault'] ?? false),
                'defaultEffort' => is_string($model['defaultReasoningEffort'] ?? null) ? $model['defaultReasoningEffort'] : null,
                'efforts' => $efforts,
            ];
        }
        return ['ok' => true, 'models' => $models];
    }

    $models = (new OpenCodeServeClient())->available_models();

    if ($models !== []) {
        GlobalStateStore::write('opencode_models', ['models' => $models, 'updated_at' => time()]);
    }

    return ['ok' => true, 'models' => array_values($models)];
}

/**
 * Answers a headless session's pending prompt via the headless runtime
 * (question by option number or label). Wrap so the dispatch action can stay
 * a thin switch; returns a handled failure if the runtime isn't available.
 *
 * @param array<string, mixed> $answers
 * @return array{ok:bool, message?:string}
 */
function csm_headless_answer_prompt(string $ref, array $answers): array
{
    $agent = csm_headless_agent($ref);
    $headless = $agent !== null ? RuntimeRegistry::runtime_for($agent, RuntimeType::HEADLESS) : null;

    return $headless !== null
        ? $headless->answer_prompt($ref, $answers)
        : ['ok' => false, 'message' => 'Headless runtime unavailable'];
}

/**
 * Resumes an opencode session (by its ses_* id) as a serve-hosted (headless)
 * session - the archived opencode session's "Resume" action, routed through
 * the server rather than spawning a tmux `opencode --session` pane. Adopts a
 * headless sidecar immediately so the redirect to session.php?session=<id>
 * resolves right away. Returns the create_cc_session()-shape `name` (=id)
 * the resume controller redirects on.
 *
 * @return array{ok:bool, name?:string, session?:string, id?:string, message?:string}
 */
function csm_headless_resume(string $workdir, string $claudeSessionId): array
{
    if ($workdir === '' || $workdir[0] !== '/') {
        return ['ok' => false, 'message' => 'Working directory must be an absolute path'];
    }

    $client = new OpenCodeServeClient();

    if (!is_dir($workdir)) {
        return ['ok' => false, 'message' => 'Working directory does not exist'];
    }

    $result = $client->resume_session($claudeSessionId, $workdir);

    if ($result['ok'] !== true || !is_string($result['id'] ?? null)) {
        return ['ok' => false, 'message' => $result['message'] ?? 'Resume failed'];
    }

    $id = $result['id'];
    $createdSession = is_array($result['session'] ?? null) ? $result['session'] : [];

    SidecarStore::write_sidecar($id, [
        'workdir' => $workdir,
        'spawned_at' => time(),
        'claude_session_id' => $id,
        'spawned_by_csm' => true,
        'agent' => 'opencode',
        'runtime' => RuntimeType::HEADLESS,
        'title' => is_string($createdSession['title'] ?? null) && $createdSession['title'] !== '' ? $createdSession['title'] : null,
    ]);

    return ['ok' => true, 'name' => $id, 'session' => $id, 'id' => $id];
}

/**
 * Normalizes a headless session's serve detail object into the same
 * session-entry shape the session.php page + sidebar expect (title/name/
 * workdir/agent/agent_label/claude_session_id/status). The raw serve GET
 * /session/{id} object carries id/title/directory but none of those keys,
 * which is why a headless session page used to render broken/blank instead
 * of loading - see docs/headless-runtime-plan.md Phase 3. Status comes from
 * the same SessionStatusStore the sync writes (default 'idle'); rich
 * blocked-prompt detail is Phase 3.
 *
 * @param array<string, mixed> $serve the GET /session/{id} object
 * @return array<string, mixed>
 */
function csm_headless_detail_shape(array $serve, string $agentId = 'opencode'): array
{
    $id = is_string($serve['id'] ?? null) ? $serve['id'] : '';
    $workdir = is_string($serve['directory'] ?? null) ? $serve['directory'] : (is_string($serve['cwd'] ?? null) ? $serve['cwd'] : null);
    $status = SessionStatusStore::read_status($id);
    $model = is_array($serve['model'] ?? null) ? $serve['model'] : [];
    $blocked = is_array($status['blocked'] ?? null) ? $status['blocked'] : null;

    // Fetch the todo/task list from the serve's GET /session/:id/todo
    // endpoint. Each item is mapped to the CSM sidebar shape
    // {content, activeForm, status} — opencode has no activeForm, so
    // content is used for both.
    $todos = null;
    $todoResult = $agentId === 'opencode' ? (new OpenCodeServeClient())->get_todo($id) : ['ok' => false];

    if ($todoResult['ok'] === true && is_array($todoResult['todos'] ?? null)) {
        $mapped = [];

        foreach ($todoResult['todos'] as $item) {
            if (!is_array($item) || !is_string($item['content'] ?? null) || !is_string($item['status'] ?? null)) {
                continue;
            }

            $mapped[] = [
                'content' => $item['content'],
                'activeForm' => $item['content'],
                'status' => $item['status'],
            ];
        }

        $todos = $mapped !== [] ? $mapped : null;
    }

    return [
        'ok' => true,
        'id' => $id,
        'session' => $id,
        'name' => $id,
        'title' => is_string($serve['title'] ?? null) && $serve['title'] !== '' ? $serve['title'] : (is_string($serve['name'] ?? null) && $serve['name'] !== '' ? $serve['name'] : (is_string($serve['preview'] ?? null) ? $serve['preview'] : $id)),
        'workdir' => $workdir,
        'directory' => $workdir,
        'agent' => $agentId,
        'agent_label' => AgentRegistry::get($agentId)->label(),
        'claude_session_id' => $id,
        'runtime' => RuntimeType::HEADLESS,
        'status' => is_string($status['status'] ?? null) ? $status['status'] : 'idle',
        'working' => ($status['status'] ?? null) === 'working',
        'blocked_reason' => is_string($blocked['question'] ?? null) ? $blocked['question'] : null,
        'prompt_context' => is_string($blocked['context'] ?? null) ? $blocked['context'] : null,
        'prompt_options' => is_array($blocked['options'] ?? null) ? $blocked['options'] : [],
        'prompt_multi_question' => (bool)($blocked['multi_question'] ?? false),
        'prompt_is_folder_trust' => (bool)($blocked['is_folder_trust'] ?? false),
        'prompt_tool_name' => is_string($blocked['tool_name'] ?? null) ? $blocked['tool_name'] : null,
        'prompt_tool_input' => is_array($blocked['tool_input'] ?? null) ? $blocked['tool_input'] : null,
        'prompt_questions' => null,
        'attached' => false,
        'pid' => null,
        'current_mode' => null,
        'current_model' => is_string($model['id'] ?? null) ? $model['id'] : (is_string($serve['model'] ?? null) ? $serve['model'] : null),
        'writable' => $agentId !== 'codex' || ($serve['writable'] ?? true) === true,
        'read_only_reason' => is_string($serve['readOnlyReason'] ?? null) ? $serve['readOnlyReason'] : null,
        'current_provider' => is_string($model['providerID'] ?? null) ? $model['providerID'] : (is_string($serve['modelProvider'] ?? null) ? $serve['modelProvider'] : null),
        'current_effort' => is_string($serve['reasoningEffort'] ?? null) ? $serve['reasoningEffort'] : (is_string($serve['effort'] ?? null) ? $serve['effort'] : null),
        'last_turn_error' => null,
        'context_used_percentage' => null,
        'git_worktree' => null,
        'has_transcript' => true,
        'todos' => $todos,
    ];
}
