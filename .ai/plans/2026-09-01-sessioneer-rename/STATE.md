# STATE.md — Sessioneer rename

- **Current objective:** Rename "Claude Session Manager" → "Sessioneer" across the
  repo, GitHub, and external infra (see PLAN.md).
- **Current step:** Task 3 done (DB column rename) and a real live-app incident
  found+fixed along the way (see RESULT.md) — live app fully verified healthy.
  Not yet committed (about to). Next: Task 4 (GitHub repo + directory rename).
- **Worker status:** Task 2 delegated to opencode (`opencode-go/kimi-k2.7-code`),
  completed the bulk (129 files) correctly per the established Bucket A/B mapping,
  but missed `CONTRIBUTING.md` + 5 `docs/*.md` planning files (~56 lines) — orchestrator
  found this via independent re-grep (never trust a worker's claim without
  verifying) and fixed it directly rather than re-delegating, since it was small
  and mechanical. Full verification detail in RESULT.md.
- **Worker model:** opencode-go/kimi-k2.7-code, cross-tool via `opencode run
  --auto`, chosen because Task 2 was a large but fully-enumerated mechanical
  rename (research cache already listed every mapping) — a free-tier
  code-specialized model was judged capable, with orchestrator review as the
  safety net regardless of which model executed it. Justification held up:
  worker did the bulk correctly, gap was a coverage miss (some doc files not
  reached) not a correctness miss (no incorrect Bucket A/B judgment calls found).
- **Process note:** hit a real tooling mistake mid-task (twice — once launching
  the Task 2 worker, once restarting a test run) — bare `&`/`nohup` backgrounding
  defeats completion tracking regardless of whether `run_in_background` wraps it.
  Written up in `.ai/lessons/nested-background-defeats-harness-tracking.md`.
- **Process note (more serious):** Task 2's worker touched several gitignored
  files during its "accidental bulk-rename pass" that neither its own re-check
  nor the orchestrator's git-diff-based Task 2 review caught, since gitignored
  files are invisible to git-based review. One of them (`host-agent/.env`'s
  `SIDECAR_DIR`) silently orphaned live session-tracking data — only found via
  an actual live smoke test during Task 3, not by the test suite or by trusting
  Task 2's "done" status. Fixed; full incident + fix in RESULT.md. Durable lesson
  in `.ai/lessons/verify-worker-output-includes-gitignored-files.md` — after any
  worker's broad edit pass, explicitly check `git status --ignored -s` too, and
  smoke-test live config-driven behavior, not just run the test suite.
- **Worker model:** N/A so far. The audit fork ran on the orchestrator's own model
  (forks always do, per Model Tiering) — chosen over a fresh cross-tool worker
  because it needed the full conversation context already established (naming
  discussion, prior findings about the `cc`/`cx`/`oc`/`ag` adapter system) rather
  than re-deriving it cold.
- **Important architectural decisions:**
  - The `cc`/`cx`/`oc`/`ag` per-agent tmux prefix system does NOT change — confirmed
    already correct and unrelated to the project's own old branding.
  - Name locked in: "Sessioneer", no stylization (e.g. not "sessAIoneer") — simplicity
    preferred over a cleverer spelling that needs explaining.
  - Andres 2026-09-01: minimize downtime during cutover, and tell him explicitly
    when it's safe to switch from `csm.example.com` to `sessioneer.example.com`. Task 5
    restructured around a dual-run (old + new stack live simultaneously) sequence
    ending in an explicit go-ahead message, followed by a grace period before the
    old hostname/units are actually removed. Also surfaced proactively: switching
    hostnames breaks the existing Web Push subscription (origin-scoped) — Andres
    will need to re-enable notifications on the new host after switching.
- **Known limitations:** The research cache's audit is a full-repo grep as of
  2026-09-01, not a hash-pinned set of files — re-grep before executing renames if
  meaningful time passes or new files land, since new occurrences wouldn't be caught
  by hash-checking alone.
- **Outstanding blockers:** Q1/Q2/Q3 in QUESTIONS.md.
