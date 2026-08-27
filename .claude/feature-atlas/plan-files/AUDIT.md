---
id: plan-files
based_on: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-26
---

# plan-files — Sidebar plan/handoff + todo-file glance — Audit

Verified against `DETAILS.md` commit `44e4caab492481c850d4dceec97d5c65e41a5b53`. `git rev-parse HEAD` is exactly that commit, so the map is current — no re-scout needed before trusting this audit. All `file:line` references were re-read from the live source, not taken on faith from `DETAILS.md`.

## Findings (most severe first)

### M1 — `read_todo_file()` does not actually enforce the realpath boundary its own docblock claims (symlinked `todo` reads outside the workdir)

- **Recommendation:** `fix`
- **Priority/severity:** `medium`
- **Evidence:** `host-agent/lib/Services/PlanFileService.php:169-181` vs its own contract at `:154-156`. The docblock says "Same realpath boundary discipline as resolve_plan_file_path() (never trust a caller...)". The code does `$realDir = realpath($workdir)` then builds `$path = $realDir . '/todo'` and reads it with `is_file()` + `file_get_contents()` — but never calls `realpath($path)` and never checks the resolved target is inside `$realDir`. `resolve_plan_file_path()` (`:86-109`) does exactly this check; `read_todo_file()` does not.
- **Current complexity / invalid states:** If the session workdir contains an entry literally named `todo` that is a symlink to a regular file anywhere else on the host (e.g. `todo -> /etc/passwd`, or `todo -> ../sibling-project/notes.md`), `is_file($realDir . '/todo')` follows the link to the target, `file_get_contents` reads the target, and its full contents are base64-encoded and shipped to the browser. `read_plan_file` is armored against an analogous case (a `.md` symlink pointing outside is rejected by `:102-106`), so the two methods are inconsistent: the path that takes only a hard-coded, non-extension-filtered name silently drops the boundary just because the name is trusted, while the path that takes a caller-supplied name keeps it.
- **Proposed representation:** Mirror `resolve_plan_file_path()`'s discipline. Prefer the existing private helper rather than a new one:

  ```php
  $path = self::resolve_plan_file_path($workdir, 'todo'); // no — 'todo' fails the .md check
  ```

  so instead add the boundary inline, e.g. after `$realDir` is confirmed:

  ```php
  $real = realpath($realDir . '/todo');
  if ($real === false || !str_starts_with($real, $realDir . '/')) {
      return ['ok' => false, 'message' => 'No todo file in this session\'s working directory'];
  }
  if (!is_file($real)) { return [...]; }
  $data = file_get_contents($real);
  ```

  The key point is that `str_starts_with($real, $realDir . '/')` collapses the symlink into its target and rejects any target outside the workdir, exactly as `:104` does. The "no file" message is reused for both the absent and the escaping-symlink case (both are "you don't have a readable `todo` in your cwd"), so no new error shape is introduced.
- **Smallest credible implementation scope:** `host-agent/lib/Services/PlanFileService.php` only — the `read_todo_file()` body (`:169-186`). Controller, `Sessions.php`, JS, and partials are untouched.
- **Regression risks / migration concerns:** Low. The change only narrows what is readable, and only in the already-absent/file-gone case. A legitimate in-workdir `todo` (the normal case, and the one the test fixture at `tests/test_sessions_lifecycle.php:530` covers) still resolves inside `$realDir` and passes unchanged. The only behavior that changes is a `todo` symlink escaping the workdir, which today is read and would now error — that is the intended tightening.
- **Validation:** Existing coverage — `tests/test_sessions_lifecycle.php:522-535` (ok=false when no `todo`, ok=true + content round-trip when it exists). Add one assertion for the new case: write a `todo` symlink to a file outside the fixture dir and assert `ok = false`. Also assert a `todo` that is a *directory* still yields `ok = false` (covered implicitly by the existing `is_file` check at `:177`, but worth pinning).
- **Confidence:** `high` — the missing check is unambiguous and the fix is a direct port of the sibling helper's boundary.

---

### M2 — Todo `status`→presentation mapping is duplicated verbatim in PHP and JS with no test guarding the JS mirror

- **Recommendation:** `research-more`
- **Priority/severity:** `medium`
- **Evidence:** `src/partials/sidebar/todo-list.php:6-8` (the PHP mapping: `completed → text-slate-500 + emerald check + line-through`, `in_progress → text-indigo-400 + indigo dot + activeForm`, else `text-slate-600 + empty circle + content`) is restated character-for-character in `public/js/session.js:1174-1185` (`renderTodoList()`), with `TranscriptView::render_todo_list_html()` (`src/lib/Views/TranscriptView.php:292-301`) as a thin wrapper over the partial. `tests/test_ui_smoke.php:921-937` asserts the *server-rendered* outcome for all three statuses (checkmark+strikethrough, indigo dot + activeForm, slate circle + content), but there is no test exercising `renderTodoList()`'s JS output.
- **Current complexity / invalid states:** Three places must stay in lockstep (PHP partial, PHP wrapper, JS mirror). `DETAILS.md` §9 and the repo itself document this as intentional PHP/JS mirroring, and the no-bundler/no-JS-DOM test harness means the mirror cannot drift-detected automatically. Any change to a status's icon/class/label made only on one side shows different rendered output at initial page load vs. the next poll (initial render is server-side via the partial; every poll after that is `renderTodoList()`). This is exactly the class of drift that already bit `formatFileSize()` (`sidebar.js:372-377`, "found live 2026-08-22") where the PHP and JS formatting diverged at >=1000 KB/MB.
- **Proposed representation:** Keep the mirror (it is inherent to the server-render + poll architecture and removing it needs a build step this repo deliberately does not have), but de-risk the divergence. Practical options, in increasing effort: (a) add a comment cross-referencing the two sides to a single canonical fixture, (b) extract the status→presentation mapping into one shared JS object that both `renderTodoList()` and any future consumer read (still duplicated vs PHP, but single-sourced on the JS side), (c) accept the duplication as-is and track it. Given the test harness cannot execute ES5 DOM, a true parity test is not currently achievable.
- **Smallest credible implementation scope:** `public/js/session.js` (and `src/partials/sidebar/todo-list.php` if a shared mapping is extracted) — but the recommended action here is to add a cross-reference comment or a status→presentation constant, not a structural rewrite. Do not add a build step.
- **Regression risks / migration concerns:** None for (a). For (b), low risk but touches the hot poll path; keep the rendered markup byte-identical so `test_ui_smoke.php:921-937` (server-rendered) is unaffected and the poll-time JS output remains visually identical.
- **Validation:** Existing — `tests/test_ui_smoke.php:921-937` (server-rendered), `:1426-1427` (empty section). No current validation covers the JS mirror; a manual check would be to load a session's page, let a poll fire, and confirm the Tasks icons match the initial server render. Flagging this as a gap per the repo's happy/sad-path expectations.
- **Confidence:** `medium` — the duplication is verifiable but the appropriate remedy is a judgment call, hence `research-more` rather than `fix`/`refactor`.

---

### L1 — `resolve_plan_file_path()` accepts a directory named like a valid plan file, and `list_plan_files()` vs `read_plan_file()` disagree on outside-workdir `.md` symlinks

- **Recommendation:** `fix`
- **Priority/severity:** `low`
- **Evidence:** `host-agent/lib/Services/PlanFileService.php:58-63` (`list_plan_files` filters with `is_file($full)`, so a *directory* named `foo.md` is never listed) vs `:102-106` (`resolve_plan_file_path` checks only `realpath(...)` existence + the string-prefix boundary, never `is_file`), so `read_plan_file($session, 'foo.md')` on a directory named `foo.md` passes resolution and then `file_get_contents($path)` at `:132` hits a directory and returns `false` after emitting `E_WARNING` ("Failed to open stream: Is a directory"), surfacing as `'Could not read file'` at `:134-136` — a specific handled result, not a crash, but a warning that can corrupt the host-agent JSON response if `display_errors` is on.
- **Current complexity / invalid states:** Two close-but-not-identical notions of "what is an allowed plan file" live in the same class: listing excludes directories and non-files via `is_file`; reading does not. Separately, a top-level `.md` *symlink* whose target lives outside the workdir is listed by `list_plan_files` (`is_file($full)` follows the link to a real file, and `:65-69` report the *target's* size/mtime) but then rejected by `read_plan_file` (`:102-106` boundary), producing a dead "Plan/handoff files" row that is unreadable and leaks the target's name/size/mtime. The two methods therefore disagree, and the failure is user-visible as a clickable-but-broken link.
- **Proposed representation:** Make `resolve_plan_file_path()` the single authority and have it return `null` for non-regular-files too — add `if (self::... is_file($real))` (or check `is_file` on the resolved target) before returning `$real`. That one change makes read reject a directory-named `*.md` cleanly (no `E_WARNING`, no JSON corruption) and reject an outside-target symlink *before* `file_get_contents`. Optionally make `list_plan_files` skip symlinks whose target is outside `realpath($workdir)` so the list and read agree (avoids the dead row). Keep the two methods sharing the resolution check so the "what is a plan file" definition lives in exactly one place.
- **Smallest credible implementation scope:** `host-agent/lib/Services/PlanFileService.php` — add an `is_file` guard in `resolve_plan_file_path()` (and, if desired, a realpath filter in the `scandir` loop). No interface changes; the return contract is unchanged.
- **Regression risks / migration concerns:** Low. `read_plan_file` on a real file is unaffected; the only changes are a directory-named-`*.md` now returns `'File not found'` (via `resolve === null`) instead of a warning-path `'Could not read file'`, and outside-target `.md` symlinks become non-listed. No existing test asserts a directory-named-`*.md` is readable, so nothing regresses.
- **Validation:** Existing — `tests/test_sessions_lifecycle.php:511-516` covers non-`.md`, README/CLAUDE, nonexistent, traversal, and subdirectory paths. Add: `read_plan_file` on a directory named `notes.md` returns `ok = false` and emits no warning; `list_plan_files` omits a `.md` symlink whose target is outside the fixture workdir.
- **Confidence:** `high` for the `is_file` gap; `medium` for the symlink-listing follow-on (judgment on whether to also filter the list).

---

### L2 — `list_plan_files()` silently returns an empty list when the workdir is unreadable, masking a permission problem as "no plan files"

- **Recommendation:** `tweak`
- **Priority/severity:** `low`
- **Evidence:** `host-agent/lib/Services/PlanFileService.php:43-45` returns `['ok' => true, 'files' => []]` only when `!is_dir($workdir)`, but `:50` `scandir($workdir) ?: []` collapses a *readable-but-unreadable* workdir (e.g. chmod removes read on the dir, or an I/O error) into the same `['ok' => true, 'files' => []]`. The browser then renders "No plan/handoff files found" (`sidebar.js:463-465`), indistinguishable from a genuinely empty project.
- **Current complexity / invalid states:** The `ok = true` + empty list contract is deliberately used for the "workdir gone" case (an empty glance is the right UX for a session whose dir vanished). But the same shape is also emitted for "workdir exists but cannot be read", conflating two states. By contrast, `read_todo_file` distinguishes the gone dir (`:171-173`) from a present-but-unreadable file (`:183-185`), so the two methods are asymmetric in their sad-path granularity.
- **Proposed representation:** Detect a `scandir` failure explicitly and return `['ok' => false, 'message' => 'Could not read working directory']` when `is_dir($workdir)` is true but `scandir($workdir)` is `false`, keeping `['ok' => true, 'files' => []]` only for the genuine empty/gone case. Add a small `message` path in `sidebar.js`'s `loadPlanFiles()` failure branch (`:456-458`) if you want the user to see it, or leave empty as-is if "empty glance" is preferred — the fix is about not silently masking a real failure.
- **Smallest credible implementation scope:** `host-agent/lib/Services/PlanFileService.php:50` (branch on `scandir` returning `false`) plus at most a one-line `message` tweak in `sidebar.js:456-458` if surfaced. No contract change for the success path.
- **Regression risks / migration concerns:** Low. If `ok = false` is introduced, the JS already handles the `!data.ok` branch (`sidebar.js:456-458`) so no new code path is needed on the client. Existing UI smoke tests use a canned agent that always returns `ok = true`, so they are unaffected.
- **Validation:** Existing — `tests/test_sessions_lifecycle.php:477,492-498` cover unknown-sidecar and a real readable dir. Add: a workdir that exists but is unreadable (or a `scandir` that fails) returns `ok = false`, while a genuinely absent dir still returns `ok = true, files = []`.
- **Confidence:** `medium` — the failure mode is real but only reachable under unusual permission/I-O conditions; the correct UX (error vs empty glance) is a judgment call.

---

## What's done well

- **Genuinely read-only.** Grep of `PlanFileService.php` for `unlink|file_put_contents|mkdir|rmdir|touch|rename|copy|fwrite|fopen` returns nothing. All three methods call only `read_sidecar`, `is_dir`, `scandir`, `pathinfo`, `is_file`, `filesize`, `filemtime`, `file_get_contents`, `base64_encode`, `realpath`. No write/delete/rename surface exists, matching the boundary's stated design.
- **Workdir never trusted from the caller.** Every method re-derives it via `SidecarStore::read_sidecar($sessionName)` (`:36`, `:119`, `:162`); the client only ever supplies `session` and `filename`. Confirmed both in the service and in the controller (`SessionController.php:215-222`, `:421-430`, `:440-448`).
- **`read_plan_file` is genuinely armored.** `resolve_plan_file_path()` (`:86-109`) blocks `../` traversal, absolute paths, subdirectory paths (via the `basename()` collapse), and outside-workdir symlink targets (via the `realpath` + `str_starts_with($real, $realDir . '/')` boundary). The `basename()`+`.md`+README/CLAUDE.md re-checks mean "a caller-supplied filename" cannot reach any file `list_plan_files` would not have surfaced. Covered by `tests/test_sessions_lifecycle.php:511-516`.
- **Sad paths return specific results, not crashes.** Unknown workdir, gone workdir, missing file, and unreadable file each produce a distinct `ok = false` + message (`:39-41`, `:122-124`, `:128-130`, `:134-136`, `:165-185`); `Controller::stream_binary_result()` (`src/lib/Controllers/Controller.php:84-90`) maps `ok = false` to a 404 with the message body, so the new-tab plan path never yields a blank/500.
- **ES5 discipline maintained.** The new todo-file click handler and `loadPlanFiles()` (`sidebar.js:439-510`) and `renderTodoList()` (`session.js:1158-1190`) use `var`/`function`, string concatenation (`+`), `encodeURIComponent`, no `const`/`let`/arrow functions/template literals — matching the repo's mobile-Safari constraint.
- **`DETAILS.md` is current.** HEAD equals `last_scanned_commit`, so the map accurately describes the source.

## Out-of-scope / cross-cutting observations

- **Todo data shape ownership (`session-view`).** The `activeForm` access on `in_progress` and the `status` enum are `session-view`'s `detail['todos']` contract (via `SessionDetailService`/`TranscriptService`). If a todo is `in_progress` without `activeForm`, `todo-list.php:8` and `renderTodoList()` would render an unescaped empty label + a PHP notice. Touches `session-view`.
- **Mirror drift mechanics.** M2's PHP/JS mirror is the same intentional pattern used across the sidebar (uploaded-files list, session rows). A closer look at whether that pattern needs a shared fixture would be a `session-view`-level concern, not `plan-files` alone.
- **`stream_binary_result` 404 handling (`session-core`/`Controller`).** The `ok = false → 404` mapping that make the plan-file new-tab path graceful lives in `Controller.php`, which is not owned by `plan-files`; only its correct use is in scope here.
- **`base64` over the wire** (both plan content and todo) is a deliberate protocol choice; a more compact/`text` transport would be a `host-agent-runtime`/protocol concern, not this subsystem's.
