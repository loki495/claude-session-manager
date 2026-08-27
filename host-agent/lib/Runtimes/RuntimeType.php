<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

/**
 * The runtime a session can live under - the orthogonal dimension to
 * AgentAdapter (which is about WHICH coding CLI; this is about WHERE/HOW
 * its session is hosted and driven).
 *
 * A session is either:
 *  - hosted in a tmux pane (tmux), driven by typing keystrokes into the
 *    pane and observed via capture-pane/hooks, or
 *  - hosted headless (headless - e.g. opencode's `opencode serve`), driven
 *    and observed over the agent's own server API, no tmux pane at all.
 *
 * Not every agent supports every runtime - see
 * AgentAdapter::supported_runtimes() for the per-agent answer, which is
 * what RuntimeRegistry consults. Only add a runtime here once more than
 * one agent could plausibly want it.
 */
final class RuntimeType
{
    public const TMUX = 'tmux';

    public const HEADLESS = 'headless';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [self::TMUX, self::HEADLESS];
    }
}
