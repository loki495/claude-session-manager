# RESULT.md — Codex Remote status hooks

- Official Codex hooks provide `session_id` plus `UserPromptSubmit`, `Stop`,
  `PermissionRequest`, `PreToolUse`, `PostToolUse`, and `Interrupt` events.
- Live check: this Remote-owned thread was `notLoaded` in Sessioneer's private
  app-server and had no `SessionStatusStore` row, confirming private app-server
  polling cannot observe its active turn reliably.
- Added a neutral, event-aware Codex command hook for working/idle transitions,
  approvals, and `request_user_input`. Remote-owned prompts are persisted as
  externally answerable and never expose nonfunctional Sessioneer controls.
- Added safe, idempotent `~/.codex/hooks.json` check/install behavior that
  preserves unrelated configuration and refuses malformed files.
- Installed the live user hook configuration. Codex still requires the normal
  `/hooks` trust review, and already-open sessions may need reopening afterward.
- Verification passed: focused tests, complete PHPStan, and the full
  `bash tests/run.sh --no-browser --bail` suite.
