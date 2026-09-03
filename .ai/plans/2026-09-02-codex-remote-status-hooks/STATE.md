# STATE.md — Codex Remote status hooks

- **Current objective:** observe Remote-owned Codex lifecycle and external prompts.
- **Current step:** implementation, verification, and live hook installation complete.
- **Worker:** Codex subagent, `gpt-5.6-luna`; selected as the lowest-cost available
  model suited to a bounded PHP/config/test change. Exact token usage unavailable.
- **Confirmed root cause:** `~/.codex/hooks.json` is absent and `CodexAdapter` reports
  `installed=true` based only on private bridge reachability. The private bridge sees
  events only for its own app-server, not the Remote-managed daemon.
- **Protocol decision:** use official Codex lifecycle hooks keyed by native
  `session_id`; external prompts are observability-only and must not render answer
  controls.
- **Known limitation:** user hooks require Codex trust review after installation.
- **Verification:** focused hook/adapter/UI-shaping tests pass; full PHPStan passes
  with a higher memory limit and serial debug mode; the full non-browser test suite
  passes; the running host agent reports the aggregate Claude + Codex hook set
  installed.
