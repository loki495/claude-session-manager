<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Detects a blocked `question` tool prompt in an OpenCode TUI pane capture.
 * This is the fallback when the DB poll (find_pending_question) doesn't
 * cover a prompt shape — the live pane does show opencode's question when
 * blocked (verified live 2026-08-25: "↑↓ select  enter submit  esc dismiss"
 * footer with numbered "1. Public, push now" options), unlike the idle
 * blank pane.
 *
 * Shape is distinct from Claude Code's PromptParser (which keys off "❯ N."
 * cursor + "Do you trust..." / "Do you want to proceed?") and Antigravity's
 * (which keys off "Do you want to proceed?" + 4 numbered options) — opencode
 * uses "↑↓ select" footer and may include command previews (gh repo create ...)
 * as option detail lines.
 */
class OpenCodePromptParser
{
    /**
     * @return array{question:string, context:string, options:array<int, array{number:int, label:string}>, multi_question:bool, tool_name?:string}|null
     */
    public static function parse_blocking_prompt(string $paneContent): ?array
    {
        // Must have the opencode TUI question footer — without it, this is not
        // a question prompt, just idle output (and idle capture is blank anyway).
        if (strpos($paneContent, 'enter submit') === false && strpos($paneContent, 'select') === false) {
            // Also check for the arrow glyph variant
            if (strpos($paneContent, '↑↓') === false && strpos($paneContent, 'select') === false) {
                return null;
            }
        }

        // Must have at least one numbered option like "1. Public, push now"
        if (preg_match('/^\s*\d+\.\s+.+/m', $paneContent) !== 1) {
            return null;
        }

        $lines = explode("\n", $paneContent);
        $options = [];
        $question = null;
        $contextLines = [];

        // Find the question line: nearest non-empty line before the first numbered option
        $firstOptionIdx = null;
        foreach ($lines as $idx => $line) {
            if (preg_match('/^\s*(\d+)\.\s+(.+)/', $line, $m) === 1) {
                $firstOptionIdx = $idx;
                break;
            }
        }

        if ($firstOptionIdx === null) {
            return null;
        }

        // Collect options
        for ($i = $firstOptionIdx; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (preg_match('/^\s*(\d+)\.\s+(.+)/', $line, $m) === 1) {
                $options[] = ['number' => (int)$m[1], 'label' => trim($m[2])];
            } elseif (preg_match('/^\s*\d+\.\s+/', $line) === 0 && trim($line) !== '' && strpos($line, '↑↓') === false && strpos($line, 'enter submit') === false && strpos($line, 'esc dismiss') === false) {
                // Detail line for previous option (like "gh repo create ...") — append to last option's label if not already there, or treat as context?
                // Keep it simple: if it's indented and not a footer, it's option detail — skip for label but could be context
                if (!empty($options)) {
                    // Don't add detail lines as separate options, just keep the short label
                    continue;
                }
            }

            // Stop at footer
            if (strpos($line, '↑↓') !== false || strpos($line, 'enter submit') !== false) {
                break;
            }
        }

        if ($options === []) {
            return null;
        }

        // Question: nearest non-empty, non-option line before first option, not too far
        for ($i = $firstOptionIdx - 1; $i >= 0 && $firstOptionIdx - $i <= 10; $i--) {
            $line = trim($lines[$i]);
            if ($line !== '' && !preg_match('/^\s*\d+\./', $line) && strpos($line, '┃') === false) {
                // Skip purely decorative lines
                if (str_repeat('─', 10) === substr($line, 0, 10)) {
                    continue;
                }
                $question = $line;
                break;
            }
        }

        // Context: a few lines before question for header
        $contextStart = max(0, ($firstOptionIdx - 6));
        for ($i = $contextStart; $i < $firstOptionIdx; $i++) {
            $line = trim($lines[$i]);
            if ($line !== '' && strpos($line, '↑↓') === false) {
                $contextLines[] = $line;
            }
        }

        $context = implode("\n", $contextLines);

        // If question still null, use first context line or generic
        if ($question === null || $question === '') {
            $question = $contextLines[0] ?? 'Waiting on input';
        }

        return [
            'question' => $question,
            'context' => $context,
            'options' => $options,
            'multi_question' => false,
            'tool_name' => 'question',
        ];
    }
}
