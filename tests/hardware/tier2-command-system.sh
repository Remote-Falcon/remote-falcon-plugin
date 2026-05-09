#!/usr/bin/env bash
# Tier 2 #2 — invoke the Restart Listener command via FPP's HTTP API,
# the way the UI's Restart button does.
#
# The earlier hardware tests called commands/restart_remote_falcon.php
# directly via SSH. That validates the script itself but not the FPP
# command-system routing. This test hits FPP's /api/command/{name}
# endpoint and verifies:
#   - FPP routes the request to our command script
#   - The script runs as expected (kill + respawn produces a new PID)
#
# Why it matters: the UI's restartListener() function in
# js/remote_falcon_core.js calls /api/command/Remote%20Falcon%20-%20Restart%20Listener.
# Validating that path on real FPP catches issues like FPP version-
# specific routing changes, command name escaping bugs, etc.
#
# Usage: FPP_HOST=192.168.1.80 ./tier2-command-system.sh

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

section "Tier 2 #2 — FPP command-system end-to-end"

echo "Snapshotting Pi state..."
SNAP=$(pi_snapshot)

echo "Stopping current listener..."
pi_stop_listener > /dev/null

echo "Installing perf branch..."
pi_install_branch "${TEST_BRANCH:-chore/hw-tier2-validation}" > /dev/null

echo "Seeding safe settings (unreachable RF)..."
pi_seed_safe_settings > /dev/null

echo "Starting listener..."
pi_start_listener_as_fpp > /dev/null
INITIAL_PID=$(pi_listener_pid)
echo "  initial pid: $INITIAL_PID"

# Confirm command appears in FPP's command list
section "Command discovery"
COMMANDS=$(pi 'curl -sf http://127.0.0.1/api/commands' || echo "")
if echo "$COMMANDS" | grep -q "Remote Falcon - Restart Listener"; then
    ok "command 'Remote Falcon - Restart Listener' registered with FPP"
else
    fail "command not in FPP /api/commands"
    echo "  /api/commands first 200 chars: $(echo "$COMMANDS" | head -c 200)"
fi

# Invoke via the same /api/command path the UI uses
section "Invoke restart via /api/command"
echo "Calling http://127.0.0.1/api/command/Remote%20Falcon%20-%20Restart%20Listener ..."
RESPONSE=$(pi 'curl -sf -w "\n__HTTP_STATUS__%{http_code}" "http://127.0.0.1/api/command/Remote%20Falcon%20-%20Restart%20Listener" || echo "__HTTP_STATUS__000"')
HTTP_CODE=$(echo "$RESPONSE" | grep -oE 'HTTP_STATUS__[0-9]+' | tail -1 | sed 's/HTTP_STATUS__//')
BODY=$(echo "$RESPONSE" | sed 's/__HTTP_STATUS__[0-9]*$//')

echo "  HTTP $HTTP_CODE"
echo "  Body: $(echo "$BODY" | head -c 200)"

if [ "$HTTP_CODE" = "200" ]; then
    ok "command endpoint returned 200"
else
    fail "command endpoint returned $HTTP_CODE (expected 200)"
fi

# Wait for the kill+respawn to land
sleep 5

# Verify a NEW listener is running with a different PID
section "Post-invocation state"
NEW_PID=$(pi_listener_pid)
if [ -z "$NEW_PID" ]; then
    fail "no PID file after command (listener didn't respawn)"
elif [ "$NEW_PID" = "$INITIAL_PID" ]; then
    fail "PID unchanged ($NEW_PID) — command didn't actually restart"
else
    ALIVE=$(pi_listener_alive "$NEW_PID")
    if [ "$ALIVE" = "alive" ]; then
        ok "new listener pid $NEW_PID running (was $INITIAL_PID)"
    else
        fail "new pid $NEW_PID written but process not alive"
    fi
fi

# Old listener should be dead
ALIVE_OLD=$(pi_listener_alive "$INITIAL_PID")
if [ "$ALIVE_OLD" = "dead" ]; then
    ok "old listener pid $INITIAL_PID terminated"
else
    fail "old listener pid $INITIAL_PID still alive — leak"
fi

# enabled flag should be true (restart command sets it)
if pi 'grep -q "remoteFalconListenerEnabled = \"true\"" /home/fpp/media/config/plugin.remote-falcon'; then
    ok "enabled flag true after command"
else
    fail "enabled flag not true"
fi

echo
echo "Restoring Pi..."
pi_restore "$SNAP" > /dev/null
echo "  done"

summarize
