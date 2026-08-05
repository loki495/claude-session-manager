<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;

/**
 * Two shared guard helpers, replacing the two request-guard blocks that
 * used to be repeated near-verbatim across the old flat entry-point files.
 * Deliberately plain method calls, not a middleware pipeline - each
 * controller method calls the one it needs as its first line.
 *
 * Not every controller method uses either of these - BrowseController's
 * (old browse.php never called start_app_session() at all) and the
 * full-page renderers/redirects (DashboardController::index()/
 * handleAction(), SessionController::show() - never JSON, never a 405)
 * inline their own AuthService calls instead. That's preserved,
 * pre-existing behavior, not an oversight.
 */
abstract class Controller
{
    /**
     * Shared by the ten mutating-POST-JSON endpoints. Also relied on to
     * produce the historical 405 for a GET to one of these paths -
     * routes.php registers GET *and* POST to the same method for exactly
     * that reason; the router itself never rejects the GET before
     * reaching here.
     */
    protected function require_post_json(): void
    {
        AuthService::start_app_session();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'POST required']);
            exit;
        }

        if (!AuthService::same_origin_or_no_origin()) {
            http_response_code(403);
            echo "Rejected: cross-origin request.";
            exit;
        }

        AuthService::require_csrf();

        header('Content-Type: application/json');
    }

    /** Shared by the six read-only GET-JSON endpoints. */
    protected function start_readonly_json(): void
    {
        AuthService::start_app_session();
        header('Cache-Control: no-store');
        header('Content-Type: application/json');
    }
}
