# INDEX.md — Active/paused/completed plans

One line per plan. Each orchestrator only ever writes its own line. Moved from
`.ai/orchestrator/` to `.ai/plans/` on 2026-09-01 when the orchestrator-worker
skill unified its light (solo) and full (delegated) modes under one folder
convention — see the skill for current mechanics.

- 2026-08-30-ui-parity-stop-bug | done | Sidebar/dashboard UI parity (bg tint, pills, quota table, prev-user-message scroll button), Stop-button and iOS-resume bug fixes, sidebar server-rendered prompt-answering with real click-through test coverage - all 7 tasks done, reviewed, committed (23b3c05, 12b2e46) | updated 2026-08-31
- 2026-08-29-agent-feature-parity | active | Bring Claude Code/Antigravity/OpenCode/Codex to parity on shared features (Tasks 1-4 done: Codex question-prompts, Codex archived-resume routing, archived-session cwd/title for OpenCode/Codex/Antigravity, OpenCode forward-poll cursor bug; A5/A6 remain) | updated 2026-08-30
- 2026-09-01-sessioneer-rename | active | Rename "Claude Session Manager"/csm -> "Sessioneer" across the repo, GitHub, and external infra (traefik, homie, host config files) - Tasks 2-4 done+verified (repo now loki495/sessioneer at ~/www/sessioneer, symlink bridge at old path; 2 real live-app incidents found+fixed along the way, see RESULT.md); Task 5 (external infra cutover) next | updated 2026-09-01
- 2026-09-01-parallel-sessions-view | planned | Sidebar cards get "Open"/"Add to view" buttons - multi-pane session view (1/2/3/4+ panes, horizontal scroll) with ONE shared sidebar across panes (toggles, not-in-view list, per-session files/tasks), desktop only, dashboard unchanged. 4-task plan written (iframe-per-pane + a small scoped sidebar.js refactor), not yet implemented | updated 2026-09-01
