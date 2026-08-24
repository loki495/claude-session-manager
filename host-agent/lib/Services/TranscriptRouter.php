<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Routes a session id to the right transcript backend - TranscriptService
 * (Claude Code) or AntigravityTranscriptService - without threading an
 * $agent parameter through every SessionDetailService/SessionService call
 * site. Dispatch is by PATH SHAPE, not a passed-in agent id: the two
 * agents' transcript directories are structurally distinct
 * (~/.claude/projects/... vs ~/.gemini/antigravity-cli/brain/...), so
 * once find_transcript_path() has resolved a real path, that path alone
 * says which backend parsed it - no need to also know or pass the
 * session's `agent` sidecar column at each of the ~6 call sites this
 * replaces. See docs/antigravity-adapter-plan.md Phase 4.
 */
class TranscriptRouter
{
    /**
     * Tries Claude Code's own resolution first (a glob), then
     * Antigravity's (a direct deterministic path) - the two id spaces
     * are both v4-ish UUIDs with no realistic collision risk between
     * them, and only one will ever actually resolve to a real file for
     * any given id.
     */
    public static function find_transcript_path(string $sessionId): ?string
    {
        return TranscriptService::find_transcript_path($sessionId) ?? AntigravityTranscriptService::find_transcript_path($sessionId);
    }

    public static function is_antigravity_path(string $path): bool
    {
        return str_contains($path, '/antigravity-cli/brain/');
    }

    /**
     * @return array{ok:bool, entries:array<int, array>, next_before:?int, has_more:bool, message?:string}
     */
    public static function read_transcript_page(string $path, ?int $before, int $limit, bool $untilRealUserMessage = false): array
    {
        return self::is_antigravity_path($path)
            ? AntigravityTranscriptService::read_transcript_page($path, $before, $limit, $untilRealUserMessage)
            : TranscriptService::read_transcript_page($path, $before, $limit, $untilRealUserMessage);
    }

    /**
     * @return array{ok:bool, entries:array<int, array>, message?:string}
     */
    public static function read_transcript_page_since(string $path, int $afterLine, int $limit): array
    {
        return self::is_antigravity_path($path)
            ? AntigravityTranscriptService::read_transcript_page_since($path, $afterLine, $limit)
            : TranscriptService::read_transcript_page_since($path, $afterLine, $limit);
    }

    /**
     * @return array{ok:bool, message?:string, data?:string, media_type?:string, filename?:string, size?:int}
     */
    public static function read_attachment(string $path, int $line, string $fileUuid): array
    {
        return self::is_antigravity_path($path)
            ? AntigravityTranscriptService::read_attachment($path, $line, $fileUuid)
            : TranscriptService::read_attachment($path, $line, $fileUuid);
    }
}
