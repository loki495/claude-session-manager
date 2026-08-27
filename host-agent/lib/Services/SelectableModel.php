<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * The fixed 5-row vocabulary behind session.php's "Select model" dropdown
 * (Andres's own ask, 2026-08-24) - Default/Sonnet/Fable/Opus/Haiku, in the
 * EXACT order Claude Code's own `/model` picker renders them (verified live
 * against a real running session, same discipline PromptParser's own
 * key-sequence docblocks already use). PromptInteractionService::set_model() relies
 * on this order directly: the picker's cursor never wraps past its first
 * row (also verified live), so pressing Up enough times always lands on
 * row 1 regardless of where the cursor started, and Down (row - 1) times
 * from there reaches any target row deterministically - no need to read
 * the CURRENT model first the way set_mode()'s relative Shift+Tab cycling
 * does.
 */
class SelectableModel
{
    /**
     * Row order matches the real /model picker exactly. "Default" has no
     * raw model ID of its own (it resolves to whatever the account's
     * runtime default currently is) - family_from_raw_model() below can
     * never return it, only a caller picking it from the dropdown does.
     */
    public const PICKER_OPTIONS = [
        'default' => 'Default',
        'sonnet' => 'Sonnet',
        'fable' => 'Fable',
        'opus' => 'Opus',
        'haiku' => 'Haiku',
    ];

    /**
     * Maps a raw model ID off a transcript's own message.model field (e.g.
     * "claude-sonnet-5", or an older dated pin like
     * "claude-sonnet-4-5-20250929") to this app's own PICKER_OPTIONS key, by
     * family prefix - the exact version within a family isn't distinguished,
     * since the dropdown only ever offers a whole family at a time, same as
     * the real picker. Returns null for an unrecognized prefix rather than
     * guessing, same "don't guess" discipline as PermissionMode::
     * normalize_hook_permission_mode().
     */
    public static function family_from_raw_model(string $rawModel): ?string
    {
        foreach (['sonnet', 'opus', 'haiku', 'fable'] as $family) {
            if (str_starts_with($rawModel, "claude-{$family}")) {
                return $family;
            }
        }

        return null;
    }
}
