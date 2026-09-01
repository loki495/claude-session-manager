---
topic: nested-background-defeats-harness-tracking
tags: [bash, general, orchestrator-worker, claude-code-harness]
---

Never nest shell-level backgrounding (`nohup cmd & ; echo "PID: $!"`) inside a
Bash tool call that already has `run_in_background: true` set. The harness's own
`run_in_background` mechanism already does the right thing — it blocks on the
literal command given to it and notifies on that command's real completion. Adding
an internal `&` defeats this: the wrapper shell backgrounds the real work and
immediately returns/exits on its own (e.g. after `echo "PID: $!"`), so the harness
reports "completed" the instant the *wrapper* exits — while the actual long-running
process (a `codex exec`/`opencode run` worker, a build, whatever) is still running,
untracked, output going wherever it was manually redirected instead of the
harness's own tracked output file.

This is the exact same failure class the orchestrator-worker skill's "Background
Launch Verification" section documents for bare shell backgrounding of cross-tool
workers (`nohup codex exec ... &` with no `wait`) — it applies just as much when the
outer call is itself already `run_in_background: true`, since the nested `&` is
what actually breaks tracking, not which mechanism wraps it.

**Correct pattern:** give `run_in_background: true` a command that itself blocks
until the real condition is met — either the foreground command directly (no `&`
at all), or an `until`/polling loop against the actual PID or a completion sentinel
file. Confirmed by direct incident 2026-09-01: launched an opencode worker via
`nohup opencode run ... & ; echo "PID: $!"` under `run_in_background: true` — the
harness reported the Bash call "completed" in seconds, but `ps aux` showed the real
`opencode run` process still consuming real CPU minutes later. Recovered by
polling `until ! kill -0 <pid>; do sleep 5; done` as the actual backgrounded
command instead.
