#!/usr/bin/env bash
# End-to-end smoke test for the Remote Falcon plugin against a virtual FPP.
# Invoked by ../../developer-files/fpp-test-env/scripts/smoke-test.sh, which
# populates these env vars:
#   FPP_BASE_URL, FPP_CONTAINER, MOCK_RF_BASE_URL, MOCK_RF_INTERNAL_URL,
#   MOCK_RF_STATE_DIR
#
# Prerequisites (the harness runs these in order):
#   1. spin-up.sh                — FPP + mock-rf-api are running
#   2. install-plugin.sh          — plugin tree is at /home/fpp/media/plugins/remote-falcon
#   3. seed-config.sh             — plugin.remote-falcon INI has remoteToken,
#                                   pluginsApiPath, remotePlaylist, etc.
#   4. (caller starts the listener via postStart.sh before calling this)

set -euo pipefail

PASS=0
FAIL=0

ok()   { printf "  \033[32m✓\033[0m %s\n" "$1"; PASS=$((PASS+1)); }
fail() { printf "  \033[31m✗\033[0m %s\n" "$1"; FAIL=$((FAIL+1)); }
section() { printf "\n\033[1m%s\033[0m\n" "$1"; }

clear_recordings() {
    echo '[]' > "$MOCK_RF_STATE_DIR/recordings.json"
}

set_route() {
    # set_route PATH JSON_BODY_OR_ROUTE_OBJECT
    local path="$1"
    local route="$2"
    python3 - "$MOCK_RF_STATE_DIR/config.json" "$path" "$route" <<'PY'
import json, sys
cfg_path, key, route_str = sys.argv[1], sys.argv[2], sys.argv[3]
with open(cfg_path) as f:
    try:
        cfg = json.load(f)
    except json.JSONDecodeError:
        cfg = {}
cfg[key] = json.loads(route_str)
with open(cfg_path, "w") as f:
    json.dump(cfg, f)
PY
}

recording_paths() {
    python3 -c "import json,sys; [print(r['path']) for r in json.load(open('$MOCK_RF_STATE_DIR/recordings.json'))]"
}

# --- 1. Plugin install + listener boot ---

section "Plugin install + listener boot"

if curl -sf "$FPP_BASE_URL/api/plugin" | grep -q '"remote-falcon"'; then
    ok "plugin appears in /api/plugin"
else
    fail "plugin not registered with FPP"
fi

if docker exec "$FPP_CONTAINER" test -f /home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid; then
    ok "listener PID file written"
else
    fail "listener PID file missing"
fi

PID=$(docker exec "$FPP_CONTAINER" cat /home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid 2>/dev/null || echo "")
if [ -n "$PID" ] && docker exec "$FPP_CONTAINER" sh -c "kill -0 $PID 2>/dev/null"; then
    ok "listener process running (pid $PID)"
else
    fail "listener process not running"
fi

if docker exec "$FPP_CONTAINER" grep -q "Starting Remote Falcon Plugin" /home/fpp/media/logs/remote-falcon-listener.log; then
    ok "listener log shows startup banner"
else
    fail "listener log missing startup banner"
fi

# --- 2. Listener calls mock RF on init ---

section "Listener init → mock RF"

# Wait briefly for the listener to make its initial calls.
sleep 2

if recording_paths | grep -qx '/remotePreferences'; then
    ok "listener fetched /remotePreferences"
else
    fail "listener did not fetch /remotePreferences"
fi

# --- 3. Settings page loads ---

section "Settings page"

if curl -sf -o /dev/null "$FPP_BASE_URL/plugin.php?plugin=remote-falcon&page=remote_falcon_ui.html"; then
    ok "settings page returns 200"
else
    fail "settings page failed to load"
fi

# --- 4. Restart listener via plugin command ---

section "Restart listener (real kill+respawn)"

OLD_PID="$PID"

# The restart command kills the running listener via postStop.sh + PID
# file and launches a fresh one via postStart.sh. The new process picks
# up any code changes left on disk by an upgrade. Behavioral assertion:
# the PID after restart must differ from the PID before.
docker exec "$FPP_CONTAINER" /home/fpp/media/plugins/remote-falcon/commands/restart_remote_falcon.php >/dev/null
sleep 3

if docker exec "$FPP_CONTAINER" sh -c "kill -0 $OLD_PID 2>/dev/null"; then
    fail "old listener still alive after restart (expected respawn)"
else
    ok "old listener terminated by restart"
fi

NEW_PID=$(docker exec "$FPP_CONTAINER" cat /home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid 2>/dev/null || echo "")
if [ -n "$NEW_PID" ] && [ "$NEW_PID" != "$OLD_PID" ] && docker exec "$FPP_CONTAINER" sh -c "kill -0 $NEW_PID 2>/dev/null"; then
    ok "new listener running with different pid (was $OLD_PID, now $NEW_PID)"
else
    fail "no new listener after restart (old=$OLD_PID, new=${NEW_PID:-<none>})"
fi

if docker exec "$FPP_CONTAINER" grep -q "Starting Remote Falcon Plugin" /home/fpp/media/logs/remote-falcon-listener.log; then
    ok "listener log shows fresh startup banner from respawn"
else
    fail "listener log missing startup banner after restart"
fi

# Track new PID so subsequent checks reference the live process.
PID="$NEW_PID"

# --- 5. Stop listener via plugin command ---

section "Stop listener"

docker exec "$FPP_CONTAINER" /home/fpp/media/plugins/remote-falcon/commands/stop_remote_falcon.php >/dev/null
# Stop just sets a flag; the loop reads it on next iteration. Wait a tick
# longer than fppStatusCheckTime + a small buffer.
sleep 3

# Existing behavior: "stop" pauses the loop but doesn't kill the process.
# Real shutdown happens via postStop.sh. (This is independent of the
# restart-fix landing in fix/listener-restart-actually-restarts.)
if docker exec "$FPP_CONTAINER" sh -c "kill -0 $PID 2>/dev/null"; then
    ok "process still alive after stop command (existing behavior; loop pauses)"
else
    fail "process died unexpectedly after stop command"
fi

if docker exec "$FPP_CONTAINER" grep -q 'remoteFalconListenerEnabled = "false"' /home/fpp/media/config/plugin.remote-falcon; then
    ok "enabled flag set to false by stop command"
else
    fail "enabled flag not flipped to false"
fi

# --- 5b. Restart command recovers from stopped state ---

section "Restart from stopped state (recovers via command)"

# Listener is currently in "stopped" state (enabled=false, process alive
# but loop paused). Calling the restart command should:
#   1. Flip enabled back to true (so the new loop runs).
#   2. Kill the existing process via postStop.sh.
#   3. Launch a fresh process via postStart.sh.
# This proves the UI's Restart button can recover from a stopped state
# (which the legacy flag-toggle approach could not).

PRE_RECOVER_PID="$PID"
docker exec "$FPP_CONTAINER" /home/fpp/media/plugins/remote-falcon/commands/restart_remote_falcon.php >/dev/null
sleep 3

if docker exec "$FPP_CONTAINER" grep -q 'remoteFalconListenerEnabled = "true"' /home/fpp/media/config/plugin.remote-falcon; then
    ok "enabled flag flipped back to true by restart command"
else
    fail "enabled flag still false after restart"
fi

LATEST_PID=$(docker exec "$FPP_CONTAINER" cat /home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid 2>/dev/null || echo "")
if [ -n "$LATEST_PID" ] && [ "$LATEST_PID" != "$PRE_RECOVER_PID" ] && docker exec "$FPP_CONTAINER" sh -c "kill -0 $LATEST_PID 2>/dev/null"; then
    ok "fresh listener (pid $LATEST_PID) running after recovery"
else
    fail "no respawn from stopped state (was=$PRE_RECOVER_PID, now=${LATEST_PID:-<none>})"
fi

PID="$LATEST_PID"

# --- 6. postStop.sh actually kills the listener ---

section "postStop.sh terminates listener"

docker exec "$FPP_CONTAINER" /home/fpp/media/plugins/remote-falcon/scripts/postStop.sh
sleep 1

if docker exec "$FPP_CONTAINER" sh -c "kill -0 $PID 2>/dev/null"; then
    fail "listener still running after postStop.sh"
else
    ok "listener terminated by postStop.sh"
fi

if ! docker exec "$FPP_CONTAINER" test -f /home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid; then
    ok "PID file removed by postStop.sh"
else
    fail "PID file still present after postStop.sh"
fi

# --- summary ---

printf "\n\033[1mSummary:\033[0m %d passed, %d failed\n" "$PASS" "$FAIL"
exit $FAIL
