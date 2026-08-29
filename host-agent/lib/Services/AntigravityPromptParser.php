<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Antigravity equivalent of PromptParser::parse_blocking_prompt() - unlike
 * Claude Code, Antigravity has no PermissionRequest-style hook at all (see
 * docs/antigravity-adapter-plan.md's Phase 3 research: confirmed live no
 * such second hook exists), so a blocked prompt can ONLY ever be detected
 * by reading the live pane, for every prompt shape, not just the two
 * carve-outs SessionService::build_session_entry() needs for Claude Code.
 *
 * Returns the SAME canonical shape PromptParser::parse_blocking_prompt()
 * does ({question, context, options, multi_question, is_folder_trust}) -
 * BlockedPromptView/session.js's rendering and PromptInteractionService::
 * answer_prompt()'s answering logic are both already generic over that
 * shape (numbered options, answered by sending the chosen digit), so
 * reusing it here needed no new UI or new answer-side wiring at all, just
 * this parser plus the small agent-branch in build_session_entry() and
 * answer_prompt()/send_escape() that calls it instead of Claude's own for
 * an antigravity session.
 *
 * Scoped to the one real prompt shape confirmed live 2026-08-24 (a
 * run_command tool-permission request) - Antigravity's confirmation menu
 * for a shell command:
 *   Requesting permission for:
 *      agy models
 *
 *   Do you want to proceed?
 *   > 1. Yes
 *     2. Yes, and always allow in this conversation for commands that start with 'agy'
 *     3. Yes, and always allow for commands that start with 'agy' (Persist to settings.json)
 *     4. No
 * Other tool types may render their own confirmation text differently
 * (not yet seen live) - this returns null for anything that doesn't match
 * this specific numbered-list-under-"Do you want to proceed?" shape,
 * same "don't guess" discipline as PromptParser's own trust-dialog
 * detection.
 */
class AntigravityPromptParser
{
    /**
     * @return array{question:string, context:string, options: array<int, array{number:int, label:string}>, multi_question:bool, is_folder_trust:bool}|null
     */
    public static function parse_blocking_prompt(string $paneContent): ?array
    {
        $lines = explode("\n", $paneContent);
        $questionIndex = null;

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (trim($lines[$i]) === 'Do you want to proceed?') {
                $questionIndex = $i;
                break;
            }
        }

        if ($questionIndex === null) {
            return null;
        }

        // A long option label (the command name is embedded in it, e.g.
        // "Yes, and always allow in this conversation for commands that
        // start with 'echo'") wraps across multiple printed lines -
        // confirmed live 2026-08-24, and NOT something tmux_capture_pane()'s
        // own -J line-rejoin flag can fix (that only rejoins a single
        // logical line the TERMINAL soft-wrapped for width, this is
        // Antigravity's own rendering choosing to print a label across
        // several lines) - so a non-blank line that isn't itself the start
        // of the NEXT numbered option is treated as a continuation of the
        // option currently being built, not the end of the list.
        $options = [];
        $number = 1;

        for ($i = $questionIndex + 1; $i < count($lines); $i++) {
            $trimmed = trim(ltrim($lines[$i], "> \t"));

            if (preg_match('/^' . $number . '\.\s*(.+)$/u', $trimmed, $matches)) {
                $options[] = ['number' => $number, 'label' => trim($matches[1])];
                $number++;
            } elseif ($options !== [] && $trimmed !== '') {
                $lastIndex = count($options) - 1;
                $options[$lastIndex]['label'] = trim($options[$lastIndex]['label'] . ' ' . $trimmed);
            } else {
                break;
            }
        }

        if ($options === []) {
            return null;
        }

        // "Requesting permission for:" plus whatever command/tool summary
        // follows, up to the blank line separating it from the question -
        // same "nearest non-blank content above" fallback discipline as
        // PromptParser::parse_blocking_prompt() uses for its own $context,
        // just walking up from a known line instead of a cursor marker.
        $contextLines = [];

        for ($i = $questionIndex - 1; $i >= 0 && count($contextLines) < PromptParser::BLOCKING_PROMPT_CONTEXT_WINDOW; $i--) {
            $trimmed = trim($lines[$i]);

            if ($trimmed === '' && $contextLines !== []) {
                break;
            }

            if ($trimmed === '') {
                continue;
            }

            array_unshift($contextLines, $trimmed);
        }

        return [
            'question' => 'Do you want to proceed?',
            'context' => implode("\n", $contextLines),
            'options' => $options,
            'multi_question' => false,
            'is_folder_trust' => false,
        ];
    }
}
