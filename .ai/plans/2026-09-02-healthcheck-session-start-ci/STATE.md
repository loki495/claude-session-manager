# State

Objective: fix the current health mismatch and dashboard session-start regressions, then assess CI.

Current step: complete.

Mechanism: orchestrator working directly while establishing the exact failure. Delegation is not being used because the current runtime instruction prohibits subagents unless explicitly requested; findings and changes remain file-backed here.

Constraints:
- The supplied workspace was an empty placeholder, so a local clone of `/home/user/www/sessioneer` was created under `repo/`.
- Claude Code live testing may be quota-limited; fixture tests are non-billable.
- No push or external write is authorized.

Decisions:
- Permission cards use the live Claude pane's menu where available, while retaining hook-fed tool context. The clicked label is sent as intent so the host agent can safely revalidate/re-map against a fresh pane.
- Legacy TUI mismatch warnings are ignored after this contract upgrade; future label-aware mismatches remain health failures.
- Codex detail retries metadata-only `thread/read` when Codex 0.152.1 reports `list_turns is not supported yet`.
- Claude's dashboard-equivalent create path was verified live without sending a model prompt; the diagnostic session was then killed. No quota-dependent response test was attempted.

Verification status:
- Focused Codex runtime tests pass.
- Full non-browser suite passes.
- A full suite browser run reached browser coverage but hit existing CDP navigation/poll timing failures; no uncaught JS errors were observed.
