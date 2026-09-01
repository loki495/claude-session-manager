---
id: uploads
based_on: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-26
---

# Audit: uploads — Compose-bar file uploads (session `.claude/uploads/`)

Verified against current HEAD `44e4caab492481c850d4dceec97d5c65e41a5b53` — **matches** the
`last_scanned_commit` in `DETAILS.md`, so the map is current, not stale. One material
correction/addition to the map noted below (the `SessionService::save_uploaded_file()` reference in
`DETAILS.md:35`-adjacent docblocks is a stale name left over from the pre-consolidation split).

Scope checked: `UploadService.php`, `UploadController.php`, `tests/test_file_uploads.php`, plus the
co-reported consume points (`SessionController.php` — confirmed **no** upload methods remain, no
leftover duplicate; `routes.php`; `public/js/session.js`; `public/js/sidebar.js`; `Controller.php`
guards; `AgentClient.php`).

---

## Finding 1 — Write path never goes through the realpath boundary that read/delete enforce

- **Recommendation:** `fix` (small hardening) — priority: **Medium**
- **Evidence:** `host-agent/lib/Services/UploadService.php:126-138` (`save_uploaded_file`),
  contrasted with `UploadService.php:197-213` (`resolve_upload_path`) which only `read_uploaded_file`
  (`:231`) and `delete_uploaded_file` (`:266`) actually use.
- **Current complexity / invalid states:** `save_uploaded_file` builds the destination as
  `$dir . '/' . $finalName` (`:136`) where `$finalName` comes from
  `unique_upload_filename($dir, sanitize_upload_filename($filename))` (`:134`). `unique_upload_filename`
  de-dupes with `file_exists($dir . '/' . $candidate)` (`:97`). `file_exists()` follows symlinks and
  returns `false` for a **broken** symlink. So if `.claude/uploads/` contains a *broken* symlink named
  exactly the candidate (e.g. `note.txt` → `/home/user/.bashrc`), `file_exists` says "no collision",
  the candidate is kept, and `file_put_contents($dir.'/note.txt', $decoded)` **writes through the
  symlink** to a path outside the uploads dir. This is the one place the subsystem's own guarantee
  ("uploads never leave the session dir") is not actually enforced: read/delete re-verify with
  `realpath` after `basename`, the write path does not.
- **Proposed representation:** Before writing, resolve the candidate with the same boundary rule (or,
  cheaper, reject any candidate that `is_link($dir . '/' . $candidate)` — a real upload name is never
  a symlink) and pick the next deduped name if it is. That closes the write-through-symlink hole and
  makes the write path symmetric with read/delete.
- **Smallest credible implementation scope:** `UploadService::save_uploaded_file` only — one
  `is_link()` guard (or reusing a `resolve_upload_path`-style helper) before `file_put_contents`, plus
  a test in `tests/test_file_uploads.php` planting a broken symlink and asserting the upload is
  rejected/skipped rather than written through it.
- **Regression risks / migration concerns:** None — behavior only tightens (a name that currently
  would've been written through a symlink now either dedupes to `name-1` or fails cleanly). The
  existing "never overwrite" guarantee (`photo.jpg` → `photo-1.jpg`) is unaffected.
- **Validation:** Existing `test_file_uploads.php` covers the collision/dedup paths (`:63-70`,
  `:89-91`) and traversal refusal (`:160-162`). Add: plant a broken symlink named `note.txt` in the
  fixture uploads dir, assert `save_uploaded_file` does **not** create/write the symlink target and
  returns either a deduped name or `ok:false`.
- **Confidence:** medium. **Severity:** medium (practical exploitability is **low** — `.claude/uploads`
  is created `0700` user-owned (`:128`) and only the host-agent (same OS user) writes there, so a
  planted symlink requires local FS access that already implies code execution; but the *asymmetry*
  is real and is exactly the crux the subsystem is supposed to guarantee, so it's worth closing).

## Finding 2 — Invalid-UTF-8 / legacy-charset filename silently breaks the JSON protocol

- **Recommendation:** `fix` — priority: **Medium**
- **Evidence:** `src/lib/Controllers/UploadController.php:92-93` passes `$file['name']` **raw** into
  the `agent_call` payload; `src/lib/AgentClient.php:39` does `fwrite($socket, json_encode($request))`
  with **no** failure check; `UploadService::sanitize_upload_filename` (`UploadService.php:76-83`)
  strips only `\x00-\x1f` control chars, not bytes `\x80-\xff`.
- **Current complexity / invalid states:** A client that submits a non-UTF-8 filename (a `curl -F
  'file=@x;filename=caf\xe9.txt'`, or any legacy Latin-1/binary escaped name) puts a raw
  invalid-UTF-8 byte into the `'filename'` array slot. PHP's `json_encode` (no
  `JSON_INVALID_UTF8_SUBSTITUTE`/`JSON_PARTIAL_OUTPUT_ON_ERROR` flag) **returns `false`**. `fwrite($socket,
  false)` writes nothing → `stream_get_contents` returns `''` → `json_decode('')` → `null` →
  `AgentClient` returns `['ok'=>false,'message'=>'Malformed response from host agent']`
  (`AgentClient.php:45-49`). The user sees "Failed to upload caf?.txt: Malformed response from host
  agent" — a failure that never reaches the service's own (correct) sanitizer, and no code path
  surfaces the real cause. It does not crash, but it is an unhandled, misleading failure for exactly
  the "legacy charset" class the subsystem is asked to cope with, and it is untested.
- **Proposed representation:** Normalize the filename to valid UTF-8 on the container side before
  building the payload (`mb_convert_encoding($name, 'UTF-8', 'UTF-8')` won't fix invalid bytes;
  use `iconv('UTF-8','UTF-8//IGNORE', $name)` or `preg_replace('//u', '', $name)` style stripping of
  invalid sequences, then fall back to `'upload'` if empty). Alternatively/additionally, make
  `AgentClient::agent_call` detect `json_encode` returning `false` and return a clean
  `['ok'=>false,'message'=>'Request could not be encoded']` rather than silently corrupting the
  conversation — a defensive fix that protects every action, not just uploads.
- **Smallest credible implementation scope:** `UploadController::upload()` (normalize `$file['name']`
  before relay) and/or `AgentClient::agent_call` (check the `json_encode` result). Two files, ~5 lines.
- **Regression risks / migration concerns:** Normalizing could slightly change the stored name for a
  genuinely-UTF-8-but-unusual name (e.g. a name with a lone combining mark) — negligible. Guarding the
  `json_encode` result in `AgentClient` cannot regress any working action since a previously-working
  request still encodes fine.
- **Validation:** Existing coverage doesn't touch this. Add a `test_file_uploads.php` case calling
  `UploadService::save_uploaded_file($session, "caf\xe9.txt", base64_encode('x'))` and asserting a
  clean `ok:false` (or an ASCII-safe name) rather than anything that would survive past the sanitizer.
  An integration-level check at `UploadController::upload()` (via `test_ui_smoke.php`) with a
  non-UTF-8 `filename=...` would need the canned agent and a modified fixture; at minimum unit-cover
  the new normalization helper.
- **Confidence:** high that the defect is real (PHP `json_encode` returns `false` on invalid UTF-8 by
  default). **Severity:** medium (real protocol-level failure, low reach since browsers send UTF-8
  names and the app is LAN-only single-user, but squarely in the asked-about "legacy charsets" box).

## Finding 3 — No client-side size guard; an oversized POST reports the misleading "No file uploaded"

- **Recommendation:** `tweak` — priority: **Medium**
- **Evidence:** `public/js/session.js:2425-2446` (`uploadOneFile`) never reads `file.size`; the
  compose-bar input has no `accept`/size limit (`src/partials/compose-bar.php:24`); the controller's
  only "too big" signals are `UPLOAD_ERR_INI_SIZE`/`UPLOAD_ERR_FORM_SIZE` →
  `'File too large'` (`UploadController.php:66-72`) and the catch-all "no file"/"No file uploaded"
  branch (`UploadController.php:56-60`). Server limits in `docker-compose.yml:29-30`:
  `upload_max_filesize=68M`, `post_max_size=70M`; app cap `MAX_UPLOAD_BYTES` default 64M decoded
  (`UploadService.php:66-69`).
- **Current complexity / invalid states:** A multi-GB file is selected and posted in full up to the
  php.ini caps. Between `upload_max_filesize` (68M) and `post_max_size` (70M) PHP raises
  `UPLOAD_ERR_INI_SIZE` → "File too large" (correct). **Above** `post_max_size` (70M), PHP drops the
  entire POST body → `$_FILES['file']` is absent → `is_array($file)` is false → **"No file uploaded"**
  (`UploadController.php:56-60`) — which is wrong and confusing: the user *did* pick a file. And
  >64M-≤68M raw files decode to >64M on the agent and are rejected there with "File too large (max
  64MB)" — fine, but only after the full file was already base64'd, socket-transferred, and decoded.
  So the size story is: server-enforced, client-silent, with one wrong message in the >70M band.
- **Proposed representation:** (a) A `file.size > MAX_UPLOAD_BYTES`-equivalent guard in `uploadOneFile`
  *before* posting, surfacing a clear status line immediately; (b) change the controller's
  no-file branch to also fire when `$_FILES['file']['error']` is `UPLOAD_ERR_NO_FILE`, keeping
  "File too large" for the ini/form-size case; (c) optionally expose the cap to the client
  (e.g. inline in `CSM_BOOTSTRAP`) so the guard uses the real value rather than a hardcoded number.
- **Smallest credible implementation scope:** `public/js/session.js` (add size check in
  `uploadOneFile`), `UploadController::upload()` (handle `UPLOAD_ERR_NO_FILE` explicitly), and the
  bootstrap/config if the cap is exposed. No host-agent change.
- **Regression risks / migration concerns:** `UPLOAD_ERR_NO_FILE` is the *only* "no file" state PHP can
  actually set for a missing `file` field, so branching on it is safe and strictly more precise than
  the current `is_array($file)` check. Client guard must not reject legitimately-sized files — use the
  same 64M/70M ceiling the server uses, with headroom, or the exposed cap.
- **Validation:** `test_ui_smoke.php:1067-1096` already asserts the no-file-field branch returns
  `ok:false`. Add an assertion (if feasible) posting a body over `post_max_size` and asserting
  `ok:false` + a size-specific message rather than "No file uploaded"; and a JS-level note that the
  client guard is unit-mirrored against the exposed cap.
- **Confidence:** high. **Severity:** medium (UX + wasted bandwidth; no crash).

## Finding 4 — Test coverage gap: controller sad-path branches are not exercised

- **Recommendation:** `refactor` (add tests) — priority: **Medium**
- **Evidence:** `tests/test_file_uploads.php` (the owner test) is PHP-CLI and can never reach
  `UploadController::upload()`/`view()`/`deleteOne()`/`deleteAll()`. The HTTP-level coverage lives in
  `tests/test_ui_smoke.php:1067-1096`, which covers upload: 405-for-GET, 403-wrong-CSRF, 200 happy
  relay, and the no-file-field branch — but **not** the `UPLOAD_ERR_INI_SIZE`/`UPLOAD_ERR_FORM_SIZE`
  → `'File too large'` branch (`UploadController.php:68`), the generic `'Upload failed (error code N)'`
  branch (`:74`), the `is_uploaded_file`/tmp-unreadable `'Could not read the uploaded file'` branch
  (`:81-85`), nor `view()`'s path through `stream_binary_result()`'s `502 'Malformed file data'`
  (`Controller.php:94-100`). The `deleteOne`/`deleteAll`/`list` controller relays are likewise untested
  at the HTTP layer in the uploaded-files space.
- **Current complexity / invalid states:** The subsystem's happy + sad paths are well covered at the
  *service* level (`test_file_uploads.php:53-202`), but the container-side controller — the only place a
  real `$_FILES` multipart upload enters and where the "File too large" friendliness actually lives —
  is under-tested on exactly the branches the CLAUDE.md sad-path rule cares about (oversized request,
  malformed tmp). Per the project rule "happy-path-only coverage is incomplete."
- **Proposed representation:** Add `test_ui_smoke.php` cases that (a) POST a body whose `file` exceeds
  `post_max_size` and assert `ok:false` with a size-specific message (see Finding 3), (b) POST with a
  `file` field whose `error` is set to a non-zero unexpected code (hard to force via `curl -F`, but a
  direct unit test of a small refactor — e.g. extracting the error-mapping into a pure function — would
  make it testable), (c) hit `/uploaded_file_view.php` with a malformed canned `data` to exercise the
  502 branch.
- **Smallest credible implementation scope:** `tests/test_ui_smoke.php` (add cases); optionally extract
  the `$_FILES['error']` → message mapping out of `UploadController::upload()` into a testable helper.
- **Regression risks / migration concerns:** None — adding tests only. Do not weaken the existing
  service-level assertions.
- **Validation:** The new tests themselves; plus re-run `tests/test_ui_smoke.php` and
  `tests/test_file_uploads.php`.
- **Confidence:** high that the branches are untested. **Severity:** medium.

## Finding 5 — Duplicated scandir/filter + resolve-or-not-found logic inside `UploadService`

- **Recommendation:** `refactor` — priority: **Low**
- **Evidence:** `UploadService.php:168-184` (`list_uploaded_files`) and `:298-308`
  (`delete_all_uploaded_files`) both implement the identical `.`/`..`/`.gitignore`/`is_file` filter
  loop; `:231-235` and `:266-270` both implement the same "resolve path or return not-found" shape
  (with the `.gitignore` short-circuit on top of delete).
- **Current complexity / invalid states:** Two near-verbatim copies of the entry filter mean the
  "what counts as an upload" rule lives in two places; if the rule ever changes (e.g. skipping a new
  special file like `.DS_Store`), it's easy to update one and miss the other. Low blast radius, but a
  genuine DRY opportunity fully inside the owned file.
- **Proposed representation:** A single private helper, e.g.
  `entries_to_operate_on(string $dir): array` returning the non-`.`/`..`/`.gitignore` regular-file
  names (or a small generator), used by both `list_uploaded_files` and `delete_all_uploaded_files`;
  plus a `resolve_or_not_found(string $workdir, string $filename): ?string`-style helper shared by
  `read_uploaded_file` and `delete_uploaded_file`.
- **Smallest credible implementation scope:** `UploadService.php` only — add the helpers, call them from
  the four methods. No interface change.
- **Regression risks / migration concerns:** Low; the helpers must preserve the exact semantics
  (skip `.gitignore`, count only `is_file` entries, `mtime` sort in list). Existing
  `test_file_uploads.php` assertions (count, `total_size`, `.gitignore` exclusion, `deleted` count)
  will catch any behavioral drift.
- **Validation:** Existing `test_file_uploads.php` (`:114-117`, `:170-179`) already pins the exact
  behaviors; re-run to confirm the refactor is behavior-identical.
- **Confidence:** high. **Severity:** low.

## Finding 6 — `.gitignore` read guard asymmetry (reads allowed, deletes refused)

- **Recommendation:** `tweak` — priority: **Low**
- **Evidence:** `delete_uploaded_file` short-circuits `basename($filename) === '.gitignore'` →
  `'File not found'` (`UploadService.php:256-258`) specifically to keep the bookkeeping file
  indistinguishable/non-deletable; `read_uploaded_file` (`:223-249`) has no such gate, so
  `/uploaded_file_view.php?filename=.gitignore` reads it back (content `"*\n"`). `list_uploaded_files`
  (`:169`) and `delete_all_uploaded_files` (`:299`) skip it; `save_uploaded_file` can't create it
  (sanitize strips the leading dot → `gitignore`, `:80`).
- **Current complexity / invalid states:** Only a directly-crafted URL reaches the read path with
  `.gitignore`; the UI never offers it (it's filtered from the list). Leaked content is a one-line
  `"*\n"`. So this is harmless today — but it's an inconsistency with the stated intent ("the special
  file isn't distinguishable"), and a reader keeps `.gitignore` reachable while a deleter doesn't.
- **Proposed representation:** Mirror the delete guard in `read_uploaded_file` (return `'File not
  found'`/`ok:false` for `basename($filename) === '.gitignore'`), or accept it deliberately and note
  the reasoning in a comment.
- **Smallest credible implementation scope:** `UploadService::read_uploaded_file` — one guard.
- **Regression risks / migration concerns:** None functionally; if the intent is truly "indistinguishable,"
  matching read to delete is the consistency fix. No real user-facing behavior changes (the UI never
  lists `.gitignore`).
- **Validation:** Add one `test_file_uploads.php` assertion that `read_uploaded_file($session,
  '.gitignore')` returns `ok:false` (mirroring `:164-166` for delete).
- **Confidence:** high the asymmetry exists; medium it's worth fixing given zero practical harm.
  **Severity:** low.

## Finding 7 — Stale docblock reference to a method that no longer exists

- **Recommendation:** `tweak` — priority: **Low**
- **Evidence:** `UploadController.php:35` docblock says "see `SessionService::save_uploaded_file()` in
  host-agent/lib/" — `host-agent/lib/Services/SessionService.php` has **no** `save_uploaded_file`
  (confirmed by grep); the method lives on `UploadService` (`UploadService.php:108`), reached via
  `dispatch_action` in `Sessions.php:185-190`. The comment predates the upload consolidation onto
  `UploadController`.
- **Current complexity / invalid states:** Misleading pointer for a reader tracing the write path; the
  name matches nothing, so it reads as if the container-side controller and the host-agent service are
  in different subsystems when they're the same feature. No runtime effect.
- **Proposed representation:** Point the comment at `UploadService::save_uploaded_file()`
  (or `Sessions.php`'s `dispatch_action` case).
- **Smallest credible implementation scope:** `UploadController.php:35` docblock text only.
- **Regression risks / migration concerns:** None.
- **Validation:** None needed (comment-only); the referenced test suite still passes.
- **Confidence:** high. **Severity:** low.

## Finding 8 — TOCTOU window between resolve and use (read/delete) — theoretical

- **Recommendation:** `research-more` — priority: **Low**
- **Evidence:** `resolve_upload_path` resolves (`:206`) then `read_uploaded_file` uses the returned
  absolute path with `file_get_contents` (`:237`) / `delete_uploaded_file` with `unlink` (`:272`).
  Between `realpath()` and the use, the file at that path could be swapped for a symlink (or, for
  `read`, its content replaced).
- **Current complexity / invalid states:** `realpath` is a snapshot; the subsequent `file_get_contents`
  would follow a symlink swapped in after the check (reading outside the dir, but only what the user
  can read), and `unlink` on a swapped-in symlink removes the *symlink*, not its target — so the
  destructive cases are largely self-limiting. `.claude/uploads` is `0700` user-owned
  (`UploadService.php:128`) and only the host-agent (same OS user) writes there, so a swap requires
  local filesystem write access that already implies code execution. **Not reachable from the web
  boundary** — this is the standard "author of the dir already has the system" caveat, not a remote
  vuln.
- **Proposed representation:** If hostile local code were in scope, re-open the file handle from a
  verified inode (e.g. `fopen` the resolved path with `O_NOFOLLOW`), or `lstat`-verify `is_link` right
  before use. Neither is warranted for a single-user LAN app today.
- **Smallest credible implementation scope:** None now (`research-more`); would be a
  `host-agent/lib/Services/UploadService.php` change plus tests if adopted.
- **Regression risks / migration concerns:** N/A.
- **Validation:** N/A.
- **Confidence:** high that it's not practically exploitable from the HTTP surface. **Severity:** low.

## Finding 9 — Unbounded aggregate size of the uploads directory

- **Recommendation:** `skip` (note) — priority: **Low**
- **Evidence:** `list_uploaded_files` sums sizes (`:179-183`) but nothing caps the *total* bytes of
  `.claude/uploads`; `max_upload_bytes()` (`:66-69`) only bounds a single file, and `save_uploaded_file`
  enforces it per-write (`:122`). A user can upload many 64M files and fill the session's project
  disk.
- **Current complexity / invalid states:** Disk exhaustion is a real (single-user) operational risk, but
  it is not a correctness/security defect and is arguably the user's own action on their own box.
- **Proposed representation:** If desired, a total cap alongside the per-file cap (reject a save that
  would push `list_uploaded_files()`'s `total_size` past a new `MAX_UPLOADS_TOTAL` config). This is
  optional and out of the feature's primary contract.
- **Smallest credible implementation scope:** `UploadService::save_uploaded_file` (compute current
  total, compare) + a config key + a test.
- **Regression risks / migration concerns:** A new cap could reject a legitimately large-but-under-cap
  project; choose a generous default.
- **Validation:** Existing `test_file_uploads.php` size assertions (`:99-104`) plus a new total-cap
  case.
- **Confidence:** high that no total cap exists; medium that it's worth adding.
  **Severity:** low.

---

## What's done well

- **Path-safety discipline is genuinely two-layered and correct for read/delete.** `basename()` in
  `resolve_upload_path` (`UploadService.php:206`) kills `../` and absolute-path traversal in the
  *name*; `realpath` + `str_starts_with($real, $realDir . '/')` (`:208`) follows and rejects a planted
  symlink that points outside. The trailing `/` in the prefix correctly prevents a sibling dir like
  `uploads-other/` matching (`$realDir . '/'` vs `uploads-other`), the same trick `browse_dir()`
  (`SessionService.php:482`) uses. `.`/`..` are rejected because `realpath($dir.'/..')` won't start with
  `$realDir . '/'`. The `test_file_uploads.php:160-162` traversal test is a real, on-target sentinel
  (`/etc/hosts` still exists).
- **Sad-path handling in the service is thorough and specific** — unknown workdir, malformed base64,
  size-limit, dir-creation failure, write failure, unreadable file, and the `.gitignore` special case
  all return `{ok:false, message:<specific>}` (`UploadService.php:112-138`, `:254-277`), never a crash.
  This matches the CLAUDE.md hard rule.
- **`unique_upload_filename` never silently overwrites** (`:90-103`), and `view()` correctly passes
  `immutable:false` (`UploadController.php:112`) because a re-upload can land on the same deduped
  filename with different content — a subtle correctness win documented inline.
- **Self-healing `.gitignore`** (`ensure_uploads_gitignore`, `:34-41`, called every save at `:132`) and
  the decision that `delete_all` leaves it behind (`:299`) are well-reasoned and covered
  (`test_file_uploads.php:86-87`, `:175`).
- **Memory handling on both runtimes** — the container bumps `memory_limit` to 512M scoped to the upload
  action (`UploadController.php:51`) *and* the host-agent does the same (`agent.php:21`), with the
  "found live 2026-08-22" context explaining why; that asymmetry the codebase itself documented is
  actually handled on both sides.
- **Two-runtime seam is respected** — the controller holds no filesystem logic, only relay + request
  validation (`UploadController.php:37-142`), and `SessionController.php` has **no** leftover upload
  methods (consolidation confirmed clean; `routes.php:71-78` wires only `UploadController`).
- **Co-reported JS is ES5-syntax-compliant** — `session.js`/`sidebar.js` upload code uses `var`,
  `function(){}`, no arrows/`const`/`let`/template literals or `Set`. (The `.finally()`/`fetch`/
  `URLSearchParams`/`Promise.resolve` are ES2015+ **APIs**, but the project already uses them
  app-wide — CLAUDE.md's ES5 rule is about syntax, and a browser old enough to lack
  `Promise.prototype.finally` isn't supported given the pervasive fetch/promise usage.) The
  `numberFormatOneDecimal`/`formatFileSize` drift fix that landed 2026-08-22 is correctly reflected in
  `sidebar.js:366-386`.
- **Coverage breadth at the service level is excellent** — `test_file_uploads.php` exercises
  happy+sad for sanitize/unique/save/list/read/delete/delete_all and `dispatch_action` wiring.

---

## Cross-cutting observations (described, not solved — out of owned scope)

- **`resolve_upload_path` is the third copy of the realpath-boundary pattern.** The same
  "realpath root, realpath candidate, `str_starts_with` prefix" dance recurs in
  `SessionService::browse_dir` (`SessionService.php:474-484`), `SessionService::create_dir`
  (`:525-531`), `PlanFileService::resolve_plan_file_path` (`PlanFileService.php:96-102`) and
  `read_todo_file` (`:169`). A single shared `HostAgent\Services\PathBoundary` helper (e.g.
  `resolve_within(string $root, string $name): ?string`) would centralize the boundary rule and let
  each caller also adopt the (currently missing) write-side guard from Finding 1. **Touches:**
  `session-input` (browse/create-dir), `session-view`/`plan-files`, `uploads`.
- **`stream_binary_result`'s 502 `'Malformed file data'` branch is untested** and is shared by the
  uploads `view()` path, plan-file content, and session/archived attachments
  (`Controller.php:94-100`). Its test coverage should live wherever attachments/plan-file audits run.
  **Touches:** `uploads`, `session-view` (plan files), `session-transcript` (attachments).
- **No aggregate upload quota** (Finding 9) overlaps the existing `QuotaService`/`QuotaController`
  subsystem — if a total cap is ever added, the config plumbing (`MAX_UPLOAD_BYTES` via
  `Config::csm_config`) and the quota concept are shared territories. **Touches:** `quota`/`config`.
- **`upload()` reads the entire file into memory and base64's it before the host-agent size check**
  (`UploadController.php:81-93`), so an oversized file (between the 64M app cap and 68M `post_max_size`)
  is fully transferred and decoded before being rejected. If uploads ever grow or multiple uploads are
  concurrent, gating size on the container side (from `$_FILES['file']['size']`) before the
  read+base64+relay would avoid the wasted work. **Touches:** `uploads` only, but is a candidate for a
  follow-up if Finding 3 is addressed.

## Out-of-scope (noted, not audited)

- Render/UI markup for the uploaded-files list and compose-bar attach controls
  (`src/partials/sidebar.php`, `src/partials/compose-bar.php`) — owned by session-view.
- `SessionService::send_message()`/`PromptInteractionService::send_message()` and the
  `[Attached: path]`/`attachments[]` send contract (`PromptInteractionService.php:654-666`,
  `session.js:2313-2317`) — owned by session-core.
- `App\AgentClient::agent_call()` transport and `App\Controllers\Controller` guard helpers
  (`require_post_json`/`start_readonly_json`/`stream_binary_result`) — owned by agent-abstraction /
  controller base, but Finding 2's `json_encode` gap and Finding 4's 502 branch are noted there as
  cross-cutting.
- `HostAgent\Stores\SidecarStore` workdir resolution and `HostAgent\Services\Config` — owned by
  session-status-state / session-lifecycle.
