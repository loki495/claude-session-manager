<?php
declare(strict_types=1);

/**
 * Exercises the Web Push logic in host-agent/lib/Push.php - subscription
 * storage, session-state transition detection, and dispatch_push_action()
 * - against isolated fixture paths, never the real subscription/state
 * files. A real send_push_notification() call is only ever exercised
 * against a guaranteed-closed local port (127.0.0.1:1), never a real push
 * service - see the "real send attempt" section below for why that's
 * still a meaningful test without actually delivering anywhere.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Push.php';

const REAL_PUSH_SUBSCRIPTIONS_FILE = '/home/andres/www/claude-session-manager/host-agent/state/push-subscriptions.json';
const REAL_PUSH_STATE_FILE = '/home/andres/www/claude-session-manager/host-agent/state/push-session-state.json';

$fixtureDir = sys_get_temp_dir() . '/csm-test-push-' . bin2hex(random_bytes(4));
mkdir($fixtureDir, 0700, true);

putenv('PUSH_SUBSCRIPTIONS_FILE=' . $fixtureDir . '/push-subscriptions.json');
putenv('PUSH_STATE_FILE=' . $fixtureDir . '/push-session-state.json');

if (push_subscriptions_file() === REAL_PUSH_SUBSCRIPTIONS_FILE || push_state_file() === REAL_PUSH_STATE_FILE) {
    fwrite(STDERR, "REFUSING TO RUN: push subscription/state files still resolve to the real ones.\n");
    exit(1);
}

try {
    // --- push_configured(): false until both VAPID keys are set ---

    putenv('VAPID_PUBLIC_KEY');
    putenv('VAPID_PRIVATE_KEY');
    assert_equal(false, push_configured(), 'push_configured: false with no VAPID keys set (fresh checkout default)');

    putenv('VAPID_PUBLIC_KEY=fake-public-key');
    assert_equal(false, push_configured(), 'push_configured: still false with only the public key set');

    putenv('VAPID_PRIVATE_KEY=fake-private-key');
    assert_equal(true, push_configured(), 'push_configured: true once both keys are set');

    // --- read/write/add/remove_push_subscription(): round-trip, dedupe by endpoint ---

    assert_equal([], read_push_subscriptions(), 'read_push_subscriptions: empty when no file exists yet');

    $subA = ['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'p256dh-a', 'auth' => 'auth-a']];
    $subB = ['endpoint' => 'https://push.example/b', 'keys' => ['p256dh' => 'p256dh-b', 'auth' => 'auth-b']];

    assert_true(add_push_subscription($subA), 'add_push_subscription: accepts a well-formed subscription');
    assert_true(add_push_subscription($subB), 'add_push_subscription: accepts a second, different subscription');
    assert_equal(2, count(read_push_subscriptions()), 'add_push_subscription: both subscriptions stored');

    assert_equal(false, add_push_subscription(['endpoint' => 'https://push.example/c']), 'add_push_subscription: rejects a subscription missing keys');
    assert_equal(false, add_push_subscription(['keys' => ['p256dh' => 'x', 'auth' => 'y']]), 'add_push_subscription: rejects a subscription missing an endpoint');
    assert_equal(2, count(read_push_subscriptions()), 'add_push_subscription: a rejected subscription is not stored');

    // Resubscribing under the SAME endpoint with new keys replaces, not duplicates -
    // this is exactly what the frontend's resubscribe-on-every-open call relies on.
    $subAUpdated = ['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'p256dh-a-NEW', 'auth' => 'auth-a-NEW']];
    add_push_subscription($subAUpdated);
    $stored = read_push_subscriptions();
    assert_equal(2, count($stored), 'add_push_subscription: resubscribing under the same endpoint does not duplicate');
    $storedA = array_values(array_filter($stored, fn(array $s) => $s['endpoint'] === 'https://push.example/a'))[0] ?? null;
    assert_equal('p256dh-a-NEW', $storedA['keys']['p256dh'] ?? null, 'add_push_subscription: resubscribing under the same endpoint updates the stored keys');

    remove_push_subscription('https://push.example/a');
    $afterRemove = read_push_subscriptions();
    assert_equal(1, count($afterRemove), 'remove_push_subscription: removes exactly the matching subscription');
    assert_equal('https://push.example/b', $afterRemove[0]['endpoint'] ?? null, 'remove_push_subscription: the other subscription survives');

    remove_push_subscription('https://push.example/b');
    assert_equal([], read_push_subscriptions(), 'remove_push_subscription: file is empty after removing the last subscription');

    // --- push_session_state(): classification used for transition detection ---

    assert_equal('blocked', push_session_state(['blocked_reason' => 'Do you want to proceed?', 'working' => false]), 'push_session_state: blocked_reason present -> blocked, regardless of working');
    assert_equal('blocked', push_session_state(['blocked_reason' => 'Do you want to proceed?', 'working' => true]), 'push_session_state: blocked_reason wins even if working is also somehow true');
    assert_equal('working', push_session_state(['blocked_reason' => null, 'working' => true]), 'push_session_state: working (not blocked) -> working');
    assert_equal('idle', push_session_state(['blocked_reason' => null, 'working' => false]), 'push_session_state: neither blocked nor working -> idle');
    assert_equal('idle', push_session_state([]), 'push_session_state: missing fields default to idle, not a crash');

    // --- check_and_send_pushes(): transition detection, with zero
    // subscriptions configured so no real send is ever attempted here -
    // see the "real send attempt" section below for that part. ---

    assert_equal(['ok' => false, 'notified' => [], 'pruned' => 0], (function () {
        putenv('VAPID_PUBLIC_KEY');
        putenv('VAPID_PRIVATE_KEY');
        $result = check_and_send_pushes([['name' => 'cc-x', 'blocked_reason' => 'hi', 'working' => false]]);
        putenv('VAPID_PUBLIC_KEY=fake-public-key');
        putenv('VAPID_PRIVATE_KEY=fake-private-key');
        return $result;
    })(), 'check_and_send_pushes: a harmless no-op when VAPID keys are not configured');

    @unlink(push_state_file());

    $first = check_and_send_pushes([
        ['name' => 'cc-blocked', 'blocked_reason' => 'Proceed?', 'working' => false],
        ['name' => 'cc-idle', 'blocked_reason' => null, 'working' => false],
    ]);
    assert_equal(['cc-blocked'], $first['notified'], 'check_and_send_pushes: a session already blocked on the very first check counts as a fresh transition (no prior state on record)');

    $second = check_and_send_pushes([
        ['name' => 'cc-blocked', 'blocked_reason' => 'Proceed?', 'working' => false],
        ['name' => 'cc-idle', 'blocked_reason' => null, 'working' => false],
    ]);
    assert_equal([], $second['notified'], 'check_and_send_pushes: still blocked on the next check -> not notified again (same prompt, not a new one)');

    $third = check_and_send_pushes([
        ['name' => 'cc-blocked', 'blocked_reason' => null, 'working' => false],
        ['name' => 'cc-idle', 'blocked_reason' => 'A new question', 'working' => false],
    ]);
    assert_equal(['cc-idle'], $third['notified'], 'check_and_send_pushes: cc-idle transitioning into blocked is notified; cc-blocked resolving is not (that\'s not a "new prompt" event)');

    $fourth = check_and_send_pushes([
        ['name' => 'cc-blocked', 'blocked_reason' => 'Proceed again?', 'working' => false],
    ]);
    assert_equal(['cc-blocked'], $fourth['notified'], 'check_and_send_pushes: transitioning back into blocked after having resolved counts as a fresh transition again');

    // --- push_notification_title(): prefers the real title, falls back
    // to something friendlier than the raw cc-YYYYMMDD-HHMM name when a
    // session hits a prompt before Claude Code has set one yet ---

    assert_equal('Fix the login bug', push_notification_title(['name' => 'cc-20260101-1200', 'title' => 'Fix the login bug', 'workdir' => '/home/andres/www/demo']), 'push_notification_title: prefers the real title when present');
    assert_equal('demo-project', push_notification_title(['name' => 'cc-20260101-1200', 'title' => null, 'workdir' => '/home/andres/www/demo-project']), 'push_notification_title: falls back to the workdir basename when no title is set yet');
    assert_equal('cc-20260101-1200', push_notification_title(['name' => 'cc-20260101-1200', 'title' => null, 'workdir' => null]), 'push_notification_title: falls back to the raw session name as a last resort');
    assert_equal('Claude session', push_notification_title([]), 'push_notification_title: a completely empty session still returns something, not a crash');
    assert_equal('Fix the login bug', push_notification_title(['name' => 'cc-20260101-1200', 'title' => "\u{2733} Fix the login bug", 'workdir' => null]), 'push_notification_title: strips Claude Code\'s leading idle-title icon (e.g. U+2733), decorative in a terminal title bar but out of place in a phone notification');
    assert_equal('Fix the login bug', push_notification_title(['name' => 'cc-20260101-1200', 'title' => "\u{2728} Fix the login bug", 'workdir' => null]), 'push_notification_title: strips any leading Symbol-Other (\\p{So}) glyph, not just the one specific icon seen live');
    assert_equal('No icon here', push_notification_title(['name' => 'cc-20260101-1200', 'title' => 'No icon here', 'workdir' => null]), 'push_notification_title: a plain title with no leading icon is untouched');

    // --- push_finished_body(): the real reply text (truncated), or a generic fallback ---

    assert_equal(
        'Found it: the redirect URL was hardcoded.',
        push_finished_body(['role' => 'assistant', 'blocks' => [['kind' => 'text', 'text' => 'Found it: the redirect URL was hardcoded.']]]),
        'push_finished_body: uses the real assistant reply text'
    );
    $longReply = str_repeat('a', 200);
    $truncated = push_finished_body(['role' => 'assistant', 'blocks' => [['kind' => 'text', 'text' => $longReply]]]);
    assert_equal(141, mb_strlen($truncated), 'push_finished_body: truncates a long reply to 140 chars + ellipsis, same convention as last_message_preview_html()');
    assert_equal('Finished - no input needed', push_finished_body(null), 'push_finished_body: no last message at all -> generic fallback');
    assert_equal('Finished - no input needed', push_finished_body(['role' => 'user', 'blocks' => [['kind' => 'text', 'text' => 'irrelevant']]]), 'push_finished_body: a non-assistant last message -> generic fallback (not the user\'s own prior message)');
    assert_equal('Finished - no input needed', push_finished_body(['role' => 'assistant', 'blocks' => [['kind' => 'tool_use', 'text' => 'tool: Bash - command: ls']]]), 'push_finished_body: an assistant turn with only tool calls, no closing text -> generic fallback');

    // --- push_permission_body()/push_blocked_body(): a permission prompt's
    // push body shows the real command/action, not the generic pane-scraped
    // "do you want to proceed?" question - an AskUserQuestion prompt keeps
    // showing the real question text as before ---

    assert_equal('npm test', push_permission_body('Bash', ['command' => 'npm test']), 'push_permission_body: Bash shows the real command');
    assert_equal('Run a Bash command', push_permission_body('Bash', []), 'push_permission_body: Bash with no command -> generic fallback');
    assert_equal('Write /tmp/foo.txt', push_permission_body('Write', ['file_path' => '/tmp/foo.txt', 'content' => 'irrelevant for the push body']), 'push_permission_body: Write shows the path, not the full file content');
    assert_equal('Edit /tmp/foo.txt', push_permission_body('Edit', ['file_path' => '/tmp/foo.txt', 'old_string' => 'a', 'new_string' => 'b']), 'push_permission_body: Edit shows the path');
    assert_equal('Run WebFetch', push_permission_body('WebFetch', ['url' => 'https://example.com']), 'push_permission_body: an unrecognized tool falls back to "Run <tool>"');
    $longCommand = str_repeat('a', 200);
    assert_equal(141, mb_strlen(push_permission_body('Bash', ['command' => $longCommand])), 'push_permission_body: a long command is truncated the same as push_finished_body');

    assert_equal(
        'npm test',
        push_blocked_body(['blocked_reason' => 'Do you want to proceed?', 'prompt_tool_name' => 'Bash', 'prompt_tool_input' => ['command' => 'npm test']]),
        'push_blocked_body: a permission prompt (matched pending tool) shows the command, not the generic question'
    );
    assert_equal(
        'Which color do you prefer?',
        push_blocked_body(['blocked_reason' => 'Which color do you prefer?', 'prompt_tool_name' => 'AskUserQuestion', 'prompt_tool_input' => ['questions' => []]]),
        'push_blocked_body: an AskUserQuestion prompt keeps showing the real question text'
    );
    assert_equal(
        'Do you trust the files in this folder?',
        push_blocked_body(['blocked_reason' => 'Do you trust the files in this folder?', 'prompt_tool_name' => null, 'prompt_tool_input' => null]),
        'push_blocked_body: no matched pending tool at all (trust dialog, stale/missing PreToolUse record) falls back to the pane-scraped question'
    );
    assert_equal(
        'Waiting on input',
        push_blocked_body([]),
        'push_blocked_body: a completely empty session still returns something, not a crash'
    );

    // --- check_and_send_pushes(): the "finished a long task" notification
    // - a genuinely new case (previously ZERO notification coverage for a
    // session that finishes without ever needing input at all) ---

    @unlink(push_state_file());
    putenv('PUSH_MIN_WORKING_SECONDS_FOR_FINISH_NOTIFY=60');

    $t0 = 1_000_000;
    $workingStart = check_and_send_pushes([['name' => 'cc-working', 'blocked_reason' => null, 'working' => true]], $t0);
    assert_equal([], $workingStart['notified'], 'check_and_send_pushes: starting to work is never itself notification-worthy');

    $stillWorkingSoon = check_and_send_pushes([['name' => 'cc-working', 'blocked_reason' => null, 'working' => true]], $t0 + 10);
    assert_equal([], $stillWorkingSoon['notified'], 'check_and_send_pushes: still working 10s later - no notification yet, still working');

    $finishedTooSoon = check_and_send_pushes([['name' => 'cc-working', 'blocked_reason' => null, 'working' => false]], $t0 + 15);
    assert_equal([], $finishedTooSoon['notified'], 'check_and_send_pushes: finished after only 15s of work - below the 60s threshold, not notified (avoids noise for quick replies)');

    @unlink(push_state_file());
    $workingStart2 = check_and_send_pushes([['name' => 'cc-long-task', 'blocked_reason' => null, 'working' => true]], $t0);
    assert_equal([], $workingStart2['notified'], 'check_and_send_pushes (long task): starting to work is never itself notification-worthy');

    $finishedLongTask = check_and_send_pushes([[
        'name' => 'cc-long-task',
        'blocked_reason' => null,
        'working' => false,
        'title' => 'Refactor the auth module',
        'last_message' => ['role' => 'assistant', 'blocks' => [['kind' => 'text', 'text' => 'Done - all tests pass.']]],
    ]], $t0 + 90);
    assert_equal(['cc-long-task'], $finishedLongTask['notified'], 'check_and_send_pushes: finished after 90s of continuous work - above the 60s threshold, notified');

    // Once notified, going idle -> idle again (nothing changed) must not re-notify.
    $stillIdleAfter = check_and_send_pushes([['name' => 'cc-long-task', 'blocked_reason' => null, 'working' => false]], $t0 + 100);
    assert_equal([], $stillIdleAfter['notified'], 'check_and_send_pushes: still idle on the next check -> not notified again');

    // A blocked prompt appearing takes priority over (and is a completely
    // separate concern from) the finished-working case - both paths must
    // coexist correctly in the same pass.
    @unlink(push_state_file());
    check_and_send_pushes([['name' => 'cc-mixed', 'blocked_reason' => null, 'working' => true]], $t0);
    $mixedResult = check_and_send_pushes([
        ['name' => 'cc-mixed', 'blocked_reason' => 'A follow-up question', 'working' => false],
        ['name' => 'cc-other', 'blocked_reason' => null, 'working' => true],
    ], $t0 + 90);
    assert_equal(['cc-mixed'], $mixedResult['notified'], 'check_and_send_pushes: a session that started working then hit a prompt is notified for the blocked transition, not double-counted as "finished"');

    putenv('PUSH_MIN_WORKING_SECONDS_FOR_FINISH_NOTIFY');

    // --- send_push_notification(): a genuinely malformed VAPID key must
    // report failure, not crash the whole (unattended, systemd-timer-run)
    // process - found live: minishlink/web-push throws a hard
    // ErrorException for this, not a normal failed-report return. ---

    $malformedKeySubscription = ['endpoint' => 'http://127.0.0.1:1/nothing-listens-here', 'keys' => ['p256dh' => 'x', 'auth' => 'y']];
    $malformedKeyResult = send_push_notification($malformedKeySubscription, 'Title', 'Body');
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
    $sendResult = send_push_notification($unreachableSubscription, 'Title', 'Body');
    assert_equal(false, $sendResult['ok'], 'send_push_notification: a real (failed) send against an unreachable endpoint reports ok=false, not an uncaught exception');

    add_push_subscription($unreachableSubscription);
    @unlink(push_state_file());
    $withRealSubscriber = check_and_send_pushes([['name' => 'cc-real-send', 'blocked_reason' => 'Proceed?', 'working' => false]]);
    assert_equal(['cc-real-send'], $withRealSubscriber['notified'], 'check_and_send_pushes: still reports the transition even though the actual send to the one subscriber failed');
    remove_push_subscription($unreachableSubscription['endpoint']);

    // --- dispatch_push_action(): routes push_* actions, returns null (so
    // agent.php can fall through to dispatch_action()) for everything else ---

    assert_equal(null, dispatch_push_action(['action' => 'list']), 'dispatch_push_action: null for a non-push action, so agent.php falls through to dispatch_action()');

    $publicKeyResponse = dispatch_push_action(['action' => 'push_public_key']);
    assert_equal(true, $publicKeyResponse['ok'] ?? null, 'dispatch_push_action: push_public_key ok=true');
    assert_equal(true, $publicKeyResponse['configured'] ?? null, 'dispatch_push_action: push_public_key reports configured=true (VAPID keys are set in this test)');
    assert_equal($realVapidKeys['publicKey'], $publicKeyResponse['public_key'] ?? null, 'dispatch_push_action: push_public_key returns the actual configured key');

    $subscribeResponse = dispatch_push_action(['action' => 'push_subscribe', 'subscription' => $subA]);
    assert_equal(true, $subscribeResponse['ok'] ?? null, 'dispatch_push_action: push_subscribe accepts a well-formed subscription');
    assert_equal(1, count(read_push_subscriptions()), 'dispatch_push_action: push_subscribe actually stored it');

    $malformedSubscribeResponse = dispatch_push_action(['action' => 'push_subscribe', 'subscription' => ['endpoint' => 'no-keys-here']]);
    assert_equal(false, $malformedSubscribeResponse['ok'] ?? null, 'dispatch_push_action: push_subscribe rejects a malformed subscription');

    $missingSubscribeResponse = dispatch_push_action(['action' => 'push_subscribe']);
    assert_equal(false, $missingSubscribeResponse['ok'] ?? null, 'dispatch_push_action: push_subscribe rejects a request with no subscription field at all');

    $unsubscribeResponse = dispatch_push_action(['action' => 'push_unsubscribe', 'endpoint' => $subA['endpoint']]);
    assert_equal(true, $unsubscribeResponse['ok'] ?? null, 'dispatch_push_action: push_unsubscribe ok=true');
    assert_equal(0, count(read_push_subscriptions()), 'dispatch_push_action: push_unsubscribe actually removed it');
} finally {
    putenv('VAPID_PUBLIC_KEY');
    putenv('VAPID_PRIVATE_KEY');
    putenv('PUSH_SUBSCRIPTIONS_FILE');
    putenv('PUSH_STATE_FILE');
    array_map('unlink', glob("{$fixtureDir}/*") ?: []);
    @rmdir($fixtureDir);
}

test_exit();
