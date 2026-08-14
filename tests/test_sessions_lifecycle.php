<?php
declare(strict_types=1);

/**
 * Exercises the real create/list/kill/cleanup logic in
 * host-agent/lib/Sessions.php against an isolated tmux socket and the
 * tests/fixtures/fake_claude stand-in (never the real tmux server or the
 * real claude binary - see tests/.env.testing). Calls dispatch_action()'s
 * underlying functions in-process; no socket layer involved here, that's
 * covered by test_agent_client_protocol.php.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\ProcessInspector;
use HostAgent\Services\PromptParser;
use HostAgent\Services\QuotaService;
use HostAgent\Services\SessionService;
use HostAgent\Services\TmuxService;
use HostAgent\Stores\SidecarStore;

const REAL_TMUX_SOCKET = '/tmp/tmux-1000/default';

if (Config::tmux_socket() === REAL_TMUX_SOCKET) {
    fwrite(STDERR, "REFUSING TO RUN: TMUX_SOCKET resolves to the real host socket. Check tests/.env.testing.\n");
    exit(1);
}

/** @var string[] $createdSessions names still possibly running, for the finally-block safety net */
$createdSessions = [];

/** @var resource|null $bareProc a plain (non-tmux) fake claude process, for the finally-block safety net */
$bareProc = null;

/** @var string|null $adhocName a non-cc-* tmux session hosting a fake claude process, for the finally-block safety net */
$adhocName = null;

/** @var string|null $adoptedTestSession a non-cc-* tmux session carrying its own sidecar (simulating an adopted session), for the finally-block safety net */
$adoptedTestSession = null;

/** @var string|null $promptTestSession a cc-* session used to test SessionService::answer_prompt(), for the finally-block safety net */
$promptTestSession = null;

/** @var string|null $sendTestSession a cc-* session used to test SessionService::send_message(), for the finally-block safety net */
$sendTestSession = null;

/** @var string|null $raceSessionA a cc-* session used to test send_message()/answer_prompt_with_text()'s tmux buffer isolation, for the finally-block safety net */
$raceSessionA = null;

/** @var string|null $raceSessionB the second session in the same buffer-isolation test, for the finally-block safety net */
$raceSessionB = null;

/** @var string|null $wrapTestSession a cc-* session used to test TmuxService::tmux_capture_pane()'s line-wrap rejoin, for the finally-block safety net */
$wrapTestSession = null;

/** @var string|null $quotaTestSession a cc-* session used to test QuotaService::quota_from_live_pane(), for the finally-block safety net */
$quotaTestSession = null;

/** @var resource|null $takeOverBareProc a plain (non-tmux) fake claude process used to test take_over_bare_process(), for the finally-block safety net */
$takeOverBareProc = null;

/** @var string|null $markerAdhocName a non-cc-* tmux session used to test take_over_bare_process()'s marker-matched path, for the finally-block safety net */
$markerAdhocName = null;

/** @var string[] $dualBareAdhocNames non-cc-* tmux sessions used to test bare_process_take_over_candidates()'s other-live-bare-process exclusion, for the finally-block safety net */
$dualBareAdhocNames = [];

/**
 * @return array{ok:bool, name:?string, message:string}
 */
function create_and_track(string $workdir, array &$createdSessions): array
{
    $result = SessionService::create_cc_session($workdir);
    $name = null;

    if (preg_match('/Created session (cc-\S+) in/', (string)($result['message'] ?? ''), $m) === 1) {
        $name = $m[1];
        $createdSessions[] = $name;
    }

    return ['ok' => (bool)($result['ok'] ?? false), 'name' => $name, 'message' => (string)($result['message'] ?? '')];
}

function find_session(string $name): ?array
{
    foreach (SessionService::list_all_sessions()['sessions'] as $session) {
        if ($session['name'] === $name) {
            return $session;
        }
    }

    return null;
}

// --- PromptParser::clean_pane_title(): strips Claude Code's animated spinner glyph,
// leaving the short task description it sets via terminal title escapes.
// Both the old Braille-dot spinner (⠂⠐...) and the newer half-circle one
// (◐◑..., found live 2026-08-12 replacing the dots - see
// PromptParser::SPINNER_GLYPH_PATTERN's own doc comment) are covered by
// the same \p{So}-based match, not two special-cased branches - the whole
// point of matching by Unicode category instead of a hardcoded range is
// that a THIRD future spinner style shouldn't need its own test case
// either, only new ones actually worth asserting as a specific regression
// guard get one. ---
assert_equal('Fix login bug', PromptParser::clean_pane_title('⠂ Fix login bug'), 'clean_pane_title: strips a leading spinner glyph (old braille-dot style)');
assert_equal('Fix login bug', PromptParser::clean_pane_title('⠐ Fix login bug'), 'clean_pane_title: strips a different spinner frame (old braille-dot style)');
assert_equal('Fix login bug', PromptParser::clean_pane_title('◐ Fix login bug'), 'clean_pane_title: strips the newer half-circle spinner glyph too');
assert_equal('Fix login bug', PromptParser::clean_pane_title('◑ Fix login bug'), 'clean_pane_title: strips a different half-circle spinner frame');
assert_equal('No spinner here', PromptParser::clean_pane_title('No spinner here'), 'clean_pane_title: leaves a plain title untouched');
assert_equal(null, PromptParser::clean_pane_title(''), 'clean_pane_title: empty title -> null (caller falls back to session name)');
assert_equal(null, PromptParser::clean_pane_title('   '), 'clean_pane_title: whitespace-only title -> null');

// --- PromptParser::pane_title_is_working(): the live "is it doing something right now"
// signal - the same leading spinner glyph PromptParser::clean_pane_title() strips off ---
assert_true(PromptParser::pane_title_is_working('⠂ Fix login bug'), 'pane_title_is_working: true when the spinner glyph is present (old braille-dot style)');
assert_true(PromptParser::pane_title_is_working('⠐ Fix login bug'), 'pane_title_is_working: true for a different spinner frame (old braille-dot style)');
assert_true(PromptParser::pane_title_is_working('◐ Fix login bug'), 'pane_title_is_working: true for the newer half-circle spinner glyph');
assert_true(PromptParser::pane_title_is_working('◑ Fix login bug'), 'pane_title_is_working: true for a different half-circle spinner frame');
assert_equal(false, PromptParser::pane_title_is_working('No spinner here'), 'pane_title_is_working: false for a plain title');
assert_equal(false, PromptParser::pane_title_is_working(''), 'pane_title_is_working: false for an empty title');

// --- PromptParser::detect_blocking_prompt(): flags a session stuck on an interactive
// prompt (folder trust, tool permission, ...) via the leading "❯ N."
// cursor Claude Code renders on the selected option, regardless of the
// exact prompt wording ---
assert_equal(null, PromptParser::detect_blocking_prompt("Just some normal output\nmore output\n"), 'detect_blocking_prompt: plain output -> not blocked');
assert_equal(
    'Do you trust the files in this folder?',
    PromptParser::detect_blocking_prompt("Do you trust the files in this folder?\n\n❯ 1. Yes, proceed\n  2. No, exit\n"),
    'detect_blocking_prompt: finds the question line directly above the choice list'
);
assert_equal(
    'Do you want to proceed?',
    PromptParser::detect_blocking_prompt("Some other line\n\nDo you want to proceed?\n❯ 1. Yes\n  2. No\n"),
    'detect_blocking_prompt: works for the tool-permission prompt shape too (a blank line separates it from unrelated context above, like a real capture)'
);
assert_equal(
    'no question line here',
    PromptParser::detect_blocking_prompt("no question line here\n❯ 1. Yes\n  2. No\n"),
    'detect_blocking_prompt: falls back to the nearest context line, even without a "?", rather than a bare generic message'
);
assert_equal(
    'Waiting on an interactive prompt (permission or trust dialog)',
    PromptParser::detect_blocking_prompt("❯ 1. Yes\n  2. No\n"),
    'detect_blocking_prompt: only falls back to the generic message when there is truly no context above the choices'
);

// --- PromptParser::parse_blocking_prompt(): the fuller parse behind PromptParser::detect_blocking_prompt(),
// also extracting the surrounding context (the actual tool call / command /
// trust-dialog explanation, not just the bare question) and every numbered
// option, so a caller can render real Approve/Deny buttons with enough
// information to decide, not a blind rubber stamp (used by SessionService::answer_prompt()
// below). The two multi-line fixtures below are verbatim tmux capture-pane
// output from a real, live session - a real trust dialog and a real Bash
// permission prompt - captured specifically because the original
// "line ending in ?" heuristic silently failed to find the real trust
// dialog's question (it wraps: the "?" lands mid-line, not at the end of
// any single one). ---
assert_equal(null, PromptParser::parse_blocking_prompt("Just some normal output\nmore output\n"), 'parse_blocking_prompt: plain output -> null');

$realTrustDialog = " Accessing workspace:\n"
    . "\n"
    . " /tmp/csm-prompt-inspect-1122606/scratch\n"
    . "\n"
    . " Quick safety check: Is this a project you created or one you trust? (Like your\n"
    . " own code, a well-known open source project, or work from your team). If not,\n"
    . " take a moment to review what's in this folder first.\n"
    . "\n"
    . " Claude Code'll be able to read, edit, and execute files here.\n"
    . "\n"
    . " Security guide\n"
    . "\n"
    . " ❯ 1. Yes, I trust this folder\n"
    . "   2. No, exit\n"
    . "\n"
    . " Enter to confirm · Esc to cancel\n";
$trustParsed = PromptParser::parse_blocking_prompt($realTrustDialog);
assert_equal(
    "Quick safety check: Is this a project you created or one you trust? (Like your own code, a well-known open source project, or work from your team). If not, take a moment to review what's in this folder first.",
    $trustParsed['question'] ?? null,
    'parse_blocking_prompt: real trust dialog - question is the FULL sentence, wrapped lines merged, not cut off at the first "?"'
);
assert_true(str_contains($trustParsed['context'] ?? '', 'Accessing workspace'), 'parse_blocking_prompt: real trust dialog - context includes the workspace path line');
assert_true(str_contains($trustParsed['context'] ?? '', "Claude Code'll be able to read"), 'parse_blocking_prompt: real trust dialog - context includes the explanation, not just the question');
assert_equal(
    [['number' => 1, 'label' => 'Yes, I trust this folder'], ['number' => 2, 'label' => 'No, exit']],
    $trustParsed['options'] ?? null,
    'parse_blocking_prompt: real trust dialog - both options extracted'
);

$realPermissionPrompt = "● Bash(echo hello-permission-test > /tmp/csm-permission-test.txt)\n"
    . "\n"
    . str_repeat('─', 40) . "\n"
    . " Bash command\n"
    . "\n"
    . "   echo hello-permission-test > /tmp/csm-permission-test.txt\n"
    . "   Write test string to a temp file\n"
    . "\n"
    . " Do you want to proceed?\n"
    . " ❯ 1. Yes\n"
    . "   2. Yes, and always allow access to tmp/ from this project\n"
    . "   3. No\n"
    . "\n"
    . " Esc to cancel · Tab to amend · ctrl+e to explain\n";
$permissionParsed = PromptParser::parse_blocking_prompt($realPermissionPrompt);
assert_equal('Do you want to proceed?', $permissionParsed['question'] ?? null, 'parse_blocking_prompt: real permission prompt - question found directly');
assert_true(str_contains($permissionParsed['context'] ?? '', 'echo hello-permission-test > /tmp/csm-permission-test.txt'), 'parse_blocking_prompt: real permission prompt - context includes the actual command being approved');
assert_true(str_contains($permissionParsed['context'] ?? '', 'Write test string to a temp file'), 'parse_blocking_prompt: real permission prompt - context includes the tool-provided description');
assert_true(!str_contains($permissionParsed['context'] ?? '', str_repeat('─', 40)), 'parse_blocking_prompt: real permission prompt - the purely-decorative separator line is stripped');
assert_equal(
    [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'Yes, and always allow access to tmp/ from this project'], ['number' => 3, 'label' => 'No']],
    $permissionParsed['options'] ?? null,
    'parse_blocking_prompt: real permission prompt - all three options extracted'
);

// --- PromptParser::parse_blocking_prompt(): a command/preview taller than the old fixed
// BLOCKING_PROMPT_CONTEXT_WINDOW (15 lines) used to have its earlier lines
// silently cut off - found live (Andres reported a truncated command).
// Fixed by finding the real top of the block via Claude Code's own "● "
// tool-invocation marker line instead of a fixed window. This script body
// is 20 lines, deliberately more than the window, with the marker at the
// very top. ---
$longCommandLines = [];
for ($i = 1; $i <= 20; $i++) {
    $longCommandLines[] = "  echo \"step {$i}\"";
}
$realLongPermissionPrompt = "● Bash(run a 20-step deploy script)\n"
    . "\n"
    . str_repeat('─', 40) . "\n"
    . " Bash command\n"
    . "\n"
    . implode("\n", $longCommandLines) . "\n"
    . "\n"
    . " Do you want to proceed?\n"
    . " ❯ 1. Yes\n"
    . "   2. No\n";
$longPermissionParsed = PromptParser::parse_blocking_prompt($realLongPermissionPrompt);
assert_true(str_contains($longPermissionParsed['context'] ?? '', 'step 1"'), 'parse_blocking_prompt: a long (>15-line) command preview includes its FIRST line - the old fixed window would have cut this off');
assert_true(str_contains($longPermissionParsed['context'] ?? '', 'step 20"'), 'parse_blocking_prompt: a long command preview also includes its last line');

$parsedNoQuestion = PromptParser::parse_blocking_prompt("no question line here\n❯ 1. Yes\n  2. No\n");
assert_equal(
    [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']],
    $parsedNoQuestion['options'] ?? null,
    'parse_blocking_prompt: options still extracted even when no question line precedes them'
);
assert_equal(false, $parsedNoQuestion['multi_question'] ?? null, 'parse_blocking_prompt: not multi_question when there is no tab bar');

// --- PromptParser::parse_blocking_prompt(): a real, live capture of a multi-question
// AskUserQuestion prompt - a tabbed interface (one tab per question plus
// a trailing Submit tab, cycled with Left/Right - see SessionService::navigate_prompt()),
// where each numbered option is followed by its own indented description
// line and a purely decorative divider precedes the last option. Captured
// specifically because the original option-parsing loop (which stopped
// at the first line that didn't look like a numbered option) silently
// dropped every option after the first real one's description line. ---
$realMultiQuestion = "❯ Use the AskUserQuestion tool right now to ask me two separate questions at\n"
    . "  once, each with 2 short options: question 1 about favorite color (red or\n"
    . "  blue), question 2 about favorite animal (cat or dog).\n"
    . str_repeat('─', 40) . "\n"
    . "←  ☐ Color  ☐ Animal  ✔ Submit  →\n"
    . "\n"
    . "What's your favorite color?\n"
    . "\n"
    . "❯ 1. Red\n"
    . "     Favorite color is red\n"
    . "  2. Blue\n"
    . "     Favorite color is blue\n"
    . "  3. Type something.\n"
    . str_repeat('─', 40) . "\n"
    . "  4. Chat about this\n"
    . "\n"
    . "Enter to select · Tab/Arrow keys to navigate · Esc to cancel\n";
$multiQuestionParsed = PromptParser::parse_blocking_prompt($realMultiQuestion);
assert_equal("What's your favorite color?", $multiQuestionParsed['question'] ?? null, 'parse_blocking_prompt: real multi-question prompt - question for the current tab found');
assert_equal(
    [
        ['number' => 1, 'label' => 'Red'],
        ['number' => 2, 'label' => 'Blue'],
        ['number' => 3, 'label' => 'Type something.'],
        ['number' => 4, 'label' => 'Chat about this'],
    ],
    $multiQuestionParsed['options'] ?? null,
    'parse_blocking_prompt: real multi-question prompt - all four options found despite interleaved description lines and a divider'
);
assert_equal(true, $multiQuestionParsed['multi_question'] ?? null, 'parse_blocking_prompt: real multi-question prompt - tab bar detected');

// --- PromptParser::parse_blocking_prompt(): a real, live capture of a multi-question
// AskUserQuestion's own "Submit" review tab (found live 2026-08-09: Andres
// reported what looked like a redundant second confirmation on this exact
// screen - see this app's session.php, the "Awaiting approval" pending-
// context card immediately followed by a "Waiting on input" card asking
// what read as the same thing again). The nearest-● scan alone finds only
// the LAST answered question's own bullet, silently dropping every
// earlier one (and the "Review your answers"/tab-bar lines) from context -
// this is what made a complete two-question review look like a single,
// already-answered, oddly-repeated mini-prompt instead. ---
$realSubmitReview = " One quiet line in the AI-tooling section linking to the repo.\n"
    . "\n"
    . str_repeat('─', 40) . "\n"
    . "←  ☒ Visibility  ☒ Repo name  ✔ Submit  →\n"
    . "\n"
    . "Review your answers\n"
    . "\n"
    . " ● Repo visibility — your phone number and personal email live in the content fragments (header.md). A public repo gets indexed/cached fast, which is hard to undo.\n"
    . "   → Public, but redact contact info from the repo\n"
    . " ● What should the GitHub repo be named?\n"
    . "   → resume\n"
    . "\n"
    . "Ready to submit your answers?\n"
    . "\n"
    . "❯ 1. Submit answers\n"
    . "  2. Cancel\n";
$submitReviewParsed = PromptParser::parse_blocking_prompt($realSubmitReview);
assert_equal('Ready to submit your answers?', $submitReviewParsed['question'] ?? null, 'parse_blocking_prompt: Submit review tab - question is the final "ready to submit" line');
assert_true(str_contains($submitReviewParsed['context'] ?? '', 'Review your answers'), 'parse_blocking_prompt: Submit review tab - context includes the "Review your answers" header, not just the last bullet');
assert_true(str_contains($submitReviewParsed['context'] ?? '', 'Repo visibility'), 'parse_blocking_prompt: Submit review tab - context includes the FIRST answered question\'s own bullet');
assert_true(str_contains($submitReviewParsed['context'] ?? '', 'Public, but redact contact info from the repo'), 'parse_blocking_prompt: Submit review tab - context includes the first question\'s real answer');
assert_true(str_contains($submitReviewParsed['context'] ?? '', 'What should the GitHub repo be named?'), 'parse_blocking_prompt: Submit review tab - context still includes the last question\'s own bullet too');
assert_true(str_contains($submitReviewParsed['context'] ?? '', "→ resume"), 'parse_blocking_prompt: Submit review tab - context still includes the last question\'s real answer too');
assert_equal(true, $submitReviewParsed['multi_question'] ?? null, 'parse_blocking_prompt: Submit review tab - still detected as multi_question (tab bar pulled into context alongside "Review your answers"), so Prev/Next navigation back to an earlier question stays available');
assert_equal(
    [['number' => 1, 'label' => 'Submit answers'], ['number' => 2, 'label' => 'Cancel']],
    $submitReviewParsed['options'] ?? null,
    'parse_blocking_prompt: Submit review tab - both real options extracted'
);

// --- PromptParser::parse_blocking_prompt(): a real, live capture of a
// RESHOWN single-question tab of a multi-question AskUserQuestion (found
// live 2026-08-09, same investigation as the Submit-review-tab case above -
// navigating back to an earlier tab has no tool-invocation marker of its
// own at all, unlike a fresh permission prompt). The nearest-● scan used
// to walk straight through the decorative separator above the tab bar and
// land on the PRECEDING, unrelated assistant message's own "● " bullet -
// Claude Code prefixes plain assistant TEXT with "● " too, not just tool
// invocations - sweeping a whole unrelated earlier paragraph into context.
// A first attempt at fixing this (stop the scan at ANY decorative
// separator) overcorrected and broke the long-command-preview case above,
// since a genuine tool invocation ALSO uses one internally, between its
// own marker and its own detail box - the real fix only stops at a
// separator once a multi-question tab bar has already been seen below
// it. ---
$realReshowTab = " On the \"built with AI, including the toolchain\" question: I'd do it — lightly. It's not cheeky if it's true and verifiable, and yours is: the resume already has a dedicated AI-tooling section\n"
    . "citing specific things you built (subagents, hooks, claude-session-manager). A one-line link to this actual repo as another concrete example is proof, not a claim.\n"
    . "\n"
    . "One real concern before I create/push a public repo: your phone number and personal email are in the content fragments. A public GitHub repo gets scraped/cached essentially immediately and that's\n"
    . "hard to fully undo. Let me get that sorted with you before I touch GitHub.\n"
    . str_repeat('─', 40) . "\n"
    . "←  ☒ Visibility  ☒ Repo name  ✔ Submit  →\n"
    . "\n"
    . "Repo visibility — your phone number and personal email live in the content fragments (header.md). A public repo gets indexed/cached fast, which is hard to undo.\n"
    . "\n"
    . "❯ 1. Public, but redact contact info from the repo\n"
    . "     Keep phone/email out of the committed fragments, inject real values only at build time.\n"
    . "  2. Public as-is\n"
    . "  3. Private repo\n"
    . "  4. Type something.\n";
$reshowParsed = PromptParser::parse_blocking_prompt($realReshowTab);
assert_true(
    !str_contains($reshowParsed['context'] ?? '', 'built with AI'),
    'parse_blocking_prompt: a reshown tab\'s context does NOT include the unrelated PRECEDING assistant message - it has no marker of its own, and the scan must not cross the separator above the tab bar to go find one'
);
assert_true(
    !str_contains($reshowParsed['context'] ?? '', 'public GitHub repo gets scraped'),
    'parse_blocking_prompt: a reshown tab\'s context does not include ANY of that preceding paragraph, not just its first line'
);
assert_true(str_contains($reshowParsed['context'] ?? '', '←  ☒ Visibility'), 'parse_blocking_prompt: a reshown tab\'s context DOES include its own tab bar');
assert_true(str_contains($reshowParsed['context'] ?? '', 'Repo visibility —'), 'parse_blocking_prompt: a reshown tab\'s context DOES include its own question');
assert_equal(true, $reshowParsed['multi_question'] ?? null, 'parse_blocking_prompt: a reshown tab is still correctly detected as multi_question');

// --- PromptParser::parse_current_mode(): reads Claude Code's own bottom status line -
// mode names and cycle order confirmed live against a real running
// session (Shift+Tab cycles manual -> accept edits -> plan -> auto). ---
assert_equal('manual', PromptParser::parse_current_mode("  andres@work ~\n  \xE2\x8F\xB8 manual mode on \xC2\xB7 \xE2\x86\x90 for agents\n"), 'parse_current_mode: manual');
assert_equal('accept edits', PromptParser::parse_current_mode("  \xE2\x8F\xB5\xE2\x8F\xB5 accept edits on (shift+tab to cycle) \xC2\xB7 \xE2\x86\x90 for agents\n"), 'parse_current_mode: accept edits - the one mode whose status line omits the word "mode" entirely');
assert_equal('plan', PromptParser::parse_current_mode("  \xE2\x8F\xB8 plan mode on (shift+tab to cycle) \xC2\xB7 \xE2\x86\x90 for agents\n"), 'parse_current_mode: plan');
assert_equal('auto', PromptParser::parse_current_mode("  \xE2\x8F\xB5\xE2\x8F\xB5 auto mode on (shift+tab to cycle) \xC2\xB7 \xE2\x86\x90 for agents\n"), 'parse_current_mode: auto');
assert_equal(null, PromptParser::parse_current_mode("Just some normal output\nmore output\n"), 'parse_current_mode: no status line -> null');
assert_equal(null, PromptParser::parse_current_mode($realTrustDialog), 'parse_current_mode: a blocking prompt covering the status line -> null, not a false match');

// --- TmuxService::tmux_attach_hint(): the exact command shown to a human to go answer
// a blocked prompt themselves ---
assert_equal('tmux -S ' . Config::tmux_socket() . ' attach -t cc-example', TmuxService::tmux_attach_hint('cc-example'), 'tmux_attach_hint: uses the configured socket path');

// --- SessionService::generate_uuid_v4(): the id passed to `claude --session-id` at launch ---
$uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
assert_true(preg_match($uuidPattern, SessionService::generate_uuid_v4()) === 1, 'generate_uuid_v4: matches the RFC 4122 v4 shape');
assert_true(SessionService::generate_uuid_v4() !== SessionService::generate_uuid_v4(), 'generate_uuid_v4: two calls produce different ids');

// --- SessionService::browse_dir(): powers the New Session folder browser, walking from
// WWW_ROOT up to (but never past) HOME_ROOT ---
$result = SessionService::browse_dir(Config::www_root());
assert_true($result['ok'] ?? false, 'SessionService::browse_dir(www_root): ok=true');
assert_equal(['.hidden-dir', 'project-a', 'project-b'], $result['dirs'] ?? null, 'SessionService::browse_dir(www_root): includes hidden dirs, sorted');

$result = SessionService::browse_dir(Config::www_root() . '/project-a');
assert_true($result['ok'] ?? false, 'SessionService::browse_dir(project-a): ok=true');
assert_equal(['nested'], $result['dirs'] ?? null, 'SessionService::browse_dir(project-a): lists its one subfolder');
assert_equal(Config::www_root(), $result['parent'] ?? null, 'SessionService::browse_dir(project-a): parent is WWW_ROOT');

$result = SessionService::browse_dir(Config::www_root() . '/project-a/nested');
assert_true($result['ok'] ?? false, 'SessionService::browse_dir(nested): ok=true');
assert_equal([], $result['dirs'] ?? null, 'SessionService::browse_dir(nested): no subfolders');
assert_equal(Config::www_root() . '/project-a', $result['parent'] ?? null, 'SessionService::browse_dir(nested): parent is project-a');

$result = SessionService::browse_dir(Config::home_root());
assert_true($result['ok'] ?? false, 'SessionService::browse_dir(home_root): ok=true');
assert_equal(null, $result['parent'], 'SessionService::browse_dir(home_root): parent is null - can\'t go up further');

$result = SessionService::browse_dir('/etc');
assert_equal(false, $result['ok'] ?? null, 'SessionService::browse_dir(/etc): rejects a path outside home_root');

$result = SessionService::browse_dir(Config::www_root() . '/does-not-exist');
assert_equal(false, $result['ok'] ?? null, 'SessionService::browse_dir(missing dir): rejects a nonexistent path');

// --- SessionService::list_plan_files(): the sidebar's "Plan/handoff
// files" glance - *.md files directly in a session's own cwd (never
// subdirectories), README.md/CLAUDE.md excluded, sorted most-recently-
// modified first. No real tmux pane needed - this only reads the
// sidecar's own recorded workdir, same as UploadService's own
// session_workdir() lookup. ---
assert_equal(false, SessionService::list_plan_files('cc-not-a-real-session')['ok'] ?? null, 'list_plan_files: rejects a session with no sidecar (unknown workdir)');

$planFilesSession = 'cc-test-plan-files-' . getmypid();
$planFilesDir = Config::www_root() . '/project-a';
SidecarStore::write_sidecar($planFilesSession, ['workdir' => $planFilesDir, 'spawned_at' => time()]);

file_put_contents($planFilesDir . '/README.md', 'readme');
file_put_contents($planFilesDir . '/CLAUDE.md', 'claude md');
file_put_contents($planFilesDir . '/notes.txt', 'not markdown');
file_put_contents($planFilesDir . '/nested/deep-plan.md', 'nested - must not appear, non-recursive');
file_put_contents($planFilesDir . '/older-plan.md', 'older');
touch($planFilesDir . '/older-plan.md', time() - 3600);
file_put_contents($planFilesDir . '/PLAN.md', 'newer');
touch($planFilesDir . '/PLAN.md', time());

$planFilesResult = SessionService::list_plan_files($planFilesSession);
assert_true($planFilesResult['ok'] ?? false, 'list_plan_files: ok=true for a session with a real, known workdir');
$planFileNames = array_column($planFilesResult['files'] ?? [], 'name');
assert_equal(['PLAN.md', 'older-plan.md'], $planFileNames, 'list_plan_files: only top-level *.md files, README.md/CLAUDE.md excluded, sorted most-recently-modified first');

$planFileSizes = array_column($planFilesResult['files'] ?? [], 'size', 'name');
assert_equal(strlen('newer'), $planFileSizes['PLAN.md'] ?? null, 'list_plan_files: reports the real file size');

@unlink($planFilesDir . '/README.md');
@unlink($planFilesDir . '/CLAUDE.md');
@unlink($planFilesDir . '/notes.txt');
@unlink($planFilesDir . '/nested/deep-plan.md');
@unlink($planFilesDir . '/older-plan.md');
@unlink($planFilesDir . '/PLAN.md');
SidecarStore::delete_sidecar($planFilesSession);

$missingWorkdirSession = 'cc-test-plan-files-missing-' . getmypid();
SidecarStore::write_sidecar($missingWorkdirSession, ['workdir' => Config::www_root() . '/does-not-exist', 'spawned_at' => time()]);
$missingWorkdirResult = SessionService::list_plan_files($missingWorkdirSession);
assert_true($missingWorkdirResult['ok'] ?? false, 'list_plan_files: ok=true even when the workdir no longer exists on disk');
assert_equal([], $missingWorkdirResult['files'] ?? null, 'list_plan_files: empty list for a missing workdir, not an error');
SidecarStore::delete_sidecar($missingWorkdirSession);

try {
    // --- create ---
    $created = create_and_track(Config::www_root() . '/project-a', $createdSessions);
    assert_true($created['ok'], 'create: ok=true');
    assert_true($created['name'] !== null, 'create: session name parsed from message');
    $name = $created['name'];

    // --- list sees it, sidecar + pid matching worked ---
    $session = $name !== null ? find_session($name) : null;
    assert_true($session !== null, 'list: created session appears');
    assert_equal(Config::www_root() . '/project-a', $session['workdir'] ?? null, 'list: workdir recorded via sidecar');
    assert_true($session['spawned_by_csm'] ?? false, 'list: spawned_by_csm is true');
    assert_true(($session['pid'] ?? null) !== null, 'list: pane process pid matched via argv[0]');
    // fake_claude (behaves like /bin/cat) never sets a terminal title like the
    // real claude CLI does, so its content isn't asserted here - only that
    // SessionService::list_all_sessions() always includes the key. The stripping behavior
    // itself is covered deterministically by the PromptParser::clean_pane_title() checks above.
    assert_true(array_key_exists('title', $session ?? []), 'list: title key present');
    assert_true(preg_match($uuidPattern, (string)($session['claude_session_id'] ?? '')) === 1, 'list: claude_session_id recorded via sidecar, uuid-shaped');

    // --- TmuxService::list_all_tmux_sessions()'s 'activity' field: sourced
    // from #{window_activity}, not tmux's own #{session_activity} - found
    // live 2026-08-08 on this app's own real session (a live, actively-
    // working session's dashboard row looked stale/"detached" - Andres
    // noticed while dogfooding). #{session_activity} does NOT reliably
    // advance from real, continuous pane output (confirmed live: stayed
    // frozen at spawn time for 8+ real hours of heavy use on the actual
    // session that surfaced this), while #{window_activity} does. This
    // needs a REAL tmux session (the bug is real tmux behavior, not
    // something a fake tmux binary could ever exhibit) - reuses $name
    // from the create block above, real fake_claude (behaves like `cat`)
    // pane, real send-keys. ---
    $activityBeforeSend = $session['activity'] ?? null;
    sleep(2); // ensure the next timestamp is actually distinguishable
    if ($name !== null) {
        TmuxService::tmux_run(['send-keys', '-t', $name, 'activity freshness probe', 'Enter']);
    }
    usleep(300000);
    $sessionAfterSend = $name !== null ? find_session($name) : null;
    assert_true(
        $sessionAfterSend !== null && $activityBeforeSend !== null && ($sessionAfterSend['activity'] ?? 0) > $activityBeforeSend,
        'list: activity timestamp actually advances after real pane output, not frozen at session-creation time'
    );

    // --- SessionService::session_detail(): the same re-derived-from-a-live-scan data as one
    // list() row, plus has_transcript - fake_claude never actually writes a
    // real ~/.claude/projects transcript, so has_transcript is expected
    // false here (see test_transcript.php for the file-found path) ---
    $detail = $name !== null ? SessionService::session_detail($name) : ['ok' => false];
    assert_true($detail['ok'] ?? false, 'session_detail: ok=true for a live session');
    assert_equal($session['claude_session_id'] ?? null, $detail['claude_session_id'] ?? null, 'session_detail: same claude_session_id as list()');
    assert_equal(false, $detail['has_transcript'] ?? null, 'session_detail: has_transcript=false (no real transcript file exists for this fixture)');

    $missingDetail = SessionService::session_detail('cc-not-a-real-session');
    assert_equal(false, $missingDetail['ok'] ?? null, 'session_detail: rejects a name that is not currently live');

    // --- SessionService::session_history(): a claude_session_id is recorded, but with no
    // real transcript file behind it (fake_claude doesn't write one) this
    // must fail gracefully, not error out ---
    $history = $name !== null ? SessionService::session_history($name, null, 10) : ['ok' => true];
    assert_equal(false, $history['ok'] ?? null, 'session_history: ok=false when no transcript file exists for a recorded claude_session_id');

    $noSidecarHistory = SessionService::session_history('cc-not-a-real-session', null, 10);
    assert_equal(false, $noSidecarHistory['ok'] ?? null, 'session_history: ok=false for a session with no sidecar at all');

    // --- SessionService::list_archived_dashboard(): excludes whatever's
    // currently tracked (this live session's own claude_session_id),
    // leaving only genuinely dormant transcripts. Real transcript files
    // are needed for this (fake_claude never writes one), so HOME_ROOT is
    // pointed at an isolated fixture dir just for this block - same
    // pattern as test_transcript.php's fakeHome, restored right after. ---
    $archivedFakeHome = sys_get_temp_dir() . '/csm-test-archived-dashboard-home-' . getmypid();
    $liveClaudeSessionId = $session['claude_session_id'] ?? null;
    $archivedUuid = '33333333-3333-4333-8333-333333333333';
    @mkdir($archivedFakeHome . '/.claude/projects/-tracked-project', 0700, true);
    @mkdir($archivedFakeHome . '/.claude/projects/-archived-project', 0700, true);
    file_put_contents(
        $archivedFakeHome . '/.claude/projects/-tracked-project/' . $liveClaudeSessionId . '.jsonl',
        '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]},"cwd":"/some/tracked/path"}' . "\n"
    );
    file_put_contents(
        $archivedFakeHome . '/.claude/projects/-archived-project/' . $archivedUuid . '.jsonl',
        '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]},"cwd":"/some/archived/path"}' . "\n"
    );
    putenv("HOME_ROOT={$archivedFakeHome}");

    $dashboardArchived = SessionService::list_archived_dashboard()['archived'] ?? [];
    $archivedIds = array_column($dashboardArchived, 'claude_session_id');
    assert_true(!in_array($liveClaudeSessionId, $archivedIds, true), 'list_archived_dashboard: the currently-tracked (live) session is excluded');
    assert_true(in_array($archivedUuid, $archivedIds, true), 'list_archived_dashboard: a genuinely dormant transcript is included');

    @unlink($archivedFakeHome . '/.claude/projects/-tracked-project/' . $liveClaudeSessionId . '.jsonl');
    @unlink($archivedFakeHome . '/.claude/projects/-archived-project/' . $archivedUuid . '.jsonl');
    @rmdir($archivedFakeHome . '/.claude/projects/-tracked-project');
    @rmdir($archivedFakeHome . '/.claude/projects/-archived-project');
    @rmdir($archivedFakeHome . '/.claude/projects');
    @rmdir($archivedFakeHome . '/.claude');
    @rmdir($archivedFakeHome);
    putenv('HOME_ROOT');

    // --- reject kill of a name that isn't currently active ---
    $result = SessionService::kill_cc_session('cc-not-a-real-session');
    assert_equal(false, $result['ok'] ?? null, 'kill: rejects a name not in the live whitelist');

    // --- kill ---
    if ($name !== null) {
        $result = SessionService::kill_cc_session($name);
        assert_true($result['ok'] ?? false, 'kill: ok=true');
        $createdSessions = array_values(array_diff($createdSessions, [$name]));

        assert_true(find_session($name) === null, 'kill: session no longer listed');
        assert_true(!file_exists(Config::sidecar_dir() . "/{$name}.json"), 'kill: sidecar file removed');
    }

    // --- SessionService::resume_cc_session(): input validation ---
    $result = SessionService::resume_cc_session('relative/path', '11111111-1111-4111-8111-111111111111');
    assert_equal(false, $result['ok'] ?? null, 'resume: rejects a relative workdir');

    $result = SessionService::resume_cc_session(Config::www_root() . '/project-a', '');
    assert_equal(false, $result['ok'] ?? null, 'resume: rejects an empty claude_session_id');

    // --- resume: happy path, reusing the claude_session_id freed up by the
    // kill just above. fake_claude ignores every arg (see its own header
    // comment) so this exercises the real tmux-spawn + sidecar-write path
    // without needing a real claude binary to actually honor --resume. ---
    $resumeId = $session['claude_session_id'] ?? null;
    assert_true($resumeId !== null, 'resume setup: have a claude_session_id to resume (from the killed session above)');

    $resumed = $resumeId !== null ? SessionService::resume_cc_session(Config::www_root() . '/project-a', (string)$resumeId) : ['ok' => false];
    assert_true($resumed['ok'] ?? false, 'resume: ok=true for a dormant claude_session_id');
    $resumedName = $resumed['name'] ?? null;
    assert_true(is_string($resumedName) && str_starts_with($resumedName, 'cc-'), 'resume: returns the new pane name');

    if (is_string($resumedName)) {
        $createdSessions[] = $resumedName;
    }

    $resumedEntry = is_string($resumedName) ? find_session($resumedName) : null;
    assert_true($resumedEntry !== null, 'resume: the new session appears in list_all_sessions()');
    assert_equal($resumeId, $resumedEntry['claude_session_id'] ?? null, 'resume: sidecar records the resumed claude_session_id, not a freshly generated one');
    assert_equal(Config::www_root() . '/project-a', $resumedEntry['workdir'] ?? null, 'resume: sidecar records the requested workdir');

    // --- resume: refuses a claude_session_id that already has a live pane
    // (the one just resumed above) - the guard against two panes fighting
    // over the same transcript. ---
    $dupResume = $resumeId !== null ? SessionService::resume_cc_session(Config::www_root() . '/project-a', (string)$resumeId) : ['ok' => true];
    assert_equal(false, $dupResume['ok'] ?? null, 'resume: refuses a claude_session_id that already has a live pane');

    if (is_string($resumedName)) {
        SessionService::kill_cc_session($resumedName);
        $createdSessions = array_values(array_diff($createdSessions, [$resumedName]));
    }

    // --- input validation: relative path rejected before touching tmux ---
    $result = SessionService::create_cc_session('relative/path');
    assert_equal(false, $result['ok'] ?? null, 'create: rejects a relative workdir');

    // --- self-healing: the tmux socket's parent directory can vanish
    // entirely (e.g. a host reboot wipes /tmp) since it's addressed via an
    // explicit -S path, which - unlike tmux's own default $TMPDIR/tmux-$UID
    // naming - tmux never auto-creates. TmuxService::tmux_run() must recreate it on
    // demand rather than every command failing until someone notices. ---
    TmuxService::tmux_run(['kill-server']); // empties the isolated test socket dir so it can be removed
    $socketDir = dirname(Config::tmux_socket());
    foreach (glob("{$socketDir}/*") ?: [] as $leftover) {
        @unlink($leftover);
    }
    @rmdir($socketDir);
    assert_true(!is_dir($socketDir), 'self-heal setup: tmux socket dir removed');

    $healed = create_and_track(Config::www_root() . '/project-a', $createdSessions);
    assert_true($healed['ok'], 'create: recreates a missing tmux socket dir and still succeeds');
    if ($healed['name'] !== null) {
        SessionService::kill_cc_session($healed['name']);
        $createdSessions = array_values(array_diff($createdSessions, [$healed['name']]));
    }

    // --- claude binary fails to start: tmux registers the session, then the pane
    // exits immediately since the command doesn't exist - SessionService::create_cc_session()'s
    // post-creation check must catch that and report failure ---
    $originalClaudeBin = Config::claude_bin();
    putenv('CLAUDE_BIN=/definitely/does/not/exist/csm-test-claude-binary');
    $bad = create_and_track(Config::www_root() . '/project-a', $createdSessions);
    putenv("CLAUDE_BIN={$originalClaudeBin}");
    assert_true(!$bad['ok'], 'create: a claude binary that fails to start is reported as failure');

    // --- cleanup respects the (short, test-only) inactivity threshold ---
    $created = create_and_track(Config::www_root() . '/project-b', $createdSessions);
    assert_true($created['ok'], 'cleanup setup: session created');

    sleep(Config::cleanup_threshold_seconds() + 1);

    $result = SessionService::cleanup_inactive_sessions();
    assert_true($result['ok'] ?? false, 'cleanup: ok=true');
    assert_true(
        $created['name'] !== null && in_array($created['name'], $result['killed'] ?? [], true),
        'cleanup: killed the inactive session'
    );
    if ($created['name'] !== null) {
        $createdSessions = array_values(array_diff($createdSessions, [$created['name']]));
    }

    // --- bare processes: a plain (non-tmux) fake claude process must show
    // up in SessionService::list_all_sessions()['bare'] with no tmux_session/title, and
    // SessionService::kill_bare_process() must SIGTERM it directly ---
    $bareCwd = Config::www_root() . '/project-a';
    $bareProc = proc_open([Config::claude_bin()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $barePipes, $bareCwd);
    assert_true(is_resource($bareProc), 'bare setup: spawned a plain (non-tmux) fake claude process');
    $barePid = is_resource($bareProc) ? (proc_get_status($bareProc)['pid'] ?? null) : null;
    usleep(300000); // let /proc reflect the new process's argv/cwd

    $bareEntry = null;
    foreach (SessionService::list_all_sessions()['bare'] as $b) {
        if ($b['pid'] === $barePid) {
            $bareEntry = $b;
            break;
        }
    }
    assert_true($bareEntry !== null, 'list: plain bare process appears in bare[]');
    assert_equal($bareCwd, $bareEntry['cwd'] ?? null, 'list: bare process cwd read via /proc');
    assert_equal(null, $bareEntry['tmux_session'], 'list: plain bare process has no owning tmux session');
    assert_equal(null, $bareEntry['title'], 'list: plain bare process has no title');

    assert_equal(false, SessionService::kill_bare_process(999999)['ok'] ?? null, 'kill_bare_process: rejects a pid that is not a running claude process');

    $killResult = $barePid !== null ? SessionService::kill_bare_process($barePid) : ['ok' => false];
    assert_true($killResult['ok'] ?? false, 'kill_bare_process: ok=true for a plain process');
    usleep(300000);

    $stillThere = false;
    foreach (ProcessInspector::find_claude_processes() as $p) {
        if ($p['pid'] === $barePid) {
            $stillThere = true;
        }
    }
    assert_true(!$stillThere, 'kill_bare_process: plain process no longer found after SIGTERM');

    if (is_resource($bareProc)) {
        proc_close($bareProc);
    }
    $bareProc = null;

    // --- regression, found live 2026-08-08: find_claude_processes() used
    // to require argv[0] to equal Config::claude_bin()'s FULL configured
    // path exactly. Typing a bare `claude` in a terminal (PATH-resolved by
    // the shell, not the full path) gives that process argv[0] "claude"
    // verbatim - a real running session was invisible to this scan (not in
    // bare[], not excluded from the archived list) purely because of how
    // it happened to be typed. Now matched by basename instead. ---
    // bash's `exec -a` sets argv[0] directly (same technique fake_claude
    // itself uses - see tests/fixtures/fake_claude) - simpler and more
    // reliable than depending on proc_open's own PATH-search behavior to
    // simulate a PATH-resolved invocation.
    $bareNameProc = proc_open(
        ['bash', '-c', 'exec -a ' . escapeshellarg(basename(Config::claude_bin())) . ' ' . escapeshellarg(Config::claude_bin())],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $bareNamePipes,
        $bareCwd
    );
    assert_true(is_resource($bareNameProc), 'bare-name setup: spawned a plain process invoked by bare name (PATH-resolved), not the full configured path');
    $bareNamePid = is_resource($bareNameProc) ? (proc_get_status($bareNameProc)['pid'] ?? null) : null;
    usleep(300000);

    $foundBareName = false;
    foreach (ProcessInspector::find_claude_processes() as $p) {
        if ($p['pid'] === $bareNamePid) {
            $foundBareName = true;
        }
    }
    assert_true($foundBareName, 'find_claude_processes: a bare-name (PATH-resolved) invocation is still found, not just the exact-full-path one');

    if (is_resource($bareNameProc)) {
        proc_terminate($bareNameProc);
        proc_close($bareNameProc);
    }

    // --- bare processes: a fake claude process living inside a tmux
    // session this tool doesn't manage (not cc-* prefixed) must be
    // enriched with that session's name and pane title, and
    // SessionService::kill_bare_process() must kill the whole session rather than just
    // SIGTERM the pid ---
    $adhocName = 'csm-test-adhoc-' . getmypid();
    $adhocCwd = Config::www_root() . '/project-b';
    $adhocCreate = TmuxService::tmux_run(['new-session', '-d', '-s', $adhocName, '-c', $adhocCwd, Config::claude_bin()]);
    assert_equal(0, $adhocCreate['exit'], 'bare setup: created an ad-hoc (non-cc-*) tmux session');
    usleep(300000);
    TmuxService::tmux_run(['select-pane', '-t', $adhocName, '-T', 'Adhoc bare title']);
    usleep(100000);

    $adhocEntry = null;
    foreach (SessionService::list_all_sessions()['bare'] as $b) {
        if (($b['cwd'] ?? null) === $adhocCwd) {
            $adhocEntry = $b;
            break;
        }
    }
    assert_true($adhocEntry !== null, "list: ad-hoc tmux session's claude process appears in bare[]");
    assert_equal($adhocName, $adhocEntry['tmux_session'] ?? null, 'list: bare process inside a non-cc-* tmux session reports that session name');
    assert_equal('Adhoc bare title', $adhocEntry['title'] ?? null, 'list: bare process picks up its tmux pane title');

    // --- regression: an adopted sidecar keyed off a real, non-cc-*-named
    // tmux session must survive list_all_sessions()'s own orphan-pruning
    // pass. prune_orphaned_sidecars() used to only be told about cc-*
    // names, so an adopted session's sidecar was deleted as a false
    // "orphan" on the very next dashboard load (see host-agent/hooks/
    // session_start.php's adoption path in commit 9462e25) ---
    SidecarStore::write_sidecar($adhocName, ['workdir' => $adhocCwd, 'spawned_at' => time()]);
    SessionService::list_all_sessions();
    assert_true(SidecarStore::read_sidecar($adhocName) !== null, 'list_all_sessions: an adopted sidecar for a live non-cc-* tmux session is not pruned as an orphan');

    $adhocPid = $adhocEntry['pid'] ?? null;
    $killResult = $adhocPid !== null ? SessionService::kill_bare_process($adhocPid) : ['ok' => false];
    assert_true($killResult['ok'] ?? false, 'kill_bare_process: ok=true for a tmux-hosted bare process');

    $hasSession = TmuxService::tmux_run(['has-session', '-t', $adhocName]);
    assert_true($hasSession['exit'] !== 0, 'kill_bare_process: ad-hoc tmux session no longer exists');
    $adhocName = null;

    // --- SessionService::take_over_bare_process()/take_over_bare_process_with_id():
    // unify-claude-sessions plan's phase 6. Two paths: a cwd-scoped
    // candidate list (nothing killed until a human picks one - a plain,
    // no-tmux bare process can only ever go this way, since there's no
    // pane to read a statusline marker from), and a confident one-click
    // match via the marker (tested separately below). ---
    assert_equal(false, SessionService::take_over_bare_process(999999)['ok'] ?? null, 'take_over_bare_process: rejects a pid that is not a running claude process');

    $takeOverCwd = Config::www_root() . '/project-a';
    $takeOverFakeHome = sys_get_temp_dir() . '/csm-test-take-over-home-' . getmypid();
    @mkdir($takeOverFakeHome . '/.claude/projects/-take-over-project', 0700, true);

    $dormantUuid = '55555555-5555-4555-8555-555555555555';
    file_put_contents(
        $takeOverFakeHome . '/.claude/projects/-take-over-project/' . $dormantUuid . '.jsonl',
        json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]], 'cwd' => $takeOverCwd, 'timestamp' => date('c', time() - 3600)]) . "\n"
    );
    putenv("HOME_ROOT={$takeOverFakeHome}");

    $takeOverBareProc = proc_open([Config::claude_bin()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $takeOverBarePipes, $takeOverCwd);
    assert_true(is_resource($takeOverBareProc), 'take-over setup: spawned a plain (non-tmux) bare process');
    $takeOverBarePid = is_resource($takeOverBareProc) ? (proc_get_status($takeOverBareProc)['pid'] ?? null) : null;
    usleep(300000);

    $resolved = $takeOverBarePid !== null ? SessionService::take_over_bare_process($takeOverBarePid) : ['ok' => false];
    assert_true($resolved['ok'] ?? false, 'take_over_bare_process: ok=true when no confident marker match exists');
    assert_true($resolved['needs_choice'] ?? false, 'take_over_bare_process: needs_choice=true for a no-tmux bare process (no pane to read a marker from)');
    assert_equal($takeOverCwd, $resolved['workdir'] ?? null, 'take_over_bare_process: workdir read via /proc, matches the process\'s real cwd');
    assert_equal([$dormantUuid], array_column($resolved['candidates'] ?? [], 'claude_session_id'), 'take_over_bare_process: candidates scoped to exactly this cwd');
    assert_equal($dormantUuid, $resolved['suggested_claude_session_id'] ?? null, 'take_over_bare_process: suggests the sole candidate for this cwd');

    $stillRunningAfterResolve = false;
    foreach (ProcessInspector::find_claude_processes() as $p) {
        if ($p['pid'] === $takeOverBarePid) {
            $stillRunningAfterResolve = true;
        }
    }
    assert_true($stillRunningAfterResolve, 'take_over_bare_process: the needs_choice path kills nothing - the original process is still running, fully cancelable');

    $confirmed = SessionService::take_over_bare_process_with_id((int)$takeOverBarePid, $takeOverCwd, $dormantUuid);
    assert_true($confirmed['ok'] ?? false, 'take_over_bare_process_with_id: ok=true');
    $takeOverName = $confirmed['name'] ?? null;
    assert_true(is_string($takeOverName) && str_starts_with($takeOverName, 'cc-'), 'take_over_bare_process_with_id: returns the new pane name');

    if (is_string($takeOverName)) {
        $createdSessions[] = $takeOverName;
    }

    usleep(300000);
    $stillRunningAfterConfirm = false;
    foreach (ProcessInspector::find_claude_processes() as $p) {
        if ($p['pid'] === $takeOverBarePid) {
            $stillRunningAfterConfirm = true;
        }
    }
    assert_true(!$stillRunningAfterConfirm, 'take_over_bare_process_with_id: the original bare process was killed');

    $takeOverEntry = is_string($takeOverName) ? find_session($takeOverName) : null;
    assert_true($takeOverEntry !== null, 'take_over_bare_process_with_id: the new session appears in list_all_sessions()');
    assert_equal($dormantUuid, $takeOverEntry['claude_session_id'] ?? null, 'take_over_bare_process_with_id: sidecar records the chosen claude_session_id');
    assert_equal($takeOverCwd, $takeOverEntry['workdir'] ?? null, 'take_over_bare_process_with_id: sidecar records the chosen workdir');

    if (is_string($takeOverName)) {
        SessionService::kill_cc_session($takeOverName);
        $createdSessions = array_values(array_diff($createdSessions, [$takeOverName]));
    }

    if (is_resource($takeOverBareProc)) {
        proc_close($takeOverBareProc);
    }
    $takeOverBareProc = null;

    // --- take_over_bare_process_with_id(): tolerates the pid already
    // having exited on its own between resolve and confirm (a real
    // possibility - some time passes while a human picks from the
    // picker) - the kill step is skipped, but the resume still goes
    // through, reusing the now-free $dormantUuid from above. ---
    $toleranceResult = SessionService::take_over_bare_process_with_id(999999, $takeOverCwd, $dormantUuid);
    assert_true($toleranceResult['ok'] ?? false, 'take_over_bare_process_with_id: still resumes even when the pid is already gone (kill step skipped, tolerated)');
    $toleranceName = $toleranceResult['name'] ?? null;

    if (is_string($toleranceName)) {
        SessionService::kill_cc_session($toleranceName);
    }

    @unlink($takeOverFakeHome . '/.claude/projects/-take-over-project/' . $dormantUuid . '.jsonl');
    @rmdir($takeOverFakeHome . '/.claude/projects/-take-over-project');
    @rmdir($takeOverFakeHome . '/.claude/projects');
    @rmdir($takeOverFakeHome . '/.claude');
    @rmdir($takeOverFakeHome);
    putenv('HOME_ROOT');

    // --- take_over_bare_process(): the confident, one-click path - a
    // statusline marker (StatuslineMarkerService::parse_marker_from_pane())
    // in the bare process's own tmux pane names an exact claude_session_id
    // backed by a real transcript, so nothing needs to be shown to a human
    // at all: kill + resume happen inside this one call. Uses project-b
    // (not project-a, already exercised above) to keep this fixture's cwd
    // uncorrelated with the candidate-list test's own dormant transcript. ---
    $markerCwd = Config::www_root() . '/project-b';
    $markerFakeHome = sys_get_temp_dir() . '/csm-test-take-over-marker-home-' . getmypid();
    @mkdir($markerFakeHome . '/.claude/projects/-marker-project', 0700, true);
    $markerUuid = '77777777-7777-4777-8777-777777777777';
    file_put_contents(
        $markerFakeHome . '/.claude/projects/-marker-project/' . $markerUuid . '.jsonl',
        json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]], 'cwd' => $markerCwd]) . "\n"
    );
    putenv("HOME_ROOT={$markerFakeHome}");

    $markerAdhocName = 'csm-test-marker-adhoc-' . getmypid();
    $markerSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $markerAdhocName, '-c', $markerCwd, Config::claude_bin()]);
    assert_equal(0, $markerSetup['exit'], 'marker take-over setup: created a live non-cc-* tmux session hosting a fake_claude process');
    usleep(300000);

    TmuxService::tmux_run(['send-keys', '-t', $markerAdhocName, 'csm-data:{"session_id":"' . $markerUuid . '"}', 'Enter']);
    usleep(300000);

    $markerBareEntry = null;
    foreach (SessionService::list_all_sessions()['bare'] as $b) {
        if (($b['tmux_session'] ?? null) === $markerAdhocName) {
            $markerBareEntry = $b;
            break;
        }
    }
    assert_true($markerBareEntry !== null, 'marker take-over setup: the fake_claude process is visible as a bare (untracked) process');
    $markerPid = $markerBareEntry['pid'] ?? null;

    $markerTakeOver = $markerPid !== null ? SessionService::take_over_bare_process((int)$markerPid) : ['ok' => false];
    assert_true($markerTakeOver['ok'] ?? false, 'take_over_bare_process: ok=true for a marker-matched bare process');
    assert_true(!($markerTakeOver['needs_choice'] ?? false), 'take_over_bare_process: no picker needed - the marker gave a confident match');
    $markerTakeOverName = $markerTakeOver['name'] ?? null;
    assert_true(is_string($markerTakeOverName) && str_starts_with($markerTakeOverName, 'cc-'), 'take_over_bare_process: returns the new pane name, already resumed');

    $markerHasSession = TmuxService::tmux_run(['has-session', '-t', $markerAdhocName]);
    assert_true($markerHasSession['exit'] !== 0, 'take_over_bare_process: the original ad-hoc tmux session was killed as part of the one-click take-over');

    $markerEntry = is_string($markerTakeOverName) ? find_session($markerTakeOverName) : null;
    assert_true($markerEntry !== null, 'take_over_bare_process: the new session appears in list_all_sessions()');
    assert_equal($markerUuid, $markerEntry['claude_session_id'] ?? null, 'take_over_bare_process: sidecar records the exact claude_session_id read from the statusline marker, not a guess');

    if (is_string($markerTakeOverName)) {
        SessionService::kill_cc_session($markerTakeOverName);
    }

    @unlink($markerFakeHome . '/.claude/projects/-marker-project/' . $markerUuid . '.jsonl');
    @rmdir($markerFakeHome . '/.claude/projects/-marker-project');
    @rmdir($markerFakeHome . '/.claude/projects');
    @rmdir($markerFakeHome . '/.claude');
    @rmdir($markerFakeHome);
    putenv('HOME_ROOT');

    // --- bare_process_take_over_candidates(): excludes another OTHER
    // live (marker-matched) bare process's own session for the same
    // cwd, even though nothing tracks it via a sidecar (Andres's own
    // concern, 2026-08-08) - resume_cc_session()'s already-live guard
    // only checks sidecars, so without this a candidate transcript
    // still being actively written by a different live bare process
    // could get a second pane fighting over it. A genuinely dormant
    // transcript (no live process at all) must still be offered. ---
    $dualBareCwd = Config::www_root() . '/project-a';
    $dualBareFakeHome = sys_get_temp_dir() . '/csm-test-dual-bare-home-' . getmypid();
    @mkdir($dualBareFakeHome . '/.claude/projects/-dual-bare-project', 0700, true);

    $targetUuid = '88888888-8888-4888-8888-888888888888';
    $otherLiveUuid = '99999999-9999-4999-9999-999999999999';
    $genuinelyDormantUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    foreach ([$targetUuid, $otherLiveUuid, $genuinelyDormantUuid] as $i => $uuid) {
        file_put_contents(
            $dualBareFakeHome . '/.claude/projects/-dual-bare-project/' . $uuid . '.jsonl',
            json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]], 'cwd' => $dualBareCwd, 'timestamp' => date('c', time() - 3600 + $i)]) . "\n"
        );
    }
    putenv("HOME_ROOT={$dualBareFakeHome}");

    $targetAdhocName = 'csm-test-dual-bare-target-' . getmypid();
    $otherAdhocName = 'csm-test-dual-bare-other-' . getmypid();
    TmuxService::tmux_run(['new-session', '-d', '-s', $targetAdhocName, '-c', $dualBareCwd, Config::claude_bin()]);
    TmuxService::tmux_run(['new-session', '-d', '-s', $otherAdhocName, '-c', $dualBareCwd, Config::claude_bin()]);
    $dualBareAdhocNames = [$targetAdhocName, $otherAdhocName];
    usleep(300000);

    // Only the OTHER pane gets a marker - the target pane has none,
    // forcing take_over_bare_process() into the needs_choice path for it.
    TmuxService::tmux_run(['send-keys', '-t', $otherAdhocName, 'csm-data:{"session_id":"' . $otherLiveUuid . '"}', 'Enter']);
    usleep(300000);

    $dualBareEntries = [];
    foreach (SessionService::list_all_sessions()['bare'] as $b) {
        if (in_array($b['tmux_session'] ?? null, [$targetAdhocName, $otherAdhocName], true)) {
            $dualBareEntries[$b['tmux_session']] = $b;
        }
    }
    assert_true(isset($dualBareEntries[$targetAdhocName], $dualBareEntries[$otherAdhocName]), 'dual-bare setup: both fake_claude processes are visible as bare');

    $dualBareResolved = SessionService::take_over_bare_process((int)$dualBareEntries[$targetAdhocName]['pid']);
    assert_true($dualBareResolved['needs_choice'] ?? false, 'take_over_bare_process: target pid (no marker of its own) falls to the picker path');
    $dualBareCandidateIds = array_column($dualBareResolved['candidates'] ?? [], 'claude_session_id');
    assert_true(in_array($targetUuid, $dualBareCandidateIds, true), 'bare_process_take_over_candidates: includes the target pid\'s own (dormant, since it has no marker) transcript');
    assert_true(in_array($genuinelyDormantUuid, $dualBareCandidateIds, true), 'bare_process_take_over_candidates: includes a genuinely dormant transcript with no live process at all');
    assert_true(!in_array($otherLiveUuid, $dualBareCandidateIds, true), 'bare_process_take_over_candidates: excludes another BARE process\'s own live session (marker-matched), even though nothing tracks it via a sidecar');

    TmuxService::tmux_run(['kill-session', '-t', $targetAdhocName]);
    TmuxService::tmux_run(['kill-session', '-t', $otherAdhocName]);
    $dualBareAdhocNames = [];

    foreach ([$targetUuid, $otherLiveUuid, $genuinelyDormantUuid] as $uuid) {
        @unlink($dualBareFakeHome . '/.claude/projects/-dual-bare-project/' . $uuid . '.jsonl');
    }
    @rmdir($dualBareFakeHome . '/.claude/projects/-dual-bare-project');
    @rmdir($dualBareFakeHome . '/.claude/projects');
    @rmdir($dualBareFakeHome . '/.claude');
    @rmdir($dualBareFakeHome);
    putenv('HOME_ROOT');

    // --- SessionService::answer_prompt(): sends the chosen option's number + Enter to a
    // live session's pane, exactly like a human attached over tmux would
    // type. fake_claude/cat doesn't understand a real permission prompt,
    // so a raw pane running `cat` with local echo disabled (stty -echo)
    // stands in here - crafted prompt text is typed via send-keys (which
    // cat echoes back exactly once, since nothing else is echoing it),
    // giving PromptParser::parse_blocking_prompt() something real to detect via an
    // actual capture-pane call, not a hand-fed string like the pure
    // PromptParser::parse_blocking_prompt() tests above. ---
    $promptTestSession = 'cc-test-answer-prompt-' . getmypid();
    $promptSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $promptTestSession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $promptSetup['exit'], 'answer_prompt setup: created a live cc-* session to answer a prompt in');
    // A raw ad-hoc pane (not made via create_cc_session()) has no sidecar of
    // its own - write one directly so it counts as "tracked" (see
    // TmuxService::list_tracked_tmux_sessions()), same as answer_prompt() et
    // al. require of any session they'll act on.
    SidecarStore::write_sidecar($promptTestSession, ['workdir' => Config::www_root(), 'spawned_at' => time()]);
    usleep(300000);

    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, 'Do you want to proceed?', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '❯ 1. Yes', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '  2. No', 'Enter']);
    usleep(300000);

    assert_equal(
        false,
        SessionService::answer_prompt($promptTestSession, 99)['ok'] ?? null,
        'answer_prompt: rejects an option not currently offered by the prompt'
    );
    assert_equal(
        false,
        SessionService::answer_prompt('cc-not-a-real-session', 1)['ok'] ?? null,
        'answer_prompt: rejects a session name that is not currently live'
    );
    assert_equal(
        false,
        SessionService::navigate_prompt($promptTestSession, 'left')['ok'] ?? null,
        'navigate_prompt: rejects a plain (non-multi-question) prompt'
    );
    assert_equal(
        false,
        SessionService::navigate_prompt($promptTestSession, 'sideways')['ok'] ?? null,
        'navigate_prompt: rejects an invalid direction'
    );

    $answered = SessionService::answer_prompt($promptTestSession, 1);
    assert_true($answered['ok'] ?? false, 'answer_prompt: ok=true for a currently-offered option');
    usleep(300000);

    $paneAfterAnswer = trim(TmuxService::tmux_capture_pane($promptTestSession));
    assert_true(str_ends_with($paneAfterAnswer, '1'), 'answer_prompt: the option number was actually sent into the pane (echoed back by cat)');

    // --- SessionService::navigate_prompt(): accept path, against a tab-bar-shaped pane
    // (same session, fresh content) - verifies the real Left/Right
    // keypress actually reaches the pane, the same way the SessionService::answer_prompt()
    // check above verifies a numbered option does. ---
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, "←  ☐ Color  ☐ Animal  ✔ Submit  →", 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, 'Pick one', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '❯ 1. A', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '  2. B', 'Enter']);
    usleep(300000);

    // Unlike SessionService::answer_prompt()'s digit case, a "Right" arrow keypress is an
    // escape sequence, not a printable byte cat's canonical-mode line
    // buffering would echo back visibly (it also never reaches cat's
    // read() at all without a following Enter to flush the line) -
    // properly proving delivery would mean putting the fixture pty in raw
    // mode to match how a real interactive TUI actually reads input,
    // which is disproportionate here: tmux's own send-keys is a
    // well-established mechanism (already exercised end-to-end by the
    // digit case above), so what actually needs coverage is this
    // function's own validation logic (already tested above: rejects a
    // non-multi-question prompt, rejects an invalid direction) - ok=true
    // confirms it correctly recognized this as answerable and the
    // underlying tmux command didn't error.
    $navigated = SessionService::navigate_prompt($promptTestSession, 'right');
    assert_true($navigated['ok'] ?? false, 'navigate_prompt: ok=true for a live multi-question prompt');

    // --- SessionService::answer_prompt(): for a MULTI-QUESTION prompt specifically,
    // sends ONLY the digit - no trailing Enter (found live 2026-08-09,
    // Andres reported answering a question "skipped" the next one; verified
    // against a real, disposable claude session: the digit alone already
    // selects+confirms+auto-advances on this prompt shape, so this app's
    // old always-send-Enter behavior landed that extra Enter on whatever
    // was showing NEXT and silently confirmed ITS current default too -
    // one real answer, two tabs advanced). Reuses the same canonical-mode-
    // cat fixture reasoning as the navigate_prompt comment above: a plain
    // digit byte with no following Enter never completes a line, so
    // cat's own read() never sees it and never echoes it back - the pane
    // staying UNCHANGED after answer_prompt() is exactly the signal that
    // no Enter followed it (a regression back to always-Enter would flush
    // the digit and change the pane, same as the single-question case
    // earlier in this file already proves FOR that shape). ---
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, "←  ☐ Color  ☐ Animal  ✔ Submit  →", 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, 'Pick one', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '❯ 1. A', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '  2. B', 'Enter']);
    usleep(300000);
    $paneBeforeMultiAnswer = TmuxService::tmux_capture_pane($promptTestSession);

    $multiAnswered = SessionService::answer_prompt($promptTestSession, 1);
    assert_true($multiAnswered['ok'] ?? false, 'answer_prompt: ok=true for a currently-offered option on a multi-question prompt');
    usleep(300000);

    $paneAfterMultiAnswer = TmuxService::tmux_capture_pane($promptTestSession);
    assert_equal(
        $paneBeforeMultiAnswer,
        $paneAfterMultiAnswer,
        'answer_prompt: for a multi-question prompt, the pane is UNCHANGED after answering - the digit alone never completes a line without a following Enter, proving none was sent'
    );

    // --- SessionService::set_mode(): jumps straight to a chosen mode by working out how
    // many Shift+Tab ("BTab") presses that is from the current mode, read
    // live from the pane. Each press is itself an escape sequence (not a
    // printable byte cat echoes back visibly - same as SessionService::navigate_prompt()'s
    // arrow-key case above), but here the *count* of presses is
    // independently verifiable: seed the pane with a real status line
    // (echoed back by cat) so PromptParser::parse_current_mode() reads a known starting
    // mode, then confirm ok=true for the multi-step jump. ---
    assert_equal(false, SessionService::set_mode($promptTestSession, 'not-a-real-mode')['ok'] ?? null, 'set_mode: rejects an unrecognized mode');
    assert_equal(false, SessionService::set_mode('cc-not-a-real-session', 'plan')['ok'] ?? null, 'set_mode: rejects a session name that is not currently live');
    assert_equal(
        false,
        SessionService::set_mode($promptTestSession, 'plan')['ok'] ?? null,
        'set_mode: rejects when the current mode cannot be read from the pane (no real Claude Code status line here yet)'
    );

    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, 'manual mode on', 'Enter']);
    usleep(300000);
    $modeSet = SessionService::set_mode($promptTestSession, 'auto'); // manual -> auto is 3 steps, the largest possible jump
    assert_true($modeSet['ok'] ?? false, 'set_mode: ok=true once the current mode is readable, for a live session');

    TmuxService::tmux_run(['kill-session', '-t', $promptTestSession]);
    SidecarStore::delete_sidecar($promptTestSession);
    $promptTestSession = null;

    // --- TmuxService::tmux_capture_pane(): a long single logical line (e.g. the command
    // in a permission prompt) that the terminal soft-wraps across several
    // pane rows must come back rejoined into one line, not split mid-word -
    // this is what PromptParser::parse_blocking_prompt() relies on to show the real,
    // complete command rather than a mangled fragment. Verified against a
    // real narrow pane, not a hand-fed string. ---
    $wrapTestSession = 'cc-test-capture-wrap-' . getmypid();
    $wrapSetup = TmuxService::tmux_run(['new-session', '-d', '-x', '60', '-y', '20', '-s', $wrapTestSession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $wrapSetup['exit'], 'tmux_capture_pane wrap test setup: created a narrow live pane');
    usleep(300000);

    $longCommand = "ssh media 'rm /tmp/apply_dashboard.py /tmp/another_very_long_filename_that_will_definitely_wrap_across_the_narrow_pane_width.py'";
    TmuxService::tmux_run(['set-buffer', '--', $longCommand]);
    TmuxService::tmux_run(['paste-buffer', '-t', $wrapTestSession]);
    TmuxService::tmux_run(['send-keys', '-t', $wrapTestSession, 'Enter']);
    usleep(300000);

    assert_true(
        str_contains(TmuxService::tmux_capture_pane($wrapTestSession), $longCommand),
        'tmux_capture_pane: a long line the terminal soft-wrapped across multiple pane rows is rejoined intact (-J), not split mid-word'
    );

    TmuxService::tmux_run(['kill-session', '-t', $wrapTestSession]);
    $wrapTestSession = null;

    // --- SessionService::send_message(): sends free text to a session's pane via tmux
    // paste-buffer + Enter, exactly as if a human had typed it while
    // attached. Verified end-to-end against a real pane: the full
    // multi-line message lands as one block (echoed back by cat), not
    // split into separate premature submits the way send-keys with the
    // raw text would (each embedded newline acting as its own Enter). ---
    $sendTestSession = 'cc-test-send-message-' . getmypid();
    $sendSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $sendTestSession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $sendSetup['exit'], 'send_message setup: created a live cc-* session to send a message to');
    SidecarStore::write_sidecar($sendTestSession, ['workdir' => Config::www_root(), 'spawned_at' => time()]);
    usleep(300000);

    assert_equal(false, SessionService::send_message('cc-not-a-real-session', 'hello')['ok'] ?? null, 'send_message: rejects a session name that is not currently live');
    assert_equal(false, SessionService::send_message($sendTestSession, '   ')['ok'] ?? null, 'send_message: rejects a whitespace-only message');

    $sent = SessionService::send_message($sendTestSession, "Line one\nLine two");
    assert_true($sent['ok'] ?? false, 'send_message: ok=true for a live session');
    usleep(300000);

    $paneAfterSend = TmuxService::tmux_capture_pane($sendTestSession);
    assert_true(
        str_contains($paneAfterSend, 'Line one') && str_contains($paneAfterSend, 'Line two'),
        'send_message: the full multi-line message landed in the pane (echoed back by cat), not split into separate premature submits'
    );

    // --- send_message()'s $attachmentPaths param: compose-bar file uploads
    // still pending when Send is pressed each become their own "[Attached:
    // path]" line, added here (not client-side) so the user's own typed
    // draft never shows that bookkeeping text - see session.js's compose-
    // attachments preview. An attachment with no typed text at all is a
    // valid send on its own. ---
    $sentAttachmentOnly = SessionService::send_message($sendTestSession, '', ['.claude/uploads/report.pdf']);
    assert_true($sentAttachmentOnly['ok'] ?? false, 'send_message: an attachment with no typed text at all is still a valid send');
    usleep(300000);
    assert_contains('[Attached: .claude/uploads/report.pdf]', TmuxService::tmux_capture_pane($sendTestSession), 'send_message: the attachment line lands in the pane even with no typed text');

    $sentWithText = SessionService::send_message($sendTestSession, 'Check this out', ['.claude/uploads/photo.png']);
    assert_true($sentWithText['ok'] ?? false, 'send_message: typed text plus an attachment together is a valid send');
    usleep(300000);
    $paneAfterBoth = TmuxService::tmux_capture_pane($sendTestSession);
    assert_true(
        str_contains($paneAfterBoth, 'Check this out') && str_contains($paneAfterBoth, '[Attached: .claude/uploads/photo.png]'),
        'send_message: typed text and the attachment line both land in the pane, on separate lines'
    );

    TmuxService::tmux_run(['kill-session', '-t', $sendTestSession]);
    SidecarStore::delete_sidecar($sendTestSession);
    $sendTestSession = null;

    // --- send_message()/answer_prompt_with_text() concurrency isolation:
    // found live 2026-08-14 that both used tmux's single SHARED default
    // buffer (a bare `set-buffer`/`paste-buffer` with no -b) - this
    // host-agent handles every request as its OWN separate OS process
    // (systemd socket activation in production; tests/lib/socket_harness.php
    // mirrors that here), all sharing the same tmux server, so two
    // genuinely concurrent sends interleaving as (A sets, B sets - clobbers
    // A's staged text, A pastes, B pastes) would silently paste B's text
    // into A's pane. Reproduced live against two real panes with that exact
    // interleaving before the fix (A received "MESSAGE-FOR-B"); the fix
    // gives every call its own uniquely-named buffer instead.
    //
    // Real OS-level concurrency can't be driven deterministically from here
    // without either flaky timing or a test-only hook inside production
    // code (not doing that) - so this drives the exact worst-case
    // interleaving directly via TmuxService::tmux_run(), using the SAME
    // named-buffer + -d command shape the real fix uses, against two real
    // panes. This proves the TECHNIQUE the fix relies on is actually sound
    // (never flaky - no timing dependency at all) - it does NOT by itself
    // catch a future regression in send_message()/answer_prompt_with_text()
    // themselves, since it never calls them; the source-level check right
    // after this block is what actually guards that. ---
    $raceSessionA = 'cc-test-buffer-race-a-' . getmypid();
    $raceSessionB = 'cc-test-buffer-race-b-' . getmypid();
    TmuxService::tmux_run(['new-session', '-d', '-s', $raceSessionA, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    TmuxService::tmux_run(['new-session', '-d', '-s', $raceSessionB, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    usleep(300000);

    // A stages its own text into its OWN named buffer...
    TmuxService::tmux_run(['set-buffer', '-b', 'csm-race-test-a', '--', 'MESSAGE-FOR-A']);
    // ...B's ENTIRE send (stage, paste, auto-delete via -d) completes fully
    // in between, on its OWN separate named buffer...
    TmuxService::tmux_run(['set-buffer', '-b', 'csm-race-test-b', '--', 'MESSAGE-FOR-B']);
    TmuxService::tmux_run(['paste-buffer', '-d', '-b', 'csm-race-test-b', '-t', $raceSessionB]);
    TmuxService::tmux_run(['send-keys', '-t', $raceSessionB, 'Enter']);
    // ...only THEN does A finally paste from its own still-untouched buffer.
    TmuxService::tmux_run(['paste-buffer', '-d', '-b', 'csm-race-test-a', '-t', $raceSessionA]);
    TmuxService::tmux_run(['send-keys', '-t', $raceSessionA, 'Enter']);
    usleep(300000);

    assert_contains('MESSAGE-FOR-A', TmuxService::tmux_capture_pane($raceSessionA), 'buffer isolation: session A receives its own message even when B\'s entire send completes in between A\'s set and paste');
    assert_true(!str_contains(TmuxService::tmux_capture_pane($raceSessionA), 'MESSAGE-FOR-B'), 'buffer isolation: session A never receives B\'s message');
    assert_contains('MESSAGE-FOR-B', TmuxService::tmux_capture_pane($raceSessionB), 'buffer isolation: session B receives its own message');

    $leftoverBuffers = TmuxService::tmux_run(['list-buffers'])['stdout'];
    assert_equal('', trim($leftoverBuffers), 'buffer isolation: both named buffers were auto-deleted by paste-buffer -d, none leaked');

    TmuxService::tmux_run(['kill-session', '-t', $raceSessionA]);
    TmuxService::tmux_run(['kill-session', '-t', $raceSessionB]);
    $raceSessionA = null;
    $raceSessionB = null;

    // --- The actual regression guard for the fix above: confirms
    // send_message() and answer_prompt_with_text() themselves genuinely
    // use a named (-b) buffer at BOTH their set-buffer and paste-buffer
    // call sites, not tmux's shared default one - a plain source check
    // rather than another behavioral one, since truly forcing these two
    // specific call sites to interleave via real OS concurrency from a
    // test would be inherently flaky (no artificial-delay hook exists in
    // either function, deliberately not adding one for testability alone).
    // Deterministic and fails immediately if either call site is ever
    // reverted to the bare, shared-buffer form. ---
    $sessionServiceSource = (string)file_get_contents(dirname(__DIR__) . '/host-agent/lib/Services/SessionService.php');
    assert_equal(
        0,
        preg_match('/set-buffer\',\s*\'--\'/', $sessionServiceSource),
        'buffer isolation: SessionService.php never uses a bare set-buffer with no -b (the shared, unsafe default buffer)'
    );
    assert_equal(
        0,
        preg_match('/paste-buffer\',\s*\'-t\'/', $sessionServiceSource),
        'buffer isolation: SessionService.php never uses paste-buffer with no -b (would paste from the shared default buffer)'
    );

    // --- Full-feature parity for an adopted (non-cc-*) session: "tracked"
    // is now sidecar-existence, not the cc-* prefix (see TmuxService::
    // list_tracked_tmux_sessions()), so a session session_start.php adopted
    // - real tmux session, real sidecar, name never touched - must show up
    // in list_all_sessions()'s main sessions[] (not bare[]), report
    // spawned_by_csm=false, and accept the exact same actions (send_message,
    // kill_cc_session) as an app-spawned cc-* one. Simulates the hook's own
    // sidecar write directly rather than re-running session_start.php here -
    // that hook's own behavior is covered separately in test_session_hook.php. ---
    $adoptedTestSession = 'andres-manual-' . getmypid();
    $adoptedSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $adoptedTestSession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $adoptedSetup['exit'], 'adopted session setup: created a live non-cc-* tmux session');
    SidecarStore::write_sidecar($adoptedTestSession, ['workdir' => Config::www_root(), 'spawned_at' => time(), 'spawned_by_csm' => false]);
    usleep(300000);

    $listed = SessionService::list_all_sessions();
    $adoptedEntry = null;

    foreach ($listed['sessions'] as $s) {
        if ($s['name'] === $adoptedTestSession) {
            $adoptedEntry = $s;
            break;
        }
    }

    assert_true($adoptedEntry !== null, 'list: an adopted (non-cc-*) session with a sidecar appears in sessions[], not just bare[]');
    assert_equal(false, $adoptedEntry['spawned_by_csm'] ?? null, 'list: an adopted session reports spawned_by_csm=false');
    assert_true(
        !in_array($adoptedTestSession, array_column($listed['bare'], 'tmux_session'), true),
        'list: an adopted, now-tracked session is not double-counted in bare[]'
    );

    $adoptedSend = SessionService::send_message($adoptedTestSession, 'Hello from an adopted session');
    assert_true($adoptedSend['ok'] ?? false, 'send_message: works against an adopted (non-cc-*) session, not just cc-* ones');
    usleep(300000);
    assert_contains('Hello from an adopted session', TmuxService::tmux_capture_pane($adoptedTestSession), 'send_message: the text actually landed in the adopted session\'s pane');

    $adoptedKill = SessionService::kill_cc_session($adoptedTestSession);
    assert_true($adoptedKill['ok'] ?? false, 'kill_cc_session: works against an adopted (non-cc-*) session, not just cc-* ones');
    $hasAdoptedSession = TmuxService::tmux_run(['has-session', '-t', $adoptedTestSession]);
    assert_true($hasAdoptedSession['exit'] !== 0, 'kill_cc_session: the adopted session\'s tmux session is actually gone');
    assert_true(SidecarStore::read_sidecar($adoptedTestSession) === null, 'kill_cc_session: the adopted session\'s sidecar is removed too');
    $adoptedTestSession = null;

    // --- QuotaService::quota_from_live_pane()/QuotaService::get_quota(): prefers a live session's own
    // status-line quota over the slow claude-quota fallback - crafted via
    // a raw `cat` pane like the wrap-test above, since fake_claude never
    // renders a real status line. ---
    $quotaTestSession = 'cc-test-quota-' . getmypid();
    $quotaSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $quotaTestSession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $quotaSetup['exit'], 'quota_from_live_pane setup: created a live cc-* session');
    SidecarStore::write_sidecar($quotaTestSession, ['workdir' => Config::www_root(), 'spawned_at' => time()]);
    usleep(300000);

    assert_equal(null, QuotaService::quota_from_live_pane(), 'quota_from_live_pane: null while no live session shows a quota line yet');

    TmuxService::tmux_run(['send-keys', '-t', $quotaTestSession, 'andres@work /some/workdir | Sonnet 5 | ctx: 4% | 5h: 51% (1h 53m) | 7d: 40% (5d 8h)', 'Enter']);
    usleep(300000);

    $liveQuota = QuotaService::quota_from_live_pane();
    assert_true($liveQuota !== null, 'quota_from_live_pane: finds the quota line once a live session shows one');
    assert_equal(51, $liveQuota['quota']['session']['pct'] ?? null, 'quota_from_live_pane: session pct read from the real pane');
    assert_equal(40, $liveQuota['quota']['week_all']['pct'] ?? null, 'quota_from_live_pane: week_all pct read from the real pane');
    assert_true(is_int($liveQuota['quota']['session']['resets_at'] ?? null), 'quota_from_live_pane: resets_at computed from the parenthetical duration');

    $getQuotaResult = QuotaService::get_quota();
    assert_equal(51, $getQuotaResult['quota']['session']['pct'] ?? null, 'QuotaService::get_quota(): prefers the live pane reading over the cache/scrape fallback');
    assert_equal(false, $getQuotaResult['cached'] ?? null, 'QuotaService::get_quota(): a live pane reading is never reported as cached');
    assert_true(!isset($getQuotaResult['quota']['context']), 'QuotaService::get_quota(): no context bucket when called without a session name');

    // --- QuotaService::live_context_pct()/get_quota($sessionName): the
    // per-session context-window overlay - genuinely distinct from the
    // account-wide session/week_all buckets above (same pane, same status
    // line, but this one is keyed to a specific session on purpose). ---
    assert_equal(4, QuotaService::live_context_pct($quotaTestSession), 'live_context_pct: reads the ctx percentage from the requested session\'s own pane');
    assert_equal(null, QuotaService::live_context_pct('cc-not-a-real-session'), 'live_context_pct: null for a session that isn\'t live');

    $withContext = QuotaService::get_quota($quotaTestSession);
    assert_equal(4, $withContext['quota']['context']['pct'] ?? null, 'get_quota($session): overlays that session\'s context pct alongside the account-wide buckets');
    assert_equal(51, $withContext['quota']['session']['pct'] ?? null, 'get_quota($session): session/week_all buckets are unaffected by the context overlay');
    assert_true(!isset($withContext['quota']['context']['resets_at']), 'get_quota($session): context has no reset timer, unlike session/week_all');

    $unknownSessionResult = QuotaService::get_quota('cc-not-a-real-session');
    assert_true(!isset($unknownSessionResult['quota']['context']), 'get_quota($session): no context bucket when the given session isn\'t live');
    assert_equal(51, $unknownSessionResult['quota']['session']['pct'] ?? null, 'get_quota($session): account-wide buckets still come through for an unknown session name');

    TmuxService::tmux_run(['kill-session', '-t', $quotaTestSession]);
    SidecarStore::delete_sidecar($quotaTestSession);
    $quotaTestSession = null;
} finally {
    // Defense in depth - tests/run.sh's `tmux kill-server` on the isolated
    // socket is the real backstop regardless of what happens here, but
    // clean up explicitly too in case this script is ever run standalone.
    foreach ($createdSessions as $leftover) {
        SessionService::kill_cc_session($leftover);
    }
    if ($adhocName !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $adhocName]);
    }
    if ($promptTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $promptTestSession]);
    }
    if ($sendTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $sendTestSession]);
    }
    if ($raceSessionA !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $raceSessionA]);
    }
    if ($raceSessionB !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $raceSessionB]);
    }
    if ($adoptedTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $adoptedTestSession]);
    }
    if ($wrapTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $wrapTestSession]);
    }
    if ($quotaTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $quotaTestSession]);
    }
    if ($bareProc !== null && is_resource($bareProc)) {
        proc_terminate($bareProc);
        proc_close($bareProc);
    }
    if ($takeOverBareProc !== null && is_resource($takeOverBareProc)) {
        proc_terminate($takeOverBareProc);
        proc_close($takeOverBareProc);
    }
    if ($markerAdhocName !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $markerAdhocName]);
    }
    foreach ($dualBareAdhocNames as $leftoverDualBare) {
        TmuxService::tmux_run(['kill-session', '-t', $leftoverDualBare]);
    }
    // All three take-over blocks above always restore HOME_ROOT before
    // falling through to later tests, but if anything in one of them
    // throws partway through (a strict-types TypeError, say), that
    // putenv() never runs - clear it here too, defense in depth, so a
    // mid-block failure can't leak a fake HOME_ROOT into whatever runs
    // next.
    putenv('HOME_ROOT');
}

test_exit();
