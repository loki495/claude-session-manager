# Claude Session Manager

A tiny, read-mostly web UI for managing `cc-*` tmux sessions running
`claude` on this dev server. No database, no persistent state — every page
load re-fetches everything fresh. It never accepts a shell string from the
user; the only things it can ever cause to run are a fixed `new-session`
command and a `kill-session` against a name that was just re-confirmed to
exist in that same request's session listing.

## Architecture: container + host-native agent

The web UI runs in a Docker container, but **it never touches tmux or the
host process table directly.** It only knows how to speak a tiny
request/response protocol over a UNIX socket. A separate, host-native
**agent** (`host-agent/`, installed directly on the host via
`host-agent/install.sh` — not containerized) owns tmux, `/proc` scanning,
and everything else that has to run in the host's own namespace.

**Why the split exists:** tmux has a client/server model where the *first*
process to talk to a not-yet-running tmux socket auto-spawns the server as
its own child. Early on this app ran tmux directly from inside the
container, and once all `cc-*` sessions were killed and the host's tmux
server exited, the *next* "New Session" click caused the **container** to
become the one auto-spawning the tmux server — inside the container's own
filesystem namespace, where `/home/andres/.local/bin/claude` and
`/home/andres/www` don't exist. `tmux new-session` returns success before
checking whether the pane's command actually stayed running, so the UI
reported "Created session cc-...", the pane died instantly, and the session
never existed anywhere reachable from the host. Moving all tmux/process
control into a small agent that is *always* a genuine host process
(invoked by systemd, not Docker) fixes this at the root: the container is
now incapable of ever being the one to start a tmux server.

As a side benefit, the agent also scans `/proc` directly for every real
`claude` process on the host (matching `argv[0]` against
`/home/andres/.local/bin/claude` — not `/proc/pid/exe`, which resolves to
a versioned binary under `~/.local/share/claude/versions/*` and changes on
every update). This finds Claude processes regardless of how they were
started, not just ones matching the `cc-*` tmux naming convention. Ones
running inside a tracked `cc-*` tmux session are shown as normal, killable
session cards; any other real `claude` process found on the host (started
by hand in a plain terminal, for example) is shown read-only, for
visibility, with no Kill button — killing those was deliberately left out
of scope to avoid adding a second, SIGTERM-based kill path alongside
`tmux kill-session`.

## What it does

- Lists `cc-*` tmux sessions: name, working directory (if known), relative
  last-active time, attached/detached.
- Also lists any other real `claude` process found on the host that isn't
  inside a tracked session, read-only, under "Other claude processes on
  host".
- **New Session**: prompts for a working directory (a dropdown of your
  `~/www/*` project folders, or a manual absolute path), then runs
  `tmux new-session -d -s "cc-$(date +%Y%m%d-%H%M)" -c "<chosen dir>" /home/andres/.local/bin/claude`
  on the host agent (the timestamp is generated server-side, never from
  user input). Verifies the session actually stayed running before
  reporting success.
- **Kill** (per row): only killable if the exact session name is present in
  a freshly-fetched session listing computed in the same request on the
  agent. Anything else is rejected.
- **Kill sessions inactive > 12h**: agent re-lists `cc-*` sessions and kills
  any whose `session_activity` is older than 12 hours. No per-session input
  involved.
- No auto-refresh — a manual Refresh button re-fetches everything on demand.

## How commands are actually run

All tmux invocations live in `host-agent/lib/Sessions.php` and go through
`proc_open()` with the command given as an **array**, e.g.
`['tmux', '-S', $socket, 'kill-session', '-t', $name]`. That form never
goes through `/bin/sh`, so there's no shell metacharacter injection surface
at all. Every session name used for `kill-session` is re-validated against
a fresh whitelist inside the same request, regardless.

The container (`src/lib/AgentClient.php`) never runs a shell command at
all — it only opens a UNIX socket and exchanges one JSON request/response
pair with the agent.

## File structure

```
claude-session-manager/
├── docker-compose.yml     # container: includes the Dockerfile inline (dockerfile_inline)
├── .env.example           # copy to .env, fill in real values, never commit .env
├── .gitignore
├── README.md
├── src/                    # bind-mounted into the container at /var/www/html
│   ├── index.php           # Basic Auth, action handling, HTML/Tailwind UI
│   └── lib/
│       └── AgentClient.php  # talks to the host agent over a UNIX socket
└── host-agent/             # installed natively on the HOST, not in Docker
    ├── agent.php            # per-connection entry point (systemd socket activation)
    ├── lib/
    │   └── Sessions.php      # tmux calls + /proc scanning + all the real logic
    ├── systemd/
    │   ├── csm-agent.socket   # defines the UNIX socket (systemd --user)
    │   └── csm-agent@.service # spawns agent.php per connection
    └── install.sh            # installs + enables the systemd units
```

There is no standalone `Dockerfile` for the container — its build steps
live inline in `docker-compose.yml` under `build.dockerfile_inline`.
`src/` is bind-mounted into the container rather than copied into the
image, so editing any PHP file there takes effect on the next page load
with no rebuild or restart.

## Setup

**Order matters: install and start the host agent *before* starting the
container.** Docker bind-mounts a source path that doesn't exist yet as an
empty directory, so if the container starts first, `/run/csm-agent.sock`
inside the container will silently be a directory instead of the real
socket, and everything will fail with "Cannot reach host agent."

1. Install the host agent (runs natively via systemd `--user`, needs no
   containers, no extra dependencies beyond PHP CLI, which is already on
   this host):
   ```
   ./host-agent/install.sh
   ```
   This copies the two unit files into `~/.config/systemd/user/`, then
   runs `systemctl --user enable --now csm-agent.socket`. Verify the
   socket exists and is a socket (`s` in `ls -la`), not a directory:
   ```
   ls -la $XDG_RUNTIME_DIR/csm-agent.sock
   ```
   Lingering must be enabled for the socket to survive logout/reboot
   without an active login session (`install.sh` checks this and prints
   the fix if not — on this box it's already enabled).

2. `cp .env.example .env` and fill in:
   - `BASIC_AUTH_USER` / `BASIC_AUTH_PASS` — required, gates the whole app.
   - `APP_GID` — must match the group that owns the agent socket
     (`SocketGroup=andres` in `host-agent/systemd/csm-agent.socket`, gid
     1001 on this box — check with `id andres`). `APP_UID` no longer needs
     to match a host user; the container doesn't touch the host
     filesystem or tmux directly anymore.
   - `CSM_AGENT_SOCKET_HOST` — path to the real socket from step 1,
     normally `/run/user/<uid>/csm-agent.sock`.
   - `BIND_ADDR` / `APP_PORT` — see network binding caveat below.

3. Build and start the container:
   ```
   docker compose up -d --build
   ```
   Leave it running. Only re-run this if you change
   `docker-compose.yml`'s `dockerfile_inline` block itself; plain PHP
   edits under `src/` never need a rebuild.

4. Visit `http://<BIND_ADDR>:<APP_PORT>/` (direct) or
   `http://csm.dev.local.test/` (via Traefik, if your DNS/hosts resolve
   `*.dev.local.test` to this box, matching the pattern used by other sites
   in `~/www`).

## Updating the host agent

`host-agent/agent.php` and `host-agent/lib/Sessions.php` run directly off
the checked-out repo path (`/home/andres/www/claude-session-manager/...`,
hardcoded in `csm-agent@.service`'s `ExecStart`), so editing them takes
effect on the *next* connection with no restart needed — each connection
gets a fresh PHP process. You only need to re-run `install.sh` (or
`systemctl --user daemon-reload`) if you change the `.socket`/`.service`
unit files themselves.

## The agent socket caveat (read this)

- The agent's socket lives at `$XDG_RUNTIME_DIR/csm-agent.sock` (normally
  `/run/user/1000/csm-agent.sock`), created by systemd from
  `host-agent/systemd/csm-agent.socket` with `SocketMode=0660` and
  `SocketGroup=andres`. Only the owning user and that group can connect —
  the container needs `APP_GID` set to that same numeric gid (1001 here).
- `Accept=yes` means systemd spawns a **new** `agent.php` process per
  connection (classic inetd-style activation) with STDIN/STDOUT bound
  directly to that connection — no daemon loop, no manual socket-handling
  code, systemd owns the whole lifecycle.
- If the app shows "Cannot reach host agent", check on the host:
  `systemctl --user status csm-agent.socket` and
  `journalctl --user -u 'csm-agent@*' -n 50`.
- If `docker compose up` was run before the agent was installed, the bind
  mount will have created a plain directory at the socket path instead of
  passing through the real socket. Fix: `docker compose down`, confirm
  `ls -la $XDG_RUNTIME_DIR/csm-agent.sock` shows a real socket (reinstall
  via `install.sh` if not), then `docker compose up -d`.

## Network binding caveat (read this too)

This app is intentionally **not** meant to be reachable from the public
internet — it can create and kill tmux sessions on your dev box.

- `docker-compose.yml` publishes the port as
  `"${BIND_ADDR}:${APP_PORT}:80"`. Set `BIND_ADDR` in `.env` to this
  machine's actual **LAN IP** (e.g. `192.168.1.50`), never `0.0.0.0`. If you
  leave it at the default `127.0.0.1`, it's only reachable from the host
  itself (e.g. via an SSH tunnel).
- The Traefik labels are included for consistency with other sites in
  `~/www`, but note: on this machine, `~/www/traefik/docker-compose.yml`
  publishes Traefik's own entrypoint as `"80:80"` — i.e. bound to **all**
  interfaces, not just LAN. That means reaching this app via
  `http://csm.dev.local.test/` through Traefik is *not* itself
  interface-restricted; Basic Auth is the actual gate in that path. If you
  want a hard interface restriction, either:
  - access it via the direct `BIND_ADDR:APP_PORT` route instead of Traefik, or
  - add a host firewall rule (e.g. `iptables`/`ufw`/`nftables`) restricting
    inbound `80`/`8080` (Traefik) or `APP_PORT` to your LAN subnet.
- Either way, Basic Auth is required on every request and is not optional.

## Home screen bookmark

On a phone on the same LAN:

1. Open `http://<BIND_ADDR>:<APP_PORT>/` (or the Traefik host, if reachable)
   in the browser, enter the Basic Auth credentials once.
2. Use "Add to Home Screen" (Safari: Share → Add to Home Screen; Chrome:
   ⋮ menu → Add to Home Screen).
3. Basic Auth credentials are cached per-browser-session by most mobile
   browsers, but you may be re-prompted after the browser fully restarts —
   this is expected and not a bug.

## Security summary

- Whole app gated behind HTTP Basic Auth, credentials from environment
  variables only (never hardcoded).
- No free-text fields except the optional custom working-directory path
  for New Session, which is passed as a `proc_open()` array argument (never
  through a shell) and only ever used as a `tmux -c` target — it can change
  *where* the fixed `claude` command starts, not *what* command runs.
- The only two commands the app can ever cause to run are a fixed
  `new-session` invocation and `kill-session` against a server-verified
  whitelist, both re-validated against a freshly-fetched session list on
  every request.
- The container has no access to the host filesystem, tmux, or the process
  table — only a single UNIX socket to the host agent, gated further by
  UNIX socket permissions (`SocketMode=0660`, `SocketGroup`).
- No database, no sessions, no persistent state of any kind (aside from
  small JSON sidecar files under `/run/user/1000/csm-sessions/` that only
  record which working directory a session was started with — on tmpfs,
  gone on reboot).
