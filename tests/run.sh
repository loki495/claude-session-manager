#!/usr/bin/env bash
# Runs every tests/test_*.php file against isolated fixtures (see
# tests/.env.testing) and guarantees cleanup of anything they start -
# tmux sessions, the fake claude process, sidecar files - even if a test
# fails or the run is interrupted. Usage: bash tests/run.sh [--bail]
#   --bail  stop at the first failing test file instead of running the rest
set -uo pipefail

bail=0
for arg in "$@"; do
    case "$arg" in
        --bail) bail=1 ;;
        *)
            echo "Unknown argument: $arg" >&2
            exit 1
            ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

set -a
# shellcheck source=/dev/null
source "$SCRIPT_DIR/.env.testing"
set +a

# Real host values (must match the defaults in host-agent/lib/Sessions.php).
# Cleanup below refuses to touch these no matter what .env.testing says, so
# a typo in .env.testing can never make this script tear down the real
# session.
REAL_TMUX_SOCKET="/tmp/tmux-1000/default"
REAL_SIDECAR_DIR="/run/user/1000/csm-sessions"
REAL_QUOTA_CACHE_FILE="/run/user/1000/csm-agent-quota-cache.json"

if [ "$TMUX_SOCKET" = "$REAL_TMUX_SOCKET" ] || [ -z "$TMUX_SOCKET" ]; then
    echo "REFUSING TO RUN: TMUX_SOCKET in tests/.env.testing resolves to the real host socket (or is empty). Aborting before touching tmux." >&2
    exit 1
fi

if [ "$SIDECAR_DIR" = "$REAL_SIDECAR_DIR" ] || [ -z "$SIDECAR_DIR" ]; then
    echo "REFUSING TO RUN: SIDECAR_DIR in tests/.env.testing resolves to the real sidecar dir (or is empty). Aborting before deleting anything." >&2
    exit 1
fi

if [ "${QUOTA_CACHE_FILE:-}" = "$REAL_QUOTA_CACHE_FILE" ] || [ -z "${QUOTA_CACHE_FILE:-}" ]; then
    echo "REFUSING TO RUN: QUOTA_CACHE_FILE in tests/.env.testing resolves to the real quota cache (or is empty). Aborting before deleting anything." >&2
    exit 1
fi

cleanup() {
    # Guard repeated here (not just above) so cleanup() is safe to call
    # standalone and never depends on the checks above having run.
    if [ -n "${TMUX_SOCKET:-}" ] && [ "$TMUX_SOCKET" != "$REAL_TMUX_SOCKET" ]; then
        tmux -S "$TMUX_SOCKET" kill-server >/dev/null 2>&1 || true
    fi

    pkill -f "$SCRIPT_DIR/fixtures/fake_claude" >/dev/null 2>&1 || true
    pkill -f "$SCRIPT_DIR/../host-agent/quota_refresh.php" >/dev/null 2>&1 || true

    if [ -n "${SIDECAR_DIR:-}" ] && [ "$SIDECAR_DIR" != "$REAL_SIDECAR_DIR" ]; then
        rm -rf "$SIDECAR_DIR"
    fi

    if [ -n "${QUOTA_CACHE_FILE:-}" ] && [ "$QUOTA_CACHE_FILE" != "$REAL_QUOTA_CACHE_FILE" ]; then
        rm -rf "$(dirname "$QUOTA_CACHE_FILE")"
    fi

    rm -rf "$(dirname "$TMUX_SOCKET")"
}

# A trap that only runs cleanup does NOT stop the script on Ctrl-C - bash
# resumes the for-loop below right after the handler returns. That left a
# real gap: an interrupted run would tear tmux/sidecars down mid-suite via
# cleanup(), then barrel on into the next test file against now-missing
# fixtures. interrupt() must itself terminate the script.
interrupt() {
    cleanup
    trap - EXIT INT TERM
    exit 130
}

trap cleanup EXIT
trap interrupt INT TERM

mkdir -p "$(dirname "$TMUX_SOCKET")" "$SIDECAR_DIR" "$(dirname "$QUOTA_CACHE_FILE")"

failures=0

for test_file in "$SCRIPT_DIR"/test_*.php; do
    echo "== $(basename "$test_file") =="
    if php "$test_file"; then
        echo "-- $(basename "$test_file"): PASS --"
    else
        echo "-- $(basename "$test_file"): FAIL --"
        failures=$((failures + 1))

        if [ "$bail" -eq 1 ]; then
            echo
            echo "RESULT: stopping after first failure ($(basename "$test_file")) - --bail was set"
            exit 1
        fi
    fi
    echo
done

if [ "$failures" -gt 0 ]; then
    echo "RESULT: $failures test file(s) failed"
    exit 1
fi

echo "RESULT: all tests passed"
exit 0
