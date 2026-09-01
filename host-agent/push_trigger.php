#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Standalone entry point run periodically by the sessioneer-push-check systemd
 * timer (see host-agent/systemd/) - two independent passes every tick:
 * every live session checked for a fresh transition into a blocked/
 * waiting-on-input state (PushDeliveryService::check_and_send_pushes()),
 * and account-wide quota checked for a bucket crossing near/over a
 * threshold or its window resetting (PushDeliveryService::
 * check_and_send_quota_pushes()). See those methods for the actual logic;
 * this script is just the timer's entry point.
 *
 * Found live 2026-08-24 (Andres: asked a real question in an Antigravity
 * session, hit "Individual quota reached", never got a push): the quota
 * pass used to feed check_and_send_quota_pushes() only
 * QuotaService::get_quota()['quota'] - the DASHBOARD-WIDE variant of that
 * key is Claude Code's buckets whenever ANY Claude quota data exists at
 * all ('quota' => $claudeLive['quota'] ?? $agLive['quota'] ?? null, see
 * QuotaService::get_quota()'s own dashboard branch), so Antigravity's own
 * buckets - already captured correctly by the antigravity_quota_poll.php
 * timer this whole time - were structurally invisible to this pass
 * whenever a Claude session had ever reported statusline quota, which is
 * effectively always. Fixed by merging BOTH agents' buckets from the same
 * call's 'agents' map before handing them to check_and_send_quota_pushes()
 * - its own bucket-keying is already agent-agnostic (a flat key => bucket
 * map, no special-casing which agent a key came from), and the two agents'
 * real bucket keys never collide ('session'/'week_all' for Claude vs
 * 'gemini-weekly'/'3p-weekly' for Antigravity), so a plain array merge is
 * enough - no dedicated per-agent pass needed.
 *
 * The quota pass has its own kill switch, PUSH_QUOTA_NOTIFICATIONS_ENABLED
 * (see .env.example) - deliberately separate from unsetting VAPID
 * entirely, which would also silence the session-transition pass. Since
 * this is a Type=oneshot service re-spawned fresh by the timer every
 * tick, systemd re-reads EnvironmentFile= (host-agent/.env) on every
 * single run - flipping this to 0 takes effect on the NEXT tick, no
 * restart of anything needed.
 *
 * A no-op (exits 0 immediately) if VAPID keys aren't configured yet, so
 * installing the timer unit before generating keys is harmless.
 */

require __DIR__ . '/lib/Push.php';
require __DIR__ . '/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\PushDeliveryService;
use HostAgent\Services\QuotaService;
use HostAgent\Services\SessionService;

if (!PushDeliveryService::push_configured()) {
    exit(0);
}

// Each pass wrapped independently - found live 2026-08-08 that an
// uncaught error partway through list_all_sessions()/check_and_send_pushes()
// (a real incident: methods referenced mid-edit during unrelated live
// development elsewhere in this same unsandboxed codebase - see this
// project's own CLAUDE.md on that risk) took the ENTIRE script down
// before it ever reached the quota pass below, silently disabling BOTH
// for as long as the crash persisted. \Throwable, not \Exception - the
// actual incident was a \Error ("Call to undefined method ..."), which
// \Exception alone would not have caught.
try {
    $sessions = SessionService::list_all_sessions()['sessions'] ?? [];

    // Merge headless sessions - same transform the 'list' action does
    // (unpack 'blocked' → 'blocked_reason', add 'working' from status).
    foreach (sessioneer_headless_sessions()['headless'] as $h) {
        $blocked = is_array($h['blocked'] ?? null) ? $h['blocked'] : null;
        $sessions[] = [
            'name' => $h['id'],
            'activity' => (int)($h['activity'] ?? 0),
            'attached' => false,
            'pid' => null,
            'workdir' => $h['workdir'],
            'spawned_by_csm' => true,
            'agent' => 'opencode',
            'agent_label' => 'OpenCode',
            'title' => $h['title'],
            'runtime' => $h['runtime'] ?? 'headless',
            'status' => $h['status'],
            'working' => ($h['status'] ?? null) === 'working',
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

    PushDeliveryService::check_and_send_pushes($sessions);
} catch (\Throwable $e) {
    error_log('sessioneer-push-check: session-transition pass crashed - ' . $e->getMessage());
}

if (Config::sessioneer_config('PUSH_QUOTA_NOTIFICATIONS_ENABLED', '1') === '1') {
    try {
        $agents = QuotaService::get_quota()['agents'] ?? [];
        $claudeQuota = is_array($agents['claude']['quota'] ?? null) ? $agents['claude']['quota'] : [];
        $antigravityQuota = is_array($agents['antigravity']['quota'] ?? null) ? $agents['antigravity']['quota'] : [];
        $mergedQuota = $claudeQuota + $antigravityQuota;

        PushDeliveryService::check_and_send_quota_pushes($mergedQuota !== [] ? $mergedQuota : null);
    } catch (\Throwable $e) {
        error_log('sessioneer-push-check: quota pass crashed - ' . $e->getMessage());
    }
}
