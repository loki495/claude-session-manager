<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Everything about reading Claude Code's own pane title and detecting/
 * parsing a blocking interactive prompt from a captured tmux pane's raw
 * text - the pane-scraping half of this app (as opposed to TmuxService,
 * which just runs tmux commands and hands back raw output).
 */
class PromptParser
{
    /**
     * Every mode Claude Code's own Shift+Tab cycle visits, in the exact order
     * it cycles through them, mapped to the exact phrase it prints in its
     * bottom status line for each - all confirmed live against a real running
     * session, not guessed. Three say "<mode> mode on"; "accept edits" is its
     * own inconsistency and just says "accept edits on" (no "mode") - caught
     * by testing against a real capture rather than a hand-written one, which
     * a plausible-looking regex-guess would have silently missed.
     */
    public const CLAUDE_CODE_MODE_STATUS_PHRASES = [
        'manual' => 'manual mode on',
        'accept edits' => 'accept edits on',
        'plan' => 'plan mode on',
        'auto' => 'auto mode on',
    ];

    /**
     * Fallback number of pane lines above the choice list to consider as
     * context, used only when no "●" tool-invocation marker (see
     * parse_blocking_prompt()) is found above the cursor - the trust dialog
     * is the one real prompt shape that has no such marker, and its own
     * explanation fits in ~8 lines. Andres reported a long command/file-
     * content preview showing truncated - found live: this used to be the
     * ONLY boundary, a fixed 15-line window regardless of how tall the actual
     * preview was, silently cutting off anything longer (a multi-line script,
     * a large file being written) with no visible sign anything was missing.
     */
    public const BLOCKING_PROMPT_CONTEXT_WINDOW = 15;

    /**
     * Matches one leading animated spinner glyph (plus trailing whitespace)
     * on Claude Code's pane title while actively working, e.g. "⠂ Fix login
     * bug" or "◐ Fix login bug". Deliberately matched by Unicode general
     * category (\p{So}, "Symbol, other") rather than a specific code-point
     * range: found live 2026-08-12 that Claude Code had switched its
     * spinner from Braille Patterns dots (U+2800-U+28FF, this app's
     * original and only match) to a rotating half-circle from the
     * Geometric Shapes block (U+25D0 ◐/◑/..., confirmed against a real
     * live pane title), silently breaking pane_title_is_working() -
     * working was always false, so the thinking indicator never showed.
     * \p{So} already covers both of those blocks plus Block Elements and
     * effectively every other symbol/dingbat-style spinner glyph set in
     * common use (verified: both the old braille dots and the new
     * half-circle are category So) - the actual fix for the root cause
     * (a hardcoded narrow range that breaks every time the CLI's spinner
     * style changes) rather than just widening it to also include this
     * one new range.
     */
    private const SPINNER_GLYPH_PATTERN = '/^\p{So}+\s*/u';

    /**
     * Claude Code sets the terminal title to a short description of the
     * current task, prefixed with an animated spinner glyph while actively
     * working - tmux captures this as pane_title via the standard OSC title
     * escape sequence, no special tmux config needed. Strips the spinner so
     * only the description remains; an empty/spinner-only title (nothing
     * set yet, or a non-Claude process) returns null so callers can fall
     * back to the session name.
     */
    public static function clean_pane_title(string $title): ?string
    {
        $stripped = preg_replace(self::SPINNER_GLYPH_PATTERN, '', $title);
        $title = trim($stripped ?? $title);

        return $title !== '' ? $title : null;
    }

    /**
     * True while Claude Code is actively working (thinking, streaming text,
     * running a tool) - the same animated spinner glyph clean_pane_title()
     * strips off is the live "is it doing something right now" signal, so a
     * caller that needs the presence rather than the cleaned title reads it
     * here instead of re-deriving it from the raw title itself.
     */
    public static function pane_title_is_working(string $title): bool
    {
        return preg_match(self::SPINNER_GLYPH_PATTERN, $title) === 1;
    }

    /**
     * Reads the current permission mode straight from Claude Code's own
     * bottom status line (e.g. "⏸ manual mode on · ← for agents" or "⏵⏵ auto
     * mode on (shift+tab to cycle) · ← for agents") - there's no other way to
     * learn it live short of parsing the same status bar a human would read.
     * Returns null if the session isn't currently showing that line at all
     * (e.g. it's showing a blocking prompt instead).
     */
    public static function parse_current_mode(string $paneContent): ?string
    {
        foreach (self::CLAUDE_CODE_MODE_STATUS_PHRASES as $mode => $phrase) {
            if (str_contains($paneContent, $phrase)) {
                return $mode;
            }
        }

        return null;
    }

    /**
     * True for a line that's purely decorative box-drawing/rule characters
     * (─│╭╮╰╯┌┐└┘┏┓┗┛━┃ etc.) and whitespace - carries no information of its
     * own, so it's dropped from context rather than shown as a bare line of
     * dashes.
     */
    public static function is_decorative_pane_line(string $line): bool
    {
        return trim($line) !== '' && preg_match('/^[\s\x{2500}-\x{257F}\x{2580}-\x{259F}]*$/u', $line) === 1;
    }

    /**
     * Claude Code renders every interactive choice it needs a human for -
     * the "Do you trust the files in this folder?" prompt on first launch in
     * a new directory, tool-permission approval, etc. - as a numbered option
     * list with a leading "❯" cursor on the selected line. That marker is
     * stable across prompt wording/versions, so it's used as the detection
     * signal rather than matching specific prompt text. Returns the nearest
     * question-like line above the option list (if one is found within a few
     * lines) as a short human-readable reason, or a generic fallback.
     *
     * $question used to require a line ending in "?" within a narrow window,
     * but real prompts wrap their question across lines (verified against a
     * live capture of Claude Code's own trust dialog, where the "?" lands
     * mid-line, not at the end of any single one) - it now looks for a "?"
     * anywhere in a line and truncates there, falling back to the nearest
     * non-blank context line, and only to a generic label if there's truly
     * nothing above the choice list at all.
     *
     * @return array{question:string, context:string, options: array<int, array{number:int, label:string}>, multi_question:bool, is_folder_trust:bool}|null
     */
    public static function parse_blocking_prompt(string $paneContent): ?array
    {
        $lines = explode("\n", $paneContent);
        $choiceIndex = null;

        // Scans from the bottom: the ❯ cursor only ever appears on one line
        // per active choice list, but if the pane's visible screen still
        // holds an earlier, already-resolved list above the current one, the
        // most recent one - furthest down - is the one actually still
        // waiting on input.
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (preg_match('/^\s*❯\s*\d+[.)]/u', $lines[$i]) === 1) {
                $choiceIndex = $i;
                break;
            }
        }

        if ($choiceIndex === null) {
            return null;
        }

        // Claude Code prefixes each tool invocation with a "● " marker line
        // (e.g. "● Bash(...)", verified against a real capture) - the actual
        // top of the block being approved, found by scanning up from the
        // choice cursor for the nearest one. Far more reliable than a fixed
        // window: it's exactly as tall as the real content, whether that's 3
        // lines or 300. Only falls back to BLOCKING_PROMPT_CONTEXT_WINDOW for
        // prompt shapes with no such marker (the trust dialog).
        //
        // The scan never crosses a decorative separator line looking for
        // one, PROVIDED a multi-question tab bar ("←  ☐ Color  ☐ Animal
        // ✔ Submit  →") was already seen between the choice list and that
        // separator (found live 2026-08-09) - Claude Code prefixes a plain
        // assistant TEXT message with "● " too, not just a tool invocation,
        // and a decorative rule directly above a reshown tab bar is exactly
        // the boundary Claude Code itself draws between that unrelated
        // preceding prose and the fresh prompt (verified against a real
        // capture: a reshown AskUserQuestion tab has no tool-invocation
        // marker of its own at all). Gated on the tab bar specifically,
        // not on ANY separator, because a genuine tool invocation ALSO
        // uses one internally, between its own "● ToolName(...)" marker
        // and its own detail box (e.g. "Bash command") - stopping there
        // too would cut a real permission prompt's context off right
        // before reaching its own marker (caught live by this file's own
        // long-command-preview test regressing when a first attempt at
        // this fix stopped at ANY separator unconditionally).
        $markerIndex = null;
        $separatorIndex = null;
        $sawTabBarBelow = false;

        for ($i = $choiceIndex - 1; $i >= 0; $i--) {
            if (preg_match('/^\s*●/u', $lines[$i]) === 1) {
                $markerIndex = $i;
                break;
            }

            if (self::is_decorative_pane_line($lines[$i])) {
                if ($sawTabBarBelow) {
                    $separatorIndex = $i;
                    break;
                }

                continue;
            }

            if (str_contains($lines[$i], '←') && str_contains($lines[$i], '→')) {
                $sawTabBarBelow = true;
            }
        }

        // A multi-question AskUserQuestion's "Submit" review tab recaps
        // EVERY answered question, each as its own "● " bullet (verified
        // live against a real capture: "Repo visibility ..." / "What
        // should the GitHub repo be named?", each followed by its own
        // indented "→ <answer>" line) - the nearest-● scan just above only
        // ever finds the LAST one, so context ended up missing every
        // earlier question's own bullet, and the tab's own "Review your
        // answers" header, entirely (found live 2026-08-09: Andres
        // reported what looked like a redundant second confirmation - it
        // was actually this incomplete capture of a multi-answer review
        // screen, showing only its final bullet + "Ready to submit your
        // answers?", which read as its own separate already-answered
        // question rather than part of the same review). Walk further up
        // past consecutive ●-bullet/→-answer/blank lines - the shape of
        // this tab specifically - and use "Review your answers" as the
        // real top of the block if found among them, extending one step
        // further to the tab bar itself ("←  ☒ Visibility  ☒ Repo name
        // ✔ Submit  →", verified live) when it's right above that header -
        // the multi_question detection below only ever looks WITHIN this
        // same context window, so without this the Submit tab specifically
        // would silently lose its own Prev/Next question navigation.
        if ($markerIndex !== null) {
            $reviewIndex = null;
            $sawReviewHeader = false;

            for ($i = $markerIndex - 1; $i >= 0; $i--) {
                if (str_contains($lines[$i], 'Review your answers')) {
                    $sawReviewHeader = true;
                    $reviewIndex = $i;

                    continue;
                }

                if ($sawReviewHeader && str_contains($lines[$i], '←') && str_contains($lines[$i], '→')) {
                    $reviewIndex = $i;

                    break;
                }

                if (preg_match('/^\s*(●|→)/u', $lines[$i]) === 1 || trim($lines[$i]) === '') {
                    continue;
                }

                break;
            }

            if ($reviewIndex !== null) {
                $markerIndex = $reviewIndex;
            }
        }

        // The fixed-window fallback must never reach past a decorative
        // separator either, same reasoning as the marker scan above - a
        // window that's wider than the gap to the boundary would otherwise
        // still sweep in unrelated preceding content the marker scan itself
        // was just kept out of.
        $windowFloor = $separatorIndex !== null ? $separatorIndex + 1 : 0;
        $start = $markerIndex !== null ? $markerIndex : max($windowFloor, $choiceIndex - self::BLOCKING_PROMPT_CONTEXT_WINDOW);
        $contextLines = array_map('rtrim', array_slice($lines, $start, $choiceIndex - $start));
        $contextLines = array_values(array_filter($contextLines, fn(string $l) => !self::is_decorative_pane_line($l)));

        while ($contextLines !== [] && trim($contextLines[0]) === '') {
            array_shift($contextLines);
        }

        while ($contextLines !== [] && trim(end($contextLines)) === '') {
            array_pop($contextLines);
        }

        $context = preg_replace('/\n{3,}/', "\n\n", implode("\n", $contextLines)) ?? implode("\n", $contextLines);

        // The question label groups $contextLines into paragraphs (consecutive
        // non-blank lines, joined with spaces) before picking one, rather than
        // searching line-by-line - real prompts wrap a single sentence across
        // several physical terminal lines (verified against a live capture of
        // the trust dialog: the "?" lands mid-line, with the rest of the
        // sentence continuing on the next one), so a per-line search would
        // either miss the question or truncate it mid-sentence. $context above
        // still preserves the original, unmerged line breaks for the full
        // verbatim display.
        $paragraphs = [];
        $current = [];

        foreach ($contextLines as $line) {
            if (trim($line) === '') {
                if ($current !== []) {
                    $paragraphs[] = trim(implode(' ', $current));
                    $current = [];
                }

                continue;
            }

            $current[] = trim($line);
        }

        if ($current !== []) {
            $paragraphs[] = trim(implode(' ', $current));
        }

        $question = null;

        for ($i = count($paragraphs) - 1; $i >= 0; $i--) {
            if (str_contains($paragraphs[$i], '?')) {
                $question = $paragraphs[$i];
                break;
            }
        }

        if ($question === null && $paragraphs !== []) {
            $question = end($paragraphs);
        }

        // Walks until the first blank line, not the first non-matching one -
        // a multi-question AskUserQuestion prompt (verified against a real,
        // live capture) interleaves each numbered option with its own
        // indented description line, plus a purely decorative divider before
        // a trailing "Chat about this" option. Neither of those matches the
        // option pattern, but neither should end the list early either - only
        // a genuine blank line (the real end of the choice block in every
        // captured prompt shape so far) does.
        $options = [];

        for ($i = $choiceIndex; $i < count($lines); $i++) {
            if (trim($lines[$i]) === '') {
                break;
            }

            if (preg_match('/^\s*❯?\s*(\d+)[.)]\s*(.+?)\s*$/u', $lines[$i], $m) === 1) {
                $options[] = ['number' => (int)$m[1], 'label' => $m[2]];
            }
        }

        // A multi-question AskUserQuestion call renders as a tab bar - one tab
        // per question plus a trailing "Submit" tab, cycled with the Left/Right
        // arrow keys (verified live) - rather than one linear prompt. Detected
        // so the frontend can offer prev/next-question navigation alongside
        // the normal numbered-option buttons for whichever tab is showing.
        $multiQuestion = false;

        foreach ($contextLines as $line) {
            if (str_contains($line, '←') && str_contains($line, '→') && str_contains($line, 'Submit')) {
                $multiQuestion = true;
                break;
            }
        }

        // The initial per-folder trust check is the one prompt where declining
        // exits the whole session outright, rather than just declining one
        // action - every other prompt shape's "no" option just moves on. That
        // makes an "exit" option a reliable, wording-independent signal for
        // "this is the trust dialog specifically" (verified against a live
        // capture: its options are "Yes, I trust this folder" / "No, exit").
        // Used to keep the dashboard's per-row treatment to the plain
        // attach-and-look tip for this one case, while other prompts get the
        // richer context+buttons treatment there too.
        $isFolderTrust = false;

        foreach ($options as $opt) {
            if (stripos($opt['label'], 'exit') !== false) {
                $isFolderTrust = true;
                break;
            }
        }

        return [
            'question' => $question ?? 'Waiting on an interactive prompt (permission or trust dialog)',
            'context' => $context,
            'options' => $options,
            'multi_question' => $multiQuestion,
            'is_folder_trust' => $isFolderTrust,
        ];
    }

    public static function detect_blocking_prompt(string $paneContent): ?string
    {
        return self::parse_blocking_prompt($paneContent)['question'] ?? null;
    }

    /**
     * Renders a PreToolUse hook payload's tool_input into the same kind of
     * full-text preview a human attached over tmux would eventually see -
     * except never truncated by pane height/width, since it comes straight
     * from the hook's JSON rather than rendered terminal output. Returns null
     * for a shape this doesn't know how to render usefully (an unrecognized
     * tool with no fields worth showing raw), in which case the caller should
     * keep the pane-scraped context instead of replacing it with nothing.
     */
    public static function format_pending_tool_input(string $toolName, array $toolInput): ?string
    {
        switch ($toolName) {
            case 'Bash':
                $command = is_string($toolInput['command'] ?? null) ? $toolInput['command'] : null;

                if ($command === null) {
                    return null;
                }

                $description = is_string($toolInput['description'] ?? null) ? $toolInput['description'] : null;

                return ($description !== null ? "{$description}\n\n" : '') . $command;

            case 'Write':
                $path = is_string($toolInput['file_path'] ?? null) ? $toolInput['file_path'] : null;
                $content = is_string($toolInput['content'] ?? null) ? $toolInput['content'] : null;

                if ($path === null || $content === null) {
                    return null;
                }

                return "Write {$path}\n\n{$content}";

            case 'Edit':
                $path = is_string($toolInput['file_path'] ?? null) ? $toolInput['file_path'] : null;

                if ($path === null) {
                    return null;
                }

                $oldString = is_string($toolInput['old_string'] ?? null) ? $toolInput['old_string'] : '';
                $newString = is_string($toolInput['new_string'] ?? null) ? $toolInput['new_string'] : '';

                return "Edit {$path}\n\n--- old ---\n{$oldString}\n\n--- new ---\n{$newString}";

            default:
                $encoded = json_encode($toolInput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return $encoded === false ? null : "{$toolName}\n\n{$encoded}";
        }
    }

    /**
     * Enriches a pane-parsed blocking prompt's context with the full,
     * untruncated tool_input recorded by the PreToolUse hook, when one is
     * available and actually looks like it belongs to the prompt currently on
     * screen - falls back to the pane-scraped $prompt completely unchanged
     * otherwise (no hook installed, a plain unmanaged claude session, or a
     * stale pending-tool file left over from a tool call answered outside
     * this app).
     *
     * The cross-check against the pane's own "● ToolName(...)" marker line
     * matters because this app only ever tracks one pending tool call per
     * session (the latest PreToolUse write wins) - for the rare case of
     * multiple tool calls queued in one assistant turn, that file could
     * belong to a different call than the one actually blocking. There's no
     * tool_use_id visible in the rendered pane to correlate more precisely
     * than the tool name, so a name mismatch is treated as "not this prompt"
     * and the pane-scraped context is kept instead.
     *
     * AskUserQuestion is the one exception to that marker check: it renders
     * with no "●" line at all (verified live - a "☐ <header>" line instead),
     * so there's nothing to cross-check its identity against, and the
     * pane-scraped question/context (already exactly what a human reading the
     * prompt sees) is left untouched rather than being replaced by a raw
     * tool_input JSON dump. tool_name/tool_input are still exposed on the
     * returned prompt either way, so callers (e.g. the push notification
     * body) can tell a real question apart from a permission prompt.
     *
     * @param array{question:string, context:string, options:array, multi_question:bool, is_folder_trust:bool} $prompt
     * @param array{tool_name:?string, tool_input:?array}|null $pendingTool
     * @return array{question:string, context:string, options:array, multi_question:bool, is_folder_trust:bool, tool_name?:string, tool_input?:array}
     */
    public static function augment_prompt_with_pending_tool(array $prompt, ?array $pendingTool): array
    {
        if ($pendingTool === null) {
            return $prompt;
        }

        $toolName = is_string($pendingTool['tool_name'] ?? null) ? $pendingTool['tool_name'] : null;
        $toolInput = is_array($pendingTool['tool_input'] ?? null) ? $pendingTool['tool_input'] : null;

        if ($toolName === null || $toolInput === null) {
            return $prompt;
        }

        if (preg_match('/^\s*●\s*([A-Za-z_]+)/u', $prompt['context'], $m) === 1 && strcasecmp($m[1], $toolName) !== 0) {
            return $prompt;
        }

        if ($toolName !== 'AskUserQuestion') {
            $fullContext = self::format_pending_tool_input($toolName, $toolInput);

            if ($fullContext !== null) {
                $prompt['context'] = $fullContext;
            }
        }

        $prompt['tool_name'] = $toolName;
        $prompt['tool_input'] = $toolInput;

        return $prompt;
    }
}
