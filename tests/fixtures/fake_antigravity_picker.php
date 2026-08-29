<?php
declare(strict_types=1);

/**
 * A minimal stand-in for Antigravity's real `/model` picker screen, driven
 * over a live tmux pane the same way the real one is - see
 * PromptInteractionService::move_antigravity_picker_cursor()'s own docblock
 * for why a fixed-count blind key sequence isn't safe against the real
 * thing (some fraction of rapid Up/Down presses get silently dropped) and
 * why this method instead re-checks the pane after every single press.
 * This fixture is deterministic (never drops a key) - it exists to cover
 * the navigation ALGORITHM itself (converges to the right row from any
 * starting position, including zero presses needed when already there,
 * and Enter reports the row actually landed on) - the drop-recovery
 * behavior itself was verified live against the real CLI (see this
 * method's own docblock), not something a fake process can faithfully
 * reproduce without just reimplementing Antigravity's own bug.
 *
 * Usage: php fake_antigravity_picker.php <starting-row 1-based>
 * Prints the same "> Label" / "  Label" list shape the real picker does,
 * re-printing the whole list after every accepted Up/Down, and prints
 * "Model set to <Label>" on Enter.
 *
 * The picker is rendered immediately on start (there's no real "/model"
 * command to type here) - the VERY FIRST Enter byte received is swallowed
 * as a no-op instead of confirming a selection, standing in for
 * set_antigravity_model()'s own leading '/model' + Enter that opens the
 * real picker before any navigation begins. Only the SECOND and later
 * Enters actually confirm.
 */

$labels = [
    'Gemini 3.7 Flash',
    'Gemini 3.6 Flash',
    'Gemini 3.5 Flash',
    'Gemini 3.1 Pro',
    'Claude Sonnet 4.6 (Thinking)',
    'Claude Opus 4.6 (Thinking)',
    'GPT-OSS 120B (Medium)',
];

$cursor = max(0, min(count($labels) - 1, ((int)($argv[1] ?? 1)) - 1));

function render(array $labels, int $cursor): void
{
    // Clear-screen + home-cursor before each redraw, matching the real
    // picker's own behavior (confirmed live 2026-08-24: capturing the pane
    // mid-navigation always showed exactly ONE copy of the list, never
    // stale earlier positions stacked above it) - without this, tmux
    // capture-pane's default no-history viewport can still show several
    // past renders at once (each just println'd, never overwritten),
    // making move_antigravity_picker_cursor()'s str_contains() check match
    // a row the cursor had already moved PAST rather than its current one.
    echo "\033[2J\033[H";
    echo "Switch Model\n";

    foreach ($labels as $i => $label) {
        echo ($i === $cursor ? '> ' : '  ') . $label . "\n";
    }
}

shell_exec('stty -icanon -echo min 1 time 0');
render($labels, $cursor);

$openingEnterSwallowed = false;

while (($byte = fread(STDIN, 1)) !== '') {
    if ($byte === "\x1b") {
        $rest = fread(STDIN, 2);

        if ($rest === '[A') {
            $cursor = max(0, $cursor - 1);
            render($labels, $cursor);
        } elseif ($rest === '[B') {
            $cursor = min(count($labels) - 1, $cursor + 1);
            render($labels, $cursor);
        }
    } elseif ($byte === "\r" || $byte === "\n") {
        if (!$openingEnterSwallowed) {
            $openingEnterSwallowed = true;
            continue;
        }

        echo 'Model set to ' . $labels[$cursor] . "\n";
    }
}
