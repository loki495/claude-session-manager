#!/usr/bin/env bash
# Runs every tests/test_*.php file against isolated fixtures (see
# tests/.env.testing) and guarantees cleanup of anything they start -
# tmux sessions, the fake claude process, sidecar files - even if a test
# fails or the run is interrupted. Usage: bash tests/run.sh [--bail] [--cleanup]
#   --bail     stop at the first failing test file instead of running the rest
#   --cleanup  don't run tests at all - just sweep any stray test-infra
#              processes for THIS checkout (see sweep_stray_processes()
#              below) and exit. A deliberate, explicit action, not run
#              automatically before a normal test run - it kills every
#              matching process by script path, which would also take out
#              a legitimate concurrent use of the same harness (e.g. a
#              scratch script for manual Playwright verification, a
#              different socket path/purpose entirely - found live
#              2026-08-08 running this automatically). Only reach for this
#              to tidy up already-existing old orphans by hand.
set -uo pipefail

bail=0
cleanup_only=0
for arg in "$@"; do
    case "$arg" in
        --bail) bail=1 ;;
        --cleanup) cleanup_only=1 ;;
        *)
            echo "Unknown argument: $arg" >&2
            exit 1
            ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Keyed by SCRIPT_DIR (not a single global path) so this only ever blocks a
# second run of THIS SAME checkout - a different worktree/clone (e.g.
# claude-session-manager-refactor) has its own tests/.env.testing pointing
# at its own isolated fixture paths, so running its suite concurrently with
# this one is genuinely safe, not something to block. Two runs of the SAME
# checkout are not safe: they'd share the exact same TMUX_SOCKET/
# SIDECAR_DIR/QUOTA_CACHE_FILE from one tests/.env.testing, and whichever
# finishes first would tear that state down via its own EXIT trap out from
# under the one still running. -n (non-blocking) fails fast with a clear
# message instead of silently hanging behind a run that might itself be
# stuck.
LOCK_FILE="/tmp/csm-test-run-$(echo -n "$SCRIPT_DIR" | cksum | cut -d' ' -f1).lock"
exec 200>"$LOCK_FILE"
if ! flock -n 200; then
    echo "REFUSING TO RUN: another tests/run.sh for this same checkout ($SCRIPT_DIR) is already in progress (lock: $LOCK_FILE). Wait for it to finish - running two at once would corrupt each other's isolated tmux/sidecar/quota-cache state." >&2
    exit 1
fi

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

# Explicit --cleanup only, deliberately NOT run automatically before every
# normal test run. Found live 2026-08-08, the same night this was added:
# running it unconditionally killed a legitimate, currently-in-use scratch
# verification server (a manual Playwright-verification harness, same
# socket_harness.php script, a DIFFERENT socket path/purpose entirely) as
# an unintended side effect of just running the normal suite - pkill -f
# against the script path matches every instance of it, not just genuinely
# orphaned ones, and the lock above only guards against a second run.sh of
# this checkout, not an unrelated harness use. A real fix for the
# accumulation problem this was meant to help with already exists at the
# right layer: socket_harness.php's own kill_stale_listener() only ever
# kills the ONE specific process actually blocking the exact socket path a
# new instance needs, never anything else - --cleanup here is for
# deliberately tidying up already-existing old orphans by hand, not
# something safe to run as a side effect of unrelated work.
# Scoped to this checkout's own absolute paths throughout, never a bare
# process-name pattern, so it can never reach into an unrelated project.
sweep_stray_processes() {
    pkill -f "$SCRIPT_DIR/lib/socket_harness.php" >/dev/null 2>&1 || true
    pkill -f "$SCRIPT_DIR/fixtures/fake_claude" >/dev/null 2>&1 || true
    pkill -f "$SCRIPT_DIR/../host-agent/quota_refresh.php" >/dev/null 2>&1 || true

    if [ -n "${TMUX_SOCKET:-}" ] && [ "$TMUX_SOCKET" != "$REAL_TMUX_SOCKET" ]; then
        tmux -S "$TMUX_SOCKET" kill-server >/dev/null 2>&1 || true
    fi
}

if [ "$cleanup_only" -eq 1 ]; then
    echo "Sweeping stray test-infrastructure processes for this checkout ($SCRIPT_DIR)..."
    sweep_stray_processes

    if [ -n "${SIDECAR_DIR:-}" ] && [ "$SIDECAR_DIR" != "$REAL_SIDECAR_DIR" ]; then
        rm -rf "$SIDECAR_DIR"
    fi

    if [ -n "${QUOTA_CACHE_FILE:-}" ] && [ "$QUOTA_CACHE_FILE" != "$REAL_QUOTA_CACHE_FILE" ]; then
        rm -rf "$(dirname "$QUOTA_CACHE_FILE")"
    fi

    rm -rf "$(dirname "$TMUX_SOCKET")"
    echo "Done."
    exit 0
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
