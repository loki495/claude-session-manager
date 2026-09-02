# Plan

- T1 — Reproduce and fix the health-check TUI layout mismatch. Status: done.
  Acceptance: the real mismatch is understood, the parser/interaction behavior matches the current TUI, and focused happy/sad-path tests pass.
- T2 — Reproduce and fix Codex dashboard session creation (`list_turns is not supported yet`). Status: done.
  Depends on: T1 checkpoint. Acceptance: dashboard-created Codex sessions start and can accept an initial message; regression tests cover the supported protocol and handled failure.
- T3 — Diagnose/fix Claude Code dashboard session creation as quota permits. Status: done.
  Depends on: T2 checkpoint. Acceptance: non-billable/local coverage passes and a live check is performed only if quota permits; any unverified live limitation is documented.
- T4 — Assess GitHub Actions CI setup and report concrete scope/risks. Status: done.
  Depends on: T1–T3. Acceptance: recommend a workflow, commands, secrets/fixtures constraints, and an effort estimate; do not create CI unless separately requested.
- T5 — Final review and verification. Status: done.
