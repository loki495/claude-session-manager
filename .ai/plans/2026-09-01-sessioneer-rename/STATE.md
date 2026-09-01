# STATE.md — Sessioneer rename

- **Current objective:** Rename "Claude Session Manager" → "Sessioneer" across the
  repo, GitHub, and external infra (see PLAN.md).
- **Current step:** Task 5 in progress — host-agent systemd units renamed for
  real (steps 1-2, 9-10 of Task 5 done, see RESULT.md), old `csm-*` units fully
  retired and removed. Remaining: the actual `csm.example.com` ->
  `sessioneer.example.com` hostname cutover (traefik repo, new TLS cert, the
  explicit "safe to switch" message to Andres, homie's dashboard card) — steps
  3-8 of Task 5, not yet started. 3 commits still unpushed (Andres asked to
  hold off pushing until Task 5 wraps).
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
  Task 2's naming audit fork (before delegation) ran on the orchestrator's own
  model (forks always do) since it needed the conversation's existing context
  rather than re-deriving it cold. Tasks 3/4 done directly by the orchestrator,
  not delegated — Task 3 had zero Bucket A/B ambiguity (a good fit for a plain
  literal-string replace); Task 4 was two external actions (`gh repo rename`,
  a directory `mv`+symlink) not the kind of work a worker adds value on.
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
  by hash-checking alone. `CLAUDE.md`'s branch-model section still has one stale
  example path (`../claude-session-manager-refactor`) — cosmetic, low priority,
  not yet fixed.
- **Outstanding blockers:** none — Q1/Q2/Q3 all answered. Task 5 is next.
