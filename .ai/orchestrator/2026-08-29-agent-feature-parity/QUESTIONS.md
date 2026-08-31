# QUESTIONS.md — Agent feature parity

## Task 3 — OpenCode's resolved "path" is a DB id, not a filesystem path (resolved)

**Raised by:** Codex CLI worker (gpt-5.4-mini), first Task 3 attempt,
2026-08-30. The worker's own session got cut off before it could write this
entry itself (log-capture bug on the orchestrator's launch side, not the
worker's fault) — the orchestrator recovered its investigation from Codex's
own session rollout file and is recording the finding here on the worker's
behalf.

**What was found:** Task 3's spec told the worker `archived_session_detail()`
(`SessionDetailService.php:175-199`) was an "already-correct" reference
pattern for `cwd` resolution across Claude/Antigravity/OpenCode, safe to
leave untouched. That's wrong for OpenCode specifically:
`TranscriptRouter::find_transcript_path()` returns a raw `ses_*` database id
for OpenCode (confirmed: `TranscriptRouter::is_opencode_path()` just checks
`OpenCodeTranscriptService::is_opencode_id($path)`, a shape check on the
value itself — it was never a real file path). Feeding that into
`TranscriptService::find_first_cwd($path)` (which scans a real file) cannot
work — `archived_session_detail()` has the exact same latent `cwd`-for-
OpenCode bug Task 3 was assigned to fix in `archived_session_history()`,
just never exercised/noticed. Antigravity and Codex are unaffected
(Antigravity's resolved path is a real file under
`~/.gemini/antigravity-cli/brain/`; Codex has its own metadata branch
already). Confirmed independently by the orchestrator, not just taken on
the worker's word.

**Also found (worker + orchestrator):** OpenCode's SQLite `session` table
already has a real `directory` column holding the actual cwd
(`OpenCodeTranscriptService.php:502`, used today only in the reverse
direction — workdir → session id, for live-session self-healing via
`find_session_for_workdir()`). No existing helper reads it back for a known
session id.

**Decision (Andres, 2026-08-30):** fix OpenCode properly as part of Task 3,
not defer it. See Task 3's revised Implementation notes in PLAN.md for the
concrete plan (new `OpenCodeTranscriptService::session_directory()` helper,
used by both `archived_session_detail()` and `archived_session_history()`).
