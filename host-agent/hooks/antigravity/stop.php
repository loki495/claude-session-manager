#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Registered as Antigravity's Stop hook (see AntigravityHookService and
 * docs/antigravity-adapter-plan.md Phase 3) - fires once the execution
 * loop terminates. Marks the session idle and clears any blocked state,
 * the third leg of the working/blocked/idle inference host-agent/hooks/
 * user_prompt_submit.php's own docblock describes for Claude Code -
 * SessionService::build_session_entry() reads this the same way
 * regardless of which agent governs the session ("blocked" itself isn't
 * populated for Antigravity sessions yet - see pre_tool_use.php's own
 * docblock for why).
 *
 * last_message comes from tailing transcript_full.jsonl's last
 * PLANNER_RESPONSE entry (see find_last_planner_response() below) - the
 * Stop payload itself carries no message-text field, unlike Claude
 * Code's own stop.php, which gets last_assistant_message directly on
 * stdin.
 *
 * decision (string, required per Antigravity's own docs): any value
 * other than "continue" lets the agent actually stop - CSM never wants
 * to force continuation, so this always returns a fixed non-"continue"
 * sentinel ("allow_stop").
 *
 * CSM_SESSION_NAME gate: same convention as every hook script here - this
 * hook fires globally (every agy invocation on the machine), so an
 * untracked session still needs a valid decision returned.
 */

require __DIR__ . '/../../lib/Sessions.php';

use HostAgent\Stores\SessionStatusStore;

/**
 * Same bounded-tail-read pattern TranscriptService::find_latest_ai_title()
 * already uses for Claude Code's own transcript file (see that method's
 * own docblock) - only reads the file's last TAIL_SCAN_BYTES, not the
 * whole thing, since Antigravity's transcript can grow across a whole
 * long session. Takes the LAST PLANNER_RESPONSE entry with real text
 * content - a tool-calls-only turn has content: null (confirmed live,
 * see docs/antigravity-adapter-plan.md), so those are skipped in favor of
 * an earlier or later entry that actually has text.
 */
function antigravity_find_last_planner_response(string $path): ?string
{
    // Same tail-scan window as TranscriptService::AI_TITLE_TAIL_SCAN_BYTES.
    $tailScanBytes = 262144;

    $size = @filesize($path);

    if ($size === false || $size === 0) {
        return null;
    }

    $handle = @fopen($path, 'rb');

    if ($handle === false) {
        return null;
    }

    $tailBytes = min($size, $tailScanBytes);
    fseek($handle, -$tailBytes, SEEK_END);
    $chunk = fread($handle, $tailBytes);
    fclose($handle);

    if ($chunk === false) {
        return null;
    }

    $lastContent = null;

    // The read may start mid-line when $tailBytes < $size - a truncated
    // leading fragment simply fails json_decode below and is skipped,
    // same tolerance TranscriptService's own tail-read methods already have.
    foreach (explode("\n", $chunk) as $line) {
        if (!str_contains($line, 'PLANNER_RESPONSE')) {
            continue;
        }

        $decoded = json_decode($line, true);

        if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'PLANNER_RESPONSE') {
            continue;
        }

        $content = is_string($decoded['content'] ?? null) ? trim($decoded['content']) : '';

        if ($content !== '') {
            $lastContent = $content;
        }
    }

    return $lastContent;
}

$sessionName = getenv('CSM_SESSION_NAME');

if ($sessionName === false || $sessionName === '') {
    echo json_encode(['decision' => 'allow_stop']);
    exit(0);
}

$input = stream_get_contents(STDIN);
$payload = json_decode((string)$input, true);

if (!is_array($payload)) {
    echo json_encode(['decision' => 'allow_stop']);
    exit(0);
}

$fields = ['status' => 'idle', 'blocked' => null];

$transcriptPath = is_string($payload['transcriptPath'] ?? null) ? $payload['transcriptPath'] : null;
$lastMessage = $transcriptPath !== null ? antigravity_find_last_planner_response($transcriptPath) : null;

if ($lastMessage !== null) {
    $fields['last_message'] = $lastMessage;
}

SessionStatusStore::update_status($sessionName, $fields);

echo json_encode(['decision' => 'allow_stop']);
