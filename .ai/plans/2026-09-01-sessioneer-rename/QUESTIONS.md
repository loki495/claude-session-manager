# QUESTIONS.md

## Q1 (orchestrator → Andres, 2026-09-01): what replaces the `csm` short form?

**What I found:** `csm` appears in ~120 files as env var prefixes (`CSM_SESSION_NAME`,
`CSM_AGENT_SOCKET_HOST`, ...), a JS global, systemd unit/socket names, and a
DB column. It needs *some* short form for these — spelling out `SESSIONEER_FOO` is
unambiguous but verbose everywhere it appears.

**Options:**
- (A) `SESSIONEER_` full-word prefix everywhere — verbose but zero ambiguity, no new
  abbreviation to remember.
- (B) A new short code (e.g. `sn`) reused the same way `csm` was.
- (C) Something else Andres has in mind.

**Answer (Andres, 2026-09-01):** (A) — `SESSIONEER_` full-word prefix everywhere.

---

## Q2 (orchestrator → Andres, 2026-09-01): rename the `spawned_by_csm`/`claude_session_id` DB columns?

**What I found:** Both are real SQLite columns on the live `sidecars`/`sessions`
tables. `claude_session_id` is already used generically for every agent (not just
Claude), so its name is leftover, same class of issue as `create_cc_session()` — but
unlike that function name, renaming this one is a real migration, not a free text
edit.

**Options:**
- (A) Rename both now as part of this pass (real migration + update every read/write
  site).
- (B) Leave both as internal-only names — they're not user-facing, so the cost of a
  migration may not be worth it right now.
- (C) Rename `spawned_by_csm` only (directly tied to the old brand name) but leave
  `claude_session_id` (arguably still meaningful shorthand even post-rename, if
  Claude Code stays the default/first-class agent).

**Answer (Andres, 2026-09-01):** (A) — rename both now, full migration.

---

## Q3 (orchestrator → Andres, 2026-09-01): does the `csm.example.com` hostname change too?

**What I found:** `csm.example.com` is real, routed via `~/www/traefik`
(`dynamic/ac495-sites.yml` + a self-signed cert in `dynamic/csm-tls.yml`), and
linked from homie's own dashboard (a card titled "Claude session manager"). Changing
it means: new Traefik router/service config, a new TLS cert, and updating homie's
card — versus keeping the old hostname as a purely cosmetic legacy artifact while
everything else (repo, code, branding) renames.

**Options:**
- (A) Rename the hostname too (e.g. `sessioneer.example.com`) — fully consistent, but
  touches a second repo (traefik) and needs cert regen.
- (B) Keep `csm.example.com` for now — it's just a private LAN/tunnel hostname, not
  public-facing the way the GitHub repo name is; rename it later if it starts to bug
  you.

**Answer (Andres, 2026-09-01):** (A) — rename to `sessioneer.example.com`, full
consistency pass including traefik + cert regen + homie's dashboard card.
