<?php
declare(strict_types=1);

$GLOBALS['__csm_test_failures'] = 0;

function assert_true(bool $condition, string $message): void
{
    if ($condition) {
        fwrite(STDOUT, "  PASS: {$message}\n");
        return;
    }

    $GLOBALS['__csm_test_failures']++;
    fwrite(STDOUT, "  FAIL: {$message}\n");
}

function assert_equal(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) {
        assert_true(true, $message);
        return;
    }

    assert_true(
        false,
        "{$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
    );
}

function assert_contains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        assert_true(true, $message);
        return;
    }

    assert_true(false, "{$message} (expected to contain \"{$needle}\")");
}

/**
 * Call at the end of every test_*.php file; the exit code is how
 * tests/run.sh knows whether that file passed.
 */
function test_exit(): never
{
    exit($GLOBALS['__csm_test_failures'] > 0 ? 1 : 0);
}
