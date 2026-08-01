<?php
declare(strict_types=1);

/**
 * Pure unit tests for host-agent/lib/Transcript.php against a hand-crafted
 * fixture JSONL (tests/fixtures/transcript_sample.jsonl) - no tmux, no
 * socket, no real ~/.claude/projects involved. See test_sessions_lifecycle.php
 * and test_agent_client_protocol.php for the tmux/socket-backed suites.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Transcript.php';

// home_root() is normally defined in Sessions.php - stubbed here since
// this file only needs claude_projects_dir(), not the rest of Sessions.php.
function home_root(): string
{
    return getenv('HOME_ROOT') ?: '/home/andres';
}

const FIXTURE_TRANSCRIPT = __DIR__ . '/fixtures/transcript_sample.jsonl';

// --- parse_transcript_line(): meta-only, malformed, and content-less
// lines are all skipped (null), everything else renders its blocks ---
assert_equal(null, parse_transcript_line('{"type":"mode"}'), 'parse_transcript_line: meta-only type -> null');
assert_equal(null, parse_transcript_line('not valid json{'), 'parse_transcript_line: malformed JSON -> null');
assert_equal(null, parse_transcript_line('{"type":"system","message":null}'), 'parse_transcript_line: no message -> null');

// --- these four (found by scanning a real, 700+-message transcript - see
// transcript_meta_only_types()'s comment) never carry a `message` key in
// practice, but are listed explicitly for clarity; verified here with
// their real shapes (no "message" key at all, not even null) ---
assert_equal(null, parse_transcript_line('{"type":"system","subtype":"stop_hook_summary","hookCount":1}'), 'parse_transcript_line: system (stop_hook_summary) -> null');
assert_equal(null, parse_transcript_line('{"type":"queue-operation","operation":"enqueue","content":"do the thing"}'), 'parse_transcript_line: queue-operation -> null');
assert_equal(null, parse_transcript_line('{"type":"file-history-snapshot","messageId":"x","snapshot":{}}'), 'parse_transcript_line: file-history-snapshot -> null');
assert_equal(null, parse_transcript_line('{"type":"file-history-delta","messageId":"x","trackingPath":"a.php"}'), 'parse_transcript_line: file-history-delta -> null');
assert_equal(null, parse_transcript_line('{"type":"user","message":{"role":"user","content":[]}}'), 'parse_transcript_line: empty content -> null');

$userLine = parse_transcript_line('{"type":"user","timestamp":"2026-01-01T00:00:00Z","message":{"role":"user","content":"Fix the bug"}}');
assert_equal('user', $userLine['type'] ?? null, 'parse_transcript_line: string content - type');
assert_equal('user', $userLine['role'] ?? null, 'parse_transcript_line: string content - role');
assert_equal([['kind' => 'text', 'text' => 'Fix the bug']], $userLine['blocks'] ?? null, 'parse_transcript_line: bare string content becomes one text block');

$toolUseLine = parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","name":"Bash"}]}}');
assert_equal([['kind' => 'tool_use', 'text' => 'Bash']], $toolUseLine['blocks'] ?? null, 'parse_transcript_line: tool_use block summarizes to the tool name');

$toolResultLine = parse_transcript_line('{"type":"user","message":{"role":"user","content":[{"type":"tool_result","content":[{"type":"text","text":"file1\nfile2"}]}]}}');
assert_equal([['kind' => 'tool_result', 'text' => "file1\nfile2"]], $toolResultLine['blocks'] ?? null, 'parse_transcript_line: tool_result content flattened to text');

$longLine = parse_transcript_line(json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => str_repeat('x', 3000)]]]]));
assert_true(strlen($longLine['blocks'][0]['text']) < 2100, 'parse_transcript_line: long text block is truncated');
assert_true(str_ends_with($longLine['blocks'][0]['text'], '(truncated)'), 'parse_transcript_line: truncated block is marked as such');

// --- find_transcript_path(): only matches UUID-shaped ids, globs across
// every project dir under claude_projects_dir() (home_root() . '/.claude/projects') ---
$fakeHome = sys_get_temp_dir() . '/csm-test-transcript-home-' . getmypid();
$uuid = '12345678-1234-4123-8123-123456789012';
@mkdir($fakeHome . '/.claude/projects/-some-project', 0700, true);
file_put_contents($fakeHome . '/.claude/projects/-some-project/' . $uuid . '.jsonl', "{}\n");
putenv("HOME_ROOT={$fakeHome}");

assert_equal(
    $fakeHome . '/.claude/projects/-some-project/' . $uuid . '.jsonl',
    find_transcript_path($uuid),
    'find_transcript_path: finds the file by globbing across project dirs'
);
assert_equal(null, find_transcript_path('not-a-uuid'), 'find_transcript_path: rejects a non-UUID-shaped id before touching the filesystem');
assert_equal(null, find_transcript_path('00000000-0000-4000-8000-000000000000'), 'find_transcript_path: well-formed but nonexistent UUID -> null');

@unlink($fakeHome . '/.claude/projects/-some-project/' . $uuid . '.jsonl');
@rmdir($fakeHome . '/.claude/projects/-some-project');
@rmdir($fakeHome . '/.claude/projects');
@rmdir($fakeHome . '/.claude');
@rmdir($fakeHome);
putenv('HOME_ROOT');

// --- read_transcript_page(): pagination over the real fixture file ---
// 10 raw lines; renderable (non-meta/malformed) ones are at raw line
// numbers 2,3,4,5,7,8 (mode/permission-mode/malformed/null-message are
// skipped) - see the fixture file for the full content.
$page1 = read_transcript_page(FIXTURE_TRANSCRIPT, null, 2);
assert_true($page1['ok'] ?? false, 'read_transcript_page: page1 ok=true');
assert_equal(2, count($page1['entries'] ?? []), 'read_transcript_page: page1 has 2 entries');
assert_equal(['thinking', 'text'], array_map(fn($e) => $e['blocks'][0]['kind'], $page1['entries']), 'read_transcript_page: page1 is lines 7-8, oldest-first (thinking, then final text)');
assert_equal(7, $page1['next_before'] ?? null, 'read_transcript_page: page1 next_before points just before line 7');
assert_true($page1['has_more'] ?? false, 'read_transcript_page: page1 has_more=true');

$page2 = read_transcript_page(FIXTURE_TRANSCRIPT, $page1['next_before'], 2);
assert_equal(['tool_use', 'tool_result'], array_map(fn($e) => $e['blocks'][0]['kind'], $page2['entries']), 'read_transcript_page: page2 is lines 4-5 (tool_use, tool_result)');
assert_equal(4, $page2['next_before'] ?? null, 'read_transcript_page: page2 next_before points just before line 4');
assert_true($page2['has_more'] ?? false, 'read_transcript_page: page2 has_more=true');

$page3 = read_transcript_page(FIXTURE_TRANSCRIPT, $page2['next_before'], 2);
assert_equal(['text', 'text'], array_map(fn($e) => $e['blocks'][0]['kind'], $page3['entries']), 'read_transcript_page: page3 is lines 2-3 (both text)');
assert_equal('Fix the bug', $page3['entries'][0]['blocks'][0]['text'] ?? null, 'read_transcript_page: page3 first entry is the original user message');
assert_equal(2, $page3['next_before'] ?? null, 'read_transcript_page: page3 next_before points just before line 2');
assert_true($page3['has_more'] ?? false, 'read_transcript_page: page3 has_more=true (line 1 is still unread)');

// Line 1 is meta-only ("mode") - the next page has nothing renderable left
// but must still report has_more=false rather than looping forever.
$page4 = read_transcript_page(FIXTURE_TRANSCRIPT, $page3['next_before'], 2);
assert_equal([], $page4['entries'] ?? null, 'read_transcript_page: page4 is empty (only a meta-only line remains)');
assert_equal(null, $page4['next_before'], 'read_transcript_page: page4 next_before is null (reached the start of the file)');
assert_equal(false, $page4['has_more'] ?? null, 'read_transcript_page: page4 has_more=false');

$missing = read_transcript_page('/does/not/exist.jsonl', null, 10);
assert_equal(false, $missing['ok'] ?? null, 'read_transcript_page: missing file -> ok=false');

test_exit();
