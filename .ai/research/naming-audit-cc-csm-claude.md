---
topic: naming-audit-cc-csm-claude
covers: full-repo grep sweep, not tied to specific file hashes (see note below) — re-grep before relying on this for execution rather than trusting indefinitely, since new files added after this date could introduce new instances the hash list below wouldn't catch.
updated: 2026-09-01
---

# Naming audit: `cc-`, `csm`, `claude` across claude-session-manager

Full-repo grep sweep (excluding vendor/, node_modules/, .git/) done ahead of a project
rename ("Claude Session Manager" → "Sessioneer", decided 2026-09-01). Read-only audit,
no files changed. Zero false positives / mid-word matches found for any of the three
patterns.

## `cc-` — fully legitimate, no rename needed

The tmux session-name prefix per agent is already fully implemented and correct:
`ClaudeCodeAdapter`='cc', `CodexAdapter`='cx', `OpenCodeAdapter`='oc',
`AntigravityAdapter`='ag' (`host-agent/lib/Agents/*Adapter.php`,
`AgentRegistry::ADAPTERS`). Session tracking is sidecar-existence-based, not
prefix-glob-based (`SessionService::list_all_sessions()` explicitly enumerates ALL
tmux sessions regardless of name — the `cc-*` comments there are historical/
explanatory, not restrictive logic). `cc` legitimately means "Claude Code" the agent,
parallel to `cx`/`oc`/`ag` — **do not rename this as part of the project rename.**

## `csm` — self-branding, rename candidate (~120 files, 239 tokens)

- Env vars: `CSM_SESSION_NAME`, `CSM_AGENT_SOCKET_HOST`, `CSM_AGENT_SOCKET`,
  `CSM_REPO_ROOT`, `CSM_BOOTSTRAP`, `CSM_ARCHIVED_BOOTSTRAP`, `CSM_TEST_HEADED`,
  `CSM_PLAYWRIGHT_LIVE`/`BASE_URL`, `CSM_STUB_STATE`, `CSM_CODEX_E*`
- **DB column `spawned_by_csm`** on the `sidecars` table (`host-agent/lib/Stores/SqliteDb.php`)
  — real schema, live on disk. Renaming needs an actual migration.
- JS globals: `window.CSM_BOOTSTRAP`, `window.__csmReplay`
- Systemd units on disk (repo templates under `host-agent/systemd/`, likely also
  installed under `~/.config/systemd/user/` on this machine):
  `csm-agent.socket`, `csm-agent@.service`, `csm-push-check.{service,timer}`,
  `csm-antigravity-quota-check.{service,timer}`, `csm-codex-bridge.service`
- Socket paths: `csm-agent.sock`, `csm-codex-bridge.sock`
- `host-agent/opencode-plugins/csm-permissions.js` (+ `CsmPermissionsPlugin` class)
- Docker: `container_name: csm-app`, compose `name: claude-session-manager`
- ~150 test-fixture temp-path names (`csm-test-*`) — cosmetic, low individual value.

## "Claude Session Manager" as literal project title — rename candidate

- `README.md:1`, `package.json` name field, `composer.json` (`loki495/claude-session-manager`),
  `docker-compose.yml:1` (`name: claude-session-manager`)
- Page titles: `src/partials/pages/index.php`, `session.php`, `archived-session.php`,
  `public/sw.js:90`
- **`AntigravityHookService::HOOK_GROUP = 'claude-session-manager'`**
  (`host-agent/lib/Services/AntigravityHookService.php:29`) — a string key written
  into **`~/.gemini/config/hooks.json` on the host, outside this repo**.
- `StatuslineMarkerService.php` — 6 marker-comment constants written into the user's
  real statusline script on disk.
- systemd unit `Description=` lines (5 files)
- ~15 hits of the literal repo path `/home/user/www/claude-session-manager` in
  `.env.testing`/test constants — will follow a directory rename automatically.

## Legacy function names (code-only, zero external migration cost)

`create_cc_session()`, `resume_cc_session()`, `kill_cc_session()` in
`SessionLifecycleService.php` already work generically per-agent internally — the
`cc_session` naming is a leftover from before the adapter system existed. Straightforward
rename (e.g. `create_agent_session()`).

## Needs a human decision (see plan QUESTIONS.md)

1. `claude_session_id` DB column + `claude_session_id_already_live()` method — used
   for every agent already, same leftover-naming issue as the functions above, BUT
   this one **is** a real schema migration if renamed (unlike the function names).
2. Top-line description in README.md/CLAUDE.md/GEMINI.md ("manages `cc-*` tmux
   sessions running Claude Code") is stale now that it also manages `cx-*`/`oc-*`/`ag-*`.

## External-facing identifiers (real migration cost, not just text edits)

- GitHub repo slug: `loki495/claude-session-manager` (confirmed live via a test
  fixture's captured `git remote -v` output)
- **Separate repo** `~/www/traefik`: `dynamic/ac495-sites.yml` (router `csm-ac495`,
  `Host(csm.example.com)`, service `csm@docker`) and `dynamic/csm-tls.yml` (self-signed
  cert for `csm.dev.local.test`, cert files on disk at
  `/etc/traefik/certs/csm.dev.local.test.{crt,key}`)
- Systemd units likely already installed under `~/.config/systemd/user/`, not just
  the repo's templates
- `~/.gemini/config/hooks.json` on the host (real file, `claude-session-manager` group key)
- The real statusline script on disk (`claude-session-manager` marker comments)
- `spawned_by_csm` SQLite column on the live `sessions.sqlite`
- The repo directory itself: `~/www/claude-session-manager`
- **Separate repo** `~/www/homie`: dashboard "Utils" group has a card titled
  "Claude session manager" linking to `https://csm.example.com`
- Docker container/image names, Docker Compose project name
