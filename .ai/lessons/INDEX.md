# INDEX.md — Durable lessons

- nested-background-defeats-harness-tracking | bash, run_in_background, orchestrator-worker | a nested `&`/nohup inside a Bash `run_in_background: true` call makes the harness report completion the instant the wrapper shell exits, not when the real backgrounded process finishes
