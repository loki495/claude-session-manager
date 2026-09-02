# PLAN.md — Rename "Claude Session Manager" → "Sessioneer"

## Objective

Rename the project from "Claude Session Manager" (repo `claude-session-manager`,
internal short form `csm`) to **"Sessioneer"**, driven by the tool now managing
sessions for multiple CLI coding agents (Claude Code, Codex, OpenCode, Antigravity),
not just Claude. Decided with Andres 2026-09-01 — see conversation log for the naming
discussion (CIAi/sessioneer/Handler considered; Sessioneer chosen, no stylization).

Full naming/branding audit is cached at
`.ai/research/naming-audit-cc-csm-claude.md` — read that before touching any task
below rather than re-deriving it.

**Confirmed, does not need to change:** the `cc`/`cx`/`oc`/`ag` per-agent tmux
session-name prefix system (`host-agent/lib/Agents/*Adapter.php`) — `cc` legitimately
means "Claude Code" the agent, not this project's old name.

**All three open questions resolved (see QUESTIONS.md):**
- Q1 — new short form is the full word `SESSIONEER_` (env vars etc.), not a new
  abbreviation.
- Q2 — rename both `spawned_by_csm` and `claude_session_id` DB columns now (real
  migration).
- Q3 — rename the hostname too: `csm.example.com` → `sessioneer.example.com` (touches
  `~/www/traefik`, needs a new TLS cert, updates homie's dashboard card).

---

## Task 1 — Decide remaining open questions

**Status:** done — all three answered 2026-09-01, see `QUESTIONS.md`.

---

## Task 2 — Code-only renames (no external migration cost)

**Objective:** Rename everything self-contained to this repo with no live
dependency outside it.

**Relevant files:** ~120 files per the research cache's `csm` bucket — see that file
for the full breakdown rather than re-grepping from scratch.

**Includes:**
- `csm` → `SESSIONEER_`-style full-word naming across env vars (`CSM_SESSION_NAME` →
  `SESSIONEER_SESSION_NAME`, etc.), the JS globals (`window.CSM_BOOTSTRAP` →
  `window.SESSIONEER_BOOTSTRAP`, `window.__csmReplay` → `window.__sessioneerReplay`),
  test-fixture temp-path names (`csm-test-*`), the `csm-permissions.js` OpenCode
  plugin file (rename file + `CsmPermissionsPlugin` class), socket path constants
  (`csm-agent.sock`, `csm-codex-bridge.sock`).
- "Claude Session Manager" → "Sessioneer" in: `README.md` title, `package.json`,
  `composer.json` (`loki495/claude-session-manager` → `loki495/sessioneer`),
  `docker-compose.yml` compose name + `container_name: csm-app` →
  `container_name: sessioneer-app`, page `<title>`s (`src/partials/pages/*.php`),
  `public/sw.js`.
- Legacy function names: `create_cc_session()` → `create_agent_session()` (and
  `resume_cc_session()`, `kill_cc_session()` similarly) in `SessionLifecycleService.php`.
- Fix the stale top-line description in `README.md`/`CLAUDE.md`/`GEMINI.md` ("manages
  `cc-*` tmux sessions running Claude Code" → mentions all four agents/prefixes:
  cc/cx/oc/ag for Claude Code/Codex/OpenCode/Antigravity).

**Status:** done — worker completed the bulk (129 files), orchestrator review found
and fixed a gap (CONTRIBUTING.md + 5 docs/*.md planning files, ~56 lines, missed by
the worker), then re-verified clean. See RESULT.md for full verification detail.

**Acceptance criteria:** `bash tests/run.sh` passes; grep for old `csm`/
`claude-session-manager` strings in code (excluding legitimate Claude Code
references — cross-check against the research cache's Bucket A) returns nothing
unexpected; `docker compose up -d --build` still starts cleanly under the new
container/compose names. First two independently verified; container rebuild
deferred to Task 5's dual-run step (no reason to rebuild twice).

---

## Task 3 — DB migrations: rename `spawned_by_csm` and `claude_session_id`

**Status:** done — see RESULT.md for full detail, including a serious
gitignored-`.env` incident found via live smoke test and fixed.

**What actually happened (revised from the original plan below, which assumed
higher-stakes persistent data):** confirmed `sidecars` lives on tmpfs (wiped on
reboot by design), so a heavy copy-rehearse-then-migrate-live approach wasn't
warranted. Used this codebase's existing `add_column_if_missing()` retrofit
pattern instead (same mechanism already used for the `agent`/`runtime`/`title`
columns) to add `agent_session_id`/`spawned_by_app` to the live table
non-destructively, alongside a full literal-string rename of both identifiers
(51 files, snake_case + camelCase) across `host-agent/`, `src/`, `public/js/`,
`tests/`. Old columns left in place, unused — matches existing convention.

**Acceptance criteria:** met — `bash tests/run.sh --no-browser` passes (31 files,
zero failures, independently verified); live smoke test against the real running
app confirms all 7 real sessions list correctly with `spawned_by_app` populated
end-to-end.

~~Original plan (superseded once the tmpfs/ephemeral nature of this table was
confirmed):~~ ~~write and test the migration script against a copy of the live DB
first... only run it against the real file during Task 5's cutover~~ — not needed;
`add_column_if_missing` is additive/non-destructive and safe to apply directly,
same as this codebase's own established pattern for exactly this situation.

---

## Task 4 — Repo + directory rename

**Status:** done. GitHub repo renamed (`loki495/claude-session-manager` ->
`loki495/sessioneer`, confirmed reachable), local remote URL updated and verified
(`git ls-remote`), local directory renamed (`~/www/claude-session-manager` ->
`~/www/sessioneer`).

**Real risk found and handled**: the currently-INSTALLED systemd units
(`~/.config/systemd/user/csm-agent@.service` etc., still `csm-*`-named — that's
Task 5's job) hardcode the OLD absolute path in `ExecStart=`/`EnvironmentFile=`.
Renaming the directory outright would have broken the live host-agent, Codex
bridge, and push-check services immediately (same class of issue as Task 3's
incident). Fixed by leaving a symlink at the old path
(`~/www/claude-session-manager -> ~/www/sessioneer`) pointing to the new location
— every currently-live absolute-path reference keeps working transparently until
Task 5 renames the systemd units for real (name + path together), at which point
the symlink can be removed.

Also updated absolute-path references to the new location directly (redundant
with the symlink but cleaner): the tracked `tests/.env.testing`, and the
gitignored `host-agent/.env`'s `PUSH_SUBSCRIPTIONS_FILE`/`PUSH_STATE_FILE`.

**Acceptance criteria:** met — full test suite re-run from the new path (31 files,
zero failures); live dashboard smoke test confirms all real sessions still list
correctly; systemd services (`csm-agent.socket`, `csm-codex-bridge.service`)
confirmed still active via the symlink bridge.

---

## Task 5 — External infra cutover (separate repos/host files)

**Objective:** Update everything outside this repo that references the old
name/host, minimizing downtime by running old and new side by side wherever
possible rather than a hard stop/replace. Andres has asked to be told explicitly
when it's safe to switch from `csm.example.com` to `sessioneer.example.com` — this
task's whole structure is built around producing that exact signal, not just
getting the rename done.

**Sub-steps, in order:**

1. ✅ **Host agent, dual-run.** Done — ran the repo's own `install.sh` (already
   correctly templated), new `sessioneer-*` units ran alongside old `csm-*` ones
   with zero conflict, then old units disabled+removed once the new ones were
   verified end-to-end. See RESULT.md "Task 5, part 1" for full detail.
2. ✅ **Container.** Done as part of step 1's verification — container already
   points at the new real socket (no separate alternate-port dual-run needed;
   the DB migration from Task 3 was already live, not a pending copy to apply).
3. ✅ **TLS cert — turned out unnecessary.** Corrected assumption from the
   original research cache: `*.example.com` already gets a wildcard Let's
   Encrypt cert automatically on the `websecure` entrypoint (confirmed via the
   ac495-infrastructure skill) — the self-signed-cert case only applies to the
   separate, legacy `csm.dev.local.test` local-dev hostname, not production.
4. ✅ **Traefik router — done, plus a real live bug found and fixed.** Found
   `https://csm.example.com` was ALREADY returning 404 (broken since Task 3's
   container recreation — `docker-compose.override.yml`'s labels had already
   renamed the Traefik docker-provider service to `sessioneer`, orphaning the
   router's `service: csm@docker` reference). Fixed the existing router to
   `service: sessioneer@docker` and added the new `sessioneer-ac495` router in
   the same edit — both hostnames now route to the same container.
5. ✅ **DNS.** Not originally scoped as its own step, but became the bulk of
   Task 5's real work — see RESULT.md "Task 5, part 2" for the full
   investigation (Cloudflare's own tunnel wildcard vs. Pi-hole's local
   wildcard, and the real fix via `misc.dnsmasq_lines`). End state: genuine
   `*.example.com` wildcard now resolves correctly for any subdomain, current or
   future — a real improvement beyond just this rename.
6. ✅ **Told Andres explicitly** — see chat: `sessioneer.example.com` is live and
   verified against real data; `csm.example.com` still works during the grace
   period. Web Push re-subscription requirement called out.
7. ✅ **homie's dashboard card** → updated to name "Sessioneer" / URL
   `https://sessioneer.example.com`, verified live on the dashboard.
8. **Grace period** — pending. Length TBD with Andres. Remaining cleanup once
   he confirms: remove the OLD `csm-ac495` Traefik router from
   `ac495-sites.yml`, remove the (unrelated, never actually needed)
   `csm.dev.local.test` TLS cert setup if no longer used, and confirm no
   client/bookmark still depends on `csm.example.com` specifically before
   dropping it. Old systemd units are already gone (done in step 1, no grace
   period needed there since nothing external depended on unit names).
9. ✅ **`~/.gemini/config/hooks.json`** — done. Found proactively (checked for
   this class of issue given Task 3's incident, rather than waiting to discover
   it live): code already expected `HOOK_GROUP='sessioneer'`, real file still had
   the old key. Renamed the JSON key + updated embedded command paths, backup
   taken first.
10. ✅ **The real statusline script** — checked, no action needed. No markers
    were actually installed in the real script yet (this app only writes them on
    first use), so there was no old/new mismatch to fix.

**Status:** done except step 8 (grace period, intentionally deferred pending
Andres). README/screenshot refresh + final stale-path cleanup done and
pushed — see RESULT.md's last entry.

**Dependencies:** Task 2 (code renames) and Task 3 (DB migration, rehearsed against
a copy) land first.

**Acceptance criteria:** at every point before step 6's explicit go-ahead,
`csm.example.com` continues working uninterrupted (dual-run, not a gap); after step 8,
no `docker ps`/`systemctl --user list-units` output still shows old container/unit
names; a fresh Claude Code launch shows the renamed statusline marker; Antigravity
hook registration still reports installed via the dashboard's health check; Web
Push re-subscribed and confirmed delivering on the new origin before the grace
period ends.

---

## Task 6 — Manual/human follow-up (not executed by Claude)

**Objective:** Track, don't execute — Andres's own accounts.

- Update LinkedIn project reference.
- Update resume text.

**Status:** pending — tracked here so it isn't forgotten, not something a worker
touches.
