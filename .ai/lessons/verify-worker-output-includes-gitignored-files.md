---
topic: verify-worker-output-includes-gitignored-files
tags: [general, orchestrator-worker, git, code-review]
---

`git status`/`git diff` review after a worker's file-editing pass — the standard
verification step in this protocol's Worker Completion Protocol / Code Review
section — is **blind to gitignored files**. A worker with broad file-editing
permission (`--auto`, or equivalent) doing a broad rename/find-replace pass can
sweep up gitignored files (`.env`, a local `docker-compose.override.yml`, old
debug/snapshot artifacts) that never show up in git-based review at all, even
though they were genuinely modified on disk.

This isn't theoretical: on 2026-09-01, an opencode worker doing a repo-wide
`csm`->`sessioneer` rename correctly caught and reverted its own accidental
touch of `.claude/settings.local.json` (confirmed empty on re-check) but
**missed** several other gitignored files it also touched: `.env`,
`host-agent/.env`, `docker-compose.override.yml`, and six old
`.playwright-mcp/*.yml` debug snapshots. The worker's own RESULT.md report
claimed these categories were "restored" — true for some, false for others —
and neither the worker's own re-grep nor the orchestrator's git-diff-based
review caught the gap, because gitignored files are invisible to both by
construction.

**Impact when it matters, not just clutter**: one of the missed files
(`host-agent/.env`) had a config value (`SIDECAR_DIR`) renamed to point at a
brand-new, empty directory instead of the real one holding live session-tracking
data — silently orphaning that data (sessions still existed, just invisible to
the app) until caught by an actual functional smoke test against the live app,
not by any code-level check.

**Rule**: after any worker pass that does broad file editing (rename, find-
replace, refactor) with real file-write permission, explicitly check gitignored
files too — `git status --ignored -s` lists them, then grep the ones plausibly
in scope for the same pattern the worker was told to change. Don't rely on the
worker's own "I restored X" claim without independently re-checking; and don't
consider `git diff`/`git status` clean as proof nothing unwanted happened,
since it only proves nothing *tracked* happened. For anything with live
operational impact (a config file consumed by a running service, not just a
tracked source file), also run an actual functional smoke test against the
live system afterward — a clean test suite run (against fixtures) does not
prove the live, gitignored-config-driven path still works.
