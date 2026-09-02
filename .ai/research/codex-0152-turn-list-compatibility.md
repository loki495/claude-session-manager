---
topic: codex-0152-turn-list-compatibility
covers:
  - path: host-agent/lib/Runtimes/CodexHeadlessRuntime.php
    hash: c2352b882b8b202321152c470a6cec5125b44cd3
updated: 2026-09-02
---

Live Codex 0.152.1 accepts `thread/start` and metadata-only `thread/read`, but `thread/read` with `includeTurns: true` returns JSON-RPC -32601: `list_turns is not supported yet`. A newly created thread already carries `turns: []` in the `thread/start` response. Session detail must treat this message as a retained-turn capability miss and retry metadata-only, just as it already does for the unmaterialized-thread `includeTurns is unavailable before first user message` response.
