# INDEX.md — Durable lessons

- nested-background-defeats-harness-tracking | bash, run_in_background, orchestrator-worker | a nested `&`/nohup inside a Bash `run_in_background: true` call makes the harness report completion the instant the wrapper shell exits, not when the real backgrounded process finishes
- verify-worker-output-includes-gitignored-files | git, orchestrator-worker, code-review | git status/diff review after a worker's edit pass is blind to gitignored files (.env, override configs, debug artifacts) - explicitly check `git status --ignored -s` too, and smoke-test live config-driven behavior, not just the tracked-file diff
