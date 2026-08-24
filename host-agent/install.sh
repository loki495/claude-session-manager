#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
UNIT_DIR="$HOME/.config/systemd/user"
RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"

# agent.php unconditionally requires vendor/autoload.php (lib/Push.php's
# Web Push dependency) - a missing vendor/ breaks the host agent entirely,
# not just the push feature, so this is no longer optional the way it
# might look from the push feature being itself opt-in.
if [ ! -d "$REPO_ROOT/vendor" ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction --working-dir="$REPO_ROOT"
fi

if [ ! -f "$SCRIPT_DIR/.env" ]; then
    cp "$SCRIPT_DIR/.env.example" "$SCRIPT_DIR/.env"
    echo "Created $SCRIPT_DIR/.env from .env.example - edit it (CLAUDE_BIN at"
    echo "least - see below) before this actually works."
fi

# CLAUDE_BIN has no safe default (see Config::claude_bin()) - it's the one
# thing install.sh genuinely can't infer, so check it explicitly rather
# than letting a fresh install silently fail later with a confusing "no
# such file" the first time a session is actually created.
if ! grep -qE '^CLAUDE_BIN=\S' "$SCRIPT_DIR/.env" 2>/dev/null; then
    echo
    echo "WARNING: CLAUDE_BIN is not set in $SCRIPT_DIR/.env - New Session"
    echo "will fail until it is. Run \`which claude\` and set it, e.g.:"
    echo "  CLAUDE_BIN=$(command -v claude 2>/dev/null || echo '/path/to/claude')"
fi

# The systemd unit files are checked in as templates (@REPO_ROOT@/
# @PHP_BIN@/@SOCKET_GROUP@ placeholders, never a literal path baked in) -
# substituted here into the real files systemd actually reads, rather than
# a plain `cp`, so a fresh clone on a different machine (different
# username, different clone location) gets units that actually point at
# ITS OWN paths instead of silently carrying over the original author's.
PHP_BIN="$(command -v php)"
SOCKET_GROUP="$(id -gn)"

render_unit() {
    sed \
        -e "s|@REPO_ROOT@|$REPO_ROOT|g" \
        -e "s|@PHP_BIN@|$PHP_BIN|g" \
        -e "s|@SOCKET_GROUP@|$SOCKET_GROUP|g" \
        "$SCRIPT_DIR/systemd/$1" > "$UNIT_DIR/$1"
}

mkdir -p "$UNIT_DIR"
render_unit csm-agent.socket
render_unit csm-agent@.service

systemctl --user daemon-reload
systemctl --user enable --now csm-agent.socket

echo "Installed. Socket should now exist at: $RUNTIME_DIR/csm-agent.sock"
ls -la "$RUNTIME_DIR/csm-agent.sock"

echo
echo "The container's own .env needs APP_GID set to match SocketGroup"
echo "above (\"$SOCKET_GROUP\"): $(getent group "$SOCKET_GROUP" | cut -d: -f3)"

# Push-notification timer: files installed, deliberately NOT enabled/started
# here - it's a no-op until VAPID keys are generated and set in .env anyway
# (see push_configured() in lib/Push.php), and starting a new recurring
# background service is worth a deliberate opt-in rather than happening
# silently on every install.sh run. See the README for the full setup
# (generate keys, set them in .env, then enable the timer yourself).
render_unit csm-push-check.service
cp "$SCRIPT_DIR/systemd/csm-push-check.timer" "$UNIT_DIR/csm-push-check.timer"
systemctl --user daemon-reload

echo
echo "Push-notification timer units installed but NOT enabled - see the"
echo "README's \"Web Push notifications\" section, then run:"
echo "  systemctl --user enable --now csm-push-check.timer"

echo
echo "Lingering must be enabled for this user so the socket survives"
echo "logouts/reboots without an active login session:"
loginctl show-user "$(whoami)" -p Linger 2>/dev/null || true
echo "If that shows Linger=no, run: sudo loginctl enable-linger $(whoami)"
