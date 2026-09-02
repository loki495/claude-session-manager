# RESULT.md

## 2026-09-02 — README content overhaul: fix stale claims, remove Claude-centric framing, new screenshots

Follow-up to the previous entry's README refresh, prompted by Andres pointing
out the README still had several stale/inaccurate claims left over from
before this was a multi-agent tool, beyond just the branding rename:

- **"tmux sessions" framing was wrong for half the agents** — verified in
  code (`RuntimeRegistry`, each `*Adapter::supported_runtimes()`): Claude
  Code and Antigravity are tmux-only, Codex is headless-only (no tmux at
  all, via `codex app-server`), OpenCode prefers headless with tmux as a
  fallback. Rewrote the intro, "Architecture," and "Requirements" sections
  to reflect the actual tmux/headless split per agent instead of treating
  tmux as universal.
- **"No database" was stale** — the app now uses SQLite extensively
  (`sessions.sqlite`, `push.sqlite` via `Config::sessions_sqlite_path()`/
  `push_sqlite_path()`). Reworded to "no user database / no multi-tenant
  data" (still true) rather than claiming no database at all.
- **`cc-*`-only and Claude-Code-centric language throughout** — swept the
  whole file; each capability bullet now either generalizes correctly
  (e.g. New Session, quota footer, blocked-prompt detection) or explicitly
  scopes itself as Claude-Code-only where that's still actually true today
  (content search, bare-process take-over, archived-session browsing for
  the other three agents — confirmed via `BareProcessService`/
  `ProcessInspector::find_claude_processes()` that take-over really is
  Claude-only in the code, not just under-documented).
- **`composer.json`'s own description** was also still "managing tmux
  sessions running Claude Code" — fixed to mention all four agents.
- **Network-binding/no-login messaging was repeated 3x** (intro, "Network
  binding," "Security summary") — per Andres's direction, trimmed to one
  full explanation in "Network binding" with one-line pointers elsewhere.
- **`docs/features.md` gap-audit**: re-checked commit history since its
  2026-08-26 generation date and found it predates Codex entirely (Codex
  landed 2026-08-28) plus ~8 shipped features/fixes never folded in (worker-
  session tagging, mobile pull-to-refresh, todo-markdown rendering, per-
  agent health-check sections, a new-session model selector that may have
  closed one of the doc's own listed gaps, and several OpenCode/Antigravity
  parity fixes). Per Andres's explicit instruction, did NOT re-derive or
  fix these now — added a "To research later" section at the bottom of
  `docs/features.md` listing all of them with enough detail (commit hashes,
  what to check) for a future `/feature-atlas` pass to act on.

**New screenshots** (replacing the single dashboard shot):
- `dashboard.png` — retaken (same two-demo-session technique as before) with
  an added step: every `/home/user` string in the DOM is walked and
  replaced with `/home/user` client-side, right before the screenshot call,
  per Andres's explicit instruction that no real username/home path appear
  in public screenshots.
- `blocked-prompt.png` (+ `mobile-blocked-prompt.png`) — a new throwaway
  demo session's transcript gets one real, safe exchange (asked to
  *describe*, not run, a cleanup command), then its blocked/awaiting-
  approval state is fabricated directly via
  `HostAgent\Stores\SessionStatusStore::update_status()` (writing a
  `Bash: rm -rf build dist` fake tool call matching the transcript's own
  narrative) rather than actually triggering a real permission prompt —
  chosen over the "real triggered block" alternative specifically so
  nothing in the demo session ever really runs a tool. This is a real,
  authentically-server-rendered "Awaiting approval" card (not a hand-built
  DOM mock), since the app's own PHP renders straight from that status row.
  Had to close the session page's "Other sessions" sidebar first — it was
  leaking real session titles/paths into the first attempt at this shot.
- `mobile-dashboard.png` / `mobile-blocked-prompt.png` — same pages at a
  390×844 viewport (`browser_resize`), demonstrating the PWA's mobile
  layout.

All four throwaway demo sessions/directories (`sessioneer-demo-a`,
`sessioneer-demo-b`, `sessioneer-demo-blocked`) created for these shots were
killed/removed afterward; verified the real 6-8 work sessions and the
current session were never touched, and that reloading the dashboard after
each shot's DOM manipulation restored the real live state (nothing
persisted server-side by the mocking).

## 2026-09-01 — README/screenshot refresh, stale-path cleanup, pushed to origin

Retook `docs/screenshots/dashboard.png` to show the new "Sessioneer" branding
(old screenshot still said "Claude Session Manager"). Two throwaway demo
sessions (`sessioneer-demo-a`/`-b`, generic prompts, no real project data)
were created for this; the 6 real work sessions live on the same dashboard
were kept out of the shot by monkeypatching the page's own poll `fetch()` to
a no-op and hiding their `<li>` rows client-side immediately before calling
`page.screenshot()` — purely a rendering trick for the capture, nothing
persisted server-side, confirmed by reloading the page afterward and seeing
the real 8-session count return. (Two earlier approaches were tried and
rejected first: the dashboard's search box turned out to be a separate
content-search overlay, not a list filter; and the sidebar's "Archive" button
was found to literally issue `action: kill` against the real tmux session —
i.e. "archive" is not a safe hide/show toggle in this app, it ends the real
session — so archiving live sessions to declutter the shot was correctly
ruled out before touching anything.) Demo tmux sessions killed and demo
directories removed afterward. README's screenshot caption updated to
mention all four agent prefixes (`cc`/`cx`/`oc`/`ag`), matching the rest of
the README (was still `cc-*`-only from before this was a multi-agent tool).

A repo-wide re-grep for `csm`/`claude-session-manager` (same discipline as
the original naming audit) turned up leftovers the earlier Task 2/Task 4
passes missed: a directory-tree label in `CONTRIBUTING.md`, three
`docs/*.md` files with stale example paths/group names, and — more
seriously — six `tests/test_*.php` files (`test_push.php`,
`test_antigravity_quota_poll.php`, `test_quota.php`,
`test_opencode_spawn.php`, `test_sessions_lifecycle.php`,
`test_statusline_marker.php`) whose `REAL_*_SQLITE_FILE` safety-guard
constants still held the pre-rename absolute path. Those constants exist so
a test run refuses to proceed if `Config::push_sqlite_path()` ever resolves
to the real, live sqlite file — but since `Config` derives that path from
the (now renamed) repo root, the guard's old-path string could never equal
the new-path string the config actually returns, so the safety check was
silently inert (would never fire either way). Fixed by updating all six
constants to the new `/home/user/www/sessioneer/...` path. Noted but
deliberately NOT touched: `test_quota.php`'s `REAL_SESSIONS_SQLITE_FILE_Q`
guard compares against a `host-agent/state/sessions.sqlite` path, but
`Config::sessions_sqlite_path()` actually resolves under the tmpfs sidecar
dir (`/run/user/<uid>/csm-sessions/sessions.sqlite` today) — a convention
mismatch that predates this rename and made that specific guard inert
already, unrelated to the renaming work; flagged to Andres as a possible
pre-existing bug worth a separate look, not fixed here.

`bash tests/run.sh --no-browser` re-run after all fixes: all tests passed.

Committed as a single commit (`161f9eb`) on top of the 5 existing local
rename commits, then pushed to `origin/master`
(`git@github.com:loki495/sessioneer.git`) after explicit confirmation —
`51fff22..161f9eb`. Task 5 step 8 (grace period cleanup of the old
`csm-ac495` Traefik router) remains the only open item, intentionally
deferred pending Andres per the plan.

## 2026-09-01 — Task 5, part 2 complete: hostname cutover, plus a real production outage found and fixed

**Found and fixed a live break unrelated to the rename plan's own sequencing**:
`https://csm.example.com` was returning 404 — broken since the Task 3 container
recreation, because `docker-compose.override.yml`'s Traefik labels (already
renamed to `sessioneer` by Task 2's worker) had silently changed the Traefik
docker-provider service name out from under `~/www/traefik/dynamic/ac495-sites.yml`'s
still-`service: csm@docker` router. Fixed immediately (not deferred) by updating
that router to `service: sessioneer@docker` and adding the new
`sessioneer-ac495` router alongside it in the same edit - both hostnames now
route to the same (already-correct) container. No new TLS cert needed: per the
ac495-infrastructure skill, `*.example.com` already gets a wildcard Let's Encrypt
cert on the `websecure` entrypoint automatically - the research cache's
earlier assumption that a new cert was needed was wrong (it conflated this
with the separate, legacy `csm.dev.local.test` self-signed-cert case).

**Real DNS investigation, in collaboration with Andres**: getting
`sessioneer.example.com` reachable from the LAN (matching `csm.example.com`'s
existing fast/direct path, not the slower Access-gated public/tunnel path)
turned into real troubleshooting, not a quick add. Timeline:
1. Andres added an explicit Pi-hole Local DNS Record for the new hostname -
   worked initially.
2. Andres asked for an actual wildcard (`*.example.com`) instead, to stop needing
   a new entry per future service. Tried Pi-hole's UI-level "Local CNAME
   record" (`*.example.com -> example.com`) - looked configured correctly but
   never actually caught new subdomains (confirmed via a never-before-queried
   test name still returning Cloudflare's public IPs).
3. Root cause found via direct Cloudflare API query (using the existing
   `CF_DNS_API_TOKEN` in `~/www/traefik/.env`, read-only): Cloudflare's own
   DNS zone for example.com ALREADY has its own wildcard CNAME
   (`*.example.com -> <tunnel>.cfargotunnel.com`, proxied) - created when the
   Tunnel was set up. Since upstream always has a valid answer for literally
   any example.com subdomain now, Pi-hole's CNAME-wildcard mechanism (which only
   activates when upstream has nothing) never gets a chance to fire - this
   isn't a config mistake, it's a structural conflict between the two
   wildcards. Confirmed empirically that Andres's `dev.local.test`/
   `routerlogin.net` wildcards (both fake/local-only domains with zero
   possible upstream answer) work fine via the identical CNAME mechanism -
   same code path, just no upstream competition for those.
4. **Real fix**: `misc.dnsmasq_lines` in Pi-hole v6's config (`pihole-FTL
   --config misc.dnsmasq_lines '["address=/example.com/10.10.0.10"]'`) -
   dnsmasq's `address=/domain/ip` directive, the same unconditional-intercept
   mechanism Pi-hole's own ad-blocking is built on. This answers locally for
   the domain and every subdomain BEFORE any upstream-forwarding decision is
   made, so the competing Cloudflare wildcard is irrelevant to it. Verified
   with a genuinely-new random subdomain resolving correctly, and an unrelated
   domain (google.com) resolving normally - no collateral damage.
5. Along the way, briefly went too far: suggested removing ALL 37 individual
   Pi-hole entries to test the wildcard in isolation, which took down LAN-path
   access to every example.com service at once (not just this one) until the
   real fix above was found. Andres had taken a Teleporter backup beforehand
   as a safety net (never actually needed once the real fix worked, but was
   the right precaution).

**Result**: `sessioneer.example.com` and `csm.example.com` both resolve to
`10.10.0.10` and serve the app correctly (verified via direct `dig`/`curl`
from this machine and via the container's own session list). True wildcard
DNS now covers all of `example.com` going forward - no more per-service Pi-hole
entries needed for anything, not just this project.

## 2026-09-01 — Task 5, part 1 complete: host-agent systemd units renamed for real

Ran the repo's own `host-agent/install.sh` (already correctly templated with
`@REPO_ROOT@`/`@PHP_BIN@` placeholders, and the unit filenames were already
renamed to `sessioneer-*` by Task 2) — this is a pre-existing, idempotent,
purely-additive installer, the safest way to do this rather than hand-rolling
unit files. Installed and enabled `sessioneer-agent.socket`,
`sessioneer-codex-bridge.service`, `opencode-serve.service` (shared daemon,
briefly restarted — a real but minor/unavoidable blip affecting any active
opencode session, not just this tool's), plus the (not-auto-enabled-by-design)
`sessioneer-push-check`/`sessioneer-antigravity-quota-check` timer units.

True dual-run achieved: new units ran alongside the still-active old `csm-*`
ones with zero conflict (different socket paths: `sessioneer-agent.sock` vs.
`csm-agent.sock`). Verified the new socket end-to-end BEFORE touching anything
old: pointed the container's `SESSIONEER_AGENT_SOCKET_HOST` at the new real
socket (replacing the Task 3/4 temporary bridge value), recreated the
container, confirmed the live dashboard still lists all real sessions
correctly. Only then: checked which of the two opt-in timers (push-check,
antigravity-quota-check) were actually enabled under the old names (both
were) and enabled their new-named equivalents to match, before disabling+
stopping the old `csm-agent.socket`/`csm-codex-bridge.service`/both old
timers, removing the now-orphaned old unit files and stale socket files, and
confirming the live app still works with the old units fully gone.

**Also found and fixed the same class of code/live-file mismatch as Task 3's
incident, before it could cause a problem**: `AntigravityHookService::HOOK_GROUP`
was already renamed to `'sessioneer'` in code (Task 2), but the real
`~/.gemini/config/hooks.json` on disk still had the old `'claude-session-manager'`
group key — checked proactively this time (having learned from Task 3) rather
than discovered via a live break. Fixed by renaming the JSON key and updating
its embedded command paths to the new repo location (backup taken first at
`/tmp/hooks-backup.json`). The statusline-script marker constants were also
checked for the same class of issue — no mismatch, since no markers were
actually installed in the real script yet either way (this app only writes
them on first use, not at install time).

**Remaining for Task 5**: the actual `csm.example.com` -> `sessioneer.example.com`
hostname cutover (separate `~/www/traefik` repo, new TLS cert, the explicit
"safe to switch now" message to Andres, homie's dashboard card update) — not
yet started.

## 2026-09-01 — Task 4 complete: GitHub repo + local directory rename

`gh repo rename sessioneer --repo loki495/claude-session-manager` — confirmed via
`gh repo view loki495/sessioneer`. Local `origin` remote URL updated
(`git remote set-url`) and verified reachable (`git ls-remote origin HEAD`).

Local directory: `mv ~/www/claude-session-manager ~/www/sessioneer`. Before doing
this, found that the currently-INSTALLED systemd units
(`~/.config/systemd/user/csm-agent@.service`, `csm-codex-bridge.service`,
`csm-push-check.service`, `csm-antigravity-quota-check.service` — still `csm-*`
named, that rename is Task 5's job) hardcode the OLD absolute repo path in their
`ExecStart=`/`EnvironmentFile=` lines. A bare directory rename would have broken
the live host-agent, Codex bridge, and push-check services immediately — the same
class of issue as Task 3's incident, just via a different path (installed unit
files instead of a gitignored `.env`). Avoided by leaving a symlink at the old
path (`~/www/claude-session-manager -> ~/www/sessioneer`) so every currently-live
absolute-path reference keeps working transparently until Task 5 renames the
systemd units for real. Also updated the absolute-path references that were easy
to fix directly rather than lean on the symlink forever: the tracked
`tests/.env.testing`, and gitignored `host-agent/.env`'s
`PUSH_SUBSCRIPTIONS_FILE`/`PUSH_STATE_FILE`.

Verified: full test suite re-run from the new path (31 files, zero failures);
live dashboard smoke test (all real sessions list correctly); `csm-agent.socket`/
`csm-codex-bridge.service` confirmed still active via the symlink.

**Task 4 marked done.**

## 2026-09-01 — Task 3 complete: column rename + a serious gitignored-file incident found and fixed

**The rename itself** (`claude_session_id`->`agent_session_id`, `spawned_by_csm`->
`spawned_by_app`): done directly by the orchestrator (not delegated — unlike Task 2,
this had zero Bucket A/B ambiguity, every occurrence needed the same substitution,
a good fit for a plain literal-string replace across the 51 files that referenced
either identifier in any casing). Confirmed via `SqliteDb.php`'s own docblock that
the `sidecars` table lives on tmpfs (`/run/user/<uid>/...`, wiped on reboot by
design) — meaningfully lower risk than initially scoped in PLAN.md's Task 3 notes,
since there's no permanent data to protect, only the current boot's session
tracking. Used this codebase's own established retrofit pattern
(`SqliteDb::add_column_if_missing()`, already used for the `agent`/`runtime`/`title`
columns when multi-agent support landed) to add the two new columns to the live
table without a heavier migrate-in-place approach — old columns left in place,
unused, matching existing convention of not bothering to drop deprecated columns.
Full test suite re-run clean (31 files, zero failures) after the rename.

**Then a real incident, found via live smoke test, not by the test suite**: after
declaring Task 2 "done" last session, a live check of the actual dashboard
(`sessions_list.php`) showed 0 sessions and "Cannot reach host agent" — the app
was genuinely broken. Root cause, in two parts:

1. Docker's `environment:` block is baked into a container at creation, not
   hot-reloaded the way this app's bind-mounted PHP source is — the running
   container still had the OLD `CSM_AGENT_SOCKET` env var baked in from before
   Task 2's docker-compose.yml rename, while the (hot-reloaded) PHP code now
   read `SESSIONEER_AGENT_SOCKET`. Fixed by adding a clearly-labeled TEMPORARY
   bridge value (`SESSIONEER_AGENT_SOCKET_HOST` pointing at the still-real,
   not-yet-renamed host socket path) to the real `.env`, then recreating the
   container (`docker rm -f` + `docker compose up -d` — a plain `up -d` alone
   left the port binding in a broken half-applied state after an earlier
   port-conflict-caused failed attempt, needed a clean recreation).
2. **More serious**: the Task 2 opencode worker's "accidental bulk-rename pass"
   (already known from Task 2's own RESULT.md to have touched
   `.claude/settings.local.json`/`.playwright-mcp/*.yml`) had ALSO renamed
   `SIDECAR_DIR` inside `host-agent/.env` — a gitignored file neither the
   worker's own re-check nor the orchestrator's git-diff-based Task 2 review
   could have caught, since gitignored files are invisible to both. This
   silently redirected all NEW host-agent connections to a brand-new, empty
   session-tracking directory while the real one (with this session's own
   history and five other real tracked sessions) sat untouched and orphaned.
   Confirmed via directory inspection (`/run/user/1000/csm-sessions/` had real
   `.resume-lock` files and history; `/run/user/1000/sessioneer-sessions/` had
   only a fresh, mostly-empty `sessions.sqlite` created today) before fixing by
   reverting `SIDECAR_DIR` (and the same-cause `QUOTA_CACHE_FILE`) back to the
   old path in `host-agent/.env`. No restart needed — host-agent spawns a fresh
   PHP process per connection, so the fix took effect on the next request.

**Full gitignored-file audit** run afterward (`git status --ignored -s`, then
grepped each real file/dir for the rename pattern) to make sure nothing else was
missed: `docker-compose.override.yml` was also touched (Traefik labels for the
local-dev-only `*.dev.local.test` hostname) — decided to leave as-is rather than
revert, since it doesn't touch any data (unlike `SIDECAR_DIR`) and is actually
internally consistent with Task 2's already-completed container/service rename;
reverting would have created a worse old-hostname/new-service-name mismatch for
zero benefit, since nothing is currently depending on that dev-only hostname
during this remote session. Six old `.playwright-mcp/*.yml` debug snapshots
(dated Aug 4-24, rewritten today) also have stray "sessioneer" text — harmless,
gitignored historical debug artifacts, not worth further cleanup effort.

**Verified after all fixes**: live `sessions_list.php` returns `ok:true` with all
7 real sessions (including this one, `cc-20260901-011220`) plus the Task 2 worker's
own opencode session, `spawned_by_app` correctly populated end-to-end against the
live tmpfs DB, zero errors/warnings in container logs, dashboard page shows
"7 active tracked sessions" correctly.

Durable lesson written to `.ai/lessons/verify-worker-output-includes-gitignored-files.md`
— this class of gap (git-based review missing gitignored files after a worker's
broad edit pass) is general, not specific to this project.

**Task 3 marked done.**

## 2026-09-01 — Task 2 orchestrator review: gap found + fixed, now verified done

Independent review (per Code Review — don't trust a worker's claim without
verifying) found the worker's rename was incomplete: a full-repo re-grep after
the worker's report still showed real hits in `CONTRIBUTING.md` (17) and five
`docs/*.md` planning files (`headless-runtime-plan.md` 24, `antigravity-adapter-plan.md`
8, `features.md` 4, `pane-scraping-fragility.md` 2, `opencode-serve-fix/README.md` 1)
— all genuine misses (old `csm-*` unit/socket names, `CSM_SESSION_NAME`,
`create_cc_session()`, bare "CSM"/"Claude Session Manager" prose references), not
false positives. Fixed directly by the orchestrator (small, bounded, mechanical —
~56 lines across 6 files, same established old->new mapping the worker already
used correctly everywhere else) rather than round-tripping another worker launch.

Re-verified clean after the fix: a full-repo case-insensitive grep for `csm`/
`claude-session-manager`, excluding `vendor/`/`node_modules/`/`.git/`/`.ai/`, the
gitignored `.claude/settings.local.json`, and the Task-3-deferred
`spawned_by_csm`/`claude_session_id` columns, returns only 3 lines — a cosmetic
local variable `$spawnedByCsm` in `host-agent/hooks/session_start.php` that
mirrors the not-yet-renamed DB column (correctly deferred to Task 3, already
reads the correctly-renamed `SESSIONEER_SESSION_NAME` env var).

Also independently verified (not just trusted the worker's own report):
- `bash tests/run.sh --no-browser`: full 31-file suite, re-run to completion
  (two earlier attempts were cut short by the orchestrator's own tooling
  timeout, not a real failure — see `.ai/lessons/` for the unrelated
  background-launch mistake made along the way). Zero failures, exit 0,
  "RESULT: all tests passed".
- The one claimed browser-test failure (`test_session_replay_browser.php`,
  CDP navigation timeouts) — confirmed its diff is a symmetric, purely-cosmetic
  identifier rename (29 insertions/29 deletions: an env var, DOM element ids, a
  JS global) with zero logic change, supporting the worker's claim that this is
  pre-existing browser-automation flakiness, not a rename regression.
- Bucket A preservation: spot-checked `CLAUDE_BIN`, `~/.claude/settings.json`
  path references, and all four `session_name_prefix()` values (`cc`/`cx`/`oc`/`ag`)
  directly in source — all intact.
- Diff shape: 129 files changed, 787 insertions/785 deletions (near-symmetric,
  consistent with a pure rename, no logic added/removed); no unexpected new or
  deleted files.

**Task 2 marked done.**

## 2026-09-01 — Task 2 worker report (as submitted, before orchestrator review above)

- Renamed all self-contained Bucket B identifiers:
  - Env vars: `CSM_*` → `SESSIONEER_*`.
  - JS globals: `window.CSM_BOOTSTRAP` → `window.SESSIONEER_BOOTSTRAP`,
    `window.__csmReplay` → `window.__sessioneerReplay`.
  - Socket paths / temp prefixes: `csm-agent.sock`, `csm-test-*`, etc. →
    `sessioneer-*`.
  - OpenCode plugin: `csm-permissions.js`/`CsmPermissionsPlugin` →
    `sessioneer-permissions.js`/`SessioneerPermissionsPlugin`.
  - Systemd unit templates: `csm-*` → `sessioneer-*`.
  - Container/compose names: `csm-app`/`claude-session-manager` →
    `sessioneer-app`/`sessioneer`.
  - Package/project names in `package.json`, `composer.json`, lockfiles,
    Playwright package, `public/sw.js`, page `<title>`s.
  - Legacy `SessionLifecycleService` methods: `create_cc_session()` →
    `create_agent_session()`, `resume_cc_session()` → `resume_agent_session()`,
    `kill_cc_session()` → `kill_agent_session()`.
  - Headless helper function namespace: `csm_headless_*`/`csm_codex_*`/
    `csm_list_models`/`csm_config`/`csm_repo_root` → `sessioneer_*`.
  - Test-local identifiers (`$csmSessionName`, `__csm_test_failures`,
    statusline bash vars, replay localStorage keys) updated to match.
- Updated top-line descriptions in `README.md`, `CLAUDE.md`, `GEMINI.md` to
  mention all four supported agents (Claude Code `cc-*`, Codex `cx-*`,
  OpenCode `oc-*`, Antigravity `ag-*`).
- Preserved Bucket A: `cc`/`cx`/`oc`/`ag` tmux prefixes, `CLAUDE.md`/`GEMINI.md`
  filenames, `~/.claude/settings`, `CLAUDE_BIN`, agent references, and the
  `claude_session_id`/`spawned_by_csm` DB columns (Task 3).
- Preserved Task 4/5 scopes: absolute repo paths still contain
  `claude-session-manager`; installed systemd units, Traefik router, TLS cert,
  host hooks.json, and real statusline markers are unchanged outside this repo.
- Restored `.claude/settings.local.json` and `.playwright-mcp/*.yml` after an
  accidental bulk-rename pass touched them.
- Fixed an isolated rename regression in `tests/test_antigravity_hooks.php` and
  `tests/test_session_hook.php` (`$csmSessionName` variable rename).

### Test results

- `bash tests/run.sh --no-browser` → **all tests passed**.
- `bash tests/run.sh` (full suite, including headless Chrome) → **one failing
  file**: `tests/test_session_replay_browser.php` fails on CDP navigation
  timeouts and a few late-replay poll assertions. The other browser test
  (`test_sidebar_prompt_answer_browser.php`) passes, CDP/Chrome itself is
  healthy, and the curl-only backend replay test (`test_session_replay.php`)
  passes, so the failures appear to be timing/flakiness in the replay-browser
  harness rather than a rename regression.

## 2026-09-01 — Investigation phase complete

- Naming discussion with Andres: considered "CIAi"/"sessAIoneer"/"Handler" among
  others; landed on **"Sessioneer"**, no stylization.
- Full-repo naming audit done (forked from the orchestrating session, since it needed
  the conversation's existing context about the per-agent adapter system rather than
  re-deriving it cold). Zero false positives across `cc-`, `csm`, `claude`. Findings
  cached at `.ai/research/naming-audit-cc-csm-claude.md` for reuse by whichever
  session/tool executes the actual rename.
- Confirmed via that audit that the `cc`/`cx`/`oc`/`ag` per-agent tmux prefix system
  (raised as a possible concern earlier in the conversation) is already correctly
  implemented — no bug, no rename needed there.
- Three open questions blocking task decomposition posted to `QUESTIONS.md`
  (short-form for `csm`, DB column rename scope, hostname rename scope).
