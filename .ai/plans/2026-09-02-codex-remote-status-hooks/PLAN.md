# PLAN.md — Codex Remote status hooks

## Objective

Make Sessioneer accurately show working, idle, and externally-blocked state for
Codex threads driven by Codex Remote, without pretending Sessioneer can answer a
connection-scoped prompt owned by the Remote app-server.

## Task 1 — Hook ingestion and installation

- **Status:** complete
- **Relevant files:** `host-agent/hooks/`, `host-agent/lib/Services/Config.php`,
  new Codex hook service/protocol files, `host-agent/lib/Agents/CodexAdapter.php`, tests.
- **Acceptance criteria:** Codex lifecycle hooks update `SessionStatusStore` by native
  `session_id`; install/check preserves unrelated `~/.codex/hooks.json` entries;
  adapter no longer reports hooks installed merely because the private bridge works;
  hook scripts never block Codex on internal failures.

## Task 2 — External blocked-state presentation

- **Status:** complete
- **Relevant files:** headless session shaping and blocked-prompt/compose UI plus tests.
- **Acceptance criteria:** Remote-owned approval and `request_user_input` events render
  as blocked with clear instructions to answer in Codex Remote, and no nonfunctional
  Sessioneer answer controls are offered.

## Task 3 — Review, verify, and activate

- **Status:** complete
- **Acceptance criteria:** focused tests, PHPStan, diff checks, and full suitable suite
  pass; live hook config is installed without overwriting user configuration; Codex
  trust/reload requirements are verified and clearly reported.
