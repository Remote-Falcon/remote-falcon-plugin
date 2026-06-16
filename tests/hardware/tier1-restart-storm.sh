#!/usr/bin/env bash
# Tier 1 #3 — restart storm.
#
# Fires the Restart Listener command 10 times over ~10 seconds. Asserts
# that the final state is exactly ONE listener running with a consistent
# PID file. Catches PID file races, double-listener bugs, and lifecycle
# scripts that don't tolerate concurrent invocations.
#
# Usage: FPP_HOST=192.168.1.80 ./tier1-restart-storm.sh

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

: "${STORM_COUNT:=10}"
: "${STORM_INTERVAL:=1}"  # seconds between restarts

section "Tier 1 #3 — restart storm (${STORM_COUNT} restarts at ${STORM_INTERVAL}s intervals)"

echo "Snapshotting Pi state..."
SNAP=$(pi_snapshot)

echo "Stopping current listener..."
pi_stop_listener > /dev/null

echo "Installing perf branch..."
pi_install_branch "${TEST_BRANCH:-chore/hw-tier1-validation}" > /dev/null

echo "Seeding safe settings (unreachable RF)..."
pi_seed_safe_settings > /dev/null

echo "Starting listener..."
pi_start_listener_as_fpp > /dev/null
INITIAL_PID=$(pi_listener_pid)
echo "  initial pid: $INITIAL_PID"

# Fire restart $STORM_COUNT times in a row
echo
echo "Firing $STORM_COUNT restarts..."
for i in $(seq 1 $STORM_COUNT); do
    pi 'sudo -u fpp /home/fpp/media/plugins/remote-falcon/commands/restart_remote_falcon.php > /dev/null 2>&1' &
    sleep "$STORM_INTERVAL"
    printf "."
done
echo " done"

# Wait for the dust to settle
sleep 3

section "Post-storm assertions"

# 1. Exactly one listener process running
LISTENER_COUNT=$(pi 'pgrep -f "/usr/bin/php.*remote_falcon_listener\.php" | wc -l' | tr -d ' ')
if [ "$LISTENER_COUNT" = "1" ]; then
    ok "exactly 1 listener process running"
elif [ "$LISTENER_COUNT" = "0" ]; then
    fail "0 listener processes (everyone died)"
else
    fail "$LISTENER_COUNT listeners running (race condition spawned duplicates)"
fi

# 2. PID file present and points at the running listener
PIDFILE_CONTENT=$(pi 'cat /home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid 2>/dev/null')
if [ -z "$PIDFILE_CONTENT" ]; then
    fail "PID file missing or empty"
else
    ok "PID file present (contains $PIDFILE_CONTENT)"
    LIVE=$(pi_listener_alive "$PIDFILE_CONTENT")
    if [ "$LIVE" = "alive" ]; then
        ok "PID file points to a live process"
    else
        fail "PID file references a dead PID"
    fi
fi

# 3. Listener PID changed from initial (proves restarts actually happened,
#    not just no-op'd)
if [ "$PIDFILE_CONTENT" != "$INITIAL_PID" ]; then
    ok "final pid ($PIDFILE_CONTENT) differs from initial ($INITIAL_PID) — restarts happened"
else
    fail "final pid unchanged — restarts may have been swallowed"
fi

# 4. Listener still functional: settings page should still render
if pi 'curl -sf -o /dev/null "http://127.0.0.1/plugin.php?plugin=remote-falcon&page=remote_falcon_ui.html"'; then
    ok "settings page still loads after storm"
else
    fail "settings page failed after storm"
fi

# 5. enabled flag should be true (last restart command sets it)
if pi 'grep -q "remoteFalconListenerEnabled = \"true\"" /home/fpp/media/config/plugin.remote-falcon'; then
    ok "enabled flag = true after final restart"
else
    fail "enabled flag not true"
fi

echo
echo "Restoring Pi..."
pi_restore "$SNAP" > /dev/null
echo "  done"

summarize
