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
assert_equal([['kind' => 'tool_use', 'text' => 'tool: Bash']], $toolUseLine['blocks'] ?? null, 'parse_transcript_line: tool_use with no input falls back to the bare tool name');

// --- summarize_tool_use(): "tool: X - key: value, ..." - shows every
// param, not just one primary argument, joined onto a single line when
// short enough (mirrors collapsible_summary()'s own single-line
// threshold - see format_tool_use_summary()) ---
assert_equal('tool: Bash - command: rm -rf /tmp/x, description: Clean up', summarize_tool_use(['name' => 'Bash', 'input' => ['command' => 'rm -rf /tmp/x', 'description' => 'Clean up']]), 'summarize_tool_use: Bash - command first (primary arg), then every other param');
assert_equal('tool: Read - file_path: /etc/hosts', summarize_tool_use(['name' => 'Read', 'input' => ['file_path' => '/etc/hosts']]), 'summarize_tool_use: Read - file_path used');
assert_equal('tool: Grep - pattern: TODO', summarize_tool_use(['name' => 'Grep', 'input' => ['pattern' => 'TODO']]), 'summarize_tool_use: Grep - pattern used');
// NotebookEdit's path argument is named notebook_path, not file_path -
// input order deliberately does NOT have it first, so this only passes if
// notebook_path is itself a recognized primary key (see
// tool_use_primary_arg_keys()), not by accident of already leading the
// input array.
assert_equal(
    'tool: NotebookEdit - notebook_path: /tmp/x.ipynb, cell_id: 3',
    summarize_tool_use(['name' => 'NotebookEdit', 'input' => ['cell_id' => '3', 'notebook_path' => '/tmp/x.ipynb']]),
    'summarize_tool_use: NotebookEdit - notebook_path leads the summary even though it is not the first input key'
);
assert_equal('tool: Bash', summarize_tool_use(['name' => 'Bash', 'input' => []]), 'summarize_tool_use: empty input -> bare name');
assert_equal('tool: Bash', summarize_tool_use(['name' => 'Bash']), 'summarize_tool_use: no input key at all -> bare name');
assert_equal(
    'tool: Weird - foo: bar',
    summarize_tool_use(['name' => 'Weird', 'input' => ['foo' => 'bar']]),
    'summarize_tool_use: unrecognized shape still shows its params as key: value, not a raw JSON dump'
);

// --- humanize_tool_name()/summarize_tool_use(): MCP tool names
// ("mcp__server__tool") are reformatted to "server.tool" - real name taken
// from a live captured transcript entry (an MCP Playwright call). Its
// `element` field (also a real captured shape: {"element": "3. Type
// something. button", "target": "f31e74"}) is a recognized primary arg too.
// Two params pushes this past the single-line length threshold, so it's
// the multi-line "Params:" form - see format_tool_use_summary(). ---
assert_equal('playwright.browser_click', humanize_tool_name('mcp__playwright__browser_click'), 'humanize_tool_name: strips the mcp__ prefix and joins server/tool with a dot');
assert_equal('Bash', humanize_tool_name('Bash'), 'humanize_tool_name: a non-MCP name passes through unchanged');
assert_equal(
    "tool: playwright.browser_click\nParams:\n- element: 3. Type something. button\n- target: f31e74",
    summarize_tool_use(['name' => 'mcp__playwright__browser_click', 'input' => ['element' => '3. Type something. button', 'target' => 'f31e74']]),
    'summarize_tool_use: MCP tool name humanized, element (primary arg) and target both shown'
);

// --- summarize_tool_use()/summarize_ask_user_question(): AskUserQuestion's
// nested questions/options input has no scalar primary key, so it would
// otherwise fall through to an unreadable raw JSON dump - real shape taken
// from a live captured transcript entry. ---
$realAskUserQuestionInput = [
    'questions' => [[
        'header' => 'Stale tab?',
        'multiSelect' => false,
        'question' => 'Does a hard refresh on that tab fix it?',
        'options' => [
            ['label' => 'Yes, fixed after hard refresh', 'description' => 'Confirms it was just a stale page load.'],
            ['label' => 'No, still shows the tmux command after hard refresh', 'description' => "Then it's a real, still-open bug."],
        ],
    ]],
];
assert_equal(
    "tool: AskUserQuestion\nParams:\n- Does a hard refresh on that tab fix it? (Yes, fixed after hard refresh / No, still shows the tmux command after hard refresh)",
    summarize_tool_use(['name' => 'AskUserQuestion', 'input' => $realAskUserQuestionInput]),
    'summarize_tool_use: AskUserQuestion shows the question and its options, not a raw JSON dump'
);
assert_equal(
    "tool: AskUserQuestion\nParams:\n- Favorite color? (Red / Blue); Favorite animal? (Cat / Dog)",
    summarize_tool_use(['name' => 'AskUserQuestion', 'input' => ['questions' => [
        ['question' => 'Favorite color?', 'options' => [['label' => 'Red'], ['label' => 'Blue']]],
        ['question' => 'Favorite animal?', 'options' => [['label' => 'Cat'], ['label' => 'Dog']]],
    ]]]),
    'summarize_tool_use: AskUserQuestion with multiple questions joins them with "; "'
);
assert_equal(
    'tool: AskUserQuestion - questions: []',
    summarize_tool_use(['name' => 'AskUserQuestion', 'input' => ['questions' => []]]),
    'summarize_tool_use: AskUserQuestion with an empty/unrecognized questions shape falls back to showing the raw param'
);

$toolUseWithCommand = parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","name":"Bash","input":{"command":"ls -la","description":"List files"}}]}}');
assert_equal([['kind' => 'tool_use', 'text' => 'tool: Bash - command: ls -la, description: List files']], $toolUseWithCommand['blocks'] ?? null, 'parse_transcript_line: tool_use with a command shows it, not just "Bash"');

$toolResultLine = parse_transcript_line('{"type":"user","message":{"role":"user","content":[{"type":"tool_result","content":[{"type":"text","text":"file1\nfile2"}]}]}}');
assert_equal([['kind' => 'tool_result', 'text' => "file1\nfile2"]], $toolResultLine['blocks'] ?? null, 'parse_transcript_line: tool_result content flattened to text');

// --- images: a real captured shape (a browser-automation screenshot tool
// result) has BOTH a text block and an image block side by side in the
// same tool_result's content array - the image used to be silently
// dropped entirely (summarize_content_block()'s default case: kind kept,
// text always empty), now carried through as an extra "image" field
// alongside the text summary. ---
$fakeImageData = base64_encode('not a real png, just fixture bytes');
$toolResultWithImage = parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [[
        'type' => 'tool_result',
        'content' => [
            ['type' => 'text', 'text' => 'Screenshot taken'],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $fakeImageData]],
        ],
    ]]],
]));
assert_equal('Screenshot taken', $toolResultWithImage['blocks'][0]['text'] ?? null, 'parse_transcript_line: tool_result with an image alongside text still shows the text');
assert_equal(
    ['media_type' => 'image/png', 'data' => $fakeImageData],
    $toolResultWithImage['blocks'][0]['image'] ?? null,
    'parse_transcript_line: tool_result with an image carries it through as an extra field, not silently dropped'
);

// --- a top-level image block (not nested in a tool_result) - e.g. a
// directly-attached/pasted image. ---
$topLevelImage = parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $fakeImageData]],
    ]],
]));
assert_equal(
    ['kind' => 'image', 'text' => '', 'image' => ['media_type' => 'image/jpeg', 'data' => $fakeImageData]],
    $topLevelImage['blocks'][0] ?? null,
    'parse_transcript_line: a top-level (not tool_result-nested) image block is carried through too'
);

// --- a pathologically large image is dropped (not embedded, not crashed
// on) rather than blowing up the page - falls back to a plain text note
// so there's still some visible sign something was there. ---
$hugeImage = parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => str_repeat('x', 8_000_001)]],
    ]],
]));
assert_equal(null, $hugeImage['blocks'][0]['image'] ?? null, 'parse_transcript_line: an oversized image is not embedded');
assert_equal('(image could not be displayed)', $hugeImage['blocks'][0]['text'] ?? null, 'parse_transcript_line: an oversized image falls back to a plain text note instead');

$longLine = parse_transcript_line(json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => str_repeat('x', 52000)]]]]));
assert_true(strlen($longLine['blocks'][0]['text']) < 50100, 'parse_transcript_line: text block beyond the hard cap is truncated');
assert_true(str_ends_with($longLine['blocks'][0]['text'], '(truncated)'), 'parse_transcript_line: truncated block is marked as such');

$normalLongLine = parse_transcript_line(json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => str_repeat('y', 10000)]]]]));
assert_equal(10000, strlen($normalLongLine['blocks'][0]['text']), 'parse_transcript_line: a normal-sized long block (well under the hard cap) is kept in full, not truncated');

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

// --- parse_transcript_line(): a message that's only a thinking block
// (the common shape - Claude Code writes thinking as its own separate
// JSONL line) is treated the same as a meta-only line, not an empty
// bubble with a role header and nothing in it. ---
assert_equal(null, parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"thinking","thinking":"hmm"}]}}'), 'parse_transcript_line: thinking-only message -> null, not an empty entry');

$mixedLine = parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"thinking","thinking":"hmm"},{"type":"text","text":"Done."}]}}');
assert_equal([['kind' => 'text', 'text' => 'Done.']], $mixedLine['blocks'] ?? null, 'parse_transcript_line: thinking alongside real content is dropped, the rest is kept');

// --- read_transcript_page(): pagination over the real fixture file ---
// 10 raw lines; renderable ones are at raw line numbers 2,3,4,5,8
// (mode/permission-mode/malformed/null-message are meta/invalid, and line
// 7's thinking-only message is dropped per the above) - see the fixture
// file for the full content.
$page1 = read_transcript_page(FIXTURE_TRANSCRIPT, null, 2);
assert_true($page1['ok'] ?? false, 'read_transcript_page: page1 ok=true');
assert_equal(2, count($page1['entries'] ?? []), 'read_transcript_page: page1 has 2 entries');
assert_equal(['tool_result', 'text'], array_map(fn($e) => $e['blocks'][0]['kind'], $page1['entries']), 'read_transcript_page: page1 is lines 5 and 8 - the thinking-only line 7 is skipped for free, not counted against the page');
assert_equal(5, $page1['next_before'] ?? null, 'read_transcript_page: page1 next_before points just before line 5');
assert_true($page1['has_more'] ?? false, 'read_transcript_page: page1 has_more=true');

$page2 = read_transcript_page(FIXTURE_TRANSCRIPT, $page1['next_before'], 2);
assert_equal(['text', 'tool_use'], array_map(fn($e) => $e['blocks'][0]['kind'], $page2['entries']), 'read_transcript_page: page2 is lines 3-4 (text, tool_use)');
assert_equal(3, $page2['next_before'] ?? null, 'read_transcript_page: page2 next_before points just before line 3');
assert_true($page2['has_more'] ?? false, 'read_transcript_page: page2 has_more=true');

// Line 1 is meta-only ("mode") - the walk consumes it for free while
// filling this last page, landing on has_more=false directly rather than
// leaving a dangling page with nothing in it.
$page3 = read_transcript_page(FIXTURE_TRANSCRIPT, $page2['next_before'], 2);
assert_equal(['text'], array_map(fn($e) => $e['blocks'][0]['kind'], $page3['entries']), 'read_transcript_page: page3 is just line 2 (the original user message)');
assert_equal('Fix the bug', $page3['entries'][0]['blocks'][0]['text'] ?? null, 'read_transcript_page: page3 entry is the original user message');
assert_equal(null, $page3['next_before'], 'read_transcript_page: page3 next_before is null (reached the start of the file)');
assert_equal(false, $page3['has_more'] ?? null, 'read_transcript_page: page3 has_more=false');

$missing = read_transcript_page('/does/not/exist.jsonl', null, 10);
assert_equal(false, $missing['ok'] ?? null, 'read_transcript_page: missing file -> ok=false');

test_exit();
