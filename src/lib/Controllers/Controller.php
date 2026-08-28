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
        AuthService::close_app_session();

        header('Content-Type: application/json');
    }

    /** Shared by the six read-only GET-JSON endpoints. */
    protected function start_readonly_json(): void
    {
        AuthService::start_app_session();
        AuthService::close_app_session();
        header('Cache-Control: no-store');
        header('Content-Type: application/json');
    }

    /**
     * Shared by every controller method that streams a host-agent binary-
     * file result (base64 `data` + `media_type` + `filename`) straight
     * through as the real HTTP response - session/archived attachments,
     * uploaded files, plan files. Images always render inline; everything
     * else downloads as an attachment, UNLESS $inlineText is set, in which
     * case text/* renders inline too - opt-in rather than universal so the
     * existing session/archived-attachment behavior (a text attachment
     * downloads, same as any other non-image) doesn't silently change:
     * only the new uploaded/plan-file "open in a new tab" links pass true,
     * since a downloaded plan/text file wouldn't be "viewed" at all.
     *
     * $immutable controls caching: true (session/archived attachments,
     * keyed by a file_uuid that never gets reused for different content)
     * is safe to cache hard. Uploaded/plan files can be overwritten in
     * place at the same filename, so those callers leave it false -
     * caching them immutably could keep showing stale content after an
     * edit/re-upload.
     *
     * @param array<string, mixed> $result
     */
    protected static function stream_binary_result(array $result, bool $immutable = false, bool $inlineText = false): void
    {
        if (!($result['ok'] ?? false)) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo (string)($result['message'] ?? 'File not found');

            return;
        }

        $data = base64_decode((string)($result['data'] ?? ''), true);

        if ($data === false) {
            http_response_code(502);
            header('Content-Type: text/plain');
            echo 'Malformed file data';

            return;
        }

        $mediaType = (string)($result['media_type'] ?? 'application/octet-stream');
        $inline = str_starts_with($mediaType, 'image/') || ($inlineText && str_starts_with($mediaType, 'text/'));

        header('Content-Type: ' . $mediaType);
        header('Cache-Control: ' . ($immutable ? 'private, max-age=86400, immutable' : 'private, no-store'));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . self::content_disposition_safe_filename((string)($result['filename'] ?? 'file')) . '"');
        echo $data;
    }

    /**
     * Strips characters that could break out of the quoted filename in a
     * Content-Disposition header (control chars, the closing quote itself)
     * - the filename ultimately comes from basename() of a real file on
     * disk, but that disk could in principle hold anything.
     */
    protected static function content_disposition_safe_filename(string $filename): string
    {
        $safe = preg_replace('/[\x00-\x1f"]/', '', $filename) ?? '';

        return $safe !== '' ? $safe : 'attachment';
    }
}
