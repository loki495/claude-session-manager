<?php
declare(strict_types=1);

/**
 * Exercises the file-upload logic in host-agent/lib/Sessions.php
 * (sanitize/unique filename helpers, save/list/delete/delete_all,
 * and dispatch_action() wiring) against a fixture SIDECAR_DIR and a
 * throwaway workdir under the system temp dir - never a real project.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;

const REAL_SIDECAR_DIR = '/run/user/1000/csm-sessions';

if (Config::sidecar_dir() === REAL_SIDECAR_DIR) {
    fwrite(STDERR, "REFUSING TO RUN: SIDECAR_DIR resolves to the real one. Check tests/.env.testing.\n");
    exit(1);
}

$fixtureWorkdir = sys_get_temp_dir() . '/csm-test-uploads-' . bin2hex(random_bytes(4));
mkdir($fixtureWorkdir, 0700, true);

$sessionName = 'cc-test-uploads-' . bin2hex(random_bytes(4));
write_sidecar($sessionName, ['workdir' => $fixtureWorkdir, 'spawned_at' => time(), 'claude_session_id' => null]);

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $full = $dir . '/' . $entry;
        is_dir($full) ? rrmdir($full) : @unlink($full);
    }

    @rmdir($dir);
}

try {
    // --- sanitize_upload_filename(): strips path components, control
    // chars, and leading dots down to a safe basename ---

    assert_equal('photo.jpg', sanitize_upload_filename('photo.jpg'), 'sanitize_upload_filename: plain filename untouched');
    assert_equal('passwd', sanitize_upload_filename('../../etc/passwd'), 'sanitize_upload_filename: strips directory traversal down to the basename');
    assert_equal('passwd', sanitize_upload_filename('/etc/passwd'), 'sanitize_upload_filename: strips an absolute path down to the basename');
    assert_equal('hidden', sanitize_upload_filename('.hidden'), 'sanitize_upload_filename: strips a leading dot rather than creating an invisible dotfile, keeping the rest of the name');
    assert_equal('upload', sanitize_upload_filename('...'), 'sanitize_upload_filename: a name that\'s ONLY dots falls back to a generic name once fully stripped');
    assert_equal('upload', sanitize_upload_filename(''), 'sanitize_upload_filename: empty input falls back to a generic name');
    assert_equal('foobar.txt', sanitize_upload_filename("foo\x00bar.txt"), 'sanitize_upload_filename: strips control characters (e.g. a null byte)');

    // --- unique_upload_filename(): suffixes on collision, never overwrites ---

    assert_equal('photo.jpg', unique_upload_filename($fixtureWorkdir, 'photo.jpg'), 'unique_upload_filename: no collision -> name unchanged');
    file_put_contents($fixtureWorkdir . '/photo.jpg', 'existing');
    assert_equal('photo-1.jpg', unique_upload_filename($fixtureWorkdir, 'photo.jpg'), 'unique_upload_filename: collision -> numeric suffix before the extension');
    file_put_contents($fixtureWorkdir . '/photo-1.jpg', 'existing too');
    assert_equal('photo-2.jpg', unique_upload_filename($fixtureWorkdir, 'photo.jpg'), 'unique_upload_filename: keeps incrementing past multiple collisions');
    assert_equal('noext', unique_upload_filename($fixtureWorkdir, 'noext'), 'unique_upload_filename: a filename with no extension works too');
    unlink($fixtureWorkdir . '/photo.jpg');
    unlink($fixtureWorkdir . '/photo-1.jpg');

    // --- save_uploaded_file(): the real end-to-end write path ---

    $content = 'hello upload';
    $saved = save_uploaded_file($sessionName, 'note.txt', base64_encode($content));
    assert_equal(true, $saved['ok'] ?? null, 'save_uploaded_file: succeeds for a known session');
    assert_equal('note.txt', $saved['filename'] ?? null, 'save_uploaded_file: reports the (sanitized) filename actually used');
    assert_equal('.claude/uploads/note.txt', $saved['path'] ?? null, 'save_uploaded_file: reports the path relative to the project workdir, ready to drop into a compose message');
    assert_equal(strlen($content), $saved['size'] ?? null, 'save_uploaded_file: reports the real decoded size');
    assert_equal($content, file_get_contents($fixtureWorkdir . '/.claude/uploads/note.txt'), 'save_uploaded_file: the file on disk actually contains the real (decoded) content, not the base64 text');

    // .claude/ is NOT reliably already gitignored (confirmed live against
    // the real claude-session-manager repo itself while building this
    // feature) - a self-contained .gitignore inside the uploads dir is
    // what actually protects an upload from showing up in `git status`.
    assert_equal(true, is_file($fixtureWorkdir . '/.claude/uploads/.gitignore'), 'save_uploaded_file: creates a self-contained .gitignore in the uploads dir, protecting uploads regardless of the project\'s own .gitignore state');
    assert_equal("*\n", file_get_contents($fixtureWorkdir . '/.claude/uploads/.gitignore'), 'save_uploaded_file: the .gitignore excludes everything in the directory');

    $savedCollision = save_uploaded_file($sessionName, 'note.txt', base64_encode('second file'));
    assert_equal('note-1.txt', $savedCollision['filename'] ?? null, 'save_uploaded_file: a second upload with the same name gets suffixed, not overwritten');
    assert_equal($content, file_get_contents($fixtureWorkdir . '/.claude/uploads/note.txt'), 'save_uploaded_file: the original file is still intact after the collision');

    $unknownSession = save_uploaded_file('cc-does-not-exist', 'x.txt', base64_encode('x'));
    assert_equal(false, $unknownSession['ok'] ?? null, 'save_uploaded_file: fails for a session with no known workdir (no sidecar)');

    $malformed = save_uploaded_file($sessionName, 'bad.txt', 'not valid base64!!!');
    assert_equal(false, $malformed['ok'] ?? null, 'save_uploaded_file: rejects malformed base64 rather than writing garbage');

    $tooBig = save_uploaded_file($sessionName, 'big.bin', base64_encode(str_repeat('a', 1000)));
    putenv('MAX_UPLOAD_BYTES=100');
    $rejectedForSize = save_uploaded_file($sessionName, 'big2.bin', base64_encode(str_repeat('a', 1000)));
    putenv('MAX_UPLOAD_BYTES');
    assert_equal(true, $tooBig['ok'] ?? null, 'save_uploaded_file: a 1000-byte file is fine under the real (25MB) default limit');
    assert_equal(false, $rejectedForSize['ok'] ?? null, 'save_uploaded_file: rejects a file over an (artificially lowered, for this test) size limit');

    // --- list_uploaded_files(): sizes, total, newest-first ---

    $listed = list_uploaded_files($sessionName);
    assert_equal(true, $listed['ok'] ?? null, 'list_uploaded_files: ok for a known session');
    // big2.bin was correctly REJECTED above (over the artificially-lowered
    // limit), so only note.txt, note-1.txt, and big.bin actually exist on
    // disk - confirms list_uploaded_files() reflects real files, not save
    // attempts.
    assert_equal(3, count($listed['files'] ?? []), 'list_uploaded_files: lists exactly the files actually saved (note.txt, note-1.txt, big.bin) - not the rejected big2.bin, and not the internal .gitignore');
    assert_equal(false, in_array('.gitignore', array_column($listed['files'], 'name'), true), 'list_uploaded_files: never lists the internal .gitignore as if it were a real upload');
    $expectedTotal = strlen($content) + strlen('second file') + 1000;
    assert_equal($expectedTotal, $listed['total_size'] ?? null, 'list_uploaded_files: total_size is the real sum of every file\'s size (the .gitignore\'s own bytes are not counted)');

    $namesInOrder = array_column($listed['files'], 'name');
    assert_equal(true, array_search('big.bin', $namesInOrder, true) < array_search('note.txt', $namesInOrder, true), 'list_uploaded_files: newest file (big.bin, saved last) sorts before the oldest (note.txt, saved first)');

    $emptySessionName = 'cc-test-uploads-empty-' . bin2hex(random_bytes(4));
    $emptyWorkdir = sys_get_temp_dir() . '/csm-test-uploads-empty-' . bin2hex(random_bytes(4));
    mkdir($emptyWorkdir, 0700, true);
    write_sidecar($emptySessionName, ['workdir' => $emptyWorkdir, 'spawned_at' => time(), 'claude_session_id' => null]);
    $emptyListed = list_uploaded_files($emptySessionName);
    assert_equal(true, $emptyListed['ok'] ?? null, 'list_uploaded_files: ok=true even when the uploads dir has never been created yet');
    assert_equal([], $emptyListed['files'] ?? null, 'list_uploaded_files: empty list, not an error, when nothing has ever been uploaded');
    assert_equal(0, $emptyListed['total_size'] ?? null, 'list_uploaded_files: total_size 0 when empty');
    rrmdir($emptyWorkdir);

    // --- delete_uploaded_file(): real delete, path-traversal-safe ---

    $deleteResult = delete_uploaded_file($sessionName, 'note-1.txt');
    assert_equal(true, $deleteResult['ok'] ?? null, 'delete_uploaded_file: succeeds for a real file');
    assert_equal(false, file_exists($fixtureWorkdir . '/.claude/uploads/note-1.txt'), 'delete_uploaded_file: the file is actually gone from disk');

    $deleteMissing = delete_uploaded_file($sessionName, 'never-existed.txt');
    assert_equal(false, $deleteMissing['ok'] ?? null, 'delete_uploaded_file: fails for a nonexistent file rather than silently succeeding');

    $deleteTraversal = delete_uploaded_file($sessionName, '../../../../etc/hosts');
    assert_equal(false, $deleteTraversal['ok'] ?? null, 'delete_uploaded_file: refuses a path-traversal filename');
    assert_equal(true, file_exists('/etc/hosts'), 'delete_uploaded_file: /etc/hosts (sanity target) still exists - the traversal attempt genuinely did nothing');

    $deleteGitignore = delete_uploaded_file($sessionName, '.gitignore');
    assert_equal(false, $deleteGitignore['ok'] ?? null, 'delete_uploaded_file: refuses to delete the internal .gitignore directly');
    assert_equal(true, is_file($fixtureWorkdir . '/.claude/uploads/.gitignore'), 'delete_uploaded_file: the .gitignore genuinely survives the attempt');

    // --- delete_all_uploaded_files(): clears everything, reports a count ---

    $beforeDeleteAllCount = count(list_uploaded_files($sessionName)['files'] ?? []);
    $deleteAllResult = delete_all_uploaded_files($sessionName);
    assert_equal(true, $deleteAllResult['ok'] ?? null, 'delete_all_uploaded_files: ok=true');
    assert_equal($beforeDeleteAllCount, $deleteAllResult['deleted'] ?? null, 'delete_all_uploaded_files: reports exactly how many files it removed (not counting .gitignore)');
    assert_equal([], list_uploaded_files($sessionName)['files'] ?? null, 'delete_all_uploaded_files: the directory is genuinely empty of real uploads afterward');
    assert_equal(true, is_file($fixtureWorkdir . '/.claude/uploads/.gitignore'), 'delete_all_uploaded_files: the .gitignore protection survives a "delete all" - a follow-up save doesn\'t need to recreate it from an unprotected window');

    $deleteAllOnMissingDir = delete_all_uploaded_files($sessionName); // uploads dir itself still exists (now empty), re-running should still be a safe no-op
    assert_equal(true, $deleteAllOnMissingDir['ok'] ?? null, 'delete_all_uploaded_files: safe to call again on an already-empty directory');
    assert_equal(0, $deleteAllOnMissingDir['deleted'] ?? null, 'delete_all_uploaded_files: reports 0 deleted the second time');

    // --- dispatch_action(): wiring for all four new actions ---

    $saved2 = save_uploaded_file($sessionName, 'dispatch-test.txt', base64_encode('via dispatch'));
    assert_equal(true, $saved2['ok'] ?? null, 'setup for dispatch_action tests: direct save succeeded');

    $dispatchList = dispatch_action(['action' => 'list_uploaded_files', 'session' => $sessionName]);
    assert_equal(true, $dispatchList['ok'] ?? null, 'dispatch_action: list_uploaded_files routes correctly');
    assert_equal(1, count($dispatchList['files'] ?? []), 'dispatch_action: list_uploaded_files reflects real on-disk state');

    $dispatchSave = dispatch_action(['action' => 'save_uploaded_file', 'session' => $sessionName, 'filename' => 'via-dispatch.txt', 'content_base64' => base64_encode('hi')]);
    assert_equal(true, $dispatchSave['ok'] ?? null, 'dispatch_action: save_uploaded_file routes correctly');

    $dispatchDelete = dispatch_action(['action' => 'delete_uploaded_file', 'session' => $sessionName, 'filename' => 'via-dispatch.txt']);
    assert_equal(true, $dispatchDelete['ok'] ?? null, 'dispatch_action: delete_uploaded_file routes correctly');

    $dispatchDeleteAll = dispatch_action(['action' => 'delete_all_uploaded_files', 'session' => $sessionName]);
    assert_equal(true, $dispatchDeleteAll['ok'] ?? null, 'dispatch_action: delete_all_uploaded_files routes correctly');
    assert_equal([], dispatch_action(['action' => 'list_uploaded_files', 'session' => $sessionName])['files'] ?? null, 'dispatch_action: delete_all_uploaded_files via dispatch actually cleared everything');
} finally {
    rrmdir($fixtureWorkdir);
    @unlink(sidecar_path($sessionName));
}

test_exit();
