<?php
declare(strict_types=1);

/**
 * Pure unit tests for HostAgent\Services\TranscriptService against a
 * hand-crafted fixture JSONL (tests/fixtures/transcript_sample.jsonl) - no
 * tmux, no socket, no real ~/.claude/projects involved. See
 * test_sessions_lifecycle.php and test_agent_client_protocol.php for the
 * tmux/socket-backed suites. Also covers SessionService::session_title()
 * at the bottom - its only real dependency is TranscriptService's own
 * transcript-path/ai-title lookups, so it needs no tmux either.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use HostAgent\Services\Config;
use HostAgent\Services\SessionService;
use HostAgent\Services\TranscriptService;

// Config::home_root() no longer needs stubbing here (it did back when
// home_root() lived in Sessions.php, pulling in that whole file just for
// this one function) - Config is now its own dependency-free, autoloaded
// class, so the real Config::home_root() (still env-overridable via
// HOME_ROOT, same as before) works directly.

const FIXTURE_TRANSCRIPT = __DIR__ . '/fixtures/transcript_sample.jsonl';

// --- TranscriptService::parse_transcript_line(): meta-only, malformed, and content-less
// lines are all skipped (null), everything else renders its blocks ---
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"mode"}'), 'parse_transcript_line: meta-only type -> null');
assert_equal(null, TranscriptService::parse_transcript_line('not valid json{'), 'parse_transcript_line: malformed JSON -> null');
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"system","message":null}'), 'parse_transcript_line: no message -> null');

// --- these four (found by scanning a real, 700+-message transcript - see
// transcript_meta_only_types()'s comment) never carry a `message` key in
// practice, but are listed explicitly for clarity; verified here with
// their real shapes (no "message" key at all, not even null) ---
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"system","subtype":"stop_hook_summary","hookCount":1}'), 'parse_transcript_line: system (stop_hook_summary) -> null');
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"queue-operation","operation":"enqueue","content":"do the thing"}'), 'parse_transcript_line: queue-operation -> null');
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"file-history-snapshot","messageId":"x","snapshot":{}}'), 'parse_transcript_line: file-history-snapshot -> null');
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"file-history-delta","messageId":"x","trackingPath":"a.php"}'), 'parse_transcript_line: file-history-delta -> null');
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"user","message":{"role":"user","content":[]}}'), 'parse_transcript_line: empty content -> null');

$userLine = TranscriptService::parse_transcript_line('{"type":"user","timestamp":"2026-01-01T00:00:00Z","message":{"role":"user","content":"Fix the bug"}}');
assert_equal('user', $userLine['type'] ?? null, 'parse_transcript_line: string content - type');
assert_equal('user', $userLine['role'] ?? null, 'parse_transcript_line: string content - role');
assert_equal([['kind' => 'text', 'text' => 'Fix the bug']], $userLine['blocks'] ?? null, 'parse_transcript_line: bare string content becomes one text block');

$toolUseLine = TranscriptService::parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","name":"Bash"}]}}');
assert_equal([['kind' => 'tool_use', 'text' => 'tool: Bash']], $toolUseLine['blocks'] ?? null, 'parse_transcript_line: tool_use with no input falls back to the bare tool name');

// --- TranscriptService::summarize_tool_use(): "tool: X - key: value, ..." - shows every
// param, not just one primary argument, joined onto a single line when
// short enough (mirrors BlockedPromptView::collapsible_summary()'s own single-line
// threshold - see format_tool_use_summary()) ---
assert_equal('tool: Bash - command: rm -rf /tmp/x, description: Clean up', TranscriptService::summarize_tool_use(['name' => 'Bash', 'input' => ['command' => 'rm -rf /tmp/x', 'description' => 'Clean up']]), 'summarize_tool_use: Bash - command first (primary arg), then every other param');
assert_equal('tool: Read - file_path: /etc/hosts', TranscriptService::summarize_tool_use(['name' => 'Read', 'input' => ['file_path' => '/etc/hosts']]), 'summarize_tool_use: Read - file_path used');
assert_equal('tool: Grep - pattern: TODO', TranscriptService::summarize_tool_use(['name' => 'Grep', 'input' => ['pattern' => 'TODO']]), 'summarize_tool_use: Grep - pattern used');
// NotebookEdit's path argument is named notebook_path, not file_path -
// input order deliberately does NOT have it first, so this only passes if
// notebook_path is itself a recognized primary key (see
// tool_use_primary_arg_keys()), not by accident of already leading the
// input array.
assert_equal(
    'tool: NotebookEdit - notebook_path: /tmp/x.ipynb, cell_id: 3',
    TranscriptService::summarize_tool_use(['name' => 'NotebookEdit', 'input' => ['cell_id' => '3', 'notebook_path' => '/tmp/x.ipynb']]),
    'summarize_tool_use: NotebookEdit - notebook_path leads the summary even though it is not the first input key'
);
assert_equal('tool: Bash', TranscriptService::summarize_tool_use(['name' => 'Bash', 'input' => []]), 'summarize_tool_use: empty input -> bare name');
assert_equal('tool: Bash', TranscriptService::summarize_tool_use(['name' => 'Bash']), 'summarize_tool_use: no input key at all -> bare name');
assert_equal(
    'tool: Weird - foo: bar',
    TranscriptService::summarize_tool_use(['name' => 'Weird', 'input' => ['foo' => 'bar']]),
    'summarize_tool_use: unrecognized shape still shows its params as key: value, not a raw JSON dump'
);

// --- TranscriptService::humanize_tool_name()/TranscriptService::summarize_tool_use(): MCP tool names
// ("mcp__server__tool") are reformatted to "server.tool" - real name taken
// from a live captured transcript entry (an MCP Playwright call). Its
// `element` field (also a real captured shape: {"element": "3. Type
// something. button", "target": "f31e74"}) is a recognized primary arg too.
// Two params pushes this past the single-line length threshold, so it's
// the multi-line "Params:" form - see format_tool_use_summary(). ---
assert_equal('playwright.browser_click', TranscriptService::humanize_tool_name('mcp__playwright__browser_click'), 'humanize_tool_name: strips the mcp__ prefix and joins server/tool with a dot');
assert_equal('Bash', TranscriptService::humanize_tool_name('Bash'), 'humanize_tool_name: a non-MCP name passes through unchanged');
assert_equal(
    "tool: playwright.browser_click\nParams:\n- element: 3. Type something. button\n- target: f31e74",
    TranscriptService::summarize_tool_use(['name' => 'mcp__playwright__browser_click', 'input' => ['element' => '3. Type something. button', 'target' => 'f31e74']]),
    'summarize_tool_use: MCP tool name humanized, element (primary arg) and target both shown'
);

// --- TranscriptService::summarize_tool_use()/summarize_ask_user_question(): AskUserQuestion's
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
    TranscriptService::summarize_tool_use(['name' => 'AskUserQuestion', 'input' => $realAskUserQuestionInput]),
    'summarize_tool_use: AskUserQuestion shows the question and its options, not a raw JSON dump'
);
assert_equal(
    "tool: AskUserQuestion\nParams:\n- Favorite color? (Red / Blue); Favorite animal? (Cat / Dog)",
    TranscriptService::summarize_tool_use(['name' => 'AskUserQuestion', 'input' => ['questions' => [
        ['question' => 'Favorite color?', 'options' => [['label' => 'Red'], ['label' => 'Blue']]],
        ['question' => 'Favorite animal?', 'options' => [['label' => 'Cat'], ['label' => 'Dog']]],
    ]]]),
    'summarize_tool_use: AskUserQuestion with multiple questions joins them with "; "'
);
assert_equal(
    'tool: AskUserQuestion - questions: []',
    TranscriptService::summarize_tool_use(['name' => 'AskUserQuestion', 'input' => ['questions' => []]]),
    'summarize_tool_use: AskUserQuestion with an empty/unrecognized questions shape falls back to showing the raw param'
);

// --- TranscriptService::summarize_tool_use()/summarize_agent_tool_use(): a subagent launch
// (Claude Code's "Agent" tool - real name verified live 2026-08-02, not
// "Task") shows "<subagent_type>: <description>" instead of dumping
// description/prompt/subagent_type/run_in_background as separate params -
// the full prompt text especially would otherwise be noisy/unreadable. ---
assert_equal(
    'tool: Agent - general-purpose: Reply with pineapple',
    TranscriptService::summarize_tool_use(['name' => 'Agent', 'input' => ['description' => 'Reply with pineapple', 'prompt' => 'Reply with exactly the single word: pineapple. Do nothing else.', 'subagent_type' => 'general-purpose', 'run_in_background' => false]]),
    'summarize_tool_use: Agent shows subagent_type + description, not every param'
);
assert_equal(
    'tool: Agent - Reply with pineapple',
    TranscriptService::summarize_tool_use(['name' => 'Agent', 'input' => ['description' => 'Reply with pineapple']]),
    'summarize_tool_use: Agent with only description (no subagent_type) still shows something readable'
);
assert_equal(
    'tool: Agent - general-purpose:',
    TranscriptService::summarize_tool_use(['name' => 'Agent', 'input' => ['subagent_type' => 'general-purpose']]),
    'summarize_tool_use: Agent with only subagent_type (no description) still shows something readable'
);
assert_true(
    str_starts_with(TranscriptService::summarize_tool_use(['name' => 'Agent', 'input' => ['foo' => 'bar']]), 'tool: Agent - foo: bar'),
    'summarize_tool_use: Agent with neither description nor subagent_type falls back to the generic param dump'
);

// --- TranscriptService::parse_transcript_line(): a real captured Agent tool_use gets an
// agent_type field (read straight from its own input.subagent_type) so
// session.php can color/collapse it as a distinct "subagent" entry kind
// instead of a generic tool call - see entry_color_kind(). ---
$agentToolUseLine = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'assistant',
    'message' => ['role' => 'assistant', 'content' => [[
        'type' => 'tool_use',
        'name' => 'Agent',
        'input' => ['description' => 'Reply with pineapple', 'prompt' => 'Reply with exactly the single word: pineapple. Do nothing else.', 'subagent_type' => 'general-purpose', 'run_in_background' => false],
    ]]],
]));
assert_equal('tool: Agent - general-purpose: Reply with pineapple', $agentToolUseLine['blocks'][0]['text'] ?? null, 'parse_transcript_line: Agent tool_use gets the clean subagent summary');
assert_equal('general-purpose', $agentToolUseLine['blocks'][0]['agent_type'] ?? null, 'parse_transcript_line: Agent tool_use block carries agent_type from its own input');

$nonAgentToolUseLine = TranscriptService::parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","name":"Bash","input":{"command":"ls"}}]}}');
assert_true(!array_key_exists('agent_type', $nonAgentToolUseLine['blocks'][0]), 'parse_transcript_line: a plain (non-Agent) tool_use never gets an agent_type field');

// --- TranscriptService::parse_transcript_line(): the Agent tool's own tool_result - real
// shape captured live 2026-08-02 against an actual subagent call. Two
// real findings baked in: (1) the second text block ("agentId: ...
// <usage>...</usage>") is pure internal bookkeeping the tool's own
// instructions say must never be shown - stripped from the rendered text
// entirely, not joined in as if it were more of the real output. (2) the
// agent_type this block gets comes from the OUTER JSONL line's
// toolUseResult.agentType field, not from anything inside the tool_result
// content itself, which has no agent-type info of its own. ---
$agentToolResultLine = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [[
        'type' => 'tool_result',
        'tool_use_id' => 'toolu_01C8mWxDwmtpqW39rfHT28WV',
        'content' => [
            ['type' => 'text', 'text' => 'pineapple'],
            ['type' => 'text', 'text' => "agentId: aba497819de523c24 (use SendMessage with to: 'aba497819de523c24', summary: '<5-10 word recap>' to continue this agent)\n<usage>subagent_tokens: 26059\ntool_uses: 0\nduration_ms: 2018</usage>"],
        ],
    ]]],
    'toolUseResult' => ['status' => 'completed', 'agentId' => 'aba497819de523c24', 'agentType' => 'general-purpose'],
]));
assert_equal('pineapple', $agentToolResultLine['blocks'][0]['text'] ?? null, 'parse_transcript_line: Agent tool_result text is just the real output, the internal agentId/usage metadata block is stripped');
assert_equal('general-purpose', $agentToolResultLine['blocks'][0]['agent_type'] ?? null, 'parse_transcript_line: Agent tool_result gets agent_type from the outer toolUseResult field');

$nonAgentToolResultLine = TranscriptService::parse_transcript_line('{"type":"user","message":{"role":"user","content":[{"type":"tool_result","content":[{"type":"text","text":"file1\nfile2"}]}]}}');
assert_true(!array_key_exists('agent_type', $nonAgentToolResultLine['blocks'][0]), 'parse_transcript_line: a plain (non-subagent) tool_result never gets an agent_type field');

// --- TranscriptService::summarize_content_block()/parse_transcript_line(): ExitPlanMode
// gets its own 'plan' block kind entirely, not the generic "tool: X -
// key: value" summary - the real plan content shown in full, like a real
// message, since Claude explicitly stops and asks for review on it. ---
$planBlock = TranscriptService::summarize_content_block(['type' => 'tool_use', 'name' => 'ExitPlanMode', 'input' => ['plan' => "# Do the thing\n\nSome details."]]);
assert_equal(['kind' => 'plan', 'text' => "# Do the thing\n\nSome details."], $planBlock, 'summarize_content_block: ExitPlanMode tool_use becomes a plan block, full text, not a param-dump summary');

$emptyPlanBlock = TranscriptService::summarize_content_block(['type' => 'tool_use', 'name' => 'ExitPlanMode', 'input' => ['plan' => '   ']]);
assert_equal('tool_use', $emptyPlanBlock['kind'], 'summarize_content_block: ExitPlanMode with a blank/whitespace-only plan falls back to the generic tool_use summary instead of an empty plan block');

$planLine = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'assistant',
    'message' => ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_plan1', 'name' => 'ExitPlanMode', 'input' => ['plan' => "# Refactor the thing\n\nDetails here."]]]],
]));
assert_equal([['kind' => 'plan', 'text' => "# Refactor the thing\n\nDetails here."]], $planLine['blocks'] ?? null, 'parse_transcript_line: a real ExitPlanMode tool_use line parses to a single plan block');

// --- TranscriptService::parse_transcript_line(): an APPROVED plan is
// self-identifying via its own outer toolUseResult ({"plan": "..."} - a
// distinctive shape, verified live 2026-08-07 against real captured
// transcripts) - no id cross-reference needed. The verbose "## Approved
// Plan:\n<plan again>" boilerplate is replaced with a short, clean line -
// the real plan text is already fully visible just above as its own
// 'plan'-kind block, showing it a second time in full would be pure
// noise. ---
$approvedPlanLine = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_plan1', 'content' => "User has approved your plan...\n\n## Approved Plan:\n# Refactor the thing"]]],
    'toolUseResult' => ['plan' => "# Refactor the thing\n\nDetails here."],
]));
assert_equal('approved', $approvedPlanLine['blocks'][0]['plan_status'] ?? null, 'parse_transcript_line: an approved plan tool_result gets plan_status=approved');
assert_equal('Plan approved - starting work', $approvedPlanLine['blocks'][0]['text'] ?? null, 'parse_transcript_line: an approved plan tool_result gets a short, clean text instead of the verbose re-dumped-plan boilerplate');

// --- TranscriptService::find_exit_plan_mode_tool_use_ids()/parse_transcript_line(): a
// REJECTED plan is NOT self-identifying - "User rejected tool use" is the
// exact same generic outer toolUseResult for ANY rejected tool (verified
// live 2026-08-07: identical string for rejected Bash/Write/Edit calls
// too), so recognizing "this rejection was specifically a plan" requires
// cross-referencing tool_use_id against a real ExitPlanMode tool_use seen
// elsewhere in the transcript - see find_exit_plan_mode_tool_use_ids(). ---
$rejectedPlanIds = TranscriptService::find_exit_plan_mode_tool_use_ids([
    json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_plan2', 'name' => 'ExitPlanMode', 'input' => ['plan' => '# A plan']]]]]),
    json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_bash1', 'name' => 'Bash', 'input' => ['command' => 'ls']]]]]),
]);
assert_equal(['toolu_plan2' => true], $rejectedPlanIds, 'find_exit_plan_mode_tool_use_ids: finds only the real ExitPlanMode tool_use id, not the unrelated Bash one');

$rejectedPlanLine = TranscriptService::parse_transcript_line(
    json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_plan2', 'is_error' => true, 'content' => "The user doesn't want to proceed with this tool use..."]]], 'toolUseResult' => 'User rejected tool use']),
    $rejectedPlanIds
);
assert_equal('rejected', $rejectedPlanLine['blocks'][0]['plan_status'] ?? null, 'parse_transcript_line: a rejected plan tool_result (id found in the pre-scanned map) gets plan_status=rejected');
assert_equal('Plan not approved', $rejectedPlanLine['blocks'][0]['text'] ?? null, 'parse_transcript_line: a rejected plan tool_result gets a short, clean text instead of the internal "STOP what you are doing" boilerplate aimed at Claude');

// A rejected Bash call has the EXACT SAME generic outer toolUseResult
// string as a rejected plan - only the id map (built from find_exit_plan_
// mode_tool_use_ids()) tells them apart. Without a matching id, this must
// NOT be mistaken for a plan rejection.
$rejectedBashLine = TranscriptService::parse_transcript_line(
    json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_bash1', 'is_error' => true, 'content' => "The user doesn't want to proceed with this tool use..."]]], 'toolUseResult' => 'User rejected tool use']),
    $rejectedPlanIds
);
assert_true(!array_key_exists('plan_status', $rejectedBashLine['blocks'][0]), 'parse_transcript_line: a rejected Bash call (same generic toolUseResult string, but its id is NOT in the plan id map) never gets a plan_status');

$toolUseWithCommand = TranscriptService::parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","name":"Bash","input":{"command":"ls -la","description":"List files"}}]}}');
assert_equal([['kind' => 'tool_use', 'text' => 'tool: Bash - command: ls -la, description: List files']], $toolUseWithCommand['blocks'] ?? null, 'parse_transcript_line: tool_use with a command shows it, not just "Bash"');

$toolResultLine = TranscriptService::parse_transcript_line('{"type":"user","message":{"role":"user","content":[{"type":"tool_result","content":[{"type":"text","text":"file1\nfile2"}]}]}}');
assert_equal([['kind' => 'tool_result', 'text' => "file1\nfile2"]], $toolResultLine['blocks'] ?? null, 'parse_transcript_line: tool_result content flattened to text');

// --- images: a real captured shape (a browser-automation screenshot tool
// result) has BOTH a text block and an image block side by side in the
// same tool_result's content array - the image used to be silently
// dropped entirely (summarize_content_block()'s default case: kind kept,
// text always empty), now carried through as an extra "image" field
// alongside the text summary. ---
$fakeImageData = base64_encode('not a real png, just fixture bytes');
$toolResultWithImage = TranscriptService::parse_transcript_line(json_encode([
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
$topLevelImage = TranscriptService::parse_transcript_line(json_encode([
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
$hugeImage = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => str_repeat('x', 8_000_001)]],
    ]],
]));
assert_equal(null, $hugeImage['blocks'][0]['image'] ?? null, 'parse_transcript_line: an oversized image is not embedded');
assert_equal('(image could not be displayed)', $hugeImage['blocks'][0]['text'] ?? null, 'parse_transcript_line: an oversized image falls back to a plain text note instead');

$longLine = TranscriptService::parse_transcript_line(json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => str_repeat('x', 52000)]]]]));
assert_true(strlen($longLine['blocks'][0]['text']) < 50100, 'parse_transcript_line: text block beyond the hard cap is truncated');
assert_true(str_ends_with($longLine['blocks'][0]['text'], '(truncated)'), 'parse_transcript_line: truncated block is marked as such');

$normalLongLine = TranscriptService::parse_transcript_line(json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => str_repeat('y', 10000)]]]]));
assert_equal(10000, strlen($normalLongLine['blocks'][0]['text']), 'parse_transcript_line: a normal-sized long block (well under the hard cap) is kept in full, not truncated');

// --- TranscriptService::find_transcript_path(): only matches UUID-shaped ids, globs across
// every project dir under claude_projects_dir() (Config::home_root() . '/.claude/projects') ---
$fakeHome = sys_get_temp_dir() . '/csm-test-transcript-home-' . getmypid();
$uuid = '12345678-1234-4123-8123-123456789012';
@mkdir($fakeHome . '/.claude/projects/-some-project', 0700, true);
file_put_contents($fakeHome . '/.claude/projects/-some-project/' . $uuid . '.jsonl', "{}\n");
putenv("HOME_ROOT={$fakeHome}");

assert_equal(
    $fakeHome . '/.claude/projects/-some-project/' . $uuid . '.jsonl',
    TranscriptService::find_transcript_path($uuid),
    'find_transcript_path: finds the file by globbing across project dirs'
);
assert_equal(null, TranscriptService::find_transcript_path('not-a-uuid'), 'find_transcript_path: rejects a non-UUID-shaped id before touching the filesystem');
assert_equal(null, TranscriptService::find_transcript_path('00000000-0000-4000-8000-000000000000'), 'find_transcript_path: well-formed but nonexistent UUID -> null');

@unlink($fakeHome . '/.claude/projects/-some-project/' . $uuid . '.jsonl');
@rmdir($fakeHome . '/.claude/projects/-some-project');
@rmdir($fakeHome . '/.claude/projects');
@rmdir($fakeHome . '/.claude');
@rmdir($fakeHome);
putenv('HOME_ROOT');

// --- TranscriptService::parse_transcript_line(): a message that's only a thinking block
// (the common shape - Claude Code writes thinking as its own separate
// JSONL line) is treated the same as a meta-only line, not an empty
// bubble with a role header and nothing in it. ---
assert_equal(null, TranscriptService::parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"thinking","thinking":"hmm"}]}}'), 'parse_transcript_line: thinking-only message -> null, not an empty entry');

$mixedLine = TranscriptService::parse_transcript_line('{"type":"assistant","message":{"role":"assistant","content":[{"type":"thinking","thinking":"hmm"},{"type":"text","text":"Done."}]}}');
assert_equal([['kind' => 'text', 'text' => 'Done.']], $mixedLine['blocks'] ?? null, 'parse_transcript_line: thinking alongside real content is dropped, the rest is kept');

// --- TranscriptService::read_transcript_page(): pagination over the real fixture file ---
// 10 raw lines; renderable ones are at raw line numbers 2,3,4,5,8
// (mode/permission-mode/malformed/null-message are meta/invalid, and line
// 7's thinking-only message is dropped per the above) - see the fixture
// file for the full content.
$page1 = TranscriptService::read_transcript_page(FIXTURE_TRANSCRIPT, null, 2);
assert_true($page1['ok'] ?? false, 'read_transcript_page: page1 ok=true');
assert_equal(2, count($page1['entries'] ?? []), 'read_transcript_page: page1 has 2 entries');
assert_equal(['tool_result', 'text'], array_map(fn($e) => $e['blocks'][0]['kind'], $page1['entries']), 'read_transcript_page: page1 is lines 5 and 8 - the thinking-only line 7 is skipped for free, not counted against the page');
assert_equal(5, $page1['next_before'] ?? null, 'read_transcript_page: page1 next_before points just before line 5');
assert_true($page1['has_more'] ?? false, 'read_transcript_page: page1 has_more=true');

$page2 = TranscriptService::read_transcript_page(FIXTURE_TRANSCRIPT, $page1['next_before'], 2);
assert_equal(['text', 'tool_use'], array_map(fn($e) => $e['blocks'][0]['kind'], $page2['entries']), 'read_transcript_page: page2 is lines 3-4 (text, tool_use)');
assert_equal(3, $page2['next_before'] ?? null, 'read_transcript_page: page2 next_before points just before line 3');
assert_true($page2['has_more'] ?? false, 'read_transcript_page: page2 has_more=true');

// Line 1 is meta-only ("mode") - the walk consumes it for free while
// filling this last page, landing on has_more=false directly rather than
// leaving a dangling page with nothing in it.
$page3 = TranscriptService::read_transcript_page(FIXTURE_TRANSCRIPT, $page2['next_before'], 2);
assert_equal(['text'], array_map(fn($e) => $e['blocks'][0]['kind'], $page3['entries']), 'read_transcript_page: page3 is just line 2 (the original user message)');
assert_equal('Fix the bug', $page3['entries'][0]['blocks'][0]['text'] ?? null, 'read_transcript_page: page3 entry is the original user message');
assert_equal(null, $page3['next_before'], 'read_transcript_page: page3 next_before is null (reached the start of the file)');
assert_equal(false, $page3['has_more'] ?? null, 'read_transcript_page: page3 has_more=false');

$missing = TranscriptService::read_transcript_page('/does/not/exist.jsonl', null, 10);
assert_equal(false, $missing['ok'] ?? null, 'read_transcript_page: missing file -> ok=false');

// --- TranscriptService::read_transcript_page_since(): the regular-poll
// counterpart - reads FORWARD from just after a given line, oldest-first
// (no reversal needed, unlike read_transcript_page()'s backward walk) -
// renderable lines in the fixture are 2, 3, 4, 5, 8 (see the file itself:
// 1/6 are meta, 7 is thinking-only, 9 is malformed, 10 has no message). ---
$since2 = TranscriptService::read_transcript_page_since(FIXTURE_TRANSCRIPT, 2, 10);
assert_true($since2['ok'] ?? false, 'read_transcript_page_since: ok=true');
assert_equal([3, 4, 5, 8], array_column($since2['entries'], 'line'), 'read_transcript_page_since: after line 2, every renderable line that follows it comes back in ascending order');

$since5 = TranscriptService::read_transcript_page_since(FIXTURE_TRANSCRIPT, 5, 10);
assert_equal([8], array_column($since5['entries'], 'line'), 'read_transcript_page_since: after line 5, only line 8 remains');

$since8 = TranscriptService::read_transcript_page_since(FIXTURE_TRANSCRIPT, 8, 10);
assert_equal([], $since8['entries'], 'read_transcript_page_since: after the last renderable line, nothing comes back');

$since0Capped = TranscriptService::read_transcript_page_since(FIXTURE_TRANSCRIPT, 0, 2);
assert_equal([2, 3], array_column($since0Capped['entries'], 'line'), 'read_transcript_page_since: $limit caps how many entries come back even when more exist further in the file');

$sinceMissing = TranscriptService::read_transcript_page_since('/does/not/exist.jsonl', 0, 10);
assert_equal(false, $sinceMissing['ok'] ?? null, 'read_transcript_page_since: missing file -> ok=false');

// --- TranscriptService::transcript_attachments_from_tool_use_result()/
// parse_transcript_line(): a SendUserFile tool_result's real file
// metadata (path, size, isImage, media_type, file_uuid) lives on the
// outer JSONL line's toolUseResult.attachments field, not in the content
// blocks themselves (verified live 2026-08-04 against a real captured
// SendUserFile call) - same "outer field, threaded onto the tool_result
// block" pattern as agent_type above, just a different field. The real
// host path is deliberately dropped from the rendered shape (never sent
// to the browser) in favor of just a filename derived from it. ---
assert_equal([], TranscriptService::transcript_attachments_from_tool_use_result(null), 'transcript_attachments_from_tool_use_result: not an array -> []');
assert_equal([], TranscriptService::transcript_attachments_from_tool_use_result(['status' => 'completed']), 'transcript_attachments_from_tool_use_result: no attachments field -> []');
assert_equal(
    [['file_uuid' => 'abc-123', 'filename' => 'footer-before.png', 'size' => 63182, 'isImage' => true, 'media_type' => 'image/png']],
    TranscriptService::transcript_attachments_from_tool_use_result([
        'attachments' => [['path' => '/tmp/claude-1000/scratchpad/footer-before.png', 'size' => 63182, 'isImage' => true, 'media_type' => 'image/png', 'pathValidated' => true, 'file_uuid' => 'abc-123']],
    ]),
    'transcript_attachments_from_tool_use_result: real path collapsed to its basename, other fields passed through'
);
assert_equal(
    [],
    TranscriptService::transcript_attachments_from_tool_use_result(['attachments' => [['size' => 10, 'isImage' => false]]]),
    'transcript_attachments_from_tool_use_result: an entry missing path/file_uuid is skipped, not half-rendered'
);

$sendUserFileLine = TranscriptService::parse_transcript_line(json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [[
        'type' => 'tool_result',
        'content' => 'Sent 1 file(s) to the user.',
    ]]],
    'toolUseResult' => [
        'attachments' => [['path' => '/tmp/x/report.pdf', 'size' => 4096, 'isImage' => false, 'media_type' => 'application/pdf', 'pathValidated' => true, 'file_uuid' => 'file-uuid-1']],
    ],
]));
assert_equal(
    [['file_uuid' => 'file-uuid-1', 'filename' => 'report.pdf', 'size' => 4096, 'isImage' => false, 'media_type' => 'application/pdf']],
    $sendUserFileLine['blocks'][0]['attachments'] ?? null,
    'parse_transcript_line: SendUserFile tool_result block carries attachments from the outer toolUseResult field'
);

$nonAttachmentToolResultLine = TranscriptService::parse_transcript_line('{"type":"user","message":{"role":"user","content":[{"type":"tool_result","content":"ok"}]}}');
assert_true(!array_key_exists('attachments', $nonAttachmentToolResultLine['blocks'][0]), 'parse_transcript_line: a plain tool_result never gets an attachments field');

// --- TranscriptService::read_attachment(): re-reads a real transcript
// line by number and returns a specific attachment's real file bytes as
// base64 - the browser only ever supplies (session, line, file_uuid),
// never a real path, so this re-derives the path itself from the
// transcript rather than trusting one from the caller. ---
$attachmentFixtureDir = sys_get_temp_dir() . '/csm-test-attachment-' . getmypid();
@mkdir($attachmentFixtureDir, 0700, true);
$attachmentFile = $attachmentFixtureDir . '/hello.txt';
file_put_contents($attachmentFile, 'hello attachment bytes');
$attachmentTranscript = $attachmentFixtureDir . '/transcript.jsonl';
file_put_contents($attachmentTranscript, json_encode([
    'type' => 'user',
    'message' => ['role' => 'user', 'content' => [['type' => 'tool_result', 'content' => 'Sent 1 file(s) to the user.']]],
    'toolUseResult' => ['attachments' => [['path' => $attachmentFile, 'size' => 22, 'isImage' => false, 'media_type' => 'text/plain', 'pathValidated' => true, 'file_uuid' => 'the-uuid']]],
]) . "\n");

$readOk = TranscriptService::read_attachment($attachmentTranscript, 1, 'the-uuid');
assert_true($readOk['ok'] ?? false, 'read_attachment: real line + matching file_uuid -> ok=true');
assert_equal(base64_encode('hello attachment bytes'), $readOk['data'] ?? null, 'read_attachment: returns the real file bytes as base64');
assert_equal('text/plain', $readOk['media_type'] ?? null, 'read_attachment: returns the media_type from the transcript');
assert_equal('hello.txt', $readOk['filename'] ?? null, 'read_attachment: filename derived from the real path\'s basename');
assert_equal(22, $readOk['size'] ?? null, 'read_attachment: size reflects the actual bytes read');

$wrongLine = TranscriptService::read_attachment($attachmentTranscript, 99, 'the-uuid');
assert_equal(false, $wrongLine['ok'] ?? null, 'read_attachment: out-of-range line -> ok=false');

$wrongUuid = TranscriptService::read_attachment($attachmentTranscript, 1, 'not-the-uuid');
assert_equal(false, $wrongUuid['ok'] ?? null, 'read_attachment: file_uuid not found on that line -> ok=false');

@unlink($attachmentFile);
$missingFile = TranscriptService::read_attachment($attachmentTranscript, 1, 'the-uuid');
assert_equal(false, $missingFile['ok'] ?? null, 'read_attachment: attachment path found in transcript but no longer exists on disk -> ok=false');

@unlink($attachmentTranscript);
@rmdir($attachmentFixtureDir);

// --- TranscriptService::find_latest_ai_title(): the primary session-title
// source for the unify-claude-sessions plan (works for a dormant session
// exactly as well as a live one, unlike a live-pane-title scrape) - keeps
// the LATEST ai-title line, since a long conversation can get more than
// one as Claude Code refines it. ---
$aiTitleFixture = sys_get_temp_dir() . '/csm-test-ai-title-' . getmypid() . '.jsonl';
file_put_contents($aiTitleFixture, implode("\n", [
    '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]}}',
    '{"type":"ai-title","aiTitle":"First title","sessionId":"abc"}',
    '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"ok"}]}}',
    '{"type":"ai-title","aiTitle":"Second, more accurate title","sessionId":"abc"}',
]) . "\n");

assert_equal('Second, more accurate title', TranscriptService::find_latest_ai_title($aiTitleFixture), 'find_latest_ai_title: keeps the LATEST ai-title line, not the first');
assert_equal(null, TranscriptService::find_latest_ai_title('/does/not/exist.jsonl'), 'find_latest_ai_title: missing file -> null');

file_put_contents($aiTitleFixture, "{\"type\":\"ai-title\",\"aiTitle\":\"\",\"sessionId\":\"abc\"}\n{\"type\":\"user\",\"message\":{\"role\":\"user\",\"content\":[{\"type\":\"text\",\"text\":\"hi\"}]}}\n");
assert_equal(null, TranscriptService::find_latest_ai_title($aiTitleFixture), 'find_latest_ai_title: a blank aiTitle is not treated as a real title');

@unlink($aiTitleFixture);

// --- SessionService::session_title(): the fallback cascade behind
// build_session_entry()'s title field - ai-title first, then the live
// pane title, then the workdir basename, then the raw session name as
// the one source that's always available (a title should never come back
// blank). Uses the same HOME_ROOT-fixture pattern as find_transcript_path()
// above since this function resolves a real transcript path internally. ---
$titleFakeHome = sys_get_temp_dir() . '/csm-test-session-title-home-' . getmypid();
$titleUuid = '87654321-4321-4321-8321-210987654321';
@mkdir($titleFakeHome . '/.claude/projects/-some-project', 0700, true);
file_put_contents(
    $titleFakeHome . '/.claude/projects/-some-project/' . $titleUuid . '.jsonl',
    '{"type":"ai-title","aiTitle":"Real ai-title wins","sessionId":"' . $titleUuid . '"}' . "\n"
);
putenv("HOME_ROOT={$titleFakeHome}");

assert_equal(
    'Real ai-title wins',
    SessionService::session_title($titleUuid, 'live pane title', '/some/workdir', 'cc-20260101-1200'),
    'session_title: ai-title wins even when a live pane title, workdir, and name are all also available'
);
assert_equal(
    'live pane title',
    SessionService::session_title('00000000-0000-4000-8000-000000000000', 'live pane title', '/some/workdir', 'cc-20260101-1200'),
    'session_title: falls back to the live pane title when no ai-title is found (well-formed but nonexistent session id)'
);
assert_equal(
    'workdir',
    SessionService::session_title(null, null, '/some/path/workdir', 'cc-20260101-1200'),
    'session_title: falls back to the workdir basename when there\'s no claude_session_id or live pane title'
);
assert_equal(
    'cc-20260101-1200',
    SessionService::session_title(null, null, null, 'cc-20260101-1200'),
    'session_title: falls back to the raw session name as the last resort'
);
assert_equal(
    'cc-20260101-1200',
    SessionService::session_title(null, '', '', 'cc-20260101-1200'),
    'session_title: an empty-string live pane title or workdir is treated the same as absent, not shown as a blank title'
);

@unlink($titleFakeHome . '/.claude/projects/-some-project/' . $titleUuid . '.jsonl');
@rmdir($titleFakeHome . '/.claude/projects/-some-project');
@rmdir($titleFakeHome . '/.claude/projects');
@rmdir($titleFakeHome . '/.claude');
@rmdir($titleFakeHome);
putenv('HOME_ROOT');

test_exit();
