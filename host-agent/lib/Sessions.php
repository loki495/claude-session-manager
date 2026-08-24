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
use HostAgent\Services\BareProcessService;
use HostAgent\Services\HookService;
use HostAgent\Services\UploadService;
use HostAgent\Services\QuotaService;

/**
 * @return array
 */
function dispatch_action(array $request): array
{
    switch ($request['action'] ?? '') {
        case 'list':
            return ['ok' => true] + SessionService::list_all_sessions();

        case 'list_archived':
            return ['ok' => true] + ArchivedSessionService::list_archived_dashboard();

        case 'session_detail':
            return SessionDetailService::session_detail((string)($request['session'] ?? ''));

        case 'archived_session_detail':
            return SessionDetailService::archived_session_detail((string)($request['claude_session_id'] ?? ''));

        case 'session_history':
            return SessionDetailService::session_history(
                (string)($request['session'] ?? ''),
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

            return SessionLifecycleService::create_cc_session((string)($request['workdir'] ?? ''), (bool)($request['enable_task_tools'] ?? false), is_string($startingMode) && $startingMode !== '' ? $startingMode : null);

        case 'resume':
            return SessionLifecycleService::resume_cc_session((string)($request['workdir'] ?? ''), (string)($request['claude_session_id'] ?? ''));

        case 'kill':
            return SessionLifecycleService::kill_cc_session((string)($request['session'] ?? ''));

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
            return PromptInteractionService::answer_prompt((string)($request['session'] ?? ''), (int)($request['option'] ?? 0));

        case 'answer_prompt_with_text':
            return PromptInteractionService::answer_prompt_with_text((string)($request['session'] ?? ''), (int)($request['option'] ?? 0), (string)($request['text'] ?? ''));

        case 'answer_multi_question':
            return PromptInteractionService::answer_multi_question((string)($request['session'] ?? ''), is_array($request['answers'] ?? null) ? $request['answers'] : []);

        case 'send_escape':
            return PromptInteractionService::send_escape((string)($request['session'] ?? ''));

        case 'send_message':
            return PromptInteractionService::send_message(
                (string)($request['session'] ?? ''),
                (string)($request['text'] ?? ''),
                is_array($request['attachment_paths'] ?? null) ? array_map('strval', $request['attachment_paths']) : []
            );

        case 'set_mode':
            return PromptInteractionService::set_mode((string)($request['session'] ?? ''), (string)($request['mode'] ?? ''));

        case 'set_model':
            return PromptInteractionService::set_model((string)($request['session'] ?? ''), (string)($request['model'] ?? ''));

        case 'cleanup':
            return SessionLifecycleService::cleanup_inactive_sessions();

        case 'browse_dir':
            return SessionService::browse_dir((string)($request['path'] ?? ''));

        case 'list_plan_files':
            return PlanFileService::list_plan_files((string)($request['session'] ?? ''));

        case 'read_plan_file':
            return PlanFileService::read_plan_file((string)($request['session'] ?? ''), (string)($request['filename'] ?? ''));

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
