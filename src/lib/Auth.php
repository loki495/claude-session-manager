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

/* ---------- CSRF guards ---------- */
/* Two independent layers, both required on every state-changing POST:
   same_origin_or_no_origin() (a same-origin check, no token involved) plus
   the session-bound token pair below. Basic Auth is the real access
   control; these just block a stray cross-site form post from a page
   loaded in the same authenticated browser. */

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

/**
 * Starts (or resumes) the PHP session used for the CSRF token and flash
 * messages - session_start() itself is idempotent-safe to call more than
 * once (a second call is a silent no-op), but callers should still only
 * need to call this once per request, near the top before any output.
 */
function start_app_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/**
 * Returns this session's CSRF token, generating and stashing one on first
 * use. Call start_app_session() first.
 */
function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Rejects the request with 403 unless $_POST['csrf_token'] matches this
 * session's token. Call start_app_session() first.
 */
function require_csrf(): void
{
    $provided = (string)($_POST['csrf_token'] ?? '');
    $expected = (string)($_SESSION['csrf_token'] ?? '');

    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo "Rejected: missing or invalid CSRF token.";
        exit;
    }
}
