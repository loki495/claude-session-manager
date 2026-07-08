<?php
declare(strict_types=1);

/**
 * Shared by every entry point under src/ (index.php, quota.php, ...) so
 * there's exactly one place enforcing Basic Auth - never copy-paste this
 * check into a new endpoint.
 */

function require_basic_auth(): void
{
    $expectedUser = getenv('BASIC_AUTH_USER');
    $expectedPass = getenv('BASIC_AUTH_PASS');

    if ($expectedUser === false || $expectedPass === false || $expectedUser === '' || $expectedPass === '') {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "Server misconfigured: BASIC_AUTH_USER / BASIC_AUTH_PASS are not set.";
        exit;
    }

    $providedUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $providedPass = $_SERVER['PHP_AUTH_PW'] ?? '';

    $ok = hash_equals($expectedUser, $providedUser) && hash_equals($expectedPass, $providedPass);

    if (!$ok) {
        header('WWW-Authenticate: Basic realm="Claude Session Manager"');
        http_response_code(401);
        echo "Authentication required.";
        exit;
    }
}

/* ---------- light CSRF guard ---------- */
/* No sessions/DB are used, so this is a same-origin check rather than a
   token. Basic Auth is the real access control; this just blocks a stray
   cross-site form post from a page loaded in the same authenticated browser. */

function same_origin_or_no_origin(): bool
{
    $source = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? null;

    if ($source === null) {
        return true;
    }

    $sourceHost = parse_url($source, PHP_URL_HOST);
    $sourcePort = parse_url($source, PHP_URL_PORT);
    $sourceAuthority = $sourcePort !== null ? "{$sourceHost}:{$sourcePort}" : $sourceHost;

    $host = $_SERVER['HTTP_HOST'] ?? null;

    return $sourceAuthority === $host || $sourceHost === $host;
}
