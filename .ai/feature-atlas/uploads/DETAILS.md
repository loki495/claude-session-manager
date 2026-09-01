---
id: uploads
name: Compose-bar file uploads (session `.claude/uploads/`)
owned_paths: [host-agent/lib/Services/UploadService.php, src/lib/Controllers/UploadController.php, tests/test_file_uploads.php]
last_scanned_commit: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# Subsystem: uploads — Compose-bar file uploads (session `.claude/uploads/`)

## Identity

- **id:** `uploads`
- **name:** Compose-bar file uploads (session `.claude/uploads/`)

## Ownership boundary

**In scope (OWNED):**

- `host-agent/lib/Services/UploadService.php` — all filesystem logic: resolving a session to its project workdir, sanitizing/suffix-deduping filenames, the `.claude/uploads/` directory contract, the `.gitignore` guard, size limit, and save/list/read/delete/delete-all operations.
- `src/lib/Controllers/UploadController.php` — the six HTTP endpoints that relay upload operations over the `AgentClient` UNIX-socket protocol to the host-agent. This is the only place a real `$_FILES` multipart upload is received.
- `tests/test_file_uploads.php` — self-contained test exercising `UploadService` + `dispatch_action()` wiring against a fixture sidecar and a throwaway temp workdir.

**Out of scope (neighbors):**

- **session-view** — the uploaded-files *listing UI* and the compose-bar *attach UI* (partials + ES5 JS). The upload endpoints that this subsystem serves are consumed by them, but the render/UI logic is physically owned by session-view.
- **session-core** — `SessionService::send_message()`/`PromptInteractionService::send_message()` consume the uploaded `.claude/uploads/<name>` path as `attachments[]`/`[Attached: path]` at send time. The path-format contract crosses here, but message-send logic is session-core.
- **agent-abstraction** — `App\AgentClient::agent_call()` (the container→host JSON protocol) is the transport this controller uses; it is not owned here.
- **session-status-state / session-lifecycle** — `SidecarStore` (which `UploadService::session_workdir()` reads) is not owned here.

The task's boundary note said the "upload controller methods" were on `SessionController` (`uploaded_*`/`upload_file`); in the current code those methods have been consolidated onto `UploadController` (owned here) — `SessionController.php` has **no** upload methods (confirmed by grep, no matches). The upload-list/attach UI that the note attributes to session-view is recorded under Co-owned below.

## Key implementation files

- **`host-agent/lib/Services/UploadService.php`** — the whole server-side brain. Static class with no instance state; every method either computes a path/name or performs a filesystem operation against the session's real project working dir. Only asset here is `ensure_uploads_gitignore()` writing the self-healing `.gitignore`.
- **`src/lib/Controllers/UploadController.php`** — thin container-side endpoints. Each method: run a guard helper, read params from `$_GET`/`$_POST`/`$_FILES`, build an `agent_call([...])` payload, `json_encode` (or stream) the agent's result. No filesystem logic of its own (the container has no access to the session's real workdir).
- **`tests/test_file_uploads.php`** — the only test that directly exercises `UploadService`. Runs `save/list/read/delete/delete_all` against a temp workdir with a fixture sidecar, plus `dispatch_action()` routing for all four actions.

## Public interfaces & contracts

### host-agent/lib/Services/UploadService.php

All methods are `public static`; the four action methods are what `dispatch_action()` in `host-agent/lib/Sessions.php` routes to (see Call sites). The two shared helpers (`resolve_upload_path`, `unique_upload_filename`) are public so tests can hit them directly.

- **`upload_dir(string $workdir): string`** (`UploadService.php:18`) — returns `rtrim($workdir, '/') . '/.claude/uploads'`. Always the `uploads` dir inside the session's own project working dir (not a global app dir) so Claude Code can `Read()` it relative to its own cwd.

- **`ensure_uploads_gitignore(string $dir): void`** (`UploadService.php:34`) — writes `"*\n"` to `$dir/.gitignore` if it doesn't already exist. Called on **every** save, not just on dir creation, so it survives a `delete_all` wiping the dir back to empty. Rationale recorded inline: `.claude/` is not reliably gitignored (checked against this very repo during dev), so without this an upload shows as untracked in `git status`.

- **`session_workdir(string $name): ?string`** (`UploadService.php:52`) — reads `SidecarStore::read_sidecar($name)` and returns `$sidecar['workdir']` if it's a non-empty string, else `null`. **Precondition implied:** only app-spawned sessions have a sidecar `workdir` (set by `create_cc_session()`); a bare/manually-attached session has no known workdir, so it consistently returns `null` to every action → `'Unknown working directory for this session'`.

- **`max_upload_bytes(): int`** (`UploadService.php:66`) — `(int)Config::csm_config('MAX_UPLOAD_BYTES', (string)(64 * 1024 * 1024))`. Independent, friendlier-error check mirroring the php.ini limits raised in `docker-compose.yml`.

- **`sanitize_upload_filename(string $filename): string`** (`UploadService.php:76`) — `basename(trim($filename))`, strips control chars `\x00-\x1f`, `ltrim` leading dots, falls back to `'upload'` if empty. Guarantee: the result is a single safe basename with no separators/control chars/leading dot — a client cannot inject `../../etc/passwd`, a leading `.` (hidden file or `.`/`..`), or a null byte.

- **`unique_upload_filename(string $dir, string $filename): string`** (`UploadService.php:90`) — appends `-{i}` before the extension (or after the whole name if no extension) until the name no longer collides with an existing file in `$dir`. Never silently overwrites an earlier upload.

- **`save_uploaded_file(string $sessionName, string $filename, string $base64Content): array`** (`UploadService.php:108`) — the write path. Returns `array{ok:bool, message?:string, filename?:string, path?:string, size?:int}`. Error cases: unknown workdir (`null`), malformed base64 (`base64_decode(..., true) === false`), file too large (> `max_upload_bytes()`), can't create dir, can't write file. On success: `{ok:true, filename:<finalName>, path:'.claude/uploads/<finalName>', size:<decoded bytes>}`. The `path` value is what the compose bar stores in `pendingAttachments` and later sends as `attachments[]` — it is relative to the session's project cwd.

- **`list_uploaded_files(string $sessionName): array`** (`UploadService.php:151`) — returns `array{ok:bool, message?:string, files?:array<int,array{name:string,size:int,mtime:int}>, total_size?:int}`. Filters out `.`/`..`/`.gitignore` and any non-file entry; `total_size` is the real sum of file sizes (`.gitignore` bytes not counted). If the uploads dir doesn't exist yet, returns `{ok:true, files:[], total_size:0}` (not an error). Results sorted newest-first by `mtime`.

- **`resolve_upload_path(string $workdir, string $filename): ?string`** (`UploadService.php:197`) — resolves `$filename` inside the uploads dir with a `realpath` boundary check. `basename()` alone already stops plain `../` in the filename, but the `realpath` + `str_starts_with($real, $realDir . '/')` check additionally blocks a symlink planted inside the uploads dir pointing elsewhere. Returns `null` if the uploads dir doesn't exist or the resolved path escapes the dir. This is the shared guard used by `read_uploaded_file` and `delete_uploaded_file`.

- **`read_uploaded_file(string $sessionName, string $filename): array`** (`UploadService.php:223`) — returns `array{ok:bool, message?:string, data?:string, media_type?:string, filename?:string}`. `data` is base64 of the file bytes; `media_type` is `mime_content_type($path) ?: 'application/octet-stream'`; `filename` is `basename($path)`. Error: unknown workdir, not found (boundary-check failure), unreadable file.

- **`delete_uploaded_file(string $sessionName, string $filename): array`** (`UploadService.php:254`) — returns `array{ok:bool, message?:string}`. First short-circuits if `basename($filename) === '.gitignore'` → `{ok:false, message:'File not found'}` (internal bookkeeping, deliberately identical to any other not-existing name so the special file isn't distinguishable). Then unknown-workdir / not-found / unlink-failure all return `{ok:false, ...}`; success is `{ok:true}`.

- **`delete_all_uploaded_files(string $sessionName): array`** (`UploadService.php:282`) — returns `array{ok:bool, message?:string, deleted?:int}`. Skips `.`/`..`/`.gitignore`; unlinks everything else, counting successes. Missing dir returns `{ok:true, deleted:0}` (safe no-op). `.gitignore` survives → a follow-up save doesn't need to recreate it from an unprotected window.

### src/lib/Controllers/UploadController.php

Each method is a thin relay; the only logic present is request-shape validation and the `upload()` memory-limit bump. All actions route to `App\AgentClient::agent_call([...])`.

- **`list(): void`** (`UploadController.php:18`) — GET-only. `start_readonly_json()`. Reads `$_GET['session']`, calls `agent_call(['action'=>'list_uploaded_files','session'=>$sessionName])`, echoes the JSON result verbatim.

- **`upload(): void`** (`UploadController.php:37`) — POST-only (`require_post_json()`). First line after the guard: `ini_set('memory_limit', '512M')` (**`UploadController.php:51`**) — found live 2026-08-22: a phone-video upload hit "Allowed memory size exhausted" under the container's 128M default, because one request holds several copies (raw bytes + ~1.37× base64 + the whole `agent_call()` JSON payload) simultaneously. Scoped to just this action, not the container's php.ini. Reads `$_POST['session']` and `$_FILES['file']`. Handle error codes: missing `$_FILES['file']`/`error` → `{ok:false, message:'No file uploaded'}`; `UPLOAD_ERR_INI_SIZE`/`UPLOAD_ERR_FORM_SIZE` → `{ok:false, message:'File too large'}` (friendlier than the generic fallback, since this is the failure a user actually hits); any other non-`UPLOAD_ERR_OK` → `{ok:false, message:'Upload failed (error code N)'}`; unreadable tmp → `{ok:false, message:'Could not read the uploaded file'}`. On success reads tmp bytes, base64-encodes, and relays `{action:'save_uploaded_file', session, filename, content_base64}`.

- **`view(): void`** (`UploadController.php:104`) — GET-only binary. Calls `AuthService::start_app_session()` directly (not the two guard helpers), then `stream_binary_result(agent_call(['action'=>'read_uploaded_file',...]), immutable:false, inlineText:true)`. Passing `immutable:false` because a re-upload can land on the same de-duplicated filename with different content; passing `inlineText:true` so a text file renders (not downloads) in the new tab.

- **`deleteOne(): void`** (`UploadController.php:120`) — POST-only. `require_post_json()`. Reads `$_POST['session']` and `$_POST['filename']`, relays `{action:'delete_uploaded_file',...}`.

- **`deleteAll(): void`** (`UploadController.php:134`) — POST-only. `require_post_json()`. Reads `$_POST['session']`, relays `{action:'delete_all_uploaded_files',...}`.

## Major call sites

**Host-agent dispatch (entry to OWNED service — `host-agent/lib/Sessions.php`, not owned):**

- `dispatch_action()` switch cases: `save_uploaded_file` (`Sessions.php:185`), `list_uploaded_files` (`Sessions.php:192`), `read_uploaded_file` (`Sessions.php:195`), `delete_uploaded_file` (`Sessions.php:198`), `delete_all_uploaded_files` (`Sessions.php:201`). Each pulls `$request['session']` (and `filename`/`content_base64` where required) and forwards to `UploadService::*`. This is the single inbound seam from `agent.php`.

**Container-side HTTP (routes → OWNED controller — `src/routes.php`, not owned):**

- `/uploaded_files.php` → `UploadController::list` (`routes.php:71`)
- `/uploaded_file_view.php` → `UploadController::view` (`routes.php:72`)
- `/upload_file.php` → `UploadController::upload` (GET+POST, `routes.php:73-74`)
- `/delete_uploaded_file.php` → `UploadController::deleteOne` (GET+POST, `routes.php:75-76`)
- `/delete_all_uploaded_files.php` → `UploadController::deleteAll` (GET+POST, `routes.php:77-78`)

**Inbound callers in OTHER subsystems (session-view frontend):**

- `public/js/session.js:2431` — compose-bar `+` upload posts to `/upload_file.php` via `FormData` (session + csrf_token + file). On success adds `{path, filename, size}` to `pendingAttachments` (`session.js:2409-2420`).
- `public/js/session.js:2470` — removing a not-yet-sent attachment chip deletes the real file via `/delete_uploaded_file.php`.
- `public/js/sidebar.js:405` — sidebar poll/list fetches `/uploaded_files.php?session=...` (name/size/total rendering).
- `public/js/sidebar.js:390` — each uploaded-file row links to `/uploaded_file_view.php?session=...&filename=...` (new-tab view).
- `public/js/sidebar.js:522` — per-file delete posts `/delete_uploaded_file.php`.
- `public/js/sidebar.js:552` — "Delete all" posts `/delete_all_uploaded_files.php`.

**Downstream consumer of the `path` contract (session-core send path):**

- `public/js/session.js:2313-2317` — `sendComposedMessage()` builds `[Attached: <path>]` lines for the optimistic bubble and appends each `path` as `attachments[]` to the `/session_send.php` body. That path is the `.claude/uploads/<name>` value returned by `UploadService::save_uploaded_file()`.
- `host-agent/lib/Services/PromptInteractionService.php:654-666` — server-side build of the `[Attached: <path>]` lines that Claude actually receives.

**Shared guard helpers consumed by OWNED controller (`src/lib/Controllers/Controller.php`, not owned):**

- `require_post_json()` (`Controller.php:31`) — CSRF + same-origin + 405-for-GET; used by `upload()`/`deleteOne()`/`deleteAll()`.
- `start_readonly_json()` (`Controller.php:54`) — used by `list()`.
- `stream_binary_result()` (`Controller.php:82`) — used by `view()`; `content_disposition_safe_filename()` (`Controller.php:117`) downstream of it.

## Tests

**`tests/test_file_uploads.php`** (the only owner-scoped test file). Self-isolating: requires `tests/lib/assert.php` + `host-agent/lib/Sessions.php`, refuses to run if `Config::sidecar_dir()` resolves to the real `/run/user/1000/csm-sessions` (`test_file_uploads.php:20-23`, guarded by `tests/.env.testing` pointing `SIDECAR_DIR` at `/tmp/csm-test-sidecars`). Uses a throwaway `sys_get_temp_dir()/csm-test-uploads-<rand>` workdir + a `cc-test-uploads-<rand>` fixture sidecar (via `SidecarStore::write_sidecar`), cleaned up in a `finally`. Coverage is **happy + sad across the board**:

- `sanitize_upload_filename` (~7 asserts, happy+sad): plain name untouched; `../../etc/passwd`→`passwd`; `/etc/passwd`→`passwd`; `.hidden`→`hidden`; all-dots `...`→`upload`; empty → `upload`; embedded null byte stripped.
- `unique_upload_filename` (happy+sad): no-collision unchanged; collision → `photo-1.jpg`; multi-collision → `photo-2.jpg`; no-extension name.
- `save_uploaded_file` (happy+sad): success returns sanitized filename + `.claude/uploads/<name>` path + real size + decodes correctly on disk; collision suffixes rather than overwriting; `.gitignore` created with `"*\n"`; unknown session → `ok:false`; malformed base64 rejected; size-limit rejection (artificially lowered via `putenv('MAX_UPLOAD_BYTES=100')`).
- `list_uploaded_files` (happy+sad): exact real-file count (rejected big2.bin and `.gitignore` excluded); `total_size` sum; newest-first order; empty/uncreated dir → `ok:true, files:[], total_size:0`.
- `read_uploaded_file` (happy+sad): real content base64 round-trip; `text/*` MIME detect; filename reported; not-found fail; unknown-session fail.
- `delete_uploaded_file` (happy+sad): real delete; not-found fail; `../../../../etc/hosts` traversal refused (sanity: `/etc/hosts` still exists); `.gitignore` delete refused + survives.
- `delete_all_uploaded_files` (happy+sad): reports exact count; dir emptied (excluding `.gitignore`); `.gitignore` survives → no unprotected window; safe re-run no-op returns `deleted:0`.
- `dispatch_action()` wiring (all four actions) — save/list/read/delete/delete_all route through the switch correctly and reflect real on-disk state.

No other test file in `tests/` references `UploadService` by name; the compose-bar/upload UI is exercised indirectly by `tests/test_ui_smoke.php` (session-view smoke) but that is out of this boundary.

## Dependencies

**Upstream (this subsystem consumes):**

- `HostAgent\Stores\SidecarStore::read_sidecar()` (`host-agent/lib/Stores/SidecarStore.php:40`) → `session_workdir()`. Returns `?array`; `workdir` is only present for app-spawned sessions (`create_cc_session()` writes it).
- `HostAgent\Services\Config::csm_config()` (`host-agent/lib/Services/Config.php:24`) → `max_upload_bytes()`. Env-var override `MAX_UPLOAD_BYTES`; default 64MB.
- `App\AgentClient::agent_call()` (web side) — transport for every controller action. One-JSON-request/one-JSON-response over the UNIX socket to `agent.php`.
- Base class `App\Controllers\Controller` — `require_post_json`/`start_readonly_json`/`stream_binary_result`/`content_disposition_safe_filename`.

**Downstream (consumes this subsystem):**

- session-view frontend — the compose-bar `+` button and the sidebar "Uploaded files" list consume `/upload_file.php`, `/uploaded_files.php`, `/uploaded_file_view.php`, `/delete_uploaded_file.php`, `/delete_all_uploaded_files.php` (see Call sites). Methods: `list()`/`view()`/`upload()`/`deleteOne()`/`deleteAll()`.
- session-core send path — consumes the `.claude/uploads/<name>` `path` contract via `attachments[]`/`[Attached: path]` (`session.js:2313-2317`, `PromptInteractionService.php:654-666`).

**Reverse dependencies (who calls into the OWNED two files):**

- `host-agent/lib/Sessions.php::dispatch_action()` (entry to `UploadService`).
- `src/routes.php` (entry to `UploadController`).
- Everything else (sidecar file/`.claude/uploads` dir) is data, not code — no runtime callers beyond the above.

## Data & schema

**No DB tables.** This subsystem is entirely filesystem-backed; the convention is deliberately a directory pairing that Claude Code already understands (it can `Read()` a path relative to its own cwd).

**Directory contract:**

- One dir per session: `<session-project-workdir>/.claude/uploads/` (via `uploads_dir()`).
- The session's project workdir is resolved from the sidecar (`session_workdir()`), not derived from the session name — so a bare/manually-attached session (no sidecar) never maps to a dir.
- Uploads dir created with mode `0700` on first save (`mkdir($dir, 0700, true)`).
- A self-contained `.gitignore` whose content is exactly `"*\n"` lives at `.../.claude/uploads/.gitignore` — created by `ensure_uploads_gitignore()` on every save, surviving `delete_all`.

**Filename processing pipeline (each save):** client `$_FILES['name']` → `sanitize_upload_filename()` (basename + strip control chars + ltrim dots → safe basename, fallback `'upload'`) → `unique_upload_filename()` (append `-{i}` before extension until no collision) → written as `<dir>/<finalName>`. The reported `path` is `.claude/uploads/<finalName>`.

**File listing shape** (`list_uploaded_files` → `files[]`): `array<int,array{name:string,size:int,mtime:int}>` + `total_size:int` (sum of file sizes, `.gitignore`/`.gitignore` irrelevant, non-file entries and `.`/`..`/`.gitignore` excluded). Newest-first by `mtime`. When the dir doesn't exist: `files:[]`, `total_size:0`, `ok:true`.

**Read shape** (`read_uploaded_file`): `data` = base64 of the raw bytes, `media_type` = `mime_content_type($path) ?: 'application/octet-stream'`, `filename` = `basename($path)`.

**Response envelope** across all four actions: `{ok:bool, message?:string}` plus the action-specific fields above. Every action returns `{ok:false, message:'Unknown working directory for this session'}` when there's no known workdir, with no partial state written.

**Size constraints:** app-level cap `MAX_UPLOAD_BYTES` (default 64MB; `UploadService::max_upload_bytes()`), independent of PHP's own `upload_max_filesize`/`post_max_size` (raised in `docker-compose.yml`). Web side also checks `UPLOAD_ERR_INI_SIZE`/`UPLOAD_ERR_FORM_SIZE` and returns `File too large` before relaying.

## Conventions / quirks worth recording

- **Path-safety discipline:** two defensive layers. `basename()` for the filename (`sanitize_upload_filename`) stops `../` and absolute paths in the *name*; `resolve_upload_path()`'s `realpath` + prefix boundary check additionally blocks a *symlink planted inside the uploads dir* pointing elsewhere. Both `read_uploaded_file` and `delete_uploaded_file` go through `resolve_upload_path()`. `.gitignore` is special-cased in `delete_uploaded_file` to return the same not-found message as any other nonexistent name, so the internal bookkeeping isn't distinguishable or deletable.
- **Size limits:** triple-layer (php.ini → `UPLOAD_ERR_*` handler → `max_upload_bytes()`), with the `upload()` action bumping `memory_limit` to 512M locally because it holds raw + base64 + final JSON copies at once.
- **ES5, no bundler:** the frontend JS (`session.js`/`sidebar.js`) is plain ES5 (`var`, `function`, no `const`/arrow/`Set`/template literals) for mobile-Safari compatibility — see the CLAUDE.md note. It lives in session-view, but any extension of the upload UI must keep that style.
- **Never overwrite:** `unique_upload_filename` guarantees a second upload with the same name becomes `name-1.ext`, never a silent overwrite; `view()` passes `immutable:false` because of exactly this (a re-upload can land on the same de-duped filename with new content).
- **Self-healing `.gitignore`:** called on every save (cheap `is_file()` check in the common case), not just on dir-creation, precisely so `delete_all` — which intentionally leaves the `.gitignore` behind — doesn't leave a window where a save re-creates uploads untracked.
- **Workdir-only resolution:** `session_workdir()` reads *only* the sidecar's `workdir` field; no fallback / pane-scraping. A session without a sidecar is uniformly "unknown working directory" across all four operations — same limitation every other workdir-dependent feature here has.
- **Found-live comments:** several non-obvious behaviors carry "found live" reasoning inline — the memory-limit 512M bump (2026-08-22), `.gitignore` necessity verified against this very repo, the `.claude/uploads/` location being Andres's own suggestion so Claude Code can `Read()` it directly. Read these before assuming simplicity.

## Co-owned / cross-subsystem

Physically in **session-view** (`owned_paths` there), but the UI logic for this subsystem. Recorded here as co-reported — **note:** the `SessionController` upload methods (`uploaded_*`/`upload_file`) referenced in this subsystem's boundary no longer exist in the code; the upload controller methods are fully consolidated onto `UploadController` (owned). What remains co-owned with session-view is the render/JS layer:

- **`src/partials/sidebar.php`** — sidebar "Uploaded files" block: `#uploaded-files-total` (`sidebar.php:72`), `#uploaded-files-list` (`sidebar.php:74`), `#delete-all-uploads-btn` (`sidebar.php:77`).
- **`src/partials/compose-bar.php`** — compose-bar attach controls: `#compose-attach-btn` (`compose-bar.php:20`), `#compose-file-input` (`compose-bar.php:24`), `#compose-upload-status` (`compose-bar.php:44`).
- **`public/js/sidebar.js`** — uploaded-file row render `uploadedFileRowHtml()` (`sidebar.js:388`); list fetch/render `loadUploadedFiles()` (`sidebar.js:400`); per-file delete handler (`sidebar.js:512-542`); "Delete all" handler (`sidebar.js:544-573`); `formatFileSize()` (`sidebar.js:378`, mirrors PHP's `number_format($n,1)` exactly — a live-found drift bug fixed 2026-08-22).
- **`public/js/session.js`** — `pendingAttachments` bookkeeping (`session.js:2188`) with per-session `localStorage` persistence (`session.js:2228-2236`); `renderComposeAttachments()` chips (`session.js:2246-2265`); `sendComposedMessage()` emitting `[Attached: path]`/`attachments[]` (`session.js:2297-2364`, esp. 2313/2317); attach/upload block `uploadOneFile()` (`session.js:2425-2446`), sequential multi-file upload (`session.js:2486-2520`), and not-yet-sent-chip removal deleting the real file (`session.js:2453-2480`).
- **`src/partials/sidebar.php` / `src/partials/compose-bar.php`** markup is rendered by session-view's `PageView`/session page; these placeholder files are co-consumed, not owned here.

Shared globals the JS uses that live in session-view's `common.js` (out of scope here): `parseJsonResponse`, `escapeHtml`, `csrfToken`, `sessionName`.
