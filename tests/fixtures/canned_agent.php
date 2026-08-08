#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Stand-in for host-agent/agent.php used only by test_ui_smoke.php: same
 * one-request-one-response JSON-over-stdio shape (see socket_harness.php),
 * but returns fixed canned data instead of touching tmux. This lets the UI
 * smoke test exercise DashboardController's rendering and form-handling
 * logic without ever creating a real (or even fixture) tmux session.
 */

const CANNED_SESSION_NAME = 'cc-20260101-1200';
const CANNED_BLOCKED_SESSION_NAME = 'cc-20260101-1300';
const CANNED_BARE_PID = 54321;
const CANNED_CLAUDE_SESSION_ID = '11111111-2222-4333-8444-555555555555';
// A real, tiny, valid 1x1 PNG (not a placeholder string) - lets the UI
// smoke test's headless browser actually decode/render it, not just check
// an <img> tag exists in the markup.
const CANNED_TEST_IMAGE_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
const CANNED_VAPID_PUBLIC_KEY = 'BAhRdSrCIQS6QqCKKxkfmfSQ_DyQk63-8zoSMWlb2PXjhuTym7Lxyboe7HSFwi79IJN7-wqbUbZmYR1CkLvXZSc';
const CANNED_ATTACHMENT_FILE_UUID = 'canned-file-uuid-1';
const CANNED_ATTACHMENT_BYTES = 'canned attachment bytes';
const CANNED_IMAGE_ATTACHMENT_FILE_UUID = 'canned-file-uuid-2';
const CANNED_ARCHIVED_CLAUDE_SESSION_ID = '99999999-8888-4777-a666-555555555555';

const CANNED_LAST_MESSAGE = [
    'role' => 'assistant',
    'timestamp' => '2026-01-01T12:05:00Z',
    'blocks' => [['kind' => 'text', 'text' => "I'll clean up the temp directory now."]],
];

/** @return array<int, array<string, mixed>> */
function canned_session_history_entries(): array
{
    return [
        ['type' => 'user', 'role' => 'user', 'timestamp' => '2026-01-01T12:00:00Z', 'blocks' => [['kind' => 'text', 'text' => 'Fix the login redirect bug']], 'line' => 2],
        ['type' => 'assistant', 'role' => 'assistant', 'timestamp' => '2026-01-01T12:00:05Z', 'blocks' => [['kind' => 'text', 'text' => 'Looking into it now.']], 'line' => 3],
        ['type' => 'assistant', 'role' => 'assistant', 'timestamp' => '2026-01-01T12:00:10Z', 'blocks' => [['kind' => 'tool_use', 'text' => 'Bash(pwd)']], 'line' => 4],
        ['type' => 'user', 'role' => 'user', 'timestamp' => '2026-01-01T12:00:15Z', 'blocks' => [['kind' => 'tool_result', 'text' => 'done', 'image' => ['media_type' => 'image/png', 'data' => CANNED_TEST_IMAGE_BASE64]]], 'line' => 5],
        // Real shape captured live 2026-08-02 against an actual
        // subagent call - see agent_type in TranscriptService's
        // parse_transcript_line()/summarize_content_block().
        ['type' => 'assistant', 'role' => 'assistant', 'timestamp' => '2026-01-01T12:00:20Z', 'blocks' => [['kind' => 'tool_use', 'text' => 'tool: Agent - general-purpose: Investigate the login bug', 'agent_type' => 'general-purpose']], 'line' => 6],
        ['type' => 'user', 'role' => 'user', 'timestamp' => '2026-01-01T12:00:25Z', 'blocks' => [['kind' => 'tool_result', 'text' => 'Found it: the redirect URL was hardcoded.', 'agent_type' => 'general-purpose']], 'line' => 7],
        // A SendUserFile-style tool_result: real file metadata
        // threaded from the outer toolUseResult.attachments field
        // (see TranscriptService::transcript_attachments_from_tool_use_result())
        // rather than embedded in the content blocks themselves.
        // Two attachments on one line - the real shape a SendUserFile
        // call sending both a download and a screenshot produces
        // (verified live 2026-08-04 against this app's own transcript).
        ['type' => 'user', 'role' => 'user', 'timestamp' => '2026-01-01T12:00:30Z', 'blocks' => [['kind' => 'tool_result', 'text' => 'Sent 2 file(s) to the user.', 'attachments' => [
            ['file_uuid' => CANNED_ATTACHMENT_FILE_UUID, 'filename' => 'notes.txt', 'size' => strlen(CANNED_ATTACHMENT_BYTES), 'isImage' => false, 'media_type' => 'text/plain'],
            ['file_uuid' => CANNED_IMAGE_ATTACHMENT_FILE_UUID, 'filename' => 'screenshot.png', 'size' => strlen(base64_decode(CANNED_TEST_IMAGE_BASE64, true)), 'isImage' => true, 'media_type' => 'image/png'],
        ]]], 'line' => 8],
        // ExitPlanMode - real shape verified live 2026-08-07: its own
        // 'plan' block kind (not a generic tool_use param dump), and the
        // matching tool_result carries plan_status (see TranscriptService::
        // parse_transcript_line()) rather than the verbose real "## Approved
        // Plan: ..." boilerplate.
        ['type' => 'assistant', 'role' => 'assistant', 'timestamp' => '2026-01-01T12:00:35Z', 'blocks' => [['kind' => 'plan', 'text' => "# Refactor the login flow\n\nSome real detail here."]], 'line' => 9],
        ['type' => 'user', 'role' => 'user', 'timestamp' => '2026-01-01T12:00:40Z', 'blocks' => [['kind' => 'tool_result', 'text' => 'Plan approved - starting work', 'plan_status' => 'approved']], 'line' => 10],
    ];
}

/**
 * `after` (when present) mirrors TranscriptService::read_transcript_page_since()'s
 * real filtering - lets test_ui_smoke.php prove session_history.php's
 * &after= param actually reaches the agent action, not just that the
 * endpoint responds.
 *
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function canned_session_history(array $request): array
{
    if (($request['session'] ?? null) !== CANNED_SESSION_NAME) {
        return ['ok' => false, 'message' => 'No transcript recorded for this session'];
    }

    $entries = canned_session_history_entries();

    if (isset($request['after'])) {
        $after = (int)$request['after'];

        return ['ok' => true, 'entries' => array_values(array_filter($entries, static fn(array $e): bool => $e['line'] > $after))];
    }

    return ['ok' => true, 'entries' => $entries, 'next_before' => 1, 'has_more' => true];
}

$input = stream_get_contents(STDIN);
$request = json_decode((string)$input, true);
$action = is_array($request) ? ($request['action'] ?? null) : null;

$response = match ($action) {
    'list' => [
        'ok' => true,
        'sessions' => [[
            'name' => CANNED_SESSION_NAME,
            'activity' => time() - 120,
            'attached' => false,
            'pid' => 12345,
            'workdir' => '/home/andres/www/demo-project',
            'spawned_by_csm' => true,
            'title' => 'Fix the login redirect bug',
            'working' => true,
            'blocked_reason' => null,
            'resume_hint' => null,
            'current_mode' => 'manual',
            'last_message' => CANNED_LAST_MESSAGE,
        ], [
            'name' => CANNED_BLOCKED_SESSION_NAME,
            'activity' => time() - 60,
            'attached' => false,
            'pid' => 12346,
            'workdir' => '/home/andres/www/other-project',
            'spawned_by_csm' => true,
            'title' => 'Clean up temp files',
            'working' => true,
            'blocked_reason' => 'Do you want to proceed?',
            'resume_hint' => 'tmux -S /fake/socket attach -t ' . CANNED_BLOCKED_SESSION_NAME,
            'prompt_context' => "Bash command\n\n  rm -rf /tmp/dashboard-example\n  Remove old temp files",
            'prompt_options' => [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']],
            'prompt_multi_question' => true,
            'prompt_is_folder_trust' => false,
            'last_message' => ['role' => 'assistant', 'timestamp' => '2026-01-01T13:04:00Z', 'blocks' => [['kind' => 'text', 'text' => 'Found some old temp files worth cleaning up.']]],
        ]],
        'bare' => [[
            'pid' => CANNED_BARE_PID,
            'cwd' => '/home/andres/www/some-other-project',
            'started_at' => time() - 600,
            'tmux_session' => 'csm-test-adhoc',
            'title' => 'Bare title',
        ]],
    ],
    'list_archived' => [
        'ok' => true,
        'archived' => [[
            'claude_session_id' => CANNED_ARCHIVED_CLAUDE_SESSION_ID,
            'cwd' => '/home/andres/www/old-project',
            'title' => 'Refactor the old widget',
            'last_activity' => time() - 3 * 86400,
        ]],
    ],
    'archived_session_detail' => ($request['claude_session_id'] ?? null) === CANNED_ARCHIVED_CLAUDE_SESSION_ID
        ? [
            'ok' => true,
            'claude_session_id' => CANNED_ARCHIVED_CLAUDE_SESSION_ID,
            'cwd' => '/home/andres/www/old-project',
            'title' => 'Refactor the old widget',
            'last_activity' => time() - 3 * 86400,
        ]
        : ['ok' => false, 'message' => 'Session not found'],
    'archived_session_history' => ($request['claude_session_id'] ?? null) === CANNED_ARCHIVED_CLAUDE_SESSION_ID
        ? ['ok' => true, 'entries' => canned_session_history_entries(), 'next_before' => 1, 'has_more' => true]
        : ['ok' => false, 'message' => 'Transcript file not found'],
    'session_detail' => ($request['session'] ?? null) === CANNED_SESSION_NAME
        ? [
            'ok' => true,
            'name' => CANNED_SESSION_NAME,
            'activity' => time() - 120,
            'attached' => false,
            'pid' => 12345,
            'workdir' => '/home/andres/www/demo-project',
            'spawned_by_csm' => true,
            'title' => 'Fix the login redirect bug',
            'working' => false,
            'blocked_reason' => 'Do you want to proceed?',
            'resume_hint' => 'tmux -S /fake/socket attach -t ' . CANNED_SESSION_NAME,
            'prompt_context' => "Bash command\n\n  rm -rf /tmp/canned-example\n  Clean up the canned example directory",
            'prompt_options' => [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No'], ['number' => 3, 'label' => 'Type something.']],
            'prompt_multi_question' => true,
            'current_mode' => null,
            'claude_session_id' => CANNED_CLAUDE_SESSION_ID,
            'has_transcript' => true,
        ]
        : ['ok' => false, 'message' => 'Session not found'],
    'answer_prompt' => ($request['session'] ?? null) === CANNED_SESSION_NAME && ($request['option'] ?? null) === 1
        ? ['ok' => true, 'message' => 'Sent option 1 to ' . CANNED_SESSION_NAME]
        : ['ok' => false, 'message' => 'Rejected: that option is not currently offered by this prompt'],
    'answer_prompt_with_text' => ($request['session'] ?? null) === CANNED_SESSION_NAME && ($request['option'] ?? null) === 3 && trim((string)($request['text'] ?? '')) !== ''
        ? ['ok' => true, 'message' => 'Sent free-text reply to ' . CANNED_SESSION_NAME]
        : ['ok' => false, 'message' => 'Reply cannot be empty'],
    'session_history' => canned_session_history($request),
    'session_attachment' => (string)($request['session'] ?? null) === CANNED_SESSION_NAME
        ? match ($request['file_uuid'] ?? null) {
            CANNED_ATTACHMENT_FILE_UUID => ['ok' => true, 'data' => base64_encode(CANNED_ATTACHMENT_BYTES), 'media_type' => 'text/plain', 'filename' => 'notes.txt', 'size' => strlen(CANNED_ATTACHMENT_BYTES)],
            CANNED_IMAGE_ATTACHMENT_FILE_UUID => ['ok' => true, 'data' => CANNED_TEST_IMAGE_BASE64, 'media_type' => 'image/png', 'filename' => 'screenshot.png', 'size' => strlen(base64_decode(CANNED_TEST_IMAGE_BASE64, true))],
            default => ['ok' => false, 'message' => 'Attachment not found'],
        }
        : ['ok' => false, 'message' => 'Attachment not found'],
    'archived_session_attachment' => (string)($request['claude_session_id'] ?? null) === CANNED_ARCHIVED_CLAUDE_SESSION_ID
        ? match ($request['file_uuid'] ?? null) {
            CANNED_ATTACHMENT_FILE_UUID => ['ok' => true, 'data' => base64_encode(CANNED_ATTACHMENT_BYTES), 'media_type' => 'text/plain', 'filename' => 'notes.txt', 'size' => strlen(CANNED_ATTACHMENT_BYTES)],
            CANNED_IMAGE_ATTACHMENT_FILE_UUID => ['ok' => true, 'data' => CANNED_TEST_IMAGE_BASE64, 'media_type' => 'image/png', 'filename' => 'screenshot.png', 'size' => strlen(base64_decode(CANNED_TEST_IMAGE_BASE64, true))],
            default => ['ok' => false, 'message' => 'Attachment not found'],
        }
        : ['ok' => false, 'message' => 'Attachment not found'],
    'browse_dir' => [
        'ok' => true,
        'path' => '/home/andres/www',
        'parent' => '/home/andres',
        'dirs' => ['project-a', 'project-b'],
    ],
    'create' => ['ok' => true, 'message' => 'Created session cc-20260101-1300 in /home/andres/www/demo-project'],
    'kill' => ($request['session'] ?? null) === CANNED_SESSION_NAME
        ? ['ok' => true, 'message' => 'Killed ' . CANNED_SESSION_NAME]
        : ['ok' => false, 'message' => 'Rejected: not a currently active managed session'],
    'kill_bare' => ($request['pid'] ?? null) === CANNED_BARE_PID
        ? ['ok' => true, 'message' => 'Killed tmux session csm-test-adhoc (pid ' . CANNED_BARE_PID . ')']
        : ['ok' => false, 'message' => 'Rejected: not a currently running claude process'],
    // An attachment with no typed text at all is a valid send (mirrors
    // SessionService::send_message()'s own real semantics) - lets
    // test_ui_smoke.php prove session_send.php's attachments[] field
    // actually reaches the agent action as attachment_paths.
    'send_message' => ($request['session'] ?? null) === CANNED_SESSION_NAME && (trim((string)($request['text'] ?? '')) !== '' || !empty($request['attachment_paths']))
        ? ['ok' => true, 'message' => 'Sent message to ' . CANNED_SESSION_NAME]
        : ['ok' => false, 'message' => 'Message cannot be empty'],
    'set_mode' => ($request['session'] ?? null) === CANNED_SESSION_NAME && in_array($request['mode'] ?? null, ['manual', 'accept edits', 'plan', 'auto'], true)
        ? ['ok' => true, 'message' => 'Set mode for ' . CANNED_SESSION_NAME . ' to ' . $request['mode']]
        : ['ok' => false, 'message' => 'Rejected: not a currently active managed session'],
    'send_escape' => ($request['session'] ?? null) === CANNED_SESSION_NAME
        ? ['ok' => true, 'message' => 'Sent Escape to ' . CANNED_SESSION_NAME]
        : ['ok' => false, 'message' => 'Rejected: not a currently active managed session'],
    'navigate_prompt' => in_array($request['session'] ?? null, [CANNED_SESSION_NAME, CANNED_BLOCKED_SESSION_NAME], true) && in_array($request['direction'] ?? null, ['left', 'right'], true)
        ? ['ok' => true, 'message' => 'Sent ' . ucfirst((string)$request['direction']) . ' to ' . $request['session']]
        : ['ok' => false, 'message' => 'Rejected: this session is not currently showing a multi-question prompt'],
    'save_uploaded_file' => ($request['session'] ?? null) === CANNED_SESSION_NAME && trim((string)($request['filename'] ?? '')) !== ''
        ? ['ok' => true, 'filename' => $request['filename'], 'path' => '.claude/uploads/' . $request['filename'], 'size' => strlen(base64_decode((string)($request['content_base64'] ?? ''), true) ?: '')]
        : ['ok' => false, 'message' => 'Rejected: not a currently active managed session'],
    'list_uploaded_files' => ($request['session'] ?? null) === CANNED_SESSION_NAME
        ? ['ok' => true, 'files' => [['name' => 'photo.jpg', 'size' => 204800, 'mtime' => time() - 60], ['name' => 'notes.txt', 'size' => 512, 'mtime' => time() - 120]], 'total_size' => 205312]
        : ['ok' => false, 'message' => 'Unknown working directory for this session'],
    'delete_uploaded_file' => ($request['session'] ?? null) === CANNED_SESSION_NAME && ($request['filename'] ?? null) === 'photo.jpg'
        ? ['ok' => true]
        : ['ok' => false, 'message' => 'File not found'],
    'delete_all_uploaded_files' => ($request['session'] ?? null) === CANNED_SESSION_NAME
        ? ['ok' => true, 'deleted' => 2]
        : ['ok' => false, 'message' => 'Unknown working directory for this session'],
    'push_public_key' => ['ok' => true, 'configured' => true, 'public_key' => CANNED_VAPID_PUBLIC_KEY],
    'push_subscribe' => is_array($request['subscription'] ?? null) && is_string($request['subscription']['endpoint'] ?? null)
        ? ['ok' => true]
        : ['ok' => false, 'message' => 'Malformed subscription'],
    'push_unsubscribe' => ['ok' => true],
    'cleanup' => ['ok' => true, 'killed' => [CANNED_SESSION_NAME], 'failed' => []],
    'check_session_hook' => ['ok' => true, 'installed' => true],
    'install_session_hook' => ['ok' => true, 'installed' => true],
    // Mirrors QuotaService::get_quota($sessionName)'s real behavior: a
    // 'context' bucket (no resets_at - it has no reset timer) only appears
    // when the request names a known-live session, alongside the
    // account-wide session/week_all buckets either way.
    'quota' => [
        'ok' => true,
        'quota' => array_merge(
            (string)($request['session'] ?? '') === CANNED_SESSION_NAME
                ? ['context' => ['pct' => 12]]
                : [],
            [
                'session' => ['pct' => 73, 'resets' => '3pm (America/Los_Angeles)', 'resets_at' => time() + 3600 + 1800],
                'week_all' => ['pct' => 29, 'resets' => 'Jul 10, 8pm (America/Los_Angeles)', 'resets_at' => time() + 2 * 86400 + 5 * 3600],
                'week_fable' => ['pct' => 92, 'resets' => 'Jul 10, 8pm (America/Los_Angeles)', 'resets_at' => time() + 2 * 86400 + 5 * 3600],
                'captured_at' => '2026-07-08T12:00:00-0700',
            ]
        ),
        'fetched_at' => time() - 120,
        'cached' => true,
        'stale' => false,
        'refreshing' => false,
    ],
    default => ['ok' => false, 'message' => 'Unknown action'],
};

fwrite(STDOUT, json_encode($response));
