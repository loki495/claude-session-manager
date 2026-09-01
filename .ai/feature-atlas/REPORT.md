# Feature Atlas — Report

**Repo:** `claude-session-manager`
**Scan date:** 2026-08-26
**Reference commit:** `44e4caa` (HEAD). Working tree carries uncommitted changes (Antigravity/OpenCode/SQLite work); all finding file:line citations below were re-read from the **current working tree** unless noted.
**Scope:** first full synthesizer pass over all **14 subsystems** (`agent-abstraction`, `archived-sessions`, `dashboard`, `host-agent-runtime`, `plan-files`, `prompt-interaction`, `push-notifications`, `quota`, `session-core`, `session-lifecycle`, `session-status-state`, `session-view`, `uploads`, `web-shell`).

Every finding in every subsystem `AUDIT.md` was independently re-checked against current source for the HIGH/critical items and spot-checked for the rest. Dispositions recorded: `confirmed` / `narrowed` / `demoted` / `rejected`. See the Meta-Audit Notes appendix for what was merged, rejected, and demoted, and why.

---

## Executive summary

**Stack / conventions observed.** Custom Composer PSR-4 PHP web app (no framework). Two runtimes: a containerized web UI (`public/` + `src/`, League Plates, plain-ES5 browser JS, no bundler/npm pipeline for the shipped JS) and a host-native agent (`host-agent/`, systemd socket-activated, `proc_open` array-form, no shell strings). They speak a one-request-one-response JSON protocol over a UNIX socket (`src/lib/AgentClient.php` ⇄ `host-agent/agent.php`). State moved to SQLite 2026-08-24 (`SqliteDb`: tmpfs `sessions.sqlite` for ephemeral per-session state; persistent `push.sqlite` for subscriptions/status/global blobs). Five Claude Code hooks + four Antigravity hooks feed a mandatory, exclusive `SessionStatusStore` contract. Repo convention: dense "found live / verified live / decided DATE" docblocks; re-validate-fresh on every mutating request; ES5 syntax rule; "lean portable" bias.

**Overall health.** Good. The codebase is unusually well documented, the two-runtime boundary is structurally sound (verified: every controller action has a dispatcher handler; no action falls through), the JSON→SQLite migration is the right architectural call, and sad-path coverage at the service layer is strong. The issues are concentrated in: (a) a handful of **silent data-loss / crash-path** defects at the foundation layer, (b) **unbounded blocking I/O** on both sides of the socket, and (c) a **documented-contract-vs-implementation** drift theme (docblocks claiming properties the code doesn't deliver).

**Single most important risk:** `session-core` F1 — a transient `tmux` query failure is conflated with "no sessions", so `list_all_sessions()` passes `[]` to `SidecarStore::prune_orphaned_sidecars()`, whose empty-input branch issues an **unconditional `DELETE FROM sidecars` / `session_status` / `pending_tools`**. One transient tmux hiccup during a dashboard poll **irrecoverably wipes the entire tracking layer** (every `claude_session_id`, workdir, agent, status row, pending tool). This is silent and unrecoverable, exactly the class of "failure surfaces as a silent destructive outcome" the repo's own sad-path rule targets. It is the highest-priority item.

---

## Priority findings (ranked, deduplicated, cross-subsystem)

Severity: `HIGH` / `MED` / `LOW`. Each links to the owning subsystem `AUDIT.md`. Verification status in parentheses. Consolidations noted inline.

### Crash / data-loss

1. **[HIGH] `session-core` F1 — transient tmux failure wipes all tracking state** (`session-status-state` co-owner)
   - `SessionService.php:420-422` (passes `$liveSessionNames` to prune), `TmuxService.php:186-190` (returns `[]` on exit≠0), `SidecarStore.php:131-132` (empty-array branch = unconditional `DELETE FROM` all three tables). **Verified.** `confirmed`. Fix: distinguish query-failure from genuinely-zero-sessions in `all_tmux_panes()`; make `prune_orphaned_sidecars([])` a no-op (`WHERE 1=0`); add a sad-path test.

2. **[HIGH] `session-status-state` F1 — hooks exit non-zero + dump an uncaught `PDOException`, violating "always exit 0 / pure observe"; Antigravity hooks skip their required `decision` field** (crash path)
   - `SqliteDb.php:68` (`ERRMODE_EXCEPTION`), all 9 hook store-write sites unguarded (`pre_tool_use.php:83-99`, `permission_request.php:77`, `user_prompt_submit.php:51`, `stop.php:58`, `session_start.php:139-150`, `antigravity/pre_tool_use.php:60-70`, `antigravity/pre_invocation.php:75-86`, `antigravity/stop.php:220-233`, `antigravity/post_tool_use.php:28`). **Verified** — grep of `host-agent/hooks/` for `try|catch|error_log` returns zero runtime handlers. `confirmed`. For Antigravity the `echo json_encode(['decision'=>...])` comes *after* the write (`antigravity/pre_tool_use.php:73`), so a throw skips the required decision. Fix: `try/catch \Throwable` around the store-write region, still reaching `exit(0)`/`echo`, plus a shared bounded log.

3. **[HIGH] `session-view` Finding 1 — OpenCode forward-poll misaligns the line cursor with the filtered entry array; a live session's detail page silently stops updating**
   - `OpenCodeTranscriptService.php:318,407` (`$entry['line'] = $idx + 1`, raw message index) versus `:413-414` (forward path indexes the **filtered** `$renderable` with `$afterLine`). **Verified** — `message_to_entry()` returns `null` for synthetic/step-start/reasoning parts (`:203,220`), so the two coordinate systems diverge whenever a non-renderable row precedes the newest renderable one; the loop then never runs and returns `{ok:true, entries:[]}` forever. `confirmed`. Fix: make `line` a renderable-position index (1-indexed position in `$renderable`).

4. **[HIGH] `archived-sessions` Finding 1 — archived detail/browse resolves transcripts with the Claude-Code-only resolver; Antigravity/OpenCode archived rows render "Session not found"**
   - `SessionDetailService.php:177` (`archived_session_detail` → claude-only `TranscriptService::find_transcript_path`), `:134` (cwd via claude-only resolver) — while the sibling paging path already uses `TranscriptRouter` (`:145`). **Verified.** `confirmed`. Fix: route `:177`/`:134` through `TranscriptRouter`, use `SessionService::session_title()` for the title.

5. **[MED] `session-lifecycle` Finding 1 — `kill_bare_process()` does not enforce "bare"** *(demoted from HIGH — deliberately, see appendix)*
   - `BareProcessService.php:22-24` (docblock promises untracked-only) vs `:36-52` (only re-scans that the pid is a running claude process, then `kill-session` on the owning pane with **no tracked-session check**). **Verified.** `narrowed`/`demoted` — reachable only via a crafted POST (the normal `bare[]` set already excludes tracked pids), so it is a defense-in-depth/contract-mismatch gap, not a common-path bug. Fix: reject when `SidecarStore::read_sidecar($owningPane['session']) !== null`.

6. **[MED] `session-lifecycle` Finding 2 — stale test assertion guards nothing** *(demoted from HIGH — test-integrity, not runtime)*
   - `tests/test_sessions_lifecycle.php:760` asserts `!file_exists(Config::sidecar_dir()."/{$name}.json")` but sidecars are SQLite-only since 2026-08-24 — the `.json` never exists, so the assertion is a tautology. **Verified.** `confirmed` (the defect), `demoted` in severity. Fix: assert `SidecarStore::read_sidecar($name) === null`.

7. **[HIGH] `session-status-state` F2 — sidecar read-modify-write race remains (session_start / antigravity pre_invocation read the sidecar in PHP then rewrite the whole row)**
   - `session_start.php:83` read + `:139-150` `write_sidecar` full overwrite; `SidecarStore::write_sidecar` (`:85-109`) is a full `ON CONFLICT DO UPDATE SET` of every column, so merging requires the PHP read. **Verified.** `confirmed` (narrowed: the transcript-existence + already-live guards make the window narrow but do not close it). Fix: add `SidecarStore::update_sidecar(string, array)` (partial `UPDATE` keyed by `array_key_exists`), have the two hooks use it.

### Unbounded I/O across the socket boundary (consolidated — the "read/write timeout" theme)

8. **[HIGH] Consolidated: unbounded blocking I/O on both sides of the socket + in the process layer**
   - **(a) `web-shell` F1 (HIGH)** — `AgentClient.php:39,46`: `fwrite` return unchecked, `stream_get_contents($socket)` has **no read timeout**; a wedged host agent hangs the request forever (and under `php -S`, every subsequent request). **Verified.**
   - **(b) `host-agent-runtime` Finding 2 (MED)** — `agent.php:26` `stream_get_contents(STDIN)` no read timeout / size cap / `json_last_error` check; a connecting-but-never-sending client wedges a per-connection systemd process (`KillMode=process`, so even stop doesn't reap it). **Verified.**
   - **(c) `session-core` F2 (MED)** — `ProcessRunner.php:33-34` drains stdout then stderr sequentially with **no timeout and no `proc_terminate`**, latent ~64KiB stderr pipe-buffer deadlock. **Verified.**
   - These are three distinct code sites needing three fixes, but one theme: no site bounds read/write I/O, so a slow/wedged peer turns into an unbounded hang. Fixes: `stream_set_timeout` on both agent reads (configurable `CSM_AGENT_TIMEOUT` / `PUSH_*`); guard `fwrite`/`json_encode`; add an opt-in `$timeoutSeconds` to `run_process()` and redirect fd2 onto fd1 (or a `stream_select` drain). *(3 audit findings merged into 1.)*

### Correctness — session model / prompt / quota / push

9. **[HIGH] `prompt-interaction` Finding 1 — multiSelect-as-the-LAST-question ships an explicitly-unverified generalization**
   - `PromptParser.php:565-570` docblock admits "Not independently confirmed, but inferred as a safe generalization … Re-verify live", yet `:623` unconditionally appends `['type'=>'right']` after every multiSelect and `:639` appends the trailing `digit '1'` regardless. **Verified.** `confirmed`. Fix: treat multiSelect-as-last as a distinct branch; return `null` (reject) until verified, matching the method's own "never send a partial/uncertain sequence" contract.

10. **[HIGH] `quota` Finding 1 — `antigravity_quota_poll.php` wipes a previously-good state to `{"captured_at": <now>}` on a SUCCESS response with no parseable bucket**
    - `antigravity_quota_poll.php:62,106` (unconditional write even when the loop added nothing), vs the sibling `quota_live_state_write.php:59-76` merge guard that **preserves** previous on malformed input. **Verified.** `confirmed`. Fix: guard the write (`if (count($state) > 1)`), keep the prior `captured_at`.

11. **[HIGH] `prompt-interaction` Finding 2 — the PHP `PermissionStore` is an orphaned half-bridge with a latent stale-intent race**
    - `PermissionStore.php` methods have no production callers (grep: only `tests/test_opencode_permission_store.php`); the real path is the pane, and `SessionService.php:166-170` ("PermissionStore used just to corroborate") never reads it. **Verified** (grep confirmed only the test calls it). `confirmed`. Fix (decide): delete the unused PHP half + plugin intent branch, or commit to the store as the authority and drain intent on `permission.replied`.

12. **[MED] `dashboard` Finding 1 — inline `onsubmit` confirm strings break on an apostrophe → destructive action runs with NO confirmation**
    - `session-row/row.php:49`, `session-row/bare-process-row.php:11,17`: `confirm('Kill session <?= $this->e($name) ?>?')` — `e()` decodes `&#039;` back to `'` inside the JS literal, producing a `SyntaxError`, so the handler never attaches and the form submits silently. The `$tmuxSession` in the bare rows comes from untrusted host data and may legally contain `'`. **Verified** (Plates `e()` = `htmlspecialchars(ENT_QUOTES)`). `confirmed` — MED, but arguably the subtlest real bypass here (a confirmation-guard bypass on a destructive act). Fix: `data-confirm-label` + a delegated `submit` listener (the pattern already used for answer-prompt forms).

13. **[MED] `push-notifications` Finding 1 — subscription table is read-modify-write at the service layer; a concurrent subscribe can be clobbered by the timer's prune**
    - `PushDeliveryService.php:299,363-366` (snapshot then whole-table replace) + `PushSubscriptionStore.php:42-55` (`DELETE` + re-insert). **Verified** (the single remaining read-modify-write race — the other three stores have a single writer). `confirmed`. Fix: prune by endpoint (`remove_push_subscriptions(array $endpoints)`), not snapshot-replace.

14. **[MED] `push-notifications` Finding 2 — a transient (non-expiry) send failure permanently swallows the notification**
    - `PushDeliveryService.php:368` writes state **unconditionally** after sends regardless of outcome; edge-triggered machine then stays silent next tick. Same shape in the quota pass (`:548`). **Verified.** `confirmed`. Fix: consume the transition only if ≥1 send succeeded or there were no subscriptions; skip the write on all-fail so the next tick retries.

15. **[MED] `push-notifications` Finding 3 — `sw.js` approve/deny swallows a failed `fetch` and does not fall back to opening the app**
    - `public/sw.js:154-168`: `.catch(function(){})`, `response.ok` never checked. **Verified.** `confirmed`. Fix: on rejection/non-ok, fall back to `openOrFocusUrl(notifData.url || '/')`.

16. **[MED] `quota` Finding 3 — `opencode_quota_state()` can throw past its connect-time try/catch, violating `get_quota()`'s "no throws" contract**
    - `QuotaService.php:183-191` try wraps only connect; `:193-235` queries outside it with `ERRMODE_EXCEPTION`. **Verified.** `confirmed`. Fix: move the queries inside the guard, return `null` on `\PDOException`.

17. **[MED] `session-core` F3 — per-session read amplification: 2 tmux calls + 3 transcript-path resolutions with no sharing**
    - `SessionService.php:100,136,69,269,390`, per session in the loop at `:427-435`. **Verified.** `confirmed` (performance, not correctness). Fix: resolve transcript path once, thread `all_tmux_panes()` map through.

18. **[MED] `plan-files` Finding M1 — `read_todo_file()` does not enforce the realpath boundary its own docblock claims (symlinked `todo` reads outside the workdir)**
    - `PlanFileService.php:169-181` (reads `$realDir.'/todo'` without `realpath`/prefix check) vs `resolve_plan_file_path():86-109` (does). **Verified.** `confirmed`. Fix: add the `realpath` + `str_starts_with($real, $realDir.'/')` boundary.

### Session model / contract correctness (Medium)

19. **[MED] `dashboard` Finding 2 — take-over confirm trusts client-supplied `workdir`**
    - `DashboardController.php:311-325` forwards `$_POST['workdir']` verbatim; `BareProcessService.php:257-272` does not re-derive it from the pid's cwd (contrast `:206-245`). **Verified.** `confirmed` (consistency breach of the re-validate pattern, low marginal risk). Fix belongs in `BareProcessService` (session-lifecycle).

20. **[MED] `uploads` Finding 1 — write path never goes through the realpath boundary read/delete enforce (broken symlink write-through)**
    - `UploadService.php:126-138` (`file_put_contents($dir.'/'.$finalName)`) vs `resolve_upload_path():197-213`. `file_exists` follows symlinks and returns false for a broken symlink → writes through it. **Verified.** `confirmed` (exploitability low — 0700 user-owned dir — but the asymmetry is real). Fix: `is_link` guard (or reuse the resolver) before write.

21. **[MED] `uploads` Finding 2 — invalid-UTF-8 / legacy-charset filename silently breaks the JSON protocol**
    - `UploadController.php:92-93` relays raw `$file['name']`; `AgentClient.php:39` `json_encode` unchecked. `UploadService::sanitize_upload_filename:76-83` strips only `\x00-\x1f`. **Verified.** `confirmed`. Fix: normalize the name UTF-8 on the container side and/or have `AgentClient` return `ok:false` when `json_encode` returns false.

22. **[MED] `session-status-state` F3 — `update_status()` documented as an atomic `INSERT … ON CONFLICT DO UPDATE` + `COALESCE`; implemented as `INSERT OR IGNORE` + plain `UPDATE`, not in a single transaction**
    - `SessionStatusStore.php:79-83,98-120`. **Verified** (doc/code mismatch high-confidence; the merged-outcome is still race-free in the way that matters because each hook only SETs its own columns, but the docblock overstates and the ordering window is unconstrained — a delayed `PreToolUse` can transiently overturn a `PermissionRequest`). `confirmed` / `narrowed`. Fix: correct the docblock (and `DETAILS.md`), optionally wrap in a transaction.

23. **[MED] `session-status-state` F4 — `write_status()` silently drops `last_turn_error`**
    - `SessionStatusStore.php:125-134` INSERT list + ON CONFLICT SET omit `last_turn_error` (update_status handles it). **Verified.** `confirmed` (latent; all current callers are tests). Fix: add the column + bind.

24. **[MED] `session-view` Finding 2 — PHP/JS markdown escaping diverge on `'`/`"`, and the byte-for-byte parity test never exercises them**
    - `MarkdownRenderer.php:176` (`ENT_QUOTES`) vs `common.js:359-363` (`escapeHtml` = DOM textContent, no quote-escaping). **Verified.** `confirmed` (no visible bug today — browser renders them same — but the documented invariant + the parity guard are both wrong). Fix: quote-safe escaper in `markdown.js` + add quote cases to the parity inputs.

25. **[MED] `session-view` Finding 3 — documented `data-line`/`.copy-btn`/`.copy-source` invariant not enforced for `image`-with-`imageHtml` blocks (no `data-line` → search jump silently no-ops)**
    - `transcript/block.php:13-14`, `session.js:410-411`. **Verified.** `confirmed` (rare path; the two runtimes are in sync with each other — drift is doc-vs-code). Fix: prefer restating the invariant honestly (doc-only).

### Config / packaging / hygiene (Medium)

26. **[MED] `host-agent-runtime` Finding 1 — `.env.example`/`.env` document env vars the implementation no longer reads (storage drifted to SQLite)**
    - `host-agent/.env.example:74,91-142` + `.env:72,80` (`QUOTA_LIVE_STATE_FILE`, `PUSH_SUBSCRIPTIONS_FILE`, `PUSH_STATE_FILE`, `PUSH_QUOTA_STATE_FILE`, `PUSH_QUOTA_CHECK_STATUS_FILE`, `push_check_status_file`). **Verified** — grep of `host-agent/` + `src/` returns zero code reads; storage is `Config::push_sqlite_path()` / `GlobalStateStore`. `confirmed`. Fix: prune the dead vars; keep only `PUSH_SQLITE_FILE`/`SESSIONS_SQLITE_FILE` etc.

27. **[MED] Consolidated: `Config::csm_repo_root()` is unquoted in every shell-command builder, breaking any clone path with a space**
    - `Config.php:274-317,337-367` (hook commands + quota command) and `StatuslineMarkerService.php:481` (statusline). **Verified.** `confirmed` (works today — no space in the deployment path — but silent-on-failure breakage for a spaced clone). *(host-agent-runtime Finding 3 + session-status-state F7 merged.)* Fix: `escapeshellarg()` on the path, or return a `[PHP_BIN, script]` pair.

28. **[MED] `host-agent-runtime` Finding 4 — `json_encode` of the response can return `false` on invalid UTF-8 → empty response → client's generic "Malformed response"**
    - `agent.php:30,40`; `AgentClient.php:42-48`. **Verified.** `confirmed`. Fix: `JSON_INVALID_UTF8_SUBSTITUTE` (or a `json_last_error()` fallback).

29. **[MED] `web-shell` F2 — `npm run typecheck` is red (134 errors); `types.d.ts` has drifted from the real `CSM_BOOTSTRAP`/page contracts**
    - **Verified by running `node_modules/.bin/tsc --noEmit`: exit 1, **134 errors**.** `confirmed`. `public/js/types.d.ts:7-16` omits `agentReachable` (set at `pages/index.php:222`, read at `index.js:585` → TS2339) and `Window` omits `CSM_ARCHIVED_BOOTSTRAP` (set at `archived-session.php:94`, read at `archived-session.js:71,104`). Plus a large `.closest`/`.value`/`.disabled` TS2339 family. Fix: the d.ts additions are web-shell-owned (do now); the `.closest`/`.value` mass is cross-subsystem (track separately).

30. **[MED] `host-agent-runtime` Finding 5 — `tests/run.sh` `rm -rf "$(dirname "$TMUX_SOCKET")"` is unguarded — catastrophic if it ever runs with an empty value**
    - `tests/run.sh:158,176`; the two sibling destructive ops are guarded (`:166,172`). **Verified.** `confirmed`. Fix: guard with the same `[ -n ... ] && [ != "$REAL_TMUX_SOCKET" ]` predicate.

### Lower-severity (representative, carried in subsystem audits)

- **[LOW]** `session-view` Finding 5 — duplicated block-cap truncation loop + paging walk across the 3 transcript backends (`TranscriptService.php:1618`, `AntigravityTranscriptService.php:241`, `OpenCodeTranscriptService.php:242` — **verified**, all identical `substr(...,"\n… (truncated)")`). Refactor: shared `apply_block_caps()`/`finish_page()`.
- **[LOW]** `session-core` F5 — `session_status.last_message` is written but never read by the listing (`SessionService.php:318` uses the transcript instead). Write-only column (drop it, or use it as a fallback).
- **[LOW]** `session-core` F7 — `agent_label` fallback renders "Claude Code" for any unknown agent id (`SessionService.php:287-291`). Misleading for a hand-edited sidecar or a future agent.
- **[LOW]** `session-core` F6 — `build_session_entry()` `@return` docblock omits `context_used_percentage`/`git_worktree` (`:96` vs `:324-325`).
- **[LOW]** `dashboard` Finding 3 — duplicated agent-badge `match` branching in `row.php:20-26` and `archived-row.php:7-13` (verify + a helper on `SessionRowView`).
- **[LOW]** `plan-files` Finding M2 — todo `status`→presentation mapping duplicated PHP (`todo-list.php:6-8`) / JS (`session.js:1174-1185`), no JS test guard.
- **[LOW]** `quota` Finding 5 — `quota_from_statusline_state()` and `antigravity_quota_state()` near-duplicate parsers + repeated first-non-null fallback. Refactor: one bucket-normalizer.
- **[LOW]** `push-notifications` Finding 4 — health "timer may not be running" threshold hardcoded 120s vs user-configurable interval up to 300s (`PushHealthService.php:54` vs `PushTimerService.php:52-55`).
- **[LOW]** `prompt-interaction` Finding 5 — `set_antigravity_model` ignores the confirm-Enter exit code → false success (`PromptInteractionService.php:611`).
- **[LOW]** `prompt-interaction` Finding 6 — mode vocabulary defined twice (`TranscriptView::MODE_OPTIONS` vs `PermissionMode`).
- **[LOW]** `web-shell` F3 — router cannot emit 405 for wrong-verb on GET-only routes; 404 body is plain text with no Content-Type.
- **[LOW]** `web-shell` F4 — CSRF hardening: no session-fixation mitigation, no cookie SameSite/HttpOnly; origin check lenient & partly redundant (defense-in-depth, not a CSRF break).
- **[LOW]** `web-shell` F5 — `require_post_json()` leaves mutation responses cacheable at the HTML page's `private, max-age=60`.
- **[LOW]** `uploads` Finding 5/6/7/8/9 — duplicated filter loop; `.gitignore` read/delete asymmetry; stale docblock (no `save_uploaded_file` on `SessionService`); theoretical TOCTOU; unbounded aggregate upload size.

---

## Cross-subsystem contracts & risks

**Two-runtime boundary.** Structurally sound and **verified**: the container never touches tmux/`/proc`, and the host agent never reaches into the container. The only crossing is the single bind-mounted socket. Every controller action resolves to a dispatcher case — cross-checked, all 44 controller actions (38 `dispatch_action` + 6 `dispatch_push_action`) have a handler, none fall through. No dispatcher/route/action drift. The one **physical** risk is the protocol's lack of a read timeout (see Priority Finding 8) — a wedged agent hangs the container request, and under `php -S` blocks the whole app.

**Co-report co-ownership joins.** The registered co-ownership model (SessionController + session.js/sidebar.js partitioned into prompt-interaction/plan-files/uploads/archived-sessions; StatuslineMarkerService quota block → quota; HealthBoxView view → dashboard while `PushHealthService::health_check()` → push-notifications; `test_sessions_lifecycle.php` shared harness) is coherent and now externally visible in the findings. Two joins deserve care at review time:
- `HealthBoxView`'s push-timer control renders a `0` from `DashboardController.php:44` when `interval_seconds` is missing → it will surface as a spurious `0s` preset. Low, but the VIEW is dashboard-owned while the threshold/interval logic is push-owned — a fix splits across two subsystems.
- The archived-detail fix (Priority 4) touches the archived-row/`archived-session.php` `'Claude Code'` hardcodes (archived-sessions Finding 4) and `SessionDetailService` (session-view) — three subsystems, one fix.

**Race / atomicity theme (the 2026-08-24 SQLite migration).** The migration correctly eliminated the worst JSON-file read-modify-write races, but a few remain:
- `SidecarStore` still has a read-modify-write in the hooks (`session_start.php:83→139-150`, `antigravity/pre_invocation.php:63→75-81`) — Priority 7.
- `PushSubscriptionStore` prune is a snapshot-replace that can clobber a concurrent subscribe — Priority 13.
- `quota_live_state_write.php` read→merge→write is not atomic and its docblock claims a transaction that isn't there — `quota` Finding 2 (MED).
- `push_session_state` is a whole-table replace by a single writer (timer) — safe today, but a transiently-absent session loses its edge-state for a tick — `push-notifications` Finding 6 (LOW, research).

**Documented-contract-vs-implementation drift** is a recurring theme (and the reason several "duplication is a bug" reads are wrong — the codebase convention says read the docblocks first): `update_status()` docblock (F3), `kill_bare_process()` docblock (F1), the `build_session_entry()` "EXCLUSIVELY SessionStatusStore" contract omits the Antigravity pane-scrape carve-out (`session-core` Finding F4), the `data-line`/`.copy-btn` invariant (session-view #3), and the `AgentAdapter` docblocks claiming callers that don't exist (agent-abstraction #1).

---

## Meta-pass results

### Coverage / gap pass
Diffed the union of all subsystems' `owned_paths` against `git ls-files` (excluding `vendor/`, `tests/`, `.claude/`). **Coverage is effectively complete — no genuine orphaned feature, so NO `NEW SUBSYSTEM REQUIRED`.** Findings:

- **`resources/tailwind.css`** (the source for `public/css/tailwind.css`, which web-shell owns) is not in any owned_paths. Minor: attribute to `web-shell`.
- **`docker-compose.yml`, root `.env.example`, `phpstan.neon`/`phpstan-baseline.neon`, `tsconfig.json`, `README/CONTRIBUTING/LICENSE`, `todo`** are infra/tooling/docs, not feature subsystems. Note: the working tree modifies `phpstan-baseline.neon` even though `CLAUDE.md` states PHPStan isn't enforced — a latent config-vs-convention mismatch worth a one-line note, not a new subsystem.
- **Attribution/inventory gap (not missing code):** `tests/test_agent_client_protocol.php` directly exercises `App\AgentClient` (`:28,94`) but isn't in web-shell's `owned_paths`/Tests list. Recommend the coordinator attribute it to `web-shell` (it's the natural home for the F1 read-timeout sad-path test).
- **Untracked-but-owned files** to commit before an open-source publish (flagged by host-agent-runtime): `host-agent/systemd/opencode-serve.service` (referenced by `install.sh:113-116`, enabled on install — must be committed), and the untracked `host-agent/opencode-plugins/` + `host-agent/opencode_diagnose.php` (owned by prompt-interaction). These are inventory/commit gaps, not subsystem gaps.

### Duplication / DRY pass (ranked by value)

1. **read/write-timeout gap** — 3 sites, 1 contract (Priority 8). *(Not DRY per se, but the highest-value single consolidation.)*
2. **`realpath`-boundary pattern** — the "resolve within a rooted dir" dance is reimplemented in **5+ places**: `SessionService::browse_dir` (`:474-484`), `SessionService::create_dir` (`:525-531`), `PlanFileService::resolve_plan_file_path` (`:96-102`) + `read_todo_file` (`:169`), `UploadService::resolve_upload_path` (`:197-213`). Each has its own guards; the write-paths (`uploads` #1, `plan-files` M1) are exactly the ones that **don't** apply the boundary. A single `HostAgent\Services\PathBoundary` helper (e.g. `resolve_within(string $root, string $name): ?string`) would centralize the rule and let every caller adopt the missing write-side guard. **Highest-value cross-cutting DRY win.**
3. **hook gate + stdin-decode preamble** — `getenv('CSM_SESSION_NAME')` + `json_decode` + `is_array` re-implemented in all **9** hook scripts. A tiny `HostAgent\Hooks\HookPayload` helper would remove ~6 lines × 9 scripts, and would make the F1 try/catch a 1-site change, not 9 (**session-status-state** F5).
4. **transcript block-cap + paging walk** — duplicated across the 3 transcript backends (session-view #5; **verified** identical `substr(...,"\n… (truncated)")` at `TranscriptService.php:1618`, `AntigravityTranscriptService.php:241`, `OpenCodeTranscriptService.php:242`). Shared `apply_block_caps()`/`finish_page()`.
5. **create/resume duplication** — `SessionLifecycleService::create_cc_session()` and `resume_cc_session()` are ~90% duplicate bodies (tmux-spawn wrapper, 300ms settle, still-alive re-check, eager sidecar write) — session-lifecycle Finding 3. ~45 near-verbatim lines; the highest-value refactor debt the 2026-08-24 split left.
6. **quota bucket-normalizer** — `quota_from_statusline_state()` vs `antigravity_quota_state()` near-duplicate parsers + repeated first-non-null fallback (quota Finding 5).
7. **agent-adapter flag-append** — ~6 copies of "push flag, push value" across the 3 adapters (agent-abstraction Finding 4). **Low**; keep it a per-adapter helper, no shared indirection layer.
8. **todo/plan JS mirrors** — inherent to the SSR+poll architecture with no build step; the PHP/JS duplication is deliberate. Don't introduce a build step. (plan-files M2.)
9. **agent-badge `match` branching** — duplicated in `row.php` + `archived-row.php` (dashboard #3); and note the JS `updateAgentFilterButtons` palette duplication (`index.js:776-796`) is a *separate* archived-sessions-side duplication.

### Over-abstraction verdict
**Not over-split.** The two seams that could look over-abstracted are actually the right size:
- **`AgentAdapter`** (7 methods, 3 adapters): The spawn-argv vocabulary genuinely differs per agent, so the seam is warranted. It is, however, slightly **ahead of its consumers** — `check_hooks()`/`install_hooks()`/`permission_mode_map()` have no production caller (verified: only tests; production routes through `HookService::*`/`PermissionMode::normalize_hook_permission_mode()` directly). That's a "contract ahead of wiring" issue (agent-abstraction #1, docblock fix), not a reason to shrink the interface. Do **not** add a cross-agent shared indirection layer for the flag-append idiom.
- **`SqliteDb`** as the single shared connection/schema helper for all Stores: correct. Two schema strings, not two helpers.
- Avoid the trap flagged in the audits: do **not** merge the three transcript service classes or the three prompt parsers — the per-backend storage shapes/predicates genuinely differ (documented as deliberate). Extract shared *helpers*, not shared *classes*.

### Schema-completeness pass (`SqliteDb.php` schema + state shapes)
Tables (`sidecars`, `session_status`, `pending_tools` — tmpfs `sessions.sqlite`; `push_subscriptions`, `push_session_state`, `push_quota_state`, `global_state` — persistent `push.sqlite`) are each keyed by their natural PK (`session_name`/`endpoint`/`bucket_key`/`key`). **No missing indexes that matter** at single-user scale; every lookup is a PK point-load, and the two whole-table-replace tables (`push_session_state`, `push_quota_state`) have a single writer (the timer). Findings:

- **Write-only column:** `session_status.last_message` — written by hooks and read back in `read_status()` but never consumed by the listing (session-core F5). **Verified.**
- **Migration drift:** `sidecars.agent` is handled by `add_column_if_missing()` (added 2026-08-24); the tmpfs lifetime means it self-resolves on reboot. But `add_column_if_missing()` swallows **all** `PDOException`s, not just "duplicate column" (session-status-state F6) — and it runs on every `db()` call, so an unrelated transient error is silently hidden.
- **`write_status()` drops `last_turn_error`** (session-status-state F4). **Verified.**
- **Live-vs-persistent split hazard:** `sessions.sqlite` is tmpfs (wiped on reboot) and holds ephemeral per-session state; `push.sqlite` is persistent (a phone's subscription must survive). This polarity is **correct and documented**. The genuine hazard is not the split but the **hook `command` paths embedded at install time** (`~/.claude/settings.json`, `~/.gemini/config/hooks.json`, the statusline script) — moving the repo after install breaks the hooks, which is a whole-repo design constraint noted in `host-agent-runtime`/`session-status-state`, not a schema defect.

### Dependency-ranking pass (interconnectedness map)

```
                        ┌────────────────────────────────────────┐
                        │  web-shell (container boundary)         │
                        │  AgentClient · AuthService · Router     │
                        │  (read-timeout @ socket) [Priority 8a]  │
                        └────────────────────────────────────────┘
                                        │  JSON over UNIX socket
                                        ▼
                        ┌────────────────────────────────────────┐
                        │  host-agent-runtime                    │
                        │  agent.php (read-timeout) [8b] ·       │
                        │  Sessions.php dispatcher · Config       │
                        └────────────────────────────────────────┘
                                        │
        ┌───────────────────────────────┼────────────────────────────────┐
        ▼                               ▼                                 ▼
┌──────────────────┐          ┌──────────────────┐            ┌───────────────────────┐
│  session-core    │◄────────►│ session-status-  │            │  agent-abstraction     │
│  SessionService  │          │ state (SqliteDb, │            │  AgentAdapter          │
│  TmuxService     │  (F1!)   │ Stores, hooks)   │ (F1! F2)   │  (resume/seam gaps #2)│
│  ProcessRunner   │          │ (F1 crash path)  │            └───────────────────────┘
│  ProcessInspector│          └──────────────────┘
└──────────────────┘                 ▲
   │  build_session_entry /          │  stores shared by all
   │  list_all_sessions              │
   ▼                                 │
┌───────────────────────┐   ┌───────────────────┐   ┌──────────────────┐
│  session-view         │   │  prompt-interaction│   │  archived-       │
│  (transcripts)        │   │  (blocked prompt)  │   │  sessions        │
└───────────────────────┘   └───────────────────┘   └──────────────────┘
   │                           │  depends on session-core + status-state
   ▼                           ▼
┌───────────────────────┐   ┌───────────────────┐   ┌──────────────────┐
│  session-lifecycle    │   │  dashboard         │   │  plan-files       │
│  (spawn/resume/kill)  │   └───────────────────┘   └──────────────────┘
└───────────────────────┘   ┌───────────────────┐   ┌──────────────────┐
                            │  uploads           │   │  quota            │
                            └───────────────────┘   │ push-notifications│
                                                    └──────────────────┘
```

**Where fixes should land first.** The foundation layer carries the most upstream risk and should be fixed first: **`session-core`** (F1 data-loss; F2 timeout; F3 amplification), then **`session-status-state`** (F1 crash-path; F2 sidecar race; F3/F4 doc/schema), then **`host-agent-runtime`** (agent.php timeout; .env dead vars; csm_repo_root quoting), then **`web-shell`** (AgentClient timeout; CSRF defaults). Fixing those de-risks every feature surface below them. The feature-surface findings (dashboard confirm bypass, uploads, push, quota, plan-files) are downstream and lower blast-radius — do them after the foundations, and do the **canned-fixture-vs-real-backend mismatch** (dashboard Cross-Cutting 1: `canned_agent.php:304-308` rejects mismatched ids, real `BareProcessService::take_over_bare_process_with_id()` doesn't) as part of the session-lifecycle/bare-process cleanup.

---

## What's done well

- **The two-runtime invariant is airtight and structurally enforced** (not by convention): the container can never become the first process to touch an unstarted tmux socket, so the tmux server can never be born inside the container namespace.
- **Zero dispatcher/route drift**: every controller action has a handler; verified all 44 actions resolve.
- **Re-validate-then-send is real and consistently applied** on every mutating path; `proc_open` array-form (no shell-string injection) is universal.
- **The SQLite migration eliminated the worst races** and its `update_status()` partial-update merge is genuinely immune to lost-field read-modify-write in the way that mattered; `SidecarStore::spawned_by_csm` three-state is handled with the correct `??`-fallthrough.
- **Info-rich, honest docblocks** that read like a changelog of live-found bugs — the convention works (read them before assuming over-engineering; they hold up).
- **Dense sad-path coverage at the service layer** (`test_push.php`, `test_file_uploads.php`, `test_quota.php`, `test_*_transcript.php`, `test_session_hook.php`, `test_agent_adapter.php`, `test_antigravity_quota_poll.php`) — happy **and** sad paths, structured results not crashes.
- **Persistent-vs-tmpfs SQLite split is the right polarity**, and the push stores' store-level atomicity (single upsert for `add_push_subscription`) is correct.
- **ES5 discipline held throughout** `public/js/*` (syntax-level; the `Promise.prototype.finally`/`fetch`/`URLSearchParams` APIs are app-wide, so a browser old enough to lack them isn't supported).
- **Markdown XSS posture is solid** and the PHP↔JS block-render mirror is kept in genuinely exact sync (the drift found is *doc-vs-code*, not JS-vs-PHP).

---

## Recommendations (ordered; highest-value first)

**(a) Crash / hardening**

1. **`session-core` F1** — make `all_tmux_panes()` distinguish query-failure from zero-sessions; make `prune_orphaned_sidecars([])` a no-op; add a sad-path test. *(Highest priority — silent, unrecoverable data loss.)*
2. **`session-status-state` F1** — wrap every hook's store-write region in `try/catch \Throwable` (log + still reach `exit(0)` / the Antigravity `decision` echo). Add exit-code assertions to the `run_status_hook_script()` harness.
3. **Consolidated read-timeout** (Priority 8) — bound the read on `AgentClient` (configurable, default generous ~30s) and `agent.php` (5s); bind `fwrite`/`json_encode`; add opt-in timeout + fd2→fd1 redirect to `ProcessRunner`.
4. **`host-agent-runtime` Finding 5** — guard the `rm -rf "$(dirname "$TMUX_SOCKET")"` in `tests/run.sh`.
5. **`quota` Finding 1** — guard `antigravity_quota_poll.php`'s write so a success-but-empty poll can't wipe prior good state.

**(b) Correctness**

6. **`session-view` Finding 1** — unify the OpenCode `line` cursor on the renderable-position index (fixes the silent live-view freeze). Add a mid-sequence-gap fixture.
7. **`archived-sessions` Finding 1** — route `archived_session_detail`/`history` cwd + detail through `TranscriptRouter`/`session_title()`.
8. **`prompt-interaction` Finding 1** — return `null` (reject) when a multiSelect is the last question until verified.
9. **`session-status-state` F2** — add `SidecarStore::update_sidecar()` and use it in `session_start.php`/`antigravity/pre_invocation.php`.
10. **`push-notifications` #1/#2/#3** — endpoint-based prune; consume transitions only on delivered/no-subscriptions; `sw.js` fallback-to-open on failed fetch.
11. **`dashboard` Finding 1** — replace inline `onsubmit` confirm strings with `data-confirm-label` + delegated listener (confirmation-guard bypass on destructive actions).
12. **`session-lifecycle` Finding 1** — reject `kill_bare_process` on a tracked session. Fix the `test_sessions_lifecycle.php:760` tautology.
13. **`plan-files` M1 / `uploads` #1** — make the write paths honor the realpath boundary (symlink escape / write-through).
14. **`quota` #3 / `uploads` #2** — keep `opencode_quota_state()` and the upload name encoding within their "no throw"/clean-failure contracts.

**(c) DRY / refactor**

15. **`host-agent-runtime` #3 + `session-status-state` F7** — `escapeshellarg()` the `csm_repo_root()` in every shell-command builder.
16. **`PathBoundary` helper** (DRY #2) — centralize the realpath-boundary rule and adopt the missing write-side guards.
17. **hook gate/decode helper** (DRY #3) — `HostAgent\Hooks\HookPayload` to de-duplicate the 9 copy-pasted preambles (and enable the #2 one-site try/catch).
18. **`session-lifecycle` Finding 3** — extract the shared create/resume spawn-and-confirm helper.
19. **`session-view` Finding 5** — shared `apply_block_caps()`/`finish_page()` across the 3 transcript backends.
20. **`quota` Finding 5** — one bucket-normalizer + first-non-null helper.
21. **`host-agent-runtime` #1** — prune the dead `.env.example`/`.env` push/quota/file vars.

**(d) Docs / tests**

22. Correct the contract-drift docblocks: `SessionStatusStore::update_status()` (F3), `kill_bare_process()` docblock, the `build_session_entry()` "EXCLUSIVELY SessionStatusStore" comment (add the Antigravity carve-out), the `data-line`/`.copy-btn` invariant (session-view #3), and the `AgentAdapter` docblocks claiming non-existent callers (agent-abstraction #1).
23. **`web-shell` F2** — fix the `types.d.ts` (add `agentReachable`, `CsmArchivedBootstrap`) and track the `.closest`/`.value` mass as a separate cross-subsystem cleanup; make `npm run typecheck` green.
24. **Test additions (never weaken existing):** the OpenCode `line`-gap forward-poll; the quote-bearing markdown parity inputs; a `main`-session mid-sequence gap.
25. **Session-status-state F3 wrap in a transaction** (optional; also correct the docblock) and pin the `add_column_if_missing` behavior (F6) to not swallow unrelated `PDOException`s.

---

## Meta-Audit Notes appendix

### Consolidated (merged to avoid cross-subsystem duplicates)
- **Read-timeout cluster** = `web-shell` F1 + `host-agent-runtime` #2 + `session-core` F2 → Priority 8. Three code sites, one theme; each still needs its own fix, but they're one ranked item.
- **Unquoted `csm_repo_root`** = `host-agent-runtime` #3 + `session-status-state` F7 → Priority 27.

### Rejected (or downgraded to note — kept out of the ranked list)
- **`agent-abstraction` #7 (uuid class cycle)** — audit itself concludes "confirmed benign, optional to break"; the cycle is inert (pure static, cached singletons). Distilled to a Low note, not a finding.
- **`session-lifecycle` 5d / `uploads` #8 / `push-notifications` #6 / `quota` #4 / `session-view` #7** — each audit frames these as `research-more`/`skip` with real justification (reachability, deliberate tradeoff, low likelihood, partly-by-design). Kept as low research notes, not actionable fixes.
- **`web-shell` F4 (CSRF hardening)** — explicitly **not a CSRF break**; the session-bound token + `hash_equals` is what actually prevents cross-site POST. The cookie/SameSite-flag suggestions are defense-in-depth tweaks, not a defect.
- **`uploads` #9 (unbounded total upload size)** — `skip`; arguably the user's own action on their own box, not a correctness/security defect.

### Demoted in severity
- **`session-lifecycle` Finding 1 (kill_bare on managed)** — HIGH→MED: reachable only via a crafted POST (the normal `bare[]` set already excludes tracked pids), so it's defense-in-depth, not a common-path bug.
- **`session-lifecycle` Finding 2 (stale test assertion)** — HIGH→MED: it's a test-integrity defect (guard fires nothing), not a runtime defect.
- **`agent-abstraction` #1** — MED→LOW: the real issue is a docblock/CONTRACT-ahead-of-wiring, not behavior; the unwired methods are correct and test-covered.
- **`dashboard` #4 (`interval_seconds`→0)** — LOW, informational: `PushTimerService` always returns the key, so it's a defensive edge, not a live bug.
- **`session-status-state` F3** — kept MD but the runtime risk is `narrowed`: the merged outcome is still race-free in the way that mattered; the docblock overstatement and the unconstrained ordering window are the real issues.

### Working-tree-drift notes
- **`session-view` audit** flagged its own verification against the dirty working tree (Antigravity/OpenCode transcript services, session.js/sidebar.js, SessionController, TranscriptView, partials, routes all modified); DETAILS.md's line anchors still land (drift is additive, not structural). I verified its findings against the same current tree. A re-scout before trusting DETAILS.md's method-line map as gospel is advisable.
- **`host-agent-runtime` audit** flagged two DETAILS.md staleness items: the dispatcher is **39** cases (not "37"), and the Config "storage env vars" section lists dead push/state file vars as live. Both confirmed.
- **Untracked files** (`host-agent/systemd/opencode-serve.service`, `host-agent/opencode_diagnose.php`, `host-agent/opencode-plugins/`) are owned (host-agent-runtime / prompt-interaction) but must be **committed before an open-source publish** — `install.sh:113-116` references and enables `opencode-serve.service`.
