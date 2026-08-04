<?php
declare(strict_types=1);

/**
 * Exercises the Web Push logic in host-agent/lib/Push.php - subscription
 * storage, session-state transition detection, and dispatch_push_action()
 * - against isolated fixture paths, never the real subscription/state
 * files. A real PushDeliveryService::send_push_notification() call is only ever exercised
 * against a guaranteed-closed local port (127.0.0.1:1), never a real push
 * service - see the "real send attempt" section below for why that's
 * still a meaningful test without actually delivering anywhere.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Push.php';

use HostAgent\Services\NotificationContentBuilder;
use HostAgent\Services\PushDeliveryService;
use HostAgent\Services\PushHealthService;
use HostAgent\Services\PushTimerService;
use HostAgent\Stores\PushSessionStateStore;
use HostAgent\Stores\PushSubscriptionStore;

const REAL_PUSH_SUBSCRIPTIONS_FILE = '/home/andres/www/claude-session-manager/host-agent/state/push-subscriptions.json';
const REAL_PUSH_STATE_FILE = '/home/andres/www/claude-session-manager/host-agent/state/push-session-state.json';
const REAL_PUSH_CHECK_STATUS_FILE = '/home/andres/www/claude-session-manager/host-agent/state/push-check-status.json';
const REAL_PUSH_TIMER_UNIT_NAME = 'csm-push-check.timer';

$fixtureDir = sys_get_temp_dir() . '/csm-test-push-' . bin2hex(random_bytes(4));
mkdir($fixtureDir, 0700, true);

putenv('PUSH_SUBSCRIPTIONS_FILE=' . $fixtureDir . '/push-subscriptions.json');
putenv('PUSH_STATE_FILE=' . $fixtureDir . '/push-session-state.json');
putenv('PUSH_CHECK_STATUS_FILE=' . $fixtureDir . '/push-check-status.json');
putenv('PUSH_TIMER_UNIT_PATH=' . $fixtureDir . '/csm-push-check.timer');
// PushTimerService::set_push_timer_interval() runs real `systemctl --user is-active`/
// `restart` commands against this unit NAME - a fake one systemd has
// never heard of is what keeps `restart` from ever firing for real
// during a test run (is-active reliably reports "inactive" for it).
putenv('PUSH_TIMER_UNIT_NAME=csm-test-fake-push-timer-' . bin2hex(random_bytes(4)) . '.timer');

if (
    PushSubscriptionStore::push_subscriptions_file() === REAL_PUSH_SUBSCRIPTIONS_FILE
    || PushSessionStateStore::push_state_file() === REAL_PUSH_STATE_FILE
    || PushDeliveryService::push_check_status_file() === REAL_PUSH_CHECK_STATUS_FILE
    || PushTimerService::push_timer_unit_name() === REAL_PUSH_TIMER_UNIT_NAME
) {
    fwrite(STDERR, "REFUSING TO RUN: push subscription/state/timer files or unit name still resolve to the real ones.\n");
    exit(1);
}

try {
    // --- PushDeliveryService::push_configured(): false until both VAPID keys are set ---

    putenv('VAPID_PUBLIC_KEY');
    putenv('VAPID_PRIVATE_KEY');
    assert_equal(false, PushDeliveryService::push_configured(), 'push_configured: false with no VAPID keys set (fresh checkout default)');

    putenv('VAPID_PUBLIC_KEY=fake-public-key');
    assert_equal(false, PushDeliveryService::push_configured(), 'push_configured: still false with only the public key set');

    putenv('VAPID_PRIVATE_KEY=fake-private-key');
    assert_equal(true, PushDeliveryService::push_configured(), 'push_configured: true once both keys are set');

    // --- PushSubscriptionStore read/write/add/remove_push_subscription(): round-trip, dedupe by endpoint ---

    assert_equal([], PushSubscriptionStore::read_push_subscriptions(), 'read_push_subscriptions: empty when no file exists yet');

    $subA = ['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'p256dh-a', 'auth' => 'auth-a']];
    $subB = ['endpoint' => 'https://push.example/b', 'keys' => ['p256dh' => 'p256dh-b', 'auth' => 'auth-b']];

    assert_true(PushSubscriptionStore::add_push_subscription($subA), 'add_push_subscription: accepts a well-formed subscription');
    assert_true(PushSubscriptionStore::add_push_subscription($subB), 'add_push_subscription: accepts a second, different subscription');
    assert_equal(2, count(PushSubscriptionStore::read_push_subscriptions()), 'add_push_subscription: both subscriptions stored');

    assert_equal(false, PushSubscriptionStore::add_push_subscription(['endpoint' => 'https://push.example/c']), 'add_push_subscription: rejects a subscription missing keys');
    assert_equal(false, PushSubscriptionStore::add_push_subscription(['keys' => ['p256dh' => 'x', 'auth' => 'y']]), 'add_push_subscription: rejects a subscription missing an endpoint');
    assert_equal(2, count(PushSubscriptionStore::read_push_subscriptions()), 'add_push_subscription: a rejected subscription is not stored');

    // Resubscribing under the SAME endpoint with new keys replaces, not duplicates -
    // this is exactly what the frontend's resubscribe-on-every-open call relies on.
    $subAUpdated = ['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'p256dh-a-NEW', 'auth' => 'auth-a-NEW']];
    PushSubscriptionStore::add_push_subscription($subAUpdated);
    $stored = PushSubscriptionStore::read_push_subscriptions();
    assert_equal(2, count($stored), 'add_push_subscription: resubscribing under the same endpoint does not duplicate');
    $storedA = array_values(array_filter($stored, fn(array $s) => $s['endpoint'] === 'https://push.example/a'))[0] ?? null;
    assert_equal('p256dh-a-NEW', $storedA['keys']['p256dh'] ?? null, 'add_push_subscription: resubscribing under the same endpoint updates the stored keys');

    PushSubscriptionStore::remove_push_subscription('https://push.example/a');
    $afterRemove = PushSubscriptionStore::read_push_subscriptions();
    assert_equal(1, count($afterRemove), 'remove_push_subscription: removes exactly the matching subscription');
    assert_equal('https://push.example/b', $afterRemove[0]['endpoint'] ?? null, 'remove_push_subscription: the other subscription survives');

    PushSubscriptionStore::remove_push_subscription('https://push.example/b');
    assert_equal([], PushSubscriptionStore::read_push_subscriptions(), 'remove_push_subscription: file is empty after removing the last subscription');

    // --- NotificationContentBuilder::push_session_state(): classification used for transition detection ---

    assert_equal('blocked', NotificationContentBuilder::push_session_state(['blocked_reason' => 'Do you want to proceed?', 'working' => false]), 'push_session_state: blocked_reason present -> blocked, regardless of working');
    assert_equal('blocked', NotificationContentBuilder::push_session_state(['blocked_reason' => 'Do you want to proceed?', 'working' => true]), 'push_session_state: blocked_reason wins even if working is also somehow true');
    assert_equal('working', NotificationContentBuilder::push_session_state(['blocked_reason' => null, 'working' => true]), 'push_session_state: working (not blocked) -> working');
    assert_equal('idle', NotificationContentBuilder::push_session_state(['blocked_reason' => null, 'working' => false]), 'push_session_state: neither blocked nor working -> idle');
    assert_equal('idle', NotificationContentBuilder::push_session_state([]), 'push_session_state: missing fields default to idle, not a crash');

    // --- PushDeliveryService::check_and_send_pushes(): transition detection, with zero
    // subscriptions configured so no real send is ever attempted here -
    // see the "real send attempt" section below for that part. ---

    assert_equal(['ok' => false, 'notified' => [], 'pruned' => 0], (function () {
        putenv('VAPID_PUBLIC_KEY');
        putenv('VAPID_PRIVATE_KEY');
        $result = PushDeliveryService::check_and_send_pushes([['name' => 'cc-x', 'blocked_reason' => 'hi', 'working' => false]]);
        putenv('VAPID_PUBLIC_KEY=fake-public-key');
        putenv('VAPID_PRIVATE_KEY=fake-private-key');
        return $result;
    })(), 'check_and_send_pushes: a harmless no-op when VAPID keys are not configured');

    @unlink(PushSessionStateStore::push_state_file());

    $first = PushDeliveryService::check_and_send_pushes([
        ['name' => 'cc-blocked', 'blocked_reason' => 'Proceed?', 'working' => false],
        ['name' => 'cc-idle', 'blocked_reason' => null, 'working' => false],
    ]);
    assert_equal(['cc-blocked'], $first['notified'], 'check_and_send_pushes: a session already blocked on the very first check counts as a fresh transition (no prior state on record)');

    $second = PushDeliveryService::check_and_send_pushes([
        ['name' => 'cc-blocked', 'blocked_reason' => 'Proceed?', 'working' => false],
        ['name' => 'cc-idle', 'blocked_reason' => null, 'working' => false],
    ]);
    assert_equal([], $second['notified'], 'check_and_send_pushes: still blocked on the next check -> not notified again (same prompt, not a new one)');

    $third = PushDeliveryService::check_and_send_pushes([
        ['name' => 'cc-blocked', 'blocked_reason' => null, 'working' => false],
        ['name' => 'cc-idle', 'blocked_reason' => 'A new question', 'working' => false],
    ]);
    assert_equal(['cc-idle'], $third['notified'], 'check_and_send_pushes: cc-idle transitioning into blocked is notified; cc-blocked resolving is not (that\'s not a "new prompt" event)');

    $fourth = PushDeliveryService::check_and_send_pushes([
        ['name' => 'cc-blocked', 'blocked_reason' => 'Proceed again?', 'working' => false],
    ]);
    assert_equal(['cc-blocked'], $fourth['notified'], 'check_and_send_pushes: transitioning back into blocked after having resolved counts as a fresh transition again');

    // --- NotificationContentBuilder::push_notification_title(): prefers the real title, falls back
    // to something friendlier than the raw cc-YYYYMMDD-HHMM name when a
    // session hits a prompt before Claude Code has set one yet ---

    assert_equal('Fix the login bug', NotificationContentBuilder::push_notification_title(['name' => 'cc-20260101-1200', 'title' => 'Fix the login bug', 'workdir' => '/home/andres/www/demo']), 'push_notification_title: prefers the real title when present');
    assert_equal('demo-project', NotificationContentBuilder::push_notification_title(['name' => 'cc-20260101-1200', 'title' => null, 'workdir' => '/home/andres/www/demo-project']), 'push_notification_title: falls back to the workdir basename when no title is set yet');
    assert_equal('cc-20260101-1200', NotificationContentBuilder::push_notification_title(['name' => 'cc-20260101-1200', 'title' => null, 'workdir' => null]), 'push_notification_title: falls back to the raw session name as a last resort');
    assert_equal('Claude session', NotificationContentBuilder::push_notification_title([]), 'push_notification_title: a completely empty session still returns something, not a crash');
    assert_equal('Fix the login bug', NotificationContentBuilder::push_notification_title(['name' => 'cc-20260101-1200', 'title' => "\u{2733} Fix the login bug", 'workdir' => null]), 'push_notification_title: strips Claude Code\'s leading idle-title icon (e.g. U+2733), decorative in a terminal title bar but out of place in a phone notification');
    assert_equal('Fix the login bug', NotificationContentBuilder::push_notification_title(['name' => 'cc-20260101-1200', 'title' => "\u{2728} Fix the login bug", 'workdir' => null]), 'push_notification_title: strips any leading Symbol-Other (\\p{So}) glyph, not just the one specific icon seen live');
    assert_equal('No icon here', NotificationContentBuilder::push_notification_title(['name' => 'cc-20260101-1200', 'title' => 'No icon here', 'workdir' => null]), 'push_notification_title: a plain title with no leading icon is untouched');

    // --- NotificationContentBuilder::push_finished_body(): the real reply text (truncated), or a generic fallback ---

    assert_equal(
        'Found it: the redirect URL was hardcoded.',
        NotificationContentBuilder::push_finished_body(['role' => 'assistant', 'blocks' => [['kind' => 'text', 'text' => 'Found it: the redirect URL was hardcoded.']]]),
        'push_finished_body: uses the real assistant reply text'
    );
    $longReply = str_repeat('a', 200);
    $truncated = NotificationContentBuilder::push_finished_body(['role' => 'assistant', 'blocks' => [['kind' => 'text', 'text' => $longReply]]]);
    assert_equal(141, mb_strlen($truncated), 'push_finished_body: truncates a long reply to 140 chars + ellipsis, same convention as last_message_preview_html()');
    assert_equal('Finished - no input needed', NotificationContentBuilder::push_finished_body(null), 'push_finished_body: no last message at all -> generic fallback');
    assert_equal('Finished - no input needed', NotificationContentBuilder::push_finished_body(['role' => 'user', 'blocks' => [['kind' => 'text', 'text' => 'irrelevant']]]), 'push_finished_body: a non-assistant last message -> generic fallback (not the user\'s own prior message)');
    assert_equal('Finished - no input needed', NotificationContentBuilder::push_finished_body(['role' => 'assistant', 'blocks' => [['kind' => 'tool_use', 'text' => 'tool: Bash - command: ls']]]), 'push_finished_body: an assistant turn with only tool calls, no closing text -> generic fallback');

    // --- NotificationContentBuilder::push_permission_body()/NotificationContentBuilder::push_blocked_body(): a permission prompt's
    // push body shows the real command/action, not the generic pane-scraped
    // "do you want to proceed?" question - an AskUserQuestion prompt keeps
    // showing the real question text as before ---

    assert_equal('npm test', NotificationContentBuilder::push_permission_body('Bash', ['command' => 'npm test']), 'push_permission_body: Bash shows the real command');
    assert_equal('Run a Bash command', NotificationContentBuilder::push_permission_body('Bash', []), 'push_permission_body: Bash with no command -> generic fallback');
    assert_equal('Write /tmp/foo.txt', NotificationContentBuilder::push_permission_body('Write', ['file_path' => '/tmp/foo.txt', 'content' => 'irrelevant for the push body']), 'push_permission_body: Write shows the path, not the full file content');
    assert_equal('Edit /tmp/foo.txt', NotificationContentBuilder::push_permission_body('Edit', ['file_path' => '/tmp/foo.txt', 'old_string' => 'a', 'new_string' => 'b']), 'push_permission_body: Edit shows the path');
    assert_equal('Run WebFetch', NotificationContentBuilder::push_permission_body('WebFetch', ['url' => 'https://example.com']), 'push_permission_body: an unrecognized tool falls back to "Run <tool>"');
    $longCommand = str_repeat('a', 200);
    assert_equal(141, mb_strlen(NotificationContentBuilder::push_permission_body('Bash', ['command' => $longCommand])), 'push_permission_body: a long command is truncated the same as push_finished_body');

    // A Bash call's own `description` field - real per-command context
    // Claude Code itself writes, confirmed live to be exactly what
    // Anthropic's own Claude app shows (without the command) for the
    // identical prompt - IS prefixed, unlike the reverted session-title
    // prefix above (a stale, session-wide label rather than real
    // per-command context).
    assert_equal('Run the test suite: npm test', NotificationContentBuilder::push_permission_body('Bash', ['command' => 'npm test', 'description' => 'Run the test suite']), 'push_permission_body: prefixes the Bash call\'s own description before the command');
    assert_equal('npm test', NotificationContentBuilder::push_permission_body('Bash', ['command' => 'npm test', 'description' => '']), 'push_permission_body: an empty description is treated as no description');
    assert_equal(141, mb_strlen(NotificationContentBuilder::push_permission_body('Bash', ['command' => $longCommand, 'description' => 'A description'])), 'push_permission_body: truncation still applies to the combined description+command');

    // Deliberately does NOT prefix the session's title onto this anymore -
    // tried it, reverted (see NotificationContentBuilder::push_permission_body()'s own comment): a
    // stale tmux pane-title from earlier in a long session read as
    // confusing noise prefixed onto a later, unrelated command.
    assert_equal(
        'npm test',
        NotificationContentBuilder::push_blocked_body(['blocked_reason' => 'Do you want to proceed?', 'prompt_tool_name' => 'Bash', 'prompt_tool_input' => ['command' => 'npm test']]),
        'push_blocked_body: a permission prompt (matched pending tool) shows the command, not the generic question, and not prefixed with the session\'s (possibly stale) title'
    );
    assert_equal(
        'Which color do you prefer?',
        NotificationContentBuilder::push_blocked_body(['blocked_reason' => 'Which color do you prefer?', 'prompt_tool_name' => 'AskUserQuestion', 'prompt_tool_input' => ['questions' => []]]),
        'push_blocked_body: an AskUserQuestion prompt keeps showing the real question text'
    );
    assert_equal(
        'Do you trust the files in this folder?',
        NotificationContentBuilder::push_blocked_body(['blocked_reason' => 'Do you trust the files in this folder?', 'prompt_tool_name' => null, 'prompt_tool_input' => null]),
        'push_blocked_body: no matched pending tool at all (trust dialog, stale/missing PreToolUse record) falls back to the pane-scraped question'
    );
    assert_equal(
        'Waiting on input',
        NotificationContentBuilder::push_blocked_body([]),
        'push_blocked_body: a completely empty session still returns something, not a crash'
    );

    // --- NotificationContentBuilder::push_blocked_title(): leads with WHAT KIND of prompt this is -
    // no "Claude" wording (iOS already attributes the notification to
    // this app via the icon and its own OS-level "from <manifest name>"
    // line, not something the Notification API can suppress - repeating
    // "Claude" in the title text was redundant, per Andres) - the
    // session's own title is still folded in after, since "which
    // session" matters here in a way it doesn't for a single-conversation
    // app. Every branch is type-labeled, including the folder-trust
    // dialog and the generic fallback. ---

    assert_equal(
        'Needs permission: Fix the login bug',
        NotificationContentBuilder::push_blocked_title(['name' => 'cc-1', 'title' => 'Fix the login bug', 'prompt_tool_name' => 'Bash']),
        'push_blocked_title: leads with "Needs permission" for a permission-type prompt (any matched tool other than AskUserQuestion)'
    );
    assert_equal(
        'Has a question: Fix the login bug',
        NotificationContentBuilder::push_blocked_title(['name' => 'cc-1', 'title' => 'Fix the login bug', 'prompt_tool_name' => 'AskUserQuestion']),
        'push_blocked_title: leads with "Has a question" for AskUserQuestion specifically'
    );
    assert_equal(
        'Needs folder trust: Fix the login bug',
        NotificationContentBuilder::push_blocked_title(['name' => 'cc-1', 'title' => 'Fix the login bug', 'prompt_tool_name' => null, 'prompt_is_folder_trust' => true]),
        'push_blocked_title: leads with "Needs folder trust" for the initial folder-trust dialog specifically, even though it has no matched tool'
    );
    assert_equal(
        'Needs input: Fix the login bug',
        NotificationContentBuilder::push_blocked_title(['name' => 'cc-1', 'title' => 'Fix the login bug', 'prompt_tool_name' => null]),
        'push_blocked_title: no matched tool and not a trust dialog (stale/missing PreToolUse record) -> generic but still type-labeled fallback, never a bare title with no hint of what kind of prompt it is'
    );

    // --- NotificationContentBuilder::push_finished_title(): same type-labeled convention for the
    // "finished working" notification ---
    assert_equal(
        'Finished: Fix the login bug',
        NotificationContentBuilder::push_finished_title(['name' => 'cc-1', 'title' => 'Fix the login bug']),
        'push_finished_title: leads with "Finished", same convention as NotificationContentBuilder::push_blocked_title()'
    );

    // --- PushDeliveryService::check_and_send_pushes(): the "finished a long task" notification
    // - a genuinely new case (previously ZERO notification coverage for a
    // session that finishes without ever needing input at all) ---

    @unlink(PushSessionStateStore::push_state_file());
    putenv('PUSH_MIN_WORKING_SECONDS_FOR_FINISH_NOTIFY=60');

    $t0 = 1_000_000;
    $workingStart = PushDeliveryService::check_and_send_pushes([['name' => 'cc-working', 'blocked_reason' => null, 'working' => true]], $t0);
    assert_equal([], $workingStart['notified'], 'check_and_send_pushes: starting to work is never itself notification-worthy');

    $stillWorkingSoon = PushDeliveryService::check_and_send_pushes([['name' => 'cc-working', 'blocked_reason' => null, 'working' => true]], $t0 + 10);
    assert_equal([], $stillWorkingSoon['notified'], 'check_and_send_pushes: still working 10s later - no notification yet, still working');

    $finishedTooSoon = PushDeliveryService::check_and_send_pushes([['name' => 'cc-working', 'blocked_reason' => null, 'working' => false]], $t0 + 15);
    assert_equal([], $finishedTooSoon['notified'], 'check_and_send_pushes: finished after only 15s of work - below the 60s threshold, not notified (avoids noise for quick replies)');

    @unlink(PushSessionStateStore::push_state_file());
    $workingStart2 = PushDeliveryService::check_and_send_pushes([['name' => 'cc-long-task', 'blocked_reason' => null, 'working' => true]], $t0);
    assert_equal([], $workingStart2['notified'], 'check_and_send_pushes (long task): starting to work is never itself notification-worthy');

    $finishedLongTask = PushDeliveryService::check_and_send_pushes([[
        'name' => 'cc-long-task',
        'blocked_reason' => null,
        'working' => false,
        'title' => 'Refactor the auth module',
        'last_message' => ['role' => 'assistant', 'blocks' => [['kind' => 'text', 'text' => 'Done - all tests pass.']]],
    ]], $t0 + 90);
    assert_equal(['cc-long-task'], $finishedLongTask['notified'], 'check_and_send_pushes: finished after 90s of continuous work - above the 60s threshold, notified');

    // Once notified, going idle -> idle again (nothing changed) must not re-notify.
    $stillIdleAfter = PushDeliveryService::check_and_send_pushes([['name' => 'cc-long-task', 'blocked_reason' => null, 'working' => false]], $t0 + 100);
    assert_equal([], $stillIdleAfter['notified'], 'check_and_send_pushes: still idle on the next check -> not notified again');

    // A blocked prompt appearing takes priority over (and is a completely
    // separate concern from) the finished-working case - both paths must
    // coexist correctly in the same pass.
    @unlink(PushSessionStateStore::push_state_file());
    PushDeliveryService::check_and_send_pushes([['name' => 'cc-mixed', 'blocked_reason' => null, 'working' => true]], $t0);
    $mixedResult = PushDeliveryService::check_and_send_pushes([
        ['name' => 'cc-mixed', 'blocked_reason' => 'A follow-up question', 'working' => false],
        ['name' => 'cc-other', 'blocked_reason' => null, 'working' => true],
    ], $t0 + 90);
    assert_equal(['cc-mixed'], $mixedResult['notified'], 'check_and_send_pushes: a session that started working then hit a prompt is notified for the blocked transition, not double-counted as "finished"');

    putenv('PUSH_MIN_WORKING_SECONDS_FOR_FINISH_NOTIFY');

    // --- PushDeliveryService::send_push_notification(): a genuinely malformed VAPID key must
    // report failure, not crash the whole (unattended, systemd-timer-run)
    // process - found live: minishlink/web-push throws a hard
    // ErrorException for this, not a normal failed-report return. ---

    $malformedKeySubscription = ['endpoint' => 'http://127.0.0.1:1/nothing-listens-here', 'keys' => ['p256dh' => 'x', 'auth' => 'y']];
    $malformedKeyResult = PushDeliveryService::send_push_notification($malformedKeySubscription, 'Title', 'Body');
    assert_equal(false, $malformedKeyResult['ok'], 'send_push_notification: a malformed VAPID key reports ok=false, not an uncaught exception');

    // --- real send attempt with a structurally-valid VAPID keypair and
    // subscription, against a guaranteed-closed local port: never a real
    // push service, but proves a real (failed) send is handled gracefully
    // end to end, not just the malformed-key case above. ---

    $realVapidKeys = Minishlink\WebPush\VAPID::createVapidKeys();
    putenv('VAPID_PUBLIC_KEY=' . $realVapidKeys['publicKey']);
    putenv('VAPID_PRIVATE_KEY=' . $realVapidKeys['privateKey']);

    $ecKeyResource = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ecDetails = openssl_pkey_get_details($ecKeyResource);
    $b64url = fn(string $d): string => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $fakeP256dh = $b64url("\x04" . $ecDetails['ec']['x'] . $ecDetails['ec']['y']);
    $fakeAuth = $b64url(random_bytes(16));

    $unreachableSubscription = ['endpoint' => 'http://127.0.0.1:1/nothing-listens-here', 'keys' => ['p256dh' => $fakeP256dh, 'auth' => $fakeAuth]];
    $sendResult = PushDeliveryService::send_push_notification($unreachableSubscription, 'Title', 'Body');
    assert_equal(false, $sendResult['ok'], 'send_push_notification: a real (failed) send against an unreachable endpoint reports ok=false, not an uncaught exception');

    PushSubscriptionStore::add_push_subscription($unreachableSubscription);
    @unlink(PushSessionStateStore::push_state_file());
    $withRealSubscriber = PushDeliveryService::check_and_send_pushes([['name' => 'cc-real-send', 'blocked_reason' => 'Proceed?', 'working' => false]]);
    assert_equal(['cc-real-send'], $withRealSubscriber['notified'], 'check_and_send_pushes: still reports the transition even though the actual send to the one subscriber failed');

    // --- PushDeliveryService::record_push_check_result()/PushHealthService::push_delivery_check(): the failed
    // send just above must leave a real, readable trace - previously a
    // non-expiry send failure left NO record anywhere at all, only an
    // expired subscription being silently pruned did. ---

    $statusAfterFailure = json_decode((string)file_get_contents(PushDeliveryService::push_check_status_file()), true);
    assert_equal(1, $statusAfterFailure['sent'] ?? null, 'record_push_check_result: counts the one send attempt from this tick');
    assert_equal(1, $statusAfterFailure['failed'] ?? null, 'record_push_check_result: counts the one failure (an unreachable endpoint is a real, non-expiry failure)');
    assert_equal(true, is_string($statusAfterFailure['last_failure_message'] ?? null) && $statusAfterFailure['last_failure_message'] !== '', 'record_push_check_result: persists a non-empty failure message, not just a bare count');

    $deliveryCheckAfterFailure = PushHealthService::push_delivery_check();
    assert_equal(false, $deliveryCheckAfterFailure['ok'], 'push_delivery_check: ok=false right after a tick with a real send failure');
    assert_equal(true, str_contains($deliveryCheckAfterFailure['detail'], '1 send(s) failed'), 'push_delivery_check: detail mentions the failure count');

    PushSubscriptionStore::remove_push_subscription($unreachableSubscription['endpoint']);

    // A tick with nothing to send (no subscribers, no transitions) still
    // records a heartbeat, and clears the previous failure - the failure
    // record reflects only the MOST RECENT tick, not history piling up.
    @unlink(PushSessionStateStore::push_state_file());
    PushDeliveryService::check_and_send_pushes([['name' => 'cc-quiet', 'blocked_reason' => null, 'working' => false]]);
    $statusAfterQuietTick = json_decode((string)file_get_contents(PushDeliveryService::push_check_status_file()), true);
    assert_equal(0, $statusAfterQuietTick['sent'] ?? null, 'record_push_check_result: a tick with nothing to send still records (0 sent), proving the timer ran');
    assert_equal(0, $statusAfterQuietTick['failed'] ?? null, 'record_push_check_result: no failures on a quiet tick');
    assert_equal(true, PushHealthService::push_delivery_check()['ok'], 'push_delivery_check: ok=true right after a clean, recent tick');

    @unlink(PushDeliveryService::push_check_status_file());
    assert_equal(false, PushHealthService::push_delivery_check()['ok'], 'push_delivery_check: ok=false when the timer has never run at all (no status file yet)');

    putenv('VAPID_PUBLIC_KEY');
    putenv('VAPID_PRIVATE_KEY');
    assert_equal(true, PushHealthService::push_delivery_check()['ok'], 'push_delivery_check: ok=true (nothing to check yet) when VAPID isn\'t configured, not a false alarm on top of the separate "VAPID push keys" health check');
    putenv('VAPID_PUBLIC_KEY=' . $realVapidKeys['publicKey']);
    putenv('VAPID_PRIVATE_KEY=' . $realVapidKeys['privateKey']);

    // --- PushTimerService get/set_push_timer_interval(): reads/writes the INSTALLED unit
    // file (isolated to a fixture path above, never the real one), and
    // PushTimerService::set_push_timer_interval()'s systemctl calls target a fake unit name
    // (also isolated above) so a test run can never touch the real
    // production csm-push-check.timer ---

    assert_equal(
        false,
        PushTimerService::get_push_timer_interval()['ok'],
        'get_push_timer_interval: ok=false when the timer unit isn\'t installed at all (fresh checkout)'
    );

    $fixtureTimerUnit = <<<'UNIT'
    [Unit]
    Description=fixture

    [Timer]
    OnBootSec=10s
    OnUnitActiveSec=10s
    Unit=csm-push-check.service

    [Install]
    WantedBy=timers.target
    UNIT;
    file_put_contents(PushTimerService::push_timer_unit_path(), $fixtureTimerUnit);

    $readBack = PushTimerService::get_push_timer_interval();
    assert_equal(true, $readBack['ok'], 'get_push_timer_interval: ok=true once the unit file exists');
    assert_equal(10, $readBack['interval_seconds'], 'get_push_timer_interval: parses the real OnUnitActiveSec value from the fixture unit');

    $tooLow = PushTimerService::set_push_timer_interval(1);
    assert_equal(false, $tooLow['ok'], 'set_push_timer_interval: rejects a value below the minimum');

    $tooHigh = PushTimerService::set_push_timer_interval(9999);
    assert_equal(false, $tooHigh['ok'], 'set_push_timer_interval: rejects a value above the maximum');

    $setResult = PushTimerService::set_push_timer_interval(30);
    assert_equal(true, $setResult['ok'], 'set_push_timer_interval: accepts a value within bounds');
    assert_equal(30, $setResult['interval_seconds'], 'set_push_timer_interval: echoes back the new interval');

    $rewritten = file_get_contents(PushTimerService::push_timer_unit_path());
    assert_equal(true, str_contains($rewritten, 'OnBootSec=30s'), 'set_push_timer_interval: actually rewrote OnBootSec= in the unit file');
    assert_equal(true, str_contains($rewritten, 'OnUnitActiveSec=30s'), 'set_push_timer_interval: actually rewrote OnUnitActiveSec= in the unit file');
    assert_equal(true, str_contains($rewritten, 'Unit=csm-push-check.service'), 'set_push_timer_interval: leaves the rest of the unit file untouched');

    assert_equal(30, PushTimerService::get_push_timer_interval()['interval_seconds'], 'get_push_timer_interval: reflects the just-written value on the next read');

    @unlink(PushTimerService::push_timer_unit_path());

    // --- dispatch_push_action(): routes push_* actions, returns null (so
    // agent.php can fall through to dispatch_action()) for everything else ---

    assert_equal(null, dispatch_push_action(['action' => 'list']), 'dispatch_push_action: null for a non-push action, so agent.php falls through to dispatch_action()');

    $publicKeyResponse = dispatch_push_action(['action' => 'push_public_key']);
    assert_equal(true, $publicKeyResponse['ok'] ?? null, 'dispatch_push_action: push_public_key ok=true');
    assert_equal(true, $publicKeyResponse['configured'] ?? null, 'dispatch_push_action: push_public_key reports configured=true (VAPID keys are set in this test)');
    assert_equal($realVapidKeys['publicKey'], $publicKeyResponse['public_key'] ?? null, 'dispatch_push_action: push_public_key returns the actual configured key');

    $subscribeResponse = dispatch_push_action(['action' => 'push_subscribe', 'subscription' => $subA]);
    assert_equal(true, $subscribeResponse['ok'] ?? null, 'dispatch_push_action: push_subscribe accepts a well-formed subscription');
    assert_equal(1, count(PushSubscriptionStore::read_push_subscriptions()), 'dispatch_push_action: push_subscribe actually stored it');

    $malformedSubscribeResponse = dispatch_push_action(['action' => 'push_subscribe', 'subscription' => ['endpoint' => 'no-keys-here']]);
    assert_equal(false, $malformedSubscribeResponse['ok'] ?? null, 'dispatch_push_action: push_subscribe rejects a malformed subscription');

    $missingSubscribeResponse = dispatch_push_action(['action' => 'push_subscribe']);
    assert_equal(false, $missingSubscribeResponse['ok'] ?? null, 'dispatch_push_action: push_subscribe rejects a request with no subscription field at all');

    $unsubscribeResponse = dispatch_push_action(['action' => 'push_unsubscribe', 'endpoint' => $subA['endpoint']]);
    assert_equal(true, $unsubscribeResponse['ok'] ?? null, 'dispatch_push_action: push_unsubscribe ok=true');
    assert_equal(0, count(PushSubscriptionStore::read_push_subscriptions()), 'dispatch_push_action: push_unsubscribe actually removed it');
} finally {
    putenv('VAPID_PUBLIC_KEY');
    putenv('VAPID_PRIVATE_KEY');
    putenv('PUSH_SUBSCRIPTIONS_FILE');
    putenv('PUSH_STATE_FILE');
    putenv('PUSH_CHECK_STATUS_FILE');
    putenv('PUSH_TIMER_UNIT_PATH');
    putenv('PUSH_TIMER_UNIT_NAME');
    array_map('unlink', glob("{$fixtureDir}/*") ?: []);
    @rmdir($fixtureDir);
}

test_exit();
