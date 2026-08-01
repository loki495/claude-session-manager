#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Stand-in for host-agent/agent.php used only by test_ui_smoke.php: same
 * one-request-one-response JSON-over-stdio shape (see socket_harness.php),
 * but returns fixed canned data instead of touching tmux. This lets the UI
 * smoke test exercise src/index.php's rendering and form-handling logic
 * without ever creating a real (or even fixture) tmux session.
 */

const CANNED_SESSION_NAME = 'cc-20260101-1200';
const CANNED_BARE_PID = 54321;
const CANNED_CLAUDE_SESSION_ID = '11111111-2222-4333-8444-555555555555';

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
            'blocked_reason' => null,
            'resume_hint' => null,
        ]],
        'bare' => [[
            'pid' => CANNED_BARE_PID,
            'cwd' => '/home/andres/www/some-other-project',
            'started_at' => time() - 600,
            'tmux_session' => 'csm-test-adhoc',
            'title' => 'Bare title',
        ]],
    ],
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
            'blocked_reason' => 'Do you want to proceed?',
            'resume_hint' => 'tmux -S /fake/socket attach -t ' . CANNED_SESSION_NAME,
            'prompt_context' => "Bash command\n\n  rm -rf /tmp/canned-example\n  Clean up the canned example directory",
            'prompt_options' => [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']],
            'claude_session_id' => CANNED_CLAUDE_SESSION_ID,
            'has_transcript' => true,
        ]
        : ['ok' => false, 'message' => 'Session not found'],
    'answer_prompt' => ($request['session'] ?? null) === CANNED_SESSION_NAME && ($request['option'] ?? null) === 1
        ? ['ok' => true, 'message' => 'Sent option 1 to ' . CANNED_SESSION_NAME]
        : ['ok' => false, 'message' => 'Rejected: that option is not currently offered by this prompt'],
    'session_history' => ($request['session'] ?? null) === CANNED_SESSION_NAME
        ? [
            'ok' => true,
            'entries' => [
                ['type' => 'user', 'role' => 'user', 'timestamp' => '2026-01-01T12:00:00Z', 'blocks' => [['kind' => 'text', 'text' => 'Fix the login redirect bug']], 'line' => 2],
                ['type' => 'assistant', 'role' => 'assistant', 'timestamp' => '2026-01-01T12:00:05Z', 'blocks' => [['kind' => 'text', 'text' => 'Looking into it now.']], 'line' => 3],
            ],
            'next_before' => 1,
            'has_more' => true,
        ]
        : ['ok' => false, 'message' => 'No transcript recorded for this session'],
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
    'cleanup' => ['ok' => true, 'killed' => [CANNED_SESSION_NAME], 'failed' => []],
    'quota' => [
        'ok' => true,
        'quota' => [
            'session' => ['pct' => 73, 'resets' => '3pm (America/Los_Angeles)', 'resets_at' => time() + 3600 + 1800],
            'week_all' => ['pct' => 29, 'resets' => 'Jul 10, 8pm (America/Los_Angeles)', 'resets_at' => time() + 2 * 86400 + 5 * 3600],
            'week_fable' => ['pct' => 92, 'resets' => 'Jul 10, 8pm (America/Los_Angeles)', 'resets_at' => time() + 2 * 86400 + 5 * 3600],
            'captured_at' => '2026-07-08T12:00:00-0700',
        ],
        'fetched_at' => time() - 120,
        'cached' => true,
        'stale' => false,
        'refreshing' => false,
    ],
    default => ['ok' => false, 'message' => 'Unknown action'],
};

fwrite(STDOUT, json_encode($response));
