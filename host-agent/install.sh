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
    echo "Created $SCRIPT_DIR/.env from .env.example - edit it if this box's paths differ."
fi

mkdir -p "$UNIT_DIR"
cp "$SCRIPT_DIR/systemd/csm-agent.socket" "$UNIT_DIR/csm-agent.socket"
cp "$SCRIPT_DIR/systemd/csm-agent@.service" "$UNIT_DIR/csm-agent@.service"

systemctl --user daemon-reload
systemctl --user enable --now csm-agent.socket

echo "Installed. Socket should now exist at: $RUNTIME_DIR/csm-agent.sock"
ls -la "$RUNTIME_DIR/csm-agent.sock"

# Push-notification timer: files installed, deliberately NOT enabled/started
# here - it's a no-op until VAPID keys are generated and set in .env anyway
# (see push_configured() in lib/Push.php), and starting a new recurring
# background service is worth a deliberate opt-in rather than happening
# silently on every install.sh run. See the README for the full setup
# (generate keys, set them in .env, then enable the timer yourself).
cp "$SCRIPT_DIR/systemd/csm-push-check.service" "$UNIT_DIR/csm-push-check.service"
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
