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
     * Antigravity's (a direct deterministic path), then OpenCode's
     * (a DB row check for ses_* shape) - the three id spaces are
     * distinct (UUID-with-dashes vs ses_* prefix) with no collision
     * risk, and only one will ever actually resolve for any given id.
     */
    public static function find_transcript_path(string $sessionId): ?string
    {
        return TranscriptService::find_transcript_path($sessionId)
            ?? AntigravityTranscriptService::find_transcript_path($sessionId)
            ?? OpenCodeTranscriptService::find_transcript_path($sessionId)
            ?? CodexTranscriptService::find_transcript_path($sessionId);
    }

    public static function is_antigravity_path(string $path): bool
    {
        return str_contains($path, '/antigravity-cli/brain/');
    }

    public static function is_opencode_path(string $path): bool
    {
        return OpenCodeTranscriptService::is_opencode_id($path);
    }

    public static function is_codex_path(string $path): bool
    {
        return CodexTranscriptService::is_codex_path($path);
    }

    /**
     * @return array{ok:bool, entries:array<int, array>, next_before:?int, has_more:bool, message?:string}
     */
    public static function read_transcript_page(string $path, ?int $before, int $limit, bool $untilRealUserMessage = false): array
    {
        if (self::is_opencode_path($path)) {
            return OpenCodeTranscriptService::read_transcript_page($path, $before, $limit, $untilRealUserMessage);
        }

        if (self::is_codex_path($path)) {
            return CodexTranscriptService::read_transcript_page($path, $before, $limit, $untilRealUserMessage);
        }

        return self::is_antigravity_path($path)
            ? AntigravityTranscriptService::read_transcript_page($path, $before, $limit, $untilRealUserMessage)
            : TranscriptService::read_transcript_page($path, $before, $limit, $untilRealUserMessage);
    }

    /**
     * @return array{ok:bool, entries:array<int, array>, message?:string}
     */
    public static function read_transcript_page_since(string $path, int $afterLine, int $limit): array
    {
        if (self::is_opencode_path($path)) {
            return OpenCodeTranscriptService::read_transcript_page_since($path, $afterLine, $limit);
        }

        if (self::is_codex_path($path)) {
            return CodexTranscriptService::read_transcript_page_since($path, $afterLine, $limit);
        }

        return self::is_antigravity_path($path)
            ? AntigravityTranscriptService::read_transcript_page_since($path, $afterLine, $limit)
            : TranscriptService::read_transcript_page_since($path, $afterLine, $limit);
    }

    /**
     * @return array{ok:bool, message?:string, data?:string, media_type?:string, filename?:string, size?:int}
     */
    public static function read_attachment(string $path, int $line, string $fileUuid): array
    {
        if (self::is_opencode_path($path)) {
            return OpenCodeTranscriptService::read_attachment($path, $line, $fileUuid);
        }

        if (self::is_codex_path($path)) {
            return CodexTranscriptService::read_attachment($path, $line, $fileUuid);
        }

        return self::is_antigravity_path($path)
            ? AntigravityTranscriptService::read_attachment($path, $line, $fileUuid)
            : TranscriptService::read_attachment($path, $line, $fileUuid);
    }
}
