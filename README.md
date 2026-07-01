# Claude Session Manager

A tiny, read-mostly web UI for managing `cc-*` tmux sessions on this dev
server. No database, no persistent state — every page load re-runs
`tmux list-sessions` fresh. It never accepts a shell string from the user;
the only things it can execute are a fixed `new-session` command and a
`kill-session` against a name that was just re-confirmed to exist in that
same request's `tmux list-sessions` output.

## What it does

- Lists sessions matching `cc-*`: name, relative last-active time, attached/detached.
- **New Session**: runs
  `tmux new-session -d -s "cc-$(date +%Y%m%d-%H%M)" -c /home/andres/www /home/andres/.local/bin/claude`
  (the timestamp is generated server-side in PHP, never from user input).
- **Kill** (per row): only killable if the exact session name is present in a
  freshly-fetched `tmux list-sessions` result computed in the same request.
  Anything else is rejected.
- **Kill sessions inactive > 12h**: re-lists `cc-*` sessions server-side and
  kills any whose `session_activity` is older than 12 hours. No per-session
  input involved.
- Auto-refreshes every 15s (`<meta http-equiv="refresh">`) plus a manual
  Refresh button.

## How commands are actually run

`src/lib/Tmux.php` calls `proc_open()` with the command given as an **array**,
e.g. `['tmux', '-S', $socket, 'kill-session', '-t', $name]`. That form never
goes through `/bin/sh`, so there's no shell metacharacter injection surface
at all — stronger than `shell_exec()` + `escapeshellarg()`. `escapeshellarg()`
is still nominally applied per the defense-in-depth requirement (see the
`escapeshellarg_noop()` comment in that file for why it's a no-op given the
array form), and every session name used for `kill-session` is re-validated
against a fresh whitelist regardless.

## File structure

```
claude-session-manager/
├── docker-compose.yml     # includes the Dockerfile inline (dockerfile_inline)
├── .env.example          # copy to .env, fill in real values, never commit .env
├── .gitignore
├── README.md
└── src/                   # bind-mounted into the container at /var/www/html
    ├── index.php          # Basic Auth, action handling, HTML/Tailwind UI
    └── lib/
        └── Tmux.php        # all tmux invocations + parsing live here
```

There is no standalone `Dockerfile` — the build steps live inline in
`docker-compose.yml` under `build.dockerfile_inline`. `src/` is bind-mounted
into the container rather than copied into the image, so editing any PHP
file takes effect on the next page load with no rebuild or restart.

## Setup

1. `cp .env.example .env` and fill in:
   - `BASIC_AUTH_USER` / `BASIC_AUTH_PASS` — required, gates the whole app.
   - `APP_UID` / `APP_GID` — must match the host user that owns the tmux
     server. Check with `id andres` on the host. On this machine that's
     currently `uid=1000(andres) gid=1001(andres)`.
   - `TMUX_SOCKET_DIR` / `TMUX_SOCKET` — see socket caveat below.
   - `BIND_ADDR` / `APP_PORT` — see network binding caveat below.

2. Build and start:
   ```
   docker compose up -d --build
   ```
   Leave it running — the container just serves the mounted `src/` files.
   Only re-run this (to rebuild) if you change `docker-compose.yml`'s
   `dockerfile_inline` block itself (base image, extensions, UID/GID args).
   Plain PHP edits under `src/` never need a rebuild.

3. Visit `http://<BIND_ADDR>:<APP_PORT>/` (direct) or
   `http://csm.dev.local.test/` (via Traefik, if your DNS/hosts resolve
   `*.dev.local.test` to this box, matching the pattern used by other sites
   in `~/www`).

## The tmux socket caveat (read this)

The container has its **own** tmux binary (installed via `apk add tmux` in
the inline Dockerfile in `docker-compose.yml`) but talks to the **host's**
tmux server, not its own. It does this by bind-mounting the host's tmux
socket directory into the container at the *same path* and pointing the
`tmux -S` flag at it via `TMUX_SOCKET`.

- Host sockets live at `/tmp/tmux-<uid>/default` (this box: `/tmp/tmux-1000/default`).
- The socket's parent directory is normally `drwx------` owned by the host
  user (`andres`, uid 1000 here) — only that UID can even open the
  directory, regardless of group. **The container's numeric UID must equal
  the host user's UID** (set via `APP_UID`, a build arg on the inline
  Dockerfile), or every tmux call will fail with a permissions error.
- `docker-compose.yml` mounts `${TMUX_SOCKET_DIR}` (a whole directory, not
  just the socket file) so it keeps working if tmux ever recreates the
  socket.
- If you ever see `error connecting to /tmp/tmux-1000/default (Permission denied)`
  in the container logs, it's almost always `APP_UID`/`APP_GID` mismatching
  the real host `id andres` output — fix `.env` and rebuild
  (`docker compose up -d --build`).

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
- No free-text fields anywhere. The only two commands the app can ever run
  are a fixed `new-session` invocation and `kill-session` against a
  server-verified whitelist.
- All tmux calls use `proc_open()` with an array command (no shell parsing
  at all), with `escapeshellarg()`/whitelist validation layered on top per
  the defense-in-depth requirement.
- No database, no sessions, no persistent state of any kind.
