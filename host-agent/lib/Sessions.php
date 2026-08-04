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

        case 'session_detail':
            return SessionService::session_detail((string)($request['session'] ?? ''));

        case 'session_history':
            return SessionService::session_history(
                (string)($request['session'] ?? ''),
                isset($request['before']) ? (int)$request['before'] : null,
                isset($request['limit']) ? (int)$request['limit'] : 30
            );

        case 'create':
            return SessionService::create_cc_session((string)($request['workdir'] ?? ''));

        case 'kill':
            return SessionService::kill_cc_session((string)($request['session'] ?? ''));

        case 'kill_bare':
            return SessionService::kill_bare_process((int)($request['pid'] ?? 0));

        case 'answer_prompt':
            return SessionService::answer_prompt((string)($request['session'] ?? ''), (int)($request['option'] ?? 0));

        case 'answer_prompt_with_text':
            return SessionService::answer_prompt_with_text((string)($request['session'] ?? ''), (int)($request['option'] ?? 0), (string)($request['text'] ?? ''));

        case 'navigate_prompt':
            return SessionService::navigate_prompt((string)($request['session'] ?? ''), (string)($request['direction'] ?? ''));

        case 'send_escape':
            return SessionService::send_escape((string)($request['session'] ?? ''));

        case 'send_message':
            return SessionService::send_message((string)($request['session'] ?? ''), (string)($request['text'] ?? ''));

        case 'set_mode':
            return SessionService::set_mode((string)($request['session'] ?? ''), (string)($request['mode'] ?? ''));

        case 'cleanup':
            return SessionService::cleanup_inactive_sessions();

        case 'browse_dir':
            return SessionService::browse_dir((string)($request['path'] ?? ''));

        case 'quota':
            return QuotaService::get_quota();

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

        case 'delete_uploaded_file':
            return UploadService::delete_uploaded_file((string)($request['session'] ?? ''), (string)($request['filename'] ?? ''));

        case 'delete_all_uploaded_files':
            return UploadService::delete_all_uploaded_files((string)($request['session'] ?? ''));

        default:
            return ['ok' => false, 'message' => 'Unknown action'];
    }
}
