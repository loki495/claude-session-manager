#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
UNIT_DIR="$HOME/.config/systemd/user"
RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"

mkdir -p "$UNIT_DIR"
cp "$SCRIPT_DIR/systemd/csm-agent.socket" "$UNIT_DIR/csm-agent.socket"
cp "$SCRIPT_DIR/systemd/csm-agent@.service" "$UNIT_DIR/csm-agent@.service"

systemctl --user daemon-reload
systemctl --user enable --now csm-agent.socket

echo "Installed. Socket should now exist at: $RUNTIME_DIR/csm-agent.sock"
ls -la "$RUNTIME_DIR/csm-agent.sock"

echo
echo "Lingering must be enabled for this user so the socket survives"
echo "logouts/reboots without an active login session:"
loginctl show-user "$(whoami)" -p Linger 2>/dev/null || true
echo "If that shows Linger=no, run: sudo loginctl enable-linger $(whoami)"
